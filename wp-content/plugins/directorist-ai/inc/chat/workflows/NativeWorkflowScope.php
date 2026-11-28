<?php
/**
 * Shared native identity, public layout and target serialization guards.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat\Workflows;

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.FunctionCommentThrowTag, Squiz.Commenting.VariableComment -- Methods implement the documented native workflow adapter seam.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Chat\ListingActions;
use DirectoristAIPro\Validation\ContractException;

/** Source-versioned native adapter with explicit preparation and native readback. */
final class NativeWorkflowScope {
	public static function listing( Context $context, int $directory, int $listing, string $action ): array {
		$context->assert_execution_identity();
		$manifest = ListingActions::describe( $context, $directory, $listing );
		if ( ! in_array( $action, array_column( $manifest['actions'], 'id' ), true ) ) {
			throw new ContractException( 'unsupported', 'This native workflow is unavailable for this listing.' );
		}
		return $manifest;
	}

	public static function marker( string $operation ): string {
		if ( ! preg_match( '/^[a-zA-Z0-9_.:-]{1,128}$/D', $operation ) ) {
			throw new ContractException( 'invalid_input', 'The native operation identity is unavailable.' );
		}
		return hash( 'sha256', get_current_blog_id() . "\0" . $operation );
	}

	/** Serialize our native submissions; unrelated native writers retain their native guarantees. */
	public static function locked( string $target, callable $callback ) {
		global $wpdb;
		$key = 'dai_native_' . substr( hash( 'sha256', $wpdb->prefix . ':' . $target ), 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $key ) ) ) {
			throw new ContractException( 'native_busy', 'This request is being updated. Please try again.' );
		}
		try {
			return $callback();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
	}

	public static function decision( string $status, array $fields, object $inputs, ?object $proposal, string $summary ): array {
		return array(
			'status'   => $status,
			'fields'   => $fields,
			'inputs'   => $inputs,
			'proposal' => $proposal,
			'summary'  => $summary,
		);
	}

	/** Strict partial inputs keep clarification useful without accepting authority fields. */
	public static function inputs( object $values, array $allowed ): void {
		foreach ( get_object_vars( $values ) as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new ContractException( 'invalid_input', 'An unsupported native task field was supplied.' );
			}
		}
	}
}
