<?php
/**
 * Contract portal bridge.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Contracts\Constants\ContractStatus;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;
use DoubleScale\Pro\Modules\Contracts\Rest\ContractShaper;
use DoubleScale\Pro\Modules\Contracts\Services\ContractUrl;

/**
 * ContractPortalProvider.
 */
final class ContractPortalProvider {

	private const TIMELINE_CAP = 50;

	public function __construct() {
		add_filter( 'doublescale_portal_timeline_items', array( $this, 'add_timeline_items' ), 10, 2 );
		add_filter( 'doublescale_portal_documents_rows', array( $this, 'add_document_rows' ), 10, 3 );
		add_filter( 'doublescale_portal_visible_document_count', array( $this, 'add_visible_document_count' ), 10, 2 );
		add_filter( 'doublescale_portal_calendar_events', array( $this, 'add_calendar_events' ), 10, 4 );
	}

	/**
	 * Project the contact's contract end dates onto the portal calendar.
	 *
	 * Mirrors {@see add_document_rows()} scoping exactly — same status/visibility
	 * gate — so a contract the customer cannot see in Documents can never surface
	 * as a calendar chip either. `end_date` is a DATE column, so the window's
	 * end-of-day bound is trimmed to its civil date before comparing.
	 *
	 * @param array<int, array<string, mixed>> $events        Calendar events.
	 * @param ContactModel|null                $contact       Resolved contact.
	 * @param string                           $start         Window start (Y-m-d).
	 * @param string                           $end_inclusive Window end (Y-m-d H:i:s).
	 * @return array<int, array<string, mixed>>
	 */
	public function add_calendar_events( array $events, $contact, string $start, string $end_inclusive ): array {
		if ( ! $this->is_active() || ! $contact instanceof ContactModel ) {
			return $events;
		}

		$contracts = ContractModel::where( 'contact_id', (int) $contact->id )
			->where( 'status', '!=', ContractStatus::DRAFT )
			->where( 'hide_from_customer', false )
			->where( 'is_trash', false )
			->whereNotNull( 'end_date' )
			->whereBetween( 'end_date', array( $start, substr( $end_inclusive, 0, 10 ) ) )
			->get();

		foreach ( $contracts as $contract ) {
			$events[] = array(
				'id'       => 'contract-' . (int) $contract->id,
				'kind'     => 'contract',
				'title'    => (string) $contract->subject,
				'start'    => (string) $contract->end_date,
				'end'      => null,
				'all_day'  => true,
				'timezone' => null,
				'status'   => (string) $contract->status,
				'route'    => '/documents',
			);
		}

		return $events;
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

		$contracts = ContractModel::where( 'contact_id', (int) $contact->id )
			->where( 'status', '!=', ContractStatus::DRAFT )
			->where( 'hide_from_customer', false )
			->where( 'is_trash', false )
			->orderBy( 'id', 'desc' )
			->limit( self::TIMELINE_CAP )
			->get();

		foreach ( $contracts as $contract ) {
			$items[] = array(
				'id'            => 'contract-' . (int) $contract->id,
				'kind'          => 'document',
				'document_type' => 'contract',
				'type'          => ContractStatus::SIGNED === (string) $contract->status ? 'contract_signed' : 'contract_sent',
				'date'          => (string) $contract->created_at,
				'title'         => (string) $contract->subject,
				'status'        => (string) $contract->status,
				'hash'          => (string) $contract->hash,
				'public_url'    => ContractUrl::get_public_url( $contract ),
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

		if ( ! in_array( $type, array( 'all', 'contract' ), true ) ) {
			return $rows;
		}

		$contracts = ContractModel::where( 'contact_id', (int) $contact->id )
			->where( 'status', '!=', ContractStatus::DRAFT )
			->where( 'hide_from_customer', false )
			->where( 'is_trash', false )
			->orderBy( 'id', 'desc' )
			->limit( 100 )
			->get();

		foreach ( $contracts as $contract ) {
			$rows[] = array(
				'id'          => (int) $contract->id,
				'type'        => 'contract',
				'number'      => (string) $contract->contract_number,
				'subject'     => (string) $contract->subject,
				'status'      => (string) $contract->status,
				'date'        => $contract->start_date,
				'due_date'    => null,
				'open_till'   => $contract->end_date,
				'currency'    => \DoubleScale\Pro\Compat\SettingsCurrency::document_currency( $contract->currency, $contract->sent_at ),
				'total'       => (float) $contract->contract_value,
				'amount_paid' => null,
				'balance'     => null,
				'is_overdue'  => false,
				'is_expired'  => ContractShaper::is_expired( $contract ),
				'invoice_id'  => null,
				'hash'        => (string) $contract->hash,
				'public_url'  => ContractUrl::get_public_url( $contract ),
				'_sort'       => (string) $contract->created_at,
			);
		}

		return $rows;
	}

	/**
	 * @param int               $count   Current count.
	 * @param ContactModel|null $contact Resolved contact.
	 * @return int
	 */
	public function add_visible_document_count( int $count, $contact ): int {
		if ( ! $this->is_active() || ! $contact instanceof ContactModel ) {
			return $count;
		}

		$count += (int) ContractModel::where( 'contact_id', (int) $contact->id )
			->where( 'status', '!=', ContractStatus::DRAFT )
			->where( 'hide_from_customer', false )
			->where( 'is_trash', false )
			->count();

		return $count;
	}

	/**
	 * @return bool
	 */
	private function is_active(): bool {
		return function_exists( 'doublescale_sales_child_module_active' )
			&& doublescale_sales_child_module_active( 'contracts' );
	}
}
