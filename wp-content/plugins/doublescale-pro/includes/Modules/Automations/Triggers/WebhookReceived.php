<?php

/**
 * Webhook Received Trigger
 *
 * This trigger will be fired when a webhook is received.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * Webhook Received
 */
class WebhookReceived extends Trigger {


	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Webhook Received';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'webhook_received';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a webhook is received.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'webhooks';

	/**
	 * Load hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'doublescale_webhook_receive', array( $this, 'webhook_received' ), 10, 2 );
	}

	/**
	 * Webhook Received
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array            $payload Payload.
	 *
	 * @return void
	 */
	public function webhook_received( $automation, $payload ) {
		$automation->set_setting( 'payload', $payload );
		$automation->set_setting( 'received_at', current_time( 'mysql' ) );
		$automation->save();

		// Process the automation
		$this->process_webhook_automation( $automation, $payload );
	}

	/**
	 * Process the automation
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array            $payload Payload.
	 *
	 * @return void
	 */
	public function process_webhook_automation( $automation, $payload ) {
		$mapped_fields = $automation->get_setting( 'mapped_fields', array() );
		if ( empty( $mapped_fields ) ) {
			return;
		}

		$contact_data = array();
		foreach ( $mapped_fields as $key => $field ) {
			if ( isset( $payload[ $key ] ) ) {
				$contact_data[ $field ] = $payload[ $key ];
			}
		}

		if ( empty( $contact_data ) ) {
			return;
		}

		try {
			$contact = ContactModel::createOrUpdate( $contact_data );

			$data = array(
				'contact' => $contact,
			);

			$this->process( $data );
		} catch ( \Exception $e ) {
			error_log( 'Error creating contact: ' . $e->getMessage() );
		}
	}

	/**
	 * Set automation attributes
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation.
	 *
	 * @return void
	 */
	public function set_settings( $automation ) {
		$random_string = bin2hex( random_bytes( 16 ) );
		$automation->set_setting( 'webhook_key', $random_string );
		$automation->save();
	}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'mapped_fields' => array(
				'label'  => __( 'Mapped Fields', 'doublescale'),
				'type'   => 'mapped_fields',
				'fields' => array(
					'first_name' => array(
						'label' => __( 'First Name', 'doublescale'),
					),
					'last_name'  => array(
						'label' => __( 'Last Name', 'doublescale'),
					),
					'email'      => array(
						'label' => __( 'Email', 'doublescale'),
					),
				),
			),
		);
	}
}
