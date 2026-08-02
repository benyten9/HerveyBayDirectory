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
				'date'          => (string) $credit_note->created_at,
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
				'currency'    => \DoubleScale\Core\Settings\Settings::document_currency( $credit_note->currency, $credit_note->sent_at ),
				'total'       => (float) $credit_note->total,
				'amount_paid' => (float) $credit_note->amount_applied,
				'balance'     => max( 0, round( (float) $credit_note->total - (float) $credit_note->amount_applied, 2 ) ),
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
}
