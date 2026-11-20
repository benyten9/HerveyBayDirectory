<?php
/**
 * Credit note portal bridge.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\CreditNotes\Constants\CreditNoteStatus;
use DoubleScale\Pro\Modules\CreditNotes\Rest\CreditNoteShaper;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;

/**
 * CreditNotePortalProvider.
 */
final class CreditNotePortalProvider {

	private const TIMELINE_CAP = 50;

	public function __construct() {
		add_filter( 'doublescale_portal_timeline_items', array( $this, 'add_timeline_items' ), 10, 2 );
		add_filter( 'doublescale_portal_documents_rows', array( $this, 'add_document_rows' ), 10, 3 );
		add_filter( 'doublescale_portal_visible_document_count', array( $this, 'add_visible_document_count' ), 10, 2 );
		add_filter( 'doublescale_client_portal_config', array( $this, 'inject_portal_config' ) );
		// The account statement: credit notes are credits, counted once.
		add_filter( 'doublescale_portal_statement_credit_notes', array( $this, 'statement_adds_credit_notes' ), 10, 2 );
		add_filter( 'doublescale_portal_statement_rows', array( $this, 'add_statement_rows' ), 10, 2 );
		add_filter( 'doublescale_portal_statement_available_credit', array( $this, 'available_credit' ), 10, 2 );
		// "Download all" in the portal: this type's PDFs.
		add_filter( 'doublescale_portal_document_pdf', array( $this, 'document_pdf' ), 10, 4 );
	}

	/**
	 * Credit notes go on the client's statement when they may see them; the
	 * payment rows applying them are then left out by free.
	 *
	 * @param bool              $adds    Adds credit notes.
	 * @param ContactModel|null $contact Client.
	 * @return bool
	 */
	public function statement_adds_credit_notes( $adds, $contact ): bool {
		return $this->is_active()
			&& $contact instanceof ContactModel
			&& ( ! function_exists( 'doublescale_portal_can' ) || doublescale_portal_can( 'credit_notes', $contact ) );
	}

	/**
	 * Each credit note sent (not a draft, not void) as a credit for what was
	 * applied to an invoice: the balance due stays what is left to pay, as on
	 * Home. What is not applied yet is available credit ({@see available_credit()}).
	 *
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param ContactModel|null                $contact Client.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_statement_rows( $rows, $contact ): array {
		$rows = is_array( $rows ) ? $rows : array();
		if ( ! $this->statement_adds_credit_notes( false, $contact ) ) {
			return $rows;
		}
		foreach ( $this->sent_notes( $contact ) as $note ) {
			$applied = round( (float) $note->amount_applied, 2 );
			if ( $applied <= 0 ) {
				continue;
			}
			$rows[] = array(
				'type'     => 'credit_note',
				'date'     => substr( (string) $note->credit_note_date, 0, 10 ),
				'number'   => (string) $note->credit_note_number,
				'currency' => \DoubleScale\Pro\Compat\SettingsCurrency::document_currency( $note->currency, $note->sent_at ),
				'charge'   => 0.0,
				'credit'   => $applied,
				'hash'     => (string) $note->hash,
			);
		}
		return $rows;
	}

	/**
	 * Credit the client has not used yet, by currency (never added across
	 * currencies): each sent credit note's amount not applied to an invoice.
	 *
	 * @param array<string, float> $credit  Currency => amount.
	 * @param ContactModel|null    $contact Client.
	 * @return array<string, float>
	 */
	public function available_credit( $credit, $contact ): array {
		$credit = is_array( $credit ) ? $credit : array();
		if ( ! $this->statement_adds_credit_notes( false, $contact ) ) {
			return $credit;
		}
		foreach ( $this->sent_notes( $contact ) as $note ) {
			$left = round( (float) $note->total - (float) $note->amount_applied, 2 );
			if ( $left <= 0 ) {
				continue;
			}
			$currency            = \DoubleScale\Pro\Compat\SettingsCurrency::document_currency( $note->currency, $note->sent_at );
			$credit[ $currency ] = round( (float) ( $credit[ $currency ] ?? 0 ) + $left, 2 );
		}
		return $credit;
	}

	/**
	 * The client's credit notes that count: sent, not void.
	 *
	 * @param ContactModel $contact Client.
	 * @return iterable<CreditNoteModel>
	 */
	private function sent_notes( ContactModel $contact ) {
		return CreditNoteModel::where( 'contact_id', (int) $contact->id )
			->whereNotIn( 'status', array( CreditNoteStatus::DRAFT, CreditNoteStatus::VOID ) )
			->get();
	}

	/**
	 * @param array<string, mixed> $config Localized portal config.
	 * @return array<string, mixed>
	 */
	public function inject_portal_config( array $config ): array {
		if ( ! $this->is_active() ) {
			return $config;
		}

		$config['credit_note_public_rest_url'] = esc_url_raw(
			rest_url( 'doublescale/v1/sales/public/credit-notes' )
		);

		return $config;
	}

	/**
	 * @param array<int, array<string, mixed>> $items   Timeline items.
	 * @param ContactModel|null                $contact Resolved contact.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_timeline_items( array $items, $contact ): array {
		if ( ! $this->is_active() || ! $contact instanceof ContactModel ) {
			return $items;
		}

		$credit_notes = CreditNoteModel::where( 'contact_id', (int) $contact->id )
			->where( 'status', '!=', CreditNoteStatus::DRAFT )
			->orderBy( 'id', 'desc' )
			->limit( self::TIMELINE_CAP )
			->get();

		foreach ( $credit_notes as $credit_note ) {
			$items[] = array(
				'id'            => 'credit-note-' . (int) $credit_note->id,
				'kind'          => 'document',
				'document_type' => 'credit_note',
				'type'          => 'credit_note_sent',
				// Sent (site time) in UTC like the other rows, else created.
				'date'          => ! empty( $credit_note->sent_at ) ? get_gmt_from_date( (string) $credit_note->sent_at ) : (string) $credit_note->created_at,
				'title'         => (string) $credit_note->credit_note_number,
				'status'        => (string) $credit_note->status,
				'hash'          => (string) $credit_note->hash,
				'public_url'    => (string) CreditNoteUrl::get_public_url( $credit_note ),
			);
		}

		return $items;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows    Document rows.
	 * @param ContactModel|null                $contact Resolved contact.
	 * @param string                           $type    Requested type filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_document_rows( array $rows, $contact, string $type ): array {
		if ( ! $this->is_active() || ! $contact instanceof ContactModel ) {
			return $rows;
		}

		if ( ! in_array( $type, array( 'all', 'credit_note' ), true ) ) {
			return $rows;
		}

		$credit_notes = CreditNoteModel::where( 'contact_id', (int) $contact->id )
			->where( 'status', '!=', CreditNoteStatus::DRAFT )
			->orderBy( 'id', 'desc' )
			->limit( 100 )
			->get();

		foreach ( $credit_notes as $credit_note ) {
			$rows[] = array(
				'id'          => (int) $credit_note->id,
				'type'        => 'credit_note',
				'number'      => (string) $credit_note->credit_note_number,
				'subject'     => $credit_note->reason,
				'status'      => (string) $credit_note->status,
				'date'        => $credit_note->credit_note_date,
				'due_date'    => null,
				'open_till'   => null,
				'currency'    => \DoubleScale\Pro\Compat\SettingsCurrency::document_currency( $credit_note->currency, $credit_note->sent_at ),
				'total'       => (float) $credit_note->total,
				'amount_paid' => (float) $credit_note->amount_applied,
				'balance'     => CreditNoteShaper::remaining( $credit_note ),
				'is_overdue'  => false,
				'is_expired'  => false,
				'invoice_id'  => $credit_note->invoice_id ? (int) $credit_note->invoice_id : null,
				'hash'        => (string) $credit_note->hash,
				'public_url'  => CreditNoteUrl::get_public_url( $credit_note ),
				'_sort'       => (string) $credit_note->created_at,
			);
		}

		return $rows;
	}

	/**
	 * @param int                $count   Current count.
	 * @param ContactModel|null  $contact Resolved contact.
	 * @return int
	 */
	public function add_visible_document_count( int $count, $contact ): int {
		if ( ! $this->is_active() || ! $contact instanceof ContactModel ) {
			return $count;
		}

		$count += (int) CreditNoteModel::where( 'contact_id', (int) $contact->id )
			->where( 'status', '!=', CreditNoteStatus::DRAFT )
			->count();

		return $count;
	}

	/**
	 * @return bool
	 */
	private function is_active(): bool {
		return function_exists( 'doublescale_sales_child_module_active' )
			&& doublescale_sales_child_module_active( 'credit_notes' );
	}

	/**
	 * The PDF of one of the client's credit notes for "Download all".
	 *
	 * @param string|null $bytes      PDF bytes so far.
	 * @param string      $type       Row type.
	 * @param int         $id         Document id.
	 * @param int         $contact_id Client.
	 * @return string|null
	 */
	public function document_pdf( $bytes, $type, $id, $contact_id ) {
		if ( 'credit_note' !== $type || ! $this->is_active() ) {
			return $bytes;
		}
		$doc = CreditNoteModel::find( (int) $id );
		if ( ! $doc || (int) $doc->contact_id !== (int) $contact_id ) {
			return $bytes;
		}
		$pdf = \DoubleScale\Pro\Modules\CreditNotes\Services\CreditNotePdf::render_pdf( CreditNoteShaper::shape_public( $doc ) );
		return is_string( $pdf ) ? $pdf : $bytes;
	}

}
