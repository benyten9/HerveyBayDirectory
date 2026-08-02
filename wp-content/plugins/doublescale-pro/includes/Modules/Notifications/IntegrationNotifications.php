<?php
/**
 * Integration Notifications Handler
 * Listens to integration events and creates notifications
 *
 * @since 1.2.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Notifications;

use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;

/**
 * IntegrationNotifications class
 *
 * Handles notification creation for integration-related events:
 * - Integration connected
 * - Integration disconnected
 * - Sync error occurred
 *
 * Integrations should fire these hooks when their status changes.
 *
 * @listens doublescale_integration_connected Fired when integration connects
 * @listens doublescale_integration_disconnected Fired when integration disconnects
 * @listens doublescale_integration_sync_error Fired when sync fails
 *
 * @since 1.2.0
 */
class IntegrationNotifications {

	/**
	 * Constructor - register hooks
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		if ( ! NotificationCategories::is_module_active( NotificationCategories::INTEGRATIONS ) ) {
			return;
		}
		add_action( 'doublescale_integration_connected', array( $this, 'on_integration_connected' ), 10, 2 );
		add_action( 'doublescale_integration_disconnected', array( $this, 'on_integration_disconnected' ), 10, 2 );
		add_action( 'doublescale_integration_sync_error', array( $this, 'on_sync_error' ), 10, 3 );
	}

	/**
	 * Handle integration connected event
	 *
	 * @since 1.2.0
	 *
	 * @param string $integration_slug Integration identifier (e.g., 'twilio', 'slack').
	 * @param string $integration_name Human-readable integration name.
	 */
	public function on_integration_connected( $integration_slug, $integration_name ) {
		NotificationService::broadcast(
			/* translators: %s: integration name */
			sprintf( __( '%s Connected', 'doublescale'), $integration_name ),
			/* translators: %s: integration name */
			sprintf( __( 'The %s integration has been successfully connected.', 'doublescale'), $integration_name ),
			array(
				'web'    => admin_url( 'admin.php?page=doublescale&path=settings&tab=integrations' ),
				'mobile' => null,
			),
			NotificationCategories::INTEGRATIONS_CONNECTED
		);
	}

	/**
	 * Handle integration disconnected event
	 *
	 * @since 1.2.0
	 *
	 * @param string $integration_slug Integration identifier.
	 * @param string $integration_name Human-readable integration name.
	 */
	public function on_integration_disconnected( $integration_slug, $integration_name ) {
		NotificationService::broadcast(
			/* translators: %s: integration name */
			sprintf( __( '%s Disconnected', 'doublescale'), $integration_name ),
			/* translators: %s: integration name */
			sprintf( __( 'The %s integration has been disconnected.', 'doublescale'), $integration_name ),
			array(
				'web'    => admin_url( 'admin.php?page=doublescale&path=settings&tab=integrations' ),
				'mobile' => null,
			),
			NotificationCategories::INTEGRATIONS_DISCONNECTED
		);
	}

	/**
	 * Handle integration sync error event
	 *
	 * @since 1.2.0
	 *
	 * @param string $integration_slug Integration identifier.
	 * @param string $integration_name Human-readable integration name.
	 * @param string $error_message    Error description.
	 */
	public function on_sync_error( $integration_slug, $integration_name, $error_message ) {
		NotificationService::broadcast(
			/* translators: %s: integration name */
			sprintf( __( '%s Sync Error', 'doublescale'), $integration_name ),
			/* translators: 1: integration name, 2: error message */
			sprintf( __( '%1$s sync failed: %2$s', 'doublescale'), $integration_name, $error_message ),
			array(
				'web'    => admin_url( 'admin.php?page=doublescale&path=settings&tab=integrations' ),
				'mobile' => null,
			),
			NotificationCategories::INTEGRATIONS_SYNC_ERROR
		);
	}
}
