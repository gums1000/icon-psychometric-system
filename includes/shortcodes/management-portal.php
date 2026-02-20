<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ------------------------------------------------------------
 * Branding Engine loader (robust, works in admin-ajax too)
 * ------------------------------------------------------------ */
add_action( 'plugins_loaded', function () {

	// If already loaded, do nothing
	if ( function_exists( 'icon_psy_save_client_branding' ) && function_exists( 'icon_psy_get_client_branding' ) ) {
		return;
	}

	// ✅ Correct path (same folder as this file)
	$engine = plugin_dir_path( __FILE__ ) . 'helpers-branding.php';

	if ( file_exists( $engine ) ) {
		require_once $engine;
	}

}, 1 );

if ( ! function_exists( 'icon_psy_mgmt_require_branding_engine' ) ) {
	function icon_psy_mgmt_require_branding_engine() {

		if ( function_exists( 'icon_psy_save_client_branding' ) && function_exists( 'icon_psy_get_client_branding' ) ) {
			return true;
		}

		$candidates = array(
			plugin_dir_path( __FILE__ ) . 'helpers-branding.php', // ✅ includes/shortcodes/helpers-branding.php
			trailingslashit( dirname( __FILE__, 3 ) ) . 'includes/shortcodes/helpers-branding.php', // plugin root fallback
			trailingslashit( WP_PLUGIN_DIR ) . 'icon-psychometric-system/includes/shortcodes/helpers-branding.php', // absolute fallback
		);

		foreach ( $candidates as $p ) {
			if ( file_exists( $p ) ) {
				require_once $p;
				break;
			}
		}

		return ( function_exists( 'icon_psy_save_client_branding' ) && function_exists( 'icon_psy_get_client_branding' ) );
	}
}



/**
 * ICON Catalyst — Management Portal (Admin only)
 *
 * Shortcode: [icon_psy_management_portal]
 *
 * - 3-up client cards grid
 * - Click card -> lazy-load embedded [icon_psy_client_portal] via AJAX (impersonation)
 * - Back button clears impersonation
 * - Credits manager (add/set/reset/unlimited) + audit log
 * - KPI cards: Clients (jump), Projects (jump), Quick Credits, Add Client
 * - Performance Dial (purchase-only credits x £50 => 0..100)
 * - Export project report (admin-post)
 * - Branding modal with Media Library picker (wp.media)
 *
 * FIXES INCLUDED:
 * - Add Client fully wired (PHP AJAX + JS UI wiring) — NOW ONLY Name + Email
 * - No JS inside PHP handlers
 * - Delegated branding click handlers so Save/Pick/Clear always work
 *
 * FIX (wp.media overlay):
 * - Close branding modal BEFORE opening wp.media, then reopen when wp.media closes/selects.
 * - Modal z-index kept BELOW wp.media.
 *
 * UPDATE (this request):
 * - Add Client: ONLY add Name + Email (removed packages/frameworks UI + backend saving)
 * - Restore embedded client portal pills/actions to work (e.g., Create project) by executing embedded scripts after AJAX inject
 */

/* ------------------------------------------------------------
 * Enqueue WP Media Library on front-end pages that contain the shortcode
 * ------------------------------------------------------------ */
add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) { return; }
	if ( ! function_exists( 'is_singular' ) || ! is_singular() ) { return; }

	global $post;
	if ( ! $post || ! isset( $post->post_content ) ) { return; }

	if ( has_shortcode( (string) $post->post_content, 'icon_psy_management_portal' ) ) {
		wp_enqueue_media();
	}
}, 20 );

/* ------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_mgmt_has_admin' ) ) {
	function icon_psy_mgmt_has_admin() {
		return ( is_user_logged_in() && current_user_can( 'manage_options' ) );
	}
}

if ( ! function_exists( 'icon_psy_mgmt_credit_key' ) ) {
	function icon_psy_mgmt_credit_key() { return 'icon_psy_participant_credits'; }
}

/** (kept for compatibility; no longer set on create) */
if ( ! function_exists( 'icon_psy_mgmt_allowed_package_key' ) ) {
	function icon_psy_mgmt_allowed_package_key() { return 'icon_psy_allowed_package'; }
}
if ( ! function_exists( 'icon_psy_mgmt_allowed_frameworks_key' ) ) {
	function icon_psy_mgmt_allowed_frameworks_key() { return 'icon_psy_allowed_frameworks'; }
}

if ( ! function_exists( 'icon_psy_mgmt_normalise_credits' ) ) {
	function icon_psy_mgmt_normalise_credits( $v ) {
		if ( $v === '' || $v === null ) return 0;
		return is_numeric( $v ) ? (int) $v : 0;
	}
}

if ( ! function_exists( 'icon_psy_mgmt_credit_label' ) ) {
	function icon_psy_mgmt_credit_label( $v ) {
		$v = icon_psy_mgmt_normalise_credits( $v );
		return ( $v === -1 ) ? 'Unlimited' : (string) max( 0, (int) $v );
	}
}

if ( ! function_exists( 'icon_psy_mgmt_is_completed_status' ) ) {
	function icon_psy_mgmt_is_completed_status( $status ) {
		$s = strtolower( trim( (string) $status ) );
		return in_array( $s, array( 'completed', 'complete', 'submitted', 'done' ), true );
	}
}

if ( ! function_exists( 'icon_psy_mgmt_rater_is_completed' ) ) {
	function icon_psy_mgmt_rater_is_completed( $r ) {
		if ( ! is_object( $r ) ) return false;
		if ( isset( $r->status ) && icon_psy_mgmt_is_completed_status( $r->status ) ) return true;
		foreach ( array( 'completed_at','submitted_at','completed_on','submitted_on' ) as $f ) {
			if ( isset( $r->$f ) && ! empty( $r->$f ) ) return true;
		}
		return false;
	}
}

if ( ! function_exists( 'icon_psy_mgmt_best_datetime' ) ) {
	function icon_psy_mgmt_best_datetime( $row, $fields ) {
		$best = '';
		foreach ( (array) $fields as $f ) {
			if ( isset( $row->$f ) && ! empty( $row->$f ) ) {
				$v = (string) $row->$f;
				if ( $best === '' || $v > $best ) $best = $v;
			}
		}
		return $best;
	}
}

if ( ! function_exists( 'icon_psy_mgmt_obj_first_field' ) ) {
	function icon_psy_mgmt_obj_first_field( $obj, $fields ) {
		if ( ! is_object( $obj ) ) return '';
		foreach ( (array) $fields as $f ) {
			if ( isset( $obj->$f ) && trim( (string) $obj->$f ) !== '' ) return trim( (string) $obj->$f );
		}
		return '';
	}
}

if ( ! function_exists( 'icon_psy_mgmt_participant_label' ) ) {
	function icon_psy_mgmt_participant_label( $p ) {
		if ( ! is_object( $p ) ) return '';
		foreach ( array('participant_name','name','full_name','display_name','participant') as $f ) {
			if ( isset( $p->$f ) && trim( (string) $p->$f ) !== '' ) return trim( (string) $p->$f );
		}
		$fn = isset($p->first_name) ? trim((string)$p->first_name) : '';
		$ln = isset($p->last_name) ? trim((string)$p->last_name) : '';
		$nm = trim( $fn . ' ' . $ln );
		if ( $nm !== '' ) return $nm;
		$id = isset($p->id) ? (int) $p->id : 0;
		return $id ? ('Participant #' . $id) : '';
	}
}

if ( ! function_exists( 'icon_psy_mgmt_rater_label' ) ) {
	function icon_psy_mgmt_rater_label( $r ) {
		if ( ! is_object( $r ) ) return '';
		$name = icon_psy_mgmt_obj_first_field( $r, array( 'rater_name','name','full_name','display_name' ) );
		if ( $name !== '' ) return $name;
		$id = isset($r->id) ? (int) $r->id : 0;
		return $id ? ('Rater #' . $id) : 'Rater';
	}
}

if ( ! function_exists( 'icon_psy_mgmt_rater_role' ) ) {
	function icon_psy_mgmt_rater_role( $r ) {
		if ( ! is_object( $r ) ) return '';
		return icon_psy_mgmt_obj_first_field( $r, array( 'role','relationship','rater_role','rater_type','type' ) );
	}
}

if ( ! function_exists( 'icon_psy_mgmt_rater_status_label' ) ) {
	function icon_psy_mgmt_rater_status_label( $r ) {
		if ( ! is_object( $r ) ) return 'Pending';

		$st = icon_psy_mgmt_obj_first_field( $r, array( 'status' ) );
		if ( $st !== '' ) {
			$s = strtolower( trim( $st ) );
			if ( in_array( $s, array('completed','complete','submitted','done'), true ) ) return 'Completed';
			if ( in_array( $s, array('invited','sent','emailed'), true ) ) return 'Invited';
			if ( in_array( $s, array('started','in_progress','in-progress'), true ) ) return 'In progress';
			return ucfirst( $s );
		}

		foreach ( array('completed_at','submitted_at','completed_on','submitted_on') as $f ) {
			if ( isset($r->$f) && ! empty($r->$f) ) return 'Completed';
		}
		foreach ( array('invite_sent_at','invited_at','sent_at') as $f ) {
			if ( isset($r->$f) && ! empty($r->$f) ) return 'Invited';
		}
		return 'Pending';
	}
}

/* ------------------------------------------------------------
 * Credit log table (dbDelta)
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_mgmt_maybe_create_credit_log_table' ) ) {
	function icon_psy_mgmt_maybe_create_credit_log_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'icon_psy_credit_log';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			admin_user_id BIGINT(20) UNSIGNED NOT NULL,
			client_user_id BIGINT(20) UNSIGNED NOT NULL,
			mode VARCHAR(24) NOT NULL DEFAULT '',
			qty INT(11) NOT NULL DEFAULT 0,
			old_value INT(11) NOT NULL DEFAULT 0,
			new_value INT(11) NOT NULL DEFAULT 0,
			credit_source VARCHAR(24) NOT NULL DEFAULT 'admin',
			note VARCHAR(255) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY client_user_id (client_user_id),
			KEY admin_user_id (admin_user_id),
			KEY credit_source (credit_source),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
	}
}

/* ------------------------------------------------------------
 * Revenue KPI (purchase-only credits x £50)
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_mgmt_compute_total_revenue' ) ) {
	function icon_psy_mgmt_compute_total_revenue() {
		global $wpdb;
		icon_psy_mgmt_maybe_create_credit_log_table();

		$credit_value = 50;
		$log_table    = $wpdb->prefix . 'icon_psy_credit_log';

		$credits_purchased = (int) $wpdb->get_var(
			"
			SELECT COALESCE(SUM(
				CASE
					WHEN credit_source='purchase'
						 AND mode='add'
						 AND new_value != -1
						 AND qty > 0
					THEN qty
					WHEN credit_source='purchase'
						 AND mode='set'
						 AND new_value != -1
						 AND (new_value - old_value) > 0
					THEN (new_value - old_value)
					ELSE 0
				END
			), 0)
			FROM {$log_table}
			"
		);

		return (int) ( $credits_purchased * $credit_value );
	}
}

/* ------------------------------------------------------------
 * Admin-post: clear impersonation (fallback)
 * ------------------------------------------------------------ */
add_action( 'admin_post_icon_psy_clear_impersonation', function () {
	if ( ! icon_psy_mgmt_has_admin() ) wp_die( 'Not allowed' );

	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'icon_psy_clear_impersonation' ) ) {
		wp_die( 'Bad nonce' );
	}

	delete_user_meta( get_current_user_id(), 'icon_psy_impersonate_client_id' );

	$back = wp_get_referer() ? wp_get_referer() : admin_url();
	$back = remove_query_arg( array( 'icon_view', 'client_id' ), $back );
	wp_safe_redirect( $back );
	exit;
} );

/* ------------------------------------------------------------
 * EXPORT: Project report (admin-post)
 * ------------------------------------------------------------ */
add_action( 'admin_post_icon_psy_export_project_details', function () {
	if ( ! icon_psy_mgmt_has_admin() ) wp_die( 'Not allowed' );

	global $wpdb;

	$client_id = isset( $_GET['client_id'] ) ? (int) $_GET['client_id'] : 0;
	if ( $client_id <= 0 ) wp_die( 'Invalid client.' );

	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'icon_psy_export_project_details_' . $client_id ) ) {
		wp_die( 'Bad nonce' );
	}

	$u = get_user_by( 'id', $client_id );
	if ( ! $u || ! in_array( 'icon_client', (array) $u->roles, true ) ) wp_die( 'Target user is not an icon_client.' );

	$projects_table     = $wpdb->prefix . 'icon_psy_projects';
	$participants_table = $wpdb->prefix . 'icon_psy_participants';
	$raters_table       = $wpdb->prefix . 'icon_psy_raters';

	$projects = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$projects_table} WHERE client_user_id=%d ORDER BY id DESC",
			$client_id
		)
	);

	$project_ids = array();
	foreach ( (array) $projects as $pr ) $project_ids[] = isset($pr->id) ? (int) $pr->id : 0;
	$project_ids = array_values( array_filter( $project_ids ) );

	$participants_by_project = array();
	$participant_label_by_id = array();
	$participant_to_project  = array();
	$participant_ids_all     = array();

	if ( ! empty( $project_ids ) ) {
		$ph = implode(',', array_fill(0, count($project_ids), '%d'));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$participants_table} WHERE project_id IN ($ph) ORDER BY project_id DESC, id ASC",
				$project_ids
			)
		);

		foreach ( (array) $rows as $p ) {
			$pid  = isset($p->project_id) ? (int) $p->project_id : 0;
			$ppid = isset($p->id) ? (int) $p->id : 0;
			if ( $pid <= 0 || $ppid <= 0 ) continue;

			if ( ! isset( $participants_by_project[$pid] ) ) $participants_by_project[$pid] = array();
			$participants_by_project[$pid][] = $p;

			$participant_to_project[ $ppid ] = $pid;
			$participant_ids_all[] = $ppid;

			$participant_label_by_id[ $ppid ] = icon_psy_mgmt_participant_label( $p );
		}
	}

	$participant_ids_all = array_values( array_unique( array_filter( $participant_ids_all ) ) );

	$raters_by_project = array();
	$raters_total_by_project = array();
	$raters_completed_by_project = array();

	if ( ! empty( $participant_ids_all ) ) {
		$ph2 = implode(',', array_fill(0, count($participant_ids_all), '%d'));
		$raters = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$raters_table} WHERE participant_id IN ($ph2) ORDER BY id ASC",
				$participant_ids_all
			)
		);

		foreach ( (array) $raters as $r ) {
			$ppid = isset($r->participant_id) ? (int) $r->participant_id : 0;
			if ( $ppid <= 0 ) continue;

			$pid = isset($participant_to_project[$ppid]) ? (int) $participant_to_project[$ppid] : 0;
			if ( $pid <= 0 ) continue;

			if ( ! isset($raters_by_project[$pid]) ) $raters_by_project[$pid] = array();
			$raters_by_project[$pid][] = $r;

			if ( ! isset($raters_total_by_project[$pid]) ) $raters_total_by_project[$pid] = 0;
			if ( ! isset($raters_completed_by_project[$pid]) ) $raters_completed_by_project[$pid] = 0;

			$raters_total_by_project[$pid]++;

			if ( icon_psy_mgmt_rater_is_completed( $r ) ) $raters_completed_by_project[$pid]++;
		}
	}

	$total_projects = is_array($projects) ? count($projects) : 0;
	$total_participants = 0;
	foreach ( (array) $participants_by_project as $arr ) $total_participants += is_array($arr) ? count($arr) : 0;

	$total_raters = 0;
	$total_raters_completed = 0;
	foreach ( (array) $raters_total_by_project as $n ) $total_raters += (int) $n;
	foreach ( (array) $raters_completed_by_project as $n ) $total_raters_completed += (int) $n;

	$overall_completion_pct = ( $total_raters > 0 ) ? (int) round( ( $total_raters_completed / $total_raters ) * 100 ) : 0;

	nocache_headers();

	$filename = 'icon-project-report-' . $client_id . '-' . gmdate('Y-m-d') . '.html';
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

	$client_name = $u ? $u->display_name : ('Client #' . $client_id);
	$generated   = current_time( 'mysql' );

	ob_start();
	?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo esc_html( $client_name ); ?> — Project Report</title>
<style>
	:root{
		--icon-green:#15a06d; --icon-blue:#14a4cf;
		--text-dark:#0a3b34; --text-mid:#425b56; --text-light:#6a837d;
		--line: rgba(20,164,207,.14); --soft: rgba(248,250,252,.88);
	}
	*{ box-sizing:border-box; }
	body{ margin:0; font-family: system-ui,-apple-system,"Segoe UI",sans-serif; color: var(--text-dark);
		background: radial-gradient(circle at top left, #e6f9ff 0%, #ffffff 45%, #e9f8f1 100%); padding:24px; }
	.wrap{ max-width:1080px; margin:0 auto; }
	.report{ background:#fff; border:1px solid var(--line); border-radius:18px; box-shadow:0 18px 48px rgba(0,0,0,.08); overflow:hidden; }
	.cover{ position:relative; padding:22px 22px 18px;
		background: radial-gradient(900px 260px at -20% -30%, rgba(20,164,207,.22) 0%, rgba(255,255,255,0) 55%),
		          radial-gradient(800px 260px at 120% 120%, rgba(21,160,109,.18) 0%, rgba(255,255,255,0) 55%), #ffffff;
		border-bottom:1px solid var(--line); }
	.cover::before{content:""; position:absolute; left:0; right:0; top:0; height:7px; background: linear-gradient(90deg, var(--icon-blue), var(--icon-green));}
	.cover-grid{ display:flex; align-items:flex-start; justify-content:space-between; gap:14px; flex-wrap:wrap; }
	.tag{ display:inline-flex; align-items:center; gap:8px; width:fit-content; padding:7px 12px; border-radius:999px; font-size:11px; font-weight:950; letter-spacing:.10em;
		text-transform:uppercase; color:#0b2f2a; border:1px solid rgba(20,164,207,.16); background: linear-gradient(135deg, rgba(20,164,207,.08), rgba(21,160,109,.06)); }
	.tag .dot{ width:10px; height:10px; border-radius:99px; background: linear-gradient(135deg, var(--icon-blue), var(--icon-green)); box-shadow:0 10px 18px rgba(20,164,207,.16); }
	h1{ margin:0; font-size:22px; font-weight:1000; letter-spacing:-.02em; color:#0b2f2a; }
	.sub{ margin:0; color: var(--text-mid); font-size:12.5px; font-weight:900; line-height:1.45; max-width:720px; }
	.meta{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:flex-end; min-width:240px; }
	.pill{ display:inline-flex; align-items:center; gap:6px; padding:7px 10px; border-radius:999px; border:1px solid rgba(20,164,207,.16); background:#fff; color:#0b2f2a; font-weight:950; font-size:12px; white-space:nowrap; }
	.pill b{ font-weight:1000; }
	.body{ padding:16px 18px 20px; }
	.section{ margin-top:14px; border:1px solid var(--line); border-radius:14px; padding:14px; background:#fff; }
	table{ width:100%; border-collapse:separate; border-spacing:0; margin-top:10px; overflow:hidden; border-radius:14px; border:1px solid rgba(20,164,207,.14); }
	thead th{ text-align:left; font-size:11px; letter-spacing:.10em; text-transform:uppercase; color:#0b2f2a; font-weight:1000; padding:10px 12px; background:#fff; border-bottom:1px solid rgba(20,164,207,.14); }
	tbody td{ padding:10px 12px; font-size:12px; font-weight:900; color:#0f172a; border-bottom:1px solid rgba(226,232,240,.95); vertical-align:top; }
	tbody tr:last-child td{ border-bottom:none; }
	.t-muted{ color:#64748b; font-weight:900; }
	.chips{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
	.chip{ display:inline-flex; align-items:center; gap:8px; padding:7px 10px; border-radius:999px; border:1px solid rgba(20,164,207,.16);
		background: linear-gradient(135deg, rgba(20,164,207,.08), rgba(21,160,109,.06)); font-size:12px; font-weight:950; color:#0f172a; }
	.badge{ display:inline-flex; align-items:center; padding:5px 8px; border-radius:999px; font-size:10px; font-weight:1000; letter-spacing:.08em; text-transform:uppercase;
		border:1px solid rgba(20,164,207,.14); background: var(--soft); color:#0b2f2a; }
	.badge.ok{ background: rgba(236,253,245,.75); border-color: rgba(21,160,109,.22); color:#065f46; }
	.badge.warn{ background: rgba(254,249,195,.70); border-color: rgba(234,179,8,.28); color:#92400e; }
	.badge.off{ background: rgba(248,250,252,.92); border-color: rgba(100,116,139,.18); color:#334155; }
</style>
</head>
<body>
	<div class="wrap">
		<div class="report">
			<div class="cover">
				<div class="cover-grid">
					<div>
						<div class="tag"><span class="dot" aria-hidden="true"></span> Project report</div>
						<h1><?php echo esc_html( $client_name ); ?></h1>
						<p class="sub">Projects, participant names, and rater progress. Generated for quick review and printing.</p>
					</div>
					<div class="meta">
						<span class="pill">Generated <b><?php echo esc_html( $generated ); ?></b></span>
						<span class="pill">Client ID <b><?php echo (int) $client_id; ?></b></span>
						<span class="pill">Overall completion <b><?php echo (int) $overall_completion_pct; ?>%</b></span>
					</div>
				</div>
			</div>

			<div class="body">
				<div class="section">
					<div style="font-weight:1000;color:#0b2f2a;">Portfolio summary</div>
					<table>
						<thead>
							<tr>
								<th style="width:36%;">Project</th>
								<th style="width:16%;">Participants</th>
								<th style="width:16%;">Raters</th>
								<th style="width:16%;">Completed</th>
								<th style="width:16%;">Completion</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( (array) $projects as $pr ) : ?>
								<?php
									$pid   = isset($pr->id) ? (int) $pr->id : 0;
									$pname = isset($pr->name) && trim((string)$pr->name) !== '' ? (string) $pr->name : ('Project #' . $pid);

									$plist  = isset($participants_by_project[$pid]) ? (array) $participants_by_project[$pid] : array();
									$pcount = is_array($plist) ? count($plist) : 0;

									$rtotal = isset($raters_total_by_project[$pid]) ? (int) $raters_total_by_project[$pid] : 0;
									$rcomp  = isset($raters_completed_by_project[$pid]) ? (int) $raters_completed_by_project[$pid] : 0;

									$pct = ( $rtotal > 0 ) ? (int) round( ( $rcomp / $rtotal ) * 100 ) : 0;
								?>
								<tr>
									<td>
										<div style="font-weight:1000;color:#0b2f2a;"><?php echo esc_html( $pname ); ?></div>
										<div class="t-muted">Project ID: <?php echo (int) $pid; ?></div>
									</td>
									<td><?php echo (int) $pcount; ?></td>
									<td><?php echo (int) $rtotal; ?></td>
									<td><?php echo (int) $rcomp; ?></td>
									<td><?php echo (int) $pct; ?>%</td>
								</tr>
							<?php endforeach; ?>
							<?php if ( empty( $projects ) ) : ?>
								<tr><td colspan="5" class="t-muted">No projects found for this client.</td></tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<?php foreach ( (array) $projects as $pr ) : ?>
					<?php
						$pid   = isset($pr->id) ? (int) $pr->id : 0;
						$pname = isset($pr->name) && trim((string)$pr->name) !== '' ? (string) $pr->name : ('Project #' . $pid);

						$plist = isset($participants_by_project[$pid]) ? (array) $participants_by_project[$pid] : array();
						$rlist = isset($raters_by_project[$pid]) ? (array) $raters_by_project[$pid] : array();
					?>
					<div class="section" style="page-break-inside:avoid;">
						<div style="font-weight:1000;color:#0b2f2a;"><?php echo esc_html( $pname ); ?> <span class="t-muted">(ID <?php echo (int)$pid; ?>)</span></div>

						<div style="margin-top:10px;font-size:11px;color:#64748b;font-weight:1000;letter-spacing:.10em;text-transform:uppercase;">Participants (names only)</div>
						<?php if ( empty( $plist ) ) : ?>
							<div class="t-muted" style="margin-top:8px;">No participants.</div>
						<?php else : ?>
							<div class="chips">
								<?php foreach ( $plist as $p ) : ?>
									<?php $label = icon_psy_mgmt_participant_label( $p ); ?>
									<?php if ( $label !== '' ) : ?><span class="chip"><?php echo esc_html( $label ); ?></span><?php endif; ?>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<div style="margin-top:14px;font-size:11px;color:#64748b;font-weight:1000;letter-spacing:.10em;text-transform:uppercase;">Raters (names only)</div>
						<?php if ( empty( $rlist ) ) : ?>
							<div class="t-muted" style="margin-top:8px;">No raters.</div>
						<?php else : ?>
							<table>
								<thead>
									<tr>
										<th style="width:36%;">Rater</th>
										<th style="width:22%;">Role</th>
										<th style="width:22%;">Status</th>
										<th style="width:20%;">Participant</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $rlist as $r ) : ?>
										<?php
											$rlbl  = icon_psy_mgmt_rater_label( $r );
											$rrole = icon_psy_mgmt_rater_role( $r );
											$rstat = icon_psy_mgmt_rater_status_label( $r );

											$ppid = isset($r->participant_id) ? (int) $r->participant_id : 0;
											$plbl = isset($participant_label_by_id[$ppid]) ? (string) $participant_label_by_id[$ppid] : '';

											$badgeClass = 'off';
											$sl = strtolower($rstat);
											if ( $sl === 'completed' ) $badgeClass = 'ok';
											else if ( $sl === 'invited' || $sl === 'in progress' || $sl === 'in_progress' ) $badgeClass = 'warn';
										?>
										<tr>
											<td style="font-weight:1000;color:#0b2f2a;"><?php echo esc_html( $rlbl ); ?></td>
											<td><?php echo $rrole !== '' ? esc_html( $rrole ) : '<span class="t-muted">—</span>'; ?></td>
											<td><span class="badge <?php echo esc_attr($badgeClass); ?>"><?php echo esc_html( $rstat ); ?></span></td>
											<td><?php echo $plbl !== '' ? esc_html( $plbl ) : '<span class="t-muted">—</span>'; ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<div class="t-muted" style="margin-top:12px;text-align:right;">ICON Talent — Catalyst Management Portal</div>
			</div>
		</div>
	</div>
</body>
</html>
	<?php
	echo ob_get_clean();
	exit;
} );

/* ------------------------------------------------------------
 * AJAX: load embedded client portal + clear impersonation
 * ------------------------------------------------------------ */
add_action( 'wp_ajax_icon_psy_load_client_portal', function() {

	if ( ! icon_psy_mgmt_has_admin() ) wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );

	$nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'icon_psy_mgmt_nonce' ) ) wp_send_json_error( array( 'message' => 'Bad nonce.' ), 400 );

	$client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
	if ( $client_id <= 0 ) wp_send_json_error( array( 'message' => 'Invalid client.' ), 400 );

	update_user_meta( get_current_user_id(), 'icon_psy_impersonate_client_id', $client_id );

	// Important: returning full HTML from shortcode; JS in injected HTML will be executed by Management JS (see executeEmbeddedScripts()).
	wp_send_json_success( array( 'html' => do_shortcode( '[icon_psy_client_portal]' ) ) );
} );

add_action( 'wp_ajax_icon_psy_clear_impersonation', function() {

	if ( ! icon_psy_mgmt_has_admin() ) wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );

	$nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'icon_psy_mgmt_nonce' ) ) wp_send_json_error( array( 'message' => 'Bad nonce.' ), 400 );

	delete_user_meta( get_current_user_id(), 'icon_psy_impersonate_client_id' );

	wp_send_json_success( array( 'ok' => true ) );
} );

/* ------------------------------------------------------------
 * AJAX: revenue KPI
 * ------------------------------------------------------------ */
add_action( 'wp_ajax_icon_psy_get_revenue_kpi', function() {

	if ( ! icon_psy_mgmt_has_admin() ) wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );

	$nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'icon_psy_mgmt_nonce' ) ) wp_send_json_error( array( 'message' => 'Bad nonce.' ), 400 );

	$total = (int) icon_psy_mgmt_compute_total_revenue();

	wp_send_json_success( array(
		'revenue_int' => $total,
		'revenue_fmt' => '£' . number_format( $total ),
	) );
} );

/* ------------------------------------------------------------
 * AJAX: get branding (uses helpers-branding.php)
 * ------------------------------------------------------------ */
add_action( 'wp_ajax_icon_psy_get_client_branding', function() {

	if ( ! icon_psy_mgmt_has_admin() ) {
		wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
	}

	$nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'icon_psy_mgmt_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Bad nonce.' ), 400 );
	}

	$client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
	if ( $client_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'Invalid client.' ), 400 );
	}

	$u = get_user_by( 'id', $client_id );
	if ( ! $u || ! in_array( 'icon_client', (array) $u->roles, true ) ) {
		wp_send_json_error( array( 'message' => 'Target user is not an icon_client.' ), 400 );
	}

	if ( ! icon_psy_mgmt_require_branding_engine() ) {
		wp_send_json_error( array( 'message' => 'Branding engine not loaded.' ), 500 );
	}

	$b = icon_psy_get_client_branding( $client_id );
	if ( ! is_array( $b ) ) $b = array();

	$logo_url = isset($b['logo_url']) ? (string) $b['logo_url'] : '';
	$logo_id  = 0;
	if ( $logo_url ) {
		$maybe_id = attachment_url_to_postid( $logo_url );
		$logo_id  = $maybe_id ? (int) $maybe_id : 0;
	}

	$portal_slug = (string) get_user_meta( $client_id, 'icon_psy_brand_portal_slug', true );

	wp_send_json_success( array(
		'branding' => array(
			'logo_id'     => $logo_id,
			'logo_url'    => $logo_url,
			'primary'     => isset($b['primary']) ? (string) $b['primary'] : '#15a06d',
			'secondary'   => isset($b['secondary']) ? (string) $b['secondary'] : '#14a4cf',
			'portal_slug' => $portal_slug,
		),
	) );
} );

/* ------------------------------------------------------------
 * AJAX: save branding (expects helpers-branding.php exists)
 * ------------------------------------------------------------ */
add_action( 'wp_ajax_icon_psy_save_client_branding', function() {

	if ( ! icon_psy_mgmt_has_admin() ) {
		wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
	}

	$nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'icon_psy_mgmt_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Bad nonce.' ), 400 );
	}

	$client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
	if ( $client_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'Invalid client.' ), 400 );
	}

	$u = get_user_by( 'id', $client_id );
	if ( ! $u || ! in_array( 'icon_client', (array) $u->roles, true ) ) {
		wp_send_json_error( array( 'message' => 'Target user is not an icon_client.' ), 400 );
	}

	if ( ! icon_psy_mgmt_require_branding_engine() ) {
		wp_send_json_error( array( 'message' => 'Branding engine not loaded.' ), 500 );
	}

	$logo_id   = isset($_POST['logo_id']) ? (int) $_POST['logo_id'] : 0;
	$primary   = isset($_POST['primary']) ? sanitize_text_field( wp_unslash($_POST['primary']) ) : '#15a06d';
	$secondary = isset($_POST['secondary']) ? sanitize_text_field( wp_unslash($_POST['secondary']) ) : '#14a4cf';
	$slug      = isset($_POST['portal_slug']) ? sanitize_title( wp_unslash($_POST['portal_slug']) ) : '';

	if ( $primary && $primary[0] !== '#' )     $primary = '#' . $primary;
	if ( $secondary && $secondary[0] !== '#' ) $secondary = '#' . $secondary;

	// Convert attachment ID -> URL (helper stores URL)
	$logo_url = '';
	if ( $logo_id > 0 ) {
		$uurl = wp_get_attachment_url( $logo_id );
		if ( $uurl ) $logo_url = (string) $uurl;
	}

	$ok = icon_psy_save_client_branding( $client_id, array(
		'logo_url'  => $logo_url,
		'primary'   => $primary,
		'secondary' => $secondary,
	) );

	if ( ! $ok ) {
		wp_send_json_error( array( 'message' => 'Branding save FAILED.' ), 500 );
	}

	// Save portal slug separately (helper does not store this)
	update_user_meta( $client_id, 'icon_psy_brand_portal_slug', $slug );

	$b = icon_psy_get_client_branding( $client_id );
	if ( ! is_array( $b ) ) $b = array();

	wp_send_json_success( array(
		'branding' => array(
			'logo_id'     => (int) $logo_id,
			'logo_url'    => isset($b['logo_url']) ? (string) $b['logo_url'] : $logo_url,
			'primary'     => isset($b['primary']) ? (string) $b['primary'] : $primary,
			'secondary'   => isset($b['secondary']) ? (string) $b['secondary'] : $secondary,
			'portal_slug' => (string) get_user_meta( $client_id, 'icon_psy_brand_portal_slug', true ),
		),
		'message' => 'Branding saved.',
	) );
} );

/* ------------------------------------------------------------
 * ✅ AJAX: Set client credits (add/set/reset/unlimited)
 * ------------------------------------------------------------ */
add_action( 'wp_ajax_icon_psy_set_client_credits', function() {

	if ( ! icon_psy_mgmt_has_admin() ) wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );

	$nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'icon_psy_mgmt_nonce' ) ) wp_send_json_error( array( 'message' => 'Bad nonce.' ), 400 );

	$client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
	if ( $client_id <= 0 ) wp_send_json_error( array( 'message' => 'Invalid client.' ), 400 );

	$u = get_user_by( 'id', $client_id );
	if ( ! $u || ! in_array( 'icon_client', (array) $u->roles, true ) ) {
		wp_send_json_error( array( 'message' => 'Target user is not an icon_client.' ), 400 );
	}

	$mode = isset($_POST['mode']) ? sanitize_key( wp_unslash($_POST['mode']) ) : 'add';
	$qty  = isset($_POST['qty']) ? (int) $_POST['qty'] : 0;
	$note = isset($_POST['note']) ? sanitize_text_field( wp_unslash($_POST['note']) ) : '';

	$key = icon_psy_mgmt_credit_key();

	$old = icon_psy_mgmt_normalise_credits( get_user_meta( $client_id, $key, true ) );
	$new = $old;

	if ( $mode === 'unlimited' ) {
		$new = -1;
	} elseif ( $mode === 'reset' ) {
		$new = 0;
	} elseif ( $mode === 'set' ) {
		$new = max( 0, $qty );
	} else { // add
		if ( $old === -1 ) {
			$new = -1; // stay unlimited
		} else {
			if ( $qty <= 0 ) wp_send_json_error( array( 'message' => 'Enter a credit amount to add.' ), 400 );
			$new = max( 0, (int)$old ) + (int)$qty;
		}
	}

	update_user_meta( $client_id, $key, (int) $new );

	// log to DB table
	global $wpdb;
	icon_psy_mgmt_maybe_create_credit_log_table();
	$log_table = $wpdb->prefix . 'icon_psy_credit_log';

	$wpdb->insert(
		$log_table,
		array(
			'admin_user_id'  => (int) get_current_user_id(),
			'client_user_id' => (int) $client_id,
			'mode'           => (string) $mode,
			'qty'            => (int) $qty,
			'old_value'      => (int) $old,
			'new_value'      => (int) $new,
			'credit_source'  => 'admin',
			'note'           => $note ? $note : null,
			'created_at'     => current_time( 'mysql' ),
		),
		array( '%d','%d','%s','%d','%d','%d','%s','%s','%s' )
	);

	// clear any cached rollup
	delete_transient( 'icon_psy_rollup_c_' . (int) $client_id );

	wp_send_json_success( array(
		'credits' => (int) $new,
		'label'   => icon_psy_mgmt_credit_label( $new ),
	) );
} );

/* ------------------------------------------------------------
 * ✅ AJAX: Get client credit log (HTML)
 * ------------------------------------------------------------ */
add_action( 'wp_ajax_icon_psy_get_client_credit_log', function() {

	if ( ! icon_psy_mgmt_has_admin() ) wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );

	$nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'icon_psy_mgmt_nonce' ) ) wp_send_json_error( array( 'message' => 'Bad nonce.' ), 400 );

	$client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
	if ( $client_id <= 0 ) wp_send_json_error( array( 'message' => 'Invalid client.' ), 400 );

	global $wpdb;
	icon_psy_mgmt_maybe_create_credit_log_table();

	$table = $wpdb->prefix . 'icon_psy_credit_log';

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$table} WHERE client_user_id=%d ORDER BY id DESC LIMIT 25",
		$client_id
	) );

	ob_start();
	?>
	<div class="icon-credit-log">
		<div class="icon-credit-log-head">
			<div class="t">Credit log</div>
			<div class="s">Last 25 actions</div>
		</div>

		<?php if ( empty( $rows ) ) : ?>
			<div class="icon-credit-log-empty">No credit actions yet.</div>
		<?php else : ?>
			<div class="icon-credit-log-list">
				<?php foreach ( $rows as $r ) : ?>
					<div class="icon-credit-log-row">
						<div>
							<div class="m"><?php echo esc_html( strtoupper( (string) $r->mode ) ); ?> • <?php echo esc_html( (string) $r->credit_source ); ?></div>
							<div class="d">
								<span class="pill">Qty: <b><?php echo (int) $r->qty; ?></b></span>
								<span class="pill">From: <b><?php echo (int) $r->old_value; ?></b></span>
								<span class="pill">To: <b><?php echo (int) $r->new_value; ?></b></span>
							</div>
							<?php if ( ! empty( $r->note ) ) : ?>
								<div class="n"><?php echo esc_html( (string) $r->note ); ?></div>
							<?php endif; ?>
						</div>
						<div class="b">
							<div class="w"><?php echo esc_html( (string) $r->created_at ); ?></div>
							<div class="u">Admin ID: <?php echo (int) $r->admin_user_id; ?></div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
	wp_send_json_success( array( 'html' => ob_get_clean() ) );
} );

/* ------------------------------------------------------------
 * ✅ AJAX: Delete client (optionally purge all client data)
 * ------------------------------------------------------------ */
add_action( 'wp_ajax_icon_psy_delete_client', function() {

	if ( ! icon_psy_mgmt_has_admin() ) wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );

	$nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'icon_psy_mgmt_nonce' ) ) wp_send_json_error( array( 'message' => 'Bad nonce.' ), 400 );

	global $wpdb;

	$client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
	if ( $client_id <= 0 ) wp_send_json_error( array( 'message' => 'Invalid client.' ), 400 );

	$u = get_user_by( 'id', $client_id );
	if ( ! $u || ! in_array( 'icon_client', (array) $u->roles, true ) ) {
		wp_send_json_error( array( 'message' => 'Target user is not an icon_client.' ), 400 );
	}

	$purge = isset($_POST['purge']) ? (int) $_POST['purge'] : 1; // default YES

	$projects_table     = $wpdb->prefix . 'icon_psy_projects';
	$participants_table = $wpdb->prefix . 'icon_psy_participants';
	$raters_table       = $wpdb->prefix . 'icon_psy_raters';
	$credit_log_table   = $wpdb->prefix . 'icon_psy_credit_log';

	if ( $purge ) {

		$project_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$projects_table} WHERE client_user_id=%d",
			$client_id
		) );
		$project_ids = array_values( array_filter( array_map( 'intval', (array) $project_ids ) ) );

		if ( ! empty( $project_ids ) ) {
			$ph = implode(',', array_fill(0, count($project_ids), '%d'));

			$participant_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$participants_table} WHERE project_id IN ($ph)",
				$project_ids
			) );
			$participant_ids = array_values( array_filter( array_map( 'intval', (array) $participant_ids ) ) );

			if ( ! empty( $participant_ids ) ) {
				$ph2 = implode(',', array_fill(0, count($participant_ids), '%d'));
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$raters_table} WHERE participant_id IN ($ph2)",
					$participant_ids
				) );
			}

			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$participants_table} WHERE project_id IN ($ph)",
				$project_ids
			) );

			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$projects_table} WHERE id IN ($ph)",
				$project_ids
			) );
		}

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$credit_log_table} WHERE client_user_id=%d",
			$client_id
		) );
	}

	$admin_id = get_current_user_id();
	$imp = (int) get_user_meta( $admin_id, 'icon_psy_impersonate_client_id', true );
	if ( $imp === $client_id ) delete_user_meta( $admin_id, 'icon_psy_impersonate_client_id' );

	delete_user_meta( $client_id, icon_psy_mgmt_credit_key() );
	delete_user_meta( $client_id, icon_psy_mgmt_allowed_package_key() );
	delete_user_meta( $client_id, icon_psy_mgmt_allowed_frameworks_key() );

	if ( function_exists( 'icon_psy_delete_client_branding' ) ) {
		@icon_psy_delete_client_branding( $client_id );
	}
	delete_user_meta( $client_id, 'icon_psy_brand_portal_slug' );

	require_once ABSPATH . 'wp-admin/includes/user.php';
	$ok = wp_delete_user( $client_id );

	if ( ! $ok ) {
		wp_send_json_error( array( 'message' => 'Could not delete user.' ), 500 );
	}

	delete_transient( 'icon_psy_rollup_c_' . (int) $client_id );

	wp_send_json_success( array(
		'ok'        => true,
		'client_id' => (int) $client_id,
		'purged'    => (int) $purge,
	) );
} );

/* ------------------------------------------------------------
 * ✅ AJAX: Create client — ONLY Name + Email
 * ------------------------------------------------------------ */
add_action( 'wp_ajax_icon_psy_create_client', function() {

	if ( ! icon_psy_mgmt_has_admin() ) wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );

	$nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'icon_psy_mgmt_nonce' ) ) wp_send_json_error( array( 'message' => 'Bad nonce.' ), 400 );

	$name  = trim( (string) ( isset($_POST['name']) ? sanitize_text_field( wp_unslash($_POST['name']) ) : '' ) );
	$email = trim( (string) ( isset($_POST['email']) ? sanitize_email( wp_unslash($_POST['email']) ) : '' ) );

	if ( $name === '' ) wp_send_json_error( array( 'message' => 'Enter a client name.' ), 400 );
	if ( ! is_email( $email ) ) wp_send_json_error( array( 'message' => 'Enter a valid email.' ), 400 );
	if ( email_exists( $email ) ) wp_send_json_error( array( 'message' => 'That email is already in use.' ), 400 );

	$base_user = sanitize_user( current( explode( '@', $email ) ), true );
	if ( $base_user === '' ) $base_user = 'client';

	$username = $base_user;
	$i = 2;
	while ( username_exists( $username ) ) {
		$username = $base_user . $i;
		$i++;
		if ( $i > 9999 ) break;
	}

	$password = wp_generate_password( 16, true, true );

	$user_id = wp_insert_user( array(
		'user_login'   => $username,
		'user_pass'    => $password,
		'user_email'   => $email,
		'display_name' => $name,
		'role'         => 'icon_client',
	) );

	if ( is_wp_error( $user_id ) || ! $user_id ) {
		$msg = is_wp_error( $user_id ) ? $user_id->get_error_message() : 'Failed to create user.';
		wp_send_json_error( array( 'message' => $msg ), 500 );
	}

	update_user_meta( (int) $user_id, icon_psy_mgmt_credit_key(), 0 );

	// Optional: create default branding record if engine exists
	if ( function_exists( 'icon_psy_save_client_branding' ) ) {
		@icon_psy_save_client_branding( (int) $user_id, array(
			'logo_url'  => '',
			'primary'   => '#15a06d',
			'secondary' => '#14a4cf',
		) );
		update_user_meta( (int) $user_id, 'icon_psy_brand_portal_slug', '' );
	}

	// Optional: WP welcome email (site-config dependent)
	if ( function_exists( 'wp_new_user_notification' ) ) {
		@wp_new_user_notification( (int) $user_id, null, 'user' );
	}

	wp_send_json_success( array(
		'client' => array(
			'ID'               => (int) $user_id,
			'display_name'     => $name,
			'user_email'       => $email,
			'credits_raw'      => 0,
			'credits_lbl'      => '0',
			'projects'         => 0,
			'raters_total'     => 0,
			'raters_completed' => 0,
			'last_invite'      => '',
			'last_submit'      => '',
			'revenue_est'      => 0,
		),
	) );
} );

/* ------------------------------------------------------------
 * Rollups (cached)
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_mgmt_get_client_rollup' ) ) {
	function icon_psy_mgmt_get_client_rollup( $client_id, $tables ) {
		global $wpdb;

		$client_id = (int) $client_id;
		if ( $client_id <= 0 ) return array();

		$cache_key = 'icon_psy_rollup_c_' . $client_id;
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) return $cached;

		icon_psy_mgmt_maybe_create_credit_log_table();

		$projects_table     = $tables['projects'];
		$participants_table = $tables['participants'];
		$raters_table       = $tables['raters'];

		$projects = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$projects_table} WHERE client_user_id=%d ORDER BY id DESC",
				$client_id
			)
		);

		$projects_count = is_array($projects) ? count($projects) : 0;

		$project_ids = array();
		foreach ( (array) $projects as $pr ) $project_ids[] = (int) $pr->id;

		$participants_count = 0;
		$raters_total = 0;
		$raters_completed = 0;

		$last_invite = '';
		$last_submit = '';

		if ( ! empty( $project_ids ) ) {

			$ph = implode(',', array_fill(0, count($project_ids), '%d'));
			$participants = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$participants_table} WHERE project_id IN ($ph)",
					$project_ids
				)
			);

			$participants_count = is_array($participants) ? count($participants) : 0;

			$participant_ids = array();
			foreach ( (array) $participants as $p ) $participant_ids[] = (int) $p->id;

			if ( ! empty( $participant_ids ) ) {
				$ph2 = implode(',', array_fill(0, count($participant_ids), '%d'));
				$raters = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$raters_table} WHERE participant_id IN ($ph2)",
						$participant_ids
					)
				);

				foreach ( (array) $raters as $r ) {
					$raters_total++;
					if ( icon_psy_mgmt_rater_is_completed( $r ) ) $raters_completed++;

					$li = icon_psy_mgmt_best_datetime( $r, array('invite_sent_at') );
					if ( $li && ( $last_invite === '' || $li > $last_invite ) ) $last_invite = $li;

					$ls = icon_psy_mgmt_best_datetime( $r, array('completed_at','submitted_at','completed_on','submitted_on') );
					if ( $ls && ( $last_submit === '' || $ls > $last_submit ) ) $last_submit = $ls;
				}
			}
		}

		$credit_value = 50;
		$log_table    = $wpdb->prefix . 'icon_psy_credit_log';

		$credits_purchased = (int) $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT COALESCE(SUM(
					CASE
						WHEN credit_source='purchase'
							 AND mode='add'
							 AND new_value != -1
							 AND qty > 0
						THEN qty
						WHEN credit_source='purchase'
							 AND mode='set'
							 AND new_value != -1
							 AND (new_value - old_value) > 0
						THEN (new_value - old_value)
						ELSE 0
					END
				), 0)
				FROM {$log_table}
				WHERE client_user_id = %d
				",
				$client_id
			)
		);

		$revenue_est = (int) ( $credits_purchased * $credit_value );

		$out = array(
			'projects'          => (int) $projects_count,
			'participants'      => (int) $participants_count,
			'raters_total'      => (int) $raters_total,
			'raters_completed'  => (int) $raters_completed,
			'last_invite'       => (string) $last_invite,
			'last_submit'       => (string) $last_submit,
			'revenue_est'       => (int) $revenue_est,
		);

		set_transient( $cache_key, $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}
}

/* ------------------------------------------------------------
 * Shortcode renderer
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_management_portal_shortcode' ) ) {

	function icon_psy_management_portal_shortcode() {

		if ( ! icon_psy_mgmt_has_admin() ) {
			return '<p>You do not have permission to view this page.</p>';
		}

		global $wpdb;

		icon_psy_mgmt_maybe_create_credit_log_table();

		$admin_id       = (int) get_current_user_id();
		$impersonate_id = (int) get_user_meta( $admin_id, 'icon_psy_impersonate_client_id', true );

		$clear_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=icon_psy_clear_impersonation' ),
			'icon_psy_clear_impersonation'
		);

		$tables = array(
			'projects'     => $wpdb->prefix . 'icon_psy_projects',
			'participants' => $wpdb->prefix . 'icon_psy_participants',
			'raters'       => $wpdb->prefix . 'icon_psy_raters',
		);

		$clients = get_users( array(
			'role'    => 'icon_client',
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 999,
			'fields'  => array( 'ID', 'display_name', 'user_email' ),
		) );

		$kpi_clients = is_array($clients) ? count($clients) : 0;

		$all_projects = $wpdb->get_results(
			"SELECT id, client_user_id, name FROM {$tables['projects']} ORDER BY id DESC LIMIT 1500"
		);
		$kpi_projects = is_array($all_projects) ? count($all_projects) : 0;

		$rollups = array();
		$kpi_revenue_est = 0;

		foreach ( (array) $clients as $c ) {
			$cid = (int) $c->ID;
			$r = icon_psy_mgmt_get_client_rollup( $cid, $tables );
			$rollups[ $cid ] = $r;
			$kpi_revenue_est += isset($r['revenue_est']) ? (int)$r['revenue_est'] : 0;
		}

		$initial_purchased_credits = (int) floor( (int)$kpi_revenue_est / 50 );
		if ( $initial_purchased_credits < 0 ) $initial_purchased_credits = 0;

		$nonce = wp_create_nonce( 'icon_psy_mgmt_nonce' );

		$brand_logo_url = 'https://icon-talent.org/wp-content/uploads/2026/02/icon_band_centered_reversed_fixed.gif';

		ob_start();
		?>
		<style>
			:root{
				--icon-green:#15a06d;
				--icon-blue:#14a4cf;
				--text-dark:#0a3b34;
				--text-mid:#425b56;
				--text-light:#6a837d;
			}

			.icon-admin-shell{background: radial-gradient(circle at top left, #e6f9ff 0%, #ffffff 45%, #e9f8f1 100%);padding: 20px 14px 40px;}
			.icon-admin-wrap{max-width:1200px;margin:0 auto;font-family: system-ui,-apple-system,"Segoe UI",sans-serif;color: var(--text-dark);}

			.icon-card{background:#fff;border:1px solid rgba(20,164,207,.14);border-radius:12px !important;padding:18px;box-shadow:0 16px 40px rgba(0,0,0,.06);margin-bottom:14px;}
			.icon-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
			.icon-space{justify-content:space-between;}

			.icon-tag{display:inline-block;padding:4px 10px;border-radius:999px;font-size:10px;letter-spacing:.08em;text-transform:uppercase;background: rgba(21,150,140,.10);color: var(--icon-green);font-weight:900;}
			.icon-h1{margin:6px 0 0;font-size:20px;font-weight:950;letter-spacing:-.02em;}
			.icon-sub{margin:6px 0 0;color:var(--text-mid);font-size:12px;max-width:900px;line-height:1.45;}

			.icon-btn-ghost{
				display:inline-flex;align-items:center;justify-content:center;
				border-radius:999px;background:#fff;border:1px solid rgba(21,149,136,.35);
				color:var(--icon-green);padding:9px 12px;font-size:12px;font-weight:900;
				cursor:pointer;text-decoration:none;white-space:nowrap;
			}
			.icon-mini-btn{
				padding:7px 10px;border-radius:999px;border:1px solid rgba(21,149,136,.35);
				background:#fff;color:var(--icon-green);font-weight:950;cursor:pointer;white-space:nowrap;font-size:12px;
			}
			.icon-mini-input{
				padding:7px 9px;border-radius:999px;border:1px solid rgba(20,164,207,.20);
				background:#fff;font-weight:900;color:#0b2f2a;outline:none;width: 150px;font-size:12px;
			}
			.icon-mini-select{
				padding:7px 9px;border-radius:999px;border:1px solid rgba(20,164,207,.20);
				background:#fff;font-weight:900;color:#0b2f2a;outline:none;min-width: 160px;font-size:12px;
			}

			/* TOP RIGHT: Performance dial */
			.icon-top-right{margin-left:auto;display:flex;align-items:center;justify-content:flex-end;gap:12px;}
			.icon-perf{
				display:flex;align-items:center;justify-content:flex-end;gap:10px;
				padding:10px 12px;border-radius:14px;border:1px solid rgba(20,164,207,.14);
				background:
					radial-gradient(700px 160px at 0% -30%, rgba(20,164,207,.18) 0%, rgba(255,255,255,0) 60%),
					radial-gradient(700px 160px at 120% 140%, rgba(21,160,109,.12) 0%, rgba(255,255,255,0) 60%),
					#ffffff;
				box-shadow:0 12px 30px rgba(0,0,0,.06);
			}
			.icon-perf .label{font-size:11px;font-weight:950;letter-spacing:.10em;text-transform:uppercase;color:#64748b;line-height:1;white-space:nowrap;}
			.icon-perf .num{
				display:inline-flex;align-items:baseline;gap:6px;
				padding:6px 10px;border-radius:999px;border:1px solid rgba(20,164,207,.16);
				background: linear-gradient(135deg, rgba(20,164,207,.06), rgba(21,160,109,.05));
				font-weight:950;color:#0b2f2a;line-height:1;white-space:nowrap;
			}
			.icon-perf .num b{font-size:12px;letter-spacing:-.01em;}
			.icon-perf .num span{font-size:10px;font-weight:900;color:#64748b;letter-spacing:.06em;text-transform:uppercase;}
			.icon-perf svg{width:86px;height:54px;display:block;}
			.icon-perf .needle{transition:none;}
			.icon-perf .progress{stroke-dasharray:157;stroke-dashoffset:157;transition: stroke-dashoffset .25s ease, stroke .2s ease;}

			.icon-kpis{display:grid;grid-template-columns: repeat(4, minmax(0, 1fr));gap:10px;margin-top:14px;}
			@media(max-width:1180px){ .icon-kpis{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
			@media(max-width:520px){ .icon-kpis{ grid-template-columns: 1fr; } }

			.icon-kpi{
				position:relative;border-radius:12px !important;padding:12px 12px 10px;
				background:
					radial-gradient(900px 220px at -20% -30%, rgba(20,164,207,.18) 0%, rgba(255,255,255,0) 55%),
					radial-gradient(700px 220px at 120% 120%, rgba(21,160,109,.14) 0%, rgba(255,255,255,0) 55%),
					#ffffff;
				border:1px solid rgba(20,164,207,.14);
				box-shadow:0 14px 34px rgba(0,0,0,.06);
				overflow:hidden;min-height:150px;
			}
			.icon-kpi::before{content:"";position:absolute;left:0;right:0;top:0;height:4px;background: linear-gradient(90deg, var(--icon-blue), var(--icon-green));opacity:.95;}
			.icon-kpi .t{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;font-weight:900;}
			.icon-kpi .v{font-size:18px;font-weight:950;margin-top:6px;color:#0b2f2a;letter-spacing:-.02em;}
			.icon-kpi .s{font-size:11px;color:#64748b;margin-top:7px;line-height:1.25;}
			.icon-kpi-controls{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px;align-items:center;}

			.icon-qc-presets{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px;}
			.icon-qc-chip{
				border-radius:999px;padding:6px 9px;border:1px solid rgba(20,164,207,.16);
				background: linear-gradient(135deg, rgba(20,164,207,.08), rgba(21,160,109,.06));
				font-size:11px;font-weight:950;cursor:pointer;color:#0b2f2a;
			}
			.icon-qc-status{font-size:11px;font-weight:900;color:#64748b;}

			/* Client grid */
			.icon-client-grid{display:grid;grid-template-columns: repeat(3, minmax(0,1fr));gap:18px;margin-top:14px;}
			@media(max-width:1100px){ .icon-client-grid{grid-template-columns: repeat(2, minmax(0,1fr));} }
			@media(max-width:640px){ .icon-client-grid{grid-template-columns: 1fr;} }

			.icon-client-card{
				position:relative;display:flex;flex-direction:column;gap:10px;min-height:205px;
				padding:16px 16px 14px;border-radius:12px;
				background:
					radial-gradient(1200px 280px at -20% -30%, rgba(20,164,207,.18) 0%, rgba(255,255,255,0) 55%),
					radial-gradient(900px 260px at 120% 120%, rgba(21,160,109,.14) 0%, rgba(255,255,255,0) 55%),
					#ffffff;
				border:1px solid rgba(20,164,207,.14);
				box-shadow:0 16px 40px rgba(0,0,0,.06);
				cursor:pointer;overflow:hidden;
				transition: transform .14s ease, box-shadow .14s ease, border-color .14s ease;
			}
			.icon-client-card::before{content:"";position:absolute;left:0;right:0;top:0;height:5px;background: linear-gradient(90deg, var(--icon-blue), var(--icon-green));opacity:.95;}
			.icon-client-card:hover{transform: translateY(-3px);box-shadow:0 22px 52px rgba(0,0,0,.10);border-color: rgba(21,160,109,.25);}
			.icon-client-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;}
			.icon-client-name{margin:0;font-weight:950;font-size:14px;line-height:1.15;letter-spacing:-.01em;color:#0b2f2a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
			.icon-client-email{margin:4px 0 0;font-size:11.5px;line-height:1.25;color:#64748b;opacity:.95;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
			.icon-toggle{
				width:32px;height:32px;border-radius:999px;border:1px solid rgba(20,164,207,.20);
				background: linear-gradient(135deg, rgba(20,164,207,.10), rgba(21,160,109,.10)), #ffffff;
				display:flex;align-items:center;justify-content:center;flex:0 0 auto;
				font-weight:950;color:#0b2f2a;font-size:12px;box-shadow:0 10px 22px rgba(20,164,207,.10);
			}
			.icon-toggle::before{content:"→";}
			.icon-client-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;}
			.icon-client-action{
				display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;
				border:1px solid rgba(20,164,207,.18);background: rgba(255,255,255,.95);
				color:#0b2f2a;font-weight:950;font-size:12px;text-decoration:none;cursor:pointer;
				box-shadow:0 10px 22px rgba(0,0,0,.04);
			}
			.icon-client-action .i{
				width:22px;height:22px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;
				color:#fff;font-weight:950;font-size:12px;background: linear-gradient(135deg, var(--icon-blue), var(--icon-green));
				box-shadow:0 10px 22px rgba(20,164,207,.18);
			}

			/* Embedded portal */
			.icon-embed{ display:none; margin-top:14px; }
			.icon-embed.is-open{ display:block; }
			.icon-loader{display:none;padding:14px;border-radius:12px;border:1px solid rgba(226,232,240,.9);background:#fff;font-weight:900;color:#0b2f2a;}
			.icon-loader.is-on{ display:block; }

			.icon-credit-controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;}
			.icon-credit-pill{
				display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;
				border:1px solid rgba(20,164,207,.16);
				background: linear-gradient(135deg, rgba(20,164,207,.08), rgba(21,160,109,.06));
				font-size:12px;font-weight:950;color:#0b2f2a;
			}
			.icon-credit-input{width:120px;padding:10px 12px;border-radius:999px;border:1px solid rgba(20,164,207,.20);background:#fff;font-weight:900;color:#0b2f2a;outline:none;}
			.icon-credit-select{padding:10px 12px;border-radius:999px;border:1px solid rgba(20,164,207,.20);background:#fff;font-weight:900;color:#0b2f2a;outline:none;}
			.icon-switch{position:relative;width:54px;height:32px;border-radius:999px;border:1px solid rgba(20,164,207,.20);background: rgba(148,163,184,.14);box-shadow:0 10px 22px rgba(20,164,207,.10);cursor:pointer;display:inline-flex;align-items:center;padding:3px;user-select:none;}
			.icon-switch .dot{width:26px;height:26px;border-radius:999px;background:#fff;box-shadow:0 10px 22px rgba(0,0,0,.08);transform: translateX(0);transition: transform .14s ease;}
			.icon-switch.is-on{background: linear-gradient(135deg, rgba(20,164,207,.30), rgba(21,160,109,.26));border-color: rgba(21,160,109,.25);}
			.icon-switch.is-on .dot{ transform: translateX(22px); }
			.icon-credit-hint{font-size:11px;color:#64748b;font-weight:900;}

			/* Credit log */
			.icon-credit-log{ margin-top:12px;border-radius:12px;border:1px solid rgba(226,232,240,.9);background:#fff;padding:12px; }
			.icon-credit-log-head{display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px;}
			.icon-credit-log-head .t{font-weight:950;color:#0b2f2a;font-size:13px;}
			.icon-credit-log-head .s{font-weight:900;color:#64748b;font-size:11px;}
			.icon-credit-log-empty{font-weight:900;color:#64748b;font-size:12px;padding:8px 0;}
			.icon-credit-log-list{display:flex;flex-direction:column;gap:10px;}
			.icon-credit-log-row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:10px 10px;border-radius:12px;border:1px solid rgba(20,164,207,.12);background:#fff;}
			.icon-credit-log-row .m{font-weight:950;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#0b2f2a;}
			.icon-credit-log-row .d{margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;}
			.icon-credit-log-row .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid rgba(20,164,207,.14);background: rgba(236,253,245,.55);font-size:11px;font-weight:900;color:#0b2f2a;}
			.icon-credit-log-row .n{margin-top:6px;font-size:12px;color:#425b56;font-weight:900;}
			.icon-credit-log-row .b{text-align:right;margin-left:auto;}
			.icon-credit-log-row .w,.icon-credit-log-row .u{font-size:11px;color:#64748b;font-weight:900;}
			.icon-credit-log-row .u{margin-top:4px;}

			/* Modal (FIXED) */
			.icon-modal{
				position:fixed;
				inset:0;
				display:none;
				align-items:center;
				justify-content:center;
				padding:18px;
				z-index: 9999; /* keep BELOW wp.media */
			}
			.icon-modal.is-open{display:flex;}
			.icon-modal-backdrop{position:absolute;inset:0;background: rgba(15,23,42,.45);backdrop-filter: blur(2px);}
			.icon-modal-card{position:relative;width:min(720px, 100%);max-height: calc(100vh - 36px);overflow:auto;border-radius:14px;border:1px solid rgba(20,164,207,.16);background:#fff;box-shadow: 0 30px 90px rgba(0,0,0,.25);padding:14px;}
		</style>

		<div class="icon-admin-shell">
			<div class="icon-admin-wrap">

				<div class="icon-card">
					<div class="icon-row icon-space">
						<div>
							<?php if ( ! empty( $brand_logo_url ) ) : ?>
								<img src="<?php echo esc_url( $brand_logo_url ); ?>"
									alt=""
									style="max-height:46px;max-width:180px;object-fit:contain;margin-bottom:10px;">
							<?php endif; ?>
							<span class="icon-tag">Management</span>
							<h1 class="icon-h1">Icon Catalyst – Management Portal</h1>
							<p class="icon-sub">Click a client card to load their full portal view below (impersonation enabled).</p>
						</div>

						<div class="icon-top-right">
							<div class="icon-perf" aria-label="Performance (purchased credits progress to 100)" role="img">
								<div class="label">Performance</div>
								<div class="num" aria-hidden="true">
									<b id="icon-perf-num"><?php echo (int) $initial_purchased_credits; ?></b>
									<span>/100</span>
								</div>

								<svg viewBox="0 0 100 60" aria-hidden="true">
									<path d="M 10 50 A 40 40 0 0 1 90 50" fill="none" stroke="rgba(185,28,28,.28)" stroke-width="10" stroke-linecap="round"></path>
									<path id="icon-perf-arc" class="progress" d="M 10 50 A 40 40 0 0 1 90 50" fill="none" stroke="rgba(21,160,109,.88)" stroke-width="10" stroke-linecap="round"></path>
									<circle cx="50" cy="50" r="4" fill="rgba(11,47,42,.85)"></circle>
									<g id="icon-perf-needle" class="needle">
										<line x1="50" y1="50" x2="82" y2="50" stroke="rgba(11,47,42,.9)" stroke-width="3" stroke-linecap="round"></line>
									</g>
								</svg>
							</div>

							<?php if ( $impersonate_id > 0 ) : ?>
								<a class="icon-btn-ghost" href="<?php echo esc_url( $clear_url ); ?>">Clear impersonation</a>
							<?php endif; ?>
						</div>
					</div>

					<div class="icon-kpis">

						<div class="icon-kpi">
							<div class="t">Clients</div>
							<div class="v" id="icon-kpi-clients"><?php echo (int) $kpi_clients; ?></div>
							<div class="icon-kpi-controls">
								<select id="icon-jump-client" class="icon-mini-select" aria-label="Jump to client">
									<option value="">Find client…</option>
									<?php foreach ( (array) $clients as $c ) : ?>
										<option value="<?php echo (int) $c->ID; ?>"><?php echo esc_html( $c->display_name ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="button" class="icon-mini-btn" id="icon-jump-client-btn">Open</button>
							</div>
							<div class="s">Jump to a client card</div>
						</div>

						<div class="icon-kpi">
							<div class="t">Projects</div>
							<div class="v"><?php echo (int) $kpi_projects; ?></div>
							<div class="icon-kpi-controls">
								<select id="icon-jump-project" class="icon-mini-select" aria-label="Jump to project">
									<option value="">Find project…</option>
									<?php foreach ( (array) $all_projects as $p ) : ?>
										<?php
											$pcid = (int) $p->client_user_id;
											$pname = isset($p->name) ? (string) $p->name : ('Project #' . (int) $p->id);
											$client_name = '';
											foreach ( (array) $clients as $cc ) {
												if ( (int) $cc->ID === $pcid ) { $client_name = $cc->display_name; break; }
											}
											$label = trim($client_name) ? ($client_name . ' — ' . $pname) : $pname;
										?>
										<option value="<?php echo esc_attr( $pcid ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="button" class="icon-mini-btn" id="icon-jump-project-btn">Open</button>
							</div>
							<div class="s">Opens client portal</div>
						</div>

						<div class="icon-kpi">
							<div class="t">Quick credits</div>
							<div class="icon-kpi-controls">
								<select id="icon-qc-client" class="icon-mini-select" aria-label="Select client">
									<option value="">Select client…</option>
									<?php foreach ( (array) $clients as $c ) : ?>
										<option value="<?php echo (int) $c->ID; ?>"><?php echo esc_html( $c->display_name ); ?></option>
									<?php endforeach; ?>
								</select>

								<select id="icon-qc-mode" class="icon-mini-select" aria-label="Credit mode" style="min-width:96px;">
									<option value="add">Add</option>
									<option value="set">Set</option>
									<option value="unlimited">Unlimited</option>
									<option value="reset">Set to 0</option>
								</select>

								<input id="icon-qc-qty" class="icon-mini-input" type="number" min="0" step="1" placeholder="Qty" aria-label="Quantity" />
							</div>

							<div class="icon-qc-presets">
								<button type="button" class="icon-qc-chip" data-qc-add="5">+5</button>
								<button type="button" class="icon-qc-chip" data-qc-add="10">+10</button>
								<button type="button" class="icon-qc-chip" data-qc-add="20">+20</button>
							</div>

							<div class="icon-kpi-controls" style="margin-top:8px;">
								<button type="button" class="icon-mini-btn" id="icon-qc-apply">Issue</button>
								<span class="icon-qc-status" id="icon-qc-status" aria-live="polite"></span>
							</div>

							<div class="s">Logs to credit log</div>
						</div>

						<!-- ✅ UPDATED: Add client ONLY Name + Email -->
						<div class="icon-kpi">
							<div class="t">Add client</div>

							<div class="icon-kpi-controls">
								<input id="icon-addclient-name" class="icon-mini-input" type="text" placeholder="Name" aria-label="Client name" />
								<input id="icon-addclient-email" class="icon-mini-input" type="email" placeholder="Email" aria-label="Client email" />
							</div>

							<div class="icon-kpi-controls" style="margin-top:8px;">
								<button type="button" class="icon-mini-btn" id="icon-addclient-btn">Create</button>
								<span class="icon-qc-status" id="icon-addclient-status" aria-live="polite"></span>
							</div>

							<div class="s">Creates icon_client</div>
						</div>

					</div>
				</div>

				<!-- Embedded client portal -->
				<div id="icon-embed" class="icon-embed">
					<div class="icon-card">

						<div class="icon-row icon-space" style="margin-bottom:10px;">
							<div>
								<div style="font-weight:950;color:#0b2f2a;" id="icon-embed-client">Client portal</div>
								<div style="font-size:12px;color:#64748b;font-weight:900;" id="icon-embed-sub">Loaded inside Management</div>
							</div>

							<div class="icon-credit-controls">
								<div class="icon-credit-pill" id="icon-credit-current">Credits: <b>—</b></div>

								<div class="icon-row" style="gap:8px;">
									<div class="icon-credit-hint">Unlimited</div>
									<div class="icon-switch" id="icon-credit-unlimited" role="switch" aria-checked="false" tabindex="0">
										<div class="dot" aria-hidden="true"></div>
									</div>
								</div>

								<input id="icon-credit-qty" class="icon-credit-input" type="number" min="0" step="1" placeholder="Credits" />
								<select id="icon-credit-mode" class="icon-credit-select">
									<option value="add">Add</option>
									<option value="set">Set</option>
								</select>

								<button type="button" class="icon-btn-ghost" id="icon-credit-apply">Apply</button>
								<button type="button" class="icon-btn-ghost" id="icon-credit-zero">Set to 0</button>

								<button type="button" class="icon-btn-ghost" id="icon-back-btn">Back</button>
							</div>
						</div>

						<div id="icon-loader" class="icon-loader">Loading client portal…</div>
						<div id="icon-credit-log-slot"></div>
						<div id="icon-embed-body"></div>
					</div>
				</div>

				<!-- Client grid -->
				<div class="icon-card">
					<div class="icon-row icon-space">
						<div>
							<div class="icon-tag">Clients</div>
							<h2 class="icon-h1" style="font-size:18px;margin-top:8px;">Client overview</h2>
							<p class="icon-sub">Click a client to open their portal. Use Export / Branding / Delete from each card.</p>
						</div>
					</div>

					<div class="icon-client-grid" id="icon-client-grid">
						<?php if ( empty( $clients ) ) : ?>
							<div class="icon-sub">No users found with role <b>icon_client</b>.</div>
						<?php else : ?>
							<?php foreach ( (array) $clients as $c ) : ?>
								<?php
									$cid = (int) $c->ID;

									$r = isset($rollups[$cid]) ? (array)$rollups[$cid] : array();
									$projects  = isset($r['projects']) ? (int)$r['projects'] : 0;
									$rt        = isset($r['raters_total']) ? (int)$r['raters_total'] : 0;
									$rc        = isset($r['raters_completed']) ? (int)$r['raters_completed'] : 0;
									$revenue   = isset($r['revenue_est']) ? (int)$r['revenue_est'] : 0;
									$completion_pct = $rt > 0 ? (int) round(($rc / $rt) * 100) : 0;

									$credits_val = icon_psy_mgmt_normalise_credits( get_user_meta( $cid, icon_psy_mgmt_credit_key(), true ) );
									$credits_lbl = icon_psy_mgmt_credit_label( $credits_val );

									$export_url = wp_nonce_url(
										admin_url( 'admin-post.php?action=icon_psy_export_project_details&client_id=' . $cid ),
										'icon_psy_export_project_details_' . $cid
									);
								?>
								<div class="icon-client-card"
									 role="button"
									 tabindex="0"
									 data-client-id="<?php echo (int) $cid; ?>"
									 data-client-name="<?php echo esc_attr( $c->display_name ); ?>"
									 data-client-email="<?php echo esc_attr( $c->user_email ); ?>"
									 data-client-credits="<?php echo esc_attr( $credits_lbl ); ?>"
									 data-client-credits-raw="<?php echo esc_attr( (string) $credits_val ); ?>"
									 data-projects="<?php echo esc_attr( (string) $projects ); ?>"
									 data-raters-total="<?php echo esc_attr( (string) $rt ); ?>"
									 data-raters-completed="<?php echo esc_attr( (string) $rc ); ?>"
									 data-completion-pct="<?php echo esc_attr( (string) $completion_pct ); ?>"
									 data-revenue-est="<?php echo esc_attr( (string) $revenue ); ?>">

									<div class="icon-client-top">
										<div style="min-width:0;">
											<div class="icon-client-name"><?php echo esc_html( $c->display_name ); ?></div>
											<div class="icon-client-email"><?php echo esc_html( $c->user_email ); ?></div>

											<div style="font-size:12px;color:#64748b;font-weight:900;margin-top:8px;">
												Projects: <b style="color:#0b2f2a;"><?php echo (int) $projects; ?></b>
												&nbsp;•&nbsp; Completion: <b style="color:#0b2f2a;"><?php echo (int) $completion_pct; ?>%</b>
												&nbsp;•&nbsp; Credits: <b style="color:#0b2f2a;"><?php echo esc_html( $credits_lbl ); ?></b>
											</div>

											<div class="icon-client-actions">
												<a class="icon-client-action"
												   href="<?php echo esc_url( $export_url ); ?>"
												   target="_blank"
												   rel="noopener"
												   onclick="event.stopPropagation();">
													<span class="i">i</span>
													Export report
												</a>

												<button class="icon-client-action"
														type="button"
														onclick="event.stopPropagation();"
														data-branding-open="<?php echo (int) $cid; ?>">
													<span class="i">🎨</span>
													Branding
												</button>

												<button class="icon-client-action"
														type="button"
														onclick="event.stopPropagation();"
														data-delete-client="<?php echo (int) $cid; ?>">
													<span class="i">🗑</span>
													Delete
												</button>
											</div>
										</div>

										<div class="icon-toggle" aria-hidden="true"></div>
									</div>

								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

				</div>

			</div>
		</div>

		<!-- Branding modal -->
		<div class="icon-modal" id="icon-branding-modal" aria-hidden="true">
			<div class="icon-modal-backdrop" data-branding-close></div>

			<div class="icon-modal-card" role="dialog" aria-modal="true" aria-labelledby="icon-branding-title">
				<div class="icon-row icon-space" style="margin-bottom:8px;">
					<div>
						<div id="icon-branding-title" style="font-weight:950;color:#0b2f2a;">Client branding</div>
						<div style="font-size:12px;color:#64748b;font-weight:900;" id="icon-branding-client-name">—</div>
					</div>
					<button type="button" class="icon-btn-ghost" data-branding-close>Close</button>
				</div>

				<div style="border-top:1px solid rgba(226,232,240,.9);padding-top:12px;">
					<div class="icon-row" style="gap:12px;align-items:flex-start;flex-wrap:wrap;">
						<div style="flex:1;min-width:220px;">
							<div style="font-size:12px;color:#64748b;font-weight:900;margin-bottom:6px;">Logo</div>

							<div class="icon-row" style="gap:10px;align-items:center;flex-wrap:wrap;">
								<button type="button" class="icon-btn-ghost" id="icon-branding-pick-logo">Choose logo</button>
								<button type="button" class="icon-btn-ghost" id="icon-branding-clear-logo">Remove</button>
								<input id="icon-branding-logo-id" type="hidden" value="0">
							</div>

							<div style="font-size:11px;color:#64748b;font-weight:900;margin-top:6px;">
								Picks an image from the WordPress Media Library.
							</div>
						</div>

						<div style="flex:1;min-width:220px;">
							<div style="font-size:12px;color:#64748b;font-weight:900;margin-bottom:6px;">Portal slug</div>
							<input id="icon-branding-slug" class="icon-mini-input" type="text" style="width:100%;max-width:240px;" placeholder="e.g. acme">
						</div>
					</div>

					<div class="icon-row" style="gap:12px;margin-top:12px;flex-wrap:wrap;">
						<div style="flex:1;min-width:220px;">
							<div style="font-size:12px;color:#64748b;font-weight:900;margin-bottom:6px;">Primary colour</div>
							<input id="icon-branding-primary" class="icon-mini-input" type="text" style="width:100%;max-width:240px;" placeholder="#15a06d">
						</div>

						<div style="flex:1;min-width:220px;">
							<div style="font-size:12px;color:#64748b;font-weight:900;margin-bottom:6px;">Secondary colour</div>
							<input id="icon-branding-secondary" class="icon-mini-input" type="text" style="width:100%;max-width:240px;" placeholder="#14a4cf">
						</div>
					</div>

					<div class="icon-row" style="gap:12px;margin-top:12px;align-items:center;flex-wrap:wrap;">
						<div style="display:flex;align-items:center;gap:10px;">
							<div style="width:44px;height:44px;border-radius:12px;border:1px solid rgba(226,232,240,.9);background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;">
								<img id="icon-branding-logo-preview" src="" alt="" style="max-width:100%;max-height:100%;display:none;">
							</div>
							<div style="font-size:12px;color:#64748b;font-weight:900;">Preview</div>
						</div>

						<div style="margin-left:auto;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
							<div id="icon-branding-status" style="font-size:12px;color:#64748b;font-weight:900;"></div>
							<button type="button" class="icon-mini-btn" id="icon-branding-save">Save branding</button>
						</div>
					</div>
				</div>

				<input type="hidden" id="icon-branding-client-id" value="0">
			</div>
		</div>

		<script>
		(function(){
			var ajaxUrl = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
			var nonce   = "<?php echo esc_js( $nonce ); ?>";

			function $(sel, root){ return (root || document).querySelector(sel); }
			function $all(sel, root){ return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

			function post(data){
				return fetch(ajaxUrl, {
					method: 'POST',
					headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
					body: new URLSearchParams(data).toString(),
					credentials: 'same-origin'
				}).then(function(r){ return r.json(); });
			}

			// ---------------------------------------------------------
			// Embedded portal
			// ---------------------------------------------------------
			var embed = $('#icon-embed');
			var embedBody = $('#icon-embed-body');
			var loader = $('#icon-loader');
			var embedClient = $('#icon-embed-client');
			var embedSub = $('#icon-embed-sub');
			var backBtn = $('#icon-back-btn');

			var creditCurrent = $('#icon-credit-current');
			var creditSwitch  = $('#icon-credit-unlimited');
			var creditQty     = $('#icon-credit-qty');
			var creditMode    = $('#icon-credit-mode');
			var creditApply   = $('#icon-credit-apply');
			var creditZero    = $('#icon-credit-zero');
			var creditLogSlot = $('#icon-credit-log-slot');

			var activeClientId = null;

			function setLoading(on){
				if (!loader) return;
				loader.classList.toggle('is-on', !!on);
			}
			function showEmbed(){
				if (!embed) return;
				embed.classList.add('is-open');
				embed.scrollIntoView({behavior:'smooth', block:'start'});
			}
			function hideEmbed(){
				if (!embed) return;
				embed.classList.remove('is-open');
				if (embedBody) embedBody.innerHTML = '';
				if (creditLogSlot) creditLogSlot.innerHTML = '';
				activeClientId = null;
			}

			function setCreditUI(label, raw){
				if (creditCurrent) creditCurrent.innerHTML = 'Credits: <b>' + (label || '—') + '</b>';

				var isUnlimited = (String(raw) === '-1' || String(label).toLowerCase() === 'unlimited');

				if (creditSwitch) {
					creditSwitch.classList.toggle('is-on', !!isUnlimited);
					creditSwitch.setAttribute('aria-checked', isUnlimited ? 'true' : 'false');
				}
				if (creditQty) creditQty.disabled = !!isUnlimited;
				if (creditMode) creditMode.disabled = !!isUnlimited;
			}

			function loadCreditLog(){
				if (!activeClientId || !creditLogSlot) return;
				post({ action:'icon_psy_get_client_credit_log', nonce:nonce, client_id: activeClientId })
				.then(function(resp){
					if (!resp || !resp.success) { creditLogSlot.innerHTML = ''; return; }
					creditLogSlot.innerHTML = resp.data.html || '';
				}).catch(function(){ creditLogSlot.innerHTML = ''; });
			}

			// ✅ IMPORTANT FIX: run <script> tags inside injected portal HTML so pills/buttons work
			function executeEmbeddedScripts(container){
				if (!container) return;

				// Execute inline + external scripts found in container
				var scripts = container.querySelectorAll('script');
				if (!scripts || !scripts.length) return;

				scripts.forEach(function(old){
					try{
						var s = document.createElement('script');

						// copy attributes (src, type, etc.)
						for (var i=0; i<old.attributes.length; i++){
							var a = old.attributes[i];
							if (a && a.name) s.setAttribute(a.name, a.value);
						}

						if (!s.type) s.type = 'text/javascript';

						if (old.src) {
							s.src = old.src;
							s.async = false;
						} else {
							s.text = old.textContent || '';
						}

						// Replace old script so browser executes the new one
						old.parentNode.replaceChild(s, old);
					}catch(err){}
				});

				// Let any portal code listen for a "ready" signal
				try{
					document.dispatchEvent(new CustomEvent('icon_psy_portal_embedded_ready', { detail: { client_id: activeClientId } }));
				}catch(e){}
			}

			function openClient(clientId, clientName, creditsLabel, creditsRaw){
				if (!clientId) return;

				activeClientId = clientId;

				if (embedClient) embedClient.textContent = clientName ? (clientName + ' — Client Portal') : 'Client Portal';
				if (embedSub) embedSub.textContent = 'Loaded inside ICON Management (impersonation enabled)';

				setCreditUI(creditsLabel || '—', creditsRaw || '');

				showEmbed();
				setLoading(true);
				if (embedBody) embedBody.innerHTML = '';
				if (creditLogSlot) creditLogSlot.innerHTML = '';

				post({ action:'icon_psy_load_client_portal', nonce:nonce, client_id: clientId })
				.then(function(resp){
					if (!resp || !resp.success) {
						var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to load client portal.';
						if (embedBody) embedBody.innerHTML =
							'<div style="padding:12px;border-radius:12px;border:1px solid rgba(185,28,28,.25);background:rgba(254,226,226,.38);color:#7f1d1d;font-weight:900;">'+ msg +'</div>';
						return;
					}
					if (embedBody) embedBody.innerHTML = resp.data.html || '';

					// ✅ run scripts AFTER inject so the portal pills/buttons bind correctly
					executeEmbeddedScripts(embedBody);

					loadCreditLog();
				})
				.catch(function(){
					if (embedBody) embedBody.innerHTML =
						'<div style="padding:12px;border-radius:12px;border:1px solid rgba(185,28,28,.25);background:rgba(254,226,226,.38);color:#7f1d1d;font-weight:900;">Network error loading client portal.</div>';
				})
				.finally(function(){ setLoading(false); });
			}

			$all('.icon-client-card').forEach(function(card){
				card.addEventListener('click', function(){
					openClient(
						card.getAttribute('data-client-id'),
						card.getAttribute('data-client-name'),
						card.getAttribute('data-client-credits'),
						card.getAttribute('data-client-credits-raw')
					);
				});
				card.addEventListener('keydown', function(e){
					if (e.key === 'Enter' || e.key === ' ') {
						e.preventDefault();
						openClient(
							card.getAttribute('data-client-id'),
							card.getAttribute('data-client-name'),
							card.getAttribute('data-client-credits'),
							card.getAttribute('data-client-credits-raw')
						);
					}
				});
			});

			function clearImpersonation(){
				setLoading(true);
				post({ action:'icon_psy_clear_impersonation', nonce:nonce })
				.finally(function(){
					setLoading(false);
					hideEmbed();
				});
			}
			if (backBtn) backBtn.addEventListener('click', function(e){ e.preventDefault(); clearImpersonation(); });

			// Credits apply/reset/unlimited
			function applyCredits(mode, qty){
				if (!activeClientId) return;

				setLoading(true);
				post({ action:'icon_psy_set_client_credits', nonce:nonce, client_id:activeClientId, mode:mode, qty:qty, note:'' })
				.then(function(resp){
					if (!resp || !resp.success) {
						alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to update credits.');
						return;
					}

					var label = (resp.data && resp.data.label) ? resp.data.label : '';
					var raw   = (resp.data && typeof resp.data.credits !== 'undefined') ? String(resp.data.credits) : '';
					setCreditUI(label, raw);

					var card = document.querySelector('.icon-client-card[data-client-id="'+activeClientId+'"]');
					if (card) {
						card.setAttribute('data-client-credits', label);
						card.setAttribute('data-client-credits-raw', raw);
					}

					loadCreditLog();
					refreshPerformanceDial();
				})
				.finally(function(){ setLoading(false); });
			}

			if (creditApply) creditApply.addEventListener('click', function(){
				if (!activeClientId) return;
				if (creditSwitch && creditSwitch.classList.contains('is-on')) return;

				var mode = creditMode ? creditMode.value : 'add';
				var qty  = creditQty ? parseInt(creditQty.value || '0', 10) : 0;

				if (mode === 'add' && qty <= 0) { alert('Enter a credit amount to add.'); return; }
				if (mode === 'set' && qty < 0) qty = 0;

				applyCredits(mode, qty);
			});
			if (creditZero) creditZero.addEventListener('click', function(){ if (activeClientId) applyCredits('reset', 0); });

			if (creditSwitch) {
				function toggleUnlimited(){
					if (!activeClientId) return;
					var isOn = creditSwitch.classList.contains('is-on');
					if (isOn) applyCredits('set', 0);
					else applyCredits('unlimited', 0);
				}
				creditSwitch.addEventListener('click', function(){ toggleUnlimited(); });
				creditSwitch.addEventListener('keydown', function(e){
					if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleUnlimited(); }
				});
			}

			// KPI Jump
			var jumpClient = $('#icon-jump-client');
			var jumpClientBtn = $('#icon-jump-client-btn');
			var jumpProject = $('#icon-jump-project');
			var jumpProjectBtn = $('#icon-jump-project-btn');

			function openClientFromId(cid){
				if (!cid) return;
				var card = document.querySelector('.icon-client-card[data-client-id="'+cid+'"]');
				if (!card) return;
				card.scrollIntoView({behavior:'smooth', block:'center'});
				openClient(
					card.getAttribute('data-client-id'),
					card.getAttribute('data-client-name'),
					card.getAttribute('data-client-credits'),
					card.getAttribute('data-client-credits-raw')
				);
			}

			if (jumpClientBtn) jumpClientBtn.addEventListener('click', function(){ openClientFromId(jumpClient ? jumpClient.value : ''); });
			if (jumpProjectBtn) jumpProjectBtn.addEventListener('click', function(){ openClientFromId(jumpProject ? jumpProject.value : ''); });

			// Performance dial
			var perfNeedle = $('#icon-perf-needle');
			var perfArc    = $('#icon-perf-arc');
			var perfNum    = $('#icon-perf-num');

			function clamp(n,a,b){ return Math.max(a, Math.min(b, n)); }

			function setPerformanceDial(purchasedCredits){
				var goal = 100;
				var c = parseInt(purchasedCredits || 0, 10);
				if (!isFinite(c)) c = 0;
				c = clamp(c, 0, goal);

				var p = clamp(c / goal, 0, 1);
				var deg = (-180) + (180 * p);

				if (perfNeedle) perfNeedle.setAttribute('transform', 'rotate(' + deg + ' 50 50)');

				if (perfArc){
					var len = 157;
					perfArc.style.strokeDasharray = '157';
					perfArc.style.strokeDashoffset = String(len * (1 - p));
					perfArc.style.stroke =
						(c < 35) ? 'rgba(185,28,28,.85)' :
						(c < 70) ? 'rgba(234,179,8,.90)' :
								   'rgba(21,160,109,.88)';
				}

				if (perfNum) perfNum.textContent = String(c);
			}

			function refreshPerformanceDial(){
				post({ action:'icon_psy_get_revenue_kpi', nonce:nonce })
				.then(function(resp){
					if (!resp || !resp.success) return;
					var revenueInt = resp.data && typeof resp.data.revenue_int !== 'undefined' ? parseInt(resp.data.revenue_int, 10) : 0;
					if (!isFinite(revenueInt)) revenueInt = 0;
					setPerformanceDial(Math.floor(revenueInt / 50));
				})
				.catch(function(){});
			}

			setPerformanceDial(<?php echo (int) $initial_purchased_credits; ?>);
			refreshPerformanceDial();

			// Branding modal (delegated save/pick/clear) — FIXED for wp.media
			var brandingModal = $('#icon-branding-modal');
			var brandingClientIdEl = $('#icon-branding-client-id');
			var brandingClientNameEl = $('#icon-branding-client-name');
			var brandingLogoIdEl = $('#icon-branding-logo-id');
			var brandingSlugEl = $('#icon-branding-slug');
			var brandingPrimaryEl = $('#icon-branding-primary');
			var brandingSecondaryEl = $('#icon-branding-secondary');
			var brandingLogoPreview = $('#icon-branding-logo-preview');
			var brandingStatus = $('#icon-branding-status');

			var mediaFrame = null;

			function openBrandingModal(){
				if (!brandingModal) return;
				brandingModal.classList.add('is-open');
				brandingModal.setAttribute('aria-hidden','false');
			}
			function closeBrandingModal(){
				if (!brandingModal) return;
				brandingModal.classList.remove('is-open');
				brandingModal.setAttribute('aria-hidden','true');
			}
			function setBrandingStatus(msg){
				if (!brandingStatus) return;
				brandingStatus.textContent = msg || '';
			}
			function setLogoPreview(url){
				if (!brandingLogoPreview) return;
				if (url) { brandingLogoPreview.src = url; brandingLogoPreview.style.display = 'block'; }
				else { brandingLogoPreview.src = ''; brandingLogoPreview.style.display = 'none'; }
			}

			function bindBrandingButtons(){
				$all('[data-branding-open]').forEach(function(btn){
					if (btn.__iconBound) return;
					btn.__iconBound = true;

					btn.addEventListener('click', function(e){
						e.preventDefault();
						e.stopPropagation();

						var clientId = parseInt(btn.getAttribute('data-branding-open') || '0', 10);
						if (!clientId) return;

						var card = document.querySelector('.icon-client-card[data-client-id="'+clientId+'"]');
						var clientName = card ? (card.getAttribute('data-client-name') || '') : '';

						if (brandingClientIdEl) brandingClientIdEl.value = String(clientId);
						if (brandingClientNameEl) brandingClientNameEl.textContent = clientName ? clientName : ('Client #' + clientId);

						if (brandingLogoIdEl) brandingLogoIdEl.value = '0';
						if (brandingSlugEl) brandingSlugEl.value = '';
						if (brandingPrimaryEl) brandingPrimaryEl.value = '';
						if (brandingSecondaryEl) brandingSecondaryEl.value = '';
						setLogoPreview('');
						setBrandingStatus('Loading…');

						openBrandingModal();

						post({ action:'icon_psy_get_client_branding', nonce:nonce, client_id:clientId })
						.then(function(resp){
							if (!resp || !resp.success) {
								setBrandingStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to load branding.');
								return;
							}
							var b = resp.data && resp.data.branding ? resp.data.branding : {};
							if (brandingLogoIdEl) brandingLogoIdEl.value = String(b.logo_id || 0);
							if (brandingSlugEl) brandingSlugEl.value = String(b.portal_slug || '');
							if (brandingPrimaryEl) brandingPrimaryEl.value = String(b.primary || '#15a06d');
							if (brandingSecondaryEl) brandingSecondaryEl.value = String(b.secondary || '#14a4cf');
							setLogoPreview(b.logo_url || '');
							setBrandingStatus('');
						})
						.catch(function(){ setBrandingStatus('Network error.'); });
					});
				});
			}
			bindBrandingButtons();

			$all('[data-branding-close]').forEach(function(el){
				el.addEventListener('click', function(e){ e.preventDefault(); closeBrandingModal(); });
			});
			document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeBrandingModal(); });

			// Global delegated actions: branding save/pick/clear + delete client
			document.addEventListener('click', function(e){

				var delBtn = e.target && e.target.closest ? e.target.closest('[data-delete-client]') : null;
				if (delBtn) {
					e.preventDefault(); e.stopPropagation();

					var clientId = parseInt(delBtn.getAttribute('data-delete-client') || '0', 10);
					if (!clientId) return;

					var purge = confirm(
						'Delete ALL client data too (projects, participants, raters, credit log)?\n\n' +
						'OK = Yes (recommended)\nCancel = No (user only)'
					);

					var hard = prompt('Type DELETE to confirm deleting this client:');
					if (hard !== 'DELETE') return;

					post({ action:'icon_psy_delete_client', nonce:nonce, client_id:clientId, purge: purge ? 1 : 0 })
					.then(function(resp){
						if (!resp || !resp.success) {
							alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Delete failed.');
							return;
						}

						var card = document.querySelector('.icon-client-card[data-client-id="'+clientId+'"]');
						if (card && card.parentNode) card.parentNode.removeChild(card);

						['icon-jump-client','icon-qc-client'].forEach(function(id){
							var sel = document.getElementById(id);
							if (!sel) return;
							Array.prototype.slice.call(sel.options).forEach(function(opt){
								if (String(opt.value) === String(clientId)) sel.removeChild(opt);
							});
						});

						var kpiEl = document.getElementById('icon-kpi-clients');
						if (kpiEl) {
							var n = parseInt((kpiEl.textContent || '0').replace(/[^\d]/g,''), 10);
							if (!isNaN(n) && n > 0) kpiEl.textContent = String(n - 1);
						}

						if (String(activeClientId || '') === String(clientId)) {
							hideEmbed();
						}

						alert('Client deleted.');
					})
					.catch(function(){ alert('Network error deleting client.'); });

					return;
				}

				var saveBtn = e.target && e.target.closest ? e.target.closest('#icon-branding-save') : null;
				if (saveBtn) {
					e.preventDefault(); e.stopPropagation();

					var clientId = brandingClientIdEl ? parseInt(brandingClientIdEl.value || '0', 10) : 0;
					if (!clientId) { setBrandingStatus('No client selected.'); return; }

					setBrandingStatus('Saving…');

					post({
						action: 'icon_psy_save_client_branding',
						nonce: nonce,
						client_id: clientId,
						logo_id: brandingLogoIdEl ? parseInt(brandingLogoIdEl.value || '0', 10) : 0,
						portal_slug: brandingSlugEl ? (brandingSlugEl.value || '') : '',
						primary: brandingPrimaryEl ? (brandingPrimaryEl.value || '') : '',
						secondary: brandingSecondaryEl ? (brandingSecondaryEl.value || '') : ''
					}).then(function(resp){
						if (!resp || !resp.success) {
							setBrandingStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to save branding.');
							return;
						}
						var b = resp.data && resp.data.branding ? resp.data.branding : {};
						setLogoPreview(b.logo_url || '');
						setBrandingStatus(resp.data && resp.data.message ? resp.data.message : 'Saved.');
						setTimeout(function(){ setBrandingStatus(''); }, 1500);
					}).catch(function(){ setBrandingStatus('Network error.'); });

					return;
				}

				var pickBtn = e.target && e.target.closest ? e.target.closest('#icon-branding-pick-logo') : null;
				if (pickBtn) {
					e.preventDefault(); e.stopPropagation();

					if (!window.wp || !wp.media) {
						setBrandingStatus('Media Library not available. Check wp_enqueue_media().');
						return;
					}

					var reopenAfterMedia = true;
					closeBrandingModal();

					if (!mediaFrame) {
						mediaFrame = wp.media({
							title: 'Select client logo',
							library: { type: 'image' },
							button: { text: 'Use this logo' },
							multiple: false
						});

						mediaFrame.on('select', function(){
							var attachment = mediaFrame.state().get('selection').first().toJSON();
							if (!attachment || !attachment.id) return;

							if (brandingLogoIdEl) brandingLogoIdEl.value = String(attachment.id);

							var url = (attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url)
								? attachment.sizes.medium.url
								: (attachment.url || '');

							setLogoPreview(url);
							setBrandingStatus('');

							if (reopenAfterMedia) openBrandingModal();
						});

						mediaFrame.on('close', function(){
							if (reopenAfterMedia) openBrandingModal();
						});
					}

					mediaFrame.open();
					return;
				}

				var clearBtn = e.target && e.target.closest ? e.target.closest('#icon-branding-clear-logo') : null;
				if (clearBtn) {
					e.preventDefault(); e.stopPropagation();
					if (brandingLogoIdEl) brandingLogoIdEl.value = '0';
					setLogoPreview('');
					return;
				}

			}, true); // capture

			// Quick credits
			var qcClient = $('#icon-qc-client');
			var qcMode   = $('#icon-qc-mode');
			var qcQty    = $('#icon-qc-qty');
			var qcApply  = $('#icon-qc-apply');
			var qcStatus = $('#icon-qc-status');

			function setQCStatus(msg){ if (qcStatus) qcStatus.textContent = msg || ''; }

			function issueCreditsQuick(clientId, mode, qty){
				if (!clientId) { setQCStatus('Select a client.'); return; }
				if (mode === 'add' && qty <= 0) { setQCStatus('Enter qty to add.'); return; }
				if (mode === 'set' && qty < 0) qty = 0;
				if (mode === 'reset' || mode === 'unlimited') qty = 0;

				setQCStatus('Issuing…');
				post({ action:'icon_psy_set_client_credits', nonce:nonce, client_id:clientId, mode:mode, qty:qty, note:'' })
				.then(function(resp){
					if (!resp || !resp.success) {
						setQCStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Failed.');
						return;
					}
					setQCStatus('Done.');
					setTimeout(function(){ setQCStatus(''); }, 2000);
					refreshPerformanceDial();
				})
				.catch(function(){ setQCStatus('Network error.'); });
			}

			$all('[data-qc-add]').forEach(function(btn){
				btn.addEventListener('click', function(){
					var clientId = qcClient ? qcClient.value : '';
					var amt = parseInt(btn.getAttribute('data-qc-add') || '0', 10);
					if (!amt) return;
					if (qcMode) qcMode.value = 'add';
					issueCreditsQuick(clientId, 'add', amt);
				});
			});

			if (qcApply) qcApply.addEventListener('click', function(){
				var clientId = qcClient ? qcClient.value : '';
				var mode = qcMode ? qcMode.value : 'add';
				var qty  = qcQty ? parseInt(qcQty.value || '0', 10) : 0;
				issueCreditsQuick(clientId, mode, qty);
			});

			// Add client (wired) — ONLY name/email
			var acName   = $('#icon-addclient-name');
			var acEmail  = $('#icon-addclient-email');
			var acBtn    = $('#icon-addclient-btn');
			var acStatus = $('#icon-addclient-status');

			function setACStatus(msg){ if (acStatus) acStatus.textContent = msg || ''; }

			function addClientOption(selectEl, client){
				if (!selectEl || !client || !client.ID) return;
				var opt = document.createElement('option');
				opt.value = String(client.ID);
				opt.textContent = client.display_name;
				selectEl.appendChild(opt);
			}

			function createClientCard(client){
				var grid = document.getElementById('icon-client-grid');
				if (!grid || !client || !client.ID) return;

				var cid = String(client.ID);

				var card = document.createElement('div');
				card.className = 'icon-client-card';
				card.setAttribute('role','button');
				card.setAttribute('tabindex','0');

				card.setAttribute('data-client-id', cid);
				card.setAttribute('data-client-name', client.display_name || 'Client');
				card.setAttribute('data-client-email', client.user_email || '');
				card.setAttribute('data-client-credits', client.credits_lbl || '0');
				card.setAttribute('data-client-credits-raw', String(client.credits_raw || 0));
				card.setAttribute('data-projects', '0');
				card.setAttribute('data-raters-total', '0');
				card.setAttribute('data-raters-completed', '0');
				card.setAttribute('data-completion-pct', '0');
				card.setAttribute('data-revenue-est', '0');

				card.innerHTML =
					'<div class="icon-client-top">' +
						'<div style="min-width:0;">' +
							'<div class="icon-client-name"></div>' +
							'<div class="icon-client-email"></div>' +
							'<div style="font-size:12px;color:#64748b;font-weight:900;margin-top:8px;">' +
								'Projects: <b style="color:#0b2f2a;">0</b>' +
								' &nbsp;•&nbsp; Completion: <b style="color:#0b2f2a;">0%</b>' +
								' &nbsp;•&nbsp; Credits: <b style="color:#0b2f2a;">0</b>' +
							'</div>' +
							'<div class="icon-client-actions">' +
								'<button class="icon-client-action" type="button" onclick="event.stopPropagation();" data-branding-open="'+cid+'">' +
									'<span class="i">🎨</span>Branding' +
								'</button>' +
								'<button class="icon-client-action" type="button" onclick="event.stopPropagation();" data-delete-client="'+cid+'">' +
									'<span class="i">🗑</span>Delete' +
								'</button>' +
							'</div>' +
						'</div>' +
						'<div class="icon-toggle" aria-hidden="true"></div>' +
					'</div>';

				card.querySelector('.icon-client-name').textContent = client.display_name || 'Client';
				card.querySelector('.icon-client-email').textContent = client.user_email || '';

				function go(){
					openClient(
						card.getAttribute('data-client-id'),
						card.getAttribute('data-client-name'),
						card.getAttribute('data-client-credits'),
						card.getAttribute('data-client-credits-raw')
					);
				}
				card.addEventListener('click', go);
				card.addEventListener('keydown', function(e){
					if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
				});

				grid.insertBefore(card, grid.firstChild);
				bindBrandingButtons();
			}

			function createClient(){
				var name = acName ? (acName.value || '').trim() : '';
				var email = acEmail ? (acEmail.value || '').trim() : '';
				if (!name) { setACStatus('Enter name.'); return; }
				if (!email) { setACStatus('Enter email.'); return; }

				setACStatus('Creating…');

				post({
					action:'icon_psy_create_client',
					nonce:nonce,
					name:name,
					email:email
				})
				.then(function(resp){
					if (!resp || !resp.success) {
						setACStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Failed.');
						return;
					}
					var client = resp.data && resp.data.client ? resp.data.client : null;
					if (!client || !client.ID) { setACStatus('Created, but no data returned.'); return; }

					addClientOption(jumpClient, client);
					addClientOption(qcClient, client);

					createClientCard(client);

					var kpiEl = document.getElementById('icon-kpi-clients');
					if (kpiEl) {
						var n = parseInt((kpiEl.textContent || '0').replace(/[^\d]/g,''), 10);
						if (!isNaN(n)) kpiEl.textContent = String(n + 1);
					}

					if (acName) acName.value = '';
					if (acEmail) acEmail.value = '';

					setACStatus('Created.');
					setTimeout(function(){ setACStatus(''); }, 2500);
				})
				.catch(function(){ setACStatus('Network error.'); });
			}

			if (acBtn) acBtn.addEventListener('click', function(){ createClient(); });
			if (acEmail) acEmail.addEventListener('keydown', function(e){
				if (e.key === 'Enter') { e.preventDefault(); createClient(); }
			});

		})();
		</script>
		<?php
		return ob_get_clean();
	}
}

add_action( 'init', function () {
	add_shortcode( 'icon_psy_management_portal', 'icon_psy_management_portal_shortcode' );
}, 20 );
