<?php
declare(strict_types=1);

function cinema_db(): PDO
{
    if (isset($GLOBALS['cinema_pdo']) && $GLOBALS['cinema_pdo'] instanceof PDO) {
        return $GLOBALS['cinema_pdo'];
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('PHP pdo_sqlite extension is not available.');
    }

    cinema_ensure_data_dirs();

    $pdo = new PDO('sqlite:' . CINEMA_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $GLOBALS['cinema_pdo'] = $pdo;

    return $pdo;
}

function cinema_db_init(): void
{
    $pdo = cinema_db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS movies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        path TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        year INTEGER,
        overview TEXT,
        tagline TEXT,
        genres TEXT,
        cast_json TEXT,
        runtime INTEGER,
        rating REAL,
        tmdb_id INTEGER,
        trailer_key TEXT,
        poster_file TEXT,
        backdrop_file TEXT,
        ext TEXT,
        playable INTEGER NOT NULL DEFAULT 1,
        file_size INTEGER NOT NULL DEFAULT 0,
        file_mtime INTEGER NOT NULL DEFAULT 0,
        duration_seconds INTEGER,
        tmdb_status TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shows (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        path TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        year INTEGER,
        overview TEXT,
        genres TEXT,
        cast_json TEXT,
        rating REAL,
        tmdb_id INTEGER,
        trailer_key TEXT,
        poster_file TEXT,
        backdrop_file TEXT,
        tmdb_status TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS episodes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        show_id INTEGER NOT NULL,
        path TEXT NOT NULL UNIQUE,
        season INTEGER NOT NULL DEFAULT 1,
        episode INTEGER NOT NULL DEFAULT 1,
        title TEXT,
        overview TEXT,
        still_file TEXT,
        ext TEXT,
        playable INTEGER NOT NULL DEFAULT 1,
        file_size INTEGER NOT NULL DEFAULT 0,
        file_mtime INTEGER NOT NULL DEFAULT 0,
        duration_seconds INTEGER,
        FOREIGN KEY (show_id) REFERENCES shows(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS progress (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_type TEXT NOT NULL CHECK(item_type IN ('movie', 'episode')),
        item_id INTEGER NOT NULL,
        position_seconds REAL NOT NULL DEFAULT 0,
        duration_seconds REAL NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(item_type, item_id)
    )");

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_movies_title ON movies(title COLLATE NOCASE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_shows_title ON shows(title COLLATE NOCASE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_episodes_show ON episodes(show_id, season, episode)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_progress_updated ON progress(updated_at)');
}

function cinema_stats(): array
{
    $pdo = cinema_db();
    return [
        'movies' => (int) $pdo->query('SELECT COUNT(*) FROM movies')->fetchColumn(),
        'shows' => (int) $pdo->query('SELECT COUNT(*) FROM shows')->fetchColumn(),
        'episodes' => (int) $pdo->query('SELECT COUNT(*) FROM episodes')->fetchColumn(),
        'movies_unmatched' => (int) $pdo->query("SELECT COUNT(*) FROM movies WHERE tmdb_id IS NULL")->fetchColumn(),
        'shows_unmatched' => (int) $pdo->query("SELECT COUNT(*) FROM shows WHERE tmdb_id IS NULL")->fetchColumn(),
    ];
}

function cinema_save_progress(string $type, int $id, float $position, float $duration): void
{
    if (!in_array($type, ['movie', 'episode'], true) || $id <= 0) {
        return;
    }
    $pdo = cinema_db();
    $st = $pdo->prepare(
        "INSERT INTO progress (item_type, item_id, position_seconds, duration_seconds, updated_at)
         VALUES (?, ?, ?, ?, datetime('now'))
         ON CONFLICT(item_type, item_id) DO UPDATE SET
            position_seconds = excluded.position_seconds,
            duration_seconds = excluded.duration_seconds,
            updated_at = datetime('now')"
    );
    $st->execute([$type, $id, $position, $duration]);
}

function cinema_get_progress(string $type, int $id): ?array
{
    $st = cinema_db()->prepare('SELECT * FROM progress WHERE item_type = ? AND item_id = ?');
    $st->execute([$type, $id]);
    $row = $st->fetch();
    return $row ?: null;
}

function cinema_media_duration_seconds(string $type, int $id): float
{
    if ($type === 'movie') {
        $row = cinema_get_movie($id);
        if (!$row) {
            return 1.0;
        }
        if (!empty($row['duration_seconds']) && (float) $row['duration_seconds'] > 0) {
            return (float) $row['duration_seconds'];
        }
        if (!empty($row['runtime']) && (int) $row['runtime'] > 0) {
            return (float) $row['runtime'] * 60;
        }
        return 1.0;
    }
    $row = cinema_get_episode($id);
    if ($row && !empty($row['duration_seconds']) && (float) $row['duration_seconds'] > 0) {
        return (float) $row['duration_seconds'];
    }
    return 1.0;
}

function cinema_show_is_watched(int $showId): bool
{
    if ($showId <= 0) {
        return false;
    }
    $st = cinema_db()->prepare(
        "SELECT COUNT(*) AS ep_total,
                SUM(CASE
                    WHEN p.duration_seconds > 0 AND p.position_seconds / p.duration_seconds >= 0.92 THEN 1
                    ELSE 0
                END) AS ep_watched
         FROM episodes e
         LEFT JOIN progress p ON p.item_type = 'episode' AND p.item_id = e.id
         WHERE e.show_id = ?"
    );
    $st->execute([$showId]);
    $row = $st->fetch();
    $total = (int) ($row['ep_total'] ?? 0);
    $watched = (int) ($row['ep_watched'] ?? 0);
    return $total > 0 && $watched >= $total;
}

function cinema_mark_show(int $showId, bool $watched): array
{
    if ($showId <= 0) {
        return ['ok' => false, 'watched' => false, 'error' => 'Invalid'];
    }
    $st = cinema_db()->prepare('SELECT id FROM shows WHERE id = ?');
    $st->execute([$showId]);
    if (!$st->fetch()) {
        return ['ok' => false, 'watched' => false, 'error' => 'Invalid'];
    }
    $pdo = cinema_db();
    if ($watched) {
        $pdo->prepare(
            "INSERT INTO progress (item_type, item_id, position_seconds, duration_seconds, updated_at)
             SELECT 'episode', e.id,
                    CASE WHEN e.duration_seconds IS NOT NULL AND e.duration_seconds > 0 THEN e.duration_seconds ELSE 1 END,
                    CASE WHEN e.duration_seconds IS NOT NULL AND e.duration_seconds > 0 THEN e.duration_seconds ELSE 1 END,
                    datetime('now')
             FROM episodes e
             WHERE e.show_id = ?
             ON CONFLICT(item_type, item_id) DO UPDATE SET
                position_seconds = excluded.position_seconds,
                duration_seconds = excluded.duration_seconds,
                updated_at = datetime('now')"
        )->execute([$showId]);
    } else {
        $pdo->prepare(
            "DELETE FROM progress
             WHERE item_type = 'episode'
               AND item_id IN (SELECT id FROM episodes WHERE show_id = ?)"
        )->execute([$showId]);
    }
    return ['ok' => true, 'watched' => $watched, 'show_watched' => $watched];
}

function cinema_mark_watched(string $type, int $id): array
{
    if ($type === 'show') {
        return cinema_mark_show($id, true);
    }
    if (!in_array($type, ['movie', 'episode'], true) || $id <= 0) {
        return ['ok' => false, 'watched' => false, 'error' => 'Invalid'];
    }
    $duration = cinema_media_duration_seconds($type, $id);
    cinema_save_progress($type, $id, $duration, $duration);
    $result = ['ok' => true, 'watched' => true];
    if ($type === 'episode') {
        $st = cinema_db()->prepare('SELECT show_id FROM episodes WHERE id = ?');
        $st->execute([$id]);
        $result['show_watched'] = cinema_show_is_watched((int) ($st->fetchColumn() ?: 0));
    }
    return $result;
}

function cinema_mark_unwatched(string $type, int $id): array
{
    if ($type === 'show') {
        return cinema_mark_show($id, false);
    }
    if (!in_array($type, ['movie', 'episode'], true) || $id <= 0) {
        return ['ok' => false, 'watched' => true, 'error' => 'Invalid'];
    }
    $st = cinema_db()->prepare('DELETE FROM progress WHERE item_type = ? AND item_id = ?');
    $st->execute([$type, $id]);
    $result = ['ok' => true, 'watched' => false];
    if ($type === 'episode') {
        $showSt = cinema_db()->prepare('SELECT show_id FROM episodes WHERE id = ?');
        $showSt->execute([$id]);
        $result['show_watched'] = cinema_show_is_watched((int) ($showSt->fetchColumn() ?: 0));
    }
    return $result;
}

function cinema_clear_progress(): array
{
    $pdo = cinema_db();
    $where = 'position_seconds > 20
              AND (duration_seconds <= 0 OR position_seconds / duration_seconds < 0.92)';
    $count = (int) $pdo->query("SELECT COUNT(*) FROM progress WHERE $where")->fetchColumn();
    $pdo->exec("DELETE FROM progress WHERE $where");
    return ['ok' => true, 'cleared' => $count];
}
