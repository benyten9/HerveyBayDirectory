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
 * InvoicePaid trigger stub.
 */
class InvoicePaid extends TriggerPro {

	public $name = 'Invoice paid';

	public $slug = 'invoice_paid';

	public $description = 'Fires when an invoice is paid in full.';

	public $attributes = array();

	public $source = 'sales';

	public $group = 'sales';
}

TriggersManager::instance()->register( new InvoicePaid() );
