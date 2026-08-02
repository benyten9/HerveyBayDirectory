<?php

/**
 * Zapier Webhook Action
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Webhooks;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Actions\Webhooks\WebhookActions;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Zapier Webhook Action
 */
class ZapierWebhook extends Action
{
	use WebhookActions;
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Send Data to Zapier';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'zapier_webhook';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'send_data';

	/**
	 * Trigger Group
	 *
	 * @var string
	 */
	public $group = 'zapier';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will send data to a Zapier webhook URL with custom key-value pairs and merge tag support.';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return bool
	 */
	public function process_action(AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact)
	{
		$webhook_url = $step->get_setting('webhook_url', '');
		$data_fields = $step->get_setting('data_fields', array());

		// Decode data fields if needed
		$data_fields = $this->decode_data_fields($data_fields);

		// Validate webhook URL
		if (! $this->validate_webhook_url($webhook_url, $automation, $step, 'Zapier Webhook')) {
			return false;
		}

		// Process custom data or use default contact data
		$processed_data = $this->process_data_fields($data_fields, $automation_contact);

		// Send data to webhook
		$response = \wp_remote_post(
			$webhook_url,
			array(
				'method'      => 'POST',
				'timeout'     => 30,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'DoubleScale/' . DOUBLESCALE_VERSION,
				),
				'body'        => \wp_json_encode($processed_data),
				'cookies'     => array(),
			)
		);

		if (\is_wp_error($response)) {
			$this->log_webhook_error(
				\__('Zapier Webhook action failed to send data.', 'doublescale'),
				'zapier_webhook_request_failed',
				$automation,
				$step,
				$webhook_url,
				array('error' => $response->get_error_message())
			);
			return false;
		}

		$response_code = \wp_remote_retrieve_response_code($response);
		$response_body = \wp_remote_retrieve_body($response);

		if ($response_code < 200 || $response_code >= 300) {
			$this->log_webhook_error(
				\__('Zapier Webhook action received error response.', 'doublescale'),
				'zapier_webhook_error_response',
				$automation,
				$step,
				$webhook_url,
				array(
					'response_code' => $response_code,
					'response_body' => $response_body,
				)
			);
			return false;
		}

		$this->log_webhook_success(
			\__('Zapier Webhook action successfully sent data.', 'doublescale'),
			'zapier_webhook_success',
			$automation,
			$step,
			$webhook_url,
			array(
				'response_code' => $response_code,
				'sent_data'     => $processed_data,
			)
		);

		return true;
	}


	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields()
	{
		return array(
			'webhook_url'  => array(
				'label'       => \__('Webhook URL', 'doublescale'),
				'type'        => 'text',
				'placeholder' => 'https://hooks.zapier.com/hooks/catch/...',
				'description' => \__('Enter your Zapier webhook URL', 'doublescale'),
			),
			'data_fields'  => array(
				'type'        => 'dynamic_keyvalue',
				'label'       => \__('Data', 'doublescale'),
				'description' => \__('Add custom key-value pairs to send to the webhook. Keys will be used as field names in the payload.', 'doublescale'),
			),
			'test_webhook' => array(
				'type'        => 'button',
				'label'       => \__('Test Webhook', 'doublescale'),
				'description' => \__('Test the webhook by sending a test payload.', 'doublescale'),
				'settings'    => array(
					'ajax_action' => 'doublescale_test_zapier_webhook',
					'button_text' => \__('Send Test Data', 'doublescale'),
				),
			),
		);
	}


	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema()
	{
		return array(
			'type'       => 'object',
			'properties' => array(
				'webhook_url' => array(
					'type'     => 'string',
					'required' => true,
				),
				'data_fields' => array(
					'type'        => 'array',
					'description' => 'Array of key-value pairs for webhook data',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'    => array('type' => 'string'),
							'key'   => array('type' => 'string'),
							'value' => array('type' => 'string'),
						),
						'required'   => array('id', 'key'),
					),
					'default'     => array(),
				),

			),
		);
	}

	/**
	 * Register hooks
	 *
	 * @since 1.0.0
	 */
	public function register()
	{
		parent::register();

		// Register AJAX handler for test webhook functionality
		\add_action('wp_ajax_doublescale_test_zapier_webhook', array($this, 'ajax_test_webhook'));
	}

	/**
	 * AJAX handler for testing webhook
	 *
	 * @since 1.0.0
	 */
	public function ajax_test_webhook()
	{
		// Check nonce for security
		\check_ajax_referer('doublescale-admin', 'nonce');

		// Call the test webhook method
		$result = $this->test_webhook();

		// Send JSON response
		if ($result['success']) {
			\wp_send_json_success($result['data']);
		} else {
			\wp_send_json_error($result['data']);
		}
	}

	/**
	 * Test webhook functionality
	 *
	 * @return array
	 */
	public function test_webhook()
	{
		// Get webhook URL from POST data
		$webhook_url = isset($_POST['webhook_url']) ? \sanitize_url($_POST['webhook_url']) : '';
		$data_fields = isset($_POST['data_fields']) ? $_POST['data_fields'] : array();

		// Decode data fields if needed
		$data_fields = $this->decode_data_fields($data_fields);

		if (empty($webhook_url)) {
			return array(
				'success' => false,
				'data'    => array(
					'message' => \__('Webhook URL is required for testing.', 'doublescale'),
				),
			);
		}

		// Validate webhook URL
		if (! \filter_var($webhook_url, \FILTER_VALIDATE_URL)) {
			return array(
				'success' => false,
				'data'    => array(
					'message' => \__('Invalid webhook URL provided.', 'doublescale'),
				),
			);
		}

		// Prepare test data
		$test_data = $this->prepare_test_data($data_fields);

		// Send test data to webhook
		$response = \wp_remote_post(
			$webhook_url,
			array(
				'method'      => 'POST',
				'timeout'     => 30,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'DoubleScale/' . DOUBLESCALE_VERSION . ' (Test)',
				),
				'body'        => \wp_json_encode($test_data),
				'cookies'     => array(),
			)
		);

		return $this->validate_webhook_response($response, 'Zapier Webhook');
	}
}
