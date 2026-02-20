<?php
/**
 * TEAM Report — PDF template (standalone, Dompdf-safe)
 *
 * Expected: included with a $data array (your existing pattern),
 * and/or $icon_pdf_args / $icon_pdf_branding (central PDF engine pattern).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ------------------------------------------------------------
// Fallback helpers (ONLY if missing)
// ------------------------------------------------------------
if ( ! function_exists( 'icon_psy_format_engine_text_to_html' ) ) {
	function icon_psy_format_engine_text_to_html( $text ) {
		$text = (string) $text;
		$text = trim( $text );
		if ( $text === '' ) return '';
		$text = str_replace( array("\r\n", "\r"), "\n", $text );

		$paras = preg_split( "/\n{2,}/", $text );
		$out   = '';
		foreach ( $paras as $p ) {
			$p = trim( $p );
			if ( $p === '' ) continue;
			$p = esc_html( $p );
			$p = nl2br( $p );
			$out .= '<p class="p">' . $p . '</p>';
		}
		return $out;
	}
}

if ( ! function_exists( 'icon_psy_lens_gap_info' ) ) {
	function icon_psy_lens_gap_info( $q1, $q2, $q3 ) {
		$vals = array(
			'Everyday'   => (float) $q1,
			'Pressure'   => (float) $q2,
			'Role-model' => (float) $q3,
		);
		$min_k = 'Everyday'; $max_k = 'Everyday';
		$min_v = 999; $max_v = -999;

		foreach ( $vals as $k => $v ) {
			if ( $v < $min_v ) { $min_v = $v; $min_k = $k; }
			if ( $v > $max_v ) { $max_v = $v; $max_k = $k; }
		}
		return array(
			'gap'   => (float) ( $max_v - $min_v ),
			'min_k' => $min_k,
			'max_k' => $max_k,
		);
	}
}

if ( ! function_exists( 'icon_psy_sd_band' ) ) {
	function icon_psy_sd_band( $sd, $n ) {
		$n  = (int) $n;
		$sd = ( $sd === null ? null : (float) $sd );

		if ( $n < 3 ) return array( 'label' => 'Early signal (low n)' );
		if ( $sd === null ) return array( 'label' => 'Consistency not available' );

		if ( $sd <= 0.60 ) return array( 'label' => 'High alignment (low variation)' );
		if ( $sd <= 1.10 ) return array( 'label' => 'Mixed views (moderate variation)' );
		return array( 'label' => 'Polarised views (high variation)' );
	}
}

if ( ! function_exists( 'icon_psy_team_next_week_action' ) ) {
	function icon_psy_team_next_week_action( $ctx ) {
		$name    = isset( $ctx['competency'] ) ? (string) $ctx['competency'] : 'this competency';
		$overall = isset( $ctx['overall'] ) ? (float) $ctx['overall'] : 0.0;

		if ( $overall >= 5.5 ) {
			return "Protect what's working. Agree one visible behaviour that demonstrates “{$name}” and reinforce it in your next team touchpoint.";
		}
		if ( $overall >= 4.5 ) {
			return "Lift performance. Pick one moment this week where “{$name}” matters most, and agree a simple team cue (what we will do or say) to strengthen it.";
		}
		return "Stabilise quickly. Define one minimum standard for “{$name}”, then run a short 10-minute check-in to align expectations and remove blockers.";
	}
}

if ( ! function_exists( 'icon_psy_team_competency_narrative_fallback' ) ) {
	function icon_psy_team_competency_narrative_fallback( $ctx ) {
		$comp    = isset( $ctx['competency'] ) ? (string) $ctx['competency'] : 'Competency';
		$desc    = isset( $ctx['description'] ) ? trim( (string) $ctx['description'] ) : '';
		$overall = isset( $ctx['overall'] ) ? (float) $ctx['overall'] : 0.0;
		$q1      = isset( $ctx['avg_q1'] ) ? (float) $ctx['avg_q1'] : 0.0;
		$q2      = isset( $ctx['avg_q2'] ) ? (float) $ctx['avg_q2'] : 0.0;
		$q3      = isset( $ctx['avg_q3'] ) ? (float) $ctx['avg_q3'] : 0.0;

		$gap = icon_psy_lens_gap_info( $q1, $q2, $q3 );

		$level = 'needs attention';
		if ( $overall >= 5.5 ) $level = 'a clear strength';
		elseif ( $overall >= 4.5 ) $level = 'developing';

		$out  = "{$comp} is currently {$level} for the team (overall " . number_format( $overall, 1 ) . "/7). ";
		$out .= "The largest difference is between {$gap['min_k']} and {$gap['max_k']} (gap " . number_format( (float) $gap['gap'], 1 ) . "). ";
		if ( $desc !== '' ) $out .= "Definition: {$desc}";
		return $out;
	}
}

// ------------------------------------------------------------
// Validate data
// ------------------------------------------------------------
if ( empty( $data ) || ! is_array( $data ) ) {
	echo '<p style="font-family:DejaVu Sans, sans-serif;">PDF template error: missing data.</p>';
	return;
}

extract( $data, EXTR_SKIP );

// Defensive defaults
$project_id        = isset( $project_id ) ? (int) $project_id : 0;
$project_name      = isset( $project_name ) ? (string) $project_name : '';
$overall_avg       = array_key_exists( 'overall_avg', $data ) ? $overall_avg : null;
$summary_sentence  = isset( $summary_sentence ) ? (string) $summary_sentence : '';
$num_responses     = isset( $num_responses ) ? (int) $num_responses : 0;
$num_raters        = isset( $num_raters ) ? (int) $num_raters : 0;
$top_strengths     = isset( $top_strengths ) && is_array( $top_strengths ) ? $top_strengths : array();
$top_devs          = isset( $top_devs ) && is_array( $top_devs ) ? $top_devs : array();
$heatmap_rows      = isset( $heatmap_rows ) && is_array( $heatmap_rows ) ? $heatmap_rows : array();
$wheel_render_html = isset( $wheel_render_html ) ? (string) $wheel_render_html : '';
$narratives_loaded = ! empty( $narratives_loaded );

// Branding: accept any of your patterns
$branding = array();
if ( isset( $data['branding'] ) && is_array( $data['branding'] ) ) $branding = $data['branding'];
elseif ( isset( $icon_pdf_branding ) && is_array( $icon_pdf_branding ) ) $branding = $icon_pdf_branding;
elseif ( isset( $icon_pdf_args['branding'] ) && is_array( $icon_pdf_args['branding'] ) ) $branding = $icon_pdf_args['branding'];

$primary   = ! empty( $branding['primary'] ) ? (string) $branding['primary'] : '#15a06d';
$secondary = ! empty( $branding['secondary'] ) ? (string) $branding['secondary'] : '#14a4cf';

// Prefer embedded data URI if available (best for Dompdf)
$logo_src = '';
if ( ! empty( $branding['logo_data_uri'] ) ) $logo_src = (string) $branding['logo_data_uri'];
elseif ( ! empty( $branding['logo_url'] ) ) $logo_src = (string) $branding['logo_url'];

$project_label = $project_name !== '' ? $project_name : ( 'Project #' . (int) $project_id );
$generated_on  = gmdate( 'j M Y' );

// Helper: interpret score to label
$score_label = function( $v ) {
	$v = ( $v === null ? null : (float) $v );
	if ( $v === null ) return 'Not enough data';
	if ( $v >= 5.5 ) return 'Strong';
	if ( $v >= 4.5 ) return 'Developing';
	return 'Priority';
};

// Helper: safe % width (0..7)
$fill_pct = function( $v ) {
	$v = max( 0.0, min( 7.0, (float) $v ) );
	return ( $v / 7.0 ) * 100.0;
};

// Heat cell class
$cell_class = function( $v ){
	$v = (float) $v;
	if ( $v >= 5.5 ) return 'pill pill-high';
	if ( $v >= 4.5 ) return 'pill pill-mid';
	return 'pill pill-low';
};

?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<style>
		@page { margin: 86px 40px 62px 40px; }
		* { box-sizing:border-box; }
		body { font-family: DejaVu Sans, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; color:#0f172a; font-size:12px; }
		/* Dompdf colour printing */
		body, .bg { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

		:root{
			--primary: <?php echo esc_html( $primary ); ?>;
			--secondary: <?php echo esc_html( $secondary ); ?>;
			--ink: #0f172a;
			--muted: #64748b;
			--card: #ffffff;
			--line: #e5e7eb;
			--soft: #f8fafc;
			--soft2: #f1f5f9;
		}

		/* Header / Footer */
		.hdr{
			position: fixed;
			top: -66px; left: 0; right: 0;
			height: 56px;
			border-bottom: 1px solid var(--line);
			padding: 10px 0 0;
		}
		.hdr-row{ width: 100%; display: table; table-layout: fixed; }
		.hdr-left, .hdr-right{ display: table-cell; vertical-align: middle; }
		.hdr-left{ width: 52%; }
		.hdr-right{ width: 48%; text-align: right; }
		.logo{ display:inline-block; vertical-align: middle; max-height: 28px; height: 28px; width: auto; }
		.brand-line{ margin-top: 8px; height: 3px; background: var(--primary); }
		.brand-line2{ height: 1px; background: var(--secondary); }
		.hdr-title{ font-weight: 900; font-size: 12px; color: var(--ink); margin: 0; }
		.hdr-sub{ font-size: 10px; color: var(--muted); margin: 2px 0 0; }

		.ftr{
			position: fixed;
			bottom: -44px; left: 0; right: 0;
			height: 34px;
			border-top: 1px solid var(--line);
			padding: 8px 0 0;
			font-size: 10px;
			color: var(--muted);
		}
		.ftr-left{ float:left; }
		.ftr-right{ float:right; }

		/* Layout */
		.page{ }
		.cover{ page-break-after: always; }
		.cover-box{
			border: 1px solid var(--line);
			background: var(--card);
			border-radius: 16px;
			padding: 22px;
		}
		.cover-tag{
			display:inline-block;
			font-size: 10px;
			letter-spacing: .12em;
			text-transform: uppercase;
			font-weight: 900;
			color: var(--primary);
			border: 1px solid rgba(0,0,0,.08);
			border-radius: 999px;
			padding: 5px 10px;
			background: var(--soft);
		}
		.h1{ font-size: 28px; margin: 12px 0 6px; font-weight: 900; color: #052e26; }
		.h2{ font-size: 16px; margin: 0 0 6px; font-weight: 900; color: #052e26; }
		.p{ margin: 0 0 10px; line-height: 1.6; color:#0f172a; }
		.small{ font-size: 10px; color: var(--muted); }

		.grid2{ width:100%; display: table; table-layout: fixed; margin-top: 14px; }
		.col{ display: table-cell; vertical-align: top; }
		.col + .col{ padding-left: 12px; }

		.card{
			border: 1px solid var(--line);
			border-radius: 14px;
			background: var(--card);
			padding: 14px;
			margin: 0 0 12px;
			page-break-inside: avoid;
		}

		/* Heatmap: allow the container to break (table spans pages) */
		.card-allow-break{ page-break-inside: auto !important; }

		.card-soft{ background: var(--soft); }

		.metric{ font-size: 28px; font-weight: 900; color: var(--primary); margin: 6px 0 2px; }
		.metric-sub{ font-size: 11px; color: var(--muted); margin: 0; }

		.kpi-row{ display: table; width:100%; table-layout: fixed; margin-top: 8px; }
		.kpi{ display: table-cell; padding-right: 10px; }
		.kpi:last-child{ padding-right:0; }
		.kpi-box{
			border: 1px solid var(--line);
			background: var(--soft2);
			border-radius: 12px;
			padding: 10px;
		}
		.kpi-lab{ font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: .10em; font-weight: 900; margin:0 0 4px; }
		.kpi-val{ font-size: 16px; font-weight: 900; margin:0; color:#0f172a; }

		/* Strengths / priorities */
		.item{
			border: 1px solid var(--line);
			background: #fff;
			border-radius: 12px;
			padding: 10px;
			margin: 0 0 8px;
		}
		.item-row{ display: table; width:100%; table-layout: fixed; }
		.item-name{ display: table-cell; font-weight: 800; font-size: 12px; color:#0f172a; }
		.item-score{ display: table-cell; text-align: right; font-weight: 900; color: var(--primary); width: 80px; }

		/* Small helpers used throughout */
		.hr{ height:1px; background: var(--line); margin: 12px 0; }

		.meta-row{ margin-top: 8px; }
		.meta-pill{
			display:inline-block;
			margin: 0 6px 6px 0;
			padding: 4px 10px;
			border-radius: 999px;
			border: 1px solid rgba(0,0,0,.08);
			background: #f1f5f9;
			color: #334155;
			font-size: 10px;
			font-weight: 900;
		}

		.callout{
			border: 1px solid rgba(0,0,0,.08);
			background: #ecfdf5;
			border-radius: 14px;
			padding: 12px;
			margin-top: 12px;
		}
		.callout-lab{
			font-size: 10px;
			text-transform: uppercase;
			letter-spacing: .12em;
			font-weight: 900;
			color: #065f46;
			margin: 0 0 6px;
		}
		.callout-txt{ margin:0; color:#0a3b34; line-height:1.6; }

		/* Heatmap table – Dompdf safe (prevents “1 row per page”) */
		table.heatmap{
			width:100%;
			border-collapse: collapse;
			table-layout: fixed;
		}
		table.heatmap thead{ display: table-header-group; }
		table.heatmap tbody{ display: table-row-group; }
		table.heatmap tr{ display: table-row; page-break-inside: avoid; }
		table.heatmap th, table.heatmap td{ display: table-cell; }

		table.heatmap thead th{
			font-size: 10px;
			text-transform: uppercase;
			letter-spacing: .10em;
			color: var(--muted);
			text-align: left;
			padding: 10px 8px;
			border-bottom: 1px solid var(--line);
			background: var(--soft);
		}
		table.heatmap tbody td{
			padding: 10px 8px;
			border-bottom: 1px solid #f1f5f9;
			vertical-align: middle;
		}

		td, th{ overflow-wrap: anywhere; word-wrap: break-word; }
		.comp-name{ font-weight: 900; color:#0f172a; display:block; }

		.pill{
			display:inline-block;
			min-width: 64px;
			text-align:center;
			padding: 4px 10px;
			border-radius: 999px;
			font-weight: 900;
			font-variant-numeric: tabular-nums;
			font-size: 11px;
			border: 1px solid rgba(0,0,0,.08);
		}
		.pill-low{ background:#fef2f2; color:#b91c1c; }
		.pill-mid{ background:#fffbeb; color:#92400e; }
		.pill-high{ background:#ecfdf5; color:#166534; }

		/* Wheel */
		.wheel-wrap{ text-align:center; }
		.wheel-card{
			border: 1px solid var(--line);
			border-radius: 16px;
			padding: 10px;
			background: #fff;
		}

		/* Competency pages */
		.comp-page{ page-break-before: always; }
		.comp-head{ display: table; width:100%; table-layout: fixed; margin-bottom: 10px; }
		.comp-title{ display: table-cell; vertical-align: top; }
		.comp-badges{ display: table-cell; vertical-align: top; text-align: right; width: 240px; }
		.comp-h{ font-size: 18px; font-weight: 900; margin: 0 0 4px; color: #052e26; }

		.badge{
			display:inline-block;
			padding: 4px 10px;
			border-radius: 999px;
			border: 1px solid rgba(0,0,0,.08);
			background: var(--soft);
			font-size: 10px;
			font-weight: 900;
			color: #0f172a;
			margin-left: 6px;
			white-space: nowrap;
		}
		.badge-strong{ background: #ecfdf5; color:#065f46; }
		.badge-pri{ background: #fef2f2; color:#b91c1c; }

		.bars{ display: table; width:100%; table-layout: fixed; margin-top: 10px; }
		.bar{ display: table-cell; padding-right: 10px; }
		.bar:last-child{ padding-right: 0; }
		.bar-box{ border:1px solid var(--line); border-radius: 14px; padding: 10px; background:#fff; }
		.bar-top{ display: table; width:100%; table-layout: fixed; margin-bottom: 6px; }
		.bar-lab{ display: table-cell; font-size: 10px; font-weight: 900; color: var(--muted); text-transform: uppercase; letter-spacing:.10em; }
		.bar-val{ display: table-cell; text-align:right; font-size: 10px; font-weight: 900; color:#0f172a; }
		.track{ height: 10px; border-radius: 999px; background: #e2e8f0; overflow:hidden; }
		.fill{ height: 10px; border-radius: 999px; background: var(--primary); }

		/* Section breaks */
		.break-after{ page-break-after: always; }
	</style>
</head>

<body>

	<!-- Fixed Header -->
	<div class="hdr">
		<div class="hdr-row">
			<div class="hdr-left">
				<?php if ( $logo_src !== '' ) : ?>
					<img class="logo" src="<?php echo esc_attr( $logo_src ); ?>" alt="">
				<?php else : ?>
					<div style="font-weight:900;color:var(--primary);font-size:14px;line-height:28px;">ICON</div>
				<?php endif; ?>
			</div>
			<div class="hdr-right">
				<p class="hdr-title">Team Report</p>
				<p class="hdr-sub"><?php echo esc_html( $project_label ); ?> · Generated <?php echo esc_html( $generated_on ); ?></p>
			</div>
		</div>
		<div class="brand-line"></div>
		<div class="brand-line2"></div>
	</div>

	<!-- Fixed Footer -->
	<div class="ftr">
		<div class="ftr-left">ICON Catalyst · Team Effectiveness</div>
		<div class="ftr-right">Confidential</div>
	</div>

	<!-- COVER -->
	<div class="page cover">
		<div class="cover-box">
			<span class="cover-tag">ICON Catalyst</span>
			<div class="h1">Team Report</div>
			<div class="h2"><?php echo esc_html( $project_label ); ?></div>

			<div class="grid2">
				<div class="col">
					<div class="card card-soft">
						<div class="small">Overall team score</div>
						<div class="metric">
							<?php echo is_null( $overall_avg ) ? 'n/a' : esc_html( number_format( (float) $overall_avg, 1 ) ); ?>
							<span style="font-size:14px;font-weight:900;color:var(--muted);">/ 7</span>
						</div>
						<p class="metric-sub"><?php echo esc_html( $score_label( $overall_avg ) ); ?></p>
						<div class="hr"></div>
						<p class="p" style="margin:0;"><?php echo esc_html( $summary_sentence !== '' ? $summary_sentence : 'A snapshot of team strengths and improvement priorities based on rater feedback.' ); ?></p>
					</div>
				</div>

				<div class="col">
					<div class="card">
						<div class="kpi-row">
							<div class="kpi">
								<div class="kpi-box">
									<p class="kpi-lab">Responses</p>
									<p class="kpi-val"><?php echo (int) $num_responses; ?></p>
								</div>
							</div>
							<div class="kpi">
								<div class="kpi-box">
									<p class="kpi-lab">Unique raters</p>
									<p class="kpi-val"><?php echo (int) $num_raters; ?></p>
								</div>
							</div>
						</div>

						<div class="hr"></div>

						<p class="p"><strong>How to read this report</strong></p>
						<p class="p">Each competency is scored through 3 lenses:</p>
						<p class="p" style="margin-bottom:0;">
							<strong>Everyday</strong> (typical behaviour) ·
							<strong>Pressure</strong> (under stress) ·
							<strong>Role-model</strong> (sets the standard).
						</p>

						<div class="meta-row">
							<span class="meta-pill">Scale: 1 to 7</span>
							<span class="meta-pill">7 = consistently strong</span>
							<span class="meta-pill">4 to 5 = developing</span>
							<span class="meta-pill">1 to 4 = priority</span>
						</div>

						<p class="small" style="margin-top:10px;">Page numbers are included automatically by the PDF engine.</p>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- EXEC SUMMARY -->
	<div class="page break-after">
		<div class="card">
			<div class="h2" style="margin:0 0 6px;">Executive summary</div>
			<p class="p"><?php echo esc_html( $summary_sentence !== '' ? $summary_sentence : 'This summary highlights performance patterns and the most practical next steps.' ); ?></p>

			<div class="grid2">
				<div class="col">
					<div class="card card-soft">
						<div class="h2" style="margin:0 0 8px;font-size:14px;">Top strengths</div>
						<?php if ( empty( $top_strengths ) ) : ?>
							<p class="p" style="margin:0;color:var(--muted);">Not enough data to determine strengths.</p>
						<?php else : ?>
							<?php foreach ( $top_strengths as $s ) : ?>
								<div class="item">
									<div class="item-row">
										<div class="item-name"><?php echo esc_html( (string) $s['name'] ); ?></div>
										<div class="item-score"><?php echo esc_html( number_format( (float) $s['overall'], 1 ) ); ?></div>
									</div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>

				<div class="col">
					<div class="card card-soft">
						<div class="h2" style="margin:0 0 8px;font-size:14px;">Improvement priorities</div>
						<?php if ( empty( $top_devs ) ) : ?>
							<p class="p" style="margin:0;color:var(--muted);">Not enough data to determine priorities.</p>
						<?php else : ?>
							<?php foreach ( $top_devs as $d ) : ?>
								<div class="item">
									<div class="item-row">
										<div class="item-name"><?php echo esc_html( (string) $d['name'] ); ?></div>
										<div class="item-score" style="color:#b91c1c;"><?php echo esc_html( number_format( (float) $d['overall'], 1 ) ); ?></div>
									</div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="callout" style="background:#f1f5f9;">
				<p class="callout-lab" style="color:#334155;">Practical focus</p>
				<p class="callout-txt" style="color:#0f172a;">
					Choose <strong>one strength</strong> to protect and one <strong>priority</strong> to lift this week.
					Keep it behavioural, specific, and visible.
				</p>
			</div>
		</div>
	</div>

	<!-- HEATMAP -->
	<div class="page break-after">
		<div class="card card-allow-break">
			<div class="h2" style="margin:0 0 6px;">Heatmap by competency</div>
			<p class="p" style="color:var(--muted);">Scores by lens. Use this to spot patterns and gaps between everyday behaviour and performance under pressure.</p>

			<?php if ( empty( $heatmap_rows ) ) : ?>
				<p class="p" style="margin:0;color:var(--muted);">Competency-level data is not yet available.</p>
			<?php else : ?>
				<table class="heatmap">
					<thead>
						<tr>
							<th style="width:38%;">Competency</th>
							<th style="width:15%;">Everyday</th>
							<th style="width:15%;">Pressure</th>
							<th style="width:15%;">Role-model</th>
							<th style="width:17%;">Overall</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $heatmap_rows as $r ) :
							$name = isset( $r['name'] ) ? (string) $r['name'] : '';
						?>
							<tr>
								<td><span class="comp-name"><?php echo esc_html( $name ); ?></span></td>
								<td><span class="<?php echo esc_attr( $cell_class( $r['avg_q1'] ) ); ?>"><?php echo esc_html( number_format( (float) $r['avg_q1'], 1 ) ); ?></span></td>
								<td><span class="<?php echo esc_attr( $cell_class( $r['avg_q2'] ) ); ?>"><?php echo esc_html( number_format( (float) $r['avg_q2'], 1 ) ); ?></span></td>
								<td><span class="<?php echo esc_attr( $cell_class( $r['avg_q3'] ) ); ?>"><?php echo esc_html( number_format( (float) $r['avg_q3'], 1 ) ); ?></span></td>
								<td><span class="<?php echo esc_attr( $cell_class( $r['overall'] ) ); ?>"><?php echo esc_html( number_format( (float) $r['overall'], 1 ) ); ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<!-- WHEEL -->
	<div class="page break-after">
		<div class="card">
			<div class="h2" style="margin:0 0 6px;">Competency wheel</div>
			<p class="p" style="color:var(--muted);">Average scores across the team. Use this for a quick profile of strengths and development priorities.</p>

			<?php if ( trim( $wheel_render_html ) === '' ) : ?>
				<p class="p" style="margin:0;color:var(--muted);">Not enough competency-level data yet to render the wheel.</p>
			<?php else : ?>
				<div class="wheel-wrap">
					<div class="wheel-card">
						<?php
						// Intentionally not escaping: trusted server-rendered SVG/IMG markup.
						echo $wheel_render_html;
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- COMPETENCY-BY-COMPETENCY (1 per page) -->
	<?php if ( ! empty( $heatmap_rows ) ) : ?>
		<?php
		$__comp_i = 0;
		$__limit = 10;
		foreach ( $heatmap_rows as $row ) :
			if ( $__comp_i >= $__limit ) { break; }

			$name    = isset( $row['name'] ) ? (string) $row['name'] : '';
			$desc    = isset( $row['description'] ) ? trim( (string) $row['description'] ) : '';
			$overall = isset( $row['overall'] ) ? (float) $row['overall'] : 0.0;
			$avg_q1  = isset( $row['avg_q1'] ) ? (float) $row['avg_q1'] : 0.0;
			$avg_q2  = isset( $row['avg_q2'] ) ? (float) $row['avg_q2'] : 0.0;
			$avg_q3  = isset( $row['avg_q3'] ) ? (float) $row['avg_q3'] : 0.0;
			$sd      = array_key_exists( 'sd', $row ) ? $row['sd'] : null;
			$n       = isset( $row['n'] ) ? (int) $row['n'] : 0;

			$ctx = array(
				'competency'  => $name,
				'description' => $desc,
				'overall'     => $overall,
				'avg_q1'      => $avg_q1,
				'avg_q2'      => $avg_q2,
				'avg_q3'      => $avg_q3,
				'sd'          => $sd,
				'n'           => $n,
			);

			// Narrative source: prefer engine, else fallback
			$engine_html = '';
			if ( $narratives_loaded && function_exists( 'icon_psy_lens_narrative_html' ) ) {
				$tmp = icon_psy_lens_narrative_html( array(
					'lens_code'       => isset( $row['lens_code'] ) ? (string) $row['lens_code'] : 'CLARITY',
					'competency'      => $name,
					'description'     => $desc,
					'overall'         => $overall,
					'avg_q1'          => $avg_q1,
					'avg_q2'          => $avg_q2,
					'avg_q3'          => $avg_q3,
					'n'               => $n,
					'sd'              => ( $sd === null ? null : (float) $sd ),
					'audience'        => 'Team',
					'show_descriptor' => false,
				) );
				$engine_html = is_string( $tmp ) ? trim( $tmp ) : '';
			}
			if ( $engine_html === '' ) {
				$text        = icon_psy_team_competency_narrative_fallback( $ctx );
				$engine_html = icon_psy_format_engine_text_to_html( $text );
			}

			$gap_info  = icon_psy_lens_gap_info( $avg_q1, $avg_q2, $avg_q3 );
			$gap_val   = (float) $gap_info['gap'];
			$band      = icon_psy_sd_band( $sd, $n );
			$next_week = icon_psy_team_next_week_action( $ctx );

			$strength_badge = $overall >= 5.5 ? 'badge badge-strong' : ( $overall < 4.5 ? 'badge badge-pri' : 'badge' );
		?>
			<div class="page comp-page">
				<div class="card">
					<div class="comp-head">
						<div class="comp-title">
							<div class="h2" style="margin:0 0 6px;">Competency detail</div>
							<p class="comp-h"><?php echo esc_html( $name ); ?></p>
							<?php if ( $desc !== '' ) : ?>
								<p class="p" style="margin:0;color:var(--muted);"><?php echo esc_html( str_replace( array('**','—','–'), array('','-','-'), $desc ) ); ?></p>
							<?php endif; ?>
						</div>
						<div class="comp-badges">
							<span class="<?php echo esc_attr( $strength_badge ); ?>">
								Overall: <?php echo esc_html( number_format( $overall, 1 ) ); ?> / 7
							</span>
							<span class="badge">n: <?php echo (int) $n; ?></span>
							<?php if ( $sd !== null ) : ?>
								<span class="badge">SD: <?php echo esc_html( number_format( (float) $sd, 2 ) ); ?></span>
							<?php endif; ?>
						</div>
					</div>

					<div class="meta-row">
						<span class="meta-pill">Biggest lens gap: <?php echo esc_html( number_format( $gap_val, 1 ) ); ?> (<?php echo esc_html( $gap_info['min_k'] ); ?> to <?php echo esc_html( $gap_info['max_k'] ); ?>)</span>
						<span class="meta-pill"><?php echo esc_html( isset( $band['label'] ) ? $band['label'] : 'Consistency band' ); ?></span>
					</div>

					<div class="bars">
						<div class="bar">
							<div class="bar-box">
								<div class="bar-top">
									<div class="bar-lab">Everyday</div>
									<div class="bar-val"><?php echo esc_html( number_format( $avg_q1, 1 ) ); ?></div>
								</div>
								<div class="track"><div class="fill" style="width:<?php echo esc_attr( number_format( $fill_pct( $avg_q1 ), 2, '.', '' ) ); ?>%;"></div></div>
							</div>
						</div>
						<div class="bar">
							<div class="bar-box">
								<div class="bar-top">
									<div class="bar-lab">Pressure</div>
									<div class="bar-val"><?php echo esc_html( number_format( $avg_q2, 1 ) ); ?></div>
								</div>
								<div class="track"><div class="fill" style="width:<?php echo esc_attr( number_format( $fill_pct( $avg_q2 ), 2, '.', '' ) ); ?>%;"></div></div>
							</div>
						</div>
						<div class="bar">
							<div class="bar-box">
								<div class="bar-top">
									<div class="bar-lab">Role-model</div>
									<div class="bar-val"><?php echo esc_html( number_format( $avg_q3, 1 ) ); ?></div>
								</div>
								<div class="track"><div class="fill" style="width:<?php echo esc_attr( number_format( $fill_pct( $avg_q3 ), 2, '.', '' ) ); ?>%;"></div></div>
							</div>
						</div>
					</div>

					<div class="hr"></div>

					<?php echo $engine_html !== '' ? $engine_html : '<p class="p" style="color:var(--muted);">Summary is not available yet.</p>'; ?>

					<div class="callout">
						<p class="callout-lab">Next week action</p>
						<p class="callout-txt"><?php echo esc_html( $next_week ); ?></p>
					</div>
				</div>
			</div>
		<?php
			$__comp_i++;
		endforeach;
		?>
	<?php endif; ?>

</body>
</html>
