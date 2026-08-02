<?php

/**
 * Class Events_Model
 *
 * This class is responsible for handling the calendar events model
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Models;

defined( 'ABSPATH' ) || exit;

use Illuminate\Support\Arr;

use WPEloquent\Eloquent\Model;
use Illuminate\Support\Str;
use DoubleScale\Modules\Booking\Helpers\IntegrationsHelper;
use DoubleScale\Modules\Booking\Managers\IntegrationsManager;
use DoubleScale\Modules\Booking\Managers\LocationsManager;
use DoubleScale\Modules\Booking\Managers\FieldsManager;
use DoubleScale\Modules\Booking\EventFields\EventFields;
use DoubleScale\Modules\Booking\Services\Availabilities;









/**
 * Calendar Events Model class
 */
class EventModel extends Model {





	/**
	 * Cached processed availability to avoid database updates during computation
	 *
	 * @var array|null
	 */
	private $processed_availability = null;

	/**
	 * Table name
	 *
	 * @var string
	 */
	protected $table = 'doublescale_booking_events';

	/**
	 * Primary key
	 *
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * Timestamps
	 *
	 * @var boolean
	 */
	public $timestamps = true;

	/**
	 * Fillable columns
	 *
	 * @var array
	 */
	protected $fillable = array(
		'hash_id',
		'calendar_id',
		'name',
		'description',
		'slug',
		'status',
		'type',
		'duration',
		'color',
		'visibility',
		'created_at',
		'updated_at',
		'is_disabled',
		'availability_id',
		'availability_meta',
		'availability_type',
	);

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'calendar_id'       => 'integer',
		'is_disabled'       => 'boolean',
		'reserve'           => 'boolean',
		'availability_id'   => 'integer',
		'availability_type' => 'string',
	);

	/**
	 * Rules
	 *
	 * @var array
	 */
	protected $rules = array(
		'calendar_id'       => 'required|integer',
		'name'              => 'required',
		'type'              => 'required',
		'duration'          => 'required',
		'color'             => 'regex:/^#[a-fA-F0-9]{6}$/',
		'availability_id'   => 'required|integer',
		'availability_meta' => 'required|array',
		'availability_type' => 'required|string',
	);

	/**
	 * Messages
	 *
	 * @var array
	 */
	protected $messages = array(
		'calendar_id.required'       => 'Calendar ID is required',
		'calendar_id.integer'        => 'Calendar ID must be an integer',
		'calendar_id.exists'         => 'Calendar ID does not exist',
		'name.required'              => 'Event name is required',
		'type.required'              => 'Event type is required',
		'duration.required'          => 'Event duration is required',
		'settings.location.required' => 'Event location is required',
		'color.regex'                => 'Color must be a valid hex color',
		'availability_id.required'   => 'Availability ID is required',
		'availability_id.integer'    => 'Availability ID must be an integer',
		'availability_meta.required' => 'Availability meta is required',
		'availability_type.required' => 'Availability type is required',
		'availability_type.string'   => 'Availability type must be a string',
	);

	/**
	 * Appends
	 *
	 * @var array
	 */
	protected $appends = array(
		'dynamic_duration',
		'location',
		'additional_settings',
		'group_settings',
		'booking_count',
		'connected_integrations',
		'price',
		'payments_settings',
		'advanced_settings',
		'waiting_list_settings',
		'reserve_times',
	);

	/**
	 * Relationship with calendar
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function calendar() {
		return $this->belongsTo( CalendarModel::class, 'calendar_id', 'id' );
	}

	/**
	 * Relationship with meta
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function meta() {
		return $this->hasMany( EventMetaModel::class, 'event_id' );
	}



	/**
	 * Relationship with bookings
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function bookings() {
		return $this->hasMany( BookingModel::class, 'event_id' );
	}

	/**
	 * Relationship with availability
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function availability() {
		return $this->belongsTo( AvailabilityModel::class, 'availability_id', 'id' );
	}

	/**
	 * Get the fields meta value.
	 *
	 * @return string|null
	 */
	public function getFieldsAttribute() {
		return $this->get_meta( 'fields' );
	}



	/**
	 * Get the availability meta value.
	 *
	 * @return string|null
	 */
	public function getAvailabilityMetaAttribute() {
		if ( ! $this->attributes['availability_meta'] ) {
			return array();
		}

		$data = maybe_unserialize( $this->attributes['availability_meta'] );

		// Handle double serialization case (when controller pre-serializes data)
		if ( is_string( $data ) && is_serialized( $data ) ) {
			$data = maybe_unserialize( $data );
		}

		return $data ?: array();
	}

	/**
	 * Set the event availability meta
	 *
	 * @param array $value
	 * @return void
	 */
	public function setAvailabilityMetaAttribute( $value ) {
		$this->attributes['availability_meta'] = maybe_serialize( $value );
	}

	/**
	 * Set the event location
	 *
	 * @param array $location
	 *
	 * @return void
	 */
	public function setLocationAttribute( array $value ) {
		$event_location = $value ?? null;
		if ( ! $event_location ) {
			return;
		}

		if ( ! is_array( $event_location ) ) {
			throw new \Exception( esc_html__( 'Invalid location', 'doublescale' ) );
		}

		foreach ( $event_location as $index => $location ) {
			$location_type = LocationsManager::instance()->get_location( $location['type'] );
			if ( ! $location_type ) {
				throw new \Exception( esc_html__( 'Location does not exist', 'doublescale' ) );
			}

			$validation = $location_type->validate_fields( $location );
			if ( \is_wp_error( $validation ) ) {
				throw new \Exception( esc_html( $validation->get_error_message() ) );
			}

			$event_location[ $index ] = $validation;
		}

		$this->update_meta( 'location', $event_location );
	}

	/**
	 * Get the event location
	 *
	 * @return array
	 */
	public function getLocationAttribute() {
		return $this->get_meta( 'location', array() );
	}

	/**
	 * Set the event limits
	 *
	 * @param array $value
	 * @return void
	 */
	public function setLimitsAttribute( $value ) {
		$this->update_meta( 'limits', $value );
	}

	/**
	 * Get the event limits
	 *
	 * @return array
	 */
	public function getLimitsAttribute() {
		return $this->get_meta( 'limits', array() );
	}

	/**
	 * Set the event reserve times
	 *
	 * @param bool $value
	 * @return void
	 */
	public function setReserveTimesAttribute( $value ) {
		$this->update_meta( 'reserve_times', (bool) $value );
	}

	/**
	 * Get the event reserve times
	 *
	 * @return bool
	 */
	public function getReserveTimesAttribute() {
		return (bool) $this->get_meta( 'reserve_times', false );
	}

	/**
	 * Get the event team members
	 *
	 * @return array
	 */
	public function getTeamMembersAttribute() {
		return $this->get_meta( 'team_members', array() );
	}

	/**
	 * Set the event team members
	 *
	 * @param array $value
	 * @return void
	 */
	public function setTeamMembersAttribute( $value ) {
		$this->update_meta( 'team_members', $value );
	}

	/**
	 * Get the event email notifications
	 *
	 * @return array
	 */
	public function getEmailNotificationsAttribute() {
		return $this->get_meta( 'email_notifications', array() );
	}

	/**
	 * Set the event email notifications
	 *
	 * @param array $value
	 * @return void
	 */
	public function setEmailNotificationsAttribute( $value ) {
		$this->update_meta( 'email_notifications', $value );
	}

	/**
	 * Get the event SMS notifications
	 *
	 * @return array
	 */
	public function getSmsNotificationsAttribute() {
		return $this->get_meta( 'sms_notifications', array() );
	}

	/**
	 * Set the event SMS notifications
	 *
	 * @param array $value
	 * @return void
	 */
	public function setSmsNotificationsAttribute( $value ) {
		$this->update_meta( 'sms_notifications', $value );
	}

	/**
	 * Get the event additional settings
	 *
	 * @return array
	 */
	public function getAdditionalSettingsAttribute() {
		return $this->get_meta( 'additional_settings', array() );
	}

	/**
	 * Set the event additional settings
	 *
	 * @param array $value
	 * @return void
	 */
	public function setAdditionalSettingsAttribute( $value ) {
		$this->update_meta( 'additional_settings', $value );
	}

	/**
	 * Get the event group settings
	 *
	 * @return array
	 */
	public function getGroupSettingsAttribute() {
		return $this->get_meta( 'group', array() );
	}

	/**
	 * Set the event group settings
	 *
	 * @param array $value
	 * @return void
	 */
	public function setGroupSettingsAttribute( $value ) {
		$this->update_meta( 'group', $value );
	}

	/**
	 * Get the event range
	 *
	 * @return array
	 */
	public function getEventRangeAttribute() {
		return $this->get_meta( 'event_range', array() );
	}

	/**
	 * Set the event range
	 *
	 * @param array $value
	 * @return void
	 */
	public function setEventRangeAttribute( $value ) {
		$this->update_meta( 'event_range', $value );
	}

	/**
	 * Get the event advanced settings
	 *
	 * @return array
	 */
	public function getAdvancedSettingsAttribute() {
		return $this->get_meta( 'advanced_settings', array() );
	}

	/**
	 * Set the event advanced settings
	 *
	 * @param array $value
	 * @return void
	 */
	public function setAdvancedSettingsAttribute( $value ) {
		$this->update_meta( 'advanced_settings', $value );
	}

	/**
	 * Get waiting list settings.
	 *
	 * @return array
	 */
	public function getWaitingListSettingsAttribute() {
		$defaults = array(
			'enabled'                 => false,
			'capacity'                => 10,
			'auto_notify'             => false,
			'limit_additional_people' => false,
			'additional_people_limit' => 0,
			'redirect_url_denied'     => '',
			'redirect_url_success'    => '',
		);
		$saved    = $this->get_meta( 'waiting_list', array() );

		return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Set waiting list settings.
	 *
	 * @param array $value
	 * @return void
	 */
	public function setWaitingListSettingsAttribute( $value ) {
		$this->update_meta( 'waiting_list', $value );
	}

	/**
	 * Get the event payments settings
	 *
	 * @return array
	 */
	public function getPaymentsSettingsAttribute() {
		return $this->get_meta( 'payments_settings', array() );
	}

	/**
	 * Set the event payments settings
	 *
	 * @param array $value
	 * @return void
	 */
	public function setPaymentsSettingsAttribute( $value ) {
		$this->update_meta( 'payments_settings', $value );
	}

	/**
	 * Get dynamic duration
	 *
	 * @return bool
	 */
	public function getDynamicDurationAttribute() {
		return $this->get_meta( 'dynamic_duration', false );
	}

	/**
	 * Set dynamic duration
	 *
	 * @param bool $value
	 * @return void
	 */
	public function setDynamicDurationAttribute( $value ) {
		$this->update_meta( 'dynamic_duration', $value );
	}

	/**
	 * Get booking count
	 *
	 * @return int
	 */
	public function getBookingCountAttribute() {
		return $this->bookings()
			->whereNotIn( 'status', BookingModel::NON_ACTIVE_STATUSES )
			->count();
	}

	/**
	 * Get Price attribute
	 *
	 * @return float
	 */
	public function getPriceAttribute() {
		$payments_enabled = $this->requirePayment();
		if ( ! $payments_enabled ) {
			return 0;
		}

		$items = $this->getItems();
		if ( empty( $items ) ) {
			return 0;
		}

		$total_price    = 0;
		$multi_duration = Arr::get( $this->additional_settings, 'allow_attendees_to_select_duration', false );
		if ( $multi_duration ) {
			$total_price = Arr::get( $this->payments, 'multi_duration_items.0.price', 0 );
		} else {
			foreach ( $items as $item ) {
				$total_price += $item['price'];
			}
		}

		return $total_price;
	}

	/**
	 * Check if integrations are available
	 *
	 * @return bool
	 */
	private function has_integrations() {
		return IntegrationsHelper::has_integrations();
	}

	/**
	 * Get Connected Integrations
	 *
	 * @return array
	 */
	public function getConnectedIntegrationsAttribute() {
		$connected_integrations = array();

		if ( ! IntegrationsHelper::has_integrations() ) {
			return IntegrationsHelper::get_default_integrations();
		}

		$integrations = IntegrationsManager::instance()->get_integrations();

		$calendar_ids = array( $this->calendar_id );
		if ( in_array( $this->calendar->type, array( 'team' ), true ) ) {
			// Use hosts assigned to this event (team_members meta), not the whole calendar roster.
			// Matches calendars/:id/integrations?host_user_ids=… used when creating/editing team events.
			$event_hosts = array_map(
				'intval',
				array_filter( (array) $this->getTeamMembersAttribute() )
			);
			if ( ! empty( $event_hosts ) ) {
				$calendar_ids = $event_hosts;
			} else {
				$calendar_ids = array_map(
					'intval',
					array_filter( (array) $this->calendar->getTeamMembers() )
				);
			}
		}

		foreach ( $integrations as $integration_key => $integration_class ) {
			// It's a class name, instantiate it
			$integration = new $integration_class();
			$slug        = $integration->slug;
			$name        = $integration->name;

			$all_connected       = true;
			$has_accounts        = false;
			$global_settings     = $integration->get_settings();
			$set_global_settings = false;
			$teams_enabled       = false;
			$has_get_started     = false;
			$has_pro_version     = true;
			$team_members_setup  = true;

			if ( $slug == 'zoom' ) {
				$app_credentials = Arr::get( $global_settings, 'app_credentials', null );
				if ( $app_credentials && is_array( $app_credentials ) && ! empty( $app_credentials['client_id'] ) && ! empty( $app_credentials['client_secret'] ) ) {
					$set_global_settings = true;
				} else {
					$set_global_settings = false;
				}
			} else {
				$app = Arr::get( $global_settings, 'app', null );
				if ( $app && is_array( $app ) && ! empty( $app['cache_time'] ) ) {
					$set_global_settings = true;
				} else {
					$set_global_settings = false;
				}
			}

			foreach ( $calendar_ids as $member_or_calendar_id ) {
				if ( $this->calendar->type === 'team' ) {
					$calendar = CalendarModel::where( 'user_id', $member_or_calendar_id )->where( 'type', 'host' )->first();
					if ( ! $calendar ) {
						$all_connected      = false;
						$team_members_setup = false;
						continue;
					}
				} else {
					$calendar = CalendarModel::find( $member_or_calendar_id );
				}

				if ( $calendar ) {
					$integration->set_host( $calendar );

					$accounts            = $integration->accounts->get_accounts();
					$has_stored_accounts = IntegrationsHelper::calendar_meta_has_integration_accounts( $accounts );

					if ( ! $has_stored_accounts ) {
						$all_connected = false;
						if ( in_array( $this->calendar->type, array( 'team' ), true ) && $slug !== 'zoom' ) {
							$team_members_setup = false;
						}
					} else {
						$has_accounts = true;
						if ( $slug === 'outlook' && ! in_array( $this->calendar->type, array( 'team' ), true ) ) {
							foreach ( $accounts as $account ) {
								if ( isset( $account['config']['default_calendar'] ) ) {
									$teams_enabled = isset( $account['config']['settings']['enable_teams'] ) &&
										$account['config']['settings']['enable_teams'] === true;
									break;
								}
							}
						}

						if ( in_array( $this->calendar->type, array( 'team' ), true ) && $slug !== 'zoom' ) {
							$has_default_calendar = false;
							foreach ( $accounts as $account ) {
								if (
									isset( $account['config']['default_calendar'] ) &&
									! empty( $account['config']['default_calendar']['calendar_id'] )
								) {
									$has_default_calendar = true;
									break;
								}
							}
							if ( ! $has_default_calendar ) {
								$team_members_setup = false;
							}
							if ( $slug === 'outlook' ) {
								$member_teams_enabled = false;
								foreach ( $accounts as $account ) {
									if (
										isset( $account['config']['settings']['enable_teams'] ) &&
										$account['config']['settings']['enable_teams'] === true
									) {
										$member_teams_enabled = true;
										break;
									}
								}
								if ( ! $member_teams_enabled ) {
									$team_members_setup = false;
								}
							}
						}
					}

					if ( in_array( $this->calendar->type, array( 'team' ), true ) && $slug === 'zoom' ) {
						$has_zoom_ready = false;
						foreach ( $accounts as $account ) {
							if ( IntegrationsHelper::zoom_account_ready_for_conferencing( $account ) ) {
								$has_zoom_ready = true;
								break;
							}
						}
						if ( ! $has_zoom_ready ) {
							$team_members_setup = false;
						}
					}
				} else {
					$all_connected = false;
				}
			}

			if ( $this->calendar->type === 'team' ) {
				$teams_enabled = true;
			}

			$connected_integrations[ $slug ] = array(
				'name'               => $name,
				'connected'          => $all_connected,
				'has_accounts'       => $has_accounts,
				'has_settings'       => $set_global_settings,
				'teams_enabled'      => $teams_enabled,
				'has_get_started'    => $has_get_started,
				'has_pro_version'    => $has_pro_version,
				'team_members_setup' => $team_members_setup,
			);
		}

		return $connected_integrations;
	}

	/**
	 * Process fields based on event location
	 *
	 * @param array $event_location
	 * @param array $existing_fields
	 *
	 * @return array Processed fields
	 */
	private function processLocationFields( $event_location ) {
		$fields = array();
		if ( 1 <= count( $event_location ) ) {
			$fields['location-select'] = array(
				'type'     => 'radio',
				'label'    => __( 'Location', 'doublescale' ),
				'required' => true,
				'group'    => 'system',
				'options'  => array(),
			);
		}

		foreach ( $event_location as $location ) {
			$location_manager = LocationsManager::instance();
			$location_type    = $location_manager->get_location( $location['type'] );

			if ( ! $location_type ) {
				throw new \Exception( esc_html__( 'Location does not exist', 'doublescale' ) );
			}

			$validation = $location_type->validate_fields( $location );
			if ( \is_wp_error( $validation ) ) {
				throw new \Exception( esc_html( $validation->get_error_message() ) );
			}

			$fields['location-select']['options'][] = array(
				'label'  => $location_manager->get_location_label( $location ),
				'value'  => $location['type'] === 'custom' ? $location['id'] : $location['type'],
				'fields' => $location_type->get_fields( $location['fields'] ?? array() ),
			);
		}

		return $fields;
	}

	/**
	 * Set fields
	 *
	 * @return void
	 */
	public function setSystemFields() {
		$event_location = $this->location ?? null;

		if ( ! $event_location || ! is_array( $event_location ) ) {
			throw new \Exception( esc_html__( 'Invalid location', 'doublescale' ) );
		}

		$system_fields   = EventFields::instance()->get_system_fields();
		$other_fields    = EventFields::instance()->get_other_fields();
		$location_fields = $this->processLocationFields( $event_location );
		$fields          = array(
			'system'   => $system_fields,
			'location' => $location_fields,
			'other'    => $other_fields,
		);

		$this->update_meta( 'fields', $fields );
	}

	/**
	 * Update fields
	 *
	 * @return void
	 */
	public function updateSystemFields() {
		$event_location = $this->location ?? null;

		if ( ! $event_location || ! is_array( $event_location ) ) {
			throw new \Exception( esc_html__( 'Invalid location', 'doublescale' ) );
		}

		$fields             = $this->fields ?? array();
		$location_fields    = $this->processLocationFields( $event_location );
		$fields['location'] = $location_fields;

		$this->update_meta( 'fields', $fields );
	}

	/**
	 * Update fields
	 *
	 * @param array $fields
	 *
	 * @return void
	 */
	public function updateFields( $fields ) {
		$current_fields = $fields ?? array();

		foreach ( $fields as $group => $group_fields ) {
			foreach ( $group_fields as $field_key => $field ) {
				if ( in_array( $group, array( 'system', 'location' ), true ) ) {
					// Keep existing fields and update only label, helpText, and placeholder
					if ( isset( $current_fields[ $group ][ $field_key ] ) ) {
						$current_fields[ $group ][ $field_key ] = array_merge(
							$current_fields[ $group ][ $field_key ],
							array_intersect_key(
								$field,
								array_flip( array( 'label', 'helpText', 'placeholder', 'hidden' ) )
							)
						);
					}
				} else {
					$field_type = FieldsManager::instance()->get_item( $field['type'] );
					$field_type = new $field_type();

					if ( $field_type->has_options && ! isset( $field['settings']['options'] ) ) {
						/* translators: %s: field label */
						throw new \Exception( esc_html( sprintf( __( 'Options are required for %s field', 'doublescale' ), $field['label'] ) ) );
					}

					$current_fields[ $group ][ $field_key ] = $field;
				}
			}
		}
		$this->update_meta( 'fields', $current_fields );
	}

	/**
	 * Get meta value
	 *
	 * @param string $key Meta key.
	 * @param mixed  $default Default value.
	 *
	 * @return mixed
	 */
	public function get_meta( $key, $default = null ) {
		$meta = $this->meta()->where( 'meta_key', $key )->first();
		$meta = $meta ? maybe_unserialize( $meta->meta_value ) : $default;

		return $meta;
	}

	/**
	 * Update meta value
	 *
	 * @param string $key Meta key.
	 * @param mixed  $value Meta value.
	 *
	 * @return void
	 */
	public function update_meta( $key, $value ) {
		$meta = $this->meta()->firstOrNew(
			array(
				'meta_key' => $key,
			)
		);

		$meta->meta_value = maybe_serialize( $value );
		$meta->save();
	}

	/**
	 * Duplicate event
	 *
	 * @return EventModel
	 */
	public function duplicate() {
		$event          = $this->replicate();
		$event->name    = $event->name . ' - ' . __( 'Copy', 'doublescale' );
		$event->hash_id = Str::random( 32 );
		$event->save();

		$meta = $this->meta()->get();
		foreach ( $meta as $meta_item ) {
			if ( 'webhook_feeds' === $meta_item->meta_key ) {
				continue;
			}
			$event->meta()->create(
				array(
					'meta_key'   => $meta_item->meta_key,
					'meta_value' => $meta_item->meta_value,
				)
			);
		}

		return $event;
	}

	/**
	 * Check if reached minimum notice
	 *
	 * @param string $start_date Booking start date
	 *
	 * @return bool
	 */
	public function requireConfirmation( $start_date ) {
		$require_confirmation = Arr::get( $this->advanced_settings, 'require_confirmation', false );
		if ( ! $require_confirmation ) {
			return false;
		}

		$confirmation_time = Arr::get( $this->advanced_settings, 'confirmation_time', 'always' );
		if ( 'always' === $confirmation_time ) {
			return true;
		}

		$confirmation_time_value = Arr::get( $this->advanced_settings, 'confirmation_time_value', 20 );
		$confirmation_time_unit  = Arr::get( $this->advanced_settings, 'confirmation_time_unit', 'minutes' );

		$start_date = new \DateTime( $start_date, new \DateTimeZone( 'UTC' ) );
		$now        = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

		if ( 'hours' === $confirmation_time_unit ) {
			$confirmation_time_value *= 60;
		}

		return $start_date->getTimestamp() - $now->getTimestamp() >= $confirmation_time_value;
	}

	/**
	 * Check if payment is required
	 *
	 * @return boolean
	 */
	public function requirePayment() {
		$payments_settings = $this->payments_settings;
		if ( ! $payments_settings ) {
			return false;
		}

		$enabled = Arr::get( $payments_settings, 'enable_payment', false );
		if ( ! $enabled ) {
			return false;
		}

		$items = Arr::get( $payments_settings, 'items', array() );
		if ( empty( $items ) ) {
			return false;
		}

		// Native payment requires registered gateways. Stripe is the only one bundled today;
		// add them via the doublescale_booking_payment_gateways filter (each entry is an
		// object/array with a `slug` property matching the `enable_<slug>` key in payments_settings).
		$payment_gateways = (array) apply_filters( 'doublescale_booking_payment_gateways', array() );

		if ( empty( $payment_gateways ) ) {
			return false;
		}

		$gateway_enabled = false;
		foreach ( $payment_gateways as $gateway ) {
			$slug = is_object( $gateway ) ? ( $gateway->slug ?? '' ) : ( $gateway['slug'] ?? '' );
			if ( $slug && Arr::get( $payments_settings, 'enable_' . $slug, false ) ) {
				$gateway_enabled = true;
				break;
			}
		}

		if ( ! $gateway_enabled ) {
			return false;
		}

		return (bool) apply_filters( 'doublescale_booking_payment_available', true );
	}

	/**
	 * Get total price
	 *
	 * @return float
	 */
	public function getTotalPrice() {
		$payments_settings = $this->payments_settings;
		if ( ! $payments_settings ) {
			return 0;
		}

		$items = Arr::get( $payments_settings, 'items', array() );
		if ( empty( $items ) ) {
			return 0;
		}

		$total_price = 0;
		foreach ( $items as $item ) {
			$total_price += $item['price'];
		}

		return $total_price;
	}

	/**
	 * Get items
	 *
	 * @return array
	 */
	public function getItems() {
		$payments_settings = $this->payments_settings;
		if ( ! $payments_settings ) {
			return array();
		}

		$items = Arr::get( $payments_settings, 'items', array() );
		if ( empty( $items ) ) {
			return array();
		}

		return $items;
	}

	/**
	 * Fetch available slots based on provided parameters.
	 *
	 * @param string   $start_date         Date string accepted by DateTime (e.g. `Y-m-d` or `Y-m-d H:i:s`). Numeric timestamps are NOT accepted.
	 * @param string   $timezone           Timezone identifier.
	 * @param int      $duration           Duration of each slot in minutes.
	 * @param int|null $user_id          Optional host user ID; defaults to the event's own user(s).
	 * @param bool     $include_full_slots Optional. Include fully-booked slots (e.g. for waiting-list rendering). Default false.
	 * @return array<string,array<int,array>> Slots grouped by date key (Y-m-d).
	 */
	public function get_available_slots( $start_date, $timezone, $duration, $user_id = null, $include_full_slots = false ) {
		$this->validate_availability( $user_id );

		$start_date = $this->adjust_start_date( $start_date, $timezone, $duration );
		$end_date   = $this->calculate_end_date( $start_date, $timezone );

		$event_date_type = Arr::get( $this->event_range, 'type', 'days' );
		if ( 'infinity' === $event_date_type ) {
			// If user is browsing future months, start from the requested month
			$requested_date = new \DateTime( gmdate( 'Y-m-d', $start_date ), new \DateTimeZone( $timezone ) );

			// Get first day of next month from the requested date
			$month_end = clone $requested_date;
			$month_end->modify( 'last day of +2 month' );
			$month_end->setTime( 23, 59, 59 );

			// Use the smaller of the calculated end date or two months ahead
			$month_end_timestamp = $month_end->getTimestamp();
			if ( $month_end_timestamp < $end_date ) {
				$end_date = $month_end_timestamp;
			}
		}

		$slots = $this->generate_daily_slots( $start_date, $end_date, $timezone, $duration, $user_id, $include_full_slots );

		return \apply_filters( 'doublescale_booking_get_available_slots', $slots, $this, $start_date, $end_date, $timezone );
	}

	/**
	 * Validate availability data and weekly hours.
	 */
	private function validate_availability( $user_id = null ) {
		// Use cached processed availability if available
		if ( $this->processed_availability !== null ) {
			$availability = $this->processed_availability;
		} else {
			$availability = $this->getTeamAvailability( $this->availability, $user_id );
			// Cache the processed availability to avoid repeated processing
			$this->processed_availability = $availability;
		}

		if ( ! $availability ) {
			// Try to get a system default availability as fallback
			$default_availability = Availabilities::get_system_availability();
			if ( $default_availability ) {
				// Only save to database if we're in a context where this is appropriate
				// For now, we'll just use it without saving to avoid unintended database updates
				$this->processed_availability = $default_availability;
				$availability                 = $default_availability;
			} else {
				throw new \Exception( esc_html__( 'Availability not set', 'doublescale' ) );
			}
		}

		$weekly_hours = $availability['weekly_hours'] ?? array();
		if ( empty( $weekly_hours ) ) {
			// Try to get system default availability as fallback
			$default_availability = Availabilities::get_system_availability();
			if ( $default_availability && ! empty( $default_availability['weekly_hours'] ) ) {
				// Only save to database if we're in a context where this is appropriate
				// For now, we'll just use it without saving to avoid unintended database updates
				$this->processed_availability = $default_availability;
			} else {
				throw new \Exception( esc_html__( 'Weekly hours are not set', 'doublescale' ) );
			}
		}
	}

	/**
	 * Get the effective availability (processed if available, otherwise original)
	 *
	 * @return array The availability array to use
	 */
	private function get_effective_availability() {
		$availability = $this->processed_availability !== null ? $this->processed_availability : $this->availability;

		// Coerce stdClass leaves (from json_decode) to nested arrays so
		// downstream array-bracket access is safe.
		if ( is_object( $availability ) ) {
			$availability = (array) $availability;
		}
		if ( is_array( $availability ) ) {
			foreach ( array( 'weekly_hours', 'override' ) as $key ) {
				if ( isset( $availability[ $key ] ) && is_object( $availability[ $key ] ) ) {
					$availability[ $key ] = json_decode( wp_json_encode( $availability[ $key ] ), true );
				}
			}
		}

		return $availability;
	}

	/**
	 * Clear the processed availability cache
	 * This should be called when the availability data changes
	 *
	 * @return void
	 */
	public function clear_availability_cache() {
		$this->processed_availability = null;
	}

	/**
	 * Set the event availability and clear cache
	 *
	 * @param array $value
	 * @return void
	 */
	public function setAvailabilityAttribute( $value ) {
		$this->clear_availability_cache();
		$this->update_meta( 'availability', $value );
	}

	/**
	 * WordPress user IDs to use for team round-robin / collective slot checks.
	 * Prefer hosts saved on the event (team_members meta), then per-host schedules,
	 * then the calendar roster — consistent with getConnectedIntegrationsAttribute().
	 *
	 * @return int[]
	 */
	private function get_team_scheduling_member_ids() {
		if ( $this->calendar->type !== 'team' ) {
			return array();
		}

		$event_hosts = array_map(
			'intval',
			array_filter( (array) $this->getTeamMembersAttribute() )
		);
		if ( ! empty( $event_hosts ) ) {
			return array_values( array_unique( $event_hosts ) );
		}

		$hosts_schedules = $this->availability_meta['hosts_schedules'] ?? array();
		if ( ! empty( $hosts_schedules ) && is_array( $hosts_schedules ) ) {
			return array_values(
				array_unique(
					array_map(
						'intval',
						array_keys( $hosts_schedules )
					)
				)
			);
		}

		return array_values(
			array_unique(
				array_map(
					'intval',
					array_filter( (array) $this->calendar->getTeamMembers() )
				)
			)
		);
	}

	private function getTeamAvailability( $availability, $user_id = null ) {
		$type          = $this->availability_type;
		$is_common     = $this->availability_meta['is_common'] ?? false;
		$calendar_type = $this->calendar->type;

		// Normalise to a plain array so this method can set keys freely.
		if ( $availability instanceof AvailabilityModel ) {
			$availability = $availability->toCompatibleArray();
		} elseif ( is_object( $availability ) ) {
			$availability = (array) $availability;
		}

		if ( $calendar_type === 'team' ) {
			if ( $type === 'existing' && $is_common == false ) {
				$availabilities  = array();
				$hosts_schedules = $this->availability_meta['hosts_schedules'] ?? array();
				foreach ( $hosts_schedules as $member_id => $schedule_id ) {
					$user_avail = Availabilities::get_availability( $schedule_id );
					if ( $user_avail ) {
						$user_avail['user_id'] = (int) $member_id;
						$availabilities[]      = $user_avail;
					}
				}

				if ( $user_id && $this->type === 'round-robin' ) {
					$wanted_ids = is_array( $user_id ) ? array_map( 'intval', $user_id ) : array( (int) $user_id );
					$filtered   = array_values(
						array_filter(
							$availabilities,
							function ( $avail ) use ( $wanted_ids ) {
								return isset( $avail['user_id'] ) && in_array( (int) $avail['user_id'], $wanted_ids, true );
							}
						)
					);
					if ( ! empty( $filtered ) ) {
						// Replace the collected availabilities with the filtered
						// set so downstream consumers (users_availability,
						// merge helpers, slot loops) only see the targeted host.
						$availabilities               = $filtered;
						$first                        = $filtered[0];
						$availability['weekly_hours'] = $first['weekly_hours'];
						$availability['override']     = $first['override'];
						$availability['timezone']     = $first['timezone'];
					} else {
						// Requested host isn't on this event — surface that as
						// an empty schedule instead of silently falling back to
						// the event's primary availability, which would otherwise
						// expose slots that don't belong to that host.
						doublescale_get_logger()->warning(
							'Round-robin availability requested for a host not in hosts_schedules',
							array(
								'source'   => 'booking-event-model',
								'event_id' => (int) $this->id,
								'host_id'  => is_array( $user_id ) ? array_map( 'intval', $user_id ) : (int) $user_id,
							)
						);
						$availabilities               = array();
						$availability['weekly_hours'] = array();
						$availability['override']     = array();
					}
				} elseif ( count( $availabilities ) > 0 ) {
					if ( $this->type === 'collective' ) {
						// Collective: only times when ALL hosts are free (intersection).
						$merged = $this->findIntersectingTeamAvailability( $availabilities );
					} else {
						// Round-robin (non-common): union — candidate slots cover any host's hours.
						$merged = $this->findCommonTeamAvailability( $availabilities );
					}
					$availability['weekly_hours'] = $merged['weekly_hours'];
					if ( ! empty( $merged['timezone'] ) ) {
						$availability['timezone'] = $merged['timezone'];
					}
					$availability['override'] = $merged['override'] ?? array();
				}
				$availability['users_availability'] = $availabilities;
				$availability['is_common']          = false;
			} else {
				if ( $type === 'existing' ) {
					$availability = Availabilities::get_availability( $this->availability_id );
				} elseif ( $type === 'custom' ) {
					$availability = array(
						'name'         => $this->availability_meta['custom_availability']['name'] ?? '',
						'weekly_hours' => $this->availability_meta['custom_availability']['value']['weekly_hours'] ?? array(),
						'override'     => $this->availability_meta['custom_availability']['value']['override'] ?? array(),
						'timezone'     => $this->calendar->get_meta( 'timezone' ) ?? 'UTC',
					);
				}
				$availability['is_common']          = true;
				$availability['users_availability'] = array();
			}
		} elseif ( $type === 'existing' ) {
				$availability = Availabilities::get_availability( $this->availability_id );
		} elseif ( $type === 'custom' ) {
			$availability = array(
				'name'         => $this->availability_meta['custom_availability']['name'] ?? '',
				'weekly_hours' => $this->availability_meta['custom_availability']['value']['weekly_hours'] ?? array(),
				'override'     => $this->availability_meta['custom_availability']['value']['override'] ?? array(),
				'timezone'     => $this->calendar->get_meta( 'timezone' ) ?? 'UTC',
			);
		}
		return $availability;
	}

	/**
	 * Parse availability value
	 *
	 * @param array $value
	 * @return array
	 */
	private function parseAvailabilityValue( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		return json_decode( $value, true );
	}

	/**
	 * Merge team member availabilities using union logic (combine all available slots).
	 *
	 * @param array $availabilities Array of user availabilities to merge
	 * @return array Merged availability structure
	 */
	private function findCommonTeamAvailability( $availabilities ) {
		if ( empty( $availabilities ) ) {
			return array();
		}

		// Initialize merged structure
		$first_availability = $availabilities[0];
		$merged             = array(
			'weekly_hours' => array(),
			'timezone'     => $first_availability['timezone'] ?? 'UTC',
			'override'     => array(),
		);

		// Days of the week
		$days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

		// For each day, combine availability from all users (union)
		foreach ( $days as $day ) {
			$merged['weekly_hours'][ $day ] = array(
				'times' => array(),
				'off'   => true, // Start as off, will be set to false if ANY user is available
			);

			$all_time_blocks    = array();
			$any_user_available = false;

			foreach ( $availabilities as $user_availability ) {
				$day_schedule = $user_availability['weekly_hours'][ $day ] ?? array();

				// If user is available this day (off = false), collect their time blocks
				if ( empty( $day_schedule['off'] ) ) {
					$any_user_available = true;
					$times              = $day_schedule['times'] ?? array();

					foreach ( $times as $time_block ) {
						$all_time_blocks[] = array(
							'start' => $time_block['start'],
							'end'   => $time_block['end'],
						);
					}
				}
			}

			// If any user is available, merge their time blocks
			if ( $any_user_available ) {
				$merged['weekly_hours'][ $day ]['off']   = false;
				$merged['weekly_hours'][ $day ]['times'] = $this->mergeTimeBlocks( $all_time_blocks );
			}
		}

		// Merge override dates from all users (union)
		foreach ( $availabilities as $user_availability ) {
			$user_overrides = $user_availability['override'] ?? array();
			foreach ( $user_overrides as $date => $time_blocks ) {
				if ( ! isset( $merged['override'][ $date ] ) ) {
					$merged['override'][ $date ] = array();
				}
				foreach ( $time_blocks as $block ) {
					$merged['override'][ $date ][] = $block;
				}
			}
		}

		// Clean up merged overrides by merging overlapping blocks
		foreach ( $merged['override'] as $date => $blocks ) {
			$merged['override'][ $date ] = $this->mergeTimeBlocks( $blocks );
		}

		return $merged;
	}

	/**
	 * Merge team member availabilities using intersection (only times when EVERY host is free).
	 * Used for collective events when hosts use separate schedules (non-common).
	 *
	 * @param array $availabilities Array of user availabilities to merge.
	 * @return array Merged availability structure.
	 */
	private function findIntersectingTeamAvailability( $availabilities ) {
		if ( empty( $availabilities ) ) {
			return array();
		}

		$first_availability = $availabilities[0];
		$merged             = array(
			'weekly_hours' => array(),
			'timezone'     => $first_availability['timezone'] ?? 'UTC',
			'override'     => array(),
		);

		$days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

		foreach ( $days as $day ) {
			$user_blocks_arrays = array();

			foreach ( $availabilities as $user_availability ) {
				$day_schedule = $user_availability['weekly_hours'][ $day ] ?? array();

				if ( ! empty( $day_schedule['off'] ) ) {
					$user_blocks_arrays = array();
					break;
				}

				$blocks = array();
				foreach ( $day_schedule['times'] ?? array() as $time_block ) {
					$blocks[] = array(
						'start' => $time_block['start'],
						'end'   => $time_block['end'],
					);
				}
				$user_blocks_arrays[] = $blocks;
			}

			if ( count( $user_blocks_arrays ) !== count( $availabilities ) ) {
				$merged['weekly_hours'][ $day ] = array(
					'times' => array(),
					'off'   => true,
				);
				continue;
			}

			$common                         = $this->findCommonTimeBlocks( $user_blocks_arrays );
			$merged['weekly_hours'][ $day ] = array(
				'times' => array_map(
					function ( $b ) {
						return array(
							'start' => $b['start'],
							'end'   => $b['end'],
						);
					},
					$common
				),
				'off'   => empty( $common ),
			);
		}

		$all_dates = array();
		foreach ( $availabilities as $user_availability ) {
			foreach ( array_keys( $user_availability['override'] ?? array() ) as $date_key ) {
				$all_dates[ $date_key ] = true;
			}
		}

		foreach ( array_keys( $all_dates ) as $date_formatted ) {
			$user_blocks_for_date = array();
			foreach ( $availabilities as $user_availability ) {
				$user_blocks_for_date[] = $this->getTimeBlocksForUserOnCalendarDate( $user_availability, $date_formatted );
			}
			$common = $this->findCommonTimeBlocks( $user_blocks_for_date );
			if ( ! empty( $common ) ) {
				$merged['override'][ $date_formatted ] = array_map(
					function ( $b ) {
						return array(
							'start' => $b['start'],
							'end'   => $b['end'],
						);
					},
					$common
				);
			}
		}

		return $merged;
	}

	/**
	 * Normalized time blocks for a user on a calendar date (override wins; else weekly for that weekday).
	 *
	 * @param array  $user_availability User availability payload.
	 * @param string $date_formatted    Y-m-d.
	 * @return array List of blocks with start/end keys.
	 */
	private function getTimeBlocksForUserOnCalendarDate( array $user_availability, $date_formatted ) {
		if ( isset( $user_availability['override'][ $date_formatted ] ) ) {
			$blocks = array();
			foreach ( $user_availability['override'][ $date_formatted ] as $time_block ) {
				$blocks[] = array(
					'start' => $time_block['start'],
					'end'   => $time_block['end'],
				);
			}
			return $blocks;
		}

		$member_tz    = new \DateTimeZone( $user_availability['timezone'] ?? 'UTC' );
		$midday       = new \DateTime( $date_formatted . ' 12:00:00', $member_tz );
		$dow          = strtolower( $midday->format( 'l' ) );
		$day_schedule = $user_availability['weekly_hours'][ $dow ] ?? array();

		if ( ! empty( $day_schedule['off'] ) ) {
			return array();
		}

		$blocks = array();
		foreach ( $day_schedule['times'] ?? array() as $time_block ) {
			$blocks[] = array(
				'start' => $time_block['start'],
				'end'   => $time_block['end'],
			);
		}

		return $blocks;
	}

	/**
	 * Find common time blocks where ALL users are available (intersection).
	 *
	 * @param array $user_time_blocks Array of arrays, each containing time blocks for a user
	 * @return array Common time blocks
	 */
	private function findCommonTimeBlocks( $user_time_blocks ) {
		if ( empty( $user_time_blocks ) ) {
			return array();
		}

		// Start with the first user's time blocks
		$common_blocks = $user_time_blocks[0];

		// For each subsequent user, find intersections
		for ( $i = 1; $i < count( $user_time_blocks ); $i++ ) {
			$common_blocks = $this->intersectTimeBlocks( $common_blocks, $user_time_blocks[ $i ] );

			// If no common blocks remain, no point continuing
			if ( empty( $common_blocks ) ) {
				break;
			}
		}

		return $common_blocks;
	}

	/**
	 * Find intersection between two sets of time blocks.
	 *
	 * @param array $blocks1 First set of time blocks
	 * @param array $blocks2 Second set of time blocks
	 * @return array Intersecting time blocks
	 */
	private function intersectTimeBlocks( $blocks1, $blocks2 ) {
		$intersections = array();

		foreach ( $blocks1 as $block1 ) {
			foreach ( $blocks2 as $block2 ) {
				$intersection = $this->getTimeBlockIntersection( $block1, $block2 );
				if ( $intersection ) {
					$intersections[] = $intersection;
				}
			}
		}

		// Merge any overlapping intersections
		return $this->mergeTimeBlocks( $intersections );
	}

	/**
	 * Get intersection between two time blocks.
	 *
	 * Compares blocks as "minutes from midnight" rather than via strtotime()
	 * on a bare "HH:MM" string. strtotime() would resolve the time against
	 * today's local date on the host, which is unstable across DST
	 * transitions and across server timezones — two perfectly identical
	 * "09:00" blocks could come out off by an hour on the spring-forward
	 * day. Minute math is timezone-independent and safe.
	 *
	 * @param array $block1 First time block
	 * @param array $block2 Second time block
	 * @return array|null Intersection block or null if no overlap
	 */
	private function getTimeBlockIntersection( $block1, $block2 ) {
		$start1 = $this->time_to_minutes( $block1['start'] );
		$end1   = $this->time_to_minutes( $block1['end'] );
		$start2 = $this->time_to_minutes( $block2['start'] );
		$end2   = $this->time_to_minutes( $block2['end'] );

		$overlap_start = max( $start1, $start2 );
		$overlap_end   = min( $end1, $end2 );

		if ( $overlap_start >= $overlap_end ) {
			return null;
		}

		return array(
			'start' => $this->minutes_to_time( $overlap_start ),
			'end'   => $this->minutes_to_time( $overlap_end ),
		);
	}

	/**
	 * Convert a wall-clock "HH:MM" / "HH:MM:SS" string to minutes from midnight.
	 *
	 * @param string $time
	 * @return int
	 */
	private function time_to_minutes( $time ) {
		$parts = explode( ':', (string) $time );
		$h     = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$m     = isset( $parts[1] ) ? (int) $parts[1] : 0;
		return ( $h * 60 ) + $m;
	}

	/**
	 * Format minutes-from-midnight back to "HH:MM".
	 *
	 * @param int $minutes
	 * @return string
	 */
	private function minutes_to_time( $minutes ) {
		$minutes = max( 0, (int) $minutes );
		return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
	}

	/**
	 * Merge overlapping time blocks into consolidated blocks.
	 *
	 * @param array $time_blocks Array of time blocks with 'start' and 'end' keys
	 * @return array Merged time blocks
	 */
	private function mergeTimeBlocks( $time_blocks ) {
		if ( empty( $time_blocks ) ) {
			return array();
		}

		// Sort blocks by start time
		usort(
			$time_blocks,
			function ( $a, $b ) {
				return strcmp( $a['start'], $b['start'] );
			}
		);

		$merged        = array();
		$current_block = $time_blocks[0];

		for ( $i = 1; $i < count( $time_blocks ); $i++ ) {
			$next_block = $time_blocks[ $i ];

			// Check if blocks overlap or are adjacent
			if ( $this->timeBlocksOverlapOrAdjacent( $current_block, $next_block ) ) {
				// Merge blocks by extending the end time
				$current_block['end'] = max( $current_block['end'], $next_block['end'] );
			} else {
				// No overlap, add current block to merged array and move to next
				$merged[]      = $current_block;
				$current_block = $next_block;
			}
		}

		// Add the last block
		$merged[] = $current_block;

		return $merged;
	}

	/**
	 * Check if two time blocks overlap or are adjacent.
	 *
	 * @param array $block1 First time block
	 * @param array $block2 Second time block
	 * @return bool True if blocks overlap or are adjacent
	 */
	private function timeBlocksOverlapOrAdjacent( $block1, $block2 ) {
		$start1 = strtotime( $block1['start'] );
		$end1   = strtotime( $block1['end'] );
		$start2 = strtotime( $block2['start'] );
		$end2   = strtotime( $block2['end'] );

		// Check for overlap or adjacent blocks (end of one equals start of another)
		return ( $start1 <= $end2 && $end1 >= $start2 );
	}

	/**
	 * Apply frequency limits to ensure booking constraints are respected.
	 *
	 * Returns the broadest unit ('days'|'weeks'|'months') whose limit is
	 * exhausted for $start_date, or null when every limit still has room.
	 * The caller uses the unit to decide how far to skip ahead in the day
	 * loop: a 'weeks' hit means every remaining day in that week is also
	 * full, so we shouldn't re-check them per time-block.
	 *
	 * @param \DateTime $start_date Start date.
	 * @return string|null Broadest exhausted unit, or null.
	 */
	private function apply_frequency_limits( $start_date ) {
		if ( ! Arr::get( $this->limits, 'frequency.enable', false ) ) {
			return null;
		}

		$reached = null;
		foreach ( Arr::get( $this->limits, 'frequency.limits', array() ) as $frequency ) {
			$limit = (int) Arr::get( $frequency, 'limit', 0 );
			$unit  = Arr::get( $frequency, 'unit' );

			// 0 / missing limit means "not configured" — treat as disabled.
			if ( $limit <= 0 || ! $unit ) {
				continue;
			}

			if ( $this->validate_frequency_limits( $limit, $unit, $start_date ) ) {
				$reached = $this->widen_limit_unit( $reached, $unit );
			}
		}
		return $reached;
	}

	/**
	 *  Validate booking frequency limits.
	 *
	 *  @return bool True if the limit is reached for the window containing $start_date.
	 */
	private function validate_frequency_limits( $limit, $unit, $start_date ) {
		if ( ! in_array( $unit, array( 'days', 'weeks', 'months' ), true ) ) {
			return false;
		}

		switch ( $unit ) {
			case 'days':
				$start = clone $start_date;
				$start->setTime( 0, 0, 0 );  // Start of the day
				$end = clone $start;
				$end->setTime( 23, 59, 59 );  // End of the day
				break;
			case 'weeks':
				$start = clone $start_date;

				// Get user's preferred start of week from settings
				$settings   = get_option( 'doublescale_booking_settings', array() );
				$start_from = isset( $settings['general']['start_from'] ) ?
					$settings['general']['start_from'] : 'Monday';
				$start_from = ucfirst( strtolower( $start_from ) );

				// Get the current day of week (0 = Sunday, 1 = Monday, etc.)
				$current_day_num = (int) $start_date->format( 'w' );

				// Convert start_from to a day number (0-6)
				$day_map = array(
					'Sunday'    => 0,
					'Monday'    => 1,
					'Tuesday'   => 2,
					'Wednesday' => 3,
					'Thursday'  => 4,
					'Friday'    => 5,
					'Saturday'  => 6,
				);

				$start_from_num = isset( $day_map[ $start_from ] ) ? $day_map[ $start_from ] : 1; // Default to Monday (1)

				// Calculate days to subtract to get to the start of the week
				$days_to_subtract = ( $current_day_num - $start_from_num ) % 7;
				if ( $days_to_subtract < 0 ) {
					$days_to_subtract += 7;
				}

				// Set to start of the week based on user preference
				$start->modify( "-{$days_to_subtract} days" );
				$start->setTime( 0, 0, 0 );

				// Set to end of week (7 days from start)
				$end = clone $start;
				$end->modify( '+6 days' );  // End of the week (6 days after start)
				$end->setTime( 23, 59, 59 );
				break;
			case 'months':
				$start = clone $start_date;
				// Set to first day of the month
				$start->modify( 'first day of this month' );
				$start->setTime( 0, 0, 0 );

				// Set to last day of the month
				$end = clone $start;
				$end->modify( 'last day of this month' );
				$end->setTime( 23, 59, 59 );
				break;
		}

		// BookingModel stores start_time / end_time as UTC strings, so we
		// convert the window's boundaries to UTC before comparing. Using
		// the availability-local clock here would slide the comparison
		// window by the host's UTC offset and either skip late-night
		// bookings from the previous calendar day or pull in early-morning
		// bookings from the next one — both pre-existing edges that made
		// the limit count off by a few rows around midnight.
		$utc        = new \DateTimeZone( 'UTC' );
		$start_time = ( clone $start )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$end_time   = ( clone $end )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		$query = BookingModel::where( 'event_id', $this->id )
			->where( 'start_time', '>=', $start_time )
			->where( 'end_time', '<=', $end_time )
			->whereNotIn( 'status', BookingModel::NON_ACTIVE_STATUSES );

		return $query->count() >= $limit;
	}

	/**
	 * Apply duration limits to ensure total booking time is within allowed constraints.
	 *
	 * @param \DateTime $start_date Start date.
	 * @return string|null Broadest exhausted unit, or null.
	 */
	private function apply_duration_limits( $start_date ) {
		if ( ! Arr::get( $this->limits, 'duration.enable', false ) ) {
			return null;
		}

		$reached = null;
		foreach ( Arr::get( $this->limits, 'duration.limits', array() ) as $duration_limit ) {
			$limit = (int) Arr::get( $duration_limit, 'limit', 0 );
			$unit  = Arr::get( $duration_limit, 'unit' );

			if ( $limit <= 0 || ! $unit ) {
				continue;
			}

			if ( $this->validate_duration_limit( $limit, $unit, $start_date ) ) {
				$reached = $this->widen_limit_unit( $reached, $unit );
			}
		}
		return $reached;
	}

	/**
	 * Pick the broader of two limit units. Used to merge multiple exhausted
	 * limits — a 'weeks' hit subsumes a 'days' hit because the entire week
	 * is full anyway.
	 *
	 * @param string|null $current Current broadest unit.
	 * @param string      $candidate Candidate unit.
	 * @return string Broadest unit.
	 */
	private function widen_limit_unit( $current, $candidate ) {
		$rank = array(
			'days'   => 1,
			'weeks'  => 2,
			'months' => 3,
		);
		if ( ! isset( $rank[ $candidate ] ) ) {
			return $current ?? $candidate;
		}
		if ( null === $current || ( $rank[ $candidate ] > ( $rank[ $current ] ?? 0 ) ) ) {
			return $candidate;
		}
		return $current;
	}

	/**
	 * Validate booking duration limits — total minutes already booked in
	 * the window vs. the configured cap.
	 *
	 * @param int       $limit      Limit value (minutes).
	 * @param string    $unit       Window unit (days, weeks, months).
	 * @param \DateTime $start_date Start datetime under check.
	 * @return bool True if the limit is reached.
	 */
	private function validate_duration_limit( $limit, $unit, $start_date ) {
		if ( ! in_array( $unit, array( 'days', 'weeks', 'months' ), true ) ) {
			return false;
		}
		switch ( $unit ) {
			case 'days':
				$start = clone $start_date;
				$start->setTime( 0, 0, 0 );  // Start of the day
				$end = clone $start;
				$end->setTime( 23, 59, 59 );  // End of the day
				break;
			case 'weeks':
				$start = clone $start_date;

				// Get user's preferred start of week from settings
				$settings   = get_option( 'doublescale_booking_settings', array() );
				$start_from = isset( $settings['general']['start_from'] ) ?
					$settings['general']['start_from'] : 'Monday';
				$start_from = ucfirst( strtolower( $start_from ) );

				// Get the current day of week (0 = Sunday, 1 = Monday, etc.)
				$current_day_num = (int) $start_date->format( 'w' );

				// Convert start_from to a day number (0-6)
				$day_map = array(
					'Sunday'    => 0,
					'Monday'    => 1,
					'Tuesday'   => 2,
					'Wednesday' => 3,
					'Thursday'  => 4,
					'Friday'    => 5,
					'Saturday'  => 6,
				);

				$start_from_num = isset( $day_map[ $start_from ] ) ? $day_map[ $start_from ] : 1; // Default to Monday (1)

				// Calculate days to subtract to get to the start of the week
				$days_to_subtract = ( $current_day_num - $start_from_num ) % 7;
				if ( $days_to_subtract < 0 ) {
					$days_to_subtract += 7;
				}

				// Set to start of the week based on user preference
				$start->modify( "-{$days_to_subtract} days" );
				$start->setTime( 0, 0, 0 );

				// Set to end of week (7 days from start)
				$end = clone $start;
				$end->modify( '+6 days' );  // End of the week (6 days after start)
				$end->setTime( 23, 59, 59 );
				break;
			case 'months':
				$start = clone $start_date;
				// Set to first day of the month
				$start->modify( 'first day of this month' );
				$start->setTime( 0, 0, 0 );

				// Set to last day of the month
				$end = clone $start;
				$end->modify( 'last day of this month' );
				$end->setTime( 23, 59, 59 );
				break;
		}

		// See validate_frequency_limits — booking times are persisted in
		// UTC, so the window boundaries have to be converted before being
		// compared, otherwise the day/week/month cut-off slides by the
		// host's UTC offset.
		$utc        = new \DateTimeZone( 'UTC' );
		$start_time = ( clone $start )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$end_time   = ( clone $end )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		$query = BookingModel::where( 'event_id', $this->id )
			->where( 'start_time', '>=', $start_time )
			->where( 'end_time', '<=', $end_time )
			->whereNotIn( 'status', BookingModel::NON_ACTIVE_STATUSES );

		$duration = 0;
		foreach ( $query->get() as $booking ) {
			$duration += $booking->slot_time;
		}

		return $duration >= $limit;
	}

	/**
	 * Adjust start date based on event and current date.
	 *
	 * @param string $start_date Date string accepted by DateTime (e.g. `Y-m-d` or `Y-m-d H:i:s`). Numeric timestamps are NOT accepted by the underlying `new \DateTime( $start_date )` call.
	 * @param string $timezone   Timezone.
	 * @param int    $duration   Slot duration in minutes.
	 * @return int Adjusted start date timestamp.
	 */
	private function adjust_start_date( $start_date, $timezone, $duration ) {
		$start_date       = new \DateTime( $start_date, new \DateTimeZone( $timezone ) );
		$event_start_date = $this->get_start_date( $timezone );
		$current_time     = ( new \DateTime( 'now', new \DateTimeZone( $timezone ) ) )->getTimestamp();

		$start_date = max( $event_start_date, $start_date->getTimestamp(), $current_time );

		return ceil( $start_date / ( $duration * 60 ) ) * ( $duration * 60 );
	}

	/**
	 * Calculate the end date for the event.
	 *
	 * @param int    $start_date Start date timestamp.
	 * @param string $timezone   Timezone.
	 * @return int End date timestamp.
	 */
	private function calculate_end_date( $start_date, $timezone ) {
		$event_end_date = $this->get_end_date( $timezone );

		if ( $start_date > $event_end_date ) {
			throw new \Exception( esc_html__( 'Event is not available', 'doublescale' ) );
		}

		return $event_end_date;
	}

	/**
	 * Generate slots for each day within the given range.
	 *
	 * @param int    $start_date Start date timestamp.
	 * @param int    $end_date   End date timestamp.
	 * @param string $timezone   Timezone.
	 * @param int    $duration   Slot duration in minutes.
	 * @return array Generated slots.
	 */
	private function generate_daily_slots( $start_date, $end_date, $timezone, $duration, $user_id = null, $include_full_slots = false ) {
		$slots = array();
		for ( $current_date = $start_date; $current_date <= $end_date; $current_date = strtotime( '+1 day', $current_date ) ) {
			$current_date_formatted = gmdate( 'Y-m-d', $current_date );
			$day_of_week            = strtolower( gmdate( 'l', $current_date ) );

			$time_blocks            = array();
			$effective_availability = $this->get_effective_availability();

			if ( isset( $effective_availability['override'][ $current_date_formatted ] ) ) {
				$time_blocks = $effective_availability['override'][ $current_date_formatted ];
			} elseif ( empty( $effective_availability['weekly_hours'][ $day_of_week ]['off'] ) ) {
				$time_blocks = $effective_availability['weekly_hours'][ $day_of_week ]['times'] ?? array();
			}

			if ( empty( $time_blocks ) ) {
				continue;
			}

			// Frequency / duration limits are window-level (day / week / month).
			// Check once per day using the day's reference time, then act on
			// the broadest exhausted unit:
			// - 'days'   → skip remaining time-blocks of THIS day only.
			// - 'weeks'  → skip ahead to next week (every remaining day in
			// the current week is also full).
			// - 'months' → skip ahead to next month.
			// The previous implementation threw inside the time-block loop
			// and only `continue`d, which silently checked the same window
			// once per block and wasted queries.
			$day_ref_dt   = new \DateTime( $current_date_formatted . ' 12:00:00', new \DateTimeZone( $effective_availability['timezone'] ) );
			$freq_reached = $this->apply_frequency_limits( $day_ref_dt );
			$dur_reached  = $this->apply_duration_limits( $day_ref_dt );
			$reached      = null;
			if ( null !== $freq_reached ) {
				$reached = $freq_reached;
			}
			if ( null !== $dur_reached ) {
				$reached = $this->widen_limit_unit( $reached, $dur_reached );
			}

			if ( 'weeks' === $reached || 'months' === $reached ) {
				$current_date = $this->advance_past_limit_window( $current_date, $reached, $effective_availability['timezone'] );
				continue;
			}
			if ( 'days' === $reached ) {
				continue;
			}

			foreach ( $time_blocks as $time_block ) {
				$day_start = new \DateTime( $current_date_formatted . ' ' . $time_block['start'], new \DateTimeZone( $effective_availability['timezone'] ) );
				$day_end   = new \DateTime( $current_date_formatted . ' ' . $time_block['end'], new \DateTimeZone( $effective_availability['timezone'] ) );

				$day_start->setTimezone( new \DateTimeZone( $timezone ) );
				$day_end->setTimezone( new \DateTimeZone( $timezone ) );

				$slots = $this->generate_slots_for_time_block( $day_start, $day_end, $duration, $timezone, $current_date, $slots, $user_id, $include_full_slots );
			}
		}

		return $slots;
	}

	/**
	 * Advance the day-loop cursor past the end of the current week or month
	 * so subsequent iterations don't re-check a window that's already full.
	 * Returns a timestamp aligned with the loop's `strtotime('+1 day')` step.
	 *
	 * @param int    $current_ts  Current loop cursor (seconds since epoch).
	 * @param string $unit        'weeks' or 'months'.
	 * @param string $timezone    Availability timezone.
	 * @return int Adjusted cursor — the next `+1 day` step lands in the next window.
	 */
	private function advance_past_limit_window( $current_ts, $unit, $timezone ) {
		$tz = new \DateTimeZone( $timezone );
		$dt = ( new \DateTime( '@' . $current_ts ) )->setTimezone( $tz );

		if ( 'months' === $unit ) {
			$dt->modify( 'last day of this month' );
			return $dt->getTimestamp();
		}

		// 'weeks' — jump to the last day of the configured week (Sun by default).
		$settings   = get_option( 'doublescale_booking_settings', array() );
		$start_from = isset( $settings['general']['start_from'] ) ? ucfirst( strtolower( $settings['general']['start_from'] ) ) : 'Monday';
		$day_map    = array(
			'Sunday'    => 0,
			'Monday'    => 1,
			'Tuesday'   => 2,
			'Wednesday' => 3,
			'Thursday'  => 4,
			'Friday'    => 5,
			'Saturday'  => 6,
		);
		$start_num  = $day_map[ $start_from ] ?? 1;
		$cur_num    = (int) $dt->format( 'w' );
		$offset     = ( $cur_num - $start_num + 7 ) % 7;
		$days_left  = 6 - $offset;
		if ( $days_left > 0 ) {
			$dt->modify( "+{$days_left} days" );
		}
		return $dt->getTimestamp();
	}

	/**
	 * Generate slots for a specific time block, considering buffers, minimum notices, and current day adjustments.
	 *
	 * @param \DateTime $day_start Start time of the time block.
	 * @param \DateTime $day_end End time of the time block.
	 * @param int       $duration Slot duration in minutes.
	 * @param string    $timezone User timezone.
	 * @param int       $current_date Unix timestamp of the current day.
	 * @param array     $slots Existing slots to append new slots.
	 * @param int       $calendar_id The calendar ID.
	 * @return array Updated slots with new time block slots.
	 */
	private function generate_slots_for_time_block( $day_start, $day_end, $duration, $timezone, $current_date, $slots, $user_id = null, $include_full_slots = false ) {
		// Get current time in user timezone
		$current_time = new \DateTime( 'now', new \DateTimeZone( $this->get_effective_availability()['timezone'] ) );
		$current_time->setTimezone( new \DateTimeZone( $timezone ) );

		// Get minimum notice settings. A null/missing value falls back to
		// 4 hours (legacy default). An explicit 0 disables the lead-time
		// — previously `Arr::get(..., 4)` only applied when the key was
		// missing, but `(int) 0` got compared with `if ( $min_notice > 0 )`
		// below and ended up equivalent to disabled anyway; the bug was
		// the default unit ('hours') being applied to a raw 4 without
		// any unit context. We now explicitly distinguish the two.
		$min_notice_raw  = Arr::get( $this->limits, 'general.minimum_notices', null );
		$min_notice      = ( null === $min_notice_raw || '' === $min_notice_raw ) ? 4 : (int) $min_notice_raw;
		$min_notice_unit = Arr::get( $this->limits, 'general.minimum_notice_unit', 'hours' );

		// Get time slot interval from event limits data
		$time_slot_interval = Arr::get( $this->limits, 'general.time_slot', 0 );

		// If time slot interval is set and valid, use it instead of duration for slot generation
		$slot_step = ( $time_slot_interval > 0 ) ? $time_slot_interval : $duration;

		// Convert all dates to timestamps for easier comparison
		$current_timestamp   = $current_time->getTimestamp();
		$day_start_timestamp = $day_start->getTimestamp();

		// Calculate the minimum notice period in seconds
		$min_notice_seconds = 0;
		if ( $min_notice > 0 ) {
			switch ( $min_notice_unit ) {
				case 'days':
					$min_notice_seconds = $min_notice * 24 * 60 * 60;
					break;
				case 'hours':
					$min_notice_seconds = $min_notice * 60 * 60;
					break;
				case 'minutes':
					$min_notice_seconds = $min_notice * 60;
					break;
			}
		}

		// Calculate the earliest allowed booking time
		$min_allowed_timestamp = $current_timestamp + $min_notice_seconds;

		// If day_start is before the minimum allowed time, adjust it
		if ( $day_start_timestamp < $min_allowed_timestamp ) {
			// Create a new DateTime object from the minimum allowed timestamp
			$day_start = new \DateTime( '@' . $min_allowed_timestamp );
			$day_start->setTimezone( new \DateTimeZone( $timezone ) );
		}

		// Round up to the next valid slot based on the slot_step
		// Get the current hour and minute from the (potentially adjusted) day_start
		$current_hour   = (int) $day_start->format( 'H' );
		$current_minute = (int) $day_start->format( 'i' );

		// Calculate the total minutes from midnight for the current day_start
		$total_minutes_from_midnight = ( $current_hour * 60 ) + $current_minute;

		// Calculate the next rounded total minutes
		$rounded_total_minutes = ceil( $total_minutes_from_midnight / $slot_step ) * $slot_step;

		// Apply the rounded time, handling day rollovers automatically.
		// First, reset the time to 00:00:00 for the current day to ensure consistent modification.
		$day_start->setTime( 0, 0, 0 );
		// Then add the rounded total minutes. This will advance the day if necessary.
		$day_start->modify( "+{$rounded_total_minutes} minutes" );

		// If after adjustments, day_start is now after day_end, there are no available slots
		if ( $day_start >= $day_end ) {
			return $slots;
		}

		$current_slot_start = clone $day_start;

		// Track original slots to help with synthetic slot generation
		$original_slots = array();

		// First pass: generate slots based on the slot_step interval
		while ( $current_slot_start < $day_end ) {
			$slot_start = clone $current_slot_start;
			$slot_end   = clone $slot_start;
			$slot_end->modify( "+{$duration} minutes" ); // End time is always based on the actual duration

			if ( $slot_end > $day_end ) {
				break; // End of time block
			}

			// Check availability of the slot
			$available_slots = $this->check_available_slots( $slot_start, $slot_end, $user_id );

			if ( $available_slots['slots'] === 0 && ! $include_full_slots ) {
				// Move to the next interval
				$current_slot_start->modify( "+{$slot_step} minutes" );
				continue;
			}

			$day       = $current_slot_start->format( 'Y-m-d' );
			$slot_data = array(
				'start'     => $slot_start->format( 'Y-m-d H:i:s' ),
				'end'       => $slot_end->format( 'Y-m-d H:i:s' ),
				'remaining' => $available_slots['slots'],
				'hosts_ids' => $available_slots['hosts_ids'],
			);

			// Store in original slots array for reference
			$original_slots[] = $slot_data;

			// Add to the slots array
			if ( ! isset( $slots[ $day ] ) ) {
				$slots[ $day ] = array();
			}
			$slots[ $day ][] = $slot_data;

			// Move to the next interval
			$current_slot_start->modify( "+{$slot_step} minutes" );
		}

		// If time_slot_interval is set and smaller than duration, we need to generate synthetic slots
		if ( $time_slot_interval > 0 && $time_slot_interval < $duration && ! empty( $original_slots ) ) {
			// Second pass: generate synthetic slots at the specified interval
			$day = $day_start->format( 'Y-m-d' );

			// Start from the earliest time aligned to the interval
			$synthetic_start = clone $day_start;
			$synthetic_end   = clone $day_end;

			// Create a map of existing slots by start time for quick lookup
			$existing_slots = array();
			if ( isset( $slots[ $day ] ) ) {
				foreach ( $slots[ $day ] as $slot ) {
					$start_time                    = ( new \DateTime( $slot['start'] ) )->format( 'H:i:s' );
					$existing_slots[ $start_time ] = true;
				}
			}

			$current_time = clone $synthetic_start;

			while ( $current_time < $synthetic_end ) {
				$current_time_str = $current_time->format( 'H:i:s' );

				// Skip if this exact time already exists as a slot
				if ( isset( $existing_slots[ $current_time_str ] ) ) {
					$current_time->modify( "+{$time_slot_interval} minutes" );
					continue;
				}

				// Check if this time falls within any existing slot's duration
				$is_within_existing_slot = false;
				$reference_slot          = null;

				foreach ( $original_slots as $original_slot ) {
					$original_start = new \DateTime( $original_slot['start'] );
					$original_end   = new \DateTime( $original_slot['end'] );

					// If current time is within this slot's range, use it as reference
					if ( $current_time >= $original_start && $current_time < $original_end ) {
						$is_within_existing_slot = true;
						$reference_slot          = $original_slot;
						break;
					}
				}

				// If we found a reference slot, create a synthetic slot
				if ( $is_within_existing_slot && $reference_slot ) {
					$synthetic_slot_start = clone $current_time;
					$synthetic_slot_end   = clone $synthetic_slot_start;
					$synthetic_slot_end->modify( "+{$duration} minutes" );

					// Only create the synthetic slot if it ends before the day_end
					if ( $synthetic_slot_end <= $day_end ) {
						// Check if this synthetic slot is available
						$available_slots = $this->check_available_slots( $synthetic_slot_start, $synthetic_slot_end, $user_id );

						if ( $available_slots['slots'] > 0 || $include_full_slots ) {
							// Mark this as a synthetic slot
							$synthetic_slot = array(
								'start'     => $synthetic_slot_start->format( 'Y-m-d H:i:s' ),
								'end'       => $synthetic_slot_end->format( 'Y-m-d H:i:s' ),
								'remaining' => $available_slots['slots'],
								'hosts_ids' => $available_slots['hosts_ids'],
								'synthetic' => true,
							);

							if ( ! isset( $slots[ $day ] ) ) {
								$slots[ $day ] = array();
							}
							$slots[ $day ][] = $synthetic_slot;
						}
					}
				}

				// Move to the next interval
				$current_time->modify( "+{$time_slot_interval} minutes" );
			}

			// Sort slots by start time
			if ( isset( $slots[ $day ] ) && count( $slots[ $day ] ) > 1 ) {
				usort(
					$slots[ $day ],
					function ( $a, $b ) {
						return strcmp( $a['start'], $b['start'] );
					}
				);
			}
		}

		return $slots;
	}

	/**
	 * Get the end date of the event
	 *
	 * @param string $timezone Timezone
	 *
	 * @return int
	 * @throws \Exception
	 */
	public function get_end_date( $timezone ) {
		// Validate required data
		if ( empty( $this->created_at ) || empty( $this->get_effective_availability()['timezone'] ) ) {
			throw new \Exception( esc_html__( 'Invalid event data: missing created_at or timezone', 'doublescale' ) );
		}

		// Validate timezone strings
		try {
			$original_tz = new \DateTimeZone( $this->get_effective_availability()['timezone'] );
			$target_tz   = new \DateTimeZone( $timezone );
		} catch ( \Exception $e ) {
			throw new \Exception( esc_html__( 'Invalid timezone provided', 'doublescale' ) );
		}

		// Create the base date from creation time
		try {
			$created_date = new \DateTime( $this->created_at, $original_tz );
			$created_date->setTimezone( $target_tz );
		} catch ( \Exception $e ) {
			throw new \Exception( esc_html__( 'Invalid created_at date format', 'doublescale' ) );
		}

		$event_date_type = Arr::get( $this->event_range, 'type', 'days' );
		$end_date        = null;

		switch ( $event_date_type ) {
			case 'days':
				$end_event_value = Arr::get( $this->event_range, 'days', 60 );

				// Validate days value
				if ( ! is_numeric( $end_event_value ) || $end_event_value < 0 || $end_event_value > 3650 ) {
					throw new \Exception( esc_html__( 'Invalid days value. Must be between 0 and 3650', 'doublescale' ) );
				}

				// Clone to avoid modifying original date
				$end_date = clone $created_date;
				$end_date->modify( "+{$end_event_value} days" );
				// Set to end of day for consistency
				$end_date->setTime( 23, 59, 59 );
				break;

			case 'infinity':
				// Clone to avoid modifying original date
				$end_date = clone $created_date;
				$end_date->modify( '+5 years' );
				// Set to end of day for consistency
				$end_date->setTime( 23, 59, 59 );
				break;

			case 'date_range':
				$end_event_value = Arr::get( $this->event_range, 'end_date', null );
				if ( empty( $end_event_value ) ) {
					throw new \Exception( esc_html__( 'End date is required for date_range type', 'doublescale' ) );
				}

				try {
					// Create DateTime with UTC timezone to avoid DST issues
					$end_date = new \DateTime( $end_event_value, new \DateTimeZone( 'UTC' ) );
					$end_date->setTime( 23, 59, 59 );
					// Convert to the target timezone
					$end_date->setTimezone( $target_tz );
				} catch ( \Exception $e ) {
					throw new \Exception( esc_html__( 'Invalid end_date format', 'doublescale' ) );
				}

				// Validate that end date is after creation date
				// For fair comparison, set created_date to start of day
				$created_date_start = clone $created_date;
				$created_date_start->setTime( 0, 0, 0 );

				if ( $end_date <= $created_date_start ) {
					throw new \Exception( esc_html__( 'End date must be after the created date', 'doublescale' ) );
				}
				break;

			default:
				throw new \Exception( esc_html__( 'Invalid event date type', 'doublescale' ) );
		}

		return $end_date->getTimestamp();
	}

	/**
	 * Get event start date
	 *
	 * @param string $timezone Timezone
	 *
	 * @return int
	 * @throws \Exception
	 */
	public function get_start_date( $timezone ) {
		// Validate required data
		if ( empty( $this->created_at ) || empty( $this->get_effective_availability()['timezone'] ) ) {
			throw new \Exception( esc_html__( 'Invalid event data: missing created_at or timezone', 'doublescale' ) );
		}

		// Validate timezone strings
		try {
			$original_tz = new \DateTimeZone( $this->get_effective_availability()['timezone'] );
			$target_tz   = new \DateTimeZone( $timezone );
		} catch ( \Exception $e ) {
			throw new \Exception( esc_html__( 'Invalid timezone provided', 'doublescale' ) );
		}

		$event_date_type = Arr::get( $this->event_range, 'type', 'days' );
		$start_date      = null;

		try {
			// Create the base date from creation time
			$start_date = new \DateTime( $this->created_at, $original_tz );
			$start_date->setTimezone( $target_tz );

			// For non-date_range types, set to start of day for consistency
			if ( 'date_range' !== $event_date_type ) {
				$start_date->setTime( 0, 0, 0 );
			}
		} catch ( \Exception $e ) {
			throw new \Exception( esc_html__( 'Invalid created_at date format', 'doublescale' ) );
		}

		// Handle date_range type with custom start date
		if ( 'date_range' === $event_date_type ) {
			$start_date_value = Arr::get( $this->event_range, 'start_date', null );
			if ( empty( $start_date_value ) ) {
				throw new \Exception( esc_html__( 'Start date is required for date_range type', 'doublescale' ) );
			}

			try {
				// Create DateTime with UTC timezone to avoid DST issues
				$start_date = new \DateTime( $start_date_value, new \DateTimeZone( 'UTC' ) );
				$start_date->setTime( 0, 0, 0 );
				// Convert to the target timezone
				$start_date->setTimezone( $target_tz );
			} catch ( \Exception $e ) {
				throw new \Exception( esc_html__( 'Invalid start_date format', 'doublescale' ) );
			}

			// Validate that start date is not in the past relative to creation date
			$created_date_check = new \DateTime( $this->created_at, $original_tz );
			$created_date_check->setTimezone( $target_tz );
			$created_date_check->setTime( 0, 0, 0 );

			if ( $start_date < $created_date_check ) {
				throw new \Exception( esc_html__( 'Start date cannot be before the created date', 'doublescale' ) );
			}
		}

		return $start_date->getTimestamp();
	}

	/**
	 * Check available slots
	 *
	 * @param \DateTime $day_start Start date
	 * @param \DateTime $day_end End date
	 *
	 * @return int
	 */
	public function check_available_slots( $day_start, $day_end, $user_id = null ) {
		$day_start = clone $day_start;
		$day_end   = clone $day_end;

		$buffer_before = Arr::get( $this->limits, 'general.buffer_before', 0 );
		$buffer_after  = Arr::get( $this->limits, 'general.buffer_after', 0 );

		$day_start->setTimezone( new \DateTimeZone( 'UTC' ) );
		$day_end->setTimezone( new \DateTimeZone( 'UTC' ) );

		switch ( $this->type ) {
			case 'one-to-one':
			case 'group':
				$slots_query = BookingModel::query()
					->whereHas(
						'hosts',
						function ( $q ) {
							$q->where( 'user_id', $this->user_id );
						}
					)
					->whereNotIn( 'status', BookingModel::NON_ACTIVE_STATUSES )
					->where(
						function ( $query ) use ( $day_start, $day_end, $buffer_before, $buffer_after ) {
							$query->where(
								function ( $q ) use ( $day_start, $day_end, $buffer_before, $buffer_after ) {
									$q->where(
										function ( $subq ) use ( $day_start, $buffer_after ) {
											$subq->whereRaw( 'DATE_ADD(end_time, INTERVAL ? MINUTE) > ?', array( $buffer_after, $day_start->format( 'Y-m-d H:i:s' ) ) );
										}
									)
										->where(
											function ( $subq ) use ( $day_end, $buffer_before ) {
												$subq->whereRaw( 'DATE_SUB(start_time, INTERVAL ? MINUTE) < ?', array( $buffer_before, $day_end->format( 'Y-m-d H:i:s' ) ) );
											}
										);
								}
							);
						}
					);
				$event_spots = 'one-to-one' === $this->type ? 1 : Arr::get( $this->group_settings, 'max_invites', 2 );
				$slots       = $slots_query->count();
				return array(
					'slots'     => $event_spots > $slots ? $event_spots - $slots : 0,
					'hosts_ids' => array(),
				);

			case 'round-robin':
			case 'collective':
				$team_members = $this->get_team_scheduling_member_ids();

				if ( 'collective' === $this->type && empty( $team_members ) ) {
					return array(
						'slots'     => 0,
						'hosts_ids' => array(),
					);
				}

				$available_members   = 0;
				$available_hosts_ids = array();
				foreach ( $team_members as $team_member_id ) {
					if ( $this->is_team_member_available_for_slot( $team_member_id, $day_start, $day_end, $buffer_before, $buffer_after ) ) {
						++$available_members;
						$available_hosts_ids[] = $team_member_id;
					}
				}

				if ( 'round-robin' === $this->type ) {
					return array(
						'slots'     => $available_members,
						'hosts_ids' => $available_hosts_ids,
					);
				}

				// collective — every host on the event must be free.
				$slots_available = ( $available_members === count( $team_members ) ) ? 1 : 0;
				return array(
					'slots'     => $slots_available,
					'hosts_ids' => $slots_available ? $available_hosts_ids : array(),
				);
			default:
				doublescale_get_logger()->warning(
					'Unknown event type encountered during slot availability check',
					array(
						'source'   => 'booking-event-model',
						'event_id' => (int) $this->id,
						'type'     => $this->type,
					)
				);
				return array(
					'slots'     => 0,
					'hosts_ids' => array(),
				);
		}
	}

	/**
	 * Is slot available
	 *
	 * @param \DateTime $start_time Start time
	 * @param int       $duration Duration of the slot
	 *
	 * @return int
	 */
	public function get_booking_available_slots( $start_time, $duration, $timezone, $user_id = null ) {
		$end_time = clone $start_time;
		$end_time->modify( "+{$duration} minutes" );

		return $this->get_slot_availability_count( $start_time, $end_time, $timezone, $user_id );
	}

	/**
	 * Check slot availability
	 *
	 * @param \DateTime $start_time Start time
	 * @param \DateTime $end_time End time
	 *
	 * @return bool
	 */
	public function get_slot_availability_count( $start_time, $end_time, $timezone, $user_id = null ) {
		$availability = $this->getTeamAvailability( $this->get_effective_availability(), $user_id );
		$avail_tz_id  = $availability['timezone'] ?? 'UTC';
		$avail_tz     = new \DateTimeZone( $avail_tz_id );

		// Resolve "which day is this slot on?" inside the availability's
		// own timezone. Using $start_time's current TZ (often the visitor's
		// or UTC) crossed midnight on the availability side and made us
		// miss overrides that were keyed by the local date, plus picked
		// the wrong weekday near DST boundaries.
		$slot_start_in_avail = ( clone $start_time )->setTimezone( $avail_tz );
		$slot_end_in_avail   = ( clone $end_time )->setTimezone( $avail_tz );
		$avail_date          = $slot_start_in_avail->format( 'Y-m-d' );
		$avail_day_of_week   = strtolower( $slot_start_in_avail->format( 'l' ) );

		$time_blocks = array();
		if ( isset( $availability['override'][ $avail_date ] ) ) {
			$time_blocks = $availability['override'][ $avail_date ];
		} else {
			$day_schedule = $availability['weekly_hours'][ $avail_day_of_week ] ?? null;
			if ( $day_schedule && empty( $day_schedule['off'] ) ) {
				$time_blocks = $day_schedule['times'] ?? array();
			}
		}

		foreach ( $time_blocks as $time_block ) {
			$day_start = new \DateTime( $avail_date . ' ' . $time_block['start'], $avail_tz );
			$day_end   = new \DateTime( $avail_date . ' ' . $time_block['end'], $avail_tz );

			if ( $slot_start_in_avail >= $day_start && $slot_end_in_avail <= $day_end ) {
				$slots = $this->check_available_slots( $start_time, $end_time, $user_id );
				return $slots['slots'];
			}
		}

		return 0;
	}

	/**
	 * Resolve whether a team member is available for a slot, combining their
	 * schedule (or the shared schedule when is_common is true) with a check
	 * that they don't already have a conflicting booking + buffer window.
	 *
	 * Previously the slot check short-circuited to "available" whenever
	 * is_common was true, which meant a shared schedule covering 9–5 still
	 * exposed 7–9 slots: nothing actually verified the slot fell inside
	 * the shared working hours. We now apply the same schedule check for
	 * both the per-host and shared paths.
	 *
	 * @param int       $team_member_id WP user ID of the host.
	 * @param \DateTime $day_start      Slot start in UTC.
	 * @param \DateTime $day_end        Slot end in UTC.
	 * @param int       $buffer_before  Minutes of buffer before each booking.
	 * @param int       $buffer_after   Minutes of buffer after each booking.
	 * @return bool True if the member is on schedule AND has no conflict.
	 */
	private function is_team_member_available_for_slot( $team_member_id, $day_start, $day_end, $buffer_before, $buffer_after ) {
		$effective       = $this->get_effective_availability();
		$is_common       = ! empty( $effective['is_common'] );
		$availabilities  = $effective['users_availability'] ?? array();
		$schedule_to_use = null;

		if ( $is_common ) {
			// Treat the merged event-level availability as the shared
			// schedule. checkMemberAvailabilitySchedule reads weekly_hours
			// / override / timezone, so we just hand it the effective row.
			$schedule_to_use = array(
				'weekly_hours' => $effective['weekly_hours'] ?? array(),
				'override'     => $effective['override'] ?? array(),
				'timezone'     => $effective['timezone'] ?? 'UTC',
			);
		} else {
			foreach ( $availabilities as $avail ) {
				if ( isset( $avail['user_id'] ) && (int) $avail['user_id'] === (int) $team_member_id ) {
					$schedule_to_use = $avail;
					break;
				}
			}
			if ( ! $schedule_to_use ) {
				$schedule_to_use = Availabilities::get_user_default_availability( $team_member_id );
			}
		}

		if ( ! $schedule_to_use ) {
			return false;
		}

		if ( ! $this->checkMemberAvailabilitySchedule( $schedule_to_use, $day_start, $day_end ) ) {
			return false;
		}

		$conflict_count = BookingModel::query()
			->whereHas(
				'hosts',
				function ( $q ) use ( $team_member_id ) {
					$q->where( 'user_id', $team_member_id );
				}
			)
			->whereNotIn( 'status', BookingModel::NON_ACTIVE_STATUSES )
			->where(
				function ( $query ) use ( $day_start, $day_end, $buffer_before, $buffer_after ) {
					$query->where(
						function ( $subq ) use ( $day_start, $buffer_after ) {
							$subq->whereRaw( 'DATE_ADD(end_time, INTERVAL ? MINUTE) > ?', array( $buffer_after, $day_start->format( 'Y-m-d H:i:s' ) ) );
						}
					)->where(
						function ( $subq ) use ( $day_end, $buffer_before ) {
							$subq->whereRaw( 'DATE_SUB(start_time, INTERVAL ? MINUTE) < ?', array( $buffer_before, $day_end->format( 'Y-m-d H:i:s' ) ) );
						}
					);
				}
			)
			->count();

		return 0 === $conflict_count;
	}

	/**
	 * Check if a team member is available according to their availability schedule
	 *
	 * @param array     $member_availability The team member's availability data
	 * @param \DateTime $day_start           Start time to check
	 * @param \DateTime $day_end             End time to check
	 *
	 * @return bool True if member is available during the specified time
	 */
	private function checkMemberAvailabilitySchedule( $member_availability, $day_start, $day_end ) {
		$start_date_formatted = $day_start->format( 'Y-m-d' );
		$member_timezone      = $member_availability['timezone'] ?? 'UTC';

		// Check for date-specific override first
		if ( isset( $member_availability['override'][ $start_date_formatted ] ) ) {
			foreach ( $member_availability['override'][ $start_date_formatted ] as $time_block ) {
				$block_start = new \DateTime( $start_date_formatted . ' ' . $time_block['start'], new \DateTimeZone( $member_timezone ) );
				$block_end   = new \DateTime( $start_date_formatted . ' ' . $time_block['end'], new \DateTimeZone( $member_timezone ) );

				// Convert to UTC for comparison
				$block_start->setTimezone( new \DateTimeZone( 'UTC' ) );
				$block_end->setTimezone( new \DateTimeZone( 'UTC' ) );

				if ( $day_start >= $block_start && $day_end <= $block_end ) {
					return true;
				}
			}
			return false; // If override exists but no matching time block found
		}

		// Fall back to regular weekly hours
		$weekly_hours = $member_availability['weekly_hours'] ?? array();
		$day_of_week  = strtolower( gmdate( 'l', $day_start->getTimestamp() ) );

		if ( isset( $weekly_hours[ $day_of_week ] ) && ! $weekly_hours[ $day_of_week ]['off'] ) {
			foreach ( $weekly_hours[ $day_of_week ]['times'] as $time_block ) {
				$block_start = new \DateTime( gmdate( 'Y-m-d', $day_start->getTimestamp() ) . ' ' . $time_block['start'], new \DateTimeZone( $member_timezone ) );
				$block_end   = new \DateTime( gmdate( 'Y-m-d', $day_start->getTimestamp() ) . ' ' . $time_block['end'], new \DateTimeZone( $member_timezone ) );

				// Convert to UTC for comparison
				$block_start->setTimezone( new \DateTimeZone( 'UTC' ) );
				$block_end->setTimezone( new \DateTimeZone( 'UTC' ) );

				if ( $day_start >= $block_start && $day_end <= $block_end ) {
					return true;
				}
			}
		}

		return false; // Member is not available during this time
	}

	/**
	 * Override the save method to add validation.
	 *
	 * @param array $options
	 * @return bool
	 * @throws \Exception
	 */
	public function save( array $options = array() ) {
		// Calendar must exist.
		$calendar = CalendarModel::find( $this->calendar_id );
		if ( ! $calendar ) {
			throw new \Exception( esc_html__( 'Calendar does not exist', 'doublescale' ) );
		}

		// Calendar owner must still exist as a WP user. Booking-eligibility is
		// enforced at role-grant time by Services\BookingProvisioner, and user
		// deletion triggers BookingProvisioner::purge_host_data() which
		// deactivates orphan calendars. If we still hit a missing owner here
		// (e.g. a race with delete_user), warn and skip rather than throwing
		// so historical event editing doesn't hard-fail.
		$user = get_userdata( (int) $this->calendar->user_id );
		if ( ! $user || ! $user->exists() ) {
			doublescale_get_logger()->warning(
				'Skipped event save: calendar owner missing',
				array(
					'source'      => 'booking-event-model',
					'calendar_id' => (int) $this->calendar_id,
					'user_id'     => (int) $this->calendar->user_id,
				)
			);
			return false;
		}

		return parent::save( $options );
	}

	/**
	 * Boot
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		static::creating(
			function ( $event ) {
				$event->hash_id = wp_generate_uuid4();
				$originalSlug   = $slug = Str::slug( $event->name );
				$count          = 1;

				while ( static::where( 'slug', $slug )->exists() ) {
					$slug = $originalSlug . '-' . $count++;
				}

				$event->slug    = $slug;
				$event->user_id = $event->calendar->user_id;

				if ( ! $event->status ) {
					$event->status = 'active';
				}

				if ( ! $event->color ) {
					$event->color = '#ffffff';
				}

				if ( ! $event->visibility ) {
					$event->visibility = 'public';
				}
			}
		);

		static::deleted(
			function ( $event ) {
				// Iterate bookings as individual model deletes so the
				// BookingModel::deleted listener fires for each row — that's
				// what releases the BookedSlotModel slot, clears booking meta
				// and logs, and removes host attachments. Mass deletes
				// (->bookings()->delete()) skip model events entirely.
				// chunk() bounds memory for events with many historical
				// bookings; cursor() was tried first but returned models
				// whose ->delete() silently no-ops when called inside this
				// listener, so we use chunk() instead.
				$event->bookings()->chunk(
					500,
					function ( $bookings ) {
						foreach ( $bookings as $booking ) {
							$booking->delete();
						}
					}
				);
				$event->meta()->delete();
			}
		);

		static::updating(
			function ( $event ) {

				$event->updateSystemFields();
			}
		);
	}
}
