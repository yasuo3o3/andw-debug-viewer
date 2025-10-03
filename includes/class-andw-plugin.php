<?php
/**
 * Core plugin bootstrap.
 *
 * @package andw-debug-viewer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once ANDW_PLUGIN_DIR . 'includes/class-andw-settings.php';
require_once ANDW_PLUGIN_DIR . 'includes/class-andw-log-reader.php';
require_once ANDW_PLUGIN_DIR . 'includes/class-andw-admin.php';
require_once ANDW_PLUGIN_DIR . 'includes/class-andw-rest-controller.php';

/**
 * Class Andw_Plugin
 */
class Andw_Plugin {
    /**
     * Singleton instance.
     *
     * @var Andw_Plugin|null
     */
    private static $instance = null;

    /**
     * Settings handler.
     *
     * @var Andw_Settings
     */
    private $settings;

    /**
     * Log reader.
     *
     * @var Andw_Log_Reader
     */
    private $log_reader;

    /**
     * Admin handler.
     *
     * @var Andw_Admin
     */
    private $admin;

    /**
     * REST controller.
     *
     * @var Andw_Rest_Controller
     */
    private $rest_controller;

    /**
     * Get singleton instance.
     *
     * @return Andw_Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->settings        = new Andw_Settings();
        $this->log_reader      = new Andw_Log_Reader();
        $this->rest_controller = new Andw_Rest_Controller( $this );
        $this->admin           = new Andw_Admin( $this );
    }

    /**
     * Initialise plugin hooks.
     *
     * @return void
     */
    public function init() {
        error_log( 'andW Debug Viewer: Plugin init() called' );

        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );

        error_log( 'andW Debug Viewer: Plugin hooks registered' );
        add_action( 'admin_menu', array( $this->admin, 'register_menu' ) );
        add_action( 'network_admin_menu', array( $this->admin, 'register_network_menu' ) );
        add_action( 'admin_init', array( $this->admin, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_assets' ) );
        add_action( 'init', array( $this, 'maybe_apply_temp_logging' ) );
        add_action( 'admin_init', array( $this, 'maybe_apply_temp_logging' ) );
        add_action( 'wp_loaded', array( $this, 'maybe_apply_temp_logging' ) );

        // WP_DEBUG_LOG=true時の自動セッション作成
        add_action( 'init', array( $this, 'ensure_wordpress_debug_session' ), 5 );
    }

    /**
     * Retrieve settings handler.
     *
     * @return Andw_Settings
     */
    public function get_settings_handler() {
        return $this->settings;
    }

    /**
     * Retrieve log reader.
     *
     * @return Andw_Log_Reader
     */
    public function get_log_reader() {
        return $this->log_reader;
    }

    /**
     * Apply temporary logging settings if active.
     *
     * @return void
     */
    public function maybe_apply_temp_logging() {
        if ( $this->settings->is_temp_logging_active() ) {
            // より確実なログ設定
            $log_file = WP_CONTENT_DIR . '/debug.log';

            // PHPのエラーログ設定
            ini_set( 'log_errors', '1' );
            ini_set( 'error_log', $log_file );
            error_reporting( E_ALL );

            // WordPressの定数も動的に設定（可能な場合）
            if ( ! defined( 'WP_DEBUG_LOG' ) ) {
                define( 'WP_DEBUG_LOG', true );
            }

            // カスタムエラーハンドラーを設定
            if ( ! function_exists( 'andw_custom_error_handler' ) ) {
                function andw_custom_error_handler( $errno, $errstr, $errfile, $errline ) {
                    $log_file = WP_CONTENT_DIR . '/debug.log';
                    $timestamp = wp_date( 'Y-m-d H:i:s' );
                    $message = "[$timestamp] PHP Error: $errstr in $errfile on line $errline";
                    error_log( $message );
                    // ファイルに直接書き込みも試行
                    if ( is_writable( dirname( $log_file ) ) ) {
                        file_put_contents( $log_file, $message . PHP_EOL, FILE_APPEND | LOCK_EX );
                    }
                    return false; // PHPの標準エラーハンドラーも実行
                }
                set_error_handler( 'andw_custom_error_handler', E_ALL );
            }

            error_log( 'andW Debug Viewer: Temporary logging activated successfully' );
        }
    }

    /**
     * WP_DEBUG_LOG=true時の自動セッション作成処理
     *
     * @return void
     */
    public function ensure_wordpress_debug_session() {
        // WP_DEBUG_LOG=true時のみ実行
        $wp_debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;
        $wp_debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

        if ( ! $wp_debug_enabled || ! $wp_debug_log_enabled ) {
            return;
        }

        // 無限ループ防止：セッションファイルが既に存在し有効な場合は何もしない
        $session_file = WP_CONTENT_DIR . '/andw-session.json';
        if ( file_exists( $session_file ) ) {
            $session_content = @file_get_contents( $session_file );
            if ( $session_content ) {
                $session_data = @json_decode( $session_content, true );
                if ( $session_data && isset( $session_data['expires_at'] ) ) {
                    $expires_at = (int) $session_data['expires_at'];
                    $current_time = time();

                    // まだ有効期限内なら何もしない
                    if ( $expires_at > $current_time ) {
                        return;
                    }

                    // 期限切れの場合のみ新規作成（ログ出力は最小限）
                    $this->create_wordpress_debug_session_silent();
                    return;
                }
            }
        }

        // セッションファイルが存在しない場合のみ新規作成
        $this->create_wordpress_debug_session_silent();
    }

    /**
     * ログ出力を抑制したWordPress debug セッション作成
     *
     * @return void
     */
    private function create_wordpress_debug_session_silent() {
        // セッションデータを直接作成（ログ出力なし）
        $session_data = array(
            'created_at'   => time(),
            'expires_at'   => time() + ( 15 * MINUTE_IN_SECONDS ),
            'session_type' => 'wordpress_debug',
            'permissions'  => array(
                'safe_to_clear'     => false,
                'safe_to_download'  => true,
                'created_by_plugin' => false,
            ),
        );

        $session_file = WP_CONTENT_DIR . '/andw-session.json';
        @file_put_contents( $session_file, json_encode( $session_data, JSON_PRETTY_PRINT ), LOCK_EX );
    }

    /**
     * Retrieve aggregated settings array.
     *
     * @return array
     */
    public function get_settings() {
        return $this->settings->get_settings();
    }

    /**
     * Load plugin textdomain.
     *
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'andw-debug-viewer', false, dirname( plugin_basename( ANDW_PLUGIN_FILE ) ) . '/languages/' );
    }

    /**
     * Determine permissions for current user/context.
     *
     * @param bool $network_context Whether this is a network admin context.
     * @return array
     */
    public function get_permissions( $network_context = false ) {
        $settings      = $this->get_settings();
        $environment   = wp_get_environment_type();

        // WP_DEBUG ベースでの安全性判定（より合理的）
        $wp_debug_enabled = ( defined( 'WP_DEBUG' ) && WP_DEBUG );
        $is_debug_mode = $wp_debug_enabled;
        $is_production_mode = ! $is_debug_mode;  // WP_DEBUG=false を本番モードとして扱う

        $override_active = $this->settings->is_production_override_active();
        $temp_logging_active = $this->settings->is_temp_logging_active();
        $temp_session_active = $this->settings->is_temp_session_active();
        $allow_mutation  = $is_debug_mode || $override_active || $temp_logging_active || $temp_session_active;

        // デバッグ出力（無限ログ防止のため、条件付きで出力）
        static $debug_logged = false;
        if ( ! $debug_logged && ( current_user_can( 'manage_options' ) || wp_doing_ajax() ) ) {
            error_log( 'andW Debug Viewer: get_permissions() - wp_debug_enabled: ' . ( $wp_debug_enabled ? 'true' : 'false' ) );
            error_log( 'andW Debug Viewer: get_permissions() - allow_mutation: ' . ( $allow_mutation ? 'true' : 'false' ) );
            $debug_logged = true;
        }

        $can_clear    = $allow_mutation;
        $can_download = $allow_mutation && ! empty( $settings['enable_download'] );

        // セッションファイルからの権限情報も考慮
        $session = $this->settings->get_active_session();
        if ( $session && isset( $session['permissions'] ) ) {
            $session_safe_to_download = ! empty( $session['permissions']['safe_to_download'] );
            if ( $session_safe_to_download && $allow_mutation ) {
                $can_download = true; // セッションでダウンロード許可されている場合は強制的に有効
            }
        }

        $download_globally_disabled = empty( $settings['enable_download'] );
        $reasons = array(
            'clear'    => '',
            'download' => '',
        );

        // 本番モード時の追加制限（$allow_mutationで既に判定済みなので、このブロックは不要になりました）
        // WP_DEBUG=false環境でも、temp_logging_activeやtemp_session_activeがあれば$allow_mutationでtrueになる

        if ( $download_globally_disabled ) {
            $can_download        = false;
            $reasons['download'] = __( '設定でダウンロード機能が無効化されています。', 'andw-debug-viewer' );
        }

        if ( is_multisite() && ! $network_context ) {
            if ( empty( $settings['allow_site_actions'] ) ) {
                $can_clear    = false;
                $can_download = false;
                $reasons['clear']    = __( 'ネットワーク管理者のみがログをクリアできます。', 'andw-debug-viewer' );
                $reasons['download'] = __( 'ネットワーク管理者のみがログをダウンロードできます。', 'andw-debug-viewer' );
            }
        }

        // 最終権限結果をログ出力（デバッグ時のみ）
        if ( ! $debug_logged && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'andW Debug Viewer: get_permissions() - final results logged once per session' );
        }

        return array(
            'environment'                => $environment,
            'wp_debug_enabled'           => $wp_debug_enabled,
            'is_debug_mode'              => $is_debug_mode,
            'is_production_mode'         => $is_production_mode,
            'override_active'            => $override_active,
            'override_expires'           => $override_active ? (int) $settings['production_temp_expiration'] : 0,
            'temp_logging_active'        => $temp_logging_active,
            'temp_logging_expires'       => $temp_logging_active ? (int) $settings['temp_logging_expiration'] : 0,
            'temp_session_active'        => $temp_session_active,
            'can_view'                   => true,
            'can_clear'                  => $can_clear,
            'can_download'               => $can_download,
            'reasons'                    => $reasons,
            'defaults'                   => array(
                'lines'    => (int) $settings['default_lines'],
                'minutes'  => (int) $settings['default_minutes'],
                'max_lines'=> (int) $settings['max_lines'],
            ),
            'auto_refresh_interval'      => (int) $settings['auto_refresh_interval'],
            'download_enabled_setting'   => (bool) $settings['enable_download'],
            'allow_site_actions_setting' => (bool) $settings['allow_site_actions'],
        );
    }
}
