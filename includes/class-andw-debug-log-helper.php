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
     * バックアップオプションキー
     */
    const BACKUP_OPTION_KEY = 'andw_wp_config_debug_backup';

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
     * wp-config.phpのバックアップを作成
     *
     * @return array
     */
    public static function create_wp_config_backup() {
        $wp_config_path = self::get_wp_config_path();
        if ( ! $wp_config_path || ! file_exists( $wp_config_path ) ) {
            return array(
                'success' => false,
                'message' => __( 'wp-config.phpが見つかりません。', 'andw-debug-viewer' ),
            );
        }

        $content = file_get_contents( $wp_config_path );
        if ( $content === false ) {
            return array(
                'success' => false,
                'message' => __( 'wp-config.phpの読み込みに失敗しました。', 'andw-debug-viewer' ),
            );
        }

        $backup_data = array(
            'before_content' => $content,
            'before_hash' => hash( 'sha256', $content ),
            'timestamp' => time(),
            'wp_debug_log_was_false' => ! ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ),
        );

        update_option( self::BACKUP_OPTION_KEY, $backup_data );

        return array(
            'success' => true,
            'message' => __( 'wp-config.phpのバックアップを作成しました。', 'andw-debug-viewer' ),
        );
    }

    /**
     * wp-config.php保存後の処理
     *
     * @return array
     */
    public static function handle_wp_config_saved() {
        $backup_data = get_option( self::BACKUP_OPTION_KEY );
        if ( ! $backup_data ) {
            // バックアップがない場合は通常のログ出力のみ
            return self::check_and_output_log();
        }

        $wp_config_path = self::get_wp_config_path();
        if ( ! $wp_config_path || ! file_exists( $wp_config_path ) ) {
            return array(
                'success' => false,
                'message' => __( 'wp-config.phpが見つかりません。', 'andw-debug-viewer' ),
            );
        }

        $current_content = file_get_contents( $wp_config_path );
        if ( $current_content === false ) {
            return array(
                'success' => false,
                'message' => __( 'wp-config.phpの読み込みに失敗しました。', 'andw-debug-viewer' ),
            );
        }

        // 変更後のハッシュを保存
        $backup_data['after_content'] = $current_content;
        $backup_data['after_hash'] = hash( 'sha256', $current_content );
        update_option( self::BACKUP_OPTION_KEY, $backup_data );

        // ログ出力
        error_log( 'wp_debug_probe - ' . date( 'Y-m-d H:i:s' ) . ' - wp-config.php saved' );

        return array(
            'success' => true,
            'message' => __( 'wp-config.phpを保存し、テストログを出力しました。', 'andw-debug-viewer' ),
            'log_path' => self::get_log_path(),
            'can_restore' => $backup_data['wp_debug_log_was_false'],
        );
    }

    /**
     * 元の状態に復元
     *
     * @return array
     */
    public static function restore_wp_config() {
        $backup_data = get_option( self::BACKUP_OPTION_KEY );
        if ( ! $backup_data ) {
            return array(
                'success' => false,
                'message' => __( '復元するバックアップが見つかりません。', 'andw-debug-viewer' ),
            );
        }

        if ( ! $backup_data['wp_debug_log_was_false'] ) {
            return array(
                'success' => false,
                'message' => __( '元々WP_DEBUG_LOGが有効だったため復元不要です。', 'andw-debug-viewer' ),
            );
        }

        $wp_config_path = self::get_wp_config_path();
        if ( ! $wp_config_path || ! file_exists( $wp_config_path ) ) {
            return array(
                'success' => false,
                'message' => __( 'wp-config.phpが見つかりません。', 'andw-debug-viewer' ),
            );
        }

        $current_content = file_get_contents( $wp_config_path );
        if ( $current_content === false ) {
            return array(
                'success' => false,
                'message' => __( 'wp-config.phpの読み込みに失敗しました。', 'andw-debug-viewer' ),
            );
        }

        $current_hash = hash( 'sha256', $current_content );

        // 他者による変更チェック
        if ( $current_hash !== $backup_data['after_hash'] ) {
            return array(
                'success' => false,
                'message' => __( 'wp-config.phpが他で変更されているため復元できません。', 'andw-debug-viewer' ),
                'conflict' => true,
            );
        }

        // 元の状態に復元
        $write_result = file_put_contents( $wp_config_path, $backup_data['before_content'] );
        if ( $write_result === false ) {
            return array(
                'success' => false,
                'message' => __( 'wp-config.phpの書き込みに失敗しました。', 'andw-debug-viewer' ),
            );
        }

        // バックアップデータを削除
        delete_option( self::BACKUP_OPTION_KEY );

        return array(
            'success' => true,
            'message' => __( 'wp-config.phpを元の状態に復元しました。', 'andw-debug-viewer' ),
        );
    }

    /**
     * 復元可能かチェック
     *
     * @return array
     */
    public static function can_restore() {
        $backup_data = get_option( self::BACKUP_OPTION_KEY );
        if ( ! $backup_data || ! isset( $backup_data['wp_debug_log_was_false'] ) ) {
            return array(
                'can_restore' => false,
                'reason' => __( '復元するバックアップがありません。', 'andw-debug-viewer' ),
            );
        }

        if ( ! $backup_data['wp_debug_log_was_false'] ) {
            return array(
                'can_restore' => false,
                'reason' => __( '元々WP_DEBUG_LOGが有効だったため復元不要です。', 'andw-debug-viewer' ),
            );
        }

        return array(
            'can_restore' => true,
            'reason' => __( '復元可能です。', 'andw-debug-viewer' ),
        );
    }

    /**
     * wp-config.phpのパスを取得
     *
     * @return string|false
     */
    private static function get_wp_config_path() {
        $config_path = ABSPATH . 'wp-config.php';
        if ( file_exists( $config_path ) ) {
            return $config_path;
        }

        $parent_config_path = dirname( ABSPATH ) . '/wp-config.php';
        if ( file_exists( $parent_config_path ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
            return $parent_config_path;
        }

        return false;
    }
}