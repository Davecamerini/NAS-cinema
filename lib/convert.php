<?php
declare(strict_types=1);

function cinema_convert_job_path(): string
{
    return CINEMA_DATA . '/convert-job.json';
}

function cinema_convert_log_path(): string
{
    return CINEMA_DATA . '/convert.log';
}

function cinema_convert_local_out(): string
{
    return CINEMA_DATA . '/convert-out.mp4';
}

function cinema_convert_progress_path(): string
{
    return CINEMA_DATA . '/convert-progress.txt';
}

function cinema_ffmpeg_bin(): string
{
    if (!function_exists('exec')) {
        return '';
    }
    $out = [];
    @exec('command -v ffmpeg 2>/dev/null', $out);
    $bin = trim((string) ($out[0] ?? ''));
    return $bin !== '' ? $bin : 'ffmpeg';
}

function cinema_ffmpeg_available(): bool
{
    if (!function_exists('exec')) {
        return false;
    }
    $out = [];
    @exec(escapeshellarg(cinema_ffmpeg_bin()) . ' -version 2>&1', $out);
    return $out !== [];
}

function cinema_convert_log(string $line): void
{
    cinema_ensure_data_dirs();
    @file_put_contents(cinema_convert_log_path(), date('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function cinema_convert_log_tail(int $lines = 12): string
{
    $path = cinema_convert_log_path();
    if (!is_file($path)) {
        return '';
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return '';
    }
    $parts = preg_split("/\r\n|\n|\r/", trim($raw)) ?: [];
    return trim(implode("\n", array_slice($parts, -$lines)));
}

function cinema_pid_running(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (is_dir('/proc/' . $pid)) {
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
        if (is_string($cmdline) && $cmdline !== '' && stripos($cmdline, 'ffmpeg') === false) {
            return false;
        }
        return true;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return false;
}

function cinema_ffmpeg_still_working(array $job): bool
{
    if (cinema_pid_running((int) ($job['pid'] ?? 0))) {
        return true;
    }
    $out = cinema_convert_local_out();
    if (!function_exists('exec') || !is_file($out)) {
        return false;
    }
    $hits = [];
    @exec('pgrep -f ' . escapeshellarg('ffmpeg.*' . basename($out)) . ' 2>/dev/null', $hits);
    return $hits !== [];
}

function cinema_convert_job_read(): array
{
    $path = cinema_convert_job_path();
    if (!is_file($path)) {
        return cinema_convert_job_blank();
    }
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return cinema_convert_job_blank();
    }
    return array_merge(cinema_convert_job_blank(), $data);
}

function cinema_convert_job_blank(): array
{
    return [
        'running' => false,
        'pid' => 0,
        'kind' => '',
        'id' => 0,
        'title' => '',
        'src' => '',
        'dest_final' => '',
        'duration' => 0,
        'percent' => 0,
        'done' => 0,
        'ok' => 0,
        'total' => 0,
        'error' => '',
        'mode' => 'copy',
        'failed' => [],
        'started_at' => 0,
        'last_size' => 0,
        'stable_ticks' => 0,
    ];
}

function cinema_convert_job_write(array $job): void
{
    cinema_ensure_data_dirs();
    @file_put_contents(
        cinema_convert_job_path(),
        json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function cinema_ffprobe_codec(string $abs, string $stream): string
{
    $cmd = 'ffprobe -v error -select_streams ' . $stream
        . ' -show_entries stream=codec_name -of default=nokey=1:noprint_wrappers=1 '
        . escapeshellarg($abs) . ' 2>/dev/null';
    $out = [];
    @exec($cmd, $out);
    return strtolower(trim((string) ($out[0] ?? '')));
}

function cinema_convertible_items(array $failedKeys = []): array
{
    $items = [];
    $pdo = cinema_db();

    $movies = $pdo->query(
        "SELECT id, path, title, year FROM movies WHERE lower(IFNULL(ext, '')) IN ('mkv', 'avi') ORDER BY id"
    )->fetchAll();
    foreach ($movies as $row) {
        $key = 'movie:' . (int) $row['id'];
        if (in_array($key, $failedKeys, true)) {
            continue;
        }
        $abs = cinema_resolve_media_abs('movie', $row);
        if ($abs === null) {
            continue;
        }
        $mp4 = cinema_mp4_sibling($abs);
        if (is_file($mp4) && filesize($mp4) > 1024) {
            cinema_point_row_at_mp4('movie', (int) $row['id'], $row, $mp4);
            continue;
        }
        $items[] = [
            'kind' => 'movie',
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'src' => $abs,
        ];
    }

    $episodes = $pdo->query(
        "SELECT e.id, e.path, e.season, e.episode, e.title, s.title AS show_title
         FROM episodes e JOIN shows s ON s.id = e.show_id
         WHERE lower(IFNULL(e.ext, '')) IN ('mkv', 'avi')
         ORDER BY e.id"
    )->fetchAll();
    foreach ($episodes as $row) {
        $key = 'episode:' . (int) $row['id'];
        if (in_array($key, $failedKeys, true)) {
            continue;
        }
        $abs = cinema_resolve_media_abs('episode', $row);
        if ($abs === null) {
            continue;
        }
        $mp4 = cinema_mp4_sibling($abs);
        if (is_file($mp4) && filesize($mp4) > 1024) {
            cinema_point_row_at_mp4('episode', (int) $row['id'], $row, $mp4);
            continue;
        }
        $ep = 'S' . (int) $row['season'] . 'E' . str_pad((string) (int) $row['episode'], 2, '0', STR_PAD_LEFT);
        $title = (string) $row['show_title'] . ' ' . $ep;
        $items[] = [
            'kind' => 'episode',
            'id' => (int) $row['id'],
            'title' => $title,
            'src' => $abs,
        ];
    }

    return $items;
}

function cinema_point_row_at_mp4(string $kind, int $id, array $row, string $mp4Abs): void
{
    $root = $kind === 'movie' ? cinema_movies_root() : cinema_series_root();
    if (!cinema_path_inside($mp4Abs, $root)) {
        return;
    }
    $rel = cinema_rel_path($mp4Abs, $root);
    $size = (int) @filesize($mp4Abs);
    $mtime = (int) @filemtime($mp4Abs);
    $duration = cinema_probe_duration($mp4Abs);
    $table = $kind === 'movie' ? 'movies' : 'episodes';
    $pdo = cinema_db();
    $pdo->prepare('DELETE FROM ' . $table . ' WHERE path = ? AND id != ?')->execute([$rel, $id]);
    if ($kind === 'movie') {
        $pdo->prepare(
            "UPDATE movies SET path = ?, ext = 'mp4', playable = 1, file_size = ?, file_mtime = ?,
             duration_seconds = COALESCE(?, duration_seconds), updated_at = datetime('now') WHERE id = ?"
        )->execute([$rel, $size, $mtime, $duration, $id]);
        return;
    }
    $pdo->prepare(
        "UPDATE episodes SET path = ?, ext = 'mp4', playable = 1, file_size = ?, file_mtime = ?,
         duration_seconds = COALESCE(?, duration_seconds) WHERE id = ?"
    )->execute([$rel, $size, $mtime, $duration, $id]);
}

function cinema_convert_scan(): array
{
    $items = cinema_convertible_items();
    return ['count' => count($items), 'error' => ''];
}

function cinema_convert_read_progress(int $durationSeconds, int $startedAt): array
{
    $elapsed = max(0, time() - max(0, $startedAt));
    $out = [
        'out_seconds' => 0,
        'percent' => 0,
        'speed' => 0.0,
        'eta_seconds' => null,
        'elapsed_seconds' => $elapsed,
        'ended' => false,
    ];
    $path = cinema_convert_progress_path();
    if (!is_file($path)) {
        return $out;
    }
    $raw = (string) @file_get_contents($path);
    if ($raw === '') {
        return $out;
    }
    $out['ended'] = strpos($raw, 'progress=end') !== false;

    $seconds = 0.0;
    if (preg_match_all('/^out_time=(\d+):(\d+):(\d+(?:\.\d+)?)/m', $raw, $m)) {
        $i = count($m[0]) - 1;
        $seconds = ((int) $m[1][$i]) * 3600 + ((int) $m[2][$i]) * 60 + (float) $m[3][$i];
    } elseif (preg_match_all('/^out_time_us=(\d+)/m', $raw, $m)) {
        $seconds = ((int) end($m[1])) / 1000000;
    } elseif (preg_match_all('/^out_time_ms=(\d+)/m', $raw, $m)) {
        $n = (int) end($m[1]);
        $seconds = ($durationSeconds > 0 && $n > $durationSeconds * 2000)
            ? $n / 1000000
            : $n / 1000;
    }

    $speed = 0.0;
    if (preg_match_all('/^speed=\s*([0-9.]+)x/m', $raw, $sm)) {
        $speed = (float) end($sm[1]);
    }

    $out['out_seconds'] = (int) floor($seconds);
    if ($durationSeconds > 0) {
        $pct = (int) round(($seconds / $durationSeconds) * 100);
        $out['percent'] = $out['ended'] ? 100 : max(0, min(99, $pct));
    } elseif ($out['ended']) {
        $out['percent'] = 100;
    }
    $out['speed'] = $speed;
    if ($speed > 0 && $durationSeconds > 0 && $seconds < $durationSeconds) {
        $out['eta_seconds'] = (int) max(0, round(($durationSeconds - $seconds) / $speed));
    } elseif ($out['ended']) {
        $out['eta_seconds'] = 0;
    }
    return $out;
}

function cinema_convert_progress_ended(): bool
{
    $path = cinema_convert_progress_path();
    if (!is_file($path)) {
        return false;
    }
    $raw = (string) @file_get_contents($path);
    return strpos($raw, 'progress=end') !== false;
}

function cinema_spawn_detached(string $command): int
{
    cinema_ensure_data_dirs();
    $pidFile = CINEMA_DATA . '/convert.pid';
    @unlink($pidFile);
    $inner = $command . ' & echo $! > ' . escapeshellarg($pidFile);
    $out = [];
    $code = 0;
    @exec('sh -c ' . escapeshellarg($inner), $out, $code);
    for ($i = 0; $i < 30; $i++) {
        if (is_file($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            if ($pid > 0) {
                return $pid;
            }
        }
        usleep(50000);
    }
    $fallback = (int) trim((string) ($out[0] ?? '0'));
    cinema_convert_log('spawn pidfile missing code=' . $code . ' out=' . implode(' ', $out));
    return $fallback;
}

/**
 * Relative subtitle stream indexes (0:s:N) that can be stored in MP4 as mov_text.
 *
 * @return list<int>
 */
function cinema_convert_text_subtitle_indexes(string $src): array
{
    if (!function_exists('cinema_ffprobe_subtitle_streams')) {
        return [];
    }
    $indexes = [];
    foreach (cinema_ffprobe_subtitle_streams($src) as $stream) {
        if (!empty($stream['text'])) {
            $indexes[] = (int) $stream['mux_index'];
        }
    }
    return $indexes;
}

/**
 * @param list<int> $subtitleIndexes
 */
function cinema_convert_ffmpeg_cmd(
    string $src,
    string $dest,
    bool $encodeVideo,
    bool $hasAudio,
    bool $copyAudio,
    array $subtitleIndexes = []
): string {
    $args = [
        cinema_ffmpeg_bin(), '-nostdin', '-y', '-hide_banner', '-loglevel', 'error',
        '-i', $src,
        '-map', '0:v:0',
    ];
    if ($hasAudio) {
        $args[] = '-map';
        $args[] = '0:a:0';
    }
    foreach ($subtitleIndexes as $sIndex) {
        $args[] = '-map';
        $args[] = '0:s:' . (int) $sIndex;
    }
    if ($encodeVideo) {
        array_push($args, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p');
    } else {
        array_push($args, '-c:v', 'copy');
    }
    if ($hasAudio) {
        if ($copyAudio) {
            array_push($args, '-c:a', 'copy');
        } else {
            array_push($args, '-c:a', 'aac', '-b:a', '192k');
        }
    }
    if ($subtitleIndexes !== []) {
        array_push($args, '-c:s', 'mov_text');
    }
    array_push($args, '-movflags', '+faststart', '-progress', cinema_convert_progress_path(), $dest);
    return implode(' ', array_map('escapeshellarg', $args));
}

function cinema_copy_mp4_to_nas(string $local, string $destFinal): bool
{
    $dir = dirname($destFinal);
    if (!is_dir($dir)) {
        return false;
    }
    $tmp = $destFinal . '.tmp';
    @unlink($tmp);
    if (!@copy($local, $tmp)) {
        cinema_convert_log('copy failed to ' . $tmp);
        @unlink($tmp);
        return false;
    }
    @unlink($destFinal);
    if (!@rename($tmp, $destFinal)) {
        if (!@copy($tmp, $destFinal)) {
            cinema_convert_log('rename/copy failed to ' . $destFinal);
            @unlink($tmp);
            return false;
        }
        @unlink($tmp);
    }
    return is_file($destFinal) && filesize($destFinal) > 1024;
}

function cinema_convert_start_item(array $item, array $job, bool $forceEncode): array
{
    $src = (string) $item['src'];
    $destFinal = cinema_mp4_sibling($src);
    $local = cinema_convert_local_out();
    $progressFile = cinema_convert_progress_path();
    @unlink($local);
    @unlink($progressFile);

    $vCodec = cinema_ffprobe_codec($src, 'v:0');
    $aCodec = cinema_ffprobe_codec($src, 'a:0');
    $hasAudio = $aCodec !== '';
    $copyVideo = !$forceEncode && in_array($vCodec, ['h264', 'avc'], true);
    $copyAudio = $hasAudio && $aCodec === 'aac';
    $encodeVideo = !$copyVideo;
    $mode = $encodeVideo ? 'encode' : 'copy';
    $subIndexes = cinema_convert_text_subtitle_indexes($src);

    $ffmpeg = cinema_convert_ffmpeg_cmd($src, $local, $encodeVideo, $hasAudio, $copyAudio, $subIndexes);
    $log = cinema_convert_log_path();
    $run = $ffmpeg . ' </dev/null >>' . escapeshellarg($log) . ' 2>&1';
    cinema_convert_log(
        'start ' . $mode . ' v=' . $vCodec . ' a=' . $aCodec
        . ' subs=' . count($subIndexes)
        . ' ' . $src . ' -> ' . $destFinal
    );
    cinema_convert_log($run);

    $pid = cinema_spawn_detached($run);
    if ($pid <= 0) {
        $job['failed'][] = $item['kind'] . ':' . $item['id'];
        $job['done'] = (int) $job['done'] + 1;
        $job['error'] = cinema_t('convert_failed') . ' (could not start ffmpeg)';
        $job['running'] = false;
        cinema_convert_log('could not start ffmpeg');
        cinema_convert_job_write($job);
        return $job;
    }
    cinema_convert_log('pid ' . $pid);

    $job['running'] = true;
    $job['pid'] = $pid;
    $job['kind'] = (string) $item['kind'];
    $job['id'] = (int) $item['id'];
    $job['title'] = (string) $item['title'];
    $job['src'] = $src;
    $job['dest_final'] = $destFinal;
    $job['duration'] = cinema_probe_duration($src) ?? 0;
    $job['percent'] = 0;
    $job['error'] = '';
    $job['mode'] = $mode;
    $job['started_at'] = time();
    $job['last_size'] = 0;
    $job['stable_ticks'] = 0;
    cinema_convert_job_write($job);
    return $job;
}

function cinema_convert_mark_failed(array $job, string $error): array
{
    $kind = (string) ($job['kind'] ?? '');
    $id = (int) ($job['id'] ?? 0);
    if ($kind !== '' && $id > 0) {
        $job['failed'][] = $kind . ':' . $id;
    }
    $job['done'] = (int) $job['done'] + 1;
    $job['error'] = $error;
    $job['running'] = false;
    $job['pid'] = 0;
    $job['kind'] = '';
    $job['id'] = 0;
    $job['src'] = '';
    $job['dest_final'] = '';
    $job['percent'] = 0;
    @unlink(cinema_convert_local_out());
    cinema_convert_log('failed: ' . $error);
    cinema_convert_job_write($job);
    return $job;
}

function cinema_convert_finalize(array $job): array
{
    $local = cinema_convert_local_out();
    $destFinal = (string) ($job['dest_final'] ?? '');
    $kind = (string) ($job['kind'] ?? '');
    $id = (int) ($job['id'] ?? 0);
    $ok = is_file($local) && filesize($local) > 1024;

    if (!$ok) {
        if (($job['mode'] ?? '') === 'copy' && $kind !== '' && $id > 0 && is_file((string) $job['src'])) {
            cinema_convert_log('copy/remux failed, retrying with encode');
            $item = [
                'kind' => $kind,
                'id' => $id,
                'title' => (string) $job['title'],
                'src' => (string) $job['src'],
            ];
            $job['running'] = false;
            $job['pid'] = 0;
            cinema_convert_job_write($job);
            return cinema_convert_start_item($item, $job, true);
        }
        $tail = cinema_convert_log_tail(8);
        $msg = cinema_t('convert_file_failed', ['title' => (string) $job['title']]);
        if ($tail !== '') {
            $msg .= ' ' . preg_replace('/\s+/', ' ', $tail);
        }
        return cinema_convert_mark_failed($job, $msg);
    }

    if ($destFinal === '' || !cinema_copy_mp4_to_nas($local, $destFinal)) {
        return cinema_convert_mark_failed($job, cinema_t('convert_not_writable', ['path' => dirname($destFinal)]));
    }
    @unlink($local);

    if (($kind === 'movie' || $kind === 'episode') && $id > 0) {
        $row = $kind === 'movie' ? cinema_get_movie($id) : cinema_get_episode($id);
        if ($row) {
            cinema_point_row_at_mp4($kind, $id, $row, $destFinal);
        }
    }

    $src = str_replace('\\', '/', (string) ($job['src'] ?? ''));
    $destNorm = str_replace('\\', '/', $destFinal);
    if ($src !== '' && strcasecmp($src, $destNorm) !== 0 && is_file($src) && is_file($destFinal) && filesize($destFinal) > 1024) {
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $root = $kind === 'movie' ? cinema_movies_root() : cinema_series_root();
        if (cinema_is_convertible_ext($ext) && cinema_path_inside($src, $root)) {
            if (@unlink($src)) {
                cinema_convert_log('deleted original ' . $src);
            } else {
                cinema_convert_log('could not delete original ' . $src);
            }
        }
    }

    $job['done'] = (int) $job['done'] + 1;
    $job['ok'] = (int) ($job['ok'] ?? 0) + 1;
    $job['percent'] = 100;
    $job['error'] = '';
    $job['running'] = false;
    $job['pid'] = 0;
    $job['kind'] = '';
    $job['id'] = 0;
    $job['src'] = '';
    $job['dest_final'] = '';
    cinema_convert_log('ok ' . $destFinal);
    cinema_convert_job_write($job);
    return $job;
}

function cinema_convert_public_status(array $job): array
{
    $done = (int) $job['done'];
    $total = (int) $job['total'];
    $failed = is_array($job['failed'] ?? null) ? count($job['failed']) : 0;
    $remaining = max(0, $total - $done);
    $running = !empty($job['running']);
    if ($running) {
        $remaining = max(1, $remaining);
    }
    $duration = (int) ($job['duration'] ?? 0);
    $prog = $running
        ? cinema_convert_read_progress($duration, (int) ($job['started_at'] ?? 0))
        : [
            'out_seconds' => $duration,
            'percent' => (int) ($job['percent'] ?? 0),
            'speed' => 0.0,
            'eta_seconds' => null,
            'elapsed_seconds' => 0,
        ];
    $percent = (int) $prog['percent'];
    $eta = $prog['eta_seconds'];
    return [
        'running' => $running,
        'title' => (string) $job['title'],
        'percent' => $percent,
        'out' => cinema_format_clock((int) $prog['out_seconds']),
        'duration' => $duration > 0 ? cinema_format_clock($duration) : '—',
        'elapsed' => cinema_format_clock((int) $prog['elapsed_seconds']),
        'eta' => $eta === null ? '—' : cinema_format_clock((int) $eta),
        'speed' => ((float) $prog['speed']) > 0 ? number_format((float) $prog['speed'], 1, '.', '') . '×' : '—',
        'done' => $done,
        'ok' => (int) ($job['ok'] ?? 0),
        'failed' => $failed,
        'current' => $running ? min($total, $done + 1) : $done,
        'total' => $total,
        'remaining' => $remaining,
        'error' => (string) $job['error'],
        'log' => cinema_convert_log_tail(8),
        'ffmpeg' => cinema_ffmpeg_bin(),
        'ffmpeg_ok' => cinema_ffmpeg_available(),
    ];
}

function cinema_convert_tick(): array
{
    if (!cinema_ffmpeg_available()) {
        return [
            'running' => false,
            'title' => '',
            'percent' => 0,
            'done' => 0,
            'ok' => 0,
            'failed' => 0,
            'total' => 0,
            'remaining' => 0,
            'error' => cinema_t('ffmpeg_missing'),
        ];
    }

    $lock = fopen(CINEMA_DATA . '/convert.lock', 'c');
    if ($lock === false) {
        return cinema_convert_public_status(cinema_convert_job_read());
    }
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        return cinema_convert_public_status(cinema_convert_job_read());
    }

    try {
        $job = cinema_convert_job_read();

        if (!empty($job['running']) && (int) $job['pid'] > 0) {
            $local = cinema_convert_local_out();
            $size = is_file($local) ? (int) filesize($local) : 0;
            $working = cinema_ffmpeg_still_working($job);
            $ended = cinema_convert_progress_ended();
            $age = time() - (int) ($job['started_at'] ?? 0);

            if (!$ended && $age < 8 && $size === 0) {
                return cinema_convert_public_status($job);
            }

            if ($working && !$ended) {
                $prog = cinema_convert_read_progress((int) $job['duration'], (int) ($job['started_at'] ?? 0));
                $job['percent'] = $prog['percent'];
                $job['last_size'] = $size;
                $job['stable_ticks'] = 0;
                cinema_convert_job_write($job);
                return cinema_convert_public_status($job);
            }

            if (!$working && !$ended && $size > 1024) {
                $last = (int) ($job['last_size'] ?? 0);
                $job['stable_ticks'] = $size === $last ? ((int) ($job['stable_ticks'] ?? 0) + 1) : 0;
                $job['last_size'] = $size;
                if ($job['stable_ticks'] < 2) {
                    cinema_convert_job_write($job);
                    return cinema_convert_public_status($job);
                }
            }

            if ($working && !$ended && (time() - (int) ($job['started_at'] ?? 0)) < 3) {
                return cinema_convert_public_status($job);
            }

            $job = cinema_convert_finalize($job);
            if (!empty($job['running'])) {
                return cinema_convert_public_status($job);
            }
        }

        $failed = is_array($job['failed'] ?? null) ? $job['failed'] : [];
        $items = cinema_convertible_items($failed);
        if ((int) $job['total'] <= 0) {
            $job['total'] = count($items);
            $job['done'] = 0;
            $job['ok'] = 0;
        }
        if ($items === []) {
            $job['running'] = false;
            $job['pid'] = 0;
            $job['percent'] = (int) $job['total'] > 0 ? 100 : 0;
            $job['title'] = '';
            cinema_convert_job_write($job);
            $out = cinema_convert_public_status($job);
            $out['remaining'] = 0;
            return $out;
        }

        $job = cinema_convert_start_item($items[0], $job, false);
        return cinema_convert_public_status($job);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function cinema_convert_begin(): array
{
    cinema_convert_log('begin ffmpeg=' . cinema_ffmpeg_bin() . ' exec=' . (function_exists('exec') ? 'yes' : 'no') . ' data=' . CINEMA_DATA);

    $existing = cinema_convert_job_read();
    if (!empty($existing['running']) && cinema_ffmpeg_still_working($existing)) {
        return cinema_convert_tick();
    }

    $job = cinema_convert_job_blank();
    $items = cinema_convertible_items();
    $job['total'] = count($items);
    $job['done'] = 0;
    $job['ok'] = 0;
    cinema_convert_job_write($job);
    cinema_convert_log('begin batch total=' . count($items));
    return cinema_convert_tick();
}
