<?php
/**
 * Abstract Email Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails\Abstracts;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Blocks\EmailBlockInterface;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Email Block abstract class with common methods
 */
abstract class EmailBlock implements EmailBlockInterface {

	/**
	 * Process merge tags in content
	 *
	 * @param string                                   $content Content with merge tags
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string Processed content
	 */
	protected function process_merge_tags( $content, $contact = null ) {
		if ( empty( $content ) || ! $contact ) {
			return $content;
		}

		// Use MergeTagsManager to process merge tags
		return MergeTagsManager::instance()->process_merge_tags( $content, $contact );
	}

	/**
	 * Build CSS style string from array
	 *
	 * @param array $styles Array of CSS properties
	 * @return string CSS style string
	 */
	protected function build_style_string( array $styles ) {
		$style_string = '';

		foreach ( $styles as $property => $value ) {
			if ( $value !== null && $value !== '' ) {
				$style_string .= "{$property}: {$value}; ";
			}
		}

		return rtrim( $style_string );
	}

	/**
	 * Format padding from object to CSS string
	 *
	 * @param array|null $padding Padding object
	 * @return string CSS padding string
	 */
	protected function format_padding( $padding ) {
		if ( ! $padding ) {
			return '0';
		}

		$top    = isset( $padding['top'] ) ? $padding['top'] : 0;
		$right  = isset( $padding['right'] ) ? $padding['right'] : 0;
		$bottom = isset( $padding['bottom'] ) ? $padding['bottom'] : 0;
		$left   = isset( $padding['left'] ) ? $padding['left'] : 0;

		return "{$top}px {$right}px {$bottom}px {$left}px";
	}

	/**
	 * Convert camelCase to kebab-case
	 *
	 * @param string $string camelCase string
	 * @return string kebab-case string
	 */
	protected function convert_camel_to_kebab( $string ) {
		return strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $string ) );
	}
}
