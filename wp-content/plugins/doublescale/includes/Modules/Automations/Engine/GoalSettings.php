<?php
/**
 * What a goal step does with a contact who reaches it without having achieved it.
 *
 * - wait     (default): hold the contact on the goal until it is achieved.
 * - continue : let the contact carry on to the next step.
 * - end      : end the automation for this contact.
 *
 * Older workflows stored a boolean `skip`; it reads as `continue`.
 *
 * @package DoubleScale\Modules\Automations\Engine
 */

namespace DoubleScale\Modules\Automations\Engine;

defined( 'ABSPATH' ) || exit;

final class GoalSettings {

	public const KEY      = 'when_not_met';
	public const WAIT     = 'wait';
	public const CONTINUE = 'continue';
	public const END      = 'end';

	/**
	 * @param mixed $settings Goal step settings.
	 * @return string One of the mode constants.
	 */
	public static function mode( $settings ): string {
		$settings = is_array( $settings ) ? $settings : array();
		$mode     = isset( $settings[ self::KEY ] ) ? strtolower( (string) $settings[ self::KEY ] ) : '';

		if ( in_array( $mode, array( self::WAIT, self::CONTINUE, self::END ), true ) ) {
			return $mode;
		}
		if ( ! empty( $settings['skip'] ) && 'false' !== $settings['skip'] ) {
			return self::CONTINUE;
		}

		return self::WAIT;
	}

	/**
	 * The goal-step field every goal shows, after its own fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			self::KEY => array(
				'label'         => __( 'If a contact reaches this goal without achieving it', 'doublescale' ),
				'type'          => 'radio',
				'options'       => array(
					self::WAIT     => __( 'Wait until the goal is achieved', 'doublescale' ),
					self::CONTINUE => __( 'Continue to the next step anyway', 'doublescale' ),
					self::END      => __( 'End the automation for this contact', 'doublescale' ),
				),
				'default-value' => self::WAIT,
				'helperText'    => __( 'Contacts who achieve this goal earlier in the workflow, during a delay for example, skip the steps in between and continue from here.', 'doublescale' ),
			),
		);
	}
}
