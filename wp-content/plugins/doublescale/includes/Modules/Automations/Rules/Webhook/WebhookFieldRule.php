<?php
/**
 * Condition on a value of the webhook that started the workflow.
 *
 * One generic rule answers every field: its slug is the prefix
 * `webhook_field_`, and a saved condition carries the field's path after it
 * (`webhook_field_data.lead.qualified`, the path URL-encoded). The editor
 * lists the paths from the workflow's last received payload.
 *
 * @package DoubleScale\Modules\Automations\Rules\Webhook
 */

namespace DoubleScale\Modules\Automations\Rules\Webhook;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Modules\Automations\Services\WebhookPayload;

/**
 * Webhook field rule.
 */
class WebhookFieldRule extends Rule {

	/** Slug prefix; the field path follows it. */
	const PREFIX = 'webhook_field_';

	/** @var string */
	public $name = 'Webhook field';

	/** @var string */
	public $slug = self::PREFIX;

	/** @var string */
	public $group = 'webhook';

	/** @var string */
	public $type = 'text';

	/** @var array */
	public $required_triggers = array( 'webhook_received' );

	/**
	 * Operators offered for a webhook value.
	 */
	public function get_operators() {
		return array(
			'is'               => __( 'Is', 'doublescale' ),
			'is_not'           => __( 'Is not', 'doublescale' ),
			'contains'         => __( 'Contains', 'doublescale' ),
			'does_not_contain' => __( 'Does not contain', 'doublescale' ),
			'starts_with'      => __( 'Starts with', 'doublescale' ),
			'ends_with'        => __( 'Ends with', 'doublescale' ),
			'greater_than'     => __( 'Greater than', 'doublescale' ),
			'lower_than'       => __( 'Less than', 'doublescale' ),
			'is_empty'         => __( 'Is empty', 'doublescale' ),
			'is_not_empty'     => __( 'Is not empty', 'doublescale' ),
		);
	}

	/**
	 * Unused: the value depends on the path in the saved condition.
	 *
	 * @param AutomationContactModel $automation_contact Run.
	 */
	public function get_value( $automation_contact ) {
		return null;
	}

	/**
	 * The field path a saved condition points at.
	 *
	 * @param array $rule Saved condition.
	 */
	public static function path_from_rule( array $rule ): string {
		$slug = (string) ( $rule['rule'] ?? '' );
		if ( 0 !== strpos( $slug, self::PREFIX ) ) {
			return '';
		}
		return rawurldecode( substr( $slug, strlen( self::PREFIX ) ) );
	}

	/**
	 * @param AutomationContactModel $automation_contact Run.
	 * @param array                  $rule               Saved condition.
	 */
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$path = self::path_from_rule( (array) $rule );
		if ( '' === $path ) {
			return false;
		}

		$value      = strtolower( trim( WebhookPayload::to_text( WebhookPayload::get( WebhookPayload::for_run( $automation_contact ), $path ) ) ) );
		$rule_value = strtolower( trim( (string) ( $rule['value'] ?? '' ) ) );
		$operator   = (string) ( $rule['operator'] ?? 'is' );

		switch ( $operator ) {
			case 'is':
				return $value === $rule_value;
			case 'is_not':
				return $value !== $rule_value;
			case 'contains':
				return '' !== $rule_value && false !== strpos( $value, $rule_value );
			case 'does_not_contain':
			case 'not_contains':
				return '' === $rule_value || false === strpos( $value, $rule_value );
			case 'starts_with':
				return '' !== $rule_value && 0 === strpos( $value, $rule_value );
			case 'ends_with':
				return '' !== $rule_value && substr( $value, -strlen( $rule_value ) ) === $rule_value;
			case 'greater_than':
				return is_numeric( $value ) && is_numeric( $rule_value ) && (float) $value > (float) $rule_value;
			case 'lower_than':
				return is_numeric( $value ) && is_numeric( $rule_value ) && (float) $value < (float) $rule_value;
			case 'is_empty':
				return '' === $value;
			case 'is_not_empty':
				return '' !== $value;
		}
		return false;
	}
}

RulesManager::instance()->register( new WebhookFieldRule() );
