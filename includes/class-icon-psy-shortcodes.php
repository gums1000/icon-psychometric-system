<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pull in the split shortcode files.
 * Each file defines plain functions like:
 *   - icon_psy_client_portal()
 *   - icon_psy_participant_portal()
 *   - icon_psy_rater_portal()
 *   - icon_psy_feedback_report()
 *   - icon_psy_rater_survey2()
 */
require_once __DIR__ . '/shortcodes/client-portal.php';
require_once __DIR__ . '/shortcodes/participant-portal.php';
require_once __DIR__ . '/shortcodes/rater-portal.php';
require_once __DIR__ . '/shortcodes/feedback-report.php';
require_once __DIR__ . '/shortcodes/rater-survey.php'; // ⬅️ make sure this file exists

/**
 * Central shortcode registrar.
 */
class Icon_PSY_Shortcodes {

    /**
     * Register all front-end shortcodes.
     */
    public static function init() {

        // Client portal (projects / participants / raters)
        add_shortcode(
            'icon_psy_client_portal',
            'icon_psy_client_portal'
        );

        // Participant "my assessment" portal
        add_shortcode(
            'icon_psy_participant_portal',
            'icon_psy_participant_portal'
        );

        // Rater portal (token-based entry into the survey)
        add_shortcode(
            'icon_psy_rater_portal',
            'icon_psy_rater_portal'
        );

        // Feedback report (manager / client view)
        add_shortcode(
            'icon_psy_feedback_report',
            'icon_psy_feedback_report'
        );

        // Dedicated 1–7 per-competency survey
        // Shortcode: [icon_psy_rater_survey2]
        add_shortcode(
            'icon_psy_rater_survey2',
            'icon_psy_rater_survey2'
        );
    }
}
