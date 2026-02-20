<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * DB helpers
 */

if ( ! function_exists( 'icon_psy_table_exists' ) ) {
    function icon_psy_table_exists( $table_name ) {
        global $wpdb;
        $like  = $wpdb->esc_like( $table_name );
        $found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $like ) );
        return ! empty( $found );
    }
}

if ( ! function_exists( 'icon_psy_prepare_in' ) ) {
    function icon_psy_prepare_in( $sql_with_placeholders, $values ) {
        global $wpdb;

        if ( empty( $values ) || ! is_array( $values ) ) {
            return $sql_with_placeholders;
        }

        $args = array_merge( array( $sql_with_placeholders ), array_values( $values ) );
        return call_user_func_array( array( $wpdb, 'prepare' ), $args );
    }
}
