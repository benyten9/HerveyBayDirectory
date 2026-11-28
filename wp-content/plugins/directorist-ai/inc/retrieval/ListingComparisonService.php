<?php
/**
 * Current eligible Directorist listing comparison.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Comparison validation stays beside native hydration.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Domain\ListingQueryCatalog;
use DirectoristAIPro\Validation\ContractException;

/** Compare public facts only after exact current native hydration. */
final class ListingComparisonService {
	private DirectoristInspector $inspector;
	private ListingQueryCatalog $catalog;

	/** Bind current native schema and fact readers. */
	public function __construct( DirectoristInspector $inspector, ListingQueryCatalog $catalog ) {
		$this->inspector = $inspector;
		$this->catalog   = $catalog;
	}

	/** Read one or compare up to five public listings with per-cell evidence states. */
	public function compare( Context $context, object $input ): object {
		if ( ! isset( $input->directory_id, $input->listing_ids, $input->fields ) || ! is_int( $input->directory_id ) || ! is_array( $input->listing_ids ) || ! is_array( $input->fields ) || count( $input->listing_ids ) < 1 || count( $input->listing_ids ) > 5 || count( array_unique( $input->listing_ids ) ) !== count( $input->listing_ids ) || ! $input->fields || count( $input->fields ) > 12 || count( array_unique( $input->fields ) ) !== count( $input->fields ) ) {
			throw new ContractException( 'invalid_input', 'Listing comparison request is invalid.' );
		}
		foreach ( $input->listing_ids as $listing_id ) {
			if ( ! is_int( $listing_id ) || $listing_id < 1 ) {
				throw new ContractException( 'invalid_input', 'Listing comparison identity is invalid.' );
			}
		}
		foreach ( $input->fields as $field ) {
			if ( ! is_string( $field ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $field ) ) {
				throw new ContractException( 'invalid_input', 'Listing comparison field is invalid.' );
			}
		}

		$catalog   = $this->catalog->describe( $context, $input->directory_id, 'public' );
		$available = array_fill_keys( array_map( static fn( object $field ): string => $field->field_id, $catalog->fields ), true );
		// Distance belongs to an origin-bearing native search. A comparison has
		// no origin input, so an uncomputed value is not a missing published fact.
		unset( $available['distance'] );
		$requested = \DirectoristAIPro\Orchestration\VisitorEvidencePolicy::resolve_fields( $input->fields, $catalog, $this->inspector->public_field_labels( $input->directory_id ) );
		$unsupported = array_values( array_diff( $requested, array_keys( $available ) ) );
		$supported = array_values( array_intersect( $requested, array_keys( $available ) ) );

		$facts = $this->inspector->query_facts( $context, $input->directory_id, 'public', $input->listing_ids );
		$rows  = array();
		foreach ( $supported as $field_id ) {
			$cells = array();
			foreach ( $facts->listings as $listing ) {
				$fact = null;
				foreach ( $listing->fields as $candidate ) {
					if ( $field_id === $candidate->field_id ) {
						$fact = $candidate;
						break;
					}
				}
				$cells[] = (object) array(
					'listing_id' => $listing->id,
					'state'      => null === $fact ? 'missing' : ( $fact->known ? 'known' : 'unknown' ),
					'value'      => null !== $fact && $fact->known ? $fact->value : null,
					'source'     => null === $fact ? null : $fact->source,
					'version'    => $listing->version->content_hash,
				);
			}
			$rows[] = (object) array( 'field_id' => $field_id, 'cells' => $cells );
		}
		return (object) array(
			'contract'         => 'directorist-ai/compare-v1',
			'status'           => $rows ? 'comparison' : 'unsupported',
			'directory_id'     => $input->directory_id,
			'catalog_revision' => $catalog->revision,
			'listing_ids'      => $input->listing_ids,
			'rows'             => $rows,
			'limitations'      => array_values( array_unique( array_merge( $catalog->limitations, $facts->limitations, array_map( static fn( string $field ): string => 'unsupported_field:' . $field, $unsupported ) ) ) ),
		);
	}
}
