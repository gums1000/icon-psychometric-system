<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ICON Catalyst Portal Router
 * Shortcode: [icon_psy_portal]
 *
 * Routes users to the correct portal based on role.
 * - icon_client        -> [icon_psy_client_portal]
 * - icon_individual    -> [icon_psy_self_portal]
 * - administrator      -> if impersonating client -> [icon_psy_client_portal], else show admin options
 * - not logged in      -> show login link
 */

if ( ! function_exists( 'icon_psy_user_has_role' ) ) {
    function icon_psy_user_has_role( $user, $role ) {
        if ( ! $user || empty( $role ) ) return false;
        $roles = isset( $user->roles ) ? (array) $user->roles : array();
        return in_array( $role, $roles, true );
    }
}

if ( ! function_exists( 'icon_psy_portal_shortcode' ) ) {

    function icon_psy_portal_shortcode( $atts ) {

        // Not logged in
        if ( ! is_user_logged_in() ) {
            $login_url = wp_login_url( get_permalink() );

            return '<div style="max-width:720px;margin:0 auto;padding:18px;border-radius:16px;border:1px solid #e5e7eb;background:#fff;">
                <h3 style="margin:0 0 6px;">ICON Catalyst Portal</h3>
                <p style="margin:0 0 10px;color:#4b5563;">Please log in to continue.</p>
                <a class="button button-primary" href="' . esc_url( $login_url ) . '">Log in</a>
            </div>';
        }

        $user = wp_get_current_user();
        if ( ! $user || empty( $user->ID ) ) {
            return '<p>Could not load your user profile.</p>';
        }

        // Admin impersonation (meta-based impersonation)
        if ( current_user_can( 'manage_options' ) && function_exists( 'icon_psy_get_effective_client_user_id' ) ) {
            $effective_id = (int) icon_psy_get_effective_client_user_id();

            // If effective user is not the admin themselves, treat as client view
            if ( $effective_id > 0 && $effective_id !== (int) $user->ID ) {
                return do_shortcode( '[icon_psy_client_portal]' );
            }
        }

        // Client users -> client portal
        if ( icon_psy_user_has_role( $user, 'icon_client' ) ) {
            return do_shortcode( '[icon_psy_client_portal]' );
        }

        // Individual users -> self portal
        if ( icon_psy_user_has_role( $user, 'icon_individual' ) ) {
            return do_shortcode( '[icon_psy_self_portal]' );
        }

        // Admin (not impersonating) -> show choices
        if ( current_user_can( 'manage_options' ) ) {

            $admin  = '<div style="max-width:820px;margin:0 auto;padding:18px;border-radius:16px;border:1px solid #e5e7eb;background:#fff;">';
            $admin .= '<h3 style="margin:0 0 6px;">ICON Catalyst Portal (Admin)</h3>';
            $admin .= '<p style="margin:0 0 10px;color:#4b5563;">You are logged in as an administrator. Choose what you want to view.</p>';
            $admin .= '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
            $admin .= '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=icon-psych-system' ) ) . '">Open Admin Dashboard</a>';
            $admin .= '<a class="button" href="' . esc_url( home_url( '/client-portal/' ) ) . '" target="_blank" rel="noopener">Open Client Portal Page</a>';
            $admin .= '</div>';
            $admin .= '<p style="margin:12px 0 0;color:#6b7280;font-size:12px;">Tip: Use the Clients screen “Open portal” to impersonate a client.</p>';
            $admin .= '</div>';

            return $admin;
        }

        // Fallback (non-role users)
        return '<p>Your account does not have access to a portal yet. Please contact support.</p>';
    }
}

add_shortcode( 'icon_psy_portal', 'icon_psy_portal_shortcode' );
