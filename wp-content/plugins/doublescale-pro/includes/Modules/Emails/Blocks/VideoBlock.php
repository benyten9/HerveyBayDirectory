<?php
/**
 * Video Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Emails\Blocks;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Abstracts\EmailBlock;

/**
 * Video block for emails
 */
class VideoBlock extends EmailBlock {
	/**
	 * Get block type
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'video';
	}

	/**
	 * Get block name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Video', 'doublescale');
	}

	/**
	 * Get default properties
	 *
	 * @return array
	 */
	public function get_default_props(): array {
		return array(
			'videoUrl'        => '',
			'imageUrl'        => '',
			'alt'             => 'Video',
			'width'           => '100%',
			'height'          => 'auto',
			'align'           => 'center',
			'backgroundColor' => '#000000',
			'padding'         => array(
				'top'    => 0,
				'right'  => 0,
				'bottom' => 0,
				'left'   => 0,
			),
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

		// Process URLs for merge tags
		$video_url = $this->process_merge_tags( $props['videoUrl'], $contact );
		$image_url = $this->process_merge_tags( $props['imageUrl'], $contact );
		$alt_text  = $this->process_merge_tags( $props['alt'], $contact );

		// Get background color (matches frontend getBackgroundColor)
		$background_color = '#000000'; // Default black
		if ( ! empty( $props['backgroundColor'] ) && $props['backgroundColor'] !== 'transparent' ) {
			$background_color = $props['backgroundColor'];
		}

		// Get alignment margin (matches frontend getContainerStyle)
		$margin = '0';
		switch ( $props['align'] ) {
			case 'center':
				$margin = '0 auto';
				break;
			case 'right':
				$margin = '0 0 0 auto';
				break;
			default: // left
				$margin = '0';
		}

		// Container styles (matches frontend getContainerStyle)
		$container_styles = array(
			'width'            => $props['width'] === 'auto' ? '100%' : $props['width'],
			'height'           => $props['height'] === 'auto' ? 'auto' : $props['height'],
			'position'         => 'relative',
			'border-radius'    => $props['borderRadius'] . 'px',
			'background-color' => $background_color,
			'padding'          => $this->format_padding( $props['padding'] ),
			'margin'           => $margin,
			'overflow'         => 'hidden',
		);

		$container_style_string = $this->build_style_string( $container_styles );

		// If no video URL, show placeholder (matches frontend)
		if ( empty( $video_url ) ) {
			return $this->render_placeholder( $container_style_string );
		}

		// If no thumbnail provided, try to get YouTube thumbnail
		if ( empty( $image_url ) ) {
			$image_url = $this->get_youtube_thumbnail( $video_url );
		}

		// If there's a thumbnail image (provided or auto-fetched), show it with play button
		if ( ! empty( $image_url ) ) {
			return $this->render_with_thumbnail( $container_style_string, $image_url, $alt_text, $video_url, $props );
		}

		// Direct video link (fallback for email clients that don't support video)
		return $this->render_video_link( $container_style_string, $video_url, $alt_text );
	}

	/**
	 * Extract YouTube video ID from URL
	 *
	 * @param string $url YouTube URL
	 * @return string|null Video ID or null if not a YouTube URL
	 */
	private function get_youtube_video_id( string $url ): ?string {
		// Match various YouTube URL formats
		$patterns = array(
			// Standard: https://www.youtube.com/watch?v=VIDEO_ID
			'/(?:youtube\.com\/watch\?v=)([a-zA-Z0-9_-]{11})/',
			// Short: https://youtu.be/VIDEO_ID
			'/(?:youtu\.be\/)([a-zA-Z0-9_-]{11})/',
			// Embed: https://www.youtube.com/embed/VIDEO_ID
			'/(?:youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
			// With additional params: https://www.youtube.com/watch?v=VIDEO_ID&list=...
			'/(?:youtube\.com\/watch\?.*v=)([a-zA-Z0-9_-]{11})/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $url, $matches ) ) {
				return $matches[1];
			}
		}

		return null;
	}

	/**
	 * Get YouTube thumbnail URL from video URL
	 *
	 * @param string $video_url Video URL
	 * @return string|null Thumbnail URL or null if not a YouTube video
	 */
	private function get_youtube_thumbnail( string $video_url ): ?string {
		$video_id = $this->get_youtube_video_id( $video_url );

		if ( ! $video_id ) {
			return null;
		}

		// Use maxresdefault for high quality, falls back gracefully in browsers
		// Alternative options: hqdefault (480x360), mqdefault (320x180), sddefault (640x480)
		return "https://img.youtube.com/vi/{$video_id}/maxresdefault.jpg";
	}

	/**
	 * Render placeholder when no video URL (matches frontend)
	 * Uses table-based layout for email client compatibility
	 *
	 * @param string $container_style Container style string
	 * @return string HTML output
	 */
	private function render_placeholder( string $container_style ): string {
		// Add height for placeholder (matches frontend isPlaceholder = true)
		$container_style_with_height = str_replace( 'height: auto;', 'height: 300px;', $container_style );
		if ( strpos( $container_style_with_height, 'height: 300px;' ) === false ) {
			$container_style_with_height = $container_style . ' height: 300px;';
		}

		// Use table for centering - email compatible
		return "
		<div style=\"{$container_style_with_height}\">
			<table role=\"presentation\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" height=\"300\" style=\"width: 100%; height: 300px;\">
				<tr>
					<td align=\"center\" valign=\"middle\" style=\"text-align: center; vertical-align: middle; color: #ffffff; font-size: 48px;\">
						&#128249;
					</td>
				</tr>
			</table>
		</div>";
	}

	/**
	 * Render with thumbnail image (matches frontend)
	 * Uses table-based layout for email client compatibility
	 *
	 * @param string $container_style Container style string
	 * @param string $image_url Image URL
	 * @param string $alt_text Alt text
	 * @param string $video_url Video URL
	 * @param array  $props Block properties
	 * @return string HTML output
	 */
	private function render_with_thumbnail( string $container_style, string $image_url, string $alt_text, string $video_url, array $props ): string {
		$border_radius = $props['borderRadius'] . 'px';

		// Escape URLs for HTML attributes
		$video_url_escaped = esc_url( $video_url );
		$image_url_escaped = esc_url( $image_url );
		$alt_text_escaped  = esc_attr( $alt_text );

		// Use a table-based approach for email compatibility
		// The entire thumbnail is wrapped in a link that opens in a new tab
		// Play button is centered using absolute positioning with email-safe CSS
		return "
		<div style=\"{$container_style}\">
			<a href=\"{$video_url_escaped}\" target=\"_blank\" rel=\"noopener noreferrer\" style=\"display: block; position: relative; text-decoration: none;\">
				<!--[if mso]>
				<v:rect xmlns:v=\"urn:schemas-microsoft-com:vml\" fill=\"true\" stroke=\"false\" style=\"width:100%;height:auto;\">
				<v:fill type=\"frame\" src=\"{$image_url_escaped}\" />
				<![endif]-->
				<img src=\"{$image_url_escaped}\" alt=\"{$alt_text_escaped}\" style=\"display: block; width: 100%; height: auto; border-radius: {$border_radius}; border: 0;\" />
				<!--[if mso]>
				</v:rect>
				<![endif]-->
				<!-- Play button overlay - centered using table for email compatibility -->
				<div style=\"position: absolute; top: 0; left: 0; right: 0; bottom: 0;\">
					<table role=\"presentation\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" height=\"100%\" style=\"width: 100%; height: 100%;\">
						<tr>
							<td align=\"center\" valign=\"middle\" style=\"text-align: center; vertical-align: middle;\">
								<div style=\"display: inline-block; width: 70px; height: 70px; line-height: 70px; background-color: rgba(0, 0, 0, 0.7); border-radius: 50%; text-align: center; font-size: 28px; color: #ffffff;\">
									&#9658;
								</div>
							</td>
						</tr>
					</table>
				</div>
			</a>
		</div>";
	}

	/**
	 * Render video link (fallback when no thumbnail available)
	 * Uses table-based layout for email client compatibility
	 *
	 * @param string $container_style Container style string
	 * @param string $video_url Video URL
	 * @param string $alt_text Alt text
	 * @return string HTML output
	 */
	private function render_video_link( string $container_style, string $video_url, string $alt_text ): string {
		$video_url_escaped = esc_url( $video_url );
		$alt_text_escaped  = esc_html( $alt_text );

		// Use table for centering - email compatible
		return "
		<div style=\"{$container_style} min-height: 200px;\">
			<table role=\"presentation\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" height=\"200\" style=\"width: 100%; height: 200px;\">
				<tr>
					<td align=\"center\" valign=\"middle\" style=\"text-align: center; vertical-align: middle;\">
						<a href=\"{$video_url_escaped}\" target=\"_blank\" rel=\"noopener noreferrer\" style=\"color: #ffffff; text-decoration: none; font-size: 16px;\">
							<div style=\"display: inline-block; width: 70px; height: 70px; line-height: 70px; background-color: rgba(255, 255, 255, 0.2); border-radius: 50%; text-align: center; font-size: 28px; color: #ffffff; margin-bottom: 10px;\">
								&#9658;
							</div>
							<br/>
							{$alt_text_escaped}
						</a>
					</td>
				</tr>
			</table>
		</div>";
	}

	/**
	 * Get alignment margin
	 *
	 * @param string $align Alignment value
	 * @return string Margin value
	 */
	private function get_alignment_margin( string $align ): string {
		switch ( $align ) {
			case 'center':
				return '0 auto';
			case 'right':
				return '0 0 0 auto';
			case 'left':
			default:
				return '0';
		}
	}
}
