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
			'textDirection'   => 'ltr',
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
		$content = $this->rewrite_html_image_srcs( $content );

		// Per-word color/size is not a text-block control — drop it so Font Color
		// and font size apply to the whole block (matches the builder).
		$content = $this->strip_inline_color_and_size( $content );
		$content = $this->strip_list_inline_text_align( $content );

		// Get font size based on heading style (matches frontend getFontSize)
		// Ensure fontSize is an integer (remove 'px' suffix if present)
		$base_font_size = is_numeric( $props['fontSize'] ) ? (int) $props['fontSize'] : (int) str_replace( 'px', '', $props['fontSize'] );
		$font_size      = $this->get_adjusted_font_size( $base_font_size, $props['headingStyle'] );

		// Check if content has HTML formatting (matches frontend hasHtmlFormatting)
		$has_html_formatting = $this->has_html_formatting( $content );

		// Build styles (matches frontend div styles)
		$text_direction = ( isset( $props['textDirection'] ) && 'rtl' === $props['textDirection'] ) ? 'rtl' : 'ltr';

		$styles = array(
			'font-size'        => $font_size . 'px',
			'color'            => $props['color'],
			'text-align'       => $props['textAlign'],
			'direction'        => $text_direction,
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
		$content = $this->inject_list_alignment_styles( $content, (string) $props['textAlign'], $text_direction );
		$content = $this->style_text_links( $content, (string) $props['color'] );

		return "<div dir=\"" . esc_attr( $text_direction ) . "\" style=\"{$style_string}\">{$content}</div>";
	}

	/**
	 * Remove per-word color and font-size from inline styles.
	 *
	 * The block Font Color / fontSize props are the single source of truth.
	 * Other styles (text-align, weight, decoration, coupon badge layout) stay.
	 *
	 * @param string $content HTML content
	 * @return string
	 */
	private function strip_inline_color_and_size( string $content ): string {
		$content = preg_replace_callback(
			'/style\s*=\s*(["\'])(.*?)\1/i',
			static function ( $matches ) {
				$quote = $matches[1];
				$decls = preg_split( '/;/', $matches[2] );
				$kept  = array();
				foreach ( $decls as $decl ) {
					$decl = trim( $decl );
					if ( '' === $decl ) {
						continue;
					}
					if ( preg_match( '/^(color|font-size|-webkit-text-fill-color)\s*:/i', $decl ) ) {
						continue;
					}
					$kept[] = $decl;
				}
				if ( empty( $kept ) ) {
					return '';
				}
				return 'style=' . $quote . implode( '; ', $kept ) . $quote;
			},
			$content
		);

		if ( ! is_string( $content ) ) {
			return '';
		}

		$unwrapped = preg_replace( '/<span\s*>(.*?)<\/span>/is', '$1', $content );
		return is_string( $unwrapped ) ? $unwrapped : $content;
	}

	/**
	 * Remove inline text-align from lists so they inherit the block alignment.
	 *
	 * Browsers often persist `text-align:left` on `<ol>`/`<ul>` after list
	 * commands; Gmail then ignores it and uses the wrapper, so the builder and
	 * the sent email disagree.
	 *
	 * @param string $content HTML content
	 * @return string
	 */
	private function strip_list_inline_text_align( string $content ): string {
		if ( false === stripos( $content, '<ol' ) && false === stripos( $content, '<ul' ) && false === stripos( $content, '<li' ) ) {
			return $content;
		}

		$replaced = preg_replace_callback(
			'/<(ol|ul|li)(\s[^>]*)?>/i',
			static function ( $matches ) {
				$tag   = $matches[1];
				$attrs = $matches[2] ?? '';
				if ( '' === $attrs || false === stripos( $attrs, 'text-align' ) ) {
					return $matches[0];
				}

				$attrs = preg_replace_callback(
					'/style\s*=\s*(["\'])(.*?)\1/i',
					static function ( $style_matches ) {
						$quote = $style_matches[1];
						$decls = preg_split( '/;/', $style_matches[2] );
						$kept  = array();
						foreach ( $decls as $decl ) {
							$decl = trim( $decl );
							if ( '' === $decl ) {
								continue;
							}
							if ( preg_match( '/^text-align\s*:/i', $decl ) ) {
								continue;
							}
							$kept[] = $decl;
						}
						if ( empty( $kept ) ) {
							return '';
						}
						return 'style=' . $quote . implode( '; ', $kept ) . $quote;
					},
					$attrs
				);

				return '<' . $tag . $attrs . '>';
			},
			$content
		);

		return is_string( $replaced ) ? $replaced : $content;
	}

	/**
	 * Apply block alignment to lists so email clients match the canvas.
	 *
	 * @param string $content         HTML content
	 * @param string $text_align      Block text-align
	 * @param string $text_direction  ltr|rtl
	 * @return string
	 */
	private function inject_list_alignment_styles( string $content, string $text_align, string $text_direction ): string {
		if ( false === stripos( $content, '<ol' ) && false === stripos( $content, '<ul' ) ) {
			return $content;
		}

		$align  = in_array( $text_align, array( 'left', 'center', 'right', 'justify' ), true ) ? $text_align : 'left';
		$inside = in_array( $align, array( 'center', 'right', 'justify' ), true );
		$pad    = ( 'rtl' === $text_direction ) ? 'padding-right' : 'padding-left';

		$replaced = preg_replace_callback(
			'/<(ol|ul)((?:\s+[^>]*)?)>/i',
			function ( $matches ) use ( $align, $inside, $pad ) {
				$tag   = $matches[1];
				$attrs = $matches[2];
				$extra = array(
					'text-align: ' . esc_attr( $align ),
					'list-style-position: ' . ( $inside ? 'inside' : 'outside' ),
					$pad . ': 20px',
				);

				foreach ( array( 'text-align', 'list-style-position', $pad ) as $prop ) {
					$attrs = preg_replace( '/' . preg_quote( $prop, '/' ) . '\s*:\s*[^;"\']+;?/i', '', $attrs );
				}

				$inject_str = implode( '; ', $extra );
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

		return is_string( $replaced ) ? $replaced : $content;
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
		$block_tags  = array( 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

		$replaced = preg_replace_callback(
			'/<(p|div|h[1-6]|span|strong|b|em|i|u|s|strike|a|font|ol|ul|li)((?:\s+[^>]*)?)>/i',
			function ( $matches ) use ( $escaped, $force_props, $block_tags ) {
				$tag   = $matches[1];
				$attrs = $matches[2];

				// Theme → Links owns <a> styling. Forcing the text-block color/
				// size onto anchors here made Send test email look like body
				// copy instead of the Link Style sitting in the sidebar.
				if ( 0 === strcasecmp( $tag, 'a' ) ) {
					return $matches[0];
				}

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
				if ( in_array( strtolower( $tag ), $block_tags, true ) && ! preg_match( '/style\s*=\s*("[^"]*|\'[^\']*)margin/i', $attrs ) ) {
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

		return is_string( $replaced ) ? $replaced : $content;
	}

	/**
	 * Text-block links: Theme → Links styles (font, color, size, spacing,
	 * decoration). Gmail recolors bare `<a>` tags, so an inner span /
	 * `<font color>` keeps the theme color. A `<u>` wrapper is not used —
	 * it stacked a second underline on top of text-decoration.
	 *
	 * @param string $content       HTML content
	 * @param string $fallback_color Block font color when theme color is empty
	 * @return string
	 */
	private function style_text_links( string $content, string $fallback_color ): string {
		if ( false === stripos( $content, '<a' ) ) {
			return $content;
		}

		$settings    = $this->get_theme_link_settings();
		$color       = esc_attr( ! empty( $settings['color'] ) ? (string) $settings['color'] : $fallback_color );
		$link_style  = $this->build_theme_link_style( $settings, $color );
		$outer_style = $this->build_theme_link_style( $settings, $color, 'none' );

		$replaced = preg_replace_callback(
			'/<a\b([^>]*)>(.*?)<\/a>/is',
			static function ( $matches ) use ( $color, $link_style, $outer_style, $settings ) {
				$attrs = $matches[1];
				$inner = $matches[2];

				$inner = preg_replace_callback(
					'/style\s*=\s*(["\'])(.*?)\1/i',
					static function ( $style_matches ) {
						$quote = $style_matches[1];
						$decls = preg_split( '/;/', $style_matches[2] );
						$kept  = array();
						foreach ( $decls as $decl ) {
							$decl = trim( $decl );
							if ( '' === $decl ) {
								continue;
							}
							if ( preg_match( '/^(color|text-decoration|-webkit-text-fill-color|font-family|font-size|letter-spacing|font-weight|font-style)\s*:/i', $decl ) ) {
								continue;
							}
							$kept[] = $decl;
						}
						if ( empty( $kept ) ) {
							return '';
						}
						return 'style=' . $quote . implode( '; ', $kept ) . $quote;
					},
					$inner
				);
				if ( ! is_string( $inner ) ) {
					$inner = $matches[2];
				}
				$link_html = $inner;

				$attrs = preg_replace_callback(
					'/style\s*=\s*(["\'])(.*?)\1/i',
					static function ( $style_matches ) {
						$quote = $style_matches[1];
						$decls = preg_split( '/;/', $style_matches[2] );
						$kept  = array();
						foreach ( $decls as $decl ) {
							$decl = trim( $decl );
							if ( '' === $decl ) {
								continue;
							}
							if ( preg_match( '/^(color|text-decoration|-webkit-text-fill-color|font-family|font-size|letter-spacing|font-weight|font-style)\s*:/i', $decl ) ) {
								continue;
							}
							$kept[] = $decl;
						}
						if ( empty( $kept ) ) {
							return '';
						}
						return 'style=' . $quote . implode( '; ', $kept ) . $quote;
					},
					$attrs
				);

				if ( preg_match( '/style\s*=\s*"/i', $attrs ) ) {
					$attrs = preg_replace( '/style\s*=\s*"/i', 'style="' . $outer_style . '; ', $attrs );
				} elseif ( preg_match( "/style\s*=\s*'/i", $attrs ) ) {
					$attrs = preg_replace( "/style\s*=\s*'/i", "style='" . $outer_style . '; ', $attrs );
				} else {
					$attrs .= ' style="' . $outer_style . ';"';
				}

				$already_wrapped = (bool) preg_match( '/<font\b[^>]*\bcolor=/i', $inner );
				if ( ! $already_wrapped ) {
					// Underline only on the span. <a> + <font> also having
					// text-decoration draws a second line in Gmail.
					$inner  = '<span class="ds-text-link" style="' . $link_style . ';">';
					$inner .= '<font color="' . $color . '" face="' . esc_attr( (string) ( $settings['font'] ?? 'Arial, sans-serif' ) ) . '" style="' . $outer_style . ';">' . $link_html . '</font>';
					$inner .= '</span>';
				}

				return '<a' . $attrs . '>' . $inner . '</a>';
			},
			$content
		);

		return is_string( $replaced ) ? $replaced : $content;
	}

	/**
	 * Theme link settings from the current email renderer.
	 *
	 * @return array
	 */
	private function get_theme_link_settings(): array {
		$defaults = array(
			'font'          => 'Arial, sans-serif',
			'size'          => 16,
			'letterSpacing' => '0px',
			'color'         => '#458DC7',
			'bold'          => false,
			'italic'        => false,
			'underline'     => true,
			'strikethrough' => false,
		);

		global $doublescale_email_renderer;
		if ( isset( $doublescale_email_renderer ) && method_exists( $doublescale_email_renderer, 'get_link_settings' ) ) {
			$saved = $doublescale_email_renderer->get_link_settings();
			if ( is_array( $saved ) && ! empty( $saved ) ) {
				return wp_parse_args( $saved, $defaults );
			}
		}

		return $defaults;
	}

	/**
	 * @param array  $settings Theme link settings
	 * @return string
	 */
	private function get_theme_link_decoration( array $settings ): string {
		$parts = array();
		if ( ! empty( $settings['underline'] ) ) {
			$parts[] = 'underline';
		}
		if ( ! empty( $settings['strikethrough'] ) ) {
			$parts[] = 'line-through';
		}
		return ! empty( $parts ) ? implode( ' ', $parts ) : 'none';
	}

	/**
	 * @param array  $settings Theme link settings
	 * @param string $color    Escaped color
	 * @return string
	 */
	private function build_theme_link_style( array $settings, string $color, ?string $decoration = null ): string {
		if ( null === $decoration ) {
			$decoration = $this->get_theme_link_decoration( $settings );
		}
		return sprintf(
			'font-family: %s; font-size: %spx; letter-spacing: %s; color: %s; font-weight: %s; font-style: %s; text-decoration: %s',
			esc_attr( (string) ( $settings['font'] ?? 'Arial, sans-serif' ) ),
			(int) ( $settings['size'] ?? 16 ),
			esc_attr( (string) ( $settings['letterSpacing'] ?? '0px' ) ),
			$color,
			! empty( $settings['bold'] ) ? 'bold' : 'normal',
			! empty( $settings['italic'] ) ? 'italic' : 'normal',
			$decoration
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
