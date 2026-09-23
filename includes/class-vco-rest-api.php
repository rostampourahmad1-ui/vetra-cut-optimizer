<?php
/**
 * REST API — /wp-json/vco/v1/*
 *
 * @package VCO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VCO_REST_API {

	const NS = 'vco/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( self::NS, '/projects', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_projects' ), 'permission_callback' => array( __CLASS__, 'perm' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_project' ), 'permission_callback' => array( __CLASS__, 'perm' ) ),
		) );

		register_rest_route( self::NS, '/projects/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_project' ), 'permission_callback' => array( __CLASS__, 'perm' ) ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'update_project' ), 'permission_callback' => array( __CLASS__, 'perm' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_project' ), 'permission_callback' => array( __CLASS__, 'perm' ) ),
		) );

		register_rest_route( self::NS, '/projects/(?P<id>\d+)/cuts', array(
			'methods' => 'PUT', 'callback' => array( __CLASS__, 'save_cuts' ), 'permission_callback' => array( __CLASS__, 'perm' ),
		) );

		register_rest_route( self::NS, '/projects/(?P<id>\d+)/inventory', array(
			'methods' => 'PUT', 'callback' => array( __CLASS__, 'save_inventory' ), 'permission_callback' => array( __CLASS__, 'perm' ),
		) );

		register_rest_route( self::NS, '/projects/(?P<id>\d+)/settings', array(
			'methods' => 'PUT', 'callback' => array( __CLASS__, 'save_settings' ), 'permission_callback' => array( __CLASS__, 'perm' ),
		) );

		register_rest_route( self::NS, '/projects/(?P<id>\d+)/optimize', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'run_optimize' ), 'permission_callback' => array( __CLASS__, 'perm' ),
		) );

		register_rest_route( self::NS, '/projects/(?P<id>\d+)/results', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'get_results' ), 'permission_callback' => array( __CLASS__, 'perm' ),
		) );

		register_rest_route( self::NS, '/meta/weights', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'meta_weights' ), 'permission_callback' => '__return_true',
		) );
	}

	public static function perm() {
		return VCO_Shortcodes::request_can_access( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_VCO_ACCESS'] ?? '' ) ) );
	}

	// ------------------------------------------------------------------

	public static function list_projects( WP_REST_Request $req ) {
		global $wpdb;
		$t    = VCO_Database::table_name( VCO_Database::T_PROJECTS );
		$rows = $wpdb->get_results( "SELECT id, name, stock_length, kerf, created_at FROM {$t} ORDER BY id DESC" ); // phpcs:ignore
		return rest_ensure_response( $rows );
	}

	public static function create_project( WP_REST_Request $req ) {
		global $wpdb;
		$t  = VCO_Database::table_name( VCO_Database::T_PROJECTS );
		$ok = $wpdb->insert( $t, array(
			'name'         => sanitize_text_field( $req->get_param( 'name' ) ?: __( 'پروژه بدون نام', 'vetra-cut-optimizer' ) ),
			'stock_length' => floatval( $req->get_param( 'stock_length' ) ?: 12 ),
			'kerf'         => floatval( $req->get_param( 'kerf' ) ?: 0 ),
			'price_config' => wp_json_encode( self::default_config() ),
			'user_id'      => get_current_user_id(),
			'created_at'   => current_time( 'mysql' ),
		), array( '%s', '%f', '%f', '%s', '%d', '%s' ) );

		if ( ! $ok ) {
			return new WP_Error( 'vco_db_error', __( 'خطا در ایجاد پروژه', 'vetra-cut-optimizer' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		return rest_ensure_response( self::project_row( $id ) );
	}

	public static function get_project( WP_REST_Request $req ) {
		$id = intval( $req['id'] );
		$p  = self::project_row( $id );
		if ( ! $p ) {
			return new WP_Error( 'vco_not_found', __( 'پروژه یافت نشد', 'vetra-cut-optimizer' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $p );
	}

	public static function update_project( WP_REST_Request $req ) {
		global $wpdb;
		$id = intval( $req['id'] );
		if ( ! self::project_exists( $id ) ) {
			return new WP_Error( 'vco_not_found', __( 'پروژه یافت نشد', 'vetra-cut-optimizer' ), array( 'status' => 404 ) );
		}
		$params = $req->get_params();
		if ( isset( $params['name'] ) ) {
			$wpdb->update(
				VCO_Database::table_name( VCO_Database::T_PROJECTS ),
				array( 'name' => sanitize_text_field( $params['name'] ) ),
				array( 'id' => $id ), array( '%s' ), array( '%d' )
			);
		}
		return rest_ensure_response( self::project_row( $id ) );
	}

	public static function delete_project( WP_REST_Request $req ) {
		global $wpdb;
		$id = intval( $req['id'] );
		foreach ( array( VCO_Database::T_RESULTS, VCO_Database::T_INVENTORY, VCO_Database::T_CUTS, VCO_Database::T_PROJECTS ) as $t ) {
			$whereKey = ( VCO_Database::T_PROJECTS === $t ) ? 'id' : 'project_id';
			$wpdb->delete( VCO_Database::table_name( $t ), array( $whereKey => $id ) );
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	// ------------------------------------------------------------------

	public static function save_cuts( WP_REST_Request $req ) {
		global $wpdb;
		$id   = intval( $req['id'] );
		$rows = $req->get_json_params();
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'vco_bad', __( 'داده نامعتبر', 'vetra-cut-optimizer' ), array( 'status' => 400 ) );
		}
		$t = VCO_Database::table_name( VCO_Database::T_CUTS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE project_id = %d", $id ) ); // phpcs:ignore
		$i = 0;
		foreach ( $rows as $r ) {
			if ( ! isset( $r['size'], $r['length'], $r['quantity'] ) ) {
				continue;
			}
			$wpdb->insert( $t, array(
				'project_id' => $id,
				'size'       => intval( $r['size'] ),
				'grade'      => sanitize_text_field( $r['grade'] ?? 'AII' ),
				'length'     => floatval( $r['length'] ),
				'quantity'   => intval( $r['quantity'] ),
				'label'      => sanitize_text_field( $r['label'] ?? '' ),
				'note'       => sanitize_textarea_field( $r['note'] ?? '' ),
				'sort_order' => $i++,
			), array( '%d', '%d', '%s', '%f', '%d', '%s', '%s', '%d' ) );
		}
		return rest_ensure_response( array( 'saved' => $i ) );
	}

	public static function save_inventory( WP_REST_Request $req ) {
		global $wpdb;
		$id   = intval( $req['id'] );
		$rows = $req->get_json_params();
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'vco_bad', __( 'داده نامعتبر', 'vetra-cut-optimizer' ), array( 'status' => 400 ) );
		}
		$t = VCO_Database::table_name( VCO_Database::T_INVENTORY );
		// Replace MANUAL rows only — auto offcut rows are owned by the optimizer.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$t} WHERE project_id = %d AND (location IS NULL OR location <> %s)", $id, VCO_Admin::OFFCUT_TAG
		) ); // phpcs:ignore
		$i = 0;
		foreach ( $rows as $r ) {
			if ( ! isset( $r['size'], $r['bar_length'], $r['quantity'] ) ) {
				continue;
			}
			$wpdb->insert( $t, array(
				'project_id' => $id,
				'size'       => intval( $r['size'] ),
				'grade'      => sanitize_text_field( $r['grade'] ?? 'AII' ),
				'bar_length' => floatval( $r['bar_length'] ),
				'quantity'   => intval( $r['quantity'] ),
				'location'   => sanitize_text_field( $r['location'] ?? '' ),
			), array( '%d', '%d', '%s', '%f', '%d', '%s' ) );
			$i++;
		}
		return rest_ensure_response( array( 'saved' => $i ) );
	}

	public static function save_settings( WP_REST_Request $req ) {
		global $wpdb;
		$id  = intval( $req['id'] );
		$prj = self::project_row( $id );
		if ( ! $prj ) {
			return new WP_Error( 'vco_not_found', __( 'پروژه یافت نشد', 'vetra-cut-optimizer' ), array( 'status' => 404 ) );
		}
		$p   = $req->get_json_params();
		$cfg = is_array( $prj['price_config'] ) ? $prj['price_config'] : self::default_config();
		$cfg = array_merge( $cfg, is_array( $p['config'] ?? null ) ? $p['config'] : array() );

		$update = array( 'price_config' => wp_json_encode( $cfg ) );
		$fmt    = array( '%s' );
		if ( isset( $p['stock_length'] ) ) {
			$update['stock_length'] = floatval( $p['stock_length'] );
			$fmt[]                  = '%f';
		}
		if ( isset( $p['kerf'] ) ) {
			$update['kerf'] = floatval( $p['kerf'] ); // stored in millimetres.
			$fmt[]          = '%f';
		}
		$wpdb->update( VCO_Database::table_name( VCO_Database::T_PROJECTS ), $update, array( 'id' => $id ), $fmt, array( '%d' ) );
		return rest_ensure_response( array( 'saved' => true ) );
	}

	public static function run_optimize( WP_REST_Request $req ) {
		$id     = intval( $req['id'] );
		$packed = self::optimizer_input( $id );
		if ( is_wp_error( $packed ) ) {
			return $packed;
		}
		$report = VCO_Optimizer::optimize( $packed['input'] );
		self::persist_results( $id, $report );

		$added = self::sync_offcut_inventory( $id, $report['offcuts'] );
		$report['offcuts_added'] = $added;
		$report['offcuts_auto']  = true;

		return rest_ensure_response( $report );
	}

	/**
	 * Load project + stored rows and assemble optimizer input. Returns WP_Error on failure.
	 */
	public static function optimizer_input( $id ) {
		$prj = self::project_row( $id );
		if ( ! $prj ) {
			return new WP_Error( 'vco_not_found', __( 'پروژه یافت نشد', 'vetra-cut-optimizer' ), array( 'status' => 404 ) );
		}
		$cfg = $prj['price_config'];
		return array(
			'project'     => $prj,
			'auto_offcut' => true,
			'input'       => array(
				'stock_length'   => floatval( $prj['stock_length'] ),
				'kerf_mm'        => floatval( $prj['kerf'] ),
				'min_reusable_m' => floatval( $cfg['min_reusable_m'] ?? 0.5 ),
				'scrap_ratio'    => floatval( $cfg['scrap_ratio'] ?? 0.3 ),
				'ton_price'      => floatval( $cfg['ton_price'] ?? ( $cfg['default_price'] ?? 0 ) ),
				'cuts'           => $prj['cuts'],
				'inventory'      => $prj['inventory'],
			),
		);
	}

	/**
	 * Replace the auto-generated offcut inventory rows with the given offcuts.
	 */
	private static function sync_offcut_inventory( $projectId, array $offcuts ) {
		global $wpdb;
		$t = VCO_Database::table_name( VCO_Database::T_INVENTORY );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE project_id = %d AND location = %s", $projectId, VCO_Admin::OFFCUT_TAG ) ); // phpcs:ignore

		$agg = array();
		foreach ( $offcuts as $o ) {
			if ( $o['length'] <= 0 ) {
				continue;
			}
			$key = $o['size'] . '|' . $o['grade'] . '|' . $o['length'];
			if ( ! isset( $agg[ $key ] ) ) {
				$agg[ $key ] = $o + array( 'quantity' => 0 );
			}
			$agg[ $key ]['quantity']++;
		}

		$added = 0;
		foreach ( $agg as $row ) {
			$wpdb->insert( $t, array(
				'project_id' => $projectId,
				'size'       => intval( $row['size'] ),
				'grade'      => $row['grade'],
				'bar_length' => floatval( $row['length'] ),
				'quantity'   => intval( $row['quantity'] ),
				'location'   => VCO_Admin::OFFCUT_TAG,
			), array( '%d', '%d', '%s', '%f', '%d', '%s' ) );
			$added++;
		}
		return $added;
	}

	private static function persist_results( $projectId, array $report ) {
		global $wpdb;
		$t = VCO_Database::table_name( VCO_Database::T_RESULTS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE project_id = %d", $projectId ) ); // phpcs:ignore
		foreach ( $report['groups'] as $g ) {
			$s = $g['stat'];
			$wpdb->insert( $t, array(
				'project_id'       => $projectId,
				'size'             => $s['size'],
				'grade'            => $s['grade'],
				'bars_new_used'    => $s['new_bars'],
				'inventory_used'   => wp_json_encode( $g['inventory_use'] ),
				'total_waste'      => $s['waste_length'],
				'waste_percentage' => $s['waste_percent'],
				'total_weight'     => $s['weight_supply'],
				'total_cost'       => $s['cost'],
				'cutting_plan'     => wp_json_encode( array( 'stat' => $s, 'bars' => $g['bars'] ) ),
				'created_at'       => current_time( 'mysql' ),
			), array( '%d', '%d', '%s', '%d', '%s', '%f', '%f', '%f', '%f', '%s', '%s' ) );
		}
	}

	// ------------------------------------------------------------------

	public static function get_results( WP_REST_Request $req ) {
		global $wpdb;
		$t    = VCO_Database::table_name( VCO_Database::T_RESULTS );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE project_id = %d ORDER BY size ASC, grade ASC", intval( $req['id'] )
		), ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['cutting_plan']   = json_decode( (string) $r['cutting_plan'], true );
			$r['inventory_used'] = json_decode( (string) $r['inventory_used'], true );
		}
		unset( $r );
		return rest_ensure_response( $rows );
	}

	public static function meta_weights() {
		$out = array();
		foreach ( VCO_Optimizer::SIZES as $s ) {
			$out[] = array( 'size' => $s, 'kg_per_meter' => VCO_Optimizer::kg_per_meter( $s ) );
		}
		return rest_ensure_response( $out );
	}

	public static function project_row( $id ) {
		global $wpdb;
		$t   = VCO_Database::table_name( VCO_Database::T_PROJECTS );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$cfg = json_decode( (string) $row['price_config'], true );
		$row['price_config'] = is_array( $cfg ) ? $cfg : self::default_config();

		$tcut = VCO_Database::table_name( VCO_Database::T_CUTS );
		$row['cuts'] = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, size, grade, length, quantity, label, note FROM {$tcut} WHERE project_id = %d ORDER BY sort_order ASC", $id
		), ARRAY_A );

		$tinv = VCO_Database::table_name( VCO_Database::T_INVENTORY );
		$row['inventory'] = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, size, grade, bar_length, quantity, location FROM {$tinv} WHERE project_id = %d ORDER BY id ASC", $id
		), ARRAY_A );

		return $row;
	}

	private static function project_exists( $id ) {
		global $wpdb;
		$t = VCO_Database::table_name( VCO_Database::T_PROJECTS );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE id = %d", $id ) );
	}

	private static function default_config() {
		return array(
			'min_reusable_m' => 0.5,
			'scrap_ratio'    => 0.3,
			'ton_price'      => 0,
			'auto_offcut'    => 1,
		);
	}
}
