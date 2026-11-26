<?php
/**
 * Latest Posts email block.
 *
 * The posts are fetched when the email is rendered (at send time), so an
 * automation or a scheduled newsletter always carries the newest posts.
 * Layouts: list (image beside text), grid (2 or 3 columns) and stacked
 * cards. Built from tables only; columns stack on phones through the
 * renderer's `.mobile-container` rule.
 *
 * @package DoubleScale\Pro\Modules\Emails\Blocks
 */

namespace DoubleScale\Pro\Modules\Emails\Blocks;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Abstracts\EmailBlock;
use DoubleScale\Modules\Emails\Blocks\ButtonBlock;
use DoubleScale\Pro\Modules\Emails\Services\LatestPosts;

/**
 * Latest Posts block.
 */
class PostsBlock extends EmailBlock {

	public function get_type(): string {
		return 'posts';
	}

	public function get_name(): string {
		return __( 'Latest Posts', 'doublescale' );
	}

	public function get_default_props(): array {
		return array(
			'postType'        => 'post',
			'taxonomy'        => '',
			'terms'           => array(),
			'count'           => 3,
			'offset'          => 0,
			'orderBy'         => 'date',
			'onlyRecent'      => false,
			'recentDays'      => 7,
			'layout'          => 'list',
			'columns'         => 2,
			'showImage'       => true,
			'imageSize'       => 'medium_large',
			'imageWidth'      => 38,
			'imageRadius'     => 8,
			'showLabel'       => true,
			'showDate'        => true,
			'showAuthor'      => false,
			'showExcerpt'     => true,
			'excerptLength'   => 24,
			'showButton'      => true,
			'buttonText'      => 'Read more',
			'buttonStyle'     => 'link',
			'titleFont'       => 'Georgia, serif',
			'titleSize'       => 20,
			'titleColor'      => '#111827',
			'fontFamily'      => "'Helvetica Neue', Helvetica, sans-serif",
			'textSize'        => 15,
			'textColor'       => '#4b5563',
			'metaColor'       => '#6b7280',
			'accentColor'     => '#4f46e5',
			'cardBackground'  => 'transparent',
			'cardBorderColor' => '',
			'cardRadius'      => 8,
			'cardPadding'     => 0,
			'gap'             => 24,
			'showDividers'    => true,
			'dividerColor'    => '#e5e7eb',
			'textAlign'       => 'left',
			'emptyMode'       => 'hide',
			'emptyMessage'    => 'New posts are on their way. Check back soon.',
			'backgroundColor' => 'transparent',
			'textDirection'   => 'ltr',
			'padding'         => array(
				'top'    => 8,
				'right'  => 0,
				'bottom' => 8,
				'left'   => 0,
			),
		);
	}

	public function render( array $props, $contact = null ): string {
		$props = wp_parse_args( $props, $this->get_default_props() );
		$items = LatestPosts::items( $props );

		if ( empty( $items ) ) {
			if ( 'message' !== $props['emptyMode'] || '' === trim( (string) $props['emptyMessage'] ) ) {
				return '';
			}
			return $this->wrap( $props, '<tr><td style="' . esc_attr( $this->text_style( $props, (int) $props['textSize'], (string) $props['metaColor'] ) ) . '">' . esc_html( (string) $props['emptyMessage'] ) . '</td></tr>' );
		}

		$width = $this->inner_width( $props );
		switch ( $props['layout'] ) {
			case 'grid':
				$rows = $this->grid( $items, $props, $width );
				break;
			case 'stacked':
				$rows = $this->stacked( $items, $props, $width );
				break;
			default:
				$rows = $this->list_rows( $items, $props, $width );
		}
		return $this->wrap( $props, $rows );
	}

	/* --------------------------------------------------------------------- */

	private function wrap( array $props, string $rows ): string {
		$p   = $props['padding'];
		$dir = 'rtl' === $props['textDirection'] ? 'rtl' : 'ltr';
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="' . $dir . '" style="width:100%;background-color:' . esc_attr( (string) $props['backgroundColor'] ) . ';">'
			. '<tr><td style="padding:' . (int) $p['top'] . 'px ' . (int) $p['right'] . 'px ' . (int) $p['bottom'] . 'px ' . (int) $p['left'] . 'px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">' . $rows . '</table>'
			. '</td></tr></table>';
	}

	/** Content width the block can use, in px. */
	private function inner_width( array $props ): int {
		global $doublescale_email_renderer;
		$w = 600;
		if ( isset( $doublescale_email_renderer ) && ! empty( $doublescale_email_renderer->content_width ) ) {
			$w = (int) $doublescale_email_renderer->content_width;
		}
		return max( 200, $w - (int) $props['padding']['left'] - (int) $props['padding']['right'] );
	}

	private function list_rows( array $items, array $props, int $width ): string {
		$gap   = max( 0, (int) $props['gap'] );
		$out   = '';
		$img_w = (int) round( $width * max( 20, min( 50, (int) $props['imageWidth'] ) ) / 100 );
		foreach ( $items as $i => $item ) {
			if ( $i > 0 ) {
				$out .= ! empty( $props['showDividers'] )
					? '<tr><td style="padding:' . $gap . 'px 0;"><div style="border-top:1px solid ' . esc_attr( (string) $props['dividerColor'] ) . ';height:1px;line-height:1px;font-size:1px;">&nbsp;</div></td></tr>'
					: '<tr><td style="height:' . $gap . 'px;line-height:' . $gap . 'px;font-size:1px;">&nbsp;</td></tr>';
			}
			$has    = ! empty( $props['showImage'] ) && '' !== $item['image'];
			$out   .= '<tr><td>';
			$out   .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;"><tr>';
			if ( $has ) {
				$out .= '<td class="mobile-container" width="' . $img_w . '" valign="top" style="width:' . $img_w . 'px;vertical-align:top;padding:0 20px 12px 0;">'
					. $this->image( $item, $img_w, $props ) . '</td>';
			}
			$out .= '<td class="mobile-container" valign="top" style="vertical-align:top;">' . $this->text( $item, $props ) . '</td>';
			$out .= '</tr></table></td></tr>';
		}
		return $out;
	}

	private function grid( array $items, array $props, int $width ): string {
		$cols = 3 === (int) $props['columns'] ? 3 : 2;
		$gap  = max( 0, (int) $props['gap'] );
		$cell = (int) floor( ( $width - $gap * ( $cols - 1 ) ) / $cols );
		$out  = '';
		foreach ( array_chunk( $items, $cols ) as $r => $chunk ) {
			$out .= '<tr><td style="padding-top:' . ( 0 === $r ? 0 : $gap ) . 'px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;"><tr>';
			for ( $c = 0; $c < $cols; $c++ ) {
				$item = $chunk[ $c ] ?? null;
				if ( $c > 0 ) {
					$out .= '<td class="mobile-container" width="' . $gap . '" style="width:' . $gap . 'px;font-size:1px;line-height:1px;">&nbsp;</td>';
				}
				$out .= '<td class="mobile-container" width="' . $cell . '" valign="top" style="width:' . $cell . 'px;vertical-align:top;">';
				if ( $item ) {
					$out .= $this->card( $item, $props, $cell );
				}
				$out .= '</td>';
			}
			$out .= '</tr></table></td></tr>';
		}
		return $out;
	}

	private function stacked( array $items, array $props, int $width ): string {
		$gap = max( 0, (int) $props['gap'] );
		$out = '';
		foreach ( $items as $i => $item ) {
			$out .= '<tr><td style="padding-top:' . ( 0 === $i ? 0 : $gap ) . 'px;">' . $this->card( $item, $props, $width ) . '</td></tr>';
		}
		return $out;
	}

	/** Image on top, text under it, in an optional card box. */
	private function card( array $item, array $props, int $width ): string {
		$pad    = max( 0, (int) $props['cardPadding'] );
		$border = '' !== (string) $props['cardBorderColor'] ? 'border:1px solid ' . esc_attr( (string) $props['cardBorderColor'] ) . ';' : '';
		$style  = 'background-color:' . esc_attr( (string) $props['cardBackground'] ) . ';' . $border . 'border-radius:' . (int) $props['cardRadius'] . 'px;';
		$html   = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;' . $style . '">';
		if ( ! empty( $props['showImage'] ) && '' !== $item['image'] ) {
			$img_w = max( 40, $width - 2 * $pad - ( $border ? 2 : 0 ) );
			$html .= '<tr><td style="padding:' . $pad . 'px ' . $pad . 'px 14px ' . $pad . 'px;">' . $this->image( $item, $img_w, $props ) . '</td></tr>';
			$html .= '<tr><td style="padding:0 ' . $pad . 'px ' . $pad . 'px ' . $pad . 'px;">' . $this->text( $item, $props ) . '</td></tr>';
		} else {
			$html .= '<tr><td style="padding:' . $pad . 'px;">' . $this->text( $item, $props ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	private function image( array $item, int $width, array $props ): string {
		return '<a href="' . esc_url( $item['url'] ) . '" target="_blank" style="display:block;text-decoration:none;">'
			. '<img src="' . esc_url( $item['image'] ) . '" alt="' . esc_attr( $item['title'] ) . '" width="' . $width . '" border="0" style="display:block;width:100%;max-width:' . $width . 'px;height:auto;border:0;outline:none;border-radius:' . (int) $props['imageRadius'] . 'px;" /></a>';
	}

	/** Label, title, meta, excerpt and the read-more link or button. */
	private function text( array $item, array $props ): string {
		$align = in_array( $props['textAlign'], array( 'left', 'center', 'right' ), true ) ? $props['textAlign'] : 'left';
		$out   = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">';

		if ( ! empty( $props['showLabel'] ) && '' !== $item['label'] ) {
			$out .= '<tr><td align="' . $align . '" style="' . esc_attr( $this->text_style( $props, 11, (string) $props['accentColor'], $align ) . 'font-weight:bold;letter-spacing:1.5px;text-transform:uppercase;padding-bottom:8px;' ) . '">' . esc_html( $item['label'] ) . '</td></tr>';
		}

		$title_style = 'font-family:' . (string) $props['titleFont'] . ';font-size:' . (int) $props['titleSize'] . 'px;line-height:1.3;color:' . (string) $props['titleColor'] . ';text-align:' . $align . ';';
		$out        .= '<tr><td align="' . $align . '" style="' . esc_attr( $title_style . 'padding-bottom:8px;' ) . '"><a href="' . esc_url( $item['url'] ) . '" target="_blank" style="' . esc_attr( $title_style . 'font-weight:normal;letter-spacing:0;text-decoration:none;' ) . '">' . esc_html( $item['title'] ) . '</a></td></tr>';

		$meta = array();
		if ( ! empty( $props['showDate'] ) && '' !== $item['date'] ) {
			$meta[] = esc_html( $item['date'] );
		}
		if ( ! empty( $props['showAuthor'] ) && '' !== $item['author'] ) {
			/* translators: %s: author name */
			$meta[] = esc_html( sprintf( __( 'By %s', 'doublescale' ), $item['author'] ) );
		}
		if ( $meta ) {
			$out .= '<tr><td align="' . $align . '" style="' . esc_attr( $this->text_style( $props, 13, (string) $props['metaColor'], $align ) . 'padding-bottom:8px;' ) . '">' . implode( ' &middot; ', $meta ) . '</td></tr>';
		}

		if ( ! empty( $props['showExcerpt'] ) && '' !== $item['excerpt'] ) {
			$out .= '<tr><td align="' . $align . '" style="' . esc_attr( $this->text_style( $props, (int) $props['textSize'], (string) $props['textColor'], $align ) . 'line-height:1.6;padding-bottom:12px;' ) . '">' . esc_html( $item['excerpt'] ) . '</td></tr>';
		}

		if ( ! empty( $props['showButton'] ) && '' !== trim( (string) $props['buttonText'] ) ) {
			$out .= '<tr><td align="' . $align . '" style="text-align:' . $align . ';">' . $this->read_more( $item, $props, $align ) . '</td></tr>';
		}
		return $out . '</table>';
	}

	private function read_more( array $item, array $props, string $align ): string {
		$text = (string) $props['buttonText'];
		if ( 'link' === $props['buttonStyle'] || ! in_array( $props['buttonStyle'], array( 'primary', 'secondary', 'tertiary' ), true ) ) {
			return '<a href="' . esc_url( $item['url'] ) . '" target="_blank" style="' . esc_attr( $this->text_style( $props, 14, (string) $props['accentColor'], $align ) . 'font-weight:bold;text-decoration:none;' ) . '">' . esc_html( $text ) . ' &rarr;</a>';
		}
		return ( new ButtonBlock() )->render(
			array(
				'text'                     => $text,
				'url'                      => $item['url'],
				'buttonStyle'              => $props['buttonStyle'],
				'align'                    => $align,
				'containerBackgroundColor' => 'transparent',
				'containerPadding'         => array(
					'top'    => 0,
					'right'  => 0,
					'bottom' => 0,
					'left'   => 0,
				),
			)
		);
	}

	private function text_style( array $props, int $size, string $color, string $align = 'left' ): string {
		return 'font-family:' . (string) $props['fontFamily'] . ';font-size:' . $size . 'px;color:' . $color . ';text-align:' . $align . ';';
	}
}
