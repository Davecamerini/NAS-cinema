<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$q = trim((string) ($_GET['q'] ?? ''));
$genre = trim((string) ($_GET['genre'] ?? ''));
$prefs = cinema_resolve_list_prefs('movies');
$sort = $prefs['sort'];
$dir = $prefs['dir'];
$hideWatched = $prefs['hide_watched'];
$movies = cinema_list_movies($q, $genre, $sort, $dir);
if ($hideWatched) {
    $movies = array_values(array_filter(
        $movies,
        static fn(array $m): bool => ($m['_watch'] ?? '') !== 'watched'
    ));
}
$genres = cinema_all_genres();

$listParams = static function (array $extra = []) use ($q, $sort, $dir, $hideWatched): array {
    return array_filter([
        'q' => $q !== '' ? $q : null,
        'sort' => $sort,
        'dir' => $dir,
        'hide_watched' => $hideWatched ? '1' : null,
    ] + $extra, static fn($v) => $v !== null && $v !== '');
};

ob_start();
echo '<div class="filters">';
echo '<a class="' . ($genre === '' ? 'active' : '') . '" href="' . cinema_h(cinema_url('movies.php', $listParams())) . '">' . cinema_h(cinema_t('all')) . '</a>';
foreach ($genres as $g) {
    $active = $genre === $g ? ' active' : '';
    echo '<a class="' . $active . '" href="' . cinema_h(cinema_url('movies.php', $listParams(['genre' => $g]))) . '">' . cinema_h($g) . '</a>';
}
echo cinema_sort_controls('movies.php', $sort, $dir, [
    'q' => $q !== '' ? $q : null,
    'genre' => $genre !== '' ? $genre : null,
    'hide_watched' => $hideWatched ? '1' : null,
]);
$hideParams = array_filter([
    'q' => $q !== '' ? $q : null,
    'genre' => $genre !== '' ? $genre : null,
    'sort' => $sort,
    'dir' => $dir,
    'hide_watched' => $hideWatched ? '0' : '1',
], static fn($v) => $v !== null && $v !== '');
echo '<a class="filter-watched' . ($hideWatched ? ' active' : '') . '" href="' . cinema_h(cinema_url('movies.php', $hideParams)) . '">' . cinema_h(cinema_t('hide_watched')) . '</a>';
echo '</div>';

if ($movies === []) {
    echo '<div class="empty-hint">' . cinema_h($hideWatched ? cinema_t('no_unwatched_movies') : cinema_t('no_movies')) . '</div>';
} else {
    echo '<div class="poster-grid">';
    foreach ($movies as $m) {
        echo cinema_movie_card($m);
    }
    echo '</div>';
}

cinema_render('layout', [
    'content' => ob_get_clean(),
    'pageTitle' => cinema_t('movies'),
    'activeNav' => 'movies',
    'searchQuery' => $q,
]);
