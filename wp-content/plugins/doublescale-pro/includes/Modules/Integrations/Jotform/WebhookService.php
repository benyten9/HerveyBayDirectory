<?php
/**
 * Jotform webhook registration (per Forms connection).
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Jotform;

use DoubleScale\Core\Managers\IntegrationsManager;
use DoubleScale\Modules\Forms\Models\FormModel;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and removes Jotform webhooks when Forms connections are saved.
 */
class WebhookService {

	/**
	 * Ensure a shared webhook token exists in integration settings. Jotform does
	 * not sign its callbacks, so this token — embedded in the callback URL path —
	 * is how inbound requests are authenticated.
	 *
	 * @return string
	 */
	public static function ensure_integration_secret() {
		/** @var Integration $integration */
		$integration = IntegrationsManager::instance()->get_integration( 'jotform' );
		$token       = $integration->get_setting( 'webhook_token' );

		if ( ! empty( $token ) ) {
			return $token;
		}

		$token                     = wp_generate_password( 32, false );
		$settings                  = $integration->get_settings();
		$settings['webhook_token'] = $token;
		$integration->update_settings( $settings );

		return $token;
	}

	/**
	 * Register (or refresh) the webhook for an active Jotform form connection.
	 *
	 * @param FormModel $form DoubleScale form record.
	 * @return true|\WP_Error
	 */
	public static function sync_for_form_model( FormModel $form ) {
		if ( 'jotform' !== $form->form_type || empty( $form->form_id ) ) {
			return true;
		}

		if ( 'active' !== $form->status ) {
			self::delete_for_jotform_id( $form->form_id );
			return true;
		}

		/** @var Integration $integration */
		$integration = IntegrationsManager::instance()->get_integration( 'jotform' );
		$api         = $integration->connect();

		if ( ! $api ) {
			return new \WP_Error(
				'jotform_not_connected',
				__( 'Connect Jotform in Integrations with an API key first.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// Ensure the token exists before building the URL.
		self::ensure_integration_secret();
		$url = $integration->get_webhook_url();

		// Skip if a webhook already points at our URL (Jotform allows duplicates).
		if ( null !== self::find_webhook_index( $api, $form->form_id, $url ) ) {
			return true;
		}

		$result = $api->create_webhook( $form->form_id, $url );

		if ( empty( $result['success'] ) ) {
			return new \WP_Error(
				'jotform_webhook_failed',
				__( 'Could not register the Jotform webhook. Ensure your site URL is publicly reachable.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Remove our webhook from a Jotform form.
	 *
	 * @param string $jotform_id External Jotform form ID.
	 * @return void
	 */
	public static function delete_for_jotform_id( $jotform_id ) {
		if ( empty( $jotform_id ) ) {
			return;
		}

		try {
			/** @var Integration $integration */
			$integration = IntegrationsManager::instance()->get_integration( 'jotform' );
			$api         = $integration->connect();
			if ( ! $api ) {
				return;
			}

			$url   = $integration->get_webhook_url();
			$index = self::find_webhook_index( $api, $jotform_id, $url );
			if ( null !== $index ) {
				$api->delete_webhook( $jotform_id, $index );
			}
		} catch ( \Exception $e ) {
			// Best-effort cleanup.
		}
	}

	/**
	 * Find the index of the webhook whose URL matches ours.
	 *
	 * Jotform returns webhooks as a map of index => url.
	 *
	 * @param Api    $api     Jotform API client.
	 * @param string $form_id Form ID.
	 * @param string $url     Our callback URL.
	 * @return int|string|null Matching index, or null when not found.
	 */
	private static function find_webhook_index( $api, $form_id, $url ) {
		$response = $api->list_webhooks( $form_id );
		if ( empty( $response['success'] ) ) {
			return null;
		}

		$content = $response['data']['content'] ?? array();
		if ( ! is_array( $content ) ) {
			return null;
		}

		foreach ( $content as $index => $webhook_url ) {
			if ( (string) $webhook_url === (string) $url ) {
				return $index;
			}
		}

		return null;
	}
}
