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
 * ProposalConvertedToInvoice trigger stub.
 */
class ProposalConvertedToInvoice extends TriggerPro {

	public $name = 'Proposal converted to invoice';

	public $slug = 'proposal_converted_to_invoice';

	public $description = 'Fires when a proposal is converted to a draft invoice.';

	public $attributes = array();

	public $source = 'sales';

	public $group = 'sales';
}

TriggersManager::instance()->register( new ProposalConvertedToInvoice() );
