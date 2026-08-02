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
 * InvoiceSent trigger stub.
 */
class InvoiceSent extends TriggerPro {

	public $name = 'Invoice sent';

	public $slug = 'invoice_sent';

	public $description = 'Fires when an invoice is emailed to the customer.';

	public $attributes = array();

	public $source = 'sales';

	public $group = 'sales';
}

TriggersManager::instance()->register( new InvoiceSent() );
