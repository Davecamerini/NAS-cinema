<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$stats = cinema_stats();
$continue = cinema_continue_items(12);
$recentMovies = cinema_recent_movies(12);
$recentShows = cinema_recent_shows(12);
$nasOk = is_dir(cinema_nas_root());
$settingsLink = '<a href="settings.php">' . cinema_h(cinema_t('settings')) . '</a>';

ob_start();
if (!$nasOk) {
    echo '<div class="empty-hint">' . str_replace('{settings}', $settingsLink, cinema_h(cinema_t('empty_nas'))) . '</div>';
} elseif ($stats['movies'] === 0 && $stats['shows'] === 0) {
    echo '<div class="empty-hint">' . str_replace('{settings}', $settingsLink, cinema_h(cinema_t('empty_library'))) . '</div>';
}

if ($continue !== []) {
    echo '<section class="row-section"><h2 class="section-title">' . cinema_h(cinema_t('continue_watching')) . '</h2><div class="poster-grid">';
    foreach ($continue as $item) {
        echo cinema_continue_card($item);
    }
    echo '</div></section>';
}

if ($recentMovies !== []) {
    echo '<section class="row-section"><h2 class="section-title">' . cinema_h(cinema_t('recent_movies')) . '</h2><div class="poster-grid">';
    foreach ($recentMovies as $m) {
        echo cinema_movie_card($m);
    }
    echo '</div></section>';
}

if ($recentShows !== []) {
    echo '<section class="row-section"><h2 class="section-title">' . cinema_h(cinema_t('recent_series')) . '</h2><div class="poster-grid">';
    foreach ($recentShows as $s) {
        echo cinema_show_card($s);
    }
    echo '</div></section>';
}

cinema_render('layout', [
    'content' => ob_get_clean(),
    'pageTitle' => cinema_t('home'),
    'activeNav' => 'home',
]);
