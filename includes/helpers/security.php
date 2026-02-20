<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Security / small utilities
 */

if ( ! function_exists( 'icon_psy_user_has_role' ) ) {
    function icon_psy_user_has_role( $user, $role ) {
        if ( ! $user || ! isset( $user->roles ) || ! is_array( $user->roles ) ) return false;
        return in_array( $role, $user->roles, true );
    }
}

if ( ! function_exists( 'icon_psy_get_effective_client_user_id' ) ) {
    function icon_psy_get_effective_client_user_id() {

        if ( ! is_user_logged_in() ) return 0;

        $uid = (int) get_current_user_id();
        $u   = get_user_by( 'id', $uid );
        if ( ! $u ) return 0;

        if ( icon_psy_user_has_role( $u, 'icon_client' ) ) {
            return $uid;
        }

        if ( current_user_can( 'manage_options' ) ) {

            $imp = get_user_meta( $uid, 'icon_psy_impersonate_client', true );
            if ( is_array( $imp ) ) {
                $cid = isset( $imp['client_id'] ) ? (int) $imp['client_id'] : 0;
                $exp = isset( $imp['expires'] ) ? (int) $imp['expires'] : 0;

                if ( $cid > 0 && $exp > time() ) {
                    return $cid;
                }

                if ( $cid > 0 ) {
                    delete_user_meta( $uid, 'icon_psy_impersonate_client' );
                }
            }

            $legacy = (int) get_user_meta( $uid, 'icon_psy_impersonate_client_id', true );
            if ( $legacy > 0 ) {
                return $legacy;
            }
        }

        return 0;
    }
}

if ( ! function_exists( 'icon_psy_is_completed_status' ) ) {
    function icon_psy_is_completed_status( $status ) {
        $s = strtolower( trim( (string) $status ) );
        return in_array( $s, array( 'completed', 'complete', 'submitted', 'done' ), true );
    }
}

if ( ! function_exists( 'icon_psy_rand_token' ) ) {
    function icon_psy_rand_token() {
        try {
            return bin2hex( random_bytes(16) );
        } catch ( Exception $e ) {
            return wp_generate_password( 32, false, false );
        }
    }
}

if ( ! function_exists( 'icon_psy_recent_action_guard' ) ) {
    function icon_psy_recent_action_guard( $user_id, $action_key, $window_seconds = 20 ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return false;

        $meta_key = 'icon_psy_last_action_' . sanitize_key( $action_key );
        $last     = (int) get_user_meta( $user_id, $meta_key, true );

        if ( $last > 0 && ( time() - $last ) < (int) $window_seconds ) {
            return true;
        }

        update_user_meta( $user_id, $meta_key, time() );
        return false;
    }
}
