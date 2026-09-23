<?php
/**
 * Uninstall — drop plugin tables.
 *
 * @package VCO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-vco-database.php';

if ( is_multisite() ) {
	$sites = get_sites( array( 'number' => 0 ) );
	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );
		VCO_Database::uninstall();
		restore_current_blog();
	}
} else {
	VCO_Database::uninstall();
}

remove_role( 'vco_site_supervisor' );
