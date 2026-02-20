<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ICON Catalyst — Project Action Plan (Client)
 * Shortcode: [icon_psy_action_plan]
 * URL: /action-plan/?project_id=123
 *
 * v4.4 (Trait-compatible: Team + 360 + Traits) — FULL FILE
 * ✅ Supports traits schema icon_traits_v1 with items map (detail_json.items)
 * ✅ Extracts trait_item_id + trait_name
 * ✅ Trait score avg(q1,q2,q3) when present
 * ✅ Auto-detect traits schema from latest completed result and forces TEAM mode
 * ✅ Keeps your full UI + handlers + PRG + Branding
 */

/* ------------------------------------------------------------
 * Load Team Report functions (ONLY if needed)
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_team_get_heatmap_rows' ) ) {
	$team_report_path = plugin_dir_path(__FILE__) . 'team-report.php';
	if ( file_exists( $team_report_path ) ) {
		require_once $team_report_path;
	}
}

/* ------------------------------------------------------------
 * Tables
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_action_plan_install_tables' ) ) {
	function icon_psy_action_plan_install_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$plans_table = $wpdb->prefix . 'icon_psy_action_plans';
		$items_table = $wpdb->prefix . 'icon_psy_action_plan_items';

		$sql1 = "CREATE TABLE {$plans_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT(20) UNSIGNED NOT NULL,
			client_user_id BIGINT(20) UNSIGNED NOT NULL,
			title VARCHAR(190) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY client_user_id (client_user_id)
		) {$charset};";

		// NOTE: item_ref_id + item_type are NEW (trait compatible, non-breaking)
		$sql2 = "CREATE TABLE {$items_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_id BIGINT(20) UNSIGNED NOT NULL,
			item_ref_id BIGINT(20) UNSIGNED NULL,
			item_type VARCHAR(20) NOT NULL DEFAULT 'competency',
			pillar VARCHAR(190) NOT NULL DEFAULT '',
			theme VARCHAR(190) NOT NULL DEFAULT '',
			action_text TEXT NOT NULL,
			owner VARCHAR(190) NOT NULL DEFAULT '',
			due_date DATE NULL,
			priority VARCHAR(20) NOT NULL DEFAULT 'medium',
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY plan_id (plan_id),
			KEY status (status),
			KEY item_ref_id (item_ref_id),
			KEY item_type (item_type)
		) {$charset};";

		dbDelta( $sql1 );
		dbDelta( $sql2 );
	}
}

add_action( 'init', function(){
	// Only run dbDelta in admin (and only for admins)
	if ( ! is_admin() ) return;
	if ( ! current_user_can('manage_options') ) return;
	icon_psy_action_plan_install_tables();
}, 5 );

/* ------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_action_plan_get_effective_client_id' ) ) {
	function icon_psy_action_plan_get_effective_client_id() {
		$u = wp_get_current_user();
		if ( ! $u || ! $u->ID ) return 0;

		// Support admin impersonation if your portal uses this meta
		if ( current_user_can( 'manage_options' ) ) {
			$imp = (int) get_user_meta( (int) $u->ID, 'icon_psy_impersonate_client_id', true );
			if ( $imp > 0 ) return $imp;
		}
		return (int) $u->ID;
	}
}

if ( ! function_exists( 'icon_psy_action_plan_decode_json' ) ) {
	function icon_psy_action_plan_decode_json( $value ) {
		if ( ! is_string( $value ) ) return null;
		$v = trim( $value );
		if ( $v === '' ) return null;
		$dec = json_decode( $v, true );
		return is_array( $dec ) ? $dec : null;
	}
}

/**
 * Normalise payload into a flat list of item rows.
 * Supports:
 * - root list
 * - {items:{key:{...}}}
 * - {items:[...]}
 * - common alternates: responses/answers/rows/data/results
 */
if ( ! function_exists( 'icon_psy_action_plan_normalise_payload' ) ) {
	function icon_psy_action_plan_normalise_payload( $decoded ) {
		if ( ! is_array( $decoded ) ) return array();

		// Root list
		$is_list = array_keys($decoded) === range(0, count($decoded)-1);
		if ( $is_list ) {
			$out = array();
			foreach ( $decoded as $v ) if ( is_array($v) ) $out[] = $v;
			return $out;
		}

		foreach ( array('items','responses','answers','rows','data','results') as $k ) {
			if ( isset($decoded[$k]) && is_array($decoded[$k]) ) {
				$inner = $decoded[$k];

				$inner_is_list = array_keys($inner) === range(0, count($inner)-1);
				if ( $inner_is_list ) {
					$out = array();
					foreach ( $inner as $v ) if ( is_array($v) ) $out[] = $v;
					return $out;
				}

				// Map => values
				$out = array();
				foreach ( $inner as $v ) if ( is_array($v) ) $out[] = $v;
				return $out;
			}
		}

		// Fallback: any child arrays
		$out = array();
		foreach ( $decoded as $v ) if ( is_array($v) ) $out[] = $v;
		return $out;
	}
}

/**
 * Score resolver
 * - Traits: avg(q1,q2,q3) if at least 2 numeric values exist
 * - Else: q1, rating, overall, score, avg, mean...
 */
if ( ! function_exists( 'icon_psy_action_plan_row_score' ) ) {
	function icon_psy_action_plan_row_score( $row ) {
		if ( is_array( $row ) ) {

			// Traits average across q1..q3 when present
			$qvals = array();
			foreach ( array('q1','q2','q3') as $qk ) {
				if ( isset($row[$qk]) && is_numeric($row[$qk]) ) $qvals[] = (float) $row[$qk];
			}
			if ( count($qvals) >= 2 ) {
				return array_sum($qvals) / count($qvals);
			}

			// Common q1
			if ( isset( $row['q1'] ) ) {
				if ( is_numeric( $row['q1'] ) ) return (float) $row['q1'];
				if ( is_array( $row['q1'] ) ) {
					if ( isset($row['q1']['rating']) && is_numeric($row['q1']['rating']) ) return (float) $row['q1']['rating'];
					if ( isset($row['q1']['score']) && is_numeric($row['q1']['score']) ) return (float) $row['q1']['score'];
					if ( isset($row['q1']['value']) && is_numeric($row['q1']['value']) ) return (float) $row['q1']['value'];
				}
			}

			foreach ( array('rating','overall','score','avg','mean','value','answer','q1_rating') as $k ) {
				if ( isset( $row[$k] ) && is_numeric( $row[$k] ) ) return (float) $row[$k];
			}
		}

		if ( is_object( $row ) ) {
			// If the row is an object, try common numeric fields
			foreach ( array('q1_rating','rating','overall','score','avg','mean') as $k ) {
				if ( isset($row->$k) && is_numeric($row->$k) ) return (float) $row->$k;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'icon_psy_action_plan_priority_for_score' ) ) {
	function icon_psy_action_plan_priority_for_score( $score ) {
		if ( $score === null ) return 'medium';
		$s = (float) $score;
		if ( $s <= 3.9 ) return 'high';
		if ( $s <= 5.2 ) return 'medium';
		return 'low';
	}
}

if ( ! function_exists( 'icon_psy_action_plan_cadence_for_score' ) ) {
	function icon_psy_action_plan_cadence_for_score( $score, $is_strength = false ) {
		if ( $is_strength ) return 'Monthly check-in';
		if ( $score === null ) return 'Weekly';
		$s = (float) $score;
		if ( $s <= 3.9 ) return 'Weekly';
		if ( $s <= 5.2 ) return 'Fortnightly';
		return 'Monthly check-in';
	}
}

if ( ! function_exists( 'icon_psy_action_plan_default_due_date' ) ) {
	function icon_psy_action_plan_default_due_date( $days = 30 ) {
		$ts = current_time( 'timestamp' );
		return gmdate( 'Y-m-d', $ts + ( (int) $days * DAY_IN_SECONDS ) );
	}
}

if ( ! function_exists( 'icon_psy_action_plan_user_owns_item' ) ) {
	function icon_psy_action_plan_user_owns_item( $item_id, $plan_id, $user_id, $is_admin, $items_table, $plans_table, $wpdb ) {
		if ( $is_admin ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$items_table} WHERE id=%d AND plan_id=%d",
				(int) $item_id, (int) $plan_id
			) );
		}

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$items_table} i
			 INNER JOIN {$plans_table} p ON p.id = i.plan_id
			 WHERE i.id=%d AND p.id=%d AND p.client_user_id=%d",
			(int) $item_id, (int) $plan_id, (int) $user_id
		) );
	}
}

/**
 * Detect which results table/column exists.
 * Your debug shows qef_icon_assessment_results with detail_json.
 */
if ( ! function_exists('icon_psy_action_plan_detect_results_source') ) {
	function icon_psy_action_plan_detect_results_source() {
		global $wpdb;

		$candidates = array(
			array('table' => $wpdb->prefix . 'icon_assessment_results',     'json' => 'detail_json'),
			array('table' => $wpdb->prefix . 'icon_psy_assessment_results', 'json' => 'detail_json'),
			array('table' => $wpdb->prefix . 'icon_assessment_results',     'json' => 'details_json'),
			array('table' => $wpdb->prefix . 'icon_psy_assessment_results', 'json' => 'details_json'),
			array('table' => $wpdb->prefix . 'icon_psy_results',            'json' => 'detail_json'),
			array('table' => $wpdb->prefix . 'icon_psy_results',            'json' => 'data_json'),
		);

		foreach ( $candidates as $c ) {
			$exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $c['table']) );
			if ( ! $exists ) continue;

			$cols = $wpdb->get_results( "SHOW COLUMNS FROM {$c['table']}", ARRAY_A );
			if ( empty($cols) ) continue;

			$names = array();
			foreach ( $cols as $col ) {
				if ( ! empty($col['Field']) ) $names[] = (string) $col['Field'];
			}

			if ( in_array($c['json'], $names, true) && in_array('project_id', $names, true) ) {
				return array(
					'table' => $c['table'],
					'json'  => $c['json'],
					'cols'  => $names,
				);
			}
		}

		return array(
			'table' => $wpdb->prefix . 'icon_assessment_results',
			'json'  => 'detail_json',
			'cols'  => array(),
		);
	}
}

/**
 * Fetch result rows using the detected source.
 */
if ( ! function_exists('icon_psy_action_plan_fetch_result_rows') ) {
	function icon_psy_action_plan_fetch_result_rows( $project_id ) {
		global $wpdb;

		$src   = icon_psy_action_plan_detect_results_source();
		$table = $src['table'];
		$json  = $src['json'];
		$cols  = isset($src['cols']) ? (array) $src['cols'] : array();

		if ( empty($cols) ) {
			// Try to load columns if missing
			$cols_raw = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
			$cols = array();
			foreach ( (array) $cols_raw as $c ) if ( ! empty($c['Field']) ) $cols[] = (string) $c['Field'];
			$src['cols'] = $cols;
		}

		if ( ! in_array('project_id', $cols, true) ) {
			return array('rows'=>array(), 'src'=>$src, 'where'=>'(no project_id column)', 'table'=>$table, 'json'=>$json, 'cols'=>$cols);
		}

		$select = array('id');
		foreach ( array('rater_id','rater_type','rater_user_id','subject_user_id','self_user_id','participant_id','status') as $maybe ) {
			if ( in_array($maybe, $cols, true) ) $select[] = $maybe;
		}
		$select[] = "{$json} AS detail_json";

		$sql = "SELECT " . implode(', ', $select) . "
			FROM {$table}
			WHERE project_id=%d
			  AND {$json} IS NOT NULL
			  AND {$json} <> ''
			ORDER BY id DESC";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, (int) $project_id ) );

		return array('rows'=>$rows, 'src'=>$src, 'where'=>'project_id=%d', 'table'=>$table, 'json'=>$json, 'cols'=>$cols);
	}
}

/**
 * Detect traits by schema in latest completed result.
 * If schema begins with "icon_traits_" => traits project.
 */
if ( ! function_exists('icon_psy_action_plan_is_traits_project') ) {
	function icon_psy_action_plan_is_traits_project( $project_id ) {
		$f = icon_psy_action_plan_fetch_result_rows( (int) $project_id );
		if ( empty($f['rows']) ) return false;

		foreach ( (array) $f['rows'] as $r ) {
			$st = '';
			if ( isset($r->status) ) $st = strtolower( trim((string)$r->status) );
			if ( $st !== '' && $st !== 'completed' ) continue;

			if ( empty($r->detail_json) ) continue;

			$decoded = icon_psy_action_plan_decode_json( (string) $r->detail_json );
			if ( ! is_array($decoded) ) continue;

			$schema = isset($decoded['schema']) ? strtolower( trim((string)$decoded['schema']) ) : '';
			if ( $schema !== '' && strpos($schema, 'icon_traits_') === 0 ) return true;

			// Some payloads might not set schema but include trait_source
			$tsrc = isset($decoded['trait_source']) ? strtolower( trim((string)$decoded['trait_source']) ) : '';
			if ( $tsrc !== '' ) return true;
		}

		return false;
	}
}

/**
 * Mode resolver: team (default) or 360.
 * ✅ FIX: traits projects are forced to team to avoid "No 360 data" on traits-only runs.
 */
if ( ! function_exists( 'icon_psy_action_plan_get_mode' ) ) {
	function icon_psy_action_plan_get_mode( $project = null, $project_id = 0 ) {

		if ( $project_id > 0 && icon_psy_action_plan_is_traits_project( (int) $project_id ) ) {
			return 'team';
		}

		$mode = isset($_GET['mode']) ? sanitize_key( wp_unslash($_GET['mode']) ) : '';
		if ( in_array( $mode, array('team','360'), true ) ) return $mode;

		if ( is_object( $project ) ) {
			foreach ( array('type','project_type','tool','tool_key','assessment_type') as $k ) {
				if ( isset($project->$k) && is_string($project->$k) ) {
					$v = strtolower( trim( (string) $project->$k ) );
					if ( strpos($v, '360') !== false ) return '360';
					if ( strpos($v, 'team') !== false ) return 'team';
				}
			}
		}

		return 'team';
	}
}

/**
 * Trait/Competency compatible:
 * - Extract an "item reference" (id + type + label) from a payload row.
 * - ✅ FIX for traits: supports trait_item_id + trait_name (your schema)
 */
if ( ! function_exists( 'icon_psy_action_plan_extract_item_ref' ) ) {
	function icon_psy_action_plan_extract_item_ref( $it ) {
		$out = array(
			'id'    => 0,
			'type'  => '',
			'label' => '',
		);

		if ( ! is_array( $it ) ) return $out;

		// ✅ Traits schema
		if ( isset( $it['trait_item_id'] ) && is_numeric( $it['trait_item_id'] ) ) {
			$out['id']   = (int) $it['trait_item_id'];
			$out['type'] = 'trait';
		}

		// Generic IDs (fallback)
		if ( $out['id'] <= 0 ) {
			if ( isset( $it['trait_id'] ) && is_numeric( $it['trait_id'] ) ) {
				$out['id']   = (int) $it['trait_id'];
				$out['type'] = 'trait';
			} elseif ( isset( $it['competency_id'] ) && is_numeric( $it['competency_id'] ) ) {
				$out['id']   = (int) $it['competency_id'];
				$out['type'] = 'competency';
			} elseif ( isset( $it['item_id'] ) && is_numeric( $it['item_id'] ) ) {
				$out['id']   = (int) $it['item_id'];
				$out['type'] = 'item';
			} elseif ( isset( $it['id'] ) && is_numeric( $it['id'] ) ) {
				$out['id']   = (int) $it['id'];
				$out['type'] = 'item';
			}
		}

		// ✅ Label keys, traits uses trait_name
		foreach ( array('trait_name','name','title','label','trait','competency','item_name','question','text') as $k ) {
			if ( isset( $it[$k] ) && is_string( $it[$k] ) ) {
				$lab = trim( (string) $it[$k] );
				if ( $lab !== '' ) { $out['label'] = $lab; break; }
			}
		}

		// Type hint fallback
		if ( $out['type'] === '' && ! empty($it['type']) && is_string($it['type']) ) {
			$t = strtolower( trim((string)$it['type']) );
			if ( strpos($t, 'trait') !== false ) $out['type'] = 'trait';
		}
		if ( $out['type'] === '' ) $out['type'] = 'item';

		return $out;
	}
}

/**
 * Resolve item name (competency or trait) from an ID by searching likely tables.
 * Falls back to "Item #ID" if it cannot be resolved.
 */
if ( ! function_exists( 'icon_psy_action_plan_get_item_name' ) ) {
	function icon_psy_action_plan_get_item_name( $item_id ) {
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
			$wpdb->prefix . 'icon_psy_ai_draft_competencies', // your AI traits/competencies drafts often live here
			$wpdb->prefix . 'icon_psy_traits',
			$wpdb->prefix . 'icon_psy_trait_bank',
		);

		$name_cols = array(
			'name','title','label','competency','competency_name',
			'trait','trait_name','item_name','question','text'
		);

		foreach ( $candidates as $table ) {

			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
			if ( ! $exists ) continue;

			$cols = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
			if ( empty( $cols ) ) continue;

			$col_names = array();
			foreach ( $cols as $c ) {
				if ( ! empty( $c['Field'] ) ) $col_names[] = (string) $c['Field'];
			}

			$name_col = '';
			foreach ( $name_cols as $nc ) {
				if ( in_array( $nc, $col_names, true ) ) { $name_col = $nc; break; }
			}
			if ( $name_col === '' ) continue;

			$id_candidates = array( 'id', 'competency_id', 'trait_id', 'item_id', 'trait_item_id' );
			$id_col = '';
			foreach ( $id_candidates as $ic ) {
				if ( in_array( $ic, $col_names, true ) ) { $id_col = $ic; break; }
			}
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

/**
 * TEAM fallback: build heatmap-like rows from completed results rows.
 * ✅ FIX: Works with traits schema where items are nested under detail_json.items (map)
 */
if ( ! function_exists( 'icon_psy_action_plan_heatmap_from_results' ) ) {
	function icon_psy_action_plan_heatmap_from_results( $project_id, &$debug = array() ) {
		$f = icon_psy_action_plan_fetch_result_rows( (int) $project_id );
		$rows = $f['rows'];
		$debug = array(
			'rows' => $rows,
			'src'  => $f['src'],
			'where'=> $f['where'],
			'table'=> $f['table'],
			'json' => $f['json'],
			'extracted_item_rows' => 0,
		);

		if ( empty( $rows ) ) return array();

		$bucket = array(); // key => [name,sum,count,item_ref_id,item_type]
		$extracted = 0;

		foreach ( $rows as $r ) {

			// Completed only (if status exists)
			if ( isset($r->status) && strtolower((string)$r->status) !== 'completed' ) continue;

			if ( empty( $r->detail_json ) ) continue;

			$decoded = icon_psy_action_plan_decode_json( (string) $r->detail_json );
			if ( ! is_array( $decoded ) ) continue;

			$list = icon_psy_action_plan_normalise_payload( $decoded );
			if ( empty($list) ) continue;

			foreach ( $list as $it ) {
				if ( ! is_array( $it ) ) continue;

				$extracted++;

				$ref = icon_psy_action_plan_extract_item_ref( $it );
				$item_id = (int) $ref['id'];
				$item_type = $ref['type'] ? (string) $ref['type'] : 'item';

				$score = icon_psy_action_plan_row_score( $it );
				if ( $score === null ) continue;

				$name = '';
				if ( ! empty( $ref['label'] ) ) {
					$name = (string) $ref['label'];
				} elseif ( $item_id > 0 ) {
					$name = icon_psy_action_plan_get_item_name( $item_id );
				}

				$name = trim( (string) $name );
				if ( $name === '' ) {
					if ( $item_id > 0 ) $name = 'Item #' . $item_id;
					else continue;
				}

				$key = strtolower( $item_type ) . '|' . $name;

				if ( ! isset( $bucket[$key] ) ) {
					$bucket[$key] = array(
						'name'       => $name,
						'sum'        => 0,
						'count'      => 0,
						'item_ref_id'=> $item_id,
						'item_type'  => $item_type,
					);
				}
				$bucket[$key]['sum']   += (float) $score;
				$bucket[$key]['count'] += 1;
			}
		}

		$debug['extracted_item_rows'] = (int) $extracted;

		if ( empty( $bucket ) ) return array();

		$out = array();
		foreach ( $bucket as $agg ) {
			if ( empty( $agg['count'] ) ) continue;
			$out[] = array(
				'name'        => $agg['name'],
				'overall'     => $agg['sum'] / $agg['count'],
				'item_ref_id' => (int) $agg['item_ref_id'],
				'item_type'   => (string) $agg['item_type'],
			);
		}

		return $out;
	}
}

/**
 * 360 aggregation from results (Self vs Others).
 * Note: Traits projects are forced into TEAM mode by schema detection.
 * This remains for true 360 projects.
 */
if ( ! function_exists( 'icon_psy_action_plan_heatmap_360_from_results' ) ) {
	function icon_psy_action_plan_heatmap_360_from_results( $project_id, &$debug = array() ) {
		global $wpdb;

		$f = icon_psy_action_plan_fetch_result_rows( (int) $project_id );
		$rows = $f['rows'];

		$raters_table  = $wpdb->prefix . 'icon_psy_raters';
		$debug = array(
			'rows' => $rows,
			'src'  => $f['src'],
			'where'=> $f['where'],
			'table'=> $f['table'],
			'json' => $f['json'],
			'extracted_item_rows' => 0,
		);

		if ( empty( $rows ) ) return array();

		// Relationship map from raters table (if present)
		$rater_rel = array();
		$raters_exists = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $raters_table ) );

		if ( $raters_exists ) {
			$cols = $wpdb->get_results( "SHOW COLUMNS FROM {$raters_table}", ARRAY_A );
			$col_names = array();
			foreach ( (array) $cols as $c ) { if ( ! empty($c['Field']) ) $col_names[] = (string) $c['Field']; }

			if ( in_array('id', $col_names, true ) && in_array('relationship', $col_names, true ) ) {
				$rrels = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, relationship FROM {$raters_table} WHERE project_id=%d",
					(int) $project_id
				) );
				foreach ( (array) $rrels as $rr ) {
					$rater_rel[ (int) $rr->id ] = strtolower( trim( (string) $rr->relationship ) );
				}
			}
		}

		$bucket = array();
		$extracted = 0;

		foreach ( (array) $rows as $r ) {

			// Completed only (if status exists)
			if ( isset($r->status) && strtolower((string)$r->status) !== 'completed' ) continue;

			if ( empty( $r->detail_json ) ) continue;

			$decoded = icon_psy_action_plan_decode_json( (string) $r->detail_json );
			if ( ! is_array( $decoded ) ) continue;

			$list = icon_psy_action_plan_normalise_payload( $decoded );
			if ( empty($list) ) continue;

			// Determine "self" vs "others"
			$is_self = false;

			// rater_type column (if exists)
			if ( isset($r->rater_type) && is_string($r->rater_type) ) {
				$is_self = ( strtolower(trim((string)$r->rater_type)) === 'self' );
			}

			// rater relationship from raters table
			if ( ! $is_self ) {
				$rid = isset($r->rater_id) ? (int) $r->rater_id : 0;
				if ( $rid > 0 && isset($rater_rel[$rid]) ) {
					$is_self = ( (string) $rater_rel[$rid] === 'self' );
				}
			}

			// fallback using self_user_id == rater_user_id
			if ( ! $is_self && isset($r->self_user_id, $r->rater_user_id) ) {
				$is_self = ( (int) $r->self_user_id > 0 && (int) $r->self_user_id === (int) $r->rater_user_id );
			}

			foreach ( $list as $it ) {
				if ( ! is_array( $it ) ) continue;

				$extracted++;

				$ref = icon_psy_action_plan_extract_item_ref( $it );
				$item_id = (int) $ref['id'];
				$item_type = $ref['type'] ? (string) $ref['type'] : 'item';

				$score = icon_psy_action_plan_row_score( $it );
				if ( $score === null ) continue;

				$name = '';
				if ( ! empty( $ref['label'] ) ) {
					$name = (string) $ref['label'];
				} elseif ( $item_id > 0 ) {
					$name = icon_psy_action_plan_get_item_name( $item_id );
				}

				$name = trim( (string) $name );
				if ( $name === '' ) {
					if ( $item_id > 0 ) $name = 'Item #' . $item_id;
					else continue;
				}

				$key = strtolower( $item_type ) . '|' . $name;

				if ( ! isset( $bucket[$key] ) ) {
					$bucket[$key] = array(
						'name'        => $name,
						'item_ref_id' => $item_id,
						'item_type'   => $item_type,
						'self_sum'    => 0, 'self_count' => 0,
						'oth_sum'     => 0, 'oth_count'  => 0,
					);
				}

				if ( $is_self ) {
					$bucket[$key]['self_sum']   += (float) $score;
					$bucket[$key]['self_count'] += 1;
				} else {
					$bucket[$key]['oth_sum']    += (float) $score;
					$bucket[$key]['oth_count']  += 1;
				}
			}
		}

		$debug['extracted_item_rows'] = (int) $extracted;

		if ( empty( $bucket ) ) return array();

		$out = array();
		foreach ( $bucket as $agg ) {
			$self   = $agg['self_count'] ? ( $agg['self_sum'] / $agg['self_count'] ) : null;
			$others = $agg['oth_count']  ? ( $agg['oth_sum']  / $agg['oth_count'] )  : null;

			if ( $others === null && $self !== null ) $others = $self;

			$overall_sum   = 0;
			$overall_count = 0;
			if ( $self !== null )   { $overall_sum += (float) $self;   $overall_count++; }
			if ( $others !== null ) { $overall_sum += (float) $others; $overall_count++; }
			$overall = $overall_count ? ( $overall_sum / $overall_count ) : null;

			$out[] = array(
				'name'        => $agg['name'],
				'self'        => $self,
				'others'      => $others,
				'gap'         => ( $self !== null && $others !== null ) ? ( (float) $self - (float) $others ) : null,
				'overall'     => $overall,
				'item_ref_id' => (int) $agg['item_ref_id'],
				'item_type'   => (string) $agg['item_type'],
			);
		}

		return $out;
	}
}

/* ------------------------------------------------------------
 * Shortcode
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_action_plan_shortcode' ) ) {
	function icon_psy_action_plan_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>Please log in.</p>';
		}

		global $wpdb;

		$projects_table   = $wpdb->prefix . 'icon_psy_projects';
		$plans_table      = $wpdb->prefix . 'icon_psy_action_plans';
		$items_table      = $wpdb->prefix . 'icon_psy_action_plan_items';

		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;
		if ( $project_id <= 0 ) {
			return '<p><strong>Missing project_id.</strong> Open this page from the Client Portal project button.</p>';
		}

		$is_admin = current_user_can( 'manage_options' );
		$user_id  = $is_admin ? (int) icon_psy_action_plan_get_effective_client_id() : (int) get_current_user_id();

		// Security: ensure project belongs to client (admin can access any)
		$owns = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$projects_table} WHERE id=%d AND client_user_id=%d",
			$project_id, $user_id
		) );

		if ( ! $owns && ! $is_admin ) {
			return '<p>You do not have access to this project.</p>';
		}

		$project = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$projects_table} WHERE id=%d LIMIT 1",
			$project_id
		) );

		if ( ! $project ) {
			return '<p>Project not found.</p>';
		}

		// Mode (team vs 360) — traits auto-detect forces team
		$traits_project = icon_psy_action_plan_is_traits_project( (int) $project_id );
		$mode = icon_psy_action_plan_get_mode( $project, (int) $project_id );
		$is_360 = ( $mode === '360' );

		// ---------------------------
		// DEBUG (admin only)
		// ---------------------------
		$ap_debug = array();

		$debug_team_src = array();
		$debug_360_src  = array();

		if ( $is_admin ) {
			$ap_debug['mode'] = $mode;
			$ap_debug['traits_project'] = $traits_project ? 'yes' : 'no';

			$participants_table = $wpdb->prefix . 'icon_psy_participants';
			$raters_table       = $wpdb->prefix . 'icon_psy_raters';

			$ap_debug['db'] = array();
			$ap_debug['db']['participants_rows'] = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$participants_table} WHERE project_id=%d",
				(int) $project_id
			) );
			$ap_debug['db']['raters_rows'] = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$raters_table} WHERE project_id=%d",
				(int) $project_id
			) );

			// Show results source detection
			$f = icon_psy_action_plan_fetch_result_rows( (int) $project_id );
			$ap_debug['results_source'] = array(
				'table' => $f['table'],
				'json'  => $f['json'],
				'cols'  => $f['cols'],
				'rows_total' => is_array($f['rows']) ? count($f['rows']) : 0,
			);

			// Show sample decode structure
			if ( ! empty($f['rows']) ) {
				$sample = $f['rows'][0];
				if ( ! empty($sample->detail_json) ) {
					$decoded = icon_psy_action_plan_decode_json( (string) $sample->detail_json );
					if ( is_array($decoded) ) {
						$ap_debug['results_sample'] = array(
							'schema' => isset($decoded['schema']) ? $decoded['schema'] : '',
							'root_keys' => array_slice( array_keys($decoded), 0, 25 ),
						);

						$list = icon_psy_action_plan_normalise_payload( $decoded );
						if ( ! empty($list) && is_array($list[0]) ) {
							$first = $list[0];
							$ref = icon_psy_action_plan_extract_item_ref( $first );
							$ap_debug['results_sample']['first_value_keys'] = array_slice( array_keys($first), 0, 30 );
							$ap_debug['results_sample']['first_item_ref']   = $ref;
							$ap_debug['results_sample']['first_score']      = icon_psy_action_plan_row_score( $first );
						}
					}
				}
			}

			$hm_team = icon_psy_action_plan_heatmap_from_results( (int) $project_id, $debug_team_src );
			$hm_360  = icon_psy_action_plan_heatmap_360_from_results( (int) $project_id, $debug_360_src );

			$ap_debug['results_team_source'] = $debug_team_src;
			$ap_debug['results_360_source']  = $debug_360_src;

			$ap_debug['heatmap_team_count'] = is_array($hm_team) ? count($hm_team) : 0;
			$ap_debug['heatmap_360_count']  = is_array($hm_360) ? count($hm_360) : 0;

			if ( is_array($hm_360) && ! empty($hm_360) ) $ap_debug['heatmap_360_first'] = $hm_360[0];
			if ( is_array($hm_team) && ! empty($hm_team) ) $ap_debug['heatmap_team_first'] = $hm_team[0];
		}

		// Branding (fallback)
		$brand_primary   = '#15a06d';
		$brand_secondary = '#14a4cf';

		if ( function_exists( 'icon_psy_get_client_branding' ) ) {
			$b = icon_psy_get_client_branding( (int) $user_id );
			if ( is_array( $b ) ) {
				if ( ! empty( $b['primary'] ) )   $brand_primary   = (string) $b['primary'];
				if ( ! empty( $b['secondary'] ) ) $brand_secondary = (string) $b['secondary'];
			}
		}

		// PRG helper
		$page_url = function_exists( 'get_permalink' )
			? get_permalink()
			: home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ?? '' ) );

		$args = array( 'project_id' => (int) $project_id );
		if ( $is_360 ) $args['mode'] = '360';
		$page_url = add_query_arg( $args, $page_url );

		$notice = '';
		$error  = '';

		if ( isset( $_GET['ap_msg'] ) && $_GET['ap_msg'] !== '' ) $notice = sanitize_text_field( wp_unslash( $_GET['ap_msg'] ) );
		if ( isset( $_GET['ap_err'] ) && $_GET['ap_err'] !== '' ) $error  = sanitize_text_field( wp_unslash( $_GET['ap_err'] ) );

		$redirect = function( $msg = '', $err = '' ) use ( $page_url ) {
			$url = remove_query_arg( array( 'ap_msg', 'ap_err' ), $page_url );
			$args = array();
			if ( $msg ) $args['ap_msg'] = $msg;
			if ( $err ) $args['ap_err'] = $err;
			if ( $args ) $url = add_query_arg( $args, $url );
			wp_safe_redirect( $url );
			exit;
		};

		// Ensure plan exists
		$plan_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$plans_table} WHERE project_id=%d AND client_user_id=%d LIMIT 1",
			$project_id, $user_id
		) );

		if ( $plan_id <= 0 ) {
			$wpdb->insert(
				$plans_table,
				array(
					'project_id'     => (int) $project_id,
					'client_user_id' => (int) $user_id,
					'title'          => $is_360 ? '360 action plan' : 'Action plan',
					'created_at'     => current_time( 'mysql' ),
					'updated_at'     => current_time( 'mysql' ),
				),
				array( '%d','%d','%s','%s','%s' )
			);
			$plan_id = (int) $wpdb->insert_id;
		}

		// POST handlers
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['icon_ap_action'] ) ) {
			$ap_action = sanitize_key( wp_unslash( $_POST['icon_ap_action'] ) );

			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'icon_ap' ) ) {
				$redirect( '', 'Security check failed.' );
			}

			switch ( $ap_action ) {

				case 'generate_plan': {

					$heatmap_rows = array();

					if ( $is_360 ) {

						// 360 mode: build rows from results (self vs others)
						$heatmap_rows = icon_psy_action_plan_heatmap_360_from_results( (int) $project_id, $debug_360_src );

					} else {

						// TEAM mode

						// 1) Try native Team Report heatmap rows if available (competencies/team tool)
						if ( function_exists( 'icon_psy_team_get_heatmap_rows' ) && ! $traits_project ) {
							$heatmap_rows = icon_psy_team_get_heatmap_rows( (int) $project_id );
						}

						// 2) Traits or no team rows => use results-derived aggregation
						if ( empty( $heatmap_rows ) ) {
							$heatmap_rows = icon_psy_action_plan_heatmap_from_results( (int) $project_id, $debug_team_src );
						}
					}

					if ( empty( $heatmap_rows ) ) {
						$redirect( '', $is_360 ? 'No 360 data found for this project yet.' : 'No team data found for this project yet.' );
					}

					$existing = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$items_table} WHERE plan_id=%d",
						$plan_id
					) );

					if ( $existing > 0 ) {
						$redirect( '', 'Plan already contains items. Clear them first if you want to regenerate.' );
					}

					$rows = array();
					foreach ( (array) $heatmap_rows as $r ) {
						$name = '';
						$item_ref_id = 0;
						$item_type = 'item';

						if ( is_array( $r ) ) {
							if ( isset( $r['name'] ) ) $name = (string) $r['name'];
							if ( isset( $r['item_ref_id'] ) && is_numeric( $r['item_ref_id'] ) ) $item_ref_id = (int) $r['item_ref_id'];
							if ( isset( $r['item_type'] ) && is_string( $r['item_type'] ) ) $item_type = sanitize_key( $r['item_type'] );
						}
						if ( is_object( $r ) ) {
							if ( isset( $r->name ) ) $name = (string) $r->name;
							if ( isset( $r->item_ref_id ) && is_numeric( $r->item_ref_id ) ) $item_ref_id = (int) $r->item_ref_id;
							if ( isset( $r->item_type ) && is_string( $r->item_type ) ) $item_type = sanitize_key( $r->item_type );
						}

						$name = trim( $name );

						// Team: use overall; 360: prefer "others" as ranking score, fallback to overall
						if ( $is_360 && is_array( $r ) ) {
							$score = ( isset($r['others']) && is_numeric($r['others']) ) ? (float) $r['others']
								: ( ( isset($r['overall']) && is_numeric($r['overall']) ) ? (float) $r['overall'] : null );
						} else {
							$score = null;
							if ( is_array($r) && isset($r['overall']) && is_numeric($r['overall']) ) $score = (float) $r['overall'];
							if ( $score === null ) $score = icon_psy_action_plan_row_score( $r );
						}

						if ( $name === '' ) continue;
						if ( $score === null ) continue;

						$rows[] = array(
							'name'        => $name,
							'score'       => $score,
							'gap'         => ( $is_360 && is_array($r) && isset($r['gap']) && is_numeric($r['gap']) ) ? (float) $r['gap'] : null,
							'self'        => ( $is_360 && is_array($r) && isset($r['self']) && is_numeric($r['self']) ) ? (float) $r['self'] : null,
							'others'      => ( $is_360 && is_array($r) && isset($r['others']) && is_numeric($r['others']) ) ? (float) $r['others'] : null,
							'item_ref_id' => $item_ref_id,
							'item_type'   => $item_type,
						);
					}

					if ( empty( $rows ) ) {
						$redirect( '', $is_360 ? '360 data found, but no items were readable.' : 'Team data found, but no items were readable.' );
					}

					usort( $rows, function( $a, $b ){
						$as = $a['score']; $bs = $b['score'];
						if ( $as === null && $bs === null ) return 0;
						if ( $as === null ) return 1;
						if ( $bs === null ) return -1;
						if ( $as == $bs ) return 0;
						return ( $as > $bs ) ? -1 : 1;
					});

					$top_strengths = array_slice( $rows, 0, 2 );
					$bottom_areas  = array_slice( array_reverse( $rows ), 0, 3 );

					// 360: biggest positive gaps (self higher than others)
					$gap_focus = array();
					if ( $is_360 ) {
						$with_gap = array_values( array_filter( $rows, function($x){
							return isset($x['gap']) && $x['gap'] !== null;
						} ) );

						usort( $with_gap, function($a,$b){
							$ag = (float) $a['gap']; $bg = (float) $b['gap'];
							if ( $ag == $bg ) return 0;
							return ( $ag > $bg ) ? -1 : 1;
						});

						$gap_focus = array_slice( $with_gap, 0, 2 );
					}

					$now           = current_time('mysql');
					$default_due   = icon_psy_action_plan_default_due_date( 30 );
					$default_owner = $is_360 ? 'Participant' : 'Team Lead';

					foreach ( $top_strengths as $row ) {

						if ( $is_360 ) {
							$oth = isset($row['others']) && $row['others'] !== null ? (float) $row['others'] : (float) $row['score'];
							$score_txt = ' (Others ' . number_format_i18n( $oth, 1 ) . '/7)';
							$cadence   = icon_psy_action_plan_cadence_for_score( $oth, true );

							$action = 'Protect and repeat what’s working in "' . $row['name'] . '"' . $score_txt . '. ' .
								'Name one behaviour to keep. Use it intentionally in the next 2 weeks. ' .
								'Create a simple check-in: ' . $cadence . '.';
						} else {
							$score_txt = ' (Team score ' . number_format_i18n( (float) $row['score'], 1 ) . '/7)';
							$cadence   = icon_psy_action_plan_cadence_for_score( $row['score'], true );

							$action = 'Protect and repeat what’s working in "' . $row['name'] . '"' . $score_txt . '. ' .
								'Name one behaviour to keep. Reinforce it in the next team meeting. ' .
								'Create a simple check-in: ' . $cadence . '.';
						}

						$wpdb->insert(
							$items_table,
							array(
								'plan_id'     => (int) $plan_id,
								'item_ref_id' => ! empty($row['item_ref_id']) ? (int) $row['item_ref_id'] : null,
								'item_type'   => ! empty($row['item_type']) ? sanitize_key( (string) $row['item_type'] ) : 'item',
								'pillar'      => 'Strength',
								'theme'       => $row['name'],
								'action_text' => $action,
								'owner'       => $default_owner,
								'due_date'    => $default_due,
								'priority'    => 'low',
								'status'      => 'open',
								'notes'       => '',
								'created_at'  => $now,
								'updated_at'  => $now,
							),
							array( '%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' )
						);
					}

					foreach ( $bottom_areas as $row ) {

						if ( $is_360 ) {
							$oth = isset($row['others']) && $row['others'] !== null ? (float) $row['others'] : (float) $row['score'];
							$score_txt = ' (Others ' . number_format_i18n( $oth, 1 ) . '/7)';
							$priority  = icon_psy_action_plan_priority_for_score( $oth );
							$cadence   = icon_psy_action_plan_cadence_for_score( $oth, false );

							$action = 'Improve "' . $row['name'] . '"' . $score_txt . ' by choosing ONE visible behaviour change you will practise. ' .
								'Test it for 2 weeks. Review progress ' . $cadence . '. Capture one example of what improved.';
						} else {
							$score_txt = ' (Team score ' . number_format_i18n( (float) $row['score'], 1 ) . '/7)';
							$priority  = icon_psy_action_plan_priority_for_score( $row['score'] );
							$cadence   = icon_psy_action_plan_cadence_for_score( $row['score'], false );

							$action = 'Improve "' . $row['name'] . '"' . $score_txt . ' by agreeing ONE behaviour change the team will practise. ' .
								'Test it for 2 weeks. Review progress ' . $cadence . '. Capture one example of what improved.';
						}

						$wpdb->insert(
							$items_table,
							array(
								'plan_id'     => (int) $plan_id,
								'item_ref_id' => ! empty($row['item_ref_id']) ? (int) $row['item_ref_id'] : null,
								'item_type'   => ! empty($row['item_type']) ? sanitize_key( (string) $row['item_type'] ) : 'item',
								'pillar'      => 'Priority',
								'theme'       => $row['name'],
								'action_text' => $action,
								'owner'       => $default_owner,
								'due_date'    => $default_due,
								'priority'    => $priority,
								'status'      => 'open',
								'notes'       => '',
								'created_at'  => $now,
								'updated_at'  => $now,
							),
							array( '%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' )
						);
					}

					// 360 gap actions
					if ( $is_360 && ! empty( $gap_focus ) ) {
						foreach ( $gap_focus as $row ) {

							$self   = isset($row['self'])   ? (float) $row['self']   : null;
							$others = isset($row['others']) ? (float) $row['others'] : null;
							$gap    = isset($row['gap'])    ? (float) $row['gap']    : null;

							$score_txt = '';
							if ( $self !== null && $others !== null && $gap !== null ) {
								$score_txt = ' (Self ' . number_format_i18n($self, 1) . '/7 vs Others ' . number_format_i18n($others, 1) . '/7, gap ' . number_format_i18n($gap, 1) . ')';
							}

							$action = 'Close the perception gap in "' . $row['name'] . '"' . $score_txt . '. ' .
								'Ask 3 raters for one example of when you showed this well and one moment you could strengthen it. ' .
								'Pick ONE behaviour change for 14 days. Ask for a quick follow-up rating at the end.';

							$wpdb->insert(
								$items_table,
								array(
									'plan_id'     => (int) $plan_id,
									'item_ref_id' => ! empty($row['item_ref_id']) ? (int) $row['item_ref_id'] : null,
									'item_type'   => ! empty($row['item_type']) ? sanitize_key( (string) $row['item_type'] ) : 'item',
									'pillar'      => 'Gap',
									'theme'       => $row['name'],
									'action_text' => $action,
									'owner'       => 'Participant',
									'due_date'    => $default_due,
									'priority'    => 'high',
									'status'      => 'open',
									'notes'       => '',
									'created_at'  => $now,
									'updated_at'  => $now,
								),
								array( '%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' )
							);
						}
					}

					$redirect( 'Recommended plan created.', '' );
					break;
				}

				case 'clear_plan': {
					$wpdb->query( $wpdb->prepare(
						"DELETE FROM {$items_table} WHERE plan_id=%d",
						$plan_id
					) );
					$redirect( 'All actions cleared.', '' );
					break;
				}

				case 'add_item': {
					$pillar   = isset($_POST['pillar']) ? sanitize_text_field( wp_unslash($_POST['pillar']) ) : '';
					$theme    = isset($_POST['theme']) ? sanitize_text_field( wp_unslash($_POST['theme']) ) : '';
					$owner    = isset($_POST['owner']) ? sanitize_text_field( wp_unslash($_POST['owner']) ) : '';
					$due      = isset($_POST['due_date']) ? sanitize_text_field( wp_unslash($_POST['due_date']) ) : '';
					$priority = isset($_POST['priority']) ? sanitize_key( wp_unslash($_POST['priority']) ) : 'medium';

					$action_text = isset($_POST['action_text']) ? sanitize_textarea_field( wp_unslash($_POST['action_text']) ) : '';
					if ( trim( $action_text ) === '' ) $redirect( '', 'Action is required.' );

					$due_sql = null;
					if ( $due && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) ) $due_sql = $due;

					$priority = in_array( $priority, array('low','medium','high'), true ) ? $priority : 'medium';

					$ok = $wpdb->insert(
						$items_table,
						array(
							'plan_id'     => (int) $plan_id,
							'item_ref_id' => null,
							'item_type'   => 'manual',
							'pillar'      => $pillar,
							'theme'       => $theme,
							'action_text' => $action_text,
							'owner'       => $owner,
							'due_date'    => $due_sql,
							'priority'    => $priority,
							'status'      => 'open',
							'notes'       => '',
							'created_at'  => current_time('mysql'),
							'updated_at'  => current_time('mysql'),
						),
						array( '%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' )
					);

					if ( false === $ok ) $redirect( '', $wpdb->last_error ? $wpdb->last_error : 'Insert failed.' );
					$redirect( 'Action added.', '' );
					break;
				}

				case 'set_status': {
					$item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
					$status  = isset($_POST['status']) ? sanitize_key( wp_unslash($_POST['status']) ) : 'open';
					$status  = in_array( $status, array('open','in_progress','done'), true ) ? $status : 'open';

					$owns_item = icon_psy_action_plan_user_owns_item( $item_id, $plan_id, $user_id, $is_admin, $items_table, $plans_table, $wpdb );
					if ( ! $owns_item ) $redirect( '', 'Invalid item.' );

					$wpdb->update(
						$items_table,
						array( 'status' => $status, 'updated_at' => current_time('mysql') ),
						array( 'id' => (int) $item_id ),
						array( '%s','%s' ),
						array( '%d' )
					);

					$redirect( 'Status updated.', '' );
					break;
				}

				case 'update_item': {
					$item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;

					$owns_item = icon_psy_action_plan_user_owns_item( $item_id, $plan_id, $user_id, $is_admin, $items_table, $plans_table, $wpdb );
					if ( ! $owns_item ) $redirect( '', 'Invalid item.' );

					$pillar   = isset($_POST['pillar']) ? sanitize_text_field( wp_unslash($_POST['pillar']) ) : '';
					$theme    = isset($_POST['theme']) ? sanitize_text_field( wp_unslash($_POST['theme']) ) : '';
					$owner    = isset($_POST['owner']) ? sanitize_text_field( wp_unslash($_POST['owner']) ) : '';
					$due      = isset($_POST['due_date']) ? sanitize_text_field( wp_unslash($_POST['due_date']) ) : '';
					$priority = isset($_POST['priority']) ? sanitize_key( wp_unslash($_POST['priority']) ) : 'medium';

					$action_text = isset($_POST['action_text']) ? sanitize_textarea_field( wp_unslash($_POST['action_text']) ) : '';
					$notes       = isset($_POST['notes']) ? sanitize_text_field( wp_unslash($_POST['notes']) ) : '';

					if ( trim( $action_text ) === '' ) $redirect( '', 'Action is required.' );

					$due_sql = null;
					if ( $due && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) ) $due_sql = $due;

					$priority = in_array( $priority, array('low','medium','high'), true ) ? $priority : 'medium';

					$wpdb->update(
						$items_table,
						array(
							'pillar'      => $pillar,
							'theme'       => $theme,
							'action_text' => $action_text,
							'owner'       => $owner,
							'due_date'    => $due_sql,
							'priority'    => $priority,
							'notes'       => $notes,
							'updated_at'  => current_time('mysql'),
						),
						array( 'id' => (int) $item_id ),
						array( '%s','%s','%s','%s','%s','%s','%s','%s' ),
						array( '%d' )
					);

					$redirect( 'Action updated.', '' );
					break;
				}

				case 'delete_item': {
					$item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;

					$owns_item = icon_psy_action_plan_user_owns_item( $item_id, $plan_id, $user_id, $is_admin, $items_table, $plans_table, $wpdb );
					if ( ! $owns_item ) $redirect( '', 'Invalid item.' );

					$wpdb->delete( $items_table, array( 'id' => (int) $item_id ), array( '%d' ) );
					$redirect( 'Action deleted.', '' );
					break;
				}
			}
		}

		// Load items
		$items = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$items_table} WHERE plan_id=%d
			 ORDER BY FIELD(status,'open','in_progress','done'), due_date IS NULL, due_date ASC, id DESC",
			$plan_id
		) );

		$has_items = ! empty( $items );

		// --- Hero stats + logo (safe fallbacks)
		$items_count = is_array( $items ) ? count( $items ) : 0;
		$open_count  = 0;
		$prog_count  = 0;
		$done_count  = 0;

		if ( is_array( $items ) ) {
			foreach ( $items as $itx ) {
				$st = isset( $itx->status ) ? (string) $itx->status : '';
				if ( $st === 'open' ) $open_count++;
				elseif ( $st === 'in_progress' ) $prog_count++;
				elseif ( $st === 'done' ) $done_count++;
			}
		}

		$brand_logo_url = '';
		if ( function_exists( 'icon_psy_get_client_branding' ) ) {
			$bb = icon_psy_get_client_branding( (int) $user_id );
			if ( is_array( $bb ) ) {
				if ( ! empty( $bb['logo_url'] ) ) {
					$brand_logo_url = (string) $bb['logo_url'];
				} elseif ( ! empty( $bb['logo'] ) && is_string( $bb['logo'] ) ) {
					$brand_logo_url = (string) $bb['logo'];
				} elseif ( ! empty( $bb['logo_id'] ) && is_numeric( $bb['logo_id'] ) ) {
					$maybe = wp_get_attachment_image_url( (int) $bb['logo_id'], 'thumbnail' );
					if ( $maybe ) $brand_logo_url = (string) $maybe;
				}
			}
		}
		if ( ! $brand_logo_url && function_exists( 'get_site_icon_url' ) ) {
			$brand_logo_url = (string) get_site_icon_url( 128 );
		}

		// Summary URL (team vs 360)
		// Traits projects still use team mode; set summary to traits report if you have one
		$summary_url = $is_360
			? home_url('/feedback-report/?project_id=' . (int) $project_id)
			: home_url('/team-report/?project_id=' . (int) $project_id);

		// Copy / defaults (team vs 360)
		$rec_title = $is_360 ? 'Recommended 360 actions' : 'Recommended team actions';
		$rec_sub   = $is_360 ? 'Create a starter plan based on the 360 feedback results.' : 'Create a starter plan based on the team results.';
		$owner_default = $is_360 ? 'Participant' : 'Team Lead';

		ob_start();
		?>
		<style>
			:root{
				--icon-green: <?php echo esc_html( $brand_primary ); ?>;
				--icon-blue: <?php echo esc_html( $brand_secondary ); ?>;
				--text-dark:#0a3b34;
				--text-mid:#425b56;
				--text-light:#6a837d;
				--ink:#071b1a;
			}
			.icon-portal-shell{
				padding: 34px 16px 44px;
				background: radial-gradient(circle at top left, #e6f9ff 0%, #ffffff 40%, #e9f8f1 100%);
			}
			.icon-portal-wrap{max-width:1160px;margin:0 auto;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--text-dark);}
			.icon-rail{height:4px;border-radius:999px;background:linear-gradient(90deg,var(--icon-blue),var(--icon-green));opacity:.85;margin:0 0 12px;box-shadow:0 10px 24px rgba(20,164,207,.18);}
			.icon-card{background:#fff;border:1px solid rgba(20,164,207,.14);border-radius:22px;box-shadow:0 16px 40px rgba(0,0,0,.06);padding:18px;margin-bottom:14px;}
			.icon-h1{margin:0 0 6px;font-size:24px;font-weight:950;letter-spacing:-.02em;color:var(--ink);}
			.icon-sub{margin:0;color:var(--text-mid);font-size:13px;max-width:900px;}
			.icon-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
			.icon-space{justify-content:space-between;}
			.icon-btn{
				display:inline-flex;align-items:center;justify-content:center;border-radius:999px;border:1px solid transparent;
				background-image:linear-gradient(135deg,var(--icon-blue),var(--icon-green));
				color:#fff;padding:10px 14px;font-size:13px;font-weight:950;cursor:pointer;text-decoration:none;white-space:nowrap;
				box-shadow:0 12px 30px rgba(20,164,207,.30);
			}
			.icon-btn-ghost{
				display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#fff;
				border:1px solid rgba(21,149,136,.35);color:var(--icon-green);padding:10px 14px;font-size:13px;font-weight:950;cursor:pointer;text-decoration:none;white-space:nowrap;
			}
			.icon-btn-danger{
				display:inline-flex;align-items:center;justify-content:center;border-radius:999px;border:1px solid rgba(185,28,28,.25);
				background:rgba(254,226,226,.55);color:#7f1d1d;padding:10px 14px;font-size:13px;font-weight:950;cursor:pointer;white-space:nowrap;
			}
			.icon-msg{border-radius:18px;padding:12px 14px;font-weight:950;border:1px solid rgba(226,232,240,.9);}
			.icon-msg.err{border-color:rgba(185,28,28,.25);background:rgba(254,226,226,.38);color:#7f1d1d;}
			.icon-msg.ok{border-color:rgba(21,160,109,.25);background:rgba(236,253,245,.75);color:#065f46;}
			.icon-label{display:block;font-size:12px;color:var(--text-light);font-weight:950;margin-bottom:6px;}
			.icon-input,.icon-select,.icon-textarea{width:100%;border-radius:14px;border:1px solid rgba(148,163,184,.55);padding:10px 12px;font-size:13px;color:var(--text-dark);background:#fff;box-sizing:border-box;}
			.icon-textarea{min-height:90px;resize:vertical;}
			.icon-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
			@media(max-width: 980px){.icon-grid-3{grid-template-columns:1fr;}}

			/* HERO (top card) */
			.icon-hero{
				position:relative;
				border-radius:26px;
				overflow:hidden;
				padding:22px 22px;
				border:1px solid rgba(255,255,255,.35);
				box-shadow:0 18px 50px rgba(0,0,0,.10);
				background:linear-gradient(90deg, #4e46ff 0%, var(--icon-blue) 50%, var(--icon-green) 100%);
				color:#fff;
				margin-bottom:14px;
			}
			.icon-hero:before,
			.icon-hero:after{
				content:"";
				position:absolute;
				border-radius:999px;
				opacity:.16;
				pointer-events:none;
			}
			.icon-hero:before{
				width:420px;height:420px;
				right:-180px;top:-180px;
				background:#ffffff;
			}
			.icon-hero:after{
				width:320px;height:320px;
				left:-160px;bottom:-160px;
				background:#ffffff;
			}
			.icon-hero-top{
				position:relative;
				display:flex;
				gap:16px;
				align-items:center;
				justify-content:space-between;
			}
			.icon-hero-left{
				display:flex;
				gap:14px;
				align-items:center;
				min-width:0;
			}
			.icon-hero-logo{
				width:64px;height:64px;
				border-radius:18px;
				background:rgba(255,255,255,.16);
				border:1px solid rgba(255,255,255,.22);
				display:flex;
				align-items:center;
				justify-content:center;
				overflow:hidden;
				box-shadow:0 10px 22px rgba(0,0,0,.12);
				flex:0 0 auto;
			}
			.icon-hero-logo img{
				width:56px;height:56px;
				object-fit:cover;
				border-radius:14px;
			}
			.icon-hero-kicker{
				font-size:12px;
				letter-spacing:.14em;
				text-transform:uppercase;
				font-weight:900;
				opacity:.92;
			}
			.icon-hero-title{
				margin:2px 0 2px;
				font-size:40px;
				line-height:1.05;
				font-weight:950;
				letter-spacing:-.02em;
				white-space:nowrap;
				overflow:hidden;
				text-overflow:ellipsis;
			}
			@media(max-width: 980px){
				.icon-hero-title{font-size:30px;white-space:normal;}
			}
			.icon-hero-sub{
				margin:0;
				font-size:14px;
				font-weight:800;
				opacity:.95;
				white-space:nowrap;
				overflow:hidden;
				text-overflow:ellipsis;
			}
			@media(max-width: 980px){
				.icon-hero-sub{white-space:normal;}
			}
			.icon-hero-actions{
				display:flex;
				gap:10px;
				align-items:center;
				flex:0 0 auto;
			}
			.icon-hero-btn{
				display:inline-flex;
				align-items:center;
				justify-content:center;
				border-radius:999px;
				padding:12px 16px;
				font-weight:950;
				font-size:13px;
				text-decoration:none;
				cursor:pointer;
				border:1px solid rgba(255,255,255,.35);
				background:rgba(255,255,255,.14);
				color:#fff;
				backdrop-filter:blur(6px);
				white-space:nowrap;
			}
			.icon-hero-btn:hover{background:rgba(255,255,255,.20);}
			.icon-hero-btn.primary{
				background:#ffffff;
				color:#062c2a;
				border-color:#ffffff;
			}
			.icon-hero-btn.primary:hover{opacity:.95;}
			.icon-hero-tags{
				position:relative;
				margin-top:14px;
				display:flex;
				gap:10px;
				flex-wrap:wrap;
			}
			.icon-hero-tag{
				display:inline-flex;
				align-items:center;
				gap:8px;
				padding:10px 14px;
				border-radius:999px;
				border:1px solid rgba(255,255,255,.28);
				background:rgba(255,255,255,.12);
				font-weight:900;
				font-size:13px;
				white-space:nowrap;
			}
			.icon-hero-tag b{font-weight:950;}

			/* Pills / chips */
			.icon-pill{
				display:inline-flex;
				align-items:center;
				white-space:normal;
				max-width:100%;
				overflow-wrap:anywhere;
				word-break:break-word;
				line-height:1.25;
				text-align:left;
				padding:8px 12px;
				border-radius:999px;
				font-size:11px;
				font-weight:950;
				border:1px solid rgba(20,164,207,.18);
				background:linear-gradient(135deg, rgba(20,164,207,.10), rgba(21,160,109,.10)), #fff;
				color:var(--ink);
			}

			.icon-chip{
				display:inline-flex;align-items:center;gap:8px;
				padding:7px 10px;border-radius:999px;font-size:11px;font-weight:950;
				border:1px solid rgba(148,163,184,.45);
				background:#fff;color:var(--ink);
				white-space:nowrap;
			}
			.icon-chip .dot{width:8px;height:8px;border-radius:99px;background:rgba(148,163,184,.9);}
			.icon-chip[data-priority="high"]{border-color:rgba(220,38,38,.25);background:rgba(254,226,226,.45);color:#7f1d1d;}
			.icon-chip[data-priority="high"] .dot{background:rgba(220,38,38,.9);}
			.icon-chip[data-priority="medium"]{border-color:rgba(245,158,11,.25);background:rgba(255,237,213,.45);color:#7c2d12;}
			.icon-chip[data-priority="medium"] .dot{background:rgba(245,158,11,.95);}
			.icon-chip[data-priority="low"]{border-color:rgba(34,197,94,.22);background:rgba(220,252,231,.45);color:#14532d;}
			.icon-chip[data-priority="low"] .dot{background:rgba(34,197,94,.95);}

			.icon-chip[data-status="open"]{border-color:rgba(59,130,246,.25);background:rgba(219,234,254,.55);color:#1e3a8a;}
			.icon-chip[data-status="open"] .dot{background:rgba(59,130,246,.9);}
			.icon-chip[data-status="in_progress"]{border-color:rgba(245,158,11,.25);background:rgba(255,237,213,.55);color:#7c2d12;}
			.icon-chip[data-status="in_progress"] .dot{background:rgba(245,158,11,.95);}
			.icon-chip[data-status="done"]{border-color:rgba(34,197,94,.22);background:rgba(220,252,231,.60);color:#14532d;}
			.icon-chip[data-status="done"] .dot{background:rgba(34,197,94,.95);}

			.icon-chip-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;}

			/* Table -> Card rows */
			.icon-table{width:100%;font-size:12px;margin-top:12px;border-collapse:separate;border-spacing:0 10px;}
			.icon-table thead th{
				font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;
				position:sticky;top:0;z-index:2;
				background:rgba(249,250,251,.92);
				backdrop-filter: blur(6px);
				padding:10px 10px;
				border-bottom:0;
				text-align:left;
			}
			.icon-table tbody tr{
				background:#fff;
				box-shadow:0 10px 26px rgba(0,0,0,.06);
				border:1px solid rgba(20,164,207,.14);
			}
			.icon-table tbody td{
				padding:14px 12px;
				vertical-align:top;
				border-bottom:0 !important;
			}
			.icon-table tbody tr td:first-child{border-top-left-radius:18px;border-bottom-left-radius:18px;}
			.icon-table tbody tr td:last-child{border-top-right-radius:18px;border-bottom-right-radius:18px;}

			.icon-action-title{font-weight:950;color:#062c28;line-height:1.25;font-size:13px;}

			details.icon-details{
				border:1px solid rgba(20,164,207,.18);
				border-radius:16px;
				padding:10px 12px;
				background:linear-gradient(135deg, rgba(20,164,207,.06), rgba(21,160,109,.06)), #fff;
				margin-top:12px;
			}
			details.icon-details summary{cursor:pointer;font-weight:950;color:var(--ink);padding:8px 8px;border-radius:12px;}

			.icon-pill-stack{display:flex;flex-direction:column;gap:8px;max-width:100%;}

			.icon-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap;}
			.icon-actions .icon-select{height:38px;border-radius:999px;padding:8px 12px;width:auto;display:inline-block;}
			.icon-actions .icon-btn-ghost,.icon-actions .icon-btn-danger{padding:9px 12px;}

			@media(max-width: 980px){
				.icon-table thead{display:none;}
				.icon-table, .icon-table tbody, .icon-table tr, .icon-table td{display:block;width:100%;}
				.icon-table tbody tr{padding:12px;border-radius:18px;}
				.icon-table tbody td{padding:8px 0;}
				.icon-actions{justify-content:flex-start;}
			}
		</style>

		<div class="icon-portal-shell">
			<div class="icon-portal-wrap">
				<div class="icon-rail"></div>

				<div class="icon-hero">
					<div class="icon-hero-top">
						<div class="icon-hero-left">
							<div class="icon-hero-logo">
								<?php if ( ! empty( $brand_logo_url ) ) : ?>
									<img src="<?php echo esc_url( $brand_logo_url ); ?>" alt="">
								<?php else : ?>
									<div style="width:56px;height:56px;border-radius:14px;background:rgba(255,255,255,.22);"></div>
								<?php endif; ?>
							</div>

							<div style="min-width:0;">
								<div class="icon-hero-kicker">ICON CATALYST • ACTION PLAN<?php echo $is_360 ? ' • 360' : ''; ?><?php echo $traits_project ? ' • TRAITS' : ''; ?></div>
								<div class="icon-hero-title">Project action plan</div>
								<p class="icon-hero-sub">
									<?php echo esc_html( (string) $project->name ); ?>
									<span style="opacity:.75;">•</span>
									Project ID: <?php echo (int) $project_id; ?>
								</p>
							</div>
						</div>

						<div class="icon-hero-actions">
							<a class="icon-hero-btn" href="<?php echo esc_url( $summary_url ); ?>">Summary</a>
							<a class="icon-hero-btn primary" href="<?php echo esc_url( home_url('/catalyst-portal/') ); ?>">Back to portal</a>
						</div>
					</div>

					<div class="icon-hero-tags">
						<span class="icon-hero-tag">Items: <b><?php echo (int) $items_count; ?></b></span>
						<span class="icon-hero-tag">Open: <b><?php echo (int) $open_count; ?></b></span>
						<span class="icon-hero-tag">In progress: <b><?php echo (int) $prog_count; ?></b></span>
						<span class="icon-hero-tag">Done: <b><?php echo (int) $done_count; ?></b></span>
					</div>
				</div>

				<?php if ( $error ) : ?><div class="icon-msg err"><?php echo esc_html( $error ); ?></div><?php endif; ?>
				<?php if ( $notice ) : ?><div class="icon-msg ok"><?php echo esc_html( $notice ); ?></div><?php endif; ?>

				<div class="icon-card">
					<div class="icon-row icon-space" style="align-items:flex-start;">
						<div>
							<div style="font-weight:950;color:var(--ink);"><?php echo esc_html( $rec_title ); ?></div>
							<div class="icon-sub"><?php echo esc_html( $rec_sub ); ?></div>
						</div>

						<?php if ( $is_admin && ! empty($ap_debug) ) : ?>
							<div style="max-width:560px;">
								<div style="font-weight:950;color:var(--ink);margin-bottom:6px;">Debug (admin)</div>
								<pre style="margin:0;white-space:pre-wrap;font-size:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:14px;padding:10px;"><?php echo esc_html( print_r($ap_debug, true) ); ?></pre>
							</div>
						<?php endif; ?>

						<div class="icon-row" style="gap:10px;">
							<?php if ( ! $has_items ) : ?>
								<form method="post" style="margin:0;">
									<?php wp_nonce_field( 'icon_ap' ); ?>
									<input type="hidden" name="icon_ap_action" value="generate_plan">
									<button class="icon-btn" type="submit">Generate recommended plan</button>
								</form>
							<?php endif; ?>

							<?php if ( $has_items ) : ?>
								<form method="post" style="margin:0;">
									<?php wp_nonce_field( 'icon_ap' ); ?>
									<input type="hidden" name="icon_ap_action" value="clear_plan">
									<button class="icon-btn-danger" type="submit" onclick="return confirm('Clear ALL actions in this plan?');">Clear all actions</button>
								</form>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<div class="icon-card">
					<div style="font-weight:950;color:var(--ink);margin-bottom:8px;">Add a new action</div>

					<form method="post">
						<input type="hidden" name="icon_ap_action" value="add_item">
						<?php wp_nonce_field( 'icon_ap' ); ?>

						<div class="icon-grid-3">
							<div>
								<label class="icon-label">Pillar (optional)</label>
								<input class="icon-input" type="text" name="pillar" placeholder="<?php echo $is_360 ? 'e.g. Behaviour / Communication / Impact' : 'e.g. Trust / Clarity / Accountability'; ?>">
							</div>
							<div>
								<label class="icon-label">Theme (optional)</label>
								<input class="icon-input" type="text" name="theme" placeholder="e.g. Communication / Decision-making">
							</div>
							<div>
								<label class="icon-label">Owner</label>
								<input class="icon-input" type="text" name="owner" value="<?php echo esc_attr( $owner_default ); ?>" placeholder="Name or role">
							</div>
						</div>

						<div style="margin-top:12px;">
							<label class="icon-label">Action *</label>
							<textarea class="icon-textarea" name="action_text" required placeholder="Write the action in one clear sentence. Keep it measurable."></textarea>
						</div>

						<div class="icon-grid-3" style="margin-top:12px;">
							<div>
								<label class="icon-label">Due date</label>
								<input class="icon-input" type="date" name="due_date">
							</div>
							<div>
								<label class="icon-label">Priority</label>
								<select class="icon-select" name="priority">
									<option value="low">Low</option>
									<option value="medium" selected>Medium</option>
									<option value="high">High</option>
								</select>
							</div>
							<div style="display:flex;align-items:flex-end;">
								<button class="icon-btn" type="submit">Add action</button>
							</div>
						</div>
					</form>
				</div>

				<div class="icon-card">
					<div class="icon-row icon-space">
						<div style="font-weight:950;color:var(--ink);">Current actions</div>
						<div class="icon-sub" style="max-width:none;">Update status, or expand an item to edit details.</div>
					</div>

					<?php if ( empty( $items ) ) : ?>
						<p class="icon-sub" style="margin-top:10px;">No actions yet. Add your first action above.</p>
					<?php else : ?>
						<div style="overflow:auto;">
							<table class="icon-table">
								<thead>
									<tr>
										<th>Action</th>
										<th>Pillar / Theme</th>
										<th style="text-align:right;">Controls</th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ( (array) $items as $it ) :
									$status_label   = ucfirst( str_replace('_',' ', (string) $it->status ) );
									$priority_label = ucfirst( (string) $it->priority );
								?>
									<tr>
										<td style="min-width:520px;">
											<div class="icon-action-title"><?php echo nl2br( esc_html( (string) $it->action_text ) ); ?></div>

											<div class="icon-chip-row">
												<?php if ( $it->owner ) : ?>
													<span class="icon-chip"><span class="dot" style="background:rgba(20,164,207,.95);"></span>Owner: <?php echo esc_html( (string) $it->owner ); ?></span>
												<?php endif; ?>

												<?php if ( $it->due_date ) : ?>
													<span class="icon-chip"><span class="dot" style="background:rgba(107,114,128,.9);"></span>Due: <?php echo esc_html( (string) $it->due_date ); ?></span>
												<?php endif; ?>

												<span class="icon-chip" data-priority="<?php echo esc_attr( (string) $it->priority ); ?>">
													<span class="dot"></span>Priority: <?php echo esc_html( $priority_label ); ?>
												</span>

												<span class="icon-chip" data-status="<?php echo esc_attr( (string) $it->status ); ?>">
													<span class="dot"></span><?php echo esc_html( $status_label ); ?>
												</span>
											</div>

											<details class="icon-details">
												<summary>Edit this action</summary>
												<form method="post" style="margin-top:10px;">
													<?php wp_nonce_field( 'icon_ap' ); ?>
													<input type="hidden" name="icon_ap_action" value="update_item">
													<input type="hidden" name="item_id" value="<?php echo (int) $it->id; ?>">

													<div class="icon-grid-3">
														<div>
															<label class="icon-label">Pillar</label>
															<input class="icon-input" type="text" name="pillar" value="<?php echo esc_attr( (string) $it->pillar ); ?>">
														</div>
														<div>
															<label class="icon-label">Theme</label>
															<input class="icon-input" type="text" name="theme" value="<?php echo esc_attr( (string) $it->theme ); ?>">
														</div>
														<div>
															<label class="icon-label">Owner</label>
															<input class="icon-input" type="text" name="owner" value="<?php echo esc_attr( (string) $it->owner ); ?>">
														</div>
													</div>

													<div style="margin-top:12px;">
														<label class="icon-label">Action *</label>
														<textarea class="icon-textarea" name="action_text" required><?php echo esc_textarea( (string) $it->action_text ); ?></textarea>
													</div>

													<div class="icon-grid-3" style="margin-top:12px;">
														<div>
															<label class="icon-label">Due date</label>
															<input class="icon-input" type="date" name="due_date" value="<?php echo $it->due_date ? esc_attr( (string) $it->due_date ) : ''; ?>">
														</div>
														<div>
															<label class="icon-label">Priority</label>
															<select class="icon-select" name="priority">
																<option value="low" <?php selected( $it->priority, 'low' ); ?>>Low</option>
																<option value="medium" <?php selected( $it->priority, 'medium' ); ?>>Medium</option>
																<option value="high" <?php selected( $it->priority, 'high' ); ?>>High</option>
															</select>
														</div>
														<div>
															<label class="icon-label">Notes</label>
															<input class="icon-input" type="text" name="notes" value="<?php echo esc_attr( (string) $it->notes ); ?>" placeholder="Optional notes">
														</div>
													</div>

													<div class="icon-row" style="margin-top:12px;">
														<button class="icon-btn-ghost" type="submit">Save changes</button>
													</div>
												</form>
											</details>
										</td>

										<td style="min-width:180px;">
											<?php if ( $it->pillar || $it->theme ) : ?>
												<div class="icon-pill-stack">
													<?php if ( $it->pillar ) : ?><span class="icon-pill"><?php echo esc_html( (string) $it->pillar ); ?></span><?php endif; ?>
													<?php if ( $it->theme )  : ?><span class="icon-pill"><?php echo esc_html( (string) $it->theme ); ?></span><?php endif; ?>
												</div>
											<?php else : ?>
												<span class="icon-sub">—</span>
											<?php endif; ?>
										</td>

										<td style="white-space:nowrap;min-width:240px;text-align:right;">
											<div class="icon-actions">
												<form method="post" style="display:inline-flex;gap:8px;align-items:center;margin:0;">
													<?php wp_nonce_field( 'icon_ap' ); ?>
													<input type="hidden" name="icon_ap_action" value="set_status">
													<input type="hidden" name="item_id" value="<?php echo (int) $it->id; ?>">
													<select class="icon-select" name="status">
														<option value="open" <?php selected( $it->status, 'open' ); ?>>Open</option>
														<option value="in_progress" <?php selected( $it->status, 'in_progress' ); ?>>In progress</option>
														<option value="done" <?php selected( $it->status, 'done' ); ?>>Done</option>
													</select>
													<button class="icon-btn-ghost" type="submit">Save</button>
												</form>

												<form method="post" style="display:inline-block;margin:0;">
													<?php wp_nonce_field( 'icon_ap' ); ?>
													<input type="hidden" name="icon_ap_action" value="delete_item">
													<input type="hidden" name="item_id" value="<?php echo (int) $it->id; ?>">
													<button class="icon-btn-danger" type="submit" onclick="return confirm('Delete this action?');">Delete</button>
												</form>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>

			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

add_action( 'init', function(){
	if ( shortcode_exists( 'icon_psy_action_plan' ) ) return;
	add_shortcode( 'icon_psy_action_plan', 'icon_psy_action_plan_shortcode' );
}, 20 );
