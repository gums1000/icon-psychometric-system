<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }



/**

 * ICON Traits Report (Screen HTML)

 *

 * Shortcode: [icon_psy_traits_report]

 * Usage (screen): /traits-report/?participant_id=XX


 *

 * This file is now SCREEN-ONLY:

 * - ✅ Keeps the full on-screen report (HTML + Chart.js radar)




 *

 * UPDATE (this request):

 * - Adds the SAME branding resolution pattern as your survey pages:

 *   - Optional token lookup (if participant_id not provided) using wp_icon_psy_raters.token

 *   - Centralised branding resolution:

 *       icon_psy_get_branding_for_participant(participant_id) if available

 *       with safe fallbacks + strict hex validation

 *   - Optional debug output (?icon_debug=1) showing how branding was resolved

 *

 * PATCH (this update):

 * - Fixes radar labels rendering:

 *   - Decodes HTML entities so "&amp;" shows as "&" on radar labels

 *   - Wraps long radar labels to 2 lines + adds padding so labels don’t get clipped

 *   - Uses a safer radar count (10) to reduce overcrowding

 *

 * PATCH (this request):

 * - Adds the SAME “shared email token link” pattern you used on the Profiler report:

 *   - Generates a share_token per participant (stored in WP options; no new files/tables)

 *   - Renders “Copy link” + “Email link” UI

 *   - Allows resolve participant_id from ?share_token=XXXX

 *   - Allows regenerate/revoke (admin-only) with nonce protection

 */



if ( ! function_exists( 'icon_psy_traits_report' ) ) {



	function icon_psy_traits_report( $atts ) {

		global $wpdb;



		// ------------------------------------------------------------

		// Inputs

		// ------------------------------------------------------------

		$atts = shortcode_atts(

			array(

				'participant_id' => 0,

			),

			$atts,

			'icon_psy_traits_report'

		);



		// Optional debug

		$icon_debug    = isset( $_GET['icon_debug'] ) && (string) $_GET['icon_debug'] !== '' ? sanitize_text_field( wp_unslash( $_GET['icon_debug'] ) ) : '';

		$icon_debug_on = in_array( $icon_debug, array( '1', 'true', 'yes', 'on' ), true );



		// Shared token (email share link)

		$share_token = isset( $_GET['share_token'] ) ? sanitize_text_field( wp_unslash( $_GET['share_token'] ) ) : '';

		$share_token = preg_replace( '/[^a-zA-Z0-9\-_]/', '', (string) $share_token );



		$participant_id     = 0;

		$participant_source = 'none';



		if ( isset( $_GET['participant_id'] ) && $_GET['participant_id'] !== '' ) {

			$participant_id     = (int) $_GET['participant_id'];

			$participant_source = 'get:participant_id';

		} elseif ( ! empty( $atts['participant_id'] ) ) {

			$participant_id     = (int) $atts['participant_id'];

			$participant_source = 'shortcode:participant_id';

		}



		// ------------------------------------------------------------

		// Optional token -> participant_id (matches your survey approach)

		// ------------------------------------------------------------

		$token    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		$rater_id = isset( $_GET['rater_id'] ) ? absint( $_GET['rater_id'] ) : 0;



		$resolved_rater      = null;

		$raters_table        = $wpdb->prefix . 'icon_psy_raters';

		$raters_table_exists = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $raters_table ) ) === $raters_table );



		// ------------------------------------------------------------

		// Share-token storage helpers (no new files/tables)

		// ------------------------------------------------------------

		if ( ! function_exists( 'icon_psy_traits_share_token_key_for_participant' ) ) {

			function icon_psy_traits_share_token_key_for_participant( $participant_id ) {

				return 'icon_psy_traits_share_for_participant_' . absint( $participant_id );

			}

		}

		if ( ! function_exists( 'icon_psy_traits_share_token_key_for_token' ) ) {

			function icon_psy_traits_share_token_key_for_token( $token ) {

				$token = preg_replace( '/[^a-zA-Z0-9\-_]/', '', (string) $token );

				return 'icon_psy_traits_share_token_' . $token;

			}

		}

		if ( ! function_exists( 'icon_psy_traits_share_generate_token' ) ) {

			function icon_psy_traits_share_generate_token() {

				// URL-safe token

				if ( function_exists( 'wp_generate_password' ) ) {

					$t = wp_generate_password( 28, false, false );

					return preg_replace( '/[^a-zA-Z0-9\-_]/', '', (string) $t );

				}

				try {

					return bin2hex( random_bytes( 16 ) );

				} catch ( Exception $e ) {

					return md5( uniqid( (string) wp_rand(), true ) );

				}

			}

		}

		if ( ! function_exists( 'icon_psy_traits_share_get_or_create_token' ) ) {

			function icon_psy_traits_share_get_or_create_token( $participant_id ) {

				$participant_id = absint( $participant_id );

				if ( $participant_id <= 0 ) return '';



				$k_part = icon_psy_traits_share_token_key_for_participant( $participant_id );

				$existing = get_option( $k_part, '' );

				$existing = is_string( $existing ) ? trim( $existing ) : '';



				if ( $existing !== '' ) {

					$k_tok = icon_psy_traits_share_token_key_for_token( $existing );

					$data  = get_option( $k_tok, array() );

					if ( is_array( $data ) && ! empty( $data['participant_id'] ) && (int) $data['participant_id'] === (int) $participant_id ) {

						return $existing;

					}

				}



				$new = icon_psy_traits_share_generate_token();

				if ( $new === '' ) return '';



				$k_tok = icon_psy_traits_share_token_key_for_token( $new );



				update_option( $k_part, $new, false );

				update_option( $k_tok, array(

					'participant_id' => $participant_id,

					'created_at'     => time(),

					'report'         => 'traits',

					'v'              => 1,

				), false );



				return $new;

			}

		}

		if ( ! function_exists( 'icon_psy_traits_share_resolve_participant_id' ) ) {

			function icon_psy_traits_share_resolve_participant_id( $share_token ) {

				$share_token = preg_replace( '/[^a-zA-Z0-9\-_]/', '', (string) $share_token );

				if ( $share_token === '' ) return 0;



				$k_tok = icon_psy_traits_share_token_key_for_token( $share_token );

				$data  = get_option( $k_tok, array() );



				if ( is_array( $data ) && ! empty( $data['participant_id'] ) ) {

					return absint( $data['participant_id'] );

				}

				return 0;

			}

		}

		if ( ! function_exists( 'icon_psy_traits_share_revoke' ) ) {

			function icon_psy_traits_share_revoke( $participant_id ) {

				$participant_id = absint( $participant_id );

				if ( $participant_id <= 0 ) return;



				$k_part = icon_psy_traits_share_token_key_for_participant( $participant_id );

				$tok = get_option( $k_part, '' );

				$tok = is_string( $tok ) ? trim( $tok ) : '';



				if ( $tok !== '' ) {

					$k_tok = icon_psy_traits_share_token_key_for_token( $tok );

					delete_option( $k_tok );

				}

				delete_option( $k_part );

			}

		}



		// ------------------------------------------------------------

		// Resolve participant_id (priority: participant_id, shortcode, token/rater_id, share_token)

		// ------------------------------------------------------------

		if ( $participant_id <= 0 && $raters_table_exists ) {



			// Token lookup first (same pattern as your survey)

			if ( $token !== '' ) {

				$resolved_rater = $wpdb->get_row(

					$wpdb->prepare(

						"SELECT * FROM {$raters_table} WHERE token = %s LIMIT 1",

						$token

					)

				);

				if ( $resolved_rater && ! empty( $resolved_rater->participant_id ) ) {

					$participant_id     = (int) $resolved_rater->participant_id;

					$participant_source = 'token->raters.participant_id';

				}

			}



			// rater_id fallback (if provided)

			if ( $participant_id <= 0 && $rater_id > 0 ) {

				$resolved_rater = $wpdb->get_row(

					$wpdb->prepare(

						"SELECT * FROM {$raters_table} WHERE id = %d LIMIT 1",

						$rater_id

					)

				);

				if ( $resolved_rater && ! empty( $resolved_rater->participant_id ) ) {

					$participant_id     = (int) $resolved_rater->participant_id;

					$participant_source = 'get:rater_id->raters.participant_id';

				}

			}

		}



		// Share-token fallback (email share link)

		if ( $participant_id <= 0 && $share_token !== '' ) {

			$pid_from_share = icon_psy_traits_share_resolve_participant_id( $share_token );

			if ( $pid_from_share > 0 ) {

				$participant_id     = (int) $pid_from_share;

				$participant_source = 'share_token->options.participant_id';

			}

		}



		// ------------------------------------------------------------

		// Admin actions: regenerate / revoke share token (nonce protected)

		// ------------------------------------------------------------

		$share_actions_note = '';

		$is_admin_share     = is_user_logged_in() && current_user_can( 'manage_options' );



		if ( $is_admin_share && $participant_id > 0 ) {



			$do_regen  = isset( $_GET['regen_share'] ) ? sanitize_text_field( wp_unslash( $_GET['regen_share'] ) ) : '';

			$do_revoke = isset( $_GET['revoke_share'] ) ? sanitize_text_field( wp_unslash( $_GET['revoke_share'] ) ) : '';

			$nonce_in  = isset( $_GET['share_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['share_nonce'] ) ) : '';



			if ( $do_regen !== '' || $do_revoke !== '' ) {

				$nonce_ok = wp_verify_nonce( $nonce_in, 'icon_psy_traits_share_' . (int) $participant_id );



				if ( $nonce_ok ) {

					if ( $do_revoke !== '' ) {

						icon_psy_traits_share_revoke( (int) $participant_id );

						$share_actions_note = 'Share link revoked.';

						$share_token = ''; // clear current

					} else {

						// regenerate = revoke then create

						icon_psy_traits_share_revoke( (int) $participant_id );

						$share_token = icon_psy_traits_share_get_or_create_token( (int) $participant_id );

						$share_actions_note = 'Share link regenerated.';

					}

				} else {

					$share_actions_note = 'Share action blocked (invalid nonce).';

				}

			}

		}



		if ( $participant_id <= 0 ) {

			return '<div style="max-width:820px;margin:0 auto;padding:18px;font-family:system-ui,-apple-system,Segoe UI,sans-serif;">

				<div style="background:#fef2f2;border:1px solid #fecaca;padding:16px 14px;border-radius:14px;">

					<strong style="color:#7f1d1d;">Missing participant_id.</strong>

					<div style="margin-top:6px;font-size:12px;color:#6b7280;">Use /traits-report/?participant_id=XX</div>

					<div style="margin-top:6px;font-size:12px;color:#6b7280;">Or /traits-report/?token=XXXX (if raters table/token exists)</div>

					<div style="margin-top:6px;font-size:12px;color:#6b7280;">Or /traits-report/?share_token=XXXX (email share link)</div>

				</div>

			</div>';

		}



		// ------------------------------------------------------------
		// Helpers (DB + decode)

		// ------------------------------------------------------------

		$table_exists = function( $table ) use ( $wpdb ) {

			$table = (string) $table;

			if ( $table === '' ) return false;

			$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );

			return ( $found === $table );

		};



		$first_existing_table = function( $candidates ) use ( $table_exists ) {

			foreach ( (array) $candidates as $t ) {

				if ( $table_exists( $t ) ) return $t;

			}

			return '';

		};



		$get_cols = function( $table ) use ( $wpdb ) {

			$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );

			return is_array( $cols ) ? $cols : array();

		};



		$decode_detail_json = function( $raw ) {

			$raw = is_string( $raw ) ? trim( $raw ) : '';

			if ( $raw === '' || $raw === 'null' ) return null;



			$d1 = json_decode( $raw, true );

			if ( is_array( $d1 ) ) return $d1;



			if ( is_string( $d1 ) && $d1 !== '' ) {

				$d2 = json_decode( $d1, true );

				if ( is_array( $d2 ) ) return $d2;

			}



			$unescaped = stripcslashes( $raw );

			if ( $unescaped !== $raw ) {

				$d3 = json_decode( $unescaped, true );

				if ( is_array( $d3 ) ) return $d3;



				if ( is_string( $d3 ) && $d3 !== '' ) {

					$d4 = json_decode( $d3, true );

					if ( is_array( $d4 ) ) return $d4;

				}

			}



			if ( function_exists( 'is_serialized' ) && is_serialized( $raw ) ) {

				$u = @unserialize( $raw );

				if ( is_array( $u ) ) return $u;

			}



			return null;

		};



		// ------------------------------------------------------------

		// Tables

		// ------------------------------------------------------------

		$participants_table = $wpdb->prefix . 'icon_psy_participants';

		$projects_table     = $wpdb->prefix . 'icon_psy_projects';



		$results_candidates = array(

			$wpdb->prefix . 'icon_assessment_results',

			$wpdb->prefix . 'icon_psy_results',

			$wpdb->prefix . 'icon_psy_assessment_results',

		);



		$results_table = $first_existing_table( $results_candidates );



		if ( $results_table === '' ) {

			return '<div style="max-width:900px;margin:0 auto;padding:18px;font-family:system-ui,-apple-system,Segoe UI,sans-serif;">

				<div style="background:#fef2f2;border:1px solid #fecaca;padding:16px 14px;border-radius:14px;">

					<strong style="color:#7f1d1d;">Results table not found.</strong>

					<div style="margin-top:6px;font-size:12px;color:#6b7280;">Tried: <code>' . esc_html( implode( ', ', $results_candidates ) ) . '</code></div>

				</div>

			</div>';

		}



		$results_cols        = $get_cols( $results_table );

		$has_completed_at    = in_array( 'completed_at', $results_cols, true );

		$has_status_col      = in_array( 'status', $results_cols, true );

		$has_created_at      = in_array( 'created_at', $results_cols, true );

		$has_detail_json     = in_array( 'detail_json', $results_cols, true );

		$has_participant_col = in_array( 'participant_id', $results_cols, true );



		if ( ! $has_detail_json || ! $has_participant_col ) {

			return '<div style="max-width:900px;margin:0 auto;padding:18px;font-family:system-ui,-apple-system,Segoe UI,sans-serif;">

				<div style="background:#fef2f2;border:1px solid #fecaca;padding:16px 14px;border-radius:14px;">

					<strong style="color:#7f1d1d;">Results table schema mismatch.</strong>

					<div style="margin-top:6px;font-size:12px;color:#6b7280;">Table: <code>' . esc_html( $results_table ) . '</code></div>

					<div style="margin-top:6px;font-size:12px;color:#6b7280;">Required columns: <code>participant_id</code>, <code>detail_json</code></div>

				</div>

			</div>';

		}



		// ------------------------------------------------------------

		// Load participant + project

		// ------------------------------------------------------------

		$participant = $wpdb->get_row(

			$wpdb->prepare(

				"SELECT p.*, pr.name AS project_name, pr.client_name AS client_name

				 FROM {$participants_table} p

				 LEFT JOIN {$projects_table} pr ON pr.id = p.project_id

				 WHERE p.id = %d

				 LIMIT 1",

				$participant_id

			)

		);



		if ( ! $participant ) {

			return '<div style="max-width:820px;margin:0 auto;padding:18px;font-family:system-ui,-apple-system,Segoe UI,sans-serif;">

				<div style="background:#fef2f2;border:1px solid #fecaca;padding:16px 14px;border-radius:14px;">

					<strong style="color:#7f1d1d;">Participant not found.</strong>

				</div>

			</div>';

		}



		$participant_name = (string) ( $participant->name ?: 'Participant' );

		$participant_role = (string) ( $participant->role ?: '' );

		$project_name     = (string) ( $participant->project_name ?: '' );

		$client_name      = (string) ( $participant->client_name ?: '' );



		if ( ! function_exists( 'icon_psy_hex_to_rgba' ) ) {

			function icon_psy_hex_to_rgba( $hex, $alpha = 1 ) {

				$hex = is_string( $hex ) ? trim( $hex ) : '';

				$hex = ltrim( $hex, '#' );

				if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) return 'rgba(0,0,0,' . floatval( $alpha ) . ')';



				$r = hexdec( substr( $hex, 0, 2 ) );

				$g = hexdec( substr( $hex, 2, 2 ) );

				$b = hexdec( substr( $hex, 4, 2 ) );



				$a = floatval( $alpha );

				if ( $a < 0 ) $a = 0;

				if ( $a > 1 ) $a = 1;



				return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $a . ')';

			}

		}



		// ------------------------------------------------------------

		// Branding (logo + colours) — SAME SOURCE as surveys

		// ------------------------------------------------------------

		$branding_debug = array(

			'source'    => 'defaults',

			'primary'   => '#15a06d',

			'secondary' => '#14a4cf',

			'logo_url'  => '',

		);



		$branding = array(

			'primary'   => '#15a06d',

			'secondary' => '#14a4cf',

			'logo_url'  => '',

		);



		$branding_helper_loaded = false;



		if ( ! function_exists( 'icon_psy_get_branding_for_participant' ) ) {



			// ✅ REAL location: icon-psychometric-system/includes/shortcodes/helpers-branding.php

			// This traits report file is also in .../includes/shortcodes/ so we can load it directly.

			$branding_file = __DIR__ . '/helpers-branding.php';



			if ( file_exists( $branding_file ) ) {

				require_once $branding_file;

				$branding_helper_loaded = true;

			}



		} else {

			$branding_helper_loaded = true;

		}



		// ✅ If the participant helper is loaded but client branding function is not,

		// load the client-branding helper too (update the path if your file name differs).

		if ( ! function_exists( 'icon_psy_get_client_branding' ) ) {

			$client_branding_file = __DIR__ . '/helpers-client-branding.php'; // <-- only if this file exists in same folder

			if ( file_exists( $client_branding_file ) ) {

				require_once $client_branding_file;

			}

		}



		if ( function_exists( 'icon_psy_get_branding_for_participant' ) ) {

			$b = icon_psy_get_branding_for_participant( (int) $participant_id );

			if ( is_array( $b ) ) {

				$branding = array_merge( $branding, $b );

			}

			$branding_debug['source'] = 'icon_psy_get_branding_for_participant';

		}



		$brand_primary   = ! empty( $branding['primary'] ) ? (string) $branding['primary'] : '#15a06d';

		$brand_secondary = ! empty( $branding['secondary'] ) ? (string) $branding['secondary'] : '#14a4cf';

		$brand_logo_url  = ! empty( $branding['logo_url'] ) ? esc_url_raw( $branding['logo_url'] ) : '';



		// Hard safety: only allow hex like #RRGGBB

		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $brand_primary ) )   { $brand_primary   = '#15a06d'; $branding_debug['primary_invalid'] = 1; }

		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $brand_secondary ) ) { $brand_secondary = '#14a4cf'; $branding_debug['secondary_invalid'] = 1; }



		$branding_debug['primary']   = $brand_primary;

		$branding_debug['secondary'] = $brand_secondary;

		$branding_debug['logo_url']  = $brand_logo_url;



		// ------------------------------------------------------------

		// Helpers (logic)

		// ------------------------------------------------------------

		$clamp = function( $v, $min, $max ) {

			$v = (int) $v;

			return max( (int) $min, min( (int) $max, $v ) );

		};



		$band = function( $v ) {

			$v = (float) $v;

			if ( $v >= 5.6 ) return array( 'High', '#065f46', '#ecfdf5', '#bbf7d0' );

			if ( $v >= 4.6 ) return array( 'Solid', '#1e3a8a', '#eff6ff', '#c7d2fe' );

			if ( $v >= 3.6 ) return array( 'Developing', '#92400e', '#fffbeb', '#fde68a' );

			return array( 'Priority', '#7f1d1d', '#fef2f2', '#fecaca' );

		};



		$delta_classification = function( $delta ) {

			$delta = (float) $delta;

			if ( $delta >= 0.35 ) return 'Meaningful improvement';

			if ( $delta <= -0.35 ) return 'Meaningful decline';

			if ( abs( $delta ) >= 0.15 ) return 'Directional change';

			return 'Broadly stable';

		};



		$score_color = function( $score ) {

			$s = (int) $score;

			$s = max( 1, min( 7, $s ) );



			if ( $s <= 4 ) {

				$t = ( $s - 1 ) / 3;

				$r = (int) round( 220 + (245 - 220) * $t );

				$g = (int) round( 38 + (158 - 38) * $t );

				$b = (int) round( 38 + (11 - 38) * $t );

			} else {

				$t = ( $s - 4 ) / 3;

				$r = (int) round( 245 + (5 - 245) * $t );

				$g = (int) round( 158 + (150 - 158) * $t );

				$b = (int) round( 11 + (105 - 11) * $t );

			}



			$bg   = sprintf( 'rgb(%d,%d,%d)', $r, $g, $b );

			$text = ( $s <= 3 ) ? '#ffffff' : '#0b2f2a';



			return array( $bg, $text );

		};



		$delta_color = function( $delta ) {

			$d = (float) $delta;

			if ( abs( $d ) < 0.00001 ) return array( '#f3f4f6', '#0f172a' );



			$d   = max( -6.0, min( 6.0, $d ) );

			$int = abs( $d ) / 6.0;



			if ( $d > 0 ) {

				$r    = (int) round( 243 + (5 - 243) * $int );

				$g    = (int) round( 243 + (150 - 243) * $int );

				$b    = (int) round( 243 + (105 - 243) * $int );

				$text = '#064e3b';

			} else {

				$r    = (int) round( 243 + (220 - 243) * $int );

				$g    = (int) round( 243 + (38 - 243) * $int );

				$b    = (int) round( 243 + (38 - 243) * $int );

				$text = '#7f1d1d';

			}



			return array( sprintf( 'rgb(%d,%d,%d)', $r, $g, $b ), $text );

		};



		$stability_label = function( $abs_gap ) {

			$g = (int) $abs_gap;

			if ( $g <= 1 ) return array( 'Stable', '#065f46', '#ecfdf5', '#bbf7d0' );

			if ( $g === 2 ) return array( 'Variable', '#92400e', '#fffbeb', '#fde68a' );

			return array( 'Volatile', '#7f1d1d', '#fef2f2', '#fecaca' );

		};



		$schema_ok = function( $decoded ) {

			if ( ! is_array( $decoded ) ) return false;

			if ( empty( $decoded['schema'] ) ) return false;

			if ( empty( $decoded['items'] ) || ! is_array( $decoded['items'] ) ) return false;

			$schema = (string) $decoded['schema'];

			return in_array( $schema, array( 'icon_traits_v2', 'icon_traits_v1' ), true );

		};



		$get_phase = function( $decoded ) {

			$p = isset( $decoded['measurement_phase'] ) ? sanitize_key( (string) $decoded['measurement_phase'] ) : '';

			return in_array( $p, array( 'baseline', 'post' ), true ) ? $p : 'baseline';

		};



		$get_pair_key = function( $decoded ) {

			return isset( $decoded['pair_key'] ) ? (string) $decoded['pair_key'] : '';

		};



		$normalise_items_map = function( $payload ) use ( $clamp ) {

			$map = array();

			if ( ! is_array( $payload ) || empty( $payload['items'] ) || ! is_array( $payload['items'] ) ) return $map;



			foreach ( (array) $payload['items'] as $k => $v ) {

				if ( ! is_array( $v ) ) continue;



				$q1         = isset( $v['q1'] ) ? $clamp( $v['q1'], 1, 7 ) : 1;

				$q2         = isset( $v['q2'] ) ? $clamp( $v['q2'], 1, 7 ) : 1;

				$q3         = isset( $v['q3'] ) ? $clamp( $v['q3'], 1, 7 ) : 1;

				$confidence = isset( $v['confidence'] ) ? $clamp( $v['confidence'], 1, 5 ) : 3;

				$evidence   = isset( $v['evidence'] ) ? (string) $v['evidence'] : '';



				// name

				if ( isset( $v['competency_name'] ) && $v['competency_name'] !== '' ) $name = (string) $v['competency_name'];

				elseif ( isset( $v['trait_name'] ) && $v['trait_name'] !== '' ) $name = (string) $v['trait_name'];

				elseif ( isset( $v['trait_key'] ) && $v['trait_key'] !== '' ) $name = (string) $v['trait_key'];

				else $name = (string) $k;



				// module

				if ( isset( $v['module'] ) && $v['module'] !== '' ) $module = (string) $v['module'];

				elseif ( isset( $v['competency_module'] ) && $v['competency_module'] !== '' ) $module = (string) $v['competency_module'];

				elseif ( isset( $v['trait_module'] ) && $v['trait_module'] !== '' ) $module = (string) $v['trait_module'];

				else $module = 'core';



				$avg = ( $q1 + $q2 + $q3 ) / 3;



				$map[(string) $k] = array(

					'key'        => (string) $k,

					'name'       => $name,

					'module'     => $module,

					'q1'         => $q1,

					'q2'         => $q2,

					'q3'         => $q3,

					'avg'        => (float) $avg,

					'confidence' => (int) $confidence,

					'evidence'   => $evidence,

				);

			}



			return $map;

		};



		$make_summary = function( $base, $post = null ) use ( $delta_classification ) {

			$name         = $base['name'];

			$q1           = (int) $base['q1'];

			$q2           = (int) $base['q2'];

			$avg          = (float) $base['avg'];

			$pressure_gap = $q2 - $q1;

			$abs_gap      = abs( $pressure_gap );



			if ( $avg >= 5.6 ) $action = 'Maintain: apply deliberately to one high value task each week, then review evidence.';

			elseif ( $avg >= 4.6 ) $action = 'Sharpen: pick one observable behaviour to move by one point and capture evidence.';

			elseif ( $avg >= 3.6 ) $action = 'Build: practise in low risk situations, then repeat under time/complexity constraints.';

			else $action = 'Priority: start small, focus on one behaviour, and record short evidence twice per week.';



			if ( $abs_gap >= 2 && $pressure_gap < 0 ) $note = 'Pattern: less stable under pressure (variance ' . (int) $abs_gap . '). Add a fallback behaviour for time tight situations.';

			elseif ( $abs_gap >= 2 && $pressure_gap > 0 ) $note = 'Pattern: strengthens under pressure (variance ' . (int) $abs_gap . '). Use it as a stabiliser for complex tasks.';

			else $note = 'Pattern: broadly stable across contexts (variance ' . (int) $abs_gap . '). Focus on making it more repeatable.';



			if ( $post && is_array( $post ) ) {

				$delta          = (float) $post['avg'] - (float) $base['avg'];

				$delta_txt      = ( $delta >= 0 ? '+' : '' ) . number_format_i18n( $delta, 2 );

				$delta_label    = $delta_classification( $delta );

				$post_gap       = abs( (int) $post['q2'] - (int) $post['q1'] );

				$pressure_trend = ( $post_gap < $abs_gap )

					? 'Consistency: under pressure stability improved (variance ' . (int) $abs_gap . ' to ' . (int) $post_gap . ').'

					: ( ( $post_gap > $abs_gap )

						? 'Consistency: under pressure stability reduced (variance ' . (int) $abs_gap . ' to ' . (int) $post_gap . ').'

						: 'Consistency: under pressure pattern is similar (variance ' . (int) $abs_gap . ').'

					);



				return array(

					'headline' => $name,

					'bullets'  => array(

						'Change: ' . $delta_label . ' (' . $delta_txt . ').',

						$pressure_trend,

						'Next: ' . $action,

					),

				);

			}



			return array(

				'headline' => $name,

				'bullets'  => array(

					$note,

					'Next: ' . $action,

				),

			);

		};



		// ------------------------------------------------------------

		// Load recent completed-ish results for this participant

		// ------------------------------------------------------------

		$where_bits   = array();

		$where_bits[] = "participant_id = %d";



		if ( $has_status_col ) {

			$where_bits[] = "(status IN ('completed','complete','submitted','done')" . ( $has_completed_at ? " OR completed_at IS NOT NULL" : "" ) . ")";

		} elseif ( $has_completed_at ) {

			$where_bits[] = "(completed_at IS NOT NULL)";

		}



		$order_col      = $has_created_at ? 'created_at' : ( $has_completed_at ? 'completed_at' : 'id' );

		$sql_candidates = "SELECT * FROM {$results_table} WHERE " . implode( " AND ", $where_bits ) . " ORDER BY {$order_col} DESC LIMIT 200";

		$candidate_rows = $wpdb->get_results( $wpdb->prepare( $sql_candidates, $participant_id ) );



		$parsed = array();

		foreach ( (array) $candidate_rows as $row ) {

			$raw         = isset( $row->detail_json ) ? (string) $row->detail_json : '';

			$decoded_any = $decode_detail_json( $raw );

			if ( ! is_array( $decoded_any ) ) continue;

			if ( ! $schema_ok( $decoded_any ) ) continue;



			$created_val = '';

			if ( isset( $row->created_at ) && $row->created_at ) $created_val = (string) $row->created_at;

			elseif ( isset( $row->completed_at ) && $row->completed_at ) $created_val = (string) $row->completed_at;

			elseif ( isset( $row->id ) ) $created_val = 'id:' . (int) $row->id;



			$parsed[] = array(

				'row'        => $row,

				'payload'    => $decoded_any,

				'phase'      => $get_phase( $decoded_any ),

				'pair_key'   => $get_pair_key( $decoded_any ),

				'created_at' => $created_val,

			);

		}



		if ( empty( $parsed ) ) {

			return '<div style="max-width:900px;margin:0 auto;padding:18px;font-family:system-ui,-apple-system,Segoe UI,sans-serif;">

				<div style="background:#fefce8;border:1px solid #fde68a;padding:16px 14px;border-radius:14px;">

					<strong style="color:#78350f;">No usable results found yet.</strong>

					<div style="margin-top:6px;font-size:12px;color:#6b7280;">

						This report looks for payloads where <code>schema</code> is <code>icon_traits_v2</code> (or <code>icon_traits_v1</code>) and <code>items</code> exist.

					</div>

				</div>

			</div>';

		}



		// ------------------------------------------------------------

		// Choose baseline/post (prefer latest pair_key group)

		// ------------------------------------------------------------

		$pair_groups = array();

		foreach ( $parsed as $p ) {

			$pk = $p['pair_key'];

			if ( $pk === '' ) continue;



			if ( ! isset( $pair_groups[ $pk ] ) ) {

				$pair_groups[ $pk ] = array(

					'latest_ts' => '',

					'baseline'  => null,

					'post'      => null,

				);

			}



			if ( $p['created_at'] && ( $pair_groups[ $pk ]['latest_ts'] === '' || $p['created_at'] > $pair_groups[ $pk ]['latest_ts'] ) ) {

				$pair_groups[ $pk ]['latest_ts'] = $p['created_at'];

			}



			if ( $p['phase'] === 'baseline' && ! $pair_groups[ $pk ]['baseline'] ) $pair_groups[ $pk ]['baseline'] = $p;

			if ( $p['phase'] === 'post' && ! $pair_groups[ $pk ]['post'] ) $pair_groups[ $pk ]['post'] = $p;

		}



		$use_baseline = null;

		$use_post     = null;



		if ( ! empty( $pair_groups ) ) {

			$best_pk = '';

			$best_ts = '';

			foreach ( $pair_groups as $pk => $g ) {

				if ( $g['latest_ts'] && ( $best_ts === '' || $g['latest_ts'] > $best_ts ) ) {

					$best_ts = $g['latest_ts'];

					$best_pk = $pk;

				}

			}

			if ( $best_pk !== '' ) {

				$use_baseline = $pair_groups[ $best_pk ]['baseline'];

				$use_post     = $pair_groups[ $best_pk ]['post'];

			}

		}



		// fallback: if no pair_key metadata, compare newest vs previous

		if ( ! $use_baseline && ! $use_post && count( $parsed ) >= 2 ) {

			$use_post     = $parsed[0];

			$use_baseline = $parsed[1];

		}



		$fallback_latest = $parsed[0];

		$is_comparison   = ( $use_baseline && $use_post );



		$baseline_row     = $is_comparison ? $use_baseline['row']     : ( $use_baseline ? $use_baseline['row']     : $fallback_latest['row'] );

		$baseline_payload = $is_comparison ? $use_baseline['payload']  : ( $use_baseline ? $use_baseline['payload']  : $fallback_latest['payload'] );

		$baseline_at      = (string) ( ( isset( $baseline_row->created_at ) ? $baseline_row->created_at : '' ) ?: ( isset( $baseline_row->completed_at ) ? $baseline_row->completed_at : '' ) ?: '' );



		$post_row     = $is_comparison ? $use_post['row'] : null;

		$post_payload = $is_comparison ? $use_post['payload'] : null;

		$post_at      = $is_comparison ? (string) ( ( isset( $post_row->created_at ) ? $post_row->created_at : '' ) ?: ( isset( $post_row->completed_at ) ? $post_row->completed_at : '' ) ?: '' ) : '';



		$ai_draft_id = 0;

		if ( isset( $baseline_payload['ai_draft_id'] ) ) $ai_draft_id = (int) $baseline_payload['ai_draft_id'];

		if ( $ai_draft_id <= 0 && $is_comparison && isset( $post_payload['ai_draft_id'] ) ) $ai_draft_id = (int) $post_payload['ai_draft_id'];



		// ------------------------------------------------------------

		// Build maps + derived views

		// ------------------------------------------------------------

		$base_map = $normalise_items_map( $baseline_payload );

		if ( empty( $base_map ) ) {

			return '<div style="max-width:900px;margin:0 auto;padding:18px;font-family:system-ui,-apple-system,Segoe UI,sans-serif;">

				<div style="background:#fef2f2;border:1px solid #fecaca;padding:16px 14px;border-radius:14px;">

					<strong style="color:#7f1d1d;">Baseline payload is missing items.</strong>

				</div>

			</div>';

		}



		$post_map = $is_comparison ? $normalise_items_map( $post_payload ) : array();



		$items = array_values( $base_map );

		usort( $items, function( $a, $b ) { return $b['avg'] <=> $a['avg']; } );



		if ( $is_comparison ) {

			foreach ( $post_map as $k => $pv ) {

				if ( isset( $base_map[ $k ] ) ) continue;

				$items[] = $pv;

			}

		}



		$modules = array();

		foreach ( $base_map as $v ) {

			$m = (string) $v['module'];

			if ( $m === '' ) $m = 'core';

			if ( ! isset( $modules[$m] ) ) $modules[$m] = array();

			$modules[$m][] = (string) $v['name'];

		}

		ksort( $modules );



		$base_avgs = array();

		foreach ( $base_map as $v ) $base_avgs[] = (float) $v['avg'];

		$baseline_overall = array_sum( $base_avgs ) / max( 1, count( $base_avgs ) );



		$post_overall       = null;

		$overall_delta      = null;

		$deltas             = array();

		$delta_q3           = array();

		$stability_improved = 0;

		$stability_worsened = 0;



		if ( $is_comparison ) {

			$post_avgs = array();

			foreach ( $post_map as $v ) $post_avgs[] = (float) $v['avg'];

			$post_overall  = array_sum( $post_avgs ) / max( 1, count( $post_avgs ) );

			$overall_delta = $post_overall - $baseline_overall;



			foreach ( $base_map as $k => $bv ) {

				if ( empty( $post_map[$k] ) ) continue;

				$pv = $post_map[$k];



				$base_gap = abs( (int) $bv['q2'] - (int) $bv['q1'] );

				$post_gap = abs( (int) $pv['q2'] - (int) $pv['q1'] );



				if ( $post_gap < $base_gap ) $stability_improved++;

				elseif ( $post_gap > $base_gap ) $stability_worsened++;



				$delta_q3[] = (float) $pv['q3'] - (float) $bv['q3'];



				$deltas[] = array(

					'key'   => $k,

					'name'  => $bv['name'],

					'delta' => (float) $pv['avg'] - (float) $bv['avg'],

					'base'  => (float) $bv['avg'],

				);

			}

			usort( $deltas, function( $a, $b ) { return $b['delta'] <=> $a['delta']; } );

		}



		$top_improve = $is_comparison ? array_slice( $deltas, 0, 5 ) : array();

		$top_drop    = $is_comparison ? array_slice( array_reverse( $deltas ), 0, 5 ) : array();



		$base_rank = array_values( $base_map );

		usort( $base_rank, function( $a, $b ) { return $b['avg'] <=> $a['avg']; } );

		$top_strengths     = array_slice( $base_rank, 0, 3 );

		$bottom_priorities = array_slice( array_reverse( $base_rank ), 0, 3 );



		// Radar uses top N baseline averages (safer count for label readability)

		$radar_count = 10; // was 12

		$radar_items = array_slice( $base_rank, 0, $radar_count );



		$radar_labels      = array();

		$radar_base_values = array();

		$radar_post_values = array();



		foreach ( $radar_items as $ri ) {

			$k = (string) $ri['key'];



			// ✅ FIX: decode HTML entities so "&amp;" becomes "&" for Chart.js labels

			$label = (string) $ri['name'];

			$label = wp_specialchars_decode( $label, ENT_QUOTES );

			$label = wp_strip_all_tags( $label );



			$radar_labels[]      = $label;

			$radar_base_values[] = isset( $base_map[$k] ) ? (float) $base_map[$k]['avg'] : (float) $ri['avg'];

			$radar_post_values[] = ( $is_comparison && isset( $post_map[$k] ) ) ? (float) $post_map[$k]['avg'] : null;

		}



		// Trend quadrants

		$trend_quadrants = array(

			'grow_protect'  => array(),

			'strength_risk' => array(),

			'breakthrough'  => array(),

			'priority'      => array(),

		);

		$trend_base_threshold = 4.6;



		if ( $is_comparison && ! empty( $deltas ) ) {

			foreach ( $deltas as $d ) {

				$high = ( (float) $d['base'] >= $trend_base_threshold );

				$up   = ( (float) $d['delta'] >= 0 );

				if ( $high && $up ) $trend_quadrants['grow_protect'][] = $d;

				elseif ( $high && ! $up ) $trend_quadrants['strength_risk'][] = $d;

				elseif ( ! $high && $up ) $trend_quadrants['breakthrough'][] = $d;

				else $trend_quadrants['priority'][] = $d;

			}

		}



		// Key takeaways

		$key_takeaways = array();

		if ( $is_comparison ) {

			$key_takeaways[] = 'Overall average changed by ' . ( (float) $overall_delta >= 0 ? '+' : '' ) . number_format_i18n( (float) $overall_delta, 2 ) . ' (Post minus Baseline).';

			if ( ! empty( $top_improve ) ) $key_takeaways[] = 'Most improved: ' . $top_improve[0]['name'] . ' (' . ( (float) $top_improve[0]['delta'] >= 0 ? '+' : '' ) . number_format_i18n( (float) $top_improve[0]['delta'], 2 ) . ').';

			if ( ! empty( $top_drop ) ) $key_takeaways[] = 'Largest decline: ' . $top_drop[0]['name'] . ' (' . number_format_i18n( (float) $top_drop[0]['delta'], 2 ) . ').';

			$key_takeaways[] = 'Under-pressure stability improved in ' . (int) $stability_improved . ' competencies, reduced in ' . (int) $stability_worsened . '.';

			if ( ! empty( $delta_q3 ) ) {

				$q3_avg_delta = array_sum( $delta_q3 ) / max( 1, count( $delta_q3 ) );

				$key_takeaways[] = 'Quality standard (Q3) average change: ' . ( $q3_avg_delta >= 0 ? '+' : '' ) . number_format_i18n( (float) $q3_avg_delta, 2 ) . '.';

			}

			$key_takeaways[] = 'Use the Trend Map to identify breakthroughs (low baseline, improved) and strengths at risk (high baseline, declined).';

		} else {

			$key_takeaways[] = 'This is a single snapshot. Use strengths and priorities to focus where the biggest return is likely.';

			if ( ! empty( $top_strengths ) ) $key_takeaways[] = 'Top strength: ' . $top_strengths[0]['name'] . ' (' . number_format_i18n( (float) $top_strengths[0]['avg'], 2 ) . ' / 7).';

			if ( ! empty( $bottom_priorities ) ) $key_takeaways[] = 'Top priority: ' . $bottom_priorities[0]['name'] . ' (' . number_format_i18n( (float) $bottom_priorities[0]['avg'], 2 ) . ' / 7).';

		}



		// ------------------------------------------------------------

		// Share token (create if admin + we are not already on a share link)

		// ------------------------------------------------------------

		$current_share_token = $share_token;

		if ( $is_admin_share && $participant_id > 0 ) {

			// Create or get token to show in UI

			$current_share_token = icon_psy_traits_share_get_or_create_token( (int) $participant_id );

		}



		// ------------------------------------------------------------

		// Render (screen)

		// ------------------------------------------------------------

		ob_start();

		?>

		<div class="icon-report-wrap" style="max-width:1260px;margin:0 auto;padding:22px 16px;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#0a3b34;background:

			radial-gradient(1100px 520px at 10% 0%, <?php echo esc_attr( icon_psy_hex_to_rgba( $brand_primary, 0.10 ) ); ?>, rgba(0,0,0,0) 60%),

			radial-gradient(900px 520px at 92% 14%, <?php echo esc_attr( icon_psy_hex_to_rgba( $brand_secondary, 0.10 ) ); ?>, rgba(0,0,0,0) 62%);

			border-radius:26px;">



			<?php if ( $icon_debug_on ) : ?>

				<div style="margin:0 0 12px 0;padding:12px 12px;border-radius:14px;background:#0b1220;color:#e5e7eb;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;font-size:12px;line-height:1.4;overflow:auto;">

					<div style="font-weight:900;margin-bottom:6px;">ICON Traits Report debug</div>

					<div>participant_id: <?php echo (int) $participant_id; ?> (<?php echo esc_html( $participant_source ); ?>)</div>

					<div>token: <?php echo $token !== '' ? esc_html( $token ) : '(none)'; ?></div>

					<div>share_token: <?php echo $share_token !== '' ? esc_html( $share_token ) : '(none)'; ?></div>

					<div>rater_id: <?php echo (int) $rater_id; ?></div>

					<div>raters_table_exists: <?php echo $raters_table_exists ? 'YES' : 'NO'; ?></div>

					<div>branding_source: <?php echo esc_html( $branding_debug['source'] ); ?></div>

					<div>primary: <?php echo esc_html( $branding_debug['primary'] ); ?></div>

					<div>secondary: <?php echo esc_html( $branding_debug['secondary'] ); ?></div>

					<div>logo_url: <?php echo $branding_debug['logo_url'] !== '' ? esc_html( $branding_debug['logo_url'] ) : '(none)'; ?></div>

				</div>

			<?php endif; ?>



			<style>

				:root{

					--icon-green: <?php echo esc_html( $brand_primary ); ?>;

					--icon-blue: <?php echo esc_html( $brand_secondary ); ?>;

					--icon-grad: linear-gradient(90deg,

						<?php echo esc_html( icon_psy_hex_to_rgba( $brand_primary, 0.95 ) ); ?>,

						<?php echo esc_html( icon_psy_hex_to_rgba( $brand_secondary, 0.95 ) ); ?>

					);

					--text-dark:#0a3b34;

					--text-mid:#425b56;

					--ink:#071b1a;

					--muted:#64748b;

					--line: rgba(10,59,52,0.12);

					--cardShadow: 0 18px 46px rgba(10,59,52,0.14);

					--cardShadowHover: 0 22px 58px rgba(10,59,52,0.18);

				}

				*{ box-sizing:border-box; }

				html, body{ -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale; }

				a{ color: inherit; }

				::selection{ background: <?php echo esc_html( icon_psy_hex_to_rgba( $brand_secondary, 0.22 ) ); ?>; }



				.icon-cover{

					position:relative;

					border-radius: 24px;

					padding: 18px 20px;

					margin-bottom: 12px;

					overflow:hidden;

					background: radial-gradient(980px 460px at 8% 10%, rgba(21,160,109,0.14) 0%, rgba(21,160,109,0) 60%),

								radial-gradient(860px 420px at 92% 22%, rgba(20,164,207,0.14) 0%, rgba(20,164,207,0) 62%),

								rgba(255,255,255,0.94);

					border: 1px solid rgba(21,160,109,0.22);

					box-shadow: 0 20px 46px rgba(10,59,52,0.16);

				}

				.icon-cover:after{

					content:'';

					position:absolute;

					right:-160px;

					top:-180px;

					width: 380px;

					height: 380px;

					border-radius: 999px;

					background: radial-gradient(circle, rgba(20,164,207,0.16), rgba(20,164,207,0) 70%);

					pointer-events:none;

				}

				.icon-cover:before{

					content:'';

					position:absolute;

					left:-120px;

					bottom:-170px;

					width: 360px;

					height: 360px;

					border-radius: 999px;

					background: radial-gradient(circle, rgba(21,160,109,0.14), rgba(21,160,109,0) 70%);

					pointer-events:none;

				}

				.icon-eyebrow{

					display:inline-block;

					font-size:11px;

					font-weight:950;

					letter-spacing:.12em;

					text-transform:uppercase;

					color:#0b2f2a;

					padding:6px 10px;

					border-radius:999px;

					border:1px solid rgba(21,160,109,.28);

					background: rgba(236,253,245,0.9);

				}

				.icon-cover h1{

					margin:10px 0 8px 0;

					font-size:34px;

					line-height:1.05;

					font-weight:950;

					letter-spacing:.01em;

					color: var(--text-dark);

					text-align:center;

				}

				.icon-cover h1 span{

					background: var(--icon-grad);

					-webkit-background-clip:text;

					background-clip:text;

					color:transparent;

				}

				.icon-cover-lead{ margin:0 auto; font-size:13px; color: var(--text-mid); max-width: 780px; line-height:1.45; text-align:center; }



				.hero{

					position:relative;

					border-radius: 22px;

					padding: 16px 18px;

					overflow:hidden;

					background: radial-gradient(900px 420px at 12% 8%, rgba(21,160,109,0.12) 0%, rgba(21,160,109,0.00) 60%),

								radial-gradient(760px 360px at 92% 22%, rgba(20,164,207,0.12) 0%, rgba(20,164,207,0.00) 62%),

								rgba(255,255,255,0.94);

					border:1px solid rgba(21,160,109,0.22);

					box-shadow: var(--cardShadow);

				}

				.hero:before{

					content:'';

					position:absolute;

					left:0; top:0; right:0;

					height: 5px;

					background: var(--icon-grad);

				}



				/* ✅ CENTER EVERYTHING IN THE HERO (TOP CARD) */

				.hero-top{

					display:flex;

					gap:12px;

					flex-wrap:wrap;

					align-items:flex-start;

					justify-content:center;

					margin-bottom: 10px;

					text-align:center;

				}

				.hero-top > div{ flex: 1 1 100%; }

				.hero-right{ text-align:center; }



				.hero h2{margin:0 0 6px;font-size:20px;font-weight:950;color:var(--text-dark);letter-spacing:.02em;text-align:center;}

				.hero p{margin:0;font-size:13px;color:var(--text-mid);text-align:center;}



				.tag{

					display:inline-flex;

					align-items:center;

					gap:8px;

					font-size:11px;

					font-weight:950;

					color:#0b2f2a;

					padding:6px 10px;

					border-radius:999px;

					border:1px solid rgba(21,160,109,.22);

					background: rgba(236,253,245,0.9);

					margin: 0 auto 8px auto;

				}

				.dot{ width:8px;height:8px;border-radius:999px; background: var(--icon-grad); }



				.chip-row{

					display:flex;

					flex-wrap:wrap;

					gap:6px;

					margin-top:10px;

					font-size:11px;

					justify-content:center;

				}

				.chip{

					padding:4px 10px;

					border-radius:999px;

					border:1px solid rgba(21,160,109,.40);

					background: rgba(236,253,245,0.9);

					color:var(--text-dark);

					box-shadow: 0 10px 22px rgba(10,59,52,0.06);

				}

				.chip-muted{

					padding:4px 10px;

					border-radius:999px;

					border:1px solid rgba(20,164,207,.25);

					background: rgba(239,246,255,0.9);

					color:#1e3a8a;

					box-shadow: 0 10px 22px rgba(10,59,52,0.05);

				}



				/* Share UI */

				.share-wrap{

					margin-top:12px;

					border:1px solid rgba(10,59,52,0.10);

					border-radius:18px;

					padding:12px 12px;

					background:#ffffff;

					box-shadow: 0 10px 26px rgba(10,59,52,0.08);

					text-align:left;

				}

				.share-title{

					font-size:11px;

					text-transform:uppercase;

					letter-spacing:.08em;

					font-weight:950;

					color:#0b2f2a;

					margin:0 0 6px 0;

					text-align:center;

				}

				.share-row{

					display:flex;

					gap:8px;

					flex-wrap:wrap;

					align-items:center;

					justify-content:center;

				}

				.share-row input{

					width:min(720px, 100%);

					padding:10px 12px;

					border-radius:12px;

					border:1px solid rgba(10,59,52,0.16);

					background:#f8fafc;

					font-size:12px;

					color:#0b2f2a;

				}

				.share-btn{

					display:inline-flex;

					align-items:center;

					justify-content:center;

					gap:8px;

					padding:10px 12px;

					border-radius:12px;

					border:1px solid rgba(21,160,109,.28);

					background: rgba(236,253,245,0.92);

					color:#0b2f2a;

					font-size:12px;

					font-weight:950;

					cursor:pointer;

					user-select:none;

					text-decoration:none;

				}

				.share-btn.alt{

					border-color: rgba(20,164,207,.22);

					background: rgba(239,246,255,0.92);

					color:#1e3a8a;

				}

				.share-btn.danger{

					border-color: rgba(127,29,29,.22);

					background: rgba(254,242,242,0.92);

					color:#7f1d1d;

				}

				.share-note{

					margin-top:8px;

					font-size:12px;

					color: var(--muted);

					text-align:center;

					line-height:1.35;

				}



				.score-grid{ display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:10px; margin-top: 10px; }

				@media(max-width:1100px){ .score-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }

				@media(max-width:620px){ .score-grid{ grid-template-columns: 1fr; } }



				.score-card{

					border-radius: 18px;

					padding: 12px 12px;

					border:1px solid rgba(10,59,52,0.10);

					background:#fff;

					box-shadow: 0 10px 26px rgba(10,59,52,0.08);

				}

				.score-k{

					font-size:11px;

					text-transform:uppercase;

					letter-spacing:.06em;

					color:#64748b;

					font-weight:950;

					margin-bottom:6px;

					text-align:center;

				}

				.score-v{ display:flex; align-items:baseline; justify-content:center; gap:10px; text-align:center; }

				.score-num{ font-size:24px; font-weight:950; color:#0b2f2a; letter-spacing:.01em; }

				.score-sub{ font-size:11px; color:#64748b; font-weight:900; }

				.scorebar{

					margin-top:10px;

					height:8px;

					border-radius:999px;

					background:#f1f5f9;

					overflow:hidden;

					border:1px solid rgba(10,59,52,0.08);

				}

				.scorebar span{ display:block; height:100%; background: var(--icon-grad); width: 50%; }



				.grid-2{ display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);gap:12px; }

				@media(max-width:980px){ .grid-2{ grid-template-columns:1fr; } }



				.icon-wow-card{

					position:relative;

					border-radius: 22px;

					padding: 16px 18px;

					margin-bottom: 12px;

					overflow: hidden;

					background: radial-gradient(900px 420px at 12% 8%, rgba(21,160,109,0.12) 0%, rgba(21,160,109,0.00) 60%),

								radial-gradient(760px 360px at 92% 22%, rgba(20,164,207,0.12) 0%, rgba(20,164,207,0.00) 62%),

								rgba(255,255,255,0.94);

					border:1px solid rgba(21,160,109,0.22);

					box-shadow: var(--cardShadow);

					transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;

				}

				.icon-wow-card:hover{ transform: translateY(-2px); box-shadow: var(--cardShadowHover); border-color: rgba(20,164,207,0.30); }

				.icon-wow-card:before{

					content:'';

					position:absolute;

					left:0; top:0; right:0;

					height: 5px;

					background: var(--icon-grad);

				}

				.icon-wow-card:after{

					content:'';

					position:absolute;

					right:-140px;

					top:-140px;

					width: 320px;

					height: 320px;

					border-radius: 999px;

					background: radial-gradient(circle, rgba(20,164,207,0.14), rgba(20,164,207,0.00) 70%);

					pointer-events:none;

				}

				.icon-wow-card h3{ margin:0 0 8px; font-size:14px; font-weight:950; color:#0b2f2a; letter-spacing:.01em; }

				.icon-wow-card .mini{ font-size:12px; color:var(--muted); line-height:1.35; }



				.pill{

					display:inline-flex;align-items:center;gap:8px;

					padding:7px 11px;border-radius:999px;

					border:1px solid rgba(20,164,207,.18);

					background:#fff;

					color: var(--ink);

					font-size:11px;font-weight:950;

					letter-spacing:.01em;

					box-shadow: 0 10px 22px rgba(10,59,52,0.06);

					transition: transform .14s ease, box-shadow .14s ease, border-color .14s ease;

				}

				a.pill:hover{ transform: translateY(-1px); box-shadow: 0 14px 28px rgba(10,59,52,0.10); border-color: rgba(21,160,109,0.30); text-decoration:none; }



				.delta-up{ color:#065f46;font-weight:950; }

				.delta-down{ color:#7f1d1d;font-weight:950; }



				.klist{ margin:0;padding-left:18px;font-size:12px;color:#425b56; }

				.klist li{ margin:4px 0; }



				.heat-wrap{ overflow:auto; }

				.heat{

					width:100%;

					min-width:0;

					display:grid;

					grid-template-columns: minmax(220px, 1.4fr) repeat(3, minmax(90px, 0.6fr));

					gap:8px;

					align-items:stretch;

				}

				.heat .hcell{

					background:#f9fafb;

					border:1px solid #e5e7eb;

					border-radius:12px;

					padding:10px 10px;

					font-size:11px;

					text-transform:uppercase;

					letter-spacing:.05em;

					color:#6b7280;

					font-weight:900;

				}

				.heat .name{ border:1px solid #e5e7eb; border-radius:12px; padding:10px 10px; background:#fff; }

				.heat .name .t{ font-weight:950;color:#0b2f2a;font-size:12px; }

				.heat .name .m{ margin-top:2px;font-size:11px;color:#64748b; }

				.heat .scorecell{

					border-radius:12px;

					border:1px solid rgba(10,59,52,0.10);

					padding:10px 10px;

					display:flex;

					align-items:center;

					justify-content:space-between;

					gap:8px;

					font-weight:950;

				}

				.heat .scorecell em{ font-style:normal; font-size:11px; opacity:.9; font-weight:900; }



				.subgrid-2{ display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:12px; }

				@media(max-width:1100px){ .subgrid-2{ grid-template-columns:1fr; } }



				.qgrid{ display:grid;grid-template-columns:1fr 1fr;gap:12px; }

				@media(max-width:980px){ .qgrid{ grid-template-columns:1fr; } }



				.qbox{

					border:1px solid rgba(21,160,109,0.18);

					border-radius:18px;

					padding:12px 12px;

					background: radial-gradient(520px 240px at 16% 18%, rgba(21,160,109,0.10) 0%, rgba(21,160,109,0) 60%), rgba(255,255,255,0.96);

					box-shadow: 0 10px 26px rgba(10,59,52,0.08);

				}

				.qbox h4{

					margin:0 0 6px 0;

					font-size:12px;

					font-weight:950;

					letter-spacing:.04em;

					text-transform:uppercase;

					color:#0b2f2a;

				}

				.qtag{

					display:inline-flex;

					align-items:flex-start;

					justify-content:flex-start;



					white-space: normal;

					flex-wrap: wrap;

					max-width: 100%;



					overflow-wrap: anywhere;

					word-break: break-word;

					hyphens: auto;



					padding:6px 10px;

					border-radius:999px;

					font-size:11px;

					font-weight:950;

					line-height:1.25;



					border:1px solid rgba(10,59,52,0.10);

					background:#f8fafc;



					margin-right:6px;

					margin-bottom:6px;

				}

				.qtag strong{

					white-space: nowrap;

				}



				.comp-card{ border:1px solid #e5e7eb; border-radius:18px; padding:12px 12px; background:#fff; margin-bottom:10px; }

				.comp-head{ display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px; }

				.comp-title{ font-weight:950;color:#0b2f2a;font-size:13px; }

				.comp-meta{ font-size:11px;color:#64748b; }



				.radar-wrap{ border:1px solid #e5e7eb; border-radius:22px; padding:18px 18px 12px; background:#fff; min-height: 460px; }

				.radar-wrap canvas{ max-height: 340px; }



				.card{

					position:relative;

					background:#fff;

					border-radius:18px;

					border:1px solid rgba(21,160,109,0.18);

					box-shadow: 0 10px 24px rgba(10,59,52,0.10);

					padding:14px 16px;

					margin-bottom:12px;

					overflow:hidden;

				}

				.card:before{

					content:'';

					position:absolute;

					left:0;top:0;right:0;height:4px;

					background: var(--icon-grad);

				}

				.card h3{ margin:0 0 8px;font-size:14px;font-weight:950;color:#0b2f2a; }

				.mini{ font-size:12px;color:var(--muted);line-height:1.35; }



				.table{ width:100%;border-collapse:separate;border-spacing:0;font-size:12px; }

				.table th,.table td{ padding:10px 8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top; }

				.table th{

					font-size:11px;

					text-transform:uppercase;

					letter-spacing:.05em;

					color:#6b7280;

					background:#f9fafb;

					position: sticky; top: 0; z-index: 1;

				}

				.table tbody tr:hover td{ background: rgba(248,250,252,0.8); }

			</style>



			<?php

			// Build share URL (prefer share_token, otherwise participant_id)

			$share_url = '';

			if ( $current_share_token !== '' ) {

				$share_url = add_query_arg(

					array( 'share_token' => $current_share_token ),

					remove_query_arg( array( 'participant_id', 'token', 'rater_id', 'icon_debug', 'regen_share', 'revoke_share', 'share_nonce' ) )

				);

			}






			$nonce_share = ( $is_admin_share && $participant_id > 0 ) ? wp_create_nonce( 'icon_psy_traits_share_' . (int) $participant_id ) : '';

			$regen_url   = ( $is_admin_share && $participant_id > 0 )

				? add_query_arg(

					array(

						'participant_id' => $participant_id,

						'regen_share'    => 1,

						'share_nonce'    => $nonce_share,

					),

					remove_query_arg( array( 'icon_debug', 'regen_share', 'revoke_share', 'share_nonce' ) )

				)

				: '';



			$revoke_url  = ( $is_admin_share && $participant_id > 0 )

				? add_query_arg(

					array(

						'participant_id' => $participant_id,

						'revoke_share'   => 1,

						'share_nonce'    => $nonce_share,

					),

					remove_query_arg( array( 'icon_debug', 'regen_share', 'revoke_share', 'share_nonce' ) )

				)

				: '';

			?>



			<?php if ( $icon_debug ) : ?>

				<div style="background:#0b2f2a;color:#fff;border-radius:14px;padding:12px 14px;margin:0 0 12px 0;font-size:12px;">

					<div style="font-weight:900;letter-spacing:.06em;text-transform:uppercase;">ICON Debug — Branding</div>

					<div style="margin-top:6px;opacity:.9;">

						Helper loaded: <strong><?php echo ! empty( $branding_helper_loaded ) ? 'YES' : 'NO'; ?></strong><br>

						primary: <strong><?php echo esc_html( $brand_primary ); ?></strong> ·

						secondary: <strong><?php echo esc_html( $brand_secondary ); ?></strong><br>

						logo_url: <strong><?php echo esc_html( $brand_logo_url ? $brand_logo_url : '(none)' ); ?></strong>

					</div>

				</div>

			<?php endif; ?>



			<section class="icon-cover">

			<?php if ( ! empty( $brand_logo_url ) ) : ?>

				<div style="display:flex;justify-content:center;margin-bottom:8px;">

					<img src="<?php echo esc_url( $brand_logo_url ); ?>" alt="" style="max-height:54px;max-width:220px;object-fit:contain;" loading="lazy">

				</div>

			<?php endif; ?>

				<div class="icon-cover-inner" style="text-align:center;">

					<span class="icon-eyebrow" style="margin-left:auto;margin-right:auto;">ICON Insight Report</span>

					<h1>Pre &amp; Post<br><span>Self Assessment</span> Report</h1>

					<p class="icon-cover-lead">

						This report is generated from the participant’s survey responses and translates results into clear, practical insight.

						<?php echo $is_comparison ? 'It compares Baseline and Post submissions.' : 'It shows the most recent submission snapshot.'; ?>

					</p>

				</div>

			</section>



			<div class="shell" style="margin-bottom:12px;">

				<div class="hero">

					<div class="hero-top">

						<div>

							<h2>ICON Pre &amp; Post Assessment Report</h2>

							<p>

								<?php if ( $is_comparison ) : ?>

									Self Assessment comparison: <strong>Baseline</strong> vs <strong>Post</strong>.

								<?php else : ?>

									Self Assessment snapshot based on the most recent submission.

								<?php endif; ?>

							</p>



							<div class="chip-row">

								<span class="chip">Participant: <?php echo esc_html( $participant_name ); ?></span>

								<?php if ( $participant_role ) : ?><span class="chip-muted">Role: <?php echo esc_html( $participant_role ); ?></span><?php endif; ?>

								<?php if ( $project_name ) : ?><span class="chip">Project: <?php echo esc_html( $project_name ); ?></span><?php endif; ?>

								<?php if ( $client_name ) : ?><span class="chip-muted">Client: <?php echo esc_html( $client_name ); ?></span><?php endif; ?>

								<?php if ( $is_comparison ) : ?>

									<?php if ( $baseline_at ) : ?><span class="chip-muted">Baseline submitted: <?php echo esc_html( $baseline_at ); ?></span><?php endif; ?>

									<?php if ( $post_at ) : ?><span class="chip-muted">Post submitted: <?php echo esc_html( $post_at ); ?></span><?php endif; ?>

								<?php else : ?>

									<?php if ( $baseline_at ) : ?><span class="chip-muted">Submitted: <?php echo esc_html( $baseline_at ); ?></span><?php endif; ?>

								<?php endif; ?>

								<?php if ( $ai_draft_id > 0 ) : ?><span class="chip-muted">Framework draft ID: <?php echo (int) $ai_draft_id; ?></span><?php endif; ?>

							</div>



							<?php if ( $is_admin_share && $share_url !== '' ) : ?>

								<div class="share-wrap" aria-label="Share report link">

									<div class="share-title">Share link (email)</div>

									<div class="share-row">

										<input id="iconShareUrl" type="text" readonly value="<?php echo esc_attr( $share_url ); ?>" onclick="this.select();">

										<button class="share-btn" type="button" id="iconCopyShare">Copy link</button>

										<a class="share-btn alt" id="iconEmailShare" href="#">Email link</a>

										<?php if ( $regen_url ) : ?><a class="share-btn" href="<?php echo esc_url( $regen_url ); ?>">Regenerate</a><?php endif; ?>

										<?php if ( $revoke_url ) : ?><a class="share-btn danger" href="<?php echo esc_url( $revoke_url ); ?>">Revoke</a><?php endif; ?>

									</div>

									<div class="share-note">

										<?php if ( $share_actions_note ) : ?><strong><?php echo esc_html( $share_actions_note ); ?></strong><br><?php endif; ?>

										This link uses a token (no participant ID). If you regenerate, the old link stops working.

									</div>

								</div>

							<?php endif; ?>



							



						</div>

					</div>



					<div class="score-grid" aria-label="Headline score tiles">

						<div class="score-card">

							<div class="score-k">Baseline average</div>

							<div class="score-v">

								<div class="score-num"><?php echo number_format_i18n( (float) $baseline_overall, 2 ); ?></div>

								<div class="score-sub">out of 7</div>

							</div>

							<div class="scorebar"><span style="width:<?php echo esc_attr( min( 100, max( 0, ( (float) $baseline_overall / 7 ) * 100 ) ) ); ?>%;"></span></div>

						</div>



						<div class="score-card">

							<div class="score-k"><?php echo $is_comparison ? 'Post average' : 'Competencies'; ?></div>

							<div class="score-v">

								<div class="score-num"><?php echo $is_comparison ? number_format_i18n( (float) $post_overall, 2 ) : (int) count( $base_map ); ?></div>

								<div class="score-sub"><?php echo $is_comparison ? 'out of 7' : 'items'; ?></div>

							</div>

							<div class="scorebar"><span style="width:<?php echo $is_comparison ? esc_attr( min( 100, max( 0, ( ( (float) $post_overall ) / 7 ) * 100 ) ) ) : '55'; ?>%;"></span></div>

						</div>



						<div class="score-card">

							<div class="score-k"><?php echo $is_comparison ? 'Overall movement' : 'Snapshot'; ?></div>

							<div class="score-v">

								<?php if ( $is_comparison ) : ?>

									<?php $d = (float) $overall_delta; ?>

									<div class="score-num" style="color:<?php echo $d >= 0 ? '#065f46' : '#7f1d1d'; ?>;"><?php echo esc_html( ( $d >= 0 ? '+' : '' ) . number_format_i18n( $d, 2 ) ); ?></div>

									<div class="score-sub">Post minus Baseline</div>

								<?php else : ?>

									<div class="score-num">Latest</div>

									<div class="score-sub">submission</div>

								<?php endif; ?>

							</div>

							<div class="scorebar"><span style="width:<?php echo $is_comparison ? esc_attr( min( 100, max( 0, ( abs( (float) $overall_delta ) / 2 ) * 100 ) ) ) : '60'; ?>%;"></span></div>

						</div>



						<div class="score-card">

							<div class="score-k"><?php echo $is_comparison ? 'Stability signal' : 'Assessment'; ?></div>

							<div class="score-v">

								<?php if ( $is_comparison ) : ?>

									<div class="score-num"><?php echo (int) $stability_improved; ?></div>

									<div class="score-sub">improved under pressure</div>

								<?php else : ?>

									<div class="score-num">Self</div>

									<div class="score-sub">assessment</div>

								<?php endif; ?>

							</div>

							<div class="scorebar"><span style="width:<?php echo $is_comparison ? esc_attr( min( 100, max( 0, ( ( (int) $stability_improved ) / max( 1, count( $base_map ) ) ) * 100 ) ) ) : '50'; ?>%;"></span></div>

						</div>

					</div>



				</div>

			</div>



			<div class="icon-wow-card" style="padding:10px 12px;">

				<div class="mini" style="font-weight:950;color:#0b2f2a;margin-bottom:6px;">Contents</div>

				<div style="display:flex;flex-wrap:wrap;gap:8px;">

					<a class="pill" href="#sec1" style="text-decoration:none;">1 Quick Scan</a>

					<a class="pill" href="#sec2" style="text-decoration:none;">2 Key Takeaways</a>

					<a class="pill" href="#sec3" style="text-decoration:none;">3 Radar</a>

					<a class="pill" href="#sec4" style="text-decoration:none;">4 Trend Map</a>

					<a class="pill" href="#sec5" style="text-decoration:none;">5 Heatmaps</a>

					<a class="pill" href="#sec6" style="text-decoration:none;">6 Tables</a>

					<a class="pill" href="#sec7" style="text-decoration:none;">7 Summaries</a>

					<a class="pill" href="#sec8" style="text-decoration:none;">8 Model</a>

				</div>

			</div>



			<div class="grid-2">

				<div class="icon-wow-card" id="sec1">

					<h3>Quick Scan</h3>



					<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">

						<?php $b_base = $band( $baseline_overall ); ?>

						<span class="pill" style="border-color:<?php echo esc_attr( $b_base[3] ); ?>;background:<?php echo esc_attr( $b_base[2] ); ?>;color:<?php echo esc_attr( $b_base[1] ); ?>;">

							Baseline avg: <?php echo number_format_i18n( $baseline_overall, 2 ); ?> / 7 <span style="opacity:.9;">(<?php echo esc_html( $b_base[0] ); ?>)</span>

						</span>



						<?php if ( $is_comparison ) : ?>

							<?php $b_post = $band( (float) $post_overall ); ?>

							<span class="pill" style="border-color:<?php echo esc_attr( $b_post[3] ); ?>;background:<?php echo esc_attr( $b_post[2] ); ?>;color:<?php echo esc_attr( $b_post[1] ); ?>;">

								Post avg: <?php echo number_format_i18n( (float) $post_overall, 2 ); ?> / 7 <span style="opacity:.9;">(<?php echo esc_html( $b_post[0] ); ?>)</span>

							</span>



							<?php

							$delta_overall     = (float) $overall_delta;

							$delta_overall_txt = ( $delta_overall >= 0 ? '+' : '' ) . number_format_i18n( $delta_overall, 2 );

							$delta_overall_cls = $delta_overall >= 0 ? 'delta-up' : 'delta-down';

							?>



							<span class="pill">Overall delta: <span class="<?php echo esc_attr( $delta_overall_cls ); ?>"><?php echo esc_html( $delta_overall_txt ); ?></span></span>

							<span class="pill">Stability improved: <?php echo (int) $stability_improved; ?> · Reduced: <?php echo (int) $stability_worsened; ?></span>

						<?php endif; ?>



						<span class="pill">Competencies: <?php echo (int) count( $base_map ); ?></span>

					</div>



					<div class="mini">

						<strong>Delta explained:</strong> Delta is calculated as <strong>Post minus Baseline</strong>. Positive delta = improvement, negative delta = decline, near zero = stable.

					</div>



					<div class="subgrid-2" style="margin-top:10px;">

						<div class="qbox">

							<h4>Top strengths</h4>

							<div class="mini">Highest baseline averages.</div>

							<?php foreach ( $top_strengths as $s ) : ?>

								<span class="qtag"><?php echo esc_html( $s['name'] ); ?> · <strong><?php echo number_format_i18n( (float) $s['avg'], 2 ); ?></strong></span>

							<?php endforeach; ?>

						</div>



						<div class="qbox">

							<h4>Top priorities</h4>

							<div class="mini">Lowest baseline averages.</div>

							<?php foreach ( $bottom_priorities as $p ) : ?>

								<span class="qtag"><?php echo esc_html( $p['name'] ); ?> · <strong><?php echo number_format_i18n( (float) $p['avg'], 2 ); ?></strong></span>

							<?php endforeach; ?>

						</div>

					</div>



					<?php if ( $is_comparison ) : ?>

						<div class="subgrid-2" style="margin-top:12px;">

							<div class="qbox">

								<h4>Most improved</h4>

								<div class="mini">Largest positive deltas (Post minus Baseline).</div>

								<?php foreach ( array_slice( $top_improve, 0, 3 ) as $d ) : ?>

									<span class="qtag"><?php echo esc_html( $d['name'] ); ?> · <strong class="delta-up">+<?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong></span>

								<?php endforeach; ?>

								<?php if ( empty( $top_improve ) ) : ?><div class="mini">Not enough matched items.</div><?php endif; ?>

							</div>



							<div class="qbox">

								<h4>Largest declines</h4>

								<div class="mini">Largest negative deltas (Post minus Baseline).</div>

								<?php foreach ( array_slice( $top_drop, 0, 3 ) as $d ) : ?>

									<span class="qtag"><?php echo esc_html( $d['name'] ); ?> · <strong class="delta-down"><?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong></span>

								<?php endforeach; ?>

								<?php if ( empty( $top_drop ) ) : ?><div class="mini">Not enough matched items.</div><?php endif; ?>

							</div>

						</div>

					<?php endif; ?>

				</div>



				<div class="icon-wow-card" id="sec2">

					<h3>Key Takeaways</h3>

					<ul class="klist">

						<?php foreach ( (array) $key_takeaways as $kt ) : ?>

							<li><?php echo esc_html( $kt ); ?></li>

						<?php endforeach; ?>

					</ul>



					<div class="mini" style="margin-top:10px;">

						Each competency is rated across three contexts: <strong>Day to day</strong>, <strong>Under pressure</strong>, and <strong>Quality standard</strong>.

					</div>



					<div class="mini" style="margin-top:8px;">

						Consistency uses variance <strong>|Q2 - Q1|</strong>. 0 to 1 stable, 2 variable, 3+ volatile.

					</div>

				</div>

			</div>



			<div class="grid-2" id="sec3">

				<div class="icon-wow-card">

					<h3>Self radar</h3>



					<div class="radar-wrap">

						<canvas id="iconSelfRadar" height="420" aria-label="Self radar chart" role="img"></canvas>

						<div id="iconRadarFallback" class="mini" style="display:none;margin-top:8px;">

							Radar chart could not load. The heatmap and tables below still show all scores.

						</div>

					</div>



					<div class="mini" style="margin-top:8px;">

						Radar shows up to <?php echo (int) $radar_count; ?> competencies (highest baseline averages) to keep it readable.

						<?php if ( $is_comparison ) : ?> The chart shows both Baseline and Post.<?php endif; ?>

					</div>

				</div>



				<div class="icon-wow-card">

					<h3>How to read the scores</h3>

					<ul class="klist">

						<li><strong>Day to day</strong> is how consistently this shows up in normal work.</li>

						<li><strong>Under pressure</strong> is how stable it is when time, complexity, or ambiguity increases.</li>

						<li><strong>Quality standard</strong> is whether your approach is repeatable and consistent.</li>

					</ul>



					<div class="mini" style="margin-top:10px;">

						<strong>Interpreting change over time</strong><br>

						<ul class="klist">

							<li><strong>0.35 or more</strong> = meaningful behavioural change</li>

							<li><strong>0.15 to 0.34</strong> = directional movement</li>

							<li><strong>Less than 0.15</strong> = broadly stable</li>

						</ul>

					</div>

				</div>

			</div>



			<?php if ( $is_comparison ) : ?>

				<div class="icon-wow-card" id="sec4">

					<h3>Trend Map (Baseline vs Delta)</h3>

					<div class="mini" style="margin-bottom:10px;">This 2×2 view uses baseline strength (high vs low) and change (up vs down).</div>



					<div class="qgrid">

						<div class="qbox">

							<h4>Grow &amp; protect</h4>

							<div class="mini">High baseline and improving.</div>

							<?php foreach ( array_slice( $trend_quadrants['grow_protect'], 0, 10 ) as $d ) : ?>

								<span class="qtag"><?php echo esc_html( $d['name'] ); ?> · <strong class="delta-up">+<?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong></span>

							<?php endforeach; ?>

							<?php if ( empty( $trend_quadrants['grow_protect'] ) ) : ?><div class="mini">No items in this quadrant.</div><?php endif; ?>

						</div>



						<div class="qbox">

							<h4>Strength at risk</h4>

							<div class="mini">High baseline but declining.</div>

							<?php foreach ( array_slice( $trend_quadrants['strength_risk'], 0, 10 ) as $d ) : ?>

								<span class="qtag"><?php echo esc_html( $d['name'] ); ?> · <strong class="delta-down"><?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong></span>

							<?php endforeach; ?>

							<?php if ( empty( $trend_quadrants['strength_risk'] ) ) : ?><div class="mini">No items in this quadrant.</div><?php endif; ?>

						</div>



						<div class="qbox">

							<h4>Breakthrough</h4>

							<div class="mini">Lower baseline and improving.</div>

							<?php foreach ( array_slice( $trend_quadrants['breakthrough'], 0, 10 ) as $d ) : ?>

								<span class="qtag"><?php echo esc_html( $d['name'] ); ?> · <strong class="delta-up">+<?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong></span>

							<?php endforeach; ?>

							<?php if ( empty( $trend_quadrants['breakthrough'] ) ) : ?><div class="mini">No items in this quadrant.</div><?php endif; ?>

						</div>



						<div class="qbox">

							<h4>Priority focus</h4>

							<div class="mini">Lower baseline and declining.</div>

							<?php foreach ( array_slice( $trend_quadrants['priority'], 0, 10 ) as $d ) : ?>

								<span class="qtag"><?php echo esc_html( $d['name'] ); ?> · <strong class="delta-down"><?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong></span>

							<?php endforeach; ?>

							<?php if ( empty( $trend_quadrants['priority'] ) ) : ?><div class="mini">No items in this quadrant.</div><?php endif; ?>

						</div>

					</div>



					<div class="mini" style="margin-top:10px;">

						Baseline “high” uses <?php echo number_format_i18n( (float) $trend_base_threshold, 1 ); ?> / 7 as the boundary.

					</div>

				</div>

			<?php endif; ?>



			<div class="card" id="sec5">

				<h3>Heatmaps</h3>

				<div class="mini" style="margin-bottom:10px;">

					<?php if ( $is_comparison ) : ?>

						Baseline and Post heatmaps are shown side by side. A Delta heatmap shows Post minus Baseline for Q1/Q2/Q3.

					<?php else : ?>

						Heatmap shows the latest self assessment.

					<?php endif; ?>

				</div>



				<?php if ( $is_comparison ) : ?>

					<div class="subgrid-2">

						<div class="card" style="margin:0;">

							<h3 style="margin-bottom:10px;">Baseline heatmap</h3>

							<div class="heat-wrap">

								<div class="heat">

									<div class="hcell">Competency</div>

									<div class="hcell">Day to day</div>

									<div class="hcell">Under pressure</div>

									<div class="hcell">Quality standard</div>



									<?php foreach ( $items as $t ) : ?>

										<?php

										$key = $t['key'];

										if ( empty( $base_map[ $key ] ) ) continue;

										$b  = $base_map[ $key ];

										$c1 = $score_color( $b['q1'] );

										$c2 = $score_color( $b['q2'] );

										$c3 = $score_color( $b['q3'] );

										?>

										<div class="name">

											<div class="t"><?php echo esc_html( $b['name'] ); ?></div>

											<div class="m">Module: <?php echo esc_html( $b['module'] ); ?> · Conf: <?php echo (int) $b['confidence']; ?>/5</div>

										</div>

										<div class="scorecell" style="background:<?php echo esc_attr( $c1[0] ); ?>;color:<?php echo esc_attr( $c1[1] ); ?>;">

											<span><?php echo (int) $b['q1']; ?>/7</span><em>Q1</em>

										</div>

										<div class="scorecell" style="background:<?php echo esc_attr( $c2[0] ); ?>;color:<?php echo esc_attr( $c2[1] ); ?>;">

											<span><?php echo (int) $b['q2']; ?>/7</span><em>Q2</em>

										</div>

										<div class="scorecell" style="background:<?php echo esc_attr( $c3[0] ); ?>;color:<?php echo esc_attr( $c3[1] ); ?>;">

											<span><?php echo (int) $b['q3']; ?>/7</span><em>Q3</em>

										</div>

									<?php endforeach; ?>

								</div>

							</div>

						</div>



						<div class="card" style="margin:0;">

							<h3 style="margin-bottom:10px;">Post heatmap</h3>

							<div class="heat-wrap">

								<div class="heat">

									<div class="hcell">Competency</div>

									<div class="hcell">Day to day</div>

									<div class="hcell">Under pressure</div>

									<div class="hcell">Quality standard</div>



									<?php foreach ( $items as $t ) : ?>

										<?php

										$key = $t['key'];

										if ( empty( $post_map[ $key ] ) ) continue;

										$p  = $post_map[ $key ];

										$c1 = $score_color( $p['q1'] );

										$c2 = $score_color( $p['q2'] );

										$c3 = $score_color( $p['q3'] );

										?>

										<div class="name">

											<div class="t"><?php echo esc_html( $p['name'] ); ?></div>

											<div class="m">Module: <?php echo esc_html( $p['module'] ); ?> · Conf: <?php echo (int) $p['confidence']; ?>/5</div>

										</div>

										<div class="scorecell" style="background:<?php echo esc_attr( $c1[0] ); ?>;color:<?php echo esc_attr( $c1[1] ); ?>;">

											<span><?php echo (int) $p['q1']; ?>/7</span><em>Q1</em>

										</div>

										<div class="scorecell" style="background:<?php echo esc_attr( $c2[0] ); ?>;color:<?php echo esc_attr( $c2[1] ); ?>;">

											<span><?php echo (int) $p['q2']; ?>/7</span><em>Q2</em>

										</div>

										<div class="scorecell" style="background:<?php echo esc_attr( $c3[0] ); ?>;color:<?php echo esc_attr( $c3[1] ); ?>;">

											<span><?php echo (int) $p['q3']; ?>/7</span><em>Q3</em>

										</div>

									<?php endforeach; ?>

								</div>

							</div>



							<div class="mini" style="margin-top:10px;">Delta is shown in the tables and the Delta heatmap below.</div>

						</div>

					</div>



					<div class="card" style="margin-top:12px;">

						<h3 style="margin-bottom:10px;">Delta heatmap (Post minus Baseline)</h3>

						<div class="mini" style="margin-bottom:10px;">Positive changes are shaded toward green, negative toward red.</div>



						<div class="heat-wrap">

							<div class="heat">

								<div class="hcell">Competency</div>

								<div class="hcell">Delta Q1</div>

								<div class="hcell">Delta Q2</div>

								<div class="hcell">Delta Q3</div>



								<?php foreach ( $items as $t ) : ?>

									<?php

									$key = $t['key'];

									if ( empty( $base_map[ $key ] ) || empty( $post_map[ $key ] ) ) continue;

									$b = $base_map[ $key ];

									$p = $post_map[ $key ];



									$d1 = (float) $p['q1'] - (float) $b['q1'];

									$d2 = (float) $p['q2'] - (float) $b['q2'];

									$d3 = (float) $p['q3'] - (float) $b['q3'];



									$dc1 = $delta_color( $d1 );

									$dc2 = $delta_color( $d2 );

									$dc3 = $delta_color( $d3 );



									$txt1 = ( $d1 >= 0 ? '+' : '' ) . number_format_i18n( (float) $d1, 0 );

									$txt2 = ( $d2 >= 0 ? '+' : '' ) . number_format_i18n( (float) $d2, 0 );

									$txt3 = ( $d3 >= 0 ? '+' : '' ) . number_format_i18n( (float) $d3, 0 );

									?>

									<div class="name">

										<div class="t"><?php echo esc_html( $b['name'] ); ?></div>

										<div class="m">Module: <?php echo esc_html( $b['module'] ); ?></div>

									</div>



									<div class="scorecell" style="background:<?php echo esc_attr( $dc1[0] ); ?>;color:<?php echo esc_attr( $dc1[1] ); ?>;">

										<span><?php echo esc_html( $txt1 ); ?></span><em>Q1</em>

									</div>

									<div class="scorecell" style="background:<?php echo esc_attr( $dc2[0] ); ?>;color:<?php echo esc_attr( $dc2[1] ); ?>;">

										<span><?php echo esc_html( $txt2 ); ?></span><em>Q2</em>

									</div>

									<div class="scorecell" style="background:<?php echo esc_attr( $dc3[0] ); ?>;color:<?php echo esc_attr( $dc3[1] ); ?>;">

										<span><?php echo esc_html( $txt3 ); ?></span><em>Q3</em>

									</div>

								<?php endforeach; ?>



							</div>

						</div>

					</div>



				<?php else : ?>



					<div class="heat-wrap">

						<div class="heat">

							<div class="hcell">Competency</div>

							<div class="hcell">Day to day</div>

							<div class="hcell">Under pressure</div>

							<div class="hcell">Quality standard</div>



							<?php foreach ( $items as $t ) : ?>

								<?php

								$key = $t['key'];

								if ( empty( $base_map[ $key ] ) ) continue;

								$b  = $base_map[ $key ];

								$c1 = $score_color( $b['q1'] );

								$c2 = $score_color( $b['q2'] );

								$c3 = $score_color( $b['q3'] );

								?>

								<div class="name">

									<div class="t"><?php echo esc_html( $b['name'] ); ?></div>

									<div class="m">Module: <?php echo esc_html( $b['module'] ); ?> · Conf: <?php echo (int) $b['confidence']; ?>/5</div>

								</div>



								<div class="scorecell" style="background:<?php echo esc_attr( $c1[0] ); ?>;color:<?php echo esc_attr( $c1[1] ); ?>;">

									<span><?php echo (int) $b['q1']; ?>/7</span><em>Q1</em>

								</div>

								<div class="scorecell" style="background:<?php echo esc_attr( $c2[0] ); ?>;color:<?php echo esc_attr( $c2[1] ); ?>;">

									<span><?php echo (int) $b['q2']; ?>/7</span><em>Q2</em>

								</div>

								<div class="scorecell" style="background:<?php echo esc_attr( $c3[0] ); ?>;color:<?php echo esc_attr( $c3[1] ); ?>;">

									<span><?php echo (int) $b['q3']; ?>/7</span><em>Q3</em>

								</div>

							<?php endforeach; ?>



						</div>

					</div>



				<?php endif; ?>

			</div>



			<div class="card" id="sec6">

				<h3><?php echo $is_comparison ? 'Comparison table and trends' : 'Competency results table'; ?></h3>

				<div class="mini" style="margin-bottom:10px;">

					<?php if ( $is_comparison ) : ?>

						Delta is Post minus Baseline. Consistency uses variance <strong>|Q2 - Q1|</strong>.

					<?php else : ?>

						Table shows average plus confidence. Consistency uses variance <strong>|Q2 - Q1|</strong>.

					<?php endif; ?>

				</div>



				<div style="overflow:auto;">

					<table class="table">

						<thead>

							<tr>

								<th class="col-competency">Competency</th>

								<?php if ( $is_comparison ) : ?>

									<th>Baseline Q1/Q2/Q3</th>

									<th>Post Q1/Q2/Q3</th>

									<th>Baseline avg</th>

									<th>Post avg</th>

									<th>Delta</th>

									<th>Consistency (|Q2-Q1|)</th>

									<th>Confidence</th>

								<?php else : ?>

									<th>Q1</th><th>Q2</th><th>Q3</th><th>Avg</th><th>Consistency</th><th>Confidence</th>

								<?php endif; ?>

							</tr>

						</thead>



						<tbody>

							<?php foreach ( $items as $t ) : ?>

								<?php

								$key = $t['key'];

								if ( empty( $base_map[ $key ] ) ) continue;



								$base      = $base_map[ $key ];

								$avg_base  = (float) $base['avg'];

								$base_gap  = abs( (int) $base['q2'] - (int) $base['q1'] );

								$base_stab = $stability_label( $base_gap );



								$post     = ( $is_comparison && ! empty( $post_map[ $key ] ) ) ? $post_map[ $key ] : null;

								$avg_post = $post ? (float) $post['avg'] : null;



								$delta_txt = '—';

								$delta_cls = '';

								if ( $is_comparison && $post ) {

									$delta       = $avg_post - $avg_base;

									$delta_label = $delta_classification( $delta );

									$delta_txt   = ( $delta >= 0 ? '+' : '' ) . number_format_i18n( $delta, 2 ) . ' (' . $delta_label . ')';

									$delta_cls   = $delta >= 0 ? 'delta-up' : 'delta-down';

								}

								?>

								<tr>

									<td class="col-competency">

										<div style="font-weight:950;color:#0b2f2a;"><?php echo esc_html( $base['name'] ); ?></div>

										<div class="mini">Module: <?php echo esc_html( $base['module'] ); ?></div>

									</td>



									<?php if ( $is_comparison ) : ?>

										<td><?php echo (int) $base['q1']; ?> / <?php echo (int) $base['q2']; ?> / <?php echo (int) $base['q3']; ?></td>

										<td><?php echo $post ? ( (int) $post['q1'] . ' / ' . (int) $post['q2'] . ' / ' . (int) $post['q3'] ) : '—'; ?></td>

										<td><?php echo number_format_i18n( $avg_base, 2 ); ?></td>

										<td><?php echo $post ? number_format_i18n( $avg_post, 2 ) : '—'; ?></td>

										<td><span class="<?php echo esc_attr( $delta_cls ); ?>"><?php echo esc_html( $delta_txt ); ?></span></td>

										<td><?php echo esc_html( $base_stab[0] ); ?> (<?php echo (int) $base_gap; ?>)</td>

										<td><?php echo (int) $base['confidence']; ?>/5<?php echo $post ? ' → ' . (int) $post['confidence'] . '/5' : ''; ?></td>

									<?php else : ?>

										<td><?php echo (int) $base['q1']; ?></td>

										<td><?php echo (int) $base['q2']; ?></td>

										<td><?php echo (int) $base['q3']; ?></td>

										<td><?php echo number_format_i18n( $avg_base, 2 ); ?></td>

										<td><?php echo esc_html( $base_stab[0] ); ?> (<?php echo (int) $base_gap; ?>)</td>

										<td><?php echo (int) $base['confidence']; ?>/5</td>

									<?php endif; ?>

								</tr>

							<?php endforeach; ?>

						</tbody>



					</table>

				</div>

			</div>



			<div class="card" id="sec7">

				<h3>Competency by competency summary</h3>

				<div class="mini" style="margin-bottom:10px;">

					<?php echo $is_comparison ? 'Each summary highlights what changed, plus a consistency signal.' : 'Each summary highlights the pattern across contexts and a practical next step.'; ?>

				</div>



				<?php foreach ( $items as $t ) : ?>

					<?php

					$key = $t['key'];

					if ( empty( $base_map[ $key ] ) ) continue;



					$base    = $base_map[ $key ];

					$post    = ( $is_comparison && ! empty( $post_map[ $key ] ) ) ? $post_map[ $key ] : null;

					$summary = $make_summary( $base, $post );

					$b       = $band( $base['avg'] );

					$anchor  = 'comp_' . sanitize_title( (string) $summary['headline'] );

					?>

					<div class="comp-card" id="<?php echo esc_attr( $anchor ); ?>">

						<div class="comp-head">

							<div>

								<div class="comp-title"><?php echo esc_html( $summary['headline'] ); ?></div>

								<div class="comp-meta">Module: <?php echo esc_html( $base['module'] ); ?></div>

							</div>

							<span class="pill" style="border-color:<?php echo esc_attr( $b[3] ); ?>;background:<?php echo esc_attr( $b[2] ); ?>;color:<?php echo esc_attr( $b[1] ); ?>;">

								<?php echo esc_html( $b[0] ); ?>

							</span>

						</div>



						<ul class="klist">

							<?php foreach ( (array) $summary['bullets'] as $bl ) : ?>

								<li><?php echo esc_html( $bl ); ?></li>

							<?php endforeach; ?>

						</ul>

					</div>

				<?php endforeach; ?>

			</div>



			<div class="card" id="sec8">

				<h3>Competency Model used</h3>

				<div class="mini" style="margin-bottom:10px;">This report displays the competencies found in the submitted framework for this assessment.</div>



				<?php foreach ( $modules as $m => $names ) : ?>

					<div style="margin-bottom:10px;">

						<div class="pill" style="margin-bottom:6px;"><?php echo esc_html( $m ); ?></div>

						<ul class="klist" style="margin-top:0;">

							<?php

							$names2 = array_values( array_unique( array_filter( $names ) ) );

							sort( $names2 );

							foreach ( $names2 as $nm ) :

							?>

								<li><?php echo esc_html( $nm ); ?></li>

							<?php endforeach; ?>

						</ul>

					</div>

				<?php endforeach; ?>

			</div>



		</div><!-- /.icon-report-wrap -->



		<?php

		$radar_labels_json = wp_json_encode( array_values( $radar_labels ) );

		$radar_base_json   = wp_json_encode( array_values( $radar_base_values ) );

		$radar_post_json   = wp_json_encode( array_values( $radar_post_values ) );

		$is_comp_json      = wp_json_encode( (bool) $is_comparison );



		// Use resolved branding colours for Chart.js too

		$js_brand_primary   = $brand_primary;

		$js_brand_secondary = $brand_secondary;

		?>

		<script>

		(function(){

			var labels = <?php echo $radar_labels_json; ?>;

			var baseValues = <?php echo $radar_base_json; ?>;

			var postValues = <?php echo $radar_post_json; ?>;

			var isComparison = <?php echo $is_comp_json; ?>;



			function showFallback(){

				var fb = document.getElementById('iconRadarFallback');

				if (fb) fb.style.display = 'block';

			}



			function loadChartJs(cb){

				if (window.Chart) return cb();

				var s = document.createElement('script');

				s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';

				s.async = true;

				s.onload = cb;

				s.onerror = function(){ showFallback(); };

				document.head.appendChild(s);

			}



			// ✅ wrap long labels to 2 lines max (Chart.js supports multiline arrays)

			function wrapLabel(label, maxChars){

				label = (label || '').toString().replace(/\s+/g,' ').trim();

				if (!label) return '';

				maxChars = maxChars || 14;



				var words = label.split(' ');

				var lines = [];

				var line = '';



				for (var i=0;i<words.length;i++){

					var w = words[i];

					var next = line ? (line + ' ' + w) : w;



					if (next.length <= maxChars){

						line = next;

					} else {

						if (line) lines.push(line);

						line = w;

					}



					if (lines.length === 2) break;

				}



				if (lines.length < 2 && line) lines.push(line);



				// If truncated, add ellipsis to last line

				var original = words.join(' ');

				var joined = lines.join(' ');

				if (original.length > joined.length) {

					lines[lines.length - 1] = (lines[lines.length - 1] + '…').replace(/……$/, '…');

				}



				return lines;

			}



			function hexToRgba(hex, a){

				if(!hex) return 'rgba(0,0,0,'+(a||1)+')';

				hex = (''+hex).replace('#','').trim();

				if(hex.length!==6) return 'rgba(0,0,0,'+(a||1)+')';

				var r = parseInt(hex.substring(0,2),16);

				var g = parseInt(hex.substring(2,4),16);

				var b = parseInt(hex.substring(4,6),16);

				return 'rgba('+r+','+g+','+b+','+(typeof a==='number'?a:1)+')';

			}



			// Share buttons (admin only)

			(function(){

				var inp = document.getElementById('iconShareUrl');

				var btn = document.getElementById('iconCopyShare');

				var email = document.getElementById('iconEmailShare');



				function getUrl(){

					return inp ? (inp.value || '').trim() : '';

				}



				if (email){

					var url = getUrl();

					var subj = 'ICON Traits Report';

					var body = 'Here is the report link:\\n\\n' + url + '\\n\\n';

					email.href = 'mailto:?subject=' + encodeURIComponent(subj) + '&body=' + encodeURIComponent(body);

				}



				if (btn && inp){

					btn.addEventListener('click', function(){

						var url = getUrl();

						if (!url) return;



						function done(ok){

							btn.textContent = ok ? 'Copied' : 'Copy failed';

							setTimeout(function(){ btn.textContent = 'Copy link'; }, 1200);

						}



						if (navigator.clipboard && navigator.clipboard.writeText){

							navigator.clipboard.writeText(url).then(function(){ done(true); }).catch(function(){ done(false); });

						} else {

							inp.focus(); inp.select();

							try {

								var ok = document.execCommand('copy');

								done(!!ok);

							} catch(e){

								done(false);

							}

						}

					});

				}

			})();



			loadChartJs(function(){

				if (!window.Chart) return showFallback();

				var el = document.getElementById('iconSelfRadar');

				if (!el) return;

				var ctx = el.getContext('2d');



				try{

					var brandPrimary = <?php echo wp_json_encode( $js_brand_primary ); ?>;

					var brandSecondary = <?php echo wp_json_encode( $js_brand_secondary ); ?>;



					var green = hexToRgba(brandPrimary, 1);

					var greenFill = hexToRgba(brandPrimary, 0.18);

					var blue = hexToRgba(brandSecondary, 1);

					var blueFill = hexToRgba(brandSecondary, 0.16);



					var datasets = [{

						label: 'Baseline',

						data: baseValues,

						fill: true,

						borderColor: green,

						backgroundColor: greenFill,

						pointBackgroundColor: green,

						pointBorderColor: '#ffffff',

						pointBorderWidth: 2,

						borderWidth: 2

					}];



					if (isComparison) {

						datasets.push({

							label: 'Post',

							data: postValues,

							fill: true,

							borderColor: blue,

							backgroundColor: blueFill,

							pointBackgroundColor: blue,

							pointBorderColor: '#ffffff',

							pointBorderWidth: 2,

							borderWidth: 2

						});

					}



					new Chart(ctx, {

						type: 'radar',

						data: { labels: labels, datasets: datasets },

						options: {

							responsive: true,

							maintainAspectRatio: false,

							// ✅ extra breathing room so labels don’t clip

							layout: { padding: 18 },

							plugins: {

								legend: {

									display: !!isComparison,

									labels: { font: { size: 12 }, padding: 16 }

								}

							},

							scales: {

								r: {

									min: 0,

									max: 7,

									ticks: { stepSize: 1, backdropColor: 'transparent', font: { size: 11 } },

									// ✅ wrap + shrink labels

									pointLabels: {

										padding: 10,

										font: { size: 10 },

										callback: function(label){

											return wrapLabel(label, 14);

										}

									}

								}

							}

						}

					});

				}catch(e){

					showFallback();

				}

			});

		})();

		</script>

		<?php



		return (string) ob_get_clean();

	}

}



add_shortcode( 'icon_psy_traits_report', 'icon_psy_traits_report' );


					color:#0b2f2a;
					margin:0 0 6px 0;
					text-align:center;
				}
				.share-row{
					display:flex;
					gap:8px;
					flex-wrap:wrap;
					align-items:center;
					justify-content:center;
				}
				.share-row input{
					width:min(720px, 100%);
					padding:10px 12px;
					border-radius:12px;
					border:1px solid rgba(10,59,52,0.16);
					background:#f8fafc;
					font-size:12px;
					color:#0b2f2a;
				}
				.share-btn{
					display:inline-flex;
					align-items:center;
					justify-content:center;
					gap:8px;
					padding:10px 12px;
					border-radius:12px;
					border:1px solid rgba(21,160,109,.28);
					background: rgba(236,253,245,0.92);
					color:#0b2f2a;
					font-size:12px;
					font-weight:950;
					cursor:pointer;
					user-select:none;
					text-decoration:none;
				}
				.share-btn.alt{
					border-color: rgba(20,164,207,.22);
					background: rgba(239,246,255,0.92);
					color:#1e3a8a;
				}
				.share-btn.danger{
					border-color: rgba(127,29,29,.22);
					background: rgba(254,242,242,0.92);
					color:#7f1d1d;
				}
				.share-note{
					margin-top:8px;
					font-size:12px;
					color: var(--muted);
					text-align:center;
					line-height:1.35;
				}

				.score-grid{ display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:10px; margin-top: 10px; }
				@media(max-width:1100px){ .score-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
				@media(max-width:620px){ .score-grid{ grid-template-columns: 1fr; } }

				.score-card{
					border-radius: 18px;
					padding: 12px 12px;
					border:1px solid rgba(10,59,52,0.10);
					background:#fff;
					box-shadow: 0 10px 26px rgba(10,59,52,0.08);
				}
				.score-k{
					font-size:11px;
					text-transform:uppercase;
					letter-spacing:.06em;
					color:#64748b;
					font-weight:950;
					margin-bottom:6px;
					text-align:center;
				}
				.score-v{ display:flex; align-items:baseline; justify-content:center; gap:10px; text-align:center; }
				.score-num{ font-size:24px; font-weight:950; color:#0b2f2a; letter-spacing:.01em; }
				.score-sub{ font-size:11px; color:#64748b; font-weight:900; }
				.scorebar{
					margin-top:10px;
					height:8px;
					border-radius:999px;
					background:#f1f5f9;
					overflow:hidden;
					border:1px solid rgba(10,59,52,0.08);
				}
				.scorebar span{ display:block; height:100%; background: var(--icon-grad); width: 50%; }

				.grid-2{ display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);gap:12px; }
				@media(max-width:980px){ .grid-2{ grid-template-columns:1fr; } }

				.icon-wow-card{
					position:relative;
					border-radius: 22px;
					padding: 16px 18px;
					margin-bottom: 12px;
					overflow: hidden;
					background: radial-gradient(900px 420px at 12% 8%, rgba(21,160,109,0.12) 0%, rgba(21,160,109,0.00) 60%),
								radial-gradient(760px 360px at 92% 22%, rgba(20,164,207,0.12) 0%, rgba(20,164,207,0.00) 62%),
								rgba(255,255,255,0.94);
					border:1px solid rgba(21,160,109,0.22);
					box-shadow: var(--cardShadow);
					transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
				}
				.icon-wow-card:hover{ transform: translateY(-2px); box-shadow: var(--cardShadowHover); border-color: rgba(20,164,207,0.30); }
				.icon-wow-card:before{
					content:'';
					position:absolute;
					left:0; top:0; right:0;
					height: 5px;
					background: var(--icon-grad);
				}
				.icon-wow-card:after{
					content:'';
					position:absolute;
					right:-140px;
					top:-140px;
					width: 320px;
					height: 320px;
					border-radius: 999px;
					background: radial-gradient(circle, rgba(20,164,207,0.14), rgba(20,164,207,0.00) 70%);
					pointer-events:none;
				}
				.icon-wow-card h3{ margin:0 0 8px; font-size:14px; font-weight:950; color:#0b2f2a; letter-spacing:.01em; }
				.icon-wow-card .mini{ font-size:12px; color:var(--muted); line-height:1.35; }

				.pill{
					display:inline-flex;align-items:center;gap:8px;
					padding:7px 11px;border-radius:999px;
					border:1px solid rgba(20,164,207,.18);
					background:#fff;
					color: var(--ink);
					font-size:11px;font-weight:950;
					letter-spacing:.01em;
					box-shadow: 0 10px 22px rgba(10,59,52,0.06);
					transition: transform .14s ease, box-shadow .14s ease, border-color .14s ease;
				}
				a.pill:hover{ transform: translateY(-1px); box-shadow: 0 14px 28px rgba(10,59,52,0.10); border-color: rgba(21,160,109,0.30); text-decoration:none; }

				.delta-up{ color:#065f46;font-weight:950; }
				.delta-down{ color:#7f1d1d;font-weight:950; }

				.klist{ margin:0;padding-left:18px;font-size:12px;color:#425b56; }
				.klist li{ margin:4px 0; }

				.heat-wrap{ overflow:auto; }
				.heat{
					width:100%;
					min-width:0;
					display:grid;
					grid-template-columns: minmax(220px, 1.4fr) repeat(3, minmax(90px, 0.6fr));
					gap:8px;
					align-items:stretch;
				}
				.heat .hcell{
					background:#f9fafb;
					border:1px solid #e5e7eb;
					border-radius:12px;
					padding:10px 10px;
					font-size:11px;
					text-transform:uppercase;
					letter-spacing:.05em;
					color:#6b7280;
					font-weight:900;
				}
				.heat .name{ border:1px solid #e5e7eb; border-radius:12px; padding:10px 10px; background:#fff; }
				.heat .name .t{ font-weight:950;color:#0b2f2a;font-size:12px; }
				.heat .name .m{ margin-top:2px;font-size:11px;color:#64748b; }
				.heat .scorecell{
					border-radius:12px;
					border:1px solid rgba(10,59,52,0.10);
					padding:10px 10px;
					display:flex;
					align-items:center;
					justify-content:space-between;
					gap:8px;
					font-weight:950;
				}
				.heat .scorecell em{ font-style:normal; font-size:11px; opacity:.9; font-weight:900; }

				.subgrid-2{ display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:12px; }
				@media(max-width:1100px){ .subgrid-2{ grid-template-columns:1fr; } }

				.qgrid{ display:grid;grid-template-columns:1fr 1fr;gap:12px; }
				@media(max-width:980px){ .qgrid{ grid-template-columns:1fr; } }

				.qbox{
					border:1px solid rgba(21,160,109,0.18);
					border-radius:18px;
					padding:12px 12px;
					background: radial-gradient(520px 240px at 16% 18%, rgba(21,160,109,0.10) 0%, rgba(21,160,109,0) 60%), rgba(255,255,255,0.96);
					box-shadow: 0 10px 26px rgba(10,59,52,0.08);
				}
				.qbox h4{
					margin:0 0 6px 0;
					font-size:12px;
					font-weight:950;
					letter-spacing:.04em;
					text-transform:uppercase;
					color:#0b2f2a;
				}
				.qtag{
					display:inline-flex;
					align-items:flex-start;
					justify-content:flex-start;

					white-space: normal;
					flex-wrap: wrap;
					max-width: 100%;

					overflow-wrap: anywhere;
					word-break: break-word;
					hyphens: auto;

					padding:6px 10px;
					border-radius:999px;
					font-size:11px;
					font-weight:950;
					line-height:1.25;

					border:1px solid rgba(10,59,52,0.10);
					background:#f8fafc;

					margin-right:6px;
					margin-bottom:6px;
				}
				.qtag strong{
					white-space: nowrap;
				}

				.comp-card{ border:1px solid #e5e7eb; border-radius:18px; padding:12px 12px; background:#fff; margin-bottom:10px; }
				.comp-head{ display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px; }
				.comp-title{ font-weight:950;color:#0b2f2a;font-size:13px; }
				.comp-meta{ font-size:11px;color:#64748b; }

				.radar-wrap{ border:1px solid #e5e7eb; border-radius:22px; padding:18px 18px 12px; background:#fff; min-height: 460px; }
				.radar-wrap canvas{ max-height: 340px; }

				.card{
					position:relative;
					background:#fff;
					border-radius:18px;
					border:1px solid rgba(21,160,109,0.18);
					box-shadow: 0 10px 24px rgba(10,59,52,0.10);
					padding:14px 16px;
					margin-bottom:12px;
					overflow:hidden;
				}
				.card:before{
					content:'';
					position:absolute;
					left:0;top:0;right:0;height:4px;
					background: var(--icon-grad);
				}
				.card h3{ margin:0 0 8px;font-size:14px;font-weight:950;color:#0b2f2a; }
				.mini{ font-size:12px;color:var(--muted);line-height:1.35; }

				.table{ width:100%;border-collapse:separate;border-spacing:0;font-size:12px; }
				.table th,.table td{ padding:10px 8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top; }
				.table th{
					font-size:11px;
					text-transform:uppercase;
					letter-spacing:.05em;
					color:#6b7280;
					background:#f9fafb;
					position: sticky; top: 0; z-index: 1;
				}
				.table tbody tr:hover td{ background: rgba(248,250,252,0.8); }
			</style>

			<?php
			// Build share URL (prefer share_token, otherwise participant_id)
			$share_url = '';
			if ( $current_share_token !== '' ) {
				$share_url = add_query_arg(
					array( 'share_token' => $current_share_token ),
					remove_query_arg( array( 'participant_id', 'token', 'rater_id', 'icon_debug', 'icon_psy_export', 'regen_share', 'revoke_share', 'share_nonce' ) )
				);
			}

			// PDF URL should work for share link users too
			$pdf_args = array( 'icon_psy_export' => 'pdf' );
			if ( $share_token !== '' ) {
				$pdf_args['share_token'] = $share_token;
			} else {
				$pdf_args['participant_id'] = $participant_id;
			}

			$pdf_url = add_query_arg(
				$pdf_args,
				remove_query_arg( array( 'icon_debug', 'icon_psy_export', 'regen_share', 'revoke_share', 'share_nonce' ) )
			);

			$nonce_share = ( $is_admin_share && $participant_id > 0 ) ? wp_create_nonce( 'icon_psy_traits_share_' . (int) $participant_id ) : '';
			$regen_url   = ( $is_admin_share && $participant_id > 0 )
				? add_query_arg(
					array(
						'participant_id' => $participant_id,
						'regen_share'    => 1,
						'share_nonce'    => $nonce_share,
					),
					remove_query_arg( array( 'icon_psy_export', 'icon_debug', 'regen_share', 'revoke_share', 'share_nonce' ) )
				)
				: '';

			$revoke_url  = ( $is_admin_share && $participant_id > 0 )
				? add_query_arg(
					array(
						'participant_id' => $participant_id,
						'revoke_share'   => 1,
						'share_nonce'    => $nonce_share,
					),
					remove_query_arg( array( 'icon_psy_export', 'icon_debug', 'regen_share', 'revoke_share', 'share_nonce' ) )
				)
				: '';
			?>

			<?php if ( $icon_debug ) : ?>
				<div style="background:#0b2f2a;color:#fff;border-radius:14px;padding:12px 14px;margin:0 0 12px 0;font-size:12px;">
					<div style="font-weight:900;letter-spacing:.06em;text-transform:uppercase;">ICON Debug — Branding</div>
					<div style="margin-top:6px;opacity:.9;">
						Helper loaded: <strong><?php echo ! empty( $branding_helper_loaded ) ? 'YES' : 'NO'; ?></strong><br>
						primary: <strong><?php echo esc_html( $brand_primary ); ?></strong> ·
						secondary: <strong><?php echo esc_html( $brand_secondary ); ?></strong><br>
						logo_url: <strong><?php echo esc_html( $brand_logo_url ? $brand_logo_url : '(none)' ); ?></strong>
					</div>
				</div>
			<?php endif; ?>

			<section class="icon-cover">
			<?php if ( ! empty( $brand_logo_url ) ) : ?>
				<div style="display:flex;justify-content:center;margin-bottom:8px;">
					<img src="<?php echo esc_url( $brand_logo_url ); ?>" alt="" style="max-height:54px;max-width:220px;object-fit:contain;" loading="lazy">
				</div>
			<?php endif; ?>
				<div class="icon-cover-inner" style="text-align:center;">
					<span class="icon-eyebrow" style="margin-left:auto;margin-right:auto;">ICON Insight Report</span>
					<h1>Pre &amp; Post<br><span>Self Assessment</span> Report</h1>
					<p class="icon-cover-lead">
						This report is generated from the participant’s survey responses and translates results into clear, practical insight.
						<?php echo $is_comparison ? 'It compares Baseline and Post submissions.' : 'It shows the most recent submission snapshot.'; ?>
					</p>
				</div>
			</section>

			<div class="shell" style="margin-bottom:12px;">
				<div class="hero">
					<div class="hero-top">
						<div>
							<h2>ICON Pre &amp; Post Assessment Report</h2>
							<p>
								<?php if ( $is_comparison ) : ?>
									Self Assessment comparison: <strong>Baseline</strong> vs <strong>Post</strong>.
								<?php else : ?>
									Self Assessment snapshot based on the most recent submission.
								<?php endif; ?>
							</p>

							<div class="chip-row">
								<span class="chip">Participant: <?php echo esc_html( $participant_name ); ?></span>
								<?php if ( $participant_role ) : ?><span class="chip-muted">Role: <?php echo esc_html( $participant_role ); ?></span><?php endif; ?>
								<?php if ( $project_name ) : ?><span class="chip">Project: <?php echo esc_html( $project_name ); ?></span><?php endif; ?>
								<?php if ( $client_name ) : ?><span class="chip-muted">Client: <?php echo esc_html( $client_name ); ?></span><?php endif; ?>
								<?php if ( $is_comparison ) : ?>
									<?php if ( $baseline_at ) : ?><span class="chip-muted">Baseline submitted: <?php echo esc_html( $baseline_at ); ?></span><?php endif; ?>
									<?php if ( $post_at ) : ?><span class="chip-muted">Post submitted: <?php echo esc_html( $post_at ); ?></span><?php endif; ?>
								<?php else : ?>
									<?php if ( $baseline_at ) : ?><span class="chip-muted">Submitted: <?php echo esc_html( $baseline_at ); ?></span><?php endif; ?>
								<?php endif; ?>
								<?php if ( $ai_draft_id > 0 ) : ?><span class="chip-muted">Framework draft ID: <?php echo (int) $ai_draft_id; ?></span><?php endif; ?>
							</div>

							<?php if ( $is_admin_share && $share_url !== '' ) : ?>
								<div class="share-wrap" aria-label="Share report link">
									<div class="share-title">Share link (email)</div>
									<div class="share-row">
										<input id="iconShareUrl" type="text" readonly value="<?php echo esc_attr( $share_url ); ?>" onclick="this.select();">
										<button class="share-btn" type="button" id="iconCopyShare">Copy link</button>
										<a class="share-btn alt" id="iconEmailShare" href="#">Email link</a>
										<?php if ( $regen_url ) : ?><a class="share-btn" href="<?php echo esc_url( $regen_url ); ?>">Regenerate</a><?php endif; ?>
										<?php if ( $revoke_url ) : ?><a class="share-btn danger" href="<?php echo esc_url( $revoke_url ); ?>">Revoke</a><?php endif; ?>
									</div>
									<div class="share-note">
										<?php if ( $share_actions_note ) : ?><strong><?php echo esc_html( $share_actions_note ); ?></strong><br><?php endif; ?>
										This link uses a token (no participant ID). If you regenerate, the old link stops working.
									</div>
								</div>
							<?php endif; ?>

							<div style="display:flex;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:12px;">
								<a class="share-btn alt" href="<?php echo esc_url( $pdf_url ); ?>" style="text-decoration:none;">Export PDF</a>
							</div>

						</div>
					</div>

					<div class="score-grid" aria-label="Headline score tiles">
						<div class="score-card">
							<div class="score-k">Baseline average</div>
							<div class="score-v">
								<div class="score-num"><?php echo number_format_i18n( (float) $baseline_overall, 2 ); ?></div>
								<div class="score-sub">out of 7</div>
							</div>
							<div class="scorebar"><span style="width:<?php echo esc_attr( min( 100, max( 0, ( (float) $baseline_overall / 7 ) * 100 ) ) ); ?>%;"></span></div>
						</div>

						<div class="score-card">
							<div class="score-k"><?php echo $is_comparison ? 'Post average' : 'Competencies'; ?></div>
							<div class="score-v">
								<div class="score-num"><?php echo $is_comparison ? number_format_i18n( (float) $post_overall, 2 ) : (int) count( $base_map ); ?></div>
								<div class="score-sub"><?php echo $is_comparison ? 'out of 7' : 'items'; ?></div>
							</div>
							<div class="scorebar"><span style="width:<?php echo $is_comparison ? esc_attr( min( 100, max( 0, ( ( (float) $post_overall ) / 7 ) * 100 ) ) ) : '55'; ?>%;"></span></div>
						</div>

						<div class="score-card">
							<div class="score-k"><?php echo $is_comparison ? 'Overall movement' : 'Snapshot'; ?></div>
							<div class="score-v">
								<?php if ( $is_comparison ) : ?>
									<?php $d = (float) $overall_delta; ?>
									<div class="score-num" style="color:<?php echo $d >= 0 ? '#065f46' : '#7f1d1d'; ?>;"><?php echo esc_html( ( $d >= 0 ? '+' : '' ) . number_format_i18n( $d, 2 ) ); ?></div>
									<div class="score-sub">Post minus Baseline</div>
								<?php else : ?>
									<div class="score-num">Latest</div>
									<div class="score-sub">submission</div>
								<?php endif; ?>
							</div>
							<div class="scorebar"><span style="width:<?php echo $is_comparison ? esc_attr( min( 100, max( 0, ( abs( (float) $overall_delta ) / 2 ) * 100 ) ) ) : '60'; ?>%;"></span></div>
						</div>

						<div class="score-card">
							<div class="score-k"><?php echo $is_comparison ? 'Stability signal' : 'Assessment'; ?></div>
							<div class="score-v">
								<?php if ( $is_comparison ) : ?>
									<div class="score-num"><?php echo (int) $stability_improved; ?></div>
									<div class="score-sub">improved under pressure</div>
								<?php else : ?>
									<div class="score-num">Self</div>
									<div class="score-sub">assessment</div>
								<?php endif; ?>
							</div>
							<div class="scorebar"><span style="width:<?php echo $is_comparison ? esc_attr( min( 100, max( 0, ( ( (int) $stability_improved ) / max( 1, count( $base_map ) ) ) * 100 ) ) ) : '50'; ?>%;"></span></div>
						</div>
					</div>

				</div>
			</div>

			<div class="icon-wow-card" style="padding:10px 12px;">
				<div class="mini" style="font-weight:950;color:#0b2f2a;margin-bottom:6px;">Contents</div>
				<div style="display:flex;flex-wrap:wrap;gap:8px;">
					<a class="pill" href="#sec1" style="text-decoration:none;">1 Quick Scan</a>
					<a class="pill" href="#sec2" style="text-decoration:none;">2 Key Takeaways</a>
					<a class="pill" href="#sec3" style="text-decoration:none;">3 Radar</a>
					<a class="pill" href="#sec4" style="text-decoration:none;">4 Trend Map</a>
					<a class="pill" href="#sec5" style="text-decoration:none;">5 Heatmaps</a>
					<a class="pill" href="#sec6" style="text-decoration:none;">6 Tables</a>
					<a class="pill" href="#sec7" style="text-decoration:none;">7 Summaries</a>
					<a class="pill" href="#sec8" style="text-decoration:none;">8 Model</a>
				</div>
			</div>

			<div class="grid-2">
				<div class="icon-wow-card" id="sec1">
					<h3>Quick Scan</h3>

					<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
						<?php $b_base = $band( $baseline_overall ); ?>
						<span class="pill" style="border-color:<?php echo esc_attr( $b_base[3] ); ?>;background:<?php echo esc_attr( $b_base[2] ); ?>;color:<?php echo esc_attr( $b_base[1] ); ?>;">
							Baseline avg: <?php echo number_format_i18n( $baseline_overall, 2 ); ?> / 7 <span style="opacity:.9;">(<?php echo esc_html( $b_base[0] ); ?>)</span>
						</span>

						<?php if ( $is_comparison ) : ?>
							<?php $b_post = $band( (float) $post_overall ); ?>
							<span class="pill" style="border-color:<?php echo esc_attr( $b_post[3] ); ?>;background:<?php echo esc_attr( $b_post[2] ); ?>;color:<?php echo esc_attr( $b_post[1] ); ?>;">
								Post avg: <?php echo number_format_i18n( (float) $post_overall, 2 ); ?> / 7 <span style="opacity:.9;">(<?php echo esc_html( $b_post[0] ); ?>)</span>
							</span>

							<?php
							$delta_overall     = (float) $overall_delta;
							$delta_overall_txt = ( $delta_overall >= 0 ? '+' : '' ) . number_format_i18n( $delta_overall, 2 );
							$delta_overall_cls = $delta_overall >= 0 ? 'delta-up' : 'delta-down';
							?>

							<span class="pill">Overall delta: <span class="<?php echo esc_attr( $delta_overall_cls ); ?>"><?php echo esc_html( $delta_overall_txt ); ?></span></span>
							<span class="pill">Stability improved: <?php echo (int) $stability_improved; ?> · Reduced: <?php echo (int) $stability_worsened; ?></span>
						<?php endif; ?>

						<span class="pill">Competencies: <?php echo (int) count( $base_map ); ?></span>
					</div>

					<div class="mini">
						<strong>Delta explained:</strong> Delta is calculated as <strong>Post minus Baseline</strong>. Positive delta = improvement, negative delta = decline, near zero = stable.
					</div>

					<div class="subgrid-2" style="margin-top:10px;">
						<div class="qbox">
							<h4>Top strengths</h4>
							<div class="mini">Highest baseline averages.</div>
							<?php foreach ( $top_strengths as $s ) : ?>
								<span class="qtag"><?php echo esc_html( $s['name'] ); ?> · <strong><?php echo number_format_i18n( (float) $s['avg'], 2 ); ?></strong></span>
							<?php endforeach; ?>
						</div>

						<div class="qbox">
							<h4>Top priorities</h4>
							<div class="mini">Lowest baseline averages.</div>
							<?php foreach ( $bottom_priorities as $p ) : ?>
								<span class="qtag"><?php echo esc_html( $p['name'] ); ?> · <strong><?php echo number_format_i18n( (float) $p['avg'], 2 ); ?></strong></span>
							<?php endforeach; ?>
						</div>
					</div>

					<?php if ( $is_comparison ) : ?>
						<div class="subgrid-2" style="margin-top:12px;">
							<div class="qbox">
								<h4>Most improved</h4>
								<div class="mini">Largest positive deltas (Post minus Baseline).</div>
								<?php foreach ( array_slice( $top_improve, 0, 3 ) as $d ) : ?>
									<span class="qtag"><?php echo esc_html( $d['name'] ); ?> · <strong class="delta-up">+<?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong></span>
								<?php endforeach; ?>
								<?php if ( empty( $top_improve ) ) : ?><div class="mini">Not enough matched items.</div><?php endif; ?>
							</div>

							<div class="qbox">
								<h4>Largest declines</h4>
								<div class="mini">Largest negative deltas (Post minus Baseline).</div>
								<?php foreach ( array_slice( $top_drop, 0, 3 ) as $d ) : ?>
									<span class="qtag"><?php echo esc_html( $d['name'] ); ?> · <strong class="delta-down"><?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong></span>
								<?php endforeach; ?>
								<?php if ( empty( $top_drop ) ) : ?><div class="mini">Not enough matched items.</div><?php endif; ?>
							</div>
						</div>
					<?php endif; ?>
				</div>

				<div class="icon-wow-card" id="sec2">
					<h3>Key Takeaways</h3>
					<ul class="klist">
						<?php foreach ( (array) $key_takeaways as $kt ) : ?>
							<li><?php echo esc_html( $kt ); ?></li>
						<?php endforeach; ?>
					</ul>

					<div class="mini" style="margin-top:10px;">
						Each competency is rated across three contexts: <strong>Day to day</strong>, <strong>Under pressure</strong>, and <strong>Quality standard</strong>.
					</div>

					<div class="mini" style="margin-top:8px;">
						Consistency uses variance <strong>|Q2 - Q1|</strong>. 0 to 1 stable, 2 variable, 3+ volatile.
					</div>
				</div>
			</div>

			<div class="grid-2" id="sec3">
				<div class="icon-wow-card">
					<h3>Self radar</h3>

					<div class="radar-wrap">
						<canvas id="iconSelfRadar" height="420" aria-label="Self radar chart" role="img"></canvas>
						<div id="iconRadarFallback" class="mini" style="display:none;margin-top:8px;">
							Radar chart could not load. The heatmap and tables below still show all scores.
						</div>
					</div>

					<div class="mini" style="margin-top:8px;">
						Radar shows up to <?php echo (int) $radar_count; ?> competencies (highest baseline averages) to keep it readable.
						<?php if ( $is_comparison ) : ?> The chart shows both Baseline and Post.<?php endif; ?>
					</div>
				</div>

				<div class="icon-wow-card">
					<h3>How to read the scores</h3>
					<ul class="klist">
						<li><strong>Day to day</strong> is how consistently this shows up in normal work.</li>
						<li><strong>Under pressure</strong> is how stable it is when time, complexity, or ambiguity increases.</li>
						<li><strong>Quality standard</strong> is whether your approach is repeatable and consistent.</li>
					</ul>

					<div class="mini" style="margin-top:10px;">
						<strong>Interpreting change over time</strong><br>
						<ul class="klist">
							<li><strong>0.35 or more</strong> = meaningful behavioural change</li>
							<li><strong>0.15 to 0.34</strong> = directional movement</li>
							<li><strong>Less than 0.15</strong> = broadly stable</li>
						</ul>
					</div>
				</div>
			</div>

			<?php if ( $is_comparison ) : ?>
				<div class="icon-wow-card" id="sec4">
					<h3>Trend Map (Baseline vs Delta)</h3>
					<div class="mini" style="margin-bottom:10px;">This 2×2 view uses baseline strength (high vs low) and change (up vs down).</div>

					<div class="qgrid">
						<div class="qbox">
							<h4>Grow &amp; protect</h4>
							<div class="mini">High baseline and improving.</div>
							<?php foreach ( array_slice( $trend_quadrants['grow_protect'], 0, 10 ) as $d ) : ?>
								<span class="qtag"><?php echo esc_html( $d['name'] ); ?> · <strong class="delta-up">+<?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong></span>
							<?php endforeach; ?>
							<?php if ( empty( $trend_quadrants['grow_protect'] ) ) : ?><div class="mini">No items in this quadrant.</div><?php endif; ?>
						</div>

						<div class="qbox">
							<h4>Strength at risk</h4>
							<div class="mini">High baseline but declining.</div>
							<?php foreach ( array_slice( $trend_quadrants['strength_risk'], 0, 10 ) as $d ) : ?>
								<span class="qtag"><?php echo esc_html( $d['name'] ); ?> · <strong class="delta-down"><?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong></span>
							<?php endforeach; ?>
							<?php if ( empty( $trend_quadrants['strength_risk'] ) ) : ?><div class="mini">No items in this quadrant.</div><?php endif; ?>
						</div>

						<div class="qbox">
							<h4>Breakthrough</h4>
							<div class="mini">Lower baseline and improving.</div>
							<?php foreach ( array_slice( $trend_quadrants['breakthrough'], 0, 10 ) as $d ) : ?>
								<span class="qtag"><?php echo esc_html( $d['name'] ); ?> · <strong class="delta-up">+<?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong></span>
							<?php endforeach; ?>
							<?php if ( empty( $trend_quadrants['breakthrough'] ) ) : ?><div class="mini">No items in this quadrant.</div><?php endif; ?>
						</div>

						<div class="qbox">
							<h4>Priority focus</h4>
							<div class="mini">Lower baseline and declining.</div>
							<?php foreach ( array_slice( $trend_quadrants['priority'], 0, 10 ) as $d ) : ?>
								<span class="qtag"><?php echo esc_html( $d['name'] ); ?> · <strong class="delta-down"><?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong></span>
							<?php endforeach; ?>
							<?php if ( empty( $trend_quadrants['priority'] ) ) : ?><div class="mini">No items in this quadrant.</div><?php endif; ?>
						</div>
					</div>

					<div class="mini" style="margin-top:10px;">
						Baseline “high” uses <?php echo number_format_i18n( (float) $trend_base_threshold, 1 ); ?> / 7 as the boundary.
					</div>
				</div>
			<?php endif; ?>

			<div class="card" id="sec5">
				<h3>Heatmaps</h3>
				<div class="mini" style="margin-bottom:10px;">
					<?php if ( $is_comparison ) : ?>
						Baseline and Post heatmaps are shown side by side. A Delta heatmap shows Post minus Baseline for Q1/Q2/Q3.
					<?php else : ?>
						Heatmap shows the latest self assessment.
					<?php endif; ?>
				</div>

				<?php if ( $is_comparison ) : ?>
					<div class="subgrid-2">
						<div class="card" style="margin:0;">
							<h3 style="margin-bottom:10px;">Baseline heatmap</h3>
							<div class="heat-wrap">
								<div class="heat">
									<div class="hcell">Competency</div>
									<div class="hcell">Day to day</div>
									<div class="hcell">Under pressure</div>
									<div class="hcell">Quality standard</div>

									<?php foreach ( $items as $t ) : ?>
										<?php
										$key = $t['key'];
										if ( empty( $base_map[ $key ] ) ) continue;
										$b  = $base_map[ $key ];
										$c1 = $score_color( $b['q1'] );
										$c2 = $score_color( $b['q2'] );
										$c3 = $score_color( $b['q3'] );
										?>
										<div class="name">
											<div class="t"><?php echo esc_html( $b['name'] ); ?></div>
											<div class="m">Module: <?php echo esc_html( $b['module'] ); ?> · Conf: <?php echo (int) $b['confidence']; ?>/5</div>
										</div>
										<div class="scorecell" style="background:<?php echo esc_attr( $c1[0] ); ?>;color:<?php echo esc_attr( $c1[1] ); ?>;">
											<span><?php echo (int) $b['q1']; ?>/7</span><em>Q1</em>
										</div>
										<div class="scorecell" style="background:<?php echo esc_attr( $c2[0] ); ?>;color:<?php echo esc_attr( $c2[1] ); ?>;">
											<span><?php echo (int) $b['q2']; ?>/7</span><em>Q2</em>
										</div>
										<div class="scorecell" style="background:<?php echo esc_attr( $c3[0] ); ?>;color:<?php echo esc_attr( $c3[1] ); ?>;">
											<span><?php echo (int) $b['q3']; ?>/7</span><em>Q3</em>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						</div>

						<div class="card" style="margin:0;">
							<h3 style="margin-bottom:10px;">Post heatmap</h3>
							<div class="heat-wrap">
								<div class="heat">
									<div class="hcell">Competency</div>
									<div class="hcell">Day to day</div>
									<div class="hcell">Under pressure</div>
									<div class="hcell">Quality standard</div>

									<?php foreach ( $items as $t ) : ?>
										<?php
										$key = $t['key'];
										if ( empty( $post_map[ $key ] ) ) continue;
										$p  = $post_map[ $key ];
										$c1 = $score_color( $p['q1'] );
										$c2 = $score_color( $p['q2'] );
										$c3 = $score_color( $p['q3'] );
										?>
										<div class="name">
											<div class="t"><?php echo esc_html( $p['name'] ); ?></div>
											<div class="m">Module: <?php echo esc_html( $p['module'] ); ?> · Conf: <?php echo (int) $p['confidence']; ?>/5</div>
										</div>
										<div class="scorecell" style="background:<?php echo esc_attr( $c1[0] ); ?>;color:<?php echo esc_attr( $c1[1] ); ?>;">
											<span><?php echo (int) $p['q1']; ?>/7</span><em>Q1</em>
										</div>
										<div class="scorecell" style="background:<?php echo esc_attr( $c2[0] ); ?>;color:<?php echo esc_attr( $c2[1] ); ?>;">
											<span><?php echo (int) $p['q2']; ?>/7</span><em>Q2</em>
										</div>
										<div class="scorecell" style="background:<?php echo esc_attr( $c3[0] ); ?>;color:<?php echo esc_attr( $c3[1] ); ?>;">
											<span><?php echo (int) $p['q3']; ?>/7</span><em>Q3</em>
										</div>
									<?php endforeach; ?>
								</div>
							</div>

							<div class="mini" style="margin-top:10px;">Delta is shown in the tables and the Delta heatmap below.</div>
						</div>
					</div>

					<div class="card" style="margin-top:12px;">
						<h3 style="margin-bottom:10px;">Delta heatmap (Post minus Baseline)</h3>
						<div class="mini" style="margin-bottom:10px;">Positive changes are shaded toward green, negative toward red.</div>

						<div class="heat-wrap">
							<div class="heat">
								<div class="hcell">Competency</div>
								<div class="hcell">Delta Q1</div>
								<div class="hcell">Delta Q2</div>
								<div class="hcell">Delta Q3</div>

								<?php foreach ( $items as $t ) : ?>
									<?php
									$key = $t['key'];
									if ( empty( $base_map[ $key ] ) || empty( $post_map[ $key ] ) ) continue;
									$b = $base_map[ $key ];
									$p = $post_map[ $key ];

									$d1 = (float) $p['q1'] - (float) $b['q1'];
									$d2 = (float) $p['q2'] - (float) $b['q2'];
									$d3 = (float) $p['q3'] - (float) $b['q3'];

									$dc1 = $delta_color( $d1 );
									$dc2 = $delta_color( $d2 );
									$dc3 = $delta_color( $d3 );

									$txt1 = ( $d1 >= 0 ? '+' : '' ) . number_format_i18n( (float) $d1, 0 );
									$txt2 = ( $d2 >= 0 ? '+' : '' ) . number_format_i18n( (float) $d2, 0 );
									$txt3 = ( $d3 >= 0 ? '+' : '' ) . number_format_i18n( (float) $d3, 0 );
									?>
									<div class="name">
										<div class="t"><?php echo esc_html( $b['name'] ); ?></div>
										<div class="m">Module: <?php echo esc_html( $b['module'] ); ?></div>
									</div>

									<div class="scorecell" style="background:<?php echo esc_attr( $dc1[0] ); ?>;color:<?php echo esc_attr( $dc1[1] ); ?>;">
										<span><?php echo esc_html( $txt1 ); ?></span><em>Q1</em>
									</div>
									<div class="scorecell" style="background:<?php echo esc_attr( $dc2[0] ); ?>;color:<?php echo esc_attr( $dc2[1] ); ?>;">
										<span><?php echo esc_html( $txt2 ); ?></span><em>Q2</em>
									</div>
									<div class="scorecell" style="background:<?php echo esc_attr( $dc3[0] ); ?>;color:<?php echo esc_attr( $dc3[1] ); ?>;">
										<span><?php echo esc_html( $txt3 ); ?></span><em>Q3</em>
									</div>
								<?php endforeach; ?>

							</div>
						</div>
					</div>

				<?php else : ?>

					<div class="heat-wrap">
						<div class="heat">
							<div class="hcell">Competency</div>
							<div class="hcell">Day to day</div>
							<div class="hcell">Under pressure</div>
							<div class="hcell">Quality standard</div>

							<?php foreach ( $items as $t ) : ?>
								<?php
								$key = $t['key'];
								if ( empty( $base_map[ $key ] ) ) continue;
								$b  = $base_map[ $key ];
								$c1 = $score_color( $b['q1'] );
								$c2 = $score_color( $b['q2'] );
								$c3 = $score_color( $b['q3'] );
								?>
								<div class="name">
									<div class="t"><?php echo esc_html( $b['name'] ); ?></div>
									<div class="m">Module: <?php echo esc_html( $b['module'] ); ?> · Conf: <?php echo (int) $b['confidence']; ?>/5</div>
								</div>

								<div class="scorecell" style="background:<?php echo esc_attr( $c1[0] ); ?>;color:<?php echo esc_attr( $c1[1] ); ?>;">
									<span><?php echo (int) $b['q1']; ?>/7</span><em>Q1</em>
								</div>
								<div class="scorecell" style="background:<?php echo esc_attr( $c2[0] ); ?>;color:<?php echo esc_attr( $c2[1] ); ?>;">
									<span><?php echo (int) $b['q2']; ?>/7</span><em>Q2</em>
								</div>
								<div class="scorecell" style="background:<?php echo esc_attr( $c3[0] ); ?>;color:<?php echo esc_attr( $c3[1] ); ?>;">
									<span><?php echo (int) $b['q3']; ?>/7</span><em>Q3</em>
								</div>
							<?php endforeach; ?>

						</div>
					</div>

				<?php endif; ?>
			</div>

			<div class="card" id="sec6">
				<h3><?php echo $is_comparison ? 'Comparison table and trends' : 'Competency results table'; ?></h3>
				<div class="mini" style="margin-bottom:10px;">
					<?php if ( $is_comparison ) : ?>
						Delta is Post minus Baseline. Consistency uses variance <strong>|Q2 - Q1|</strong>.
					<?php else : ?>
						Table shows average plus confidence. Consistency uses variance <strong>|Q2 - Q1|</strong>.
					<?php endif; ?>
				</div>

				<div style="overflow:auto;">
					<table class="table">
						<thead>
							<tr>
								<th class="col-competency">Competency</th>
								<?php if ( $is_comparison ) : ?>
									<th>Baseline Q1/Q2/Q3</th>
									<th>Post Q1/Q2/Q3</th>
									<th>Baseline avg</th>
									<th>Post avg</th>
									<th>Delta</th>
									<th>Consistency (|Q2-Q1|)</th>
									<th>Confidence</th>
								<?php else : ?>
									<th>Q1</th><th>Q2</th><th>Q3</th><th>Avg</th><th>Consistency</th><th>Confidence</th>
								<?php endif; ?>
							</tr>
						</thead>

						<tbody>
							<?php foreach ( $items as $t ) : ?>
								<?php
								$key = $t['key'];
								if ( empty( $base_map[ $key ] ) ) continue;

								$base      = $base_map[ $key ];
								$avg_base  = (float) $base['avg'];
								$base_gap  = abs( (int) $base['q2'] - (int) $base['q1'] );
								$base_stab = $stability_label( $base_gap );

								$post     = ( $is_comparison && ! empty( $post_map[ $key ] ) ) ? $post_map[ $key ] : null;
								$avg_post = $post ? (float) $post['avg'] : null;

								$delta_txt = '—';
								$delta_cls = '';
								if ( $is_comparison && $post ) {
									$delta       = $avg_post - $avg_base;
									$delta_label = $delta_classification( $delta );
									$delta_txt   = ( $delta >= 0 ? '+' : '' ) . number_format_i18n( $delta, 2 ) . ' (' . $delta_label . ')';
									$delta_cls   = $delta >= 0 ? 'delta-up' : 'delta-down';
								}
								?>
								<tr>
									<td class="col-competency">
										<div style="font-weight:950;color:#0b2f2a;"><?php echo esc_html( $base['name'] ); ?></div>
										<div class="mini">Module: <?php echo esc_html( $base['module'] ); ?></div>
									</td>

									<?php if ( $is_comparison ) : ?>
										<td><?php echo (int) $base['q1']; ?> / <?php echo (int) $base['q2']; ?> / <?php echo (int) $base['q3']; ?></td>
										<td><?php echo $post ? ( (int) $post['q1'] . ' / ' . (int) $post['q2'] . ' / ' . (int) $post['q3'] ) : '—'; ?></td>
										<td><?php echo number_format_i18n( $avg_base, 2 ); ?></td>
										<td><?php echo $post ? number_format_i18n( $avg_post, 2 ) : '—'; ?></td>
										<td><span class="<?php echo esc_attr( $delta_cls ); ?>"><?php echo esc_html( $delta_txt ); ?></span></td>
										<td><?php echo esc_html( $base_stab[0] ); ?> (<?php echo (int) $base_gap; ?>)</td>
										<td><?php echo (int) $base['confidence']; ?>/5<?php echo $post ? ' → ' . (int) $post['confidence'] . '/5' : ''; ?></td>
									<?php else : ?>
										<td><?php echo (int) $base['q1']; ?></td>
										<td><?php echo (int) $base['q2']; ?></td>
										<td><?php echo (int) $base['q3']; ?></td>
										<td><?php echo number_format_i18n( $avg_base, 2 ); ?></td>
										<td><?php echo esc_html( $base_stab[0] ); ?> (<?php echo (int) $base_gap; ?>)</td>
										<td><?php echo (int) $base['confidence']; ?>/5</td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>

					</table>
				</div>
			</div>

			<div class="card" id="sec7">
				<h3>Competency by competency summary</h3>
				<div class="mini" style="margin-bottom:10px;">
					<?php echo $is_comparison ? 'Each summary highlights what changed, plus a consistency signal.' : 'Each summary highlights the pattern across contexts and a practical next step.'; ?>
				</div>

				<?php foreach ( $items as $t ) : ?>
					<?php
					$key = $t['key'];
					if ( empty( $base_map[ $key ] ) ) continue;

					$base    = $base_map[ $key ];
					$post    = ( $is_comparison && ! empty( $post_map[ $key ] ) ) ? $post_map[ $key ] : null;
					$summary = $make_summary( $base, $post );
					$b       = $band( $base['avg'] );
					$anchor  = 'comp_' . sanitize_title( (string) $summary['headline'] );
					?>
					<div class="comp-card" id="<?php echo esc_attr( $anchor ); ?>">
						<div class="comp-head">
							<div>
								<div class="comp-title"><?php echo esc_html( $summary['headline'] ); ?></div>
								<div class="comp-meta">Module: <?php echo esc_html( $base['module'] ); ?></div>
							</div>
							<span class="pill" style="border-color:<?php echo esc_attr( $b[3] ); ?>;background:<?php echo esc_attr( $b[2] ); ?>;color:<?php echo esc_attr( $b[1] ); ?>;">
								<?php echo esc_html( $b[0] ); ?>
							</span>
						</div>

						<ul class="klist">
							<?php foreach ( (array) $summary['bullets'] as $bl ) : ?>
								<li><?php echo esc_html( $bl ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="card" id="sec8">
				<h3>Competency Model used</h3>
				<div class="mini" style="margin-bottom:10px;">This report displays the competencies found in the submitted framework for this assessment.</div>

				<?php foreach ( $modules as $m => $names ) : ?>
					<div style="margin-bottom:10px;">
						<div class="pill" style="margin-bottom:6px;"><?php echo esc_html( $m ); ?></div>
						<ul class="klist" style="margin-top:0;">
							<?php
							$names2 = array_values( array_unique( array_filter( $names ) ) );
							sort( $names2 );
							foreach ( $names2 as $nm ) :
							?>
								<li><?php echo esc_html( $nm ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>

		</div><!-- /.icon-report-wrap -->

		<?php
		$radar_labels_json = wp_json_encode( array_values( $radar_labels ) );
		$radar_base_json   = wp_json_encode( array_values( $radar_base_values ) );
		$radar_post_json   = wp_json_encode( array_values( $radar_post_values ) );
		$is_comp_json      = wp_json_encode( (bool) $is_comparison );

		// Use resolved branding colours for Chart.js too
		$js_brand_primary   = $brand_primary;
		$js_brand_secondary = $brand_secondary;
		?>
		<script>
		(function(){
			var labels = <?php echo $radar_labels_json; ?>;
			var baseValues = <?php echo $radar_base_json; ?>;
			var postValues = <?php echo $radar_post_json; ?>;
			var isComparison = <?php echo $is_comp_json; ?>;

			function showFallback(){
				var fb = document.getElementById('iconRadarFallback');
				if (fb) fb.style.display = 'block';
			}

			function loadChartJs(cb){
				if (window.Chart) return cb();
				var s = document.createElement('script');
				s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
				s.async = true;
				s.onload = cb;
				s.onerror = function(){ showFallback(); };
				document.head.appendChild(s);
			}

			// ✅ wrap long labels to 2 lines max (Chart.js supports multiline arrays)
			function wrapLabel(label, maxChars){
				label = (label || '').toString().replace(/\s+/g,' ').trim();
				if (!label) return '';
				maxChars = maxChars || 14;

				var words = label.split(' ');
				var lines = [];
				var line = '';

				for (var i=0;i<words.length;i++){
					var w = words[i];
					var next = line ? (line + ' ' + w) : w;

					if (next.length <= maxChars){
						line = next;
					} else {
						if (line) lines.push(line);
						line = w;
					}

					if (lines.length === 2) break;
				}

				if (lines.length < 2 && line) lines.push(line);

				// If truncated, add ellipsis to last line
				var original = words.join(' ');
				var joined = lines.join(' ');
				if (original.length > joined.length) {
					lines[lines.length - 1] = (lines[lines.length - 1] + '…').replace(/……$/, '…');
				}

				return lines;
			}

			function hexToRgba(hex, a){
				if(!hex) return 'rgba(0,0,0,'+(a||1)+')';
				hex = (''+hex).replace('#','').trim();
				if(hex.length!==6) return 'rgba(0,0,0,'+(a||1)+')';
				var r = parseInt(hex.substring(0,2),16);
				var g = parseInt(hex.substring(2,4),16);
				var b = parseInt(hex.substring(4,6),16);
				return 'rgba('+r+','+g+','+b+','+(typeof a==='number'?a:1)+')';
			}

			// Share buttons (admin only)
			(function(){
				var inp = document.getElementById('iconShareUrl');
				var btn = document.getElementById('iconCopyShare');
				var email = document.getElementById('iconEmailShare');

				function getUrl(){
					return inp ? (inp.value || '').trim() : '';
				}

				if (email){
					var url = getUrl();
					var subj = 'ICON Traits Report';
					var body = 'Here is the report link:\\n\\n' + url + '\\n\\n';
					email.href = 'mailto:?subject=' + encodeURIComponent(subj) + '&body=' + encodeURIComponent(body);
				}

				if (btn && inp){
					btn.addEventListener('click', function(){
						var url = getUrl();
						if (!url) return;

						function done(ok){
							btn.textContent = ok ? 'Copied' : 'Copy failed';
							setTimeout(function(){ btn.textContent = 'Copy link'; }, 1200);
						}

						if (navigator.clipboard && navigator.clipboard.writeText){
							navigator.clipboard.writeText(url).then(function(){ done(true); }).catch(function(){ done(false); });
						} else {
							inp.focus(); inp.select();
							try {
								var ok = document.execCommand('copy');
								done(!!ok);
							} catch(e){
								done(false);
							}
						}
					});
				}
			})();

			loadChartJs(function(){
				if (!window.Chart) return showFallback();
				var el = document.getElementById('iconSelfRadar');
				if (!el) return;
				var ctx = el.getContext('2d');

				try{
					var brandPrimary = <?php echo wp_json_encode( $js_brand_primary ); ?>;
					var brandSecondary = <?php echo wp_json_encode( $js_brand_secondary ); ?>;

					var green = hexToRgba(brandPrimary, 1);
					var greenFill = hexToRgba(brandPrimary, 0.18);
					var blue = hexToRgba(brandSecondary, 1);
					var blueFill = hexToRgba(brandSecondary, 0.16);

					var datasets = [{
						label: 'Baseline',
						data: baseValues,
						fill: true,
						borderColor: green,
						backgroundColor: greenFill,
						pointBackgroundColor: green,
						pointBorderColor: '#ffffff',
						pointBorderWidth: 2,
						borderWidth: 2
					}];

					if (isComparison) {
						datasets.push({
							label: 'Post',
							data: postValues,
							fill: true,
							borderColor: blue,
							backgroundColor: blueFill,
							pointBackgroundColor: blue,
							pointBorderColor: '#ffffff',
							pointBorderWidth: 2,
							borderWidth: 2
						});
					}

					new Chart(ctx, {
						type: 'radar',
						data: { labels: labels, datasets: datasets },
						options: {
							responsive: true,
							maintainAspectRatio: false,
							// ✅ extra breathing room so labels don’t clip
							layout: { padding: 18 },
							plugins: {
								legend: {
									display: !!isComparison,
									labels: { font: { size: 12 }, padding: 16 }
								}
							},
							scales: {
								r: {
									min: 0,
									max: 7,
									ticks: { stepSize: 1, backdropColor: 'transparent', font: { size: 11 } },
									// ✅ wrap + shrink labels
									pointLabels: {
										padding: 10,
										font: { size: 10 },
										callback: function(label){
											return wrapLabel(label, 14);
										}
									}
								}
							}
						}
					});
				}catch(e){
					showFallback();
				}
			});
		})();
		</script>
		<?php

		return (string) ob_get_clean();
	}
}

add_shortcode( 'icon_psy_traits_report', 'icon_psy_traits_report' );
