<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ------------------------------------------------------------
 * BRANDING ENGINE LOADER (ensures client branding works in reports)
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_get_client_branding' ) ) {

	$__icon_branding_paths = array(
		plugin_dir_path( __FILE__ ) . 'helpers-branding.php',
		trailingslashit( dirname( __FILE__, 2 ) ) . 'shortcodes/helpers-branding.php',
		trailingslashit( dirname( __FILE__, 3 ) ) . 'includes/shortcodes/helpers-branding.php',
		trailingslashit( dirname( __FILE__, 2 ) ) . 'includes/helpers-branding.php',
	);

	foreach ( $__icon_branding_paths as $__p ) {
		if ( file_exists( $__p ) ) {
			require_once $__p;
			break;
		}
	}

	unset( $__icon_branding_paths, $__p );
}

/* ------------------------------------------------------------
 * SHARED SAFE HELPERS (define once, reuse everywhere)
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_table_exists' ) ) {
	function icon_psy_table_exists( $table ) {
		global $wpdb;
		$table = (string) $table;
		if ( $table === '' ) return false;
		$like = $wpdb->esc_like( $table );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $like ) );
	}
}

if ( ! function_exists( 'icon_psy_get_table_columns_lower' ) ) {
	function icon_psy_get_table_columns_lower( $table ) {
		global $wpdb;
		$table = (string) $table;
		if ( $table === '' ) return array();
		$cols = $wpdb->get_col( "DESCRIBE {$table}", 0 );
		return is_array( $cols ) ? array_map( 'strtolower', $cols ) : array();
	}
}

if ( ! function_exists( 'icon_psy_pick_col' ) ) {
	function icon_psy_pick_col( $cols_lower, $candidates ) {
		$cols_lower = (array) $cols_lower;
		foreach ( (array) $candidates as $c ) {
			$c = (string) $c;
			if ( $c === '' ) continue;
			if ( in_array( strtolower( $c ), $cols_lower, true ) ) return $c;
		}
		return '';
	}
}

if ( ! function_exists( 'icon_psy_safe_trim' ) ) {
	function icon_psy_safe_trim( $text, $max = 220 ) {
		$text = is_string( $text ) ? trim( $text ) : '';
		if ( $text === '' ) return '';
		$text = preg_replace( '/\s+/', ' ', $text );
		$max  = max( 10, (int) $max );

		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $text ) <= $max ) return $text;
			return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '...';
		}

		if ( strlen( $text ) <= $max ) return $text;
		return rtrim( substr( $text, 0, $max - 1 ) ) . '...';
	}
}

if ( ! function_exists( 'icon_psy_hash_index' ) ) {
	function icon_psy_hash_index( $key, $count ) {
		$count = max( 1, (int) $count );
		$h = function_exists( 'crc32' ) ? crc32( (string) $key ) : strlen( (string) $key );
		return (int) ( abs( (int) $h ) % $count );
	}
}

if ( ! function_exists( 'icon_psy_pick_variant' ) ) {
	function icon_psy_pick_variant( $key, $variants ) {
		$variants = (array) $variants;
		if ( empty( $variants ) ) return '';
		$idx = icon_psy_hash_index( $key, count( $variants ) );
		return (string) $variants[ $idx ];
	}
}

/* ------------------------------------------------------------
 * Completion detection fallback (prevents fatal if helper missing)
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_team_row_is_completed_detected' ) ) {
	function icon_psy_team_row_is_completed_detected( $row ) {

		$status = '';
		if ( is_object( $row ) && isset( $row->status ) ) {
			$status = (string) $row->status;
		} elseif ( is_array( $row ) && isset( $row['status'] ) ) {
			$status = (string) $row['status'];
		}

		if ( function_exists( 'icon_psy_is_completed_status' ) ) {
			return icon_psy_is_completed_status( $status );
		}

		$status = strtolower( trim( (string) $status ) );
		if ( in_array( $status, array( 'completed', 'complete', 'submitted', 'done', 'finished' ), true ) ) return true;

		// broader fallback checks
		if ( is_object( $row ) ) {
			if ( isset( $row->is_completed ) ) {
				$v = $row->is_completed;
				if ( $v === 1 || $v === '1' || $v === true || $v === 'yes' || $v === 'true' ) return true;
			}
			if ( isset( $row->completed_at ) && ! empty( $row->completed_at ) ) return true;
			if ( isset( $row->q1_rating ) && $row->q1_rating !== null && $row->q1_rating !== '' ) return true;
			if ( isset( $row->detail_json ) && ! empty( $row->detail_json ) ) return true;
		}

		return false;
	}
}

/* ------------------------------------------------------------
 * Results schema detection (TEAM)
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_detect_results_schema_team' ) ) {
	function icon_psy_detect_results_schema_team() {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$candidates = array(
			$prefix . 'icon_assessment_results',
			$prefix . 'icon_psy_assessment_results',
			$prefix . 'icon_psy_results',
			$prefix . 'icon_psy_assessment_result',
			$prefix . 'icon_assessment_result',
		);

		$table = '';
		$cols  = array();

		foreach ( $candidates as $t ) {
			if ( icon_psy_table_exists( $t ) ) {
				$table = $t;
				$cols  = icon_psy_get_table_columns_lower( $t );
				if ( ! empty( $cols ) ) break;
			}
		}

		if ( ! $table ) {
			return array( 'table' => '', 'cols' => array(), 'map' => array(), 'order' => '' );
		}

		$map = array();
		$map['project_id']      = icon_psy_pick_col( $cols, array( 'project_id','projectID','projectId','project','icon_project_id' ) );
		$map['participant_id']  = icon_psy_pick_col( $cols, array( 'participant_id','participantID','participantId','participant','user_id','assessment_user_id','candidate_id' ) );
		$map['rater_id']        = icon_psy_pick_col( $cols, array( 'rater_id','raterID','raterId','reviewer_id' ) );
		$map['framework_id']    = icon_psy_pick_col( $cols, array( 'framework_id','frameworkID','framework' ) );

		$map['status']          = icon_psy_pick_col( $cols, array( 'status','completion_status','survey_status','state' ) );
		$map['is_completed']    = icon_psy_pick_col( $cols, array( 'is_completed','is_complete','completed','complete','submitted','is_submitted' ) );
		$map['completed_at']    = icon_psy_pick_col( $cols, array( 'completed_at','submitted_at','submitted_on','completed_on','finished_at','completed_date' ) );

		$map['assessment_type'] = icon_psy_pick_col( $cols, array( 'assessment_type','type','survey_type','module','assessment_module' ) );
		$map['context']         = icon_psy_pick_col( $cols, array( 'context','assessment_context','category','mode' ) );

		$map['q1_rating']       = icon_psy_pick_col( $cols, array( 'q1_rating','overall_rating','overall','rating','score' ) );
		$map['q2_text']         = icon_psy_pick_col( $cols, array( 'q2_text','strengths_text','strength_text','what_working','q2','strengths' ) );
		$map['q3_text']         = icon_psy_pick_col( $cols, array( 'q3_text','development_text','improve_text','what_change','q3','development' ) );
		$map['detail_json']     = icon_psy_pick_col( $cols, array( 'detail_json','responses_json','answers_json','response_json','details_json','payload_json' ) );

		$order = icon_psy_pick_col( $cols, array( 'created_at','submitted_at','completed_at','id' ) );

		return array(
			'table' => $table,
			'cols'  => $cols,
			'map'   => $map,
			'order' => $order,
		);
	}
}

/* ------------------------------------------------------------
 * TEAM dataset builder (used by heatmap + action plan)
 * ------------------------------------------------------------ */
if ( ! function_exists( 'icon_psy_team_report_build_dataset' ) ) {

	function icon_psy_team_report_build_dataset( $project_id ) {
		global $wpdb;

		$project_id = (int) $project_id;
		if ( $project_id <= 0 ) return array( 'heatmap_rows' => array() );

		$projects_table     = $wpdb->prefix . 'icon_psy_projects';
		$competencies_table = $wpdb->prefix . 'icon_psy_framework_competencies';
		$raters_table       = $wpdb->prefix . 'icon_psy_raters';

		$project = null;
		if ( icon_psy_table_exists( $projects_table ) ) {
			$project = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$projects_table} WHERE id = %d LIMIT 1", $project_id ) );
		}

		if ( ! function_exists( 'icon_psy_detect_results_schema_team' ) ) {
			return array( 'heatmap_rows' => array() );
		}

		$schema        = icon_psy_detect_results_schema_team();
		$results_table = $schema['table'];
		$map           = $schema['map'];
		$order_col     = $schema['order'];

		if ( ! $results_table || empty( $map['project_id'] ) ) {
			return array( 'heatmap_rows' => array() );
		}

		$rater_relationship_col = '';
		if ( icon_psy_table_exists( $raters_table ) ) {
			$rcols = icon_psy_get_table_columns_lower( $raters_table );
			$rater_relationship_col = icon_psy_pick_col( $rcols, array( 'relationship', 'relationship_type', 'rater_relationship', 'relation', 'rel' ) );
		}
		$join_raters = ( ! empty( $map['rater_id'] ) && icon_psy_table_exists( $raters_table ) && $rater_relationship_col );

		$order_sql = "ORDER BY r.id ASC";
		if ( ! empty( $order_col ) ) {
			$order_sql = "ORDER BY r.`" . esc_sql( $order_col ) . "` ASC";
		}

		$team_where_bits = array();
		if ( ! empty( $map['assessment_type'] ) ) {
			$team_where_bits[] = "LOWER(COALESCE(r.`" . esc_sql( $map['assessment_type'] ) . "`,'')) IN ('team','teams','team_assessment','team-survey','team_survey')";
		}
		if ( ! empty( $map['context'] ) ) {
			$team_where_bits[] = "LOWER(COALESCE(r.`" . esc_sql( $map['context'] ) . "`,'')) IN ('team','teams','team_assessment','team-survey','team_survey')";
		}
		$team_where_sql = $team_where_bits ? " AND ( " . implode( " OR ", $team_where_bits ) . " )" : '';

		$sql = "
			SELECT r.*" . ( $join_raters ? ", rt.`" . esc_sql( $rater_relationship_col ) . "` AS rater_relationship" : "" ) . "
			FROM {$results_table} r
			" . ( $join_raters ? "LEFT JOIN {$raters_table} rt ON r.`" . esc_sql( $map['rater_id'] ) . "` = rt.id" : "" ) . "
			WHERE r.`" . esc_sql( $map['project_id'] ) . "` = %d
			{$team_where_sql}
			{$order_sql}
		";

		$raw_results = $wpdb->get_results( $wpdb->prepare( $sql, $project_id ) );

		if ( empty( $raw_results ) && $team_where_sql !== '' ) {
			$sql2 = "
				SELECT r.*" . ( $join_raters ? ", rt.`" . esc_sql( $rater_relationship_col ) . "` AS rater_relationship" : "" ) . "
				FROM {$results_table} r
				" . ( $join_raters ? "LEFT JOIN {$raters_table} rt ON r.`" . esc_sql( $map['rater_id'] ) . "` = rt.id" : "" ) . "
				WHERE r.`" . esc_sql( $map['project_id'] ) . "` = %d
				{$order_sql}
			";
			$raw_results = $wpdb->get_results( $wpdb->prepare( $sql2, $project_id ) );
		}

		$normalized = array();
		foreach ( (array) $raw_results as $r ) {
			$obj = new stdClass();
			foreach ( (array) $r as $k => $v ) { $obj->$k = $v; }

			foreach ( array( 'participant_id','rater_id','framework_id','q1_rating','q2_text','q3_text','detail_json','status','is_completed','completed_at' ) as $nk ) {
				if ( ! empty( $map[ $nk ] ) && isset( $r->{ $map[ $nk ] } ) ) $obj->$nk = $r->{ $map[ $nk ] };
			}

			if ( $join_raters && isset( $r->rater_relationship ) ) $obj->rater_relationship = $r->rater_relationship;
			$normalized[] = $obj;
		}

		$results = array();
		foreach ( (array) $normalized as $row ) {
			if ( icon_psy_team_row_is_completed_detected( $row ) ) $results[] = $row;
		}
		if ( empty( $results ) && ! empty( $normalized ) ) $results = $normalized;

		// Aggregate heat data
		$heat_agg = array();

		foreach ( (array) $results as $row ) {
			if ( empty( $row->detail_json ) ) continue;
			$detail = json_decode( $row->detail_json, true );
			if ( ! is_array( $detail ) ) continue;

			foreach ( $detail as $entry ) {
				if ( empty( $entry['competency_id'] ) ) continue;
				$cid = (int) $entry['competency_id'];

				$q1 = array_key_exists( 'q1', $entry ) ? ( $entry['q1'] === null ? null : (float) $entry['q1'] ) : null;
				$q2 = array_key_exists( 'q2', $entry ) ? ( $entry['q2'] === null ? null : (float) $entry['q2'] ) : null;
				$q3 = array_key_exists( 'q3', $entry ) ? ( $entry['q3'] === null ? null : (float) $entry['q3'] ) : null;

				if ( ! isset( $heat_agg[ $cid ] ) ) {
					$heat_agg[ $cid ] = array(
						'sum_q1' => 0, 'cnt_q1' => 0,
						'sum_q2' => 0, 'cnt_q2' => 0,
						'sum_q3' => 0, 'cnt_q3' => 0,
						'overall_vals' => array(),
					);
				}

				$has_any = false;
				if ( $q1 !== null ) { $heat_agg[ $cid ]['sum_q1'] += $q1; $heat_agg[ $cid ]['cnt_q1']++; $has_any = true; }
				if ( $q2 !== null ) { $heat_agg[ $cid ]['sum_q2'] += $q2; $heat_agg[ $cid ]['cnt_q2']++; $has_any = true; }
				if ( $q3 !== null ) { $heat_agg[ $cid ]['sum_q3'] += $q3; $heat_agg[ $cid ]['cnt_q3']++; $has_any = true; }

				if ( $has_any ) {
					$vals = array();
					if ( $q1 !== null ) $vals[] = $q1;
					if ( $q2 !== null ) $vals[] = $q2;
					if ( $q3 !== null ) $vals[] = $q3;
					$overall = $vals ? ( array_sum( $vals ) / count( $vals ) ) : null;
					if ( $overall !== null ) $heat_agg[ $cid ]['overall_vals'][] = (float) $overall;
				}
			}
		}

		$heatmap_rows = array();

		if ( $heat_agg && icon_psy_table_exists( $competencies_table ) ) {
			$competency_ids = array_values( array_filter( array_map( 'intval', array_keys( $heat_agg ) ) ) );
			if ( $competency_ids ) {

				$cols_lower = icon_psy_get_table_columns_lower( $competencies_table );
				$has_desc   = in_array( 'description', $cols_lower, true );
				$has_lens   = in_array( 'lens_code',   $cols_lower, true );

				$select_bits = array( 'id', 'name' );
				if ( $has_desc ) $select_bits[] = 'description';
				if ( $has_lens ) $select_bits[] = 'lens_code';

				$placeholders = implode( ',', array_fill( 0, count( $competency_ids ), '%d' ) );
				$sqlc = "SELECT " . implode( ', ', $select_bits ) . " FROM {$competencies_table} WHERE id IN ($placeholders) ORDER BY id ASC";

				$args     = array_merge( array( $sqlc ), $competency_ids );
				$prepared = call_user_func_array( array( $wpdb, 'prepare' ), $args );
				$competencies = $wpdb->get_results( $prepared );

				foreach ( (array) $competencies as $comp ) {
					$cid = (int) $comp->id;
					if ( empty( $heat_agg[ $cid ] ) ) continue;

					$agg2 = $heat_agg[ $cid ];

					$avg_q1 = ( $agg2['cnt_q1'] > 0 ) ? ( $agg2['sum_q1'] / (int) $agg2['cnt_q1'] ) : null;
					$avg_q2 = ( $agg2['cnt_q2'] > 0 ) ? ( $agg2['sum_q2'] / (int) $agg2['cnt_q2'] ) : null;
					$avg_q3 = ( $agg2['cnt_q3'] > 0 ) ? ( $agg2['sum_q3'] / (int) $agg2['cnt_q3'] ) : null;

					$parts = array();
					if ( $avg_q1 !== null ) $parts[] = (float) $avg_q1;
					if ( $avg_q2 !== null ) $parts[] = (float) $avg_q2;
					if ( $avg_q3 !== null ) $parts[] = (float) $avg_q3;
					$overall = $parts ? ( array_sum( $parts ) / count( $parts ) ) : 0.0;

					$vals = ( isset( $agg2['overall_vals'] ) && is_array( $agg2['overall_vals'] ) ) ? $agg2['overall_vals'] : array();
					$sd = null;
					if ( count( $vals ) >= 2 ) {
						$mean = array_sum( $vals ) / count( $vals );
						$var_sum = 0;
						foreach ( $vals as $v ) { $var_sum += pow( (float) $v - (float) $mean, 2 ); }
						$sd = sqrt( $var_sum / count( $vals ) );
					} elseif ( count( $vals ) === 1 ) {
						$sd = 0.0;
					}

					$n = (int) count( $vals );

					$heatmap_rows[] = array(
						'id'          => $cid,
						'name'        => (string) $comp->name,
						'description' => ( $has_desc && isset( $comp->description ) ) ? (string) $comp->description : '',
						'lens_code'   => ( $has_lens && isset( $comp->lens_code ) && $comp->lens_code !== '' ) ? (string) $comp->lens_code : 'CLARITY',
						'avg_q1'      => ( $avg_q1 === null ? 0.0 : (float) $avg_q1 ),
						'avg_q2'      => ( $avg_q2 === null ? 0.0 : (float) $avg_q2 ),
						'avg_q3'      => ( $avg_q3 === null ? 0.0 : (float) $avg_q3 ),
						'overall'     => (float) $overall,
						'n'           => $n,
						'sd'          => $sd,
					);
				}
			}
		}

		if ( $heatmap_rows ) {
			usort( $heatmap_rows, function( $a, $b ){
				if ( $a['overall'] === $b['overall'] ) return 0;
				return ( $a['overall'] > $b['overall'] ) ? -1 : 1;
			} );
		}

		return array(
			'project_id'   => $project_id,
			'heatmap_rows' => $heatmap_rows,
			'project'      => $project,
		);
	}
}

if ( ! function_exists( 'icon_psy_team_get_heatmap_rows' ) ) {
	function icon_psy_team_get_heatmap_rows( $project_id ) {
		$data = function_exists( 'icon_psy_team_report_build_dataset' ) ? icon_psy_team_report_build_dataset( (int) $project_id ) : array();
		return ( ! empty( $data['heatmap_rows'] ) && is_array( $data['heatmap_rows'] ) ) ? $data['heatmap_rows'] : array();
	}
}

/**
 * TEAM Report (client / admin view)
 *
 * Shortcode: [icon_psy_team_report]
 * Usage: /team-report/?project_id=XX
 *
 * Screen-only report (no export logic)
 * Competency wheel + heatmap + competency summaries
 * Optional display of individual raters on wheel + min/max markers
 */

if ( ! function_exists( 'icon_psy_team_report' ) ) {

	function icon_psy_team_report( $atts ) {
		global $wpdb;

		if ( ! defined( 'M_PI' ) ) define( 'M_PI', 3.14159265358979323846 );

		// -----------------------------------------
		// Inputs / share link support
		// -----------------------------------------
		$share_token    = isset( $_GET['share'] ) ? sanitize_text_field( wp_unslash( $_GET['share'] ) ) : '';
		$participant_id = isset( $_GET['participant_id'] ) ? (int) $_GET['participant_id'] : 0;

		$report_type  = 'team';
		$allow_public = false;

		if ( $share_token && $participant_id > 0 && function_exists( 'icon_psy_validate_report_share_token' ) ) {
			$allow_public = (bool) icon_psy_validate_report_share_token( $share_token, $participant_id, $report_type );
		}
		$public_view = $allow_public ? true : false;

		// -----------------------------------------
		// Safe permissions helpers
		// -----------------------------------------
		if ( ! function_exists( 'icon_psy_user_has_role' ) ) {
			function icon_psy_user_has_role( $user, $role ) {
				return ( $user && isset( $user->roles ) && is_array( $user->roles ) ) ? in_array( $role, $user->roles, true ) : false;
			}
		}

		if ( ! function_exists( 'icon_psy_get_effective_client_user_id' ) ) {
			function icon_psy_get_effective_client_user_id() {
				if ( ! is_user_logged_in() ) return 0;

				$uid = (int) get_current_user_id();
				$u   = get_user_by( 'id', $uid );
				if ( ! $u ) return 0;

				if ( icon_psy_user_has_role( $u, 'icon_client' ) ) return $uid;

				if ( current_user_can( 'manage_options' ) ) {
					$imp = get_user_meta( $uid, 'icon_psy_impersonate_client', true );
					if ( is_array( $imp ) ) {
						$cid = isset( $imp['client_id'] ) ? (int) $imp['client_id'] : 0;
						$exp = isset( $imp['expires'] ) ? (int) $imp['expires'] : 0;

						if ( $cid > 0 && $exp > time() ) return $cid;
						if ( $cid > 0 ) delete_user_meta( $uid, 'icon_psy_impersonate_client' );
					}

					$legacy = (int) get_user_meta( $uid, 'icon_psy_impersonate_client_id', true );
					if ( $legacy > 0 ) return $legacy;
				}

				return 0;
			}
		}

		// -------------------------------
		// Lens + consistency helpers
		// -------------------------------
		if ( ! function_exists( 'icon_psy_lens_gap_info' ) ) {
			function icon_psy_lens_gap_info( $q1, $q2, $q3 ) {
				$vals = array(
					'Everyday'   => (float) $q1,
					'Pressure'   => (float) $q2,
					'Role-model' => (float) $q3,
				);

				$max_k = 'Everyday'; $min_k = 'Everyday';
				$max_v = -INF; $min_v = INF;

				foreach ( $vals as $k => $v ) {
					if ( $v > $max_v ) { $max_v = $v; $max_k = $k; }
					if ( $v < $min_v ) { $min_v = $v; $min_k = $k; }
				}

				return array(
					'gap'   => max( 0.0, (float) ( $max_v - $min_v ) ),
					'max_k' => $max_k,
					'min_k' => $min_k,
					'max_v' => $max_v,
					'min_v' => $min_v,
				);
			}
		}

		if ( ! function_exists( 'icon_psy_sd_band' ) ) {
			function icon_psy_sd_band( $sd, $n ) {
				$n  = (int) $n;
				$sd = ( $sd === null ) ? null : (float) $sd;

				if ( $sd === null ) return array( 'label' => ( $n > 0 ? 'Consistency: early signal' : 'Consistency: no data' ), 'class' => 'icon-psy-pill-muted' );
				if ( $n < 3 ) return array( 'label' => 'Consistency: early signal', 'class' => 'icon-psy-pill-muted' );

				if ( $n < 5 ) {
					if ( $sd < 0.75 ) return array( 'label' => 'Consistency: emerging (aligned)', 'class' => 'icon-psy-pill-good' );
					if ( $sd < 1.10 ) return array( 'label' => 'Consistency: emerging (mixed)', 'class' => 'icon-psy-pill-warn' );
					return array( 'label' => 'Consistency: emerging (split views)', 'class' => 'icon-psy-pill-attn' );
				}

				if ( $sd < 0.60 ) return array( 'label' => 'Consistency: High', 'class' => 'icon-psy-pill-good' );
				if ( $sd < 1.00 ) return array( 'label' => 'Consistency: Moderate', 'class' => 'icon-psy-pill-warn' );
				return array( 'label' => 'Consistency: Mixed views', 'class' => 'icon-psy-pill-attn' );
			}
		}

		if ( ! function_exists( 'icon_psy_team_next_week_action' ) ) {
			function icon_psy_team_next_week_action( $ctx ) {
				$overall = isset( $ctx['overall'] ) ? (float) $ctx['overall'] : 0.0;
				$q1      = isset( $ctx['avg_q1'] ) ? (float) $ctx['avg_q1'] : 0.0;
				$q2      = isset( $ctx['avg_q2'] ) ? (float) $ctx['avg_q2'] : 0.0;
				$q3      = isset( $ctx['avg_q3'] ) ? (float) $ctx['avg_q3'] : 0.0;

				$level = ( $overall >= 5.5 ) ? 'strength' : ( ( $overall >= 4.5 ) ? 'solid' : 'dev' );

				$min    = min( $q1, $q2, $q3 );
				$target = ( $min === $q2 ) ? 'Pressure' : ( ( $min === $q3 ) ? 'Role-model' : 'Everyday' );

				$actions = array(
					'Everyday' => array(
						'Next week: choose one routine moment (stand-up, handover, weekly check-in) and use one shared standard move every time: owner, next step, deadline.',
						'Next week: add a tiny checklist (3 prompts max) into a regular meeting and repeat it in every session so the behaviour becomes automatic.',
						'Next week: name the expected behaviour at the start of the meeting, then close with a 60-second check: did we do it?',
					),
					'Pressure' => array(
						'Next week: when work speeds up, run a 3-minute reset once per day: priorities, roles, risks. Keep it short and repeatable.',
						'Next week: introduce one rule under pressure: one owner, one next action, one deadline. Use it in every escalation.',
						'Next week: before high-urgency decisions, pause for 90 seconds: options, decision owner, next step. Then move.',
					),
					'Role-model' => array(
						'Next week: leaders narrate the why behind key decisions once per day and reinforce the standard when it shows up.',
						'Next week: pick one non-negotiable behaviour to role-model and recognise it in others in real time.',
						'Next week: add a short what good looks like example at the start of key meetings, then follow up with one coaching moment.',
					),
				);

				if ( $level === 'strength' ) {
					$lock = array(
						'Next week: protect the strength by teaching one teammate the how behind it and applying it to one higher-stakes piece of work.',
						'Next week: capture the team standard in one sentence, then practise it in one real deliverable.',
						'Next week: scale the strength by using it deliberately in a tougher conversation or decision, then debrief what worked.',
					);
					return icon_psy_pick_variant( 'nextweek|strength|' . $target, $lock );
				}

				return icon_psy_pick_variant( 'nextweek|' . $level . '|' . $target, $actions[ $target ] );
			}
		}

		// -----------------------------------------
		// Narrative formatting helpers
		// -----------------------------------------
		if ( ! function_exists( 'icon_psy_narr_clean_text' ) ) {
			function icon_psy_narr_clean_text( $text ) {
				$text = is_string( $text ) ? trim( $text ) : '';
				if ( $text === '' ) return '';
				$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
				$text = str_replace( '**', '', $text );
				$text = str_replace( array( "—", "–" ), '-', $text );
				$parts = preg_split( "/\n\s*(ACTION:|ACTIONS:|QUESTION:)\s*/i", $text );
				return trim( isset( $parts[0] ) ? (string) $parts[0] : '' );
			}
		}

		if ( ! function_exists( 'icon_psy_narr_to_two_paragraphs' ) ) {
			function icon_psy_narr_to_two_paragraphs( $text ) {
				$text = icon_psy_narr_clean_text( $text );
				if ( $text === '' ) return array( '', '' );

				$chunks = preg_split( "/\n\s*\n+/", $text );
				$paras  = array();

				foreach ( (array) $chunks as $c ) {
					$c = trim( (string) $c );
					if ( $c === '' ) continue;

					$lines = array_map( 'trim', explode( "\n", $c ) );
					$lines = array_values( array_filter( $lines, function( $l ){
						if ( $l === '' ) return false;
						if ( preg_match( '/^\s*[\-\•]\s+/', $l ) ) return false;
						if ( preg_match( '/^\s*(action|actions|question)\s*:/i', $l ) ) return false;
						return true;
					} ) );

					$c2 = trim( implode( ' ', $lines ) );
					if ( $c2 !== '' ) $paras[] = $c2;
				}

				$p1 = isset( $paras[0] ) ? (string) $paras[0] : '';
				$p2 = isset( $paras[1] ) ? (string) $paras[1] : '';

				if ( $p1 !== '' && $p2 === '' ) {
					$sentences = preg_split( '/(?<=[\.\!\?])\s+/', $p1 );
					if ( is_array( $sentences ) && count( $sentences ) >= 4 ) {
						$mid = (int) ceil( count( $sentences ) / 2 );
						$p1  = trim( implode( ' ', array_slice( $sentences, 0, $mid ) ) );
						$p2  = trim( implode( ' ', array_slice( $sentences, $mid ) ) );
					} else {
						$p2 = 'Looking ahead, the fastest improvement comes from agreeing one small team habit and repeating it consistently in real work.';
					}
				}

				if ( $p1 !== '' && $p2 !== '' ) {
					$a = strtolower( preg_replace( '/[^a-z0-9\s]/i', '', $p1 ) );
					$b = strtolower( preg_replace( '/[^a-z0-9\s]/i', '', $p2 ) );

					$pct = 0.0;
					if ( function_exists( 'similar_text' ) ) similar_text( $a, $b, $pct );

					if ( $pct >= 70 ) {
						$p2 = 'Looking ahead, focus on where this competency matters most under pressure. One agreed team habit, repeated consistently, will lift both performance and confidence.';
					}
				}

				return array( $p1, $p2 );
			}
		}

		if ( ! function_exists( 'icon_psy_format_engine_text_to_html' ) ) {
			function icon_psy_format_engine_text_to_html( $text ) {
				list( $p1, $p2 ) = icon_psy_narr_to_two_paragraphs( $text );
				if ( $p1 === '' && $p2 === '' ) return '';

				$html = '';
				if ( $p1 !== '' ) $html .= '<p style="margin:0 0 8px;font-size:12px;color:#4b5563;line-height:1.55;">' . esc_html( $p1 ) . '</p>';
				if ( $p2 !== '' ) $html .= '<p style="margin:0 0 8px;font-size:12px;color:#4b5563;line-height:1.55;">' . esc_html( $p2 ) . '</p>';
				return $html;
			}
		}

		if ( ! function_exists( 'icon_psy_team_competency_narrative_fallback' ) ) {
			function icon_psy_team_competency_narrative_fallback( $ctx ) {
				$comp     = isset( $ctx['competency'] ) ? (string) $ctx['competency'] : 'this competency';
				$overall  = isset( $ctx['overall'] ) ? (float) $ctx['overall'] : 0.0;
				$avg_q1   = isset( $ctx['avg_q1'] ) ? (float) $ctx['avg_q1'] : 0.0;
				$avg_q2   = isset( $ctx['avg_q2'] ) ? (float) $ctx['avg_q2'] : 0.0;
				$avg_q3   = isset( $ctx['avg_q3'] ) ? (float) $ctx['avg_q3'] : 0.0;
				$sd       = isset( $ctx['sd'] ) ? $ctx['sd'] : null;
				$n        = isset( $ctx['n'] ) ? (int) $ctx['n'] : 0;

				$level = ( $overall >= 5.5 ) ? 'strength' : ( ( $overall >= 4.5 ) ? 'solid' : 'developing' );

				$lens = 'consistent';
				if ( $avg_q2 + 0.4 < $avg_q1 && $avg_q2 + 0.4 < $avg_q3 ) $lens = 'pressure-dip';
				elseif ( $avg_q3 + 0.4 < $avg_q1 && $avg_q3 + 0.4 < $avg_q2 ) $lens = 'rolemodel-dip';
				elseif ( $avg_q1 + 0.4 < $avg_q2 && $avg_q1 + 0.4 < $avg_q3 ) $lens = 'everyday-dip';

				$spread_note = '';
				if ( $sd !== null ) {
					$sdv = (float) $sd;
					if ( $n >= 5 ) {
						if ( $sdv >= 1.15 ) $spread_note = 'Views vary widely, suggesting different experiences across roles or situations.';
						elseif ( $sdv >= 0.75 ) $spread_note = 'There is some variation, suggesting pockets of strong practice and areas where shared expectations would help.';
						else $spread_note = 'Scores are relatively consistent, supporting trust and predictability across the team.';
					} else {
						if ( $sdv >= 1.15 ) $spread_note = 'Early signal: views vary, so validate with examples in a short team conversation.';
						elseif ( $sdv >= 0.75 ) $spread_note = 'Early signal: some variation, so agree what good looks like in practical terms.';
						else $spread_note = 'Early signal: views look aligned, so lock the habit in with repetition.';
					}
				}

				$p1_strength = array(
					"In {$comp}, the team is showing strong capability. This is likely helping work move forward with clarity and confidence.",
					"Results for {$comp} land as a clear strength. This capability appears to be supporting pace, quality, and smoother collaboration.",
					"{$comp} is coming through strongly. The team is likely benefiting from predictable, repeatable behaviours in this area."
				);

				$p1_solid = array(
					"In {$comp}, results suggest a solid base. The behaviour is present and helpful, with headroom to make it more deliberate and repeatable.",
					"{$comp} is generally effective. The opportunity is to turn a good baseline into a consistent team advantage.",
					"Scores for {$comp} indicate a stable platform. With small improvements in consistency, this can become a standout strength."
				);

				$p1_dev = array(
					"In {$comp}, results suggest a clear development opportunity. This capability may be showing up unevenly, especially in busy periods.",
					"{$comp} looks like a priority area. Inconsistency here can make execution feel less predictable and increase friction under load.",
					"Scores in {$comp} point to a development focus. Improving shared habits here will likely lift coordination and confidence."
				);

				if ( $level === 'strength' ) $p1 = icon_psy_pick_variant( $comp . '|p1|strength', $p1_strength );
				elseif ( $level === 'solid' ) $p1 = icon_psy_pick_variant( $comp . '|p1|solid', $p1_solid );
				else $p1 = icon_psy_pick_variant( $comp . '|p1|dev', $p1_dev );

				$everyday_blocks = array(
					'Make the behaviour visible in routine moments: stand-ups, handovers, and weekly check-ins. Agree one shared standard move so it becomes automatic.',
					'Build it into the operating rhythm: a short checklist, a shared question, or a quick reminder at the start of key meetings.',
					'Create consistency by naming the expected behaviour out loud and reinforcing it in the moment.'
				);

				$pressure_blocks = array(
					'Pressure is the lever. Choose one stabilising habit (clarify priorities, confirm roles, or surface risks early) and practise it consistently over the next two weeks.',
					'When the pace rises, agree a short reset routine: pause, prioritise, and decide. This keeps quality high and reduces rework.',
					'Under pressure, protect focus with one simple rule (one owner, one next action, one deadline). Repeat it until it becomes the default.'
				);

				$rolemodel_blocks = array(
					'Role-modelling is the lever. Make what good looks like explicit, demonstrate it consistently, and recognise it when you see it in others.',
					'Strengthen role-modelling by narrating the behaviour: explain the why behind decisions and show how to handle ambiguity constructively.',
					'Turn it into leadership consistency: reinforce standards in real time, use brief coaching moments, and align on team non-negotiables.'
				);

				if ( $lens === 'pressure-dip' ) $p2 = icon_psy_pick_variant( $comp . '|p2|pressure', $pressure_blocks );
				elseif ( $lens === 'rolemodel-dip' ) $p2 = icon_psy_pick_variant( $comp . '|p2|rolemodel', $rolemodel_blocks );
				elseif ( $lens === 'everyday-dip' ) $p2 = icon_psy_pick_variant( $comp . '|p2|everyday', $everyday_blocks );
				else $p2 = icon_psy_pick_variant( $comp . '|p2|mix', array_merge( $everyday_blocks, $pressure_blocks, $rolemodel_blocks ) );

				if ( $spread_note !== '' ) $p2 .= ' ' . $spread_note;

				return trim( $p1 ) . "\n\n" . trim( $p2 );
			}
		}

		// -----------------------------------------
		// Wheel SVG generator (solid colors + rater dots + min/max markers)
		// -----------------------------------------
		if ( ! function_exists( 'icon_psy_render_competency_wheel_svg' ) ) {
			function icon_psy_render_competency_wheel_svg( $rows, $opts = array() ) {

				$rows = is_array( $rows ) ? $rows : array();
				if ( empty( $rows ) ) return '';

				$max_items    = isset( $opts['max_items'] ) ? (int) $opts['max_items'] : 10;
				$size         = isset( $opts['size'] ) ? (int) $opts['size'] : 420;
				$vb_pad       = isset( $opts['viewbox_pad'] ) ? (int) $opts['viewbox_pad'] : 70;
				$label_mode   = isset( $opts['label_mode'] ) ? (string) $opts['label_mode'] : 'wrapped';
				$tooltips     = isset( $opts['tooltips'] ) ? (bool) $opts['tooltips'] : true;
				$links        = isset( $opts['links'] ) ? (bool) $opts['links'] : true;

				$show_raters  = isset( $opts['show_raters'] ) ? (bool) $opts['show_raters'] : true;
				$rater_points = ( isset( $opts['rater_points'] ) && is_array( $opts['rater_points'] ) ) ? $opts['rater_points'] : array(); // [comp_id][rater_id] => score
				$rater_labels = ( isset( $opts['rater_labels'] ) && is_array( $opts['rater_labels'] ) ) ? $opts['rater_labels'] : array(); // [rater_id] => label
				$minmax_map   = ( isset( $opts['minmax_map'] ) && is_array( $opts['minmax_map'] ) ) ? $opts['minmax_map'] : array();       // [comp_id] => ['min'=>, 'max'=>]

				$cx     = (int) floor( $size / 2 );
				$cy     = (int) floor( $size / 2 );
				$radius = (int) floor( ( $size / 2 ) - 60 );

				$use = array_slice( $rows, 0, max( 3, min( $max_items, count( $rows ) ) ) );
				$n   = count( $use );
				if ( $n < 3 ) return '';

				$max_scale  = 7.0;
				$levels     = array( 1, 2, 3, 4, 5, 6, 7 );
				$angle_step = ( 2 * M_PI ) / $n;

				$pt = function( $angle, $r ) use ( $cx, $cy ) {
					$x = $cx + ( $r * sin( $angle ) );
					$y = $cy - ( $r * cos( $angle ) );
					return array( $x, $y );
				};

				$wrap_label = function( $text, $max_len = 14, $max_lines = 3 ) {
					$text = trim( (string) $text );
					if ( $text === '' ) return array('');

					$words = preg_split( '/\s+/', $text );
					$lines = array();
					$line  = '';

					foreach ( $words as $w ) {
						$try = ( $line === '' ) ? $w : ( $line . ' ' . $w );
						$len = function_exists('mb_strlen') ? mb_strlen($try) : strlen($try);

						if ( $len <= $max_len ) $line = $try;
						else {
							if ( $line !== '' ) $lines[] = $line;
							$line = $w;
							if ( count( $lines ) >= $max_lines - 1 ) break;
						}
					}
					if ( $line !== '' && count( $lines ) < $max_lines ) $lines[] = $line;

					$joined = implode( ' ', $lines );
					if ( strtolower( $joined ) !== strtolower( $text ) ) {
						$last = rtrim( (string) array_pop( $lines ), '. ' );
						$lines[] = $last . '...';
					}
					return $lines;
				};

				$poly_pts = array();
				for ( $i = 0; $i < $n; $i++ ) {
					$val = isset( $use[ $i ]['overall'] ) ? (float) $use[ $i ]['overall'] : 0.0;
					$val = max( 1.0, min( $max_scale, $val ) );
					$r   = ( $val / $max_scale ) * $radius;
					$p   = $pt( $i * $angle_step, $r );
					$poly_pts[] = number_format( $p[0], 2, '.', '' ) . ',' . number_format( $p[1], 2, '.', '' );
				}

				$rings = '';
				foreach ( $levels as $lv ) {
					$r = ( (float) $lv / $max_scale ) * $radius;
					$ring_pts = array();
					for ( $i = 0; $i < $n; $i++ ) {
						$p = $pt( $i * $angle_step, $r );
						$ring_pts[] = number_format( $p[0], 2, '.', '' ) . ',' . number_format( $p[1], 2, '.', '' );
					}
					$rings .= '<polygon points="' . esc_attr( implode( ' ', $ring_pts ) ) . '" fill="none" stroke="#e5e7eb" stroke-width="1"/>';
				}

				$spokes = '';
				$labels = '';

				$font_size = 10.5;
				$line_h    = 12;

				$palette = array(
					'#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0ea5e9','#b45309','#4f46e5',
				);

				for ( $i = 0; $i < $n; $i++ ) {
					$a = $i * $angle_step;
					$p_outer = $pt( $a, $radius );
					$spokes .= '<line x1="' . esc_attr( $cx ) . '" y1="' . esc_attr( $cy ) . '" x2="' . esc_attr( number_format( $p_outer[0], 2, '.', '' ) ) . '" y2="' . esc_attr( number_format( $p_outer[1], 2, '.', '' ) ) . '" stroke="#e5e7eb" stroke-width="1"/>';

					$p_lab = $pt( $a, $radius + 32 );

					$name  = isset( $use[ $i ]['name'] ) ? (string) $use[ $i ]['name'] : ( 'Competency ' . ( $i + 1 ) );
					$val_o = isset( $use[ $i ]['overall'] ) ? (float) $use[ $i ]['overall'] : 0.0;

					$anchor = 'middle';
					if ( $p_lab[0] < $cx - 10 ) $anchor = 'end';
					if ( $p_lab[0] > $cx + 10 ) $anchor = 'start';

					$cid  = isset( $use[ $i ]['id'] ) ? (int) $use[ $i ]['id'] : 0;
					$href = $cid > 0 ? ( '#icon-comp-' . $cid ) : '';

					$title_line = esc_html( $name . ' | Overall ' . number_format( $val_o, 1 ) . ' / 7' );

					if ( $links && $href ) $labels .= '<a class="icon-wheel-link" href="' . esc_attr( $href ) . '" xlink:href="' . esc_attr( $href ) . '">';

					$labels .= '<text class="icon-wheel-label" x="' . esc_attr( number_format( $p_lab[0], 2, '.', '' ) ) . '" y="' . esc_attr( number_format( $p_lab[1], 2, '.', '' ) ) . '" font-size="' . esc_attr( $font_size ) . '" fill="#374151" text-anchor="' . esc_attr( $anchor ) . '">';
					if ( $tooltips ) $labels .= '<title>' . $title_line . '</title>';

					if ( $label_mode === 'overall_line' ) {
						$labels .= '<tspan x="' . esc_attr( number_format( $p_lab[0], 2, '.', '' ) ) . '" dy="0">' . $title_line . '</tspan>';
					} else {
						$lines = $wrap_label( $name, 14, 3 );
						for ( $li = 0; $li < count( $lines ); $li++ ) {
							$dy = ( $li === 0 ) ? 0 : $line_h;
							$labels .= '<tspan x="' . esc_attr( number_format( $p_lab[0], 2, '.', '' ) ) . '" dy="' . esc_attr( $dy ) . '">' . esc_html( $lines[ $li ] ) . '</tspan>';
						}
					}

					$labels .= '</text>';
					if ( $links && $href ) $labels .= '</a>';
				}

				$lvl_txt = '';
				foreach ( array( 1, 3, 5, 7 ) as $lv ) {
					$r = ( (float) $lv / $max_scale ) * $radius;
					$lvl_txt .= '<text x="' . esc_attr( $cx + 6 ) . '" y="' . esc_attr( $cy - $r + 4 ) . '" font-size="9.5" fill="#9ca3af">' . esc_html( (string) $lv ) . '</text>';
				}

				$vb_size = $size + ( 2 * $vb_pad );
				$vb_min  = -1 * $vb_pad;

				$svg  = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" role="img" aria-label="Competency wheel" ';
				$svg .= 'viewBox="' . esc_attr( $vb_min . ' ' . $vb_min . ' ' . $vb_size . ' ' . $vb_size ) . '" ';
				$svg .= 'style="display:block;width:100%;height:auto;max-width:100%;">';

				$svg .= '<rect x="' . esc_attr( $vb_min ) . '" y="' . esc_attr( $vb_min ) . '" width="' . esc_attr( $vb_size ) . '" height="' . esc_attr( $vb_size ) . '" fill="#ffffff"/>';
				$svg .= $rings . $spokes . $lvl_txt;

				$svg .= '<polygon points="' . esc_attr( implode( ' ', $poly_pts ) ) . '" fill="#0f766e" fill-opacity="0.16" stroke="#0f766e" stroke-width="2"/>';

				for ( $i = 0; $i < $n; $i++ ) {
					$val = isset( $use[ $i ]['overall'] ) ? (float) $use[ $i ]['overall'] : 0.0;
					$val = max( 1.0, min( $max_scale, $val ) );
					$p   = $pt( $i * $angle_step, ( $val / $max_scale ) * $radius );
					$svg .= '<circle class="icon-wheel-avg-dot" cx="' . esc_attr( number_format( $p[0], 2, '.', '' ) ) . '" cy="' . esc_attr( number_format( $p[1], 2, '.', '' ) ) . '" r="3.6" fill="#0f766e"/>';
				}

				for ( $i = 0; $i < $n; $i++ ) {
					$cid = isset( $use[ $i ]['id'] ) ? (int) $use[ $i ]['id'] : 0;
					if ( $cid <= 0 ) continue;

					$a = $i * $angle_step;

					if ( isset( $minmax_map[ $cid ] ) && is_array( $minmax_map[ $cid ] ) ) {
						$mn = array_key_exists( 'min', $minmax_map[ $cid ] ) ? $minmax_map[ $cid ]['min'] : null;
						$mx = array_key_exists( 'max', $minmax_map[ $cid ] ) ? $minmax_map[ $cid ]['max'] : null;

						if ( $mn !== null ) {
							$mn = max( 1.0, min( $max_scale, (float) $mn ) );
							$p  = $pt( $a, ( $mn / $max_scale ) * $radius );
							$svg .= '<circle class="icon-wheel-min-dot" cx="' . esc_attr( number_format( $p[0], 2, '.', '' ) ) . '" cy="' . esc_attr( number_format( $p[1], 2, '.', '' ) ) . '" r="4.0" fill="#ffffff" stroke="#b91c1c" stroke-width="2">';
							if ( $tooltips ) $svg .= '<title>Lowest rater score: ' . esc_html( number_format( (float) $mn, 1 ) ) . ' / 7</title>';
							$svg .= '</circle>';
						}

						if ( $mx !== null ) {
							$mx = max( 1.0, min( $max_scale, (float) $mx ) );
							$p  = $pt( $a, ( $mx / $max_scale ) * $radius );
							$svg .= '<circle class="icon-wheel-max-dot" cx="' . esc_attr( number_format( $p[0], 2, '.', '' ) ) . '" cy="' . esc_attr( number_format( $p[1], 2, '.', '' ) ) . '" r="4.0" fill="#ffffff" stroke="#166534" stroke-width="2">';
							if ( $tooltips ) $svg .= '<title>Highest rater score: ' . esc_html( number_format( (float) $mx, 1 ) ) . ' / 7</title>';
							$svg .= '</circle>';
						}
					}

					if ( $show_raters && isset( $rater_points[ $cid ] ) && is_array( $rater_points[ $cid ] ) ) {
						foreach ( $rater_points[ $cid ] as $rid => $v ) {
							$rid = (int) $rid;
							$v   = max( 1.0, min( $max_scale, (float) $v ) );

							$color = $palette[ icon_psy_hash_index( 'rater|' . $rid, count( $palette ) ) ];
							$p     = $pt( $a, ( $v / $max_scale ) * $radius );
							$lab   = isset( $rater_labels[ $rid ] ) ? (string) $rater_labels[ $rid ] : ( 'Rater #' . $rid );

							$svg .= '<circle class="icon-wheel-rater-dot" data-rid="' . esc_attr( (string) $rid ) . '" cx="' . esc_attr( number_format( $p[0], 2, '.', '' ) ) . '" cy="' . esc_attr( number_format( $p[1], 2, '.', '' ) ) . '" r="2.4" fill="' . esc_attr( $color ) . '" fill-opacity="0.95" stroke="#ffffff" stroke-width="1">';
							if ( $tooltips ) $svg .= '<title>' . esc_html( $lab . ' - ' . number_format( $v, 1 ) . ' / 7' ) . '</title>';
							$svg .= '</circle>';
						}
					}
				}

				$svg .= $labels . '</svg>';
				return $svg;
			}
		}

		// -----------------------------------------
		// Inputs
		// -----------------------------------------
		$atts = shortcode_atts( array( 'project_id' => 0 ), $atts, 'icon_psy_team_report' );

		$project_id = (int) $atts['project_id'];
		if ( ! $project_id && isset( $_GET['project_id'] ) ) $project_id = (int) $_GET['project_id'];

		if ( $project_id <= 0 && isset( $_GET['participant_id'] ) ) {
			$participant_id = (int) $_GET['participant_id'];
			if ( $participant_id > 0 ) {
				$participants_table = $wpdb->prefix . 'icon_psy_participants';
				if ( icon_psy_table_exists( $participants_table ) ) {
					$row = $wpdb->get_row( $wpdb->prepare( "SELECT project_id FROM {$participants_table} WHERE id = %d LIMIT 1", $participant_id ) );
					if ( $row && isset( $row->project_id ) ) $project_id = (int) $row->project_id;
				}
			}
		}

		if ( $project_id <= 0 ) return '<p>We could not find this project. Please check the link or contact your administrator.</p>';

		// -----------------------------------------
		// Tables
		// -----------------------------------------
		$projects_table     = $wpdb->prefix . 'icon_psy_projects';
		$competencies_table = $wpdb->prefix . 'icon_psy_framework_competencies';
		$raters_table       = $wpdb->prefix . 'icon_psy_raters';
		$participants_table = $wpdb->prefix . 'icon_psy_participants';

		$project = $wpdb->get_row( $wpdb->prepare( "SELECT pr.* FROM {$projects_table} pr WHERE pr.id = %d LIMIT 1", $project_id ) );
		if ( ! $project ) return '<p>We could not find this project. Please check the link or contact your administrator.</p>';

		// -----------------------------------------
		// Permissions (only enforce if NOT public share)
		// -----------------------------------------
		$is_admin            = current_user_can( 'manage_options' );
		$effective_client_id = (int) icon_psy_get_effective_client_user_id();

		if ( ! $allow_public && ! $is_admin ) {
			if ( $effective_client_id <= 0 ) return '<p>You do not have permission to view this report.</p>';
			$owner_id = isset( $project->client_user_id ) ? (int) $project->client_user_id : 0;
			if ( $owner_id <= 0 || $owner_id !== $effective_client_id ) return '<p>You do not have permission to view this report.</p>';
		}

		$brand_client_id = $allow_public
			? (int) ( isset( $project->client_user_id ) ? $project->client_user_id : 0 )
			: (int) $effective_client_id;

		// -----------------------------------------
		// Client branding (CANONICAL pattern)
		// -----------------------------------------
		$brand_primary   = '#15a06d';
		$brand_secondary = '#14a4cf';
		$brand_logo_url  = '';

		if ( function_exists( 'icon_psy_get_client_branding' ) && $brand_client_id > 0 ) {
			$b = icon_psy_get_client_branding( $brand_client_id );
			if ( is_array( $b ) ) {
				if ( ! empty( $b['primary'] ) )   $brand_primary   = (string) $b['primary'];
				if ( ! empty( $b['secondary'] ) ) $brand_secondary = (string) $b['secondary'];
				if ( ! empty( $b['logo_url'] ) )  $brand_logo_url  = (string) $b['logo_url'];
			}
		}

		$sanitize_hex = function( $c, $fallback ) {
			$c = trim( (string) $c );
			if ( $c === '' ) return $fallback;
			if ( $c[0] !== '#' ) $c = '#' . $c;
			return preg_match( '/^#([a-fA-F0-9]{6})$/', $c ) ? strtolower( $c ) : $fallback;
		};

		$brand_primary   = $sanitize_hex( $brand_primary,   '#15a06d' );
		$brand_secondary = $sanitize_hex( $brand_secondary, '#14a4cf' );
		$brand_logo_url  = trim( (string) $brand_logo_url );

		// FIX: unify variable used by the HTML
		$logo_url = $brand_logo_url;

		$project_name   = isset( $project->name ) ? (string) $project->name : '';
		$client_name    = isset( $project->client_name ) ? (string) $project->client_name : '';
		$project_status = isset( $project->status ) ? (string) $project->status : '';
		$framework_id   = isset( $project->framework_id ) ? (int) $project->framework_id : 0;

		// -----------------------------------------
		// Results fetch (AUTO-DETECTED SCHEMA)
		// -----------------------------------------
		$schema        = icon_psy_detect_results_schema_team();
		$results_table = $schema['table'];
		$map           = $schema['map'];
		$order_col     = $schema['order'];

		if ( ! $results_table || empty( $map['project_id'] ) ) {
			return '<p>Results table/project key could not be detected. Please confirm your results table schema.</p>';
		}

		$rater_relationship_col = '';
		if ( icon_psy_table_exists( $raters_table ) ) {
			$rcols = icon_psy_get_table_columns_lower( $raters_table );
			$rater_relationship_col = icon_psy_pick_col( $rcols, array( 'relationship', 'relationship_type', 'rater_relationship', 'relation', 'rel' ) );
		}

		$join_raters = ( ! empty( $map['rater_id'] ) && icon_psy_table_exists( $raters_table ) && $rater_relationship_col );

		$order_sql = "ORDER BY r.id ASC";
		if ( ! empty( $order_col ) ) $order_sql = "ORDER BY r.`" . esc_sql( $order_col ) . "` ASC";

		$team_where_bits = array();
		if ( ! empty( $map['assessment_type'] ) ) {
			$team_where_bits[] = "LOWER(COALESCE(r.`" . esc_sql( $map['assessment_type'] ) . "`,'')) IN ('team','teams','team_assessment','team-survey','team_survey')";
		}
		if ( ! empty( $map['context'] ) ) {
			$team_where_bits[] = "LOWER(COALESCE(r.`" . esc_sql( $map['context'] ) . "`,'')) IN ('team','teams','team_assessment','team-survey','team_survey')";
		}
		$team_where_sql = $team_where_bits ? " AND ( " . implode( " OR ", $team_where_bits ) . " )" : '';

		$sql = "
			SELECT r.*" . ( $join_raters ? ", rt.`" . esc_sql( $rater_relationship_col ) . "` AS rater_relationship" : "" ) . "
			FROM {$results_table} r
			" . ( $join_raters ? "LEFT JOIN {$raters_table} rt ON r.`" . esc_sql( $map['rater_id'] ) . "` = rt.id" : "" ) . "
			WHERE r.`" . esc_sql( $map['project_id'] ) . "` = %d
			{$team_where_sql}
			{$order_sql}
		";

		$raw_results = $wpdb->get_results( $wpdb->prepare( $sql, $project_id ) );

		if ( empty( $raw_results ) && $team_where_sql !== '' ) {
			$sql2 = "
				SELECT r.*" . ( $join_raters ? ", rt.`" . esc_sql( $rater_relationship_col ) . "` AS rater_relationship" : "" ) . "
				FROM {$results_table} r
				" . ( $join_raters ? "LEFT JOIN {$raters_table} rt ON r.`" . esc_sql( $map['rater_id'] ) . "` = rt.id" : "" ) . "
				WHERE r.`" . esc_sql( $map['project_id'] ) . "` = %d
				{$order_sql}
			";
			$raw_results = $wpdb->get_results( $wpdb->prepare( $sql2, $project_id ) );
		}

		$normalized = array();
		foreach ( (array) $raw_results as $r ) {
			$obj = new stdClass();
			foreach ( (array) $r as $k => $v ) { $obj->$k = $v; }

			foreach ( array( 'participant_id','rater_id','framework_id','q1_rating','q2_text','q3_text','detail_json','status','is_completed','completed_at' ) as $nk ) {
				if ( ! empty( $map[ $nk ] ) && isset( $r->{ $map[ $nk ] } ) ) $obj->$nk = $r->{ $map[ $nk ] };
			}
			if ( $join_raters && isset( $r->rater_relationship ) ) $obj->rater_relationship = $r->rater_relationship;

			$normalized[] = $obj;
		}

		$results = array();
		foreach ( (array) $normalized as $row ) {
			if ( icon_psy_team_row_is_completed_detected( $row ) ) $results[] = $row;
		}
		if ( empty( $results ) && ! empty( $normalized ) ) $results = $normalized;

		// -----------------------------------------
		// Empty state
		// -----------------------------------------
		if ( empty( $results ) ) {
			ob_start();
			?>
			<div class="icon-psy-report-wrapper"
				 style="max-width:960px;margin:0 auto;padding:24px 18px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;--icon-primary:<?php echo esc_attr( $brand_primary ); ?>;--icon-secondary:<?php echo esc_attr( $brand_secondary ); ?>;">
				<style>
					.icon-psy-report-wrapper,.icon-psy-report-wrapper *{box-sizing:border-box}
					.icon-psy-report-card{background:#fff;border-radius:16px;padding:22px 24px;box-shadow:0 18px 40px rgba(15,118,110,.08);border:1px solid #e5f2ec;margin-bottom:12px}
					.icon-psy-hero-header{display:flex;align-items:center;gap:18px;padding:18px 22px;border-radius:22px;margin-bottom:14px;background:linear-gradient(135deg,var(--icon-primary),var(--icon-secondary));color:#fff;position:relative;overflow:hidden}
					.icon-psy-hero-header::before{content:"";position:absolute;right:-80px;top:-80px;width:220px;height:220px;border-radius:999px;background:rgba(255,255,255,.16);pointer-events:none}
					.icon-psy-hero-header::after{content:"";position:absolute;left:-60px;bottom:-60px;width:180px;height:180px;border-radius:999px;background:rgba(255,255,255,.10);pointer-events:none}
					.icon-psy-hero-logo img{width:60px;height:60px;object-fit:contain;background:#fff;padding:8px;border-radius:14px;box-shadow:0 10px 20px rgba(0,0,0,.12)}
					.icon-psy-hero-title{margin:3px 0 2px;font-size:28px;font-weight:900;line-height:1.05}
					.icon-psy-hero-sub{font-size:13px;opacity:.92;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
				</style>

				<div class="icon-psy-hero-header">
					<?php if ( ! empty( $logo_url ) ) : ?>
						<div class="icon-psy-hero-logo"><img src="<?php echo esc_url( $logo_url ); ?>" alt=""></div>
					<?php endif; ?>
					<div style="flex:1;min-width:0">
						<div style="font-size:11px;letter-spacing:.10em;text-transform:uppercase;opacity:.88;">ICON Catalyst - Team insight</div>
						<h1 class="icon-psy-hero-title">Team Report</h1>
						<div class="icon-psy-hero-sub">
							<?php if ( $project_name ) : ?><strong><?php echo esc_html( $project_name ); ?></strong><?php endif; ?>
							<?php if ( $client_name ) : ?> • <?php echo esc_html( $client_name ); ?><?php endif; ?>
						</div>
					</div>
				</div>

				<div class="icon-psy-report-card">
					<h2 style="margin:0 0 6px;font-size:18px;font-weight:900;color:#022c22;">No team rows found</h2>
					<p style="margin:0;font-size:13px;color:#6b7280;line-height:1.55;">
						We couldn’t find any completed team rows in the detected results table for this project.
					</p>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		// -----------------------------------------
		// Framework name
		// -----------------------------------------
		$framework_name     = '';
		$first_framework_id = $framework_id;

		if ( $first_framework_id <= 0 ) {
			foreach ( $results as $row ) {
				if ( ! empty( $row->framework_id ) ) { $first_framework_id = (int) $row->framework_id; break; }
			}
		}

		if ( $first_framework_id > 0 ) {
			$frameworks_table = $wpdb->prefix . 'icon_psy_frameworks';
			if ( icon_psy_table_exists( $frameworks_table ) ) {
				$fw = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$frameworks_table} WHERE id = %d LIMIT 1", $first_framework_id ) );
				if ( $fw && ! empty( $fw->name ) ) $framework_name = (string) $fw->name;
			}
		}

		// -----------------------------------------
		// Aggregate + heat data + rater points
		// -----------------------------------------
		$strengths     = array();
		$dev_opps      = array();
		$rater_ids     = array();
		$overall_sum   = 0;
		$overall_count = 0;

		$rel_counts = array(
			'self'          => 0,
			'line_manager'  => 0,
			'peer'          => 0,
			'direct_report' => 0,
			'other'         => 0,
			'unknown'       => 0,
		);

		$heat_agg = array();
		$rater_comp_overall = array(); // [competency_id][rater_id] => score

		foreach ( $results as $row ) {

			$rid_val = 0;
			if ( isset( $row->rater_id ) && $row->rater_id !== null && $row->rater_id !== '' ) {
				$rid_val = (int) $row->rater_id;
				$rater_ids[ $rid_val ] = true;
			}

			if ( ! empty( $row->q2_text ) ) $strengths[] = (string) $row->q2_text;
			if ( ! empty( $row->q3_text ) ) $dev_opps[]  = (string) $row->q3_text;

			if ( isset( $row->q1_rating ) && $row->q1_rating !== null && $row->q1_rating !== '' ) {
				$overall_sum   += (float) $row->q1_rating;
				$overall_count += 1;
			}

			if ( isset( $row->rater_relationship ) ) {
				$rel = strtolower( trim( (string) $row->rater_relationship ) );
				if ( $rel === '' ) $rel = 'unknown';

				if ( strpos( $rel, 'self' ) !== false ) $rel_counts['self']++;
				elseif ( strpos( $rel, 'manager' ) !== false || strpos( $rel, 'line' ) !== false ) $rel_counts['line_manager']++;
				elseif ( strpos( $rel, 'peer' ) !== false ) $rel_counts['peer']++;
				elseif ( strpos( $rel, 'direct' ) !== false || strpos( $rel, 'report' ) !== false ) $rel_counts['direct_report']++;
				elseif ( $rel === 'unknown' ) $rel_counts['unknown']++;
				else $rel_counts['other']++;
			} else {
				$rel_counts['unknown']++;
			}

			if ( empty( $row->detail_json ) ) continue;
			$detail = json_decode( $row->detail_json, true );
			if ( ! is_array( $detail ) ) continue;

			foreach ( $detail as $entry ) {
				if ( empty( $entry['competency_id'] ) ) continue;
				$cid = (int) $entry['competency_id'];

				$q1 = array_key_exists( 'q1', $entry ) ? ( $entry['q1'] === null ? null : (float) $entry['q1'] ) : null;
				$q2 = array_key_exists( 'q2', $entry ) ? ( $entry['q2'] === null ? null : (float) $entry['q2'] ) : null;
				$q3 = array_key_exists( 'q3', $entry ) ? ( $entry['q3'] === null ? null : (float) $entry['q3'] ) : null;

				if ( ! isset( $heat_agg[ $cid ] ) ) {
					$heat_agg[ $cid ] = array(
						'sum_q1' => 0, 'cnt_q1' => 0,
						'sum_q2' => 0, 'cnt_q2' => 0,
						'sum_q3' => 0, 'cnt_q3' => 0,
						'overall_vals' => array(),
					);
				}

				$has_any = false;
				if ( $q1 !== null ) { $heat_agg[ $cid ]['sum_q1'] += $q1; $heat_agg[ $cid ]['cnt_q1']++; $has_any = true; }
				if ( $q2 !== null ) { $heat_agg[ $cid ]['sum_q2'] += $q2; $heat_agg[ $cid ]['cnt_q2']++; $has_any = true; }
				if ( $q3 !== null ) { $heat_agg[ $cid ]['sum_q3'] += $q3; $heat_agg[ $cid ]['cnt_q3']++; $has_any = true; }

				if ( $has_any ) {
					$vals = array();
					if ( $q1 !== null ) $vals[] = $q1;
					if ( $q2 !== null ) $vals[] = $q2;
					if ( $q3 !== null ) $vals[] = $q3;

					$overall = $vals ? ( array_sum( $vals ) / count( $vals ) ) : null;
					if ( $overall !== null ) {
						$heat_agg[ $cid ]['overall_vals'][] = (float) $overall;
						if ( $rid_val > 0 ) {
							if ( ! isset( $rater_comp_overall[ $cid ] ) ) $rater_comp_overall[ $cid ] = array();
							$rater_comp_overall[ $cid ][ $rid_val ] = (float) $overall;
						}
					}
				}
			}
		}

		$num_responses = count( $results );
		$num_raters    = count( $rater_ids );
		if ( $num_raters <= 0 ) $num_raters = $num_responses;
		$overall_avg = $overall_count > 0 ? ( $overall_sum / $overall_count ) : null;

		// -----------------------------------------
		// Build rater labels
		// -----------------------------------------
		$rater_labels = array();
		if ( $rater_ids && icon_psy_table_exists( $raters_table ) ) {
			$rids   = array_values( array_map( 'intval', array_keys( $rater_ids ) ) );
			$rcols  = icon_psy_get_table_columns_lower( $raters_table );
			$c_name = icon_psy_pick_col( $rcols, array( 'name','full_name','display_name','rater_name' ) );
			$c_email= icon_psy_pick_col( $rcols, array( 'email','email_address','rater_email' ) );
			$c_rel  = icon_psy_pick_col( $rcols, array( 'relationship', 'relationship_type', 'rater_relationship', 'relation', 'rel' ) );

			$select = array( 'id' );
			if ( $c_name )  $select[] = '`' . esc_sql( $c_name ) . '` AS r_name';
			if ( $c_email ) $select[] = '`' . esc_sql( $c_email ) . '` AS r_email';
			if ( $c_rel )   $select[] = '`' . esc_sql( $c_rel ) . '` AS r_rel';

			$placeholders = implode( ',', array_fill( 0, count( $rids ), '%d' ) );
			$q = "SELECT " . implode( ',', $select ) . " FROM {$raters_table} WHERE id IN ({$placeholders})";
			$args = array_merge( array( $q ), $rids );

			$rows = $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), $args ) );
			foreach ( (array) $rows as $rr ) {
				$rid = isset( $rr->id ) ? (int) $rr->id : 0;
				if ( $rid <= 0 ) continue;

				$nm = isset( $rr->r_name )  ? trim( (string) $rr->r_name )  : '';
				$em = isset( $rr->r_email ) ? trim( (string) $rr->r_email ) : '';
				$rl = isset( $rr->r_rel )   ? trim( (string) $rr->r_rel )   : '';

				$label = $nm !== '' ? $nm : ( $em !== '' ? $em : ( 'Rater #' . $rid ) );
				if ( $rl !== '' ) $label .= ' (' . $rl . ')';

				$rater_labels[ $rid ] = $label;
			}
		}

		foreach ( array_keys( (array) $rater_ids ) as $rid ) {
			$rid = (int) $rid;
			if ( $rid > 0 && ! isset( $rater_labels[ $rid ] ) ) $rater_labels[ $rid ] = 'Rater #' . $rid;
		}

		$rater_palette = array(
			'#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0ea5e9','#b45309','#4f46e5',
		);
		$rater_colors = array();
		foreach ( (array) $rater_labels as $rid => $lab ) {
			$rid = (int) $rid;
			if ( $rid > 0 ) $rater_colors[ $rid ] = $rater_palette[ icon_psy_hash_index( 'rater|' . $rid, count( $rater_palette ) ) ];
		}

		// -----------------------------------------
		// Build heatmap rows with competency names/descriptions
		// -----------------------------------------
		$heatmap_rows = array();

		if ( $heat_agg && icon_psy_table_exists( $competencies_table ) ) {
			$competency_ids = array_values( array_filter( array_map( 'intval', array_keys( $heat_agg ) ) ) );
			if ( $competency_ids ) {
				$cols_lower = icon_psy_get_table_columns_lower( $competencies_table );
				$has_desc   = in_array( 'description', $cols_lower, true );
				$has_lens   = in_array( 'lens_code',   $cols_lower, true );

				$select_bits = array( 'id', 'name' );
				if ( $has_desc ) $select_bits[] = 'description';
				if ( $has_lens ) $select_bits[] = 'lens_code';

				$placeholders = implode( ',', array_fill( 0, count( $competency_ids ), '%d' ) );
				$sqlc = "SELECT " . implode( ', ', $select_bits ) . " FROM {$competencies_table} WHERE id IN ($placeholders) ORDER BY id ASC";

				$args = array_merge( array( $sqlc ), $competency_ids );
				$competencies = $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), $args ) );

				foreach ( (array) $competencies as $comp ) {
					$cid = (int) $comp->id;
					if ( empty( $heat_agg[ $cid ] ) ) continue;

					$agg2 = $heat_agg[ $cid ];

					$avg_q1 = ( $agg2['cnt_q1'] > 0 ) ? ( $agg2['sum_q1'] / (int) $agg2['cnt_q1'] ) : null;
					$avg_q2 = ( $agg2['cnt_q2'] > 0 ) ? ( $agg2['sum_q2'] / (int) $agg2['cnt_q2'] ) : null;
					$avg_q3 = ( $agg2['cnt_q3'] > 0 ) ? ( $agg2['sum_q3'] / (int) $agg2['cnt_q3'] ) : null;

					$parts = array();
					if ( $avg_q1 !== null ) $parts[] = (float) $avg_q1;
					if ( $avg_q2 !== null ) $parts[] = (float) $avg_q2;
					if ( $avg_q3 !== null ) $parts[] = (float) $avg_q3;
					$overall = $parts ? ( array_sum( $parts ) / count( $parts ) ) : 0.0;

					$vals = ( isset( $agg2['overall_vals'] ) && is_array( $agg2['overall_vals'] ) ) ? $agg2['overall_vals'] : array();
					$sd = null;
					if ( count( $vals ) >= 2 ) {
						$mean = array_sum( $vals ) / count( $vals );
						$var_sum = 0;
						foreach ( $vals as $v ) { $var_sum += pow( (float) $v - (float) $mean, 2 ); }
						$sd  = sqrt( $var_sum / count( $vals ) );
					} elseif ( count( $vals ) === 1 ) {
						$sd = 0.0;
					}

					$n = (int) count( $vals );

					$heatmap_rows[] = array(
						'id'          => $cid,
						'name'        => (string) $comp->name,
						'description' => ( $has_desc && isset( $comp->description ) ) ? (string) $comp->description : '',
						'lens_code'   => ( $has_lens && isset( $comp->lens_code ) && $comp->lens_code !== '' ) ? (string) $comp->lens_code : 'CLARITY',
						'avg_q1'      => ( $avg_q1 === null ? 0.0 : (float) $avg_q1 ),
						'avg_q2'      => ( $avg_q2 === null ? 0.0 : (float) $avg_q2 ),
						'avg_q3'      => ( $avg_q3 === null ? 0.0 : (float) $avg_q3 ),
						'overall'     => (float) $overall,
						'n'           => $n,
						'sd'          => $sd,
					);
				}
			}
		}

		if ( $heatmap_rows ) {
			usort( $heatmap_rows, function( $a, $b ){
				if ( $a['overall'] === $b['overall'] ) return 0;
				return ( $a['overall'] > $b['overall'] ) ? -1 : 1;
			} );
		}

		$top_strengths = $heatmap_rows ? array_slice( $heatmap_rows, 0, 3 ) : array();
		$top_devs      = $heatmap_rows ? array_slice( array_reverse( $heatmap_rows ), 0, 3 ) : array();

		$focus_list = array();
		if ( $heatmap_rows ) {
			$cands = array();
			foreach ( $heatmap_rows as $r ) {
				$gap = icon_psy_lens_gap_info( $r['avg_q1'], $r['avg_q2'], $r['avg_q3'] );
				$r['_gap']       = $gap['gap'];
				$r['_gap_max_k'] = $gap['max_k'];
				$r['_gap_min_k'] = $gap['min_k'];
				$r['_gap_max_v'] = $gap['max_v'];
				$r['_gap_min_v'] = $gap['min_v'];
				$cands[] = $r;
			}
			usort( $cands, function( $a, $b ){
				if ( (float) $a['overall'] !== (float) $b['overall'] ) return ( (float) $a['overall'] < (float) $b['overall'] ) ? -1 : 1;
				if ( (float) $a['_gap'] !== (float) $b['_gap'] ) return ( (float) $a['_gap'] > (float) $b['_gap'] ) ? -1 : 1;
				return 0;
			} );
			$focus_list = array_slice( $cands, 0, 2 );
		}

		$clean_points = function( $arr, $limit = 6 ) {
			$out = array();
			foreach ( (array) $arr as $t ) {
				$t = icon_psy_safe_trim( (string) $t, 220 );
				if ( $t === '' ) continue;
				$k = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $t ) );
				if ( $k === '' || isset( $out[ $k ] ) ) continue;
				$out[ $k ] = $t;
				if ( count( $out ) >= (int) $limit ) break;
			}
			return array_values( $out );
		};

		$strength_points = $clean_points( $strengths, 6 );
		$dev_points      = $clean_points( $dev_opps, 6 );

		$wheel_minmax = array();
		foreach ( (array) $rater_comp_overall as $cid => $by_rater ) {
			if ( ! is_array( $by_rater ) || empty( $by_rater ) ) continue;
			$vals = array();
			foreach ( $by_rater as $v ) { $v = (float) $v; if ( $v > 0 ) $vals[] = $v; }
			if ( $vals ) $wheel_minmax[ (int) $cid ] = array( 'min' => min( $vals ), 'max' => max( $vals ) );
		}

		// -----------------------------------------
		// Competency wheel (set switching)
		// -----------------------------------------
		$wheel_svg = '';
		$wheel_comp_ids = array();
		$set_index = isset( $_GET['wheelset'] ) ? max( 0, (int) $_GET['wheelset'] ) : 0;
		$per_set   = 10;

		if ( $heatmap_rows ) {
			$wheel_rows = array();
			foreach ( array_slice( $heatmap_rows, $set_index * $per_set, $per_set ) as $r ) {
				$wheel_rows[] = array(
					'id'      => (int) $r['id'],
					'name'    => (string) $r['name'],
					'overall' => (float) $r['overall'],
				);
				$wheel_comp_ids[] = (int) $r['id'];
			}

			$wheel_rater_points = array();
			foreach ( $wheel_comp_ids as $cid ) {
				if ( isset( $rater_comp_overall[ $cid ] ) && is_array( $rater_comp_overall[ $cid ] ) ) {
					$wheel_rater_points[ $cid ] = $rater_comp_overall[ $cid ];
				}
			}

			$wheel_svg = icon_psy_render_competency_wheel_svg(
				$wheel_rows,
				array(
					'max_items'    => 10,
					'size'         => 560,
					'viewbox_pad'  => 40,
					'label_mode'   => 'wrapped',
					'tooltips'     => true,
					'links'        => true,
					'show_raters'  => true,
					'rater_points' => $wheel_rater_points, // FIX: actually pass points
					'rater_labels' => $rater_labels,
					'minmax_map'   => $wheel_minmax,
				)
			);
		}

		$summary_level = '';
		if ( $overall_avg !== null ) {
			if ( $overall_avg >= 5.5 ) $summary_level = 'strong';
			elseif ( $overall_avg >= 4.5 ) $summary_level = 'balanced';
			else $summary_level = 'developing';
		}

		if ( $summary_level === 'strong' ) $summary_sentence = 'Overall results suggest a strong team profile, with consistently positive scores across most areas.';
		elseif ( $summary_level === 'balanced' ) $summary_sentence = 'Overall results indicate a solid and balanced team profile, with clear strengths and a small number of improvement priorities.';
		elseif ( $summary_level === 'developing' ) $summary_sentence = 'Results highlight clear improvement opportunities to build more consistent team effectiveness, especially in everyday execution and under pressure.';
		else $summary_sentence = 'Results are available, but overall scoring is limited in the current dataset. Use the competency view to guide discussion.';

		$team_member_count = 0;
		if ( icon_psy_table_exists( $participants_table ) ) {
			$team_member_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$participants_table} WHERE project_id = %d", $project_id ) );
		}

		$bar_pct = function( $v ) {
			$v = max( 0.0, min( 7.0, (float) $v ) );
			return ( $v / 7.0 ) * 100.0;
		};

		// -----------------------------------------
		// Output HTML
		// -----------------------------------------
		ob_start();
		?>
		<div id="icon-report-top" class="icon-psy-report-wrapper"
			 style="max-width:960px;margin:0 auto;padding:24px 18px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;--icon-primary:<?php echo esc_attr( $brand_primary ); ?>;--icon-secondary:<?php echo esc_attr( $brand_secondary ); ?>;">

			<style>
				html{scroll-behavior:smooth}
				.icon-psy-report-wrapper,.icon-psy-report-wrapper *{box-sizing:border-box}
				.icon-psy-report-card{background:#fff;border-radius:16px;padding:22px 24px;box-shadow:0 18px 40px rgba(15,118,110,.08);border:1px solid rgba(20,164,207,.18);margin-bottom:12px;position:relative;overflow:hidden}
				.icon-psy-report-card:before{content:"";position:absolute;left:0;top:0;width:100%;height:8px;background:linear-gradient(135deg,var(--icon-primary),var(--icon-secondary));opacity:.95}
				.icon-psy-report-card:after{content:"";position:absolute;right:-90px;top:-90px;width:220px;height:220px;border-radius:999px;background:radial-gradient(circle at 30% 30%,rgba(20,164,207,.18),rgba(21,160,109,.06) 55%,rgba(255,255,255,0) 70%);pointer-events:none}

				.icon-psy-hero-header{display:flex;align-items:center;gap:18px;padding:18px 22px;border-radius:22px;margin-bottom:14px;background:linear-gradient(135deg,var(--icon-primary),var(--icon-secondary));color:#fff;position:relative;overflow:hidden}
				.icon-psy-hero-header:before{content:"";position:absolute;right:-90px;top:-90px;width:260px;height:260px;border-radius:999px;background:rgba(255,255,255,.16);pointer-events:none}
				.icon-psy-hero-header:after{content:"";position:absolute;left:-70px;bottom:-70px;width:210px;height:210px;border-radius:999px;background:rgba(255,255,255,.10);pointer-events:none}
				.icon-psy-hero-logo img{width:60px;height:60px;object-fit:contain;background:#fff;padding:8px;border-radius:14px;box-shadow:0 12px 24px rgba(0,0,0,.12)}
				.icon-psy-hero-titlewrap{flex:1;min-width:0}
				.icon-psy-hero-eyebrow{font-size:11px;letter-spacing:.10em;text-transform:uppercase;opacity:.88}
				.icon-psy-hero-title{margin:3px 0 2px;font-size:30px;font-weight:900;line-height:1.05}
				.icon-psy-hero-sub{font-size:13px;opacity:.92;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
				.icon-psy-hero-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
				.icon-psy-hero-btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.14);color:#fff;font-size:12px;font-weight:900;text-decoration:none;backdrop-filter:blur(6px)}
				.icon-psy-hero-btn:hover{background:rgba(255,255,255,.22)}

				.icon-psy-chip-row{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;font-size:11px}
				.icon-psy-chip,.icon-psy-chip-muted{padding:3px 9px;border-radius:999px;border:1px solid rgba(255,255,255,.28);color:#fff}
				.icon-psy-chip{background:rgba(255,255,255,.18)}
				.icon-psy-chip-muted{background:rgba(255,255,255,.14)}

				.icon-psy-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
				.icon-psy-section-title{font-size:16px;font-weight:600;margin:0 0 4px;color:#022c22}
				.icon-psy-section-title:after{content:"";display:block;width:42px;height:3px;margin-top:6px;border-radius:999px;background:linear-gradient(135deg,var(--icon-primary),var(--icon-secondary));opacity:.95}
				.icon-psy-section-sub{margin:0 0 10px;font-size:13px;color:#6b7280}
				.icon-psy-screen-only{display:block}

				.icon-psy-backtop{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:999px;border:1px solid rgba(21,160,109,.25);background:rgba(21,160,109,.08);color:#065f46;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap;margin-top:1px}
				.icon-psy-backtop:hover{background:rgba(21,160,109,.12)}

				.icon-psy-fac-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-radius:14px;border:1px solid #e5e7eb;background:#fff;margin:12px 0 0}
				.icon-psy-fac-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
				.icon-psy-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:999px;border:1px solid var(--icon-primary);background:var(--icon-primary);color:#fff;font-size:12px;font-weight:800;text-decoration:none;box-shadow:0 10px 22px rgba(21,160,109,.18);cursor:pointer}
				.icon-psy-btn-secondary{border:1px solid #e5e7eb;background:#f9fafb;color:#0a3b34;box-shadow:none}
				.icon-psy-pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;border:1px solid #e5e7eb;background:#f3f4f6;font-size:11px;color:#374151;font-weight:900}
				.icon-psy-pill-good{background:#ecfdf5;border-color:#bbf7d0;color:#065f46}
				.icon-psy-pill-warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
				.icon-psy-pill-attn{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
				.icon-psy-pill-muted{background:#f3f4f6;border-color:#e5e7eb;color:#4b5563}

				.icon-psy-debrief .icon-psy-debrief-hide{display:none!important}
				.icon-psy-debrief .icon-psy-report-card{box-shadow:none;border-color:#e5e7eb}
				.icon-psy-debrief .icon-psy-report-wrapper{max-width:1100px}
				.icon-psy-debrief .icon-psy-backtop{display:none!important}

				.icon-psy-toc{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:10px}
				@media (max-width:820px){.icon-psy-toc{grid-template-columns:1fr}}
				.icon-psy-toc a{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-radius:14px;border:1px solid #e5e7eb;background:#f9fafb;text-decoration:none;color:#0a3b34;font-size:13px;font-weight:600}
				.icon-psy-toc a span{font-size:11px;color:#6b7280;font-weight:600}
				.icon-psy-toc a:hover{background:#fff}

				.icon-psy-summary-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,2fr);gap:14px}
				@media (max-width:720px){.icon-psy-summary-grid{grid-template-columns:1fr}}
				.icon-psy-summary-box{border-radius:12px;border:1px solid #e5e7eb;background:#f9fafb;padding:10px 12px;font-size:13px;color:#374151}
				.icon-psy-summary-label{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:3px}
				.icon-psy-summary-metric{font-size:20px;font-weight:700;color:#065f46}
				.icon-psy-summary-tag{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;border:1px solid #bbf7d0;background:#ecfdf5;font-size:11px;color:#166534;margin-top:6px}

				.icon-psy-two-col{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px;margin-top:10px}
				@media (max-width:820px){.icon-psy-two-col{grid-template-columns:1fr}}
				.icon-psy-pick-card{border-radius:14px;border:1px solid #e5e7eb;background:#f9fafb;padding:12px}
				.icon-psy-pick-title{margin:0 0 6px;font-size:13px;font-weight:700;color:#022c22}
				.icon-psy-pick-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;border-radius:12px;background:#fff;border:1px solid #eef2f7;margin:0 0 8px;text-decoration:none;color:inherit}
				.icon-psy-pick-name{font-size:12px;font-weight:600;color:#111827;line-height:1.2}
				.icon-psy-pick-score{font-size:12px;font-weight:800;color:#0f766e;font-variant-numeric:tabular-nums;white-space:nowrap}

				.icon-psy-focus-box{margin-top:10px;border-radius:14px;border:1px solid #e5e7eb;background:#fff;padding:12px}
				.icon-psy-focus-title{margin:0 0 6px;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:var(--icon-primary)}
				.icon-psy-focus-item{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:10px;border-radius:12px;border:1px solid #eef2f7;background:#f9fafb;margin:0 0 8px;text-decoration:none;color:inherit}
				.icon-psy-focus-item:hover{background:#fff}
				.icon-psy-focus-name{font-size:13px;font-weight:900;color:#022c22;margin:0 0 2px}
				.icon-psy-focus-meta{font-size:11px;color:#6b7280;line-height:1.35}
				.icon-psy-focus-score{font-size:12px;font-weight:900;color:#0f766e;white-space:nowrap}

				.icon-psy-heat-table-wrapper{overflow-x:auto;margin-top:6px}
				.icon-psy-heat-table{width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed}
				.icon-psy-heat-table thead th{text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;background:#f9fafb;font-weight:600;color:#374151;white-space:nowrap}
				.icon-psy-heat-table tbody td{padding:7px 8px;border-bottom:1px solid #f3f4f6;vertical-align:middle;overflow:hidden}
				.icon-psy-heat-table thead th:nth-child(1){width:36%}
				.icon-psy-heat-table thead th:nth-child(n+2){width:16%}
				.icon-psy-heat-table td:first-child{white-space:normal;overflow-wrap:anywhere;word-break:break-word}
				.icon-psy-heat-table td:not(:first-child){white-space:nowrap;text-align:center}
				.icon-psy-heat-name{font-weight:700;color:#111827}
				.icon-psy-heat-link{text-decoration:none;color:inherit}
				.icon-psy-heat-link:hover .icon-psy-heat-name{text-decoration:underline;text-decoration-color:var(--icon-secondary)}
				.icon-psy-heat-cell{display:inline-block;text-align:center;width:74px;min-width:74px;line-height:26px;height:26px;border-radius:999px;padding:0 10px;font-variant-numeric:tabular-nums}
				.icon-psy-heat-low{background:#fef2f2;color:#b91c1c}
				.icon-psy-heat-mid{background:#fffbeb;color:#92400e}
				.icon-psy-heat-high{background:#ecfdf5;color:#166534}

				.icon-psy-wheel-row{display:grid;grid-template-columns:1fr;gap:12px;align-items:stretch;margin-top:10px}
				.icon-psy-wheel-card{border-radius:16px;border:1px solid #e5e7eb;background:#fff;padding:10px 12px;width:100%;overflow:visible;position:relative}
				.icon-psy-wheel-visual{line-height:0;font-size:0;display:flex;justify-content:center;align-items:flex-start;position:relative;z-index:1}
				.icon-psy-wheel-card svg{transform:translateY(-8px);transform-origin:center top}
				.icon-psy-wheel-under{margin-top:-14px;padding:0 4px 2px;display:flex;flex-direction:column;align-items:center;gap:6px;position:relative;z-index:5}
				.icon-psy-mini-btn{appearance:none;display:inline-flex;align-items:center;justify-content:center;padding:4px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;color:#0a3b34;font-size:11px;font-weight:800;cursor:pointer}
				.icon-psy-mini-btn:hover{background:#f9fafb}
				.icon-wheel-hide-raters .icon-wheel-rater-dot{display:none}
				.icon-psy-legend-key{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px;font-size:11px;color:#6b7280;justify-content:center}
				.icon-psy-legend-key span{display:inline-flex;align-items:center;gap:6px;padding:3px 8px;border:1px solid #e5e7eb;background:#fff;border-radius:999px}
				.icon-psy-key-swatch{width:10px;height:10px;border-radius:999px;flex:0 0 auto}
				.icon-psy-key-min{background:#fff;border:2px solid #b91c1c}
				.icon-psy-key-max{background:#fff;border:2px solid #166534}
				.icon-psy-key-avg{background:#0f766e}
				.icon-psy-rater-list{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:6px;font-size:11px;color:#374151}
				.icon-psy-rater-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 8px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;font-size:11px;color:#374151}
				.icon-psy-rater-pill:hover{background:#f9fafb}
				.icon-psy-rater-pill[aria-pressed="false"]{opacity:.35;filter:grayscale(.2)}
				.icon-psy-rater-dot{width:10px;height:10px;border-radius:999px;border:1px solid rgba(0,0,0,.08);flex:0 0 auto}
				.icon-psy-wheel-notes{border-radius:16px;border:1px solid #e5e7eb;background:#f9fafb;padding:12px;font-size:13px;color:#374151}
				.icon-psy-wheel-notes h4{margin:0 0 6px;font-size:14px}
				.icon-psy-wheel-notes ul{margin:8px 0 0 18px}
				.icon-psy-wheel-notes li{margin:0 0 6px}
				.icon-wheel-label{cursor:pointer}
				.icon-wheel-link{text-decoration:none}

				.icon-psy-competency-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin-top:10px}
				.icon-psy-competency-summary-card{position:relative;background:linear-gradient(180deg,rgba(255,255,255,.92),rgba(249,250,251,.92));border:1px solid rgba(20,164,207,.16);border-radius:18px;box-shadow:0 18px 40px rgba(2,44,34,.06);overflow:hidden;padding:12px 12px;font-size:13px;color:#374151;scroll-margin-top:84px}
				.icon-psy-competency-summary-card:before{content:"";position:absolute;left:0;top:0;width:100%;height:8px;background:linear-gradient(135deg,var(--icon-primary),var(--icon-secondary));opacity:.95}
				.icon-psy-competency-summary-card:after{content:"";position:absolute;right:-70px;top:-70px;width:200px;height:200px;border-radius:999px;background:radial-gradient(circle at 30% 30%,rgba(20,164,207,.18),rgba(21,160,109,.08) 55%,rgba(255,255,255,0) 70%);pointer-events:none}
				@media (hover:hover){.icon-psy-competency-summary-card:hover{transform:translateY(-2px);box-shadow:0 22px 56px rgba(2,44,34,.10);border-color:rgba(20,164,207,.26)}}
				.icon-psy-competency-summary-card:target{outline:3px solid rgba(21,160,109,.22);box-shadow:0 24px 70px rgba(21,160,109,.14)}
				.icon-psy-competency-summary-name{font-size:15px;font-weight:900;letter-spacing:-.01em;margin:4px 0 4px;padding-right:140px;color:#022c22;line-height:1.25}
				.icon-psy-competency-metrics{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;font-size:11px;padding-right:140px}
				.icon-psy-competency-pill{padding:2px 7px;border-radius:999px;border:1px solid rgba(21,160,109,.20);background:rgba(21,160,109,.10);color:#064e3b;font-weight:900}
				.icon-psy-competency-pill-muted{padding:2px 7px;border-radius:999px;border:1px solid rgba(20,164,207,.14);background:rgba(20,164,207,.06);color:#0f3a46;font-weight:800}

				.icon-psy-mini-bars{display:grid;gap:8px;grid-template-columns:repeat(3,minmax(0,1fr));margin:10px 0}
				@media (max-width:820px){.icon-psy-mini-bars{grid-template-columns:1fr}}
				.icon-psy-mini-bar-card{border:1px solid rgba(20,164,207,.14);border-radius:14px;background:rgba(255,255,255,.92);padding:7px 9px}
				.icon-psy-mini-bar-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:5px;min-width:0;flex-wrap:nowrap}
				.icon-psy-mini-bar-label{display:block;font-size:9px;font-weight:800;color:#374151;line-height:1.15;flex:1 1 auto;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
				.icon-psy-mini-bar-score{font-size:9px;font-weight:900;color:#022c22;font-variant-numeric:tabular-nums;white-space:nowrap;flex:0 0 auto;line-height:1.15}
				.icon-psy-mini-bar-track{height:8px;border-radius:999px;background:rgba(2,44,34,.08);overflow:hidden}
				.icon-psy-mini-bar-fill{height:8px;border-radius:999px;background:linear-gradient(90deg,var(--icon-primary),var(--icon-secondary))}

				.icon-psy-module-label{position:absolute;top:12px;right:12px;z-index:3;display:inline-flex;align-items:center;gap:8px;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;border:1px solid rgba(255,255,255,.35);color:#fff;box-shadow:0 12px 26px rgba(2,44,34,.14);backdrop-filter:blur(6px)}
				.icon-psy-module-label i{width:8px;height:8px;border-radius:999px;background:rgba(255,255,255,.92);box-shadow:0 0 0 2px rgba(255,255,255,.20)}
				.icon-psy-mod-strength .icon-psy-module-label{background:linear-gradient(135deg,var(--icon-primary),rgba(21,160,109,.82))}
				.icon-psy-mod-solid .icon-psy-module-label{background:linear-gradient(135deg,rgba(20,164,207,.92),rgba(21,160,109,.72))}
				.icon-psy-mod-develop .icon-psy-module-label{background:linear-gradient(135deg,rgba(2,44,34,.72),rgba(20,164,207,.72))}
				@media (max-width:520px){.icon-psy-module-label{top:10px;right:10px;padding:5px 9px;font-size:10px}}
			</style>

			<div class="icon-psy-hero-header">
				<?php if ( ! empty( $logo_url ) ) : ?>
					<div class="icon-psy-hero-logo"><img src="<?php echo esc_url( $logo_url ); ?>" alt=""></div>
				<?php endif; ?>

				<div class="icon-psy-hero-titlewrap">
					<div class="icon-psy-hero-eyebrow">ICON Catalyst • Team insight</div>
					<div class="icon-psy-hero-title">Team Report</div>
					<div class="icon-psy-hero-sub">
						<?php if ( $project_name ) : ?><strong><?php echo esc_html( $project_name ); ?></strong><?php endif; ?>
						<?php if ( $client_name ) : ?> • <?php echo esc_html( $client_name ); ?><?php endif; ?>
					</div>

					<div class="icon-psy-chip-row icon-psy-debrief-hide">
						<?php if ( $project_status ) : ?><span class="icon-psy-chip-muted">Status: <?php echo esc_html( ucfirst( $project_status ) ); ?></span><?php endif; ?>
						<span class="icon-psy-chip">Team responses: <?php echo (int) $num_responses; ?></span>
						<span class="icon-psy-chip-muted">Team members: <?php echo (int) $team_member_count; ?></span>
						<?php if ( $framework_name ) : ?><span class="icon-psy-chip">Framework: <?php echo esc_html( $framework_name ); ?></span><?php endif; ?>
						<?php if ( $public_view ) : ?><span class="icon-psy-chip-muted">Shared view</span><?php endif; ?>
					</div>
				</div>

				<div class="icon-psy-hero-actions">
					<a class="icon-psy-hero-btn" href="#icon-sec-summary">Summary</a>
				</div>
			</div>

			<div class="icon-psy-fac-bar icon-psy-screen-only">
				<div class="icon-psy-fac-left">
					<button type="button" class="icon-psy-btn icon-psy-btn-secondary" id="iconPsyDebriefBtn">Debrief mode</button>
					<span class="icon-psy-pill icon-psy-pill-muted">Tip: click a wheel label to jump</span>
				</div>
				<div style="font-size:11px;color:#6b7280;line-height:1.35;">Facilitator view hides non-essential sections</div>
			</div>

			<div class="icon-psy-report-card icon-psy-debrief-hide" id="icon-sec-intro">
				<div class="icon-psy-section-head">
					<h3 class="icon-psy-section-title">Introduction</h3>
					<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
				</div>
				<p class="icon-psy-section-sub">
					This report summarises team feedback using three lenses for each competency:
					<strong>Everyday</strong> (day-to-day), <strong>Under pressure</strong> (pace, risk, conflict), and
					<strong>Role-modelling</strong> (leadership consistency).
				</p>

				<div class="icon-psy-toc">
					<a href="#icon-sec-summary"><div>Summary and overview</div><span>Jump ↓</span></a>
					<a href="#icon-sec-heatmap"><div>Heatmap</div><span>Jump ↓</span></a>
					<a href="#icon-sec-wheel"><div>Competency wheel</div><span>Jump ↓</span></a>
					<a href="#icon-sec-competency"><div>Competency summaries</div><span>Jump ↓</span></a>
					<a href="#icon-sec-qual"><div>Qualitative themes</div><span>Jump ↓</span></a>
					<a href="#icon-sec-actions"><div>Action plan</div><span>Jump ↓</span></a>
					<a href="#icon-sec-method"><div>Method and notes</div><span>Jump ↓</span></a>
				</div>

				<div style="margin-top:12px;font-size:12px;color:#6b7280;line-height:1.55;">
					Interpretation guidance: treat scores as directional information, then focus on visible, repeatable behaviours the team can practise and review.
				</div>
			</div>

			<div class="icon-psy-report-card icon-psy-debrief-hide" id="icon-sec-summary">
				<div class="icon-psy-section-head">
					<h3 class="icon-psy-section-title">Summary and overview</h3>
					<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
				</div>

				<div class="icon-psy-summary-grid">
					<div class="icon-psy-summary-box">
						<div class="icon-psy-summary-label">Overall team score</div>
						<div class="icon-psy-summary-metric"><?php echo $overall_avg !== null ? number_format( $overall_avg, 1 ) . ' / 7' : '—'; ?></div>
						<div class="icon-psy-summary-tag"><?php echo esc_html( $summary_level ?: 'insight' ); ?></div>
					</div>

					<div class="icon-psy-summary-box">
						<div class="icon-psy-summary-label">Responses</div>
						<div class="icon-psy-summary-metric"><?php echo (int) $num_responses; ?></div>
						<div class="icon-psy-summary-tag"><?php echo (int) $num_raters; ?> raters</div>
					</div>
				</div>

				<p style="margin-top:12px;font-size:13px;color:#374151;line-height:1.55;"><?php echo esc_html( $summary_sentence ); ?></p>

				<?php if ( $top_strengths || $top_devs ) : ?>
					<div class="icon-psy-two-col">
						<div class="icon-psy-pick-card">
							<div class="icon-psy-pick-title">Top strengths</div>
							<?php foreach ( (array) $top_strengths as $r ) : ?>
								<a class="icon-psy-pick-item" href="#icon-comp-<?php echo (int) $r['id']; ?>">
									<div class="icon-psy-pick-name"><?php echo esc_html( $r['name'] ); ?></div>
									<div class="icon-psy-pick-score"><?php echo number_format( (float) $r['overall'], 1 ); ?> / 7</div>
								</a>
							<?php endforeach; ?>
						</div>

						<div class="icon-psy-pick-card">
							<div class="icon-psy-pick-title">Development priorities</div>
							<?php foreach ( (array) $top_devs as $r ) : ?>
								<a class="icon-psy-pick-item" href="#icon-comp-<?php echo (int) $r['id']; ?>">
									<div class="icon-psy-pick-name"><?php echo esc_html( $r['name'] ); ?></div>
									<div class="icon-psy-pick-score"><?php echo number_format( (float) $r['overall'], 1 ); ?> / 7</div>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $focus_list ) : ?>
					<div class="icon-psy-focus-box">
						<div class="icon-psy-focus-title">Fastest uplift</div>
						<?php foreach ( $focus_list as $f ) : ?>
							<a class="icon-psy-focus-item" href="#icon-comp-<?php echo (int) $f['id']; ?>">
								<div>
									<div class="icon-psy-focus-name"><?php echo esc_html( $f['name'] ); ?></div>
									<div class="icon-psy-focus-meta">
										Gap: <?php echo esc_html( $f['_gap_max_k'] ); ?> <?php echo number_format( (float) $f['_gap_max_v'], 1 ); ?> vs
										<?php echo esc_html( $f['_gap_min_k'] ); ?> <?php echo number_format( (float) $f['_gap_min_v'], 1 ); ?>
									</div>
								</div>
								<div class="icon-psy-focus-score"><?php echo number_format( (float) $f['overall'], 1 ); ?> / 7</div>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="icon-psy-report-card icon-psy-debrief-hide" id="icon-sec-heatmap">
				<div class="icon-psy-section-head">
					<h3 class="icon-psy-section-title">Heatmap</h3>
					<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
				</div>

				<div class="icon-psy-heat-table-wrapper">
					<table class="icon-psy-heat-table">
						<thead>
							<tr>
								<th>Competency</th><th>Everyday</th><th>Pressure</th><th>Role-model</th><th>Overall</th>
							</tr>
						</thead>
						<tbody>
							<?php
							$cell = function( $v ) {
								$v = (float) $v;
								$c = ( $v >= 5.5 ) ? 'icon-psy-heat-high' : ( ( $v >= 4.5 ) ? 'icon-psy-heat-mid' : 'icon-psy-heat-low' );
								echo '<span class="icon-psy-heat-cell ' . esc_attr( $c ) . '">' . esc_html( number_format( $v, 1 ) ) . '</span>';
							};
							?>
							<?php foreach ( (array) $heatmap_rows as $r ) : ?>
								<tr>
									<td class="icon-psy-heat-name">
										<a class="icon-psy-heat-link" href="#icon-comp-<?php echo (int) $r['id']; ?>"><?php echo esc_html( $r['name'] ); ?></a>
									</td>
									<td><?php $cell( $r['avg_q1'] ); ?></td>
									<td><?php $cell( $r['avg_q2'] ); ?></td>
									<td><?php $cell( $r['avg_q3'] ); ?></td>
									<td><?php $cell( $r['overall'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="icon-psy-report-card" id="icon-sec-wheel">
				<div class="icon-psy-section-head">
					<h3 class="icon-psy-section-title">Competency wheel</h3>
					<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
				</div>

				<?php $total_comp = count( $heatmap_rows ); $total_sets = (int) ceil( $total_comp / 10 ); ?>

				<?php if ( $total_sets > 1 ) : ?>
					<div class="icon-psy-wheel-switch" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:8px 0 10px;font-size:13px;font-weight:600;color:#374151;">
						<div>
							Showing competencies
							<?php
								$start = $set_index * 10 + 1;
								$end   = min( ( $set_index + 1 ) * 10, $total_comp );
								echo '<strong>' . (int) $start . '–' . (int) $end . '</strong>';
							?>
							<div style="font-size:11px;color:#6b7280;margin-top:2px;">
								To view the remaining competencies, switch between sets.
							</div>
						</div>

						<select onchange="iconWheelSwitchSet(this.value)" style="border:1px solid #e5e7eb;border-radius:8px;padding:4px 8px;font-size:12px;background:#fff;">
							<?php for ( $i = 0; $i < $total_sets; $i++ ) : ?>
								<option value="<?php echo (int) $i; ?>" <?php selected( $i, $set_index ); ?>>Set <?php echo (int) ( $i + 1 ); ?></option>
							<?php endfor; ?>
						</select>
					</div>
				<?php endif; ?>

				<div class="icon-psy-wheel-row">
					<?php $wheel_wrap_class = 'icon-wheel-hide-raters'; ?>
					<div class="icon-psy-wheel-card <?php echo esc_attr( $wheel_wrap_class ); ?>" id="iconWheelWrap">
						<div class="icon-psy-wheel-visual"><?php echo $wheel_svg; ?></div>

						<div class="icon-psy-wheel-under">
							<div style="display:flex;gap:8px;justify-content:center;align-items:center;flex-wrap:wrap;">
								<button class="icon-psy-mini-btn" id="iconToggleRaters" type="button">Show individual raters</button>
								<button class="icon-psy-mini-btn" id="iconShowAllRaters" type="button" style="display:none;">Show all</button>
							</div>

							<div class="icon-psy-legend-key">
								<span><i class="icon-psy-key-swatch icon-psy-key-avg"></i> Team average</span>
								<span><i class="icon-psy-key-swatch icon-psy-key-min"></i> Lowest rater</span>
								<span><i class="icon-psy-key-swatch icon-psy-key-max"></i> Highest rater</span>
							</div>

							<?php if ( $rater_labels ) : ?>
								<div class="icon-psy-rater-list" aria-label="Raters key">
									<?php foreach ( $rater_labels as $rid => $lab ) :
										$rid = (int) $rid;
										$col = isset( $rater_colors[ $rid ] ) ? $rater_colors[ $rid ] : '#64748b';
									?>
										<button type="button" class="icon-psy-rater-pill" data-rid="<?php echo (int) $rid; ?>" aria-pressed="true" title="Toggle this rater">
											<i class="icon-psy-rater-dot" style="background:<?php echo esc_attr( $col ); ?>"></i>
											<?php echo esc_html( $lab ); ?>
										</button>
									<?php endforeach; ?>
								</div>
							<?php else : ?>
								<div style="margin-top:6px;font-size:11px;color:#6b7280;text-align:center;">
									No rater IDs were detected in the results table for this project, so individual rater dots cannot be shown.
								</div>
							<?php endif; ?>
						</div>
					</div>

					<div class="icon-psy-wheel-notes">
						<h4>How to use the wheel</h4>
						<ul>
							<li>Outer edge = stronger behaviour</li>
							<li>Red marker = lowest rater</li>
							<li>Green marker = highest rater</li>
							<li>Dots = individual perceptions (toggle)</li>
						</ul>
					</div>
				</div>
			</div>

			<div class="icon-psy-report-card icon-psy-debrief-hide" id="icon-sec-competency">
				<div class="icon-psy-section-head">
					<h3 class="icon-psy-section-title">Competency summaries</h3>
					<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
				</div>

				<div class="icon-psy-competency-summary-grid">
					<?php foreach ( (array) $heatmap_rows as $r ) : ?>
						<?php
						$sd_band = icon_psy_sd_band( $r['sd'], (int) $r['n'] );
						$ctx = array(
							'competency' => $r['name'],
							'overall'    => (float) $r['overall'],
							'avg_q1'     => (float) $r['avg_q1'],
							'avg_q2'     => (float) $r['avg_q2'],
							'avg_q3'     => (float) $r['avg_q3'],
							'sd'         => $r['sd'],
							'n'          => (int) $r['n'],
						);
						$narr_html = icon_psy_format_engine_text_to_html( icon_psy_team_competency_narrative_fallback( $ctx ) );
						?>
						<div class="icon-psy-competency-summary-card" id="icon-comp-<?php echo (int) $r['id']; ?>">
							<div class="icon-psy-competency-summary-name"><?php echo esc_html( $r['name'] ); ?></div>

							<div class="icon-psy-competency-metrics">
								<span class="icon-psy-competency-pill">Overall: <?php echo esc_html( number_format( (float) $r['overall'], 1 ) ); ?> / 7</span>
								<span class="icon-psy-competency-pill-muted"><?php echo esc_html( $sd_band['label'] ); ?></span>
								<span class="icon-psy-competency-pill-muted">Responses: <?php echo (int) $r['n']; ?></span>
							</div>

							<div class="icon-psy-mini-bars">
								<div class="icon-psy-mini-bar-card">
									<div class="icon-psy-mini-bar-top"><span class="icon-psy-mini-bar-label">Everyday</span><span class="icon-psy-mini-bar-score"><?php echo esc_html( number_format( (float) $r['avg_q1'], 1 ) ); ?></span></div>
									<div class="icon-psy-mini-bar-track"><div class="icon-psy-mini-bar-fill" style="width:<?php echo esc_attr( number_format( (float) $bar_pct( $r['avg_q1'] ), 1 ) ); ?>%;"></div></div>
								</div>

								<div class="icon-psy-mini-bar-card">
									<div class="icon-psy-mini-bar-top"><span class="icon-psy-mini-bar-label">Pressure</span><span class="icon-psy-mini-bar-score"><?php echo esc_html( number_format( (float) $r['avg_q2'], 1 ) ); ?></span></div>
									<div class="icon-psy-mini-bar-track"><div class="icon-psy-mini-bar-fill" style="width:<?php echo esc_attr( number_format( (float) $bar_pct( $r['avg_q2'] ), 1 ) ); ?>%;"></div></div>
								</div>

								<div class="icon-psy-mini-bar-card">
									<div class="icon-psy-mini-bar-top"><span class="icon-psy-mini-bar-label">Role-model</span><span class="icon-psy-mini-bar-score"><?php echo esc_html( number_format( (float) $r['avg_q3'], 1 ) ); ?></span></div>
									<div class="icon-psy-mini-bar-track"><div class="icon-psy-mini-bar-fill" style="width:<?php echo esc_attr( number_format( (float) $bar_pct( $r['avg_q3'] ), 1 ) ); ?>%;"></div></div>
								</div>
							</div>

							<?php if ( ! empty( $r['description'] ) ) : ?>
								<p style="margin:0 0 10px;font-size:12px;color:#6b7280;line-height:1.55;"><?php echo esc_html( icon_psy_safe_trim( $r['description'], 240 ) ); ?></p>
							<?php endif; ?>

							<?php echo $narr_html ? $narr_html : ''; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="icon-psy-report-card icon-psy-debrief-hide" id="icon-sec-qual">
				<div class="icon-psy-section-head">
					<h3 class="icon-psy-section-title">Qualitative themes</h3>
					<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
				</div>

				<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px;">
					<div style="border-radius:14px;border:1px solid #e5e7eb;background:#f9fafb;padding:12px;">
						<h4 style="margin:0 0 6px;font-size:13px;font-weight:800;color:#022c22;">What is working well</h4>
						<?php if ( $strength_points ) : ?>
							<ul style="margin:6px 0 0 18px;color:#374151;font-size:12px;line-height:1.55;">
								<?php foreach ( $strength_points as $p ) echo '<li>' . esc_html( $p ) . '</li>'; ?>
							</ul>
						<?php else : ?>
							<p style="margin:0;font-size:12px;color:#6b7280;">No qualitative “what’s working” text was captured in this dataset.</p>
						<?php endif; ?>
					</div>

					<div style="border-radius:14px;border:1px solid #e5e7eb;background:#f9fafb;padding:12px;">
						<h4 style="margin:0 0 6px;font-size:13px;font-weight:800;color:#022c22;">Improvement opportunities</h4>
						<?php if ( $dev_points ) : ?>
							<ul style="margin:6px 0 0 18px;color:#374151;font-size:12px;line-height:1.55;">
								<?php foreach ( $dev_points as $p ) echo '<li>' . esc_html( $p ) . '</li>'; ?>
							</ul>
						<?php else : ?>
							<p style="margin:0;font-size:12px;color:#6b7280;">No qualitative “improvement” text was captured in this dataset.</p>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="icon-psy-report-card icon-psy-debrief-hide" id="icon-sec-actions">
				<div class="icon-psy-section-head">
					<h3 class="icon-psy-section-title">Action plan</h3>
					<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
				</div>

				<?php if ( $focus_list ) : ?>
					<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:10px;">
						<?php foreach ( (array) $focus_list as $f ) : ?>
							<div style="border-radius:14px;border:1px solid #e5e7eb;background:#fff;padding:12px;">
								<p style="margin:0 0 6px;font-size:12px;font-weight:900;color:#0f766e;"><?php echo esc_html( $f['name'] ); ?></p>
								<p style="margin:0;font-size:12px;color:#374151;line-height:1.55;"><?php echo esc_html( icon_psy_team_next_week_action( $f ) ); ?></p>
							</div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p style="margin:0;font-size:13px;color:#6b7280;">No action items could be generated because competency data is limited.</p>
				<?php endif; ?>
			</div>

			<div class="icon-psy-report-card icon-psy-debrief-hide" id="icon-sec-method">
				<div class="icon-psy-section-head">
					<h3 class="icon-psy-section-title">Method and notes</h3>
					<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
				</div>

				<ul style="margin:8px 0 0 18px;color:#374151;font-size:12px;line-height:1.6;">
					<li>Scores are on a 1–7 scale. Use them as directional insight, not absolute truth.</li>
					<li>“Early signal” labels appear when the number of responses is small.</li>
					<li>Wheel shows: team average (filled polygon + dots), min (red ring), max (green ring). Individual dots can be toggled on screen.</li>
					<li>Click wheel labels to jump to the matching competency summary.</li>
				</ul>
			</div>

			<script>
				function iconWheelSwitchSet(idx){
					sessionStorage.setItem('iconWheelScrollY', String(window.scrollY || 0));
					const url = new URL(window.location.href);
					url.searchParams.set('wheelset', idx);
					url.hash = 'icon-sec-wheel';
					window.location.href = url.toString();
				}

				document.addEventListener('DOMContentLoaded', function(){
					const wrap = document.getElementById('iconWheelWrap');
					const btn  = document.getElementById('iconToggleRaters');

					const saved = sessionStorage.getItem('iconWheelScrollY');
					if (saved){
						sessionStorage.removeItem('iconWheelScrollY');
						window.scrollTo(0, parseInt(saved, 10) || 0);
					}

					const dBtn = document.getElementById('iconPsyDebriefBtn');
					if (dBtn){
						dBtn.addEventListener('click', function(){
							document.body.classList.toggle('icon-psy-debrief');
						});
					}

					const raterPills = document.querySelectorAll('.icon-psy-rater-pill[data-rid]');
					const showAllBtn = document.getElementById('iconShowAllRaters');

					if (wrap && raterPills.length){
						const active = new Set();
						raterPills.forEach(p => active.add(String(p.getAttribute('data-rid'))));

						const setShowState = (isOn) => {
							wrap.classList.toggle('icon-wheel-hide-raters', !isOn);
							if (btn) btn.textContent = isOn ? 'Hide individual raters' : 'Show individual raters';
							if (showAllBtn) showAllBtn.style.display = isOn ? '' : 'none';
						};

						const apply = () => {
							const dots = wrap.querySelectorAll('.icon-wheel-rater-dot[data-rid]');
							dots.forEach(dot => {
								const rid = String(dot.getAttribute('data-rid'));
								dot.style.display = active.has(rid) ? '' : 'none';
							});
							raterPills.forEach(p => {
								const rid = String(p.getAttribute('data-rid'));
								p.setAttribute('aria-pressed', active.has(rid) ? 'true' : 'false');
							});
						};

						if (btn){
							btn.addEventListener('click', function(){
								const nowOn = wrap.classList.contains('icon-wheel-hide-raters');
								setShowState(nowOn);
								if (nowOn) apply();
							});
						}

						if (showAllBtn){
							showAllBtn.addEventListener('click', function(){
								active.clear();
								raterPills.forEach(p => active.add(String(p.getAttribute('data-rid'))));
								setShowState(true);
								apply();
							});
						}

						raterPills.forEach(p => {
							p.addEventListener('click', () => {
								const rid = String(p.getAttribute('data-rid'));
								if (wrap.classList.contains('icon-wheel-hide-raters')) setShowState(true);

								const allOn = (active.size === raterPills.length);
								if (allOn) {
									active.clear();
									active.add(rid);
									apply();
									return;
								}

								if (active.has(rid)) active.delete(rid);
								else active.add(rid);

								if (active.size === 0) raterPills.forEach(pp => active.add(String(pp.getAttribute('data-rid'))));
								apply();
							});
						});

						setShowState(!wrap.classList.contains('icon-wheel-hide-raters'));
					}

					(function(){
						const cards = document.querySelectorAll('.icon-psy-competency-summary-card');
						if (!cards || !cards.length) return;

						const pickBand = (score) => {
							if (score >= 5.6) return { key:'strength', label:'Strength' };
							if (score >= 4.6) return { key:'solid', label:'Solid' };
							return { key:'develop', label:'Develop' };
						};

						cards.forEach(card => {
							const pill = card.querySelector('.icon-psy-competency-pill');
							if (!pill) return;

							const m = (pill.textContent || '').trim().match(/Overall:\s*([0-9]+(?:\.[0-9]+)?)/i);
							if (!m) return;

							const score = parseFloat(m[1]);
							if (Number.isNaN(score)) return;

							const band = pickBand(score);
							card.classList.remove('icon-psy-mod-strength','icon-psy-mod-solid','icon-psy-mod-develop');
							card.classList.add('icon-psy-mod-' + band.key);

							let el = card.querySelector('.icon-psy-module-label');
							if (!el){
								el = document.createElement('div');
								el.className = 'icon-psy-module-label';
								el.innerHTML = '<i></i>' + band.label;
								card.appendChild(el);
							} else {
								el.innerHTML = '<i></i>' + band.label;
							}
						});
					})();
				});
			</script>

		</div>
		<?php
		return ob_get_clean();
	}
}

add_shortcode( 'icon_psy_team_report', 'icon_psy_team_report' );
