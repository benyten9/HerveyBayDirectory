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
 * UpdateStageDeal action stub.
 */
class UpdateStageDeal extends ProAutomationStubAction {

	public $name = 'Update a deal stage';

	public $slug = 'update_stage_deal';

	public $description = 'This action will update the stage of a deal.';

	public $source = 'sales';

	public $group = 'deal';
}

UpdateStageDeal::instance();
