<?php
/**
 * Inbox module bootstrap.
 *
 * Owns: message providers (Twilio, Meta WhatsApp), bounce handlers, inbox/messaging
 * REST API, individual senders, email/IMAP incoming, OAuth and user email polling.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'inbox';
	}

	public function label(): string {
		return __( 'Inbox', 'doublescale' );
	}

	public function description(): string {
		return __( 'Unified messaging inbox for email, SMS, and WhatsApp conversations.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function register( Container $container ): void {
		$container->singleton(
			Services\MessageProviderRegistry::class,
			static fn() => Services\MessageProviderRegistry::instance()
		);

		$container->singleton(
			Services\BounceHandlerManager::class,
			static fn() => Services\BounceHandlerManager::instance()
		);
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestMessagingController::class,
			Rest\Controllers\RestWhatsappTemplatesController::class,
			Rest\Controllers\RestMetaWhatsappTemplatesController::class,
			Rest\Controllers\RestUserEmailController::class,
			Rest\Controllers\RestInboxController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		$campaigns_tasks = new \DoubleScale\Pro\Modules\Tasks\Tasks( 'doublescale_campaigns' );
		Incoming\MessagingIncoming::instance();
		Incoming\EmailIncoming::instance( $campaigns_tasks );
		Oauth\EmailOauth::init();
		Oauth\UserEmailPoller::instance( $campaigns_tasks );
		Oauth\UserEmailOauth::init();
		$container->get( Services\BounceHandlerManager::class );
		$container->get( Services\MessageProviderRegistry::class );

		$this->loadModuleMergeTagFiles();

		add_action( 'init', array( $this, 'register_cron_schedules' ) );
	}

	public function register_cron_schedules() {
		if ( get_transient( 'doublescale_register_tasks_lock_inbox' ) ) {
			return;
		}
		set_transient( 'doublescale_register_tasks_lock_inbox', 1, MINUTE_IN_SECONDS );
		$tasks = new \DoubleScale\Pro\Modules\Tasks\Tasks( 'doublescale_campaigns' );

		$interval = self::poll_interval();

		// A changed interval must replace the existing recurring action —
		// Action Scheduler keeps the interval it was created with.
		$applied = (int) get_option( 'doublescale_inbox_poll_interval_applied', 0 );
		if ( $applied !== $interval ) {
			$tasks->unschedule_all( 'doublescale_email_inbound' );
			$tasks->unschedule_all( 'doublescale_user_email_accounts' );
			update_option( 'doublescale_inbox_poll_interval_applied', $interval, false );
		}

		$settings = \DoubleScale\Pro\Settings::get( 'email_inbound', array() );
		if ( ! empty( $settings['enabled'] ) ) {
			if ( $tasks->get_next_timestamp( 'doublescale_email_inbound' ) === false ) {
				$tasks->schedule_recurring( time(), $interval, 'doublescale_email_inbound' );
			}
		}

		if ( get_option( 'doublescale_has_user_email_accounts' ) ) {
			if ( $tasks->get_next_timestamp( 'doublescale_user_email_accounts' ) === false ) {
				$tasks->schedule_recurring( time(), $interval, 'doublescale_user_email_accounts' );
			}
		}
	}

	/**
	 * Seconds between mailbox polls.
	 *
	 * Every run costs a WP bootstrap plus an IMAP connect, so hosts with strict
	 * CPU budgets can stretch this via the filter. Clamped to 60s–1h.
	 *
	 * @return int
	 */
	public static function poll_interval() {
		$interval = (int) apply_filters( 'doublescale_inbox_poll_interval', 60 );
		return max( 60, min( HOUR_IN_SECONDS, $interval ) );
	}
}
