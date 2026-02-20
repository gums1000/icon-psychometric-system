<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ------------------------------------------------------------
 * BRANDING ENGINE LOADER (critical for client portal)
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_get_client_branding' ) ) {
	// Try the common locations inside your plugin
	$try = array(
		plugin_dir_path( __FILE__ ) . 'helpers-branding.php',
		trailingslashit( dirname( __FILE__ ) ) . 'helpers-branding.php',
		trailingslashit( dirname( __FILE__, 2 ) ) . 'helpers-branding.php',
		trailingslashit( dirname( __FILE__, 3 ) ) . 'includes/shortcodes/helpers-branding.php',
	);
	foreach ( $try as $f ) {
		if ( file_exists( $f ) ) { require_once $f; break; }
	}
}

/* ------------------------------------------------------------
 * SAFETY / FALLBACK HELPERS
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_table_exists' ) ) {
	function icon_psy_table_exists( $t ) {
		global $wpdb;
		$t = (string) $t;
		return $t !== '' && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
	}
}

if ( ! function_exists( 'icon_psy_prepare_in' ) ) {
	function icon_psy_prepare_in( $sql, $ids ) {
		global $wpdb;
		$ids = array_map( 'intval', (array) $ids );
		if ( empty( $ids ) ) return $sql;
		return $wpdb->prepare(
			str_replace( '(%d)', '(' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $sql ),
			$ids
		);
	}
}

if ( ! function_exists( 'icon_psy_rand_token' ) ) {
	function icon_psy_rand_token( $len = 32 ) {
		$len = max( 16, (int) $len );
		if ( function_exists( 'wp_generate_password' ) ) return wp_generate_password( $len, false, false );
		try { return substr( bin2hex( random_bytes( (int) ceil( $len / 2 ) ) ), 0, $len ); }
		catch ( Exception $e ) { return substr( md5( uniqid( 'icon', true ) ), 0, $len ); }
	}
}

if ( ! function_exists( 'icon_psy_recent_action_guard' ) ) {
	function icon_psy_recent_action_guard( $user_id, $key, $seconds = 20 ) {
		$k    = 'icon_psy_guard_' . md5( (string) $key );
		$last = (int) get_user_meta( (int) $user_id, $k, true );
		$now  = time();
		if ( $last && ( $now - $last ) < max( 5, (int) $seconds ) ) return true;
		update_user_meta( (int) $user_id, $k, $now );
		return false;
	}
}

if ( ! function_exists( 'icon_psy_get_effective_client_user_id' ) ) {
	function icon_psy_get_effective_client_user_id() {
		$u = wp_get_current_user();
		if ( ! $u || ! $u->ID ) return 0;
		if ( current_user_can( 'manage_options' ) ) {
			$imp = (int) get_user_meta( (int) $u->ID, 'icon_psy_impersonate_client_id', true );
			if ( $imp > 0 ) return $imp;
		}
		return (int) $u->ID;
	}
}

if ( ! function_exists( 'icon_psy_user_has_role' ) ) {
	function icon_psy_user_has_role( $user, $role ) {
		return $user && isset( $user->roles ) && is_array( $user->roles ) && in_array( $role, $user->roles, true );
	}
}

if ( ! function_exists( 'icon_psy_extract_icon_cfg_from_reference' ) ) {
	function icon_psy_extract_icon_cfg_from_reference( $reference ) {
		$maybe = json_decode( (string) $reference, true );
		return is_array( $maybe ) ? $maybe : array();
	}
}

if ( ! function_exists( 'icon_psy_build_project_reference_blob' ) ) {
	function icon_psy_build_project_reference_blob( $reference, $pkg, $cfg_mode ) {
		return wp_json_encode( array( 'ref' => (string) $reference, 'icon_pkg' => (string) $pkg, 'icon_cfg' => (string) $cfg_mode ) );
	}
}

if ( ! function_exists( 'icon_psy_get_client_credit_balance' ) ) {
	function icon_psy_get_client_credit_balance( $user_id ) {
		$bal = get_user_meta( (int) $user_id, 'icon_psy_participant_credits', true );
		return ( $bal === '' || $bal === null ) ? 0 : (int) $bal;
	}
}

if ( ! function_exists( 'icon_psy_decrement_client_credits' ) ) {
	function icon_psy_decrement_client_credits( $user_id, $amount ) {
		$bal = icon_psy_get_client_credit_balance( (int) $user_id );
		if ( (int) $bal === -1 ) return true;
		return (bool) update_user_meta( (int) $user_id, 'icon_psy_participant_credits', max( 0, (int) $bal - max( 0, (int) $amount ) ) );
	}
}

/* ------------------------------------------------------------
 * BRANDING NORMALISER (fixes “branding not changing”)
 * - Accepts many key names from different engine versions
 * - Sanitises colours + logo URL
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_sanitize_hex_color_fallback' ) ) {
	function icon_psy_sanitize_hex_color_fallback( $c ) {
		$c = trim( (string) $c );
		if ( $c === '' ) return '';
		if ( function_exists( 'sanitize_hex_color' ) ) {
			$h = sanitize_hex_color( $c );
			return $h ? $h : '';
		}
		if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c ) ) return $c;
		return '';
	}
}

if ( ! function_exists( 'icon_psy_normalize_client_branding' ) ) {
	function icon_psy_normalize_client_branding( $b ) {
		$out = array( 'logo_url' => '', 'primary' => '', 'secondary' => '' );
		if ( ! is_array( $b ) ) return $out;

		$logo_keys = array( 'logo_url','logo','logoUrl','client_logo','client_logo_url','brand_logo','brand_logo_url','logo_src','logo_image','logoImage' );
		$pri_keys  = array( 'primary','primary_color','primary_colour','brand_primary','brand_primary_color','colour_primary','color_primary','icon_green','accent','accent_primary' );
		$sec_keys  = array( 'secondary','secondary_color','secondary_colour','brand_secondary','brand_secondary_color','colour_secondary','color_secondary','icon_blue','accent2','accent_secondary' );

		foreach ( $logo_keys as $k ) {
			if ( isset( $b[ $k ] ) && trim( (string) $b[ $k ] ) !== '' ) { $out['logo_url'] = trim( (string) $b[ $k ] ); break; }
		}
		foreach ( $pri_keys as $k ) {
			if ( isset( $b[ $k ] ) && trim( (string) $b[ $k ] ) !== '' ) { $out['primary'] = trim( (string) $b[ $k ] ); break; }
		}
		foreach ( $sec_keys as $k ) {
			if ( isset( $b[ $k ] ) && trim( (string) $b[ $k ] ) !== '' ) { $out['secondary'] = trim( (string) $b[ $k ] ); break; }
		}

		if ( ( $out['primary'] === '' || $out['secondary'] === '' ) && isset( $b['colors'] ) && is_array( $b['colors'] ) ) {
			if ( $out['primary'] === '' && ! empty( $b['colors']['primary'] ) )     $out['primary']   = (string) $b['colors']['primary'];
			if ( $out['secondary'] === '' && ! empty( $b['colors']['secondary'] ) ) $out['secondary'] = (string) $b['colors']['secondary'];
		}

		if ( $out['logo_url'] ) $out['logo_url'] = esc_url_raw( $out['logo_url'] );
		$hp = icon_psy_sanitize_hex_color_fallback( $out['primary'] );
		$hs = icon_psy_sanitize_hex_color_fallback( $out['secondary'] );
		$out['primary']   = $hp ? $hp : '';
		$out['secondary'] = $hs ? $hs : '';

		return $out;
	}
}

// Helper: extract pkg key from a project row (checks icon_pkg column, then reference blob)
if ( ! function_exists( 'icon_psy_get_project_pkg' ) ) {
	function icon_psy_get_project_pkg( $project_row, $has_reference = false ) {
		if ( ! $project_row ) return '';
		$pkg = '';
		if ( isset( $project_row->icon_pkg ) && (string) $project_row->icon_pkg !== '' ) {
			$pkg = (string) $project_row->icon_pkg;
		} elseif ( $has_reference && ! empty( $project_row->reference ) ) {
			$cfg = icon_psy_extract_icon_cfg_from_reference( (string) $project_row->reference );
			if ( isset( $cfg['icon_pkg'] ) ) $pkg = (string) $cfg['icon_pkg'];
		}
		return strtolower( trim( $pkg ) );
	}
}

if ( ! function_exists( 'icon_psy_project_is_leadership_project' ) ) {
	function icon_psy_project_is_leadership_project( $p, $hr = false ) {
		return icon_psy_get_project_pkg( $p, $hr ) === 'leadership_assessment';
	}
}

if ( ! function_exists( 'icon_psy_project_is_profiler_project' ) ) {
	function icon_psy_project_is_profiler_project( $project_row, $frameworks_table ) {
		if ( ! $project_row ) return false;
		$fid = isset( $project_row->framework_id ) ? (int) $project_row->framework_id : 0;
		if ( $fid <= 0 ) return false;
		global $wpdb;
		return strtolower( trim( (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$frameworks_table} WHERE id=%d", $fid ) ) ) ) === 'icon profiler';
	}
}

if ( ! function_exists( 'icon_psy_project_is_teams_project' ) ) {
	function icon_psy_project_is_teams_project( $p, $hr = false ) {
		return in_array( icon_psy_get_project_pkg( $p, $hr ), array( 'high_performing_teams', 'teams_cohorts', 'teams', 'team', 'hpt' ), true );
	}
}

if ( ! function_exists( 'icon_psy_project_is_traits_project' ) ) {
	function icon_psy_project_is_traits_project( $p, $hr = false ) {
		return icon_psy_get_project_pkg( $p, $hr ) === 'aaet_internal';
	}
}

if ( ! function_exists( 'icon_psy_create_post_invite_for_rater' ) ) {
	function icon_psy_create_post_invite_for_rater( $raters_table, $baseline_rater_id, $token_len = 32 ) {
		global $wpdb;
		$baseline_rater_id = (int) $baseline_rater_id;
		if ( $baseline_rater_id <= 0 ) return 0;

		$base = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$raters_table} WHERE id=%d LIMIT 1", $baseline_rater_id ) );
		if ( ! $base ) return 0;

		$phase = isset( $base->phase ) ? sanitize_key( (string) $base->phase ) : 'baseline';
		if ( $phase === 'post' ) {
			if ( empty( $base->baseline_rater_id ) ) return 0;
			$baseline_rater_id = (int) $base->baseline_rater_id;
			$base = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$raters_table} WHERE id=%d LIMIT 1", $baseline_rater_id ) );
			if ( ! $base ) return 0;
		}

		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$raters_table} WHERE baseline_rater_id=%d AND phase='post' LIMIT 1", $baseline_rater_id ) );
		if ( $existing > 0 ) return $existing;

		$pair_key = isset( $base->pair_key ) ? (string) $base->pair_key : '';
		if ( $pair_key === '' ) {
			$seed = (int) $base->participant_id . '|' . strtolower( (string) ( $base->email ?? '' ) ) . '|' . strtolower( (string) ( $base->relationship ?? '' ) );
			$pair_key = substr( hash( 'sha256', $seed ), 0, 48 );
			$wpdb->update( $raters_table, array( 'pair_key' => $pair_key, 'phase' => 'baseline' ), array( 'id' => $baseline_rater_id ), array( '%s', '%s' ), array( '%d' ) );
		}

		$token   = icon_psy_rand_token( $token_len );
		$expires = gmdate( 'Y-m-d H:i:s', time() + 14 * DAY_IN_SECONDS );

		$ok = $wpdb->insert( $raters_table, array(
			'participant_id'    => (int) $base->participant_id,
			'name'              => (string) ( $base->name ?? '' ),
			'email'             => (string) ( $base->email ?? '' ),
			'relationship'      => (string) ( $base->relationship ?? '' ),
			'status'            => 'pending',
			'created_at'        => current_time( 'mysql' ),
			'phase'             => 'post',
			'pair_key'          => $pair_key,
			'baseline_rater_id' => (int) $baseline_rater_id,
			'token'             => $token,
			'invite_token'      => $token,
			'token_expires_at'  => $expires,
			'invite_sent_at'    => null,
		), array( '%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s' ) );

		return $ok !== false ? (int) $wpdb->insert_id : 0;
	}
}

if ( ! function_exists( 'icon_psy_package_credit_rules' ) ) {
	function icon_psy_package_credit_rules() {
		return array(
			'tool_only'             => array( 'mode' => 'per_participant', 'cost' => 1 ),
			'leadership_assessment' => array( 'mode' => 'per_participant', 'cost' => 1 ),
			'feedback_360'          => array( 'mode' => 'per_participant', 'cost' => 2 ),
			'bundle_debrief'        => array( 'mode' => 'per_participant', 'cost' => 4 ),
			'full_package'          => array( 'mode' => 'per_participant', 'cost' => 8 ),
			'high_performing_teams' => array( 'mode' => 'per_project',    'cost' => 2 ),
			'aaet_internal'         => array( 'mode' => 'per_participant', 'cost' => 1 ),
		);
	}
}

if ( ! function_exists( 'icon_psy_get_project_credit_rule' ) ) {
	function icon_psy_get_project_credit_rule( $project_row ) {
		if ( ! is_object( $project_row ) ) return null;
		$rules = icon_psy_package_credit_rules();
		$pkg   = icon_psy_get_project_pkg( $project_row, true );
		return ( $pkg && isset( $rules[ $pkg ] ) ) ? $rules[ $pkg ] : null;
	}
}

if ( ! function_exists( 'icon_psy_should_charge_for_new_participant' ) ) {
	function icon_psy_should_charge_for_new_participant( $mode, $existing ) {
		if ( $mode === 'per_participant' ) return true;
		if ( $mode === 'per_project' )    return ( (int) $existing === 0 );
		return false;
	}
}

/* ------------------------------------------------------------
 * EMAIL-ONLY PUBLIC SHARE LINKS (no login required)
 * - Creates expiring tokens stored in wp_options
 * - When a valid token is present, we temporarily log the visitor
 *   in as a low-privilege “share viewer” user for this request only
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_get_or_create_share_viewer_user_id' ) ) {
	function icon_psy_get_or_create_share_viewer_user_id() {
		$opt_key = 'icon_psy_share_viewer_user_id';
		$uid = (int) get_option( $opt_key, 0 );
		if ( $uid > 0 && get_user_by( 'id', $uid ) ) return $uid;

		$login = 'icon_share_viewer';
		$u = get_user_by( 'login', $login );
		if ( $u && $u->ID ) {
			update_option( $opt_key, (int) $u->ID, false );
			return (int) $u->ID;
		}

		$pass = wp_generate_password( 32, true, true );
		$new_id = wp_create_user( $login, $pass, $login . '@invalid.local' );
		if ( is_wp_error( $new_id ) ) return 0;

		$nu = get_user_by( 'id', (int) $new_id );
		if ( $nu && ! is_wp_error( $nu ) ) {
			$nu->set_role( 'subscriber' );
			update_option( $opt_key, (int) $new_id, false );
			return (int) $new_id;
		}
		return 0;
	}
}

if ( ! function_exists( 'icon_psy_share_store' ) ) {
	function icon_psy_share_store( $token, $payload ) {
		$k = 'icon_psy_share_' . preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );
		return update_option( $k, wp_json_encode( $payload ), false );
	}
}
if ( ! function_exists( 'icon_psy_share_load' ) ) {
	function icon_psy_share_load( $token ) {
		$k = 'icon_psy_share_' . preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );
		$raw = get_option( $k, '' );
		$pay = json_decode( (string) $raw, true );
		return is_array( $pay ) ? $pay : null;
	}
}
if ( ! function_exists( 'icon_psy_share_delete' ) ) {
	function icon_psy_share_delete( $token ) {
		$k = 'icon_psy_share_' . preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );
		return delete_option( $k );
	}
}

if ( ! function_exists( 'icon_psy_create_email_share_link' ) ) {
	/**
	 * Creates an expiring “email share” link for a report.
	 * $url_base should be the report page URL (e.g. /feedback-report/).
	 * $expires_days is in DAYS (default 14).
	 */
	function icon_psy_create_email_share_link( $url_base, $project_id, $participant_id, $report_type, $expires_days = 14 ) {
		$token       = icon_psy_rand_token( 40 );
		$expires_days = max( 1, (int) $expires_days );
		$exp         = time() + ( $expires_days * DAY_IN_SECONDS );

		$payload = array(
			'project_id'     => (int) $project_id,
			'participant_id' => (int) $participant_id,
			'report_type'    => sanitize_key( (string) $report_type ),
			'expires'        => (int) $exp,
		);

		icon_psy_share_store( $token, $payload );

		// IMPORTANT: include participant_id (and project_id/report_type) so the report knows what to render.
		return add_query_arg(
			array(
				'icon_share'     => $token,
				'participant_id' => (int) $participant_id,
				'project_id'     => (int) $project_id,
				'report_type'    => sanitize_key( (string) $report_type ),
			),
			$url_base
		);
	}
}

if ( ! function_exists( 'icon_psy_public_share_payload_from_request' ) ) {
	function icon_psy_public_share_payload_from_request() {
		if ( empty( $_GET['icon_share'] ) ) return null;
		$token = sanitize_text_field( wp_unslash( $_GET['icon_share'] ) );
		if ( $token === '' ) return null;

		$pay = icon_psy_share_load( $token );
		if ( ! is_array( $pay ) ) return null;

		$exp = isset( $pay['expires'] ) ? (int) $pay['expires'] : 0;
		if ( $exp > 0 && time() > $exp ) {
			icon_psy_share_delete( $token );
			return null;
		}
		return $pay;
	}
}

/**
 * IMPORTANT:
 * This must run BEFORE WP decides the current user.
 * Do NOT put this inside init.
 */
add_filter( 'determine_current_user', function( $user_id ) {

	if ( ! empty( $user_id ) ) return $user_id;

	$pay = icon_psy_public_share_payload_from_request();
	if ( ! is_array( $pay ) ) return $user_id;

	// ✅ FIX: Get the path without query string - more robust parsing
	$req_uri = $_SERVER['REQUEST_URI'] ?? '';
	$req_path = (string) ( parse_url( home_url( $req_uri ), PHP_URL_PATH ) ?: '' );

	$allowed = array(
		'/feedback-report', '/feedback-report/',
		'/team-report', '/team-report/',
		'/traits-report', '/traits-report/',

		// ✅ Profiler routes (add all variants you might hit)
		'/icon-profiler-report', '/icon-profiler-report/',
		'/profiler-report', '/profiler-report/',
		'/profiler-report.php', '/icon-profiler-report.php',
	);

	$ok = false;
	foreach ( $allowed as $p ) {
		// ✅ FIX: Check if path contains this segment (more reliable)
		if ( strpos( $req_path, $p ) !== false ) { 
			$ok = true; 
			break; 
		}
	}
	if ( ! $ok ) return $user_id;

	$GLOBALS['icon_psy_public_share'] = $pay;

	$viewer = icon_psy_get_or_create_share_viewer_user_id();
	return $viewer > 0 ? $viewer : $user_id;

}, 1 );

/* ------------------------------------------------------------
 * Main shortcode
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_client_portal_shortcode' ) ) {

	function icon_psy_client_portal_shortcode( $atts ) {

		if ( ! is_user_logged_in() ) {
			$page_url = function_exists( 'get_permalink' ) ? get_permalink() : home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ?? '' ) );
			ob_start(); ?>
			<style>
				:root{--icon-green:#15a06d;--icon-blue:#14a4cf;--text-dark:#0a3b34;--text-mid:#425b56;--ink:#071b1a;}
				.icon-login-shell{padding:34px 16px 44px;background:radial-gradient(circle at top left,#e6f9ff 0%,#ffffff 40%,#e9f8f1 100%);}
				.icon-login-wrap{max-width:860px;margin:0 auto;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--text-dark);}
				.icon-rail{height:4px;border-radius:999px;background:linear-gradient(90deg,var(--icon-blue),var(--icon-green));opacity:.85;margin:0 0 12px;box-shadow:0 10px 24px rgba(20,164,207,.18);}
				.icon-card{background:#fff;border:1px solid rgba(20,164,207,.14);border-radius:22px;box-shadow:0 16px 40px rgba(0,0,0,.06);padding:18px;position:relative;overflow:hidden;}
				.icon-h1{margin:10px 0 6px;font-size:24px;font-weight:950;letter-spacing:-.02em;color:var(--ink);}
				.icon-sub{margin:0;color:var(--text-mid);font-size:13px;max-width:720px;}
				.icon-btn-ghost{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#fff;border:1px solid rgba(21,149,136,.35);color:var(--icon-green);padding:10px 14px;font-size:13px;font-weight:950;cursor:pointer;text-decoration:none;white-space:nowrap;}
				.icon-btn-ghost:hover{background-image:linear-gradient(135deg,var(--icon-blue),var(--icon-green));color:#fff;border-color:transparent;box-shadow:0 12px 30px rgba(20,164,207,.30);transform:translateY(-1px);}
				.icon-login-form-wrap{margin-top:14px;border-top:1px solid rgba(226,232,240,.9);padding-top:14px;}
				.icon-login-form-wrap input[type="text"],.icon-login-form-wrap input[type="password"]{width:100%;border-radius:14px;border:1px solid rgba(148,163,184,.55);padding:10px 12px;font-size:13px;color:var(--text-dark);background:#fff;box-sizing:border-box;}
				.icon-login-form-wrap label{font-weight:800;font-size:12px;color:#64748b;}
				.icon-login-form-wrap p{margin:10px 0;}
				.icon-login-form-wrap input[type="submit"],.icon-btn{border-radius:999px;border:1px solid transparent;background-image:linear-gradient(135deg,var(--icon-blue),var(--icon-green));color:#fff;padding:10px 14px;font-size:13px;font-weight:950;cursor:pointer;white-space:nowrap;box-shadow:0 12px 30px rgba(20,164,207,.30);display:inline-flex;align-items:center;justify-content:center;text-decoration:none;}
			</style>
			<div class="icon-login-shell"><div class="icon-login-wrap">
				<div class="icon-rail"></div>
				<div class="icon-card">
					<h1 class="icon-h1">Icon Catalyst client access</h1>
					<p class="icon-sub">Please log in to manage projects, participants, and rater invites.</p>
					<div class="icon-login-form-wrap">
						<?php if ( function_exists( 'wp_login_form' ) ) {
							wp_login_form( array( 'echo' => true, 'remember' => true, 'redirect' => $page_url,
								'form_id' => 'icon_psy_client_loginform', 'label_username' => 'Email / Username',
								'label_password' => 'Password', 'label_remember' => 'Remember me', 'label_log_in' => 'Log in' ) );
						} else { echo '<p>Please log in.</p>'; } ?>
					</div>
					<div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
						<a class="icon-btn-ghost" href="<?php echo esc_url( wp_lostpassword_url( $page_url ) ); ?>">Forgot password?</a>
						<a class="icon-btn" href="https://icon-talent.org">← Back to Icon Talent</a>
					</div>
				</div>
			</div></div>
			<?php return ob_get_clean();
		}

		// Logged-in portal
		global $wpdb;

		$rater_page_url           = home_url( '/rater-portal/' );
		$team_page_url            = home_url( '/team-survey/' );
		$report_page_url          = home_url( '/feedback-report/' );
		$profiler_report_page_url = home_url( '/icon-profiler-report/' );
		$traits_report_page_url   = home_url( '/traits-report/' );
		$traits_survey_page_url   = home_url( '/rater-survey-traits/' );
		$action_plan_page_url     = home_url( '/action-plan/' );
		$company_report_page_url  = home_url( '/company-report/' );
		$team_survey_page_url     = home_url( '/team-survey/' );
		$team_report_page_url     = home_url( '/team-report/' );

		$current_user = wp_get_current_user();
		$real_user_id = (int) $current_user->ID;
		$is_admin     = current_user_can( 'manage_options' );

		$page_url = remove_query_arg(
			array( 'icon_psy_msg','icon_psy_err','icon_open_project','icon_open_participant','icon_open_add_rater','icon_open_add_participant' ),
			function_exists( 'get_permalink' ) ? get_permalink() : home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ?? '' ) )
		);

		$notice = isset( $_GET['icon_psy_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['icon_psy_msg'] ) ) : '';
		$error  = isset( $_GET['icon_psy_err'] ) ? sanitize_text_field( wp_unslash( $_GET['icon_psy_err'] ) ) : '';

		// Stop impersonation
		if ( $is_admin && isset( $_GET['icon_psy_impersonate'] ) && $_GET['icon_psy_impersonate'] === 'stop'
			&& isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'icon_psy_impersonate' ) ) {
			delete_user_meta( $real_user_id, 'icon_psy_impersonate_client' );
			delete_user_meta( $real_user_id, 'icon_psy_impersonate_client_id' );
			wp_safe_redirect( home_url( '/management-portal/' ) );
			exit;
		}

		if ( ! $is_admin ) {
			delete_user_meta( $real_user_id, 'icon_psy_impersonate_client' );
			delete_user_meta( $real_user_id, 'icon_psy_impersonate_client_id' );
		}

		$effective_user_id = (int) icon_psy_get_effective_client_user_id();
		$effective_user    = $effective_user_id ? get_user_by( 'id', $effective_user_id ) : null;

		if ( $is_admin ) {
			if ( ! $effective_user || ! icon_psy_user_has_role( $effective_user, 'icon_client' ) )
				return '<p><strong>Admin:</strong> Please select a client to impersonate from the Management Portal first.</p>';
		} elseif ( ! icon_psy_user_has_role( $current_user, 'icon_client' ) ) {
			return '<p>You do not have permission to view this portal.</p>';
		}

		$user_id = $is_admin ? $effective_user_id : $real_user_id;

		// Branding (robust normalisation)
		$branding = array( 'logo_url' => '', 'primary' => '#15a06d', 'secondary' => '#14a4cf' );

		if ( function_exists( 'icon_psy_get_client_branding' ) ) {
			$b = icon_psy_get_client_branding( (int) $user_id );
			$norm = icon_psy_normalize_client_branding( $b );
			if ( ! empty( $norm['logo_url'] ) )  $branding['logo_url']  = $norm['logo_url'];
			if ( ! empty( $norm['primary'] ) )   $branding['primary']   = $norm['primary'];
			if ( ! empty( $norm['secondary'] ) ) $branding['secondary'] = $norm['secondary'];
		} else {
			$meta_logo = get_user_meta( (int) $user_id, 'icon_psy_brand_logo', true );
			$meta_p    = get_user_meta( (int) $user_id, 'icon_psy_brand_primary', true );
			$meta_s    = get_user_meta( (int) $user_id, 'icon_psy_brand_secondary', true );
			$norm = icon_psy_normalize_client_branding( array(
				'logo_url'  => $meta_logo,
				'primary'   => $meta_p,
				'secondary' => $meta_s,
			) );
			if ( ! empty( $norm['logo_url'] ) )  $branding['logo_url']  = $norm['logo_url'];
			if ( ! empty( $norm['primary'] ) )   $branding['primary']   = $norm['primary'];
			if ( ! empty( $norm['secondary'] ) ) $branding['secondary'] = $norm['secondary'];
		}

		$brand_primary   = $branding['primary']   ?: '#15a06d';
		$brand_secondary = $branding['secondary'] ?: '#14a4cf';

		$brand_logo_url  = ! empty( $branding['logo_url'] ) ? esc_url_raw( $branding['logo_url'] ) : '';
		$wm_logo_url     = $brand_logo_url;
		$top_right_logo_url = $brand_logo_url;

		// Tables
		$projects_table     = $wpdb->prefix . 'icon_psy_projects';
		$participants_table = $wpdb->prefix . 'icon_psy_participants';
		$raters_table       = $wpdb->prefix . 'icon_psy_raters';
		$frameworks_table   = $wpdb->prefix . 'icon_psy_frameworks';

		foreach ( array( $projects_table, $participants_table, $raters_table, $frameworks_table ) as $t ) {
			if ( ! icon_psy_table_exists( $t ) )
				return '<p><strong>Portal setup issue:</strong> missing table <code>' . esc_html( $t ) . '</code>.</p>';
		}

		// Ensure required columns exist
		$projects_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$projects_table}", 0 );
		$alter_map = array(
			'course_id' => "ALTER TABLE {$projects_table} ADD COLUMN course_id BIGINT(20) UNSIGNED NULL",
			'icon_pkg'  => "ALTER TABLE {$projects_table} ADD COLUMN icon_pkg VARCHAR(64) NULL",
			'icon_cfg'  => "ALTER TABLE {$projects_table} ADD COLUMN icon_cfg VARCHAR(32) NULL",
		);
		foreach ( $alter_map as $col => $sql ) {
			if ( ! in_array( $col, $projects_cols, true ) ) $wpdb->query( $sql ); // phpcs:ignore
		}
		$projects_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$projects_table}", 0 );

		$has_course_id        = in_array( 'course_id',   $projects_cols, true );
		$has_client_user_id   = in_array( 'client_user_id', $projects_cols, true );
		$has_reference        = in_array( 'reference',   $projects_cols, true );
		$has_framework_id     = in_array( 'framework_id', $projects_cols, true );
		$has_created_by       = in_array( 'created_by',  $projects_cols, true );
		$has_status           = in_array( 'status',      $projects_cols, true );
		$has_created_at       = in_array( 'created_at',  $projects_cols, true );
		$has_client_name_col  = in_array( 'client_name', $projects_cols, true );
		$has_icon_pkg         = in_array( 'icon_pkg',    $projects_cols, true );
		$has_icon_cfg         = in_array( 'icon_cfg',    $projects_cols, true );

		if ( ! $has_client_user_id )
			return '<p><strong>Portal setup issue:</strong> projects table is missing <code>client_user_id</code>.</p>';

		$frameworks = $wpdb->get_results( "SELECT id, name FROM {$frameworks_table} ORDER BY name ASC" );

		// Courses
		$courses = array();
		if ( function_exists( 'icon_psy_course_engine_get_courses_for_client' ) ) {
			$courses = icon_psy_course_engine_get_courses_for_client( (int) $user_id, array( 'status' => 'active', 'limit' => 200 ) );
		} else {
			$ct = $wpdb->prefix . 'icon_psy_courses';
			$courses = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ct} WHERE client_user_id=%d AND status='active' ORDER BY id DESC", (int) $user_id ) );
		}

		// Rater table columns
		$raters_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$raters_table}", 0 );
		$rater_alters = array(
			'token'             => "ALTER TABLE {$raters_table} ADD COLUMN token VARCHAR(64) NULL",
			'invite_token'      => "ALTER TABLE {$raters_table} ADD COLUMN invite_token VARCHAR(64) NULL",
			'token_expires_at'  => "ALTER TABLE {$raters_table} ADD COLUMN token_expires_at DATETIME NULL",
			'invite_sent_at'    => "ALTER TABLE {$raters_table} ADD COLUMN invite_sent_at DATETIME NULL",
			'phase'             => "ALTER TABLE {$raters_table} ADD COLUMN phase VARCHAR(20) NOT NULL DEFAULT 'baseline'",
			'pair_key'          => "ALTER TABLE {$raters_table} ADD COLUMN pair_key VARCHAR(64) NOT NULL DEFAULT ''",
			'baseline_rater_id' => "ALTER TABLE {$raters_table} ADD COLUMN baseline_rater_id BIGINT(20) UNSIGNED NULL",
		);
		foreach ( $rater_alters as $col => $sql ) {
			if ( ! in_array( $col, $raters_cols, true ) ) $wpdb->query( $sql ); // phpcs:ignore
		}
		$raters_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$raters_table}", 0 );

		$has_token_col        = in_array( 'token',           $raters_cols, true );
		$has_invite_token_col = in_array( 'invite_token',    $raters_cols, true );
		$has_expires_col      = in_array( 'token_expires_at', $raters_cols, true );
		$has_sent_col         = in_array( 'invite_sent_at',  $raters_cols, true );

		// Packages
		$packages_off_shelf = array(
			'tool_only'             => array( 'title' => 'Tool only (Self administered 360 feedback)',     'price' => 'From £40 per person' ),
			'leadership_assessment' => array( 'title' => 'Leadership Assessment / Icon Profiler',          'price' => 'From £45 per person' ),
			'feedback_360'          => array( 'title' => '360 feedback',                                   'price' => 'From £95 per person' ),
			'bundle_debrief'        => array( 'title' => '360 plus coaching debrief',                      'price' => 'From £195 per person' ),
			'full_package'          => array( 'title' => 'Full Catalyst package',                          'price' => 'From £395 per person' ),
			'high_performing_teams' => array( 'title' => 'High Performing Teams assessment',               'price' => 'From £75 per team' ),
			'teams_cohorts'         => array( 'title' => 'Teams and leadership cohorts',                   'price' => 'Custom pricing' ),
			'subscription_50'       => array( 'title' => 'Subscription (Yearly 50 fully managed reports)', 'price' => '£2,225 per year' ),
			'aaet_internal'         => array( 'title' => 'Internal',                                       'price' => 'Internal' ),
		);
		$packages_custom = array(
			'custom_lite' => array( 'title' => 'Custom Lite', 'price' => '£4,500 to £6,500' ),
			'custom_plus' => array( 'title' => 'Custom Plus', 'price' => '£7,500 to £12,500' ),
			'enterprise'  => array( 'title' => 'Enterprise',  'price' => '£15,000+' ),
		);
		$all_packages = array_merge( $packages_off_shelf, $packages_custom );

		$icon_credit_unit_price_gbp = 50;

		$check_nonce = fn( $action, $field ) =>
			isset( $_POST[ $field ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ), $action );

		$prg_redirect = function( $msg = '', $err = '', $extra_args = array() ) use ( $page_url ) {
			$url  = remove_query_arg( array( 'icon_psy_msg','icon_psy_err','icon_open_project','icon_open_participant','icon_open_add_rater','icon_open_add_participant' ), $page_url );
			$args = array_filter( array_merge(
				$msg !== '' ? array( 'icon_psy_msg' => $msg ) : array(),
				$err !== '' ? array( 'icon_psy_err' => $err ) : array(),
				array_filter( (array) $extra_args, fn( $v ) => $v !== null && $v !== '' )
			) );
			if ( ! empty( $args ) ) $url = add_query_arg( $args, $url );
			wp_safe_redirect( $url );
			exit;
		};

		// Rater token update helper
		$update_rater_token = function( $rater_id, $token, $expires, $status = 'invited' ) use ( $wpdb, $raters_table, $has_token_col, $has_invite_token_col, $has_expires_col, $has_sent_col ) {
			$u = array( 'status' => $status );
			$f = array( '%s' );
			if ( $has_token_col )        { $u['token']            = $token;   $f[] = '%s'; }
			if ( $has_invite_token_col ) { $u['invite_token']     = $token;   $f[] = '%s'; }
			if ( $has_expires_col )      { $u['token_expires_at'] = $expires; $f[] = '%s'; }
			if ( $has_sent_col )         { $u['invite_sent_at']   = current_time( 'mysql' ); $f[] = '%s'; }
			$wpdb->update( $raters_table, $u, array( 'id' => (int) $rater_id ), $f, array( '%d' ) );
		};

		$credit_balance_now = icon_psy_get_client_credit_balance( $user_id );

		/* --------------------------------------------------------
		 * POST handlers (PRG)
		 * -------------------------------------------------------- */
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['icon_psy_client_action'] ) ) {

			$action = sanitize_key( wp_unslash( $_POST['icon_psy_client_action'] ) );

			// ADD COURSE
			if ( 'add_course' === $action && $check_nonce( 'icon_psy_add_course', 'icon_psy_add_course_nonce' ) ) {
				$title = isset( $_POST['course_title'] ) ? sanitize_text_field( wp_unslash( $_POST['course_title'] ) ) : '';
				if ( trim( $title ) === '' ) $prg_redirect( '', 'Course title is required.', array( 'icon_open_create' => 1, 'icon_open_course' => 1 ) );

				$obj      = isset( $_POST['course_objectives'] ) ? wp_kses_post( wp_unslash( $_POST['course_objectives'] ) ) : '';
				$has_pre  = ! empty( $_POST['course_has_pre'] )  ? 1 : 0;
				$has_post = ! empty( $_POST['course_has_post'] ) ? 1 : 0;
				$new_id   = 0;

				if ( function_exists( 'icon_psy_course_engine_create_course' ) ) {
					$new_id = (int) icon_psy_course_engine_create_course( (int) $user_id, $title, $obj, 'traits', $has_pre, $has_post );
				} else {
					$ct = $wpdb->prefix . 'icon_psy_courses';
					$ok = $wpdb->insert( $ct, array( 'client_user_id' => (int) $user_id, 'title' => $title, 'objectives' => $obj,
						'assessment_type' => 'traits', 'has_pre' => $has_pre, 'has_post' => $has_post,
						'status' => 'active', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ),
						array( '%d','%s','%s','%s','%d','%d','%s','%s','%s' ) );
					if ( $ok !== false ) $new_id = (int) $wpdb->insert_id;
				}

				if ( $new_id <= 0 ) $prg_redirect( '', 'Failed to create course. Please try again.', array( 'icon_open_create' => 1, 'icon_open_course' => 1 ) );
				$prg_redirect( 'Course created.', '', array( 'icon_open_create' => 1, 'icon_course_id' => $new_id ) );
			}

			// ADD PROJECT
			if ( 'add_project' === $action && $check_nonce( 'icon_psy_add_project', 'icon_psy_add_project_nonce' ) ) {
				$project_name = isset( $_POST['project_name'] ) ? sanitize_text_field( wp_unslash( $_POST['project_name'] ) ) : '';
				if ( $project_name === '' ) $prg_redirect( '', 'Project name is required.' );

				$client_name  = isset( $_POST['client_name'] ) ? sanitize_text_field( wp_unslash( $_POST['client_name'] ) ) : '';
				$reference    = isset( $_POST['project_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['project_reference'] ) ) : '';
				$framework_id = isset( $_POST['framework_id'] ) ? (int) $_POST['framework_id'] : 0;
				$config_mode  = ( isset( $_POST['icon_config_mode'] ) && sanitize_key( wp_unslash( $_POST['icon_config_mode'] ) ) === 'custom' ) ? 'custom' : 'off_shelf';
				$package_key  = isset( $_POST['icon_package_key'] ) ? sanitize_key( wp_unslash( $_POST['icon_package_key'] ) ) : 'tool_only';
				if ( ! array_key_exists( $package_key, $all_packages ) ) $package_key = 'tool_only';

				$data = array( 'name' => $project_name, 'client_user_id' => $user_id );
				$fmts = array( '%s', '%d' );
				if ( $has_icon_pkg )        { $data['icon_pkg']      = $package_key;                              $fmts[] = '%s'; }
				if ( $has_icon_cfg )        { $data['icon_cfg']      = $config_mode;                              $fmts[] = '%s'; }
				if ( $has_client_name_col ) { $data['client_name']   = $client_name;                              $fmts[] = '%s'; }
				if ( $has_created_by )      { $data['created_by']    = $user_id;                                  $fmts[] = '%d'; }
				if ( $has_status )          { $data['status']        = 'active';                                  $fmts[] = '%s'; }
				if ( $has_created_at )      { $data['created_at']    = current_time( 'mysql' );                   $fmts[] = '%s'; }
				if ( $has_framework_id )    { $data['framework_id']  = $config_mode === 'custom' ? 0 : (int) $framework_id; $fmts[] = '%d'; }
				if ( $has_reference )       { $data['reference']     = icon_psy_build_project_reference_blob( $reference, $package_key, $config_mode ); $fmts[] = '%s'; }

				$ok = $wpdb->insert( $projects_table, $data, $fmts );
				if ( false === $ok ) $prg_redirect( '', $wpdb->last_error ?: 'Database insert failed.' );
				$prg_redirect( 'Project created. ID: ' . (int) $wpdb->insert_id, '' );
			}

			// ADD PARTICIPANT
			if ( 'add_participant' === $action && $check_nonce( 'icon_psy_add_participant', 'icon_psy_add_participant_nonce' ) ) {
				$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
				$owns       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$projects_table} WHERE id=%d AND client_user_id=%d", $project_id, $user_id ) );
				if ( $project_id <= 0 || ! $owns ) $prg_redirect( '', 'Invalid project.' );

				$project_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$projects_table} WHERE id=%d AND client_user_id=%d", $project_id, $user_id ) );
				$existing    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$participants_table} WHERE project_id=%d", $project_id ) );
				$rule        = $project_row ? icon_psy_get_project_credit_rule( $project_row ) : null;
				$mode        = is_array( $rule ) ? (string) $rule['mode'] : '';
				$cost        = is_array( $rule ) ? (int) $rule['cost'] : 0;
				$should_charge = $mode && $cost > 0 && icon_psy_should_charge_for_new_participant( $mode, $existing );
				$bal         = icon_psy_get_client_credit_balance( $user_id );

				if ( $should_charge && (int) $bal !== -1 && max( 0, (int) $bal ) < $cost ) {
					$prg_redirect( '', 'Insufficient credits for this package. Please purchase more credits to add a participant.', array( 'icon_open_project' => $project_id, 'icon_open_add_participant' => 1 ) );
				}

				$name  = isset( $_POST['participant_name'] ) ? sanitize_text_field( wp_unslash( $_POST['participant_name'] ) ) : '';
				$email = isset( $_POST['participant_email'] ) ? sanitize_email( wp_unslash( $_POST['participant_email'] ) ) : '';
				$role  = isset( $_POST['participant_role'] ) ? sanitize_text_field( wp_unslash( $_POST['participant_role'] ) ) : '';
				if ( $name === '' ) $prg_redirect( '', 'Participant name is required.', array( 'icon_open_project' => $project_id, 'icon_open_add_participant' => 1 ) );

				$ok = $wpdb->insert( $participants_table, array( 'project_id' => $project_id, 'name' => $name, 'email' => $email, 'role' => $role, 'status' => 'pending', 'created_at' => current_time( 'mysql' ) ), array( '%d','%s','%s','%s','%s','%s' ) );
				if ( false === $ok ) $prg_redirect( '', $wpdb->last_error ?: 'Database insert failed.', array( 'icon_open_project' => $project_id, 'icon_open_add_participant' => 1 ) );

				$inserted_pid = (int) $wpdb->insert_id;

				// Auto self-rater for Leadership Assessment
				if ( $project_row && icon_psy_project_is_leadership_project( $project_row, $has_reference ) ) {
					$already = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$raters_table} WHERE participant_id=%d AND (relationship='Self' OR relationship='self')", $inserted_pid ) );
					if ( ! $already ) {
						$st = icon_psy_rand_token();
						$se = gmdate( 'Y-m-d H:i:s', time() + 14 * DAY_IN_SECONDS );
						$su = array( 'participant_id' => $inserted_pid, 'name' => $name, 'email' => $email, 'relationship' => 'Self', 'status' => 'invited', 'created_at' => current_time( 'mysql' ) );
						$sf = array( '%d','%s','%s','%s','%s','%s' );
						if ( $has_token_col )        { $su['token']            = $st; $sf[] = '%s'; }
						if ( $has_invite_token_col ) { $su['invite_token']     = $st; $sf[] = '%s'; }
						if ( $has_expires_col )      { $su['token_expires_at'] = $se; $sf[] = '%s'; }
						if ( $has_sent_col )         { $su['invite_sent_at']   = current_time( 'mysql' ); $sf[] = '%s'; }
						$wpdb->insert( $raters_table, $su, $sf );
					}
				}

				$credits_used = 0;
				if ( $should_charge && (int) $bal !== -1 && $cost > 0 ) {
					$credits_used = $cost;
					if ( ! icon_psy_decrement_client_credits( $user_id, $credits_used ) )
						$prg_redirect( 'Participant added, but credit balance could not be updated. Please contact support.', '' );
				}

				$credit_note = $should_charge
					? ( (int) $bal === -1 ? ' (Unlimited plan — no credits deducted)' : " ({$credits_used} credit" . ( $credits_used === 1 ? '' : 's' ) . ' used)' )
					: ' (No credits used)';
				$prg_redirect( 'Participant added.' . $credit_note, '', array( 'icon_open_project' => $project_id, 'icon_open_add_participant' => 1 ) );
			}

			// CREATE POST INVITE (Traits)
			if ( 'create_post_invite' === $action && $check_nonce( 'icon_psy_create_post_invite', 'icon_psy_create_post_invite_nonce' ) ) {
				$bid   = isset( $_POST['baseline_rater_id'] ) ? (int) $_POST['baseline_rater_id'] : 0;
				if ( $bid <= 0 ) $prg_redirect( '', 'Invalid baseline rater.' );
				$owns  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$raters_table} r INNER JOIN {$participants_table} p ON p.id=r.participant_id INNER JOIN {$projects_table} pr ON pr.id=p.project_id WHERE r.id=%d AND pr.client_user_id=%d", $bid, $user_id ) );
				if ( ! $owns ) $prg_redirect( '', 'You do not own this rater.' );
				$new_post_id = icon_psy_create_post_invite_for_rater( $raters_table, $bid, 32 );
				if ( $new_post_id <= 0 ) $prg_redirect( '', 'Failed to create post invite. Please check database permissions/columns.' );
				$part_id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT participant_id FROM {$raters_table} WHERE id=%d", $new_post_id ) );
				$proj_id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT project_id FROM {$participants_table} WHERE id=%d", $part_id ) );
				$prg_redirect( 'Post-course invite created. You can now send it.', '', array( 'icon_open_project' => $proj_id, 'icon_open_participant' => $part_id, 'icon_open_add_rater' => 1 ) );
			}

			// ADD RATER
			if ( 'add_rater' === $action && $check_nonce( 'icon_psy_add_rater', 'icon_psy_add_rater_nonce' ) ) {
				$participant_id = isset( $_POST['participant_id'] ) ? (int) $_POST['participant_id'] : 0;
				$name           = isset( $_POST['rater_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rater_name'] ) ) : '';
				$email          = isset( $_POST['rater_email'] ) ? sanitize_email( wp_unslash( $_POST['rater_email'] ) ) : '';
				$relationship   = isset( $_POST['rater_relationship'] ) ? sanitize_text_field( wp_unslash( $_POST['rater_relationship'] ) ) : '';
				$owns = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$participants_table} p INNER JOIN {$projects_table} pr ON pr.id=p.project_id WHERE p.id=%d AND pr.client_user_id=%d", $participant_id, $user_id ) );
				$proj_id_for_part = (int) $wpdb->get_var( $wpdb->prepare( "SELECT project_id FROM {$participants_table} WHERE id=%d", $participant_id ) );
				$project_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$projects_table} WHERE id=%d AND client_user_id=%d", $proj_id_for_part, $user_id ) );

				if ( $project_row && icon_psy_project_is_leadership_project( $project_row, $has_reference ) )
					$prg_redirect( '', 'Leadership Assessment projects use a self-rater only. Additional raters are disabled.', array( 'icon_open_project' => $proj_id_for_part, 'icon_open_participant' => $participant_id ) );
				if ( $participant_id <= 0 || ! $owns ) $prg_redirect( '', 'Invalid participant.' );
				if ( $name === '' ) $prg_redirect( '', 'Rater name is required.' );

				$ok = $wpdb->insert( $raters_table, array( 'participant_id' => $participant_id, 'name' => $name, 'email' => $email, 'relationship' => $relationship, 'status' => 'pending', 'created_at' => current_time( 'mysql' ) ), array( '%d','%s','%s','%s','%s','%s' ) );
				if ( false === $ok ) $prg_redirect( '', $wpdb->last_error ?: 'Database insert failed.' );
				$prg_redirect( 'Rater added.', '', array( 'icon_open_project' => $proj_id_for_part, 'icon_open_participant' => $participant_id, 'icon_open_add_rater' => 1 ) );
			}

			// INVITE PROJECT RATERS
			if ( 'invite_project_raters' === $action && $check_nonce( 'icon_psy_invite_project_raters', 'icon_psy_invite_project_raters_nonce' ) ) {
				$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
				if ( icon_psy_recent_action_guard( $real_user_id, 'invite_project_raters_' . $project_id, 20 ) )
					$prg_redirect( 'Invite already processed — please wait a moment before trying again.', '', array( 'icon_open_project' => $project_id ) );
				$owns = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$projects_table} WHERE id=%d AND client_user_id=%d", $project_id, $user_id ) );
				if ( ! $owns ) $prg_redirect( '', 'Invalid project.' );
				$project = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$projects_table} WHERE id=%d", $project_id ) );
				if ( ! $project ) $prg_redirect( '', 'Project not found.' );

				$pkg_for_email   = icon_psy_get_project_pkg( $project, $has_reference );
				$participants    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$participants_table} WHERE project_id=%d", $project_id ) );
				$is_team         = icon_psy_project_is_teams_project( $project, $has_reference );
				$is_traits       = icon_psy_project_is_traits_project( $project, $has_reference );
				$base_url        = $is_team ? $team_page_url : ( $is_traits ? $traits_survey_page_url : $rater_page_url );
				$sent = $sk_comp = $sk_email = $sk_mail = 0;

				foreach ( (array) $participants as $p ) {
					foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$raters_table} WHERE participant_id=%d", (int) $p->id ) ) as $r ) {
						if ( function_exists( 'icon_psy_rater_is_completed_row' ) && icon_psy_rater_is_completed_row( $r ) ) { $sk_comp++; continue; }
						$rem = trim( (string) ( $r->email ?? '' ) );
						if ( ! is_email( $rem ) ) { $sk_email++; continue; }
						$token   = icon_psy_rand_token();
						$expires = gmdate( 'Y-m-d H:i:s', time() + 14 * DAY_IN_SECONDS );
						$ok_mail = function_exists( 'icon_psy_send_rater_invite_email' ) && icon_psy_send_rater_invite_email( $rem, (string) $r->name, (string) $p->name, (string) $project->name, add_query_arg( array( 'token' => $token ), $base_url ), $pkg_for_email );
						if ( $ok_mail ) { $update_rater_token( (int) $r->id, $token, $expires ); $sent++; } else { $sk_mail++; }
					}
				}
				$prg_redirect( "Invites sent: {$sent}. Skipped — completed: {$sk_comp}, missing/invalid email: {$sk_email}, mail failed: {$sk_mail}.", '', array( 'icon_open_project' => $project_id ) );
			}

			// INVITE SINGLE RATER
			if ( 'invite_rater' === $action && $check_nonce( 'icon_psy_invite_rater', 'icon_psy_invite_rater_nonce' ) ) {
				$rater_id = isset( $_POST['rater_id'] ) ? (int) $_POST['rater_id'] : 0;
				if ( icon_psy_recent_action_guard( $real_user_id, 'invite_rater_' . $rater_id, 20 ) )
					$prg_redirect( 'Invite already processed — please wait a moment before trying again.', '' );
				$owns = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$raters_table} r INNER JOIN {$participants_table} p ON p.id=r.participant_id INNER JOIN {$projects_table} pr ON pr.id=p.project_id WHERE r.id=%d AND pr.client_user_id=%d", $rater_id, $user_id ) );
				if ( $rater_id <= 0 || ! $owns ) $prg_redirect( '', 'Invalid rater.' );
				$r       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$raters_table} WHERE id=%d", $rater_id ) );
				if ( ! $r ) $prg_redirect( '', 'Rater not found.' );
				if ( function_exists( 'icon_psy_rater_is_completed_row' ) && icon_psy_rater_is_completed_row( $r ) ) $prg_redirect( '', 'This rater is already completed. Invite disabled.' );
				$p       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$participants_table} WHERE id=%d", (int) $r->participant_id ) );
				$project = $p ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$projects_table} WHERE id=%d", (int) $p->project_id ) ) : null;
				$email   = trim( (string) ( $r->email ?? '' ) );
				if ( ! is_email( $email ) ) $prg_redirect( '', 'Rater email is missing or invalid.' );

				$base_url = $project && icon_psy_project_is_teams_project( $project, $has_reference ) ? $team_survey_page_url
					: ( $project && icon_psy_project_is_traits_project( $project, $has_reference ) ? $traits_survey_page_url : $rater_page_url );
				$token    = icon_psy_rand_token();
				$expires  = gmdate( 'Y-m-d H:i:s', time() + 14 * DAY_IN_SECONDS );
				$pkg_email = icon_psy_get_project_pkg( $project, $has_reference );

				$ok_mail = function_exists( 'icon_psy_send_rater_invite_email' ) && icon_psy_send_rater_invite_email( $email, (string) ( $r->name ?? '' ), (string) ( $p ? $p->name : 'Participant' ), (string) ( $project ? $project->name : 'Project' ), add_query_arg( array( 'token' => $token ), $base_url ), $pkg_email );
				if ( ! $ok_mail ) $prg_redirect( '', 'Email failed to send (wp_mail returned false).' );

				$update_rater_token( $rater_id, $token, $expires );
				$proj_id_redir = (int) $wpdb->get_var( $wpdb->prepare( "SELECT project_id FROM {$participants_table} WHERE id=%d", (int) $r->participant_id ) );
				$prg_redirect( 'Invite sent.', '', array( 'icon_open_project' => $proj_id_redir, 'icon_open_participant' => (int) $r->participant_id, 'icon_open_add_rater' => 1 ) );
			}

			// DELETE PROJECT
			if ( 'delete_project' === $action && $check_nonce( 'icon_psy_delete_project', 'icon_psy_delete_project_nonce' ) ) {
				$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
				if ( $project_id <= 0 ) $prg_redirect( '', 'Invalid project.' );
				$owns = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$projects_table} WHERE id=%d AND client_user_id=%d", $project_id, $user_id ) );
				if ( ! $owns ) $prg_redirect( '', 'You do not own this project.' );
				$part_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$participants_table} WHERE project_id=%d", $project_id ) ) );
				if ( ! empty( $part_ids ) ) {
					$wpdb->query( icon_psy_prepare_in( "DELETE FROM {$raters_table} WHERE participant_id IN (%d)", $part_ids ) ); // phpcs:ignore
					$wpdb->query( icon_psy_prepare_in( "DELETE FROM {$participants_table} WHERE id IN (%d)", $part_ids ) ); // phpcs:ignore
				}
				$wpdb->delete( $projects_table, array( 'id' => $project_id ), array( '%d' ) );
				$prg_redirect( 'Project deleted.', '' );
			}

			// DELETE PARTICIPANT
			if ( 'delete_participant' === $action && $check_nonce( 'icon_psy_delete_participant', 'icon_psy_delete_participant_nonce' ) ) {
				$participant_id = isset( $_POST['participant_id'] ) ? (int) $_POST['participant_id'] : 0;
				if ( $participant_id <= 0 ) $prg_redirect( '', 'Invalid participant.' );
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT p.id, p.project_id FROM {$participants_table} p INNER JOIN {$projects_table} pr ON pr.id=p.project_id WHERE p.id=%d AND pr.client_user_id=%d", $participant_id, $user_id ) );
				if ( ! $row ) $prg_redirect( '', 'You do not own this participant.' );
				$wpdb->delete( $raters_table, array( 'participant_id' => $participant_id ), array( '%d' ) );
				$wpdb->delete( $participants_table, array( 'id' => $participant_id ), array( '%d' ) );
				$prg_redirect( 'Participant deleted.', '', array( 'icon_open_project' => (int) $row->project_id ) );
			}

			// DELETE RATER
			if ( 'delete_rater' === $action && $check_nonce( 'icon_psy_delete_rater', 'icon_psy_delete_rater_nonce' ) ) {
				$rater_id = isset( $_POST['rater_id'] ) ? (int) $_POST['rater_id'] : 0;
				if ( $rater_id <= 0 ) $prg_redirect( '', 'Invalid rater.' );
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT r.id, r.participant_id, p.project_id FROM {$raters_table} r INNER JOIN {$participants_table} p ON p.id=r.participant_id INNER JOIN {$projects_table} pr ON pr.id=p.project_id WHERE r.id=%d AND pr.client_user_id=%d", $rater_id, $user_id ) );
				if ( ! $row ) $prg_redirect( '', 'You do not own this rater.' );
				$wpdb->delete( $raters_table, array( 'id' => $rater_id ), array( '%d' ) );
				$prg_redirect( 'Rater deleted.', '', array( 'icon_open_project' => (int) $row->project_id, 'icon_open_participant' => (int) $row->participant_id, 'icon_open_add_rater' => 1 ) );
			}

			$prg_redirect( '', '' );
		}

		/* --------------------------------------------------------
		 * Load projects + rollups
		 * -------------------------------------------------------- */
		$projects                = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$projects_table} WHERE client_user_id=%d ORDER BY id DESC", $user_id ) );
		$participants_by_project = array();
		$raters_by_participant   = array();
		$total_participants      = 0;
		$total_raters            = 0;
		$total_projects          = is_array( $projects ) ? count( $projects ) : 0;

		if ( ! empty( $projects ) ) {
			$pids = array_map( fn( $pr ) => (int) $pr->id, (array) $projects );
			$participants = $wpdb->get_results( icon_psy_prepare_in( "SELECT * FROM {$participants_table} WHERE project_id IN (%d) ORDER BY id ASC", $pids ) );
			foreach ( (array) $participants as $p ) { $participants_by_project[ (int) $p->project_id ][] = $p; $total_participants++; }
			$part_ids = array_map( fn( $pp ) => (int) $pp->id, (array) $participants );
			if ( ! empty( $part_ids ) ) {
				$raters = $wpdb->get_results( icon_psy_prepare_in( "SELECT * FROM {$raters_table} WHERE participant_id IN (%d) ORDER BY id ASC", $part_ids ) );
				foreach ( (array) $raters as $r ) { $raters_by_participant[ (int) $r->participant_id ][] = $r; $total_raters++; }
			}
		}

		// Credits display
		$credit_balance  = icon_psy_get_client_credit_balance( $user_id );
		$default_buy_url = home_url( '/contact/' );
		$buy_credit_url  = apply_filters( 'icon_psy_credit_pack_buy_url', $default_buy_url, array( 'credits' => 1, 'key' => 'c1', 'title' => '1 credit' ) ) ?: $default_buy_url;
		if ( strpos( (string) $buy_credit_url, 'add-to-cart=' ) === false ) $buy_credit_url = add_query_arg( array( 'credits' => 1 ), $buy_credit_url );

		$credit_display = '0';
		$credit_class   = 'credit-bad';
		if ( (int) $credit_balance === -1 )      { $credit_display = 'Unlimited'; $credit_class = 'credit-ok'; }
		elseif ( $credit_balance >= 10 )         { $credit_display = (string) $credit_balance; $credit_class = 'credit-ok'; }
		elseif ( $credit_balance >= 1 )          { $credit_display = (string) $credit_balance; $credit_class = 'credit-warn'; }
		else                                     { $credit_display = '0'; $credit_class = 'credit-bad'; }

		$client_can_manage = true;

		// Inline SVG icons (tiny, proportional)
		$ICON_SVG = array(
			'copy' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"></rect><rect x="4" y="4" width="11" height="11" rx="2"></rect></svg>',
			'send' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 2L11 13"></path><path d="M22 2l-7 20-4-9-9-4 20-7z"></path></svg>',
			'trash'=> '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M19 6l-1 16H6L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path></svg>',
			'users'=> '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',
			'file' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path></svg>',
			'plan' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>',
			'plus' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>',
		);

		ob_start();
		?>
		<style>
			:root{--icon-green:<?php echo esc_html($brand_primary);?>;--icon-blue:<?php echo esc_html($brand_secondary);?>;--text-dark:#0a3b34;--text-mid:#425b56;--text-light:#6a837d;--ink:#071b1a;}
			@keyframes iconFadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
			.icon-portal-wrap{animation:iconFadeUp .35s ease both}
			.icon-card,details.icon-project,details.icon-details{transition:transform .14s ease,box-shadow .14s ease,border-color .14s ease,filter .14s ease}
			.icon-card:hover{transform:translateY(-2px);box-shadow:0 22px 52px rgba(0,0,0,.08);border-color:rgba(21,160,109,.20)}
			.icon-card.icon-wm::after{content:"iT";position:absolute;right:16px;bottom:10px;font-weight:950;font-size:56px;letter-spacing:-.06em;line-height:1;color:rgba(10,59,52,.05);transform:rotate(-10deg);pointer-events:none;user-select:none}
			.icon-portal-shell{position:relative;padding:34px 16px 44px;background:radial-gradient(circle at top left,#e6f9ff 0%,#ffffff 40%,#e9f8f1 100%);overflow:hidden}
			.icon-portal-orbit{position:absolute;border-radius:999px;opacity:.12;filter:blur(2px);pointer-events:none;z-index:0;background:linear-gradient(135deg,var(--icon-blue),var(--icon-green))}
			.icon-portal-orbit.o1{width:260px;height:80px;top:4%;left:-40px}
			.icon-portal-orbit.o2{width:240px;height:240px;bottom:-90px;right:-50px;border-radius:50%}
			.icon-portal-orbit.o3{width:160px;height:52px;top:56%;right:10%}
			.icon-portal-wrap{max-width:1160px;margin:0 auto;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--text-dark);position:relative;z-index:2}
			.icon-rail{height:4px;border-radius:999px;background:linear-gradient(90deg,var(--icon-blue),var(--icon-green));opacity:.85;margin:0 0 12px;box-shadow:0 10px 24px rgba(20,164,207,.18)}
			.icon-card{background:#fff;border:1px solid rgba(20,164,207,.14);border-radius:22px;box-shadow:0 16px 40px rgba(0,0,0,.06);padding:18px;margin-bottom:14px;position:relative;overflow:hidden}
			.icon-card.icon-hero{background:radial-gradient(circle at top left,rgba(20,164,207,.12) 0%,rgba(255,255,255,1) 38%),radial-gradient(circle at bottom right,rgba(21,160,109,.12) 0%,rgba(255,255,255,1) 52%),#fff;padding-top:22px}
			<?php if ( $wm_logo_url ) : ?>
			.icon-card.icon-hero::after{
				content:"";
				position:absolute;
				top:10px;right:-10px;bottom:10px;
				width:min(48%,420px);
				background-image:url("<?php echo esc_url( $wm_logo_url ); ?>");
				background-repeat:no-repeat;
				background-position:right center;
				background-size:contain;
				opacity:.14;
				filter:brightness(0) invert(1);
				-webkit-mask-image:linear-gradient(90deg,rgba(0,0,0,0) 0%,rgba(0,0,0,.85) 40%,rgba(0,0,0,1) 100%);
				mask-image:linear-gradient(90deg,rgba(0,0,0,0) 0%,rgba(0,0,0,.85) 40%,rgba(0,0,0,1) 100%);
				pointer-events:none;
			}
			<?php endif; ?>

			.icon-card.icon-hero>*{position:relative;z-index:2}
			.icon-hero-logo-right{position:absolute;top:16px;right:26px;z-index:3;display:flex;align-items:center;justify-content:flex-end;pointer-events:none}
			.icon-hero-logo-right img{height:44px;width:auto;max-width:260px;object-fit:contain;display:block}

			.icon-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
			.icon-space{justify-content:space-between}
			.icon-tag{display:inline-block;padding:4px 12px;border-radius:999px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;background:rgba(21,150,140,.1);color:var(--icon-green);font-weight:900}
			.icon-tagbar{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;align-items:center;justify-content:flex-start !important;width:100%}
			.icon-tagbar>*{flex:0 0 auto}
			.icon-mini-controls{display:inline-flex;gap:10px;flex-wrap:wrap;align-items:center;margin-left:0 !important}
			.icon-actions-left{justify-content:flex-start !important;width:100%;margin-top:12px !important}
			.icon-h1{margin:0 0 6px;font-size:24px;font-weight:950;letter-spacing:-.02em;color:var(--ink)}
			.icon-sub{margin:0;color:var(--text-mid);font-size:13px;max-width:760px}
			.icon-tag.icon-tagstat{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;background:rgba(21,150,140,.10);color:var(--icon-green);font-weight:950;box-shadow:inset 0 0 0 1px rgba(21,160,109,.10)}
			.icon-tag.icon-tagstat strong{font-weight:950;color:var(--ink);letter-spacing:0;text-transform:none}
			.icon-tag.icon-credit.credit-ok{background:rgba(21,160,109,.12);color:var(--icon-green);box-shadow:inset 0 0 0 1px rgba(21,160,109,.18)}
			.icon-tag.icon-credit.credit-warn{background:rgba(245,158,11,.14);color:#b45309;box-shadow:inset 0 0 0 1px rgba(245,158,11,.22)}
			.icon-tag.icon-credit.credit-bad{background:rgba(185,28,28,.12);color:#991b1b;box-shadow:inset 0 0 0 1px rgba(185,28,28,.18)}
			.icon-control-pill{display:inline-flex;align-items:center;gap:10px;padding:7px 12px;border-radius:999px;border:1px solid rgba(20,164,207,.18);background:linear-gradient(135deg,rgba(20,164,207,.10),rgba(21,160,109,.10)),#fff;color:var(--ink);font-size:11px;letter-spacing:.06em;text-transform:uppercase;font-weight:950;cursor:pointer;user-select:none;white-space:nowrap;box-shadow:0 12px 26px rgba(20,164,207,.14);transition:transform .12s ease,box-shadow .12s ease,border-color .12s ease}
			.icon-control-pill:hover{transform:translateY(-1px);border-color:rgba(21,160,109,.28);box-shadow:0 16px 34px rgba(20,164,207,.16)}
			.icon-control-pill .icon-badge{width:22px;height:22px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;background:#fff;border:1px solid rgba(21,160,109,.25);color:var(--icon-green);font-weight:950;line-height:1;box-shadow:0 10px 22px rgba(21,160,109,.10)}
			.icon-pill{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:950;border:1px solid rgba(20,164,207,.18);background:linear-gradient(135deg,rgba(20,164,207,.10),rgba(21,160,109,.10)),#fff;color:var(--ink)}
			.icon-btn{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;border:1px solid transparent;background-image:linear-gradient(135deg,var(--icon-blue),var(--icon-green));color:#fff;padding:10px 14px;font-size:13px;font-weight:950;cursor:pointer;text-decoration:none;white-space:nowrap;box-shadow:0 12px 30px rgba(20,164,207,.30);transition:transform .12s ease,box-shadow .12s ease,filter .12s ease;gap:8px}
			.icon-btn:hover{transform:translateY(-1px);box-shadow:0 16px 34px rgba(20,164,207,.34)}
			.icon-btn-ghost{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#fff;border:1px solid rgba(21,149,136,.35);color:var(--icon-green);padding:10px 14px;font-size:13px;font-weight:950;cursor:pointer;text-decoration:none;white-space:nowrap;gap:8px}
			.icon-btn-danger{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;border:1px solid rgba(185,28,28,.25);background:rgba(254,226,226,.55);color:#7f1d1d;padding:10px 14px;font-size:13px;font-weight:950;cursor:pointer;text-decoration:none;white-space:nowrap;transition:transform .12s ease,box-shadow .12s ease,border-color .12s ease;gap:8px}
			.icon-btn-danger:hover{transform:translateY(-1px);border-color:rgba(185,28,28,.40);box-shadow:0 16px 34px rgba(185,28,28,.12)}
			.icon-btn,.icon-btn-ghost,.icon-btn-danger{height:42px;box-sizing:border-box;display:inline-flex;align-items:center}
			.icon-btn-ghost[disabled],.icon-btn[disabled]{opacity:.55;cursor:not-allowed;filter:saturate(.6)}
			.icon-email-report>summary{list-style:none;display:inline-flex;align-items:center;gap:10px;border-radius:999px;background:#fff;border:1px solid rgba(21,149,136,.35);color:var(--icon-green);padding:10px 14px;font-size:13px;font-weight:950;cursor:pointer;white-space:nowrap;height:42px;box-sizing:border-box}
			.icon-email-report>summary::-webkit-details-marker{display:none}
			.icon-email-report>summary:hover{background-image:linear-gradient(135deg,var(--icon-blue),var(--icon-green));color:#fff;border-color:transparent;box-shadow:0 12px 30px rgba(20,164,207,.30);transform:translateY(-1px)}
			.icon-email-report .icon-plus{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:999px;background:linear-gradient(135deg,var(--icon-blue),var(--icon-green));color:#fff;font-size:13px;line-height:1;font-weight:900}
			.icon-grid-2{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:14px}
			.icon-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
			@media(max-width:980px){
				.icon-grid-2,.icon-grid-3{grid-template-columns:1fr}
				.icon-hero-logo-right{position:static;justify-content:flex-start;margin-bottom:10px;pointer-events:none}
			}
			.icon-label{display:block;font-size:12px;color:var(--text-light);font-weight:950;margin-bottom:6px}
			.icon-input,.icon-select{width:100%;border-radius:14px;border:1px solid rgba(148,163,184,.55);padding:10px 12px;font-size:13px;color:var(--text-dark);background:#fff;box-sizing:border-box}
			.icon-msg{border-radius:18px;padding:12px 14px;font-weight:950;border:1px solid rgba(226,232,240,.9)}
			.icon-msg.err{border-color:rgba(185,28,28,.25);background:rgba(254,226,226,.38);color:#7f1d1d}
			.icon-msg.ok{border-color:rgba(21,160,109,.25);background:rgba(236,253,245,.75);color:#065f46}
			.icon-section-title{margin:0 0 8px;font-size:14px;font-weight:950;color:#0b2f2a}
			.icon-mini{font-size:12px;color:#64748b;margin-top:4px;line-height:1.35}
			details.icon-project{border:1px solid rgba(226,232,240,.9);border-radius:18px;background:#fff;overflow:hidden}
			details.icon-project+details.icon-project{margin-top:12px}
			details.icon-project summary{list-style:none;cursor:pointer;padding:14px;display:flex;align-items:center;justify-content:space-between;gap:12px}
			details.icon-project summary::-webkit-details-marker{display:none}
			.icon-project-left{min-width:0}
			.icon-project-title{font-weight:950;color:#0b2f2a;font-size:15px;margin:0}
			.icon-project-meta{font-size:12px;color:#64748b;margin-top:4px;line-height:1.35}
			details.icon-project summary .icon-toggle::before{content:"+"}
			details.icon-project[open] summary .icon-toggle::before{content:"–"}
			.icon-project-body{padding:14px 14px 16px;border-top:1px solid rgba(226,232,240,.9);background:#fff}
			details.icon-details{border:1px solid rgba(226,232,240,.9);border-radius:16px;padding:10px;background:#f9fbfc;margin-top:12px}
			details.icon-details summary{cursor:pointer;font-weight:950;color:#0b2f2a;list-style:none}
			details.icon-details summary::-webkit-details-marker{display:none}
			details.icon-details summary:after{content:"＋";float:right;color:#0b2f2a;font-weight:950}
			details.icon-details[open] summary:after{content:"－"}
			.icon-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px}
			.icon-table th,.icon-table td{padding:8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
			.icon-table th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;background:#f9fafb}
			.icon-copy{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#fff;border:1px solid rgba(15,118,110,.25);color:#0f766e;padding:8px 10px;font-size:12px;font-weight:950;cursor:pointer;text-decoration:none;white-space:nowrap;gap:8px}
			.icon-toggle{width:34px;height:34px;border-radius:999px;border:1px solid rgba(20,164,207,.18);display:flex;align-items:center;justify-content:center;font-weight:950;color:#0b2f2a;background:#f9fbfc;flex:0 0 auto}
			.icon-hidden{display:none !important}
			.icon-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;z-index:99999;padding:18px}
			.icon-modal.is-open{display:flex}
			.icon-modal-backdrop{position:absolute;inset:0;background:rgba(2,6,23,.55);backdrop-filter:blur(2px)}
			.icon-modal-card{position:relative;width:min(620px,100%);background:#fff;border:1px solid rgba(20,164,207,.18);border-radius:22px;box-shadow:0 22px 70px rgba(0,0,0,.22);padding:16px;overflow:hidden}
			.icon-modal-card h4{margin:0 0 6px;font-weight:950;color:var(--ink)}
			.icon-modal-card p{margin:0;color:var(--text-mid)}
			.icon-participant-card{border:1px solid rgba(226,232,240,.9);border-radius:16px;padding:12px;background:radial-gradient(900px 220px at 120% 120%,rgba(20,164,207,.08) 0%,rgba(255,255,255,0) 60%),#f9fbfc;box-shadow:0 10px 26px rgba(0,0,0,.04)}
			#icon-howto-card,#icon-pricing-card{margin-top:14px}

			.icon-ico{width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;opacity:.95}
			.icon-ico svg{width:16px;height:16px;display:block;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
			.icon-btn-label{display:inline-block;line-height:1}
		</style>

		<div class="icon-portal-shell">
			<div class="icon-portal-orbit o1"></div>
			<div class="icon-portal-orbit o2"></div>
			<div class="icon-portal-orbit o3"></div>

			<div class="icon-portal-wrap">
				<div class="icon-rail"></div>

				<div class="icon-card icon-hero icon-wm">

					<div class="icon-row icon-space" style="align-items:flex-start;gap:14px;">
						<div style="min-width:0;">
							<?php if ( $wm_logo_url ) : ?>
								<div style="display:flex;align-items:center;gap:12px;margin-bottom:6px;">
									<img src="<?php echo esc_url( $wm_logo_url ); ?>" alt="Client logo" style="height:44px;width:auto;max-width:220px;object-fit:contain;">
								</div>
							<?php endif; ?>
							<h1 class="icon-h1">Icon Catalyst client access</h1>
							<p class="icon-sub"><?php echo esc_html( $is_admin ? 'Admin view as: ' . $effective_user->display_name : 'Logged in as: ' . $current_user->display_name ); ?></p>

							<div class="icon-row icon-tagbar">
								<div class="icon-tag icon-tagstat">Projects: <strong><?php echo (int) $total_projects; ?></strong></div>
								<div class="icon-tag icon-tagstat">Participants: <strong><?php echo (int) $total_participants; ?></strong></div>
								<div class="icon-tag icon-tagstat">Raters: <strong><?php echo (int) $total_raters; ?></strong></div>
								<div class="icon-tag icon-tagstat icon-credit <?php echo esc_attr( $credit_class ); ?>">Credits: <strong><?php echo esc_html( $credit_display ); ?></strong></div>
								<?php if ( $total_projects > 0 ) : ?>
									<div class="icon-mini-controls" id="icon-project-controls">
										<button type="button" class="icon-control-pill" id="icon-expand-all"><span class="icon-badge">+</span> Expand projects</button>
										<button type="button" class="icon-control-pill" id="icon-collapse-all"><span class="icon-badge">–</span> Collapse projects</button>
									</div>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<div class="icon-row icon-actions-left" style="gap:10px;flex-wrap:wrap;margin-top:10px;">
						<?php if ( (int) $credit_balance !== -1 ) : ?>
							<a href="<?php echo esc_url( $buy_credit_url ); ?>" class="icon-btn"><span class="icon-ico"><?php echo $ICON_SVG['plus']; ?></span><span class="icon-btn-label">Buy credits</span></a>
						<?php endif; ?>
						<button type="button" class="icon-btn" id="icon-open-pricing"><span class="icon-ico"><?php echo $ICON_SVG['file']; ?></span><span class="icon-btn-label">Pricing</span></button>
						<button type="button" class="icon-btn" id="icon-open-howto"><span class="icon-ico"><?php echo $ICON_SVG['file']; ?></span><span class="icon-btn-label">How to use</span></button>
						<button type="button" class="icon-btn" id="icon-open-create"><span class="icon-ico"><?php echo $ICON_SVG['plus']; ?></span><span class="icon-btn-label">Create project</span></button>
						<button type="button" class="icon-btn" id="icon-open-support"><span class="icon-ico"><?php echo $ICON_SVG['send']; ?></span><span class="icon-btn-label">Support</span></button>
						<a class="icon-btn" href="<?php echo esc_url( home_url('/client-trends/') ); ?>"><span class="icon-ico"><?php echo $ICON_SVG['users']; ?></span><span class="icon-btn-label">Client trends</span></a>
						<a class="icon-btn" href="https://icon-talent.org"><span class="icon-btn-label">← Back to Icon Talent</span></a>
						<?php if ( $is_admin ) : ?>
							<a class="icon-btn-ghost" href="<?php echo esc_url( add_query_arg( array( 'icon_psy_impersonate' => 'stop', '_wpnonce' => wp_create_nonce( 'icon_psy_impersonate' ) ), home_url( '/catalyst-portal/' ) ) ); ?>"><span class="icon-ico"><?php echo $ICON_SVG['trash']; ?></span><span class="icon-btn-label">Clear impersonation</span></a>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( $error )  : ?><div class="icon-msg err" style="margin-top:12px;"><?php echo esc_html( $error ); ?></div><?php endif; ?>
				<?php if ( $notice ) : ?><div class="icon-msg ok" style="margin-top:12px;"><?php echo esc_html( $notice ); ?></div><?php endif; ?>

				<!-- Pricing -->
				<div class="icon-card icon-wm icon-hidden" id="icon-pricing-card">
					<div class="icon-row icon-space">
						<div><div class="icon-section-title">Pricing and credits</div><p class="icon-mini">Indicative pricing and how credits are applied for each package.</p></div>
						<button type="button" class="icon-btn-ghost" data-icon-close="icon-pricing-card"><span class="icon-ico"><?php echo $ICON_SVG['trash']; ?></span><span class="icon-btn-label">Close</span></button>
					</div>
					<div style="margin-top:12px;border-top:1px solid rgba(226,232,240,.9);padding-top:14px;">
						<div class="icon-msg" style="margin-bottom:12px;">
							<div style="font-weight:950;color:var(--ink);margin-bottom:4px;">How credits work</div>
							<div class="icon-mini" style="margin:0;">Credits are applied based on the package rules. Some packages deduct credits <strong>per participant</strong>, others deduct credits <strong>once per project</strong> (only when the first participant is added).</div>
						</div>
						<div style="overflow:auto;">
							<table class="icon-table">
								<thead><tr><th>Package</th><th>Price</th><th>Credit rule</th><th>Credits needed</th></tr></thead>
								<tbody>
									<?php
									$rules = icon_psy_package_credit_rules();
									foreach ( array_keys( $all_packages ) as $pkg_key ) :
										$title      = trim( preg_replace( '/\s*\(.*?\)\s*/', ' ', (string) ( $all_packages[ $pkg_key ]['title'] ?? $pkg_key ) ) );
										$price      = (string) ( $all_packages[ $pkg_key ]['price'] ?? '' );
										$rule       = $rules[ $pkg_key ] ?? null;
										$mode       = is_array( $rule ) ? (string) $rule['mode'] : '';
										$cost       = is_array( $rule ) ? (int) $rule['cost'] : 0;
										$rule_label = 'Managed / invoiced';
										$cr_label   = '—';
										if ( $mode && $cost > 0 ) {
											$rule_label = $mode === 'per_participant' ? 'Per participant' : 'Per project';
											$cr_label   = "{$cost} credit" . ( $cost === 1 ? '' : 's' ) . ' ' . ( $mode === 'per_participant' ? 'per participant' : 'per project (charged once)' );
										}
										$gbp = ( $mode && $cost > 0 ) ? '£' . number_format_i18n( $cost * $icon_credit_unit_price_gbp ) . " ({$cost} credit" . ( $cost === 1 ? '' : 's' ) . ')' . ( $mode === 'per_project' ? ' per project' : ' per participant' ) : '';
									?>
										<tr>
											<td style="font-weight:950;color:#0b2f2a;"><?php echo esc_html( $title ); ?><div class="icon-mini" style="margin-top:4px;opacity:.9;"><?php echo esc_html( $pkg_key ); ?></div></td>
											<td><?php echo esc_html( $gbp ?: ( $price ?: '—' ) ); ?></td>
											<td><span class="icon-pill"><?php echo esc_html( $rule_label ); ?></span></td>
											<td><?php echo esc_html( $cr_label ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<div class="icon-mini" style="margin-top:10px;">Notes:<ul style="margin:6px 0 0;padding-left:18px;">
							<li>For <strong>per project</strong> packages, credits are deducted only when the first participant is added.</li>
							<li><strong>Subscription</strong> and certain <strong>custom / enterprise</strong> packages may be invoiced or fully managed rather than credit-based.</li>
						</ul></div>
					</div>
				</div>

				<!-- How to use -->
				<div class="icon-card icon-wm icon-hidden" id="icon-howto-card">
					<div class="icon-row icon-space">
						<div><div class="icon-section-title">How to use the portal</div><p class="icon-mini">A quick guide to creating projects, adding participants, inviting raters, and accessing reports.</p></div>
						<button type="button" class="icon-btn-ghost" data-icon-close="icon-howto-card"><span class="icon-ico"><?php echo $ICON_SVG['trash']; ?></span><span class="icon-btn-label">Close</span></button>
					</div>
					<div style="margin-top:12px;border-top:1px solid rgba(226,232,240,.9);padding-top:14px;">
						<div class="icon-grid-2">
							<div><div class="icon-section-title">Step 1: Create a project</div><p class="icon-mini">Use <strong>Create project</strong> to set up a new assessment. Choose the package and whether it's off-the-shelf or customised. Projects keep everything organised: participants, rater invites, and reporting.</p><div class="icon-mini" style="margin-top:8px;"><strong>Tip:</strong> If you have multiple departments or cohorts, create a project per cohort to keep reporting clean.</div></div>
							<div><div class="icon-section-title">Step 2: Add participants</div><p class="icon-mini">Open a project, then use <strong>Add participant</strong>. A participant is the person receiving feedback (or completing the assessment). Once a participant is added, you can generate reports for them.</p><div class="icon-mini" style="margin-top:8px;"><strong>Credits:</strong> credits are applied based on the <strong>package</strong>, not always 1 per participant. Some packages charge <strong>per participant</strong>, others charge <strong>once per project</strong>.</div></div>
						</div>
						<div style="height:12px;"></div>
						<div class="icon-grid-2">
							<div><div class="icon-section-title">Step 3: Add raters and send invites</div><p class="icon-mini">In most projects, you'll add raters under a participant using <strong>Add rater</strong>. Then click <strong>Invite</strong> to email them their survey link, or use <strong>Copy link</strong> to send it manually.</p><div class="icon-mini" style="margin-top:8px;">You can also use <strong>Invite all raters</strong> at the top of the project to send invitations in one go.</div></div>
							<div><div class="icon-section-title">Leadership Assessment projects</div><p class="icon-mini">Leadership Assessment projects use a <strong>self-rater only</strong>. The portal will automatically create the self entry when you add the participant. Additional raters are intentionally disabled for this project type.</p><div class="icon-mini" style="margin-top:8px;"><strong>Best practice:</strong> Ask the participant to complete their self survey first so their report is ready sooner.</div></div>
						</div>
						<div style="height:12px;"></div>
						<div class="icon-grid-2">
							<div><div class="icon-section-title">Teams / High Performing Teams projects</div><p class="icon-mini">Teams projects route invite links to the <strong>Team Survey</strong> page. Reporting is accessed using the <strong>View report</strong> button on the participant row (team report format).</p></div>
							<div><div class="icon-section-title">Step 4: View reports</div><p class="icon-mini">Use <strong>View report</strong> on a participant card to open the report in a new tab. Reports update as raters complete their surveys.</p><div class="icon-mini" style="margin-top:8px;"><strong>Completed:</strong> the portal shows completion counts (e.g. 3/6) so you can see progress at a glance.</div></div>
						</div>
						<div style="height:12px;"></div>
						<div class="icon-grid-2">
							<div><div class="icon-section-title">Credits and packages</div><p class="icon-mini">Credits are displayed at the top of the portal. If your balance is low, use <strong>Buy credits</strong>. Credits are applied based on the project's package rules:</p><div class="icon-mini" style="margin-top:10px;"><ul style="margin:0;padding-left:18px;"><li><strong>Per participant</strong>: credits are deducted each time you add a participant</li><li><strong>Per project</strong>: credits are deducted only once, when the first participant is added</li><li><strong>Invoiced / managed</strong>: some packages may not use credits</li></ul></div></div>
							<div><div class="icon-section-title">Support</div><p class="icon-mini">Use the <strong>Support</strong> button (top-right) if you need help with access, invites, or reports. When contacting support, include your project name and participant name.</p><div class="icon-mini" style="margin-top:8px;"><strong>Common fixes:</strong> check the rater email address, resend the invite, or copy the link and send it directly.</div></div>
						</div>
					</div>
				</div>

				<!-- Create Project -->
				<div class="icon-card icon-wm <?php echo ( isset( $_GET['icon_psy_err'] ) && ! isset( $_GET['icon_open_project'] ) ? '' : 'icon-hidden' ); ?>" id="icon-create-project-card">
					<div class="icon-row icon-space">
						<div><div class="icon-section-title">Create a new project</div><p class="icon-mini">Set up a new project to manage participants, raters, and surveys.</p></div>
						<button type="button" class="icon-btn-ghost" data-icon-close="icon-create-project-card"><span class="icon-ico"><?php echo $ICON_SVG['trash']; ?></span><span class="icon-btn-label">Close</span></button>
					</div>
					<div style="margin-top:12px;border-top:1px solid rgba(226,232,240,.9);padding-top:14px;">
						<form method="post">
							<input type="hidden" name="icon_psy_client_action" value="add_project">
							<?php wp_nonce_field( 'icon_psy_add_project', 'icon_psy_add_project_nonce' ); ?>
							<div class="icon-grid-2">
								<div><label class="icon-label">Project name *</label><input class="icon-input" type="text" name="project_name" required></div>
								<div><label class="icon-label">Client name</label><input class="icon-input" type="text" name="client_name"></div>
							</div>
							<div class="icon-grid-2" style="margin-top:12px;">
								<div>
									<label class="icon-label">Setup type</label>
									<select class="icon-select" name="icon_config_mode">
										<option value="off_shelf">Off-the-shelf</option>
										<option value="custom">Customised</option>
									</select>
								</div>
								<div>
									<label class="icon-label">Package</label>
									<select class="icon-select" name="icon_package_key">
										<optgroup label="Standard packages">
											<?php foreach ( $packages_off_shelf as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v['title'] ); ?></option><?php endforeach; ?>
										</optgroup>
										<optgroup label="Custom design">
											<?php foreach ( $packages_custom as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v['title'] ); ?></option><?php endforeach; ?>
										</optgroup>
									</select>
								</div>
							</div>
							<?php if ( $has_framework_id ) : ?>
								<div style="margin-top:12px;">
									<label class="icon-label">Framework</label>
									<select class="icon-select" name="framework_id">
										<option value="0">— Select —</option>
										<?php foreach ( (array) $frameworks as $fw ) : ?><option value="<?php echo (int) $fw->id; ?>"><?php echo esc_html( $fw->name ); ?></option><?php endforeach; ?>
									</select>
									<div class="icon-mini">For "Customised" projects, framework can be configured later.</div>
								</div>
							<?php endif; ?>
							<div style="margin-top:12px;"><label class="icon-label">Reference</label><input class="icon-input" type="text" name="project_reference" placeholder="Optional internal reference"></div>
							<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
								<button class="icon-btn" type="submit"><span class="icon-ico"><?php echo $ICON_SVG['plus']; ?></span><span class="icon-btn-label">Create project</span></button>
								<button class="icon-btn-ghost" type="button" data-icon-close="icon-create-project-card"><span class="icon-btn-label">Cancel</span></button>
							</div>
						</form>
					</div>
				</div>

				<div class="icon-card icon-wm" style="margin-top:14px;">
					<div class="icon-section-title" style="margin-bottom:0;">Projects</div>
					<div class="icon-mini" style="margin-top:6px;">Click a project to view participants and manage rater invites.</div>

					<div style="margin-top:12px;">
						<?php if ( empty( $projects ) ) : ?>
							<div class="icon-mini">No projects yet. Click <strong>Create project</strong> above to get started.</div>
						<?php else : ?>

							<?php foreach ( (array) $projects as $project ) : ?>
								<?php
								$project_id = (int) $project->id;
								$parts      = $participants_by_project[ $project_id ] ?? array();
								$pkg_key    = icon_psy_get_project_pkg( $project, $has_reference );
								$cfg_mode   = '';
								if ( isset( $project->icon_cfg ) && (string) $project->icon_cfg !== '' ) $cfg_mode = (string) $project->icon_cfg;
								elseif ( $has_reference && ! empty( $project->reference ) ) { $cfg = icon_psy_extract_icon_cfg_from_reference( (string) $project->reference ); if ( isset( $cfg['icon_cfg'] ) ) $cfg_mode = (string) $cfg['icon_cfg']; }

								$is_leadership_project = icon_psy_project_is_leadership_project( $project, $has_reference );
								$is_team_project       = icon_psy_project_is_teams_project( $project, $has_reference );
								$pkg_title             = trim( preg_replace( '/\s*\(.*?\)\s*/', ' ', (string) ( $all_packages[ $pkg_key ]['title'] ?? '' ) ) );
								$meta_line             = $cfg_mode ? ( $cfg_mode === 'custom' ? 'Customised' : 'Off-the-shelf' ) : '';
								?>

								<details class="icon-project" data-icon-project data-project-id="<?php echo (int) $project_id; ?>">
									<summary>
										<div class="icon-project-left">
											<p class="icon-project-title">
												<?php echo esc_html( $project->name ); ?>
												<?php if ( $pkg_title ) : ?><span class="icon-tag" style="margin-left:10px;vertical-align:middle;"><?php echo esc_html( $pkg_title ); ?></span><?php endif; ?>
											</p>
											<div class="icon-project-meta"><?php echo esc_html( implode( ' | ', array_filter( array( $meta_line, 'Participants: ' . count( $parts ) ) ) ) ); ?></div>
										</div>
										<span class="icon-toggle" aria-hidden="true"></span>
									</summary>

									<div class="icon-project-body">
										<div class="icon-row" style="justify-content:flex-end;margin-bottom:10px;gap:10px;">
											<form method="post" style="margin:0;">
												<input type="hidden" name="icon_psy_client_action" value="invite_project_raters">
												<?php wp_nonce_field( 'icon_psy_invite_project_raters', 'icon_psy_invite_project_raters_nonce' ); ?>
												<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
												<button class="icon-btn-ghost icon-confirm-invite" type="submit"><span class="icon-ico"><?php echo $ICON_SVG['send']; ?></span><span class="icon-btn-label">Invite all raters</span></button>
											</form>
											<form method="post" style="margin:0;">
												<input type="hidden" name="icon_psy_client_action" value="delete_project">
												<?php wp_nonce_field( 'icon_psy_delete_project', 'icon_psy_delete_project_nonce' ); ?>
												<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
												<button class="icon-btn-danger icon-confirm-delete" type="submit"><span class="icon-ico"><?php echo $ICON_SVG['trash']; ?></span><span class="icon-btn-label">Delete project</span></button>
											</form>
										</div>

										<?php if ( ! empty( $parts ) ) : ?>
											<div style="margin-top:12px;display:flex;flex-direction:column;gap:10px;">
												<?php foreach ( $parts as $p ) : ?>
													<?php
													$pid    = (int) $p->id;
													$raters = $raters_by_participant[ $pid ] ?? array();
													$p_total = count( $raters );
													$p_completed = 0;
													foreach ( $raters as $rr ) { if ( function_exists( 'icon_psy_rater_is_completed_row' ) && icon_psy_rater_is_completed_row( $rr ) ) $p_completed++; }

													$is_profiler_project = icon_psy_project_is_profiler_project( $project, $frameworks_table );
													$is_traits_project   = icon_psy_project_is_traits_project( $project, $has_reference );

													// ✅ FIX: Define report type FIRST before using it
													$icon_email_report_type = $is_team_project ? 'team' : ( $is_traits_project ? 'traits' : ( $is_profiler_project ? 'profiler' : 'feedback' ) );

													$report_base = $is_team_project ? $team_report_page_url : ( $is_profiler_project ? $profiler_report_page_url : ( $is_traits_project ? $traits_report_page_url : $report_page_url ) );
													$report_url = icon_psy_create_email_share_link(
														$report_base,
														$project_id,
														$pid,
														$icon_email_report_type,  // ✅ Now properly defined
														14
													);
													$icon_email_report_type = $is_team_project ? 'team' : ( $is_traits_project ? 'traits' : ( $is_profiler_project ? 'profiler' : 'feedback' ) );
													?>
													<div class="icon-participant-card">
														<div class="icon-row icon-space">
															<div>
																<div style="font-weight:950;"><?php echo esc_html( $p->name ); ?></div>
																<div class="icon-mini"><?php echo esc_html( implode( ' · ', array_filter( array( $p->email ?? '', ! empty( $p->role ) ? 'Role: ' . $p->role : '', 'Completed: ' . $p_completed . '/' . $p_total ) ) ) ); ?></div>
															</div>
															<div class="icon-row" style="gap:10px;flex-wrap:wrap;">
																<a class="icon-btn-ghost" href="<?php echo esc_url( $report_url ); ?>" target="_blank" rel="noopener"><span class="icon-ico"><?php echo $ICON_SVG['file']; ?></span><span class="icon-btn-label">View report</span></a>
																<a class="icon-btn" href="<?php echo esc_url( add_query_arg( array( 'project_id' => $project_id ), $action_plan_page_url ) ); ?>"><span class="icon-ico"><?php echo $ICON_SVG['plan']; ?></span><span class="icon-btn-label">Action Plan</span></a>

																<details class="icon-details icon-email-report" style="margin:0;" data-email-report-for="<?php echo $pid; ?>">
																	<summary><span>Email report</span><span class="icon-plus">+</span></summary>
																	<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-top:10px;">
																		<input type="hidden" name="action" value="icon_psy_send_report_email">
																		<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
																		<input type="hidden" name="participant_id" value="<?php echo (int) $pid; ?>">
																		<input type="hidden" name="report_type" value="<?php echo esc_attr( $icon_email_report_type ); ?>">
																		<?php wp_nonce_field( 'icon_psy_send_report_email' ); ?>
																		<div class="icon-mini" style="margin:0 0 8px;">Choose who should receive the report link:</div>
																		<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
																			<label class="icon-pill" style="cursor:pointer;"><input type="checkbox" name="send_to[]" value="client" style="margin-right:8px;"> Client</label>
																			<label class="icon-pill" style="cursor:pointer;"><input type="checkbox" name="send_to[]" value="participant" style="margin-right:8px;"> Participant</label>
																		</div>
																		<?php if ( ! empty( $raters ) ) : ?>
																			<div class="icon-mini" style="margin:10px 0 6px;">Raters</div>
																			<div style="display:flex;flex-direction:column;gap:8px;">
																				<?php foreach ( (array) $raters as $rr ) :
																					if ( ! is_email( $rr->email ?? '' ) ) continue; ?>
																					<label class="icon-pill" style="cursor:pointer;display:flex;align-items:center;gap:10px;">
																						<input type="checkbox" name="rater_ids[]" value="<?php echo (int) $rr->id; ?>">
																						<span style="font-weight:950;"><?php echo esc_html( $rr->name ?? '' ); ?></span>
																						<span class="icon-mini" style="margin:0;"><?php echo esc_html( $rr->email ?? '' ); ?></span>
																					</label>
																				<?php endforeach; ?>
																			</div>
																		<?php endif; ?>
																		<div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;">
																			<button type="submit" class="icon-btn"><span class="icon-ico"><?php echo $ICON_SVG['send']; ?></span><span class="icon-btn-label">Send report link</span></button>
																		</div>
																		<div class="icon-mini" style="margin-top:8px;">The email will include an expiring share link that opens without login.</div>
																	</form>
																</details>

																<form method="post" style="margin:0;">
																	<input type="hidden" name="icon_psy_client_action" value="delete_participant">
																	<?php wp_nonce_field( 'icon_psy_delete_participant', 'icon_psy_delete_participant_nonce' ); ?>
																	<input type="hidden" name="participant_id" value="<?php echo (int) $pid; ?>">
																	<button class="icon-btn-danger icon-confirm-delete" type="submit"><span class="icon-ico"><?php echo $ICON_SVG['trash']; ?></span><span class="icon-btn-label">Delete</span></button>
																</form>
															</div>
														</div>

														<?php if ( ! $is_leadership_project ) : ?>
															<details class="icon-details" data-add-rater-for="<?php echo (int) $pid; ?>">
																<summary>Add rater</summary>
																<form method="post" style="margin-top:10px;">
																	<input type="hidden" name="icon_psy_client_action" value="add_rater">
																	<?php wp_nonce_field( 'icon_psy_add_rater', 'icon_psy_add_rater_nonce' ); ?>
																	<input type="hidden" name="participant_id" value="<?php echo (int) $pid; ?>">
																	<div class="icon-grid-3">
																		<div><label class="icon-label">Name *</label><input class="icon-input" type="text" name="rater_name" required></div>
																		<div><label class="icon-label">Email</label><input class="icon-input" type="email" name="rater_email"></div>
																		<div><label class="icon-label">Relationship</label><input class="icon-input" type="text" name="rater_relationship"></div>
																	</div>
																	<div style="margin-top:10px;"><button class="icon-btn" type="submit"><span class="icon-ico"><?php echo $ICON_SVG['plus']; ?></span><span class="icon-btn-label">Add rater</span></button></div>
																</form>
															</details>
														<?php else : ?>
															<div class="icon-mini" style="margin-top:10px;">Leadership Assessment: self-rater is auto-created, so additional raters are disabled.</div>
														<?php endif; ?>

														<?php if ( ! empty( $raters ) ) : ?>
															<table class="icon-table">
																<thead><tr><th>Name</th><th>Email</th><th>Relationship</th><th>Status</th><th>Expires</th><th>Link</th><th>Invite</th></tr></thead>
																<tbody>
																	<?php foreach ( $raters as $r ) : ?>
																		<?php
																		if ( function_exists( 'icon_psy_sync_rater_completed_status' ) ) icon_psy_sync_rater_completed_status( $raters_table, $r );
																		$is_completed = function_exists( 'icon_psy_rater_is_completed_row' ) && icon_psy_rater_is_completed_row( $r );
																		$phase_val    = in_array( $r->phase ?? '', array( 'baseline', 'post' ), true ) ? (string) $r->phase : 'baseline';
																		$has_post_row = false;
																		if ( $is_traits_project && $phase_val === 'baseline' )
																			$has_post_row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$raters_table} WHERE baseline_rater_id=%d AND phase='post'", (int) $r->id ) ) > 0;

																		$tok  = (string) ( $r->token ?? $r->invite_token ?? '' );
																		$base = $is_team_project ? $team_survey_page_url : ( $is_traits_project ? $traits_survey_page_url : $rater_page_url );
																		$link = $tok ? add_query_arg( array( 'token' => $tok ), $base ) : '';
																		?>
																		<tr>
																			<td><?php echo esc_html( $r->name ?? '' ); ?></td>
																			<td><?php echo esc_html( $r->email ?? '' ); ?></td>
																			<td><?php echo esc_html( $r->relationship ?? '' ); ?></td>
																			<td><?php echo esc_html( ucfirst( (string) ( $r->status ?? 'pending' ) ) ); ?><?php if ( $is_completed ) : ?><div class="icon-mini">Completed</div><?php endif; ?></td>
																			<td><?php echo esc_html( $r->token_expires_at ?? '—' ); ?></td>
																			<td>
																				<?php if ( $link ) : ?>
																					<button type="button" class="icon-copy" data-copy="<?php echo esc_attr( $link ); ?>">
																						<span class="icon-ico"><?php echo $ICON_SVG['copy']; ?></span>
																						<span class="icon-btn-label">Copy link</span>
																					</button>
																					<div class="icon-mini" style="margin-top:6px;word-break:break-all;"><?php echo esc_html( $link ); ?></div>
																				<?php else : ?>
																					<span class="icon-mini">No link yet</span>
																				<?php endif; ?>
																			</td>
																			<td>
																				<?php if ( $client_can_manage ) : ?>
																					<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
																						<form method="post" style="margin:0;">
																							<input type="hidden" name="icon_psy_client_action" value="invite_rater">
																							<?php wp_nonce_field( 'icon_psy_invite_rater', 'icon_psy_invite_rater_nonce' ); ?>
																							<input type="hidden" name="rater_id" value="<?php echo (int) $r->id; ?>">
																							<?php if ( $is_completed ) : ?>
																								<button type="button" class="icon-btn-ghost" disabled><span class="icon-btn-label">Completed</span></button>
																							<?php else : ?>
																								<button type="submit" class="icon-btn-ghost icon-confirm-invite">
																									<span class="icon-ico"><?php echo $ICON_SVG['send']; ?></span>
																									<span class="icon-btn-label"><?php echo empty( $r->invite_sent_at ) ? 'Invite' : 'Resend'; ?></span>
																								</button>
																							<?php endif; ?>
																						</form>

																						<?php if ( $is_traits_project ) : ?>
																							<?php if ( $phase_val === 'baseline' && ! $has_post_row && $is_completed ) : ?>
																								<form method="post" style="margin:0;">
																									<input type="hidden" name="icon_psy_client_action" value="create_post_invite">
																									<?php wp_nonce_field( 'icon_psy_create_post_invite', 'icon_psy_create_post_invite_nonce' ); ?>
																									<input type="hidden" name="baseline_rater_id" value="<?php echo (int) $r->id; ?>">
																									<button type="submit" class="icon-btn-ghost"><span class="icon-ico"><?php echo $ICON_SVG['plus']; ?></span><span class="icon-btn-label">Create post invite</span></button>
																								</form>
																							<?php elseif ( $phase_val === 'post' ) : ?>
																								<span class="icon-pill">Post-course</span>
																							<?php elseif ( $has_post_row ) : ?>
																								<span class="icon-pill">Post invite exists</span>
																							<?php else : ?>
																								<span class="icon-pill">Baseline</span>
																							<?php endif; ?>
																						<?php endif; ?>

																						<form method="post" style="margin:0;">
																							<input type="hidden" name="icon_psy_client_action" value="delete_rater">
																							<?php wp_nonce_field( 'icon_psy_delete_rater', 'icon_psy_delete_rater_nonce' ); ?>
																							<input type="hidden" name="rater_id" value="<?php echo (int) $r->id; ?>">
																							<button type="submit" class="icon-btn-danger icon-confirm-delete"><span class="icon-ico"><?php echo $ICON_SVG['trash']; ?></span><span class="icon-btn-label">Delete</span></button>
																						</form>
																					</div>
																					<?php if ( ! empty( $r->invite_sent_at ) ) : ?><div class="icon-mini" style="margin-top:6px;">Sent: <?php echo esc_html( $r->invite_sent_at ); ?></div><?php endif; ?>
																				<?php else : ?>
																					<span class="icon-mini" style="margin:0;">Managed</span>
																				<?php endif; ?>
																			</td>
																		</tr>
																	<?php endforeach; ?>
																</tbody>
															</table>
														<?php endif; ?>
													</div>
												<?php endforeach; ?>
											</div>
										<?php else : ?>
											<div class="icon-mini" style="margin-top:12px;">No participants yet.</div>
										<?php endif; ?>

										<details class="icon-details" data-add-participant-for="<?php echo (int) $project_id; ?>" style="margin-top:12px;">
											<summary>Add participant</summary>
											<?php if ( (int) $credit_balance !== -1 && (int) $credit_balance <= 0 ) : ?>
												<div class="icon-msg err" style="margin-top:10px;">Your credit balance is <strong>0</strong>. Some packages require credits to add participants. Use the <strong>Buy credits</strong> button at the top.</div>
											<?php endif; ?>
											<form method="post" style="margin-top:10px;">
												<input type="hidden" name="icon_psy_client_action" value="add_participant">
												<?php wp_nonce_field( 'icon_psy_add_participant', 'icon_psy_add_participant_nonce' ); ?>
												<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
												<div class="icon-grid-3">
													<div><label class="icon-label">Name *</label><input class="icon-input" type="text" name="participant_name" required></div>
													<div><label class="icon-label">Email</label><input class="icon-input" type="email" name="participant_email"></div>
													<div><label class="icon-label">Role</label><input class="icon-input" type="text" name="participant_role"></div>
												</div>
												<div style="margin-top:10px;"><button class="icon-btn" type="submit"><span class="icon-ico"><?php echo $ICON_SVG['plus']; ?></span><span class="icon-btn-label">Add participant (credits may apply)</span></button></div>
											</form>
										</details>

									</div>
								</details>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>

			</div>
		</div>

		<!-- Support modal -->
		<div class="icon-modal" id="icon-support-modal" aria-hidden="true">
			<div class="icon-modal-backdrop" data-icon-modal-close></div>
			<div class="icon-modal-card" role="dialog" aria-modal="true" aria-labelledby="icon-support-title">
				<div class="icon-row icon-space" style="margin-bottom:6px;">
					<h4 id="icon-support-title" style="margin:0;">Support</h4>
					<button type="button" class="icon-btn-ghost" data-icon-modal-close><span class="icon-btn-label">Close</span></button>
				</div>
				<p style="margin:0 0 10px;">If you have questions about the portal, survey links, or access issues, our team can help.</p>
				<p style="margin:0;"><strong>Icon Talent Support</strong><br>+44 7484 873730</p>
				<p class="icon-mini" style="margin-top:10px;">Include your project name and participant name so we can resolve it quickly.</p>
			</div>
		</div>

		<script>
(function(){
  function ready(fn){ document.readyState!=='loading'?fn():document.addEventListener('DOMContentLoaded',fn); }
  function qsa(sel,root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }
  function showEl(el){ if(el) el.classList.remove('icon-hidden'); }
  function hideEl(el){ if(el) el.classList.add('icon-hidden'); }

  ready(function(){
    var STATE_KEY = 'icon_psy_portal_state_v1';

    function getOpenState(){
      return {
        openProjects: qsa('details.icon-project[open]').map(function(d){ return d.getAttribute('data-project-id'); }).filter(Boolean),
        openAddParticipants: qsa('details.icon-details[open][data-add-participant-for]').map(function(d){ return d.getAttribute('data-add-participant-for'); }).filter(Boolean),
        openAddRaters: qsa('details.icon-details[open][data-add-rater-for]').map(function(d){ return d.getAttribute('data-add-rater-for'); }).filter(Boolean),
        scrollY: window.scrollY||0,
        focusParticipantId: (function(){
          var box = qsa('details.icon-details[open][data-add-rater-for]')[0];
          return box ? box.getAttribute('data-add-rater-for') : '';
        })()
      };
    }

    function saveState(){ try{ sessionStorage.setItem(STATE_KEY, JSON.stringify(getOpenState())); }catch(e){} }

    function restoreState(){
      try{
        var params = new URLSearchParams(window.location.search);
        if(['icon_open_project','icon_open_participant','icon_open_add_rater','icon_open_add_participant'].some(function(k){ return params.has(k); })) return;
        var st = JSON.parse(sessionStorage.getItem(STATE_KEY)||'null');
        if(!st) return;
        (st.openProjects||[]).forEach(function(pid){ var d=document.querySelector('details.icon-project[data-project-id="'+pid+'"]'); if(d) d.open=true; });
        (st.openAddParticipants||[]).forEach(function(pid){ var d=document.querySelector('details.icon-details[data-add-participant-for="'+pid+'"]'); if(d) d.open=true; });
        (st.openAddRaters||[]).forEach(function(pid){ var d=document.querySelector('details.icon-details[data-add-rater-for="'+pid+'"]'); if(d) d.open=true; });
        var scrolled=false;
        if(st.focusParticipantId){
          var box=document.querySelector('details.icon-details[data-add-rater-for="'+st.focusParticipantId+'"]');
          var card=box?box.closest('.icon-participant-card'):null;
          if(card){ card.scrollIntoView({behavior:'auto',block:'center'}); scrolled=true; }
        }
        if(!scrolled && st.scrollY) window.scrollTo(0, Math.max(0,st.scrollY));
      }catch(e){}
    }

    (function(){
      try{
        var url=new URL(window.location.href);
        var keys=['icon_psy_msg','icon_psy_err','icon_open_project','icon_open_participant','icon_open_add_rater','icon_open_add_participant'];
        var changed=keys.some(function(k){ if(url.searchParams.has(k)){url.searchParams.delete(k);return true;} return false; });
        if(changed && window.history && window.history.replaceState)
          window.history.replaceState({},document.title,url.pathname+(url.searchParams.toString()?'?'+url.searchParams.toString():'')+url.hash);
      }catch(e){}
    })();

    qsa('details.icon-project').forEach(function(d){d.addEventListener('toggle',saveState);});
    qsa('form').forEach(function(f){ f.addEventListener('submit',saveState,{capture:true}); });
    window.addEventListener('beforeunload',saveState);

    var exp=document.getElementById('icon-expand-all');
    var col=document.getElementById('icon-collapse-all');
    if(exp) exp.addEventListener('click',function(e){ e.preventDefault(); qsa('details.icon-project').forEach(function(d){d.open=true;}); });
    if(col) col.addEventListener('click',function(e){ e.preventDefault(); qsa('details.icon-project').forEach(function(d){d.open=false;}); });

    qsa('button.icon-copy').forEach(function(btn){
      btn.addEventListener('click',function(e){
        e.preventDefault(); e.stopPropagation();
        var txt=btn.getAttribute('data-copy')||''; if(!txt) return;

        var labelEl = btn.querySelector('.icon-btn-label');
        var oldLabel = labelEl ? (labelEl.textContent || 'Copy link') : (btn.textContent || 'Copy link');

        function flash(){
          if(labelEl){
            labelEl.textContent='Copied';
            setTimeout(function(){ labelEl.textContent=oldLabel; },1200);
          } else {
            var old=btn.textContent;
            btn.textContent='Copied';
            setTimeout(function(){btn.textContent=old||'Copy link';},1200);
          }
        }

        if(navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(txt).then(flash).catch(fallback);
        else fallback();

        function fallback(){
          var ta=document.createElement('textarea');
          ta.value=txt;
          ta.style.cssText='position:fixed;left:-9999px;top:-9999px';
          document.body.appendChild(ta);
          ta.focus(); ta.select();
          try{document.execCommand('copy');}catch(e){}
          document.body.removeChild(ta);
          flash();
        }
      });
    });

    function toggleCard(btnId, cardId){
      var btn=document.getElementById(btnId), card=document.getElementById(cardId);
      if(btn && card) btn.addEventListener('click',function(e){ e.preventDefault(); e.stopPropagation(); showEl(card); card.scrollIntoView({behavior:'smooth',block:'start'}); });
    }
    toggleCard('icon-open-pricing','icon-pricing-card');
    toggleCard('icon-open-howto','icon-howto-card');
    toggleCard('icon-open-create','icon-create-project-card');

    qsa('[data-icon-close]').forEach(function(btn){
      btn.addEventListener('click',function(e){ e.preventDefault(); e.stopPropagation(); hideEl(document.getElementById(btn.getAttribute('data-icon-close'))); });
    });

    qsa('.icon-confirm-invite').forEach(function(el){ el.addEventListener('click',function(e){ e.stopPropagation(); if(!confirm('Send invite email(s) now?')){ e.preventDefault(); } }); });
    qsa('.icon-confirm-delete').forEach(function(el){ el.addEventListener('click',function(e){ e.stopPropagation(); if(!confirm('Delete this item now? This cannot be undone.')){ e.preventDefault(); } }); });

    var supportModal=document.getElementById('icon-support-modal');
    function openModal(){ if(supportModal){ supportModal.classList.add('is-open'); supportModal.setAttribute('aria-hidden','false'); } }
    function closeModal(){ if(supportModal){ supportModal.classList.remove('is-open'); supportModal.setAttribute('aria-hidden','true'); } }
    var sb=document.getElementById('icon-open-support');
    if(sb) sb.addEventListener('click',function(e){ e.preventDefault(); e.stopPropagation(); openModal(); });
    qsa('[data-icon-modal-close]').forEach(function(el){ el.addEventListener('click',function(e){ e.preventDefault(); e.stopPropagation(); closeModal(); }); });
    document.addEventListener('keydown',function(e){ if(e.key==='Escape') closeModal(); });

    (function(){
      try{
        var params=new URLSearchParams(window.location.search);
        var openProject=params.get('icon_open_project');
        var openParticipant=params.get('icon_open_participant');
        var openAddRater=params.get('icon_open_add_rater');
        var openAddParticipant=params.get('icon_open_add_participant');
        if(openProject){
          var proj=document.querySelector('details.icon-project[data-project-id="'+openProject+'"]');
          if(proj) proj.open=true;
          if(openAddParticipant==='1'){
            var addP=document.querySelector('details.icon-details[data-add-participant-for="'+openProject+'"]');
            if(addP){ addP.open=true; addP.scrollIntoView({behavior:'smooth',block:'center'}); }
          }
          if(openParticipant && openAddRater==='1'){
            var addBox=document.querySelector('details.icon-details[data-add-rater-for="'+openParticipant+'"]');
            if(addBox){ addBox.open=true; var card=addBox.closest('.icon-participant-card'); if(card) card.scrollIntoView({behavior:'smooth',block:'center'}); }
          }
        }
      }catch(e){}
    })();

    restoreState();
  });
})();
</script>
		<?php
		return ob_get_clean();
	}
}

add_action( 'init', function() {
	add_shortcode( 'icon_psy_client_portal', 'icon_psy_client_portal_shortcode' );
}, 20 );

/* ------------------------------------------------------------
 * admin-post: send report link email (PUBLIC share link)
 * Force this handler to be the only one (prevents old handlers emailing old tokens)
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_send_report_email_handler' ) ) {

	function icon_psy_send_report_email_handler() {

		if ( ! current_user_can( 'read' ) ) wp_die( 'Forbidden', 403 );
		check_admin_referer( 'icon_psy_send_report_email' );

		global $wpdb;

		$project_id     = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		$participant_id = isset( $_POST['participant_id'] ) ? (int) $_POST['participant_id'] : 0;
		$report_type    = isset( $_POST['report_type'] ) ? sanitize_key( wp_unslash( $_POST['report_type'] ) ) : 'feedback';

		$send_to   = isset( $_POST['send_to'] ) ? (array) $_POST['send_to'] : array();
		$rater_ids = isset( $_POST['rater_ids'] ) ? array_map( 'intval', (array) $_POST['rater_ids'] ) : array();

		if ( $project_id <= 0 || $participant_id <= 0 ) {
			wp_safe_redirect( wp_get_referer() ?: home_url() );
			exit;
		}

		$projects_table     = $wpdb->prefix . 'icon_psy_projects';
		$participants_table = $wpdb->prefix . 'icon_psy_participants';
		$raters_table       = $wpdb->prefix . 'icon_psy_raters';

		// ✅ IMPORTANT: use effective client id (supports impersonation correctly)
		$effective_user_id = function_exists( 'icon_psy_get_effective_client_user_id' )
			? (int) icon_psy_get_effective_client_user_id()
			: (int) get_current_user_id();

		$owns = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$projects_table} WHERE id=%d AND client_user_id=%d",
			$project_id, $effective_user_id
		) );

		if ( ! $owns ) {
			wp_safe_redirect( add_query_arg(
				array( 'icon_psy_err' => rawurlencode( 'You do not own this project.' ) ),
				wp_get_referer() ?: home_url()
			) );
			exit;
		}

		$p  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$participants_table} WHERE id=%d AND project_id=%d", $participant_id, $project_id ) );
		$pr = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$projects_table} WHERE id=%d", $project_id ) );

		if ( ! $p || ! $pr ) {
			wp_safe_redirect( add_query_arg(
				array( 'icon_psy_err' => rawurlencode( 'Participant or project not found.' ) ),
				wp_get_referer() ?: home_url()
			) );
			exit;
		}

		$report_base = home_url( '/feedback-report/' );
		if ( $report_type === 'team' )     $report_base = home_url( '/team-report/' );
		if ( $report_type === 'traits' )   $report_base = home_url( '/traits-report/' );
		if ( $report_type === 'profiler' ) $report_base = home_url( '/icon-profiler-report/' );

		// ✅ Always create a fresh share link
		$share_url = icon_psy_create_email_share_link( $report_base, $project_id, $participant_id, $report_type, 14 );

		$recipients = array();

		// ✅ If "client" selected, send to the effective client user (not the admin user when impersonating)
		if ( in_array( 'client', $send_to, true ) ) {
			$client_user = get_user_by( 'id', (int) $effective_user_id );
			if ( $client_user && is_email( $client_user->user_email ) ) $recipients[] = $client_user->user_email;
		}

		if ( in_array( 'participant', $send_to, true ) && ! empty( $p->email ) && is_email( $p->email ) ) {
			$recipients[] = (string) $p->email;
		}

		if ( ! empty( $rater_ids ) ) {
			$ph = implode( ',', array_fill( 0, count( $rater_ids ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id,email FROM {$raters_table} WHERE id IN ({$ph}) AND participant_id=%d",
				array_merge( $rater_ids, array( $participant_id ) )
			) );
			foreach ( (array) $rows as $rr ) {
				if ( ! empty( $rr->email ) && is_email( $rr->email ) ) $recipients[] = (string) $rr->email;
			}
		}

		$recipients = array_values( array_unique( array_filter( $recipients ) ) );

		if ( empty( $recipients ) ) {
			wp_safe_redirect( add_query_arg(
				array( 'icon_psy_err' => rawurlencode( 'No valid recipients selected.' ) ),
				wp_get_referer() ?: home_url()
			) );
			exit;
		}

		// ✅ Helps avoid Gmail showing an older email in the same thread and you clicking the old link
		$subject = 'Your Icon Talent report link: ' . (string) $p->name . ' • ' . gmdate('Y-m-d H:i');

		// -----------------------------------------------------------------
		// Branded HTML email with button (uses client branding if available)
		// -----------------------------------------------------------------
		$brand_primary   = '#15a06d';
		$brand_secondary = '#14a4cf';
		$brand_logo_url  = '';

		if ( function_exists( 'icon_psy_get_client_branding' ) ) {
			$b = icon_psy_get_client_branding( (int) $effective_user_id );
			$norm = function_exists( 'icon_psy_normalize_client_branding' ) ? icon_psy_normalize_client_branding( $b ) : array();
			if ( ! empty( $norm['primary'] ) )   $brand_primary   = $norm['primary'];
			if ( ! empty( $norm['secondary'] ) ) $brand_secondary = $norm['secondary'];
			if ( ! empty( $norm['logo_url'] ) )  $brand_logo_url  = esc_url_raw( $norm['logo_url'] );
		} else {
			// Fallback: try user_meta keys (kept intentionally lightweight)
			$meta_logo = get_user_meta( (int) $effective_user_id, 'icon_psy_brand_logo', true );
			$meta_p    = get_user_meta( (int) $effective_user_id, 'icon_psy_brand_primary', true );
			$meta_s    = get_user_meta( (int) $effective_user_id, 'icon_psy_brand_secondary', true );
			if ( function_exists( 'icon_psy_normalize_client_branding' ) ) {
				$norm = icon_psy_normalize_client_branding( array(
					'logo_url'  => $meta_logo,
					'primary'   => $meta_p,
					'secondary' => $meta_s,
				) );
				if ( ! empty( $norm['primary'] ) )   $brand_primary   = $norm['primary'];
				if ( ! empty( $norm['secondary'] ) ) $brand_secondary = $norm['secondary'];
				if ( ! empty( $norm['logo_url'] ) )  $brand_logo_url  = esc_url_raw( $norm['logo_url'] );
			}
		}

		$project_name     = (string) ( $pr->name ?? 'Project' );
		$participant_name = (string) ( $p->name ?? 'Participant' );
		$expires_on       = gmdate( 'j M Y', time() + 14 * DAY_IN_SECONDS );

		// Button (Gmail-friendly table button)
		$button_html =
			'<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:16px 0 8px;">' .
				'<tr>' .
					'<td bgcolor="' . esc_attr( $brand_primary ) . '" style="border-radius:999px;">' .
						'<a href="' . esc_url( $share_url ) . '" target="_blank" rel="noopener" ' .
						   'style="display:inline-block;padding:14px 22px;font-family:Arial,Helvetica,sans-serif;' .
						   'font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;' .
						   'border-radius:999px;background-color:' . esc_attr( $brand_primary ) . ';">' .
							'View report' .
						'</a>' .
					'</td>' .
				'</tr>' .
			'</table>';

		$logo_html = '';
		if ( $brand_logo_url ) {
			$logo_html = '<img src="' . esc_url( $brand_logo_url ) . '" alt="Logo" style="display:block;max-height:42px;width:auto;border:0;outline:none;text-decoration:none;">';
		}

		// HTML email body
		$html  = '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#f3faf8;">';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3faf8;padding:24px 0;">';
		$html .= '<tr><td align="center">';
		$html .= '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid rgba(20,164,207,.18);">';

		// Top bar
		$html .= '<tr><td style="padding:14px 18px;background:' . esc_attr( $brand_secondary ) . ';">';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>';
		$html .= '<td align="left" style="vertical-align:middle;">' . ( $logo_html ? $logo_html : '<span style="font-family:Arial,Helvetica,sans-serif;color:#ffffff;font-weight:800;">Icon Talent</span>' ) . '</td>';
		$html .= '<td align="right" style="vertical-align:middle;font-family:Arial,Helvetica,sans-serif;color:#ffffff;font-size:12px;font-weight:700;">Report link</td>';
		$html .= '</tr></table>';
		$html .= '</td></tr>';

		// Content
		$html .= '<tr><td style="padding:18px 18px 8px;font-family:Arial,Helvetica,sans-serif;color:#0a3b34;">';
		$html .= '<h2 style="margin:0 0 8px;font-size:18px;line-height:1.25;font-weight:800;color:#071b1a;">Your report link is ready</h2>';
		$html .= '<p style="margin:0 0 10px;font-size:14px;line-height:1.6;color:#425b56;">Use the button below to open the report securely.</p>';

		// Key details
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:10px 0 0;background:#f8fbfc;border:1px solid rgba(226,232,240,.95);border-radius:14px;">';
		$html .= '<tr><td style="padding:12px 12px;font-size:13px;line-height:1.6;color:#0a3b34;">';
		$html .= '<strong style="color:#071b1a;">Project:</strong> ' . esc_html( $project_name ) . '<br>';
		$html .= '<strong style="color:#071b1a;">Participant:</strong> ' . esc_html( $participant_name ) . '<br>';
		$html .= '<strong style="color:#071b1a;">Link expires:</strong> ' . esc_html( $expires_on ) . '<br>';
		$html .= '</td></tr></table>';

		$html .= $button_html;

		// Fallback link
		$html .= '<p style="margin:8px 0 0;font-size:12px;line-height:1.6;color:#64748b;">If the button does not work, copy and paste this link into your browser:</p>';
		$html .= '<p style="margin:6px 0 0;font-size:12px;line-height:1.6;word-break:break-all;color:#0f766e;">' . esc_html( $share_url ) . '</p>';

		$html .= '<p style="margin:14px 0 0;font-size:12px;line-height:1.6;color:#64748b;">This link is secure and will expire automatically.</p>';
		$html .= '</td></tr>';

		// Footer
		$html .= '<tr><td style="padding:14px 18px;background:#ffffff;border-top:1px solid rgba(226,232,240,.95);font-family:Arial,Helvetica,sans-serif;">';
		$html .= '<p style="margin:0;font-size:12px;line-height:1.6;color:#64748b;">Icon Talent</p>';
		$html .= '</td></tr>';

		$html .= '</table>';
		$html .= '</td></tr></table>';
		$html .= '</body></html>';

		// Plain-text fallback (some clients still prefer it)
		$text  = "Hello,\n\n";
		$text .= "Your report link is ready.\n\n";
		$text .= "Project: " . $project_name . "\n";
		$text .= "Participant: " . $participant_name . "\n";
		$text .= "Link expires: " . $expires_on . "\n\n";
		$text .= "Open report: " . $share_url . "\n\n";
		$text .= "Icon Talent\n";

		// Send as HTML (Gmail friendly)
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// If you ever need to force plain-text instead, swap $html for $text and headers to text/plain.
		$ok = wp_mail( $recipients, $subject, $html, $headers );


		$redir = wp_get_referer() ?: home_url( '/catalyst-portal/' );
		if ( $ok ) {
			wp_safe_redirect( add_query_arg( array( 'icon_psy_msg' => rawurlencode( 'Report link email sent.' ) ), $redir ) );
		} else {
			wp_safe_redirect( add_query_arg( array( 'icon_psy_err' => rawurlencode( 'Email failed to send.' ) ), $redir ) );
		}
		exit;
	}
}

// ✅ CRITICAL: remove any older handlers hooked to this action, then add ours.
remove_all_actions( 'admin_post_icon_psy_send_report_email' );
add_action( 'admin_post_icon_psy_send_report_email', 'icon_psy_send_report_email_handler', 1 );
