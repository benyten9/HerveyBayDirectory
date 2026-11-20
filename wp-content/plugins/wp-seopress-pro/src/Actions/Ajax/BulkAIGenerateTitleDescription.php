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
		add_action( 'wp_ajax_seopress_bulk_action_ai_save_meta', array( $this, 'handleSaveMeta' ) );
		add_action( 'wp_ajax_seopress_bulk_action_ai_save_image_meta', array( $this, 'handleSaveImageMeta' ) );
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
	 * @param array $data    The result returned by the Completions service.
	 * @param int   $post_id The post or attachment the result is for.
	 *
	 * @return void
	 */
	protected function sendGenerationResult( $data, $post_id = 0 ) {
		// The review step shows each suggestion next to the value it would
		// replace, so it needs that value and something to name the item by.
		if ( is_array( $data ) && $post_id && $this->isReview() ) {
			$data['review'] = $this->getReviewContext( $post_id );
		}

		if ( is_array( $data ) && empty( $data['success'] ) ) {
			wp_send_json_error( $data );
		}

		wp_send_json_success( $data );
	}

	/**
	 * Whether the request comes from the bulk review step.
	 *
	 * In review mode nothing is written: the suggestions are only returned, and
	 * the user saves the ones they approved with the save actions below.
	 *
	 * @since 10.4.0
	 *
	 * @return bool
	 */
	protected function isReview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Every handler checks its nonce before calling this.
		return ! empty( $_POST['review'] );
	}

	/**
	 * The current values of a post or an attachment, with what the review
	 * step needs to show it.
	 *
	 * @since 10.4.0
	 *
	 * @param int $post_id The post or attachment ID.
	 *
	 * @return array
	 */
	protected function getReviewContext( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		if ( 'attachment' === $post->post_type ) {
			return array(
				'label'     => $post->post_title,
				'link'      => get_edit_post_link( $post_id, 'raw' ),
				'thumbnail' => wp_get_attachment_image_url( $post_id, 'thumbnail' ),
				'current'   => array(
					'alt_text'    => (string) get_post_meta( $post_id, '_wp_attachment_image_alt', true ),
					'caption'     => $post->post_excerpt,
					'description' => $post->post_content,
				),
			);
		}

		return array(
			'label'   => $post->post_title,
			'link'    => get_permalink( $post_id ),
			'current' => array(
				'title' => html_entity_decode( (string) get_post_meta( $post_id, '_seopress_titles_title', true ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
				'desc'  => html_entity_decode( (string) get_post_meta( $post_id, '_seopress_titles_desc', true ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
			),
		);
	}

	/**
	 * Check a save request from the bulk review step.
	 *
	 * @since 10.4.0
	 *
	 * @param string $nonce_action The list table's bulk nonce action.
	 *
	 * @return int The post ID, once the request is allowed.
	 */
	protected function checkSaveRequest( $nonce_action ) {
		check_ajax_referer( $nonce_action );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( $post_id, 'edit_post', 'bulk' ) ) {
			wp_send_json_error( 'not_authorized' );
		}

		return $post_id;
	}

	/**
	 * Save the SEO title and/or meta description the user approved in the
	 * bulk review step.
	 *
	 * @since 10.4.0
	 *
	 * @return void
	 */
	public function handleSaveMeta() {
		$post_id = $this->checkSaveRequest( 'bulk-posts' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in checkSaveRequest().

		$values = array();
		if ( isset( $_POST['title'] ) ) {
			$values['title'] = sanitize_text_field( wp_unslash( $_POST['title'] ) );
		}
		if ( isset( $_POST['desc'] ) ) {
			$values['desc'] = sanitize_textarea_field( wp_unslash( $_POST['desc'] ) );
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing

		seopress_pro_get_service( 'Completions' )->saveTitlesDesc( $post_id, $values );

		wp_send_json_success();
	}

	/**
	 * Save the image metadata the user approved in the bulk review step.
	 *
	 * @since 10.4.0
	 *
	 * @return void
	 */
	public function handleSaveImageMeta() {
		$post_id = $this->checkSaveRequest( 'bulk-media' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in checkSaveRequest().

		$values = array();
		if ( isset( $_POST['alt_text'] ) ) {
			$values['alt_text'] = sanitize_text_field( wp_unslash( $_POST['alt_text'] ) );
		}
		if ( isset( $_POST['caption'] ) ) {
			$values['caption'] = sanitize_text_field( wp_unslash( $_POST['caption'] ) );
		}
		if ( isset( $_POST['description'] ) ) {
			$values['description'] = sanitize_textarea_field( wp_unslash( $_POST['description'] ) );
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing

		seopress_pro_get_service( 'Completions' )->saveImageMeta( $post_id, $values );

		wp_send_json_success();
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

		$data = seopress_pro_get_service( 'Completions' )->generateTitlesDesc( $post_id, 'title', $lang, ! $this->isReview() );

		$this->sendGenerationResult( $data, $post_id );
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

		$data = seopress_pro_get_service( 'Completions' )->generateTitlesDesc( $post_id, 'desc', $lang, ! $this->isReview() );

		$this->sendGenerationResult( $data, $post_id );
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
		$data = seopress_pro_get_service( 'Completions' )->generateImgAltText( $post_id, 'image_meta', $lang, true, null, null, ! $this->isReview() );

		$this->sendGenerationResult( $data, $post_id );
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
		$data = seopress_pro_get_service( 'Completions' )->generateImgAltText( $post_id, 'image_meta', $lang, false, null, null, ! $this->isReview() );

		$this->sendGenerationResult( $data, $post_id );
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

		$data = seopress_pro_get_service( 'Completions' )->generateImgAltText( $post_id, 'image_meta', $lang, true, null, array( 'alt_text' ), ! $this->isReview() );

		$this->sendGenerationResult( $data, $post_id );
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

		$data = seopress_pro_get_service( 'Completions' )->generateImgAltText( $post_id, 'image_meta', $lang, true, null, array( 'caption' ), ! $this->isReview() );

		$this->sendGenerationResult( $data, $post_id );
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

		$data = seopress_pro_get_service( 'Completions' )->generateImgAltText( $post_id, 'image_meta', $lang, true, null, array( 'description' ), ! $this->isReview() );

		$this->sendGenerationResult( $data, $post_id );
	}
}
