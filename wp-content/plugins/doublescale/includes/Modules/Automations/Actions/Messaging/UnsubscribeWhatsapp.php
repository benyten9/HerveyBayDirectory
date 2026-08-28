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
 * UnsubscribeWhatsapp action stub.
 */
class UnsubscribeWhatsapp extends ProAutomationStubAction {

	public $name = 'Unsubscribe WhatsApp';

	public $slug = 'unsubscribe_whatsapp';

	public $description = 'Unsubscribe the contact from WhatsApp. Optionally match an incoming message keyword before unsubscribing.';

	public $source = 'message';

	public $group = 'whatsapp';
}

UnsubscribeWhatsapp::instance();
