<?php
/**
 * Write-path helpers for per-document currency.
 *
 * Resolution (read path) lives on Settings::document_currency(). Freeze and
 * lock belong here so a draft's NULL inherit is honoured until send, then
 * written atomically with sent_at.
 *
 * @package DoubleScale\Core\Services
 */

namespace DoubleScale\Core\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\Currencies;
use DoubleScale\Core\Settings\Settings;
use WP_Error;

/**
 * DocumentCurrency class.
 */
final class DocumentCurrency {

	/**
	 * Sanitize a REST currency input.
	 *
	 * null / '' → null (inherit global). Lowercase codes are normalised.
	 * Junk codes are rejected.
	 *
	 * @param mixed $raw Raw request value.
	 * @return string|null|WP_Error
	 */
	public static function sanitize_input( $raw ) {
		$code = Currencies::normalize( $raw );
		if ( null === $code ) {
			return null;
		}
		if ( ! Currencies::is_valid( $code ) ) {
			return new WP_Error(
				'invalid_currency',
				__( 'Please choose a valid currency.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}
		return $code;
	}

	/**
	 * If the stored column is still inherit (NULL/empty), write the resolved
	 * global. Call this immediately before setting sent_at.
	 *
	 * @param object $model Document model with a `currency` attribute.
	 * @return void
	 */
	public static function freeze_on_send( $model ): void {
		if ( ! is_object( $model ) ) {
			return;
		}
		if ( empty( $model->currency ) ) {
			$model->currency = Settings::get_currency();
		}
	}

	/**
	 * Reject a currency change on a sent, paid, or proposal-linked invoice.
	 *
	 * Same-value writes are allowed so an edit that re-submits the current
	 * currency does not 400.
	 *
	 * Settled value locks the currency too, not just sending. Invoices track it
	 * in `amount_paid`; credit notes use `amount_applied`. An invoice converted
	 * from a proposal is locked because the proposal already chose the currency.
	 *
	 * @param object     $model             Document model.
	 * @param mixed      $new_currency      Incoming stored value (null = inherit).
	 * @param bool       $lock_when_settled Also lock once money/credit has moved.
	 * @return WP_Error|null
	 */
	public static function reject_if_locked( $model, $new_currency, bool $lock_when_settled = false ) {
		if ( ! is_object( $model ) ) {
			return null;
		}

		$incoming = Currencies::stored_or_null( $new_currency );
		$current  = Currencies::stored_or_null( $model->currency ?? null );
		if ( $incoming === $current ) {
			return null;
		}

		$locked = ! empty( $model->sent_at );
		if ( isset( $model->proposal_id ) && (int) $model->proposal_id > 0 ) {
			$locked = true;
		}
		if ( $lock_when_settled ) {
			foreach ( array( 'amount_paid', 'amount_applied' ) as $settled_column ) {
				if ( isset( $model->{$settled_column} ) && (float) $model->{$settled_column} > 0 ) {
					$locked = true;
					break;
				}
			}
		}

		if ( ! $locked ) {
			return null;
		}

		return new WP_Error(
			'currency_locked',
			__( 'Currency is locked once a document is sent, a payment has been recorded, or this invoice was converted from a proposal.', 'doublescale' ),
			array( 'status' => 400 )
		);
	}
}
