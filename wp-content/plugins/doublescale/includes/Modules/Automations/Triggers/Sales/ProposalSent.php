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
 * ProposalSent trigger stub.
 */
class ProposalSent extends TriggerPro {

	public $name = 'Proposal sent';

	public $slug = 'proposal_sent';

	public $description = 'Fires when a proposal is emailed to the customer.';

	public $attributes = array();

	public $source = 'sales';

	public $group = 'sales';
}

TriggersManager::instance()->register( new ProposalSent() );
