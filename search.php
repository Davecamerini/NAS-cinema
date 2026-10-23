<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$q = trim((string) ($_GET['q'] ?? ''));

cinema_render('layout', [
    'content' => cinema_search_html($q),
    'pageTitle' => cinema_search_title($q),
    'activeNav' => 'home',
    'searchQuery' => $q,
]);
