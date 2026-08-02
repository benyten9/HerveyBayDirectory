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
 * AddNoteDeal action stub.
 */
class AddNoteDeal extends ProAutomationStubAction {

	public $name = 'Add a deal note';

	public $slug = 'add_note_deal';

	public $description = 'This action will add a note to a deal.';

	public $source = 'sales';

	public $group = 'deal';
}

AddNoteDeal::instance();
