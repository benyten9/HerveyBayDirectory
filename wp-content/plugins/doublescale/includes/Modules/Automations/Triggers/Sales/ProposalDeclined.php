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
 * ProposalDeclined trigger stub.
 */
class ProposalDeclined extends TriggerPro {

	public $name = 'Proposal declined';

	public $slug = 'proposal_declined';

	public $description = 'Fires when a customer declines a proposal.';

	public $attributes = array();

	public $source = 'sales';

	public $group = 'sales';
}

TriggersManager::instance()->register( new ProposalDeclined() );
