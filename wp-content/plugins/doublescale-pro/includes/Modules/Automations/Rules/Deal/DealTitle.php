<?php

/**
 * Class Deal Title
 *
 * This class is responsible for handling the Deal Title rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Deal;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;

/**
 * Deal Title class
 */
class DealTitle extends Rule
{

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Deal Title';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'deal_title';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'deal';

	/**
	 * Is automation rule
	 *
	 * @var boolean
	 *
	 * @since 1.0.0
	 */
	public $is_automation = true;

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'text';

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Deal Model.
	 *
	 * @return mixed
	 */
	public function get_value($automation_contact)
	{
        $deal_id = $automation_contact->data['deal_id'] ?? null;
        if ( ! $deal_id ) {
            return '';
        }
        $deal = DealModel::find($deal_id);
        if ( ! $deal ) {
            return '';
        }
        return $deal->title ?? '';
	}
}

DealRuleRegistration::register(new DealTitle());
