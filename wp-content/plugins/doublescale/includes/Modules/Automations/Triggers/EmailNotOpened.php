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
 * EmailNotOpened trigger stub.
 */
class EmailNotOpened extends TriggerPro {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Email Not Opened';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'email_not_opened';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'Fires when a contact has not opened any email for a set number of days. Use this to pause sending and protect domain reputation.';

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
	public $source = 'messaging';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'messaging';
}

TriggersManager::instance()->register( new EmailNotOpened() );
