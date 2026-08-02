<?php

/**
 * Class WhatsApp Rule
 *
 * Rule for contact whatsapp
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Contact;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * WhatsApp Rule
 */
class Whatsapp extends Rule {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'WhatsApp';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'contact_whatsapp_phone';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Contact WhatsApp';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'contact';

	/**
	 * Is automation rule
	 *
	 * @var bool
	 */
	public $is_automation = false;

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'phone';

	/**
	 * Get value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 *
	 * @return mixed
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;
		return $contact->whatsapp_phone;
	}
}

RulesManager::instance()->register( new WhatsApp() );
