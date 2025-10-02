(function () {
    const data = window.andwData || {};
    const { __ } = window.wp.i18n;
    const apiFetch = window.wp.apiFetch;

    const textarea = document.getElementById('andw-log');
    const statusEl = document.getElementById('andw-status');
    const statsEl = document.getElementById('andw-stats');
    const refreshBtn = document.getElementById('andw-refresh');
    const pauseBtn = document.getElementById('andw-pause');
    const clearBtn = document.getElementById('andw-clear');
    const downloadBtn = document.getElementById('andw-download');
    const linesInput = document.getElementById('andw-lines');
    const minutesInput = document.getElementById('andw-minutes');
    const modeLines = document.getElementById('andw-mode-lines');
    const modeMinutes = document.getElementById('andw-mode-minutes');

    if (!textarea || !refreshBtn) {
        return;
    }

    const state = {
        mode: modeLines.checked ? 'lines' : 'minutes',
        paused: false,
        timer: null,
    };

    const strings = data.strings || {};

    function getNonceHeaders() {
        return {
            'X-WP-Nonce': data.nonce,
        };
    }

    function setStatus(message, type = 'info') {
        if (!statusEl) {
            return;
        }
        statusEl.textContent = message || '';
        statusEl.className = 'andw-status andw-status-' + type;
    }

    function renderStats(stats) {
        if (!statsEl || !stats) {
            return;
        }
        if (!stats.exists) {
            statsEl.textContent = strings.missingLog || '';
            return;
        }
        const sizeLabel = strings.sizeLabel || __('サイズ', 'andw-debug-viewer');
        const updatedLabel = strings.updatedLabel || __('最終更新', 'andw-debug-viewer');
        const updated = stats.modified ? new Date(stats.modified * 1000).toLocaleString() : '-';
        statsEl.textContent = sizeLabel + ': ' + stats.size_human + ' / ' + updatedLabel + ': ' + updated;
    }

    function renderLog(result) {
        if (textarea) {
            textarea.value = result.log || '';
        }
        if (result.meta && result.meta.fallback && strings.fallbackNote) {
            setStatus(strings.fallbackNote, 'warning');
        }
    }

    function getModeValue() {
        const maxLines = data.settings ? parseInt(data.settings.maxLines, 10) : 1000;
        if (modeMinutes.checked) {
            const minutes = parseInt(minutesInput.value, 10) || 1;
            state.mode = 'minutes';
            return Math.max(minutes, 1);
        }
        const lines = parseInt(linesInput.value, 10) || 1;
        state.mode = 'lines';
        return Math.min(Math.max(lines, 1), maxLines);
    }

    function fetchLog() {
        const value = getModeValue();
        const path = data.restUrl + 'tail?mode=' + state.mode + '&value=' + encodeURIComponent(value);
        setStatus(strings.refresh || __('再読み込み', 'andw-debug-viewer'));
        return apiFetch({
            path: path,
            headers: getNonceHeaders(),
        })
            .then(function (response) {
                renderLog(response);
                renderStats(response.stats);
                setStatus('');
                return response;
            })
            .catch(function (error) {
                const message = error && error.message ? error.message : (strings.downloadError || __('取得に失敗しました。', 'andw-debug-viewer'));
                setStatus(message, 'error');
            });
    }

    function fetchStats() {
        const path = data.restUrl + 'stats';
        return apiFetch({
            path: path,
            headers: getNonceHeaders(),
        })
            .then(function (response) {
                renderStats(response.stats);
            })
            .catch(function () {
                // ignore
            });
    }

    function startTimer() {
        clearTimer();
        const interval = data.settings ? parseInt(data.settings.autoRefresh, 10) : 5;
        if (!interval || state.paused) {
            return;
        }
        state.timer = window.setInterval(fetchLog, interval * 1000);
    }

    function clearTimer() {
        if (state.timer) {
            window.clearInterval(state.timer);
            state.timer = null;
        }
    }

    function togglePause() {
        state.paused = !state.paused;
        if (state.paused) {
            clearTimer();
            setStatus(strings.paused || __('自動更新を一時停止しました。', 'andw-debug-viewer'), 'info');
            pauseBtn.textContent = strings.resume || __('再開', 'andw-debug-viewer');
        } else {
            setStatus(strings.resumed || __('自動更新を再開しました。', 'andw-debug-viewer'), 'info');
            pauseBtn.textContent = strings.pause || __('一時停止', 'andw-debug-viewer');
            startTimer();
        }
    }

    function handleClear() {
        if (!clearBtn || clearBtn.disabled) {
            return;
        }
        const confirmMessage = strings.clearConfirm || __('本当に debug.log をクリアしますか？', 'andw-debug-viewer');
        if (!window.confirm(confirmMessage)) {
            return;
        }
        apiFetch({
            path: data.restUrl + 'clear',
            method: 'POST',
            headers: getNonceHeaders(),
        })
            .then(function () {
                setStatus(strings.cleared || __('ログをクリアしました。', 'andw-debug-viewer'), 'success');
                fetchLog();
            })
            .catch(function (error) {
                const message = error && error.message ? error.message : __('クリアに失敗しました。', 'andw-debug-viewer');
                setStatus(message, 'error');
            });
    }

    function handleDownload() {
        if (!downloadBtn || downloadBtn.disabled) {
            return;
        }
        setStatus(__('ダウンロード中…', 'andw-debug-viewer'));
        apiFetch({
            path: data.restUrl + 'download',
            headers: getNonceHeaders(),
            parse: false,
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error(strings.downloadError || __('ダウンロードに失敗しました。', 'andw-debug-viewer'));
                }
                const disposition = response.headers.get('Content-Disposition') || '';
                const match = disposition.match(/filename="?([^";]+)"?/);
                const filename = match ? match[1] : 'debug.log';
                return response.blob().then(function (blob) {
                    return { blob: blob, filename: filename };
                });
            })
            .then(function (result) {
                const url = window.URL.createObjectURL(result.blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = result.filename;
                link.click();
                window.URL.revokeObjectURL(url);
                setStatus('');
            })
            .catch(function (error) {
                const message = error && error.message ? error.message : (strings.downloadError || __('ダウンロードに失敗しました。', 'andw-debug-viewer'));
                setStatus(message, 'error');
            });
    }

    function attachEvents() {
        refreshBtn.addEventListener('click', function () {
            fetchLog();
        });

        pauseBtn.addEventListener('click', function () {
            togglePause();
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', handleClear);
        }
        if (downloadBtn) {
            downloadBtn.addEventListener('click', handleDownload);
        }

        [modeLines, modeMinutes, linesInput, minutesInput].forEach(function (el) {
            if (el) {
                el.addEventListener('change', function () {
                    if (!state.paused) {
                        fetchLog();
                    }
                });
            }
        });
    }

    function initialise() {
        renderStats(data.stats || {});
        fetchLog().then(function () {
            if (!state.paused) {
                startTimer();
            }
        });
        fetchStats();
        attachEvents();
    }

    initialise();

    // DOMContentLoaded後にカウントダウンを初期化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', andwInitCountdowns);
    } else {
        andwInitCountdowns();
    }
})();

/**
 * Initialize countdown timers for temporary permissions
 */
function andwInitCountdowns() {
    const countdownElements = document.querySelectorAll('.andw-countdown');

    countdownElements.forEach(function(element) {
        const statusDisplay = element.closest('.andw-status-display');
        if (!statusDisplay) {
            return;
        }

        const expiresTimestamp = parseInt(statusDisplay.dataset.expires, 10);
        if (!expiresTimestamp) {
            return;
        }

        function updateCountdown() {
            const now = Math.floor(Date.now() / 1000);
            const remaining = expiresTimestamp - now;

            if (remaining <= 0) {
                // 期限切れ - 要素を隠す
                statusDisplay.style.display = 'none';
                return;
            }

            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            const timeString = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;

            element.textContent = timeString + ' 残り';
        }

        // 初回更新
        updateCountdown();

        // 1秒ごとに更新
        const interval = setInterval(updateCountdown, 1000);

        // 期限切れ後にインターバルをクリア
        setTimeout(function() {
            clearInterval(interval);
            statusDisplay.style.display = 'none';
        }, (expiresTimestamp - Math.floor(Date.now() / 1000)) * 1000);
    });
}
