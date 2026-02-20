<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

/**
 * ICON Traits Report — PDF Template (Dompdf)
 *
 * Location:
 *   /wp-content/plugins/<your-plugin>/includes/pdf/templates/traits.php
 *
 * Called by central engine:
 *   icon_psy_pdf_render_and_stream('traits', ['participant_id' => XX]);
 *
 * PDF-ONLY (no JS):
 * - WOW Cover page
 * - Fixed header/footer + watermark
 * - Dompdf-safe pills/chips (no flex/gap)
 * - Radar rendered to PNG (GD) + table of radar items
 * - Sections: Quick Scan, Key Takeaways, Radar, Trend Map, Heatmaps, Tables, Summaries, Model
 *
 * FIX (this update):
 * - Removes “empty page-break divs” (common cause of BLANK PAGES in Dompdf)
 *   and applies page breaks directly to the section card that follows.
 * - Stops forcing page-break-inside:avoid on ALL cards (another common cause of “missing pages”
 *   when a card grows taller than a page). Now only “.nobreak” cards avoid splits.
 */

/* ------------------------------------------------------------
 * Input
 * ------------------------------------------------------------ */
$participant_id = isset( $icon_pdf_args['participant_id'] ) ? absint( $icon_pdf_args['participant_id'] ) : 0;
if ( ! $participant_id ) { wp_die( 'Missing participant_id' ); }

$is_pdf = true;

/* ------------------------------------------------------------
 * Helpers (DB + decode)
 * ------------------------------------------------------------ */
$table_exists = function( $table ) use ( $wpdb ) {
	$table = (string) $table;
	if ( $table === '' ) return false;
	$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
	return ( $found === $table );
};

$first_existing_table = function( $candidates ) use ( $table_exists ) {
	foreach ( (array) $candidates as $t ) {
		if ( $table_exists( $t ) ) return $t;
	}
	return '';
};

$get_cols = function( $table ) use ( $wpdb ) {
	$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
	return is_array( $cols ) ? $cols : array();
};

$decode_detail_json = function( $raw ) {
	$raw = is_string( $raw ) ? trim( $raw ) : '';
	if ( $raw === '' || $raw === 'null' ) return null;

	$d1 = json_decode( $raw, true );
	if ( is_array( $d1 ) ) return $d1;

	if ( is_string( $d1 ) && $d1 !== '' ) {
		$d2 = json_decode( $d1, true );
		if ( is_array( $d2 ) ) return $d2;
	}

	$unescaped = stripcslashes( $raw );
	if ( $unescaped !== $raw ) {
		$d3 = json_decode( $unescaped, true );
		if ( is_array( $d3 ) ) return $d3;

		if ( is_string( $d3 ) && $d3 !== '' ) {
			$d4 = json_decode( $d3, true );
			if ( is_array( $d4 ) ) return $d4;
		}
	}

	if ( function_exists( 'is_serialized' ) && is_serialized( $raw ) ) {
		$u = @unserialize( $raw );
		if ( is_array( $u ) ) return $u;
	}

	return null;
};

$clamp = function( $v, $min, $max ) {
	$v = (int) $v;
	return max( (int) $min, min( (int) $max, $v ) );
};

$band = function( $v ) {
	$v = (float) $v;
	if ( $v >= 5.6 ) return array( 'High', '#065f46', '#ecfdf5', '#bbf7d0' );
	if ( $v >= 4.6 ) return array( 'Solid', '#1e3a8a', '#eff6ff', '#c7d2fe' );
	if ( $v >= 3.6 ) return array( 'Developing', '#92400e', '#fffbeb', '#fde68a' );
	return array( 'Priority', '#7f1d1d', '#fef2f2', '#fecaca' );
};

$delta_classification = function( $delta ) {
	$delta = (float) $delta;
	if ( $delta >= 0.35 ) return 'Meaningful improvement';
	if ( $delta <= -0.35 ) return 'Meaningful decline';
	if ( abs( $delta ) >= 0.15 ) return 'Directional change';
	return 'Broadly stable';
};

$score_color = function( $score ) {
	$s = (int) $score;
	$s = max( 1, min( 7, $s ) );

	if ( $s <= 4 ) {
		$t = ( $s - 1 ) / 3;
		$r = (int) round( 220 + (245 - 220) * $t );
		$g = (int) round( 38 + (158 - 38) * $t );
		$b = (int) round( 38 + (11 - 38) * $t );
	} else {
		$t = ( $s - 4 ) / 3;
		$r = (int) round( 245 + (5 - 245) * $t );
		$g = (int) round( 158 + (150 - 158) * $t );
		$b = (int) round( 11 + (105 - 11) * $t );
	}

	$bg   = sprintf( 'rgb(%d,%d,%d)', $r, $g, $b );
	$text = ( $s <= 3 ) ? '#ffffff' : '#0b2f2a';

	return array( $bg, $text );
};

$delta_color = function( $delta ) {
	$d = (float) $delta;
	if ( abs( $d ) < 0.00001 ) return array( '#f3f4f6', '#0f172a' );

	$d   = max( -6.0, min( 6.0, $d ) );
	$int = abs( $d ) / 6.0;

	if ( $d > 0 ) {
		$r    = (int) round( 243 + (5 - 243) * $int );
		$g    = (int) round( 244 + (150 - 244) * $int );
		$b    = (int) round( 246 + (105 - 246) * $int );
		$text = '#064e3b';
	} else {
		$r    = (int) round( 243 + (220 - 243) * $int );
		$g    = (int) round( 244 + (38 - 244) * $int );
		$b    = (int) round( 246 + (38 - 246) * $int );
		$text = '#7f1d1d';
	}

	return array( sprintf( 'rgb(%d,%d,%d)', $r, $g, $b ), $text );
};

$stability_label = function( $abs_gap ) {
	$g = (int) $abs_gap;
	if ( $g <= 1 ) return array( 'Stable', '#065f46', '#ecfdf5', '#bbf7d0' );
	if ( $g === 2 ) return array( 'Variable', '#92400e', '#fffbeb', '#fde68a' );
	return array( 'Volatile', '#7f1d1d', '#fef2f2', '#fecaca' );
};

$schema_ok = function( $decoded ) {
	if ( ! is_array( $decoded ) ) return false;
	if ( empty( $decoded['schema'] ) ) return false;
	if ( empty( $decoded['items'] ) || ! is_array( $decoded['items'] ) ) return false;
	$schema = (string) $decoded['schema'];
	return in_array( $schema, array( 'icon_traits_v2', 'icon_traits_v1' ), true );
};

$get_phase = function( $decoded ) {
	$p = isset( $decoded['measurement_phase'] ) ? sanitize_key( (string) $decoded['measurement_phase'] ) : '';
	return in_array( $p, array( 'baseline', 'post' ), true ) ? $p : 'baseline';
};

$get_pair_key = function( $decoded ) {
	return isset( $decoded['pair_key'] ) ? (string) $decoded['pair_key'] : '';
};

$normalise_items_map = function( $payload ) use ( $clamp ) {
	$map = array();
	if ( ! is_array( $payload ) || empty( $payload['items'] ) || ! is_array( $payload['items'] ) ) return $map;

	foreach ( (array) $payload['items'] as $k => $v ) {
		if ( ! is_array( $v ) ) continue;

		$q1         = isset( $v['q1'] ) ? $clamp( $v['q1'], 1, 7 ) : 1;
		$q2         = isset( $v['q2'] ) ? $clamp( $v['q2'], 1, 7 ) : 1;
		$q3         = isset( $v['q3'] ) ? $clamp( $v['q3'], 1, 7 ) : 1;
		$confidence = isset( $v['confidence'] ) ? $clamp( $v['confidence'], 1, 5 ) : 3;
		$evidence   = isset( $v['evidence'] ) ? (string) $v['evidence'] : '';

		if ( isset( $v['competency_name'] ) && $v['competency_name'] !== '' ) $name = (string) $v['competency_name'];
		elseif ( isset( $v['trait_name'] ) && $v['trait_name'] !== '' ) $name = (string) $v['trait_name'];
		elseif ( isset( $v['trait_key'] ) && $v['trait_key'] !== '' ) $name = (string) $v['trait_key'];
		else $name = (string) $k;

		if ( isset( $v['module'] ) && $v['module'] !== '' ) $module = (string) $v['module'];
		elseif ( isset( $v['competency_module'] ) && $v['competency_module'] !== '' ) $module = (string) $v['competency_module'];
		elseif ( isset( $v['trait_module'] ) && $v['trait_module'] !== '' ) $module = (string) $v['trait_module'];
		else $module = 'core';

		$avg = ( $q1 + $q2 + $q3 ) / 3;

		$map[(string) $k] = array(
			'key'        => (string) $k,
			'name'       => $name,
			'module'     => $module,
			'q1'         => $q1,
			'q2'         => $q2,
			'q3'         => $q3,
			'avg'        => (float) $avg,
			'confidence' => (int) $confidence,
			'evidence'   => $evidence,
		);
	}

	return $map;
};

$make_summary = function( $base, $post = null ) use ( $delta_classification ) {
	$name         = $base['name'];
	$q1           = (int) $base['q1'];
	$q2           = (int) $base['q2'];
	$avg          = (float) $base['avg'];
	$pressure_gap = $q2 - $q1;
	$abs_gap      = abs( $pressure_gap );

	if ( $avg >= 5.6 ) $action = 'Maintain: apply deliberately to one high value task each week, then review evidence.';
	elseif ( $avg >= 4.6 ) $action = 'Sharpen: pick one observable behaviour to move by one point and capture evidence.';
	elseif ( $avg >= 3.6 ) $action = 'Build: practise in low risk situations, then repeat under time/complexity constraints.';
	else $action = 'Priority: start small, focus on one behaviour, and record short evidence twice per week.';

	if ( $abs_gap >= 2 && $pressure_gap < 0 ) $note = 'Pattern: less stable under pressure (variance ' . (int) $abs_gap . '). Add a fallback behaviour for time tight situations.';
	elseif ( $abs_gap >= 2 && $pressure_gap > 0 ) $note = 'Pattern: strengthens under pressure (variance ' . (int) $abs_gap . '). Use it as a stabiliser for complex tasks.';
	else $note = 'Pattern: broadly stable across contexts (variance ' . (int) $abs_gap . '). Focus on making it more repeatable.';

	if ( $post && is_array( $post ) ) {
		$delta          = (float) $post['avg'] - (float) $base['avg'];
		$delta_txt      = ( $delta >= 0 ? '+' : '' ) . number_format_i18n( $delta, 2 );
		$delta_label    = $delta_classification( $delta );
		$post_gap       = abs( (int) $post['q2'] - (int) $post['q1'] );
		$pressure_trend = ( $post_gap < $abs_gap )
			? 'Consistency: under pressure stability improved (variance ' . (int) $abs_gap . ' to ' . (int) $post_gap . ').'
			: ( ( $post_gap > $abs_gap )
				? 'Consistency: under pressure stability reduced (variance ' . (int) $abs_gap . ' to ' . (int) $post_gap . ').'
				: 'Consistency: under pressure pattern is similar (variance ' . (int) $abs_gap . ').'
			);

		return array(
			'headline' => $name,
			'bullets'  => array(
				'Change: ' . $delta_label . ' (' . $delta_txt . ').',
				$pressure_trend,
				'Next: ' . $action,
			),
		);
	}

	return array(
		'headline' => $name,
		'bullets'  => array(
			$note,
			'Next: ' . $action,
		),
	);
};

/* ------------------------------------------------------------
 * Tables + participant/project + results
 * ------------------------------------------------------------ */
$participants_table = $wpdb->prefix . 'icon_psy_participants';
$projects_table     = $wpdb->prefix . 'icon_psy_projects';

$results_candidates = array(
	$wpdb->prefix . 'icon_assessment_results',
	$wpdb->prefix . 'icon_psy_results',
	$wpdb->prefix . 'icon_psy_assessment_results',
);
$results_table = $first_existing_table( $results_candidates );
if ( $results_table === '' ) { wp_die( 'Results table not found.' ); }

$results_cols        = $get_cols( $results_table );
$has_completed_at    = in_array( 'completed_at', $results_cols, true );
$has_status_col      = in_array( 'status', $results_cols, true );
$has_created_at      = in_array( 'created_at', $results_cols, true );
$has_detail_json     = in_array( 'detail_json', $results_cols, true );
$has_participant_col = in_array( 'participant_id', $results_cols, true );

if ( ! $has_detail_json || ! $has_participant_col ) {
	wp_die( 'Results schema mismatch (missing participant_id/detail_json).' );
}

$participant = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT p.*, pr.name AS project_name, pr.client_name AS client_name
		 FROM {$participants_table} p
		 LEFT JOIN {$projects_table} pr ON pr.id = p.project_id
		 WHERE p.id = %d
		 LIMIT 1",
		$participant_id
	)
);
if ( ! $participant ) { wp_die( 'Participant not found.' ); }

$participant_name = (string) ( $participant->name ?: 'Participant' );
$participant_role = (string) ( $participant->role ?: '' );
$project_name     = (string) ( $participant->project_name ?: '' );
$client_name      = (string) ( $participant->client_name ?: '' );

/* ------------------------------------------------------------
 * Client Branding (logo + colours)
 * ------------------------------------------------------------ */
$sanitize_hex = function( $hex, $fallback ) {
	$hex = is_string( $hex ) ? trim( $hex ) : '';
	if ( $hex === '' ) return $fallback;
	if ( $hex[0] !== '#' ) $hex = '#' . $hex;
	if ( preg_match( '/^#([a-fA-F0-9]{6})$/', $hex ) ) return strtolower( $hex );
	return $fallback;
};

$hex_to_rgb = function( $hex, $fallback = array( 21, 160, 109 ) ) use ( $sanitize_hex ) {
	$hex = $sanitize_hex( (string) $hex, '#15a06d' );
	$hex = ltrim( $hex, '#' );
	if ( strlen( $hex ) !== 6 ) return $fallback;
	return array(
		hexdec( substr( $hex, 0, 2 ) ),
		hexdec( substr( $hex, 2, 2 ) ),
		hexdec( substr( $hex, 4, 2 ) ),
	);
};

$branding = array(
	'primary'   => '#15a06d',
	'secondary' => '#14a4cf',
	'logo_url'  => '',
);

if ( isset( $icon_pdf_args['branding'] ) && is_array( $icon_pdf_args['branding'] ) ) {

	$bo = $icon_pdf_args['branding'];

	if ( ! empty( $bo['primary'] ) )   $branding['primary']   = $sanitize_hex( (string) $bo['primary'], $branding['primary'] );
	if ( ! empty( $bo['secondary'] ) ) $branding['secondary'] = $sanitize_hex( (string) $bo['secondary'], $branding['secondary'] );

	$logo_candidate = '';
	if ( ! empty( $bo['logo_url'] ) ) $logo_candidate = (string) $bo['logo_url'];
	elseif ( ! empty( $bo['logo'] ) ) $logo_candidate = (string) $bo['logo'];
	elseif ( ! empty( $bo['brand_logo'] ) ) $logo_candidate = (string) $bo['brand_logo'];

	if ( $logo_candidate !== '' ) $branding['logo_url'] = $logo_candidate;

		// lock can be in branding[] OR top-level args
		// BUT: only lock overrides if we were actually given override values (colours/logo).
		// If you only pass brand_name, we still want DB branding to apply.
		$lock_requested = ( ! empty( $bo['lock'] ) || ! empty( $icon_pdf_args['lock'] ) );

		$has_any_brand_overrides =
			( ! empty( $bo['primary'] ) ) ||
			( ! empty( $bo['secondary'] ) ) ||
			( ! empty( $bo['logo_url'] ) ) ||
			( ! empty( $bo['logo'] ) ) ||
			( ! empty( $bo['brand_logo'] ) );

	 $branding_override_lock = ( $lock_requested && $has_any_brand_overrides );

} else {
	$branding_override_lock = ! empty( $icon_pdf_args['lock'] );
}

$projects_cols = $get_cols( $projects_table );
$project_id    = isset( $participant->project_id ) ? (int) $participant->project_id : 0;

if ( ! $branding_override_lock && $project_id > 0 ) {
	$select = array( 'id' );

	if ( in_array( 'client_id', $projects_cols, true ) ) $select[] = 'client_id';
	if ( in_array( 'branding_json', $projects_cols, true ) ) $select[] = 'branding_json';

	foreach ( array( 'primary_color','brand_primary','primary_colour','colour_primary' ) as $c ) {
		if ( in_array( $c, $projects_cols, true ) ) { $select[] = $c; break; }
	}
	foreach ( array( 'secondary_color','brand_secondary','secondary_colour','colour_secondary' ) as $c ) {
		if ( in_array( $c, $projects_cols, true ) ) { $select[] = $c; break; }
	}
	foreach ( array( 'logo_url','brand_logo','brand_logo_url','client_logo','client_logo_url' ) as $c ) {
		if ( in_array( $c, $projects_cols, true ) ) { $select[] = $c; break; }
	}

	$project_row = null;
	if ( count( $select ) > 1 ) {
		$project_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT " . implode( ',', array_map( 'sanitize_key', $select ) ) . " FROM {$projects_table} WHERE id = %d LIMIT 1",
				$project_id
			),
			ARRAY_A
		);
	}

	$client_id = 0;

	if ( is_array( $project_row ) ) {
		if ( ! empty( $project_row['client_id'] ) ) $client_id = (int) $project_row['client_id'];

		if ( ! empty( $project_row['branding_json'] ) ) {
			$bj = $decode_detail_json( (string) $project_row['branding_json'] );
			if ( is_array( $bj ) ) {
				if ( ! empty( $bj['primary'] ) )   $branding['primary']   = $sanitize_hex( (string) $bj['primary'], $branding['primary'] );
				if ( ! empty( $bj['secondary'] ) ) $branding['secondary'] = $sanitize_hex( (string) $bj['secondary'], $branding['secondary'] );
				if ( ! empty( $bj['logo_url'] ) ) {
					$branding['logo_url'] = (string) $bj['logo_url'];
				} elseif ( ! empty( $bj['logo'] ) ) {
					$branding['logo_url'] = (string) $bj['logo'];
				} elseif ( ! empty( $bj['logo_id'] ) ) {
					$branding['logo_url'] = (string) $bj['logo_id'];
				} elseif ( ! empty( $bj['logo_attachment_id'] ) ) {
					$branding['logo_url'] = (string) $bj['logo_attachment_id'];
				}
			}
		}

		foreach ( array( 'primary_color','brand_primary','primary_colour','colour_primary' ) as $c ) {
			if ( isset( $project_row[$c] ) && $project_row[$c] !== '' ) { $branding['primary'] = $sanitize_hex( (string) $project_row[$c], $branding['primary'] ); break; }
		}
		foreach ( array( 'secondary_color','brand_secondary','secondary_colour','colour_secondary' ) as $c ) {
			if ( isset( $project_row[$c] ) && $project_row[$c] !== '' ) { $branding['secondary'] = $sanitize_hex( (string) $project_row[$c], $branding['secondary'] ); break; }
		}
		foreach ( array( 'logo_url','brand_logo','brand_logo_url','client_logo','client_logo_url' ) as $c ) {
			if ( isset( $project_row[$c] ) && $project_row[$c] !== '' ) { $branding['logo_url'] = (string) $project_row[$c]; break; }
		}
	}

	if ( ( $branding['logo_url'] === '' ) && $client_id > 0 ) {

		$clients_candidates = array(
			$wpdb->prefix . 'icon_psy_clients',
			$wpdb->prefix . 'icon_clients',
			$wpdb->prefix . 'icon_psy_companies',
			$wpdb->prefix . 'icon_psy_client_accounts',
		);

		$clients_table = $first_existing_table( $clients_candidates );

		if ( $clients_table ) {
			$ccols = $get_cols( $clients_table );

			$sel = array( 'id' );
			if ( in_array( 'branding_json', $ccols, true ) ) $sel[] = 'branding_json';

			foreach ( array( 'primary_color','brand_primary','primary_colour','colour_primary' ) as $c ) {
				if ( in_array( $c, $ccols, true ) ) { $sel[] = $c; break; }
			}
			foreach ( array( 'secondary_color','brand_secondary','secondary_colour','colour_secondary' ) as $c ) {
				if ( in_array( $c, $ccols, true ) ) { $sel[] = $c; break; }
			}
			foreach ( array( 'logo_url','brand_logo','brand_logo_url','logo','client_logo' ) as $c ) {
				if ( in_array( $c, $ccols, true ) ) { $sel[] = $c; break; }
			}

			$client_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT " . implode( ',', array_map( 'sanitize_key', $sel ) ) . " FROM {$clients_table} WHERE id = %d LIMIT 1",
					$client_id
				),
				ARRAY_A
			);

			if ( is_array( $client_row ) ) {
				if ( ! empty( $client_row['branding_json'] ) ) {
					$bj = $decode_detail_json( (string) $client_row['branding_json'] );
					if ( is_array( $bj ) ) {
						if ( ! empty( $bj['primary'] ) )   $branding['primary']   = $sanitize_hex( (string) $bj['primary'], $branding['primary'] );
						if ( ! empty( $bj['secondary'] ) ) $branding['secondary'] = $sanitize_hex( (string) $bj['secondary'], $branding['secondary'] );
						if ( ! empty( $bj['logo_url'] ) )  $branding['logo_url']  = (string) $bj['logo_url'];
						if ( ! empty( $bj['logo'] ) && $branding['logo_url'] === '' ) $branding['logo_url'] = (string) $bj['logo'];
					}
				}
				foreach ( array( 'primary_color','brand_primary','primary_colour','colour_primary' ) as $c ) {
					if ( isset( $client_row[$c] ) && $client_row[$c] !== '' ) { $branding['primary'] = $sanitize_hex( (string) $client_row[$c], $branding['primary'] ); break; }
				}
				foreach ( array( 'secondary_color','brand_secondary','secondary_colour','colour_secondary' ) as $c ) {
					if ( isset( $client_row[$c] ) && $client_row[$c] !== '' ) { $branding['secondary'] = $sanitize_hex( (string) $client_row[$c], $branding['secondary'] ); break; }
				}
				foreach ( array( 'logo_url','brand_logo','brand_logo_url','logo','client_logo' ) as $c ) {
					if ( isset( $client_row[$c] ) && $client_row[$c] !== '' ) { $branding['logo_url'] = (string) $client_row[$c]; break; }
				}
			}
		}
	}

	if ( $branding['logo_url'] === '' && $client_name !== '' ) {
		$opt = get_option( 'icon_psy_branding_' . sanitize_key( $client_name ) );
		if ( is_array( $opt ) ) {
			if ( ! empty( $opt['primary'] ) )   $branding['primary']   = $sanitize_hex( (string) $opt['primary'], $branding['primary'] );
			if ( ! empty( $opt['secondary'] ) ) $branding['secondary'] = $sanitize_hex( (string) $opt['secondary'], $branding['secondary'] );
			if ( ! empty( $opt['logo_url'] ) )  $branding['logo_url']  = (string) $opt['logo_url'];
		}
	}
}

$brand_primary   = $branding['primary'];
$brand_secondary = $branding['secondary'];
$brand_logo_url  = $branding['logo_url'];

// ------------------------------------------------------------
// Brand label (used in cover, watermark, signature)
// ------------------------------------------------------------
$brand_label = '';
if ( isset( $icon_pdf_args['branding']['brand_name'] ) && $icon_pdf_args['branding']['brand_name'] !== '' ) {
	$brand_label = (string) $icon_pdf_args['branding']['brand_name'];
} elseif ( $client_name !== '' ) {
	$brand_label = (string) $client_name;
} else {
	$brand_label = 'ICON';
}

/* ------------------------------------------------------------
 * Radar renderer (GD -> PNG data URI) — BRAND-AWARE
 * ------------------------------------------------------------ */
$render_radar_png_data_uri = function( $labels, $baseValues, $postValues = null, $brand_primary = '#15a06d', $brand_secondary = '#14a4cf' ) use ( $hex_to_rgb ) {

	if ( ! function_exists( 'imagecreatetruecolor' ) ) return '';

	$labels     = is_array( $labels ) ? array_values( $labels ) : array();
	$baseValues = is_array( $baseValues ) ? array_values( $baseValues ) : array();
	$postValues = is_array( $postValues ) ? array_values( $postValues ) : null;

	$n = count( $labels );
	if ( $n < 3 ) return '';

	$w = 860;
	$h = 860;

	$img = imagecreatetruecolor( $w, $h );

	$white = imagecolorallocate( $img, 255, 255, 255 );
	imagefilledrectangle( $img, 0, 0, $w, $h, $white );

	$ink  = imagecolorallocate( $img, 11, 47, 42 );
	$grid = imagecolorallocatealpha( $img, 148, 163, 184, 92 );

	$rgb1 = $hex_to_rgb( $brand_primary, array( 21, 160, 109 ) );
	$rgb2 = $hex_to_rgb( $brand_secondary, array( 20, 164, 207 ) );

	$green     = imagecolorallocate( $img, (int) $rgb1[0], (int) $rgb1[1], (int) $rgb1[2] );
	$blue      = imagecolorallocate( $img, (int) $rgb2[0], (int) $rgb2[1], (int) $rgb2[2] );
	$greenFill = imagecolorallocatealpha( $img, (int) $rgb1[0], (int) $rgb1[1], (int) $rgb1[2], 92 );
	$blueFill  = imagecolorallocatealpha( $img, (int) $rgb2[0], (int) $rgb2[1], (int) $rgb2[2], 92 );

	$ttf = '';
	if ( function_exists( 'imagettftext' ) ) {
		$candidates = array(
			'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
			'/usr/share/fonts/dejavu/DejaVuSans.ttf',
		);
		foreach ( $candidates as $p ) {
			if ( $p && file_exists( $p ) ) { $ttf = $p; break; }
		}
	}

	$normalize_label = function( $s ) {
		$s = (string) $s;
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$s = mb_convert_encoding( $s, 'UTF-8', 'UTF-8' );
		}
		$map = array(
			"\xC2\xA0"     => ' ',
			"\xE2\x80\x93" => '-',
			"\xE2\x80\x94" => '-',
			"\xE2\x80\x90" => '-',
			"\xE2\x88\x92" => '-',
			"\xE2\x80\x98" => "'",
			"\xE2\x80\x99" => "'",
			"\xE2\x80\x9C" => '"',
			"\xE2\x80\x9D" => '"',
		);
		$s = strtr( $s, $map );
		$s = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( $s );
	};
	foreach ( $labels as $i => $lab ) { $labels[$i] = $normalize_label( $lab ); }

	$cx = (int) round( $w * 0.53 );
	$cy = (int) round( $h * 0.50 );
	$r  = (int) round( min( $w, $h ) * 0.32 );
	$pi = pi();

	for ( $ring = 1; $ring <= 7; $ring++ ) {
		$rr = (int) round( $r * ( $ring / 7 ) );
		imageellipse( $img, $cx, $cy, $rr * 2, $rr * 2, $grid );
	}
	for ( $i = 0; $i < $n; $i++ ) {
		$ang = ( -$pi / 2 ) + ( 2 * $pi ) * ( $i / $n );
		$x   = $cx + (int) round( $r * cos( $ang ) );
		$y   = $cy + (int) round( $r * sin( $ang ) );
		imageline( $img, $cx, $cy, $x, $y, $grid );
	}

	$points = function( $vals ) use ( $cx, $cy, $r, $n, $pi ) {
		$pts = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$v = isset( $vals[$i] ) ? (float) $vals[$i] : 0.0;
			$v = max( 0.0, min( 7.0, $v ) );
			$rr  = $r * ( $v / 7.0 );
			$ang = ( -$pi / 2 ) + ( 2 * $pi ) * ( $i / $n );
			$pts[] = $cx + (int) round( $rr * cos( $ang ) );
			$pts[] = $cy + (int) round( $rr * sin( $ang ) );
		}
		return $pts;
	};

	$basePts = $points( $baseValues );
	imagefilledpolygon( $img, $basePts, $n, $greenFill );
	imagepolygon( $img, $basePts, $n, $green );

	if ( is_array( $postValues ) ) {
		$postPts = $points( $postValues );
		imagefilledpolygon( $img, $postPts, $n, $blueFill );
		imagepolygon( $img, $postPts, $n, $blue );
	}

	if ( $ttf ) @imagettftext( $img, 14, 0, 18, 26, $ink, $ttf, 'Self Radar (0–7 scale)' );
	else imagestring( $img, 5, 18, 10, 'Self Radar (0-7 scale)', $ink );

	imagefilledrectangle( $img, 18, 34, 30, 46, $green );
	if ( $ttf ) @imagettftext( $img, 10, 0, 38, 45, $ink, $ttf, 'Baseline' );
	else imagestring( $img, 3, 38, 35, 'Baseline', $ink );

	if ( is_array( $postValues ) ) {
		imagefilledrectangle( $img, 140, 34, 152, 46, $blue );
		if ( $ttf ) @imagettftext( $img, 10, 0, 160, 45, $ink, $ttf, 'Post' );
		else imagestring( $img, 3, 160, 35, 'Post', $ink );
	}

	$draw_values = function( $vals, $color, $nudge = 10 ) use ( $img, $cx, $cy, $r, $n, $pi, $ttf ) {
		for ( $i = 0; $i < $n; $i++ ) {
			$v = isset( $vals[$i] ) ? (float) $vals[$i] : 0.0;
			$v = max( 0.0, min( 7.0, $v ) );
			$rr  = $r * ( $v / 7.0 );
			$ang = ( -$pi / 2 ) + ( 2 * $pi ) * ( $i / $n );

			$tx = $cx + (int) round( ( $rr + $nudge ) * cos( $ang ) );
			$ty = $cy + (int) round( ( $rr + $nudge ) * sin( $ang ) );

			$txt = number_format( $v, 1 );

			if ( cos( $ang ) < -0.3 ) $tx -= 18;
			if ( cos( $ang ) >  0.3 ) $tx += 2;
			if ( sin( $ang ) >  0.3 ) $ty += 10;
			if ( sin( $ang ) < -0.3 ) $ty -= 2;

			if ( $ttf ) @imagettftext( $img, 10, 0, $tx, $ty, $color, $ttf, $txt );
			else imagestring( $img, 2, $tx, $ty, $txt, $color );
		}
	};
	$draw_values( $baseValues, $green, 6 );
	if ( is_array( $postValues ) ) $draw_values( $postValues, $blue, 12 );

	$edge_pad = 14;

	$text_width = function( $text, $size ) use ( $ttf ) {
		if ( ! $ttf || ! function_exists( 'imagettfbbox' ) ) {
			return max( 10, strlen( $text ) * ( $size * 0.55 ) );
		}
		$box = imagettfbbox( $size, 0, $ttf, $text );
		return abs( $box[2] - $box[0] );
	};

	$draw_clamped = function( $x, $y, $text, $size ) use ( $img, $ink, $ttf, $edge_pad, $w, $text_width ) {
		$tw = $text_width( $text, $size );
		if ( $x < $edge_pad ) $x = $edge_pad;
		if ( $x + $tw > ( $w - $edge_pad ) ) $x = ( $w - $edge_pad ) - $tw;

		if ( $ttf ) @imagettftext( $img, $size, 0, (int) $x, (int) $y, $ink, $ttf, $text );
		else imagestring( $img, 2, (int) $x, (int) ( $y - 10 ), $text, $ink );
	};

	$split_label = function( $raw ) use ( $normalize_label ) {
		$s = $normalize_label( $raw );
		if ( $s === '' ) return array( '' );

		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
		if ( $len <= 18 ) return array( $s );

		$parts = preg_split( '/\s*-\s*|\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) || count( $parts ) <= 1 ) return array( $s );

		$l1 = ''; $l2 = '';
		foreach ( $parts as $p ) {
			$len1 = function_exists( 'mb_strlen' ) ? mb_strlen( $l1, 'UTF-8' ) : strlen( $l1 );
			$len2 = function_exists( 'mb_strlen' ) ? mb_strlen( $l2, 'UTF-8' ) : strlen( $l2 );
			if ( $len1 <= $len2 ) $l1 .= ( $l1 ? ' ' : '' ) . $p;
			else $l2 .= ( $l2 ? ' ' : '' ) . $p;
		}
		$l1 = trim( $l1 ); $l2 = trim( $l2 );

		$max = 22;
		$trim_to = function( $t ) use ( $max ) {
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $t, 'UTF-8' ) > $max ) {
				$t = trim( mb_substr( $t, 0, $max - 3, 'UTF-8' ) ) . '...';
			} elseif ( strlen( $t ) > $max ) {
				$t = trim( substr( $t, 0, $max - 3 ) ) . '...';
			}
			return $t;
		};
		$l1 = $trim_to( $l1 );
		$l2 = $trim_to( $l2 );

		if ( $l2 === '' ) return array( $l1 );
		return array( $l1, $l2 );
	};

	$label_radius = $r + 44;

	for ( $i = 0; $i < $n; $i++ ) {
		$ang = ( -$pi / 2 ) + ( 2 * $pi ) * ( $i / $n );
		$lx  = $cx + (int) round( $label_radius * cos( $ang ) );
		$ly  = $cy + (int) round( $label_radius * sin( $ang ) );

		$lines = $split_label( $labels[$i] ?? '' );

		$is_left  = ( cos( $ang ) < -0.25 );
		$is_right = ( cos( $ang ) > 0.25 );

		$size = 10;
		$dy   = 0;
		if ( sin( $ang ) > 0.35 ) $dy = 10;
		elseif ( sin( $ang ) < -0.35 ) $dy = -4;

		$x1 = $lx + ( $is_right ? 6 : 0 );
		if ( $is_left ) $x1 = $lx - $text_width( $lines[0], $size ) - 6;

		$y1 = $ly + $dy;
		$draw_clamped( $x1, $y1, $lines[0], $size );

		if ( isset( $lines[1] ) && $lines[1] !== '' ) {
			$x2 = $lx + ( $is_right ? 6 : 0 );
			if ( $is_left ) $x2 = $lx - $text_width( $lines[1], $size ) - 6;
			$draw_clamped( $x2, $y1 + 14, $lines[1], $size );
		}
	}

	ob_start();
	imagepng( $img );
	$png = ob_get_clean();
	imagedestroy( $img );

	if ( ! $png ) return '';
	return 'data:image/png;base64,' . base64_encode( $png );
};

/* Load candidate rows */
$where_bits = array();
$where_bits[] = "participant_id = %d";
if ( $has_status_col ) {
	$where_bits[] = "(status IN ('completed','complete','submitted','done')" . ( $has_completed_at ? " OR completed_at IS NOT NULL" : "" ) . ")";
} elseif ( $has_completed_at ) {
	$where_bits[] = "(completed_at IS NOT NULL)";
}

$order_col      = $has_created_at ? 'created_at' : ( $has_completed_at ? 'completed_at' : 'id' );
$sql_candidates = "SELECT * FROM {$results_table} WHERE " . implode( " AND ", $where_bits ) . " ORDER BY {$order_col} DESC LIMIT 200";
$candidate_rows = $wpdb->get_results( $wpdb->prepare( $sql_candidates, $participant_id ) );

$parsed = array();
foreach ( (array) $candidate_rows as $row ) {
	$raw         = isset( $row->detail_json ) ? (string) $row->detail_json : '';
	$decoded_any = $decode_detail_json( $raw );
	if ( ! is_array( $decoded_any ) ) continue;
	if ( ! $schema_ok( $decoded_any ) ) continue;

	$created_val = '';
	if ( isset( $row->created_at ) && $row->created_at ) $created_val = (string) $row->created_at;
	elseif ( isset( $row->completed_at ) && $row->completed_at ) $created_val = (string) $row->completed_at;
	elseif ( isset( $row->id ) ) $created_val = 'id:' . (int) $row->id;

	$parsed[] = array(
		'row'        => $row,
		'payload'    => $decoded_any,
		'phase'      => $get_phase( $decoded_any ),
		'pair_key'   => $get_pair_key( $decoded_any ),
		'created_at' => $created_val,
	);
}
if ( empty( $parsed ) ) { wp_die( 'No usable results found yet.' ); }

/* Choose baseline/post */
$pair_groups = array();
foreach ( $parsed as $p ) {
	$pk = $p['pair_key'];
	if ( $pk === '' ) continue;

	if ( ! isset( $pair_groups[ $pk ] ) ) {
		$pair_groups[ $pk ] = array(
			'latest_ts' => '',
			'baseline'  => null,
			'post'      => null,
		);
	}
	if ( $p['created_at'] && ( $pair_groups[ $pk ]['latest_ts'] === '' || $p['created_at'] > $pair_groups[ $pk ]['latest_ts'] ) ) {
		$pair_groups[ $pk ]['latest_ts'] = $p['created_at'];
	}

	if ( $p['phase'] === 'baseline' && ! $pair_groups[ $pk ]['baseline'] ) $pair_groups[ $pk ]['baseline'] = $p;
	if ( $p['phase'] === 'post' && ! $pair_groups[ $pk ]['post'] ) $pair_groups[ $pk ]['post'] = $p;
}

$use_baseline = null;
$use_post     = null;

if ( ! empty( $pair_groups ) ) {
	$best_pk = '';
	$best_ts = '';
	foreach ( $pair_groups as $pk => $g ) {
		if ( $g['latest_ts'] && ( $best_ts === '' || $g['latest_ts'] > $best_ts ) ) {
			$best_ts = $g['latest_ts'];
			$best_pk = $pk;
		}
	}
	if ( $best_pk !== '' ) {
		$use_baseline = $pair_groups[ $best_pk ]['baseline'];
		$use_post     = $pair_groups[ $best_pk ]['post'];
	}
}
if ( ! $use_baseline && ! $use_post && count( $parsed ) >= 2 ) {
	$use_post     = $parsed[0];
	$use_baseline = $parsed[1];
}

$fallback_latest = $parsed[0];
$is_comparison   = ( $use_baseline && $use_post );

$baseline_row     = $is_comparison ? $use_baseline['row']     : ( $use_baseline ? $use_baseline['row']     : $fallback_latest['row'] );
$baseline_payload = $is_comparison ? $use_baseline['payload']  : ( $use_baseline ? $use_baseline['payload']  : $fallback_latest['payload'] );
$baseline_at      = (string) ( ( isset( $baseline_row->created_at ) ? $baseline_row->created_at : '' ) ?: ( isset( $baseline_row->completed_at ) ? $baseline_row->completed_at : '' ) ?: '' );

$post_row     = $is_comparison ? $use_post['row'] : null;
$post_payload = $is_comparison ? $use_post['payload'] : null;
$post_at      = $is_comparison ? (string) ( ( isset( $post_row->created_at ) ? $post_row->created_at : '' ) ?: ( isset( $post_row->completed_at ) ? $post_row->completed_at : '' ) ?: '' ) : '';

$ai_draft_id = 0;
if ( isset( $baseline_payload['ai_draft_id'] ) ) $ai_draft_id = (int) $baseline_payload['ai_draft_id'];
if ( $ai_draft_id <= 0 && $is_comparison && isset( $post_payload['ai_draft_id'] ) ) $ai_draft_id = (int) $post_payload['ai_draft_id'];

$base_map = $normalise_items_map( $baseline_payload );
if ( empty( $base_map ) ) wp_die( 'Baseline payload missing items.' );

$post_map = $is_comparison ? $normalise_items_map( $post_payload ) : array();

$items = array_values( $base_map );
usort( $items, function( $a, $b ) { return $b['avg'] <=> $a['avg']; } );

if ( $is_comparison ) {
	foreach ( $post_map as $k => $pv ) {
		if ( isset( $base_map[ $k ] ) ) continue;
		$items[] = $pv;
	}
}

$modules = array();
foreach ( $base_map as $v ) {
	$m = (string) $v['module'];
	if ( $m === '' ) $m = 'core';
	if ( ! isset( $modules[$m] ) ) $modules[$m] = array();
	$modules[$m][] = (string) $v['name'];
}
ksort( $modules );

$base_avgs = array();
foreach ( $base_map as $v ) $base_avgs[] = (float) $v['avg'];
$baseline_overall = array_sum( $base_avgs ) / max( 1, count( $base_avgs ) );

$post_overall       = null;
$overall_delta      = null;
$deltas             = array();
$delta_q3           = array();
$stability_improved = 0;
$stability_worsened = 0;

if ( $is_comparison ) {
	$post_avgs = array();
	foreach ( $post_map as $v ) $post_avgs[] = (float) $v['avg'];
	$post_overall  = array_sum( $post_avgs ) / max( 1, count( $post_avgs ) );
	$overall_delta = $post_overall - $baseline_overall;

	foreach ( $base_map as $k => $bv ) {
		if ( empty( $post_map[$k] ) ) continue;
		$pv = $post_map[$k];

		$base_gap = abs( (int) $bv['q2'] - (int) $bv['q1'] );
		$post_gap = abs( (int) $pv['q2'] - (int) $pv['q1'] );

		if ( $post_gap < $base_gap ) $stability_improved++;
		elseif ( $post_gap > $base_gap ) $stability_worsened++;

		$delta_q3[] = (float) $pv['q3'] - (float) $bv['q3'];

		$deltas[] = array(
			'key'   => $k,
			'name'  => $bv['name'],
			'delta' => (float) $pv['avg'] - (float) $bv['avg'],
			'base'  => (float) $bv['avg'],
		);
	}
	usort( $deltas, function( $a, $b ) { return $b['delta'] <=> $a['delta']; } );
}

$top_improve = $is_comparison ? array_slice( $deltas, 0, 5 ) : array();
$top_drop    = $is_comparison ? array_slice( array_reverse( $deltas ), 0, 5 ) : array();

$base_rank = array_values( $base_map );
usort( $base_rank, function( $a, $b ) { return $b['avg'] <=> $a['avg']; } );
$top_strengths     = array_slice( $base_rank, 0, 3 );
$bottom_priorities = array_slice( array_reverse( $base_rank ), 0, 3 );

$radar_items       = array_slice( $base_rank, 0, 12 );
$radar_labels      = array();
$radar_base_values = array();
$radar_post_values = array();

foreach ( $radar_items as $ri ) {
	$k = (string) $ri['key'];
	$radar_labels[]      = (string) $ri['name'];
	$radar_base_values[] = isset( $base_map[$k] ) ? (float) $base_map[$k]['avg'] : (float) $ri['avg'];
	$radar_post_values[] = ( $is_comparison && isset( $post_map[$k] ) ) ? (float) $post_map[$k]['avg'] : null;
}

$trend_quadrants = array(
	'grow_protect'  => array(),
	'strength_risk' => array(),
	'breakthrough'  => array(),
	'priority'      => array(),
);
$trend_base_threshold = 4.6;

if ( $is_comparison && ! empty( $deltas ) ) {
	foreach ( $deltas as $d ) {
		$high = ( (float) $d['base'] >= $trend_base_threshold );
		$up   = ( (float) $d['delta'] >= 0 );
		if ( $high && $up ) $trend_quadrants['grow_protect'][] = $d;
		elseif ( $high && ! $up ) $trend_quadrants['strength_risk'][] = $d;
		elseif ( ! $high && $up ) $trend_quadrants['breakthrough'][] = $d;
		else $trend_quadrants['priority'][] = $d;
	}
}

$key_takeaways = array();
if ( $is_comparison ) {
	$key_takeaways[] = 'Overall average changed by ' . ( (float) $overall_delta >= 0 ? '+' : '' ) . number_format_i18n( (float) $overall_delta, 2 ) . ' (Post minus Baseline).';
	if ( ! empty( $top_improve ) ) $key_takeaways[] = 'Most improved: ' . $top_improve[0]['name'] . ' (' . ( (float) $top_improve[0]['delta'] >= 0 ? '+' : '' ) . number_format_i18n( (float) $top_improve[0]['delta'], 2 ) . ').';
	if ( ! empty( $top_drop ) ) $key_takeaways[] = 'Largest decline: ' . $top_drop[0]['name'] . ' (' . number_format_i18n( (float) $top_drop[0]['delta'], 2 ) . ').';
	$key_takeaways[] = 'Under-pressure stability improved in ' . (int) $stability_improved . ' competencies, reduced in ' . (int) $stability_worsened . '.';
	if ( ! empty( $delta_q3 ) ) {
		$q3_avg_delta = array_sum( $delta_q3 ) / max( 1, count( $delta_q3 ) );
		$key_takeaways[] = 'Quality standard (Q3) average change: ' . ( $q3_avg_delta >= 0 ? '+' : '' ) . number_format_i18n( (float) $q3_avg_delta, 2 ) . '.';
	}
	$key_takeaways[] = 'Use the Trend Map to identify breakthroughs (low baseline, improved) and strengths at risk (high baseline, declined).';
} else {
	$key_takeaways[] = 'This is a single snapshot. Use strengths and priorities to focus where the biggest return is likely.';
	if ( ! empty( $top_strengths ) ) $key_takeaways[] = 'Top strength: ' . $top_strengths[0]['name'] . ' (' . number_format_i18n( (float) $top_strengths[0]['avg'], 2 ) . ' / 7).';
	if ( ! empty( $bottom_priorities ) ) $key_takeaways[] = 'Top priority: ' . $bottom_priorities[0]['name'] . ' (' . number_format_i18n( (float) $bottom_priorities[0]['avg'], 2 ) . ' / 7).';
}

$post_vals = null;
if ( $is_comparison ) {
	$post_vals = array();
	foreach ( $radar_post_values as $v ) $post_vals[] = ( $v === null ? 0.0 : (float) $v );
}
$radar_pdf_uri = $render_radar_png_data_uri(
	$radar_labels,
	array_map( 'floatval', $radar_base_values ),
	$is_comparison ? $post_vals : null,
	$brand_primary,
	$brand_secondary
);

/* ------------------------------------------------------------
 * WOW Assets: SVGs + cover art + logo (CLEAN + DOMPDF SAFE)
 * ------------------------------------------------------------ */
$pdf_generated_on = function_exists( 'wp_date' ) ? wp_date( 'j M Y' ) : date_i18n( 'j M Y' );
$icon_pdf_date    = $pdf_generated_on;

/**
 * Simple brand SVG assets (no remote fetching, Dompdf-safe)
 */
$svg_to_data_uri = function( $svg ) {
	$svg = is_string( $svg ) ? $svg : '';
	if ( $svg === '' ) return '';
	return 'data:image/svg+xml;base64,' . base64_encode( $svg );
};

$pdf_bg_svg_uri  = $svg_to_data_uri(
	'<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1000" viewBox="0 0 1600 1000">' .
	'<defs>' .
	'<linearGradient id="g" x1="0" y1="0" x2="1" y2="1">' .
	'<stop offset="0" stop-color="' . esc_attr( $brand_primary ) . '"/>' .
	'<stop offset="1" stop-color="' . esc_attr( $brand_secondary ) . '"/>' .
	'</linearGradient>' .
	'</defs>' .
	'<rect width="1600" height="1000" fill="#ffffff"/>' .
	'<circle cx="1280" cy="120" r="420" fill="url(#g)" opacity="0.10"/>' .
	'<circle cx="220" cy="860" r="520" fill="url(#g)" opacity="0.08"/>' .
	'<circle cx="840" cy="520" r="680" fill="url(#g)" opacity="0.05"/>' .
	'</svg>'
);

$pdf_bar_svg_uri = $svg_to_data_uri(
	'<svg xmlns="http://www.w3.org/2000/svg" width="1400" height="20" viewBox="0 0 1400 20">' .
	'<defs><linearGradient id="b" x1="0" y1="0" x2="1" y2="0">' .
	'<stop offset="0" stop-color="' . esc_attr( $brand_primary ) . '"/>' .
	'<stop offset="1" stop-color="' . esc_attr( $brand_secondary ) . '"/>' .
	'</linearGradient></defs>' .
	'<rect x="0" y="0" width="1400" height="20" rx="10" fill="url(#b)"/>' .
	'</svg>'
);

/**
 * Convert:
 * - attachment ID (int or numeric string)
 * - local media URL -> attachment -> local file
 * - remote URL (fallback)
 * into a Dompdf-safe data URI.
 */
$logo_value_to_data_uri = function( $logo_value ) {

	// Allow already-embedded data URIs
	if ( is_string( $logo_value ) && strpos( $logo_value, 'data:' ) === 0 ) {
		return trim( $logo_value );
	}

	// 1) Attachment ID (int OR numeric string)
	if ( is_numeric( $logo_value ) && function_exists( 'get_attached_file' ) ) {
		$att_id = absint( $logo_value );
		if ( $att_id > 0 ) {
			$path = get_attached_file( $att_id );
			if ( $path && file_exists( $path ) && is_readable( $path ) ) {

				$ext   = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
				$ctype = 'image/png';
				if ( $ext === 'jpg' || $ext === 'jpeg' ) $ctype = 'image/jpeg';
				elseif ( $ext === 'svg' ) $ctype = 'image/svg+xml';
				elseif ( $ext === 'webp' ) $ctype = 'image/webp';
				elseif ( $ext === 'gif' ) $ctype = 'image/gif';

				$raw = @file_get_contents( $path );
				if ( is_string( $raw ) && $raw !== '' ) {
					return 'data:' . $ctype . ';base64,' . base64_encode( $raw );
				}
			}
		}
	}

	// 2) URL (try to map to local file first)
	$url = is_string( $logo_value ) ? trim( $logo_value ) : '';
	if ( $url === '' ) return '';

	// Fix protocol-relative URLs
	if ( strpos( $url, '//' ) === 0 ) {
		$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
	}

	// If this is a local WordPress media URL, convert URL -> attachment -> file
	if ( function_exists( 'attachment_url_to_postid' ) && function_exists( 'get_attached_file' ) ) {
		$att_id = attachment_url_to_postid( $url );
		if ( $att_id ) {
			$path = get_attached_file( $att_id );
			if ( $path && file_exists( $path ) && is_readable( $path ) ) {
				$ext   = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
				$ctype = 'image/png';
				if ( $ext === 'jpg' || $ext === 'jpeg' ) $ctype = 'image/jpeg';
				elseif ( $ext === 'svg' ) $ctype = 'image/svg+xml';
				elseif ( $ext === 'webp' ) $ctype = 'image/webp';
				elseif ( $ext === 'gif' ) $ctype = 'image/gif';

				$raw = @file_get_contents( $path );
				if ( is_string( $raw ) && $raw !== '' ) {
					return 'data:' . $ctype . ';base64,' . base64_encode( $raw );
				}
			}
		}
	}

	// 3) Remote fetch fallback (last resort)
	if ( function_exists( 'wp_remote_get' ) ) {
		$resp = wp_remote_get( $url, array( 'timeout' => 12 ) );
		if ( ! is_wp_error( $resp ) ) {
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( $code >= 200 && $code < 300 ) {
				$body = wp_remote_retrieve_body( $resp );
				if ( is_string( $body ) && $body !== '' && strlen( $body ) <= 450000 ) {

					$ctype = wp_remote_retrieve_header( $resp, 'content-type' );
					$ctype = is_string( $ctype ) ? $ctype : '';

					if ( $ctype === '' ) {
						$ext = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
						if ( $ext === 'png' ) $ctype = 'image/png';
						elseif ( $ext === 'jpg' || $ext === 'jpeg' ) $ctype = 'image/jpeg';
						elseif ( $ext === 'svg' ) $ctype = 'image/svg+xml';
						elseif ( $ext === 'webp' ) $ctype = 'image/webp';
						elseif ( $ext === 'gif' ) $ctype = 'image/gif';
						else $ctype = 'image/png';
					}

					return 'data:' . $ctype . ';base64,' . base64_encode( $body );
				}
			}
		}
	}

	return '';
};

/**
 * Pick the “best” logo source:
 * - args branding first
 * - then DB-resolved $brand_logo_url
 * - then branding array fields
 */
$pick_logo_value = function() use ( $brand_logo_url, $icon_pdf_args, $branding ) {

	if ( isset( $icon_pdf_args['branding'] ) && is_array( $icon_pdf_args['branding'] ) ) {
		$bo = $icon_pdf_args['branding'];
		foreach ( array( 'logo_url', 'logo', 'brand_logo', 'logo_id', 'logo_attachment_id' ) as $k ) {
			if ( ! empty( $bo[ $k ] ) ) return $bo[ $k ];
		}
	}

	if ( ! empty( $brand_logo_url ) ) return $brand_logo_url;

	foreach ( array( 'logo_url', 'logo', 'brand_logo', 'logo_id', 'logo_attachment_id' ) as $k ) {
		if ( ! empty( $branding[ $k ] ) ) return $branding[ $k ];
	}

	return '';
};

$final_logo_value  = $pick_logo_value();
$pdf_logo_data_uri = $final_logo_value ? $logo_value_to_data_uri( $final_logo_value ) : '';

/**
 * IMPORTANT:
 * No monogram fallback here — if logo missing, we show no logo.
 */
if ( ! $pdf_logo_data_uri ) {
	$pdf_logo_data_uri = '';
}

/* ------------------------------------------------------------
 * PDF UI helpers (Icon style)
 * ------------------------------------------------------------ */
$icon_section_head = function( $num, $title, $subtitle = '' ) {
	$num = (int) $num;
	$title = (string) $title;
	$subtitle = (string) $subtitle;
	?>
	<div class="secHead">
		<div class="secTag">SECTION <?php echo str_pad((string)$num, 2, '0', STR_PAD_LEFT); ?></div>
		<div class="secTitle"><?php echo esc_html($title); ?></div>
		<?php if ( $subtitle !== '' ) : ?>
			<div class="secSub"><?php echo esc_html($subtitle); ?></div>
		<?php endif; ?>
	</div>
	<?php
};

$icon_heat_key = function() {
	?>
	<div class="heatKey">
		<span class="hk hk-low">Low</span>
		<span class="hk hk-dev">Developing</span>
		<span class="hk hk-solid">Solid</span>
		<span class="hk hk-high">High</span>
		<span class="hk hk-note">Scale: 1–7</span>
	</div>
	<?php
};
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
	*{ -webkit-print-color-adjust: exact; print-color-adjust: exact; box-sizing:border-box !important; }
	html, body{ margin:0; padding:0; background:#ffffff; font-family: DejaVu Sans, Arial, sans-serif; color:#0a3b34; }
	img{ max-width:100% !important; height:auto !important; }

	@page { margin: 102px 30px 78px 30px; }
	@page cover { margin: 0; }

	:root{
		--iconGreen: <?php echo esc_html( $brand_primary ); ?>;
		--iconBlue:  <?php echo esc_html( $brand_secondary ); ?>;
		--ink:#0a3b34;
		--muted:#64748b;
	}

	/* Page-break helpers (avoid empty “breaker divs”) */
	.pageBreakBefore{ page-break-before: always; }

	#iconPdfHeader{
		position: fixed; top: -92px; left:0; right:0; height:78px;
		padding: 14px 22px 10px; background:#fff;
		border-bottom:1px solid rgba(10,59,52,0.10);
	}
	#iconPdfHeader .bar{
		position:absolute; top:0; left:0; right:0; height:6px;
		background: var(--iconGreen);
	}
	#iconPdfHeader .logo{
		float:left; width:44px; height:44px; border-radius:12px;
		border:1px solid rgba(10,59,52,0.10); background:#fff; padding:6px; margin-right:12px;
	}
	#iconPdfHeader .logo img{ width:100%; height:100%; display:block; }
	#iconPdfHeader .title{ font-size:14px; font-weight:950; margin:2px 0 2px; }
	#iconPdfHeader .sub{ font-size:10px; color:#64748b; margin:0; }
	#iconPdfHeader .meta{ font-size:10px; color:#475569; margin:4px 0 0; }

	#iconPdfFooter{
		position: fixed; bottom:-62px; left:0; right:0; height:56px;
		padding: 10px 22px; background:#fff;
		border-top:1px solid rgba(10,59,52,0.10);
	}
	#iconPdfFooter .bar{ position:absolute; top:0; left:0; right:0; height:4px; background: var(--iconBlue); }
	#iconPdfFooter .left{ float:left; font-size:9px; color:#475569; }
	#iconPdfFooter .right{ float:right; font-size:9px; color:#475569; }

	#iconPdfWatermark{
		position: fixed; left:-40px; top:330px; transform:rotate(-28deg);
		opacity:.05; font-size:72px; font-weight:950; letter-spacing:.08em; text-transform:uppercase;
	}

	#iconPdfCover{
		page: cover; margin:0 !important; padding:0 !important; position:relative;
		width:100%; height:100%; overflow:hidden; page-break-after:always; background:#fff !important;
	}
	#iconPdfCover .bg{ position:absolute; left:0; top:0; right:0; bottom:0; }
	#iconPdfCover .bg img{ width:100%; height:100%; display:block; }
	#iconPdfCover .overlay{ position:absolute; left:0; top:0; right:0; bottom:0; padding:78px 62px 60px; }

	#iconPdfCover .cover-topbar{
		height:10px; border-radius:999px; overflow:hidden; margin-bottom:28px;
		border:1px solid rgba(10,59,52,0.10); background:#fff;
	}
	#iconPdfCover .cover-logo{
		width:62px; height:62px; border-radius:18px; border:1px solid rgba(10,59,52,0.10);
		background:#fff; padding:10px; margin-bottom:14px;
	}
	#iconPdfCover .eyebrow{
		display:inline-block; font-size:10px; font-weight:950; letter-spacing:.14em; text-transform:uppercase;
		color:#0b2f2a; padding:7px 12px; border-radius:999px;
		border:1px solid var(--iconGreen); background:#ecfdf5; margin-bottom:14px;
	}
	#iconPdfCover h1{ margin:0 0 12px; font-size:44px; line-height:1.02; font-weight:950; }
	#iconPdfCover h1 span{ color: var(--iconBlue); }
	#iconPdfCover .lead{ max-width:820px; font-size:14px; line-height:1.5; color:#425b56; margin:0 0 26px; }
	#iconPdfCover .meta-row{ margin-top:16px; font-size:11px; color:#334155; }
	#iconPdfCover .meta-pill{ display:inline-block; margin:0 8px 8px 0; padding:6px 10px; border-radius:999px; border:1px solid rgba(10,59,52,0.12); background:#fff; }
	#iconPdfCover .signature{
		position:absolute; left:62px; right:62px; bottom:52px; padding-top:16px;
		border-top:1px solid rgba(10,59,52,0.12); font-size:10px; color:#475569;
	}
	#iconPdfCover .cover-card{
		border-radius:22px; background:#fff; border:1px solid rgba(10,59,52,0.10);
		padding:14px; margin-bottom:10px;
	}

	/* Core cards */
	.card{
		position:relative; background:#fff; border-radius:18px; border:1px solid rgba(10,59,52,0.10);
		padding:14px 16px; margin-bottom:12px; overflow:hidden;
		/* IMPORTANT: allow splitting by default (prevents “missing pages” when content grows) */
		page-break-inside: auto; break-inside: auto;
	}
	/* Only use nobreak where you truly need it */
	.nobreak{ page-break-inside: avoid; break-inside: avoid; }

	.card:before{ content:''; position:absolute; left:0; top:0; right:0; height:4px; background: var(--iconGreen); }
	h2{ margin:0 0 6px; font-size:18px; font-weight:950; }
	h3{ margin:0 0 8px; font-size:14px; font-weight:950; color:#0b2f2a; }
	.mini{ font-size:12px; color:#64748b; line-height:1.4; }
	ul, li{ line-height:1.4; }

	.pill-row, .chip-row{ display:block; width:100%; white-space:normal; }
	.chip-row{ text-align:center; }
	.pill, .chip, .chip-muted, .meta-pill{
		display:inline-block; vertical-align:top; line-height:1.25; height:auto;
		max-width:100%; overflow-wrap:anywhere; word-break:break-word;
	}
	.pill{ margin:0 8px 8px 0; padding:7px 11px; border-radius:999px; border:1px solid rgba(20,164,207,.18); background:#fff; font-size:11px; font-weight:950; color:#071b1a; }
	.chip{ margin:0 6px 6px 0; padding:4px 10px; border-radius:999px; border:1px solid rgba(21,160,109,.40); background:#ecfdf5; color:#0a3b34; font-size:11px; font-weight:900; }
	.chip-muted{ margin:0 6px 6px 0; padding:4px 10px; border-radius:999px; border:1px solid rgba(20,164,207,.25); background:#eff6ff; color:#1e3a8a; font-size:11px; font-weight:900; }

	.table{
		width:100%; table-layout:fixed; border-collapse:separate; border-spacing:0;
		border:1px solid rgba(10,59,52,0.10); border-radius:14px; overflow:hidden; font-size:12px;
		page-break-inside:auto !important;
	}
	.table th, .table td{
		padding:10px 8px; border-bottom:1px solid #e5e7eb; text-align:left; vertical-align:top;
		word-wrap:break-word; overflow-wrap:anywhere; white-space:normal;
	}
	.table th{ font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; background:#f9fafb; }
	.table tr{ page-break-inside: avoid !important; break-inside: avoid !important; }

	.secHead{
		margin: 0 0 10px 0;
		padding: 10px 12px 10px;
		border: 1px solid rgba(10,59,52,0.10);
		border-radius: 14px;
		background: #ffffff;
		position: relative;
		overflow: hidden;
	}
	.secHead:before{ content:''; position:absolute; left:0; top:0; right:0; height:4px; background: var(--iconGreen); }
	.secHead:after{
		content:''; position:absolute; right:-120px; top:-120px;
		width:260px; height:260px; border-radius:999px;
		background: rgba(20,164,207,0.08);
	}
	.secTag{
		display:inline-block; font-size: 9px; font-weight: 950; letter-spacing: .14em;
		text-transform: uppercase; color: #0b2f2a; padding: 5px 10px;
		border-radius: 999px; border: 1px solid rgba(21,160,109,.22); background: #ecfdf5;
	}
	.secTitle{ margin-top: 8px; font-size: 15px; font-weight: 950; color: var(--ink); }
	.secSub{ margin-top: 4px; font-size: 11px; color: var(--muted); }

	.card.iconCard{ background:#fff; }
	.card.iconCard:after{
		content:''; position:absolute; left:-120px; bottom:-140px;
		width:320px; height:320px; border-radius:999px;
		background: rgba(21,160,109,0.06);
	}
	.card.iconCard .orb2{
		position:absolute; right:-140px; top:-140px;
		width:360px; height:360px; border-radius:999px;
		background: rgba(20,164,207,0.06);
	}

	.heatKey{ margin: 0 0 10px 0; white-space: normal; }
	.hk{
		display:inline-block; padding: 5px 10px; margin: 0 6px 6px 0;
		border-radius: 999px; border: 1px solid rgba(10,59,52,0.10);
		font-size: 10px; font-weight: 900;
	}
	.hk-low{ background:#fef2f2; border-color:#fecaca; color:#7f1d1d; }
	.hk-dev{ background:#fffbeb; border-color:#fde68a; color:#92400e; }
	.hk-solid{ background:#eff6ff; border-color:#c7d2fe; color:#1e3a8a; }
	.hk-high{ background:#ecfdf5; border-color:#bbf7d0; color:#065f46; }
	.hk-note{ background:#f8fafc; border-color:#e2e8f0; color:#475569; }

	.deltaPill{
		display:inline-block; padding: 4px 8px; border-radius: 999px;
		border: 1px solid rgba(10,59,52,0.10);
		font-weight: 950; font-size: 9px; line-height: 1;
	}
	.deltaPill.up{ background:#ecfdf5; border-color:#bbf7d0; color:#065f46; }
	.deltaPill.down{ background:#fef2f2; border-color:#fecaca; color:#7f1d1d; }
	.deltaPill.flat{ background:#f8fafc; border-color:#e2e8f0; color:#334155; }

	.heatTableWrap{ margin-top:10px; }
	.heatTableTitle{ font-size:12px; font-weight:950; color:#0b2f2a; margin:0 0 8px 0; }
	.heatTableNote{ font-size:11px; color:#64748b; margin:0 0 10px 0; }
	.heatTable{
		width:100%; table-layout:fixed; border-collapse:separate; border-spacing:0;
		border:1px solid rgba(10,59,52,0.10); border-radius:14px; overflow:hidden; font-size:11px;
	}
	.heatTable th, .heatTable td{
		padding:8px 8px; border-bottom:1px solid #e5e7eb; vertical-align:top;
		word-wrap:break-word; overflow-wrap:anywhere; white-space:normal;
	}
	.heatTable th{
		font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; background:#f9fafb;
	}
	.heatTable tr{ page-break-inside:avoid; break-inside:avoid; }
	.heatTable tr:last-child td{ border-bottom:none; }
	.hmName .t{ font-weight:950; color:#0b2f2a; font-size:11px; }
	.hmName .m{ margin-top:2px; font-size:10px; color:#64748b; }
	.hmScore{
		border-radius:10px; border:1px solid rgba(10,59,52,0.10);
		padding:8px 8px; font-weight:950; text-align:center;
	}
	.hmScore small{ display:block; margin-top:2px; font-size:9px; font-weight:900; opacity:.9; }

	.radar-box{
		border:1px solid rgba(10,59,52,0.10); border-radius:14px; padding:8px; background:#fff;
		text-align:center; margin-bottom:8px;
	}
	.radar-box img{ display:block; margin:0 auto; width:520px !important; height:520px !important; }

	.compTable { width:100%; table-layout:fixed; border-collapse:separate; border-spacing:0; }
	.compTable th, .compTable td{ padding:6px 5px !important; font-size:9px !important; line-height:1.15 !important; vertical-align:top !important; }
	.compTable th{ font-size:8px !important; letter-spacing:.05em !important; }
	.compTable .col-name{ width:34% !important; }
	.compTable .col-q{ width:13% !important; }
	.compTable .col-avg{ width:9% !important; }
	.compTable .col-delta{ width:16% !important; }
	.compTable .col-cons{ width:10% !important; }
	.compTable .col-conf{ width:9% !important; }
</style>
</head>

<body>

	<div id="iconPdfHeader">
		<div class="bar"></div>
		<div style="position:relative;">
			<?php if ( $pdf_logo_data_uri ) : ?>
				<div class="logo"><img src="<?php echo esc_attr( $pdf_logo_data_uri ); ?>" alt="Logo"></div>
			<?php endif; ?>
			<div class="title"><?php echo esc_html( $brand_label ); ?> Pre &amp; Post Assessment Report</div>
			<p class="sub">Self Assessment<?php echo $is_comparison ? ' (Baseline vs Post)' : ' (Snapshot)'; ?> · Generated <?php echo esc_html( $pdf_generated_on ); ?></p>
			<div class="meta">
				<?php echo esc_html( $participant_name ); ?>
				<?php if ( $project_name ) : ?> · <?php echo esc_html( $project_name ); ?><?php endif; ?>
				<?php if ( $client_name ) : ?> · <?php echo esc_html( $client_name ); ?><?php endif; ?>
			</div>
		</div>
	</div>

	<div id="iconPdfFooter">
		<div class="bar"></div>
		<div class="left">Confidential · For development use only</div>
		<div class="right">
			<?php echo esc_html( $participant_name ); ?>
			<?php if ( $project_name ) : ?> · <?php echo esc_html( $project_name ); ?><?php endif; ?>
			· <?php echo esc_html( $icon_pdf_date ); ?>
		</div>
	</div>

	<div id="iconPdfWatermark"><?php echo esc_html( $brand_label ); ?></div> 

	<!-- Cover -->
	<div id="iconPdfCover">
		<div class="bg"><?php if ( $pdf_bg_svg_uri ) : ?><img src="<?php echo esc_attr( $pdf_bg_svg_uri ); ?>" alt=""><?php endif; ?></div>
		<div class="overlay">

			<div class="cover-topbar"><?php if ( $pdf_bar_svg_uri ) : ?><img src="<?php echo esc_attr( $pdf_bar_svg_uri ); ?>" alt=""><?php endif; ?></div>

			<?php if ( $pdf_logo_data_uri ) : ?>
				<div class="cover-logo"><img src="<?php echo esc_attr( $pdf_logo_data_uri ); ?>" alt="Logo"></div>
			<?php endif; ?>
			<span class="eyebrow"><?php echo esc_html( $brand_label ); ?> Insight Report</span>

			<h1>Pre &amp; Post<br><span>Self Assessment</span> Report</h1>

			<p class="lead">
				This report translates the participant’s survey responses into clear, practical insight.
				<?php if ( $is_comparison ) : ?>
					It compares Baseline and Post submissions to show movement, stability, and priorities.
				<?php else : ?>
					It presents the most recent submission snapshot and the strongest focus areas.
				<?php endif; ?>
			</p>

			<div class="meta-row">
				<span class="meta-pill"><strong>Participant:</strong> <?php echo esc_html( $participant_name ); ?></span>
				<?php if ( $participant_role ) : ?><span class="meta-pill"><strong>Role:</strong> <?php echo esc_html( $participant_role ); ?></span><?php endif; ?>
				<?php if ( $project_name ) : ?><span class="meta-pill"><strong>Project:</strong> <?php echo esc_html( $project_name ); ?></span><?php endif; ?>
				<?php if ( $client_name ) : ?><span class="meta-pill"><strong>Client:</strong> <?php echo esc_html( $client_name ); ?></span><?php endif; ?>

				<?php if ( $is_comparison ) : ?>
					<?php if ( $baseline_at ) : ?><span class="meta-pill"><strong>Baseline:</strong> <?php echo esc_html( $baseline_at ); ?></span><?php endif; ?>
					<?php if ( $post_at ) : ?><span class="meta-pill"><strong>Post:</strong> <?php echo esc_html( $post_at ); ?></span><?php endif; ?>
				<?php else : ?>
					<?php if ( $baseline_at ) : ?><span class="meta-pill"><strong>Submitted:</strong> <?php echo esc_html( $baseline_at ); ?></span><?php endif; ?>
				<?php endif; ?>

				<span class="meta-pill"><strong>Generated:</strong> <?php echo esc_html( $pdf_generated_on ); ?></span>
			</div>

			<div style="margin-top:24px;">
				<div class="cover-card nobreak">
					<h3 style="margin:0 0 6px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;">What this report gives you</h3>
					<p style="margin:0;font-size:11px;line-height:1.45;color:#475569;">
						A clear snapshot of strengths, priorities, and patterns across contexts (day-to-day, under pressure, quality standard), with practical next steps you can apply immediately.
					</p>
				</div>
				<div class="cover-card nobreak">
					<h3 style="margin:0 0 6px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;">How to use it</h3>
					<p style="margin:0;font-size:11px;line-height:1.45;color:#475569;">
						Start with Quick Scan and Key Takeaways, then use Radar and Heatmaps to pinpoint focus areas. For comparisons, use Trend Map and Delta views to target meaningful change.
					</p>
				</div>
				<div class="cover-card nobreak">
					<h3 style="margin:0 0 6px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;">Interpretation note</h3>
					<p style="margin:0;font-size:11px;line-height:1.45;color:#475569;">
						This is a development tool. Treat results as a prompt for reflection and action planning. Consistency uses variance |Q2 - Q1| to highlight stability under pressure.
					</p>
				</div>
				<div class="cover-card nobreak">
					<h3 style="margin:0 0 6px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;">Confidentiality</h3>
					<p style="margin:0;font-size:11px;line-height:1.45;color:#475569;">
						Confidential to the participant and authorised stakeholders. Do not distribute externally without permission.
					</p>
				</div>
			</div>

			<div class="signature">
				<div style="float:left;"><strong><?php echo esc_html( $brand_label ); ?></strong> · Insight that turns into action</div>
				<div style="float:right;">Document ID: <?php echo (int) $participant_id; ?><?php echo $ai_draft_id > 0 ? ' · Framework draft ' . (int) $ai_draft_id : ''; ?></div>
				<div style="clear:both;"></div>
			</div>

		</div>
	</div>

	<!-- Section 1 -->
	<div class="card iconCard" id="sec1">
		<div class="orb2"></div>
		<?php $icon_section_head(1, 'Quick Scan', $is_comparison ? 'Baseline vs Post summary tiles and focus areas.' : 'Snapshot summary tiles and focus areas.'); ?>

		<h2 style="text-align:center;margin:0 0 6px 0;">
			<?php echo esc_html( $brand_label ); ?> Pre &amp; Post Assessment Report
		</h2>
		<p class="mini" style="text-align:center;margin-top:0;margin-bottom:10px;">
			<?php if ( $is_comparison ) : ?>
				Self Assessment comparison: <strong>Baseline</strong> vs <strong>Post</strong>.
			<?php else : ?>
				Self Assessment snapshot based on the most recent submission.
			<?php endif; ?>
		</p>

		<div class="chip-row">
			<span class="chip">Participant: <?php echo esc_html( $participant_name ); ?></span>
			<?php if ( $participant_role ) : ?><span class="chip-muted">Role: <?php echo esc_html( $participant_role ); ?></span><?php endif; ?>
			<?php if ( $project_name ) : ?><span class="chip">Project: <?php echo esc_html( $project_name ); ?></span><?php endif; ?>
			<?php if ( $client_name ) : ?><span class="chip-muted">Client: <?php echo esc_html( $client_name ); ?></span><?php endif; ?>
			<?php if ( $is_comparison ) : ?>
				<?php if ( $baseline_at ) : ?><span class="chip-muted">Baseline submitted: <?php echo esc_html( $baseline_at ); ?></span><?php endif; ?>
				<?php if ( $post_at ) : ?><span class="chip-muted">Post submitted: <?php echo esc_html( $post_at ); ?></span><?php endif; ?>
			<?php else : ?>
				<?php if ( $baseline_at ) : ?><span class="chip-muted">Submitted: <?php echo esc_html( $baseline_at ); ?></span><?php endif; ?>
			<?php endif; ?>
			<?php if ( $ai_draft_id > 0 ) : ?><span class="chip-muted">Framework draft ID: <?php echo (int) $ai_draft_id; ?></span><?php endif; ?>
		</div>

		<div class="pill-row" style="margin-top:10px;">
			<?php $b_base = $band( $baseline_overall ); ?>
			<span class="pill" style="border-color:<?php echo esc_attr( $b_base[3] ); ?>;background:<?php echo esc_attr( $b_base[2] ); ?>;color:<?php echo esc_attr( $b_base[1] ); ?>;">
				Baseline avg: <?php echo number_format_i18n( $baseline_overall, 2 ); ?> / 7 (<?php echo esc_html( $b_base[0] ); ?>)
			</span>

			<?php if ( $is_comparison ) : ?>
				<?php $b_post = $band( (float) $post_overall ); ?>
				<span class="pill" style="border-color:<?php echo esc_attr( $b_post[3] ); ?>;background:<?php echo esc_attr( $b_post[2] ); ?>;color:<?php echo esc_attr( $b_post[1] ); ?>;">
					Post avg: <?php echo number_format_i18n( (float) $post_overall, 2 ); ?> / 7 (<?php echo esc_html( $b_post[0] ); ?>)
				</span>

				<?php
				$delta_overall     = (float) $overall_delta;
				$delta_overall_txt = ( $delta_overall >= 0 ? '+' : '' ) . number_format_i18n( $delta_overall, 2 );
				$delta_overall_col = $delta_overall >= 0 ? '#065f46' : '#7f1d1d';
				?>
				<span class="pill">Overall delta: <strong style="color:<?php echo esc_attr( $delta_overall_col ); ?>;"><?php echo esc_html( $delta_overall_txt ); ?></strong></span>
				<span class="pill">Stability improved: <?php echo (int) $stability_improved; ?> · Reduced: <?php echo (int) $stability_worsened; ?></span>
			<?php endif; ?>

			<span class="pill">Competencies: <?php echo (int) count( $base_map ); ?></span>
		</div>

		<div class="mini" style="margin-top:8px;">
			<strong>Delta explained:</strong> Delta is calculated as <strong>Post minus Baseline</strong>. Positive delta = improvement, negative delta = decline, near zero = stable.
		</div>

		<div style="margin-top:10px;">
			<div style="width:49%; float:left;">
				<div class="card nobreak" style="margin-bottom:0;">
					<h3>Top strengths</h3>
					<div class="mini">Highest baseline averages.</div>
					<?php foreach ( $top_strengths as $s ) : ?>
						<span class="pill" style="margin-top:8px;"><?php echo esc_html( $s['name'] ); ?> · <strong><?php echo number_format_i18n( (float) $s['avg'], 2 ); ?></strong></span>
					<?php endforeach; ?>
				</div>
			</div>
			<div style="width:49%; float:right;">
				<div class="card nobreak" style="margin-bottom:0;">
					<h3>Top priorities</h3>
					<div class="mini">Lowest baseline averages.</div>
					<?php foreach ( $bottom_priorities as $p ) : ?>
						<span class="pill" style="margin-top:8px;"><?php echo esc_html( $p['name'] ); ?> · <strong><?php echo number_format_i18n( (float) $p['avg'], 2 ); ?></strong></span>
					<?php endforeach; ?>
				</div>
			</div>
			<div style="clear:both;"></div>
		</div>

		<?php if ( $is_comparison ) : ?>
			<div style="margin-top:12px;">
				<div style="width:49%; float:left;">
					<div class="card nobreak" style="margin-bottom:0;">
						<h3>Most improved</h3>
						<div class="mini">Largest positive deltas.</div>
						<?php foreach ( array_slice( $top_improve, 0, 3 ) as $d ) : ?>
							<span class="pill" style="margin-top:8px;border-color:#bbf7d0;background:#ecfdf5;color:#065f46;">
								<?php echo esc_html( $d['name'] ); ?> · <strong>+<?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong>
							</span>
						<?php endforeach; ?>
					</div>
				</div>
				<div style="width:49%; float:right;">
					<div class="card nobreak" style="margin-bottom:0;">
						<h3>Largest declines</h3>
						<div class="mini">Largest negative deltas.</div>
						<?php foreach ( array_slice( $top_drop, 0, 3 ) as $d ) : ?>
							<span class="pill" style="margin-top:8px;border-color:#fecaca;background:#fef2f2;color:#7f1d1d;">
								<?php echo esc_html( $d['name'] ); ?> · <strong><?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong>
							</span>
						<?php endforeach; ?>
					</div>
				</div>
				<div style="clear:both;"></div>
			</div>
		<?php endif; ?>
	</div>

	<!-- Section 2 -->
	<div class="card iconCard" id="sec2">
		<div class="orb2"></div>
		<?php $icon_section_head(2, 'Key Takeaways', 'The strongest signals from your results.'); ?>
		<ul style="margin:0; padding-left:18px; font-size:12px; color:#425b56;">
			<?php foreach ( (array) $key_takeaways as $kt ) : ?>
				<li style="margin:4px 0;"><?php echo esc_html( $kt ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>

	<!-- Section 3 (page break applied to the section card itself) -->
	<div class="card iconCard pageBreakBefore" id="sec3">
		<div class="orb2"></div>
		<?php $icon_section_head(3, 'Self Radar', 'Baseline vs Post — values shown at plotted points (0–7).'); ?>

		<div class="mini" style="margin-bottom:10px;">
			PDF view renders the radar as an image for reliability. Competency names label the spokes; score values are shown at each plotted point.
		</div>

		<div class="radar-pack">
			<?php if ( $radar_pdf_uri ) : ?>
				<div class="radar-box nobreak">
					<img src="<?php echo esc_attr( $radar_pdf_uri ); ?>" alt="Radar">
					<div class="mini" style="margin-top:6px;">
						Baseline vs Post — values shown at each plotted point (0–7). Labels wrap automatically for readability.
					</div>
				</div>
			<?php else : ?>
				<div class="mini" style="margin-bottom:10px;">Radar image could not be generated (GD missing).</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- Radar competencies (new page) -->
	<div class="card iconCard pageBreakBefore">
		<div class="orb2"></div>
		<?php $icon_section_head(3, 'Radar competencies', 'Top competencies used in the radar, with Baseline/Post and Delta.'); ?>

		<div class="radar-table" style="margin-top:0;">
			<table class="table">
				<thead>
					<tr>
						<th style="width:40px;">#</th>
						<th>Competency</th>
						<th>Baseline avg</th>
						<?php if ( $is_comparison ) : ?>
							<th>Post avg</th>
							<th>Delta</th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $radar_items as $idx => $ri ) : ?>
						<?php
						$k      = (string) $ri['key'];
						$bavg   = isset( $base_map[$k] ) ? (float) $base_map[$k]['avg'] : (float) $ri['avg'];
						$pavg   = ( $is_comparison && isset( $post_map[$k] ) ) ? (float) $post_map[$k]['avg'] : null;
						$dd     = ( $pavg !== null ) ? ( $pavg - $bavg ) : null;
						$dd_txt = ( $dd !== null ) ? ( ( $dd >= 0 ? '+' : '' ) . number_format_i18n( (float) $dd, 2 ) ) : '—';
						$dd_col = ( $dd !== null ) ? ( $dd >= 0 ? '#065f46' : '#7f1d1d' ) : '#64748b';
						?>
						<tr>
							<td style="font-weight:950;"><?php echo (int) ( $idx + 1 ); ?></td>
							<td><?php echo esc_html( (string) $ri['name'] ); ?></td>
							<td><?php echo number_format_i18n( (float) $bavg, 2 ); ?></td>
							<?php if ( $is_comparison ) : ?>
								<td><?php echo $pavg !== null ? number_format_i18n( (float) $pavg, 2 ) : '—'; ?></td>
								<td><span style="color:<?php echo esc_attr( $dd_col ); ?>; font-weight:950;"><?php echo esc_html( $dd_txt ); ?></span></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<?php if ( $is_comparison ) : ?>
		<div class="card iconCard pageBreakBefore" id="sec4">
			<div class="orb2"></div>
			<?php $icon_section_head(4, 'Trend Map', 'Baseline strength vs change (Post minus Baseline).'); ?>
			<div class="mini" style="margin-bottom:10px;">
				High/low baseline split uses <?php echo number_format_i18n( (float) $trend_base_threshold, 1 ); ?>/7.
			</div>

			<div style="width:49%; float:left;">
				<div class="card nobreak" style="margin-bottom:12px;">
					<h3>Grow &amp; protect</h3>
					<div class="mini">High baseline and improving.</div>
					<?php foreach ( array_slice( $trend_quadrants['grow_protect'], 0, 10 ) as $d ) : ?>
						<span class="pill" style="margin-top:8px;border-color:#bbf7d0;background:#ecfdf5;color:#065f46;">
							<?php echo esc_html( $d['name'] ); ?> · <strong>+<?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong>
						</span>
					<?php endforeach; ?>
				</div>

				<div class="card nobreak" style="margin-bottom:0;">
					<h3>Breakthrough</h3>
					<div class="mini">Lower baseline and improving.</div>
					<?php foreach ( array_slice( $trend_quadrants['breakthrough'], 0, 10 ) as $d ) : ?>
						<span class="pill" style="margin-top:8px;border-color:#bbf7d0;background:#ecfdf5;color:#065f46;">
							<?php echo esc_html( $d['name'] ); ?> · <strong>+<?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong>
						</span>
					<?php endforeach; ?>
				</div>
			</div>

			<div style="width:49%; float:right;">
				<div class="card nobreak" style="margin-bottom:12px;">
					<h3>Strength at risk</h3>
					<div class="mini">High baseline but declining.</div>
					<?php foreach ( array_slice( $trend_quadrants['strength_risk'], 0, 10 ) as $d ) : ?>
						<span class="pill" style="margin-top:8px;border-color:#fecaca;background:#fef2f2;color:#7f1d1d;">
							<?php echo esc_html( $d['name'] ); ?> · <strong><?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong>
						</span>
					<?php endforeach; ?>
				</div>

				<div class="card nobreak" style="margin-bottom:0;">
					<h3>Priority focus</h3>
					<div class="mini">Lower baseline and declining.</div>
					<?php foreach ( array_slice( $trend_quadrants['priority'], 0, 10 ) as $d ) : ?>
						<span class="pill" style="margin-top:8px;border-color:#fecaca;background:#fef2f2;color:#7f1d1d;">
							<?php echo esc_html( $d['name'] ); ?> · <strong><?php echo number_format_i18n( (float) $d['delta'], 2 ); ?></strong>
						</span>
					<?php endforeach; ?>
				</div>
			</div>

			<div style="clear:both;"></div>
		</div>
	<?php endif; ?>

	<!-- Heatmaps (new page) -->
	<div class="card iconCard pageBreakBefore" id="sec5">
		<div class="orb2"></div>
		<?php $icon_section_head(5, 'Heatmaps', 'Baseline, Post, and Delta across Q1/Q2/Q3.'); ?>
		<?php $icon_heat_key(); ?>

		<?php if ( $is_comparison ) : ?>

			<p class="mini" style="margin-bottom:10px;">
				Baseline and Post show Q1 (day to day), Q2 (under pressure), and Q3 (quality standard). Delta is Post minus Baseline.
			</p>

			<div class="heatTableWrap">
				<div class="heatTableTitle">Baseline heatmap</div>
				<table class="heatTable">
					<thead>
						<tr>
							<th style="width:52%;">Competency</th>
							<th style="width:16%;">Q1</th>
							<th style="width:16%;">Q2</th>
							<th style="width:16%;">Q3</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $t ) : ?>
							<?php
							$key = $t['key'];
							if ( empty( $base_map[ $key ] ) ) continue;
							$b  = $base_map[ $key ];
							$c1 = $score_color( $b['q1'] );
							$c2 = $score_color( $b['q2'] );
							$c3 = $score_color( $b['q3'] );
							?>
							<tr>
								<td class="hmName">
									<div class="t"><?php echo esc_html( $b['name'] ); ?></div>
									<div class="m">Module: <?php echo esc_html( $b['module'] ); ?> · Conf: <?php echo (int) $b['confidence']; ?>/5</div>
								</td>
								<td><div class="hmScore" style="background:<?php echo esc_attr($c1[0]); ?>;color:<?php echo esc_attr($c1[1]); ?>;"><?php echo (int) $b['q1']; ?><small>Day</small></div></td>
								<td><div class="hmScore" style="background:<?php echo esc_attr($c2[0]); ?>;color:<?php echo esc_attr($c2[1]); ?>;"><?php echo (int) $b['q2']; ?><small>Pressure</small></div></td>
								<td><div class="hmScore" style="background:<?php echo esc_attr($c3[0]); ?>;color:<?php echo esc_attr($c3[1]); ?>;"><?php echo (int) $b['q3']; ?><small>Quality</small></div></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

		<?php else : ?>

			<p class="mini" style="margin-bottom:10px;">
				This heatmap shows Q1 (day to day), Q2 (under pressure), and Q3 (quality standard).
			</p>

			<div class="heatTableWrap">
				<div class="heatTableTitle">Snapshot heatmap</div>
				<table class="heatTable">
					<thead>
						<tr>
							<th style="width:52%;">Competency</th>
							<th style="width:16%;">Q1</th>
							<th style="width:16%;">Q2</th>
							<th style="width:16%;">Q3</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $t ) : ?>
							<?php
							$key = $t['key'];
							if ( empty( $base_map[ $key ] ) ) continue;
							$b  = $base_map[ $key ];
							$c1 = $score_color( $b['q1'] );
							$c2 = $score_color( $b['q2'] );
							$c3 = $score_color( $b['q3'] );
							?>
							<tr>
								<td class="hmName">
									<div class="t"><?php echo esc_html( $b['name'] ); ?></div>
									<div class="m">Module: <?php echo esc_html( $b['module'] ); ?> · Conf: <?php echo (int) $b['confidence']; ?>/5</div>
								</td>
								<td><div class="hmScore" style="background:<?php echo esc_attr($c1[0]); ?>;color:<?php echo esc_attr($c1[1]); ?>;"><?php echo (int) $b['q1']; ?><small>Day</small></div></td>
								<td><div class="hmScore" style="background:<?php echo esc_attr($c2[0]); ?>;color:<?php echo esc_attr($c2[1]); ?>;"><?php echo (int) $b['q2']; ?><small>Pressure</small></div></td>
								<td><div class="hmScore" style="background:<?php echo esc_attr($c3[0]); ?>;color:<?php echo esc_attr($c3[1]); ?>;"><?php echo (int) $b['q3']; ?><small>Quality</small></div></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

		<?php endif; ?>
	</div>

	<?php if ( $is_comparison ) : ?>
		<!-- Post heatmap (new page) -->
		<div class="card iconCard pageBreakBefore">
			<div class="orb2"></div>
			<?php $icon_section_head(5, 'Heatmaps', 'Post heatmap'); ?>
			<?php $icon_heat_key(); ?>

			<div class="heatTableWrap" style="margin-top:0;">
				<div class="heatTableTitle">Post heatmap</div>
				<table class="heatTable">
					<thead>
						<tr>
							<th style="width:52%;">Competency</th>
							<th style="width:16%;">Q1</th>
							<th style="width:16%;">Q2</th>
							<th style="width:16%;">Q3</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $t ) : ?>
							<?php
							$key = $t['key'];
							if ( empty( $post_map[ $key ] ) ) continue;
							$p  = $post_map[ $key ];
							$c1 = $score_color( $p['q1'] );
							$c2 = $score_color( $p['q2'] );
							$c3 = $score_color( $p['q3'] );
							?>
							<tr>
								<td class="hmName">
									<div class="t"><?php echo esc_html( $p['name'] ); ?></div>
									<div class="m">Module: <?php echo esc_html( $p['module'] ); ?> · Conf: <?php echo (int) $p['confidence']; ?>/5</div>
								</td>
								<td><div class="hmScore" style="background:<?php echo esc_attr($c1[0]); ?>;color:<?php echo esc_attr($c1[1]); ?>;"><?php echo (int) $p['q1']; ?><small>Day</small></div></td>
								<td><div class="hmScore" style="background:<?php echo esc_attr($c2[0]); ?>;color:<?php echo esc_attr($c2[1]); ?>;"><?php echo (int) $p['q2']; ?><small>Pressure</small></div></td>
								<td><div class="hmScore" style="background:<?php echo esc_attr($c3[0]); ?>;color:<?php echo esc_attr($c3[1]); ?>;"><?php echo (int) $p['q3']; ?><small>Quality</small></div></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<!-- Delta heatmap (new page) -->
		<div class="card iconCard pageBreakBefore">
			<div class="orb2"></div>
			<?php $icon_section_head(5, 'Heatmaps', 'Delta (Post minus Baseline)'); ?>
			<?php $icon_heat_key(); ?>

			<div class="heatTableWrap" style="margin-top:0;">
				<div class="heatTableTitle">Delta heatmap (Post minus Baseline)</div>
				<p class="heatTableNote">Positive = improvement, negative = decline.</p>

				<table class="heatTable">
					<thead>
						<tr>
							<th style="width:52%;">Competency</th>
							<th style="width:16%;">Δ Q1</th>
							<th style="width:16%;">Δ Q2</th>
							<th style="width:16%;">Δ Q3</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $t ) : ?>
							<?php
							$key = $t['key'];
							if ( empty( $base_map[ $key ] ) || empty( $post_map[ $key ] ) ) continue;
							$b = $base_map[ $key ];
							$p = $post_map[ $key ];

							$d1 = (float) $p['q1'] - (float) $b['q1'];
							$d2 = (float) $p['q2'] - (float) $b['q2'];
							$d3 = (float) $p['q3'] - (float) $b['q3'];

							$dc1 = $delta_color( $d1 );
							$dc2 = $delta_color( $d2 );
							$dc3 = $delta_color( $d3 );

							$txt1 = ( $d1 >= 0 ? '+' : '' ) . number_format_i18n( (float) $d1, 0 );
							$txt2 = ( $d2 >= 0 ? '+' : '' ) . number_format_i18n( (float) $d2, 0 );
							$txt3 = ( $d3 >= 0 ? '+' : '' ) . number_format_i18n( (float) $d3, 0 );
							?>
							<tr>
								<td class="hmName">
									<div class="t"><?php echo esc_html( $b['name'] ); ?></div>
									<div class="m">Module: <?php echo esc_html( $b['module'] ); ?></div>
								</td>
								<td><div class="hmScore" style="background:<?php echo esc_attr($dc1[0]); ?>;color:<?php echo esc_attr($dc1[1]); ?>;"><?php echo esc_html($txt1); ?><small>Δ Day</small></div></td>
								<td><div class="hmScore" style="background:<?php echo esc_attr($dc2[0]); ?>;color:<?php echo esc_attr($dc2[1]); ?>;"><?php echo esc_html($txt2); ?><small>Δ Pressure</small></div></td>
								<td><div class="hmScore" style="background:<?php echo esc_attr($dc3[0]); ?>;color:<?php echo esc_attr($dc3[1]); ?>;"><?php echo esc_html($txt3); ?><small>Δ Quality</small></div></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>

	<!-- Section 6 (new page) -->
	<div class="card iconCard pageBreakBefore" id="sec6">
		<div class="orb2"></div>
		<?php $icon_section_head(6, $is_comparison ? 'Comparison table and trends' : 'Competency results table', 'Compact view for quick scanning.'); ?>

		<table class="table compTable">
			<thead>
				<?php if ( $is_comparison ) : ?>
				<tr>
					<th class="col-name">Competency</th>
					<th class="col-q">Baseline<br>Q1/Q2/Q3</th>
					<th class="col-q">Post<br>Q1/Q2/Q3</th>
					<th class="col-avg">Base<br>Avg</th>
					<th class="col-avg">Post<br>Avg</th>
					<th class="col-delta">Delta</th>
					<th class="col-cons">Cons.</th>
					<th class="col-conf">Conf.</th>
				</tr>
				<?php else : ?>
				<tr>
					<th class="col-name">Competency</th>
					<th class="col-q">Q1</th>
					<th class="col-q">Q2</th>
					<th class="col-q">Q3</th>
					<th class="col-avg">Avg</th>
					<th class="col-cons">Cons.</th>
					<th class="col-conf">Conf.</th>
				</tr>
				<?php endif; ?>
			</thead>
			<tbody>
				<?php foreach ( $items as $t ) : ?>
					<?php
					$key = $t['key'];
					if ( empty( $base_map[ $key ] ) ) continue;

					$base      = $base_map[ $key ];
					$avg_base  = (float) $base['avg'];
					$base_gap  = abs( (int) $base['q2'] - (int) $base['q1'] );
					$base_stab = $stability_label( $base_gap );

					$post     = ( $is_comparison && ! empty( $post_map[ $key ] ) ) ? $post_map[ $key ] : null;
					$avg_post = $post ? (float) $post['avg'] : null;

					$delta_txt = '—';
					$delta_cls = '';
					if ( $is_comparison && $post ) {
						$delta = $avg_post - $avg_base;
						$symbol = '•';
						if ( $delta >= 0.15 ) $symbol = '▲';
						elseif ( $delta <= -0.15 ) $symbol = '▼';

						$delta_txt = $symbol . ' ' . ( $delta >= 0 ? '+' : '' ) . number_format_i18n( $delta, 2 );
						$delta_cls = $delta >= 0 ? 'delta-up' : 'delta-down';
					}
					$dp = 'flat';
					if ( $delta_cls === 'delta-up' ) $dp = 'up';
					elseif ( $delta_cls === 'delta-down' ) $dp = 'down';
					?>
					<tr>
						<td>
							<div style="font-weight:950;color:#0b2f2a;"><?php echo esc_html( $base['name'] ); ?></div>
							<div class="mini"><?php echo esc_html( $base['module'] ); ?></div>
						</td>

						<?php if ( $is_comparison ) : ?>
							<td><?php echo (int) $base['q1']; ?> / <?php echo (int) $base['q2']; ?> / <?php echo (int) $base['q3']; ?></td>
							<td><?php echo $post ? ( (int) $post['q1'] . ' / ' . (int) $post['q2'] . ' / ' . (int) $post['q3'] ) : '—'; ?></td>
							<td><?php echo number_format_i18n( $avg_base, 2 ); ?></td>
							<td><?php echo $post ? number_format_i18n( $avg_post, 2 ) : '—'; ?></td>
							<td><span class="deltaPill <?php echo esc_attr($dp); ?>"><?php echo esc_html( $delta_txt ); ?></span></td>
							<td><?php echo esc_html( $base_stab[0] ); ?> (<?php echo (int) $base_gap; ?>)</td>
							<td><?php echo (int) $base['confidence']; ?>/5<?php echo $post ? ' to ' . (int) $post['confidence'] . '/5' : ''; ?></td>
						<?php else : ?>
							<td><?php echo (int) $base['q1']; ?></td>
							<td><?php echo (int) $base['q2']; ?></td>
							<td><?php echo (int) $base['q3']; ?></td>
							<td><?php echo number_format_i18n( $avg_base, 2 ); ?></td>
							<td><?php echo esc_html( $base_stab[0] ); ?> (<?php echo (int) $base_gap; ?>)</td>
							<td><?php echo (int) $base['confidence']; ?>/5</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<!-- Section 7 -->
	<div class="card iconCard" id="sec7">
		<div class="orb2"></div>
		<?php $icon_section_head(7, 'Competency by competency summary', $is_comparison ? 'What changed, plus a consistency signal.' : 'Pattern across contexts and a practical next step.'); ?>

		<?php foreach ( $items as $t ) : ?>
			<?php
			$key = $t['key'];
			if ( empty( $base_map[ $key ] ) ) continue;

			$base    = $base_map[ $key ];
			$post    = ( $is_comparison && ! empty( $post_map[ $key ] ) ) ? $post_map[ $key ] : null;
			$summary = $make_summary( $base, $post );
			$b       = $band( $base['avg'] );
			?>
			<div class="card nobreak">
				<div style="overflow:hidden;">
					<div style="float:left; width:72%;">
						<div style="font-weight:950;color:#0b2f2a;font-size:13px;"><?php echo esc_html( $summary['headline'] ); ?></div>
						<div class="mini"><?php echo esc_html( $base['module'] ); ?></div>
					</div>
					<div style="float:right; width:26%; text-align:right;">
						<span class="pill" style="margin:0;border-color:<?php echo esc_attr( $b[3] ); ?>;background:<?php echo esc_attr( $b[2] ); ?>;color:<?php echo esc_attr( $b[1] ); ?>;"><?php echo esc_html( $b[0] ); ?></span>
					</div>
					<div style="clear:both;"></div>
				</div>

				<ul style="margin:8px 0 0; padding-left:18px; font-size:12px; color:#425b56;">
					<?php foreach ( (array) $summary['bullets'] as $bl ) : ?>
						<li style="margin:4px 0;"><?php echo esc_html( $bl ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endforeach; ?>
	</div>

	<!-- Section 8 (new page) -->
	<div class="card iconCard pageBreakBefore" id="sec8">
		<div class="orb2"></div>
		<?php $icon_section_head(8, 'Competency Model used', 'Competencies found in the submitted framework for this assessment.'); ?>

		<?php foreach ( $modules as $m => $names ) : ?>
			<div class="card">
				<div class="pill" style="margin-bottom:8px;"><?php echo esc_html( $m ); ?></div>
				<ul style="margin:0; padding-left:18px; font-size:12px; color:#425b56;">
					<?php
					$names2 = array_values( array_unique( array_filter( $names ) ) );
					sort( $names2 );
					foreach ( $names2 as $nm ) :
					?>
						<li style="margin:4px 0;"><?php echo esc_html( $nm ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endforeach; ?>
	</div>

</body>
</html>
