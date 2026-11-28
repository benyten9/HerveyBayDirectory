<?php
/**
 * Bounded canonical discovery paths for an unresolved native inspection.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

/** Read hints contain no values, schemas, locators or additional authority. */
final class BuilderControlIndexPrompt {

	private const MAX_BYTES = 4096;
	private const COLORS    = array(
		'heading_h2' => 'content.decoration.headingFont.h2.font.desktop.value.color',
		'body'       => 'content.decoration.bodyFont.body.font.desktop.value.color',
	);

	/**
	 * Preserve only bounded identifiers from the current server-owned public index.
	 *
	 * Explicit private/read-only controls override a contradictory index entry.
	 * A partial discovery index never completes an unresolved native contract.
	 *
	 * @param object $authoring Fresh native authoring metadata before projection.
	 * @return object|null Detached read-only index, or no safe index.
	 */
	public static function from_authoring( object $authoring ): ?object {
		if ( ! is_array( $authoring->control_index ?? null ) || ! array_is_list( $authoring->control_index ) || ! $authoring->control_index || count( $authoring->control_index ) > 64 ) {
			return null;
		}
		$denied     = array();
		$components = $authoring->components ?? array();
		if ( ! is_array( $components ) || count( $components ) > 64 ) {
			return null;
		}
		foreach ( $components as $component ) {
			if ( ! $component instanceof \stdClass || ! self::identity( $component->provider_id ?? null ) || ! self::identity( $component->native_id ?? null ) || ! is_array( $component->controls ?? array() ) || count( $component->controls ?? array() ) > 200 || ! self::flags( $component ) ) {
				return null;
			}
			if ( true === ( $component->private ?? null ) || true === ( $component->read_only ?? null ) || false === ( $component->writable ?? null ) ) {
				$denied[ $component->provider_id . '|' . $component->native_id ]['*'] = true;
			}
			foreach ( $component->controls ?? array() as $control ) {
				if ( ! $control instanceof \stdClass || ! is_string( $control->path ?? null ) || strlen( $control->path ) > 256 || ( property_exists( $control, 'private' ) && ! is_bool( $control->private ) ) || ( property_exists( $control, 'read_only' ) && ! is_bool( $control->read_only ) ) || ( property_exists( $control, 'writable' ) && ! is_bool( $control->writable ) ) ) {
					return null;
				}
				if ( true === ( $control->private ?? null ) || true === ( $control->read_only ?? null ) || false === ( $control->writable ?? null ) ) {
					$denied[ ( $component->provider_id ?? '' ) . '|' . ( $component->native_id ?? '' ) ][ $control->path ?? '' ] = true;
				}
			}
		}
		$rows  = array();
		$total = 0;
		foreach ( $authoring->control_index as $row ) {
			if ( ! $row instanceof \stdClass || ! self::identity( $row->provider_id ?? null ) || ! self::identity( $row->native_id ?? null ) || ! is_array( $row->paths ?? null ) || ! array_is_list( $row->paths ) || count( $row->paths ) > 200 || ! self::flags( $row ) || true === ( $row->private ?? null ) || true === ( $row->read_only ?? null ) || false === ( $row->writable ?? null ) ) {
				return null;
			}
			$key = $row->provider_id . '|' . $row->native_id;
			if ( isset( $rows[ $key ] ) ) {
				return null;
			}
			$paths = array();
			foreach ( $row->paths as $path ) {
				if ( ! is_string( $path ) || strlen( $path ) > 256 || ! preg_match( '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,15}$/D', $path ) ) {
					return null;
				}
				if ( ! isset( $denied[ $key ]['*'] ) && ! isset( $denied[ $key ][ $path ] ) ) {
					$paths[ $path ] = $path;
				}
			}
			$total += count( $paths );
			if ( 'divi/core|divi/text' === $key ) {
				$priority = array_values( array_intersect( self::COLORS, $paths ) );
				$paths    = array_merge( $priority, array_values( array_diff( $paths, $priority ) ) );
			}
			$rows[ $key ] = (object) array(
				'provider_id' => $row->provider_id,
				'native_id'   => $row->native_id,
				'paths'       => array_values( $paths ),
			);
		}
		if ( isset( $rows['divi/core|divi/text'] ) ) {
			$rows = array( 'divi/core|divi/text' => $rows['divi/core|divi/text'] ) + $rows;
		}
		$out = (object) array(
			'control_index'            => array(),
			'control_index_complete'   => false,
			'control_index_projection' => (object) array(
				'partial'               => true,
				'source_index_complete' => true === ( $authoring->control_index_complete ?? null ),
				'source_path_count'     => $total,
				'returned_path_count'   => 0,
				'truncated'             => true,
				'note'                  => 'Canonical public read paths only, not schemas or authoring permission. Inspect the exact needed component/path. Omitted paths are unknown. Design property-family names are navigation labels, never control paths.',
			),
			'canonical_text_colors'    => array(),
		);
		foreach ( array_slice( $rows, 0, 4, true ) as $key => $row ) {
			$copy                 = (object) array(
				'provider_id' => $row->provider_id,
				'native_id'   => $row->native_id,
				'paths'       => array(),
			);
			$out->control_index[] = $copy;
			foreach ( array_slice( $row->paths, 0, 32 ) as $path ) {
				$copy->paths[] = $path;
				if ( strlen( json_encode( $out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ) > self::MAX_BYTES - 512 ) {
					array_pop( $copy->paths );
					break;
				}
			}
			if ( ! $copy->paths ) {
				array_pop( $out->control_index );
			}
		}
		foreach ( $out->control_index as $row ) {
			if ( 'divi/core' !== $row->provider_id || 'divi/text' !== $row->native_id ) {
				continue;
			}
			foreach ( self::COLORS as $role => $path ) {
				if ( in_array( $path, $row->paths, true ) ) {
					$out->canonical_text_colors[] = (object) array(
						'provider_id'   => 'divi/core',
						'native_id'     => 'divi/text',
						'role'          => $role,
						'control_path'  => $path,
						'schema_status' => 'inspect_required',
					);
				}
			}
		}
		$out->control_index_projection->returned_path_count = array_sum( array_map( static fn( object $row ): int => count( $row->paths ), $out->control_index ) );
		$out->control_index_projection->truncated           = $out->control_index_projection->returned_path_count < $total;
		return $out->control_index && strlen( json_encode( $out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ) <= self::MAX_BYTES ? $out : null;
	}

	/**
	 * Validate bounded component identifiers, never a model-owned selector.
	 *
	 * @param mixed $value Source identifier.
	 * @return bool Whether it is a bounded identifier.
	 */
	private static function identity( $value ): bool {
		return is_string( $value ) && strlen( $value ) <= 128 && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D', $value );
	}

	/**
	 * Ambiguous visibility flags never establish a public read hint.
	 *
	 * @param object $value Source metadata.
	 * @return bool Whether all present visibility flags are typed.
	 */
	private static function flags( object $value ): bool {
		return ( ! property_exists( $value, 'private' ) || is_bool( $value->private ) ) && ( ! property_exists( $value, 'read_only' ) || is_bool( $value->read_only ) ) && ( ! property_exists( $value, 'writable' ) || is_bool( $value->writable ) );
	}
}
