<?php
/**
 * Uninstall script for andW Debug Viewer.
 *
 * @package andw-debug-viewer
 */

// Exit if uninstall is not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Delete plugin options.
delete_option( 'andw_settings' );
delete_option( 'andw_debug_log_override' );

// Delete temporary options (if any exist).
delete_option( 'andw_settings_temp' );

// Remove session files.
$session_file = WP_CONTENT_DIR . '/andw-session.json';
if ( file_exists( $session_file ) ) {
    wp_delete_file( $session_file );
}

// For multisite installations, also clean up network-wide data if needed.
if ( is_multisite() ) {
    // Note: This plugin doesn't currently use site options,
    // but this serves as a template for future network-wide features.
    // delete_site_option( 'andw_network_settings' );
}

// Clear any transients (none currently used, but good practice).
// delete_transient( 'andw_cache_key' );

// Note: We don't remove wp-content/debug.log as it may contain
// logs from other sources and should be managed separately.