<?php
/**
 * Safe native field diagnostics for a rejected listing proposal.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Listing;

use DirectoristAIPro\Validation\ContractException;

/** Identify the rejected control without exposing its value or validator prose. */
final class ListingFieldFeedback {

	/**
	 * Preserve authority failures and describe only native value rejection.
	 *
	 * @param ContractException $error Native validation failure.
	 * @param string            $id Server-bound logical field identity.
	 * @param string            $widget Installed native widget.
	 * @param string[]          $field_ids Bound controls in inspector order.
	 * @return ContractException Safe diagnostic; never an instruction to apply.
	 */
	public static function for_field( ContractException $error, string $id, string $widget, array $field_ids ): ContractException {
		if ( 'invalid_input' !== $error->error_code() ) {
			return $error;
		}
		$index = array_search( $id, $field_ids, true );
		if ( false === $index ) {
			return $error;
		}
		$codes = array( 'listing_native_value_invalid', 'listing_field_index_' . $index );
		if ( preg_match( '/^[a-z][a-z0-9_]{0,46}$/D', $id ) ) {
			$codes[] = 'listing_field_id_' . $id;
		}
		if ( preg_match( '/^[a-z][a-z0-9_]{0,47}$/D', $widget ) ) {
			$codes[] = 'listing_widget_' . $widget;
		}
		return new ContractException( 'invalid_input', 'A supplied value does not meet this native field\'s requirements.', array_merge( $codes, $error->repair_codes() ) );
	}
}
