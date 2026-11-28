<?php
/**
 * Exact document binding for a stored builder conversation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\Database;

/** Stored task context can narrow document authority, never broaden it. */
final class BuilderConversationTarget {
	/**
	 * Load only the exact active, owned run's stored selection.
	 *
	 * @param Database $db Current storage.
	 * @param Context  $context Current actor and run.
	 * @throws ContractException When the selected run is unavailable.
	 */
	public static function active_context( Database $db, Context $context ): ?string {
		if ( null === $context->conversation_id() && null === $context->run_id() ) {
			return null;
		}
		if ( null === $context->conversation_id() || null === $context->run_id() ) {
			throw new ContractException( 'unauthorized', 'Builder operation requires its complete conversation identity.' );
		}
		$owner         = $context->actor()->owner()->reference();
		$runs          = $db->table( 'runs' );
		$conversations = $db->table( 'conversations' );
		$rows          = $db->rows( "SELECT c.context_json FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id AND c.active_run_id=r.id WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.owner_ref=%s AND c.erasure_requested=0 AND r.cancel_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) AND r.state IN ('queued','running','waiting_approval')", array( $context->run_id(), $context->conversation_id(), $owner, $owner ) );
		if ( 1 !== count( $rows ) || ! is_string( $rows[0]['context_json'] ?? null ) ) {
			throw new ContractException( 'unauthorized', 'Builder conversation is unavailable.' );
		}
		( new BuilderDraftResultApi( $db ) )->assert_refinement_binding( $rows[0]['context_json'] );
		return $rows[0]['context_json'];
	}

	/**
	 * A bound conversation cannot ask the model for another builder or directory catalog.
	 *
	 * @param string   $json Stored selection.
	 * @param string   $builder Requested builder.
	 * @param int|null $directory_id Requested directory.
	 * @throws ContractException When the catalog differs from the selected target.
	 */
	public static function assert_catalog_binding( string $json, string $builder, ?int $directory_id ): void {
		try {
			$binding = ( new JsonDecoder( 65536, 12 ) )->decode( $json );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'evidence_stale', 'Builder conversation target is unavailable.' );
		}
		if ( ! $binding instanceof \stdClass ) {
			throw new ContractException( 'evidence_stale', 'Builder conversation target is invalid.' );
		}
		if ( property_exists( $binding, 'builder_document' ) && ( ! $binding->builder_document instanceof \stdClass || ( $binding->builder_document->builder ?? null ) !== $builder ) ) {
			throw new ContractException( 'unauthorized', 'Builder catalog differs from the selected native builder.' );
		}
		$selected_directory = $binding->builder_scope->directory_id ?? null;
		if ( property_exists( $binding, 'builder_scope' ) && ( ! $binding->builder_scope instanceof \stdClass || ( null === $directory_id ? null !== $selected_directory : ( ! is_int( $selected_directory ) && ! is_string( $selected_directory ) ) || (string) $directory_id !== (string) $selected_directory ) ) ) {
			throw new ContractException( 'unauthorized', 'Builder catalog differs from the selected directory.' );
		}
	}
	/**
	 * Check a logical or native document binding held by the owned conversation.
	 *
	 * @param string      $json Stored context JSON, not caller-supplied request data.
	 * @param string      $document_key Selected logical graph document.
	 * @param object      $native_document Current native graph document identity.
	 * @param object|null $scope Current requested change scope, when planning.
	 * @throws ContractException When a bound conversation targets another document.
	 */
	public static function assert_binding( string $json, string $document_key, object $native_document, ?object $scope = null ): void {
		try {
			$binding = ( new JsonDecoder( 65536, 12 ) )->decode( $json );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'evidence_stale', 'Builder conversation target is unavailable.' );
		}
		if ( ! $binding instanceof \stdClass ) {
			throw new ContractException( 'evidence_stale', 'Builder conversation target is invalid.' );
		}
		if ( property_exists( $binding, 'document_key' ) && ( ! is_string( $binding->document_key ) || $binding->document_key !== $document_key ) ) {
			throw new ContractException( 'unauthorized', 'Builder conversation is bound to another document.' );
		}
		if ( property_exists( $binding, 'target' ) && null !== $binding->target && ( ! is_string( $binding->target ) || ! in_array( $binding->target, array( $document_key, $native_document->native_id ), true ) ) ) {
			throw new ContractException( 'unauthorized', 'Builder conversation target differs from the native document.' );
		}
		if ( property_exists( $binding, 'builder_document' ) ) {
			$selected = $binding->builder_document;
			$keys     = $selected instanceof \stdClass ? array_keys( get_object_vars( $selected ) ) : array();
			sort( $keys, SORT_STRING );
			$current_document = (object) array(
				'builder'     => $native_document->builder ?? null,
				'native_kind' => $native_document->native_kind ?? null,
				'native_id'   => $native_document->native_id ?? null,
			);
			if ( array( 'builder', 'native_id', 'native_kind' ) !== $keys || ( new JsonCanonicalizer() )->encode( $selected ) !== ( new JsonCanonicalizer() )->encode( $current_document ) ) {
				throw new ContractException( 'unauthorized', 'Builder conversation selection differs from the native document.' );
			}
		}
		if ( null !== $scope && property_exists( $binding, 'builder_scope' ) ) {
			$expected = $binding->builder_scope;
			$keys     = $expected instanceof \stdClass ? array_keys( get_object_vars( $expected ) ) : array();
			sort( $keys, SORT_STRING );
			if ( array( 'breakpoint_id', 'directory_id', 'interaction', 'view' ) !== $keys || ( new JsonCanonicalizer() )->encode( $expected ) !== ( new JsonCanonicalizer() )->encode( $scope ) ) {
				throw new ContractException( 'unauthorized', 'Builder conversation scope differs from the selected native change.' );
			}
		}
	}
}
