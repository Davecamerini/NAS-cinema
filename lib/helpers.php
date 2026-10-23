<?php
declare(strict_types=1);

function cinema_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cinema_url(string $path, array $query = []): string
{
    if ($query === []) {
        return $path;
    }
    return $path . '?' . http_build_query($query);
}

function cinema_redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function cinema_render(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require CINEMA_VIEWS . '/' . $view . '.php';
}

function cinema_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cinema_ensure_data_dirs(): void
{
    foreach ([CINEMA_DATA, CINEMA_POSTERS, CINEMA_SUBS] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('Cannot create directory: ' . $dir);
        }
    }
}

function cinema_cookie_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return ($dir === '' || $dir === '.') ? '/' : $dir . '/';
}

/**
 * Saved list filters (sort / dir / hide_watched) for movies or series.
 *
 * @return array{sort:string,dir:string,hide_watched:bool}
 */
function cinema_get_list_prefs(string $list): array
{
    $list = $list === 'series' ? 'series' : 'movies';
    $defaults = ['sort' => 'title', 'dir' => 'asc', 'hide_watched' => false];
    $raw = (string) ($_COOKIE['cinema_' . $list . '_prefs'] ?? '');
    if ($raw === '') {
        return $defaults;
    }
    $data = [];
    parse_str($raw, $data);
    if (!is_array($data)) {
        return $defaults;
    }
    $sort = cinema_normalize_sort((string) ($data['sort'] ?? 'title'));
    $dir = cinema_normalize_dir((string) ($data['dir'] ?? ''), $sort);
    return [
        'sort' => $sort,
        'dir' => $dir,
        'hide_watched' => ((string) ($data['hide_watched'] ?? '') === '1'),
    ];
}

function cinema_save_list_prefs(string $list, string $sort, string $dir, bool $hideWatched): void
{
    $list = $list === 'series' ? 'series' : 'movies';
    $sort = cinema_normalize_sort($sort);
    $dir = cinema_normalize_dir($dir, $sort);
    $value = http_build_query([
        'sort' => $sort,
        'dir' => $dir,
        'hide_watched' => $hideWatched ? '1' : '0',
    ]);
    $name = 'cinema_' . $list . '_prefs';
    $opts = [
        'expires' => time() + 365 * 24 * 60 * 60,
        'path' => cinema_cookie_path(),
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie($name, $value, $opts);
    $_COOKIE[$name] = $value;
}

/**
 * Resolve sort/dir/hide_watched from GET, falling back to saved prefs, then persist.
 *
 * @return array{sort:string,dir:string,hide_watched:bool}
 */
function cinema_resolve_list_prefs(string $list): array
{
    $prefs = cinema_get_list_prefs($list);
    // Explicit query wins. Omitting sort used to mean "title", which broke restoring
    // a saved non-title sort when the user chose Title again — always send sort in links.
    if (array_key_exists('sort', $_GET)) {
        $sort = cinema_normalize_sort((string) $_GET['sort']);
        $dir = cinema_normalize_dir((string) ($_GET['dir'] ?? ''), $sort);
    } else {
        $sort = cinema_normalize_sort((string) $prefs['sort']);
        $dir = array_key_exists('dir', $_GET)
            ? cinema_normalize_dir((string) $_GET['dir'], $sort)
            : cinema_normalize_dir((string) $prefs['dir'], $sort);
    }
    if (array_key_exists('hide_watched', $_GET)) {
        $hideWatched = (string) $_GET['hide_watched'] === '1';
    } else {
        $hideWatched = (bool) $prefs['hide_watched'];
    }
    cinema_save_list_prefs($list, $sort, $dir, $hideWatched);
    return [
        'sort' => $sort,
        'dir' => $dir,
        'hide_watched' => $hideWatched,
    ];
}

function cinema_video_extensions(): array
{
    return ['mp4', 'm4v', 'webm', 'ogg', 'ogv', 'mov', 'mkv', 'avi'];
}

function cinema_is_playable_ext(string $ext): bool
{
    return in_array(strtolower($ext), ['mp4', 'm4v', 'webm', 'ogg', 'ogv', 'mov'], true);
}

function cinema_is_mp4_ext(string $ext): bool
{
    return strtolower(trim($ext)) === 'mp4';
}

function cinema_is_convertible_ext(string $ext): bool
{
    return in_array(strtolower($ext), ['mkv', 'avi'], true);
}

function cinema_path_stem(string $path): string
{
    $path = str_replace('\\', '/', $path);
    return strtolower(dirname($path) . '/' . pathinfo($path, PATHINFO_FILENAME));
}

function cinema_mp4_sibling(string $abs): string
{
    $abs = str_replace('\\', '/', $abs);
    return dirname($abs) . '/' . pathinfo($abs, PATHINFO_FILENAME) . '.mp4';
}

function cinema_filter_originals_with_playable_sibling(array $files): array
{
    $playableStems = [];
    foreach ($files as $abs) {
        $ext = strtolower(pathinfo((string) $abs, PATHINFO_EXTENSION));
        if (cinema_is_playable_ext($ext)) {
            $playableStems[cinema_path_stem((string) $abs)] = true;
        }
    }
    $out = [];
    foreach ($files as $abs) {
        $abs = (string) $abs;
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if (cinema_is_convertible_ext($ext) && isset($playableStems[cinema_path_stem($abs)])) {
            continue;
        }
        $out[] = $abs;
    }
    return $out;
}

function cinema_mime_for_ext(string $ext): string
{
    $map = [
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'video/ogg',
        'ogv' => 'video/ogg',
        'mov' => 'video/quicktime',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo',
    ];
    $ext = strtolower($ext);
    return $map[$ext] ?? 'application/octet-stream';
}

function cinema_is_ignored_name(string $name): bool
{
    $lower = strtolower($name);
    if ($lower === '' || $lower[0] === '.') {
        return true;
    }
    $skip = [
        '@eadir', 'thumbs.db', 'poster.jpg', 'poster.png', 'folder.jpg',
        'thumbnails', 'previews', 'duration', '.plex', 'lost+found',
        'extras', 'featurettes', 'trailers', 'samples', 'sample',
    ];
    return in_array($lower, $skip, true);
}

function cinema_is_video_filename(string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return $ext !== '' && in_array($ext, cinema_video_extensions(), true);
}

function cinema_format_runtime(?int $minutes): string
{
    if ($minutes === null || $minutes <= 0) {
        return '';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h > 0) {
        return $h . 'h ' . $m . 'm';
    }
    return $m . 'm';
}

function cinema_format_clock(int $seconds): string
{
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%d:%02d', $m, $s);
}

function cinema_format_duration_seconds(?int $seconds): string
{
    if ($seconds === null || $seconds <= 0) {
        return '';
    }
    return cinema_format_clock($seconds);
}

function cinema_format_bytes(int $bytes): string
{
    if ($bytes < 0) {
        $bytes = 0;
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $n = (float) $bytes;
    $i = 0;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    if ($i === 0) {
        return $bytes . ' B';
    }
    return sprintf($n >= 10 ? '%.0f %s' : '%.1f %s', $n, $units[$i]);
}

/**
 * Disk usage for a path (NAS root). Returns null if the path is missing or unreadable.
 *
 * @return array{path:string,total:int,free:int,used:int,used_pct:float,free_pct:float}|null
 */
function cinema_disk_usage(string $path): ?array
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    if ($path === '' || !is_dir($path)) {
        return null;
    }
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    if ($total === false || $free === false || $total <= 0) {
        return null;
    }
    $total = (int) $total;
    $free = (int) max(0, (int) $free);
    $used = (int) max(0, $total - $free);
    $usedPct = round(($used / $total) * 100, 1);
    $freePct = round(($free / $total) * 100, 1);
    return [
        'path' => $path,
        'total' => $total,
        'free' => $free,
        'used' => $used,
        'used_pct' => $usedPct,
        'free_pct' => $freePct,
    ];
}

function cinema_decode_json_list(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function cinema_poster_url(string $kind, int $id, ?string $file): string
{
    if ($file === null || $file === '') {
        return '';
    }
    return cinema_url('poster.php', ['type' => $kind, 'id' => $id]);
}

function cinema_excerpt(string $text, int $max = 160): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $max, '…', 'UTF-8');
    }
    if (strlen($text) <= $max) {
        return $text;
    }
    return substr($text, 0, $max - 3) . '...';
}

function cinema_progress_percent(?float $position, ?float $duration): int
{
    if ($position === null || $duration === null || $duration <= 0) {
        return 0;
    }
    return (int) max(0, min(100, round(($position / $duration) * 100)));
}

function cinema_watch_status_from_progress(?float $position, ?float $duration): string
{
    $pos = (float) ($position ?? 0);
    $dur = (float) ($duration ?? 0);
    if ($dur > 0 && ($pos / $dur) >= 0.92) {
        return 'watched';
    }
    if ($pos > 20) {
        return 'progress';
    }
    return 'unwatched';
}
