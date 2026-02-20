<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ICON Catalyst – Survey (Rater + Self mode from same code)
 *
 * Shortcode: [icon_psy_rater_survey2]
 * Typical use:
 * - Raters arrive via token link -> /rater-survey/?token=XXXX
 * - Self-assessment uses SAME survey, but the rater.relationship contains "self"
 *
 * Data writes to: wp_icon_assessment_results (your $results_table)
 * Marks rater as completed in: wp_icon_psy_raters
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

        // Defensive: if projects.framework_id doesn't exist, skip the project lookup to avoid DB errors
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

        // 1) framework attached to the project
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

        // 2) fallback to core/default
        $default_id = (int) $wpdb->get_var(
            "SELECT id FROM {$frameworks_table} WHERE is_default = 1 LIMIT 1"
        );

        return $default_id > 0 ? $default_id : 0;
    }
}

if ( ! function_exists( 'icon_psy_rater_survey2' ) ) {

    function icon_psy_rater_survey2( $atts ) {
        global $wpdb;

        // -----------------------------
        // 1) Resolve rater_id / token
        // -----------------------------
        $atts = shortcode_atts(
            array(
                'rater_id' => 0,
                'token'    => '',
            ),
            $atts,
            'icon_psy_rater_survey2'
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

        $raters_table       = $wpdb->prefix . 'icon_psy_raters';
        $participants_table = $wpdb->prefix . 'icon_psy_participants';
        $projects_table     = $wpdb->prefix . 'icon_psy_projects';
        $frameworks_table   = $wpdb->prefix . 'icon_psy_frameworks';
        $competencies_table = $wpdb->prefix . 'icon_psy_framework_competencies';
        $results_table      = $wpdb->prefix . 'icon_assessment_results';

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

        // Admin-only debug panel (safe)
        $debug_html = '';
        if ( current_user_can( 'manage_options' ) ) {
            $debug_html  = '<div style="margin:8px 0;padding:8px 10px;border-radius:8px;';
            $debug_html .= 'border:1px solid #d1e7dd;background:#f0fdf4;font-size:11px;color:#0a3b34;">';
            $debug_html .= '<strong>ICON Catalyst survey debug</strong><br>';
            $debug_html .= 'Lookup mode: ' . esc_html( $lookup_mode ) . '<br>';
            $debug_html .= 'Param rater_id: ' . esc_html( $rater_id ) . '<br>';
            $debug_html .= 'Param token: ' . esc_html( $token ? $token : '(none)' ) . '<br>';
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
                    <h2 style="margin:0 0 6px;color:#7f1d1d;font-size:18px;">ICON Catalyst Feedback</h2>
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
        $rater_status     = $rater->status ? strtolower( $rater->status ) : 'pending';

        // Relationship drives tone (single source of truth)
        $relationship_raw = isset( $rater->relationship ) ? strtolower( trim( (string) $rater->relationship ) ) : '';
        $is_self_mode     = ( $relationship_raw !== '' && strpos( $relationship_raw, 'self' ) !== false );

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
                    <p style="margin:0 0 4px;font-size:13px;color:#581c1c;">No active ICON Catalyst leadership framework is configured.</p>
                    <p style="margin:0;font-size:12px;color:#6b7280;">Please create and publish a framework, then try this link again.</p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        $framework_name = $framework->name ?: 'ICON Catalyst Framework';

        $competencies = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, description
                 FROM {$competencies_table}
                 WHERE framework_id = %d
                 ORDER BY sort_order ASC, id ASC",
                (int) $framework_id
            )
        );

        if ( empty( $competencies ) ) {
            ob_start();
            echo $debug_html;
            ?>
            <div style="max-width:720px;margin:0 auto;padding:20px;">
                <div style="background:#fef2f2;border:1px solid #fecaca;padding:18px 16px;border-radius:14px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
                    <h2 style="margin:0 0 6px;color:#7f1d1d;font-size:18px;">ICON Catalyst Framework</h2>
                    <p style="margin:0 0 4px;font-size:13px;color:#581c1c;">This framework does not yet have any competencies defined.</p>
                    <p style="margin:0;font-size:12px;color:#6b7280;">Please add competencies, then resend the link.</p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        // -----------------------------
        // 4) Handle submission
        // -----------------------------
        $message       = '';
        $message_class = '';

        if ( isset( $_POST['icon_psy_rater_survey2_submitted'] ) && '1' === (string) $_POST['icon_psy_rater_survey2_submitted'] ) {

            check_admin_referer( 'icon_psy_rater_survey2' );

            if ( $rater_status === 'completed' ) {
                $message       = $is_self_mode ? 'Your self-assessment has already been submitted. Thank you.' : 'Your feedback has already been submitted. Thank you.';
                $message_class = 'success';
            } else {

                $scores   = isset( $_POST['scores'] ) && is_array( $_POST['scores'] ) ? $_POST['scores'] : array();
                $strength = isset( $_POST['q2_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['q2_text'] ) ) : '';
                $develop  = isset( $_POST['q3_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['q3_text'] ) ) : '';

                $detail_data = array();
                $all_numbers = array();

                foreach ( $competencies as $comp ) {
                    $cid = (int) $comp->id;

                    if ( ! isset( $scores[ $cid ] ) || ! is_array( $scores[ $cid ] ) ) {
                        continue;
                    }

                    $c_q1 = isset( $scores[ $cid ]['q1'] ) ? (int) $scores[ $cid ]['q1'] : 0;
                    $c_q2 = isset( $scores[ $cid ]['q2'] ) ? (int) $scores[ $cid ]['q2'] : 0;
                    $c_q3 = isset( $scores[ $cid ]['q3'] ) ? (int) $scores[ $cid ]['q3'] : 0;

                    // Clamp 1–7
                    $c_q1 = max( 1, min( 7, $c_q1 ) );
                    $c_q2 = max( 1, min( 7, $c_q2 ) );
                    $c_q3 = max( 1, min( 7, $c_q3 ) );

                    $detail_data[ $cid ] = array(
                        'competency_id' => $cid,
                        'q1'            => $c_q1,
                        'q2'            => $c_q2,
                        'q3'            => $c_q3,
                    );

                    $all_numbers[] = $c_q1;
                    $all_numbers[] = $c_q2;
                    $all_numbers[] = $c_q3;
                }

                if ( empty( $detail_data ) ) {
                    $message       = $is_self_mode ? 'Please score the leadership areas before submitting your self-assessment.' : 'Please provide ratings across the leadership areas before submitting.';
                    $message_class = 'error';
                } else {

                    $overall_rating = 0;
                    if ( ! empty( $all_numbers ) ) {
                        $overall_rating = array_sum( $all_numbers ) / count( $all_numbers );
                    }

                    $detail_json = wp_json_encode( $detail_data );

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

                        $message       = $is_self_mode ? 'Saved — your individual leadership self-assessment is complete.' : 'Thank you – your ICON Catalyst feedback has been submitted.';
                        $message_class = 'success';
                        $rater_status  = 'completed';
                    }
                }
            }
        }

        // -----------------------------
        // 5) If completed -> thanks panel
        // -----------------------------
        if ( $rater_status === 'completed' && $message_class !== 'error' ) {
            ob_start();
            echo $debug_html;
            ?>
            <div style="max-width:820px;margin:0 auto;padding:22px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
                <div style="background:linear-gradient(135deg,#ecfdf5 0,#e0f2fe 60%,#ffffff 100%);border-radius:18px;padding:20px 22px;border:1px solid #bbf7d0;box-shadow:0 16px 35px rgba(21,160,109,0.18);">
                    <h2 style="margin:0 0 6px;font-size:20px;font-weight:800;color:#0a3b34;">
                        <?php echo $is_self_mode ? 'ICON Catalyst Self-Assessment' : 'ICON Catalyst Feedback'; ?>
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
                        <span style="padding:3px 9px;border-radius:999px;border:1px solid #bbf7d0;background:#ecfdf5;color:#166534;">Status: Completed</span>
                    </div>

                    <p style="margin:0;font-size:13px;color:#0f5132;">Thank you — it’s been recorded. You can now close this window.</p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        // -----------------------------
        // 6) Survey UI
        // -----------------------------

        // Images (safe if longer/shorter than competencies)
        $competency_images = array(
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

        // “What to look for” bullets (index-based fallback)
        $look_for_map_rater = array(
            0 => array('Anticipates future challenges and plans ahead','Connects decisions to longer-term outcomes','Balances detail with big-picture thinking','Makes sensible trade-offs when priorities clash','Communicates direction clearly to others'),
            1 => array('Builds trust through consistent behaviour','Sets clear expectations and follows through','Encourages growth and learning in others','Addresses issues directly and fairly','Creates confidence through calm leadership'),
            2 => array('Shares information openly and at the right time','Works across teams without silo behaviour','Seeks input before deciding','Handles disagreement constructively','Aligns stakeholders to a common goal'),
            3 => array('Understands stakeholder needs and pressures','Responds constructively to feedback','Improves experience through action, not promises','Balances service and outcomes','Treats people with respect and professionalism'),
            4 => array('Delivers on commitments and follows through','Keeps priorities clear when things change','Maintains momentum under pressure','Focuses on outcomes, not activity','Removes obstacles rather than accepting delays'),
            5 => array('Stays calm and steady in difficult moments','Adapts approach when challenged','Does not blame others when things go wrong','Models positive behaviour for others','Recovers quickly after setbacks'),
            6 => array('Sets the behavioural standard for others','Upholds agreed values consistently','Influences through behaviour, not authority','Encourages accountability and ownership','Acts with integrity even under pressure'),
            7 => array('Inspires confidence in others','Represents the organisation positively','Leads by example, not slogans','Creates clarity and direction','Builds pride and belief in the team'),
        );

        // Self language version
        $look_for_map_self = array(
            0 => array('I think ahead and prepare, not just react.','I connect daily actions to longer-term outcomes.','I weigh options and make sensible trade-offs.','I communicate direction clearly (even when it’s messy).','I stay curious and ask better questions.'),
            1 => array('I build trust through consistent actions.','I set clear expectations and follow through.','I coach and develop others (not rescue them).','I handle difficult conversations respectfully.','I create confidence through calm leadership.'),
            2 => array('I share information early, not late.','I work across teams without “silo” thinking.','I seek input before deciding.','I deal with disagreement constructively.','I align people to a common goal.'),
            3 => array('I genuinely understand stakeholder needs.','I respond constructively to feedback.','I improve experience through action (not promises).','I balance service and outcomes.','I treat people with respect and professionalism.'),
            4 => array('I deliver on commitments and follow through.','I keep priorities clear when things change.','I maintain momentum under pressure.','I focus on outcomes, not activity.','I remove obstacles rather than accept delays.'),
            5 => array('I stay steady in difficult moments.','I adapt my approach when challenged.','I take accountability (no blame games).','I model positive behaviour consistently.','I recover quickly after setbacks.'),
            6 => array('I set the behavioural standard for others.','I live the agreed values consistently.','I influence through behaviour, not authority.','I encourage ownership and accountability.','I act with integrity when it’s hard.'),
            7 => array('I inspire confidence in others.','I represent the organisation positively.','I lead by example, not slogans.','I create clarity and direction.','I build belief and pride in the team.'),
        );

        $q_hints_self = array(
            'q1' => array('Think about a normal week.','How consistently do I show this behaviour when nothing is “on fire”?','Use evidence: meetings, decisions, emails, conversations.'),
            'q2' => array('Think about stress, conflict, time pressure, or uncertainty.','Do I stay effective… or do I slip into unhelpful habits?','Score based on what actually happens, not what I want to happen.'),
            'q3' => array('If someone copied my behaviour, would it be a good example?','Do I set the standard visibly and consistently?','This is about impact — not intention.'),
        );

        // Autosave key is unique per invite
        $autosave_key = 'icon_psy_rater_survey2_' . ( $token ? $token : ( 'rid_' . (int) $rater->id ) );

        ob_start();
        echo $debug_html;
        ?>
        <div class="icon-psy-rater-survey-wrapper" style="max-width:980px;margin:0 auto;padding:22px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">

            <style>
                :root{
                    --icon-green:#15a06d;
                    --icon-blue:#14a4cf;
                    --text-dark:#0a3b34;
                    --text-mid:#425b56;
                    --text-light:#6a837d;
                }
                .icon-psy-hero {
                    background: radial-gradient(circle at top left, #e6f4ef 0, #ecfdf5 40%, #e0f2fe 100%);
                    border-radius: 20px;
                    padding: 18px 20px 16px;
                    border: 1px solid rgba(21,160,109,0.25);
                    box-shadow: 0 20px 40px rgba(10,59,52,0.20);
                    margin-bottom: 14px;
                }
                .icon-psy-hero h2 { margin:0 0 6px;font-size:22px;font-weight:800;color:var(--text-dark);letter-spacing:.02em; }
                .icon-psy-hero p { margin:0;font-size:13px;color:var(--text-mid); }
                .icon-psy-chip-row{ display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;font-size:11px; }
                .icon-psy-chip{ padding:3px 9px;border-radius:999px;border:1px solid var(--icon-green);background:#ecfdf5;color:var(--text-dark); }
                .icon-psy-chip-muted{ padding:3px 9px;border-radius:999px;border:1px solid #cbd5f5;background:#eff6ff;color:#1e3a8a; }

                .icon-psy-notice{ margin-bottom:14px;padding:10px 12px;border-radius:12px;border:1px solid;font-size:12px; }
                .icon-psy-notice.info{ border-color:#bee3f8;background:#eff6ff;color:#1e3a8a; }
                .icon-psy-notice.success{ border-color:#bbf7d0;background:#ecfdf5;color:#166534; }
                .icon-psy-notice.error{ border-color:#fecaca;background:#fef2f2;color:#b91c1c; }

                .icon-psy-progress-wrap{ background:#fff;border-radius:16px;border:1px solid #d1e7dd;box-shadow:0 10px 24px rgba(10,59,52,0.10);padding:12px 14px;margin-bottom:12px; }
                .icon-psy-progress-top{ display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:8px;font-size:12px;color:var(--text-mid); }
                .icon-psy-progress-bar{ height:8px;background:rgba(148,163,184,0.35);border-radius:999px;overflow:hidden; }
                .icon-psy-progress-fill{ height:100%;width:0%;background-image:linear-gradient(135deg,var(--icon-blue),var(--icon-green));border-radius:999px;transition:width .35s ease; }

                .icon-psy-instructions{
                    background: linear-gradient(135deg, #ffffff 0%, #f7fffb 60%, #eef9ff 100%);
                    border-radius: 18px;
                    border: 1px solid rgba(20,164,207,.18);
                    box-shadow: 0 10px 24px rgba(10,59,52,0.10);
                    padding: 14px 16px;
                    margin-bottom: 12px;
                }
                .icon-psy-instructions h3{ margin:0 0 6px;font-size:14px;font-weight:900;color:var(--text-dark); }
                .icon-psy-instructions ul{ margin:0 0 0 18px;padding:0;font-size:12px;color:var(--text-mid); }
                .icon-psy-instructions li{ margin:4px 0; }

                .icon-psy-card{ background:#fff;border-radius:18px;border:1px solid #d1e7dd;box-shadow:0 10px 24px rgba(10,59,52,0.10);padding:14px 16px 12px;margin-bottom:12px; }
                .icon-psy-card-grid{ display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:14px;align-items:start; }
                @media(max-width:860px){ .icon-psy-card-grid{ grid-template-columns:1fr; } }

                .icon-psy-title{ font-size:15px;font-weight:800;color:var(--text-dark);margin:0 0 4px; }
                .icon-psy-desc{ font-size:12px;color:var(--text-light);margin:0 0 10px;line-height:1.45; }

                .icon-psy-lookfor{ margin:0 0 12px 18px;padding:0;font-size:12px;color:var(--text-mid); }
                .icon-psy-lookfor li{ margin:4px 0; }

                .icon-psy-image-box{
                    border-radius: 16px;
                    border: 1px solid rgba(20,164,207,0.18);
                    background: radial-gradient(circle at top left, rgba(20,164,207,0.08), rgba(21,160,109,0.06));
                    padding: 10px;
                    box-shadow: 0 14px 32px rgba(0,0,0,0.06);
                }
                .icon-psy-image-box img{ width:100%;height:auto;border-radius:12px;display:block; }
                .icon-psy-image-placeholder{
                    width:100%;aspect-ratio:4/3;border-radius:12px;border:1px dashed rgba(148,163,184,0.75);
                    background:rgba(249,250,251,0.7);display:flex;align-items:center;justify-content:center;
                    font-size:12px;color:var(--text-light);
                }

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

                .icon-psy-comments-grid{ display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;margin-top:12px; }
                .icon-psy-comment-card{ border-radius:14px;border:1px solid #d1e7dd;background:#f5fdf9;padding:10px 12px;font-size:13px;color:var(--text-dark); }
                .icon-psy-comment-label{ font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:4px; }
                .icon-psy-comment-card textarea{
                    width:100%;min-height:90px;border-radius:10px;border:1px solid #cbd5e1;padding:7px 9px;font-size:13px;resize:vertical;color:var(--text-dark);background:#fff;
                }
                .icon-psy-comment-card textarea:focus{ outline:none;border-color:var(--icon-green);box-shadow:0 0 0 1px rgba(21,160,109,0.40); }

                .icon-psy-btn-row{ margin-top:14px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap; }
                .icon-psy-btn-secondary{
                    display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#fff;border:1px solid rgba(21,149,136,0.35);
                    color:var(--icon-green);padding:9px 16px;font-size:13px;font-weight:800;cursor:pointer;text-decoration:none;white-space:nowrap;
                }
                .icon-psy-btn-secondary:hover{ background:rgba(230,249,255,0.65);border-color:rgba(20,164,207,0.6);color:var(--icon-blue); }
                .icon-psy-btn-primary{
                    background:linear-gradient(135deg,var(--icon-green),var(--icon-blue));border:1px solid #0f766e;color:#fff;padding:9px 18px;border-radius:999px;
                    font-size:13px;font-weight:900;cursor:pointer;box-shadow:0 14px 30px rgba(15,118,110,0.36);letter-spacing:.03em;text-transform:uppercase;
                }
                .icon-psy-btn-primary:hover{ box-shadow:0 18px 40px rgba(15,118,110,0.50);transform:translateY(-1px); }

                .icon-psy-anim-in{ animation: iconPsyIn .22s ease-out; }
                .icon-psy-anim-out{ animation: iconPsyOut .16s ease-in; }
                @keyframes iconPsyIn{ from{ opacity:0; transform: translateY(8px); } to{ opacity:1; transform: translateY(0); } }
                @keyframes iconPsyOut{ from{ opacity:1; transform: translateY(0); } to{ opacity:0; transform: translateY(-6px); } }
            </style>

            <div class="icon-psy-hero">
                <h2><?php echo $is_self_mode ? 'ICON Catalyst – Individual Leadership Self-Assessment' : 'ICON Catalyst – Rater Survey'; ?></h2>
                <p>
                    <?php if ( $is_self_mode ) : ?>
                        Self-assessment using the selected framework and competencies. Answer based on your last 4–8 weeks.
                    <?php else : ?>
                        You are providing confidential feedback on <strong><?php echo esc_html( $participant_name ); ?></strong>
                        <?php if ( $participant_role ) : ?>(<?php echo esc_html( $participant_role ); ?>)<?php endif; ?>.
                        Please rate how consistently they demonstrate each leadership behaviour.
                    <?php endif; ?>
                </p>

                <div class="icon-psy-chip-row">
                    <?php if ( $project_name ) : ?><span class="icon-psy-chip">Project: <?php echo esc_html( $project_name ); ?></span><?php endif; ?>
                    <?php if ( $client_name ) : ?><span class="icon-psy-chip-muted">Client: <?php echo esc_html( $client_name ); ?></span><?php endif; ?>
                    <span class="icon-psy-chip-muted">Framework: <?php echo esc_html( $framework_name ); ?></span>
                    <span class="icon-psy-chip-muted">Scale: 1 = very low, 7 = very high</span>
                    <?php if ( $is_self_mode ) : ?><span class="icon-psy-chip">For you only</span><?php endif; ?>
                </div>
            </div>

            <?php if ( $message ) : ?>
                <div class="icon-psy-notice <?php echo esc_attr( $message_class ); ?>"><?php echo esc_html( $message ); ?></div>
            <?php endif; ?>

            <?php if ( $rater_status !== 'completed' || $message_class === 'error' ) : ?>

                <!-- SHOW THESE INSTRUCTIONS ONLY FOR THE FIRST COMPETENCY (step 0) -->
                <div id="icon-psy-instructions-block" class="js-instructions-block">
                    <?php if ( $is_self_mode ) : ?>
                        <div class="icon-psy-instructions">
                            <h3>How to complete this self-assessment</h3>
                            <ul>
                                <li>Answer based on the last 4–8 weeks (not your best day).</li>
                                <li>Use evidence: decisions, behaviours and outcomes.</li>
                                <li>Be honest. This is for development, not a scorecard.</li>
                                <li>You must answer all 3 questions before moving to the next competency.</li>
                            </ul>
                        </div>
                    <?php else : ?>
                        <div class="icon-psy-notice info">
                            Please answer based on your honest impression. You do not need to over-think each rating.
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
                    <?php wp_nonce_field( 'icon_psy_rater_survey2' ); ?>
                    <input type="hidden" name="icon_psy_rater_survey2_submitted" value="1" />
                    <input type="hidden" name="rater_id" value="<?php echo esc_attr( (int) $rater->id ); ?>" />
                    <input type="hidden" name="participant_id" value="<?php echo esc_attr( $participant_id ); ?>" />
                    <input type="hidden" name="project_id" value="<?php echo esc_attr( $project_id ); ?>" />
                    <input type="hidden" name="framework_id" value="<?php echo esc_attr( (int) $framework_id ); ?>" />

                    <div class="icon-psy-competency-list">
                        <?php
                        $total_steps = count( $competencies );
                        $idx = 0;

                        foreach ( $competencies as $comp ) :
                            $cid  = (int) $comp->id;
                            $desc = $comp->description ? $comp->description : '';
                            $img_url = isset( $competency_images[ $idx ] ) ? $competency_images[ $idx ] : '';
                            $look_for = $is_self_mode
                                ? ( isset( $look_for_map_self[ $idx ] ) ? $look_for_map_self[ $idx ] : array() )
                                : ( isset( $look_for_map_rater[ $idx ] ) ? $look_for_map_rater[ $idx ] : array() );
                            ?>
                            <div class="icon-psy-card js-competency-card"
                                 data-step="<?php echo esc_attr( $idx ); ?>"
                                 data-total="<?php echo esc_attr( $total_steps ); ?>"
                                 style="<?php echo $idx === 0 ? '' : 'display:none;'; ?>">

                                <div class="icon-psy-card-grid">
                                    <div>
                                        <p class="icon-psy-title"><?php echo esc_html( $comp->name ); ?></p>
                                        <?php if ( $desc !== '' ) : ?><p class="icon-psy-desc"><?php echo esc_html( $desc ); ?></p><?php endif; ?>

                                        <?php if ( ! empty( $look_for ) ) : ?>
                                            <div style="font-size:11px;font-weight:900;color:var(--text-mid);margin:8px 0 6px;">
                                                <?php echo $is_self_mode ? 'What to look for in myself' : 'What to look for'; ?>
                                            </div>
                                            <ul class="icon-psy-lookfor">
                                                <?php foreach ( $look_for as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>

                                        <!-- Q1 -->
                                        <div class="icon-psy-scale-block">
                                            <div class="icon-psy-scale-label"><?php echo $is_self_mode ? 'Day-to-day' : 'Everyday impact'; ?></div>
                                            <?php if ( $is_self_mode ) : ?>
                                                <div class="icon-psy-hint"><ul><?php foreach ( $q_hints_self['q1'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?></ul></div>
                                            <?php endif; ?>
                                            <div class="icon-psy-scale">
                                                <?php for ( $i = 1; $i <= 7; $i++ ) : $id = "cid_{$cid}_q1_{$i}"; ?>
                                                    <input type="radio" id="<?php echo esc_attr( $id ); ?>" name="scores[<?php echo esc_attr( $cid ); ?>][q1]" value="<?php echo esc_attr( $i ); ?>">
                                                    <label for="<?php echo esc_attr( $id ); ?>" data-num="<?php echo esc_attr( $i ); ?>"></label>
                                                <?php endfor; ?>
                                            </div>
                                        </div>

                                        <!-- Q2 -->
                                        <div class="icon-psy-scale-block">
                                            <div class="icon-psy-scale-label">Under pressure</div>
                                            <?php if ( $is_self_mode ) : ?>
                                                <div class="icon-psy-hint"><ul><?php foreach ( $q_hints_self['q2'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?></ul></div>
                                            <?php endif; ?>
                                            <div class="icon-psy-scale">
                                                <?php for ( $i = 1; $i <= 7; $i++ ) : $id = "cid_{$cid}_q2_{$i}"; ?>
                                                    <input type="radio" id="<?php echo esc_attr( $id ); ?>" name="scores[<?php echo esc_attr( $cid ); ?>][q2]" value="<?php echo esc_attr( $i ); ?>">
                                                    <label for="<?php echo esc_attr( $id ); ?>" data-num="<?php echo esc_attr( $i ); ?>"></label>
                                                <?php endfor; ?>
                                            </div>
                                        </div>

                                        <!-- Q3 -->
                                        <div class="icon-psy-scale-block">
                                            <div class="icon-psy-scale-label">Role-modelling</div>
                                            <?php if ( $is_self_mode ) : ?>
                                                <div class="icon-psy-hint"><ul><?php foreach ( $q_hints_self['q3'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?></ul></div>
                                            <?php endif; ?>
                                            <div class="icon-psy-scale">
                                                <?php for ( $i = 1; $i <= 7; $i++ ) : $id = "cid_{$cid}_q3_{$i}"; ?>
                                                    <input type="radio" id="<?php echo esc_attr( $id ); ?>" name="scores[<?php echo esc_attr( $cid ); ?>][q3]" value="<?php echo esc_attr( $i ); ?>">
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

                    <!-- Comments only on final step -->
                    <div class="js-comments-block" style="display:none;">
                        <div class="icon-psy-comments-grid">
                            <div class="icon-psy-comment-card">
                                <div class="icon-psy-comment-label"><?php echo $is_self_mode ? 'Key strengths' : 'Key strengths'; ?></div>
                                <textarea name="q2_text" id="icon-psy-q2-text" placeholder="<?php echo esc_attr( $is_self_mode ? 'What do I do particularly well as a leader?' : 'What do they do particularly well as a leader?' ); ?>"></textarea>
                            </div>
                            <div class="icon-psy-comment-card">
                                <div class="icon-psy-comment-label"><?php echo $is_self_mode ? 'Development priorities' : 'Development priorities'; ?></div>
                                <textarea name="q3_text" id="icon-psy-q3-text" placeholder="<?php echo esc_attr( $is_self_mode ? 'Where should I focus next?' : 'Where would it be most valuable for them to grow next?' ); ?>"></textarea>
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

                    // NEW: instructions wrapper (only show on step 0)
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

add_shortcode( 'icon_psy_rater_survey2', 'icon_psy_rater_survey2' );
