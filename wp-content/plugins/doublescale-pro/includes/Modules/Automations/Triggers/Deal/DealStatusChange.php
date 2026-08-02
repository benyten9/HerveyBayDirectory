<?php

namespace DoubleScale\Pro\Modules\Automations\Triggers\Deal;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;

class DealStatusChange extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Deal Status changes';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'deal_status_change';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a deal status is changed.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Trigger Source
	 *
	 * @var string
	 */
	public $source = 'sales';

	/**
	 * Trigger Group
	 *
	 * @var string
	 */
	public $group = 'deal';

	/**
	 * Trigger Attributes Schema
	 *
	 * @var array
	 */
	public $attributes_schema = array();




	public function __construct() {
		 parent::__construct();
	}

	public function load_hooks() {
		add_action( 'doublescale_automation_deal_status_changed', array( $this, 'deal_status_changed' ), 10, 4 );
	}

	public function deal_status_changed( $contact, $deal, $old_status, $new_status ) {
		$this->process(
			array(
				'contact' => $contact,
				'deal'    => $deal,
				'data'    => array(
					'old_status' => $old_status,
					'new_status' => $new_status,
					'deal_id'    => $deal->id,
				),
			)
		);
	}

	/**
	 * Is Processable
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation
	 * @param array           $args
	 *
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$status = $automation->get_setting( 'status', '' );
		if ( $args['data']['new_status'] === $args['data']['old_status'] ) {
			return false;
		}
		return $args['data']['new_status'] === $status;
	}

	public function get_fields() {
		return array(
			'status' => array(
				'type'    => 'select',
				'label'   => __( 'Deal status changes to', 'doublescale' ),
				'options' => array(
					'open' => __( 'Open', 'doublescale' ),
					'won'  => __( 'Won', 'doublescale' ),
					'lost' => __( 'Lost', 'doublescale' ),
				),
			),
		);
	}

	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'status' => array(
					'type' => 'string',
				),
			),
		);
	}
}
