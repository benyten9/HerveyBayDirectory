<?php
/**
 * Banner Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Emails\Blocks;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Abstracts\EmailBlock;

/**
 * Banner block for emails
 */
class BannerBlock extends EmailBlock {
	/**
	 * Get block type
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'banner';
	}

	/**
	 * Get block name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Banner', 'doublescale');
	}

	/**
	 * Get default properties
	 *
	 * @return array
	 */
	public function get_default_props(): array {
		return array(
			'content'         => 'Your text here',
			'fontSize'        => 16,
			'color'           => '#333',
			'align'           => 'center',
			'src'             => '',
			'alt'             => 'Banner',
			'backgroundColor' => '#f3f4f6',
			'padding'         => array(
				'top'    => 20,
				'right'  => 20,
				'bottom' => 20,
				'left'   => 20,
			),
			'link'            => '',
			'borderRadius'    => '9999',
			'shape'           => 'circle',
			'rotation'        => 0,
		);
	}

	/**
	 * Render block
	 *
	 * @param array $props Block properties
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string HTML output
	 */
	public function render( array $props, $contact = null ): string {
		// Merge with default props
		$props = wp_parse_args( $props, $this->get_default_props() );

		// Process content, src, alt, and link for merge tags
		$content = $this->process_merge_tags( $props['content'], $contact );
		$src     = ! empty( $props['src'] ) ? $this->process_merge_tags( $props['src'], $contact ) : '';
		$alt     = ! empty( $props['alt'] ) ? $this->process_merge_tags( $props['alt'], $contact ) : '';
		$link    = ! empty( $props['link'] ) ? $this->process_merge_tags( $props['link'], $contact ) : '';

		// Wrapper style (matches frontend wrapperStyle)
		$wrapper_style = $this->build_style_string(
			array(
				'text-align' => $props['align'],
				'width'      => '100%',
			)
		);

		// Container style (matches frontend containerStyle)
		$container_style = $this->build_style_string(
			array(
				'background-color' => $props['backgroundColor'],
				'padding'          => $this->format_padding( $props['padding'] ),
				'border-radius'    => $props['borderRadius'] . 'px',
				'display'          => 'inline-block',
				'max-width'        => '100%',
			)
		);

		// Image style (matches frontend imageStyle - fixed 100px size)
		$image_style = $this->build_style_string(
			array(
				'width'         => '100px',
				'height'        => '100px',
				'max-width'     => '100%',
				'border-radius' => $props['borderRadius'] . 'px',
				'display'       => 'block',
				'transform'     => $props['rotation'] !== 0 ? 'rotate(' . $props['rotation'] . 'deg)' : 'none',
				'object-fit'    => 'cover',
			)
		);

		// Placeholder style (matches frontend placeholderStyle)
		$placeholder_style = $this->build_style_string(
			array(
				'width'           => '100px',
				'height'          => '100px',
				'max-width'       => '100%',
				'border-radius'   => $props['borderRadius'] . 'px',
				'display'         => 'flex',
				'align-items'     => 'center',
				'justify-content' => 'center',
				'color'           => '#6B7280',
				'font-size'       => '14px',
				'font-weight'     => '500',
				'margin'          => '0 auto',
			)
		);

		// Start wrapper div
		$output  = "<div style=\"{$wrapper_style}\">";
		$output .= "<div style=\"{$container_style}\">";

		// Render banner element
		if ( ! empty( $src ) ) {
			// Render image
			$banner_element = "<img src=\"{$src}\" alt=\"{$alt}\" style=\"{$image_style}\" />";
		} else {
			// Render placeholder (simplified for email - no icon, just text)
			$banner_element = "<div style=\"{$placeholder_style}\">📷</div>";
		}

		// Wrap in link if provided
		if ( $link ) {
			$banner_element = "<a href=\"{$link}\" target=\"_blank\" rel=\"noopener noreferrer\" style=\"text-decoration:none;\">{$banner_element}</a>";
		}

		$output .= $banner_element;
		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}
}

