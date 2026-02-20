<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Front-end rater survey for the ICON Catalyst System.
 *
 * Shortcode:
 *   [icon_psy_rater_survey]
 *
 * URL pattern (for now):
 *   /rater-survey/?rater_id=123
 *
 * - Auto-builds questions from the project's framework competencies
 * - Saves per-competency results into {prefix}icon_psy_360_results
 * - Creates/updates a header record in {prefix}icon_assessment_results
 * - Sets rater.status = 'completed'
 */
class Icon_PSY_Survey {

    /**
     * Bootstrap.
     */
    public static function init() {
        add_shortcode( 'icon_psy_rater_survey', array( __CLASS__, 'shortcode_rater_survey' ) );
        add_action( 'init', array( __CLASS__, 'maybe_create_results_table' ) );
    }

    /**
     * Create / align the per-competency results table if it does not exist.
     * Uses the same name you already have: {prefix}icon_psy_360_results
     */
    public static function maybe_create_results_table() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $results_table   = $wpdb->prefix . 'icon_psy_360_results';

        // dbDelta will not drop any extra columns (planning_score, etc.)
        // It will only add missing ones and adjust indexes if needed.
        $sql = "CREATE TABLE {$results_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            rater_id BIGINT(20) UNSIGNED NOT NULL,
            participant_id BIGINT(20) UNSIGNED NOT NULL,
            project_id BIGINT(20) UNSIGNED NOT NULL,
            competency_id BIGINT(20) UNSIGNED NOT NULL,
            score FLOAT NULL,
            comment TEXT NULL,
            header_id BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY rater_id (rater_id),
            KEY participant_id (participant_id),
            KEY project_id (project_id),
            KEY competency_id (competency_id),
            KEY header_id (header_id)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Rater Survey Shortcode
     *
     * Usage: [icon_psy_rater_survey]
     * Expects ?rater_id=123 in the URL (we'll move to secure tokens later).
     */
    public static function shortcode_rater_survey( $atts ) {
        if ( is_admin() ) {
            // Don't render survey in admin editors.
            return '';
        }

        global $wpdb;

        $raters_table         = $wpdb->prefix . 'icon_psy_raters';
        $participants_table   = $wpdb->prefix . 'icon_psy_participants';
        $projects_table       = $wpdb->prefix . 'icon_psy_projects';
        $competencies_table   = $wpdb->prefix . 'icon_psy_framework_competencies';
        $per_comp_table       = $wpdb->prefix . 'icon_psy_360_results'; // IMPORTANT: matches your DB
        $header_results_table = $wpdb->prefix . 'icon_assessment_results';

        // Get rater_id from query string or shortcode attribute.
        $atts = shortcode_atts(
            array(
                'rater_id' => 0,
            ),
            $atts,
            'icon_psy_rater_survey'
        );

        $rater_id = (int) $atts['rater_id'];

        if ( ! $rater_id && isset( $_GET['rater_id'] ) ) {
            $rater_id = (int) $_GET['rater_id'];
        }

        if ( $rater_id <= 0 ) {
            return '<p>Rater link is not valid. Please contact the organiser of your ICON Catalyst System assessment.</p>';
        }

        // Fetch rater + participant + project + framework.
        $rater = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.*,
                        p.name AS participant_name,
                        p.id   AS participant_id,
                        pr.id  AS project_id,
                        pr.name AS project_name,
                        pr.client_name AS client_name,
                        pr.framework_id AS framework_id
                 FROM {$raters_table} r
                 LEFT JOIN {$participants_table} p ON r.participant_id = p.id
                 LEFT JOIN {$projects_table} pr ON p.project_id = pr.id
                 WHERE r.id = %d
                 LIMIT 1",
                $rater_id
            )
        );

        if ( ! $rater ) {
            return '<p>We could not find your assessment. Please check your link or contact the organiser.</p>';
        }

        $participant_id = (int) $rater->participant_id;
        $framework_id   = (int) $rater->framework_id;
        $project_id     = isset( $rater->project_id ) ? (int) $rater->project_id : 0;

        if ( $participant_id <= 0 || $framework_id <= 0 ) {
            return '<p>This assessment is not fully configured. Please contact the organiser of your ICON Catalyst System project.</p>';
        }

        // If rater already completed, show thank-you message and exit.
        if ( ! empty( $rater->status ) && 'completed' === $rater->status ) {
            return self::render_thank_you( $rater );
        }

        // Fetch competencies for the framework.
        $competencies = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$competencies_table}
                 WHERE framework_id = %d
                 ORDER BY sort_order ASC, name ASC",
                $framework_id
            )
        );

        if ( empty( $competencies ) ) {
            return '<p>No competencies have been set up yet for this ICON Catalyst System framework. Please contact the organiser.</p>';
        }

        $message       = '';
        $message_class = '';

        // Handle form submit.
        if (
            isset( $_POST['icon_psy_rater_survey_action'] )
            && 'submit' === $_POST['icon_psy_rater_survey_action']
        ) {
            if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'icon_psy_rater_survey_' . $rater_id ) ) {
                $message       = 'Your session has expired. Please reload the page and try again.';
                $message_class = 'error';
            } else {
                $scores        = array();
                $has_missing   = false;

                foreach ( $competencies as $comp ) {
                    $field_name = 'score_' . (int) $comp->id;
                    $raw_score  = isset( $_POST[ $field_name ] ) ? wp_unslash( $_POST[ $field_name ] ) : '';

                    if ( '' === $raw_score ) {
                        $has_missing = true;
                    } else {
                        $scores[ (int) $comp->id ] = (float) $raw_score;
                    }
                }

                $overall_comment = isset( $_POST['overall_comment'] )
                    ? wp_kses_post( wp_unslash( $_POST['overall_comment'] ) )
                    : '';

                if ( $has_missing ) {
                    $message       = 'Please provide a rating for each competency before submitting.';
                    $message_class = 'error';
                } else {

                    // -------------------------------------------------
                    // 1) Compute overall ratings (1–5 and 1–7)
                    // -------------------------------------------------
                    $total_score = 0;
                    $count_score = 0;

                    foreach ( $scores as $val ) {
                        $total_score += (float) $val;
                        $count_score++;
                    }

                    $overall_rating_5 = ( $count_score > 0 )
                        ? ( $total_score / $count_score )
                        : null;

                    $overall_rating_7 = null;
                    if ( ! is_null( $overall_rating_5 ) ) {
                        // Linear mapping of 1–5 to 1–7
                        $overall_rating_7 = 1 + ( ( $overall_rating_5 - 1 ) * ( 6 / 4 ) ); // ~1–7
                    }

                    // -------------------------------------------------
                    // 2) Create/update header row in icon_assessment_results
                    // -------------------------------------------------
                    // Try to find existing header for this participant/rater/project
                    $existing_header_id = 0;

                    if ( $project_id > 0 ) {
                        $existing_header_id = (int) $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT id
                                 FROM {$header_results_table}
                                 WHERE participant_id = %d
                                   AND rater_id      = %d
                                   AND project_id    = %d
                                 LIMIT 1",
                                $participant_id,
                                $rater_id,
                                $project_id
                            )
                        );
                    } else {
                        // Fallback if project_id is missing: match only on participant + rater.
                        $existing_header_id = (int) $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT id
                                 FROM {$header_results_table}
                                 WHERE participant_id = %d
                                   AND rater_id      = %d
                                 LIMIT 1",
                                $participant_id,
                                $rater_id
                            )
                        );
                    }

                    // Build per-competency payload for JSON
                    $detail_payload = array();

                    foreach ( $competencies as $comp ) {
                        $cid   = (int) $comp->id;
                        $score = isset( $scores[ $cid ] ) ? $scores[ $cid ] : null;

                        $detail_payload[ $cid ] = array(
                            'competency_id' => $cid,
                            'q1'            => $score,            // rating 1–5
                            'q2'            => $overall_comment, // same overall comment
                            'q3'            => '',               // reserved
                        );
                    }

                    $detail_json = ! empty( $detail_payload )
                        ? wp_json_encode( $detail_payload )
                        : null;

                    $header_data = array(
                        'participant_id' => $participant_id,
                        'rater_id'       => $rater_id,
                        'project_id'     => $project_id,
                        'framework_id'   => $framework_id,
                        'q1_rating'      => is_null( $overall_rating_7 ) ? null : $overall_rating_7,
                        'q2_text'        => $overall_comment,
                        'q3_text'        => '',
                        'detail_json'    => $detail_json,
                        'created_at'     => current_time( 'mysql' ),
                        'updated_at'     => current_time( 'mysql' ),
                        'status'         => 'completed',
                    );

                    $header_formats = array(
                        '%d', // participant_id
                        '%d', // rater_id
                        '%d', // project_id
                        '%d', // framework_id
                        '%f', // q1_rating
                        '%s', // q2_text
                        '%s', // q3_text
                        '%s', // detail_json
                        '%s', // created_at
                        '%s', // updated_at
                        '%s', // status
                    );

                    $header_id = 0;

                    if ( $existing_header_id > 0 ) {
                        $wpdb->update(
                            $header_results_table,
                            $header_data,
                            array( 'id' => $existing_header_id ),
                            $header_formats,
                            array( '%d' )
                        );
                        $header_id = $existing_header_id;
                        error_log( 'ICON360 debug: Updated header row id ' . $header_id . ' in ' . $header_results_table );
                    } else {
                        $wpdb->insert(
                            $header_results_table,
                            $header_data,
                            $header_formats
                        );
                        $header_id = (int) $wpdb->insert_id;
                        error_log( 'ICON360 debug: Inserted new header row id ' . $header_id . ' in ' . $header_results_table );
                    }

                    // -------------------------------------------------
                    // 3) Save per-competency scores into icon_psy_360_results
                    // -------------------------------------------------
                    if ( $header_id > 0 ) {
                        error_log(
                            'ICON360 debug: About to write per-competency rows into ' . $per_comp_table .
                            ' for header_id=' . $header_id .
                            ' participant_id=' . $participant_id .
                            ' rater_id=' . $rater_id
                        );

                        // Clean out any prior rows for this header (if the link was reused)
                        $wpdb->delete(
                            $per_comp_table,
                            array( 'header_id' => $header_id ),
                            array( '%d' )
                        );

                        foreach ( $competencies as $comp ) {
                            $cid   = (int) $comp->id;
                            $score = isset( $scores[ $cid ] ) ? $scores[ $cid ] : null;

                            $data = array(
                                'participant_id' => $participant_id,
                                'rater_id'       => $rater_id,
                                'project_id'     => $project_id,
                                'competency_id'  => $cid,
                                'score'          => $score,
                                'comment'        => $overall_comment,
                                'header_id'      => $header_id,
                                'created_at'     => current_time( 'mysql' ),
                            );

                            $formats = array(
                                '%d', // participant_id
                                '%d', // rater_id
                                '%d', // project_id
                                '%d', // competency_id
                                '%f', // score
                                '%s', // comment
                                '%d', // header_id
                                '%s', // created_at
                            );

                            $insert_ok = $wpdb->insert(
                                $per_comp_table,
                                $data,
                                $formats
                            );

                            if ( false === $insert_ok ) {
                                error_log(
                                    'ICON360 debug: FAILED inserting per-competency row into ' . $per_comp_table .
                                    ' (header_id=' . $header_id . ', competency_id=' . $cid . ') ' .
                                    'Error: ' . $wpdb->last_error
                                );
                            } else {
                                error_log(
                                    'ICON360 debug: Inserted per-competency row id ' . $wpdb->insert_id .
                                    ' into ' . $per_comp_table .
                                    ' for header_id=' . $header_id .
                                    ' competency_id=' . $cid
                                );
                            }
                        }

                        // Double-check how many rows we now have for this header
                        $row_count = (int) $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$per_comp_table} WHERE header_id = %d",
                                $header_id
                            )
                        );

                        error_log(
                            'ICON360 debug: After insert loop, found ' . $row_count .
                            ' per-competency rows in ' . $per_comp_table .
                            ' for header_id=' . $header_id
                        );
                    } else {
                        error_log(
                            'ICON360 debug: Header id is 0, SKIPPING per-competency insert. ' .
                            'participant_id=' . $participant_id .
                            ' rater_id=' . $rater_id
                        );
                    }

                    // -------------------------------------------------
                    // 4) Mark rater as completed.
                    // -------------------------------------------------
                    $wpdb->update(
                        $raters_table,
                        array( 'status' => 'completed' ),
                        array( 'id' => $rater_id ),
                        array( '%s' ),
                        array( '%d' )
                    );

                    // Show thank-you message.
                    return self::render_thank_you( $rater );
                }
            }
        }

        ob_start();
        ?>
        <div class="icon-psy-rater-survey-wrapper" style="max-width:780px; margin:0 auto; padding:24px 16px;">
            <style>
                .icon-psy-rater-survey-card {
                    background:#ffffff;
                    border-radius:12px;
                    padding:20px 22px;
                    box-shadow:0 10px 24px rgba(15,118,110,0.10);
                    border:1px solid #e5f3ec;
                    font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                    color:#111827;
                }
                .icon-psy-rater-survey-title {
                    font-size:20px;
                    font-weight:700;
                    margin:0 0 4px;
                }
                .icon-psy-rater-survey-subtitle {
                    font-size:13px;
                    color:#4b5563;
                    margin:0 0 10px;
                }
                .icon-psy-rater-meta {
                    font-size:12px;
                    color:#6b7280;
                    margin-bottom:14px;
                }
                .icon-psy-notice {
                    margin:10px 0 16px 0;
                    padding:8px 10px;
                    border-radius:8px;
                    border-width:1px;
                    border-style:solid;
                    font-size:12px;
                }
                .icon-psy-notice.error {
                    background:#fef2f2;
                    border-color:#fecaca;
                    color:#b91c1c;
                }
                .icon-psy-notice.info {
                    background:#eff6ff;
                    border-color:#bfdbfe;
                    color:#1e40af;
                }
                .icon-psy-competency-list {
                    margin-top:10px;
                }
                .icon-psy-competency-row {
                    padding:10px 0;
                    border-bottom:1px solid #f3f4f6;
                }
                .icon-psy-competency-row:last-child {
                    border-bottom:none;
                }
                .icon-psy-competency-name {
                    font-weight:600;
                    font-size:13px;
                    margin:0 0 2px 0;
                }
                .icon-psy-competency-desc {
                    font-size:12px;
                    color:#4b5563;
                    margin:0 0 6px 0;
                }
                .icon-psy-rating-scale {
                    font-size:12px;
                    display:flex;
                    flex-wrap:wrap;
                    gap:8px;
                    align-items:center;
                    margin-top:2px;
                }
                .icon-psy-rating-scale label {
                    display:inline-flex;
                    align-items:center;
                    gap:4px;
                    cursor:pointer;
                }
                .icon-psy-rating-scale input[type="radio"] {
                    margin:0;
                }
                .icon-psy-overall-comment {
                    margin-top:12px;
                }
                .icon-psy-overall-comment textarea {
                    width:100%;
                    min-height:80px;
                }
                .icon-psy-submit-row {
                    margin-top:14px;
                }
            </style>

            <div class="icon-psy-rater-survey-card">
                <h1 class="icon-psy-rater-survey-title">ICON Catalyst System – Rater Feedback</h1>
                <p class="icon-psy-rater-survey-subtitle">
                    Thank you for providing feedback as part of this ICON Catalyst System assessment.
                    Your responses are confidential and will be combined with others to support a constructive
                    development conversation.
                </p>

                <div class="icon-psy-rater-meta">
                    You are rating:
                    <strong><?php echo esc_html( $rater->participant_name ); ?></strong>
                    <?php if ( ! empty( $rater->project_name ) ) : ?>
                        &nbsp;&middot;&nbsp; Project:
                        <strong><?php echo esc_html( $rater->project_name ); ?></strong>
                    <?php endif; ?>
                    <?php if ( ! empty( $rater->client_name ) ) : ?>
                        &nbsp;&middot;&nbsp; Client:
                        <strong><?php echo esc_html( $rater->client_name ); ?></strong>
                    <?php endif; ?>
                </div>

                <?php if ( $message ) : ?>
                    <div class="icon-psy-notice <?php echo esc_attr( $message_class ); ?>">
                        <?php echo esc_html( $message ); ?>
                    </div>
                <?php else : ?>
                    <div class="icon-psy-notice info">
                        Please rate each competency on a scale from 1 to 5, where
                        <strong>1 = Rarely demonstrated</strong> and
                        <strong>5 = Consistently strong</strong>.
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field( 'icon_psy_rater_survey_' . $rater_id ); ?>
                    <input type="hidden" name="icon_psy_rater_survey_action" value="submit" />
                    <input type="hidden" name="rater_id" value="<?php echo esc_attr( $rater_id ); ?>" />

                    <div class="icon-psy-competency-list">
                        <?php foreach ( $competencies as $comp ) : ?>
                            <?php
                            $cid        = (int) $comp->id;
                            $field_name = 'score_' . $cid;
                            ?>
                            <div class="icon-psy-competency-row">
                                <p class="icon-psy-competency-name">
                                    <?php echo esc_html( $comp->name ); ?>
                                </p>
                                <?php if ( ! empty( $comp->description ) ) : ?>
                                    <p class="icon-psy-competency-desc">
                                        <?php echo esc_html( $comp->description ); ?>
                                    </p>
                                <?php endif; ?>

                                <div class="icon-psy-rating-scale">
                                    <span>Rating:</span>
                                    <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                                        <label>
                                            <input type="radio"
                                                   name="<?php echo esc_attr( $field_name ); ?>"
                                                   value="<?php echo esc_attr( $i ); ?>" />
                                            <?php echo esc_html( $i ); ?>
                                        </label>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="icon-psy-overall-comment">
                        <label for="icon-psy-overall-comment">
                            Overall comments (optional)
                        </label>
                        <textarea id="icon-psy-overall-comment"
                                  name="overall_comment"
                                  placeholder="Please add any specific strengths, examples, or suggestions you would like to share..."></textarea>
                    </div>

                    <div class="icon-psy-submit-row">
                        <button type="submit" class="button button-primary">
                            Submit feedback
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Thank-you screen once the rater has completed the survey.
     */
    protected static function render_thank_you( $rater ) {
        ob_start();
        ?>
        <div class="icon-psy-rater-survey-wrapper" style="max-width:720px; margin:0 auto; padding:24px 16px;">
            <div class="icon-psy-rater-survey-card" style="background:#ecfdf5; border-radius:12px; padding:20px 22px; border:1px solid #bbf7d0;">
                <h1 style="margin:0 0 6px; font-size:20px; font-weight:700; color:#064e3b;">
                    Thank you for completing your feedback
                </h1>
                <p style="margin:0 0 8px; font-size:13px; color:#047857;">
                    Your responses have been recorded in the ICON Catalyst System.
                </p>
                <p style="margin:0; font-size:13px; color:#065f46;">
                    Your input will be combined with feedback from other raters to support
                    a constructive, future-focused development conversation.
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Bootstrap
Icon_PSY_Survey::init();
