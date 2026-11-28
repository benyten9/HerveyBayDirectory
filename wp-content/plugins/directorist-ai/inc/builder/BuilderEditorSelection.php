<?php
/**
 * Native editor selection narrows a reviewed draft to one saved area.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;

// phpcs:disable Squiz.Commenting.FunctionComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Closed selection boundary.

/** Saved identity and containment fencing for selected-area authoring. */
final class BuilderEditorSelection {
	/**
	 * Browser block ordinals omit whitespace; saved graph locators retain it.
	 *
	 * @throws ContractException Invalid or unsaved browser path.
	 */
	public static function gutenberg_saved_path( array $blocks, array $path ): array {
		if ( count( $path ) < 2 || count( $path ) > 32 || 'content' !== $path[0] ) {
			throw new ContractException( 'scope_conflict', 'Select one supported saved block.' );
		}
		$result = array( 'content' );
		for ( $offset = 1; $offset < count( $path ); $offset += 2 ) {
			if ( ! is_string( $path[ $offset ] ) || ! preg_match( '/^i(0|[1-9][0-9]*)$/D', $path[ $offset ], $match ) || count( $blocks ) > 1900 ) {
				throw new ContractException( 'scope_conflict', 'The browser selection path is unavailable.' );
			}
			$visible = 0;
			$found   = null;
			foreach ( $blocks as $raw => $block ) {
				if ( null === ( $block['blockName'] ?? null ) && '' === trim( $block['innerHTML'] ?? '' ) ) {
					continue;
				}
				if ( $visible++ === (int) $match[1] ) {
					$found    = $block;
					$result[] = 'i' . $raw;
					break;
				}
			}
			if ( null === $found ) {
				throw new ContractException( 'scope_conflict', 'The selected block is not in the saved document.' );
			}
			if ( $offset + 1 < count( $path ) ) {
				if ( 'innerBlocks' !== $path[ $offset + 1 ] || $offset + 2 >= count( $path ) ) {
					throw new ContractException( 'scope_conflict', 'The browser selection path is invalid.' );
				}
				$result[] = 'innerBlocks';
				$blocks   = $found['innerBlocks'] ?? array();
			}
		}
		return $result;
	}

	/**
	 * Canonicalize only the native UI endpoint's browser observation.
	 *
	 * @throws ContractException Native source or path is unavailable.
	 */
	public static function saved_gutenberg_selection( object $selection, object $document ): object {
		self::assert_source( $selection, $document );
		$ref = $document->native_id;
		if ( preg_match( '/^post:([1-9][0-9]*)$/D', $ref, $match ) ) {
			$content = get_post( (int) $match[1] )->post_content;
		} elseif ( preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $ref, $match ) ) {
			$content = get_block_template( $match[2] . '//' . $match[3], $match[1] )->content;
		} else {
			throw new ContractException( 'scope_conflict', 'The selected native document is unavailable.' );
		}
		if ( strlen( $content ) > 1048576 ) {
			throw new ContractException( 'needs_narrowing', 'The saved document exceeds selection bounds.' );
		}
		$result            = clone $selection;
		$result->slot_path = self::gutenberg_saved_path( parse_blocks( $content ), $selection->slot_path ?? array() );
		return $result;
	}

	public static function source_stamp( object $document ): string {
		$ref = $document->native_id ?? '';
		if ( preg_match( '/^post:([1-9][0-9]*)$/D', $ref, $match ) ) {
			$post = get_post( (int) $match[1] );
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				throw new ContractException( 'unauthorized', 'Selected document is unavailable.' );
			}
			$native = array( $post->ID, $post->post_type, $post->post_content );
			if ( 'elementor' === ( $document->builder ?? null ) ) {
				$metadata = array();
				foreach ( get_post_meta( $post->ID ) as $key => $value ) {
					if ( in_array( $key, array( '_elementor_data', '_elementor_page_settings', '_elementor_template_type', '_elementor_conditions' ), true ) || str_starts_with( $key, '_directorist_' ) ) {
						$metadata[ $key ] = $value;
					}
				}
				ksort( $metadata );
				$native[] = $metadata;
			}
		} elseif ( preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $ref, $match ) && current_user_can( 'edit_theme_options' ) && function_exists( 'get_block_template' ) ) {
			$template = GutenbergTemplateSource::read( $ref );
			if ( ! $template instanceof \WP_Block_Template ) {
				throw new ContractException( 'stale_version', 'Selected template is unavailable in the active theme.' );
			}
			$native = array( $template->id, $template->type, $template->content );
			if ( get_stylesheet() !== $template->theme ) {
				$native[] = get_stylesheet();
			}
		} else {
			throw new ContractException( 'invalid_input', 'Selected native document identity is invalid.' );
		}
		return hash( 'sha256', ( new JsonCanonicalizer() )->encode( (object) array( 'document' => $document, 'native' => $native ) ) );
	}

	public static function assert_source( object $selection, object $document ): void {
		$keys = array_keys( get_object_vars( $selection ) );
		sort( $keys, SORT_STRING );
		if ( array( 'label', 'native_node_id', 'slot_path', 'source_stamp' ) !== $keys || ! is_string( $selection->label ) || strlen( $selection->label ) > 640 || ( null !== $selection->native_node_id && ( ! is_string( $selection->native_node_id ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/D', $selection->native_node_id ) ) ) || ( null !== $selection->slot_path && ( ! is_array( $selection->slot_path ) || ! $selection->slot_path || count( $selection->slot_path ) > 32 ) ) ) {
			throw new ContractException( 'invalid_input', 'The native selection shape is invalid.' );
		}
		foreach ( $selection->slot_path ?? array() as $part ) {
			GraphVersion::identifier( $part );
		}
		if ( null === ( $selection->native_node_id ?? null ) && null === ( $selection->slot_path ?? null ) ) {
			throw new ContractException( 'invalid_input', 'Select a native area before preparing this change.' );
		}
		if ( ! is_string( $selection->source_stamp ?? null ) || ! hash_equals( $selection->source_stamp, self::source_stamp( $document ) ) ) {
			throw new ContractException( 'stale_version', 'Save and refresh the document context before editing the selected area.' );
		}
	}

	/**
	 * Pure graph fence: caller observations never become write authority.
	 *
	 * @throws ContractException Missing, ambiguous or out-of-area target.
	 */
	public static function assert_changes( ?string $context_json, object $graph, array $changes ): void {
		$binding   = null === $context_json ? null : json_decode( $context_json );
		$selection = $binding->builder_selection ?? null;
		if ( null === $selection ) {
			return;
		}
		if ( ! $selection instanceof \stdClass || ( null === ( $selection->native_node_id ?? null ) && null === ( $selection->slot_path ?? null ) ) ) {
			throw new ContractException( 'scope_conflict', 'The selected native area is invalid.' );
		}
		$selected_node = self::resolve_node( $selection, $graph );
		$allowed       = array( $selected_node->id => true );
		for ( $pass = 0; $pass < count( $graph->nodes ); ++$pass ) {
			$changed = false;
			foreach ( $graph->edges as $edge ) {
				if ( 'contains' === $edge->kind && null === ( $edge->target_document_key ?? null ) && isset( $allowed[ $edge->source ] ) && ! isset( $allowed[ $edge->target ] ) ) {
					$allowed[ $edge->target ] = true;
					$changed                  = true;
				}
			}
			if ( ! $changed ) {
				break;
			}
		}
		foreach ( $changes as $change ) {
			$targets = array();
			foreach ( array( 'target_id', 'parent_id' ) as $property ) {
				if ( isset( $change->{$property} ) ) {
					$targets[] = $change->{$property};
				}
			}
			if ( ! $targets ) {
				throw new ContractException( 'scope_conflict', 'Selected-area changes require an exact native target.' );
			}
			foreach ( $targets as $target ) {
				if ( ! isset( $allowed[ $target ] ) ) {
					throw new ContractException( 'scope_conflict', 'The proposal changes content outside the selected area.' );
				}
			}
		}
	}
	public static function resolve_node( object $selection, object $graph ): object {
		$matches = array();
		foreach ( $graph->nodes as $node ) {
			if ( 'directorist-ai' === $node->provider_id || null !== $node->opaque_ref ) {
				continue;
			}
			if ( null !== ( $selection->native_node_id ?? null ) && ( $node->locator->native_id ?? null ) !== $selection->native_node_id ) {
				continue;
			}
			if ( null !== ( $selection->slot_path ?? null ) && ( $node->locator->slot_path ?? null ) !== $selection->slot_path ) {
				continue;
			}
			$matches[] = $node->id;
		}
		if ( 1 !== count( $matches ) ) {
			throw new ContractException( 'scope_conflict', 'The selected area is missing, ambiguous or unsupported in the saved layout.' );
		}
		foreach ( $graph->nodes as $node ) {
			if ( $node->id === $matches[0] ) {
				return $node;
			}
		}
		throw new ContractException( 'scope_conflict', 'Selected area is unavailable.' );
	}
}
