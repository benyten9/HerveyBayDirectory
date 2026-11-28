<?php
/**
 * Authorized logical listing query catalog.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Domain;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag -- Compact logical catalog definitions.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;

/** Advertised fields map to fixed adapter logic, never raw meta or SQL names. */
final class ListingQueryCatalog {
	private DirectoristInspector $inspector;

	/** Bind the effective Directorist schema reader. */
	public function __construct( DirectoristInspector $inspector ) {
		$this->inspector = $inspector;
	}

	/** Check requested dataset without treating it as authority. */
	public function permitted( Context $context, int $directory_id, string $dataset ): bool {
		if ( 'public' === $dataset ) {
			return $this->inspector->can_describe( $context, $directory_id, 'public_search' );
		}
		if ( 'own' === $dataset ) {
			return ! $context->actor()->is_guest() && $this->inspector->can_describe( $context, $directory_id, 'submission' );
		}
		return 'management' === $dataset && $this->inspector->can_describe( $context, $directory_id, 'management' );
	}

	/** Return only fields with a fixed supported logical implementation. */
	public function describe( Context $context, int $directory_id, string $dataset ): object {
		return $this->query_snapshot( $context, $directory_id, $dataset )['catalog'];
	}

	/** Return server-only source authority alongside the unchanged advertised catalog. */
	public function query_snapshot( Context $context, int $directory_id, string $dataset ): array {
		// A provider round may outlive another request's directory edits.
		wp_cache_delete( $directory_id, 'terms' );
		wp_cache_delete( $directory_id, 'term_meta' );
		if ( ! $this->permitted( $context, $directory_id, $dataset ) ) {
			throw new ContractException( 'unauthorized', 'Listing query catalog is unavailable.' );
		}
		$purpose = 'public' === $dataset ? 'public_search' : ( 'management' === $dataset ? 'management' : 'submission' );
		$snapshot = $this->inspector->description_snapshot( $context, $directory_id, $purpose );
		$directory = $snapshot['directory'];
		$fields = array();
		$limitations = $directory->limitations;
		foreach ( $directory->fields as $field ) {
			$definition = $this->definition( $field );
			if ( null === $definition ) {
				$limitations[] = 'query_field_unsupported:' . $field->field_id;
				continue;
			}
			$fields[] = (object) array( 'field_id' => $field->field_id, 'value_type' => $definition['type'], 'operators' => $definition['operators'], 'orderable' => $definition['orderable'], 'allowed_values' => $field->allowed_values, 'conditional_logic' => $field->conditional_logic );
		}
		$revision_data = (object) array( 'dataset' => $dataset, 'schema_hash' => $directory->schema_hash, 'fields' => $fields );
		$catalog = (object) array( 'contract' => 'directorist-ai/catalog-v1', 'directory_id' => $directory_id, 'dataset' => $dataset, 'revision' => hash( 'sha256', wp_json_encode( $revision_data ) ), 'schema_hash' => $directory->schema_hash, 'fields' => $fields, 'limitations' => array_values( array_unique( $limitations ) ) );
		return array( 'catalog' => $catalog, 'sources' => $snapshot['sources'], 'source_fingerprint' => $snapshot['source_fingerprint'], 'sources_supported' => $snapshot['sources_supported'], 'taxonomy_sources' => $snapshot['sources_supported'] ? $this->taxonomy_sources( $fields, $snapshot['sources'] ) : array() );
	}

	/** Native field dispatch, not a logical name or sanitized widget, authorizes a taxonomy leaf. */
	private function taxonomy_sources( array $fields, array $sources ): array {
		$taxonomies = array( 'category' => defined( 'ATBDP_CATEGORY' ) ? ATBDP_CATEGORY : null, 'location' => defined( 'ATBDP_LOCATION' ) ? ATBDP_LOCATION : null, 'tag' => defined( 'ATBDP_TAGS' ) ? ATBDP_TAGS : null );
		$result = array();
		foreach ( $fields as $field ) {
			$widget = $sources[ $field->field_id ]['widget_name'] ?? null;
			if ( 'term_ids' !== $field->value_type || ! is_array( $widget ) || ! $widget['present'] || 'string' !== $widget['type'] || ! isset( $taxonomies[ $widget['value'] ] ) ) {
				continue;
			}
			$result[ $field->field_id ] = $taxonomies[ $widget['value'] ];
		}
		return $result;
	}

	/** Fixed logical predicate support by stable field family. */
	private function definition( object $field ): ?array {
		if ( 'image_upload' === $field->widget && 'images' === $field->field_id ) {
			return array( 'type' => 'structured', 'operators' => array(), 'orderable' => false );
		}
		if ( 'ai_distance' === $field->widget ) {
			return array( 'type' => 'number', 'operators' => array( 'near', 'exists' ), 'orderable' => true );
		}
		if ( in_array( $field->widget, array( 'ai_hours', 'ai_faqs' ), true ) ) {
			return array( 'type' => 'structured', 'operators' => array(), 'orderable' => false );
		}
		if ( 'pricing' === $field->widget && 'pricing' === $field->field_id ) {
			// Display/compare the native price or range without treating its object as a number.
			return array( 'type' => 'structured', 'operators' => array(), 'orderable' => false );
		}
		if ( in_array( $field->field_id, array( 'categories', 'locations', 'tags' ), true ) ) {
			return array( 'type' => 'term_ids', 'operators' => array( 'eq', 'in', 'exists' ), 'orderable' => false );
		}
		if ( 'number' === $field->type ) {
			return array( 'type' => 'number', 'operators' => array( 'eq', 'lt', 'lte', 'gt', 'gte', 'between', 'exists' ), 'orderable' => true );
		}
		if ( $field->allowed_values ) {
			if ( 'checkbox' === $field->widget ) {
				return array( 'type' => 'enum_set', 'operators' => array( 'eq', 'in', 'exists' ), 'orderable' => false );
			}
			return array( 'type' => 'enum', 'operators' => array( 'eq', 'in', 'exists' ), 'orderable' => true );
		}
		if ( in_array( $field->field_id, array( 'title', 'description', 'excerpt', 'address', 'email', 'phone', 'website' ), true ) || in_array( $field->type, array( 'text', 'textarea', 'email', 'url' ), true ) ) {
			return array( 'type' => 'text', 'operators' => array( 'eq', 'text', 'exists' ), 'orderable' => in_array( $field->field_id, array( 'title', 'address' ), true ) );
		}
		if ( in_array( $field->type, array( 'switch', 'toggle' ), true ) ) {
			return array( 'type' => 'boolean', 'operators' => array( 'eq', 'exists' ), 'orderable' => false );
		}
		return null;
	}
}
