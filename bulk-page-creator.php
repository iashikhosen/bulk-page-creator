<?php
/**
 * Plugin Name: Bulk Page Creation
 * Plugin URI: https://ashikhosen.com
 * Description: Create WordPress pages and unlimited nested parent/child page structures from simple text.
 * Version: 4.0.0
 * Author: Ashik Hosen
 * Author URI: https://ashikhosen.com
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ash_Bulk_Page_Creation {

	private $slug = 'ash-bulk-page-creation';

	public function __construct() {

		add_action(
			'admin_menu',
			array( $this, 'admin_menu' )
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			array( $this, 'plugin_action_links' )
		);

		add_action(
			'admin_enqueue_scripts',
			array( $this, 'admin_assets' )
		);

		add_action(
			'wp_ajax_ash_bpc_preflight',
			array( $this, 'ajax_preflight' )
		);

		add_action(
			'wp_ajax_ash_bpc_create_one',
			array( $this, 'ajax_create_one' )
		);
	}

	/**
	 * Plugin settings link.
	 */
	public function plugin_action_links( $links ) {

		$url = admin_url(
			'tools.php?page=' . $this->slug
		);

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">Settings</a>',
				esc_url( $url )
			)
		);

		return $links;
	}

	/**
	 * Admin menu.
	 */
	public function admin_menu() {

		add_management_page(
			'Bulk Page Creation',
			'Bulk Page Creation',
			'manage_options',
			$this->slug,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Admin assets.
	 */
	public function admin_assets( $hook ) {

		if ( 'tools_page_' . $this->slug !== $hook ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		wp_localize_script(
			'jquery',
			'ashBPC',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ash_bpc_nonce' ),
			)
		);
	}

	/**
	 * Parse text structure.
	 */
	private function parse_input( $input ) {

		$input = str_replace(
			array( "\r\n", "\r" ),
			"\n",
			$input
		);

		$lines = explode( "\n", $input );

		$items = array();

		foreach ( $lines as $line_number => $raw_line ) {

			$line = trim( $raw_line );

			if ( '' === $line ) {
				continue;
			}

			if (
				! preg_match(
					'/^(-*)(?:\s*)(.*?)\s*$/',
					$line,
					$matches
				)
			) {
				continue;
			}

			$dashes = isset( $matches[1] )
				? $matches[1]
				: '';

			$title = isset( $matches[2] )
				? trim( $matches[2] )
				: '';

			if ( '' === $title ) {
				continue;
			}

			$items[] = array(
				'number' => $line_number + 1,
				'level'  => strlen( $dashes ),
				'title'  => $title,
			);
		}

		return $items;
	}

	/**
	 * Validate hierarchy.
	 */
	private function validate_items( $items ) {

		$errors  = array();
		$parents = array();

		foreach ( $items as $index => $item ) {

			$level = $item['level'];

			if (
				$level > 0 &&
				! isset( $parents[ $level - 1 ] )
			) {

				$errors[] = sprintf(
					'Line %d ("%s") has no valid parent.',
					$item['number'],
					$item['title']
				);

				continue;
			}

			$parents[ $level ] = $index;

			foreach ( $parents as $stored_level => $stored_index ) {

				if ( $stored_level > $level ) {
					unset(
						$parents[ $stored_level ]
					);
				}
			}
		}

		return $errors;
	}

	/**
	 * Find existing page.
	 */
	private function find_existing_page( $title ) {

		$pages = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => array(
					'publish',
					'draft',
					'pending',
					'private',
					'future',
				),
				'title'                  => $title,
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( empty( $pages ) ) {
			return false;
		}

		return $pages[0];
	}

	/**
	 * Get all existing pages.
	 */
	private function get_existing_items( $items ) {

		$existing = array();

		foreach ( $items as $index => $item ) {

			$page = $this->find_existing_page(
				$item['title']
			);

			if ( $page ) {

				$existing[] = array(
					'index'    => $index,
					'title'    => $item['title'],
					'level'    => $item['level'],
					'id'       => (int) $page->ID,
					'parentId' => (int) $page->post_parent,
					'status'   => $page->post_status,
					'url'      => get_permalink( $page->ID ),
				);
			}
		}

		return $existing;
	}

	/**
	 * Preflight.
	 */
	public function ajax_preflight() {

		if ( ! current_user_can( 'manage_options' ) ) {

			wp_send_json_error(
				array(
					'message' => 'You do not have permission to create pages.',
				),
				403
			);
		}

		check_ajax_referer(
			'ash_bpc_nonce',
			'nonce'
		);

		$input = isset( $_POST['content'] )
			? wp_unslash( $_POST['content'] )
			: '';

		$items = $this->parse_input( $input );

		if ( empty( $items ) ) {

			wp_send_json_error(
				array(
					'message' => 'Please enter at least one page.',
				)
			);
		}

		$errors = $this->validate_items( $items );

		if ( ! empty( $errors ) ) {

			wp_send_json_error(
				array(
					'message' => implode( "\n", $errors ),
				)
			);
		}

		$existing =
			$this->get_existing_items(
				$items
			);

		wp_send_json_success(
			array(
				'total'    => count( $items ),
				'items'    => $items,
				'existing' => $existing,
			)
		);
	}

	/**
	 * Create exactly ONE page.
	 *
	 * The browser calls this once for every page.
	 * This is what makes the progressive loader real.
	 */
	public function ajax_create_one() {

		if ( ! current_user_can( 'manage_options' ) ) {

			wp_send_json_error(
				array(
					'message' => 'You do not have permission to create pages.',
				),
				403
			);
		}

		check_ajax_referer(
			'ash_bpc_nonce',
			'nonce'
		);

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 );
		}

		$title = isset( $_POST['title'] )
			? sanitize_text_field(
				wp_unslash(
					$_POST['title']
				)
			)
			: '';

		$parent_id = isset( $_POST['parent_id'] )
			? absint(
				$_POST['parent_id']
			)
			: 0;

		$conflict_action = isset(
			$_POST['conflict_action']
		)
			? sanitize_key(
				wp_unslash(
					$_POST['conflict_action']
				)
			)
			: 'skip';

		if ( '' === $title ) {

			wp_send_json_error(
				array(
					'message' => 'Page title is empty.',
				)
			);
		}

		if (
			! in_array(
				$conflict_action,
				array(
					'replace',
					'skip',
					'duplicate',
				),
				true
			)
		) {

			$conflict_action = 'skip';
		}

		$existing =
			$this->find_existing_page(
				$title
			);

		/*
		 * Existing page.
		 */
		if ( $existing ) {

			/*
			 * Replace.
			 */
			if ( 'replace' === $conflict_action ) {

				$updated = wp_update_post(
					wp_slash(
						array(
							'ID'          => $existing->ID,
							'post_title'  => $title,
							'post_parent' => $parent_id,
							'post_status' => 'publish',
							'post_type'   => 'page',
						)
					),
					true
				);

				if ( is_wp_error( $updated ) ) {

					wp_send_json_error(
						array(
							'message' =>
								$updated->get_error_message(),
						)
					);
				}

				wp_send_json_success(
					array(
						'action'     => 'replaced',
						'id'         => (int) $existing->ID,
						'title'      => get_the_title(
							$existing->ID
						),
						'parent_id'  => $parent_id,
						'url'        => get_permalink(
							$existing->ID
						),
						'message'    => 'Page replaced successfully.',
					)
				);
			}

			/*
			 * Skip.
			 *
			 * IMPORTANT:
			 * The existing page ID is returned so children can
			 * still correctly attach to this page.
			 */
			if ( 'skip' === $conflict_action ) {

				wp_send_json_success(
					array(
						'action'     => 'skipped',
						'id'         => (int) $existing->ID,
						'title'      => get_the_title(
							$existing->ID
						),
						'parent_id'  => $parent_id,
						'url'        => get_permalink(
							$existing->ID
						),
						'message'    => 'Existing page skipped.',
					)
				);
			}

			/*
			 * Duplicate.
			 *
			 * WordPress automatically generates a unique slug.
			 */
			if ( 'duplicate' === $conflict_action ) {

				$new_page = wp_insert_post(
					wp_slash(
						array(
							'post_title'   => $title,
							'post_content' => '',
							'post_status'  => 'publish',
							'post_type'    => 'page',
							'post_parent'  => $parent_id,
						)
					),
					true
				);

				if ( is_wp_error( $new_page ) ) {

					wp_send_json_error(
						array(
							'message' =>
								$new_page->get_error_message(),
						)
					);
				}

				wp_send_json_success(
					array(
						'action'     => 'duplicated',
						'id'         => (int) $new_page,
						'title'      => get_the_title(
							$new_page
						),
						'parent_id'  => $parent_id,
						'url'        => get_permalink(
							$new_page
						),
						'message'    => 'Page duplicated successfully.',
					)
				);
			}
		}

		/*
		 * New page.
		 */
		$new_page = wp_insert_post(
			wp_slash(
				array(
					'post_title'   => $title,
					'post_content' => '',
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_parent'  => $parent_id,
				)
			),
			true
		);

		if ( is_wp_error( $new_page ) ) {

			wp_send_json_error(
				array(
					'message' =>
						$new_page->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'action'     => 'created',
				'id'         => (int) $new_page,
				'title'      => get_the_title(
					$new_page
				),
				'parent_id'  => $parent_id,
				'url'        => get_permalink(
					$new_page
				),
				'message'    => 'Page created successfully.',
			)
		);
	}

	/**
	 * Admin page.
	 */
	public function render_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>

		<div class="ash-bpc-wrap">

			<div class="ash-bpc-container">

				<div class="ash-bpc-header">

					<div class="ash-bpc-brand">

						<div class="ash-bpc-brand-icon">
							<span class="dashicons dashicons-admin-page"></span>
						</div>

						<div>

							<h1>Bulk Page Creation</h1>

							<p>
								Create WordPress pages from a simple
								nested text structure.
							</p>

						</div>

					</div>

				</div>


				<div class="ash-bpc-card">

					<div class="ash-bpc-card-header">

						<div>

							<h2>Page Structure</h2>

							<p>
								Enter one page per line and use dashes
								to define parent and child relationships.
							</p>

						</div>

						<div class="ash-bpc-format-label">

							<span class="dashicons dashicons-editor-code"></span>

							Text Format

						</div>

					</div>


					<div class="ash-bpc-editor">

						<div class="ash-bpc-editor-top">

							<span>Page hierarchy</span>

							<span class="ash-bpc-counter">

								<span id="ash-bpc-line-count">0</span>

								pages

							</span>

						</div>


						<textarea
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
						></textarea>

					</div>


					<div class="ash-bpc-help">

						<div class="ash-bpc-help-header">

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

								<span>Copy Prompt</span>

							</button>

						</div>


						<div class="ash-bpc-examples">

							<div class="ash-bpc-example">
								<code>Work</code>
								<span>Top-level page</span>
							</div>

							<div class="ash-bpc-example">
								<code>- Portfolio</code>
								<span>Child of Work</span>
							</div>

							<div class="ash-bpc-example">
								<code>-- WordPress</code>
								<span>Child of Portfolio</span>
							</div>

							<div class="ash-bpc-example">
								<code>--- Elementor</code>
								<span>Child of WordPress</span>
							</div>

						</div>


						<div class="ash-bpc-help-note">

							You can continue nesting with as many
							dashes as needed.

						</div>

					</div>


					<div class="ash-bpc-actions">

						<button
							type="button"
							class="ash-bpc-button ash-bpc-preview-button"
							id="ash-bpc-preview"
						>

							<span class="dashicons dashicons-visibility"></span>

							Preview

						</button>


						<button
							type="button"
							class="ash-bpc-button ash-bpc-create-button"
							id="ash-bpc-create"
						>

							<span class="dashicons dashicons-plus-alt2"></span>

							Create Pages

						</button>

					</div>

				</div>


				<!-- Preview -->

				<div
					class="ash-bpc-card ash-bpc-preview-card"
					id="ash-bpc-preview-card"
					style="display:none;"
				>

					<div class="ash-bpc-card-header">

						<div>

							<h2>Preview</h2>

							<p>
								This is the page structure that will
								be created.
							</p>

						</div>

						<button
							type="button"
							class="ash-bpc-close"
							id="ash-bpc-close-preview"
						>

							<span class="dashicons dashicons-no-alt"></span>

						</button>

					</div>


					<div
						class="ash-bpc-tree"
						id="ash-bpc-tree"
					></div>

				</div>


				<!-- Result -->

				<div
					class="ash-bpc-card ash-bpc-result-card"
					id="ash-bpc-result-card"
					style="display:none;"
				>

					<div class="ash-bpc-card-header">

						<div>

							<h2>Creation Complete</h2>

							<p id="ash-bpc-result-summary"></p>

						</div>

					</div>


					<div
						class="ash-bpc-results"
						id="ash-bpc-results"
					></div>

				</div>


				<div class="ash-bpc-footer">

					<span>Bulk Page Creation</span>

					<span>WordPress native Pages</span>

				</div>

			</div>


			<!-- Existing pages modal -->

			<div
				class="ash-bpc-modal-overlay"
				id="ash-bpc-conflict-modal"
				style="display:none;"
			>

				<div class="ash-bpc-modal">

					<div class="ash-bpc-modal-header">

						<div>

							<div class="ash-bpc-modal-icon">
								<span class="dashicons dashicons-warning"></span>
							</div>

							<h2>Existing Pages Found</h2>

							<p>
								Some pages already exist. Choose what
								should happen to them.
							</p>

						</div>

						<button
							type="button"
							class="ash-bpc-modal-close"
							id="ash-bpc-modal-close"
						>

							<span class="dashicons dashicons-no-alt"></span>

						</button>

					</div>


					<div class="ash-bpc-existing-count">

						<span id="ash-bpc-existing-number">0</span>

						existing pages found

					</div>


					<div
						class="ash-bpc-existing-list"
						id="ash-bpc-existing-list"
					></div>


					<div class="ash-bpc-modal-actions">

						<button
							type="button"
							class="ash-bpc-conflict-button ash-bpc-replace"
							data-action="replace"
						>

							<span class="dashicons dashicons-update"></span>

							Replace

						</button>


						<button
							type="button"
							class="ash-bpc-conflict-button ash-bpc-skip"
							data-action="skip"
						>

							<span class="dashicons dashicons-controls-skipforward"></span>

							Skip

						</button>


						<button
							type="button"
							class="ash-bpc-conflict-button ash-bpc-duplicate"
							data-action="duplicate"
						>

							<span class="dashicons dashicons-admin-page"></span>

							Duplicate

						</button>

					</div>

				</div>

			</div>


			<!-- Progressive loader -->

			<div
				class="ash-bpc-progress-overlay"
				id="ash-bpc-progress-overlay"
				style="display:none;"
			>

				<div class="ash-bpc-progress-box">

					<div class="ash-bpc-progress-header">

						<div class="ash-bpc-progress-main-icon">
							<span class="dashicons dashicons-admin-page"></span>
						</div>

						<div>

							<h2 id="ash-bpc-progress-title">
								Creating Pages
							</h2>

							<p id="ash-bpc-progress-subtitle">
								Please wait while your pages are created.
							</p>

						</div>

					</div>


					<div class="ash-bpc-progress-steps">

						<div
							class="ash-bpc-progress-step"
							id="ash-bpc-step-starting"
						>

							<div class="ash-bpc-step-marker">
								<span class="dashicons dashicons-minus"></span>
							</div>

							<div class="ash-bpc-step-content">

								<strong>Starting</strong>

								<span>
									Preparing your page structure
								</span>

							</div>

						</div>


						<div
							class="ash-bpc-progress-step"
							id="ash-bpc-step-checking"
						>

							<div class="ash-bpc-step-marker">
								<span class="dashicons dashicons-minus"></span>
							</div>

							<div class="ash-bpc-step-content">

								<strong>Checking Pages</strong>

								<span>
									Checking existing pages
								</span>

							</div>

						</div>


						<div
							class="ash-bpc-progress-step"
							id="ash-bpc-step-creating"
						>

							<div class="ash-bpc-step-marker">
								<span class="dashicons dashicons-minus"></span>
							</div>

							<div class="ash-bpc-step-content">

								<strong>Creating Pages</strong>

								<span
									id="ash-bpc-current-page"
								>
									Waiting...
								</span>

							</div>

						</div>


						<div
							class="ash-bpc-progress-pages"
							id="ash-bpc-progress-pages"
						></div>


						<div
							class="ash-bpc-progress-step"
							id="ash-bpc-step-completed"
						>

							<div class="ash-bpc-step-marker">
								<span class="dashicons dashicons-minus"></span>
							</div>

							<div class="ash-bpc-step-content">

								<strong>Completed</strong>

								<span
									id="ash-bpc-completed-text"
								>
									Waiting for creation to finish
								</span>

							</div>

						</div>

					</div>


					<div class="ash-bpc-progress-bar-wrap">

						<div class="ash-bpc-progress-bar">

							<div
								class="ash-bpc-progress-bar-fill"
								id="ash-bpc-progress-bar-fill"
							></div>

						</div>


						<div class="ash-bpc-progress-counter">

							<span id="ash-bpc-progress-number">
								0
							</span>

							/

							<span id="ash-bpc-progress-total">
								0
							</span>

							pages

						</div>

					</div>

				</div>

			</div>

		</div>


		<style>

			/* =====================================================
			 * BASE
			 * ===================================================== */

			.ash-bpc-wrap {
				margin: 0 20px 0 0;
				color: #1d2327;
			}

			.ash-bpc-wrap *,
			.ash-bpc-wrap *::before,
			.ash-bpc-wrap *::after {
				box-sizing: border-box;
			}

			.ash-bpc-container {
				max-width: 1120px;
				margin: 32px auto;
			}


			/* =====================================================
			 * HEADER
			 * ===================================================== */

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
				box-shadow: 0 5px 16px rgba(34,113,177,.18);
			}

			.ash-bpc-brand-icon .dashicons {
				width: 25px;
				height: 25px;
				font-size: 25px;
			}

			.ash-bpc-brand h1 {
				margin: 0 0 4px;
				font-size: 25px;
				line-height: 1.25;
				font-weight: 600;
			}

			.ash-bpc-brand p {
				margin: 0;
				color: #646970;
				font-size: 14px;
			}


			/* =====================================================
			 * CARD
			 * ===================================================== */

			.ash-bpc-card {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 10px;
				box-shadow: 0 2px 8px rgba(0,0,0,.04);
				margin-bottom: 20px;
				overflow: hidden;
			}

			.ash-bpc-card-header {
				padding: 22px 24px;
				border-bottom: 1px solid #f0f0f1;
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 20px;
			}

			.ash-bpc-card-header h2 {
				margin: 0 0 5px;
				font-size: 17px;
				font-weight: 600;
			}

			.ash-bpc-card-header p {
				margin: 0;
				font-size: 13px;
				color: #646970;
			}

			.ash-bpc-format-label {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				padding: 6px 10px;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				background: #f6f7f7;
				color: #50575e;
				font-size: 12px;
				white-space: nowrap;
			}

			.ash-bpc-format-label .dashicons {
				width: 15px;
				height: 15px;
				font-size: 15px;
			}


			/* =====================================================
			 * EDITOR
			 * ===================================================== */

			.ash-bpc-editor {
				margin: 24px;
				border: 1px solid #c3c4c7;
				border-radius: 8px;
				overflow: hidden;
			}

			.ash-bpc-editor-top {
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

			.ash-bpc-counter {
				font-weight: 400;
				color: #8c8f94;
			}

			#ash-bpc-content {
				display: block;
				width: 100%;
				min-height: 390px;
				padding: 18px;
				border: 0;
				outline: 0;
				box-shadow: none;
				resize: vertical;
				font-family: Consolas, Monaco, monospace;
				font-size: 14px;
				line-height: 1.8;
				color: #1d2327;
				background: #fff;
			}

			#ash-bpc-content:focus {
				box-shadow: inset 0 0 0 1px #2271b1;
			}


			/* =====================================================
			 * HELP
			 * ===================================================== */

			.ash-bpc-help {
				margin: 0 24px 24px;
				padding: 18px;
				border: 1px solid #e2e4e7;
				border-radius: 8px;
				background: #f9f9f9;
			}

			.ash-bpc-help-header {
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
			}

			.ash-bpc-help-title .dashicons {
				color: #2271b1;
			}

			.ash-bpc-copy-prompt {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				height: 30px;
				padding: 0 10px;
				border: 1px solid #c3c4c7;
				border-radius: 5px;
				background: #fff;
				color: #50575e;
				font-size: 12px;
				cursor: pointer;
			}

			.ash-bpc-copy-prompt:hover {
				background: #f6f7f7;
			}

			.ash-bpc-copy-prompt.is-copied {
				border-color: #46b450;
				color: #28752c;
				background: #f0f8f0;
			}

			.ash-bpc-examples {
				display: grid;
				grid-template-columns: repeat(2,1fr);
				gap: 8px;
			}

			.ash-bpc-example {
				display: flex;
				align-items: center;
				gap: 10px;
			}

			.ash-bpc-example code {
				min-width: 130px;
				padding: 6px 8px;
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 5px;
				font-size: 12px;
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


			/* =====================================================
			 * BUTTONS
			 * ===================================================== */

			.ash-bpc-actions {
				display: flex;
				justify-content: flex-end;
				gap: 10px;
				padding: 18px 24px;
				background: #fafafa;
				border-top: 1px solid #f0f0f1;
			}

			.ash-bpc-button {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				gap: 7px;
				min-height: 38px;
				padding: 0 16px;
				border-radius: 6px;
				font-size: 13px;
				font-weight: 500;
				cursor: pointer;
			}

			.ash-bpc-preview-button {
				background: #fff;
				border: 1px solid #c3c4c7;
				color: #2c3338;
			}

			.ash-bpc-create-button {
				background: #2271b1;
				border: 1px solid #2271b1;
				color: #fff;
			}

			.ash-bpc-create-button:hover {
				background: #135e96;
			}


			/* =====================================================
			 * TREE
			 * ===================================================== */

			.ash-bpc-tree {
				padding: 24px;
			}

			.ash-bpc-tree-row {
				display: flex;
				align-items: center;
				min-height: 43px;
				border-bottom: 1px solid #f0f0f1;
				font-size: 13px;
			}

			.ash-bpc-tree-indent {
				display: flex;
				align-self: stretch;
			}

			.ash-bpc-tree-branch {
				width: 20px;
				border-left: 1px solid #dcdcde;
				position: relative;
			}

			.ash-bpc-tree-branch::after {
				content: "";
				position: absolute;
				left: 0;
				top: 21px;
				width: 13px;
				border-top: 1px solid #dcdcde;
			}

			.ash-bpc-tree-icon {
				width: 30px;
				height: 30px;
				margin-right: 10px;
				flex: 0 0 30px;
				display: flex;
				align-items: center;
				justify-content: center;
				border-radius: 6px;
				background: #f0f6fc;
				color: #2271b1;
			}

			.ash-bpc-tree-title {
				font-weight: 500;
			}

			.ash-bpc-tree-level {
				margin-left: auto;
				padding: 3px 7px;
				border-radius: 4px;
				background: #f6f7f7;
				color: #8c8f94;
				font-size: 11px;
			}


			/* =====================================================
			 * EXISTING PAGE MODAL
			 * ===================================================== */

			.ash-bpc-modal-overlay {
				position: fixed;
				inset: 0;
				z-index: 999999;
				background: rgba(0,0,0,.45);
				display: flex;
				align-items: center;
				justify-content: center;
				padding: 20px;
			}

			.ash-bpc-modal {
				width: 100%;
				max-width: 620px;
				background: #fff;
				border-radius: 12px;
				box-shadow: 0 18px 60px rgba(0,0,0,.22);
				overflow: hidden;
			}

			.ash-bpc-modal-header {
				display: flex;
				justify-content: space-between;
				padding: 22px 24px;
				border-bottom: 1px solid #f0f0f1;
			}

			.ash-bpc-modal-header > div:first-child {
				position: relative;
				padding-left: 48px;
			}

			.ash-bpc-modal-icon {
				position: absolute;
				left: 0;
				top: 0;
				width: 36px;
				height: 36px;
				border-radius: 8px;
				background: #fff8e5;
				color: #9b6a00;
				display: flex;
				align-items: center;
				justify-content: center;
			}

			.ash-bpc-modal h2 {
				margin: 0 0 4px;
				font-size: 17px;
			}

			.ash-bpc-modal p {
				margin: 0;
				color: #646970;
				font-size: 12px;
			}

			.ash-bpc-modal-close {
				width: 32px;
				height: 32px;
				border: 1px solid #dcdcde;
				background: #fff;
				border-radius: 6px;
				cursor: pointer;
			}

			.ash-bpc-existing-count {
				padding: 14px 24px;
				background: #f6f7f7;
				font-size: 12px;
			}

			.ash-bpc-existing-count span {
				font-weight: 700;
				color: #2271b1;
			}

			.ash-bpc-existing-list {
				max-height: 280px;
				overflow-y: auto;
				padding: 10px 24px;
			}

			.ash-bpc-existing-item {
				display: flex;
				align-items: center;
				gap: 10px;
				padding: 9px 0;
				border-bottom: 1px solid #f0f0f1;
				font-size: 12px;
			}

			.ash-bpc-existing-item-icon {
				width: 27px;
				height: 27px;
				border-radius: 5px;
				background: #f0f6fc;
				color: #2271b1;
				display: flex;
				align-items: center;
				justify-content: center;
			}

			.ash-bpc-existing-title {
				font-weight: 500;
			}

			.ash-bpc-existing-url {
				margin-left: auto;
				color: #8c8f94;
				font-size: 11px;
			}

			.ash-bpc-modal-actions {
				display: grid;
				grid-template-columns: repeat(3,1fr);
				gap: 8px;
				padding: 18px 24px;
				background: #fafafa;
				border-top: 1px solid #f0f0f1;
			}

			.ash-bpc-conflict-button {
				min-height: 42px;
				border-radius: 6px;
				background: #fff;
				cursor: pointer;
				font-size: 13px;
				display: flex;
				align-items: center;
				justify-content: center;
				gap: 6px;
			}

			.ash-bpc-replace {
				border: 1px solid #2271b1;
				color: #2271b1;
			}

			.ash-bpc-skip {
				border: 1px solid #c3c4c7;
				color: #50575e;
			}

			.ash-bpc-duplicate {
				border: 1px solid #8c8f94;
				color: #2c3338;
			}


			/* =====================================================
			 * PROGRESSIVE CREATION OVERLAY
			 * ===================================================== */

			.ash-bpc-progress-overlay {
				position: fixed;
				inset: 0;
				z-index: 1000000;
				background: rgba(255,255,255,.94);
				display: flex;
				align-items: center;
				justify-content: center;
				padding: 20px;
			}

			.ash-bpc-progress-box {
				width: 100%;
				max-width: 560px;
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 12px;
				box-shadow: 0 18px 60px rgba(0,0,0,.13);
				overflow: hidden;
			}

			.ash-bpc-progress-header {
				display: flex;
				align-items: center;
				gap: 14px;
				padding: 22px 24px;
				border-bottom: 1px solid #f0f0f1;
			}

			.ash-bpc-progress-main-icon {
				width: 44px;
				height: 44px;
				flex: 0 0 44px;
				display: flex;
				align-items: center;
				justify-content: center;
				border-radius: 9px;
				background: #f0f6fc;
				color: #2271b1;
			}

			.ash-bpc-progress-main-icon .dashicons {
				font-size: 21px;
				width: 21px;
				height: 21px;
			}

			.ash-bpc-progress-header h2 {
				margin: 0 0 4px;
				font-size: 17px;
				font-weight: 600;
			}

			.ash-bpc-progress-header p {
				margin: 0;
				font-size: 12px;
				color: #646970;
			}

			.ash-bpc-progress-steps {
				padding: 18px 24px 12px;
			}

			.ash-bpc-progress-step {
				position: relative;
				display: flex;
				align-items: center;
				gap: 12px;
				min-height: 48px;
			}

			.ash-bpc-step-marker {
				width: 28px;
				height: 28px;
				flex: 0 0 28px;
				border: 1px solid #dcdcde;
				border-radius: 50%;
				display: flex;
				align-items: center;
				justify-content: center;
				background: #fff;
				color: #a7aaad;
				transition: .2s ease;
			}

			.ash-bpc-step-marker .dashicons {
				width: 14px;
				height: 14px;
				font-size: 14px;
			}

			.ash-bpc-step-content {
				display: flex;
				flex-direction: column;
				gap: 2px;
				min-width: 0;
			}

			.ash-bpc-step-content strong {
				font-size: 13px;
				font-weight: 600;
			}

			.ash-bpc-step-content span {
				font-size: 11px;
				color: #8c8f94;
			}

			/* Completed */

			.ash-bpc-progress-step.is-complete
			.ash-bpc-step-marker {
				background: #46b450;
				border-color: #46b450;
				color: #fff;
			}

			.ash-bpc-progress-step.is-complete
			.ash-bpc-step-content strong {
				color: #28752c;
			}

			/* Active */

			.ash-bpc-progress-step.is-active
			.ash-bpc-step-marker {
				border-color: #2271b1;
				color: #2271b1;
				box-shadow: 0 0 0 4px rgba(34,113,177,.09);
			}

			.ash-bpc-progress-step.is-active
			.ash-bpc-step-content strong {
				color: #2271b1;
			}

			.ash-bpc-progress-step.is-active
			.ash-bpc-step-marker::after {
				content: "";
				width: 8px;
				height: 8px;
				border: 2px solid #2271b1;
				border-top-color: transparent;
				border-radius: 50%;
				animation: ashBpcProgressSpin .7s linear infinite;
			}

			@keyframes ashBpcProgressSpin {
				to {
					transform: rotate(360deg);
				}
			}


			/* =====================================================
			 * INDIVIDUAL PAGE PROGRESS ITEMS
			 * ===================================================== */

			.ash-bpc-progress-pages {
				margin: 4px 0 8px 40px;
				max-height: 235px;
				overflow-y: auto;
				border-left: 1px solid #e2e4e7;
				padding-left: 16px;
			}

			.ash-bpc-progress-page {
				display: flex;
				align-items: center;
				gap: 9px;
				min-height: 32px;
				font-size: 12px;
				color: #646970;
				transition: .15s ease;
			}

			.ash-bpc-page-marker {
				width: 19px;
				height: 19px;
				flex: 0 0 19px;
				border: 1px solid #dcdcde;
				border-radius: 50%;
				display: flex;
				align-items: center;
				justify-content: center;
				background: #fff;
				color: transparent;
			}

			.ash-bpc-page-marker .dashicons {
				width: 12px;
				height: 12px;
				font-size: 12px;
			}

			.ash-bpc-progress-page.is-active {
				color: #2271b1;
				font-weight: 500;
			}

			.ash-bpc-progress-page.is-active
			.ash-bpc-page-marker {
				border-color: #2271b1;
				color: #2271b1;
				box-shadow: 0 0 0 3px rgba(34,113,177,.07);
			}

			.ash-bpc-progress-page.is-active
			.ash-bpc-page-marker::after {
				content: "";
				width: 6px;
				height: 6px;
				border: 1.5px solid #2271b1;
				border-top-color: transparent;
				border-radius: 50%;
				animation: ashBpcProgressSpin .7s linear infinite;
			}

			.ash-bpc-progress-page.is-complete {
				color: #28752c;
			}

			.ash-bpc-progress-page.is-complete
			.ash-bpc-page-marker {
				background: #46b450;
				border-color: #46b450;
				color: #fff;
			}

			.ash-bpc-progress-page.is-skipped {
				color: #646970;
			}

			.ash-bpc-progress-page.is-skipped
			.ash-bpc-page-marker {
				background: #eef6fc;
				border-color: #72aee6;
				color: #2271b1;
			}

			.ash-bpc-progress-page.is-replaced {
				color: #28752c;
			}

			.ash-bpc-progress-page.is-replaced
			.ash-bpc-page-marker {
				background: #edfaef;
				border-color: #46b450;
				color: #46b450;
			}

			.ash-bpc-progress-page.is-duplicated {
				color: #6d5515;
			}

			.ash-bpc-progress-page.is-duplicated
			.ash-bpc-page-marker {
				background: #fcf8ec;
				border-color: #c8a64b;
				color: #9b6a00;
			}


			/* =====================================================
			 * PROGRESS BAR
			 * ===================================================== */

			.ash-bpc-progress-bar-wrap {
				padding: 18px 24px 22px;
				border-top: 1px solid #f0f0f1;
				background: #fafafa;
			}

			.ash-bpc-progress-bar {
				width: 100%;
				height: 5px;
				background: #e2e4e7;
				border-radius: 5px;
				overflow: hidden;
			}

			.ash-bpc-progress-bar-fill {
				height: 100%;
				width: 0;
				background: #2271b1;
				border-radius: 5px;
				transition: width .25s ease;
			}

			.ash-bpc-progress-counter {
				margin-top: 8px;
				text-align: right;
				font-size: 11px;
				color: #646970;
			}

			.ash-bpc-progress-counter span {
				font-weight: 600;
				color: #2c3338;
			}


			/* =====================================================
			 * RESULTS
			 * ===================================================== */

			.ash-bpc-results {
				padding: 24px;
			}

			.ash-bpc-result-section {
				margin-bottom: 15px;
				border: 1px solid #dcdcde;
				border-radius: 8px;
				overflow: hidden;
			}

			.ash-bpc-result-section-header {
				padding: 12px 14px;
				background: #f6f7f7;
				font-size: 13px;
				font-weight: 600;
			}

			.ash-bpc-result-list {
				margin: 0;
				padding: 0;
				list-style: none;
			}

			.ash-bpc-result-list li {
				padding: 9px 14px;
				border-top: 1px solid #f0f0f1;
				font-size: 12px;
			}

			.ash-bpc-result-created {
				border-color: #c6e1c6;
			}

			.ash-bpc-result-created
			.ash-bpc-result-section-header {
				background: #edfaef;
				color: #1e4620;
			}

			.ash-bpc-result-replaced {
				border-color: #c6e1c6;
			}

			.ash-bpc-result-replaced
			.ash-bpc-result-section-header {
				background: #edfaef;
				color: #1e4620;
			}

			.ash-bpc-result-skipped {
				border-color: #c5d9ed;
			}

			.ash-bpc-result-skipped
			.ash-bpc-result-section-header {
				background: #eef6fc;
				color: #164b72;
			}

			.ash-bpc-result-duplicated {
				border-color: #e0d2ad;
			}

			.ash-bpc-result-duplicated
			.ash-bpc-result-section-header {
				background: #fcf8ec;
				color: #6d5515;
			}

			.ash-bpc-result-failed {
				border-color: #e5c7c7;
			}

			.ash-bpc-result-failed
			.ash-bpc-result-section-header {
				background: #fcf0f0;
				color: #8a2424;
			}


			/* =====================================================
			 * FOOTER
			 * ===================================================== */

			.ash-bpc-footer {
				display: flex;
				justify-content: space-between;
				padding: 4px 2px;
				font-size: 11px;
				color: #8c8f94;
			}


			/* =====================================================
			 * RESPONSIVE
			 * ===================================================== */

			@media (max-width: 782px) {

				.ash-bpc-wrap {
					margin-right: 10px;
				}

				.ash-bpc-container {
					margin: 20px 10px;
				}

				.ash-bpc-card-header {
					align-items: flex-start;
				}

				.ash-bpc-examples {
					grid-template-columns: 1fr;
				}

				.ash-bpc-actions {
					flex-direction: column-reverse;
					align-items: stretch;
				}

				.ash-bpc-button {
					width: 100%;
				}

				.ash-bpc-modal-actions {
					grid-template-columns: 1fr;
				}

				.ash-bpc-progress-box {
					max-height: calc(100vh - 40px);
					overflow-y: auto;
				}

				.ash-bpc-progress-pages {
					max-height: 180px;
				}

				.ash-bpc-footer {
					flex-direction: column;
					gap: 5px;
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

					const previewCard =
						document.getElementById(
							'ash-bpc-preview-card'
						);

					const closePreview =
						document.getElementById(
							'ash-bpc-close-preview'
						);

					const tree =
						document.getElementById(
							'ash-bpc-tree'
						);

					const createButton =
						document.getElementById(
							'ash-bpc-create'
						);

					const resultCard =
						document.getElementById(
							'ash-bpc-result-card'
						);

					const resultSummary =
						document.getElementById(
							'ash-bpc-result-summary'
						);

					const results =
						document.getElementById(
							'ash-bpc-results'
						);

					const conflictModal =
						document.getElementById(
							'ash-bpc-conflict-modal'
						);

					const modalClose =
						document.getElementById(
							'ash-bpc-modal-close'
						);

					const existingList =
						document.getElementById(
							'ash-bpc-existing-list'
						);

					const existingNumber =
						document.getElementById(
							'ash-bpc-existing-number'
						);

					const progressOverlay =
						document.getElementById(
							'ash-bpc-progress-overlay'
						);

					const progressPages =
						document.getElementById(
							'ash-bpc-progress-pages'
						);

					const currentPage =
						document.getElementById(
							'ash-bpc-current-page'
						);

					const progressTitle =
						document.getElementById(
							'ash-bpc-progress-title'
						);

					const progressSubtitle =
						document.getElementById(
							'ash-bpc-progress-subtitle'
						);

					const progressNumber =
						document.getElementById(
							'ash-bpc-progress-number'
						);

					const progressTotal =
						document.getElementById(
							'ash-bpc-progress-total'
						);

					const progressBar =
						document.getElementById(
							'ash-bpc-progress-bar-fill'
						);

					const completedText =
						document.getElementById(
							'ash-bpc-completed-text'
						);

					const copyPrompt =
						document.getElementById(
							'ash-bpc-copy-prompt'
						);


					let pendingContent = '';

					let pendingItems = [];

					let pendingExisting = [];

					let selectedConflictAction = 'skip';

					let creationResults = {
						created: [],
						replaced: [],
						skipped: [],
						duplicated: [],
						failed: []
					};


					/*
					 * Prompt.
					 */
					const promptText =
`Convert the requirements I provide into a WordPress page hierarchy.

Return ONLY the final page hierarchy in plain text.

Rules:

1. A page with no dash is a top-level page.
2. A page beginning with one dash (-) is a child of the nearest previous top-level page.
3. A page beginning with two dashes (--) is a child of the nearest previous one-dash page.
4. A page beginning with three dashes (---) is a child of the nearest previous two-dash page.
5. Continue the same pattern for unlimited nesting.
6. Preserve the logical parent/child relationships.
7. Preserve the exact order of the pages.
8. Every non-empty line must represent exactly one page.
9. Do not add numbering.
10. Do not add bullet points other than the required dashes.
11. Do not add explanations.
12. Do not add headings.
13. Do not use Markdown code fences.
14. Return only the final page hierarchy.

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

Now convert the requirements into this exact format.`;


					/*
					 * Escape HTML.
					 */
					function escapeHtml(
						value
					) {

						const div =
							document.createElement(
								'div'
							);

						div.textContent =
							value;

						return div.innerHTML;
					}


					/*
					 * Parse hierarchy.
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
								.split(
									'\n'
								);

						const items = [];

						lines.forEach(
							function(
								rawLine,
								lineIndex
							) {

								const line =
									rawLine.trim();

								if ( ! line ) {
									return;
								}

								const match =
									line.match(
										/^(-*)(?:\s*)(.*?)\s*$/
									);

								if ( ! match ) {
									return;
								}

								const title =
									(
										match[2] || ''
									).trim();

								if ( ! title ) {
									return;
								}

								items.push(
									{
										number:
											lineIndex + 1,
										level:
											(
												match[1] || ''
											).length,
										title:
											title
									}
								);
							}
						);

						return items;
					}


					/*
					 * Counter.
					 */
					function updateCounter() {

						lineCount.textContent =
							parseStructure().length;
					}


					/*
					 * AJAX helper.
					 */
					function ajaxRequest(
						action,
						data
					) {

						const body =
							new URLSearchParams();

						body.append(
							'action',
							action
						);

						body.append(
							'nonce',
							ashBPC.nonce
						);

						Object.keys(
							data
						).forEach(
							function( key ) {

								body.append(
									key,
									data[key]
								);

							}
						);

						return fetch(
							ashBPC.ajaxUrl,
							{
								method: 'POST',
								headers: {
									'Content-Type':
										'application/x-www-form-urlencoded; charset=UTF-8'
								},
								body:
									body.toString()
							}
						).then(
							function( response ) {

								return response.json();

							}
						);
					}


					/*
					 * Preview tree.
					 */
					function renderTree() {

						const items =
							parseStructure();

						tree.innerHTML = '';

						if ( ! items.length ) {

							tree.innerHTML =
								'<div style="padding:30px;text-align:center;color:#646970;">Enter your page structure first.</div>';

							previewCard.style.display =
								'block';

							return;
						}


						items.forEach(
							function( item ) {

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
										(
											item.level === 0
												? 'Top level'
												: 'Level ' +
													item.level
										) +
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
								behavior: 'smooth',
								block: 'start'
							}
						);
					}


					/*
					 * Show conflict modal.
					 */
					function showConflictModal(
						existing
					) {

						existingNumber.textContent =
							existing.length;

						existingList.innerHTML =
							'';


						existing.forEach(
							function( item ) {

								const row =
									document.createElement(
										'div'
									);

								row.className =
									'ash-bpc-existing-item';


								row.innerHTML =
									'<div class="ash-bpc-existing-item-icon">' +
										'<span class="dashicons dashicons-admin-page"></span>' +
									'</div>' +

									'<div class="ash-bpc-existing-title">' +
										escapeHtml(
											item.title
										) +
									'</div>' +

									(
										item.url
											? '<div class="ash-bpc-existing-url">' +
												escapeHtml(
													item.url
												) +
												'</div>'
											: ''
									);

								existingList.appendChild(
									row
								);
							}
						);


						conflictModal.style.display =
							'flex';

						document.body.style.overflow =
							'hidden';
					}


					/*
					 * Hide conflict modal.
					 */
					function hideConflictModal() {

						conflictModal.style.display =
							'none';

						document.body.style.overflow =
							'';
					}


					/*
					 * Progress helpers.
					 */
					function getStep(
						id
					) {

						return document.getElementById(
							id
						);
					}


					function completeStep(
						id,
						text
					) {

						const step =
							getStep( id );

						if ( ! step ) {
							return;
						}

						step.classList.remove(
							'is-active'
						);

						step.classList.add(
							'is-complete'
						);


						const marker =
							step.querySelector(
								'.ash-bpc-step-marker'
							);

						if ( marker ) {

							marker.innerHTML =
								'<span class="dashicons dashicons-yes"></span>';

						}


						if ( text ) {

							const sub =
								step.querySelector(
									'.ash-bpc-step-content span'
								);

							if ( sub ) {
								sub.textContent =
									text;
							}
						}
					}


					function activateStep(
						id
					) {

						const step =
							getStep( id );

						if ( ! step ) {
							return;
						}

						step.classList.add(
							'is-active'
						);
					}


					function resetProgress() {

						[
							'ash-bpc-step-starting',
							'ash-bpc-step-checking',
							'ash-bpc-step-creating',
							'ash-bpc-step-completed'
						].forEach(
							function( id ) {

								const step =
									getStep( id );

								step.classList.remove(
									'is-active',
									'is-complete'
								);

								const marker =
									step.querySelector(
										'.ash-bpc-step-marker'
									);

								marker.innerHTML =
									'<span class="dashicons dashicons-minus"></span>';

							}
						);


						progressPages.innerHTML =
							'';

						progressBar.style.width =
							'0%';

						progressNumber.textContent =
							'0';

						progressTotal.textContent =
							'0';

						currentPage.textContent =
							'Waiting...';

						completedText.textContent =
							'Waiting for creation to finish';
					}


					function createProgressItems(
						items
					) {

						progressPages.innerHTML =
							'';

						items.forEach(
							function(
								item,
								index
							) {

								const row =
									document.createElement(
										'div'
									);

								row.className =
									'ash-bpc-progress-page';

								row.dataset.index =
									index;


								row.innerHTML =
									'<div class="ash-bpc-page-marker">' +
										'<span class="dashicons"></span>' +
									'</div>' +

									'<span class="ash-bpc-page-title">' +
										escapeHtml(
											item.title
										) +
									'</span>';

								progressPages.appendChild(
									row
								);
							}
						);
					}


					function setCurrentPage(
						index
					) {

						const rows =
							progressPages.querySelectorAll(
								'.ash-bpc-progress-page'
							);

						rows.forEach(
							function(
								row,
								rowIndex
							) {

								row.classList.remove(
									'is-active'
								);

								if (
									rowIndex ===
									index
								) {

									row.classList.add(
										'is-active'
									);

									row.scrollIntoView(
										{
											behavior:
												'smooth',
											block:
												'nearest'
										}
									);
								}
							}
						);


						currentPage.textContent =
							pendingItems[index]
								? 'Creating: ' +
									pendingItems[index].title
								: 'Processing...';
					}


					function completePage(
						index,
						action
					) {

						const row =
							progressPages.querySelector(
								'[data-index="' +
								index +
								'"]'
							);

						if ( ! row ) {
							return;
						}

						row.classList.remove(
							'is-active'
						);

						row.classList.add(
							'is-' +
							(
								action === 'created'
									? 'complete'
									: action
							)
						);


						const marker =
							row.querySelector(
								'.ash-bpc-page-marker'
							);

						if (
							action ===
							'created'
						) {

							marker.innerHTML =
								'<span class="dashicons dashicons-yes"></span>';

						} else if (
							action ===
							'replaced'
						) {

							marker.innerHTML =
								'<span class="dashicons dashicons-update"></span>';

						} else if (
							action ===
							'skipped'
						) {

							marker.innerHTML =
								'<span class="dashicons dashicons-controls-skipforward"></span>';

						} else if (
							action ===
							'duplicated'
						) {

							marker.innerHTML =
								'<span class="dashicons dashicons-admin-page"></span>';

						}

					}


					/*
					 * Result section.
					 */
					function resultSection(
						title,
						items,
						className
					) {

						if (
							! items ||
							! items.length
						) {
							return '';
						}


						let html =
							'<div class="ash-bpc-result-section ' +
							className +
							'">';

						html +=
							'<div class="ash-bpc-result-section-header">' +
							escapeHtml(
								title
							) +
							'</div>';

						html +=
							'<ul class="ash-bpc-result-list">';


						items.forEach(
							function( item ) {

								html +=
									'<li>';

								html +=
									'<strong>' +
									escapeHtml(
										item.title
									) +
									'</strong>';


								if (
									item.url
								) {

									html +=
										' <a href="' +
										escapeHtml(
											item.url
										) +
										'" target="_blank">View</a>';

								}


								if (
									item.message
								) {

									html +=
										' — ' +
										escapeHtml(
											item.message
										);

								}


								html +=
									'</li>';
							}
						);


						html +=
							'</ul></div>';

						return html;
					}


					function renderResults() {

						const total =
							pendingItems.length;

						const processed =
							creationResults.created.length +
							creationResults.replaced.length +
							creationResults.skipped.length +
							creationResults.duplicated.length;


						resultSummary.textContent =
							processed +
							' of ' +
							total +
							' pages processed successfully.';


						let html = '';


						html +=
							resultSection(
								'Created (' +
								creationResults.created.length +
								')',
								creationResults.created,
								'ash-bpc-result-created'
							);


						html +=
							resultSection(
								'Replaced (' +
								creationResults.replaced.length +
								')',
								creationResults.replaced,
								'ash-bpc-result-replaced'
							);


						html +=
							resultSection(
								'Skipped (' +
								creationResults.skipped.length +
								')',
								creationResults.skipped,
								'ash-bpc-result-skipped'
							);


						html +=
							resultSection(
								'Duplicated (' +
								creationResults.duplicated.length +
								')',
								creationResults.duplicated,
								'ash-bpc-result-duplicated'
							);


						html +=
							resultSection(
								'Failed (' +
								creationResults.failed.length +
								')',
								creationResults.failed,
								'ash-bpc-result-failed'
							);


						results.innerHTML =
							html;

						resultCard.style.display =
							'block';

					}


					/*
					 * Get parent ID for current level.
					 *
					 * The page immediately above this level
					 * becomes the parent.
					 */
					function getParentId(
						index
					) {

						const current =
							pendingItems[index];

						if (
							! current ||
							current.level === 0
						) {
							return 0;
						}


						for (
							let i = index - 1;
							i >= 0;
							i--
						) {

							if (
								pendingItems[i].level ===
								current.level - 1
							) {

								return (
									pendingItems[i]
										.createdId || 0
								);
							}
						}


						return 0;
					}


					/*
					 * Process pages ONE BY ONE.
					 */
					function processPage(
						index
					) {

						if (
							index >=
							pendingItems.length
						) {

							finishCreation();

							return;
						}


						setCurrentPage(
							index
						);


						const item =
							pendingItems[index];


						const parentId =
							getParentId(
								index
							);


						/*
						 * If a nested page cannot find its
						 * parent, stop rather than creating
						 * the wrong hierarchy.
						 */
						if (
							item.level > 0 &&
							! parentId
						) {

							creationResults.failed.push(
								{
									title:
										item.title,
									message:
										'Parent page could not be determined.'
								}
							);

							completePage(
								index,
								'failed'
							);

							processPage(
								index + 1
							);

							return;
						}


						ajaxRequest(
							'ash_bpc_create_one',
							{
								title:
									item.title,

								parent_id:
									parentId,

								conflict_action:
									selectedConflictAction
							}
						)
						.then(
							function( response ) {

								if (
									! response.success
								) {

									const message =
										response.data &&
										response.data.message
											? response.data.message
											: 'Page could not be created.';


									creationResults.failed.push(
										{
											title:
												item.title,
											message:
												message
										}
									);


									completePage(
										index,
										'failed'
									);


									processPage(
										index + 1
									);

									return;
								}


								const data =
									response.data;


								/*
								 * Store the actual created/existing
								 * page ID.
								 *
								 * This is essential for nested pages.
								 */
								item.createdId =
									parseInt(
										data.id,
										10
									);


								if (
									data.action ===
									'created'
								) {

									creationResults.created.push(
										data
									);

								} else if (
									data.action ===
									'replaced'
								) {

									creationResults.replaced.push(
										data
									);

								} else if (
									data.action ===
									'skipped'
								) {

									creationResults.skipped.push(
										data
									);

								} else if (
									data.action ===
									'duplicated'
								) {

									creationResults.duplicated.push(
										data
									);
								}


								completePage(
									index,
									data.action
								);


								const completed =
									index + 1;

								const percentage =
									(
										completed /
										pendingItems.length
									) *
									100;


								progressNumber.textContent =
									completed;

								progressBar.style.width =
									percentage +
									'%';


								processPage(
									index + 1
								);

							}
						)
						.catch(
							function() {

								creationResults.failed.push(
									{
										title:
											item.title,
										message:
											'Network error while creating this page.'
									}
								);


								completePage(
									index,
									'failed'
								);


								processPage(
									index + 1
								);

							}
						);
					}


					/*
					 * Finish.
					 */
					function finishCreation() {

						currentPage.textContent =
							'All pages processed.';

						progressTitle.textContent =
							'Creation Complete';

						progressSubtitle.textContent =
							'Your page hierarchy has been processed.';


						completeStep(
							'ash-bpc-step-creating',
							'All pages processed successfully'
						);


						completeStep(
							'ash-bpc-step-completed',
							'Creation finished'
						);


						progressNumber.textContent =
							pendingItems.length;

						progressTotal.textContent =
							pendingItems.length;

						progressBar.style.width =
							'100%';


						const processed =
							creationResults.created.length +
							creationResults.replaced.length +
							creationResults.skipped.length +
							creationResults.duplicated.length;


						completedText.textContent =
							processed +
							' of ' +
							pendingItems.length +
							' pages processed';


						/*
						 * Give the user a moment to see
						 * the final completed state.
						 */
						setTimeout(
							function() {

								progressOverlay.style.display =
									'none';

								document.body.style.overflow =
									'';

								createButton.disabled =
									false;

								renderResults();

							},
							1000
						);
					}


					/*
					 * Start actual progressive creation.
					 */
					function startCreation(
						action
					) {

						selectedConflictAction =
							action;

						hideConflictModal();

						pendingItems =
							parseStructure();

						creationResults = {
							created: [],
							replaced: [],
							skipped: [],
							duplicated: [],
							failed: []
						};


						if (
							! pendingItems.length
						) {
							return;
						}


						resetProgress();

						createProgressItems(
							pendingItems
						);


						progressTotal.textContent =
							pendingItems.length;


						progressOverlay.style.display =
							'flex';

						document.body.style.overflow =
							'hidden';


						/*
						 * Starting.
						 */
						activateStep(
							'ash-bpc-step-starting'
						);


						setTimeout(
							function() {

								completeStep(
									'ash-bpc-step-starting',
									'Page structure ready'
								);


								/*
								 * Checking.
								 */
								activateStep(
									'ash-bpc-step-checking'
								);


								setTimeout(
									function() {

										completeStep(
											'ash-bpc-step-checking',
											'Existing page check complete'
										);


										/*
										 * Creating.
										 */
										activateStep(
											'ash-bpc-step-creating'
										);


										processPage(
											0
										);

									},
									250
								);

							},
							250
						);
					}


					/*
					 * Create button.
					 */
					createButton.addEventListener(
						'click',
						function() {

							const content =
								textarea.value.trim();


							if ( ! content ) {

								alert(
									'Please enter your page structure first.'
								);

								textarea.focus();

								return;
							}


							pendingContent =
								textarea.value;


							createButton.disabled =
								true;


							/*
							 * Preflight is deliberately
							 * separate from actual creation.
							 */
							ajaxRequest(
								'ash_bpc_preflight',
								{
									content:
										pendingContent
								}
							)
							.then(
								function( response ) {

									createButton.disabled =
										false;


									if (
										! response.success
									) {

										alert(
											response.data &&
											response.data.message
												? response.data.message
												: 'Unable to check pages.'
										);

										return;
									}


									pendingItems =
										response.data.items ||
										[];

									pendingExisting =
										response.data.existing ||
										[];


									/*
									 * No conflicts:
									 * immediately start.
									 */
									if (
										! pendingExisting.length
									) {

										startCreation(
											'skip'
										);

										return;
									}


									/*
									 * Conflicts:
									 * show choice modal.
									 */
									showConflictModal(
										pendingExisting
									);

								}
							)
							.catch(
								function() {

									createButton.disabled =
										false;

									alert(
										'The request could not be completed. Please try again.'
									);

								}
							);

						}
					);


					/*
					 * Conflict buttons.
					 */
					document
						.querySelectorAll(
							'.ash-bpc-conflict-button'
						)
						.forEach(
							function( button ) {

								button.addEventListener(
									'click',
									function() {

										startCreation(
											button.dataset.action
										);

									}
								);

							}
						);


					/*
					 * Close modal.
					 */
					modalClose.addEventListener(
						'click',
						hideConflictModal
					);


					conflictModal.addEventListener(
						'click',
						function( event ) {

							if (
								event.target ===
								conflictModal
							) {

								hideConflictModal();

							}

						}
					);


					/*
					 * Preview.
					 */
					previewButton.addEventListener(
						'click',
						renderTree
					);


					/*
					 * Close preview.
					 */
					closePreview.addEventListener(
						'click',
						function() {

							previewCard.style.display =
								'none';

						}
					);


					/*
					 * Counter.
					 */
					textarea.addEventListener(
						'input',
						updateCounter
					);


					/*
					 * Copy prompt.
					 */
					copyPrompt.addEventListener(
						'click',
						function() {

							const button =
								copyPrompt;


							function copied() {

								button.classList.add(
									'is-copied'
								);

								button.innerHTML =
									'<span class="dashicons dashicons-yes"></span> Copied!';


								setTimeout(
									function() {

										button.classList.remove(
											'is-copied'
										);

										button.innerHTML =
											'<span class="dashicons dashicons-admin-page"></span> Copy Prompt';

									},
									1800
								);
							}


							if (
								navigator.clipboard &&
								window.isSecureContext
							) {

								navigator.clipboard
									.writeText(
										promptText
									)
									.then(
										copied
									)
									.catch(
										function() {

											fallbackCopy();

										}
									);

							} else {

								fallbackCopy();

							}


							function fallbackCopy() {

								const temp =
									document.createElement(
										'textarea'
									);

								temp.value =
									promptText;

								temp.style.position =
									'fixed';

								temp.style.left =
									'-9999px';

								document.body.appendChild(
									temp
								);

								temp.select();

								try {

									document.execCommand(
										'copy'
									);

									copied();

								} catch ( error ) {

									alert(
										'Unable to copy the prompt.'
									);

								}

								document.body.removeChild(
									temp
								);
							}

						}
					);


					/*
					 * Escape key.
					 */
					document.addEventListener(
						'keydown',
						function( event ) {

							if (
								event.key ===
								'Escape'
							) {

								hideConflictModal();

							}

						}
					);


					/*
					 * Initial count.
					 */
					updateCounter();

				}
			);

		</script>

		<?php
	}
}


/**
 * Initialize plugin.
 */
new Ash_Bulk_Page_Creation();
