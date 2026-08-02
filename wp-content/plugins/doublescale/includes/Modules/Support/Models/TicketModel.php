<?php
/**
 * Ticket model — workflow state for support tickets.
 *
 * Replies, internal notes, and system activities are NOT children of this
 * model directly; they live in `doublescale_activities` and are linked
 * through `activity_associations.entity_type=3`. The `conversations()`
 * relation hides that join behind a single call.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Core\Models\UserModel;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Models\TagModel;
use DoubleScale\Modules\Support\Constants\TicketPriority;
use DoubleScale\Modules\Support\Constants\TicketStatus;

/**
 * TicketModel class.
 *
 * @property int                                                                 $id
 * @property string                                                              $hash
 * @property string                                                              $title
 * @property string                                                              $status
 * @property string                                                              $priority
 * @property int                                                                 $mailbox_id
 * @property int                                                                 $contact_id
 * @property int|null                                                            $agent_user_id
 * @property string|null                                                         $product
 * @property string|null                                                         $message_id
 * @property int                                                                 $response_count
 * @property array<int, int>|null                                                $tag_ids
 * @property array<string, mixed>|null                                           $custom_data
 * @property string                                                              $created_at
 * @property string                                                              $updated_at
 * @property \DoubleScale\Modules\Contacts\Models\ContactModel|null              $contact
 * @property \DoubleScale\Core\Models\UserModel|null                             $agent
 * @property MailboxModel|null                                                   $mailbox
 * @property \Illuminate\Database\Eloquent\Collection<int, AttachmentModel>      $attachments
 */
class TicketModel extends Model {

	/**
	 * Eloquent prepends `$wpdb->prefix` via the Capsule connection config.
	 *
	 * @var string
	 */
	protected $table = 'doublescale_support_tickets';

	/**
	 * Primary key.
	 *
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected $fillable = array(
		'hash',
		'title',
		'status',
		'priority',
		'mailbox_id',
		'contact_id',
		'agent_user_id',
		'product',
		'message_id',
		'response_count',
		'tag_ids',
		'custom_data',
	);

	/**
	 * Casts — JSON columns decode into arrays on read.
	 *
	 * @var array<string, string>
	 */
	protected $casts = array(
		'tag_ids'     => 'array',
		'custom_data' => 'array',
	);

	/**
	 * Eloquent timestamps (`created_at`, `updated_at`) are enabled.
	 *
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * Customer (the contact who owns this ticket).
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( ContactModel::class, 'contact_id', 'id' );
	}

	/**
	 * Assigned agent (a WordPress user).
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function agent() {
		return $this->belongsTo( UserModel::class, 'agent_user_id', 'ID' );
	}

	/**
	 * Mailbox (channel/department) the ticket belongs to. The `mailbox_id`
	 * column is NOT NULL — every ticket has a mailbox. {@see TicketService::resolve_mailbox_id()}
	 * falls back to the default mailbox when a create omits one, and deleting a
	 * mailbox re-points its tickets to an operator-chosen fallback before the
	 * row is removed (see RestMailboxController::delete_item), so there is no
	 * "no channel" / orphaned-ticket state.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function mailbox() {
		return $this->belongsTo( MailboxModel::class, 'mailbox_id', 'id' );
	}

	/**
	 * Attachments uploaded against this ticket — both temp uploads
	 * (pre-activity) and finalised attachments.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function attachments() {
		return $this->hasMany( AttachmentModel::class, 'attachable_id', 'id' )
			->where( 'attachable_type', AttachmentModel::ATTACHABLE_TYPE );
	}

	/**
	 * Full conversation log — every reply, note, and system event linked to
	 * this ticket through `activity_associations.entity_type=3`.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function conversations() {
		return ActivityModel::forTicket( $this->id );
	}

	/**
	 * Tag models for the IDs stored in `tag_ids` JSON. Returns an empty
	 * collection when `tag_ids` is unset or non-array.
	 *
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	public function tags() {
		$ids = is_array( $this->tag_ids ) ? array_filter( array_map( 'intval', $this->tag_ids ) ) : array();
		if ( empty( $ids ) ) {
			return TagModel::query()->whereRaw( '0=1' )->get();
		}
		return TagModel::query()->whereIn( 'id', $ids )->get();
	}

	/**
	 * Look up a ticket by its public hash.
	 *
	 * @param string $hash Ticket hash.
	 * @return self|null
	 */
	public static function get_by_hash( $hash ) {
		return self::where( 'hash', $hash )->first();
	}

	/**
	 * Scope: filter by exact status.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param string                                $status Status value.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByStatus( $query, $status ) {
		return $query->where( 'status', $status );
	}

	/**
	 * Scope: only open tickets.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeOpen( $query ) {
		return $query->where( 'status', TicketStatus::OPEN );
	}

	/**
	 * Scope: every status except 'closed' — the typical inbox filter.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeActive( $query ) {
		return $query->whereIn( 'status', TicketStatus::get_active_statuses() );
	}

	/**
	 * Scope: filter by assigned agent (WP user id).
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $agent_user_id Agent WP user id.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByAgent( $query, $agent_user_id ) {
		return $query->where( 'agent_user_id', $agent_user_id );
	}

	/**
	 * Scope: filter by customer.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $contact_id Contact id.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByContact( $query, $contact_id ) {
		return $query->where( 'contact_id', $contact_id );
	}

	/**
	 * Scope: filter by mailbox channel.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $mailbox_id Mailbox id.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByMailbox( $query, $mailbox_id ) {
		return $query->where( 'mailbox_id', $mailbox_id );
	}

	/**
	 * Scope: tickets carrying a given tag id (via JSON_CONTAINS on `tag_ids`).
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $tag_id Tag id.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeWithTag( $query, $tag_id ) {
		return $query->whereRaw( 'JSON_CONTAINS(tag_ids, ?)', array( (string) (int) $tag_id ) );
	}

	/**
	 * Boot — auto-fill `hash` on create, cascade-delete attachments + linked
	 * activity associations on delete.
	 *
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		static::creating(
			function ( $ticket ) {
				if ( empty( $ticket->hash ) ) {
					$ticket->hash = self::generate_hash();
				}
				if ( empty( $ticket->priority ) ) {
					$ticket->priority = TicketPriority::NORMAL;
				}
				if ( empty( $ticket->status ) ) {
					$ticket->status = TicketStatus::OPEN;
				}
			}
		);

		static::deleting(
			function ( $ticket ) {
				// Loop-delete attachments so each model's `deleting` event fires
				// and unlinks the physical file from disk.
				foreach ( $ticket->attachments as $attachment ) {
					$attachment->delete();
				}

				// Detach every activity-association row pointing at this ticket.
				// The activities themselves stay (they belong to the contact
				// timeline); only the linkage is removed.
				ActivityAssociationModel::where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_TICKET )
					->where( 'entity_id', $ticket->id )
					->delete();
			}
		);
	}

	/**
	 * Generate a 32-char public identifier suitable for portal URLs.
	 *
	 * @return string
	 */
	private static function generate_hash() {
		try {
			return md5( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return md5( uniqid( (string) wp_rand(), true ) );
		}
	}
}
