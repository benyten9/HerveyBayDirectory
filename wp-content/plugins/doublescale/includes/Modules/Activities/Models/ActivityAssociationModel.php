<?php

/**
 * Class Activity_Source_Model
 * This class is responsible for handling the activity source model
 * Links activities to their sources (deals, campaigns, companies)
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Activities\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * Activity_Source_Model class
 */
class ActivityAssociationModel extends Model {






	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_activity_associations';

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
		'activity_id',
		'entity_type',
		'entity_id',
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
	 * Entity type constants
	 *
	 * @since 1.0.0
	 */
	const ENTITY_TYPE_DEAL     = 1;
	const ENTITY_TYPE_CAMPAIGN = 2;
	const ENTITY_TYPE_TICKET   = 3;
	const ENTITY_TYPE_TASK     = 4;
	const ENTITY_TYPE_CONTACT  = 5;
	const ENTITY_TYPE_PROPOSAL = 6;
	const ENTITY_TYPE_INVOICE  = 7;
	const ENTITY_TYPE_PROJECT  = 8;

	/**
	 * Validation rules
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $rules = array(
		'activity_id' => 'required|integer',
		'entity_type' => 'required|integer|in:1,2,3,4,5,6,7,8',
		'entity_id'   => 'required|integer',
	);

	/**
	 * Validation messages
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $messages = array(
		'activity_id.required' => 'Activity ID is required.',
		'entity_type.required' => 'Entity type is required.',
		'entity_type.in'       => 'Entity type must be 1 (Deal), 2 (Campaign), 3 (Ticket), 4 (Task), 5 (Contact), 6 (Proposal), 7 (Invoice), or 8 (Project).',
		'entity_id.required'   => 'Entity ID is required.',
	);

	/**
	 * Get the activity this association belongs to
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function activity() {
		return $this->belongsTo( ActivityModel::class, 'activity_id', 'id' );
	}

	/**
	 * Get the associated entity (polymorphic relationship)
	 * This returns the actual deal, campaign, or company
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\MorphTo
	 */
	public function entity() {
		return $this->morphTo( 'entity', 'entity_type', 'entity_id' );
	}

	/**
	 * Get deal association (if entity_type is ENTITY_TYPE_DEAL)
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo|null
	 */
	public function deal() {
		$deal_model = doublescale_resolve_deal_model_class();
		if ( $deal_model ) {
			return $this->belongsTo( $deal_model, 'entity_id', 'id' )
				->where( 'entity_type', self::ENTITY_TYPE_DEAL );
		}
		return null;
	}

	/**
	 * Get campaign association (if entity_type is ENTITY_TYPE_CAMPAIGN)
	 * Note: Campaign model might be in Pro or added later
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo|null
	 */
	public function campaign() {
		if ( class_exists( '\DoubleScale\Modules\Campaigns\Models\CampaignModel' ) ) {
			return $this->belongsTo( '\DoubleScale\Modules\Campaigns\Models\CampaignModel', 'entity_id', 'id' )
				->where( 'entity_type', self::ENTITY_TYPE_CAMPAIGN );
		}
		if ( class_exists( '\DoubleScale\Modules\Tracking\Models\TrackingCampaignModel' ) ) {
			return $this->belongsTo( \DoubleScale\Modules\Tracking\Models\TrackingCampaignModel::class, 'entity_id', 'id' )
				->where( 'entity_type', self::ENTITY_TYPE_CAMPAIGN );
		}
		return null;
	}

	/**
	 * Get contact association (if entity_type is ENTITY_TYPE_CONTACT)
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( \DoubleScale\Modules\Contacts\Models\ContactModel::class, 'entity_id', 'id' )
			->where( 'entity_type', self::ENTITY_TYPE_CONTACT );
	}

	/**
	 * Get proposal association (if entity_type is ENTITY_TYPE_PROPOSAL).
	 * Structure-only until proposal activities are wired through associations.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo|null
	 */
	public function proposal() {
		if ( class_exists( '\DoubleScale\Modules\Documents\Models\ProposalModel' ) ) {
			return $this->belongsTo( '\DoubleScale\Modules\Documents\Models\ProposalModel', 'entity_id', 'id' )
				->where( 'entity_type', self::ENTITY_TYPE_PROPOSAL );
		}
		return null;
	}

	/**
	 * Get invoice association (if entity_type is ENTITY_TYPE_INVOICE).
	 * Structure-only until invoice activities are wired through associations.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo|null
	 */
	public function invoice() {
		if ( class_exists( '\DoubleScale\Modules\Documents\Models\InvoiceModel' ) ) {
			return $this->belongsTo( '\DoubleScale\Modules\Documents\Models\InvoiceModel', 'entity_id', 'id' )
				->where( 'entity_type', self::ENTITY_TYPE_INVOICE );
		}
		return null;
	}

	/**
	 * Get project association (if entity_type is ENTITY_TYPE_PROJECT).
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo|null
	 */
	public function project() {
		if ( class_exists( '\DoubleScale\Pro\Modules\Projects\Models\ProjectModel' ) ) {
			return $this->belongsTo( '\DoubleScale\Pro\Modules\Projects\Models\ProjectModel', 'entity_id', 'id' )
				->where( 'entity_type', self::ENTITY_TYPE_PROJECT );
		}
		return null;
	}

	/**
	 * Scope: Filter by entity type
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param string                                $type Entity type.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByEntityType( $query, $type ) {
		return $query->where( 'entity_type', $type );
	}

	/**
	 * Scope: Filter by entity
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param string                                $type Entity type.
	 * @param int                                   $id Entity ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByEntity( $query, $type, $id ) {
		return $query->where( 'entity_type', $type )->where( 'entity_id', $id );
	}

	/**
	 * Scope: Filter by deal
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $deal_id Deal ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForDeal( $query, $deal_id ) {
		return $query->where( 'entity_type', self::ENTITY_TYPE_DEAL )->where( 'entity_id', $deal_id );
	}

	/**
	 * Scope: Filter by campaign
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $campaign_id Campaign ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForCampaign( $query, $campaign_id ) {
		return $query->where( 'entity_type', self::ENTITY_TYPE_CAMPAIGN )->where( 'entity_id', $campaign_id );
	}

	/**
	 * Scope: Filter by ticket
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $ticket_id Support ticket ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForTicket( $query, $ticket_id ) {
		return $query->where( 'entity_type', self::ENTITY_TYPE_TICKET )->where( 'entity_id', $ticket_id );
	}

	/**
	 * Scope: Filter by task
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $task_id Task ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForTask( $query, $task_id ) {
		return $query->where( 'entity_type', self::ENTITY_TYPE_TASK )->where( 'entity_id', $task_id );
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
		return $query->where( 'entity_type', self::ENTITY_TYPE_CONTACT )->where( 'entity_id', $contact_id );
	}

	/**
	 * Scope: Filter by proposal (structure-only until wired).
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $proposal_id Proposal ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForProposal( $query, $proposal_id ) {
		return $query->where( 'entity_type', self::ENTITY_TYPE_PROPOSAL )->where( 'entity_id', $proposal_id );
	}

	/**
	 * Scope: Filter by invoice (structure-only until wired).
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $invoice_id Invoice ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForInvoice( $query, $invoice_id ) {
		return $query->where( 'entity_type', self::ENTITY_TYPE_INVOICE )->where( 'entity_id', $invoice_id );
	}

	/**
	 * Scope: Filter by project.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $project_id Project ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForProject( $query, $project_id ) {
		return $query->where( 'entity_type', self::ENTITY_TYPE_PROJECT )->where( 'entity_id', $project_id );
	}

	/**
	 * Delete all association rows linking activities to a contact.
	 *
	 * Activities themselves are kept (timeline history); only the morph link is removed.
	 *
	 * @param int $contact_id Contact ID.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function delete_for_contact( int $contact_id ): int {
		if ( $contact_id <= 0 ) {
			return 0;
		}

		return (int) self::query()
			->forContact( $contact_id )
			->delete();
	}

	/**
	 * SQL EXISTS fragment: activity row linked to a contact via associations.
	 *
	 * @param string $activity_id_ref SQL expression for activities.id (e.g. "a.id").
	 * @param string $contact_id_ref  SQL expression for contacts.id.
	 *
	 * @return string
	 */
	public static function sql_activity_linked_to_contact_exists( $activity_id_ref, $contact_id_ref ) {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_activity_associations';
		$type  = self::ENTITY_TYPE_CONTACT;

		return "EXISTS (
			SELECT 1 FROM {$table} aa
			WHERE aa.activity_id = {$activity_id_ref}
			AND aa.entity_type = {$type}
			AND aa.entity_id = {$contact_id_ref}
		)";
	}

	/**
	 * Convert a REST-facing entity-type string to its internal integer constant.
	 *
	 * @param string $entity_type_string Entity type string ('deal', 'campaign', 'ticket').
	 *
	 * @return int|null Entity type integer or null if invalid.
	 */
	public static function string_to_entity_type( $entity_type_string ) {
		$map = array(
			'deal'     => self::ENTITY_TYPE_DEAL,
			'campaign' => self::ENTITY_TYPE_CAMPAIGN,
			'ticket'   => self::ENTITY_TYPE_TICKET,
			'task'     => self::ENTITY_TYPE_TASK,
			'contact'  => self::ENTITY_TYPE_CONTACT,
			'proposal' => self::ENTITY_TYPE_PROPOSAL,
			'invoice'  => self::ENTITY_TYPE_INVOICE,
			'project'  => self::ENTITY_TYPE_PROJECT,
		);

		return $map[ strtolower( $entity_type_string ) ] ?? null;
	}

	/**
	 * Convert an internal entity-type integer to its REST-facing string label.
	 *
	 * @param int $entity_type_int Entity type integer.
	 *
	 * @return string|null Entity type string or null if invalid.
	 */
	public static function entity_type_to_string( $entity_type_int ) {
		$map = array(
			self::ENTITY_TYPE_DEAL     => 'deal',
			self::ENTITY_TYPE_CAMPAIGN => 'campaign',
			self::ENTITY_TYPE_TICKET   => 'ticket',
			self::ENTITY_TYPE_TASK     => 'task',
			self::ENTITY_TYPE_CONTACT  => 'contact',
			self::ENTITY_TYPE_PROPOSAL => 'proposal',
			self::ENTITY_TYPE_INVOICE  => 'invoice',
			self::ENTITY_TYPE_PROJECT  => 'project',
		);

		return $map[ $entity_type_int ] ?? null;
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

		// Validate entity type before creating
		static::creating(
			function ( $association ) {
				// REST may pass entity_type as 'deal'/'campaign'; normalise to int.
				if ( is_string( $association->entity_type ) ) {
					$association->entity_type = self::string_to_entity_type( $association->entity_type );
				}

				$valid_types = array(
					self::ENTITY_TYPE_DEAL,
					self::ENTITY_TYPE_CAMPAIGN,
					self::ENTITY_TYPE_TICKET,
					self::ENTITY_TYPE_TASK,
					self::ENTITY_TYPE_CONTACT,
					self::ENTITY_TYPE_PROPOSAL,
					self::ENTITY_TYPE_INVOICE,
					self::ENTITY_TYPE_PROJECT,
				);

				if ( ! in_array( $association->entity_type, $valid_types, true ) ) {
					throw new \Exception( 'Invalid entity type. Must be 1 (Deal), 2 (Campaign), 3 (Ticket), 4 (Task), 5 (Contact), 6 (Proposal), 7 (Invoice), or 8 (Project).' );
				}
			}
		);
	}
}
