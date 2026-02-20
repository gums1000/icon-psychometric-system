<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ICON Psychometric System — Admin Menu (COMPLETE FILE)
 *
 * FIXES INCLUDED (your issue):
 * - ✅ Settings submenu now has a REAL callback: render_settings_page()
 * - ✅ Feedback Results submenu now exists + REAL callback: render_feedback_results_page()
 * - ✅ Defensive table-exists checks to avoid “system has crashed” style failures from missing tables
 * - ✅ OpenAI key wiring INSIDE this file (saved option + masked display + optional update)
 * - ✅ Optional redirect/cleanup for legacy results slug (safe)
 *
 * This is the ADMIN MENU file (not the “main plugin bootstrap” file).
 * Your main plugin file should include/require this file (or this file can self-init at the bottom).
 */

/* ---------------------------------------------------------
 * Optional: Redirect legacy broken results page slug (safe)
 * --------------------------------------------------------- */
add_action( 'admin_init', function () {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( 'icon-psych-system-results' === $page ) {
		wp_safe_redirect( admin_url( 'admin.php?page=icon-psych-system-feedback-results' ) );
		exit;
	}
} );

/* ---------------------------------------------------------
 * Optional: Remove legacy submenu slug if added elsewhere (safe)
 * --------------------------------------------------------- */
add_action( 'admin_menu', function () {
	remove_submenu_page( 'icon-psych-system', 'icon-psych-system-results' );
}, 999 );

/**
 * Admin impersonation helpers (view client portal as a specific client).
 * (Kept for compatibility; Clients page no longer links to the client portal as requested.)
 */
if ( ! function_exists( 'icon_psy_get_client_portal_url' ) ) {
	function icon_psy_get_client_portal_url() {
		return home_url( '/client-portal/' ); // change if your slug differs
	}
}

if ( ! function_exists( 'icon_psy_set_impersonation' ) ) {
	function icon_psy_set_impersonation( $client_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), 'icon_psy_impersonate_client_id', (int) $client_id );
	}
}

if ( ! function_exists( 'icon_psy_clear_impersonation' ) ) {
	function icon_psy_clear_impersonation() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_user_meta( get_current_user_id(), 'icon_psy_impersonate_client_id' );
	}
}

if ( ! function_exists( 'icon_psy_get_effective_client_user_id' ) ) {
	function icon_psy_get_effective_client_user_id() {

		if ( ! is_user_logged_in() ) {
			return 0;
		}

		$current_id = (int) get_current_user_id();

		if ( current_user_can( 'manage_options' ) ) {
			$imp = (int) get_user_meta( $current_id, 'icon_psy_impersonate_client_id', true );
			if ( $imp > 0 ) {
				return $imp;
			}
		}

		return $current_id;
	}
}

/**
 * Handle ?icon_psy_impersonate=<id> and ?icon_psy_impersonate=stop
 */
add_action( 'init', function() {

	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( empty( $_GET['icon_psy_impersonate'] ) || empty( $_GET['_wpnonce'] ) ) {
		return;
	}

	$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
	if ( ! wp_verify_nonce( $nonce, 'icon_psy_impersonate' ) ) {
		return;
	}

	$val = sanitize_text_field( wp_unslash( $_GET['icon_psy_impersonate'] ) );

	if ( 'stop' === $val ) {
		icon_psy_clear_impersonation();
		return;
	}

	$client_id = (int) $val;
	if ( $client_id > 0 ) {
		$u = get_user_by( 'id', $client_id );
		if ( $u && in_array( 'icon_client', (array) $u->roles, true ) ) {
			icon_psy_set_impersonation( $client_id );
		}
	}
} );

class Icon_PSY_Admin_Menu {

	const SETTINGS_KEY    = 'icon_psy_settings';
	const OPENAI_KEY_OPT  = 'icon_psy_openai_api_key';

	/**
	 * Hook into admin_menu.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Register top-level and submenus.
	 */
	public static function register_menu() {

		// Top level
		add_menu_page(
			'Icon Psychometric System',
			'Icon Psych System',
			'manage_options',
			'icon-psych-system',
			array( __CLASS__, 'render_dashboard_page' ),
			'dashicons-chart-pie',
			26
		);

		// Clients
		add_submenu_page(
			'icon-psych-system',
			'Clients',
			'Clients',
			'manage_options',
			'icon-psych-system-clients',
			array( __CLASS__, 'render_clients_page' )
		);

		// Projects
		add_submenu_page(
			'icon-psych-system',
			'Projects',
			'Projects',
			'manage_options',
			'icon-psych-system-projects',
			array( __CLASS__, 'render_projects_page' )
		);

		// Participants
		add_submenu_page(
			'icon-psych-system',
			'Participants',
			'Participants',
			'manage_options',
			'icon-psych-system-participants',
			array( __CLASS__, 'render_participants_page' )
		);

		// Raters
		add_submenu_page(
			'icon-psych-system',
			'Raters',
			'Raters',
			'manage_options',
			'icon-psych-system-raters',
			array( __CLASS__, 'render_raters_page' )
		);

		// Frameworks
		add_submenu_page(
			'icon-psych-system',
			'Frameworks',
			'Frameworks',
			'manage_options',
			'icon-psych-system-frameworks',
			array( __CLASS__, 'render_frameworks_page' )
		);

		// AI Competency Designer
		if ( class_exists( 'Icon_PSY_AI_Competency_Designer' ) ) {
			add_submenu_page(
				'icon-psych-system',
				'AI Competency Designer',
				'AI Designer',
				'manage_options',
				'icon-psych-ai-competency-designer',
				array( 'Icon_PSY_AI_Competency_Designer', 'render_page' )
			);
		}

		// ✅ Feedback Results (FIX)
		add_submenu_page(
			'icon-psych-system',
			'Feedback Results',
			'Feedback Results',
			'manage_options',
			'icon-psych-system-feedback-results',
			array( __CLASS__, 'render_feedback_results_page' )
		);

		// ✅ Settings (FIX)
		add_submenu_page(
			'icon-psych-system',
			'Icon Psych Settings',
			'Settings',
			'manage_options',
			'icon-psych-system-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	// ------------------------------------------------------------
	// Utilities (defensive)
	// ------------------------------------------------------------

	protected static function table_exists( $table_name ) {
		global $wpdb;
		$like = $wpdb->esc_like( $table_name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $like ) );
		return ( $found === $table_name );
	}

	protected static function get_settings() {
		$defaults = array(
			'logo_url'           => '',
			'client_portal_slug' => '/client-portal/',
			'rater_portal_slug'  => '/rater-portal/',
			'report_slug'        => '/feedback-report/',
			'email_from_name'    => 'Icon Talent',
			'email_from_email'   => get_option( 'admin_email' ),
		);

		$saved = get_option( self::SETTINGS_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( $defaults, $saved );
	}

	// ---- OpenAI key helpers (wired) ----
	protected static function get_openai_key() {
		$k = get_option( self::OPENAI_KEY_OPT, '' );
		return is_string( $k ) ? trim( $k ) : '';
	}

	protected static function set_openai_key( $new_key ) {
		$new_key = is_string( $new_key ) ? trim( $new_key ) : '';
		if ( $new_key === '' ) {
			return false;
		}
		update_option( self::OPENAI_KEY_OPT, $new_key );
		return true;
	}

	protected static function mask_key( $key ) {
		$key = is_string( $key ) ? $key : '';
		$key = trim( $key );
		if ( $key === '' ) {
			return 'No key saved';
		}
		if ( strlen( $key ) <= 10 ) {
			return str_repeat( '•', max( 0, strlen( $key ) - 2 ) ) . substr( $key, -2 );
		}
		return substr( $key, 0, 6 ) . '…' . substr( $key, -4 );
	}

	/**
	 * Tiny JS/CSS helper for inline edit rows (Clients / Participants / Raters).
	 */
	protected static function render_inline_edit_assets() {
		?>
		<style>
			.icon-psy-edit-row{ display:none; background:#f8fafc; }
			.icon-psy-edit-row td{ padding:14px 12px !important; }
			.icon-psy-edit-grid{
				display:grid;
				grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
				gap:10px;
				align-items:end;
				margin-top:8px;
			}
			.icon-psy-edit-grid label{ font-weight:600; font-size:12px; color:#111827; display:block; margin-bottom:4px; }
			.icon-psy-edit-grid input[type="text"],
			.icon-psy-edit-grid input[type="email"],
			.icon-psy-edit-grid select{
				width:100%;
				max-width:520px;
			}
			.icon-psy-edit-actions{
				display:flex;
				gap:8px;
				flex-wrap:wrap;
				margin-top:12px;
			}
			.icon-psy-muted{ font-size:11px; color:#6b7280; }
		</style>
		<script>
			document.addEventListener('DOMContentLoaded', function(){
				document.querySelectorAll('[data-icon-psy-edit]').forEach(function(btn){
					btn.addEventListener('click', function(e){
						e.preventDefault();
						var id = btn.getAttribute('data-icon-psy-edit');
						var row = document.querySelector('[data-icon-psy-edit-row="' + id + '"]');
						if(!row) return;
						row.style.display = (row.style.display === 'table-row') ? 'none' : 'table-row';
					});
				});
				document.querySelectorAll('[data-icon-psy-cancel]').forEach(function(btn){
					btn.addEventListener('click', function(e){
						e.preventDefault();
						var id = btn.getAttribute('data-icon-psy-cancel');
						var row = document.querySelector('[data-icon-psy-edit-row="' + id + '"]');
						if(!row) return;
						row.style.display = 'none';
					});
				});
			});
		</script>
		<?php
	}

	// ------------------------------------------------------------
	// Tables (frameworks)
	// ------------------------------------------------------------

	/**
	 * Ensure frameworks + competencies tables exist.
	 */
	protected static function maybe_create_framework_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate    = $wpdb->get_charset_collate();
		$frameworks_table   = $wpdb->prefix . 'icon_psy_frameworks';
		$competencies_table = $wpdb->prefix . 'icon_psy_framework_competencies';

		// Frameworks
		$sql1 = "CREATE TABLE {$frameworks_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(100) NULL,
			name VARCHAR(190) NOT NULL,
			type VARCHAR(50) NULL,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(50) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY slug (slug),
			KEY is_default (is_default),
			KEY status (status)
		) {$charset_collate};";

		// Competencies
		$sql2 = "CREATE TABLE {$competencies_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			framework_id BIGINT(20) UNSIGNED NOT NULL,
			code VARCHAR(64) NULL,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			module VARCHAR(50) NOT NULL DEFAULT 'core',
			sort_order INT(11) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY framework_id (framework_id),
			KEY module (module),
			KEY sort_order (sort_order)
		) {$charset_collate};";

		dbDelta( $sql1 );
		dbDelta( $sql2 );
	}

	// ------------------------------------------------------------
	// Pages
	// ------------------------------------------------------------

	/**
	 * Simple dashboard page.
	 */
	public static function render_dashboard_page() {
		?>
		<div class="wrap">
			<h1>Icon Psychometric System</h1>
			<p>Welcome to the Icon Psych System admin dashboard.</p>
		</div>
		<?php
	}

	/**
	 * ✅ SETTINGS PAGE (WIRED + OPENAI KEY)
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get_settings();
		$message  = '';
		$class    = 'updated';

		if ( isset( $_POST['icon_psy_settings_action'] ) ) {
			check_admin_referer( 'icon_psy_settings' );

			$settings['logo_url']           = isset( $_POST['logo_url'] ) ? esc_url_raw( wp_unslash( $_POST['logo_url'] ) ) : $settings['logo_url'];
			$settings['client_portal_slug'] = isset( $_POST['client_portal_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['client_portal_slug'] ) ) : $settings['client_portal_slug'];
			$settings['rater_portal_slug']  = isset( $_POST['rater_portal_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['rater_portal_slug'] ) ) : $settings['rater_portal_slug'];
			$settings['report_slug']        = isset( $_POST['report_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['report_slug'] ) ) : $settings['report_slug'];

			$settings['email_from_name']  = isset( $_POST['email_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['email_from_name'] ) ) : $settings['email_from_name'];
			$settings['email_from_email'] = isset( $_POST['email_from_email'] ) ? sanitize_email( wp_unslash( $_POST['email_from_email'] ) ) : $settings['email_from_email'];

			// OpenAI key optional update: leave blank = keep existing
			if ( isset( $_POST['openai_api_key'] ) ) {
				$candidate = trim( (string) wp_unslash( $_POST['openai_api_key'] ) );
				if ( $candidate !== '' ) {
					self::set_openai_key( $candidate );
				}
			}

			update_option( self::SETTINGS_KEY, $settings );

			$message = 'Settings saved.';
			$class   = 'updated';
		}

		$saved_key  = self::get_openai_key();
		$masked_key = self::mask_key( $saved_key );
		?>
		<div class="wrap">
			<h1>Settings</h1>

			<div class="notice notice-info">
				<p><strong>OpenAI integration:</strong> Uses your saved OpenAI key.</p>
			</div>

			<?php if ( $message ) : ?>
				<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" style="max-width: 900px;">
				<?php wp_nonce_field( 'icon_psy_settings' ); ?>
				<input type="hidden" name="icon_psy_settings_action" value="save" />

				<table class="form-table" role="presentation">

					<tr>
						<th scope="row"><label for="openai_api_key">OpenAI API key</label></th>
						<td>
							<p style="margin:0 0 8px 0;">
								Saved: <code><?php echo esc_html( $masked_key ); ?></code>
							</p>
							<input type="password" id="openai_api_key" name="openai_api_key" class="regular-text" style="width:100%;max-width:560px;"
								   value="" placeholder="Paste a new key to update (leave blank to keep current)" />
							<p class="description">This key will be used by the AI Designer and any OpenAI-powered functions in the plugin.</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="logo_url">Logo URL</label></th>
						<td>
							<input type="url" id="logo_url" name="logo_url" class="regular-text" style="width:100%;max-width:560px;"
								   value="<?php echo esc_attr( $settings['logo_url'] ); ?>" />
							<p class="description">Used wherever you want a configurable logo (optional).</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="client_portal_slug">Client portal slug</label></th>
						<td>
							<input type="text" id="client_portal_slug" name="client_portal_slug" class="regular-text"
								   value="<?php echo esc_attr( $settings['client_portal_slug'] ); ?>" />
							<p class="description">Example: <code>/client-portal/</code></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="rater_portal_slug">Rater portal slug</label></th>
						<td>
							<input type="text" id="rater_portal_slug" name="rater_portal_slug" class="regular-text"
								   value="<?php echo esc_attr( $settings['rater_portal_slug'] ); ?>" />
							<p class="description">Example: <code>/rater-portal/</code></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="report_slug">Report slug</label></th>
						<td>
							<input type="text" id="report_slug" name="report_slug" class="regular-text"
								   value="<?php echo esc_attr( $settings['report_slug'] ); ?>" />
							<p class="description">Example: <code>/feedback-report/</code></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="email_from_name">Email From name</label></th>
						<td>
							<input type="text" id="email_from_name" name="email_from_name" class="regular-text"
								   value="<?php echo esc_attr( $settings['email_from_name'] ); ?>" />
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="email_from_email">Email From address</label></th>
						<td>
							<input type="email" id="email_from_email" name="email_from_email" class="regular-text"
								   value="<?php echo esc_attr( $settings['email_from_email'] ); ?>" />
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Settings', 'primary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * ✅ FEEDBACK RESULTS PAGE (FIXED)
	 * Shows latest results header rows in icon_assessment_results.
	 * Defensive: if table missing, it shows a warning instead of “crashed”.
	 */
	public static function render_feedback_results_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		$results_header_table = $wpdb->prefix . 'icon_assessment_results';
		$results_detail_table = $wpdb->prefix . 'icon_psy_results';
		$participants_table   = $wpdb->prefix . 'icon_psy_participants';
		$projects_table       = $wpdb->prefix . 'icon_psy_projects';
		$raters_table         = $wpdb->prefix . 'icon_psy_raters';

		$missing = array();
		foreach ( array( $results_header_table, $participants_table, $projects_table, $raters_table ) as $t ) {
			if ( ! self::table_exists( $t ) ) {
				$missing[] = $t;
			}
		}

		?>
		<div class="wrap">
			<h1>Feedback Results</h1>
			<p>Admin view of submitted results (header records). Useful for checking submissions are landing.</p>

			<?php if ( ! empty( $missing ) ) : ?>
				<div class="notice notice-warning">
					<p><strong>Some tables are missing</strong> (so we’re showing limited info, not crashing):</p>
					<ul style="margin-left:18px;">
						<?php foreach ( $missing as $m ) : ?>
							<li><code><?php echo esc_html( $m ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php
			if ( ! self::table_exists( $results_header_table ) ) {
				echo '<p><em>No results table found yet.</em></p></div>';
				return;
			}

			$limit = 200;

			$select = "r.*";
			$join   = "";
			if ( self::table_exists( $participants_table ) ) {
				$select .= ", p.name AS participant_name, p.email AS participant_email, p.project_id AS participant_project_id";
				$join   .= " LEFT JOIN {$participants_table} p ON r.participant_id = p.id";
			}
			if ( self::table_exists( $projects_table ) && self::table_exists( $participants_table ) ) {
				$select .= ", pr.name AS project_name, pr.client_name AS client_name";
				$join   .= " LEFT JOIN {$projects_table} pr ON p.project_id = pr.id";
			}
			if ( self::table_exists( $raters_table ) ) {
				$select .= ", rt.name AS rater_name, rt.email AS rater_email, rt.relationship AS rater_relationship";
				$join   .= " LEFT JOIN {$raters_table} rt ON r.rater_id = rt.id";
			}

			$participant_filter = isset( $_GET['participant_id'] ) ? (int) $_GET['participant_id'] : 0;
			$where = "WHERE 1=1";
			if ( $participant_filter > 0 ) {
				$where .= $wpdb->prepare( " AND r.participant_id = %d", $participant_filter );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$rows = $wpdb->get_results(
				"SELECT {$select}
				 FROM {$results_header_table} r
				 {$join}
				 {$where}
				 ORDER BY r.created_at DESC
				 LIMIT " . (int) $limit
			);
			?>

			<div style="max-width:1200px;margin-top:12px;">
				<div style="padding:12px 14px;border:1px solid #e5e7eb;background:#fff;border-radius:10px;margin-bottom:12px;">
					<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
						<input type="hidden" name="page" value="icon-psych-system-feedback-results">
						<div>
							<label for="participant_id"><strong>Filter by participant ID</strong></label><br>
							<input type="number" id="participant_id" name="participant_id" value="<?php echo esc_attr( $participant_filter ); ?>" style="width:200px;">
						</div>
						<div>
							<button class="button button-primary" type="submit">Apply</button>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=icon-psych-system-feedback-results' ) ); ?>">Clear</a>
						</div>
					</form>
				</div>

				<table class="widefat striped">
					<thead>
						<tr>
							<th>ID</th>
							<th>Created</th>
							<th>Status</th>
							<th>Participant</th>
							<th>Project</th>
							<th>Rater</th>
							<th>Overall (q1_rating)</th>
							<th>Detail rows</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="8"><em>No results found.</em></td></tr>
						<?php else : ?>
							<?php foreach ( $rows as $r ) : ?>
								<?php
								$pid   = isset( $r->participant_id ) ? (int) $r->participant_id : 0;
								$rid   = isset( $r->rater_id ) ? (int) $r->rater_id : 0;
								$pname = ( isset( $r->participant_name ) && $r->participant_name ) ? $r->participant_name : ( $pid ? 'Participant #' . $pid : '—' );
								$proj  = ( isset( $r->project_name ) && $r->project_name ) ? $r->project_name : '—';
								$rname = ( isset( $r->rater_name ) && $r->rater_name ) ? $r->rater_name : ( $rid ? 'Rater #' . $rid : '—' );
								$q1    = isset( $r->q1_rating ) ? $r->q1_rating : '';
								$stat  = isset( $r->status ) ? $r->status : '';
								$created = isset( $r->created_at ) ? $r->created_at : '';

								$detail_count = '—';
								if ( self::table_exists( $results_detail_table ) && $pid > 0 ) {
									// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
									$detail_count = (int) $wpdb->get_var(
										$wpdb->prepare(
											"SELECT COUNT(*) FROM {$results_detail_table} WHERE participant_id = %d",
											$pid
										)
									);
								}
								?>
								<tr>
									<td><?php echo isset( $r->id ) ? (int) $r->id : 0; ?></td>
									<td><?php echo esc_html( $created ); ?></td>
									<td><?php echo esc_html( $stat ); ?></td>
									<td>
										<strong><?php echo esc_html( $pname ); ?></strong><br>
										<span style="font-size:11px;color:#6b7280;">ID: <?php echo (int) $pid; ?></span>
									</td>
									<td><?php echo esc_html( $proj ); ?></td>
									<td>
										<?php echo esc_html( $rname ); ?>
										<?php if ( isset( $r->rater_relationship ) && $r->rater_relationship ) : ?>
											<br><span style="font-size:11px;color:#6b7280;"><?php echo esc_html( $r->rater_relationship ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $q1 ); ?></td>
									<td><?php echo esc_html( $detail_count ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<?php if ( ! empty( $wpdb->last_error ) ) : ?>
					<div class="notice notice-warning" style="margin-top:12px;">
						<p><strong>DB notice:</strong> <?php echo esc_html( $wpdb->last_error ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * PROJECTS PAGE
	 * (Your complete original page output + action chain)
	 */
	public static function render_projects_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		self::maybe_create_framework_tables();
		self::maybe_add_token_column_to_raters_table();
		self::maybe_add_framework_column_to_projects_table();

		$projects_table        = $wpdb->prefix . 'icon_psy_projects';
		$participants_table    = $wpdb->prefix . 'icon_psy_participants';
		$raters_table          = $wpdb->prefix . 'icon_psy_raters';
		$frameworks_table      = $wpdb->prefix . 'icon_psy_frameworks';
		$results_header_table  = $wpdb->prefix . 'icon_assessment_results';
		$results_detail_table  = $wpdb->prefix . 'icon_psy_results';

		// Defensive: if core tables missing, show notice instead of “crash”
		$need = array( $projects_table, $participants_table, $raters_table );
		$missing = array();
		foreach ( $need as $t ) {
			if ( ! self::table_exists( $t ) ) {
				$missing[] = $t;
			}
		}

		if ( ! empty( $missing ) ) {
			?>
			<div class="wrap">
				<h1>Projects</h1>
				<div class="notice notice-warning">
					<p><strong>Missing required tables:</strong></p>
					<ul style="margin-left:18px;">
						<?php foreach ( $missing as $m ) : ?>
							<li><code><?php echo esc_html( $m ); ?></code></li>
						<?php endforeach; ?>
					</ul>
					<p>Fix the DB schema first (plugin activation / installer), then reload this page.</p>
				</div>
			</div>
			<?php
			return;
		}

		// Load frameworks for dropdowns + labels
		$frameworks = $wpdb->get_results(
			"SELECT * FROM {$frameworks_table} ORDER BY is_default DESC, name ASC"
		);

		$frameworks_by_id     = array();
		$default_framework_id = 0;

		if ( ! empty( $frameworks ) ) {
			foreach ( $frameworks as $fw ) {
				$frameworks_by_id[ (int) $fw->id ] = $fw;
				if ( $default_framework_id === 0 && (int) $fw->is_default === 1 ) {
					$default_framework_id = (int) $fw->id;
				}
			}
			if ( $default_framework_id === 0 ) {
				$default_framework_id = (int) $frameworks[0]->id;
			}
		}

		$message       = '';
		$message_class = 'updated';

		/**
		 * Handle admin-side actions: add/delete project, participant, rater, and send emails.
		 * ✅ one continuous if/elseif chain
		 */
		if ( isset( $_POST['icon_psy_projects_action'] ) ) {
			check_admin_referer( 'icon_psy_projects' );
			$action = sanitize_key( wp_unslash( $_POST['icon_psy_projects_action'] ) );

			// ADD PROJECT
			if ( 'add_project' === $action ) {

				$name         = isset( $_POST['project_name'] ) ? sanitize_text_field( wp_unslash( $_POST['project_name'] ) ) : '';
				$client_name  = isset( $_POST['client_name'] ) ? sanitize_text_field( wp_unslash( $_POST['client_name'] ) ) : '';
				$status       = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'draft';
				$framework_id = isset( $_POST['framework_id'] ) ? (int) $_POST['framework_id'] : 0;

				if ( $name === '' ) {
					$message       = 'Project name is required.';
					$message_class = 'error';
				} elseif ( $framework_id <= 0 ) {
					$message       = 'Please select a framework for this project.';
					$message_class = 'error';
				} else {
					$data = array(
						'name'         => $name,
						'client_name'  => $client_name,
						'status'       => $status,
						'framework_id' => $framework_id,
						'created_at'   => current_time( 'mysql' ),
					);
					$formats = array( '%s', '%s', '%s', '%d', '%s' );

					$inserted = $wpdb->insert( $projects_table, $data, $formats );

					if ( false === $inserted ) {
						// Backward compatibility if framework_id column missing
						unset( $data['framework_id'] );
						$formats = array( '%s', '%s', '%s', '%s' );

						$fallback = $wpdb->insert( $projects_table, $data, $formats );

						if ( false === $fallback ) {
							$message       = 'There was a problem creating the project.';
							$message_class = 'error';
						} else {
							$message       = 'Project created, but the framework could not be stored. Please edit the project once your database is updated.';
							$message_class = 'updated';
						}
					} else {
						$message       = 'Project added.';
						$message_class = 'updated';
					}
				}

			// DELETE PROJECT (and all related data)
			} elseif ( 'delete_project' === $action ) {

				$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
				if ( $project_id > 0 ) {

					// Gather participant IDs for this project
					$participant_ids = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT id FROM {$participants_table} WHERE project_id = %d",
							$project_id
						)
					);

					if ( ! empty( $participant_ids ) ) {
						$participant_ids = array_map( 'intval', $participant_ids );
						$placeholders    = implode( ',', array_fill( 0, count( $participant_ids ), '%d' ) );

						// Delete raters linked to those participants
						$wpdb->query(
							$wpdb->prepare(
								"DELETE FROM {$raters_table} WHERE participant_id IN ($placeholders)",
								$participant_ids
							)
						);

						// Delete per-competency results for those participants
						if ( self::table_exists( $results_detail_table ) ) {
							$wpdb->query(
								$wpdb->prepare(
									"DELETE FROM {$results_detail_table} WHERE participant_id IN ($placeholders)",
									$participant_ids
								)
							);
						}

						// Delete header results for those participants
						if ( self::table_exists( $results_header_table ) ) {
							$wpdb->query(
								$wpdb->prepare(
									"DELETE FROM {$results_header_table} WHERE participant_id IN ($placeholders)",
									$participant_ids
								)
							);
						}

						// Finally delete the participants
						$wpdb->query(
							$wpdb->prepare(
								"DELETE FROM {$participants_table} WHERE id IN ($placeholders)",
								$participant_ids
							)
						);
					}

					// Delete any stray header/detail records still pointing at this project
					if ( self::table_exists( $results_detail_table ) ) {
						$wpdb->delete(
							$results_detail_table,
							array( 'project_id' => $project_id ),
							array( '%d' )
						);
					}
					if ( self::table_exists( $results_header_table ) ) {
						$wpdb->delete(
							$results_header_table,
							array( 'project_id' => $project_id ),
							array( '%d' )
						);
					}

					// Delete the project itself
					$wpdb->delete(
						$projects_table,
						array( 'id' => $project_id ),
						array( '%d' )
					);

					$message       = 'Project, its participants/raters, and all related feedback have been deleted.';
					$message_class = 'updated';
				}

			// ADD PARTICIPANT
			} elseif ( 'add_participant' === $action ) {

				$project_id        = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
				$participant_name  = isset( $_POST['participant_name'] ) ? sanitize_text_field( wp_unslash( $_POST['participant_name'] ) ) : '';
				$participant_email = isset( $_POST['participant_email'] ) ? sanitize_email( wp_unslash( $_POST['participant_email'] ) ) : '';
				$participant_role  = isset( $_POST['participant_role'] ) ? sanitize_text_field( wp_unslash( $_POST['participant_role'] ) ) : '';

				if ( $project_id <= 0 || '' === $participant_name ) {
					$message       = 'Participant name and project are required.';
					$message_class = 'error';
				} else {
					$wpdb->insert(
						$participants_table,
						array(
							'project_id' => $project_id,
							'name'       => $participant_name,
							'email'      => $participant_email,
							'role'       => $participant_role,
							'created_at' => current_time( 'mysql' ),
						),
						array( '%d', '%s', '%s', '%s', '%s' )
					);
					$message       = 'Participant added.';
					$message_class = 'updated';
				}

			// DELETE PARTICIPANT
			} elseif ( 'delete_participant' === $action ) {

				$participant_id = isset( $_POST['participant_id'] ) ? (int) $_POST['participant_id'] : 0;
				if ( $participant_id > 0 ) {

					// Delete raters for this participant
					$wpdb->delete(
						$raters_table,
						array( 'participant_id' => $participant_id ),
						array( '%d' )
					);

					// Delete per-competency results
					if ( self::table_exists( $results_detail_table ) ) {
						$wpdb->delete(
							$results_detail_table,
							array( 'participant_id' => $participant_id ),
							array( '%d' )
						);
					}

					// Delete header results
					if ( self::table_exists( $results_header_table ) ) {
						$wpdb->delete(
							$results_header_table,
							array( 'participant_id' => $participant_id ),
							array( '%d' )
						);
					}

					// Delete participant
					$wpdb->delete(
						$participants_table,
						array( 'id' => $participant_id ),
						array( '%d' )
					);

					$message       = 'Participant, their raters and all related feedback deleted.';
					$message_class = 'updated';
				}

			// ADD RATER (creates token + sends invite)
			} elseif ( 'add_rater' === $action ) {

				$participant_id     = isset( $_POST['participant_id'] ) ? (int) $_POST['participant_id'] : 0;
				$rater_name         = isset( $_POST['rater_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rater_name'] ) ) : '';
				$rater_email        = isset( $_POST['rater_email'] ) ? sanitize_email( wp_unslash( $_POST['rater_email'] ) ) : '';
				$rater_relationship = isset( $_POST['rater_relationship'] ) ? sanitize_text_field( wp_unslash( $_POST['rater_relationship'] ) ) : '';

				if ( $participant_id <= 0 || '' === $rater_name ) {
					$message       = 'Rater name and participant are required.';
					$message_class = 'error';
				} else {

					$token = wp_generate_password( 32, false, false );

					$wpdb->insert(
						$raters_table,
						array(
							'participant_id' => $participant_id,
							'name'           => $rater_name,
							'email'          => $rater_email,
							'relationship'   => $rater_relationship,
							'token'          => $token,
							'status'         => 'pending',
							'created_at'     => current_time( 'mysql' ),
						),
						array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
					);

					$message       = 'Rater added and invite sent.';
					$message_class = 'updated';

					if ( ! empty( $rater_email ) ) {
						$rater_link = site_url( '/rater-portal/?token=' . urlencode( $token ) );

						$subject = 'Your Icon Catalyst Feedback Request';

						$body  = "Hi {$rater_name},\n\n";
						$body .= "You have been invited to provide confidential feedback as part of the Icon Catalyst System™.\n\n";
						$body .= "Your feedback link is below:\n\n";
						$body .= "{$rater_link}\n\n";
						$body .= "This link is unique to you and should take around 5–10 minutes to complete.\n\n";
						$body .= "Thank you for supporting this developmental process.\n\n";
						$body .= "Icon Talent";

						$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

						wp_mail( $rater_email, $subject, $body, $headers );
					}
				}

			// DELETE RATER
			} elseif ( 'delete_rater' === $action ) {

				$rater_id = isset( $_POST['rater_id'] ) ? (int) $_POST['rater_id'] : 0;
				if ( $rater_id > 0 ) {

					// Delete per-competency results for this rater
					if ( self::table_exists( $results_detail_table ) ) {
						$wpdb->delete(
							$results_detail_table,
							array( 'rater_id' => $rater_id ),
							array( '%d' )
						);
					}

					// Delete header results for this rater
					if ( self::table_exists( $results_header_table ) ) {
						$wpdb->delete(
							$results_header_table,
							array( 'rater_id' => $rater_id ),
							array( '%d' )
						);
					}

					// Delete the rater
					$wpdb->delete(
						$raters_table,
						array( 'id' => $rater_id ),
						array( '%d' )
					);

					$message       = 'Rater and all their feedback deleted.';
					$message_class = 'updated';
				}

			// EMAIL PARTICIPANT (manual send)
			} elseif ( 'email_participant' === $action ) {

				$participant_id = isset( $_POST['participant_id'] ) ? (int) $_POST['participant_id'] : 0;

				if ( $participant_id > 0 ) {
					$participant = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT p.*, pr.name AS project_name, pr.client_name AS project_client
							 FROM {$participants_table} p
							 LEFT JOIN {$projects_table} pr ON p.project_id = pr.id
							 WHERE p.id = %d",
							$participant_id
						)
					);

					if ( $participant && ! empty( $participant->email ) ) {
						$to   = $participant->email;
						$name = $participant->name ? $participant->name : 'Leader';

						$project_name   = $participant->project_name ? $participant->project_name : 'your 360° feedback project';
						$project_client = $participant->project_client ? $participant->project_client : '';

						$subject = 'Your Icon Catalyst 360° Feedback Project';

						$body  = "Hi {$name},\n\n";
						$body .= "You have been included in an Icon Catalyst 360° feedback project";
						if ( $project_client ) {
							$body .= " for {$project_client}";
						}
						$body .= " ({$project_name}).\n\n";
						$body .= "Over the coming days, your nominated raters (manager, peers and direct reports) will receive";
						$body .= " a confidential feedback survey. Once the process is complete, you will receive an Icon Catalyst feedback report\n";
						$body .= " to support your leadership and development.\n\n";
						$body .= "If you have any questions, please contact your programme sponsor or the Icon Talent team.\n\n";
						$body .= "Icon Talent";

						$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

						wp_mail( $to, $subject, $body, $headers );

						$message       = 'Email sent to participant.';
						$message_class = 'updated';
					} else {
						$message       = 'No valid email address found for this participant.';
						$message_class = 'error';
					}
				}

			// EMAIL RATER (manual send / resend)
			} elseif ( 'email_rater' === $action ) {

				$rater_id = isset( $_POST['rater_id'] ) ? (int) $_POST['rater_id'] : 0;

				if ( $rater_id > 0 ) {
					$rater = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT r.*, p.name AS participant_name
							 FROM {$raters_table} r
							 LEFT JOIN {$participants_table} p ON r.participant_id = p.id
							 WHERE r.id = %d",
							$rater_id
						)
					);

					if ( $rater && ! empty( $rater->email ) ) {
						$token = isset( $rater->token ) ? (string) $rater->token : '';

						if ( empty( $token ) ) {
							$token = wp_generate_password( 32, false, false );
							$wpdb->update(
								$raters_table,
								array( 'token' => $token ),
								array( 'id' => $rater_id ),
								array( '%s' ),
								array( '%d' )
							);
						}

						$rater_name       = $rater->name ? $rater->name : 'Colleague';
						$participant_name = $rater->participant_name ? $rater->participant_name : 'the participant';

						$rater_link = site_url( '/rater-portal/?token=' . urlencode( $token ) );

						$subject = 'Your Icon Catalyst Feedback Request (reminder)';

						$body  = "Hi {$rater_name},\n\n";
						$body .= "This is a reminder to provide confidential feedback for {$participant_name} ";
						$body .= "as part of the Icon Catalyst System™.\n\n";
						$body .= "Your unique feedback link is below:\n\n";
						$body .= "{$rater_link}\n\n";
						$body .= "The survey should take around 5–10 minutes to complete and your responses will remain confidential.\n\n";
						$body .= "Thank you for supporting this developmental process.\n\n";
						$body .= "Icon Talent";

						$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

						wp_mail( $rater->email, $subject, $body, $headers );

						$message       = 'Invite email sent to rater.';
						$message_class = 'updated';
					} else {
						$message       = 'No valid email address found for this rater.';
						$message_class = 'error';
					}
				}

			} // end action chain
		} // end if POST action

		// Fetch all projects
		$projects = $wpdb->get_results(
			"SELECT * FROM {$projects_table} ORDER BY created_at DESC"
		);

		// Fetch all participants
		$participants = $wpdb->get_results(
			"SELECT * FROM {$participants_table} ORDER BY created_at ASC"
		);

		$participants_by_project = array();
		if ( ! empty( $participants ) ) {
			foreach ( $participants as $p ) {
				$pid = (int) $p->project_id;
				if ( ! isset( $participants_by_project[ $pid ] ) ) {
					$participants_by_project[ $pid ] = array();
				}
				$participants_by_project[ $pid ][] = $p;
			}
		}

		// Fetch all raters
		$raters = $wpdb->get_results(
			"SELECT * FROM {$raters_table} ORDER BY created_at ASC"
		);

		$raters_by_participant = array();
		if ( ! empty( $raters ) ) {
			foreach ( $raters as $r ) {
				$pid = (int) $r->participant_id;
				if ( ! isset( $raters_by_participant[ $pid ] ) ) {
					$raters_by_participant[ $pid ] = array();
				}
				$raters_by_participant[ $pid ][] = $r;
			}
		}
		?>
		<div class="wrap">
			<h1>Projects</h1>
			<p>Manage projects, participants, raters and email invitations. Each project is linked to a framework.</p>

			<?php if ( $message ) : ?>
				<div class="notice <?php echo esc_attr( $message_class ); ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<style>
				.icon-psy-admin-projects .icon-psy-card {
					background:#ffffff;
					border-radius:10px;
					padding:14px 16px;
					box-shadow:0 1px 4px rgba(0,0,0,0.06);
					border:1px solid #e3f0ea;
					margin-bottom:14px;
				}
				.icon-psy-admin-projects .icon-psy-project-header {
					display:flex;
					justify-content:space-between;
					align-items:flex-start;
					gap:10px;
					cursor:pointer;
				}
				.icon-psy-admin-projects .icon-psy-project-header:hover {
					background:#f0fdf4;
					border-radius:8px;
				}
				.icon-psy-admin-projects .icon-psy-project-title {
					font-weight:600;
					font-size:15px;
					color:#111827;
				}
				.icon-psy-admin-projects .icon-psy-subtitle {
					font-size:13px;
					color:#4b5563;
					margin-top:2px;
				}
				.icon-psy-admin-projects .icon-psy-chip-row {
					display:flex;
					flex-wrap:wrap;
					gap:6px;
					margin-top:6px;
					font-size:11px;
					color:#374151;
				}
				.icon-psy-admin-projects .icon-psy-chip {
					display:inline-flex;
					align-items:center;
					gap:4px;
					padding:2px 8px;
					border-radius:999px;
					background:#ecfdf5;
					color:#064e3b;
				}
				.icon-psy-admin-projects .icon-psy-chip-muted {
					background:#f3f4f6;
					color:#374151;
				}
				.icon-psy-admin-projects .icon-psy-project-body {
					margin-top:10px;
					border-top:1px solid #e5e7eb;
					padding-top:8px;
					display:none;
				}
				.icon-psy-admin-projects .icon-psy-participant-row {
					border-radius:8px;
					border:1px solid #edf2f7;
					padding:8px 10px;
					margin-bottom:8px;
					background:#f9fafb;
				}
				.icon-psy-admin-projects .icon-psy-participant-header {
					display:flex;
					justify-content:space-between;
					align-items:flex-start;
					gap:8px;
					cursor:pointer;
				}
				.icon-psy-admin-projects .icon-psy-participant-name {
					font-size:14px;
					font-weight:600;
					color:#111827;
				}
				.icon-psy-admin-projects .icon-psy-participant-meta {
					margin-top:4px;
					display:flex;
					flex-wrap:wrap;
					gap:6px;
					font-size:11px;
					color:#4b5563;
				}
				.icon-psy-admin-projects .icon-psy-rater-list {
					margin-top:8px;
					border-top:1px dashed #e5e7eb;
					padding-top:6px;
					display:none;
				}
				.icon-psy-admin-projects .icon-psy-rater-row {
					display:flex;
					justify-content:space-between;
					gap:8px;
					padding:4px 0;
					border-bottom:1px solid #f3f4f6;
					font-size:12px;
					color:#374151;
				}
				.icon-psy-admin-projects .icon-psy-rater-row:last-child {
					border-bottom:none;
				}
				.icon-psy-admin-projects .icon-psy-d-inline-form {
					margin-top:8px;
					display:flex;
					flex-wrap:wrap;
					gap:6px;
					align-items:center;
				}
				.icon-psy-admin-projects .icon-psy-d-inline-form input[type="text"],
				.icon-psy-admin-projects .icon-psy-d-inline-form input[type="email"] {
					width:160px;
				}
				.icon-psy-admin-projects .icon-psy-chevron {
					font-size:16px;
					line-height:1;
				}
			</style>

			<div class="icon-psy-admin-projects" style="max-width:960px; margin-top:16px;">

				<!-- Add Project -->
				<div class="icon-psy-card">
					<h2 style="margin-top:0;">Add Project</h2>
					<form method="post">
						<?php wp_nonce_field( 'icon_psy_projects' ); ?>
						<input type="hidden" name="icon_psy_projects_action" value="add_project" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="icon-psy-project-name">Project name</label></th>
								<td>
									<input type="text" id="icon-psy-project-name" name="project_name" class="regular-text" required />
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="icon-psy-client-name">Client name</label></th>
								<td>
									<input type="text" id="icon-psy-client-name" name="client_name" class="regular-text" />
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="icon-psy-project-status">Status</label></th>
								<td>
									<select id="icon-psy-project-status" name="status">
										<option value="draft">Draft</option>
										<option value="active">Active</option>
										<option value="closed">Closed</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="icon-psy-framework-id">Framework</label></th>
								<td>
									<?php self::render_framework_select( 'framework_id', $default_framework_id ); ?>
									<p class="description">
										This framework will drive the competencies / questions for this project (required).
									</p>
								</td>
							</tr>
						</table>
						<?php submit_button( 'Add Project', 'primary' ); ?>
					</form>
				</div>

				<?php if ( empty( $projects ) ) : ?>
					<p><em>No projects found.</em></p>
				<?php else : ?>
					<?php foreach ( $projects as $project ) : ?>
						<?php
						$project_id   = (int) $project->id;
						$project_name = $project->name ? $project->name : 'Untitled project';

						$project_participants = isset( $participants_by_project[ $project_id ] )
							? $participants_by_project[ $project_id ]
							: array();

						$project_framework = null;
						if ( ! empty( $project->framework_id ) && isset( $frameworks_by_id[ (int) $project->framework_id ] ) ) {
							$project_framework = $frameworks_by_id[ (int) $project->framework_id ];
						}
						?>
						<div class="icon-psy-card" data-project="<?php echo esc_attr( $project_id ); ?>">
							<div class="icon-psy-project-header" data-toggle-project="<?php echo esc_attr( $project_id ); ?>">
								<div>
									<div class="icon-psy-project-title">
										<?php echo esc_html( $project_name ); ?>
									</div>
									<?php if ( ! empty( $project->client_name ) ) : ?>
										<div class="icon-psy-subtitle">
											Client: <?php echo esc_html( $project->client_name ); ?>
										</div>
									<?php endif; ?>
									<div class="icon-psy-chip-row">
										<span class="icon-psy-chip">
											Participants: <?php echo esc_html( count( $project_participants ) ); ?>
										</span>
										<?php if ( ! empty( $project->status ) ) : ?>
											<span class="icon-psy-chip icon-psy-chip-muted">
												Status: <?php echo esc_html( $project->status ); ?>
											</span>
										<?php endif; ?>
										<?php if ( $project_framework ) : ?>
											<span class="icon-psy-chip icon-psy-chip-muted">
												Framework: <?php echo esc_html( $project_framework->name ); ?>
												<?php if ( (int) $project_framework->is_default === 1 ) : ?>
													(core)
												<?php endif; ?>
											</span>
										<?php endif; ?>
									</div>
								</div>
								<div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
									<form method="post" onsubmit="return confirm('Delete this project and all its participants/raters?');">
										<?php wp_nonce_field( 'icon_psy_projects' ); ?>
										<input type="hidden" name="icon_psy_projects_action" value="delete_project" />
										<input type="hidden" name="project_id" value="<?php echo esc_attr( $project_id ); ?>" />
										<button type="submit" class="button button-secondary button-link-delete">Delete</button>
									</form>
									<div style="font-size:12px; color:#047857; display:flex; align-items:center; gap:4px;">
										<span>View participants</span>
										<span class="icon-psy-chevron">▾</span>
									</div>
								</div>
							</div>

							<div class="icon-psy-project-body" data-project-body="<?php echo esc_attr( $project_id ); ?>">
								<!-- Add participant inline form -->
								<form method="post" class="icon-psy-d-inline-form">
									<?php wp_nonce_field( 'icon_psy_projects' ); ?>
									<input type="hidden" name="icon_psy_projects_action" value="add_participant" />
									<input type="hidden" name="project_id" value="<?php echo esc_attr( $project_id ); ?>" />
									<input type="text" name="participant_name" placeholder="Participant name" required />
									<input type="email" name="participant_email" placeholder="Email" />
									<input type="text" name="participant_role" placeholder="Role" />
									<button type="submit" class="button">Add participant</button>
								</form>

								<?php if ( empty( $project_participants ) ) : ?>
									<p style="font-size:13px; color:#6b7280; margin-top:6px;">
										No participants yet for this project.
									</p>
								<?php else : ?>
									<?php foreach ( $project_participants as $participant ) : ?>
										<?php
										$participant_id   = (int) $participant->id;
										$participant_name = $participant->name ? $participant->name : 'Unnamed participant';

										$participant_raters = isset( $raters_by_participant[ $participant_id ] )
											? $raters_by_participant[ $participant_id ]
											: array();
										?>
										<div class="icon-psy-participant-row" data-participant="<?php echo esc_attr( $participant_id ); ?>">
											<div class="icon-psy-participant-header" data-toggle-participant="<?php echo esc_attr( $participant_id ); ?>">
												<div>
													<div class="icon-psy-participant-name">
														<?php echo esc_html( $participant_name ); ?>
													</div>
													<div class="icon-psy-participant-meta">
														<?php if ( ! empty( $participant->email ) ) : ?>
															<span><?php echo esc_html( $participant->email ); ?></span>
														<?php endif; ?>
														<?php if ( ! empty( $participant->role ) ) : ?>
															<span>Role: <?php echo esc_html( $participant->role ); ?></span>
														<?php endif; ?>
													</div>
												</div>
												<div style="display:flex; flex-direction:column; align-items:flex-end; gap:4px;">
													<div style="display:flex; gap:6px;">
														<form method="post" onsubmit="return confirm('Send email to this participant?');">
															<?php wp_nonce_field( 'icon_psy_projects' ); ?>
															<input type="hidden" name="icon_psy_projects_action" value="email_participant" />
															<input type="hidden" name="participant_id" value="<?php echo esc_attr( $participant_id ); ?>" />
															<button type="submit" class="button button-secondary">Send email</button>
														</form>
														<form method="post" onsubmit="return confirm('Delete this participant and their raters?');">
															<?php wp_nonce_field( 'icon_psy_projects' ); ?>
															<input type="hidden" name="icon_psy_projects_action" value="delete_participant" />
															<input type="hidden" name="participant_id" value="<?php echo esc_attr( $participant_id ); ?>" />
															<button type="submit" class="button button-secondary button-link-delete">Delete</button>
														</form>
													</div>
													<div style="font-size:12px; color:#0f766e; display:flex; align-items:center; gap:4px;">
														<span>View raters</span>
														<span class="icon-psy-chevron">▾</span>
													</div>
												</div>
											</div>

											<div class="icon-psy-rater-list" data-participant-body="<?php echo esc_attr( $participant_id ); ?>">
												<!-- Add rater inline form -->
												<form method="post" class="icon-psy-d-inline-form">
													<?php wp_nonce_field( 'icon_psy_projects' ); ?>
													<input type="hidden" name="icon_psy_projects_action" value="add_rater" />
													<input type="hidden" name="participant_id" value="<?php echo esc_attr( $participant_id ); ?>" />
													<input type="text" name="rater_name" placeholder="Rater name" required />
													<input type="email" name="rater_email" placeholder="Email" />
													<input type="text" name="rater_relationship" placeholder="Relationship" />
													<button type="submit" class="button">Add rater</button>
												</form>

												<?php if ( empty( $participant_raters ) ) : ?>
													<p style="font-size:12px; color:#6b7280; margin-top:6px;">
														No raters added for this participant yet.
													</p>
												<?php else : ?>
													<?php foreach ( $participant_raters as $rater ) : ?>
														<div class="icon-psy-rater-row">
															<div>
																<div style="font-weight:500;">
																	<?php echo esc_html( $rater->name ); ?>
																</div>
																<div style="font-size:11px; color:#6b7280;">
																	<?php if ( ! empty( $rater->email ) ) : ?>
																		<?php echo esc_html( $rater->email ); ?>
																	<?php endif; ?>
																	<?php if ( ! empty( $rater->relationship ) ) : ?>
																		&nbsp;&middot;&nbsp;<?php echo esc_html( $rater->relationship ); ?>
																	<?php endif; ?>
																</div>
															</div>
															<div style="display:flex; flex-direction:column; align-items:flex-end; gap:4px;">
																<span style="font-size:11px; color:#374151;">
																	Status: <?php echo esc_html( ucfirst( $rater->status ? $rater->status : 'invited' ) ); ?>
																</span>
																<div style="display:flex; gap:6px;">
																	<form method="post" onsubmit="return confirm('Send invite email to this rater?');">
																		<?php wp_nonce_field( 'icon_psy_projects' ); ?>
																		<input type="hidden" name="icon_psy_projects_action" value="email_rater" />
																		<input type="hidden" name="rater_id" value="<?php echo esc_attr( $rater->id ); ?>" />
																		<button type="submit" class="button button-secondary">Send invite</button>
																	</form>
																	<form method="post" onsubmit="return confirm('Delete this rater?');">
																		<?php wp_nonce_field( 'icon_psy_projects' ); ?>
																		<input type="hidden" name="icon_psy_projects_action" value="delete_rater" />
																		<input type="hidden" name="rater_id" value="<?php echo esc_attr( $rater->id ); ?>" />
																		<button type="submit" class="button button-secondary button-link-delete">Delete</button>
																	</form>
																</div>
															</div>
														</div>
													<?php endforeach; ?>
												<?php endif; ?>
											</div>
										</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<script>
			document.addEventListener('DOMContentLoaded', function() {
				// Toggle project body
				document.querySelectorAll('.icon-psy-admin-projects [data-toggle-project]').forEach(function(header) {
					header.addEventListener('click', function(e) {
						if (e.target.closest('form,button')) return;
						var key  = header.getAttribute('data-toggle-project');
						var body = document.querySelector('.icon-psy-admin-projects [data-project-body="' + key + '"]');
						if (!body) return;
						var isHidden = (body.style.display === '' || body.style.display === 'none');
						body.style.display = isHidden ? 'block' : 'none';
						var chevron = header.querySelector('.icon-psy-chevron');
						if (chevron) chevron.textContent = isHidden ? '▴' : '▾';
					});
				});

				// Toggle participant rater list
				document.querySelectorAll('.icon-psy-admin-projects [data-toggle-participant]').forEach(function(header) {
					header.addEventListener('click', function(e) {
						if (e.target.closest('form,button')) return;
						var key  = header.getAttribute('data-toggle-participant');
						var body = document.querySelector('.icon-psy-admin-projects [data-participant-body="' + key + '"]');
						if (!body) return;
						var isHidden = (body.style.display === '' || body.style.display === 'none');
						body.style.display = isHidden ? 'block' : 'none';
						var chevron = header.querySelector('.icon-psy-chevron');
						if (chevron) chevron.textContent = isHidden ? '▴' : '▾';
					});
				});
			});
			</script>
		</div>
		<?php
	}

	/**
	 * CLIENTS PAGE – list icon_client users + EDIT + DELETE (no portal links).
	 */
	public static function render_clients_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$message       = '';
		$message_class = 'updated';

		// Handle actions
		if ( isset( $_POST['icon_psy_clients_action'] ) ) {
			check_admin_referer( 'icon_psy_clients' );
			$action = sanitize_key( wp_unslash( $_POST['icon_psy_clients_action'] ) );

			if ( 'update_client' === $action ) {
				$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
				$name    = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
				$email   = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
				$org     = isset( $_POST['icon_client_org'] ) ? sanitize_text_field( wp_unslash( $_POST['icon_client_org'] ) ) : '';

				if ( $user_id <= 0 ) {
					$message = 'Invalid client.';
					$message_class = 'error';
				} else {
					$u = get_user_by( 'id', $user_id );
					if ( ! $u || ! in_array( 'icon_client', (array) $u->roles, true ) ) {
						$message = 'Client user not found.';
						$message_class = 'error';
					} else {
						$userdata = array( 'ID' => $user_id );

						if ( $name !== '' ) {
							$userdata['display_name'] = $name;
							$userdata['user_nicename'] = sanitize_title( $name );
						}
						if ( $email !== '' ) {
							$userdata['user_email'] = $email;
						}

						$updated = wp_update_user( $userdata );

						if ( is_wp_error( $updated ) ) {
							$message = 'Could not update client: ' . $updated->get_error_message();
							$message_class = 'error';
						} else {
							update_user_meta( $user_id, 'icon_client_org', $org );
							$message = 'Client updated.';
							$message_class = 'updated';
						}
					}
				}

			} elseif ( 'delete_client' === $action ) {

				$client_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
				$admin_id  = (int) get_current_user_id();

				if ( $client_id <= 0 ) {
					$message = 'Invalid client.';
					$message_class = 'error';
				} elseif ( $client_id === $admin_id ) {
					$message = 'You cannot delete your own admin account.';
					$message_class = 'error';
				} else {
					$u = get_user_by( 'id', $client_id );
					if ( ! $u ) {
						$message = 'Client user not found.';
						$message_class = 'error';
					} elseif ( user_can( $u, 'administrator' ) ) {
						$message = 'Safety block: you cannot delete an administrator here.';
						$message_class = 'error';
					} elseif ( ! in_array( 'icon_client', (array) $u->roles, true ) ) {
						$message = 'This user is not an icon_client.';
						$message_class = 'error';
					} else {

						// If any admin is impersonating this client, clear their meta (best-effort)
						// (We can’t reliably clear for all admins without extra queries, but clear for current admin for sure)
						$imp = (int) get_user_meta( $admin_id, 'icon_psy_impersonate_client_id', true );
						if ( $imp === $client_id ) {
							delete_user_meta( $admin_id, 'icon_psy_impersonate_client_id' );
						}

						// Delete user and reassign posts/content to current admin
						if ( ! function_exists( 'wp_delete_user' ) ) {
							require_once ABSPATH . 'wp-admin/includes/user.php';
						}

						$deleted = wp_delete_user( $client_id, $admin_id );

						if ( ! $deleted ) {
							$message = 'Could not delete client (WordPress returned false).';
							$message_class = 'error';
						} else {
							$message = 'Client deleted.';
							$message_class = 'updated';
						}
					}
				}
			}
		}

		$clients = get_users( array(
			'role'    => 'icon_client',
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 2000,
		) );

		?>
		<div class="wrap">
			<h1>Clients</h1>
			<p>Manage client users (edit name, email and organisation).</p>

			<?php self::render_inline_edit_assets(); ?>

			<?php if ( $message ) : ?>
				<div class="notice <?php echo esc_attr( $message_class ); ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>Client</th>
						<th>Email</th>
						<th>Organisation</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $clients ) ) : ?>
					<tr><td colspan="4"><em>No client users found.</em></td></tr>
				<?php else : ?>
					<?php foreach ( $clients as $c ) : ?>
						<?php
						$org = get_user_meta( $c->ID, 'icon_client_org', true );
						$org = is_string( $org ) ? $org : '';
						?>
						<tr>
							<td><strong><?php echo esc_html( $c->display_name ); ?></strong> <span class="icon-psy-muted">(#<?php echo (int) $c->ID; ?>)</span></td>
							<td><?php echo esc_html( $c->user_email ); ?></td>
							<td><?php echo esc_html( $org ); ?></td>
							<td style="white-space:nowrap; display:flex; gap:8px; align-items:center;">
								<a href="#" class="button" data-icon-psy-edit="<?php echo (int) $c->ID; ?>">Edit</a>

								<form method="post" onsubmit="return confirm('Delete this client user? This cannot be undone.');" style="margin:0;">
									<?php wp_nonce_field( 'icon_psy_clients' ); ?>
									<input type="hidden" name="icon_psy_clients_action" value="delete_client">
									<input type="hidden" name="user_id" value="<?php echo (int) $c->ID; ?>">
									<button type="submit" class="button button-secondary button-link-delete">Delete</button>
								</form>
							</td>
						</tr>
						<tr class="icon-psy-edit-row" data-icon-psy-edit-row="<?php echo (int) $c->ID; ?>">
							<td colspan="4">
								<form method="post">
									<?php wp_nonce_field( 'icon_psy_clients' ); ?>
									<input type="hidden" name="icon_psy_clients_action" value="update_client">
									<input type="hidden" name="user_id" value="<?php echo (int) $c->ID; ?>">

									<div class="icon-psy-edit-grid">
										<div>
											<label>Client name</label>
											<input type="text" name="display_name" value="<?php echo esc_attr( $c->display_name ); ?>" class="regular-text">
										</div>
										<div>
											<label>Email</label>
											<input type="email" name="user_email" value="<?php echo esc_attr( $c->user_email ); ?>" class="regular-text">
										</div>
										<div>
											<label>Organisation (meta)</label>
											<input type="text" name="icon_client_org" value="<?php echo esc_attr( $org ); ?>" class="regular-text">
										</div>
									</div>

									<div class="icon-psy-edit-actions">
										<button type="submit" class="button button-primary">Save</button>
										<a href="#" class="button" data-icon-psy-cancel="<?php echo (int) $c->ID; ?>">Cancel</a>
									</div>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * PARTICIPANTS PAGE – list + EDIT (same inline edit pattern).
	 */
	public static function render_participants_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		$participants_table = $wpdb->prefix . 'icon_psy_participants';
		$projects_table     = $wpdb->prefix . 'icon_psy_projects';

		$message       = '';
		$message_class = 'updated';

		if ( isset( $_POST['icon_psy_participants_action'] ) ) {
			check_admin_referer( 'icon_psy_participants' );
			$action = sanitize_key( wp_unslash( $_POST['icon_psy_participants_action'] ) );

			if ( 'update_participant' === $action ) {
				if ( ! self::table_exists( $participants_table ) ) {
					$message = 'Participants table not found.';
					$message_class = 'error';
				} else {
					$participant_id = isset( $_POST['participant_id'] ) ? (int) $_POST['participant_id'] : 0;
					$name           = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
					$email          = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
					$role           = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';
					$project_id     = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;

					if ( $participant_id <= 0 || $name === '' ) {
						$message = 'Participant name is required.';
						$message_class = 'error';
					} else {
						$updated = $wpdb->update(
							$participants_table,
							array(
								'name'       => $name,
								'email'      => $email,
								'role'       => $role,
								'project_id' => $project_id,
							),
							array( 'id' => $participant_id ),
							array( '%s', '%s', '%s', '%d' ),
							array( '%d' )
						);

						if ( false === $updated ) {
							$message = 'Could not update participant.';
							$message_class = 'error';
						} else {
							$message = 'Participant updated.';
							$message_class = 'updated';
						}
					}
				}
			}
		}

		if ( ! self::table_exists( $participants_table ) ) {
			echo '<div class="wrap"><h1>Participants</h1><p><em>Participants table not found.</em></p></div>';
			return;
		}

		$projects = array();
		if ( self::table_exists( $projects_table ) ) {
			$projects = $wpdb->get_results( "SELECT id, name FROM {$projects_table} ORDER BY name ASC" );
		}

		$rows = $wpdb->get_results(
			"SELECT p.*, pr.name AS project_name
			 FROM {$participants_table} p
			 LEFT JOIN {$projects_table} pr ON p.project_id = pr.id
			 ORDER BY p.created_at DESC
			 LIMIT 200"
		);
		?>
		<div class="wrap">
			<h1>Participants</h1>
			<p>Quick overview of all participants (edit inline). For full management use the Projects screen.</p>

			<?php self::render_inline_edit_assets(); ?>

			<?php if ( $message ) : ?>
				<div class="notice <?php echo esc_attr( $message_class ); ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>ID</th>
						<th>Name</th>
						<th>Email</th>
						<th>Role</th>
						<th>Project</th>
						<th>Created</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="7"><em>No participants found.</em></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo (int) $row->id; ?></td>
								<td><strong><?php echo esc_html( $row->name ); ?></strong></td>
								<td><?php echo esc_html( $row->email ); ?></td>
								<td><?php echo esc_html( $row->role ); ?></td>
								<td><?php echo esc_html( $row->project_name ); ?></td>
								<td><?php echo esc_html( $row->created_at ); ?></td>
								<td style="white-space:nowrap;">
									<a href="#" class="button" data-icon-psy-edit="<?php echo (int) $row->id; ?>">Edit</a>
								</td>
							</tr>

							<tr class="icon-psy-edit-row" data-icon-psy-edit-row="<?php echo (int) $row->id; ?>">
								<td colspan="7">
									<form method="post">
										<?php wp_nonce_field( 'icon_psy_participants' ); ?>
										<input type="hidden" name="icon_psy_participants_action" value="update_participant">
										<input type="hidden" name="participant_id" value="<?php echo (int) $row->id; ?>">

										<div class="icon-psy-edit-grid">
											<div>
												<label>Name *</label>
												<input type="text" name="name" value="<?php echo esc_attr( $row->name ); ?>" class="regular-text" required>
											</div>
											<div>
												<label>Email</label>
												<input type="email" name="email" value="<?php echo esc_attr( $row->email ); ?>" class="regular-text">
											</div>
											<div>
												<label>Role</label>
												<input type="text" name="role" value="<?php echo esc_attr( $row->role ); ?>" class="regular-text">
											</div>
											<div>
												<label>Project</label>
												<select name="project_id">
													<option value="0">—</option>
													<?php if ( ! empty( $projects ) ) : ?>
														<?php foreach ( $projects as $p ) : ?>
															<option value="<?php echo (int) $p->id; ?>" <?php selected( (int) $row->project_id, (int) $p->id ); ?>>
																<?php echo esc_html( $p->name ); ?>
															</option>
														<?php endforeach; ?>
													<?php endif; ?>
												</select>
											</div>
										</div>

										<div class="icon-psy-edit-actions">
											<button type="submit" class="button button-primary">Save</button>
											<a href="#" class="button" data-icon-psy-cancel="<?php echo (int) $row->id; ?>">Cancel</a>
										</div>

										<?php if ( ! empty( $wpdb->last_error ) ) : ?>
											<div class="icon-psy-muted" style="margin-top:8px;">DB: <?php echo esc_html( $wpdb->last_error ); ?></div>
										<?php endif; ?>
									</form>
								</td>
							</tr>

						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * RATERS PAGE – list + EDIT (same inline edit pattern).
	 */
	public static function render_raters_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		self::maybe_add_token_column_to_raters_table();

		$raters_table       = $wpdb->prefix . 'icon_psy_raters';
		$participants_table = $wpdb->prefix . 'icon_psy_participants';

		$message       = '';
		$message_class = 'updated';

		if ( isset( $_POST['icon_psy_raters_action'] ) ) {
			check_admin_referer( 'icon_psy_raters' );
			$action = sanitize_key( wp_unslash( $_POST['icon_psy_raters_action'] ) );

			if ( 'update_rater' === $action ) {
				if ( ! self::table_exists( $raters_table ) ) {
					$message = 'Raters table not found.';
					$message_class = 'error';
				} else {
					$rater_id     = isset( $_POST['rater_id'] ) ? (int) $_POST['rater_id'] : 0;
					$name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
					$email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
					$relationship = isset( $_POST['relationship'] ) ? sanitize_text_field( wp_unslash( $_POST['relationship'] ) ) : '';
					$status       = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

					if ( $rater_id <= 0 || $name === '' ) {
						$message = 'Rater name is required.';
						$message_class = 'error';
					} else {
						$updated = $wpdb->update(
							$raters_table,
							array(
								'name'         => $name,
								'email'        => $email,
								'relationship' => $relationship,
								'status'       => $status,
							),
							array( 'id' => $rater_id ),
							array( '%s', '%s', '%s', '%s' ),
							array( '%d' )
						);

						if ( false === $updated ) {
							$message = 'Could not update rater.';
							$message_class = 'error';
						} else {
							$message = 'Rater updated.';
							$message_class = 'updated';
						}
					}
				}
			}
		}

		if ( ! self::table_exists( $raters_table ) ) {
			echo '<div class="wrap"><h1>Raters</h1><p><em>Raters table not found.</em></p></div>';
			return;
		}

		$rows = $wpdb->get_results(
			"SELECT r.*, p.name AS participant_name
			 FROM {$raters_table} r
			 LEFT JOIN {$participants_table} p ON r.participant_id = p.id
			 ORDER BY r.created_at DESC
			 LIMIT 200"
		);

		$base_survey_url = home_url( '/rater-portal/' );
		?>
		<div class="wrap">
			<h1>Raters</h1>
			<p>
				Overview of all raters. Use the token / survey link column to test individual rater links
				or to resend invites from your email system. Edit inline below.
			</p>

			<?php self::render_inline_edit_assets(); ?>

			<?php if ( $message ) : ?>
				<div class="notice <?php echo esc_attr( $message_class ); ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>ID</th>
						<th>Rater</th>
						<th>Email</th>
						<th>Relationship</th>
						<th>Participant</th>
						<th>Token / Survey link</th>
						<th>Status</th>
						<th>Created</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="9"><em>No raters found.</em></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$token       = isset( $row->token ) ? (string) $row->token : '';
							$token_short = $token ? substr( $token, 0, 8 ) . '…' : '';
							$survey_link = $token
								? add_query_arg( 'token', rawurlencode( $token ), $base_survey_url )
								: '';
							?>
							<tr>
								<td><?php echo (int) $row->id; ?></td>
								<td><strong><?php echo esc_html( $row->name ); ?></strong></td>
								<td><?php echo esc_html( $row->email ); ?></td>
								<td><?php echo esc_html( $row->relationship ); ?></td>
								<td><?php echo esc_html( $row->participant_name ); ?></td>
								<td>
									<?php if ( $token && $survey_link ) : ?>
										<div style="font-size:11px; color:#374151; margin-bottom:4px;">
											Token: <code><?php echo esc_html( $token_short ); ?></code>
										</div>
										<input
											type="text"
											readonly
											value="<?php echo esc_attr( $survey_link ); ?>"
											style="width:100%; max-width:360px; font-size:11px;"
											onclick="this.select();"
										/>
									<?php else : ?>
										<em style="font-size:11px; color:#9ca3af;">No token set</em>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $row->status ); ?></td>
								<td><?php echo esc_html( $row->created_at ); ?></td>
								<td style="white-space:nowrap;">
									<a href="#" class="button" data-icon-psy-edit="<?php echo (int) $row->id; ?>">Edit</a>
								</td>
							</tr>

							<tr class="icon-psy-edit-row" data-icon-psy-edit-row="<?php echo (int) $row->id; ?>">
								<td colspan="9">
									<form method="post">
										<?php wp_nonce_field( 'icon_psy_raters' ); ?>
										<input type="hidden" name="icon_psy_raters_action" value="update_rater">
										<input type="hidden" name="rater_id" value="<?php echo (int) $row->id; ?>">

										<div class="icon-psy-edit-grid">
											<div>
												<label>Name *</label>
												<input type="text" name="name" value="<?php echo esc_attr( $row->name ); ?>" class="regular-text" required>
											</div>
											<div>
												<label>Email</label>
												<input type="email" name="email" value="<?php echo esc_attr( $row->email ); ?>" class="regular-text">
											</div>
											<div>
												<label>Relationship</label>
												<input type="text" name="relationship" value="<?php echo esc_attr( $row->relationship ); ?>" class="regular-text">
											</div>
											<div>
												<label>Status</label>
												<input type="text" name="status" value="<?php echo esc_attr( $row->status ); ?>" class="regular-text" placeholder="pending / completed / invited">
												<div class="icon-psy-muted">Token not edited here (auto-managed).</div>
											</div>
										</div>

										<div class="icon-psy-edit-actions">
											<button type="submit" class="button button-primary">Save</button>
											<a href="#" class="button" data-icon-psy-cancel="<?php echo (int) $row->id; ?>">Cancel</a>
										</div>

										<?php if ( ! empty( $wpdb->last_error ) ) : ?>
											<div class="icon-psy-muted" style="margin-top:8px;">DB: <?php echo esc_html( $wpdb->last_error ); ?></div>
										<?php endif; ?>
									</form>
								</td>
							</tr>

						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * FRAMEWORKS PAGE – Icon style, create, delete, core selection.
	 */
	public static function render_frameworks_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		self::maybe_create_framework_tables();

		$frameworks_table   = $wpdb->prefix . 'icon_psy_frameworks';
		$competencies_table = $wpdb->prefix . 'icon_psy_framework_competencies';

		$message       = '';
		$message_class = 'updated';

		// Handle actions
		if ( isset( $_POST['icon_psy_frameworks_action'] ) ) {
			check_admin_referer( 'icon_psy_frameworks' );

			$action = sanitize_key( wp_unslash( $_POST['icon_psy_frameworks_action'] ) );

			// CREATE FRAMEWORK
			if ( 'create_framework' === $action ) {

				$name   = isset( $_POST['framework_name'] ) ? sanitize_text_field( wp_unslash( $_POST['framework_name'] ) ) : '';
				$slug   = isset( $_POST['framework_slug'] ) ? sanitize_title( wp_unslash( $_POST['framework_slug'] ) ) : '';
				$type   = isset( $_POST['framework_type'] ) ? sanitize_text_field( wp_unslash( $_POST['framework_type'] ) ) : '';
				$status = isset( $_POST['framework_status'] ) ? sanitize_text_field( wp_unslash( $_POST['framework_status'] ) ) : 'active';

				$seed = ! empty( $_POST['framework_seed'] );

				if ( '' === $name ) {
					$message       = 'Framework name is required.';
					$message_class = 'error';
				} else {

					if ( '' === $slug ) {
						$slug = sanitize_title( $name );
					}

					$existing = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$frameworks_table} WHERE slug = %s LIMIT 1",
							$slug
						)
					);
					if ( $existing ) {
						$slug = $slug . '-' . wp_generate_password( 4, false, false );
					}

					$inserted = $wpdb->insert(
						$frameworks_table,
						array(
							'slug'       => $slug,
							'name'       => $name,
							'type'       => $type,
							'is_default' => 0,
							'status'     => $status,
							'created_at' => current_time( 'mysql' ),
							'updated_at' => current_time( 'mysql' ),
						),
						array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
					);

					if ( false === $inserted ) {
						$message       = 'Could not create framework. Please try again.';
						$message_class = 'error';
					} else {
						$new_id = (int) $wpdb->insert_id;

						if ( $seed && $new_id > 0 ) {
							$starters = array(
								array( 'code' => 'ST', 'name' => 'Strategic Thinking', 'desc' => 'Thinks ahead, connects themes, and sets clear direction.' ),
								array( 'code' => 'LP', 'name' => 'Leading People',     'desc' => 'Builds trust, sets expectations, and develops others.' ),
								array( 'code' => 'CO', 'name' => 'Collaboration',      'desc' => 'Works across boundaries, shares information, and aligns stakeholders.' ),
								array( 'code' => 'CF', 'name' => 'Customer Focus',     'desc' => 'Understands needs, improves experience, and delivers value.' ),
								array( 'code' => 'EX', 'name' => 'Execution',          'desc' => 'Delivers outcomes, follows through, and manages priorities.' ),
							);

							$order = 10;
							foreach ( $starters as $s ) {
								$wpdb->insert(
									$competencies_table,
									array(
										'framework_id' => $new_id,
										'code'         => $s['code'],
										'name'         => $s['name'],
										'description'  => $s['desc'],
										'module'       => 'core',
										'sort_order'   => $order,
										'created_at'   => current_time( 'mysql' ),
										'updated_at'   => current_time( 'mysql' ),
									),
									array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
								);
								$order += 10;
							}
						}

						$message       = 'Framework created successfully.';
						$message_class = 'updated';
					}
				}

			// DELETE
			} elseif ( 'delete' === $action ) {

				$framework_id = isset( $_POST['framework_id'] ) ? (int) $_POST['framework_id'] : 0;

				if ( $framework_id > 0 ) {
					$wpdb->delete(
						$competencies_table,
						array( 'framework_id' => $framework_id ),
						array( '%d' )
					);
					$wpdb->delete(
						$frameworks_table,
						array( 'id' => $framework_id ),
						array( '%d' )
					);

					$message       = 'Framework and its competencies deleted.';
					$message_class = 'updated';
				}

			// SET DEFAULT (core)
			} elseif ( 'set_default' === $action ) {

				$framework_id = isset( $_POST['framework_id'] ) ? (int) $_POST['framework_id'] : 0;

				if ( $framework_id > 0 ) {
					$wpdb->query( "UPDATE {$frameworks_table} SET is_default = 0" );
					$wpdb->update(
						$frameworks_table,
						array( 'is_default' => 1 ),
						array( 'id' => $framework_id ),
						array( '%d' ),
						array( '%d' )
					);

					$message       = 'Framework set as core (default) framework.';
					$message_class = 'updated';
				}
			}
		}

		// Load frameworks
		$frameworks = $wpdb->get_results(
			"SELECT * FROM {$frameworks_table} ORDER BY is_default DESC, name ASC"
		);
		?>
		<div class="wrap">
			<h1>Frameworks</h1>
			<p>
				View and manage your Icon frameworks.
				The <strong>core</strong> framework (default) will be suggested for new projects.
			</p>

			<?php if ( $message ) : ?>
				<div class="notice <?php echo esc_attr( $message_class ); ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<style>
				.icon-psy-admin-frameworks {
					max-width: 1100px;
					margin: 16px 0 32px 0;
				}
				.icon-psy-admin-frameworks .icon-psy-framework-card {
					background:#ffffff;
					border-radius:10px;
					padding:14px 16px;
					box-shadow:0 1px 4px rgba(0,0,0,0.06);
					border:1px solid #e3f0ea;
					margin-bottom:16px;
				}
				.icon-psy-admin-frameworks .icon-psy-framework-name {
					font-weight:600;
					font-size:15px;
					color:#111827;
					margin:0 0 4px 0;
				}
				.icon-psy-admin-frameworks .icon-psy-framework-subtitle {
					font-size:12px;
					color:#4b5563;
					margin:0 0 6px 0;
				}
				.icon-psy-admin-frameworks .icon-psy-chip-row {
					display:flex;
					flex-wrap:wrap;
					gap:6px;
					margin-top:2px;
					font-size:11px;
				}
				.icon-psy-admin-frameworks .icon-psy-chip {
					display:inline-flex;
					align-items:center;
					gap:4px;
					padding:2px 8px;
					border-radius:999px;
					background:#ecfdf5;
					color:#064e3b;
				}
				.icon-psy-admin-frameworks .icon-psy-chip-muted {
					background:#f3f4f6;
					color:#374151;
					border-radius:999px;
					padding:2px 8px;
					display:inline-flex;
					align-items:center;
				}
				.icon-psy-admin-frameworks .icon-psy-competency-list {
					margin-top:10px;
					border-top:1px solid #e5e7eb;
					padding-top:8px;
				}
				.icon-psy-admin-frameworks .icon-psy-competency-row {
					padding:6px 0;
					border-bottom:1px solid #f3f4f6;
					font-size:12px;
					color:#374151;
				}
				.icon-psy-admin-frameworks .icon-psy-competency-row:last-child {
					border-bottom:none;
				}
				.icon-psy-admin-frameworks .icon-psy-competency-name {
					font-weight:500;
					margin:0 0 2px 0;
				}
				.icon-psy-admin-frameworks .icon-psy-competency-meta {
					font-size:11px;
					color:#6b7280;
					margin:0;
				}
				.icon-psy-admin-frameworks .icon-psy-fw-actions {
					margin-top:8px;
					display:flex;
					gap:8px;
					flex-wrap:wrap;
				}
				.icon-psy-admin-frameworks .icon-psy-create-grid {
					display:grid;
					grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
					gap:10px;
					margin-top:10px;
				}
			</style>

			<div class="icon-psy-admin-frameworks">

				<!-- CREATE FRAMEWORK -->
				<div class="icon-psy-framework-card">
					<h2 class="icon-psy-framework-name" style="margin-bottom:6px;">Create framework</h2>
					<p class="icon-psy-framework-subtitle">Add a new competency framework (for 360, teams, leadership).</p>

					<form method="post">
						<?php wp_nonce_field( 'icon_psy_frameworks' ); ?>
						<input type="hidden" name="icon_psy_frameworks_action" value="create_framework" />

						<div class="icon-psy-create-grid">
							<div>
								<label><strong>Name *</strong></label><br>
								<input type="text" name="framework_name" class="regular-text" required style="width:100%;" placeholder="e.g. Icon Leadership Framework">
							</div>

							<div>
								<label><strong>Slug</strong></label><br>
								<input type="text" name="framework_slug" class="regular-text" style="width:100%;" placeholder="e.g. icon-leadership">
								<div style="font-size:11px;color:#6b7280;margin-top:4px;">Leave blank to auto-generate from name.</div>
							</div>

							<div>
								<label><strong>Type</strong></label><br>
								<input type="text" name="framework_type" class="regular-text" style="width:100%;" placeholder="e.g. leadership / team / 360">
							</div>

							<div>
								<label><strong>Status</strong></label><br>
								<select name="framework_status" style="width:100%; max-width:260px;">
									<option value="active">Active</option>
									<option value="published">Published</option>
									<option value="draft">Draft</option>
								</select>
							</div>
						</div>

						<div style="margin-top:10px;">
							<label style="font-size:12px;color:#425b56;">
								<input type="checkbox" name="framework_seed" value="1" checked>
								Seed with 5 starter competencies (Strategic Thinking, Leading People, Collaboration, Customer Focus, Execution)
							</label>
						</div>

						<p class="submit" style="margin-top:12px;">
							<button type="submit" class="button button-primary">Create framework</button>
						</p>
					</form>
				</div>

				<!-- LIST FRAMEWORKS -->
				<?php if ( empty( $frameworks ) ) : ?>
					<p><em>No frameworks found yet.</em></p>
				<?php else : ?>
					<?php foreach ( $frameworks as $framework ) : ?>
						<?php
						$framework_id   = (int) $framework->id;
						$framework_name = $framework->name ? $framework->name : 'Untitled framework';

						$competencies = $wpdb->get_results(
							$wpdb->prepare(
								"SELECT * FROM {$competencies_table}
								 WHERE framework_id = %d
								 ORDER BY sort_order ASC, name ASC",
								$framework_id
							)
						);
						?>
						<div class="icon-psy-framework-card">
							<div>
								<h2 class="icon-psy-framework-name">
									<?php echo esc_html( $framework_name ); ?>
								</h2>
								<p class="icon-psy-framework-subtitle">
									ID <?php echo $framework_id; ?>
									<?php if ( ! empty( $framework->type ) ) : ?>
										&middot; Type: <?php echo esc_html( $framework->type ); ?>
									<?php endif; ?>
								</p>
								<div class="icon-psy-chip-row">
									<?php if ( ! empty( $framework->slug ) ) : ?>
										<span class="icon-psy-chip-muted">
											Slug: <?php echo esc_html( $framework->slug ); ?>
										</span>
									<?php endif; ?>
									<?php if ( (int) $framework->is_default === 1 ) : ?>
										<span class="icon-psy-chip">Core framework</span>
									<?php endif; ?>
									<span class="icon-psy-chip-muted">
										Competencies: <?php echo esc_html( count( $competencies ) ); ?>
									</span>
									<?php if ( ! empty( $framework->status ) ) : ?>
										<span class="icon-psy-chip-muted">
											Status: <?php echo esc_html( $framework->status ); ?>
										</span>
									<?php endif; ?>
								</div>
							</div>

							<div class="icon-psy-fw-actions">
								<?php if ( (int) $framework->is_default !== 1 ) : ?>
									<form method="post" onsubmit="return confirm('Set this as the core (default) framework?');">
										<?php wp_nonce_field( 'icon_psy_frameworks' ); ?>
										<input type="hidden" name="icon_psy_frameworks_action" value="set_default" />
										<input type="hidden" name="framework_id" value="<?php echo $framework_id; ?>" />
										<button type="submit" class="button" style="background:#0f766e; border-color:#0f766e; color:#fff;">
											Make core framework
										</button>
									</form>
								<?php endif; ?>

								<form method="post" onsubmit="return confirm('Delete this framework and all its competencies? This cannot be undone.');">
									<?php wp_nonce_field( 'icon_psy_frameworks' ); ?>
									<input type="hidden" name="icon_psy_frameworks_action" value="delete" />
									<input type="hidden" name="framework_id" value="<?php echo $framework_id; ?>" />
									<button type="submit" class="button button-secondary button-link-delete">
										Delete framework
									</button>
								</form>
							</div>

							<div class="icon-psy-competency-list">
								<?php if ( empty( $competencies ) ) : ?>
									<p style="font-size:12px; color:#6b7280; margin:4px 0 0 0;">No competencies yet.</p>
								<?php else : ?>
									<?php foreach ( $competencies as $comp ) : ?>
										<div class="icon-psy-competency-row">
											<p class="icon-psy-competency-name">
												<?php echo esc_html( $comp->name ); ?>
												<?php if ( ! empty( $comp->code ) ) : ?>
													<span style="font-weight:normal; color:#6b7280;">
														(<?php echo esc_html( $comp->code ); ?>)
													</span>
												<?php endif; ?>
											</p>
											<p class="icon-psy-competency-meta">
												Module: <?php echo esc_html( $comp->module ? $comp->module : 'core' ); ?>
												<?php if ( ! empty( $comp->description ) ) : ?>
													&middot; <?php echo esc_html( wp_trim_words( $comp->description, 18, '…' ) ); ?>
												<?php endif; ?>
											</p>
										</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Fetch frameworks for dropdowns (active or published).
	 */
	protected static function get_published_frameworks() {
		global $wpdb;

		$frameworks_table = $wpdb->prefix . 'icon_psy_frameworks';
		if ( ! self::table_exists( $frameworks_table ) ) {
			return array();
		}

		$rows = $wpdb->get_results(
			"SELECT id, name, status
			 FROM {$frameworks_table}
			 WHERE status IN ('active', 'published')
			 ORDER BY name ASC"
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Render a framework dropdown for project forms.
	 */
	protected static function render_framework_select( $field_name = 'framework_id', $selected_id = 0 ) {
		$frameworks = self::get_published_frameworks();
		?>
		<select name="<?php echo esc_attr( $field_name ); ?>" required>
			<option value=""><?php esc_html_e( 'Select a framework…', 'icon-psy' ); ?></option>
			<?php foreach ( $frameworks as $fw ) : ?>
				<option value="<?php echo (int) $fw->id; ?>" <?php selected( $selected_id, (int) $fw->id ); ?>>
					<?php echo esc_html( $fw->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Ensure the raters table has a `token` column.
	 */
	protected static function maybe_add_token_column_to_raters_table() {
		global $wpdb;
		$raters_table = $wpdb->prefix . 'icon_psy_raters';

		if ( ! self::table_exists( $raters_table ) ) {
			return;
		}

		$has_column = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$raters_table} LIKE %s",
				'token'
			)
		);

		if ( ! $has_column ) {
			$wpdb->query(
				"ALTER TABLE {$raters_table}
				 ADD COLUMN token VARCHAR(64) NULL AFTER relationship"
			);
		}
	}

	/**
	 * Ensure the projects table has a `framework_id` column.
	 */
	protected static function maybe_add_framework_column_to_projects_table() {
		global $wpdb;
		$projects_table = $wpdb->prefix . 'icon_psy_projects';

		if ( ! self::table_exists( $projects_table ) ) {
			return;
		}

		$has_column = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$projects_table} LIKE %s",
				'framework_id'
			)
		);

		if ( ! $has_column ) {
			$wpdb->query(
				"ALTER TABLE {$projects_table}
				 ADD COLUMN framework_id BIGINT(20) UNSIGNED NULL AFTER status"
			);
		}
	}
}

// init
Icon_PSY_Admin_Menu::init();
