<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

/**
 * COURSE ENGINE bootstrap
 * - tables
 * - helpers
 * - handlers
 */

/* ------------------------------------------------------------
 * Tables (v1)
 * ------------------------------------------------------------ */
function icon_psy_course_engine_install_tables() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();

	$client_settings = $wpdb->prefix . 'icon_psy_client_settings';

	$sql = "CREATE TABLE {$client_settings} (
		client_id BIGINT(20) UNSIGNED NOT NULL,
		logo_attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		brand_primary VARCHAR(20) NOT NULL DEFAULT '#15a06d',
		brand_secondary VARCHAR(20) NOT NULL DEFAULT '#14a4cf',
		portal_slug VARCHAR(120) NOT NULL DEFAULT '',
		updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (client_id),
		KEY portal_slug (portal_slug)
	) {$charset_collate};";

	dbDelta( $sql );

	// Optional version marker
	update_option( 'icon_psy_course_engine_db_version', '1.0' );
}
function icon_psy_get_client_branding( $client_id ) {
	global $wpdb;

	$client_id = absint( $client_id );
	if ( ! $client_id ) {
		return array(
			'logo_url'       => '',
			'primary'        => '#15a06d',
			'secondary'      => '#14a4cf',
			'portal_slug'    => '',
			'logo_id'        => 0,
		);
	}

	$table = $wpdb->prefix . 'icon_psy_client_settings';

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT client_id, logo_attachment_id, brand_primary, brand_secondary, portal_slug
		 FROM {$table}
		 WHERE client_id = %d",
		$client_id
	), ARRAY_A );

	$logo_id = 0;
	$primary = '#15a06d';
	$secondary = '#14a4cf';
	$slug = '';

	if ( $row ) {
		$logo_id   = (int) $row['logo_attachment_id'];
		$primary   = sanitize_text_field( $row['brand_primary'] );
		$secondary = sanitize_text_field( $row['brand_secondary'] );
		$slug      = sanitize_title( $row['portal_slug'] );
	}

	$logo_url = '';
	if ( $logo_id ) {
		$logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
		if ( ! $logo_url ) { $logo_url = ''; }
	}

	return array(
		'logo_url'    => $logo_url,
		'primary'     => $primary ? $primary : '#15a06d',
		'secondary'   => $secondary ? $secondary : '#14a4cf',
		'portal_slug' => $slug,
		'logo_id'     => $logo_id,
	);
}
function icon_psy_save_client_branding( $client_id, $logo_attachment_id, $primary, $secondary, $portal_slug ) {
	global $wpdb;

	$client_id = absint( $client_id );
	if ( ! $client_id ) return false;

	$table = $wpdb->prefix . 'icon_psy_client_settings';

	$logo_attachment_id = absint( $logo_attachment_id );
	$primary   = sanitize_text_field( $primary );
	$secondary = sanitize_text_field( $secondary );
	$portal_slug = sanitize_title( $portal_slug );

	// Basic sane defaults
	if ( ! $primary )   { $primary   = '#15a06d'; }
	if ( ! $secondary ) { $secondary = '#14a4cf'; }

	$exists = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(1) FROM {$table} WHERE client_id = %d",
		$client_id
	) );

	$data = array(
		'client_id'           => $client_id,
		'logo_attachment_id'  => $logo_attachment_id,
		'brand_primary'       => $primary,
		'brand_secondary'     => $secondary,
		'portal_slug'         => $portal_slug,
		'updated_at'          => current_time( 'mysql' ),
	);

	add_action( 'init', function () {
		$ver = get_option( 'icon_psy_course_engine_db_version', '' );
		if ( $ver !== '1.0' ) {
			icon_psy_course_engine_install_tables();
		}
	}, 5 );

	$formats = array( '%d','%d','%s','%s','%s','%s' );

	if ( $exists ) {
		unset( $data['client_id'] );
		unset( $formats[0] );
		$wpdb->update( $table, $data, array( 'client_id' => $client_id ), $formats, array( '%d' ) );
		return true;
	}

	$wpdb->insert( $table, $data, $formats );
	return true;
}
