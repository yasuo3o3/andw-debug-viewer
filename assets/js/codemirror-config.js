(function () {
    'use strict';

    // グローバル変数チェック
    if (typeof window.wp === 'undefined' || typeof window.wp.codeEditor === 'undefined') {
        console.error('andW Debug Viewer: wp.codeEditor is not available');
        return;
    }

    if (typeof window.andwCodeMirrorConfig === 'undefined') {
        console.error('andW Debug Viewer: andwCodeMirrorConfig is not defined');
        return;
    }

    const config = window.andwCodeMirrorConfig;
    let codeMirrorInstance = null;
    let wpDebugHighlightMarks = [];

    /**
     * Initialize CodeMirror for wp-config editor
     */
    function initializeCodeMirror() {
        const textarea = document.getElementById('wp-config-editor');

        if (!textarea) {
            console.warn('andW Debug Viewer: wp-config-editor not found');
            return false;
        }

        try {
            // CodeMirrorの初期化
            codeMirrorInstance = window.wp.codeEditor.initialize(textarea, config.settings);

            if (!codeMirrorInstance || !codeMirrorInstance.codemirror) {
                throw new Error('CodeMirror initialization failed');
            }

            const cm = codeMirrorInstance.codemirror;

            // WP_DEBUGハイライト機能の設定
            setupWpDebugHighlighting(cm);

            // エラーハンドリング
            cm.on('error', function(error) {
                console.error('andW Debug Viewer CodeMirror error:', error);
            });

            // リサイズ処理
            cm.on('refresh', function() {
                highlightWpDebugLines(cm);
            });

            // 初回ハイライト実行
            setTimeout(function() {
                highlightWpDebugLines(cm);
            }, 100);

            console.log('andW Debug Viewer: CodeMirror initialized successfully');
            return true;

        } catch (error) {
            console.error('andW Debug Viewer: CodeMirror initialization error:', error);
            showFallbackMessage();
            return false;
        }
    }

    /**
     * Setup WP_DEBUG highlighting functionality
     */
    function setupWpDebugHighlighting(cm) {
        if (!config.wpDebugHighlight.enabled) {
            return;
        }

        // リアルタイムハイライト（変更時）
        cm.on('change', function() {
            // パフォーマンス考慮でデバウンス
            clearTimeout(window.andwHighlightTimeout);
            window.andwHighlightTimeout = setTimeout(function() {
                highlightWpDebugLines(cm);
            }, 300);
        });

        // カーソル移動時の追加ハイライト
        cm.on('cursorActivity', function() {
            clearTimeout(window.andwCursorTimeout);
            window.andwCursorTimeout = setTimeout(function() {
                highlightWpDebugLines(cm);
            }, 100);
        });
    }

    /**
     * Highlight WP_DEBUG related lines
     */
    function highlightWpDebugLines(cm) {
        if (!cm || !config.wpDebugHighlight.enabled) {
            return;
        }

        // 既存のマークをクリア
        clearWpDebugHighlights(cm);

        const doc = cm.getDoc();
        const totalLines = doc.lineCount();

        for (let i = 0; i < totalLines; i++) {
            const line = doc.getLine(i);
            const trimmedLine = line.trim();

            // WP_DEBUG関連行の検出
            if (isWpDebugLine(trimmedLine)) {
                highlightLine(cm, i, line, trimmedLine);
            }
        }
    }

    /**
     * Check if line contains WP_DEBUG related code
     */
    function isWpDebugLine(line) {
        return line.includes('WP_DEBUG') &&
               (line.includes('define(') || line.includes('defined('));
    }

    /**
     * Highlight a specific line
     */
    function highlightLine(cm, lineNumber, fullLine, trimmedLine) {
        const doc = cm.getDoc();

        try {
            // 行全体をハイライト
            const lineHandle = cm.addLineClass(lineNumber, 'background', 'andw-wp-debug-line');

            // テキストマーカーでさらに強調
            const from = { line: lineNumber, ch: 0 };
            const to = { line: lineNumber, ch: fullLine.length };

            const mark = doc.markText(from, to, {
                className: 'andw-wp-debug-text',
                clearOnEnter: false,
                atomic: false
            });

            // マーク情報を保存（後でクリアするため）
            wpDebugHighlightMarks.push({
                lineHandle: lineHandle,
                textMark: mark,
                lineNumber: lineNumber
            });

        } catch (error) {
            console.warn('andW Debug Viewer: Error highlighting line ' + lineNumber, error);
        }
    }

    /**
     * Clear all WP_DEBUG highlights
     */
    function clearWpDebugHighlights(cm) {
        wpDebugHighlightMarks.forEach(function(mark) {
            try {
                if (mark.textMark && mark.textMark.clear) {
                    mark.textMark.clear();
                }
                if (mark.lineHandle) {
                    cm.removeLineClass(mark.lineNumber, 'background', 'andw-wp-debug-line');
                }
            } catch (error) {
                console.warn('andW Debug Viewer: Error clearing highlight', error);
            }
        });

        wpDebugHighlightMarks = [];
    }

    /**
     * Show fallback message when CodeMirror fails
     */
    function showFallbackMessage() {
        const editor = document.getElementById('wp-config-editor');
        if (editor && editor.parentNode) {
            const notice = document.createElement('div');
            notice.className = 'notice notice-warning inline';
            notice.style.marginBottom = '10px';
            notice.innerHTML = '<p><strong>' + config.i18n.initError + '</strong> ' + config.i18n.fallbackMode + '</p>';
            editor.parentNode.insertBefore(notice, editor);
        }
    }

    /**
     * Cleanup function for page unload
     */
    function cleanup() {
        if (codeMirrorInstance && codeMirrorInstance.codemirror) {
            clearWpDebugHighlights(codeMirrorInstance.codemirror);
        }

        clearTimeout(window.andwHighlightTimeout);
        clearTimeout(window.andwCursorTimeout);
    }

    // Initialize when DOM is ready
    function initialize() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeCodeMirror);
        } else {
            // DOM already loaded
            setTimeout(initializeCodeMirror, 10);
        }

        // Cleanup on page unload
        window.addEventListener('beforeunload', cleanup);
    }

    // Start initialization
    initialize();

})();