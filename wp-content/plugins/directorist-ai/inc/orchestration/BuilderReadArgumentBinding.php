<?php
/**
 * Model read arguments must retain the current editor's immutable target.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Builder\BuilderConversationTarget;
use DirectoristAIPro\Capabilities\{Context, Gateway};
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\UI\EditorWorkingCopy;
use DirectoristAIPro\Validation\{ContractException, JsonCanonicalizer, JsonDecoder};

/** Feedback for bad model input never substitutes for native authorization. */
final class BuilderReadArgumentBinding {
	/**
	 * Classify only a malformed model read target after positive current checks.
	 *
	 * Valid calls and any uncertain authority continue through the original gateway.
	 * Original argument bytes are neither rewritten nor executed under another scope.
	 *
	 * @param ModelToolCall $call Original untrusted model call.
	 * @param array         $catalog Current server function-to-operation map.
	 * @param Gateway       $gateway Existing strict native schema validator.
	 * @param Context       $context Current actor/run authority.
	 * @param Database      $db Current run storage.
	 * @param array         $run Current leased run, used only to compare immutable context.
	 * @return array|null Invalid-input feedback, or null for ordinary native execution.
	 * @throws ContractException Stale current run identity or context.
	 */
	public static function for_call( ModelToolCall $call, array $catalog, Gateway $gateway, Context $context, Database $db, array $run ): ?array {
		$operation = $catalog[ $call->name ]['operation_id'] ?? null;
		if ( ! is_string( $run['context_json'] ?? null ) ) {
			return null;
		}
		if ( 'builder' !== $context->profile() || ! in_array( $operation, array( 'builder.inspect', 'builder.describe' ), true ) ) {
			return null;
		}
		$arguments = $call->arguments instanceof \stdClass ? get_object_vars( $call->arguments ) : $call->arguments;
		if ( ! is_array( $arguments ) || array_keys( $arguments ) !== array( 'input_json' ) || ! is_string( $arguments['input_json'] ) ) {
			return null;
		}
		try {
			$input   = $gateway->validate_input( $operation, $arguments['input_json'] );
			$binding = ( new JsonDecoder( 65536, 12 ) )->decode( $run['context_json'] ?? '' );
		} catch ( ContractException $error ) {
			return null;
		}
		if ( ! $binding instanceof \stdClass || ! self::mismatch( $operation, $input, $binding ) ) {
			return null;
		}
		$context->assert_execution_identity();
		$current = BuilderConversationTarget::active_context( $db, $context );
		if ( ! is_string( $current ) || ! hash_equals( $run['context_json'], $current ) ) {
			return null;
		}
		if ( ! self::current_editor_authority( $context, $binding ) ) {
			return null;
		}
		$context->assert_execution_identity();
		return array(
			'ok'           => false,
			'operation'    => $operation,
			'code'         => 'invalid_input',
			'repair_codes' => array( 'copy_current_builder_document_directory_and_scope' ),
			'message'      => 'This model read differs from the current editor target. Copy its exact document, directory and scope. Read other directory field definitions with directory.describe; shared directory_query membership does not change this bound builder read scope. No argument was rewritten or executed.',
		);
	}

	/**
	 * Compare only the binding invariants already enforced by native builder reads.
	 *
	 * @param string $operation Current server operation identity.
	 * @param object $input Original native-schema-validated model input.
	 * @param object $binding Stored server context, never model-supplied authority.
	 * @return bool Whether the model changed a bound catalog/document/scope identity.
	 */
	public static function mismatch( string $operation, object $input, object $binding ): bool {
		if ( ! in_array( $operation, array( 'builder.inspect', 'builder.describe' ), true ) ) {
			return false;
		}
		$document = $binding->builder_document ?? null;
		$scope    = $binding->builder_scope ?? null;
		if ( ! $document instanceof \stdClass || ! $scope instanceof \stdClass ) {
			return false;
		}
		$document_keys = array_keys( get_object_vars( $document ) );
		$scope_keys    = array_keys( get_object_vars( $scope ) );
		sort( $document_keys, SORT_STRING );
		sort( $scope_keys, SORT_STRING );
		if ( array( 'builder', 'native_id', 'native_kind' ) !== $document_keys || array( 'breakpoint_id', 'directory_id', 'interaction', 'view' ) !== $scope_keys ) {
			return false;
		}
		$expected = $scope->directory_id ?? null;
		$actual   = $input->directory_id ?? null;
		if ( ( null === $expected ) !== ( null === $actual ) || ( null !== $expected && (string) $expected !== (string) $actual ) ) {
			return true;
		}
		if ( 'builder.describe' === $operation ) {
			return ( $input->builder ?? null ) !== ( $document->builder ?? null );
		}
		if ( 'builder.inspect' !== $operation ) {
			return false;
		}
		$canonical = new JsonCanonicalizer();
		return $canonical->encode( $input->document ?? null ) !== $canonical->encode( $document ) || $canonical->encode( $input->scope ?? null ) !== $canonical->encode( $scope );
	}

	/**
	 * Defer to original native failure behavior unless current editor authority is positive.
	 *
	 * @param Context $context Current worker actor and site.
	 * @param object  $binding Current stored native target.
	 * @return bool Current signed capture, original source and directory remain authorized.
	 */
	private static function current_editor_authority( Context $context, object $binding ): bool {
		$context->assert_execution_identity();
		if ( $context->actor()->is_guest() || ! ( $context->actor()->can( 'edit_pages' ) || $context->actor()->can( 'edit_posts' ) || $context->actor()->can( 'edit_theme_options' ) ) ) {
			return false;
		}
		$record = EditorWorkingCopy::editor_context( $binding->builder_document );
		if ( null === $record || 'editor_candidate' !== ( $record['output_mode'] ?? null ) ) {
			return false;
		}
		$directory = $binding->builder_scope->directory_id ?? null;
		return null === $directory || ( is_string( $directory ) && preg_match( '/^[1-9][0-9]*$/D', $directory ) && ( new DirectoristInspector() )->can_describe( $context, (int) $directory, 'submission' ) );
	}
}
