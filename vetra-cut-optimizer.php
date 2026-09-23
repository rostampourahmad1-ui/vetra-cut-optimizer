<?php
/**
 * Plugin Name:       Vetra RebarCut
 * Plugin URI:        https://vetra.local/
 * Description:       بهینه‌ساز برش میلگرد — کسر خودکار از موجودی انبار، لیست خرید، نقشه گرافیکی برش، چاپ لیبل، PDF، Excel، شورتکد و REST API.
 * Version:           2.5.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            VETRA
 * License:           GPL-2.0-or-later
 * Text Domain:       vetra-cut-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VCO_VERSION', '2.5.0' );
define( 'VCO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VCO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once VCO_PLUGIN_DIR . 'includes/class-vco-database.php';
require_once VCO_PLUGIN_DIR . 'includes/class-vco-optimizer.php';
require_once VCO_PLUGIN_DIR . 'includes/class-vco-rest-api.php';
require_once VCO_PLUGIN_DIR . 'includes/class-vco-admin.php';
require_once VCO_PLUGIN_DIR . 'includes/class-vco-shortcodes.php';

register_activation_hook( __FILE__, array( 'VCO_Database', 'install' ) );
register_activation_hook( __FILE__, 'vco_mark_version' );

function vco_mark_version() {
	update_option( 'vco_version', VCO_VERSION );
}

add_action( 'plugins_loaded', 'vco_bootstrap' );
function vco_bootstrap() {
	if ( get_option( 'vco_version' ) !== VCO_VERSION ) {
		VCO_Database::install();
		update_option( 'vco_version', VCO_VERSION );
	}
	VCO_REST_API::init();
	VCO_Admin::init();
	VCO_Shortcodes::init();
}

register_deactivation_hook( __FILE__, 'vco_flush_rewrites' );
function vco_flush_rewrites() {
	flush_rewrite_rules();
}
