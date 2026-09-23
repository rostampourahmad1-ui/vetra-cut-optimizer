<?php
/**
 * Database layer — creates/installs custom tables.
 *
 * @package VCO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VCO_Database {

	const T_PROJECTS  = 'vetra_rebar_projects';
	const T_CUTS      = 'vetra_rebar_cuts';
	const T_INVENTORY = 'vetra_rebar_inventory';
	const T_RESULTS   = 'vetra_rebar_results';

	public static function table_name( $base ) {
		global $wpdb;
		return $wpdb->prefix . $base;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		dbDelta(
			"CREATE TABLE {$p}vetra_rebar_projects (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(191) NOT NULL DEFAULT '',
				stock_length DOUBLE NOT NULL DEFAULT 12,
				kerf DOUBLE NOT NULL DEFAULT 0,
				price_config LONGTEXT NULL,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}vetra_rebar_cuts (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				project_id BIGINT(20) UNSIGNED NOT NULL,
				size INT NOT NULL,
				grade VARCHAR(10) NOT NULL DEFAULT 'AII',
				length DOUBLE NOT NULL DEFAULT 0,
				quantity INT NOT NULL DEFAULT 0,
				label VARCHAR(191) NOT NULL DEFAULT '',
				note TEXT NULL,
				sort_order INT NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY project_id (project_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}vetra_rebar_inventory (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				project_id BIGINT(20) UNSIGNED NOT NULL,
				size INT NOT NULL,
				grade VARCHAR(10) NOT NULL DEFAULT 'AII',
				bar_length DOUBLE NOT NULL DEFAULT 0,
				quantity INT NOT NULL DEFAULT 0,
				location VARCHAR(191) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY project_id (project_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}vetra_rebar_results (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				project_id BIGINT(20) UNSIGNED NOT NULL,
				size INT NOT NULL,
				grade VARCHAR(10) NOT NULL DEFAULT 'AII',
				bars_new_used INT NOT NULL DEFAULT 0,
				inventory_used LONGTEXT NULL,
				total_waste DOUBLE NOT NULL DEFAULT 0,
				waste_percentage DOUBLE NOT NULL DEFAULT 0,
				total_weight DOUBLE NOT NULL DEFAULT 0,
				total_cost DOUBLE NOT NULL DEFAULT 0,
				cutting_plan LONGTEXT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY project_id (project_id)
			) {$charset};"
		);

		// dbDelta does not remove obsolete columns from older plugin versions.
		$projects = $p . 'vetra_rebar_projects';
		$cuts     = $p . 'vetra_rebar_cuts';
		if ( $wpdb->get_var( "SHOW COLUMNS FROM {$projects} LIKE 'mode'" ) ) { // phpcs:ignore
			$wpdb->query( "ALTER TABLE {$projects} DROP COLUMN mode" ); // phpcs:ignore
		}
		if ( $wpdb->get_var( "SHOW COLUMNS FROM {$cuts} LIKE 'shape'" ) ) { // phpcs:ignore
			$wpdb->query( "ALTER TABLE {$cuts} DROP COLUMN shape" ); // phpcs:ignore
		}
	}

	public static function uninstall() {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->query( "DROP TABLE IF EXISTS {$p}vetra_rebar_results" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$p}vetra_rebar_inventory" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$p}vetra_rebar_cuts" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$p}vetra_rebar_projects" ); // phpcs:ignore
	}
}
