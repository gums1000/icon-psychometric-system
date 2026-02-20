<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Self Assessment Portal (Portal hub + list + expanded coaching report + optional PDF)
 * Shortcode: [icon_psy_self_portal]
 *
 * Report view: ?self_result_id=123
 * PDF export:  ?self_result_id=123&export=pdf  (only if \Dompdf\Dompdf is available)
 *
 * SELF-ONLY LANGUAGE:
 * - No “raters”, no “feedback from others”, no “perceived”
 * - Everything is framed as self-reflection + coaching guidance
 */

if ( ! function_exists( 'icon_psy_self_portal' ) ) {

    function icon_psy_self_coaching_band( $avg ) {
        $avg = (float) $avg;

        if ( $avg >= 6.0 ) {
            return array(
                'label' => 'Strength (High)',
                'tone'  => 'success',
                'headline' => 'This is a clear strength for you.',
                'what_it_means' => 'You are demonstrating this consistently and at a level you can build on.',
                'watch_out' => 'Avoid complacency. Under pressure, keep your standards steady.',
                'next_step' => 'Pick one moment this week to deliberately role-model this behaviour in a visible way.',
            );
        }

        if ( $avg >= 4.6 ) {
            return array(
                'label' => 'Solid (Developing)',
                'tone'  => 'info',
                'headline' => 'You are doing this well, but it is not yet fully consistent.',
                'what_it_means' => 'You have capability here, but it varies by context, energy, time pressure, or stakeholder.',
                'watch_out' => 'In busy weeks you may revert to “get it done” mode and skip the behaviour.',
                'next_step' => 'Choose one trigger situation where you slip (e.g., conflict / deadlines) and plan a new response.',
            );
        }

        if ( $avg >= 3.2 ) {
            return array(
                'label' => 'Priority (Needs Focus)',
                'tone'  => 'warning',
                'headline' => 'This is a development priority.',
                'what_it_means' => 'The behaviour is not yet showing reliably, which may limit your leadership impact.',
                'watch_out' => 'If unaddressed, this can create confusion, inconsistent standards, or reduced trust.',
                'next_step' => 'Pick one simple practice you can repeat daily for 10 days (small change, high consistency).',
            );
        }

        return array(
            'label' => 'Risk Area (Low)',
            'tone'  => 'danger',
            'headline' => 'This is currently a risk area.',
            'what_it_means' => 'Your leadership impact may be reduced here, especially during pressure or change.',
            'watch_out' => 'This may show up as inconsistency or uncertainty.',
            'next_step' => 'Get specific: identify 2 real examples in the last month, then decide what you will do differently next time.',
        );
    }

    function icon_psy_self_score_words( $n ) {
        $n = (int) $n;
        if ( $n <= 2 ) return 'Very low';
        if ( $n <= 3 ) return 'Low';
        if ( $n <= 4 ) return 'Moderate';
        if ( $n <= 5 ) return 'Good';
        if ( $n <= 6 ) return 'Very good';
        return 'Excellent';
    }

    function icon_psy_self_bar_html( $value, $max = 7 ) {
        $value = max( 0, min( (int) $max, (int) $value ) );
        $pct = $max > 0 ? round( ( $value / $max ) * 100 ) : 0;

        $html  = '<div style="height:10px;border-radius:999px;background:rgba(148,163,184,.35);overflow:hidden;">';
        $html .= '<div style="height:10px;width:' . esc_attr( $pct ) . '%;border-radius:999px;background:linear-gradient(135deg,#14a4cf,#15a06d);"></div>';
        $html .= '</div>';

        return $html;
    }

    function icon_psy_self_try_export_pdf( $html, $filename = 'self-assessment-report.pdf' ) {
        if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
            return false;
        }

        try {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml( $html );
            $dompdf->setPaper( 'A4', 'portrait' );
            $dompdf->render();

            header( 'Content-Type: application/pdf' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            echo $dompdf->output();
            exit;
        } catch ( \Exception $e ) {
            return false;
        }
    }

    function icon_psy_self_portal( $atts ) {

        if ( ! is_user_logged_in() ) {
            return '<p>You need to be logged in to view your assessments.</p>';
        }

        global $wpdb;

        if ( function_exists( 'icon_psy_maybe_add_self_user_id_to_results_table' ) ) {
            icon_psy_maybe_add_self_user_id_to_results_table();
        }

        $results_table      = $wpdb->prefix . 'icon_assessment_results';
        $frameworks_table   = $wpdb->prefix . 'icon_psy_frameworks';
        $competencies_table = $wpdb->prefix . 'icon_psy_framework_competencies';

        $uid     = (int) get_current_user_id();
        $view_id = isset( $_GET['self_result_id'] ) ? (int) $_GET['self_result_id'] : 0;
        $export  = isset( $_GET['export'] ) ? sanitize_text_field( wp_unslash( $_GET['export'] ) ) : '';

        $base_list_url = remove_query_arg( array( 'self_result_id', 'export' ) );

        $css = '
        <style>
            :root{--icon-green:#15a06d;--icon-blue:#14a4cf;--text-dark:#0a3b34;--text-mid:#425b56;--text-light:#6a837d;}
            .icon-psy-wrap{max-width:1100px;margin:0 auto;padding:22px 16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
            .icon-psy-hero{background:radial-gradient(circle at top left,#e6f9ff 0,#ffffff 35%,#e9f8f1 100%);border-radius:22px;padding:18px 20px 16px;border:1px solid rgba(21,160,109,.22);box-shadow:0 20px 40px rgba(10,59,52,.16);margin-bottom:14px;}
            .icon-psy-hero h2{margin:0 0 6px;font-size:22px;font-weight:900;color:var(--text-dark);letter-spacing:.02em;}
            .icon-psy-hero p{margin:0;font-size:13px;color:var(--text-mid);}
            .icon-psy-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
            .icon-psy-row.space{justify-content:space-between;}
            .icon-psy-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:900;border:1px solid rgba(20,164,207,.25);background:#eff6ff;color:#1e3a8a;}
            .icon-psy-chip.green{border-color:rgba(21,160,109,.35);background:#ecfdf5;color:#166534;}
            .icon-psy-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:12px;}
            @media (max-width: 900px){.icon-psy-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
            @media (max-width: 560px){.icon-psy-grid{grid-template-columns:1fr;}}
            .icon-psy-card{background:#fff;border-radius:18px;padding:14px;border:1px solid rgba(20,164,207,.14);box-shadow:0 16px 35px rgba(10,59,52,.08);}
            .icon-psy-card.soft{background:linear-gradient(135deg,#ffffff 0,#f7fffb 60%,#eef9ff 100%);}
            .icon-psy-k{font-size:11px;color:var(--text-light);text-transform:uppercase;letter-spacing:.08em;font-weight:900;}
            .icon-psy-v{font-size:18px;color:var(--text-dark);font-weight:900;margin-top:4px;}
            .icon-psy-muted{font-size:12px;color:#64748b;}
            .icon-psy-btn{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;border:1px solid transparent;background-image:linear-gradient(135deg,var(--icon-blue),var(--icon-green));color:#fff;padding:8px 14px;font-size:13px;font-weight:900;cursor:pointer;text-decoration:none;white-space:nowrap;}
            .icon-psy-btn-ghost{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#fff;border:1px solid rgba(21,149,136,.35);color:var(--icon-green);padding:7px 12px;font-size:12px;font-weight:900;cursor:pointer;text-decoration:none;white-space:nowrap;line-height:1;}
            .icon-psy-btn-ghost:hover{border-color:rgba(20,164,207,.6);color:var(--icon-blue);}
            .icon-psy-hr{height:1px;background:rgba(226,232,240,.9);margin:12px 0;border:0;}

            .icon-psy-callout{border-radius:16px;padding:12px 14px;border:1px solid rgba(226,232,240,.9);background:#fff;}
            .icon-psy-callout.success{border-color:#bbf7d0;background:#ecfdf5;}
            .icon-psy-callout.info{border-color:#bee3f8;background:#eff6ff;}
            .icon-psy-callout.warning{border-color:#fde68a;background:#fffbeb;}
            .icon-psy-callout.danger{border-color:#fecaca;background:#fef2f2;}
            .icon-psy-callout h4{margin:0 0 6px;font-size:13px;font-weight:900;color:var(--text-dark);}
            .icon-psy-callout p{margin:0;font-size:12px;color:var(--text-mid);line-height:1.45;}

            .icon-psy-section-title{margin:0 0 8px;font-size:16px;font-weight:900;color:var(--text-dark);}
            .icon-psy-section-sub{margin:0 0 10px;font-size:13px;color:var(--text-mid);line-height:1.5;}

            .icon-psy-table{width:100%;border-collapse:collapse;font-size:12px;}
            .icon-psy-table th{padding:8px 8px;text-align:left;border-bottom:1px solid #e5e7eb;color:#64748b;text-transform:uppercase;letter-spacing:.08em;font-size:11px;}
            .icon-psy-table td{padding:10px 8px;border-bottom:1px solid #f1f5f9;color:#0f172a;vertical-align:top;}
        </style>';

        // -----------------------------
        // VIEW REPORT
        // -----------------------------
        if ( $view_id > 0 ) {

            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT r.*, f.name AS framework_name
                     FROM {$results_table} r
                     LEFT JOIN {$frameworks_table} f ON r.framework_id = f.id
                     WHERE r.id = %d
                       AND r.self_user_id = %d
                       AND (r.rater_id = 0 OR r.rater_id IS NULL)
                       AND (r.project_id = 0 OR r.project_id IS NULL)
                     LIMIT 1",
                    $view_id,
                    $uid
                )
            );

            if ( ! $row ) {
                return $css . '<div class="icon-psy-wrap"><p>Sorry — that self assessment report was not found.</p></div>';
            }

            $framework_id   = (int) $row->framework_id;
            $framework_name = $row->framework_name ? $row->framework_name : 'Leadership Framework';
            $overall        = isset( $row->q1_rating ) ? (float) $row->q1_rating : 0;
            $created_at     = ! empty( $row->created_at ) ? $row->created_at : '';
            $strengths      = ! empty( $row->q2_text ) ? $row->q2_text : '';
            $dev            = ! empty( $row->q3_text ) ? $row->q3_text : '';
            $detail_json    = ! empty( $row->detail_json ) ? $row->detail_json : '';

            $detail = json_decode( $detail_json, true );
            if ( ! is_array( $detail ) ) {
                $detail = array();
            }

            $comp_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, name, description
                     FROM {$competencies_table}
                     WHERE framework_id = %d
                     ORDER BY sort_order ASC, id ASC",
                    $framework_id
                )
            );

            $comp_map = array();
            if ( $comp_rows ) {
                foreach ( $comp_rows as $c ) {
                    $comp_map[ (int) $c->id ] = array(
                        'name' => (string) $c->name,
                        'desc' => (string) $c->description,
                    );
                }
            }

            $lines = array();
            $lens_gaps = array(); // for blind spots
            foreach ( $detail as $cid => $vals ) {
                $cid = (int) $cid;
                $q1  = isset( $vals['q1'] ) ? (int) $vals['q1'] : 0;
                $q2  = isset( $vals['q2'] ) ? (int) $vals['q2'] : 0;
                $q3  = isset( $vals['q3'] ) ? (int) $vals['q3'] : 0;

                $avg = ( $q1 + $q2 + $q3 ) / 3;

                $name = isset( $comp_map[ $cid ]['name'] ) ? $comp_map[ $cid ]['name'] : ('Competency #' . $cid);
                $desc = isset( $comp_map[ $cid ]['desc'] ) ? $comp_map[ $cid ]['desc'] : '';

                $lines[] = array(
                    'cid'  => $cid,
                    'name' => $name,
                    'desc' => $desc,
                    'q1'   => $q1,
                    'q2'   => $q2,
                    'q3'   => $q3,
                    'avg'  => $avg,
                );

                $minv = min($q1,$q2,$q3);
                $maxv = max($q1,$q2,$q3);
                $gap  = $maxv - $minv;

                if ( $gap >= 2 ) {
                    $lens_gaps[] = array(
                        'name' => $name,
                        'gap'  => $gap,
                        'q1'   => $q1,
                        'q2'   => $q2,
                        'q3'   => $q3,
                    );
                }
            }

            usort( $lines, function( $a, $b ) {
                if ( $a['avg'] === $b['avg'] ) return 0;
                return ( $a['avg'] > $b['avg'] ) ? -1 : 1;
            });

            $top3 = array_slice( $lines, 0, 3 );
            $bot3 = array_slice( array_reverse( $lines ), 0, 3 );

            usort( $lens_gaps, function( $a, $b ){
                if ( $a['gap'] === $b['gap'] ) return 0;
                return ( $a['gap'] > $b['gap'] ) ? -1 : 1;
            });

            $pdf_url = add_query_arg(
                array( 'self_result_id' => (int) $row->id, 'export' => 'pdf' ),
                $base_list_url
            );

            ob_start();
            echo $css;
            ?>
            <div class="icon-psy-wrap">

                <div class="icon-psy-hero">
                    <div class="icon-psy-row space">
                        <div>
                            <h2>My Self Assessment Report</h2>
                            <p>
                                Framework: <strong><?php echo esc_html( $framework_name ); ?></strong>
                                <?php if ( $created_at ) : ?>
                                    · Completed: <strong><?php echo esc_html( mysql2date( 'j M Y, H:i', $created_at ) ); ?></strong>
                                <?php endif; ?>
                            </p>
                            <div class="icon-psy-row" style="margin-top:10px;">
                                <span class="icon-psy-chip green">Individual</span>
                                <span class="icon-psy-chip">Scale: 1–7</span>
                                <span class="icon-psy-chip green">Private development</span>
                            </div>
                        </div>

                        <div class="icon-psy-row">
                            <a class="icon-psy-btn-ghost" href="<?php echo esc_url( $base_list_url ); ?>">← Back to portal</a>
                            <a class="icon-psy-btn" href="<?php echo esc_url( $pdf_url ); ?>">Download PDF</a>
                        </div>
                    </div>

                    <div class="icon-psy-grid">
                        <div class="icon-psy-card soft">
                            <div class="icon-psy-k">Overall score</div>
                            <div class="icon-psy-v"><?php echo esc_html( number_format_i18n( $overall, 2 ) ); ?> / 7</div>
                            <div style="margin-top:8px;"><?php echo icon_psy_self_bar_html( (int) round( $overall ) ); ?></div>
                            <div class="icon-psy-muted" style="margin-top:6px;"><?php echo esc_html( icon_psy_self_score_words( (int) round( $overall ) ) ); ?></div>
                        </div>

                        <div class="icon-psy-card">
                            <div class="icon-psy-k">Competencies explored</div>
                            <div class="icon-psy-v"><?php echo (int) count( $lines ); ?></div>
                            <div class="icon-psy-muted" style="margin-top:6px;">3 lenses: day-to-day, under pressure, role-modelling.</div>
                        </div>

                        <div class="icon-psy-card">
                            <div class="icon-psy-k">Report ID</div>
                            <div class="icon-psy-v"><?php echo (int) $row->id; ?></div>
                            <div class="icon-psy-muted" style="margin-top:6px;">Useful for support or auditing.</div>
                        </div>
                    </div>
                </div>

                <!-- Competency model -->
                <div class="icon-psy-card soft" style="margin-bottom:12px;">
                    <div class="icon-psy-section-title">Competency model for this report</div>
                    <div class="icon-psy-section-sub">
                        These are the leadership competencies explored in this self-assessment.
                        Each area is rated on how consistently the behaviour shows up for you in:
                        <strong>day-to-day work</strong>, <strong>under pressure</strong>, and <strong>as a role model</strong>.
                    </div>
                </div>

                <!-- Summary & overview -->
                <div class="icon-psy-card" style="margin-bottom:12px;">
                    <div class="icon-psy-section-title">Summary & overview</div>
                    <div class="icon-psy-section-sub">
                        A high-level view of how you rated yourself, where your strengths are most consistent,
                        and where focused development will have the greatest impact.
                    </div>

                    <div class="icon-psy-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));">
                        <div class="icon-psy-card soft" style="box-shadow:none;">
                            <div class="icon-psy-k">Most consistent strengths</div>
                            <?php if ( empty( $top3 ) ) : ?>
                                <p class="icon-psy-muted" style="margin:8px 0 0;">No competency detail found.</p>
                            <?php else : ?>
                                <?php foreach ( $top3 as $t ) : ?>
                                    <div style="margin-top:10px;">
                                        <strong style="color:var(--text-dark);"><?php echo esc_html( $t['name'] ); ?></strong>
                                        <div class="icon-psy-muted" style="margin-top:4px;">Average <?php echo esc_html( number_format_i18n( $t['avg'], 2 ) ); ?> / 7</div>
                                        <div style="margin-top:6px;"><?php echo icon_psy_self_bar_html( (int) round( $t['avg'] ) ); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="icon-psy-card soft" style="box-shadow:none;">
                            <div class="icon-psy-k">Greatest development impact</div>
                            <?php if ( empty( $bot3 ) ) : ?>
                                <p class="icon-psy-muted" style="margin:8px 0 0;">No competency detail found.</p>
                            <?php else : ?>
                                <?php foreach ( $bot3 as $b ) : ?>
                                    <div style="margin-top:10px;">
                                        <strong style="color:var(--text-dark);"><?php echo esc_html( $b['name'] ); ?></strong>
                                        <div class="icon-psy-muted" style="margin-top:4px;">Average <?php echo esc_html( number_format_i18n( $b['avg'], 2 ) ); ?> / 7</div>
                                        <div style="margin-top:6px;"><?php echo icon_psy_self_bar_html( (int) round( $b['avg'] ) ); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Leadership heatmap -->
                <div class="icon-psy-card" style="margin-bottom:12px;">
                    <div class="icon-psy-section-title">Leadership heatmap</div>
                    <div class="icon-psy-section-sub">
                        Your average scores across the leadership framework (1 = very low, 7 = very high)
                        viewed through three lenses: <strong>day-to-day</strong>, <strong>under pressure</strong>, and <strong>role-modelling</strong>.
                    </div>

                    <?php if ( empty( $lines ) ) : ?>
                        <p class="icon-psy-muted" style="margin:0;">No competency detail found in this assessment.</p>
                    <?php else : ?>
                        <table class="icon-psy-table">
                            <thead>
                                <tr>
                                    <th>Competency</th>
                                    <th>Day-to-day</th>
                                    <th>Under pressure</th>
                                    <th>Role-modelling</th>
                                    <th>Average</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $lines as $ln ) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $ln['name'] ); ?></strong></td>
                                        <td>
                                            <?php echo (int) $ln['q1']; ?> / 7
                                            <div style="margin-top:6px;"><?php echo icon_psy_self_bar_html( (int) $ln['q1'] ); ?></div>
                                        </td>
                                        <td>
                                            <?php echo (int) $ln['q2']; ?> / 7
                                            <div style="margin-top:6px;"><?php echo icon_psy_self_bar_html( (int) $ln['q2'] ); ?></div>
                                        </td>
                                        <td>
                                            <?php echo (int) $ln['q3']; ?> / 7
                                            <div style="margin-top:6px;"><?php echo icon_psy_self_bar_html( (int) $ln['q3'] ); ?></div>
                                        </td>
                                        <td>
                                            <strong><?php echo esc_html( number_format_i18n( $ln['avg'], 2 ) ); ?> / 7</strong>
                                            <div style="margin-top:6px;"><?php echo icon_psy_self_bar_html( (int) round( $ln['avg'] ) ); ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Competency-by-competency summary -->
                <div class="icon-psy-card soft" style="margin-bottom:12px;">
                    <div class="icon-psy-section-title">Competency-by-competency summary</div>
                    <div class="icon-psy-section-sub">
                        For each leadership competency, this section summarises what your scores may mean in practice,
                        and gives coaching prompts to help you improve.
                    </div>
                </div>

                <?php if ( ! empty( $lines ) ) : ?>
                    <?php foreach ( $lines as $ln ) :
                        $insight = icon_psy_self_coaching_band( $ln['avg'] );
                        ?>
                        <!-- ONE CARD PER COMPETENCY -->
                        <div class="icon-psy-card" style="margin-bottom:12px;">
                            <div class="icon-psy-row space">
                                <div>
                                    <div class="icon-psy-section-title" style="margin:0;"><?php echo esc_html( $ln['name'] ); ?></div>
                                    <?php if ( ! empty( $ln['desc'] ) ) : ?>
                                        <div class="icon-psy-section-sub" style="margin-top:6px;"><?php echo esc_html( $ln['desc'] ); ?></div>
                                    <?php else : ?>
                                        <div class="icon-psy-section-sub" style="margin-top:6px;">Reflect on recent examples where this behaviour showed up (or didn’t).</div>
                                    <?php endif; ?>
                                </div>

                                <div class="icon-psy-row">
                                    <span class="icon-psy-chip green"><?php echo esc_html( $insight['label'] ); ?></span>
                                    <span class="icon-psy-chip">Avg: <?php echo esc_html( number_format_i18n( $ln['avg'], 2 ) ); ?>/7</span>
                                </div>
                            </div>

                            <div style="margin-top:10px;">
                                <?php echo icon_psy_self_bar_html( (int) round( $ln['avg'] ) ); ?>
                            </div>

                            <div class="icon-psy-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-top:12px;">
                                <div class="icon-psy-card soft" style="box-shadow:none;">
                                    <div class="icon-psy-k">Day-to-day</div>
                                    <div class="icon-psy-v" style="font-size:16px;"><?php echo (int) $ln['q1']; ?> / 7</div>
                                    <div class="icon-psy-muted"><?php echo esc_html( icon_psy_self_score_words( $ln['q1'] ) ); ?></div>
                                    <div style="margin-top:8px;"><?php echo icon_psy_self_bar_html( (int) $ln['q1'] ); ?></div>
                                </div>

                                <div class="icon-psy-card soft" style="box-shadow:none;">
                                    <div class="icon-psy-k">Under pressure</div>
                                    <div class="icon-psy-v" style="font-size:16px;"><?php echo (int) $ln['q2']; ?> / 7</div>
                                    <div class="icon-psy-muted"><?php echo esc_html( icon_psy_self_score_words( $ln['q2'] ) ); ?></div>
                                    <div style="margin-top:8px;"><?php echo icon_psy_self_bar_html( (int) $ln['q2'] ); ?></div>
                                </div>

                                <div class="icon-psy-card soft" style="box-shadow:none;">
                                    <div class="icon-psy-k">Role-modelling</div>
                                    <div class="icon-psy-v" style="font-size:16px;"><?php echo (int) $ln['q3']; ?> / 7</div>
                                    <div class="icon-psy-muted"><?php echo esc_html( icon_psy_self_score_words( $ln['q3'] ) ); ?></div>
                                    <div style="margin-top:8px;"><?php echo icon_psy_self_bar_html( (int) $ln['q3'] ); ?></div>
                                </div>
                            </div>

                            <hr class="icon-psy-hr" />

                            <div class="icon-psy-callout <?php echo esc_attr( $insight['tone'] ); ?>">
                                <h4><?php echo esc_html( $insight['headline'] ); ?></h4>
                                <p><strong>What it means:</strong> <?php echo esc_html( $insight['what_it_means'] ); ?></p>
                                <p style="margin-top:6px;"><strong>Watch out for:</strong> <?php echo esc_html( $insight['watch_out'] ); ?></p>
                                <p style="margin-top:6px;"><strong>Coaching next step:</strong> <?php echo esc_html( $insight['next_step'] ); ?></p>
                            </div>

                            <div class="icon-psy-card soft" style="margin-top:10px;box-shadow:none;">
                                <div class="icon-psy-k">Coaching prompts</div>
                                <p style="margin:8px 0 0;font-size:13px;color:var(--text-mid);line-height:1.55;">
                                    • What is one recent example that supports this score?<br>
                                    • What would a “+1 point” improvement look like in behaviour?<br>
                                    • What will I do differently next time — and how will I measure it?
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Blind spots & awareness -->
                <div class="icon-psy-card" style="margin-bottom:12px;">
                    <div class="icon-psy-section-title">Blind spots & awareness</div>
                    <div class="icon-psy-section-sub">
                        Where one lens is much higher or lower than the others.
                        This often highlights a pattern such as “strong day-to-day but inconsistent under pressure” (or vice versa).
                    </div>

                    <?php if ( empty( $lens_gaps ) ) : ?>
                        <p class="icon-psy-muted" style="margin:0;">No major lens gaps detected (your scores are fairly consistent across contexts).</p>
                    <?php else : ?>
                        <?php foreach ( array_slice( $lens_gaps, 0, 5 ) as $g ) : ?>
                            <div class="icon-psy-card soft" style="margin-top:10px;box-shadow:none;">
                                <strong style="color:var(--text-dark);"><?php echo esc_html( $g['name'] ); ?></strong>
                                <div class="icon-psy-muted" style="margin-top:6px;">
                                    Lens gap: <strong><?php echo (int) $g['gap']; ?></strong> points (Day-to-day <?php echo (int) $g['q1']; ?> · Pressure <?php echo (int) $g['q2']; ?> · Role-model <?php echo (int) $g['q3']; ?>)
                                </div>
                                <div class="icon-psy-muted" style="margin-top:6px;">
                                    Coaching clue: ask “What changes in me when the context changes?” then define one stabilising habit for the weaker lens.
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Development focus & next steps -->
                <div class="icon-psy-card soft" style="margin-bottom:12px;">
                    <div class="icon-psy-section-title">Development focus & next steps</div>
                    <div class="icon-psy-section-sub">
                        Choose 1–3 priorities. Small, repeatable actions beat big intentions.
                    </div>

                    <div class="icon-psy-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));">
                        <?php foreach ( $bot3 as $b ) :
                            $ins = icon_psy_self_coaching_band( $b['avg'] );
                            ?>
                            <div class="icon-psy-card" style="box-shadow:none;">
                                <div class="icon-psy-k">Priority</div>
                                <div class="icon-psy-v" style="font-size:16px;"><?php echo esc_html( $b['name'] ); ?></div>
                                <div class="icon-psy-muted" style="margin-top:6px;">Avg <?php echo esc_html( number_format_i18n( $b['avg'], 2 ) ); ?>/7</div>
                                <div style="margin-top:8px;"><?php echo icon_psy_self_bar_html( (int) round( $b['avg'] ) ); ?></div>
                                <div class="icon-psy-muted" style="margin-top:8px;"><strong>Next step:</strong> <?php echo esc_html( $ins['next_step'] ); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="icon-psy-card" style="margin-top:12px;box-shadow:none;">
                        <div class="icon-psy-k">My reflections</div>
                        <div class="icon-psy-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));margin-top:10px;">
                            <div class="icon-psy-card soft" style="box-shadow:none;">
                                <div class="icon-psy-k">Key strengths</div>
                                <div style="margin-top:8px;font-size:13px;color:var(--text-mid);white-space:pre-wrap;line-height:1.6;"><?php echo esc_html( $strengths ? $strengths : '—' ); ?></div>
                            </div>
                            <div class="icon-psy-card soft" style="box-shadow:none;">
                                <div class="icon-psy-k">Development priorities</div>
                                <div style="margin-top:8px;font-size:13px;color:var(--text-mid);white-space:pre-wrap;line-height:1.6;"><?php echo esc_html( $dev ? $dev : '—' ); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <?php
            $report_html = ob_get_clean();

            if ( $export === 'pdf' ) {
                $ok = icon_psy_self_try_export_pdf( $report_html, 'self-assessment-report-' . (int) $row->id . '.pdf' );
                if ( ! $ok ) {
                    $report_html = $css
                        . '<div class="icon-psy-wrap"><div class="icon-psy-card soft"><strong>PDF export not available.</strong><br><span class="icon-psy-muted">Dompdf class was not found. Please ensure Dompdf is loaded in WordPress.</span></div></div>'
                        . $report_html;
                }
            }

            return $report_html;
        }

        // -----------------------------
        // PORTAL HUB + LIST VIEW
        // -----------------------------
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.id, r.framework_id, r.q1_rating, r.status, r.created_at, f.name AS framework_name
                 FROM {$results_table} r
                 LEFT JOIN {$frameworks_table} f ON r.framework_id = f.id
                 WHERE r.self_user_id = %d
                   AND (r.rater_id = 0 OR r.rater_id IS NULL)
                   AND (r.project_id = 0 OR r.project_id IS NULL)
                 ORDER BY r.id DESC
                 LIMIT 50",
                $uid
            )
        );

        $url_start   = home_url( '/my-leadership-assessment/' );
        $url_profile = home_url( '/my-account/' );

        ob_start();
        echo $css;
        ?>
        <div class="icon-psy-wrap">

            <div class="icon-psy-hero">
                <div class="icon-psy-row space">
                    <div>
                        <h2>My Portal</h2>
                        <p>Your ICON Catalyst self assessments and coaching reports.</p>
                        <div class="icon-psy-row" style="margin-top:10px;">
                            <span class="icon-psy-chip green">Individual</span>
                            <span class="icon-psy-chip">Private development</span>
                        </div>
                    </div>

                    <div class="icon-psy-row">
                        <a class="icon-psy-btn" href="<?php echo esc_url( $url_start ); ?>">+ Start self assessment</a>
                        <a class="icon-psy-btn-ghost" href="<?php echo esc_url( $url_profile ); ?>">Account</a>
                    </div>
                </div>

                <div class="icon-psy-grid">
                    <div class="icon-psy-card soft">
                        <div class="icon-psy-k">Saved assessments</div>
                        <div class="icon-psy-v"><?php echo (int) count( $rows ); ?></div>
                        <div class="icon-psy-muted" style="margin-top:6px;">Your last 50 results are shown below.</div>
                    </div>
                    <div class="icon-psy-card">
                        <div class="icon-psy-k">What this portal does</div>
                        <div class="icon-psy-v" style="font-size:16px;">Review & coach yourself</div>
                        <div class="icon-psy-muted" style="margin-top:6px;">Open a report to see a coaching summary per competency.</div>
                    </div>
                    <div class="icon-psy-card">
                        <div class="icon-psy-k">Tip</div>
                        <div class="icon-psy-v" style="font-size:16px;">Focus on +1</div>
                        <div class="icon-psy-muted" style="margin-top:6px;">Pick one priority and improve it by one point in 30 days.</div>
                    </div>
                </div>
            </div>

            <?php if ( empty( $rows ) ) : ?>
                <div class="icon-psy-card">
                    <p style="margin:0;color:var(--text-mid);">No self assessments saved yet.</p>
                </div>
            <?php else : ?>
                <div class="icon-psy-grid">
                    <?php foreach ( $rows as $r ) :
                        $fw  = $r->framework_name ? $r->framework_name : 'Framework';
                        $dt  = $r->created_at ? mysql2date( 'j M Y, H:i', $r->created_at ) : '';
                        $avg = isset( $r->q1_rating ) ? (float) $r->q1_rating : 0;
                        $url = add_query_arg( array( 'self_result_id' => (int) $r->id ), $base_list_url );
                        ?>
                        <div class="icon-psy-card">
                            <div class="icon-psy-k">Framework</div>
                            <div class="icon-psy-v" style="font-size:16px;"><?php echo esc_html( $fw ); ?></div>

                            <div style="margin-top:10px;">
                                <?php echo icon_psy_self_bar_html( (int) round( $avg ) ); ?>
                            </div>

                            <div class="icon-psy-row" style="margin-top:10px;">
                                <span class="icon-psy-chip"><strong><?php echo esc_html( number_format_i18n( $avg, 2 ) ); ?></strong>&nbsp;/ 7 overall</span>
                                <?php if ( $dt ) : ?>
                                    <span class="icon-psy-chip green"><?php echo esc_html( $dt ); ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="icon-psy-row space" style="margin-top:12px;">
                                <a class="icon-psy-btn-ghost" href="<?php echo esc_url( $url ); ?>">View coaching report</a>
                                <span class="icon-psy-muted">ID: <?php echo (int) $r->id; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }
}

add_shortcode( 'icon_psy_self_portal', 'icon_psy_self_portal' );
