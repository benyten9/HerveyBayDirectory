<?php
/**
 * Rule: project due date.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Project;

defined( 'ABSPATH' ) || exit;

class ProjectDueDate extends BaseProjectRule {

	public $name = 'Project Due Date';
	public $slug = 'project_due_date';
	public $type = 'date';

	public function get_operators() {
		return array(
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'greater_than' => __( 'Is after', 'doublescale' ),
			'lower_than'   => __( 'Is before', 'doublescale' ),
			'is_empty'     => __( 'Is empty', 'doublescale' ),
			'is_not_empty' => __( 'Is not empty', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$project = $this->resolve_project( $automation_contact );
		return $project ? ( $project->due_date ?? '' ) : '';
	}

	public function is_met( \DoubleScale\Modules\Automations\Models\AutomationContactModel $automation_contact, $rule = array() ) {
		$value      = $this->get_value( $automation_contact );
		$operator   = $rule['operator'] ?? 'is';
		$rule_value = $rule['value'] ?? '';

		if ( in_array( $operator, array( 'is_empty', 'is_not_empty' ), true ) ) {
			return parent::is_met( $automation_contact, $rule );
		}

		if ( '' === (string) $value || '' === (string) $rule_value ) {
			return false;
		}

		$project_ts = strtotime( (string) $value );
		$rule_ts    = strtotime( (string) $rule_value );
		if ( ! $project_ts || ! $rule_ts ) {
			return false;
		}

		$project_day = gmdate( 'Y-m-d', $project_ts );
		$rule_day    = gmdate( 'Y-m-d', $rule_ts );

		switch ( $operator ) {
			case 'is':
				return $project_day === $rule_day;
			case 'is_not':
				return $project_day !== $rule_day;
			case 'greater_than':
				return $project_day > $rule_day;
			case 'lower_than':
				return $project_day < $rule_day;
			default:
				return false;
		}
	}
}

ProjectRuleRegistration::register( new ProjectDueDate() );