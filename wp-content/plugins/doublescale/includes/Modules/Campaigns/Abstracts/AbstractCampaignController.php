<?php

/**
 * Abstract Campaign Controller
 * Base class for all campaign REST controllers
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Abstracts;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Utils\Utils;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Modules\Campaigns\Services\CampaignAnalytics;
use DoubleScale\Modules\Campaigns\Services\CampaignStatusManager;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Modules\Campaigns\Services\TemplateFieldMapper;
use DoubleScale\Modules\Contacts\Models\ContactUnsubscribeModel;
use DoubleScale\Core\Constants\MessageSourceTypes;

/**
 * AbstractCampaignController class
 */
abstract class AbstractCampaignController extends RestController {

	/**
	 * Columns campaign lists may be sorted by.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const SORTABLE_COLUMNS = array( 'name', 'status', 'type', 'execute_at', 'created_at', 'updated_at' );

	/**
	 * Campaign REST requires the campaigns module (routes vanish when module is off unless shimmed).
	 *
	 * @return WP_Error|null
	 */
	protected function campaigns_module_guard(): ?WP_Error {
		return $this->require_module( 'campaigns' );
	}

	/**
	 * Campaign channel (email, sms, whatsapp)
	 *
	 * @var string
	 */
	protected $channel;

	/**
	 * Analytics service
	 *
	 * @var CampaignAnalytics
	 */
	protected $analytics;

	/**
	 * Enrichment service
	 *
	 * @var \DoubleScale\Modules\Campaigns\Services\CampaignEnrichment
	 */
	protected $enrichment;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->analytics  = CampaignAnalytics::instance();
		$this->enrichment = \DoubleScale\Modules\Campaigns\Services\CampaignEnrichment::instance();
	}

	/**
	 * Get campaign query - default implementation filters by channel if set
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	protected function get_campaign_query() {
		if ( $this->channel ) {
			// Convert string to integer for database query (accessors don't work in WHERE)
			$type_int = CampaignChannel::to_integer( $this->channel );
			return CampaignModel::query()->whereRaw( 'type = ?', array( $type_int ) );
		}
		return CampaignModel::query();
	}

	/**
	 * Get campaign message query - default implementation based on channel
	 *
	 * @param int $campaign_id
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	protected function get_campaign_message_query( $campaign_id ) {
		if ( ! $this->channel ) {
			return CommunicationTrackingModel::query()
				->where( 'source_type', \DoubleScale\Core\Constants\MessageSourceTypes::CAMPAIGN )
				->where( 'source_id', $campaign_id );
		}

		$mode_map = array(
			'email'    => CommunicationTrackingModel::MODE_EMAIL,
			'sms'      => CommunicationTrackingModel::MODE_SMS,
			'whatsapp' => CommunicationTrackingModel::MODE_WHATSAPP,
		);

		$mode = $mode_map[ $this->channel ] ?? CommunicationTrackingModel::MODE_EMAIL;

		return CommunicationTrackingModel::query()
			->where( 'mode', $mode )
			->where( 'source_type', \DoubleScale\Core\Constants\MessageSourceTypes::CAMPAIGN )
			->where( 'source_id', $campaign_id );
	}

	/**
	 * Register common routes
	 *
	 * @return void
	 */
	protected function register_common_routes() {
		// Main CRUD routes
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
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'The ids of the items to delete.', 'doublescale' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'required'    => true,
						),
					),
				),
			)
		);

		// Individual item routes
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the object.', 'doublescale' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);

		// Analytics route
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/analytics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_analytics' ),
					'permission_callback' => array( $this, 'get_analytics_permissions_check' ),
					'args'                => $this->get_analytics_params(),
				),
			)
		);

		// Campaign time-series analytics route
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/time-series',
			array(
				'args' => array(
					'id'     => array(
						'description' => __( 'Campaign ID', 'doublescale' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'period' => array(
						'description' => __( 'Time period for grouping', 'doublescale' ),
						'type'        => 'string',
						'enum'        => array( 'hour', 'day', 'week', 'month' ),
						'default'     => 'day',
					),
					'limit'  => array(
						'description' => __( 'Number of periods to return', 'doublescale' ),
						'type'        => 'integer',
						'default'     => 30,
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_campaign_time_series' ),
					'permission_callback' => array( $this, 'get_analytics_permissions_check' ),
				),
			)
		);

		// Duplicate route
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/duplicate',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the object.', 'doublescale' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'duplicate_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);

		// Campaign messages route - child controllers should register this with campaign-type-specific status enums
	}

	/**
	 * Get all campaigns
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		try {
			$keywords = $request->get_param( 'keywords' ) ?? null;
			$per_page = $request->get_param( 'per_page' ) ?? 10;
			$page     = $request->get_param( 'page' ) ?? 1;
			$from     = $request->get_param( 'from' ) ?? null;
			$to       = $request->get_param( 'to' ) ?? null;

			// New filter parameters
			$status        = $request->get_param( 'status' ) ?? null;
			$campaign_type = $request->get_param( 'campaign_type' ) ?? null;
			$created_from  = $request->get_param( 'created_from' ) ?? null;
			$created_to    = $request->get_param( 'created_to' ) ?? null;
			$updated_from  = $request->get_param( 'updated_from' ) ?? null;
			$updated_to    = $request->get_param( 'updated_to' ) ?? null;

			$query       = $this->get_campaign_query();
			$total_count = $query->count();

			// Apply filters
			if ( $keywords ) {
				$query->where( 'name', 'like', '%' . $keywords . '%' );
			}

			// Status filter
			if ( $status ) {
				$query->where( 'status', $status );
			}

			// Campaign type filter (standard or ab_test)
			if ( $campaign_type ) {
				if ( $campaign_type === 'standard' ) {
					// Standard campaigns have ab_test = false or null in settings
					$query->where(
						function ( $q ) {
							$q->whereRaw( "JSON_EXTRACT(settings, '$.ab_test') = false" )
							->orWhereRaw( "JSON_EXTRACT(settings, '$.ab_test') IS NULL" );
						}
					);
				} elseif ( $campaign_type === 'ab_test' ) {
					// A/B test campaigns have ab_test = true in settings
					$query->whereRaw( "JSON_EXTRACT(settings, '$.ab_test') = true" );
				}
			}

			// Backward compatibility: use from/to for created_at if provided
			if ( $from ) {
				$query->where( 'created_at', '>=', $from );
			}
			if ( $to ) {
				$query->where( 'created_at', '<=', $to );
			}

			// New created_at date range filters
			if ( $created_from ) {
				$query->where( 'created_at', '>=', $created_from );
			}
			if ( $created_to ) {
				$query->where( 'created_at', '<=', $created_to );
			}

			// Updated_at date range filters
			if ( $updated_from ) {
				$query->where( 'updated_at', '>=', $updated_from );
			}
			if ( $updated_to ) {
				$query->where( 'updated_at', '<=', $updated_to );
			}

			$this->apply_sorting( $query, $request, static::SORTABLE_COLUMNS );

			$campaigns = $query->paginate( $per_page, array( '*' ), 'page', $page );

			// Enrich all campaigns with computed stats (prevents N+1)
			$this->enrichment->enrich_collection( $campaigns->items() );

			return new WP_REST_Response(
				$campaigns->toArray() + array( 'total_count' => $total_count ),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get single campaign
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		try {
			$campaign_id = $request->get_param( 'id' );
			$campaign    = $this->get_campaign_query()->find( $campaign_id );

			if ( ! $campaign ) {
				/* translators: %s: campaign channel type (e.g. Email, Sms) */
				return new WP_Error( 'error', sprintf( __( '%s Campaign not found', 'doublescale' ), ucfirst( $this->channel ) ), array( 'status' => 404 ) );
			}

			// Attach full template data for frontend use
			$campaign->attach_templates( $campaign );

			// Enrich with computed counts and analytics
			$this->enrichment->enrich( $campaign );

			return new WP_REST_Response( $campaign, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create campaign
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function create_item( $request ) {
		try {
			$campaign_data = $this->prepare_campaign( $request );
			// Pass string directly - model's setTypeAttribute will convert to integer
			$campaign_data['type']       = $this->channel;
			$campaign_data['created_by'] = get_current_user_id() ?: null;
			$campaign                    = CampaignModel::create( $campaign_data );

			// Attach full template data for frontend use
			$campaign->attach_templates( $campaign );

			// Enrich with computed counts and analytics
			$this->enrichment->enrich( $campaign );

			return new WP_REST_Response( $campaign, 201 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update campaign
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function update_item( $request ) {
		try {
			$campaign_id = $request->get_param( 'id' );
			$campaign    = $this->get_campaign_query()->find( $campaign_id );

			if ( ! $campaign ) {
				/* translators: %s: campaign channel type (e.g. Email, Sms) */
				return new WP_Error( 'error', sprintf( __( '%s Campaign not found', 'doublescale' ), ucfirst( $this->channel ) ), array( 'status' => 404 ) );
			}

			$settings     = is_array( $campaign->settings ) ? $campaign->settings : array();
			$is_automated = ! empty( $settings['automated'] );

			// Automated campaigns can be updated while active; standard ones must be draft
			if ( ! $is_automated && $campaign->status !== 'draft' ) {
				return new WP_Error( 'error', __( 'Campaign is not draft', 'doublescale' ), array( 'status' => 400 ) );
			}

			if ( $is_automated && ! in_array( $campaign->status, array( 'draft', 'active' ), true ) ) {
				return new WP_Error( 'error', __( 'Automated campaign must be in draft or active state to update', 'doublescale' ), array( 'status' => 400 ) );
			}

			$campaign_data = $this->prepare_campaign( $request );

			// Recalculate count if filters were updated
			if ( isset( $campaign_data['settings']['filters'] ) ) {
				$filters                = $campaign_data['settings']['filters'] ?? array();
				$contact_filter         = \DoubleScale\Modules\Campaigns\Services\CampaignContactFilter::instance();
				$campaign_data['count'] = $contact_filter->get_contact_count( $this->channel, $filters );
			}

			// Note: is_attached / templates cleanup is handled unconditionally inside the
			// CampaignModel::saving hook — no need to set is_attached here.

			$campaign->update( $campaign_data );

			// Attach full template data for frontend use
			$campaign->attach_templates( $campaign );

			// Enrich with computed counts and analytics
			$this->enrichment->enrich( $campaign );

			return new WP_REST_Response( $campaign, 200 );
		} catch ( \Exception $e ) {
			$logger = doublescale_get_logger();
			$logger->error(
				'Campaign update error: ' . $e->getMessage(),
				array(
					'campaign_id' => $campaign_id,
					'trace'       => $e->getTraceAsString(),
				)
			);
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete campaign
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function delete_item( $request ) {
		try {
			$campaign_id = $request->get_param( 'id' );
			$campaign    = $this->get_campaign_query()->find( $campaign_id );

			if ( ! $campaign ) {
				/* translators: %s: campaign channel type (e.g. Email, Sms) */
				return new WP_Error( 'error', sprintf( __( '%s Campaign not found', 'doublescale' ), ucfirst( $this->channel ) ), array( 'status' => 404 ) );
			}

			$campaign->delete();

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete multiple campaigns
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function delete_items( $request ) {
		try {
			$campaign_ids = $request->get_param( 'ids' );
			$campaigns    = $this->get_campaign_query()->whereIn( 'id', $campaign_ids )->get();

			if ( $campaigns->isEmpty() ) {
				/* translators: %s: campaign channel type (e.g. Email, Sms) */
				return new WP_Error( 'error', sprintf( __( '%s Campaigns not found', 'doublescale' ), ucfirst( $this->channel ) ), array( 'status' => 404 ) );
			}

			CampaignModel::destroy( $campaign_ids );

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Duplicate campaign
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function duplicate_item( $request ) {
		try {
			$campaign_id = $request->get_param( 'id' );
			$campaign    = $this->get_campaign_query()->find( $campaign_id );

			if ( ! $campaign ) {
				/* translators: %s: campaign channel type (e.g. Email, Sms) */
				return new WP_Error( 'error', sprintf( __( '%s Campaign not found', 'doublescale' ), ucfirst( $this->channel ) ), array( 'status' => 404 ) );
			}

			$campaign_data = $campaign->toArray();
			unset( $campaign_data['id'], $campaign_data['created_at'], $campaign_data['updated_at'] );

			$campaign_data['created_by'] = get_current_user_id() ?: null;

			if ( isset( $campaign_data['settings']['templates'] ) && is_array( $campaign_data['settings']['templates'] ) ) {
				// Sms path: strip template_id to force creation of new TemplateModel records
				foreach ( $campaign_data['settings']['templates'] as $key => $template ) {
					unset( $campaign_data['settings']['templates'][ $key ]['template_id'] );
				}
			} elseif ( isset( $campaign_data['settings']['template_ids'] ) && is_array( $campaign_data['settings']['template_ids'] ) ) {
				// Email path: load TemplateModel records and convert so the saving hook can create new ones
				$loaded_templates = array();
				foreach ( $campaign_data['settings']['template_ids'] as $tid ) {
					$template = TemplateModel::find( $tid );
					if ( $template ) {
						$tpl_array = TemplateFieldMapper::template_to_array( $template, $campaign->type );
						unset( $tpl_array['template_id'] );
						$loaded_templates[] = $tpl_array;
					}
				}
				$campaign_data['settings']['templates'] = $loaded_templates;
			}

			unset( $campaign_data['settings']['template_ids'] );
			unset( $campaign_data['settings']['is_attached'] );

			$campaign_data['status'] = 'draft';
			$campaign_data['name']   = $campaign_data['name'] . ' - Copy';
			$new_campaign            = CampaignModel::create( $campaign_data );

			// Attach full template data for frontend use
			$new_campaign->attach_templates( $new_campaign );

			// Enrich with computed counts and analytics
			$this->enrichment->enrich( $new_campaign );

			return new WP_REST_Response( $new_campaign, 201 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get campaign messages - unified implementation for all campaign types
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function get_campaign_messages( $request ) {
		try {
			$campaign_id = $request->get_param( 'id' );
			$per_page    = $request->get_param( 'per_page' ) ?: 10;
			$page        = $request->get_param( 'page' ) ?: 1;
			$status      = $request->get_param( 'status' ) ?: '';

			$query = $this->get_campaign_message_query( $campaign_id );

			// Apply status filter if provided
			if ( ! empty( $status ) && $status !== 'all' ) {
				$this->apply_message_status_filter( $query, $status );
			}

			$campaign_messages = $query->with( 'contact', 'template' )
				->paginate( $per_page, array( '*' ), 'page', $page );

			return new WP_REST_Response( $campaign_messages, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get campaign execution runs for automated campaigns.
	 *
	 * Groups messages by execution batch using minute-level precision on sent_at.
	 * Each automated campaign execution sends all emails within seconds, so grouping
	 * by minute accurately identifies distinct runs even when multiple executions
	 * occur on the same day.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_campaign_runs( $request ) {
		try {
			$campaign_id = $request->get_param( 'id' );
			$per_page    = $request->get_param( 'per_page' ) ?: 10;
			$page        = $request->get_param( 'page' ) ?: 1;

			$campaign = CampaignModel::find( $campaign_id );
			if ( ! $campaign ) {
				return new WP_Error( 'not_found', __( 'Campaign not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$table = ( new CommunicationTrackingModel() )->getTable();

			// Count distinct execution batches (minute-level granularity)
			$count_query  = $this->get_campaign_message_query( $campaign_id );
			$count_result = $count_query->selectRaw( "COUNT(DISTINCT DATE_FORMAT({$table}.sent_at, '%Y-%m-%d %H:%i')) as cnt" )->first();
			$total_runs   = $count_result ? (int) $count_result->cnt : 0;

			// Group by minute to identify distinct execution batches
			$base_query = $this->get_campaign_message_query( $campaign_id );
			$runs       = $base_query->selectRaw( "DATE_FORMAT({$table}.sent_at, '%Y-%m-%d %H:%i') as run_batch" )
				->selectRaw( "MIN({$table}.sent_at) as run_start" )
				->selectRaw( "MAX({$table}.sent_at) as run_end" )
				->selectRaw( 'COUNT(*) as total' )
				->selectRaw( "SUM(CASE WHEN {$table}.status = " . TrackingStatus::SENT . " OR {$table}.status = " . TrackingStatus::DELIVERED . ' THEN 1 ELSE 0 END) as sent' )
				->selectRaw( "SUM(CASE WHEN {$table}.status = " . TrackingStatus::FAILED . ' THEN 1 ELSE 0 END) as failed' )
				->selectRaw( "SUM(CASE WHEN {$table}.opened = 1 THEN 1 ELSE 0 END) as opened" )
				->selectRaw( "SUM(CASE WHEN {$table}.clicked = 1 THEN 1 ELSE 0 END) as clicked" )
				->groupBy( 'run_batch' )
				->orderBy( 'run_batch', 'DESC' )
				->offset( ( $page - 1 ) * $per_page )
				->limit( $per_page )
				->get();

			$runs_data = array();
			foreach ( $runs as $run ) {
				$batch_contact_ids = $this->get_campaign_message_query( $campaign_id )
					->whereRaw( "DATE_FORMAT({$table}.sent_at, '%Y-%m-%d %H:%i') = ?", array( $run->run_batch ) )
					->pluck( 'contact_id' )
					->toArray();

				$unsub_table  = ( new ContactUnsubscribeModel() )->getTable();
				$unsubscribed = ContactUnsubscribeModel::forCampaign( $campaign_id )
					->whereIn( "{$unsub_table}.contact_id", $batch_contact_ids )
					->count();

				$runs_data[] = array(
					'run_batch'    => $run->run_batch,
					'run_start'    => $run->run_start,
					'run_end'      => $run->run_end,
					'total'        => (int) $run->total,
					'sent'         => (int) $run->sent,
					'failed'       => (int) $run->failed,
					'opened'       => (int) $run->opened,
					'clicked'      => (int) $run->clicked,
					'unsubscribed' => (int) $unsubscribed,
				);
			}

			return new WP_REST_Response(
				array(
					'data'  => $runs_data,
					'total' => $total_runs,
					'page'  => (int) $page,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get campaign messages for a specific execution run batch
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_campaign_run_messages( $request ) {
		try {
			$campaign_id = $request->get_param( 'id' );
			$run_batch   = $request->get_param( 'run_batch' );
			$per_page    = $request->get_param( 'per_page' ) ?: 10;
			$page        = $request->get_param( 'page' ) ?: 1;
			$status      = $request->get_param( 'status' ) ?: '';

			if ( empty( $run_batch ) ) {
				return new WP_Error( 'missing_param', __( 'run_batch parameter is required', 'doublescale' ), array( 'status' => 400 ) );
			}

			$query = $this->get_campaign_message_query( $campaign_id );
			$table = ( new CommunicationTrackingModel() )->getTable();

			// Match messages within the same minute as the batch
			$query->whereRaw( "DATE_FORMAT({$table}.sent_at, '%Y-%m-%d %H:%i') = ?", array( $run_batch ) );

			if ( ! empty( $status ) && $status !== 'all' ) {
				$this->apply_message_status_filter( $query, $status );
			}

			$messages = $query->with( 'contact', 'template' )
				->paginate( $per_page, array( '*' ), 'page', $page );

			return new WP_REST_Response( $messages, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Apply status filter to message query
	 * Can be overridden by child classes for type-specific filtering
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @param string                                $status
	 * @return void
	 */
	protected function apply_message_status_filter( $query, $status ) {
		// Common status filters across all campaign types
		switch ( $status ) {
			case 'failed':
				$query->where( 'status', TrackingStatus::FAILED );
				break;
			case 'sent':
				$query->where( 'status', TrackingStatus::SENT );
				break;
			case 'pending':
				$query->where( 'status', TrackingStatus::PENDING );
				break;
			case 'delivered':
				$query->where( 'status', TrackingStatus::DELIVERED );
				break;
			case 'opened':
				// Email-specific: check opened column
				$query->where( 'opened', 1 );
				break;
			case 'clicked':
				// Email-specific: check clicked column
				$query->where( 'clicked', 1 );
				break;
			case 'scheduled':
				// Scheduled messages
				$query->where( 'status', TrackingStatus::SCHEDULED );
				break;
		}
	}

	/**
	 * Get analytics
	 * Cached for 5 minutes to improve performance
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function get_analytics( $request ) {
		try {
			$interval   = $request->get_param( 'interval' ) ?: 'last_30_days';
			$start_date = $request->get_param( 'start_date' ) ?: '';
			$end_date   = $request->get_param( 'end_date' ) ?: '';

			// Allow channel to be set via request parameter (for unified endpoint)
			$channel = $request->get_param( 'channel' );
			if ( ! empty( $channel ) ) {
				// Validate it's a valid channel string
				$valid_channels = CampaignChannel::get_core_channel_strings();
				if ( in_array( $channel, $valid_channels, true ) ) {
					$this->channel = $channel;
				}
			}

			// Ensure channel is set
			if ( empty( $this->channel ) ) {
				return new WP_Error(
					'missing_channel',
					__( 'Channel parameter is required', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			// Generate cache key
			$cache_key = sprintf(
				'doublescale_analytics_%s_%s_%s_%s',
				$this->channel,
				$interval,
				$start_date,
				$end_date
			);

			// Try to get cached result
			$analytics = get_transient( $cache_key );

			if ( false === $analytics ) {
				// Cache miss - fetch fresh data
				// Pass string channel directly to analytics service
				$analytics = $this->analytics->get_analytics( $this->channel, $interval, $start_date, $end_date );

				// Cache for 5 minutes
				set_transient( $cache_key, $analytics, 5 * MINUTE_IN_SECONDS );
			}

			// Normalize analytics response using centralized method
			$response = CampaignAnalytics::normalize_response( $analytics, $this->channel );

			return new WP_REST_Response( $response, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get campaign time-series analytics
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_campaign_time_series( $request ) {
		try {
			$campaign_id = (int) $request->get_param( 'id' );
			$period      = $request->get_param( 'period' ) ?: 'day';
			$limit       = (int) ( $request->get_param( 'limit' ) ?: 30 );

			// Validate campaign exists and matches type
			$campaign = $this->get_campaign_query()->find( $campaign_id );
			if ( ! $campaign ) {
				return new WP_Error( 'not_found', __( 'Campaign not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			// Derive channel from campaign when using the unified controller
			// (individual-type controllers already have $this->channel set; the unified one does not)
			if ( empty( $this->channel ) ) {
				$this->channel = $campaign->type;
			}

			// Generate cache key
			$cache_key = sprintf(
				'doublescale_timeseries_%s_%d_%s_%d',
				$this->channel,
				$campaign_id,
				$period,
				$limit
			);

			// Try to get cached result
			$time_series = get_transient( $cache_key );

			if ( false === $time_series ) {
				// Cache miss - fetch fresh data
				$time_series = $this->analytics->get_campaign_time_series(
					$campaign_id,
					$this->channel,
					$period,
					$limit
				);

				// Cache for 5 minutes
				set_transient( $cache_key, $time_series, 5 * MINUTE_IN_SECONDS );
			}

			return new WP_REST_Response( $time_series, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Prepare campaign data
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array
	 */
	protected function prepare_campaign( $request ) {
		$campaign_data = array(
			'name'        => $request->get_param( 'name' ),
			'description' => $request->get_param( 'description' ),
			'status'      => $request->get_param( 'status' ),
			'settings'    => $request->get_param( 'settings' ),
			'count'       => $request->get_param( 'count' ),
			'execute_at'  => $request->get_param( 'execute_at' ),
		);

		foreach ( $campaign_data as $key => $value ) {
			if ( empty( $value ) && $value !== 0 ) {
				unset( $campaign_data[ $key ] );
			}
		}

		return $campaign_data;
	}

	/**
	 * Get collection parameters
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'keyword'  => array(
				'description' => __( 'The keyword to search for.', 'doublescale' ),
				'type'        => 'string',
			),
			'per_page' => array(
				'description' => __( 'The number of items to return per page.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 10,
			),
			'page'     => array(
				'description' => __( 'The page number.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 1,
			),
		);
	}

	/**
	 * Get analytics parameters
	 *
	 * @return array
	 */
	public function get_analytics_params() {
		return array(
			'channel'    => array(
				'description' => __( 'Campaign channel (email, sms, whatsapp).', 'doublescale' ),
				'type'        => 'string',
				'enum'        => CampaignChannel::get_core_channel_strings(),
				'required'    => false,
			),
			'interval'   => array(
				'description' => __( 'Interval for the analytics.', 'doublescale' ),
				'type'        => 'string',
				'enum'        => array( 'custom', 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_year', 'last_year' ),
				'required'    => false,
			),
			'start_date' => array(
				'description' => __( 'Start date for the analytics.', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),
			'end_date'   => array(
				'description' => __( 'End date for the analytics.', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),
		) + $this->get_sorting_collection_params( static::SORTABLE_COLUMNS );
	}

	/**
	 * Process merge tags in message content
	 * Common merge tag processing for all campaign types (Email, Sms, WhatsApp)
	 *
	 * @param string            $message Message content
	 * @param ContactModel|null $contact Contact for merge tags (can be null)
	 * @return string Processed message
	 */
	protected function process_merge_tags( $message, $contact ) {
		return \DoubleScale\Core\MergeTags\MergeTagsManager::instance()->process_merge_tags( $message, $contact );
	}

	/**
	 * Validate campaign status
	 *
	 * @param string          $status
	 * @param WP_REST_Request $request
	 * @param string          $param
	 *
	 * @return bool|WP_Error
	 */
	public function validate_campaign_status( $status, $request, $param ) {
		$status_manager = CampaignStatusManager::instance();

		if ( ! $status_manager->is_valid_status( $status ) ) {
			return new WP_Error(
				'invalid_campaign_status',
				/* translators: %s: invalid campaign status value */
				sprintf( __( 'Invalid campaign status: %s', 'doublescale' ), $status ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Permission checks - all return the same for campaigns
	 */
	public function get_items_permissions_check( $request ) {
		$mod = $this->campaigns_module_guard();
		if ( $mod instanceof WP_Error ) {
			return $mod;
		}
		return Permissions::has_crm_manager_access();
	}
	public function get_item_permissions_check( $request ) {
		$mod = $this->campaigns_module_guard();
		if ( $mod instanceof WP_Error ) {
			return $mod;
		}
		return Permissions::has_crm_manager_access();
	}
	public function create_item_permissions_check( $request ) {
		$mod = $this->campaigns_module_guard();
		if ( $mod instanceof WP_Error ) {
			return $mod;
		}
		return Permissions::has_crm_manager_access();
	}
	public function update_item_permissions_check( $request ) {
		$mod = $this->campaigns_module_guard();
		if ( $mod instanceof WP_Error ) {
			return $mod;
		}
		return Permissions::has_crm_manager_access();
	}
	public function delete_item_permissions_check( $request ) {
		$mod = $this->campaigns_module_guard();
		if ( $mod instanceof WP_Error ) {
			return $mod;
		}
		return Permissions::has_crm_manager_access();
	}
	public function delete_items_permissions_check( $request ) {
		$mod = $this->campaigns_module_guard();
		if ( $mod instanceof WP_Error ) {
			return $mod;
		}
		return Permissions::has_crm_manager_access();
	}
	public function get_analytics_permissions_check( $request ) {
		$mod = $this->campaigns_module_guard();
		if ( $mod instanceof WP_Error ) {
			return $mod;
		}
		return Permissions::has_crm_manager_access();
	}
}
