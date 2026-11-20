<?php
/**
 * Timeline sentence for a client who changed their own details in the client
 * portal: who, and each field's old and new value.
 *
 * Both timelines word it here — `ActivityManager` (`/timeline`) and
 * `ActivityModel::formatted_message` (`/activities`).
 *
 * @package DoubleScale\Modules\Activities
 */

namespace DoubleScale\Modules\Activities\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;

/**
 * DetailsActivityPresenter.
 */
final class DetailsActivityPresenter {

	/**
	 * Whether the activity type is a details change.
	 *
	 * @param string $type Activity type.
	 * @return bool
	 */
	public static function is_details_type( string $type ): bool {
		return ActivityTypes::DETAILS_UPDATED === $type;
	}

	/**
	 * Field labels, as the contact page names them.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return array(
			'first_name'     => __( 'First name', 'doublescale' ),
			'last_name'      => __( 'Last name', 'doublescale' ),
			'phone'          => __( 'Phone', 'doublescale' ),
			'whatsapp_phone' => __( 'WhatsApp number', 'doublescale' ),
			'address_1'      => __( 'Address', 'doublescale' ),
			'address_2'      => __( 'Address line 2', 'doublescale' ),
			'city'           => __( 'City', 'doublescale' ),
			'state'          => __( 'State / Region', 'doublescale' ),
			'zip'            => __( 'ZIP / Postal code', 'doublescale' ),
			'country'        => __( 'Country', 'doublescale' ),
		);
	}

	/**
	 * "Mona Adel updated their details in the client portal: Phone +20100… →
	 * +20111…; City Cairo → Giza".
	 *
	 * @param array<string, mixed> $data Activity data (`actor_name`, `changes`).
	 * @return string
	 */
	public static function message( array $data ): string {
		$name = trim( (string) ( $data['actor_name'] ?? '' ) );
		$name = '' !== $name ? $name : __( 'The contact', 'doublescale' );

		/* translators: %s: contact name */
		$base = sprintf( __( '%s updated their details in the client portal', 'doublescale' ), $name );

		$labels = self::labels();
		// Custom fields (custom_field_<id>) carry the name they had when changed.
		foreach ( (array) ( $data['labels'] ?? array() ) as $field => $label ) {
			if ( is_string( $label ) && '' !== $label && ! isset( $labels[ $field ] ) ) {
				$labels[ $field ] = $label;
			}
		}
		$changes = isset( $data['changes'] ) && is_array( $data['changes'] ) ? $data['changes'] : array();
		// In the page's order, whatever order the data was stored in.
		$changes = array_merge( array_intersect_key( $labels, $changes ), $changes );
		$parts   = array();
		foreach ( $changes as $field => $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}
			$parts[] = sprintf(
				/* translators: 1: field name, 2: old value, 3: new value */
				__( '%1$s %2$s → %3$s', 'doublescale' ),
				$labels[ $field ] ?? (string) $field,
				self::shown( $change['old'] ?? null ),
				self::shown( $change['new'] ?? null )
			);
		}

		if ( empty( $parts ) ) {
			return $base;
		}

		/* translators: 1: activity message, 2: list of changes */
		return sprintf( __( '%1$s: %2$s', 'doublescale' ), $base, implode( '; ', $parts ) );
	}

	/**
	 * A stored value as the team reads it.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function shown( $value ): string {
		$value = trim( (string) $value );

		return '' === $value ? __( '(empty)', 'doublescale' ) : $value;
	}
}
