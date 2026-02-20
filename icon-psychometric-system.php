<?php
/*
Plugin Name: Icon Psychometric System
Description: 360 / 180 / leadership profiling engine for Icon Talent.
Version: 1.0.0
Author: Icon Talent
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

// -----------------------------------------------------------------------------
// DEBUG TOGGLE (set true temporarily if you need logs)
// -----------------------------------------------------------------------------
if ( ! defined( 'ICON_PSY_DEBUG' ) ) {
	define( 'ICON_PSY_DEBUG', false );
}
if ( ! function_exists( 'icon_psy_log' ) ) {
	function icon_psy_log( $msg ) {
		if ( defined( 'ICON_PSY_DEBUG' ) && ICON_PSY_DEBUG ) {
			error_log( '[ICON_PSY] ' . $msg );
		}
	}
}

// -----------------------------------------------------------------------------
// 0) SINGLE SOURCE OF TRUTH: your URLs (LOGIN / REGISTER / LOST / PORTAL)
// -----------------------------------------------------------------------------
if ( ! function_exists( 'icon_psy_portal_url' ) ) {
	function icon_psy_portal_url() {
		return home_url( '/catalyst-portal/' );
	}
}

if ( ! function_exists( 'icon_psy_login_url' ) ) {
	function icon_psy_login_url() {
		return home_url( '/portal-login/' );
	}
}

if ( ! function_exists( 'icon_psy_register_url' ) ) {
	function icon_psy_register_url() {
		return home_url( '/register-page-2/' );
	}
}

if ( ! function_exists( 'icon_psy_lost_password_url' ) ) {
	function icon_psy_lost_password_url() {
		return home_url( '/lost-password-page/' );
	}
}

// -----------------------------------------------------------------------------
// 1) SAFE INCLUDE HELPER MUST EXIST BEFORE ANY LOADING HAPPENS
// -----------------------------------------------------------------------------
if ( ! function_exists( 'icon_psy_require_if_exists' ) ) {
	function icon_psy_require_if_exists( $path ) {
		if ( $path && file_exists( $path ) ) {
			require_once $path;
			return true;
		}
		return false;
	}
}

// If already logged in and they hit the login page, bounce them out
add_action( 'template_redirect', function () {
	if ( is_admin() ) { return; }

	if ( is_user_logged_in() && function_exists( 'is_page' ) && is_page( 'portal-login' ) ) {
		if ( current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( home_url( '/management-portal/' ) );
			exit;
		}
		wp_safe_redirect( icon_psy_portal_url() );
		exit;
	}
}, 4 );

// -----------------------------------------------------------------------------
// 2) CONSTANTS + ROOT DETECTION (recursive marker search)
// -----------------------------------------------------------------------------
if ( ! defined( 'ICON_PSY_PLUGIN_FILE' ) ) {
	define( 'ICON_PSY_PLUGIN_FILE', __FILE__ );
}

$base_dir = rtrim( plugin_dir_path( __FILE__ ), "/ \t\n\r\0\x0B" ) . '/';
$base_url = rtrim( plugin_dir_url( __FILE__ ),  "/ \t\n\r\0\x0B" ) . '/';

$marker_rel = 'includes/class-icon-psy-admin-menu.php';

if ( ! function_exists( 'icon_psy_find_root_dir_by_marker' ) ) {
	function icon_psy_find_root_dir_by_marker( $start_dir, $marker_rel, $max_depth = 6 ) {

		$start_dir = rtrim( $start_dir, "/ \t\n\r\0\x0B" ) . '/';
		if ( file_exists( $start_dir . $marker_rel ) ) {
			return $start_dir;
		}

		if ( $max_depth <= 0 ) { return ''; }

		$items = @scandir( $start_dir );
		if ( ! is_array( $items ) ) { return ''; }

		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) { continue; }
			$full = $start_dir . $item;

			if ( is_dir( $full ) ) {
				$lower = strtolower( $item );
				if ( in_array( $lower, array( 'node_modules', 'vendor', '.git' ), true ) ) {
					continue;
				}
				$full  = rtrim( $full, "/ \t\n\r\0\x0B" ) . '/';
				$found = icon_psy_find_root_dir_by_marker( $full, $marker_rel, $max_depth - 1 );
				if ( $found ) { return $found; }
			}
		}

		return '';
	}
}

$root_dir = icon_psy_find_root_dir_by_marker( $base_dir, $marker_rel, 6 );
if ( ! $root_dir ) {
	$root_dir = $base_dir;
}

$rel      = str_replace( $base_dir, '', $root_dir );
$rel      = ltrim( str_replace( '\\', '/', $rel ), '/' );
$root_url = $base_url . ( $rel ? trailingslashit( $rel ) : '' );

if ( ! defined( 'ICON_PSY_PLUGIN_DIR' ) ) {
	define( 'ICON_PSY_PLUGIN_DIR', trailingslashit( $root_dir ) );
}
if ( ! defined( 'ICON_PSY_PLUGIN_URL' ) ) {
	define( 'ICON_PSY_PLUGIN_URL', trailingslashit( $root_url ) );
}

// -----------------------------------------------------------------------------
// 3) LOAD FILES (ONLY what you need)
//    IMPORTANT: narratives must load BEFORE report shortcodes
// -----------------------------------------------------------------------------
if ( ! function_exists( 'icon_psy_load_files' ) ) {
	function icon_psy_load_files() {

		icon_psy_log('ICON_PSY_PLUGIN_DIR=' . ICON_PSY_PLUGIN_DIR);
		error_log('[ICON_PSY] your message here');

		// Helpers (roles, auth helpers, etc.)
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/helpers/icon-psy-auth-helpers.php' );
		// ✅ Email helper (must load BEFORE client portal + invites)
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/helpers/email.php' );

		// ✅ Narratives engine MUST load early (before feedback/team reports)
		$npath = ICON_PSY_PLUGIN_DIR . 'includes/narratives/lens-narratives.php';
		$nok   = icon_psy_require_if_exists( $npath );
		icon_psy_log( 'Lens narratives include: ' . $npath . ' => ' . ( $nok ? 'OK' : 'MISSING' ) );
		icon_psy_log( 'Lens narratives function? ' . ( function_exists( 'icon_psy_lens_narrative_html' ) ? 'YES' : 'NO' ) );

		// Theme My Login integration
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/auth/tml-portal.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/auth/portal-redirects.php' );

		// ✅ ONE portal UI
		$cp = ICON_PSY_PLUGIN_DIR . 'includes/shortcodes/client-portal.php';
		error_log('[ICON_PSY] client-portal path: ' . $cp);
		error_log('[ICON_PSY] client-portal exists: ' . ( file_exists($cp) ? 'YES' : 'NO' ) );

		$cp_ok = icon_psy_require_if_exists( $cp );
		error_log('[ICON_PSY] client-portal require: ' . ( $cp_ok ? 'OK' : 'FAILED' ) );
		error_log('[ICON_PSY] client-portal function exists: ' . ( function_exists('icon_psy_client_portal_shortcode') ? 'YES' : 'NO' ) );

		error_log('[ICON_PSY] loaded client-portal.php? ' . ( function_exists('icon_psy_client_portal_shortcode') ? 'YES' : 'NO' ));


		// Branded pages helper (if you’ve created it)
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/auth/icon-branded-auth-pages.php' );

		// ✅ Branding/Course engine (client settings table + get/save branding)
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/course-engine.php' );

		// Shortcodes / Reports
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/shortcodes/self-assessment.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/shortcodes/feedback-report.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/shortcodes/management-portal.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/shortcodes/team-report.php' );

		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/shortcodes/action-plan.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/shortcodes/client-trends.php' );

		// Core
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/class-icon-psy-activator.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/class-icon-psy-admin-menu.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/class-icon-psy-frontend.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/class-icon-psy-shortcodes.php' );

		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/class-icon-psy-ai.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/class-icon-psy-ai-competency-designer.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/class-icon-psy-survey.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/shortcodes/team-survey2.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/shortcodes/rater-survey-traits.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/shortcodes/traits-report.php' );
		icon_psy_require_if_exists( ICON_PSY_PLUGIN_DIR . 'includes/shortcodes/company-report.php' );

        $pattern = plugin_dir_path( __FILE__ ) . 'includes/shortcodes/*.php';

        // SAFE shortcode include loader
		/*
		$shortcodes_dir = plugin_dir_path( __FILE__ ) . 'includes/shortcodes/';
		$pattern        = $shortcodes_dir . '*.php';

		$files = glob( $pattern );
		if ( ! is_array( $files ) ) {
			$files = array();
		}

		foreach ( $files as $file ) {
			if ( is_string( $file ) && $file !== '' && file_exists( $file ) ) {
				require_once $file;
			} else {
				error_log( 'ICON PSY missing include: ' . (string) $file );
			}
		}
		*/

		// ICON Profiler report
		$path = ICON_PSY_PLUGIN_DIR . 'includes/shortcodes/icon-profiler-report.php';
		if ( ICON_PSY_DEBUG ) {// -----------------------------------------------------------------------------
// X) HIDE SITE MENUS/HEADER/FOOTER ON ICON PAGES (portal + reports)
// -----------------------------------------------------------------------------

/**
 * ✅ Ensure branding table exists on plugin activation.
 * This calls the course/branding engine's activation function (which should call dbDelta).
 */
register_activation_hook( __FILE__, function () {
	if ( function_exists( 'icon_psy_course_engine_activate' ) ) {
		icon_psy_course_engine_activate();
	} elseif ( function_exists( 'icon_psy_course_engine_install_tables' ) ) {
		// Fallback if you only have install_tables()
		icon_psy_course_engine_install_tables();
	}
} );

/**
 * ✅ Self-heal: if the branding table is missing (migration / activation missed), create it at runtime.
 * Safe + cheap: only runs a SHOW TABLES and only creates if missing.
 */
add_action( 'init', function () {
	global $wpdb;
	$table = $wpdb->prefix . 'icon_psy_client_settings';
	$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
	if ( $found !== $table ) {
		if ( function_exists( 'icon_psy_course_engine_install_tables' ) ) {
			icon_psy_course_engine_install_tables();
		}
	}
}, 5 );

if ( ! class_exists( 'Icon_PSY_Plugin' ) ) {
	class Icon_PSY_Plugin {
		public static function init() {

			if ( is_admin() && class_exists( 'Icon_PSY_Admin_Menu' ) ) {
				Icon_PSY_Admin_Menu::init();
			}
			if ( class_exists( 'Icon_PSY_Frontend' ) ) {
				Icon_PSY_Frontend::init();
			}
			if ( class_exists( 'Icon_PSY_Shortcodes' ) ) {
				Icon_PSY_Shortcodes::init();
			}
			if ( class_exists( 'Icon_PSY_AI' ) ) {
				Icon_PSY_AI::init();
			}
		}
	}
}
add_action( 'plugins_loaded', array( 'Icon_PSY_Plugin', 'init' ), 20 );

// -----------------------------------------------------------------------------
// 6) LOGIN REDIRECT: admins -> management, ICON users -> portal
// -----------------------------------------------------------------------------
add_filter( 'login_redirect', function( $redirect_to, $requested, $user ) {

	if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
		return $redirect_to;
	}

	// Admins -> management portal
	if ( user_can( $user, 'manage_options' ) ) {
		return home_url( '/management-portal/' );
	}

	// ICON users -> catalyst portal
	$roles = (array) $user->roles;
	if ( in_array( 'icon_client', $roles, true ) || in_array( 'icon_individual', $roles, true ) ) {
		return icon_psy_portal_url();
	}

	return $redirect_to;

}, 999, 3 );

// -----------------------------------------------------------------------------
// 7) PORTAL GUARD: if someone hits portal while logged out, bounce to login page
//    (with bypass for auth pages to avoid loops)
// -----------------------------------------------------------------------------

add_action( 'template_redirect', function() {

	if ( is_admin() ) { return; }

	// Never interfere with the auth pages themselves
	if ( function_exists( 'is_page' ) && ( is_page( 'portal-login' ) || is_page( 'register-page-2' ) || is_page( 'lost-password-page' ) ) ) {
		return;
	}

	$req_path = isset( $_SERVER['REQUEST_URI'] )
		? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH )
		: '';
	$req_path = rtrim( (string) $req_path, '/' );

	$portal_path = rtrim( wp_parse_url( icon_psy_portal_url(), PHP_URL_PATH ), '/' );

	if ( $portal_path && $req_path === $portal_path && ! is_user_logged_in() ) {

		$login_url = add_query_arg(
			array( 'redirect_to' => icon_psy_portal_url() ),
			icon_psy_login_url()
		);

		wp_safe_redirect( $login_url );
		exit;
	}

}, 5 );

// -----------------------------------------------------------------------------
// 9) Ensure results table has self_user_id column (for individual/self assessments)
// -----------------------------------------------------------------------------
if ( ! function_exists( 'icon_psy_maybe_add_self_user_id_to_results_table' ) ) {
	function icon_psy_maybe_add_self_user_id_to_results_table() {
		global $wpdb;

		$results_table = $wpdb->prefix . 'icon_assessment_results';

		// If table doesn't exist yet, don't fatal
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $results_table ) ) );
		if ( empty( $found ) ) return;

		$has = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$results_table} LIKE %s",
				'self_user_id'
			)
		);

		if ( ! $has ) {
			$wpdb->query(
				"ALTER TABLE {$results_table}
				 ADD COLUMN self_user_id BIGINT(20) UNSIGNED NULL AFTER rater_id"
			);
		}
	}
}
add_action( 'init', 'icon_psy_maybe_add_self_user_id_to_results_table' );

// -----------------------------------------------------------------------------
// 10) Feedback Results admin submenu (kept, but ONLY registered once)
// -----------------------------------------------------------------------------
if ( is_admin() ) {
	add_action( 'admin_menu', 'icon_psy_register_results_page', 20 );
}

if ( ! function_exists( 'icon_psy_register_results_page' ) ) {
	function icon_psy_register_results_page() {
		add_submenu_page(
			'icon-psych-system',
			'Feedback Results',
			'Feedback Results',
			'manage_options',
			'icon-psych-system-results',
			'icon_psy_render_results_page'
		);
	}
}

// -----------------------------------------------------------------------------
// 10.5) WooCommerce entitlements (credits + role) after successful payment
// -----------------------------------------------------------------------------
add_action( 'woocommerce_order_status_processing', 'icon_psy_apply_entitlements_from_order' );
add_action( 'woocommerce_order_status_completed',  'icon_psy_apply_entitlements_from_order' );

if ( ! function_exists( 'icon_psy_apply_entitlements_from_order' ) ) {
	function icon_psy_apply_entitlements_from_order( $order_id ) {

		if ( ! function_exists( 'wc_get_order' ) ) return;

		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) return;

		// Don't apply twice
		if ( get_post_meta( $order_id, '_icon_psy_entitlements_applied', true ) ) return;

		$add_credits = 0;
		$packages    = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) continue;

			$pkg_key  = (string) $product->get_meta( 'icon_package_key' );
			$per_qty  = (int) $product->get_meta( 'icon_credit_per_qty' );
			$ent_type = (string) $product->get_meta( 'icon_entitlement_type' );

			if ( $per_qty <= 0 ) $per_qty = 1;

			$qty = (int) $item->get_quantity();

			if ( $pkg_key ) {
				$packages[] = $pkg_key;
			}

			if ( $ent_type === 'custom_request' ) {
				continue;
			}

			$add_credits += ( $qty * $per_qty );
		}

		if ( $add_credits > 0 ) {
			if ( function_exists( 'icon_psy_increment_client_credits' ) ) {
				icon_psy_increment_client_credits( $user_id, $add_credits );
			} else {
				$current = (int) get_user_meta( $user_id, 'icon_psy_participant_credits', true );
				update_user_meta( $user_id, 'icon_psy_participant_credits', $current + $add_credits );
			}
		}

		if ( ! empty( $packages ) ) {
			$existing = get_user_meta( $user_id, 'icon_psy_packages', true );
			if ( ! is_array( $existing ) ) $existing = array();
			update_user_meta( $user_id, 'icon_psy_packages', array_values( array_unique( array_merge( $existing, $packages ) ) ) );
		}

		$u = get_user_by( 'id', $user_id );
		if ( $u && ! in_array( 'icon_client', (array) $u->roles, true ) ) {
			$u->add_role( 'icon_client' );
		}

		update_post_meta( $order_id, '_icon_psy_entitlements_applied', time() );
	}
}

// -----------------------------------------------------------------------------
// 11) Dompdf test shortcode (kept)
// -----------------------------------------------------------------------------
if ( ! function_exists( 'icon_psy_pdf_test_shortcode' ) ) {
	function icon_psy_pdf_test_shortcode() {

		$autoload = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
		if ( ! file_exists( $autoload ) ) {
			return '<p>vendor/autoload.php not found. Dompdf is not correctly installed.</p>';
		}

		require_once $autoload;

		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			return '<p>Dompdf class not found even after loading autoload.php.</p>';
		}

		if ( ob_get_length() ) {
			ob_end_clean();
		}

		$dompdf = new \Dompdf\Dompdf();
		$dompdf->loadHtml('
			<html>
				<head><meta charset="utf-8"></head>
				<body>
					<h1>ICON Catalyst PDF Test</h1>
					<p>If you can see this, Dompdf & output are working correctly.</p>
				</body>
			</html>
		');

		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="icon-catalyst-test.pdf"' );
		header( 'Cache-Control: private, max-age=0, must-revalidate' );
		header( 'Pragma: public' );

		echo $dompdf->output();
		exit;
	}
}
add_shortcode( 'icon_psy_pdf_test', 'icon_psy_pdf_test_shortcode' );

// -----------------------------------------------------------------------------
// X) HIDE SITE MENUS/HEADER/FOOTER ON ICON PAGES (portal + reports)
// -----------------------------------------------------------------------------
if ( ! function_exists( 'icon_psy_is_icon_page' ) ) {
	function icon_psy_is_icon_page() {
		if ( is_admin() ) return false;

		if ( ! function_exists( 'is_page' ) || ! is_page() ) return false;

		global $post;
		if ( ! $post ) return false;

		$shortcodes = array(
			'icon_psy_client_portal',
			'icon_psy_feedback_report',
			'icon_psy_team_report',
			'icon_profiler_report',
			'icon_psy_rater_survey',
			'icon_psy_rater_survey2',
			'icon_psy_team_survey2',
		);

		foreach ( $shortcodes as $sc ) {
			if ( has_shortcode( (string) $post->post_content, $sc ) ) {
				return true;
			}
		}

		$slugs = array(
			'catalyst-portal',
			'feedback-report',
			'team-report',
			'icon-profiler-report',
			'rater-survey',
			'portal-login',
		);

		$slug = isset( $post->post_name ) ? (string) $post->post_name : '';
		if ( $slug && in_array( $slug, $slugs, true ) ) return true;

		return false;
	}
}

add_action( 'wp_head', function() {
	if ( ! icon_psy_is_icon_page() ) return;
	?>
	<style id="icon-psy-hide-site-chrome">
		header, nav, #masthead, #site-header, .site-header, .main-navigation,
		#site-navigation, .primary-navigation, .header, .top-bar,
		.elementor-location-header, .elementor-location-footer,
		footer, #colophon, .site-footer {
			display: none !important;
		}

		body { padding-top: 0 !important; margin-top: 0 !important; }

		/* Optional: hide WP admin bar on front-end report pages */
		/* #wpadminbar { display:none !important; } html { margin-top:0 !important; } */
	</style>
	<?php
}, 20 );

if ( ! function_exists( 'icon_psy_get_package_catalog' ) ) {
	/**
	 * Single source of truth for packages.
	 * Returns: [ 'off_shelf' => [...], 'custom' => [...], 'all' => [...] ]
	 */
	function icon_psy_get_package_catalog() {

		$packages_off_shelf = array(
			'tool_only'              => array( 'title' => 'Tool only (Self administered 360 feedback)', 'price' => 'From £40 per person' ),
			'leadership_assessment'  => array( 'title' => 'Leadership Assessment / Icon Profiler',       'price' => 'From £45 per person' ),
			'feedback_360'           => array( 'title' => '360 feedback',                                 'price' => 'From £95 per person' ),
			'bundle_debrief'         => array( 'title' => '360 plus coaching debrief',                    'price' => 'From £195 per person' ),
			'full_package'           => array( 'title' => 'Full Catalyst package',                        'price' => 'From £395 per person' ),
			'high_performing_teams'  => array( 'title' => 'High Performing Teams assessment',             'price' => 'From £75 per team' ),
			'teams_cohorts'          => array( 'title' => 'Teams and leadership cohorts',                 'price' => 'Custom pricing' ),
			'subscription_50'        => array( 'title' => 'Subscription (Yearly 50 fully managed reports)','price' => '£2,225 per year' ),
			'aaet_internal'          => array( 'title' => 'Internal',                                     'price' => 'Internal' ),
		);

		$packages_custom = array(
			'custom_lite' => array( 'title' => 'Custom Lite', 'price' => '£4,500 to £6,500' ),
			'custom_plus' => array( 'title' => 'Custom Plus', 'price' => '£7,500 to £12,500' ),
			'enterprise'  => array( 'title' => 'Enterprise',  'price' => '£15,000+' ),
		);

		$catalog = array(
			'off_shelf' => $packages_off_shelf,
			'custom'    => $packages_custom,
			'all'       => array_merge( $packages_off_shelf, $packages_custom ),
		);

		// Optional: let you override from elsewhere later
		return apply_filters( 'icon_psy_package_catalog', $catalog );
	}
}


// -----------------------------------------------------------------------------
// Y) SAFE SHORTCODE REGISTRATION (avoid fatals if function not loaded yet)
// -----------------------------------------------------------------------------
if ( function_exists( 'icon_psy_rater_survey' ) && ! shortcode_exists( 'icon_psy_rater_survey' ) ) {
	add_shortcode( 'icon_psy_rater_survey', 'icon_psy_rater_survey' );
}
