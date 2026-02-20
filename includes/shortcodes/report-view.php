<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ICON Catalyst — Report Viewer (public token entry point)
 *
 * Shortcode: [icon_psy_report_view]
 * Page slug: report-view
 *
 * URL: /report-view/?token=XXXX
 *
 * This renders the correct report WITHOUT requiring login.
 * It detects report type from the participant’s project:
 * - Teams -> /team-report/?participant_id=
 * - Profiler -> /icon-profiler-report/?participant_id=
 * - Traits -> /traits-report/?participant_id=
 * - Default -> /feedback-report/?participant_id=
 *
 * IMPORTANT:
 * This file expects the tokens table created by includes/email-report.php
 */

if ( ! function_exists( 'icon_psy_table_exists' ) ) {
	function icon_psy_table_exists( $table_name ) {
		global $wpdb;
		$t = (string) $table_name;
		if ( $t === '' ) return false;
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $t ) );
		return ( $found === $t );
	}
}

if ( ! function_exists( 'icon_psy_report_tokens_table' ) ) {
	function icon_psy_report_tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'icon_psy_report_tokens';
	}
}

if ( ! function_exists( 'icon_psy_project_is_profiler_project' ) ) {
	function icon_psy_project_is_profiler_project( $project_row, $frameworks_table ) {
		if ( ! $project_row ) return false;
		$fid = isset( $project_row->framework_id ) ? (int) $project_row->framework_id : 0;
		if ( $fid <= 0 ) return false;

		global $wpdb;
		$name = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT name FROM {$frameworks_table} WHERE id=%d", $fid )
		);

		return ( strtolower( trim( $name ) ) === 'icon profiler' );
	}
}

if ( ! function_exists( 'icon_psy_extract_icon_cfg_from_reference' ) ) {
	function icon_psy_extract_icon_cfg_from_reference( $reference ) {
		$reference = (string) $reference;
		$maybe = json_decode( $reference, true );
		return is_array( $maybe ) ? $maybe : array();
	}
}

if ( ! function_exists( 'icon_psy_project_is_teams_project' ) ) {
	function icon_psy_project_is_teams_project( $project_row, $has_reference = false ) {
		if ( ! $project_row ) return false;

		$pkg = '';
		if ( isset( $project_row->icon_pkg ) && (string) $project_row->icon_pkg !== '' ) {
			$pkg = (string) $project_row->icon_pkg;
		} elseif ( $has_reference && isset( $project_row->reference ) && (string) $project_row->reference !== '' ) {
			$cfg = icon_psy_extract_icon_cfg_from_reference( (string) $project_row->reference );
			if ( is_array( $cfg ) && isset( $cfg['icon_pkg'] ) ) $pkg = (string) $cfg['icon_pkg'];
		}

		$pkg = strtolower( trim( $pkg ) );

		return in_array( $pkg, array(
			'high_performing_teams','teams_cohorts','teams','team','hpt'
		), true );
	}
}

if ( ! function_exists( 'icon_psy_project_is_traits_project' ) ) {
	function icon_psy_project_is_traits_project( $project_row, $has_reference = false ) {
		if ( ! $project_row ) return false;

		$pkg = '';
		if ( isset( $project_row->icon_pkg ) && (string) $project_row->icon_pkg !== '' ) {
			$pkg = (string) $project_row->icon_pkg;
		} elseif ( $has_reference && isset( $project_row->reference ) && (string) $project_row->reference !== '' ) {
			$cfg = icon_psy_extract_icon_cfg_from_reference( (string) $project_row->reference );
			if ( is_array( $cfg ) && isset( $cfg['icon_pkg'] ) ) $pkg = (string) $cfg['icon_pkg'];
		}

		$pkg = strtolower( trim( (string) $pkg ) );
		return ( $pkg === 'aaet_internal' );
	}
}

if ( ! function_exists( 'icon_psy_report_view_shortcode' ) ) {
	function icon_psy_report_view_shortcode() {
		global $wpdb;

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		if ( $token === '' ) {
			return '<div style="padding:18px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;">Missing token.</div>';
		}

		$table = icon_psy_report_tokens_table();
		if ( ! icon_psy_table_exists( $table ) ) {
			return '<div style="padding:18px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;">Report viewer is not configured.</div>';
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token=%s LIMIT 1", $token )
		);

		if ( ! $row ) {
			return '<div style="padding:18px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;">Invalid or expired link.</div>';
		}

		// expiry check
		if ( ! empty( $row->expires_at ) ) {
			$exp = strtotime( (string) $row->expires_at . ' UTC' );
			if ( $exp && time() > $exp ) {
				return '<div style="padding:18px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;">This link has expired.</div>';
			}
		}

		$participant_id = (int) $row->participant_id;
		if ( $participant_id <= 0 ) {
			return '<div style="padding:18px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;">Invalid participant.</div>';
		}

		$projects_table     = $wpdb->prefix . 'icon_psy_projects';
		$participants_table = $wpdb->prefix . 'icon_psy_participants';
		$frameworks_table   = $wpdb->prefix . 'icon_psy_frameworks';

		if ( ! icon_psy_table_exists( $projects_table ) || ! icon_psy_table_exists( $participants_table ) ) {
			return '<div style="padding:18px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;">Report data tables missing.</div>';
		}

		$p = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$participants_table} WHERE id=%d", $participant_id ) );
		if ( ! $p ) {
			return '<div style="padding:18px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;">Participant not found.</div>';
		}

		$project = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$projects_table} WHERE id=%d", (int) $p->project_id ) );

		$has_reference = ( $project && isset( $project->reference ) );

		$is_team     = $project ? icon_psy_project_is_teams_project( $project, $has_reference ) : false;
		$is_traits   = $project ? icon_psy_project_is_traits_project( $project, $has_reference ) : false;
		$is_profiler = ( $project && icon_psy_table_exists( $frameworks_table ) )
			? icon_psy_project_is_profiler_project( $project, $frameworks_table )
			: false;

		// Decide destination page (your existing slugs)
		if ( $is_team ) {
			$dest = home_url( '/team-report/' );
		} elseif ( $is_profiler ) {
			$dest = home_url( '/icon-profiler-report/' );
		} elseif ( $is_traits ) {
			$dest = home_url( '/traits-report/' );
		} else {
			$dest = home_url( '/feedback-report/' );
		}

		// Pass participant_id as normal, plus token for traceability (not required by report pages)
		$url = add_query_arg(
			array(
				'participant_id' => $participant_id,
				'view_token'     => $token,
			),
			$dest
		);

		// Hard redirect (fast + avoids rendering heavy reports inside this page)
		wp_safe_redirect( $url );
		exit;
	}
}

add_shortcode( 'icon_psy_report_view', 'icon_psy_report_view_shortcode' );
