<?php
/**
 * Front-end helpers for Icon Psychometric System
 * - Hides theme header/footer on any page using an [icon_psy_*] shortcode
 * - Handles secure front-end deletion of projects, participants, and raters
 * - Handles adding participants and raters from the Client Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Icon_PSY_Frontend {

    /**
     * Init hooks.
     */
    public static function init() {
        // Decide whether the current page should be "full screen"
        add_action( 'template_redirect', array( __CLASS__, 'maybe_fullscreen_page' ) );

        // Front-end delete handlers (admin-post)
        add_action( 'admin_post_icon_psy_delete_project', array( __CLASS__, 'handle_delete_project' ) );
        add_action( 'admin_post_icon_psy_delete_participant', array( __CLASS__, 'handle_delete_participant' ) );
        add_action( 'admin_post_icon_psy_delete_rater', array( __CLASS__, 'handle_delete_rater' ) );

        // Front-end add handlers (admin-post)
        add_action( 'admin_post_icon_psy_add_participant', array( __CLASS__, 'handle_add_participant' ) );
        add_action( 'admin_post_icon_psy_add_rater', array( __CLASS__, 'handle_add_rater' ) );
    }

    /**
     * Detect any page using an [icon_psy_*] shortcode and mark it as fullscreen.
     */
    public static function maybe_fullscreen_page() {
        if ( ! is_singular() ) {
            return;
        }

        global $post;

        if ( ! $post instanceof WP_Post ) {
            return;
        }

        $content = $post->post_content;

        // Any shortcode that starts with [icon_psy_ will trigger "app style" layout.
        if ( strpos( $content, '[icon_psy_' ) === false ) {
            return;
        }

        // Add a body class we can target with CSS.
        add_filter( 'body_class', array( __CLASS__, 'add_fullscreen_body_class' ) );

        // Output CSS to hide theme chrome.
        add_action( 'wp_head', array( __CLASS__, 'output_fullscreen_css' ), 20 );
    }

    /**
     * Add body class to pages that should hide menus/headers/footers.
     */
    public static function add_fullscreen_body_class( $classes ) {
        $classes[] = 'icon-psy-fullscreen';
        return $classes;
    }

    /**
     * CSS that hides typical theme headers/footers/nav on fullscreen pages.
     * This is intentionally broad to catch most themes (SeedProd, Elementor, etc.).
     */
    public static function output_fullscreen_css() {
        ?>
        <style id="icon-psy-fullscreen-css">
        /* Remove header / nav / footer for Icon Psych pages */
        body.icon-psy-fullscreen header,
        body.icon-psy-fullscreen .site-header,
        body.icon-psy-fullscreen nav,
        body.icon-psy-fullscreen .main-navigation,
        body.icon-psy-fullscreen .navbar,
        body.icon-psy-fullscreen .elementor-location-header,
        body.icon-psy-fullscreen .page-header,
        body.icon-psy-fullscreen .tutor-header,
        body.icon-psy-fullscreen footer,
        body.icon-psy-fullscreen .site-footer,
        body.icon-psy-fullscreen .elementor-location-footer,
        body.icon-psy-fullscreen .tutor-footer {
            display: none !important;
        }

        /* Remove extra padding some themes add */
        body.icon-psy-fullscreen {
            margin: 0 !important;
        }

        body.icon-psy-fullscreen #content,
        body.icon-psy-fullscreen .site-content,
        body.icon-psy-fullscreen .content-area,
        body.icon-psy-fullscreen .entry-content {
            padding-top: 0 !important;
            padding-bottom: 0 !important;
        }
        </style>
        <?php
    }

    /**
     * Helper: redirect back to referring page (usually Client Portal) with a message.
     *
     * @param string $code   A short message code, e.g. 'project_deleted' or 'participant_added'
     */
    protected static function redirect_with_message( $code ) {
        $referer = wp_get_referer();

        if ( ! $referer ) {
            $referer = home_url( '/' );
        }

        $url = add_query_arg(
            array(
                'icon_psy_msg' => sanitize_key( $code ),
            ),
            $referer
        );

        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Handle deletion of a project (cascades to participants + raters).
     * Triggered via: admin-post.php?action=icon_psy_delete_project
     */
    public static function handle_delete_project() {
        if ( ! is_user_logged_in() ) {
            wp_die( __( 'You must be logged in to perform this action.', 'icon-psy' ) );
        }

        $project_id = isset( $_REQUEST['project_id'] ) ? (int) $_REQUEST['project_id'] : 0;

        if ( $project_id <= 0 ) {
            self::redirect_with_message( 'action_error' );
        }

        // Nonce check (matches build in shortcode)
        check_admin_referer( 'icon_psy_delete_project_' . $project_id );

        global $wpdb;

        $projects_table     = $wpdb->prefix . 'icon_psy_projects';
        $participants_table = $wpdb->prefix . 'icon_psy_participants';
        $raters_table       = $wpdb->prefix . 'icon_psy_raters';

        // Find participants for this project
        $participant_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$participants_table} WHERE project_id = %d",
                $project_id
            )
        );

        if ( ! empty( $participant_ids ) ) {
            $participant_ids   = array_map( 'intval', $participant_ids );
            $in_placeholders   = implode( ',', array_fill( 0, count( $participant_ids ), '%d' ) );

            // Delete raters linked to those participants
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$raters_table} WHERE participant_id IN ($in_placeholders)",
                    $participant_ids
                )
            );

            // Delete participants
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$participants_table} WHERE id IN ($in_placeholders)",
                    $participant_ids
                )
            );
        }

        // Finally delete the project
        $wpdb->delete(
            $projects_table,
            array( 'id' => $project_id ),
            array( '%d' )
        );

        self::redirect_with_message( 'project_deleted' );
    }

    /**
     * Handle deletion of a participant (cascades to raters).
     * Triggered via: admin-post.php?action=icon_psy_delete_participant
     */
    public static function handle_delete_participant() {
        if ( ! is_user_logged_in() ) {
            wp_die( __( 'You must be logged in to perform this action.', 'icon-psy' ) );
        }

        $participant_id = isset( $_REQUEST['participant_id'] ) ? (int) $_REQUEST['participant_id'] : 0;

        if ( $participant_id <= 0 ) {
            self::redirect_with_message( 'action_error' );
        }

        // Nonce check
        check_admin_referer( 'icon_psy_delete_participant_' . $participant_id );

        global $wpdb;

        $participants_table = $wpdb->prefix . 'icon_psy_participants';
        $raters_table       = $wpdb->prefix . 'icon_psy_raters';

        // Delete raters for this participant
        $wpdb->delete(
            $raters_table,
            array( 'participant_id' => $participant_id ),
            array( '%d' )
        );

        // Delete participant
        $wpdb->delete(
            $participants_table,
            array( 'id' => $participant_id ),
            array( '%d' )
        );

        self::redirect_with_message( 'participant_deleted' );
    }

    /**
     * Handle deletion of a rater.
     * Triggered via: admin-post.php?action=icon_psy_delete_rater
     */
    public static function handle_delete_rater() {
        if ( ! is_user_logged_in() ) {
            wp_die( __( 'You must be logged in to perform this action.', 'icon-psy' ) );
        }

        $rater_id = isset( $_REQUEST['rater_id'] ) ? (int) $_REQUEST['rater_id'] : 0;

        if ( $rater_id <= 0 ) {
            self::redirect_with_message( 'action_error' );
        }

        // Nonce check
        check_admin_referer( 'icon_psy_delete_rater_' . $rater_id );

        global $wpdb;

        $raters_table = $wpdb->prefix . 'icon_psy_raters';

        // Delete rater
        $wpdb->delete(
            $raters_table,
            array( 'id' => $rater_id ),
            array( '%d' )
        );

        self::redirect_with_message( 'rater_deleted' );
    }

    /**
     * Handle adding a participant from the front-end portal.
     * Triggered via: admin-post.php?action=icon_psy_add_participant
     */
    public static function handle_add_participant() {
        if ( ! is_user_logged_in() ) {
            wp_die( __( 'You must be logged in to perform this action.', 'icon-psy' ) );
        }

        $project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
        if ( $project_id <= 0 ) {
            self::redirect_with_message( 'action_error' );
        }

        check_admin_referer( 'icon_psy_add_participant_' . $project_id );

        $name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $role  = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';

        if ( $name === '' ) {
            self::redirect_with_message( 'action_error' );
        }

        global $wpdb;
        $participants_table = $wpdb->prefix . 'icon_psy_participants';

        $inserted = $wpdb->insert(
            $participants_table,
            array(
                'project_id' => $project_id,
                'name'       => $name,
                'email'      => $email,
                'role'       => $role,
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            self::redirect_with_message( 'action_error' );
        }

        self::redirect_with_message( 'participant_added' );
    }

    /**
     * Handle adding a rater from the front-end portal.
     * Triggered via: admin-post.php?action=icon_psy_add_rater
     */
    public static function handle_add_rater() {
        if ( ! is_user_logged_in() ) {
            wp_die( __( 'You must be logged in to perform this action.', 'icon-psy' ) );
        }

        $participant_id = isset( $_POST['participant_id'] ) ? (int) $_POST['participant_id'] : 0;
        if ( $participant_id <= 0 ) {
            self::redirect_with_message( 'action_error' );
        }

        check_admin_referer( 'icon_psy_add_rater_' . $participant_id );

        $name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $relationship = isset( $_POST['relationship'] ) ? sanitize_text_field( wp_unslash( $_POST['relationship'] ) ) : '';

        if ( $name === '' ) {
            self::redirect_with_message( 'action_error' );
        }

        global $wpdb;
        $raters_table = $wpdb->prefix . 'icon_psy_raters';

        $inserted = $wpdb->insert(
            $raters_table,
            array(
                'participant_id' => $participant_id,
                'name'           => $name,
                'email'          => $email,
                'relationship'   => $relationship,
                'status'         => 'invited',
                'created_at'     => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            self::redirect_with_message( 'action_error' );
        }

        self::redirect_with_message( 'rater_added' );
    }
}
