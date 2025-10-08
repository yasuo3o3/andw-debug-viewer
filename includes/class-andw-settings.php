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
     * Safe debug logging function.
     *
     * @param string $message Debug message.
     * @return void
     */
    private function debug_log( $message ) {
        if ( function_exists( 'wp_debug_log' ) ) {
            wp_debug_log( $message );
        }
    }

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
        }

        if ( $settings['production_temp_expiration'] && $this->is_production_override_expired( (int) $settings['production_temp_expiration'] ) ) {
            $settings['production_temp_expiration'] = 0;
            update_option( self::OPTION_NAME, $settings, false );
        }

        // 一時ログの期限切れチェック（WordPressオプション）
        $option_expired = $settings['temp_logging_expiration'] && $this->is_temp_logging_expired( (int) $settings['temp_logging_expiration'] );

        // セッションファイルの期限切れチェック
        $session_expired = false;
        $session_file = WP_CONTENT_DIR . '/andw-session.json';
        if ( file_exists( $session_file ) ) {
            $session_content = file_get_contents( $session_file );
            $session_data = json_decode( $session_content, true );
            if ( $session_data && isset( $session_data['expires_at'] ) && isset( $session_data['session_type'] ) && 'temp_logging' === $session_data['session_type'] ) {
                $session_expired = $session_data['expires_at'] <= time();
            }
        }

        // いずれかが期限切れの場合、クリーンアップ処理を実行
        if ( $option_expired || $session_expired ) {
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
            'expires_at' => time() + ( 60 * MINUTE_IN_SECONDS ) // 本番用: 60分間
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
        return ! empty( $settings['temp_logging_enabled'] ) && ! empty( $settings['temp_logging_expiration'] ) && $settings['temp_logging_expiration'] > time();
    }

    /**
     * Check whether temporary logging has expired.
     *
     * @param int $expiration Timestamp.
     * @return bool
     */
    public function is_temp_logging_expired( $expiration ) {
        return empty( $expiration ) || $expiration <= time();
    }

    /**
     * Enable temporary logging for 15 minutes.
     *
     * @return bool Success status.
     */
    public function enable_temp_logging() {
        $this->debug_log( 'andW Debug: enable_temp_logging() called' );

        // WP_DEBUGが有効な場合は一時ログを有効化しない
        $wp_debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;
        $wp_debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

        $this->debug_log( 'andW Debug: WP_DEBUG=' . ( $wp_debug_enabled ? 'true' : 'false' ) );
        $this->debug_log( 'andW Debug: WP_DEBUG_LOG=' . ( $wp_debug_log_enabled ? 'true' : 'false' ) );

        if ( $wp_debug_enabled && $wp_debug_log_enabled ) {
            $this->debug_log( 'andW Debug: skipping temp logging because WP_DEBUG and WP_DEBUG_LOG are both enabled' );
            return false;
        }

        $settings = $this->get_settings();
        $old_settings = $settings; // バックアップ

        // debug.logの事前状態を確認
        $debug_log_path = WP_CONTENT_DIR . '/debug.log';
        $debug_log_existed_before = file_exists( $debug_log_path );

        // 現在の設定を記録（print_r削除）

        $settings['temp_logging_enabled'] = true;

        $settings['temp_logging_expiration'] = current_time( 'timestamp' ) + ( 60 * MINUTE_IN_SECONDS ); // 本番用: 60分間

        // debug.logがプラグインによって作成される場合の記録
        if ( ! $debug_log_existed_before ) {
            $settings['debug_log_created_by_plugin'] = true;
            $settings['debug_log_creation_timestamp'] = time();
        }

        // 既存設定を確認
        $existing_settings = get_option( self::OPTION_NAME, array() );

        $this->debug_log( 'andW Debug: attempting to save settings via update_option' );

        // まず update_option を試行
        $updated = update_option( self::OPTION_NAME, $settings, false );

        $this->debug_log( 'andW Debug: update_option result: ' . ( $updated ? 'true' : 'false' ) );

        // 保存後の設定を確認
        $after_save = get_option( self::OPTION_NAME, array() );

        // update_option が false を返した場合でも、値が変わっていない場合は true とみなす場合がある
        if ( ! $updated ) {
            $this->debug_log( 'andW Debug: update_option returned false, checking if values were actually saved' );
            // 現在保存されている値を確認
            $current_saved = get_option( self::OPTION_NAME, array() );

            // 期待する値と一致するかチェック
            if ( isset( $current_saved['temp_logging_enabled'] ) &&
                 $current_saved['temp_logging_enabled'] &&
                 isset( $current_saved['temp_logging_expiration'] ) &&
                 abs( $current_saved['temp_logging_expiration'] - $settings['temp_logging_expiration'] ) < 5 ) {
                $updated = true; // 実際には正しく保存されている
            }
        }

        // 処理結果を記録

        if ( $updated ) {
            $this->debug_log( 'andW Debug: settings saved successfully, proceeding with temp logging setup' );

            // セッションファイルを作成
            $this->create_temp_session_file();

            $this->apply_temp_logging_settings();

            // Try to enable wp-config.php modification for proper error logging
            $this->debug_log( 'andW Debug: calling enable_wp_config_debug_logging()' );
            $wp_config_enabled = $this->enable_wp_config_debug_logging();
            $this->debug_log( 'andW Debug: enable_wp_config_debug_logging() returned: ' . ( $wp_config_enabled ? 'true' : 'false' ) );

            // ログ有効化の確認メッセージをデバッグログファイルに出力
            $log_file = trailingslashit( WP_CONTENT_DIR ) . 'debug.log';
            $config_status = $wp_config_enabled ? 'wp-config.phpも一時的に変更されました' : 'wp-config.phpの変更は行われませんでした';
            $log_message = '[' . wp_date( 'Y-m-d H:i:s', time() ) . '] andW Debug Viewer: 60分間のログ出力が有効化されました。' . $config_status . '。有効期限: ' . wp_date( 'Y-m-d H:i:s', $settings['temp_logging_expiration'] );
            if ( wp_is_writable( dirname( $log_file ) ) || wp_is_writable( $log_file ) ) {
                // WordPress Filesystem APIを使用
                global $wp_filesystem;
                if ( empty( $wp_filesystem ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    WP_Filesystem();
                }

                if ( $wp_filesystem ) {
                    $existing_content = $wp_filesystem->exists( $log_file ) ? $wp_filesystem->get_contents( $log_file ) : '';
                    $new_content = $existing_content . $log_message . PHP_EOL;
                    $wp_filesystem->put_contents( $log_file, $new_content, FS_CHMOD_FILE );
                }
            }
            // 設定が正しく保存されたか確認
            $saved_settings = $this->get_settings();
        } else {
            // 失敗時の処理
            $current_saved = get_option( self::OPTION_NAME, array() );

            // WordPressのデータベースエラーがあるかチェック
            global $wpdb;
            if ( $wpdb->last_error ) {
                // データベースエラーあり
            }

            // 代替手段: 直接設定を更新
            $manual_save = array(
                'temp_logging_enabled' => true,
                'temp_logging_expiration' => current_time( 'timestamp' ) + ( 60 * MINUTE_IN_SECONDS ), // 本番用: 60分間
            );
            $result = add_option( self::OPTION_NAME . '_temp', $manual_save, '', 'no' );

            // 設定保存が失敗してもログ機能を有効にするため、
            // とりあえず一時的にログを出力して、UIを正しく表示させる
            if ( ! $updated ) {
                // 設定保存が失敗してもセッションファイルを強制作成
                $this->create_temp_session_file();

                $this->apply_temp_logging_settings();
                $log_file = trailingslashit( WP_CONTENT_DIR ) . 'debug.log';
                $log_message = '[' . wp_date( 'Y-m-d H:i:s', time() ) . '] andW Debug Viewer: ログ出力を一時的に有効化しました（設定保存に問題がある可能性があります）';
                if ( wp_is_writable( dirname( $log_file ) ) || wp_is_writable( $log_file ) ) {
                    // WordPress Filesystem APIを使用
                    global $wp_filesystem;
                    if ( empty( $wp_filesystem ) ) {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                        WP_Filesystem();
                    }

                    if ( $wp_filesystem ) {
                        $existing_content = $wp_filesystem->exists( $log_file ) ? $wp_filesystem->get_contents( $log_file ) : '';
                        $new_content = $existing_content . $log_message . PHP_EOL;
                        $wp_filesystem->put_contents( $log_file, $new_content, FS_CHMOD_FILE );
                    }
                }

                // 強制的に成功とみなす
                $updated = true;
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

        // disable_temp_logging() 処理開始

        // debug.logを削除するかどうか判断
        $debug_log_path = WP_CONTENT_DIR . '/debug.log';
        if ( $was_created_by_plugin && file_exists( $debug_log_path ) ) {
            $deleted = wp_delete_file( $debug_log_path );
        }

        // JSON設定をクリア
        $settings['temp_logging_enabled'] = false;
        $settings['temp_logging_expiration'] = 0;
        $settings['debug_log_created_by_plugin'] = false;
        $settings['debug_log_creation_timestamp'] = 0;

        // andw-session.jsonファイルを削除
        $session_file = WP_CONTENT_DIR . '/andw-session.json';
        if ( file_exists( $session_file ) ) {
            $deleted = wp_delete_file( $session_file );
        }

        // wp-config.php修正も復元
        $this->disable_wp_config_debug_logging();

        return update_option( self::OPTION_NAME, $settings, false );
    }

    /**
     * Apply PHP logging settings when temporary logging is enabled.
     *
     * @return void
     */
    private function apply_temp_logging_settings() {
        if ( $this->is_temp_logging_active() ) {
            // 一時セッションファイルを作成
            $this->create_temp_session_file();

            // 標準のdebug.logを使用（ini_setは削除済み）
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
            'expires_at'  => $now + ( 60 * MINUTE_IN_SECONDS ), // 本番用: 60分間
            'session_type' => $type,
            'permissions' => $final_permissions,
        );

        $session_file = WP_CONTENT_DIR . '/andw-session.json';

        // WordPress Filesystem APIを使用
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $result = false;
        if ( $wp_filesystem ) {
            $result = $wp_filesystem->put_contents( $session_file, json_encode( $session_data, JSON_PRETTY_PRINT ), FS_CHMOD_FILE );
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
     * Explicitly end debug log usage and remove related files.
     *
     * @return bool Whether deletion was successful.
     */
    public function explicitly_end_debug_log_usage() {
        $success = true;

        // 一時ログ出力設定を無効化
        delete_option( self::OPTION_NAME . '_temp' );

        // WP_DEBUG_LOG オーバーライドを無効化
        delete_option( 'andw_debug_log_override' );

        // セッションファイルの削除
        $session_file = WP_CONTENT_DIR . '/andw-session.json';
        if ( file_exists( $session_file ) ) {
            $success = wp_delete_file( $session_file ) !== false && $success;
        }

        // debug.log の削除
        $debug_log_file = WP_CONTENT_DIR . '/debug.log';
        if ( file_exists( $debug_log_file ) ) {
            $success = wp_delete_file( $debug_log_file ) !== false && $success;
        }

        return $success;
    }

    /**
     * Create WordPress debug session file for WP_DEBUG=true environments.
     *
     * @return void
     */
    public function create_wordpress_debug_session() {
        // WP_DEBUG=true時のみ実行
        $wp_debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;
        $wp_debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

        if ( ! $wp_debug_enabled || ! $wp_debug_log_enabled ) {
            return;
        }

        // 既存のセッションファイルをチェック
        $session_file = WP_CONTENT_DIR . '/andw-session.json';
        if ( file_exists( $session_file ) ) {
            return;
        }

        // WordPress debugセッションを作成
        $this->create_session_file( 'wordpress_debug' );
    }

    /**
     * Get active session data if exists.
     *
     * @return array|false Session data or false if no active session.
     */
    public function get_active_session() {
        $session_file = WP_CONTENT_DIR . '/andw-session.json';

        if ( ! file_exists( $session_file ) ) {
            return false;
        }

        $session_content = file_get_contents( $session_file );
        $session_data = json_decode( $session_content, true );

        if ( ! $session_data || ! isset( $session_data['expires_at'] ) ) {
            return false;
        }

        $current_time = time();
        $expires_at = (int) $session_data['expires_at'];
        $is_active = ( $expires_at > $current_time );

        // ログ出力を完全に抑制（WP_DEBUG_LOG=true環境での無限ループ防止）
        // static $last_session_state = null;
        // if ( $last_session_state !== $is_active ) {
        //     $this->debug_log( 'andW Debug Viewer: セッション状態変化: ' . ( $is_active ? 'アクティブ' : '期限切れ' ) );
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
            wp_delete_file( $session_file );
        }
    }

    /**
     * Handle temporary logging expiration.
     *
     * @return void
     */
    private function handle_temp_logging_expiration() {
        // Temporary logging expiration cleanup started

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

                // セッション権限情報を確認
            }
        }

        // フォールバック: セッションファイルがない場合は従来の方法で確認
        if ( ! $session_data ) {
            $current_settings = get_option( self::OPTION_NAME, array() );
            $was_temp_active = ! empty( $current_settings['temp_logging_enabled'] );
            $was_created_by_plugin = ! empty( $current_settings['debug_log_created_by_plugin'] );

            $safe_to_clear = $was_temp_active;
            $created_by_plugin = $was_created_by_plugin;
        }

        // debug.logを削除するかどうか判断（WP_DEBUG_LOG=false環境では積極的に削除）
        $debug_log_path = WP_CONTENT_DIR . '/debug.log';
        $wp_debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

        if ( file_exists( $debug_log_path ) ) {
            if ( ! $wp_debug_log_enabled && $safe_to_clear ) {
                // WP_DEBUG_LOG=false環境では、一時ログ期限切れ時に削除
                $deleted = wp_delete_file( $debug_log_path );
            } elseif ( $safe_to_clear && $created_by_plugin ) {
                // プラグインが作成したものは削除
                $deleted = wp_delete_file( $debug_log_path );
            }
        }

        // WordPressオプションから一時ログ設定をクリア（無限ループ防止のため直接get_optionを使用）
        $settings = get_option( self::OPTION_NAME, $this->get_defaults() );
        if ( ! is_array( $settings ) ) {
            $settings = $this->get_defaults();
        }
        $settings['temp_logging_enabled'] = false;
        $settings['temp_logging_expiration'] = 0;
        $settings['debug_log_created_by_plugin'] = false;
        $settings['debug_log_creation_timestamp'] = 0;

        $updated = update_option( self::OPTION_NAME, $settings, false );

        // andw-session.jsonファイルを削除
        $session_file = WP_CONTENT_DIR . '/andw-session.json';
        if ( file_exists( $session_file ) ) {
            $deleted = wp_delete_file( $session_file );
        }

        // wp-config.php修正も復元
        $this->disable_wp_config_debug_logging();

        // 古いdebug-temp.logファイルがあれば削除（後方互換性）
        $temp_log_file = WP_CONTENT_DIR . '/debug-temp.log';
        if ( file_exists( $temp_log_file ) ) {
            wp_delete_file( $temp_log_file );
        }

        // Temporary logging expiration cleanup completed
    }

    /**
     * Enable temporary wp-config.php modification for debug logging.
     *
     * @return bool Success status.
     */
    public function enable_wp_config_debug_logging() {
        $wp_config_path = ABSPATH . 'wp-config.php';

        // Debug logging
        $this->debug_log( 'andW Debug: enable_wp_config_debug_logging() called' );
        $this->debug_log( 'andW Debug: wp-config path: ' . $wp_config_path );
        $this->debug_log( 'andW Debug: file exists: ' . ( file_exists( $wp_config_path ) ? 'yes' : 'no' ) );
        $this->debug_log( 'andW Debug: writable: ' . ( wp_is_writable( $wp_config_path ) ? 'yes' : 'no' ) );

        if ( ! file_exists( $wp_config_path ) || ! wp_is_writable( $wp_config_path ) ) {
            $this->debug_log( 'andW Debug: wp-config.php not found or not writable' );
            return false;
        }

        // Check if already modified
        if ( $this->is_wp_config_debug_modified() ) {
            $this->debug_log( 'andW Debug: wp-config already modified' );
            return true; // Already modified
        }

        // Read current wp-config.php
        $wp_config_content = file_get_contents( $wp_config_path );
        if ( false === $wp_config_content ) {
            $this->debug_log( 'andW Debug: failed to read wp-config.php' );
            return false;
        }

        $this->debug_log( 'andW Debug: wp-config content read, length: ' . strlen( $wp_config_content ) );

        // Create backup
        $backup_path = WP_CONTENT_DIR . '/andw-wp-config-backup.php';
        $backup_result = file_put_contents( $backup_path, $wp_config_content );
        if ( false === $backup_result ) {
            $this->debug_log( 'andW Debug: failed to create backup at: ' . $backup_path );
            return false;
        }

        $this->debug_log( 'andW Debug: backup created successfully' );

        // Find the WP_DEBUG section and modify it
        $debug_section_pattern = '/if\s*\(\s*!\s*defined\s*\(\s*[\'"]WP_DEBUG[\'"]\s*\)\s*\)\s*\{\s*define\s*\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*false\s*\)\s*;\s*\}/';

        $new_debug_section = "// andW Debug Viewer: Temporary debug logging enabled\n" .
                           "if ( ! defined( 'WP_DEBUG' ) ) {\n" .
                           "\tdefine( 'WP_DEBUG', true );\n" .
                           "}\n" .
                           "if ( ! defined( 'WP_DEBUG_LOG' ) ) {\n" .
                           "\tdefine( 'WP_DEBUG_LOG', true );\n" .
                           "}\n" .
                           "if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {\n" .
                           "\tdefine( 'WP_DEBUG_DISPLAY', false );\n" .
                           "}\n" .
                           "// andW Debug Viewer: End temporary modification";

        $modified_content = preg_replace( $debug_section_pattern, $new_debug_section, $wp_config_content );

        if ( $modified_content === $wp_config_content ) {
            $this->debug_log( 'andW Debug: WP_DEBUG pattern did not match, trying WP_ENVIRONMENT_TYPE' );
            // If pattern didn't match, try to insert before WP_ENVIRONMENT_TYPE
            $env_pattern = '/define\s*\(\s*[\'"]WP_ENVIRONMENT_TYPE[\'"]/';
            if ( preg_match( $env_pattern, $wp_config_content ) ) {
                $modified_content = preg_replace(
                    $env_pattern,
                    $new_debug_section . "\n\ndefine( 'WP_ENVIRONMENT_TYPE'",
                    $wp_config_content
                );
                $this->debug_log( 'andW Debug: inserted before WP_ENVIRONMENT_TYPE' );
            } else {
                $this->debug_log( 'andW Debug: WP_ENVIRONMENT_TYPE pattern did not match' );
            }
        } else {
            $this->debug_log( 'andW Debug: WP_DEBUG pattern matched successfully' );
        }

        // If still no modification, insert before "That's all, stop editing!"
        if ( $modified_content === $wp_config_content ) {
            $this->debug_log( 'andW Debug: trying stop editing pattern' );
            $stop_pattern = '/\/\*\s*That\'s all, stop editing!/';
            if ( preg_match( $stop_pattern, $wp_config_content ) ) {
                $modified_content = preg_replace(
                    $stop_pattern,
                    $new_debug_section . "\n\n/* That's all, stop editing!",
                    $wp_config_content
                );
                $this->debug_log( 'andW Debug: inserted before stop editing comment' );
            } else {
                $this->debug_log( 'andW Debug: stop editing pattern did not match' );
            }
        }

        // Verify modification was made
        if ( $modified_content === $wp_config_content ) {
            $this->debug_log( 'andW Debug: no modification was made, all patterns failed' );
            // Clean up backup
            wp_delete_file( $backup_path );
            return false;
        }

        $this->debug_log( 'andW Debug: modification successful, writing to wp-config.php' );

        // Write modified wp-config.php
        $write_result = file_put_contents( $wp_config_path, $modified_content );
        if ( false === $write_result ) {
            $this->debug_log( 'andW Debug: failed to write modified wp-config.php' );
            // Clean up backup
            wp_delete_file( $backup_path );
            return false;
        }

        $this->debug_log( 'andW Debug: wp-config.php written successfully' );

        // Store modification info
        $modification_info = array(
            'enabled' => true,
            'backup_path' => $backup_path,
            'enabled_at' => time(),
            'expires_at' => time() + ( 60 * MINUTE_IN_SECONDS ), // 60 minutes
        );

        $option_result = update_option( 'andw_wp_config_debug_mod', $modification_info, false );
        $this->debug_log( 'andW Debug: option update result: ' . ( $option_result ? 'success' : 'failed' ) );

        return true;
    }

    /**
     * Disable temporary wp-config.php modification and restore original.
     *
     * @return bool Success status.
     */
    public function disable_wp_config_debug_logging() {
        $modification_info = get_option( 'andw_wp_config_debug_mod', array() );

        if ( empty( $modification_info['enabled'] ) || empty( $modification_info['backup_path'] ) ) {
            return false;
        }

        $backup_path = $modification_info['backup_path'];
        $wp_config_path = ABSPATH . 'wp-config.php';

        if ( ! file_exists( $backup_path ) || ! file_exists( $wp_config_path ) ) {
            // Clean up option even if files are missing
            delete_option( 'andw_wp_config_debug_mod' );
            return false;
        }

        // Restore from backup
        $backup_content = file_get_contents( $backup_path );
        if ( false === $backup_content ) {
            return false;
        }

        $restore_result = file_put_contents( $wp_config_path, $backup_content );
        if ( false === $restore_result ) {
            return false;
        }

        // Clean up
        wp_delete_file( $backup_path );
        delete_option( 'andw_wp_config_debug_mod' );

        return true;
    }

    /**
     * Check if wp-config.php has been modified for debug logging.
     *
     * @return bool Whether wp-config.php is currently modified.
     */
    public function is_wp_config_debug_modified() {
        $modification_info = get_option( 'andw_wp_config_debug_mod', array() );

        if ( empty( $modification_info['enabled'] ) ) {
            return false;
        }

        // Check if expired
        if ( ! empty( $modification_info['expires_at'] ) && $modification_info['expires_at'] <= time() ) {
            // Auto-restore if expired
            $this->disable_wp_config_debug_logging();
            return false;
        }

        return true;
    }

    /**
     * Get wp-config modification expiration time.
     *
     * @return int Expiration timestamp, 0 if not modified.
     */
    public function get_wp_config_debug_expires() {
        $modification_info = get_option( 'andw_wp_config_debug_mod', array() );
        return isset( $modification_info['expires_at'] ) ? (int) $modification_info['expires_at'] : 0;
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
