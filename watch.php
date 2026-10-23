<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$movieId = isset($_GET['movie']) ? (int) $_GET['movie'] : 0;
$episodeId = isset($_GET['episode']) ? (int) $_GET['episode'] : 0;

$title = cinema_t('watch');
$back = 'index.php';
$backLabel = cinema_t('back');
$src = '';
$type = '';
$id = 0;
$start = 0;
$playable = true;
$ext = '';
$nextEpisode = null;
$nextLabel = '';
$nextUrl = '';

if ($movieId > 0) {
    $row = cinema_get_movie($movieId);
    if (!$row) {
        http_response_code(404);
        echo cinema_t('not_found');
        exit;
    }
    $title = (string) $row['title'];
    $back = cinema_url('movie.php', ['id' => $movieId]);
    $backLabel = cinema_t('badge_movie');
    $src = cinema_url('stream.php', ['movie' => $movieId]);
    $type = 'movie';
    $id = $movieId;
    $playable = (int) $row['playable'] === 1;
    $ext = (string) $row['ext'];
    $p = cinema_get_progress('movie', $movieId);
    $start = $p ? (float) $p['position_seconds'] : 0;
    $watchStatus = cinema_watch_status_from_progress(
        $start,
        $p ? (float) $p['duration_seconds'] : (float) ($row['duration_seconds'] ?? 0)
    );
} elseif ($episodeId > 0) {
    $row = cinema_get_episode($episodeId);
    if (!$row) {
        http_response_code(404);
        echo cinema_t('not_found');
        exit;
    }
    $epLabel = 'S' . (int) $row['season'] . 'E' . str_pad((string) (int) $row['episode'], 2, '0', STR_PAD_LEFT);
    $title = (string) $row['show_title'] . ' · ' . $epLabel;
    if (!empty($row['title'])) {
        $title .= ' · ' . $row['title'];
    }
    $back = cinema_url('show.php', ['id' => (int) $row['show_id'], 'season' => (int) $row['season']]);
    $backLabel = (string) $row['show_title'];
    $src = cinema_url('stream.php', ['episode' => $episodeId]);
    $type = 'episode';
    $id = $episodeId;
    $playable = (int) $row['playable'] === 1;
    $ext = (string) $row['ext'];
    $p = cinema_get_progress('episode', $episodeId);
    $start = $p ? (float) $p['position_seconds'] : 0;
    $watchStatus = cinema_watch_status_from_progress(
        $start,
        $p ? (float) $p['duration_seconds'] : (float) ($row['duration_seconds'] ?? 0)
    );
    $nextEpisode = cinema_following_episode($episodeId);
    if ($nextEpisode) {
        $nextLabel = 'S' . (int) $nextEpisode['season'] . 'E' . str_pad((string) (int) $nextEpisode['episode'], 2, '0', STR_PAD_LEFT);
        if (!empty($nextEpisode['title'])) {
            $nextLabel .= ' · ' . $nextEpisode['title'];
        }
        $nextUrl = cinema_url('watch.php', ['episode' => (int) $nextEpisode['id']]);
    }
} else {
    cinema_redirect('index.php');
}

$mime = cinema_mime_for_ext($ext);
$watched = ($watchStatus ?? 'unwatched') === 'watched';
$subtitleTracks = ($type !== '' && isset($row) && is_array($row))
    ? cinema_subtitles_for_media($type, $row)
    : [];
$defaultSub = cinema_pick_default_subtitle_index($subtitleTracks);

$renderTracks = static function () use ($subtitleTracks, $defaultSub, $type, $id): void {
    foreach ($subtitleTracks as $i => $track) {
        $query = $type === 'movie' ? ['movie' => $id] : ['episode' => $id];
        if (($track['source'] ?? '') === 'mux' && $track['mux_index'] !== null) {
            $query['mux'] = (int) $track['mux_index'];
        } else {
            $query['file'] = (string) ($track['file'] ?? '');
        }
        $src = cinema_url('subtitle.php', $query);
        $attrs = ' kind="subtitles" src="' . cinema_h($src) . '" label="' . cinema_h($track['label']) . '"';
        if (($track['lang'] ?? '') !== '') {
            $attrs .= ' srclang="' . cinema_h((string) $track['lang']) . '"';
        }
        if ($i === $defaultSub) {
            $attrs .= ' default';
        }
        echo '<track' . $attrs . '>';
    }
};
?>
<!DOCTYPE html>
<html lang="<?= cinema_app_lang() === 'it' ? 'it' : 'en' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= cinema_h($title) ?> — Cinema</title>
    <link rel="icon" type="image/png" href="../loto.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css?v=<?= cinema_h(CINEMA_VERSION) ?>">
</head>
<body class="watch-page">
<div class="watch-shell">
    <div class="watch-bar">
        <a href="<?= cinema_h($back) ?>"><i class="bi bi-arrow-left"></i> <?= cinema_h($backLabel) ?></a>
        <h1><?= cinema_h($title) ?></h1>
        <button type="button" class="btn btn-secondary btn-sm js-watch-toggle" data-type="<?= cinema_h($type) ?>" data-id="<?= (int) $id ?>" data-watched="<?= $watched ? '1' : '0' ?>">
            <i class="bi <?= $watched ? 'bi-eye-slash' : 'bi-check2-circle' ?>"></i>
            <span class="js-watch-toggle-label"><?= cinema_h($watched ? cinema_t('mark_unwatched') : cinema_t('mark_watched')) ?></span>
        </button>
    </div>
    <div class="player-wrap">
        <?php if (!$playable): ?>
            <div class="unplayable">
                <p><?= cinema_h(cinema_t('file_may_not_play', ['ext' => strtoupper($ext)])) ?></p>
                <p><?= cinema_h(cinema_t('prefer_mp4')) ?></p>
                <video id="cinemaPlayer" controls autoplay data-type="<?= cinema_h($type) ?>" data-id="<?= (int) $id ?>" data-start="<?= cinema_h((string) $start) ?>">
                    <source src="<?= cinema_h($src) ?>" type="<?= cinema_h($mime) ?>">
                    <?php $renderTracks(); ?>
                </video>
            </div>
        <?php else: ?>
            <video id="cinemaPlayer" controls autoplay data-type="<?= cinema_h($type) ?>" data-id="<?= (int) $id ?>" data-start="<?= cinema_h((string) $start) ?>">
                <source src="<?= cinema_h($src) ?>" type="<?= cinema_h($mime) ?>">
                <?php $renderTracks(); ?>
            </video>
        <?php endif; ?>
        <?php if ($nextUrl !== ''): ?>
            <div class="next-episode" id="nextEpisodeOverlay" hidden
                 data-next-url="<?= cinema_h($nextUrl) ?>"
                 data-seconds="10">
                <div class="next-episode-card">
                    <p class="next-episode-kicker"><?= cinema_h(cinema_t('next_episode')) ?></p>
                    <p class="next-episode-title"><?= cinema_h($nextLabel) ?></p>
                    <p class="next-episode-countdown" id="nextEpisodeCountdown"></p>
                    <div class="next-episode-actions">
                        <button type="button" class="btn btn-primary" id="nextEpisodePlay">
                            <i class="bi bi-play-fill"></i> <?= cinema_h(cinema_t('next_episode_play')) ?>
                        </button>
                        <button type="button" class="btn btn-secondary" id="nextEpisodeCancel">
                            <?= cinema_h(cinema_t('next_episode_cancel')) ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>window.cinemaI18n = <?= json_encode(cinema_js_strings(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="assets/app.js?v=<?= cinema_h(CINEMA_VERSION) ?>"></script>
</body>
</html>
