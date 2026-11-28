<?php
/**
 * Native Directorist core / Advanced Review 1.0.2 reviewed submissions.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat\Workflows;

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.FunctionCommentThrowTag, Squiz.Commenting.VariableComment -- Methods implement the documented native workflow adapter seam.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAdvancedReview\App\Repositories\ReviewRepository;
use DirectoristAdvancedReview\App\DTO\ReviewDTO;

/** Source-versioned native adapter with explicit preparation and native readback. */
final class ReviewAdapter implements NativeWorkflowAdapter {
	public function id(): string {
		return 'review.submit'; }
	public function revision(): string {
		return 'directorist-8.9.4/advanced-review-1.0.2-v1'; }
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'    => array(
					'type'      => 'string',
					'maxLength' => 120,
				),
				'feedback' => array(
					'type'      => 'string',
					'maxLength' => 1000,
				),
				'rating'   => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 5,
				),
				'ratings'  => array(
					'type'                 => 'object',
					'maxProperties'        => 12,
					'additionalProperties' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 5,
					),
				),
				'name'     => array(
					'type'      => 'string',
					'maxLength' => 120,
				),
				'email'    => array(
					'type'      => 'string',
					'maxLength' => 120,
				),
				'consent'  => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
	}
	public function available( Context $context, int $directory, int $listing ): array {
		try {
			NativeWorkflowScope::listing( $context, $directory, $listing, 'review' );
			if ( ! defined( 'ATBDP_VERSION' ) || '8.9.4' !== ATBDP_VERSION ) {
				return array(
					'available' => false,
					'reason'    => 'This Directorist review engine has no certified chat adapter.',
				); }
			if ( $context->actor()->is_guest() && ! directorist_can_guest_review() ) {
				return array(
					'available' => false,
					'reason'    => 'Sign in before reviewing this listing.',
				); }
			$this->policy( $directory, $listing );
			return array(
				'available' => true,
				'reason'    => '',
			);
		} catch ( ContractException $error ) {
			return array(
				'available' => false,
				'reason'    => $error->getMessage(),
			); }
	}
	public function prepare( Context $context, int $directory, int $listing, object $values ): array {
		NativeWorkflowScope::inputs( $values, array_keys( $this->input_schema()['properties'] ) );
		$available = $this->available( $context, $directory, $listing );
		if ( ! $available['available'] ) {
			return NativeWorkflowScope::decision( 'unavailable', array(), $values, null, $available['reason'] ); }
		$manifest = NativeWorkflowScope::listing( $context, $directory, $listing, 'review' );
		$policy   = $this->policy( $directory, $listing );
		$fields   = array(
			array(
				'id'        => 'title',
				'label'     => 'Review title',
				'type'      => 'text',
				'required'  => true,
				'maxLength' => 120,
			),
			array(
				'id'        => 'feedback',
				'label'     => 'Your review',
				'type'      => 'textarea',
				'required'  => true,
				'maxLength' => 1000,
			),
		);
		$fields[] = $policy['criteria'] ? array(
			'id'       => 'ratings',
			'label'    => 'Ratings',
			'type'     => 'ratings',
			'required' => true,
			'criteria' => $policy['criteria'],
		) : array(
			'id'       => 'rating',
			'label'    => 'Rating',
			'type'     => 'rating',
			'required' => true,
			'min'      => 1,
			'max'      => 5,
		);
		if ( $context->actor()->is_guest() ) {
			$fields[] = array(
				'id'        => 'name',
				'label'     => 'Name',
				'type'      => 'text',
				'required'  => true,
				'maxLength' => 120,
			);
			$fields[] = array(
				'id'        => 'email',
				'label'     => 'Email',
				'type'      => 'email',
				'required'  => true,
				'maxLength' => 120,
			);
		}
		if ( $policy['consent'] ) {
			$fields[] = array(
				'id'       => 'consent',
				'label'    => $policy['consent_label'],
				'type'     => 'checkbox',
				'required' => true,
			); }
		foreach ( array(
			'title'    => 120,
			'feedback' => 1000,
			'name'     => 120,
			'email'    => 120,
		) as $key => $max ) {
			if ( isset( $values->$key ) && ( ! is_string( $values->$key ) || mb_strlen( $values->$key ) > $max || wp_strip_all_tags( $values->$key ) !== trim( $values->$key ) ) ) {
				throw new ContractException( 'invalid_input', 'The review text fields are invalid.' ); }
		}
		if ( isset( $values->consent ) && ! is_bool( $values->consent ) ) {
			throw new ContractException( 'invalid_input', 'Review consent must be explicitly selected.' ); }
		if ( $policy['criteria'] && isset( $values->rating ) ) {
			throw new ContractException( 'invalid_input', 'Rate the native review criteria individually.' ); }
		if ( ! $policy['criteria'] && isset( $values->ratings ) ) {
			throw new ContractException( 'invalid_input', 'This native form uses one overall rating.' ); }
		$missing = empty( $values->title ) || empty( $values->feedback ) || '' === trim( $values->title ) || '' === trim( $values->feedback ) || ( $policy['consent'] && true !== ( $values->consent ?? null ) );
		if ( $policy['criteria'] ) {
			if ( ! isset( $values->ratings ) ) {
				$missing = true; } else {
				if ( ! $values->ratings instanceof \stdClass ) {
					throw new ContractException( 'invalid_input', 'Native review criteria are invalid.' ); }
				if ( ! get_object_vars( $values->ratings ) ) {
					return NativeWorkflowScope::decision( 'needs_input', $fields, $values, null, 'Rate each native review criterion before confirming.' ); }
				$keys   = array_map( 'strval', array_keys( get_object_vars( $values->ratings ) ) );
				$wanted = array_column( $policy['criteria'], 'id' );
				sort( $keys );
				sort( $wanted );
				if ( $keys !== $wanted ) {
					throw new ContractException( 'invalid_input', 'Rate exactly the currently configured criteria.' ); }
				foreach ( get_object_vars( $values->ratings ) as $rating ) {
					$this->rating( $rating ); }
				}
		} elseif ( ! isset( $values->rating ) ) {
			$missing = true;
		} else {
			$this->rating( $values->rating ); }
		if ( $context->actor()->is_guest() ) {
			$missing = $missing || empty( $values->name ) || empty( $values->email );
			if ( ! empty( $values->email ) && ! is_email( $values->email ) ) {
				throw new ContractException( 'invalid_input', 'Enter a valid review email.' ); }
		}
		$email = $context->actor()->is_guest() ? ( $values->email ?? '' ) : wp_get_current_user()->user_email;
		if ( ( 'advanced' === $policy['engine'] && ( new ReviewRepository() )->is_user_reviewed( $listing, $context->actor()->is_guest() ? $email : '' ) ) || ( $email && directorist_user_review_exists( $email, $listing ) && ! directorist_is_multiple_review_enabled() ) ) {
			return NativeWorkflowScope::decision( 'unavailable', $fields, $values, null, 'You have already reviewed this listing.' ); }
		if ( $missing ) {
			return NativeWorkflowScope::decision( 'needs_input', $fields, $values, null, 'Complete the review fields, then check your draft before submitting.' ); }
		$state    = $policy['immediate'] && ( ! $context->actor()->is_guest() || 'advanced' === $policy['engine'] ) ? 'published' : 'pending';
		$proposal = (object) array(
			'inputs'       => clone $values,
			'engine'       => $policy['engine'],
			'policy_hash'  => hash( 'sha256', wp_json_encode( $policy ) ),
			'title'        => $manifest['title'],
			'url'          => $manifest['url'],
			'native_state' => $state,
		);
		return NativeWorkflowScope::decision( 'ready', $fields, $values, $proposal, 'Review your rating and text. Submit only after your explicit confirmation.' );
	}
	public function execute( Context $context, int $directory, int $listing, object $proposal, string $operation ): array {
		$marker = NativeWorkflowScope::marker( $operation );
		return NativeWorkflowScope::locked(
			'review:' . $listing,
			function () use ( $context, $directory, $listing, $proposal, $marker ): array {
				$context->assert_execution_identity();
				$existing = $this->operation_comment( $context, $listing, $marker );
				if ( $existing ) {
					return $this->receipt( $context, $directory, $listing, $existing ); }
				$current = $this->prepare( $context, $directory, $listing, $proposal->inputs ?? (object) array() );
				if ( 'ready' !== $current['status'] || wp_json_encode( $current['proposal'] ) !== wp_json_encode( $proposal ) ) {
					throw new ContractException( 'stale_quote', 'The native review form or eligibility changed. Review your submission again.' ); }
				$inputs = $proposal->inputs;
				$owner  = hash( 'sha256', $context->actor()->owner()->reference() );
				if ( 'advanced' === $proposal->engine ) {
					$dto     = ( new ReviewDTO() )->set_post_id( $listing )->set_title( $inputs->title )->set_content( $inputs->feedback )->set_user_id( $context->actor()->user_id() )->set_attachment_ids( array() );
					$ratings = isset( $inputs->ratings ) ? get_object_vars( $inputs->ratings ) : array();
					$dto->set_ratings( $ratings )->set_rating( $ratings ? array_sum( $ratings ) / count( $ratings ) : $inputs->rating );
					if ( $context->actor()->is_guest() ) {
						$dto->set_name( $inputs->name )->set_email( $inputs->email ); }
					$inject = static function ( int $id, \WP_Comment $comment ) use ( $listing, $marker, $owner ): void {
						if ( $listing === (int) $comment->comment_post_ID && 'review' === $comment->comment_type ) {
							add_comment_meta( $id, 'directorist_ai_operation', $marker, true );
							add_comment_meta( $id, 'directorist_ai_owner', $owner, true ); }
					};
					add_action( 'wp_insert_comment', $inject, 1, 2 );
					try {
						$id = (int) ( new ReviewRepository() )->create( $dto );
					} finally {
							remove_action( 'wp_insert_comment', $inject, 1 ); }
					\Directorist\Review\Comment::clear_transients( $listing );
				} else {
					$data     = array(
						'comment_post_ID'          => $listing,
						'comment_parent'           => 0,
						'comment'                  => $inputs->feedback,
						'rating'                   => $inputs->rating,
						'author'                   => $inputs->name ?? '',
						'email'                    => $inputs->email ?? '',
						'directorist-gdpr-consent' => ! empty( $inputs->consent ),
					);
					$previous = $_POST;
					$_POST    = $data;
					$inject   = static function ( array $comment ) use ( $listing, $marker, $owner ): array {
						if ( $listing === (int) $comment['comment_post_ID'] ) {
							$comment['comment_meta']['directorist_ai_operation'] = $marker;
							$comment['comment_meta']['directorist_ai_owner']     = $owner; }
						return $comment;
					};
					add_filter( 'preprocess_comment', $inject, 100 );
					try {
						$comment = wp_handle_comment_submission( $data );
					} finally {
							$_POST = $previous;
							remove_filter( 'preprocess_comment', $inject, 100 ); }
					if ( is_wp_error( $comment ) ) {
						throw new ContractException( 'native_rejected', wp_strip_all_tags( $comment->get_error_message() ) ); }
					$id = (int) $comment->comment_ID;
				}
				$comment = $this->operation_comment( $context, $listing, $marker );
				if ( ! $comment || (int) $comment->comment_ID !== $id ) {
					throw new ContractException( 'native_unknown', 'The native review result requires reconciliation before any retry.' ); }
				return $this->receipt( $context, $directory, $listing, $comment );
			}
		);
	}
	public function reconcile( Context $context, int $directory, int $listing, array $identity ): array {
		$context->assert_execution_identity();
		$comment = isset( $identity['native_id'] ) ? get_comment( (int) $identity['native_id'] ) : ( isset( $identity['operation_key'] ) ? $this->operation_comment( $context, $listing, NativeWorkflowScope::marker( $identity['operation_key'] ) ) : null );
		return $comment ? $this->receipt( $context, $directory, $listing, $comment ) : array(
			'type'         => 'review',
			'status'       => 'unknown',
			'native_state' => 'unknown',
			'summary'      => 'No exact native review receipt is available. Do not resubmit automatically.',
		);
	}
	/** Native numeric criterion IDs use their exact decimal JSON object key. */
	private function criterion_id( $id ): string {
		if ( is_int( $id ) && $id > 0 ) {
			$id = (string) $id;
		}
		if ( ! is_string( $id ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/D', $id ) ) {
			throw new ContractException( 'unsupported', 'The native review criteria are unavailable.' );
		}
		return $id;
	}

	private function policy( int $directory, int $listing ): array {
		$advanced = in_array( 'directorist-advanced-review/directorist-advanced-review.php', (array) get_option( 'active_plugins', array() ), true ) || ( is_multisite() && isset( get_site_option( 'active_sitewide_plugins', array() )['directorist-advanced-review/directorist-advanced-review.php'] ) );
		if ( $advanced && ( ! function_exists( 'directorist_advanced_review_version' ) || '1.0.2' !== directorist_advanced_review_version() || ! class_exists( ReviewRepository::class ) ) ) {
			throw new ContractException( 'unsupported', 'This installed advanced review engine has no certified chat adapter.' ); }
		$section = \Directorist\Directorist_Single_Listing::instance( $listing )->get_review_section_data();
		$data    = $section['section_data'] ?? array();
		$consent = false;
		$label   = 'I agree to the native review terms.';
		foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) && 'review_consent' === ( $field['widget_child_name'] ?? '' ) && ! empty( $field['enable_gdpr_consent'] ) ) {
				$consent = true;
				if ( ! empty( $field['consent_label'] ) ) {
					$label = wp_strip_all_tags( $field['consent_label'] ); }
			}
		}
		$criteria = array();
		if ( $advanced ) {
			$native = ( new ReviewRepository() )->get_review_criterias( $listing );
			if ( ! empty( $native['is_multi_criteria'] ) ) {
				foreach ( (array) ( $native['criterias'] ?? array() ) as $criterion ) {
					if ( ! is_array( $criterion ) || ! is_string( $criterion['value'] ?? null ) ) {
						throw new ContractException( 'unsupported', 'The native review criteria are unavailable.' ); }
					$criteria[] = array(
						'id'    => $this->criterion_id( $criterion['id'] ?? null ),
						'label' => wp_strip_all_tags( $criterion['value'] ),
						'min'   => 1,
						'max'   => 5,
					);
				}
				if ( ! $criteria || count( $criteria ) > 12 || count( array_unique( array_column( $criteria, 'id' ) ) ) !== count( $criteria ) ) {
					throw new ContractException( 'unsupported', 'The native criteria configuration is unsupported.' ); }
			}
		} elseif ( ! empty( $data['rating_type'] ) && 'single' !== $data['rating_type'] ) {
			throw new ContractException( 'unsupported', 'This review rating engine needs its native form.' ); }
		return array(
			'engine'        => $advanced ? 'advanced' : 'core',
			'criteria'      => $criteria,
			'consent'       => $consent,
			'consent_label' => $label,
			'immediate'     => directorist_is_immediate_review_approve_enabled(),
			'guest'         => directorist_is_guest_review_enabled(),
			'owner'         => directorist_is_owner_review_enabled(),
			'multiple'      => directorist_is_multiple_review_enabled(),
		);
	}
	private function rating( $value ): void {
		if ( ! is_int( $value ) || $value < 1 || $value > 5 ) {
			throw new ContractException( 'invalid_input', 'Each native rating must be an integer from one to five.' ); } }
	private function operation_comment( Context $context, int $listing, string $marker ): ?\WP_Comment {
		$comments = get_comments(
			array(
				'post_id'    => $listing,
				'type'       => 'review',
				'status'     => 'all',
				'number'     => 2,
				'meta_key'   => 'directorist_ai_operation',
				'meta_value' => $marker,
			)
		);
		if ( count( $comments ) > 1 ) {
			throw new ContractException( 'native_unknown', 'The native review operation has ambiguous receipts.' ); }
		return $comments ? $comments[0] : null;
	}
	private function receipt( Context $context, int $directory, int $listing, \WP_Comment $comment ): array {
		$context->assert_execution_identity();
		if ( $listing !== (int) $comment->comment_post_ID || 'review' !== $comment->comment_type || (int) $comment->user_id !== $context->actor()->user_id() || get_comment_meta( $comment->comment_ID, 'directorist_ai_owner', true ) !== hash( 'sha256', $context->actor()->owner()->reference() ) ) {
			throw new ContractException( 'unauthorized', 'This review is unavailable for this account.' ); }
		$manifest = NativeWorkflowScope::listing( $context, $directory, $listing, 'view' );
		$state    = array(
			'1'     => 'published',
			'0'     => 'pending',
			'spam'  => 'spam',
			'trash' => 'trash',
		)[ (string) $comment->comment_approved ] ?? 'unknown';
		return array(
			'type'         => 'review',
			'status'       => 'unknown' === $state ? 'unknown' : 'succeeded',
			'native_id'    => (int) $comment->comment_ID,
			'native_state' => $state,
			'title'        => $manifest['title'],
			'url'          => $manifest['url'],
			'rating'       => (float) get_comment_meta( $comment->comment_ID, 'rating', true ),
			'summary'      => 'published' === $state ? 'Your native review is published.' : ( 'pending' === $state ? 'Your native review is awaiting moderation.' : 'The native review is held by the current moderation state.' ),
		);
	}
}
