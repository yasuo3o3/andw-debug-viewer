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
            'enable_download'            => false,
            'allow_site_actions'         => false,
            'production_temp_expiration' => 0,
            'temp_logging_enabled'       => false,
            'temp_logging_expiration'    => 0,
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

        if ( $settings['production_temp_expiration'] && $this->is_production_override_expired( (int) $settings['production_temp_expiration'] ) ) {
            $settings['production_temp_expiration'] = 0;
            update_option( self::OPTION_NAME, $settings, false );
        }

        if ( $settings['temp_logging_expiration'] && $this->is_temp_logging_expired( (int) $settings['temp_logging_expiration'] ) ) {
            $settings['temp_logging_enabled'] = false;
            $settings['temp_logging_expiration'] = 0;
            update_option( self::OPTION_NAME, $settings, false );
        }

        return $this->normalize_settings( $settings );
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
        $settings   = $this->get_settings();
        $expiration = (int) $settings['production_temp_expiration'];

        return $expiration > current_time( 'timestamp' );
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
        $settings = $this->get_settings();
        $settings['temp_logging_enabled'] = true;
        $settings['temp_logging_expiration'] = current_time( 'timestamp' ) + ( 15 * MINUTE_IN_SECONDS );

        $updated = update_option( self::OPTION_NAME, $settings, false );

        if ( $updated ) {
            $this->apply_temp_logging_settings();
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
        $settings['temp_logging_enabled'] = false;
        $settings['temp_logging_expiration'] = 0;

        return update_option( self::OPTION_NAME, $settings, false );
    }

    /**
     * Apply PHP logging settings when temporary logging is enabled.
     *
     * @return void
     */
    private function apply_temp_logging_settings() {
        if ( $this->is_temp_logging_active() ) {
            ini_set( 'log_errors', '1' );
            ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' );
            error_reporting( E_ALL );
        }
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
