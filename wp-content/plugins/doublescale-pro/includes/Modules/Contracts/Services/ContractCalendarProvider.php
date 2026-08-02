<?php
/**
 * Contracts ⇄ admin calendar bridge.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Utils\CalendarSupport;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Pro\Modules\Contracts\Constants\ContractStatus;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;

/**
 * ContractCalendarProvider.
 */
final class ContractCalendarProvider {

	private const MAX_ROWS = 500;

	public function __construct() {
		add_filter( 'doublescale_admin_calendar_events', array( $this, 'add_events' ), 10, 4 );
	}

	/**
	 * @param array<int, array<string, mixed>> $events    Events collected so far.
	 * @param array{0:string,1:string}         $window    [ start (Y-m-d), end_inclusive (Y-m-d H:i:s) ].
	 * @param int                              $viewer_id Current staff user id.
	 * @param int                              $view_user Manager-only "view as assignee" id (0 = all / self).
	 * @return array<int, array<string, mixed>>
	 */
	public function add_events( array $events, array $window, int $viewer_id, int $view_user ): array {
		if ( ! $this->is_active() ) {
			return $events;
		}

		list( $start, $end_inclusive ) = $window;

		$scope_user = Capabilities::can_manage_all_sales( $viewer_id )
			? ( $view_user > 0 ? $view_user : 0 )
			: $viewer_id;

		$contracts = $this->safe_get(
			$this->scoped( ContractModel::query(), 'assigned_user_id', $scope_user )
				->where( 'is_trash', false )
				->where( 'status', '!=', ContractStatus::DRAFT )
				->whereBetween( 'end_date', array( $start, $end_inclusive ) )
		);

		if ( $contracts->isEmpty() ) {
			return $events;
		}

		$user_ids    = $contracts->pluck( 'assigned_user_id' )->all();
		$contact_ids = $contracts->pluck( 'contact_id' )->all();
		$names       = CalendarSupport::user_names( $user_ids );
		$contacts    = self::contact_names( $contact_ids );

		foreach ( $contracts as $contract ) {
			$events[] = $this->shape(
				'contract-' . (int) $contract->id,
				'contract',
				(string) $contract->subject,
				(string) $contract->end_date,
				(string) $contract->status,
				(int) $contract->assigned_user_id,
				(int) $contract->contact_id,
				'sales/contracts/' . (int) $contract->id,
				$names,
				$contacts
			);
		}

		return $events;
	}

	/**
	 * @param mixed  $query     Eloquent query builder.
	 * @param string $owner_col Owner column for this model.
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
	 * @param mixed $query Eloquent query builder.
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	private function safe_get( $query ) {
		$model = $query->getModel();
		try {
			if ( ! self::table_exists( (string) $model->getTable() ) ) {
				return $model->newCollection();
			}
			return $query->limit( self::MAX_ROWS )->get();
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->warning(
				'Contracts calendar query skipped; table read failed.',
				array(
					'source'    => 'contracts-calendar-provider',
					'exception' => $e->getMessage(),
				)
			);
			return $model->newCollection();
		}
	}

	/**
	 * @param string $table Fully-qualified table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		$like = $wpdb->esc_like( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $table;
	}

	/**
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

	/**
	 * @return bool
	 */
	private function is_active(): bool {
		return function_exists( 'doublescale_is_module_active' )
			&& doublescale_is_module_active( 'sales' )
			&& function_exists( 'doublescale_sales_child_module_active' )
			&& doublescale_sales_child_module_active( 'contracts' );
	}
}
