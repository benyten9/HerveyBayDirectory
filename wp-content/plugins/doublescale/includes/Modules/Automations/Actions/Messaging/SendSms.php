<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Messaging;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * SendSms action stub.
 */
class SendSms extends ProAutomationStubAction {

	public $name = 'Send Sms';

	public $slug = 'send_sms';

	public $description = 'This action will send an Sms to the user with full tracking and analytics.';

	public $source = 'message';

	public $group = 'sms';
}

SendSms::instance();
