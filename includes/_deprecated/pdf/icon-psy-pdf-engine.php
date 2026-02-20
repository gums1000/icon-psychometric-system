<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * ICON PSY — Central PDF Engine (Dompdf)
 *
 * Call:
 *   icon_psy_pdf_render_and_stream('traits', ['participant_id' => 72]);
 *
 * Optional:
 *   - 'paper' => 'A4' (default) OR [width, height] in points
 *   - 'orientation' => 'portrait' (default) or 'landscape'
 *   - 'branding' => ['primary'=>'#...', 'secondary'=>'#...', 'logo_url'=>'...'] (overrides DB if provided)
 *   - 'lock' => true (prevents template from doing DB/theme fallback overrides)
 *   - 'attachment' => 1 (default) download, 0 inline
 */

if ( ! function_exists( 'icon_psy_pdf_render_and_stream' ) ) {

	function icon_psy_pdf_render_and_stream( $report_key, array $args = array() ) {

		$report_key = sanitize_key( (string) $report_key );

		$allowed = array( 'traits', 'self', 'feedback', 'team' );
		if ( ! in_array( $report_key, $allowed, true ) ) {
			wp_die( 'Invalid report.' );
		}

		// Require login for PDFs (recommended)
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		// ------------------------------------------------------------
		// Load Dompdf
		// ------------------------------------------------------------
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {

			// engine file is /includes/pdf/icon-psy-pdf-engine.php
			$autoload_candidates = array(
				dirname( __FILE__, 3 ) . '/vendor/autoload.php',
				dirname( __FILE__, 2 ) . '/vendor/autoload.php',
				dirname( __FILE__, 1 ) . '/vendor/autoload.php',
			);

			foreach ( $autoload_candidates as $p ) {
				if ( $p && file_exists( $p ) ) {
					require_once $p;
					break;
				}
			}
		}

		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			wp_die( 'PDF engine not available: Dompdf not found (autoload missing).' );
		}

		// Prevent notices/warnings corrupting PDF output (do BEFORE render)
		@ini_set( 'display_errors', '0' );
		@ini_set( 'display_startup_errors', '0' );
		@error_reporting( 0 );

		// ------------------------------------------------------------
		// Dompdf options (WP-safe hardening)
		// ------------------------------------------------------------
		$options = new Options();
		$options->set( 'isHtml5ParserEnabled', true );
		$options->set( 'isRemoteEnabled', true );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'isFontSubsettingEnabled', true );

		// FIX: Dompdf can fail to load local assets unless chroot is set.
		// Allow WP root + uploads (most common asset locations).
		$uploads = wp_get_upload_dir();
		$chroot  = array();
		if ( defined('ABSPATH') ) $chroot[] = ABSPATH;
		if ( ! empty( $uploads['basedir'] ) ) $chroot[] = $uploads['basedir'];
		$chroot = array_values( array_filter( array_unique( $chroot ) ) );
		if ( ! empty( $chroot ) ) {
			$options->set( 'chroot', $chroot );
		}

		// FIX: temp dir helps on some hosts (permissions / memory)
		if ( ! empty( $uploads['basedir'] ) && is_dir( $uploads['basedir'] ) && is_writable( $uploads['basedir'] ) ) {
			$tmp = trailingslashit( $uploads['basedir'] ) . 'icon-pdf-tmp';
			if ( ! file_exists( $tmp ) ) {
				@wp_mkdir_p( $tmp );
			}
			if ( is_dir( $tmp ) && is_writable( $tmp ) ) {
				$options->set( 'tempDir', $tmp );
			}
		}

		$dompdf = new Dompdf( $options );

		// Paper config (optional)
		$paper       = isset( $args['paper'] ) ? $args['paper'] : 'A4';
		$orientation = isset( $args['orientation'] ) ? strtolower( (string) $args['orientation'] ) : 'portrait';
		if ( ! in_array( $orientation, array( 'portrait', 'landscape' ), true ) ) {
			$orientation = 'portrait';
		}
		$dompdf->setPaper( $paper, $orientation );

		try {

			// ------------------------------------------------------------
			// BRANDING (resolve once here, inject into args)
			// ------------------------------------------------------------
			$participant_id = isset( $args['participant_id'] ) ? absint( $args['participant_id'] ) : 0;
			$project_id     = isset( $args['project_id'] ) ? absint( $args['project_id'] ) : 0;
			$client_id      = isset( $args['client_id'] ) ? absint( $args['client_id'] ) : 0;

			$resolved_branding = icon_psy_pdf_resolve_branding( $participant_id, $project_id, $client_id );

			// If caller supplied branding, let it override resolved values
			if ( isset( $args['branding'] ) && is_array( $args['branding'] ) ) {
				$bo = $args['branding'];

				if ( ! empty( $bo['primary'] ) )   $resolved_branding['primary']   = (string) $bo['primary'];
				if ( ! empty( $bo['secondary'] ) ) $resolved_branding['secondary'] = (string) $bo['secondary'];

				$logo_candidate = '';
				if ( ! empty( $bo['logo_url'] ) )      $logo_candidate = (string) $bo['logo_url'];
				elseif ( ! empty( $bo['logo'] ) )      $logo_candidate = (string) $bo['logo'];
				elseif ( ! empty( $bo['brand_logo'] ) ) $logo_candidate = (string) $bo['brand_logo'];

				if ( $logo_candidate !== '' ) $resolved_branding['logo_url'] = $logo_candidate;
			}

			// FIX: if logo_url is actually an attachment ID, resolve it.
			if ( ! empty( $resolved_branding['logo_url'] ) && is_numeric( $resolved_branding['logo_url'] ) ) {
				$u = wp_get_attachment_url( absint( $resolved_branding['logo_url'] ) );
				if ( $u ) {
					$resolved_branding['logo_url'] = $u;
				}
			}

			// FIX: embed logo as data URI to avoid remote/SSL/chroot surprises.
			$resolved_branding['logo_data_uri'] = '';
			if ( ! empty( $resolved_branding['logo_url'] ) ) {
				$resolved_branding['logo_data_uri'] = icon_psy_pdf_logo_to_data_uri( $resolved_branding['logo_url'] );
			}

			$args['branding'] = $resolved_branding;

			// Force template to honour args branding
			if ( ! isset( $args['lock'] ) ) {
				$args['lock'] = true;
			}

			// Build HTML from template
			$html = icon_psy_pdf_build_html( $report_key, $args );

			// Provide $data for templates that expect it
			$data = ( isset( $args['data'] ) && is_array( $args['data'] ) ) ? $args['data'] : $args;

			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->render();

			// Add page numbers (dynamic position)
			$canvas      = $dompdf->getCanvas();
			$fontMetrics = $dompdf->getFontMetrics();
			$font        = $fontMetrics->get_font( 'DejaVu Sans', 'normal' );

			if ( $font && $canvas ) {
				$w = (float) $canvas->get_width();
				$h = (float) $canvas->get_height();
				$x = max( 10, $w - 140 );  // right-ish
				$y = max( 10, $h - 28 );   // bottom-ish
				$canvas->page_text( $x, $y, "Page {PAGE_NUM} of {PAGE_COUNT}", $font, 9, array( 71, 85, 105 ) );
			}

			$filename = icon_psy_pdf_filename( $report_key, $args );

			// Clear any output buffers so the PDF bytes are clean
			while ( ob_get_level() ) {
				@ob_end_clean();
			}

			$attachment = isset( $args['attachment'] ) ? (int) $args['attachment'] : 1;

			// Stream
			$dompdf->stream( $filename, array( 'Attachment' => $attachment ) );
			exit;

		} catch ( \Throwable $e ) {

			while ( ob_get_level() ) {
				@ob_end_clean();
			}

			wp_die( 'PDF export failed: ' . esc_html( $e->getMessage() ) );
		}
	}
}

if ( ! function_exists( 'icon_psy_pdf_build_html' ) ) {

	function icon_psy_pdf_build_html( $report_key, array $args ) {

		$report_key = sanitize_key( (string) $report_key );

		// templates live at: /includes/pdf/templates/{report}.php
		$template = dirname( __FILE__ ) . '/templates/' . $report_key . '.php';

		if ( ! file_exists( $template ) ) {
			wp_die( 'PDF template missing: ' . esc_html( $template ) );
		}

		// Make args visible to template (keep this exact name)
		$icon_pdf_args     = $args;
		$icon_pdf_branding = isset( $args['branding'] ) && is_array( $args['branding'] ) ? $args['branding'] : array();

		// ------------------------------------------------------------
		// Back-compat: provide $data to templates that expect it
		// ------------------------------------------------------------
		$data = ( isset( $args['data'] ) && is_array( $args['data'] ) )
			? $args['data']
			: $args; // allow templates to use args directly as data

		// Also expose branding as $branding for older templates
		$branding = ( isset( $args['branding'] ) && is_array( $args['branding'] ) )
			? $args['branding']
			: array();

		// ✅ Provide $data for templates that expect it
		$data = array();
		if ( isset( $args['data'] ) && is_array( $args['data'] ) ) {
			$data = $args['data'];
		} else {
			// Fallback: allow templates to read directly from args
			$data = $args;
		}

		ob_start();
		include $template;
		return (string) ob_get_clean();
	}
}

/**
 * Convert a logo URL to a data URI (best-effort).
 * Returns "" if it cannot be fetched/read.
 */
if ( ! function_exists( 'icon_psy_pdf_logo_to_data_uri' ) ) {

	function icon_psy_pdf_logo_to_data_uri( $url ) {

		$url = is_string( $url ) ? trim( $url ) : '';
		if ( $url === '' ) return '';

		// If this is a local file path disguised as URL, try to map it.
		// Otherwise fetch via HTTP (best-effort).
		$bytes = '';

		// Local file mapping for uploads
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['baseurl'] ) && ! empty( $uploads['basedir'] ) && strpos( $url, $uploads['baseurl'] ) === 0 ) {
			$rel  = substr( $url, strlen( $uploads['baseurl'] ) );
			$path = rtrim( $uploads['basedir'], '/\\' ) . $rel;
			if ( file_exists( $path ) && is_readable( $path ) ) {
				$bytes = @file_get_contents( $path );
			}
		}

		if ( $bytes === '' ) {
			$r = wp_remote_get( $url, array( 'timeout' => 8 ) );
			if ( ! is_wp_error( $r ) ) {
				$code = (int) wp_remote_retrieve_response_code( $r );
				if ( $code >= 200 && $code < 300 ) {
					$bytes = (string) wp_remote_retrieve_body( $r );
				}
			}
		}

		if ( $bytes === '' ) return '';

		// MIME best-effort
		$mime = 'image/png';
		$lower = strtolower( parse_url( $url, PHP_URL_PATH ) ?: '' );
		if ( preg_match( '/\.jpe?g$/', $lower ) ) $mime = 'image/jpeg';
		elseif ( preg_match( '/\.gif$/', $lower ) ) $mime = 'image/gif';
		elseif ( preg_match( '/\.svg$/', $lower ) ) $mime = 'image/svg+xml';
		elseif ( preg_match( '/\.webp$/', $lower ) ) $mime = 'image/webp';

		return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
	}
}

/**
 * Resolve branding from DB (project/client/option fallback).
 * Returns: ['primary'=>'#..','secondary'=>'#..','logo_url'=>'...','logo_data_uri'=>'...']
 */
if ( ! function_exists( 'icon_psy_pdf_resolve_branding' ) ) {

	function icon_psy_pdf_resolve_branding( $participant_id = 0, $project_id = 0, $client_id = 0 ) {
		global $wpdb;

		$branding = array(
			'primary'       => '#15a06d',
			'secondary'     => '#14a4cf',
			'logo_url'      => '',
			'logo_data_uri' => '',
		);

		$sanitize_hex = function( $hex, $fallback ) {
			$hex = is_string( $hex ) ? trim( $hex ) : '';
			if ( $hex === '' ) return $fallback;
			if ( $hex[0] !== '#' ) $hex = '#' . $hex;
			return preg_match( '/^#([a-fA-F0-9]{6})$/', $hex ) ? strtolower( $hex ) : $fallback;
		};

		$decode_json = function( $raw ) {
			$raw = is_string($raw) ? trim($raw) : '';
			if ( $raw === '' || $raw === 'null' ) return null;
			$d = json_decode($raw, true);
			if ( is_array($d) ) return $d;
			$u = stripcslashes($raw);
			if ( $u !== $raw ) {
				$d2 = json_decode($u, true);
				if ( is_array($d2) ) return $d2;
			}
			return null;
		};

		// Derive project/client from participant if needed
		$client_name = '';
		if ( $participant_id > 0 && ( $project_id <= 0 || $client_id <= 0 ) ) {
			$p = $wpdb->get_row( $wpdb->prepare(
				"SELECT p.project_id, pr.client_id, pr.client_name
				 FROM {$wpdb->prefix}icon_psy_participants p
				 LEFT JOIN {$wpdb->prefix}icon_psy_projects pr ON pr.id = p.project_id
				 WHERE p.id = %d
				 LIMIT 1",
				$participant_id
			) );

			if ( $p ) {
				if ( $project_id <= 0 ) $project_id = (int) $p->project_id;
				if ( $client_id <= 0 )  $client_id  = (int) $p->client_id;
				$client_name = (string) ( $p->client_name ?? '' );
			}
		}

		// 1) Project branding
		if ( $project_id > 0 ) {
			$pr = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}icon_psy_projects WHERE id = %d LIMIT 1", $project_id ),
				ARRAY_A
			);

			if ( is_array($pr) ) {

				if ( ! empty($pr['branding_json']) ) {
					$bj = $decode_json( $pr['branding_json'] );
					if ( is_array($bj) ) {
						if ( ! empty($bj['primary']) )   $branding['primary']   = $sanitize_hex( $bj['primary'], $branding['primary'] );
						if ( ! empty($bj['secondary']) ) $branding['secondary'] = $sanitize_hex( $bj['secondary'], $branding['secondary'] );

						if ( ! empty($bj['logo_url']) ) $branding['logo_url'] = (string) $bj['logo_url'];
						elseif ( ! empty($bj['logo']) ) $branding['logo_url'] = (string) $bj['logo'];
						elseif ( ! empty($bj['logo_id']) ) $branding['logo_url'] = (string) $bj['logo_id']; // attachment id ok
					}
				}

				foreach ( array('primary_color','brand_primary','primary_colour','colour_primary') as $c ) {
					if ( ! empty($pr[$c]) ) { $branding['primary'] = $sanitize_hex( $pr[$c], $branding['primary'] ); break; }
				}
				foreach ( array('secondary_color','brand_secondary','secondary_colour','colour_secondary') as $c ) {
					if ( ! empty($pr[$c]) ) { $branding['secondary'] = $sanitize_hex( $pr[$c], $branding['secondary'] ); break; }
				}
				foreach ( array('logo_url','brand_logo','brand_logo_url','client_logo','client_logo_url') as $c ) {
					if ( ! empty($pr[$c]) ) { $branding['logo_url'] = (string) $pr[$c]; break; }
				}
			}
		}

		// 2) Client branding table (if present)
		if ( $client_id > 0 && $branding['logo_url'] === '' ) {

			$clients_candidates = array(
				$wpdb->prefix . 'icon_psy_clients',
				$wpdb->prefix . 'icon_clients',
				$wpdb->prefix . 'icon_psy_companies',
				$wpdb->prefix . 'icon_psy_client_accounts',
			);

			$clients_table = '';
			foreach ( $clients_candidates as $t ) {
				$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $t ) );
				if ( $found === $t ) { $clients_table = $t; break; }
			}

			if ( $clients_table ) {
				$cl = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$clients_table} WHERE id = %d LIMIT 1", $client_id ), ARRAY_A );
				if ( is_array($cl) ) {

					if ( ! empty($cl['branding_json']) ) {
						$bj = $decode_json( $cl['branding_json'] );
						if ( is_array($bj) ) {
							if ( ! empty($bj['primary']) )   $branding['primary']   = $sanitize_hex( $bj['primary'], $branding['primary'] );
							if ( ! empty($bj['secondary']) ) $branding['secondary'] = $sanitize_hex( $bj['secondary'], $branding['secondary'] );

							if ( ! empty($bj['logo_url']) ) $branding['logo_url'] = (string) $bj['logo_url'];
							elseif ( ! empty($bj['logo']) ) $branding['logo_url'] = (string) $bj['logo'];
						}
					}

					foreach ( array('primary_color','brand_primary','primary_colour','colour_primary') as $c ) {
						if ( ! empty($cl[$c]) ) { $branding['primary'] = $sanitize_hex( $cl[$c], $branding['primary'] ); break; }
					}
					foreach ( array('secondary_color','brand_secondary','secondary_colour','colour_secondary') as $c ) {
						if ( ! empty($cl[$c]) ) { $branding['secondary'] = $sanitize_hex( $cl[$c], $branding['secondary'] ); break; }
					}
					foreach ( array('logo_url','brand_logo','brand_logo_url','logo','client_logo') as $c ) {
						if ( ! empty($cl[$c]) ) { $branding['logo_url'] = (string) $cl[$c]; break; }
					}
				}
			}
		}

		// 3) Option fallback (if you use this pattern)
		if ( $branding['logo_url'] === '' && $client_name !== '' ) {
			$opt = get_option( 'icon_psy_branding_' . sanitize_key( $client_name ) );
			if ( is_array($opt) ) {
				if ( ! empty($opt['primary']) )   $branding['primary']   = $sanitize_hex( $opt['primary'], $branding['primary'] );
				if ( ! empty($opt['secondary']) ) $branding['secondary'] = $sanitize_hex( $opt['secondary'], $branding['secondary'] );
				if ( ! empty($opt['logo_url']) )  $branding['logo_url']  = (string) $opt['logo_url'];
			}
		}

		return $branding;
	}
}

if ( ! function_exists( 'icon_psy_pdf_filename' ) ) {

	function icon_psy_pdf_filename( $report_key, array $args ) {

		$parts = array( 'icon', $report_key, gmdate( 'Ymd-His' ) );

		if ( isset( $args['participant_id'] ) ) {
			$parts[] = 'p' . absint( $args['participant_id'] );
		}
		if ( isset( $args['project_id'] ) ) {
			$parts[] = 'proj' . absint( $args['project_id'] );
		}

		return implode( '-', $parts ) . '.pdf';
	}
}
// ------------------------------------------------------------
// Compatibility wrappers (Team report expects these)
// ------------------------------------------------------------
if ( ! function_exists( 'icon_psy_send_pdf' ) ) {
	function icon_psy_send_pdf( $template_key, $data = array(), $branding = array(), $filename = '' ) {

		$args = array();

		// If caller passed full args into $data, accept it
		if ( is_array( $data ) && isset( $data['project_id'] ) || isset( $data['participant_id'] ) ) {
			$args = $data;
		} else {
			$args['data'] = is_array( $data ) ? $data : array();
		}

		if ( is_array( $branding ) && ! empty( $branding ) ) {
			$args['branding'] = $branding;
		}

		// Optional filename override
		if ( $filename ) {
			$args['filename'] = (string) $filename;
		}

		// Stream the PDF
		return icon_psy_pdf_render_and_stream( $template_key, $args );
	}
}

if ( ! function_exists( 'icon_psy_team_send_pdf' ) ) {
	function icon_psy_team_send_pdf( $args = array() ) {

		$args = is_array( $args ) ? $args : array();

		// Team template key in your engine allowed list is 'team'
		$report_key = 'team';

		// If Team Report passes ['data'=>..., 'branding'=>...] this will work
		return icon_psy_pdf_render_and_stream( $report_key, $args );
	}
}

