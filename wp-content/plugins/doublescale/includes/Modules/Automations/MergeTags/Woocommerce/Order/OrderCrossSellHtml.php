<?php
/**
 * Class Order Cross Sell HTML
 *
 * Renders the order's cross-sell products as email-ready product cards
 * (image, name, description, price, link).
 *
 * Kept separate from {@see OrderCrossSell}, which returns a plain comma-joined
 * list: that tag is already in use in plain-text emails and subject lines, so
 * changing its output to markup would break those sends.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Order;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Emails\ProductDataFetcher;

// Merge-tag files are pulled in by a RecursiveDirectoryIterator whose order is
// filesystem-dependent, so the parent is not guaranteed to be loaded first.
require_once __DIR__ . '/OrderCrossSell.php';

/**
 * Order Cross Sell HTML Merge Tag
 */
class OrderCrossSellHtml extends OrderCrossSell {

	/**
	 * Maximum cards rendered, so a product with a long cross-sell list cannot
	 * turn the email into an unscrollable catalogue.
	 */
	protected const MAX_PRODUCTS = 4;

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Cross Sell (products)';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'cross_sell_html';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Cross-sell products with image, description and price';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact   Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return '';
		}

		$order_id = is_object( $contact ) && method_exists( $contact, 'get_data' )
			? (int) $contact->get_data( 'order_id' )
			: 0;

		if ( $order_id <= 0 ) {
			return '';
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}

		$products = $this->get_cross_sell_products( $order );
		if ( empty( $products ) ) {
			return '';
		}

		$products = array_slice( $products, 0, static::MAX_PRODUCTS );

		$cards = '';
		foreach ( $products as $product ) {
			$cards .= $this->render_card( $product );
		}

		if ( '' === $cards ) {
			return '';
		}

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="border-collapse:collapse;width:100%;">'
			. $cards
			. '</table>';
	}

	/**
	 * Render a single product as an email-safe card row.
	 *
	 * Table-based with inline styles throughout: Outlook's Word engine ignores
	 * float, flex and most external CSS, so a two-cell row is the only layout
	 * that survives across clients.
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return string
	 */
	protected function render_card( $product ) {
		$data = ProductDataFetcher::get_product_data( $product->get_id() );
		if ( ! is_array( $data ) ) {
			return '';
		}

		$title = isset( $data['title'] ) ? (string) $data['title'] : '';
		if ( '' === $title ) {
			return '';
		}

		$link        = isset( $data['buttonLink'] ) ? (string) $data['buttonLink'] : '';
		$image       = isset( $data['imageSrc'] ) ? (string) $data['imageSrc'] : '';
		$description = isset( $data['description'] ) ? (string) $data['description'] : '';
		$price       = isset( $data['price'] ) ? (string) $data['price'] : '';

		$title_html = esc_html( $title );
		if ( '' !== $link ) {
			$title_html = '<a href="' . esc_url( $link ) . '" style="color:#111827;text-decoration:none;">'
				. $title_html . '</a>';
		}

		$image_cell = '';
		if ( '' !== $image ) {
			$image_html = '<img src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" width="96"'
				. ' style="display:block;width:96px;max-width:96px;height:auto;border:0;border-radius:4px;" />';

			if ( '' !== $link ) {
				$image_html = '<a href="' . esc_url( $link ) . '" style="text-decoration:none;">' . $image_html . '</a>';
			}

			$image_cell = '<td width="96" valign="top" style="padding:0 12px 0 0;width:96px;">'
				. $image_html . '</td>';
		}

		$body = '<div style="font-size:15px;font-weight:600;line-height:1.3;color:#111827;">'
			. $title_html . '</div>';

		if ( '' !== $description ) {
			$body .= '<div style="font-size:13px;line-height:1.5;color:#6B7280;padding-top:4px;">'
				. esc_html( $description ) . '</div>';
		}

		if ( '' !== $price ) {
			// get_price_html() returns markup (<del>/<ins> for sale prices), so
			// it is filtered rather than escaped, which would print the tags.
			$body .= '<div style="font-size:14px;font-weight:600;color:#111827;padding-top:6px;">'
				. wp_kses_post( $price ) . '</div>';
		}

		return '<tr><td style="padding:0 0 16px 0;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="border-collapse:collapse;width:100%;">'
			. '<tr>'
			. $image_cell
			. '<td valign="top" style="padding:0;">' . $body . '</td>'
			. '</tr>'
			. '</table>'
			. '</td></tr>';
	}
}

MergeTagsManager::instance()->register( new OrderCrossSellHtml() );
