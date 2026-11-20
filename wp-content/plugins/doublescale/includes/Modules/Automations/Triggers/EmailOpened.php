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
 * EmailOpened trigger stub.
 */
class EmailOpened extends TriggerPro {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Email Opened';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'email_opened';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'Fires the first time a contact opens an email. Use this to follow up while your message is fresh.';

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

// Without Pro this is listed as a locked Pro trigger. With a Pro that predates
// the trigger it would be listed, selectable, and never fire — so it stays out
// of the picker until that Pro is updated.
if ( ! doublescale_is_pro_addon_active()
	|| class_exists( \DoubleScale\Pro\Modules\Automations\Triggers\EmailOpened::class ) ) {
	TriggersManager::instance()->register( new EmailOpened() );
}
