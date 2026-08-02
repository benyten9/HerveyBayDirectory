<?php

/**
 * Delay Action
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Delays;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\PluginKernel;

/**
 * Delay Action
 */
class Delay extends Action {


	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Wait X Days/Hours/Minutes';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'delay';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will delay the automation for a specified amount of time.';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Auto enqueue step
	 *
	 * @var bool
	 */
	public $auto_enqueue = false;

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'delay';

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel        $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $contact Contact Model.
	 *
	 * @return bool
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		// Schedule the next step after 2 minutes
		$next_step = $automation->get_next_step( $step );
		$time      = null;
		$delay     = $step->get_setting( 'delay' );
		$unit      = $step->get_setting( 'unit' );

		switch ( $unit ) {
			case 'minutes':
				$time = strtotime( "+{$delay} minutes" );
				break;
			case 'hours':
				$time = strtotime( "+{$delay} hours" );
				break;
			case 'days':
				$time = strtotime( "+{$delay} days" );
				break;
		}
		if ( ! $next_step ) {
			return false;
		}

		PluginKernel::instance()->automations_tasks->schedule_single( $time, 'process_automation_step', $automation->id, $step->id, $next_step->id, $automation_contact->id );

		return true;
	}

	/**
	 * Get fields.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'delay' => array(
				'type'  => 'number',
				'label' => __( 'Delay', 'doublescale' ),
			),
			'unit'  => array(
				'type'    => 'select',
				'label'   => __( 'Unit', 'doublescale' ),
				'options' => array(
					'minutes' => __( 'Minutes', 'doublescale' ),
					'hours'   => __( 'Hours', 'doublescale' ),
					'days'    => __( 'Days', 'doublescale' ),
				),
			),
		);
	}

	/**
	 * Get Attributes Schema
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'delay' => array(
					'type'    => 'string',
					'title'   => 'Delay',
					'default' => '1',
				),
				'unit'  => array(
					'type'    => 'string',
					'title'   => 'Unit',
					'default' => 'minutes',
					'enum'    => array( 'minutes', 'hours', 'days' ),
				),
			),
		);
	}
}

Delay::instance();
