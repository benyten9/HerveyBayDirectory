<?php
/**
 * Project custom-field automation rules (group: project_fields).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Project;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Pro\Modules\Automations\Support\ProjectContactResolver;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

defined( 'ABSPATH' ) || exit;

/**
 * Fields class — one rule instance per project-scoped custom field.
 */
class Fields extends Rule {

	/**
	 * @var string
	 */
	public $name;

	/**
	 * @var string
	 */
	public $slug;

	/**
	 * @var string
	 */
	public $group = 'project_fields';

	/**
	 * @var CustomFieldModel
	 */
	public $custom_field;

	/**
	 * @var string
	 */
	public $type = 'text';

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
	 * @param CustomFieldModel $custom_field Field definition.
	 */
	public function __construct( $custom_field ) {
		$this->custom_field = $custom_field;
		$this->name         = $custom_field->name;
		$this->slug         = 'project_field_' . $custom_field->id;
		$this->type         = $this->map_field_type( $custom_field->type );
	}

	/**
	 * @param string $field_type Field type.
	 * @return string
	 */
	protected function map_field_type( $field_type ) {
		$type_map = array(
			'text'        => 'text',
			'textarea'    => 'text',
			'email'       => 'text',
			'phone'       => 'text',
			'url'         => 'text',
			'number'      => 'number',
			'date'        => 'date',
			'select'      => 'select',
			'multiselect' => 'multiselect',
			'radio'       => 'select',
			'checkbox'    => 'multiselect',
			'boolean'     => 'select',
		);

		return $type_map[ $field_type ] ?? 'text';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_operators() {
		switch ( $this->custom_field->type ) {
			case 'number':
				return array(
					'is'           => __( 'Is', 'doublescale' ),
					'is_not'       => __( 'Is not', 'doublescale' ),
					'greater_than' => __( 'Greater than', 'doublescale' ),
					'lower_than'   => __( 'Less than', 'doublescale' ),
					'is_empty'     => __( 'Is empty', 'doublescale' ),
					'is_not_empty' => __( 'Is not empty', 'doublescale' ),
				);

			case 'date':
				return array(
					'is'           => __( 'Is', 'doublescale' ),
					'is_not'       => __( 'Is not', 'doublescale' ),
					'greater_than' => __( 'Is after', 'doublescale' ),
					'lower_than'   => __( 'Is before', 'doublescale' ),
					'is_empty'     => __( 'Is empty', 'doublescale' ),
					'is_not_empty' => __( 'Is not empty', 'doublescale' ),
				);

			case 'select':
			case 'radio':
				return array(
					'is'           => __( 'Is', 'doublescale' ),
					'is_not'       => __( 'Is not', 'doublescale' ),
					'is_empty'     => __( 'Is empty', 'doublescale' ),
					'is_not_empty' => __( 'Is not empty', 'doublescale' ),
				);

			case 'multiselect':
			case 'checkbox':
				return array(
					'contains'         => __( 'Contains', 'doublescale' ),
					'does_not_contain' => __( 'Does not contain', 'doublescale' ),
					'is_empty'         => __( 'Is empty', 'doublescale' ),
					'is_not_empty'     => __( 'Is not empty', 'doublescale' ),
				);

			case 'boolean':
				return array(
					'is' => __( 'Is', 'doublescale' ),
				);

			default:
				return array(
					'is'               => __( 'Is', 'doublescale' ),
					'is_not'           => __( 'Is not', 'doublescale' ),
					'contains'         => __( 'Contains', 'doublescale' ),
					'does_not_contain' => __( 'Does not contain', 'doublescale' ),
					'starts_with'      => __( 'Starts with', 'doublescale' ),
					'ends_with'        => __( 'Ends with', 'doublescale' ),
					'is_empty'         => __( 'Is empty', 'doublescale' ),
					'is_not_empty'     => __( 'Is not empty', 'doublescale' ),
				);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function has_options() {
		return in_array( $this->custom_field->type, array( 'select', 'multiselect', 'radio', 'checkbox', 'boolean' ), true );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_options() {
		if ( 'boolean' === $this->custom_field->type ) {
			return array(
				'true'  => __( 'Checked', 'doublescale' ),
				'false' => __( 'Unchecked', 'doublescale' ),
			);
		}

		// Use CustomFieldModel's normalizer — raw `attributes` may be
		// `{options:[…], required:…}` or nested option objects; using those
		// as array keys fatals with "Illegal offset type".
		$options = array();
		foreach ( $this->custom_field->get_option_values() as $value ) {
			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}
			$key             = (string) $value;
			$options[ $key ] = $key;
		}

		return $options;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $automation_contact ) {
		$project = ProjectContactResolver::resolve_from_automation_contact( $automation_contact );
		if ( ! $project ) {
			return '';
		}

		$field = null;
		foreach ( $project->custom_fields as $custom_field ) {
			if ( (int) $custom_field->id === (int) $this->custom_field->id ) {
				$field = $custom_field;
				break;
			}
		}
		$value = $field ? ( $field->pivot->value ?? '' ) : '';

		if ( in_array( $this->custom_field->type, array( 'multiselect', 'checkbox' ), true ) && is_string( $value ) ) {
			return array_map( 'trim', explode( ',', $value ) );
		}

		return $value ?? '';
	}
}

$custom_fields = array();
if (
	AutomationModuleStorage::is_ready( 'projects', ProjectModel::class )
	&& AutomationModuleStorage::is_ready( 'custom-fields', CustomFieldModel::class )
) {
	$custom_fields = CustomFieldModel::where( 'scope', 'project' )->get();
}

if ( ! empty( $custom_fields ) ) {
	foreach ( $custom_fields as $custom_field ) {
		$rule = new Fields( $custom_field );
		ProjectRuleRegistration::register( $rule );
	}
}
