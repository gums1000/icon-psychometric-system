<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RATER PORTAL (redirector to the dedicated survey page)
 *
 * Shortcode: [icon_psy_rater_portal]
 *
 * The actual 1–7 per-competency survey is handled by the
 * `[icon_psy_rater_survey2]` shortcode on the /rater-survey/ page.
 *
 * This portal:
 *  - validates the token / rater_id
 *  - if NOT completed => redirects to /rater-survey/?rater_id=XX
 *  - if completed => shows a simple "already submitted" message
 */

if ( ! function_exists( 'icon_psy_rater_portal' ) ) {

    function icon_psy_rater_portal( $atts ) {
        global $wpdb;

        $atts = shortcode_atts(
            array(
                'token'    => '',
                'rater_id' => 0,
            ),
            $atts,
            'icon_psy_rater_portal'
        );

        // Collect token / rater_id from URL or shortcode
        $token    = '';
        $rater_id = 0;

        if ( isset( $_GET['token'] ) && $_GET['token'] !== '' ) {
            $token = sanitize_text_field( wp_unslash( $_GET['token'] ) );
        } elseif ( ! empty( $atts['token'] ) ) {
            $token = sanitize_text_field( wp_unslash( $atts['token'] ) );
        }

        if ( isset( $_GET['rater_id'] ) && $_GET['rater_id'] !== '' ) {
            $rater_id = (int) $_GET['rater_id'];
        } elseif ( ! empty( $atts['rater_id'] ) ) {
            $rater_id = (int) $atts['rater_id'];
        }

        if ( $token === '' && $rater_id <= 0 ) {
            return '<p>Sorry, this Icon Catalyst feedback link is missing or invalid.</p>';
        }

        $raters_table       = $wpdb->prefix . 'icon_psy_raters';
        $participants_table = $wpdb->prefix . 'icon_psy_participants';
        $projects_table     = $wpdb->prefix . 'icon_psy_projects';

        $lookup_mode = $rater_id > 0 ? 'id' : 'token';
        $rater       = null;
        $used_raw    = false;

        // 1) Prefer a direct ID lookup if we have one (joined with participant + project)
        if ( $lookup_mode === 'id' && $rater_id > 0 ) {
            $rater = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT
                        r.*,
                        p.name          AS participant_name,
                        p.role          AS participant_role,
                        p.project_id    AS project_id,
                        pr.name         AS project_name,
                        pr.client_name  AS client_name
                     FROM {$raters_table} r
                     LEFT JOIN {$participants_table} p ON r.participant_id = p.id
                     LEFT JOIN {$projects_table} pr   ON p.project_id     = pr.id
                     WHERE r.id = %d
                     LIMIT 1",
                    $rater_id
                )
            );
        }

        // 2) Token lookup (joined) - accept token OR invite_token
        if ( ! $rater && $token !== '' ) {
            $rater = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT
                        r.*,
                        p.name          AS participant_name,
                        p.role          AS participant_role,
                        p.project_id    AS project_id,
                        pr.name         AS project_name,
                        pr.client_name  AS client_name
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

        // 3) RAW token fallback (no joins)
        if ( ! $rater && $token !== '' ) {
            $raw = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$raters_table} WHERE (token = %s OR invite_token = %s) LIMIT 1",
                    $token,
                    $token
                )
            );

            if ( $raw ) {
                $used_raw = true;

                // Try to enrich with participant + project (optional)
                $participant = null;
                if ( ! empty( $raw->participant_id ) ) {
                    $participant = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM {$participants_table} WHERE id = %d LIMIT 1",
                            (int) $raw->participant_id
                        )
                    );
                }

                $project = null;
                if ( $participant && ! empty( $participant->project_id ) ) {
                    $project = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM {$projects_table} WHERE id = %d LIMIT 1",
                            (int) $participant->project_id
                        )
                    );
                }

                // Attach display-only fields so later code still works
                $raw->participant_name = $participant ? $participant->name : '';
                $raw->participant_role = $participant ? $participant->role : '';
                $raw->project_id       = $participant ? (int) $participant->project_id : 0;
                $raw->project_name     = $project ? $project->name : '';
                $raw->client_name      = $project ? $project->client_name : '';

                $rater = $raw;
            }
        }

        // Admin-only debug (NOTE: you will NOT see this on redirect, only on rendered output)
        $debug_html = '';
        if ( current_user_can( 'manage_options' ) ) {
            $debug  = '<div style="margin:8px 0;padding:8px 10px;border-radius:6px;';
            $debug .= 'border:1px solid #e5e7eb;background:#f9fafb;font-size:11px;color:#374151;">';
            $debug .= '<strong>ICON Catalyst rater portal debug</strong><br>';
            $debug .= 'Lookup mode: ' . esc_html( $lookup_mode ) . '<br>';
            $debug .= 'Token: ' . esc_html( $token ? $token : '(none)' ) . '<br>';
            $debug .= 'Rater ID param: ' . esc_html( $rater_id ) . '<br>';
            $debug .= 'Found rater row: ' . ( $rater ? 'YES' : 'NO' ) . '<br>';
            $debug .= 'Used raw token fallback: ' . ( $used_raw ? 'YES' : 'NO' ) . '<br>';
            if ( ! empty( $wpdb->last_error ) ) {
                $debug .= 'Last DB error: ' . esc_html( $wpdb->last_error ) . '<br>';
            }
            $debug .= '</div>';

            $debug_html = $debug;
        }

        if ( ! $rater ) {
            ob_start();
            echo $debug_html;
            ?>
            <div style="max-width:700px;margin:0 auto;padding:20px;">
                <div style="background:#fff3f3;border:1px solid #fca5a5;padding:16px;border-radius:10px;">
                    <h2 style="margin:0 0 6px;color:#7f1d1d;">Icon Catalyst Feedback</h2>
                    <p style="margin:0 0 6px;font-size:13px;">
                        Sorry, this feedback link isn’t recognised.
                    </p>
                    <p style="margin:0;font-size:12px;color:#6b7280;">
                        It may have expired, already been used, or contains a typo.
                    </p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        // Status + completion flag
        $status = ! empty( $rater->status ) ? (string) $rater->status : 'pending';

        if ( function_exists( 'icon_psy_is_completed_status' ) ) {
            $is_completed = icon_psy_is_completed_status( $status );
        } else {
            $s = strtolower( trim( (string) $status ) );
            $is_completed = in_array( $s, array( 'completed', 'complete', 'submitted', 'done' ), true );
        }

        // Survey page that contains [icon_psy_rater_survey2]
        $survey_page_url = home_url( '/rater-survey/' );

        // Build survey URL with rater_id
        $survey_url = add_query_arg(
            array(
                'rater_id' => (int) $rater->id,
                'token'    => $token,
            ),
            $survey_page_url
        );

        // If NOT completed, redirect straight to the survey page
        if ( ! $is_completed ) {

            // DEBUG: find what is breaking redirects
            if ( headers_sent( $file, $line ) ) {
                return $debug_html
                    . '<div style="max-width:820px;margin:0 auto;padding:16px;border:1px solid #fecaca;background:#fef2f2;border-radius:12px;">'
                    . '<strong>Headers already sent</strong><br>'
                    . 'File: ' . esc_html( $file ) . '<br>'
                    . 'Line: ' . esc_html( $line ) . '<br>'
                    . '</div>'
                    . '<p style="max-width:820px;margin:10px auto 0;padding:0 16px;">'
                    . '<a href="' . esc_url( $survey_url ) . '">Click here to open the survey</a>'
                    . '</p>';
            }

            wp_safe_redirect( $survey_url );
            exit;
        }

        // ----- If we reach here, feedback is already completed -----
        $participant_name = ! empty( $rater->participant_name ) ? (string) $rater->participant_name : 'Participant';
        $project_name     = ! empty( $rater->project_name ) ? (string) $rater->project_name : 'Project';
        $client_name      = ! empty( $rater->client_name ) ? (string) $rater->client_name : '';
        $participant_role = ! empty( $rater->participant_role ) ? (string) $rater->participant_role : '';

        ob_start();
        echo $debug_html;
        ?>
        <div class="icon-psy-portal icon-psy-rater-portal" style="max-width:820px;margin:0 auto;padding:20px 16px;">

            <style>
                .icon-psy-rater-card {
                    background:#ffffff;
                    border-radius:12px;
                    padding:20px 22px;
                    box-shadow:0 10px 24px rgba(15,118,110,0.08);
                    border:1px solid #e3f0ea;
                    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
                    color:#111827;
                }
                .icon-psy-rater-title {
                    font-size:20px;
                    font-weight:700;
                    margin:0 0 4px;
                }
                .icon-psy-rater-subtitle {
                    margin:0;
                    font-size:13px;
                    color:#4b5563;
                }
                .icon-psy-chip-row {
                    display:flex;
                    flex-wrap:wrap;
                    gap:6px;
                    margin-top:10px;
                    font-size:11px;
                }
                .icon-psy-chip-muted {
                    padding:2px 8px;
                    border-radius:999px;
                    background:#f3f4f6;
                    border:1px solid #e5e7eb;
                    color:#374151;
                }
                .icon-psy-rater-success {
                    margin-top:12px;
                    padding:10px 12px;
                    border-radius:8px;
                    border:1px solid #bbf7d0;
                    background:#ecfdf5;
                    font-size:13px;
                    color:#166534;
                }
            </style>

            <div class="icon-psy-rater-card">
                <div class="icon-psy-rater-title">Icon Catalyst Feedback</div>
                <p class="icon-psy-rater-subtitle">
                    Thank you — your feedback has already been submitted for
                    <strong><?php echo esc_html( $participant_name ); ?></strong>.
                </p>

                <div class="icon-psy-chip-row">
                    <?php if ( $participant_role ) : ?>
                        <span class="icon-psy-chip-muted">Role: <?php echo esc_html( $participant_role ); ?></span>
                    <?php endif; ?>

                    <span class="icon-psy-chip-muted">Project: <?php echo esc_html( $project_name ); ?></span>

                    <?php if ( $client_name ) : ?>
                        <span class="icon-psy-chip-muted">Client: <?php echo esc_html( $client_name ); ?></span>
                    <?php endif; ?>

                    <span class="icon-psy-chip-muted">Status: Completed</span>
                </div>

                <div class="icon-psy-rater-success">
                    Your feedback has been recorded. You can now close this window.
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
} // end if function_exists wrapper

add_action( 'init', function() {
    add_shortcode( 'icon_psy_rater_portal', 'icon_psy_rater_portal' );
}, 20 );
