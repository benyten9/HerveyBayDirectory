<?php
/**
 * Sales ⇄ Client Portal bridge.
 *
 * Contributes the Documents section (invoices + proposals), the "outstanding
 * balance" dashboard summary card, and recent document-lifecycle timeline rows
 * to the portal. Document lifecycle is projected straight from the Sales models
 * here (the `invoice_*` / `proposal_*` activity types are not written to
 * `doublescale_activities`), mirroring {@see \DoubleScale\Modules\Booking\Services\BookingPortalProvider}.
 *
 * Phase 1 is link-out only: rows carry the existing public hash-page URL
 * (`InvoiceUrl` / `ProposalUrl`) — viewing, paying (Pro), accepting and signing
 * all happen on that page, never inside the portal renderer.
 *
 * Every contribution is additionally gated on {@see doublescale_sales_documents_ready()}
 * so the release filter can hide the whole surface while Sales admin stays live.
 * Resolved in {@see \DoubleScale\Modules\Sales\Module::boot()}.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Modules\Documents\Rest\ProposalShaper;
use DoubleScale\Modules\Documents\Services\InvoiceUrl;
use DoubleScale\Modules\Documents\Services\ProposalUrl;
use DoubleScale\Modules\Portal\Services\PortalAccess;
use DoubleScale\Modules\Portal\Services\PortalAccessGate;

/**
 * SalesPortalProvider.
 */
final class SalesPortalProvider {

	/**
	 * Cap on each doc type projected into the timeline (mirrors Booking's 50).
	 */
	private const TIMELINE_CAP = 50;

	/**
	 * Invoice statuses that count as "needs attention" (and as outstanding).
	 */
	private const OUTSTANDING_INVOICE_STATUSES = array(
		InvoiceStatus::UNPAID,
		InvoiceStatus::PARTIALLY_PAID,
		InvoiceStatus::OVERDUE,
	);

	/**
	 * Proposal statuses that count as "needs attention".
	 */
	private const OPEN_PROPOSAL_STATUSES = array(
		ProposalStatus::SENT,
		ProposalStatus::OPEN,
	);

	public function __construct() {
		add_filter( 'doublescale_portal_sections', array( $this, 'register_section' ) );
		add_filter( 'doublescale_portal_summary_cards', array( $this, 'add_summary_card' ), 10, 2 );
		add_filter( 'doublescale_portal_timeline_items', array( $this, 'add_timeline_items' ), 10, 2 );
		add_filter( 'doublescale_portal_calendar_events', array( $this, 'add_calendar_events' ), 10, 4 );
		add_filter( 'doublescale_client_portal_config', array( $this, 'inject_portal_config' ) );
	}

	/**
	 * Expose credit-notes portal flags to the renderer (Pro supplies the REST URL).
	 *
	 * @param array<string, mixed> $config Localized portal config.
	 * @return array<string, mixed>
	 */
	public function inject_portal_config( array $config ): array {
		$config['credit_notes_module_enabled'] = doublescale_sales_child_module_active( 'credit_notes' );
		$config['credit_notes_pro_active']       = function_exists( 'doublescale_is_pro_addon_active' )
			&& doublescale_is_pro_addon_active()
			&& doublescale_sales_child_module_active( 'credit_notes' );
		$config['invoices_payments_pro_active'] = doublescale_sales_child_module_active( 'documents' );
		$config['contracts_module_enabled'] = doublescale_sales_child_module_active( 'contracts' );
		$config['contracts_pro_active']       = function_exists( 'doublescale_is_pro_addon_active' )
			&& doublescale_is_pro_addon_active()
			&& doublescale_sales_child_module_active( 'contracts' );

		return $config;
	}

	/**
	 * Contribute the Documents section descriptor.
	 *
	 * @param array<int, array<string, mixed>> $sections Section descriptors.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_section( array $sections ): array {
		$sections[] = array(
			'slug'         => 'documents',
			'label'        => __( 'Documents', 'doublescale' ),
			'icon'         => 'document',
			'order'        => 30,
			'is_available' => static fn() => doublescale_sales_documents_ready()
				&& doublescale_any_sales_document_module_active(),
			'badge'        => static fn( $contact ) => self::count_visible_documents( $contact ),
		);

		return $sections;
	}

	/**
	 * Add the "outstanding balance" summary card.
	 *
	 * @param array<int, array<string, mixed>> $cards   Summary cards.
	 * @param ContactModel|null                $contact Resolved contact.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_summary_card( array $cards, $contact ): array {
		if ( ! doublescale_sales_documents_ready()
			|| ! doublescale_any_sales_document_module_active()
			|| ! $contact instanceof ContactModel ) {
			return $cards;
		}

		$cards[] = array(
			'key'   => 'outstanding_balance',
			'label' => __( 'Outstanding balance', 'doublescale' ),
			'value' => self::outstanding_balance_value( $contact ),
			'route' => 'documents',
		);

		return $cards;
	}

	/**
	 * Project recent document lifecycle rows into the timeline.
	 *
	 * @param array<int, array<string, mixed>> $items   Timeline items.
	 * @param ContactModel|null                $contact Resolved contact.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_timeline_items( array $items, $contact ): array {
		if ( ! doublescale_sales_documents_ready()
			|| ! doublescale_any_sales_document_module_active()
			|| ! $contact instanceof ContactModel ) {
			return $items;
		}

		if ( doublescale_sales_child_module_active( 'documents' ) ) {
			// Latest touched first, so an old invoice paid today is among them.
			$invoices = \DoubleScale\Modules\Portal\Services\PortalScope::apply( InvoiceModel::query(), (int) $contact->id, 'invoices' )
				->where( 'status', '!=', InvoiceStatus::DRAFT )
				->with( 'payments' )
				->orderBy( 'updated_at', 'desc' )
				->limit( self::TIMELINE_CAP )
				->get();

			foreach ( $invoices as $invoice ) {
				$items[] = array(
					'id'            => 'invoice-' . (int) $invoice->id,
					'kind'          => 'document',
					'document_type' => 'invoice',
					'type'          => self::invoice_lifecycle_type( $invoice ),
					'date'          => self::invoice_event_date( $invoice ),
					'title'         => (string) $invoice->invoice_number,
					'status'        => (string) $invoice->status,
					'public_url'    => (string) InvoiceUrl::get_public_url( $invoice ),
				);
			}

			$proposals = \DoubleScale\Modules\Portal\Services\PortalScope::apply( ProposalModel::query(), (int) $contact->id, 'invoices' )
				->where( 'status', '!=', ProposalStatus::DRAFT )
				->orderBy( 'updated_at', 'desc' )
				->limit( self::TIMELINE_CAP )
				->get();

			foreach ( $proposals as $proposal ) {
				$title   = '' !== (string) $proposal->subject ? (string) $proposal->subject : (string) $proposal->proposal_number;
				$items[] = array(
					'id'            => 'proposal-' . (int) $proposal->id,
					'kind'          => 'document',
					'document_type' => 'proposal',
					'type'          => self::proposal_lifecycle_type( $proposal ),
					'date'          => self::proposal_event_date( $proposal ),
					'title'         => $title,
					'status'        => (string) $proposal->status,
					'public_url'    => (string) ProposalUrl::get_public_url( $proposal ),
				);
			}
		}

		return $items;
	}

	/**
	 * Project the contact's invoice due dates + proposal expiries onto the
	 * calendar feed as all-day events.
	 *
	 * Date-only columns (`due_date`, `open_till`) become tz-agnostic all-day
	 * events (`timezone => null`). Comparing a DATE column to the window's
	 * inclusive end-of-day bound (`…-30 23:59:59`) still includes the 30th.
	 * Each event carries the document `hash` and a `/documents/{type}/{hash}`
	 * route so the in-portal hash-keyed detail view resolves (a numeric id 404s).
	 *
	 * @param array<int, array<string, mixed>> $events        Calendar events.
	 * @param ContactModel|null                $contact       Resolved contact.
	 * @param string                           $start         Window start (Y-m-d).
	 * @param string                           $end_inclusive Window end, inclusive end-of-day (Y-m-d H:i:s).
	 * @return array<int, array<string, mixed>>
	 */
	public function add_calendar_events( array $events, $contact, string $start, string $end_inclusive ): array {
		if ( ! doublescale_sales_documents_ready()
			|| ! doublescale_any_sales_document_module_active()
			|| ! $contact instanceof ContactModel ) {
			return $events;
		}

		if ( doublescale_sales_child_module_active( 'documents' ) ) {
			$invoices = \DoubleScale\Modules\Portal\Services\PortalScope::apply( InvoiceModel::query(), (int) $contact->id, 'invoices' )
				->where( 'status', '!=', InvoiceStatus::DRAFT )
				->whereNotNull( 'due_date' )
				->whereBetween( 'due_date', array( $start, $end_inclusive ) )
				->get();

			foreach ( $invoices as $invoice ) {
				$hash     = (string) $invoice->hash;
				$events[] = array(
					'id'       => 'invoice-' . (int) $invoice->id,
					'kind'     => 'invoice',
					'title'    => (string) $invoice->invoice_number,
					'start'    => (string) $invoice->due_date,
					'end'      => null,
					'all_day'  => true,
					'timezone' => null,
					'status'   => (string) $invoice->status,
					'route'    => '' !== $hash ? '/documents/invoice/' . $hash : '/documents',
				);
			}

			$proposals = \DoubleScale\Modules\Portal\Services\PortalScope::apply( ProposalModel::query(), (int) $contact->id, 'invoices' )
				->where( 'status', '!=', ProposalStatus::DRAFT )
				->whereNotNull( 'open_till' )
				->whereBetween( 'open_till', array( $start, $end_inclusive ) )
				->get();

			foreach ( $proposals as $proposal ) {
				$hash     = (string) $proposal->hash;
				$title    = '' !== (string) $proposal->subject ? (string) $proposal->subject : (string) $proposal->proposal_number;
				$events[] = array(
					'id'       => 'proposal-' . (int) $proposal->id,
					'kind'     => 'proposal',
					'title'    => $title,
					'start'    => (string) $proposal->open_till,
					'end'      => null,
					'all_day'  => true,
					'timezone' => null,
					'status'   => (string) $proposal->status,
					'route'    => '' !== $hash ? '/documents/proposal/' . $hash : '/documents',
				);
			}
		}

		return $events;
	}

	/**
	 * Count customer-visible documents (mirrors the portal Documents → All list).
	 *
	 * @param ContactModel|null $contact Resolved contact.
	 * @return int
	 */
	private static function count_visible_documents( $contact ): int {
		if ( ! $contact instanceof ContactModel ) {
			return 0;
		}

		$count  = 0;
		$access = PortalAccess::for_contact( $contact );
		$shows  = array(
			'documents'    => ! empty( $access['documents'] ),
			'contracts'    => ! empty( $access['contracts'] ),
			'credit_notes' => ! empty( $access['credit_notes'] ),
		);

		if (
			doublescale_sales_child_module_active( 'documents' )
			&& (
				! function_exists( 'doublescale_is_module_storage_ready' )
				|| doublescale_is_module_storage_ready( 'documents', InvoiceModel::class )
			)
		) {
			try {
				if ( $shows['documents'] ) {
					$count += (int) \DoubleScale\Modules\Portal\Services\PortalScope::apply( InvoiceModel::query(), (int) $contact->id, 'invoices' )
						->where( 'status', '!=', InvoiceStatus::DRAFT )
						->count();

					$count += (int) \DoubleScale\Modules\Portal\Services\PortalScope::apply( ProposalModel::query(), (int) $contact->id, 'invoices' )
						->where( 'status', '!=', ProposalStatus::DRAFT )
						->count();
				}
			} catch ( \Throwable $e ) {
				// Tables missing — leave count at 0 for documents.
			}
		}

		if ( $shows['documents'] && $shows['contracts'] && $shows['credit_notes'] ) {
			/**
			 * Allow Pro modules to include additional customer-visible documents.
			 *
			 * @param int           $count   Current count.
			 * @param ContactModel  $contact Resolved contact.
			 */
			return (int) apply_filters( 'doublescale_portal_visible_document_count', $count, $contact );
		}

		// A document module is hidden for this customer. The count filter above
		// cannot tell a contract from a credit note, so count the rows the
		// Documents page itself lists for the kinds Pro adds, after the same gate.
		$added = apply_filters( 'doublescale_portal_documents_rows', array(), $contact, 'all' );

		return $count + count( PortalAccessGate::document_rows( is_array( $added ) ? $added : array(), $contact ) );
	}

	/**
	 * Count outstanding invoices + open proposals (summary / attention helpers).
	 *
	 * @param ContactModel|null $contact Resolved contact.
	 * @return int
	 */
	private static function count_actionable( $contact ): int {
		if ( ! $contact instanceof ContactModel ) {
			return 0;
		}

		$documents_ready = doublescale_sales_child_module_active( 'documents' )
			&& (
				! function_exists( 'doublescale_is_module_storage_ready' )
				|| doublescale_is_module_storage_ready( 'documents', InvoiceModel::class )
			);

		try {
			$invoices = $documents_ready
				? (int) \DoubleScale\Modules\Portal\Services\PortalScope::apply( InvoiceModel::query(), (int) $contact->id, 'invoices' )
					->whereIn( 'status', self::OUTSTANDING_INVOICE_STATUSES )
					->count()
				: 0;

			$proposals = $documents_ready
				? (int) \DoubleScale\Modules\Portal\Services\PortalScope::apply( ProposalModel::query(), (int) $contact->id, 'invoices' )
					->whereIn( 'status', self::OPEN_PROPOSAL_STATUSES )
					->count()
				: 0;
		} catch ( \Throwable $e ) {
			return 0;
		}

		return $invoices + $proposals;
	}

	/**
	 * Formatted outstanding-balance string for the summary card.
	 *
	 * Currency is per-document, so totals stay grouped and are never added
	 * across currencies — same pattern as the invoices list summary cards.
	 *
	 * @param ContactModel $contact Resolved contact.
	 * @return string e.g. "1,250.00 USD · 800.00 EGP", or "0" when nothing is outstanding.
	 */
	private static function outstanding_balance_value( ContactModel $contact ): string {
		if ( ! doublescale_sales_child_module_active( 'documents' ) ) {
			return '0';
		}

		$invoices = \DoubleScale\Modules\Portal\Services\PortalScope::apply( InvoiceModel::query(), (int) $contact->id, 'invoices' )
			->whereIn( 'status', self::OUTSTANDING_INVOICE_STATUSES )
			->get();

		$by_currency = array();
		foreach ( $invoices as $invoice ) {
			$balance = InvoiceShaper::balance( $invoice );
			if ( $balance <= 0 ) {
				continue;
			}

			$currency                 = \DoubleScale\Core\Services\CurrencyResolver::resolve( $invoice );
			$by_currency[ $currency ] = ( $by_currency[ $currency ] ?? 0.0 ) + $balance;
		}

		if ( array() === $by_currency ) {
			return '0';
		}

		$parts = array();
		// The Documents cards' form ("1,250 $", "9,496.20 EUR"): one way to
		// write an amount across the portal.
		foreach ( $by_currency as $currency => $sum ) {
			$decimals = abs( $sum - round( $sum ) ) < 0.005 ? 0 : 2;
			$amount   = function_exists( 'number_format_i18n' ) ? number_format_i18n( $sum, $decimals ) : number_format( $sum, $decimals );
			$code     = strtoupper( trim( (string) $currency ) );
			$parts[]  = '' === $code ? $amount : $amount . ' ' . ( 'USD' === $code ? '$' : $code );
		}

		return implode( ' · ', $parts );
	}

	/**
	 * When the invoice's own event happened, in UTC like every other row of
	 * the activity list: paid = its latest payment, else sent, else created.
	 *
	 * @param InvoiceModel $invoice Invoice (payments loaded).
	 * @return string
	 */
	private static function invoice_event_date( InvoiceModel $invoice ): string {
		if ( InvoiceStatus::PAID === (string) $invoice->status ) {
			$latest = '';
			foreach ( $invoice->payments as $payment ) {
				$latest = max( $latest, (string) $payment->created_at );
			}
			if ( '' !== $latest ) {
				return $latest;
			}
		}

		return self::site_time_to_utc( $invoice->sent_at, (string) $invoice->created_at );
	}

	/**
	 * When the proposal was answered, else sent, else created (UTC).
	 *
	 * @param ProposalModel $proposal Proposal.
	 * @return string
	 */
	private static function proposal_event_date( ProposalModel $proposal ): string {
		switch ( (string) $proposal->status ) {
			case ProposalStatus::ACCEPTED:
				$when = $proposal->accepted_at;
				break;
			case ProposalStatus::DECLINED:
				$when = $proposal->declined_at;
				break;
			default:
				$when = $proposal->sent_at;
		}
		if ( empty( $when ) ) {
			$when = $proposal->sent_at;
		}

		return self::site_time_to_utc( $when, (string) $proposal->created_at );
	}

	/**
	 * Sent/answered times are stored in the site's time; the activity list
	 * reads UTC (as `created_at`).
	 *
	 * @param mixed  $site_time Stored site-time value.
	 * @param string $fallback  UTC value when it is empty.
	 * @return string
	 */
	private static function site_time_to_utc( $site_time, string $fallback ): string {
		$value = trim( (string) $site_time );

		return '' !== $value ? get_gmt_from_date( $value ) : $fallback;
	}

	/**
	 * Map an invoice to a projected lifecycle activity-type slug.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return string
	 */
	private static function invoice_lifecycle_type( InvoiceModel $invoice ): string {
		return InvoiceStatus::PAID === (string) $invoice->status ? 'invoice_paid' : 'invoice_sent';
	}

	/**
	 * Map a proposal to a projected lifecycle activity-type slug.
	 *
	 * @param ProposalModel $proposal Proposal.
	 * @return string
	 */
	private static function proposal_lifecycle_type( ProposalModel $proposal ): string {
		switch ( (string) $proposal->status ) {
			case ProposalStatus::ACCEPTED:
				return 'proposal_accepted';
			case ProposalStatus::DECLINED:
				return 'proposal_declined';
			default:
				return 'proposal_sent';
		}
	}
}
