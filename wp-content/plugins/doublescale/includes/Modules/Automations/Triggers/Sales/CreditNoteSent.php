<?php
/**
 * Pro automation trigger (free plugin): definition only.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Automations\Triggers\Sales;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * CreditNoteSent trigger stub.
 */
class CreditNoteSent extends TriggerPro {

	public $name = 'Credit note sent';

	public $slug = 'credit_note_sent';

	public $description = 'Fires when a credit note is emailed to the customer.';

	public $attributes = array();

	public $source = 'sales';

	public $group = 'credit_notes';
}

TriggersManager::instance()->register( new CreditNoteSent() );
