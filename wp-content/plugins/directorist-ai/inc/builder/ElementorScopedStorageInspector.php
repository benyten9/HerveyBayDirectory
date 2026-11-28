<?php
/**
 * Bounded integrity check for Elementor Directorist card scopes.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Compact read-only verifier; graph extraction remains P5-09.

use DirectoristAIPro\Validation\ContractException;

/** Verifies native scope ownership without exposing private stored trees. */
final class ElementorScopedStorageInspector {
	private int $nodes         = 0;
	private array $summaries   = array();
	private array $visible_ids = array();
	private array $hidden_ids  = array();
	private bool $incomplete   = false;

	public function inspect( int $post_id ): object {
		$this->nodes       = 0;
		$this->summaries   = array();
		$this->visible_ids = array();
		$this->hidden_ids  = array();
		$this->incomplete  = false;
		$raw               = get_post_meta( $post_id, '_elementor_data', true );
		if ( '' === $raw || null === $raw || array() === $raw ) {
			return $this->result( 'not_present' );
		}
		if ( is_string( $raw ) ) {
			if ( strlen( $raw ) > 2097152 ) {
				throw new ContractException( 'evidence_stale', 'Elementor document data exceeds the inspection boundary.' );
			}
			try {
				$data = json_decode( $raw, true, 64, JSON_THROW_ON_ERROR );
			} catch ( \JsonException $error ) {
				throw new ContractException( 'evidence_stale', 'Elementor document data is not valid JSON.' );
			}
		} else {
			$data = $raw;
		}
		if ( ! is_array( $data ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor document data is invalid.' );
		}
		$this->walk( $data, 0 );
		if ( array_intersect_key( $this->visible_ids, $this->hidden_ids ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor child identity is shared by visible and inactive scopes.' );
		}
		return $this->result( ! $this->summaries ? 'not_present' : ( $this->incomplete ? 'incomplete' : 'complete' ) );
	}

	private function walk( array $elements, int $depth ): void {
		if ( $depth > 40 || count( $elements ) > 1000 ) {
			throw new ContractException( 'evidence_stale', 'Elementor scope tree exceeds the inspection boundary.' );
		}
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || ++$this->nodes > 5000 ) {
				throw new ContractException( 'evidence_stale', 'Elementor scope tree is invalid.' );
			}
			if ( ! isset( $element['id'] ) || ! is_string( $element['id'] ) || ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $element['id'] ) || isset( $this->visible_ids[ $element['id'] ] ) ) {
				throw new ContractException( 'evidence_stale', 'Elementor visible child identity is invalid or duplicated.' );
			}
			$this->visible_ids[ $element['id'] ] = true;
			$element_type                        = (string) ( $element['elType'] ?? '' );
			$type                                = sanitize_key( 'widget' === $element_type ? (string) ( $element['widgetType'] ?? '' ) : $element_type );
			if ( 'directorist_listing_card_template' === $type ) {
				$this->inspect_card( $element );
			}
			$children = $element['elements'] ?? array();
			if ( ! is_array( $children ) ) {
				throw new ContractException( 'evidence_stale', 'Elementor child storage is invalid.' );
			}
			$this->walk( $children, $depth + 1 );
		}
	}

	private function inspect_card( array $card ): void {
		$settings = $card['settings'] ?? array();
		if ( ! is_array( $settings ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor card settings are invalid.' );
		}
		$active = sanitize_key( (string) ( $settings['active_template_key'] ?? '' ) );
		$raw    = $settings['scoped_templates'] ?? '{}';
		if ( is_string( $raw ) ) {
			try {
				$scopes = json_decode( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ), true, 48, JSON_THROW_ON_ERROR );
			} catch ( \JsonException $error ) {
				throw new ContractException( 'evidence_stale', 'Elementor scoped templates are not valid JSON.' );
			}
		} else {
			$scopes = $raw;
		}
		if ( ! is_array( $scopes ) || count( $scopes ) > 100 ) {
			throw new ContractException( 'evidence_stale', 'Elementor scoped templates are invalid.' );
		}
		if ( '' === $active || ! $scopes ) {
			$this->incomplete  = true;
			$this->summaries[] = array(
				'active' => $active,
				'scopes' => array(),
			);
			return;
		}
		if ( ! preg_match( '/^dir-(\d+)-(grid|list|map)$/D', $active ) || ! isset( $scopes[ $active ] ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor active card scope is ambiguous.' );
		}
		$owners     = array();
		$active_ids = $this->tree_ids( is_array( $card['elements'] ?? null ) ? $card['elements'] : array() );
		foreach ( $active_ids as $id ) {
			$owners[ $id ] = $active;
		}
		$scope_hashes = array();
		ksort( $scopes, SORT_STRING );
		foreach ( $scopes as $key => $scope ) {
			if ( ! is_string( $key ) || ! preg_match( '/^dir-(\d+)-(grid|list|map)$/D', $key, $matches ) || ! is_array( $scope ) || ! is_array( $scope['elements'] ?? null ) || ( isset( $scope['directory_type_id'] ) && (int) $scope['directory_type_id'] !== (int) $matches[1] ) || ( isset( $scope['view_type'] ) && sanitize_key( (string) $scope['view_type'] ) !== $matches[2] ) ) {
				throw new ContractException( 'evidence_stale', 'Elementor card scope identity is invalid.' );
			}
			$ids = $this->tree_ids( $scope['elements'] );
			if ( $key === $active ) {
				$left  = $active_ids;
				$right = $ids;
				sort( $left, SORT_STRING );
				sort( $right, SORT_STRING );
				if ( $left !== $right ) {
					throw new ContractException( 'evidence_stale', 'Elementor active card scope differs from the visible tree.' );
				}
			} else {
				foreach ( $ids as $id ) {
					if ( isset( $owners[ $id ] ) || isset( $this->hidden_ids[ $id ] ) ) {
						throw new ContractException( 'evidence_stale', 'Elementor child identity is owned by multiple card scopes.' );
					}
					$owners[ $id ]           = $key;
					$this->hidden_ids[ $id ] = true;
				}
			}
			$scope_hashes[ $key ] = hash( 'sha256', wp_json_encode( array_values( $ids ) ) );
		}
		$this->summaries[] = array(
			'active' => $active,
			'scopes' => $scope_hashes,
		);
	}

	private function tree_ids( array $elements ): array {
		$ids  = array();
		$walk = function ( array $children, int $depth ) use ( &$walk, &$ids ): void {
			if ( $depth > 40 ) {
				throw new ContractException( 'evidence_stale', 'Elementor card scope nesting is too deep.' );
			}
			foreach ( $children as $child ) {
				if ( ! is_array( $child ) || ! isset( $child['id'] ) || ! is_string( $child['id'] ) || ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $child['id'] ) || isset( $ids[ $child['id'] ] ) || ! is_array( $child['elements'] ?? array() ) ) {
					throw new ContractException( 'evidence_stale', 'Elementor scoped child identity is invalid or duplicated.' );
				}
				$ids[ $child['id'] ] = true;
				$walk( $child['elements'] ?? array(), $depth + 1 );
			}
		};
		$walk( $elements, 0 );
		return array_keys( $ids );
	}

	private function result( string $status ): object {
		$scope_count = 0;
		foreach ( $this->summaries as $summary ) {
			$scope_count += count( $summary['scopes'] );
		}
		return (object) array(
			'revision'    => hash( 'sha256', wp_json_encode( array( 'p5-08-scope-v1', $status, $this->summaries ) ) ),
			'scope_count' => $scope_count,
			'status'      => $status,
		);
	}
}
