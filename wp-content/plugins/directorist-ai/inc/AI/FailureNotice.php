<?php
/**
 * Safe task failures shared by source adapters and conversation surfaces.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

/** Describe the failed step without exposing managed routing or promising a free request. */
final class FailureNotice {
	/** Explain the actual application allowance without exposing account balances. */
	public static function capacity_message( array $boundaries, bool $visitor = false ): string {
		$daily = array_intersect( $boundaries, array( 'budget_scope_actor_day', 'budget_scope_profile_day', 'budget_scope_site_day', 'budget_scope_task_site_day', 'budget_scope_task_visitor_day' ) );
		if ( $visitor ) { return $daily ? 'This site has reached its AI allowance for today. Please try again later or contact the site owner.' : 'I could not complete this answer within its processing allowance. Your conversation is retained.'; }
		if ( $daily ) {
			$subject = in_array( 'budget_scope_actor_day', $boundaries, true ) ? 'Your daily website AI allowance' : ( in_array( 'budget_scope_profile_day', $boundaries, true ) ? 'The daily AI allowance for this feature' : 'This website’s daily AI allowance' );
			return $subject . ' was reached. Provider credits may still be available. Review AI & credits limits. Your content and conversation are retained.';
		}
		return 'This request reached its processing allowance. Review its saved results before starting another request. Your content and conversation are retained.';
	}

	/** Exact notice for a rejected layout plan before any approval or native effect. */
	public static function layout_planning_message(): string {
		return 'This layout could not be prepared for review. Your content and conversation are retained.';
	}

	/** Exact notice for an unsuccessful current-canvas plan before any native application. */
	public static function editor_planning_message(): string {
		return 'AI could not prepare this change for the open page. Your current layout is unchanged and your conversation is retained.';
	}

	/**
	 * Keep only closed connector diagnostics, never provider prose or credentials.
	 *
	 * @param array $error Native connector diagnostic fields.
	 * @return array Public error classification.
	 */
	public static function wordpress_diagnostic( array $error ): array {
		$code    = $error['code'] ?? null;
		$allowed = array( 'network_error', 'server_error', 'request_timeout', 'http_request_failed', 'prompt_network_error', 'prompt_client_error', 'prompt_upstream_server_error', 'prompt_token_limit_reached', 'prompt_invalid_argument', 'prompt_builder_error', 'prompt_prevented', 'provider_not_found', 'model_not_found' );
		if ( ! is_string( $code ) || ! in_array( $code, $allowed, true ) ) {
			return array(
				'code'        => 'unknown_provider_error',
				'http_status' => null,
			);
		}
		$status = $error['http_status'] ?? null;
		$result = array(
			'code'        => $code,
			'http_status' => is_int( $status ) && $status >= 400 && $status <= 599 ? $status : null,
		);
		if ( 'prompt_client_error' === $code && 404 === $result['http_status'] && 'model_unavailable' === ( $error['reason'] ?? null ) ) {
			$result['reason'] = 'model_unavailable';
		}
		return $result;
	}

	/**
	 * Explain a recognized connector failure using application-owned wording.
	 *
	 * @param array $error Native connector diagnostic fields.
	 * @return string|null Public notice for a recognized error.
	 */
	private static function wordpress_notice( array $error ): ?string {
		$error  = self::wordpress_diagnostic( $error );
		$code   = $error['code'];
		$status = $error['http_status'];
		if ( 'model_not_found' === $code || 'model_unavailable' === ( $error['reason'] ?? null ) ) {
			$notice = 'The selected AI model is unavailable for this provider account. Choose an available model in AI connections.';
		} elseif ( 'provider_not_found' === $code ) {
			$notice = 'The selected WordPress AI provider is unavailable. Check that its connector is enabled and configured in AI connections.';
		} elseif ( 'request_timeout' === $code || ( 'prompt_client_error' === $code && 408 === $status ) ) {
			$notice = 'The AI provider did not respond in time. Try again, or ask the administrator to check the connection.';
		} elseif ( in_array( $code, array( 'network_error', 'http_request_failed', 'prompt_network_error' ), true ) ) {
			$notice = 'The site could not reach the selected WordPress AI provider. Try again, or ask the administrator to check the connection.';
		} elseif ( 'prompt_client_error' === $code && 429 === $status ) {
			$notice = 'The AI provider is limiting requests or has reached its quota. Try again later, or check the provider account limits.';
		} elseif ( 'prompt_client_error' === $code && in_array( $status, array( 401, 403 ), true ) ) {
			$notice = 'The AI provider refused access. Check the API credentials, account permissions and model access in AI connections.';
		} elseif ( 'prompt_client_error' === $code && 404 === $status ) {
			$notice = 'The selected AI model or API endpoint could not be found. Review the model and connection settings in AI connections.';
		} elseif ( in_array( $code, array( 'server_error', 'prompt_upstream_server_error' ), true ) ) {
			$notice = 'The AI provider is temporarily unavailable. Try again shortly.';
		} else {
			return null;
		}
		return $notice . ' Your existing content and previous results were retained.';
	}

	/**
	 * Return a notice for a known provider failure, leaving domain errors to their handlers.
	 *
	 * @param string $code Stable error code.
	 * @param string $source Captured source.
	 * @param string $task Required modality.
	 * @param array  $diagnostic Closed native connector diagnostics.
	 */
	public static function message( string $code, string $source = 'wordpress', string $task = 'text', array $diagnostic = array() ): ?string { // phpcs:ignore WordPress.WP.CapitalPDangit -- Internal source identifier.
		if ( 'page_redesign_unavailable' === $code ) {
			return __( 'I could not verify the heading, search button, and listing card controls together on the active page. No changes were prepared.', 'directorist-ai' );
		}
		if ( 'builder_preparation_interrupted' === $code ) {
			return 'The editor could not finish preparing this request. Your current layout is unchanged and your conversation is retained. Start again to retry.';
		}
		if ( 'generation_stalled' === $code ) { return 'AI stopped repeating a request that was not making progress. Your content and conversation are retained. Refine the request before continuing.'; }
		if ( 'input_context_too_large' === $code ) {
			return 'This request contains more detail than AI could prepare in one step. Try one section or task at a time. Your content and conversation are retained.';
		}
		$requirement = 'image' === $task ? 'image generation' : ( 'vision' === $task ? 'image input and text generation' : 'text generation and tool/function calling' );
		if ( 'unsupported_provider_capability' === $code && 'managed' !== $source ) {
			return 'The selected WordPress connector cannot run this request. Requires ' . $requirement . '. No generation was started for this step. Ask the administrator to check AI connections.';
		}
		if ( 'provider_capability_unknown' === $code ) {
			return 'WordPress could not verify support for this request. Requires ' . $requirement . '. No generation was started for this step. Ask the administrator to check the selected connector.';
		}
		if ( in_array( $code, array( 'unsupported_operation', 'unsupported_capability', 'unsupported_image', 'unsupported_provider_capability' ), true ) ) {
			return 'Directorist AI could not start this ' . ( 'image' === $task ? 'image generation' : ( 'vision' === $task ? 'image understanding' : 'AI' ) ) . ' request. Ask the administrator to check the service connection. The selected AI source was retained.';
		}
		if ( in_array( $code, array( 'managed_pending', 'managed_uncertain', 'managed_transport_uncertain' ), true ) ) {
			return 'The Directorist AI request is unconfirmed. Its existing receipt is retained. Do not repeat it while its status is being checked.';
		}
		if ( in_array( $code, array( 'managed_failed', 'managed_request_rejected' ), true ) ) {
			return 'Directorist AI could not complete this request. Its available receipt is retained. Check the request status before trying again.';
		}
		if ( in_array( $code, array( 'managed_unavailable', 'invalid_token' ), true ) ) {
			return 'Directorist AI is not connected. Ask the administrator to check the license and service connection.';
		}
		if ( 'insufficient_balance' === $code ) {
			return 'Your shared AI credits are insufficient. Ask the administrator to buy credits in Credits & usage.';
		}
		if ( 'invalid_output' === $code ) {
			return 'The AI result could not be validated. No returned action was applied. Your existing content and previous results were retained.';
		}
		if ( 'output_truncated' === $code ) {
			return 'The AI response reached its configured token limit. Your request and existing results were retained.';
		}
		if ( 'provider_unavailable' === $code ) {
			if ( 'wordpress' === $source ) { // phpcs:ignore WordPress.WP.CapitalPDangit -- Internal source identifier.
				$notice = self::wordpress_notice( $diagnostic );
				if ( null !== $notice ) {
					return $notice; }
			}
			return ( 'managed' === $source ? 'Directorist AI' : 'The selected WordPress connector' ) . ' could not complete this request. Your existing content and previous results were retained.';
		}
		return null;
	}

	/**
	 * Return a short visitor notice without account, billing or provider details.
	 *
	 * @param string $code Stable runtime error.
	 */
	public static function visitor_message( string $code ): string {
		if ( 'generation_stalled' === $code ) { return 'I stopped a request that was repeating without progress. Your conversation is saved. Try asking in a different way.'; }
		if ( 'managed_uncertain' === $code ) {
			return 'The AI service could not confirm this answer. Your conversation is saved. Please contact the site owner if this continues.';
		}
		if ( 'input_context_too_large' === $code ) {
			return 'I could not prepare this search. Your conversation is saved. Please contact the site owner if this continues.';
		}
		if ( in_array( $code, array( 'managed_pending', 'managed_uncertain', 'managed_transport_uncertain' ), true ) ) {
			return 'Your request is still being checked. Please wait before trying again.';
		}
		if ( in_array( $code, array( 'budget_exhausted', 'output_truncated', 'input_context_too_large' ), true ) ) {
			return 'I could not finish this answer. Try a shorter question or ask again later.';
		}
		if ( in_array( $code, array( 'stale_evidence', 'grounded_response_required' ), true ) ) {
			return 'I could not verify an answer. Try a more specific question.';
		}
		return 'I could not answer your question. Please try again. If this continues, contact the site owner.';
	}

	/**
	 * Resolve the exact server-produced notice for a captured runtime failure.
	 *
	 * @param string $code Stable runtime error.
	 * @param array  $route Captured AI route.
	 * @param string $task Required modality.
	 * @param array  $diagnostic Closed native connector diagnostics.
	 */
	public static function runtime_message( string $code, array $route = array(), string $task = 'text', array $diagnostic = array() ): string {
		$source = Settings::for_task( $route, $task )['source'] ?? 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit -- Internal source identifier.
		$notice = self::message( $code, $source, $task, $diagnostic );
		if ( null !== $notice ) {
			return $notice;
		}
		if ( 'provider_configuration_changed' === $code ) {
			return 'The configured AI model changed after this turn started. Start a new request using the updated configuration.';
		}
		if ( 'storage_unavailable' === $code ) {
			return 'Runtime storage is incompatible with this plugin version. New AI requests and effects are disabled. Your existing records were retained.';
		}
		if ( 'grounded_response_required' === $code ) {
			return 'I could not produce a validated source-backed answer. Please clarify the requested search or comparison.';
		}
		if ( 'stale_evidence' === $code ) {
			return 'The answer sources changed before delivery. Please run the lookup again.';
		}
		if ( 'budget_exhausted' === $code ) {
			return 'An AI usage limit was reached. Website limits can apply even when provider credits remain. Your existing messages and artifacts were retained.';
		}
		if ( 'unsupported_dependency' === $code ) {
			return 'This request could not use a required feature. Your content and conversation are retained.';
		}
		if ( 'usage_bound_exceeded' === $code ) {
			return 'The AI response exceeded its reserved usage limit. Its usage was recorded and no returned action was executed.';
		}
		return 'The AI request could not be completed. Your request and existing artifacts were retained.';
	}

	/**
	 * Keep the live listing failure and its restored form projection consistent.
	 *
	 * Only the recorded native image step grants an image-specific explanation.
	 * A media editor may also fail during text planning, before image generation.
	 *
	 * @param string $code Safe runtime error code.
	 * @param object $checkpoint Server-owned durable run checkpoint.
	 * @return string User-facing failure without private provider diagnostics.
	 */
	public static function listing_message( string $code, object $checkpoint ): string {
		$route    = json_decode( wp_json_encode( $checkpoint->ai_route ?? array() ), true );
		$boundary = is_array( $checkpoint->failure_boundary ?? null ) ? $checkpoint->failure_boundary : array();
		$results  = is_array( $checkpoint->tool_results ?? null ) ? $checkpoint->tool_results : array();
		$last     = $results ? end( $results ) : null;
		if ( isset( $checkpoint->failure_boundary ) && 'listing.form_image' === ( $last->response->operation ?? null ) ) {
			$source = Settings::for_task( $route, 'image' )['source'] ?? 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit -- Internal source identifier.
			return self::image_message( $code, $source, $boundary );
		}
		if ( 'generation_stalled' === $code ) {
			return 'I could not finish this change. Try a more specific instruction for the selected fields, or choose another field. Your listing is unchanged and your conversation is saved.';
		}
		if ( in_array( $code, array( 'invalid_input', 'evidence_stale', 'stale_evidence', 'context_unavailable' ), true ) ) {
			return 'The listing details or directory changed. Review the current fields before starting a new request.';
		}
		if ( 'scope_mismatch' === $code ) {
			return 'These changes do not match the selected listing fields. Choose the field you want to edit and try again. Your listing is unchanged.';
		}
		if ( 'budget_exhausted' === $code && $boundary ) {
			return self::capacity_message( $boundary );
		}
		return self::runtime_message( $code, $route, 'text', (array) ( $checkpoint->wordpress_error ?? array() ) );
	}

	/**
	 * Describe an image failure while preserving the native listing editing path.
	 *
	 * @param string $code Stable error code.
	 * @param string $source Captured image source.
	 * @param array  $boundary Safe server-authored allowance scope codes.
	 */
	public static function image_message( string $code, string $source, array $boundary = array() ): string {
		if ( 'budget_exhausted' === $code ) {
			$scope    = in_array( 'budget_scope_actor_day', $boundary, true ) ? 'daily user' : ( in_array( 'budget_scope_profile_day', $boundary, true ) ? 'daily task-profile' : ( array_intersect( $boundary, array( 'budget_scope_site_day', 'budget_scope_task_site_day' ) ) ? 'daily site' : ( in_array( 'budget_scope_task_visitor_day', $boundary, true ) ? 'daily visitor' : 'current request' ) ) );
			$recovery = str_starts_with( $scope, 'daily' ) ? 'The daily allowance resets at ' . gmdate( 'Y-m-d 00:00', strtotime( 'tomorrow UTC' ) ) . ' UTC' . '.' : 'Start a new image request to use its own bounded task allowance.';
			return 'Image generation reached the ' . $scope . ' AI usage limit. ' . $recovery . ' Your conversation and existing images are retained. You can also use the native uploader.';
		}
		if ( in_array( 'image_gallery_limit', $boundary, true ) ) {
			return 'This gallery has reached its native image count limit. Replace an existing gallery image or remove one before adding another. Existing images are unchanged.'; }
		if ( in_array( 'image_gallery_capacity', $boundary, true ) ) {
			return 'This gallery has no remaining upload space. Remove an unused gallery image or use a smaller image. Existing images are unchanged.'; }
		if ( in_array( 'image_size_limit', $boundary, true ) ) {
			return 'The image could not fit this directory’s native upload size limit. Try a smaller image or use the native uploader. Existing images are unchanged.'; }
		if ( in_array( 'image_processing_unavailable', $boundary, true ) ) {
			return 'The site could not prepare this image for the native uploader. Ask the administrator to check image processing, or use the native uploader. Existing images are unchanged.'; }
		if ( 'invalid_input' === $code ) {
			return 'The generated image could not fit this directory’s native image requirements. Your listing is unchanged. Try a smaller illustration, remove an unused gallery image, or use the native uploader.';
		}
		return ( self::message( $code, $source, 'image' ) ?? 'The selected AI source could not produce a usable image.' ) . ' Existing listing content and images are unchanged. You can use the native uploader.';
	}
}
