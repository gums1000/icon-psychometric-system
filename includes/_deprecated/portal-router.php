<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode: [icon_psy_portal]
 * Routes logged-in users to the correct portal based on role/meta.
 */

if ( ! function_exists( 'icon_psy_portal_router_shortcode' ) ) {

    function icon_psy_portal_router_shortcode() {

        // Send non-logged-in users to your branded auth page
        if ( ! is_user_logged_in() ) {
            $auth_url = home_url( '/login-register/' ); // <-- change if your auth page slug differs
            $auth_url = add_query_arg( 'view', 'login', $auth_url );

            return '<p>You need to be logged in. <a href="' . esc_url( $auth_url ) . '">Login</a></p>';
        }

        $user  = wp_get_current_user();
        $roles = (array) $user->roles;

        // First: direct role check
        if ( in_array( 'icon_client', $roles, true ) ) {
            return do_shortcode( '[icon_psy_client_portal]' );
        }

        if ( in_array( 'icon_individual', $roles, true ) ) {
            return do_shortcode( '[icon_psy_self_portal]' );
        }

        // Fallback: meta check (covers older accounts that never got the role)
        $account_type = get_user_meta( $user->ID, 'icon_account_type', true );

        if ( $account_type === 'business' || get_user_meta( $user->ID, 'icon_client_org', true ) ) {
            if ( function_exists( 'icon_psy_sync_user_portal_role' ) ) {
                icon_psy_sync_user_portal_role( $user->ID );
            }
            return do_shortcode( '[icon_psy_client_portal]' );
        }

        if ( $account_type === 'individual' ) {
            if ( function_exists( 'icon_psy_sync_user_portal_role' ) ) {
                icon_psy_sync_user_portal_role( $user->ID );
            }
            return do_shortcode( '[icon_psy_self_portal]' );
        }

        return '<p>Your account does not have access to a portal yet. Please contact support.</p>';
    }
}

add_shortcode( 'icon_psy_portal', 'icon_psy_portal_router_shortcode' );
