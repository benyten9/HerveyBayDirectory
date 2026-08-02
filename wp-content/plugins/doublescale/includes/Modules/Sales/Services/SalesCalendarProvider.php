<?php
/**
 * Sales ⇄ admin calendar bridge.
 *
 * Contributes three all-day kinds to the cross-module admin/staff calendar feed
 * (`doublescale_admin_calendar_events`): invoice due dates (`due_date`), proposal
 * expiries (`open_till`). Contract renewals are contributed by the Pro Contracts module.
 *
 * Role scoping mirrors the Sales list endpoints: a manager
 * ({@see Capabilities::can_manage_all_sales()}) sees everything in the window;
 * a rep sees only records assigned to them (`sale_agent_user_id` for invoices,
 * `assigned_user_id` for proposals). A manager may scope to one staffer
 * via `$view_user`.
 *
 * Resolved in {@see \DoubleScale\Modules\Sales\Module::boot()}.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Utils\CalendarSupport;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;

/**
 * SalesCalendarProvider.
 */
final class SalesCalendarProvider {

	/**
	 * Per-kind safety cap for one window.
	 */
	private const MAX_ROWS = 500;

	public function __construct() {
		add_filter( 'doublescale_admin_calendar_events', array( $this, 'add_events' ), 10, 4 );
	}

	/**
	 * Project the viewer's in-window invoices and proposals.
	 *
	 * @param array<int, array<string, mixed>> $events    Events collected so far.
	 * @param array{0:string,1:string}         $window    [ start (Y-m-d), end_inclusive (Y-m-d H:i:s) ].
	 * @param int                              $viewer_id Current staff user id.
	 * @param int                              $view_user Manager-only "view as assignee" id (0 = all / self).
	 * @return array<int, array<string, mixed>>
	 */
	public function add_events( array $events, array $window, int $viewer_id, int $view_user ): array {
		if ( ! function_exists( 'doublescale_is_module_active' ) || ! doublescale_is_module_active( 'sales' ) ) {
			return $events;
		}

		list( $start, $end_inclusive ) = $window;

		// 0 = no owner constraint (managers, or a manager's "All"); >0 = a single staffer.
		// Scope against the server-resolved $viewer_id (never the ambient current
		// user) so the aggregator's per-viewer contract holds.
		$scope_user = Capabilities::can_manage_all_sales( $viewer_id )
			? ( $view_user > 0 ? $view_user : 0 )
			: $viewer_id;

		$invoice_seed  = new InvoiceModel();
		$proposal_seed = new ProposalModel();

		$invoices  = doublescale_sales_child_module_active( 'documents' )
			? $this->safe_get(
				$this->scoped( InvoiceModel::query(), 'sale_agent_user_id', $scope_user )
					->where( 'status', '!=', InvoiceStatus::DRAFT )
					->whereBetween( 'due_date', array( $start, $end_inclusive ) ),
				'invoices'
			)
			: $invoice_seed->newCollection();
		$proposals = doublescale_sales_child_module_active( 'documents' )
			? $this->safe_get(
				$this->scoped( ProposalModel::query(), 'assigned_user_id', $scope_user )
					->where( 'status', '!=', ProposalStatus::DRAFT )
					->whereBetween( 'open_till', array( $start, $end_inclusive ) ),
				'proposals'
			)
			: $proposal_seed->newCollection();

		// Batch-resolve assignee + contact display names across both sets.
		$user_ids    = array_merge(
			$invoices->pluck( 'sale_agent_user_id' )->all(),
			$proposals->pluck( 'assigned_user_id' )->all()
		);
		$contact_ids = array_merge(
			$invoices->pluck( 'contact_id' )->all(),
			$proposals->pluck( 'contact_id' )->all()
		);
		$names       = CalendarSupport::user_names( $user_ids );
		$contacts    = self::contact_names( $contact_ids );

		foreach ( $invoices as $invoice ) {
			$events[] = $this->shape(
				'invoice-' . (int) $invoice->id,
				'invoice',
				(string) $invoice->invoice_number,
				(string) $invoice->due_date,
				(string) $invoice->status,
				(int) $invoice->sale_agent_user_id,
				(int) $invoice->contact_id,
				'sales/invoices/' . (int) $invoice->id,
				$names,
				$contacts
			);
		}

		foreach ( $proposals as $proposal ) {
			$title    = '' !== (string) $proposal->subject ? (string) $proposal->subject : (string) $proposal->proposal_number;
			$events[] = $this->shape(
				'proposal-' . (int) $proposal->id,
				'proposal',
				$title,
				(string) $proposal->open_till,
				(string) $proposal->status,
				(int) $proposal->assigned_user_id,
				(int) $proposal->contact_id,
				'sales/proposals/' . (int) $proposal->id,
				$names,
				$contacts
			);
		}

		return $events;
	}

	/**
	 * Apply the owner-column scope clause (no-op when scoping to "all").
	 *
	 * @param mixed  $query      Eloquent query builder.
	 * @param string $owner_col  Owner column for this model.
	 * @param int    $scope_user 0 = all; >0 = a single staffer.
	 * @return mixed
	 */
	private function scoped( $query, string $owner_col, int $scope_user ) {
		if ( $scope_user > 0 ) {
			$query->where( $owner_col, $scope_user );
		}
		return $query;
	}

	/**
	 * Run a windowed query, returning an empty collection (instead of fataling)
	 * when its table is missing — e.g. a Sales sub-feature (contracts) whose
	 * migration hasn't run on this install. Keeps one absent table from taking
	 * down the whole aggregated calendar feed.
	 *
	 * @param mixed  $query Eloquent query builder (limit + get applied here).
	 * @param string $kind  Kind label for the log entry.
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	private function safe_get( $query, string $kind ) {
		$model = $query->getModel();
		try {
			// Skip a table that isn't installed (e.g. contracts, a sub-feature
			// whose migration hasn't run) so we neither fatal nor let $wpdb echo a
			// "table doesn't exist" banner into the REST response.
			if ( ! self::table_exists( (string) $model->getTable() ) ) {
				return $model->newCollection();
			}
			return $query->limit( self::MAX_ROWS )->get();
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->warning(
				'Sales calendar query skipped; table read failed.',
				array(
					'source'    => 'sales-calendar-provider',
					'kind'      => $kind,
					'exception' => $e->getMessage(),
				)
			);
			// getModel() touches no DB, so newCollection() is safe here too.
			return $model->newCollection();
		}
	}

	/**
	 * Whether a table exists. The name is taken verbatim from the model — WPEloquent
	 * bakes the `$wpdb` prefix into `getTable()`, so it is already fully qualified
	 * (e.g. `wp_doublescale_sales_contracts`); `esc_like()` keeps the literal
	 * underscores from acting as LIKE wildcards.
	 *
	 * @param string $table Fully-qualified table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		$like = $wpdb->esc_like( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off schema existence probe; there is no WP API for it and the result is not cacheable per request.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $table;
	}

	/**
	 * Build a single all-day Sales calendar event.
	 *
	 * @param string             $id         Stable `{kind}-{id}` key.
	 * @param string             $kind       Event kind.
	 * @param string             $title      Event title.
	 * @param string             $date       All-day civil date (Y-m-d).
	 * @param string             $status     Record status (drives color).
	 * @param int                $user_id    Assignee user id (0 = none).
	 * @param int                $contact_id Related contact id (0 = none).
	 * @param string             $route      Admin SPA detail route.
	 * @param array<int, string> $names      user_id => display name.
	 * @param array<int, string> $contacts   contact_id => display name.
	 * @return array<string, mixed>
	 */
	private function shape( string $id, string $kind, string $title, string $date, string $status, int $user_id, int $contact_id, string $route, array $names, array $contacts ): array {
		return array(
			'id'       => $id,
			'kind'     => $kind,
			'title'    => $title,
			'start'    => $date,
			'end'      => null,
			'all_day'  => true,
			'timezone' => null,
			'status'   => $status,
			'assignee' => $user_id > 0 ? array(
				'id'   => $user_id,
				'name' => $names[ $user_id ] ?? '',
			) : null,
			'contact'  => $contact_id > 0 ? array(
				'id'   => $contact_id,
				'name' => $contacts[ $contact_id ] ?? '',
			) : null,
			'route'    => $route,
		);
	}

	/**
	 * Resolve `[ contact_id => display_name ]` for a set of ids in one query.
	 *
	 * @param array<int, int> $contact_ids Candidate contact ids.
	 * @return array<int, string>
	 */
	private static function contact_names( array $contact_ids ): array {
		$contact_ids = array_values( array_unique( array_filter( array_map( 'intval', $contact_ids ) ) ) );
		if ( empty( $contact_ids ) ) {
			return array();
		}

		$map = array();
		foreach ( ContactModel::whereIn( 'id', $contact_ids )->get() as $contact ) {
			$name                      = trim( (string) ( $contact->first_name ?? '' ) . ' ' . (string) ( $contact->last_name ?? '' ) );
			$map[ (int) $contact->id ] = '' !== $name ? $name : (string) ( $contact->email ?? '' );
		}

		return $map;
	}
}
