<?php
/**
 * REST API: Log Controller
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage API
 */

namespace DoubleScale\Modules\Smtp\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Smtp\EmailLog\EmailLogHandler;
use DoubleScale\Modules\Smtp\Rest\EmailLogExport;
use DoubleScale\Modules\Smtp\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST_Email_Log_Controller is REST api controller class for log
 *
 * @since 1.0.0
 */
class RestSmtpEmailLogController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'smtp/email-log';

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/mutation',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'mutation' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
				),
			)
		);

		// Export logs.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'export_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// Get logs count for specific date for chart.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/count',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_count' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<log_id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);

		// Resent emails.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/resend',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resend_emails' ),
					'permission_callback' => array( $this, 'resend_emails_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Resend emails
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function resend_emails( $request ) {
		$ids = $request->get_param( 'ids' );
		$ids = explode( ',', $ids );

		if ( empty( $ids ) ) {
			return new WP_Error( 'doublescale_smtp_logs_no_ids', esc_html__( 'No ids provided', 'doublescale' ), array( 'status' => 422 ) );
		}

		$logs = EmailLogHandler::get( $ids );
		if ( empty( $logs ) ) {
			return new WP_Error( 'doublescale_smtp_logs_no_logs', esc_html__( 'No logs found', 'doublescale' ), array( 'status' => 422 ) );
		}

		foreach ( $logs as $log ) {
			if ( empty( $log ) ) {
				continue;
			}
			$email = array(
				'to'          => $log['recipients']['to'],
				'from'        => $log['from'],
				'cc'          => $log['recipients']['cc'],
				'bcc'         => $log['recipients']['bcc'],
				'reply_to'    => $log['recipients']['reply_to'],
				'subject'     => $log['subject'],
				'body'        => $log['body'],
				'headers'     => $log['headers'],
				'attachments' => $log['attachments'],
			);

			$to      = $email['to'];
			$subject = $email['subject'];

			// Message.
			$message = $email['body'];

			// Headers.
			$headers = array();

			// Check if is body text is html.
			if ( $this->is_html( $message ) ) {
				$headers[] = 'Content-Type: text/html; charset=UTF-8';
			} else {
				$headers[] = 'Content-Type: text/plain; charset=UTF-8';
			}

			if ( ! empty( $email['from'] ) ) {
				$headers[] = 'From: ' . $email['from'];
			}

			if ( ! empty( $email['cc'] ) ) {
				$headers[] = 'Cc: ' . $email['cc'];
			}

			if ( ! empty( $email['bcc'] ) ) {
				$headers[] = 'Bcc: ' . $email['bcc'];
			}

			if ( ! empty( $email['reply_to'] ) ) {
				$headers[] = 'Reply-To: ' . $email['reply_to'];
			}

			if ( ! empty( $email['headers'] ) ) {
				$headers[] = $email['headers'];
			}

			// Attachments.
			$attachments = array();
			if ( ! empty( $email['attachments'] ) ) {
				$attachments = $email['attachments'];
			}

			add_filter(
				'doublescale_smtp_mailer_log_result',
				function ( $result, $email_data ) use ( $log ) {
					$resend_count = $log['resend_count'] ?? 0;
					if ( 'succeeded' === $email_data['status'] && 'succeeded' === $log['status'] ) {
						// Update resent count.
						$resend_count = is_numeric( $resend_count ) ? $resend_count + 1 : 1;
					}

					EmailLogHandler::update(
						$log['log_id'],
						array(
							'resend_count' => $resend_count,
							'status'       => $email_data['status'],
							'response'     => $email_data['response'] ?? array(),
						)
					);
					return false;
				},
				10,
				2
			);

			// Send email.
			wp_mail( $to, $subject, $message, $headers, $attachments );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Check if string is html
	 *
	 * @param string $string string.
	 * @return bool
	 */
	private function is_html( $string ) {
		return preg_match( '/<[^<]+>/', $string ) !== 0;
	}

	/**
	 * Resend emails permission check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function resend_emails_permissions_check( $request ) {
		return Settings::user_can_manage_smtp_rest();
	}

	/**
	 * Get count of logs for specific date
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_count( $request ) {
		$from_date         = $request->get_param( 'start' );
		$to_date           = $request->get_param( 'end' );
		$logs_for_each_day = array();

		if ( $from_date && $to_date ) {
			// Days between two dates.
			$from_date = $this->get_date( $from_date );
			$to_date   = $this->get_date( $to_date, '23:59:59' );
			$from_date = new \DateTime( $from_date );
			$to_date   = new \DateTime( $to_date );
			$interval  = new \DateInterval( 'P1D' );
			$period    = new \DatePeriod( $from_date, $interval, $to_date );

			foreach ( $period as $date ) {
				$logs_for_each_day[ $date->format( 'Y-m-d' ) ] = EmailLogHandler::get_count( false, $date->format( 'Y-m-d 00:00:00' ), $date->format( 'Y-m-d 23:59:59' ) );
			}
		}

		$success_logs = EmailLogHandler::get_count( 'succeeded' );
		$error_logs   = EmailLogHandler::get_count( 'failed' );
		$total_logs   = EmailLogHandler::get_count();

		$result = array(
			'total'   => $total_logs,
			'success' => $success_logs,
			'failed'  => $error_logs,
			'days'    => $logs_for_each_day,
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get all logs.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		$status = $this->normalize_list_status( $request->get_param( 'status' ) );

		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 10 );
		$per_page = max( 1, min( 200, $per_page ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$offset   = $per_page * ( $page - 1 );

		$search = $request->get_param( 'search' );
		$search = ( is_string( $search ) && $search !== '' )
			? sanitize_text_field( wp_unslash( $search ) )
			: false;

		$start_date = false;
		$end_date   = false;
		$start_raw  = $request->get_param( 'start_date' );
		$end_raw    = $request->get_param( 'end_date' );
		if ( is_string( $start_raw ) && is_string( $end_raw ) && $start_raw !== '' && $end_raw !== '' ) {
			$parsed = $this->parse_date_range_for_storage( $start_raw, $end_raw );
			if ( $parsed ) {
				$start_date = $parsed[0];
				$end_date   = $parsed[1];
			}
		}

		$logs        = EmailLogHandler::get_all( $status, $offset, $per_page, $start_date, $end_date, $search );
		$total_items = EmailLogHandler::get_count( $status, $start_date, $end_date, $search );

		$total_pages = $per_page > 0 ? (int) ceil( $total_items / $per_page ) : 0;

		$data = array(
			'items'       => $logs,
			'total_items' => $total_items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Perform delete / flush via POST JSON body (works when DELETE requests are blocked by hosts/CDNs).
	 *
	 * Body: `{ "op": "delete_one", "log_id": 1 }` | `{ "op": "delete_many", "ids": [1,2] }` | `{ "op": "flush" }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function mutation( $request ) {
		$op = sanitize_key( (string) $request->get_param( 'op' ) );

		switch ( $op ) {
			case 'delete_one':
				$log_id = absint( $request->get_param( 'log_id' ) );
				if ( ! $log_id ) {
					return new WP_Error(
						'doublescale_smtp_logs_invalid_log_id',
						__( 'Invalid log id.', 'doublescale' ),
						array( 'status' => 400 )
					);
				}
				if ( ! EmailLogHandler::delete( $log_id ) ) {
					return new WP_Error(
						'doublescale_smtp_logs_delete_failed',
						__( 'Could not delete log entry.', 'doublescale' ),
						array( 'status' => 422 )
					);
				}
				return new WP_REST_Response( array( 'success' => true ), 200 );

			case 'delete_many':
				$ids = $request->get_param( 'ids' );
				if ( ! is_array( $ids ) || empty( $ids ) ) {
					return new WP_Error(
						'doublescale_smtp_logs_no_ids',
						__( 'No log ids provided.', 'doublescale' ),
						array( 'status' => 400 )
					);
				}
				if ( ! EmailLogHandler::delete( array_map( 'absint', $ids ) ) ) {
					return new WP_Error(
						'doublescale_smtp_logs_delete_failed',
						__( 'Could not delete selected log entries.', 'doublescale' ),
						array( 'status' => 422 )
					);
				}
				return new WP_REST_Response( array( 'success' => true ), 200 );

			case 'flush':
				return new WP_REST_Response(
					array( 'success' => (bool) EmailLogHandler::flush() ),
					200
				);

			default:
				return new WP_Error(
					'doublescale_smtp_logs_invalid_op',
					__( 'Invalid operation.', 'doublescale' ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Export items with pagination and file creation.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function export_items( $request ) {
		return EmailLogExport::export_items(
			array(
				'file_id'     => $request->get_param( 'file_id' ),
				'file_prefix' => 'email',
				'download'    => $request->get_param( 'download' ) ?? false,
				'filter'      => $request->get_param( 'status' ) ?? false,
				'offset'      => intval( $request->get_param( 'offset' ) ?? 0 ),
				'limit'       => 100,
			),
			array( EmailLogHandler::class, 'get_all' )
		);
	}

	/**
	 * Check if a given request has access to get all items.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|bool
	 */
	public function get_items_permissions_check( $request ) {
		return Settings::user_can_manage_smtp_rest();
	}

	/**
	 * Delete items from the collection
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|WP_REST_RESPONSE
	 */
	public function delete_items( $request ) {
		$ids = $request->get_param( 'ids' );

		if ( is_array( $ids ) ) {
			if ( ! empty( $ids ) ) {
				$deleted = (bool) EmailLogHandler::delete( $ids );
			} else {
				$deleted = false;
			}
		} else {
			$deleted = (bool) EmailLogHandler::flush();
		}

		return new WP_REST_Response( array( 'success' => $deleted ), 200 );
	}

	/**
	 * Delete items permission check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|bool
	 */
	public function delete_items_permissions_check( $request ) {
		return Settings::user_can_manage_smtp_rest();
	}

	/**
	 * Delete one item from the collection
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|WP_REST_RESPONSE
	 */
	public function delete_item( $request ) {
		$deleted = EmailLogHandler::delete( $request->get_param( 'log_id' ) );

		if ( ! $deleted ) {
			return new WP_Error( 'doublescale_smtp_logs_db_error_on_deleting_log', __( 'Error on deleting log in db!', 'doublescale' ), array( 'status' => 422 ) );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Delete item permission check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_Error|bool
	 */
	public function delete_item_permissions_check( $request ) {
		return Settings::user_can_manage_smtp_rest();
	}

	/**
	 * REST query parameters for paginated SMTP email log listing.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params() {
		return array(
			'page'       => array(
				'description'       => __( 'Current page (1-based).', 'doublescale' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value >= 1;
				},
			),
			'per_page'   => array(
				'description'       => __( 'Number of log rows per page (max 200).', 'doublescale' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 200,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 200;
				},
			),
			'status'     => array(
				'description' => __( 'Filter by outcome: all, succeeded, or failed.', 'doublescale' ),
				'type'        => 'string',
				'enum'        => array( 'all', 'succeeded', 'failed' ),
				'default'     => 'all',
			),
			'search'     => array(
				'description' => __( 'Search subject, body, headers, from, or recipients.', 'doublescale' ),
				'type'        => 'string',
			),
			'start_date' => array(
				'description' => __( 'Start date (Y-m-d), paired with end_date.', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),
			'end_date'   => array(
				'description' => __( 'End date (Y-m-d), paired with start_date.', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),
		);
	}

	/**
	 * Normalize list filter status (false = all logs).
	 *
	 * @param mixed $param Raw status query arg.
	 * @return string|false succeeded|failed or false.
	 */
	protected function normalize_list_status( $param ) {
		if ( null === $param || '' === $param || 'all' === $param ) {
			return false;
		}
		$s = sanitize_key( (string) $param );
		if ( in_array( $s, array( 'succeeded', 'failed' ), true ) ) {
			return $s;
		}

		return false;
	}

	/**
	 * Parse start/end day into GMT bounds for the stored `timestamp` column (GMT strings).
	 *
	 * Accepts `Y-m-d` (recommended for REST) or legacy `m/d/Y`.
	 *
	 * @param string $start_raw Start date string.
	 * @param string $end_raw   End date string.
	 * @return array{0: string, 1: string}|null Two GMT datetimes or null if invalid.
	 */
	protected function parse_date_range_for_storage( $start_raw, $end_raw ) {
		$start_raw = trim( (string) $start_raw );
		$end_raw   = trim( (string) $end_raw );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_raw ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_raw ) ) {
			$start_gmt = get_gmt_from_date( $start_raw . ' 00:00:00' );
			$end_gmt   = get_gmt_from_date( $end_raw . ' 23:59:59' );
			if ( $start_gmt && $end_gmt ) {
				return array( $start_gmt, $end_gmt );
			}

			return null;
		}

		if ( strpos( $start_raw, '/' ) !== false && strpos( $end_raw, '/' ) !== false ) {
			return array(
				$this->get_date( $start_raw ),
				$this->get_date( $end_raw, '23:59:59' ),
			);
		}

		return null;
	}

	/**
	 * Get valid date
	 *
	 * @param string $date date.
	 * @param string $time time.
	 *
	 * @return string
	 */
	public function get_date( $date, $time = '00:00:00' ) {
		list($month, $day, $year) = explode( '/', $date );
		$value                    = "$year-$month-$day";
		if ( $time ) {
			$value .= " $time";
		}

		return $value;
	}
}
