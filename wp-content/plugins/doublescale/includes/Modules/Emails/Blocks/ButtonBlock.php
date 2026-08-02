<?php
/**
 * Button Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails\Blocks;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Abstracts\EmailBlock;

/**
 * Button block for emails
 */
class ButtonBlock extends EmailBlock {
	/**
	 * Get block type
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'button';
	}

	/**
	 * Get block name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Button', 'doublescale' );
	}

	/**
	 * Get default properties
	 *
	 * @return array
	 */
	public function get_default_props(): array {
		return array(
			'text'                     => 'Click Here',
			'url'                      => '#',
			'containerPadding'         => array(
				'top'    => 0,
				'right'  => 0,
				'bottom' => 0,
				'left'   => 0,
			),
			'containerBackgroundColor' => 'transparent',
			'align'                    => 'center',
			'buttonStyle'              => 'primary',
		);
	}

	/**
	 * Get global button settings for a specific button style
	 *
	 * @param string $button_style Button style (primary, secondary, tertiary)
	 * @return array Global button settings
	 */
	private function get_global_button_settings( $button_style = 'primary' ): array {
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
	 * Render block
	 *
	 * @param array                                    $props Block properties
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string HTML output
	 */
	public function render( array $props, $contact = null ): string {
		// Merge with default props
		$props = wp_parse_args( $props, $this->get_default_props() );

		// Process text and URL for merge tags
		$text = $this->process_merge_tags( $props['text'], $contact );
		$url  = $this->process_merge_tags( $props['url'], $contact );

		// Get global button settings (matching frontend)
		$global_settings = $this->get_global_button_settings( $props['buttonStyle'] );

		// Container padding (matches frontend containerPaddingString)
		$container_padding        = $props['containerPadding'] ?? array(
			'top'    => 0,
			'right'  => 0,
			'bottom' => 0,
			'left'   => 0,
		);
		$container_padding_string = $this->format_padding( $container_padding );

		// Get alignment (matches frontend getAlignment function)
		$alignment = 'center';
		switch ( $props['align'] ) {
			case 'left':
				$alignment = 'left';
				break;
			case 'right':
				$alignment = 'right';
				break;
			case 'full':
				$alignment = 'center';
				break;
			default:
				$alignment = 'center';
		}

		// Container style (matches frontend containerStyle)
		$container_style = $this->build_style_string(
			array(
				'text-align'       => $alignment,
				'width'            => $props['align'] === 'full' ? '100%' : 'auto',
				'padding'          => $container_padding_string,
				'background-color' => $props['containerBackgroundColor'],
				'overflow'         => 'hidden',
				'word-wrap'        => 'break-word',
			)
		);

		// Button style (matches frontend getButtonStyle function)
		$button_padding_string = $this->format_button_padding_multiplied( $global_settings['padding'] );

		$button_styles = array(
			'display'          => $props['align'] === 'full' ? 'block' : 'inline-block',
			'font-family'      => $global_settings['font'],
			'font-size'        => $global_settings['size'] . 'px',
			'letter-spacing'   => $global_settings['letterSpacing'],
			'border-radius'    => $global_settings['borderRadius'] . 'px',
			'font-weight'      => $global_settings['bold'] ? 'bold' : 'normal',
			'font-style'       => $global_settings['italic'] ? 'italic' : 'normal',
			'text-decoration'  => $this->format_button_text_decoration( $global_settings ),
			'white-space'      => 'normal',
			'word-wrap'        => 'break-word',
			'overflow-wrap'    => 'break-word',
			'max-width'        => '100%',
			'padding'          => $button_padding_string,
			'color'            => $global_settings['textColor'],
			'background-color' => $global_settings['backgroundColor'],
			'border'           => $global_settings['borderWidth'] . 'px solid ' . $global_settings['borderColor'],
		);

		// Add full width when alignment is 'full'
		if ( $props['align'] === 'full' ) {
			$button_styles['width'] = '100%';
		}

		$button_style = $this->build_style_string( $button_styles );

		// Simple div structure matching frontend exactly
		return "<div style=\"{$container_style}\">
			<a href=\"{$url}\" style=\"{$button_style}\">{$text}</a>
		</div>";
	}

	/**
	 * Build CSS text-decoration from global button settings.
	 *
	 * @param array $settings Button settings.
	 * @return string
	 */
	private function format_button_text_decoration( array $settings ): string {
		$parts = array();
		if ( ! empty( $settings['underline'] ) ) {
			$parts[] = 'underline';
		}
		if ( ! empty( $settings['strikethrough'] ) ) {
			$parts[] = 'line-through';
		}
		return empty( $parts ) ? 'none' : implode( ' ', $parts );
	}

	/**
	 * Format button padding directly from settings values
	 *
	 * @param array $padding Padding array
	 * @return string CSS padding string
	 */
	private function format_button_padding_multiplied( array $padding ): string {
		$top    = $padding['top'] ?? 2;
		$right  = $padding['right'] ?? 4;
		$bottom = $padding['bottom'] ?? 2;
		$left   = $padding['left'] ?? 4;

		return "{$top}px {$right}px {$bottom}px {$left}px";
	}
}
