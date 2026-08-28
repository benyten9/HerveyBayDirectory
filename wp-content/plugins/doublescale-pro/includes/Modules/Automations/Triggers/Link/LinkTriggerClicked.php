<?php
/**
 * Link Trigger Clicked
 *
 * Fires when a configured link trigger URL is clicked.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Link;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\LinkTriggers\Models\LinkTriggerModel;

/**
 * LinkTriggerClicked trigger.
 */
class LinkTriggerClicked extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Link Trigger Clicked';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'link_trigger_clicked';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger fires when a link trigger URL is clicked.';

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
	public $source = 'link_triggers';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'link_triggers';

	/**
	 * Load Hooks
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'doublescale_link_trigger_clicked', array( $this, 'link_trigger_clicked' ), 10, 2 );
	}

	/**
	 * Handle a link-trigger click.
	 *
	 * @param LinkTriggerModel $link_trigger Clicked link trigger.
	 * @param ContactModel     $contact      Resolved contact.
	 * @return void
	 */
	public function link_trigger_clicked( $link_trigger, $contact ) {
		if ( ! $link_trigger instanceof LinkTriggerModel || ! $contact instanceof ContactModel ) {
			return;
		}

		$data = array(
			'contact' => $contact,
			'data'    => array(
				'link_trigger_id' => (int) $link_trigger->id,
			),
		);

		$this->process( $data );
	}

	/**
	 * Filter by selected link triggers when configured.
	 *
	 * An empty selection means every link trigger — same pattern as membership /
	 * product filters. Requiring a selection here is what made published
	 * automations silently never start after a click.
	 *
	 * @param AutomationModel $automation Automation model.
	 * @param array           $args       Trigger args.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$clicked_id = isset( $args['data']['link_trigger_id'] ) ? (int) $args['data']['link_trigger_id'] : 0;
		if ( $clicked_id <= 0 ) {
			return false;
		}

		return self::selected_links_match( $automation->get_setting( 'links', array() ), $clicked_id );
	}

	/**
	 * Whether a clicked link trigger id matches the automation's optional filter.
	 *
	 * @param mixed $selected   Setting value from `links` (ids, or empty).
	 * @param int   $clicked_id Clicked link trigger id.
	 * @return bool
	 */
	public static function selected_links_match( $selected, $clicked_id ) {
		$clicked_id = (int) $clicked_id;
		if ( $clicked_id <= 0 ) {
			return false;
		}

		if ( ! is_array( $selected ) ) {
			$selected = ( null === $selected || '' === $selected ) ? array() : array( $selected );
		}

		$selected_ids = array_values(
			array_filter(
				array_map( 'intval', $selected ),
				static function ( $id ) {
					return $id > 0;
				}
			)
		);
		if ( empty( $selected_ids ) ) {
			return true;
		}

		return in_array( $clicked_id, $selected_ids, true );
	}

	/**
	 * Trigger configuration fields.
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'links' => array(
				'type'        => 'api_select',
				'label'       => __( 'Link Triggers', 'doublescale' ),
				'endpoint'    => 'doublescale/v1/link-triggers',
				'placeholder' => __( 'Select link trigger(s)', 'doublescale' ),
				'multiple'    => true,
				'required'    => false,
				'helperText'  => __(
					'Leave empty to run for every link trigger. Select specific links to limit this automation to those clicks.',
					'doublescale'
				),
			),
		);
	}

	/**
	 * Attributes schema.
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'links' => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'integer',
					),
					'required' => false,
				),
			),
		);
	}
}
