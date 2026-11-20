<?php
/**
 * A client's account statement for the client portal.
 *
 * For a period, one statement per currency (amounts in different currencies
 * are never added together): the balance brought forward from before the
 * period, the invoices the client was sent as charges (drafts never count),
 * payments and credit notes as credits, a running balance and the balance at
 * the end.
 *
 * Credit notes come from Pro (`doublescale_portal_statement_rows`). Applying
 * a credit note to an invoice also writes a payment row (mode `credit_note`):
 * when Pro sends credit-note rows (and says so with
 * `doublescale_portal_statement_credit_notes`), those payment rows are left
 * out so the credit counts once; without them they stay, so the balance is
 * still right. Pro counts a credit note for what was applied, so the balance
 * due is what is left to pay; credit not applied yet comes apart, by currency,
 * through `doublescale_portal_statement_available_credit`.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\CurrencyResolver;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;

/**
 * PortalStatement.
 */
final class PortalStatement {

	/** Payment mode of a credit note applied to an invoice. */
	private const CREDIT_NOTE_MODE = 'credit_note';

	/**
	 * The statement of a client for a period, inclusive ("Y-m-d").
	 *
	 * @param ContactModel $contact Client.
	 * @param string       $from    First day.
	 * @param string       $to      Last day.
	 * @return array{from: string, to: string, currencies: array<int, array<string, mixed>>}
	 */
	public static function build( ContactModel $contact, string $from, string $to ): array {
		$rows = self::all_rows( $contact );

		/**
		 * Credit the client has not used yet, by currency (Pro's credit notes).
		 *
		 * @param array<string, float> $credit  Currency => amount.
		 * @param ContactModel         $contact Client.
		 */
		$available = (array) apply_filters( 'doublescale_portal_statement_available_credit', array(), $contact );

		$by_currency = array();
		foreach ( $rows as $row ) {
			$currency = (string) $row['currency'];
			if ( ! isset( $by_currency[ $currency ] ) ) {
				$by_currency[ $currency ] = array(
					'currency' => $currency,
					'opening'  => 0.0,
					'charges'  => 0.0,
					'credits'  => 0.0,
					'rows'     => array(),
				);
			}
			$day = (string) $row['date'];
			if ( $day < $from ) {
				$by_currency[ $currency ]['opening'] += (float) $row['charge'] - (float) $row['credit'];
			} elseif ( $day <= $to ) {
				$by_currency[ $currency ]['rows'][] = $row;
			}
		}

		// A currency with credit left and nothing else still gets its statement.
		foreach ( array_keys( $available ) as $currency ) {
			if ( ! isset( $by_currency[ $currency ] ) ) {
				$by_currency[ $currency ] = array(
					'currency' => (string) $currency,
					'opening'  => 0.0,
					'charges'  => 0.0,
					'credits'  => 0.0,
					'rows'     => array(),
				);
			}
		}

		$statements = array();
		foreach ( $by_currency as $currency => $statement ) {
			usort(
				$statement['rows'],
				static function ( $a, $b ) {
					// By day; on the same day the charge before what pays it.
					return array( $a['date'], $a['charge'] > 0 ? 0 : 1, $a['number'] ) <=> array( $b['date'], $b['charge'] > 0 ? 0 : 1, $b['number'] );
				}
			);
			$balance = round( $statement['opening'], 2 );
			foreach ( $statement['rows'] as &$row ) {
				$balance                = round( $balance + (float) $row['charge'] - (float) $row['credit'], 2 );
				$row['balance']         = $balance;
				$statement['charges']  += (float) $row['charge'];
				$statement['credits']  += (float) $row['credit'];
			}
			unset( $row );
			$statement['available_credit'] = round( (float) ( $available[ $currency ] ?? 0 ), 2 );
			// Nothing then or now in this currency: no statement for it.
			if ( empty( $statement['rows'] ) && 0.0 === round( $statement['opening'], 2 ) && 0.0 === $statement['available_credit'] ) {
				continue;
			}
			$statement['opening'] = round( $statement['opening'], 2 );
			$statement['charges'] = round( $statement['charges'], 2 );
			$statement['credits'] = round( $statement['credits'], 2 );
			$statement['closing'] = $balance;
			$statements[]         = $statement;
		}
		usort( $statements, static fn( $a, $b ) => strcmp( (string) $a['currency'], (string) $b['currency'] ) );

		return array(
			'from'       => $from,
			'to'         => $to,
			'currencies' => $statements,
		);
	}

	/**
	 * Every charge and credit of the client, any date.
	 *
	 * @param ContactModel $contact Client.
	 * @return array<int, array<string, mixed>>
	 */
	private static function all_rows( ContactModel $contact ): array {
		/**
		 * Whether Pro adds the client's credit notes to the statement. When it
		 * does, the payment rows applying them are left out (counted once).
		 *
		 * @param bool         $adds    Pro adds credit notes.
		 * @param ContactModel $contact Client.
		 */
		$credit_notes_added = (bool) apply_filters( 'doublescale_portal_statement_credit_notes', false, $contact );

		$rows     = array();
		$invoices = \DoubleScale\Modules\Portal\Services\PortalScope::apply( InvoiceModel::with( array( 'payments' ) ), (int) $contact->id, 'invoices' )
			->where( 'status', '!=', InvoiceStatus::DRAFT )
			->get();

		foreach ( $invoices as $invoice ) {
			$currency = CurrencyResolver::resolve( $invoice );
			$rows[]   = array(
				'type'     => 'invoice',
				'date'     => self::day( $invoice->invoice_date ),
				'number'   => (string) $invoice->invoice_number,
				'currency' => $currency,
				'charge'   => round( (float) $invoice->total, 2 ),
				'credit'   => 0.0,
				'hash'     => (string) $invoice->hash,
			);
			foreach ( $invoice->payments as $payment ) {
				if ( $credit_notes_added && self::CREDIT_NOTE_MODE === (string) $payment->payment_mode ) {
					continue;
				}
				$rows[] = array(
					'type'     => 'payment',
					'date'     => self::day( $payment->payment_date ),
					'number'   => (string) $invoice->invoice_number,
					'currency' => $currency,
					'charge'   => 0.0,
					'credit'   => round( (float) $payment->amount, 2 ),
					'hash'     => (string) $invoice->hash,
				);
			}
		}

		/**
		 * More charges or credits on the client's statement (Pro: credit notes).
		 * Each row: type, date (Y-m-d), number, currency, charge, credit, hash.
		 *
		 * @param array<int, array<string, mixed>> $rows    Rows so far.
		 * @param ContactModel                     $contact Client.
		 */
		$rows = (array) apply_filters( 'doublescale_portal_statement_rows', $rows, $contact );

		return array_values(
			array_filter(
				$rows,
				static fn( $row ) => is_array( $row ) && '' !== (string) ( $row['date'] ?? '' ) && '' !== (string) ( $row['currency'] ?? '' )
			)
		);
	}

	/**
	 * @param mixed $value Stored date or datetime.
	 * @return string "Y-m-d" or ''.
	 */
	private static function day( $value ): string {
		$value = (string) $value;
		return ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) ? '' : substr( $value, 0, 10 );
	}
}
