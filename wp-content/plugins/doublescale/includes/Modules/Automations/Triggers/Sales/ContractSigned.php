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
 * ContractSigned trigger stub.
 */
class ContractSigned extends TriggerPro {

	public $name = 'Contract signed';

	public $slug = 'contract_signed';

	public $description = 'Fires when a customer signs a contract.';

	public $attributes = array();

	public $source = 'sales';

	public $group = 'contracts';
}

TriggersManager::instance()->register( new ContractSigned() );
