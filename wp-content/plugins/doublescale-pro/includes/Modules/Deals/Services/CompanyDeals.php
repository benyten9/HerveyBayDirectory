<?php
/**
 * Deals and companies: a deal carries its contact's company, so the company
 * page lists the company's pipeline (Deals tab, open deals figure), deleting
 * a company detaches its deals, and a contact who joins a company brings
 * their unassigned deals along.
 *
 * @package DoubleScale\Pro\Modules\Deals\Services
 */

namespace DoubleScale\Pro\Modules\Deals\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Core\UserRoles\PermissionsCompat;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;

/**
 * CompanyDeals class
 */
class CompanyDeals {

	/**
	 * Hook into free's companies.
	 */
	public static function register(): void {
		add_filter( 'doublescale_company_record_types', array( self::class, 'types' ), 20 );
		add_filter( 'doublescale_company_records', array( self::class, 'records' ), 10, 6 );
		add_filter( 'doublescale_company_summary', array( self::class, 'summary' ), 10, 3 );
		add_filter(
			'doublescale_company_tables',
			static function ( $tables ) {
				$tables[] = 'doublescale_deals';
				return $tables;
			}
		);
		add_action( 'doublescale_contact_company_changed', array( self::class, 'adopt_deals' ), 10, 2 );
	}

	/**
	 * Deals sit after projects on a company page, for those who see deals.
	 *
	 * @param string[] $types Types.
	 * @return string[]
	 */
	public static function types( $types ): array {
		$types = (array) $types;
		if ( Permissions::can_read_deals() ) {
			$at = array_search( 'projects', $types, true );
			array_splice( $types, false === $at ? 0 : $at + 1, 0, array( 'deals' ) );
		}
		return $types;
	}

	/**
	 * One page of the company's deals.
	 *
	 * @param array|null $result   Earlier result.
	 * @param string     $type     Record type.
	 * @param int[]      $ids      Member contact ids.
	 * @param mixed      $company  Company.
	 * @param int        $page     Page.
	 * @param int        $per_page Rows per page.
	 * @return array|null
	 */
	public static function records( $result, $type, $ids, $company, $page, $per_page ) {
		if ( 'deals' !== $type || is_array( $result ) ) {
			return $result;
		}
		$query = self::query( (int) $company->id );
		$total = (int) ( clone $query )->count();
		$rows  = $query->with( 'stage' )->orderBy( 'id', 'desc' )->skip( ( (int) $page - 1 ) * (int) $per_page )->take( (int) $per_page )->get();

		$data = array();
		foreach ( $rows as $deal ) {
			$data[] = array(
				'id'         => (int) $deal->id,
				'contact_id' => (int) $deal->contact_id,
				'shared'     => false,
				'title'      => (string) $deal->title,
				'status'     => (string) $deal->status,
				'stage'      => $deal->stage ? (string) $deal->stage->name : '',
				'amount'     => (float) $deal->value,
				'currency'   => (string) ( $deal->currency ?: '' ),
				'due_date'   => $deal->expected_close_date ? (string) $deal->expected_close_date : null,
				'date'       => $deal->created_at ? (string) $deal->created_at : null,
				'route'      => 'pipeline/deal/' . (int) $deal->id,
			);
		}
		return array(
			'data'  => $data,
			'total' => $total,
		);
	}

	/**
	 * Open deals of the company: how many and their value.
	 *
	 * @param array $summary Figures.
	 * @param int[] $ids     Member contact ids.
	 * @param mixed $company Company.
	 * @return array
	 */
	public static function summary( $summary, $ids, $company = null ): array {
		$summary = (array) $summary;
		unset( $ids );
		if ( ! $company || ! Permissions::can_read_deals() ) {
			return $summary;
		}
		$open  = self::query( (int) $company->id )->where( 'status', 'open' )->get( array( 'value', 'currency' ) );
		$money = array();
		foreach ( $open as $deal ) {
			$currency           = (string) ( $deal->currency ?: '' );
			$money[ $currency ] = ( $money[ $currency ] ?? 0 ) + (float) $deal->value;
		}
		$summary['open_deals'] = array(
			'count'    => count( $open ),
			'value'    => count( $money ) <= 1 ? (float) ( reset( $money ) ?: 0 ) : null,
			'currency' => 1 === count( $money ) ? (string) key( $money ) : null,
		);
		return $summary;
	}

	/**
	 * A contact who joins a company brings their deals that belong to no company.
	 *
	 * @param int $contact_id Contact ID.
	 * @param int $company_id New company (0 when they left).
	 * @return void
	 */
	public static function adopt_deals( $contact_id, $company_id ): void {
		if ( (int) $company_id <= 0 ) {
			return;
		}
		// A contact in several companies: deals go to their main one only.
		$main = \DoubleScale\Pro\Modules\Companies\Services\CompanyService::company_of( (int) $contact_id );
		if ( ! $main || (int) $main->id !== (int) $company_id ) {
			return;
		}
		DealModel::query()
			->where( 'contact_id', (int) $contact_id )
			->whereNull( 'company_id' )
			->update( array( 'company_id' => (int) $company_id ) );
	}

	/**
	 * The company's deals, limited to the user's own unless they see all deals.
	 *
	 * @param int $company_id Company ID.
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	private static function query( int $company_id ) {
		$query = DealModel::query()->where( 'company_id', $company_id );
		if ( ! PermissionsCompat::sees_all_deals() ) {
			$query->where( 'owner_id', get_current_user_id() );
		}
		return $query;
	}
}
