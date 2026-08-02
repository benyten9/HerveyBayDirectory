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
 * ContactInformationUpdated trigger stub.
 */
class ContactInformationUpdated extends TriggerPro {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Contact Information Updated';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'contact_information_updated';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger fires when a contact\'s profile information is updated, either by an admin or by the contact themselves.';

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
}

TriggersManager::instance()->register( new ContactInformationUpdated() );
