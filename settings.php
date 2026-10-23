<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $nas = trim((string) ($_POST['nas_root'] ?? ''));
    $moviesDir = trim((string) ($_POST['movies_dir'] ?? 'Movies'));
    $seriesDir = trim((string) ($_POST['series_dir'] ?? 'Series'));
    $key = trim((string) ($_POST['tmdb_key'] ?? ''));
    $lang = trim((string) ($_POST['tmdb_language'] ?? 'en-US'));
    $lang = $lang === 'it-IT' ? 'it-IT' : 'en-US';
    $appLang = trim((string) ($_POST['app_language'] ?? 'en'));
    $appLang = $appLang === 'it' ? 'it' : 'en';
    if ($nas === '') {
        $error = cinema_t('nas_required');
    } else {
        cinema_config_save([
            'nas_root' => $nas,
            'movies_dir' => $moviesDir !== '' ? $moviesDir : 'Movies',
            'series_dir' => $seriesDir !== '' ? $seriesDir : 'Series',
            'tmdb_key' => $key,
            'tmdb_language' => $lang !== '' ? $lang : 'en-US',
            'app_language' => $appLang,
        ]);
        $message = cinema_t('settings_saved');
    }
}

$cfg = cinema_config();
$stats = cinema_stats();
$nasOk = is_dir((string) $cfg['nas_root']);
$moviesOk = is_dir(cinema_movies_root());
$seriesOk = is_dir(cinema_series_root());
$disk = cinema_disk_usage((string) $cfg['nas_root']);
$ffmpeg = false;
$ffmpegBin = false;
if (function_exists('exec')) {
    $out = [];
    @exec('ffprobe -version 2>&1', $out);
    $ffmpeg = $out !== [];
    $ffmpegBin = cinema_ffmpeg_available();
}

ob_start();
?>
<div class="settings-layout">
    <form method="post" class="form-grid">
        <input type="hidden" name="action" value="save">
        <div>
            <label for="nas_root"><?= cinema_h(cinema_t('nas_root')) ?></label>
            <input type="text" id="nas_root" name="nas_root" value="<?= cinema_h((string) $cfg['nas_root']) ?>" required>
            <p class="hint"><?= $nasOk ? cinema_h(cinema_t('folder_exists')) : cinema_h(cinema_t('folder_missing')) ?></p>
        </div>
        <div>
            <label for="movies_dir"><?= cinema_h(cinema_t('movies_subfolder')) ?></label>
            <input type="text" id="movies_dir" name="movies_dir" value="<?= cinema_h((string) $cfg['movies_dir']) ?>">
            <p class="hint"><?= cinema_h(cinema_movies_root()) ?> — <?= $moviesOk ? cinema_h(cinema_t('ok')) : cinema_h(cinema_t('missing')) ?></p>
        </div>
        <div>
            <label for="series_dir"><?= cinema_h(cinema_t('series_subfolder')) ?></label>
            <input type="text" id="series_dir" name="series_dir" value="<?= cinema_h((string) $cfg['series_dir']) ?>">
            <p class="hint"><?= cinema_h(cinema_series_root()) ?> — <?= $seriesOk ? cinema_h(cinema_t('ok')) : cinema_h(cinema_t('missing')) ?></p>
        </div>
        <div>
            <label for="tmdb_key"><?= cinema_h(cinema_t('tmdb_key')) ?></label>
            <input type="text" id="tmdb_key" name="tmdb_key" value="<?= cinema_h((string) $cfg['tmdb_key']) ?>" autocomplete="off">
            <p class="hint"><?= str_replace('{link}', '<a href="https://www.themoviedb.org/settings/api" target="_blank" rel="noopener">themoviedb.org</a>', cinema_h(cinema_t('tmdb_key_hint'))) ?></p>
        </div>
        <div>
            <label for="tmdb_language"><?= cinema_h(cinema_t('tmdb_language')) ?></label>
            <?php $lang = (string) $cfg['tmdb_language']; ?>
            <select id="tmdb_language" name="tmdb_language">
                <option value="it-IT"<?= $lang === 'it-IT' ? ' selected' : '' ?>><?= cinema_h(cinema_t('lang_italian')) ?></option>
                <option value="en-US"<?= $lang !== 'it-IT' ? ' selected' : '' ?>><?= cinema_h(cinema_t('lang_english')) ?></option>
            </select>
        </div>
        <div>
            <label for="app_language"><?= cinema_h(cinema_t('app_language')) ?></label>
            <?php $appLangUi = cinema_app_lang(); ?>
            <select id="app_language" name="app_language">
                <option value="it"<?= $appLangUi === 'it' ? ' selected' : '' ?>><?= cinema_h(cinema_t('lang_italian')) ?></option>
                <option value="en"<?= $appLangUi !== 'it' ? ' selected' : '' ?>><?= cinema_h(cinema_t('lang_english')) ?></option>
            </select>
        </div>
        <div>
            <button type="submit" class="btn btn-primary"><?= cinema_h(cinema_t('save_settings')) ?></button>
        </div>
    </form>

    <div class="settings-side">
        <div class="stats-grid">
            <div class="stat-card"><strong><?= (int) $stats['movies'] ?></strong><span><?= cinema_h(cinema_t('movies')) ?></span></div>
            <div class="stat-card"><strong><?= (int) $stats['shows'] ?></strong><span><?= cinema_h(cinema_t('series')) ?></span></div>
            <div class="stat-card"><strong><?= (int) $stats['episodes'] ?></strong><span><?= cinema_h(cinema_t('episodes')) ?></span></div>
            <div class="stat-card"><strong><?= (int) $stats['movies_unmatched'] + (int) $stats['shows_unmatched'] ?></strong><span><?= cinema_h(cinema_t('unmatched_tmdb')) ?></span></div>
        </div>
        <div class="storage-card<?= $disk && $disk['free_pct'] < 10 ? ' storage-low' : '' ?>">
            <div class="storage-card-head">
                <strong><?= cinema_h(cinema_t('storage')) ?></strong>
                <?php if ($disk): ?>
                    <span><?= cinema_h(cinema_t('storage_free', [
                        'free' => cinema_format_bytes($disk['free']),
                        'pct' => (string) $disk['free_pct'],
                    ])) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($disk): ?>
                <div class="storage-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) round($disk['used_pct']) ?>" aria-label="<?= cinema_h(cinema_t('storage')) ?>">
                    <span style="width:<?= cinema_h((string) min(100, max(0, $disk['used_pct']))) ?>%"></span>
                </div>
                <p class="storage-meta">
                    <?= cinema_h(cinema_t('storage_used', [
                        'used' => cinema_format_bytes($disk['used']),
                        'total' => cinema_format_bytes($disk['total']),
                    ])) ?>
                </p>
            <?php else: ?>
                <p class="storage-meta"><?= cinema_h(cinema_t('storage_unavailable')) ?></p>
            <?php endif; ?>
        </div>
        <p class="hint"><?= cinema_h(cinema_t('last_scan')) ?>: <?= cinema_h((string) ($cfg['last_scan_at'] ?: cinema_t('never'))) ?> · FFmpeg/ffprobe: <?= $ffmpeg ? cinema_h(cinema_t('ffmpeg_on')) : cinema_h(cinema_t('ffmpeg_off')) ?> · FFmpeg: <?= $ffmpegBin ? cinema_h(cinema_t('ok')) : cinema_h(cinema_t('ffmpeg_off')) ?> · <?= cinema_h(cinema_t('remote_http')) ?>: <?= ini_get('allow_url_fopen') ? cinema_h(cinema_t('http_on')) : cinema_h(cinema_t('http_off')) ?> · Cinema <?= cinema_h(CINEMA_VERSION) ?></p>

        <div class="action-groups">
            <div class="action-group">
                <h3><?= cinema_h(cinema_t('actions_library')) ?></h3>
                <div class="btn-row">
                    <button type="button" class="btn btn-primary" id="scanBtn"><i class="bi bi-folder2-open"></i> <?= cinema_h(cinema_t('scan_library')) ?></button>
                    <button type="button" class="btn btn-secondary" id="metaBtn"><i class="bi bi-cloud-download"></i> <?= cinema_h(cinema_t('fetch_meta')) ?></button>
                    <button type="button" class="btn btn-secondary" id="retryBtn"><?= cinema_h(cinema_t('retry_failed_btn')) ?></button>
                </div>
            </div>
            <div class="action-group">
                <h3><?= cinema_h(cinema_t('actions_convert')) ?></h3>
                <div class="btn-row">
                    <button type="button" class="btn btn-secondary" id="scanConvertBtn"><i class="bi bi-search"></i> <?= cinema_h(cinema_t('scan_convert')) ?></button>
                    <button type="button" class="btn btn-primary" id="convertBtn"<?= $ffmpegBin ? '' : ' disabled' ?>><i class="bi bi-arrow-repeat"></i> <?= cinema_h(cinema_t('convert_mp4')) ?></button>
                </div>
                <p class="hint"><?= cinema_h(cinema_t('convert_keep_hint')) ?></p>
            </div>
            <div class="action-group">
                <h3><?= cinema_h(cinema_t('actions_clear')) ?></h3>
                <div class="btn-row">
                    <button type="button" class="btn btn-danger" id="clearMetaBtn"><i class="bi bi-trash"></i> <?= cinema_h(cinema_t('clear_meta')) ?></button>
                    <button type="button" class="btn btn-danger" id="clearProgressBtn"><i class="bi bi-stopwatch"></i> <?= cinema_h(cinema_t('clear_progress')) ?></button>
                </div>
            </div>
        </div>
        <div class="job-progress" id="jobProgress" hidden>
            <div class="job-progress-bar"><span id="jobProgressFill"></span></div>
        </div>
        <p class="hint" id="jobStatus"></p>

        <div class="folder-layout-box">
            <h2 class="section-title"><?= cinema_h(cinema_t('folder_layout')) ?></h2>
            <pre class="hint"><?= cinema_h((string) $cfg['nas_root']) ?>/
  <?= cinema_h((string) $cfg['movies_dir']) ?>/
    The Matrix (1999)/The Matrix (1999).mp4
    Inception (2010).mp4
  <?= cinema_h((string) $cfg['series_dir']) ?>/
    Breaking Bad/Season 01/Breaking Bad - S01E01.mp4
    The Office/S01E01.mp4</pre>
        </div>
    </div>
</div>
<?php
cinema_render('layout', [
    'content' => ob_get_clean(),
    'pageTitle' => cinema_t('settings'),
    'activeNav' => 'settings',
    'flush' => false,
    'message' => $message,
    'error' => $error,
]);
