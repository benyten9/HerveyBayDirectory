<?php
/**
 * Remove Tags Action
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions;

defined( 'ABSPATH' ) || exit;

use phpDocumentor\Reflection\DocBlock\Tag;
use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\TagModel;

/**
 * Remove Tags Action
 */
class RemoveTags extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove Tags';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'remove_tags';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will remove tags from the contact.';

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
	public $group = 'contact';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel     $automation Automation Model.
	 * @param AutomationStepModel $step Automation Step Model.
	 * @param ContactModel        $contact Contact Model.
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$tags_ids = $step->get_setting( 'tags', array() );
		$tags     = TagModel::find( $tags_ids );

		if ( ! empty( $tags ) ) {
			$tags_ids = wp_list_pluck( $tags->toArray(), 'id' );
			$contact  = $automation_contact->contact;
			$contact->tags()->detach( $tags_ids );
		}

		return true;
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
			'tags' => array(
				'label'    => __( 'Tags', 'doublescale' ),
				'type'     => 'tags',
				'multiple' => true,
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tags' => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'integer',
					),
					'default'  => array(),
					'required' => true,
				),
			),
		);
	}
}

RemoveTags::instance();
