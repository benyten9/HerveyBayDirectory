<?php

/**
 * REST Api: General Controller
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Api
 */

namespace DoubleScale\Core\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\Scope\OwnerScope;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Automations\Models\AbandonedCartModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Contacts\Models\TagModel;
use DoubleScale\Modules\Contacts\Models\ListModel;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\Services\CurrencyResolver;

/**
 * RestGeneralController is REST api controller class for log
 *
 * @since 1.0.0
 */
class RestGeneralController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'general';

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/dashboard',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_dashboard' ),
					'permission_callback' => array( $this, 'get_dashboard_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Whether dashboard figures from a module may be read: the module is on
	 * and its tables exist ({@see doublescale_module_data_ready()}). A module
	 * that is off, or not installed (Pro modules on a free-only site), adds no
	 * figures; its cards are left out.
	 *
	 * @param string $slug Module slug.
	 */
	private function dashboard_aggregate_allowed( string $slug ): bool {
		return doublescale_module_data_ready( $slug );
	}

	/**
	 * Resolves DealModel FQCN when the Pro Deals module is installed.
	 *
	 * @return class-string|null
	 */
	private function resolve_deal_model_class(): ?string {
		if ( class_exists( '\DoubleScale\Pro\Modules\Deals\Models\DealModel' ) ) {
			return '\DoubleScale\Pro\Modules\Deals\Models\DealModel';
		}

		return null;
	}

	/**
	 * Resolves ProjectModel FQCN when the Pro Projects module is installed.
	 *
	 * @return class-string|null
	 */
	private function resolve_project_model_class(): ?string {
		if ( class_exists( '\DoubleScale\Pro\Modules\Projects\Models\ProjectModel' ) ) {
			return '\DoubleScale\Pro\Modules\Projects\Models\ProjectModel';
		}

		return null;
	}

	/**
	 * Get dashboard
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_dashboard( WP_REST_Request $request ) {
		// Null = not this person's to see; the dashboard leaves that card out.
		$total_contacts               = null;
		$total_tags                   = null;
		$total_lists                  = null;
		$recent_contacts              = null;
		$recent_unsubscribed_contacts = null;
		$new_contacts_30d             = null;
		$new_contacts_prev_30d        = null;

		// Trend windows: the last 30 days and the 30 before them.
		$now        = current_time( 'timestamp' );
		$since_30d  = gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS );
		$since_60d  = gmdate( 'Y-m-d H:i:s', $now - 60 * DAY_IN_SECONDS );

		// Every figure is what this person may see: an agent's dashboard counts
		// their own records, and only campaign managers see campaign and email totals.
		$user_id   = (int) get_current_user_id();
		$campaigns = Permissions::can_manage_campaigns( $user_id );
		// Contact figures for the CRM, sales and campaign roles. Helpdesk,
		// Booking, Project and Automation staff reach contacts through their
		// own work; their dashboard is their calendar and tasks.
		$crm_contacts = Permissions::has_agent_access( $user_id )
			|| Permissions::is_campaign_manager( $user_id )
			|| Permissions::is_campaign_agent( $user_id );

		if ( $crm_contacts && $this->dashboard_aggregate_allowed( 'contacts' ) ) {
			// A scoped agent's dashboard counts their own contacts, not the site's.
			$scope                        = OwnerScope::current();
			$total_contacts               = $scope->apply_contact_visibility( ContactModel::query() )->count();
			$total_tags                   = TagModel::count();
			$total_lists                  = ListModel::count();
			$recent_contacts              = $scope->apply_contact_visibility( ContactModel::query() )->orderBy( 'id', 'desc' )->limit( 5 )->get();
			$recent_unsubscribed_contacts = $scope->apply_contact_visibility( ContactModel::where( 'email_status', 'unsubscribed' ) )->orderBy( 'id', 'desc' )->limit( 5 )->get();
			$new_contacts_30d             = $scope->apply_contact_visibility( ContactModel::where( 'created_at', '>=', $since_30d ) )->count();
			$new_contacts_prev_30d        = $scope->apply_contact_visibility( ContactModel::where( 'created_at', '>=', $since_60d )->where( 'created_at', '<', $since_30d ) )->count();
		}

		$total_sent_emails     = $campaigns ? 0 : null;
		$total_email_templates = $campaigns ? 0 : null;
		$sent_emails_30d       = $campaigns ? 0 : null;
		$sent_emails_prev_30d  = $campaigns ? 0 : null;
		$recent_emails         = array();

		if ( $campaigns && $this->dashboard_aggregate_allowed( 'tracking' ) && class_exists( CommunicationTrackingModel::class ) ) {
			$total_sent_emails    = CommunicationTrackingModel::emails()->where( 'status', TrackingStatus::SENT )->count();
			$sent_emails_30d      = CommunicationTrackingModel::emails()->where( 'status', TrackingStatus::SENT )->where( 'created_at', '>=', $since_30d )->count();
			$sent_emails_prev_30d = CommunicationTrackingModel::emails()->where( 'status', TrackingStatus::SENT )->where( 'created_at', '>=', $since_60d )->where( 'created_at', '<', $since_30d )->count();
			$recent_emails     = CommunicationTrackingModel::emails()->with( 'template' )->orderBy( 'id', 'desc' )->limit( 5 )->get();
		}

		if (
			$campaigns
			&& $this->dashboard_aggregate_allowed( 'campaigns' )
			&& class_exists( '\DoubleScale\Modules\Campaigns\Models\TemplateModel' )
		) {
			$total_email_templates = \DoubleScale\Modules\Campaigns\Models\TemplateModel::where( 'type', CampaignChannel::CHANNEL_EMAIL )->count();
		}

		$top_campaigns = $campaigns ? array() : null;

		if (
			$campaigns
			&& $this->dashboard_aggregate_allowed( 'campaigns' )
			&& class_exists( '\DoubleScale\Modules\Campaigns\Models\CampaignModel' )
		) {
			$top_campaigns = \DoubleScale\Modules\Campaigns\Models\CampaignModel::orderBy( 'id', 'desc' )->limit( 5 )->get();
		}

		$total_automations = null;
		$top_automations   = null;

		if ( Permissions::can_access_automations( $user_id ) && $this->dashboard_aggregate_allowed( 'automations' ) && class_exists( AutomationModel::class ) ) {
			// An automation agent counts the workflows they built.
			$mine = static function () use ( $user_id ) {
				$query = AutomationModel::query();
				return Permissions::can_manage_all_automations( $user_id ) ? $query : $query->where( 'created_by', $user_id );
			};
			$total_automations = $mine()->where( 'status', 'active' )->count();
			$top_automations   = $mine()->orderBy( 'id', 'desc' )->limit( 5 )->get();
		}

		// The pipeline is the sales roles' (null for everyone else).
		$sells                         = Permissions::has_agent_access( $user_id );
		$deals                         = $sells ? 0 : null;
		$deals_closed_won              = $sells ? 0 : null;
		$deals_won_value               = $sells ? 0 : null;
		$deals_won_value_by_currency   = $sells ? array() : null;

		$deal_model = $this->resolve_deal_model_class();
		if (
			$sells
			&& $deal_model
			&& $this->dashboard_aggregate_allowed( 'deals' )
			&& (
				! function_exists( 'doublescale_is_module_storage_ready' )
				|| doublescale_is_module_storage_ready( 'deals', $deal_model )
			)
		) {
			try {
				// An agent counts the deals they own.
				$own   = ! Permissions::sees_all_records( 'deals', $user_id );
				$query = static function () use ( $deal_model, $own, $user_id ) {
					$q = $deal_model::query();
					return $own ? $q->where( 'owner_id', $user_id ) : $q;
				};
				$deals            = $query()->count();
				$deals_closed_won = $query()->where( 'status', 'won' )->count();
				$won              = $query()->where( 'status', 'won' )->get();
				$deals_won_value_by_currency = CurrencyResolver::sum_by_currency( $won, 'value' );
				$global                      = CurrencyResolver::global_currency();
				// @deprecated Keep the scalar as the global-currency bucket only
				// so mixed EUR+USD never become a fake total.
				$deals_won_value = $deals_won_value_by_currency[ $global ] ?? 0;
			} catch ( \Throwable $e ) {
				$deals                         = 0;
				$deals_closed_won              = 0;
				$deals_won_value               = 0;
				$deals_won_value_by_currency   = array();
			}
		}

		// Projects for the people who open them (null for everyone else).
		$works_projects = Permissions::has_project_access( $user_id );
		$projects       = $works_projects ? 0 : null;
		$project_model  = $this->resolve_project_model_class();
		if (
			$works_projects
			&& $project_model
			&& $this->dashboard_aggregate_allowed( 'projects' )
			&& (
				! function_exists( 'doublescale_is_module_storage_ready' )
				|| doublescale_is_module_storage_ready( 'projects', $project_model )
			)
		) {
			try {
				if ( Permissions::sees_all_records( 'projects', $user_id ) ) {
					$projects = $project_model::count();
				} elseif ( method_exists( $project_model, 'scopeInvolving' ) ) {
					// Pro with project teams: the projects they own or work on.
					$projects = $project_model::query()->involving( $user_id )->count();
				} else {
					$projects = $project_model::where( 'owner_id', $user_id )->count();
				}
			} catch ( \Throwable $e ) {
				$projects = 0;
			}
		}

		$response = array(
			'total_contacts'               => $total_contacts,
			'total_sent_emails'            => $total_sent_emails,
			'sent_emails_30d'              => $sent_emails_30d,
			'sent_emails_prev_30d'         => $sent_emails_prev_30d,
			'new_contacts_30d'             => $new_contacts_30d,
			'new_contacts_prev_30d'        => $new_contacts_prev_30d,
			'total_tags'                   => $total_tags,
			'total_lists'                  => $total_lists,
			'total_automations'            => $total_automations,
			'total_email_templates'        => $total_email_templates,
			'deals'                        => $deals,
			'deals_closed_won'             => $deals_closed_won,
			'deals_won_value'              => $deals_won_value,
			'deals_won_value_by_currency'  => $deals_won_value_by_currency,
			'projects'                     => $projects,
			'recent_contacts'              => $recent_contacts,
			'recent_unsubscribed_contacts' => $recent_unsubscribed_contacts,
			'top_campaigns'                => $top_campaigns,
			'top_automations'              => $top_automations,
			'recent_emails'                => $recent_emails,
		);

		if (
			doublescale_is_plugin_active( 'woocommerce' )
			&& $this->dashboard_aggregate_allowed( 'automations' )
			&& class_exists( AbandonedCartModel::class )
			// Cart recovery's table (Pro creates it while Automations is on).
			&& doublescale_table_exists( 'abandoned_carts' )
		) {
			$total_orders           = AbandonedCartModel::where( 'order_id', '>', 0 )->count();
			// WooCommerce carts are one store currency — summing `total` is safe.
			$total_revenue          = AbandonedCartModel::where( 'order_id', '>', 0 )->sum( 'total' );
			$recent_abandoned_carts = AbandonedCartModel::orderBy( 'id', 'desc' )->limit( 5 )->get();
			$recent_recoverd_carts  = AbandonedCartModel::where( 'status', 'recovered' )->orderBy( 'id', 'desc' )->limit( 5 )->get();

			$response['total_orders']           = $total_orders;
			$response['total_revenue']          = $total_revenue;
			$response['recent_abandoned_carts'] = $recent_abandoned_carts;
			$response['recent_recoverd_carts']  = $recent_recoverd_carts;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Get dashboard permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool|\WP_Error
	 */
	public function get_dashboard_permissions_check( WP_REST_Request $request ) {
		// Every team role has a dashboard (their calendar and tasks at least);
		// each figure in it is limited to what that person may see.
		$user_id = (int) get_current_user_id();
		return user_can( $user_id, 'manage_options' ) || Permissions::check_user_has_role( $user_id );
	}
}
