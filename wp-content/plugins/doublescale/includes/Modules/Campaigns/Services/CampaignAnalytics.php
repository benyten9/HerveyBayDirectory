<?php
/**
 * Campaign Analytics Service
 * Handles analytics for all campaign types
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Utils\Utils;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Contacts\Models\ContactUnsubscribeModel;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Constants\CampaignChannel;

/**
 * CampaignAnalytics class
 */
class CampaignAnalytics {

	/**
	 * Class Instance.
	 *
	 * @since 1.0.0
	 *
	 * @var CampaignAnalytics
	 */
	private static $instance;

	/**
	 * CampaignAnalytics Instance.
	 *
	 * Instantiates or reuses an instance of CampaignAnalytics.
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @return self - Single instance
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get model query for campaign type
	 *
	 * @param string $type Campaign type ('email', 'sms', 'whatsapp') or integer constant
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	protected function get_model_query( $type ) {
		// Convert string to integer if needed (for backward compatibility)
		if ( is_string( $type ) ) {
			$type = CampaignChannel::ensure_integer( $type );
			if ( null === $type ) {
				throw new \InvalidArgumentException( 'Unsupported campaign type string' );
			}
		}

		switch ( $type ) {
			case CampaignChannel::CHANNEL_EMAIL:
			case CampaignChannel::CHANNEL_EMAIL_SEQUENCE:
			case CampaignChannel::CHANNEL_SEQUENCE_MAIL:
				return CommunicationTrackingModel::emails();
			case CampaignChannel::CHANNEL_SMS:
				return CommunicationTrackingModel::sms();
			case CampaignChannel::CHANNEL_WHATSAPP:
				return CommunicationTrackingModel::whatsapp();
			default:
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
				throw new \InvalidArgumentException( "Unsupported campaign type: {$type}" );
		}
	}

	/**
	 * Get analytics data for campaign type
	 * Optimized to use single GROUP BY query instead of N+1 queries
	 *
	 * IMPORTANT: This method filters by source_type = CAMPAIGN to ensure
	 * only campaign messages are included (not automations or individual messages).
	 *
	 * @param string $type Campaign type string ('email', 'sms', 'whatsapp')
	 * @param string $interval Analytics interval
	 * @param string $start_date Start date
	 * @param string $end_date End date
	 *
	 * @return array Analytics data with string channel key
	 */
	public function get_analytics( $type, $interval = 'last_30_days', $start_date = '', $end_date = '' ) {
		// Ensure we have a string type for the response key
		$channel_string = is_string( $type ) ? $type : CampaignChannel::to_string( $type );

		$query = $this->get_model_query( $type );

		// Filter to only include campaign messages (not automations or individual)
		$query->where( 'source_type', MessageSourceTypes::CAMPAIGN );

		if ( 'custom' !== $interval ) {
			$start_date = Utils::get_start_date( $interval, $start_date );
			$end_date   = Utils::get_end_date( $interval, $end_date );
		}

		$dates     = Utils::get_dates_between_dates( $start_date, $end_date );
		$date_type = $dates['type'] ?? 'hour';

		// Determine the MySQL date format based on interval type
		$date_formats = array(
			'hour'  => '%Y-%m-%d %H:00:00',
			'day'   => '%Y-%m-%d',
			'month' => '%Y-%m',
			'year'  => '%Y',
		);

		$format = $date_formats[ $date_type ] ?? $date_formats['day'];

		// Optimized: Single GROUP BY query instead of N queries
		$results = $query
			->selectRaw( "DATE_FORMAT(created_at, '{$format}') as period, COUNT(*) as count" )
			->whereBetween( 'created_at', array( $start_date . ' 00:00:00', $end_date . ' 23:59:59' ) )
			->groupBy( 'period' )
			->orderBy( 'period', 'ASC' )
			->get();

		// Convert results to associative array with date as key
		$data = array();
		foreach ( $results as $result ) {
			$data[ $result->period ] = (int) $result->count;
		}

		// Fill in missing dates with zero counts
		foreach ( $dates['dates'] as $date ) {
			$period_key = $this->format_date_for_period( $date, $date_type );
			if ( ! isset( $data[ $period_key ] ) ) {
				$data[ $period_key ] = 0;
			}
		}

		// Sort by date to ensure proper order
		ksort( $data );

		$totals = $this->get_total_stats( $type, $start_date, $end_date );

		// Return with string channel key for consistent Api response
		return array(
			$channel_string => $data,
			'data'          => $dates,
		) + $totals;
	}

	/**
	 * Format date string to match the period format used in GROUP BY query
	 *
	 * @param string $date Date string
	 * @param string $period_type Period type ('hour', 'day', 'month', 'year')
	 *
	 * @return string Formatted date string
	 */
	protected function format_date_for_period( $date, $period_type ) {
		$timestamp = strtotime( $date );

		switch ( $period_type ) {
			case 'hour':
				return gmdate( 'Y-m-d H:00:00', $timestamp );
			case 'day':
				return gmdate( 'Y-m-d', $timestamp );
			case 'month':
				return gmdate( 'Y-m', $timestamp );
			case 'year':
				return gmdate( 'Y', $timestamp );
			default:
				return gmdate( 'Y-m-d', $timestamp );
		}
	}

	/**
	 * Get total statistics for campaign type
	 * Optimized to use single query instead of multiple clones
	 *
	 * IMPORTANT: This method filters by source_type = CAMPAIGN to ensure
	 * only campaign messages are included (not automations or individual messages).
	 *
	 * @param string $type Campaign type ('email', 'sms')
	 * @param string $start_date Start date for filtering (optional)
	 * @param string $end_date End date for filtering (optional)
	 *
	 * @return array Total statistics
	 */
	public function get_total_stats( $type, $start_date = null, $end_date = null ) {
		// Create base query with optional date filtering
		$base_query = $this->get_model_query( $type );

		// Filter to only include campaign messages (not automations or individual)
		$base_query->where( 'source_type', MessageSourceTypes::CAMPAIGN );

		if ( $start_date && $end_date ) {
			$base_query->whereBetween( 'created_at', array( $start_date . ' 00:00:00', $end_date . ' 23:59:59' ) );
		}

		// Optimized: Single query with aggregate functions instead of multiple clones
		$result = $base_query->selectRaw( 'COUNT(*) as total, SUM(CASE WHEN status = ' . TrackingStatus::SENT . ' THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status = ' . TrackingStatus::FAILED . ' THEN 1 ELSE 0 END) as failed, SUM(CASE WHEN status = ' . TrackingStatus::PENDING . ' THEN 1 ELSE 0 END) as pending' )->first();

		$stats = array(
			'total'   => (int) $result->total,
			'sent'    => (int) $result->sent,
			'failed'  => (int) $result->failed,
			'pending' => (int) $result->pending,
		);

		return $this->add_type_specific_stats( $stats, $type, null, $start_date, $end_date );
	}

	/**
	 * Get campaign-specific statistics
	 * Optimized to use single query instead of multiple separate queries
	 *
	 * @param string $type Campaign type ('email', 'sms')
	 * @param int    $campaign_id Campaign ID (maps to source_id in tracking)
	 *
	 * @return array Campaign statistics
	 */
	public function get_campaign_stats( $type, $campaign_id ) {
		$query = $this->get_model_query( $type );

		// Optimized: Single query with aggregate functions
		$result = $query->where( 'source_id', $campaign_id )
			->where( 'source_type', MessageSourceTypes::CAMPAIGN )
			->selectRaw( 'COUNT(*) as total, SUM(CASE WHEN status = ' . TrackingStatus::SENT . ' THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status = ' . TrackingStatus::FAILED . ' THEN 1 ELSE 0 END) as failed, SUM(CASE WHEN status = ' . TrackingStatus::PENDING . ' THEN 1 ELSE 0 END) as pending' )
			->first();

		$stats = array(
			'total'   => (int) $result->total,
			'sent'    => (int) $result->sent,
			'failed'  => (int) $result->failed,
			'pending' => (int) $result->pending,
		);

		return $this->add_type_specific_stats( $stats, $type, $campaign_id );
	}

	/**
	 * Add type-specific statistics to base stats
	 *
	 * @param array  $stats Base statistics
	 * @param string $type Campaign type ('email', 'sms')
	 * @param int    $campaign_id Optional campaign ID for filtering (maps to source_id in tracking)
	 * @param string $start_date Optional start date for filtering
	 * @param string $end_date Optional end date for filtering
	 *
	 * @return array Enhanced statistics with type-specific data
	 */
	protected function add_type_specific_stats( $stats, $type, $campaign_id = null, $start_date = null, $end_date = null ) {
		global $wpdb;

		// Get table names for JOIN
		$contacts_table = $wpdb->prefix . 'doublescale_contacts';
		$tracking_table = $wpdb->prefix . 'doublescale_communication_tracking';

		// Build base query with all common filters
		$base_query = $this->get_model_query( $type );

		// ALWAYS filter by source_type to exclude automation and individual messages
		$base_query->where( $tracking_table . '.source_type', MessageSourceTypes::CAMPAIGN );

		if ( $campaign_id ) {
			$base_query->where( $tracking_table . '.source_id', $campaign_id );
		}

		if ( $start_date && $end_date ) {
			$base_query->whereBetween( $tracking_table . '.created_at', array( $start_date . ' 00:00:00', $end_date . ' 23:59:59' ) );
		}

		// Optimized: Single query per type with aggregate functions, including unsubscribe tracking
		// Convert string to integer for comparison (get_model_query already validated the type)
		$type_int = is_string( $type ) ? CampaignChannel::to_integer( $type ) : $type;

		if ( $type_int === CampaignChannel::CHANNEL_EMAIL || $type_int === CampaignChannel::CHANNEL_EMAIL_SEQUENCE || $type_int === CampaignChannel::CHANNEL_SEQUENCE_MAIL ) {
			$result = $base_query
				->leftJoin( $contacts_table . ' as contacts', $tracking_table . '.contact_id', '=', 'contacts.id' )
				->selectRaw(
					"
					SUM(CASE WHEN {$tracking_table}.opened = 1 AND {$tracking_table}.status = " . TrackingStatus::SENT . " THEN 1 ELSE 0 END) as total_opened,
					SUM(CASE WHEN {$tracking_table}.clicked = 1 AND {$tracking_table}.status = " . TrackingStatus::SENT . ' THEN 1 ELSE 0 END) as total_clicked
				'
				)
				->first();

			$stats['opened']  = (int) $result->total_opened;
			$stats['clicked'] = (int) $result->total_clicked;

			// Get unsubscribe count from dedicated table (use mode integer)
			$stats['unsubscribed'] = $this->get_unsubscribe_count( $campaign_id, $type_int );

			$stats = $this->calculate_email_rates( $stats );

		} elseif ( $type_int === CampaignChannel::CHANNEL_SMS ) {
			$result = $base_query
				->selectRaw(
					"
					SUM(CASE WHEN {$tracking_table}.clicked = 1 AND {$tracking_table}.status = " . TrackingStatus::SENT . " THEN 1 ELSE 0 END) as total_clicked,
					SUM(CASE WHEN {$tracking_table}.status = " . TrackingStatus::DELIVERED . ' THEN 1 ELSE 0 END) as delivered
				'
				)
				->first();

			$stats['clicked']   = (int) $result->total_clicked;
			$stats['delivered'] = (int) $result->delivered;

			// Get unsubscribe count from dedicated table (use mode integer)
			$stats['unsubscribed'] = $this->get_unsubscribe_count( $campaign_id, $type_int );

			$stats = $this->calculate_sms_rates( $stats );

		} elseif ( $type_int === CampaignChannel::CHANNEL_WHATSAPP ) {
			$result = $base_query
				->selectRaw(
					"
					SUM(CASE WHEN {$tracking_table}.clicked = 1 AND {$tracking_table}.status = " . TrackingStatus::SENT . " THEN 1 ELSE 0 END) as total_clicked,
					SUM(CASE WHEN {$tracking_table}.status = " . TrackingStatus::DELIVERED . ' THEN 1 ELSE 0 END) as delivered,
					SUM(CASE WHEN ' . $tracking_table . '.status = ' . TrackingStatus::READ . ' THEN 1 ELSE 0 END) as total_read
				'
				)
				->first();

			$stats['clicked']   = (int) $result->total_clicked;
			$stats['delivered'] = (int) $result->delivered;
			$stats['read']      = (int) $result->total_read;

			// Get unsubscribe count from dedicated table (use mode integer)
			$stats['unsubscribed'] = $this->get_unsubscribe_count( $campaign_id, $type_int );

			$stats = $this->calculate_whatsapp_rates( $stats );
		}

		return $stats;
	}

	/**
	 * Get unsubscribe count from dedicated contact_unsubscribes table
	 *
	 * @param int $campaign_id Campaign ID
	 * @param int $mode        Mode integer (1=Email, 2=Sms, 3=Whatsapp)
	 *
	 * @return int Unsubscribe count
	 */
	protected function get_unsubscribe_count( $campaign_id, $mode ) {
		if ( ! $campaign_id || ! $mode ) {
			return 0;
		}

		try {
			$count = ContactUnsubscribeModel::forCampaign( $campaign_id )
				->forMode( $mode )
				->count();

			return (int) $count;
		} catch ( \Exception $e ) {
			// Log error but return 0 instead of breaking
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Failed to get unsubscribe count',
					array(
						'campaign_id' => $campaign_id,
						'mode'        => $mode,
						'error'       => $e->getMessage(),
					)
				);
			}
			return 0;
		}
	}

	/**
	 * Calculate Email-specific rates
	 *
	 * @param array $stats Base statistics
	 *
	 * @return array Statistics with calculated rates
	 */
	protected function calculate_email_rates( $stats ) {
		// Calculate open rate (opened / sent)
		$stats['open_rate'] = $stats['sent'] > 0
			? round( ( $stats['opened'] / $stats['sent'] ) * 100, 2 )
			: 0;

		// Calculate click rate (clicked / sent)
		$stats['click_rate'] = $stats['sent'] > 0
			? round( ( $stats['clicked'] / $stats['sent'] ) * 100, 2 )
			: 0;

		return $stats;
	}

	/**
	 * Calculate Sms-specific rates
	 *
	 * @param array $stats Base statistics
	 *
	 * @return array Statistics with calculated rates
	 */
	protected function calculate_sms_rates( $stats ) {
		// Calculate delivery rate (delivered / sent)
		$stats['delivery_rate'] = $stats['sent'] > 0
			? round( ( $stats['delivered'] / $stats['sent'] ) * 100, 2 )
			: 0;

		// Calculate click rate (clicked / sent)
		$stats['click_rate'] = $stats['sent'] > 0
			? round( ( $stats['clicked'] / $stats['sent'] ) * 100, 2 )
			: 0;

		return $stats;
	}

	/**
	 * Calculate WhatsApp-specific rates
	 *
	 * @param array $stats Base statistics
	 *
	 * @return array Statistics with calculated rates
	 */
	protected function calculate_whatsapp_rates( $stats ) {
		// Calculate delivery rate (delivered / sent)
		$stats['delivery_rate'] = $stats['sent'] > 0
			? round( ( $stats['delivered'] / $stats['sent'] ) * 100, 2 )
			: 0;

		// Calculate read rate (read / delivered)
		$stats['read_rate'] = $stats['delivered'] > 0
			? round( ( $stats['read'] / $stats['delivered'] ) * 100, 2 )
			: 0;

		// Calculate click rate (clicked / sent)
		$stats['click_rate'] = $stats['sent'] > 0
			? round( ( $stats['clicked'] / $stats['sent'] ) * 100, 2 )
			: 0;

		return $stats;
	}

	/**
	 * Get time-series analytics for campaign
	 *
	 * @param int    $campaign_id Campaign ID (maps to source_id in tracking)
	 * @param string $type Campaign type ('email', 'sms')
	 * @param string $period Time period ('hour', 'day', 'week', 'month')
	 * @param int    $limit Number of periods to return
	 *
	 * @return array Time-series analytics data
	 */
	public function get_campaign_time_series( $campaign_id, $type, $period = 'day', $limit = 30 ) {
		$query = $this->get_model_query( $type );

		$date_formats = array(
			'hour'  => '%Y-%m-%d %H:00:00',
			'day'   => '%Y-%m-%d',
			'week'  => '%Y-%u',
			'month' => '%Y-%m',
		);

		$format = $date_formats[ $period ] ?? $date_formats['day'];

		$select_fields = "
            DATE_FORMAT(sent_at, '{$format}') as period,
            COUNT(*) as total_sent,
            SUM(CASE WHEN status = " . TrackingStatus::SENT . ' THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status = ' . TrackingStatus::FAILED . ' THEN 1 ELSE 0 END) as failed
        ';

		if ( $type === CampaignChannel::STR_EMAIL ) {
			$select_fields .= ',
                SUM(CASE WHEN opened = 1 AND status = ' . TrackingStatus::SENT . ' THEN 1 ELSE 0 END) as total_opened,
                SUM(CASE WHEN clicked = 1 AND status = ' . TrackingStatus::SENT . ' THEN 1 ELSE 0 END) as total_clicked
            ';
		} elseif ( $type === CampaignChannel::STR_SMS ) {
			$select_fields .= ',
                SUM(CASE WHEN clicked = 1 AND status = ' . TrackingStatus::SENT . ' THEN 1 ELSE 0 END) as total_clicked
            ';
		}

		$results = $query->selectRaw( $select_fields )
			->where( 'source_id', $campaign_id )
			->where( 'source_type', MessageSourceTypes::CAMPAIGN )
			->whereNotNull( 'sent_at' )
			->groupBy( 'period' )
			->orderBy( 'period', 'DESC' )
			->limit( $limit )
			->get();

		return $results->toArray();
	}

	/**
	 * Get field mapping for normalizing analytics response
	 *
	 * Maps source analytics keys to standardized response keys.
	 * This ensures consistent field naming across all Api responses.
	 *
	 * @since 1.0.0
	 *
	 * @return array Field mapping (source_key => response_key)
	 */
	public static function get_field_mapping() {
		return array(
			// Common count fields across all channels
			'sent'          => 'total_sent',
			'failed'        => 'total_failed',
			'pending'       => 'total_pending',

			// Email-specific count fields
			'opened'        => 'total_opened',
			'clicked'       => 'total_clicked',
			'unsubscribed'  => 'total_unsubscribed',

			// Email-specific rate fields
			'open_rate'     => 'open_rate',
			'click_rate'    => 'click_rate',

			// Sms/Whatsapp count fields
			'delivered'     => 'total_delivered',

			// Sms/Whatsapp rate fields
			'delivery_rate' => 'delivery_rate',

			// WhatsApp-specific count fields
			'read'          => 'total_read',

			// WhatsApp-specific rate fields
			'read_rate'     => 'read_rate',
		);
	}

	/**
	 * Normalize analytics response using standard field mapping
	 *
	 * Converts analytics data to use consistent field names (e.g., 'sent' => 'total_sent').
	 * Also includes channel-specific data and metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $analytics Raw analytics data from service (already has string keys)
	 * @param string $channel   Channel identifier (email, sms, whatsapp)
	 *
	 * @return array Normalized response with consistent field names
	 */
	public static function normalize_response( $analytics, $channel ) {
		$field_mapping = self::get_field_mapping();

		// Start with base structure
		// Analytics service now returns data with string keys directly
		$response = array(
			$channel => $analytics[ $channel ] ?? array(),
			'data'   => $analytics['data'] ?? array(),
			'total'  => $analytics['total'] ?? 0,
		);

		// Map analytics fields to response using the mapping
		foreach ( $field_mapping as $source_key => $response_key ) {
			if ( isset( $analytics[ $source_key ] ) ) {
				$response[ $response_key ] = $analytics[ $source_key ];
			}
		}

		return $response;
	}
}
