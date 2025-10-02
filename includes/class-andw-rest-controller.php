<?php
/**
 * REST API controller.
 *
 * @package andw-debug-viewer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Andw_Rest_Controller
 */
class Andw_Rest_Controller extends WP_REST_Controller {
    const REST_NAMESPACE = 'andw-debug-viewer/v1';

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
    }

    /**
     * Register REST routes.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/tail',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_tail' ),
                'permission_callback' => array( $this, 'check_permissions' ),
                'args'                => $this->get_tail_args(),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/clear',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'post_clear' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/download',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_download' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/stats',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_stats' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/temp-logging',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'enable_temp_logging' ),
                    'permission_callback' => array( $this, 'check_permissions' ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'disable_temp_logging' ),
                    'permission_callback' => array( $this, 'check_permissions' ),
                ),
            )
        );
    }

    /**
     * Ensure current user has capability.
     *
     * @return bool
     */
    public function check_permissions() {
        return current_user_can( 'manage_options' ) || current_user_can( 'manage_network_options' );
    }

    /**
     * Retrieve tail arguments definition.
     *
     * @return array
     */
    private function get_tail_args() {
        return array(
            'mode'  => array(
                'type'        => 'string',
                'enum'        => array( 'lines', 'minutes' ),
                'default'     => 'lines',
                'description' => __( '表示モード（行数または分数）。', 'andw-debug-viewer' ),
            ),
            'value' => array(
                'type'        => 'integer',
                'default'     => null,
                'description' => __( 'モードに応じた行数または分数。', 'andw-debug-viewer' ),
            ),
        );
    }

    /**
     * Handle tail fetching.
     *
     * @param WP_REST_Request $request Request instance.
     * @return WP_REST_Response|WP_Error
     */
    public function get_tail( WP_REST_Request $request ) {
        $mode  = $request->get_param( 'mode' );
        $value = $request->get_param( 'value' );
        $settings = $this->plugin->get_settings();
        $max_lines = (int) $settings['max_lines'];

        if ( 'minutes' === $mode ) {
            $minutes = ( null !== $value ) ? (int) $value : (int) $settings['default_minutes'];
            if ( $minutes < 1 ) {
                $minutes = 1;
            }
            $result = $this->plugin->get_log_reader()->read_tail_by_minutes( $minutes, $max_lines );
        } else {
            $mode = 'lines';
            $lines = ( null !== $value ) ? (int) $value : (int) $settings['default_lines'];
            if ( $lines < 1 ) {
                $lines = 1;
            }
            if ( $lines > $max_lines ) {
                $lines = $max_lines;
            }
            $result = $this->plugin->get_log_reader()->read_tail_by_lines( $lines, $max_lines );
        }

        if ( is_wp_error( $result ) ) {
            return $this->prepare_error_response( $result );
        }

        return rest_ensure_response(
            array(
                'mode'        => $mode,
                'log'         => $result['content'],
                'meta'        => array(
                    'total_lines'   => isset( $result['total_lines'] ) ? (int) $result['total_lines'] : 0,
                    'scanned_lines' => isset( $result['scanned_lines'] ) ? (int) $result['scanned_lines'] : 0,
                    'fallback'      => ! empty( $result['fallback'] ),
                ),
                'stats'       => $this->plugin->get_log_reader()->get_stats(),
                'permissions' => $this->plugin->get_permissions( is_network_admin() ),
            )
        );
    }

    /**
     * Handle log clear.
     *
     * @param WP_REST_Request $request Request instance.
     * @return WP_REST_Response|WP_Error
     */
    public function post_clear( WP_REST_Request $request ) {
        $permissions = $this->plugin->get_permissions( is_network_admin() );
        if ( empty( $permissions['can_clear'] ) ) {
            return $this->prepare_error_response(
                new WP_Error( 'andw_forbidden', __( 'この環境ではログのクリアが許可されていません。', 'andw-debug-viewer' ), array( 'status' => 403 ) )
            );
        }

        $result = $this->plugin->get_log_reader()->clear_log();
        if ( is_wp_error( $result ) ) {
            return $this->prepare_error_response( $result );
        }

        return rest_ensure_response(
            array(
                'cleared'   => true,
                'timestamp' => current_time( 'mysql' ),
            )
        );
    }

    /**
     * Handle download.
     *
     * @param WP_REST_Request $request Request instance.
     * @return WP_REST_Response|WP_Error
     */
    public function get_download( WP_REST_Request $request ) {
        $permissions = $this->plugin->get_permissions( is_network_admin() );
        if ( empty( $permissions['can_download'] ) ) {
            return $this->prepare_error_response(
                new WP_Error( 'andw_download_forbidden', __( 'この環境ではダウンロードが許可されていません。', 'andw-debug-viewer' ), array( 'status' => 403 ) )
            );
        }

        $payload = $this->plugin->get_log_reader()->get_download_payload();
        if ( is_wp_error( $payload ) ) {
            return $this->prepare_error_response( $payload );
        }

        $response = new WP_REST_Response( $payload['content'] );
        $response->set_headers(
            array(
                'Content-Type'           => 'text/plain; charset=utf-8',
                'Content-Disposition'    => 'attachment; filename="' . sanitize_file_name( $payload['filename'] ) . '"',
                'Content-Length'         => (string) $payload['size'],
                'X-Content-Type-Options' => 'nosniff',
            )
        );

        return $response;
    }

    /**
     * Retrieve log stats.
     *
     * @param WP_REST_Request $request Request instance.
     * @return WP_REST_Response
     */
    public function get_stats( WP_REST_Request $request ) {
        return rest_ensure_response(
            array(
                'stats'       => $this->plugin->get_log_reader()->get_stats(),
                'permissions' => $this->plugin->get_permissions( is_network_admin() ),
            )
        );
    }

    /**
     * Wrap WP_Error to REST error.
     *
     * @param WP_Error $error Error instance.
     * @return WP_Error
     */
    private function prepare_error_response( WP_Error $error ) {
        $data = $error->get_error_data();
        if ( empty( $data ) || empty( $data['status'] ) ) {
            $error->add_data( array( 'status' => 500 ) );
        }

        return $error;
    }

    /**
     * Enable temporary logging (POST /temp-logging).
     *
     * @param WP_REST_Request $request Request instance.
     * @return WP_REST_Response
     */
    public function enable_temp_logging( WP_REST_Request $request ) {
        $settings = $this->plugin->get_settings_handler();
        $success = $settings->enable_temp_logging();

        if ( ! $success ) {
            return new WP_Error(
                'temp_logging_error',
                __( 'ログ出力設定の変更に失敗しました。', 'andw-debug-viewer' ),
                array( 'status' => 500 )
            );
        }

        return rest_ensure_response(
            array(
                'success'     => true,
                'message'     => __( '一時ログ出力を有効にしました（15分間）。', 'andw-debug-viewer' ),
                'permissions' => $this->plugin->get_permissions( is_network_admin() ),
            )
        );
    }

    /**
     * Disable temporary logging (DELETE /temp-logging).
     *
     * @param WP_REST_Request $request Request instance.
     * @return WP_REST_Response
     */
    public function disable_temp_logging( WP_REST_Request $request ) {
        $settings = $this->plugin->get_settings_handler();
        $success = $settings->disable_temp_logging();

        if ( ! $success ) {
            return new WP_Error(
                'temp_logging_error',
                __( 'ログ出力設定の変更に失敗しました。', 'andw-debug-viewer' ),
                array( 'status' => 500 )
            );
        }

        return rest_ensure_response(
            array(
                'success'     => true,
                'message'     => __( '一時ログ出力を無効にしました。', 'andw-debug-viewer' ),
                'permissions' => $this->plugin->get_permissions( is_network_admin() ),
            )
        );
    }
}
