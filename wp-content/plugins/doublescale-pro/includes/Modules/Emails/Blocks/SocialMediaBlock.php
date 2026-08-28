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
				'whatsapp'   => array(
					'enabled' => false,
					'link'    => '',
				),
			),
			'customIcons'   => array(),
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
				'whatsapp',
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
		$props['platforms'] = wp_parse_args(
			isset( $props['platforms'] ) && is_array( $props['platforms'] ) ? $props['platforms'] : array(),
			$this->get_default_props()['platforms']
		);
		if ( ! isset( $props['customIcons'] ) || ! is_array( $props['customIcons'] ) ) {
			$props['customIcons'] = array();
		}

		$props = $this->normalize_social_block_props( $props );

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
		$enabled_platforms = array();
		foreach ( $props['platforms'] as $platform => $data ) {
			if ( 'custom' === $platform ) {
				continue;
			}
			if ( empty( $data['enabled'] ) ) {
				continue;
			}
			$enabled_platforms[ $platform ] = $data;
		}

		foreach ( $props['customIcons'] as $custom_icon ) {
			if ( empty( $custom_icon['id'] ) || empty( $custom_icon['enabled'] ) || empty( $custom_icon['iconUrl'] ) ) {
				continue;
			}
			$enabled_platforms[ 'custom:' . $custom_icon['id'] ] = $custom_icon;
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

			if ( 0 === strpos( $platform, 'custom:' ) ) {
				$icon_url = $this->escape_image_src( $data['iconUrl'] ?? '' );
				$alt      = esc_attr__( 'Custom', 'doublescale' );
			} else {
				$icon_url = $this->escape_image_src(
					$this->get_social_icon_url(
						$platform,
						$icon_size,
						$props['shape'],
						$props['colorMode'] === 'original',
						$props['color']
					)
				);
				$alt = esc_attr( $platform );
			}

			// Calculate padding: 8px on each side for 16px gap, but no padding on outer edges
			$is_first = ( $platform_index === 0 );
			$is_last  = ( $platform_index === $platform_count - 1 );

			$padding_left  = $is_first ? '0' : '8px';
			$padding_right = $is_last ? '0' : '8px';

			$cell_style = "padding: 0 {$padding_right} 0 {$padding_left};";

			$html .= "<td align=\"center\" valign=\"middle\" style=\"{$cell_style}\">
				<a href=\"{$link}\" target=\"_blank\" rel=\"noopener noreferrer\" style=\"display:inline-block;text-decoration:none;line-height:0;\">
					<img src=\"{$icon_url}\" alt=\"{$alt}\" width=\"{$icon_size}\" height=\"{$icon_size}\" style=\"border-radius:{$border_radius};border:0;display:block;\" />
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

	/**
	 * Normalize custom icons and migrate legacy single-custom data.
	 *
	 * @param array $props Block properties.
	 * @return array
	 */
	private function normalize_social_block_props( array $props ) {
		$custom_icons = array();
		$seen_ids     = array();

		foreach ( $props['customIcons'] as $icon ) {
			if ( empty( $icon['id'] ) || isset( $seen_ids[ $icon['id'] ] ) ) {
				continue;
			}
			$seen_ids[ $icon['id'] ] = true;
			$custom_icons[]          = array(
				'id'      => (string) $icon['id'],
				'enabled' => ! empty( $icon['enabled'] ),
				'link'    => isset( $icon['link'] ) ? (string) $icon['link'] : '',
				'iconUrl' => isset( $icon['iconUrl'] ) ? (string) $icon['iconUrl'] : '',
			);
		}

		$legacy_custom = $props['platforms']['custom'] ?? null;
		if (
			is_array( $legacy_custom )
			&& (
				! empty( $legacy_custom['enabled'] )
				|| ! empty( $legacy_custom['link'] )
				|| ! empty( $legacy_custom['iconUrl'] )
			)
			&& ! isset( $seen_ids['legacy-custom'] )
		) {
			$custom_icons[] = array(
				'id'      => 'legacy-custom',
				'enabled' => ! empty( $legacy_custom['enabled'] ),
				'link'    => isset( $legacy_custom['link'] ) ? (string) $legacy_custom['link'] : '',
				'iconUrl' => isset( $legacy_custom['iconUrl'] ) ? (string) $legacy_custom['iconUrl'] : '',
			);
			$seen_ids['legacy-custom'] = true;

			if ( is_array( $props['platformOrder'] ) ) {
				$props['platformOrder'] = array_map(
					static function ( $key ) {
						return 'custom' === $key ? 'custom:legacy-custom' : $key;
					},
					$props['platformOrder']
				);
			}
		}

		$props['customIcons'] = $custom_icons;

		if ( ! is_array( $props['platformOrder'] ) ) {
			$props['platformOrder'] = $this->get_default_props()['platformOrder'];
		}

		$order          = array();
		$seen_order     = array();
		$allowed_custom = array();
		foreach ( $custom_icons as $icon ) {
			$allowed_custom[ 'custom:' . $icon['id'] ] = true;
		}

		foreach ( $props['platformOrder'] as $key ) {
			if ( isset( $seen_order[ $key ] ) ) {
				continue;
			}
			if ( isset( $props['platforms'][ $key ] ) || isset( $allowed_custom[ $key ] ) || 'custom' === $key ) {
				$seen_order[ $key ] = true;
				$order[]              = $key;
			}
		}

		foreach ( array_keys( $props['platforms'] ) as $key ) {
			if ( 'custom' === $key || isset( $seen_order[ $key ] ) ) {
				continue;
			}
			$order[]              = $key;
			$seen_order[ $key ] = true;
		}

		foreach ( array_keys( $allowed_custom ) as $key ) {
			if ( isset( $seen_order[ $key ] ) ) {
				continue;
			}
			$order[]            = $key;
			$seen_order[ $key ] = true;
		}

		$props['platformOrder'] = $order;

		return $props;
	}
}
