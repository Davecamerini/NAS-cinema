<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$movie = $id > 0 ? cinema_get_movie($id) : null;
if (!$movie) {
    http_response_code(404);
    cinema_render('layout', [
        'content' => '<div class="empty-hint">' . cinema_h(cinema_t('movie_not_found')) . '</div>',
        'pageTitle' => cinema_t('not_found'),
        'activeNav' => 'movies',
        'backUrl' => 'movies.php',
        'backLabel' => cinema_t('movies'),
    ]);
    exit;
}

$poster = cinema_poster_url('movie', (int) $movie['id'], $movie['poster_file'] ?? null);
if ($poster === '') {
    $poster = cinema_placeholder_poster();
}
$genres = cinema_genre_chips($movie['genres'] ?? null, 'movies.php');
$cast = cinema_decode_json_list($movie['cast_json'] ?? null);
$similar = cinema_similar_movies($movie);
$progress = cinema_get_progress('movie', (int) $movie['id']);
$watchStatus = cinema_watch_status_from_progress(
    $progress ? (float) $progress['position_seconds'] : 0,
    $progress ? (float) $progress['duration_seconds'] : (float) ($movie['duration_seconds'] ?? 0)
);
$watched = $watchStatus === 'watched';
$playLabel = $watchStatus === 'progress' ? cinema_t('resume') : cinema_t('play');
$runtime = cinema_format_runtime($movie['runtime'] !== null ? (int) $movie['runtime'] : null);
$playable = (int) $movie['playable'] === 1;

ob_start();
?>
<div class="hero">
    <img class="hero-poster" src="<?= cinema_h($poster) ?>" alt="<?= cinema_h((string) $movie['title']) ?>">
    <div>
        <h2 class="hero-title"><?= cinema_h((string) $movie['title']) ?><?php if (!empty($movie['year'])): ?> <span style="font-weight:500;color:#6c778d;font-size:1.2rem">(<?= (int) $movie['year'] ?>)</span><?php endif; ?></h2>
        <?php if (!empty($movie['tagline'])): ?>
            <p class="hero-tagline"><?= cinema_h((string) $movie['tagline']) ?></p>
        <?php endif; ?>
        <div class="meta-bar">
            <?php if (!empty($movie['rating'])): ?>
                <span class="rating"><i class="bi bi-star-fill"></i> <?= cinema_h(number_format((float) $movie['rating'], 1)) ?></span>
            <?php endif; ?>
            <?php if ($runtime !== ''): ?><span><?= cinema_h($runtime) ?></span><?php endif; ?>
            <?php if (!empty($movie['ext'])): ?><span><?= cinema_h(strtoupper((string) $movie['ext'])) ?></span><?php endif; ?>
            <?php if (!$playable): ?><span><?= cinema_h(cinema_t('may_not_play')) ?></span><?php endif; ?>
        </div>
        <div><?= $genres ?></div>
        <?php if (!empty($movie['overview'])): ?>
            <p class="synopsis"><?= nl2br(cinema_h((string) $movie['overview'])) ?></p>
        <?php endif; ?>
        <?php if ($cast !== []): ?>
            <p class="cast"><strong><?= cinema_h(cinema_t('cast')) ?></strong> — <?= cinema_h(implode(', ', $cast)) ?></p>
        <?php endif; ?>
        <div class="btn-row">
            <a class="btn btn-primary" id="playBtn" href="<?= cinema_h(cinema_url('watch.php', ['movie' => (int) $movie['id']])) ?>"><i class="bi bi-play-fill"></i> <span class="js-play-label"><?= cinema_h($playLabel) ?></span></a>
            <button type="button" class="btn btn-secondary js-watch-toggle" data-type="movie" data-id="<?= (int) $movie['id'] ?>" data-watched="<?= $watched ? '1' : '0' ?>">
                <i class="bi <?= $watched ? 'bi-eye-slash' : 'bi-check2-circle' ?>"></i>
                <span class="js-watch-toggle-label"><?= cinema_h($watched ? cinema_t('mark_unwatched') : cinema_t('mark_watched')) ?></span>
            </button>
            <?php if (!empty($movie['trailer_key'])): ?>
                <a class="btn btn-secondary" href="https://www.youtube.com/watch?v=<?= cinema_h((string) $movie['trailer_key']) ?>" target="_blank" rel="noopener"><i class="bi bi-youtube"></i> <?= cinema_h(cinema_t('trailer')) ?></a>
            <?php endif; ?>
        </div>
        <p class="hint" style="margin-top:12px;"><?= cinema_h((string) $movie['path']) ?> · <?= cinema_h(cinema_format_bytes((int) $movie['file_size'])) ?></p>
    </div>
</div>
<?php if ($similar !== []): ?>
    <section class="row-section">
        <h2 class="section-title"><?= cinema_h(cinema_t('more_like_this')) ?></h2>
        <div class="poster-grid">
            <?php foreach ($similar as $m) echo cinema_movie_card($m); ?>
        </div>
    </section>
<?php endif; ?>
<?php
cinema_render('layout', [
    'content' => ob_get_clean(),
    'pageTitle' => (string) $movie['title'],
    'activeNav' => 'movies',
    'backUrl' => 'movies.php',
    'backLabel' => cinema_t('movies'),
]);
