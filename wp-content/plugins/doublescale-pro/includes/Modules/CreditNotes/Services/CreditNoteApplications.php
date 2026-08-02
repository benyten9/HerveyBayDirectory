<?php
/**
 * Sync credit note amount_applied and status from application records.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Constants\PaymentMode;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\PaymentModel;
use DoubleScale\Modules\Documents\Services\InvoicePayments;
use DoubleScale\Pro\Modules\CreditNotes\Constants\CreditNoteStatus;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteApplicationModel;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;
use WP_Error;

/**
 * CreditNoteApplications service.
 */
class CreditNoteApplications {

	/**
	 * Prefix for payment transaction_id linking to an application row.
	 */
	public const PAYMENT_REF_PREFIX = 'cn_application:';

	/**
	 * Apply credit from a credit note to an invoice.
	 *
	 * @param CreditNoteModel $credit_note Credit note.
	 * @param InvoiceModel    $invoice     Target invoice.
	 * @param float           $amount      Amount to apply.
	 * @param string|null     $note        Optional note.
	 * @return array{credit_note: CreditNoteModel, invoice: InvoiceModel}|WP_Error
	 */
	public function apply( CreditNoteModel $credit_note, InvoiceModel $invoice, float $amount, ?string $note = null ) {
		if ( ! function_exists( 'doublescale_sales_child_module_active' )
			|| ! doublescale_sales_child_module_active( 'documents' ) ) {
			return new WP_Error(
				'module_disabled',
				__( 'Invoice documents must be enabled to apply credit.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		$amount = round( $amount, 2 );
		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_data', __( 'Application amount must be greater than zero.', 'doublescale' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		try {
			$credit_note = CreditNoteModel::query()
				->where( 'id', (int) $credit_note->id )
				->lockForUpdate()
				->first();

			if ( ! $credit_note ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'not_found', __( 'Credit note not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$invoice = InvoiceModel::query()
				->where( 'id', (int) $invoice->id )
				->lockForUpdate()
				->first();

			if ( ! $invoice ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			if ( in_array( (string) $credit_note->status, array( CreditNoteStatus::DRAFT, CreditNoteStatus::VOID ), true ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'invalid_status', __( 'Credit cannot be applied from this credit note.', 'doublescale' ), array( 'status' => 400 ) );
			}

			if ( InvoiceStatus::DRAFT === (string) $invoice->status ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'invalid_status', __( 'Credit cannot be applied to draft invoices.', 'doublescale' ), array( 'status' => 400 ) );
			}

			if ( (int) $credit_note->contact_id !== (int) $invoice->contact_id ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'invalid_data', __( 'Credit note and invoice must belong to the same customer.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$credit_remaining = $this->credit_remaining( $credit_note );
			if ( $amount > $credit_remaining + 0.001 ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error(
					'credit_exceeds_remaining',
					__( 'Application amount exceeds the remaining credit on this credit note.', 'doublescale' ),
					array( 'status' => 422 )
				);
			}

			$invoice_balance = max( 0, round( (float) $invoice->total - (float) $invoice->amount_paid, 2 ) );
			if ( $amount > $invoice_balance + 0.001 ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error(
					'application_exceeds_balance',
					__( 'Application amount exceeds the balance due on this invoice.', 'doublescale' ),
					array( 'status' => 422 )
				);
			}

			$application = new CreditNoteApplicationModel();
			$application->fill(
				array(
					'credit_note_id'     => (int) $credit_note->id,
					'invoice_id'         => (int) $invoice->id,
					'amount'             => $amount,
					'applied_date'       => current_time( 'Y-m-d' ),
					'note'               => $note,
					'applied_by_user_id' => get_current_user_id() ?: null,
				)
			);
			$application->save();

			$payment = new PaymentModel();
			$payment->fill(
				array(
					'invoice_id'          => (int) $invoice->id,
					'amount'              => $amount,
					'payment_mode'        => PaymentMode::CREDIT_NOTE,
					'payment_date'        => current_time( 'Y-m-d' ),
					'transaction_id'      => self::PAYMENT_REF_PREFIX . (int) $application->id,
					'note'                => sprintf(
						/* translators: %s: credit note number */
						__( 'Credit note %s', 'doublescale' ),
						(string) $credit_note->credit_note_number
					),
					'recorded_by_user_id' => get_current_user_id() ?: null,
				)
			);
			$payment->save();

			$invoice     = ( new InvoicePayments() )->sync( $invoice );
			$credit_note = $this->sync( $credit_note );

			$wpdb->query( 'COMMIT' );

			do_action(
				'doublescale_sales_credit_note_applied',
				$credit_note,
				$invoice,
				$amount,
				(int) $application->id
			);

			return array(
				'credit_note' => $credit_note,
				'invoice'     => $invoice,
			);
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Remaining credit derived from application rows (source of truth).
	 *
	 * @param CreditNoteModel $credit_note Credit note.
	 * @return float
	 */
	private function credit_remaining( CreditNoteModel $credit_note ): float {
		$amount_applied = (float) CreditNoteApplicationModel::query()
			->where( 'credit_note_id', (int) $credit_note->id )
			->sum( 'amount' );

		return max( 0, round( (float) $credit_note->total - $amount_applied, 2 ) );
	}

	/**
	 * Revoke all applications (and linked invoice payments) before credit note deletion.
	 *
	 * @param CreditNoteModel $credit_note Credit note being deleted.
	 * @return void
	 */
	public function destroy_applications( CreditNoteModel $credit_note ): void {
		$applications = CreditNoteApplicationModel::query()
			->with( array( 'creditNote', 'invoice' ) )
			->where( 'credit_note_id', (int) $credit_note->id )
			->get();

		foreach ( $applications as $application ) {
			$this->revoke( $application );
		}
	}

	/**
	 * Revoke an application and restore balances.
	 *
	 * @param CreditNoteApplicationModel $application Application row.
	 * @return array{credit_note: CreditNoteModel, invoice: InvoiceModel}|WP_Error
	 */
	public function revoke( CreditNoteApplicationModel $application ) {
		$credit_note = $application->creditNote;
		$invoice     = $application->invoice;

		if ( ! $credit_note || ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Application references could not be resolved.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$payment = PaymentModel::query()
			->where( 'invoice_id', (int) $invoice->id )
			->where( 'transaction_id', self::PAYMENT_REF_PREFIX . (int) $application->id )
			->first();

		if ( $payment ) {
			$payment->delete();
		}

		$application->delete();

		$invoice     = ( new InvoicePayments() )->sync( $invoice );
		$credit_note = $this->sync( $credit_note );

		return array(
			'credit_note' => $credit_note,
			'invoice'     => $invoice,
		);
	}

	/**
	 * Recompute amount_applied and status from applications, then save.
	 *
	 * @param CreditNoteModel $credit_note Credit note.
	 * @return CreditNoteModel
	 */
	public function sync( CreditNoteModel $credit_note ): CreditNoteModel {
		$amount_applied = (float) CreditNoteApplicationModel::query()
			->where( 'credit_note_id', (int) $credit_note->id )
			->sum( 'amount' );

		$credit_note->amount_applied = round( $amount_applied, 2 );
		$credit_note->status         = self::derive_status( $credit_note );
		$credit_note->save();

		return $credit_note->fresh();
	}

	/**
	 * @param CreditNoteModel $credit_note Credit note.
	 * @return string
	 */
	public static function derive_status( CreditNoteModel $credit_note ): string {
		return self::derive_status_from_values(
			(string) $credit_note->status,
			(float) $credit_note->total,
			(float) $credit_note->amount_applied
		);
	}

	/**
	 * Pure status derivation for tests and reuse.
	 *
	 * @param string $current_status Stored credit note status.
	 * @param float  $total          Gross credit value.
	 * @param float  $amount_applied Amount allocated so far.
	 * @return string
	 */
	public static function derive_status_from_values( string $current_status, float $total, float $amount_applied ): string {
		if ( CreditNoteStatus::DRAFT === $current_status ) {
			return CreditNoteStatus::DRAFT;
		}

		if ( CreditNoteStatus::VOID === $current_status ) {
			return CreditNoteStatus::VOID;
		}

		if ( $total > 0 && $amount_applied >= $total ) {
			return CreditNoteStatus::APPLIED;
		}

		if ( $amount_applied > 0 ) {
			return CreditNoteStatus::PARTIALLY_APPLIED;
		}

		return CreditNoteStatus::OPEN;
	}
}
