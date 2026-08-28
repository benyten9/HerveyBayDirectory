<?php
/**
 * Project model.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\Models;

use WPEloquent\Eloquent\Model;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\Models\UserModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Core\Constants\TaskStatus;
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
		'deal_id',
		'budget',
		'start_date',
		'due_date',
		'owner_id',
		'position',
		'progress',
		'calculate_progress',
		'created_at',
		'updated_at',
	);

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
	 * @var array<string, string>
	 */
	protected $casts = array(
		'budget'             => 'float',
		'position'           => 'integer',
		'progress'           => 'integer',
		'calculate_progress' => 'boolean',
	);

	/**
	 * @var array<string, string>
	 */
	public $rules = array(
		'title'       => 'required|string|max:255',
		'description' => 'nullable|string',
		'status_id'   => 'required|integer',
		'contact_id'  => 'nullable|integer',
		'deal_id'     => 'nullable|integer',
		'budget'      => 'nullable|numeric|min:0',
		'start_date'  => 'nullable|date_format:Y-m-d',
		'due_date'    => 'nullable|date_format:Y-m-d',
		'owner_id'    => 'nullable|integer|min:1',
		'position'    => 'nullable|integer',
		'progress'    => 'nullable|integer|min:0|max:100',
		'calculate_progress' => 'nullable|boolean',
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
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function discussions() {
		return $this->hasMany( ProjectDiscussionModel::class, 'project_id', 'id' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function status() {
		return $this->belongsTo( ProjectStatusModel::class, 'status_id', 'id' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( ContactModel::class, 'contact_id', 'id' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo|null
	 */
	public function deal() {
		if ( ! class_exists( DealModel::class ) ) {
			return null;
		}
		return $this->belongsTo( DealModel::class, 'deal_id', 'id' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function owner() {
		return $this->belongsTo( UserModel::class, 'owner_id', 'ID' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany|null
	 */
	public function tasks() {
		if ( ! class_exists( TaskModel::class ) ) {
			return null;
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
	public function getTaskProgressAttribute() {
		$zero = array(
			'total'     => 0,
			'completed' => 0,
			'percent'   => 0,
		);

		if ( ! $this->id || null === $this->tasks() ) {
			return $zero;
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
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany|null
	 */
	public function activityAssociations() {
		if ( ! class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
			return null;
		}
		return $this->hasMany( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel', 'entity_id', 'id' )
			->where( 'entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough|null
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
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function custom_fields() {
		return $this->belongsToMany( CustomFieldModel::class, 'doublescale_custom_field_relationship', 'entity_id', 'custom_field_id' )
			->withPivot( 'value' )
			->wherePivot( 'entity_type', 'project' );
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

			$required_fields = CustomFieldModel::where( 'scope', 'project' )->get();
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
		if ( '' === $hash || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
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
