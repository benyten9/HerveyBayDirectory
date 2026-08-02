<?php
/**
 * Communication Tracking Model
 * Tracks message delivery, opens, clicks across all channels and sources
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Tracking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Modules\Tracking\Models\TrackingCampaignModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\TrackingTemplateModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Constants\CampaignChannel;

/**
 * CommunicationTrackingModel class
 */
class CommunicationTrackingModel extends Model {


	/**
	 * Communication channel modes for tracking
	 */
	const MODE_EMAIL    = 1;
	const MODE_SMS      = 2;
	const MODE_WHATSAPP = 3;

	/**
	 * Campaign model for Eloquent relations when full Campaigns module is present (Pro or future free).
	 *
	 * @return string
	 */
	protected static function resolve_campaign_relation_class() {
		if ( class_exists( '\DoubleScale\Modules\Campaigns\Models\CampaignModel' ) ) {
			return \DoubleScale\Modules\Campaigns\Models\CampaignModel::class;
		}
		return TrackingCampaignModel::class;
	}

	/**
	 * Template model for Eloquent relations when full Campaigns module is present.
	 *
	 * @return string
	 */
	protected static function resolve_template_relation_class() {
		if ( class_exists( '\DoubleScale\Modules\Campaigns\Models\TemplateModel' ) ) {
			return \DoubleScale\Modules\Campaigns\Models\TemplateModel::class;
		}
		return TrackingTemplateModel::class;
	}

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_communication_tracking';

	/**
	 * Primary key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $primary_key = 'id';

	/**
	 * Fillable columns for tracking records
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $fillable = array(
		'contact_id',     // Who received the message
		'template_id',    // Template used
		'hash_key',       // Unique tracking hash
		'mode',           // Email/Sms/Whatsapp
		'direction',      // outbound/inbound - message direction
		'source_type',    // Campaign/Automation/Individual
		'source_id',      // Polymorphic FK: campaign_id, automation_id, or activity_id (for individuals)
		'step_id',        // Automation step ID (NULL for campaigns/individual)
		'author_id',      // User who sent the message (for individual sends)
		'recipient',      // Email address or phone number
		'external_id',    // Twilio MessageSid, email provider ID, etc.
		'opened',         // Open tracking (emails only)
		'clicked',        // Click tracking
		'status',         // sent/pending/failed/delivered/read
		'sent_at',        // When sent
		'opened_at',      // When opened (emails/whatsapp read)
		'clicked_at',     // When clicked
		'created_at',     // Record created
		'updated_at',     // Record updated
	);

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'opened'      => 'boolean',
		'clicked'     => 'boolean',
		'mode'        => 'integer',
		'direction'   => 'integer',
		'source_type' => 'integer',
		'source_id'   => 'integer',
		'step_id'     => 'integer',
		'status'      => 'integer',
	);

	/**
	 * Appends (virtual attributes for Api responses)
	 *
	 * @var array
	 */
	protected $appends = array(
		'status_name',
		'status_slug',
		'status_class',
		'direction_slug',
		'error_info',
		'sent_by',
		'sent_from',
	);

	/**
	 * Timestamps
	 *
	 * @var bool
	 *
	 * @since 1.0.0
	 */
	public $timestamps = true;

	/**
	 * Campaign relationship (only valid when source_type = CAMPAIGN).
	 *
	 * source_id is polymorphic: it points to a campaign only when
	 * source_type = 1 (CAMPAIGN). For individual messages (source_type = 3)
	 * source_id points to an activity, so eager-loading this relationship
	 * on non-campaign records would return a wrong model.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function campaign() {
		return $this->belongsTo( static::resolve_campaign_relation_class(), 'source_id' );
	}

	/**
	 * Contact relationship
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( ContactModel::class, 'contact_id' );
	}

	/**
	 * Template relationship
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function template() {
		return $this->belongsTo( static::resolve_template_relation_class(), 'template_id' );
	}

	/**
	 * Activity relationship (for individual messages only, via source_id).
	 *
	 * For individual messages (source_type = 3), source_id points to the activity
	 * — this accessor exposes that as an Eloquent relation for convenience.
	 *
	 * IMPORTANT: This relationship should ONLY be used when source_type = INDIVIDUAL (3).
	 * For campaigns/automations, source_id points to campaign/automation, not activity.
	 * The relationship itself doesn't enforce this constraint to avoid affecting main queries.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function activity() {
		return $this->belongsTo( ActivityModel::class, 'source_id' );
	}

	/**
	 * Communication tracking meta relationship (for merge tag values)
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function communication_tracking_meta() {
		return $this->hasMany( CommunicationTrackingMetaModel::class, 'communication_tracking_id' );
	}

	/**
	 * Get by hash key
	 *
	 * @param string $hash_key Hash key.
	 *
	 * @since 1.0.0
	 *
	 * @return object
	 */
	public static function get_by_hash_key( $hash_key ) {
		return self::where( 'hash_key', $hash_key )->firstOrFail();
	}

	/**
	 * Scope: Email messages only
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeEmails( $query ) {
		return $query->where( 'mode', self::MODE_EMAIL );
	}

	/**
	 * Scope: Sms messages only
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeSms( $query ) {
		return $query->where( 'mode', self::MODE_SMS );
	}

	/**
	 * Scope: WhatsApp messages only
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeWhatsapp( $query ) {
		return $query->where( 'mode', self::MODE_WHATSAPP );
	}

	/**
	 * Scope: Outbound messages only
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeOutbound( $query ) {
		return $query->where( 'direction', MessageDirection::OUTBOUND );
	}

	/**
	 * Scope: Inbound messages only
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeInbound( $query ) {
		return $query->where( 'direction', MessageDirection::INBOUND );
	}

	/**
	 * Scope: Campaign messages only
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeFromCampaign( $query ) {
		return $query->where( 'source_type', MessageSourceTypes::CAMPAIGN );
	}

	/**
	 * Scope: Automation messages only
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeFromAutomation( $query ) {
		return $query->where( 'source_type', MessageSourceTypes::AUTOMATION );
	}


	/**
	 * Scope: Messages by source type
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @param int                                   $source_type
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeBySourceType( $query, $source_type ) {
		return $query->where( 'source_type', $source_type );
	}

	/**
	 * Scope: Messages by source
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @param int                                   $source_type
	 * @param int                                   $source_id
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeBySource( $query, $source_type, $source_id ) {
		return $query->where( 'source_type', $source_type )->where( 'source_id', $source_id );
	}

	/**
	 * Scope: Messages by automation step
	 *
	 * @since 1.0.0
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @param int                                   $step_id
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByStep( $query, $step_id ) {
		return $query->where( 'step_id', $step_id );
	}

	/**
	 * Check if message is email
	 *
	 * @return bool
	 */
	public function is_email() {
		return $this->mode === self::MODE_EMAIL;
	}

	/**
	 * Check if message is Sms
	 *
	 * @return bool
	 */
	public function is_sms() {
		return $this->mode === self::MODE_SMS;
	}

	/**
	 * Check if message is WhatsApp
	 *
	 * @return bool
	 */
	public function is_whatsapp() {
		return $this->mode === self::MODE_WHATSAPP;
	}

	/**
	 * Check if message was opened (email only)
	 *
	 * @return bool
	 */
	public function is_opened() {
		return $this->is_email() && $this->opened == 1;
	}

	/**
	 * Check if message was clicked
	 *
	 * @return bool
	 */
	public function is_clicked() {
		return $this->clicked == 1;
	}

	/**
	 * Get source type label
	 *
	 * @return string
	 */
	public function get_source_type_label() {
		return MessageSourceTypes::get_type_label( $this->source_type );
	}

	/**
	 * Check if message is from campaign
	 *
	 * @return bool
	 */
	public function is_from_campaign() {
		return $this->source_type === MessageSourceTypes::CAMPAIGN;
	}

	/**
	 * Check if message is from automation
	 *
	 * @return bool
	 */
	public function is_from_automation() {
		return $this->source_type === MessageSourceTypes::AUTOMATION;
	}

	/**
	 * Get automation relationship (only when source_type = 2)
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function automation() {
		return $this->belongsTo( AutomationModel::class, 'source_id' );
	}

	/**
	 * Get automation step relationship (only when source_type = AUTOMATION)
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function step() {
		return $this->belongsTo( AutomationStepModel::class, 'step_id' );
	}


	/**
	 * Get campaign safely (only when source_type = CAMPAIGN)
	 *
	 * @return CampaignModel|null
	 */
	public function get_campaign() {
		if ( $this->source_type === MessageSourceTypes::CAMPAIGN ) {
			if ( class_exists( '\DoubleScale\Modules\Campaigns\Models\CampaignModel' ) ) {
				return \DoubleScale\Modules\Campaigns\Models\CampaignModel::find( $this->source_id );
			}
			return TrackingCampaignModel::find( $this->source_id );
		}
		return null;
	}

	/**
	 * Get automation safely (only when source_type = AUTOMATION)
	 *
	 * @return AutomationModel|null
	 */
	public function get_automation() {
		if ( $this->source_type === MessageSourceTypes::AUTOMATION ) {
			return AutomationModel::find( $this->source_id );
		}
		return null;
	}

	/**
	 * Get automation step safely (only when source_type = AUTOMATION)
	 *
	 * @since 1.0.0
	 *
	 * @return AutomationStepModel|null
	 */
	public function get_step() {
		if ( $this->source_type === MessageSourceTypes::AUTOMATION && $this->step_id ) {
			return AutomationStepModel::find( $this->step_id );
		}
		return null;
	}

	/**
	 * Set source information
	 *
	 * @param int $source_type Source type constant
	 * @param int $source_id Source ID (campaign_id, automation_id, etc.)
	 * @return void
	 */
	public function set_source( $source_type, $source_id = 0 ) {
		$this->source_type = $source_type;
		$this->source_id   = $source_id;
	}

	/**
	 * Check if message failed
	 *
	 * @return bool
	 */
	public function is_failed() {
		return $this->status === TrackingStatus::FAILED;
	}

	/**
	 * Check if message was sent
	 *
	 * @return bool
	 */
	public function is_sent() {
		return $this->status === TrackingStatus::SENT;
	}

	/**
	 * Check if message is pending
	 *
	 * @return bool
	 */
	public function is_pending() {
		return $this->status === TrackingStatus::PENDING;
	}

	/**
	 * Check if message is delivered
	 *
	 * @return bool
	 */
	public function is_delivered() {
		return $this->status === TrackingStatus::DELIVERED;
	}


	/**
	 * Get status name (accessor for Api)
	 *
	 * @return string
	 */
	public function getStatusNameAttribute() {
		return TrackingStatus::get_name( $this->status );
	}

	/**
	 * Get status slug (accessor for Api)
	 *
	 * @return string
	 */
	public function getStatusSlugAttribute() {
		return TrackingStatus::get_slug( $this->status );
	}

	/**
	 * Get status CSS class (accessor for UI)
	 *
	 * @return string
	 */
	public function getStatusClassAttribute() {
		return TrackingStatus::get_status_class( $this->status );
	}

	/**
	 * Get direction slug (accessor for Api)
	 *
	 * @return string
	 */
	public function getDirectionSlugAttribute() {
		return MessageDirection::get_slug( $this->direction );
	}

	/**
	 * Get error info (accessor for Api)
	 * Returns error information for failed messages from the meta table
	 *
	 * @since 1.0.0
	 *
	 * @return array|null Array with 'code' and 'message' keys, or null if no error
	 */
	public function getErrorInfoAttribute() {
		// Only fetch error info for failed messages to avoid unnecessary queries
		if ( $this->status !== TrackingStatus::FAILED ) {
			return null;
		}

		return CommunicationTrackingMetaModel::get_error_info( $this->id );
	}

	/**
	 * Get sender info (accessor for Api).
	 *
	 * Resolves author_id to user display data, with fallback to activity from_email/from_name.
	 *
	 * @since 1.0.0
	 *
	 * @return array|null { display_name, email, avatar_url } or null for non-individual messages.
	 */
	public function getSentByAttribute() {
		if ( (int) $this->direction !== MessageDirection::OUTBOUND ) {
			return null;
		}

		// Try author_id first (set on CRM-sent and IMAP-synced outbound messages).
		if ( ! empty( $this->author_id ) ) {
			$user = get_userdata( (int) $this->author_id );
			if ( $user ) {
				return array(
					'display_name' => $user->display_name ?: $user->user_login,
					'email'        => $user->user_email,
					'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
				);
			}
		}

		// Fallback: activity user_id (for IMAP-synced outbound emails).
		$activity = $this->getRelationValue( 'activity' );
		if ( $activity && ! empty( $activity->user_id ) ) {
			$user = get_userdata( (int) $activity->user_id );
			if ( $user ) {
				return array(
					'display_name' => $user->display_name ?: $user->user_login,
					'email'        => $user->user_email,
					'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
				);
			}
		}

		// Final fallback: from_email/from_name stored in activity data.
		if ( $activity ) {
			$data = $activity->data;
			if ( is_string( $data ) ) {
				$data = json_decode( $data, true );
			}
			$from_email = $data['from_email'] ?? '';
			$from_name  = $data['from_name'] ?? '';
			if ( ! empty( $from_email ) || ! empty( $from_name ) ) {
				return array(
					'display_name' => $from_name ?: $from_email,
					'email'        => $from_email,
					'avatar_url'   => $from_email ? get_avatar_url( $from_email, array( 'size' => 32 ) ) : '',
				);
			}
		}

		return null;
	}

	/**
	 * Get the actual "from" email identity used to send this message.
	 *
	 * For individual messages: reads from_email/from_name stored in activity data.
	 * For campaign messages: reads from_email/from_name from the template settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array|null { email, name } or null if unavailable.
	 */
	public function getSentFromAttribute() {
		if ( (int) $this->direction !== MessageDirection::OUTBOUND ) {
			return null;
		}

		// Individual messages: from_email stored in activity data by handle_result().
		if ( (int) $this->source_type === MessageSourceTypes::INDIVIDUAL ) {
			$activity = $this->getRelationValue( 'activity' );
			if ( $activity ) {
				$data = $activity->data;
				if ( is_string( $data ) ) {
					$data = json_decode( $data, true );
				}
				$from_email = $data['from_email'] ?? '';
				$from_name  = $data['from_name'] ?? '';
				if ( ! empty( $from_email ) ) {
					return array(
						'email' => $from_email,
						'name'  => $from_name,
					);
				}
			}
			return null;
		}

		// Campaign/automation messages: from_email stored in template settings.
		if ( ! empty( $this->template_id ) ) {
			$template = $this->getRelationValue( 'template' );
			if ( $template ) {
				$from_email = $template->get_setting( 'from_email' );
				$from_name  = $template->get_setting( 'from_name' );
				if ( ! empty( $from_email ) ) {
					return array(
						'email' => $from_email,
						'name'  => $from_name ?: '',
					);
				}
			}
		}

		return null;
	}

	/**
	 * Get campaign message statistics
	 *
	 * @param int $campaign_id Campaign ID (maps to source_id with source_type=CAMPAIGN)
	 * @param int $mode Message mode (optional)
	 *
	 * @return array
	 */
	public static function get_campaign_stats( $campaign_id, $mode = null ) {
		if ( ! class_exists( '\DoubleScale\Modules\Campaigns\Services\CampaignAnalytics' ) ) {
			return array();
		}

		$analytics = \DoubleScale\Modules\Campaigns\Services\CampaignAnalytics::instance();

		if ( $mode ) {
			$channel = CampaignChannel::from_mode( $mode );
			if ( $channel ) {
				return $analytics->get_campaign_stats( $channel, $campaign_id );
			}
		}

		// Return combined stats for all modes
		$stats = array();
		foreach ( CampaignChannel::get_all() as $channel ) {
			$stats[ $channel ] = $analytics->get_campaign_stats( $channel, $campaign_id );
		}

		return $stats;
	}

	/**
	 * Boot method for model events
	 */
	public static function boot() {
		parent::boot();

		// Update campaign message counts when message status changes
		static::saved(
			function ( $message ) {
				// Trigger campaign count recalculation based on mode
				$mode_actions = array(
					self::MODE_EMAIL    => 'doublescale_campaign_email_status_changed',
					self::MODE_SMS      => 'doublescale_campaign_sms_status_changed',
					self::MODE_WHATSAPP => 'doublescale_campaign_whatsapp_status_changed',
				);

				if ( isset( $mode_actions[ $message->mode ] ) ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook names are prefixed with 'doublescale_'.
					do_action( $mode_actions[ $message->mode ], $message );
				}
			}
		);
	}

	/**
	 * Get contact with tracking context set
	 * Returns the associated contact with this tracking record's context set for merge tags
	 *
	 * @return ContactModel|null Contact with tracking context set
	 */
	public function get_contact_with_tracking_context() {
		if ( ! $this->contact ) {
			return null;
		}

		// Set tracking context on the contact
		return $this->contact->set_tracking_context( $this->id );
	}

	/**
	 * Render original content using stored merge tag values
	 * Convenience method to render any content with this tracking record's stored values
	 *
	 * @param string $content Content with merge tags to render
	 * @return string Rendered content with stored merge tag values
	 */
	public function render_original_content( $content ) {
		return CommunicationTrackingMetaModel::render_with_stored_values( $this->id, $content );
	}

	/**
	 * Render the original template as it was sent
	 * Uses stored merge tag values to show historical accuracy
	 *
	 * @return string Rendered HTML of the original email
	 */
	public function render_original_template() {
		if ( ! $this->template_id ) {
			return '';
		}

		if ( ! class_exists( '\DoubleScale\Modules\Emails\EmailRenderer' ) ) {
			return '';
		}

		$renderer = new \DoubleScale\Modules\Emails\EmailRenderer();
		return $renderer->render_template_with_tracking( $this->template_id, $this->id, $this->contact );
	}
}
