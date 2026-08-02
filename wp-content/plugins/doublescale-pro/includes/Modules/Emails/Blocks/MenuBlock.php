<?php
/**
 * Menu Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Emails\Blocks;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Abstracts\EmailBlock;

/**
 * Menu block for emails
 */
class MenuBlock extends EmailBlock {
	/**
	 * Get block type
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'menu';
	}

	/**
	 * Get block name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Menu', 'doublescale');
	}

	/**
	 * Get default properties
	 *
	 * @return array
	 */
	public function get_default_props(): array {
		return array(
			'menuItems' => array(
				array(
					'id'              => '1',
					'name'            => 'Menu Item 01',
					'link'            => '#',
					'fontSize'        => 12,
					'color'           => '#333',
					'fontFamily'      => 'Poppins,Helvetica Neue,Helvetica,Arial,sans-serif',
					'bold'            => false,
					'italic'          => false,
					'underline'       => false,
					'strikethrough'   => false,
					'backgroundColor' => 'transparent',
					'borderRadius'    => '0',
					'letterSpacing'   => '0px',
				),
				array(
					'id'              => '2',
					'name'            => 'Menu Item 02',
					'link'            => '#',
					'fontSize'        => 12,
					'color'           => '#333',
					'fontFamily'      => 'Poppins,Helvetica Neue,Helvetica,Arial,sans-serif',
					'bold'            => false,
					'italic'          => false,
					'underline'       => false,
					'strikethrough'   => false,
					'backgroundColor' => 'transparent',
					'borderRadius'    => '0',
					'letterSpacing'   => '0px',
				),
			),
			'padding'   => array(
				'top'    => 4,
				'right'  => 8,
				'bottom' => 4,
				'left'   => 8,
			),
			'align'     => 'center',
		);
	}

	/**
	 * Render block (Omnisend-style table structure for email client compatibility)
	 *
	 * @param array                                    $props Block properties
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string HTML output
	 */
	public function render( array $props, $contact = null ): string {
		// Merge with default props
		$props = wp_parse_args( $props, $this->get_default_props() );

		$block_id   = substr( uniqid( '', true ), -12 );
		$item_width = isset( $props['itemWidth'] ) ? intval( $props['itemWidth'] ) : 138;
		$background = isset( $props['backgroundColor'] ) ? esc_attr( $props['backgroundColor'] ) : '';
		$padding    = $this->format_padding_multiplied( $props['padding'] ?? null );

		$menu_items_html = '';
		foreach ( $props['menuItems'] as $index => $item ) {
			$item_id = substr( uniqid( '', true ), -12 );
			$menu_items_html .= $this->render_menu_item( $item, $index, $contact, $item_id, $item_width );
		}

		// Omnisend pattern: outer table > tbody > tr > td (with block padding) > inner table > tbody > tr > item tds
		$html  = '<table id="' . esc_attr( $block_id ) . '" background="' . $background . '" border="0" cellspacing="0" cellpadding="0" width="100%" style="">';
		$html .= '<tbody><tr><td style="padding:' . esc_attr( $padding ) . ';">';
		$html .= '<table border="0" cellspacing="0" cellpadding="0" width="100%" style="width:100%"><tbody><tr>';
		$html .= $menu_items_html;
		$html .= '</tr></tbody></table>';
		$html .= '</td></tr></tbody></table>';

		return $html;
	}

	/**
	 * Render individual menu item (Omnisend pattern)
	 *
	 * @param array                                    $item Menu item properties
	 * @param int                                      $index Item index
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @param string                                   $item_id Unique ID for the item
	 * @param int                                      $item_width Width in px per item
	 * @return string HTML output
	 */
	private function render_menu_item( array $item, int $index, $contact, string $item_id, int $item_width ): string {
		$name        = $this->process_merge_tags( $item['name'], $contact );
		$link        = $this->process_merge_tags( $item['link'], $contact );
		$display_name = ! empty( $name ) ? $name : 'MenuItem ' . str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT );

		$font_size      = isset( $item['fontSize'] ) ? intval( $item['fontSize'] ) : 12;
		$color          = isset( $item['color'] ) ? esc_attr( $item['color'] ) : '#333333';
		$font_family    = isset( $item['fontFamily'] ) ? esc_attr( $item['fontFamily'] ) : 'Poppins,Helvetica Neue,Helvetica,Arial,sans-serif';
		$letter_spacing = isset( $item['letterSpacing'] ) ? esc_attr( $item['letterSpacing'] ) : '0px';

		$text_decoration = 'none';
		if ( ! empty( $item['underline'] ) ) {
			$text_decoration = 'underline';
		} elseif ( ! empty( $item['strikethrough'] ) ) {
			$text_decoration = 'line-through';
		}

		$font_styles = "font-family:{$font_family};font-size:{$font_size}px;color:{$color};letter-spacing:{$letter_spacing};text-decoration:{$text_decoration};";
		$link_style  = "text-decoration:none;display:inline-block;width:100%;text-align:center;font-family:{$font_family};font-size:{$font_size}px;color:{$color};letter-spacing:{$letter_spacing};line-height:1.2;";

		// Only add padding to link when explicitly set
		if ( isset( $item['linkPadding'] ) && $item['linkPadding'] !== '' ) {
			$link_padding = intval( $item['linkPadding'] );
			$link_style  .= "padding-top:{$link_padding}px;padding-bottom:{$link_padding}px;";
		}

		// Build pill styles only when explicitly set
		$pill_styles = array();
		if ( isset( $item['backgroundColor'] ) && $item['backgroundColor'] !== '' && $item['backgroundColor'] !== 'transparent' ) {
			$pill_styles[] = 'background-color:' . esc_attr( $item['backgroundColor'] );
		}
		if ( isset( $item['borderColor'] ) && $item['borderColor'] !== '' ) {
			$border_width = isset( $item['borderWidth'] ) ? intval( $item['borderWidth'] ) : 1;
			$pill_styles[] = 'border:' . $border_width . 'px solid ' . esc_attr( $item['borderColor'] );
		}
		if ( isset( $item['borderRadius'] ) && $item['borderRadius'] !== '' && intval( $item['borderRadius'] ) > 0 ) {
			$radius = intval( $item['borderRadius'] );
			$pill_styles[] = 'border-radius:' . $radius . 'px';
			$pill_styles[] = 'border-collapse:separate';
		}
		$pill_style = ! empty( $pill_styles ) ? implode( ';', $pill_styles ) : '';

		// Build td padding only when linkPadding is set
		$td_padding = '';
		if ( isset( $item['linkPadding'] ) && $item['linkPadding'] !== '' ) {
			$link_padding = intval( $item['linkPadding'] );
			$td_padding   = "padding-left:{$link_padding}px;padding-right:{$link_padding}px;padding-top:{$link_padding}px;padding-bottom:{$link_padding}px;";
		}

		// Omnisend pattern: mobile-container td > table > mobile-table td > pill table > single td > link
		$html  = '<td id="' . esc_attr( $item_id . 'c' ) . '" class="mobile-container" style="width:' . $item_width . 'px;vertical-align:top;">';
		$html .= '<table id="' . esc_attr( $item_id ) . '" border="0" cellspacing="0" cellpadding="0" width="100%" style="table-layout:fixed;"><tbody><tr>';
		$html .= '<td align="center" class="mobile-table" valign="top">';
		$html .= '<table border="0" cellpadding="0" cellspacing="0" width="100%"' . ( $pill_style ? ' style="' . $pill_style . '"' : '' ) . '><tbody><tr>';
		$html .= '<td align="center" valign="middle" style="' . $font_styles . $td_padding . '">';
		$html .= '<a target="_blank" href="' . esc_url( $link ) . '" style="' . $link_style . '">' . esc_html( $display_name ) . '</a>';
		$html .= '</td></tr></tbody></table>';
		$html .= '</td></tr></tbody></table>';
		$html .= '</td>';

		return $html;
	}

	/**
	 * Format padding directly from editor values
	 *
	 * @param array|null $padding Padding object
	 * @return string CSS padding string
	 */
	private function format_padding_multiplied( $padding ) {
		if ( ! $padding ) {
			return '0';
		}

		$top    = isset( $padding['top'] ) ? $padding['top'] : 0;
		$right  = isset( $padding['right'] ) ? $padding['right'] : 0;
		$bottom = isset( $padding['bottom'] ) ? $padding['bottom'] : 0;
		$left   = isset( $padding['left'] ) ? $padding['left'] : 0;

		return "{$top}px {$right}px {$bottom}px {$left}px";
	}
}

