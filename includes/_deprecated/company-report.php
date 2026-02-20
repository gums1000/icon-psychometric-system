<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Company Portfolio Pack (HTML-only Consolidated Report)
 * Shortcode: [icon_psy_company_report]
 *
 * SAFE MODE:
 * - No PDF engine calls.
 * - No changes to existing reports/framework logic.
 * - Consolidates across projects into a printable HTML pack.
 * - Adds "Framework Summary" rollups when multiple projects share the same framework_id.
 *
 * NEXT STAGE (this update):
 * - Adds optional "Framework Score Summary" (averages) IF results table exists:
 *   {$wpdb->prefix}icon_assessment_results with columns participant_id + detail_json
 * - Only summarises scores within the same framework_id group (no apples-to-oranges).
 *
 * UPDATE (this request):
 * - Adds client branding: primary/secondary + logo (best-effort)
 * - Branding is applied to CSS variables and hero logo
 */

/* ------------------------------------------------------------
 * Minimal helper fallbacks (only if missing in your plugin)
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_table_exists' ) ) {
	function icon_psy_table_exists( $table_name ) {
		global $wpdb;
		$t = (string) $table_name;
		if ( $t === '' ) return false;
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $t ) );
		return ( $found === $t );
	}
}

if ( ! function_exists( 'icon_psy_user_has_role' ) ) {
	function icon_psy_user_has_role( $user, $role ) {
		if ( ! $user || ! isset( $user->roles ) || ! is_array( $user->roles ) ) return false;
		return in_array( $role, $user->roles, true );
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

if ( ! function_exists( 'icon_psy_rater_is_completed_row' ) ) {
	function icon_psy_rater_is_completed_row( $rater_row ) {
		if ( ! $rater_row ) return false;
		$s = strtolower( (string) ( $rater_row->status ?? '' ) );
		if ( $s === 'completed' || $s === 'complete' ) return true;
		if ( isset( $rater_row->completed_at ) && ! empty( $rater_row->completed_at ) ) return true;
		return false;
	}
}

if ( ! function_exists( 'icon_psy_project_is_teams_project' ) ) {
	function icon_psy_project_is_teams_project( $project_row, $has_reference = false ) {
		if ( ! $project_row ) return false;
		$pkg = isset($project_row->icon_pkg) ? strtolower(trim((string)$project_row->icon_pkg)) : '';
		$teams_keys = array('high_performing_teams','teams_cohorts','teams','team','hpt');
		return in_array( $pkg, $teams_keys, true );
	}
}

if ( ! function_exists( 'icon_psy_project_is_traits_project' ) ) {
	function icon_psy_project_is_traits_project( $project_row, $has_reference = false ) {
		if ( ! $project_row ) return false;
		$pkg = isset($project_row->icon_pkg) ? strtolower(trim((string)$project_row->icon_pkg)) : '';
		return ( $pkg === 'aaet_internal' );
	}
}

if ( ! function_exists( 'icon_psy_project_is_profiler_project' ) ) {
	function icon_psy_project_is_profiler_project( $project_row, $frameworks_table ) {
		if ( ! $project_row ) return false;
		$fid = isset($project_row->framework_id) ? (int) $project_row->framework_id : 0;
		if ( $fid <= 0 ) return false;

		global $wpdb;
		$name = (string) $wpdb->get_var(
			$wpdb->prepare("SELECT name FROM {$frameworks_table} WHERE id=%d", $fid)
		);

		return ( strtolower(trim($name)) === 'icon profiler' );
	}
}

if ( ! function_exists( 'icon_psy_company_participant_report_url' ) ) {
	function icon_psy_company_participant_report_url( $project_row, $frameworks_table, $participant_id ) {

		$report_page_url          = home_url( '/feedback-report/' );
		$profiler_report_page_url = home_url( '/icon-profiler-report/' );
		$traits_report_page_url   = home_url( '/traits-report/' );
		$team_report_page_url     = home_url( '/team-report/' );

		$is_team   = icon_psy_project_is_teams_project( $project_row, true );
		$is_prof   = icon_psy_project_is_profiler_project( $project_row, $frameworks_table );
		$is_traits = icon_psy_project_is_traits_project( $project_row, true );

		if ( $is_team ) $base = $team_report_page_url;
		elseif ( $is_prof ) $base = $profiler_report_page_url;
		elseif ( $is_traits ) $base = $traits_report_page_url;
		else $base = $report_page_url;

		return add_query_arg( array( 'participant_id' => (int) $participant_id ), $base );
	}
}

/* ------------------------------------------------------------
 * NEXT STAGE helpers: score rollups from detail_json
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_company_extract_scores_from_detail_json' ) ) {
	function icon_psy_company_extract_scores_from_detail_json( $detail_json ) {
		$detail_json = (string) $detail_json;
		if ( $detail_json === '' ) return array();

		$decoded = json_decode( $detail_json, true );
		if ( ! is_array( $decoded ) ) return array();

		$out = array();

		$try_list = function( $list, $label_keys, $value_keys ) use ( &$out ) {
			if ( ! is_array( $list ) ) return;
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) continue;

				$label = '';
				foreach ( (array) $label_keys as $lk ) {
					if ( isset($row[$lk]) && is_string($row[$lk]) && trim($row[$lk]) !== '' ) {
						$label = trim((string)$row[$lk]);
						break;
					}
				}
				if ( $label === '' ) continue;

				$val = null;
				foreach ( (array) $value_keys as $vk ) {
					if ( isset($row[$vk]) && is_numeric($row[$vk]) ) {
						$val = (float) $row[$vk];
						break;
					}
				}
				if ( $val === null ) continue;

				$out[$label] = $val;
			}
		};

		if ( isset($decoded['competencies']) && is_array($decoded['competencies']) ) {
			$comp = $decoded['competencies'];
			$is_assoc = array_keys($comp) !== range(0, count($comp) - 1);
			if ( $is_assoc ) {
				foreach ( $comp as $k => $v ) {
					if ( is_string($k) && $k !== '' && is_numeric($v) ) {
						$out[ trim($k) ] = (float) $v;
					}
				}
			} else {
				$try_list( $comp, array('name','label','title','competency'), array('score','value','avg','mean','result') );
			}
		}

		if ( empty($out) && isset($decoded['traits']) && is_array($decoded['traits']) ) {
			$try_list( $decoded['traits'], array('name','label','title','trait'), array('score','value','avg','mean','result') );
		}

		if ( empty($out) && isset($decoded['radar_items']) && is_array($decoded['radar_items']) ) {
			$try_list( $decoded['radar_items'], array('name','label','title','competency','trait'), array('score','value','avg','mean','result') );
		}

		if ( empty($out) && isset($decoded['radar']) && is_array($decoded['radar']) ) {
			if ( isset($decoded['radar']['items']) && is_array($decoded['radar']['items']) ) {
				$try_list( $decoded['radar']['items'], array('name','label','title'), array('score','value','avg','mean','result') );
			}
		}

		if ( empty($out) ) {
			foreach ( $decoded as $k => $v ) {
				if ( ! is_array($v) ) continue;
				$is_list = array_keys($v) === range(0, count($v) - 1);
				if ( ! $is_list ) continue;
				$before = count($out);
				$try_list( $v, array('name','label','title'), array('score','value','avg','mean','result') );
				if ( count($out) > $before ) break;
			}
		}

		$out = apply_filters( 'icon_psy_company_rollup_extract_scores', $out, $decoded );

		return is_array($out) ? $out : array();
	}
}

if ( ! function_exists( 'icon_psy_company_compute_score_rollup_for_participants' ) ) {
	function icon_psy_company_compute_score_rollup_for_participants( $results_table, $participant_ids ) {
		global $wpdb;

		$participant_ids = array_values(array_unique(array_map('intval', (array)$participant_ids)));
		$participant_ids = array_filter($participant_ids, function($x){ return $x > 0; });

		if ( empty($participant_ids) ) return array();

		$ph = implode(',', array_fill(0, count($participant_ids), '%d'));
		$sql = "SELECT participant_id, detail_json FROM {$results_table} WHERE participant_id IN ($ph)";
		$rows = $wpdb->get_results( $wpdb->prepare($sql, $participant_ids) );

		$agg = array();
		foreach ( (array)$rows as $r ) {
			$scores = icon_psy_company_extract_scores_from_detail_json( $r->detail_json ?? '' );
			if ( empty($scores) ) continue;

			foreach ( $scores as $label => $val ) {
				$label = trim((string)$label);
				if ( $label === '' ) continue;
				if ( ! is_numeric($val) ) continue;

				if ( ! isset($agg[$label]) ) {
					$agg[$label] = array('sum'=>0.0,'n'=>0);
				}
				$agg[$label]['sum'] += (float)$val;
				$agg[$label]['n']   += 1;
			}
		}

		foreach ( $agg as $label => $row ) {
			$n = (int)($row['n'] ?? 0);
			$sum = (float)($row['sum'] ?? 0);
			$agg[$label]['avg'] = $n > 0 ? ($sum / $n) : 0;
		}

		return $agg;
	}
}

/* ------------------------------------------------------------
 * Shortcode
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_company_report_shortcode' ) ) {

	function icon_psy_company_report_shortcode( $atts ) {

		if ( ! is_user_logged_in() ) {
			return '<p>Please log in to access this report.</p>';
		}

		global $wpdb;

		$current_user = wp_get_current_user();
		$is_admin     = current_user_can( 'manage_options' );

		$effective_user_id = (int) icon_psy_get_effective_client_user_id();
		$effective_user    = $effective_user_id ? get_user_by( 'id', $effective_user_id ) : null;

		if ( $is_admin ) {
			if ( ! $effective_user || ! icon_psy_user_has_role( $effective_user, 'icon_client' ) ) {
				return '<p><strong>Admin:</strong> Please select a client to impersonate from the Management Portal first.</p>';
			}
		} else {
			if ( ! icon_psy_user_has_role( $current_user, 'icon_client' ) ) {
				return '<p>You do not have permission to view this report.</p>';
			}
		}

		$client_user_id = $is_admin ? $effective_user_id : (int) $current_user->ID;

		// ✅ Per-client branding (same logic as Client Portal; colours only)
		$branding = array( 'primary' => '#15a06d', 'secondary' => '#14a4cf' );
		if ( function_exists( 'icon_psy_get_client_branding' ) ) {
			$b = icon_psy_get_client_branding( (int) $client_user_id );
			if ( is_array( $b ) ) {
				$branding = array_merge( $branding, $b );
			}
		}

		$brand_primary   = ! empty( $branding['primary'] )   ? (string) $branding['primary']   : '#15a06d';
		$brand_secondary = ! empty( $branding['secondary'] ) ? (string) $branding['secondary'] : '#14a4cf';

		// (optional but recommended) normalize to #RRGGBB
		$norm_hex = function( $c, $fallback ) {
			$c = trim( (string) $c );
			if ( $c === '' ) return $fallback;
			if ( $c[0] !== '#' ) $c = '#' . $c;
			return preg_match('/^#([a-fA-F0-9]{6})$/', $c) ? strtolower($c) : $fallback;
		};
		$brand_primary   = $norm_hex( $brand_primary, '#15a06d' );
		$brand_secondary = $norm_hex( $brand_secondary, '#14a4cf' );

		$projects_table     = $wpdb->prefix . 'icon_psy_projects';
		$participants_table = $wpdb->prefix . 'icon_psy_participants';
		$raters_table       = $wpdb->prefix . 'icon_psy_raters';
		$frameworks_table   = $wpdb->prefix . 'icon_psy_frameworks';

		$results_table      = $wpdb->prefix . 'icon_assessment_results';

		foreach ( array($projects_table,$participants_table,$raters_table,$frameworks_table) as $tchk ) {
			if ( ! icon_psy_table_exists( $tchk ) ) {
				return '<p><strong>Setup issue:</strong> missing table <code>' . esc_html($tchk) . '</code>.</p>';
			}
		}

		$page_url = function_exists('get_permalink') ? get_permalink() : home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ?? '' ) );

		$projects = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$projects_table} WHERE client_user_id=%d ORDER BY id DESC",
				$client_user_id
			)
		);

		$framework_rows = $wpdb->get_results( "SELECT id, name FROM {$frameworks_table} ORDER BY name ASC" );
		$framework_name_by_id = array();
		foreach ( (array) $framework_rows as $fw ) {
			$framework_name_by_id[ (int)$fw->id ] = (string)$fw->name;
		}

		$selected_ids = array();

		if ( isset($_GET['project_ids_arr']) && is_array($_GET['project_ids_arr']) ) {
			foreach ( (array) $_GET['project_ids_arr'] as $x ) {
				$x = (int) $x;
				if ( $x > 0 ) $selected_ids[] = $x;
			}
		}

		if ( empty($selected_ids) && isset($_GET['project_ids']) && $_GET['project_ids'] !== '' ) {
			$raw = sanitize_text_field( wp_unslash( $_GET['project_ids'] ) );
			$parts = preg_split('/[,\s]+/', $raw);
			foreach ( (array) $parts as $x ) {
				$x = (int) $x;
				if ( $x > 0 ) $selected_ids[] = $x;
			}
		}

		$selected_ids = array_values(array_unique(array_map('intval', $selected_ids)));

		if ( empty($selected_ids) && ! empty($projects) ) {
			foreach ( (array) $projects as $pr ) $selected_ids[] = (int) $pr->id;
		}

		$projects_by_id = array();
		foreach ( (array) $projects as $pr ) $projects_by_id[(int)$pr->id] = $pr;

		$selected_projects = array();
		foreach ( (array) $selected_ids as $pid ) {
			if ( isset($projects_by_id[$pid]) ) $selected_projects[] = $projects_by_id[$pid];
		}

		$participants_by_project = array();
		$raters_by_participant   = array();

		if ( ! empty($selected_projects) ) {

			$pids = array_map('intval', wp_list_pluck($selected_projects, 'id'));
			$ph   = implode(',', array_fill(0, count($pids), '%d'));

			$sqlP = "SELECT * FROM {$participants_table} WHERE project_id IN ($ph) ORDER BY project_id ASC, id ASC";
			$participants = $wpdb->get_results( $wpdb->prepare($sqlP, $pids) );

			$part_ids = array();
			foreach ( (array) $participants as $p ) {
				$participants_by_project[(int)$p->project_id][] = $p;
				$part_ids[] = (int)$p->id;
			}

			if ( ! empty($part_ids) ) {
				$ph2 = implode(',', array_fill(0, count($part_ids), '%d'));
				$sqlR = "SELECT * FROM {$raters_table} WHERE participant_id IN ($ph2) ORDER BY participant_id ASC, id ASC";
				$raters = $wpdb->get_results( $wpdb->prepare($sqlR, $part_ids) );

				foreach ( (array) $raters as $r ) {
					$raters_by_participant[(int)$r->participant_id][] = $r;
				}
			}
		}

		$compute_project_rollup = function( $project_row ) use ( $participants_by_project, $raters_by_participant ) {
			$pid = (int)$project_row->id;
			$parts = isset($participants_by_project[$pid]) ? (array)$participants_by_project[$pid] : array();
			$pcount = count($parts);

			$raters_total = 0;
			$raters_done  = 0;

			foreach ( $parts as $p ) {
				$raters = isset($raters_by_participant[(int)$p->id]) ? (array)$raters_by_participant[(int)$p->id] : array();
				$raters_total += count($raters);
				foreach ( $raters as $rr ) {
					if ( icon_psy_rater_is_completed_row($rr) ) $raters_done++;
				}
			}

			$pct = $raters_total > 0 ? round(($raters_done / $raters_total) * 100) : 0;

			return array(
				'participants'   => $pcount,
				'raters_total'   => $raters_total,
				'raters_done'    => $raters_done,
				'completion_pct' => $pct,
			);
		};

		$total_projects = count($selected_projects);
		$total_participants = 0;
		$total_raters = 0;
		$total_completed_raters = 0;

		foreach ( (array) $selected_projects as $pr ) {
			$roll = $compute_project_rollup($pr);
			$total_participants      += (int)$roll['participants'];
			$total_raters            += (int)$roll['raters_total'];
			$total_completed_raters  += (int)$roll['raters_done'];
		}

		$completion_pct = ( $total_raters > 0 ) ? round( ($total_completed_raters / $total_raters) * 100 ) : 0;

		$framework_groups = array();
		foreach ( (array) $selected_projects as $pr ) {

			$fid = isset($pr->framework_id) ? (int)$pr->framework_id : 0;
			$key = ( $fid > 0 ) ? ('fw_' . $fid) : 'fw_none';

			if ( ! isset($framework_groups[$key]) ) {
				$framework_groups[$key] = array(
					'framework_id'  => $fid,
					'name'          => ( $fid > 0 && isset($framework_name_by_id[$fid]) ) ? (string)$framework_name_by_id[$fid] : 'No framework (or custom setup)',
					'projects'      => array(),
					'projects_count'=> 0,
					'participants'  => 0,
					'raters_total'  => 0,
					'raters_done'   => 0,
					'score_rollup'  => array(),
				);
			}

			$framework_groups[$key]['projects'][] = $pr;
			$framework_groups[$key]['projects_count']++;

			$roll = $compute_project_rollup($pr);
			$framework_groups[$key]['participants'] += (int)$roll['participants'];
			$framework_groups[$key]['raters_total'] += (int)$roll['raters_total'];
			$framework_groups[$key]['raters_done']  += (int)$roll['raters_done'];
		}

		uksort($framework_groups, function($a,$b){
			if ($a === 'fw_none') return 1;
			if ($b === 'fw_none') return -1;
			return strcmp($a,$b);
		});

		$can_score_rollup = false;
		if ( icon_psy_table_exists( $results_table ) ) {
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$results_table}", 0 );
			$can_score_rollup = ( is_array($cols) && in_array('participant_id', $cols, true) && in_array('detail_json', $cols, true) );
		}

		if ( $can_score_rollup ) {
			foreach ( $framework_groups as $k => $g ) {
				if ( empty($g['framework_id']) ) continue;

				$group_participant_ids = array();
				foreach ( (array)$g['projects'] as $pr ) {
					$pid = (int)$pr->id;
					$parts = isset($participants_by_project[$pid]) ? (array)$participants_by_project[$pid] : array();
					foreach ( $parts as $p ) $group_participant_ids[] = (int)$p->id;
				}
				$group_participant_ids = array_values(array_unique(array_filter(array_map('intval',$group_participant_ids))));

				$rollup = icon_psy_company_compute_score_rollup_for_participants( $results_table, $group_participant_ids );
				if ( is_array($rollup) ) $framework_groups[$k]['score_rollup'] = $rollup;
			}
		}

		// Client label
		$client_label = $is_admin && $effective_user ? $effective_user->display_name : $current_user->display_name;

		ob_start();
		?>
		<style>
			/* Match your portal look/feel */
			:root{
				--icon-green: <?php echo esc_html( $brand_primary ); ?>;
				--icon-blue:  <?php echo esc_html( $brand_secondary ); ?>;
				--text-dark:#0a3b34;
				--text-mid:#425b56;
				--text-light:#6a837d;
				--ink:#071b1a;
			}
			@media print{
				.no-print{ display:none !important; }
				.print-break{ page-break-before: always; }
				.icon-portal-shell{ background:#fff !important; padding:0 !important; }
				.icon-card{ box-shadow:none !important; }
				a{ color:#000; text-decoration:underline; }
			}
			.icon-portal-shell{
				position:relative;
				padding: 34px 16px 44px;
				background: radial-gradient(circle at top left, #e6f9ff 0%, #ffffff 40%, #e9f8f1 100%);
				overflow:hidden;
			}
			.icon-portal-wrap{
				max-width:1160px;margin:0 auto;
				font-family: system-ui,-apple-system,"Segoe UI",sans-serif;
				color: var(--text-dark);
				position:relative;z-index:2;
			}
			.icon-rail{
				height:4px;border-radius:999px;
				background: linear-gradient(90deg, var(--icon-blue), var(--icon-green));
				opacity:.85;margin: 0 0 12px;
				box-shadow: 0 10px 24px rgba(20,164,207,.18);
			}
			.icon-card{
				background:#fff;border:1px solid rgba(20,164,207,.14);
				border-radius:22px;box-shadow:0 16px 40px rgba(0,0,0,.06);
				padding:18px;margin-bottom:14px;
				position:relative; overflow:hidden;
			}
			.icon-card.icon-hero{
				background:
					radial-gradient(circle at top left, rgba(20,164,207,.12) 0%, rgba(255,255,255,1) 38%),
					radial-gradient(circle at bottom right, rgba(21,160,109,.12) 0%, rgba(255,255,255,1) 52%),
					#fff;
				padding-top:22px;
			}
			.icon-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
			.icon-space{justify-content:space-between;}
			.icon-tag{
				display:inline-block;padding:4px 12px;border-radius:999px;
				font-size:11px;letter-spacing:.08em;text-transform:uppercase;
				background: rgba(21,150,140,.1);color: var(--icon-green);font-weight:900;
			}
			.icon-h1{margin:0 0 6px;font-size:24px;font-weight:950;letter-spacing:-.02em;color:var(--ink);}
			.icon-sub{margin:0;color:var(--text-mid);font-size:13px;max-width:860px;line-height:1.45;}
			.icon-tagbar{ gap:10px; margin-top:10px; align-items:center; }
			.icon-tag.icon-tagstat{
				display:inline-flex;align-items:center;gap:8px;
				padding:6px 12px;
				font-size:11px;letter-spacing:.08em;text-transform:uppercase;
				background: rgba(21,150,140,.10);
				color: var(--icon-green);
				font-weight:950;
				box-shadow: inset 0 0 0 1px rgba(21,160,109,.10);
			}
			.icon-tag.icon-tagstat strong{
				font-weight:950;color: var(--ink);
				letter-spacing:0;text-transform:none;
			}
			.icon-btn{
				display:inline-flex;align-items:center;justify-content:center;
				border-radius:999px;border:1px solid transparent;
				background-image: linear-gradient(135deg,var(--icon-blue),var(--icon-green));
				color:#fff;padding:10px 14px;font-size:13px;font-weight:950;
				cursor:pointer;text-decoration:none;white-space:nowrap;
				box-shadow:0 12px 30px rgba(20,164,207,.30);
			}
			.icon-btn-ghost{
				display:inline-flex;align-items:center;justify-content:center;
				border-radius:999px;background:#fff;border:1px solid rgba(21,149,136,.35);
				color:var(--icon-green);padding:10px 14px;font-size:13px;font-weight:950;
				cursor:pointer;text-decoration:none;white-space:nowrap;
			}
			.icon-mini{font-size:12px;color:#64748b;line-height:1.4;}
			.icon-section-title{margin:0 0 8px;font-size:14px;font-weight:950;color:#0b2f2a;}
			.icon-grid-2{display:grid;grid-template-columns: minmax(0,1.25fr) minmax(0,1fr); gap:14px;}
			@media(max-width: 980px){.icon-grid-2{grid-template-columns:1fr;}}
			.icon-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px;}
			.icon-table th,.icon-table td{padding:8px 8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top;}
			.icon-table th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;background:#f9fafb;}
			.icon-link{color:#0b2f2a;text-decoration:underline;}
			.icon-pill{
				display:inline-flex;align-items:center;
				padding:6px 10px;border-radius:999px;
				font-size:11px;font-weight:950;
				border:1px solid rgba(20,164,207,.18);
				background: linear-gradient(135deg, rgba(20,164,207,.10), rgba(21,160,109,.10)), #fff;
				color: var(--ink);
			}
			.icon-divider{border-top:1px solid rgba(226,232,240,.9); margin-top:12px; padding-top:14px;}

			/* Branding logo box */
			.icon-brandbox{
				width:56px;height:56px;border-radius:16px;
				border:1px solid rgba(10,59,52,.10);
				background:#fff;
				display:flex;align-items:center;justify-content:center;
				box-shadow:0 12px 30px rgba(0,0,0,.06);
				padding:8px;
			}
			.icon-brandbox img{max-width:100%;max-height:100%;display:block;}

			/* Selector fix */
			.icon-select-grid{
				display:grid;
				grid-template-columns:repeat(2,minmax(0,1fr));
				gap:10px;
				margin-top:10px;
			}
			@media(max-width: 980px){
				.icon-select-grid{ grid-template-columns:1fr; }
			}
			.icon-select-item{
				display:flex;
				gap:10px;
				align-items:flex-start;
				padding:12px 12px;
				border-radius:16px;
				border:1px solid rgba(226,232,240,.9);
				background:#f9fbfc;
				box-shadow:0 10px 26px rgba(0,0,0,.02);
				cursor:pointer;
				user-select:none;
			}
			.icon-select-item:hover{
				border-color: rgba(20,164,207,.22);
				box-shadow:0 16px 34px rgba(0,0,0,.05);
			}
			.icon-select-item input[type="checkbox"]{
				margin:2px 0 0 0;
				flex:0 0 auto;
				width:16px;
				height:16px;
			}
			.icon-select-text{
				min-width:0;
				flex:1 1 auto;
			}
			.icon-select-title{
				margin:0;
				font-weight:950;
				color:#0b2f2a;
				font-size:13px;
				line-height:1.25;
				letter-spacing:-.01em;
				word-break:break-word;
			}
			.icon-select-meta{
				margin-top:6px;
				font-size:12px;
				color:#64748b;
				line-height:1.35;
				word-break:break-word;
			}
			.icon-select-meta strong{
				color:#0b2f2a;
				font-weight:800;
			}
		</style>

		<div class="icon-portal-shell">
			<div class="icon-portal-wrap">

				<div class="icon-rail"></div>

				<!-- HERO -->
				<div class="icon-card icon-hero">
					<div class="icon-row icon-space" style="align-items:flex-start;">

						<div class="icon-row" style="align-items:flex-start; gap:12px;">

							<?php if ( $brand_logo_url ) : ?>
								<div class="icon-brandbox">
									<img src="<?php echo esc_url( $brand_logo_url ); ?>" alt="Client logo">
								</div>
							<?php endif; ?>

							<div style="min-width:0;">
								<div class="icon-tag">Consolidated</div>
								<h1 class="icon-h1" style="margin-top:8px;">Company Portfolio Pack</h1>
								<p class="icon-sub">
									Client: <strong><?php echo esc_html($client_label); ?></strong><br>
									Consolidated across selected projects. Framework-level summary is included when projects share the same framework.
								</p>

								<div class="icon-row icon-tagbar">
									<div class="icon-tag icon-tagstat">Projects: <strong><?php echo (int)$total_projects; ?></strong></div>
									<div class="icon-tag icon-tagstat">Participants: <strong><?php echo (int)$total_participants; ?></strong></div>
									<div class="icon-tag icon-tagstat">Raters: <strong><?php echo (int)$total_raters; ?></strong></div>
									<div class="icon-tag icon-tagstat">Completion: <strong><?php echo (int)$completion_pct; ?>%</strong></div>
								</div>

								<div class="icon-mini" style="margin-top:10px;">
									Branding: <?php echo esc_html($brand_primary); ?> / <?php echo esc_html($brand_secondary); ?>
									<?php if ( $brand_logo_url ) : ?> · Logo loaded<?php else : ?> · No logo found<?php endif; ?>
								</div>

							</div>
						</div>

						<div class="icon-row no-print" style="justify-content:flex-end; gap:10px;">
							<button type="button" class="icon-btn" onclick="window.print()">Print / Save as PDF</button>
							<a class="icon-btn-ghost" href="<?php echo esc_url( home_url('/catalyst-portal/') ); ?>">← Back to portal</a>
						</div>
					</div>
				</div>

				<!-- Selection + Preview -->
				<div class="icon-card no-print">
					<div class="icon-grid-2">
						<div>
							<div class="icon-section-title">Select projects</div>
							<p class="icon-mini">Tick projects to include. This stays within the same client account.</p>

							<form method="get" action="<?php echo esc_url($page_url); ?>">

								<div class="icon-select-grid">
									<?php if ( empty($projects) ) : ?>
										<div class="icon-mini">No projects found for this client.</div>
									<?php else : ?>
										<?php foreach ( (array)$projects as $pr ) : ?>
											<?php
												$pid = (int)$pr->id;
												$checked = in_array($pid, $selected_ids, true);

												$pkg = isset($pr->icon_pkg) ? (string)$pr->icon_pkg : '';
												$fid = isset($pr->framework_id) ? (int)$pr->framework_id : 0;
												$fw_name = ($fid > 0 && isset($framework_name_by_id[$fid])) ? (string)$framework_name_by_id[$fid] : '';
											?>
											<label class="icon-select-item">
												<input type="checkbox" name="project_ids_arr[]" value="<?php echo (int)$pid; ?>" <?php checked($checked); ?>>
												<span class="icon-select-text">
													<p class="icon-select-title"><?php echo esc_html($pr->name); ?></p>

													<div class="icon-select-meta">
														<?php if ( $pkg ) : ?>
															<span><strong>Pkg:</strong> <?php echo esc_html($pkg); ?></span>
														<?php endif; ?>

														<?php if ( $fw_name ) : ?>
															<?php if ( $pkg ) echo '<span> · </span>'; ?>
															<span><strong>Framework:</strong> <?php echo esc_html($fw_name); ?></span>
														<?php endif; ?>

														<?php if ( ! $pkg && ! $fw_name ) : ?>
															<span>—</span>
														<?php endif; ?>
													</div>
												</span>
											</label>
										<?php endforeach; ?>
									<?php endif; ?>
								</div>

								<div class="icon-row" style="margin-top:12px;">
									<button class="icon-btn" type="submit">Update selection</button>
									<button class="icon-btn-ghost" type="button" onclick="(function(){
										document.querySelectorAll('input[name=&quot;project_ids_arr[]&quot;]').forEach(function(b){b.checked=true;});
									})()">Select all</button>
									<button class="icon-btn-ghost" type="button" onclick="(function(){
										document.querySelectorAll('input[name=&quot;project_ids_arr[]&quot;]').forEach(function(b){b.checked=false;});
									})()">Clear</button>
								</div>
							</form>
						</div>

						<div>
							<div class="icon-section-title">At-a-glance preview</div>
							<p class="icon-mini">Completion rollups across the selected projects.</p>

							<div style="overflow:auto;">
								<table class="icon-table">
									<thead>
										<tr>
											<th>Project</th>
											<th>Framework</th>
											<th>Participants</th>
											<th>Raters</th>
											<th>Completion</th>
										</tr>
									</thead>
									<tbody>
										<?php if ( empty($selected_projects) ) : ?>
											<tr><td colspan="5" class="icon-mini">No projects selected.</td></tr>
										<?php else : ?>
											<?php foreach ( (array)$selected_projects as $pr ) : ?>
												<?php
													$roll = $compute_project_rollup($pr);
													$fid = isset($pr->framework_id) ? (int)$pr->framework_id : 0;
													$fw_name = ($fid > 0 && isset($framework_name_by_id[$fid])) ? (string)$framework_name_by_id[$fid] : '—';
												?>
												<tr>
													<td style="font-weight:950;color:#0b2f2a;"><?php echo esc_html($pr->name); ?></td>
													<td><?php echo esc_html($fw_name); ?></td>
													<td><?php echo (int)$roll['participants']; ?></td>
													<td><?php echo (int)$roll['raters_done']; ?>/<?php echo (int)$roll['raters_total']; ?></td>
													<td><?php echo (int)$roll['completion_pct']; ?>%</td>
												</tr>
											<?php endforeach; ?>
										<?php endif; ?>
									</tbody>
								</table>
							</div>

							<div class="icon-mini" style="margin-top:10px;">
								<strong>Note:</strong> Score rollups appear below under Framework Summary if results data is available.
							</div>
						</div>
					</div>
				</div>

				<!-- PACK START -->
				<div class="icon-card">
					<div class="icon-section-title">Executive Summary</div>
					<div class="icon-row icon-tagbar">
						<div class="icon-pill">Generated: <?php echo esc_html( current_time('mysql') ); ?></div>
						<div class="icon-pill">Client: <?php echo esc_html($client_label); ?></div>
					</div>

					<div class="icon-divider">
						<div class="icon-section-title">Framework Summary</div>
						<p class="icon-mini">Projects using the same framework are grouped and summarised below (completion + optional score averages).</p>

						<div style="overflow:auto;">
							<table class="icon-table">
								<thead>
									<tr>
										<th>Framework</th>
										<th>Projects</th>
										<th>Participants</th>
										<th>Raters</th>
										<th>Completion</th>
										<th>Score summary</th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty($framework_groups) ) : ?>
										<tr><td colspan="6" class="icon-mini">No data.</td></tr>
									<?php else : ?>
										<?php foreach ( $framework_groups as $g ) : ?>
											<?php
												$rt = (int)$g['raters_total'];
												$rd = (int)$g['raters_done'];
												$pct = $rt > 0 ? round(($rd / $rt) * 100) : 0;

												$score_rollup = isset($g['score_rollup']) && is_array($g['score_rollup']) ? $g['score_rollup'] : array();

												$score_note = '—';
												if ( empty($g['framework_id']) ) {
													$score_note = 'Not summarised (no framework_id)';
												} elseif ( ! $can_score_rollup ) {
													$score_note = 'Not available (results table missing)';
												} elseif ( empty($score_rollup) ) {
													$score_note = 'No score data found';
												} else {
													$score_note = count($score_rollup) . ' items averaged';
												}
											?>
											<tr>
												<td style="font-weight:950;color:#0b2f2a;"><?php echo esc_html($g['name']); ?></td>
												<td><?php echo (int)$g['projects_count']; ?></td>
												<td><?php echo (int)$g['participants']; ?></td>
												<td><?php echo $rd; ?>/<?php echo $rt; ?></td>
												<td><?php echo (int)$pct; ?>%</td>
												<td><?php echo esc_html($score_note); ?></td>
											</tr>

											<?php
											if ( ! empty($score_rollup) && is_array($score_rollup) ) :

												$list = array();
												foreach ( $score_rollup as $label => $row ) {
													$avg = isset($row['avg']) ? (float)$row['avg'] : null;
													$n   = isset($row['n']) ? (int)$row['n'] : 0;
													if ( $avg === null || $n <= 0 ) continue;
													$list[] = array('label'=>$label,'avg'=>$avg,'n'=>$n);
												}

												$by_high = $list;
												usort($by_high, function($a,$b){
													if ($a['avg'] === $b['avg']) return 0;
													return ($a['avg'] < $b['avg']) ? 1 : -1;
												});

												$by_low = $list;
												usort($by_low, function($a,$b){
													if ($a['avg'] === $b['avg']) return 0;
													return ($a['avg'] > $b['avg']) ? 1 : -1;
												});

												$top_strengths = array_slice($by_high, 0, 6);
												$top_priorities = array_slice($by_low, 0, 6);
											?>
												<tr>
													<td colspan="6" style="background:#fcfeff;">
														<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
															<div>
																<div class="icon-mini" style="font-weight:950;color:#0b2f2a;">Top strengths (highest averages)</div>
																<table class="icon-table" style="margin-top:8px;">
																	<thead>
																		<tr><th>Item</th><th>Avg</th><th>N</th></tr>
																	</thead>
																	<tbody>
																		<?php foreach ( (array)$top_strengths as $it ) : ?>
																			<tr>
																				<td style="font-weight:900;color:#0b2f2a;"><?php echo esc_html($it['label']); ?></td>
																				<td><?php echo esc_html( number_format_i18n($it['avg'], 2) ); ?></td>
																				<td><?php echo (int)$it['n']; ?></td>
																			</tr>
																		<?php endforeach; ?>
																	</tbody>
																</table>
															</div>

															<div>
																<div class="icon-mini" style="font-weight:950;color:#0b2f2a;">Top priorities (lowest averages)</div>
																<table class="icon-table" style="margin-top:8px;">
																	<thead>
																		<tr><th>Item</th><th>Avg</th><th>N</th></tr>
																	</thead>
																	<tbody>
																		<?php foreach ( (array)$top_priorities as $it ) : ?>
																			<tr>
																				<td style="font-weight:900;color:#0b2f2a;"><?php echo esc_html($it['label']); ?></td>
																				<td><?php echo esc_html( number_format_i18n($it['avg'], 2) ); ?></td>
																				<td><?php echo (int)$it['n']; ?></td>
																			</tr>
																		<?php endforeach; ?>
																	</tbody>
																</table>
															</div>
														</div>

														<div class="icon-mini" style="margin-top:8px;">
															These averages are computed from <code><?php echo esc_html($results_table); ?></code> → <code>detail_json</code> using best-effort parsing.
															If your schema differs, you can customise extraction using the filter <code>icon_psy_company_rollup_extract_scores</code>.
														</div>
													</td>
												</tr>
											<?php endif; ?>

										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>

					</div>
				</div>

				<!-- PROJECT SECTIONS -->
				<?php if ( empty($selected_projects) ) : ?>
					<div class="icon-card">
						<p class="icon-mini">No projects selected.</p>
					</div>
				<?php else : ?>

					<?php foreach ( (array)$selected_projects as $pr ) : ?>
						<?php
							$pid = (int)$pr->id;
							$roll = $compute_project_rollup($pr);

							$pkg = isset($pr->icon_pkg) ? (string)$pr->icon_pkg : '';
							$fid = isset($pr->framework_id) ? (int)$pr->framework_id : 0;
							$fw_name = ($fid > 0 && isset($framework_name_by_id[$fid])) ? (string)$framework_name_by_id[$fid] : '—';

							$parts = isset($participants_by_project[$pid]) ? (array)$participants_by_project[$pid] : array();
						?>

						<div class="icon-card print-break">
							<div class="icon-row icon-space" style="align-items:flex-start;">
								<div style="min-width:0;">
									<div class="icon-section-title" style="margin-bottom:0;"><?php echo esc_html($pr->name); ?></div>
									<div class="icon-mini" style="margin-top:6px;">
										<?php
											$bits = array();
											if ( $pkg ) $bits[] = 'Package: ' . $pkg;
											if ( $fw_name && $fw_name !== '—' ) $bits[] = 'Framework: ' . $fw_name;
											$bits[] = 'Participants: ' . (int)$roll['participants'];
											$bits[] = 'Completion: ' . (int)$roll['completion_pct'] . '%';
											echo esc_html( implode(' · ', $bits) );
										?>
									</div>
								</div>
								<div class="icon-row" style="gap:10px;">
									<span class="icon-pill">Raters: <?php echo (int)$roll['raters_done']; ?>/<?php echo (int)$roll['raters_total']; ?></span>
								</div>
							</div>

							<div class="icon-divider">
								<?php if ( empty($parts) ) : ?>
									<p class="icon-mini">No participants in this project.</p>
								<?php else : ?>
									<div style="overflow:auto;">
										<table class="icon-table">
											<thead>
												<tr>
													<th>Participant</th>
													<th>Email</th>
													<th>Role</th>
													<th>Progress</th>
													<th>Report link</th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ( $parts as $p ) : ?>
													<?php
														$part_id = (int)$p->id;
														$raters  = isset($raters_by_participant[$part_id]) ? (array)$raters_by_participant[$part_id] : array();
														$total = count($raters);
														$done  = 0;
														foreach ( $raters as $rr ) { if ( icon_psy_rater_is_completed_row($rr) ) $done++; }

														$report_url = icon_psy_company_participant_report_url( $pr, $frameworks_table, $part_id );
													?>
													<tr>
														<td style="font-weight:950;color:#0b2f2a;"><?php echo esc_html($p->name); ?></td>
														<td><?php echo esc_html($p->email ?? ''); ?></td>
														<td><?php echo esc_html($p->role ?? ''); ?></td>
														<td><?php echo esc_html($done . '/' . $total); ?></td>
														<td>
															<a class="icon-link" href="<?php echo esc_url($report_url); ?>" target="_blank" rel="noopener">Open participant report</a>
															<div class="icon-mini" style="margin-top:6px;word-break:break-all;"><?php echo esc_html($report_url); ?></div>
														</td>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									</div>
								<?php endif; ?>
							</div>

						</div>

					<?php endforeach; ?>

				<?php endif; ?>

			</div>
		</div>

		<?php
		return ob_get_clean();
	}
}

add_action( 'init', function(){
	add_shortcode( 'icon_psy_company_report', 'icon_psy_company_report_shortcode' );
}, 20 );
