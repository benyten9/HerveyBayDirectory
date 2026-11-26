<?php
/**
 * Website.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * A web address; "example.com" becomes "https://example.com".
 */
class UrlField extends FieldType {

	/** {@inheritDoc} */
	public function type(): string {
		return 'url';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Website', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function sanitize( array $field, $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return null;
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $value ) ) {
			$value = 'https://' . $value;
		}
		return self::cut( esc_url_raw( $value ), 500 );
	}

	/** {@inheritDoc} */
	public function validate( array $field, $value, array $texts ): string {
		return self::is_web_address( (string) $value ) ? '' : $this->say( $texts, 'invalidUrl' );
	}

	/**
	 * A web address the visitor typed: http(s) with a real-looking host. Format only, no
	 * network lookups (wp_http_validate_url() resolves the host, which a visitor must not
	 * be able to make the server do).
	 *
	 * @param string $value Cleaned answer.
	 * @return bool
	 */
	private static function is_web_address( string $value ): bool {
		if ( strlen( $value ) > 500 || ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$parts = wp_parse_url( $value );
		$host  = (string) ( $parts['host'] ?? '' );
		return in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& '' !== $host
			&& strlen( $host ) <= 253
			&& (bool) preg_match( '/^[a-z0-9.\-]+$/i', $host );
	}
}
