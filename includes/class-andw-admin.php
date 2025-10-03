<?php
/**
 * Admin interface handler.
 *
 * @package andw-debug-viewer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Andw_Admin
 */
class Andw_Admin {
    /**
     * Plugin instance.
     *
     * @var Andw_Plugin
     */
    private $plugin;

    /**
     * Constructor.
     *
     * @param Andw_Plugin $plugin Plugin instance.
     */
    public function __construct( Andw_Plugin $plugin ) {
        $this->plugin = $plugin;
        add_action( 'admin_post_andw_toggle_prod_override', array( $this, 'handle_toggle_production_override' ) );
        add_action( 'admin_post_andw_toggle_temp_logging', array( $this, 'handle_temp_logging_toggle' ) );
        add_action( 'admin_post_andw_test_log_output', array( $this, 'handle_test_log_output' ) );

        // 汎用的なadmin-post.phpデバッグ
        add_action( 'admin_post_nopriv_andw_toggle_temp_logging', array( $this, 'handle_temp_logging_toggle' ) );
        add_action( 'admin_init', array( $this, 'debug_admin_post' ) );

        // Ajax代替手段
        add_action( 'wp_ajax_andw_toggle_temp_logging', array( $this, 'ajax_toggle_temp_logging' ) );
    }

    /**
     * Debug admin-post.php requests.
     *
     * @return void
     */
    public function debug_admin_post() {
        if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'andw_admin_post_trace' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['action'] ) && strpos( sanitize_key( wp_unslash( $_POST['action'] ) ), 'andw_' ) === 0 ) {
            // admin-post.php action detected (logging removed)
        }
    }

    /**
     * Register admin menu (site context).
     *
     * @return void
     */
    public function register_menu() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        add_menu_page(
            __( 'andW Debug Viewer', 'andw-debug-viewer' ),
            __( 'andW Debug Viewer', 'andw-debug-viewer' ),
            'manage_options',
            'andw-debug-viewer',
            array( $this, 'render_page' ),
            'dashicons-editor-code',
            59
        );
    }

    /**
     * Register admin menu for network context.
     *
     * @return void
     */
    public function register_network_menu() {
        if ( ! is_multisite() ) {
            return;
        }
        if ( ! current_user_can( 'manage_network_options' ) ) {
            return;
        }

        add_menu_page(
            __( 'andW Debug Viewer', 'andw-debug-viewer' ),
            __( 'andW Debug Viewer', 'andw-debug-viewer' ),
            'manage_network_options',
            'andw-debug-viewer',
            array( $this, 'render_page' ),
            'dashicons-editor-code',
            59
        );
    }

    /**
     * Register plugin settings.
     *
     * @return void
     */
    public function register_settings() {
        register_setting(
            'andw_settings_group',
            Andw_Settings::OPTION_NAME,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this->plugin->get_settings_handler(), 'sanitize' ),
                'show_in_rest'      => false,
            )
        );

        add_settings_section(
            'andw_general',
            __( 'ビューアー設定', 'andw-debug-viewer' ),
            array( $this, 'render_general_section_intro' ),
            'andw-settings'
        );

        $this->add_number_field(
            'default_lines',
            __( '既定の表示行数', 'andw-debug-viewer' ),
            'andw-default-lines',
            10,
            1000,
            10,
            __( 'ログ初期表示で読み込む行数（最大1000行）。', 'andw-debug-viewer' )
        );

        $this->add_number_field(
            'default_minutes',
            __( '既定の表示分数', 'andw-debug-viewer' ),
            'andw-default-minutes',
            1,
            120,
            1,
            __( '「直近M分」モードで表示する既定の分数。', 'andw-debug-viewer' )
        );

        $this->add_number_field(
            'max_lines',
            __( '最大読み込み行数', 'andw-debug-viewer' ),
            'andw-max-lines',
            100,
            1000,
            50,
            __( '1回の読み込みで取得する最大行数。大きすぎるとパフォーマンスに影響します。', 'andw-debug-viewer' )
        );

        $this->add_number_field(
            'auto_refresh_interval',
            __( '自動更新間隔 (秒)', 'andw-debug-viewer' ),
            'andw-auto-refresh-interval',
            2,
            120,
            1,
            __( '自動更新タイマーの間隔（秒）。', 'andw-debug-viewer' )
        );

        add_settings_field(
            'andw_enable_download',
            __( 'ダウンロードを許可', 'andw-debug-viewer' ),
            array( $this, 'render_checkbox_field' ),
            'andw-settings',
            'andw_general',
            array(
                'key'         => 'enable_download',
                'label_for'   => 'andw-enable-download',
                'description' => __( 'ログのダウンロードボタンを表示します（5MB以下）。', 'andw-debug-viewer' ),
            )
        );

        if ( is_multisite() ) {
            add_settings_field(
                'andw_allow_site_actions',
                __( 'サイト管理者への操作許可', 'andw-debug-viewer' ),
                array( $this, 'render_checkbox_field' ),
                'andw-settings',
                'andw_general',
                array(
                    'key'         => 'allow_site_actions',
                    'label_for'   => 'andw-allow-site-actions',
                    'description' => __( '個別サイト管理画面からのクリア／ダウンロードを許可します。', 'andw-debug-viewer' ),
                )
            );
        }
    }

    /**
     * Helper to register number fields.
     *
     * @param string $key Setting key.
     * @param string $label Field label.
     * @param string $id HTML id.
     * @param int    $min Minimum value.
     * @param int    $max Maximum value.
     * @param int    $step Step value.
     * @param string $description Description.
     * @return void
     */
    private function add_number_field( $key, $label, $id, $min, $max, $step, $description ) {
        add_settings_field(
            'andw_' . $key,
            $label,
            array( $this, 'render_number_field' ),
            'andw-settings',
            'andw_general',
            array(
                'key'         => $key,
                'label_for'   => $id,
                'min'         => $min,
                'max'         => $max,
                'step'        => $step,
                'description' => $description,
            )
        );
    }

    /**
     * Enqueue assets for the admin page.
     *
     * @param string $hook Current admin hook.
     * @return void
     */
    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_andw-debug-viewer' !== $hook ) {
            return;
        }

        $is_network = is_network_admin();
        // ログ出力を抑制（WP_DEBUG_LOG=true環境での無限ループ防止）
        // error_log( 'andW Debug Viewer: Admin page render - calling get_permissions()' );
        $permissions = $this->plugin->get_permissions( $is_network );
        // error_log( 'andW Debug Viewer: Admin page render - permissions received: ' . print_r( $permissions, true ) );

        wp_enqueue_style(
            'andw-admin',
            ANDW_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            ANDW_VERSION
        );

        wp_enqueue_script(
            'andw-admin',
            ANDW_PLUGIN_URL . 'assets/js/admin.js',
            array( 'wp-api-fetch', 'wp-i18n', 'wp-element' ),
            ANDW_VERSION,
            true
        );

        wp_localize_script(
            'andw-admin',
            'andwData',
            $this->prepare_localized_data( $permissions, $is_network )
        );
    }

    /**
     * Render the admin page.
     *
     * @return void
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_network_options' ) ) {
            wp_die( esc_html__( 'この操作を実行する権限がありません。', 'andw-debug-viewer' ) );
        }

        // nonce検証
        if ( isset( $_GET['tab'] ) && ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'andw_switch_tab' ) ) ) {
            $active_tab = 'viewer';
        } else {
            $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'viewer';
        }

        if ( ! in_array( $active_tab, array( 'viewer', 'settings' ), true ) ) {
            $active_tab = 'viewer';
        }

        $permissions = $this->plugin->get_permissions( is_network_admin() );

        echo '<div class="wrap andw-wrap">';
        echo '<h1>' . esc_html__( 'andW Debug Viewer', 'andw-debug-viewer' ) . '</h1>';
        $this->render_tabs( $active_tab );
        $this->maybe_render_notice();

        if ( 'settings' === $active_tab ) {
            $this->render_settings_tab( $permissions );
        } else {
            $this->render_viewer_tab( $permissions );
        }

        echo '</div>';
    }

    /**
     * Render navigation tabs.
     *
     * @param string $active Active tab.
     * @return void
     */
    private function render_tabs( $active ) {
        $tabs = array(
            'viewer'   => __( 'ログビューアー', 'andw-debug-viewer' ),
            'settings' => __( '設定', 'andw-debug-viewer' ),
        );

        $base_url = is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );

        echo '<nav class="nav-tab-wrapper andw-nav">';
        foreach ( $tabs as $slug => $label ) {
            $class = ( $slug === $active ) ? ' nav-tab nav-tab-active' : ' nav-tab';
            $href  = add_query_arg(
                array(
                    'page' => 'andw-debug-viewer',
                    'tab'  => $slug,
                ),
                $base_url
            );

            printf(
                '<a href="%1$s" class="%2$s">%3$s</a>',
                esc_url( $href ),
                esc_attr( $class ),
                esc_html( $label )
            );
        }
        echo '</nav>';
    }

    /**
     * Render viewer tab content.
     *
     * @param array $permissions Permissions context.
     * @return void
     */
    private function render_viewer_tab( array $permissions ) {
        // WP_DEBUG_LOG ベースでの表示（ログ出力設定状況で判定）
        $wp_debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
        $environment = isset( $permissions['environment'] ) ? $permissions['environment'] : 'production';

        if ( $wp_debug_log_enabled ) {
            // ユーザーがログ出力を設定している → 既存ログは保護が必要（警告色）
            $badge_slug  = 'wordpress-debug';
            $badge_class = 'andw-badge andw-env-production';
            $badge_label = 'WP_DEBUG_LOG 出力設定';
        } else {
            // ユーザーがログ出力を設定していない → 一時ログは安全に削除可能（安全色）
            $badge_slug  = 'no-debug-log';
            $badge_class = 'andw-badge andw-env-debug';
            $badge_label = 'WP_DEBUG_LOG 設定なし';
        }

        $max_lines   = isset( $permissions['defaults']['max_lines'] ) ? (int) $permissions['defaults']['max_lines'] : 1000;

        echo '<section class="andw-viewer" aria-label="' . esc_attr__( 'デバッグログビューアー', 'andw-debug-viewer' ) . '">';
        echo '<header class="andw-toolbar">';
        echo '<div class="andw-environment">';
        printf( '<span class="%1$s">%2$s</span>', esc_attr( $badge_class ), esc_html( $badge_label ) );

        if ( ! empty( $permissions['override_active'] ) && ! empty( $permissions['override_expires'] ) ) {
            $expires = wp_date( 'Y/m/d H:i', (int) $permissions['override_expires'] );
            /* translators: %s: expiration date/time. */
            echo '<span class="andw-override-info">' . esc_html( sprintf( __( '一時許可中: %s まで', 'andw-debug-viewer' ), $expires ) ) . '</span>';
        }

        echo '</div>';

        echo '<div class="andw-mode-controls">';
        echo '<fieldset>';
        echo '<legend class="screen-reader-text">' . esc_html__( '表示モード', 'andw-debug-viewer' ) . '</legend>';
        echo '<label for="andw-mode-lines"><input type="radio" name="andw-mode" id="andw-mode-lines" value="lines" checked> ' . esc_html__( '直近N行', 'andw-debug-viewer' ) . '</label>';
        printf(
            '<input type="number" id="andw-lines" class="small-text" min="1" max="%1$s" step="10" value="%2$s">',
            esc_attr( $max_lines ),
            esc_attr( (int) $permissions['defaults']['lines'] )
        );
        echo '<label for="andw-mode-minutes"><input type="radio" name="andw-mode" id="andw-mode-minutes" value="minutes"> ' . esc_html__( '直近M分', 'andw-debug-viewer' ) . '</label>';
        printf(
            '<input type="number" id="andw-minutes" class="small-text" min="1" max="120" step="1" value="%1$s">',
            esc_attr( (int) $permissions['defaults']['minutes'] )
        );
        echo '</fieldset>';
        echo '</div>';

        echo '<div class="andw-actions">';
        echo '<button type="button" class="button" id="andw-refresh">' . esc_html__( '再読み込み', 'andw-debug-viewer' ) . '</button>';
        echo '<button type="button" class="button" id="andw-pause" data-paused="false">' . esc_html__( '一時停止', 'andw-debug-viewer' ) . '</button>';
        // ログ出力を抑制（WP_DEBUG_LOG=true環境での無限ループ防止）
        // error_log( 'andW Debug Viewer: Button rendering - 権限詳細: ' . print_r( $permissions, true ) );

        echo '<button type="button" class="button button-secondary" id="andw-clear"';
        if ( empty( $permissions['can_clear'] ) ) {
            echo ' disabled="disabled"';
        }
        echo '>' . esc_html__( 'ログをクリア', 'andw-debug-viewer' ) . '</button>';

        echo '<button type="button" class="button" id="andw-download"';
        if ( empty( $permissions['can_download'] ) ) {
            echo ' disabled="disabled"';
        }
        echo '>' . esc_html__( 'ダウンロード', 'andw-debug-viewer' ) . '</button>';
        echo '</div>';
        echo '</header>';

        // 一時ログ有効化ブロック（ログより上に配置）
        $this->render_temp_logging_controls_compact( $permissions );

        echo '<div class="andw-status" id="andw-status" aria-live="polite"></div>';
        echo '<textarea id="andw-log" class="andw-log" rows="40" readonly="readonly"></textarea>';
        echo '<footer class="andw-footer">';
        echo '<div class="andw-stats" id="andw-stats"></div>';
        if ( ! empty( $permissions['reasons']['clear'] ) || ! empty( $permissions['reasons']['download'] ) ) {
            echo '<div class="andw-hints">';
            if ( ! empty( $permissions['reasons']['clear'] ) ) {
                echo '<p class="description">' . esc_html( $permissions['reasons']['clear'] ) . '</p>';
            }
            if ( ! empty( $permissions['reasons']['download'] ) ) {
                echo '<p class="description">' . esc_html( $permissions['reasons']['download'] ) . '</p>';
            }
            echo '</div>';
        }
        echo '</footer>';

        // 危険操作ブロック（WP_DEBUG_LOG=true時こそ保護が必要）
        $wp_debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
        if ( $wp_debug_log_enabled ) {
            $this->render_production_override_controls_compact( $permissions );
        }

        echo '<noscript><p>' . esc_html__( 'このビューアーを使用するには JavaScript を有効にしてください。', 'andw-debug-viewer' ) . '</p></noscript>';
        echo '</section>';
    }

    /**
     * Render settings tab.
     *
     * @param array $permissions Permissions context.
     * @return void
     */
    private function render_settings_tab( array $permissions ) {
        echo '<section class="andw-settings">';

        echo '<div class="andw-card">';
        echo '<h2>' . esc_html__( '設定について', 'andw-debug-viewer' ) . '</h2>';
        echo '<p>' . esc_html__( '一時的なログ有効化や危険な操作の許可設定は、使いやすさのためログビューアータブに移動しました。', 'andw-debug-viewer' ) . '</p>';
        echo '<p>' . esc_html__( 'こちらの設定タブでは、ビューアーの基本動作設定を調整できます。', 'andw-debug-viewer' ) . '</p>';
        echo '</div>';

        echo '<form action="' . esc_url( admin_url( 'options.php' ) ) . '" method="post">';
        settings_fields( 'andw_settings_group' );
        do_settings_sections( 'andw-settings' );
        submit_button();
        echo '</form>';

        echo '</section>';
    }

    /**
     * Intro description for general section.
     *
     * @return void
     */
    public function render_general_section_intro() {
        echo '<p>' . esc_html__( 'ログビューアーの初期表示や自動更新設定を調整します。', 'andw-debug-viewer' ) . '</p>';
    }

    /**
     * Render a number field.
     *
     * @param array $args Field arguments.
     * @return void
     */
    public function render_number_field( array $args ) {
        $settings = $this->plugin->get_settings();
        $key   = $args['key'];
        $id    = $args['label_for'];
        $min   = isset( $args['min'] ) ? (int) $args['min'] : 0;
        $max   = isset( $args['max'] ) ? (int) $args['max'] : 0;
        $step  = isset( $args['step'] ) ? (int) $args['step'] : 1;
        $value = isset( $settings[ $key ] ) ? $settings[ $key ] : 0;

        printf(
            '<input type="number" id="%1$s" name="%2$s[%3$s]" value="%4$s" class="small-text" min="%5$s" max="%6$s" step="%7$s">',
            esc_attr( $id ),
            esc_attr( Andw_Settings::OPTION_NAME ),
            esc_attr( $key ),
            esc_attr( $value ),
            esc_attr( $min ),
            esc_attr( $max ),
            esc_attr( $step )
        );

        if ( ! empty( $args['description'] ) ) {
            echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
        }
    }

    /**
     * Render a checkbox field.
     *
     * @param array $args Field arguments.
     * @return void
     */
    public function render_checkbox_field( array $args ) {
        $settings = $this->plugin->get_settings();
        $key = $args['key'];
        $id  = $args['label_for'];
        $checked = ! empty( $settings[ $key ] );

        printf(
            '<label><input type="checkbox" id="%1$s" name="%2$s[%3$s]" value="1" %4$s> %5$s</label>',
            esc_attr( $id ),
            esc_attr( Andw_Settings::OPTION_NAME ),
            esc_attr( $key ),
            checked( $checked, true, false ),
            esc_html__( '有効にする', 'andw-debug-viewer' )
        );

        if ( ! empty( $args['description'] ) ) {
            echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
        }
    }

    /**
     * Render production override controls.
     *
     * @param array $permissions Permissions context.
     * @return void
     */
    private function render_production_override_controls( array $permissions ) {
        $override_active = ! empty( $permissions['override_active'] );
        $expires = ! empty( $permissions['override_expires'] ) ? wp_date( 'Y/m/d H:i', (int) $permissions['override_expires'] ) : '';

        echo '<div class="andw-card">';
        echo '<h2>' . esc_html__( 'WP_DEBUG=false 環境で「ログを削除」「ログをダウンロード」の一時許可', 'andw-debug-viewer' ) . '</h2>';
        if ( $override_active && $expires ) {
            /* translators: %s: expiration date/time. */
            echo '<p>' . esc_html( sprintf( __( '現在、一時許可が有効です（%s まで）。', 'andw-debug-viewer' ), $expires ) ) . '</p>';
        } else {
            echo '<p>' . esc_html__( 'WP_DEBUG=false の環境では既定でクリア／ダウンロードは無効です。必要な場合のみ15分間の一時許可を発行できます。', 'andw-debug-viewer' ) . '</p>';
        }

        echo '<div class="andw-control-row">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'andw_toggle_prod_override' );
        echo '<input type="hidden" name="action" value="andw_toggle_prod_override">';
        if ( $override_active ) {
            echo '<input type="hidden" name="state" value="disable">';
            submit_button( __( '一時許可を解除', 'andw-debug-viewer' ), 'secondary', 'submit', false );
        } else {
            echo '<input type="hidden" name="state" value="enable">';
            submit_button( __( '15分間許可を発行', 'andw-debug-viewer' ), 'primary', 'submit', false );
        }
        echo '</form>';

        if ( $override_active && ! empty( $permissions['override_expires'] ) ) {
            echo '<div class="andw-status-display" data-expires="' . esc_attr( $permissions['override_expires'] ) . '">';
            echo '<span class="andw-status-active">' . esc_html__( '有効中:', 'andw-debug-viewer' ) . '</span> ';
            echo '<span class="andw-countdown" id="override-countdown"></span>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * Render temporary logging controls.
     *
     * @param array $permissions Permissions context.
     * @return void
     */
    private function render_temp_logging_controls( array $permissions ) {
        $temp_logging_active = ! empty( $permissions['temp_logging_active'] );
        $temp_session_active = ! empty( $permissions['temp_session_active'] );
        $expires = ! empty( $permissions['temp_logging_expires'] ) ? wp_date( 'Y/m/d H:i', (int) $permissions['temp_logging_expires'] ) : '';

        // WordPress debug.log の状態を確認
        $debug_log_path = WP_CONTENT_DIR . '/debug.log';
        $debug_log_exists = file_exists( $debug_log_path );
        $wordpress_debug_log_enabled = ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG );
        $debug_log_working = false;

        if ( $debug_log_exists ) {
            // ケース1: debug.log ファイルが存在 = 過去にデバッグ機能を使用していた証拠
            $debug_log_working = true;
        } elseif ( $wordpress_debug_log_enabled ) {
            // ケース2: WP_DEBUG_LOG=true だが初回ログ出力が必要
            $debug_log_working = true;

            // 初回ログを出力してファイルを作成
            $init_message = '[' . wp_date( 'Y-m-d H:i:s' ) . '] andW Debug Viewer: WordPress debug log initialized (WP_DEBUG_LOG=true detected)';

            // WordPressデバッグセッションファイルを作成
            $settings_handler = $this->plugin->get_settings_handler();
            $settings_handler->create_wordpress_debug_session();
        } else {
            // ケース3: debug.log無し + WP_DEBUG_LOG=false = 完全無効状態
            $debug_log_working = false;
        }

        // 最終的な判定（プラグインの機能は含める）
        $actual_logging_works = $debug_log_working || $temp_logging_active || $temp_session_active;

        // ログ状態の記録（error_log削除済み）

        echo '<div class="andw-card">';
        echo '<h2>' . esc_html__( 'WP_DEBUG=false でも一時的にログを有効化', 'andw-debug-viewer' ) . '</h2>';
        if ( $actual_logging_works ) {
            if ( $temp_logging_active && $expires ) {
                /* translators: %s: expiration date/time. */
                echo '<p>' . esc_html( sprintf( __( '現在、一時ログ出力が有効です（%s まで）。', 'andw-debug-viewer' ), $expires ) ) . '</p>';
            } elseif ( $debug_log_exists ) {
                echo '<p>' . esc_html__( 'debug.log ファイルが存在するため、ログ機能を利用できます。過去のログも含めて閲覧可能です。', 'andw-debug-viewer' ) . '</p>';
            } elseif ( $wordpress_debug_log_enabled ) {
                echo '<p>' . esc_html__( 'wp-config.php で WP_DEBUG_LOG が有効なため、WordPress デバッグログが利用できます。', 'andw-debug-viewer' ) . '</p>';
            }
        } else {
            echo '<p>' . esc_html__( 'debug.log ファイルが存在せず、WP_DEBUG_LOG も無効です。wp-configを変更せずに15分間だけログ出力を有効化できます。', 'andw-debug-viewer' ) . '</p>';
        }

        echo '<div class="andw-control-row">';

        // ステータス表示
        if ( $actual_logging_works ) {
            if ( $temp_logging_active ) {
                echo '<div class="andw-status-display" data-expires="' . esc_attr( $permissions['temp_logging_expires'] ) . '" style="background: #d63638; color: white; padding: 8px 12px; border-radius: 4px; margin-bottom: 10px; display: inline-block;">';
                echo '<strong>🟢 一時ログ出力 有効中</strong>';
                if ( ! empty( $permissions['temp_logging_expires'] ) ) {
                    $remaining = $permissions['temp_logging_expires'] - current_time( 'timestamp' );
                    $minutes = max( 0, absint( floor( $remaining / 60 ) ) );
                    $seconds = max( 0, absint( $remaining % 60 ) );
                    echo ' - 残り時間: <span class="andw-countdown" id="temp-logging-countdown">' . esc_html( sprintf( '%02d:%02d', $minutes, $seconds ) ) . '</span>';
                }
                echo '</div><br>';
            } elseif ( $temp_session_active ) {
                echo '<div style="background: #00a32a; color: white; padding: 8px 12px; border-radius: 4px; margin-bottom: 10px; display: inline-block;">';
                echo '<strong>🟢 一時セッション 有効中</strong> - ログ操作が15分間許可されています';
                echo '</div><br>';
            } else {
                echo '<div style="background: #00a32a; color: white; padding: 8px 12px; border-radius: 4px; margin-bottom: 10px; display: inline-block;">';
                if ( $debug_log_exists ) {
                    echo '<strong>✅ debug.log ファイル 利用可能</strong> - 既存のログファイルが見つかりました';
                } elseif ( $wordpress_debug_log_enabled ) {
                    echo '<strong>✅ WordPress デバッグログ 有効</strong> - wp-config.php で WP_DEBUG_LOG が有効（初回ログを作成しました）';
                }
                echo '</div><br>';
            }
        } else {
            echo '<div style="background: #72aee6; color: white; padding: 8px 12px; border-radius: 4px; margin-bottom: 10px; display: inline-block;">';
            echo '<strong>⭕ ログ機能 無効</strong> - 必要に応じて有効化してください';
            echo '</div><br>';
        }

        if ( ! $actual_logging_works ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block; margin-right:10px;">';
            wp_nonce_field( 'andw_toggle_temp_logging' );
            echo '<input type="hidden" name="action" value="andw_toggle_temp_logging">';
            if ( $temp_logging_active ) {
                echo '<input type="hidden" name="state" value="disable">';
                submit_button( __( '⏹️ 一時ログ出力を停止', 'andw-debug-viewer' ), 'delete', 'submit', false );
            } else {
                echo '<input type="hidden" name="state" value="enable">';
                submit_button( __( '▶️ 15分間ログ出力を有効化', 'andw-debug-viewer' ), 'primary', 'submit', false );
            }
            echo '</form>';
        } else {
            echo '<p style="margin: 0; padding: 8px; background: #f0f6fc; border: 1px solid #0969da; border-radius: 4px; color: #0969da; display: inline-block;">';
            echo '<strong>ℹ️ 既にログ機能が有効です</strong> - 一時有効化は不要です';
            echo '</p>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block; margin-right:10px;">';
        wp_nonce_field( 'andw_test_log_output' );
        echo '<input type="hidden" name="action" value="andw_test_log_output">';
        submit_button( __( '🧪 テスト用ログ出力', 'andw-debug-viewer' ), 'secondary', 'submit', false );
        echo '</form>';


        echo '</div>';
        echo '</div>';
    }

    /**
     * Render compact temporary logging controls for viewer tab.
     *
     * @param array $permissions Permissions context.
     * @return void
     */
    private function render_temp_logging_controls_compact( array $permissions ) {
        $temp_logging_active = ! empty( $permissions['temp_logging_active'] );
        $temp_session_active = ! empty( $permissions['temp_session_active'] );
        $expires = ! empty( $permissions['temp_logging_expires'] ) ? wp_date( 'Y/m/d H:i', (int) $permissions['temp_logging_expires'] ) : '';

        $debug_log_path = WP_CONTENT_DIR . '/debug.log';
        $debug_log_exists = file_exists( $debug_log_path );
        $wordpress_debug_log_enabled = ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG );
        $debug_log_working = $debug_log_exists || $wordpress_debug_log_enabled;

        $actual_logging_works = $debug_log_working || $temp_logging_active || $temp_session_active;

        // 常時状態を表示
        echo '<div class="andw-temp-logging-compact" style="border-radius: 4px; padding: 10px; margin: 10px 0;">';

        if ( $temp_logging_active && $expires ) {
            // 一時ログ有効中
            echo '<div style="background: #d63638; color: white; padding: 8px 12px; border-radius: 4px; margin-bottom: 10px;">';
            echo '<strong>🟢 一時ログ出力 有効中</strong> - ' . esc_html( $expires ) . ' まで';
            echo '</div>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin: 0;">';
            wp_nonce_field( 'andw_toggle_temp_logging' );
            echo '<input type="hidden" name="action" value="andw_toggle_temp_logging">';
            echo '<input type="hidden" name="state" value="disable">';
            echo '<input type="hidden" name="current_tab" value="viewer">';
            submit_button( __( '⏹️ 一時ログ出力を停止', 'andw-debug-viewer' ), 'secondary small', 'submit', false );
            echo '</form>';
        } elseif ( $debug_log_exists ) {
            // debug.log ファイル存在
            echo '<div style="background: #00a32a; color: white; padding: 8px 12px; border-radius: 4px;">';
            echo '<strong>✅ debug.log ファイル 利用可能</strong> - 既存のログファイルが見つかりました';
            echo '</div>';
        } elseif ( $wordpress_debug_log_enabled ) {
            // WP_DEBUG_LOG=true
            echo '<div style="background: #00a32a; color: white; padding: 8px 12px; border-radius: 4px;">';
            echo '<strong>✅ WordPress デバッグログ 有効</strong> - wp-config.php で WP_DEBUG_LOG が有効';
            echo '</div>';
        } else {
            // ログ機能無効
            echo '<div style="background: #f9f9f9; border: 1px solid #ddd;">';
            echo '<p style="margin: 0 0 8px; font-size: 14px;"><strong>⚠️ ログ機能が無効です</strong> - debug.log ファイルが存在せず、WP_DEBUG_LOG も無効になっています。</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin: 0;">';
            wp_nonce_field( 'andw_toggle_temp_logging' );
            echo '<input type="hidden" name="action" value="andw_toggle_temp_logging">';
            echo '<input type="hidden" name="state" value="enable">';
            echo '<input type="hidden" name="current_tab" value="viewer">';
            submit_button( __( '▶️ 15分間ログ出力を有効化', 'andw-debug-viewer' ), 'primary small', 'submit', false );
            echo '</form>';
            echo '</div>';
        }

        // テスト機能（常時表示）
        echo '<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">';
        echo '<p style="margin: 0 0 5px; font-size: 12px; color: #666;">テスト機能:</p>';
        echo '<div style="display: flex; gap: 5px; flex-wrap: wrap;">';

        // テスト用ログ出力
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin: 0; display: inline-block;">';
        wp_nonce_field( 'andw_test_log_output' );
        echo '<input type="hidden" name="action" value="andw_test_log_output">';
        echo '<input type="hidden" name="current_tab" value="viewer">';
        submit_button( __( '🧪 テスト用ログ出力', 'andw-debug-viewer' ), 'secondary small', 'submit', false, array( 'style' => 'margin: 0;' ) );
        echo '</form>';


        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Render compact production override controls for viewer tab.
     *
     * @param array $permissions Permissions context.
     * @return void
     */
    private function render_production_override_controls_compact( array $permissions ) {
        $override_active = ! empty( $permissions['override_active'] );
        $expires = ! empty( $permissions['override_expires'] ) ? wp_date( 'Y/m/d H:i', (int) $permissions['override_expires'] ) : '';

        echo '<div class="andw-production-override-compact" style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 10px; margin: 10px 0;">';
        echo '<details style="cursor: pointer;">';
        echo '<summary style="font-weight: bold; color: #856404;">🔒 WP_DEBUG=True 環境での危険な操作</summary>';
        echo '<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ffeaa7;">';

        if ( $override_active && $expires ) {
            echo '<p style="margin: 0 0 8px; color: #856404;">現在、一時許可が有効です（' . esc_html( $expires ) . ' まで）。</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin: 0;">';
            wp_nonce_field( 'andw_toggle_prod_override' );
            echo '<input type="hidden" name="action" value="andw_toggle_prod_override">';
            echo '<input type="hidden" name="state" value="disable">';
            echo '<input type="hidden" name="current_tab" value="viewer">';
            submit_button( __( '一時許可を解除', 'andw-debug-viewer' ), 'secondary small', 'submit', false );
            echo '</form>';
        } else {
            echo '<p style="margin: 0 0 8px; color: #856404; font-size: 13px;">WP_DEBUG=True の環境では、誤操作防止のため「ログをクリア」「ログをダウンロード」は既定で無効です。</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin: 0;">';
            wp_nonce_field( 'andw_toggle_prod_override' );
            echo '<input type="hidden" name="action" value="andw_toggle_prod_override">';
            echo '<input type="hidden" name="state" value="enable">';
            echo '<input type="hidden" name="current_tab" value="viewer">';
            submit_button( __( '⚠️ 15分間危険な操作を許可', 'andw-debug-viewer' ), 'secondary small', 'submit', false );
            echo '</form>';
        }

        echo '</div>';
        echo '</details>';
        echo '</div>';
    }

    /**
     * Handle production override toggle.
     *
     * @return void
     */
    public function handle_toggle_production_override() {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_network_options' ) ) {
            wp_die( esc_html__( 'この操作を実行する権限がありません。', 'andw-debug-viewer' ) );
        }

        check_admin_referer( 'andw_toggle_prod_override' );

        $state = isset( $_POST['state'] ) ? sanitize_key( $_POST['state'] ) : 'enable';
        $settings_handler = $this->plugin->get_settings_handler();

        // WP_DEBUG_LOG環境を確認してセッションレスかセッションベースかを判定
        $wp_debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

        if ( 'disable' === $state ) {
            if ( $wp_debug_log_enabled ) {
                // WP_DEBUG_LOG=true: wp_optionsベースで無効化
                $result = $settings_handler->disable_debug_log_override();
            } else {
                // WP_DEBUG_LOG=false: 従来のセッションベース
                $settings = $settings_handler->get_settings();
                $settings['production_temp_expiration'] = 0;
                $result = update_option( Andw_Settings::OPTION_NAME, $settings, false );
            }
            $message = 'prod_disabled';
        } else {
            if ( $wp_debug_log_enabled ) {
                // WP_DEBUG_LOG=true: wp_optionsベースで有効化（15分間）
                $result = $settings_handler->enable_debug_log_override();
            } else {
                // WP_DEBUG_LOG=false: 従来のセッションベース
                $timestamp = current_time( 'timestamp' ) + ( 15 * MINUTE_IN_SECONDS );
                $result = $settings_handler->set_production_override_expiration( $timestamp );
            }
            $message = 'prod_enabled';
        }

        // リダイレクト先を元のタブに戻す
        $current_tab = 'viewer';  // デフォルトはビューアータブ
        if ( isset( $_POST['current_tab'] ) ) {
            $current_tab = sanitize_key( $_POST['current_tab'] );
        } else {
            $referer = wp_get_referer();
            if ( $referer && strpos( $referer, 'tab=settings' ) !== false ) {
                $current_tab = 'settings';
            }
        }

        $redirect = add_query_arg(
            array(
                'page'            => 'andw-debug-viewer',
                'tab'             => $current_tab,
                'andw_message' => $message,
            ),
            is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Display notice if message query present.
     *
     * @return void
     */
    private function maybe_render_notice() {
        $override_message = isset( $_GET['override_message'] ) ? sanitize_key( $_GET['override_message'] ) : '';
        $temp_logging_message = isset( $_GET['temp_logging_message'] ) ? sanitize_key( $_GET['temp_logging_message'] ) : '';
        $legacy_message = isset( $_GET['andw_message'] ) ? sanitize_key( $_GET['andw_message'] ) : '';

        $messages = array(
            'prod_enabled'         => __( 'WP_DEBUG=false 環境で15分間の一時許可を有効化しました。', 'andw-debug-viewer' ),
            'prod_disabled'        => __( 'WP_DEBUG=false 環境での一時許可を解除しました。', 'andw-debug-viewer' ),
            'override_enabled'     => __( 'WP_DEBUG=false 環境での一時許可を有効にしました。', 'andw-debug-viewer' ),
            'override_disabled'    => __( 'WP_DEBUG=false 環境での一時許可を解除しました。', 'andw-debug-viewer' ),
            'override_error'       => __( '操作に失敗しました。', 'andw-debug-viewer' ),
            'temp_logging_enabled' => __( '一時ログ出力を有効にしました（15分間）。', 'andw-debug-viewer' ),
            'temp_logging_disabled'=> __( '一時ログ出力を無効にしました。', 'andw-debug-viewer' ),
            'temp_logging_error'   => __( 'ログ出力設定の変更に失敗しました。', 'andw-debug-viewer' ),
            'test_log_success'     => __( 'テスト用ログメッセージを出力しました。ログビューアーで確認してください。', 'andw-debug-viewer' ),
        );

        $message_key = $override_message ?: $temp_logging_message ?: $legacy_message;

        if ( ! empty( $message_key ) && isset( $messages[ $message_key ] ) ) {
            printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $message_key ] ) );
        }
    }

    /**
     * Prepare data for JavaScript localisation.
     *
     * @param array $permissions Permissions array.
     * @param bool  $is_network Whether network context.
     * @return array
     */
    private function prepare_localized_data( array $permissions, $is_network ) {
        $settings = $this->plugin->get_settings();
        $stats    = $this->plugin->get_log_reader()->get_stats();

        return array(
            'restUrl'   => 'andw-debug-viewer/v1/',
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'settings'  => array(
                'defaultLines'    => (int) $settings['default_lines'],
                'defaultMinutes'  => (int) $settings['default_minutes'],
                'maxLines'        => (int) $settings['max_lines'],
                'autoRefresh'     => (int) $settings['auto_refresh_interval'],
                'downloadEnabled' => (bool) $settings['enable_download'],
            ),
            'permissions' => $permissions,
            'stats'       => $stats,
            'environment' => array(
                'label' => ! empty( $permissions['wp_debug_enabled'] ) ? 'DEBUG MODE' : 'PRODUCTION',
                'slug'  => ! empty( $permissions['wp_debug_enabled'] ) ? 'debug' : 'production',
                'wp_debug_enabled' => ! empty( $permissions['wp_debug_enabled'] ),
                'is_temp_environment' => ! empty( $permissions['temp_logging_active'] ),
            ),
            'isNetwork' => (bool) $is_network,
            'strings'   => array(
                'refresh'       => __( '再読み込み', 'andw-debug-viewer' ),
                'pause'         => __( '一時停止', 'andw-debug-viewer' ),
                'resume'        => __( '再開', 'andw-debug-viewer' ),
                'cleared'       => __( 'ログをクリアしました。', 'andw-debug-viewer' ),
                'clearConfirm'  => __( '本当に debug.log をクリアしますか？', 'andw-debug-viewer' ),
                'downloadError' => __( 'ダウンロードに失敗しました。', 'andw-debug-viewer' ),
                'noData'        => __( '表示できるログがありません。', 'andw-debug-viewer' ),
                'fallbackNote'  => __( 'タイムスタンプが見つからなかったため行数モードで表示しています。', 'andw-debug-viewer' ),
                'statsLabel'    => __( 'ファイル情報', 'andw-debug-viewer' ),
                'sizeLabel'     => __( 'サイズ', 'andw-debug-viewer' ),
                'updatedLabel'  => __( '最終更新', 'andw-debug-viewer' ),
                'missingLog'    => __( 'debug.log が見つかりません。', 'andw-debug-viewer' ),
                'paused'        => __( '自動更新を一時停止しました。', 'andw-debug-viewer' ),
                'resumed'       => __( '自動更新を再開しました。', 'andw-debug-viewer' ),
            ),
        );
    }

    /**
     * Handle temporary logging toggle.
     *
     * @return void
     */
    public function handle_temp_logging_toggle() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'この操作を実行する権限がありません。', 'andw-debug-viewer' ) );
        }

        if ( ! isset( $_POST['_wpnonce'] ) ) {
            wp_die( esc_html__( 'ナンスが見つかりません。', 'andw-debug-viewer' ) );
        }

        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'andw_toggle_temp_logging' ) ) {
            wp_die( esc_html__( '無効なリクエストです。', 'andw-debug-viewer' ) );
        }

        $state = isset( $_POST['state'] ) ? sanitize_key( $_POST['state'] ) : '';

        if ( empty( $state ) ) {
            $message = 'temp_logging_error';
        } else {
            $settings = $this->plugin->get_settings_handler();

            if ( 'enable' === $state ) {
                $success = $settings->enable_temp_logging();
                $message = $success ? 'temp_logging_enabled' : 'temp_logging_error';
            } else {
                $success = $settings->disable_temp_logging();
                $message = $success ? 'temp_logging_disabled' : 'temp_logging_error';
            }
        }

        // リダイレクト先を元のタブに戻す（リファラーから判定）
        $current_tab = 'viewer';  // デフォルトはビューアータブ
        if ( isset( $_POST['current_tab'] ) ) {
            $current_tab = sanitize_key( $_POST['current_tab'] );
        } else {
            $referer = wp_get_referer();
            if ( $referer && strpos( $referer, 'tab=settings' ) !== false ) {
                $current_tab = 'settings';
            }
        }

        $redirect_url = add_query_arg(
            array(
                'page' => 'andw-debug-viewer',
                'tab'  => $current_tab,
                'temp_logging_message' => $message,
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handle test log output.
     *
     * @return void
     */
    public function handle_test_log_output() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'この操作を実行する権限がありません。', 'andw-debug-viewer' ) );
        }

        if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'andw_test_log_output' ) ) {
            wp_die( esc_html__( '無効なリクエストです。', 'andw-debug-viewer' ) );
        }

        // デバッグ情報収集
        $environment = wp_get_environment_type();
        $settings_handler = $this->plugin->get_settings_handler();
        $settings = $settings_handler->get_settings();
        $temp_logging_active = $settings_handler->is_temp_logging_active();
        $current_time = current_time( 'timestamp' );
        $log_file = WP_CONTENT_DIR . '/debug.log';
        $log_errors = ini_get( 'log_errors' );
        $error_log_setting = ini_get( 'error_log' );

        // ログ設定（ini_set削除済み）

        $timestamp = wp_date( 'Y-m-d H:i:s' );

        // WP_DEBUG関連設定を確認
        $wp_debug = defined( 'WP_DEBUG' ) ? ( WP_DEBUG ? 'true' : 'false' ) : 'undefined';
        $wp_debug_log = defined( 'WP_DEBUG_LOG' ) ? ( WP_DEBUG_LOG ? 'true' : 'false' ) : 'undefined';

        // debug.logファイルの存在確認
        $log_file_exists = file_exists( $log_file ) ? 'YES' : 'NO';
        $log_file_writable = wp_is_writable( dirname( $log_file ) ) ? 'YES' : 'NO';
        $log_file_size = file_exists( $log_file ) ? filesize( $log_file ) : 0;

        // PHPの設定詳細
        $display_errors = ini_get( 'display_errors' );

        $debug_info = "[$timestamp] andW Debug Viewer: 完全デバッグ情報";
        $debug_info .= " | 環境: $environment";
        $debug_info .= " | WP_DEBUG: $wp_debug";
        $debug_info .= " | WP_DEBUG_LOG: $wp_debug_log";
        $debug_info .= " | 一時ログ有効: " . ( $temp_logging_active ? 'YES' : 'NO' );
        $debug_info .= " | 現在時刻: $current_time";
        $debug_info .= " | 有効化フラグ: " . ( ! empty( $settings['temp_logging_enabled'] ) ? 'YES' : 'NO' );
        $debug_info .= " | 期限: " . ( ! empty( $settings['temp_logging_expiration'] ) ? $settings['temp_logging_expiration'] : 'なし' );
        $debug_info .= " | 期限チェック: " . ( ! empty( $settings['temp_logging_expiration'] ) && $settings['temp_logging_expiration'] > $current_time ? 'OK' : 'NG' );
        $debug_info .= " | log_errors: $log_errors → " . ini_get( 'log_errors' );
        $debug_info .= " | error_log: '$error_log_setting' → '" . ini_get( 'error_log' ) . "'";
        $debug_info .= " | display_errors: $display_errors";
        $debug_info .= " | error_reporting: $error_reporting_level";
        $debug_info .= " | debug.log存在: $log_file_exists";
        $debug_info .= " | 書き込み可能: $log_file_writable";
        $debug_info .= " | ファイルサイズ: $log_file_size bytes";

        // ログ機能の実際の状況判定
        $logging_analysis = "[$timestamp] andW Debug Viewer: ログ機能分析";

        // 実際にログが出力できるかテスト
        $can_log = false;
        if ( ini_get( 'log_errors' ) ) {
            if ( ini_get( 'error_log' ) || file_exists( $log_file ) ) {
                $can_log = true;
                $logging_analysis .= " | 結論: PHPログ機能は有効です（ini_set可能またはファイル存在）";
            }
        }

        if ( ! $can_log && $wp_debug === 'true' && $wp_debug_log === 'true' ) {
            $can_log = true;
            $logging_analysis .= " | 結論: WordPressデバッグログが有効です";
        }

        if ( $can_log ) {
            $logging_analysis .= " | 一時有効化: 不要（既に動作中）";
            $logging_analysis .= " | 推奨: 設定の確認と修正";
        } else {
            $logging_analysis .= " | 結論: ログ機能が完全に無効状態です";
            $logging_analysis .= " | 一時有効化: 必要";
        }

        $test_messages = array(
            $debug_info,
            $logging_analysis,
            "[$timestamp] andW Debug Viewer: テストログメッセージ - INFO レベル",
            "[$timestamp] andW Debug Viewer: テストエラーメッセージ - ERROR レベル",
            "[$timestamp] andW Debug Viewer: テスト警告メッセージ - WARNING レベル"
        );

        foreach ( $test_messages as $message ) {
            // ファイルに直接書き込みも試行
            if ( wp_is_writable( dirname( $log_file ) ) ) {
                file_put_contents( $log_file, $message . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
        }

        // リダイレクト先を元のタブに戻す
        $current_tab = 'viewer';  // デフォルトはビューアータブ
        if ( isset( $_POST['current_tab'] ) ) {
            $current_tab = sanitize_key( $_POST['current_tab'] );
        } else {
            $referer = wp_get_referer();
            if ( $referer && strpos( $referer, 'tab=settings' ) !== false ) {
                $current_tab = 'settings';
            }
        }

        $redirect_url = add_query_arg(
            array(
                'page' => 'andw-debug-viewer',
                'tab'  => $current_tab,
                'temp_logging_message' => 'test_log_success',
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Ajax handler for temporary logging toggle.
     *
     * @return void
     */
    public function ajax_toggle_temp_logging() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( json_encode( array( 'success' => false, 'message' => 'Permission denied' ) ) );
        }

        check_ajax_referer( 'andw_ajax_nonce', 'nonce' );

        $state = isset( $_POST['state'] ) ? sanitize_key( $_POST['state'] ) : '';
        $settings = $this->plugin->get_settings_handler();

        if ( 'enable' === $state ) {
            $success = $settings->enable_temp_logging();
            $message = $success ? 'temp_logging_enabled' : 'temp_logging_error';
        } else {
            $success = $settings->disable_temp_logging();
            $message = $success ? 'temp_logging_disabled' : 'temp_logging_error';
        }

        wp_die( json_encode( array( 'success' => $success, 'message' => $message ) ) );
    }

}
