<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Email;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * SendEmail action stub.
 */
class SendEmail extends ProAutomationStubAction {

	public $name = 'Send Email';

	public $slug = 'send_email';

	public $description = 'This action will send an email to the user with full tracking and analytics.';

	public $source = 'email';

	public $group = 'email';
}

SendEmail::instance();
