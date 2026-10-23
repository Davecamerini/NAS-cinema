<?php
declare(strict_types=1);

function cinema_collect_videos(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static function ($current) {
                $name = $current->getFilename();
                if (cinema_is_ignored_name($name)) {
                    return false;
                }
                return true;
            }
        )
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $name = $file->getFilename();
        if (cinema_is_ignored_name($name) || !cinema_is_video_filename($name)) {
            continue;
        }
        $out[] = $file->getPathname();
    }
    return $out;
}

function cinema_rel_path(string $abs, string $root): string
{
    $abs = str_replace('\\', '/', $abs);
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (str_starts_with($abs, $root . '/')) {
        return substr($abs, strlen($root) + 1);
    }
    return ltrim($abs, '/');
}

function cinema_path_inside(string $abs, string $root): bool
{
    $realFile = realpath($abs);
    $realRoot = realpath($root);
    if ($realFile === false || $realRoot === false) {
        return false;
    }
    $realFile = str_replace('\\', '/', $realFile);
    $realRoot = str_replace('\\', '/', $realRoot);
    return $realFile === $realRoot || str_starts_with($realFile, $realRoot . '/');
}

function cinema_probe_duration(string $absPath): ?int
{
    if (!function_exists('exec') || !is_file($absPath)) {
        return null;
    }
    $cmd = 'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
        . escapeshellarg($absPath) . ' 2>/dev/null';
    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);
    if ($code !== 0 || $out === []) {
        return null;
    }
    $n = (float) $out[0];
    return $n > 0 ? (int) round($n) : null;
}

function cinema_sidecar_poster(string $dir): ?string
{
    foreach (['poster.jpg', 'poster.png', 'folder.jpg', 'folder.png'] as $name) {
        $p = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

function cinema_import_sidecar_poster(string $source, string $destName): ?string
{
    cinema_ensure_data_dirs();
    $dest = CINEMA_POSTERS . '/' . $destName;
    if (@copy($source, $dest)) {
        return $destName;
    }
    return null;
}

function cinema_upsert_movie_file(string $absPath, string $moviesRoot): int
{
    $rel = cinema_rel_path($absPath, $moviesRoot);
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $size = (int) @filesize($absPath);
    $mtime = (int) @filemtime($absPath);

    $parts = explode('/', str_replace('\\', '/', $rel));
    if (count($parts) > 1) {
        $parsed = cinema_parse_title_year($parts[0]);
    } else {
        $parsed = cinema_parse_title_year(basename($absPath));
    }

    $pdo = cinema_db();
    $st = $pdo->prepare('SELECT id, tmdb_id, poster_file, file_size, file_mtime FROM movies WHERE path = ?');
    $st->execute([$rel]);
    $existing = $st->fetch();

    if ($existing
        && (int) $existing['file_size'] === $size
        && (int) $existing['file_mtime'] === $mtime
    ) {
        $id = (int) $existing['id'];
        if (empty($existing['poster_file']) && empty($existing['tmdb_id'])) {
            $side = cinema_sidecar_poster(dirname($absPath));
            if ($side) {
                $file = cinema_import_sidecar_poster($side, 'movie-' . $id . '.jpg');
                if ($file) {
                    $pdo->prepare('UPDATE movies SET poster_file = ? WHERE id = ?')->execute([$file, $id]);
                }
            }
        }
        return $id;
    }

    $duration = cinema_probe_duration($absPath);
    $playable = cinema_is_playable_ext($ext) ? 1 : 0;

    if ($existing) {
        $pdo->prepare(
            'UPDATE movies SET file_size = ?, file_mtime = ?, ext = ?, playable = ?, duration_seconds = COALESCE(?, duration_seconds), updated_at = datetime(\'now\') WHERE id = ?'
        )->execute([$size, $mtime, $ext, $playable, $duration, (int) $existing['id']]);
        $id = (int) $existing['id'];
        if (empty($existing['poster_file']) && empty($existing['tmdb_id'])) {
            $side = cinema_sidecar_poster(dirname($absPath));
            if ($side) {
                $file = cinema_import_sidecar_poster($side, 'movie-' . $id . '.jpg');
                if ($file) {
                    $pdo->prepare('UPDATE movies SET poster_file = ? WHERE id = ?')->execute([$file, $id]);
                }
            }
        }
        return $id;
    }

    $pdo->prepare(
        'INSERT INTO movies (path, title, year, ext, playable, file_size, file_mtime, duration_seconds)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$rel, $parsed['title'], $parsed['year'], $ext, $playable, $size, $mtime, $duration]);
    $id = (int) $pdo->lastInsertId();

    $side = cinema_sidecar_poster(dirname($absPath));
    if ($side) {
        $file = cinema_import_sidecar_poster($side, 'movie-' . $id . '.jpg');
        if ($file) {
            $pdo->prepare('UPDATE movies SET poster_file = ? WHERE id = ?')->execute([$file, $id]);
        }
    }

    return $id;
}

function cinema_upsert_show(string $folderName, string $relPath): int
{
    $parsed = cinema_parse_title_year($folderName);
    $pdo = cinema_db();
    $st = $pdo->prepare('SELECT id FROM shows WHERE path = ?');
    $st->execute([$relPath]);
    $id = $st->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO shows (path, title, year) VALUES (?, ?, ?)')->execute([
        $relPath,
        $parsed['title'],
        $parsed['year'],
    ]);
    return (int) $pdo->lastInsertId();
}

function cinema_upsert_episode(int $showId, string $absPath, string $seriesRoot, ?int $seasonFromFolder): int
{
    $rel = cinema_rel_path($absPath, $seriesRoot);
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $size = (int) @filesize($absPath);
    $mtime = (int) @filemtime($absPath);

    $pdo = cinema_db();
    $st = $pdo->prepare('SELECT id, file_size, file_mtime FROM episodes WHERE path = ?');
    $st->execute([$rel]);
    $existing = $st->fetch();

    if ($existing
        && (int) $existing['file_size'] === $size
        && (int) $existing['file_mtime'] === $mtime
    ) {
        return (int) $existing['id'];
    }

    $parsedEp = cinema_parse_episode(basename($absPath));
    $season = $parsedEp['season'] ?? ($seasonFromFolder ?: 1);
    $episode = $parsedEp['episode'] ?? 0;
    $title = cinema_episode_title_from_filename(basename($absPath));
    $duration = cinema_probe_duration($absPath);
    $playable = cinema_is_playable_ext($ext) ? 1 : 0;
    $id = $existing ? (int) $existing['id'] : 0;

    if ($episode <= 0) {
        if ($id > 0) {
            $episode = (int) ($pdo->query('SELECT episode FROM episodes WHERE id = ' . $id)->fetchColumn() ?: 1);
        } else {
            $q = $pdo->prepare('SELECT COALESCE(MAX(episode), 0) FROM episodes WHERE show_id = ? AND season = ?');
            $q->execute([$showId, $season]);
            $episode = (int) $q->fetchColumn() + 1;
        }
    }

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE episodes SET season = ?, episode = ?, ext = ?, playable = ?, file_size = ?, file_mtime = ?, duration_seconds = COALESCE(?, duration_seconds) WHERE id = ?'
        )->execute([$season, $episode, $ext, $playable, $size, $mtime, $duration, $id]);
        return $id;
    }

    $pdo->prepare(
        'INSERT INTO episodes (show_id, path, season, episode, title, ext, playable, file_size, file_mtime, duration_seconds)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$showId, $rel, $season, $episode, $title !== '' ? $title : null, $ext, $playable, $size, $mtime, $duration]);

    return (int) $pdo->lastInsertId();
}

function cinema_scan_job_path(): string
{
    return CINEMA_DATA . '/scan-job.json';
}

function cinema_scan_job_load(): ?array
{
    $path = cinema_scan_job_path();
    if (!is_file($path)) {
        return null;
    }
    $job = json_decode((string) @file_get_contents($path), true);
    return is_array($job) ? $job : null;
}

function cinema_scan_job_save(array $job): void
{
    cinema_ensure_data_dirs();
    @file_put_contents(
        cinema_scan_job_path(),
        json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function cinema_scan_job_clear(): void
{
    $path = cinema_scan_job_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

function cinema_scan_pick_movie_file(array $files): string
{
    usort($files, static function ($a, $b) {
        $pa = cinema_is_playable_ext(strtolower(pathinfo($a, PATHINFO_EXTENSION))) ? 0 : 1;
        $pb = cinema_is_playable_ext(strtolower(pathinfo($b, PATHINFO_EXTENSION))) ? 0 : 1;
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        return ((int) @filesize($b)) <=> ((int) @filesize($a));
    });
    return $files[0];
}

function cinema_scan_collect_items(): array
{
    $moviesRoot = cinema_movies_root();
    $seriesRoot = cinema_series_root();
    $items = [];

    $movieFiles = cinema_filter_originals_with_playable_sibling(cinema_collect_videos($moviesRoot));
    $grouped = [];
    foreach ($movieFiles as $abs) {
        $rel = cinema_rel_path($abs, $moviesRoot);
        $parts = explode('/', $rel);
        $key = count($parts) > 1 ? $parts[0] : $rel;
        $grouped[$key][] = $abs;
    }
    foreach ($grouped as $files) {
        $items[] = ['kind' => 'movie', 'abs' => cinema_scan_pick_movie_file($files)];
    }

    if (is_dir($seriesRoot)) {
        $entries = @scandir($seriesRoot) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || cinema_is_ignored_name($entry)) {
                continue;
            }
            $showAbs = $seriesRoot . '/' . $entry;
            if (!is_dir($showAbs)) {
                if (cinema_is_video_filename($entry)) {
                    $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                    if (cinema_is_convertible_ext($ext) && is_file(cinema_mp4_sibling($showAbs))) {
                        continue;
                    }
                    $items[] = ['kind' => 'loose', 'entry' => $entry, 'abs' => $showAbs];
                }
                continue;
            }
            $items[] = ['kind' => 'show', 'entry' => $entry, 'rel' => $entry, 'abs' => $showAbs];
            $videos = cinema_filter_originals_with_playable_sibling(cinema_collect_videos($showAbs));
            foreach ($videos as $abs) {
                $relFromShow = cinema_rel_path($abs, $showAbs);
                $first = explode('/', $relFromShow)[0] ?? '';
                $seasonFolder = is_dir($showAbs . '/' . $first) ? cinema_parse_season_folder($first) : null;
                $items[] = [
                    'kind' => 'episode',
                    'show_rel' => $entry,
                    'entry' => $entry,
                    'abs' => $abs,
                    'season' => $seasonFolder,
                ];
            }
        }
    }

    return $items;
}

function cinema_scan_process_item(array $item, array &$job): void
{
    $moviesRoot = cinema_movies_root();
    $seriesRoot = cinema_series_root();
    $kind = (string) ($item['kind'] ?? '');

    if ($kind === 'movie') {
        $job['seenMovies'][] = cinema_upsert_movie_file((string) $item['abs'], $moviesRoot);
        return;
    }

    if ($kind === 'show') {
        $rel = (string) $item['rel'];
        $showId = cinema_upsert_show((string) $item['entry'], $rel);
        $job['seenShows'][] = $showId;
        $job['showIds'][$rel] = $showId;
        if (!empty($item['abs'])) {
            $side = cinema_sidecar_poster((string) $item['abs']);
            if ($side) {
                $file = cinema_import_sidecar_poster($side, 'show-' . $showId . '.jpg');
                if ($file) {
                    cinema_db()->prepare('UPDATE shows SET poster_file = COALESCE(poster_file, ?) WHERE id = ?')
                        ->execute([$file, $showId]);
                }
            }
        }
        return;
    }

    if ($kind === 'loose') {
        $entry = (string) $item['entry'];
        $showId = cinema_upsert_show(cinema_strip_media_extension($entry), $entry);
        $job['seenShows'][] = $showId;
        $job['seenEpisodes'][] = cinema_upsert_episode($showId, (string) $item['abs'], $seriesRoot, 1);
        return;
    }

    if ($kind === 'episode') {
        $rel = (string) $item['show_rel'];
        $showId = (int) ($job['showIds'][$rel] ?? 0);
        if ($showId <= 0) {
            $showId = cinema_upsert_show((string) ($item['entry'] ?? $rel), $rel);
            $job['showIds'][$rel] = $showId;
            $job['seenShows'][] = $showId;
        }
        $season = $item['season'] ?? null;
        $job['seenEpisodes'][] = cinema_upsert_episode(
            $showId,
            (string) $item['abs'],
            $seriesRoot,
            $season !== null ? (int) $season : null
        );
    }
}

function cinema_scan_finalize(array $job): void
{
    $pdo = cinema_db();
    $moviesRoot = cinema_movies_root();
    $seriesRoot = cinema_series_root();
    $seenMovies = array_values(array_unique(array_map('intval', $job['seenMovies'] ?? [])));
    $seenShows = array_values(array_unique(array_map('intval', $job['seenShows'] ?? [])));
    $seenEpisodes = array_values(array_unique(array_map('intval', $job['seenEpisodes'] ?? [])));

    if ($seenMovies !== []) {
        $pdo->exec('DELETE FROM movies WHERE id NOT IN (' . implode(',', $seenMovies) . ')');
    } elseif (is_dir($moviesRoot)) {
        $pdo->exec('DELETE FROM movies');
    }
    if ($seenEpisodes !== []) {
        $pdo->exec('DELETE FROM episodes WHERE id NOT IN (' . implode(',', $seenEpisodes) . ')');
    } elseif (is_dir($seriesRoot)) {
        $pdo->exec('DELETE FROM episodes');
    }
    if ($seenShows !== []) {
        $pdo->exec('DELETE FROM shows WHERE id NOT IN (' . implode(',', $seenShows) . ')');
    } elseif (is_dir($seriesRoot)) {
        $pdo->exec('DELETE FROM shows');
    }

    cinema_config_save(['last_scan_at' => date('c')]);
    cinema_scan_job_clear();
}

function cinema_scan_library_batch(int $limit = 32, bool $reset = false): array
{
    $limit = max(1, min(40, $limit));
    if ($reset) {
        cinema_scan_job_clear();
    }

    $job = $reset ? null : cinema_scan_job_load();
    if ($job === null) {
        if (function_exists('cinema_convertible_items')) {
            cinema_convertible_items();
        }
        $items = cinema_scan_collect_items();
        $job = [
            'started' => date('c'),
            'items' => $items,
            'index' => 0,
            'seenMovies' => [],
            'seenShows' => [],
            'seenEpisodes' => [],
            'showIds' => [],
        ];
        $total = count($items);
        if ($total === 0) {
            cinema_scan_finalize($job);
            return cinema_stats() + [
                'ok' => true,
                'done' => 0,
                'total' => 0,
                'remaining' => 0,
            ];
        }
        cinema_scan_job_save($job);
        return cinema_stats() + [
            'ok' => true,
            'done' => 0,
            'total' => $total,
            'remaining' => $total,
        ];
    }

    $items = $job['items'] ?? [];
    $index = (int) ($job['index'] ?? 0);
    $total = count($items);
    $end = min($total, $index + $limit);
    for ($i = $index; $i < $end; $i++) {
        cinema_scan_process_item($items[$i] ?? [], $job);
        $job['index'] = $i + 1;
    }

    $done = (int) $job['index'];
    $remaining = max(0, $total - $done);
    if ($remaining === 0) {
        cinema_scan_finalize($job);
    } else {
        cinema_scan_job_save($job);
    }

    return cinema_stats() + [
        'ok' => true,
        'done' => $done,
        'total' => $total,
        'remaining' => $remaining,
    ];
}

function cinema_list_movies(string $q = '', string $genre = '', string $sort = 'title', string $dir = ''): array
{
    $sort = cinema_normalize_sort($sort);
    $dir = cinema_normalize_dir($dir, $sort);
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';
    $sql = 'SELECT * FROM movies';
    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = 'title LIKE ?';
        $params[] = '%' . $q . '%';
    }
    if ($genre !== '') {
        $where[] = 'genres LIKE ?';
        $params[] = '%' . $genre . '%';
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $order = match ($sort) {
        'year' => 'year ' . $dirSql . ', title COLLATE NOCASE ASC',
        'added' => 'file_mtime ' . $dirSql,
        'rating' => 'rating ' . $dirSql . ', title COLLATE NOCASE ASC',
        default => 'title COLLATE NOCASE ' . $dirSql,
    };
    $sql .= ' ORDER BY ' . $order;
    $st = cinema_db()->prepare($sql);
    $st->execute($params);
    return cinema_with_movie_watch_status($st->fetchAll());
}

function cinema_list_shows(string $q = '', string $genre = '', string $sort = 'title', string $dir = ''): array
{
    $sort = cinema_normalize_sort($sort);
    $dir = cinema_normalize_dir($dir, $sort);
    $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';
    $sql = 'SELECT s.*,
                   (SELECT COUNT(*) FROM episodes e WHERE e.show_id = s.id) AS episode_count,
                   (SELECT COUNT(*) FROM episodes e
                    WHERE e.show_id = s.id AND lower(IFNULL(e.ext, \'\')) != \'mp4\') AS non_mp4_count
            FROM shows s';
    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = 's.title LIKE ?';
        $params[] = '%' . $q . '%';
    }
    if ($genre !== '') {
        $where[] = 's.genres LIKE ?';
        $params[] = '%' . $genre . '%';
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $order = match ($sort) {
        'year' => 's.year ' . $dirSql . ', s.title COLLATE NOCASE ASC',
        'added' => 's.updated_at ' . $dirSql,
        'rating' => 's.rating ' . $dirSql . ', s.title COLLATE NOCASE ASC',
        default => 's.title COLLATE NOCASE ' . $dirSql,
    };
    $sql .= ' ORDER BY ' . $order;
    $st = cinema_db()->prepare($sql);
    $st->execute($params);
    return cinema_with_show_watch_status($st->fetchAll());
}

function cinema_get_movie(int $id): ?array
{
    $st = cinema_db()->prepare('SELECT * FROM movies WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function cinema_get_show(int $id): ?array
{
    $st = cinema_db()->prepare('SELECT * FROM shows WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function cinema_get_episode(int $id): ?array
{
    $st = cinema_db()->prepare(
        'SELECT e.*, s.title AS show_title, s.id AS show_id_join
         FROM episodes e JOIN shows s ON s.id = e.show_id WHERE e.id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function cinema_show_episodes(int $showId): array
{
    $st = cinema_db()->prepare(
        'SELECT e.*, p.position_seconds, p.duration_seconds AS progress_duration
         FROM episodes e
         LEFT JOIN progress p ON p.item_type = \'episode\' AND p.item_id = e.id
         WHERE e.show_id = ?
         ORDER BY e.season, e.episode, e.title'
    );
    $st->execute([$showId]);
    return $st->fetchAll();
}

function cinema_similar_movies(array $movie, int $limit = 8): array
{
    $limit = max(1, min(24, $limit));
    $genres = cinema_decode_json_list($movie['genres'] ?? null);
    if ($genres === []) {
        $st = cinema_db()->prepare('SELECT * FROM movies WHERE id != ? ORDER BY rating DESC LIMIT ' . $limit);
        $st->execute([(int) $movie['id']]);
        return cinema_with_movie_watch_status($st->fetchAll());
    }
    $where = [];
    $params = [];
    foreach ($genres as $g) {
        $where[] = 'genres LIKE ?';
        $params[] = '%' . $g . '%';
    }
    $sql = 'SELECT * FROM movies WHERE id != ? AND (' . implode(' OR ', $where) . ') ORDER BY rating DESC LIMIT ' . (int) $limit;
    $st = cinema_db()->prepare($sql);
    $st->execute(array_merge([(int) $movie['id']], $params));
    return cinema_with_movie_watch_status($st->fetchAll());
}

function cinema_similar_shows(array $show, int $limit = 8): array
{
    $limit = max(1, min(24, $limit));
    $genres = cinema_decode_json_list($show['genres'] ?? null);
    $pdo = cinema_db();
    if ($genres === []) {
        $st = $pdo->prepare('SELECT * FROM shows WHERE id != ? ORDER BY rating DESC LIMIT ' . $limit);
        $st->execute([(int) $show['id']]);
        return cinema_with_show_watch_status($st->fetchAll());
    }
    $where = [];
    $params = [];
    foreach ($genres as $g) {
        $where[] = 'genres LIKE ?';
        $params[] = '%' . $g . '%';
    }
    $sql = 'SELECT * FROM shows WHERE id != ? AND (' . implode(' OR ', $where) . ') ORDER BY rating DESC LIMIT ' . (int) $limit;
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([(int) $show['id']], $params));
    return cinema_with_show_watch_status($st->fetchAll());
}

function cinema_all_genres(): array
{
    $names = [];
    foreach (['movies', 'shows'] as $table) {
        $rows = cinema_db()->query('SELECT genres FROM ' . $table . ' WHERE genres IS NOT NULL AND genres != \'\'' )->fetchAll();
        foreach ($rows as $row) {
            foreach (cinema_decode_json_list($row['genres']) as $g) {
                $names[$g] = true;
            }
        }
    }
    $list = array_keys($names);
    natcasesort($list);
    return array_values($list);
}

function cinema_continue_items(int $limit = 12): array
{
    $limit = max(1, min(48, $limit));
    $st = cinema_db()->query(
        "SELECT * FROM progress
         WHERE position_seconds > 20
           AND (duration_seconds <= 0 OR position_seconds / duration_seconds < 0.92)
         ORDER BY updated_at DESC
         LIMIT " . $limit
    );
    $out = [];
    foreach ($st->fetchAll() as $p) {
        if ($p['item_type'] === 'movie') {
            $item = cinema_get_movie((int) $p['item_id']);
            if (!$item) {
                continue;
            }
            $item['_kind'] = 'movie';
            $item['_progress'] = $p;
            $out[] = $item;
        } else {
            $item = cinema_get_episode((int) $p['item_id']);
            if (!$item) {
                continue;
            }
            $item['_kind'] = 'episode';
            $item['_progress'] = $p;
            $out[] = $item;
        }
    }
    return $out;
}

function cinema_recent_movies(int $limit = 12): array
{
    $limit = max(1, min(48, $limit));
    return cinema_with_movie_watch_status(
        cinema_db()->query('SELECT * FROM movies ORDER BY file_mtime DESC LIMIT ' . $limit)->fetchAll()
    );
}

function cinema_recent_shows(int $limit = 12): array
{
    $limit = max(1, min(48, $limit));
    return cinema_with_show_watch_status(
        cinema_db()->query(
            'SELECT s.* FROM shows s
             ORDER BY (SELECT MAX(e.file_mtime) FROM episodes e WHERE e.show_id = s.id) DESC
             LIMIT ' . $limit
        )->fetchAll()
    );
}

function cinema_with_movie_watch_status(array $movies): array
{
    if ($movies === []) {
        return $movies;
    }
    $ids = [];
    foreach ($movies as $movie) {
        $ids[] = (int) $movie['id'];
    }
    $map = [];
    foreach (array_chunk(array_values(array_unique($ids)), 400) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = cinema_db()->prepare(
            "SELECT item_id, position_seconds, duration_seconds
             FROM progress WHERE item_type = 'movie' AND item_id IN ($placeholders)"
        );
        $st->execute($chunk);
        foreach ($st->fetchAll() as $row) {
            $map[(int) $row['item_id']] = cinema_watch_status_from_progress(
                (float) $row['position_seconds'],
                (float) $row['duration_seconds']
            );
        }
    }
    foreach ($movies as &$movie) {
        $movie['_watch'] = $map[(int) $movie['id']] ?? 'unwatched';
    }
    unset($movie);
    return $movies;
}

function cinema_with_show_watch_status(array $shows): array
{
    if ($shows === []) {
        return $shows;
    }
    $ids = [];
    foreach ($shows as $show) {
        $ids[] = (int) $show['id'];
    }
    $map = [];
    $nonMp4 = [];
    foreach (array_chunk(array_values(array_unique($ids)), 400) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = cinema_db()->prepare(
            "SELECT e.show_id,
                    COUNT(*) AS ep_total,
                    SUM(CASE
                        WHEN p.duration_seconds > 0 AND p.position_seconds / p.duration_seconds >= 0.92 THEN 1
                        ELSE 0
                    END) AS ep_watched,
                    SUM(CASE WHEN p.position_seconds > 20 THEN 1 ELSE 0 END) AS ep_started,
                    SUM(CASE WHEN lower(IFNULL(e.ext, '')) != 'mp4' THEN 1 ELSE 0 END) AS non_mp4
             FROM episodes e
             LEFT JOIN progress p ON p.item_type = 'episode' AND p.item_id = e.id
             WHERE e.show_id IN ($placeholders)
             GROUP BY e.show_id"
        );
        $st->execute($chunk);
        foreach ($st->fetchAll() as $row) {
            $total = (int) $row['ep_total'];
            $watched = (int) $row['ep_watched'];
            $started = (int) $row['ep_started'];
            if ($total > 0 && $watched >= $total) {
                $status = 'watched';
            } elseif ($started > 0) {
                $status = 'progress';
            } else {
                $status = 'unwatched';
            }
            $showId = (int) $row['show_id'];
            $map[$showId] = $status;
            $nonMp4[$showId] = (int) $row['non_mp4'] > 0;
        }
    }
    foreach ($shows as &$show) {
        $id = (int) $show['id'];
        $show['_watch'] = $map[$id] ?? 'unwatched';
        if (!isset($show['non_mp4_count'])) {
            $show['_has_non_mp4'] = !empty($nonMp4[$id]);
        } else {
            $show['_has_non_mp4'] = (int) $show['non_mp4_count'] > 0;
        }
    }
    unset($show);
    return $shows;
}

function cinema_default_show_season(array $seasons): int
{
    if ($seasons === []) {
        return 1;
    }
    foreach ($seasons as $num => $eps) {
        foreach ($eps as $ep) {
            $status = cinema_watch_status_from_progress(
                isset($ep['position_seconds']) ? (float) $ep['position_seconds'] : 0,
                isset($ep['progress_duration']) ? (float) $ep['progress_duration'] : (float) ($ep['duration_seconds'] ?? 0)
            );
            if ($status !== 'watched') {
                return (int) $num;
            }
        }
    }
    return isset($seasons[1]) ? 1 : (int) array_key_first($seasons);
}

function cinema_next_episode(int $showId): ?array
{
    $episodes = cinema_show_episodes($showId);
    $firstUnwatched = null;
    foreach ($episodes as $ep) {
        $pos = (float) ($ep['position_seconds'] ?? 0);
        $dur = (float) ($ep['progress_duration'] ?? $ep['duration_seconds'] ?? 0);
        $done = $dur > 0 && $pos / $dur >= 0.92;
        if (!$done) {
            return $ep;
        }
        if ($firstUnwatched === null && $pos <= 20) {
            $firstUnwatched = $ep;
        }
    }
    return $episodes[0] ?? null;
}

/** Next episode in season/episode order after the given episode, or null if last. */
function cinema_following_episode(int $episodeId): ?array
{
    if ($episodeId <= 0) {
        return null;
    }
    $current = cinema_get_episode($episodeId);
    if (!$current) {
        return null;
    }
    $showId = (int) $current['show_id'];
    $season = (int) $current['season'];
    $episode = (int) $current['episode'];
    $episodes = cinema_show_episodes($showId);
    foreach ($episodes as $ep) {
        $s = (int) $ep['season'];
        $e = (int) $ep['episode'];
        if ($s > $season || ($s === $season && $e > $episode)) {
            return $ep;
        }
    }
    return null;
}

function cinema_resolve_media_abs(string $kind, array $row): ?string
{
    if ($kind === 'movie') {
        $root = cinema_movies_root();
    } else {
        $root = cinema_series_root();
    }
    $abs = $root . '/' . ltrim(str_replace('\\', '/', (string) $row['path']), '/');
    if (!is_file($abs) || !cinema_path_inside($abs, $root)) {
        return null;
    }
    return $abs;
}
