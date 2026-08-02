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
		$config['invoices_payments_pro_active'] = function_exists( 'doublescale_is_pro_addon_active' )
			&& doublescale_is_pro_addon_active()
			&& doublescale_sales_child_module_active( 'documents' );
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
			$invoices = InvoiceModel::where( 'contact_id', (int) $contact->id )
				->where( 'status', '!=', InvoiceStatus::DRAFT )
				->orderBy( 'id', 'desc' )
				->limit( self::TIMELINE_CAP )
				->get();

			foreach ( $invoices as $invoice ) {
				$items[] = array(
					'id'            => 'invoice-' . (int) $invoice->id,
					'kind'          => 'document',
					'document_type' => 'invoice',
					'type'          => self::invoice_lifecycle_type( $invoice ),
					'date'          => (string) $invoice->created_at,
					'title'         => (string) $invoice->invoice_number,
					'status'        => (string) $invoice->status,
					'public_url'    => (string) InvoiceUrl::get_public_url( $invoice ),
				);
			}

			$proposals = ProposalModel::where( 'contact_id', (int) $contact->id )
				->where( 'status', '!=', ProposalStatus::DRAFT )
				->orderBy( 'id', 'desc' )
				->limit( self::TIMELINE_CAP )
				->get();

			foreach ( $proposals as $proposal ) {
				$title   = '' !== (string) $proposal->subject ? (string) $proposal->subject : (string) $proposal->proposal_number;
				$items[] = array(
					'id'            => 'proposal-' . (int) $proposal->id,
					'kind'          => 'document',
					'document_type' => 'proposal',
					'type'          => self::proposal_lifecycle_type( $proposal ),
					'date'          => (string) $proposal->created_at,
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
			$invoices = InvoiceModel::where( 'contact_id', (int) $contact->id )
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

			$proposals = ProposalModel::where( 'contact_id', (int) $contact->id )
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

		$count = 0;

		if (
			doublescale_sales_child_module_active( 'documents' )
			&& (
				! function_exists( 'doublescale_is_module_storage_ready' )
				|| doublescale_is_module_storage_ready( 'documents', InvoiceModel::class )
			)
		) {
			try {
				$count += (int) InvoiceModel::where( 'contact_id', (int) $contact->id )
					->where( 'status', '!=', InvoiceStatus::DRAFT )
					->count();

				$count += (int) ProposalModel::where( 'contact_id', (int) $contact->id )
					->where( 'status', '!=', ProposalStatus::DRAFT )
					->count();
			} catch ( \Throwable $e ) {
				// Tables missing — leave count at 0 for documents.
			}
		}

		/**
		 * Allow Pro modules to include additional customer-visible documents.
		 *
		 * @param int           $count   Current count.
		 * @param ContactModel  $contact Resolved contact.
		 */
		return (int) apply_filters( 'doublescale_portal_visible_document_count', $count, $contact );
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
				? (int) InvoiceModel::where( 'contact_id', (int) $contact->id )
					->whereIn( 'status', self::OUTSTANDING_INVOICE_STATUSES )
					->count()
				: 0;

			$proposals = $documents_ready
				? (int) ProposalModel::where( 'contact_id', (int) $contact->id )
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
	 * Sales has no global store currency (currency is per-document), so we sum
	 * outstanding balances grouped by currency and present the dominant one. A
	 * mixed-currency account is rare; this is a documented MVP simplification.
	 *
	 * @param ContactModel $contact Resolved contact.
	 * @return string e.g. "1,250.00 USD", or "0" when nothing is outstanding.
	 */
	private static function outstanding_balance_value( ContactModel $contact ): string {
		if ( ! doublescale_sales_child_module_active( 'documents' ) ) {
			return '0';
		}

		$invoices = InvoiceModel::where( 'contact_id', (int) $contact->id )
			->whereIn( 'status', self::OUTSTANDING_INVOICE_STATUSES )
			->get();

		$by_currency = array();
		foreach ( $invoices as $invoice ) {
			$currency                 = (string) $invoice->currency;
			$by_currency[ $currency ] = ( $by_currency[ $currency ] ?? 0.0 ) + InvoiceShaper::balance( $invoice );
		}

		if ( array() === $by_currency ) {
			return '0';
		}

		arsort( $by_currency );
		$currency = (string) array_key_first( $by_currency );
		$sum      = (float) $by_currency[ $currency ];

		$amount = function_exists( 'number_format_i18n' ) ? number_format_i18n( $sum, 2 ) : number_format( $sum, 2 );

		return '' !== $currency ? $amount . ' ' . $currency : $amount;
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
