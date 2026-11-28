<?php
/**
 * Closed permission-filtered directory facts contract.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag -- Compact shared boundary.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Prevents labels, values or unknown native data from reaching builder providers. */
final class DirectoryFactsValidator {
	public function normalize( object $facts, int $directory_id ): object {
		$keys     = array_keys( get_object_vars( $facts ) );
		$required = array( 'complete', 'directory_id', 'field_ids', 'limitations', 'revision' );
		$allowed  = array_merge( $required, array( 'directory_slug', 'fields' ) );
		if ( array_diff( $required, $keys ) || array_diff( $keys, $allowed ) || $facts->directory_id !== $directory_id || ! is_string( $facts->revision ) || ! preg_match( '/^[a-f0-9]{64}$/D', $facts->revision ) || ! is_bool( $facts->complete ) || ! is_array( $facts->field_ids ) || ! is_array( $facts->limitations ) ) {
			throw new ContractException( 'unsupported_contract', 'Directory facts contract is invalid.' );
		}
		$field_ids   = ProviderRegistration::tokens( $facts->field_ids );
		$limitations = ProviderRegistration::tokens( $facts->limitations );
		$slug        = $facts->directory_slug ?? null;
		if ( null !== $slug && ( ! is_string( $slug ) || ! ProviderRegistration::valid_token( $slug ) ) ) {
			throw new ContractException( 'unsupported_contract', 'Directory slug is invalid.' );
		}
		$fields = array();
		foreach ( $facts->fields ?? array() as $field ) {
			$field_keys = $field instanceof \stdClass ? array_keys( get_object_vars( $field ) ) : array();
			sort( $field_keys, SORT_STRING );
			$has_admin_only = $field instanceof \stdClass && property_exists( $field, 'admin_only' );
			$expected_keys  = array( 'conditional_logic', 'editable', 'field_id', 'required', 'type', 'widget' );
			if ( $has_admin_only ) {
				array_unshift( $expected_keys, 'admin_only' );
			}
			if ( ! $field instanceof \stdClass || $expected_keys !== $field_keys || ( $has_admin_only && ! is_bool( $field->admin_only ) ) || ! is_string( $field->field_id ) || ! in_array( $field->field_id, $field_ids, true ) || ! is_string( $field->type ) || ! ProviderRegistration::valid_token( $field->type ) || ! is_string( $field->widget ) || ! ProviderRegistration::valid_token( $field->widget ) || ! is_bool( $field->required ) || ! is_bool( $field->editable ) ) {
				throw new ContractException( 'unsupported_contract', 'Directory field fact is invalid.' );
			}
			( new JsonDecoder( 65536, 16 ) )->check( $field->conditional_logic );
			$fields[] = json_decode( json_encode( $field, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 18, JSON_THROW_ON_ERROR );
		}
		usort( $fields, static fn( object $left, object $right ): int => $left->field_id <=> $right->field_id );
		return (object) array(
			'directory_id'   => $directory_id,
			'revision'       => $facts->revision,
			'field_ids'      => $field_ids,
			'complete'       => $facts->complete,
			'limitations'    => $limitations,
			'directory_slug' => $slug,
			'fields'         => $fields,
		);
	}
}
