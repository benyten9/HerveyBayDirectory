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
 * UpdateTitleDeal action stub.
 */
class UpdateTitleDeal extends ProAutomationStubAction {

	public $name = 'Update a deal title';

	public $slug = 'update_title_deal';

	public $description = 'This action will update the title of a deal.';

	public $source = 'sales';

	public $group = 'deal';
}

UpdateTitleDeal::instance();
