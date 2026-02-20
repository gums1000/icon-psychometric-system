<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Competency Designer
 *
 * - Uses your OpenAI key (stored in option: icon_psy_openai_api_key).
 * - Generates draft competencies from a plain-English brief OR technical traits from course objectives.
 * - NEW: Allows you to paste a provided list (no AI) and store it as a draft, then copy into a framework.
 * - Stores drafts in DB and ALWAYS shows the most recent draft in a clear card.
 */
class Icon_PSY_AI_Competency_Designer {

	/**
	 * Hook anything else you want here (not strictly needed for now).
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_draft_tables' ) );
	}

	/**
	 * Create tables for AI drafts + their competencies/traits.
	 */
	public static function maybe_create_draft_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate   = $wpdb->get_charset_collate();
		$drafts_table      = $wpdb->prefix . 'icon_psy_ai_drafts';
		$draft_comps_table = $wpdb->prefix . 'icon_psy_ai_draft_competencies';

		// Main drafts table
		$sql1 = "CREATE TABLE {$drafts_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(190) NOT NULL,
			brief TEXT NULL,
			raw_response LONGTEXT NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'draft',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		// Draft competencies / traits table (reused)
		$sql2 = "CREATE TABLE {$draft_comps_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			draft_id BIGINT(20) UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			module VARCHAR(50) NOT NULL DEFAULT 'core',
			sort_order INT(11) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY draft_id (draft_id),
			KEY sort_order (sort_order)
		) {$charset_collate};";

		dbDelta( $sql1 );
		dbDelta( $sql2 );
	}

	/**
	 * Main AI Designer page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		self::maybe_create_draft_tables();

		$drafts_table      = $wpdb->prefix . 'icon_psy_ai_drafts';
		$draft_comps_table = $wpdb->prefix . 'icon_psy_ai_draft_competencies';

		$message       = '';
		$message_class = 'updated';

		// Handle actions: generate, delete_draft, save_to_framework
		if ( isset( $_POST['icon_psy_ai_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['icon_psy_ai_action'] ) );

			if ( 'generate' === $action ) {
				check_admin_referer( 'icon_psy_ai_designer' );

				$title = isset( $_POST['ai_title'] )
					? sanitize_text_field( wp_unslash( $_POST['ai_title'] ) )
					: '';

				// Generator mode: competencies (default) or traits_from_objectives or provided_list (NEW)
				$generator_mode = isset( $_POST['generator_mode'] )
					? sanitize_key( wp_unslash( $_POST['generator_mode'] ) )
					: 'competencies';

				if ( ! in_array( $generator_mode, array( 'competencies', 'traits_from_objectives', 'provided_list' ), true ) ) {
					$generator_mode = 'competencies';
				}

				// Existing: brief (framework)
				$brief = isset( $_POST['ai_brief'] )
					? wp_kses_post( wp_unslash( $_POST['ai_brief'] ) )
					: '';

				// New: objectives input (course)
				$objectives = isset( $_POST['ai_objectives'] )
					? wp_kses_post( wp_unslash( $_POST['ai_objectives'] ) )
					: '';

				// NEW: provided list input (no AI)
				$provided = isset( $_POST['ai_provided'] )
					? wp_kses_post( wp_unslash( $_POST['ai_provided'] ) )
					: '';

				$audience = isset( $_POST['ai_audience'] )
					? sanitize_text_field( wp_unslash( $_POST['ai_audience'] ) )
					: '';

				$context = isset( $_POST['ai_context'] )
					? sanitize_text_field( wp_unslash( $_POST['ai_context'] ) )
					: '';

				// NEW: number of competencies/traits (clamped 3–20, default 8)
				$num_competencies = isset( $_POST['num_competencies'] )
					? (int) $_POST['num_competencies']
					: 8;

				if ( $num_competencies < 3 ) {
					$num_competencies = 3;
				} elseif ( $num_competencies > 20 ) {
					$num_competencies = 20;
				}

				if ( '' === $title ) {
					$message       = 'Please provide a working title.';
					$message_class = 'error';
				} else {

					// Build the stored "brief" for the draft (so your latest draft card is meaningful)
					$stored_brief = $brief;

					// Decide generation route
					if ( 'traits_from_objectives' === $generator_mode ) {

						if ( '' === trim( wp_strip_all_tags( $objectives ) ) ) {
							$message       = 'Please provide course objectives to generate technical traits.';
							$message_class = 'error';
						} else {
							$stored_brief = "MODE: Technical traits from course objectives\n"
								. ( $audience ? "Audience: {$audience}\n" : '' )
								. ( $context ? "Context: {$context}\n" : '' )
								. "Objectives:\n{$objectives}";

							$result = self::generate_technical_traits_from_objectives(
								$title,
								$objectives,
								$audience,
								$context,
								$num_competencies
							);
						}

					} elseif ( 'provided_list' === $generator_mode ) {

						if ( '' === trim( wp_strip_all_tags( $provided ) ) ) {
							$message       = 'Please paste your competencies list.';
							$message_class = 'error';
						} else {
							$stored_brief = "MODE: Provided list (no AI)\n"
								. "Format: One per line as Name | Description\n"
								. "Requested count: {$num_competencies}\n";

							$result = self::parse_provided_list_to_items( $provided, $num_competencies );
						}

					} else {
						// Default mode: competencies from brief
						$result = self::generate_competencies_from_brief( $title, $brief, $num_competencies );
					}

					if ( ! $message && is_wp_error( $result ) ) {
						$message       = 'AI generation failed: ' . $result->get_error_message();
						$message_class = 'error';
					} elseif ( ! $message ) {

						// $result should be array( 'raw' => '...', 'competencies' => [ [name, description, module], ... ] )
						$raw          = isset( $result['raw'] ) ? $result['raw'] : '';
						$items        = isset( $result['competencies'] ) && is_array( $result['competencies'] )
							? $result['competencies']
							: array();

						// Insert draft
						$wpdb->insert(
							$drafts_table,
							array(
								'title'        => $title,
								'brief'        => $stored_brief,
								'raw_response' => $raw,
								'status'       => 'draft',
								'created_at'   => current_time( 'mysql' ),
							),
							array( '%s', '%s', '%s', '%s', '%s' )
						);

						$draft_id = (int) $wpdb->insert_id;

						if ( $draft_id && ! empty( $items ) ) {
							$sort = 1;
							foreach ( $items as $item ) {
								$c_name   = isset( $item['name'] ) ? $item['name'] : '';
								$c_desc   = isset( $item['description'] ) ? $item['description'] : '';
								$c_module = isset( $item['module'] ) ? $item['module'] : 'core';

								if ( '' === trim( $c_name ) ) {
									continue;
								}

								$wpdb->insert(
									$draft_comps_table,
									array(
										'draft_id'    => $draft_id,
										'name'        => $c_name,
										'description' => $c_desc,
										'module'      => $c_module,
										'sort_order'  => $sort,
									),
									array( '%d', '%s', '%s', '%s', '%d' )
								);
								$sort++;
							}
						}

						if ( 'traits_from_objectives' === $generator_mode ) {
							$message = 'AI technical traits draft created. See the “Latest AI Draft” section below.';
						} elseif ( 'provided_list' === $generator_mode ) {
							$message = 'Draft created from your provided list (no AI). See the “Latest AI Draft” section below.';
						} else {
							$message = 'AI competency draft created. See the “Latest AI Draft” section below.';
						}

						$message_class = 'updated';
					}
				}

			} elseif ( 'delete_draft' === $action ) {
				check_admin_referer( 'icon_psy_ai_designer' );
				$draft_id = isset( $_POST['draft_id'] ) ? (int) $_POST['draft_id'] : 0;

				if ( $draft_id > 0 ) {
					$wpdb->delete(
						$draft_comps_table,
						array( 'draft_id' => $draft_id ),
						array( '%d' )
					);
					$wpdb->delete(
						$drafts_table,
						array( 'id' => $draft_id ),
						array( '%d' )
					);
					$message       = 'Draft deleted.';
					$message_class = 'updated';
				}

			} elseif ( 'save_to_framework' === $action ) {
				// This expects an existing framework_id and moves competencies over.
				check_admin_referer( 'icon_psy_ai_designer' );
				$draft_id     = isset( $_POST['draft_id'] ) ? (int) $_POST['draft_id'] : 0;
				$framework_id = isset( $_POST['framework_id'] ) ? (int) $_POST['framework_id'] : 0;

				if ( $draft_id > 0 && $framework_id > 0 ) {
					$frameworks_table   = $wpdb->prefix . 'icon_psy_frameworks';
					$competencies_table = $wpdb->prefix . 'icon_psy_framework_competencies';

					// Ensure framework exists
					$fw_exists = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$frameworks_table} WHERE id = %d",
							$framework_id
						)
					);

					if ( $fw_exists ) {
						$draft_comps = $wpdb->get_results(
							$wpdb->prepare(
								"SELECT * FROM {$draft_comps_table}
								 WHERE draft_id = %d
								 ORDER BY sort_order ASC",
								$draft_id
							)
						);

						$sort = 1;
						if ( ! empty( $draft_comps ) ) {
							foreach ( $draft_comps as $comp ) {
								$wpdb->insert(
									$competencies_table,
									array(
										'framework_id' => $framework_id,
										'code'         => '',
										'name'         => $comp->name,
										'description'  => $comp->description,
										'module'       => $comp->module,
										'sort_order'   => $sort,
										'created_at'   => current_time( 'mysql' ),
									),
									array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
								);
								$sort++;
							}
						}

						// Mark draft as used
						$wpdb->update(
							$drafts_table,
							array( 'status' => 'saved' ),
							array( 'id' => $draft_id ),
							array( '%s' ),
							array( '%d' )
						);

						$message       = 'Draft items copied into the selected framework.';
						$message_class = 'updated';
					} else {
						$message       = 'Selected framework does not exist.';
						$message_class = 'error';
					}
				}
			}
		}

		// Fetch latest draft (ALWAYS something to show if it exists)
		$latest_draft = $wpdb->get_row(
			"SELECT * FROM {$drafts_table}
			 ORDER BY created_at DESC
			 LIMIT 1"
		);

		$latest_comps = array();
		if ( $latest_draft ) {
			$latest_comps = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$draft_comps_table}
					 WHERE draft_id = %d
					 ORDER BY sort_order ASC",
					(int) $latest_draft->id
				)
			);
		}

		// Frameworks for "Save to framework" dropdown
		$frameworks = $wpdb->get_results(
			"SELECT id, name FROM {$wpdb->prefix}icon_psy_frameworks
			 ORDER BY name ASC"
		);

		?>
		<div class="wrap">
			<h1>AI Competency Designer</h1>
			<p>
				Use this tool to turn a plain-English description of your leadership model
				into a clear set of draft competencies — or generate technical traits from
				course objectives — or paste your own list (no AI). You can then review, tweak,
				and copy them into a framework.
			</p>

			<?php if ( $message ) : ?>
				<div class="notice <?php echo esc_attr( $message_class ); ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<style>
				.icon-psy-ai-grid {
					display: grid;
					grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.4fr);
					gap: 16px;
					margin-top: 16px;
				}
				@media (max-width: 960px) {
					.icon-psy-ai-grid {
						grid-template-columns: minmax(0, 1fr);
					}
				}
				.icon-psy-card {
					background:#ffffff;
					border-radius:10px;
					padding:16px 18px;
					box-shadow:0 1px 4px rgba(0,0,0,0.04);
					border:1px solid #e5e7eb;
				}
				.icon-psy-card h2 {
					margin-top:0;
					font-size:16px;
				}
				.icon-psy-ai-competency {
					padding:8px 0;
					border-bottom:1px solid #f3f4f6;
				}
				.icon-psy-ai-competency:last-child {
					border-bottom:none;
				}
				.icon-psy-ai-competency-title {
					font-weight:600;
					margin:0 0 2px 0;
					font-size:13px;
				}
				.icon-psy-ai-competency-desc {
					margin:0;
					font-size:12px;
					color:#4b5563;
					white-space:pre-wrap;
				}
				.icon-psy-ai-competency-meta {
					margin:2px 0 0 0;
					font-size:11px;
					color:#6b7280;
				}
				.icon-psy-inline-actions {
					margin-top:10px;
					display:flex;
					flex-wrap:wrap;
					gap:8px;
					align-items:center;
				}
				.icon-psy-pill-muted {
					display:inline-flex;
					align-items:center;
					gap:4px;
					padding:2px 8px;
					border-radius:999px;
					background:#f3f4f6;
					color:#374151;
					font-size:11px;
				}
				.icon-psy-ai-toggle {
					display:flex;
					gap:10px;
					align-items:center;
					padding:10px 12px;
					border:1px solid #e5e7eb;
					border-radius:10px;
					background:#fbfbfc;
					margin: 10px 0 14px 0;
					flex-wrap: wrap;
				}
				.icon-psy-ai-toggle label {
					font-size:12px;
					color:#374151;
				}
				.icon-psy-ai-hint {
					margin: 6px 0 0 0;
					font-size: 12px;
					color: #6b7280;
				}
			</style>

			<div class="icon-psy-ai-grid">

				<!-- Left: Prompt & Generate -->
				<div class="icon-psy-card">
					<h2>Generate draft items</h2>

					<form method="post">
						<?php wp_nonce_field( 'icon_psy_ai_designer' ); ?>
						<input type="hidden" name="icon_psy_ai_action" value="generate" />

						<div class="icon-psy-ai-toggle">
							<strong style="font-size:12px; color:#111827;">Mode:</strong>
							<label>
								<input type="radio" name="generator_mode" value="competencies" checked />
								Competencies from brief
							</label>
							<label>
								<input type="radio" name="generator_mode" value="traits_from_objectives" />
								Technical traits from objectives
							</label>
							<label>
								<input type="radio" name="generator_mode" value="provided_list" />
								Use my provided list (no AI)
							</label>
						</div>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="icon-psy-ai-title">Working title</label>
								</th>
								<td>
									<input type="text"
										   id="icon-psy-ai-title"
										   name="ai_title"
										   class="regular-text"
										   placeholder="e.g. Icon Leadership Essentials / Strategic Planning (One-day)"
										   required />
								</td>
							</tr>

							<!-- Brief mode -->
							<tr class="icon-psy-row-brief">
								<th scope="row">
									<label for="icon-psy-ai-brief">Brief description</label>
								</th>
								<td>
									<textarea id="icon-psy-ai-brief"
											  name="ai_brief"
											  rows="6"
											  class="large-text"
											  placeholder="Describe the framework levels, behaviours, and focus areas you want this to cover..."></textarea>
									<p class="description">
										The AI will convert this into a set of clear, behaviour-based competencies.
									</p>
								</td>
							</tr>

							<!-- Objectives mode -->
							<tr class="icon-psy-row-objectives" style="display:none;">
								<th scope="row">
									<label for="icon-psy-ai-audience">Audience</label>
								</th>
								<td>
									<input type="text"
										   id="icon-psy-ai-audience"
										   name="ai_audience"
										   class="regular-text"
										   placeholder="e.g. Public sector supervisors, middle managers, analysts" />
									<p class="icon-psy-ai-hint">Optional but helps set the technical depth.</p>
								</td>
							</tr>

							<tr class="icon-psy-row-objectives" style="display:none;">
								<th scope="row">
									<label for="icon-psy-ai-context">Context</label>
								</th>
								<td>
									<input type="text"
										   id="icon-psy-ai-context"
										   name="ai_context"
										   class="regular-text"
										   placeholder="e.g. KSA Ministry of Interior, transformation programme, frontline service delivery" />
									<p class="icon-psy-ai-hint">Optional. Keep it short.</p>
								</td>
							</tr>

							<tr class="icon-psy-row-objectives" style="display:none;">
								<th scope="row">
									<label for="icon-psy-ai-objectives">Course objectives</label>
								</th>
								<td>
									<textarea id="icon-psy-ai-objectives"
											  name="ai_objectives"
											  rows="8"
											  class="large-text"
											  placeholder="Paste the course objectives here (one per line or bullet list)..."></textarea>
									<p class="description">
										The AI will generate technical traits (measurable capabilities) aligned to achieving these objectives.
									</p>
								</td>
							</tr>

							<!-- Provided list mode (NEW) -->
							<tr class="icon-psy-row-provided" style="display:none;">
								<th scope="row">
									<label for="icon-psy-ai-provided">Provided competencies</label>
								</th>
								<td>
									<textarea id="icon-psy-ai-provided"
											  name="ai_provided"
											  rows="10"
											  class="large-text"
											  placeholder="Paste one per line in this format: Name | Description"></textarea>
									<p class="description">
										One item per line. Use the <code>|</code> separator between name and description. No AI will be used.
									</p>
								</td>
							</tr>

							<!-- Number of competencies / traits -->
							<tr>
								<th scope="row">
									<label for="icon-psy-num-competencies">Number of items</label>
								</th>
								<td>
									<input type="number"
										   id="icon-psy-num-competencies"
										   name="num_competencies"
										   min="3"
										   max="20"
										   value="8"
										   class="small-text" />
									<p class="description">
										Choose how many distinct items you want to generate/store (3–20 recommended).
									</p>
								</td>
							</tr>
						</table>

						<?php submit_button( 'Generate Draft', 'primary', 'submit', false ); ?>
						<span class="icon-psy-pill-muted">
							Uses your saved OpenAI key (except provided list mode).
						</span>
					</form>

					<script>
						(function(){
							function updateModeUI(){
								var mode = document.querySelector('input[name="generator_mode"]:checked');
								var objectivesRows = document.querySelectorAll('.icon-psy-row-objectives');
								var briefRows = document.querySelectorAll('.icon-psy-row-brief');
								var providedRows = document.querySelectorAll('.icon-psy-row-provided');

								var isObjectives = mode && mode.value === 'traits_from_objectives';
								var isProvided   = mode && mode.value === 'provided_list';

								objectivesRows.forEach(function(r){ r.style.display = isObjectives ? '' : 'none'; });
								briefRows.forEach(function(r){ r.style.display = (!isObjectives && !isProvided) ? '' : 'none'; });
								providedRows.forEach(function(r){ r.style.display = isProvided ? '' : 'none'; });

								// Toggle required on objectives textarea when in objectives mode
								var obj = document.getElementById('icon-psy-ai-objectives');
								if (obj) {
									if (isObjectives) {
										obj.setAttribute('required', 'required');
									} else {
										obj.removeAttribute('required');
									}
								}

								// Toggle required on provided textarea when in provided_list mode
								var provided = document.getElementById('icon-psy-ai-provided');
								if (provided) {
									if (isProvided) {
										provided.setAttribute('required', 'required');
									} else {
										provided.removeAttribute('required');
									}
								}
							}

							document.querySelectorAll('input[name="generator_mode"]').forEach(function(r){
								r.addEventListener('change', updateModeUI);
							});

							updateModeUI();
						})();
					</script>
				</div>

				<!-- Right: Latest Draft -->
				<div class="icon-psy-card">
					<h2>Latest AI draft</h2>
					<?php if ( ! $latest_draft ) : ?>
						<p style="font-size:13px; color:#6b7280;">
							No drafts yet. Generate a draft on the left to see it here.
						</p>
					<?php else : ?>
						<p style="margin-top:0; font-size:12px; color:#4b5563;">
							<strong><?php echo esc_html( $latest_draft->title ); ?></strong>
							<br />
							<span style="font-size:11px; color:#6b7280;">
								Created: <?php echo esc_html( $latest_draft->created_at ); ?>
								&nbsp;&middot;&nbsp;
								Status: <?php echo esc_html( ucfirst( $latest_draft->status ) ); ?>
							</span>
						</p>

						<?php if ( ! empty( $latest_draft->brief ) ) : ?>
							<details style="margin: 8px 0 12px 0;">
								<summary style="cursor:pointer; font-size:12px; color:#374151;">Show prompt details</summary>
								<div style="margin-top:8px; padding:10px; background:#fbfbfc; border:1px solid #eef0f2; border-radius:10px; font-size:12px; color:#4b5563; white-space:pre-wrap;">
									<?php echo esc_html( $latest_draft->brief ); ?>
								</div>
							</details>
						<?php endif; ?>

						<?php if ( ! empty( $latest_comps ) ) : ?>
							<?php foreach ( $latest_comps as $comp ) : ?>
								<div class="icon-psy-ai-competency">
									<p class="icon-psy-ai-competency-title">
										<?php echo esc_html( $comp->name ); ?>
									</p>
									<?php if ( ! empty( $comp->description ) ) : ?>
										<p class="icon-psy-ai-competency-desc">
											<?php echo esc_html( $comp->description ); ?>
										</p>
									<?php endif; ?>
									<p class="icon-psy-ai-competency-meta">
										Module: <?php echo esc_html( $comp->module ); ?>
										&nbsp;&middot;&nbsp;
										Order: <?php echo (int) $comp->sort_order; ?>
									</p>
								</div>
							<?php endforeach; ?>
						<?php else : ?>
							<p style="font-size:12px; color:#6b7280;">
								This draft has no parsed items yet.
							</p>
						<?php endif; ?>

						<div class="icon-psy-inline-actions">
							<form method="post" style="margin:0;">
								<?php wp_nonce_field( 'icon_psy_ai_designer' ); ?>
								<input type="hidden" name="icon_psy_ai_action" value="delete_draft" />
								<input type="hidden" name="draft_id" value="<?php echo (int) $latest_draft->id; ?>" />
								<button type="submit"
										class="button button-secondary button-link-delete"
										onclick="return confirm('Delete this AI draft?');">
									Delete draft
								</button>
							</form>

							<?php if ( ! empty( $frameworks ) ) : ?>
								<form method="post" style="margin:0;">
									<?php wp_nonce_field( 'icon_psy_ai_designer' ); ?>
									<input type="hidden" name="icon_psy_ai_action" value="save_to_framework" />
									<input type="hidden" name="draft_id" value="<?php echo (int) $latest_draft->id; ?>" />
									<select name="framework_id" required>
										<option value=""><?php esc_html_e( 'Copy into framework…', 'icon-psy' ); ?></option>
										<?php foreach ( $frameworks as $fw ) : ?>
											<option value="<?php echo (int) $fw->id; ?>">
												<?php echo esc_html( $fw->name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<button type="submit" class="button">
										Copy items
									</button>
								</form>
							<?php else : ?>
								<span class="icon-psy-pill-muted">
									No frameworks yet – create one first to copy this draft into.
								</span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * NEW: Parse provided list (no AI) into items compatible with draft storage/UI.
	 *
	 * Expected format:
	 *   Name | Description
	 * one per line. Description is optional.
	 *
	 * Returns array(
	 *   'raw'          => string,
	 *   'competencies' => array( array('name','description','module'), ... )
	 * )
	 */
	protected static function parse_provided_list_to_items( $provided, $limit = 20 ) {
		$limit = (int) $limit;
		if ( $limit < 1 ) {
			$limit = 1;
		} elseif ( $limit > 20 ) {
			$limit = 20;
		}

		$lines = preg_split( "/\r\n|\n|\r/", trim( (string) $provided ) );
		$items = array();

		foreach ( $lines as $line ) {
			$line = trim( wp_strip_all_tags( $line ) );
			if ( '' === $line ) {
				continue;
			}

			// Expect: Name | Description
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$name  = isset( $parts[0] ) ? $parts[0] : '';
			$desc  = isset( $parts[1] ) ? $parts[1] : '';

			if ( '' === $name ) {
				continue;
			}

			$items[] = array(
				'name'        => $name,
				'description' => $desc,
				'module'      => 'core',
			);

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return array(
			'raw'          => 'Provided list (no AI). Parsed ' . count( $items ) . ' items.',
			'competencies' => $items,
		);
	}

	/**
	 * Call OpenAI and parse a simple JSON-like competency list.
	 *
	 * Returns array(
	 *   'raw'          => (string) full model response,
	 *   'competencies' => array(
	 *        array( 'name' => '...', 'description' => '...', 'module' => 'core' ),
	 *        ...
	 *   ),
	 * ).
	 *
	 * If something fails, returns WP_Error.
	 */
	protected static function generate_competencies_from_brief( $title, $brief, $num_competencies = 8 ) {
		$api_key = get_option( 'icon_psy_openai_api_key', '' );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'no_api_key',
				'No OpenAI API key found. Please add it in Icon Psych Settings.'
			);
		}

		// Make sure the number is sensible even if called directly.
		$num_competencies = (int) $num_competencies;
		if ( $num_competencies < 3 ) {
			$num_competencies = 3;
		} elseif ( $num_competencies > 20 ) {
			$num_competencies = 20;
		}

		$prompt = "You are designing a leadership competency framework called \"{$title}\".

Brief description of the framework context:
{$brief}

Generate exactly {$num_competencies} distinct leadership competencies.

For each competency:
- \"name\": a short, punchy title (max 5 words)
- \"description\": 2–3 sentences (around 30–80 words) describing what excellent performance looks like, using clear, observable, behaviour-based language
- \"module\": always \"core\"

Return JSON ONLY in this exact structure:

{
  \"competencies\": [
    { \"name\": \"...\", \"description\": \"...\", \"module\": \"core\" },
    { \"name\": \"...\", \"description\": \"...\", \"module\": \"core\" }
  ]
}";

		$body = array(
			'model'    => 'gpt-4.1-mini',
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => 'You are an expert in leadership frameworks and competency design.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature' => 0.4,
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code      = wp_remote_retrieve_response_code( $response );
		$resp_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'api_error',
				'OpenAI API error: ' . $code . ' ' . $resp_body
			);
		}

		$data = json_decode( $resp_body, true );

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'bad_response',
				'Unexpected response from OpenAI.'
			);
		}

		$raw_text = $data['choices'][0]['message']['content'];

		// Try to parse JSON from the model response (it might contain extra text, so strip).
		$json_start   = strpos( $raw_text, '{' );
		$json_end     = strrpos( $raw_text, '}' );
		$competencies = array();

		if ( false !== $json_start && false !== $json_end && $json_end > $json_start ) {
			$json_str = substr( $raw_text, $json_start, $json_end - $json_start + 1 );
			$parsed   = json_decode( $json_str, true );

			if ( isset( $parsed['competencies'] ) && is_array( $parsed['competencies'] ) ) {
				foreach ( $parsed['competencies'] as $c ) {
					if ( empty( $c['name'] ) ) {
						continue;
					}
					$competencies[] = array(
						'name'        => (string) $c['name'],
						'description' => isset( $c['description'] ) ? (string) $c['description'] : '',
						'module'      => ! empty( $c['module'] ) ? (string) $c['module'] : 'core',
					);
				}
			}
		}

		return array(
			'raw'          => $raw_text,
			'competencies' => $competencies,
		);
	}

	/**
	 * Generate "technical traits" from course objectives.
	 *
	 * We still return using the same array keys to reuse storage + UI:
	 * - 'competencies' is used as the list of generated items
	 * - each item: name, description, module
	 */
	protected static function generate_technical_traits_from_objectives( $title, $objectives, $audience = '', $context = '', $num_traits = 8 ) {
		$api_key = get_option( 'icon_psy_openai_api_key', '' );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'no_api_key',
				'No OpenAI API key found. Please add it in Icon Psych Settings.'
			);
		}

		$num_traits = (int) $num_traits;
		if ( $num_traits < 3 ) {
			$num_traits = 3;
		} elseif ( $num_traits > 20 ) {
			$num_traits = 20;
		}

		$audience_line = $audience ? "Audience: {$audience}\n" : '';
		$context_line  = $context ? "Context: {$context}\n" : '';

		$prompt = "You are an instructional design analyst for professional technical training.

Course title:
\"{$title}\"

{$audience_line}{$context_line}
Course objectives (paste may include bullets/lines):
{$objectives}

Task:
Generate exactly {$num_traits} technical traits (measurable capabilities) required to achieve these objectives.

Rules:
- Traits must be technical and measurable (avoid vague traits like \"good communicator\" unless the objectives are explicitly communication-focused and you define it technically).
- Each trait name must be max 5 words.
- Each trait description must be 2–4 sentences, written as observable workplace capability.
- Every objective must be covered by at least 2 traits across the full set (ensure broad coverage).
- Do not invent specific tools, frameworks, or standards unless explicitly mentioned in the objectives/context. Keep tool references generic when unsure.
- Use British English spelling.
- Output JSON ONLY.

Return JSON in this exact structure:

{
  \"competencies\": [
	{ \"name\": \"...\", \"description\": \"...\", \"module\": \"core\" }
  ]
}";

		$body = array(
			'model'    => 'gpt-4.1-mini',
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => 'You are an expert in instructional design and competency modelling. You write clear, measurable technical traits aligned to objectives.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature' => 0.35,
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 45,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code      = wp_remote_retrieve_response_code( $response );
		$resp_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'api_error',
				'OpenAI API error: ' . $code . ' ' . $resp_body
			);
		}

		$data = json_decode( $resp_body, true );

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'bad_response',
				'Unexpected response from OpenAI.'
			);
		}

		$raw_text = $data['choices'][0]['message']['content'];

		// Parse JSON from the model response (it might contain extra text, so strip).
		$json_start = strpos( $raw_text, '{' );
		$json_end   = strrpos( $raw_text, '}' );

		$items = array();

		if ( false !== $json_start && false !== $json_end && $json_end > $json_start ) {
			$json_str = substr( $raw_text, $json_start, $json_end - $json_start + 1 );
			$parsed   = json_decode( $json_str, true );

			if ( isset( $parsed['competencies'] ) && is_array( $parsed['competencies'] ) ) {
				foreach ( $parsed['competencies'] as $c ) {
					if ( empty( $c['name'] ) ) {
						continue;
					}
					$items[] = array(
						'name'        => (string) $c['name'],
						'description' => isset( $c['description'] ) ? (string) $c['description'] : '',
						'module'      => ! empty( $c['module'] ) ? (string) $c['module'] : 'core',
					);
				}
			}
		}

		return array(
			'raw'          => $raw_text,
			'competencies' => $items,
		);
	}
}

// Make sure init runs (tables etc.)
Icon_PSY_AI_Competency_Designer::init();
