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
	 * Whether dashboard aggregates for a module slug may run.
	 * Unregistered slugs (e.g. Pro-only modules on free) return false.
	 *
	 * @param string $slug Module slug.
	 */
	private function dashboard_aggregate_allowed( string $slug ): bool {
		if ( ! function_exists( 'doublescale_module_slug_to_class_map' ) || ! function_exists( 'doublescale_is_module_active' ) ) {
			return true;
		}

		$classes = doublescale_module_slug_to_class_map();
		if ( ! isset( $classes[ $slug ] ) ) {
			return false;
		}

		return doublescale_is_module_active( $slug );
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
		$total_contacts               = 0;
		$total_tags                   = 0;
		$total_lists                  = 0;
		$recent_contacts              = array();
		$recent_unsubscribed_contacts = array();

		if ( $this->dashboard_aggregate_allowed( 'contacts' ) ) {
			$total_contacts               = ContactModel::count();
			$total_tags                   = TagModel::count();
			$total_lists                  = ListModel::count();
			$recent_contacts              = ContactModel::orderBy( 'id', 'desc' )->limit( 5 )->get();
			$recent_unsubscribed_contacts = ContactModel::where( 'email_status', 'unsubscribed' )->orderBy( 'id', 'desc' )->limit( 5 )->get();
		}

		$total_sent_emails     = 0;
		$total_email_templates = 0;
		$recent_emails         = array();

		if ( $this->dashboard_aggregate_allowed( 'tracking' ) && class_exists( CommunicationTrackingModel::class ) ) {
			$total_sent_emails = CommunicationTrackingModel::emails()->where( 'status', TrackingStatus::SENT )->count();
			$recent_emails     = CommunicationTrackingModel::emails()->with( 'template' )->orderBy( 'id', 'desc' )->limit( 5 )->get();
		}

		if (
			$this->dashboard_aggregate_allowed( 'campaigns' )
			&& class_exists( '\DoubleScale\Modules\Campaigns\Models\TemplateModel' )
		) {
			$total_email_templates = \DoubleScale\Modules\Campaigns\Models\TemplateModel::where( 'type', CampaignChannel::CHANNEL_EMAIL )->count();
		}

		$top_campaigns = array();

		if (
			$this->dashboard_aggregate_allowed( 'campaigns' )
			&& class_exists( '\DoubleScale\Modules\Campaigns\Models\CampaignModel' )
		) {
			$top_campaigns = \DoubleScale\Modules\Campaigns\Models\CampaignModel::orderBy( 'id', 'desc' )->limit( 5 )->get();
		}

		$total_automations = 0;
		$top_automations   = array();

		if ( $this->dashboard_aggregate_allowed( 'automations' ) && class_exists( AutomationModel::class ) ) {
			$total_automations = AutomationModel::where( 'status', 'active' )->count();
			$top_automations   = AutomationModel::orderBy( 'id', 'desc' )->limit( 5 )->get();
		}

		$deals            = 0;
		$deals_closed_won = 0;
		$deals_won_value  = 0;

		$deal_model = $this->resolve_deal_model_class();
		if (
			$deal_model
			&& $this->dashboard_aggregate_allowed( 'deals' )
			&& (
				! function_exists( 'doublescale_is_module_storage_ready' )
				|| doublescale_is_module_storage_ready( 'deals', $deal_model )
			)
		) {
			try {
				$deals            = $deal_model::count();
				$deals_closed_won = $deal_model::where( 'status', 'won' )->count();
				$deals_won_value  = (float) $deal_model::where( 'status', 'won' )->sum( 'value' );
			} catch ( \Throwable $e ) {
				$deals            = 0;
				$deals_closed_won = 0;
				$deals_won_value  = 0;
			}
		}

		$projects      = 0;
		$project_model = $this->resolve_project_model_class();
		if (
			$project_model
			&& $this->dashboard_aggregate_allowed( 'projects' )
			&& (
				! function_exists( 'doublescale_is_module_storage_ready' )
				|| doublescale_is_module_storage_ready( 'projects', $project_model )
			)
		) {
			try {
				$projects = $project_model::count();
			} catch ( \Throwable $e ) {
				$projects = 0;
			}
		}

		$response = array(
			'total_contacts'               => $total_contacts,
			'total_sent_emails'            => $total_sent_emails,
			'total_tags'                   => $total_tags,
			'total_lists'                  => $total_lists,
			'total_automations'            => $total_automations,
			'total_email_templates'        => $total_email_templates,
			'deals'                        => $deals,
			'deals_closed_won'             => $deals_closed_won,
			'deals_won_value'              => $deals_won_value,
			'projects'                     => $projects,
			'recent_contacts'              => $recent_contacts,
			'recent_unsubscribed_contacts' => $recent_unsubscribed_contacts,
			'top_campaigns'                => $top_campaigns,
			'top_automations'              => $top_automations,
			'recent_emails'                => $recent_emails,
		);

		if (
			doublescale_is_plugin_active( 'woocommerce/woocommerce.php' )
			&& $this->dashboard_aggregate_allowed( 'automations' )
			&& class_exists( AbandonedCartModel::class )
		) {
			$total_orders           = AbandonedCartModel::where( 'order_id', '>', 0 )->count();
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
		return Permissions::has_sales_rep_access();
	}
}
