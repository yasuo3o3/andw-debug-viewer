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
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
        add_action( 'admin_menu', array( $this->admin, 'register_menu' ) );
        add_action( 'network_admin_menu', array( $this->admin, 'register_network_menu' ) );
        add_action( 'admin_init', array( $this->admin, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_assets' ) );
        add_action( 'init', array( $this, 'maybe_apply_temp_logging' ) );
        add_action( 'admin_init', array( $this, 'maybe_apply_temp_logging' ) );
        add_action( 'wp_loaded', array( $this, 'maybe_apply_temp_logging' ) );
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
        $is_production = ( 'production' === $environment );
        $override_active = $this->settings->is_production_override_active();
        $temp_logging_active = $this->settings->is_temp_logging_active();
        $allow_mutation  = ! $is_production || $override_active;

        $can_clear    = $allow_mutation;
        $can_download = $allow_mutation && ! empty( $settings['enable_download'] );

        $download_globally_disabled = empty( $settings['enable_download'] );
        $reasons = array(
            'clear'    => '',
            'download' => '',
        );

        // 一時ログ有効時は別ファイルなので本番環境でも操作可能
        if ( $is_production && ! $override_active && ! $temp_logging_active ) {
            $can_clear    = false;
            $can_download = false;
            $reasons['clear']    = __( '本番環境では既定でクリアは無効です。設定から15分間の一時許可を発行できます。', 'andw-debug-viewer' );
            $reasons['download'] = __( '本番環境では既定でダウンロードは無効です。', 'andw-debug-viewer' );
        }

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

        return array(
            'environment'                => $environment,
            'is_production'              => $is_production,
            'override_active'            => $override_active,
            'override_expires'           => $override_active ? (int) $settings['production_temp_expiration'] : 0,
            'temp_logging_active'        => $temp_logging_active,
            'temp_logging_expires'       => $temp_logging_active ? (int) $settings['temp_logging_expiration'] : 0,
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
