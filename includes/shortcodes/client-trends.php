<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ICON Catalyst — Client Trends (Client)
 * Shortcode: [icon_psy_client_trends]
 * URL: /client-trends/
 *
 * v5 (UPDATED)
 * - Focus CTA auto-picks LOWEST scoring project and routes to /action-plan/?project_id=XXX
 * - Trait Report compatibility:
 *   - Reads from multiple possible results tables
 *   - Detects columns dynamically (status/json/project/date)
 *   - ALSO supports trait-style tables keyed by participant_id (no project_id present)
 *   - Handles nested JSON payload shapes (items/results/data/rows)
 *   - Broader score extraction (rating/avg/mean/overall/q1 etc)
 *
 * ADDED (this update):
 * - Universal Action Plan extractor helper:
 *     icon_psy_action_plan_get_items_for_project($project_id)
 *   Returns normalised items (trait/competency/item) + scores, sorted LOW->HIGH.
 *   This gives your /action-plan/ page the same “data contract” for Trait Report
 *   as it already has for Team/360.
 * - Action-plan links now include &source=auto as a hint for future routing.
 */

if ( ! function_exists( 'icon_psy_trends_table_exists' ) ) {
	function icon_psy_trends_table_exists( $table ) {
		global $wpdb;
		$t = (string) $table;
		if ( $t === '' ) return false;
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $t ) );
		return ! empty( $found );
	}
}

if ( ! function_exists( 'icon_psy_trends_get_cols' ) ) {
	function icon_psy_trends_get_cols( $table ) {
		global $wpdb;
		if ( ! icon_psy_trends_table_exists( $table ) ) return array();
		$cols = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
		$out = array();
		foreach ( (array) $cols as $c ) {
			if ( ! empty( $c['Field'] ) ) $out[] = (string) $c['Field'];
		}
		return $out;
	}
}

if ( ! function_exists( 'icon_psy_trends_has_col' ) ) {
	function icon_psy_trends_has_col( $table, $col ) {
		$cols = icon_psy_trends_get_cols( $table );
		return in_array( (string) $col, $cols, true );
	}
}

if ( ! function_exists( 'icon_psy_trends_project_has_traits_results' ) ) {
	/**
	 * True if this project has Traits-style results (schema icon_traits_v1) in the detected results table.
	 */
	function icon_psy_trends_project_has_traits_results( $project_id ) {
		global $wpdb;

		$project_id = (int) $project_id;
		if ( $project_id <= 0 ) return false;

		$src = icon_psy_trends_detect_results_source();
		if ( empty( $src['table'] ) || empty( $src['col_json'] ) ) return false;

		$t    = (string) $src['table'];
		$colP = ! empty( $src['col_project'] ) ? (string) $src['col_project'] : '';
		$colR = ! empty( $src['col_participant'] ) ? (string) $src['col_participant'] : '';
		$colJ = (string) $src['col_json'];
		$colS = ! empty( $src['col_status'] ) ? (string) $src['col_status'] : '';

		$where_status = '';
		if ( $colS !== '' ) {
			$where_status = " AND ({$colS}='completed' OR {$colS}='complete' OR {$colS}='done') ";
		}

		// We’ll search for the schema marker written by your Traits survey.
		// This is intentionally string-search to avoid DB JSON functions.
		$like1 = '%"schema":"icon_traits_v1"%';
		$like2 = '%"schema":"icon_traits_v1"%'; // tolerate spaces in some encoders

		// Prefer project_id-keyed if available
		if ( $colP !== '' ) {
			$hit = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$t}
				 WHERE {$colP}=%d {$where_status}
				   AND ( {$colJ} LIKE %s OR {$colJ} LIKE %s )
				 LIMIT 1",
				$project_id,
				$like1,
				$like2
			) );
			return $hit > 0;
		}

		// Participant-keyed results: resolve one participant for the project and search by that.
		$participant_id = icon_psy_trends_get_project_participant_id( $project_id );
		if ( $participant_id > 0 && $colR !== '' ) {
			$hit = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$t}
				 WHERE {$colR}=%d {$where_status}
				   AND ( {$colJ} LIKE %s OR {$colJ} LIKE %s )
				 LIMIT 1",
				$participant_id,
				$like1,
				$like2
			) );
			return $hit > 0;
		}

		return false;
	}
}

if ( ! function_exists( 'icon_psy_trends_get_effective_client_id' ) ) {
	function icon_psy_trends_get_effective_client_id() {
		$u = wp_get_current_user();
		if ( ! $u || ! $u->ID ) return 0;

		if ( current_user_can( 'manage_options' ) ) {
			$imp = (int) get_user_meta( (int) $u->ID, 'icon_psy_impersonate_client_id', true );
			if ( $imp > 0 ) return $imp;
		}
		return (int) $u->ID;
	}
}

if ( ! function_exists( 'icon_psy_trends_decode_json' ) ) {
	function icon_psy_trends_decode_json( $value ) {
		if ( ! is_string( $value ) ) return null;
		$v = trim( $value );
		if ( $v === '' ) return null;
		$dec = json_decode( $v, true );
		return is_array( $dec ) ? $dec : null;
	}
}

/**
 * Many report engines wrap items inside payload keys like:
 * - items, results, data, rows, answers, competencies, traits
 * This returns the most likely "list of rows".
 */
if ( ! function_exists( 'icon_psy_trends_payload_to_rows' ) ) {
	function icon_psy_trends_payload_to_rows( $payload ) {
		if ( ! is_array( $payload ) ) return array();

		// If it's already a list of arrays (0..n), return as-is.
		$is_list = true;
		$i = 0;
		foreach ( $payload as $k => $v ) {
			if ( $k !== $i ) { $is_list = false; break; }
			$i++;
		}
		if ( $is_list ) return $payload;

		$candidates = array('items','results','data','rows','answers','competencies','traits','dimensions','scores');
		foreach ( $candidates as $ck ) {
			if ( isset( $payload[$ck] ) && is_array( $payload[$ck] ) ) {
				// Might still be nested again
				$inner = $payload[$ck];
				// If inner is associative wrapper again, try one more pass
				$inner_list = icon_psy_trends_payload_to_rows( $inner );
				if ( ! empty( $inner_list ) ) return $inner_list;
				return $inner;
			}
		}

		// Some payloads store as { key: {..row..}, key2:{..row..} }
		$out = array();
		foreach ( $payload as $v ) {
			if ( is_array( $v ) ) $out[] = $v;
		}
		return $out;
	}
}

if ( ! function_exists( 'icon_psy_trends_get_project_participant_id' ) ) {
	/**
	 * Returns first participant belonging to a project
	 */
	function icon_psy_trends_get_project_participant_id( $project_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'icon_psy_participants';
		if ( ! icon_psy_trends_table_exists( $table ) ) return 0;

		$pid = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE project_id=%d ORDER BY id ASC LIMIT 1",
			(int) $project_id
		) );

		return (int) $pid;
	}
}

if ( ! function_exists( 'icon_psy_trends_guess_report_slug_for_project' ) ) {
	/**
	 * Decide which summary page to use for a project based on its stored result payload.
	 * Returns 'traits-report' or 'team-report' (fallback).
	 *
	 * @param int   $project_id
	 * @param array $completed_results Rows already pulled from the detected results table.
	 * @return string
	 */
	function icon_psy_trends_guess_report_slug_for_project( $project_id, $completed_results ) {
		$pid = (int) $project_id;
		if ( $pid <= 0 ) return 'team-report';

		// Find the most recent result row for this project and inspect it.
		foreach ( (array) $completed_results as $r ) {
			$rid = isset($r->project_id) ? (int) $r->project_id : 0;
			if ( $rid !== $pid ) continue;

			if ( empty( $r->detail_json ) ) continue;

			$payload = icon_psy_trends_decode_json( (string) $r->detail_json );
			if ( ! is_array( $payload ) ) continue;

			// Quick “traits” signals
			if ( isset($payload['traits']) && is_array($payload['traits']) ) return 'traits-report';
			if ( isset($payload['trait_results']) && is_array($payload['trait_results']) ) return 'traits-report';

			$rows = icon_psy_trends_payload_to_rows( $payload );
			foreach ( (array) $rows as $it ) {
				if ( ! is_array( $it ) ) continue;

				// Strong trait signal
				if ( isset($it['trait_id']) && is_numeric($it['trait_id']) ) return 'traits-report';
				if ( isset($it['trait']) && is_string($it['trait']) && trim($it['trait']) !== '' ) return 'traits-report';
				if ( isset($it['trait_name']) && is_string($it['trait_name']) && trim($it['trait_name']) !== '' ) return 'traits-report';

				// If you store a type field
				if ( isset($it['type']) && is_string($it['type']) && strtolower(trim($it['type'])) === 'trait' ) return 'traits-report';
			}

			// If we checked a project row and didn’t find trait markers, assume non-traits.
			return 'team-report';
		}

		// No results found for the project -> keep default summary page
		return 'team-report';
	}
}

if ( ! function_exists( 'icon_psy_trends_row_score' ) ) {
	function icon_psy_trends_row_score( $row ) {
		if ( ! is_array( $row ) ) return null;

		// Direct numeric fields
		foreach ( array('rating','overall','score','avg','mean','value','result','final') as $k ) {
			if ( isset( $row[$k] ) && is_numeric( $row[$k] ) ) return (float) $row[$k];
		}

		// Nested q1 formats
		if ( isset( $row['q1'] ) ) {
			if ( is_numeric( $row['q1'] ) ) return (float) $row['q1'];
			if ( is_array( $row['q1'] ) ) {
				foreach ( array('rating','score','value','avg','mean') as $k ) {
					if ( isset( $row['q1'][$k] ) && is_numeric( $row['q1'][$k] ) ) return (float) $row['q1'][$k];
				}
			}
		}

		// Sometimes: { scores: { self: x, avg: y } } or { summary: { avg: y } }
		foreach ( array('scores','summary','aggregate') as $wrap ) {
			if ( isset( $row[$wrap] ) && is_array( $row[$wrap] ) ) {
				foreach ( array('avg','mean','overall','rating','score','value') as $k ) {
					if ( isset( $row[$wrap][$k] ) && is_numeric( $row[$wrap][$k] ) ) return (float) $row[$wrap][$k];
				}
			}
		}

		return null;
	}
}

if ( ! function_exists( 'icon_psy_trends_extract_item_ref' ) ) {
	function icon_psy_trends_extract_item_ref( $it ) {
		$out = array('id'=>0,'type'=>'','label'=>'');

		if ( ! is_array( $it ) ) return $out;

		if ( isset( $it['trait_id'] ) && is_numeric( $it['trait_id'] ) ) {
			$out['id'] = (int) $it['trait_id']; $out['type'] = 'trait';
		} elseif ( isset( $it['competency_id'] ) && is_numeric( $it['competency_id'] ) ) {
			$out['id'] = (int) $it['competency_id']; $out['type'] = 'competency';
		} elseif ( isset( $it['item_id'] ) && is_numeric( $it['item_id'] ) ) {
			$out['id'] = (int) $it['item_id']; $out['type'] = 'item';
		} elseif ( isset( $it['id'] ) && is_numeric( $it['id'] ) ) {
			$out['id'] = (int) $it['id']; $out['type'] = 'item';
		}

		foreach ( array('name','title','label','trait','competency','item_name','question','text','dimension') as $k ) {
			if ( isset( $it[$k] ) && is_string( $it[$k] ) ) {
				$lab = trim( (string) $it[$k] );
				if ( $lab !== '' ) { $out['label'] = $lab; break; }
			}
		}

		return $out;
	}
}

if ( ! function_exists( 'icon_psy_trends_get_item_name' ) ) {
	function icon_psy_trends_get_item_name( $item_id ) {
		static $cache = array();
		$iid = (int) $item_id;
		if ( $iid <= 0 ) return '';
		if ( isset( $cache[$iid] ) ) return $cache[$iid];

		global $wpdb;

		$candidates = array(
			$wpdb->prefix . 'icon_psy_framework_competencies',
			$wpdb->prefix . 'icon_psy_competencies',
			$wpdb->prefix . 'icon_psy_competency_bank',
			$wpdb->prefix . 'icon_psy_framework_items',
			$wpdb->prefix . 'icon_psy_ai_draft_competencies',
			$wpdb->prefix . 'icon_psy_traits',
			$wpdb->prefix . 'icon_psy_trait_bank',
		);

		$name_cols = array('name','title','label','competency','competency_name','trait','trait_name','item_name','question','text');
		$id_cols   = array('id','competency_id','trait_id','item_id');

		foreach ( $candidates as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
			if ( ! $exists ) continue;

			$cols = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
			if ( empty( $cols ) ) continue;

			$col_names = array();
			foreach ( $cols as $c ) { if ( ! empty( $c['Field'] ) ) $col_names[] = (string) $c['Field']; }

			$name_col = '';
			foreach ( $name_cols as $nc ) { if ( in_array( $nc, $col_names, true ) ) { $name_col = $nc; break; } }
			if ( $name_col === '' ) continue;

			$id_col = '';
			foreach ( $id_cols as $ic ) { if ( in_array( $ic, $col_names, true ) ) { $id_col = $ic; break; } }
			if ( $id_col === '' ) continue;

			$val = $wpdb->get_var( $wpdb->prepare(
				"SELECT {$name_col} FROM {$table} WHERE {$id_col}=%d LIMIT 1",
				$iid
			) );

			if ( $val !== null && $val !== '' ) {
				$cache[$iid] = (string) $val;
				return $cache[$iid];
			}
		}

		$cache[$iid] = 'Item #' . $iid;
		return $cache[$iid];
	}
}

if ( ! function_exists( 'icon_psy_trends_norm_pct' ) ) {
	function icon_psy_trends_norm_pct( $score, $min = 1, $max = 7 ) {
		if ( $score === null ) return 0;
		$s = (float) $score;
		$min = (float) $min; $max = (float) $max;
		if ( $max <= $min ) return 0;
		$p = ( $s - $min ) / ( $max - $min );
		if ( $p < 0 ) $p = 0;
		if ( $p > 1 ) $p = 1;
		return (int) round( $p * 100 );
	}
}

if ( ! function_exists( 'icon_psy_trends_project_is_traits' ) ) {
	function icon_psy_trends_project_is_traits( $project_row ) {

		if ( ! $project_row ) return false;

		$hay = '';
		foreach ( array('type','project_type','assessment_type','tool','product','framework','name') as $k ) {
			if ( isset($project_row->$k) && is_string($project_row->$k) ) {
				$hay .= ' ' . strtolower( trim( (string) $project_row->$k ) );
			}
		}

		// Signals for traits products (extend freely)
		if ( strpos($hay, 'trait') !== false ) return true;
		if ( strpos($hay, 'internal') !== false ) return true;
		if ( strpos($hay, 'aaet') !== false ) return true;

		return false;
	}
}

if ( ! function_exists( 'icon_psy_trends_month_key' ) ) {
	function icon_psy_trends_month_key( $ts ) {
		if ( ! $ts ) return '';
		return gmdate( 'Y-m', (int) $ts );
	}
}

if ( ! function_exists( 'icon_psy_trends_fmt_month' ) ) {
	function icon_psy_trends_fmt_month( $ym ) {
		if ( ! is_string( $ym ) || ! preg_match('/^\d{4}-\d{2}$/', $ym) ) return $ym;
		$ts = strtotime( $ym . '-01 00:00:00' );
		if ( ! $ts ) return $ym;
		return date_i18n( 'M Y', $ts );
	}
}

if ( ! function_exists( 'icon_psy_trends_build_sparkline' ) ) {
	function icon_psy_trends_build_sparkline( $points, $w = 140, $h = 44 ) {
		$w = (int) $w; $h = (int) $h;
		if ( $w < 60 ) $w = 60;
		if ( $h < 30 ) $h = 30;

		$vals = array();
		foreach ( (array) $points as $p ) {
			if ( is_numeric( $p ) ) $vals[] = (float) $p;
		}
		if ( count( $vals ) < 2 ) {
			return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" aria-hidden="true"><path d="M6 '.($h-10).' L'.($w-6).' '.($h-10).'" fill="none" stroke="rgba(148,163,184,.7)" stroke-width="2" stroke-linecap="round"/></svg>';
		}

		$min = min($vals); $max = max($vals);
		if ( $max <= $min ) { $max = $min + 0.0001; }

		$pad = 6;
		$nx = count($vals);
		$dx = ($w - $pad*2) / max(1, $nx - 1);

		$d = '';
		for ( $i=0; $i<$nx; $i++ ) {
			$x = $pad + $dx * $i;
			$norm = ( $vals[$i] - $min ) / ( $max - $min );
			$y = ($h - $pad) - $norm * ($h - $pad*2);
			$d .= ($i===0 ? 'M' : 'L') . round($x,2) . ' ' . round($y,2) . ' ';
		}

		$d_area = $d . 'L ' . ($w-$pad) . ' ' . ($h-$pad) . ' L ' . $pad . ' ' . ($h-$pad) . ' Z';

		return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" aria-hidden="true">
			<defs>
				<linearGradient id="ig" x1="0" y1="0" x2="1" y2="0">
					<stop offset="0" stop-color="var(--icon-blue)"/>
					<stop offset="1" stop-color="var(--icon-green)"/>
				</linearGradient>
			</defs>
			<path d="'.$d_area.'" fill="url(#ig)" opacity="0.16"></path>
			<path d="'.$d.'" fill="none" stroke="url(#ig)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></path>
		</svg>';
	}
}

if ( ! function_exists( 'icon_psy_trends_safe_num' ) ) {
	function icon_psy_trends_safe_num( $n, $dp = 1 ) {
		if ( $n === null || ! is_numeric( $n ) ) return '—';
		return number_format_i18n( (float) $n, (int) $dp );
	}
}

/**
 * Flexible "results source" detector.
 * Supports BOTH:
 * - project_id keyed results tables (team/360 style)
 * - participant_id keyed results tables (traits style)
 *
 * Returns:
 *  - table
 *  - col_project (may be '')
 *  - col_participant (may be '')
 *  - col_json
 *  - col_status (or '')
 *  - col_created (or '')
 */
if ( ! function_exists( 'icon_psy_trends_detect_results_source' ) ) {
	function icon_psy_trends_detect_results_source() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'icon_assessment_results',
			$wpdb->prefix . 'icon_psy_assessment_results',
			$wpdb->prefix . 'icon_psy_trait_results',
			$wpdb->prefix . 'icon_psy_traits_results',
			$wpdb->prefix . 'icon_psy_results',
		);

		$project_cols     = array('project_id','project','icon_project_id');
		$participant_cols = array('participant_id','participant','icon_participant_id');

		$json_cols    = array('detail_json','details_json','result_json','results_json','payload_json','data_json','json','detail');
		$status_cols  = array('status','state');
		$created_cols = array('created_at','created','created_on','submitted_at','submitted_on');

		foreach ( $tables as $t ) {
			if ( ! icon_psy_trends_table_exists( $t ) ) continue;
			$cols = icon_psy_trends_get_cols( $t );
			if ( empty( $cols ) ) continue;

			$col_project = '';
			foreach ( $project_cols as $c ) {
				if ( in_array($c,$cols,true) ) { $col_project = $c; break; }
			}

			$col_participant = '';
			foreach ( $participant_cols as $c ) {
				if ( in_array($c,$cols,true) ) { $col_participant = $c; break; }
			}

			// MUST have one of these
			if ( $col_project === '' && $col_participant === '' ) continue;

			$col_json = '';
			foreach ( $json_cols as $c ) { if ( in_array($c,$cols,true) ) { $col_json = $c; break; } }
			if ( $col_json === '' ) continue;

			$col_status = '';
			foreach ( $status_cols as $c ) { if ( in_array($c,$cols,true) ) { $col_status = $c; break; } }

			$col_created = '';
			foreach ( $created_cols as $c ) { if ( in_array($c,$cols,true) ) { $col_created = $c; break; } }

			return array(
				'table'           => $t,
				'col_project'     => $col_project,
				'col_participant' => $col_participant,
				'col_json'        => $col_json,
				'col_status'      => $col_status,
				'col_created'     => $col_created,
			);
		}

		return array();
	}
}

/* ------------------------------------------------------------
 * UNIVERSAL ACTION PLAN EXTRACTOR (Traits / Team / 360 compatible)
 * This is the “data” your action plan needs.
 *
 * Returns array of:
 *  - type (trait|competency|item)
 *  - id (0 if unknown)
 *  - name
 *  - score (1–7)
 *  - pct (0–100)
 *
 * Sorted LOW->HIGH to make “priorities” easy.
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_action_plan_get_items_for_project' ) ) {
	function icon_psy_action_plan_get_items_for_project( $project_id, $limit = 200 ) {
		global $wpdb;

		$project_id = (int) $project_id;
		if ( $project_id <= 0 ) return array();

		$src = icon_psy_trends_detect_results_source();
		if ( empty( $src['table'] ) ) return array();

		$t    = (string) $src['table'];
		$colP = ! empty($src['col_project']) ? (string) $src['col_project'] : '';
		$colR = ! empty($src['col_participant']) ? (string) $src['col_participant'] : '';
		$colJ = (string) $src['col_json'];
		$colS = ! empty($src['col_status']) ? (string) $src['col_status'] : '';

		$where_status = '';
		if ( $colS !== '' ) {
			$where_status = " AND ({$colS}='completed' OR {$colS}='complete' OR {$colS}='done') ";
		}

		$rows = array();

		// Prefer project_id keyed tables
		if ( $colP !== '' ) {

			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, {$colJ} AS detail_json
				 FROM {$t}
				 WHERE {$colP}=%d {$where_status}
				 ORDER BY id DESC
				 LIMIT %d",
				$project_id,
				(int) $limit
			) );

		} else {

			// Trait-style: keyed by participant_id
			$participant_id = icon_psy_trends_get_project_participant_id( $project_id );
			if ( $participant_id > 0 && $colR !== '' ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, {$colJ} AS detail_json
					 FROM {$t}
					 WHERE {$colR}=%d {$where_status}
					 ORDER BY id DESC
					 LIMIT %d",
					$participant_id,
					(int) $limit
				) );
			}
		}

		if ( empty( $rows ) ) return array();

		// Aggregate by (type|name) for stable averages
		$bucket = array();

		foreach ( (array) $rows as $r ) {
			if ( empty( $r->detail_json ) ) continue;

			$payload = icon_psy_trends_decode_json( (string) $r->detail_json );
			if ( ! is_array( $payload ) ) continue;

			$list = icon_psy_trends_payload_to_rows( $payload );
			if ( empty( $list ) ) continue;

			foreach ( $list as $it ) {
				if ( ! is_array( $it ) ) continue;

				$score = icon_psy_trends_row_score( $it );
				if ( $score === null ) continue;

				$ref  = icon_psy_trends_extract_item_ref( $it );
				$type = $ref['type'] ? (string) $ref['type'] : 'item';

				$name = '';
				if ( ! empty( $ref['label'] ) ) $name = (string) $ref['label'];
				elseif ( ! empty( $ref['id'] ) ) $name = icon_psy_trends_get_item_name( (int) $ref['id'] );
				$name = trim( (string) $name );
				if ( $name === '' ) continue;

				$key = strtolower($type) . '|' . $name;

				if ( ! isset( $bucket[$key] ) ) {
					$bucket[$key] = array(
						'type'  => $type,
						'id'    => ! empty($ref['id']) ? (int) $ref['id'] : 0,
						'name'  => $name,
						'sum'   => 0.0,
						'count' => 0,
					);
				}

				$bucket[$key]['sum']   += (float) $score;
				$bucket[$key]['count'] += 1;
			}
		}

		$out = array();
		foreach ( $bucket as $b ) {
			if ( empty( $b['count'] ) ) continue;
			$avg = (float) $b['sum'] / (float) $b['count'];
			$out[] = array(
				'type'  => (string) $b['type'],
				'id'    => (int) $b['id'],
				'name'  => (string) $b['name'],
				'score' => $avg,
				'pct'   => icon_psy_trends_norm_pct( $avg, 1, 7 ),
			);
		}

		// LOW -> HIGH for action plan priorities
		usort( $out, function($a,$b){
			if ( $a['score'] == $b['score'] ) return 0;
			return ( $a['score'] < $b['score'] ) ? -1 : 1;
		});

		return $out;
	}
}

if ( ! function_exists( 'icon_psy_client_trends_shortcode' ) ) {
	function icon_psy_client_trends_shortcode() {

		if ( ! is_user_logged_in() ) return '<p>Please log in.</p>';

		global $wpdb;

		$client_id = (int) icon_psy_trends_get_effective_client_id();
		if ( $client_id <= 0 ) return '<p>Client not found.</p>';

		$projects_table = $wpdb->prefix . 'icon_psy_projects';
		$plans_table    = $wpdb->prefix . 'icon_psy_action_plans';
		$items_table    = $wpdb->prefix . 'icon_psy_action_plan_items';

		if ( ! icon_psy_trends_table_exists( $projects_table ) ) {
			return '<p>Projects table not found.</p>';
		}

		$projects = $wpdb->get_results( $wpdb->prepare(
			"SELECT *
			FROM {$projects_table}
			WHERE client_user_id=%d
			ORDER BY id DESC",
			$client_id
		) );

		$project_ids = array();
		foreach ( (array) $projects as $p ) $project_ids[] = (int) $p->id;

		// Branding
		$brand_primary   = '#15a06d';
		$brand_secondary = '#14a4cf';
		$brand_logo_url  = function_exists('get_site_icon_url') ? (string) get_site_icon_url(128) : '';

		if ( function_exists( 'icon_psy_get_client_branding' ) ) {
			$b = icon_psy_get_client_branding( (int) $client_id );
			if ( is_array($b) ) {
				if ( ! empty($b['primary']) )   $brand_primary   = (string) $b['primary'];
				if ( ! empty($b['secondary']) ) $brand_secondary = (string) $b['secondary'];
				if ( ! empty($b['logo_url']) )  $brand_logo_url  = (string) $b['logo_url'];
				elseif ( ! empty($b['logo']) && is_string($b['logo']) ) $brand_logo_url = (string) $b['logo'];
				elseif ( ! empty($b['logo_id']) && is_numeric($b['logo_id']) ) {
					$maybe = wp_get_attachment_image_url( (int) $b['logo_id'], 'thumbnail' );
					if ( $maybe ) $brand_logo_url = (string) $maybe;
				}
			}
		}

		$project_map = array();
		foreach ( (array) $projects as $p ) {
			if ( ! empty($p->id) ) $project_map[(int)$p->id] = $p;
		}

		if ( empty( $project_ids ) ) {
			ob_start();
			?>
			<style>
				:root{--icon-green:<?php echo esc_html($brand_primary); ?>;--icon-blue:<?php echo esc_html($brand_secondary); ?>;}
				.icon-card{background:#fff;border:1px solid rgba(20,164,207,.14);border-radius:22px;box-shadow:0 16px 40px rgba(0,0,0,.06);padding:18px;margin:14px 0;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;}
				.icon-sub{margin:0;color:#425b56;font-size:13px;}
			</style>
			<div class="icon-card">
				<div style="font-weight:950;">Client trends</div>
				<p class="icon-sub">No projects yet.</p>
			</div>
			<?php
			return ob_get_clean();
		}

		$in = implode( ',', array_map( 'intval', $project_ids ) );

		// Detect best results source (Trait report compatible)
		$src = icon_psy_trends_detect_results_source();
		$completed_results = array();
		$results_has_created = false;

		if ( ! empty( $src['table'] ) ) {

			$t    = (string) $src['table'];
			$colP = ! empty($src['col_project']) ? (string) $src['col_project'] : '';
			$colR = ! empty($src['col_participant']) ? (string) $src['col_participant'] : '';
			$colJ = (string) $src['col_json'];
			$colS = ! empty($src['col_status']) ? (string) $src['col_status'] : '';
			$colC = ! empty($src['col_created']) ? (string) $src['col_created'] : '';

			$results_has_created = ( $colC !== '' );

			$where_status = '';
			if ( $colS !== '' ) {
				// Common statuses: completed, complete, done
				$where_status = " AND ({$colS}='completed' OR {$colS}='complete' OR {$colS}='done') ";
			}

			$select_cols = "id, {$colJ} AS detail_json";
			if ( $colC !== '' ) $select_cols .= ", {$colC} AS created_at";

			// Prefer project_id if available
			if ( $colP !== '' ) {

				$select_cols .= ", {$colP} AS project_id";

				$completed_results = $wpdb->get_results(
					"SELECT {$select_cols}
					 FROM {$t}
					 WHERE {$colP} IN ({$in}) {$where_status}
					 ORDER BY id DESC"
				);

			} else {

				// Trait-style tables: keyed by participant_id
				$participants_table = $wpdb->prefix . 'icon_psy_participants';

				$participant_ids = array();
				if ( icon_psy_trends_table_exists( $participants_table ) ) {
					$participant_ids = $wpdb->get_col(
						"SELECT id FROM {$participants_table}
						 WHERE project_id IN ({$in})"
					);
				}

				$participant_ids = array_values( array_unique( array_map( 'intval', (array) $participant_ids ) ) );
				if ( ! empty( $participant_ids ) && $colR !== '' ) {

					$rin = implode(',', $participant_ids);
					$select_cols .= ", 0 AS project_id";

					$completed_results = $wpdb->get_results(
						"SELECT {$select_cols}
						 FROM {$t}
						 WHERE {$colR} IN ({$rin}) {$where_status}
						 ORDER BY id DESC"
					);

				} else {
					$completed_results = array();
				}
			}
		}

		// Bucket: key => sum/count
		$bucket = array();
		$total_score_sum = 0;
		$total_score_cnt = 0;

		// Per project avg
		$per_project_sum = array();
		$per_project_cnt = array();

		// Trend by month
		$trend_month_sum = array();
		$trend_month_cnt = array();
		$trend_proj_month_sum = array();
		$trend_proj_month_cnt = array();

		// Momentum windows + movers
		$window_now_sum = 0; $window_now_cnt = 0;
		$window_prev_sum = 0; $window_prev_cnt = 0;
		$now_ts = current_time('timestamp');
		$cut_now = $now_ts - (30 * DAY_IN_SECONDS);
		$cut_prev = $now_ts - (60 * DAY_IN_SECONDS);

		$mover_now = array();
		$mover_prev = array();

		foreach ( (array) $completed_results as $r ) {
			$pid = isset($r->project_id) ? (int) $r->project_id : 0;

			// If we came from participant-keyed results, project_id is forced 0 (we can’t reliably attribute to a specific project here)
			// We still use these results for portfolio-level items and trend/momentum where possible.
			if ( empty( $r->detail_json ) ) continue;

			$payload = icon_psy_trends_decode_json( (string) $r->detail_json );
			if ( ! is_array( $payload ) ) continue;

			$rows = icon_psy_trends_payload_to_rows( $payload );
			if ( empty( $rows ) ) continue;

			$row_ts = 0;
			if ( $results_has_created && ! empty( $r->created_at ) ) {
				$maybe = strtotime( (string) $r->created_at );
				if ( $maybe ) $row_ts = (int) $maybe;
			}

			foreach ( $rows as $it ) {
				if ( ! is_array( $it ) ) continue;

				$score = icon_psy_trends_row_score( $it );
				if ( $score === null ) continue;

				$ref  = icon_psy_trends_extract_item_ref( $it );
				$name = '';
				if ( ! empty( $ref['label'] ) ) $name = (string) $ref['label'];
				elseif ( ! empty( $ref['id'] ) ) $name = icon_psy_trends_get_item_name( (int) $ref['id'] );

				$name = trim( (string) $name );
				if ( $name === '' ) continue;

				$type = $ref['type'] ? (string) $ref['type'] : 'item';
				$key  = strtolower($type) . '|' . $name;

				if ( ! isset( $bucket[$key] ) ) $bucket[$key] = array('name'=>$name,'type'=>$type,'sum'=>0,'count'=>0);
				$bucket[$key]['sum']   += (float) $score;
				$bucket[$key]['count'] += 1;

				$total_score_sum += (float) $score;
				$total_score_cnt += 1;

				// Only calculate per-project averages if we have a real project_id on the row
				if ( $pid > 0 ) {
					if ( ! isset( $per_project_sum[$pid] ) ) { $per_project_sum[$pid] = 0; $per_project_cnt[$pid] = 0; }
					$per_project_sum[$pid] += (float) $score;
					$per_project_cnt[$pid] += 1;
				}

				if ( $row_ts ) {
					$ym = icon_psy_trends_month_key( $row_ts );
					if ( $ym ) {
						if ( ! isset( $trend_month_sum[$ym] ) ) { $trend_month_sum[$ym] = 0; $trend_month_cnt[$ym] = 0; }
						$trend_month_sum[$ym] += (float) $score;
						$trend_month_cnt[$ym] += 1;

						// Only track project-month trend if project_id is available
						if ( $pid > 0 ) {
							if ( ! isset( $trend_proj_month_sum[$pid] ) ) { $trend_proj_month_sum[$pid] = array(); $trend_proj_month_cnt[$pid] = array(); }
							if ( ! isset( $trend_proj_month_sum[$pid][$ym] ) ) { $trend_proj_month_sum[$pid][$ym] = 0; $trend_proj_month_cnt[$pid][$ym] = 0; }
							$trend_proj_month_sum[$pid][$ym] += (float) $score;
							$trend_proj_month_cnt[$pid][$ym] += 1;
						}
					}

					if ( $row_ts >= $cut_now ) {
						$window_now_sum += (float) $score; $window_now_cnt += 1;
						if ( ! isset( $mover_now[$key] ) ) $mover_now[$key] = array('sum'=>0,'count'=>0,'name'=>$name,'type'=>$type);
						$mover_now[$key]['sum'] += (float) $score;
						$mover_now[$key]['count'] += 1;
					} elseif ( $row_ts >= $cut_prev && $row_ts < $cut_now ) {
						$window_prev_sum += (float) $score; $window_prev_cnt += 1;
						if ( ! isset( $mover_prev[$key] ) ) $mover_prev[$key] = array('sum'=>0,'count'=>0,'name'=>$name,'type'=>$type);
						$mover_prev[$key]['sum'] += (float) $score;
						$mover_prev[$key]['count'] += 1;
					}
				}
			}
		}

		$items = array();
		foreach ( $bucket as $b ) {
			if ( empty($b['count']) ) continue;
			$items[] = array(
				'name'  => (string) $b['name'],
				'type'  => (string) $b['type'],
				'score' => (float) ( $b['sum'] / $b['count'] ),
			);
		}

		usort( $items, function($a,$b){
			if ( $a['score'] == $b['score'] ) return 0;
			return ( $a['score'] > $b['score'] ) ? -1 : 1;
		});

		$top5 = array_slice( $items, 0, 5 );
		$bot5 = array_slice( array_reverse( $items ), 0, 5 );

		$portfolio_score = $total_score_cnt ? ( $total_score_sum / $total_score_cnt ) : null;

		$strength_index = null;
		if ( ! empty( $top5 ) ) {
			$sum = 0; $cnt = 0;
			foreach ( $top5 as $x ) { $sum += (float)$x['score']; $cnt++; }
			$strength_index = $cnt ? ( $sum / $cnt ) : null;
		}
		$risk_index = null;
		if ( ! empty( $bot5 ) ) {
			$sum = 0; $cnt = 0;
			foreach ( $bot5 as $x ) { $sum += (float)$x['score']; $cnt++; }
			$risk_index = $cnt ? ( $sum / $cnt ) : null;
		}

		// Trend series
		$trend_labels = array();
		$trend_values = array();
		if ( ! empty( $trend_month_sum ) ) {
			$months = array_keys( $trend_month_sum );
			sort( $months );
			$months = array_slice( $months, -8 );
			foreach ( $months as $ym ) {
				$cnt = isset($trend_month_cnt[$ym]) ? (int) $trend_month_cnt[$ym] : 0;
				$avg = $cnt ? ( (float)$trend_month_sum[$ym] / $cnt ) : null;
				$trend_labels[] = $ym;
				$trend_values[] = $avg;
			}
		}

		// Momentum
		$momentum_label = 'Stable';
		$momentum_delta = 0.0;

		$now_avg  = $window_now_cnt  ? ( $window_now_sum  / $window_now_cnt )  : null;
		$prev_avg = $window_prev_cnt ? ( $window_prev_sum / $window_prev_cnt ) : null;
		if ( $now_avg !== null && $prev_avg !== null ) $momentum_delta = (float) $now_avg - (float) $prev_avg;

		if ( $momentum_delta >= 0.15 ) $momentum_label = 'Rising';
		elseif ( $momentum_delta <= -0.15 ) $momentum_label = 'Declining';

		$momentum_dir = 'flat';
		if ( $momentum_label === 'Rising' ) $momentum_dir = 'up';
		elseif ( $momentum_label === 'Declining' ) $momentum_dir = 'down';

		// Movers
		$movers_enabled = ! empty( $src ) && ! empty( $src['col_created'] );
		$movers_up = array();
		$movers_down = array();

		if ( $movers_enabled ) {
			$min_prev = 6;
			$min_now  = 6;
			$all_keys = array_unique( array_merge( array_keys($mover_now), array_keys($mover_prev) ) );

			foreach ( $all_keys as $k ) {
				$now_c  = isset($mover_now[$k]['count']) ? (int) $mover_now[$k]['count'] : 0;
				$prev_c = isset($mover_prev[$k]['count']) ? (int) $mover_prev[$k]['count'] : 0;
				if ( $now_c < $min_now || $prev_c < $min_prev ) continue;

				$now_avg_i  = (float) $mover_now[$k]['sum']  / max(1, $now_c);
				$prev_avg_i = (float) $mover_prev[$k]['sum'] / max(1, $prev_c);
				$delta = $now_avg_i - $prev_avg_i;

				$name = isset($mover_now[$k]['name']) ? (string) $mover_now[$k]['name'] : ( isset($mover_prev[$k]['name']) ? (string) $mover_prev[$k]['name'] : $k );
				$type = isset($mover_now[$k]['type']) ? (string) $mover_now[$k]['type'] : ( isset($mover_prev[$k]['type']) ? (string) $mover_prev[$k]['type'] : 'item' );

				$row = array('name'=>$name,'type'=>$type,'now'=>$now_avg_i,'prev'=>$prev_avg_i,'delta'=>$delta);

				if ( $delta >= 0 ) $movers_up[] = $row;
				else $movers_down[] = $row;
			}

			usort( $movers_up, function($a,$b){ return ($a['delta']==$b['delta'])?0:(($a['delta']>$b['delta'])?-1:1); } );
			usort( $movers_down, function($a,$b){ return ($a['delta']==$b['delta'])?0:(($a['delta']<$b['delta'])?-1:1); } );

			$movers_up   = array_slice( $movers_up, 0, 5 );
			$movers_down = array_slice( $movers_down, 0, 5 );
		}

		// Action plans (portfolio + per project)
		$ap_open = 0; $ap_prog = 0; $ap_done = 0; $ap_total = 0;
		$per_project_ap = array();
		$action_plans_available = icon_psy_trends_table_exists( $plans_table ) && icon_psy_trends_table_exists( $items_table );

		if ( $action_plans_available ) {
			$plan_rows = $wpdb->get_results(
				"SELECT id, project_id FROM {$plans_table}
				 WHERE client_user_id=" . (int) $client_id . " AND project_id IN ({$in})"
			);

			$proj_to_plans = array();
			foreach ( (array) $plan_rows as $pr ) {
				$pid = isset($pr->project_id) ? (int) $pr->project_id : 0;
				$plid = isset($pr->id) ? (int) $pr->id : 0;
				if ( $pid <= 0 || $plid <= 0 ) continue;
				if ( ! isset( $proj_to_plans[$pid] ) ) $proj_to_plans[$pid] = array();
				$proj_to_plans[$pid][] = $plid;
			}

			$plan_ids = array();
			foreach ( $proj_to_plans as $pid => $list ) foreach ( (array) $list as $plid ) $plan_ids[] = (int) $plid;
			$plan_ids = array_values( array_unique( array_filter( $plan_ids ) ) );

			if ( ! empty( $plan_ids ) ) {
				$pin = implode(',', array_map('intval', $plan_ids));
				$rows = $wpdb->get_results(
					"SELECT status, COUNT(*) c
					 FROM {$items_table}
					 WHERE plan_id IN ({$pin})
					 GROUP BY status"
				);
				foreach ( (array) $rows as $x ) {
					$st = (string) $x->status;
					$c  = (int) $x->c;
					$ap_total += $c;
					if ( $st === 'open' ) $ap_open += $c;
					elseif ( $st === 'in_progress' ) $ap_prog += $c;
					elseif ( $st === 'done' ) $ap_done += $c;
				}
			}

			foreach ( (array) $project_ids as $pid ) {
				$pid = (int) $pid;
				$per_project_ap[$pid] = array('open'=>0,'prog'=>0,'done'=>0,'total'=>0,'pct'=>0);
				if ( empty( $proj_to_plans[$pid] ) ) continue;

				$pin = implode(',', array_map('intval', (array) $proj_to_plans[$pid] ));
				$rows = $wpdb->get_results(
					"SELECT status, COUNT(*) c
					 FROM {$items_table}
					 WHERE plan_id IN ({$pin})
					 GROUP BY status"
				);
				$total = 0; $done = 0; $open = 0; $prog = 0;
				foreach ( (array) $rows as $x ) {
					$st = (string) $x->status;
					$c  = (int) $x->c;
					$total += $c;
					if ( $st === 'open' ) $open += $c;
					elseif ( $st === 'in_progress' ) $prog += $c;
					elseif ( $st === 'done' ) $done += $c;
				}
				$per_project_ap[$pid] = array(
					'open'  => $open,
					'prog'  => $prog,
					'done'  => $done,
					'total' => $total,
					'pct'   => $total ? (int) round( ( $done / $total ) * 100 ) : 0,
				);
			}
		}

		$ap_pct = $ap_total ? (int) round( ( $ap_done / $ap_total ) * 100 ) : 0;

		// Build project cards + find LOWEST scoring project id (for Focus CTA)
		$project_cards = array();
		$lowest_project_id = 0;
		$lowest_project_score = null;

		foreach ( (array) $projects as $p ) {
			$pid = isset($p->id) ? (int) $p->id : 0;
			if ( $pid <= 0 ) continue;

			$avg = null;
			if ( isset( $per_project_cnt[$pid] ) && (int) $per_project_cnt[$pid] > 0 ) {
				$avg = (float) $per_project_sum[$pid] / (float) $per_project_cnt[$pid];
			}

			// lowest scoring project (ignore nulls)
			if ( $avg !== null ) {
				if ( $lowest_project_score === null || (float)$avg < (float)$lowest_project_score ) {
					$lowest_project_score = (float) $avg;
					$lowest_project_id = (int) $pid;
				}
			}

			$ap = isset( $per_project_ap[$pid] ) ? (array) $per_project_ap[$pid] : array('open'=>0,'prog'=>0,'done'=>0,'total'=>0,'pct'=>0);

			$spark_vals = array();
			if ( ! empty( $trend_labels ) && isset( $trend_proj_month_sum[$pid] ) && is_array( $trend_proj_month_sum[$pid] ) ) {
				foreach ( $trend_labels as $ym ) {
					$cnt = isset($trend_proj_month_cnt[$pid][$ym]) ? (int) $trend_proj_month_cnt[$pid][$ym] : 0;
					$avgm = $cnt ? ( (float)$trend_proj_month_sum[$pid][$ym] / $cnt ) : null;
					$spark_vals[] = $avgm;
				}
			}

			$project_cards[] = array(
				'id'        => $pid,
				'name'      => isset($p->name) ? (string) $p->name : ('Project #' . $pid),
				'created_at'=> isset($p->created_at) ? (string) $p->created_at : '',
				'score'     => $avg,
				'score_pct' => icon_psy_trends_norm_pct( $avg, 1, 7 ),
				'ap'        => $ap,
				'spark'     => $spark_vals,
			);
		}

		// Links
		$portal_link = home_url('/catalyst-portal/');

		// Focus CTA: lowest scoring project action plan
		$focus_action_link = $portal_link;
		if ( $lowest_project_id > 0 ) {
			$focus_action_link = home_url( '/action-plan/?project_id=' . (int) $lowest_project_id . '&source=auto' );
		}

		// Trend svg
		$trend_svg = icon_psy_trends_build_sparkline( $trend_values, 260, 52 );

		// Focus items
		$focus_items = array_slice( $bot5, 0, 2 );

		$portfolio_pct = icon_psy_trends_norm_pct( $portfolio_score, 1, 7 );
		$strength_pct  = icon_psy_trends_norm_pct( $strength_index, 1, 7 );
		$risk_pct      = icon_psy_trends_norm_pct( $risk_index, 1, 7 );

		ob_start();
		?>
		<style>
			:root{
				--icon-green: <?php echo esc_html($brand_primary); ?>;
				--icon-blue: <?php echo esc_html($brand_secondary); ?>;
				--ink:#071b1a;
				--mid:#425b56;
				--muted:#6a837d;
				--line: rgba(20,164,207,.14);
				--shadow: 0 18px 50px rgba(0,0,0,.10);
				--shadow2: 0 16px 40px rgba(0,0,0,.06);
				--glass: rgba(255,255,255,.82);
			}
			@keyframes floatIn { from { transform: translateY(10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
			@keyframes shimmer { 0% { transform: translateX(-40%); opacity: .0; } 40%{ opacity: .18; } 100%{ transform: translateX(140%); opacity: 0; } }

			.icon-shell{
				padding: 36px 16px 60px;
				background:
					radial-gradient(circle at 10% 0%, rgba(20,164,207,.18) 0%, rgba(255,255,255,0) 35%),
					radial-gradient(circle at 90% 10%, rgba(21,160,109,.18) 0%, rgba(255,255,255,0) 35%),
					radial-gradient(circle at 30% 90%, rgba(78,70,255,.10) 0%, rgba(255,255,255,0) 35%),
					radial-gradient(circle at top left, #e6f9ff 0%, #ffffff 40%, #e9f8f1 100%);
			}
			.icon-wrap{max-width:1180px;margin:0 auto;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);}
			.icon-rail{height:4px;border-radius:999px;background:linear-gradient(90deg,var(--icon-blue),var(--icon-green));opacity:.9;margin:0 0 14px;box-shadow:0 12px 28px rgba(20,164,207,.18);}

			.icon-card{
				background: var(--glass);
				border: 1px solid var(--line);
				border-radius: 24px;
				box-shadow: var(--shadow2);
				padding: 18px;
				margin-bottom: 14px;
				backdrop-filter: blur(10px);
				animation: floatIn .35s ease both;
			}

			.icon-hero{
				position:relative;border-radius: 28px;overflow:hidden;
				padding: 22px 22px;
				border: 1px solid rgba(255,255,255,.35);
				box-shadow: var(--shadow);
				background: linear-gradient(100deg, #4e46ff 0%, var(--icon-blue) 45%, var(--icon-green) 100%);
				color:#fff;margin-bottom: 14px;animation: floatIn .35s ease both;
			}
			.icon-hero:before,.icon-hero:after{content:"";position:absolute;border-radius:999px;opacity:.18;pointer-events:none;}
			.icon-hero:before{width:560px;height:560px;right:-240px;top:-260px;background:#fff;}
			.icon-hero:after{width:420px;height:420px;left:-210px;bottom:-210px;background:#fff;}
			.icon-hero .shine{position:absolute;inset:0;pointer-events:none;overflow:hidden;}
			.icon-hero .shine:before{
				content:"";position:absolute;top:-40%;left:-60%;width:40%;height:180%;
				background: linear-gradient(90deg, rgba(255,255,255,0), rgba(255,255,255,.75), rgba(255,255,255,0));
				transform: rotate(18deg);animation: shimmer 4.8s ease-in-out infinite;opacity:.12;
			}

			.icon-hero-top{position:relative;display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap;}
			.icon-hero-left{display:flex;gap:14px;align-items:center;min-width:0;}
			.icon-logo{
				width:70px;height:70px;border-radius:20px;background: rgba(255,255,255,.16);
				border: 1px solid rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;
				overflow:hidden;box-shadow:0 14px 30px rgba(0,0,0,.14);flex:0 0 auto;
			}
			.icon-logo img{width:60px;height:60px;object-fit:cover;border-radius:16px;}
			.icon-kicker{font-size:12px;letter-spacing:.14em;text-transform:uppercase;font-weight:900;opacity:.92;}
			.icon-title{margin:3px 0 2px;font-size:42px;line-height:1.02;font-weight:950;letter-spacing:-.03em;}
			.icon-hero-sub{margin:0;color:rgba(255,255,255,.88);font-size:14px;font-weight:850;}
			.icon-hero-actions{display:flex;gap:10px;align-items:center;flex:0 0 auto;flex-wrap:wrap;}

			.icon-btn{
				display:inline-flex;align-items:center;justify-content:center;border-radius:999px;
				padding: 12px 16px;font-weight:950;font-size:13px;text-decoration:none;cursor:pointer;
				border:1px solid rgba(255,255,255,.35);background: rgba(255,255,255,.14);color:#fff;
				backdrop-filter: blur(10px);white-space:nowrap;box-shadow: 0 10px 24px rgba(0,0,0,.10);
				transition: transform .15s ease, opacity .15s ease, box-shadow .15s ease;position:relative;overflow:hidden;
			}
			.icon-btn:after{content:"";position:absolute;inset:0;background: linear-gradient(90deg, rgba(255,255,255,0), rgba(255,255,255,.35), rgba(255,255,255,0));transform: translateX(-140%);opacity:0;}
			.icon-btn:hover{transform: translateY(-1px);opacity:.98;box-shadow:0 16px 34px rgba(0,0,0,.14);}
			.icon-btn:hover:after{transform: translateX(140%);opacity:.22;transition: transform .55s ease, opacity .55s ease;}
			.icon-btn.primary{background:#fff;color:#062c2a;border-color:#fff;}

			.icon-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px;}
			@media(max-width:980px){.icon-grid{grid-template-columns:1fr;}.icon-title{font-size:32px;}}

			.kpi{
				position:relative;border-radius:22px;padding:16px;border:1px solid rgba(20,164,207,.14);
				background: linear-gradient(135deg, rgba(20,164,207,.07), rgba(21,160,109,.07)), rgba(255,255,255,.92);
				box-shadow: 0 16px 36px rgba(0,0,0,.06);overflow:hidden;transition: transform .18s ease, box-shadow .18s ease;
			}
			.kpi:hover{transform: translateY(-2px); box-shadow: 0 20px 44px rgba(0,0,0,.08);}
			.kpi:before{content:"";position:absolute;width:240px;height:240px;border-radius:999px;right:-120px;top:-130px;background: linear-gradient(135deg, rgba(20,164,207,.25), rgba(21,160,109,.18));opacity:.32;}
			.kpi .lab{position:relative;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6b7280;font-weight:950;}
			.kpi .val{position:relative;font-size:30px;font-weight:950;letter-spacing:-.02em;margin-top:6px;color:var(--ink);}
			.kpi .hint{position:relative;margin-top:4px;font-size:12px;color:var(--muted);font-weight:850;}
			.kpi-row{display:flex;gap:14px;align-items:center;justify-content:space-between;margin-top:10px;position:relative;}
			.dial{
				--pct: 50;width:52px;height:52px;border-radius:999px;
				background: conic-gradient(from 220deg, var(--icon-blue), var(--icon-green) calc(var(--pct)*1%), rgba(148,163,184,.18) 0);
				display:flex;align-items:center;justify-content:center;box-shadow: 0 12px 28px rgba(20,164,207,.18);flex:0 0 auto;
			}
			.dial > span{
				width:40px;height:40px;border-radius:999px;background:#fff;display:flex;align-items:center;justify-content:center;
				border:1px solid rgba(148,163,184,.25);font-weight:950;font-size:12px;color:var(--ink);
			}

			.section-title{font-weight:950;color:var(--ink);margin:0 0 6px;font-size:14px;}
			.section-sub{margin:0;color:var(--mid);font-size:13px;}

			.dash-grid{display:grid;grid-template-columns:1.35fr .65fr;gap:12px;}
			@media(max-width:980px){.dash-grid{grid-template-columns:1fr;}}

			.panel{
				border-radius:22px;border:1px solid rgba(148,163,184,.22);
				background: rgba(255,255,255,.92);
				padding: 14px;box-shadow: 0 14px 34px rgba(0,0,0,.05);
				position:relative;overflow:hidden;
			}

			.momentum{
				display:inline-flex;align-items:center;gap:10px;padding:10px 12px;border-radius:999px;
				border:1px solid rgba(148,163,184,.22);background: rgba(255,255,255,.88);
				font-weight:950;font-size:12px;color:var(--ink);
			}
			.arrow{
				width:26px;height:26px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;
				background: linear-gradient(135deg, rgba(20,164,207,.18), rgba(21,160,109,.18));
				border:1px solid rgba(20,164,207,.18);color:#062c2a;font-weight:950;
			}

			.dual{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
			@media(max-width:980px){.dual{grid-template-columns:1fr;}}

			.item-row{
				display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
				padding: 12px 12px;border-radius: 18px;border: 1px solid rgba(20,164,207,.12);
				background: linear-gradient(135deg, rgba(20,164,207,.05), rgba(21,160,109,.04)), #fff;
				margin-top: 10px;transition: transform .16s ease, box-shadow .16s ease;
			}
			.item-name{font-weight:950;color:#062c28;line-height:1.2;}
			.item-meta{font-size:11px;color:var(--muted);font-weight:900;margin-top:4px;letter-spacing:.02em;}
			.score{font-weight:950;white-space:nowrap;}

			.bar{height:10px;border-radius:999px;background: rgba(148,163,184,.20);overflow:hidden;margin-top:10px;}
			.bar > span{display:block;height:100%;background: linear-gradient(90deg, var(--icon-blue), var(--icon-green));}

			.meter{height:12px;border-radius:999px;background:rgba(148,163,184,.20);overflow:hidden;margin-top:10px;}
			.meter > span{display:block;height:100%;width:<?php echo (int) $ap_pct; ?>%;background:linear-gradient(90deg,var(--icon-blue),var(--icon-green));}

			.movers{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;}
			@media(max-width:980px){.movers{grid-template-columns:1fr;}}
			.mover{
				display:flex;justify-content:space-between;gap:12px;align-items:flex-start;
				padding:12px;border-radius:18px;border:1px solid rgba(148,163,184,.20);
				background: rgba(255,255,255,.92);
				margin-top:10px;
			}
			.mover .delta{
				font-weight:950;white-space:nowrap;padding:8px 10px;border-radius:999px;border:1px solid rgba(148,163,184,.22);
				background: rgba(7,27,26,.04);
			}
			.mover .delta.up{border-color:rgba(34,197,94,.25);background:rgba(220,252,231,.55);color:#14532d;}
			.mover .delta.down{border-color:rgba(220,38,38,.25);background:rgba(254,226,226,.55);color:#7f1d1d;}

			.focus{
				border-radius:24px;border:1px solid rgba(20,164,207,.18);
				background: linear-gradient(135deg, rgba(20,164,207,.10), rgba(21,160,109,.10)), rgba(255,255,255,.92);
				box-shadow: 0 18px 44px rgba(0,0,0,.06);
				padding:16px;position:relative;overflow:hidden;
			}
			.focus:before{
				content:"";position:absolute;width:380px;height:380px;border-radius:999px;
				right:-220px;top:-240px;background: linear-gradient(135deg, rgba(20,164,207,.25), rgba(21,160,109,.22));
				opacity:.28;
			}
			.focus h3{margin:0 0 6px;font-size:16px;font-weight:950;letter-spacing:-.01em;position:relative;}
			.focus ul{margin:10px 0 0;padding-left:18px;position:relative;}
			.focus li{margin:6px 0;color:var(--ink);font-weight:850;}
			.focus .chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;position:relative;}
			.chip{
				display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;
				border:1px solid rgba(148,163,184,.25);background: rgba(255,255,255,.88);
				font-size:11px;font-weight:950;color:var(--ink);
			}
			.chip .dot{width:8px;height:8px;border-radius:99px;background:rgba(148,163,184,.9);}
			.chip.pri .dot{background:rgba(220,38,38,.9);}
			.chip.ap .dot{background:rgba(21,160,109,.95);}

			.projects-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:12px;}
			@media(max-width:1100px){.projects-grid{grid-template-columns:1fr 1fr;}}
			@media(max-width:740px){.projects-grid{grid-template-columns:1fr;}}

			.proj{
				position:relative;border-radius:22px;border:1px solid rgba(20,164,207,.14);
				background: rgba(255,255,255,.92);
				box-shadow: 0 16px 40px rgba(0,0,0,.06);
				padding: 14px;overflow:hidden;
				transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
			}
			.proj:hover{transform: translateY(-3px); box-shadow: 0 22px 54px rgba(0,0,0,.10); border-color: rgba(20,164,207,.22);}
			.proj:before{
				content:"";position:absolute;width:280px;height:280px;border-radius:999px;
				right:-150px;bottom:-160px;background: linear-gradient(135deg, rgba(20,164,207,.18), rgba(21,160,109,.14));
				opacity:.35;
			}
			.proj-top{position:relative;display:flex;gap:10px;align-items:flex-start;justify-content:space-between;}
			.proj-name{font-weight:950;color:var(--ink);letter-spacing:-.01em;line-height:1.2;}
			.proj-meta{position:relative;margin-top:6px;color:var(--muted);font-size:12px;font-weight:850;}
			.badges{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;position:relative;}
			.badge{
				display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;
				border:1px solid rgba(148,163,184,.25);background: rgba(255,255,255,.85);
				font-size:11px;font-weight:950;color:var(--ink);white-space:nowrap;
			}
			.badge .dot{width:8px;height:8px;border-radius:99px;background:rgba(148,163,184,.9);}
			.badge.score .dot{background: rgba(20,164,207,.95);}
			.badge.ap .dot{background: rgba(21,160,109,.95);}

			.proj-row{position:relative;display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:10px;}
			.proj-row .bar{flex:1;margin-top:0;}
			.proj-spark{position:relative;flex:0 0 auto;opacity:.95;}

			.proj-actions{position:relative;display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;}
			.proj-actions a{
				display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:10px 12px;
				font-weight:950;font-size:13px;text-decoration:none;border:1px solid rgba(148,163,184,.25);
				background: rgba(7,27,26,.05);color: var(--ink);transition: transform .15s ease, box-shadow .15s ease;
			}
			.proj-actions a:hover{transform: translateY(-1px);box-shadow:0 14px 30px rgba(0,0,0,.08);}
			.proj-actions a.primary{border-color: transparent;background: linear-gradient(135deg, var(--icon-blue), var(--icon-green));color:#fff;}
		</style>

		<div class="icon-shell">
			<div class="icon-wrap">
				<div class="icon-rail"></div>

				<div class="icon-hero">
					<div class="shine"></div>
					<div class="icon-hero-top">
						<div class="icon-hero-left">
							<div class="icon-logo"><?php if ( $brand_logo_url ) : ?><img src="<?php echo esc_url($brand_logo_url); ?>" alt=""><?php endif; ?></div>
							<div style="min-width:0;">
								<div class="icon-kicker">ICON CATALYST • CLIENT TRENDS</div>
								<div class="icon-title">Portfolio scorecard</div>
								<p class="icon-hero-sub">
									Projects: <?php echo (int) count($projects); ?>
									<span style="opacity:.7;">•</span>
									Completed results: <?php echo (int) count($completed_results); ?>
									<span style="opacity:.7;">•</span>
									Momentum: <span style="opacity:.95;"><?php echo esc_html( $momentum_label ); ?></span>
								</p>
							</div>
						</div>
						<div class="icon-hero-actions">
							<a class="icon-btn" href="<?php echo esc_url( home_url('/catalyst-portal/') ); ?>">Back to portal</a>
							<a class="icon-btn primary" href="<?php echo esc_url( $focus_action_link ); ?>">Action plan</a>
						</div>
					</div>
				</div>

				<div class="icon-card">
					<div class="section-title">Executive summary</div>
					<p class="section-sub">
						<?php if ( empty($src) ) : ?>
							No results table detected. (If your Trait Report stores results in a different table, add it to the detector list.)
						<?php else : ?>
							Using results source: <b><?php echo esc_html( str_replace($wpdb->prefix,'wp_', (string)$src['table']) ); ?></b>
						<?php endif; ?>
					</p>

					<div class="icon-grid" style="margin-top:12px;">
						<div class="kpi">
							<div class="lab">Portfolio score</div>
							<div class="kpi-row">
								<div>
									<div class="val"><?php echo $portfolio_score === null ? '—' : esc_html( number_format_i18n($portfolio_score, 1) . '/7' ); ?></div>
									<div class="hint">Overall performance</div>
								</div>
								<div class="dial" style="--pct:<?php echo (int) $portfolio_pct; ?>;"><span><?php echo (int) $portfolio_pct; ?>%</span></div>
							</div>
						</div>

						<div class="kpi">
							<div class="lab">Strength index</div>
							<div class="kpi-row">
								<div>
									<div class="val"><?php echo $strength_index === null ? '—' : esc_html( number_format_i18n( $strength_index, 1 ) . '/7' ); ?></div>
									<div class="hint">Top 5 average</div>
								</div>
								<div class="dial" style="--pct:<?php echo (int) $strength_pct; ?>;"><span><?php echo (int) $strength_pct; ?>%</span></div>
							</div>
						</div>

						<div class="kpi">
							<div class="lab">Risk index</div>
							<div class="kpi-row">
								<div>
									<div class="val"><?php echo $risk_index === null ? '—' : esc_html( number_format_i18n( $risk_index, 1 ) . '/7' ); ?></div>
									<div class="hint">Bottom 5 average</div>
								</div>
								<div class="dial" style="--pct:<?php echo (int) $risk_pct; ?>;"><span><?php echo (int) $risk_pct; ?>%</span></div>
							</div>
						</div>

						<div class="kpi">
							<div class="lab">Action completion</div>
							<div class="kpi-row">
								<div>
									<div class="val"><?php echo (int) $ap_pct; ?>%</div>
									<div class="hint"><?php echo (int)$ap_done; ?> done • <?php echo (int)$ap_total; ?> total</div>
								</div>
								<div class="dial" style="--pct:<?php echo (int) $ap_pct; ?>;"><span><?php echo (int) $ap_pct; ?>%</span></div>
							</div>
						</div>
					</div>

					<div style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:12px;">
						<div class="momentum">
							<span class="arrow"><?php echo $momentum_dir === 'up' ? '↗' : ( $momentum_dir === 'down' ? '↘' : '→' ); ?></span>
							<?php echo esc_html( $momentum_label ); ?>
							<span style="opacity:.75;">•</span>
							Δ <?php echo esc_html( number_format_i18n( (float) $momentum_delta, 2 ) ); ?>
						</div>
						<div class="section-sub">
							<?php echo $movers_enabled ? 'Based on last 30 days vs previous 30 days' : 'Trend/movers require results created_at.'; ?>
						</div>
					</div>
				</div>

				<div class="icon-card">
					<div class="section-title">Dashboard insights</div>
					<p class="section-sub">Trends over time. What is moving. Where to focus next.</p>

					<div class="dash-grid" style="margin-top:12px;">
						<div class="panel">
							<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
								<div>
									<div style="font-weight:950;color:var(--ink);">Portfolio trend</div>
									<p class="section-sub">
										<?php if ( ! empty( $trend_labels ) ) : ?>
											Last <?php echo (int) count($trend_labels); ?> months
										<?php else : ?>
											Trend not available yet.
										<?php endif; ?>
									</p>
								</div>
								<div class="momentum">
									<span class="arrow"><?php echo $momentum_dir === 'up' ? '↑' : ( $momentum_dir === 'down' ? '↓' : '•' ); ?></span>
									<?php echo esc_html( $momentum_label ); ?>
								</div>
							</div>

							<div style="margin-top:10px;"><?php echo $trend_svg; ?></div>

							<?php if ( ! empty( $trend_labels ) ) : ?>
								<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
									<?php foreach ( $trend_labels as $ym ) : ?>
										<span style="font-size:11px;font-weight:900;color:var(--muted);"><?php echo esc_html( icon_psy_trends_fmt_month( $ym ) ); ?></span>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>

						<div class="panel">
							<div style="font-weight:950;color:var(--ink);">Action plan progress</div>
							<p class="section-sub">
								Done: <?php echo (int)$ap_done; ?> • In progress: <?php echo (int)$ap_prog; ?> • Open: <?php echo (int)$ap_open; ?> • Total: <?php echo (int)$ap_total; ?>
							</p>
							<div class="meter"><span></span></div>
						</div>
					</div>

					<div class="dual" style="margin-top:12px;">
						<div class="panel">
							<div class="section-title">Top 5 strengths</div>
							<p class="section-sub">What’s working across your projects.</p>
							<?php if ( empty($top5) ) : ?>
								<p class="section-sub" style="margin-top:10px;">No items found yet.</p>
							<?php else : foreach ( $top5 as $x ) :
								$score = (float) $x['score'];
								$pct = icon_psy_trends_norm_pct( $score, 1, 7 );
							?>
								<div class="item-row">
									<div style="min-width:0;">
										<div class="item-name"><?php echo esc_html( (string) $x['name'] ); ?></div>
										<div class="item-meta"><?php echo esc_html( strtoupper( (string) $x['type'] ) ); ?></div>
										<div class="bar"><span style="width:<?php echo (int) $pct; ?>%;"></span></div>
									</div>
									<div class="score"><?php echo esc_html( number_format_i18n( $score, 1 ) . '/7' ); ?></div>
								</div>
							<?php endforeach; endif; ?>
						</div>

						<div class="panel">
							<div class="section-title">Bottom 5 priorities</div>
							<p class="section-sub">Where improvement will move the needle fastest.</p>
							<?php if ( empty($bot5) ) : ?>
								<p class="section-sub" style="margin-top:10px;">No items found yet.</p>
							<?php else : foreach ( $bot5 as $x ) :
								$score = (float) $x['score'];
								$pct = icon_psy_trends_norm_pct( $score, 1, 7 );
							?>
								<div class="item-row">
									<div style="min-width:0;">
										<div class="item-name"><?php echo esc_html( (string) $x['name'] ); ?></div>
										<div class="item-meta"><?php echo esc_html( strtoupper( (string) $x['type'] ) ); ?></div>
										<div class="bar"><span style="width:<?php echo (int) $pct; ?>%;"></span></div>
									</div>
									<div class="score"><?php echo esc_html( number_format_i18n( $score, 1 ) . '/7' ); ?></div>
								</div>
							<?php endforeach; endif; ?>
						</div>
					</div>

					<div class="movers">
						<div class="panel">
							<div class="section-title">Top movers</div>
							<p class="section-sub"><?php echo $movers_enabled ? 'Calculated from recent results (30d vs previous 30d).' : 'Requires results created_at.'; ?></p>

							<?php if ( $movers_enabled && ( ! empty($movers_up) || ! empty($movers_down) ) ) : ?>
								<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px;">
									<div>
										<div style="font-weight:950;">Improving</div>
										<?php if ( empty($movers_up) ) : ?>
											<p class="section-sub" style="margin-top:8px;">No clear improvers yet.</p>
										<?php else : foreach ( $movers_up as $m ) : ?>
											<div class="mover">
												<div style="min-width:0;">
													<div class="item-name"><?php echo esc_html( (string) $m['name'] ); ?></div>
													<div class="item-meta"><?php echo esc_html( strtoupper( (string) $m['type'] ) ); ?> • Now <?php echo esc_html( icon_psy_trends_safe_num($m['now'], 1) ); ?>/7</div>
												</div>
												<div class="delta up">+<?php echo esc_html( icon_psy_trends_safe_num($m['delta'], 2) ); ?></div>
											</div>
										<?php endforeach; endif; ?>
									</div>

									<div>
										<div style="font-weight:950;">Declining</div>
										<?php if ( empty($movers_down) ) : ?>
											<p class="section-sub" style="margin-top:8px;">No clear declines yet.</p>
										<?php else : foreach ( $movers_down as $m ) : ?>
											<div class="mover">
												<div style="min-width:0;">
													<div class="item-name"><?php echo esc_html( (string) $m['name'] ); ?></div>
													<div class="item-meta"><?php echo esc_html( strtoupper( (string) $m['type'] ) ); ?> • Now <?php echo esc_html( icon_psy_trends_safe_num($m['now'], 1) ); ?>/7</div>
												</div>
												<div class="delta down"><?php echo esc_html( icon_psy_trends_safe_num($m['delta'], 2) ); ?></div>
											</div>
										<?php endforeach; endif; ?>
									</div>
								</div>
							<?php else : ?>
								<p class="section-sub" style="margin-top:10px;">Not enough recent data to calculate movers yet.</p>
							<?php endif; ?>
						</div>

						<div class="focus">
							<h3>Next best focus</h3>
							<p class="section-sub" style="position:relative;">
								This button takes you straight to the action plan for your lowest scoring project.
							</p>

							<div class="chips">
								<?php if ( ! empty( $focus_items ) ) : foreach ( $focus_items as $fi ) : ?>
									<span class="chip pri"><span class="dot"></span><?php echo esc_html( (string) $fi['name'] ); ?></span>
								<?php endforeach; endif; ?>
								<span class="chip ap"><span class="dot"></span>Completion: <?php echo (int) $ap_pct; ?>%</span>
							</div>

							<ul>
								<li>Pick ONE behaviour change for the next 14 days.</li>
								<li>Keep it visible. Review weekly. Capture one example of improvement.</li>
								<?php if ( $ap_pct < 40 ) : ?><li>Action completion is your lever. Raise completion to accelerate progress.</li><?php endif; ?>
								<?php if ( $lowest_project_id > 0 ) : ?><li>We’re routing you into Project ID <?php echo (int) $lowest_project_id; ?> (lowest scoring).</li><?php endif; ?>
							</ul>

							<div style="margin-top:12px;position:relative;">
								<a class="icon-btn primary" href="<?php echo esc_url( $focus_action_link ); ?>" style="display:inline-flex;">Open focus action plan</a>
							</div>
						</div>
					</div>

				</div>

				<div class="icon-card">
					<div class="section-title">Client Wow</div>
					<p class="section-sub">A beautiful portfolio view. Each project shows score, completion, and a trend sparkline.</p>

					<div class="projects-grid">
						<?php foreach ( (array) $project_cards as $pc ) :
							$pid   = (int) $pc['id'];
							$score = $pc['score'];
							$pct   = (int) $pc['score_pct'];
							$ap    = (array) $pc['ap'];
							$ap_p  = isset($ap['pct']) ? (int) $ap['pct'] : 0;

							$participant_id = icon_psy_trends_get_project_participant_id( $pid );

							// Strong detection: check actual stored results (schema icon_traits_v1)
							$is_traits = icon_psy_trends_project_has_traits_results( $pid );

							// Fallback (only if no results exist yet): keep your name/type heuristic
							if ( ! $is_traits ) {
								$proj_row = isset($project_map[$pid]) ? $project_map[$pid] : null;
								if ( icon_psy_trends_project_is_traits( $proj_row ) ) $is_traits = true;
							}

							if ( $is_traits ) {
								if ( $participant_id > 0 ) {
									$proj_link = home_url( '/traits-report/?participant_id=' . (int) $participant_id . '&project_id=' . (int) $pid );
								} else {
									$proj_link = home_url( '/catalyst-portal/' );
								}
							} else {
								$proj_link = home_url( '/team-report/?project_id=' . (int) $pid );
							}

							$ap_link = home_url( '/action-plan/?project_id=' . (int) $pid . ( $is_traits ? '&mode=traits' : '' ) );

							$spark = '';
							if ( ! empty( $pc['spark'] ) ) $spark = icon_psy_trends_build_sparkline( $pc['spark'], 140, 44 );
							else $spark = icon_psy_trends_build_sparkline( array( $score, $score ), 140, 44 );
						?>
							<div class="proj">
								<div class="proj-top">
									<div style="min-width:0;">
										<div class="proj-name"><?php echo esc_html( (string) $pc['name'] ); ?></div>
										<div class="proj-meta">Project ID <?php echo (int) $pid; ?></div>
									</div>
									<div class="badges">
										<span class="badge score"><span class="dot"></span>
											<?php echo $score === null ? 'Score —' : esc_html( number_format_i18n( (float) $score, 1 ) . '/7' ); ?>
										</span>
										<span class="badge ap"><span class="dot"></span>
											<?php echo $action_plans_available ? ( 'Actions ' . (int) $ap_p . '%' ) : 'Actions —'; ?>
										</span>
									</div>
								</div>

								<div class="proj-row">
									<div style="flex:1;min-width:0;">
										<div style="font-size:12px;color:var(--mid);font-weight:850;margin-top:8px;">Project score</div>
										<div class="bar"><span style="width:<?php echo (int) $pct; ?>%;"></span></div>
									</div>
									<div class="proj-spark"><?php echo $spark; ?></div>
								</div>

								<div class="proj-row">
									<div style="flex:1;min-width:0;">
										<div style="font-size:12px;color:var(--mid);font-weight:850;margin-top:8px;">Action completion</div>
										<div class="bar"><span style="width:<?php echo (int) $ap_p; ?>%;"></span></div>
									</div>
								</div>

								<div class="proj-actions">
									<a href="<?php echo esc_url( $proj_link ); ?>">View summary</a>
									<a class="primary" href="<?php echo esc_url( $ap_link ); ?>">Action plan</a>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

add_action( 'init', function(){
	if ( shortcode_exists( 'icon_psy_client_trends' ) ) return;
	add_shortcode( 'icon_psy_client_trends', 'icon_psy_client_trends_shortcode' );
}, 20 );
