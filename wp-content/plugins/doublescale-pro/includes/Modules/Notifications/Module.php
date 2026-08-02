<?php
/**
 * Notifications Pro module bootstrap.
 *
 * Pro extension layer for the free Notifications module. The notification engine
 * (service, preferences, categories, email sender, model, REST endpoints, the
 * heartbeat, and the free-module listeners) lives in FREE at
 * `doublescale/includes/Modules/Notifications`. Email is the free delivery
 * channel; this module unlocks the Pro channels (bell, browser, push) and wires
 * the Pro-module domain listeners (deals, tasks, campaigns, automations, email
 * tracking, integrations) plus the mobile push pipeline.
 *
 * Depends on free's `notifications` module. Slug is `notifications-pro`. When
 * free's module is missing (e.g. Pro activated standalone) every method no-ops
 * via a defensive `class_exists` guard, mirroring `booking-pro`.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Notifications;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Modules\Notifications\Services\NotificationChannels;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'notifications-pro';
	}

	public function label(): string {
		return __( 'Notifications Pro', 'doublescale' );
	}

	public function description(): string {
		return __( 'Pro extensions for notifications: in-app (bell), desktop (browser), and mobile push channels plus deal, task, campaign, automation, email-tracking, and integration alerts.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function dependencies(): array {
		return array( 'core', 'notifications' );
	}

	public function is_toggleable(): bool {
		return false;
	}

	/**
	 * Defensive presence check: free's Notifications module must be present for
	 * any Pro channel/listener to function (they all call free's
	 * NotificationService / read free's NotificationPreferences).
	 *
	 * Checks the free Module class (loaded eagerly during module discovery),
	 * not a service class (lazily autoloaded), so the check is reliable at the
	 * point register()/boot() run — mirroring booking-pro's guard.
	 */
	private function free_notifications_present(): bool {
		return class_exists( \DoubleScale\Modules\Notifications\Module::class, false );
	}

	public function register( Container $container ): void {
		if ( ! $this->free_notifications_present() ) {
			return;
		}

		// Unlock the Pro delivery channels. Registered in register() (which runs
		// before any module's boot()) so the full channel set is available before
		// the first notification is created or the preferences UI is read.
		add_filter(
			'doublescale_notification_allowed_channels',
			static function () {
				return NotificationChannels::ALL;
			}
		);

		// Wire Pro's domain listeners + the mobile push pipeline once free's
		// Notifications module has booted. The free module fires this action at
		// the tail of its boot(), so the engine is fully available here.
		add_action(
			'doublescale_register_notification_listeners',
			static function () {
				new DealNotifications();
				new TaskNotifications();
				new CampaignNotifications();
				new AutomationNotifications();
				new EmailTrackingNotifications();
				new IntegrationNotifications();
				new ProjectNotifications();
				new \DoubleScale\Pro\Modules\Sales\Approvals\ApprovalNotifications();

				add_action(
					'doublescale_send_push_notification',
					array( Services\PushNotificationService::class, 'send' ),
					10,
					3
				);
			}
		);
	}

	public function boot( Container $container ): void {
		if ( ! $this->free_notifications_present() ) {
			return;
		}

		parent::boot( $container );
	}
}
