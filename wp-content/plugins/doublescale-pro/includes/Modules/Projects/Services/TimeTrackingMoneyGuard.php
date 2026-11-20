<?php
/**
 * Keeps project money away from team members on sites whose time tracking
 * add-on predates project teams.
 *
 * @package DoubleScale\Pro\Modules\Projects\Services
 */

namespace DoubleScale\Pro\Modules\Projects\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Projects\Capabilities;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Before project teams only owners and managers could read a project, so the
 * add-on put the project's rate, fee type and tax on every time entry. A
 * member reads the project now; until the add-on hides its money itself
 * (it then has TimeEntryAccess::can_see_project_money()), Pro takes it out of
 * the add-on's responses.
 */
final class TimeTrackingMoneyGuard {

	private const ADDON_ACCESS_CLASS = '\\DoubleScale\\TimeTracking\\Services\\TimeEntryAccess';

	/**
	 * @return void
	 */
	public static function register(): void {
		if ( ! defined( 'DOUBLESCALE_TT_VERSION' ) || method_exists( self::ADDON_ACCESS_CLASS, 'can_see_project_money' ) ) {
			return;
		}
		add_filter( 'rest_post_dispatch', array( self::class, 'filter_response' ), 20, 3 );
	}

	/**
	 * @param mixed           $response Response.
	 * @param mixed           $server   Server.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function filter_response( $response, $server, $request ) {
		unset( $server );
		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		if ( 0 !== strpos( (string) $request->get_route(), '/doublescale/v1/time-entries' ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$memo = array();
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			foreach ( $data['data'] as $i => $row ) {
				$data['data'][ $i ] = self::strip_row( $row, $memo );
			}
		} elseif ( isset( $data['project'] ) && is_array( $data['project'] ) && isset( $data['id'] ) ) {
			$data = self::strip_row( $data, $memo );
		}

		// The summary of one project.
		$project_id = (int) $request->get_param( 'project_id' );
		if ( $project_id > 0 && isset( $data['project'] ) && is_array( $data['project'] ) && ! isset( $data['project']['id'] ) && ! self::sees_money( $project_id, $memo ) ) {
			$data['project']['fee_type']    = null;
			$data['project']['hourly_rate'] = null;
		}

		$response->set_data( $data );
		return $response;
	}

	/**
	 * @param mixed              $row  One time entry.
	 * @param array<int, bool>   $memo Answers per project.
	 * @return mixed
	 */
	private static function strip_row( $row, array &$memo ) {
		if ( ! is_array( $row ) || empty( $row['project']['id'] ) ) {
			return $row;
		}
		if ( self::sees_money( (int) $row['project']['id'], $memo ) ) {
			return $row;
		}
		$row['project']['fee_type']    = null;
		$row['project']['hourly_rate'] = null;
		$row['project']['tax']         = null;
		if ( array_key_exists( 'invoice', $row ) ) {
			$row['invoice'] = null;
		}
		return $row;
	}

	/**
	 * @param int              $project_id Project.
	 * @param array<int, bool> $memo       Answers per project.
	 * @return bool
	 */
	private static function sees_money( int $project_id, array &$memo ): bool {
		if ( ! array_key_exists( $project_id, $memo ) ) {
			$memo[ $project_id ] = Capabilities::can_view_project_financials( $project_id );
		}
		return $memo[ $project_id ];
	}
}
