<?php

namespace SEOPressPro\Actions\Ajax;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooks;

class BulkAIGenerateTitleDescription implements ExecuteHooks {

	/**
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'wp_ajax_seopress_bulk_action_ai_title', array( $this, 'handleTitle' ) );
		add_action( 'wp_ajax_seopress_bulk_action_ai_desc', array( $this, 'handleDescription' ) );
		add_action( 'wp_ajax_seopress_bulk_action_ai_alt_text', array( $this, 'handleAltText' ) );
		add_action( 'wp_ajax_seopress_bulk_action_ai_alt_text_missing', array( $this, 'handleAltTextMissing' ) );
		add_action( 'wp_ajax_seopress_bulk_action_ai_alt_only', array( $this, 'handleAltOnly' ) );
		add_action( 'wp_ajax_seopress_bulk_action_ai_caption_only', array( $this, 'handleCaptionOnly' ) );
		add_action( 'wp_ajax_seopress_bulk_action_ai_description_only', array( $this, 'handleDescriptionOnly' ) );
	}

	/**
	 * Answer a generation result.
	 *
	 * The service reports a failure in the payload itself, and both outcomes
	 * carry a `message`: a failed generation was announced with
	 * wp_send_json_success() all the same, so the browser saw a success with
	 * nothing usable in it and could only fall back to a generic error. The
	 * reason sat in the payload and never reached the user, which is exactly
	 * what support needs.
	 *
	 * @since 10.2.0
	 *
	 * @param array $data The result returned by the Completions service.
	 *
	 * @return void
	 */
	protected function sendGenerationResult( $data ) {
		if ( is_array( $data ) && empty( $data['success'] ) ) {
			wp_send_json_error( $data );
		}

		wp_send_json_success( $data );
	}

	/**
	 * @return void
	 */
	public function handleTitle() {
		check_ajax_referer( 'bulk-posts' );

		if ( ! is_admin() ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( 'missing_parameters' );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ) );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( $post_id, 'edit_post', 'bulk' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		$lang = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : 'en_US';

		$data = seopress_pro_get_service( 'Completions' )->generateTitlesDesc( $post_id, 'title', $lang, true );

		$this->sendGenerationResult( $data );
	}

	/**
	 * @return void
	 */
	public function handleDescription() {
		check_ajax_referer( 'bulk-posts' );

		if ( ! is_admin() ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( 'missing_parameters' );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ) );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( $post_id, 'edit_post', 'bulk' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		$lang = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : 'en_US';

		$data = seopress_pro_get_service( 'Completions' )->generateTitlesDesc( $post_id, 'desc', $lang, true );

		$this->sendGenerationResult( $data );
	}

	/**
	 * Handle bulk action to generate image metadata (alt text, caption, description)
	 *
	 * @return void
	 */
	public function handleAltText() {
		check_ajax_referer( 'bulk-media' );

		if ( ! is_admin() ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( 'missing_parameters' );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ) );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( $post_id, 'edit_post', 'bulk' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		$lang = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : 'en_US';

		// Generate all image metadata (alt text, caption, description) - replace all fields
		$data = seopress_pro_get_service( 'Completions' )->generateImgAltText( $post_id, 'image_meta', $lang, true );

		$this->sendGenerationResult( $data );
	}

	/**
	 * Handle bulk action to generate image metadata for images with missing alt text
	 *
	 * @return void
	 */
	public function handleAltTextMissing() {
		check_ajax_referer( 'bulk-media' );

		if ( ! is_admin() ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( 'missing_parameters' );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ) );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( $post_id, 'edit_post', 'bulk' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		$lang = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : 'en_US';

		// Generate image metadata only for empty fields (alt text, caption, description)
		$data = seopress_pro_get_service( 'Completions' )->generateImgAltText( $post_id, 'image_meta', $lang, false );

		$this->sendGenerationResult( $data );
	}

	/**
	 * Handle bulk action to generate only alt text with AI.
	 *
	 * @return void
	 */
	public function handleAltOnly() {
		check_ajax_referer( 'bulk-media' );

		if ( ! is_admin() ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( 'missing_parameters' );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ) );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( $post_id, 'edit_post', 'bulk' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		$lang = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : 'en_US';

		$data = seopress_pro_get_service( 'Completions' )->generateImgAltText( $post_id, 'image_meta', $lang, true, null, array( 'alt_text' ) );

		$this->sendGenerationResult( $data );
	}

	/**
	 * Handle bulk action to generate only caption with AI.
	 *
	 * @return void
	 */
	public function handleCaptionOnly() {
		check_ajax_referer( 'bulk-media' );

		if ( ! is_admin() ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( 'missing_parameters' );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ) );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( $post_id, 'edit_post', 'bulk' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		$lang = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : 'en_US';

		$data = seopress_pro_get_service( 'Completions' )->generateImgAltText( $post_id, 'image_meta', $lang, true, null, array( 'caption' ) );

		$this->sendGenerationResult( $data );
	}

	/**
	 * Handle bulk action to generate only description with AI.
	 *
	 * @return void
	 */
	public function handleDescriptionOnly() {
		check_ajax_referer( 'bulk-media' );

		if ( ! is_admin() ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( 'missing_parameters' );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ) );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( $post_id, 'edit_post', 'bulk' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		$lang = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : 'en_US';

		$data = seopress_pro_get_service( 'Completions' )->generateImgAltText( $post_id, 'image_meta', $lang, true, null, array( 'description' ) );

		$this->sendGenerationResult( $data );
	}
}
