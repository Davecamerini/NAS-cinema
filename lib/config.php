<?php
declare(strict_types=1);

function cinema_default_config(): array
{
    return [
        'nas_root' => '/mnt/NASgard/video',
        'movies_dir' => 'Movies',
        'series_dir' => 'Series',
        'tmdb_key' => '',
        'tmdb_language' => 'en-US',
        'app_language' => 'en',
        'last_scan_at' => '',
    ];
}

function cinema_config(): array
{
    if (isset($GLOBALS['cinema_config_cache']) && is_array($GLOBALS['cinema_config_cache'])) {
        return $GLOBALS['cinema_config_cache'];
    }
    $defaults = cinema_default_config();
    if (!is_file(CINEMA_CONFIG_PATH)) {
        $GLOBALS['cinema_config_cache'] = $defaults;
        return $defaults;
    }
    $raw = @file_get_contents(CINEMA_CONFIG_PATH);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        $GLOBALS['cinema_config_cache'] = $defaults;
        return $defaults;
    }
    $cfg = array_merge($defaults, $data);
    $GLOBALS['cinema_config_cache'] = $cfg;
    return $cfg;
}

function cinema_config_get(string $key, $default = '')
{
    $cfg = cinema_config();
    return $cfg[$key] ?? $default;
}

function cinema_config_save(array $values): array
{
    $cfg = array_merge(cinema_config(), $values);
    cinema_ensure_data_dirs();
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents(CINEMA_CONFIG_PATH, $json) === false) {
        throw new RuntimeException('Could not write config.json');
    }
    $GLOBALS['cinema_config_cache'] = $cfg;
    return $cfg;
}

function cinema_nas_root(): string
{
    $root = (string) cinema_config_get('nas_root', '');
    return rtrim(str_replace('\\', '/', $root), '/');
}

function cinema_movies_root(): string
{
    $sub = trim((string) cinema_config_get('movies_dir', 'Movies'), '/\\');
    return cinema_nas_root() . '/' . $sub;
}

function cinema_series_root(): string
{
    $sub = trim((string) cinema_config_get('series_dir', 'Series'), '/\\');
    return cinema_nas_root() . '/' . $sub;
}
