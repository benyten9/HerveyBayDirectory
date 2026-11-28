<?php
/**
 * Source-supported public discovery facts; no projected search index.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat\Workflows;

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.FunctionCommentThrowTag -- Fixed native fact adapter methods.

/** Public native fields admit price, coordinates, hours and FAQ facts. */
final class PublicListingFacts {
	/** Public pricing has one active native branch; edit controls keep their raw values. */
	public static function active_pricing( $value ): object {
		$values = is_object( $value ) ? get_object_vars( $value ) : ( is_array( $value ) ? $value : array() );
		$result = new \stdClass();
		if ( array_key_exists( 'type', $values ) && ( is_scalar( $values['type'] ) || null === $values['type'] ) ) {
			$result->type = $values['type']; }
		$branch = 'price' === ( $values['type'] ?? null ) ? 'price' : ( 'range' === ( $values['type'] ?? null ) ? 'range' : null );
		if ( null !== $branch && array_key_exists( $branch, $values ) ) {
			$result->$branch = $values[ $branch ]; }
		return $result;
	}

	public static function definitions( int $directory, array $native ): array {
		$layout = directorist_single_listings_contents( $directory );
		$result = array();
		if ( self::visible( $layout, $native, 'pricing' ) || self::header_price( $directory, $native ) ) {
			$result['directorist_ai_price'] = array(
				'widget_name' => 'ai_price',
				'type'        => 'number',
				'label'       => 'Price',
				'field_key'   => 'price',
			);
		}
		if ( self::visible( $layout, $native, 'map' ) ) {
			$result['directorist_ai_distance'] = array(
				'widget_name' => 'ai_distance',
				'type'        => 'distance',
				'label'       => 'Distance',
				'field_key'   => 'distance',
			);
		}
		if ( self::hours_loaded() && get_directorist_option( 'enable_business_hour', 1 ) && get_directorist_option( 'atbh_display_single_listing', 1 ) && self::visible( $layout, $native, 'business_hours' ) ) {
			$result['directorist_ai_open_now'] = array(
				'widget_name' => 'ai_open_now',
				'type'        => 'switch',
				'label'       => 'Open now',
				'field_key'   => 'open_now',
			);
			$result['directorist_ai_hours']    = array(
				'widget_name' => 'ai_hours',
				'type'        => 'object',
				'label'       => 'Business hours',
				'field_key'   => 'hours',
			);
		}
		if ( self::active( 'directorist-faqs/directorist-faqs.php' ) && defined( 'FAQS_VERSION' ) && '2.2.1' === FAQS_VERSION && self::renderer( 'FAQS_Post_Type_Manager' ) && get_directorist_option( 'enable_faqs', 1 ) && self::visible( $layout, $native, 'faqs' ) ) {
			$result['directorist_ai_faqs'] = array(
				'widget_name' => 'ai_faqs',
				'type'        => 'object',
				'label'       => 'FAQs',
				'field_key'   => 'faqs',
			);
		}
		return $result;
	}

	public static function fact( int $listing, string $widget ): array {
		$key   = substr( $widget, 3 );
		$value = null;
		if ( 'price' === $key && 'price' === \Directorist\Helper::pricing_type( $listing ) ) {
			$price = get_post_meta( $listing, '_price', true );
			if ( metadata_exists( 'post', $listing, '_price' ) && is_scalar( $price ) && is_numeric( $price ) && is_finite( (float) $price ) && (float) $price >= 0 ) {
				$value = (float) $price; }
		} elseif ( in_array( $key, array( 'open_now', 'hours' ), true ) && self::hours_loaded() && directorist_is_business_hour_enabled_for_listing( $listing ) && self::plan( $listing, 'is_plan_allowed_business_hours' ) ) {
			$hours  = get_post_meta( $listing, '_bdbh', true );
			$always = (bool) get_post_meta( $listing, '_enable247hour', true );
			$zone   = get_post_meta( $listing, '_timezone', true );
			if ( ! $zone ) {
				$zone = get_directorist_option( 'timezone', 'America/New_York' ); }
			if ( is_string( $zone ) && in_array( $zone, \DateTimeZone::listIdentifiers(), true ) && ( $always || ( is_array( $hours ) && $hours ) ) ) {
				$previous = date_default_timezone_get();
				try {
					$status = directorist_business_hours_status_times( $listing );
				} finally {
					// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Native helper changes the global timezone; always restore the caller's timezone.
					date_default_timezone_set( $previous );
				}
				$open  = ! empty( $status['always_open'] ) || ! empty( $status['is_open'] );
				$value = 'open_now' === $key ? $open : (object) array(
					'timezone'      => $zone,
					'always_open'   => $always,
					'open_now'      => $open,
					'schedule'      => self::schedule( $hours, get_post_meta( $listing, '_bdbh_version', true ) ),
					'current_open'  => $status['current_open'] ?? null,
					'current_close' => $status['current_close'] ?? null,
					'next_open'     => $status['next_open'] ?? null,
					'next_close'    => $status['next_close'] ?? null,
				);
			}
		} elseif ( 'faqs' === $key && self::plan( $listing, 'is_plan_allowed_listing_faqs' ) ) {
			$faqs = get_post_meta( $listing, '_faqs', true );
			if ( is_array( $faqs ) ) {
				$value = array();
				foreach ( array_slice( $faqs, 0, 20 ) as $faq ) {
					if ( is_array( $faq ) && is_string( $faq['quez'] ?? null ) && is_string( $faq['ans'] ?? null ) ) {
						$value[] = (object) array(
							'question' => mb_substr( wp_strip_all_tags( strip_shortcodes( $faq['quez'] ) ), 0, 200 ),
							'answer'   => mb_substr( wp_strip_all_tags( strip_shortcodes( $faq['ans'] ) ), 0, 2000 ),
						);
					}
				}
			}
		}
		return array(
			'known' => null !== $value,
			'value' => $value,
			'kind'  => 'meta',
			'key'   => $key,
		);
	}

	/** Visibility follows fields actually referenced by native general sections. */
	private static function visible( array $layout, array $native, string $widget ): bool {
		foreach ( (array) ( $layout['groups'] ?? array() ) as $group ) {
			if ( ! is_array( $group ) || 'general_group' !== ( $group['type'] ?? '' ) ) {
				continue; }
			foreach ( (array) ( $group['fields'] ?? array() ) as $key ) {
				$field = is_scalar( $key ) ? ( $layout['fields'][ $key ] ?? null ) : null;
				if ( ! is_array( $field ) || ! empty( $field['only_for_admin'] ) || ( $field['widget_name'] ?? '' ) !== $widget ) {
					continue; }
				$source = $field['original_widget_key'] ?? null;
				if ( 'other_widgets' === ( $field['widget_group'] ?? '' ) ) {
					return true; }
				if ( is_scalar( $source ) && isset( $native[ $source ] ) && empty( $native[ $source ]['only_for_admin'] ) && ( $native[ $source ]['widget_name'] ?? '' ) === $widget ) {
					return true; }
			}
		}
		return false;
	}

	/** Native quick info renders the price widget from this exact header group. */
	private static function header_price( int $directory, array $native ): bool {
		$public_pricing = false;
		foreach ( $native as $field ) {
			if ( is_array( $field ) && 'pricing' === ( $field['widget_name'] ?? '' ) && empty( $field['only_for_admin'] ) ) {
				$public_pricing = true;
				break;
			}
		}
		if ( ! $public_pricing ) {
			return false;
		}
		foreach ( directorist_single_listing_header( $directory ) as $group ) {
			if ( ! is_array( $group ) || 'more-widgets-placeholder' !== ( $group['placeholderKey'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $group['selectedWidgets'] ?? array() ) as $widget ) {
				if ( is_array( $widget ) && 'price' === ( $widget['widget_name'] ?? '' ) && empty( $widget['only_for_admin'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function active( string $plugin ): bool {
		return \DirectoristAIPro\Extensions\ExtensionRegistry::active_plugin( $plugin );
	}
	/** Require the currently bound native item renderer without booting extensions. */
	private static function renderer( string $renderer_class ): bool {
		if ( ! class_exists( $renderer_class, false ) ) {
			return false; }
		global $wp_filter;
		foreach ( (array) ( $wp_filter['directorist_single_item_template']->callbacks ?? array() ) as $callbacks ) {
			foreach ( (array) $callbacks as $entry ) {
				$callback = $entry['function'] ?? null;
				if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && is_object( $callback[0] ) && is_a( $callback[0], $renderer_class ) && 'directorist_single_item_template' === $callback[1] && is_callable( $callback ) ) {
					return true; }
			}
		}
		return false;
	}
	private static function hours_loaded(): bool {
		return self::active( 'directorist-business-hours/bd-business-hour.php' ) && defined( 'BDBH_VERSION' ) && '3.7' === BDBH_VERSION && self::renderer( 'DBH_Directory_Type' ) && function_exists( 'directorist_business_hours_status_times' ) && function_exists( 'directorist_is_business_hour_enabled_for_listing' );
	}
	private static function plan( int $listing, string $flag ): bool {
		if ( ! function_exists( 'is_fee_manager_active' ) ) {
			return false; }
		if ( ! is_fee_manager_active() ) {
			return true; }
		$plan = (int) get_post_meta( $listing, '_fm_plans', true );
		return $plan > 0 && function_exists( $flag ) && (bool) $flag( $plan );
	}
	private static function schedule( $hours, $version = '' ): array {
		$result = array();
		foreach ( array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ) as $day ) {
			$data = is_array( $hours ) ? ( $hours[ $day ] ?? null ) : null;
			if ( ! is_array( $data ) ) {
				continue; }
			$slots = array();
			foreach ( array_slice( (array) ( $data['start'] ?? array() ), 0, 12 ) as $index => $start ) {
				$close = $data['close'][ $index ] ?? null;
				if ( is_string( $start ) && is_string( $close ) && preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $start ) && preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $close ) ) {
					$slots[] = (object) array(
						'start' => $start,
						'close' => $close,
					); }
			}
			$result[] = (object) array(
				'day'     => $day,
				'enabled' => ! empty( $data['enable'] ),
				'closed'  => ! empty( $version ) ? empty( $data['enable'] ) : ( in_array( $data['remain_close'] ?? '', array( 'on', '1' ), true ) ? true : null ),
				'all_day' => 'open' === ( $data['remain_close'] ?? '' ),
				'slots'   => $slots,
			);
		}
		return $result;
	}
}
