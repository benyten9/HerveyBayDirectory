<?php
/**
 * Import Notifications Handler
 * Listens to import events and creates notifications
 *
 * @since 1.2.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications;

use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;

/**
 * ImportNotifications class
 *
 * Handles notification creation for import-related events.
 *
 * @since 1.2.0
 */
class ImportNotifications {

	/**
	 * Constructor - register hooks
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		add_action( 'doublescale_import_complete', array( $this, 'on_import_completed' ), 10, 4 );
	}

	/**
	 * Handle import completed event
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id        User who initiated the import.
	 * @param int $imported_count Number of contacts imported.
	 * @param int $error_count    Number of errors.
	 * @param int $total          Total contacts processed.
	 */
	public function on_import_completed( $user_id, $imported_count, $error_count, $total ) {
		if ( ! $user_id ) {
			return;
		}

		// Build message based on results.
		if ( $error_count > 0 ) {
			$message = sprintf(
				/* translators: 1: number imported, 2: number of errors */
				__( 'Successfully imported %1$d contacts with %2$d errors.', 'doublescale' ),
				$imported_count,
				$error_count
			);
		} else {
			$message = sprintf(
				/* translators: %d: number of contacts imported */
				__( 'Successfully imported %d contacts.', 'doublescale' ),
				$imported_count
			);
		}

		NotificationService::create(
			$user_id,
			__( 'Import Completed', 'doublescale' ),
			$message,
			array(
				'web'    => admin_url( 'admin.php?page=doublescale&path=contacts' ),
				'mobile' => '/contacts',
			),
			NotificationCategories::CONTACTS_IMPORT
		);
	}
}
