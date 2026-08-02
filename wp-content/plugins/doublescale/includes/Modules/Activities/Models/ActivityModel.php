<?php

/**
 * Activity Model
 * Unified model for all activity types (messages, notes, calls, meetings, system events)
 * Works for both contacts and deals
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Activities\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Models\UserModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

/**
 * ActivityModel class
 */
class ActivityModel extends Model {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_activities';

	/**
	 * Contact IDs pending association write after insert (keyed by spl_object_id).
	 * Must not live on model attributes — Eloquent would try to INSERT them.
	 *
	 * @var array<int, int>
	 */
	private static $pending_contact_ids = array();

	/**
	 * Primary key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $primary_key = 'id';

	/**
	 * Fillable columns.
	 *
	 * contact_id is virtual input only — stripped in creating() and written to
	 * activity_associations in created(); it is not a database column.
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $fillable = array(
		'contact_id',
		'activity_type',
		'data',
		'user_id',
		'activity_date',
		'created_at',
		'updated_at',
	);

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'data' => 'array',
	);

	/**
	 * Attributes to append to model's array/JSON form
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $appends = array(
		'deal_id',
		'contact_id',
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
	 * Validation rules
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $rules = array(
		'activity_type' => '', // Set dynamically in constructor
		'user_id'       => 'nullable|integer',
	);

	/**
	 * Validation messages
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $messages = array(
		'activity_type.required' => 'Activity type is required.',
		'activity_type.in'       => 'Invalid activity type.',
	);

	/**
	 * Constructor
	 *
	 * @param array $attributes Model attributes.
	 */
	public function __construct( array $attributes = array() ) {
		// Set validation rule dynamically from constants
		$this->rules['activity_type'] = ActivityTypes::get_validation_rule();
		parent::__construct( $attributes );
	}

	/**
	 * Contact relationship via polymorphic activity_associations.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasOneThrough
	 */
	public function contact() {
		$assoc_table = $this->associations()->getRelated()->getTable();

		return $this->hasOneThrough(
			ContactModel::class,
			ActivityAssociationModel::class,
			'activity_id',
			'id',
			'id',
			'entity_id'
		)->where( $assoc_table . '.entity_type', ActivityAssociationModel::ENTITY_TYPE_CONTACT );
	}

	/**
	 * User relationship
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function user() {
		return $this->belongsTo( UserModel::class, 'user_id', 'ID' );
	}

	/**
	 * Tracking relationship (for messages that need tracking)
	 *
	 * For individual messages, tracking.source_id points to this activity.
	 * Note: source_type must be INDIVIDUAL (3) for this relationship.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasOne
	 */
	public function tracking() {
		return $this->hasOne( CommunicationTrackingModel::class, 'source_id' )
			->where( 'source_type', \DoubleScale\Core\Constants\MessageSourceTypes::INDIVIDUAL );
	}

	/**



	/**
	 * Comments relationship
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function comments() {
		return $this->hasMany( ActivityCommentModel::class, 'activity_id', 'id' )->orderBy( 'created_at', 'asc' );
	}

	/**
	 * Activity associations relationship (many associations per activity)
	 * Links this activity to deals, companies, or projects
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function associations() {
		return $this->hasMany( ActivityAssociationModel::class, 'activity_id', 'id' );
	}

	/**
	 * Get activity associations of a specific type
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Entity type (deal, company, project).
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function associationsByType( $type ) {
		return $this->associations()->where( 'entity_type', $type );
	}

	/**
	 * Get deal associations for this activity
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function dealAssociations() {
		return $this->associationsByType( ActivityAssociationModel::ENTITY_TYPE_DEAL );
	}

	/**
	 * Get campaign associations for this activity
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function campaignAssociations() {
		return $this->associationsByType( ActivityAssociationModel::ENTITY_TYPE_CAMPAIGN );
	}

	/**
	 * Get ticket associations for this activity
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function ticketAssociations() {
		return $this->associationsByType( ActivityAssociationModel::ENTITY_TYPE_TICKET );
	}

	/**
	 * Task associations (if any).
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function taskAssociations() {
		return $this->associationsByType( ActivityAssociationModel::ENTITY_TYPE_TASK );
	}

	/**
	 * Get contact associations for this activity
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function contactAssociations() {
		return $this->associationsByType( ActivityAssociationModel::ENTITY_TYPE_CONTACT );
	}

	/**
	 * Relations required by appended contact_id / deal_id / task_id accessors.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string>
	 */
	public static function morph_append_relations(): array {
		return array( 'contactAssociations', 'dealAssociations', 'taskAssociations' );
	}

	/**
	 * Eager-load morph rows required by appended contact_id / deal_id / task_id.
	 *
	 * Call on queries that serialize virtual morph ids — not on counts/aggregates.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeWithMorphAppends( $query ) {
		return $query->with( self::morph_append_relations() );
	}

	/**
	 * First entity_id for a typed association relation.
	 *
	 * Resolution order, cheapest first:
	 *   1. The typed relation (e.g. contactAssociations) when eager-loaded via
	 *      {@see scopeWithMorphAppends()} — the preferred, N+1-free path.
	 *   2. The generic `associations` relation when it is already loaded — lets
	 *      callers that loaded `associations` (but not the typed relations) read
	 *      the id without a query.
	 *   3. A single scalar query as a last resort, so appended accessors invoked
	 *      implicitly during toArray()/toJson() degrade gracefully instead of
	 *      throwing when nothing was eager-loaded.
	 *
	 * @param string $typed_relation Eager-loaded relation name (e.g. contactAssociations).
	 * @param int    $entity_type    ENTITY_TYPE_* constant.
	 *
	 * @return int|null
	 */
	private function first_association_entity_id( string $typed_relation, int $entity_type ): ?int {
		if ( $this->relationLoaded( $typed_relation ) ) {
			$association = $this->{$typed_relation}->first();
			return $association ? (int) $association->entity_id : null;
		}

		if ( $this->relationLoaded( 'associations' ) ) {
			$association = $this->associations->first(
				function ( $association ) use ( $entity_type ) {
					return (int) $association->entity_type === $entity_type;
				}
			);
			return $association ? (int) $association->entity_id : null;
		}

		// Nothing eager-loaded: fall back to a single scalar lookup rather than
		// fatal. Callers that serialize many rows should use withMorphAppends().
		if ( ! $this->exists ) {
			return null;
		}

		$entity_id = ActivityAssociationModel::query()
			->where( 'activity_id', $this->id )
			->where( 'entity_type', $entity_type )
			->value( 'entity_id' );

		return null !== $entity_id ? (int) $entity_id : null;
	}

	/**
	 * Convenience accessor: first associated contact's ID, or null.
	 *
	 * Source of truth is the polymorphic contact association.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function getContactIdAttribute() {
		if ( $this->relationLoaded( 'contact' ) && $this->contact ) {
			return (int) $this->contact->id;
		}

		return $this->first_association_entity_id(
			'contactAssociations',
			ActivityAssociationModel::ENTITY_TYPE_CONTACT
		);
	}

	/**
	 * Convenience accessor: first associated deal's ID, or null.
	 *
	 * Activities may be associated with multiple entities; REST callers that
	 * want only the primary deal id read `$activity->deal_id`.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function getDealIdAttribute() {
		return $this->first_association_entity_id(
			'dealAssociations',
			ActivityAssociationModel::ENTITY_TYPE_DEAL
		);
	}

	/**
	 * Convenience accessor: first associated task's ID, or null.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function getTaskIdAttribute() {
		return $this->first_association_entity_id(
			'taskAssociations',
			ActivityAssociationModel::ENTITY_TYPE_TASK
		);
	}

	/**
	 * Scope: Filter by contact
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $contact_id Contact ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForContact( $query, $contact_id ) {
		return $query->whereHas(
			'associations',
			function ( $q ) use ( $contact_id ) {
				$q->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_CONTACT )
					->where( 'entity_id', $contact_id );
			}
		);
	}

	/**
	 * Scope: Filter by deal using activity_associations table
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $deal_id Deal ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForDeal( $query, $deal_id ) {
		return $query->whereHas(
			'associations',
			function ( $q ) use ( $deal_id ) {
				$q->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_DEAL )
					->where( 'entity_id', $deal_id );
			}
		)->orderBy( 'created_at', 'desc' );
	}

	/**
	 * Scope: Filter by support ticket using activity_associations table.
	 *
	 * Returns the conversation log for a ticket — replies, internal notes, and
	 * system events — in chronological order (oldest first) so the ticket
	 * detail view can render the thread top-down.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $ticket_id Ticket ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForTicket( $query, $ticket_id ) {
		return $query->whereHas(
			'associations',
			function ( $q ) use ( $ticket_id ) {
				$q->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_TICKET )
					->where( 'entity_id', $ticket_id );
			}
		)->orderBy( 'created_at', 'asc' );
	}

	/**
	 * Scope: Filter by task using activity_associations table.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $task_id Task ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForTask( $query, $task_id ) {
		return $query->whereHas(
			'associations',
			function ( $q ) use ( $task_id ) {
				$q->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_TASK )
					->where( 'entity_id', $task_id );
			}
		)->orderBy( 'created_at', 'asc' );
	}

	/**
	 * Scope: Filter by activity type
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param string|array                          $type Activity type(s).
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByType( $query, $type ) {
		if ( is_array( $type ) ) {
			return $query->whereIn( 'activity_type', $type );
		}
		return $query->where( 'activity_type', $type );
	}

	/**
	 * Scope: Only messages (email, Sms, WhatsApp)
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeMessages( $query ) {
		return $query->whereIn(
			'activity_type',
			array(
				'email_sent',
				'sms_sent',
				'whatsapp_sent',
				'email_received',
				'sms_received',
				'whatsapp_received',
			)
		);
	}

	/**
	 * Scope: Only activities with tracking
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeTracked( $query ) {
		return $query->has( 'tracking' );
	}

	/**
	 * Scope: Only notes
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeNotes( $query ) {
		return $query->where( 'activity_type', ActivityTypes::NOTE );
	}

	/**
	 * Scope: User-created activities (editable)
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeUserCreated( $query ) {
		return $query->whereIn( 'activity_type', ActivityTypes::get_editable_types() );
	}

	/**
	 * Scope: System-generated activities (immutable)
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeSystemGenerated( $query ) {
		return $query->whereIn( 'activity_type', ActivityTypes::get_system_types() );
	}

	/**
	 * Get formatted activity message
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function getFormattedMessageAttribute() {
		$user_name = $this->user ? $this->user->display_name : __( 'Unknown User', 'doublescale' );

		switch ( $this->activity_type ) {
			case 'created':
				return sprintf(
					/* translators: %s: user name */
					__( '%s created this deal', 'doublescale' ),
					$user_name
				);

			case 'stage_changed':
				$old_stage_name = __( 'Unknown Stage', 'doublescale' );
				$new_stage_name = __( 'Unknown Stage', 'doublescale' );

				if ( class_exists( '\DoubleScale\Pro\Modules\Deals\Models\PipelineStageModel' ) ) {
					$old_stage = \DoubleScale\Pro\Modules\Deals\Models\PipelineStageModel::find( $this->data['old_stage_id'] ?? 0 );
					$new_stage = \DoubleScale\Pro\Modules\Deals\Models\PipelineStageModel::find( $this->data['new_stage_id'] ?? 0 );

					$old_stage_name = $old_stage ? $old_stage->name : $old_stage_name;
					$new_stage_name = $new_stage ? $new_stage->name : $new_stage_name;
				}

				return sprintf(
					/* translators: 1: user name, 2: old stage name, 3: new stage name */
					__( '%1$s moved deal from "%2$s" to "%3$s"', 'doublescale' ),
					$user_name,
					$old_stage_name,
					$new_stage_name
				);

			case 'value_changed':
				return sprintf(
					/* translators: 1: user name, 2: old value, 3: new value */
					__( '%1$s changed deal value from %2$s to %3$s', 'doublescale' ),
					$user_name,
					$this->data['old_value'] ?? 0,
					$this->data['new_value'] ?? 0
				);

			case 'status_changed':
				$status = $this->data['status'] ?? 'unknown';
				if ( 'won' === $status ) {
					return sprintf(
						/* translators: %s: user name */
						__( '%s marked deal as won', 'doublescale' ),
						$user_name
					);
				} elseif ( 'lost' === $status ) {
					$reason = ! empty( $this->data['reason'] ) ? ' - ' . $this->data['reason'] : '';
					return sprintf(
						/* translators: 1: user name, 2: reason */
						__( '%1$s marked deal as lost%2$s', 'doublescale' ),
						$user_name,
						$reason
					);
				}
				return sprintf(
					/* translators: 1: user name, 2: status */
					__( '%1$s changed deal status to %2$s', 'doublescale' ),
					$user_name,
					$status
				);

			case 'note':
				return sprintf(
					/* translators: %s: user name */
					__( '%s added a note', 'doublescale' ),
					$user_name
				);

			case 'email_sent':
				$subject       = $this->data['subject'] ?? '';
				$contact_email = $this->data['contact_email'] ?? '';
				$contact_name  = $this->data['contact_name'] ?? '';
				$is_manual     = self::is_manual_email_log( is_array( $this->data ) ? $this->data : array() );

				$sender = $user_name;
				if ( __( 'Unknown User', 'doublescale' ) === $sender ) {
					$from_email = $this->data['from_email'] ?? '';
					if ( ! empty( $from_email ) ) {
						$sender = $from_email;
					}
				}

				$message = $is_manual
					? sprintf(
						/* translators: %s: user name */
						__( '%s logged an email', 'doublescale' ),
						$sender
					)
					: sprintf(
						/* translators: %s: user name */
						__( '%s sent an email', 'doublescale' ),
						$sender
					);

				if ( ! empty( $subject ) ) {
					$message .= sprintf(
						/* translators: %s: email subject */
						__( ' with subject "%s"', 'doublescale' ),
						$subject
					);
				}

				if ( ! empty( $contact_email ) ) {
					$recipient = ! empty( $contact_name ) ? $contact_name : $contact_email;
					$message  .= sprintf(
						/* translators: %s: recipient */
						__( ' to %s', 'doublescale' ),
						$recipient
					);
				}

				return $message;

			case 'email_received':
				$subject    = $this->data['subject'] ?? '';
				$from_email = $this->data['from_email'] ?? '';

				$sender  = ! empty( $from_email ) ? $from_email : __( 'a contact', 'doublescale' );
				$message = sprintf(
					/* translators: %s: sender email */
					__( '%s sent a reply', 'doublescale' ),
					$sender
				);

				if ( ! empty( $subject ) ) {
					$message .= sprintf(
						/* translators: %s: email subject */
						__( ' with subject "%s"', 'doublescale' ),
						$subject
					);
				}

				return $message;

			case 'call_logged':
				$outcome      = $this->data['outcome'] ?? '';
				$duration     = $this->data['duration'] ?? null;
				$phone_number = $this->data['phone_number'] ?? '';

				$message = sprintf(
					/* translators: %s: user name */
					__( '%s logged a call', 'doublescale' ),
					$user_name
				);

				if ( ! empty( $phone_number ) ) {
					$message .= sprintf(
						/* translators: %s: phone number */
						__( ' to %s', 'doublescale' ),
						$phone_number
					);
				}

				if ( ! empty( $outcome ) ) {
					$message .= sprintf(
						/* translators: %s: call outcome */
						__( ' with outcome: %s', 'doublescale' ),
						$outcome
					);
				}

				if ( $duration ) {
					$message .= sprintf(
						/* translators: %d: duration in minutes */
						__( ' (Duration: %d minutes)', 'doublescale' ),
						$duration
					);
				}

				return $message;

			case 'meeting_scheduled':
				$title         = $this->data['title'] ?? '';
				$scheduled_at  = $this->data['scheduled_at'] ?? '';
				$attendee_name = $this->data['primary_attendee_name'] ?? '';

				$message = sprintf(
					/* translators: %s: user name */
					__( '%s scheduled a meeting', 'doublescale' ),
					$user_name
				);

				if ( ! empty( $title ) ) {
					$message .= sprintf( ' "%s"', $title );
				}

				if ( ! empty( $attendee_name ) ) {
					$message .= sprintf(
						/* translators: %s: attendee name */
						__( ' with %s', 'doublescale' ),
						$attendee_name
					);
				}

				if ( ! empty( $scheduled_at ) ) {
					$formatted_date = date_i18n( 'M j, Y \a\t g:i A', strtotime( $scheduled_at ) );
					$message       .= sprintf(
						/* translators: %s: scheduled date */
						__( ' for %s', 'doublescale' ),
						$formatted_date
					);
				}

				return $message;

			case 'sms_received':
				$sms_from = $this->data['from'] ?? '';
				$message  = __( 'Sms received', 'doublescale' );
				if ( ! empty( $sms_from ) ) {
					$message .= sprintf(
						/* translators: %s: phone number */
						__( ' from %s', 'doublescale' ),
						$sms_from
					);
				}
				return $message;

			case 'whatsapp_received':
				$wa_from = $this->data['from'] ?? '';
				$message = __( 'Whatsapp message received', 'doublescale' );
				if ( ! empty( $wa_from ) ) {
					$message .= sprintf(
						/* translators: %s: phone number */
						__( ' from %s', 'doublescale' ),
						$wa_from
					);
				}
				return $message;

			case 'sms_sent':
				$sms_to  = $this->data['to'] ?? '';
				$message = sprintf(
					/* translators: %s: user name */
					__( '%s sent an Sms', 'doublescale' ),
					$user_name
				);
				if ( ! empty( $sms_to ) ) {
					$message .= sprintf(
						/* translators: %s: phone number */
						__( ' to %s', 'doublescale' ),
						$sms_to
					);
				}
				return $message;

			case 'whatsapp_sent':
				$wa_to   = $this->data['to'] ?? '';
				$message = sprintf(
					/* translators: %s: user name */
					__( '%s sent a WhatsApp message', 'doublescale' ),
					$user_name
				);
				if ( ! empty( $wa_to ) ) {
					$message .= sprintf(
						/* translators: %s: phone number */
						__( ' to %s', 'doublescale' ),
						$wa_to
					);
				}
				return $message;

			case 'logged_in':
				$ip_address = $this->data['ip_address'] ?? '';
				$message    = sprintf(
					/* translators: %s: user name */
					__( '%s logged in', 'doublescale' ),
					$user_name
				);

				if ( ! empty( $ip_address ) ) {
					$message .= sprintf(
						/* translators: %s: IP address */
						__( ' from IP: %s', 'doublescale' ),
						$ip_address
					);
				}

				return $message;

			case 'logged_out':
				return sprintf(
					/* translators: %s: user name */
					__( '%s logged out', 'doublescale' ),
					$user_name
				);

			default:
				return sprintf(
					/* translators: %s: user name */
					__( '%s performed an action', 'doublescale' ),
					$user_name
				);
		}
	}

	/**
	 * Get subject from data JSON
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function get_subject() {
		if ( ! is_array( $this->data ) ) {
			return null;
		}
		return $this->data['subject'] ?? null;
	}

	/**
	 * Get body from data JSON
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function get_body() {
		if ( ! is_array( $this->data ) ) {
			return null;
		}
		return $this->data['body'] ?? null;
	}

	/**
	 * Get content from data JSON (for notes)
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function get_content() {
		if ( ! is_array( $this->data ) ) {
			return null;
		}
		return $this->data['content'] ?? null;
	}

	/**
	 * Get note title from data JSON
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function get_title() {
		if ( ! is_array( $this->data ) ) {
			return null;
		}
		return $this->data['title'] ?? null;
	}

	/**
	 * Search activities by content
	 *
	 * @since 1.0.0
	 *
	 * @param string $search_term Search term.
	 *
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	public static function search_content( $search_term ) {
		return self::whereRaw(
			'JSON_SEARCH(data, "one", ?) IS NOT NULL',
			array( "%{$search_term}%" )
		)->get();
	}

	/**
	 * Get by tracking ID
	 *
	 * @since 1.0.0
	 *
	 * @param int $tracking_id Tracking ID.
	 *
	 * @return ActivityModel|null
	 */
	public static function get_by_tracking_id( $tracking_id ) {
		return self::whereHas(
			'tracking',
			function ( $query ) use ( $tracking_id ) {
				$query->where( 'id', $tracking_id );
			}
		)->first();
	}

	/**
	 * Check if activity is a message type
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_message() {
		return in_array( $this->activity_type, array( 'email_sent', 'sms_sent', 'whatsapp_sent' ), true );
	}

	/**
	 * Check if activity has tracking
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function has_tracking() {
		return $this->tracking()->exists();
	}

	/**
	 * Check if activity is a note
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_note() {
		return 'note' === $this->activity_type;
	}

	/**
	 * Check if activity type is editable (user-created)
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_editable() {
		if ( ActivityTypes::EMAIL_SENT === $this->activity_type ) {
			$data = is_array( $this->data ) ? $this->data : array();
			return self::is_manual_email_log( $data );
		}

		return ActivityTypes::is_editable_type( $this->activity_type );
	}

	/**
	 * Whether an email_sent activity was manually logged (vs sent through the CRM).
	 *
	 * Manual logs are created via "Add Log Email"; sent emails are created by the
	 * inbox send pipeline and carry delivery metadata (message_id / from_email).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Activity data payload.
	 *
	 * @return bool
	 */
	public static function is_manual_email_log( array $data ): bool {
		if ( isset( $data['source'] ) && 'manual' === $data['source'] ) {
			return true;
		}

		if ( isset( $data['source'] ) && 'sent' === $data['source'] ) {
			return false;
		}

		// Legacy rows: manual logs store sent_at but never message_id / from_email.
		if ( ! empty( $data['sent_at'] ) && empty( $data['message_id'] ) && empty( $data['from_email'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if activity type is system-generated (immutable)
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_system_activity() {
		return ActivityTypes::is_system_type( $this->activity_type );
	}

	/**
	 * Transform activity to note format for Api response
	 * Returns format expected by frontend: { id, title, type, note, created_at, updated_at }
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function to_note_format() {
		$data = $this->data ?? array();
		return array(
			'id'         => $this->id,
			'contact_id' => $this->contact_id,
			'deal_id'    => $this->deal_id,
			'title'      => $data['title'] ?? '',
			'type'       => $data['type'] ?? 'note',
			'note'       => $data['content'] ?? $data['note'] ?? '',
			'user_id'    => $this->user_id,
			'created_at' => $this->created_at,
			'updated_at' => $this->updated_at,
		);
	}

	/**
	 * Add a note activity
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Note data (contact_id, deal_id, title, content, user_id).
	 *
	 * @return ActivityModel
	 */
	public static function add_note( $data ) {
		return self::create(
			array(
				'contact_id'    => $data['contact_id'] ?? null,
				'deal_id'       => $data['deal_id'] ?? null,
				'activity_type' => 'note',
				'data'          => array(
					'title'   => $data['title'] ?? '',
					'content' => $data['content'] ?? '',
				),
				'user_id'       => $data['user_id'] ?? get_current_user_id(),
			)
		);
	}

	/**
	 * Log email activity
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Email data.
	 *
	 * @return ActivityModel
	 */
	public static function log_email( $data ) {
		return self::create(
			array(
				'contact_id'    => $data['contact_id'] ?? null,
				'deal_id'       => $data['deal_id'] ?? null,
				'activity_type' => 'email_sent',
				'data'          => array(
					'subject'       => $data['subject'] ?? '',
					'sent_at'       => $data['sent_at'] ?? current_time( 'mysql', true ),
					'contact_email' => $data['contact_email'] ?? '',
					'contact_name'  => $data['contact_name'] ?? '',
					'source'        => 'manual',
				),
				'user_id'       => $data['user_id'] ?? get_current_user_id(),
			)
		);
	}

	/**
	 * Log call activity
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Call data.
	 *
	 * @return ActivityModel
	 */
	public static function log_call( $data ) {
		return self::create(
			array(
				'contact_id'    => $data['contact_id'] ?? null,
				'deal_id'       => $data['deal_id'] ?? null,
				'activity_type' => 'call_logged',
				'data'          => array(
					'duration'     => isset( $data['duration'] ) ? intval( $data['duration'] ) : null,
					'outcome'      => $data['outcome'] ?? '',
					'notes'        => $data['notes'] ?? '',
					'called_at'    => $data['called_at'] ?? current_time( 'mysql', true ),
					'phone_number' => $data['phone_number'] ?? '',
				),
				'user_id'       => $data['user_id'] ?? get_current_user_id(),
			)
		);
	}

	/**
	 * Schedule meeting activity
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Meeting data.
	 *
	 * @return ActivityModel
	 */
	public static function schedule_meeting( $data ) {
		return self::create(
			array(
				'contact_id'    => $data['contact_id'] ?? null,
				'deal_id'       => $data['deal_id'] ?? null,
				'activity_type' => 'meeting_scheduled',
				'data'          => array(
					'title'                  => $data['title'] ?? '',
					'scheduled_at'           => $data['scheduled_at'] ?? '',
					'duration'               => isset( $data['duration'] ) ? intval( $data['duration'] ) : 60,
					'location'               => $data['location'] ?? '',
					'description'            => $data['description'] ?? '',
					'primary_attendee_id'    => $data['primary_attendee_id'] ?? null,
					'primary_attendee_name'  => $data['primary_attendee_name'] ?? '',
					'primary_attendee_email' => $data['primary_attendee_email'] ?? '',
				),
				'user_id'       => $data['user_id'] ?? get_current_user_id(),
			)
		);
	}

	/**
	 * Log user login activity
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Login data.
	 *
	 * @return ActivityModel|null
	 */
	public static function log_login( $data ) {
		$user_id    = $data['user_id'];
		$user_email = $data['user_email'] ?? '';
		$contact    = ContactModel::where( 'email', $user_email )->first();
		if ( ! $contact ) {
			return null;
		}

		return self::create(
			array(
				'contact_id'    => $contact->id,
				'activity_type' => 'logged_in',
				'data'          => array(
					'ip_address' => $data['ip_address'] ?? '',
					'user_agent' => $data['user_agent'] ?? '',
					'login_time' => $data['login_time'] ?? current_time( 'mysql', true ),
				),
				'user_id'       => $user_id,
			)
		);
	}

	/**
	 * Log user logout activity
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Logout data.
	 *
	 * @return ActivityModel|null
	 */
	public static function log_logout( $data ) {
		$user_id    = $data['user_id'];
		$user_email = $data['user_email'] ?? '';
		$contact    = ContactModel::where( 'email', $user_email )->first();
		if ( ! $contact ) {
			return null;
		}
		return self::create(
			array(
				'contact_id'    => $contact->id,
				'activity_type' => 'logged_out',
				'data'          => array(
					'logout_time' => $data['logout_time'] ?? current_time( 'mysql', true ),
				),
				'user_id'       => $user_id,
			)
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
			function ( $activity ) {
				// Note: created_at is handled automatically by Eloquent ($timestamps = true)
				// which uses Carbon::now() (UTC), consistent with the rest of the codebase.

				// Accept contact_id in mass assignment, but never persist it — contact
				// linking lives in activity_associations after the column cleanup.
				if ( array_key_exists( 'contact_id', $activity->attributes ) ) {
					self::$pending_contact_ids[ spl_object_id( $activity ) ] = (int) $activity->attributes['contact_id'];
					unset( $activity->attributes['contact_id'] );
				}

				// Auto-populate activity_date from activity-specific date fields.
				if ( ! $activity->activity_date ) {
					$activity->activity_date = self::extract_activity_date( $activity );
				}
			}
		);

		// When contact_id was provided at create time, write a contact association.
		static::created(
			function ( $activity ) {
				$object_id  = spl_object_id( $activity );
				$contact_id = self::$pending_contact_ids[ $object_id ] ?? 0;
				unset( self::$pending_contact_ids[ $object_id ] );

				if ( $contact_id <= 0 ) {
					return;
				}

				$exists = ActivityAssociationModel::query()
					->where( 'activity_id', $activity->id )
					->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_CONTACT )
					->where( 'entity_id', $contact_id )
					->exists();

				if ( $exists ) {
					return;
				}

				ActivityAssociationModel::create(
					array(
						'activity_id' => $activity->id,
						'entity_type' => ActivityAssociationModel::ENTITY_TYPE_CONTACT,
						'entity_id'   => $contact_id,
					)
				);

				// Keep in-memory relation in sync if it was already loaded empty.
				if ( $activity->relationLoaded( 'associations' ) ) {
					$activity->unsetRelation( 'associations' );
				}
			}
		);

		static::updating(
			function ( $activity ) {
				// contact_id is virtual — never write it to the activities table.
				if ( array_key_exists( 'contact_id', $activity->attributes ) ) {
					unset( $activity->attributes['contact_id'] );
				}

				// Re-sync activity_date when data (JSON) changes.
				if ( $activity->isDirty( 'data' ) ) {
					$activity->activity_date = self::extract_activity_date( $activity );
				}
			}
		);

		static::deleting(
			function ( $activity ) {
				// Delete all comments
				$activity->comments()->delete();
				$activity->associations()->delete();
			}
		);
	}

	/**
	 * Extract the activity-specific date from the data JSON.
	 *
	 * Checks called_at, sent_at, scheduled_at in order and falls back to
	 * created_at. This mirrors the COALESCE logic previously used in SQL.
	 *
	 * @since 1.0.0
	 *
	 * @param ActivityModel $activity The activity instance.
	 *
	 * @return string Date string in MySQL format.
	 */
	private static function extract_activity_date( $activity ) {
		$data = $activity->data;
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}
		if ( is_array( $data ) ) {
			foreach ( array( 'called_at', 'sent_at', 'scheduled_at' ) as $key ) {
				if ( ! empty( $data[ $key ] ) && strtotime( $data[ $key ] ) !== false ) {
					return $data[ $key ];
				}
			}
		}
		$fallback = $activity->created_at;
		return $fallback ? (string) $fallback : current_time( 'mysql', true );
	}
}
