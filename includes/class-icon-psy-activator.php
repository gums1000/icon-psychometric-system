<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Icon_PSY_Activator {

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $projects_table      = $wpdb->prefix . 'icon_psy_projects';
        $participants_table  = $wpdb->prefix . 'icon_psy_participants';
        $raters_table        = $wpdb->prefix . 'icon_psy_raters';
        $results_table       = $wpdb->prefix . 'icon_psy_results';
        $frameworks_table    = $wpdb->prefix . 'icon_psy_frameworks';
        $competencies_table  = $wpdb->prefix . 'icon_psy_framework_competencies';
        $questions_table     = $wpdb->prefix . 'icon_psy_framework_questions';
        $texts_table         = $wpdb->prefix . 'icon_psy_framework_texts';

        $sql = "";

        $sql .= "CREATE TABLE $projects_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            client_name VARCHAR(255) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;\n";

        $sql .= "CREATE TABLE $participants_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT 0,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            role VARCHAR(100) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            token VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY project_id (project_id),
            KEY token (token)
        ) $charset_collate;\n";

        $sql .= "CREATE TABLE $raters_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT(20) UNSIGNED NOT NULL,
            participant_id BIGINT(20) UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            relationship VARCHAR(100) NULL,
            token VARCHAR(64) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'invited',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY project_id (project_id),
            KEY participant_id (participant_id),
            KEY token (token)
        ) $charset_collate;\n";

        $raters_table = $wpdb->prefix . 'icon_psy_raters';
$columns = $wpdb->get_col("DESC {$raters_table}", 0);

if ( ! in_array( 'token', $columns, true ) ) {
    $wpdb->query("
        ALTER TABLE {$raters_table}
        ADD COLUMN token VARCHAR(64) NULL AFTER email
    ");
}


        // Results table (scores + comments)
$results_table = $wpdb->prefix . 'icon_psy_results';

$sql = "CREATE TABLE $results_table (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT(20) UNSIGNED NOT NULL,
    participant_id BIGINT(20) UNSIGNED NOT NULL,
    rater_id BIGINT(20) UNSIGNED NOT NULL,
    competency_id BIGINT(20) UNSIGNED NOT NULL,
    question_id BIGINT(20) UNSIGNED NOT NULL,
    score DECIMAL(5,2) NULL,
    comment TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY project_id (project_id),
    KEY participant_id (participant_id),
    KEY rater_id (rater_id),
    KEY competency_id (competency_id)
) $charset_collate;";

dbDelta( $sql );


        $sql .= "CREATE TABLE $frameworks_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            type VARCHAR(50) NOT NULL DEFAULT '360',
            owner VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;\n";

        $sql .= "CREATE TABLE $competencies_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            framework_id BIGINT(20) UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            sort_order INT(11) NOT NULL DEFAULT 0,
            colour VARCHAR(20) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY framework_id (framework_id)
        ) $charset_collate;\n";

        $sql .= "CREATE TABLE $questions_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            framework_id BIGINT(20) UNSIGNED NOT NULL,
            competency_id BIGINT(20) UNSIGNED NOT NULL,
            question_text TEXT NOT NULL,
            sort_order INT(11) NOT NULL DEFAULT 0,
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            comment_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY framework_id (framework_id),
            KEY competency_id (competency_id)
        ) $charset_collate;\n";

        $sql .= "CREATE TABLE $texts_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            framework_id BIGINT(20) UNSIGNED NOT NULL,
            competency_id BIGINT(20) UNSIGNED NOT NULL,
            high_text TEXT NULL,
            mid_text TEXT NULL,
            low_text TEXT NULL,
            development_text TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY framework_id (framework_id),
            KEY competency_id (competency_id)
        ) $charset_collate;\n";

        dbDelta( $sql );
    }
}
