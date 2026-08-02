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
 * UpdateValueDeal action stub.
 */
class UpdateValueDeal extends ProAutomationStubAction {

	public $name = 'Update a deal value';

	public $slug = 'update_value_deal';

	public $description = 'This action will update the value of a deal.';

	public $source = 'sales';

	public $group = 'deal';
}

UpdateValueDeal::instance();
