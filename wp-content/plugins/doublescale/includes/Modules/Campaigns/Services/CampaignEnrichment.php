<?php
/**
 * Campaign Enrichment Service
 * Handles data enrichment for campaigns with computed counts and analytics
 * Prevents N+1 queries and eliminates need for unset() calls
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Filters\Process as Contact_Filters_Process;
use DoubleScale\Modules\Campaigns\Services\CampaignAnalytics;
use DoubleScale\Core\Constants\CampaignChannel;

/**
 * CampaignEnrichment class
 *
 * Responsibilities:
 * - Compute campaign statistics (contacts count, templates count, analytics)
 * - Prevent N+1 queries using optimized queries
 * - Provide reusable methods for controllers
 * - Keep computed data separate from model persistence layer
 */
class CampaignEnrichment {

	/**
	 * Class Instance.
	 *
	 * @var CampaignEnrichment
	 */
	private static $instance;

	/**
	 * Analytics service instance
	 *
	 * @var CampaignAnalytics
	 */
	private $analytics;

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->analytics = CampaignAnalytics::instance();
	}

	/**
	 * Get singleton instance
	 *
	 * @return CampaignEnrichment
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Enrich a single campaign with all computed data
	 * This is the main method controllers should call
	 * Optimized to prevent N+1 queries
	 *
	 * @param CampaignModel $campaign Campaign model instance
	 *
	 * @return CampaignModel Returns same instance with enriched data
	 */
	public function enrich( CampaignModel $campaign ) {
		// Skip if campaign type is not set
		if ( empty( $campaign->type ) ) {
			return $campaign;
		}

		// Compute and set all statistics
		$stats = $this->compute_all_stats( $campaign );

		// Set as non-persistent attributes (won't be saved to database)
		foreach ( $stats as $key => $value ) {
			$campaign->setAttribute( $key, $value );
		}

		return $campaign;
	}

	/**
	 * Compute all statistics for a campaign in one method
	 * Returns array of computed values without modifying the model
	 *
	 * @param CampaignModel $campaign Campaign model
	 *
	 * @return array Associative array of computed statistics
	 */
	public function compute_all_stats( CampaignModel $campaign ) {
		$stats = array();

		// Compute contacts count
		$stats['contacts_count'] = $this->compute_contacts_count( $campaign );

		// Compute template counts (optimized single query)
		$stats['templates_count'] = $this->compute_template_counts( $campaign );

		// Get analytics stats from service
		$analytics_stats = $this->analytics->get_campaign_stats( $campaign->type, $campaign->id );

		// Merge analytics stats into result
		$stats = array_merge( $stats, $this->normalize_analytics_stats( $campaign, $analytics_stats ) );

		return $stats;
	}

	/**
	 * Compute contacts count for campaign
	 * Uses optimized query with proper filtering by channel status
	 *
	 * @param CampaignModel $campaign Campaign model
	 *
	 * @return int Contact count
	 */
	public function compute_contacts_count( CampaignModel $campaign ) {
		$filters = $campaign->get_setting( 'filters', array() );
		$query   = ContactModel::query();

		// Apply type-specific filtering with channel-specific status
		if ( $campaign->is_email_campaign() || $campaign->is_email_sequence() || $campaign->is_sequence_mail() ) {
			$query->where( 'email_status', 'subscribed' )
				->whereNotNull( 'email' )
				->where( 'email', '!=', '' );
		} elseif ( $campaign->is_sms_campaign() ) {
			$query->where( 'sms_status', 'subscribed' )
				->whereNotNull( 'phone' )
				->where( 'phone', '!=', '' );
		} elseif ( $campaign->is_whatsapp_campaign() ) {
			$query->where( 'whatsapp_status', 'subscribed' )
				->whereNotNull( 'whatsapp_phone' )
				->where( 'whatsapp_phone', '!=', '' );
		}

		// Apply custom filters if provided
		if ( ! empty( $filters ) ) {
			$contact_filters = new Contact_Filters_Process( $query, $filters );
			$query           = $contact_filters->filter();

			if ( ! $query ) {
				doublescale_get_logger()->error(
					'Contact filters returned null query',
					array(
						'campaign_id' => $campaign->id,
						'filters'     => $filters,
						'context'     => 'campaign_enrichment_compute_contacts_count',
					)
				);
				return 0;
			}
		}

		return $query->count();
	}

	/**
	 * Compute template counts using optimized GROUP BY query
	 * Prevents N+1 query problem (single query instead of N queries)
	 *
	 * @param CampaignModel $campaign Campaign model
	 *
	 * @return array Template counts indexed by template_id
	 */
	public function compute_template_counts( CampaignModel $campaign ) {
		$mode = CampaignChannel::to_mode( $campaign->type );
		if ( $mode === null ) {
			return array();
		}

		// Single query with GROUP BY instead of N separate queries
		$counts = $campaign->messages()
			->where( 'mode', $mode )
			->selectRaw( 'template_id, COUNT(*) as count' )
			->groupBy( 'template_id' )
			->get()
			->pluck( 'count', 'template_id' )
			->toArray();

		// Ensure all template IDs have a count (even if 0)
		$template_counts = array();
		foreach ( $campaign->get_template_ids() as $template_id ) {
			$template_counts[ $template_id ] = isset( $counts[ $template_id ] ) ? (int) $counts[ $template_id ] : 0;
		}

		return $template_counts;
	}

	/**
	 * Normalize analytics stats based on campaign type
	 * Returns array of stats to be set as campaign attributes
	 *
	 * @param CampaignModel $campaign Campaign model
	 * @param array         $analytics_stats Raw analytics stats
	 *
	 * @return array Normalized stats
	 */
	private function normalize_analytics_stats( CampaignModel $campaign, $analytics_stats ) {
		$stats = array();

		// Common stats for all campaign types
		$stats['sent_count']         = $analytics_stats['sent'] ?? 0;
		$stats['failed_count']       = $analytics_stats['failed'] ?? 0;
		$stats['clicked_count']      = $analytics_stats['clicked'] ?? 0;
		$stats['unsubscribed_count'] = $analytics_stats['unsubscribed'] ?? 0;

		// Type-specific stats
		if ( $campaign->is_email_campaign() ) {
			$stats['opened_count'] = $analytics_stats['opened'] ?? 0;
			$stats['open_rate']    = $analytics_stats['open_rate'] ?? 0;
			$stats['click_rate']   = $analytics_stats['click_rate'] ?? 0;
		} elseif ( $campaign->is_sms_campaign() ) {
			$stats['pending_count']   = $analytics_stats['pending'] ?? 0;
			$stats['delivered_count'] = $analytics_stats['delivered'] ?? 0;
			$stats['delivery_rate']   = $analytics_stats['delivery_rate'] ?? 0;
			$stats['click_rate']      = $analytics_stats['click_rate'] ?? 0;
		} elseif ( $campaign->is_whatsapp_campaign() ) {
			$stats['pending_count']   = $analytics_stats['pending'] ?? 0;
			$stats['delivered_count'] = $analytics_stats['delivered'] ?? 0;
			$stats['read_count']      = $analytics_stats['read'] ?? 0;
			$stats['delivery_rate']   = $analytics_stats['delivery_rate'] ?? 0;
			$stats['read_rate']       = $analytics_stats['read_rate'] ?? 0;
			$stats['click_rate']      = $analytics_stats['click_rate'] ?? 0;
		}

		return $stats;
	}

	/**
	 * Enrich multiple campaigns efficiently
	 * Future optimization: batch queries could be added here
	 *
	 * @param array $campaigns Array of CampaignModel instances
	 *
	 * @return array Same campaigns array with enriched data
	 */
	public function enrich_collection( $campaigns ) {
		if ( empty( $campaigns ) ) {
			return $campaigns;
		}

		foreach ( $campaigns as $campaign ) {
			$this->enrich( $campaign );
		}

		return $campaigns;
	}
}
