<?php
declare(strict_types=1);

function cinema_stream_file(string $absPath, string $downloadName, string $mime): void
{
    $size = (int) filesize($absPath);
    $mtime = (int) filemtime($absPath);
    $etag = '"' . md5($absPath . $mtime . $size) . '"';

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600, must-revalidate');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('ETag: ' . $etag);
    header('Content-Disposition: inline; filename="' . str_replace(['"', '\\'], '', $downloadName) . '"');
    header('X-Content-Type-Options: nosniff');

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }

    $start = 0;
    $end = $size - 1;
    $range = false;

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') {
            $start = (int) $m[1];
        }
        if ($m[2] !== '') {
            $end = (int) $m[2];
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $end = min($end, $size - 1);
        $range = true;
    }

    $length = $end - $start + 1;
    if ($range) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . $length);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }

    @set_time_limit(0);
    ignore_user_abort(true);

    $fp = fopen($absPath, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit;
    }
    if ($start > 0) {
        fseek($fp, $start);
    }

    $remaining = $length;
    $chunk = 256 * 1024;
    while ($remaining > 0 && !feof($fp) && connection_status() === CONNECTION_NORMAL) {
        $read = min($chunk, $remaining);
        $buf = fread($fp, $read);
        if ($buf === false || $buf === '') {
            break;
        }
        echo $buf;
        $remaining -= strlen($buf);
        flush();
    }
    fclose($fp);
    exit;
}

function cinema_stream_by_request(): void
{
    $movieId = isset($_GET['movie']) ? (int) $_GET['movie'] : 0;
    $episodeId = isset($_GET['episode']) ? (int) $_GET['episode'] : 0;

    if ($movieId > 0) {
        $row = cinema_get_movie($movieId);
        if (!$row) {
            http_response_code(404);
            echo 'Not found';
            exit;
        }
        $abs = cinema_resolve_media_abs('movie', $row);
        if ($abs === null) {
            http_response_code(404);
            echo 'File missing';
            exit;
        }
        cinema_stream_file($abs, basename((string) $row['path']), cinema_mime_for_ext((string) $row['ext']));
    }

    if ($episodeId > 0) {
        $row = cinema_get_episode($episodeId);
        if (!$row) {
            http_response_code(404);
            echo 'Not found';
            exit;
        }
        $abs = cinema_resolve_media_abs('episode', $row);
        if ($abs === null) {
            http_response_code(404);
            echo 'File missing';
            exit;
        }
        cinema_stream_file($abs, basename((string) $row['path']), cinema_mime_for_ext((string) $row['ext']));
    }

    http_response_code(400);
    echo 'Missing movie or episode';
    exit;
}
