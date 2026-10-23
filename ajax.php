<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'scan') {
    @set_time_limit(120);
    try {
        $reset = (string) ($_POST['reset'] ?? $_GET['reset'] ?? '') === '1';
        cinema_json(cinema_scan_library_batch(32, $reset));
    } catch (Throwable $e) {
        cinema_json(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'fetch_meta') {
    @set_time_limit(90);
    try {
        cinema_json(cinema_fetch_metadata_batch(3));
    } catch (Throwable $e) {
        cinema_json(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'progress') {
    $type = (string) ($_POST['type'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $position = (float) ($_POST['position'] ?? 0);
    $duration = (float) ($_POST['duration'] ?? 0);
    cinema_save_progress($type, $id, $position, $duration);
    cinema_json(['ok' => true]);
}

if ($action === 'set_watched') {
    $type = (string) ($_POST['type'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $watched = (string) ($_POST['watched'] ?? '') === '1';
    $result = $watched ? cinema_mark_watched($type, $id) : cinema_mark_unwatched($type, $id);
    if (empty($result['ok'])) {
        cinema_json(['error' => (string) ($result['error'] ?? 'Invalid')], 400);
    }
    cinema_json($result);
}

if ($action === 'retry_failed') {
    cinema_db()->exec("UPDATE movies SET tmdb_status = NULL WHERE tmdb_id IS NULL");
    cinema_db()->exec("UPDATE shows SET tmdb_status = NULL WHERE tmdb_id IS NULL");
    cinema_json(['ok' => true]);
}

if ($action === 'clear_meta') {
    @set_time_limit(60);
    try {
        cinema_json(cinema_clear_metadata());
    } catch (Throwable $e) {
        cinema_json(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'clear_progress') {
    try {
        cinema_json(cinema_clear_progress());
    } catch (Throwable $e) {
        cinema_json(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'convert_scan') {
    @set_time_limit(60);
    try {
        cinema_json(cinema_convert_scan());
    } catch (Throwable $e) {
        cinema_json(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'convert_begin') {
    @set_time_limit(120);
    ignore_user_abort(true);
    try {
        cinema_json(cinema_convert_begin());
    } catch (Throwable $e) {
        cinema_json(['error' => $e->getMessage(), 'log' => function_exists('cinema_convert_log_tail') ? cinema_convert_log_tail(8) : ''], 500);
    }
}

if ($action === 'convert_tick') {
    @set_time_limit(60);
    ignore_user_abort(true);
    try {
        cinema_json(cinema_convert_tick());
    } catch (Throwable $e) {
        cinema_json(['error' => $e->getMessage(), 'log' => function_exists('cinema_convert_log_tail') ? cinema_convert_log_tail(8) : ''], 500);
    }
}

if ($action === 'search') {
    $q = trim((string) ($_POST['q'] ?? $_GET['q'] ?? ''));
    cinema_json([
        'q' => $q,
        'title' => cinema_search_title($q),
        'html' => cinema_search_html($q),
    ]);
}

cinema_json(['error' => 'Unknown action'], 400);
