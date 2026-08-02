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
 * ContractSent trigger stub.
 */
class ContractSent extends TriggerPro {

	public $name = 'Contract sent';

	public $slug = 'contract_sent';

	public $description = 'Fires when a contract is emailed to the customer.';

	public $attributes = array();

	public $source = 'sales';

	public $group = 'contracts';
}

TriggersManager::instance()->register( new ContractSent() );
