<?php
declare(strict_types=1);

function cinema_subtitle_lang_label(string $code): string
{
    $code = strtolower(trim($code));
    $labels = [
        'en' => 'English',
        'eng' => 'English',
        'it' => 'Italiano',
        'ita' => 'Italiano',
        'es' => 'Español',
        'spa' => 'Español',
        'fr' => 'Français',
        'fra' => 'Français',
        'fre' => 'Français',
        'de' => 'Deutsch',
        'deu' => 'Deutsch',
        'ger' => 'Deutsch',
        'pt' => 'Português',
        'ja' => '日本語',
        'jpn' => '日本語',
        'zh' => '中文',
        'ko' => '한국어',
        'ru' => 'Русский',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'sv' => 'Svenska',
        'no' => 'Norsk',
        'da' => 'Dansk',
        'fi' => 'Suomi',
        'tr' => 'Türkçe',
        'ar' => 'العربية',
        'hi' => 'हिन्दी',
        'und' => 'Unknown',
    ];
    if ($code === '') {
        return cinema_t('subtitles');
    }
    return $labels[$code] ?? strtoupper($code);
}

function cinema_normalize_subtitle_lang(string $code): string
{
    $code = strtolower(trim($code));
    $map = [
        'eng' => 'en',
        'ita' => 'it',
        'fra' => 'fr',
        'fre' => 'fr',
        'spa' => 'es',
        'deu' => 'de',
        'ger' => 'de',
        'jpn' => 'ja',
        'por' => 'pt',
        'chi' => 'zh',
        'zho' => 'zh',
        'kor' => 'ko',
        'rus' => 'ru',
        'und' => '',
    ];
    return $map[$code] ?? $code;
}

function cinema_ffprobe_bin(): string
{
    if (!function_exists('exec')) {
        return '';
    }
    $out = [];
    @exec('command -v ffprobe 2>/dev/null', $out);
    $bin = trim((string) ($out[0] ?? ''));
    return $bin !== '' ? $bin : 'ffprobe';
}

function cinema_ffprobe_available(): bool
{
    if (!function_exists('exec')) {
        return false;
    }
    $out = [];
    @exec(escapeshellarg(cinema_ffprobe_bin()) . ' -version 2>&1', $out);
    return $out !== [];
}

function cinema_subtitle_codec_is_text(string $codec): bool
{
    $codec = strtolower(trim($codec));
    return in_array($codec, [
        'subrip', 'srt', 'ass', 'ssa', 'webvtt', 'mov_text', 'text',
        'subtitle', 'ttml', 'eia_608', 'captions',
    ], true);
}

/**
 * @return list<array{mux_index:int,codec:string,lang:string,title:string,forced:bool}>
 */
function cinema_ffprobe_subtitle_streams(string $videoAbs): array
{
    if (!cinema_ffprobe_available() || !is_file($videoAbs)) {
        return [];
    }
    $cmd = escapeshellarg(cinema_ffprobe_bin())
        . ' -v error -select_streams s'
        . ' -show_entries stream=index,codec_name:stream_tags=language,title,handler_name:stream_disposition=forced,default'
        . ' -of json '
        . escapeshellarg($videoAbs)
        . ' 2>/dev/null';
    $out = [];
    @exec($cmd, $out);
    $raw = implode("\n", $out);
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['streams']) || !is_array($data['streams'])) {
        return [];
    }

    $list = [];
    $muxIndex = 0;
    foreach ($data['streams'] as $stream) {
        if (!is_array($stream)) {
            $muxIndex++;
            continue;
        }
        $codec = strtolower((string) ($stream['codec_name'] ?? ''));
        $tags = is_array($stream['tags'] ?? null) ? $stream['tags'] : [];
        $disp = is_array($stream['disposition'] ?? null) ? $stream['disposition'] : [];
        $lang = cinema_normalize_subtitle_lang((string) ($tags['language'] ?? ''));
        $title = trim((string) ($tags['title'] ?? $tags['handler_name'] ?? ''));
        $forced = !empty($disp['forced']);
        $list[] = [
            'mux_index' => $muxIndex,
            'codec' => $codec,
            'lang' => $lang,
            'title' => $title,
            'forced' => $forced,
            'text' => cinema_subtitle_codec_is_text($codec),
        ];
        $muxIndex++;
    }
    return $list;
}

/**
 * @return list<array{mux_index:int,codec:string,lang:string,title:string,forced:bool,text:bool}>
 */
function cinema_muxed_subtitle_streams_cached(string $kind, int $id, string $videoAbs): array
{
    cinema_ensure_data_dirs();
    $mtime = (int) @filemtime($videoAbs);
    $metaFile = CINEMA_SUBS . '/' . $kind . '-' . $id . '.json';
    if (is_file($metaFile)) {
        $raw = @file_get_contents($metaFile);
        $meta = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($meta)
            && (int) ($meta['mtime'] ?? -1) === $mtime
            && isset($meta['streams'])
            && is_array($meta['streams'])
        ) {
            return array_values(array_filter($meta['streams'], static function ($s) {
                return is_array($s);
            }));
        }
    }

    $streams = cinema_ffprobe_subtitle_streams($videoAbs);
    @file_put_contents(
        $metaFile,
        json_encode(['mtime' => $mtime, 'streams' => $streams], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    return $streams;
}

function cinema_muxed_subtitle_cache_path(string $kind, int $id, int $muxIndex): string
{
    return CINEMA_SUBS . '/' . $kind . '-' . $id . '-s' . $muxIndex . '.vtt';
}

function cinema_extract_muxed_subtitle_vtt(string $videoAbs, int $muxIndex, string $cachePath): bool
{
    if (!cinema_ffmpeg_available() || !is_file($videoAbs)) {
        return false;
    }
    cinema_ensure_data_dirs();
    $dir = dirname($cachePath);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }

    $ffmpeg = cinema_ffmpeg_bin();
    $tmpVtt = $cachePath . '.partial.vtt';
    @unlink($tmpVtt);

    $cmd = escapeshellarg($ffmpeg)
        . ' -nostdin -y -hide_banner -loglevel error'
        . ' -i ' . escapeshellarg($videoAbs)
        . ' -map 0:s:' . (int) $muxIndex
        . ' -c:s webvtt '
        . escapeshellarg($tmpVtt)
        . ' 2>&1';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    if ($code === 0 && is_file($tmpVtt) && filesize($tmpVtt) > 0) {
        return @rename($tmpVtt, $cachePath) || (@copy($tmpVtt, $cachePath) && @unlink($tmpVtt));
    }
    @unlink($tmpVtt);

    $tmpSrt = $cachePath . '.partial.srt';
    @unlink($tmpSrt);
    $cmd = escapeshellarg($ffmpeg)
        . ' -nostdin -y -hide_banner -loglevel error'
        . ' -i ' . escapeshellarg($videoAbs)
        . ' -map 0:s:' . (int) $muxIndex
        . ' -c:s srt '
        . escapeshellarg($tmpSrt)
        . ' 2>&1';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    if ($code !== 0 || !is_file($tmpSrt) || filesize($tmpSrt) <= 0) {
        @unlink($tmpSrt);
        return false;
    }
    $srt = @file_get_contents($tmpSrt);
    @unlink($tmpSrt);
    if ($srt === false) {
        return false;
    }
    $vtt = cinema_srt_to_vtt($srt);
    return @file_put_contents($cachePath, $vtt, LOCK_EX) !== false;
}

function cinema_ensure_muxed_subtitle_vtt(string $kind, int $id, string $videoAbs, int $muxIndex): ?string
{
    $cachePath = cinema_muxed_subtitle_cache_path($kind, $id, $muxIndex);
    $videoMtime = (int) @filemtime($videoAbs);
    if (is_file($cachePath) && (int) @filemtime($cachePath) >= $videoMtime && filesize($cachePath) > 0) {
        return $cachePath;
    }
    if (!cinema_extract_muxed_subtitle_vtt($videoAbs, $muxIndex, $cachePath)) {
        return null;
    }
    return is_file($cachePath) ? $cachePath : null;
}

/**
 * @return list<array{source:string,file:?string,mux_index:?int,lang:string,label:string,format:string}>
 */
function cinema_find_sidecar_subtitles(string $videoAbs): array
{
    $videoAbs = str_replace('\\', '/', $videoAbs);
    if (!is_file($videoAbs)) {
        return [];
    }
    $dir = dirname($videoAbs);
    $stem = cinema_strip_media_extension(basename($videoAbs));
    if ($stem === '' || !is_dir($dir)) {
        return [];
    }

    $pattern = '/^' . preg_quote($stem, '/') . '\.(?:([a-z]{2,3})(?:[-_][a-z]{2,8})?\.)?(srt|vtt)$/i';
    $entries = @scandir($dir) ?: [];
    $out = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || cinema_is_ignored_name($entry)) {
            continue;
        }
        if (!preg_match($pattern, $entry, $m)) {
            continue;
        }
        $abs = $dir . '/' . $entry;
        if (!is_file($abs)) {
            continue;
        }
        $lang = cinema_normalize_subtitle_lang((string) ($m[1] ?? ''));
        $format = strtolower((string) $m[2]);
        $out[] = [
            'source' => 'sidecar',
            'file' => $entry,
            'mux_index' => null,
            'path' => $abs,
            'lang' => $lang,
            'label' => cinema_subtitle_lang_label($lang !== '' ? $lang : (string) ($m[1] ?? '')),
            'format' => $format,
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return strcasecmp($a['label'] . (string) $a['file'], $b['label'] . (string) $b['file']);
    });

    return $out;
}

/**
 * @return list<array{source:string,file:?string,mux_index:?int,lang:string,label:string,format:string}>
 */
function cinema_find_muxed_subtitles(string $kind, int $id, string $videoAbs): array
{
    $streams = cinema_muxed_subtitle_streams_cached($kind, $id, $videoAbs);
    $out = [];
    foreach ($streams as $stream) {
        if (empty($stream['text'])) {
            continue;
        }
        $lang = (string) ($stream['lang'] ?? '');
        $title = trim((string) ($stream['title'] ?? ''));
        $forced = !empty($stream['forced']);
        if ($title !== '') {
            $label = $title;
        } else {
            $label = cinema_subtitle_lang_label($lang);
        }
        if ($forced && stripos($label, 'forced') === false) {
            $label .= ' (forced)';
        }
        $out[] = [
            'source' => 'mux',
            'file' => null,
            'mux_index' => (int) $stream['mux_index'],
            'path' => null,
            'lang' => $lang,
            'label' => $label,
            'format' => 'mux',
        ];
    }
    return $out;
}

/**
 * @return list<array{source:string,file:?string,mux_index:?int,lang:string,label:string,format:string}>
 */
function cinema_subtitles_for_media(string $kind, array $row): array
{
    $abs = cinema_resolve_media_abs($kind, $row);
    if ($abs === null) {
        return [];
    }
    $id = (int) ($row['id'] ?? 0);
    $tracks = cinema_find_sidecar_subtitles($abs);
    if ($id > 0) {
        $tracks = array_merge($tracks, cinema_find_muxed_subtitles($kind, $id, $abs));
    }
    return $tracks;
}

function cinema_srt_to_vtt(string $srt): string
{
    $srt = preg_replace('/^\xEF\xBB\xBF/', '', $srt) ?? $srt;
    $srt = str_replace(["\r\n", "\r"], "\n", $srt);
    $srt = trim($srt);
    if ($srt === '') {
        return "WEBVTT\n\n";
    }

    $blocks = preg_split("/\n{2,}/", $srt) ?: [];
    $cues = [];
    foreach ($blocks as $block) {
        $lines = explode("\n", trim($block));
        if ($lines === [] || $lines[0] === '') {
            continue;
        }
        if (preg_match('/^\d+$/', $lines[0])) {
            array_shift($lines);
        }
        if ($lines === []) {
            continue;
        }
        $timing = array_shift($lines);
        if ($timing === null || !preg_match('/(\d{1,2}:\d{2}:\d{2}[,.]\d{1,3})\s*-->\s*(\d{1,2}:\d{2}:\d{2}[,.]\d{1,3})/', $timing, $tm)) {
            continue;
        }
        $start = str_replace(',', '.', $tm[1]);
        $end = str_replace(',', '.', $tm[2]);
        $text = implode("\n", $lines);
        if (trim($text) === '') {
            continue;
        }
        $cues[] = $start . ' --> ' . $end . "\n" . $text;
    }

    return "WEBVTT\n\n" . implode("\n\n", $cues) . ($cues !== [] ? "\n" : '');
}

function cinema_subtitle_file_to_vtt(string $absPath, string $format): ?string
{
    $raw = @file_get_contents($absPath);
    if ($raw === false) {
        return null;
    }
    if ($format === 'vtt' || $format === 'mux') {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (!preg_match('/^\s*WEBVTT/i', $raw)) {
            return "WEBVTT\n\n" . ltrim($raw);
        }
        return $raw;
    }
    return cinema_srt_to_vtt($raw);
}

/**
 * @param list<array{lang:string}> $tracks
 */
function cinema_pick_default_subtitle_index(array $tracks): int
{
    if ($tracks === []) {
        return -1;
    }
    $prefer = cinema_normalize_subtitle_lang(cinema_app_lang());
    foreach ($tracks as $i => $track) {
        if (($track['lang'] ?? '') === $prefer) {
            return $i;
        }
    }
    if ($prefer !== 'en') {
        foreach ($tracks as $i => $track) {
            if (($track['lang'] ?? '') === 'en') {
                return $i;
            }
        }
    }
    return 0;
}

function cinema_stream_subtitle_by_request(): void
{
    @set_time_limit(120);
    $movieId = isset($_GET['movie']) ? (int) $_GET['movie'] : 0;
    $episodeId = isset($_GET['episode']) ? (int) $_GET['episode'] : 0;
    $file = basename((string) ($_GET['file'] ?? ''));
    $mux = isset($_GET['mux']) ? (int) $_GET['mux'] : -1;

    $kind = '';
    $row = null;
    if ($movieId > 0) {
        $kind = 'movie';
        $row = cinema_get_movie($movieId);
    } elseif ($episodeId > 0) {
        $kind = 'episode';
        $row = cinema_get_episode($episodeId);
    }

    if ($kind === '' || !$row) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }

    $videoAbs = cinema_resolve_media_abs($kind, $row);
    if ($videoAbs === null) {
        http_response_code(404);
        echo 'File missing';
        exit;
    }

    $vtt = null;

    if ($mux >= 0) {
        $streams = cinema_muxed_subtitle_streams_cached($kind, (int) $row['id'], $videoAbs);
        $allowed = false;
        foreach ($streams as $stream) {
            if ((int) ($stream['mux_index'] ?? -1) === $mux && !empty($stream['text'])) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            http_response_code(404);
            echo 'Subtitle track not found';
            exit;
        }
        $cache = cinema_ensure_muxed_subtitle_vtt($kind, (int) $row['id'], $videoAbs, $mux);
        if ($cache === null) {
            http_response_code(500);
            echo 'Could not extract subtitle (text tracks only; PGS/VobSub need a sidecar)';
            exit;
        }
        $vtt = cinema_subtitle_file_to_vtt($cache, 'vtt');
    } else {
        if ($file === '' || strpos($file, '..') !== false) {
            http_response_code(400);
            echo 'Missing subtitle';
            exit;
        }
        $tracks = cinema_find_sidecar_subtitles($videoAbs);
        $match = null;
        foreach ($tracks as $track) {
            if (strcasecmp((string) $track['file'], $file) === 0) {
                $match = $track;
                break;
            }
        }
        if ($match === null || empty($match['path'])) {
            http_response_code(404);
            echo 'Subtitle not found';
            exit;
        }
        $root = $kind === 'movie' ? cinema_movies_root() : cinema_series_root();
        if (!is_file($match['path']) || !cinema_path_inside($match['path'], $root)) {
            http_response_code(404);
            echo 'Subtitle missing';
            exit;
        }
        $vtt = cinema_subtitle_file_to_vtt($match['path'], (string) $match['format']);
    }

    if ($vtt === null || $vtt === '') {
        http_response_code(500);
        echo 'Could not read subtitle';
        exit;
    }

    header('Content-Type: text/vtt; charset=utf-8');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $vtt;
    exit;
}
