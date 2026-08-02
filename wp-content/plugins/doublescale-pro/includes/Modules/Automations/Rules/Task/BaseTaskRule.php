<?php
/**
 * Shared base for task automation rules (conditions).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Task;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Pro\Modules\Automations\Support\TaskContactResolver;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

/**
 * BaseTaskRule
 */
abstract class BaseTaskRule extends Rule {

	/**
	 * @var string
	 */
	public $group = 'task';

	/**
	 * @var bool
	 */
	public $is_automation = true;

	/**
	 * @var array
	 */
	public $required_triggers = array(
		'task_created',
		'task_completed',
		'task_assigned',
		'task_status_changed',
		'task_overdue',
		'task_due_soon',
		'subtask_created',
		'subtask_completed',
	);

	/**
	 * Resolve the task for the current enrollment.
	 *
	 * @param AutomationContactModel|object $automation_contact Contact.
	 * @return TaskModel|null
	 */
	protected function resolve_task( $automation_contact ): ?TaskModel {
		return TaskContactResolver::resolve_from_automation_contact( $automation_contact );
	}

	/**
	 * Default is/is_not comparison.
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
