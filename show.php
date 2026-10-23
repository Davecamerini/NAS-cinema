<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$show = $id > 0 ? cinema_get_show($id) : null;
if (!$show) {
    http_response_code(404);
    cinema_render('layout', [
        'content' => '<div class="empty-hint">' . cinema_h(cinema_t('series_not_found')) . '</div>',
        'pageTitle' => cinema_t('not_found'),
        'activeNav' => 'series',
        'backUrl' => 'series.php',
        'backLabel' => cinema_t('series'),
    ]);
    exit;
}

$episodes = cinema_show_episodes((int) $show['id']);
$seasons = [];
foreach ($episodes as $ep) {
    $seasons[(int) $ep['season']][] = $ep;
}
ksort($seasons);
if (isset($_GET['season'])) {
    $season = (int) $_GET['season'];
    if (!isset($seasons[$season])) {
        $season = cinema_default_show_season($seasons);
    }
} else {
    $season = cinema_default_show_season($seasons);
}
$seasonEps = $seasons[$season] ?? [];

$poster = cinema_poster_url('show', (int) $show['id'], $show['poster_file'] ?? null);
if ($poster === '') {
    $poster = cinema_placeholder_poster();
}
$next = cinema_next_episode((int) $show['id']);
$similar = cinema_similar_shows($show);
$cast = cinema_decode_json_list($show['cast_json'] ?? null);

ob_start();
?>
<div class="hero">
    <img class="hero-poster" src="<?= cinema_h($poster) ?>" alt="<?= cinema_h((string) $show['title']) ?>">
    <div>
        <h2 class="hero-title"><?= cinema_h((string) $show['title']) ?><?php if (!empty($show['year'])): ?> <span style="font-weight:500;color:#6c778d;font-size:1.2rem">(<?= (int) $show['year'] ?>)</span><?php endif; ?></h2>
        <div class="meta-bar">
            <?php if (!empty($show['rating'])): ?>
                <span class="rating"><i class="bi bi-star-fill"></i> <?= cinema_h(number_format((float) $show['rating'], 1)) ?></span>
            <?php endif; ?>
            <span><?= cinema_h(cinema_t('episodes_n', ['n' => (string) count($episodes)])) ?></span>
            <span><?= cinema_h(cinema_t('seasons_n', ['n' => (string) count($seasons)])) ?></span>
        </div>
        <div><?= cinema_genre_chips($show['genres'] ?? null, 'series.php') ?></div>
        <?php if (!empty($show['overview'])): ?>
            <p class="synopsis"><?= nl2br(cinema_h((string) $show['overview'])) ?></p>
        <?php endif; ?>
        <?php if ($cast !== []): ?>
            <p class="cast"><strong><?= cinema_h(cinema_t('cast')) ?></strong> — <?= cinema_h(implode(', ', $cast)) ?></p>
        <?php endif; ?>
        <div class="btn-row">
            <?php if ($next): ?>
                <a class="btn btn-primary" href="<?= cinema_h(cinema_url('watch.php', ['episode' => (int) $next['id']])) ?>">
                    <i class="bi bi-play-fill"></i> <?= cinema_h(cinema_t('play_episode', ['label' => 'S' . (int) $next['season'] . 'E' . str_pad((string) (int) $next['episode'], 2, '0', STR_PAD_LEFT)])) ?>
                </a>
            <?php endif; ?>
            <?php if ($episodes !== []):
                $showWatched = cinema_show_is_watched((int) $show['id']);
                ?>
                <button type="button" class="btn btn-secondary js-watch-toggle" data-type="show" data-id="<?= (int) $show['id'] ?>" data-watched="<?= $showWatched ? '1' : '0' ?>">
                    <i class="bi <?= $showWatched ? 'bi-eye-slash' : 'bi-check2-circle' ?>"></i>
                    <span class="js-watch-toggle-label"><?= cinema_h($showWatched ? cinema_t('mark_unwatched') : cinema_t('mark_watched')) ?></span>
                </button>
            <?php endif; ?>
            <?php if (!empty($show['trailer_key'])): ?>
                <a class="btn btn-secondary" href="https://www.youtube.com/watch?v=<?= cinema_h((string) $show['trailer_key']) ?>" target="_blank" rel="noopener"><i class="bi bi-youtube"></i> <?= cinema_h(cinema_t('trailer')) ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($seasons !== []): ?>
    <div class="season-tabs">
        <?php foreach ($seasons as $num => $_): ?>
            <a class="<?= $num === $season ? 'active' : '' ?>" href="<?= cinema_h(cinema_url('show.php', ['id' => (int) $show['id'], 'season' => $num])) ?>"><?= cinema_h(cinema_t('season_n', ['n' => (string) (int) $num])) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="episode-list">
        <?php foreach ($seasonEps as $ep):
            $epStatus = cinema_watch_status_from_progress(
                isset($ep['position_seconds']) ? (float) $ep['position_seconds'] : 0,
                isset($ep['progress_duration']) ? (float) $ep['progress_duration'] : (float) ($ep['duration_seconds'] ?? 0)
            );
            $epWatched = $epStatus === 'watched';
            $pct = $epWatched ? 100 : cinema_progress_percent(
                isset($ep['position_seconds']) ? (float) $ep['position_seconds'] : 0,
                isset($ep['progress_duration']) ? (float) $ep['progress_duration'] : (float) ($ep['duration_seconds'] ?? 0)
            );
            $still = !empty($ep['still_file'])
                ? cinema_poster_url('episode', (int) $ep['id'], $ep['still_file'])
                : $poster;
            $label = 'S' . (int) $ep['season'] . 'E' . str_pad((string) (int) $ep['episode'], 2, '0', STR_PAD_LEFT);
            $name = trim((string) ($ep['title'] ?? '')) !== '' ? (string) $ep['title'] : $label;
            ?>
            <div class="episode-row">
                <a class="episode-row-main" href="<?= cinema_h(cinema_url('watch.php', ['episode' => (int) $ep['id']])) ?>">
                    <img class="episode-still" src="<?= cinema_h($still) ?>" alt="">
                    <div>
                        <strong><?= cinema_h($label) ?> · <?= cinema_h($name) ?><?= cinema_format_warn_html((string) ($ep['ext'] ?? '')) ?></strong>
                        <span><?php if (!empty($ep['overview'])) echo cinema_h(cinema_excerpt((string) $ep['overview'])); ?></span>
                        <span class="poster-progress js-ep-progress"<?= $pct > 0 ? '' : ' hidden' ?>><span style="width:<?= $pct ?>%;border-radius:4px;"></span></span>
                    </div>
                </a>
                <div class="episode-side">
                    <span><?= cinema_h(cinema_format_duration_seconds($ep['duration_seconds'] !== null ? (int) $ep['duration_seconds'] : null)) ?></span>
                    <button type="button" class="btn btn-secondary btn-sm js-watch-toggle" data-type="episode" data-id="<?= (int) $ep['id'] ?>" data-watched="<?= $epWatched ? '1' : '0' ?>">
                        <i class="bi <?= $epWatched ? 'bi-eye-slash' : 'bi-check2-circle' ?>"></i>
                        <span class="js-watch-toggle-label"><?= cinema_h($epWatched ? cinema_t('mark_unwatched') : cinema_t('mark_watched')) ?></span>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="empty-hint"><?= cinema_h(cinema_t('no_episodes')) ?></div>
<?php endif; ?>

<?php if ($similar !== []): ?>
    <section class="row-section" style="margin-top:2rem;">
        <h2 class="section-title"><?= cinema_h(cinema_t('more_like_this')) ?></h2>
        <div class="poster-grid">
            <?php foreach ($similar as $s) echo cinema_show_card($s); ?>
        </div>
    </section>
<?php endif; ?>
<?php
cinema_render('layout', [
    'content' => ob_get_clean(),
    'pageTitle' => (string) $show['title'],
    'activeNav' => 'series',
    'backUrl' => 'series.php',
    'backLabel' => cinema_t('series'),
]);
