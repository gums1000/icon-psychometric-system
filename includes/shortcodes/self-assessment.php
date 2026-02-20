<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Individual / Self Leadership Assessment (Self-Assessment)
 * Shortcode: [icon_psy_self_assessment]
 *
 * WORDING UPDATE ONLY (self-assessment language):
 * - Keeps DB logic, fields, locations identical
 * - Updates all user-facing copy to reflect “I am assessing myself”
 * - Maintains:
 *   - Instruction card
 *   - Per-question hint text (self language)
 *   - “What to look for” bullets
 *   - Optional competency image (index-based)
 */
if ( ! function_exists( 'icon_psy_self_assessment' ) ) {

    function icon_psy_self_assessment( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p>You need to be logged in to complete your self-assessment.</p>';
        }

        global $wpdb;

        // Ensure schema
        if ( function_exists( 'icon_psy_maybe_add_self_user_id_to_results_table' ) ) {
            icon_psy_maybe_add_self_user_id_to_results_table();
        }

        $frameworks_table   = $wpdb->prefix . 'icon_psy_frameworks';
        $competencies_table = $wpdb->prefix . 'icon_psy_framework_competencies';
        $results_table      = $wpdb->prefix . 'icon_assessment_results';

        $current_user_id = (int) get_current_user_id();

        // Load frameworks (active/published)
        $frameworks = $wpdb->get_results(
            "SELECT id, name, is_default
             FROM {$frameworks_table}
             WHERE status IN ('active','published')
             ORDER BY is_default DESC, name ASC"
        );

        if ( empty( $frameworks ) ) {
            return '<p>No active frameworks found. Please create and publish a framework first.</p>';
        }

        // Selected framework
        $selected_framework_id = 0;
        if ( isset( $_GET['framework_id'] ) ) {
            $selected_framework_id = (int) $_GET['framework_id'];
        }
        if ( $selected_framework_id <= 0 ) {
            foreach ( $frameworks as $fw ) {
                if ( (int) $fw->is_default === 1 ) {
                    $selected_framework_id = (int) $fw->id;
                    break;
                }
            }
            if ( $selected_framework_id <= 0 ) {
                $selected_framework_id = (int) $frameworks[0]->id;
            }
        }

        // Fetch selected framework row (protect against invalid id)
        $framework = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$frameworks_table}
                 WHERE id = %d AND status IN ('active','published')
                 LIMIT 1",
                $selected_framework_id
            )
        );

        if ( ! $framework ) {
            $framework = $wpdb->get_row(
                "SELECT * FROM {$frameworks_table}
                 WHERE status IN ('active','published')
                 ORDER BY is_default DESC, id ASC
                 LIMIT 1"
            );
        }

        $framework_id   = (int) $framework->id;
        $framework_name = $framework->name ? $framework->name : 'Leadership Framework';

        $competencies = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, description
                 FROM {$competencies_table}
                 WHERE framework_id = %d
                 ORDER BY sort_order ASC, id ASC",
                $framework_id
            )
        );

        if ( empty( $competencies ) ) {
            return '<p>This framework has no competencies yet. Add competencies and try again.</p>';
        }

        // Handle submission (UNCHANGED)
        $message       = '';
        $message_class = '';

        if ( isset( $_POST['icon_psy_self_assessment_submitted'] ) && '1' === (string) $_POST['icon_psy_self_assessment_submitted'] ) {

            check_admin_referer( 'icon_psy_self_assessment' );

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
                $message       = 'Please score the leadership areas before submitting.';
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
                        'participant_id' => 0,
                        'rater_id'       => 0,
                        'self_user_id'   => $current_user_id,
                        'project_id'     => 0,
                        'framework_id'   => $framework_id,
                        'q1_rating'      => $overall_rating,
                        'q2_text'        => $strength,
                        'q3_text'        => $develop,
                        'detail_json'    => $detail_json,
                        'status'         => 'completed',
                        'created_at'     => current_time( 'mysql' ),
                    ),
                    array( '%d','%d','%d','%d','%d','%f','%s','%s','%s','%s','%s' )
                );

                if ( false === $inserted ) {
                    $message       = 'We hit a problem saving your self-assessment. Please try again.';
                    $message_class = 'error';
                } else {
                    $message       = 'Saved — your self-assessment is complete.';
                    $message_class = 'success';
                }
            }
        }

        // 8 URLs (same set as rater)
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

        // “What to look for” (index-based fallback) - already self language
        $look_for_map = array(
            0 => array(
                'I think ahead and prepare, not just react.',
                'I connect daily actions to longer-term outcomes.',
                'I weigh options and make sensible trade-offs.',
                'I communicate direction clearly (even when it’s messy).',
                'I stay curious and ask better questions.'
            ),
            1 => array(
                'I build trust through consistent actions.',
                'I set clear expectations and follow through.',
                'I coach and develop others (not rescue them).',
                'I handle difficult conversations respectfully.',
                'I create confidence through calm leadership.'
            ),
            2 => array(
                'I share information early, not late.',
                'I work across teams without “silo” thinking.',
                'I seek input before deciding.',
                'I deal with disagreement constructively.',
                'I align people to a common goal.'
            ),
            3 => array(
                'I genuinely understand stakeholder needs.',
                'I respond constructively to feedback.',
                'I improve experience through action (not promises).',
                'I balance service and outcomes.',
                'I treat people with respect and professionalism.'
            ),
            4 => array(
                'I deliver on commitments and follow through.',
                'I keep priorities clear when things change.',
                'I maintain momentum under pressure.',
                'I focus on outcomes, not activity.',
                'I remove obstacles rather than accept delays.'
            ),
            5 => array(
                'I stay steady in difficult moments.',
                'I adapt my approach when challenged.',
                'I take accountability (no blame games).',
                'I model positive behaviour consistently.',
                'I recover quickly after setbacks.'
            ),
            6 => array(
                'I set the behavioural standard for others.',
                'I live the agreed values consistently.',
                'I influence through behaviour, not authority.',
                'I encourage ownership and accountability.',
                'I act with integrity when it’s hard.'
            ),
            7 => array(
                'I inspire confidence in others.',
                'I represent the organisation positively.',
                'I lead by example, not slogans.',
                'I create clarity and direction.',
                'I build belief and pride in the team.'
            ),
        );

        // Per-question “hints” (self language)
        $q_hints = array(
            'q1' => array(
                'Think about a normal week.',
                'How consistently do I show this behaviour when nothing is “on fire”?',
                'Use evidence: meetings, decisions, emails, conversations.'
            ),
            'q2' => array(
                'Think about stress, conflict, time pressure, or uncertainty.',
                'Do I stay effective… or do I slip into unhelpful habits?',
                'Score based on what actually happens, not what I want to happen.'
            ),
            'q3' => array(
                'If someone copied my behaviour, would it be a good example?',
                'Do I set the standard visibly and consistently?',
                'This is about impact — not intention.'
            ),
        );

        $autosave_key = 'icon_psy_self_assessment_u' . $current_user_id . '_fw' . $framework_id;

        ob_start();
        ?>
        <div class="icon-psy-self-wrap" style="max-width:980px;margin:0 auto;padding:22px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">

            <style>
                :root{
                    --icon-green:#15a06d;
                    --icon-blue:#14a4cf;
                    --text-dark:#0a3b34;
                    --text-mid:#425b56;
                    --text-light:#6a837d;
                }

                .icon-psy-hero{
                    background: radial-gradient(circle at top left, #e6f4ef 0, #ecfdf5 40%, #e0f2fe 100%);
                    border-radius: 20px;
                    padding: 18px 20px 16px;
                    border: 1px solid rgba(21,160,109,0.25);
                    box-shadow: 0 20px 40px rgba(10,59,52,0.20);
                    margin-bottom: 14px;
                }
                .icon-psy-hero h2{
                    margin:0 0 6px;
                    font-size:22px;
                    font-weight:800;
                    color:var(--text-dark);
                    letter-spacing:.02em;
                }
                .icon-psy-hero p{
                    margin:0;
                    font-size:13px;
                    color:var(--text-mid);
                }

                .icon-psy-chip-row{
                    display:flex;
                    flex-wrap:wrap;
                    gap:6px;
                    margin-top:10px;
                    font-size:11px;
                }
                .icon-psy-chip{
                    padding:3px 9px;
                    border-radius:999px;
                    border:1px solid var(--icon-green);
                    background:#ecfdf5;
                    color:var(--text-dark);
                }
                .icon-psy-chip-muted{
                    padding:3px 9px;
                    border-radius:999px;
                    border:1px solid rgba(20,164,207,.35);
                    background:#eff6ff;
                    color:#1e3a8a;
                }

                .icon-psy-notice{
                    margin-bottom: 14px;
                    padding: 10px 12px;
                    border-radius: 12px;
                    border-width: 1px;
                    border-style: solid;
                    font-size: 12px;
                }
                .icon-psy-notice.success{border-color:#bbf7d0;background:#ecfdf5;color:#166534;}
                .icon-psy-notice.error{border-color:#fecaca;background:#fef2f2;color:#b91c1c;}

                /* Progress */
                .icon-psy-progress-wrap{
                    background:#ffffff;
                    border-radius:16px;
                    border:1px solid #d1e7dd;
                    box-shadow:0 10px 24px rgba(10,59,52,0.10);
                    padding:12px 14px;
                    margin-bottom: 12px;
                }
                .icon-psy-progress-top{
                    display:flex;
                    justify-content:space-between;
                    gap:10px;
                    align-items:center;
                    margin-bottom:8px;
                    font-size:12px;
                    color: var(--text-mid);
                }
                .icon-psy-progress-bar{
                    height:8px;
                    background: rgba(148,163,184,0.35);
                    border-radius:999px;
                    overflow:hidden;
                }
                .icon-psy-progress-fill{
                    height:100%;
                    width:0%;
                    background-image: linear-gradient(135deg, var(--icon-blue), var(--icon-green));
                    border-radius:999px;
                    transition: width .35s ease;
                }

                /* Instruction card */
                .icon-psy-instructions{
                    background: linear-gradient(135deg, #ffffff 0%, #f7fffb 60%, #eef9ff 100%);
                    border-radius: 18px;
                    border: 1px solid rgba(20,164,207,.18);
                    box-shadow: 0 10px 24px rgba(10,59,52,0.10);
                    padding: 14px 16px;
                    margin-bottom: 12px;
                }
                .icon-psy-instructions h3{
                    margin:0 0 6px;
                    font-size:14px;
                    font-weight:900;
                    color:var(--text-dark);
                }
                .icon-psy-instructions ul{
                    margin:0 0 0 18px;
                    padding:0;
                    font-size:12px;
                    color:var(--text-mid);
                }
                .icon-psy-instructions li{ margin:4px 0; }

                /* Card */
                .icon-psy-card{
                    background:#ffffff;
                    border-radius: 18px;
                    border: 1px solid #d1e7dd;
                    box-shadow: 0 10px 24px rgba(10,59,52,0.10);
                    padding: 14px 16px 12px;
                    margin-bottom: 12px;
                }
                .icon-psy-card-grid{
                    display:grid;
                    grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);
                    gap:14px;
                    align-items:start;
                }
                @media (max-width: 860px){
                    .icon-psy-card-grid{ grid-template-columns:1fr; }
                }
                .icon-psy-card h3{
                    margin:0 0 4px;
                    font-size:15px;
                    font-weight:800;
                    color:var(--text-dark);
                }
                .icon-psy-card .sub{
                    margin:0 0 10px;
                    font-size:12px;
                    color:var(--text-light);
                    line-height:1.45;
                }

                /* “Look for” bullets */
                .icon-psy-lookfor{
                    margin: 0 0 12px 18px;
                    padding: 0;
                    font-size: 12px;
                    color: var(--text-mid);
                }
                .icon-psy-lookfor li{ margin:4px 0; }

                /* Image box */
                .icon-psy-image-box{
                    border-radius: 16px;
                    border: 1px solid rgba(20,164,207,0.18);
                    background: radial-gradient(circle at top left, rgba(20,164,207,0.08), rgba(21,160,109,0.06));
                    padding: 10px;
                    box-shadow: 0 14px 32px rgba(0,0,0,0.06);
                }
                .icon-psy-image-box img{
                    width:100%;
                    height:auto;
                    border-radius: 12px;
                    display:block;
                }

                /* Scale blocks */
                .icon-psy-scale-block { margin-bottom: 12px; }
                .icon-psy-scale-label{
                    font-size:11px;
                    color:var(--text-mid);
                    margin-bottom:6px;
                    font-weight:800;
                }
                .icon-psy-hint{
                    font-size:11px;
                    color:var(--text-light);
                    margin: -2px 0 8px;
                    line-height: 1.4;
                }
                .icon-psy-hint ul{
                    margin: 6px 0 0 18px;
                    padding: 0;
                }
                .icon-psy-hint li{ margin: 3px 0; }

                /* Dots with numbers under */
                .icon-psy-scale{
                    display:grid;
                    grid-template-columns:repeat(7,minmax(28px,1fr));
                    gap:6px;
                    align-items:start;
                }
                .icon-psy-scale input[type="radio"]{display:none;}
                .icon-psy-scale label{
                    position:relative;
                    width:100%;
                    min-height:34px;
                    display:flex;
                    align-items:flex-start;
                    justify-content:center;
                    cursor:pointer;
                    user-select:none;
                }
                .icon-psy-scale label::before{
                    content:"";
                    width:14px;height:14px;
                    border-radius:999px;
                    border:2px solid rgba(107,114,128,0.55);
                    background:#ffffff;
                    margin-top:2px;
                    transition: transform .10s ease, border-color .10s ease, box-shadow .10s ease, background .10s ease;
                }
                .icon-psy-scale label::after{
                    content: attr(data-num);
                    position:absolute;
                    top:18px;
                    font-size:11px;
                    color:var(--text-mid);
                    font-variant-numeric: tabular-nums;
                }
                .icon-psy-scale label:hover::before{
                    border-color:var(--icon-green);
                    box-shadow:0 0 0 2px rgba(21,160,109,0.14);
                    transform: translateY(-1px);
                }
                .icon-psy-scale input[type="radio"]:checked + label::before{
                    border-color:var(--icon-green);
                    background-image:linear-gradient(135deg,var(--icon-green),var(--icon-blue));
                    box-shadow:0 10px 18px rgba(21,160,109,0.28);
                    transform: translateY(-1px);
                }
                .icon-psy-scale input[type="radio"]:checked + label::after{
                    color:var(--text-dark);
                    font-weight:900;
                }

                /* Comments */
                .icon-psy-comments-grid{
                    display:grid;
                    grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
                    gap:10px;
                    margin-top:12px;
                }
                .icon-psy-comment-card{
                    border-radius: 14px;
                    border: 1px solid #d1e7dd;
                    background: #f5fdf9;
                    padding: 10px 12px;
                    font-size: 13px;
                    color: var(--text-dark);
                }
                .icon-psy-comment-label{
                    font-size: 11px;
                    text-transform: uppercase;
                    letter-spacing: 0.08em;
                    color: #6b7280;
                    margin-bottom: 4px;
                }
                .icon-psy-comment-card textarea{
                    width:100%;
                    min-height:90px;
                    border-radius: 10px;
                    border: 1px solid #cbd5e1;
                    padding: 7px 9px;
                    font-size: 13px;
                    resize: vertical;
                    color: var(--text-dark);
                    background:#fff;
                }
                .icon-psy-comment-card textarea:focus{
                    outline:none;
                    border-color:var(--icon-green);
                    box-shadow:0 0 0 1px rgba(21,160,109,0.40);
                }

                /* Buttons */
                .icon-psy-btn-row{
                    margin-top: 14px;
                    display:flex;
                    justify-content: space-between;
                    gap: 10px;
                    flex-wrap: wrap;
                }
                .icon-psy-btn-secondary{
                    display:inline-flex;
                    align-items:center;
                    justify-content:center;
                    border-radius: 999px;
                    background: #ffffff;
                    border: 1px solid rgba(21,149,136,0.35);
                    color: var(--icon-green);
                    padding: 9px 16px;
                    font-size: 13px;
                    font-weight: 800;
                    cursor: pointer;
                    text-decoration: none;
                    white-space: nowrap;
                }
                .icon-psy-btn-secondary:hover{
                    background: rgba(230,249,255,0.65);
                    border-color: rgba(20,164,207,0.6);
                    color: var(--icon-blue);
                }
                .icon-psy-btn-primary{
                    background: linear-gradient(135deg,var(--icon-green),var(--icon-blue));
                    border: 1px solid #0f766e;
                    color: #ffffff;
                    padding: 9px 18px;
                    border-radius: 999px;
                    font-size: 13px;
                    font-weight: 900;
                    cursor: pointer;
                    box-shadow: 0 14px 30px rgba(15,118,110,0.36);
                    letter-spacing: 0.03em;
                    text-transform: uppercase;
                }
                .icon-psy-btn-primary:hover{
                    box-shadow: 0 18px 40px rgba(15,118,110,0.50);
                    transform: translateY(-1px);
                }

                /* Framework select */
                .icon-psy-select{
                    border-radius:999px;
                    border:1px solid rgba(148,163,184,.6);
                    padding:8px 12px;
                    font-size:13px;
                    background:#fff;
                }

                /* Animations */
                .icon-psy-anim-in{ animation: iconPsyIn .22s ease-out; }
                .icon-psy-anim-out{ animation: iconPsyOut .16s ease-in; }
                @keyframes iconPsyIn{ from{ opacity:0; transform: translateY(8px); } to{ opacity:1; transform: translateY(0); } }
                @keyframes iconPsyOut{ from{ opacity:1; transform: translateY(0); } to{ opacity:0; transform: translateY(-6px); } }
            </style>

            <div class="icon-psy-hero">
                <h2>ICON Catalyst – Leadership Self-Assessment</h2>
                <p>This is a self-assessment: you are rating your own behaviours against your selected framework and competencies.</p>

                <div class="icon-psy-chip-row">
                    <span class="icon-psy-chip-muted">Framework: <?php echo esc_html( $framework_name ); ?></span>
                    <span class="icon-psy-chip-muted">Scale: 1 = very low, 7 = very high</span>
                    <span class="icon-psy-chip">Private to me</span>
                </div>

                <div style="margin-top:12px;">
                    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <label style="font-size:12px;color:var(--text-mid);font-weight:800;">Change framework:</label>
                        <select name="framework_id" class="icon-psy-select" onchange="this.form.submit()">
                            <?php foreach ( $frameworks as $fw ) : ?>
                                <option value="<?php echo (int) $fw->id; ?>" <?php selected( $framework_id, (int) $fw->id ); ?>>
                                    <?php echo esc_html( $fw->name ); ?><?php echo ( (int) $fw->is_default === 1 ) ? ' (core)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>

            <?php if ( $message ) : ?>
                <div class="icon-psy-notice <?php echo esc_attr( $message_class ); ?>">
                    <?php echo esc_html( $message ); ?>
                </div>
            <?php endif; ?>

            <!-- Instruction card -->
            <div class="icon-psy-instructions">
                <h3>How to complete my self-assessment</h3>
                <ul>
                    <li>I answer based on the last 4–8 weeks (not my best day).</li>
                    <li>I use evidence: real decisions, behaviours, and outcomes.</li>
                    <li>I stay honest. This is for my development, not a judgement.</li>
                    <li>I must answer all 3 questions before moving to the next competency.</li>
                </ul>
            </div>

            <div class="icon-psy-progress-wrap" id="icon-psy-self-progress">
                <div class="icon-psy-progress-top">
                    <div><strong>Progress</strong> <span id="icon-psy-self-progress-text" style="font-weight:600;"></span></div>
                    <div id="icon-psy-self-autosave" style="font-size:11px;color:var(--text-light);">Autosave: on</div>
                </div>
                <div class="icon-psy-progress-bar">
                    <div class="icon-psy-progress-fill" id="icon-psy-self-progress-fill"></div>
                </div>
            </div>

            <form method="post" id="icon-psy-self-form">
                <?php wp_nonce_field( 'icon_psy_self_assessment' ); ?>
                <input type="hidden" name="icon_psy_self_assessment_submitted" value="1" />

                <div class="icon-psy-self-cards">
                    <?php
                    $total_steps = count( $competencies );
                    $idx = 0;

                    foreach ( $competencies as $comp ) :
                        $cid  = (int) $comp->id;
                        $desc = $comp->description ? $comp->description : 'Use honest reflection based on recent real situations.';
                        $img_url = isset( $competency_images[ $idx ] ) ? $competency_images[ $idx ] : '';
                        $look_for = isset( $look_for_map[ $idx ] ) ? $look_for_map[ $idx ] : array();
                        ?>
                        <div class="icon-psy-card js-self-card"
                             data-step="<?php echo esc_attr( $idx ); ?>"
                             data-total="<?php echo esc_attr( $total_steps ); ?>"
                             style="<?php echo $idx === 0 ? '' : 'display:none;'; ?>">

                            <div class="icon-psy-card-grid">

                                <!-- LEFT -->
                                <div>
                                    <h3><?php echo esc_html( $comp->name ); ?></h3>
                                    <p class="sub"><?php echo esc_html( $desc ); ?></p>

                                    <?php if ( ! empty( $look_for ) ) : ?>
                                        <div style="font-size:11px;font-weight:900;color:var(--text-mid);margin:8px 0 6px;">What to look for in myself</div>
                                        <ul class="icon-psy-lookfor">
                                            <?php foreach ( $look_for as $item ) : ?>
                                                <li><?php echo esc_html( $item ); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>

                                    <!-- Q1 -->
                                    <div class="icon-psy-scale-block">
                                        <div class="icon-psy-scale-label">My day-to-day behaviour</div>
                                        <div class="icon-psy-hint">
                                            <ul>
                                                <?php foreach ( $q_hints['q1'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?>
                                            </ul>
                                        </div>
                                        <div class="icon-psy-scale">
                                            <?php for ( $i = 1; $i <= 7; $i++ ) :
                                                $id = "cid_{$cid}_q1_{$i}";
                                                ?>
                                                <input type="radio" id="<?php echo esc_attr( $id ); ?>" name="scores[<?php echo esc_attr( $cid ); ?>][q1]" value="<?php echo esc_attr( $i ); ?>">
                                                <label for="<?php echo esc_attr( $id ); ?>" data-num="<?php echo esc_attr( $i ); ?>"></label>
                                            <?php endfor; ?>
                                        </div>
                                    </div>

                                    <!-- Q2 -->
                                    <div class="icon-psy-scale-block">
                                        <div class="icon-psy-scale-label">My behaviour under pressure</div>
                                        <div class="icon-psy-hint">
                                            <ul>
                                                <?php foreach ( $q_hints['q2'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?>
                                            </ul>
                                        </div>
                                        <div class="icon-psy-scale">
                                            <?php for ( $i = 1; $i <= 7; $i++ ) :
                                                $id = "cid_{$cid}_q2_{$i}";
                                                ?>
                                                <input type="radio" id="<?php echo esc_attr( $id ); ?>" name="scores[<?php echo esc_attr( $cid ); ?>][q2]" value="<?php echo esc_attr( $i ); ?>">
                                                <label for="<?php echo esc_attr( $id ); ?>" data-num="<?php echo esc_attr( $i ); ?>"></label>
                                            <?php endfor; ?>
                                        </div>
                                    </div>

                                    <!-- Q3 -->
                                    <div class="icon-psy-scale-block">
                                        <div class="icon-psy-scale-label">How I role-model to others</div>
                                        <div class="icon-psy-hint">
                                            <ul>
                                                <?php foreach ( $q_hints['q3'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?>
                                            </ul>
                                        </div>
                                        <div class="icon-psy-scale">
                                            <?php for ( $i = 1; $i <= 7; $i++ ) :
                                                $id = "cid_{$cid}_q3_{$i}";
                                                ?>
                                                <input type="radio" id="<?php echo esc_attr( $id ); ?>" name="scores[<?php echo esc_attr( $cid ); ?>][q3]" value="<?php echo esc_attr( $i ); ?>">
                                                <label for="<?php echo esc_attr( $id ); ?>" data-num="<?php echo esc_attr( $i ); ?>"></label>
                                            <?php endfor; ?>
                                        </div>
                                    </div>

                                    <div class="icon-psy-btn-row">
                                        <button type="button" class="icon-psy-btn-secondary js-self-prev">Back</button>
                                        <?php if ( $idx === $total_steps - 1 ) : ?>
                                            <button type="button" class="icon-psy-btn-primary js-self-next">Finish</button>
                                        <?php else : ?>
                                            <button type="button" class="icon-psy-btn-primary js-self-next">Next</button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- RIGHT -->
                                <div>
                                    <div class="icon-psy-image-box">
                                        <?php if ( ! empty( $img_url ) ) : ?>
                                            <img src="<?php echo esc_url( $img_url ); ?>" alt="" loading="lazy" />
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

                <!-- Comments only after final competency -->
                <div class="js-self-comments" style="display:none;">
                    <div class="icon-psy-card">
                        <h3>My final reflections</h3>
                        <p class="sub">These notes are for my own development plan.</p>

                        <div class="icon-psy-comments-grid">
                            <div class="icon-psy-comment-card">
                                <div class="icon-psy-comment-label">My key strengths</div>
                                <textarea name="q2_text" id="icon-psy-self-q2" placeholder="What do I do particularly well? What evidence do I have?"></textarea>
                            </div>
                            <div class="icon-psy-comment-card">
                                <div class="icon-psy-comment-label">My development priorities</div>
                                <textarea name="q3_text" id="icon-psy-self-q3" placeholder="Where should I focus next, and what will I do differently?"></textarea>
                            </div>
                        </div>

                        <div style="margin-top:14px;display:flex;justify-content:flex-end;">
                            <button type="submit" class="icon-psy-btn-primary" id="icon-psy-self-submit">Save my self-assessment</button>
                        </div>
                    </div>
                </div>

            </form>

            <script>
            (function(){
                const autosaveKey = <?php echo wp_json_encode( $autosave_key ); ?>;

                const form = document.getElementById('icon-psy-self-form');
                const cards = Array.from(document.querySelectorAll('.js-self-card'));
                const totalSteps = cards.length;

                const commentsWrap = document.querySelector('.js-self-comments');
                const q2 = document.getElementById('icon-psy-self-q2');
                const q3 = document.getElementById('icon-psy-self-q3');

                const progressText = document.getElementById('icon-psy-self-progress-text');
                const progressFill = document.getElementById('icon-psy-self-progress-fill');
                const autosaveLabel = document.getElementById('icon-psy-self-autosave');

                let step = 0;

                function setAutosave(msg){
                    if(!autosaveLabel) return;
                    autosaveLabel.textContent = msg;
                    clearTimeout(setAutosave._t);
                    setAutosave._t = setTimeout(()=>{ autosaveLabel.textContent = 'Autosave: on'; }, 900);
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

                    if(commentsWrap){
                        commentsWrap.style.display = (newStep === totalSteps - 1) ? 'block' : 'none';
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
                    updateProgress();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }

                function currentAnswered(){
                    const card = cards[step];
                    if(!card) return false;
                    const checked = card.querySelectorAll('input[type="radio"]:checked').length;
                    return checked >= 3;
                }

                function serialize(){
                    const data = {};
                    const checked = form.querySelectorAll('input[type="radio"]:checked');
                    checked.forEach((i)=>{ data[i.name] = i.value; });
                    data['_step'] = step;
                    data['q2_text'] = q2 ? (q2.value || '') : '';
                    data['q3_text'] = q3 ? (q3.value || '') : '';
                    return data;
                }

                function restore(saved){
                    if(!saved) return;

                    Object.keys(saved).forEach((k)=>{
                        if(k === '_step' || k === 'q2_text' || k === 'q3_text') return;
                        const val = saved[k];
                        const selector = `input[type="radio"][name="${CSS.escape(k)}"][value="${CSS.escape(String(val))}"]`;
                        const el = form.querySelector(selector);
                        if(el) el.checked = true;
                    });

                    if(q2 && typeof saved.q2_text === 'string') q2.value = saved.q2_text;
                    if(q3 && typeof saved.q3_text === 'string') q3.value = saved.q3_text;

                    if(typeof saved._step === 'number' && saved._step >= 0 && saved._step < totalSteps){
                        cards.forEach((c, idx)=>{ c.style.display = (idx === saved._step) ? 'block' : 'none'; });
                        step = saved._step;
                    } else {
                        cards.forEach((c, idx)=>{ c.style.display = (idx === 0) ? 'block' : 'none'; });
                        step = 0;
                    }

                    if(commentsWrap){
                        commentsWrap.style.display = (step === totalSteps - 1) ? 'block' : 'none';
                    }

                    updateProgress();
                }

                function saveNow(){
                    try{
                        localStorage.setItem(autosaveKey, JSON.stringify(serialize()));
                        setAutosave('Autosaved ✓');
                    }catch(e){}
                }

                // restore autosave on load
                try{
                    const raw = localStorage.getItem(autosaveKey);
                    if(raw){
                        restore(JSON.parse(raw));
                    }else{
                        updateProgress();
                    }
                }catch(e){
                    updateProgress();
                }

                // autosave on changes
                document.addEventListener('change', function(e){
                    if(!form.contains(e.target)) return;
                    saveNow();
                });
                document.addEventListener('input', function(e){
                    if(!form.contains(e.target)) return;
                    if(e.target && (e.target.id === 'icon-psy-self-q2' || e.target.id === 'icon-psy-self-q3')){
                        saveNow();
                    }
                });

                // nav buttons
                document.addEventListener('click', function(e){
                    const nextBtn = e.target.closest('.js-self-next');
                    const prevBtn = e.target.closest('.js-self-prev');

                    if(prevBtn){
                        e.preventDefault();
                        if(step === 0) return;
                        showCard(step - 1);
                        saveNow();
                        return;
                    }

                    if(nextBtn){
                        e.preventDefault();

                        if(!currentAnswered()){
                            alert('Please answer all three questions before continuing.');
                            return;
                        }

                        if(step === totalSteps - 1){
                            if(commentsWrap){
                                commentsWrap.style.display = 'block';
                                window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
                            }
                            saveNow();
                            return;
                        }

                        showCard(step + 1);
                        saveNow();
                    }
                });

                // on real submit: clear autosave
                form.addEventListener('submit', function(){
                    try{ localStorage.removeItem(autosaveKey); }catch(e){}
                });

            })();
            </script>

        </div>
        <?php
        return ob_get_clean();
    }
}

add_shortcode( 'icon_psy_self_assessment', 'icon_psy_self_assessment' );
