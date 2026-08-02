<?php
/**
 * Text Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails\Blocks;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Abstracts\EmailBlock;

/**
 * Text block for emails
 */
class TextBlock extends EmailBlock {
	/**
	 * Get block type
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'text';
	}

	/**
	 * Get block name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Text', 'doublescale' );
	}

	/**
	 * Get default properties
	 *
	 * @return array
	 */
	public function get_default_props(): array {
		return array(
			'content'         => '<p>Your text here</p>',
			'hyperlink'       => 'https://',
			'fontSize'        => 16,
			'color'           => '#333',
			'align'           => 'center',
			'fontFamily'      => 'Arial',
			'bold'            => false,
			'italic'          => false,
			'underline'       => false,
			'line-through'    => false,
			'lineHeight'      => '1.5',
			'letterSpacing'   => '0px',
			'borderRadius'    => '0px',
			'borderWidth'     => '0px',
			'linkColor'       => '#333',
			'backgroundColor' => 'transparent',
			'textAlign'       => 'left',
			'listType'        => 'none',
			'headingStyle'    => 'p',
			'padding'         => array(
				'top'    => 4,
				'right'  => 8,
				'bottom' => 4,
				'left'   => 8,
			),
		);
	}

	/**
	 * Render block
	 *
	 * @param array                                    $props Block properties
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string HTML output
	 */
	public function render( array $props, $contact = null ): string {
		// Merge with default props
		$props = wp_parse_args( $props, $this->get_default_props() );

		// Process content for merge tags
		$content = $this->process_merge_tags( $props['content'], $contact );

		// Get font size based on heading style (matches frontend getFontSize)
		// Ensure fontSize is an integer (remove 'px' suffix if present)
		$base_font_size = is_numeric( $props['fontSize'] ) ? (int) $props['fontSize'] : (int) str_replace( 'px', '', $props['fontSize'] );
		$font_size      = $this->get_adjusted_font_size( $base_font_size, $props['headingStyle'] );

		// Check if content has HTML formatting (matches frontend hasHtmlFormatting)
		$has_html_formatting = $this->has_html_formatting( $content );

		// Build styles (matches frontend div styles)
		$styles = array(
			'font-size'        => $font_size . 'px',
			'color'            => $props['color'],
			'text-align'       => $props['textAlign'],
			'font-family'      => $props['fontFamily'],
			'line-height'      => $props['lineHeight'],
			'letter-spacing'   => $props['letterSpacing'],
			'border-radius'    => $props['borderRadius'],
			'border-width'     => $props['borderWidth'],
			'background-color' => $props['backgroundColor'],
			'padding'          => $props['padding']['top'] . 'px ' . $props['padding']['right'] . 'px ' . $props['padding']['bottom'] . 'px ' . $props['padding']['left'] . 'px',
			'margin'           => '0',
			'word-wrap'        => 'break-word',
			'overflow-wrap'    => 'break-word',
			'max-width'        => '100%',
			'white-space'      => 'normal',
			'width'            => '100%',
			'box-sizing'       => 'border-box',
			'overflow'         => 'hidden',
		);

		// Only apply formatting styles if no HTML formatting exists (matches frontend logic)
		if ( ! $has_html_formatting ) {
			if ( ! empty( $props['bold'] ) ) {
				$styles['font-weight'] = 'bold';
			} else {
				$styles['font-weight'] = 'normal';
			}

			if ( ! empty( $props['italic'] ) ) {
				$styles['font-style'] = 'italic';
			} else {
				$styles['font-style'] = 'normal';
			}

			// Handle text decoration
			$text_decoration = array();
			if ( ! empty( $props['underline'] ) ) {
				$text_decoration[] = 'underline';
			}
			if ( ! empty( $props['line-through'] ) ) {
				$text_decoration[] = 'line-through';
			}

			if ( ! empty( $text_decoration ) ) {
				$styles['text-decoration'] = implode( ' ', $text_decoration );
			} else {
				$styles['text-decoration'] = 'none';
			}
		}

		$style_string = $this->build_style_string( $styles );

		// Get element type based on heading style (matches frontend getElementType)
		$element_type = $this->get_element_type( $props['headingStyle'] );

		// Inject inherited styles into block-level tags in content so email clients
		// that don't inherit from the wrapper <div> still render correctly.
		$content = $this->inject_inherited_styles(
			$content,
			array(
				'font-size'   => $font_size . 'px',
				'font-family' => $props['fontFamily'],
				'line-height' => $props['lineHeight'],
				'color'       => $props['color'],
			)
		);

		return "<div style=\"{$style_string}\">{$content}</div>";
	}

	/**
	 * Inject inherited styles into block-level elements inside the content HTML.
	 *
	 * Email clients (especially Outlook and Gmail) don't reliably inherit
	 * font-size, font-family, line-height, or color from a parent <div>.
	 * Heading tags (<h1>-<h6>) also carry their own user-agent font-size
	 * and margin which must be overridden explicitly.
	 *
	 * For every <p>, <div>, and <h1>-<h6> tag found in content:
	 *   - 'color', 'font-size', 'font-family' always win over any existing inline
	 *     declaration on that tag (matches the frontend's unconditional `!important`
	 *     CSS rule forcing the block's color/size/family onto these structural
	 *     tags — see TextRenderer's `.rendererId p, div, span/h1-h6` rules — so a
	 *     stray inline color/size baked into saved content, e.g. from a paste or an
	 *     older save, can't make the sent email diverge from what the builder showed).
	 *   - Other styles (e.g. line-height) are injected only when the tag doesn't
	 *     already have them.
	 *   - Always injects margin:0 when missing.
	 *
	 * @param string $content HTML content
	 * @param array  $styles  Key-value pairs to inject (e.g. 'font-size' => '60px').
	 * @return string Processed content
	 */
	private function inject_inherited_styles( string $content, array $styles ): string {
		$escaped = array();
		foreach ( $styles as $prop => $val ) {
			$escaped[ $prop ] = esc_attr( $val );
		}

		$force_props = array( 'color', 'font-size', 'font-family' );

		return preg_replace_callback(
			'/<(p|div|h[1-6])((?:\s+[^>]*)?)>/i',
			function ( $matches ) use ( $escaped, $force_props ) {
				$tag   = $matches[1];
				$attrs = $matches[2];

				$inject = array();
				foreach ( $escaped as $prop => $val ) {
					$has_prop = (bool) preg_match( '/style\s*=\s*("[^"]*|\'[^\']*)' . preg_quote( $prop, '/' ) . '/i', $attrs );
					if ( ! $has_prop ) {
						$inject[] = $prop . ': ' . $val;
					} elseif ( in_array( $prop, $force_props, true ) ) {
						$attrs    = preg_replace( '/' . preg_quote( $prop, '/' ) . '\s*:\s*[^;"\']+;?/i', '', $attrs );
						$inject[] = $prop . ': ' . $val;
					}
				}
				if ( ! preg_match( '/style\s*=\s*("[^"]*|\'[^\']*)margin/i', $attrs ) ) {
					$inject[] = 'margin: 0';
				}

				if ( empty( $inject ) ) {
					return $matches[0];
				}

				$inject_str = implode( '; ', $inject );

				if ( preg_match( '/style\s*=\s*"/i', $attrs ) ) {
					$attrs = preg_replace( '/style\s*=\s*"/i', 'style="' . $inject_str . '; ', $attrs );
					return '<' . $tag . $attrs . '>';
				}

				if ( preg_match( "/style\s*=\s*'/i", $attrs ) ) {
					$attrs = preg_replace( "/style\s*=\s*'/i", "style='" . $inject_str . '; ', $attrs );
					return '<' . $tag . $attrs . '>';
				}

				return '<' . $tag . $attrs . ' style="' . $inject_str . ';">';
			},
			$content
		);
	}

	/**
	 * Get adjusted font size based on heading style
	 * NOTE: Frontend handles font size multiplication in the editor UI,
	 * but the actual fontSize value saved is already the final size.
	 * Backend should use the fontSize value directly without multiplication.
	 *
	 * @param int    $font_size Base font size (already adjusted in frontend)
	 * @param string $heading_style Heading style
	 * @return int Font size to use
	 */
	private function get_adjusted_font_size( int $font_size, string $heading_style ): int {
		// The fontSize prop already contains the correct size from the frontend
		// No need to multiply again - just use it directly
		return $font_size;
	}

	/**
	 * Get element type based on heading style (matches frontend getElementType)
	 *
	 * @param string $heading_style Heading style
	 * @return string HTML element type
	 */
	private function get_element_type( string $heading_style ): string {
		switch ( $heading_style ) {
			case 'h1':
			case 'h2':
			case 'h3':
				return $heading_style;
			case 'small':
				return 'small';
			default:
				return 'p';
		}
	}

	/**
	 * Check if content has HTML formatting (matches frontend hasHtmlFormatting)
	 *
	 * @param string $content Content to check
	 * @return bool Whether content has HTML formatting
	 */
	private function has_html_formatting( string $content ): bool {
		return ! empty( $content ) && strpos( $content, '<' ) !== false && strpos( $content, '>' ) !== false;
	}

	/**
	 * Format padding with multipliers (matches frontend)
	 *
	 * @param array|null $padding Padding object
	 * @return string CSS padding string
	 */
	private function format_padding_multiplied( $padding ) {
		if ( ! $padding ) {
			return '0';
		}

		$top    = isset( $padding['top'] ) ? $padding['top'] * 2 : 0;
		$right  = isset( $padding['right'] ) ? $padding['right'] * 4 : 0;
		$bottom = isset( $padding['bottom'] ) ? $padding['bottom'] * 2 : 0;
		$left   = isset( $padding['left'] ) ? $padding['left'] * 4 : 0;

		return "{$top}px {$right}px {$bottom}px {$left}px";
	}
}
