<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$type = (string) ($_GET['type'] ?? '');
$id = (int) ($_GET['id'] ?? 0);

$file = null;
if ($type === 'movie') {
    $row = cinema_get_movie($id);
    $file = $row['poster_file'] ?? null;
} elseif ($type === 'show') {
    $row = cinema_get_show($id);
    $file = $row['poster_file'] ?? null;
} elseif ($type === 'episode') {
    $row = cinema_get_episode($id);
    $file = $row['still_file'] ?? null;
}

$file = basename((string) $file);
if ($file === '' || strpos($file, '..') !== false) {
    http_response_code(404);
    exit;
}

$abs = CINEMA_POSTERS . '/' . $file;
if (!is_file($abs) || !cinema_path_inside($abs, CINEMA_POSTERS)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . (string) filesize($abs));
readfile($abs);
exit;
