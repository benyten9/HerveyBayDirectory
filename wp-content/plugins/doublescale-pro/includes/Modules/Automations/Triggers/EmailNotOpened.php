<?php
/**
 * Email Not Opened Trigger
 *
 * Fires when a subscribed contact was sent email but has not opened any
 * for a configured number of days (sunset / domain-reputation flows).
 *
 * @since 1.1.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Automations\Sweepers\EmailNotOpenedSweeper;

/**
 * EmailNotOpened class
 */
class EmailNotOpened extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Email Not Opened';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'email_not_opened';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'Fires when a contact has not opened any email for a set number of days. Use this to pause sending and protect domain reputation.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'messaging';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'messaging';

	/**
	 * Load Hooks
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'doublescale_automation_email_not_opened', array( $this, 'handle' ), 10, 4 );
		EmailNotOpenedSweeper::instance();
	}

	/**
	 * Enroll a contact that matched the unopened-email window.
	 *
	 * @param mixed  $contact        Contact model.
	 * @param int    $days           Window that matched.
	 * @param string $last_sent_at   Latest outbound sent_at.
	 * @param string $last_opened_at Latest opened_at, or empty.
	 *
	 * @return void
	 */
	public function handle( $contact, $days = 30, $last_sent_at = '', $last_opened_at = '' ) {
		if ( ! $contact instanceof ContactModel ) {
			return;
		}

		$this->process(
			array(
				'contact' => $contact,
				'data'    => array(
					'days'           => (int) $days,
					'last_sent_at'   => (string) $last_sent_at,
					'last_opened_at' => (string) $last_opened_at,
				),
			)
		);
	}

	/**
	 * Only automations whose days setting matches this sweep window.
	 *
	 * @since 1.1.0
	 *
	 * @param AutomationModel $automation Automation Model.
	 * @param array           $args       Arguments.
	 *
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		if ( empty( $args['contact'] ) || ! $args['contact'] instanceof ContactModel ) {
			return false;
		}

		$configured = EmailNotOpenedSweeper::normalize_days( $automation->get_setting( 'days', EmailNotOpenedSweeper::DEFAULT_DAYS ) );
		$actual     = (int) ( $args['data']['days'] ?? 0 );

		return $configured > 0 && $configured === $actual;
	}

	/**
	 * Get fields for trigger configuration
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'days' => array(
				'label'         => __( 'Days without an open', 'doublescale' ),
				'type'          => 'number',
				'placeholder'   => '30',
				'default-value' => EmailNotOpenedSweeper::DEFAULT_DAYS,
				'helperText'    => __( 'Fires once a contact was emailed at least this many days ago and has not opened any email in that window. Contacts who have never been sent an email are skipped.', 'doublescale' ),
			),
		);
	}

	/**
	 * In-product documentation for the trigger sidebar.
	 *
	 * @return array{title: string, intro: string, steps: array<int, string>, tip: string}
	 */
	public function get_documentation() {
		return array(
			'title' => __( 'Pause email to unengaged contacts', 'doublescale' ),
			'intro' => __(
				'This trigger finds contacts who received at least one email and have not opened any email in the last X days. Typical use: unsubscribe them or move them off regular campaigns so your sending domain stays healthy.',
				'doublescale'
			),
			'steps' => array(
				__( 'Set how many days without an open should qualify (for example 30 or 90).', 'doublescale' ),
				__( 'Add an action such as Unsubscribe from Email, apply a sunset tag, or move the contact to a low-frequency list.', 'doublescale' ),
				__( 'Contacts who have never been sent an email are ignored. A new send resets the timer until that email is also older than X days without an open.', 'doublescale' ),
			),
			'tip'   => __(
				'The trigger fires once per quiet stretch. If a contact opens later, then goes quiet again, turn on Run Multiple Times so they can enter the workflow again.',
				'doublescale'
			),
		);
	}
}
