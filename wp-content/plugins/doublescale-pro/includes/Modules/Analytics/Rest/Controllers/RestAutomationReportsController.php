<?php

/**
 * Class RestAutomationReportsController
 * This class is responsible for handling the Automation Reports REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Analytics\Rest\Controllers;

use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationContactProcessesModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Core\Constants\TrackingStatus;

/**
 * Global WordPress functions used in this controller
 * These are available in WordPress context but may not be available to the linter
 *
 * @global function register_rest_route()
 * @global function __()
 * @global function current_user_can()
 * @global constant ABSPATH
 */

/**
 * RestAutomationReportsController class
 */
class RestAutomationReportsController extends RestController {

	/**
	 * Process statuses that mean a contact entered/completed a step.
	 *
	 * Condition steps store `yes`/`no`; other steps use `completed`.
	 *
	 * @since 1.1.8
	 *
	 * @var string[]
	 */
	private const STEP_ENTERED_STATUSES = array( 'completed', 'yes', 'no' );

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'automation-reports';

	/**
	 * Register the routes for the objects of the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		 // Get funnel data for a specific automation
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/get-chart-report',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_chart_report' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Automation ID.', 'doublescale'),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// Get detailed step analytics for a specific automation
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/steps-report',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_steps_report' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Automation ID.', 'doublescale'),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// Get email analytics for a specific automation
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/email-analytics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_email_analytics' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Automation ID.', 'doublescale'),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// Get email analytics for a specific step
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/step/(?P<step_id>[\d]+)/email-analytics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_step_email_analytics' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'step_id' => array(
							'description' => __( 'Automation Step ID.', 'doublescale'),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Get funnel data for chart visualization
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_chart_report( $request ) {
		try {
			$automation_id = $request->get_param( 'id' );
			// Verify automation exists
			$automation = AutomationModel::find( $automation_id );
			if ( ! $automation ) {
				return new WP_Error( 'not_found', __( 'Automation not found.', 'doublescale'), array( 'status' => 404 ) );
			}
			// get step from automation_contacts_processes
			$steps_ids = AutomationContactProcessesModel::where( 'automation_id', $automation_id )
				->orderBy( 'created_at' )
				->distinct()
				->pluck( 'step_id' )
				->all();

			// Get all steps for this automation
			$steps = empty( $steps_ids )
				? collect( array() )
				: AutomationStepModel::whereIn( 'id', $steps_ids )
					->where( 'status', '!=', 'deleted' )
					->get();

			// Base query for automation contacts
			$automation_contacts = AutomationContactModel::where( 'automation_id', $automation_id );

			// Get total contacts who entered the automation
			$total_contacts = $automation_contacts->count();

			// Calculate funnel data for each step
			$funnel_data = array();

			// Add entrance step
			$funnel_data[] = array(
				'label'      => __( 'Entrance', 'doublescale'),
				'value'      => $total_contacts,
				'percentage' => $total_contacts > 0 ? 100 : 0,
				'step_id'    => null,
				'step_type'  => 'entrance',
			);

			if ( $steps->isEmpty() ) {
				return new WP_REST_Response(
					array(
						'funnel_data'     => $funnel_data,
						'total_contacts'  => 0,
						'completion_rate' => 0,
					),
					200
				);
			}

			if ( $total_contacts === 0 ) {
				return new WP_REST_Response(
					array(
						'funnel_data'     => $funnel_data,
						'total_contacts'  => 0,
						'completion_rate' => 0,
					),
					200
				);
			}

			// Process each automation step
			foreach ( $steps as $step ) {
				$step_contacts = $this->count_step_contacts(
					$automation_id,
					$step->id,
					$automation_contacts->pluck( 'id' )->all()
				);

				if ( $step_contacts === 0 ) {
					continue;
				}
				$percentage    = $total_contacts > 0 ? round( ( $step_contacts / $total_contacts ) * 100, 1 ) : 0;

				$funnel_data[] = array(
					'label'      => $this->get_step_label( $step->action ),
					'value'      => $step_contacts,
					'percentage' => (int) ceil( $percentage ),
					'step_id'    => $step->id,
					'step_type'  => $step->type,
				);
			}

			// Calculate completion rate (contacts who completed all steps)
			$completed_contacts = AutomationContactModel::where( 'automation_id', $automation_id )
				->where( 'status', 'completed' );

			$completion_count = $completed_contacts->count();
			$completion_rate  = $total_contacts > 0 ? round( ( $completion_count / $total_contacts ) * 100, 1 ) : 0;

			return new WP_REST_Response(
				array(
					'funnel_data'     => $funnel_data,
					'total_contacts'  => $total_contacts,
					'completion_rate' => $completion_rate,
					'automation'      => array(
						'id'   => $automation->id,
						'name' => $automation->name,
					),
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get step report
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_steps_report( $request ) {
		try {
			$automation_id = $request->get_param( 'id' );
			// Verify automation exists
			$automation = AutomationModel::find( $automation_id );
			if ( ! $automation ) {
				return new WP_Error( 'not_found', __( 'Automation not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			// Get total contacts who entered the automation
			$automation_contacts = AutomationContactModel::where( 'automation_id', $automation_id );
			$total_contacts      = $automation_contacts->count();

			if ( $total_contacts === 0 ) {
				return new WP_REST_Response(
					array(
						'steps'          => array(),
						'total_contacts' => 0,
					),
					200
				);
			}

			// get step IDs from automation_contacts_processes
			$steps_ids = AutomationContactProcessesModel::where( 'automation_id', $automation_id )
				->orderBy( 'created_at' )
				->distinct()
				->pluck( 'step_id' )
				->all();

			// Get all steps for this automation
			$steps = empty( $steps_ids )
				? collect( array() )
				: AutomationStepModel::whereIn( 'id', $steps_ids )
					->where( 'status', '!=', 'deleted' )
					->get();

			$step_reports = array();

			// Add entrance step
			$step_reports[] = array(
				'stepName'          => __( 'Entrance', 'doublescale'),
				'contactsEntered'   => $total_contacts,
				'contactsCompleted' => $total_contacts,
				'completionRate'    => 100,
				'dropOffRate'       => 0,
				'stepId'            => null,
				'stepType'          => 'entrance',
			);

			// Process each automation step
			foreach ( $steps as $step ) {
				// Count contacts who entered this step
				$step_contacts_entered = $this->count_step_contacts(
					$automation_id,
					$step->id,
					$automation_contacts->pluck( 'id' )->all()
				);

				if ( $step_contacts_entered === 0 ) {
					continue;
				}

				// Count contacts who completed this step (for now, same as entered)
				// In future, you might want to add a 'completed' status check
				$step_contacts_completed = $step_contacts_entered;

				// Calculate completion rate relative to total contacts
				$completion_rate = $total_contacts > 0 ? round( ( $step_contacts_entered / $total_contacts ) * 100, 1 ) : 0;

				// Calculate drop-off rate from previous step
				$drop_off_rate = $total_contacts > 0 ? round( ( ( $total_contacts - $step_contacts_entered ) / $total_contacts ) * 100, 1 ) : 0;

				// Base step data
				$step_data = array(
					'stepName'          => $this->get_step_label( $step->action ),
					'contactsEntered'   => $step_contacts_entered,
					'contactsCompleted' => $step_contacts_completed,
					'completionRate'    => (int) ceil( $completion_rate ),
					'dropOffRate'       => (int) ceil( $drop_off_rate ),
					'stepId'            => $step->id,
					'stepType'          => $step->type,
				);

				// Add email tracking metrics for email action steps
				$email_actions = array( 'send_email', 'send_campaign_email', 'send_email_sequence' );
				if ( in_array( $step->action, $email_actions, true ) ) {
					$email_metrics             = $this->get_step_email_metrics( $step->id );
					$step_data['emailMetrics'] = $email_metrics;
				}

				$step_reports[] = $step_data;
			}

			// Calculate overall conversion rate: (last step completed / first step entered) * 100
			$first_step_entered  = $total_contacts;
			$last_step_completed = 0;

			// Find the last step that has completed contacts
			for ( $i = count( $step_reports ) - 1; $i >= 0; $i-- ) {
				if ( $step_reports[ $i ]['contactsCompleted'] > 0 ) {
					$last_step_completed = $step_reports[ $i ]['contactsCompleted'];
					break;
				}
			}

			$overall_conversion = $first_step_entered > 0 ? round( ( $last_step_completed / $first_step_entered ) * 100, 1 ) : 0;

			return new WP_REST_Response(
				array(
					'steps'              => $step_reports,
					'total_contacts'     => $total_contacts,
					'overall_conversion' => (int) ceil( $overall_conversion ),
					'automation'         => array(
						'id'   => $automation->id,
						'name' => $automation->name,
					),
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}


	/**
	 * Get email analytics for all email steps in an automation
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_email_analytics( $request ) {
		try {
			$automation_id = $request->get_param( 'id' );

			// Verify automation exists
			$automation = AutomationModel::find( $automation_id );
			if ( ! $automation ) {
				return new WP_Error( 'not_found', __( 'Automation not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			// Get all email steps for this automation
			$email_steps = AutomationStepModel::where( 'automation_id', $automation_id )
				->whereIn( 'action', array( 'send_email', 'send_campaign_email', 'send_email_sequence' ) )
				->where( 'status', '!=', 'deleted' )
				->orderBy( 'order', 'asc' )
				->get();

			$analytics = array();
			$totals    = array(
				'total'   => 0,
				'sent'    => 0,
				'failed'  => 0,
				'pending' => 0,
				'opened'  => 0,
				'clicked' => 0,
			);

			foreach ( $email_steps as $step ) {
				$metrics = $this->get_step_email_metrics( $step->id );

				// Add to totals
				$totals['total']   += $metrics['total'];
				$totals['sent']    += $metrics['sent'];
				$totals['failed']  += $metrics['failed'];
				$totals['pending'] += $metrics['pending'];
				$totals['opened']  += $metrics['opened'];
				$totals['clicked'] += $metrics['clicked'];

				$analytics[] = array(
					'stepId'    => $step->id,
					'stepName'  => $this->get_step_label( $step->action ),
					'stepOrder' => $step->order,
					'action'    => $step->action,
					'metrics'   => $metrics,
				);
			}

			// Calculate overall rates
			$overall_open_rate    = $totals['sent'] > 0 ? round( ( $totals['opened'] / $totals['sent'] ) * 100, 2 ) : 0;
			$overall_click_rate   = $totals['sent'] > 0 ? round( ( $totals['clicked'] / $totals['sent'] ) * 100, 2 ) : 0;
			$overall_failure_rate = $totals['total'] > 0 ? round( ( $totals['failed'] / $totals['total'] ) * 100, 2 ) : 0;

			return new WP_REST_Response(
				array(
					'automation'   => array(
						'id'   => $automation->id,
						'name' => $automation->name,
					),
					'steps'        => $analytics,
					'totals'       => $totals,
					'overallRates' => array(
						'openRate'    => $overall_open_rate,
						'clickRate'   => $overall_click_rate,
						'failureRate' => $overall_failure_rate,
					),
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get email analytics for a specific automation step
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_step_email_analytics( $request ) {
		try {
			$step_id = $request->get_param( 'step_id' );

			// Verify step exists and get step details
			$step = AutomationStepModel::find( $step_id );
			if ( ! $step ) {
				return new WP_Error( 'not_found', __( 'Automation step not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			// Verify this is an email step
			$email_actions = array( 'send_email', 'send_campaign_email', 'send_email_sequence' );
			if ( ! in_array( $step->action, $email_actions, true ) ) {
				return new WP_Error( 'invalid_step', __( 'This step does not send emails.', 'doublescale'), array( 'status' => 400 ) );
			}

			// Get step metrics
			$metrics = $this->get_step_email_metrics( $step_id );

			// Get step label for display
			$step_label = $this->get_step_label( $step->action );

			return new WP_REST_Response(
				array(
					'step'    => array(
						'id'     => $step->id,
						'name'   => $step_label,
						'action' => $step->action,
						'order'  => $step->order,
					),
					'metrics' => $metrics,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get email tracking metrics for a specific automation step
	 *
	 * @since 1.0.0
	 *
	 * @param int $step_id Step ID.
	 *
	 * @return array Email metrics (total, sent, failed, opened, clicked, rates)
	 */
	private function get_step_email_metrics( $step_id ) {
		global $wpdb;

		// Use raw SQL for better performance
		$table_name = $wpdb->prefix . 'doublescale_communication_tracking';

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix.
		$metrics = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
					COUNT(*) as total,
					SUM(CASE WHEN status = %d THEN 1 ELSE 0 END) as sent,
					SUM(CASE WHEN status = %d THEN 1 ELSE 0 END) as failed,
					SUM(CASE WHEN status = %d THEN 1 ELSE 0 END) as pending,
					SUM(CASE WHEN opened = 1 THEN 1 ELSE 0 END) as opened,
					SUM(CASE WHEN clicked = 1 THEN 1 ELSE 0 END) as clicked
				FROM {$table_name}
				WHERE step_id = %d
				AND mode = %d",
				TrackingStatus::SENT,
				TrackingStatus::FAILED,
				TrackingStatus::PENDING,
				$step_id,
				CommunicationTrackingModel::MODE_EMAIL
			),
			ARRAY_A
		);

		// Convert string values to integers
		$total   = (int) ( $metrics['total'] ?? 0 );
		$sent    = (int) ( $metrics['sent'] ?? 0 );
		$failed  = (int) ( $metrics['failed'] ?? 0 );
		$pending = (int) ( $metrics['pending'] ?? 0 );
		$opened  = (int) ( $metrics['opened'] ?? 0 );
		$clicked = (int) ( $metrics['clicked'] ?? 0 );

		// Calculate rates
		$open_rate    = $sent > 0 ? round( ( $opened / $sent ) * 100, 2 ) : 0;
		$click_rate   = $sent > 0 ? round( ( $clicked / $sent ) * 100, 2 ) : 0;
		$failure_rate = $total > 0 ? round( ( $failed / $total ) * 100, 2 ) : 0;

		return array(
			'total'       => $total,
			'sent'        => $sent,
			'failed'      => $failed,
			'pending'     => $pending,
			'opened'      => $opened,
			'clicked'     => $clicked,
			'openRate'    => $open_rate,
			'clickRate'   => $click_rate,
			'failureRate' => $failure_rate,
		);
	}

	/**
	 * Count distinct contacts who entered a step.
	 *
	 * @since 1.1.8
	 *
	 * @param int   $automation_id          Automation ID.
	 * @param int   $step_id                Step ID.
	 * @param int[] $automation_contact_ids Automation contact IDs to scope the count.
	 *
	 * @return int
	 */
	private function count_step_contacts( $automation_id, $step_id, $automation_contact_ids ) {
		if ( empty( $automation_contact_ids ) ) {
			return 0;
		}

		return (int) AutomationContactProcessesModel::where( 'automation_id', $automation_id )
			->where( 'step_id', $step_id )
			->whereIn( 'status', self::STEP_ENTERED_STATUSES )
			->whereIn( 'automation_contact_id', $automation_contact_ids )
			->distinct()
			->count( 'automation_contact_id' );
	}

	/**
	 * Get step label
	 *
	 * @since 1.0.0
	 *
	 * @param string $string Step action.
	 *
	 * @return string
	 */
	public function get_step_label( $string ) {
		return ucwords( str_replace( '_', ' ', $string ) );
	}


	/**
	 * Get items permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
