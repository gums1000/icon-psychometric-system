<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ICON Catalyst – Technical Traits Survey (Rater + Self mode)
 *
 * Shortcode: [icon_psy_rater_survey_traits]
 *
 * UPDATE (Self-only UI):
 * - This survey is intended to be used as SELF-ASSESSMENT.
 * - Shows the RATER'S name (not the participant name).
 * - Forces self-mode wording/UI by default (overrideable with force_self=0 if needed).
 * - No DB logic changed.
 *
 * PATCH (stability):
 * - Prevents duplicate result rows: if an existing result exists for this participant+rater+project+framework (+phase/pair_key when available),
 *   we UPDATE it instead of INSERTing a new one.
 *
 * PATCH (branding):
 * - Gradients now truly use the resolved brand colours (previously hard-coded Icon green/blue RGBA).
 * - Branding merge now accepts common key aliases (primary_color / secondary_color / logo etc).
 * - Admin-only branding debug shows resolved values.
 */

/**
 * Get the framework_id for a given participant (uses project.framework_id first).
 * Falls back to core/default if project.framework_id is empty OR if the column doesn't exist.
 */
if ( ! function_exists( 'icon_psy_get_framework_id_for_participant' ) ) {
	function icon_psy_get_framework_id_for_participant( $participant_id ) {
		global $wpdb;

		$participants_table = $wpdb->prefix . 'icon_psy_participants';
		$projects_table     = $wpdb->prefix . 'icon_psy_projects';
		$frameworks_table   = $wpdb->prefix . 'icon_psy_frameworks';

		static $has_project_framework_id = null;
		if ( null === $has_project_framework_id ) {
			$col = $wpdb->get_var(
				$wpdb->prepare(
					"SHOW COLUMNS FROM {$projects_table} LIKE %s",
					'framework_id'
				)
			);
			$has_project_framework_id = ! empty( $col );
		}

		if ( $has_project_framework_id ) {
			$framework_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT pr.framework_id
					 FROM {$projects_table} pr
					 INNER JOIN {$participants_table} p ON p.project_id = pr.id
					 WHERE p.id = %d
					 LIMIT 1",
					(int) $participant_id
				)
			);

			if ( $framework_id > 0 ) {
				return $framework_id;
			}
		}

		$default_id = (int) $wpdb->get_var(
			"SELECT id FROM {$frameworks_table} WHERE is_default = 1 LIMIT 1"
		);

		return $default_id > 0 ? $default_id : 0;
	}
}

/**
 * Convert "#RRGGBB" to "r,g,b" triplet for rgba(var(--x-rgb), alpha)
 */
if ( ! function_exists( 'icon_psy_hex_to_rgb_triplet' ) ) {
	function icon_psy_hex_to_rgb_triplet( $hex ) {
		$hex = trim( (string) $hex );
		if ( preg_match( '/^#([0-9a-fA-F]{6})$/', $hex, $m ) ) {
			$h = $m[1];
			$r = hexdec( substr( $h, 0, 2 ) );
			$g = hexdec( substr( $h, 2, 2 ) );
			$b = hexdec( substr( $h, 4, 2 ) );
			return $r . ',' . $g . ',' . $b; // "r,g,b"
		}
		return '21,160,109'; // fallback (Icon Green)
	}
}

/**
 * Robust branding fetcher.
 * This mirrors the "client portal" behaviour more closely by trying multiple helpers safely.
 *
 * Priority:
 * 1) icon_psy_get_branding_for_participant($participant_id)  (your standard)
 * 2) icon_psy_get_client_branding_for_project($project_id)  (project based)
 * 3) icon_psy_get_client_branding(...)                      (client-portal helper; signature can vary)
 * 4) Defaults
 *
 * Returns: array(logo_url, primary, secondary)
 */
if ( ! function_exists( 'icon_psy_safe_get_branding_context' ) ) {
	function icon_psy_safe_get_branding_context( $participant_id, $project_id, $client_name = '' ) {

		$out = array(
			'logo_url'  => '',
			'primary'   => '#15a06d', // Icon Green
			'secondary' => '#14a4cf', // Icon Blue
		);

		// Helper to merge values safely (accept common key aliases)
		$merge = function( $b ) use ( &$out ) {
			if ( ! is_array( $b ) ) { return; }

			// accept multiple key styles
			$logo = '';
			if ( ! empty( $b['logo_url'] ) ) { $logo = (string) $b['logo_url']; }
			elseif ( ! empty( $b['logo'] ) ) { $logo = (string) $b['logo']; }
			elseif ( ! empty( $b['brand_logo_url'] ) ) { $logo = (string) $b['brand_logo_url']; }

			$primary = '';
			if ( ! empty( $b['primary'] ) ) { $primary = (string) $b['primary']; }
			elseif ( ! empty( $b['primary_color'] ) ) { $primary = (string) $b['primary_color']; }
			elseif ( ! empty( $b['brand_primary'] ) ) { $primary = (string) $b['brand_primary']; }

			$secondary = '';
			if ( ! empty( $b['secondary'] ) ) { $secondary = (string) $b['secondary']; }
			elseif ( ! empty( $b['secondary_color'] ) ) { $secondary = (string) $b['secondary_color']; }
			elseif ( ! empty( $b['brand_secondary'] ) ) { $secondary = (string) $b['brand_secondary']; }

			if ( $logo ) { $out['logo_url'] = $logo; }
			if ( $primary ) { $out['primary'] = $primary; }
			if ( $secondary ) { $out['secondary'] = $secondary; }
		};

		// 1) Participant-based (most specific)
		if ( function_exists( 'icon_psy_get_branding_for_participant' ) && $participant_id ) {
			try {
				$merge( icon_psy_get_branding_for_participant( (int) $participant_id ) );
			} catch ( Throwable $e ) {}
		}

		// 2) Project-based
		if ( function_exists( 'icon_psy_get_client_branding_for_project' ) && $project_id ) {
			try { $merge( icon_psy_get_client_branding_for_project( (int) $project_id ) ); } catch ( Throwable $e ) {}
		}

		// 3) Client-portal helper (signature varies across installs)
		// We try several call patterns safely.
		if ( function_exists( 'icon_psy_get_client_branding' ) ) {
			$tries = array();

			// Most common patterns we’ve seen in portal files:
			$tries[] = array(); // icon_psy_get_client_branding()
			if ( $project_id )  { $tries[] = array( (int) $project_id ); }      // icon_psy_get_client_branding($project_id)
			if ( $client_name ) { $tries[] = array( (string) $client_name ); }  // icon_psy_get_client_branding($client_name)
			if ( $participant_id ) { $tries[] = array( (int) $participant_id, (int) $project_id, (string) $client_name ); }

			foreach ( $tries as $args ) {
				try {
					$b = call_user_func_array( 'icon_psy_get_client_branding', $args );
					if ( is_array( $b ) ) {
						$merge( $b );
						// If we have something meaningful, stop trying
						if ( ! empty( $out['logo_url'] ) || ( ! empty( $out['primary'] ) && ! empty( $out['secondary'] ) ) ) {
							break;
						}
					}
				} catch ( Throwable $e ) {
					// ignore signature/arg errors and keep trying
				}
			}
		}

		// Final sanitation
		$primary   = (string) $out['primary'];
		$secondary = (string) $out['secondary'];

		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $primary ) )   { $primary = '#15a06d'; }
		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $secondary ) ) { $secondary = '#14a4cf'; }

		$out['primary']   = $primary;
		$out['secondary'] = $secondary;

		if ( ! empty( $out['logo_url'] ) ) {
			$out['logo_url'] = esc_url_raw( $out['logo_url'] );
		}

		return $out;
	}
}

if ( ! function_exists( 'icon_psy_rater_survey_traits' ) ) {

	function icon_psy_rater_survey_traits( $atts ) {
		global $wpdb;

		$atts = shortcode_atts(
			array(
				'rater_id'    => 0,
				'token'       => '',
				'draft_id'    => 0,
				'phase'       => '',
				'pair_key'    => '',
				'force_self'  => '1', // default ON (self assessment)
			),
			$atts,
			'icon_psy_rater_survey_traits'
		);

		$rater_id = 0;
		$token    = '';

		if ( isset( $_GET['rater_id'] ) && $_GET['rater_id'] !== '' ) {
			$rater_id = (int) $_GET['rater_id'];
		} elseif ( ! empty( $atts['rater_id'] ) ) {
			$rater_id = (int) $atts['rater_id'];
		}

		if ( isset( $_GET['token'] ) && $_GET['token'] !== '' ) {
			$token = sanitize_text_field( wp_unslash( $_GET['token'] ) );
		} elseif ( ! empty( $atts['token'] ) ) {
			$token = sanitize_text_field( wp_unslash( $atts['token'] ) );
		}

		$draft_id_override = 0;
		if ( isset( $_GET['draft_id'] ) && $_GET['draft_id'] !== '' ) {
			$draft_id_override = (int) $_GET['draft_id'];
		} elseif ( ! empty( $atts['draft_id'] ) ) {
			$draft_id_override = (int) $atts['draft_id'];
		}

		// force self mode (default ON)
		$force_self = null;
		if ( isset( $_GET['force_self'] ) && $_GET['force_self'] !== '' ) {
			$force_self = (string) wp_unslash( $_GET['force_self'] );
		} else {
			$force_self = (string) $atts['force_self'];
		}
		$force_self_mode = ( $force_self !== '0' );

		$raters_table        = $wpdb->prefix . 'icon_psy_raters';
		$participants_table  = $wpdb->prefix . 'icon_psy_participants';
		$projects_table      = $wpdb->prefix . 'icon_psy_projects';
		$frameworks_table    = $wpdb->prefix . 'icon_psy_frameworks';
		$results_table       = $wpdb->prefix . 'icon_assessment_results';

		$ai_drafts_table     = $wpdb->prefix . 'icon_psy_ai_drafts';
		$ai_items_table      = $wpdb->prefix . 'icon_psy_ai_draft_competencies';

		// -----------------------------
		// 2) Look up rater (joined to participant/project)
		// -----------------------------
		$lookup_mode = $rater_id > 0 ? 'id' : 'token';
		$rater       = null;

		if ( $lookup_mode === 'id' && $rater_id > 0 ) {
			$rater = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						r.*,
						p.name         AS participant_name,
						p.role         AS participant_role,
						p.project_id   AS project_id,
						pr.name        AS project_name,
						pr.client_name AS client_name
					 FROM {$raters_table} r
					 LEFT JOIN {$participants_table} p ON r.participant_id = p.id
					 LEFT JOIN {$projects_table} pr   ON p.project_id     = pr.id
					 WHERE r.id = %d
					 LIMIT 1",
					$rater_id
				)
			);
		}

		if ( ! $rater && $token !== '' ) {
			$rater = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						r.*,
						p.name         AS participant_name,
						p.role         AS participant_role,
						p.project_id   AS project_id,
						pr.name        AS project_name,
						pr.client_name AS client_name
					 FROM {$raters_table} r
					 LEFT JOIN {$participants_table} p ON r.participant_id = p.id
					 LEFT JOIN {$projects_table} pr   ON p.project_id     = pr.id
					 WHERE (r.token = %s OR r.invite_token = %s)
					 LIMIT 1",
					$token,
					$token
				)
			);
		}

		$debug_html = '';
		if ( current_user_can( 'manage_options' ) ) {
			$debug_html  = '<div style="margin:8px 0;padding:8px 10px;border-radius:8px;';
			$debug_html .= 'border:1px solid #d1e7dd;background:#f0fdf4;font-size:11px;color:#0a3b34;">';
			$debug_html .= '<strong>ICON Technical Traits survey debug</strong><br>';
			$debug_html .= 'Lookup mode: ' . esc_html( $lookup_mode ) . '<br>';
			$debug_html .= 'Param rater_id: ' . esc_html( $rater_id ) . '<br>';
			$debug_html .= 'Param token: ' . esc_html( $token ? $token : '(none)' ) . '<br>';
			$debug_html .= 'Draft override: ' . esc_html( $draft_id_override ? (string) $draft_id_override : '(none)' ) . '<br>';
			$debug_html .= 'Force self mode: ' . esc_html( $force_self_mode ? 'YES' : 'NO' ) . '<br>';
			$debug_html .= 'Found rater row: ' . ( $rater ? 'YES' : 'NO' ) . '<br>';
			if ( ! empty( $wpdb->last_error ) ) {
				$debug_html .= 'Last DB error: ' . esc_html( $wpdb->last_error ) . '<br>';
			}
			$debug_html .= '</div>';
		}

		if ( ! $rater ) {
			ob_start();
			echo $debug_html;
			?>
			<div style="max-width:720px;margin:0 auto;padding:20px;">
				<div style="background:#fef2f2;border:1px solid #fecaca;padding:18px 16px;border-radius:14px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
					<h2 style="margin:0 0 6px;color:#7f1d1d;font-size:18px;">ICON Technical Traits</h2>
					<p style="margin:0 0 4px;font-size:13px;color:#581c1c;">Sorry, we could not find this self-assessment invitation.</p>
					<p style="margin:0;font-size:12px;color:#6b7280;">The link may have expired, already been used, or contains a typo.</p>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		$participant_id   = (int) $rater->participant_id;

		$project_id       = ! empty( $rater->project_id ) ? (int) $rater->project_id : 0;
		$participant_name = $rater->participant_name ?: 'Participant';
		$participant_role = $rater->participant_role;
		$project_name     = $rater->project_name ?: '';
		$client_name      = $rater->client_name ?: '';
		$rater_status     = $rater->status ? strtolower( (string) $rater->status ) : 'pending';

		// ------------------------------------------------------------
		// Branding (logo + colours) — pulled the same way as portal
		// ------------------------------------------------------------
		$brand_logo_url  = '';
		$brand_primary   = '#15a06d';
		$brand_secondary = '#14a4cf';

		$branding = icon_psy_safe_get_branding_context( $participant_id, $project_id, $client_name );
		if ( is_array( $branding ) ) {
			if ( ! empty( $branding['logo_url'] ) )  { $brand_logo_url  = (string) $branding['logo_url']; }
			if ( ! empty( $branding['primary'] ) )   { $brand_primary   = (string) $branding['primary']; }
			if ( ! empty( $branding['secondary'] ) ) { $brand_secondary = (string) $branding['secondary']; }
		}

		// Admin-only branding debug (so you can verify the resolver is working)
		if ( current_user_can( 'manage_options' ) ) {
			$cid_dbg = function_exists( 'icon_psy_get_client_user_id_for_context' )
				? (int) icon_psy_get_client_user_id_for_context( $participant_id, $project_id )
				: 0;

			$debug_html .= '<div style="margin:8px 0;padding:8px 10px;border-radius:8px;border:1px solid #dbeafe;background:#eff6ff;font-size:11px;color:#1e3a8a;">';
			$debug_html .= '<strong>Branding debug</strong><br>';
			$debug_html .= 'client_user_id resolved: ' . esc_html( (string) $cid_dbg ) . '<br>';
			$debug_html .= 'brand_primary: ' . esc_html( (string) $brand_primary ) . '<br>';
			$debug_html .= 'brand_secondary: ' . esc_html( (string) $brand_secondary ) . '<br>';
			$debug_html .= 'brand_logo_url: ' . esc_html( (string) $brand_logo_url ) . '<br>';
			$debug_html .= '</div>';
		}

		// Relationship (stored) + determine self mode
		$relationship_raw = isset( $rater->relationship ) ? strtolower( trim( (string) $rater->relationship ) ) : '';
		$is_self_mode     = $force_self_mode || ( $relationship_raw !== '' && strpos( $relationship_raw, 'self' ) !== false );

		// If forcing self, ensure relationship in payload looks like self (UI/payload consistency)
		if ( $is_self_mode && ( $relationship_raw === '' || strpos( $relationship_raw, 'self' ) === false ) ) {
			$relationship_raw = 'self';
		}

		// RATER display name (self mode uses this, NOT participant)
		$rater_display_name = '';
		foreach ( array( 'name', 'rater_name', 'full_name', 'display_name' ) as $k ) {
			if ( isset( $rater->{$k} ) && trim( (string) $rater->{$k} ) !== '' ) {
				$rater_display_name = trim( (string) $rater->{$k} );
				break;
			}
		}
		if ( $rater_display_name === '' ) {
			foreach ( array( 'email', 'rater_email' ) as $ek ) {
				if ( isset( $rater->{$ek} ) && trim( (string) $rater->{$ek} ) !== '' ) {
					$em = trim( (string) $rater->{$ek} );
					$rater_display_name = strstr( $em, '@', true ) ? strstr( $em, '@', true ) : $em;
					break;
				}
			}
		}
		if ( $rater_display_name === '' ) {
			$rater_display_name = 'You';
		}

		// -----------------------------
		// 2b) Phase + pair_key
		// -----------------------------
		$phase = 'baseline';
		if ( isset( $rater->phase ) && $rater->phase !== '' ) {
			$phase = sanitize_key( (string) $rater->phase );
		} else {
			$phase_candidate = '';
			if ( isset( $_GET['phase'] ) && $_GET['phase'] !== '' ) {
				$phase_candidate = sanitize_key( wp_unslash( $_GET['phase'] ) );
			} elseif ( ! empty( $atts['phase'] ) ) {
				$phase_candidate = sanitize_key( (string) $atts['phase'] );
			}
			if ( $phase_candidate ) { $phase = $phase_candidate; }
		}
		if ( ! in_array( $phase, array( 'baseline', 'post' ), true ) ) {
			$phase = 'baseline';
		}

		$pair_key = '';
		if ( isset( $rater->pair_key ) && $rater->pair_key !== '' ) {
			$pair_key = (string) $rater->pair_key;
		} else {
			if ( isset( $_GET['pair_key'] ) && $_GET['pair_key'] !== '' ) {
				$pair_key = sanitize_text_field( wp_unslash( $_GET['pair_key'] ) );
			} elseif ( ! empty( $atts['pair_key'] ) ) {
				$pair_key = sanitize_text_field( wp_unslash( (string) $atts['pair_key'] ) );
			}
		}

		// -----------------------------
		// 3) Resolve framework
		// -----------------------------
		$framework_id = icon_psy_get_framework_id_for_participant( $participant_id );

		$framework = null;
		if ( $framework_id > 0 ) {
			$framework = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$frameworks_table}
					 WHERE id = %d AND status IN ('active','published')
					 LIMIT 1",
					$framework_id
				)
			);
		}

		if ( ! $framework ) {
			$fallback_id = (int) $wpdb->get_var(
				"SELECT id FROM {$frameworks_table} WHERE is_default = 1 LIMIT 1"
			);

			if ( $fallback_id > 0 ) {
				$framework = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$frameworks_table}
						 WHERE id = %d AND status IN ('active','published')
						 LIMIT 1",
						$fallback_id
					)
				);
				$framework_id = $fallback_id;
			}
		}

		if ( ! $framework ) {
			ob_start();
			echo $debug_html;
			?>
			<div style="max-width:720px;margin:0 auto;padding:20px;">
				<div style="background:#fef2f2;border:1px solid #fecaca;padding:18px 16px;border-radius:14px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
					<h2 style="margin:0 0 6px;color:#7f1d1d;font-size:18px;">ICON Catalyst Framework</h2>
					<p style="margin:0 0 4px;font-size:13px;color:#581c1c;">No active ICON Catalyst framework is configured.</p>
					<p style="margin:0;font-size:12px;color:#6b7280;">Please create and publish a framework, then try this link again.</p>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		$framework_name = $framework->name ?: 'ICON Catalyst Framework';

		// -----------------------------
		// 4) Load TECHNICAL TRAITS (AI draft items)
		// -----------------------------
		$draft_row = null;

		if ( $draft_id_override > 0 ) {
			$draft_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$ai_drafts_table} WHERE id = %d LIMIT 1",
					$draft_id_override
				)
			);
		}

		if ( ! $draft_row ) {
			$draft_row = $wpdb->get_row(
				"SELECT * FROM {$ai_drafts_table}
				 ORDER BY created_at DESC, id DESC
				 LIMIT 1"
			);
		}

		$traits = array();
		$traits_draft_id = 0;
		$traits_draft_title = '';
		$traits_draft_created = '';

		if ( $draft_row ) {
			$traits_draft_id      = (int) $draft_row->id;
			$traits_draft_title   = (string) $draft_row->title;
			$traits_draft_created = (string) $draft_row->created_at;

			$traits = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, name, description, module, sort_order
					 FROM {$ai_items_table}
					 WHERE draft_id = %d
					 ORDER BY sort_order ASC, id ASC",
					$traits_draft_id
				)
			);
		}

		if ( empty( $traits ) ) {
			ob_start();
			echo $debug_html;
			?>
			<div style="max-width:820px;margin:0 auto;padding:20px;">
				<div style="background:#fefce8;border:1px solid #fde68a;padding:18px 16px;border-radius:14px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
					<h2 style="margin:0 0 6px;color:#78350f;font-size:18px;">Technical Traits not available</h2>
					<p style="margin:0 0 6px;font-size:13px;color:#92400e;">
						This survey is configured to use <strong>technical traits</strong> from the AI engine, but no AI draft items were found.
					</p>
					<p style="margin:0;font-size:12px;color:#6b7280;">
						Generate a Technical Traits draft in the AI Designer first (or pass a valid <code>?draft_id=</code> on the survey link).
					</p>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		$trait_items = array();
		foreach ( $traits as $t ) {
			$trait_key = 'ai_' . (int) $traits_draft_id . '_' . (int) $t->id;

			$trait_items[] = array(
				'trait_key'   => $trait_key,
				'item_id'     => (int) $t->id,
				'name'        => (string) $t->name,
				'description' => (string) ( $t->description ? $t->description : '' ),
				'module'      => (string) ( $t->module ? $t->module : 'core' ),
				'sort_order'  => (int) $t->sort_order,
			);
		}

		// -----------------------------
		// 4b) Results table column detection (optional columns)
		// -----------------------------
		static $results_cols = null;
		if ( null === $results_cols ) {
			$results_cols = array(
				'measurement_phase' => false,
				'pair_key'          => false,
			);
			$col1 = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$results_table} LIKE %s", 'measurement_phase' ) );
			$col2 = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$results_table} LIKE %s", 'pair_key' ) );
			$results_cols['measurement_phase'] = ! empty( $col1 );
			$results_cols['pair_key']          = ! empty( $col2 );
		}

		// -----------------------------
		// 5) Handle submission
		// -----------------------------
		$message       = '';
		$message_class = '';

		if ( isset( $_POST['icon_psy_rater_survey_traits_submitted'] ) && '1' === (string) $_POST['icon_psy_rater_survey_traits_submitted'] ) {

			check_admin_referer( 'icon_psy_rater_survey_traits' );

			if ( $rater_status === 'completed' ) {
				$message       = 'You have already submitted your self-assessment. Thank you.';
				$message_class = 'success';
			} else {

				$post_phase = isset( $_POST['measurement_phase'] ) ? sanitize_key( wp_unslash( $_POST['measurement_phase'] ) ) : $phase;
				if ( in_array( $post_phase, array( 'baseline', 'post' ), true ) ) {
					$phase = $post_phase;
				}

				$post_pair_key = isset( $_POST['pair_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pair_key'] ) ) : $pair_key;
				if ( $post_pair_key !== '' ) {
					$pair_key = $post_pair_key;
				}

				$scores   = isset( $_POST['scores'] ) && is_array( $_POST['scores'] ) ? $_POST['scores'] : array();
				$strength = isset( $_POST['q2_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['q2_text'] ) ) : '';
				$develop  = isset( $_POST['q3_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['q3_text'] ) ) : '';

				$detail_data = array();
				$all_numbers = array();

				foreach ( $trait_items as $t ) {
					$tkey = (string) $t['trait_key'];

					if ( ! isset( $scores[ $tkey ] ) || ! is_array( $scores[ $tkey ] ) ) {
						continue;
					}

					$c_q1 = isset( $scores[ $tkey ]['q1'] ) ? (int) $scores[ $tkey ]['q1'] : 0;
					$c_q2 = isset( $scores[ $tkey ]['q2'] ) ? (int) $scores[ $tkey ]['q2'] : 0;
					$c_q3 = isset( $scores[ $tkey ]['q3'] ) ? (int) $scores[ $tkey ]['q3'] : 0;

					$c_conf = isset( $scores[ $tkey ]['confidence'] ) ? (int) $scores[ $tkey ]['confidence'] : 3;
					$c_evidence = isset( $scores[ $tkey ]['evidence'] ) ? sanitize_textarea_field( wp_unslash( $scores[ $tkey ]['evidence'] ) ) : '';

					$c_q1   = max( 1, min( 7, $c_q1 ) );
					$c_q2   = max( 1, min( 7, $c_q2 ) );
					$c_q3   = max( 1, min( 7, $c_q3 ) );
					$c_conf = max( 1, min( 5, $c_conf ) );

					$detail_data[ $tkey ] = array(
						'type'            => 'technical_trait',
						'trait_key'       => $tkey,
						'trait_item_id'   => (int) $t['item_id'],
						'trait_name'      => (string) $t['name'],
						'trait_module'    => (string) $t['module'],
						'ai_draft_id'     => (int) $traits_draft_id,
						'ai_draft_title'  => (string) $traits_draft_title,
						'q1'              => $c_q1,
						'q2'              => $c_q2,
						'q3'              => $c_q3,
						'confidence'      => $c_conf,
						'evidence'        => $c_evidence,
					);

					$all_numbers[] = $c_q1;
					$all_numbers[] = $c_q2;
					$all_numbers[] = $c_q3;
				}

				if ( empty( $detail_data ) ) {
					$message       = 'Please score the technical traits before submitting your self-assessment.';
					$message_class = 'error';
				} else {

					$overall_rating = 0;
					if ( ! empty( $all_numbers ) ) {
						$overall_rating = array_sum( $all_numbers ) / count( $all_numbers );
					}

					$detail_json = wp_json_encode( array(
						'schema'            => 'icon_traits_v2',
						'trait_source'      => 'ai_draft',
						'measurement_phase' => $phase,
						'pair_key'          => $pair_key,
						'ai_draft_id'       => (int) $traits_draft_id,
						'ai_draft_title'    => (string) $traits_draft_title,
						'framework_id'      => (int) $framework_id,
						'participant_id'    => (int) $participant_id,
						'rater_id'          => (int) $rater->id,
						'relationship'      => (string) $relationship_raw,
						'items'             => $detail_data,
					) );

					// Base row payload
					$base_row = array(
						'participant_id' => $participant_id,
						'rater_id'       => (int) $rater->id,
						'project_id'     => $project_id,
						'framework_id'   => (int) $framework_id,
						'q1_rating'      => $overall_rating,
						'q2_text'        => $strength,
						'q3_text'        => $develop,
						'detail_json'    => $detail_json,
						'status'         => 'completed',
					);

					// Detect existing result row (so we UPDATE instead of INSERT duplicates)
					$where_sql  = "participant_id = %d AND rater_id = %d AND project_id = %d AND framework_id = %d";
					$where_args = array( $participant_id, (int) $rater->id, $project_id, (int) $framework_id );

					if ( ! empty( $results_cols['measurement_phase'] ) ) {
						$where_sql   .= " AND measurement_phase = %s";
						$where_args[] = $phase;
					}
					if ( ! empty( $results_cols['pair_key'] ) ) {
						if ( $pair_key !== '' ) {
							$where_sql   .= " AND pair_key = %s";
							$where_args[] = $pair_key;
						} else {
							$where_sql .= " AND (pair_key = '' OR pair_key IS NULL)";
						}
					}

					$existing_id = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$results_table} WHERE {$where_sql} ORDER BY id DESC LIMIT 1",
							$where_args
						)
					);

					$ok = false;

					if ( $existing_id > 0 ) {

						$update_row     = $base_row;
						$update_formats = array( '%d','%d','%d','%d','%f','%s','%s','%s','%s' );

						$ok = ( false !== $wpdb->update(
							$results_table,
							$update_row,
							array( 'id' => $existing_id ),
							$update_formats,
							array( '%d' )
						) );

					} else {

						$insert_row = $base_row + array(
							'created_at' => current_time( 'mysql' ),
						);

						$insert_formats = array( '%d','%d','%d','%d','%f','%s','%s','%s','%s','%s' );

						if ( ! empty( $results_cols['measurement_phase'] ) ) {
							$insert_row['measurement_phase'] = $phase;
							$insert_formats[] = '%s';
						}
						if ( ! empty( $results_cols['pair_key'] ) ) {
							$insert_row['pair_key'] = $pair_key;
							$insert_formats[] = '%s';
						}

						$ok = ( false !== $wpdb->insert( $results_table, $insert_row, $insert_formats ) );
					}

					if ( ! $ok ) {
						$message       = 'We hit a problem saving your self-assessment. Please try again.';
						$message_class = 'error';
					} else {

						$wpdb->update(
							$raters_table,
							array( 'status' => 'completed' ),
							array( 'id' => (int) $rater->id ),
							array( '%s' ),
							array( '%d' )
						);

						$message       = 'Saved — your technical traits self-assessment is complete.';
						$message_class = 'success';
						$rater_status  = 'completed';
					}
				}
			}
		}

		// -----------------------------
		// 6) If completed -> thanks panel
		// -----------------------------
		if ( $rater_status === 'completed' && $message_class !== 'error' ) {
			ob_start();
			echo $debug_html;

			$phase_label = ( $phase === 'post' ) ? 'Post-course' : 'Baseline';
			?>
			<div style="max-width:820px;margin:0 auto;padding:22px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
				<div style="background:linear-gradient(135deg,#ffffff 0, rgba(255,255,255,.9) 40%, #ffffff 100%);border-radius:18px;padding:20px 22px;border:1px solid rgba(0,0,0,0.06);box-shadow:0 16px 35px rgba(0,0,0,0.10);">
					<h2 style="margin:0 0 6px;font-size:20px;font-weight:800;color:#0a3b34;">ICON Technical Traits Self-Assessment</h2>

					<p style="margin:0 0 10px;font-size:13px;color:#425b56;">
						Self-assessment submitted for <strong><?php echo esc_html( $rater_display_name ); ?></strong>.
					</p>

					<div style="display:flex;flex-wrap:wrap;gap:6px;font-size:11px;margin-bottom:8px;">
						<?php if ( $project_name ) : ?>
							<span style="padding:3px 9px;border-radius:999px;border:1px solid rgba(0,0,0,0.10);background:#fff;color:#0a3b34;">Project: <?php echo esc_html( $project_name ); ?></span>
						<?php endif; ?>
						<?php if ( $client_name ) : ?>
							<span style="padding:3px 9px;border-radius:999px;border:1px solid rgba(0,0,0,0.10);background:#fff;color:#374151;">Client: <?php echo esc_html( $client_name ); ?></span>
						<?php endif; ?>
						<span style="padding:3px 9px;border-radius:999px;border:1px solid rgba(0,0,0,0.10);background:#fff;color:#374151;">Framework: <?php echo esc_html( $framework_name ); ?></span>
						<span style="padding:3px 9px;border-radius:999px;border:1px solid rgba(0,0,0,0.10);background:#fff;color:#1e3a8a;">Phase: <?php echo esc_html( $phase_label ); ?></span>
						<span style="padding:3px 9px;border-radius:999px;border:1px solid rgba(0,0,0,0.10);background:#fff;color:#166534;">Status: Completed</span>
					</div>

					<p style="margin:0;font-size:13px;color:#0f5132;">Thank you — it’s been recorded. You can now close this window.</p>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		// -----------------------------
		// 7) Survey UI
		// -----------------------------
		$trait_images = array(
            'https://icon-talent.org/wp-content/uploads/2025/12/Values-1.png',
            'https://icon-talent.org/wp-content/uploads/2025/12/ChatGPT-Image-Dec-12-2025-08_11_17-PM.png',
            'https://icon-talent.org/wp-content/uploads/2025/12/ChatGPT-Image-Dec-12-2025-08_13_29-PM.png',
            'https://icon-talent.org/wp-content/uploads/2025/12/ChatGPT-Image-Dec-12-2025-08_14_58-PM.png',
            'https://icon-talent.org/wp-content/uploads/2025/12/ChatGPT-Image-Dec-12-2025-08_18_37-PM.png',
            'https://icon-talent.org/wp-content/uploads/2025/12/ChatGPT-Image-Dec-12-2025-08_21_14-PM.png',
            'https://icon-talent.org/wp-content/uploads/2025/12/ChatGPT-Image-Dec-12-2025-08_23_36-PM.png',
            'https://icon-talent.org/wp-content/uploads/2025/12/ChatGPT-Image-Dec-12-2025-08_36_02-PM.png',
            'https://icon-talent.org/wp-content/uploads/2025/12/ChatGPT-Image-Dec-16-2025-08_27_11-PM.png',
            'https://icon-talent.org/wp-content/uploads/2025/12/ChatGPT-Image-Dec-16-2025-08_27_50-PM.png',
            'https://icon-talent.org/wp-content/uploads/2025/12/ChatGPT-Image-Dec-16-2025-08_40_15-PM.png',
            'https://icon-talent.org/wp-content/uploads/2025/12/ChatGPT-Image-Dec-16-2025-09_38_21-PM.png',
            'https://icon-talent.org/wp-content/uploads/2025/12/ChatGPT-Image-Dec-16-2025-09_42_23-PM.png',
            'https://icon-talent.org/wp-content/uploads/2025/12/ChatGPT-Image-Dec-16-2025-09_43_13-PM.png',
			'https://icon-talent.org/wp-content/uploads/2026/02/ChatGPT-Image-Feb-5-2026-11_34_56-AM.png',
			'https://icon-talent.org/wp-content/uploads/2026/02/ChatGPT-Image-Feb-5-2026-11_33_54-AM.png',
			'https://icon-talent.org/wp-content/uploads/2026/02/ChatGPT-Image-Feb-5-2026-11_31_05-AM.png',
			'https://icon-talent.org/wp-content/uploads/2026/02/ChatGPT-Image-Feb-5-2026-11_31_01-AM.png',
			'https://icon-talent.org/wp-content/uploads/2026/02/ChatGPT-Image-Feb-5-2026-11_28_48-AM.png',
			'https://icon-talent.org/wp-content/uploads/2026/02/ChatGPT-Image-Feb-5-2026-11_27_30-AM.png',
			'https://icon-talent.org/wp-content/uploads/2026/02/ChatGPT-Image-Feb-5-2026-11_25_29-AM.png',
			'https://icon-talent.org/wp-content/uploads/2026/02/ChatGPT-Image-Feb-5-2026-11_24_31-AM.png',
		);

		$q_hints_self = array(
			'q1' => array('Think about a normal week.', 'Use evidence: deliverables, decisions, outputs.', 'Score consistency, not intention.'),
			'q2' => array('Think about time pressure, ambiguity, or conflict.', 'Do I stay methodical and effective?', 'Score what happens in reality.'),
			'q3' => array('If someone copied my method, would it be a good standard?', 'Is quality visible and repeatable?', 'This is about impact.'),
		);

		$autosave_key = 'icon_psy_rater_survey_traits_' . ( $token ? $token : ( 'rid_' . (int) $rater->id ) ) . '_d' . (int) $traits_draft_id . '_p_' . $phase;
		$phase_label  = ( $phase === 'post' ) ? 'Post-course' : 'Baseline';

		ob_start();
		echo $debug_html;

		// Debug badge (fixed: inside PHP)
		SELF SURVEY FILE LOADED: v2026-02-03-XYZ123

		?>

		<div class="icon-psy-rater-survey-wrapper" style="max-width:980px;margin:0 auto;padding:22px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">

			<style>
				:root{
					--icon-green: <?php echo esc_html( $brand_primary ); ?>;
					--icon-blue: <?php echo esc_html( $brand_secondary ); ?>;

					--icon-green-rgb: <?php echo esc_html( icon_psy_hex_to_rgb_triplet( $brand_primary ) ); ?>;
					--icon-blue-rgb: <?php echo esc_html( icon_psy_hex_to_rgb_triplet( $brand_secondary ) ); ?>;

					--text-dark:#0a3b34;
					--text-mid:#425b56;
					--text-light:#6a837d;
				}
				.icon-psy-hero {
					background: radial-gradient(
						circle at top left,
						rgba(var(--icon-blue-rgb), 0.14) 0,
						rgba(var(--icon-green-rgb), 0.10) 45%,
						#ffffff 100%
					);
					border-radius: 20px;
					padding: 18px 20px 16px;
					border: 1px solid rgba(0,0,0,0.06);
					box-shadow: 0 20px 40px rgba(10,59,52,0.20);
					margin-bottom: 14px;
					position:relative;
				}
				.icon-psy-hero h2 { margin:0 0 6px;font-size:22px;font-weight:800;color:var(--text-dark);letter-spacing:.02em; }
				.icon-psy-hero p { margin:0;font-size:13px;color:var(--text-mid); }
				.icon-psy-chip-row{ display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;font-size:11px; }
				.icon-psy-chip{ padding:3px 9px;border-radius:999px;border:1px solid var(--icon-green);background:rgba(var(--icon-green-rgb),0.10);color:var(--text-dark); }
				.icon-psy-chip-muted{ padding:3px 9px;border-radius:999px;border:1px solid rgba(var(--icon-blue-rgb),0.35);background:rgba(var(--icon-blue-rgb),0.08);color:#0b3554; }

				.icon-psy-notice{ margin-bottom:14px;padding:10px 12px;border-radius:12px;border:1px solid;font-size:12px; }
				.icon-psy-notice.info{ border-color:#bee3f8;background:#eff6ff;color:#1e3a8a; }
				.icon-psy-notice.success{ border-color:#bbf7d0;background:#ecfdf5;color:#166534; }
				.icon-psy-notice.error{ border-color:#fecaca;background:#fef2f2;color:#b91c1c; }

				.icon-psy-progress-wrap{ background:#fff;border-radius:16px;border:1px solid rgba(0,0,0,0.06);box-shadow:0 10px 24px rgba(10,59,52,0.10);padding:12px 14px;margin-bottom:12px; }
				.icon-psy-progress-top{ display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:8px;font-size:12px;color:var(--text-mid); }
				.icon-psy-progress-bar{ height:8px;background:rgba(148,163,184,0.35);border-radius:999px;overflow:hidden; }
				.icon-psy-progress-fill{ height:100%;width:0%;background-image:linear-gradient(135deg,var(--icon-blue),var(--icon-green));border-radius:999px;transition:width .35s ease; }

				.icon-psy-instructions{
					background: linear-gradient(135deg, #ffffff 0%, rgba(var(--icon-green-rgb),0.06) 60%, rgba(var(--icon-blue-rgb),0.06) 100%);
					border-radius: 18px;
					border: 1px solid rgba(0,0,0,0.06);
					box-shadow: 0 10px 24px rgba(10,59,52,0.10);
					padding: 14px 16px;
					margin-bottom: 12px;
				}
				.icon-psy-instructions h3{ margin:0 0 6px;font-size:14px;font-weight:900;color:var(--text-dark); }
				.icon-psy-instructions ul{ margin:0 0 0 18px;padding:0;font-size:12px;color:var(--text-mid); }
				.icon-psy-instructions li{ margin:4px 0; }

				.icon-psy-card{ background:#fff;border-radius:18px;border:1px solid rgba(0,0,0,0.06);box-shadow:0 10px 24px rgba(10,59,52,0.10);padding:14px 16px 12px;margin-bottom:12px; }
				.icon-psy-card-grid{ display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:14px;align-items:start; }
				@media(max-width:860px){ .icon-psy-card-grid{ grid-template-columns:1fr; } }

				.icon-psy-title{ font-size:15px;font-weight:800;color:var(--text-dark);margin:0 0 4px; }
				.icon-psy-desc{ font-size:12px;color:var(--text-light);margin:0 0 10px;line-height:1.45; }

				.icon-psy-scale-block{ margin-bottom:12px; }
				.icon-psy-scale-label{ font-size:11px;color:var(--text-mid);margin-bottom:6px;font-weight:800; }
				.icon-psy-hint{ font-size:11px;color:var(--text-light);margin:-2px 0 8px;line-height:1.4; }
				.icon-psy-hint ul{ margin:6px 0 0 18px;padding:0; }
				.icon-psy-hint li{ margin:3px 0; }

				.icon-psy-scale{ display:grid;grid-template-columns:repeat(7,minmax(28px,1fr));gap:6px;align-items:start; }
				.icon-psy-scale-5{ display:grid;grid-template-columns:repeat(5,minmax(28px,1fr));gap:6px;align-items:start; }
				.icon-psy-scale input[type="radio"], .icon-psy-scale-5 input[type="radio"]{ display:none; }
				.icon-psy-scale label, .icon-psy-scale-5 label{
					position:relative;width:100%;min-height:34px;display:flex;align-items:flex-start;justify-content:center;
					cursor:pointer;user-select:none;
				}
				.icon-psy-scale label::before, .icon-psy-scale-5 label::before{
					content:"";width:14px;height:14px;border-radius:999px;border:2px solid rgba(107,114,128,0.55);
					background:#fff;margin-top:2px;transition:transform .10s ease,border-color .10s ease,box-shadow .10s ease,background .10s ease;
				}
				.icon-psy-scale label::after, .icon-psy-scale-5 label::after{
					content:attr(data-num);position:absolute;top:18px;font-size:11px;color:var(--text-mid);font-variant-numeric:tabular-nums;
				}
				.icon-psy-scale label:hover::before, .icon-psy-scale-5 label:hover::before{
					border-color:var(--icon-green);box-shadow:0 0 0 2px rgba(var(--icon-green-rgb),0.14);transform:translateY(-1px);
				}
				.icon-psy-scale input[type="radio"]:checked + label::before,
				.icon-psy-scale-5 input[type="radio"]:checked + label::before{
					border-color:var(--icon-green);background-image:linear-gradient(135deg,var(--icon-green),var(--icon-blue));
					box-shadow:0 10px 18px rgba(var(--icon-green-rgb),0.28);transform:translateY(-1px);
				}
				.icon-psy-scale input[type="radio"]:checked + label::after,
				.icon-psy-scale-5 input[type="radio"]:checked + label::after{ color:var(--text-dark);font-weight:900; }

				.icon-psy-image-box{
					border-radius: 16px;
					border: 1px solid rgba(0,0,0,0.06);
					background: radial-gradient(
						circle at top left,
						rgba(var(--icon-blue-rgb), 0.10),
						rgba(var(--icon-green-rgb), 0.08)
					);
					padding: 10px;
					box-shadow: 0 14px 32px rgba(0,0,0,0.06);
				}
				.icon-psy-image-box img{ width:100%;height:auto;border-radius:12px;display:block; }
				.icon-psy-image-placeholder{
					width:100%;aspect-ratio:4/3;border-radius:12px;border:1px dashed rgba(148,163,184,0.75);
					background:rgba(249,250,251,0.7);display:flex;align-items:center;justify-content:center;
					font-size:12px;color:var(--text-light);
				}

				.icon-psy-evidence{
					border-radius:14px;border:1px solid rgba(0,0,0,0.06);background:rgba(var(--icon-green-rgb),0.06);padding:10px 12px;margin:10px 0 4px;
				}
				.icon-psy-evidence-label{
					font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:4px;font-weight:800;
				}
				.icon-psy-evidence textarea{
					width:100%;min-height:70px;border-radius:10px;border:1px solid #cbd5e1;padding:7px 9px;font-size:13px;resize:vertical;
					color:var(--text-dark);background:#fff;
				}
				.icon-psy-evidence textarea:focus{ outline:none;border-color:var(--icon-green);box-shadow:0 0 0 1px rgba(var(--icon-green-rgb),0.40); }

				.icon-psy-comments-grid{ display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;margin-top:12px; }
				.icon-psy-comment-card{ border-radius:14px;border:1px solid rgba(0,0,0,0.06);background:rgba(var(--icon-green-rgb),0.06);padding:10px 12px;font-size:13px;color:var(--text-dark); }
				.icon-psy-comment-label{ font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:4px; }
				.icon-psy-comment-card textarea{
					width:100%;min-height:90px;border-radius:10px;border:1px solid #cbd5e1;padding:7px 9px;font-size:13px;resize:vertical;color:var(--text-dark);background:#fff;
				}
				.icon-psy-comment-card textarea:focus{ outline:none;border-color:var(--icon-green);box-shadow:0 0 0 1px rgba(var(--icon-green-rgb),0.40); }

				.icon-psy-btn-row{ margin-top:14px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap; }
				.icon-psy-btn-secondary{
					display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#fff;border:1px solid rgba(0,0,0,0.10);
					color:var(--icon-green);padding:9px 16px;font-size:13px;font-weight:800;cursor:pointer;text-decoration:none;white-space:nowrap;
				}
				.icon-psy-btn-secondary:hover{ background:rgba(var(--icon-blue-rgb),0.08);border-color:rgba(var(--icon-blue-rgb),0.35);color:var(--icon-blue); }
				.icon-psy-btn-primary{
					background:linear-gradient(135deg,var(--icon-green),var(--icon-blue));border:1px solid rgba(0,0,0,0.10);color:#fff;padding:9px 18px;border-radius:999px;
					font-size:13px;font-weight:900;cursor:pointer;box-shadow:0 14px 30px rgba(15,118,110,0.30);letter-spacing:.03em;text-transform:uppercase;
				}
				.icon-psy-btn-primary:hover{ box-shadow:0 18px 40px rgba(15,118,110,0.42);transform:translateY(-1px); }

				.icon-psy-anim-in{ animation: iconPsyIn .22s ease-out; }
				.icon-psy-anim-out{ animation: iconPsyOut .16s ease-in; }
				@keyframes iconPsyIn{ from{ opacity:0; transform: translateY(8px); } to{ opacity:1; transform: translateY(0); } }
				@keyframes iconPsyOut{ from{ opacity:1; transform: translateY(0); } to{ opacity:0; transform: translateY(-6px); } }
			</style>

			<!-- HERO/TOP CARD -->
			<div class="icon-psy-hero">
				<?php if ( ! empty( $brand_logo_url ) ) : ?>
					<div style="position:absolute;top:14px;right:16px;">
						<img
							src="<?php echo esc_url( $brand_logo_url ); ?>"
							alt=""
							style="max-height:44px;max-width:180px;object-fit:contain;"
							loading="lazy"
						/>
					</div>
				<?php endif; ?>

				<h2>ICON – Technical Traits Self-Assessment</h2>
				<p>
					This is a self-assessment for <strong><?php echo esc_html( $rater_display_name ); ?></strong>.
					Please answer honestly about yourself, based on your typical behaviour over the last 4–8 weeks (not your best day).
				</p>

				<div class="icon-psy-chip-row">
					<?php if ( $project_name ) : ?><span class="icon-psy-chip">Project: <?php echo esc_html( $project_name ); ?></span><?php endif; ?>
					<?php if ( $client_name ) : ?><span class="icon-psy-chip-muted">Client: <?php echo esc_html( $client_name ); ?></span><?php endif; ?>
					<span class="icon-psy-chip-muted">Framework: <?php echo esc_html( $framework_name ); ?></span>
					<span class="icon-psy-chip-muted">Phase: <?php echo esc_html( $phase_label ); ?></span>
					<span class="icon-psy-chip-muted">Trait draft: <?php echo esc_html( $traits_draft_title ? $traits_draft_title : ('Draft #' . (int) $traits_draft_id) ); ?></span>
					<?php if ( $traits_draft_created ) : ?><span class="icon-psy-chip-muted">Draft date: <?php echo esc_html( $traits_draft_created ); ?></span><?php endif; ?>
					<span class="icon-psy-chip-muted">Scale: 1 = very low, 7 = very high</span>
					<span class="icon-psy-chip">Private to me</span>
				</div>
			</div>

			<?php if ( $message ) : ?>
				<div class="icon-psy-notice <?php echo esc_attr( $message_class ); ?>"><?php echo esc_html( $message ); ?></div>
			<?php endif; ?>

			<?php if ( $rater_status !== 'completed' || $message_class === 'error' ) : ?>

				<div id="icon-psy-instructions-block" class="js-instructions-block">
					<div class="icon-psy-instructions">
						<h3>How to complete my self-assessment</h3>
						<ul>
							<li>I answer based on the last 4–8 weeks (not my best day).</li>
							<li>I use evidence: outputs, decisions, artefacts, and results.</li>
							<li>I stay honest. This is for my development, not a judgement.</li>
							<li>I must answer all required questions (including Confidence) before moving on.</li>
						</ul>
					</div>
				</div>

				<div class="icon-psy-progress-wrap" id="icon-psy-progress">
					<div class="icon-psy-progress-top">
						<div><strong>Progress</strong> <span id="icon-psy-progress-text" style="font-weight:600;"></span></div>
						<div id="icon-psy-autosave-status" style="font-size:11px;color:var(--text-light);">Autosave: on</div>
					</div>
					<div class="icon-psy-progress-bar"><div class="icon-psy-progress-fill" id="icon-psy-progress-fill"></div></div>
				</div>

				<form method="post" id="icon-psy-survey-form">
					<?php wp_nonce_field( 'icon_psy_rater_survey_traits' ); ?>
					<input type="hidden" name="icon_psy_rater_survey_traits_submitted" value="1" />
					<input type="hidden" name="rater_id" value="<?php echo esc_attr( (int) $rater->id ); ?>" />
					<input type="hidden" name="participant_id" value="<?php echo esc_attr( $participant_id ); ?>" />
					<input type="hidden" name="project_id" value="<?php echo esc_attr( $project_id ); ?>" />
					<input type="hidden" name="framework_id" value="<?php echo esc_attr( (int) $framework_id ); ?>" />
					<input type="hidden" name="ai_draft_id" value="<?php echo esc_attr( (int) $traits_draft_id ); ?>" />
					<input type="hidden" name="measurement_phase" value="<?php echo esc_attr( $phase ); ?>" />
					<input type="hidden" name="pair_key" value="<?php echo esc_attr( $pair_key ); ?>" />

					<div class="icon-psy-competency-list">
						<?php
						$total_steps = count( $trait_items );
						$idx = 0;

						foreach ( $trait_items as $t ) :
							$tkey = $t['trait_key'];
							$desc = $t['description'];
							$img_url = isset( $trait_images[ $idx ] ) ? $trait_images[ $idx ] : '';
							?>
							<div class="icon-psy-card js-competency-card"
								 data-step="<?php echo esc_attr( $idx ); ?>"
								 data-total="<?php echo esc_attr( $total_steps ); ?>"
								 style="<?php echo $idx === 0 ? '' : 'display:none;'; ?>">

								<div class="icon-psy-card-grid">
									<div>
										<p class="icon-psy-title"><?php echo esc_html( $t['name'] ); ?></p>
										<?php if ( $desc !== '' ) : ?><p class="icon-psy-desc"><?php echo esc_html( $desc ); ?></p><?php endif; ?>

										<div class="icon-psy-scale-block">
											<div class="icon-psy-scale-label">My day-to-day delivery</div>
											<div class="icon-psy-hint"><ul><?php foreach ( $q_hints_self['q1'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?></ul></div>
											<div class="icon-psy-scale">
												<?php for ( $i = 1; $i <= 7; $i++ ) : $id = "tkey_{$tkey}_q1_{$i}"; ?>
													<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="scores[<?php echo esc_attr( $tkey ); ?>][q1]" value="<?php echo esc_attr( $i ); ?>">
													<label for="<?php echo esc_attr( $id ); ?>" data-num="<?php echo esc_attr( $i ); ?>"></label>
												<?php endfor; ?>
											</div>
										</div>

										<div class="icon-psy-scale-block">
											<div class="icon-psy-scale-label">My delivery under pressure</div>
											<div class="icon-psy-hint"><ul><?php foreach ( $q_hints_self['q2'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?></ul></div>
											<div class="icon-psy-scale">
												<?php for ( $i = 1; $i <= 7; $i++ ) : $id = "tkey_{$tkey}_q2_{$i}"; ?>
													<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="scores[<?php echo esc_attr( $tkey ); ?>][q2]" value="<?php echo esc_attr( $i ); ?>">
													<label for="<?php echo esc_attr( $id ); ?>" data-num="<?php echo esc_attr( $i ); ?>"></label>
												<?php endfor; ?>
											</div>
										</div>

										<div class="icon-psy-scale-block">
											<div class="icon-psy-scale-label">How I set the quality standard</div>
											<div class="icon-psy-hint"><ul><?php foreach ( $q_hints_self['q3'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?></ul></div>
											<div class="icon-psy-scale">
												<?php for ( $i = 1; $i <= 7; $i++ ) : $id = "tkey_{$tkey}_q3_{$i}"; ?>
													<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="scores[<?php echo esc_attr( $tkey ); ?>][q3]" value="<?php echo esc_attr( $i ); ?>">
													<label for="<?php echo esc_attr( $id ); ?>" data-num="<?php echo esc_attr( $i ); ?>"></label>
												<?php endfor; ?>
											</div>
										</div>

										<div class="icon-psy-scale-block">
											<div class="icon-psy-scale-label">My confidence in this rating (required)</div>
											<div class="icon-psy-hint">1 = limited evidence, 5 = strong evidence from my recent work.</div>
											<div class="icon-psy-scale-5">
												<?php for ( $i = 1; $i <= 5; $i++ ) : $id = "tkey_{$tkey}_conf_{$i}"; ?>
													<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="scores[<?php echo esc_attr( $tkey ); ?>][confidence]" value="<?php echo esc_attr( $i ); ?>">
													<label for="<?php echo esc_attr( $id ); ?>" data-num="<?php echo esc_attr( $i ); ?>"></label>
												<?php endfor; ?>
											</div>
										</div>

										<div class="icon-psy-evidence">
											<div class="icon-psy-evidence-label">My evidence (optional)</div>
											<textarea
												class="js-evidence"
												name="scores[<?php echo esc_attr( $tkey ); ?>][evidence]"
												placeholder="Optional: a brief example from my work (what happened, what I delivered, the outcome)."></textarea>
										</div>

										<div class="icon-psy-btn-row">
											<button type="button" class="icon-psy-btn-secondary js-prev-card">Back</button>
											<?php if ( $idx === $total_steps - 1 ) : ?>
												<button type="button" class="icon-psy-btn-primary js-next-card">Finish</button>
											<?php else : ?>
												<button type="button" class="icon-psy-btn-primary js-next-card">Next</button>
											<?php endif; ?>
										</div>
									</div>

									<div>
										<div class="icon-psy-image-box">
											<?php if ( ! empty( $img_url ) ) : ?>
												<img src="<?php echo esc_url( $img_url ); ?>" alt="" loading="lazy" />
											<?php else : ?>
												<div class="icon-psy-image-placeholder">Image placeholder</div>
											<?php endif; ?>
										</div>
									</div>
								</div>
							</div>
							<?php
							$idx++;
						endforeach;
						?>
					</div>

					<div class="js-comments-block" style="display:none;">
						<div class="icon-psy-comments-grid">
							<div class="icon-psy-comment-card">
								<div class="icon-psy-comment-label">My key strengths</div>
								<textarea name="q2_text" id="icon-psy-q2-text" placeholder="What do I do particularly well in practice?"></textarea>
							</div>
							<div class="icon-psy-comment-card">
								<div class="icon-psy-comment-label">My development priorities</div>
								<textarea name="q3_text" id="icon-psy-q3-text" placeholder="Where should I focus next?"></textarea>
							</div>
						</div>

						<div style="margin-top:14px;display:flex;justify-content:flex-end;">
							<button type="submit" class="icon-psy-btn-primary" id="icon-psy-submit-final">Save my self-assessment</button>
						</div>
					</div>
				</form>

				<script>
				(function(){
					const autosaveKey = <?php echo wp_json_encode( $autosave_key ); ?>;
					const form = document.getElementById('icon-psy-survey-form');
					const cards = Array.from(document.querySelectorAll('.js-competency-card'));
					const totalSteps = cards.length;

					const progressText = document.getElementById('icon-psy-progress-text');
					const progressFill = document.getElementById('icon-psy-progress-fill');
					const autosaveStatus = document.getElementById('icon-psy-autosave-status');

					const commentsBlock = document.querySelector('.js-comments-block');
					const instructionsBlock = document.getElementById('icon-psy-instructions-block');

					let step = 0;

					function setAutosaveStatus(msg){
						if(!autosaveStatus) return;
						autosaveStatus.textContent = msg;
						clearTimeout(setAutosaveStatus._t);
						setAutosaveStatus._t = setTimeout(()=>{ autosaveStatus.textContent = 'Autosave: on'; }, 900);
					}

					function updateInstructions(){
						if(!instructionsBlock) return;
						instructionsBlock.style.display = (step === 0) ? 'block' : 'none';
					}

					function updateProgress(){
						const pct = totalSteps > 0 ? Math.round(((step+1) / totalSteps) * 100) : 0;
						if(progressText) progressText.textContent = `(${step+1} of ${totalSteps})`;
						if(progressFill) progressFill.style.width = pct + '%';
					}

					function showCard(newStep){
						if(newStep < 0 || newStep >= totalSteps) return;

						const current = cards[step];
						const next = cards[newStep];

						if(commentsBlock){
							commentsBlock.style.display = (newStep === totalSteps - 1) ? 'block' : 'none';
						}

						if(current){
							current.classList.remove('icon-psy-anim-in');
							current.classList.add('icon-psy-anim-out');
							setTimeout(()=>{ current.style.display = 'none'; }, 140);
						}

						next.style.display = 'block';
						next.classList.remove('icon-psy-anim-out');
						next.classList.add('icon-psy-anim-in');

						step = newStep;

						updateInstructions();
						updateProgress();
						window.scrollTo({ top: 0, behavior: 'smooth' });
					}

					function currentCardAnswered(){
						const card = cards[step];
						if(!card) return false;
						const checked = card.querySelectorAll('input[type="radio"]:checked').length;
						return checked >= 4; // q1,q2,q3,confidence
					}

					function serializeForm(){
						const data = {};
						const radios = form.querySelectorAll('input[type="radio"]:checked');
						radios.forEach((i)=>{ data[i.name] = i.value; });

						const textareas = form.querySelectorAll('textarea');
						textareas.forEach((t)=>{ data[t.name] = t.value || ''; });

						const hidden = form.querySelectorAll('input[type="hidden"]');
						hidden.forEach((h)=>{ if(h.name) data[h.name] = h.value; });

						data['_step'] = step;
						return data;
					}

					function restoreForm(saved){
						if(!saved) return;

						Object.keys(saved).forEach((k)=>{
							if(k === '_step') return;

							if(k.startsWith('scores[') && k.includes('][') && !k.endsWith('][evidence]')) {
								const val = saved[k];
								const selector = `input[type="radio"][name="${CSS.escape(k)}"][value="${CSS.escape(String(val))}"]`;
								const el = form.querySelector(selector);
								if(el) el.checked = true;
								return;
							}

							const ta = form.querySelector(`textarea[name="${CSS.escape(k)}"]`);
							if(ta && typeof saved[k] === 'string') ta.value = saved[k];
						});

						if(typeof saved._step === 'number' && saved._step >= 0 && saved._step < totalSteps){
							cards.forEach((c, idx)=>{ c.style.display = (idx === saved._step) ? 'block' : 'none'; });
							step = saved._step;
						} else {
							cards.forEach((c, idx)=>{ c.style.display = (idx === 0) ? 'block' : 'none'; });
							step = 0;
						}

						if(commentsBlock){
							commentsBlock.style.display = (step === totalSteps - 1) ? 'block' : 'none';
						}

						updateInstructions();
						updateProgress();
					}

					function saveNow(){
						try{
							localStorage.setItem(autosaveKey, JSON.stringify(serializeForm()));
							setAutosaveStatus('Autosaved ✓');
						}catch(e){}
					}

					try{
						const raw = localStorage.getItem(autosaveKey);
						if(raw){
							restoreForm(JSON.parse(raw));
						}else{
							updateInstructions();
							updateProgress();
						}
					}catch(e){
						updateInstructions();
						updateProgress();
					}

					document.addEventListener('change', function(e){
						if(!form.contains(e.target)) return;
						saveNow();
					});
					document.addEventListener('input', function(e){
						if(!form.contains(e.target)) return;
						if(e.target && e.target.tagName === 'TEXTAREA'){
							saveNow();
						}
					});

					document.addEventListener('click', function(e){
						const nextBtn = e.target.closest('.js-next-card');
						const prevBtn = e.target.closest('.js-prev-card');

						if(prevBtn){
							e.preventDefault();
							if(step === 0) return;
							showCard(step - 1);
							saveNow();
							return;
						}

						if(nextBtn){
							e.preventDefault();

							if(!currentCardAnswered()){
								alert('Please answer all required questions (including Confidence) before continuing.');
								return;
							}

							if(step === totalSteps - 1){
								if(commentsBlock){
									commentsBlock.style.display = 'block';
									window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
								}
								saveNow();
								return;
							}

							showCard(step + 1);
							saveNow();
						}
					});

					form.addEventListener('submit', function(){
						try{ localStorage.removeItem(autosaveKey); }catch(e){}
					});
				})();
				</script>

			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}

add_shortcode( 'icon_psy_rater_survey_traits', 'icon_psy_rater_survey_traits' );
