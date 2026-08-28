<?php
/**
 * Automation action: update a project custom field.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Project;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;

defined( 'ABSPATH' ) || exit;

/**
 * UpdateCustomFieldProject action.
 */
class UpdateCustomFieldProject extends BaseProjectAction {

	/**
	 * @var string
	 */
	public $name = 'Update a project custom field';

	/**
	 * @var string
	 */
	public $slug = 'update_custom_field_project';

	/**
	 * @var string
	 */
	public $description = 'This action will update a custom field on the triggering project.';

	/**
	 * @var array
	 */
	public $required_triggers = array(
		'project_created',
		'project_status_changed',
		'project_completed',
		'project_owner_changed',
		'project_due_soon',
		'project_overdue',
		'project_comment_posted',
		'project_converted_from_deal',
	);

	/**
	 * {@inheritdoc}
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$project = $this->resolve_project( $automation_contact );
		if ( ! $project ) {
			return false;
		}

		$custom_fields = $step->get_setting( 'project-custom-fields' );
		if ( empty( $custom_fields ) || ! is_array( $custom_fields ) ) {
			return false;
		}

		$merge_tags_manager = \DoubleScale\Core\MergeTags\MergeTagsManager::instance();
		foreach ( $custom_fields as &$field ) {
			$value = $field['value'] ?? '';
			if ( is_string( $value ) && preg_match( '/{{.*?:.*?}}/', $value ) ) {
				$field['value'] = $merge_tags_manager->process_merge_tags( $value, $automation_contact );
			}
		}
		unset( $field );

		$result = $project->sync_custom_fields( $custom_fields );
		return ! is_wp_error( $result );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array(
			'project-custom-fields' => array(
				'label'   => $this->t( 'Custom Field' ),
				'type'    => 'deal_custom_field_change',
				'options' => $this->get_custom_fields_options(),
			),
		);
	}

	/**
	 * @return array
	 */
	public function get_custom_fields_options() {
		if ( ! AutomationModuleStorage::is_ready( 'custom-fields', CustomFieldModel::class ) ) {
			return array();
		}

		$custom_fields = CustomFieldModel::where( 'scope', 'project' )->get();
		$options       = array();
		foreach ( $custom_fields as $custom_field ) {
			$options[ $custom_field->id ] = array(
				'label'      => $custom_field->name,
				'type'       => $custom_field->type,
				'attributes' => $custom_field->attributes,
			);
		}
		return $options;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'project-custom-fields' => array(
					'type'     => 'array',
					'required' => true,
				),
			),
		);
	}
}