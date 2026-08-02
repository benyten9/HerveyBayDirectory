<?php
/**
 * Typeform webhook registration (per Forms connection).
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Typeform;

use DoubleScale\Core\Managers\IntegrationsManager;
use DoubleScale\Modules\Forms\Models\FormModel;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and removes Typeform webhooks when Forms connections are saved.
 */
class WebhookService {

	/**
	 * Ensure a shared webhook signing secret exists in integration settings.
	 *
	 * @return string
	 */
	public static function ensure_integration_secret() {
		/** @var Integration $integration */
		$integration = IntegrationsManager::instance()->get_integration( 'typeform' );
		$secret      = $integration->get_setting( 'webhook_secret' );

		if ( ! empty( $secret ) ) {
			return $secret;
		}

		$secret = wp_generate_password( 32, false );
		$settings = $integration->get_settings();
		$settings['webhook_secret'] = $secret;
		$integration->update_settings( $settings );

		return $secret;
	}

	/**
	 * Register (or refresh) the webhook for an active Typeform form connection.
	 *
	 * @param FormModel $form DoubleScale form record.
	 * @return true|\WP_Error
	 */
	public static function sync_for_form_model( FormModel $form ) {
		if ( 'typeform' !== $form->form_type || empty( $form->form_id ) ) {
			return true;
		}

		if ( 'active' !== $form->status ) {
			self::delete_for_typeform_id( $form->form_id );
			return true;
		}

		/** @var Integration $integration */
		$integration = IntegrationsManager::instance()->get_integration( 'typeform' );
		$api         = $integration->connect();

		if ( ! $api ) {
			return new \WP_Error(
				'typeform_not_connected',
				__( 'Connect Typeform in Integrations with a personal access token first.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$secret = self::ensure_integration_secret();
		$url    = $integration->get_webhook_url();
		$tag    = Integration::WEBHOOK_TAG;
		$result = $api->create_webhook( $form->form_id, $tag, $url, $secret );

		if ( empty( $result['success'] ) ) {
			return new \WP_Error(
				'typeform_webhook_failed',
				__( 'Could not register the Typeform webhook. Ensure your site URL is publicly reachable.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Remove the webhook from a Typeform form.
	 *
	 * @param string $typeform_id External Typeform form ID.
	 * @return void
	 */
	public static function delete_for_typeform_id( $typeform_id ) {
		if ( empty( $typeform_id ) ) {
			return;
		}

		try {
			/** @var Integration $integration */
			$integration = IntegrationsManager::instance()->get_integration( 'typeform' );
			$api         = $integration->connect();
			if ( $api ) {
				$api->delete_webhook( $typeform_id, Integration::WEBHOOK_TAG );
			}
		} catch ( \Exception $e ) {
			// Best-effort cleanup.
		}
	}
}
