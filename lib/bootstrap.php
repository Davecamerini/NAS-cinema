<?php
declare(strict_types=1);

define('CINEMA_DEBUG', isset($_GET['debug']) && $_GET['debug'] === '1');

if (CINEMA_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

try {
    require_once __DIR__ . '/paths.php';
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/i18n.php';
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/parse.php';
    require_once __DIR__ . '/library.php';
    require_once __DIR__ . '/convert.php';
    require_once __DIR__ . '/tmdb.php';
    require_once __DIR__ . '/stream.php';
    require_once __DIR__ . '/subtitles.php';
    require_once __DIR__ . '/ui.php';

    cinema_ensure_data_dirs();
    cinema_db_init();
} catch (Throwable $e) {
    if (CINEMA_DEBUG) {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Cinema error: ' . $e->getMessage() . "\n\n";
        echo $e->getFile() . ':' . $e->getLine() . "\n\n";
        echo $e->getTraceAsString();
        exit;
    }
    header('HTTP/1.1 500 Internal Server Error');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body>';
    echo '<p>Cinema could not start. Add <code>?debug=1</code> for details.</p></body></html>';
    exit;
}
