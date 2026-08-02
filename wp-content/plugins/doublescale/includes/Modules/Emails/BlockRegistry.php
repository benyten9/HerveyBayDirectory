<?php
/**
 * Block Registry
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Blocks\EmailBlockInterface;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Registry for email blocks
 */
class BlockRegistry {
	/**
	 * Registered blocks
	 *
	 * @var EmailBlockInterface[]
	 */
	private $blocks = array();

	/**
	 * Instances
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	private static $instances = array();

	/**
	 * Get Instance
	 *
	 * @since 1.0.0
	 *
	 * @return BlockRegistry
	 */
	public static function instance() {
		$class = get_called_class();
		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();
		}
		return self::$instances[ $class ];
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->register_default_blocks();
	}

	/**
	 * Register a block
	 *
	 * @param EmailBlockInterface $block Block instance
	 * @return BlockRegistry
	 */
	public function register_block( EmailBlockInterface $block ) {
		$this->blocks[ $block->get_type() ] = $block;
		return $this;
	}

	/**
	 * Get a block by type
	 *
	 * @param string $type Block type
	 * @return EmailBlockInterface|null
	 */
	public function get_block( $type ) {
		return isset( $this->blocks[ $type ] ) ? $this->blocks[ $type ] : null;
	}

	/**
	 * Get all registered blocks
	 *
	 * @return EmailBlockInterface[]
	 */
	public function get_blocks() {
		return $this->blocks;
	}

	/**
	 * Render a block
	 *
	 * @param string                                   $type Block type
	 * @param array                                    $props Block properties
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string HTML output
	 */
	public function render_block( $type, array $props, $contact = null ) {
		$block = $this->get_block( $type );

		if ( ! $block ) {
			// Return empty string to hide unsupported blocks (pro blocks when pro not active)
			return '';
		}

		try {
			return (string) $block->render( $props, $contact );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'DoubleScale: skipped email block render (type: %s): %s',
						is_scalar( $type ) ? (string) $type : '',
						$e->getMessage()
					)
				);
			}

			return '';
		}
	}

	/**
	 * Register the two Free blocks. Pro extends this set via the
	 * `doublescale_mail_block_register` action below.
	 */
	protected function register_default_blocks() {
		$this->register_block( new \DoubleScale\Modules\Emails\Blocks\TextBlock() );
		$this->register_block( new \DoubleScale\Modules\Emails\Blocks\ButtonBlock() );

		/**
		 * Pro registers its 12 blocks here (Banner, Divider, Html, Image, Menu,
		 * Preheader, Product, SocialMedia, Table, Timer, Video).
		 * Unregistered types fall back to an empty string in render_block(), so
		 * email templates that reference Pro blocks while Pro is inactive simply
		 * skip those blocks rather than fataling.
		 *
		 * @param BlockRegistry $this Registry instance
		 */
		do_action( 'doublescale_mail_block_register', $this );
	}
}
