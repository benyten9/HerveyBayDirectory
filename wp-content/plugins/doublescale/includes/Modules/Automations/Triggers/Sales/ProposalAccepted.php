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
 * ProposalAccepted trigger stub.
 */
class ProposalAccepted extends TriggerPro {

	public $name = 'Proposal accepted';

	public $slug = 'proposal_accepted';

	public $description = 'Fires when a customer accepts a proposal.';

	public $attributes = array();

	public $source = 'sales';

	public $group = 'sales';
}

TriggersManager::instance()->register( new ProposalAccepted() );
