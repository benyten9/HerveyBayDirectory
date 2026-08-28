<?php

/**
 * Class ContactModel
 * This class is responsible for handling the contact model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Modules\Contacts\Models\ListModel;
use DoubleScale\Modules\Contacts\Models\TagModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\Models\UserModel;
use DoubleScale\Modules\Automations\Models\AutomationContactProcessesModel;
use DoubleScale\Modules\Contacts\Models\ContactUnsubscribeModel;
// use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel; // Optional explicit import; class autoloads when Pro is active.
use DoubleScale\Core\Services\CurrencyResolver;
use DoubleScale\Core\Utils\Utils;
use DoubleScale\Core\Validators\PhoneValidator;
use DoubleScale\Core\Constants\EddOrderStatus;
use DoubleScale\Core\Constants\OrderStatus;
use DoubleScale\Modules\Campaigns\Models\EddOrderModel;

/**
 * ContactModel class
 */
class ContactModel extends Model {



	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_contacts';

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
		'hash_id',
		'email',
		'first_name',
		'last_name',
		'company_name',
		'company_registration_number',
		'tax_vat_number',
		'avatar_id',
		'phone',
		'whatsapp_phone',
		'address_1',
		'address_2',
		'city',
		'state',
		'country',
		'zip',
		'source',
		'email_status',
		'sms_status',
		'whatsapp_status',
		'created_at',
		'updated_at',
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
	 * The accessors to append to the model's array form.
	 *
	 * @var array
	 */
	protected $appends = array( 'avatar_url' );

	/**
	 * Tracking context for merge tags
	 * When set, merge tags will use stored values from communication_tracking_meta
	 *
	 * @var int|null
	 */
	protected $tracking_context_id = null;

	/**
	 * Rules
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public $rules = array(
		// Use WordPress is_email() in save() instead of Illuminate's "email" rule: the scoped
		// Egulias RFC lexer triggers PCRE errors on PHP 8.2+ (preg_split unknown \p property),
		// which makes every address fail validation.
		'email'          => 'nullable',
		'phone'          => 'nullable|regex:/^\+?[0-9]+$/',
		'whatsapp_phone' => 'nullable|regex:/^\+[1-9][0-9]{0,14}$/',
		'zip'            => 'nullable|string|max:150',
	);

	/**
	 * Messages
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public $messages = array(
		'email.required'              => 'Contact email field is required.',
		'email.email'                 => 'Invalid email address.',
		'email.unique'                => 'A contact with this email address already exists.',
		'contact.identifier_required' => 'Contact must have an email address or phone number.',
		'phone.regex'                 => 'Invalid phone number.',
		'phone.unique'                => 'A contact with this phone number already exists.',
		'whatsapp_phone.regex'        => 'Invalid WhatsApp phone number. Must be in E.164 format (e.g., +12025551234).',
		'whatsapp_phone.unique'       => 'A contact with this WhatsApp number already exists.',
		'zip.max'                     => 'Zip / postal code must be 150 characters or fewer.',
	);

	/**
	 * Get the contact lists
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function lists() {
		return $this->belongsToMany( ListModel::class, 'doublescale_contact_taxonomy_relationship', 'contact_id', 'taxonomy_id' )
			->wherePivot( 'taxonomy_type', 'list' )
			->withPivot( 'taxonomy_type', 'status' );
	}

	/**
	 * Get the contact tags
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function tags() {
		return $this->belongsToMany( TagModel::class, 'doublescale_contact_taxonomy_relationship', 'contact_id', 'taxonomy_id' )
			->wherePivot( 'taxonomy_type', 'tag' )
			->withPivot( 'taxonomy_type' );
	}

	/**
	 * Get the custom fields (definitions + pivot values).
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany|null
	 */
	public function custom_fields() {
		if ( class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' ) ) {
			return $this->belongsToMany( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::class, 'doublescale_custom_field_relationship', 'entity_id', 'custom_field_id' )
				->withPivot( 'value' )
				->wherePivot( 'entity_type', 'contact' );
		}
		return null;
	}

	/**
	 * Get the contact user
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function user() {
		return $this->belongsTo( UserModel::class, 'email', 'user_email' );
	}

	/**
	 * Get the contact campaign emails
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function campaign_emails() {
		if ( ! class_exists( '\DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel' ) ) {
			return $this->hasMany( ActivityModel::class, 'contact_id', 'id' )->whereRaw( '1 = 0' );
		}
		return $this->hasMany( \DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel::class, 'contact_id', 'id' )->emails();
	}

	/**
	 * Get the contact orders
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function orders() {
		if ( ! class_exists( '\DoubleScale\Modules\Campaigns\Models\WcOrderModel' ) ) {
			return $this->hasMany( ActivityModel::class, 'contact_id', 'id' )->whereRaw( '1 = 0' );
		}
		return $this->hasMany( \DoubleScale\Modules\Campaigns\Models\WcOrderModel::class, 'billing_email', 'email' )
			->whereIn( 'status', OrderStatus::get_revenue_statuses() )
			->orderBy( 'date_created_gmt', 'desc' );
	}

	/**
	 * Get edd orders
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function edd_orders() {
		if ( ! class_exists( EddOrderModel::class ) ) {
			return $this->hasMany( ActivityModel::class, 'contact_id', 'id' )->whereRaw( '1 = 0' );
		}
		return $this->hasMany( EddOrderModel::class, 'email', 'email' );
	}

	/**
	 * Build an EDD orders query scoped to this contact.
	 *
	 * Matches billing email, linked EDD customer ID, and WordPress user ID so
	 * orders are not missed when identifiers differ across records.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function edd_orders_query() {
		if ( ! class_exists( EddOrderModel::class ) ) {
			return ActivityModel::query()->whereRaw( '1 = 0' );
		}

		$email = $this->email;

		return EddOrderModel::query()->where(
			function ( $query ) use ( $email ) {
				$query->where( 'email', $email );

				if ( function_exists( 'edd_get_customer_by' ) ) {
					$customer = edd_get_customer_by( 'email', $email );
					if ( $customer && ! empty( $customer->id ) ) {
						$query->orWhere( 'customer_id', (int) $customer->id );
					}
				}

				$user = get_user_by( 'email', $email );
				if ( $user ) {
					$query->orWhere( 'user_id', (int) $user->ID );
				}
			}
		);
	}

	/**
	 * EDD sale orders that count toward purchase history totals.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function edd_revenue_orders_query() {
		return $this->edd_orders_query()
			->where( 'type', 'sale' )
			->whereIn( 'status', EddOrderStatus::get_revenue_statuses() );
	}

	/**
	 * Get the contact custom field value from the pivot.
	 *
	 * @since 1.0.0
	 *
	 * @param int $custom_field_id Custom field ID
	 *
	 * @return string|null
	 */
	public function get_custom_field( $custom_field_id ) {
		if ( ! class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' ) ) {
			return null;
		}

		$custom_field = $this->custom_fields->where( 'id', $custom_field_id )->first();
		if ( $custom_field ) {
			return $custom_field->pivot->value ?? '';
		}

		return null;
	}

	/**
	 * Get the contact notes (from activities table)
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function notes() {
		return $this->activities()
			->where( 'activity_type', 'note' )
			->with( ActivityModel::morph_append_relations() );
	}

	/**
	 * Get all activities for this contact
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function activities() {
		return $this->belongsToMany(
			ActivityModel::class,
			'doublescale_activity_associations',
			'entity_id',
			'activity_id'
		)->wherePivot( 'entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_CONTACT );
	}

	/**
	 * Get all communication tracking records for this contact
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function communication_tracking() {
		if ( ! class_exists( '\DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel' ) ) {
			return $this->hasMany( ActivityModel::class, 'contact_id', 'id' )->whereRaw( '1 = 0' );
		}
		return $this->hasMany( \DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel::class, 'contact_id', 'id' );
	}

	/**
	 * Get form submissions for this contact
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function form_submissions() {
		if ( ! class_exists( '\DoubleScale\Pro\Modules\Forms\Models\FormSubmissionModel' ) ) {
			return $this->hasMany( ActivityModel::class, 'contact_id', 'id' )->whereRaw( '1 = 0' );
		}
		return $this->hasMany( \DoubleScale\Pro\Modules\Forms\Models\FormSubmissionModel::class, 'contact_id', 'id' );
	}

	/**
	 * Get page visits for this contact
	 * Page visits are PRO-only feature - uses PRO model if available
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany|null
	 */
	public function page_visits() {
		if ( class_exists( 'DoubleScale\Modules\WebsiteTracking\Models\PageVisitModel' ) ) {
			return $this->hasMany( \DoubleScale\Modules\WebsiteTracking\Models\PageVisitModel::class, 'contact_id', 'id' );
		}
		return $this->hasMany( ActivityModel::class, 'contact_id', 'id' )->whereRaw( '1 = 0' );
	}

	/**
	 * Get unsubscribes
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function unsubscribes() {
		return $this->hasMany( ContactUnsubscribeModel::class, 'contact_id', 'id' );
	}

	/**
	 * Get notes attribute - transforms activity models to note format for serialization
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function getNotesAttribute() {
		// Check if notes relationship is loaded
		if ( ! $this->relationLoaded( 'notes' ) ) {
			return array();
		}

		// Transform activities to note format
		return $this->getRelation( 'notes' )->map(
			function ( $activity ) {
				return $activity->to_note_format();
			}
		)->values()->toArray();
	}

	/**
	 * Get the contact automations
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function automation_contacts() {
		return $this->hasMany( AutomationContactModel::class, 'contact_id', 'id' );
	}

	/**
	 * Get contact processes
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function processes() {
		return $this->hasMany( AutomationContactProcessesModel::class, 'contact_id', 'id' );
	}

	/**
	 * Get the contact meta
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function meta() {
		return $this->hasMany( ContactMetaModel::class, 'contact_id', 'id' );
	}

	/**
	 * Get the deals for this contact
	 * Deals are PRO-only feature - uses PRO model if available
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany|null
	 */
	public function deals() {
		$deal_model = doublescale_resolve_deal_model_class();
		if ( $deal_model ) {
			return $this->hasMany( $deal_model, 'contact_id', 'id' );
		}
		return null;
	}

	/**
	 * Get active deals (open status) for this contact
	 * Deals are PRO-only feature - uses PRO model if available
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany|null
	 */
	public function active_deals() {
		$deal_model = doublescale_resolve_deal_model_class();
		if ( $deal_model ) {
			return $this->hasMany( $deal_model, 'contact_id', 'id' )->where( 'status', 'open' );
		}
		return null;
	}

	/**
	 * Get total deal value for won deals
	 * Returns 0 if Pro plugin is not active
	 *
	 * @since 1.0.0
	 *
	 * @return float
	 */
	public function getTotalDealValueAttribute() {
		$deals = $this->deals();
		if ( ! $deals ) {
			return 0;
		}
		$map    = CurrencyResolver::sum_by_currency( $deals->where( 'status', 'won' )->get(), 'value' );
		$global = CurrencyResolver::global_currency();
		return $map[ $global ] ?? 0;
	}

	/**
	 * Won deal value grouped by resolved currency.
	 *
	 * @return array<string, float>
	 */
	public function getTotalDealValueByCurrencyAttribute() {
		$deals = $this->deals();
		if ( ! $deals ) {
			return array();
		}
		return CurrencyResolver::sum_by_currency( $deals->where( 'status', 'won' )->get(), 'value' );
	}

	/**
	 * Get active deals count
	 * Returns 0 if Pro plugin is not active
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function getActiveDealsCountAttribute() {
		$active_deals = $this->active_deals();
		if ( ! $active_deals ) {
			return 0;
		}
		return $active_deals->count();
	}

	/**
	 * Get avatar URL using WordPress get_avatar_url()
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function getAvatarUrlAttribute() {
		$avatar_id = ! empty( $this->avatar_id ) ? (int) $this->avatar_id : 0;
		if ( $avatar_id > 0 ) {
			$custom_url = wp_get_attachment_image_url( $avatar_id, 'medium' );
			if ( $custom_url ) {
				return $custom_url;
			}
		}

		$email = $this->email;
		$size  = 96; // Default avatar size

		// Get the user ID if this email belongs to a WordPress user
		$user    = get_user_by( 'email', $email );
		$user_id = $user ? $user->ID : 0;

		// Use get_avatar_url() with email and size
		// If user_id is 0, it will use the email for Gravatar
		$avatar_url = get_avatar_url( $user_id ? $user_id : $email, array( 'size' => $size ) );

		return $avatar_url;
	}

	/**
	 * Get automation data
	 *
	 * @since 1.0.0
	 *
	 * @return AutomationModel
	 */
	public function automation() {
		return $this->automation_contact->automation;
	}

	/**
	 * Get contact by email
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Contact email
	 *
	 * @return ContactModel
	 */
	public static function get_by_email( $email ) {
		$email = self::normalize_email( $email );
		if ( null === $email ) {
			return null;
		}

		return self::where( 'email', $email )->first();
	}

	/**
	 * Normalize an email value for storage and lookup.
	 *
	 * @param mixed $email Raw email value.
	 * @return string|null Trimmed email, or null when empty.
	 */
	public static function normalize_email( $email ) {
		if ( ! is_string( $email ) ) {
			return null;
		}

		$email = trim( $email );
		return '' === $email ? null : $email;
	}

	/**
	 * Find an existing contact by email, phone, or WhatsApp number.
	 *
	 * @param array<string, mixed> $data Contact attributes.
	 * @param int|null             $exclude_id Contact ID to exclude (updates).
	 *
	 * @return ContactModel|null
	 */
	public static function find_by_identifiers( array $data, $exclude_id = null ) {
		$conflict = self::find_identifier_conflict( $data, $exclude_id );

		return $conflict ? $conflict['contact'] : null;
	}

	/**
	 * Find an identifier conflict for email, phone, or WhatsApp number.
	 *
	 * @param array<string, mixed> $data       Contact attributes.
	 * @param int|null             $exclude_id Contact ID to exclude (updates).
	 *
	 * @return array{field: string, contact: ContactModel}|null
	 */
	public static function find_identifier_conflict( array $data, $exclude_id = null ) {
		$email = self::normalize_email( $data['email'] ?? null );
		if ( null !== $email ) {
			$query = self::where( 'email', $email );
			if ( null !== $exclude_id ) {
				$query->where( 'id', '!=', $exclude_id );
			}
			$contact = $query->first();
			if ( $contact ) {
				return array(
					'field'   => 'email',
					'contact' => $contact,
				);
			}
		}

		$phone = self::normalize_phone_field( $data['phone'] ?? null );
		if ( '' !== $phone ) {
			$query = self::where( 'phone', $phone );
			if ( null !== $exclude_id ) {
				$query->where( 'id', '!=', $exclude_id );
			}
			$contact = $query->first();
			if ( $contact ) {
				return array(
					'field'   => 'phone',
					'contact' => $contact,
				);
			}
		}

		$country_hint = isset( $data['country'] ) ? (string) $data['country'] : '';
		$whatsapp     = self::normalize_whatsapp_field( $data['whatsapp_phone'] ?? null, $country_hint );
		if ( '' !== $whatsapp ) {
			$query = self::where( 'whatsapp_phone', $whatsapp );
			if ( null !== $exclude_id ) {
				$query->where( 'id', '!=', $exclude_id );
			}
			$contact = $query->first();
			if ( $contact ) {
				return array(
					'field'   => 'whatsapp_phone',
					'contact' => $contact,
				);
			}
		}

		return null;
	}

	/**
	 * @param mixed $phone Raw phone value.
	 * @return string Normalized phone or empty string.
	 */
	public static function normalize_phone_field( $phone ) {
		return PhoneValidator::normalize_loose( $phone );
	}

	/**
	 * @param mixed        $phone        Raw WhatsApp value.
	 * @param string|null  $country_hint Optional country hint for E.164 resolution.
	 * @return string Normalized E.164 value or empty string.
	 */
	public static function normalize_whatsapp_field( $phone, $country_hint = null ) {
		$whatsapp = PhoneValidator::to_e164( $phone, (string) ( $country_hint ?? '' ) );
		return null === $whatsapp ? '' : $whatsapp;
	}

	/**
	 * Whether the contact has at least one required identifier (email or phone).
	 *
	 * WhatsApp is optional and cannot stand in for email/phone. The third
	 * argument is kept so existing call sites keep compiling.
	 *
	 * @param string|null $email
	 * @param string      $phone
	 * @param string      $whatsapp_phone Unused. Kept for call-site compatibility.
	 * @return bool
	 */
	public static function has_identifier( $email, $phone = '', $whatsapp_phone = '' ) {
		unset( $whatsapp_phone );

		if ( null !== self::normalize_email( $email ) ) {
			return true;
		}

		return '' !== self::normalize_phone_field( $phone );
	}

	/**
	 * Get contact by hash ID
	 *
	 * @since 1.0.0
	 *
	 * @param string $hash_id Contact hash ID
	 *
	 * @return ContactModel
	 */
	public static function get_by_hash_id( $hash_id ) {
		return self::where( 'hash_id', $hash_id )->first();
	}

	/**
	 * Check if contact is subscribed to a specific channel
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Channel name (email, sms, whatsapp)
	 * @return bool True if subscribed
	 */
	public function is_subscribed_to_channel( $channel ) {
		// Validate channel
		if ( ! in_array( $channel, self::get_valid_channels(), true ) ) {
			return false;
		}

		// Check channel-specific status
		$status_field = $channel . '_status';
		$status_value = $this->getAttribute( $status_field );

		// Handle NULL gracefully (shouldn't happen with NOT NULL constraint, but defensive)
		if ( is_null( $status_value ) ) {
			return true; // Default to subscribed
		}

		// All channels: 'blocked', 'unsubscribed' = not subscribed
		$excluded_statuses = array( 'blocked', 'unsubscribed' );

		// Email-only statuses: 'bounced', 'unverified'
		if ( 'email' === $channel ) {
			$excluded_statuses[] = 'bounced';
			$excluded_statuses[] = 'unverified';
		}

		if ( in_array( $status_value, $excluded_statuses, true ) ) {
			return false;
		}

		return 'subscribed' === $status_value;
	}

	/**
	 * Unsubscribe from specific mode
	 *
	 * @since 1.0.0
	 *
	 * @param int      $mode        Mode integer (1=Email, 2=Sms, 3=Whatsapp)
	 * @param string   $reason      Optional reason
	 * @param int|null $source_type Source type integer (1=Campaign, 2=Automation)
	 * @param int|null $source_id   Campaign ID or Automation ID
	 * @return bool Success
	 */
	public function unsubscribe_from_mode( $mode, $reason = '', $source_type = null, $source_id = null ) {
		// Map mode to channel for status field
		$channel_map = array(
			1 => 'email',
			2 => 'sms',
			3 => 'whatsapp',
		);

		if ( ! isset( $channel_map[ $mode ] ) ) {
			return false;
		}

		$channel      = $channel_map[ $mode ];
		$status_field = $channel . '_status';

		// Check if already unsubscribed
		if ( 'unsubscribed' === $this->getAttribute( $status_field ) ) {
			return true;
		}

		// Update status
		$this->$status_field = 'unsubscribed';
		$this->save();

		// Record unsubscribe in dedicated table
		try {
			ContactUnsubscribeModel::record_unsubscribe(
				$this->id,
				$mode,
				$reason,
				$source_type,
				$source_id
			);
		} catch ( \Exception $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Failed to record unsubscribe',
					array(
						'contact_id'  => $this->id,
						'mode'        => $mode,
						'error'       => $e->getMessage(),
						'source_type' => $source_type,
						'source_id'   => $source_id,
					)
				);
			}
		}

		$channel_label = self::get_channel_label( $channel );
		/* translators: %s: channel label (e.g., Email, Sms, WhatsApp) */
		$note_text = sprintf( __( 'Contact unsubscribed from %s.', 'doublescale' ), $channel_label );

		if ( ! empty( $reason ) ) {
			/* translators: %s: unsubscribe reason */
			$note_text .= ' ' . sprintf( __( 'Reason: %s', 'doublescale' ), $reason );
		}

		ActivityModel::create(
			array(
				'contact_id'    => $this->id,
				'activity_type' => 'note',
				'data'          => array(
					'title' => __( 'Unsubscribed', 'doublescale' ),
					'type'  => 'system',
					'note'  => $note_text,
				),
				'user_id'       => get_current_user_id() ?: null,
			)
		);

		// Fire action
		do_action( "doublescale_{$channel}_unsubscribed", $this );

		return true;
	}

	/**
	 * Subscribe to specific channel
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Channel name (email, sms, whatsapp)
	 * @return bool Success
	 */
	public function subscribe_to_channel( $channel ) {
		// Validate channel
		if ( ! in_array( $channel, self::get_valid_channels(), true ) ) {
			return false;
		}

		$status_field = $channel . '_status';

		// Check if already subscribed
		if ( 'subscribed' === $this->getAttribute( $status_field ) ) {
			return true; // Already subscribed
		}

		// Update channel status
		$this->$status_field = 'subscribed';

		// Save changes
		$this->save();

		// Create system note
		$channel_label = self::get_channel_label( $channel );

		ActivityModel::create(
			array(
				'contact_id'    => $this->id,
				'activity_type' => 'note',
				'data'          => array(
					'title' => __( 'Subscribed', 'doublescale' ),
					'type'  => 'system',
					/* translators: %s: channel label (e.g., Email, Sms, WhatsApp) */
					'note'  => sprintf( __( 'Contact subscribed to %s.', 'doublescale' ), $channel_label ),
				),
				'user_id'       => get_current_user_id() ?: null,
			)
		);

		// Fire channel-specific action
		do_action( "doublescale_{$channel}_subscribed", $this );

		return true;
	}

	/**
	 * Get channel subscription statuses
	 *
	 * @since 1.0.0
	 *
	 * @return array Channel statuses
	 */
	public function get_channel_subscriptions() {
		return array(
			'email'    => $this->getAttribute( 'email_status' ),
			'sms'      => $this->getAttribute( 'sms_status' ),
			'whatsapp' => $this->getAttribute( 'whatsapp_status' ),
		);
	}

	/**
	 * Get localized channel label
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Channel name (email, sms, whatsapp).
	 * @return string Localized channel label
	 */
	public static function get_channel_label( $channel ) {
		$labels = array(
			'email'    => __( 'emails', 'doublescale' ),
			'sms'      => __( 'Sms messages', 'doublescale' ),
			'whatsapp' => __( 'Whatsapp messages', 'doublescale' ),
		);

		return $labels[ $channel ] ?? __( 'communications', 'doublescale' );
	}

	/**
	 * Get list of valid channels
	 *
	 * @since 1.0.0
	 *
	 * @return array Valid channel identifiers
	 */
	public static function get_valid_channels() {
		return array( 'email', 'sms', 'whatsapp' );
	}

	/**
	 * Check whether the contact is subscribed to a specific list.
	 *
	 * @since 1.0.0
	 *
	 * @param int $list_id List ID.
	 * @return bool
	 */
	public function is_subscribed_to_list( $list_id ) {
		$list = $this->lists()->whereKey( (int) $list_id )->first();
		if ( ! $list ) {
			return false;
		}

		$status = $list->pivot->status ?? 'subscribed';

		return 'subscribed' === $status;
	}

	/**
	 * Unsubscribe from a list while retaining membership.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $list_id List ID.
	 * @param string $reason  Optional reason.
	 * @return bool
	 */
	public function unsubscribe_from_list( $list_id, $reason = '' ) {
		$list_id = (int) $list_id;
		if ( $list_id <= 0 ) {
			return false;
		}

		$list = ListModel::find( $list_id );
		if ( ! $list ) {
			return false;
		}

		if ( ! $this->lists()->whereKey( $list_id )->exists() ) {
			return false;
		}

		if ( ! $this->is_subscribed_to_list( $list_id ) ) {
			return true;
		}

		$this->lists()->updateExistingPivot( $list_id, array( 'status' => 'unsubscribed' ) );

		/* translators: %s: list name */
		$note_text = sprintf( __( 'Unsubscribed from list: %s', 'doublescale' ), $list->name );
		if ( ! empty( $reason ) ) {
			/* translators: %s: unsubscribe reason */
			$note_text .= ' ' . sprintf( __( 'Reason: %s', 'doublescale' ), $reason );
		}

		ActivityModel::create(
			array(
				'contact_id'    => $this->id,
				'activity_type' => 'note',
				'data'          => array(
					'title' => __( 'List Unsubscribed', 'doublescale' ),
					'type'  => 'system',
					'note'  => $note_text,
				),
				'user_id'       => get_current_user_id() ?: null,
			)
		);

		do_action( 'doublescale_contact_list_unsubscribed', $this, $list_id );

		return true;
	}

	/**
	 * Resubscribe to a list (attach first when not a member).
	 *
	 * @since 1.0.0
	 *
	 * @param int $list_id List ID.
	 * @return bool
	 */
	public function resubscribe_to_list( $list_id ) {
		$list_id = (int) $list_id;
		if ( $list_id <= 0 ) {
			return false;
		}

		$list = ListModel::find( $list_id );
		if ( ! $list ) {
			return false;
		}

		if ( ! $this->lists()->whereKey( $list_id )->exists() ) {
			$this->add_lists( array( $list_id ) );
			return true;
		}

		if ( $this->is_subscribed_to_list( $list_id ) ) {
			return true;
		}

		$this->lists()->updateExistingPivot( $list_id, array( 'status' => 'subscribed' ) );

		ActivityModel::create(
			array(
				'contact_id'    => $this->id,
				'activity_type' => 'note',
				'data'          => array(
					'title' => __( 'List Subscribed', 'doublescale' ),
					'type'  => 'system',
					/* translators: %s: list name */
					'note'  => sprintf( __( 'Subscribed to list: %s', 'doublescale' ), $list->name ),
				),
				'user_id'       => get_current_user_id() ?: null,
			)
		);

		do_action( 'doublescale_contact_list_subscribed', $this, $list_id );

		return true;
	}

	/**
	 * Sync lists
	 *
	 * @since 1.0.0
	 *
	 * @param array $lists List IDs
	 *
	 * @return void
	 */
	public function sync_lists( $lists ) {
		$existing_lists  = $this->lists->pluck( 'id' )->toArray();
		$lists_to_add    = array_diff( $lists, $existing_lists );
		$lists_to_remove = array_diff( $existing_lists, $lists );

		if ( ! empty( $lists_to_add ) ) {
			$this->lists()->attach(
				$lists_to_add,
				array(
					'taxonomy_type' => 'list',
					'status'        => 'subscribed',
				)
			);
			do_action( 'doublescale_contact_list_apply', $this, $lists_to_add );
		}

		if ( ! empty( $lists_to_remove ) ) {
			$this->lists()->detach( $lists_to_remove );
			do_action( 'doublescale_contact_list_remove', $this, $lists_to_remove );
		}
	}

	/**
	 * Add lists without detaching existing ones, firing the applied hook.
	 *
	 * @since 1.0.0
	 *
	 * @param array $lists List IDs
	 *
	 * @return void
	 */
	public function add_lists( $lists ) {
		$existing_lists = $this->lists->pluck( 'id' )->toArray();
		$lists_to_add   = array_diff( $lists, $existing_lists );

		if ( ! empty( $lists_to_add ) ) {
			$this->lists()->attach(
				$lists_to_add,
				array(
					'taxonomy_type' => 'list',
					'status'        => 'subscribed',
				)
			);
			do_action( 'doublescale_contact_list_apply', $this, $lists_to_add );
		}
	}

	/**
	 * Sync tags
	 *
	 * @since 1.0.0
	 *
	 * @param array $tags Tag IDs
	 *
	 * @return void
	 */
	public function sync_tags( $tags ) {
		$existing_tags  = $this->tags->pluck( 'id' )->toArray();
		$tags_to_add    = array_diff( $tags, $existing_tags );
		$tags_to_remove = array_diff( $existing_tags, $tags );

		if ( ! empty( $tags_to_add ) ) {
			$this->tags()->attach( $tags_to_add, array( 'taxonomy_type' => 'tag' ) );
			do_action( 'doublescale_contact_tag_apply', $this, $tags_to_add );
		}

		if ( ! empty( $tags_to_remove ) ) {
			$this->tags()->detach( $tags_to_remove );
			do_action( 'doublescale_contact_tag_remove', $this, $tags_to_remove );
		}
	}

	/**
	 * Add tags without detaching existing ones, firing the applied hook.
	 *
	 * @since 1.0.0
	 *
	 * @param array $tags Tag IDs
	 *
	 * @return void
	 */
	public function add_tags( $tags ) {
		$existing_tags = $this->tags->pluck( 'id' )->toArray();
		$tags_to_add   = array_diff( $tags, $existing_tags );

		if ( ! empty( $tags_to_add ) ) {
			$this->tags()->attach( $tags_to_add, array( 'taxonomy_type' => 'tag' ) );
			do_action( 'doublescale_contact_tag_apply', $this, $tags_to_add );
		}
	}

	/**
	 * Override save method to ensure events are registered
	 *
	 * @param array $options
	 * @return bool
	 */
	public function save( array $options = array() ) {
		// Ensure events are registered if this is a new contact
		if ( ! $this->exists ) {
			$dispatcher = static::getEventDispatcher();
			$model_name = static::class;
			$event_name = "eloquent.creating: {$model_name}";
			$listeners  = $dispatcher->getListeners( $event_name );

			// If no listeners, re-register events on current dispatcher
			if ( count( $listeners ) === 0 ) {
				$this->registerEventsOnDispatcher( $dispatcher, $model_name );
			}
		}

		if ( ! empty( $this->rules ) ) {
			$this->trim();
			$this->normalize_contact_identifiers();

			$email          = self::normalize_email( $this->resolve_identifier_value( 'email' ) );
			$phone          = (string) $this->resolve_identifier_value( 'phone' );
			$whatsapp_phone = (string) $this->resolve_identifier_value( 'whatsapp_phone' );

			if ( ! self::has_identifier( $email, $phone, $whatsapp_phone ) ) {
				throw new \Exception( esc_html( $this->messages['contact.identifier_required'] ) );
			}

			if ( null !== $email && ! is_email( $email ) ) {
				throw new \Exception( esc_html( $this->messages['email.email'] ) );
			}

			if ( '' !== $phone && ! preg_match( '/^\+?[0-9]+$/', $phone ) ) {
				throw new \Exception( esc_html( $this->messages['phone.regex'] ) );
			}

			if ( '' !== $whatsapp_phone && ! preg_match( '/^\+[1-9][0-9]{0,14}$/', $whatsapp_phone ) ) {
				throw new \Exception( esc_html( $this->messages['whatsapp_phone.regex'] ) );
			}

			$conflict = self::find_identifier_conflict(
				array(
					'email'          => $email,
					'phone'          => $phone,
					'whatsapp_phone' => $whatsapp_phone,
					'country'        => $this->resolve_identifier_value( 'country' ),
				),
				$this->exists ? (int) $this->id : null
			);
			if ( $conflict ) {
				$message_key = $conflict['field'] . '.unique';
				$message     = $this->messages[ $message_key ] ?? 'Contact already exists.';
				throw new \Exception( esc_html( $message ) );
			}
		}

		return parent::save( $options );
	}

	/**
	 * Override create method to ensure events are properly registered
	 *
	 * @param array $attributes
	 * @return static
	 */
	public static function create( array $attributes = array() ) {
		// Ensure boot is called by creating a temporary instance
		new static();

		// Create the actual instance using standard Eloquent approach
		$instance = new static();
		$instance->fill( $attributes );
		$instance->save();

		return $instance;
	}

	/**
	 * Create or update contact
	 *
	 * @param array $data Contact data
	 *
	 * @return ContactModel
	 */
	public static function createOrUpdate( $data ) {
		$normalized = self::normalize_contact_data( $data );
		$contact    = self::find_by_identifiers( $normalized );

		if ( ! $contact ) {
			// Use create() to ensure events fire
			return self::create( $normalized );
		}

		// For updates, use fill + save to trigger saved event
		$contact->fill( $normalized );
		$contact->save();

		return $contact;
	}

	/**
	 * Normalize identifier fields before create/update.
	 *
	 * @param array<string, mixed> $data Raw contact data.
	 * @return array<string, mixed>
	 */
	public static function normalize_contact_data( array $data ) {
		if ( array_key_exists( 'email', $data ) ) {
			$data['email'] = self::normalize_email( $data['email'] );
		}

		if ( array_key_exists( 'phone', $data ) ) {
			$phone         = self::normalize_phone_field( $data['phone'] );
			$data['phone'] = '' === $phone ? null : $phone;
		}

		if ( array_key_exists( 'whatsapp_phone', $data ) ) {
			$country_hint           = isset( $data['country'] ) ? (string) $data['country'] : '';
			$whatsapp               = self::normalize_whatsapp_field( $data['whatsapp_phone'], $country_hint );
			$data['whatsapp_phone'] = '' === $whatsapp ? null : $whatsapp;
		}

		if ( array_key_exists( 'avatar_id', $data ) ) {
			$avatar_id = absint( $data['avatar_id'] );
			$data['avatar_id'] = $avatar_id > 0 ? $avatar_id : null;
		}

		return $data;
	}

	/**
	 * Normalize identifier columns on the current model instance.
	 *
	 * @return void
	 */
	private function normalize_contact_identifiers() {
		if ( array_key_exists( 'email', $this->attributes ) ) {
			$this->attributes['email'] = self::normalize_email( $this->attributes['email'] );
		}

		if ( array_key_exists( 'phone', $this->attributes ) ) {
			$phone = self::normalize_phone_field( $this->attributes['phone'] );
			if ( '' === $phone ) {
				$this->attributes['phone'] = null;
			} else {
				$this->attributes['phone'] = $phone;
			}
		}

		if ( array_key_exists( 'whatsapp_phone', $this->attributes ) ) {
			$country_hint = isset( $this->attributes['country'] ) ? (string) $this->attributes['country'] : '';
			$whatsapp     = self::normalize_whatsapp_field( $this->attributes['whatsapp_phone'], $country_hint );
			if ( '' === $whatsapp ) {
				$this->attributes['whatsapp_phone'] = null;
			} else {
				$this->attributes['whatsapp_phone'] = $whatsapp;
			}
		}
	}

	/**
	 * Resolve an identifier field from pending attributes or the stored record.
	 *
	 * @param string $field Field name.
	 * @return mixed
	 */
	private function resolve_identifier_value( $field ) {
		if ( array_key_exists( $field, $this->attributes ) ) {
			return $this->attributes[ $field ];
		}

		if ( $this->exists && isset( $this->original[ $field ] ) ) {
			return $this->original[ $field ];
		}

		return '';
	}

	/**
	 * Register events on a specific dispatcher
	 *
	 * @param object $dispatcher Event dispatcher instance
	 * @param string $model_name Model class name
	 * @return void
	 */
	private function registerEventsOnDispatcher( $dispatcher, $model_name ) {
		// Creating event
		$dispatcher->listen(
			"eloquent.creating: {$model_name}",
			function ( $contact ) {
				$contact->hash_id = Utils::generate_hash_key();
			}
		);

		// Saving event
		$dispatcher->listen(
			"eloquent.saving: {$model_name}",
			function ( $contact ) {
				unset( $contact->revenue );
			}
		);

		// Created event - fire subscribed for genuinely new contacts.
		$dispatcher->listen(
			"eloquent.created: {$model_name}",
			function ( $contact ) {
				if ( $contact->email_status !== 'unsubscribed' ) {
					do_action( 'doublescale_contact_subscribe', $contact );
				}
			}
		);

		// Updated event - fire unsubscribed only when email_status actually changed.
		$dispatcher->listen(
			"eloquent.updated: {$model_name}",
			function ( $contact ) {
				// getChanges() returns only fields that changed in this update.
				if ( ! array_key_exists( 'email_status', $contact->getChanges() ) ) {
					return;
				}

				if ( $contact->email_status === 'unsubscribed' ) {
					do_action( 'doublescale_contact_unsubscribe', $contact );
				}
			}
		);

		// Deleting event
		$dispatcher->listen(
			"eloquent.deleting: {$model_name}",
			function ( $contact ) {
				// Contact children (deals, projects, proposals, tasks) reference the
				// contact without FK constraints; let their modules cascade while the
				// contact row still exists.
				do_action( 'doublescale_contact_deleting', $contact );

				// Delete note activities for this contact (via polymorphic associations).
				ActivityModel::query()
					->whereHas(
						'associations',
						function ( $q ) use ( $contact ) {
							$q->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_CONTACT )
								->where( 'entity_id', $contact->id );
						}
					)
					->where( 'activity_type', 'note' )
					->delete();

				// Remove contact linkage from remaining activities (emails, calls, etc.).
				ActivityAssociationModel::delete_for_contact( (int) $contact->id );

				$contact->automation_contacts()->delete();
			}
		);

		// Retrieved event (if WooCommerce is active)
		if ( doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			$dispatcher->listen(
				"eloquent.retrieved: {$model_name}",
				function ( $contact ) {
					// Only compute revenue when orders are explicitly loaded.
					if ( ! $contact->relationLoaded( 'orders' ) ) {
						return;
					}

					$orders              = $contact->orders;
					$revenue_by_currency = array();

					foreach ( $orders as $order ) {
						$currency = $order->currency ?: \get_woocommerce_currency();
						if ( ! isset( $revenue_by_currency[ $currency ] ) ) {
							$revenue_by_currency[ $currency ] = 0.0;
						}
						$revenue_by_currency[ $currency ] += (float) $order->total_amount;
					}

					if ( empty( $revenue_by_currency ) ) {
						return;
					}

					if ( 1 === count( $revenue_by_currency ) ) {
						$currency = array_key_first( $revenue_by_currency );
						$contact->revenue = number_format( $revenue_by_currency[ $currency ], 2, '.', '' ) . ' ' . $currency;
						return;
					}

					$parts = array();
					foreach ( $revenue_by_currency as $currency => $amount ) {
						$parts[] = number_format( $amount, 2, '.', '' ) . ' ' . $currency;
					}
					$contact->revenue = implode( ' · ', $parts );
				}
			);
		}
	}

	/**
	 * Delete the contact notes boot method
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot() {
		// Use global flag to prevent multiple event registrations
		global $doublescale_contact_events_registered;

		parent::boot();

		if ( $doublescale_contact_events_registered ) {
			return;
		}
		$doublescale_contact_events_registered = true;

		// Get the event dispatcher
		$dispatcher = static::getEventDispatcher();
		if ( ! $dispatcher ) {
			return;
		}

		// Register events directly with the dispatcher
		$model_name = static::class;
		$instance   = new static();
		$instance->registerEventsOnDispatcher( $dispatcher, $model_name );
	}

	/**
	 * Set tracking context for merge tags processing
	 * When set, merge tags will use stored values from communication_tracking_meta
	 *
	 * @param int $tracking_id Communication tracking ID
	 * @return self
	 */
	public function set_tracking_context( $tracking_id ) {
		$this->tracking_context_id = $tracking_id;
		return $this;
	}

	/**
	 * Get current tracking context
	 *
	 * @return int|null Communication tracking ID or null if not set
	 */
	public function get_tracking_context() {
		return $this->tracking_context_id;
	}

	/**
	 * Check if contact has tracking context set
	 *
	 * @return bool
	 */
	public function has_tracking_context() {
		return ! is_null( $this->tracking_context_id );
	}

	/**
	 * Clear tracking context
	 *
	 * @return self
	 */
	public function clear_tracking_context() {
		$this->tracking_context_id = null;
		return $this;
	}
}
