<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * helpers-branding.php
 * Client branding helpers for Icon Catalyst.
 *
 * Stores branding against a client user (client_user_id) using user_meta.
 *
 * Meta keys:
 * - icon_psy_brand_logo_url
 * - icon_psy_brand_primary
 * - icon_psy_brand_secondary
 *
 * Returned array keys (stable API):
 * - logo_url
 * - primary
 * - secondary
 */

/* ------------------------------------------------------------
 * Small utilities
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_branding_sanitize_hex' ) ) {
	function icon_psy_branding_sanitize_hex( $hex, $fallback = '#15a06d' ) {
		$hex = is_string( $hex ) ? trim( $hex ) : '';
		if ( $hex === '' ) return $fallback;
		if ( $hex[0] !== '#' ) $hex = '#' . $hex;
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ? strtoupper( $hex ) : $fallback;
	}
}

if ( ! function_exists( 'icon_psy_branding_default' ) ) {
	function icon_psy_branding_default() {
		return array(
			'logo_url'  => 'https://icon-talent.org/wp-content/uploads/2025/12/Icon-Catalyst-System.png',
			'primary'   => '#15A06D',
			'secondary' => '#14A4CF',
		);
	}
}

/* ------------------------------------------------------------
 * Core API: get branding for a client user
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_get_client_branding' ) ) {
	function icon_psy_get_client_branding( $client_user_id ) {
		$client_user_id = (int) $client_user_id;
		$defaults       = icon_psy_branding_default();

		if ( $client_user_id <= 0 ) {
			return $defaults;
		}

		$logo_url  = get_user_meta( $client_user_id, 'icon_psy_brand_logo_url', true );
		$primary   = get_user_meta( $client_user_id, 'icon_psy_brand_primary', true );
		$secondary = get_user_meta( $client_user_id, 'icon_psy_brand_secondary', true );

		$out = array(
			'logo_url'  => is_string( $logo_url ) && $logo_url !== '' ? esc_url_raw( $logo_url ) : $defaults['logo_url'],
			'primary'   => icon_psy_branding_sanitize_hex( $primary,   $defaults['primary'] ),
			'secondary' => icon_psy_branding_sanitize_hex( $secondary, $defaults['secondary'] ),
		);

		/**
		 * Backwards/compat tolerant aliases:
		 * If any older keys exist, allow them to override if the new key is empty.
		 */
		if ( ( ! $logo_url || $logo_url === '' ) ) {
			$alt = get_user_meta( $client_user_id, 'icon_psy_logo_url', true );
			if ( is_string( $alt ) && $alt !== '' ) $out['logo_url'] = esc_url_raw( $alt );
		}
		if ( ( ! $primary || $primary === '' ) ) {
			$alt = get_user_meta( $client_user_id, 'icon_psy_primary', true );
			if ( is_string( $alt ) && $alt !== '' ) $out['primary'] = icon_psy_branding_sanitize_hex( $alt, $defaults['primary'] );
		}
		if ( ( ! $secondary || $secondary === '' ) ) {
			$alt = get_user_meta( $client_user_id, 'icon_psy_secondary', true );
			if ( is_string( $alt ) && $alt !== '' ) $out['secondary'] = icon_psy_branding_sanitize_hex( $alt, $defaults['secondary'] );
		}

		return $out;
	}
}

/* ------------------------------------------------------------
 * Save branding for a client user
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_save_client_branding' ) ) {
	function icon_psy_save_client_branding( $client_user_id, $branding ) {
		$client_user_id = (int) $client_user_id;
		if ( $client_user_id <= 0 ) return false;

		$branding = is_array( $branding ) ? $branding : array();
		$defaults = icon_psy_branding_default();

		// Accept multiple incoming key names
		$logo = '';
		if ( ! empty( $branding['logo_url'] ) )        $logo = (string) $branding['logo_url'];
		elseif ( ! empty( $branding['logo'] ) )        $logo = (string) $branding['logo'];
		elseif ( ! empty( $branding['brand_logo_url'] ) ) $logo = (string) $branding['brand_logo_url'];

		$primary   = $branding['primary']   ?? ( $branding['brand_primary']   ?? '' );
		$secondary = $branding['secondary'] ?? ( $branding['brand_secondary'] ?? '' );

		$logo_url  = $logo !== '' ? esc_url_raw( $logo ) : '';
		$primary   = icon_psy_branding_sanitize_hex( $primary, $defaults['primary'] );
		$secondary = icon_psy_branding_sanitize_hex( $secondary, $defaults['secondary'] );

		$ok1 = update_user_meta( $client_user_id, 'icon_psy_brand_logo_url', $logo_url );
		$ok2 = update_user_meta( $client_user_id, 'icon_psy_brand_primary', $primary );
		$ok3 = update_user_meta( $client_user_id, 'icon_psy_brand_secondary', $secondary );

		// update_user_meta returns false if value unchanged; treat as success if any write succeeded OR nothing needed changing
		return ( $ok1 || $ok2 || $ok3 || ( $ok1 === false && $ok2 === false && $ok3 === false ) );
	}
}

/* ------------------------------------------------------------
 * Optional: delete branding for a client user (revert to defaults)
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_delete_client_branding' ) ) {
	function icon_psy_delete_client_branding( $client_user_id ) {
		$client_user_id = (int) $client_user_id;
		if ( $client_user_id <= 0 ) return false;

		delete_user_meta( $client_user_id, 'icon_psy_brand_logo_url' );
		delete_user_meta( $client_user_id, 'icon_psy_brand_primary' );
		delete_user_meta( $client_user_id, 'icon_psy_brand_secondary' );

		return true;
	}
}

/* ------------------------------------------------------------
 * Helper: branding for participant (participant -> project -> client_user_id)
 * (Keeps your portal/report code simpler)
 * ------------------------------------------------------------ */

if ( ! function_exists( 'icon_psy_get_branding_for_participant' ) ) {
	function icon_psy_get_branding_for_participant( $participant_id ) {
		global $wpdb;

		$participant_id = (int) $participant_id;
		$defaults       = icon_psy_branding_default();

		if ( $participant_id <= 0 ) return $defaults;

		$participants = $wpdb->prefix . 'icon_psy_participants';
		$projects     = $wpdb->prefix . 'icon_psy_projects';

		$project_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT project_id FROM {$participants} WHERE id=%d LIMIT 1", $participant_id )
		);
		if ( $project_id <= 0 ) return $defaults;

		$client_user_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT client_user_id FROM {$projects} WHERE id=%d LIMIT 1", $project_id )
		);
		if ( $client_user_id <= 0 ) return $defaults;

		$b = icon_psy_get_client_branding( $client_user_id );
		return is_array( $b ) ? $b : $defaults;
	}
}
