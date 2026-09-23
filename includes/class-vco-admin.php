<?php
/**
 * Admin UI — menu, asset loading, CSV/print exports.
 *
 * @package VCO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VCO_Admin {

	const PAGE       = 'vetra-cut-optimizer';
	const OFFCUT_TAG = 'ته‌مانده خودکار';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_frontend' ) );
		add_action( 'admin_post_vco_export_csv', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/** Register (not enqueue) assets early so a shortcode can print them on the front-end. */
	public static function register_frontend() {
		wp_register_style( 'vco-admin', VCO_PLUGIN_URL . 'assets/css/admin.css', array(), VCO_VERSION );
		wp_register_script( 'vco-admin', VCO_PLUGIN_URL . 'assets/js/admin.js', array(), VCO_VERSION, true );
	}

	public static function menu() {
		$icon = self::menu_icon();
		add_menu_page(
			__( 'بهینه‌ساز برش میلگرد', 'vetra-cut-optimizer' ),
			__( 'وترا کات', 'vetra-cut-optimizer' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			$icon,
			58
		);
		add_submenu_page(
			self::PAGE,
			__( 'تنظیمات وترا کات', 'vetra-cut-optimizer' ),
			__( 'تنظیمات', 'vetra-cut-optimizer' ),
			'manage_options',
			self::PAGE . '-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/** White scissors icon as an inline SVG data URI. */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#ffffff"><path d="M9.64 7.64c.23-.5.36-1.05.36-1.64 0-2.21-1.79-4-4-4S2 3.79 2 6s1.79 4 4 4c.59 0 1.14-.13 1.64-.36L10 12l-2.36 2.36C7.14 14.13 6.59 14 6 14c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4c0-.59-.13-1.14-.36-1.64L12 14l7 7h3v-1L9.64 7.64zM6 8c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm0 12c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm6-7.5c-.28 0-.5-.22-.5-.5s.22-.5.5-.5.5.22.5.5-.22.5-.5.5zM19 3l-6 6 2 2 7-7V3h-3z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public static function register_settings() {
		register_setting(
			'vco_settings_group',
			'vco_allowed_roles',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_roles' ),
				'default'           => array(),
			)
		);
	}

	public static function sanitize_roles( $input ) {
		$known = array_keys( wp_roles()->roles );
		$out   = array();
		foreach ( (array) $input as $slug ) {
			$slug = sanitize_key( $slug );
			if ( in_array( $slug, $known, true ) ) {
				$out[] = $slug;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی کافی ندارید.', 'vetra-cut-optimizer' ) );
		}
		$saved     = (array) get_option( 'vco_allowed_roles', array() );
		$all_roles = wp_roles()->roles;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'تنظیمات وترا کات', 'vetra-cut-optimizer' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'vco_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'نقش‌های مجاز برای استفاده از افزونه', 'vetra-cut-optimizer' ); ?></th>
						<td>
							<fieldset>
								<p><?php esc_html_e( 'کاربرانی که یکی از این نقش‌ها را دارند می‌توانند از طریق شورتکد [vco] بدون دسترسی به پیشخوان با افزونه کار کنند.', 'vetra-cut-optimizer' ); ?></p>
								<?php foreach ( $all_roles as $slug => $role ) : ?>
									<label style="display:block;margin:4px 0">
										<input type="checkbox" name="vco_allowed_roles[]" value="<?php echo esc_attr( $slug ); ?>"
											<?php checked( in_array( $slug, $saved, true ) ); ?> />
										<?php echo esc_html( $role['name'] ); ?>
										<code style="color:#666">(<?php echo esc_html( $slug ); ?>)</code>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'نقش «سرپرست کارگاه» همیشه مجاز است.', 'vetra-cut-optimizer' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'ذخیره تنظیمات', 'vetra-cut-optimizer' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		self::enqueue();
	}

	/** Shared asset loader for admin page and front-end shortcode. */
	public static function enqueue( $roles = array() ) {
		wp_register_style( 'vco-admin', VCO_PLUGIN_URL . 'assets/css/admin.css', array(), VCO_VERSION );
		wp_register_script( 'vco-admin', VCO_PLUGIN_URL . 'assets/js/admin.js', array(), VCO_VERSION, true );
		if ( is_admin() ) {
			wp_enqueue_style( 'vco-admin' );
		}
		wp_enqueue_script( 'vco-admin' );
		wp_localize_script( 'vco-admin', 'VCO', array(
			'restUrl'   => esc_url_raw( rest_url( VCO_REST_API::NS ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'accessToken' => VCO_Shortcodes::issue_token( (array) $roles ),
			'allowedRoles' => (array) $roles,
			'csvUrl'    => admin_url( 'admin-post.php' ),
			'csvNonce'  => wp_create_nonce( 'vco_export' ),
			'offcutTag' => self::OFFCUT_TAG,
			'sizes'     => VCO_Optimizer::SIZES,
			'version'   => VCO_VERSION,
		) );
	}

	/**
	 * Inline stylesheet for the front-end, where wp_head has already fired by the
	 * time the shortcode renders. Returns an empty string in wp-admin.
	 */
	public static function frontend_css() {
		static $printed = false;
		if ( $printed || is_admin() ) {
			return '';
		}
		$printed = true;
		$file = VCO_PLUGIN_DIR . 'assets/css/admin.css';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		return '<style id="vco-admin-css">' . file_get_contents( $file ) . '</style>' . "\n"; // phpcs:ignore
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی کافی ندارید.', 'vetra-cut-optimizer' ) );
		}
		echo '<div class="wrap"><div id="vco-root"><div class="vco-loading">…</div></div></div>';
	}

	/**
	 * Export the (re-run, always fresh) cutting plan as UTF-8 BOM CSV — Excel friendly RTL.
	 */
	public static function export_csv() {
		if ( ! VCO_Shortcodes::request_can_access( sanitize_text_field( wp_unslash( $_GET['vco_access'] ?? '' ) ) ) ) {
			wp_die( esc_html__( 'دسترسی کافی ندارید.', 'vetra-cut-optimizer' ) );
		}
		check_admin_referer( 'vco_export' );
		$pid    = intval( $_GET['project'] ?? 0 );
		$packed = VCO_REST_API::optimizer_input( $pid );
		if ( is_wp_error( $packed ) ) {
			wp_die( esc_html__( 'پروژه یا داده نامعتبر.', 'vetra-cut-optimizer' ) );
		}
		$report = VCO_Optimizer::optimize( $packed['input'] );

		$filename = 'vetra-cut-plan-' . $pid . '-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM for Excel.

		$row = function ( $cells ) use ( $out ) {
			$line = '';
			foreach ( (array) $cells as $c ) {
				$c = str_replace( '"', '""', (string) $c );
				$line .= '"' . $c . '",';
			}
			fwrite( $out, rtrim( $line, ',' ) . "\r\n" );
		};
		$fa = function ( $n ) {
			return number_format( (float) $n, 2, '.', '' );
		};

		$row( array( 'گزارش بهینه‌سازی برش میلگرد — Vetra RebarCut' ) );
		$row( array( 'تاریخ', $report['generated_at'] ) );
		$row( array( 'قیمت هر کیلوگرم (ریال)', $fa( $report['config']['kg_price'] ) ) );
		$row( array() );

		$row( array( '=== لیست خرید شاخه نو (پس از کسر موجودی انبار) ===' ) );
		$row( array( 'سایز', 'طول شاخه (m)', 'تعداد شاخه', 'وزن کل (kg)', 'هزینه (ریال)' ) );
		foreach ( $report['purchase_list'] as $pl ) {
			$row( array( 'Ø' . $pl['size'], $fa( $pl['bar_length'] ), $pl['qty_bars'], $fa( $pl['weight_kg'] ), $fa( $pl['cost'] ) ) );
		}
		$row( array() );

		$row( array( '=== برنامه برش ===' ) );
		$row( array( 'سایز', 'نوع شاخه', 'طول (m)', 'منبع', 'ردیف قطعات (برش‌ها)', 'ته‌مانده قابل استفاده', 'ضایعات' ) );
		foreach ( $report['groups'] as $g ) {
			$label = 'Ø' . $g['stat']['size'];
			foreach ( $g['bars'] as $b ) {
				$cuts = array();
				foreach ( $b['pieces'] as $p ) {
					$cuts[] = $fa( $p['length'] ) . ( $p['label'] ? ' (' . $p['label'] . ')' : '' );
				}
				$row( array(
					$label,
					'new' === $b['type'] ? 'شاخه نو' : 'انبار',
					$fa( $b['length'] ),
					$b['location'] ? $b['location'] : ( 'new' === $b['type'] ? 'خرید' : '-' ),
					implode( ' | ', $cuts ),
					$fa( $b['reusable'] ),
					$fa( $b['waste'] ),
				) );
			}
		}
		$row( array() );

		$row( array( '=== لیست برش بهینه‌شده (جدول متنی) ===' ) );
		$row( array( 'ردیف', 'طول برش (m)', 'سایز', 'لیبل', 'توضیحات', 'شاخه' ) );
		$rn = 0;
		foreach ( $report['groups'] as $g ) {
			$bi = 0;
			foreach ( $g['bars'] as $b ) {
				$bi++;
				foreach ( $b['pieces'] as $p ) {
					$rn++;
					$row( array( $rn, $fa( $p['length'] ), 'Ø' . $g['stat']['size'], $p['label'], $p['note'], $bi ) );
				}
			}
		}
		$row( array() );

		$row( array( '=== آمار ===' ) );
		$t = $report['totals'];
		$row( array( 'شاخص', 'مقدار' ) );
		$row( array( 'شاخه نو مورد نیاز', $t['new_bars'] ) );
		$row( array( 'مصرف از انبار (متر)', $fa( $t['inventory_len'] ) ) );
		$row( array( 'کل ضایعات (متر)', $fa( $t['waste_length'] ) ) );
		$row( array( 'درصد ضایعات', $fa( $t['waste_percent'] ) ) );
		$row( array( 'ته‌مانده قابل استفاده (متر)', $fa( $t['reusable_left'] ) ) );
		$row( array( 'وزن مفید (kg)', $fa( $t['weight_useful'] ) ) );
		$row( array( 'وزن خرید (kg)', $fa( $t['weight_purchase'] ) ) );
		$row( array( 'هزینه خرید (ریال)', $fa( $t['cost'] ) ) );
		$row( array( 'ارزش ضایعات (ریال)', $fa( $t['scrap_value'] ) ) );
		$row( array( 'صرفه‌جویی (ریال)', $fa( $t['saving'] ) ) );

		fclose( $out ); // phpcs:ignore
		exit;
	}
}
