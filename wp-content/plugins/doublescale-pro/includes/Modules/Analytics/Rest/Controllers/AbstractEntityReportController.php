<?php
/**
 * Base REST controller for entity reports.
 *
 * Implements the request plumbing every entity report shares: route shape,
 * argument schema, filter normalization, module gating, and — most importantly
 * — ownership scoping.
 *
 * The ownership rule is implemented exactly once, here. It follows
 * RestSalesAnalyticsController: an explicit 403 when a non-manager names another
 * user's owner_id, and a *forced* self-scope otherwise. It deliberately does not
 * follow the `$filters['owner_id'] ?? get_current_user_id()` form used by
 * RestReportsController, where an explicitly supplied parameter wins over the
 * default and lets a sales rep read a peer's records.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\Analytics\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Pro\Modules\Analytics\Services\EntityReportService;
use DoubleScale\Pro\Modules\Analytics\Services\Support\ReportPeriod;
use DoubleScale\Pro\Modules\Analytics\Support\EntityReportDescriptor;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Shared entity report endpoint behaviour.
 */
abstract class AbstractEntityReportController extends RestController {

	/**
	 * Report service for this entity.
	 *
	 * @return EntityReportService
	 */
	abstract protected function service();

	/**
	 * Whether the current user may view this entity's reports at all.
	 *
	 * @return bool
	 */
	abstract protected function can_view();

	/**
	 * Whether the current user may view other users' records.
	 *
	 * @return bool
	 */
	abstract protected function can_manage_all();

	/**
	 * @return EntityReportDescriptor
	 */
	protected function descriptor() {
		return $this->service()->descriptor();
	}

	/**
	 * Register the report route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_report' ),
					'permission_callback' => array( $this, 'get_report_permissions_check' ),
					'args'                => $this->get_report_params(),
				),
			)
		);
	}

	/**
	 * Permission check: module gate first, then capability.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_report_permissions_check( $request ) {
		unset( $request );

		$disabled = $this->require_module( $this->descriptor()->module_slug() );
		if ( $disabled ) {
			return $disabled;
		}

		if ( ! $this->can_view() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view these reports.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Build the report.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_report( $request ) {
		$disabled = $this->require_module( $this->descriptor()->module_slug() );
		if ( $disabled ) {
			return $disabled;
		}

		if ( ! $this->descriptor()->model_exists() ) {
			return new WP_Error(
				'rest_not_available',
				__( 'This report is not available in the current installation.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		$filters = $this->get_filters_from_request( $request );

		$owner_error = $this->resolve_owner_filter( $request, $filters );
		if ( $owner_error instanceof WP_Error ) {
			return $owner_error;
		}

		$period = ReportPeriod::from_filters( $filters );

		return new WP_REST_Response( $this->service()->get_report( $period, $filters ), 200 );
	}

	/**
	 * Apply the ownership rule.
	 *
	 * Explicit 403 rather than silent substitution, so an over-reaching client is
	 * told it was refused instead of quietly shown different data.
	 *
	 * @param \WP_REST_Request     $request Request object.
	 * @param array<string, mixed> $filters Filters, modified in place.
	 * @return WP_Error|null
	 */
	protected function resolve_owner_filter( $request, array &$filters ) {
		// scope=self forces the current user's own records for everyone,
		// including managers — this is what the "My Reports" view requests. It
		// is derived server-side from get_current_user_id(), so it cannot be
		// used to read someone else's data.
		if ( 'self' === $request->get_param( 'scope' ) ) {
			$filters['owner_id'] = get_current_user_id();

			return null;
		}

		$owner_id = absint( $request->get_param( 'owner_id' ) );

		if ( $owner_id > 0 ) {
			if ( ! $this->can_manage_all() && $owner_id !== get_current_user_id() ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'You can only view your own reports.', 'doublescale' ),
					array( 'status' => 403 )
				);
			}

			$filters['owner_id'] = $owner_id;

			return null;
		}

		if ( ! $this->can_manage_all() ) {
			// Forced, not defaulted.
			$filters['owner_id'] = get_current_user_id();
		}

		return null;
	}

	/**
	 * Normalize request parameters into a filter array.
	 *
	 * `owner_id` is deliberately absent — it is set only by
	 * resolve_owner_filter() so the security rule cannot be bypassed by an
	 * earlier assignment.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return array<string, mixed>
	 */
	protected function get_filters_from_request( $request ) {
		$filters = array();

		$date_from = sanitize_text_field( (string) $request->get_param( 'date_from' ) );
		$date_to   = sanitize_text_field( (string) $request->get_param( 'date_to' ) );
		if ( '' !== $date_from ) {
			$filters['date_from'] = $date_from;
		}
		if ( '' !== $date_to ) {
			$filters['date_to'] = $date_to;
		}

		$frequency = sanitize_text_field( (string) $request->get_param( 'frequency' ) );
		if ( '' !== $frequency ) {
			$filters['frequency'] = $frequency;
		}

		$days_back = absint( $request->get_param( 'days_back' ) );
		if ( $days_back > 0 ) {
			$filters['days_back'] = $days_back;
		}

		$contact_id = absint( $request->get_param( 'contact_id' ) );
		if ( $contact_id > 0 ) {
			$filters['contact_id'] = $contact_id;
		}

		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );
		if ( '' !== $status ) {
			$filters['status'] = $status;
		}

		$currencies = sanitize_text_field( (string) $request->get_param( 'currencies' ) );
		if ( '' !== $currencies ) {
			$filters['currencies'] = $currencies;
		}

		return $filters;
	}

	/**
	 * Argument schema shared by every entity report.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_report_params() {
		return array(
			'date_from'  => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Range start (Y-m-d).', 'doublescale' ),
			),
			'date_to'    => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Range end (Y-m-d).', 'doublescale' ),
			),
			'frequency'  => array(
				'type'              => 'string',
				'required'          => false,
				'default'           => ReportPeriod::DAILY,
				'enum'              => array( ReportPeriod::DAILY, ReportPeriod::WEEKLY, ReportPeriod::MONTHLY ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'days_back'  => array(
				'type'              => 'integer',
				'required'          => false,
				'minimum'           => 1,
				'maximum'           => 730,
				'sanitize_callback' => 'absint',
			),
			'owner_id'   => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'scope'      => array(
				'type'              => 'string',
				'required'          => false,
				'enum'              => array( 'self' ),
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Set to "self" to force the current user\'s own records.', 'doublescale' ),
			),
			'contact_id' => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'status'     => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'currencies' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Comma-separated currency codes (e.g. USD,EUR).', 'doublescale' ),
			),
		);
	}
}
