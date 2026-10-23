<?php
declare(strict_types=1);

function cinema_tmdb_key(): string
{
    return trim((string) cinema_config_get('tmdb_key', ''));
}

function cinema_tmdb_language(): string
{
    $lang = trim((string) cinema_config_get('tmdb_language', 'en-US'));
    return $lang !== '' ? $lang : 'en-US';
}

function cinema_tmdb_get(string $path, array $query = []): ?array
{
    $key = cinema_tmdb_key();
    if ($key === '') {
        return null;
    }
    $query['api_key'] = $key;
    if (!isset($query['language'])) {
        $query['language'] = cinema_tmdb_language();
    }
    $url = 'https://api.themoviedb.org/3/' . ltrim($path, '/') . '?' . http_build_query($query);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 12,
            'header' => "Accept: application/json\r\nUser-Agent: Valhalla-Cinema/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function cinema_tmdb_search_movie(string $title, ?int $year): ?array
{
    $query = ['query' => $title];
    if ($year) {
        $query['year'] = $year;
    }
    $data = cinema_tmdb_get('search/movie', $query);
    $results = $data['results'] ?? [];
    if (!is_array($results) || $results === []) {
        if ($year) {
            $data = cinema_tmdb_get('search/movie', ['query' => $title]);
            $results = $data['results'] ?? [];
        }
    }
    if (!is_array($results) || $results === []) {
        return null;
    }

    if ($year) {
        foreach ($results as $row) {
            $rd = (string) ($row['release_date'] ?? '');
            if (str_starts_with($rd, (string) $year)) {
                return $row;
            }
        }
    }
    return $results[0];
}

function cinema_tmdb_search_tv(string $title, ?int $year): ?array
{
    $query = ['query' => $title];
    if ($year) {
        $query['first_air_date_year'] = $year;
    }
    $data = cinema_tmdb_get('search/tv', $query);
    $results = $data['results'] ?? [];
    if (!is_array($results) || $results === []) {
        if ($year) {
            $data = cinema_tmdb_get('search/tv', ['query' => $title]);
            $results = $data['results'] ?? [];
        }
    }
    if (!is_array($results) || $results === []) {
        return null;
    }
    if ($year) {
        foreach ($results as $row) {
            $rd = (string) ($row['first_air_date'] ?? '');
            if (str_starts_with($rd, (string) $year)) {
                return $row;
            }
        }
    }
    return $results[0];
}

function cinema_tmdb_movie_details(int $tmdbId): ?array
{
    return cinema_tmdb_get('movie/' . $tmdbId, ['append_to_response' => 'credits,videos']);
}

function cinema_tmdb_tv_details(int $tmdbId): ?array
{
    return cinema_tmdb_get('tv/' . $tmdbId, ['append_to_response' => 'credits,videos']);
}

function cinema_tmdb_season(int $tmdbId, int $season): ?array
{
    return cinema_tmdb_get('tv/' . $tmdbId . '/season/' . $season);
}

function cinema_tmdb_pick_trailer(?array $videos): ?string
{
    $results = $videos['results'] ?? [];
    if (!is_array($results)) {
        return null;
    }
    $best = null;
    foreach ($results as $row) {
        if (($row['site'] ?? '') !== 'YouTube') {
            continue;
        }
        $type = (string) ($row['type'] ?? '');
        $key = (string) ($row['key'] ?? '');
        if ($key === '') {
            continue;
        }
        if ($type === 'Trailer') {
            return $key;
        }
        if ($best === null) {
            $best = $key;
        }
    }
    return $best;
}

function cinema_tmdb_cast_names(?array $credits, int $limit = 8): array
{
    $cast = $credits['cast'] ?? [];
    if (!is_array($cast)) {
        return [];
    }
    $names = [];
    foreach ($cast as $person) {
        $name = trim((string) ($person['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $names[] = $name;
        if (count($names) >= $limit) {
            break;
        }
    }
    return $names;
}

function cinema_tmdb_genre_names(?array $genres): array
{
    if (!is_array($genres)) {
        return [];
    }
    $out = [];
    foreach ($genres as $g) {
        $name = trim((string) ($g['name'] ?? ''));
        if ($name !== '') {
            $out[] = $name;
        }
    }
    return $out;
}

function cinema_tmdb_download_image(?string $path, string $dest, string $size = 'w500'): bool
{
    if ($path === null || $path === '' || strpos($path, '/') !== 0) {
        return false;
    }
    $url = 'https://image.tmdb.org/t/p/' . $size . $path;
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 20, 'header' => "User-Agent: Valhalla-Cinema/1.0\r\n"],
    ]);
    $bin = @file_get_contents($url, false, $ctx);
    if ($bin === false || $bin === '') {
        return false;
    }
    cinema_ensure_data_dirs();
    return @file_put_contents($dest, $bin) !== false;
}

function cinema_apply_tmdb_movie(array $movie, array $details): void
{
    $id = (int) $movie['id'];
    $posterFile = $movie['poster_file'] ?? null;
    $backdropFile = $movie['backdrop_file'] ?? null;

    if (!empty($details['poster_path'])) {
        $file = 'movie-' . $id . '.jpg';
        if (cinema_tmdb_download_image((string) $details['poster_path'], CINEMA_POSTERS . '/' . $file, 'w500')) {
            $posterFile = $file;
        }
    }

    $year = null;
    if (!empty($details['release_date']) && preg_match('/^(\d{4})/', (string) $details['release_date'], $m)) {
        $year = (int) $m[1];
    }

    $st = cinema_db()->prepare(
        "UPDATE movies SET
            title = COALESCE(NULLIF(?, ''), title),
            year = COALESCE(?, year),
            overview = ?,
            tagline = ?,
            genres = ?,
            cast_json = ?,
            runtime = ?,
            rating = ?,
            tmdb_id = ?,
            trailer_key = ?,
            poster_file = ?,
            backdrop_file = ?,
            tmdb_status = 'ok',
            updated_at = datetime('now')
         WHERE id = ?"
    );
    $st->execute([
        (string) ($details['title'] ?? ''),
        $year,
        (string) ($details['overview'] ?? ''),
        (string) ($details['tagline'] ?? ''),
        json_encode(cinema_tmdb_genre_names($details['genres'] ?? []), JSON_UNESCAPED_UNICODE),
        json_encode(cinema_tmdb_cast_names($details['credits'] ?? null), JSON_UNESCAPED_UNICODE),
        isset($details['runtime']) ? (int) $details['runtime'] : null,
        isset($details['vote_average']) ? (float) $details['vote_average'] : null,
        (int) $details['id'],
        cinema_tmdb_pick_trailer($details['videos'] ?? null),
        $posterFile,
        $backdropFile,
        $id,
    ]);
}

function cinema_apply_tmdb_show(array $show, array $details): void
{
    $id = (int) $show['id'];
    $posterFile = $show['poster_file'] ?? null;
    $backdropFile = $show['backdrop_file'] ?? null;

    if (!empty($details['poster_path'])) {
        $file = 'show-' . $id . '.jpg';
        if (cinema_tmdb_download_image((string) $details['poster_path'], CINEMA_POSTERS . '/' . $file, 'w500')) {
            $posterFile = $file;
        }
    }

    $year = null;
    if (!empty($details['first_air_date']) && preg_match('/^(\d{4})/', (string) $details['first_air_date'], $m)) {
        $year = (int) $m[1];
    }

    $st = cinema_db()->prepare(
        "UPDATE shows SET
            title = COALESCE(NULLIF(?, ''), title),
            year = COALESCE(?, year),
            overview = ?,
            genres = ?,
            cast_json = ?,
            rating = ?,
            tmdb_id = ?,
            trailer_key = ?,
            poster_file = ?,
            backdrop_file = ?,
            tmdb_status = 'ok',
            updated_at = datetime('now')
         WHERE id = ?"
    );
    $st->execute([
        (string) ($details['name'] ?? ''),
        $year,
        (string) ($details['overview'] ?? ''),
        json_encode(cinema_tmdb_genre_names($details['genres'] ?? []), JSON_UNESCAPED_UNICODE),
        json_encode(cinema_tmdb_cast_names($details['credits'] ?? null), JSON_UNESCAPED_UNICODE),
        isset($details['vote_average']) ? (float) $details['vote_average'] : null,
        (int) $details['id'],
        cinema_tmdb_pick_trailer($details['videos'] ?? null),
        $posterFile,
        $backdropFile,
        $id,
    ]);
}

function cinema_apply_tmdb_episodes(array $show, bool $missingOnly = false): int
{
    $tmdbId = (int) ($show['tmdb_id'] ?? 0);
    if ($tmdbId <= 0) {
        return 0;
    }
    $sql = 'SELECT id, season, episode FROM episodes WHERE show_id = ?';
    if ($missingOnly) {
        $sql .= ' AND overview IS NULL';
    }
    $st = cinema_db()->prepare($sql);
    $st->execute([(int) $show['id']]);
    $episodes = $st->fetchAll();
    $bySeason = [];
    foreach ($episodes as $ep) {
        $bySeason[(int) $ep['season']][] = $ep;
    }

    $updated = 0;
    foreach ($bySeason as $seasonNum => $eps) {
        $payload = cinema_tmdb_season($tmdbId, $seasonNum);
        if (!$payload || empty($payload['episodes']) || !is_array($payload['episodes'])) {
            continue;
        }
        $map = [];
        foreach ($payload['episodes'] as $row) {
            $map[(int) ($row['episode_number'] ?? 0)] = $row;
        }
        foreach ($eps as $ep) {
            $info = $map[(int) $ep['episode']] ?? null;
            if (!$info) {
                if ($missingOnly) {
                    cinema_db()->prepare('UPDATE episodes SET overview = COALESCE(overview, ?) WHERE id = ?')
                        ->execute(['', (int) $ep['id']]);
                }
                continue;
            }
            $stillFile = null;
            if (!empty($info['still_path'])) {
                $file = 'ep-' . $ep['id'] . '.jpg';
                if (cinema_tmdb_download_image((string) $info['still_path'], CINEMA_POSTERS . '/' . $file, 'w300')) {
                    $stillFile = $file;
                }
            }
            $runtime = isset($info['runtime']) ? (int) $info['runtime'] * 60 : null;
            $upd = cinema_db()->prepare(
                'UPDATE episodes SET title = COALESCE(NULLIF(?, ""), title), overview = ?, still_file = COALESCE(?, still_file), duration_seconds = COALESCE(duration_seconds, ?) WHERE id = ?'
            );
            $upd->execute([
                (string) ($info['name'] ?? ''),
                (string) ($info['overview'] ?? ''),
                $stillFile,
                $runtime,
                (int) $ep['id'],
            ]);
            $updated++;
        }
    }
    if ($missingOnly) {
        cinema_db()->prepare(
            'UPDATE episodes SET overview = COALESCE(overview, ?) WHERE show_id = ? AND overview IS NULL'
        )->execute(['', (int) $show['id']]);
    }
    return $updated;
}

function cinema_meta_pending_count(): int
{
    return (int) cinema_db()->query(
        "SELECT (
            (SELECT COUNT(*) FROM movies WHERE tmdb_id IS NULL AND IFNULL(tmdb_status,'') != 'failed')
          + (SELECT COUNT(*) FROM shows WHERE tmdb_id IS NULL AND IFNULL(tmdb_status,'') != 'failed')
          + (SELECT COUNT(*) FROM shows s
             WHERE s.tmdb_id IS NOT NULL
               AND EXISTS (SELECT 1 FROM episodes e WHERE e.show_id = s.id AND e.overview IS NULL))
        )"
    )->fetchColumn();
}

function cinema_clear_metadata(): array
{
    $pdo = cinema_db();
    $pdo->beginTransaction();
    try {
        $movies = $pdo->query('SELECT id, path FROM movies')->fetchAll();
        $movieStmt = $pdo->prepare(
            "UPDATE movies SET title = ?, year = ?, overview = NULL, tagline = NULL, genres = NULL,
             cast_json = NULL, runtime = NULL, rating = NULL, tmdb_id = NULL, trailer_key = NULL,
             poster_file = NULL, backdrop_file = NULL, tmdb_status = NULL, updated_at = datetime('now')
             WHERE id = ?"
        );
        foreach ($movies as $movie) {
            $parts = explode('/', str_replace('\\', '/', (string) $movie['path']));
            $parsed = count($parts) > 1
                ? cinema_parse_title_year($parts[0])
                : cinema_parse_title_year(basename((string) $movie['path']));
            $movieStmt->execute([$parsed['title'], $parsed['year'], (int) $movie['id']]);
        }

        $shows = $pdo->query('SELECT id, path FROM shows')->fetchAll();
        $showStmt = $pdo->prepare(
            "UPDATE shows SET title = ?, year = ?, overview = NULL, genres = NULL, cast_json = NULL,
             rating = NULL, tmdb_id = NULL, trailer_key = NULL, poster_file = NULL, backdrop_file = NULL,
             tmdb_status = NULL, updated_at = datetime('now') WHERE id = ?"
        );
        foreach ($shows as $show) {
            $parsed = cinema_parse_title_year(basename(str_replace('\\', '/', (string) $show['path'])));
            $showStmt->execute([$parsed['title'], $parsed['year'], (int) $show['id']]);
        }

        $episodes = $pdo->query('SELECT id, path FROM episodes')->fetchAll();
        $epStmt = $pdo->prepare('UPDATE episodes SET title = ?, overview = NULL, still_file = NULL WHERE id = ?');
        foreach ($episodes as $episode) {
            $title = cinema_episode_title_from_filename(basename((string) $episode['path']));
            $epStmt->execute([$title !== '' ? $title : null, (int) $episode['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $deleted = 0;
    if (is_dir(CINEMA_POSTERS)) {
        $files = @scandir(CINEMA_POSTERS) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $abs = CINEMA_POSTERS . '/' . $file;
            if (is_file($abs) && @unlink($abs)) {
                $deleted++;
            }
        }
    }

    return cinema_stats() + ['deleted_files' => $deleted];
}

function cinema_fetch_metadata_batch(int $limit = 4): array
{
    if (cinema_tmdb_key() === '') {
        return [
            'processed' => 0,
            'remaining' => 0,
            'pending_before' => 0,
            'error' => cinema_t('no_tmdb_key'),
        ];
    }

    $pdo = cinema_db();
    $pendingBefore = cinema_meta_pending_count();
    $processed = 0;

    $movies = $pdo->query(
        "SELECT * FROM movies WHERE tmdb_id IS NULL AND IFNULL(tmdb_status, '') != 'failed' ORDER BY id LIMIT " . (int) $limit
    )->fetchAll();
    foreach ($movies as $movie) {
        $hit = cinema_tmdb_search_movie((string) $movie['title'], $movie['year'] !== null ? (int) $movie['year'] : null);
        if (!$hit || empty($hit['id'])) {
            $pdo->prepare("UPDATE movies SET tmdb_status = 'failed' WHERE id = ?")->execute([(int) $movie['id']]);
            $processed++;
            continue;
        }
        $details = cinema_tmdb_movie_details((int) $hit['id']);
        if (!$details) {
            $pdo->prepare("UPDATE movies SET tmdb_status = 'failed' WHERE id = ?")->execute([(int) $movie['id']]);
            $processed++;
            continue;
        }
        cinema_apply_tmdb_movie($movie, $details);
        $processed++;
    }

    $left = $limit - $processed;
    if ($left > 0) {
        $shows = $pdo->query(
            "SELECT * FROM shows WHERE tmdb_id IS NULL AND IFNULL(tmdb_status, '') != 'failed' ORDER BY id LIMIT " . (int) $left
        )->fetchAll();
        foreach ($shows as $show) {
            $hit = cinema_tmdb_search_tv((string) $show['title'], $show['year'] !== null ? (int) $show['year'] : null);
            if (!$hit || empty($hit['id'])) {
                $pdo->prepare("UPDATE shows SET tmdb_status = 'failed' WHERE id = ?")->execute([(int) $show['id']]);
                $processed++;
                continue;
            }
            $details = cinema_tmdb_tv_details((int) $hit['id']);
            if (!$details) {
                $pdo->prepare("UPDATE shows SET tmdb_status = 'failed' WHERE id = ?")->execute([(int) $show['id']]);
                $processed++;
                continue;
            }
            cinema_apply_tmdb_show($show, $details);
            $fresh = $pdo->prepare('SELECT * FROM shows WHERE id = ?');
            $fresh->execute([(int) $show['id']]);
            $row = $fresh->fetch();
            if ($row) {
                cinema_apply_tmdb_episodes($row);
            }
            $processed++;
        }
    }

    $left = $limit - $processed;
    if ($left > 0) {
        $shows = $pdo->query(
            "SELECT s.* FROM shows s
             WHERE s.tmdb_id IS NOT NULL
               AND EXISTS (SELECT 1 FROM episodes e WHERE e.show_id = s.id AND e.overview IS NULL)
             ORDER BY s.id LIMIT " . (int) $left
        )->fetchAll();
        foreach ($shows as $show) {
            cinema_apply_tmdb_episodes($show, true);
            $processed++;
        }
    }

    $remaining = cinema_meta_pending_count();

    return [
        'processed' => $processed,
        'remaining' => $remaining,
        'pending_before' => $pendingBefore,
        'error' => '',
    ];
}
