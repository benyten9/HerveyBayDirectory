<?php
/**
 * Class AutomationContactProcessesModel
 * This class is responsible for handling the AutomationContactProcessesModel model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * AutomationContactProcessesModel class
 */
class AutomationContactProcessesModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_automation_contact_processes';

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
		'step_id',
		'contact_id',
		'automation_id',
		'automation_contact_id',
		'status',
		'created_at',
		'updated_at',
	);

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'settings'              => 'array',
		'step_id'               => 'integer',
		'contact_id'            => 'integer',
		'automation_id'         => 'integer',
		'automation_contact_id' => 'integer',
	);

	/**
	 * Step model
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function step() {
		return $this->belongsTo( 'DoubleScale\Modules\Automations\Models\AutomationStepModel', 'step_id', 'id' );
	}

	/**
	 * Contact model
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( 'DoubleScale\Modules\Contacts\Models\ContactModel', 'contact_id', 'id' );
	}

	/**
	 * Automation model
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function automation() {
		return $this->belongsTo( 'DoubleScale\Modules\Automations\Models\AutomationModel', 'automation_id', 'id' );
	}

	/**
	 * Automation Contacts
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function automation_contact() {
		return $this->belongsTo( 'DoubleScale\Modules\Automations\Models\AutomationContactModel', 'automation_contact_id', 'id' );
	}
}
