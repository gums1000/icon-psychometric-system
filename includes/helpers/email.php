<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Email + rater completion detection helpers
 */

if ( ! function_exists( 'icon_psy_wc_cart_or_product_url' ) ) {
	function icon_psy_wc_cart_or_product_url( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) return '';

		if ( function_exists( 'wc_get_cart_url' ) ) {
			return add_query_arg( array( 'add-to-cart' => $product_id ), wc_get_cart_url() );
		}

		$plink = get_permalink( $product_id );
		return $plink ? $plink : '';
	}
}

if ( ! function_exists( 'icon_psy_wc_purchase_links' ) ) {
	function icon_psy_wc_purchase_links() {

		$tool_only     = 9617;
		$leadership    = 9618;
		$feedback_360  = 9619;
		$debrief       = 9620;
		$full          = 9621;
		$subscription  = 9622;

		return array(
			'tool_only'     => icon_psy_wc_cart_or_product_url( $tool_only ),
			'leadership'    => icon_psy_wc_cart_or_product_url( $leadership ),
			'feedback_360'  => icon_psy_wc_cart_or_product_url( $feedback_360 ),
			'debrief'       => icon_psy_wc_cart_or_product_url( $debrief ),
			'full'          => icon_psy_wc_cart_or_product_url( $full ),
			'subscription'  => icon_psy_wc_cart_or_product_url( $subscription ),
		);
	}
}

if ( ! function_exists( 'icon_psy_render_purchase_card' ) ) {
	function icon_psy_render_purchase_card( $credit_balance ) {

		$links = function_exists( 'icon_psy_wc_purchase_links' ) ? icon_psy_wc_purchase_links() : array();

		if ( (int) $credit_balance === -1 ) {
			return '';
		}

		$credit_balance = max( 0, (int) $credit_balance );

		$title = ( $credit_balance <= 0 ) ? 'You need credits to add participants' : 'Top up credits';
		$sub   = ( $credit_balance <= 0 )
			? 'Purchase a package below. After payment, credits are added automatically.'
			: 'Purchase a package below. Credits are added automatically after payment.';

		ob_start();
		?>
		<div class="icon-card" style="border:1px solid rgba(185,28,28,.15); margin-top:14px;">
			<div class="icon-section-title"><?php echo esc_html( $title ); ?></div>
			<div class="icon-mini" style="margin-top:6px;"><?php echo esc_html( $sub ); ?></div>

			<div class="icon-row" style="margin-top:12px;gap:10px;flex-wrap:wrap;">

				<?php if ( ! empty( $links['tool_only'] ) ) : ?>
					<a class="icon-btn" href="<?php echo esc_url( $links['tool_only'] ); ?>">Buy Tool Only</a>
				<?php endif; ?>

				<?php if ( ! empty( $links['leadership'] ) ) : ?>
					<a class="icon-btn" href="<?php echo esc_url( $links['leadership'] ); ?>">Buy Leadership Assessment</a>
				<?php endif; ?>

				<?php if ( ! empty( $links['feedback_360'] ) ) : ?>
					<a class="icon-btn" href="<?php echo esc_url( $links['feedback_360'] ); ?>">Buy 360 Feedback</a>
				<?php endif; ?>

				<?php if ( ! empty( $links['debrief'] ) ) : ?>
					<a class="icon-btn" href="<?php echo esc_url( $links['debrief'] ); ?>">Buy 360 + Coaching</a>
				<?php endif; ?>

				<?php if ( ! empty( $links['full'] ) ) : ?>
					<a class="icon-btn" href="<?php echo esc_url( $links['full'] ); ?>">Buy Full Package</a>
				<?php endif; ?>

				<?php if ( ! empty( $links['subscription'] ) ) : ?>
					<a class="icon-btn-ghost" href="<?php echo esc_url( $links['subscription'] ); ?>">Subscription (50)</a>
				<?php endif; ?>

			</div>

			<div class="icon-mini" style="margin-top:10px;">
				After checkout, return here and refresh — your balance will update automatically.
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

if ( ! function_exists( 'icon_psy_send_rater_invite_email' ) ) {
	function icon_psy_send_rater_invite_email( $to_email, $rater_name, $participant_name, $project_name, $invite_url, $project_type = '' ) {

		if ( ! is_email( $to_email ) ) return false;

		$is_team = function_exists('icon_psy_project_is_teams_project_type')
			? icon_psy_project_is_teams_project_type( $project_type )
			: false;

		$subject = $is_team
			? 'You’ve been invited to contribute to a High Performing Teams assessment'
			: 'ICON Catalyst 360 Feedback Request';

		$intro = $is_team
			? 'You’ve been invited to complete a short questionnaire as part of the <strong>High Performing Teams</strong> assessment. Your responses will be combined with others to create a confidential team report.'
			: 'You’ve been invited to complete a short feedback questionnaire as part of the <strong>ICON Catalyst</strong> process. Your responses will be combined with others to create a confidential report.';

		$body = '
			<div style="font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;line-height:1.5;color:#0a3b34;">
				<h2 style="margin:0 0 10px;">You’ve been invited to give feedback</h2>
				<p style="margin:0 0 12px;">
					Hello ' . esc_html( $rater_name ? $rater_name : 'there' ) . ',
				</p>

				<p style="margin:0 0 12px;">' . $intro . '</p>

				<div style="background:#f5f7fb;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:12px 0;">
					<div><strong>Project:</strong> ' . esc_html( $project_name ) . '</div>
					<div><strong>' . ( $is_team ? 'Team/Participant:' : 'Participant:' ) . '</strong> ' . esc_html( $participant_name ) . '</div>
				</div>

				<p style="margin:0 0 12px;">Click the button below to start:</p>

				<p style="margin:14px 0;">
					<a href="' . esc_url( $invite_url ) . '" style="display:inline-block;padding:12px 16px;border-radius:999px;text-decoration:none;color:#fff;font-weight:800;background:linear-gradient(135deg,#14a4cf,#15a06d);">
						Start feedback
					</a>
				</p>

				<p style="margin:0 0 8px;color:#425b56;font-size:13px;">If the button doesn’t work, copy and paste this link into your browser:</p>
				<p style="margin:0;color:#425b56;font-size:13px;word-break:break-all;">' . esc_html( $invite_url ) . '</p>

				<hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0;">

				<p style="margin:0;color:#6a837d;font-size:12px;">Sent by Icon Talent — ICON Catalyst System</p>
			</div>
		';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return wp_mail( $to_email, $subject, $body, $headers );
	}
}

if ( ! function_exists( 'icon_psy_portal_build_invite_base_url' ) ) {
	/**
	 * Decide which survey page to use for this project.
	 */
	function icon_psy_portal_build_invite_base_url( $project_row, $has_reference, $rater_page_url, $team_survey_page_url, $traits_survey_page_url ) {

		if ( function_exists('icon_psy_project_is_teams_project') && icon_psy_project_is_teams_project( $project_row, $has_reference ) ) {
			return array( 'base' => $team_survey_page_url, 'type' => 'teams' );
		}

		if ( function_exists('icon_psy_project_is_traits_project') && icon_psy_project_is_traits_project( $project_row, $has_reference ) ) {
			return array( 'base' => $traits_survey_page_url, 'type' => 'traits' );
		}

		return array( 'base' => $rater_page_url, 'type' => 'feedback' );
	}
}

if ( ! function_exists( 'icon_psy_portal_invite_single_rater' ) ) {
	/**
	 * Sends 1 invite and updates invite_sent_at/status.
	 * Returns array: ['ok'=>bool,'msg'=>string,'err'=>string,'extra'=>array]
	 */
	function icon_psy_portal_invite_single_rater( $args ) {
		global $wpdb;

		$defaults = array(
			'user_id'                => 0,
			'rater_id'               => 0,
			'projects_table'         => '',
			'participants_table'     => '',
			'raters_table'           => '',
			'has_reference'          => false,
			'has_token_col'          => true,
			'has_invite_token_col'   => true,
			'has_expires_col'        => true,
			'has_sent_col'           => true,
			'rater_page_url'         => '',
			'team_survey_page_url'   => '',
			'traits_survey_page_url' => '',
		);
		$a = array_merge( $defaults, is_array($args) ? $args : array() );

		$user_id  = (int) $a['user_id'];
		$rater_id = (int) $a['rater_id'];

		if ( $user_id <= 0 || $rater_id <= 0 ) {
			return array( 'ok'=>false, 'msg'=>'', 'err'=>'Invalid request.', 'extra'=>array() );
		}

		$projects_table     = $a['projects_table'];
		$participants_table = $a['participants_table'];
		$raters_table       = $a['raters_table'];

		// Ownership + context (rater + participant + project)
		$ctx = $wpdb->get_row( $wpdb->prepare(
			"SELECT r.*,
			        p.id AS participant_id,
			        p.name AS participant_name,
			        p.project_id AS project_id,
			        pr.name AS project_name,
			        pr.icon_pkg,
			        pr.icon_cfg,
			        pr.reference
			 FROM {$raters_table} r
			 INNER JOIN {$participants_table} p ON p.id = r.participant_id
			 INNER JOIN {$projects_table} pr ON pr.id = p.project_id
			 WHERE r.id=%d AND pr.client_user_id=%d
			 LIMIT 1",
			$rater_id, $user_id
		) );

		if ( ! $ctx ) {
			return array( 'ok'=>false, 'msg'=>'', 'err'=>'You do not own this rater.', 'extra'=>array() );
		}

		$project_id     = (int) $ctx->project_id;
		$participant_id = (int) $ctx->participant_id;

		$to = sanitize_email( (string) ($ctx->email ?? '') );
		if ( ! is_email( $to ) ) {
			return array(
				'ok'   => false,
				'msg'  => '',
				'err'  => 'Rater email is missing or invalid.',
				'extra'=> array(
					'icon_open_project'     => $project_id,
					'icon_open_participant' => $participant_id,
					'icon_open_add_rater'   => 1,
				)
			);
		}

		// Token
		$tok = '';
		if ( ! empty($ctx->token) ) $tok = (string) $ctx->token;
		if ( $tok === '' && ! empty($ctx->invite_token) ) $tok = (string) $ctx->invite_token;

		if ( $tok === '' ) {
			$tok     = function_exists('icon_psy_rand_token') ? icon_psy_rand_token() : wp_generate_password(32,false,false);
			$expires = gmdate( 'Y-m-d H:i:s', time() + (14 * DAY_IN_SECONDS) );

			$upd = array();
			$uf  = array();

			if ( ! empty($a['has_token_col']) )        { $upd['token'] = $tok; $uf[] = '%s'; }
			if ( ! empty($a['has_invite_token_col']) ) { $upd['invite_token'] = $tok; $uf[] = '%s'; }
			if ( ! empty($a['has_expires_col']) )      { $upd['token_expires_at'] = $expires; $uf[] = '%s'; }

			if ( ! empty($upd) ) {
				$wpdb->update( $raters_table, $upd, array('id'=>$rater_id), $uf, array('%d') );
			}
		}

		// Minimal “project-like” object for your project detectors
		$project_like = (object) array(
			'icon_pkg'  => (string) ($ctx->icon_pkg ?? ''),
			'icon_cfg'  => (string) ($ctx->icon_cfg ?? ''),
			'reference' => (string) ($ctx->reference ?? ''),
		);

		$pick = icon_psy_portal_build_invite_base_url(
			$project_like,
			(bool) $a['has_reference'],
			(string) $a['rater_page_url'],
			(string) $a['team_survey_page_url'],
			(string) $a['traits_survey_page_url']
		);

		$invite_url = add_query_arg( array('token' => $tok), $pick['base'] );

		$sent = icon_psy_send_rater_invite_email(
			$to,
			(string) ($ctx->name ?? ''),
			(string) ($ctx->participant_name ?? ''),
			(string) ($ctx->project_name ?? ''),
			$invite_url,
			(string) $pick['type']
		);

		if ( ! $sent ) {
			return array(
				'ok'=>false,
				'msg'=>'',
				'err'=>'Email could not be sent (wp_mail failed). This is usually SMTP/server configuration.',
				'extra'=>array(
					'icon_open_project'     => $project_id,
					'icon_open_participant' => $participant_id,
					'icon_open_add_rater'   => 1,
				)
			);
		}

		// Mark sent + invited
		$upd2 = array( 'status' => 'invited' );
		$uf2  = array( '%s' );
		if ( ! empty($a['has_sent_col']) ) { $upd2['invite_sent_at'] = current_time('mysql'); $uf2[] = '%s'; }

		$wpdb->update( $raters_table, $upd2, array('id'=>$rater_id), $uf2, array('%d') );

		return array(
			'ok'=>true,
			'msg'=>'Invite sent.',
			'err'=>'',
			'extra'=>array(
				'icon_open_project'     => $project_id,
				'icon_open_participant' => $participant_id,
				'icon_open_add_rater'   => 1,
			)
		);
	}
}

if ( ! function_exists( 'icon_psy_portal_invite_all_project_raters' ) ) {
	/**
	 * Sends invites for all non-completed raters in a project.
	 * Returns array: ['ok'=>bool,'msg'=>string,'err'=>string,'extra'=>array]
	 */
	function icon_psy_portal_invite_all_project_raters( $args ) {
		global $wpdb;

		$defaults = array(
			'user_id'                => 0,
			'project_id'             => 0,
			'projects_table'         => '',
			'participants_table'     => '',
			'raters_table'           => '',
			'has_reference'          => false,
			'has_token_col'          => true,
			'has_invite_token_col'   => true,
			'has_expires_col'        => true,
			'has_sent_col'           => true,
			'rater_page_url'         => '',
			'team_survey_page_url'   => '',
			'traits_survey_page_url' => '',
		);
		$a = array_merge( $defaults, is_array($args) ? $args : array() );

		$user_id    = (int) $a['user_id'];
		$project_id = (int) $a['project_id'];

		if ( $user_id <= 0 || $project_id <= 0 ) {
			return array( 'ok'=>false, 'msg'=>'', 'err'=>'Invalid request.', 'extra'=>array() );
		}

		$projects_table     = $a['projects_table'];
		$participants_table = $a['participants_table'];
		$raters_table       = $a['raters_table'];

		$project_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$projects_table} WHERE id=%d AND client_user_id=%d LIMIT 1",
			$project_id, $user_id
		) );
		if ( ! $project_row ) {
			return array( 'ok'=>false, 'msg'=>'', 'err'=>'You do not own this project.', 'extra'=>array() );
		}

		$pick = icon_psy_portal_build_invite_base_url(
			$project_row,
			(bool) $a['has_reference'],
			(string) $a['rater_page_url'],
			(string) $a['team_survey_page_url'],
			(string) $a['traits_survey_page_url']
		);

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.*, p.name AS participant_name
			 FROM {$raters_table} r
			 INNER JOIN {$participants_table} p ON p.id = r.participant_id
			 WHERE p.project_id=%d
			 ORDER BY p.id ASC, r.id ASC",
			$project_id
		) );

		if ( empty($rows) ) {
			return array(
				'ok'=>false,'msg'=>'','err'=>'No raters found for this project.',
				'extra'=>array('icon_open_project'=>$project_id)
			);
		}

		$sent = 0; $skipped_email = 0; $skipped_completed = 0;

		foreach ( (array) $rows as $r ) {

			if ( function_exists('icon_psy_rater_is_completed_row') && icon_psy_rater_is_completed_row( $r ) ) {
				$skipped_completed++;
				continue;
			}

			$to = sanitize_email( (string) ($r->email ?? '') );
			if ( ! is_email($to) ) { $skipped_email++; continue; }

			$rater_id = (int) ($r->id ?? 0);
			if ( $rater_id <= 0 ) continue;

			$tok = '';
			if ( ! empty($r->token) ) $tok = (string) $r->token;
			if ( $tok === '' && ! empty($r->invite_token) ) $tok = (string) $r->invite_token;

			if ( $tok === '' ) {
				$tok     = function_exists('icon_psy_rand_token') ? icon_psy_rand_token() : wp_generate_password(32,false,false);
				$expires = gmdate( 'Y-m-d H:i:s', time() + (14 * DAY_IN_SECONDS) );

				$upd = array(); $uf = array();
				if ( ! empty($a['has_token_col']) )        { $upd['token'] = $tok; $uf[] = '%s'; }
				if ( ! empty($a['has_invite_token_col']) ) { $upd['invite_token'] = $tok; $uf[] = '%s'; }
				if ( ! empty($a['has_expires_col']) )      { $upd['token_expires_at'] = $expires; $uf[] = '%s'; }

				if ( ! empty($upd) ) {
					$wpdb->update( $raters_table, $upd, array('id'=>$rater_id), $uf, array('%d') );
				}
			}

			$invite_url = add_query_arg( array('token'=>$tok), $pick['base'] );

			$ok = icon_psy_send_rater_invite_email(
				$to,
				(string) ($r->name ?? ''),
				(string) ($r->participant_name ?? ''),
				(string) ($project_row->name ?? ''),
				$invite_url,
				(string) $pick['type']
			);

			if ( $ok ) {
				$sent++;

				$upd2 = array( 'status' => 'invited' );
				$uf2  = array( '%s' );
				if ( ! empty($a['has_sent_col']) ) { $upd2['invite_sent_at'] = current_time('mysql'); $uf2[] = '%s'; }

				$wpdb->update( $raters_table, $upd2, array('id'=>$rater_id), $uf2, array('%d') );
			}
		}

		$msg = 'Invites sent: ' . (int)$sent . '.';
		if ( $skipped_email )     $msg .= ' Skipped (missing/invalid email): ' . (int)$skipped_email . '.';
		if ( $skipped_completed ) $msg .= ' Skipped (completed): ' . (int)$skipped_completed . '.';

		return array(
			'ok'=>true,
			'msg'=>$msg,
			'err'=>'',
			'extra'=>array('icon_open_project'=>$project_id)
		);
	}
}

if ( ! function_exists( 'icon_psy_rater_has_submission' ) ) {
	function icon_psy_rater_has_submission( $rater_id, $token = '' ) {
		global $wpdb;

		$rater_id = (int) $rater_id;
		$token    = trim( (string) $token );

		if ( $rater_id <= 0 && $token === '' ) return false;

		static $cache = array();
		$cache_key = $rater_id . '|' . $token;
		if ( isset( $cache[ $cache_key ] ) ) return (bool) $cache[ $cache_key ];

		$candidates = array(
			$wpdb->prefix . 'icon_assessment_results',
			$wpdb->prefix . 'icon_psy_results',
			$wpdb->prefix . 'icon_psy_rater_answers',
			$wpdb->prefix . 'icon_psy_rater_responses',
			$wpdb->prefix . 'icon_psy_rater_scores',
			$wpdb->prefix . 'icon_psy_rater_competency_scores',
			$wpdb->prefix . 'icon_psy_scores',
		);

		foreach ( $candidates as $t ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $t ) ) );
			if ( empty( $found ) ) continue;

			$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t}", 0 );
			if ( empty( $cols ) ) continue;

			if ( $rater_id > 0 && in_array( 'rater_id', $cols, true ) ) {
				$cnt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE rater_id=%d", $rater_id ) );
				if ( $cnt > 0 ) { $cache[$cache_key] = true; return true; }
			}

			if ( $token !== '' && in_array( 'token', $cols, true ) ) {
				$cnt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE token=%s", $token ) );
				if ( $cnt > 0 ) { $cache[$cache_key] = true; return true; }
			}

			if ( $token !== '' && in_array( 'invite_token', $cols, true ) ) {
				$cnt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE invite_token=%s", $token ) );
				if ( $cnt > 0 ) { $cache[$cache_key] = true; return true; }
			}
		}

		$cache[$cache_key] = false;
		return false;
	}
}

if ( ! function_exists( 'icon_psy_rater_is_completed_row' ) ) {
	function icon_psy_rater_is_completed_row( $r ) {
		if ( ! is_object($r) ) return false;

		$status = isset($r->status) ? (string) $r->status : '';
		if ( function_exists('icon_psy_is_completed_status') && icon_psy_is_completed_status( $status ) ) return true;

		foreach ( array('completed_at','submitted_at','completed_on','submitted_on') as $f ) {
			if ( isset($r->$f) && ! empty($r->$f) ) return true;
		}

		$rid = isset($r->id) ? (int) $r->id : 0;

		$tok = '';
		if ( isset($r->token) && $r->token ) $tok = (string) $r->token;
		if ( $tok === '' && isset($r->invite_token) && $r->invite_token ) $tok = (string) $r->invite_token;

		if ( function_exists('icon_psy_rater_has_submission') && icon_psy_rater_has_submission( $rid, $tok ) ) return true;

		return false;
	}
}

if ( ! function_exists( 'icon_psy_sync_rater_completed_status' ) ) {
	function icon_psy_sync_rater_completed_status( $raters_table, $r ) {
		global $wpdb;

		if ( ! is_object( $r ) ) return;

		$rid = isset($r->id) ? (int) $r->id : 0;
		if ( $rid <= 0 ) return;

		$current_status = isset($r->status) ? (string) $r->status : '';

		if ( icon_psy_rater_is_completed_row( $r ) && ! ( function_exists('icon_psy_is_completed_status') && icon_psy_is_completed_status( $current_status ) ) ) {
			$wpdb->update(
				$raters_table,
				array( 'status' => 'completed' ),
				array( 'id' => $rid ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}
}

/* ------------------------------------------------------------
 * SIMPLE REPORT EMAIL SENDER (Icon-styled HTML)
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_send_report_email' ) ) {

	/**
	 * Send report link email(s) for a participant.
	 *
	 * Args:
	 * - project_id (int)
	 * - participant_id (int)
	 * - report_type (string) feedback|team|traits|profiler
	 * - to (string) client|participant|raters|all
	 *
	 * Returns array: ['ok'=>bool,'msg'=>string,'sent'=>int]
	 */
	function icon_psy_send_report_email( $args ) {

		global $wpdb;

		$args = is_array( $args ) ? $args : array();
		$project_id     = isset( $args['project_id'] ) ? (int) $args['project_id'] : 0;
		$participant_id = isset( $args['participant_id'] ) ? (int) $args['participant_id'] : 0;
		$report_type    = isset( $args['report_type'] ) ? sanitize_key( (string) $args['report_type'] ) : 'feedback';
		$to             = isset( $args['to'] ) ? sanitize_key( (string) $args['to'] ) : 'client';

		if ( $project_id <= 0 || $participant_id <= 0 ) {
			return array( 'ok' => false, 'msg' => 'Missing project_id or participant_id.', 'sent' => 0 );
		}

		$projects_table     = $wpdb->prefix . 'icon_psy_projects';
		$participants_table = $wpdb->prefix . 'icon_psy_participants';
		$raters_table       = $wpdb->prefix . 'icon_psy_raters';

		$project = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$projects_table} WHERE id=%d LIMIT 1", $project_id ) );
		$part    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$participants_table} WHERE id=%d LIMIT 1", $participant_id ) );

		if ( ! $project || ! $part ) {
			return array( 'ok' => false, 'msg' => 'Project or participant not found.', 'sent' => 0 );
		}

		// Resolve client email (project->client_user_id => WP user email)
		$client_email = '';
		if ( ! empty( $project->client_user_id ) ) {
			$u = get_user_by( 'id', (int) $project->client_user_id );
			if ( $u && ! empty( $u->user_email ) ) {
				$client_email = (string) $u->user_email;
			}
		}

		// Participant email (assumes column name is "email")
		$participant_email = ! empty( $part->email ) ? (string) $part->email : '';

		// Rater emails
		$raters = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$raters_table} WHERE participant_id=%d", $participant_id ) );

		$rater_emails = array();
		foreach ( (array) $raters as $r ) {
			$em = isset( $r->email ) ? trim( (string) $r->email ) : '';
			if ( is_email( $em ) ) $rater_emails[] = $em;
		}
		$rater_emails = array_values( array_unique( $rater_emails ) );

		// Decide recipients
		$recipients = array();

		if ( $to === 'client' ) {
			if ( is_email( $client_email ) ) $recipients[] = $client_email;

		} elseif ( $to === 'participant' ) {
			if ( is_email( $participant_email ) ) $recipients[] = $participant_email;

		} elseif ( $to === 'raters' ) {
			$recipients = $rater_emails;

		} elseif ( $to === 'all' ) {
			if ( is_email( $client_email ) ) $recipients[] = $client_email;
			if ( is_email( $participant_email ) ) $recipients[] = $participant_email;
			$recipients = array_merge( $recipients, $rater_emails );
			$recipients = array_values( array_unique( $recipients ) );
		}

		if ( empty( $recipients ) ) {
			return array( 'ok' => false, 'msg' => 'No valid recipient email address found.', 'sent' => 0 );
		}

		// Report base URL by type (adjust slugs if needed)
		switch ( $report_type ) {
			case 'team':
				$base = home_url( '/team-report/' );
				$report_label = 'TEAM';
				$headline = 'Your team report is ready';
				$intro = 'Your <strong>High Performing Teams</strong> report is now available. Click the button below to open it.';
				break;
			case 'traits':
				$base = home_url( '/traits-report/' );
				$report_label = 'TRAITS';
				$headline = 'Your traits report is ready';
				$intro = 'Your <strong>Technical Traits</strong> report is now available. Click the button below to open it.';
				break;
			case 'profiler':
				$base = home_url( '/icon-profiler-report/' );
				$report_label = 'PROFILER';
				$headline = 'Your profiler report is ready';
				$intro = 'Your <strong>Icon Profiler</strong> report is now available. Click the button below to open it.';
				break;
			case 'feedback':
			default:
				$base = home_url( '/feedback-report/' );
				$report_label = 'FEEDBACK';
				$headline = 'Your feedback report is ready';
				$intro = 'Your <strong>ICON Catalyst</strong> feedback report is now available. Click the button below to open it.';
				break;
		}

		// IMPORTANT: use both ids (more robust)
		$report_url = add_query_arg(
			array(
				'project_id'     => (int) $project_id,
				'participant_id' => (int) $participant_id,
			),
			$base
		);

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$proj_name = (string) ( $project->name ?? 'Project' );
		$part_name = (string) ( $part->name ?? 'Participant' );

		$subject   = 'Your report is ready: ' . $part_name . ' (' . $proj_name . ')';

		// Icon-styled HTML email (matches the rater invite look/feel)
		$body = '
			<div style="font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;line-height:1.5;color:#0a3b34;">
				<h2 style="margin:0 0 10px;">' . esc_html( $headline ) . '</h2>

				<p style="margin:0 0 12px;">
					Hello,
				</p>

				<p style="margin:0 0 12px;">' . $intro . '</p>

				<div style="background:#f5f7fb;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:12px 0;">
					<div><strong>Project:</strong> ' . esc_html( $proj_name ) . '</div>
					<div><strong>Participant:</strong> ' . esc_html( $part_name ) . '</div>
					<div><strong>Report type:</strong> ' . esc_html( $report_label ) . '</div>
				</div>

				<p style="margin:0 0 12px;">Click the button below to open the report:</p>

				<p style="margin:14px 0;">
					<a href="' . esc_url( $report_url ) . '" style="display:inline-block;padding:12px 16px;border-radius:999px;text-decoration:none;color:#fff;font-weight:800;background:linear-gradient(135deg,#14a4cf,#15a06d);">
						Open report
					</a>
				</p>

				<p style="margin:0 0 8px;color:#425b56;font-size:13px;">If the button doesn’t work, copy and paste this link into your browser:</p>
				<p style="margin:0;color:#425b56;font-size:13px;word-break:break-all;">' . esc_html( $report_url ) . '</p>

				<hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0;">

				<p style="margin:0;color:#6a837d;font-size:12px;">Sent by Icon Talent — ICON Catalyst System</p>
			</div>
		';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = 0;
		$fail = 0;

		foreach ( $recipients as $email ) {
			$email = sanitize_email( (string) $email );
			if ( ! is_email( $email ) ) { $fail++; continue; }

			$ok = wp_mail( $email, $subject, $body, $headers );
			if ( $ok ) $sent++;
			else $fail++;
		}

		if ( $sent > 0 ) {
			return array(
				'ok'   => true,
				'msg'  => 'Sent: ' . (int) $sent . ( $fail ? ' (failed: ' . (int) $fail . ')' : '' ),
				'sent' => (int) $sent
			);
		}

		return array( 'ok' => false, 'msg' => 'wp_mail returned false for all recipients.', 'sent' => 0 );
	}
}

/* ------------------------------------------------------------
 * POST HANDLER (admin_post) - used by your portal form
 * ------------------------------------------------------------ */

if ( ! function_exists('icon_psy_handle_send_report_email') ) {

	add_action('admin_post_icon_psy_send_report_email', 'icon_psy_handle_send_report_email');

	function icon_psy_handle_send_report_email() {

		if ( ! is_user_logged_in() ) {
			wp_die('Not logged in.');
		}

		// Nonce: your forms use wp_nonce_field('icon_psy_send_report_email')
		if ( empty($_POST['_wpnonce']) || ! wp_verify_nonce( sanitize_text_field( wp_unslash($_POST['_wpnonce']) ), 'icon_psy_send_report_email' ) ) {
			wp_die('Security check failed.');
		}

		$project_id     = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
		$participant_id = isset($_POST['participant_id']) ? (int) $_POST['participant_id'] : 0;
		$report_type    = isset($_POST['report_type']) ? sanitize_key( wp_unslash($_POST['report_type']) ) : 'feedback';
		$to             = isset($_POST['to']) ? sanitize_key( wp_unslash($_POST['to']) ) : 'client';

		if ( $project_id <= 0 || $participant_id <= 0 ) {
			wp_die('Missing project_id or participant_id.');
		}

		$res = icon_psy_send_report_email(array(
			'project_id'     => $project_id,
			'participant_id' => $participant_id,
			'report_type'    => $report_type,
			'to'             => $to,
		));

		$ok  = is_array($res) ? ! empty($res['ok']) : (bool) $res;
		$msg = is_array($res) ? (string) ($res['msg'] ?? '') : '';

		$back = wp_get_referer() ? wp_get_referer() : home_url('/catalyst-portal/');
		$back = add_query_arg(
			array(
				'icon_psy_msg'          => $ok ? 'Email sent.' : '',
				'icon_psy_err'          => $ok ? '' : ( $msg ? $msg : 'Email failed to send.' ),
				'icon_open_project'     => $project_id,
				'icon_open_participant' => $participant_id,
			),
			$back
		);

		wp_safe_redirect($back);
		exit;
	}
}
