<?php
/**
 * Plugin Name: andW Debug Viewer
 * Plugin URI: https://netservice.jp/
 * Description: 管理画面から debug.log を安全に閲覧・管理するツール。
 * Version: 0.0.1
 * Contributors: yasuo3o3
 * Author: yasuo3o3
 * Author URI: https://yasuo-o.xyz/
 * License: GPLv2 or later
 * Text Domain: andw-debug-viewer
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package andw-debug-viewer
 */

defined( 'ABSPATH' ) || exit;

define( 'ANDW_VERSION', '0.01' );
define( 'ANDW_PLUGIN_FILE', __FILE__ );
define( 'ANDW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ANDW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ANDW_PLUGIN_DIR . 'includes/class-andw-plugin.php';

Andw_Plugin::instance()->init();
