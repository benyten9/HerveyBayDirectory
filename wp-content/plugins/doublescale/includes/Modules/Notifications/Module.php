<?php
/**
 * Notifications module bootstrap (free).
 *
 * Owns the shared notification engine: the notification service + preferences,
 * the email channel (the free delivery channel), the notification model +
 * migration, the heartbeat, the REST endpoints, and the domain listeners for
 * modules that ship in free (booking, support, contacts, forms, import).
 *
 * Email is the only channel available out of the box. Bell, browser, and push
 * are Pro channels: Pro opts them in through the
 * {@see 'doublescale_notification_allowed_channels'} filter and attaches its own
 * domain listeners (deals, tasks, campaigns, automations, etc.) via the
 * {@see 'doublescale_register_notification_listeners'} action fired in boot().
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Modules\Notifications\Services\NotificationEmailSender;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'notifications';
	}

	public function label(): string {
		return __( 'Notifications', 'doublescale' );
	}

	public function description(): string {
		return __( 'Email notifications for campaigns, deals, tasks, bookings, support, and more.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestNotificationsController::class,
			Rest\Controllers\RestNotificationPreferencesController::class,
		);
	}

	public function register( Container $container ): void {
		$container->singleton(
			NotificationHeartbeat::class,
			static fn() => NotificationHeartbeat::instance()
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		// Real-time bell/browser updates (no-ops on free where those channels
		// are unavailable, but harmless — kept so Pro doesn't have to re-wire it).
		$container->get( NotificationHeartbeat::class );

		// Free-module domain listeners. Each guards on its owning module being
		// active, so they no-op when that module is disabled.
		new ImportNotifications();
		new FormNotifications();
		new ContactNotifications();
		new BookingNotifications();
		new SupportNotifications();

		// Reset the site-wide daily notification-email counter once a day so the
		// 1,000/day cap is a rolling daily limit rather than a lifetime ceiling.
		// `doublescale_daily4` is the free "Daily Cleanup Tasks" slot scheduled in
		// CoreModule::register_cron_schedules(); the Tasks group prefixes the hook.
		add_action( 'doublescale_daily_doublescale_daily4', array( NotificationEmailSender::class, 'reset_daily_count' ) );

		/**
		 * Let other plugins (Pro) attach additional notification listeners and
		 * channels after the free engine has booted. Fires once, right after the
		 * free listeners are wired and while the module registry is still booting,
		 * so any `doublescale_notification_allowed_channels` filters Pro adds here
		 * are in place before the first notification is created.
		 *
		 * @since 1.0.0
		 *
		 * @param Container $container The DI container.
		 */
		do_action( 'doublescale_register_notification_listeners', $container );
	}
}
