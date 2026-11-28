<?php
/**
 * Strict inline image source validation shared by staging and native import.
 *
 * @package DirectoristAIPro
 */

// phpcs:disable NormalizedArrays.Arrays.CommaAfterLast.MissingMultiLine -- Retain exact reviewed PHP tokens.
namespace DirectoristAIPro\Domain;

use DirectoristAIPro\Validation\ContractException;

/** Remote provider URLs and model-authored paths never cross this boundary. */
final class MediaSource {
	public const MAX_BYTES = 2097152;

	/**
	 * Validate bounded inline image bytes and their expected hash.
	 *
	 * @return array{bytes:string,mime:string,extension:string,hash:string,size:int}
	 *
	 * @param string $encoded Reviewed input.
	 * @param string $declared_mime Reviewed input.
	 * @param string $expected_hash Reviewed input.
	 * @throws \DirectoristAIPro\Validation\ContractException When native validation fails.
	 */
	public static function from_inline( string $encoded, string $declared_mime, string $expected_hash = '' ): array {
		if ( strlen( $encoded ) > (int) ceil( self::MAX_BYTES * 4 / 3 ) + 4 || ! preg_match( '/^[A-Za-z0-9+\/=]+$/D', $encoded ) ) {
			throw new ContractException( 'invalid_input', 'Image source must be bounded inline data.' );
		}
		$bytes = base64_decode( $encoded, true );
		if ( false === $bytes || '' === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
			throw new ContractException( 'invalid_input', 'Image source exceeds the supported size.' );
		}
		$hash = hash( 'sha256', $bytes );
		if ( '' !== $expected_hash && ! hash_equals( $expected_hash, $hash ) ) {
			throw new ContractException( 'approval_mismatch', 'Reviewed image bytes changed.' );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Retain reviewed PHP tokens while validation remains in the surrounding code.
		$image      = @getimagesizefromstring( $bytes );
		$mime       = is_array( $image ) ? (string) ( $image['mime'] ?? '' ) : '';
		$extensions = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp'
		);
		if ( ! isset( $extensions[ $mime ] ) || $mime !== $declared_mime || (int) ( $image[0] ?? 0 ) < 1 || (int) ( $image[1] ?? 0 ) < 1 || (int) $image[0] > 4096 || (int) $image[1] > 4096 ) {
			throw new ContractException( 'invalid_input', 'Image MIME or dimensions are unsupported.' );
		}
		return array(
			'bytes'     => $bytes,
			'mime'      => $mime,
			'extension' => $extensions[ $mime ],
			'hash'      => $hash,
			'size'      => strlen( $bytes )
		);
	}
}
