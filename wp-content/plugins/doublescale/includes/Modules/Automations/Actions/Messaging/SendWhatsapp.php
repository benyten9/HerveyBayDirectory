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
 * SendWhatsapp action stub.
 */
class SendWhatsapp extends ProAutomationStubAction {

	public $name = 'Send WhatsApp';

	public $slug = 'send_whatsapp';

	public $description = 'Send a WhatsApp message using a pre-approved Meta WhatsApp business template.';

	public $source = 'message';

	public $group = 'whatsapp';
}

SendWhatsapp::instance();
