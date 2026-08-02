<?php
/**
 * Product Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Emails\Blocks;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Abstracts\EmailBlock;

/**
 * Product block for emails
 */
class ProductBlock extends EmailBlock {
	/**
	 * Get block type
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'product';
	}

	/**
	 * Get block name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Product', 'doublescale');
	}

	/**
	 * Get default properties
	 *
	 * @return array
	 */
	public function get_default_props(): array {
		return array(
			'imageSrc'             => '',
			'imageAlt'             => 'Product Image',
			'width'                => '100%',
			'title'                => 'Product Title',
			'description'          => 'Product description goes here',
			'price'                => '99.99 EGP',
			'buttonText'           => 'Shop Now',
			'buttonLink'           => '#',
			'buttonStyle'          => 'primary',
			'padding'              => array(
				'top'    => 16,
				'right'  => 16,
				'bottom' => 16,
				'left'   => 16,
			),
			'imagePadding'         => array(
				'top'    => 8,
				'right'  => 8,
				'bottom' => 8,
				'left'   => 8,
			),
			'borderColor'          => '#e5e7eb',
			'titleColor'           => '#1f2937',
			'descriptionColor'     => '#000000',
			'priceColor'           => '#059669',
			'imageBackgroundColor' => '#f9fafb',
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
		// Check if productId is set and fetch live WooCommerce data
		if ( ! empty( $props['productId'] ) ) {
			$live_product_data = \DoubleScale\Modules\Emails\ProductDataFetcher::get_product_data( $props['productId'] );

			if ( $live_product_data ) {
				// Override props with live product data
				$props = array_merge( $props, $live_product_data );
			} else {
				// Product not found or not published - hide the block
				return '';
			}
		}

		// Merge with default props
		$props = wp_parse_args( $props, $this->get_default_props() );

		// Process content for merge tags
		$image_src   = $this->process_merge_tags( $props['imageSrc'], $contact );
		$image_alt   = $this->process_merge_tags( $props['imageAlt'], $contact );
		$title       = $this->process_merge_tags( $props['title'], $contact );
		$description = $this->process_merge_tags( $props['description'], $contact );
		$price       = $this->process_merge_tags( $props['price'], $contact );
		$button_text = $this->process_merge_tags( $props['buttonText'], $contact );
		$button_link = $this->process_merge_tags( $props['buttonLink'], $contact );

		// Get button settings
		$button_settings = $this->get_global_button_settings( $props['buttonStyle'] );

		// Build wrapper styles (always centered)
		$wrapper_styles = array(
			'text-align' => 'center',
			'width'      => '100%',
		);

		// Build container styles - matching frontend to prevent overflow
		$container_styles = array(
			'width'          => $props['width'],
			'max-width'      => '100%',
			'padding'        => $this->format_padding( $props['padding'] ),
			'border'         => '1px solid ' . $props['borderColor'],
			'border-radius'  => '8px',
			'display'        => 'inline-block',
			'vertical-align' => 'top',
			'margin'         => '0 auto',
			'box-sizing'     => 'border-box',
			'text-align'     => 'center',
			'word-wrap'      => 'break-word',
			'overflow-wrap'  => 'break-word',
		);

		// Build image styles
		$image_padding = $props['imagePadding'] ? $this->format_padding( $props['imagePadding'] ) : '8px';
		$image_styles  = array(
			'width'            => '100%',
			'height'           => '200px',
			'object-fit'       => 'cover',
			'border-radius'    => '4px',
			'background-color' => $props['imageBackgroundColor'],
			'padding'          => $image_padding,
			'box-sizing'       => 'border-box',
		);

		$image_placeholder_styles = array(
			'width'            => '100%',
			'height'           => '200px',
			'background-color' => '#F5F5F580',
			'border-radius'    => '4px',
			'padding'          => $image_padding,
			'display'          => 'flex',
			'align-items'      => 'center',
			'justify-content'  => 'center',
			'color'            => '#6B7280',
			'font-size'        => '14px',
			'font-weight'      => '500',
			'box-sizing'       => 'border-box',
		);

		// Build text styles - with proper text wrapping
		$title_styles = array(
			'font-weight'   => 'bold',
			'color'         => $props['titleColor'],
			'margin'        => '16px 0 8px 0',
			'font-size'     => '18px',
			'line-height'   => '1.4',
			'word-wrap'     => 'break-word',
			'overflow-wrap' => 'break-word',
			'max-width'     => '100%',
		);

		$description_styles = array(
			'color'         => $props['descriptionColor'],
			'font-weight'   => 'normal',
			'margin'        => '8px 0',
			'font-size'     => '14px',
			'line-height'   => '1.5',
			'word-wrap'     => 'break-word',
			'overflow-wrap' => 'break-word',
			'max-width'     => '100%',
		);

		$price_styles = array(
			'color'         => $props['priceColor'],
			'font-weight'   => 'bold',
			'margin'        => '8px 0 16px 0',
			'font-size'     => '18px',
			'line-height'   => '1.4',
			'word-wrap'     => 'break-word',
			'overflow-wrap' => 'break-word',
			'max-width'     => '100%',
		);

		// Build button styles
		$button_styles = $this->get_button_style( $props['buttonStyle'], $button_settings );

		// Build style strings
		$wrapper_style_string           = $this->build_style_string( $wrapper_styles );
		$container_style_string         = $this->build_style_string( $container_styles );
		$image_style_string             = $this->build_style_string( $image_styles );
		$image_placeholder_style_string = $this->build_style_string( $image_placeholder_styles );
		$title_style_string             = $this->build_style_string( $title_styles );
		$description_style_string       = $this->build_style_string( $description_styles );
		$price_style_string             = $this->build_style_string( $price_styles );
		$button_style_string            = $this->build_style_string( $button_styles );

		// Render image or placeholder
		$image_html = '';
		if ( ! empty( $image_src ) ) {
			$image_html = "<img src=\"{$image_src}\" alt=\"{$image_alt}\" style=\"{$image_style_string}\" />";
		} else {
			$image_html = "<div style=\"{$image_placeholder_style_string}\">📷</div>";
		}

		// Render price with fallback
		$display_price = ! empty( $price ) ? $price : '0.00 EGP';

		return "<div style=\"{$wrapper_style_string}\">
			<div style=\"{$container_style_string}\">
				{$image_html}
				<h3 style=\"{$title_style_string}\">{$title}</h3>
				<p style=\"{$description_style_string}\">{$description}</p>
				<div style=\"{$price_style_string}\">{$display_price}</div>
				<a href=\"{$button_link}\" style=\"{$button_style_string}\">{$button_text}</a>
			</div>
		</div>";
	}

	/**
	 * Get global button settings
	 *
	 * @param string $button_style Button style type
	 * @return array Button settings
	 */
	private function get_global_button_settings( string $button_style = 'primary' ): array {
		// Default settings matching the frontend ButtonSettingsContext
		$default_settings = array(
			'font'            => 'Arial',
			'size'            => 14,
			'letterSpacing'   => '0px',
			'borderRadius'    => 0,
			'textColor'       => '#FFFFFF',
			'backgroundColor' => '#1E3A8A',
			'borderWidth'     => 1,
			'borderColor'     => '#1E3A8A',
			'padding'         => array(
				'top'    => 4,
				'right'  => 8,
				'bottom' => 4,
				'left'   => 8,
			),
			'bold'            => false,
			'italic'          => false,
			'underline'       => false,
		);

		// Try to get settings from current email renderer (template-specific)
		global $doublescale_email_renderer;
		if ( isset( $doublescale_email_renderer ) && method_exists( $doublescale_email_renderer, 'get_button_settings' ) ) {
			$template_settings = $doublescale_email_renderer->get_button_settings( $button_style );
			if ( ! empty( $template_settings ) ) {
				return wp_parse_args( $template_settings, $default_settings );
			}
		}

		// Fallback to global settings from database
		$saved_settings = \DoubleScale\Core\Settings\Settings::get( 'button_settings', array() );

		if ( ! empty( $saved_settings ) && is_array( $saved_settings ) ) {
			// Get settings for the specific button style
			$style_settings = isset( $saved_settings[ $button_style ] ) ? $saved_settings[ $button_style ] : array();

			// Merge with defaults to ensure all properties exist
			$merged_settings = wp_parse_args( $style_settings, $default_settings );

			return $merged_settings;
		}

		return $default_settings;
	}

	/**
	 * Get button style based on button type
	 *
	 * @param string $button_style Button style type
	 * @param array  $button_settings Button settings
	 * @return array Button styles
	 */
	private function get_button_style( string $button_style, array $button_settings ): array {
		$decoration_parts = array();
		if ( ! empty( $button_settings['underline'] ) ) {
			$decoration_parts[] = 'underline';
		}
		if ( ! empty( $button_settings['strikethrough'] ) ) {
			$decoration_parts[] = 'line-through';
		}
		$text_decoration = empty( $decoration_parts ) ? 'none' : implode( ' ', $decoration_parts );

		$base_style = array(
			'display'         => 'inline-block',
			'font-family'     => $button_settings['font'],
			'font-size'       => $button_settings['size'] . 'px',
			'letter-spacing'  => $button_settings['letterSpacing'],
			'border-radius'   => $button_settings['borderRadius'] . 'px',
			'font-weight'     => ! empty( $button_settings['bold'] ) ? 'bold' : 'normal',
			'font-style'      => ! empty( $button_settings['italic'] ) ? 'italic' : 'normal',
			'text-decoration' => $text_decoration,
			'padding'         => $this->format_button_padding( $button_settings['padding'] ),
		);

		// Apply global button settings (all button types use the same styling)
		return array_merge(
			$base_style,
			array(
				'color'            => $button_settings['textColor'],
				'background-color' => $button_settings['backgroundColor'],
				'border'           => $button_settings['borderWidth'] . 'px solid ' . $button_settings['borderColor'],
			)
		);
	}

	/**
	 * Format button padding
	 *
	 * @param array $padding Padding array
	 * @return string CSS padding string
	 */
	private function format_button_padding( array $padding ): string {
		$top    = $padding['top'] ?? 2;
		$right  = $padding['right'] ?? 4;
		$bottom = $padding['bottom'] ?? 2;
		$left   = $padding['left'] ?? 4;

		return "{$top}px {$right}px {$bottom}px {$left}px";
	}
}

