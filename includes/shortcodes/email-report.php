<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Email report handler for Client Portal.
 * POSTs to admin-post.php?action=icon_psy_send_report_email
 *
 * Supports:
 * - client / participant / all raters
 * - SELECTED individual raters via rater_ids[]
 * - PUBLIC share links via ?share=TOKEN (no login required)
 * - PRG redirect back to portal with success/error message
 */

add_action('admin_post_icon_psy_send_report_email', 'icon_psy_send_report_email_handler');

/* -------------------------------------------------------------
 * Share table + token helpers (MUST be OUTSIDE the handler)
 * ------------------------------------------------------------ */

if ( ! function_exists('icon_psy_ensure_report_share_table') ) {
	function icon_psy_ensure_report_share_table() {
		global $wpdb;
		$table   = $wpdb->prefix . 'icon_psy_report_shares';
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			token VARCHAR(64) NOT NULL,
			participant_id BIGINT(20) UNSIGNED NOT NULL,
			report_type VARCHAR(24) NOT NULL,
			recipient_email VARCHAR(190) NULL,
			expires_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY participant_id (participant_id),
			KEY report_type (report_type)
		) {$charset};";

		dbDelta($sql);
	}
}

if ( ! function_exists('icon_psy_create_report_share_token') ) {
	function icon_psy_create_report_share_token( $participant_id, $report_type, $recipient_email = '', $days_valid = 14 ) {
		global $wpdb;

		icon_psy_ensure_report_share_table();

		$table = $wpdb->prefix . 'icon_psy_report_shares';

		// token stored as VARCHAR(64) - keep it <= 64 chars
		$token = function_exists('wp_generate_password')
			? wp_generate_password( 48, false, false )
			: substr( bin2hex( random_bytes(24) ), 0, 48 );

		$expires = gmdate('Y-m-d H:i:s', time() + ( max(1, (int)$days_valid) * DAY_IN_SECONDS ) );

		$wpdb->insert(
			$table,
			array(
				'token'           => $token,
				'participant_id'   => (int) $participant_id,
				'report_type'      => (string) $report_type,
				'recipient_email'  => $recipient_email ? (string) $recipient_email : null,
				'expires_at'       => $expires,
				'created_at'       => current_time('mysql'),
			),
			array('%s','%d','%s','%s','%s','%s')
		);

		return $token;
	}
}

/**
 * Validate a share token (used by report pages).
 * You will call this from feedback-report/team-report/traits-report/profiler-report.
 */
if ( ! function_exists('icon_psy_validate_report_share_token') ) {
	function icon_psy_validate_report_share_token( $token, $participant_id, $report_type ) {
		global $wpdb;

		$token = trim((string)$token);
		$participant_id = (int) $participant_id;
		$report_type = sanitize_key((string)$report_type);

		if ( $token === '' || $participant_id <= 0 || $report_type === '' ) return false;

		$table = $wpdb->prefix . 'icon_psy_report_shares';
		icon_psy_ensure_report_share_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE token=%s AND participant_id=%d AND report_type=%s LIMIT 1",
				$token, $participant_id, $report_type
			)
		);

		if ( ! $row ) return false;

		if ( ! empty($row->expires_at) ) {
			$exp = strtotime((string)$row->expires_at . ' UTC');
			if ( $exp && time() > $exp ) return false;
		}

		return true;
	}
}

/* -------------------------------------------------------------
 * Main handler
 * ------------------------------------------------------------ */

function icon_psy_send_report_email_handler() {

	if ( ! is_user_logged_in() ) {
		wp_die('Not logged in.');
	}

	// Security
	if ( ! isset($_POST['_wpnonce']) || ! wp_verify_nonce( sanitize_text_field( wp_unslash($_POST['_wpnonce']) ), 'icon_psy_send_report_email' ) ) {
		wp_die('Security check failed.');
	}

	// Back URL (PRG)
	$back = wp_get_referer();
	if ( ! $back ) { $back = home_url('/catalyst-portal/'); }

	// Inputs
	$project_id     = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
	$participant_id = isset($_POST['participant_id']) ? (int) $_POST['participant_id'] : 0;
	$report_type    = isset($_POST['report_type']) ? sanitize_key( wp_unslash($_POST['report_type']) ) : 'feedback';

	// Old buttons (compat)
	$to = isset($_POST['to']) ? sanitize_key( wp_unslash($_POST['to']) ) : '';

	// New UI (recommended): checkboxes + selected raters
	$send_to   = isset($_POST['send_to']) ? (array) wp_unslash($_POST['send_to']) : array(); // 'client','participant'
	$rater_ids = isset($_POST['rater_ids']) ? array_map('intval', (array) wp_unslash($_POST['rater_ids'])) : array();

	$send_to = array_map('sanitize_key', (array)$send_to);
	$rater_ids = array_values(array_filter(array_map('intval', (array)$rater_ids)));

	// Validate
	if ( $participant_id <= 0 ) {
		wp_safe_redirect( add_query_arg(array('icon_psy_err' => rawurlencode('Missing participant_id.')), $back) );
		exit;
	}

	global $wpdb;

	$projects_table     = $wpdb->prefix . 'icon_psy_projects';
	$participants_table = $wpdb->prefix . 'icon_psy_participants';
	$raters_table       = $wpdb->prefix . 'icon_psy_raters';

	// Load participant + project
	$p = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$participants_table} WHERE id=%d", $participant_id) );
	if ( ! $p ) {
		wp_safe_redirect( add_query_arg(array('icon_psy_err' => rawurlencode('Participant not found.')), $back) );
		exit;
	}

	$pr = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$projects_table} WHERE id=%d", (int) $p->project_id) );
	if ( ! $pr ) {
		wp_safe_redirect( add_query_arg(array('icon_psy_err' => rawurlencode('Project not found.')), $back) );
		exit;
	}

	// Ownership check: must be the client (or admin)
	$effective_user_id = function_exists('icon_psy_get_effective_client_user_id')
		? (int) icon_psy_get_effective_client_user_id()
		: (int) get_current_user_id();

	if ( (int) $pr->client_user_id !== (int) $effective_user_id && ! current_user_can('manage_options') ) {
		wp_safe_redirect( add_query_arg(array('icon_psy_err' => rawurlencode('You do not have permission to email this report.')), $back) );
		exit;
	}

	// Build base report URL
	$base_report_url = icon_psy_build_report_url_for_email( $report_type, (int) $p->id, (int) $pr->id );

	// Recipients:
	// - Prefer new UI fields (send_to[] + rater_ids[])
	// - Fall back to old "to" field if provided
	$recipients = array();

	if ( ! empty($send_to) || ! empty($rater_ids) ) {
		$recipients = icon_psy_get_email_recipients_selected( $send_to, $rater_ids, $pr, $p, $raters_table );
	} elseif ( $to ) {
		$recipients = icon_psy_get_email_recipients_legacy( $to, $pr, $p, $raters_table );
	} else {
		// Default if nothing provided
		$recipients = icon_psy_get_email_recipients_legacy( 'client', $pr, $p, $raters_table );
	}

	if ( empty($recipients) ) {
		wp_safe_redirect( add_query_arg(array('icon_psy_err' => rawurlencode('No valid email recipients found.')), $back) );
		exit;
	}

	$headers = array('Content-Type: text/plain; charset=UTF-8');
	$attachments = array();

	$sent_count = 0;
	$fail_count = 0;

	// IMPORTANT: token + link must be per-recipient
	foreach ( $recipients as $email ) {

		// Create token + public share URL
		$token = icon_psy_create_report_share_token( (int) $p->id, (string) $report_type, (string) $email, 14 );

		$share_url = add_query_arg(
			array( 'share' => $token ),
			$base_report_url
		);

		$email_data = icon_psy_build_email_content( array(
			'report_type' => $report_type,
			'project'     => $pr,
			'participant' => $p,
			'report_url'  => $share_url,
			'recipient'   => $email,
		) );

		$subject = (string) ($email_data['subject'] ?? 'Your report');
		$body    = (string) ($email_data['body'] ?? '');

		$ok = wp_mail( $email, $subject, $body, $headers, $attachments );
		if ( $ok ) $sent_count++;
		else $fail_count++;
	}

	if ( $sent_count > 0 ) {
		$msg = "Email sent to {$sent_count} recipient(s)." . ( $fail_count ? " Failed: {$fail_count}." : "" );
		wp_safe_redirect( add_query_arg(array('icon_psy_msg' => rawurlencode($msg)), $back) );
		exit;
	}

	wp_safe_redirect( add_query_arg(array('icon_psy_err' => rawurlencode('Email failed (wp_mail returned false).')), $back) );
	exit;
}

/* -------------------------------------------------------------
 * URL builder
 * ------------------------------------------------------------ */

function icon_psy_build_report_url_for_email( $report_type, $participant_id, $project_id ) {
	$report_type = sanitize_key($report_type);

	$base = home_url('/feedback-report/');
	if ( $report_type === 'team' )     $base = home_url('/team-report/');
	if ( $report_type === 'traits' )   $base = home_url('/traits-report/');
	if ( $report_type === 'profiler' ) $base = home_url('/icon-profiler-report/');

	return add_query_arg(array(
		'participant_id' => (int) $participant_id,
	), $base);
}

/* -------------------------------------------------------------
 * Recipient helpers
 * ------------------------------------------------------------ */

// Legacy: to=client|participant|raters|all
function icon_psy_get_email_recipients_legacy( $to, $project_row, $participant_row, $raters_table ) {
	$to = sanitize_key($to);
	$list = array();

	if ( $to === 'client' || $to === 'all' ) {
		$client_user = get_user_by('id', (int) $project_row->client_user_id);
		if ( $client_user && is_email($client_user->user_email) ) {
			$list[ strtolower($client_user->user_email) ] = $client_user->user_email;
		}
	}

	if ( $to === 'participant' || $to === 'all' ) {
		$pe = isset($participant_row->email) ? trim((string)$participant_row->email) : '';
		if ( is_email($pe) ) $list[ strtolower($pe) ] = $pe;
	}

	if ( $to === 'raters' || $to === 'all' ) {
		global $wpdb;
		$raters = $wpdb->get_col( $wpdb->prepare("SELECT email FROM {$raters_table} WHERE participant_id=%d", (int) $participant_row->id) );
		foreach ( (array)$raters as $re ) {
			$re = trim((string)$re);
			if ( is_email($re) ) $list[ strtolower($re) ] = $re;
		}
	}

	return array_values($list);
}

// New: send_to[] + rater_ids[] (selected individual raters)
function icon_psy_get_email_recipients_selected( $send_to, $rater_ids, $project_row, $participant_row, $raters_table ) {
	global $wpdb;

	$list = array();
	$send_to = array_map('sanitize_key', (array)$send_to);
	$rater_ids = array_values(array_filter(array_map('intval', (array)$rater_ids)));

	if ( in_array('client', $send_to, true) ) {
		$client_user = get_user_by('id', (int) $project_row->client_user_id);
		if ( $client_user && is_email($client_user->user_email) ) {
			$list[ strtolower($client_user->user_email) ] = $client_user->user_email;
		}
	}

	if ( in_array('participant', $send_to, true) ) {
		$pe = isset($participant_row->email) ? trim((string)$participant_row->email) : '';
		if ( is_email($pe) ) $list[ strtolower($pe) ] = $pe;
	}

	if ( ! empty($rater_ids) ) {
		$placeholders = implode(',', array_fill(0, count($rater_ids), '%d'));
		$sql = "SELECT email FROM {$raters_table} WHERE participant_id=%d AND id IN ({$placeholders})";
		$args = array_merge( array( (int)$participant_row->id ), $rater_ids );
		$emails = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

		foreach ( (array)$emails as $re ) {
			$re = trim((string)$re);
			if ( is_email($re) ) $list[ strtolower($re) ] = $re;
		}
	}

	return array_values($list);
}

/* -------------------------------------------------------------
 * Email template
 * ------------------------------------------------------------ */

function icon_psy_build_email_content( $ctx ) {

	$report_type = sanitize_key( (string) ($ctx['report_type'] ?? 'feedback') );
	$pr          = $ctx['project'] ?? null;
	$p           = $ctx['participant'] ?? null;
	$url         = (string) ($ctx['report_url'] ?? '');

	$project_name     = ( $pr && isset($pr->name) ) ? (string)$pr->name : 'Project';
	$participant_name = ( $p && isset($p->name) ) ? (string)$p->name : 'Participant';

	$report_label = 'Feedback report';
	if ( $report_type === 'team' )     $report_label = 'Team report';
	if ( $report_type === 'traits' )   $report_label = 'Traits report';
	if ( $report_type === 'profiler' ) $report_label = 'Profiler report';

	$subject = "{$report_label}: {$participant_name} ({$project_name})";

	$lines = array();
	$lines[] = "Hello,";
	$lines[] = "";
	$lines[] = "Your {$report_label} is ready.";
	$lines[] = "Project: {$project_name}";
	$lines[] = "Participant: {$participant_name}";
	$lines[] = "";
	$lines[] = "Open the report here:";
	$lines[] = $url ? $url : "(Report link unavailable)";
	$lines[] = "";
	$lines[] = "Regards,";
	$lines[] = "Icon Talent";

	return array(
		'subject' => $subject,
		'body'    => implode("\n", $lines),
	);
}
