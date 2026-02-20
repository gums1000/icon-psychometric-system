<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ICON Portal Endpoint (Workaround B - cleaned)
 *
 * Purpose:
 * - Avoid routing/query-var/page-template issues with Astra/WordPress.
 * - Render the client portal all-in-one shortcode directly when:
 *   1) Visiting the page slug /catalyst-portal/
 *   OR
 *   2) URL includes ?icon_portal=1
 *
 * IMPORTANT:
 * - This assumes you have a real WP Page with slug: catalyst-portal
 * - That page can be blank; we intercept output and render the shortcode directly.
 *
 * Test URLs:
 * - https://icon-talent.org/catalyst-portal/
 * - https://icon-talent.org/catalyst-portal/?icon_portal=1
 * - https://icon-talent.org/catalyst-portal/#register  (hash is fine for the JS tabs)
 */

add_action( 'template_redirect', function () {

    // ---- Decide whether we should render ----
    $flag = isset( $_GET['icon_portal'] ) ? sanitize_text_field( wp_unslash( $_GET['icon_portal'] ) ) : '';

    $is_portal_page = false;
    if ( function_exists( 'is_page' ) ) {
        // Match by slug (recommended)
        $is_portal_page = is_page( 'catalyst-portal' );
    }

    // Trigger if:
    // - you’re on the portal page slug, OR
    // - explicitly using ?icon_portal=1
    $should_render = ( $is_portal_page || $flag === '1' );

    if ( ! $should_render ) {
        return;
    }

    error_log( 'ICON_PORTAL endpoint TRIGGERED. is_page(catalyst-portal)=' . ( $is_portal_page ? 'YES' : 'NO' ) . ' flag=' . $flag );

    // ---- Prevent 404/theme output ----
    global $wp_query;
    if ( $wp_query && is_object( $wp_query ) ) {
        $wp_query->is_404 = false;
        status_header( 200 );
    } else {
        status_header( 200 );
    }

    nocache_headers();

    // ---- Render the portal (logged-out shows login/register/forgot) ----
    // Make sure the shortcode exists
    if ( ! shortcode_exists( 'icon_psy_client_portal_allinone' ) ) {
        error_log( 'ICON_PORTAL ERROR: shortcode [icon_psy_client_portal_allinone] not registered.' );
        echo '<p style="padding:16px;border:1px solid #fecaca;background:#fef2f2;border-radius:12px;">
            Portal error: shortcode <strong>[icon_psy_client_portal_allinone]</strong> is not registered.
        </p>';
        exit;
    }

    echo do_shortcode( '[icon_psy_client_portal_allinone]' );
    exit;

}, 0 );
