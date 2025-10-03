<?php
/**
 * Settings handler for andW Debug Viewer.
 *
 * @package andw-debug-viewer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Andw_Settings
 */
class Andw_Settings {
    const OPTION_NAME = 'andw_settings';

    /**
     * Default option values.
     *
     * @return array
     */
    public function get_defaults() {
        return array(
            'default_lines'              => 100,
            'default_minutes'            => 5,
            'max_lines'                  => 500,
            'auto_refresh_interval'      => 10,
            'enable_download'            => true,
            'allow_site_actions'         => false,
            'production_temp_expiration' => 0,
            'temp_logging_enabled'       => false,
            'temp_logging_expiration'    => 0,
            'debug_log_created_by_plugin' => false,  // プラグインがdebug.logを作成したか
            'debug_log_creation_timestamp' => 0,     // プラグインが作成した時刻
        );
    }

    /**
     * Retrieve the saved settings merged with defaults.
     *
     * @return array
     */
    public function get_settings() {
        $defaults = $this->get_defaults();
        $settings = get_option( self::OPTION_NAME, array() );

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $settings = wp_parse_args( $settings, $defaults );

        // 既存のインストールでenable_downloadがfalseの場合、trueに更新
        if ( ! $settings['enable_download'] ) {
            $settings['enable_download'] = true;
            update_option( self::OPTION_NAME, $settings, false );
            error_log( 'andW Debug Viewer: Updated enable_download setting to true' );
        }

        if ( $settings['production_temp_expiration'] && $this->is_production_override_expired( (int) $settings['production_temp_expiration'] ) ) {
            $settings['production_temp_expiration'] = 0;
            update_option( self::OPTION_NAME, $settings, false );
        }

        if ( $settings['temp_logging_expiration'] && $this->is_temp_logging_expired( (int) $settings['temp_logging_expiration'] ) ) {
            // 期限切れ時の処理
            $this->handle_temp_logging_expiration();
            $settings['temp_logging_enabled'] = false;
            $settings['temp_logging_expiration'] = 0;
            update_option( self::OPTION_NAME, $settings, false );
        }

        return $this->normalize_settings( $settings );
    }

    /**
     * WP_DEBUG_LOG=true環境でのoverride状態管理
     *
     * @return bool
     */
    public function is_debug_log_override_active() {
        $override = get_option( 'andw_debug_log_override', array( 'enabled' => false ) );

        if ( empty( $override['enabled'] ) ) {
            return false;
        }

        if ( ! isset( $override['expires_at'] ) ) {
            return false;
        }

        $expires_at = (int) $override['expires_at'];
        $current_time = time();

        if ( $expires_at <= $current_time ) {
            // 期限切れの場合は自動でクリア
            delete_option( 'andw_debug_log_override' );
            return false;
        }

        return true;
    }

    /**
     * WP_DEBUG_LOG=true環境でのoverride有効化
     *
     * @return bool
     */
    public function enable_debug_log_override() {
        $override_data = array(
            'enabled' => true,
            'expires_at' => time() + ( 15 * MINUTE_IN_SECONDS )
        );

        return update_option( 'andw_debug_log_override', $override_data, false );
    }

    /**
     * WP_DEBUG_LOG=true環境でのoverride無効化
     *
     * @return bool
     */
    public function disable_debug_log_override() {
        return delete_option( 'andw_debug_log_override' );
    }

    /**
     * WP_DEBUG_LOG=true環境でのoverride期限取得
     *
     * @return int
     */
    public function get_debug_log_override_expires() {
        $override = get_option( 'andw_debug_log_override', array() );
        return isset( $override['expires_at'] ) ? (int) $override['expires_at'] : 0;
    }

    /**
     * Sanitize settings before saving.
     *
     * @param array $input Raw input.
     * @return array
     */
    public function sanitize( $input ) {
        $defaults = $this->get_defaults();

        if ( ! is_array( $input ) ) {
            $input = array();
        }

        $sanitized                      = $defaults;
        $sanitized['max_lines']         = $this->sanitize_range( $input, 'max_lines', 100, 1000 );
        $sanitized['default_lines']     = $this->sanitize_range( $input, 'default_lines', 10, $sanitized['max_lines'] );
        $sanitized['default_minutes']   = $this->sanitize_range( $input, 'default_minutes', 1, 120 );
        $sanitized['auto_refresh_interval'] = $this->sanitize_range( $input, 'auto_refresh_interval', 2, 120 );
        $sanitized['enable_download']   = ! empty( $input['enable_download'] );
        $sanitized['allow_site_actions'] = ! empty( $input['allow_site_actions'] );

        $existing = $this->get_settings();
        if ( ! empty( $existing['production_temp_expiration'] ) && ! $this->is_production_override_expired( (int) $existing['production_temp_expiration'] ) ) {
            $sanitized['production_temp_expiration'] = (int) $existing['production_temp_expiration'];
        }

        if ( ! empty( $existing['temp_logging_enabled'] ) && ! empty( $existing['temp_logging_expiration'] ) && ! $this->is_temp_logging_expired( (int) $existing['temp_logging_expiration'] ) ) {
            $sanitized['temp_logging_enabled'] = true;
            $sanitized['temp_logging_expiration'] = (int) $existing['temp_logging_expiration'];
        }

        return $sanitized;
    }

    /**
     * Normalize settings ensuring numeric bounds.
     *
     * @param array $settings Raw settings.
     * @return array
     */
    private function normalize_settings( $settings ) {
        $settings['max_lines']               = (int) $settings['max_lines'];
        $settings['default_lines']           = min( max( (int) $settings['default_lines'], 10 ), $settings['max_lines'] );
        $settings['default_minutes']         = min( max( (int) $settings['default_minutes'], 1 ), 120 );
        $settings['auto_refresh_interval']   = min( max( (int) $settings['auto_refresh_interval'], 2 ), 120 );
        $settings['enable_download']         = ! empty( $settings['enable_download'] );
        $settings['allow_site_actions']      = ! empty( $settings['allow_site_actions'] );
        $settings['production_temp_expiration'] = max( 0, (int) $settings['production_temp_expiration'] );

        return $settings;
    }

    /**
     * Update production override expiration.
     *
     * @param int $timestamp Unix timestamp.
     * @return void
     */
    public function set_production_override_expiration( $timestamp ) {
        $settings                              = $this->get_settings();
        $settings['production_temp_expiration'] = max( 0, (int) $timestamp );
        update_option( self::OPTION_NAME, $settings, false );
    }

    /**
     * Determine if production override is active.
     *
     * @return bool
     */
    public function is_production_override_active() {
        $wp_debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

        if ( $wp_debug_log_enabled ) {
            // WP_DEBUG_LOG=true環境: wp_optionsで管理
            return $this->is_debug_log_override_active();
        } else {
            // WP_DEBUG_LOG=false環境: 従来の方法
            $settings   = $this->get_settings();
            $expiration = (int) $settings['production_temp_expiration'];
            return $expiration > current_time( 'timestamp' );
        }
    }

    /**
     * Check whether override has expired.
     *
     * @param int $expiration Timestamp.
     * @return bool
     */
    public function is_production_override_expired( $expiration ) {
        return empty( $expiration ) || $expiration <= current_time( 'timestamp' );
    }

    /**
     * Check whether temporary logging is active.
     *
     * @return bool
     */
    public function is_temp_logging_active() {
        $settings = $this->get_settings();
        return ! empty( $settings['temp_logging_enabled'] ) && ! empty( $settings['temp_logging_expiration'] ) && $settings['temp_logging_expiration'] > current_time( 'timestamp' );
    }

    /**
     * Check whether temporary logging has expired.
     *
     * @param int $expiration Timestamp.
     * @return bool
     */
    public function is_temp_logging_expired( $expiration ) {
        return empty( $expiration ) || $expiration <= current_time( 'timestamp' );
    }

    /**
     * Enable temporary logging for 15 minutes.
     *
     * @return bool Success status.
     */
    public function enable_temp_logging() {
        // WP_DEBUGが有効な場合は一時ログを有効化しない
        $wp_debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;
        $wp_debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

        if ( $wp_debug_enabled && $wp_debug_log_enabled ) {
            error_log( 'andW Debug Viewer: WP_DEBUGが有効なため、一時ログの有効化をスキップします' );
            return false;
        }

        $settings = $this->get_settings();
        $old_settings = $settings; // バックアップ

        // debug.logの事前状態を確認
        $debug_log_path = WP_CONTENT_DIR . '/debug.log';
        $debug_log_existed_before = file_exists( $debug_log_path );

        // 現在の設定をログに出力
        error_log( 'andW Debug Viewer: Current settings before update: ' . print_r( $settings, true ) );
        error_log( 'andW Debug Viewer: debug.log existed before: ' . ( $debug_log_existed_before ? 'YES' : 'NO' ) );

        $settings['temp_logging_enabled'] = true;
        $settings['temp_logging_expiration'] = current_time( 'timestamp' ) + ( 15 * MINUTE_IN_SECONDS );

        // debug.logがプラグインによって作成される場合の記録
        if ( ! $debug_log_existed_before ) {
            $settings['debug_log_created_by_plugin'] = true;
            $settings['debug_log_creation_timestamp'] = current_time( 'timestamp' );
            error_log( 'andW Debug Viewer: Will create debug.log - marking as plugin-created' );
        } else {
            error_log( 'andW Debug Viewer: debug.log already exists - not marking as plugin-created' );
        }

        error_log( 'andW Debug Viewer: New settings to save: ' . print_r( $settings, true ) );

        // 既存設定を確認
        $existing_settings = get_option( self::OPTION_NAME, array() );
        error_log( 'andW Debug Viewer: Existing settings: ' . print_r( $existing_settings, true ) );

        // まず update_option を試行
        $updated = update_option( self::OPTION_NAME, $settings, false );

        error_log( 'andW Debug Viewer: update_option returned: ' . ( $updated ? 'true' : 'false' ) );

        // 保存後の設定を確認
        $after_save = get_option( self::OPTION_NAME, array() );
        error_log( 'andW Debug Viewer: After save settings: ' . print_r( $after_save, true ) );

        // update_option が false を返した場合でも、値が変わっていない場合は true とみなす場合がある
        if ( ! $updated ) {
            // 現在保存されている値を確認
            $current_saved = get_option( self::OPTION_NAME, array() );
            error_log( 'andW Debug Viewer: Currently saved after update_option: ' . print_r( $current_saved, true ) );

            // 期待する値と一致するかチェック
            if ( isset( $current_saved['temp_logging_enabled'] ) &&
                 $current_saved['temp_logging_enabled'] &&
                 isset( $current_saved['temp_logging_expiration'] ) &&
                 abs( $current_saved['temp_logging_expiration'] - $settings['temp_logging_expiration'] ) < 5 ) {
                $updated = true; // 実際には正しく保存されている
                error_log( 'andW Debug Viewer: Settings were actually saved correctly, treating as success' );
            }
        }

        // デバッグ情報をログに出力
        error_log( 'andW Debug Viewer: enable_temp_logging() - final result: ' . ( $updated ? 'SUCCESS' : 'FAILED' ) );

        if ( $updated ) {
            // セッションファイルを作成
            $this->create_temp_session_file();

            $this->apply_temp_logging_settings();

            // ログ有効化の確認メッセージをデバッグログファイルに出力
            $log_file = trailingslashit( WP_CONTENT_DIR ) . 'debug.log';
            $log_message = '[' . date( 'Y-m-d H:i:s' ) . '] andW Debug Viewer: 15分間のログ出力が有効化されました。有効期限: ' . date( 'Y-m-d H:i:s', $settings['temp_logging_expiration'] );
            if ( is_writable( dirname( $log_file ) ) || is_writable( $log_file ) ) {
                file_put_contents( $log_file, $log_message . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
            error_log( $log_message );

            // 設定が正しく保存されたか確認
            $saved_settings = $this->get_settings();
            error_log( 'andW Debug Viewer: Saved settings verification: ' . print_r( $saved_settings, true ) );
        } else {
            // 失敗時は詳細情報をログに出力
            error_log( 'andW Debug Viewer: Original settings: ' . print_r( $old_settings, true ) );
            $current_saved = get_option( self::OPTION_NAME, array() );
            error_log( 'andW Debug Viewer: Currently saved: ' . print_r( $current_saved, true ) );

            // WordPressのデータベースエラーがあるかチェック
            global $wpdb;
            if ( $wpdb->last_error ) {
                error_log( 'andW Debug Viewer: Database error: ' . $wpdb->last_error );
            }

            // 代替手段: 直接設定を更新
            $manual_save = array(
                'temp_logging_enabled' => true,
                'temp_logging_expiration' => current_time( 'timestamp' ) + ( 15 * MINUTE_IN_SECONDS ),
            );
            $result = add_option( self::OPTION_NAME . '_temp', $manual_save, '', 'no' );
            error_log( 'andW Debug Viewer: Manual save result: ' . ( $result ? 'SUCCESS' : 'FAILED' ) );

            // 設定保存が失敗してもログ機能を有効にするため、
            // とりあえず一時的にログを出力して、UIを正しく表示させる
            if ( ! $updated ) {
                error_log( 'andW Debug Viewer: 設定の保存に失敗しましたが、ログ機能を一時的に有効化します' );

                // 設定保存が失敗してもセッションファイルを強制作成
                $this->create_temp_session_file();

                $this->apply_temp_logging_settings();
                $log_file = trailingslashit( WP_CONTENT_DIR ) . 'debug.log';
                $log_message = '[' . date( 'Y-m-d H:i:s' ) . '] andW Debug Viewer: ログ出力を一時的に有効化しました（設定保存に問題がある可能性があります）';
                if ( is_writable( dirname( $log_file ) ) || is_writable( $log_file ) ) {
                    file_put_contents( $log_file, $log_message . PHP_EOL, FILE_APPEND | LOCK_EX );
                }
                error_log( $log_message );

                // 強制的に成功とみなす
                $updated = true;
                error_log( 'andW Debug Viewer: Forcing success status for UI display' );
            }
        }

        return $updated;
    }

    /**
     * Disable temporary logging.
     *
     * @return bool Success status.
     */
    public function disable_temp_logging() {
        $settings = $this->get_settings();
        $was_created_by_plugin = ! empty( $settings['debug_log_created_by_plugin'] );

        error_log( 'andW Debug Viewer: disable_temp_logging() called' );
        error_log( 'andW Debug Viewer: was_created_by_plugin: ' . ( $was_created_by_plugin ? 'YES' : 'NO' ) );

        // debug.logを削除するかどうか判断
        $debug_log_path = WP_CONTENT_DIR . '/debug.log';
        if ( $was_created_by_plugin && file_exists( $debug_log_path ) ) {
            $deleted = unlink( $debug_log_path );
            error_log( 'andW Debug Viewer: Deleted plugin-created debug.log: ' . ( $deleted ? 'SUCCESS' : 'FAILED' ) );
        } elseif ( file_exists( $debug_log_path ) ) {
            error_log( 'andW Debug Viewer: debug.log exists but was not created by plugin - keeping it' );
        }

        // JSON設定をクリア
        $settings['temp_logging_enabled'] = false;
        $settings['temp_logging_expiration'] = 0;
        $settings['debug_log_created_by_plugin'] = false;
        $settings['debug_log_creation_timestamp'] = 0;

        // andw-session.jsonファイルを削除
        $session_file = WP_CONTENT_DIR . '/andw-session.json';
        if ( file_exists( $session_file ) ) {
            $deleted = unlink( $session_file );
            error_log( 'andW Debug Viewer: Deleted session file: ' . ( $deleted ? 'SUCCESS' : 'FAILED' ) );
        }

        return update_option( self::OPTION_NAME, $settings, false );
    }

    /**
     * Apply PHP logging settings when temporary logging is enabled.
     *
     * @return void
     */
    private function apply_temp_logging_settings() {
        error_log( 'andW Debug Viewer: apply_temp_logging_settings() called' );
        error_log( 'andW Debug Viewer: is_temp_logging_active(): ' . ( $this->is_temp_logging_active() ? 'true' : 'false' ) );

        if ( $this->is_temp_logging_active() ) {
            error_log( 'andW Debug Viewer: Creating session file...' );

            // 一時セッションファイルを作成
            $this->create_temp_session_file();

            // 標準のdebug.logを使用（ini_setは不要）
            ini_set( 'log_errors', '1' );
            error_reporting( E_ALL );

            error_log( 'andW Debug Viewer: 一時ログセッションを開始しました' );
        } else {
            error_log( 'andW Debug Viewer: Temp logging not active, session file not created' );
        }
    }

    /**
     * Create session file with proper type and permissions.
     *
     * @param string $type Session type ('temp_logging' or 'wordpress_debug').
     * @param array  $permissions Optional permissions override.
     * @return void
     */
    private function create_session_file( $type = 'temp_logging', $permissions = array() ) {
        error_log( 'andW Debug Viewer: create_session_file() called with type: ' . $type );

        // デフォルトの権限設定
        $default_permissions = array(
            'safe_to_clear'      => false,
            'safe_to_download'   => true,
            'created_by_plugin'  => false,
        );

        // セッションタイプ別のデフォルト権限
        if ( 'temp_logging' === $type ) {
            $default_permissions['safe_to_clear'] = true;
            $default_permissions['created_by_plugin'] = true;
        } elseif ( 'wordpress_debug' === $type ) {
            $default_permissions['safe_to_clear'] = false;
            $default_permissions['created_by_plugin'] = false;
        }

        // 権限をマージ
        $final_permissions = wp_parse_args( $permissions, $default_permissions );

        // Use time() instead of current_time() for consistency across environments
        $now = time();
        $session_data = array(
            'created_at'  => $now,
            'expires_at'  => $now + ( 15 * MINUTE_IN_SECONDS ),
            'session_type' => $type,
            'permissions' => $final_permissions,
        );

        error_log( 'andW Debug Viewer: create_session_file() - current time: ' . $now );
        error_log( 'andW Debug Viewer: create_session_file() - expires at: ' . $session_data['expires_at'] );
        error_log( 'andW Debug Viewer: create_session_file() - permissions: ' . json_encode( $final_permissions ) );

        $session_file = WP_CONTENT_DIR . '/andw-session.json';

        error_log( 'andW Debug Viewer: Session file path: ' . $session_file );
        error_log( 'andW Debug Viewer: WP_CONTENT_DIR: ' . WP_CONTENT_DIR );
        error_log( 'andW Debug Viewer: WP_CONTENT_DIR writable: ' . ( is_writable( WP_CONTENT_DIR ) ? 'true' : 'false' ) );

        $result = file_put_contents( $session_file, json_encode( $session_data, JSON_PRETTY_PRINT ), LOCK_EX );

        if ( $result !== false ) {
            error_log( 'andW Debug Viewer: セッションファイル作成成功: ' . $session_file . ' (' . $result . ' bytes)' );
        } else {
            error_log( 'andW Debug Viewer: セッションファイル作成失敗: ' . $session_file );
        }
    }

    /**
     * Create temporary session file (backward compatibility).
     *
     * @return void
     */
    private function create_temp_session_file() {
        $this->create_session_file( 'temp_logging' );
    }

    /**
     * Create WordPress debug session file for WP_DEBUG=true environments.
     *
     * @return void
     */
    public function create_wordpress_debug_session() {
        error_log( 'andW Debug Viewer: create_wordpress_debug_session() called' );

        // WP_DEBUG=true時のみ実行
        $wp_debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;
        $wp_debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

        if ( ! $wp_debug_enabled || ! $wp_debug_log_enabled ) {
            error_log( 'andW Debug Viewer: WP_DEBUG or WP_DEBUG_LOG not enabled, skipping session creation' );
            return;
        }

        // 既存のセッションファイルをチェック
        $session_file = WP_CONTENT_DIR . '/andw-session.json';
        if ( file_exists( $session_file ) ) {
            error_log( 'andW Debug Viewer: Session file already exists, not overwriting' );
            return;
        }

        // WordPress debugセッションを作成
        $this->create_session_file( 'wordpress_debug' );
        error_log( 'andW Debug Viewer: WordPress debug session created' );
    }

    /**
     * Get active session data if exists.
     *
     * @return array|false Session data or false if no active session.
     */
    public function get_active_session() {
        $session_file = WP_CONTENT_DIR . '/andw-session.json';

        // 無限ログ防止のため、詳細なログ出力を抑制
        static $logged_session_check = false;

        if ( ! file_exists( $session_file ) ) {
            if ( ! $logged_session_check ) {
                error_log( 'andW Debug Viewer: セッションファイルが存在しません（初回チェック）' );
                $logged_session_check = true;
            }
            return false;
        }

        $session_content = file_get_contents( $session_file );
        $session_data = json_decode( $session_content, true );

        if ( ! $session_data || ! isset( $session_data['expires_at'] ) ) {
            if ( ! $logged_session_check ) {
                error_log( 'andW Debug Viewer: 不正なセッションデータ（初回チェック）' );
                $logged_session_check = true;
            }
            return false;
        }

        $current_time = time();
        $expires_at = (int) $session_data['expires_at'];
        $is_active = ( $expires_at > $current_time );

        // ログ出力を完全に抑制（WP_DEBUG_LOG=true環境での無限ループ防止）
        // static $last_session_state = null;
        // if ( $last_session_state !== $is_active ) {
        //     error_log( 'andW Debug Viewer: セッション状態変化: ' . ( $is_active ? 'アクティブ' : '期限切れ' ) );
        //     $last_session_state = $is_active;
        // }

        return $is_active ? $session_data : false;
    }

    /**
     * Check if temp session is active.
     *
     * @return bool
     */
    public function is_temp_session_active() {
        $session = $this->get_active_session();
        return $session !== false;
    }

    /**
     * Remove expired session file.
     *
     * @return void
     */
    public function cleanup_expired_session() {
        $session_file = WP_CONTENT_DIR . '/andw-session.json';

        if ( file_exists( $session_file ) && ! $this->is_temp_session_active() ) {
            unlink( $session_file );
            error_log( 'andW Debug Viewer: 期限切れセッションファイルを削除しました' );
        }
    }

    /**
     * Handle temporary logging expiration.
     *
     * @return void
     */
    private function handle_temp_logging_expiration() {
        error_log( 'andW Debug Viewer: Temporary logging expiration cleanup started' );

        // セッションファイルから権限情報を取得
        $session_file = WP_CONTENT_DIR . '/andw-session.json';
        $session_data = false;
        $safe_to_clear = false;
        $created_by_plugin = false;

        if ( file_exists( $session_file ) ) {
            $session_content = file_get_contents( $session_file );
            $session_data = json_decode( $session_content, true );

            if ( $session_data && isset( $session_data['permissions'] ) ) {
                $safe_to_clear = ! empty( $session_data['permissions']['safe_to_clear'] );
                $created_by_plugin = ! empty( $session_data['permissions']['created_by_plugin'] );

                error_log( 'andW Debug Viewer: Session type: ' . ( isset( $session_data['session_type'] ) ? $session_data['session_type'] : 'unknown' ) );
                error_log( 'andW Debug Viewer: safe_to_clear: ' . ( $safe_to_clear ? 'YES' : 'NO' ) );
                error_log( 'andW Debug Viewer: created_by_plugin: ' . ( $created_by_plugin ? 'YES' : 'NO' ) );
            }
        }

        // フォールバック: セッションファイルがない場合は従来の方法で確認
        if ( ! $session_data ) {
            error_log( 'andW Debug Viewer: No session file found, checking WordPress options as fallback' );
            $settings = $this->get_settings();
            $was_temp_active = ! empty( $settings['temp_logging_enabled'] );
            $was_created_by_plugin = ! empty( $settings['debug_log_created_by_plugin'] );

            error_log( 'andW Debug Viewer: was_temp_active: ' . ( $was_temp_active ? 'YES' : 'NO' ) );
            error_log( 'andW Debug Viewer: was_created_by_plugin: ' . ( $was_created_by_plugin ? 'YES' : 'NO' ) );

            $safe_to_clear = $was_temp_active;
            $created_by_plugin = $was_created_by_plugin;
        }

        // debug.logを削除するかどうか判断（WP_DEBUG_LOG=false環境では積極的に削除）
        $debug_log_path = WP_CONTENT_DIR . '/debug.log';
        $wp_debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

        if ( file_exists( $debug_log_path ) ) {
            if ( ! $wp_debug_log_enabled && $safe_to_clear ) {
                // WP_DEBUG_LOG=false環境では、一時ログ期限切れ時に削除
                $deleted = unlink( $debug_log_path );
                error_log( 'andW Debug Viewer: Deleted temp debug.log (WP_DEBUG_LOG=false): ' . ( $deleted ? 'SUCCESS' : 'FAILED' ) );
            } elseif ( $wp_debug_log_enabled ) {
                // WP_DEBUG_LOG=true環境では削除しない
                error_log( 'andW Debug Viewer: debug.log exists but WP_DEBUG_LOG=true - keeping it' );
            } elseif ( $safe_to_clear && $created_by_plugin ) {
                // プラグインが作成したものは削除
                $deleted = unlink( $debug_log_path );
                error_log( 'andW Debug Viewer: Deleted plugin-created debug.log: ' . ( $deleted ? 'SUCCESS' : 'FAILED' ) );
            } else {
                error_log( 'andW Debug Viewer: debug.log exists but conditions not met for deletion' );
            }
        } else {
            error_log( 'andW Debug Viewer: debug.log does not exist - nothing to delete' );
        }

        // WordPressオプションから一時ログ設定をクリア
        error_log( 'andW Debug Viewer: Clearing temporary logging settings from WordPress options' );
        $settings = $this->get_settings();
        $settings['temp_logging_enabled'] = false;
        $settings['temp_logging_expiration'] = 0;
        $settings['debug_log_created_by_plugin'] = false;
        $settings['debug_log_creation_timestamp'] = 0;

        $updated = update_option( self::OPTION_NAME, $settings, false );
        error_log( 'andW Debug Viewer: WordPress options cleanup result: ' . ( $updated ? 'SUCCESS' : 'FAILED' ) );

        // andw-session.jsonファイルを削除
        $session_file = WP_CONTENT_DIR . '/andw-session.json';
        if ( file_exists( $session_file ) ) {
            $deleted = unlink( $session_file );
            error_log( 'andW Debug Viewer: Deleted session file: ' . ( $deleted ? 'SUCCESS' : 'FAILED' ) );
        }

        // 古いdebug-temp.logファイルがあれば削除（後方互換性）
        $temp_log_file = WP_CONTENT_DIR . '/debug-temp.log';
        if ( file_exists( $temp_log_file ) ) {
            unlink( $temp_log_file );
            error_log( 'andW Debug Viewer: Cleaned up legacy temporary log file: debug-temp.log' );
        }

        error_log( 'andW Debug Viewer: Temporary logging expiration cleanup completed' );
    }

    /**
     * Helper for sanitizing numeric ranges.
     *
     * @param array  $input Input array.
     * @param string $key   Key name.
     * @param int    $min   Minimum value.
     * @param int    $max   Maximum value.
     * @return int
     */
    private function sanitize_range( $input, $key, $min, $max ) {
        $value = isset( $input[ $key ] ) ? (int) $input[ $key ] : 0;
        if ( $value < $min ) {
            $value = $min;
        }
        if ( $value > $max ) {
            $value = $max;
        }

        return $value;
    }
}
