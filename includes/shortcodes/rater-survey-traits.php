<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ICON Catalyst – Technical Traits Survey (Rater + Self mode)
 *
 * Shortcode: [icon_psy_rater_survey_traits]
 * Typical use:
 * - Raters arrive via token link -> /rater-survey-traits/?token=XXXX
 * - Self-assessment uses SAME survey, but the rater.relationship contains "self"
 *
 * Trait source:
 * - Pulls “technical traits” from the AI engine draft table:
 *     wp_icon_psy_ai_draft_competencies  (items)
 *     wp_icon_psy_ai_drafts             (header)
 * - By default uses the MOST RECENT draft (any status).
 * - Optional override via URL/shortcode:
 *     ?draft_id=123  or  [icon_psy_rater_survey_traits draft_id="123"]
 *
 * Framework selection:
 * - DOES NOT change at this stage.
 * - We still resolve framework_id the same way (project -> default) and store it in results.
 *
 * Data writes to: wp_icon_assessment_results (your $results_table)
 * Marks rater as completed in: wp_icon_psy_raters
 *
 * PATCH (THIS REQUEST):
 * - Pull branding (logo + colours) the same way the portal effectively does:
 *   - safe helper calls (participant/project/client)
 *   - plus DB fallbacks (clients / client_branding / projects columns)
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
 * Helpers: table exists + columns cache
 */
if ( ! function_exists( 'icon_psy_table_exists' ) ) {
	function icon_psy_table_exists( $table ) {
		global $wpdb;
		$like  = $wpdb->esc_like( $table );
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $like ) );
		return ! empty( $found );
	}
}
if ( ! function_exists( 'icon_psy_columns_map' ) ) {
	function icon_psy_columns_map( $table ) {
		global $wpdb;
		static $cache = array();
		if ( isset( $cache[ $table ] ) ) return $cache[ $table ];

		$cols = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
		$map  = array();
		if ( is_array( $cols ) ) {
			foreach ( $cols as $c ) {
				if ( ! empty( $c['Field'] ) ) $map[ $c['Field'] ] = true;
			}
		}
		$cache[ $table ] = $map;
		return $map;
	}
}

/**
 * Robust branding fetcher for this survey (portal-like fallbacks).
 *
 * Returns: array(logo_url, primary, secondary, source)
 */
if ( ! function_exists( 'icon_psy_safe_get_branding_context' ) ) {
	function icon_psy_safe_get_branding_context( $participant_id, $project_id, $client_name = '', $client_id = 0 ) {
		global $wpdb;

		$out = array(
			'logo_url'  => '',
			'primary'   => '#15a06d',
			'secondary' => '#14a4cf',
			'source'    => 'defaults',
		);

		$merge = function( $b, $src = '' ) use ( &$out ) {
			if ( ! is_array( $b ) ) return;

			$changed = false;

			// common key variants we might see across files
			$logo_candidates = array( 'logo_url', 'logo', 'brand_logo_url', 'client_logo_url', 'logo_src' );
			foreach ( $logo_candidates as $k ) {
				if ( ! empty( $b[ $k ] ) && is_string( $b[ $k ] ) ) {
					$out['logo_url'] = (string) $b[ $k ];
					$changed = true;
					break;
				}
			}

			$primary_candidates = array( 'primary', 'primary_color', 'primary_colour', 'brand_primary', 'brand_primary_color' );
			foreach ( $primary_candidates as $k ) {
				if ( ! empty( $b[ $k ] ) && is_string( $b[ $k ] ) ) {
					$out['primary'] = (string) $b[ $k ];
					$changed = true;
					break;
				}
			}

			$secondary_candidates = array( 'secondary', 'secondary_color', 'secondary_colour', 'brand_secondary', 'brand_secondary_color' );
			foreach ( $secondary_candidates as $k ) {
				if ( ! empty( $b[ $k ] ) && is_string( $b[ $k ] ) ) {
					$out['secondary'] = (string) $b[ $k ];
					$changed = true;
					break;
				}
			}

			if ( $changed && $src ) {
				$out['source'] = $src;
			}
		};

		// 1) Participant helper (if present)
		if ( function_exists( 'icon_psy_get_branding_for_participant' ) && $participant_id ) {
			try {
				$b = icon_psy_get_branding_for_participant( (int) $participant_id );
				$merge( $b, 'icon_psy_get_branding_for_participant' );
			} catch ( Throwable $e ) {}
		}

		// 2) Project helper (if present) — ADDED
		if ( function_exists( 'icon_psy_get_client_branding_for_project' ) && $project_id ) {
			try {
				$b = icon_psy_get_client_branding_for_project( (int) $project_id );
				$merge( $b, 'icon_psy_get_client_branding_for_project' );
			} catch ( Throwable $e ) {}
		}

		// 3) Generic helper exists in your install
		if ( function_exists( 'icon_psy_get_client_branding' ) ) {
			$tries = array();

			// Try signatures commonly used across installs
			$tries[] = array(); // icon_psy_get_client_branding()
			if ( $client_name ) $tries[] = array( (string) $client_name ); // by client name
			if ( $project_id )  $tries[] = array( (int) $project_id );     // by project id
			if ( $client_id )   $tries[] = array( (int) $client_id );      // by client id
			if ( $participant_id && $project_id ) $tries[] = array( (int) $participant_id, (int) $project_id, (string) $client_name );

			foreach ( $tries as $args ) {
				try {
					$b = call_user_func_array( 'icon_psy_get_client_branding', $args );
					if ( is_array( $b ) ) {
						$merge( $b, 'icon_psy_get_client_branding(' . count( $args ) . ' args)' );
						// If we got a logo OR custom colours, that's enough.
						if ( ! empty( $out['logo_url'] ) || ( $out['primary'] !== '#15a06d' || $out['secondary'] !== '#14a4cf' ) ) {
							break;
						}
					}
				} catch ( Throwable $e ) {}
			}
		}

		// 4) DB fallback: clients table
		$clients_table = $wpdb->prefix . 'icon_psy_clients';
		if ( empty( $out['logo_url'] ) && icon_psy_table_exists( $clients_table ) && $client_name ) {
			$cols = icon_psy_columns_map( $clients_table );

			// pick best available column names
			$col_name = isset( $cols['name'] ) ? 'name' : ( isset( $cols['client_name'] ) ? 'client_name' : '' );

			if ( $col_name ) {
				$select = array();
				foreach ( array( 'logo_url','logo','brand_logo_url','client_logo_url' ) as $c ) { if ( isset( $cols[ $c ] ) ) $select[] = $c; }
				foreach ( array( 'primary','primary_color','primary_colour','brand_primary','brand_primary_color' ) as $c ) { if ( isset( $cols[ $c ] ) ) $select[] = $c; }
				foreach ( array( 'secondary','secondary_color','secondary_colour','brand_secondary','brand_secondary_color' ) as $c ) { if ( isset( $cols[ $c ] ) ) $select[] = $c; }

				if ( ! empty( $select ) ) {
					$sql = "SELECT " . implode( ',', array_map( 'sanitize_key', $select ) ) . " FROM {$clients_table} WHERE {$col_name} = %s LIMIT 1";
					$row = $wpdb->get_row( $wpdb->prepare( $sql, (string) $client_name ), ARRAY_A );
					if ( is_array( $row ) ) {
						$merge( $row, 'DB:' . $clients_table );
					}
				}
			}
		}

		// 5) DB fallback: client branding table
		$branding_table = $wpdb->prefix . 'icon_psy_client_branding';
		if ( ( empty( $out['logo_url'] ) || ( $out['primary'] === '#15a06d' && $out['secondary'] === '#14a4cf' ) ) && icon_psy_table_exists( $branding_table ) ) {
			$cols = icon_psy_columns_map( $branding_table );

			$where = '';
			$args  = array();
			if ( $project_id && isset( $cols['project_id'] ) ) {
				$where  = 'project_id = %d';
				$args[] = (int) $project_id;
			} elseif ( $client_name && ( isset( $cols['client_name'] ) || isset( $cols['name'] ) ) ) {
				$where  = ( isset( $cols['client_name'] ) ? 'client_name' : 'name' ) . ' = %s';
				$args[] = (string) $client_name;
			}

			if ( $where ) {
				$select = array();
				foreach ( array( 'logo_url','logo','brand_logo_url','client_logo_url' ) as $c ) { if ( isset( $cols[ $c ] ) ) $select[] = $c; }
				foreach ( array( 'primary','primary_color','primary_colour','brand_primary','brand_primary_color' ) as $c ) { if ( isset( $cols[ $c ] ) ) $select[] = $c; }
				foreach ( array( 'secondary','secondary_color','secondary_colour','brand_secondary','brand_secondary_color' ) as $c ) { if ( isset( $cols[ $c ] ) ) $select[] = $c; }

				if ( ! empty( $select ) ) {
					$sql = "SELECT " . implode( ',', array_map( 'sanitize_key', $select ) ) . " FROM {$branding_table} WHERE {$where} ORDER BY id DESC LIMIT 1";
					$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
					if ( is_array( $row ) ) {
						$merge( $row, 'DB:' . $branding_table );
					}
				}
			}
		}

		// 6) DB fallback: projects table columns
		$projects_table = $wpdb->prefix . 'icon_psy_projects';
		if ( $project_id && icon_psy_table_exists( $projects_table ) ) {
			$cols = icon_psy_columns_map( $projects_table );

			$select = array();
			foreach ( array( 'logo_url','client_logo_url','brand_logo_url','logo' ) as $c ) { if ( isset( $cols[ $c ] ) ) $select[] = $c; }
			foreach ( array( 'primary','primary_color','primary_colour','brand_primary','brand_primary_color' ) as $c ) { if ( isset( $cols[ $c ] ) ) $select[] = $c; }
			foreach ( array( 'secondary','secondary_color','secondary_colour','brand_secondary','brand_secondary_color' ) as $c ) { if ( isset( $cols[ $c ] ) ) $select[] = $c; }

			if ( ! empty( $select ) ) {
				$sql = "SELECT " . implode( ',', array_map( 'sanitize_key', $select ) ) . " FROM {$projects_table} WHERE id = %d LIMIT 1";
				$row = $wpdb->get_row( $wpdb->prepare( $sql, (int) $project_id ), ARRAY_A );
				if ( is_array( $row ) ) {
					$merge( $row, 'DB:' . $projects_table );
				}
			}
		}

		// sanitize hex colours
		$primary   = (string) $out['primary'];
		$secondary = (string) $out['secondary'];

		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $primary ) )   { $primary = '#15a06d'; }
		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $secondary ) ) { $secondary = '#14a4cf'; }

		$out['primary']   = $primary;
		$out['secondary'] = $secondary;

		if ( ! empty( $out['logo_url'] ) ) {
			$out['logo_url'] = esc_url_raw( (string) $out['logo_url'] );
		} else {
			$out['logo_url'] = '';
		}

		return $out;
	}
}

if ( ! function_exists( 'icon_psy_rater_survey_traits' ) ) {

	function icon_psy_rater_survey_traits( $atts ) {
		global $wpdb;

		$atts = shortcode_atts(
			array(
				'rater_id'   => 0,
				'token'      => '',
				'draft_id'   => 0,
				'force_self' => '1',
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

		$force_self = (string) $atts['force_self'];
		if ( isset( $_GET['force_self'] ) && $_GET['force_self'] !== '' ) {
			$force_self = (string) wp_unslash( $_GET['force_self'] );
		}
		$force_self_mode = ( $force_self !== '0' );

		$raters_table        = $wpdb->prefix . 'icon_psy_raters';
		$participants_table  = $wpdb->prefix . 'icon_psy_participants';
		$projects_table      = $wpdb->prefix . 'icon_psy_projects';
		$frameworks_table    = $wpdb->prefix . 'icon_psy_frameworks';
		$results_table       = $wpdb->prefix . 'icon_assessment_results';

		$ai_drafts_table     = $wpdb->prefix . 'icon_psy_ai_drafts';
		$ai_items_table      = $wpdb->prefix . 'icon_psy_ai_draft_competencies';

		$lookup_mode = $rater_id > 0 ? 'id' : 'token';
		$rater       = null;

		// Does projects table have client_id? (optional schema)
		static $has_project_client_id = null;
		if ( null === $has_project_client_id ) {
			$col = $wpdb->get_var(
				$wpdb->prepare(
					"SHOW COLUMNS FROM {$projects_table} LIKE %s",
					'client_id'
				)
			);
			$has_project_client_id = ! empty( $col );
		}

		// Dynamic select fragment (prevents SQL failing if column missing)
		$select_project_client_id = $has_project_client_id ? ", pr.client_id AS client_id" : ", 0 AS client_id";

		// -----------------------------------------------------------------
		// Lookup by rater_id  (FIXED: no PHP echo inside SQL string)
		// -----------------------------------------------------------------
		if ( $lookup_mode === 'id' && $rater_id > 0 ) {

			$sql = "SELECT
				r.*,
				p.name         AS participant_name,
				p.role         AS participant_role,
				p.project_id   AS project_id,
				pr.name        AS project_name,
				pr.client_name AS client_name
				{$select_project_client_id}
			 FROM {$raters_table} r
			 LEFT JOIN {$participants_table} p ON r.participant_id = p.id
			 LEFT JOIN {$projects_table} pr   ON p.project_id     = pr.id
			 WHERE r.id = %d
			 LIMIT 1";

			$rater = $wpdb->get_row( $wpdb->prepare( $sql, $rater_id ) );
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
						{$select_project_client_id}
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
			$debug_html .= 'Force self: ' . esc_html( $force_self_mode ? 'YES' : 'NO' ) . '<br>';
			$debug_html .= 'Found rater row: ' . ( $rater ? 'YES' : 'NO' ) . '<br>';
			if ( ! empty( $wpdb->last_error ) ) {
				$debug_html .= 'Last DB error: ' . esc_html( $wpdb->last_error ) . '<br>';
			}
			$debug_html .= '<div style="margin:8px 0 0;padding:8px 10px;border-radius:8px;border:1px solid #e5e7eb;background:#fff;font-size:11px;color:#111;">';
			$debug_html .= '<strong>HARD PROOF</strong><br>';
			$debug_html .= 'PHP file: <code>' . esc_html( __FILE__ ) . '</code><br>';
			$debug_html .= 'Function: <code>icon_psy_rater_survey_traits()</code><br>';
			$debug_html .= 'Shortcode tag: <code>icon_psy_rater_survey_traits</code><br>';
			$debug_html .= 'Has icon_psy_get_client_branding(): ' . ( function_exists('icon_psy_get_client_branding') ? 'YES' : 'NO' ) . '<br>';
			$debug_html .= 'Has icon_psy_get_client_branding_for_project(): ' . ( function_exists('icon_psy_get_client_branding_for_project') ? 'YES' : 'NO' ) . '<br>';
			$debug_html .= '</div>';
			$debug_html .= '</div>';
		}

		if ( ! $rater ) {
			ob_start();
			echo $debug_html;
			?>
			<div style="max-width:720px;margin:0 auto;padding:20px;">
				<div style="background:#fef2f2;border:1px solid #fecaca;padding:18px 16px;border-radius:14px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
					<h2 style="margin:0 0 6px;color:#7f1d1d;font-size:18px;">ICON Technical Traits Feedback</h2>
					<p style="margin:0 0 4px;font-size:13px;color:#581c1c;">Sorry, we could not find this feedback invitation.</p>
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
		$client_id        = ! empty( $rater->client_id ) ? (int) $rater->client_id : 0;
		$rater_status     = $rater->status ? strtolower( (string) $rater->status ) : 'pending';

		$relationship_raw = isset( $rater->relationship ) ? strtolower( trim( (string) $rater->relationship ) ) : '';
		$is_self_mode     = $force_self_mode || ( $relationship_raw !== '' && strpos( $relationship_raw, 'self' ) !== false );

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
		if ( $rater_display_name === '' ) $rater_display_name = 'You';

		// -----------------------------
		// Branding (logo + colours) — robust resolver
		// -----------------------------
		$brand_logo_url  = '';
		$brand_primary   = '#15a06d';
		$brand_secondary = '#14a4cf';
		$brand_source    = 'defaults';

		$branding = icon_psy_safe_get_branding_context( $participant_id, $project_id, $client_name, $client_id );

		// Add raw branding debug
		if ( current_user_can( 'manage_options' ) ) {
			$debug_html .= '<div style="margin:8px 0;padding:8px 10px;border-radius:8px;border:1px solid #e5e7eb;background:#fff;font-size:11px;color:#111;">';
			$debug_html .= '<strong>Branding RAW</strong><br>';
			$debug_html .= '<pre style="margin:6px 0 0;white-space:pre-wrap;">' . esc_html( print_r( $branding, true ) ) . '</pre>';
			$debug_html .= '</div>';
		}

		if ( is_array( $branding ) ) {
			if ( ! empty( $branding['logo_url'] ) )  { $brand_logo_url  = (string) $branding['logo_url']; }
			if ( ! empty( $branding['primary'] ) )   { $brand_primary   = (string) $branding['primary']; }
			if ( ! empty( $branding['secondary'] ) ) { $brand_secondary = (string) $branding['secondary']; }
			if ( ! empty( $branding['source'] ) )    { $brand_source    = (string) $branding['source']; }
		}

		if ( current_user_can( 'manage_options' ) ) {
			$debug_html .= '<div style="margin:8px 0;padding:8px 10px;border-radius:8px;border:1px solid #dbeafe;background:#eff6ff;font-size:11px;color:#1e3a8a;">';
			$debug_html .= '<strong>Branding context</strong><br>';
			$debug_html .= 'Resolved participant_id: ' . esc_html( $participant_id ) . '<br>';
			$debug_html .= 'Resolved project_id: ' . esc_html( $project_id ) . '<br>';
			$debug_html .= 'Resolved project_name: ' . esc_html( $project_name ) . '<br>';
			$debug_html .= 'Resolved client_name: ' . esc_html( $client_name ) . '<br>';
			$debug_html .= 'Resolved client_id: ' . esc_html( (int) $client_id ) . '<br>';
			$debug_html .= 'Resolved primary: ' . esc_html( $brand_primary ) . '<br>';
			$debug_html .= 'Resolved secondary: ' . esc_html( $brand_secondary ) . '<br>';
			$debug_html .= 'Resolved logo_url: ' . esc_html( $brand_logo_url ? $brand_logo_url : '(none)' ) . '<br>';
			$debug_html .= 'Brand source: ' . esc_html( $brand_source ) . '<br>';
			$debug_html .= '</div>';
		}

		// -----------------------------
		// 3) Resolve framework (unchanged)
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
					<p style="margin:0 0 4px;font-size:13px;color:#581c1c;">No active ICON Catalyst leadership framework is configured.</p>
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

		$traits              = array();
		$traits_draft_id     = 0;
		$traits_draft_title  = '';
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
		// 5) Handle submission (UNCHANGED)
		// -----------------------------
		$message       = '';
		$message_class = '';

		if ( isset( $_POST['icon_psy_rater_survey_traits_submitted'] ) && '1' === (string) $_POST['icon_psy_rater_survey_traits_submitted'] ) {

			check_admin_referer( 'icon_psy_rater_survey_traits' );

			if ( $rater_status === 'completed' ) {
				$message       = $is_self_mode ? 'Your self-assessment has already been submitted. Thank you.' : 'Your feedback has already been submitted. Thank you.';
				$message_class = 'success';
			} else {

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

					$c_q1 = max( 1, min( 7, $c_q1 ) );
					$c_q2 = max( 1, min( 7, $c_q2 ) );
					$c_q3 = max( 1, min( 7, $c_q3 ) );

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
					);

					$all_numbers[] = $c_q1;
					$all_numbers[] = $c_q2;
					$all_numbers[] = $c_q3;
				}

				if ( empty( $detail_data ) ) {
					$message       = $is_self_mode ? 'Please score the technical traits before submitting your self-assessment.' : 'Please provide ratings across the technical traits before submitting.';
					$message_class = 'error';
				} else {

					$overall_rating = 0;
					if ( ! empty( $all_numbers ) ) {
						$overall_rating = array_sum( $all_numbers ) / count( $all_numbers );
					}

					$detail_json = wp_json_encode( array(
						'schema'       => 'icon_traits_v1',
						'trait_source' => 'ai_draft',
						'ai_draft_id'  => (int) $traits_draft_id,
						'items'        => $detail_data,
					) );

					$inserted = $wpdb->insert(
						$results_table,
						array(
							'participant_id' => $participant_id,
							'rater_id'       => (int) $rater->id,
							'project_id'     => $project_id,
							'framework_id'   => (int) $framework_id,
							'q1_rating'      => $overall_rating,
							'q2_text'        => $strength,
							'q3_text'        => $develop,
							'detail_json'    => $detail_json,
							'status'         => 'completed',
							'created_at'     => current_time( 'mysql' ),
						),
						array( '%d','%d','%d','%d','%f','%s','%s','%s','%s','%s' )
					);

					if ( false === $inserted ) {
						$message       = $is_self_mode ? 'We hit a problem saving your self-assessment. Please try again.' : 'We hit a problem saving your feedback. Please try again.';
						$message_class = 'error';
					} else {

						$wpdb->update(
							$raters_table,
							array( 'status' => 'completed' ),
							array( 'id' => (int) $rater->id ),
							array( '%s' ),
							array( '%d' )
						);

						$message       = $is_self_mode ? 'Saved — your technical traits self-assessment is complete.' : 'Thank you – your technical traits feedback has been submitted.';
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
			?>
			<div style="max-width:820px;margin:0 auto;padding:22px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
				<div style="background:linear-gradient(135deg,#ecfdf5 0,#e0f2fe 60%,#ffffff 100%);border-radius:18px;padding:20px 22px;border:1px solid #bbf7d0;box-shadow:0 16px 35px rgba(21,160,109,0.18);">
					<h2 style="margin:0 0 6px;font-size:20px;font-weight:800;color:#0a3b34;">
						<?php echo $is_self_mode ? 'ICON Technical Traits Self-Assessment' : 'ICON Technical Traits Feedback'; ?>
					</h2>

					<p style="margin:0 0 10px;font-size:13px;color:#425b56;">
						<?php if ( $is_self_mode ) : ?>
							Your self-assessment has already been submitted.
						<?php else : ?>
							You have already submitted feedback for <strong><?php echo esc_html( $participant_name ); ?></strong>.
						<?php endif; ?>
					</p>

					<div style="display:flex;flex-wrap:wrap;gap:6px;font-size:11px;margin-bottom:8px;">
						<?php if ( $participant_role ) : ?>
							<span style="padding:3px 9px;border-radius:999px;border:1px solid #cbd5f5;background:#eff6ff;color:#1e3a8a;">Role: <?php echo esc_html( $participant_role ); ?></span>
						<?php endif; ?>
						<?php if ( $project_name ) : ?>
							<span style="padding:3px 9px;border-radius:999px;border:1px solid #bbf7d0;background:#ecfdf5;color:#166534;">Project: <?php echo esc_html( $project_name ); ?></span>
						<?php endif; ?>
						<?php if ( $client_name ) : ?>
							<span style="padding:3px 9px;border-radius:999px;border:1px solid #e5e7eb;background:#f9fafb;color:#374151;">Client: <?php echo esc_html( $client_name ); ?></span>
						<?php endif; ?>
						<span style="padding:3px 9px;border-radius:999px;border:1px solid #e5e7eb;background:#f9fafb;color:#374151;">Framework: <?php echo esc_html( $framework_name ); ?></span>
						<span style="padding:3px 9px;border-radius:999px;border:1px solid #bbf7d0;background:#ecfdf5;color:#166534;">Status: Completed</span>
					</div>

					<p style="margin:0;font-size:13px;color:#0f5132;">
						Thank you — it’s been recorded. You can now close this window.
					</p>
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

		$autosave_key = 'icon_psy_rater_survey_traits_' . ( $token ? $token : ( 'rid_' . (int) $rater->id ) ) . '_d' . (int) $traits_draft_id;

		ob_start();
		echo $debug_html;
		?>
		<div class="icon-psy-rater-survey-wrapper" style="max-width:980px;margin:0 auto;padding:22px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">

			<style>
				:root{
					--icon-green: <?php echo esc_html( $brand_primary ); ?>;
					--icon-blue: <?php echo esc_html( $brand_secondary ); ?>;
					--text-dark:#0a3b34;
					--text-mid:#425b56;
					--text-light:#6a837d;
				}
				.icon-psy-hero {
					background: radial-gradient(circle at top left, rgba(20,164,207,0.14) 0, rgba(21,160,109,0.10) 45%, #ffffff 100%);
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
				.icon-psy-chip{ padding:3px 9px;border-radius:999px;border:1px solid var(--icon-green);background:rgba(21,160,109,0.10);color:var(--text-dark); }
				.icon-psy-chip-muted{ padding:3px 9px;border-radius:999px;border:1px solid rgba(20,164,207,0.35);background:rgba(20,164,207,0.08);color:#0b3554; }

				.icon-psy-notice{ margin-bottom:14px;padding:10px 12px;border-radius:12px;border:1px solid;font-size:12px; }
				.icon-psy-notice.info{ border-color:#bee3f8;background:#eff6ff;color:#1e3a8a; }
				.icon-psy-notice.success{ border-color:#bbf7d0;background:#ecfdf5;color:#166534; }
				.icon-psy-notice.error{ border-color:#fecaca;background:#fef2f2;color:#b91c1c; }

				.icon-psy-progress-wrap{ background:#fff;border-radius:16px;border:1px solid rgba(0,0,0,0.06);box-shadow:0 10px 24px rgba(10,59,52,0.10);padding:12px 14px;margin-bottom:12px; }
				.icon-psy-progress-top{ display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:8px;font-size:12px;color:var(--text-mid); }
				.icon-psy-progress-bar{ height:8px;background:rgba(148,163,184,0.35);border-radius:999px;overflow:hidden; }
				.icon-psy-progress-fill{ height:100%;width:0%;background-image:linear-gradient(135deg,var(--icon-blue),var(--icon-green));border-radius:999px;transition:width .35s ease; }

				.icon-psy-instructions{
					background: linear-gradient(135deg, #ffffff 0%, rgba(21,160,109,0.06) 60%, rgba(20,164,207,0.06) 100%);
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
				.icon-psy-scale input[type="radio"]{ display:none; }
				.icon-psy-scale label{
					position:relative;width:100%;min-height:34px;display:flex;align-items:flex-start;justify-content:center;
					cursor:pointer;user-select:none;
				}
				.icon-psy-scale label::before{
					content:"";width:14px;height:14px;border-radius:999px;border:2px solid rgba(107,114,128,0.55);
					background:#fff;margin-top:2px;transition:transform .10s ease,border-color .10s ease,box-shadow .10s ease,background .10s ease;
				}
				.icon-psy-scale label::after{
					content:attr(data-num);position:absolute;top:18px;font-size:11px;color:var(--text-mid);font-variant-numeric:tabular-nums;
				}
				.icon-psy-scale label:hover::before{ border-color:var(--icon-green);box-shadow:0 0 0 2px rgba(21,160,109,0.14);transform:translateY(-1px); }
				.icon-psy-scale input[type="radio"]:checked + label::before{
					border-color:var(--icon-green);background-image:linear-gradient(135deg,var(--icon-green),var(--icon-blue));
					box-shadow:0 10px 18px rgba(21,160,109,0.28);transform:translateY(-1px);
				}
				.icon-psy-scale input[type="radio"]:checked + label::after{ color:var(--text-dark);font-weight:900; }

				.icon-psy-image-box{
					border-radius: 16px;
					border: 1px solid rgba(0,0,0,0.06);
					background: radial-gradient(circle at top left, rgba(20,164,207,0.10), rgba(21,160,109,0.08));
					padding: 10px;
					box-shadow: 0 14px 32px rgba(0,0,0,0.06);
				}
				.icon-psy-image-box img{ width:100%;height:auto;border-radius:12px;display:block; }
				.icon-psy-image-placeholder{
					width:100%;aspect-ratio:4/3;border-radius:12px;border:1px dashed rgba(148,163,184,0.75);
					background:rgba(249,250,251,0.7);display:flex;align-items:center;justify-content:center;
					font-size:12px;color:var(--text-light);
				}

				.icon-psy-comments-grid{ display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;margin-top:12px; }
				.icon-psy-comment-card{ border-radius:14px;border:1px solid rgba(0,0,0,0.06);background:rgba(21,160,109,0.06);padding:10px 12px;font-size:13px;color:var(--text-dark); }
				.icon-psy-comment-label{ font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:4px; }
				.icon-psy-comment-card textarea{
					width:100%;min-height:90px;border-radius:10px;border:1px solid #cbd5e1;padding:7px 9px;font-size:13px;resize:vertical;color:var(--text-dark);background:#fff;
				}
				.icon-psy-comment-card textarea:focus{ outline:none;border-color:var(--icon-green);box-shadow:0 0 0 1px rgba(21,160,109,0.40); }

				.icon-psy-btn-row{ margin-top:14px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap; }
				.icon-psy-btn-secondary{
					display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#fff;border:1px solid rgba(0,0,0,0.10);
					color:var(--icon-green);padding:9px 16px;font-size:13px;font-weight:800;cursor:pointer;text-decoration:none;white-space:nowrap;
				}
				.icon-psy-btn-secondary:hover{ background:rgba(20,164,207,0.08);border-color:rgba(20,164,207,0.35);color:var(--icon-blue); }
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

			<div class="icon-psy-hero">
				<?php if ( ! empty( $brand_logo_url ) ) : ?>
					<div style="position:absolute;top:14px;right:16px;">
						<img src="<?php echo esc_url( $brand_logo_url ); ?>" alt="" style="max-height:44px;max-width:180px;object-fit:contain;" loading="lazy" />
					</div>
				<?php endif; ?>

				<h2><?php echo $is_self_mode ? 'ICON – Technical Traits Self-Assessment' : 'ICON – Technical Traits Rater Survey'; ?></h2>
				<p>
					<?php if ( $is_self_mode ) : ?>
						This is a self-assessment for <strong><?php echo esc_html( $rater_display_name ); ?></strong>.
						Please answer honestly about yourself, based on your typical behaviour over the last 4–8 weeks (not your best day).
					<?php else : ?>
						You are providing confidential feedback on <strong><?php echo esc_html( $participant_name ); ?></strong>
						<?php if ( $participant_role ) : ?>(<?php echo esc_html( $participant_role ); ?>)<?php endif; ?>.
						Please rate how consistently they demonstrate each technical trait in practice.
					<?php endif; ?>
				</p>

				<div class="icon-psy-chip-row">
					<?php if ( $project_name ) : ?><span class="icon-psy-chip">Project: <?php echo esc_html( $project_name ); ?></span><?php endif; ?>
					<?php if ( $client_name ) : ?><span class="icon-psy-chip-muted">Client: <?php echo esc_html( $client_name ); ?></span><?php endif; ?>
					<span class="icon-psy-chip-muted">Framework: <?php echo esc_html( $framework_name ); ?></span>
					<span class="icon-psy-chip-muted">Trait draft: <?php echo esc_html( $traits_draft_title ? $traits_draft_title : ('Draft #' . (int) $traits_draft_id) ); ?></span>
					<?php if ( $traits_draft_created ) : ?><span class="icon-psy-chip-muted">Draft date: <?php echo esc_html( $traits_draft_created ); ?></span><?php endif; ?>
					<span class="icon-psy-chip-muted">Scale: 1 = very low, 7 = very high</span>
					<?php if ( $is_self_mode ) : ?><span class="icon-psy-chip">Private to me</span><?php endif; ?>
				</div>
			</div>

			<?php if ( $message ) : ?>
				<div class="icon-psy-notice <?php echo esc_attr( $message_class ); ?>"><?php echo esc_html( $message ); ?></div>
			<?php endif; ?>

			<?php if ( $rater_status !== 'completed' || $message_class === 'error' ) : ?>

				<div id="icon-psy-instructions-block" class="js-instructions-block">
					<?php if ( $is_self_mode ) : ?>
						<div class="icon-psy-instructions">
							<h3>How to complete this self-assessment</h3>
							<ul>
								<li>Answer based on the last 4–8 weeks (not your best day).</li>
								<li>Use evidence: outputs, decisions, artefacts, and results.</li>
								<li>Be honest. This is for development, not a scorecard.</li>
								<li>You must answer all 3 questions before moving to the next trait.</li>
							</ul>
						</div>
					<?php else : ?>
						<div class="icon-psy-notice info">
							Please answer based on your honest impression. Use practical evidence (outputs, behaviours, results) rather than assumptions.
						</div>
					<?php endif; ?>
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
											<div class="icon-psy-scale-label">Day-to-day delivery</div>
											<?php if ( $is_self_mode ) : ?>
												<div class="icon-psy-hint"><ul><?php foreach ( $q_hints_self['q1'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?></ul></div>
											<?php endif; ?>
											<div class="icon-psy-scale">
												<?php for ( $i = 1; $i <= 7; $i++ ) : $id = "tkey_{$tkey}_q1_{$i}"; ?>
													<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="scores[<?php echo esc_attr( $tkey ); ?>][q1]" value="<?php echo esc_attr( $i ); ?>">
													<label for="<?php echo esc_attr( $id ); ?>" data-num="<?php echo esc_attr( $i ); ?>"></label>
												<?php endfor; ?>
											</div>
										</div>

										<div class="icon-psy-scale-block">
											<div class="icon-psy-scale-label">Under pressure</div>
											<?php if ( $is_self_mode ) : ?>
												<div class="icon-psy-hint"><ul><?php foreach ( $q_hints_self['q2'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?></ul></div>
											<?php endif; ?>
											<div class="icon-psy-scale">
												<?php for ( $i = 1; $i <= 7; $i++ ) : $id = "tkey_{$tkey}_q2_{$i}"; ?>
													<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="scores[<?php echo esc_attr( $tkey ); ?>][q2]" value="<?php echo esc_attr( $i ); ?>">
													<label for="<?php echo esc_attr( $id ); ?>" data-num="<?php echo esc_attr( $i ); ?>"></label>
												<?php endfor; ?>
											</div>
										</div>

										<div class="icon-psy-scale-block">
											<div class="icon-psy-scale-label">Role-modelling / quality standard</div>
											<?php if ( $is_self_mode ) : ?>
												<div class="icon-psy-hint"><ul><?php foreach ( $q_hints_self['q3'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?></ul></div>
											<?php endif; ?>
											<div class="icon-psy-scale">
												<?php for ( $i = 1; $i <= 7; $i++ ) : $id = "tkey_{$tkey}_q3_{$i}"; ?>
													<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="scores[<?php echo esc_attr( $tkey ); ?>][q3]" value="<?php echo esc_attr( $i ); ?>">
													<label for="<?php echo esc_attr( $id ); ?>" data-num="<?php echo esc_attr( $i ); ?>"></label>
												<?php endfor; ?>
											</div>
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
								<div class="icon-psy-comment-label">Key strengths</div>
								<textarea name="q2_text" id="icon-psy-q2-text" placeholder="<?php echo esc_attr( $is_self_mode ? 'What do I do particularly well in practice?' : 'What do they do particularly well in practice?' ); ?>"></textarea>
							</div>
							<div class="icon-psy-comment-card">
								<div class="icon-psy-comment-label">Development priorities</div>
								<textarea name="q3_text" id="icon-psy-q3-text" placeholder="<?php echo esc_attr( $is_self_mode ? 'Where should I focus next?' : 'Where would it be most valuable for them to improve next?' ); ?>"></textarea>
							</div>
						</div>

						<div style="margin-top:14px;display:flex;justify-content:flex-end;">
							<button type="submit" class="icon-psy-btn-primary" id="icon-psy-submit-final">
								<?php echo $is_self_mode ? 'Save my self-assessment' : 'Submit feedback'; ?>
							</button>
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
					const q2Text = document.getElementById('icon-psy-q2-text');
					const q3Text = document.getElementById('icon-psy-q3-text');

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
						return checked >= 3;
					}

					function serializeForm(){
						const data = {};
						const inputs = form.querySelectorAll('input[type="radio"]:checked');
						inputs.forEach((i)=>{ data[i.name] = i.value; });
						if(q2Text) data['q2_text'] = q2Text.value || '';
						if(q3Text) data['q3_text'] = q3Text.value || '';
						data['_step'] = step;
						return data;
					}

					function restoreForm(saved){
						if(!saved) return;

						Object.keys(saved).forEach((k)=>{
							if(k === '_step' || k === 'q2_text' || k === 'q3_text') return;
							const val = saved[k];
							const selector = `input[type="radio"][name="${CSS.escape(k)}"][value="${CSS.escape(String(val))}"]`;
							const el = form.querySelector(selector);
							if(el) el.checked = true;
						});

						if(q2Text && typeof saved.q2_text === 'string') q2Text.value = saved.q2_text;
						if(q3Text && typeof saved.q3_text === 'string') q3Text.value = saved.q3_text;

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
							const payload = serializeForm();
							localStorage.setItem(autosaveKey, JSON.stringify(payload));
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
						if(e.target && (e.target.id === 'icon-psy-q2-text' || e.target.id === 'icon-psy-q3-text')){
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
								alert('Please answer all three questions before continuing.');
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
