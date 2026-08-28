<?php
/**
 * HTML Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Emails\Blocks;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Abstracts\EmailBlock;
use DoubleScale\Pro\Modules\Emails\Support\HtmlBlockCssInliner;

/**
 * HTML block for emails
 */
class HtmlBlock extends EmailBlock {
	/**
	 * Get block type
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'html';
	}

	/**
	 * Get block name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'HTML', 'doublescale');
	}

	/**
	 * Get default properties
	 *
	 * @return array
	 */
	public function get_default_props(): array {
		return array(
			'content'   => '',
			'customCss' => '',
			'blockId'   => '',
			'width'     => '100',
			'padding'   => array(
				'top'    => 0,
				'right'  => 0,
				'bottom' => 0,
				'left'   => 0,
			),
		);
	}

	/**
	 * Render block
	 *
	 * @param array                                       $props Block properties
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string HTML output
	 */
	public function render( array $props, $contact = null ): string {
		// Merge with default props
		$props = wp_parse_args( $props, $this->get_default_props() );

		// Process the HTML content for merge tags
		$content = $this->process_merge_tags( $props['content'], $contact );

		// Container style (matching frontend)
		$container_style = $this->build_style_string(
			array(
				'width'   => $props['width'] . '%',
				'padding' => $this->format_padding( $props['padding'] ),
			)
		);

		// Check if content is empty, default, or just whitespace (matching frontend)
		$is_default_content = empty( $content ) ||
			trim( $content ) === '' ||
			$content === '<p>Insert your HTML here</p>' ||
			trim( $content ) === '<p>Insert your HTML here</p>';

		$block_id = ! empty( $props['blockId'] )
			? sanitize_html_class( $props['blockId'] )
			: 'html-block-' . substr( md5( (string) $content ), 0, 9 );

		// Use CSS string directly (matching frontend)
		$css_string = $props['customCss'] ?? '';

		// If content is empty/default, do not render the block at all
		if ( $is_default_content ) {
			return '';
		}

		// Inline CSS for email clients (strips embedded <style> tags).
		$inlined_content = HtmlBlockCssInliner::process( $content, $css_string, $block_id );

		// Inner content style (matching frontend)
		$inner_style = $this->build_style_string(
			array(
				'width'      => '100%',
				'min-height' => '50px',
				'color'      => '#666',
				'font-size'  => '14px',
			)
		);

		$inner_content = "<div id=\"{$block_id}\" style=\"width: 100%;\">{$inlined_content}</div>";

		// Use table structure for better email client compatibility
		return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">
			<tr>
				<td style=\"{$container_style}\">
					<div style=\"{$inner_style}\">{$inner_content}</div>
				</td>
			</tr>
		</table>";
	}
}

