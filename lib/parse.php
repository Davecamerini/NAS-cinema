<?php
declare(strict_types=1);

function cinema_strip_media_extension(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    if (preg_match('/\.(mp4|m4v|mkv|avi|mov|wmv|webm|ogg|ogv)$/i', $name)) {
        return (string) preg_replace('/\.[^.]+$/', '', $name);
    }
    return $name;
}

function cinema_strip_release_tags(string $name): string
{
    $name = str_replace(['.', '_'], ' ', $name);
    $junk = '2160p|1080p|720p|480p|4k|uhd|hdr10|hdr|dv|atmos|bluray|blu ray|web dl|webdl|webrip|hdrip|brrip|dvdrip|x264|x265|h264|h265|hevc|avc|aac|dts|truehd|repack|proper|extended|unrated|remux|internal|multi|subbed|dubbed|nf|amzn|dsnp|complete|nfo';
    $name = preg_replace('/\b(' . $junk . ')\b/i', ' ', $name) ?? $name;
    $name = preg_replace('/[\[\]\(\)\{\}]/', ' ', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    return trim($name, " -\t.");
}

function cinema_parse_title_year(string $name): array
{
    $base = cinema_strip_media_extension($name);

    $year = null;
    if (preg_match('/\((\d{4})\)/', $base, $m)) {
        $year = (int) $m[1];
        $base = trim((string) preg_replace('/\((\d{4})\)/', ' ', $base));
    } elseif (preg_match('/\[((?:19|20)\d{2})\]/', $base, $m)) {
        $year = (int) $m[1];
        $base = trim((string) preg_replace('/\[((?:19|20)\d{2})\]/', ' ', $base, 1));
    }

    $base = cinema_strip_release_tags($base);

    if ($year === null && preg_match('/\b((?:19|20)\d{2})\b/', $base, $m)) {
        $year = (int) $m[1];
        $base = trim((string) preg_replace('/\b(?:19|20)\d{2}\b/', ' ', $base, 1));
        $base = cinema_strip_release_tags($base);
    }

    if ($base === '') {
        $base = cinema_strip_media_extension($name);
    }

    return ['title' => $base, 'year' => $year];
}

function cinema_parse_episode(string $filename): ?array
{
    $name = cinema_strip_media_extension($filename);
    if (preg_match('/S(\d{1,2})E(\d{1,3})/i', $name, $m)) {
        return ['season' => (int) $m[1], 'episode' => (int) $m[2]];
    }
    if (preg_match('/\b(\d{1,2})x(\d{1,3})\b/i', $name, $m)) {
        return ['season' => (int) $m[1], 'episode' => (int) $m[2]];
    }
    if (preg_match('/(?:season|stagione)\s*(\d{1,2}).*?(?:episode|puntata|e)\s*(\d{1,3})/i', $name, $m)) {
        return ['season' => (int) $m[1], 'episode' => (int) $m[2]];
    }
    return null;
}

function cinema_parse_season_folder(string $name): ?int
{
    $name = trim($name);
    if (preg_match('/^(?:season|stagione)\s*(\d{1,2})$/i', $name, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/^S(\d{1,2})$/i', $name, $m)) {
        return (int) $m[1];
    }
    return null;
}

function cinema_episode_title_from_filename(string $filename): string
{
    $name = cinema_strip_media_extension($filename);
    $name = preg_replace('/S\d{1,2}E\d{1,3}/i', ' ', $name) ?? $name;
    $name = preg_replace('/\b\d{1,2}x\d{1,3}\b/i', ' ', $name) ?? $name;
    $parsed = cinema_parse_title_year($name);
    return $parsed['title'];
}
