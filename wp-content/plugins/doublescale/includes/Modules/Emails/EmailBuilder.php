<?php
/**
 * Email Builder
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Main Email Builder class
 */
class EmailBuilder {
	/**
	 * Instance
	 *
	 * @var EmailBuilder
	 */
	private static $instance;

	/**
	 * Get Instance
	 *
	 * @return EmailBuilder
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->includes();
	}

	/**
	 * Include required files
	 */
	private function includes() {
		$base       = DOUBLESCALE_PLUGIN_DIR;
		$emails_dir = $base . 'includes/Modules/Emails/';

		// Include block system.
		require_once $emails_dir . 'Abstracts/EmailBlock.php';
		require_once $emails_dir . 'BlockRegistry.php';

		// Include layout handlers.
		require_once $emails_dir . 'Layouts/LayoutHandlerInterface.php';
		require_once $emails_dir . 'Layouts/AbstractLayoutHandler.php';
		require_once $emails_dir . 'Layouts/AbstractGridLayoutHandler.php';
		require_once $emails_dir . 'Layouts/SideBySideLayoutHandler.php';
		require_once $emails_dir . 'Layouts/GridLayoutHandler.php';
		require_once $emails_dir . 'Layouts/GenericInlineLayoutHandler.php';
		require_once $emails_dir . 'Layouts/LayoutHandlerRegistry.php';

		// Include free block implementations.
		require_once $emails_dir . 'Blocks/TextBlock.php';
		require_once $emails_dir . 'Blocks/ButtonBlock.php';
		// Pro blocks are loaded by the Pro plugin via the `doublescale_mail_block_register` action.

		// Include renderer.
		require_once $emails_dir . 'EmailRenderer.php';
	}
}
