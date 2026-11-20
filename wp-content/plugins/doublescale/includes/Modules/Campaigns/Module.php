<?php
/**
 * Campaigns module bootstrap.
 *
 * Owns: campaigns, templates, sequences, campaign REST API, AI email builder hooks.
 * The email builder/renderer/blocks themselves live in the Emails module.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Abilities\ProvidesAbilities;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Campaigns\Abilities\CampaignAbilities;
use DoubleScale\Modules\Campaigns\Services\ChannelEngineRegistry;
use DoubleScale\Core\Container;

final class Module extends AbstractModule implements ProvidesAbilities {

	/**
	 * Read-only abilities for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return CampaignAbilities::definitions();
	}

	public function slug(): string {
		return 'campaigns';
	}

	public function label(): string {
		return __( 'Campaigns', 'doublescale' );
	}

	public function description(): string {
		return __( 'Email and SMS campaigns, templates, and email sequences.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return true;
	}

	public function dependencies(): array {
		return array( 'core', 'contacts', 'emails' );
	}

	/**
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function scheduledHooks(): array {
		// Every channel's drain, whichever plugin scheduled it: with Campaigns
		// off nothing listens on them, so a drain left behind — Pro's SMS one
		// included — would fail every minute. Unscheduling a drain that was
		// never scheduled is a no-op.
		return array(
			array( 'doublescale_campaigns', 'doublescale_email_campaigns' ),
			array( 'doublescale_campaigns', 'doublescale_sms_campaigns' ),
			array( 'doublescale_campaigns', 'doublescale_whatsapp_campaigns' ),
		);
	}

	public function register( Container $container ): void {
		$container->singleton(
			Services\CampaignStatusManager::class,
			static fn() => Services\CampaignStatusManager::instance()
		);
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestCampaignController::class,
			Rest\Controllers\RestTemplateController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		Campaign\EmailProcessing::instance();

		// Pro registers its SMS and WhatsApp engines at priority 20; an older
		// Pro's SMS engine is adopted here. See ChannelEngineRegistry.
		add_action( 'doublescale_modules_booted', array( $this, 'register_fallback_engines' ), 30 );

		add_action( 'init', array( $this, 'register_cron_schedules' ) );

		// Daily send counters carry their date; drop past days on the free
		// "Daily Cleanup Tasks" slot (see Notifications\Module).
		add_action( 'doublescale_daily_doublescale_daily4', array( Services\CampaignRateLimiter::class, 'cleanup_old_daily_counts' ) );
	}

	/**
	 * Adopt an older Pro's SMS engine into the registry.
	 *
	 * Free ships no WhatsApp engine: Pro 1.3.0+ registers its own, and next to
	 * an older Pro WhatsApp is off (see Compat\LegacyWhatsapp).
	 *
	 * @return void
	 */
	public function register_fallback_engines() {
		// A Pro that predates the registry boots its SMS engine on its own
		// (doublescale_modules_booted @20) and never registers it. Adopt that
		// instance so every route finds SMS the same way on any Pro version.
		// The `false` keeps this to a Pro that actually booted SMS here.
		$pro_sms = '\\DoubleScale\\Pro\\Modules\\Campaigns\\Sms\\SmsProcessing';
		if ( ! ChannelEngineRegistry::has( CampaignChannel::STR_SMS ) && class_exists( $pro_sms, false ) ) {
			ChannelEngineRegistry::register( CampaignChannel::STR_SMS, $pro_sms::instance(), ChannelEngineRegistry::OWNER_PRO );
		}
	}

	public function register_cron_schedules() {
		if ( get_transient( 'doublescale_register_tasks_lock_campaigns' ) ) {
			return;
		}
		set_transient( 'doublescale_register_tasks_lock_campaigns', 1, MINUTE_IN_SECONDS );
		$tasks = new \DoubleScale\Core\Tasks( 'doublescale_campaigns' );

		if ( $tasks->get_next_timestamp( 'doublescale_email_campaigns' ) === false ) {
			$tasks->schedule_recurring( time(), 60, 'doublescale_email_campaigns' );
		}

		// The WhatsApp drain belongs to the engine that runs WhatsApp, and free
		// runs none. A drain free scheduled before it stopped shipping its
		// engine would wake every minute with nothing listening: clear it while
		// no engine owns WhatsApp (Pro's engine schedules its own).
		if (
			! ChannelEngineRegistry::has( CampaignChannel::STR_WHATSAPP )
			&& $tasks->get_next_timestamp( 'doublescale_whatsapp_campaigns' ) !== false
		) {
			$tasks->unschedule_all( 'doublescale_whatsapp_campaigns' );
		}
	}
}
