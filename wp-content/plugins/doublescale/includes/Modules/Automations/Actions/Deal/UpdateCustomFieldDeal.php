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
 * UpdateCustomFieldDeal action stub.
 */
class UpdateCustomFieldDeal extends ProAutomationStubAction {

	public $name = 'Update a deal custom field';

	public $slug = 'update_custom_field_deal';

	public $description = 'This action will update a deal custom field.';

	public $source = 'sales';

	public $group = 'deal';
}

UpdateCustomFieldDeal::instance();
