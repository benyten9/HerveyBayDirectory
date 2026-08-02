<?php
/**
 * REST controller for support reports — aggregate read-only analytics.
 *
 * Routes registered (namespace `doublescale/v1`):
 *
 *   GET /support/reports/summary
 *   GET /support/reports/tickets-over-time
 *   GET /support/reports/breakdown
 *   GET /support/reports/agents
 *   GET /support/reports/mailboxes
 *
 * All routes gate through {@see require_support_access()} and
 * {@see require_module('support')}. Non-managers are scoped to their own
 * assigned tickets on every aggregation query.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Support\Constants\TicketPriority;
use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Modules\Support\Models\MailboxModel;
use DoubleScale\Modules\Support\Models\TicketModel;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestReportController class.
 */
class RestReportController extends RestController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'support/reports';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$routes = array(
			'summary'   => 'get_summary',
			'breakdown' => 'get_breakdown',
			'agents'    => 'get_agents',
			'mailboxes' => 'get_mailboxes',
		);

		foreach ( $routes as $suffix => $callback ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/' . $suffix,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, $callback ),
						'permission_callback' => array( $this, 'permissions_check' ),
						'args'                => $this->get_filter_params(),
					),
				)
			);
		}

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tickets-over-time',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tickets_over_time' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array_merge(
						$this->get_filter_params(),
						array(
							'bucket' => array(
								'type'              => 'string',
								'default'           => 'auto',
								'sanitize_callback' => 'sanitize_text_field',
							),
						)
					),
				),
			)
		);
	}

	/**
	 * Shared filter query args.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_filter_params(): array {
		return array(
			'from'          => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'to'            => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'mailbox_id'    => array(
				'type' => 'integer',
			),
			'agent_user_id' => array(
				'type' => 'integer',
			),
		);
	}

	/**
	 * GET /support/reports/summary
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_summary( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$filters = $this->parse_filters( $request );

		$base = TicketModel::query();
		$this->apply_ticket_filters( $base, $filters, true );

		$new = (int) ( clone $base )->count();

		$status_rows = ( clone $base )
			->selectRaw( 'status, COUNT(*) as c' )
			->groupBy( 'status' )
			->get();

		$by_status = array();
		foreach ( $status_rows as $row ) {
			$by_status[ (string) $row->status ] = (int) $row->c;
		}

		$open     = ( $by_status[ TicketStatus::OPEN ] ?? 0 ) + ( $by_status[ TicketStatus::PENDING ] ?? 0 );
		$resolved = $by_status[ TicketStatus::RESOLVED ] ?? 0;
		$closed   = $by_status[ TicketStatus::CLOSED ] ?? 0;

		$responses_query = ActivityModel::query()
			->messages()
			->whereBetween( 'created_at', array( $filters['from_datetime'], $filters['to_datetime'] ) )
			->whereHas(
				'associations',
				function ( $q ) {
					$q->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_TICKET );
				}
			);

		$this->apply_activity_ticket_scope( $responses_query, $filters );

		return new WP_REST_Response(
			array(
				'new'              => $new,
				'open'             => $open,
				'resolved'         => $resolved,
				'closed'           => $closed,
				'total_responses'  => (int) $responses_query->count(),
			),
			200
		);
	}

	/**
	 * GET /support/reports/tickets-over-time
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tickets_over_time( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$filters = $this->parse_filters( $request );
		$bucket  = $this->resolve_bucket( $filters['from'], $filters['to'], (string) $request->get_param( 'bucket' ) );

		list( $select_expr, $group_expr ) = $this->bucket_sql( $bucket, 'created_at' );
		list( $resolved_select, $resolved_group ) = $this->bucket_sql( $bucket, 'updated_at' );

		$created_query = TicketModel::query();
		$this->apply_ticket_filters( $created_query, $filters, true );
		$created_rows = $created_query
			->selectRaw( "{$select_expr} as d, COUNT(*) as c" )
			->groupBy( 'd' )
			->orderBy( 'd', 'asc' )
			->get();

		$created_map = array();
		foreach ( $created_rows as $row ) {
			$created_map[ (string) $row->d ] = (int) $row->c;
		}

		$resolved_query = TicketModel::query()
			->whereIn( 'status', array( TicketStatus::RESOLVED, TicketStatus::CLOSED ) )
			->whereBetween( 'updated_at', array( $filters['from_datetime'], $filters['to_datetime'] ) );
		$this->apply_ticket_filters( $resolved_query, $filters, false );

		$resolved_rows = $resolved_query
			->selectRaw( "{$resolved_select} as d, COUNT(*) as c" )
			->groupBy( 'd' )
			->orderBy( 'd', 'asc' )
			->get();

		$resolved_map = array();
		foreach ( $resolved_rows as $row ) {
			$resolved_map[ (string) $row->d ] = (int) $row->c;
		}

		$series = $this->fill_time_series( $filters['from'], $filters['to'], $bucket, $created_map, $resolved_map );

		return new WP_REST_Response(
			array(
				'bucket' => $bucket,
				'series' => $series,
			),
			200
		);
	}

	/**
	 * GET /support/reports/breakdown
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_breakdown( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$filters = $this->parse_filters( $request );

		$base = TicketModel::query();
		$this->apply_ticket_filters( $base, $filters, true );

		$status_rows = ( clone $base )
			->selectRaw( 'status, COUNT(*) as c' )
			->groupBy( 'status' )
			->get()
			->keyBy( 'status' );

		$by_status = array();
		foreach ( TicketStatus::all() as $status ) {
			$by_status[] = array(
				'key'   => $status,
				'label' => TicketStatus::get_label( $status ),
				'count' => isset( $status_rows[ $status ] ) ? (int) $status_rows[ $status ]->c : 0,
			);
		}

		$priority_rows = ( clone $base )
			->selectRaw( 'priority, COUNT(*) as c' )
			->groupBy( 'priority' )
			->get()
			->keyBy( 'priority' );

		$by_priority = array();
		foreach ( TicketPriority::all() as $priority ) {
			$by_priority[] = array(
				'key'   => $priority,
				'label' => TicketPriority::get_label( $priority ),
				'count' => isset( $priority_rows[ $priority ] ) ? (int) $priority_rows[ $priority ]->c : 0,
			);
		}

		return new WP_REST_Response(
			array(
				'by_status'   => $by_status,
				'by_priority' => $by_priority,
			),
			200
		);
	}

	/**
	 * GET /support/reports/agents
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_agents( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$filters = $this->parse_filters( $request );

		$assigned_query = TicketModel::query();
		$this->apply_ticket_filters( $assigned_query, $filters, true );
		$assigned_rows = $assigned_query
			->selectRaw( 'agent_user_id, COUNT(*) as assigned' )
			->whereNotNull( 'agent_user_id' )
			->groupBy( 'agent_user_id' )
			->get()
			->keyBy( 'agent_user_id' );

		$resolved_query = TicketModel::query()
			->whereIn( 'status', array( TicketStatus::RESOLVED, TicketStatus::CLOSED ) );
		$this->apply_ticket_filters( $resolved_query, $filters, true );
		$resolved_rows = $resolved_query
			->selectRaw( 'agent_user_id, COUNT(*) as resolved' )
			->whereNotNull( 'agent_user_id' )
			->groupBy( 'agent_user_id' )
			->get()
			->keyBy( 'agent_user_id' );

		$responses_query = ActivityModel::query()
			->messages()
			->whereBetween( 'created_at', array( $filters['from_datetime'], $filters['to_datetime'] ) )
			->whereNotNull( 'user_id' )
			->whereHas(
				'associations',
				function ( $q ) {
					$q->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_TICKET );
				}
			);
		$this->apply_activity_ticket_scope( $responses_query, $filters );

		$response_rows = $responses_query
			->selectRaw( 'user_id, COUNT(*) as responses' )
			->groupBy( 'user_id' )
			->get()
			->keyBy( 'user_id' );

		$agent_ids = array_unique(
			array_merge(
				$assigned_rows->keys()->all(),
				$resolved_rows->keys()->all(),
				$response_rows->keys()->all()
			)
		);

		if ( ! Permissions::can_manage_all_tickets() ) {
			$agent_ids = array( get_current_user_id() );
		}

		$data = array();
		foreach ( $agent_ids as $agent_id ) {
			$agent_id = (int) $agent_id;
			if ( $agent_id <= 0 ) {
				continue;
			}
			$user = get_userdata( $agent_id );
			if ( ! $user ) {
				continue;
			}

			$data[] = array(
				'agent'     => $this->shape_agent( $user ),
				'assigned'  => isset( $assigned_rows[ $agent_id ] ) ? (int) $assigned_rows[ $agent_id ]->assigned : 0,
				'resolved'  => isset( $resolved_rows[ $agent_id ] ) ? (int) $resolved_rows[ $agent_id ]->resolved : 0,
				'responses' => isset( $response_rows[ $agent_id ] ) ? (int) $response_rows[ $agent_id ]->responses : 0,
			);
		}

		usort(
			$data,
			static function ( $a, $b ) {
				return $b['assigned'] <=> $a['assigned'];
			}
		);

		return new WP_REST_Response( array( 'data' => $data ), 200 );
	}

	/**
	 * GET /support/reports/mailboxes
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_mailboxes( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$filters = $this->parse_filters( $request );

		$base = TicketModel::query();
		$this->apply_ticket_filters( $base, $filters, true );

		$rows = $base
			->selectRaw( 'mailbox_id, status, COUNT(*) as c' )
			->whereNotNull( 'mailbox_id' )
			->groupBy( 'mailbox_id', 'status' )
			->get();

		$by_mailbox = array();
		foreach ( $rows as $row ) {
			$mailbox_id = (int) $row->mailbox_id;
			if ( ! isset( $by_mailbox[ $mailbox_id ] ) ) {
				$by_mailbox[ $mailbox_id ] = array(
					'total'    => 0,
					'open'     => 0,
					'resolved' => 0,
					'closed'   => 0,
				);
			}
			$count = (int) $row->c;
			$by_mailbox[ $mailbox_id ]['total'] += $count;

			$status = (string) $row->status;
			if ( TicketStatus::OPEN === $status || TicketStatus::PENDING === $status ) {
				$by_mailbox[ $mailbox_id ]['open'] += $count;
			} elseif ( TicketStatus::RESOLVED === $status ) {
				$by_mailbox[ $mailbox_id ]['resolved'] += $count;
			} elseif ( TicketStatus::CLOSED === $status ) {
				$by_mailbox[ $mailbox_id ]['closed'] += $count;
			}
		}

		$mailboxes = MailboxModel::whereIn( 'id', array_keys( $by_mailbox ) )->get()->keyBy( 'id' );

		$data = array();
		foreach ( $by_mailbox as $mailbox_id => $stats ) {
			$mailbox = $mailboxes->get( $mailbox_id );
			$data[]  = array(
				'mailbox'  => array(
					'id'   => $mailbox_id,
					'slug' => $mailbox ? (string) $mailbox->slug : '',
					'name' => $mailbox ? (string) $mailbox->name : (string) $mailbox_id,
				),
				'total'    => $stats['total'],
				'open'     => $stats['open'],
				'resolved' => $stats['resolved'],
				'closed'   => $stats['closed'],
			);
		}

		usort(
			$data,
			static function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);

		return new WP_REST_Response( array( 'data' => $data ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Unused — present for the framework contract. // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return $this->require_support_access();
	}

	/**
	 * Parse and normalize shared report filters from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function parse_filters( WP_REST_Request $request ): array {
		$from = sanitize_text_field( (string) $request->get_param( 'from' ) );
		$to   = sanitize_text_field( (string) $request->get_param( 'to' ) );

		if ( ! $this->is_valid_date( $from ) ) {
			$from = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		}
		if ( ! $this->is_valid_date( $to ) ) {
			$to = gmdate( 'Y-m-d' );
		}

		if ( $from > $to ) {
			$swap = $from;
			$from = $to;
			$to   = $swap;
		}

		$mailbox_id = $request->get_param( 'mailbox_id' );
		$agent_id   = $request->get_param( 'agent_user_id' );

		$filters = array(
			'from'          => $from,
			'to'            => $to,
			'from_datetime' => $from . ' 00:00:00',
			'to_datetime'   => $to . ' 23:59:59',
			'mailbox_id'    => ( null !== $mailbox_id && '' !== $mailbox_id ) ? (int) $mailbox_id : null,
			'agent_user_id' => null,
		);

		if ( Permissions::can_manage_all_tickets() ) {
			if ( null !== $agent_id && '' !== $agent_id ) {
				$filters['agent_user_id'] = (int) $agent_id;
			}
		} else {
			$filters['agent_user_id'] = get_current_user_id();
		}

		return $filters;
	}

	/**
	 * Apply mailbox / agent / ownership filters to a ticket query.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param array<string, mixed>                  $filters Parsed filters.
	 * @param bool                                  $use_created_range Whether to constrain created_at.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	private function apply_ticket_filters( $query, array $filters, bool $use_created_range = true ) {
		if ( $use_created_range ) {
			$query->whereBetween( 'created_at', array( $filters['from_datetime'], $filters['to_datetime'] ) );
		}

		if ( ! empty( $filters['mailbox_id'] ) ) {
			$query->where( 'mailbox_id', $filters['mailbox_id'] );
		}

		if ( ! empty( $filters['agent_user_id'] ) ) {
			$query->where( 'agent_user_id', $filters['agent_user_id'] );
		} elseif ( ! Permissions::can_manage_all_tickets() ) {
			$query->where( 'agent_user_id', get_current_user_id() );
		}

		return $query;
	}

	/**
	 * Scope activity message counts to tickets matching report filters.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Activity query.
	 * @param array<string, mixed>                  $filters Parsed filters.
	 * @return void
	 */
	private function apply_activity_ticket_scope( $query, array $filters ): void {
		$query->whereHas(
			'associations',
			function ( $q ) use ( $filters ) {
				$q->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_TICKET )
					->whereIn(
						'entity_id',
						function ( $sub ) use ( $filters ) {
							$sub->select( 'id' )
								->from( ( new TicketModel() )->getTable() );

							if ( ! empty( $filters['mailbox_id'] ) ) {
								$sub->where( 'mailbox_id', $filters['mailbox_id'] );
							}
							if ( ! empty( $filters['agent_user_id'] ) ) {
								$sub->where( 'agent_user_id', $filters['agent_user_id'] );
							} elseif ( ! Permissions::can_manage_all_tickets() ) {
								$sub->where( 'agent_user_id', get_current_user_id() );
							}
						}
					);
			}
		);
	}

	/**
	 * Resolve time bucket from span or explicit request value.
	 *
	 * @param string $from   Start date Y-m-d.
	 * @param string $to     End date Y-m-d.
	 * @param string $bucket Requested bucket (`auto` picks by span).
	 * @return string daily|weekly|monthly
	 */
	private function resolve_bucket( string $from, string $to, string $bucket ): string {
		if ( in_array( $bucket, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			return $bucket;
		}

		$days = (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS );
		if ( $days <= 62 ) {
			return 'daily';
		}
		if ( $days <= 181 ) {
			return 'weekly';
		}
		return 'monthly';
	}

	/**
	 * SQL expressions for bucketing a datetime column.
	 *
	 * @param string $bucket    Bucket type.
	 * @param string $date_col  Column name.
	 * @return array{0: string, 1: string}
	 */
	private function bucket_sql( string $bucket, string $date_col ): array {
		switch ( $bucket ) {
			case 'weekly':
				return array(
					"DATE_FORMAT({$date_col}, '%x-%v')",
					"DATE_FORMAT({$date_col}, '%x-%v')",
				);
			case 'monthly':
				return array(
					"DATE_FORMAT({$date_col}, '%Y-%m')",
					"DATE_FORMAT({$date_col}, '%Y-%m')",
				);
			default:
				return array(
					"DATE({$date_col})",
					'DATE(' . $date_col . ')',
				);
		}
	}

	/**
	 * Zero-fill a merged created/resolved time series.
	 *
	 * @param string               $from         Start date.
	 * @param string               $to           End date.
	 * @param string               $bucket       Bucket type.
	 * @param array<string, int>   $created_map  Created counts keyed by bucket.
	 * @param array<string, int>   $resolved_map Resolved counts keyed by bucket.
	 * @return array<int, array<string, mixed>>
	 */
	private function fill_time_series( string $from, string $to, string $bucket, array $created_map, array $resolved_map ): array {
		$keys  = $this->generate_bucket_keys( $from, $to, $bucket );
		$out   = array();

		foreach ( $keys as $key ) {
			$out[] = array(
				'date'     => $key,
				'created'  => $created_map[ $key ] ?? 0,
				'resolved' => $resolved_map[ $key ] ?? 0,
			);
		}

		return $out;
	}

	/**
	 * Generate ordered bucket keys covering the date range.
	 *
	 * @param string $from   Start date.
	 * @param string $to     End date.
	 * @param string $bucket Bucket type.
	 * @return string[]
	 */
	private function generate_bucket_keys( string $from, string $to, string $bucket ): array {
		$keys    = array();
		$current = new \DateTime( $from );
		$end     = new \DateTime( $to );

		if ( 'monthly' === $bucket ) {
			$current->modify( 'first day of this month' );
			while ( $current <= $end ) {
				$keys[] = $current->format( 'Y-m' );
				$current->modify( '+1 month' );
			}
			return $keys;
		}

		if ( 'weekly' === $bucket ) {
			$current->modify( 'monday this week' );
			if ( $current > $end ) {
				$current->modify( '-7 days' );
			}
			while ( $current <= $end ) {
				$keys[] = $current->format( 'o-W' );
				$current->modify( '+7 days' );
			}
			return $keys;
		}

		while ( $current <= $end ) {
			$keys[] = $current->format( 'Y-m-d' );
			$current->modify( '+1 day' );
		}

		return $keys;
	}

	/**
	 * @param string $date Date string.
	 * @return bool
	 */
	private function is_valid_date( string $date ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
	}

	/**
	 * @param \WP_User $user User to shape.
	 * @return array<string, mixed>
	 */
	private function shape_agent( \WP_User $user ): array {
		return array(
			'id'           => (int) $user->ID,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	private function require_support_access() {
		if ( Permissions::has_support_access() ) {
			return true;
		}
		return new WP_Error( 'not_allowed', __( 'You do not have permission to access support tickets.', 'doublescale' ), array( 'status' => 403 ) );
	}
}
