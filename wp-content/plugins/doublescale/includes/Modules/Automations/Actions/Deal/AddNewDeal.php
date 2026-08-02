<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Deal;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * AddNewDeal action stub.
 */
class AddNewDeal extends ProAutomationStubAction {

	public $name = 'Add a deal';

	public $slug = 'add_new_deal';

	public $description = 'This action will add a new deal.';

	public $source = 'sales';

	public $group = 'deal';
}

AddNewDeal::instance();
