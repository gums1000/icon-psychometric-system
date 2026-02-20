<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ICON Catalyst — Feedback Report (manager / client view)
 *
 * Shortcode: [icon_psy_feedback_report]
 * Usage: /feedback-report/?participant_id=XX
 *
 * HTML-ONLY VERSION (amended + shortened):
 * - Removes ALL PDF export logic (no ?icon_psy_export=pdf handling)
 * - Removes ALL PDF helpers (data-URI logo fetch, PDF text sanitise, radar PNG generator)
 * - Removes “Download PDF” button + URL builder
 * - Keeps the full HTML report output (sections intact)
 * - Keeps client branding logic (Client Portal standard pattern)
 */

/* -------------------------------------------------------------
 * Branding (STANDARD: Client Portal branding logic + fallbacks)
 * ------------------------------------------------------------- */

if ( ! function_exists( 'icon_psy_hex_to_rgb' ) ) {
	function icon_psy_hex_to_rgb( $hex ) {
		$hex = trim( (string) $hex );
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
		}
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) return array( 15, 118, 110 );
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}
}

if ( ! function_exists( 'icon_psy_rgb_to_hex' ) ) {
	function icon_psy_rgb_to_hex( $r, $g, $b ) {
		$r = max( 0, min( 255, (int) $r ) );
		$g = max( 0, min( 255, (int) $g ) );
		$b = max( 0, min( 255, (int) $b ) );
		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}
}

if ( ! function_exists( 'icon_psy_mix_with_white' ) ) {
	// $amount: 0..1 (0 = original, 1 = white)
	function icon_psy_mix_with_white( $hex, $amount = 0.85 ) {
		$amount = max( 0, min( 1, (float) $amount ) );
		list( $r, $g, $b ) = icon_psy_hex_to_rgb( $hex );
		$r2 = (int) round( $r + ( 255 - $r ) * $amount );
		$g2 = (int) round( $g + ( 255 - $g ) * $amount );
		$b2 = (int) round( $b + ( 255 - $b ) * $amount );
		return icon_psy_rgb_to_hex( $r2, $g2, $b2 );
	}
}

if ( ! function_exists( 'icon_psy_brand_pick' ) ) {
	function icon_psy_brand_pick( $raw, $keys, $default = '' ) {
		if ( ! is_array( $raw ) ) return $default;
		foreach ( (array) $keys as $k ) {
			if ( isset( $raw[ $k ] ) && $raw[ $k ] !== '' && $raw[ $k ] !== null ) {
				return $raw[ $k ];
			}
		}
		return $default;
	}
}

if ( ! function_exists( 'icon_psy_brand_resolve_logo_url' ) ) {
	/**
	 * Supports:
	 * - logo_id (Media Library)
	 * - logo_url / logo / logo_src / logo_png
	 */
	function icon_psy_brand_resolve_logo_url( $raw ) {

		$logo_id = (int) icon_psy_brand_pick( $raw, array( 'logo_id', 'logo_attachment_id', 'brand_logo_id' ), 0 );
		if ( $logo_id > 0 ) {
			$src = wp_get_attachment_image_src( $logo_id, 'full' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				return (string) $src[0];
			}
		}

		$url = (string) icon_psy_brand_pick(
			$raw,
			array( 'logo_url', 'logo', 'logo_src', 'logo_png', 'brand_logo_url' ),
			''
		);

		return trim( $url );
	}
}

if ( ! function_exists( 'icon_psy_get_branding_for_client' ) ) {
	function icon_psy_get_branding_for_client( $client_user_id = 0 ) {

		// Default ICON branding (fallback)
		$brand = array(
			'primary'   => '#0f766e', // Icon green
			'secondary' => '#0ea5e9', // Icon blue
			'ink'       => '#022c22',
			'soft'      => '#ecfdf5',
			'border'    => '#d1fae5',
			'logo_url'  => 'https://icon-talent.org/wp-content/uploads/2025/12/Icon-Catalyst-System.png',
		);

		// Pull from your standard engine if available
		if ( function_exists( 'icon_psy_get_client_branding' ) ) {

			$raw = icon_psy_get_client_branding( (int) $client_user_id );

			// NORMALISE branding payload (array | object | json string)
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$raw = $decoded;
				}
			}
			if ( is_object( $raw ) ) {
				$raw = (array) $raw;
			}

			if ( is_array( $raw ) ) {

				$primary = (string) icon_psy_brand_pick(
					$raw,
					array(
						'primary','primary_color','primary_colour','primary_hex','brand_primary',
						'primaryColor','primaryColour'
					),
					$brand['primary']
				);

				$secondary = (string) icon_psy_brand_pick(
					$raw,
					array(
						'secondary','secondary_color','secondary_colour','secondary_hex','brand_secondary',
						'secondaryColor','secondaryColour'
					),
					$brand['secondary']
				);

				$primary   = trim( $primary );
				$secondary = trim( $secondary );

				// Basic sanity (only accept hex-ish strings)
				if ( preg_match( '/^#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $primary ) ) {
					if ( $primary !== '' && $primary[0] !== '#' ) $primary = '#' . $primary;
					$brand['primary'] = $primary;
				}
				if ( preg_match( '/^#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $secondary ) ) {
					if ( $secondary !== '' && $secondary[0] !== '#' ) $secondary = '#' . $secondary;
					$brand['secondary'] = $secondary;
				}

				$logo_url = icon_psy_brand_resolve_logo_url( $raw );
				if ( $logo_url !== '' ) {
					$brand['logo_url'] = $logo_url;
				}
			}
		}

		// Derived tones
		$brand['soft']   = icon_psy_mix_with_white( $brand['primary'], 0.90 );
		$brand['border'] = icon_psy_mix_with_white( $brand['primary'], 0.78 );
		$brand['ink']    = '#022c22';

		return $brand;
	}
}

if ( ! function_exists( 'icon_psy_feedback_report_css' ) ) {
	function icon_psy_feedback_report_css( $brand ) {

		$primary   = isset( $brand['primary'] ) ? (string) $brand['primary'] : '#0f766e';
		$secondary = isset( $brand['secondary'] ) ? (string) $brand['secondary'] : '#0ea5e9';

		list( $pr, $pg, $pb ) = icon_psy_hex_to_rgb( $primary );
		list( $sr, $sg, $sb ) = icon_psy_hex_to_rgb( $secondary );

		// Premium / “high-end” styling update:
		// - Softer, more executive palette (ink + paper)
		// - Cleaner elevation (subtle shadow + neutral border)
		// - Better typography rhythm + tighter UI components
		// - Reduced “loud” gradients; keep brand as accent
		// - Tables feel “report-like” (header, zebra, hover)
		$css = '
:root{
  --icon-green: ' . esc_attr( $primary ) . ';
  --icon-blue: ' . esc_attr( $secondary ) . ';
  --icon-p-rgb:' . (int) $pr . ',' . (int) $pg . ',' . (int) $pb . ';
  --icon-s-rgb:' . (int) $sr . ',' . (int) $sg . ',' . (int) $sb . ';

  --ink-900:#0b1220;
  --ink-800:#101a2e;
  --ink-700:#1f2a44;
  --ink-600:#33415f;
  --muted-600:#55627a;
  --muted-500:#6b7280;

  --paper:#ffffff;
  --paper-2:#fbfcfe;
  --surface:#f5f7fb;
  --border:#e6e9f1;
  --border-2:#dfe5f1;

  --shadow: 0 16px 40px rgba(11,18,32,0.08);
  --shadow-2: 0 10px 24px rgba(11,18,32,0.06);
  --ring: 0 0 0 4px rgba(var(--icon-p-rgb),0.18);

  --radius-xl:22px;
  --radius-lg:18px;
  --radius-md:14px;
  --radius-sm:12px;
}

.icon-psy-report-wrapper{
  color:var(--ink-900);
  -webkit-font-smoothing:antialiased;
  -moz-osx-font-smoothing:grayscale;
}

.icon-psy-page{ max-width:1180px;margin:0 auto;padding:18px 18px 44px; }

.icon-psy-content{
  margin-top:18px;
  background:var(--paper);
  border-radius:var(--radius-xl);
  padding:18px;
  border:1px solid var(--border);
  box-shadow: var(--shadow-2);
}

/* Screen shell (premium) */
.is-screen{
  background:
    radial-gradient(1200px 520px at 18% -10%, rgba(var(--icon-p-rgb),0.18), rgba(var(--icon-p-rgb),0) 56%),
    radial-gradient(900px 520px at 95% 0%, rgba(var(--icon-s-rgb),0.16), rgba(var(--icon-s-rgb),0) 58%),
    linear-gradient(180deg, #ffffff 0%, #ffffff 42%, #f5f7fb 42%, #f5f7fb 100%);
  border-radius:26px;
  padding:18px;
  box-shadow:0 28px 70px rgba(11,18,32,0.10);
  border:1px solid rgba(0,0,0,0.06);
}

/* HERO (executive) */
.icon-psy-hero{
  border-radius:26px;
  padding:26px 26px 20px;
  color:#fff;
  position:relative;
  overflow:hidden;
  background:
    radial-gradient(520px 320px at 0% 0%, rgba(var(--icon-p-rgb),0.28), rgba(var(--icon-p-rgb),0) 60%),
    radial-gradient(520px 320px at 100% 0%, rgba(var(--icon-s-rgb),0.22), rgba(var(--icon-s-rgb),0) 60%),
    linear-gradient(135deg, rgba(var(--icon-p-rgb),0.22) 0%, rgba(var(--icon-s-rgb),0.18) 100%);
  color: var(--ink-900);
  border:1px solid rgba(0,0,0,0.06);
}


.icon-psy-hero::before{
  content:"";
  position:absolute;
  inset:-22% -18% auto auto;
  width:560px;height:560px;border-radius:50%;
  background:rgba(255,255,255,0.06);
  filter: blur(0px);
}
.icon-psy-hero::after{
  content:"";
  position:absolute;
  inset:auto -22% -40% auto;
  width:560px;height:560px;border-radius:50%;
  background:rgba(var(--icon-p-rgb),0.10);
}

.icon-psy-hero-inner{
  position:relative;z-index:2;
  display:grid;grid-template-columns:74px 1fr auto;
  gap:18px;align-items:center;
}

.icon-psy-hero-avatar{
  width:68px;height:68px;border-radius:18px;
  background:rgba(255,255,255,0.10);
  border:1px solid rgba(255,255,255,0.14);
  display:flex;align-items:center;justify-content:center;
  box-shadow: 0 10px 20px rgba(0,0,0,0.18);
}
.icon-psy-hero-avatar img{
  width:54px;height:54px;border-radius:14px;
  object-fit:contain;display:block;background:#fff;padding:6px;
}

.icon-psy-hero-kicker{
  font-size:11px;letter-spacing:1.8px;text-transform:uppercase;
  opacity:.88;margin-bottom:8px;
}

.icon-psy-hero-title{
  font-size:44px;line-height:1.06;font-weight:820;
  margin:0 0 8px;
  letter-spacing:-0.02em;
}
.icon-psy-hero-sub{
  font-size:14px;opacity:.90;margin:0;
  color:rgba(255,255,255,0.92);
}

.icon-psy-hero-actions{ display:flex;gap:10px;align-items:center; }

.icon-psy-hero-btn{
  appearance:none;border:1px solid rgba(255,255,255,0.18);
  cursor:pointer;font-weight:750;
  border-radius:999px;
  padding:11px 14px;
  text-decoration:none;
  display:inline-flex;align-items:center;
  transition: transform .12s ease, filter .12s ease, background .12s ease;
}
.icon-psy-hero-btn.primary{
  background:#fff;color:var(--ink-900);
  border-color:rgba(255,255,255,0.20);
}
.icon-psy-hero-btn.ghost{
  background:rgba(255,255,255,0.08);
  color:#fff;
  backdrop-filter: blur(8px);
}
.icon-psy-hero-btn:hover{ transform: translateY(-1px); }
.icon-psy-hero-btn:focus{ outline:none; box-shadow: var(--ring); }

.icon-psy-hero-chips{
  position:relative;z-index:2;
  display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;
}

.icon-psy-chip-row{ display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;font-size:11px; }
.icon-psy-chip{
  display:inline-flex;align-items:center;gap:6px;
  padding:5px 10px;border-radius:999px;font-size:11px;
  border:1px solid rgba(0,0,0,0.08);
  background:rgba(255,255,255,0.70);
  color:var(--ink-800);
  backdrop-filter: blur(10px);
}
.icon-psy-chip strong{ font-weight:800; }
.icon-psy-chip-muted{
  padding:5px 10px;border-radius:999px;
  border:1px solid var(--border);
  background:var(--paper-2);
  color:var(--ink-700);
}

/* Cards / sections */
.icon-psy-report-card{
  background:var(--paper);
  border-radius:var(--radius-lg);
  padding:22px 24px;
  box-shadow:var(--shadow-2);
  border:1px solid var(--border);
  margin-bottom:16px;
}

.icon-psy-report-title{ font-size:22px;font-weight:780;margin:0 0 6px;color:var(--ink-900); letter-spacing:-0.01em; }
.icon-psy-report-sub{ margin:0 0 10px;font-size:13px;color:var(--muted-600); }

.icon-psy-section-head{ display:flex;align-items:flex-start;justify-content:space-between;gap:10px; }
.icon-psy-section-title{
  font-size:16px;font-weight:780;margin:0 0 6px;
  color:var(--ink-900);
  letter-spacing:-0.01em;
}
.icon-psy-section-sub{ margin:0 0 12px;font-size:13px;color:var(--muted-600); line-height:1.55; }
.icon-psy-empty{ font-size:13px;color:var(--muted-600);margin:6px 0 0; }

.icon-psy-backtop{
  border:1px solid var(--border);
  background:var(--paper-2);
  color:var(--ink-700);
  text-decoration:none;
  padding:7px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:700;
  transition: transform .12s ease, background .12s ease, border-color .12s ease;
}
.icon-psy-backtop:hover{ transform: translateY(-1px); border-color: rgba(var(--icon-p-rgb),0.30); }
.icon-psy-backtop:focus{ outline:none; box-shadow: var(--ring); }

/* Layout grids */
.icon-psy-summary-grid{ display:grid;grid-template-columns:minmax(0,2fr) minmax(0,2fr);gap:14px; }
@media (max-width:720px){ .icon-psy-summary-grid{ grid-template-columns:1fr; } }

.icon-psy-summary-box{
  border-radius:var(--radius-md);
  border:1px solid var(--border);
  background:linear-gradient(180deg, var(--paper) 0%, var(--paper-2) 100%);
  padding:12px 14px;
  font-size:13px;
  color:var(--ink-700);
}
.icon-psy-summary-label{
  font-size:11px;text-transform:uppercase;letter-spacing:0.10em;
  color:var(--muted-600);
  margin-bottom:4px;
  font-weight:800;
}
.icon-psy-summary-metric{
  font-size:22px;
  font-weight:850;
  letter-spacing:-0.02em;
  color:var(--ink-900);
}
.icon-psy-summary-tag{
  display:inline-flex;align-items:center;
  padding:4px 10px;border-radius:999px;
  border:1px solid rgba(var(--icon-p-rgb),0.25);
  background:rgba(var(--icon-p-rgb),0.08);
  font-size:11px;color:var(--ink-800);
  margin-top:10px;
  font-weight:800;
}

.icon-psy-summary-lists{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
  gap:12px;margin-top:12px;font-size:13px;
}
.icon-psy-summary-list-card{
  border-radius:var(--radius-md);
  border:1px solid var(--border);
  background:var(--paper-2);
  padding:12px 14px;
}
.icon-psy-summary-list-title{
  font-size:11px;text-transform:uppercase;letter-spacing:0.10em;
  color:var(--muted-600);
  margin:0 0 8px;
  font-weight:850;
}
.icon-psy-summary-list-card ul{ margin:0;padding-left:18px; }
.icon-psy-summary-list-card li{ margin:0 0 8px; line-height:1.55; }

/* How-to cards */
.icon-psy-how-grid{ display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:10px; }
.icon-psy-how-card{
  border-radius:var(--radius-md);
  border:1px solid var(--border);
  background:linear-gradient(180deg, var(--paper) 0%, var(--paper-2) 100%);
  padding:12px 14px;
  transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
}
.icon-psy-how-card:hover{
  transform: translateY(-1px);
  box-shadow: 0 12px 26px rgba(11,18,32,0.08);
  border-color: rgba(var(--icon-p-rgb),0.22);
}
.icon-psy-how-title{
  font-size:11px;text-transform:uppercase;letter-spacing:0.10em;
  color:var(--muted-600);
  margin:0 0 8px;
  font-weight:850;
}
.icon-psy-how-line{ font-size:13px;color:var(--ink-700);line-height:1.55;margin:0; }
.icon-psy-how-chip{
  display:inline-flex;align-items:center;gap:6px;
  padding:6px 10px;border-radius:999px;
  border:1px solid rgba(var(--icon-p-rgb),0.28);
  background:rgba(var(--icon-p-rgb),0.10);
  font-size:11px;color:var(--ink-900);
  margin-top:10px;text-decoration:none;font-weight:850;
  transition: transform .12s ease, filter .12s ease;
}
.icon-psy-how-chip:hover{ transform: translateY(-1px); }

/* Comments */
.icon-psy-comments-grid{ display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:10px; }
.icon-psy-comment-card{
  border-radius:var(--radius-md);
  border:1px solid var(--border);
  background:var(--paper-2);
  padding:12px 14px;font-size:13px;color:var(--ink-700);
}
.icon-psy-comment-label{
  font-size:11px;text-transform:uppercase;letter-spacing:0.10em;
  color:var(--muted-600);
  margin-bottom:8px;
  font-weight:850;
}

/* Competency cards */
.icon-psy-competency-overview-grid,
.icon-psy-competency-detail-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
  gap:12px;margin-top:10px;
}
.icon-psy-competency-overview-card,
.icon-psy-competency-detail-card{
  border-radius:var(--radius-md);
  border:1px solid var(--border);
  background:linear-gradient(180deg, var(--paper) 0%, var(--paper-2) 100%);
  padding:12px 14px;font-size:13px;color:var(--ink-700);
}
.icon-psy-competency-summary-name{
  font-size:14px;font-weight:850;color:var(--ink-900);
  margin:0 0 6px;letter-spacing:-0.01em;
}

/* Radar layout */
.icon-psy-radar-layout{ display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1.6fr);gap:14px; }
@media (max-width:820px){ .icon-psy-radar-layout{ grid-template-columns:1fr; } }
.icon-psy-radar-wrap{ display:flex;align-items:center;justify-content:center; }
.icon-psy-radar-caption{ font-size:12px;color:var(--muted-600);margin-top:6px;text-align:center; }

/* Tables (report-grade) */
.icon-psy-heat-table-wrapper{ overflow-x:auto;margin-top:8px; }
.icon-psy-heat-table,
.icon-psy-rater-table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
  font-size:13px;
  border:1px solid var(--border);
  border-radius:var(--radius-md);
  overflow:hidden;
  background:var(--paper);
}
.icon-psy-heat-table thead th,
.icon-psy-rater-table thead th{
  text-align:left;
  padding:10px 10px;
  border-bottom:1px solid var(--border);
  background:linear-gradient(180deg, var(--paper) 0%, #f3f5fa 100%);
  font-weight:850;
  color:var(--ink-800);
  font-size:12px;
  letter-spacing:0.02em;
}
.icon-psy-heat-table tbody td,
.icon-psy-rater-table tbody td{
  padding:10px 10px;
  border-bottom:1px solid #edf0f7;
  vertical-align:middle;
  color:var(--ink-700);
}
.icon-psy-heat-table tbody tr:nth-child(even),
.icon-psy-rater-table tbody tr:nth-child(even){
  background:#fafbfe;
}
.icon-psy-heat-table tbody tr:hover,
.icon-psy-rater-table tbody tr:hover{
  background:rgba(var(--icon-p-rgb),0.06);
}

.icon-psy-heat-name{ font-weight:800;color:var(--ink-900); }

/* Heat cell pills (clean) */
.icon-psy-heat-cell{
  display:inline-flex;align-items:center;justify-content:center;
  min-width:54px;padding:7px 10px;border-radius:999px;
  border:1px solid var(--border);
  background:#fff;
  font-weight:900;
  color:var(--ink-900);
}
.icon-psy-heat-low{
  background:rgba(239,68,68,0.12);
  border-color:rgba(239,68,68,0.35);
  color:#7f1d1d;
}
.icon-psy-heat-mid{
  background:rgba(245,158,11,0.14);
  border-color:rgba(245,158,11,0.38);
  color:#7c3a06;
}
.icon-psy-heat-high{
  background:rgba(34,197,94,0.14);
  border-color:rgba(34,197,94,0.38);
  color:#14532d;
}


/* Mini bars */
.icon-psy-mini-note{ margin:12px 0 0;font-size:12px;color:var(--muted-600);line-height:1.55; }

.icon-psy-mini-bar-row{ display:flex;align-items:center;gap:10px;margin-top:10px; }
.icon-psy-mini-bar-label{
  width:72px;font-size:11px;color:var(--muted-600);
  font-weight:900;text-transform:uppercase;letter-spacing:.10em;
}
.icon-psy-mini-bar-track{
  flex:1;height:10px;border-radius:999px;
  background:#eef2fb;
  overflow:hidden;
  border:1px solid var(--border);
}
.icon-psy-mini-bar{ height:100%;border-radius:999px; }
.icon-psy-mini-bar.self{ background: rgba(var(--icon-p-rgb),0.95); }
.icon-psy-mini-bar.others{ background: rgba(var(--icon-s-rgb),0.95); }

/* Insights */
.icon-psy-insight-box{
  border-radius:var(--radius-lg);
  border:1px solid var(--border);
  background:linear-gradient(180deg, var(--paper) 0%, var(--paper-2) 100%);
  padding:14px 16px;
}
.icon-psy-insight-strip{ display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:12px; }
@media (max-width:900px){ .icon-psy-insight-strip{ grid-template-columns:1fr; } }
.icon-psy-tile{
  border-radius:var(--radius-md);
  border:1px solid var(--border);
  background:#fff;
  padding:12px 14px;
  box-shadow: 0 10px 22px rgba(11,18,32,0.05);
}
.icon-psy-tile .k{
  margin:0 0 6px;font-size:11px;text-transform:uppercase;
  letter-spacing:.10em;color:var(--muted-600);font-weight:900;
}
.icon-psy-tile .v{
  margin:0 0 4px;font-size:16px;font-weight:900;
  color:var(--ink-900);
  letter-spacing:-0.01em;
}
.icon-psy-tile .s{ margin:0;font-size:12px;color:var(--muted-600);line-height:1.55; }

.icon-psy-prompts{ margin:0;padding-left:18px;font-size:13px;color:var(--ink-700); }
.icon-psy-prompts li{ margin:0 0 8px;line-height:1.6; }

/* Competency map chips */
.icon-psy-competency-map{
  border-radius:var(--radius-lg);
  border:1px solid var(--border);
  background:var(--paper-2);
  padding:12px 14px;
}
.icon-psy-competency-map-title{
  font-size:11px;text-transform:uppercase;letter-spacing:.10em;
  color:var(--muted-600);
  margin:0 0 10px;
  font-weight:900;
}
.icon-psy-competency-chip-cloud{ display:flex;flex-wrap:wrap;gap:8px; }
.icon-psy-competency-chip{
  display:inline-flex;align-items:center;
  padding:6px 10px;border-radius:999px;
  border:1px solid var(--border);
  background:#fff;
  color:var(--ink-700);
  font-size:11px;font-weight:850;
}

/* Competency metric pills */
.icon-psy-competency-metrics{ display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 10px; }
.icon-psy-competency-pill{
  display:inline-flex;align-items:center;
  padding:5px 10px;border-radius:999px;
  border:1px solid rgba(var(--icon-p-rgb),0.26);
  background:rgba(var(--icon-p-rgb),0.10);
  color:var(--ink-900);
  font-size:11px;font-weight:900;
}
.icon-psy-competency-pill-muted{
  display:inline-flex;align-items:center;
  padding:5px 10px;border-radius:999px;
  border:1px solid var(--border);
  background:#fff;
  color:var(--ink-700);
  font-size:11px;font-weight:900;
}

/* Blind spot cards */
.icon-psy-blind-grid{ display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:10px; }
.icon-psy-blind-card{
  border-radius:var(--radius-lg);
  border:1px solid var(--border);
  background:#fff;
  padding:12px 14px;
  box-shadow: 0 12px 26px rgba(11,18,32,0.06);
}
.icon-psy-blind-card.positive{
  border-color: rgba(var(--icon-p-rgb),0.22);
  background: linear-gradient(180deg, rgba(var(--icon-p-rgb),0.08) 0%, #ffffff 70%);
}
.icon-psy-blind-name{ margin:0 0 8px;font-weight:900;color:var(--ink-900);font-size:13px; }
.icon-psy-blind-metrics{ display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px; }
.icon-psy-blind-pill{
  display:inline-flex;align-items:center;
  padding:5px 10px;border-radius:999px;
  border:1px solid var(--border);
  background:var(--paper-2);
  color:var(--ink-700);
  font-size:11px;font-weight:900;
}
.icon-psy-blind-text{ font-size:13px;color:var(--ink-700);line-height:1.6; }

/* Small improvements for generic links inside report */
.icon-psy-report-wrapper a{ color: inherit; }
.icon-psy-report-wrapper a:focus{ outline:none; box-shadow: var(--ring); border-radius:10px; }

/* Ensure lists look “reporty” */
.icon-psy-report-wrapper ul li, .icon-psy-report-wrapper ol li{
  line-height:1.6;
}
';
		return $css;
	}
}

/* -------------------------------------------------------------
 * Shortcode registration
 * ------------------------------------------------------------- */

if ( ! function_exists( 'icon_psy_register_feedback_report_shortcode' ) ) {
	function icon_psy_register_feedback_report_shortcode() {
		if ( shortcode_exists( 'icon_psy_feedback_report' ) ) return;
		add_shortcode( 'icon_psy_feedback_report', 'icon_psy_feedback_report' );
	}
	add_action( 'init', 'icon_psy_register_feedback_report_shortcode' );
}

/* -------------------------------------------------------------
 * Global helpers (safe, no redeclare)
 * ------------------------------------------------------------- */

if ( ! function_exists( 'icon_psy_is_completed_status' ) ) {
	function icon_psy_is_completed_status( $status ) {
		$s = strtolower( trim( (string) $status ) );
		return in_array( $s, array( 'completed', 'complete', 'submitted', 'done', 'finished' ), true );
	}
}

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

		// Client direct access
		if ( icon_psy_user_has_role( $u, 'icon_client' ) ) {
			return $uid;
		}

		// Admin impersonation support (same meta used in portal)
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
			if ( $legacy > 0 ) return $legacy;
		}

		return 0;
	}
}

/* -------------------------------------------------------------
 * Ensure narratives engine is available on this page
 * ------------------------------------------------------------- */
if ( ! function_exists( 'icon_psy_try_load_lens_narratives' ) ) {
	function icon_psy_try_load_lens_narratives() {

		if ( function_exists( 'icon_psy_lens_narrative_html' ) ) {
			return array( 'loaded' => true, 'path' => 'already_loaded' );
		}

		$candidates = array(
			defined('ICON_PSY_PLUGIN_DIR') ? ICON_PSY_PLUGIN_DIR . 'includes/narratives/lens-narratives.php' : '',
			dirname( __FILE__ ) . '/../narratives/lens-narratives.php',
		);

		foreach ( $candidates as $path ) {
			if ( $path && file_exists( $path ) ) {
				require_once $path;
				if ( function_exists( 'icon_psy_lens_narrative_html' ) ) {
					return array( 'loaded' => true, 'path' => $path );
				}
			}
		}

		return array( 'loaded' => false, 'path' => 'not_found' );
	}
}

/* -------------------------------------------------------------
 * DB schema detection
 * ------------------------------------------------------------- */

if ( ! function_exists( 'icon_psy_table_exists' ) ) {
	function icon_psy_table_exists( $table ) {
		global $wpdb;
		$like = $wpdb->esc_like( $table );
		$res  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $like ) );
		return ! empty( $res );
	}
}

if ( ! function_exists( 'icon_psy_get_table_columns' ) ) {
	function icon_psy_get_table_columns( $table ) {
		global $wpdb;
		$rows = $wpdb->get_results( "DESCRIBE {$table}" );
		$cols = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				if ( isset( $r->Field ) ) $cols[] = (string) $r->Field;
			}
		}
		return $cols;
	}
}

if ( ! function_exists( 'icon_psy_pick_col' ) ) {
	function icon_psy_pick_col( $cols, $candidates ) {
		$lower_map = array();
		foreach ( (array) $cols as $c ) {
			$lower_map[ strtolower( $c ) ] = $c;
		}
		foreach ( (array) $candidates as $cand ) {
			$key = strtolower( (string) $cand );
			if ( isset( $lower_map[ $key ] ) ) return $lower_map[ $key ];
		}
		return '';
	}
}

if ( ! function_exists( 'icon_psy_detect_results_schema' ) ) {
	function icon_psy_detect_results_schema() {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$candidates = array(
			$prefix . 'icon_assessment_results',
			$prefix . 'icon_psy_assessment_results',
			$prefix . 'icon_psy_results',
			$prefix . 'icon_psy_assessment_result',
			$prefix . 'icon_assessment_result',
		);

		$table = '';
		$cols  = array();

		foreach ( $candidates as $t ) {
			if ( icon_psy_table_exists( $t ) ) {
				$table = $t;
				$cols  = icon_psy_get_table_columns( $t );
				if ( ! empty( $cols ) ) break;
			}
		}

		if ( ! $table ) {
			return array( 'table' => '', 'cols' => array(), 'map' => array(), 'order' => '' );
		}

		$map = array();
		$map['participant_id'] = icon_psy_pick_col( $cols, array( 'participant_id','participantID','participantId','participant','user_id','assessment_user_id','candidate_id' ) );
		$map['rater_id']       = icon_psy_pick_col( $cols, array( 'rater_id','raterID','raterId','reviewer_id' ) );
		$map['framework_id']   = icon_psy_pick_col( $cols, array( 'framework_id','frameworkID','framework' ) );
		$map['status']         = icon_psy_pick_col( $cols, array( 'status','completion_status','survey_status','state' ) );
		$map['is_completed']   = icon_psy_pick_col( $cols, array( 'is_completed','is_complete','completed','complete','submitted','is_submitted' ) );
		$map['completed_at']   = icon_psy_pick_col( $cols, array( 'completed_at','submitted_at','submitted_on','completed_on','finished_at','completed_date' ) );

		$map['q1_rating'] = icon_psy_pick_col( $cols, array(
			'q1_rating',
			'overall_rating',
			'rating_overall',
			'overall_score',
			'score_overall',
			'rating'
		) );

		if ( empty( $map['q1_rating'] ) ) {
			// last resort fallbacks (risky)
			$map['q1_rating'] = icon_psy_pick_col( $cols, array( 'score', 'overall' ) );
		}
		$map['q2_text']        = icon_psy_pick_col( $cols, array( 'q2_text','strengths_text','strength_text','what_working','q2','strengths' ) );
		$map['q3_text']        = icon_psy_pick_col( $cols, array( 'q3_text','development_text','improve_text','what_change','q3','development' ) );
		$map['detail_json']    = icon_psy_pick_col( $cols, array( 'detail_json','responses_json','answers_json','response_json','details_json','payload_json' ) );

		$order = icon_psy_pick_col( $cols, array( 'created_at','submitted_at','completed_at','id' ) );

		return array( 'table' => $table, 'cols' => $cols, 'map' => $map, 'order' => $order );
	}
}

if ( ! function_exists( 'icon_psy_row_is_completed_detected' ) ) {
	function icon_psy_row_is_completed_detected( $row, $map ) {
		if ( ! is_object( $row ) ) return false;

		if ( isset( $row->status ) && $row->status !== '' ) {
			if ( icon_psy_is_completed_status( $row->status ) ) return true;
		}

		if ( isset( $row->is_completed ) ) {
			$v = $row->is_completed;
			if ( $v === 1 || $v === '1' || $v === true || $v === 'yes' || $v === 'true' ) return true;
		}

		if ( isset( $row->completed_at ) && ! empty( $row->completed_at ) ) return true;
		if ( isset( $row->q1_rating ) && $row->q1_rating !== null && $row->q1_rating !== '' ) return true;
		if ( isset( $row->detail_json ) && ! empty( $row->detail_json ) ) return true;

		return false;
	}
}

/* -------------------------------------------------------------
 * Narrative text utilities
 * ------------------------------------------------------------- */

if ( ! function_exists( 'icon_psy_narr_clean_text' ) ) {
	function icon_psy_narr_clean_text( $text ) {
		$text = is_string( $text ) ? trim( $text ) : '';
		if ( $text === '' ) return '';

		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = str_replace( '**', '', $text );
		$text = str_replace( array( "—", "–" ), '-', $text );

		$parts = preg_split( "/\n\s*(ACTION:|ACTIONS:|QUESTION:)\s*/i", $text );
		$text  = isset( $parts[0] ) ? trim( (string) $parts[0] ) : '';
		return trim( $text );
	}
}

if ( ! function_exists( 'icon_psy_narr_to_two_paragraphs' ) ) {
	function icon_psy_narr_to_two_paragraphs( $text ) {
		$text = icon_psy_narr_clean_text( $text );
		if ( $text === '' ) return array( '', '' );

		$chunks = preg_split( "/\n\s*\n+/", $text );
		$paras  = array();

		foreach ( (array) $chunks as $c ) {
			$c = trim( (string) $c );
			if ( $c === '' ) continue;

			$lines = array_map( 'trim', explode( "\n", $c ) );
			$lines = array_values( array_filter( $lines, function( $l ){
				if ( $l === '' ) return false;
				if ( preg_match( '/^\s*[\-\•]\s+/', $l ) ) return false;
				if ( preg_match( '/^\s*(action|actions|question)\s*:/i', $l ) ) return false;
				return true;
			} ) );

			$c2 = trim( implode( ' ', $lines ) );
			if ( $c2 !== '' ) $paras[] = $c2;
		}

		$p1 = isset( $paras[0] ) ? (string) $paras[0] : '';
		$p2 = isset( $paras[1] ) ? (string) $paras[1] : '';

		if ( $p1 !== '' && $p2 === '' ) {
			$sentences = preg_split( '/(?<=[\.\!\?])\s+/', $p1 );
			if ( is_array( $sentences ) && count( $sentences ) >= 4 ) {
				$mid = (int) ceil( count( $sentences ) / 2 );
				$p1  = trim( implode( ' ', array_slice( $sentences, 0, $mid ) ) );
				$p2  = trim( implode( ' ', array_slice( $sentences, $mid ) ) );
			} else {
				$p2 = 'Looking ahead, the most useful step is to make this behaviour more deliberate in one real situation, then notice the impact it has on others. Keep it simple, repeatable, and visible.';
			}
		}

		if ( $p1 !== '' && $p2 !== '' ) {
			$a = strtolower( preg_replace( '/[^a-z0-9\s]/i', '', $p1 ) );
			$b = strtolower( preg_replace( '/[^a-z0-9\s]/i', '', $p2 ) );

			$pct = 0.0;
			if ( function_exists( 'similar_text' ) ) {
				similar_text( $a, $b, $pct );
			}
			if ( $pct >= 70 ) {
				$p2 = 'Looking ahead, focus on where this competency shows up under pressure and in higher-stakes moments. One small change, repeated consistently, will make your impact clearer and more reliable to the people around you.';
			}
		}

		return array( $p1, $p2 );
	}
}

if ( ! function_exists( 'icon_psy_format_engine_text_to_html' ) ) {
	function icon_psy_format_engine_text_to_html( $text ) {
		list( $p1, $p2 ) = icon_psy_narr_to_two_paragraphs( $text );
		if ( $p1 === '' && $p2 === '' ) return '';

		$html = '';
		if ( $p1 !== '' ) {
			$html .= '<p style="margin:0 0 8px;font-size:12px;color:#55627a;line-height:1.65;">' . esc_html( $p1 ) . '</p>';
		}
		if ( $p2 !== '' ) {
			$html .= '<p style="margin:0 0 8px;font-size:12px;color:#55627a;line-height:1.65;">' . esc_html( $p2 ) . '</p>';
		}
		return $html;
	}
}

if ( ! function_exists( 'icon_psy_competency_narrative_fallback' ) ) {
	function icon_psy_competency_narrative_fallback( $ctx ) {

		$comp    = isset( $ctx['competency'] ) ? (string) $ctx['competency'] : 'this competency';
		$overall = isset( $ctx['overall'] ) ? (float) $ctx['overall'] : 0.0;
		$avg_q1  = isset( $ctx['avg_q1'] ) ? (float) $ctx['avg_q1'] : 0.0;
		$avg_q2  = isset( $ctx['avg_q2'] ) ? (float) $ctx['avg_q2'] : 0.0;
		$avg_q3  = isset( $ctx['avg_q3'] ) ? (float) $ctx['avg_q3'] : 0.0;

		$self   = array_key_exists( 'self', $ctx ) ? $ctx['self'] : null;
		$others = array_key_exists( 'others', $ctx ) ? $ctx['others'] : null;

		$level = 'developing';
		if ( $overall >= 5.5 ) { $level = 'strength'; }
		elseif ( $overall >= 4.5 ) { $level = 'solid'; }

		$lens = 'consistent';
		if ( $avg_q2 + 0.4 < $avg_q1 && $avg_q2 + 0.4 < $avg_q3 ) {
			$lens = 'pressure-dip';
		} elseif ( $avg_q3 + 0.4 < $avg_q1 && $avg_q3 + 0.4 < $avg_q2 ) {
			$lens = 'rolemodel-dip';
		} elseif ( $avg_q1 + 0.4 < $avg_q2 && $avg_q1 + 0.4 < $avg_q3 ) {
			$lens = 'everyday-dip';
		}

		$gap_note = '';
		if ( $self !== null && $others !== null ) {
			$gap = (float) $others - (float) $self;
			if ( abs( $gap ) < 0.4 ) {
				$gap_note = 'Your self-view is broadly aligned with how others experience you.';
			} elseif ( $gap > 0.4 ) {
				$gap_note = 'Others are experiencing slightly more impact here than you give yourself credit for.';
			} else {
				$gap_note = 'You see slightly more impact here than others are currently reporting, which is a useful prompt to explore examples and expectations.';
			}
		}

		if ( $level === 'strength' ) {
			$p1 = "In {$comp}, feedback suggests you are creating strong, positive impact. People are likely experiencing confidence, clarity, and reliable judgement when this behaviour matters, which helps build momentum and trust.";
		} elseif ( $level === 'solid' ) {
			$p1 = "In {$comp}, feedback suggests a solid level of impact. This behaviour is showing up often enough to support effective working relationships, with a few moments where being more deliberate could increase consistency and visibility.";
		} else {
			$p1 = "In {$comp}, feedback suggests this is an important development opportunity. The impact is present at times, but it may not be showing up consistently enough yet for others to rely on it in everyday working situations.";
		}

		$p2_parts = array();

		if ( $lens === 'pressure-dip' ) {
			$p2_parts[] = 'Your ratings indicate this competency may soften under pressure. The quickest improvement comes from choosing one steadying behaviour you can repeat when stakes rise, such as slowing decisions, checking assumptions, or making your intent explicit.';
		} elseif ( $lens === 'rolemodel-dip' ) {
			$p2_parts[] = 'Your ratings indicate role-modelling is the area with the most headroom. Strengthening this is often about making the behaviour visible to others: say what “good” looks like, demonstrate it in the moment, and reinforce it consistently.';
		} elseif ( $lens === 'everyday-dip' ) {
			$p2_parts[] = 'Your ratings indicate this competency is less visible day-to-day. Building it into routine moments, such as brief check-ins, clearer expectations, or more consistent follow-through, tends to lift overall impact quickly.';
		} else {
			$p2_parts[] = 'Looking ahead, the biggest lift will come from using this competency more deliberately in one or two repeatable situations. When people can predict what you will do, they experience steadiness and trust the leadership signal.';
		}

		if ( $gap_note !== '' ) $p2_parts[] = $gap_note;

		if ( $level === 'strength' ) {
			$p2_parts[] = 'Keep it strong by using it in higher-stakes conversations, where calm clarity and timely direction make the biggest difference.';
		} elseif ( $level === 'solid' ) {
			$p2_parts[] = 'A small shift in consistency will turn this from “often effective” into “reliably effective”, especially when priorities compete.';
		} else {
			$p2_parts[] = 'Start small and repeat: one behaviour, one context, done consistently, then expand to tougher situations once it becomes automatic.';
		}

		$p2 = implode( ' ', array_map( 'trim', $p2_parts ) );
		return trim( $p1 ) . "\n\n" . trim( $p2 );
	}
}

/* -------------------------------------------------------------
 * Main shortcode output (HTML ONLY)
 * ------------------------------------------------------------- */
if ( ! function_exists( 'icon_psy_feedback_report' ) ) {

	function icon_psy_feedback_report( $atts ) {
		global $wpdb;

		if ( ! defined( 'M_PI' ) ) {
			define( 'M_PI', 3.14159265358979323846 );
		}

		$narr_engine = icon_psy_try_load_lens_narratives();

		// Inputs
		$atts = shortcode_atts(
			array( 'participant_id' => 0 ),
			$atts,
			'icon_psy_feedback_report'
		);

		$participant_id = (int) $atts['participant_id'];
		if ( ! $participant_id && isset( $_GET['participant_id'] ) ) {
			$participant_id = (int) $_GET['participant_id'];
		}

		if ( $participant_id <= 0 ) {
			return '<p>We could not find this participant. Please check the link or contact your administrator.</p>';
		}

		// Require login + ownership controls
		if ( ! is_user_logged_in() ) {
			return '<p>You need to be logged in to view this report.</p>';
		}

		$is_admin            = current_user_can( 'manage_options' );
		$effective_client_id = (int) icon_psy_get_effective_client_user_id();

		// Tables
		$participants_table = $wpdb->prefix . 'icon_psy_participants';
		$projects_table     = $wpdb->prefix . 'icon_psy_projects';
		$frameworks_table   = $wpdb->prefix . 'icon_psy_frameworks';
		$competencies_table = $wpdb->prefix . 'icon_psy_framework_competencies';
		$raters_table       = $wpdb->prefix . 'icon_psy_raters';

		// Load participant + project (include client_user_id for ownership checks)
		$participant = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					p.*,
					pr.id             AS project_id,
					pr.name           AS project_name,
					pr.client_name    AS client_name,
					pr.status         AS project_status,
					pr.client_user_id AS client_user_id,
					pr.framework_id   AS project_framework_id
				 FROM {$participants_table} p
				 LEFT JOIN {$projects_table} pr ON p.project_id = pr.id
				 WHERE p.id = %d
				 LIMIT 1",
				$participant_id
			)
		);

		if ( ! $participant ) {
			return '<p>We could not find this participant. Please check the link or contact your administrator.</p>';
		}

		// Ownership: admins allowed; clients only if they own the project
		if ( ! $is_admin ) {
			if ( $effective_client_id <= 0 ) {
				return '<p>You do not have permission to view this report.</p>';
			}
			$owner_id = isset( $participant->client_user_id ) ? (int) $participant->client_user_id : 0;
			if ( $owner_id <= 0 || $owner_id !== $effective_client_id ) {
				return '<p>You do not have permission to view this report.</p>';
			}
		}

		// Branding (client-aware; falls back to ICON)
		$brand_client_id = $effective_client_id;
		if ( $is_admin && $brand_client_id <= 0 ) {
			$brand_client_id = isset( $participant->client_user_id ) ? (int) $participant->client_user_id : 0;
		}
		$brand = icon_psy_get_branding_for_client( $brand_client_id );
		$brand_logo_url = ! empty( $brand['logo_url'] ) ? (string) $brand['logo_url'] : '';

		$participant_name = $participant->name ? $participant->name : 'Participant';
		$participant_role = $participant->role;
		$project_name     = $participant->project_name ?: '';
		$client_name      = $participant->client_name ?: '';
		$project_status   = $participant->project_status ?: '';

		// Results fetch (AUTO-DETECTED SCHEMA)
		$schema        = icon_psy_detect_results_schema();
		$results_table = $schema['table'];
		$map           = $schema['map'];
		$order_col     = $schema['order'];

		$debug_html = '';
		if ( $is_admin ) {
			$debug_html .= '<div style="margin:8px 0;padding:8px 10px;border-radius:10px;border:1px solid #e6e9f1;background:#fbfcfe;font-size:11px;color:#33415f;">';
			$debug_html .= '<strong>ICON Catalyst feedback debug</strong><br>';
			$debug_html .= 'Participant ID: ' . (int) $participant_id . '<br>';
			$debug_html .= 'Detected results table: <code>' . esc_html( $results_table ? $results_table : 'NOT FOUND' ) . '</code><br>';
			if ( $results_table ) {
				$debug_html .= 'participant_id col: <code>' . esc_html( $map['participant_id'] ? $map['participant_id'] : 'MISSING' ) . '</code><br>';
				$debug_html .= 'detail_json col: <code>' . esc_html( $map['detail_json'] ? $map['detail_json'] : 'n/a' ) . '</code><br>';
				$debug_html .= 'q1_rating col: <code>' . esc_html( $map['q1_rating'] ? $map['q1_rating'] : 'n/a' ) . '</code><br>';
				$debug_html .= 'q2_text col: <code>' . esc_html( $map['q2_text'] ? $map['q2_text'] : 'n/a' ) . '</code><br>';
				$debug_html .= 'q3_text col: <code>' . esc_html( $map['q3_text'] ? $map['q3_text'] : 'n/a' ) . '</code><br>';
			}
			$debug_html .= 'Narratives engine: <code>' . ( $narr_engine['loaded'] ? 'LOADED' : 'NOT LOADED' ) . '</code><br>';
			if ( $narr_engine['loaded'] ) {
				$debug_html .= 'Narratives path: <code>' . esc_html( $narr_engine['path'] ) . '</code><br>';
			}
			if ( ! empty( $wpdb->last_error ) ) {
				$debug_html .= '<br>Last DB error: ' . esc_html( $wpdb->last_error );
			}
			$debug_html .= '</div>';
		}

		if ( ! $results_table || empty( $map['participant_id'] ) ) {
			return $debug_html . '<p>Results table/participant key could not be detected. Please confirm your results table schema.</p>';
		}

		$join_raters = ( ! empty( $map['rater_id'] ) && icon_psy_table_exists( $raters_table ) );

		$relationship_col = 'relationship';
		if ( $join_raters ) {
			$rcols = icon_psy_get_table_columns( $raters_table );
			$relationship_col = icon_psy_pick_col( $rcols, array( 'relationship','relationship_type','relation','type' ) );
			if ( $relationship_col === '' ) $relationship_col = 'relationship';
		}

		$order_sql = "ORDER BY r.id ASC";
		if ( ! empty( $order_col ) ) {
			$order_sql = "ORDER BY r.`" . esc_sql( $order_col ) . "` ASC";
		}

		// Build SQL cleanly (avoids parse issues with ternary + concatenation)
		$select_sql = "SELECT r.*";
		$join_sql   = "";
		$from_sql   = "FROM {$results_table} r";

		if ( $join_raters ) {
			$select_sql .= ", rt.`" . esc_sql( $relationship_col ) . "` AS rater_relationship";
			$join_sql    = "LEFT JOIN {$raters_table} rt ON r.`" . esc_sql( $map['rater_id'] ) . "` = rt.id";
		}

		$sql = "
			{$select_sql}
			{$from_sql}
			{$join_sql}
			WHERE r.`" . esc_sql( $map['participant_id'] ) . "` = %d
			{$order_sql}
		";

		$raw_results = $wpdb->get_results(
			$wpdb->prepare( $sql, $participant_id )
		);

		// Normalize row fields into expected names
		$normalized = array();
		foreach ( (array) $raw_results as $r ) {
			$obj = new stdClass();
			foreach ( (array) $r as $k => $v ) {
				$obj->$k = $v;
			}

			if ( ! empty( $map['q1_rating'] ) && isset( $r->{ $map['q1_rating'] } ) ) $obj->q1_rating = $r->{ $map['q1_rating'] };
			if ( ! empty( $map['rater_id'] ) && isset( $r->{ $map['rater_id'] } ) ) $obj->rater_id = $r->{ $map['rater_id'] };
			if ( ! empty( $map['q2_text'] )   && isset( $r->{ $map['q2_text'] } ) )   $obj->q2_text   = $r->{ $map['q2_text'] };
			if ( ! empty( $map['q3_text'] )   && isset( $r->{ $map['q3_text'] } ) )   $obj->q3_text   = $r->{ $map['q3_text'] };
			if ( ! empty( $map['detail_json'] ) && isset( $r->{ $map['detail_json'] } ) ) $obj->detail_json = $r->{ $map['detail_json'] };
			if ( ! empty( $map['framework_id'] ) && isset( $r->{ $map['framework_id'] } ) ) $obj->framework_id = $r->{ $map['framework_id'] };

			if ( ! empty( $map['status'] ) && isset( $r->{ $map['status'] } ) ) $obj->status = $r->{ $map['status'] };
			if ( ! empty( $map['is_completed'] ) && isset( $r->{ $map['is_completed'] } ) ) $obj->is_completed = $r->{ $map['is_completed'] };
			if ( ! empty( $map['completed_at'] ) && isset( $r->{ $map['completed_at'] } ) ) $obj->completed_at = $r->{ $map['completed_at'] };

			$normalized[] = $obj;
		}

		// Filter completed robustly
		$results = array();
		foreach ( (array) $normalized as $row ) {
			if ( icon_psy_row_is_completed_detected( $row, $map ) ) {
				$results[] = $row;
			}
		}

		// If filtered everything out but raw exists, fall back to raw
		if ( empty( $results ) && ! empty( $normalized ) ) {
			$results = $normalized;
			if ( $is_admin ) {
				$debug_html .= '<div style="margin:8px 0;padding:8px 10px;border-radius:10px;border:1px solid #fde68a;background:#fffbeb;font-size:11px;color:#92400e;">';
				$debug_html .= '<strong>Notice:</strong> Completion filter excluded all rows. Showing raw rows to avoid a blank report.';
				$debug_html .= '</div>';
			}
		}

		// Nothing at all
		if ( empty( $results ) ) {
			ob_start();
			?>
			<div class="icon-psy-report-wrapper" style="max-width:960px;margin:0 auto;padding:24px 18px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
				<?php echo $debug_html; ?>
				<div class="icon-psy-report-card">
					<h2 class="icon-psy-report-title" style="margin:0 0 6px;">ICON Catalyst Feedback Report</h2>
					<p class="icon-psy-report-sub" style="margin:0;">
						Participant: <strong><?php echo esc_html( $participant_name ); ?></strong>
						<?php if ( $participant_role ) : ?>&nbsp;·&nbsp;Role: <?php echo esc_html( $participant_role ); ?><?php endif; ?>
					</p>
					<p style="margin:10px 0 0;font-size:13px;color:#6b7280;">
						No feedback rows were found for this participant in the detected results table.
					</p>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		// -----------------------------
		// Aggregate
		// -----------------------------
		$rater_ids      = array();
		$strengths      = array();
		$dev_opps       = array();
		$category_stats = array();
		$self_sum       = 0; $self_count = 0;
		$others_sum     = 0; $others_count = 0;

		$heat_agg       = array();
		$overall_sum    = 0; $overall_count = 0;

		foreach ( $results as $row ) {

			if ( ! empty( $row->rater_id ) ) {
				$rater_ids[ (int) $row->rater_id ] = true;
			}

			if ( ! empty( $row->q2_text ) ) { $strengths[] = $row->q2_text; }
			if ( ! empty( $row->q3_text ) ) { $dev_opps[]  = $row->q3_text; }

			if ( isset( $row->q1_rating ) && ! is_null( $row->q1_rating ) && $row->q1_rating !== '' ) {
				$overall_sum   += (float) $row->q1_rating;
				$overall_count += 1;
			}

			$rel_raw = '';
			if ( isset( $row->rater_relationship ) && $row->rater_relationship !== '' ) {
				$rel_raw = strtolower( trim( (string) $row->rater_relationship ) );
			}

			$rel_key = $rel_raw;
			if ( $rel_key === '' ) {
				$rel_key = 'other';
			} elseif ( strpos( $rel_key, 'manager' ) !== false ) {
				$rel_key = 'manager';
			} elseif ( strpos( $rel_key, 'self' ) !== false ) {
				$rel_key = 'self';
			} elseif ( strpos( $rel_key, 'report' ) !== false ) {
				$rel_key = 'direct_report';
			} elseif ( strpos( $rel_key, 'peer' ) !== false || strpos( $rel_key, 'colleague' ) !== false ) {
				$rel_key = 'peer';
			}

			if ( ! isset( $category_stats[ $rel_key ] ) ) {
				$label = 'Other';
				switch ( $rel_key ) {
					case 'manager':       $label = 'Manager'; break;
					case 'self':          $label = 'Self-rating'; break;
					case 'peer':          $label = 'Peers and colleagues'; break;
					case 'direct_report': $label = 'Direct reports'; break;
				}
				$category_stats[ $rel_key ] = array(
					'label' => $label,
					'count' => 0,
					'sum'   => 0,
					'avg'   => null,
				);
			}

			if ( isset( $row->q1_rating ) && ! is_null( $row->q1_rating ) && $row->q1_rating !== '' ) {
				$category_stats[ $rel_key ]['count'] += 1;
				$category_stats[ $rel_key ]['sum']   += (float) $row->q1_rating;

				if ( $rel_key === 'self' ) {
					$self_sum   += (float) $row->q1_rating;
					$self_count += 1;
				} else {
					$others_sum   += (float) $row->q1_rating;
					$others_count += 1;
				}
			}

			// detail_json: per competency q1/q2/q3
			if ( ! empty( $row->detail_json ) ) {
				$detail = json_decode( $row->detail_json, true );
				if ( is_array( $detail ) ) {
					foreach ( $detail as $entry ) {
						// competency id (accept multiple key names)
						$cid = 0;
						foreach ( array('competency_id','competencyId','competency','id') as $k ) {
							if ( isset( $entry[$k] ) && $entry[$k] !== '' ) { $cid = (int) $entry[$k]; break; }
						}
						if ( $cid <= 0 ) { continue; }

						// lens scores (accept multiple key names)
						$pick_num = function( $arr, $keys ) {
							foreach ( $keys as $k ) {
								if ( isset( $arr[$k] ) && $arr[$k] !== '' && $arr[$k] !== null && is_numeric( $arr[$k] ) ) {
									return (float) $arr[$k];
								}
							}
							return null;
						};

						// Try common variants
						$q1 = $pick_num( $entry, array('q1','avg_q1','everyday','everyday_impact','lens1','q1_rating') );
						$q2 = $pick_num( $entry, array('q2','avg_q2','pressure','under_pressure','lens2','q2_rating') );
						$q3 = $pick_num( $entry, array('q3','avg_q3','rolemodel','role_modelling','role_modeling','lens3','q3_rating') );

						// If nested "answers" exists, fall back to it
						if ( ($q1 === null || $q2 === null || $q3 === null) && isset( $entry['answers'] ) && is_array( $entry['answers'] ) ) {
							$q1 = $q1 ?? $pick_num( $entry['answers'], array('q1','everyday','everyday_impact') );
							$q2 = $q2 ?? $pick_num( $entry['answers'], array('q2','pressure','under_pressure') );
							$q3 = $q3 ?? $pick_num( $entry['answers'], array('q3','rolemodel','role_modelling','role_modeling') );
						}

						if ( $q1 === null || $q2 === null || $q3 === null ) { continue; }

						if ( ! isset( $heat_agg[ $cid ] ) ) {
							$heat_agg[ $cid ] = array(
								'sum_q1'               => 0,
								'sum_q2'               => 0,
								'sum_q3'               => 0,
								'count'                => 0,

								'overall_self_sum'     => 0,
								'overall_self_count'   => 0,
								'overall_others_sum'   => 0,
								'overall_others_count' => 0,

								'overall_sum'          => 0,
								'overall_sumsq'        => 0,
							);
						}

						$heat_agg[ $cid ]['sum_q1'] += $q1;
						$heat_agg[ $cid ]['sum_q2'] += $q2;
						$heat_agg[ $cid ]['sum_q3'] += $q3;
						$heat_agg[ $cid ]['count']  += 1;

						$comp_overall = ( $q1 + $q2 + $q3 ) / 3;

						$heat_agg[ $cid ]['overall_sum']   += $comp_overall;
						$heat_agg[ $cid ]['overall_sumsq'] += ( $comp_overall * $comp_overall );

						if ( $rel_key === 'self' ) {
							$heat_agg[ $cid ]['overall_self_sum']   += $comp_overall;
							$heat_agg[ $cid ]['overall_self_count'] += 1;
						} else {
							$heat_agg[ $cid ]['overall_others_sum']   += $comp_overall;
							$heat_agg[ $cid ]['overall_others_count'] += 1;
						}
					}
				}
			}
		}

		$num_raters = count( $rater_ids );
		foreach ( $category_stats as $key => $data ) {
			$category_stats[ $key ]['avg'] = ( $data['count'] > 0 ) ? ( $data['sum'] / $data['count'] ) : null;
		}

		$overall_avg = $overall_count > 0 ? $overall_sum / $overall_count : null;
		$self_avg    = $self_count > 0 ? $self_sum / $self_count : null;
		$others_avg  = $others_count > 0 ? $others_sum / $others_count : null;
		if ( $is_admin ) {
			$debug_html .= '<div style="margin:10px 0;padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;font-size:12px;">';
			$debug_html .= '<strong>DEBUG: Category stats</strong><br><pre style="margin:8px 0 0;white-space:pre-wrap;">' . esc_html( print_r( $category_stats, true ) ) . '</pre>';
			$debug_html .= '</div>';

			// Show 3 sample detail_json payloads (first 3 rows)
			$sample = array_slice( (array) $results, 0, 3 );
			$debug_html .= '<div style="margin:10px 0;padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;font-size:12px;">';
			$debug_html .= '<strong>DEBUG: Sample detail_json (first 3 rows)</strong><br>';
			foreach ( $sample as $i => $row ) {
				$dj = isset( $row->detail_json ) ? (string) $row->detail_json : '';
				$debug_html .= '<div style="margin:8px 0;"><strong>Row ' . ( $i + 1 ) . '</strong><pre style="margin:4px 0 0;white-space:pre-wrap;max-height:160px;overflow:auto;">' . esc_html( $dj ) . '</pre></div>';
			}
			$debug_html .= '</div>';
		}


		// Framework name
		$framework_name     = '';
		$first_framework_id = 0;

		foreach ( $results as $row ) {
			if ( ! empty( $row->framework_id ) ) { $first_framework_id = (int) $row->framework_id; break; }
		}
		if ( $first_framework_id <= 0 && ! empty( $participant->project_framework_id ) ) {
			$first_framework_id = (int) $participant->project_framework_id;
		}

		if ( $first_framework_id > 0 ) {
			$fw = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT name FROM {$frameworks_table} WHERE id = %d LIMIT 1",
					$first_framework_id
				)
			);
			if ( $fw && ! empty( $fw->name ) ) { $framework_name = $fw->name; }
		}

		// Heatmap rows
		$heatmap_rows = array();
		if ( ! empty( $heat_agg ) ) {

			$competency_ids = array_map( 'intval', array_keys( $heat_agg ) );
			$competency_ids = array_values( array_filter( $competency_ids ) );

			if ( ! empty( $competency_ids ) ) {

				$cols = icon_psy_get_table_columns( $competencies_table );
				$cols_lower = array_map( 'strtolower', $cols );

				$has_desc = in_array( 'description', $cols_lower, true );
				$has_lens = in_array( 'lens_code',   $cols_lower, true );

				$select_bits = array( 'id', 'name' );
				if ( $has_desc ) { $select_bits[] = 'description'; }
				if ( $has_lens ) { $select_bits[] = 'lens_code'; }

				$select_sql = implode( ', ', $select_bits );
				$placeholders = implode( ',', array_fill( 0, count( $competency_ids ), '%d' ) );

				$sqlc = "
					SELECT {$select_sql}
					FROM {$competencies_table}
					WHERE id IN ($placeholders)
					ORDER BY id ASC
				";

				$args     = array_merge( array( $sqlc ), $competency_ids );
				$prepared = call_user_func_array( array( $wpdb, 'prepare' ), $args );

				$competencies = $wpdb->get_results( $prepared );

				if ( ! empty( $competencies ) ) {
					foreach ( $competencies as $comp ) {
						$cid = (int) $comp->id;
						if ( empty( $heat_agg[ $cid ] ) ) { continue; }

						$agg   = $heat_agg[ $cid ];
						$count = max( 1, (int) $agg['count'] );

						$avg_q1  = $agg['sum_q1'] / $count;
						$avg_q2  = $agg['sum_q2'] / $count;
						$avg_q3  = $agg['sum_q3'] / $count;
						$overall = ( $avg_q1 + $avg_q2 + $avg_q3 ) / 3;

						$self_comp_avg   = $agg['overall_self_count'] > 0 ? ( $agg['overall_self_sum'] / $agg['overall_self_count'] ) : null;
						$others_comp_avg = $agg['overall_others_count'] > 0 ? ( $agg['overall_others_sum'] / $agg['overall_others_count'] ) : null;

						$n  = (int) $agg['count'];
						$sd = null;
						if ( $n >= 2 ) {
							$sum   = (float) $agg['overall_sum'];
							$sumsq = (float) $agg['overall_sumsq'];
							$var   = ( $sumsq - ( $sum * $sum ) / $n ) / ( $n - 1 );
							$sd    = sqrt( max( 0, $var ) );
						}

						$heatmap_rows[] = array(
							'id'          => $cid,
							'name'        => (string) $comp->name,
							'description' => ( $has_desc && isset( $comp->description ) ) ? (string) $comp->description : '',
							'lens_code'   => ( $has_lens && isset( $comp->lens_code ) && $comp->lens_code !== '' ) ? (string) $comp->lens_code : 'CLARITY',
							'avg_q1'      => $avg_q1,
							'avg_q2'      => $avg_q2,
							'avg_q3'      => $avg_q3,
							'overall'     => $overall,
							'self_avg'    => $self_comp_avg,
							'others_avg'  => $others_comp_avg,
							'n'           => $n,
							'sd'          => $sd,
						);
					}
				}
			}
		}

		// Keep heatmap_rows in ORIGINAL order (framework / ID order).
		// Create a sorted copy for “Top strengths” + “Development priorities”.
		$heatmap_rows_sorted = $heatmap_rows;

		if ( ! empty( $heatmap_rows_sorted ) ) {
			usort(
				$heatmap_rows_sorted,
				function( $a, $b ) {
					if ( $a['overall'] === $b['overall'] ) { return 0; }
					return ( $a['overall'] > $b['overall'] ) ? -1 : 1;
				}
			);
		}

		// Summary narrative
		$summary_level = '';
		if ( ! is_null( $overall_avg ) ) {
			if ( $overall_avg >= 5.5 )      { $summary_level = 'strong'; }
			elseif ( $overall_avg >= 4.5 )  { $summary_level = 'balanced'; }
			else                            { $summary_level = 'developing'; }
		}

		$summary_sentence = '';
		if ( $summary_level === 'strong' ) {
			$summary_sentence = 'Overall feedback suggests you are creating strong leadership impact, with consistently positive perceptions across most areas.';
		} elseif ( $summary_level === 'balanced' ) {
			$summary_sentence = 'Overall feedback shows a solid, balanced profile, with clear strengths and a small number of priorities that will lift impact further.';
		} elseif ( $summary_level === 'developing' ) {
			$summary_sentence = 'Feedback points to a few clear opportunities to build greater consistency and increase leadership impact day-to-day.';
		}

		$alignment_sentence = '';
		if ( ! is_null( $self_avg ) && ! is_null( $others_avg ) ) {
			$gap = $others_avg - $self_avg;

			if ( abs( $gap ) < 0.4 ) {
				$alignment_sentence = 'Your self-view is broadly aligned with how others experience you.';
			} elseif ( $gap > 0.4 ) {
				$alignment_sentence = 'Others are experiencing more impact than you are giving yourself credit for. Capture examples so you can use this strength more deliberately.';
			} else {
				$alignment_sentence = 'You may be seeing more impact than others are currently experiencing. Use examples and expectations to calibrate, then agree one visible behaviour shift.';
			}
		}

		$top_strengths = array();
		$top_devs      = array();

		if ( ! empty( $heatmap_rows_sorted ) ) {
			$top_strengths = array_slice( $heatmap_rows_sorted, 0, 3 );
			$reversed      = array_reverse( $heatmap_rows_sorted );
			$top_devs      = array_slice( $reversed, 0, 3 );
		}

		// Lens helpers (used in blind spots and awareness)
		if ( ! function_exists( 'icon_psy_infer_lens_key' ) ) {
			function icon_psy_infer_lens_key( $avg_q1, $avg_q2, $avg_q3 ) {
				$avg_q1 = (float) $avg_q1;
				$avg_q2 = (float) $avg_q2;
				$avg_q3 = (float) $avg_q3;

				if ( $avg_q2 + 0.4 < $avg_q1 && $avg_q2 + 0.4 < $avg_q3 ) return 'UNDER_PRESSURE';
				if ( $avg_q3 + 0.4 < $avg_q1 && $avg_q3 + 0.4 < $avg_q2 ) return 'ROLE_MODELLING';
				if ( $avg_q1 + 0.4 < $avg_q2 && $avg_q1 + 0.4 < $avg_q3 ) return 'EVERYDAY_IMPACT';
				return 'BALANCED';
			}
		}

		if ( ! function_exists( 'icon_psy_lens_label' ) ) {
			function icon_psy_lens_label( $lens_key ) {
				switch ( (string) $lens_key ) {
					case 'UNDER_PRESSURE':  return 'Under pressure';
					case 'ROLE_MODELLING':  return 'Role-modelling';
					case 'EVERYDAY_IMPACT': return 'Everyday impact';
					default:                return 'Balanced pattern';
				}
			}
		}

		if ( ! function_exists( 'icon_psy_blindspot_lens_copy' ) ) {
			function icon_psy_blindspot_lens_copy( $type, $lens_key ) {
				$type = (string) $type;
				$lens_key = (string) $lens_key;

				if ( $type === 'overrate' ) {
					switch ( $lens_key ) {
						case 'UNDER_PRESSURE':
							return 'Under pressure, the impact may be landing differently than intended. Ask for one recent example where pace or stakes increased, then agree one steadying behaviour you will repeat (pause, clarify priorities, check understanding).';
						case 'ROLE_MODELLING':
							return 'The biggest gap is likely about visibility. Make the behaviour explicit, model it in the moment, and reinforce it consistently so others can reliably recognise the standard you intend.';
						case 'EVERYDAY_IMPACT':
							return 'Day-to-day, the behaviour may not be showing up as consistently as you think. Build it into routine moments (check-ins, follow-through, clearer expectations) so the signal is repeated and easy to notice.';
						default:
							return 'Treat this as a calibration opportunity. Invite examples, compare expectations, and agree one small change that makes your intent more visible to others in real work situations.';
					}
				}

				switch ( $lens_key ) {
					case 'UNDER_PRESSURE':
						return 'Others are seeing strength here, especially when things get busy. Name what you do at your best under pressure and use it intentionally in the next high-stakes moment so you build confidence in it.';
					case 'ROLE_MODELLING':
						return 'Others are noticing the standard you set. Make it deliberate: explain what “good” looks like, demonstrate it, and use that consistency to set tone for the team.';
					case 'EVERYDAY_IMPACT':
						return 'Others experience more steady impact than you give yourself credit for. Notice the small day-to-day behaviours you repeat, and keep them visible so your reliability continues to compound.';
					default:
						return 'This is a strength you may be under-recognising. Capture what “effective” looks like in practice (specific behaviours and examples), then lean into it in moments where it will have the highest return.';
				}
			}
		}

		// Blind spot analysis
		$blind_overrate  = array();
		$blind_underrate = array();
		$blind_threshold = 0.6;

		if ( ! empty( $heatmap_rows ) ) {
			foreach ( $heatmap_rows as $row ) {
				if ( is_null( $row['self_avg'] ) || is_null( $row['others_avg'] ) ) { continue; }

				$gap = $row['others_avg'] - $row['self_avg'];

				$lens_key   = icon_psy_infer_lens_key( $row['avg_q1'], $row['avg_q2'], $row['avg_q3'] );
				$lens_label = icon_psy_lens_label( $lens_key );

				if ( $gap <= -$blind_threshold ) {
					$blind_overrate[] = array(
						'name'       => $row['name'],
						'self'       => $row['self_avg'],
						'others'     => $row['others_avg'],
						'gap'        => $gap,
						'lens_key'   => $lens_key,
						'lens_label' => $lens_label,
					);
				} elseif ( $gap >= $blind_threshold ) {
					$blind_underrate[] = array(
						'name'       => $row['name'],
						'self'       => $row['self_avg'],
						'others'     => $row['others_avg'],
						'gap'        => $gap,
						'lens_key'   => $lens_key,
						'lens_label' => $lens_label,
					);
				}
			}

			$sort_gap = function( $a, $b ) {
				$aa = abs( $a['gap'] ); $bb = abs( $b['gap'] );
				if ( $aa === $bb ) { return 0; }
				return ( $aa > $bb ) ? -1 : 1;
			};

			if ( ! empty( $blind_overrate ) ) {
				usort( $blind_overrate, $sort_gap );
				$blind_overrate = array_slice( $blind_overrate, 0, 4 );
			}
			if ( ! empty( $blind_underrate ) ) {
				usort( $blind_underrate, $sort_gap );
				$blind_underrate = array_slice( $blind_underrate, 0, 4 );
			}
		}

		// Radar data (HTML SVG only)
		$radar_competencies = array();
		if ( ! empty( $heatmap_rows ) ) {
			foreach ( $heatmap_rows as $row ) {
				if ( ! is_null( $row['others_avg'] ) || ! is_null( $row['self_avg'] ) ) {
					$radar_competencies[] = $row;
				}
			}
		}
		$has_radar = count( $radar_competencies ) >= 3;

		// Facilitator Insights
		$lens_counts = array(
			'EVERYDAY_IMPACT' => 0,
			'UNDER_PRESSURE'  => 0,
			'ROLE_MODELLING'  => 0,
			'BALANCED'        => 0,
		);

		$top_dips      = array();
		$top_abs_gaps  = array();
		$focus_list    = array();

		if ( ! empty( $heatmap_rows ) ) {
			foreach ( $heatmap_rows as $row ) {

				$avg_q1 = (float) $row['avg_q1'];
				$avg_q2 = (float) $row['avg_q2'];
				$avg_q3 = (float) $row['avg_q3'];

				$lens_key = icon_psy_infer_lens_key( $avg_q1, $avg_q2, $avg_q3 );
				if ( ! isset( $lens_counts[ $lens_key ] ) ) $lens_counts[ $lens_key ] = 0;
				$lens_counts[ $lens_key ]++;

				$min_lens = min( $avg_q1, $avg_q2, $avg_q3 );
				$max_lens = max( $avg_q1, $avg_q2, $avg_q3 );
				$dip_mag  = $max_lens - $min_lens;

				$top_dips[] = array(
					'name'       => (string) $row['name'],
					'lens_key'   => $lens_key,
					'lens_label' => icon_psy_lens_label( $lens_key ),
					'dip'        => $dip_mag,
					'avg_q1'     => $avg_q1,
					'avg_q2'     => $avg_q2,
					'avg_q3'     => $avg_q3,
					'overall'    => (float) $row['overall'],
				);

				$abs_gap = null;
				if ( ! is_null( $row['self_avg'] ) && ! is_null( $row['others_avg'] ) ) {
					$abs_gap = abs( (float) $row['others_avg'] - (float) $row['self_avg'] );
					$top_abs_gaps[] = array(
						'name'    => (string) $row['name'],
						'gap'     => $abs_gap,
						'self'    => (float) $row['self_avg'],
						'others'  => (float) $row['others_avg'],
						'overall' => (float) $row['overall'],
					);
				}

				$score = ( 7.0 - (float) $row['overall'] ) * 1.0 + $dip_mag * 0.7;
				if ( $abs_gap !== null ) $score += $abs_gap * 0.8;

				$focus_list[] = array(
					'name'       => (string) $row['name'],
					'score'      => $score,
					'overall'    => (float) $row['overall'],
					'lens_label' => icon_psy_lens_label( $lens_key ),
					'dip'        => $dip_mag,
					'gap'        => $abs_gap,
				);
			}

			usort( $top_dips, function( $a, $b ){
				if ( $a['dip'] === $b['dip'] ) return 0;
				return ( $a['dip'] > $b['dip'] ) ? -1 : 1;
			} );
			$top_dips = array_slice( $top_dips, 0, 5 );

			if ( ! empty( $top_abs_gaps ) ) {
				usort( $top_abs_gaps, function( $a, $b ){
					if ( $a['gap'] === $b['gap'] ) return 0;
					return ( $a['gap'] > $b['gap'] ) ? -1 : 1;
				} );
				$top_abs_gaps = array_slice( $top_abs_gaps, 0, 5 );
			}

			usort( $focus_list, function( $a, $b ){
				if ( $a['score'] === $b['score'] ) return 0;
				return ( $a['score'] > $b['score'] ) ? -1 : 1;
			} );
			$focus_list = array_slice( $focus_list, 0, 3 );
		}

		$dominant_lens_key = 'BALANCED';
		$dominant_lens_val = -1;
		foreach ( $lens_counts as $k => $v ) {
			if ( $v > $dominant_lens_val ) {
				$dominant_lens_val = $v;
				$dominant_lens_key = $k;
			}
		}
		$dominant_lens_label = icon_psy_lens_label( $dominant_lens_key );

		// Rater group agreement (range)
		$group_avgs = array();
		foreach ( (array) $category_stats as $k => $data ) {
			if ( isset( $data['avg'] ) && $data['avg'] !== null ) {
				$group_avgs[ $k ] = (float) $data['avg'];
			}
		}
		$agreement_range   = null;
		$agreement_highest = '';
		$agreement_lowest  = '';
		if ( count( $group_avgs ) >= 2 ) {
			$maxv = max( $group_avgs );
			$minv = min( $group_avgs );
			$agreement_range = $maxv - $minv;

			$maxk = array_search( $maxv, $group_avgs, true );
			$mink = array_search( $minv, $group_avgs, true );

			$agreement_highest = isset( $category_stats[ $maxk ]['label'] ) ? (string) $category_stats[ $maxk ]['label'] : '';
			$agreement_lowest  = isset( $category_stats[ $mink ]['label'] ) ? (string) $category_stats[ $mink ]['label'] : '';
		}

		// Debrief prompts (adaptive)
		$debrief_prompts = array();
		$debrief_prompts[] = 'What feedback here feels most accurate, and what surprises you? Which real examples explain both?';

		if ( ! is_null( $self_avg ) && ! is_null( $others_avg ) ) {
			if ( abs( $self_avg - $others_avg ) >= 0.6 ) {
				$debrief_prompts[] = 'Where do you think your intent is not landing as clearly as you expect? What would others say they need to see more consistently?';
			} else {
				$debrief_prompts[] = 'Self and others are broadly aligned. Where can you “turn up the volume” on a strength so it becomes even more visible in key moments?';
			}
		} else {
			$debrief_prompts[] = 'Where do you think your impact is strongest today, and what evidence supports that from others’ comments?';
		}

		if ( $agreement_range !== null ) {
			if ( $agreement_range >= 0.9 ) {
				$debrief_prompts[] = 'Different groups are experiencing you differently (range is meaningful). What changes between those relationships that could explain the gap?';
			} elseif ( $agreement_range >= 0.5 ) {
				$debrief_prompts[] = 'There is some variation across rater groups. Which contexts make you most consistent, and which make you less predictable?';
			} else {
				$debrief_prompts[] = 'Rater groups are fairly consistent. What habits are you repeating that make your leadership signal reliable?';
			}
		}

		if ( ! empty( $top_dips ) ) {
			$d = $top_dips[0];
			if ( $d['lens_key'] === 'UNDER_PRESSURE' ) {
				$debrief_prompts[] = 'Under pressure appears to be a weaker lens in places. What happens to pace, tone, or clarity when workload spikes — and what single “steadying behaviour” will you repeat?';
			} elseif ( $d['lens_key'] === 'ROLE_MODELLING' ) {
				$debrief_prompts[] = 'Role-modelling is a key theme in places. What standard are you expecting, and how can you make it more visible in the moment (say it, show it, reinforce it)?';
			} elseif ( $d['lens_key'] === 'EVERYDAY_IMPACT' ) {
				$debrief_prompts[] = 'Everyday impact is less consistent in places. What simple routines (check-ins, follow-through, clarity) would make the behaviour show up more often?';
			}
		}

		if ( ! empty( $blind_overrate ) ) {
			$debrief_prompts[] = 'Pick one blind-spot competency. What would “good evidence” look like from others over the next 2 weeks that confirms the shift is working?';
		}
		if ( ! empty( $focus_list ) ) {
			$debrief_prompts[] = 'From the focus list, choose one item. What is the smallest visible behaviour change you can test this week, and who will you ask for feedback immediately after?';
		}
		$debrief_prompts = array_slice( $debrief_prompts, 0, 7 );

		// HERO variables
		$report_title           = 'Feedback Report';
		$summary_anchor_id      = 'icon-sec-summary';
		$participant_role_label = $participant_role ? (string) $participant_role : '';
		$completed_raters       = (int) $num_raters;

		$status_label = '';
		if ( ! empty( $project_status ) ) {
			$status_label = ucwords( str_replace( array('_','-'), ' ', (string) $project_status ) );
		} else {
			$status_label = 'In progress';
		}

		// Output HTML
		ob_start();
		$css = icon_psy_feedback_report_css( $brand );
		echo '<style>' . $css . '</style>';
		?>
		<div id="icon-report-top" class="icon-psy-report-wrapper is-screen" style="max-width:960px;margin:0 auto;padding:24px 18px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
			<?php echo $debug_html; ?>

			<div class="icon-psy-page">

				<!-- HERO -->
				<div class="icon-psy-hero">
					<div class="icon-psy-hero-inner">

						<div class="icon-psy-hero-avatar">
							<?php if ( ! empty( $brand_logo_url ) ) : ?>
								<img src="<?php echo esc_url( $brand_logo_url ); ?>" alt="Client logo">
							<?php else : ?>
								<span style="font-weight:800;">iT</span>
							<?php endif; ?>
						</div>

						<div>
							<div class="icon-psy-hero-kicker">ICON CATALYST • FEEDBACK REPORT</div>
							<h1 class="icon-psy-hero-title"><?php echo esc_html( $report_title ); ?></h1>
							<p class="icon-psy-hero-sub">
								<?php echo esc_html( $project_name ); ?>
								<?php if ( ! empty( $client_name ) ) : ?> • <?php echo esc_html( $client_name ); ?><?php endif; ?>
							</p>
						</div>

						<div class="icon-psy-hero-actions">
							<?php if ( ! empty( $summary_anchor_id ) ) : ?>
								<a class="icon-psy-hero-btn ghost" href="#<?php echo esc_attr( $summary_anchor_id ); ?>">Summary</a>
							<?php endif; ?>
						</div>

					</div>

					<div class="icon-psy-hero-chips">
						<?php if ( ! empty( $status_label ) ) : ?>
							<span class="icon-psy-chip">Status: <strong><?php echo esc_html( $status_label ); ?></strong></span>
						<?php endif; ?>

						<span class="icon-psy-chip">Completed raters: <strong><?php echo (int) $completed_raters; ?></strong></span>

						<?php if ( ! empty( $framework_name ) ) : ?>
							<span class="icon-psy-chip">Framework: <strong><?php echo esc_html( $framework_name ); ?></strong></span>
						<?php endif; ?>

						<?php if ( ! empty( $participant_name ) ) : ?>
							<span class="icon-psy-chip">Participant: <strong><?php echo esc_html( $participant_name ); ?></strong></span>
						<?php endif; ?>

						<?php if ( ! empty( $participant_role_label ) ) : ?>
							<span class="icon-psy-chip">Role: <strong><?php echo esc_html( $participant_role_label ); ?></strong></span>
						<?php endif; ?>
					</div>
				</div>

				<!-- CONTENT WRAPPER -->
				<div class="icon-psy-content">

					<!-- HOW TO READ -->
					<div class="icon-psy-report-card" id="icon-sec-how">
						<div class="icon-psy-section-head">
							<h3 class="icon-psy-section-title">How to read this report</h3>
							<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
						</div>
						<p class="icon-psy-section-sub">
							Designed to give a clear, practical view of how leadership is experienced by others. Use it as a development conversation, not a scorecard.
						</p>

						<div class="icon-psy-how-grid">
							<div class="icon-psy-how-card">
								<p class="icon-psy-how-title">Summary and overview</p>
								<p class="icon-psy-how-line">Overall impression, key strengths, and priority development themes.</p>
								<a class="icon-psy-how-chip" href="#icon-sec-summary">Start here</a>
							</div>

							<div class="icon-psy-how-card">
								<p class="icon-psy-how-title">Facilitator insights</p>
								<p class="icon-psy-how-line">Patterns, focus list, and debrief prompts that turn the data into action.</p>
								<a class="icon-psy-how-chip" href="#icon-sec-insights">Use in debrief</a>
							</div>

							<div class="icon-psy-how-card">
								<p class="icon-psy-how-title">Heatmap and rater groups</p>
								<p class="icon-psy-how-line">Average scores across everyday impact, under pressure, and role-modelling, plus group perspectives.</p>
								<a class="icon-psy-how-chip" href="#icon-sec-heatmap">Look for patterns</a>
							</div>

							<div class="icon-psy-how-card">
								<p class="icon-psy-how-title">Radar wheel</p>
								<p class="icon-psy-how-line">Self vs others across competencies, to spot gaps and alignment.</p>
								<a class="icon-psy-how-chip" href="#icon-sec-radar">Scan quickly</a>
							</div>

							<div class="icon-psy-how-card">
								<p class="icon-psy-how-title">Competency detail</p>
								<p class="icon-psy-how-line">Two-paragraph summary per competency, focused on impact now and what strengthens it next.</p>
								<a class="icon-psy-how-chip" href="#icon-sec-competency">Go deeper</a>
							</div>

							<div class="icon-psy-how-card">
								<p class="icon-psy-how-title">Blind spots</p>
								<p class="icon-psy-how-line">Where intention and impact may not be aligned, based on consistent gaps.</p>
								<a class="icon-psy-how-chip" href="#icon-sec-blind">Be curious</a>
							</div>

							<div class="icon-psy-how-card">
								<p class="icon-psy-how-title">Narrative feedback</p>
								<p class="icon-psy-how-line">Anonymised strengths and development comments to support constructive discussion.</p>
								<a class="icon-psy-how-chip" href="#icon-sec-narrative">Use examples</a>
							</div>
						</div>
					</div>

					<!-- COMPETENCY MODEL OVERVIEW -->
					<div class="icon-psy-report-card" id="icon-sec-model">
						<div class="icon-psy-section-head">
							<h3 class="icon-psy-section-title">Leadership competency model for this report</h3>
							<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
						</div>
						<p class="icon-psy-section-sub">
							These are the leadership competencies explored in this feedback.
						</p>

						<?php if ( empty( $heatmap_rows ) ) : ?>
							<p class="icon-psy-empty">Competency-level data is not yet available.</p>
						<?php else : ?>
							<div class="icon-psy-competency-overview-grid">
								<?php foreach ( $heatmap_rows as $row ) :
									$desc = trim( (string) $row['description'] );
									$overall = isset( $row['overall'] ) ? (float) $row['overall'] : 0;
									$overall_pct = max( 0, min( 100, round( ( $overall / 7 ) * 100 ) ) );
								?>
									<div class="icon-psy-competency-overview-card">
										<p class="icon-psy-competency-summary-name"><?php echo esc_html( $row['name'] ); ?></p>
										<div style="font-size:12px;color:#55627a;line-height:1.65;margin-bottom:6px;">
											<?php echo $desc !== '' ? esc_html( $desc ) : 'This competency looks at how consistently this behaviour shows up in work.'; ?>
										</div>
										<div class="icon-psy-mini-bar-row">
											<span class="icon-psy-mini-bar-label">Focus</span>
											<div class="icon-psy-mini-bar-track">
												<div class="icon-psy-mini-bar others" style="width: <?php echo esc_attr( $overall_pct ); ?>%;"></div>
											</div>
										</div>
										<div style="margin-top:2px;font-size:10px;color:#6b7280;">
											Shaded bar shows the overall emphasis of this competency in the feedback (1 to 7 scale).
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>

					<!-- SUMMARY -->
					<div class="icon-psy-report-card" id="icon-sec-summary">
						<div class="icon-psy-section-head">
							<h3 class="icon-psy-section-title">Summary and overview</h3>
							<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
						</div>
						<p class="icon-psy-section-sub">
							A high-level view of perceived impact, key strengths, and development priorities.
						</p>

						<div class="icon-psy-summary-grid">
							<div class="icon-psy-summary-box">
								<div class="icon-psy-summary-label">Overall impression (all raters)</div>
								<div class="icon-psy-summary-metric">
									<?php echo is_null( $overall_avg ) ? 'n/a' : esc_html( number_format( $overall_avg, 1 ) . ' / 7' ); ?>
								</div>
								<?php if ( $summary_sentence ) : ?>
									<div style="margin-top:8px;font-size:13px;line-height:1.6;"><?php echo esc_html( $summary_sentence ); ?></div>
								<?php endif; ?>
								<div class="icon-psy-summary-tag">
									<?php
									if ( $summary_level === 'strong' )        echo 'Overall impression: Strong profile';
									elseif ( $summary_level === 'balanced' )  echo 'Overall impression: Balanced profile';
									elseif ( $summary_level === 'developing' )echo 'Overall impression: Developing profile';
									else                                      echo 'Overall impression: Insufficient data';
									?>
								</div>
							</div>

							<div class="icon-psy-summary-box">
								<div class="icon-psy-summary-label">Self vs others</div>
								<?php if ( is_null( $self_avg ) || is_null( $others_avg ) ) : ?>
									<div style="font-size:13px;color:#6b7280;">
										A full self vs others comparison is not yet available.
									</div>
								<?php else : ?>
									<div style="display:flex;gap:16px;align-items:flex-end;">
										<div>
											<div style="font-size:11px;color:#6b7280;">Self</div>
											<div style="font-size:18px;font-weight:900;color:#0b1220;"><?php echo esc_html( number_format( $self_avg, 1 ) . ' / 7' ); ?></div>
										</div>
										<div>
											<div style="font-size:11px;color:#6b7280;">Others</div>
											<div style="font-size:18px;font-weight:900;color:#0b1220;"><?php echo esc_html( number_format( $others_avg, 1 ) . ' / 7' ); ?></div>
										</div>
									</div>
									<?php if ( $alignment_sentence ) : ?>
										<div style="margin-top:8px;font-size:13px;line-height:1.6;"><?php echo esc_html( $alignment_sentence ); ?></div>
									<?php endif; ?>
								<?php endif; ?>
							</div>
						</div>

						<div class="icon-psy-summary-lists">
							<div class="icon-psy-summary-list-card">
								<p class="icon-psy-summary-list-title">Top leadership strengths</p>
								<?php if ( empty( $top_strengths ) ) : ?>
									<p class="icon-psy-empty">Not enough data yet.</p>
								<?php else : ?>
									<ul>
										<?php foreach ( $top_strengths as $item ) : ?>
											<li><strong><?php echo esc_html( $item['name'] ); ?></strong> - Average: <?php echo esc_html( number_format( $item['overall'], 1 ) ); ?> / 7</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</div>

							<div class="icon-psy-summary-list-card">
								<p class="icon-psy-summary-list-title">Key development priorities</p>
								<?php if ( empty( $top_devs ) ) : ?>
									<p class="icon-psy-empty">No clear priorities yet.</p>
								<?php else : ?>
									<ul>
										<?php foreach ( $top_devs as $item ) : ?>
											<li><strong><?php echo esc_html( $item['name'] ); ?></strong> - Average: <?php echo esc_html( number_format( $item['overall'], 1 ) ); ?> / 7</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<!-- FACILITATOR INSIGHTS -->
					<div class="icon-psy-report-card" id="icon-sec-insights">
						<div class="icon-psy-section-head">
							<h3 class="icon-psy-section-title">Facilitator insights</h3>
							<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
						</div>
						<p class="icon-psy-section-sub">
							A debrief-ready view of patterns, focus areas, and questions that move this from “information” to “action”.
						</p>

						<div class="icon-psy-insight-box">
							<div style="font-size:13px;color:#33415f;line-height:1.6;">
								<strong>Facilitator insights</strong><br>
								Scan patterns quickly, then use prompts to agree one visible behaviour shift.
							</div>

							<div class="icon-psy-insight-strip">
								<div class="icon-psy-tile">
									<p class="k">Dominant pattern</p>
									<p class="v"><?php echo esc_html( $dominant_lens_label ); ?></p>
									<p class="s">Most common “dip” lens across competencies.</p>
								</div>

								<div class="icon-psy-tile">
									<p class="k">Rater agreement</p>
									<p class="v"><?php echo $agreement_range === null ? 'n/a' : esc_html( number_format( (float) $agreement_range, 1 ) ); ?></p>
									<p class="s">
										<?php if ( $agreement_range === null ) : ?>
											Not enough group averages yet.
										<?php else : ?>
											Highest: <strong><?php echo esc_html( $agreement_highest ); ?></strong><br>
											Lowest: <strong><?php echo esc_html( $agreement_lowest ); ?></strong>
										<?php endif; ?>
									</p>
								</div>

								<div class="icon-psy-tile">
									<p class="k">Best focus</p>
									<p class="v"><?php echo ! empty( $focus_list ) ? esc_html( (string) $focus_list[0]['name'] ) : 'n/a'; ?></p>
									<p class="s">Highest return discussion choice.</p>
								</div>
							</div>

							<div style="margin-top:14px;">
								<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.10em;color:#6b7280;margin-bottom:8px;font-weight:900;">
									Debrief prompts
								</div>
								<?php if ( empty( $debrief_prompts ) ) : ?>
									<p class="icon-psy-empty">Prompts will appear once competency-level feedback is available.</p>
								<?php else : ?>
									<ol class="icon-psy-prompts">
										<?php foreach ( $debrief_prompts as $p ) : ?>
											<li><?php echo esc_html( $p ); ?></li>
										<?php endforeach; ?>
									</ol>
								<?php endif; ?>
							</div>

							<?php if ( ! empty( $top_dips ) ) : ?>
								<p class="icon-psy-mini-note">
									<strong>Where consistency drops most:</strong>
									<?php echo esc_html( $top_dips[0]['name'] ); ?> (<?php echo esc_html( $top_dips[0]['lens_label'] ); ?>).
								</p>
							<?php endif; ?>
						</div>
					</div>

					<!-- HEATMAP -->
					<div class="icon-psy-report-card" id="icon-sec-heatmap">
						<div class="icon-psy-section-head">
							<h3 class="icon-psy-section-title">Leadership heatmap</h3>
							<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
						</div>
						<p class="icon-psy-section-sub">
							Average scores (1 to 7) across three lenses:
							<strong>Everyday impact</strong>, <strong>Under pressure</strong>, <strong>Role-modelling</strong>.
						</p>

						<div class="icon-psy-heat-table-wrapper">
							<?php if ( empty( $heatmap_rows ) ) : ?>
								<p class="icon-psy-empty">No competency-level scores are available yet.</p>
							<?php else : ?>
								<table class="icon-psy-heat-table">
									<thead>
										<tr>
											<th>Leadership area</th>
											<th>Everyday impact</th>
											<th>Under pressure</th>
											<th>Role-modelling</th>
											<th>Overall</th>
										</tr>
									</thead>
									<tbody>
										<?php
										$band_for = function( $v ){
											$v = (float) $v;
											if ( $v < 3.5 ) return 'icon-psy-heat-low';
											if ( $v < 5.5 ) return 'icon-psy-heat-mid';
											return 'icon-psy-heat-high';
										};
										?>

										<?php foreach ( $heatmap_rows as $row ) :
											$q1 = (float) $row['avg_q1'];
											$q2 = (float) $row['avg_q2'];
											$q3 = (float) $row['avg_q3'];
											$overall = (float) $row['overall'];

											$band_q1 = $band_for( $q1 );
											$band_q2 = $band_for( $q2 );
											$band_q3 = $band_for( $q3 );
											$band_o  = $band_for( $overall );
										?>
											<tr>
												<td class="icon-psy-heat-name"><?php echo esc_html( $row['name'] ); ?></td>
												<td><div class="icon-psy-heat-cell <?php echo esc_attr( $band_q1 ); ?>"><?php echo number_format( $q1, 1 ); ?></div></td>
												<td><div class="icon-psy-heat-cell <?php echo esc_attr( $band_q2 ); ?>"><?php echo number_format( $q2, 1 ); ?></div></td>
												<td><div class="icon-psy-heat-cell <?php echo esc_attr( $band_q3 ); ?>"><?php echo number_format( $q3, 1 ); ?></div></td>
												<td><div class="icon-psy-heat-cell <?php echo esc_attr( $band_o ); ?>"><?php echo number_format( $overall, 1 ); ?></div></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>

								<div class="icon-psy-chip-row" style="margin-top:10px;">
									<a class="icon-psy-chip" href="#icon-sec-raters" style="text-decoration:none;">Next: Rater group perspectives →</a>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<!-- RATER GROUPS -->
					<div class="icon-psy-report-card" id="icon-sec-raters">
						<div class="icon-psy-section-head">
							<h3 class="icon-psy-section-title">Rater group perspectives</h3>
							<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
						</div>
						<p class="icon-psy-section-sub">
							How different rater groups view this leader.
						</p>

						<?php if ( empty( $category_stats ) ) : ?>
							<p class="icon-psy-empty">No rater group breakdown is available yet.</p>
						<?php else : ?>
							<table class="icon-psy-rater-table">
								<thead>
									<tr>
										<th>Rater group</th>
										<th>Number of feedback providers</th>
										<th>Average overall score (1 to 7)</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $category_stats as $data ) : ?>
										<tr>
											<td><?php echo esc_html( $data['label'] ); ?></td>
											<td><?php echo (int) $data['count']; ?></td>
											<td><?php echo is_null( $data['avg'] ) ? '<span class="icon-psy-empty">n/a</span>' : esc_html( number_format( $data['avg'], 1 ) . ' / 7' ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>

					<!-- NARRATIVE COMMENTS -->
					<div class="icon-psy-report-card" id="icon-sec-narrative">
						<div class="icon-psy-section-head">
							<h3 class="icon-psy-section-title">Narrative feedback</h3>
							<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
						</div>
						<p class="icon-psy-section-sub">Anonymised comments highlighting strengths and development opportunities.</p>

						<div class="icon-psy-comments-grid">
							<div class="icon-psy-comment-card">
								<div class="icon-psy-comment-label">Perceived strengths</div>
								<?php if ( empty( $strengths ) ) : ?>
									<p class="icon-psy-empty">No strengths comments recorded yet.</p>
								<?php else : ?>
									<ul style="margin:0;padding-left:18px;">
										<?php foreach ( $strengths as $c ) : ?>
											<li><?php echo esc_html( str_replace( array('**','—','–'), array('','-','-'), (string) $c ) ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</div>

							<div class="icon-psy-comment-card">
								<div class="icon-psy-comment-label">Development opportunities</div>
								<?php if ( empty( $dev_opps ) ) : ?>
									<p class="icon-psy-empty">No development comments recorded yet.</p>
								<?php else : ?>
									<ul style="margin:0;padding-left:18px;">
										<?php foreach ( $dev_opps as $c ) : ?>
											<li><?php echo esc_html( str_replace( array('**','—','–'), array('','-','-'), (string) $c ) ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<!-- RADAR -->
					<div class="icon-psy-report-card" id="icon-sec-radar">
						<div class="icon-psy-section-head">
							<h3 class="icon-psy-section-title">Self vs others radar</h3>
							<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
						</div>
						<p class="icon-psy-section-sub">
							A visual wheel comparing self and others across competencies.
						</p>

						<?php if ( ! $has_radar ) : ?>
							<p class="icon-psy-empty">Not enough data yet to draw the radar chart.</p>
						<?php else : ?>
							<div class="icon-psy-radar-layout">
								<div class="icon-psy-radar-wrap">
									<?php
									$cx      = 120; $cy = 120;
									$radius  = 90;
									$max_val = 7;
									$count_c = count( $radar_competencies );

									$points_self   = array();
									$points_others = array();

									for ( $i = 0; $i < $count_c; $i++ ) {
										$row   = $radar_competencies[ $i ];
										$angle = ( 2 * M_PI * $i / $count_c ) - ( M_PI / 2 );

										$val_self = ! is_null( $row['self_avg'] ) ? max( 0, min( $max_val, $row['self_avg'] ) ) : 0;
										$r_self   = $radius * ( $val_self / $max_val );
										$x_self   = $cx + $r_self * cos( $angle );
										$y_self   = $cy + $r_self * sin( $angle );
										$points_self[] = $x_self . ',' . $y_self;

										$val_oth = ! is_null( $row['others_avg'] ) ? max( 0, min( $max_val, $row['others_avg'] ) ) : 0;
										$r_oth   = $radius * ( $val_oth / $max_val );
										$x_oth   = $cx + $r_oth * cos( $angle );
										$y_oth   = $cy + $r_oth * sin( $angle );
										$points_others[] = $x_oth . ',' . $y_oth;
									}

									$label_safe = function( $s ){
										$s = trim( (string) $s );
										if ( strlen( $s ) > 14 ) $s = substr( $s, 0, 12 ) . '...';
										return $s;
									};

									// Use brand colours for radar (styling only)
									list( $pr, $pg, $pb ) = icon_psy_hex_to_rgb( isset($brand['primary']) ? $brand['primary'] : '#0f766e' );
									list( $sr, $sg, $sb ) = icon_psy_hex_to_rgb( isset($brand['secondary']) ? $brand['secondary'] : '#0ea5e9' );
									$others_fill = 'rgba(' . (int)$sr . ',' . (int)$sg . ',' . (int)$sb . ',0.10)';
									$self_fill   = 'rgba(' . (int)$pr . ',' . (int)$pg . ',' . (int)$pb . ',0.10)';
									$others_stroke = 'rgba(' . (int)$sr . ',' . (int)$sg . ',' . (int)$sb . ',0.95)';
									$self_stroke   = 'rgba(' . (int)$pr . ',' . (int)$pg . ',' . (int)$pb . ',0.95)';
									?>
									<div>
										<svg width="260" height="260" viewBox="0 0 240 240" role="img" aria-label="Leadership radar chart">
											<circle cx="<?php echo $cx; ?>" cy="<?php echo $cy; ?>" r="<?php echo $radius; ?>" fill="none" stroke="#e6e9f1" stroke-width="1" />
											<circle cx="<?php echo $cx; ?>" cy="<?php echo $cy; ?>" r="<?php echo $radius * 0.66; ?>" fill="none" stroke="#e6e9f1" stroke-width="1" />
											<circle cx="<?php echo $cx; ?>" cy="<?php echo $cy; ?>" r="<?php echo $radius * 0.33; ?>" fill="none" stroke="#e6e9f1" stroke-width="1" />

											<?php for ( $i = 0; $i < $count_c; $i++ ) :
												$row      = $radar_competencies[ $i ];
												$angle    = ( 2 * M_PI * $i / $count_c ) - ( M_PI / 2 );
												$x_outer  = $cx + $radius * cos( $angle );
												$y_outer  = $cy + $radius * sin( $angle );
												$x_label  = $cx + ( $radius + 16 ) * cos( $angle );
												$y_label  = $cy + ( $radius + 16 ) * sin( $angle );
											?>
												<line x1="<?php echo $cx; ?>" y1="<?php echo $cy; ?>" x2="<?php echo $x_outer; ?>" y2="<?php echo $y_outer; ?>" stroke="#e6e9f1" stroke-width="1" />
												<text x="<?php echo $x_label; ?>" y="<?php echo $y_label; ?>" font-size="8" text-anchor="middle" alignment-baseline="middle" fill="#6b7280">
													<?php echo esc_html( $label_safe( $row['name'] ) ); ?>
												</text>
											<?php endfor; ?>

											<polygon points="<?php echo esc_attr( implode( ' ', $points_others ) ); ?>" fill="<?php echo esc_attr( $others_fill ); ?>" stroke="<?php echo esc_attr( $others_stroke ); ?>" stroke-width="2" />
											<?php if ( ! is_null( $self_avg ) ) : ?>
												<polygon points="<?php echo esc_attr( implode( ' ', $points_self ) ); ?>" fill="<?php echo esc_attr( $self_fill ); ?>" stroke="<?php echo esc_attr( $self_stroke ); ?>" stroke-width="2" />
											<?php endif; ?>
										</svg>

										<div class="icon-psy-radar-caption">
											<span style="display:inline-block;width:10px;height:10px;border-radius:999px;background:<?php echo esc_attr( $self_stroke ); ?>;margin-right:6px;"></span>
											Self
											&nbsp;&nbsp;
											<span style="display:inline-block;width:10px;height:10px;border-radius:999px;background:<?php echo esc_attr( $others_stroke ); ?>;margin-right:6px;margin-left:10px;"></span>
											Others
										</div>
									</div>
								</div>

								<div>
									<div class="icon-psy-competency-map">
										<div class="icon-psy-competency-map-title">Leadership competency map</div>
										<div class="icon-psy-competency-chip-cloud">
											<?php foreach ( $heatmap_rows as $row ) : ?>
												<span class="icon-psy-competency-chip"><?php echo esc_html( $row['name'] ); ?></span>
											<?php endforeach; ?>
										</div>
									</div>

									<p style="margin-top:10px;font-size:13px;color:#33415f;line-height:1.65;">
										Use the wheel as a fast scan of perceived impact. Where the “Others” shape extends further, people are consistently experiencing that behaviour.
										Where it sits closer to the centre, agree one clear behaviour you will practise and make visible over the next 2 weeks.
									</p>
								</div>
							</div>
						<?php endif; ?>
					</div>

					<!-- PER COMPETENCY -->
					<div class="icon-psy-report-card" id="icon-sec-competency">
						<div class="icon-psy-section-head">
							<h3 class="icon-psy-section-title">Competency-by-competency summary</h3>
							<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
						</div>
						<p class="icon-psy-section-sub">
							Two-paragraph summary for each competency.
						</p>

						<?php if ( empty( $heatmap_rows ) ) : ?>
							<p class="icon-psy-empty">Competency-level data is not yet available.</p>
						<?php else : ?>
							<div class="icon-psy-competency-detail-grid">
								<?php foreach ( $heatmap_rows as $row ) :
									$name      = (string) $row['name'];
									$desc      = trim( (string) $row['description'] );
									$lens_code = isset( $row['lens_code'] ) ? (string) $row['lens_code'] : 'CLARITY';

									$overall = (float) $row['overall'];
									$avg_q1  = (float) $row['avg_q1'];
									$avg_q2  = (float) $row['avg_q2'];
									$avg_q3  = (float) $row['avg_q3'];

									$self_c   = $row['self_avg'];
									$others_c = $row['others_avg'];

									$level = 'developing';
									if ( $overall >= 5.5 ) { $level = 'strength'; }
									elseif ( $overall >= 4.5 ) { $level = 'solid'; }

									$self_width   = ! is_null( $self_c )   ? max( 0, min( 100, round( ( (float) $self_c   / 7 ) * 100 ) ) ) : 0;
									$others_width = ! is_null( $others_c ) ? max( 0, min( 100, round( ( (float) $others_c / 7 ) * 100 ) ) ) : 0;

									$ctx = array(
										'participant_id'   => $participant_id,
										'participant_name' => $participant_name,
										'participant_role' => $participant_role,
										'competency'       => $name,
										'description'      => $desc,
										'overall'          => $overall,
										'avg_q1'           => $avg_q1,
										'avg_q2'           => $avg_q2,
										'avg_q3'           => $avg_q3,
										'level'            => $level,
										'self'             => $self_c,
										'others'           => $others_c,
										'n'                => isset( $row['n'] ) ? (int) $row['n'] : null,
										'sd'               => isset( $row['sd'] ) ? $row['sd'] : null,
										'lens_code'        => $lens_code,
									);

									$engine_html = '';
									if ( ! empty( $narr_engine['loaded'] ) && function_exists( 'icon_psy_lens_narrative_html' ) ) {
										$engine_ctx = array(
											'lens_code'   => $lens_code,
											'competency'  => $name,
											'description' => $desc,
											'overall'     => $overall,
											'avg_q1'      => $avg_q1,
											'avg_q2'      => $avg_q2,
											'avg_q3'      => $avg_q3,
											'self'        => ( $self_c === null ? null : (float) $self_c ),
											'others'      => ( $others_c === null ? null : (float) $others_c ),
											'n'           => isset( $row['n'] ) ? (int) $row['n'] : null,
											'sd'          => isset( $row['sd'] ) ? $row['sd'] : null,
											'role'        => (string) $participant_role,
											'audience'    => 'Stakeholders',
										);

										$engine_html = (string) icon_psy_lens_narrative_html( $engine_ctx );
										$engine_html = trim( $engine_html );
									}

									if ( $engine_html === '' ) {
										$text        = icon_psy_competency_narrative_fallback( $ctx );
										$engine_html = icon_psy_format_engine_text_to_html( $text );
									}
								?>
									<div class="icon-psy-competency-detail-card">
										<p class="icon-psy-competency-summary-name"><?php echo esc_html( $name ); ?></p>

										<div class="icon-psy-competency-metrics">
											<span class="icon-psy-competency-pill-muted">Overall: <?php echo esc_html( number_format( $overall, 1 ) ); ?> / 7</span>
											<span class="<?php echo ! is_null( $self_c ) ? 'icon-psy-competency-pill' : 'icon-psy-competency-pill-muted'; ?>">
												Self: <?php echo ! is_null( $self_c ) ? esc_html( number_format( (float) $self_c, 1 ) ) : 'n/a'; ?>
											</span>
											<span class="icon-psy-competency-pill-muted">
												Others: <?php echo ! is_null( $others_c ) ? esc_html( number_format( (float) $others_c, 1 ) ) : 'n/a'; ?>
											</span>
											<?php if ( isset( $row['n'] ) && (int) $row['n'] > 0 ) : ?>
												<span class="icon-psy-competency-pill-muted">n: <?php echo (int) $row['n']; ?></span>
											<?php endif; ?>
											<?php if ( isset( $row['sd'] ) && $row['sd'] !== null ) : ?>
												<span class="icon-psy-competency-pill-muted">sd: <?php echo esc_html( number_format( (float) $row['sd'], 2 ) ); ?></span>
											<?php endif; ?>
										</div>

										<?php if ( $desc !== '' ) : ?>
											<div style="margin:0 0 8px;font-size:12px;color:#55627a;line-height:1.65;">
												<?php echo esc_html( str_replace( array('**','—','–'), array('','-','-'), $desc ) ); ?>
											</div>
										<?php endif; ?>

										<?php echo $engine_html !== '' ? $engine_html : '<p class="icon-psy-empty">Summary is not available yet.</p>'; ?>

										<?php if ( ! is_null( $self_c ) || ! is_null( $others_c ) ) : ?>
											<div style="margin-top:10px;">
												<?php if ( ! is_null( $self_c ) ) : ?>
													<div class="icon-psy-mini-bar-row">
														<span class="icon-psy-mini-bar-label">Self</span>
														<div class="icon-psy-mini-bar-track">
															<div class="icon-psy-mini-bar self" style="width: <?php echo esc_attr( $self_width ); ?>%;"></div>
														</div>
													</div>
												<?php endif; ?>
												<?php if ( ! is_null( $others_c ) ) : ?>
													<div class="icon-psy-mini-bar-row">
														<span class="icon-psy-mini-bar-label">Others</span>
														<div class="icon-psy-mini-bar-track">
															<div class="icon-psy-mini-bar others" style="width: <?php echo esc_attr( $others_width ); ?>%;"></div>
														</div>
													</div>
												<?php endif; ?>
												<div style="margin-top:2px;font-size:10px;color:#6b7280;">Bars show average ratings on a 1 to 7 scale.</div>
											</div>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>

					<!-- BLIND SPOTS -->
					<div class="icon-psy-report-card" id="icon-sec-blind">
						<div class="icon-psy-section-head">
							<h3 class="icon-psy-section-title">Blind spots and awareness</h3>
							<a class="icon-psy-backtop" href="#icon-report-top">Back to top ↑</a>
						</div>
						<p class="icon-psy-section-sub">
							Where intention and impact may not be fully aligned. Use this as curiosity, not criticism.
						</p>

						<?php if ( empty( $blind_overrate ) && empty( $blind_underrate ) ) : ?>
							<p class="icon-psy-empty">No strong blind-spot signals detected yet (or not enough self vs others data).</p>
						<?php else : ?>
							<div class="icon-psy-blind-grid">
								<?php foreach ( (array) $blind_overrate as $b ) : ?>
									<div class="icon-psy-blind-card">
										<p class="icon-psy-blind-name"><?php echo esc_html( $b['name'] ); ?></p>
										<div class="icon-psy-blind-metrics">
											<span class="icon-psy-blind-pill">Self: <?php echo esc_html( number_format( (float) $b['self'], 1 ) ); ?></span>
											<span class="icon-psy-blind-pill">Others: <?php echo esc_html( number_format( (float) $b['others'], 1 ) ); ?></span>
											<span class="icon-psy-blind-pill">Gap: <?php echo esc_html( number_format( (float) $b['gap'], 1 ) ); ?></span>
											<span class="icon-psy-blind-pill"><?php echo esc_html( $b['lens_label'] ); ?></span>
										</div>
										<div class="icon-psy-blind-text">
											<?php echo esc_html( icon_psy_blindspot_lens_copy( 'overrate', $b['lens_key'] ) ); ?>
										</div>
									</div>
								<?php endforeach; ?>

								<?php foreach ( (array) $blind_underrate as $b ) : ?>
									<div class="icon-psy-blind-card positive">
										<p class="icon-psy-blind-name"><?php echo esc_html( $b['name'] ); ?></p>
										<div class="icon-psy-blind-metrics">
											<span class="icon-psy-blind-pill">Self: <?php echo esc_html( number_format( (float) $b['self'], 1 ) ); ?></span>
											<span class="icon-psy-blind-pill">Others: <?php echo esc_html( number_format( (float) $b['others'], 1 ) ); ?></span>
											<span class="icon-psy-blind-pill">Gap: <?php echo esc_html( number_format( (float) $b['gap'], 1 ) ); ?></span>
											<span class="icon-psy-blind-pill"><?php echo esc_html( $b['lens_label'] ); ?></span>
										</div>
										<div class="icon-psy-blind-text">
											<?php echo esc_html( icon_psy_blindspot_lens_copy( 'underrate', $b['lens_key'] ) ); ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>

				</div><!-- /.icon-psy-content -->
			</div><!-- /.icon-psy-page -->
		</div><!-- /.wrapper -->
		<?php
		return ob_get_clean();
	}
}
