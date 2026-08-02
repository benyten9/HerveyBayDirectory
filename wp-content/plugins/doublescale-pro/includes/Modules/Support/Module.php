<?php
/**
 * Support Pro module bootstrap.
 *
 * Pro extension layer for the free Support module. Its one job today is the
 * **inbound email channel** (`box_type='email'` mailboxes): turning incoming
 * email into support tickets.
 *
 * Two inbound paths, mutually exclusive per mailbox:
 *   - PRIMARY — {@see Services\MailboxImapPoller} polls each `box_type='email'`
 *     mailbox's OWN inbox directly over IMAP (Gmail/Outlook OAuth), on the
 *     recurring `doublescale_support_email_inbound` action this module schedules
 *     (group `doublescale_support`) whenever at least one email channel exists.
 *   - FALLBACK — {@see Services\InboundTicketRouter} turns mail FORWARDED into
 *     the global CRM inbox (`doublescale_email_received`) into tickets by
 *     recipient match, for send-only connections / operators who prefer
 *     forwarding. It skips receive-capable mailboxes (the poller owns those).
 *
 * Both share {@see Services\InboundTicketFactory} for the actual ticket
 * minting/threading via the **free** `TicketService`.
 *
 * Like Booking Pro, this depends on the free `support` module and no-ops via a
 * `class_exists` guard if free Support is missing (Pro installed standalone).
 * Slug is `support-pro`; namespace is `DoubleScale\Pro\Modules\Support`.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Support;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

/**
 * Support Pro module.
 */
final class Module extends AbstractModule {

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'support-pro';
	}

	/**
	 * Human-readable module label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Support Pro', 'doublescale' );
	}

	/**
	 * Module description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Pro extensions for the Support module: email channel intake and ticket custom fields.', 'doublescale' );
	}

	/**
	 * Module version.
	 *
	 * @return string
	 */
	public function version(): string {
		return '1.0.0';
	}

	/**
	 * Whether the module can be toggled off independently. It rides with free
	 * Support and adds no standalone surface, so it is not separately toggleable.
	 *
	 * @return bool
	 */
	public function is_toggleable(): bool {
		return false;
	}

	/**
	 * Defensive presence check: free's Support module must be available for the
	 * router/poller to function (they call free's `TicketService` /
	 * `ContactResolver` and query free's `MailboxModel`). This guards Pro running
	 * standalone (free Support absent).
	 *
	 * NOTE: autoload is intentionally ENABLED here. `register()`/`boot()` run in
	 * module-registry order, and Pro Support (`support-pro`) can be booted BEFORE
	 * free Support (`support`) — `dependencies()` is advisory
	 * (`ModuleRegistry::sort_by_dependencies()` continues past missing deps and
	 * imposes no hard ordering between two always-on modules). With a
	 * load-order-only check (`class_exists(..., false)`) the class is simply
	 * "not loaded yet" at that point, so the guard would wrongly report free
	 * Support absent and silently disable the ENTIRE inbound email channel
	 * (router never subscribed, poll never scheduled). Allowing autoload answers
	 * the real question — "is free Support installed?" — independent of boot
	 * order. We also declare the dependency in {@see dependencies()} as
	 * defence-in-depth.
	 *
	 * @return bool
	 */
	private function free_support_present(): bool {
		return class_exists( \DoubleScale\Modules\Support\Services\TicketService::class );
	}

	/**
	 * Module load-order dependencies. Beyond `core`, this rides on free Support:
	 * it resolves free's `TicketService`/`ContactResolver` and queries free's
	 * `MailboxModel`, so free Support should boot first. The registry's sort is
	 * advisory (it continues past missing deps), so {@see free_support_present()}
	 * remains the hard guard — this just nudges the boot order.
	 *
	 * @return string[]
	 */
	public function dependencies(): array {
		return array( 'core', 'support' );
	}

	/**
	 * Bind module services. No-ops when free Support is absent.
	 *
	 * @param Container $container DI container.
	 * @return void
	 */
	public function register( Container $container ): void {
		if ( ! $this->free_support_present() ) {
			return;
		}

		// Forwarding fallback: inbound email → ticket router. Singleton so its
		// hook subscription happens exactly once; resolving it in boot() is what
		// wires the `doublescale_email_received` listener.
		$container->singleton(
			Services\InboundTicketRouter::class,
			static fn () => new Services\InboundTicketRouter()
		);

		// Primary inbound: per-mailbox IMAP poller. Resolved in boot() to wire
		// its recurring scheduled callback.
		$container->singleton(
			Services\MailboxImapPoller::class,
			static fn () => new Services\MailboxImapPoller()
		);

		$container->singleton(
			Services\CustomFieldsService::class,
			static fn () => new Services\CustomFieldsService()
		);

		$container->singleton(
			Services\WebhookTokenService::class,
			static fn () => new Services\WebhookTokenService()
		);

		$container->singleton(
			Services\IncomingWebhookService::class,
			static fn ( $app ) => new Services\IncomingWebhookService(
				$app->make( Services\WebhookTokenService::class )
			)
		);

		// Auto-close inactive tickets: settings facade + the daily runner. The
		// runner is resolved in boot() to wire its recurring scheduled callback.
		$container->singleton(
			Services\AutoCloseSettings::class,
			static fn () => new Services\AutoCloseSettings()
		);
		$container->singleton(
			Services\AutoCloseRunner::class,
			static fn () => new Services\AutoCloseRunner()
		);
	}

	/**
	 * REST controllers registered by this module.
	 *
	 * @return array<int, class-string>
	 */
	public function restControllers(): array {
		return array(
			Rest\Controllers\RestCustomFieldsController::class,
			Rest\Controllers\RestIncomingWebhookController::class,
			Rest\Controllers\RestAutoCloseController::class,
		);
	}

	/**
	 * Boot the module: resolve the router so it subscribes to the CRM inbound
	 * hook. No-ops when free Support is absent.
	 *
	 * @param Container $container DI container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		if ( ! $this->free_support_present() ) {
			return;
		}

		parent::boot( $container );

		// Resolve the router so its constructor subscribes to
		// `doublescale_email_received` (the forwarding fallback path).
		$container->get( Services\InboundTicketRouter::class );

		// Register the poll callback now (a plain add_action, safe this early) so
		// an in-flight action always has a handler.
		$this->register_inbound_callback( $container );

		// Same contract for the auto-close runner: the callback exists from boot,
		// the scheduling is deferred to `init`.
		$this->register_auto_close_callback( $container );

		// DEFER the actual (un)scheduling to `init`. Modules boot during
		// plugin-load, which is BEFORE Action Scheduler initializes its data
		// store; calling as_schedule_recurring_action() now is rejected
		// ("called before the Action Scheduler data store was initialized") and
		// nothing persists. The Inbox module schedules from `init` for the same
		// reason ({@see \DoubleScale\Pro\Modules\Inbox\Module::register_cron_schedules()}).
		add_action(
			'init',
			function () {
				$this->sync_inbound_schedule();
				$this->sync_auto_close_schedule();
			}
		);
	}

	/**
	 * Register the per-mailbox IMAP poll callback on the recurring action hook.
	 *
	 * Split out of {@see sync_inbound_schedule()} so it can run in `boot()` (the
	 * callback must exist as early as possible for any in-flight action) while
	 * the scheduling itself is deferred to `init`.
	 *
	 * @param Container $container DI container.
	 * @return void
	 */
	private function register_inbound_callback( Container $container ): void {
		if ( ! class_exists( \DoubleScale\Pro\Modules\Tasks\Tasks::class ) ) {
			return;
		}
		$tasks  = new \DoubleScale\Pro\Modules\Tasks\Tasks( 'doublescale_support' );
		$poller = $container->get( Services\MailboxImapPoller::class );
		$tasks->register_callback( 'doublescale_support_email_inbound', array( $poller, 'run' ) );
	}

	/**
	 * Schedule (or tear down) the recurring `doublescale_support_email_inbound`
	 * action based on whether any email channel currently exists.
	 *
	 * Runs on `init` (deferred from `boot()` — see the call site) because Action
	 * Scheduler's data store is not initialized during plugin-load. The poll
	 * callback itself is registered earlier in `boot()` via
	 * {@see register_inbound_callback()}, so an in-flight action always has a
	 * handler regardless of scheduling state.
	 *
	 * Scheduling on group `doublescale_support` with the full hook name
	 * `doublescale_support_email_inbound` matches free Support's
	 * `scheduledHooks()` declaration, so toggling the Support module off cleanly
	 * unschedules the poll via `ModuleManager::clearScheduledTasksForModule()`.
	 * Re-evaluated every load: adding the first email channel starts polling on
	 * the next load; removing the last (or disabling Support) stops it.
	 *
	 * @return void
	 */
	private function sync_inbound_schedule(): void {
		if ( ! class_exists( \DoubleScale\Pro\Modules\Tasks\Tasks::class ) ) {
			return;
		}

		$tasks = new \DoubleScale\Pro\Modules\Tasks\Tasks( 'doublescale_support' );

		// `doublescale_is_module_enabled()` never existed, so the previous guard was
		// always true and this gate never fired. Match the idiom used by
		// doublescale_sales_child_module_active() in ModuleFeatureGate.php.
		$support_on = function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'support' );

		$has_email_box = false;
		if ( $support_on ) {
			try {
				$has_email_box = \DoubleScale\Modules\Support\Models\MailboxModel::where( 'box_type', 'email' )->exists();
			} catch ( \Throwable $e ) {
				return; // Table not ready — retry next boot.
			}
		}

		if ( $support_on && $has_email_box ) {
			if ( false === $tasks->get_next_timestamp( 'doublescale_support_email_inbound' ) ) {
				$tasks->schedule_recurring( time(), MINUTE_IN_SECONDS, 'doublescale_support_email_inbound' );
			}
		} else {
			$tasks->unschedule_all( 'doublescale_support_email_inbound' );
		}
	}

	/**
	 * Register the auto-close runner on the recurring action hook.
	 *
	 * Split out of {@see sync_auto_close_schedule()} so the callback exists from
	 * `boot()` (an in-flight action must always have a handler) while the
	 * scheduling itself is deferred to `init` — same pattern as the IMAP poller.
	 *
	 * @param Container $container DI container.
	 * @return void
	 */
	private function register_auto_close_callback( Container $container ): void {
		if ( ! class_exists( \DoubleScale\Pro\Modules\Tasks\Tasks::class ) ) {
			return;
		}
		$tasks  = new \DoubleScale\Pro\Modules\Tasks\Tasks( 'doublescale_support' );
		$runner = $container->get( Services\AutoCloseRunner::class );
		$tasks->register_callback( 'doublescale_support_auto_close', array( $runner, 'run' ) );
	}

	/**
	 * Schedule (or tear down) the daily `doublescale_support_auto_close` action
	 * based on whether the feature is currently enabled.
	 *
	 * Runs on `init` (deferred from `boot()`) because Action Scheduler's data
	 * store is not initialized during plugin-load. The runner callback itself is
	 * registered earlier in `boot()` via {@see register_auto_close_callback()},
	 * so an in-flight action always has a handler regardless of scheduling state
	 * (and the runner is itself a no-op when the feature is disabled).
	 *
	 * Scheduling on group `doublescale_support` with the full hook name
	 * `doublescale_support_auto_close` matches free Support's `scheduledHooks()`
	 * declaration, so toggling the Support module off cleanly unschedules the
	 * job via `ModuleManager::clearScheduledTasksForModule()`. Gating on the
	 * `enabled` flag (rather than scheduling unconditionally) keeps the queue
	 * clean when the operator hasn't turned the feature on.
	 *
	 * @return void
	 */
	private function sync_auto_close_schedule(): void {
		if ( ! class_exists( \DoubleScale\Pro\Modules\Tasks\Tasks::class ) ) {
			return;
		}

		$tasks = new \DoubleScale\Pro\Modules\Tasks\Tasks( 'doublescale_support' );

		// `doublescale_is_module_enabled()` never existed, so the previous guard was
		// always true and this gate never fired. Match the idiom used by
		// doublescale_sales_child_module_active() in ModuleFeatureGate.php.
		$support_on = function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'support' );
		$enabled    = false;
		if ( $support_on ) {
			$settings = Services\AutoCloseSettings::get();
			$enabled  = ! empty( $settings['enabled'] );
		}

		if ( $enabled ) {
			if ( false === $tasks->get_next_timestamp( 'doublescale_support_auto_close' ) ) {
				$tasks->schedule_recurring( time(), DAY_IN_SECONDS, 'doublescale_support_auto_close' );
			}
		} else {
			$tasks->unschedule_all( 'doublescale_support_auto_close' );
		}
	}
}
