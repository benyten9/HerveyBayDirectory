<?php
/**
 * Bind public billing-unit labels to exact verified listing facts.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Domain\ListingQueryCatalog;

/** Presentation metadata never adds a fact or modifies the sealed response. */
final class VisitorPriceUnitDisplay {

	/**
	 * Resolve only unambiguous public unit fields against the original catalog.
	 *
	 * @param Context     $context Verified history owner and site.
	 * @param object      $response Verified current or source-qualified response.
	 * @param object|null $input Original owned grounded input.
	 * @return array Directory identifiers mapped to one exact public field identifier.
	 */
	public static function fields( Context $context, object $response, ?object $input ): array {
		if ( null === $input || 'visitor' !== $context->profile() || 'public' !== ( $response->task->dataset ?? null ) || 'listing' !== ( $response->task->family ?? null ) ) {
			return array();
		}
		$revisions = array();
		$conflicts = array();
		$sets      = array_merge( $input->searches ?? array(), isset( $input->search ) ? array( $input->search ) : array(), $response->comparisons ?? array(), isset( $response->comparison ) ? array( $response->comparison ) : array() );
		foreach ( $sets as $set ) {
			$id       = $set->directory_id ?? null;
			$revision = $set->catalog_revision ?? null;
			if ( ! is_int( $id ) || $id < 1 || ! is_string( $revision ) || ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) ) {
				continue;
			}
			if ( isset( $revisions[ $id ] ) && $revisions[ $id ] !== $revision ) {
				$conflicts[ $id ] = true;
			}
			$revisions[ $id ] = $revision;
		}
		$fields    = array();
		$inspector = new DirectoristInspector();
		$catalogs  = new ListingQueryCatalog( $inspector );
		foreach ( array_slice( $revisions, 0, 20, true ) as $id => $revision ) {
			if ( isset( $conflicts[ $id ] ) ) {
				continue;
			}
			try {
				$context->assert_execution_identity();
				$catalog = $catalogs->describe( $context, $id, 'public' );
				$schema  = $inspector->describe( $context, $id, 'public_search' );
				$context->assert_execution_identity();
				if ( ! hash_equals( $revision, $catalog->revision ) || ! hash_equals( $catalog->schema_hash, $schema->schema_hash ) || ( $schema->directory->id ?? null ) !== $id ) {
					continue;
				}
				$public     = array_fill_keys( array_column( $catalog->fields, 'field_id' ), true );
				$candidates = array();
				foreach ( $schema->fields as $field ) {
					$label = is_string( $field->label ?? null ) ? strtolower( trim( preg_replace( '/\s+/u', ' ', $field->label ) ) ) : '';
					if ( isset( $public[ $field->field_id ] ) && in_array( $label, array( 'price unit', 'pricing unit', 'billing period', 'billing unit', 'rate unit', 'price basis', 'salary basis', 'salary period', 'pay period' ), true ) ) {
						$candidates[] = $field->field_id;
					}
				}
				if ( 1 === count( array_unique( $candidates ) ) ) {
					$fields[ $id ] = $candidates[0];
				}
			} catch ( \Throwable $error ) {
				// Missing or changed public metadata must not guess a billing basis.
				continue;
			}
		}
		return $fields;
	}
}
