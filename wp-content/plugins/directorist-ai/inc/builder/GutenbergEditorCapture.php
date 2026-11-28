<?php
/**
 * Read-only capture entry point in an authenticated Gutenberg page editor.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

/** The browser supplies ephemeral registry and dirty-tree facts, never authority. */
final class GutenbergEditorCapture {
	/** Attach to the native block editor asset hook. */
	public function hooks(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/** Enqueue only for the currently opened editable page. */
	public function enqueue(): void {
		$screen = get_current_screen();
		global $post;
		if ( ! $screen instanceof \WP_Screen || 'post' !== $screen->base || 'page' !== $screen->post_type || ! $post instanceof \WP_Post || 'page' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$path = plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . 'inc/assets/js/gutenberg-editor-capture.js';
		if ( ! is_readable( $path ) ) {
			return;
		}
		$handle = 'directorist-ai-gutenberg-editor-capture';
		wp_enqueue_script( $handle, plugin_dir_url( DIRECTORIST_AI_PRO_FILE ) . 'inc/assets/js/gutenberg-editor-capture.js', array( 'wp-data', 'wp-blocks' ), hash_file( 'sha256', $path ), true );
		wp_add_inline_script(
			$handle,
			'window.directoristAIGutenbergBoot = ' . wp_json_encode(
				array(
					'documentRef' => 'post:' . $post->ID,
					'pageId'      => (int) $post->ID,
					'endpoint'    => rest_url( 'directorist-ai/v1/builder/gutenberg/' ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
		$stage_id = isset( $_GET['directorist_ai_stage'] ) ? sanitize_text_field( wp_unslash( $_GET['directorist_ai_stage'] ) ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $stage_id ) ) {
			$stage_id = ''; }
		try {
			if ( 'gutenberg' !== BuilderScopedLiveApplyRegistry::installed()->adapter( 'gutenberg' )->builder() ) {
				return;
			}
		} catch ( \Throwable $error ) {
			return;
		}
		$apply_path = plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . 'inc/assets/js/gutenberg-live-apply.js';
		if ( ! is_readable( $apply_path ) ) {
			return;
		}
		$apply_handle = 'directorist-ai-gutenberg-live-apply';
		wp_enqueue_script( $apply_handle, plugin_dir_url( DIRECTORIST_AI_PRO_FILE ) . 'inc/assets/js/gutenberg-live-apply.js', array( 'wp-api-fetch', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-i18n', 'wp-plugins' ), hash_file( 'sha256', $apply_path ), true );
		wp_add_inline_script(
			$apply_handle,
			'window.directoristAIGutenbergApplyBoot = ' . wp_json_encode(
				array(
					'operationId' => $stage_id,
					'pageId'      => (int) $post->ID,
					'endpoint'    => rest_url( 'directorist-ai/v1/builder/drafts/' . rawurlencode( $stage_id ) ),
					'liveRest'    => rest_url( 'directorist-ai/v1/builder/live/' ),
					'draftRest'   => rest_url( 'directorist-ai/v1/builder/drafts/' ),
					'pageRest'    => rest_url( 'wp/v2/pages/' ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
	}
}
