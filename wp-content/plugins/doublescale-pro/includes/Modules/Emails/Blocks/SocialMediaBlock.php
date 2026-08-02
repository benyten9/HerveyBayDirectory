<?php
/**
 * Social Media Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Emails\Blocks;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Abstracts\EmailBlock;
use DoubleScale\Modules\Emails\SocialIconGenerator;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Social Media block for emails
 */
class SocialMediaBlock extends EmailBlock {
	/**
	 * Get block type
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'social_media';
	}

	/**
	 * Get block name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Social Media', 'doublescale');
	}

	/**
	 * Get default properties
	 *
	 * @return array
	 */
	public function get_default_props(): array {
		return array(
			'platforms' => array(
				'facebook'   => array(
					'enabled' => true,
					'link'    => 'https://facebook.com',
				),
				'x'          => array(
					'enabled' => true,
					'link'    => 'https://x.com',
				),
				'instagram'  => array(
					'enabled' => true,
					'link'    => 'https://instagram.com',
				),
				'youtube'    => array(
					'enabled' => false,
					'link'    => '',
				),
				'pinterest'  => array(
					'enabled' => false,
					'link'    => '',
				),
				'linkedin'   => array(
					'enabled' => false,
					'link'    => '',
				),
				'tiktok'     => array(
					'enabled' => true,
					'link'    => 'https://tiktok.com',
				),
				'threads'    => array(
					'enabled' => false,
					'link'    => '',
				),
				'spotify'    => array(
					'enabled' => false,
					'link'    => '',
				),
				'snapchat'   => array(
					'enabled' => false,
					'link'    => '',
				),
				'soundcloud' => array(
					'enabled' => false,
					'link'    => '',
				),
				'mail'       => array(
					'enabled' => false,
					'link'    => '',
				),
				'website'    => array(
					'enabled' => false,
					'link'    => '',
				),
				'vimeo'      => array(
					'enabled' => false,
					'link'    => '',
				),
				'medium'     => array(
					'enabled' => false,
					'link'    => '',
				),
				'discord'    => array(
					'enabled' => false,
					'link'    => '',
				),
			),
			'platformOrder' => array(
				'facebook',
				'x',
				'instagram',
				'tiktok',
				'threads',
				'youtube',
				'pinterest',
				'spotify',
				'snapchat',
				'soundcloud',
				'mail',
				'website',
				'vimeo',
				'medium',
				'discord',
				'linkedin',
			),
			'iconSize'  => 'medium',
			'align'     => 'center',
			'shape'     => 'circle',
			'colorMode' => 'original',
			'color'     => '',
			'padding'   => array(
				'top'    => 16,
				'right'  => 16,
				'bottom' => 16,
				'left'   => 16,
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

		// Container styles (matching frontend)
		$container_style = $this->build_style_string(
			array(
				'padding'    => $this->format_padding( $props['padding'] ),
				'text-align' => $props['align'],
				'width'      => '100%',
			)
		);

		// Get icon size in pixels (matching frontend)
		$icon_size = 32; // Default medium
		if ( $props['iconSize'] === 'small' ) {
			$icon_size = 24;
		} elseif ( $props['iconSize'] === 'large' ) {
			$icon_size = 40;
		}

		// Get border radius based on shape (matching frontend)
		$border_radius = '0';
		if ( $props['shape'] === 'circle' ) {
			$border_radius = '50%';
		} elseif ( $props['shape'] === 'rounded' ) {
			$border_radius = '8px';
		}

		// Find enabled platforms (matching frontend)
		// Frontend only checks enabled, not link (uses '#' as fallback)
		$enabled_platforms = array();
		foreach ( $props['platforms'] as $platform => $data ) {
			if ( ! empty( $data['enabled'] ) ) {
				$enabled_platforms[ $platform ] = $data;
			}
		}

		if ( empty( $enabled_platforms ) ) {
			// No platforms enabled, return placeholder (matching frontend)
			return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">
				<tr>
					<td style=\"{$container_style};text-align:center;padding:20px;\">
						<span style=\"font-size: 32px; font-weight: 600; color: #1E3A8A;\">" .
							esc_html__( 'Add social media links', 'doublescale') . '</span>
					</td>
				</tr>
			</table>';
		}

		$ordered_enabled = array();
		if ( ! empty( $props['platformOrder'] ) && is_array( $props['platformOrder'] ) ) {
			foreach ( $props['platformOrder'] as $platform ) {
				if ( isset( $enabled_platforms[ $platform ] ) ) {
					$ordered_enabled[ $platform ] = $enabled_platforms[ $platform ];
				}
			}
			foreach ( $enabled_platforms as $platform => $data ) {
				if ( ! isset( $ordered_enabled[ $platform ] ) ) {
					$ordered_enabled[ $platform ] = $data;
				}
			}
		} else {
			$ordered_enabled = $enabled_platforms;
		}

		// Start with a table for better email compatibility (matching frontend)
		// Frontend uses gap-4 (16px) between icons, so we use 8px padding on each side = 16px gap
		$html = "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">
			<tr>
				<td style=\"{$container_style}\">
					<table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" align=\"{$props['align']}\">
						<tr>";

		// Add each platform icon (matching frontend)
		// Frontend uses gap-4 (16px) between icons, so we use 8px padding on left/right
		$platform_count  = count( $ordered_enabled );
		$platform_index = 0;

		foreach ( $ordered_enabled as $platform => $data ) {
			// Use link if provided, otherwise use '#' as fallback (matching frontend)
			$link = ! empty( $data['link'] ) ? $this->process_merge_tags( $data['link'], $contact ) : '#';

			$icon_url = $this->get_social_icon_url(
				$platform,
				$icon_size,
				$props['shape'],
				$props['colorMode'] === 'original',
				$props['color']
			);

			// Calculate padding: 8px on each side for 16px gap, but no padding on outer edges
			$is_first = ( $platform_index === 0 );
			$is_last  = ( $platform_index === $platform_count - 1 );

			$padding_left  = $is_first ? '0' : '8px';
			$padding_right = $is_last ? '0' : '8px';

			$cell_style = "padding: 0 {$padding_right} 0 {$padding_left};";

			$html .= "<td align=\"center\" valign=\"middle\" style=\"{$cell_style}\">
				<a href=\"{$link}\" target=\"_blank\" rel=\"noopener noreferrer\" style=\"display:inline-block;text-decoration:none;line-height:0;\">
					<img src=\"{$icon_url}\" alt=\"{$platform}\" width=\"{$icon_size}\" height=\"{$icon_size}\" style=\"border-radius:{$border_radius};border:0;display:block;\" />
				</a>
			</td>";

			$platform_index++;
		}

		$html .= '</tr>
					</table>
				</td>
			</tr>
		</table>';

		return $html;
	}

	/**
	 * Get the URL for a social icon PNG.
	 *
	 * Original-color icons are shipped with the plugin in assets/social-icons/.
	 * Custom-color icons are generated on-demand via GD and cached in
	 * wp-content/uploads/doublescale/social-icons/.
	 *
	 * @param string $platform Platform name.
	 * @param int    $size Icon size in pixels (24, 32, or 40).
	 * @param string $shape Shape (circle, rounded, square).
	 * @param bool   $original_colors Whether to use original brand colors.
	 * @param string $color Custom hex color (when not using original).
	 * @return string Full URL to the PNG icon.
	 */
	private function get_social_icon_url( $platform, $size, $shape, $original_colors = true, $color = '' ) {
		if ( $original_colors || empty( $color ) ) {
			$filename = "{$platform}-{$shape}-{$size}.png";
			return DOUBLESCALE_PRO_PLUGIN_URL . 'assets/social-icons/' . $filename;
		}

		$url = SocialIconGenerator::ensure_icon( $platform, $size, $shape, $color );

		if ( $url ) {
			return $url;
		}

		$filename = "{$platform}-{$shape}-{$size}.png";
		return DOUBLESCALE_PRO_PLUGIN_URL . 'assets/social-icons/' . $filename;
	}
}
