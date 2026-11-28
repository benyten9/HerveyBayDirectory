<?php
/**
 * Closed display notes supplied by native compilers, never by model prose.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Validation\ContractException;

/** Notes describe an approved candidate and grant no editing authority. */
final class BuilderCompletionNotes {
	/**
	 * Validate value-free reports from the initial Elementor native compiler.
	 * They describe render-inactive public color assignments, never permission.
	 */
	public static function initial_omissions( $report, object $graph, array $changes ): array {
		if ( array() === $report ) { return array(); }
		$deny = static function (): void { throw new ContractException( 'unsupported_contract', 'Native optional decoration report is invalid.' ); };
		if ( ! is_array( $report ) || ! array_is_list( $report ) || count( $report ) > 64 || 'elementor' !== ( $graph->document->builder ?? null ) || 'complete' !== ( $graph->completeness ?? null ) || ! $changes ) { $deny(); }
		( new \DirectoristAIPro\Validation\JsonDecoder( 16384, 6 ) )->check( $report );
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$parents = array();
		foreach ( $graph->nodes ?? array() as $node ) { $parents[ $node->id ] = $node; }
		$requested = array();
		foreach ( $changes as $change ) {
			$parent = $parents[ $change->parent_id ?? '' ] ?? null;
			if ( 'insert' !== ( $change->op ?? null ) || null === $parent || null !== ( $parent->opaque_ref ?? null ) || $canonical->encode( $parent->locator->document ?? null ) !== $canonical->encode( $graph->document ) ) { $deny(); }
			foreach ( $change->subtree->nodes ?? array() as $node ) {
				$groups = array();
				if ( is_array( $node->attributes->initial_controls ?? null ) ) { $groups[] = (object) array( 'provider_id' => $node->provider_id, 'native_id' => $node->native_id, 'controls' => $node->attributes->initial_controls ); }
				foreach ( $node->attributes->initial_component_controls ?? array() as $group ) {
					$groups[] = (object) array( 'provider_id' => 'directorist-elementor', 'native_id' => $group->native_id ?? null, 'controls' => $group->controls ?? array() );
				}
				foreach ( $groups as $group ) {
					foreach ( $group->controls as $item ) {
						if ( ! is_string( $item->control_path ?? null ) || ! property_exists( $item, 'value' ) ) { continue; }
						$keys = str_starts_with( $item->control_path, 'native_controls.' ) ? ( $item->value instanceof \stdClass ? array_keys( get_object_vars( $item->value ) ) : array() ) : array( $item->control_path );
						foreach ( $keys as $key ) { $requested[ $canonical->encode( array( $group->provider_id, $group->native_id, $item->control_path, $key ) ) ] = true; }
					}
				}
			}
		}
		$seen = array();
		$result = array();
		foreach ( $report as $row ) {
			if ( ! $row instanceof \stdClass || count( get_object_vars( $row ) ) !== 5 || array_diff( array_keys( get_object_vars( $row ) ), array( 'provider_id', 'native_id', 'control_path', 'native_key', 'reason' ) ) || ! in_array( $row->provider_id ?? null, array( 'elementor/core', 'directorist-elementor' ), true ) || ! is_string( $row->native_id ?? null ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,127}$/D', $row->native_id ) || 'elementor-document' === $row->native_id || ! is_string( $row->control_path ?? null ) || ! preg_match( '/^[a-z_][a-z0-9_.-]{0,159}$/D', $row->control_path ) || ! is_string( $row->native_key ?? null ) || ! preg_match( '/^[a-z_][a-z0-9_]{0,127}$/D', $row->native_key ) || ! preg_match( '/(?:^|_)color(?:_|$)/D', $row->native_key ) || str_contains( $row->native_key, '__' ) || preg_match( '/(?:^|_)(?:query|directory|binding|globals|dynamic|css|script|content)(?:_|$)/D', $row->native_key ) || 'native_decoration_inactive' !== ( $row->reason ?? null ) ) { $deny(); }
			$key = $canonical->encode( array( $row->provider_id, $row->native_id, $row->control_path, $row->native_key ) );
			if ( ! isset( $requested[ $key ] ) || isset( $seen[ $key ] ) ) { $deny(); }
			$seen[ $key ] = true;
			$result[] = (object) array( 'provider_id' => $row->provider_id, 'native_id' => $row->native_id, 'control_path' => $row->control_path, 'native_key' => $row->native_key, 'reason' => $row->reason );
		}
		return $result;
	}

	/**
	 * Validate the complete tiny native note vocabulary.
	 *
	 * @param mixed $notes Installed compiler result notes.
	 * @return array Detached closed note vocabulary.
	 * @throws ContractException When the installed note is not declared.
	 */
	public static function validate( $notes ): array {
		if ( ! is_array( $notes ) || count( $notes ) > 4 || array_values( $notes ) !== $notes ) {
			throw new ContractException( 'unsupported_contract', 'Native result notes are invalid.' );
		}
		$seen = array();
		foreach ( $notes as $note ) {
			if ( ! $note instanceof \stdClass || array( 'code' ) !== array_keys( get_object_vars( $note ) ) || ! in_array( $note->code, array( 'card_padding_unavailable', 'unsupported_optional_settings_omitted' ), true ) || isset( $seen[ $note->code ] ) ) {
				throw new ContractException( 'unsupported_contract', 'Native result note is not declared.' );
			}
			$seen[ $note->code ] = true;
		}
		return array_map( static fn( object $note ): object => (object) array( 'code' => $note->code ), $notes );
	}
}
