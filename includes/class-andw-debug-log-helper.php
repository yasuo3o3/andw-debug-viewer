<?php
/**
 * WP_DEBUG_LOG管理ヘルパークラス
 *
 * @package andw-debug-viewer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Andw_Debug_Log_Helper
 */
class Andw_Debug_Log_Helper {

    /**
     * WP_DEBUG_LOGの状態を確認し、必要に応じてログ出力
     *
     * @return array
     */
    public static function check_and_output_log() {
        $wp_debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

        if ( $wp_debug_log_enabled ) {
            // WP_DEBUG_LOGが有効：一行ログ出力
            error_log( 'wp_debug_probe - ' . date( 'Y-m-d H:i:s' ) . ' - andW Debug Viewer test' );

            return array(
                'enabled' => true,
                'message' => __( 'WP_DEBUG_LOGが有効です。テストログを出力しました。', 'andw-debug-viewer' ),
                'log_path' => self::get_log_path(),
            );
        } else {
            // WP_DEBUG_LOGが無効：wp-configタブへ遷移指示
            return array(
                'enabled' => false,
                'message' => __( 'WP_DEBUG_LOGが無効です。wp-configタブで手動設定してください。', 'andw-debug-viewer' ),
                'redirect_to_config' => true,
            );
        }
    }

    /**
     * ログファイルのパスを取得
     *
     * @return string
     */
    public static function get_log_path() {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            if ( is_string( WP_DEBUG_LOG ) ) {
                return WP_DEBUG_LOG;
            } else {
                return WP_CONTENT_DIR . '/debug.log';
            }
        }
        return WP_CONTENT_DIR . '/debug.log';
    }

    /**
     * wp-config.php保存後の処理
     *
     * @return array
     */
    public static function handle_wp_config_saved() {
        // wp-config.php保存後のテストログ出力
        return self::check_and_output_log();
    }
}