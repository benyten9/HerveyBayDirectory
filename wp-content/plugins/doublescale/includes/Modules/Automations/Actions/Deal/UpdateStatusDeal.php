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
 * UpdateStatusDeal action stub.
 */
class UpdateStatusDeal extends ProAutomationStubAction {

	public $name = 'Update a deal status';

	public $slug = 'update_status_deal';

	public $description = 'This action will update the status of a deal.';

	public $source = 'sales';

	public $group = 'deal';
}

UpdateStatusDeal::instance();
