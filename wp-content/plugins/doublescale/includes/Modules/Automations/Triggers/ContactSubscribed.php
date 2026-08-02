<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * ContactSubscribed trigger stub.
 */
class ContactSubscribed extends TriggerPro {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Contact Subscribes';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'contact_subscribed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'Fires when a contact subscribes to Email, SMS, or WhatsApp. Filter by channel in automation settings (Pro).';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'contact';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
}

TriggersManager::instance()->register( new ContactSubscribed() );
