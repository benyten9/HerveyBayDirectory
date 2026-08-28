<?php
/**
 * Shared base for project automation rules (conditions).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Project;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Pro\Modules\Automations\Support\ProjectContactResolver;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

defined( 'ABSPATH' ) || exit;

/**
 * BaseProjectRule
 */
abstract class BaseProjectRule extends Rule {

	/**
	 * @var string
	 */
	public $group = 'project';

	/**
	 * @var bool
	 */
	public $is_automation = true;

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
	 * Resolve the project for the current enrollment.
	 *
	 * @param AutomationContactModel|object $automation_contact Contact.
	 * @return ProjectModel|null
	 */
	protected function resolve_project( $automation_contact ): ?ProjectModel {
		return ProjectContactResolver::resolve_from_automation_contact( $automation_contact );
	}

	/**
	 * Default operator switch.
	 *
	 * @param AutomationContactModel $automation_contact Contact.
	 * @param array                  $rule               Rule config.
	 * @return bool
	 */
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value      = $this->get_value( $automation_contact );
		$operator   = $rule['operator'] ?? 'is';
		$rule_value = $rule['value'] ?? '';

		switch ( $operator ) {
			case 'is':
				return (string) $value === (string) $rule_value;
			case 'is_not':
				return (string) $value !== (string) $rule_value;
			case 'contains':
				return false !== stripos( (string) $value, (string) $rule_value );
			case 'does_not_contain':
				return false === stripos( (string) $value, (string) $rule_value );
			case 'is_empty':
				return '' === (string) $value || null === $value;
			case 'is_not_empty':
				return '' !== (string) $value && null !== $value;
			case 'greater_than':
				return $value > $rule_value;
			case 'lower_than':
				return $value < $rule_value;
			default:
				return false;
		}
	}

	/**
	 * Standard is/is_not operators.
	 *
	 * @return array
	 */
	protected function is_is_not_operators(): array {
		return array(
			'is'     => __( 'Is', 'doublescale' ),
			'is_not' => __( 'Is not', 'doublescale' ),
		);
	}
}