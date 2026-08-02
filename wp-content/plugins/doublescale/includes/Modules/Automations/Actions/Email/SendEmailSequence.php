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
 * SendEmailSequence action stub.
 */
class SendEmailSequence extends ProAutomationStubAction {

	public $name = 'Send Email Sequence';

	public $slug = 'send_email_sequence';

	public $description = 'This action will send a email sequence to the contact.';

	public $source = 'email';

	public $group = 'email';
}

SendEmailSequence::instance();
