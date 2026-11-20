<?php
/**
 * Workflow send queue: email, SMS and WhatsApp from workflows go out in
 * pages within the shared limits instead of one by one inside each step.
 *
 * @package DoubleScale\Pro\Modules\SendQueue
 */

namespace DoubleScale\Pro\Modules\SendQueue;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

/**
 * Send queue module.
 */
final class Module extends AbstractModule {

	/**
	 * @return string
	 */
	public function slug(): string {
		return 'send-queue';
	}

	/**
	 * @return string
	 */
	public function label(): string {
		return __( 'Workflow Send Queue', 'doublescale' );
	}

	/**
	 * @return string
	 */
	public function description(): string {
		return __( 'Sends workflow emails, SMS and WhatsApp messages in batches within the daily and WhatsApp limits.', 'doublescale' );
	}

	/**
	 * @return bool
	 */
	public function is_toggleable(): bool {
		return false;
	}

	/**
	 * @return array<int, string>
	 */
	public function dependencies(): array {
		return array( 'core' );
	}

	/**
	 * @return array<int, string>
	 */
	public function migrations(): array {
		return array( $this->module_dir() . '/Migrations/SendQueueTable.php' );
	}

	/**
	 * @return array<int, string>
	 */
	public function restControllers(): array {
		return array( Rest\Controllers\RestSendQueueController::class );
	}

	/**
	 * @param Container $container Service container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		parent::boot( $container );

		if ( ! SendQueue::engine_supported() ) {
			return;
		}

		Dispatcher::register();

		// Workflows first: a campaign gives way while workflow messages wait.
		add_filter(
			'doublescale_campaign_should_yield',
			static function ( $yield, $channel ) {
				return $yield || SendQueue::has_due( (string) $channel );
			},
			10,
			2
		);

		add_action( 'doublescale_contact_deleting', array( SendQueue::class, 'forget_contact' ) );
	}
}
