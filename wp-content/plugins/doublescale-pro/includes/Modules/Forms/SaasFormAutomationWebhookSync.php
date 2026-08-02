<?php
/**
 * Register/remove SaaS form webhooks (Typeform, Jotform) directly from an
 * automation, so those triggers work without a separate Forms → SaaS Forms
 * connection.
 *
 * The inbound webhook is shared per external form: a Forms connection and any
 * number of automations that target the same form all rely on the one webhook.
 * We therefore only remove a webhook when nothing else still needs it.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms;

use DoubleScale\Core\Managers\IntegrationsManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Pro\Modules\Integrations\Jotform\WebhookService as JotformWebhookService;
use DoubleScale\Pro\Modules\Integrations\Typeform\WebhookService as TypeformWebhookService;

defined( 'ABSPATH' ) || exit;

/**
 * Automation-driven SaaS webhook sync.
 */
class SaasFormAutomationWebhookSync {

	/**
	 * Trigger slugs handled here (each maps to an Integration + Forms handler
	 * of the same slug).
	 *
	 * @var string[]
	 */
	private const SLUGS = array( 'typeform', 'jotform' );

	/**
	 * Register hooks. Called once on Pro boot.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'doublescale_automation_saved', array( __CLASS__, 'on_saved' ), 10, 2 );
		add_action( 'doublescale_automation_deleted', array( __CLASS__, 'on_deleted' ), 10, 1 );
	}

	/**
	 * @param AutomationModel $automation Saved automation.
	 * @param string|null     $old_status Previous status.
	 * @return void
	 */
	public static function on_saved( $automation, $old_status = null ) {
		unset( $old_status );

		$slug = self::slug_for( $automation );
		if ( null === $slug ) {
			return;
		}

		$form_id = self::form_id_for( $automation );
		if ( '' === $form_id ) {
			return;
		}

		if ( 'active' === $automation->status ) {
			self::register( $slug, $form_id );
		} else {
			self::maybe_remove( $slug, $form_id, (int) $automation->id );
		}
	}

	/**
	 * @param AutomationModel $automation Automation about to be deleted.
	 * @return void
	 */
	public static function on_deleted( $automation ) {
		$slug = self::slug_for( $automation );
		if ( null === $slug ) {
			return;
		}

		$form_id = self::form_id_for( $automation );
		if ( '' === $form_id ) {
			return;
		}

		self::maybe_remove( $slug, $form_id, (int) $automation->id );
	}

	/**
	 * The SaaS slug this automation targets, or null when it is not one of ours.
	 *
	 * @param AutomationModel $automation Automation.
	 * @return string|null
	 */
	private static function slug_for( $automation ) {
		$trigger = $automation->trigger ?? '';
		return in_array( $trigger, self::SLUGS, true ) ? $trigger : null;
	}

	/**
	 * The external form id stored on the automation trigger settings.
	 *
	 * @param AutomationModel $automation Automation.
	 * @return string
	 */
	private static function form_id_for( $automation ) {
		$form_id = (string) $automation->get_setting( 'form_id', '' );

		// Some form pickers prefix the value as "slug:external_id".
		if ( false !== strpos( $form_id, ':' ) ) {
			$parts   = explode( ':', $form_id );
			$form_id = end( $parts );
		}

		return trim( $form_id );
	}

	/**
	 * Register the webhook for a form (idempotent — WebhookService de-dupes).
	 *
	 * @param string $slug    Integration slug.
	 * @param string $form_id External form id.
	 * @return void
	 */
	private static function register( $slug, $form_id ) {
		$integration = IntegrationsManager::instance()->get_integration( $slug );
		if ( ! $integration || ! $integration->is_connected() ) {
			return;
		}

		if ( 'jotform' === $slug ) {
			JotformWebhookService::ensure_integration_secret();
			$api = $integration->connect();
			if ( $api ) {
				$api->create_webhook( $form_id, $integration->get_webhook_url() );
			}
			return;
		}

		// Typeform.
		$api = $integration->connect();
		if ( ! $api ) {
			return;
		}
		$secret = TypeformWebhookService::ensure_integration_secret();
		$api->create_webhook(
			$form_id,
			\DoubleScale\Pro\Modules\Integrations\Typeform\Integration::WEBHOOK_TAG,
			$integration->get_webhook_url(),
			$secret
		);
	}

	/**
	 * Remove the webhook only when no active Forms connection and no other
	 * active automation still needs it.
	 *
	 * @param string $slug              Integration slug.
	 * @param string $form_id           External form id.
	 * @param int    $excluded_automation Automation id to ignore in the count.
	 * @return void
	 */
	private static function maybe_remove( $slug, $form_id, $excluded_automation ) {
		if ( self::form_connection_exists( $slug, $form_id ) ) {
			return;
		}

		if ( self::other_active_automation_exists( $slug, $form_id, $excluded_automation ) ) {
			return;
		}

		if ( 'jotform' === $slug ) {
			JotformWebhookService::delete_for_jotform_id( $form_id );
		} else {
			TypeformWebhookService::delete_for_typeform_id( $form_id );
		}
	}

	/**
	 * Public entry point for the Forms handlers: remove a SaaS webhook after a
	 * form connection is deleted, but only when nothing else still needs it.
	 *
	 * The inbound webhook is shared per external form — a Forms connection and
	 * any number of automations that target the same form all rely on the one
	 * webhook. Deleting the form connection must therefore NOT tear the webhook
	 * away from a still-active automation. This mirrors maybe_remove(); there is
	 * no automation to exclude here (the caller is a form deletion, not an
	 * automation change), and the just-deleted form is already gone from the DB
	 * so form_connection_exists() will not count it.
	 *
	 * @param string $slug    Integration slug ('jotform'|'typeform').
	 * @param string $form_id External form id.
	 * @return void
	 */
	public static function remove_webhook_if_unused( $slug, $form_id ) {
		if ( ! in_array( $slug, self::SLUGS, true ) || '' === (string) $form_id ) {
			return;
		}

		self::maybe_remove( $slug, (string) $form_id, 0 );
	}

	/**
	 * Whether an active Forms → SaaS Forms connection uses this form.
	 *
	 * @param string $slug    Integration slug.
	 * @param string $form_id External form id.
	 * @return bool
	 */
	private static function form_connection_exists( $slug, $form_id ) {
		if ( ! class_exists( FormModel::class ) ) {
			return false;
		}

		// get_form_by_form_id() ends in firstOrFail(), which throws
		// ModelNotFoundException ("No query results for model [FormModel]") when
		// there is no matching active form — e.g. the form was just deleted.
		// Here "not found" simply means "no active connection", so swallow it
		// and return false rather than letting it bubble up and fail the request.
		try {
			$form = FormModel::get_form_by_form_id( $form_id, $slug, 'active' );
		} catch ( \Throwable $e ) {
			return false;
		}

		return ! empty( $form );
	}

	/**
	 * Whether another active automation targets the same form.
	 *
	 * @param string $slug                Integration slug.
	 * @param string $form_id             External form id.
	 * @param int    $excluded_automation Automation id to ignore.
	 * @return bool
	 */
	private static function other_active_automation_exists( $slug, $form_id, $excluded_automation ) {
		$automations = AutomationModel::get_automations_by_trigger( $slug );

		foreach ( $automations as $automation ) {
			if ( (int) $automation->id === $excluded_automation ) {
				continue;
			}
			if ( self::form_id_for( $automation ) === $form_id ) {
				return true;
			}
		}

		return false;
	}
}
