<?php
/**
 * Contact Information Updated Trigger
 *
 * Fires when a contact's profile information is updated by an admin or by the contact.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * ContactInformationUpdated trigger.
 */
class ContactInformationUpdated extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Contact Information Updated';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'contact_information_updated';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger fires when a contact\'s profile information is updated, either by an admin or by the contact themselves.';

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
	public $group = 'contact';

	/**
	 * Load Hooks
	 */
	public function load_hooks() {
		add_action( 'doublescale_contact_update', array( $this, 'contact_updated' ), 10, 2 );
	}

	/**
	 * Handle contact profile update.
	 *
	 * @param ContactModel $contact Updated contact.
	 * @param array        $context Update context.
	 */
	public function contact_updated( ContactModel $contact, $context = array() ) {
		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$changes = $context['changes'] ?? array();
		if ( empty( $changes ) ) {
			return;
		}

		$data = array(
			'contact' => $contact,
			'data'    => array(
				'updated_by' => $context['updated_by'] ?? 'admin',
				'changes'    => $changes,
			),
		);

		$this->process( $data );
	}

	/**
	 * Filter by who performed the update when configured.
	 *
	 * @param AutomationModel $automation Automation model.
	 * @param array           $args       Trigger args.
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$setting = $automation->get_setting( 'updated_by', 'any' );
		if ( 'any' === $setting ) {
			return true;
		}

		$actual = $args['data']['updated_by'] ?? 'admin';

		return $setting === $actual;
	}

	/**
	 * Trigger configuration fields.
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'updated_by' => array(
				'type'          => 'select',
				'label'         => __( 'Updated by', 'doublescale' ),
				'default-value' => 'any',
				'options'       => array(
					'any'     => __( 'Anyone (admin or contact)', 'doublescale' ),
					'admin'   => __( 'Admin only', 'doublescale' ),
					'contact' => __( 'Contact only', 'doublescale' ),
				),
			),
		);
	}

	/**
	 * Attributes schema.
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'updated_by' => array(
					'type' => 'string',
				),
				'changes'    => array(
					'type' => 'object',
				),
			),
		);
	}
}
