<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Webhooks;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * HttpRequestWebhook action stub.
 */
class HttpRequestWebhook extends ProAutomationStubAction {

	use WebhookActions;

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'HTTP Request';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'http_request_webhook';

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
	public $group = 'http_request';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will send data to a HTTP Request webhook URL with custom key-value pairs and merge tag support.';

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
}

HttpRequestWebhook::instance();
