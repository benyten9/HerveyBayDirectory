<?php

/**
 * Class CampaignModel
 * This class is responsible for handling the campaign model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Filters\Process as Contact_Filters_Process;
use DoubleScale\Modules\Campaigns\Services\CampaignStatusManager;
use DoubleScale\Modules\Campaigns\Services\CampaignTemplateFactory;
use DoubleScale\Modules\Campaigns\Services\TemplateFieldMapper;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Modules\Campaigns\Services\CampaignAnalytics;

/**
 * CampaignModel class
 */
class CampaignModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_campaigns';

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
		'name',
		'description',
		'status',
		'type',
		'settings',
		'parent_id',
		'count',
		'execute_at',
		'processing_started_at',
		'created_by',
		'created_at',
		'updated_at',
	);

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		// Note: 'settings' conversion handled by getSettingsAttribute/setSettingsAttribute accessors
		// Note: 'type' conversion handled by getTypeAttribute/setTypeAttribute accessors
	);

	/**
	 * Rules
	 *
	 * @var array
	 */
	protected $rules = array(
		'name' => 'required',
	);

	/**
	 * Messages
	 *
	 * @var array
	 */
	protected $messages = array(
		'name.required' => 'Campaign name is required',
	);

	/**
	 * Appends
	 *
	 * @var array
	 */
	protected $appends = array( 'subject', 'email_body', 'preview_text' );

	/**
	 * Guarded attributes - computed fields that should never be persisted
	 * These are set by CampaignEnrichment service and are not database columns
	 *
	 * @var array
	 */
	protected $guarded_computed = array(
		'contacts_count',
		'templates_count',
		'sent_count',
		'failed_count',
		'opened_count',
		'clicked_count',
		'unsubscribed_count',
		'open_rate',
		'click_rate',
		'pending_count',
		'delivered_count',
		'delivery_rate',
		'read_count',
		'read_rate',
		'is_attached',
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
	 * Get all campaign messages
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function messages() {
		return $this->hasMany( CommunicationTrackingModel::class, 'source_id', 'id' )
			->where( 'source_type', MessageSourceTypes::CAMPAIGN );
	}

	/**
	 * Get campaign messages with existing contacts only
	 * Filters out messages where the contact has been deleted
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function messages_with_contacts() {
		global $wpdb;
		$contacts_table = $wpdb->prefix . 'doublescale_contacts';
		$tracking_table = $wpdb->prefix . 'doublescale_communication_tracking';

		return $this->hasMany( CommunicationTrackingModel::class, 'source_id', 'id' )
			->where( 'source_type', MessageSourceTypes::CAMPAIGN )
			->whereExists(
				function ( $query ) use ( $contacts_table, $tracking_table ) {
					$query->selectRaw( '1' )
						->from( $contacts_table )
						->whereColumn( "{$contacts_table}.id", "{$tracking_table}.contact_id" );
				}
			);
	}

	/**
	 * Get the campaign emails
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function emails() {
		return $this->messages()->emails();
	}

	/**
	 * Get the campaign Sms
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function sms() {
		return $this->messages()->sms();
	}

	/**
	 * Get the campaign WhatsApp messages
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function whatsapp() {
		return $this->messages()->whatsapp();
	}

	/**
	 * Get the campaign email sequences
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function sequences_mail() {
		return $this->hasMany( self::class, 'parent_id' )->where( 'type', CampaignChannel::CHANNEL_SEQUENCE_MAIL );
	}

	/**
	 * Get parent campaign
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function parent() {
		return $this->belongsTo( self::class, 'parent_id' );
	}


	public function getSentAttribute() {
		return $this->messages_with_contacts()
			->where( 'status', TrackingStatus::SENT )
			->count();
	}

	public function getOpenedAttribute() {
		return $this->messages_with_contacts()
			->where( 'opened', true )
			->count();
	}

	public function getClickAttribute() {
		return $this->messages_with_contacts()
			->where( 'clicked', true )
			->count();
	}

	public function getSubjectAttribute() {
		$template_ids = $this->get_template_ids();
		$template     = reset( $template_ids );
		if ( $template ) {
			$template = TemplateModel::find( $template );
			if ( $template ) {
				return $template->settings['subject'] ?? '';
			}
		}
		return '';
	}

	public function getEmailBodyAttribute() {
		$template_ids = $this->get_template_ids();
		$template     = reset( $template_ids );
		if ( $template ) {
			$template = TemplateModel::find( $template );
			if ( $template ) {
				return $template->body;
			}
		}
		return '';
	}


	public function getPreviewTextAttribute() {
		$template_ids = $this->get_template_ids();
		$template     = reset( $template_ids );
		if ( $template ) {
			$template = TemplateModel::find( $template );
			if ( $template ) {
				return $template->settings['preview_text'] ?? '';
			}
		}
		return '';
	}

	/**
	 * Get setting
	 *
	 * @param string $key key.
	 * @param mixed  $default default value.
	 *
	 * @return mixed
	 */
	public function get_setting( $key, $default = null ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
	}

	/**
	 * Get campaign type as string for Api/frontend
	 *
	 * @since 1.0.0
	 *
	 * @return string Campaign type string ('email', 'sms', 'whatsapp')
	 */
	public function getTypeAttribute( $value ) {
		// Convert integer from database to string for Api
		return CampaignChannel::to_string( $value ) ?? 'email';
	}

	/**
	 * Set campaign type from string to integer for database
	 *
	 * @since 1.0.0
	 *
	 * @param string|int $value Campaign type as string or integer
	 */
	public function setTypeAttribute( $value ) {
		// If it's already an integer, store it directly
		if ( is_int( $value ) ) {
			$this->attributes['type'] = $value;
			return;
		}

		// Convert string to integer for database storage
		$integer_value            = CampaignChannel::to_integer( $value );
		$this->attributes['type'] = $integer_value ?? CampaignChannel::CHANNEL_EMAIL;
	}

	/**
	 * Get settings attribute accessor
	 * Ensures settings is never null, returns empty array instead
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $value JSON string or null from database
	 * @return array
	 */
	public function getSettingsAttribute( $value ) {
		// If settings is null, return empty array
		if ( is_null( $value ) ) {
			return array();
		}

		// Parse JSON to array
		$decoded = json_decode( $value, true );

		// If decoding failed or result is null, return empty array
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Set settings attribute mutator
	 * Converts array to JSON string for database storage
	 *
	 * @since 1.0.0
	 *
	 * @param array|string|null $value Settings data
	 */
	public function setSettingsAttribute( $value ) {
		// If already a string (JSON), store directly
		if ( is_string( $value ) ) {
			$this->attributes['settings'] = $value;
			return;
		}

		// If null or not an array, store empty JSON object
		if ( ! is_array( $value ) ) {
			$this->attributes['settings'] = '{}';
			return;
		}

		// Convert array to JSON string
		$this->attributes['settings'] = json_encode( $value );
	}

	/**
	 * Get campaign type as integer (for internal processing)
	 *
	 * @since 1.0.0
	 *
	 * @return int Campaign type integer
	 */
	public function get_type() {
		// Access the raw value from database and cast to integer
		// Eloquent may return this as a string, so we ensure it's an integer
		return (int) ( $this->attributes['type'] ?? CampaignChannel::get_default() );
	}

	/**
	 * Get the campaign type for template processing
	 * Sequence mails and email sequences should be treated as email campaigns for template processing
	 *
	 * @since 1.0.0
	 *
	 * @return int Campaign type integer for processing
	 */
	public function get_template_processing_type() {
		$type = $this->get_type();

		// Sequence mails and email sequences are email-based campaigns
		if ( $type === CampaignChannel::CHANNEL_SEQUENCE_MAIL || $type === CampaignChannel::CHANNEL_EMAIL_SEQUENCE ) {
			return CampaignChannel::CHANNEL_EMAIL;
		}

		return $type;
	}

	/**
	 * Check if campaign is Sms type
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_sms_campaign() {
		return $this->get_type() === CampaignChannel::CHANNEL_SMS;
	}

	/**
	 * Check if campaign is email type (includes email sequences)
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_email_campaign() {
		$type = $this->get_type();
		return $type === CampaignChannel::CHANNEL_EMAIL
			|| $type === CampaignChannel::CHANNEL_EMAIL_SEQUENCE
			|| $type === CampaignChannel::CHANNEL_SEQUENCE_MAIL;
	}

	/**
	 * Check if campaign is WhatsApp type
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_whatsapp_campaign() {
		return $this->get_type() === CampaignChannel::CHANNEL_WHATSAPP;
	}

	/**
	 * Check if campaign is email sequence parent type
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_email_sequence() {
		return $this->get_type() === CampaignChannel::CHANNEL_EMAIL_SEQUENCE;
	}

	/**
	 * Check if campaign is sequence mail (child of email sequence)
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_sequence_mail() {
		return $this->get_type() === CampaignChannel::CHANNEL_SEQUENCE_MAIL;
	}



	/**
	 * Get the templates
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_templates() {
		$template_ids = $this->get_setting( 'template_ids', array() );

		if ( empty( $template_ids ) ) {
			return array();
		}

		$templates = array();

		foreach ( $template_ids as $template_id ) {
			$template = TemplateModel::find( $template_id );
			if ( $template ) {
				$templates[] = $template;
			}
		}

		return $templates;
	}

	/**
	 * Process template data and create/update TemplateModel records
	 *
	 * @param array $templates_data Array of template data
	 * @return array Array of template IDs
	 */
	private function process_templates( $templates_data ) {
		$campaign_type_int = $this->get_template_processing_type();
		// Convert integer to string for template factory
		$campaign_type_str = CampaignChannel::to_string( $campaign_type_int ) ?? 'email';
		$campaign_status   = $this->status ?? 'draft';
		$template_factory  = CampaignTemplateFactory::instance();

		return $template_factory->process_templates_data( $templates_data, $campaign_type_str, $campaign_status );
	}

	/**
	 * Attach templates to campaign settings for Api responses
	 * Converts template_ids back to full template objects
	 *
	 * @param CampaignModel $campaign Campaign model
	 * @return void
	 */
	public function attach_templates( $campaign ) {
		// Get templates and add them to settings for frontend
		$templates = $campaign->get_templates();

		if ( ! empty( $templates ) ) {
			$settings                = $campaign->settings;
			$settings['templates']   = $templates;
			$settings['is_attached'] = true;
			$campaign->settings      = $settings;
		}
	}

	/**
	 * Get template IDs
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_template_ids() {
		return $this->get_setting( 'template_ids', array() );
	}

	/**
	 * Get template count
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_template_count() {
		return count( $this->get_template_ids() );
	}

	/**
	 * Check if campaign has multiple templates (A/B testing)
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function has_multiple_templates() {
		return $this->get_template_count() > 1;
	}

	/**
	 * Get status manager
	 *
	 * @return CampaignStatusManager
	 */
	protected function get_status_manager() {
		return CampaignStatusManager::instance();
	}


	/**
	 * Get status label
	 *
	 * @return string
	 */
	public function get_status_label() {
		$labels = $this->get_status_manager()->get_status_labels();
		return $labels[ $this->status ] ?? $this->status;
	}

	/**
	 * Validate status before setting
	 *
	 * @param string $value
	 */
	public function setStatusAttribute( $value ) {
		$manager = $this->get_status_manager();

		if ( ! $manager->is_valid_status( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \InvalidArgumentException( "Invalid campaign status: {$value}" );
		}

		$current_status = $this->attributes['status'] ?? null;

		if ( $current_status && $current_status !== $value && ! $manager->is_valid_transition( $current_status, $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \InvalidArgumentException( "Invalid campaign status transition: {$current_status} -> {$value}" );
		}

		$this->attributes['status'] = $value;
	}

	/**
	 * Delete the contact notes boot method
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		// Save templates when saving the campaign
		static::saving(
			function ( $campaign ) {
				// Retrieve the settings attribute
				$settings = $campaign->settings;

				// If templates exist in settings without the is_attached flag, they are
				// new/edited template data that must be persisted as TemplateModel records.
				// When is_attached is present the templates were injected by attach_templates()
				// for the Api response — they should be dropped, not re-processed.
				if ( isset( $settings['templates'] ) && is_array( $settings['templates'] ) && ! isset( $settings['is_attached'] ) ) {
					$template_ids             = $campaign->process_templates( $settings['templates'] );
					$settings['template_ids'] = $template_ids;
				}

				// Always strip transient keys so they are never persisted to the DB.
				unset( $settings['templates'] );
				unset( $settings['is_attached'] );

				$campaign->settings = $settings;

				// Standard (non-automated) campaigns: when entering "processing", clear persisted
				// send offset from a previous run so the same campaign id does not skip all contacts
				// (e.g. completed run left offset == audience count, or filters/count changed).
				// Do not clear when resuming from "paused" — offset must continue mid-flight.
				if ( $campaign->isDirty( 'status' ) && CampaignStatusManager::PROCESSING === $campaign->status ) {
					$previous  = $campaign->getOriginal( 'status' );
					$automated = ! empty( $settings['automated'] );
					if ( ! $automated && CampaignStatusManager::PAUSED !== $previous ) {
						$type_int = (int) $campaign->getOriginal( 'type' );
						$slug     = CampaignChannel::to_string( $type_int );
						if ( $slug && in_array( $slug, CampaignChannel::get_core_channel_strings(), true ) ) {
							$id = (int) $campaign->getKey();
							if ( $id > 0 ) {
								delete_option( "doublescale_{$slug}_campaigns_last_contact_offset_{$id}" );
								delete_option( "doublescale_{$slug}_campaign_start_time_{$id}" );
							}
						}
					}
				}
			}
		);

		// Fire event when campaign is scheduled
		static::saved(
			function ( $campaign ) {
				// Check if status changed to 'schedule'
				$original_status = $campaign->getOriginal( 'status' );
				$new_status      = $campaign->status;

				if ( $new_status === 'schedule' && $original_status !== 'schedule' ) {
					/**
					 * Fires when a campaign is scheduled.
					 *
					 * @since 1.0.0
					 *
					 * @param CampaignModel $campaign   The scheduled campaign.
					 * @param string         $execute_at Scheduled execution time.
					 */
					do_action( 'doublescale_campaign_schedule', $campaign, $campaign->execute_at );
				}
			}
		);

		// Delete the campaign templates when deleting the campaign
		// static::deleting(
		// function ($campaign) {
		// Get template IDs and delete associated templates
		// $template_ids = $campaign->get_template_ids();

		// foreach ($template_ids as $template_id) {
		// $template = TemplateModel::find($template_id);
		// if ($template) {
		// $template->delete();
		// }
		// }
		// }
		// );

		// Note: Computed attributes (counts, rates, etc.) are now handled by
		// CampaignEnrichment service in controllers, not in model events.
		// This prevents N+1 queries and eliminates the need for unset() calls.
	}
}
