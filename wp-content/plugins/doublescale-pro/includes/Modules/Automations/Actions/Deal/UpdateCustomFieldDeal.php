<?php

namespace DoubleScale\Pro\Modules\Automations\Actions\Deal;


use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;

// Use global function via fully-qualified call when needed.

class UpdateCustomFieldDeal extends BaseDealAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Update a deal custom field';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'update_custom_field_deal';


	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will update a deal custom field.';



	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();



	/**
	 * Action Source
	 *
	 * @var string
	 */
	public $source = 'sales';

	/**
	 * Action Group
	 *
	 * @var string
	 */
	public $group = 'deal';



	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$custom_fields = $step->get_setting( 'deal-custom-fields' );
		$affects       = $step->get_setting( 'affects' );
		$pipeline      = $step->get_setting( 'pipeline' );

		if ( ! empty( $custom_fields ) && is_array( $custom_fields ) ) {
			$merge_tags_manager = \DoubleScale\Core\MergeTags\MergeTagsManager::instance();
			foreach ( $custom_fields as &$field ) {
				$value = $field['value'] ?? '';
				if ( is_string( $value ) && preg_match( '/{{.*?:.*?}}/', $value ) ) {
					$field['value'] = $merge_tags_manager->process_merge_tags( $value, $automation_contact );
				}
			}
			unset( $field );
		}

		$deals = $this->build_target_deals_query(
			array(
				'affects'  => $affects,
				'pipeline' => $pipeline,
			),
			$automation_contact
		)->get();

		foreach ( $deals as $deal ) {
			$deal->sync_custom_fields( $custom_fields );
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
			'deal-custom-fields' => array(
				'label'   => $this->t( 'Custom Field' ),
				'type'    => 'deal_custom_field_change',
				'options' => $this->get_custom_fields_options(),
			),
			'affects'            => array(
				'label'   => $this->t( 'Affects' ),
				'type'    => 'select',
				'options' => $this->get_effects_options(),
				'tooltip' => $this->t(
					'
When this automation is triggered, we will use this configuration to decide which deal(s) to update for
the given contact.'
				),
			),
			'pipeline'           => array(
				'label'   => $this->t( 'Pipeline' ),
				'type'    => 'select',
				'options' => $this->get_pipelines_options(),
			),
		);
	}

	public function get_custom_fields_options() {
		if ( ! \DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage::is_ready( 'custom-fields', CustomFieldModel::class ) ) {
			return array();
		}

		$custom_fields = CustomFieldModel::where( 'scope', 'deal' )->get();
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


	public function get_fields_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'deal-custom-fields' => array(
					'type'     => 'array',
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'custom_field_id' => array(
								'type' => 'string',
							),
							'value'           => array(
								'type' => 'string',
							),
						),
					),
					'required' => true,
				),
				'affects'            => array(
					'type'     => 'string',
					'required' => true,
				),
				'pipeline'           => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);
	}
}
