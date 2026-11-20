<?php
/**
 * Email Opened Trigger
 *
 * Fires the first time a contact opens an email — the opposite of
 * {@see EmailNotOpened}. Optionally limited to the emails of chosen campaigns.
 *
 * @since 1.2.32
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

/**
 * EmailOpened class
 */
class EmailOpened extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Email Opened';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'email_opened';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'Fires the first time a contact opens an email. Use this to follow up while your message is fresh.';

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
	 * @since 1.2.32
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'doublescale_mail_open', array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * Enroll a contact that opened an email.
	 *
	 * @param mixed  $contact  Contact model.
	 * @param mixed  $tracking The opened email's tracking row.
	 * @param string $via      How the open was detected: pixel, click or reply.
	 *
	 * @return void
	 */
	public function handle( $contact, $tracking = null, $via = '' ) {
		if ( ! $contact instanceof ContactModel ) {
			return;
		}

		$open = array( 'via' => (string) $via );

		if ( $tracking instanceof CommunicationTrackingModel ) {
			$open['tracking_id'] = (int) $tracking->id;
			$open['source_type'] = (int) $tracking->source_type;
			$open['source_id']   = (int) $tracking->source_id;
		}

		$this->process(
			array(
				'contact' => $contact,
				'data'    => $open,
			)
		);
	}

	/**
	 * Only opens of the selected campaigns, and never the workflow's own emails.
	 *
	 * @since 1.2.32
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

		$open = isset( $args['data'] ) && is_array( $args['data'] ) ? $args['data'] : array();

		// With Run Multiple Times on, an email this workflow sent would start it
		// again on every open — and mail apps that preload images open them all.
		if ( self::sent_by_automation( $open, $automation->id ) ) {
			return false;
		}

		return self::matches_campaigns( $automation->get_setting( 'campaign_ids', array() ), $open );
	}

	/**
	 * Whether an open belongs to the automation's optional campaign selection.
	 *
	 * An empty selection means every email. A selection matches campaign emails
	 * only: `source_id` is polymorphic, so automation #7 is not campaign #7.
	 *
	 * @param mixed $selected Setting value from `campaign_ids` (ids, or empty).
	 * @param array $open     Open snapshot built by {@see handle()}.
	 * @return bool
	 */
	public static function matches_campaigns( $selected, array $open ) {
		if ( ! is_array( $selected ) ) {
			$selected = ( null === $selected || '' === $selected ) ? array() : array( $selected );
		}

		$campaign_ids = array_values(
			array_filter(
				array_map( 'intval', $selected ),
				static function ( $id ) {
					return $id > 0;
				}
			)
		);
		if ( empty( $campaign_ids ) ) {
			return true;
		}

		if ( MessageSourceTypes::CAMPAIGN !== (int) ( $open['source_type'] ?? 0 ) ) {
			return false;
		}

		return in_array( (int) ( $open['source_id'] ?? 0 ), $campaign_ids, true );
	}

	/**
	 * Whether the opened email was sent by this automation.
	 *
	 * @param array $open          Open snapshot built by {@see handle()}.
	 * @param mixed $automation_id Automation id.
	 * @return bool
	 */
	public static function sent_by_automation( array $open, $automation_id ) {
		$automation_id = (int) $automation_id;

		return $automation_id > 0
			&& MessageSourceTypes::AUTOMATION === (int) ( $open['source_type'] ?? 0 )
			&& (int) ( $open['source_id'] ?? 0 ) === $automation_id;
	}

	/**
	 * Get fields for trigger configuration
	 *
	 * @since 1.2.32
	 *
	 * @return array
	 */
	public function get_fields() {
		// The picker lists campaigns over REST, and that route goes with the module.
		if ( function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'campaigns' ) ) {
			return array();
		}

		return array(
			'campaign_ids' => array(
				'label'       => __( 'Campaigns', 'doublescale' ),
				'type'        => 'infinite_scroll_multiselect',
				'endpoint'    => '/doublescale/v1/campaigns',
				'placeholder' => __( 'Search campaigns…', 'doublescale' ),
				'helperText'  => __( 'Leave empty to run for every email a contact opens, including automation and one-to-one emails. Select campaigns to run only when one of their emails is opened.', 'doublescale' ),
				'settings'    => array(
					'searchParamName' => 'keywords',
					'perPage'         => 20,
					'apiParams'       => array(
						'channel' => 'email',
					),
				),
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @since 1.2.32
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'campaign_ids' => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'integer',
					),
					'required' => false,
				),
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
			'title' => __( 'Follow up when a contact opens an email', 'doublescale' ),
			'intro' => __(
				'This trigger starts the workflow the first time a contact opens an email you sent. Typical use: tag engaged contacts, alert the contact owner, or send the next message while yours is fresh.',
				'doublescale'
			),
			'steps' => array(
				__( 'Leave Campaigns empty to run for every email, or pick the campaigns that should start this workflow.', 'doublescale' ),
				__( 'Add an action such as Add Tags, Create Task, or a follow-up email.', 'doublescale' ),
				__( 'An email counts as opened when its images load, or when the contact clicks a link in it or replies to it.', 'doublescale' ),
			),
			'tip'   => __(
				'Each email starts the workflow once, on its first open. To let a contact enter again when they open another email, turn on Run Multiple Times. Emails sent by this workflow never start it again.',
				'doublescale'
			),
		);
	}
}
