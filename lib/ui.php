<?php
declare(strict_types=1);

function cinema_placeholder_poster(): string
{
    return 'data:image/svg+xml,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 600"><rect fill="#263238" width="400" height="600"/><text x="200" y="310" fill="#90a4ae" text-anchor="middle" font-family="Segoe UI,sans-serif" font-size="22">' . cinema_t('no_poster') . '</text></svg>'
    );
}

function cinema_format_warn_html(string $ext = '', bool $seriesHasNonMp4 = false): string
{
    if ($seriesHasNonMp4) {
        $title = cinema_t('format_warn_series');
    } elseif ($ext !== '' && !cinema_is_mp4_ext($ext)) {
        $title = cinema_t('format_warn_file', ['ext' => strtoupper($ext)]);
    } else {
        return '';
    }
    return '<span class="format-warn" title="' . cinema_h($title) . '" aria-label="' . cinema_h($title) . '">'
        . '<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>'
        . '</span>';
}

function cinema_card_html(
    string $href,
    string $title,
    string $sub,
    string $posterUrl,
    string $badge,
    ?float $rating,
    int $progress = 0,
    string $watch = 'unwatched',
    string $formatWarnHtml = ''
): string {
    $img = $posterUrl !== '' ? $posterUrl : cinema_placeholder_poster();
    $html = '<a class="poster-card" href="' . cinema_h($href) . '">';
    $html .= '<div class="poster-art">';
    $html .= '<img src="' . cinema_h($img) . '" alt="' . cinema_h($title) . '" loading="lazy">';
    if ($badge !== '') {
        $html .= '<span class="poster-badge">' . cinema_h($badge) . '</span>';
    }
    if ($watch === 'watched') {
        $html .= '<span class="poster-watch poster-watch-done" title="' . cinema_h(cinema_t('watch_status_done')) . '" aria-label="' . cinema_h(cinema_t('watch_status_done')) . '"><i class="bi bi-check-circle-fill"></i></span>';
    } elseif ($watch === 'progress') {
        $html .= '<span class="poster-watch poster-watch-progress" title="' . cinema_h(cinema_t('watch_status_progress')) . '" aria-label="' . cinema_h(cinema_t('watch_status_progress')) . '"><i class="bi bi-play-circle-fill"></i></span>';
    }
    if ($rating !== null && $rating > 0) {
        $html .= '<span class="poster-rating"><i class="bi bi-star-fill"></i> ' . cinema_h(number_format($rating, 1)) . '</span>';
    }
    if ($progress > 0) {
        $html .= '<span class="poster-progress"><span style="width:' . $progress . '%"></span></span>';
    }
    $html .= '</div>';
    $html .= '<div class="poster-meta"><strong><span class="poster-title-text">' . cinema_h($title) . '</span>'
        . $formatWarnHtml . '</strong>';
    if ($sub !== '') {
        $html .= '<span>' . cinema_h($sub) . '</span>';
    }
    $html .= '</div></a>';
    return $html;
}

function cinema_movie_card(array $m): string
{
    $year = !empty($m['year']) ? (string) $m['year'] : '';
    $rating = isset($m['rating']) && $m['rating'] !== null ? (float) $m['rating'] : null;
    return cinema_card_html(
        cinema_url('movie.php', ['id' => (int) $m['id']]),
        (string) $m['title'],
        $year,
        cinema_poster_url('movie', (int) $m['id'], $m['poster_file'] ?? null),
        '',
        $rating,
        0,
        (string) ($m['_watch'] ?? 'unwatched'),
        cinema_format_warn_html((string) ($m['ext'] ?? ''))
    );
}

function cinema_show_card(array $s): string
{
    $year = !empty($s['year']) ? (string) $s['year'] : '';
    $count = isset($s['episode_count']) ? (int) $s['episode_count'] : 0;
    $sub = $year;
    if ($count > 0) {
        $sub = trim($year . ' · ' . cinema_t('ep_short', ['n' => (string) $count]));
    }
    $rating = isset($s['rating']) && $s['rating'] !== null ? (float) $s['rating'] : null;
    $hasNonMp4 = !empty($s['_has_non_mp4']) || (int) ($s['non_mp4_count'] ?? 0) > 0;
    return cinema_card_html(
        cinema_url('show.php', ['id' => (int) $s['id']]),
        (string) $s['title'],
        $sub,
        cinema_poster_url('show', (int) $s['id'], $s['poster_file'] ?? null),
        '',
        $rating,
        0,
        (string) ($s['_watch'] ?? 'unwatched'),
        cinema_format_warn_html('', $hasNonMp4)
    );
}

function cinema_continue_card(array $item): string
{
    $p = $item['_progress'] ?? [];
    $pct = cinema_progress_percent(
        isset($p['position_seconds']) ? (float) $p['position_seconds'] : 0,
        isset($p['duration_seconds']) ? (float) $p['duration_seconds'] : 0
    );
    if (($item['_kind'] ?? '') === 'episode') {
        $title = (string) ($item['show_title'] ?? cinema_t('series'));
        $sub = 'S' . (int) $item['season'] . 'E' . str_pad((string) (int) $item['episode'], 2, '0', STR_PAD_LEFT);
        if (!empty($item['title'])) {
            $sub .= ' · ' . $item['title'];
        }
        $show = cinema_get_show((int) $item['show_id']);
        $poster = $show ? cinema_poster_url('show', (int) $show['id'], $show['poster_file'] ?? null) : '';
        if (!empty($item['still_file'])) {
            $poster = cinema_poster_url('episode', (int) $item['id'], $item['still_file']);
        }
        return cinema_card_html(
            cinema_url('watch.php', ['episode' => (int) $item['id']]),
            $title,
            $sub,
            $poster,
            '',
            null,
            $pct,
            'progress',
            cinema_format_warn_html((string) ($item['ext'] ?? ''))
        );
    }

    return cinema_card_html(
        cinema_url('watch.php', ['movie' => (int) $item['id']]),
        (string) $item['title'],
        !empty($item['year']) ? (string) $item['year'] : '',
        cinema_poster_url('movie', (int) $item['id'], $item['poster_file'] ?? null),
        '',
        isset($item['rating']) ? (float) $item['rating'] : null,
        $pct,
        'progress',
        cinema_format_warn_html((string) ($item['ext'] ?? ''))
    );
}

function cinema_normalize_sort(string $sort): string
{
    return in_array($sort, ['title', 'year', 'added', 'rating'], true) ? $sort : 'title';
}

function cinema_normalize_dir(string $dir, string $sort = 'title'): string
{
    if ($dir === 'asc' || $dir === 'desc') {
        return $dir;
    }
    return $sort === 'title' ? 'asc' : 'desc';
}

function cinema_sort_options(): array
{
    return [
        'title' => cinema_t('sort_title'),
        'year' => cinema_t('sort_year'),
        'added' => cinema_t('sort_added'),
        'rating' => cinema_t('sort_rating'),
    ];
}

/**
 * @param array<string, scalar|null> $keep Query params to preserve (except sort/dir).
 */
function cinema_sort_controls(string $page, string $currentSort, string $currentDir, array $keep = []): string
{
    $currentSort = cinema_normalize_sort($currentSort);
    $currentDir = cinema_normalize_dir($currentDir, $currentSort);

    $buildUrl = static function (string $sort, string $dir) use ($page, $keep): string {
        // Always include sort/dir so "title" / default order are not mistaken for "use cookie".
        $params = array_filter($keep + [
            'sort' => $sort,
            'dir' => $dir,
        ], static fn($v) => $v !== null && $v !== '');
        return cinema_url($page, $params);
    };

    $html = '<div class="filter-sort">';
    $html .= '<label class="filter-sort-field">';
    $html .= '<span class="filter-sort-label">' . cinema_h(cinema_t('sort_by')) . '</span>';
    $html .= '<select class="filter-sort-select" aria-label="' . cinema_h(cinema_t('sort_by')) . '"';
    $html .= ' onchange="window.location.href=this.value">';
    foreach (cinema_sort_options() as $value => $label) {
        $sel = $value === $currentSort ? ' selected' : '';
        $html .= '<option value="' . cinema_h($buildUrl($value, $currentDir)) . '"' . $sel . '>' . cinema_h($label) . '</option>';
    }
    $html .= '</select></label>';

    $html .= '<label class="filter-sort-field">';
    $html .= '<span class="filter-sort-label">' . cinema_h(cinema_t('sort_dir')) . '</span>';
    $html .= '<select class="filter-sort-select" aria-label="' . cinema_h(cinema_t('sort_dir')) . '"';
    $html .= ' onchange="window.location.href=this.value">';
    foreach (['asc' => cinema_t('sort_asc'), 'desc' => cinema_t('sort_desc')] as $value => $label) {
        $sel = $value === $currentDir ? ' selected' : '';
        $html .= '<option value="' . cinema_h($buildUrl($currentSort, $value)) . '"' . $sel . '>' . cinema_h($label) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '</div>';
    return $html;
}

function cinema_genre_chips(?string $json, string $listPage): string
{
    $html = '';
    foreach (cinema_decode_json_list($json) as $g) {
        $html .= '<a class="genre-chip" href="' . cinema_h(cinema_url($listPage, ['genre' => $g])) . '">' . cinema_h($g) . '</a>';
    }
    return $html;
}

function cinema_search_title(string $q): string
{
    $q = trim($q);
    return $q === '' ? cinema_t('search') : cinema_t('search_title', ['q' => $q]);
}

function cinema_search_html(string $q): string
{
    $q = trim($q);
    if ($q === '') {
        return '<div class="empty-hint">' . cinema_h(cinema_t('search_empty')) . '</div>';
    }

    $movies = cinema_list_movies($q, '', 'title');
    $shows = cinema_list_shows($q, '', 'title');
    $html = '<section class="row-section"><h2 class="section-title">' . cinema_h(cinema_t('movies')) . '</h2>';
    if ($movies === []) {
        $html .= '<p class="hint">' . cinema_h(cinema_t('no_movies_match')) . '</p>';
    } else {
        $html .= '<div class="poster-grid">';
        foreach ($movies as $m) {
            $html .= cinema_movie_card($m);
        }
        $html .= '</div>';
    }
    $html .= '</section><section class="row-section"><h2 class="section-title">' . cinema_h(cinema_t('series')) . '</h2>';
    if ($shows === []) {
        $html .= '<p class="hint">' . cinema_h(cinema_t('no_series_match')) . '</p>';
    } else {
        $html .= '<div class="poster-grid">';
        foreach ($shows as $s) {
            $html .= cinema_show_card($s);
        }
        $html .= '</div>';
    }
    $html .= '</section>';
    return $html;
}
