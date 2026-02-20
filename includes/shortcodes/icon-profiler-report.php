<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }



if ( ! function_exists( 'icon_profiler_report_render' ) ) {



	function icon_profiler_report_render( $atts ) {

		global $wpdb;



		$is_pdf      = false;



		// Share mode = not logged in, and ANY share param is present AND non-empty

		$share_keys = array( 'icon_share', 'icon_psy_share', 'share', 'token' );

		$is_share = false;



		if ( ! is_user_logged_in() ) {

			foreach ( $share_keys as $k ) {

				if ( array_key_exists( $k, $_GET ) ) {

					$val = is_array($_GET[$k]) ? '' : trim( (string) wp_unslash( $_GET[$k] ) );

					// Allow key-only flags like ?icon_share (no value) OR explicit values

					if ( $val !== '' || $_GET[$k] === '' ) {

						$is_share = true;

						break;

					}

				}

			}

		}



		if ( ! defined( 'M_PI' ) ) { define( 'M_PI', 3.14159265358979323846 ); }



		// ── Helpers ──

		if ( ! function_exists( 'icon_psy_is_completed_status' ) ) {

			function icon_psy_is_completed_status( $status ) {

				return in_array( strtolower( trim( (string) $status ) ), array( 'completed','complete','submitted','done','finished' ), true );

			}

		}

		if ( ! function_exists( 'icon_psy_user_has_role' ) ) {

			function icon_psy_user_has_role( $user, $role ) {

				return $user && isset( $user->roles ) && is_array( $user->roles ) && in_array( $role, $user->roles, true );

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

					$legacy = (int) get_user_meta( $uid, 'icon_psy_impersonate_client_id', true );

					if ( $legacy > 0 ) return $legacy;

				}

				return 0;

			}

		}

		if ( ! function_exists( 'icon_psy_table_exists' ) ) {

			function icon_psy_table_exists( $table ) {

				global $wpdb;

				$res = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table ) ) );

				return ! empty( $res );

			}

		}

		if ( ! function_exists( 'icon_psy_get_table_columns_lower' ) ) {

			function icon_psy_get_table_columns_lower( $table ) {

				global $wpdb;

				$cols = $wpdb->get_col( "DESCRIBE {$table}", 0 );

				return is_array( $cols ) ? array_map( 'strtolower', $cols ) : array();

			}

		}

		if ( ! function_exists( 'icon_psy_pick_col' ) ) {

			function icon_psy_pick_col( $cols_lower, $candidates ) {

				foreach ( (array) $candidates as $c ) {

					if ( in_array( strtolower( $c ), $cols_lower, true ) ) return $c;

				}

				return '';

			}

		}

		if ( ! function_exists( 'icon_psy_detect_results_schema' ) ) {

			function icon_psy_detect_results_schema() {

				global $wpdb;

				$p = $wpdb->prefix;

				$candidates = array(

					$p.'icon_assessment_results', $p.'icon_psy_assessment_results',

					$p.'icon_psy_results', $p.'icon_psy_assessment_result', $p.'icon_assessment_result',

				);

				$table = ''; $cols = array();

				foreach ( $candidates as $t ) {

					if ( icon_psy_table_exists( $t ) ) { $table = $t; $cols = icon_psy_get_table_columns_lower( $t ); if ( ! empty( $cols ) ) break; }

				}

				if ( ! $table ) return array( 'table'=>'','cols'=>array(),'map'=>array(),'order'=>'' );

				$map = array(

					'participant_id' => icon_psy_pick_col( $cols, array('participant_id','participant','user_id','assessment_user_id') ),

					'rater_id'       => icon_psy_pick_col( $cols, array('rater_id','reviewer_id') ),

					'status'         => icon_psy_pick_col( $cols, array('status','state','completion_status') ),

					'completed_at'   => icon_psy_pick_col( $cols, array('completed_at','submitted_at','created_at') ),

					'q1_rating'      => icon_psy_pick_col( $cols, array('q1_rating','overall_rating','overall','rating','score') ),

					'q2_text'        => icon_psy_pick_col( $cols, array('q2_text','strengths_text','strengths') ),

					'q3_text'        => icon_psy_pick_col( $cols, array('q3_text','development_text','development') ),

					'detail_json'    => icon_psy_pick_col( $cols, array('detail_json','responses_json','answers_json','payload_json') ),

				);

				$order = icon_psy_pick_col( $cols, array('created_at','submitted_at','completed_at','id') );

				return array( 'table'=>$table,'cols'=>$cols,'map'=>$map,'order'=>$order );

			}

		}

		if ( ! function_exists( 'icon_profiler_row_is_completed_detected' ) ) {

			function icon_profiler_row_is_completed_detected( $row ) {

				if ( ! is_object( $row ) ) return false;

				if ( isset( $row->status ) && $row->status !== '' && in_array( strtolower( trim( (string) $row->status ) ), array('completed','complete','submitted','done','finished'), true ) ) return true;

				if ( isset( $row->completed_at ) && ! empty( $row->completed_at ) ) return true;

				if ( isset( $row->q1_rating ) && $row->q1_rating !== null && $row->q1_rating !== '' ) return true;

				if ( isset( $row->detail_json ) && ! empty( $row->detail_json ) ) return true;

				return false;

			}

		}



		$share = isset($GLOBALS['icon_psy_public_share']) && is_array($GLOBALS['icon_psy_public_share'])

		? $GLOBALS['icon_psy_public_share']

		: null;



		if ( $share ) {

		// We have a validated share token. Treat as authorised for viewing this report.

		// IMPORTANT: still render only the report defined by the share payload.

		$_GET['project_id']     = (int) ($share['project_id'] ?? 0);

		$_GET['participant_id'] = (int) ($share['participant_id'] ?? 0);

		$_GET['report_type']    = sanitize_key((string) ($share['report_type'] ?? ''));

		}



		// ------------------------------------------------------------

		// Access gate:

		// - If share token validated, allow view without normal login/role checks

		// - Otherwise require logged in client/admin

		// ------------------------------------------------------------

		if ( ! $share ) {

			if ( ! is_user_logged_in() ) {

				return '<p>You must be logged in to view this report.</p>';

			}



			$effective_client_id = (int) icon_psy_get_effective_client_user_id();

			if ( $effective_client_id <= 0 ) {

				return '<p>You do not have permission to view this report.</p>';

			}

		}



		// ── Inputs + auth ──

		$atts           = shortcode_atts( array('participant_id'=>0), $atts, 'icon_profiler_report' );

		$participant_id = (int) $atts['participant_id'];

		if ( ! $participant_id && isset( $_GET['participant_id'] ) ) $participant_id = (int) $_GET['participant_id'];

		if ( $participant_id <= 0 ) return '<p>Missing participant_id.</p>';



		$is_admin            = current_user_can( 'manage_options' );

		$effective_client_id = (int) icon_psy_get_effective_client_user_id();



		$participants_table = $wpdb->prefix . 'icon_psy_participants';

		$projects_table     = $wpdb->prefix . 'icon_psy_projects';

		$competencies_table = $wpdb->prefix . 'icon_psy_framework_competencies';

		$raters_table       = $wpdb->prefix . 'icon_psy_raters';



		$participant = $wpdb->get_row( $wpdb->prepare(

			"SELECT p.*, pr.id AS project_id, pr.name AS project_name, pr.client_name AS client_name,

			 pr.status AS project_status, pr.client_user_id AS client_user_id, pr.framework_id AS framework_id

			 FROM {$participants_table} p LEFT JOIN {$projects_table} pr ON p.project_id=pr.id WHERE p.id=%d LIMIT 1",

			$participant_id

		) );

		if ( ! $participant ) return '<p>Participant not found.</p>';



		if ( ! $is_admin && ! $is_share ) {

			if ( $effective_client_id <= 0 ) return '<p>You do not have permission to view this report.</p>';

			$owner_id = isset( $participant->client_user_id ) ? (int) $participant->client_user_id : 0;

			if ( $owner_id <= 0 || $owner_id !== $effective_client_id ) return '<p>You do not have permission to view this report.</p>';

		}



		$participant_name = $participant->name ?: 'Participant';

		$participant_role = $participant->role ?: '';

		$project_name     = $participant->project_name ?: '';

		$client_name      = $participant->client_name ?: '';

		$project_status   = $participant->project_status ?: '';

		$framework_id     = isset( $participant->framework_id ) ? (int) $participant->framework_id : 0;



		// ── Results fetch ──

		$schema        = icon_psy_detect_results_schema();

		$results_table = $schema['table'];

		$map           = $schema['map'];

		$order_col     = $schema['order'];



		$debug_html = '';

		if ( $is_admin && ! $is_pdf ) {

			$debug_html = '<div style="margin:10px 0;padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#f9fafb;color:#374151;font-size:12px;">'

				. '<strong>Icon Profiler debug</strong><br>Participant ID: '.(int)$participant_id.'<br>'

				. 'Results table: <code>'.esc_html($results_table).'</code><br>'

				. 'Framework ID: <code>'.esc_html((string)$framework_id).'</code><br></div>';

		}

		if ( ! $results_table || empty( $map['participant_id'] ) ) return $debug_html . '<p>Results table/participant key could not be detected.</p>';



		$join_raters = ( ! empty( $map['rater_id'] ) && icon_psy_table_exists( $raters_table ) );

		$order_sql   = ! empty( $order_col ) ? "ORDER BY r.`".esc_sql($order_col)."` ASC" : "ORDER BY r.id ASC";

		$sql = "SELECT r.*" . ( $join_raters ? ", rt.relationship AS rater_relationship" : "" )

			. " FROM {$results_table} r "

			. ( $join_raters ? "LEFT JOIN {$raters_table} rt ON r.`".esc_sql($map['rater_id'])."` = rt.id" : "" )

			. " WHERE r.`".esc_sql($map['participant_id'])."` = %d {$order_sql}";



		$raw_results = $wpdb->get_results( $wpdb->prepare( $sql, $participant_id ) );

		$results = array();

		foreach ( (array) $raw_results as $r ) {

			$obj = new stdClass();

			foreach ( (array) $r as $k => $v ) { $obj->$k = $v; }

			foreach ( array('q1_rating','q2_text','q3_text','detail_json','status','completed_at') as $f ) {

				if ( ! empty( $map[$f] ) && isset( $r->{ $map[$f] } ) ) $obj->$f = $r->{ $map[$f] };

			}

			if ( icon_profiler_row_is_completed_detected( $obj ) ) $results[] = $obj;

		}

		if ( empty( $results ) ) return $debug_html . '<p>No completed rows found for this participant yet.</p>';



		// ── Aggregates ──

		$heat_agg = array(); $rater_ids = array(); $strengths = array(); $dev_opps = array();

		$overall_sum = 0; $overall_count = 0;

		foreach ( $results as $row ) {

			if ( isset( $row->rater_id ) && $row->rater_id ) $rater_ids[(int)$row->rater_id] = true;

			if ( ! empty( $row->q2_text ) ) $strengths[] = (string) $row->q2_text;

			if ( ! empty( $row->q3_text ) ) $dev_opps[]  = (string) $row->q3_text;

			if ( isset( $row->q1_rating ) && $row->q1_rating !== null && $row->q1_rating !== '' ) {

				$overall_sum += (float) $row->q1_rating; $overall_count++;

			}

			if ( ! empty( $row->detail_json ) ) {

				$detail = json_decode( $row->detail_json, true );

				if ( is_array( $detail ) ) {

					foreach ( $detail as $entry ) {

						if ( empty( $entry['competency_id'] ) ) continue;

						$cid = (int) $entry['competency_id'];

						$q1  = isset($entry['q1']) ? (float)$entry['q1'] : null;

						$q2  = isset($entry['q2']) ? (float)$entry['q2'] : null;

						$q3  = isset($entry['q3']) ? (float)$entry['q3'] : null;

						if ( $q1===null || $q2===null || $q3===null ) continue;

						if ( ! isset( $heat_agg[$cid] ) ) $heat_agg[$cid] = array('sum_q1'=>0,'sum_q2'=>0,'sum_q3'=>0,'count'=>0);

						$heat_agg[$cid]['sum_q1']+=$q1; $heat_agg[$cid]['sum_q2']+=$q2;

						$heat_agg[$cid]['sum_q3']+=$q3; $heat_agg[$cid]['count']+=1;

					}

				}

			}

		}

		$num_raters  = count( $rater_ids );

		$overall_avg = $overall_count ? ( $overall_sum / $overall_count ) : null;



		// ── Competencies ──

		$competency_ids = array_values( array_filter( array_map('intval', array_keys($heat_agg)) ) );

		if ( empty( $competency_ids ) ) return $debug_html . '<p>Competency data not available yet (detail_json is empty).</p>';



		$cols_lower      = icon_psy_get_table_columns_lower( $competencies_table );

		$has_desc        = in_array( 'description', $cols_lower, true );

		$has_framework_c = in_array( 'framework_id', $cols_lower, true );

		$comp_order_col  = icon_psy_pick_col( $cols_lower, array('sort_order','display_order','order_index','position','sequence','sort') );

		$select_bits     = array('id','name');

		if ( $has_desc ) $select_bits[] = 'description';

		if ( $comp_order_col ) $select_bits[] = $comp_order_col;

		$order_by = $comp_order_col ? "ORDER BY `".esc_sql($comp_order_col)."` ASC, id ASC" : "ORDER BY id ASC";



		$build_heatmap_row = function( $comp, $agg ) use ( $has_desc ) {

			$count = max(1,(int)$agg['count']);

			$avg_q1 = $agg['sum_q1']/$count; $avg_q2 = $agg['sum_q2']/$count; $avg_q3 = $agg['sum_q3']/$count;

			return array(

				'id'=>(int)$comp->id, 'name'=>(string)$comp->name,

				'description'=>($has_desc && isset($comp->description)) ? (string)$comp->description : '',

				'avg_q1'=>$avg_q1, 'avg_q2'=>$avg_q2, 'avg_q3'=>$avg_q3,

				'overall'=>($avg_q1+$avg_q2+$avg_q3)/3,

			);

		};



		$heatmap_rows = array();

		if ( $framework_id > 0 && $has_framework_c ) {

			$comps = $wpdb->get_results( $wpdb->prepare(

				"SELECT ".implode(',',$select_bits)." FROM {$competencies_table} WHERE framework_id=%d {$order_by}", $framework_id

			) );

			foreach ( (array) $comps as $comp ) {

				$cid = (int) $comp->id;

				if ( ! empty( $heat_agg[$cid] ) ) $heatmap_rows[] = $build_heatmap_row( $comp, $heat_agg[$cid] );

			}

		}

		if ( empty( $heatmap_rows ) ) {

			$placeholders = implode(',', array_fill(0,count($competency_ids),'%d'));

			$args = array_merge( array("SELECT ".implode(',',$select_bits)." FROM {$competencies_table} WHERE id IN ($placeholders) {$order_by}"), $competency_ids );

			$comps = $wpdb->get_results( call_user_func_array( array($wpdb,'prepare'), $args ) );

			foreach ( (array) $comps as $comp ) {

				$cid = (int) $comp->id;

				if ( ! empty( $heat_agg[$cid] ) ) $heatmap_rows[] = $build_heatmap_row( $comp, $heat_agg[$cid] );

			}

		}

		if ( empty( $heatmap_rows ) ) return $debug_html . '<p>Competency data not available yet.</p>';



		// ── Style averages ──

		$ordered = count($heatmap_rows) > 12 ? array_slice($heatmap_rows,0,12) : $heatmap_rows;

		$types = array(

			'DRIVER'    => array('label'=>'Driver',    'sum'=>0,'n'=>0,'avg'=>null),

			'SUPPORTER' => array('label'=>'Supporter', 'sum'=>0,'n'=>0,'avg'=>null),

			'THINKER'   => array('label'=>'Thinker',   'sum'=>0,'n'=>0,'avg'=>null),

			'CONNECTOR' => array('label'=>'Connector', 'sum'=>0,'n'=>0,'avg'=>null),

		);

		$band_map = array(0=>'DRIVER',1=>'SUPPORTER',2=>'THINKER',3=>'CONNECTOR');

		foreach ( $ordered as $i => $row ) {

			$k = $band_map[(int)floor(($i)/3)] ?? 'CONNECTOR';

			$types[$k]['sum'] += (float)$row['overall']; $types[$k]['n']++;

		}

		foreach ( $types as $k => $v ) $types[$k]['avg'] = $v['n'] ? ($v['sum']/$v['n']) : null;



		$ranked = array();

		foreach ( $types as $k => $v ) if ($v['avg']!==null) $ranked[] = array('key'=>$k,'label'=>$v['label'],'avg'=>$v['avg']);

		usort($ranked,function($a,$b){ return $a['avg']===$b['avg'] ? 0 : ($a['avg']>$b['avg'] ? -1 : 1); });



		$primary       = isset($ranked[0]) ? $ranked[0] : null;

		$secondary     = (isset($ranked[1],$ranked[0]) && ((float)$ranked[0]['avg']-(float)$ranked[1]['avg']) <= 0.35) ? $ranked[1] : null;

		$dominant_key  = $primary ? (string)$primary['key'] : 'DRIVER';

		$secondary_key = $secondary ? (string)$secondary['key'] : '';



		$avgD = (float)($types['DRIVER']['avg']    ?? 0.0);

		$avgC = (float)($types['CONNECTOR']['avg'] ?? 0.0);

		$avgS = (float)($types['SUPPORTER']['avg'] ?? 0.0);

		$avgT = (float)($types['THINKER']['avg']   ?? 0.0);



		// ── Style knowledge ──

		$style_knowledge = array(

			'DRIVER' => array(

				'headline'          => 'Decisive, outcomes-first leadership',

				'core'              => 'Drivers lead with clarity, urgency, and a strong results focus. You tend to simplify complexity into a decision and a direction, then mobilise action quickly.',

				'so_what'           => 'Your greatest impact comes when your decisiveness is paired with buy-in. When you add one moment of inclusion (a quick check-in), people follow faster.',

				'strengths'         => array('Moves from discussion to decision quickly','Sets clear standards and holds accountability','Comfortable with risk, ambiguity, and tough calls','Gives direction when others feel stuck'),

				'watch_outs'        => array('Can sound abrupt or impatient when under time pressure','May under-communicate context (the "why")','Can solve problems for people instead of building ownership','May overlook emotional impact in the pursuit of speed'),

				'pressure'          => 'Under pressure, Drivers often increase pace and directness. This can be extremely effective in crisis, but can reduce psychological safety if not balanced with tone.',

				'comms_do'          => array('Name the decision and the reason in one sentence','Ask one alignment question: "Any critical risk I\'m missing?"','Confirm owner + deadline before closing'),

				'comms_dont'        => array('Stack multiple decisions without pause','Use only "do it" language with no context','Dismiss concerns without acknowledging them'),

				'best_env'          => array('Turnarounds, delivery under tight timeframes, crisis response','Performance improvement, operational execution'),

				'development_moves' => array('Add 10% more context: "Here\'s why this matters."','Use "challenge + care" phrasing: "I\'m pushing the standard because I believe you can meet it."','Delegate the "how" more often than you delegate the "what".'),

				'how_to_work_with'  => array('Bring options, not problems (2 choices + your recommendation).','Be concise: headline first, details second.','Agree a decision point and a timebox in advance.','If you need more time, be explicit: "I need 24 hours to validate."'),

			),

			'CONNECTOR' => array(

				'headline'          => 'Influence through relationships and energy',

				'core'              => 'Connectors lead by building momentum through people. You bring optimism, engagement, and social intelligence that helps others feel involved and motivated.',

				'so_what'           => 'Your influence becomes even stronger when you add structure. Clear priorities and follow-through convert energy into consistent delivery.',

				'strengths'         => array('Builds rapport quickly and creates engagement','Influences stakeholders and strengthens collaboration','Sees opportunities and creates momentum','Good at alignment across teams and functions'),

				'watch_outs'        => array('May avoid tension or delay hard conversations','Can spread focus too widely when excited by options','May rely on verbal agreement without locking actions','Can be experienced as inconsistent if follow-through slips'),

				'pressure'          => 'Under pressure, Connectors can either over-communicate or go quiet if the atmosphere turns tense. The key is calm structure: priorities, roles, and next steps.',

				'comms_do'          => array('Summarise agreements in writing (1–3 bullet actions)','Name the hard thing respectfully, early','Use stakeholder mapping before big changes'),

				'comms_dont'        => array('Rely on "everyone seems aligned" without a clear close','Keep adding new ideas when execution is the priority','Over-promise to protect relationships'),

				'best_env'          => array('Change engagement, stakeholder management, cross-team collaboration','Culture building, customer-facing leadership'),

				'development_moves' => array('Protect focus: choose the top 2 priorities for the week.','Create follow-through rituals: weekly actions review.','Practice "warm directness" in difficult conversations.'),

				'how_to_work_with'  => array('Start with people and purpose, then move to the plan.','Invite them early to build ownership and engagement.','Close with actions: who, what, when.','If you need a decision, ask directly for it.'),

			),

			'SUPPORTER' => array(

				'headline'          => 'Stability, care, and dependable delivery',

				'core'              => 'Supporters lead through trust, consistency, and care. You create safety and cohesion, and you\'re often the person who ensures the team actually sustains performance.',

				'so_what'           => 'Your biggest lift comes from being slightly more direct in moments that need it. Clear standards can be delivered kindly.',

				'strengths'         => array('Builds loyalty and team stability','Listens well and notices impact on people','Creates calm under pressure','Strong follow-through and reliability'),

				'watch_outs'        => array('May hesitate to challenge underperformance','Can defer decisions to avoid conflict','May take on too much to protect others','Can be under-recognised because the work is "quietly excellent"'),

				'pressure'          => 'Under pressure, Supporters may absorb stress privately and keep going. That can sustain the team, but it can also hide risks until late.',

				'comms_do'          => array('Name standards clearly: "For success we need X."','Use calm firmness: "I need this by Friday."','Escalate earlier when capacity is stretched'),

				'comms_dont'        => array('Hint instead of stating what is needed','Carry the load silently','Delay feedback until it becomes emotional'),

				'best_env'          => array('Team leadership, service delivery, operational consistency','People development, retention, onboarding'),

				'development_moves' => array('Use one direct feedback sentence weekly.','Protect boundaries: stop taking on "rescues".','Ask for clarity when direction is vague.'),

				'how_to_work_with'  => array('Be respectful and steady; avoid sudden last-minute demands.','Provide context and timelines early.','Acknowledge their contribution and stability.','Invite their view: they often see risks others miss.'),

			),

			'THINKER' => array(

				'headline'          => 'Analytical, structured, high-standard leadership',

				'core'              => 'Thinkers lead with logic, rigour, and quality. You strengthen decisions by testing assumptions, clarifying criteria, and improving systems and processes.',

				'so_what'           => 'Your influence increases when you make your thinking visible in simpler language. "Here\'s the logic" helps others trust your conclusions.',

				'strengths'         => array('Thinks clearly under complexity','Improves quality, risk management, and decision criteria','Asks good questions and strengthens governance','Brings structure and systems thinking'),

				'watch_outs'        => array('May over-analyse when speed is needed','Can sound critical if tone is too sharp','May prefer "being right" over "being aligned"','Can withhold judgement too long, frustrating fast movers'),

				'pressure'          => 'Under pressure, Thinkers can tighten standards and become more critical. This can improve quality, but can also reduce confidence if feedback lacks balance.',

				'comms_do'          => array('Lead with a headline, then reasoning','State criteria up front: "We\'ll decide based on X."','Pair critique with a path forward'),

				'comms_dont'        => array('Drop large analysis without a recommendation','Correct people publicly when trust is low','Delay decisions without setting a decision time'),

				'best_env'          => array('Strategy, risk, governance, technical leadership','Process improvement, performance analysis'),

				'development_moves' => array('Timebox analysis: decide what "good enough" looks like.','Add warmth before challenge: acknowledge effort first.','Offer a clear recommendation more often.'),

				'how_to_work_with'  => array('Bring data, logic, and clear assumptions.','Ask for criteria and definitions early.','Give them time to think, then agree a decision point.','If you disagree, discuss the criteria, not the person.'),

			),

		);



		// ── DISC overlay (local-only; no DB changes required) ──

		$icon_to_disc = array(

			'DRIVER'    => 'D',

			'CONNECTOR' => 'I',

			'SUPPORTER' => 'S',

			'THINKER'   => 'C',

		);



		$disc_knowledge = array(

			'IC' => array(

				'label'    => 'Assessor',

				'headline' => 'Engaging, persuasive, and quality-focused',

				'core'     => 'You combine relationship-led influence with a strong need for accuracy and credibility. You tend to build buy-in through energy and connection, while also checking detail so outcomes are polished and trusted.',

				'strengths' => array(

					'Builds rapport quickly while keeping standards high',

					'Balances people impact with quality and risk awareness',

					'Communicates with confidence when prepared',

					'Creates momentum without losing credibility',

				),

				'watch_outs' => array(

					'Can over-check detail when speed is needed',

					'May sound critical if standards rise under stress',

					'Can hesitate if information feels incomplete',

					'May avoid tension to protect relationships',

				),

				'comms_do' => array(

					'Lead with the headline, then the rationale',

					'Timebox analysis: agree what “good enough” looks like',

					'Close meetings with actions in writing (who/what/when)',

					'Pair critique with a path forward',

				),

				'how_to_work_with' => array(

					'Bring context and the “why” early',

					'Share options, then invite a recommendation',

					'Confirm next steps clearly (owner + deadline)',

					'Keep tone warm when challenging standards',

				),

			),

			'DI' => array(

				'label'    => 'Persuader',

				'headline' => 'Fast-moving, confident, and persuasive',

				'core'     => 'You combine urgency and influence. You tend to push for action, gain alignment quickly, and keep momentum high when others get stuck.',

				'strengths' => array('Moves people to decisions','Confident under pressure','Influences stakeholders','Keeps urgency high'),

				'watch_outs' => array('Can rush detail','May dominate discussions','May under-communicate the why','Can sound blunt'),

				'comms_do' => array('Name the decision and reason','Ask for risks you may be missing','Confirm owner + deadline','Keep it concise'),

				'how_to_work_with' => array('Bring options not problems','Be brief then detailed','Agree timeboxes','Be direct with risks'),

			),

			'SC' => array(

				'label'    => 'Practitioner',

				'headline' => 'Steady, supportive, and careful',

				'core'     => 'You combine stability and people-care with careful thinking and quality. You build trust through consistency and thoroughness.',

				'strengths' => array('Reliable follow-through','Calm presence','Strong quality focus','Good listener'),

				'watch_outs' => array('May delay decisions','Can over-carry others','May avoid conflict','Can over-check detail'),

				'comms_do' => array('Clarify expectations early','Name standards kindly','Escalate capacity risks sooner','Put decisions in writing'),

				'how_to_work_with' => array('Give time to process','Share context early','Avoid last-minute changes','Be respectful and steady'),

			),

		);



		$disc_letter_library = array(

			'D' => array(

				'needs' => array('Autonomy','Fast decisions','Clear outcomes','Authority to act'),

				'stress_triggers' => array('Delays','Loss of control','Over-analysis','Inefficiency'),

				'under_pressure' => 'Becomes blunt, impatient, pushes decisions faster',

				'best_response' => 'Be brief, focus on outcomes, offer options',

				'avoid_response' => 'Do not ramble or remove ownership',

				'communication' => 'Start with the result, then details if needed',

				'conflict' => 'Direct and fast — wants resolution quickly',

				'leadership' => 'Drives pace, sets direction, pushes accountability',

			),

			'I' => array(

				'needs' => array('Recognition','Collaboration','Engagement','Positive feedback'),

				'stress_triggers' => array('Isolation','Rejection','Silence','Overly formal environments'),

				'under_pressure' => 'Talks more, becomes emotional, seeks reassurance',

				'best_response' => 'Stay warm, acknowledge ideas, keep interaction human',

				'avoid_response' => 'Avoid cold or purely written criticism',

				'communication' => 'Start friendly, then focus conversation',

				'conflict' => 'Avoids tension first, then becomes expressive',

				'leadership' => 'Motivates people, builds energy and buy-in',

			),

			'S' => array(

				'needs' => array('Stability','Time to adjust','Clarity','Predictability'),

				'stress_triggers' => array('Sudden change','Pressure','Conflict','Unclear expectations'),

				'under_pressure' => 'Withdraws, agrees outwardly, resists internally',

				'best_response' => 'Slow down, reassure, explain impact',

				'avoid_response' => 'Do not force immediate decisions',

				'communication' => 'Calm, structured, step-by-step',

				'conflict' => 'Avoids conflict, needs safety first',

				'leadership' => 'Supports people, builds trust and reliability',

			),

			'C' => array(

				'needs' => array('Accuracy','Logic','Standards','Clear expectations'),

				'stress_triggers' => array('Ambiguity','Poor quality','Emotional arguments','Rushed decisions'),

				'under_pressure' => 'Becomes critical, withdrawn, over-checks',

				'best_response' => 'Provide data, structure, reasoning',

				'avoid_response' => 'Do not pressure for instant answers',

				'communication' => 'Structured and precise',

				'conflict' => 'Debates facts not feelings',

				'leadership' => 'Improves quality, reduces risk',

			),

		);



		// User-facing labels (no letters shown to participants)

		$style_labels = array(

			'D' => 'Driving',

			'I' => 'Connecting',

			'S' => 'Supporting',

			'C' => 'Thinking',

		);



		// Helper: Convert a DISC code (e.g., "CS") to human words (e.g., "Thinking + Supporting")

		$disc_code_to_words = function( $code ) use ( $style_labels ) {

			$code = strtoupper( preg_replace( '/[^A-Z]/', '', (string) $code ) );

			if ( $code === '' ) return array();

			$out = array();

			foreach ( str_split( $code ) as $ch ) {

				if ( isset( $style_labels[ $ch ] ) ) $out[] = $style_labels[ $ch ];

			}

			return array_values( array_unique( array_filter( $out ) ) );

		};



		// ── Images ──

		$logo_url = 'https://icon-talent.org/wp-content/uploads/2025/05/ICON-Assessment.png';



		// NEW: Rotating wheel GIF (screen + PDF first frame)

		$wheel_url = 'https://icon-talent.org/wp-content/uploads/2026/02/icon_disc_wheel_rotating.gif';



		$style_icon_urls = array(

			'THINKER'   => 'https://icon-talent.org/wp-content/uploads/2025/05/20250526_1738_Thinker-Icon-Design_remix_01jw6q9ah9f8evk4fp4qpe1jv9.png',

			'SUPPORTER' => 'https://icon-talent.org/wp-content/uploads/2025/05/20250526_1736_Supporter-Icon-Design_remix_01jw6q75tbfh1s58z2hcj6zmyz.png',

			'CONNECTOR' => 'https://icon-talent.org/wp-content/uploads/2025/05/20250526_1733_Connector-Icon_remix_01jw6q1c1cev0s3hsc2bn517r8.png',

			'DRIVER'    => 'https://icon-talent.org/wp-content/uploads/2025/05/20250526_1719_Four-Personality-Types_remix_01jw6p80vvftdr0fpsa98zzbkq.png',

		);



		$style_icon_src = array();

		foreach ( $style_icon_urls as $k => $u ) $style_icon_src[$k] = icon_profiler_img_src($u,$is_pdf);



		// NEW: ensure logo/wheel render correctly in both modes

		$brand_logo_src = icon_profiler_img_src( $logo_url, $is_pdf );

		$wheel_src      = icon_profiler_img_src( $wheel_url, $is_pdf );



		// ── Utilities ──

		$score_to_pct = function($score){ if($score===null) return 0; return (int)round((max(0,min(7,(float)$score))/7)*100); };

		$first_name   = trim((string)preg_replace('/\s+.*/','',(string)$participant_name)) ?: 'You';



		// ── Software tier (Premium) ──

		// If later you store tier on project/participant, you can replace this with a DB field.

		$software_tier = 'Premium';



		// ── Culture lens ──

		$pace_axis_raw      = (($avgD+$avgC)/2)-(($avgS+$avgT)/2);

		$challenge_axis_raw = (($avgD+$avgT)/2)-(($avgC+$avgS)/2);

		$icon_to_pct        = function($v){ $norm=($v+3)/6; return (int)round(100*max(0,min(1,$norm))); };

		$pace_pct      = $icon_to_pct($pace_axis_raw);

		$challenge_pct = $icon_to_pct($challenge_axis_raw);

		$pace_label      = $pace_axis_raw>=0.35      ? 'Fast-paced and outspoken'     : ($pace_axis_raw<=-0.35      ? 'Steady and reflective'    : 'Balanced pace');

		$challenge_label = $challenge_axis_raw>=0.35 ? 'Questioning and challenging'  : ($challenge_axis_raw<=-0.35 ? 'Warm and accepting'        : 'Balanced stance');



		$pace_detail = array(

			'Pace is the speed of your decision-making and how quickly your communication pushes toward action.',

			'High pace signals urgency, confidence, and decisiveness. If others need time to process, it can be misread as rushing.',

			'Low pace signals calm, thoroughness, and stability. In fast-moving contexts, it can be misread as hesitation.',

			'Best practice: match pace to risk. High-risk decisions need a deliberate pause. Low-risk decisions can move faster.',

		);

		$pace_watch = array(

			'Under pressure: pace usually increases. Watch for skipped context and missed questions.',

			'In mixed groups: use a short timebox plus a clear close to keep fast and steady people aligned.',

			'If you are naturally fast: add one pause question ("What am I missing?").',

			'If you are naturally steady: add one momentum move ("Here is the next step and who owns it.").',

		);

		$challenge_detail = array(

			'Challenge is how strongly you test ideas, question assumptions, and hold standards.',

			'High challenge signals rigour and accountability. Without warmth, it can feel critical.',

			'Low challenge signals safety and acceptance. If standards are slipping, it can feel like low urgency.',

			'Best practice: pair challenge with safety. Make it clear you are challenging the idea, not the person.',

		);

		$challenge_watch = array(

			'Under pressure: challenge can become sharper. Watch tone, timing, and how directly you name issues.',

			'In low-trust groups: raise safety before you raise challenge.',

			'If you are naturally challenging: add acknowledgement before your question ("I can see the work here…").',

			'If you are naturally accepting: add a standard statement ("For success, we need X to be true.").',

		);



		// ── Pressure shift ──

		$style_lenses = array(

			'DRIVER'=>array('q1_sum'=>0,'q2_sum'=>0,'q3_sum'=>0,'n'=>0,'q1'=>null,'q2'=>null,'q3'=>null),

			'SUPPORTER'=>array('q1_sum'=>0,'q2_sum'=>0,'q3_sum'=>0,'n'=>0,'q1'=>null,'q2'=>null,'q3'=>null),

			'THINKER'=>array('q1_sum'=>0,'q2_sum'=>0,'q3_sum'=>0,'n'=>0,'q1'=>null,'q2'=>null,'q3'=>null),

			'CONNECTOR'=>array('q1_sum'=>0,'q2_sum'=>0,'q3_sum'=>0,'n'=>0,'q1'=>null,'q2'=>null,'q3'=>null),

		);

		foreach ( $ordered as $i => $row ) {

			$k  = $band_map[(int)floor(($i)/3)] ?? 'CONNECTOR';

			$q1 = isset($row['avg_q1']) ? (float)$row['avg_q1'] : null;

			$q2 = isset($row['avg_q2']) ? (float)$row['avg_q2'] : null;

			$q3 = isset($row['avg_q3']) ? (float)$row['avg_q3'] : null;

			if ($q1===null||$q2===null||$q3===null) continue;

			$style_lenses[$k]['q1_sum']+=$q1; $style_lenses[$k]['q2_sum']+=$q2;

			$style_lenses[$k]['q3_sum']+=$q3; $style_lenses[$k]['n']++;

		}

		foreach ( $style_lenses as $k => $v ) {

			if ($v['n']>0) {

				$style_lenses[$k]['q1']=$v['q1_sum']/$v['n'];

				$style_lenses[$k]['q2']=$v['q2_sum']/$v['n'];

				$style_lenses[$k]['q3']=$v['q3_sum']/$v['n'];

			}

		}



		$pressure_shift = array('headline'=>'','meaning'=>'','do_next'=>'');

		if ( isset($style_lenses[$dominant_key]) && (int)$style_lenses[$dominant_key]['n'] > 0 ) {

			$e = (float)$style_lenses[$dominant_key]['q1'];

			$p = (float)$style_lenses[$dominant_key]['q2'];

			$r = (float)$style_lenses[$dominant_key]['q3'];

			$dp = $p-$e; $dr = $r-$e;

			if ( abs($dp) >= abs($dr) ) {

				if     ($dp>=0.35)  { $pressure_shift = array('headline'=>'Your signal intensifies under pressure','meaning'=>'When stakes rise, your dominant style becomes more pronounced. People may experience you as more direct, faster, and more decisive.','do_next'=>'Keep one strength visible, and add one balancing behaviour (one check-in question plus one clear next step).'); }

				elseif ($dp<=-0.35) { $pressure_shift = array('headline'=>'Your signal softens under pressure','meaning'=>'When stakes rise, your dominant style becomes less visible. People may experience you as quieter or harder to read in the moment.','do_next'=>'Make intent visible: name the priority, state what good looks like, and confirm next action with owner and deadline.'); }

				else                { $pressure_shift = array('headline'=>'Your signal stays steady under pressure','meaning'=>'Your dominant style remains consistent across everyday and high-stakes moments. This builds predictability and trust.','do_next'=>'Use the style deliberately. Flex only when the context demands it.'); }

			} else {

				if     ($dr>=0.35)  { $pressure_shift = array('headline'=>'Your role modelling becomes more visible','meaning'=>'When leadership is on show, your style becomes more consistent. People likely see you as an example setter when it matters.','do_next'=>'Make the standard explicit: say what you are doing and why, so others can copy the behaviour.'); }

				elseif ($dr<=-0.35) { $pressure_shift = array('headline'=>'Role modelling is your biggest lift','meaning'=>'In visible leadership moments, your style is less consistent. The standard you want to set may not always be seen.','do_next'=>'Pick one repeatable behaviour that signals your standard and repeat it weekly. Consistency creates reputation.'); }

				else                { $pressure_shift = array('headline'=>'Your role modelling stays balanced','meaning'=>'You remain broadly stable when leadership is visible, so people experience you as consistent across contexts.','do_next'=>'Focus on one high-return behaviour. Small changes repeated become your leadership signature.'); }

			}

		}



		// ── DISC overlay: local-only inference (no DB changes required) ──

		$disc = array(

			'natural'   => '',

			'adapted'   => '',

			'primary'   => '',

			'secondary' => '',

			'key'       => '',

			'label'     => '',

			'source'    => 'estimated', // estimated | stored

		);



		foreach ( array(

			'disc_natural'   => 'natural',

			'disc_adapted'   => 'adapted',

			'disc_primary'   => 'primary',

			'disc_secondary' => 'secondary',

			'disc_label'     => 'label',

		) as $col => $to ) {

			if ( isset($participant->$col) && (string)$participant->$col !== '' ) {

				$disc[$to] = (string)$participant->$col;

				$disc['source'] = 'stored';

			}

		}



		if ( $disc['primary'] === '' ) {

			$disc['primary'] = $icon_to_disc[$dominant_key] ?? '';

		}

		if ( $disc['secondary'] === '' && $secondary_key ) {

			$disc['secondary'] = $icon_to_disc[$secondary_key] ?? '';

		}



		$k1 = strtoupper(preg_replace('/[^A-Z]/','', (string)$disc['primary']));

		$k2 = strtoupper(preg_replace('/[^A-Z]/','', (string)$disc['secondary']));

		if ( $k1 && $k2 ) $disc['key'] = $k1.$k2;



		if ( $disc['natural'] === '' && $disc['key'] ) $disc['natural'] = $disc['key'];

		if ( $disc['adapted'] === '' && $disc['primary'] ) $disc['adapted'] = $disc['primary'];



		if ( $disc['label'] === '' && $disc['key'] && isset($disc_knowledge[$disc['key']]['label']) ) {

			$disc['label'] = (string) $disc_knowledge[$disc['key']]['label'];

		}



		$disc_bank = ( $disc['key'] && isset($disc_knowledge[$disc['key']]) ) ? $disc_knowledge[$disc['key']] : null;



		$nat_words_for_text = $disc_code_to_words( $disc['natural'] );

		$adp_words_for_text = $disc_code_to_words( $disc['adapted'] );



		$nat_label = ! empty( $nat_words_for_text ) ? implode( ' + ', $nat_words_for_text ) : '';

		$adp_label = ! empty( $adp_words_for_text ) ? implode( ' + ', $adp_words_for_text ) : '';



		$shift_text = '';

		if ( $nat_label && $adp_label && $nat_label !== $adp_label ) {

			$shift_text = "You are currently adjusting your behaviour from {$nat_label} toward {$adp_label} to meet environmental expectations.";

		} else {

			$shift_text = "Your current environment appears aligned with your natural behavioural preferences.";

		}



		// ── Map dot math ──

		$corner = array('CONNECTOR'=>array('x'=>-1.0,'y'=>1.0),'DRIVER'=>array('x'=>1.0,'y'=>1.0),'SUPPORTER'=>array('x'=>-1.0,'y'=>-1.0),'THINKER'=>array('x'=>1.0,'y'=>-1.0));

		$base   = $corner[$dominant_key] ?? array('x'=>0.0,'y'=>0.0);

		$target = ($secondary_key && isset($corner[$secondary_key])) ? $corner[$secondary_key] : $base;

		$avg_by_key = array('DRIVER'=>$avgD,'CONNECTOR'=>$avgC,'SUPPORTER'=>$avgS,'THINKER'=>$avgT);

		$dom_avg2   = $avg_by_key[$dominant_key]  ?? 0.0;

		$sec_avg2   = $avg_by_key[$secondary_key] ?? 0.0;

		$blend = ($secondary_key && $dom_avg2>0.01) ? 0.28*max(0.0,min(1.0,$sec_avg2/$dom_avg2)) : 0.0;

		$x_n = $base['x'] + $blend*($target['x']-$base['x']);

		$y_n = $base['y'] + $blend*($target['y']-$base['y']);

		if ($base['x']>0) $x_n=max(0.10,$x_n); else $x_n=min(-0.10,$x_n);

		if ($base['y']>0) $y_n=max(0.10,$y_n); else $y_n=min(-0.10,$y_n);

		$x_n = max(-1.0,min(1.0,$x_n)); $y_n = max(-1.0,min(1.0,$y_n));



		$svg_w = 760; $svg_h = 500; $pad = 78;

		$cx = (int)round($svg_w/2); $cy = (int)round($svg_h/2);

		$rx = (int)round(($svg_w/2)-$pad); $ry = (int)round(($svg_h/2)-$pad);

		$dot_x = (int)round($cx+($x_n*$rx)); $dot_y = (int)round($cy-($y_n*$ry));




		// ── SVG badge positions ──

		$badgeR   = 48;

		$badgePad = 42;



		$tlx = $pad + $badgePad;          $tly = $pad + $badgePad;

		$trx = $svg_w - $pad - $badgePad; $try = $pad + $badgePad;

		$blx = $pad + $badgePad;          $bly = $svg_h - $pad - $badgePad;

		$brx = $svg_w - $pad - $badgePad; $bry = $svg_h - $pad - $badgePad;



		// ── DISC render flags ──

		$letters = array_values(array_unique(array_filter(array(

			(string) $disc['primary'],

			(string) $disc['secondary'],

		))));

		$has_disc = ! empty($letters);



		// ── HTML ──

		ob_start();

		?>

		<div class="icon-profiler-wrap" style="max-width:1040px;margin:0 auto;padding:24px 18px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">



		<?php echo $debug_html; ?>



		<style>

		:root{--icon-green:#15a06d;--icon-blue:#14a4cf;--ink:#071b1a;--text-mid:#425b56;--card:#ffffff;--border:rgba(20,164,207,.14);--shadow:0 18px 46px rgba(0,0,0,.07);--shadow2:0 26px 72px rgba(0,0,0,.10);--grad:linear-gradient(135deg,var(--icon-blue),var(--icon-green));--pdf-accent:#0f766e;--pdf-line:#e5e7eb;}

		.screen-only{display:block;}.pdf-only{display:none;}

		<?php if($is_pdf): ?>.screen-only{display:none!important;}.pdf-only{display:block!important;}<?php endif; ?>

		.icon-profiler-wrap{position:relative;overflow:hidden;border-radius:24px;background:radial-gradient(circle at top left,#e6f9ff 0%,#ffffff 44%,#e9f8f1 100%);}

		.icon-card{background:var(--card);border:1px solid var(--border);border-radius:22px;padding:18px 18px 16px;box-shadow:var(--shadow);margin:0 0 14px;position:relative;overflow:hidden;transition:transform .12s ease,box-shadow .12s ease;}

		.icon-card:hover{box-shadow:var(--shadow2);transform:translateY(-1px);}

		<?php if(!$is_pdf): ?>

		.icon-profiler-wrap .deep-box,.icon-profiler-wrap .tile,.icon-profiler-wrap .map-card,.icon-profiler-wrap .bars,.icon-profiler-wrap .icon-acc,.icon-profiler-wrap .icon-acc-btn,.icon-profiler-wrap .icon-acc-panel{background:#fff!important;color:var(--ink)!important;}

		.icon-profiler-wrap .deep-box:hover,.icon-profiler-wrap .tile:hover,.icon-profiler-wrap .map-card:hover,.icon-profiler-wrap .bars:hover,.icon-profiler-wrap .icon-acc:hover{background:rgba(21,160,109,.12)!important;border-color:rgba(21,160,109,.38)!important;box-shadow:0 10px 30px rgba(21,160,109,.18)!important;}

		.icon-profiler-wrap .icon-acc-btn:hover{background:rgba(21,160,109,.12)!important;}

		.icon-profiler-wrap .deep-box:hover *,.icon-profiler-wrap .tile:hover *,.icon-profiler-wrap .map-card:hover *,.icon-profiler-wrap .bars:hover *,.icon-profiler-wrap .icon-acc:hover *,.icon-profiler-wrap .icon-acc-btn:hover *{color:inherit!important;}

		<?php endif; ?>

		.icon-hero{

		background:

		linear-gradient(135deg, rgba(20,164,207,.10), rgba(21,160,109,.10)),

		radial-gradient(circle at top left,rgba(20,164,207,.18) 0%,rgba(255,255,255,1) 48%),

		radial-gradient(circle at bottom right,rgba(21,160,109,.18) 0%,rgba(255,255,255,1) 60%);

		border:1px solid rgba(20,164,207,.20);

		}

		.icon-hero:after{

		content:"";

		position:absolute;

		top:10px;

		right:20px;                 /* moves it left */

		bottom:10px;

		width:min(40%,380px);       /* slightly smaller so it doesn't crowd text */

		background-image:url("https://icon-talent.org/wp-content/uploads/2026/02/icon_band_centered_reversed_fixed.gif");

		background-repeat:no-repeat;

		background-position:center right;  /* keeps visual alignment natural */

		background-size:contain;

		opacity:.95;

		pointer-events:none;

		}

		.icon-hero>*{position:relative;z-index:2;}

		.h1{margin:0 0 6px;font-size:26px;font-weight:950;letter-spacing:-.02em;color:var(--ink);}

		.sub{margin:0;color:var(--text-mid);font-size:13px;max-width:760px;line-height:1.55;}

		.section-title{margin:0 0 6px;font-size:16px;font-weight:950;color:#0b2f2a;letter-spacing:-.01em;}

		.p{margin:0;color:#4b5563;font-size:13px;line-height:1.6;}

		.chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;font-size:11px;}

		.chip,.chip2{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;font-weight:950;letter-spacing:.06em;text-transform:uppercase;box-shadow:inset 0 0 0 1px rgba(21,160,109,.10);}

		.chip{background:rgba(21,160,109,.12);color:var(--icon-green);border:1px solid rgba(21,160,109,.18);}

		.chip2{background:rgba(20,164,207,.10);color:#0b2f2a;border:1px solid rgba(20,164,207,.16);text-transform:none;letter-spacing:0;font-weight:800;}

		.chip3{background:rgba(15,118,110,.10);color:#0b2f2a;border:1px solid rgba(15,118,110,.18);text-transform:none;letter-spacing:.02em;font-weight:950;}

		.icon-btn{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;border:1px solid transparent;background:var(--grad);color:#fff;padding:10px 14px;font-size:13px;font-weight:950;text-decoration:none;box-shadow:0 12px 30px rgba(20,164,207,.30);white-space:nowrap;}

		.prof-top{display:grid;grid-template-columns:minmax(0,1.12fr) minmax(0,.88fr);gap:14px;align-items:stretch;}

		@media(max-width:860px){.prof-top{grid-template-columns:1fr;}}

		.bars{border:1px solid rgba(226,232,240,.95);background:#fff;border-radius:18px;padding:14px;}

		.bars h4{margin:0 0 10px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;}

		.row{display:flex;align-items:center;gap:10px;margin:0 0 10px;}

		.name{width:92px;font-weight:950;color:#0b2f2a;font-size:12px;}

		.track{flex:1;height:9px;border-radius:999px;background:#e5e7eb;overflow:hidden;position:relative;}

		.fill{position:absolute;left:0;top:0;bottom:0;border-radius:999px;background:var(--grad);opacity:.95;}

		.score{width:76px;text-align:right;font-variant-numeric:tabular-nums;color:#6b7280;font-size:12px;}

		.deep-quad,.grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}

		@media(max-width:860px){.deep-quad,.grid2{grid-template-columns:1fr;}}

		.deep-box{border:1px solid rgba(226,232,240,.95);border-radius:18px;background:#fff;padding:14px;}

		.deep-head{display:flex;gap:12px;align-items:center;margin-bottom:10px;}

		.deep-head img{width:56px;height:56px;border-radius:14px;background:rgba(20,164,207,.08);border:1px solid rgba(226,232,240,.95);padding:8px;object-fit:contain;}

		.deep-title{font-weight:950;color:#0b2f2a;font-size:14px;letter-spacing:-.01em;}

		.klist{margin:0;padding-left:18px;color:#374151;font-size:13px;line-height:1.55;}

		.klist li{margin:0 0 6px;}

		table{width:100%;border-collapse:collapse;font-size:13px;}

		th,td{padding:8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top;}

		th{background:#f9fafb;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;}

		.cell{display:inline-block;min-width:64px;text-align:center;padding:3px 8px;border-radius:999px;font-variant-numeric:tabular-nums;box-shadow:inset 0 0 0 1px rgba(0,0,0,.04);}

		.low{background:#fef2f2;color:#b91c1c;}.mid{background:#fffbeb;color:#92400e;}.high{background:#ecfdf5;color:#166534;}

		.map-card{border:1px solid rgba(226,232,240,.95);border-radius:18px;background:radial-gradient(circle at top left,rgba(20,164,207,.10) 0%,rgba(255,255,255,1) 42%),radial-gradient(circle at bottom right,rgba(21,160,109,.10) 0%,rgba(255,255,255,1) 60%),#fff;padding:14px;overflow:hidden;}

		.map-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-bottom:10px;}

		.map-sub{margin:0;font-size:12px;color:#6b7280;line-height:1.45;max-width:760px;}

		.map-tags{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;}

		.map-tag{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;border:1px solid rgba(20,164,207,.14);background:#f9fafb;font-weight:900;font-size:11px;color:#0b2f2a;}

		.map-tag .mini-dot{width:10px;height:10px;border-radius:999px;background:var(--grad);box-shadow:0 10px 22px rgba(20,164,207,.22);}

		.mini-dot.pos{background:#15a06d;}

		.mini-dot.neg{background:#b91c1c;}

		.tile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}

		@media(max-width:860px){.tile-grid{grid-template-columns:1fr;}}

		.tile{border:1px solid rgba(226,232,240,.95);border-radius:16px;background:#fff;padding:12px;}

		.tile .k{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;font-weight:950;margin:0 0 6px;}

		.tile .v{font-size:13px;color:#374151;line-height:1.55;margin:0;}



		/* Dashboard */

		.dash-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:12px;}

		@media(max-width:860px){.dash-grid{grid-template-columns:1fr;}}

		.kpi{border:1px solid rgba(226,232,240,.95);border-radius:16px;background:#fff;padding:12px;position:relative;overflow:hidden;}

		.kpi:before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:var(--grad);opacity:.85;}

		.kpi .k{margin:0 0 6px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;font-weight:950;}

		.kpi .v{margin:0;font-size:16px;font-weight:950;color:#0b2f2a;letter-spacing:-.01em;}

		.kpi .s{margin:6px 0 0;font-size:12px;color:#6b7280;line-height:1.45;}

		.meter{height:10px;border-radius:999px;background:#e5e7eb;overflow:hidden;margin-top:10px;position:relative;}

		.meter > span{position:absolute;left:0;top:0;bottom:0;border-radius:999px;background:var(--grad);opacity:.95;}

		.kpi-mini{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;}

		.kpi-mini .pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;border:1px solid rgba(20,164,207,.14);background:#f9fafb;font-weight:900;font-size:11px;color:#0b2f2a;}

		.kpi-mini .dot{width:9px;height:9px;border-radius:999px;background:var(--grad);}



		/* Gap chart (Pressure - Everyday) */

		.gap-card{border:1px solid rgba(226,232,240,.95);border-radius:18px;background:#fff;padding:14px;}

		.gap-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-bottom:10px;}

		.gap-sub{margin:0;font-size:12px;color:#6b7280;line-height:1.45;max-width:820px;}

		.gap-table{display:flex;flex-direction:column;gap:10px;margin-top:10px;}

		.gap-row{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,2fr) 92px;gap:10px;align-items:center;}

		@media(max-width:860px){.gap-row{grid-template-columns:1fr;gap:6px;}}

		.gap-name{font-size:12px;font-weight:950;color:#0b2f2a;line-height:1.35;}

		.gap-bar{height:12px;border-radius:999px;background:#eef2f7;position:relative;overflow:hidden;}

		.gap-bar:before{content:"";position:absolute;left:50%;top:-2px;bottom:-2px;width:2px;background:rgba(148,163,184,.65);}

		.gap-left,.gap-right{position:absolute;top:0;bottom:0;border-radius:999px;}

		.gap-left{right:50%;background:rgba(185,28,28,.20);border:1px solid rgba(185,28,28,.20);}

		.gap-right{left:50%;background:rgba(21,160,109,.18);border:1px solid rgba(21,160,109,.20);}

		.gap-pill{display:inline-flex;justify-content:center;min-width:92px;padding:4px 8px;border-radius:999px;font-variant-numeric:tabular-nums;font-weight:950;font-size:12px;border:1px solid rgba(226,232,240,.95);background:#fff;color:#0b2f2a;}

		.gap-pill.neg{border-color:rgba(185,28,28,.18);background:#fef2f2;color:#991b1b;}

		.gap-pill.pos{border-color:rgba(21,160,109,.18);background:#ecfdf5;color:#065f46;}

		.gap-pill.zero{color:#6b7280;}



		.icon-acc{border:1px solid rgba(226,232,240,.95);border-radius:18px;overflow:hidden;background:#fff;}

		.icon-acc+.icon-acc{margin-top:10px;}

		.icon-acc-btn{width:100%;border:0;background:linear-gradient(180deg,#ffffff,#fbfdff);padding:14px;display:flex;gap:12px;align-items:center;cursor:pointer;text-align:left;}

		.icon-acc-btn:hover{background:#f9fafb;}

		.icon-acc-icon{width:42px;height:42px;border-radius:14px;border:1px solid rgba(226,232,240,.95);background:rgba(20,164,207,.08);padding:7px;display:flex;align-items:center;justify-content:center;}

		.icon-acc-icon img{width:100%;height:100%;object-fit:contain;}

		.icon-acc-title{font-weight:950;color:#0b2f2a;font-size:14px;margin:0;}

		.icon-acc-sub{margin:2px 0 0;font-size:12px;color:#6b7280;}

		.icon-acc-caret{margin-left:auto;width:34px;height:34px;border-radius:999px;border:1px solid rgba(20,164,207,.14);background:#fff;display:flex;align-items:center;justify-content:center;color:#0b2f2a;font-weight:950;}

		.icon-acc-panel{display:none;padding:0 14px 14px;}

		.icon-acc.is-open .icon-acc-panel{display:block;}

		.icon-acc.is-open .icon-acc-caret{transform:rotate(180deg);transition:transform .12s ease;}



		/* Default -> Situational strip */

		.shift-strip{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;}

		.shift-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;border:1px solid rgba(20,164,207,.14);background:#f9fafb;font-weight:950;color:#0b2f2a;font-size:12px;}

		.shift-arrow{font-weight:950;color:#6b7280;}

		.shift-note{font-size:12px;color:#6b7280;margin-top:8px;line-height:1.45;}



		/* Heatmap legend */

		.legend{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0 10px;}

		.legend .tag{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;border:1px solid rgba(226,232,240,.95);background:#fff;font-weight:900;font-size:11px;color:#0b2f2a;}

		.legend .sw{width:10px;height:10px;border-radius:999px;display:inline-block;}

		.legend .sw.low{background:#fef2f2;border:1px solid rgba(185,28,28,.18);}

		.legend .sw.mid{background:#fffbeb;border:1px solid rgba(146,64,14,.18);}

		.legend .sw.high{background:#ecfdf5;border:1px solid rgba(22,101,52,.18);}



		/* NEW: Wheel card */

		.wheel-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px;align-items:center;}

		@media(max-width:860px){.wheel-grid{grid-template-columns:1fr;}}

		.wheel-frame{border:1px solid rgba(226,232,240,.95);border-radius:18px;background:#fff;padding:14px;display:flex;align-items:center;justify-content:center;overflow:hidden;}

		.wheel-frame img{

		width:min(300px,100%);

		height:auto;

		display:block;

		filter:drop-shadow(0 10px 22px rgba(0,0,0,.10));

		}



		<?php if($is_pdf): ?>

		*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}

		html,body{background:#ffffff!important;}

		.pdf-break{page-break-before:always!important;}

		.pdf-keep{page-break-inside:avoid!important;break-inside:avoid!important;}

		.icon-profiler-wrap{padding:0!important;margin:0!important;border-radius:0!important;background:#ffffff!important;}

		.icon-card:hover{transform:none!important;box-shadow:none!important;transition:none!important;}

		.icon-hero:after{display:none!important;}

		.icon-card{box-shadow:none!important;border-radius:12px!important;border:1px solid var(--pdf-line)!important;background:#ffffff!important;position:relative!important;margin-bottom:12px!important;padding:14px 14px 12px 18px!important;page-break-inside:avoid!important;break-inside:avoid!important;overflow:visible!important;}

		.icon-card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:6px;background:var(--pdf-accent);}

		.fill{background:var(--pdf-accent)!important;opacity:1!important;}

		.track{background:#e5e7eb!important;}

		.map-tag .mini-dot{background:var(--pdf-accent)!important;box-shadow:none!important;}

		.deep-box,.tile,.map-card,.bars,.icon-acc,.icon-acc-panel,.tile-grid,.deep-quad,.grid2,.map-head,.deep-head,.chips,.row,.shift-strip,.legend,.wheel-grid,.wheel-frame{page-break-inside:avoid!important;break-inside:avoid!important;}

		.map-head{display:block!important;}.map-sub{display:block!important;margin-bottom:10px!important;}.map-tags{justify-content:flex-start!important;margin-top:6px!important;}

		.icon-acc-panel{display:block!important;}

		.icon-acc.pdf-page{page-break-before:always!important;}

		.section-title,.h1,h4{page-break-after:avoid!important;}

		thead{display:table-header-group;}tr{page-break-inside:avoid;}

		<?php endif; ?>

		</style>



		<!-- HERO -->

		<div class="icon-card icon-hero">

			<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">

				<div style="min-width:0;max-width:820px;">

					<div style="display:flex;gap:10px;align-items:center;margin-bottom:6px;">

						<?php if ( ! empty( $brand_logo_src ) ): ?>

							<img src="<?php echo esc_attr($brand_logo_src); ?>" alt="ICON" style="width:42px;height:auto;border-radius:12px;border:1px solid rgba(226,232,240,.95);background:#fff;padding:6px;"/>

						<?php endif; ?>

						<div class="h1" style="margin:0;">ICON Profiler</div>

					</div>



					<?php

					$display_style = $types[$dominant_key]['label'];

					if($secondary_key){

						$display_style .= ' / '.$types[$secondary_key]['label'];

					}

					?>



					<p class="sub" style="font-size:15px;color:#0b2f2a;font-weight:700;margin-top:4px;">

					<?php echo esc_html($participant_name); ?> — <?php echo esc_html($display_style); ?> leadership profile

					</p>



					<p class="sub" style="margin-top:6px;max-width:720px;">

					This report translates behavioural patterns into practical leadership actions, communication tendencies, and development priorities.

					</p>



					<div class="chips" style="margin-top:14px;">

						<?php if($client_name):?><span class="chip2">Client <strong><?php echo esc_html($client_name);?></strong></span><?php endif;?>

						<?php if($project_name):?><span class="chip2">Project <strong><?php echo esc_html($project_name);?></strong></span><?php endif;?>

						<?php if($participant_role):?><span class="chip2">Role <strong><?php echo esc_html($participant_role);?></strong></span><?php endif;?>

						<span class="chip2">Raters <strong><?php echo (int)$num_raters;?></strong></span>

						<span class="chip2 chip3">Software <strong><?php echo esc_html($software_tier); ?></strong></span>



						<?php if(!is_null($overall_avg)):?>

						<span class="chip">Score <strong><?php echo esc_html(number_format((float)$overall_avg,1));?></strong> / 7</span>

						<?php endif;?>

					</div>

				</div>

			</div>

		</div>



		<!-- AT-A-GLANCE DASHBOARD -->

		<div class="icon-card">

			<div class="section-title">At a glance</div>

			<p class="p" style="margin-bottom:10px;color:#6b7280;">

				A quick visual summary of your profile signal, pace/challenge, and how consistent your style looks across contexts.

			</p>



			<?php

				$gap_to_second = null;

				if ( isset($ranked[0], $ranked[1]) ) {

					$gap_to_second = (float)$ranked[0]['avg'] - (float)$ranked[1]['avg'];

				}

				$secondary_state = ($secondary_key ? 'Secondary detected' : 'No close secondary');

				$secondary_note  = ($secondary_key ? 'Close blend' : 'Clear dominant');



				$stability_badge = 'Balanced';

				$stability_note  = 'Your signal appears relatively consistent across contexts.';

				if ( isset($style_lenses[$dominant_key]['q1'],$style_lenses[$dominant_key]['q2'],$style_lenses[$dominant_key]['q3']) && $style_lenses[$dominant_key]['q1'] !== null ) {

					$a = array(

						(float)$style_lenses[$dominant_key]['q1'],

						(float)$style_lenses[$dominant_key]['q2'],

						(float)$style_lenses[$dominant_key]['q3'],

					);

					$span = max($a) - min($a);

					if ( $span >= 0.65 ) { $stability_badge='High shift'; $stability_note='Your style signal changes noticeably between everyday and higher-stakes contexts.'; }

					elseif ( $span >= 0.35 ) { $stability_badge='Moderate shift'; $stability_note='You show some flex depending on pressure and visibility.'; }

					else { $stability_badge='Stable'; $stability_note='Your style signal stays consistent across contexts, which builds predictability.'; }

				}



				$overall_pct = !is_null($overall_avg) ? (int)round((max(0,min(7,(float)$overall_avg))/7)*100) : 0;

			?>



			<div class="dash-grid">

				<div class="kpi">

					<p class="k">Primary style</p>

					<p class="v"><?php echo esc_html($types[$dominant_key]['label']);?></p>

					<p class="s"><?php echo esc_html($style_knowledge[$dominant_key]['headline'] ?? ''); ?></p>

					<div class="kpi-mini">

						<span class="pill"><span class="dot"></span>Raters: <strong><?php echo (int)$num_raters;?></strong></span>

						<span class="pill"><span class="dot"></span>Tier: <strong><?php echo esc_html($software_tier); ?></strong></span>

						<?php if(!is_null($overall_avg)): ?>

							<span class="pill"><span class="dot"></span>Overall: <strong><?php echo esc_html(number_format((float)$overall_avg,1));?></strong> / 7</span>

						<?php endif; ?>

					</div>

					<?php if(!is_null($overall_avg)): ?>

						<div class="meter"><span style="width:<?php echo (int)$overall_pct; ?>%;"></span></div>

					<?php endif; ?>

				</div>



				<div class="kpi">

					<p class="k">Secondary proximity</p>

					<p class="v"><?php echo $secondary_key ? esc_html($types[$secondary_key]['label']) : '—'; ?></p>

					<p class="s">

						<?php echo esc_html($secondary_state); ?>

						<?php if(!is_null($gap_to_second)): ?>

							• Gap: <strong><?php echo esc_html(number_format((float)$gap_to_second,2)); ?></strong>

						<?php endif; ?>

						<br><?php echo esc_html($secondary_note); ?>

					</p>

					<div class="kpi-mini">

						<span class="pill"><span class="dot"></span><?php echo esc_html($secondary_key ? 'Blend present' : 'Single-dominant'); ?></span>

						<?php if($secondary_key): ?>

							<span class="pill"><span class="dot"></span>Close threshold: <strong>0.35</strong></span>

						<?php endif; ?>

					</div>

				</div>



				<div class="kpi">

					<p class="k">Pace & challenge</p>

					<p class="v"><?php echo esc_html($pace_label); ?></p>

					<p class="s"><?php echo esc_html($challenge_label); ?></p>



					<p class="k" style="margin-top:10px;">Pace</p>

					<div class="meter"><span style="width:<?php echo (int)$pace_pct; ?>%;"></span></div>



					<p class="k" style="margin-top:10px;">Challenge</p>

					<div class="meter"><span style="width:<?php echo (int)$challenge_pct; ?>%;"></span></div>



					<p class="s" style="margin-top:10px;"><strong><?php echo esc_html($stability_badge); ?>:</strong> <?php echo esc_html($stability_note); ?></p>

				</div>

			</div>

		</div>



		<!-- LEADERSHIP PROFILE -->

		<div class="icon-card">

			<div class="section-title">Your leadership profile</div>

			<p class="p" style="margin-bottom:12px;"><?php echo esc_html($first_name);?>, your profile is calculated from your results pattern. It shows your natural default, plus a secondary style if it is close. The goal is not to change who you are, but to help you use your strengths deliberately and flex when the situation demands it.</p>

			<div class="prof-top">

				<div class="deep-box">

					<div class="deep-head">

						<?php if(!empty($style_icon_src[$dominant_key])):?><img src="<?php echo esc_attr($style_icon_src[$dominant_key]);?>" alt="<?php echo esc_attr($types[$dominant_key]['label']);?>"><?php endif;?>

						<div style="min-width:0;">

							<p class="deep-title" style="margin:0;">Primary style: <?php echo esc_html($types[$dominant_key]['label']);?></p>

							<?php if($secondary_key):?>

								<p class="p" style="margin:4px 0 0;color:#065f46;font-size:12px;">Secondary style: <strong><?php echo esc_html($types[$secondary_key]['label']);?></strong></p>

							<?php else:?>

								<p class="p" style="margin:4px 0 0;color:#6b7280;font-size:12px;">No close secondary detected. This usually means your dominant style shows more consistently.</p>

							<?php endif;?>

							<p class="p" style="margin:6px 0 0;color:#6b7280;font-size:12px;">Derived from the first 12 competencies in framework order: 1–3 Driver, 4–6 Supporter, 7–9 Thinker, 10–12 Connector.</p>

						</div>

					</div>

					<?php $dom_bank = isset($style_knowledge[$dominant_key]) ? $style_knowledge[$dominant_key] : null; ?>

					<?php if($dom_bank):?>

						<div class="tile-grid" style="margin-top:10px;">

							<div class="tile"><p class="k">Headline</p><p class="v"><?php echo esc_html($dom_bank['headline']);?></p></div>

							<div class="tile"><p class="k">So what</p><p class="v"><?php echo esc_html($dom_bank['so_what']);?></p></div>

						</div>

						<div class="tile" style="margin-top:12px;"><p class="k">Your style in one paragraph</p><p class="v"><?php echo esc_html($dom_bank['core']);?></p></div>

						<div class="tile-grid" style="margin-top:12px;">

							<div class="tile"><p class="k">Where you tend to shine</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom_bank['best_env'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>

							<div class="tile"><p class="k">High-return development moves</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom_bank['development_moves'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>

						</div>

					<?php endif;?>

				</div>

				<div class="bars screen-only">

					<h4>Style comparison (1 to 7)</h4>

					<?php foreach(array('CONNECTOR','DRIVER','SUPPORTER','THINKER') as $k):

						$avg=$types[$k]['avg']; $pct=$score_to_pct($avg);?>

						<div class="row">

							<div class="name"><?php echo esc_html($types[$k]['label']);?></div>

							<div class="track"><div class="fill" style="width:<?php echo(int)$pct;?>%;"></div></div>

							<div class="score"><?php echo $avg===null?'n/a':esc_html(number_format((float)$avg,1).' / 7');?></div>

						</div>

					<?php endforeach;?>

				</div>

				<?php if($is_pdf):?><div class="bars pdf-only" style="display:none;"></div><?php endif;?>

			</div>

		</div>



		<?php if($is_pdf):?>

		<div class="icon-card pdf-break">

			<div class="section-title">Leadership style comparison</div>

			<p class="p" style="margin-bottom:10px;color:#6b7280;">Style averages on a 1 to 7 scale.</p>

			<div class="bars pdf-keep">

				<h4>Style comparison (1 to 7)</h4>

				<?php foreach(array('CONNECTOR','DRIVER','SUPPORTER','THINKER') as $k):

					$avg=$types[$k]['avg']; $pct=$score_to_pct($avg);?>

					<div class="row">

						<div class="name"><?php echo esc_html($types[$k]['label']);?></div>

						<div class="track"><div class="fill" style="width:<?php echo(int)$pct;?>%;"></div></div>

						<div class="score"><?php echo $avg===null?'n/a':esc_html(number_format((float)$avg,1).' / 7');?></div>

					</div>

				<?php endforeach;?>

			</div>

		</div>

		<?php endif;?>



		<?php if ( $has_disc ): ?>

		<div class="icon-card <?php echo $is_pdf ? 'pdf-break' : ''; ?>">



			<div class="section-title">How behavioural patterns work</div>

			<p class="p" style="margin-bottom:12px;">

				People tend to lean toward one or two natural behavioural patterns. None are better than others — each brings strengths and blind spots.

				Your results estimate which patterns are most visible in your day-to-day leadership behaviour.

			</p>



			<div class="tile-grid">

				<div class="tile"><p class="k">Driving style</p><p class="v">Decisive, outcome-focused, and comfortable taking ownership. Prefers speed, clarity, and action.</p></div>

				<div class="tile"><p class="k">Connecting style</p><p class="v">Engaging, persuasive, and people-focused. Builds energy, collaboration, and stakeholder alignment.</p></div>

				<div class="tile"><p class="k">Supporting style</p><p class="v">Steady, dependable, and cooperative. Creates stability, trust, and team cohesion.</p></div>

				<div class="tile"><p class="k">Thinking style</p><p class="v">Analytical, structured, and quality-focused. Improves decisions through logic and careful evaluation.</p></div>

			</div>



			<div class="tile" style="margin-top:12px;">

				<p class="k">Important</p>

				<p class="v">Everyone uses all four patterns. This report highlights the ones you use most naturally and how they may shift depending on the situation.</p>

			</div>



			<div class="section-title" style="margin-top:14px;">Behavioural tendencies</div>



			<p class="p" style="margin-bottom:10px;">

				This section translates your behavioural tendencies into everyday workplace behaviour. It helps explain how others may experience your communication, decision-making, and reactions under pressure.

				<?php if ( $disc['source'] === 'estimated' ): ?>

					<span style="color:#6b7280;"> </span>

				<?php endif; ?>

			</p>



			<div class="tile" style="margin-top:14px;">

				<p class="k">Default vs situational behaviour</p>

				<p class="v">

					<strong>Default</strong> describes your most effortless behaviour.<br>

					<strong>Situational</strong> reflects how you are adjusting to current expectations or environment.

				</p>



				<div class="shift-strip">

					<?php

					$nat = (string)$disc['natural'];

					$adp = (string)$disc['adapted'];



					$nat_words = $disc_code_to_words( $nat );

					$adp_words = $disc_code_to_words( $adp );

					?>

					<span class="shift-pill">Default: <strong><?php echo esc_html( $nat_words ? implode(' + ', $nat_words) : 'Not set' ); ?></strong></span>

					<span class="shift-arrow">→</span>

					<span class="shift-pill">Situational: <strong><?php echo esc_html( $adp_words ? implode(' + ', $adp_words) : 'Not set' ); ?></strong></span>

				</div>



				<p class="shift-note"><?php echo esc_html($shift_text); ?></p>

			</div>



			<div class="tile-grid" style="margin-top:12px;">

				<?php foreach($letters as $L): if(isset($disc_letter_library[$L])): ?>

				<div class="tile">

					<p class="k"><?php echo esc_html($style_labels[$L] ?? $L); ?> style needs</p>

					<ul class="klist">

						<?php foreach($disc_letter_library[$L]['needs'] as $n): ?>

							<li><?php echo esc_html($n); ?></li>

						<?php endforeach; ?>

					</ul>

				</div>

				<?php endif; endforeach; ?>

			</div>



			<div class="tile-grid" style="margin-top:12px;">

				<?php foreach($letters as $L): if(isset($disc_letter_library[$L])): $lib=$disc_letter_library[$L]; ?>

				<div class="tile">

					<p class="k"><?php echo esc_html($style_labels[$L] ?? $L); ?> under pressure</p>

					<p class="v"><strong>Triggers:</strong> <?php echo esc_html(implode(', ',$lib['stress_triggers'])); ?></p>

					<p class="v"><strong>Behaviour:</strong> <?php echo esc_html($lib['under_pressure']); ?></p>

					<p class="v"><strong>Best response:</strong> <?php echo esc_html($lib['best_response']); ?></p>

				</div>

				<?php endif; endforeach; ?>

			</div>



			<div class="tile-grid" style="margin-top:12px;">

				<?php foreach($letters as $L): if(isset($disc_letter_library[$L])): $lib=$disc_letter_library[$L]; ?>

				<div class="tile">

					<p class="k"><?php echo esc_html($style_labels[$L] ?? $L); ?> communication</p>

					<p class="v"><?php echo esc_html($lib['communication']); ?></p>

					<p class="k" style="margin-top:8px;">Conflict approach</p>

					<p class="v"><?php echo esc_html($lib['conflict']); ?></p>

				</div>

				<?php endif; endforeach; ?>

			</div>



			<div class="tile" style="margin-top:12px;">

				<p class="k">Leadership translation</p>

				<ul class="klist">

					<?php foreach($letters as $L): if(isset($disc_letter_library[$L])): ?>

						<li><?php echo esc_html($disc_letter_library[$L]['leadership']); ?></li>

					<?php endif; endforeach; ?>

				</ul>

			</div>



			<div class="tile" style="margin-top:12px;">

				<p class="k">Behaviour focus for the next 30 days</p>

				<ul class="klist">

					<li><strong>Start:</strong> Ask one extra clarifying question before responding.</li>

					<li><strong>Stop:</strong> Defaulting to your first instinct under pressure.</li>

					<li><strong>Continue:</strong> Using your natural strengths intentionally.</li>

					<li><strong>If you notice stress:</strong> Slow pace, summarise goals, reset expectations.</li>

				</ul>

			</div>



			<?php if ( $disc_bank ): ?>

				<div class="tile" style="margin-top:12px;">

					<p class="k">In one paragraph</p>

					<p class="v"><?php echo esc_html($disc_bank['core']);?></p>

				</div>



				<div class="tile-grid" style="margin-top:12px;">

					<div class="tile">

						<p class="k">Strengths</p>

						<ul class="klist" style="margin:0;"><?php foreach((array)$disc_bank['strengths'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul>

					</div>

					<div class="tile">

						<p class="k">Watch-outs</p>

						<ul class="klist" style="margin:0;"><?php foreach((array)$disc_bank['watch_outs'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul>

					</div>

				</div>



				<div class="tile-grid" style="margin-top:12px;">

					<div class="tile">

						<p class="k">Communication do</p>

						<ul class="klist" style="margin:0;"><?php foreach((array)$disc_bank['comms_do'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul>

					</div>

					<div class="tile">

						<p class="k">How to work with you</p>

						<ul class="klist" style="margin:0;"><?php foreach((array)$disc_bank['how_to_work_with'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul>

					</div>

				</div>

			<?php endif; ?>



		</div>

		<?php endif; ?>



		<!-- MAP -->

		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">

			<div class="section-title">Your position on the Profiler map</div>

			<div class="map-card">

				<div class="map-head">

					<p class="map-sub">The dot is anchored to your dominant style. If a secondary style is close, it blends slightly toward it, but never crosses quadrants.</p>

					<div class="map-tags">

						<span class="map-tag"><span class="mini-dot"></span> Current pattern</span>

						<span class="map-tag">Primary: <strong><?php echo esc_html($types[$dominant_key]['label']);?></strong></span>

						<?php if($secondary_key):?><span class="map-tag">Secondary: <strong><?php echo esc_html($types[$secondary_key]['label']);?></strong></span><?php endif;?>

					</div>

				</div>

				<div style="display:flex;justify-content:center;overflow:auto;">

					<?php if($is_pdf && !empty($map_png_data_uri)):?>

						<img src="<?php echo esc_attr($map_png_data_uri);?>" alt="Profiler map" style="display:block;margin:0 auto;width:720px;max-width:100%;height:auto;border:1px solid #e5e7eb;border-radius:12px;background:#fff;"/>

					<?php else:?>

						<svg width="<?php echo(int)$svg_w;?>" height="<?php echo(int)$svg_h;?>" viewBox="0 0 <?php echo(int)$svg_w;?> <?php echo(int)$svg_h;?>" role="img" aria-label="Profiler map">

							<defs>

								<linearGradient id="gBorder" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#14a4cf" stop-opacity="0.70"/><stop offset="100%" stop-color="#15a06d" stop-opacity="0.70"/></linearGradient>

								<linearGradient id="gSoft" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#14a4cf" stop-opacity="0.10"/><stop offset="100%" stop-color="#15a06d" stop-opacity="0.10"/></linearGradient>

								<filter id="cardShadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="10" stdDeviation="12" flood-color="#0b2f2a" flood-opacity="0.14"/></filter>

								<filter id="badgeShadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="8" stdDeviation="10" flood-color="#0b2f2a" flood-opacity="0.16"/></filter>

								<filter id="dotGlow" x="-60%" y="-60%" width="220%" height="220%"><feGaussianBlur stdDeviation="7" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter>

							</defs>

							<g filter="url(#cardShadow)">

								<rect x="10" y="10" width="<?php echo(int)($svg_w-20);?>" height="<?php echo(int)($svg_h-20);?>" rx="22" ry="22" fill="#ffffff" stroke="url(#gBorder)" stroke-width="3"/>

								<rect x="22" y="22" width="<?php echo(int)($svg_w-44);?>" height="<?php echo(int)($svg_h-44);?>" rx="18" ry="18" fill="url(#gSoft)" stroke="rgba(226,232,240,.95)" stroke-width="1"/>

							</g>

							<rect x="<?php echo(int)$pad;?>" y="<?php echo(int)$pad;?>" width="<?php echo(int)($cx-$pad);?>" height="<?php echo(int)($cy-$pad);?>" fill="rgba(20,164,207,.07)"/>

							<rect x="<?php echo(int)$cx;?>" y="<?php echo(int)$pad;?>" width="<?php echo(int)($svg_w-$cx-$pad);?>" height="<?php echo(int)($cy-$pad);?>" fill="rgba(21,160,109,.07)"/>

							<rect x="<?php echo(int)$pad;?>" y="<?php echo(int)$cy;?>" width="<?php echo(int)($cx-$pad);?>" height="<?php echo(int)($svg_h-$cy-$pad);?>" fill="rgba(21,160,109,.05)"/>

							<rect x="<?php echo(int)$cx;?>" y="<?php echo(int)$cy;?>" width="<?php echo(int)($svg_w-$cx-$pad);?>" height="<?php echo(int)($svg_h-$cy-$pad);?>" fill="rgba(20,164,207,.05)"/>

							<line x1="<?php echo(int)$cx;?>" y1="<?php echo(int)$pad;?>" x2="<?php echo(int)$cx;?>" y2="<?php echo(int)($svg_h-$pad);?>" stroke="rgba(148,163,184,.62)" stroke-width="2"/>

							<line x1="<?php echo(int)$pad;?>" y1="<?php echo(int)$cy;?>" x2="<?php echo(int)($svg_w-$pad);?>" y2="<?php echo(int)$cy;?>" stroke="rgba(148,163,184,.62)" stroke-width="2"/>

							<text x="<?php echo(int)($pad+8);?>" y="<?php echo(int)($cy-12);?>" font-size="12" font-weight="900" fill="#0b2f2a">People</text>

							<text x="<?php echo(int)($svg_w-$pad-46);?>" y="<?php echo(int)($cy-12);?>" font-size="12" font-weight="900" fill="#0b2f2a">Task</text>

							<text x="<?php echo(int)($cx+10);?>" y="<?php echo(int)($pad+20);?>" font-size="12" font-weight="900" fill="#0b2f2a">Pace</text>

							<text x="<?php echo(int)($cx+10);?>" y="<?php echo(int)($svg_h-$pad-8);?>" font-size="12" font-weight="900" fill="#0b2f2a">Steady</text>

							<?php foreach(array('CONNECTOR'=>array($tlx,$tly),'DRIVER'=>array($trx,$try),'SUPPORTER'=>array($blx,$bly),'THINKER'=>array($brx,$bry)) as $sk=>$pos):

								$bx=$pos[0]; $by=$pos[1];

								$border_color = in_array($sk,array('CONNECTOR','THINKER')) ? 'rgba(20,164,207,.22)' : 'rgba(21,160,109,.22)';

							?>

								<g filter="url(#badgeShadow)">

									<circle cx="<?php echo(int)$bx;?>" cy="<?php echo(int)$by;?>" r="<?php echo(int)$badgeR;?>" fill="rgba(255,255,255,.96)" stroke="<?php echo $border_color;?>" stroke-width="2"/>

									<?php if(!empty($style_icon_src[$sk])):?><image href="<?php echo esc_attr($style_icon_src[$sk]);?>" x="<?php echo(int)($bx-33);?>" y="<?php echo(int)($by-33);?>" width="66" height="66" preserveAspectRatio="xMidYMid meet"/><?php endif;?>

								</g>

								<text x="<?php echo(int)$bx;?>" y="<?php echo(int)($by+60);?>" font-size="11" font-weight="950" fill="#0b2f2a" text-anchor="middle"><?php echo esc_html($types[$sk]['label']);?></text>

							<?php endforeach;?>

							<circle cx="<?php echo(int)$dot_x;?>" cy="<?php echo(int)$dot_y;?>" r="18" fill="rgba(20,164,207,.25)" filter="url(#dotGlow)"/>

							<circle cx="<?php echo(int)$dot_x;?>" cy="<?php echo(int)$dot_y;?>" r="10" fill="#0f766e"/>

						</svg>

					<?php endif;?>

				</div>

			</div>

		</div>



		<!-- NEW: WHEEL (Rotating GIF) -->

		<div class="icon-card <?php echo $is_pdf ? 'pdf-break' : ''; ?>">

			<div class="section-title">Profile Map how to use</div>

			<p class="p" style="margin-bottom:10px;color:#6b7280;">

				A visual reference of the four behavioural patterns.

			</p>

			<div class="wheel-grid">

				<div class="wheel-frame">

					<?php if ( ! empty( $wheel_src ) ): ?>

						<img src="<?php echo esc_attr($wheel_src); ?>" alt="Profiler wheel">

					<?php else: ?>

						<div class="p" style="color:#6b7280;">Wheel image unavailable.</div>

					<?php endif; ?>

				</div>

				<div class="deep-box">

					<p class="p" style="font-weight:950;margin-bottom:6px;">How to use</p>

					<ul class="klist">

						<li><strong>Primary</strong> = your most visible default pattern.</li>

						<li><strong>Secondary</strong> = a close pattern you may switch into in certain contexts.</li>

						<li>In mixed teams, performance improves when you flex <em>pace</em> and <em>challenge</em> to match the moment.</li>

						<li>None are “better” — the goal is deliberate use, not changing personality.</li>

					</ul>

				</div>

			</div>

		</div>



		<!-- WHAT YOUR RESULTS SUGGEST -->

		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">

			<div class="section-title">What your results suggest</div>

			<p class="p" style="margin-bottom:10px;">This section translates your profile into practical, day-to-day leadership behaviours: how you come across, what you naturally do well, what tends to trip you up, and exactly what to do next to level up.</p>

			<?php $dom=$dom_bank; $sec=($secondary_key&&isset($style_knowledge[$secondary_key]))?$style_knowledge[$secondary_key]:null; ?>

			<div class="deep-box pdf-keep">

				<div class="deep-head">

					<?php if(!empty($style_icon_src[$dominant_key])):?><img src="<?php echo esc_attr($style_icon_src[$dominant_key]);?>" alt="<?php echo esc_attr($types[$dominant_key]['label']);?>"><?php endif;?>

					<div>

						<p class="deep-title" style="margin:0;">Dominant style: <?php echo esc_html($types[$dominant_key]['label']);?></p>

						<p class="p" style="margin:4px 0 0;color:#6b7280;font-size:12px;">This is the pattern people are most likely to experience as your "default".</p>

					</div>

				</div>

				<?php if($dom):?>

					<div class="tile-grid">

						<div class="tile"><p class="k">Core signal</p><p class="v"><?php echo esc_html($dom['core']);?></p></div>

						<div class="tile"><p class="k">Under pressure</p><p class="v"><?php echo esc_html($dom['pressure']);?></p></div>

					</div>

					<div class="tile-grid" style="margin-top:12px;">

						<div class="tile"><p class="k">Likely strengths</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom['strengths'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>

						<div class="tile"><p class="k">Watch-outs</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom['watch_outs'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>

					</div>

					<?php if($is_pdf):?>

						<div class="tile pdf-keep" style="margin-top:12px;"><p class="k">Communication do</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom['comms_do'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>

						<div class="pdf-break"></div>

						<div class="tile pdf-keep" style="margin-top:12px;"><p class="k">Communication avoid</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom['comms_dont'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>

					<?php else:?>

						<div class="tile-grid" style="margin-top:12px;">

							<div class="tile"><p class="k">Communication do</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom['comms_do'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>

							<div class="tile"><p class="k">Communication avoid</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom['comms_dont'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>

						</div>

					<?php endif;?>

				<?php endif;?>

			</div>

			<?php if($secondary_key):?>

				<div class="deep-box pdf-keep" style="margin-top:12px;">

					<div class="deep-head">

						<?php if(!empty($style_icon_src[$secondary_key])):?><img src="<?php echo esc_attr($style_icon_src[$secondary_key]);?>" alt="<?php echo esc_attr($types[$secondary_key]['label']);?>"><?php endif;?>

						<div>

							<p class="deep-title" style="margin:0;">Secondary style: <?php echo esc_html($types[$secondary_key]['label']);?></p>

							<p class="p" style="margin:4px 0 0;color:#6b7280;font-size:12px;">This is your "switch". It often appears in specific situations (stakeholders, time pressure, ambiguity, conflict, or high standards).</p>

						</div>

					</div>

					<?php if($sec):?>

						<div class="tile-grid">

							<div class="tile"><p class="k">When you may switch into it</p><p class="v">You may lean into <?php echo esc_html($types[$secondary_key]['label']);?> when the moment demands <?php echo esc_html(strtolower($sec['headline']));?>.</p></div>

							<div class="tile"><p class="k">How to use it well</p><ul class="klist" style="margin:0;"><li>Use it as a tool, not a mask. Keep your dominant strengths visible.</li><li>Use one behaviour from this style to balance the room.</li><li>Return to your default once alignment and next steps are clear.</li></ul></div>

						</div>

					<?php endif;?>

				</div>

			<?php endif;?>

			<?php if($is_pdf && isset($style_knowledge[$dominant_key]['so_what'])):?>

				<div class="icon-card pdf-break" style="margin-top:12px;">

					<div class="section-title">Your next best step</div>

					<div class="tile pdf-keep"><p class="k">High ROI</p><p class="v"><?php echo esc_html($style_knowledge[$dominant_key]['so_what']);?></p></div>

				</div>

			<?php else:?>

				<div class="tile screen-only" style="margin-top:12px;"><p class="k">Your next best step (high ROI)</p><p class="v"><?php echo $dom?esc_html($dom['so_what']):'';?></p></div>

			<?php endif;?>

		</div>



		<!-- PROFILER SECTIONS -->

		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">

			<div class="section-title">Profiler sections</div>

			<p class="p" style="margin-bottom:10px;color:#6b7280;">On screen, these expand and collapse. In the PDF, they start on new pages and are fully shown.</p>

			<?php foreach(array('DRIVER','CONNECTOR','SUPPORTER','THINKER') as $i=>$sk):

				$bank=isset($style_knowledge[$sk])?$style_knowledge[$sk]:null;

				if(!$bank) continue;

				$is_dom=($sk===$dominant_key); $is_sec=($secondary_key&&$sk===$secondary_key);

				$tag=$is_dom?'Your dominant style':($is_sec?'Your secondary style':'Profile reference');

				$pdf_page_class=($is_pdf&&$i>0)?'pdf-page':'';

			?>

				<div class="icon-acc <?php echo($is_dom&&!$is_pdf)?'is-open':'';?> <?php echo esc_attr($pdf_page_class);?>">

					<button type="button" class="icon-acc-btn screen-only">

						<span class="icon-acc-icon"><?php if(!empty($style_icon_src[$sk])):?><img src="<?php echo esc_attr($style_icon_src[$sk]);?>" alt="<?php echo esc_attr($types[$sk]['label']);?>"><?php endif;?></span>

						<span style="min-width:0;"><p class="icon-acc-title"><?php echo esc_html($types[$sk]['label']);?></p><p class="icon-acc-sub"><?php echo esc_html($bank['headline']);?> • <?php echo esc_html($tag);?></p></span>

						<span class="icon-acc-caret">⌄</span>

					</button>

					<div class="pdf-only" style="padding:14px 14px 0;">

						<div style="display:flex;gap:12px;align-items:center;">

							<span class="icon-acc-icon"><?php if(!empty($style_icon_src[$sk])):?><img src="<?php echo esc_attr($style_icon_src[$sk]);?>" alt="<?php echo esc_attr($types[$sk]['label']);?>"><?php endif;?></span>

							<div><p class="icon-acc-title" style="margin:0;"><?php echo esc_html($types[$sk]['label']);?></p><p class="icon-acc-sub" style="margin:2px 0 0;"><?php echo esc_html($bank['headline']);?> • <?php echo esc_html($tag);?></p></div>

						</div>

					</div>

					<div class="icon-acc-panel">

						<div class="tile" style="margin-top:12px;"><p class="k">In a sentence</p><p class="v"><?php echo esc_html($bank['core']);?></p></div>

						<div class="tile-grid" style="margin-top:12px;">

							<div class="tile"><p class="k">Strengths</p><ul class="klist" style="margin:0;"><?php foreach((array)$bank['strengths'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>

							<div class="tile"><p class="k">Watch-outs</p><ul class="klist" style="margin:0;"><?php foreach((array)$bank['watch_outs'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>

						</div>

						<div class="tile" style="margin-top:12px;"><p class="k">Development moves</p><ul class="klist" style="margin:0;"><?php foreach((array)$bank['development_moves'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>

					</div>

				</div>

			<?php endforeach;?>

			<?php if(!$is_pdf):?>

				<script>(function(){var a=document.querySelectorAll('.icon-acc');if(!a||!a.length)return;a.forEach(function(b){var c=b.querySelector('.icon-acc-btn');if(!c)return;c.addEventListener('click',function(){b.classList.toggle('is-open');});});})();</script>

			<?php endif;?>

		</div>



		<!-- CULTURE LENS -->

		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">

			<div class="section-title">Culture lens</div>

			<p class="p" style="margin-bottom:10px;">This is a practical read of the leadership signal you tend to project in teams. It looks at two things people feel quickly: <strong>pace</strong> and <strong>challenge</strong>.</p>

			<?php

			$render_lens_box = function($label,$pct,$detail,$watch,$title) {

				echo '<div class="deep-box pdf-keep">';

				echo '<p class="p" style="font-weight:950;margin-bottom:6px;">'.esc_html($title).' signal</p>';

				echo '<p class="p" style="margin:0 0 8px;color:#6b7280;font-size:12px;">'.esc_html($label).'</p>';

				echo '<div class="track" style="height:10px;"><div class="fill" style="width:'.(int)$pct.'%;"></div></div>';

				echo '<div class="tile" style="margin-top:10px;"><p class="k">What '.strtolower($title).' means</p><ul class="klist" style="margin:0;">';

				foreach($detail as $it) echo '<li>'.esc_html($it).'</li>';

				echo '</ul></div><div class="tile" style="margin-top:10px;"><p class="k">Watch-outs and adjustments</p><ul class="klist" style="margin:0;">';

				foreach($watch as $it) echo '<li>'.esc_html($it).'</li>';

				echo '</ul></div></div>';

			};

			?>

			<?php if($is_pdf):?>

				<?php $render_lens_box($pace_label,$pace_pct,$pace_detail,$pace_watch,'Pace');?>

				<div class="icon-card pdf-break">

					<div class="section-title">Culture lens (continued)</div>

					<?php $render_lens_box($challenge_label,$challenge_pct,$challenge_detail,$challenge_watch,'Challenge');?>

				</div>

			<?php else:?>

				<div class="deep-quad">

					<?php $render_lens_box($pace_label,$pace_pct,$pace_detail,$pace_watch,'Pace');?>

					<?php $render_lens_box($challenge_label,$challenge_pct,$challenge_detail,$challenge_watch,'Challenge');?>

				</div>

			<?php endif;?>

		</div>



		<!-- PRESSURE SHIFT -->

		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">

			<div class="section-title">Pressure shift insight</div>

			<?php if(empty($pressure_shift['headline'])):?>

				<p class="p" style="color:#6b7280;">Not enough lens data yet to calculate a pressure shift insight.</p>

			<?php else:?>

				<div class="deep-box pdf-keep" style="margin-top:10px;">

					<p class="p" style="font-weight:950;margin-bottom:6px;"><?php echo esc_html($pressure_shift['headline']);?></p>

					<p class="p" style="margin:0 0 10px;"><?php echo esc_html($pressure_shift['meaning']);?></p>

					<p class="p" style="margin:0;color:#6b7280;"><strong>What to do next:</strong> <?php echo esc_html($pressure_shift['do_next']);?></p>

					<?php if(isset($style_lenses[$dominant_key]['q1'],$style_lenses[$dominant_key]['q2'],$style_lenses[$dominant_key]['q3'])&&$style_lenses[$dominant_key]['q1']!==null):?>

						<div style="margin-top:10px;font-size:12px;color:#6b7280;"><strong>Dominant lens averages:</strong>

							Everyday <?php echo esc_html(number_format((float)$style_lenses[$dominant_key]['q1'],1));?>,

							Pressure <?php echo esc_html(number_format((float)$style_lenses[$dominant_key]['q2'],1));?>,

							Role-modelling <?php echo esc_html(number_format((float)$style_lenses[$dominant_key]['q3'],1));?>.

						</div>

					<?php endif;?>

				</div>

			<?php endif;?>

		</div>



		<!-- PRESSURE vs EVERYDAY GAP CHART -->

		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">

			<div class="section-title">Pressure shift by trait (visual)</div>

			<div class="gap-card">

				<div class="gap-head">

					<p class="gap-sub">

						This shows the difference between <strong>Under pressure</strong> and <strong>Everyday</strong> behaviour for each trait.

						Right side means the signal increases under pressure. Left side means it reduces.

					</p>

					<div class="map-tags">

						<span class="map-tag"><span class="mini-dot pos"></span> Increase under pressure</span>

						<span class="map-tag"><span class="mini-dot neg"></span> Decrease under pressure</span>

					</div>

				</div>



				<?php

					$gap_rows = array();

					foreach ( (array)$heatmap_rows as $r ) {

						$e = isset($r['avg_q1']) ? (float)$r['avg_q1'] : 0.0;

						$p = isset($r['avg_q2']) ? (float)$r['avg_q2'] : 0.0;

						$d = $p - $e;

						$gap_rows[] = array(

							'name'  => (string)($r['name'] ?? ''),

							'delta' => (float)$d,

						);

					}

					usort($gap_rows, function($a,$b){

						$aa = abs((float)$a['delta']); $bb = abs((float)$b['delta']);

						if ( $aa === $bb ) return 0;

						return ($aa > $bb) ? -1 : 1;

					});



					$gap_rows = array_slice($gap_rows, 0, 12);



					$maxAbs = 0.0;

					foreach($gap_rows as $gr) $maxAbs = max($maxAbs, abs((float)$gr['delta']));

					if ($maxAbs < 0.01) $maxAbs = 0.01;

				?>



				<div class="gap-table">

					<?php foreach($gap_rows as $gr):

						$delta = (float)$gr['delta'];

						$pct   = (int)round( min(1.0, abs($delta)/$maxAbs ) * 100 );

						$cls   = $delta > 0.06 ? 'pos' : ($delta < -0.06 ? 'neg' : 'zero');

						$label = ($delta > 0 ? '+' : '').number_format($delta,2);

					?>

						<div class="gap-row">

							<div class="gap-name"><?php echo esc_html($gr['name']); ?></div>



							<div class="gap-bar" aria-hidden="true">

								<?php if($delta < -0.06): ?>

									<span class="gap-left" style="width:<?php echo (int)$pct; ?>%;"></span>

								<?php elseif($delta > 0.06): ?>

									<span class="gap-right" style="width:<?php echo (int)$pct; ?>%;"></span>

								<?php endif; ?>

							</div>



							<div><span class="gap-pill <?php echo esc_attr($cls); ?>"><?php echo esc_html($label); ?></span></div>

						</div>

					<?php endforeach; ?>

				</div>



				<p class="gap-sub" style="margin-top:12px;">

					<strong>How to read this:</strong> The bigger the bar, the bigger the behavioural shift under pressure for that trait.

					Use the top items as your coaching focus because they create the biggest difference in how others experience you.

				</p>

			</div>

		</div>



		<!-- HEATMAP -->

		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">

			<div class="section-title">Trait heatmap</div>

			<p class="p" style="margin-bottom:6px;color:#6b7280;">Averages across Everyday, Under pressure, and Role-modelling (1 to 7).</p>



			<div class="legend">

				<span class="tag"><span class="sw low"></span> Low</span>

				<span class="tag"><span class="sw mid"></span> Mid</span>

				<span class="tag"><span class="sw high"></span> High</span>

			</div>



			<div style="overflow:auto;">

				<table>

					<thead><tr><th>Trait</th><th>Everyday</th><th>Pressure</th><th>Role model</th><th>Overall</th></tr></thead>

					<tbody>

						<?php foreach($heatmap_rows as $row):

							$overall=(float)$row['overall'];

							$band=$overall<3.5?'low':($overall<5.5?'mid':'high');

						?>

							<tr>

								<td style="font-weight:950;color:#0b2f2a;"><?php echo esc_html($row['name']);?></td>

								<td><span class="cell <?php echo esc_attr($band);?>"><?php echo esc_html(number_format($row['avg_q1'],1));?></span></td>

								<td><span class="cell <?php echo esc_attr($band);?>"><?php echo esc_html(number_format($row['avg_q2'],1));?></span></td>

								<td><span class="cell <?php echo esc_attr($band);?>"><?php echo esc_html(number_format($row['avg_q3'],1));?></span></td>

								<td><span class="cell <?php echo esc_attr($band);?>"><?php echo esc_html(number_format($overall,1));?></span></td>

							</tr>

						<?php endforeach;?>

					</tbody>

				</table>

			</div>

		</div>



		<!-- NARRATIVE -->

		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">

			<div class="section-title">Narrative feedback</div>

			<div class="deep-quad">

				<div class="deep-box pdf-keep" style="margin:0;">

					<p class="p" style="font-weight:950;margin-bottom:8px;">Perceived strengths</p>

					<?php if(empty($strengths)):?><p class="p" style="color:#6b7280;">No strengths comments recorded yet.</p>

					<?php else:?><ul class="klist"><?php foreach($strengths as $c):?><li><?php echo esc_html((string)$c);?></li><?php endforeach;?></ul><?php endif;?>

				</div>

				<div class="deep-box pdf-keep" style="margin:0;">

					<p class="p" style="font-weight:950;margin-bottom:8px;">Development opportunities</p>

					<?php if(empty($dev_opps)):?><p class="p" style="color:#6b7280;">No development comments recorded yet.</p>

					<?php else:?><ul class="klist"><?php foreach($dev_opps as $c):?><li><?php echo esc_html((string)$c);?></li><?php endforeach;?></ul><?php endif;?>

				</div>

			</div>

		</div>



		</div>

		<?php

		$screen_html = ob_get_clean();




		return $screen_html;

	}

}



if ( ! function_exists('icon_profiler_register_shortcode') ) {

	function icon_profiler_register_shortcode() {

		if ( shortcode_exists('icon_profiler_report') ) remove_shortcode('icon_profiler_report');

		add_shortcode('icon_profiler_report','icon_profiler_report_render');

	}

}

add_action('init','icon_profiler_register_shortcode',99);



if ( function_exists('error_log') ) error_log('ICON PROFILER REPORT FILE LOADED: '.__FILE__);


		.tile{border:1px solid rgba(226,232,240,.95);border-radius:16px;background:#fff;padding:12px;}
		.tile .k{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;font-weight:950;margin:0 0 6px;}
		.tile .v{font-size:13px;color:#374151;line-height:1.55;margin:0;}

		/* Dashboard */
		.dash-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:12px;}
		@media(max-width:860px){.dash-grid{grid-template-columns:1fr;}}
		.kpi{border:1px solid rgba(226,232,240,.95);border-radius:16px;background:#fff;padding:12px;position:relative;overflow:hidden;}
		.kpi:before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:var(--grad);opacity:.85;}
		.kpi .k{margin:0 0 6px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;font-weight:950;}
		.kpi .v{margin:0;font-size:16px;font-weight:950;color:#0b2f2a;letter-spacing:-.01em;}
		.kpi .s{margin:6px 0 0;font-size:12px;color:#6b7280;line-height:1.45;}
		.meter{height:10px;border-radius:999px;background:#e5e7eb;overflow:hidden;margin-top:10px;position:relative;}
		.meter > span{position:absolute;left:0;top:0;bottom:0;border-radius:999px;background:var(--grad);opacity:.95;}
		.kpi-mini{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;}
		.kpi-mini .pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;border:1px solid rgba(20,164,207,.14);background:#f9fafb;font-weight:900;font-size:11px;color:#0b2f2a;}
		.kpi-mini .dot{width:9px;height:9px;border-radius:999px;background:var(--grad);}

		/* Gap chart (Pressure - Everyday) */
		.gap-card{border:1px solid rgba(226,232,240,.95);border-radius:18px;background:#fff;padding:14px;}
		.gap-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-bottom:10px;}
		.gap-sub{margin:0;font-size:12px;color:#6b7280;line-height:1.45;max-width:820px;}
		.gap-table{display:flex;flex-direction:column;gap:10px;margin-top:10px;}
		.gap-row{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,2fr) 92px;gap:10px;align-items:center;}
		@media(max-width:860px){.gap-row{grid-template-columns:1fr;gap:6px;}}
		.gap-name{font-size:12px;font-weight:950;color:#0b2f2a;line-height:1.35;}
		.gap-bar{height:12px;border-radius:999px;background:#eef2f7;position:relative;overflow:hidden;}
		.gap-bar:before{content:"";position:absolute;left:50%;top:-2px;bottom:-2px;width:2px;background:rgba(148,163,184,.65);}
		.gap-left,.gap-right{position:absolute;top:0;bottom:0;border-radius:999px;}
		.gap-left{right:50%;background:rgba(185,28,28,.20);border:1px solid rgba(185,28,28,.20);}
		.gap-right{left:50%;background:rgba(21,160,109,.18);border:1px solid rgba(21,160,109,.20);}
		.gap-pill{display:inline-flex;justify-content:center;min-width:92px;padding:4px 8px;border-radius:999px;font-variant-numeric:tabular-nums;font-weight:950;font-size:12px;border:1px solid rgba(226,232,240,.95);background:#fff;color:#0b2f2a;}
		.gap-pill.neg{border-color:rgba(185,28,28,.18);background:#fef2f2;color:#991b1b;}
		.gap-pill.pos{border-color:rgba(21,160,109,.18);background:#ecfdf5;color:#065f46;}
		.gap-pill.zero{color:#6b7280;}

		.icon-acc{border:1px solid rgba(226,232,240,.95);border-radius:18px;overflow:hidden;background:#fff;}
		.icon-acc+.icon-acc{margin-top:10px;}
		.icon-acc-btn{width:100%;border:0;background:linear-gradient(180deg,#ffffff,#fbfdff);padding:14px;display:flex;gap:12px;align-items:center;cursor:pointer;text-align:left;}
		.icon-acc-btn:hover{background:#f9fafb;}
		.icon-acc-icon{width:42px;height:42px;border-radius:14px;border:1px solid rgba(226,232,240,.95);background:rgba(20,164,207,.08);padding:7px;display:flex;align-items:center;justify-content:center;}
		.icon-acc-icon img{width:100%;height:100%;object-fit:contain;}
		.icon-acc-title{font-weight:950;color:#0b2f2a;font-size:14px;margin:0;}
		.icon-acc-sub{margin:2px 0 0;font-size:12px;color:#6b7280;}
		.icon-acc-caret{margin-left:auto;width:34px;height:34px;border-radius:999px;border:1px solid rgba(20,164,207,.14);background:#fff;display:flex;align-items:center;justify-content:center;color:#0b2f2a;font-weight:950;}
		.icon-acc-panel{display:none;padding:0 14px 14px;}
		.icon-acc.is-open .icon-acc-panel{display:block;}
		.icon-acc.is-open .icon-acc-caret{transform:rotate(180deg);transition:transform .12s ease;}

		/* Default -> Situational strip */
		.shift-strip{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;}
		.shift-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;border:1px solid rgba(20,164,207,.14);background:#f9fafb;font-weight:950;color:#0b2f2a;font-size:12px;}
		.shift-arrow{font-weight:950;color:#6b7280;}
		.shift-note{font-size:12px;color:#6b7280;margin-top:8px;line-height:1.45;}

		/* Heatmap legend */
		.legend{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0 10px;}
		.legend .tag{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;border:1px solid rgba(226,232,240,.95);background:#fff;font-weight:900;font-size:11px;color:#0b2f2a;}
		.legend .sw{width:10px;height:10px;border-radius:999px;display:inline-block;}
		.legend .sw.low{background:#fef2f2;border:1px solid rgba(185,28,28,.18);}
		.legend .sw.mid{background:#fffbeb;border:1px solid rgba(146,64,14,.18);}
		.legend .sw.high{background:#ecfdf5;border:1px solid rgba(22,101,52,.18);}

		/* NEW: Wheel card */
		.wheel-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px;align-items:center;}
		@media(max-width:860px){.wheel-grid{grid-template-columns:1fr;}}
		.wheel-frame{border:1px solid rgba(226,232,240,.95);border-radius:18px;background:#fff;padding:14px;display:flex;align-items:center;justify-content:center;overflow:hidden;}
		.wheel-frame img{
		width:min(300px,100%);
		height:auto;
		display:block;
		filter:drop-shadow(0 10px 22px rgba(0,0,0,.10));
		}

		<?php if($is_pdf): ?>
		*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}
		html,body{background:#ffffff!important;}
		.pdf-break{page-break-before:always!important;}
		.pdf-keep{page-break-inside:avoid!important;break-inside:avoid!important;}
		.icon-profiler-wrap{padding:0!important;margin:0!important;border-radius:0!important;background:#ffffff!important;}
		.icon-card:hover{transform:none!important;box-shadow:none!important;transition:none!important;}
		.icon-hero:after{display:none!important;}
		.icon-card{box-shadow:none!important;border-radius:12px!important;border:1px solid var(--pdf-line)!important;background:#ffffff!important;position:relative!important;margin-bottom:12px!important;padding:14px 14px 12px 18px!important;page-break-inside:avoid!important;break-inside:avoid!important;overflow:visible!important;}
		.icon-card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:6px;background:var(--pdf-accent);}
		.fill{background:var(--pdf-accent)!important;opacity:1!important;}
		.track{background:#e5e7eb!important;}
		.map-tag .mini-dot{background:var(--pdf-accent)!important;box-shadow:none!important;}
		.deep-box,.tile,.map-card,.bars,.icon-acc,.icon-acc-panel,.tile-grid,.deep-quad,.grid2,.map-head,.deep-head,.chips,.row,.shift-strip,.legend,.wheel-grid,.wheel-frame{page-break-inside:avoid!important;break-inside:avoid!important;}
		.map-head{display:block!important;}.map-sub{display:block!important;margin-bottom:10px!important;}.map-tags{justify-content:flex-start!important;margin-top:6px!important;}
		.icon-acc-panel{display:block!important;}
		.icon-acc.pdf-page{page-break-before:always!important;}
		.section-title,.h1,h4{page-break-after:avoid!important;}
		thead{display:table-header-group;}tr{page-break-inside:avoid;}
		<?php endif; ?>
		</style>

		<!-- HERO -->
		<div class="icon-card icon-hero">
			<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">
				<div style="min-width:0;max-width:820px;">
					<div style="display:flex;gap:10px;align-items:center;margin-bottom:6px;">
						<?php if ( ! empty( $brand_logo_src ) ): ?>
							<img src="<?php echo esc_attr($brand_logo_src); ?>" alt="ICON" style="width:42px;height:auto;border-radius:12px;border:1px solid rgba(226,232,240,.95);background:#fff;padding:6px;"/>
						<?php endif; ?>
						<div class="h1" style="margin:0;">ICON Profiler</div>
					</div>

					<?php
					$display_style = $types[$dominant_key]['label'];
					if($secondary_key){
						$display_style .= ' / '.$types[$secondary_key]['label'];
					}
					?>

					<p class="sub" style="font-size:15px;color:#0b2f2a;font-weight:700;margin-top:4px;">
					<?php echo esc_html($participant_name); ?> — <?php echo esc_html($display_style); ?> leadership profile
					</p>

					<p class="sub" style="margin-top:6px;max-width:720px;">
					This report translates behavioural patterns into practical leadership actions, communication tendencies, and development priorities.
					</p>

					<div class="chips" style="margin-top:14px;">
						<?php if($client_name):?><span class="chip2">Client <strong><?php echo esc_html($client_name);?></strong></span><?php endif;?>
						<?php if($project_name):?><span class="chip2">Project <strong><?php echo esc_html($project_name);?></strong></span><?php endif;?>
						<?php if($participant_role):?><span class="chip2">Role <strong><?php echo esc_html($participant_role);?></strong></span><?php endif;?>
						<span class="chip2">Raters <strong><?php echo (int)$num_raters;?></strong></span>
						<span class="chip2 chip3">Software <strong><?php echo esc_html($software_tier); ?></strong></span>

						<?php if(!is_null($overall_avg)):?>
						<span class="chip">Score <strong><?php echo esc_html(number_format((float)$overall_avg,1));?></strong> / 7</span>
						<?php endif;?>
					</div>
				</div>
			</div>
		</div>

		<!-- AT-A-GLANCE DASHBOARD -->
		<div class="icon-card">
			<div class="section-title">At a glance</div>
			<p class="p" style="margin-bottom:10px;color:#6b7280;">
				A quick visual summary of your profile signal, pace/challenge, and how consistent your style looks across contexts.
			</p>

			<?php
				$gap_to_second = null;
				if ( isset($ranked[0], $ranked[1]) ) {
					$gap_to_second = (float)$ranked[0]['avg'] - (float)$ranked[1]['avg'];
				}
				$secondary_state = ($secondary_key ? 'Secondary detected' : 'No close secondary');
				$secondary_note  = ($secondary_key ? 'Close blend' : 'Clear dominant');

				$stability_badge = 'Balanced';
				$stability_note  = 'Your signal appears relatively consistent across contexts.';
				if ( isset($style_lenses[$dominant_key]['q1'],$style_lenses[$dominant_key]['q2'],$style_lenses[$dominant_key]['q3']) && $style_lenses[$dominant_key]['q1'] !== null ) {
					$a = array(
						(float)$style_lenses[$dominant_key]['q1'],
						(float)$style_lenses[$dominant_key]['q2'],
						(float)$style_lenses[$dominant_key]['q3'],
					);
					$span = max($a) - min($a);
					if ( $span >= 0.65 ) { $stability_badge='High shift'; $stability_note='Your style signal changes noticeably between everyday and higher-stakes contexts.'; }
					elseif ( $span >= 0.35 ) { $stability_badge='Moderate shift'; $stability_note='You show some flex depending on pressure and visibility.'; }
					else { $stability_badge='Stable'; $stability_note='Your style signal stays consistent across contexts, which builds predictability.'; }
				}

				$overall_pct = !is_null($overall_avg) ? (int)round((max(0,min(7,(float)$overall_avg))/7)*100) : 0;
			?>

			<div class="dash-grid">
				<div class="kpi">
					<p class="k">Primary style</p>
					<p class="v"><?php echo esc_html($types[$dominant_key]['label']);?></p>
					<p class="s"><?php echo esc_html($style_knowledge[$dominant_key]['headline'] ?? ''); ?></p>
					<div class="kpi-mini">
						<span class="pill"><span class="dot"></span>Raters: <strong><?php echo (int)$num_raters;?></strong></span>
						<span class="pill"><span class="dot"></span>Tier: <strong><?php echo esc_html($software_tier); ?></strong></span>
						<?php if(!is_null($overall_avg)): ?>
							<span class="pill"><span class="dot"></span>Overall: <strong><?php echo esc_html(number_format((float)$overall_avg,1));?></strong> / 7</span>
						<?php endif; ?>
					</div>
					<?php if(!is_null($overall_avg)): ?>
						<div class="meter"><span style="width:<?php echo (int)$overall_pct; ?>%;"></span></div>
					<?php endif; ?>
				</div>

				<div class="kpi">
					<p class="k">Secondary proximity</p>
					<p class="v"><?php echo $secondary_key ? esc_html($types[$secondary_key]['label']) : '—'; ?></p>
					<p class="s">
						<?php echo esc_html($secondary_state); ?>
						<?php if(!is_null($gap_to_second)): ?>
							• Gap: <strong><?php echo esc_html(number_format((float)$gap_to_second,2)); ?></strong>
						<?php endif; ?>
						<br><?php echo esc_html($secondary_note); ?>
					</p>
					<div class="kpi-mini">
						<span class="pill"><span class="dot"></span><?php echo esc_html($secondary_key ? 'Blend present' : 'Single-dominant'); ?></span>
						<?php if($secondary_key): ?>
							<span class="pill"><span class="dot"></span>Close threshold: <strong>0.35</strong></span>
						<?php endif; ?>
					</div>
				</div>

				<div class="kpi">
					<p class="k">Pace & challenge</p>
					<p class="v"><?php echo esc_html($pace_label); ?></p>
					<p class="s"><?php echo esc_html($challenge_label); ?></p>

					<p class="k" style="margin-top:10px;">Pace</p>
					<div class="meter"><span style="width:<?php echo (int)$pace_pct; ?>%;"></span></div>

					<p class="k" style="margin-top:10px;">Challenge</p>
					<div class="meter"><span style="width:<?php echo (int)$challenge_pct; ?>%;"></span></div>

					<p class="s" style="margin-top:10px;"><strong><?php echo esc_html($stability_badge); ?>:</strong> <?php echo esc_html($stability_note); ?></p>
				</div>
			</div>
		</div>

		<!-- LEADERSHIP PROFILE -->
		<div class="icon-card">
			<div class="section-title">Your leadership profile</div>
			<p class="p" style="margin-bottom:12px;"><?php echo esc_html($first_name);?>, your profile is calculated from your results pattern. It shows your natural default, plus a secondary style if it is close. The goal is not to change who you are, but to help you use your strengths deliberately and flex when the situation demands it.</p>
			<div class="prof-top">
				<div class="deep-box">
					<div class="deep-head">
						<?php if(!empty($style_icon_src[$dominant_key])):?><img src="<?php echo esc_attr($style_icon_src[$dominant_key]);?>" alt="<?php echo esc_attr($types[$dominant_key]['label']);?>"><?php endif;?>
						<div style="min-width:0;">
							<p class="deep-title" style="margin:0;">Primary style: <?php echo esc_html($types[$dominant_key]['label']);?></p>
							<?php if($secondary_key):?>
								<p class="p" style="margin:4px 0 0;color:#065f46;font-size:12px;">Secondary style: <strong><?php echo esc_html($types[$secondary_key]['label']);?></strong></p>
							<?php else:?>
								<p class="p" style="margin:4px 0 0;color:#6b7280;font-size:12px;">No close secondary detected. This usually means your dominant style shows more consistently.</p>
							<?php endif;?>
							<p class="p" style="margin:6px 0 0;color:#6b7280;font-size:12px;">Derived from the first 12 competencies in framework order: 1–3 Driver, 4–6 Supporter, 7–9 Thinker, 10–12 Connector.</p>
						</div>
					</div>
					<?php $dom_bank = isset($style_knowledge[$dominant_key]) ? $style_knowledge[$dominant_key] : null; ?>
					<?php if($dom_bank):?>
						<div class="tile-grid" style="margin-top:10px;">
							<div class="tile"><p class="k">Headline</p><p class="v"><?php echo esc_html($dom_bank['headline']);?></p></div>
							<div class="tile"><p class="k">So what</p><p class="v"><?php echo esc_html($dom_bank['so_what']);?></p></div>
						</div>
						<div class="tile" style="margin-top:12px;"><p class="k">Your style in one paragraph</p><p class="v"><?php echo esc_html($dom_bank['core']);?></p></div>
						<div class="tile-grid" style="margin-top:12px;">
							<div class="tile"><p class="k">Where you tend to shine</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom_bank['best_env'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>
							<div class="tile"><p class="k">High-return development moves</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom_bank['development_moves'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>
						</div>
					<?php endif;?>
				</div>
				<div class="bars screen-only">
					<h4>Style comparison (1 to 7)</h4>
					<?php foreach(array('CONNECTOR','DRIVER','SUPPORTER','THINKER') as $k):
						$avg=$types[$k]['avg']; $pct=$score_to_pct($avg);?>
						<div class="row">
							<div class="name"><?php echo esc_html($types[$k]['label']);?></div>
							<div class="track"><div class="fill" style="width:<?php echo(int)$pct;?>%;"></div></div>
							<div class="score"><?php echo $avg===null?'n/a':esc_html(number_format((float)$avg,1).' / 7');?></div>
						</div>
					<?php endforeach;?>
				</div>
				<?php if($is_pdf):?><div class="bars pdf-only" style="display:none;"></div><?php endif;?>
			</div>
		</div>

		<?php if($is_pdf):?>
		<div class="icon-card pdf-break">
			<div class="section-title">Leadership style comparison</div>
			<p class="p" style="margin-bottom:10px;color:#6b7280;">Style averages on a 1 to 7 scale.</p>
			<div class="bars pdf-keep">
				<h4>Style comparison (1 to 7)</h4>
				<?php foreach(array('CONNECTOR','DRIVER','SUPPORTER','THINKER') as $k):
					$avg=$types[$k]['avg']; $pct=$score_to_pct($avg);?>
					<div class="row">
						<div class="name"><?php echo esc_html($types[$k]['label']);?></div>
						<div class="track"><div class="fill" style="width:<?php echo(int)$pct;?>%;"></div></div>
						<div class="score"><?php echo $avg===null?'n/a':esc_html(number_format((float)$avg,1).' / 7');?></div>
					</div>
				<?php endforeach;?>
			</div>
		</div>
		<?php endif;?>

		<?php if ( $has_disc ): ?>
		<div class="icon-card <?php echo $is_pdf ? 'pdf-break' : ''; ?>">

			<div class="section-title">How behavioural patterns work</div>
			<p class="p" style="margin-bottom:12px;">
				People tend to lean toward one or two natural behavioural patterns. None are better than others — each brings strengths and blind spots.
				Your results estimate which patterns are most visible in your day-to-day leadership behaviour.
			</p>

			<div class="tile-grid">
				<div class="tile"><p class="k">Driving style</p><p class="v">Decisive, outcome-focused, and comfortable taking ownership. Prefers speed, clarity, and action.</p></div>
				<div class="tile"><p class="k">Connecting style</p><p class="v">Engaging, persuasive, and people-focused. Builds energy, collaboration, and stakeholder alignment.</p></div>
				<div class="tile"><p class="k">Supporting style</p><p class="v">Steady, dependable, and cooperative. Creates stability, trust, and team cohesion.</p></div>
				<div class="tile"><p class="k">Thinking style</p><p class="v">Analytical, structured, and quality-focused. Improves decisions through logic and careful evaluation.</p></div>
			</div>

			<div class="tile" style="margin-top:12px;">
				<p class="k">Important</p>
				<p class="v">Everyone uses all four patterns. This report highlights the ones you use most naturally and how they may shift depending on the situation.</p>
			</div>

			<div class="section-title" style="margin-top:14px;">Behavioural tendencies</div>

			<p class="p" style="margin-bottom:10px;">
				This section translates your behavioural tendencies into everyday workplace behaviour. It helps explain how others may experience your communication, decision-making, and reactions under pressure.
				<?php if ( $disc['source'] === 'estimated' ): ?>
					<span style="color:#6b7280;"> </span>
				<?php endif; ?>
			</p>

			<div class="tile" style="margin-top:14px;">
				<p class="k">Default vs situational behaviour</p>
				<p class="v">
					<strong>Default</strong> describes your most effortless behaviour.<br>
					<strong>Situational</strong> reflects how you are adjusting to current expectations or environment.
				</p>

				<div class="shift-strip">
					<?php
					$nat = (string)$disc['natural'];
					$adp = (string)$disc['adapted'];

					$nat_words = $disc_code_to_words( $nat );
					$adp_words = $disc_code_to_words( $adp );
					?>
					<span class="shift-pill">Default: <strong><?php echo esc_html( $nat_words ? implode(' + ', $nat_words) : 'Not set' ); ?></strong></span>
					<span class="shift-arrow">→</span>
					<span class="shift-pill">Situational: <strong><?php echo esc_html( $adp_words ? implode(' + ', $adp_words) : 'Not set' ); ?></strong></span>
				</div>

				<p class="shift-note"><?php echo esc_html($shift_text); ?></p>
			</div>

			<div class="tile-grid" style="margin-top:12px;">
				<?php foreach($letters as $L): if(isset($disc_letter_library[$L])): ?>
				<div class="tile">
					<p class="k"><?php echo esc_html($style_labels[$L] ?? $L); ?> style needs</p>
					<ul class="klist">
						<?php foreach($disc_letter_library[$L]['needs'] as $n): ?>
							<li><?php echo esc_html($n); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; endforeach; ?>
			</div>

			<div class="tile-grid" style="margin-top:12px;">
				<?php foreach($letters as $L): if(isset($disc_letter_library[$L])): $lib=$disc_letter_library[$L]; ?>
				<div class="tile">
					<p class="k"><?php echo esc_html($style_labels[$L] ?? $L); ?> under pressure</p>
					<p class="v"><strong>Triggers:</strong> <?php echo esc_html(implode(', ',$lib['stress_triggers'])); ?></p>
					<p class="v"><strong>Behaviour:</strong> <?php echo esc_html($lib['under_pressure']); ?></p>
					<p class="v"><strong>Best response:</strong> <?php echo esc_html($lib['best_response']); ?></p>
				</div>
				<?php endif; endforeach; ?>
			</div>

			<div class="tile-grid" style="margin-top:12px;">
				<?php foreach($letters as $L): if(isset($disc_letter_library[$L])): $lib=$disc_letter_library[$L]; ?>
				<div class="tile">
					<p class="k"><?php echo esc_html($style_labels[$L] ?? $L); ?> communication</p>
					<p class="v"><?php echo esc_html($lib['communication']); ?></p>
					<p class="k" style="margin-top:8px;">Conflict approach</p>
					<p class="v"><?php echo esc_html($lib['conflict']); ?></p>
				</div>
				<?php endif; endforeach; ?>
			</div>

			<div class="tile" style="margin-top:12px;">
				<p class="k">Leadership translation</p>
				<ul class="klist">
					<?php foreach($letters as $L): if(isset($disc_letter_library[$L])): ?>
						<li><?php echo esc_html($disc_letter_library[$L]['leadership']); ?></li>
					<?php endif; endforeach; ?>
				</ul>
			</div>

			<div class="tile" style="margin-top:12px;">
				<p class="k">Behaviour focus for the next 30 days</p>
				<ul class="klist">
					<li><strong>Start:</strong> Ask one extra clarifying question before responding.</li>
					<li><strong>Stop:</strong> Defaulting to your first instinct under pressure.</li>
					<li><strong>Continue:</strong> Using your natural strengths intentionally.</li>
					<li><strong>If you notice stress:</strong> Slow pace, summarise goals, reset expectations.</li>
				</ul>
			</div>

			<?php if ( $disc_bank ): ?>
				<div class="tile" style="margin-top:12px;">
					<p class="k">In one paragraph</p>
					<p class="v"><?php echo esc_html($disc_bank['core']);?></p>
				</div>

				<div class="tile-grid" style="margin-top:12px;">
					<div class="tile">
						<p class="k">Strengths</p>
						<ul class="klist" style="margin:0;"><?php foreach((array)$disc_bank['strengths'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul>
					</div>
					<div class="tile">
						<p class="k">Watch-outs</p>
						<ul class="klist" style="margin:0;"><?php foreach((array)$disc_bank['watch_outs'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul>
					</div>
				</div>

				<div class="tile-grid" style="margin-top:12px;">
					<div class="tile">
						<p class="k">Communication do</p>
						<ul class="klist" style="margin:0;"><?php foreach((array)$disc_bank['comms_do'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul>
					</div>
					<div class="tile">
						<p class="k">How to work with you</p>
						<ul class="klist" style="margin:0;"><?php foreach((array)$disc_bank['how_to_work_with'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul>
					</div>
				</div>
			<?php endif; ?>

		</div>
		<?php endif; ?>

		<!-- MAP -->
		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">
			<div class="section-title">Your position on the Profiler map</div>
			<div class="map-card">
				<div class="map-head">
					<p class="map-sub">The dot is anchored to your dominant style. If a secondary style is close, it blends slightly toward it, but never crosses quadrants.</p>
					<div class="map-tags">
						<span class="map-tag"><span class="mini-dot"></span> Current pattern</span>
						<span class="map-tag">Primary: <strong><?php echo esc_html($types[$dominant_key]['label']);?></strong></span>
						<?php if($secondary_key):?><span class="map-tag">Secondary: <strong><?php echo esc_html($types[$secondary_key]['label']);?></strong></span><?php endif;?>
					</div>
				</div>
				<div style="display:flex;justify-content:center;overflow:auto;">
					<?php if($is_pdf && !empty($map_png_data_uri)):?>
						<img src="<?php echo esc_attr($map_png_data_uri);?>" alt="Profiler map" style="display:block;margin:0 auto;width:720px;max-width:100%;height:auto;border:1px solid #e5e7eb;border-radius:12px;background:#fff;"/>
					<?php else:?>
						<svg width="<?php echo(int)$svg_w;?>" height="<?php echo(int)$svg_h;?>" viewBox="0 0 <?php echo(int)$svg_w;?> <?php echo(int)$svg_h;?>" role="img" aria-label="Profiler map">
							<defs>
								<linearGradient id="gBorder" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#14a4cf" stop-opacity="0.70"/><stop offset="100%" stop-color="#15a06d" stop-opacity="0.70"/></linearGradient>
								<linearGradient id="gSoft" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#14a4cf" stop-opacity="0.10"/><stop offset="100%" stop-color="#15a06d" stop-opacity="0.10"/></linearGradient>
								<filter id="cardShadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="10" stdDeviation="12" flood-color="#0b2f2a" flood-opacity="0.14"/></filter>
								<filter id="badgeShadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="8" stdDeviation="10" flood-color="#0b2f2a" flood-opacity="0.16"/></filter>
								<filter id="dotGlow" x="-60%" y="-60%" width="220%" height="220%"><feGaussianBlur stdDeviation="7" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
							</defs>
							<g filter="url(#cardShadow)">
								<rect x="10" y="10" width="<?php echo(int)($svg_w-20);?>" height="<?php echo(int)($svg_h-20);?>" rx="22" ry="22" fill="#ffffff" stroke="url(#gBorder)" stroke-width="3"/>
								<rect x="22" y="22" width="<?php echo(int)($svg_w-44);?>" height="<?php echo(int)($svg_h-44);?>" rx="18" ry="18" fill="url(#gSoft)" stroke="rgba(226,232,240,.95)" stroke-width="1"/>
							</g>
							<rect x="<?php echo(int)$pad;?>" y="<?php echo(int)$pad;?>" width="<?php echo(int)($cx-$pad);?>" height="<?php echo(int)($cy-$pad);?>" fill="rgba(20,164,207,.07)"/>
							<rect x="<?php echo(int)$cx;?>" y="<?php echo(int)$pad;?>" width="<?php echo(int)($svg_w-$cx-$pad);?>" height="<?php echo(int)($cy-$pad);?>" fill="rgba(21,160,109,.07)"/>
							<rect x="<?php echo(int)$pad;?>" y="<?php echo(int)$cy;?>" width="<?php echo(int)($cx-$pad);?>" height="<?php echo(int)($svg_h-$cy-$pad);?>" fill="rgba(21,160,109,.05)"/>
							<rect x="<?php echo(int)$cx;?>" y="<?php echo(int)$cy;?>" width="<?php echo(int)($svg_w-$cx-$pad);?>" height="<?php echo(int)($svg_h-$cy-$pad);?>" fill="rgba(20,164,207,.05)"/>
							<line x1="<?php echo(int)$cx;?>" y1="<?php echo(int)$pad;?>" x2="<?php echo(int)$cx;?>" y2="<?php echo(int)($svg_h-$pad);?>" stroke="rgba(148,163,184,.62)" stroke-width="2"/>
							<line x1="<?php echo(int)$pad;?>" y1="<?php echo(int)$cy;?>" x2="<?php echo(int)($svg_w-$pad);?>" y2="<?php echo(int)$cy;?>" stroke="rgba(148,163,184,.62)" stroke-width="2"/>
							<text x="<?php echo(int)($pad+8);?>" y="<?php echo(int)($cy-12);?>" font-size="12" font-weight="900" fill="#0b2f2a">People</text>
							<text x="<?php echo(int)($svg_w-$pad-46);?>" y="<?php echo(int)($cy-12);?>" font-size="12" font-weight="900" fill="#0b2f2a">Task</text>
							<text x="<?php echo(int)($cx+10);?>" y="<?php echo(int)($pad+20);?>" font-size="12" font-weight="900" fill="#0b2f2a">Pace</text>
							<text x="<?php echo(int)($cx+10);?>" y="<?php echo(int)($svg_h-$pad-8);?>" font-size="12" font-weight="900" fill="#0b2f2a">Steady</text>
							<?php foreach(array('CONNECTOR'=>array($tlx,$tly),'DRIVER'=>array($trx,$try),'SUPPORTER'=>array($blx,$bly),'THINKER'=>array($brx,$bry)) as $sk=>$pos):
								$bx=$pos[0]; $by=$pos[1];
								$border_color = in_array($sk,array('CONNECTOR','THINKER')) ? 'rgba(20,164,207,.22)' : 'rgba(21,160,109,.22)';
							?>
								<g filter="url(#badgeShadow)">
									<circle cx="<?php echo(int)$bx;?>" cy="<?php echo(int)$by;?>" r="<?php echo(int)$badgeR;?>" fill="rgba(255,255,255,.96)" stroke="<?php echo $border_color;?>" stroke-width="2"/>
									<?php if(!empty($style_icon_src[$sk])):?><image href="<?php echo esc_attr($style_icon_src[$sk]);?>" x="<?php echo(int)($bx-33);?>" y="<?php echo(int)($by-33);?>" width="66" height="66" preserveAspectRatio="xMidYMid meet"/><?php endif;?>
								</g>
								<text x="<?php echo(int)$bx;?>" y="<?php echo(int)($by+60);?>" font-size="11" font-weight="950" fill="#0b2f2a" text-anchor="middle"><?php echo esc_html($types[$sk]['label']);?></text>
							<?php endforeach;?>
							<circle cx="<?php echo(int)$dot_x;?>" cy="<?php echo(int)$dot_y;?>" r="18" fill="rgba(20,164,207,.25)" filter="url(#dotGlow)"/>
							<circle cx="<?php echo(int)$dot_x;?>" cy="<?php echo(int)$dot_y;?>" r="10" fill="#0f766e"/>
						</svg>
					<?php endif;?>
				</div>
			</div>
		</div>

		<!-- NEW: WHEEL (Rotating GIF) -->
		<div class="icon-card <?php echo $is_pdf ? 'pdf-break' : ''; ?>">
			<div class="section-title">Profile Map how to use</div>
			<p class="p" style="margin-bottom:10px;color:#6b7280;">
				A visual reference of the four behavioural patterns.
			</p>
			<div class="wheel-grid">
				<div class="wheel-frame">
					<?php if ( ! empty( $wheel_src ) ): ?>
						<img src="<?php echo esc_attr($wheel_src); ?>" alt="Profiler wheel">
					<?php else: ?>
						<div class="p" style="color:#6b7280;">Wheel image unavailable.</div>
					<?php endif; ?>
				</div>
				<div class="deep-box">
					<p class="p" style="font-weight:950;margin-bottom:6px;">How to use</p>
					<ul class="klist">
						<li><strong>Primary</strong> = your most visible default pattern.</li>
						<li><strong>Secondary</strong> = a close pattern you may switch into in certain contexts.</li>
						<li>In mixed teams, performance improves when you flex <em>pace</em> and <em>challenge</em> to match the moment.</li>
						<li>None are “better” — the goal is deliberate use, not changing personality.</li>
					</ul>
				</div>
			</div>
		</div>

		<!-- WHAT YOUR RESULTS SUGGEST -->
		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">
			<div class="section-title">What your results suggest</div>
			<p class="p" style="margin-bottom:10px;">This section translates your profile into practical, day-to-day leadership behaviours: how you come across, what you naturally do well, what tends to trip you up, and exactly what to do next to level up.</p>
			<?php $dom=$dom_bank; $sec=($secondary_key&&isset($style_knowledge[$secondary_key]))?$style_knowledge[$secondary_key]:null; ?>
			<div class="deep-box pdf-keep">
				<div class="deep-head">
					<?php if(!empty($style_icon_src[$dominant_key])):?><img src="<?php echo esc_attr($style_icon_src[$dominant_key]);?>" alt="<?php echo esc_attr($types[$dominant_key]['label']);?>"><?php endif;?>
					<div>
						<p class="deep-title" style="margin:0;">Dominant style: <?php echo esc_html($types[$dominant_key]['label']);?></p>
						<p class="p" style="margin:4px 0 0;color:#6b7280;font-size:12px;">This is the pattern people are most likely to experience as your "default".</p>
					</div>
				</div>
				<?php if($dom):?>
					<div class="tile-grid">
						<div class="tile"><p class="k">Core signal</p><p class="v"><?php echo esc_html($dom['core']);?></p></div>
						<div class="tile"><p class="k">Under pressure</p><p class="v"><?php echo esc_html($dom['pressure']);?></p></div>
					</div>
					<div class="tile-grid" style="margin-top:12px;">
						<div class="tile"><p class="k">Likely strengths</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom['strengths'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>
						<div class="tile"><p class="k">Watch-outs</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom['watch_outs'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>
					</div>
					<?php if($is_pdf):?>
						<div class="tile pdf-keep" style="margin-top:12px;"><p class="k">Communication do</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom['comms_do'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>
						<div class="pdf-break"></div>
						<div class="tile pdf-keep" style="margin-top:12px;"><p class="k">Communication avoid</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom['comms_dont'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>
					<?php else:?>
						<div class="tile-grid" style="margin-top:12px;">
							<div class="tile"><p class="k">Communication do</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom['comms_do'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>
							<div class="tile"><p class="k">Communication avoid</p><ul class="klist" style="margin:0;"><?php foreach((array)$dom['comms_dont'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>
						</div>
					<?php endif;?>
				<?php endif;?>
			</div>
			<?php if($secondary_key):?>
				<div class="deep-box pdf-keep" style="margin-top:12px;">
					<div class="deep-head">
						<?php if(!empty($style_icon_src[$secondary_key])):?><img src="<?php echo esc_attr($style_icon_src[$secondary_key]);?>" alt="<?php echo esc_attr($types[$secondary_key]['label']);?>"><?php endif;?>
						<div>
							<p class="deep-title" style="margin:0;">Secondary style: <?php echo esc_html($types[$secondary_key]['label']);?></p>
							<p class="p" style="margin:4px 0 0;color:#6b7280;font-size:12px;">This is your "switch". It often appears in specific situations (stakeholders, time pressure, ambiguity, conflict, or high standards).</p>
						</div>
					</div>
					<?php if($sec):?>
						<div class="tile-grid">
							<div class="tile"><p class="k">When you may switch into it</p><p class="v">You may lean into <?php echo esc_html($types[$secondary_key]['label']);?> when the moment demands <?php echo esc_html(strtolower($sec['headline']));?>.</p></div>
							<div class="tile"><p class="k">How to use it well</p><ul class="klist" style="margin:0;"><li>Use it as a tool, not a mask. Keep your dominant strengths visible.</li><li>Use one behaviour from this style to balance the room.</li><li>Return to your default once alignment and next steps are clear.</li></ul></div>
						</div>
					<?php endif;?>
				</div>
			<?php endif;?>
			<?php if($is_pdf && isset($style_knowledge[$dominant_key]['so_what'])):?>
				<div class="icon-card pdf-break" style="margin-top:12px;">
					<div class="section-title">Your next best step</div>
					<div class="tile pdf-keep"><p class="k">High ROI</p><p class="v"><?php echo esc_html($style_knowledge[$dominant_key]['so_what']);?></p></div>
				</div>
			<?php else:?>
				<div class="tile screen-only" style="margin-top:12px;"><p class="k">Your next best step (high ROI)</p><p class="v"><?php echo $dom?esc_html($dom['so_what']):'';?></p></div>
			<?php endif;?>
		</div>

		<!-- PROFILER SECTIONS -->
		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">
			<div class="section-title">Profiler sections</div>
			<p class="p" style="margin-bottom:10px;color:#6b7280;">On screen, these expand and collapse. In the PDF, they start on new pages and are fully shown.</p>
			<?php foreach(array('DRIVER','CONNECTOR','SUPPORTER','THINKER') as $i=>$sk):
				$bank=isset($style_knowledge[$sk])?$style_knowledge[$sk]:null;
				if(!$bank) continue;
				$is_dom=($sk===$dominant_key); $is_sec=($secondary_key&&$sk===$secondary_key);
				$tag=$is_dom?'Your dominant style':($is_sec?'Your secondary style':'Profile reference');
				$pdf_page_class=($is_pdf&&$i>0)?'pdf-page':'';
			?>
				<div class="icon-acc <?php echo($is_dom&&!$is_pdf)?'is-open':'';?> <?php echo esc_attr($pdf_page_class);?>">
					<button type="button" class="icon-acc-btn screen-only">
						<span class="icon-acc-icon"><?php if(!empty($style_icon_src[$sk])):?><img src="<?php echo esc_attr($style_icon_src[$sk]);?>" alt="<?php echo esc_attr($types[$sk]['label']);?>"><?php endif;?></span>
						<span style="min-width:0;"><p class="icon-acc-title"><?php echo esc_html($types[$sk]['label']);?></p><p class="icon-acc-sub"><?php echo esc_html($bank['headline']);?> • <?php echo esc_html($tag);?></p></span>
						<span class="icon-acc-caret">⌄</span>
					</button>
					<div class="pdf-only" style="padding:14px 14px 0;">
						<div style="display:flex;gap:12px;align-items:center;">
							<span class="icon-acc-icon"><?php if(!empty($style_icon_src[$sk])):?><img src="<?php echo esc_attr($style_icon_src[$sk]);?>" alt="<?php echo esc_attr($types[$sk]['label']);?>"><?php endif;?></span>
							<div><p class="icon-acc-title" style="margin:0;"><?php echo esc_html($types[$sk]['label']);?></p><p class="icon-acc-sub" style="margin:2px 0 0;"><?php echo esc_html($bank['headline']);?> • <?php echo esc_html($tag);?></p></div>
						</div>
					</div>
					<div class="icon-acc-panel">
						<div class="tile" style="margin-top:12px;"><p class="k">In a sentence</p><p class="v"><?php echo esc_html($bank['core']);?></p></div>
						<div class="tile-grid" style="margin-top:12px;">
							<div class="tile"><p class="k">Strengths</p><ul class="klist" style="margin:0;"><?php foreach((array)$bank['strengths'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>
							<div class="tile"><p class="k">Watch-outs</p><ul class="klist" style="margin:0;"><?php foreach((array)$bank['watch_outs'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>
						</div>
						<div class="tile" style="margin-top:12px;"><p class="k">Development moves</p><ul class="klist" style="margin:0;"><?php foreach((array)$bank['development_moves'] as $it):?><li><?php echo esc_html($it);?></li><?php endforeach;?></ul></div>
					</div>
				</div>
			<?php endforeach;?>
			<?php if(!$is_pdf):?>
				<script>(function(){var a=document.querySelectorAll('.icon-acc');if(!a||!a.length)return;a.forEach(function(b){var c=b.querySelector('.icon-acc-btn');if(!c)return;c.addEventListener('click',function(){b.classList.toggle('is-open');});});})();</script>
			<?php endif;?>
		</div>

		<!-- CULTURE LENS -->
		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">
			<div class="section-title">Culture lens</div>
			<p class="p" style="margin-bottom:10px;">This is a practical read of the leadership signal you tend to project in teams. It looks at two things people feel quickly: <strong>pace</strong> and <strong>challenge</strong>.</p>
			<?php
			$render_lens_box = function($label,$pct,$detail,$watch,$title) {
				echo '<div class="deep-box pdf-keep">';
				echo '<p class="p" style="font-weight:950;margin-bottom:6px;">'.esc_html($title).' signal</p>';
				echo '<p class="p" style="margin:0 0 8px;color:#6b7280;font-size:12px;">'.esc_html($label).'</p>';
				echo '<div class="track" style="height:10px;"><div class="fill" style="width:'.(int)$pct.'%;"></div></div>';
				echo '<div class="tile" style="margin-top:10px;"><p class="k">What '.strtolower($title).' means</p><ul class="klist" style="margin:0;">';
				foreach($detail as $it) echo '<li>'.esc_html($it).'</li>';
				echo '</ul></div><div class="tile" style="margin-top:10px;"><p class="k">Watch-outs and adjustments</p><ul class="klist" style="margin:0;">';
				foreach($watch as $it) echo '<li>'.esc_html($it).'</li>';
				echo '</ul></div></div>';
			};
			?>
			<?php if($is_pdf):?>
				<?php $render_lens_box($pace_label,$pace_pct,$pace_detail,$pace_watch,'Pace');?>
				<div class="icon-card pdf-break">
					<div class="section-title">Culture lens (continued)</div>
					<?php $render_lens_box($challenge_label,$challenge_pct,$challenge_detail,$challenge_watch,'Challenge');?>
				</div>
			<?php else:?>
				<div class="deep-quad">
					<?php $render_lens_box($pace_label,$pace_pct,$pace_detail,$pace_watch,'Pace');?>
					<?php $render_lens_box($challenge_label,$challenge_pct,$challenge_detail,$challenge_watch,'Challenge');?>
				</div>
			<?php endif;?>
		</div>

		<!-- PRESSURE SHIFT -->
		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">
			<div class="section-title">Pressure shift insight</div>
			<?php if(empty($pressure_shift['headline'])):?>
				<p class="p" style="color:#6b7280;">Not enough lens data yet to calculate a pressure shift insight.</p>
			<?php else:?>
				<div class="deep-box pdf-keep" style="margin-top:10px;">
					<p class="p" style="font-weight:950;margin-bottom:6px;"><?php echo esc_html($pressure_shift['headline']);?></p>
					<p class="p" style="margin:0 0 10px;"><?php echo esc_html($pressure_shift['meaning']);?></p>
					<p class="p" style="margin:0;color:#6b7280;"><strong>What to do next:</strong> <?php echo esc_html($pressure_shift['do_next']);?></p>
					<?php if(isset($style_lenses[$dominant_key]['q1'],$style_lenses[$dominant_key]['q2'],$style_lenses[$dominant_key]['q3'])&&$style_lenses[$dominant_key]['q1']!==null):?>
						<div style="margin-top:10px;font-size:12px;color:#6b7280;"><strong>Dominant lens averages:</strong>
							Everyday <?php echo esc_html(number_format((float)$style_lenses[$dominant_key]['q1'],1));?>,
							Pressure <?php echo esc_html(number_format((float)$style_lenses[$dominant_key]['q2'],1));?>,
							Role-modelling <?php echo esc_html(number_format((float)$style_lenses[$dominant_key]['q3'],1));?>.
						</div>
					<?php endif;?>
				</div>
			<?php endif;?>
		</div>

		<!-- PRESSURE vs EVERYDAY GAP CHART -->
		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">
			<div class="section-title">Pressure shift by trait (visual)</div>
			<div class="gap-card">
				<div class="gap-head">
					<p class="gap-sub">
						This shows the difference between <strong>Under pressure</strong> and <strong>Everyday</strong> behaviour for each trait.
						Right side means the signal increases under pressure. Left side means it reduces.
					</p>
					<div class="map-tags">
						<span class="map-tag"><span class="mini-dot pos"></span> Increase under pressure</span>
						<span class="map-tag"><span class="mini-dot neg"></span> Decrease under pressure</span>
					</div>
				</div>

				<?php
					$gap_rows = array();
					foreach ( (array)$heatmap_rows as $r ) {
						$e = isset($r['avg_q1']) ? (float)$r['avg_q1'] : 0.0;
						$p = isset($r['avg_q2']) ? (float)$r['avg_q2'] : 0.0;
						$d = $p - $e;
						$gap_rows[] = array(
							'name'  => (string)($r['name'] ?? ''),
							'delta' => (float)$d,
						);
					}
					usort($gap_rows, function($a,$b){
						$aa = abs((float)$a['delta']); $bb = abs((float)$b['delta']);
						if ( $aa === $bb ) return 0;
						return ($aa > $bb) ? -1 : 1;
					});

					$gap_rows = array_slice($gap_rows, 0, 12);

					$maxAbs = 0.0;
					foreach($gap_rows as $gr) $maxAbs = max($maxAbs, abs((float)$gr['delta']));
					if ($maxAbs < 0.01) $maxAbs = 0.01;
				?>

				<div class="gap-table">
					<?php foreach($gap_rows as $gr):
						$delta = (float)$gr['delta'];
						$pct   = (int)round( min(1.0, abs($delta)/$maxAbs ) * 100 );
						$cls   = $delta > 0.06 ? 'pos' : ($delta < -0.06 ? 'neg' : 'zero');
						$label = ($delta > 0 ? '+' : '').number_format($delta,2);
					?>
						<div class="gap-row">
							<div class="gap-name"><?php echo esc_html($gr['name']); ?></div>

							<div class="gap-bar" aria-hidden="true">
								<?php if($delta < -0.06): ?>
									<span class="gap-left" style="width:<?php echo (int)$pct; ?>%;"></span>
								<?php elseif($delta > 0.06): ?>
									<span class="gap-right" style="width:<?php echo (int)$pct; ?>%;"></span>
								<?php endif; ?>
							</div>

							<div><span class="gap-pill <?php echo esc_attr($cls); ?>"><?php echo esc_html($label); ?></span></div>
						</div>
					<?php endforeach; ?>
				</div>

				<p class="gap-sub" style="margin-top:12px;">
					<strong>How to read this:</strong> The bigger the bar, the bigger the behavioural shift under pressure for that trait.
					Use the top items as your coaching focus because they create the biggest difference in how others experience you.
				</p>
			</div>
		</div>

		<!-- HEATMAP -->
		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">
			<div class="section-title">Trait heatmap</div>
			<p class="p" style="margin-bottom:6px;color:#6b7280;">Averages across Everyday, Under pressure, and Role-modelling (1 to 7).</p>

			<div class="legend">
				<span class="tag"><span class="sw low"></span> Low</span>
				<span class="tag"><span class="sw mid"></span> Mid</span>
				<span class="tag"><span class="sw high"></span> High</span>
			</div>

			<div style="overflow:auto;">
				<table>
					<thead><tr><th>Trait</th><th>Everyday</th><th>Pressure</th><th>Role model</th><th>Overall</th></tr></thead>
					<tbody>
						<?php foreach($heatmap_rows as $row):
							$overall=(float)$row['overall'];
							$band=$overall<3.5?'low':($overall<5.5?'mid':'high');
						?>
							<tr>
								<td style="font-weight:950;color:#0b2f2a;"><?php echo esc_html($row['name']);?></td>
								<td><span class="cell <?php echo esc_attr($band);?>"><?php echo esc_html(number_format($row['avg_q1'],1));?></span></td>
								<td><span class="cell <?php echo esc_attr($band);?>"><?php echo esc_html(number_format($row['avg_q2'],1));?></span></td>
								<td><span class="cell <?php echo esc_attr($band);?>"><?php echo esc_html(number_format($row['avg_q3'],1));?></span></td>
								<td><span class="cell <?php echo esc_attr($band);?>"><?php echo esc_html(number_format($overall,1));?></span></td>
							</tr>
						<?php endforeach;?>
					</tbody>
				</table>
			</div>
		</div>

		<!-- NARRATIVE -->
		<div class="icon-card <?php echo $is_pdf?'pdf-break':'';?>">
			<div class="section-title">Narrative feedback</div>
			<div class="deep-quad">
				<div class="deep-box pdf-keep" style="margin:0;">
					<p class="p" style="font-weight:950;margin-bottom:8px;">Perceived strengths</p>
					<?php if(empty($strengths)):?><p class="p" style="color:#6b7280;">No strengths comments recorded yet.</p>
					<?php else:?><ul class="klist"><?php foreach($strengths as $c):?><li><?php echo esc_html((string)$c);?></li><?php endforeach;?></ul><?php endif;?>
				</div>
				<div class="deep-box pdf-keep" style="margin:0;">
					<p class="p" style="font-weight:950;margin-bottom:8px;">Development opportunities</p>
					<?php if(empty($dev_opps)):?><p class="p" style="color:#6b7280;">No development comments recorded yet.</p>
					<?php else:?><ul class="klist"><?php foreach($dev_opps as $c):?><li><?php echo esc_html((string)$c);?></li><?php endforeach;?></ul><?php endif;?>
				</div>
			</div>
		</div>

		</div>
		<?php
		$screen_html = ob_get_clean();

		// ── PDF export (kept intact; only the button was removed) ──
		if ( $is_pdf ) {
			while ( ob_get_level() > 0 ) { @ob_end_clean(); }
			$autoload = dirname(dirname(dirname(__FILE__))) . '/vendor/autoload.php';
			if ( file_exists($autoload) ) require_once $autoload;

			if ( class_exists('\Dompdf\Dompdf') ) {
				$dompdf = new \Dompdf\Dompdf();
				if ( method_exists($dompdf,'set_option') ) {
					$dompdf->set_option('isHtml5ParserEnabled',true);
					$dompdf->set_option('isFontSubsettingEnabled',true);
					$dompdf->set_option('defaultFont','DejaVu Sans');
					$dompdf->set_option('isRemoteEnabled',true);
				}
				$logo_data_uri = icon_psy_fetch_image_data_uri($logo_url);
				$issued_date   = function_exists('date_i18n') ? date_i18n('j F Y') : date('j F Y');

				$pdf_header = '
				<div class="pdf-header"><div class="pdf-header-inner">
					<div class="pdf-brand">'.($logo_data_uri?'<img src="'.esc_attr($logo_data_uri).'" class="pdf-logo" alt="Icon">':'').'
					<div class="pdf-brand-text"><div class="pdf-title">ICON Profiler Report</div>
					<div class="pdf-subtitle">Participant: '.esc_html($participant_name).($participant_role?' | Role: '.esc_html($participant_role):'').' | Tier: '.esc_html($software_tier).'</div></div></div>
					<div class="pdf-meta"><div class="pdf-issued">Issued: '.esc_html($issued_date).'</div><div class="pdf-scale">Scale: 1 to 7</div></div>
				</div><div class="pdf-header-bar"></div></div>';

				$pdf_footer_stub = '<div class="pdf-footer"><div class="pdf-footer-inner"><div>Confidential - Icon Talent</div><div class="pdf-page-slot"></div></div></div>';

				$cover = '
				<div class="pdf-cover"><div class="pdf-cover-topbar"></div><div class="pdf-cover-content">
					'.($logo_data_uri?'<img src="'.esc_attr($logo_data_uri).'" class="pdf-cover-logo" alt="Icon">':'').'
					<div class="pdf-cover-h1">ICON Profiler Report</div>
					<div class="pdf-cover-h2">Confidential report for development use</div>
					<div class="pdf-cover-card">
						<div class="pdf-cover-row"><span class="k">Participant</span><span class="v">'.esc_html($participant_name).'</span></div>
						'.($participant_role?'<div class="pdf-cover-row"><span class="k">Role</span><span class="v">'.esc_html($participant_role).'</span></div>':'').'
						'.($project_name?'<div class="pdf-cover-row"><span class="k">Project</span><span class="v">'.esc_html($project_name).'</span></div>':'').'
						<div class="pdf-cover-row"><span class="k">Tier</span><span class="v">'.esc_html($software_tier).'</span></div>
						<div class="pdf-cover-row"><span class="k">Issued</span><span class="v">'.esc_html($issued_date).'</span></div>
					</div>
					<div class="pdf-cover-note">This report is intended to support reflection, coaching, and practical development actions.</div>
				</div><div class="pdf-cover-bottombar"></div></div>
				<div style="page-break-after:always;"></div>';

				$pdf_css = '@page{margin:84px 22px 46px 22px;}
				body{font-family:DejaVu Sans,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:11px;color:#111827;margin:0;padding:0;}
				*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}
				.pdf-header{position:fixed;top:-84px;left:0;right:0;height:84px;background:#ffffff;}
				.pdf-header-inner{padding:14px 22px 10px;display:table;width:100%;}
				.pdf-brand{display:table-cell;vertical-align:middle;width:70%;}
				.pdf-meta{display:table-cell;vertical-align:middle;text-align:right;width:30%;font-size:10px;color:#6b7280;}
				.pdf-logo{width:44px;height:auto;display:inline-block;vertical-align:middle;margin-right:10px;}
				.pdf-brand-text{display:inline-block;vertical-align:middle;}
				.pdf-title{font-size:14px;font-weight:800;color:#0b2f2a;line-height:1.1;}
				.pdf-subtitle{font-size:10px;color:#6b7280;margin-top:2px;}
				.pdf-header-bar{height:4px;background:#0f766e;}
				.pdf-footer{position:fixed;bottom:-46px;left:0;right:0;height:46px;background:#ffffff;border-top:1px solid #e5e7eb;}
				.pdf-footer-inner{padding:12px 22px;font-size:9px;color:#6b7280;display:table;width:100%;}
				.pdf-footer-inner>div{display:table-cell;}
				.pdf-footer-inner>div:last-child{text-align:right;}
				.pdf-cover{width:100%;height:100%;background:#0f766e;color:#ffffff;position:relative;}
				.pdf-cover-topbar{height:14px;background:#14a4cf;}
				.pdf-cover-bottombar{position:absolute;left:0;right:0;bottom:0;height:18px;background:#14a4cf;}
				.pdf-cover-content{padding:54px 44px;text-align:center;position:relative;}
				.pdf-cover-logo{width:160px;height:auto;display:block;margin:0 auto 18px;}
				.pdf-cover-h1{font-size:34px;font-weight:800;margin:0 0 10px;letter-spacing:0.2px;}
				.pdf-cover-h2{font-size:14px;opacity:.94;margin:0 0 22px;}
				.pdf-cover-card{display:inline-block;text-align:left;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);border-radius:12px;padding:14px 16px;min-width:360px;max-width:520px;}
				.pdf-cover-row{margin:0 0 8px;}.pdf-cover-row:last-child{margin:0;}
				.pdf-cover-row .k{display:inline-block;width:88px;font-size:11px;opacity:.92;}
				.pdf-cover-row .v{font-size:12px;font-weight:700;}
				.pdf-cover-note{margin-top:18px;font-size:11px;opacity:.92;}';

				$dompdf->loadHtml('<html><head><meta charset="utf-8"><style>'.$pdf_css.'</style></head><body>'.$pdf_header.$pdf_footer_stub.$cover.$screen_html.'</body></html>');
				$dompdf->setPaper('A4','portrait');
				$dompdf->render();
				$canvas = $dompdf->getCanvas();
				if ($canvas) {
					$font = $dompdf->getFontMetrics()->get_font("DejaVu Sans","normal");
					$canvas->page_text(470,820,"Page {PAGE_NUM} of {PAGE_COUNT}",$font,9,array(0.42,0.45,0.50));
				}
				$dompdf->stream('icon-profiler-report-'.(int)$participant_id.'.pdf',array('Attachment'=>true));
				exit;
			}
			return '<p><strong>PDF engine not found.</strong> Dompdf missing.</p>'.$screen_html;
		}

		return $screen_html;
	}
}

if ( ! function_exists('icon_profiler_register_shortcode') ) {
	function icon_profiler_register_shortcode() {
		if ( shortcode_exists('icon_profiler_report') ) remove_shortcode('icon_profiler_report');
		add_shortcode('icon_profiler_report','icon_profiler_report_render');
	}
}
add_action('init','icon_profiler_register_shortcode',99);

if ( function_exists('error_log') ) error_log('ICON PROFILER REPORT FILE LOADED: '.__FILE__);
