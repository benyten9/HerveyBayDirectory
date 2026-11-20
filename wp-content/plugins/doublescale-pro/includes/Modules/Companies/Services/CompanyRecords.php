<?php
/**
 * What a company page lists: the tickets, invoices, proposals, files (and,
 * from Pro, projects) of every member, newest first, as compact rows.
 *
 * This is the team's view, so it lists all of the members' records, not only
 * the ones shared with the company in the client portal; `shared` marks those.
 * Each list keeps the scoping of its own module: an agent who only sees their
 * own tickets or invoices sees only those here too.
 *
 * @package DoubleScale\Modules\Contacts\Services
 */

namespace DoubleScale\Pro\Modules\Companies\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Modules\Portal\Services\PortalFileService;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\Companies\Models\CompanyModel;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Contacts\Services\PurchaseTotals;
use DoubleScale\Modules\Support\Models\TicketModel;

/**
 * CompanyRecords class
 */
class CompanyRecords {

	/** Invoice statuses that owe nothing. */
	private const SETTLED_INVOICE = array( 'draft', 'paid', 'cancelled', 'void' );

	/**
	 * The record types this site and this user can list, in tab order.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		$types = array();
		/**
		 * Filters the record types a company page lists first (Pro adds projects).
		 *
		 * @param string[] $types Types.
		 */
		$types = (array) apply_filters( 'doublescale_company_record_types', $types );
		if ( self::module( 'support' ) && Permissions::has_support_access() ) {
			$types[] = 'tickets';
		}
		if ( self::documents_ready() && Permissions::has_sales_rep_access() ) {
			$types[] = 'invoices';
			$types[] = 'proposals';
		}
		$types[] = 'files';
		return array_values( array_unique( $types ) );
	}

	/**
	 * One page of a company's records of a type.
	 *
	 * @param CompanyModel $company  Company.
	 * @param string       $type     Record type.
	 * @param int          $page     Page.
	 * @param int          $per_page Rows per page.
	 * @return array{data: array<int, array<string, mixed>>, total: int}|null Null for a type this user can't list.
	 */
	public static function page( CompanyModel $company, string $type, int $page = 1, int $per_page = 20 ): ?array {
		if ( ! in_array( $type, self::types(), true ) ) {
			return null;
		}
		$members = array_column( CompanyService::members( (int) $company->id ), null, 'contact_id' );
		$ids     = array_keys( $members );
		$empty   = array(
			'data'  => array(),
			'total' => 0,
		);

		/**
		 * Lists a company's records of a type handled by an add-on (Pro: projects).
		 *
		 * @param array|null   $result   {data, total}, or null when not handled.
		 * @param string       $type     Record type.
		 * @param int[]        $ids      Member contact ids.
		 * @param CompanyModel $company  Company.
		 * @param int          $page     Page.
		 * @param int          $per_page Rows per page.
		 */
		$custom = apply_filters( 'doublescale_company_records', null, $type, $ids, $company, $page, $per_page );
		if ( is_array( $custom ) ) {
			return self::with_people( $custom, $members );
		}
		// A company with no people yet can still hold its own files.
		if ( empty( $ids ) && 'files' !== $type ) {
			return $empty;
		}

		$query = self::query( $type, $ids, (int) $company->id );
		if ( ! $query ) {
			return $empty;
		}
		$total = (int) ( clone $query )->count();
		$rows  = $query->orderBy( 'id', 'desc' )->skip( ( $page - 1 ) * $per_page )->take( $per_page )->get();

		$data = array();
		foreach ( $rows as $row ) {
			$data[] = self::shape( $type, $row, (int) $company->id );
		}
		return self::with_people(
			array(
				'data'  => $data,
				'total' => $total,
			),
			$members
		);
	}

	/**
	 * Figures for the company page's tiles.
	 *
	 * @param CompanyModel $company Company.
	 * @return array<string, mixed>
	 */
	public static function summary( CompanyModel $company ): array {
		$ids     = array_column( CompanyService::members( (int) $company->id ), 'contact_id' );
		$types   = self::types();
		$summary = array( 'members' => count( $ids ) );

		if ( $ids && in_array( 'tickets', $types, true ) ) {
			$summary['open_tickets'] = (int) self::query( 'tickets', $ids )->whereNotIn( 'status', array( 'resolved', 'closed' ) )->count();
		}
		if ( $ids && in_array( 'invoices', $types, true ) ) {
			$due   = self::query( 'invoices', $ids )->whereNotIn( 'status', self::SETTLED_INVOICE )->get( array( 'total', 'amount_paid', 'currency', 'due_date' ) );
			$money = array();
			$late  = 0;
			foreach ( $due as $invoice ) {
				$currency           = (string) $invoice->currency;
				$money[ $currency ] = ( $money[ $currency ] ?? 0 ) + max( 0, (float) $invoice->total - (float) $invoice->amount_paid );
				if ( $invoice->due_date && strtotime( (string) $invoice->due_date ) < strtotime( 'today' ) ) {
					++$late;
				}
			}
			$summary['invoices_due'] = array(
				'count'   => count( $due ),
				'overdue' => $late,
				// One currency or none: a single figure; several: the count only.
				'amount'   => 1 === count( $money ) ? (float) reset( $money ) : null,
				'currency' => 1 === count( $money ) ? (string) key( $money ) : null,
			);
		}

		// Figures that fill the row when one above is missing.
		$summary['purchases']  = PurchaseTotals::for_contacts( $ids );
		$summary['files']      = (int) self::query( 'files', $ids, (int) $company->id )->count();
		$summary['created_at'] = $company->created_at ? (string) $company->created_at : null;
		if ( $ids && in_array( 'proposals', $types, true ) ) {
			$summary['open_proposals'] = (int) self::query( 'proposals', $ids )->whereIn( 'status', array( 'sent', 'open' ) )->count();
		}

		/**
		 * Filters the company page's figures (Pro adds active projects).
		 *
		 * @param array<string, mixed> $summary Figures.
		 * @param int[]                $ids     Member contact ids.
		 * @param CompanyModel         $company Company.
		 */
		return (array) apply_filters( 'doublescale_company_summary', $summary, $ids, $company );
	}

	/**
	 * Members' records of a type, scoped like the module's own list.
	 *
	 * @param string $type       Type.
	 * @param int[]  $ids        Member contact ids.
	 * @param int    $company_id The company (files: its own files too).
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder|null
	 */
	private static function query( string $type, array $ids, int $company_id = 0 ) {
		$me = get_current_user_id();
		switch ( $type ) {
			case 'tickets':
				$query = TicketModel::query()->whereIn( 'contact_id', $ids );
				if ( ! Permissions::can_manage_all_tickets() ) {
					$query->where( 'agent_user_id', $me );
				}
				return $query;
			case 'invoices':
				$query = InvoiceModel::query()->whereIn( 'contact_id', $ids );
				if ( ! Permissions::has_sales_manager_access() ) {
					$query->where( 'sale_agent_user_id', $me );
				}
				return $query;
			case 'proposals':
				$query = ProposalModel::query()->whereIn( 'contact_id', $ids );
				if ( ! Permissions::has_sales_manager_access() ) {
					$query->where( 'assigned_user_id', $me );
				}
				return $query;
			case 'files':
				return AttachmentModel::query()
					->where(
						static function ( $q ) use ( $ids, $company_id ) {
							$q->where(
								static function ( $company ) use ( $company_id ) {
									$company->where( 'attachable_type', CompanyFiles::TYPE )->where( 'attachable_id', $company_id );
								}
							);
							if ( $ids ) {
								$q->orWhere(
									static function ( $people ) use ( $ids ) {
										$people->where( 'attachable_type', 'contact' )->whereIn( 'attachable_id', $ids );
									}
								);
							}
						}
					)
					->active();
		}
		return null;
	}

	/**
	 * A record as a row of the company page.
	 *
	 * @param string $type       Type.
	 * @param object $row        Model.
	 * @param int    $company_id The company.
	 * @return array<string, mixed>
	 */
	private static function shape( string $type, $row, int $company_id ): array {
		$base = array(
			'id'         => (int) $row->id,
			'contact_id' => (int) ( 'files' === $type ? $row->attachable_id : $row->contact_id ),
			'shared'     => (int) $row->company_id === $company_id,
			'date'       => $row->created_at ? (string) $row->created_at : null,
		);
		switch ( $type ) {
			case 'tickets':
				return $base + array(
					'title'    => (string) $row->title,
					'status'   => (string) $row->status,
					'priority' => (string) $row->priority,
					'route'    => 'support/ticket/' . (int) $row->id,
					'date'     => $row->updated_at ? (string) $row->updated_at : $base['date'],
				);
			case 'invoices':
				return array_merge(
					$base,
					array(
						'title'    => (string) $row->invoice_number,
						'status'   => (string) $row->status,
						'amount'   => (float) $row->total,
						'due'      => in_array( (string) $row->status, self::SETTLED_INVOICE, true ) ? 0.0 : max( 0, (float) $row->total - (float) $row->amount_paid ),
						'currency' => (string) $row->currency,
						'due_date' => $row->due_date ? (string) $row->due_date : null,
						'route'    => 'sales/invoices/' . (int) $row->id,
						'date'     => $row->invoice_date ? (string) $row->invoice_date : $base['date'],
					)
				);
			case 'proposals':
				return array_merge(
					$base,
					array(
						'title'    => (string) ( $row->subject ?: $row->proposal_number ),
						'number'   => (string) $row->proposal_number,
						'status'   => (string) $row->status,
						'amount'   => (float) $row->total,
						'currency' => (string) $row->currency,
						'route'    => 'sales/proposals/' . (int) $row->id,
						'date'     => $row->date ? (string) $row->date : $base['date'],
					)
				);
			case 'files':
				$from_client = empty( $row->user_id );
				$on_company  = CompanyFiles::TYPE === (string) $row->attachable_type;
				$visible     = ! $from_client && PortalFileService::is_client_visible( $row );
				$item        = array_merge(
					$base,
					array(
						'contact_id'  => $on_company ? 0 : (int) $row->attachable_id,
						'title'       => (string) $row->file_name,
						'size'        => (int) $row->file_size,
						'file_type'   => (string) $row->file_type,
						'url'         => ( new AttachmentService() )->signed_url( $row ),
						'from_client' => $from_client,
						// On the company itself (all its people), not on one person.
						'on_company'  => $on_company,
						// A client's upload is shared when they chose to; a company file when the team shows it.
						'shared'      => $from_client ? (int) $row->company_id === $company_id : ( $on_company && $visible ),
					)
				);
				// The team's files have a "Visible to client" switch.
				if ( ! $from_client ) {
					$item['client_visible']   = $visible;
					$item['client_opened_at'] = ( (array) $row->meta )['client_opened_at'] ?? null;
				}
				return $item;
		}
		return $base;
	}

	/**
	 * Add each row's member name, for "who" columns.
	 *
	 * @param array{data: array<int, array<string, mixed>>, total: int} $result  Page.
	 * @param array<int, array<string, mixed>>                           $members Members by contact id.
	 * @return array{data: array<int, array<string, mixed>>, total: int}
	 */
	private static function with_people( array $result, array $members ): array {
		foreach ( $result['data'] as &$row ) {
			$member             = $members[ (int) ( $row['contact_id'] ?? 0 ) ] ?? null;
			$row['contact_name'] = $member ? trim( $member['first_name'] . ' ' . $member['last_name'] ) ?: $member['email'] : '';
		}
		unset( $row );
		return $result;
	}

	/**
	 * Whether a module is on.
	 *
	 * @param string $module Module key.
	 */
	private static function module( string $module ): bool {
		return ! function_exists( 'doublescale_is_module_active' ) || doublescale_is_module_active( $module );
	}

	/**
	 * Whether the sales documents tables are there.
	 */
	private static function documents_ready(): bool {
		return function_exists( 'doublescale_sales_documents_ready' ) ? (bool) doublescale_sales_documents_ready() : self::module( 'documents' );
	}
}
