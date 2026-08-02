<?php

/**
 * WooCommerce Order Note Added Trigger
 * This trigger will be fired when a note is added to an order.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Order;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use WC_Order;

/**
 * Order Note Added Trigger
 */
class OrderNoteAdded extends Trigger
{
	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Order Note Added';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_order_note_added';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a note is added to an order.';

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
	public $source = 'woocommerce';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		add_action('woocommerce_order_note_added', array($this, 'order_note_added'), 10, 2);
	}

	/**
	 * Order Note Added
	 *
	 * @since 1.0.0
	 *
	 * @param int    $note_id Note ID.
	 * @param object $note Note data object.
	 *
	 * @return void
	 */
	public function order_note_added($note_id, $order)
	{
		$note = get_comment($note_id);

		if (! $note || ! $order instanceof \WC_Order) {
			return;
		}

		$note_type    = $note->comment_type ?? '';
		$note_content = $note->comment_content ?? '';
		$note_author  = $note->comment_author ?? '';

		$data = array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'email'      => $order->get_billing_email(),
			'data'       => array(
				'order_id'     => $order->get_id(),
				'note_id'      => $note_id,
				'note_content' => $note_content,
				'note_type'    => $note_type,
				'note_author'  => $note_author,
			),
		);

		$this->process($data);
	}



	/**
	 * Is Processable
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation
	 * @param array            $args
	 *
	 * @return bool
	 */
	public function is_processable(AutomationModel $automation, $args)
	{
		$note_type     = $args['data']['note_type'] ?? '';
		$note_content  = $args['data']['note_content'] ?? '';
		$selected_type = $automation->get_setting('note_type', 'both');

		// Check note types if specified
		if ($selected_type !== 'both' && $selected_type !== $note_type) {
			return false;
		}

		// Check note text matching if specified
		$note_text_matches = $automation->get_setting('note_text_matches', '');
		if (! empty($note_text_matches)) {
			// Convert to lowercase for case-insensitive matching
			$note_content_lower = strtolower($note_content);
			$search_text_lower  = strtolower($note_text_matches);

			// Check if the note content contains the search text
			if (strpos($note_content_lower, $search_text_lower) === false) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields()
	{
		return array(
			'note_type'         => array(
				'type'        => 'select',
				'label'       => __('Note Type', 'doublescale'),
				'description' => __('Select which note type to trigger for.', 'doublescale'),
				'options'     => array(
					'both'          => __('Both', 'doublescale'),
					'customer_note' => __('Customer', 'doublescale'),
					'order_note'    => __('Private', 'doublescale'),
				),
				'default'     => 'both',
			),
			'note_text_matches' => array(
				'type'        => 'text',
				'label'       => __('Note Text Matches', 'doublescale'),
				'description' => __('Enter the text to match in the note (optional).', 'doublescale'),
				'placeholder' => __('Enter text to search for in notes...', 'doublescale'),
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_attributes_schema()
	{
		return array(
			'type'       => 'object',
			'properties' => array(
				'note_type'         => array(
					'type' => 'string',
				),
				'note_text_matches' => array(
					'type' => 'string',
				),
			),
		);
	}
}
