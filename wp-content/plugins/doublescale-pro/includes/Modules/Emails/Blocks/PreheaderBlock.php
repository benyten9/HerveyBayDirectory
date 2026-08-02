<?php
/**
 * Preheader Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Emails\Blocks;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Abstracts\EmailBlock;

/**
 * Preheader block for emails
 */
class PreheaderBlock extends EmailBlock {
	/**
	 * Get block type
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'preheader';
	}

	/**
	 * Get block name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Preheader', 'doublescale');
	}

	/**
	 * Get default properties
	 *
	 * @return array
	 */
	public function get_default_props(): array {
		return array(
			'text'          => 'If you cannot see images, Please',
			'linkText'      => 'Click here',
			'linkUrl'       => 'https://',
			'fontSize'      => 12,
			'textColor'     => '#9197A4',
			'linkColor'     => '#3B82F6',
			'textAlign'     => 'left',
			'fontFamily'    => 'Arial',
			'bold'          => false,
			'italic'        => false,
			'underline'     => true,
			'letterSpacing' => '0px',
			'headingStyle'  => 'p',
			'padding'       => array(
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

		// Process content for merge tags
		$text      = $this->process_merge_tags( $props['text'], $contact );
		$link_text = $this->process_merge_tags( $props['linkText'], $contact );
		$link_url  = $this->process_merge_tags( $props['linkUrl'], $contact );

		// Get font size based on heading style
		// Ensure fontSize is an integer (remove 'px' suffix if present)
		$base_font_size     = is_numeric( $props['fontSize'] ) ? (int) $props['fontSize'] : (int) str_replace( 'px', '', $props['fontSize'] );
		$adjusted_font_size = $this->get_adjusted_font_size( $base_font_size, $props['headingStyle'] );

		// Build text styles
		$text_styles = array(
			'font-size'       => $adjusted_font_size . 'px',
			'color'           => $props['textColor'],
			'font-family'     => $props['fontFamily'],
			'font-weight'     => ! empty( $props['bold'] ) ? 'bold' : 'normal',
			'font-style'      => ! empty( $props['italic'] ) ? 'italic' : 'normal',
			'text-decoration' => 'none',
			'letter-spacing'  => $props['letterSpacing'],
		);

		// Build link styles
		$link_styles = array(
			'font-size'       => $adjusted_font_size . 'px',
			'color'           => $props['linkColor'],
			'font-family'     => $props['fontFamily'],
			'font-weight'     => ! empty( $props['bold'] ) ? 'bold' : 'normal',
			'font-style'      => ! empty( $props['italic'] ) ? 'italic' : 'normal',
			'text-decoration' => ! empty( $props['underline'] ) ? 'underline' : 'none',
			'letter-spacing'  => $props['letterSpacing'],
		);

		// Build container styles (matching frontend)
		$container_styles = array(
			'text-align'     => $props['textAlign'],
			'padding'        => $this->format_padding( $props['padding'] ),
			'font-size'      => $adjusted_font_size . 'px',
			'font-family'    => $props['fontFamily'],
			'font-weight'    => ! empty( $props['bold'] ) ? 'bold' : 'normal',
			'font-style'     => ! empty( $props['italic'] ) ? 'italic' : 'normal',
			'letter-spacing' => $props['letterSpacing'],
			'word-wrap'      => 'break-word',
			'overflow-wrap'  => 'break-word',
			'max-width'      => '100%',
			'white-space'    => 'normal',
			'width'          => '100%',
			'box-sizing'     => 'border-box',
		);

		$text_style_string      = $this->build_style_string( $text_styles );
		$link_style_string      = $this->build_style_string( $link_styles );
		$container_style_string = $this->build_style_string( $container_styles );

		// Get HTML element based on heading style
		$element_tag = $this->get_element_tag( $props['headingStyle'] );

		// Use default text if empty
		$display_text      = ! empty( $text ) ? $text : __( 'If you cannot see images, Please', 'doublescale');
		$display_link_text = ! empty( $link_text ) ? $link_text : __( 'Click here', 'doublescale');

		// Use table structure for better email client compatibility
		return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">
			<tr>
				<td>
					<{$element_tag} style=\"{$container_style_string}\">
						<span style=\"{$text_style_string}\">{$display_text}</span>
						<span style=\"margin-left: 4px; margin-right: 2px;\"> </span>
						<a href=\"{$link_url}\" style=\"{$link_style_string}\" target=\"_blank\" rel=\"noopener noreferrer\">{$display_link_text}</a>
					</{$element_tag}>
				</td>
			</tr>
		</table>";
	}

	/**
	 * Get adjusted font size based on heading style
	 *
	 * @param int    $font_size Base font size
	 * @param string $heading_style Heading style
	 * @return int Adjusted font size
	 */
	private function get_adjusted_font_size( int $font_size, string $heading_style ): int {
		switch ( $heading_style ) {
			case 'h1':
				return max( $font_size * 2.5, 24 );
			case 'h2':
				return max( $font_size * 2, 20 );
			case 'h3':
				return max( $font_size * 1.5, 18 );
			case 'small':
				return max( $font_size * 0.8, 12 );
			default:
				return $font_size;
		}
	}

	/**
	 * Get HTML element tag based on heading style
	 *
	 * @param string $heading_style Heading style
	 * @return string HTML element tag
	 */
	private function get_element_tag( string $heading_style ): string {
		switch ( $heading_style ) {
			case 'h1':
				return 'h1';
			case 'h2':
				return 'h2';
			case 'h3':
				return 'h3';
			case 'p':
				return 'p';
			case 'small':
				return 'small';
			default:
				return 'div';
		}
	}

	/**
	 * Get alignment justify content value
	 *
	 * @param string $align Alignment value
	 * @return string Justify content value
	 */
	private function get_alignment_justify( string $align ): string {
		switch ( $align ) {
			case 'center':
				return 'center';
			case 'right':
				return 'flex-end';
			case 'left':
			default:
				return 'flex-start';
		}
	}
}
