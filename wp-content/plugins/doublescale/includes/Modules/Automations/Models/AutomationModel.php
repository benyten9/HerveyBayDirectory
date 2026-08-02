<?php

/**
 * Class AutomationModel
 * This class is responsible for handling the Automation model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

/**
 * AutomationModel class
 */
class AutomationModel extends Model {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_automations';

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
		'trigger',
		'status',
		'settings',
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
		'settings' => 'array',
	);

	/**
	 * Rules
	 *
	 * @var array
	 */
	protected $rules = array(
		'name'    => 'required',
		'trigger' => 'required',
	);

	/**
	 * Messages
	 *
	 * @var array
	 */
	protected $messages = array(
		'name.required'    => 'Automation name is required',
		'trigger.required' => 'Automation trigger is required',
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
	 * Contacts
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function contacts() {
		return $this->hasMany( AutomationContactModel::class, 'automation_id', 'id' );
	}

	/**
	 * Steps
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function steps() {
		return $this->hasMany( AutomationStepModel::class, 'automation_id', 'id' );
	}

	/**
	 * Processes
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function processes() {
		return $this->hasMany( AutomationContactProcessesModel::class, 'automation_id', 'id' );
	}

	/**
	 * Get all automation messages
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function messages() {
		return $this->hasMany( CommunicationTrackingModel::class, 'source_id', 'id' )
			->where( 'source_type', \DoubleScale\Core\Constants\MessageSourceTypes::AUTOMATION );
	}

	/**
	 * Get all automations by trigger
	 *
	 * @since 1.0.0
	 *
	 * @param string $trigger Trigger name
	 *
	 * @return AutomationModel[]
	 */
	public static function get_automations_by_trigger( $trigger ) {
		return self::where( 'trigger', $trigger )
			->where( 'status', 'active' )
			->get();
	}

	/**
	 * Get automation steps ordered by order
	 *
	 * @since 1.0.0
	 *
	 * @return AutomationStepModel[]
	 */
	public function get_steps() {
		return $this->steps()
			->orderBy( 'order', 'asc' )
			->get();
	}

	/**
	 * Get first step
	 *
	 * @since 1.0.0
	 *
	 * @return AutomationStepModel
	 */
	public function get_first_step() {
		return $this->steps()
			->where( 'status', 'active' )
			->where( 'parent_id', 0 )
			->orderBy( 'order', 'asc' )
			->first();
	}

	/**
	 * Get last step
	 *
	 * @since 1.0.0
	 *
	 * @return AutomationStepModel
	 */
	public function get_last_step() {
		return $this->steps()
			->where( 'status', 'active' )
			->orderBy( 'order', 'desc' )
			->first();
	}

	/**
	 * Get step by order
	 *
	 * @since 1.0.0
	 *
	 * @param int $order Step order
	 *
	 * @return AutomationStepModel
	 */
	public function get_step_by_order( $order ) {
		return $this->steps()
			->where( 'status', 'active' )
			->where( 'order', $order )
			->first();
	}

	/**
	 * Get Next Step
	 *
	 * @since 1.0.0
	 *
	 * @param object $step Automation Step.
	 *
	 * @return object|bool
	 */
	public function get_next_step( $step ) {
		// For root level steps, get the next root level step
		if ( 0 == $step->parent_id ) {
			return $this->steps()
				->where( 'status', 'active' )
				->where( 'parent_id', 0 )
				->where( 'order', '>', $step->order )
				->orderBy( 'order', 'asc' )
				->first();
		}

		// For branch steps, first look for next step in the same branch
		$next_step = $this->steps()
			->where( 'status', 'active' )
			->where( 'parent_id', $step->parent_id )
			->where( 'condition', $step->condition )
			->where( 'order', '>', $step->order )
			->orderBy( 'order', 'asc' )
			->first();

		// If no more steps in this branch, we need to find what comes after the condition block
		if ( ! $next_step ) {
			// Get the parent condition step
			$parent_step = $this->steps()
				->where( 'id', $step->parent_id )
				->first();

			if ( $parent_step ) {
				// If parent condition is also nested, find next step in its parent context
				if ( $parent_step->parent_id > 0 ) {
					$next_step = $this->steps()
						->where( 'status', 'active' )
						->where( 'parent_id', $parent_step->parent_id )
						->where( 'condition', $parent_step->condition )
						->where( 'order', '>', $parent_step->order )
						->orderBy( 'order', 'asc' )
						->first();
				} else {
					// Parent condition is at root level, find next root level step
					$next_step = $this->steps()
						->where( 'status', 'active' )
						->where( 'parent_id', 0 )
						->where( 'order', '>', $parent_step->order )
						->orderBy( 'order', 'asc' )
						->first();
				}
			}
		}

		return $next_step;
	}

	/**
	 * Get Setting
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Setting key
	 * @param mixed  $default Default value
	 *
	 * @return mixed
	 */
	public function get_setting( $key, $default = null ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
	}

	/**
	 * Set Setting
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Setting key
	 * @param mixed  $value Setting value
	 *
	 * @return void
	 */
	public function set_setting( $key, $value ) {
		$settings         = $this->settings;
		$settings[ $key ] = $value;
		$this->settings   = $settings;
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

		self::deleting(
			function ( $automation ) {
				$automation->contacts()->delete();
				$automation->steps()->delete();
				$automation->processes()->delete();
			}
		);

		// Delete draft steps will retrerive the automation
		self::retrieved(
			function ( $automation ) {
				$automation->steps()
					->where( 'status', 'draft' )
					->delete();
			}
		);
	}
}
