<?php
/**
 * Image Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Emails\Blocks;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Abstracts\EmailBlock;

/**
 * Image block for emails
 */
class ImageBlock extends EmailBlock {
	/**
	 * Get block type
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'image';
	}

	/**
	 * Get block name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Image', 'doublescale');
	}

	/**
	 * Get default properties
	 *
	 * @return array
	 */
	public function get_default_props(): array {
		return array(
			'src'             => '',
			'alt'             => 'Image',
			'width'           => '100%',
			'height'          => 'auto',
			'maxWidth'        => '100%',
			'align'           => 'center',
			'backgroundColor' => 'transparent',
			'padding'         => array(
				'top'    => 0,
				'right'  => 0,
				'bottom' => 0,
				'left'   => 0,
			),
			'link'            => '',
			'borderRadius'    => '0',
			'shape'           => 'rectangle',
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

		// Process src, alt, and link for merge tags
		$src  = ! empty( $props['src'] ) ? $this->process_merge_tags( $props['src'], $contact ) : '';
		$alt  = ! empty( $props['alt'] ) ? $this->process_merge_tags( $props['alt'], $contact ) : '';
		$link = ! empty( $props['link'] ) ? $this->process_merge_tags( $props['link'], $contact ) : '';

		// Wrapper style (matches frontend wrapperStyle)
		$wrapper_style = $this->build_style_string(
			array(
				'text-align' => $props['align'],
				'width'      => '100%',
				'margin'     => '0',
			)
		);

		// Container style (matches frontend containerStyle)
		$is_full_width = $props['width'] === '100%' || ( strpos( $props['width'], '%' ) !== false && floatval( $props['width'] ) >= 100 );
		$should_center = $props['align'] === 'center';

		// A px width is an explicit, absolute request, but `max-width: 100%`
		// resolves against the parent column and silently clamps it back down.
		// `100%` is also the default maxWidth every block ships with, so it
		// cannot be read as a deliberate cap — drop it for pixel widths and
		// honour only a narrower, explicitly chosen max-width.
		$is_auto_width  = $props['width'] === 'auto';
		$is_pixel_width = substr( $props['width'], -2 ) === 'px';

		$container_max_width = ! empty( $props['maxWidth'] ) ? $props['maxWidth'] : '100%';
		if ( $is_pixel_width && $container_max_width === '100%' ) {
			$container_max_width = null;
		}

		$container_style = $this->build_style_string(
			array(
				'background-color' => $props['backgroundColor'],
				'padding'          => $this->format_padding( $props['padding'] ),
				'border-radius'    => $props['borderRadius'] . 'px',
				'display'          => ( $is_full_width && ! $should_center ) ? 'block' : 'inline-block',
				'max-width'        => $container_max_width,
				'width'            => $props['width'],
				// Padding must not eat into an explicit width, or a 320px image
				// with horizontal padding renders narrower than requested.
				'box-sizing'       => 'border-box',
				'margin'           => '0',
			)
		);

		// Image style (matches frontend imageStyle)
		$image_style = $this->build_style_string(
			array(
				// The image fills its container, except at width:auto where the
				// container shrink-wraps — filling it would collapse the image.
				'width'         => $is_auto_width ? 'auto' : '100%',
				'height'        => $props['height'] === 'auto' ? 'auto' : $props['height'],
				'max-width'     => '100%',
				'border-radius' => $props['borderRadius'] . 'px',
				'display'       => 'block',
				'border'        => '0',
				'outline'       => 'none',
				'margin'        => '0',
				'padding'       => '0',
			)
		);

		// Placeholder wrapper style
		$placeholder_height        = $props['height'] === 'auto' ? '200px' : $props['height'];
		$placeholder_wrapper_style = $this->build_style_string(
			array(
				'width'            => '100%',
				'height'           => $placeholder_height,
				'border-radius'    => $props['borderRadius'] . 'px',
				'background-color' => '#F5F5F580',
				'display'          => 'block',
				'margin'           => '0',
				'padding'          => '0',
				'overflow'         => 'hidden',
				'box-sizing'       => 'border-box',
			)
		);

		// Placeholder table for positioning (email-safe)
		$placeholder_table_style = $this->build_style_string(
			array(
				'width'           => '100%',
				'height'          => '100%',
				'border-collapse' => 'collapse',
				'margin'          => '0',
				'padding'         => '0',
			)
		);

		$placeholder_cell_style = $this->build_style_string(
			array(
				'text-align'     => 'center',
				'vertical-align' => 'middle',
				'color'          => '#6B7280',
				'font-size'      => '24px',
				'line-height'    => '1',
				'margin'         => '0',
				'padding'        => '0',
			)
		);

		// Simple div structure - already wrapped in table cell by renderer
		$output  = "<div style=\"{$wrapper_style}\">";
		$output .= "<div style=\"{$container_style}\">";

		// Build the image or placeholder
		if ( ! empty( $src ) ) {
			// Outlook's Word engine ignores CSS width on <img>, so mirror an
			// explicit px width onto the HTML width attribute (unitless, as the
			// attribute requires) to keep the size intact there.
			$width_attr = '';
			if ( $is_pixel_width ) {
				$width_attr = ' width="' . esc_attr( (string) (int) floatval( $props['width'] ) ) . '"';
			}

			// Render image with proper URL escaping
			$image = '<img src="' . $this->escape_image_src( $src ) . '" alt="' . esc_attr( $alt ) . '"' . $width_attr . ' style="' . $image_style . '" border="0" />';

			// Wrap in link if provided
			if ( $link ) {
				$output .= '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer" style="text-decoration:none;display:block;">' . $image . '</a>';
			} else {
				$output .= $image;
			}
		} else {
			// Render placeholder with table-based centering for email compatibility
			$output .= "<div style=\"{$placeholder_wrapper_style}\">";
			$output .= "<table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"{$placeholder_table_style}\">";
			$output .= "<tr><td style=\"{$placeholder_cell_style}\">📷</td></tr>";
			$output .= '</table>';
			$output .= '</div>';
		}

		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}
}



