<?php
/**
 * Plugin Name: Bulk Page Creation
 * Plugin URI: https://ashikhosen.com
 * Description: Create WordPress pages and unlimited nested parent/child page structures from simple text.
 * Version: 3.0.0
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
			'wp_ajax_ash_bpc_create',
			array( $this, 'ajax_create' )
		);
	}

	/**
	 * Plugin Settings link.
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
	 * Parse hierarchy.
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

			if ( ! preg_match( '/^(-*)(?:\s*)(.*?)\s*$/', $line, $matches ) ) {
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

			if ( $level > 0 && ! isset( $parents[ $level - 1 ] ) ) {

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
					unset( $parents[ $stored_level ] );
				}
			}
		}

		return $errors;
	}

	/**
	 * Find existing page by title.
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
	 * Find all existing pages before creation.
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
	 * AJAX preflight.
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

		$existing = $this->get_existing_items( $items );

		wp_send_json_success(
			array(
				'total'    => count( $items ),
				'existing' => $existing,
			)
		);
	}

	/**
	 * AJAX create.
	 */
	public function ajax_create() {

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

		$conflict_action = isset( $_POST['conflict_action'] )
			? sanitize_key(
				wp_unslash(
					$_POST['conflict_action']
				)
			)
			: 'skip';

		$allowed_actions = array(
			'replace',
			'skip',
			'duplicate',
		);

		if ( ! in_array( $conflict_action, $allowed_actions, true ) ) {
			$conflict_action = 'skip';
		}

		$items = $this->parse_input( $input );

		if ( empty( $items ) ) {

			wp_send_json_error(
				array(
					'message' => 'No pages found.',
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

		/*
		 * Increase execution time when possible.
		 * This helps with large page trees.
		 */
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$parents = array();

		$created = array();

		$skipped = array();

		$replaced = array();

		$duplicated = array();

		$failed = array();

		foreach ( $items as $index => $item ) {

			$level = $item['level'];

			$title = $item['title'];

			/*
			 * Determine parent from the previously
			 * processed hierarchy level.
			 */
			$parent_id = 0;

			$parent_title = '';

			if ( $level > 0 ) {

				if ( ! isset( $parents[ $level - 1 ] ) ) {

					$failed[] = array(
						'title'   => $title,
						'message' => 'Parent page could not be determined.',
					);

					continue;
				}

				$parent_id =
					(int) $parents[ $level - 1 ]['id'];

				$parent_title =
					$parents[ $level - 1 ]['title'];
			}

			/*
			 * Find an existing page with this title.
			 */
			$existing =
				$this->find_existing_page(
					$title
				);

			$page_id = 0;

			/*
			 * =====================================================
			 * EXISTING PAGE
			 * =====================================================
			 */
			if ( $existing ) {

				/*
				 * REPLACE
				 */
				if ( 'replace' === $conflict_action ) {

					$update = array(
						'ID'          => $existing->ID,
						'post_title'  => $title,
						'post_parent' => $parent_id,
						'post_status' => 'publish',
						'post_type'   => 'page',
					);

					$updated = wp_update_post(
						wp_slash( $update ),
						true
					);

					if ( is_wp_error( $updated ) ) {

						$failed[] = array(
							'title'   => $title,
							'message' => $updated->get_error_message(),
						);

						continue;
					}

					$page_id = (int) $existing->ID;

					$replaced[] = array(
						'title'  => $title,
						'id'     => $page_id,
						'parent' => $parent_title,
						'url'    => get_permalink( $page_id ),
					);
				}

				/*
				 * SKIP
				 */
				elseif ( 'skip' === $conflict_action ) {

					$page_id = (int) $existing->ID;

					$skipped[] = array(
						'title'  => $title,
						'id'     => $page_id,
						'parent' => $parent_title,
						'url'    => get_permalink( $page_id ),
					);
				}

				/*
				 * DUPLICATE
				 */
				else {

					$new_page = wp_insert_post(
						wp_slash(
							array(
								'post_title'  => $title,
								'post_content' => '',
								'post_status' => 'publish',
								'post_type'   => 'page',
								'post_parent' => $parent_id,
							)
						),
						true
					);

					if ( is_wp_error( $new_page ) ) {

						$failed[] = array(
							'title'   => $title,
							'message' => $new_page->get_error_message(),
						);

						continue;
					}

					$page_id = (int) $new_page;

					$duplicated[] = array(
						'title'  => get_the_title( $page_id ),
						'id'     => $page_id,
						'parent' => $parent_title,
						'url'    => get_permalink( $page_id ),
					);
				}
			}

			/*
			 * =====================================================
			 * NEW PAGE
			 * =====================================================
			 */
			else {

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

					$failed[] = array(
						'title'   => $title,
						'message' => $new_page->get_error_message(),
					);

					continue;
				}

				$page_id = (int) $new_page;

				$created[] = array(
					'title'  => $title,
					'id'     => $page_id,
					'parent' => $parent_title,
					'url'    => get_permalink( $page_id ),
				);
			}

			/*
			 * Store the page at its hierarchy level.
			 *
			 * This is critical for nested structures.
			 */
			$parents[ $level ] = array(
				'id'    => $page_id,
				'title' => $title,
			);

			/*
			 * Remove deeper hierarchy levels.
			 */
			foreach ( $parents as $stored_level => $stored_page ) {

				if ( $stored_level > $level ) {
					unset(
						$parents[ $stored_level ]
					);
				}
			}
		}

		wp_send_json_success(
			array(
				'total'      => count( $items ),
				'created'    => $created,
				'replaced'   => $replaced,
				'skipped'    => $skipped,
				'duplicated' => $duplicated,
				'failed'     => $failed,
			)
		);
	}

	/**
	 * Render admin page.
	 */
	public function render_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>

		<div class="ash-bpc-wrap">

			<div class="ash-bpc-container">

				<!-- Header -->

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


				<!-- Main Card -->

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
- Case Studies
About
Contact"
							spellcheck="false"
						></textarea>

					</div>


					<!-- How it works -->

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


					<!-- Actions -->

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


			<!-- Conflict Modal -->

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


			<!-- Loading -->

			<div
				class="ash-bpc-loading"
				id="ash-bpc-loading"
				style="display:none;"
			>

				<div class="ash-bpc-loading-box">

					<div class="ash-bpc-spinner"></div>

					<strong id="ash-bpc-loading-title">
						Creating Pages
					</strong>

					<span id="ash-bpc-loading-text">
						Please wait...
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

			.ash-bpc-container {
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


			/* =========================================================
			 * Card
			 * ========================================================= */

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


			/* =========================================================
			 * Editor
			 * ========================================================= */

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

			#ash-bpc-content::placeholder {
				color: #a7aaad;
			}


			/* =========================================================
			 * Help
			 * ========================================================= */

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
				font-size: 17px;
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
				font-weight: 500;
				cursor: pointer;
				transition: .15s ease;
			}

			.ash-bpc-copy-prompt:hover {
				border-color: #8c8f94;
				background: #f6f7f7;
				color: #1d2327;
			}

			.ash-bpc-copy-prompt.is-copied {
				border-color: #46b450;
				background: #f0f8f0;
				color: #28752c;
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
				transition: .15s ease;
			}

			.ash-bpc-preview-button {
				background: #fff;
				border: 1px solid #c3c4c7;
				color: #2c3338;
			}

			.ash-bpc-preview-button:hover {
				background: #f6f7f7;
				border-color: #8c8f94;
			}

			.ash-bpc-create-button {
				background: #2271b1;
				border: 1px solid #2271b1;
				color: #fff;
			}

			.ash-bpc-create-button:hover {
				background: #135e96;
				border-color: #135e96;
			}

			.ash-bpc-button:disabled {
				opacity: .55;
				cursor: not-allowed;
			}


			/* =========================================================
			 * Tree
			 * ========================================================= */

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

			.ash-bpc-tree-row:last-child {
				border-bottom: 0;
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

			.ash-bpc-tree-icon .dashicons {
				font-size: 16px;
				width: 16px;
				height: 16px;
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


			/* =========================================================
			 * Result
			 * ========================================================= */

			.ash-bpc-results {
				padding: 24px;
			}

			.ash-bpc-result-section {
				margin-bottom: 15px;
				border: 1px solid #dcdcde;
				border-radius: 8px;
				overflow: hidden;
			}

			.ash-bpc-result-section:last-child {
				margin-bottom: 0;
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

			.ash-bpc-result-created .ash-bpc-result-section-header {
				background: #edfaef;
				color: #1e4620;
			}

			.ash-bpc-result-replaced {
				border-color: #c6e1c6;
			}

			.ash-bpc-result-replaced .ash-bpc-result-section-header {
				background: #edfaef;
				color: #1e4620;
			}

			.ash-bpc-result-skipped {
				border-color: #c5d9ed;
			}

			.ash-bpc-result-skipped .ash-bpc-result-section-header {
				background: #eef6fc;
				color: #164b72;
			}

			.ash-bpc-result-duplicated {
				border-color: #e0d2ad;
			}

			.ash-bpc-result-duplicated .ash-bpc-result-section-header {
				background: #fcf8ec;
				color: #6d5515;
			}

			.ash-bpc-result-failed {
				border-color: #e5c7c7;
			}

			.ash-bpc-result-failed .ash-bpc-result-section-header {
				background: #fcf0f0;
				color: #8a2424;
			}


			/* =========================================================
			 * Modal
			 * ========================================================= */

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
				animation: ashBpcModalIn .18s ease;
			}

			@keyframes ashBpcModalIn {

				from {
					opacity: 0;
					transform: translateY(8px) scale(.99);
				}

				to {
					opacity: 1;
					transform: translateY(0) scale(1);
				}

			}

			.ash-bpc-modal-header {
				display: flex;
				align-items: flex-start;
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

			.ash-bpc-modal-icon .dashicons {
				font-size: 18px;
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
				display: flex;
				align-items: center;
				justify-content: center;
				cursor: pointer;
				color: #646970;
			}

			.ash-bpc-modal-close:hover {
				background: #f6f7f7;
				color: #1d2327;
			}

			.ash-bpc-existing-count {
				padding: 14px 24px;
				background: #f6f7f7;
				font-size: 12px;
				color: #50575e;
				font-weight: 500;
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

			.ash-bpc-existing-item:last-child {
				border-bottom: 0;
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
				flex: 0 0 27px;
			}

			.ash-bpc-existing-item-icon .dashicons {
				font-size: 14px;
				width: 14px;
				height: 14px;
			}

			.ash-bpc-existing-title {
				font-weight: 500;
				color: #1d2327;
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
				font-weight: 500;
				display: flex;
				align-items: center;
				justify-content: center;
				gap: 6px;
			}

			.ash-bpc-replace {
				border: 1px solid #2271b1;
				color: #2271b1;
			}

			.ash-bpc-replace:hover {
				background: #f0f6fc;
			}

			.ash-bpc-skip {
				border: 1px solid #c3c4c7;
				color: #50575e;
			}

			.ash-bpc-skip:hover {
				background: #f6f7f7;
			}

			.ash-bpc-duplicate {
				border: 1px solid #8c8f94;
				color: #2c3338;
			}

			.ash-bpc-duplicate:hover {
				background: #f6f7f7;
			}


			/* =========================================================
			 * Loading
			 * ========================================================= */

			.ash-bpc-loading {
				position: fixed;
				inset: 0;
				z-index: 999998;
				background: rgba(255,255,255,.78);
				display: flex;
				align-items: center;
				justify-content: center;
			}

			.ash-bpc-loading-box {
				min-width: 260px;
				padding: 28px;
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 10px;
				box-shadow: 0 12px 40px rgba(0,0,0,.12);
				text-align: center;
			}

			.ash-bpc-spinner {
				width: 30px;
				height: 30px;
				margin: 0 auto 14px;
				border: 3px solid #dcdcde;
				border-top-color: #2271b1;
				border-radius: 50%;
				animation: ashBpcSpin .7s linear infinite;
			}

			@keyframes ashBpcSpin {

				to {
					transform: rotate(360deg);
				}

			}

			.ash-bpc-loading-box strong {
				display: block;
				margin-bottom: 5px;
				font-size: 14px;
			}

			.ash-bpc-loading-box span {
				font-size: 12px;
				color: #646970;
			}


			/* =========================================================
			 * Footer
			 * ========================================================= */

			.ash-bpc-footer {
				display: flex;
				justify-content: space-between;
				padding: 4px 2px;
				font-size: 11px;
				color: #8c8f94;
			}


			/* =========================================================
			 * Responsive
			 * ========================================================= */

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

				.ash-bpc-existing-url {
					display: none;
				}

				.ash-bpc-footer {
					flex-direction: column;
					gap: 5px;
				}
			}

			@media (max-width: 500px) {

				.ash-bpc-help-header {
					flex-direction: column;
					align-items: stretch;
				}

				.ash-bpc-copy-prompt {
					width: 100%;
					justify-content: center;
				}

				.ash-bpc-example {
					align-items: flex-start;
					flex-direction: column;
					gap: 4px;
				}

				.ash-bpc-example code {
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

					const loading =
						document.getElementById(
							'ash-bpc-loading'
						);

					const loadingTitle =
						document.getElementById(
							'ash-bpc-loading-title'
						);

					const loadingText =
						document.getElementById(
							'ash-bpc-loading-text'
						);

					const copyPrompt =
						document.getElementById(
							'ash-bpc-copy-prompt'
						);


					let pendingContent = '';


					/*
					 * Generic prompt.
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
					function escapeHtml( value ) {

						const div =
							document.createElement(
								'div'
							);

						div.textContent =
							value;

						return div.innerHTML;
					}


					/*
					 * Parse hierarchy in JavaScript.
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
							function( rawLine ) {

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

								const dashes =
									match[1] || '';

								const title =
									(
										match[2] || ''
									).trim();

								if ( ! title ) {
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


					/*
					 * Update page counter.
					 */
					function updateCounter() {

						const count =
							parseStructure().length;

						lineCount.textContent =
							count;
					}


					/*
					 * Render tree.
					 */
					function renderTree() {

						const items =
							parseStructure();

						tree.innerHTML =
							'';

						if ( ! items.length ) {

							tree.innerHTML =
								'<div style="padding:30px;text-align:center;color:#646970;">' +
								'Enter your page structure first.' +
								'</div>';

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
					 * Show loading.
					 */
					function showLoading(
						title,
						text
					) {

						loadingTitle.textContent =
							title;

						loadingText.textContent =
							text;

						loading.style.display =
							'flex';
					}


					/*
					 * Hide loading.
					 */
					function hideLoading() {

						loading.style.display =
							'none';
					}


					/*
					 * AJAX request helper.
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
								body: body.toString()
							}
						).then(
							function( response ) {

								return response.json();

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
					 * Create result section.
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
							escapeHtml( title ) +
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
									item.parent
								) {

									html +=
										' — Child of ' +
										escapeHtml(
											item.parent
										);

								}


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
							'</ul>';

						html +=
							'</div>';

						return html;
					}


					/*
					 * Render creation result.
					 */
					function renderResults(
						data
					) {

						resultCard.style.display =
							'block';


						const created =
							data.created || [];

						const replaced =
							data.replaced || [];

						const skipped =
							data.skipped || [];

						const duplicated =
							data.duplicated || [];

						const failed =
							data.failed || [];


						const processed =
							created.length +
							replaced.length +
							skipped.length +
							duplicated.length;


						resultSummary.textContent =
							processed +
							' of ' +
							data.total +
							' pages processed successfully.';


						let html = '';


						html +=
							resultSection(
								'Created (' +
								created.length +
								')',
								created,
								'ash-bpc-result-created'
							);


						html +=
							resultSection(
								'Replaced (' +
								replaced.length +
								')',
								replaced,
								'ash-bpc-result-replaced'
							);


						html +=
							resultSection(
								'Skipped (' +
								skipped.length +
								')',
								skipped,
								'ash-bpc-result-skipped'
							);


						html +=
							resultSection(
								'Duplicated (' +
								duplicated.length +
								')',
								duplicated,
								'ash-bpc-result-duplicated'
							);


						html +=
							resultSection(
								'Failed (' +
								failed.length +
								')',
								failed,
								'ash-bpc-result-failed'
							);


						results.innerHTML =
							html;


						resultCard.scrollIntoView(
							{
								behavior: 'smooth',
								block: 'start'
							}
						);
					}


					/*
					 * Create pages.
					 */
					function createPages(
						conflictAction
					) {

						hideConflictModal();

						showLoading(
							'Creating Pages',
							'Creating your page hierarchy...'
						);


						createButton.disabled =
							true;


						ajaxRequest(
							'ash_bpc_create',
							{
								content:
									pendingContent,

								conflict_action:
									conflictAction
							}
						)
						.then(
							function( response ) {

								hideLoading();

								createButton.disabled =
									false;


								if (
									! response.success
								) {

									alert(
										response.data &&
										response.data.message
											? response.data.message
											: 'Something went wrong.'
									);

									return;
								}


								renderResults(
									response.data
								);

							}
						)
						.catch(
							function() {

								hideLoading();

								createButton.disabled =
									false;

								alert(
									'The request could not be completed. Please try again.'
								);

							}
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


							const total =
								parseStructure().length;


							if ( ! total ) {

								alert(
									'No valid pages were found.'
								);

								return;
							}


							pendingContent =
								textarea.value;


							showLoading(
								'Checking Pages',
								'Checking for existing pages...'
							);


							ajaxRequest(
								'ash_bpc_preflight',
								{
									content:
										pendingContent
								}
							)
							.then(
								function( response ) {

									hideLoading();


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


									const data =
										response.data;


									/*
									 * No existing pages:
									 * create immediately.
									 */
									if (
										! data.existing ||
										! data.existing.length
									) {

										createPages(
											'skip'
										);

										return;
									}


									/*
									 * Existing pages found:
									 * show modal.
									 */
									showConflictModal(
										data.existing
									);

								}
							)
							.catch(
								function() {

									hideLoading();

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

										createPages(
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


					/*
					 * Close modal by clicking overlay.
					 */
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
					 * Escape key.
					 */
					document.addEventListener(
						'keydown',
						function( event ) {

							if (
								'Escape' ===
								event.key
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
					 * Initial counter.
					 */
					updateCounter();

				}
			);

		</script>

		<?php
	}
}


/**
 * Initialize.
 */
new Ash_Bulk_Page_Creation();
