<?php

/**
 * Class AutomationContactModel
 * This class is responsible for handling the Automation Contact model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactProcessesModel;

/**
 * AutomationContactModel class
 */
class AutomationContactModel extends Model {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_automation_contacts';

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
		'contact_id',
		'automation_id',
		'current_step',
		'next_step',
		'status',
		'data',
		'execution_time',
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
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'data'          => 'array',
		'contact_id'    => 'integer',
		'automation_id' => 'integer',
		'current_step'  => 'integer',
		'next_step'     => 'integer',
	);

	/**
	 * Automation
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function automation() {
		return $this->belongsTo( AutomationModel::class, 'automation_id', 'id' );
	}

	/**
	 * Contact
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( ContactModel::class, 'contact_id', 'id' );
	}

	/**
	 * Next step
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function next_step() {
		return $this->belongsTo( AutomationStepModel::class, 'next_step' );
	}

	/**
	 * Current step
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function current_step() {
		return $this->belongsTo( AutomationStepModel::class, 'current_step' );
	}

	/**
	 * Processes
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function processes() {
		return $this->hasMany( AutomationContactProcessesModel::class, 'automation_contact_id', 'id' );
	}

	/**
	 * Get data
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 *
	 * @return mixed
	 */
	public function get_data( $key, $default = null ) {
		return $this->data[ $key ] ?? $default;
	}


	/**
	 * Set data
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Data.
	 *
	 * @return void
	 */
	public function set_data( $data ) {
		$this->data = array_merge( $this->data, $data );
		$this->save();
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

		// Delete all the contact processes when the contact is deleted
		static::deleting(
			function ( $automation_contact ) {
				$automation_contact->processes()->delete();
			}
		);
	}
}
