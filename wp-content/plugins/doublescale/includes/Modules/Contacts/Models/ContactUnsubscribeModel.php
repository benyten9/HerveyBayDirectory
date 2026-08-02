<?php
/**
 * Class ContactUnsubscribeModel
 * Model for tracking contact unsubscribe events
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Core\Constants\MessageSourceTypes;

/**
 * ContactUnsubscribeModel class
 *
 * Tracks contact unsubscribe events with source attribution
 * Uses integer constants matching CommunicationTrackingModel
 */
class ContactUnsubscribeModel extends Model {

	/**
	 * Mode constants (matching CommunicationTrackingModel)
	 */
	const MODE_EMAIL    = 1;
	const MODE_SMS      = 2;
	const MODE_WHATSAPP = 3;

	/**
	 * Source type constants (matching MessageSourceTypes)
	 * Note: Only CAMPAIGN and AUTOMATION - Individual messages don't have unsubscribe links
	 */
	const SOURCE_CAMPAIGN   = MessageSourceTypes::CAMPAIGN;   // 1
	const SOURCE_AUTOMATION = MessageSourceTypes::AUTOMATION; // 2

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_contact_unsubscribes';

	/**
	 * Primary key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $primary_key = 'id';

	/**
	 * Fillable columns
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $fillable = array(
		'contact_id',
		'mode',
		'reason',
		'source_type',
		'source_id',
		'created_at',
	);

	/**
	 * Timestamps - only created_at, no updated_at
	 *
	 * @var bool
	 *
	 * @since 1.0.0
	 */
	public $timestamps = false;

	/**
	 * Rules
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $rules = array(
		'contact_id' => 'required|integer',
		'mode'       => 'required|integer|in:1,2,3',
	);

	/**
	 * Messages
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $messages = array(
		'contact_id.required' => 'Contact ID is required.',
		'mode.required'       => 'Mode is required.',
		'mode.integer'        => 'Mode must be an integer.',
		'mode.in'             => 'Invalid mode type. Must be 1 (Email), 2 (Sms), or 3 (Whatsapp).',
	);

	/**
	 * Get the contact
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( ContactModel::class, 'contact_id', 'id' );
	}

	/**
	 * Scope: Filter by mode
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @param string                                $mode
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForMode( $query, $mode ) {
		return $query->where( 'mode', $mode );
	}

	/**
	 * Scope: Filter by source type
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @param string                                $source_type
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeBySourceType( $query, $source_type ) {
		return $query->where( 'source_type', $source_type );
	}

	/**
	 * Scope: Filter by source (type and id)
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @param string                                $source_type
	 * @param int                                   $source_id
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeBySource( $query, $source_type, $source_id ) {
		return $query->where( 'source_type', $source_type )
					->where( 'source_id', $source_id );
	}

	/**
	 * Scope: Filter by campaign
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @param int                                   $campaign_id
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForCampaign( $query, $campaign_id ) {
		return $query->where( 'source_type', self::SOURCE_CAMPAIGN )
					->where( 'source_id', $campaign_id );
	}

	/**
	 * Scope: Filter by automation
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @param int                                   $automation_id
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForAutomation( $query, $automation_id ) {
		return $query->where( 'source_type', self::SOURCE_AUTOMATION )
					->where( 'source_id', $automation_id );
	}

	/**
	 * Scope: Filter by contact
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @param int                                   $contact_id
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForContact( $query, $contact_id ) {
		return $query->where( 'contact_id', $contact_id );
	}

	/**
	 * Create unsubscribe record
	 *
	 * @since 1.0.0
	 *
	 * @param int         $contact_id  Contact ID
	 * @param string      $mode     mode (email, sms, whatsapp)
	 * @param string      $reason      Reason for unsubscribe
	 * @param string|null $source_type Source type (campaign, automation)
	 * @param int|null    $source_id   Source ID (campaign or automation ID)
	 *
	 * @return ContactUnsubscribeModel
	 */
	public static function record_unsubscribe( $contact_id, $mode, $reason = '', $source_type = null, $source_id = null ) {
		return self::create(
			array(
				'contact_id'  => $contact_id,
				'mode'        => $mode,
				'reason'      => $reason,
				'source_type' => $source_type,
				'source_id'   => $source_id,
				'created_at'  => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Get valid source types
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_valid_source_types() {
		return array(
			self::SOURCE_CAMPAIGN,
			self::SOURCE_AUTOMATION,
		);
	}

	/**
	 * Boot method
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		static::creating(
			function ( $unsubscribe ) {
				if ( ! $unsubscribe->created_at ) {
					$unsubscribe->created_at = current_time( 'mysql', true );
				}
			}
		);
	}
}
