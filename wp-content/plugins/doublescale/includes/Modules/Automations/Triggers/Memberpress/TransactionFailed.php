<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Memberpress;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * TransactionFailed trigger stub.
 */
class TransactionFailed extends TriggerPro {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Transaction Failed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'memberpress_transaction_failed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a MemberPress transaction fails.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'memberpress';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'memberpress';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
}

TriggersManager::instance()->register( new TransactionFailed() );
