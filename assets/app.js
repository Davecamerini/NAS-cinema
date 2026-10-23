(function () {
    var toggle = document.getElementById('darkModeToggle');
    var moon = document.getElementById('moonIcon');
    var sun = document.getElementById('sunIcon');
    if (toggle && moon && sun) {
        var saved = localStorage.getItem('darkMode');
        var isDarkMode = saved === null ? true : saved === 'true';
        if (isDarkMode) {
            document.body.classList.add('dark-mode');
            moon.style.display = 'none';
            sun.style.display = 'block';
        }
        if (saved === null) {
            localStorage.setItem('darkMode', 'true');
        }
        toggle.addEventListener('click', function () {
            var isDark = document.body.classList.toggle('dark-mode');
            moon.style.display = isDark ? 'none' : 'block';
            sun.style.display = isDark ? 'block' : 'none';
            localStorage.setItem('darkMode', isDark ? 'true' : 'false');
        });
    }

    var menuBtn = document.getElementById('menuBtn');
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    function closeMenu() {
        if (sidebar) sidebar.classList.remove('is-open');
        if (backdrop) backdrop.hidden = true;
    }
    if (menuBtn && sidebar) {
        menuBtn.addEventListener('click', function () {
            sidebar.classList.toggle('is-open');
            if (backdrop) backdrop.hidden = !sidebar.classList.contains('is-open');
        });
    }
    if (backdrop) {
        backdrop.addEventListener('click', closeMenu);
    }

    function t(key, vars) {
        var s = (window.cinemaI18n && window.cinemaI18n[key]) ? window.cinemaI18n[key] : key;
        if (vars) {
            Object.keys(vars).forEach(function (k) {
                s = s.replace('{' + k + '}', vars[k]);
            });
        }
        return s;
    }

    function setWatchToggleState(btn, isWatched) {
        btn.setAttribute('data-watched', isWatched ? '1' : '0');
        var label = btn.querySelector('.js-watch-toggle-label');
        if (label) label.textContent = isWatched ? t('mark_unwatched') : t('mark_watched');
        var icon = btn.querySelector('i');
        if (icon) {
            icon.className = 'bi ' + (isWatched ? 'bi-eye-slash' : 'bi-check2-circle');
        }
    }

    function setEpisodeRowWatched(row, isWatched) {
        var epBtn = row.querySelector('.js-watch-toggle');
        if (epBtn) setWatchToggleState(epBtn, isWatched);
        var bar = row.querySelector('.js-ep-progress');
        if (bar) {
            bar.hidden = !isWatched;
            var fill = bar.querySelector('span');
            if (fill) fill.style.width = isWatched ? '100%' : '0%';
        }
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-watch-toggle');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        if (btn.disabled) return;
        var watched = btn.getAttribute('data-watched') === '1';
        var type = btn.getAttribute('data-type') || '';
        var id = btn.getAttribute('data-id') || '';
        var next = watched ? '0' : '1';
        btn.disabled = true;
        fetch('ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=set_watched&type=' + encodeURIComponent(type)
                + '&id=' + encodeURIComponent(id)
                + '&watched=' + next
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (!data || !data.ok) return;
            var isWatched = !!data.watched;
            setWatchToggleState(btn, isWatched);
            var playLabel = document.querySelector('.js-play-label');
            if (playLabel) playLabel.textContent = t('play');
            if (type === 'show') {
                document.querySelectorAll('.episode-row').forEach(function (row) {
                    setEpisodeRowWatched(row, isWatched);
                });
            } else {
                var row = btn.closest('.episode-row');
                if (row) setEpisodeRowWatched(row, isWatched);
            }
            if (data.show_watched !== undefined) {
                var showBtn = document.querySelector('.js-watch-toggle[data-type="show"]');
                if (showBtn) setWatchToggleState(showBtn, !!data.show_watched);
            }
            var player = document.getElementById('cinemaPlayer');
            if (player && player.getAttribute('data-type') === type && player.getAttribute('data-id') === id) {
                player.setAttribute('data-watch-lock', isWatched ? '1' : '0');
            }
        }).finally(function () {
            btn.disabled = false;
        });
    });

    var searchForm = document.getElementById('cinemaSearchForm');
    var searchInput = document.getElementById('cinemaSearch');
    var pageContent = document.getElementById('pageContent');
    var pageTitleEl = document.querySelector('.page-header h1');
    var searchAbort = null;
    var originalSearchPage = {
        html: pageContent ? pageContent.innerHTML : '',
        title: pageTitleEl ? pageTitleEl.textContent : '',
        docTitle: document.title,
        url: location.pathname + location.search + location.hash
    };

    function restoreOriginalPage() {
        if (!pageContent) return;
        if (searchAbort) {
            searchAbort.abort();
            searchAbort = null;
        }
        pageContent.innerHTML = originalSearchPage.html;
        if (pageTitleEl) {
            pageTitleEl.textContent = originalSearchPage.title;
        }
        document.title = originalSearchPage.docTitle;
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', originalSearchPage.url);
        }
    }

    function runLiveSearch(q) {
        if (!pageContent) return;
        q = String(q || '');
        if (q === '') {
            restoreOriginalPage();
            return;
        }
        if (searchAbort) {
            searchAbort.abort();
        }
        searchAbort = new AbortController();
        fetch('ajax.php?action=search&q=' + encodeURIComponent(q), {
            signal: searchAbort.signal
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (!data || data.html == null) return;
            pageContent.innerHTML = data.html;
            if (pageTitleEl && data.title) {
                pageTitleEl.textContent = data.title;
            }
            document.title = (data.title || '') + ' — Cinema';
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', 'search.php?q=' + encodeURIComponent(q));
            }
        }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            runLiveSearch(searchInput.value.trim());
        });
    }
    if (searchForm) {
        searchForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (searchInput) {
                runLiveSearch(searchInput.value.trim());
            }
        });
    }

    var scanBtn = document.getElementById('scanBtn');
    var metaBtn = document.getElementById('metaBtn');
    var retryBtn = document.getElementById('retryBtn');
    var clearMetaBtn = document.getElementById('clearMetaBtn');
    var clearProgressBtn = document.getElementById('clearProgressBtn');
    var scanConvertBtn = document.getElementById('scanConvertBtn');
    var convertBtn = document.getElementById('convertBtn');
    var jobStatus = document.getElementById('jobStatus');
    var jobProgress = document.getElementById('jobProgress');
    var jobProgressFill = document.getElementById('jobProgressFill');
    var jobButtons = [scanBtn, metaBtn, retryBtn, clearMetaBtn, clearProgressBtn, scanConvertBtn, convertBtn].filter(Boolean);
    var convertLockedOff = !!(convertBtn && convertBtn.disabled);

    function setStatus(text) {
        if (jobStatus) jobStatus.textContent = text;
    }

    function setJobBusy(busy) {
        jobButtons.forEach(function (btn) {
            btn.disabled = busy || (btn === convertBtn && convertLockedOff);
        });
    }

    function setProgress(done, total) {
        if (!jobProgress || !jobProgressFill) return;
        if (!total) {
            jobProgress.hidden = true;
            jobProgressFill.style.width = '0%';
            return;
        }
        var pct = Math.max(0, Math.min(100, Math.round((done / total) * 100)));
        jobProgress.hidden = false;
        jobProgressFill.style.width = pct + '%';
    }

    function postAction(action, extra) {
        return fetch('ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=' + encodeURIComponent(action) + (extra ? '&' + extra : '')
        }).then(function (r) {
            return r.text().then(function (text) {
                var data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error((text || ('HTTP ' + r.status)).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 280));
                }
                return data;
            });
        });
    }

    if (scanBtn) {
        scanBtn.addEventListener('click', function () {
            setJobBusy(true);
            setProgress(0, 0);
            setStatus(t('scanning'));
            function next(reset) {
                postAction('scan', 'reset=' + (reset ? '1' : '0')).then(function (data) {
                    if (data.error) {
                        setStatus(data.error);
                        setJobBusy(false);
                        return;
                    }
                    var total = data.total || 0;
                    var done = data.done || 0;
                    var remaining = data.remaining || 0;
                    if (total > 0) {
                        setProgress(done, total);
                        setStatus(t('scan_progress', { done: done, total: total }));
                    }
                    if (remaining > 0) {
                        next(false);
                        return;
                    }
                    if (total > 0) {
                        setProgress(total, total);
                    }
                    setStatus(t('indexed', { movies: data.movies, shows: data.shows, episodes: data.episodes }));
                    window.setTimeout(function () { window.location.reload(); }, 800);
                }).catch(function (err) {
                    setStatus(t('scan_failed') + (err && err.message ? ': ' + err.message : ''));
                    setJobBusy(false);
                });
            }
            next(true);
        });
    }

    if (metaBtn) {
        metaBtn.addEventListener('click', function () {
            setJobBusy(true);
            var metaTotal = 0;
            setProgress(0, 0);
            setStatus(t('fetching_meta'));
            function next() {
                postAction('fetch_meta').then(function (data) {
                    if (data.error) {
                        setStatus(data.error);
                        setJobBusy(false);
                        return;
                    }
                    if (!metaTotal) {
                        metaTotal = data.pending_before || ((data.processed || 0) + (data.remaining || 0));
                    }
                    var remaining = data.remaining || 0;
                    var done = metaTotal > 0 ? metaTotal - remaining : (data.processed || 0);
                    if (metaTotal > 0) {
                        setProgress(done, metaTotal);
                        setStatus(t('meta_progress', { done: done, total: metaTotal }));
                    }
                    if (remaining > 0) {
                        next();
                        return;
                    }
                    if (metaTotal > 0) {
                        setProgress(metaTotal, metaTotal);
                    }
                    setStatus(t('meta_done'));
                    window.setTimeout(function () { window.location.reload(); }, 800);
                }).catch(function () {
                    setStatus(t('meta_failed'));
                    setJobBusy(false);
                });
            }
            next();
        });
    }

    if (retryBtn) {
        retryBtn.addEventListener('click', function () {
            setJobBusy(true);
            postAction('retry_failed').then(function () {
                setStatus(t('retry_cleared'));
                setJobBusy(false);
            }).catch(function () {
                setStatus(t('retry_failed'));
                setJobBusy(false);
            });
        });
    }

    if (clearMetaBtn) {
        clearMetaBtn.addEventListener('click', function () {
            setJobBusy(true);
            setProgress(0, 0);
            setStatus(t('clearing_meta'));
            postAction('clear_meta').then(function (data) {
                if (data.error) {
                    setStatus(data.error);
                    setJobBusy(false);
                    return;
                }
                setStatus(t('meta_cleared'));
                window.setTimeout(function () { window.location.reload(); }, 800);
            }).catch(function () {
                setStatus(t('clear_meta_failed'));
                setJobBusy(false);
            });
        });
    }

    if (clearProgressBtn) {
        clearProgressBtn.addEventListener('click', function () {
            setJobBusy(true);
            setProgress(0, 0);
            setStatus(t('clearing_progress'));
            postAction('clear_progress').then(function (data) {
                if (data.error) {
                    setStatus(data.error);
                    setJobBusy(false);
                    return;
                }
                setStatus(t('progress_cleared'));
                window.setTimeout(function () { window.location.reload(); }, 800);
            }).catch(function () {
                setStatus(t('clear_progress_failed'));
                setJobBusy(false);
            });
        });
    }

    if (scanConvertBtn) {
        scanConvertBtn.addEventListener('click', function () {
            setJobBusy(true);
            setProgress(0, 0);
            setStatus(t('scanning_convert'));
            postAction('convert_scan').then(function (data) {
                if (data.error) {
                    setStatus(data.error);
                    setJobBusy(false);
                    return;
                }
                var n = data.count || 0;
                setStatus(n > 0 ? t('convert_count', { n: n }) : t('convert_none'));
                setJobBusy(false);
            }).catch(function () {
                setStatus(t('convert_scan_failed'));
                setJobBusy(false);
            });
        });
    }

    if (convertBtn) {
        convertBtn.addEventListener('click', function () {
            setJobBusy(true);
            setProgress(0, 0);
            setStatus(t('converting', { title: '…' }));
            var convertTimer = null;
            function convertDetail(data, err) {
                var bits = [];
                if (err && err.message) bits.push(err.message);
                if (data && data.error) bits.push(data.error);
                if (data && data.log) bits.push(data.log);
                if (data && data.ffmpeg_ok === false) bits.push('ffmpeg=' + (data.ffmpeg || 'missing'));
                return bits.join(' — ');
            }
            function applyConvert(data) {
                if (data.error && !data.running && (data.remaining || 0) === 0 && (data.total || 0) === 0) {
                    setStatus(convertDetail(data) || t('convert_failed'));
                    setJobBusy(false);
                    return true;
                }
                var total = data.total || 0;
                var done = data.done || 0;
                var current = data.current || done;
                var percent = data.percent || 0;
                var remaining = data.remaining || 0;
                if (total > 0) {
                    setProgress(Math.round((done + percent / 100) * 100), total * 100);
                    if (data.title) {
                        setStatus(t('convert_progress', {
                            title: data.title,
                            percent: percent,
                            out: data.out || '0:00',
                            duration: data.duration || '—',
                            elapsed: data.elapsed || '0:00',
                            eta: data.eta || '—',
                            speed: data.speed || '—',
                            done: current,
                            total: total
                        }));
                    } else if (data.error) {
                        setStatus(data.error);
                    }
                }
                if (!data.running && remaining === 0) {
                    if (total === 0) {
                        setStatus(convertDetail(data) || t('convert_none'));
                    } else if ((data.failed || 0) > 0) {
                        setProgress(1, 1);
                        setStatus(t('convert_partial', {
                            ok: data.ok || 0,
                            total: total,
                            failed: data.failed
                        }) + (convertDetail(data) ? ' ' + convertDetail(data) : ''));
                    } else {
                        setProgress(1, 1);
                        setStatus(t('convert_done'));
                        window.setTimeout(function () { window.location.reload(); }, 800);
                        return true;
                    }
                    setJobBusy(false);
                    return true;
                }
                return false;
            }
            function tick() {
                postAction('convert_tick').then(function (data) {
                    if (applyConvert(data)) return;
                    convertTimer = window.setTimeout(tick, 900);
                }).catch(function (err) {
                    setStatus(t('convert_failed') + (err && err.message ? ': ' + err.message : ''));
                    setJobBusy(false);
                });
            }
            postAction('convert_begin').then(function (data) {
                if (applyConvert(data)) return;
                tick();
            }).catch(function (err) {
                setStatus(t('convert_failed') + (err && err.message ? ': ' + err.message : ''));
                setJobBusy(false);
            });
        });
    }

    var player = document.getElementById('cinemaPlayer');
    if (player) {
        var itemType = player.getAttribute('data-type');
        var itemId = player.getAttribute('data-id');
        var startAt = parseFloat(player.getAttribute('data-start') || '0');
        var lastSent = 0;

        player.addEventListener('loadedmetadata', function () {
            if (startAt > 10 && startAt < player.duration - 5) {
                player.currentTime = startAt;
            }
        });

        function sendProgress(force) {
            if (!itemType || !itemId) return;
            if (player.getAttribute('data-watch-lock') === '1') return;
            if (player.getAttribute('data-watch-lock') === '0') {
                player.removeAttribute('data-watch-lock');
            }
            var pos = player.currentTime || 0;
            var dur = player.duration || 0;
            if (!force && Math.abs(pos - lastSent) < 4) return;
            lastSent = pos;
            fetch('ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=progress&type=' + encodeURIComponent(itemType)
                    + '&id=' + encodeURIComponent(itemId)
                    + '&position=' + encodeURIComponent(String(pos))
                    + '&duration=' + encodeURIComponent(String(dur))
            });
        }

        var chromeTimer = null;
        var lastMouseX = null;
        var lastMouseY = null;

        function isVideoFullscreen() {
            var el = document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement;
            return !!el && (el === player || (el.contains && el.contains(player)));
        }

        function showPlayerChrome() {
            player.controls = true;
            document.body.classList.remove('watch-idle');
            if (chromeTimer) window.clearTimeout(chromeTimer);
            chromeTimer = null;
            if (isVideoFullscreen()) return;
            if (!player.paused && !player.ended) {
                chromeTimer = window.setTimeout(hidePlayerChrome, 2500);
            }
        }

        function hidePlayerChrome() {
            if (player.paused || player.ended || isVideoFullscreen()) return;
            player.controls = false;
            document.body.classList.add('watch-idle');
        }

        player.addEventListener('timeupdate', function () {
            sendProgress(false);
            maybeStartNextEpisodePrompt();
        });
        player.addEventListener('pause', function () {
            sendProgress(true);
            showPlayerChrome();
        });
        player.addEventListener('play', showPlayerChrome);
        player.addEventListener('ended', function () {
            sendProgress(true);
            showPlayerChrome();
            maybeStartNextEpisodePrompt();
        });
        window.addEventListener('beforeunload', function () { sendProgress(true); });
        document.addEventListener('mousemove', function (e) {
            if (lastMouseX !== null && e.clientX === lastMouseX && e.clientY === lastMouseY) return;
            lastMouseX = e.clientX;
            lastMouseY = e.clientY;
            showPlayerChrome();
        });
        document.addEventListener('pointerdown', showPlayerChrome);
        document.addEventListener('touchstart', showPlayerChrome);
        document.addEventListener('fullscreenchange', showPlayerChrome);
        document.addEventListener('webkitfullscreenchange', showPlayerChrome);
        player.addEventListener('click', function () {
            if (!player.controls && !isVideoFullscreen()) {
                if (player.paused) player.play(); else player.pause();
            }
        });

        var nextOverlay = document.getElementById('nextEpisodeOverlay');
        var nextCountdownEl = document.getElementById('nextEpisodeCountdown');
        var nextPlayBtn = document.getElementById('nextEpisodePlay');
        var nextCancelBtn = document.getElementById('nextEpisodeCancel');
        var nextTimer = null;
        var nextLeft = 0;
        var nextNavigating = false;
        var nextPromptStarted = false;
        var nextCountdownPaused = false;
        var NEXT_WATCHED_RATIO = 0.92;

        function clearNextEpisodeTimer() {
            if (nextTimer) {
                window.clearInterval(nextTimer);
                nextTimer = null;
            }
        }

        function updateNextCountdown() {
            if (!nextCountdownEl) return;
            nextCountdownEl.textContent = t('next_episode_in', { n: String(Math.max(0, nextLeft)) });
        }

        function markCurrentWatchedThenGo(url) {
            if (nextNavigating || !url) return;
            nextNavigating = true;
            clearNextEpisodeTimer();
            if (nextOverlay) nextOverlay.hidden = true;
            var body = 'action=set_watched&type=' + encodeURIComponent(itemType || '')
                + '&id=' + encodeURIComponent(itemId || '')
                + '&watched=1';
            var go = function () { window.location.href = url; };
            fetch('ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body,
                keepalive: true
            }).then(go).catch(go);
        }

        function runNextCountdownTick() {
            if (nextCountdownPaused || nextNavigating) return;
            nextLeft -= 1;
            updateNextCountdown();
            if (nextLeft <= 0) {
                clearNextEpisodeTimer();
                markCurrentWatchedThenGo(nextOverlay ? (nextOverlay.getAttribute('data-next-url') || '') : '');
            }
        }

        function startNextCountdown() {
            clearNextEpisodeTimer();
            nextCountdownPaused = false;
            nextTimer = window.setInterval(runNextCountdownTick, 1000);
        }

        function pauseNextCountdown() {
            nextCountdownPaused = true;
        }

        function resumeNextCountdown() {
            if (!nextPromptStarted || nextNavigating || !nextOverlay || nextOverlay.hidden) return;
            nextCountdownPaused = false;
        }

        function startNextEpisodePrompt() {
            if (nextPromptStarted || !nextOverlay || itemType !== 'episode') return;
            var url = nextOverlay.getAttribute('data-next-url') || '';
            if (!url) return;
            nextPromptStarted = true;
            nextLeft = parseInt(nextOverlay.getAttribute('data-seconds') || '10', 10) || 10;
            nextOverlay.hidden = false;
            document.body.classList.remove('watch-idle');
            updateNextCountdown();
            startNextCountdown();
        }

        function maybeStartNextEpisodePrompt() {
            if (nextPromptStarted || !nextOverlay || itemType !== 'episode') return;
            var dur = player.duration || 0;
            var pos = player.currentTime || 0;
            if (player.ended || (dur > 0 && pos / dur >= NEXT_WATCHED_RATIO)) {
                startNextEpisodePrompt();
            }
        }

        function cancelNextEpisodePrompt() {
            clearNextEpisodeTimer();
            nextCountdownPaused = false;
            if (nextOverlay) nextOverlay.hidden = true;
            if (nextCountdownEl) nextCountdownEl.textContent = '';
        }

        if (nextPlayBtn && nextOverlay) {
            nextPlayBtn.addEventListener('click', function () {
                markCurrentWatchedThenGo(nextOverlay.getAttribute('data-next-url') || '');
            });
            nextPlayBtn.addEventListener('mouseenter', pauseNextCountdown);
            nextPlayBtn.addEventListener('mouseleave', resumeNextCountdown);
            nextPlayBtn.addEventListener('focus', pauseNextCountdown);
            nextPlayBtn.addEventListener('blur', resumeNextCountdown);
        }
        if (nextCancelBtn) {
            nextCancelBtn.addEventListener('click', cancelNextEpisodePrompt);
        }

        function isPlayerTypingTarget(el) {
            return el && el.closest && el.closest('input, textarea, button');
        }

        document.addEventListener('keydown', function (e) {
            if (!player || isPlayerTypingTarget(e.target) || isVideoFullscreen()) return;
            var handled = true;
            if (e.code === 'Space') {
                e.preventDefault();
                e.stopPropagation();
                if (e.repeat) return;
                if (player.paused) player.play(); else player.pause();
            } else if (e.code === 'ArrowLeft') {
                e.preventDefault();
                player.currentTime = Math.max(0, player.currentTime - 10);
            } else if (e.code === 'ArrowRight') {
                e.preventDefault();
                player.currentTime = Math.min(player.duration || 0, player.currentTime + 10);
            } else if (e.code === 'ArrowUp') {
                e.preventDefault();
                player.volume = Math.min(1, player.volume + 0.1);
            } else if (e.code === 'ArrowDown') {
                e.preventDefault();
                player.volume = Math.max(0, player.volume - 0.1);
            } else {
                handled = false;
            }
            if (handled) showPlayerChrome();
        }, true);

        document.addEventListener('keyup', function (e) {
            if (!player || isPlayerTypingTarget(e.target) || isVideoFullscreen()) return;
            if (e.code === 'Space' || e.code === 'ArrowLeft' || e.code === 'ArrowRight'
                || e.code === 'ArrowUp' || e.code === 'ArrowDown') {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);

        showPlayerChrome();
    }
})();
