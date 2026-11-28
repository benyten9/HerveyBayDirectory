<?php
/** Deterministic Markdown for already permitted native facts.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Readiness;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Pure bounded text formatting.

/** Formatting never invokes a provider or allocates an AI task. */
final class Markdown {
	public static function plain( string $value ): string {
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Keep visible block boundaries: stripping <p>1</p><p>2</p> must not invent the value "12".
		$value = preg_replace( '~<(?:br\b[^>]*|/(?:p|div|li|h[1-6]|tr|section|article|blockquote)\s*)>~i', "\n", $value );
		$value = wp_strip_all_tags( strip_shortcodes( $value ) );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );
		return trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $value ) );
	}

	public static function text( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'Yes' : 'No';
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = self::plain( (string) $value );
		return preg_replace( '/([\\\\`*_{}\[\]()#+.!|>~\-])/u', '\\\\$1', $value );
	}

	public static function url( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '~^https?://~i', $value ) ) {
			return '';
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		return str_replace( array( '(', ')', '[', ']', '<', '>', '"', "\n", "\r" ), array( '%28', '%29', '%5B', '%5D', '%3C', '%3E', '%22', '', '' ), $url );
	}

	public static function link( string $label, string $url ): string {
		return '[' . str_replace( "\n", ' ', self::text( $label ) ) . '](' . self::url( $url ) . ')';
	}

	public static function listing( array $facts ): string {
		$lines = array( '# ' . str_replace( "\n", ' ', self::text( $facts['title'] ) ), '', 'Canonical source: ' . self::link( $facts['canonical'], $facts['canonical'] ), 'Source modified (UTC): ' . self::text( $facts['modified_gmt'] ), 'Directory: ' . self::text( $facts['directory_name'] ), '', '## Public listing facts', '' );
		foreach ( $facts['fields'] as $field ) {
			if ( 'title' === $field['id'] ) {
				continue;
			}
			$value = $field['value'];
			if ( 'faqs' === $field['id'] && is_array( $value ) ) {
				$lines[] = '### FAQs';
				$lines[] = '';
				foreach ( $value as $faq ) {
					$lines[] = '- Question: ' . str_replace( "\n", "\n  ", self::text( $faq->question ) );
					$lines[] = '  Answer: ' . str_replace( "\n", "\n  ", self::text( $faq->answer ) );
				}
				continue;
			}
			if ( 'hours' === $field['id'] && is_object( $value ) ) {
				$lines[] = '- Business hours timezone: ' . self::text( $value->timezone );
				$lines[] = '- Always open: ' . self::text( $value->always_open );
				foreach ( $value->schedule as $day ) {
					$slots   = array_map( static fn( $slot ): string => $slot->start . '–' . $slot->close, $day->slots );
					$lines[] = '  - ' . self::text( ucfirst( $day->day ) ) . ': ' . ( ! $day->enabled ? 'Closed' : ( $day->all_day ? 'Open all day' : self::text( implode( ', ', $slots ) ) ) );
				}
				continue;
			}
			$text = is_array( $value ) ? implode( ', ', array_map( array( self::class, 'text' ), $value ) ) : self::text( $value );
			if ( '' !== $text ) {
				$lines[] = '- ' . str_replace( "\n", ' ', self::text( $field['label'] ) ) . ': ' . str_replace( "\n", "\n  ", $text );
			}
		}
		$lines[] = '';
		$lines[] = 'These facts come from the linked public listing. Empty, hidden and unsupported fields are omitted.';
		return implode( "\n", $lines ) . "\n";
	}
}
