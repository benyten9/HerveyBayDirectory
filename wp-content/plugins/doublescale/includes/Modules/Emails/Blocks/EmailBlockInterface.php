<?php
// includes/emails/blocks/interface-email-block.php

namespace DoubleScale\Modules\Emails\Blocks;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Interface for all email blocks
 */
interface EmailBlockInterface {
	/**
	 * Get block type identifier
	 *
	 * @return string
	 */
	public function get_type(): string;

	/**
	 * Get block name
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Render block for email
	 *
	 * @param array                                    $props Block properties
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string HTML output
	 */
	public function render( array $props, $contact = null ): string;

	/**
	 * Get default properties for the block
	 *
	 * @return array
	 */
	public function get_default_props(): array;
}
