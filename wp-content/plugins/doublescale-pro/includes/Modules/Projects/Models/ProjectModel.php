<?php
/**
 * Project model.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\Models;

use DoubleScale\Vendor\WPEloquent\Eloquent\Model;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\Models\UserModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\Projects\Services\ProjectTeam;
use Exception;
use WP_Error;

class ProjectModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_projects';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var array<int, string>
	 */
	protected $fillable = array(
		'title',
		'hash',
		'description',
		'status_id',
		'contact_id',
		'company_id',
		'deal_id',
		'budget',
		'start_date',
		'due_date',
		'owner_id',
		'position',
		'progress',
		'calculate_progress',
		'fee_type',
		'hourly_rate',
		'tax_id',
		'estimated_hours_min',
		'estimated_hours_max',
		'portal_show_time',
		'portal_show_notes',
		'portal_show_tasks',
		'portal_show_tickets',
		'created_at',
		'updated_at',
	);

	/**
	 * Billed by the hour (the default when nothing was chosen).
	 */
	const FEE_HOURLY = 'hourly';

	/**
	 * One agreed price; its amount is the project's budget.
	 */
	const FEE_FIXED = 'fixed';

	/**
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * @var array<int, string>
	 */
	protected $appends = array(
		'currency',
	);

	/**
	 * Relations read from another module's data: [module slug, model class].
	 * A module's tables exist only once it has been switched on, and its data
	 * counts as absent while it is off, so these load only while
	 * doublescale_is_module_storage_ready() says yes (with Sales off there is
	 * no deal to show on a project).
	 */
	private const OTHER_MODULE_RELATIONS = array(
		'deal'          => array( 'deals', \DoubleScale\Pro\Modules\Deals\Models\DealModel::class ),
		'custom_fields' => array( 'custom-fields', \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::class ),
	);

	/**
	 * The relations to eager load, without those of modules that are off.
	 *
	 * @param string[] $relations Relation names.
	 * @return string[]
	 */
	public static function loadable_relations( array $relations ): array {
		return array_values(
			array_filter(
				$relations,
				static function ( $relation ) {
					if ( ! isset( self::OTHER_MODULE_RELATIONS[ $relation ] ) ) {
						return true;
					}
					[ $slug, $model ] = self::OTHER_MODULE_RELATIONS[ $relation ];
					return function_exists( 'doublescale_is_module_storage_ready' )
						&& doublescale_is_module_storage_ready( $slug, $model );
				}
			)
		);
	}

	/**
	 * @var array<string, string>
	 */
	protected $casts = array(
		'budget'             => 'float',
		'position'           => 'integer',
		'progress'           => 'integer',
		'calculate_progress' => 'boolean',
		'hourly_rate'         => 'float',
		'tax_id'              => 'integer',
		'estimated_hours_min' => 'float',
		'estimated_hours_max' => 'float',
		'portal_show_time'    => 'boolean',
		'portal_show_notes'   => 'boolean',
		'portal_show_tasks'   => 'boolean',
		'portal_show_tickets' => 'boolean',
	);

	/**
	 * @var array<string, string>
	 */
	public $rules = array(
		'title'       => 'required|string|max:255',
		'description' => 'nullable|string',
		'status_id'   => 'required|integer',
		'contact_id'  => 'nullable|integer',
		'company_id'  => 'nullable|integer',
		'deal_id'     => 'nullable|integer',
		'budget'      => 'nullable|numeric|min:0',
		'start_date'  => 'nullable|date_format:Y-m-d',
		'due_date'    => 'nullable|date_format:Y-m-d',
		'owner_id'    => 'nullable|integer|min:1',
		'position'    => 'nullable|integer',
		'progress'    => 'nullable|integer|min:0|max:100',
		'calculate_progress' => 'nullable|boolean',
		'fee_type'            => 'nullable|in:hourly,fixed',
		'hourly_rate'         => 'nullable|numeric|min:0',
		'tax_id'              => 'nullable|integer|min:1',
		'estimated_hours_min' => 'nullable|numeric|min:0',
		'estimated_hours_max' => 'nullable|numeric|min:0',
	);

	/**
	 * @var array<string, string>
	 */
	public $messages = array(
		'title.required'      => 'Project title is required.',
		'title.max'           => 'Project title must not exceed 255 characters.',
		'status_id.required'  => 'Project status is required.',
		'budget.numeric'      => 'Budget must be a number.',
		'budget.min'          => 'Budget cannot be negative.',
		'start_date.date_format' => 'Start date must be in Y-m-d format.',
		'due_date.date_format'   => 'Due date must be in Y-m-d format.',
		'owner_id.min'        => 'Owner ID must be a positive number.',
	);

	/**
	 * How the project is billed. A project saved before fee types existed has
	 * none: its budget, when it has one, was its price, so it is fixed price.
	 *
	 * @return string self::FEE_HOURLY or self::FEE_FIXED.
	 */
	public function effectiveFeeType(): string {
		if ( in_array( (string) $this->fee_type, array( self::FEE_HOURLY, self::FEE_FIXED ), true ) ) {
			return (string) $this->fee_type;
		}
		return null !== $this->budget && (float) $this->budget > 0 ? self::FEE_FIXED : self::FEE_HOURLY;
	}

	/**
	 * Scope: the projects a user may read.
	 *
	 * The one place a list decides which projects someone sees; the
	 * single-project check is {@see \DoubleScale\Pro\Modules\Projects\Capabilities::can_read_project()}.
	 * "Their own" means the projects they own or are on the team of.
	 *
	 * @param \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder $query   Query builder.
	 * @param int                                   $user_id WordPress user ID.
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	public function scopeReadableBy( $query, $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id > 0 && ( Permissions::is_crm_manager( $user_id ) || user_can( $user_id, 'doublescale_project_read_all_projects' ) ) ) {
			return $query;
		}

		if ( $user_id <= 0 || ! user_can( $user_id, 'doublescale_project_read_own_projects' ) ) {
			return $query->whereRaw( '1 = 0' );
		}

		return $this->scopeInvolving( $query, $user_id );
	}

	/**
	 * Scope: the projects a user owns or is on the team of, whatever they may
	 * read (the "team member" filter, a manager viewing someone's calendar).
	 *
	 * The OR stays inside its own brackets, so it never widens the caller's
	 * other conditions.
	 *
	 * @param \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder $query   Query builder.
	 * @param int                                   $user_id WordPress user ID.
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	public function scopeInvolving( $query, $user_id ) {
		$user_id = (int) $user_id;
		$table   = $this->getTable();

		return $query->where(
			static function ( $q ) use ( $user_id, $table ) {
				$q->where( $table . '.owner_id', $user_id );
				if ( ProjectTeam::table_exists() ) {
					$q->orWhereIn( $table . '.id', ProjectTeam::subquery_for_user( $user_id ) );
				}
			}
		);
	}

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function members() {
		return $this->hasMany( ProjectMemberModel::class, 'project_id', 'id' );
	}

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function discussions() {
		return $this->hasMany( ProjectDiscussionModel::class, 'project_id', 'id' );
	}

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function status() {
		return $this->belongsTo( ProjectStatusModel::class, 'status_id', 'id' );
	}

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( ContactModel::class, 'contact_id', 'id' );
	}

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo|null
	 */
	public function deal() {
		// Deals are another module's data: matches nothing while it is off.
		if ( ! doublescale_module_data_ready( 'deals' ) ) {
			return $this->belongsTo( static::class, 'deal_id', 'id' )->whereRaw( '1 = 0' );
		}
		return $this->belongsTo( DealModel::class, 'deal_id', 'id' );
	}

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function owner() {
		return $this->belongsTo( UserModel::class, 'owner_id', 'ID' );
	}

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\HasMany|null
	 */
	public function tasks() {
		// Tasks are another module's data: an empty list while it is off.
		if ( ! doublescale_module_data_ready( 'tasks' ) ) {
			return $this->hasMany( static::class, 'id', 'id' )->whereRaw( '1 = 0' );
		}
		return $this->hasMany( TaskModel::class, 'entity_id', 'id' )
			->where( 'entity_type', TaskEntityType::PROJECT );
	}

	/**
	 * Task-completion progress for this project.
	 *
	 * Mirrors TaskModel::getSubtaskProgressAttribute(). Returns zeros when the
	 * Tasks module is unavailable or the project is unsaved.
	 *
	 * @return array{total:int, completed:int, percent:int}
	 */
	/**
	 * Task counts per project id, filled by prime_task_progress() for lists:
	 * array( total, completed ).
	 *
	 * @var array<int, array{0:int, 1:int}>
	 */
	private static $task_progress_cache = array();

	/**
	 * Count tasks for many projects in one query (a list page used to run one
	 * or two count queries per project).
	 *
	 * @param int[] $project_ids Project ids.
	 * @return void
	 */
	public static function prime_task_progress( array $project_ids ) {
		if ( ! doublescale_module_data_ready( 'tasks' ) ) {
			return;
		}
		$ids = array_values( array_diff( array_unique( array_map( 'intval', $project_ids ) ), array_keys( self::$task_progress_cache ) ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			self::$task_progress_cache[ $id ] = array( 0, 0 );
		}
		$rows = TaskModel::query()
			->where( 'entity_type', TaskEntityType::PROJECT )
			->whereIn( 'entity_id', $ids )
			->selectRaw( 'entity_id, COUNT(*) AS total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed', array( TaskStatus::COMPLETED ) )
			->groupBy( 'entity_id' )
			->toBase()
			->get();
		foreach ( $rows as $row ) {
			self::$task_progress_cache[ (int) $row->entity_id ] = array( (int) $row->total, (int) $row->completed );
		}
	}

	public function getTaskProgressAttribute() {
		$zero = array(
			'total'     => 0,
			'completed' => 0,
			'percent'   => 0,
		);

		if ( ! $this->id || null === $this->tasks() ) {
			return $zero;
		}

		if ( isset( self::$task_progress_cache[ (int) $this->id ] ) ) {
			list( $total, $completed ) = self::$task_progress_cache[ (int) $this->id ];
			return 0 === $total ? $zero : array(
				'total'     => $total,
				'completed' => $completed,
				'percent'   => (int) round( ( $completed / $total ) * 100 ),
			);
		}

		$total = (int) $this->tasks()->count();
		if ( 0 === $total ) {
			return $zero;
		}

		$completed = (int) $this->tasks()->where( 'status', TaskStatus::COMPLETED )->count();

		return array(
			'total'     => $total,
			'completed' => $completed,
			'percent'   => (int) round( ( $completed / $total ) * 100 ),
		);
	}

	/**
	 * Effective progress percentage (0-100).
	 *
	 * Auto mode derives it live from completed tasks; manual mode returns the
	 * stored value.
	 *
	 * @return int
	 */
	public function resolveProgress(): int {
		if ( $this->calculate_progress ) {
			$task_progress = $this->task_progress;
			return (int) $task_progress['percent'];
		}

		return (int) ( $this->progress ?? 0 );
	}

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\HasMany|null
	 */
	public function activityAssociations() {
		if ( ! class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
			return null;
		}
		return $this->hasMany( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel', 'entity_id', 'id' )
			->where( 'entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT );
	}

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\HasManyThrough|null
	 */
	public function associatedActivities() {
		if ( ! class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
			return null;
		}
		global $wpdb;
		$association_table = $wpdb->prefix . 'doublescale_activity_associations';

		return $this->hasManyThrough(
			ActivityModel::class,
			'\DoubleScale\Modules\Activities\Models\ActivityAssociationModel',
			'entity_id',
			'id',
			'id',
			'activity_id'
		)->where( $association_table . '.entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT );
	}

	/**
	 * @return string
	 */
	public function getCurrencyAttribute() {
		return \DoubleScale\Pro\Settings::get_currency();
	}

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function custom_fields() {
		return $this->belongsToMany( CustomFieldModel::class, 'doublescale_custom_field_relationship', 'entity_id', 'custom_field_id' )
			->withPivot( 'value' )
			->wherePivot( 'entity_type', 'project' )
			->ordered();
	}

	/**
	 * @param int $custom_field_id Custom field ID.
	 * @return string|null
	 */
	public function get_custom_field( $custom_field_id ) {
		$custom_field = $this->custom_fields->where( 'id', $custom_field_id )->first();
		if ( $custom_field ) {
			return $custom_field->pivot->value ?? '';
		}
		return null;
	}

	/**
	 * @param array $custom_fields Custom fields.
	 * @return void|WP_Error
	 */
	public function sync_custom_fields( $custom_fields ) {
		try {
			$normalized_fields = CustomFieldModel::normalize_submission( $custom_fields ?: array() );

			$required_fields = CustomFieldModel::where( 'scope', 'project' )->ordered()->get();
			foreach ( $required_fields as $field_model ) {
				if ( ! $field_model->is_required_field() ) {
					continue;
				}
				$value     = $normalized_fields[ $field_model->id ] ?? null;
				$validated = $field_model->validate_submission_value( $value );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
			}

			if ( empty( $normalized_fields ) ) {
				return;
			}

			$custom_fields_arr = array();
			foreach ( $normalized_fields as $field_id => $value ) {
				$custom_field_model = CustomFieldModel::find( $field_id );
				if ( ! $custom_field_model ) {
					continue;
				}
				$validated = $custom_field_model->validate_submission_value( $value );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
				if ( is_array( $value ) ) {
					$value = implode( ',', $value );
				}
				$custom_fields_arr[ $field_id ] = array(
					'value'       => $value,
					'entity_type' => 'project',
				);
			}

			$this->custom_fields()->syncWithoutDetaching( $custom_fields_arr );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param int      $status_id New status ID.
	 * @param int|null $user_id   User making the change.
	 * @return bool
	 */
	public function moveToStatus( $status_id, $user_id = null ) {
		$old_status_id = $this->status_id;
		if ( (int) $old_status_id === (int) $status_id ) {
			return true;
		}

		$this->status_id = $status_id;
		$saved           = $this->save();

		if ( $saved ) {
			$activity = ActivityModel::create(
				array(
					'contact_id'    => $this->contact_id,
					'activity_type' => ActivityTypes::PROJECT_STATUS_CHANGED,
					'data'          => array(
						'old_status_id' => $old_status_id,
						'new_status_id' => $status_id,
					),
					'user_id'       => $user_id,
				)
			);

			if ( $activity && class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
				\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::create(
					array(
						'activity_id' => $activity->id,
						'entity_type' => \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT,
						'entity_id'   => $this->id,
					)
				);
			}

			do_action( 'doublescale_project_status_changed', $this, $old_status_id, $status_id );
		}

		return $saved;
	}

	/**
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		static::creating(
			function ( $project ) {
				if ( empty( $project->hash ) ) {
					$project->hash = self::generate_hash();
				}
			}
		);

		// Given to another client: the first client's shared link stops
		// opening it.
		static::updating(
			function ( $project ) {
				$old_contact = (int) $project->getOriginal( 'contact_id' );
				if ( $project->isDirty( 'contact_id' ) && $old_contact > 0 && (int) $project->contact_id !== $old_contact ) {
					$project->hash = self::generate_hash();
				}
			}
		);

		static::created(
			function ( $project ) {
				$activity = ActivityModel::create(
					array(
						'contact_id'    => $project->contact_id,
						'activity_type' => ActivityTypes::PROJECT_CREATED,
						'data'          => array(
							'title'  => $project->title,
							'budget' => $project->budget,
						),
						'user_id'       => get_current_user_id(),
					)
				);

				if ( $activity && class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
					\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::create(
						array(
							'activity_id' => $activity->id,
							'entity_type' => \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT,
							'entity_id'   => $project->id,
						)
					);
				}

				do_action( 'doublescale_project_created', $project );
			}
		);

		static::updated(
			function ( $project ) {
				$changes = $project->getChanges();

				do_action( 'doublescale_project_updated', $project, $changes );

				if ( is_array( $changes ) && array_key_exists( 'owner_id', $changes ) ) {
					$old_owner_id = $project->getOriginal( 'owner_id' );
					$new_owner_id = $project->owner_id;

					/**
					 * Fires when a project's owner changes.
					 *
					 * @param ProjectModel $project      Updated project.
					 * @param int|null     $old_owner_id Previous owner user ID.
					 * @param int|null     $new_owner_id New owner user ID.
					 */
					do_action( 'doublescale_project_owner_changed', $project, $old_owner_id, $new_owner_id );
				}
			}
		);

		static::deleting(
			function ( $project ) {
				if ( class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
					\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::where(
						'entity_type',
						\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT
					)->where( 'entity_id', $project->id )->delete();
				}

				if ( class_exists( ProjectDiscussionModel::class ) ) {
					ProjectDiscussionModel::where( 'project_id', $project->id )->delete();
				}

				ProjectTeam::purge_project( (int) $project->id );

				// Linked support tickets stay; they just lose the link (wherever
				// the tickets table exists, also while Support is off).
				if ( doublescale_table_exists( 'support_tickets' ) ) {
					try {
						\DoubleScale\Modules\Support\Models\TicketModel::where( 'project_id', (int) $project->id )
							->update( array( 'project_id' => null ) );
					} catch ( \Throwable $e ) {
						// Older free without the project_id column: nothing is linked.
						unset( $e );
					}
				}
			}
		);

		static::deleted(
			function ( $project ) {
				do_action( 'doublescale_project_deleted', (int) $project->id, $project );
			}
		);
	}

	/**
	 * @param string $hash Project hash.
	 * @return ProjectModel|null
	 */
	public static function get_by_hash( $hash ) {
		$hash = trim( (string) $hash );
		if ( '' === $hash || ! preg_match( '/^[A-Za-z0-9]{32}$/', $hash ) ) {
			return null;
		}
		return self::query()->where( 'hash', $hash )->first();
	}

	/**
	 * @return string
	 */
	public static function generate_hash() {
		try {
			return md5( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return md5( uniqid( (string) wp_rand(), true ) );
		}
	}
}
