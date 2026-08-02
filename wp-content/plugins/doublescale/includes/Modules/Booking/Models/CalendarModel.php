<?php

namespace DoubleScale\Modules\Booking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use Illuminate\Support\Str;

class CalendarModel extends Model {

	protected $table = 'doublescale_booking_calendars';

	protected $primary_key = 'id';

	public $timestamps = true;

	protected $fillable = array(
		'hash_id',
		'user_id',
		'name',
		'description',
		'slug',
		'status',
		'type',
	);

	protected $appends = array( 'timezone', 'avatar', 'featured_image' );

	protected $casts = array(
		'user_id' => 'integer',
	);

	protected $rules = array(
		'user_id' => 'required|integer',
		'name'    => 'required|string',
		'type'    => 'required',
	);

	protected $messages = array(
		'user_id.required' => 'Calendar user is required',
		'user_id.integer'  => 'Calendar user must be an integer',
		'name.required'    => 'Calendar name is required',
		'name.string'      => 'Calendar name must be a string',
		'type.required'    => 'Calendar type is required',
	);

	public function bookings() {
		return $this->hasMany( BookingModel::class, 'calendar_id' );
	}

	public function meta() {
		return $this->hasMany( CalendarMetaModel::class, 'calendar_id' );
	}

	public function events() {
		return $this->hasMany( EventModel::class, 'calendar_id' );
	}

	public function user() {
		return $this->belongsTo( UserModel::class, 'user_id', 'ID' );
	}

	public function getTimezoneAttribute() {
		$value = $this->meta()->where( 'meta_key', 'timezone' )->value( 'meta_value' );
		return $value ? maybe_unserialize( $value ) : null;
	}

	public function setTimezoneAttribute( $value ) {
		$meta             = $this->meta()->firstOrNew( array( 'meta_key' => 'timezone' ) );
		$meta->meta_value = maybe_serialize( $value );
		$meta->save();
	}

	public function getAvatarAttribute() {
		return $this->get_meta( 'avatar' );
	}

	public function setAvatarAttribute( $value ) {
		$this->update_meta( 'avatar', $value );
	}

	public function getFeaturedImageAttribute() {
		return $this->get_meta( 'featured_image' );
	}

	public function setFeaturedImageAttribute( $value ) {
		$this->update_meta( 'featured_image', $value );
	}

	public function get_meta( $key, $default = null ) {
		$meta = $this->meta()->where( 'meta_key', $key )->first();
		return $meta ? maybe_unserialize( $meta->meta_value ) : $default;
	}

	public function update_meta( $key, $value ) {
		$meta             = $this->meta()->where( 'meta_key', $key )->firstOrNew( array( 'meta_key' => $key ) );
		$meta->meta_value = maybe_serialize( $value );
		$meta->save();
	}

	public function syncTeamMembers( array $memberIds ) {
		$existingMeta = $this->meta()->where( 'meta_key', 'team_members' )->first();

		if ( ! $existingMeta ) {
			$this->meta()->create(
				array(
					'meta_key'   => 'team_members',
					'meta_value' => maybe_serialize( $memberIds ),
				)
			);
		} else {
			$existingMeta->update( array( 'meta_value' => maybe_serialize( array_values( array_unique( $memberIds ) ) ) ) );
		}
	}

	public function getTeamMembers() {
		$teamMembersMeta = $this->meta()->where( 'meta_key', 'team_members' )->first();
		return $teamMembersMeta ? maybe_unserialize( $teamMembersMeta->meta_value ) : array();
	}

	public function getTeamMembersCalendarIds() {
		$teamMembers = $this->getTeamMembers();
		$calendarIds = array();

		if ( empty( $teamMembers ) || ! is_array( $teamMembers ) ) {
			return $calendarIds;
		}

		foreach ( $teamMembers as $member_id ) {
			$calendar = self::where( 'user_id', $member_id )->get();
			foreach ( $calendar as $cal ) {
				$calendarIds[] = $cal->id;
			}
		}
		return $calendarIds;
	}

	public function save( array $options = array() ) {
		if ( ! $this->exists ) {
			$dispatcher = static::getEventDispatcher();
			$model_name = static::class;
			$event_name = "eloquent.creating: {$model_name}";
			$listeners  = $dispatcher->getListeners( $event_name );

			if ( count( $listeners ) === 0 ) {
				$this->registerEventsOnDispatcher( $dispatcher, $model_name );
			}
		}

		return parent::save( $options );
	}

	private function registerEventsOnDispatcher( $dispatcher, $model_name ) {
		$dispatcher->listen(
			"eloquent.creating: {$model_name}",
			function ( $calendar ) {
				$calendar->hash_id = self::generateHashKey();
				$originalSlug      = $slug = Str::slug( $calendar->name );
				$count             = 1;

				while ( static::where( 'slug', $slug )->exists() ) {
					$slug = $originalSlug . '-' . $count++;
				}

				$calendar->slug   = $slug;
				$calendar->status = 'active';

				if ( 'host' === $calendar->type ) {
					$user = new \WP_User( $calendar->user_id );
					if ( ! $user->exists() ) {
						return false;
					}
				}
			}
		);

		$dispatcher->listen(
			"eloquent.deleting: {$model_name}",
			function ( $calendar ) {
				// Cascade in three steps. Events first, because deleting an
				// event fires EventModel::deleted, which cleans its own
				// bookings + slots/meta/logs. We then sweep any bookings that
				// were attached to the calendar directly (no event_id) before
				// finally clearing the calendar's own meta. chunk() bounds
				// memory for calendars with large histories; per-model
				// deletes (not mass delete) so each BookingModel::deleted
				// listener runs and BookedSlotModel rows are released.
				$calendar->events()->chunk(
					500,
					function ( $events ) {
						foreach ( $events as $event ) {
							$event->delete();
						}
					}
				);
				$calendar->bookings()->chunk(
					500,
					function ( $bookings ) {
						foreach ( $bookings as $booking ) {
							$booking->delete();
						}
					}
				);
				$calendar->meta()->delete();
			}
		);

		$dispatcher->listen(
			"eloquent.retrieved: {$model_name}",
			function ( $calendar ) {
				if ( 'team' === $calendar->type ) {
					$calendar->team_members = $calendar->getTeamMembers();
				}
			}
		);
	}

	private static bool $events_registered = false;

	public static function boot() {
		parent::boot();

		// Idempotent registration. Eloquent re-runs boot() per process; a
		// static flag (vs. a global) keeps this scoped to the class so tests
		// can reset it via reflection and long-running runtimes (Roadrunner,
		// Octane, FrankenPHP) don't cross-contaminate the dispatcher between
		// requests.
		if ( self::$events_registered ) {
			return;
		}
		self::$events_registered = true;

		$dispatcher = static::getEventDispatcher();
		if ( ! $dispatcher ) {
			return;
		}

		$model_name = static::class;
		$instance   = new static();
		$instance->registerEventsOnDispatcher( $dispatcher, $model_name );
	}

	private static function generateHashKey() {
		return wp_generate_uuid4();
	}
}
