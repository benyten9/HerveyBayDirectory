<?php
/**
 * Consent.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * A tick box for terms, email, SMS or WhatsApp consent. Never ticked in advance (GDPR), never
 * written to a contact field (the consent log and subscription status record it).
 */
class ConsentField extends FieldType {

	/** Channels a consent box can be for. */
	public const CHANNELS = array( 'terms', 'email', 'sms', 'whatsapp' );

	/** {@inheritDoc} */
	public function type(): string {
		return 'consent';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Consent', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function takes_default(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public function saves_to_contact(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public function in_timeline(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public function props( array $props, array $raw ): array {
		$channel          = (string) ( $props['channel'] ?? 'terms' );
		$props['channel'] = in_array( $channel, self::CHANNELS, true ) ? $channel : 'terms';
		return $props;
	}

	/** {@inheritDoc} */
	public function sanitize( array $field, $value ) {
		return in_array( $value, array( true, 1, '1', 'true', 'yes', 'on' ), true ) ? true : null;
	}

	/** {@inheritDoc} */
	public function readable( array $field, $value ): string {
		return $value ? __( 'Yes', 'doublescale' ) : __( 'No', 'doublescale' );
	}
}
