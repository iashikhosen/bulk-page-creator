<?php
/**
 * Plugin Name: Bulk Page Creation
 * Plugin URI: https://ashikhosen.com
 * Description: Create WordPress pages and unlimited nested parent/child page structures from a simple text format.
 * Version: 2.1.0
 * Author: Ashik Hosen
 * Author URI: https://ashikhosen.com
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ash_Bulk_Page_Creation {

	/**
	 * Plugin slug.
	 */
	private $slug = 'ash-bulk-page-creation';

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action(
			'admin_menu',
			array( $this, 'add_admin_menu' )
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			array( $this, 'add_settings_link' )
		);

		add_action(
			'admin_enqueue_scripts',
			array( $this, 'admin_assets' )
		);
	}

	/**
	 * Add Settings link beside Deactivate.
	 */
	public function add_settings_link( $links ) {

		$settings_url = admin_url(
			'tools.php?page=' . $this->slug
		);

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $settings_url ),
			esc_html__( 'Settings', 'ash-bulk-page-creation' )
		);

		array_unshift(
			$links,
			$settings_link
		);

		return $links;
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {

		add_management_page(
			'Bulk Page Creation',
			'Bulk Page Creation',
			'manage_options',
			$this->slug,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Load required admin assets.
	 */
	public function admin_assets( $hook ) {

		if ( 'tools_page_' . $this->slug !== $hook ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
	}

	/**
	 * Render admin page.
	 */
	public function render_admin_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$input  = '';
		$result = null;

		if (
			isset( $_POST['ash_bpc_create'] ) &&
			isset( $_POST['ash_bpc_nonce'] )
		) {

			$nonce = sanitize_text_field(
				wp_unslash(
					$_POST['ash_bpc_nonce']
				)
			);

			if (
				! wp_verify_nonce(
					$nonce,
					'ash_bpc_create_pages'
				)
			) {

				$result = array(
					'created'  => array(),
					'existing' => array(),
					'errors'   => array(
						'Security verification failed. Please try again.',
					),
				);

			} else {

				$input = isset( $_POST['ash_bpc_content'] )
					? wp_unslash( $_POST['ash_bpc_content'] )
					: '';

				$result = $this->create_pages_from_text(
					$input
				);
			}
		}

		?>

		<div class="ash-bpc-wrap">

			<div class="ash-bpc-shell">

				<!-- Header -->
				<div class="ash-bpc-header">

					<div class="ash-bpc-brand">

						<div class="ash-bpc-brand-icon">
							<span class="dashicons dashicons-admin-page"></span>
						</div>

						<div>

							<h1>Bulk Page Creation</h1>

							<p>
								Create your WordPress page structure
								from a simple text format.
							</p>

						</div>

					</div>

				</div>


				<!-- Main Card -->
				<div class="ash-bpc-card">

					<div class="ash-bpc-card-head">

						<div>

							<h2>Page Structure</h2>

							<p>
								Enter one page per line. Use dashes
								to define parent and child relationships.
							</p>

						</div>

						<div class="ash-bpc-format-badge">

							<span class="dashicons dashicons-editor-code"></span>

							Text Format

						</div>

					</div>


					<form
						method="post"
						id="ash-bpc-form"
					>

						<?php
						wp_nonce_field(
							'ash_bpc_create_pages',
							'ash_bpc_nonce'
						);
						?>


						<!-- Editor -->
						<div class="ash-bpc-editor-wrap">

							<div class="ash-bpc-editor-toolbar">

								<span>
									Page hierarchy
								</span>

								<span class="ash-bpc-line-info">

									<span id="ash-bpc-line-count">
										0
									</span>

									lines

								</span>

							</div>


							<textarea
								name="ash_bpc_content"
								id="ash-bpc-content"
								placeholder="Work
- Portfolio
-- WordPress
--- Elementor
--- Bricks
-- LMS
- Case Studies
About
Contact"
								spellcheck="false"
							><?php
								echo esc_textarea(
									$input
								);
							?></textarea>

						</div>


						<!-- How It Works -->
						<div class="ash-bpc-help">

							<div class="ash-bpc-help-top">

								<div class="ash-bpc-help-title">

									<span class="dashicons dashicons-info-outline"></span>

									How it works

								</div>


								<button
									type="button"
									class="ash-bpc-copy-prompt"
									id="ash-bpc-copy-prompt"
								>

									<span class="dashicons dashicons-admin-page"></span>

									<span class="ash-bpc-copy-text">
										Copy Prompt
									</span>

								</button>

							</div>


							<div class="ash-bpc-examples">

								<div class="ash-bpc-example">

									<code>Work</code>

									<span>
										Top-level page
									</span>

								</div>


								<div class="ash-bpc-example">

									<code>- Portfolio</code>

									<span>
										Child of Work
									</span>

								</div>


								<div class="ash-bpc-example">

									<code>-- WordPress</code>

									<span>
										Child of Portfolio
									</span>

								</div>


								<div class="ash-bpc-example">

									<code>--- Elementor</code>

									<span>
										Child of WordPress
									</span>

								</div>

							</div>


							<div class="ash-bpc-help-note">

								You can continue nesting with as many
								dashes as needed.

							</div>

						</div>


						<!-- Result -->
						<?php if ( $result ) : ?>

							<?php
							$this->render_result(
								$result
							);
							?>

						<?php endif; ?>


						<!-- Actions -->
						<div class="ash-bpc-actions">

							<button
								type="button"
								class="ash-bpc-btn ash-bpc-btn-secondary"
								id="ash-bpc-preview"
							>

								<span class="dashicons dashicons-visibility"></span>

								Preview

							</button>


							<button
								type="submit"
								name="ash_bpc_create"
								class="ash-bpc-btn ash-bpc-btn-primary"
								id="ash-bpc-create"
							>

								<span class="dashicons dashicons-plus-alt2"></span>

								Create Pages

							</button>

						</div>

					</form>

				</div>


				<!-- Preview Card -->
				<div
					class="ash-bpc-card ash-bpc-preview-card"
					id="ash-bpc-preview-card"
					style="display:none;"
				>

					<div class="ash-bpc-card-head">

						<div>

							<h2>Preview</h2>

							<p>
								This is how your WordPress page
								hierarchy will be created.
							</p>

						</div>


						<button
							type="button"
							class="ash-bpc-close-preview"
							id="ash-bpc-close-preview"
							aria-label="Close preview"
						>

							<span class="dashicons dashicons-no-alt"></span>

						</button>

					</div>


					<div
						class="ash-bpc-tree"
						id="ash-bpc-tree"
					></div>

				</div>


				<!-- Footer -->
				<div class="ash-bpc-footer">

					<span>
						Bulk Page Creation
					</span>

					<span>
						WordPress native Pages
					</span>

				</div>

			</div>

		</div>


		<style>

			/* =========================================================
			 * Base
			 * ========================================================= */

			.ash-bpc-wrap {
				margin: 0 20px 0 0;
				color: #1d2327;
			}

			.ash-bpc-wrap *,
			.ash-bpc-wrap *::before,
			.ash-bpc-wrap *::after {
				box-sizing: border-box;
			}

			.ash-bpc-shell {
				max-width: 1120px;
				margin: 32px auto;
			}


			/* =========================================================
			 * Header
			 * ========================================================= */

			.ash-bpc-header {
				margin-bottom: 24px;
			}

			.ash-bpc-brand {
				display: flex;
				align-items: center;
				gap: 16px;
			}

			.ash-bpc-brand-icon {
				width: 52px;
				height: 52px;
				border-radius: 12px;
				background: #2271b1;
				color: #fff;
				display: flex;
				align-items: center;
				justify-content: center;
				box-shadow: 0 5px 16px rgba(34, 113, 177, 0.18);
			}

			.ash-bpc-brand-icon .dashicons {
				font-size: 25px;
				width: 25px;
				height: 25px;
			}

			.ash-bpc-brand h1 {
				margin: 0 0 4px;
				font-size: 25px;
				line-height: 1.25;
				font-weight: 600;
				color: #1d2327;
			}

			.ash-bpc-brand p {
				margin: 0;
				color: #646970;
				font-size: 14px;
			}


			/* =========================================================
			 * Cards
			 * ========================================================= */

			.ash-bpc-card {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 10px;
				box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
				margin-bottom: 20px;
				overflow: hidden;
			}

			.ash-bpc-card-head {
				padding: 22px 24px;
				border-bottom: 1px solid #f0f0f1;
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 20px;
			}

			.ash-bpc-card-head h2 {
				font-size: 17px;
				margin: 0 0 5px;
				font-weight: 600;
			}

			.ash-bpc-card-head p {
				margin: 0;
				font-size: 13px;
				color: #646970;
			}

			.ash-bpc-format-badge {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				padding: 6px 10px;
				border-radius: 6px;
				background: #f6f7f7;
				border: 1px solid #dcdcde;
				color: #50575e;
				font-size: 12px;
				font-weight: 500;
				white-space: nowrap;
			}

			.ash-bpc-format-badge .dashicons {
				font-size: 15px;
				width: 15px;
				height: 15px;
			}


			/* =========================================================
			 * Editor
			 * ========================================================= */

			.ash-bpc-editor-wrap {
				margin: 24px;
				border: 1px solid #c3c4c7;
				border-radius: 8px;
				overflow: hidden;
				background: #fff;
			}

			.ash-bpc-editor-toolbar {
				height: 42px;
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 0 13px;
				background: #f6f7f7;
				border-bottom: 1px solid #dcdcde;
				font-size: 12px;
				font-weight: 600;
				color: #50575e;
			}

			.ash-bpc-line-info {
				font-weight: 400;
				color: #8c8f94;
			}

			#ash-bpc-content {
				display: block;
				width: 100%;
				min-height: 360px;
				padding: 18px;
				border: 0;
				outline: none;
				box-shadow: none;
				resize: vertical;
				font-family: Consolas, Monaco, monospace;
				font-size: 14px;
				line-height: 1.8;
				color: #1d2327;
				background: #fff;
			}

			#ash-bpc-content:focus {
				outline: none;
				box-shadow: inset 0 0 0 1px #2271b1;
			}

			#ash-bpc-content::placeholder {
				color: #a7aaad;
			}


			/* =========================================================
			 * How It Works
			 * ========================================================= */

			.ash-bpc-help {
				margin: 0 24px 24px;
				padding: 18px;
				border: 1px solid #e2e4e7;
				border-radius: 8px;
				background: #f9f9f9;
			}

			.ash-bpc-help-top {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 15px;
				margin-bottom: 14px;
			}

			.ash-bpc-help-title {
				display: flex;
				align-items: center;
				gap: 7px;
				font-size: 13px;
				font-weight: 600;
				margin: 0;
			}

			.ash-bpc-help-title .dashicons {
				color: #2271b1;
				font-size: 17px;
				width: 17px;
				height: 17px;
			}


			/* Copy Prompt Button */

			.ash-bpc-copy-prompt {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				gap: 6px;
				height: 30px;
				padding: 0 10px;
				border: 1px solid #c3c4c7;
				border-radius: 5px;
				background: #fff;
				color: #50575e;
				font-size: 12px;
				font-weight: 500;
				cursor: pointer;
				transition: all 0.15s ease;
			}

			.ash-bpc-copy-prompt:hover {
				border-color: #8c8f94;
				background: #f6f7f7;
				color: #1d2327;
			}

			.ash-bpc-copy-prompt .dashicons {
				width: 15px;
				height: 15px;
				font-size: 15px;
			}

			.ash-bpc-copy-prompt.is-copied {
				border-color: #46b450;
				color: #28752c;
				background: #f0f8f0;
			}


			/* Examples */

			.ash-bpc-examples {
				display: grid;
				grid-template-columns: repeat(2, 1fr);
				gap: 8px;
			}

			.ash-bpc-example {
				display: flex;
				align-items: center;
				gap: 10px;
				min-width: 0;
			}

			.ash-bpc-example code {
				display: inline-block;
				min-width: 130px;
				padding: 6px 8px;
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 5px;
				font-size: 12px;
				color: #2c3338;
			}

			.ash-bpc-example span {
				font-size: 12px;
				color: #646970;
			}

			.ash-bpc-help-note {
				margin-top: 12px;
				font-size: 12px;
				color: #646970;
			}


			/* =========================================================
			 * Actions
			 * ========================================================= */

			.ash-bpc-actions {
				display: flex;
				align-items: center;
				justify-content: flex-end;
				gap: 10px;
				padding: 18px 24px;
				background: #fafafa;
				border-top: 1px solid #f0f0f1;
			}

			.ash-bpc-btn {
				display: inline-flex !important;
				align-items: center;
				justify-content: center;
				gap: 7px;
				min-height: 38px;
				padding: 0 16px !important;
				border-radius: 6px !important;
				font-size: 13px !important;
				font-weight: 500 !important;
				cursor: pointer;
				text-decoration: none;
				transition: all 0.15s ease;
			}

			.ash-bpc-btn .dashicons {
				font-size: 17px;
				width: 17px;
				height: 17px;
			}

			.ash-bpc-btn-secondary {
				background: #fff !important;
				border: 1px solid #c3c4c7 !important;
				color: #2c3338 !important;
			}

			.ash-bpc-btn-secondary:hover {
				border-color: #8c8f94 !important;
				background: #f6f7f7 !important;
			}

			.ash-bpc-btn-primary {
				background: #2271b1 !important;
				border: 1px solid #2271b1 !important;
				color: #fff !important;
			}

			.ash-bpc-btn-primary:hover {
				background: #135e96 !important;
				border-color: #135e96 !important;
			}

			.ash-bpc-btn:disabled {
				opacity: 0.55;
				cursor: not-allowed;
			}


			/* =========================================================
			 * Preview
			 * ========================================================= */

			.ash-bpc-preview-card {
				animation: ashBpcFadeIn 0.18s ease;
			}

			@keyframes ashBpcFadeIn {

				from {
					opacity: 0;
					transform: translateY(-4px);
				}

				to {
					opacity: 1;
					transform: translateY(0);
				}

			}

			.ash-bpc-close-preview {
				width: 34px;
				height: 34px;
				border: 1px solid #dcdcde;
				background: #fff;
				border-radius: 6px;
				display: flex;
				align-items: center;
				justify-content: center;
				cursor: pointer;
				color: #646970;
			}

			.ash-bpc-close-preview:hover {
				background: #f6f7f7;
				color: #1d2327;
			}

			.ash-bpc-tree {
				padding: 24px;
			}

			.ash-bpc-tree-empty {
				padding: 35px 20px;
				text-align: center;
				color: #646970;
				font-size: 13px;
			}

			.ash-bpc-tree-row {
				display: flex;
				align-items: center;
				min-height: 42px;
				border-bottom: 1px solid #f0f0f1;
				font-size: 13px;
			}

			.ash-bpc-tree-row:last-child {
				border-bottom: 0;
			}

			.ash-bpc-tree-indent {
				display: flex;
				flex: 0 0 auto;
				height: 42px;
				position: relative;
			}

			.ash-bpc-tree-branch {
				width: 20px;
				height: 100%;
				border-left: 1px solid #dcdcde;
				position: relative;
			}

			.ash-bpc-tree-branch::after {
				content: "";
				position: absolute;
				left: 0;
				top: 20px;
				width: 14px;
				border-top: 1px solid #dcdcde;
			}

			.ash-bpc-tree-icon {
				width: 30px;
				height: 30px;
				border-radius: 6px;
				background: #f0f6fc;
				color: #2271b1;
				display: flex;
				align-items: center;
				justify-content: center;
				flex: 0 0 30px;
				margin-right: 10px;
			}

			.ash-bpc-tree-icon .dashicons {
				font-size: 16px;
				width: 16px;
				height: 16px;
			}

			.ash-bpc-tree-title {
				font-weight: 500;
				color: #1d2327;
			}

			.ash-bpc-tree-level {
				margin-left: auto;
				padding: 3px 7px;
				border-radius: 4px;
				background: #f6f7f7;
				color: #8c8f94;
				font-size: 11px;
			}


			/* =========================================================
			 * Results
			 * ========================================================= */

			.ash-bpc-result {
				margin: 0 24px 24px;
				border-radius: 8px;
				overflow: hidden;
				border: 1px solid #dcdcde;
			}

			.ash-bpc-result-header {
				padding: 14px 16px;
				background: #f6f7f7;
				border-bottom: 1px solid #dcdcde;
				font-size: 13px;
				font-weight: 600;
			}

			.ash-bpc-result-list {
				margin: 0;
				padding: 0;
				list-style: none;
				max-height: 280px;
				overflow-y: auto;
				background: #fff;
			}

			.ash-bpc-result-list li {
				padding: 9px 16px;
				border-bottom: 1px solid #f0f0f1;
				font-size: 12px;
			}

			.ash-bpc-result-list li:last-child {
				border-bottom: 0;
			}

			.ash-bpc-result-created {
				border-color: #c6e1c6;
			}

			.ash-bpc-result-created .ash-bpc-result-header {
				background: #edfaef;
				color: #1e4620;
			}

			.ash-bpc-result-existing {
				border-color: #c5d9ed;
			}

			.ash-bpc-result-existing .ash-bpc-result-header {
				background: #eef6fc;
				color: #164b72;
			}

			.ash-bpc-result-error {
				border-color: #e5c7c7;
			}

			.ash-bpc-result-error .ash-bpc-result-header {
				background: #fcf0f0;
				color: #8a2424;
			}


			/* =========================================================
			 * Footer
			 * ========================================================= */

			.ash-bpc-footer {
				display: flex;
				justify-content: space-between;
				padding: 4px 2px;
				color: #8c8f94;
				font-size: 11px;
			}


			/* =========================================================
			 * Responsive
			 * ========================================================= */

			@media (max-width: 782px) {

				.ash-bpc-shell {
					margin: 20px 10px;
				}

				.ash-bpc-wrap {
					margin-right: 10px;
				}

				.ash-bpc-card-head {
					align-items: flex-start;
				}

				.ash-bpc-help-top {
					align-items: flex-start;
				}

				.ash-bpc-examples {
					grid-template-columns: 1fr;
				}

				.ash-bpc-actions {
					flex-direction: column-reverse;
					align-items: stretch;
				}

				.ash-bpc-btn {
					width: 100%;
				}

				.ash-bpc-footer {
					flex-direction: column;
					gap: 5px;
				}

			}

			@media (max-width: 500px) {

				.ash-bpc-help-top {
					flex-direction: column;
					align-items: stretch;
				}

				.ash-bpc-copy-prompt {
					width: 100%;
				}

				.ash-bpc-example {
					align-items: flex-start;
					flex-direction: column;
					gap: 4px;
				}

				.ash-bpc-example code {
					min-width: 0;
					width: 100%;
				}

			}

		</style>


		<script>

			document.addEventListener(
				'DOMContentLoaded',
				function() {

					const textarea =
						document.getElementById(
							'ash-bpc-content'
						);

					const lineCount =
						document.getElementById(
							'ash-bpc-line-count'
						);

					const previewButton =
						document.getElementById(
							'ash-bpc-preview'
						);

					const closePreview =
						document.getElementById(
							'ash-bpc-close-preview'
						);

					const previewCard =
						document.getElementById(
							'ash-bpc-preview-card'
						);

					const tree =
						document.getElementById(
							'ash-bpc-tree'
						);

					const form =
						document.getElementById(
							'ash-bpc-form'
						);

					const copyPromptButton =
						document.getElementById(
							'ash-bpc-copy-prompt'
						);

					const copyPromptText =
						copyPromptButton
							? copyPromptButton.querySelector(
								'.ash-bpc-copy-text'
							)
							: null;


					/*
					 * Generic prompt.
					 *
					 * No specific AI platform is mentioned.
					 */
					const pageTreePrompt =
`Convert the page requirements I provide into a WordPress page hierarchy.

Return ONLY the final page hierarchy in plain text.

Follow these rules exactly:

1. A page with no dash is a top-level page.
2. A page beginning with one dash (-) is a child of the nearest previous top-level page.
3. A page beginning with two dashes (--) is a child of the nearest previous one-dash page.
4. A page beginning with three dashes (---) is a child of the nearest previous two-dash page.
5. Continue the same pattern for unlimited nesting.
6. Preserve the logical parent/child relationships.
7. Preserve the order of the pages.
8. Do not add numbering.
9. Do not add bullet points other than the required dashes.
10. Do not add explanations.
11. Do not add headings.
12. Do not use Markdown code fences.
13. Return only the page hierarchy.

Example:

Work
- Portfolio
-- WordPress
--- Elementor
--- Bricks
-- LMS
- Case Studies
-- LearnDash
-- Membership
About
Contact

Now convert the requirements I provide into this exact format.`;


					/**
					 * Parse page structure.
					 */
					function parseStructure() {

						const lines =
							textarea.value
								.replace(
									/\r\n/g,
									'\n'
								)
								.replace(
									/\r/g,
									'\n'
								)
								.split('\n');

						const items = [];

						lines.forEach(
							function(rawLine) {

								const line =
									rawLine.trim();

								if (!line) {
									return;
								}

								const match =
									line.match(
										/^(-*)(?:\s*)(.*?)\s*$/
									);

								if (!match) {
									return;
								}

								const dashes =
									match[1] || '';

								const title =
									(
										match[2] || ''
									).trim();

								if (!title) {
									return;
								}

								items.push(
									{
										level:
											dashes.length,
										title:
											title
									}
								);

							}
						);

						return items;
					}


					/**
					 * Update line counter.
					 */
					function updateLineCount() {

						const value =
							textarea.value.trim();

						if (!value) {

							lineCount.textContent =
								'0';

							return;
						}

						const lines =
							value
								.split(
									/\r\n|\r|\n/
								)
								.filter(
									function(line) {
										return (
											line.trim() !== ''
										);
									}
								);

						lineCount.textContent =
							lines.length;
					}


					/**
					 * Escape HTML.
					 */
					function escapeHtml(value) {

						const div =
							document.createElement(
								'div'
							);

						div.textContent =
							value;

						return div.innerHTML;
					}


					/**
					 * Render preview.
					 */
					function renderPreview() {

						const items =
							parseStructure();

						tree.innerHTML =
							'';

						if (!items.length) {

							tree.innerHTML =
								'<div class="ash-bpc-tree-empty">' +
								'Enter your page structure above to see a preview.' +
								'</div>';

							previewCard.style.display =
								'block';

							return;
						}


						items.forEach(
							function(item) {

								const row =
									document.createElement(
										'div'
									);

								row.className =
									'ash-bpc-tree-row';


								let indent = '';

								for (
									let i = 0;
									i < item.level;
									i++
								) {

									indent +=
										'<div class="ash-bpc-tree-branch"></div>';

								}


								const relationship =
									item.level === 0
										? 'Top level'
										: 'Level ' +
											item.level;


								row.innerHTML =

									'<div class="ash-bpc-tree-indent">' +
										indent +
									'</div>' +

									'<div class="ash-bpc-tree-icon">' +
										'<span class="dashicons dashicons-admin-page"></span>' +
									'</div>' +

									'<div class="ash-bpc-tree-title">' +
										escapeHtml(
											item.title
										) +
									'</div>' +

									'<div class="ash-bpc-tree-level">' +
										relationship +
									'</div>';


								tree.appendChild(
									row
								);

							}
						);


						previewCard.style.display =
							'block';


						previewCard.scrollIntoView(
							{
								behavior:
									'smooth',
								block:
									'start'
							}
						);
					}


					/**
					 * Copy generic prompt.
					 */
					if ( copyPromptButton ) {

						copyPromptButton.addEventListener(
							'click',
							function() {

								const showCopied =
									function() {

										copyPromptButton.classList.add(
											'is-copied'
										);

										if ( copyPromptText ) {

											copyPromptText.textContent =
												'Copied!';

										}

										setTimeout(
											function() {

												copyPromptButton.classList.remove(
													'is-copied'
												);

												if (
													copyPromptText
												) {

													copyPromptText.textContent =
														'Copy Prompt';

												}

											},
											1800
										);

									};


								if (
									navigator.clipboard &&
									window.isSecureContext
								) {

									navigator.clipboard
										.writeText(
											pageTreePrompt
										)
										.then(
											showCopied
										)
										.catch(
											function() {

												fallbackCopy(
													showCopied
												);

											}
										);

								} else {

									fallbackCopy(
										showCopied
									);

								}

							}
						);

					}


					/**
					 * Clipboard fallback.
					 */
					function fallbackCopy(
						callback
					) {

						const temporaryTextarea =
							document.createElement(
								'textarea'
							);

						temporaryTextarea.value =
							pageTreePrompt;

						temporaryTextarea.setAttribute(
							'readonly',
							''
						);

						temporaryTextarea.style.position =
							'fixed';

						temporaryTextarea.style.top =
							'-9999px';

						temporaryTextarea.style.left =
							'-9999px';

						document.body.appendChild(
							temporaryTextarea
						);

						temporaryTextarea.select();

						temporaryTextarea.setSelectionRange(
							0,
							temporaryTextarea.value.length
						);

						try {

							const successful =
								document.execCommand(
									'copy'
								);

							if ( successful ) {

								callback();

							} else {

								alert(
									'Unable to copy the prompt.'
								);

							}

						} catch (error) {

							alert(
								'Unable to copy the prompt.'
							);

						}

						document.body.removeChild(
							temporaryTextarea
						);
					}


					/**
					 * Live line counter.
					 */
					textarea.addEventListener(
						'input',
						updateLineCount
					);


					/**
					 * Preview.
					 */
					previewButton.addEventListener(
						'click',
						renderPreview
					);


					/**
					 * Close preview.
					 */
					closePreview.addEventListener(
						'click',
						function() {

							previewCard.style.display =
								'none';

						}
					);


					/**
					 * Prevent accidental double submit.
					 */
					form.addEventListener(
						'submit',
						function() {

							const createButton =
								document.getElementById(
									'ash-bpc-create'
								);

							createButton.disabled =
								true;

							createButton.innerHTML =
								'<span class="dashicons dashicons-update"></span>' +
								' Creating...';

						}
					);


					/**
					 * Initial line count.
					 */
					updateLineCount();

				}
			);

		</script>

		<?php
	}

	/**
	 * Render result panels.
	 *
	 * @param array $result Result data.
	 */
	private function render_result( $result ) {

		$created_count =
			count( $result['created'] );

		$existing_count =
			count( $result['existing'] );

		$error_count =
			count( $result['errors'] );


		/*
		 * Created pages.
		 */
		if ( $created_count > 0 ) :
			?>

			<div class="ash-bpc-result ash-bpc-result-created">

				<div class="ash-bpc-result-header">

					<?php
					echo esc_html(
						$created_count .
						' page' .
						(
							1 === $created_count
								? ''
								: 's'
						) .
						' created successfully.'
					);
					?>

				</div>


				<ul class="ash-bpc-result-list">

					<?php
					foreach (
						$result['created']
						as $page
					) :
						?>

						<li>

							<strong>
								<?php
								echo esc_html(
									$page['title']
								);
								?>
							</strong>

							<?php
							if (
								$page['parent']
							) :
								?>

								<span>
									— Child of
									<?php
									echo esc_html(
										$page['parent']
									);
									?>
								</span>

							<?php else : ?>

								<span>
									— Top-level page
								</span>

							<?php endif; ?>

						</li>

					<?php endforeach; ?>

				</ul>

			</div>

		<?php endif;


		/*
		 * Existing pages.
		 */
		if ( $existing_count > 0 ) :
			?>

			<div class="ash-bpc-result ash-bpc-result-existing">

				<div class="ash-bpc-result-header">

					<?php
					echo esc_html(
						$existing_count .
						' page' .
						(
							1 === $existing_count
								? ''
								: 's'
						) .
						' already existed and were reused.'
					);
					?>

				</div>


				<ul class="ash-bpc-result-list">

					<?php
					foreach (
						$result['existing']
						as $page
					) :
						?>

						<li>

							<strong>
								<?php
								echo esc_html(
									$page['title']
								);
								?>
							</strong>

							<?php
							if (
								$page['parent']
							) :
								?>

								<span>
									— Parent:
									<?php
									echo esc_html(
										$page['parent']
									);
									?>
								</span>

							<?php else : ?>

								<span>
									— Top-level page
								</span>

							<?php endif; ?>

						</li>

					<?php endforeach; ?>

				</ul>

			</div>

		<?php endif;


		/*
		 * Errors.
		 */
		if ( $error_count > 0 ) :
			?>

			<div class="ash-bpc-result ash-bpc-result-error">

				<div class="ash-bpc-result-header">

					<?php
					echo esc_html(
						$error_count .
						' error' .
						(
							1 === $error_count
								? ''
								: 's'
						) .
						' occurred.'
					);
					?>

				</div>


				<ul class="ash-bpc-result-list">

					<?php
					foreach (
						$result['errors']
						as $error
					) :
						?>

						<li>
							<?php
							echo esc_html(
								$error
							);
							?>
						</li>

					<?php endforeach; ?>

				</ul>

			</div>

		<?php endif;
	}

	/**
	 * Create pages from text.
	 *
	 * @param string $input Page hierarchy.
	 * @return array
	 */
	private function create_pages_from_text( $input ) {

		$result = array(
			'created'  => array(),
			'existing' => array(),
			'errors'   => array(),
		);


		/*
		 * Normalize line endings.
		 */
		$input = str_replace(
			array(
				"\r\n",
				"\r",
			),
			"\n",
			$input
		);


		/*
		 * Split into lines.
		 */
		$lines = explode(
			"\n",
			$input
		);


		/*
		 * Latest page at every hierarchy level.
		 *
		 * Example:
		 *
		 * [0] Work
		 * [1] Portfolio
		 * [2] WordPress
		 * [3] Elementor
		 */
		$parents = array();


		foreach (
			$lines as $line_number => $raw_line
		) {

			/*
			 * Trim line.
			 */
			$line = trim(
				$raw_line
			);


			/*
			 * Ignore blank lines.
			 */
			if ( '' === $line ) {
				continue;
			}


			/*
			 * Capture leading dashes and title.
			 */
			if (
				! preg_match(
					'/^(-*)(?:\s*)(.*?)\s*$/',
					$line,
					$matches
				)
			) {

				$result['errors'][] =
					'Invalid line ' .
					( $line_number + 1 ) .
					'.';

				continue;
			}


			/*
			 * Number of dashes.
			 */
			$dashes =
				isset( $matches[1] )
					? $matches[1]
					: '';


			$level =
				strlen(
					$dashes
				);


			/*
			 * Page title.
			 */
			$title =
				isset( $matches[2] )
					? trim(
						$matches[2]
					)
					: '';


			/*
			 * Empty title.
			 */
			if ( '' === $title ) {

				$result['errors'][] =
					'Line ' .
					( $line_number + 1 ) .
					' has no page title.';

				continue;
			}


			/*
			 * Child pages require a valid parent
			 * at the immediately previous level.
			 */
			if ( $level > 0 ) {

				if (
					! isset(
						$parents[ $level - 1 ]
					)
				) {

					$result['errors'][] =
						'Line ' .
						( $line_number + 1 ) .
						' ("' .
						$title .
						'") has no valid parent.';

					continue;
				}
			}


			/*
			 * Default parent.
			 */
			$parent_id =
				0;

			$parent_title =
				'';


			/*
			 * Determine parent.
			 */
			if ( $level > 0 ) {

				$parent_id =
					(int)
					$parents[
						$level - 1
					]['id'];

				$parent_title =
					$parents[
						$level - 1
					]['title'];
			}


			/*
			 * Look for existing page.
			 */
			$existing_page =
				get_page_by_title(
					$title,
					OBJECT,
					'page'
				);


			/*
			 * Existing page is reusable only if
			 * its parent is exactly correct.
			 */
			$existing_correct_page =
				false;


			if ( $existing_page ) {

				if (
					(int)
					$existing_page->post_parent ===
					(int)
					$parent_id
				) {

					$existing_correct_page =
						true;
				}
			}


			/*
			 * Reuse existing page.
			 */
			if (
				$existing_correct_page
			) {

				$page_id =
					(int)
					$existing_page->ID;


				$result['existing'][] =
					array(
						'id' =>
							$page_id,

						'title' =>
							$title,

						'parent' =>
							$parent_title,
					);

			} else {

				/*
				 * Create new page.
				 */
				$page_id =
					wp_insert_post(
						array(
							'post_title' =>
								$title,

							'post_content' =>
								'',

							'post_status' =>
								'publish',

							'post_type' =>
								'page',

							'post_parent' =>
								$parent_id,

							'comment_status' =>
								'closed',

							'ping_status' =>
								'closed',
						),
						true
					);


				/*
				 * Handle error.
				 */
				if (
					is_wp_error(
						$page_id
					)
				) {

					$result['errors'][] =
						'Line ' .
						( $line_number + 1 ) .
						' ("' .
						$title .
						'"): ' .
						$page_id->get_error_message();

					continue;
				}


				$result['created'][] =
					array(
						'id' =>
							(int)
							$page_id,

						'title' =>
							$title,

						'parent' =>
							$parent_title,
					);
			}


			/*
			 * Store current page at its level.
			 */
			$parents[ $level ] =
				array(
					'id' =>
						(int)
						$page_id,

					'title' =>
						$title,
				);


			/*
			 * Remove deeper levels.
			 *
			 * Example:
			 *
			 * Work
			 * - Portfolio
			 * -- WordPress
			 * --- Elementor
			 * - Case Studies
			 *
			 * When Case Studies is processed,
			 * levels 2 and 3 are removed.
			 */
			foreach (
				$parents as $stored_level => $stored_page
			) {

				if (
					$stored_level >
					$level
				) {

					unset(
						$parents[
							$stored_level
						]
					);
				}
			}
		}


		return $result;
	}
}


/**
 * Initialize plugin.
 */
new Ash_Bulk_Page_Creation();