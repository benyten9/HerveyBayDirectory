<?php
/**
 * Shared documentation for the Tags Applied automation trigger.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Triggers;

defined( 'ABSPATH' ) || exit;

/**
 * Tags Applied trigger documentation.
 */
class TagsAppliedDocs {

	/**
	 * Guide for using tags as remote automation triggers.
	 *
	 * @return array{title: string, intro: string, steps: array<int, string>, tip: string}
	 */
	public static function get() {
		return array(
			'title' => __( 'Use tags as a remote trigger', 'doublescale' ),
			'intro' => __(
				'Tags Applied is one of the most flexible triggers in DoubleScale. You can treat a dedicated tag like a push button that starts an automation from anywhere in your stack.',
				'doublescale'
			),
			'steps' => array(
				__(
					'Create a tag with a unique name, for example trigger_run_welcome_sequence.',
					'doublescale'
				),
				__(
					'Build an automation that starts with Tags Applied and select that tag.',
					'doublescale'
				),
				__(
					'Add Remove Tag as the first action and remove the same trigger tag.',
					'doublescale'
				),
				__(
					'Add the rest of your workflow (emails, deal stage changes, delays, and so on).',
					'doublescale'
				),
			),
			'tip' => __(
				'Because the tag is removed as soon as the automation starts, the contact is reset and ready to run again. To replay the flow later — manually, from a webhook, or from another automation — apply the tag again.',
				'doublescale'
			),
		);
	}
}
