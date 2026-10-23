<?php
declare(strict_types=1);

/** @var string $pageTitle */
/** @var string $activeNav */
/** @var string $content */
/** @var string $message */
/** @var string $error */
/** @var string $searchQuery */
/** @var bool $flush */
/** @var string $backUrl */
/** @var string $backLabel */

$pageTitle = $pageTitle ?? 'Cinema';
$activeNav = $activeNav ?? 'home';
$content = $content ?? '';
$message = $message ?? '';
$error = $error ?? '';
$searchQuery = $searchQuery ?? (string) ($_GET['q'] ?? '');
$flush = $flush ?? true;
$backUrl = $backUrl ?? '';
$backLabel = $backLabel ?? cinema_t('back');
$htmlLang = cinema_app_lang() === 'it' ? 'it' : 'en';
?>
<!DOCTYPE html>
<html lang="<?= cinema_h($htmlLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= cinema_h($pageTitle) ?> — Cinema</title>
    <link rel="icon" type="image/png" href="../loto.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css?v=<?= cinema_h(CINEMA_VERSION) ?>">
</head>
<body>
<div class="app-shell">
    <div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-top">
            <a href="index.php" class="sidebar-brand">
                <img src="../loto.png" alt="">
                <span class="sidebar-brand-text">Cinema<span class="sidebar-brand-sub">Valhalla</span></span>
            </a>
            <hr class="sidebar-divider">
        </div>
        <div class="sidebar-middle">
            <ul class="sidebar-nav">
                <li><a href="index.php" class="<?= $activeNav === 'home' ? 'active' : '' ?>"><i class="bi bi-house"></i> <?= cinema_h(cinema_t('home')) ?></a></li>
                <li><a href="movies.php" class="<?= $activeNav === 'movies' ? 'active' : '' ?>"><i class="bi bi-film"></i> <?= cinema_h(cinema_t('movies')) ?></a></li>
                <li><a href="series.php" class="<?= $activeNav === 'series' ? 'active' : '' ?>"><i class="bi bi-tv"></i> <?= cinema_h(cinema_t('series')) ?></a></li>
                <li><a href="settings.php" class="<?= $activeNav === 'settings' ? 'active' : '' ?>"><i class="bi bi-gear"></i> <?= cinema_h(cinema_t('settings')) ?></a></li>
            </ul>
            <button type="button" class="dark-mode-toggle" id="darkModeToggle" title="<?= cinema_h(cinema_t('dark_mode')) ?>" aria-label="<?= cinema_h(cinema_t('dark_mode')) ?>">
                <svg id="moonIcon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <svg id="sunIcon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:none;" aria-hidden="true">
                    <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" stroke="currentColor" stroke-width="2"/>
                </svg>
            </button>
        </div>
        <div class="sidebar-bottom">
            <a href="../index.php" class="sidebar-back"><i class="bi bi-arrow-left-circle"></i> <?= cinema_h(cinema_t('back_valhalla')) ?></a>
        </div>
    </aside>
    <main class="main-content">
        <div class="main-toolbar">
            <div class="page-header">
                <button type="button" class="menu-btn" id="menuBtn" aria-label="<?= cinema_h(cinema_t('open_menu')) ?>"><i class="bi bi-list"></i></button>
                <?php if ($backUrl !== ''): ?>
                    <a href="<?= cinema_h($backUrl) ?>" class="page-back-link"><i class="bi bi-arrow-left"></i> <?= cinema_h($backLabel) ?></a>
                <?php endif; ?>
                <h1><?= cinema_h($pageTitle) ?></h1>
            </div>
            <form class="search-form" action="search.php" method="get" id="cinemaSearchForm">
                <i class="bi bi-search"></i>
                <input type="search" name="q" id="cinemaSearch" value="<?= cinema_h($searchQuery) ?>" placeholder="<?= cinema_h(cinema_t('search_placeholder')) ?>" aria-label="<?= cinema_h(cinema_t('search')) ?>" autocomplete="off">
            </form>
        </div>
        <div class="<?= $flush ? 'browse-panel' : 'content-panel' ?>" id="pageContent">
            <?php if ($message !== ''): ?><div class="message success"><?= cinema_h($message) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="message error"><?= cinema_h($error) ?></div><?php endif; ?>
            <?= $content ?>
        </div>
    </main>
</div>
<script>window.cinemaI18n = <?= json_encode(cinema_js_strings(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="assets/app.js?v=<?= cinema_h(CINEMA_VERSION) ?>"></script>
</body>
</html>
