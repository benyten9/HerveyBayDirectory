<?php
/** Native composite import values, never storage keys supplied by the model. */
namespace DirectoristAIPro\Import;

use DirectoristAIPro\Validation\ContractException;

// phpcs:disable Squiz.Commenting.FunctionComment -- Closed import/native adapter contract.
final class CompositeFields {

	public const COMPONENTS = array( 'pricing' => array( 'type', 'range' ), 'map' => array( 'longitude', 'hide_map' ) );

	/** Read the installed form's translated labels instead of inventing label aliases. */
	public static function range_choices( array $props ): array {
		$file = dirname( rtrim( DIRECTORIST_ASSETS_DIR, '/' ) ) . '/templates/listing-form/fields/pricing.php';
		if ( ! is_file( $file ) || ! class_exists( \DOMDocument::class ) ) { return array(); }
		$render = static function () use ( $file, $props ): string {
			$data = $props + array( 'price_unit_field_label' => '', 'price_range_label' => '', 'price_unit_field_type' => 'number' ); $data['pricing_type'] = 'both'; $price_unit_checkbox = true;
			$listing_form = new class {
				public function get_add_listing_id() { return 0; }
				public function get_conditional_logic_attributes( $data ) { return ''; }
				public function field_label_template( $data ) {}
			};
			ob_start(); try { include $file; return (string) ob_get_contents(); } finally { ob_end_clean(); }
		};
		$document = new \DOMDocument(); $previous = libxml_use_internal_errors( true );
		try { $document->loadHTML( '<?xml encoding="utf-8" ?>' . $render() ); } finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
		$choices = array();
		foreach ( $document->getElementsByTagName( 'select' ) as $select ) {
			if ( 'price_range' !== $select->getAttribute( 'name' ) ) { continue; }
			foreach ( $select->getElementsByTagName( 'option' ) as $option ) {
				$value = $option->getAttribute( 'value' ); if ( '' === $value ) { continue; }
				$label = trim( $option->textContent ); $aliases = array( $label, trim( preg_replace( '/\s*\([^)]*\)\s*$/u', '', $label ) ) );
				if ( preg_match( '/\(([^()]*)\)\s*$/u', $label, $match ) && '' !== trim( $match[1] ) ) { $aliases[] = trim( $match[1] ); }
				$choices[] = array( 'value' => $value, 'label' => $label, 'aliases' => array_values( array_unique( $aliases ) ) );
			}
		}
		return $choices;
	}

	public static function paths( array $entry ): array {
		if ( isset( $entry['components'] ) && ! is_array( $entry['components'] ) ) { throw new ContractException( 'mapping_invalid', 'Composite source paths must use the declared named components.' ); }
		return array_merge( array( $entry['source_path'] ?? null ), array_values( $entry['components'] ?? array() ) );
	}

	public static function convert( $value, string $widget, array $props, array $parts = array(), array $policy = array() ) {
		if ( null === $value || '' === $value ) {
			if ( ! array_filter( $parts, static fn( $v ) => null !== $v && '' !== $v ) ) { return null; }
		}
		if ( is_string( $value ) && '{' === substr( trim( $value ), 0, 1 ) ) {
			try { $value = json_decode( $value, true, 8, JSON_THROW_ON_ERROR ); }
			catch ( \JsonException $error ) { throw new ContractException( 'value_shape', 'Use a complete JSON object or map the separate source columns.' ); }
		}
		$source = is_array( $value ) ? $value : array( 'pricing' === $widget ? 'price' : 'latitude' => $value );
		$aliases = 'pricing' === $widget ? array( 'price_type' => 'type', 'price_range' => 'range' ) : array( 'manual_lat' => 'latitude', 'manual_lng' => 'longitude' );
		foreach ( $aliases as $alias => $name ) {
			if ( array_key_exists( $alias, $source ) ) {
				if ( array_key_exists( $name, $source ) && $source[ $name ] !== $source[ $alias ] ) { throw new ContractException( 'value_shape', 'Conflicting source values name the same native field.' ); }
				$source[ $name ] = $source[ $alias ]; unset( $source[ $alias ] );
			}
		}
		$allowed = 'pricing' === $widget ? array( 'type', 'price', 'range' ) : array( 'latitude', 'longitude', 'hide_map' );
		if ( array_diff( array_keys( $source ), $allowed ) || array_diff( array_keys( $parts ), self::COMPONENTS[ $widget ] ?? array() ) ) {
			throw new ContractException( 'value_shape', 'The source contains properties outside this native composite field. Map Address separately.' );
		}
		foreach ( $parts as $name => $part ) {
			if ( null === $part || '' === $part ) { continue; }
			if ( isset( $source[ $name ] ) && '' !== $source[ $name ] && $source[ $name ] !== $part && ! ( is_numeric( $source[ $name ] ) && is_numeric( $part ) && (float) $source[ $name ] === (float) $part ) ) { throw new ContractException( 'value_shape', 'Two mapped source columns disagree about this value.' ); }
			$source[ $name ] = $part;
		}
		return 'pricing' === $widget ? self::pricing( $source, $props, $policy ) : self::map( $source );
	}

	private static function number( $value, string $code, string $message ): float {
		if ( is_bool( $value ) || ! is_scalar( $value ) || ! is_numeric( $value ) || ! is_finite( (float) $value ) ) { throw new ContractException( $code, $message ); }
		return (float) $value;
	}

	private static function pricing( array $source, array $props, array $policy ): array {
		$mode = $props['pricing_type'] ?? 'both';
		if ( ! in_array( $mode, array( 'both', 'price_unit', 'price_range' ), true ) ) { throw new ContractException( 'setting_unverified', 'This saved pricing mode is not supported by the installed native field.' ); }
		$ranges = array( 'skimming', 'moderate', 'economy', 'bellow_economy' );
		$range_value = static function ( $value ) use ( $ranges, $policy ) {
			if ( ! is_string( $value ) || '' === $value ) { return $value; }
			if ( in_array( $value, $ranges, true ) ) { return $value; }
			$matches = array();
			foreach ( $policy['pricing_range_choices'] ?? array() as $choice ) {
				foreach ( $choice['aliases'] ?? array() as $alias ) { if ( is_string( $alias ) && 0 === strcasecmp( trim( $value ), $alias ) ) { $matches[] = $choice['value']; } }
			}
			$matches = array_values( array_unique( $matches ) );
			return 1 === count( $matches ) && in_array( $matches[0], $ranges, true ) ? $matches[0] : $value;
		};
		$price = $source['price'] ?? null; $range = $source['range'] ?? null; $type = $source['type'] ?? null;
		$range = $range_value( $range ); $primary_range = $range_value( $price );
		// A single native range column can occupy the primary source selector.
		if ( null === $range && is_string( $primary_range ) && in_array( $primary_range, $ranges, true ) ) { $range = $primary_range; $price = null; }
		if ( null === $type || '' === $type ) {
			$type = 'price_unit' === $mode ? 'price' : ( 'price_range' === $mode ? 'range' : ( null !== $range && '' !== $range ? 'range' : 'price' ) );
		}
		if ( ! in_array( $type, array( 'price', 'range' ), true ) || 'price_unit' === $mode && 'price' !== $type || 'price_range' === $mode && 'range' !== $type ) { throw new ContractException( 'value_pricing', 'The source price type does not match the selected directory’s saved pricing mode.' ); }
		if ( 'range' === $type ) {
			if ( null !== $price && '' !== $price ) { throw new ContractException( 'value_pricing', 'A native price range cannot also store a fixed amount. Map one published pricing mode per record.' ); }
			if ( ! in_array( $range, $ranges, true ) ) { throw new ContractException( 'value_pricing', 'Use a native price range: skimming, moderate, economy or bellow_economy.' ); }
			return array( 'price_type' => 'range', 'price' => '', 'price_range' => $range );
		}
		if ( null !== $range && '' !== $range ) { throw new ContractException( 'value_pricing', 'A fixed price cannot also store a native price range.' ); }
		$amount = self::number( $price, 'value_pricing', 'Use a finite numeric price without currency symbols or ambiguous separators.' );
		if ( $amount < 0 || abs( $amount - round( $amount, 2 ) ) > 0.00000001 ) { throw new ContractException( 'value_pricing', 'Use a nonnegative price with at most two decimal places.' ); }
		if ( 0.0 === $amount ) { throw new ContractException( 'value_pricing', 'Directorist’s native submission does not retain a zero fixed price. Leave pricing empty or review the source amount.' ); }
		if ( isset( $policy['allow_decimal'] ) && ! $policy['allow_decimal'] && floor( $amount ) !== $amount ) { throw new ContractException( 'value_pricing', 'The site’s saved pricing settings require a whole-number amount.' ); }
		foreach ( array( 'min_value' => true, 'min' => true, 'max_value' => false, 'max' => false ) as $name => $minimum ) {
			if ( isset( $props[ $name ] ) && is_numeric( $props[ $name ] ) && ( $minimum ? $amount < (float) $props[ $name ] : (float) $props[ $name ] > 0 && $amount > (float) $props[ $name ] ) ) { throw new ContractException( 'value_bounds', 'The source price exceeds the saved field bounds.' ); }
		}
		return array( 'price_type' => 'price', 'price' => round( $amount, 2 ), 'price_range' => '' );
	}

	private static function map( array $source ): array {
		$hidden = isset( $source['hide_map'] ) ? Mapping::convert( $source['hide_map'], 'boolean', array() ) : false;
		$lat = $source['latitude'] ?? null; $lng = $source['longitude'] ?? null;
		$has_lat = null !== $lat && '' !== $lat; $has_lng = null !== $lng && '' !== $lng;
		if ( $has_lat !== $has_lng || ! $hidden && ! $has_lat ) { throw new ContractException( 'value_coordinates', 'Map both latitude and longitude from the source. An address alone is not verified coordinates.' ); }
		if ( $has_lat ) {
			$latitude = self::number( $lat, 'value_coordinates', 'Use finite numeric latitude and longitude values.' );
			$longitude = self::number( $lng, 'value_coordinates', 'Use finite numeric latitude and longitude values.' );
			if ( $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ) { throw new ContractException( 'value_coordinates', 'Latitude must be between -90 and 90. Longitude must be between -180 and 180.' ); }
			if ( $hidden ) { throw new ContractException( 'value_map_visibility', 'A hidden native map discards coordinates. Leave coordinates unmapped or show the map to retain them.' ); }
		}
		return array( 'hide_map' => (bool) $hidden, 'manual_lat' => $has_lat ? trim( (string) $lat ) : '', 'manual_lng' => $has_lng ? trim( (string) $lng ) : '' );
	}

	public static function hydrate( string $widget, $value, array &$posted ): void {
		if ( 'pricing' === $widget ) {
			$posted['atbd_listing_pricing'] = $value['price_type'] ?? '';
			$posted['price'] = $value['price'] ?? '';
			$posted['price_range'] = $value['price_range'] ?? '';
		} elseif ( is_array( $value ) ) {
			$posted['hide_map'] = $value['hide_map'];
			$posted['manual_lat'] = $value['manual_lat'];
			$posted['manual_lng'] = $value['manual_lng'];
		}
	}

	public static function sanitized( string $widget, array $value ): array {
		if ( 'pricing' === $widget ) {
			return array( 'price_type' => $value['price_type'], 'price' => 'price' === $value['price_type'] ? (float) $value['price'] : '', 'price_range' => 'range' === $value['price_type'] ? $value['price_range'] : '' );
		}
		return array( 'hide_map' => (bool) $value['hide_map'], 'manual_lat' => (string) $value['manual_lat'], 'manual_lng' => (string) $value['manual_lng'] );
	}
}
