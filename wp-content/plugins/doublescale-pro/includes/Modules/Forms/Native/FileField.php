<?php
/**
 * File upload, a DoubleScale Pro field type for native forms. Registered with Free's field
 * type registry (doublescale_native_form_field_type_classes); without Pro, Free keeps a
 * placeholder (Fields\ProField) so saved forms don't lose the question.
 *
 * @package DoubleScale\Pro\Modules\Forms\Native
 */

namespace DoubleScale\Pro\Modules\Forms\Native;

use DoubleScale\Modules\Forms\Native\Fields\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Upload tokens from the upload endpoint (Uploads), at most the field's number of files.
 * Files stay with the visit that uploaded them: never saved with an unfinished entry.
 */
class FileField extends FieldType {

	/** {@inheritDoc} */
	public function type(): string {
		return 'file';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'File upload', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function is_pro(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function is_upload(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function saved_in_partial(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public function in_timeline(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public function sanitize( array $field, $value ) {
		$clean = array();
		foreach ( self::as_list( $value ) as $token ) {
			if ( is_scalar( $token ) && preg_match( '/^[A-Za-z0-9_\-]{8,80}$/', (string) $token ) ) {
				$clean[] = (string) $token;
			}
		}
		// No more files than the field allows (the browser's limit is not enough).
		$most = max( 1, min( 20, (int) ( $field['props']['max_files'] ?? 1 ) ) );
		return empty( $clean ) ? null : array_slice( array_values( array_unique( $clean ) ), 0, $most );
	}

	/** {@inheritDoc} */
	public function readable( array $field, $value ): string {
		$names = array_filter(
			(array) $value,
			static function ( $item ) {
				return is_string( $item ) && ! preg_match( '/^[a-f0-9]{48}$/', $item );
			}
		);
		$count = count( (array) $value );
		/* translators: %d: number of files */
		return $names ? implode( ', ', $names ) : sprintf( _n( '%d file', '%d files', $count, 'doublescale' ), $count );
	}
}
