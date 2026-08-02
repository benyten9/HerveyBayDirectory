<?php

/**
 * Class DealModel
 * This class is responsible for handling the deal model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Deals\Models;

use WPEloquent\Eloquent\Model;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\Models\UserModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;

/**
 * DealModel class
 */
class DealModel extends Model
{
	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_deals';

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
		'title',
		'contact_id',
		'pipeline_id',
		'stage_id',
		'value',
		'currency', // Writable only by server freeze paths; clients never set this.
		'expected_close_date',
		'probability',
		'priority',
		'status',
		'owner_id',
		'source',
		'lost_reason',
		'won_time',
		'lost_time',
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
	 * Attributes to append to model's array/JSON form
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $appends = array(
		'currency',
	);

	/**
	 * Cast attributes
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $casts = array(
		'value'       => 'float',
		'probability' => 'float',
		'won_time'    => 'datetime',
		'lost_time'   => 'datetime',
	);

	/**
	 * Rules
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public $rules = array(
		'title'               => 'required|string|max:255',
		'contact_id'          => 'required|integer',
		'pipeline_id'         => 'required|integer',
		'stage_id'            => 'required|integer',
		'value'               => 'nullable|numeric|min:0',
		'expected_close_date' => 'nullable|date_format:Y-m-d',
		'probability'         => 'nullable|numeric|between:0,100',
		'priority'            => 'nullable|in:low,medium,high',
		'status'              => 'required|in:open,won,lost',
		'owner_id'            => 'nullable|integer|min:1',
		'won_time'            => 'nullable|date_format:Y-m-d H:i:s',
		'lost_time'           => 'nullable|date_format:Y-m-d H:i:s',
	);

	/**
	 * Messages
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public $messages = array(
		'title.required'               => 'Deal title is required.',
		'title.max'                    => 'Deal title must not exceed 255 characters.',
		'contact_id.required'          => 'Contact is required.',
		'pipeline_id.required'         => 'Pipeline is required.',
		'stage_id.required'            => 'Stage is required.',
		'value.numeric'                => 'Deal value must be a number.',
		'value.min'                    => 'Deal value cannot be negative.',
		'expected_close_date.date_format' => 'Expected close date must be in Y-m-d format (e.g., 2025-12-31).',
		'probability.numeric'          => 'Probability must be a number.',
		'probability.between'          => 'Probability must be between 0 and 100.',
		'priority.in'                  => 'Priority must be low, medium, or high.',
		'status.in'                    => 'Status must be open, won, or lost.',
		'owner_id.min'                 => 'Owner ID must be a positive number.',
		'won_time.date_format'         => 'Won time must be in Y-m-d H:i:s format.',
		'lost_time.date_format'        => 'Lost time must be in Y-m-d H:i:s format.',
	);

	/**
	 * Get the contact this deal belongs to
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact()
	{
		return $this->belongsTo(ContactModel::class, 'contact_id', 'id');
	}

	/**
	 * Get the pipeline this deal belongs to
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function pipeline()
	{
		return $this->belongsTo(PipelineModel::class, 'pipeline_id', 'id');
	}

	/**
	 * Get the stage this deal is in
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function stage()
	{
		return $this->belongsTo(PipelineStageModel::class, 'stage_id', 'id');
	}

	/**
	 * Get the deal owner (WordPress user)
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function owner()
	{
		return $this->belongsTo(UserModel::class, 'owner_id', 'ID');
	}

	/**
	 * Get the deal activities
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany|null
	 */
	public function activities()
	{
		return $this->associatedActivities();
	}

	/**
	 * Get activity associations where this deal is the entity
	 * This is the reverse relationship from activity_associations table
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function activityAssociations()
	{
		if (class_exists('\DoubleScale\Modules\Activities\Models\ActivityAssociationModel')) {
			return $this->hasMany('\DoubleScale\Modules\Activities\Models\ActivityAssociationModel', 'entity_id', 'id')
				->where('entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL);
		}
		return null;
	}

	/**
	 * Get all activities linked to this deal through activity_associations
	 * This includes activities that may not have deal_id set but are linked via associations
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough|null
	 */
	public function associatedActivities()
	{
		if (class_exists('\DoubleScale\Modules\Activities\Models\ActivityAssociationModel')) {
			global $wpdb;
			$association_table = $wpdb->prefix . 'doublescale_activity_associations';

			return $this->hasManyThrough(
				ActivityModel::class,
				'\DoubleScale\Modules\Activities\Models\ActivityAssociationModel',
				'entity_id',     // Foreign key on activity_associations table
				'id',            // Foreign key on activities table
				'id',            // Local key on deals table
				'activity_id'    // Local key on activity_associations table
			)->where($association_table . '.entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL);
		}
		return null;
	}

	/**
	 * Resolve deal currency: frozen stored value, or global settings when NULL.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw column value (may be null).
	 * @return string Currency code (e.g., 'USD', 'EUR')
	 */
	public function getCurrencyAttribute( $value = null )
	{
		$stored = $value;
		if ( null === $stored && isset( $this->attributes['currency'] ) ) {
			$stored = $this->attributes['currency'];
		}

		return \DoubleScale\Core\Settings\Settings::deal_currency( $stored );
	}

	/**
	 * Check if deal is overdue
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function getIsOverdueAttribute()
	{
		if (! $this->expected_close_date || $this->status !== 'open') {
			return false;
		}

		try {
			$expected_date = new \DateTime($this->expected_close_date);
			$now           = new \DateTime();
			return $expected_date < $now;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Get days until close
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function getDaysUntilCloseAttribute()
	{
		if (! $this->expected_close_date) {
			return null;
		}

		try {
			$expected_date = new \DateTime($this->expected_close_date);
			$now           = new \DateTime();
			$diff          = $now->diff($expected_date);
			return $diff->days * ($expected_date > $now ? 1 : -1);
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Get the custom fields
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function custom_fields()
	{
		return $this->belongsToMany(CustomFieldModel::class, 'doublescale_custom_field_relationship', 'entity_id', 'custom_field_id')
			->withPivot('value')
			->wherePivot('entity_type', 'deal');
	}


	/**
	 * Get the deal custom field value
	 *
	 * @since 1.0.0
	 *
	 * @param int $custom_field_id Custom field ID
	 *
	 * @return string
	 */
	public function get_custom_field($custom_field_id)
	{
		$custom_field = $this->custom_fields->where('id', $custom_field_id)->first();
		if ($custom_field) {
			return $custom_field->pivot->value ?? '';
		}

		return null;
	}


	/**
	 * Sync custom fields to deal
	 *
	 * @since 1.0.0
	 *
	 * @param array $custom_fields Custom fields.
	 *
	 * @return void|WP_Error
	 */
	public function sync_custom_fields($custom_fields)
	{
		try {
			$normalized_fields = CustomFieldModel::normalize_submission($custom_fields ?: array());

			$required_fields = CustomFieldModel::where('scope', 'deal')->get();
			foreach ($required_fields as $field_model) {
				if (!$field_model->is_required_field()) {
					continue;
				}

				if (array_key_exists($field_model->id, $normalized_fields)) {
					$value = $normalized_fields[ $field_model->id ];
				} elseif ($this->exists) {
					$value = $this->get_custom_field($field_model->id);
				} else {
					$value = null;
				}

				$validated = $field_model->validate_submission_value($value);
				if (is_wp_error($validated)) {
					return $validated;
				}
			}

			if (empty($normalized_fields)) {
				return;
			}

			$custom_fields_arr = array();

			foreach ($normalized_fields as $field_id => $value) {
				$custom_field_model = CustomFieldModel::find($field_id);
				if (!$custom_field_model) {
					continue;
				}

				$validated = $custom_field_model->validate_submission_value($value);
				if (is_wp_error($validated)) {
					return $validated;
				}

				if (is_array($value)) {
					$value = implode(',', $value);
				}

				$custom_fields_arr[$field_id] = array(
					'value'       => $value,
					'entity_type' => 'deal',
				);
			}

			$this->custom_fields()->syncWithoutDetaching($custom_fields_arr);
		} catch (Exception $e) {
			return new WP_Error('error', $e->getMessage(), array('status' => 400));
		}
	}


	/**
	 * Get weighted value based on stage win probability
	 * Get weighted value based on deal or stage win probability
	 *
	 * @since 1.0.0
	 *
	 * @return float
	 */
	public function getWeightedValueAttribute()
	{
		$stage = $this->stage;
		if (! $stage) {
			return 0;
		}

		// Use deal's custom probability if set, otherwise use stage default
		$probability = $this->probability ?? $stage->win_probability;
		return $this->value * ($probability / 100);
	}

	/**
	 * Move deal to a different stage
	 *
	 * @since 1.0.0
	 *
	 * @param int      $stage_id New stage ID
	 * @param int|null $user_id User making the change
	 * @param bool     $update_probability Whether to update deal probability to match new stage (default: false to preserve custom values)
	 *
	 * @return bool
	 */
	public function moveToStage($stage_id, $user_id = null, $update_probability = false)
	{
		$old_stage_id = $this->stage_id;

		if ($old_stage_id == $stage_id) {
			return true;
		}

		$old_probability = $this->probability;
		$this->stage_id  = $stage_id;

		// Optionally update probability to match new stage
		if ($update_probability) {
			$new_stage = PipelineStageModel::find($stage_id);
			if ($new_stage) {
				$this->probability = $new_stage->win_probability;
			}
		}

		$saved = $this->save();

		if ($saved) {
			// Prepare activity data
			$activity_data = array(
				'old_stage_id' => $old_stage_id,
				'new_stage_id' => $stage_id,
			);

			// Include probability change if it was updated
			if ($update_probability && $old_probability !== $this->probability) {
				$activity_data['old_probability'] = $old_probability;
				$activity_data['new_probability'] = $this->probability;
			}

			// Log the stage change activity
			$activity = ActivityModel::create(
				array(
					'contact_id'    => $this->contact_id,
					'activity_type' => ActivityTypes::STAGE_CHANGED,
					'data'          => $activity_data,
					'user_id'       => $user_id,
				)
			);

			// Create activity association with this deal
			if ($activity && class_exists('\DoubleScale\Modules\Activities\Models\ActivityAssociationModel')) {
				\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::create(
					array(
						'activity_id' => $activity->id,
						'entity_type' => \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL,
						'entity_id'   => $this->id,
					)
				);
			}

			do_action('doublescale_deal_stage_changed', $this->contact, $this, $old_stage_id, $stage_id);
		}

		return $saved;
	}


	/**
	 * Boot method
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot()
	{
		parent::boot();

		static::created(
			function ($deal) {
				// Log deal creation activity
				$activity = ActivityModel::create(
					array(
						'contact_id'    => $deal->contact_id,
						'activity_type' => ActivityTypes::DEAL_CREATED,
						'data'          => array(
							'title' => $deal->title,
							'value' => $deal->value,
						),
						'user_id'       => get_current_user_id(),
					)
				);

				// Create activity association with this deal
				if ($activity && class_exists('\DoubleScale\Modules\Activities\Models\ActivityAssociationModel')) {
					\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::create(
						array(
							'activity_id' => $activity->id,
							'entity_type' => \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL,
							'entity_id'   => $deal->id,
						)
					);
				}

				do_action('doublescale_deal_created', $deal);
			}
		);

		static::updated(
			function ($deal) {
				$changes = $deal->getChanges();

				// Log value changes
				if (isset($changes['value'])) {
					$activity = ActivityModel::create(
						array(
							'contact_id'    => $deal->contact_id,
							'activity_type' => ActivityTypes::VALUE_CHANGED,
							'data'          => array(
								'old_value' => $deal->getOriginal('value'),
								'new_value' => $changes['value'],
							),
							'user_id'       => get_current_user_id(),
						)
					);

					// Create activity association with this deal
					if ($activity && class_exists('\DoubleScale\Modules\Activities\Models\ActivityAssociationModel')) {
						\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::create(
							array(
								'activity_id' => $activity->id,
								'entity_type' => \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL,
								'entity_id'   => $deal->id,
							)
						);
					}
				}

				do_action('doublescale_deal_updated', $deal, $changes);
			}
		);

		static::deleting(
			function ($deal) {
				// Delete all activity associations for this deal
				if (class_exists('\DoubleScale\Modules\Activities\Models\ActivityAssociationModel')) {
					\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::where('entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL)
						->where('entity_id', $deal->id)
						->delete();
				}

				// Note: We don't delete the activities themselves as they might be associated 
				// with other entities or directly with contacts. Only the associations are removed.
			}
		);
	}

	/**
	 * Get status according to stage probability
	 *
	 * @since 1.0.0
	 *
	 * @param float $stage_probability Stage win probability
	 *
	 * @return string
	 */
	public static function get_status_from_probability($stage_probability)
	{
		if ($stage_probability == 100) {
			return 'won';
		} elseif ($stage_probability == 0) {
			return 'lost';
		}
		return 'open';
	}
}
