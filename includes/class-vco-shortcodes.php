<?php
/**
 * Shortcode — [vetra_cut_optimizer] / [vco] for pages and posts.
 *
 * @package VCO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VCO_Shortcodes {

	const SUPERVISOR_ROLE = 'vco_site_supervisor';

	public static function init() {
		self::install_supervisor_role();
		add_shortcode( 'vetra_cut_optimizer', array( __CLASS__, 'render' ) );
		add_shortcode( 'vco', array( __CLASS__, 'render' ) );
		add_action( 'admin_init', array( __CLASS__, 'block_supervisor_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_supervisor_admin_bar' ) );
	}

	public static function block_supervisor_admin() {
		$action = sanitize_key( $_REQUEST['action'] ?? '' );
		if ( self::is_supervisor_user() && ! wp_doing_ajax() && 'vco_export_csv' !== $action ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}

	public static function hide_supervisor_admin_bar( $show ) {
		return self::is_supervisor_user() ? false : $show;
	}

	private static function is_supervisor_user() {
		$user = wp_get_current_user();
		return $user && in_array( self::SUPERVISOR_ROLE, (array) $user->roles, true );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array( 'project' => 0, 'roles' => '' ), $atts, 'vetra_cut_optimizer' );
		$roles = self::normalise_roles( $atts['roles'] );
		if ( empty( $roles ) ) {
			$roles = self::default_roles();
		}

		if ( ! self::user_can_roles( $roles ) ) {
			return '<p class="vco-deny">'
				. esc_html__( 'نقش کاربری شما اجازه استفاده از این ابزار را ندارد.', 'vetra-cut-optimizer' )
				. '</p>';
		}

		VCO_Admin::enqueue( $roles );

		$extra = intval( $atts['project'] ) ? ' data-initial-project="' . intval( $atts['project'] ) . '"' : '';
		return VCO_Admin::frontend_css()
			. '<div class="vco-front-wrap"' . $extra . '><div id="vco-root"><div class="vco-loading">…</div></div></div>';
	}

	/** Roles that may use the plugin by default (settings page), plus the dedicated role. */
	public static function default_roles() {
		$roles = self::normalise_roles( (array) get_option( 'vco_allowed_roles', array() ) );
		$roles[] = self::SUPERVISOR_ROLE;
		return array_values( array_unique( $roles ) );
	}

	/** Return valid WordPress role slugs from a comma-separated shortcode value. */
	public static function normalise_roles( $roles ) {
		if ( is_string( $roles ) ) {
			$roles = explode( ',', $roles );
		}
		$known = wp_roles()->roles;
		$out   = array();
		foreach ( (array) $roles as $requested ) {
			$requested = trim( (string) $requested );
			$slug      = sanitize_key( $requested );
			foreach ( $known as $known_slug => $role ) {
				if ( $slug === $known_slug || $requested === ( $role['name'] ?? '' ) ) {
					$out[] = $known_slug;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Create the front-end-only role without granting any wp-admin capability. */
	public static function install_supervisor_role() {
		$role = get_role( self::SUPERVISOR_ROLE );
		if ( ! $role ) {
			$role = add_role( self::SUPERVISOR_ROLE, 'سرپرست کارگاه', array( 'read' => true, 'vco_use_optimizer' => true ) );
		}
		if ( $role ) {
			$role->add_cap( 'read' );
			$role->add_cap( 'vco_use_optimizer' );
		}
	}

	public static function user_can_roles( array $roles = array() ) {
		if ( current_user_can( apply_filters( 'vco_required_cap', 'manage_options' ) ) ) {
			return true;
		}
		if ( empty( $roles ) ) {
			return false;
		}
		$user = wp_get_current_user();
		return (bool) array_intersect( (array) $user->roles, $roles );
	}

	/** Signed, short-lived access token used by a role-authorised shortcode. */
	public static function issue_token( array $roles ) {
		if ( empty( $roles ) ) {
			return '';
		}
		$payload = self::base64url_encode( wp_json_encode( array( 'roles' => array_values( $roles ), 'exp' => time() + DAY_IN_SECONDS ) ) );
		return $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	}

	public static function validate_token( $token ) {
		$parts = explode( '.', (string) $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return false;
		}
		$data = json_decode( self::base64url_decode( $parts[0] ), true );
		return is_array( $data ) && ! empty( $data['exp'] ) && time() < intval( $data['exp'] ) && self::user_can_roles( self::normalise_roles( $data['roles'] ?? array() ) );
	}

	public static function request_can_access( $token = '' ) {
		if ( current_user_can( apply_filters( 'vco_required_cap', 'manage_options' ) ) ) {
			return true;
		}
		if ( current_user_can( 'vco_use_optimizer' ) ) {
			return true;
		}
		if ( self::user_can_roles( self::default_roles() ) ) {
			return true;
		}
		return self::validate_token( $token );
	}

	private static function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( $value ) {
		return base64_decode( strtr( $value, '-_', '+/' ) );
	}
}
