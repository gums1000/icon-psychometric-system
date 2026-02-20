<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Auth helper file (NO redirects)
 * Single source of truth for Icon auth URLs.
 */

if ( ! function_exists( 'icon_psy_portal_url' ) ) {
    function icon_psy_portal_url() {
        return home_url( '/catalyst-portal/' );
    }
}

if ( ! function_exists( 'icon_psy_login_url' ) ) {
    function icon_psy_login_url() {
        return home_url( '/portal-login/' );
    }
}

if ( ! function_exists( 'icon_psy_register_url' ) ) {
    function icon_psy_register_url() {
        return home_url( '/register-page-2/' );
    }
}

if ( ! function_exists( 'icon_psy_lost_password_url' ) ) {
    function icon_psy_lost_password_url() {
        return home_url( '/lost-password-page/' );
    }
}

if ( ! function_exists( 'icon_psy_management_portal_url' ) ) {
    function icon_psy_management_portal_url() {
        return home_url( '/management-portal/' );
    }
}
