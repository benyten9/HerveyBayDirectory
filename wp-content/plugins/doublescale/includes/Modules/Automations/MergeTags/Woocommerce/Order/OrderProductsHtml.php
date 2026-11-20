<?php
/**
 * Class Order Products HTML
 *
 * {{order:products_html}}: the products bought in the order as email-ready
 * cards — image and title, both linking to the product page.
 *
 * Kept apart from {@see OrderProducts}, which is plain text for subjects,
 * WhatsApp and SMS, where markup would print as tags.
 *
 * @since 1.7.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Order;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

// Merge-tag files are pulled in by a RecursiveDirectoryIterator whose order is
// filesystem-dependent, so the parent is not guaranteed to be loaded first.
require_once __DIR__ . '/OrderProducts.php';

/**
 * Order Products HTML Merge Tag
 */
class OrderProductsHtml extends OrderProducts {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Products (with images)';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'products_html';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Products bought in the order, each with its image and a link to it';

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
		$cards = '';

		foreach ( $this->get_products_for_contact( $contact ) as $product ) {
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
	 * Where the card's image and title link to.
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return string
	 */
	protected function get_card_url( $product ) {
		return (string) $product->get_permalink();
	}

	/**
	 * Extra line under the title. None here; the review variant adds its link.
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return string HTML.
	 */
	protected function render_card_footer( $product ) {
		return '';
	}

	/**
	 * Render a single product as an email-safe card row.
	 *
	 * Table-based with inline styles throughout, like the cross-sell cards:
	 * Outlook's Word engine ignores float, flex and most external CSS.
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return string
	 */
	protected function render_card( $product ) {
		$title = (string) $product->get_name();
		if ( '' === $title ) {
			return '';
		}

		$link     = $this->get_card_url( $product );
		$image_id = $product->get_image_id();
		$image    = $image_id ? wp_get_attachment_image_src( $image_id, 'woocommerce_thumbnail' ) : false;
		$image    = $image ? (string) $image[0] : '';

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
			. $title_html . '</div>'
			. $this->render_card_footer( $product );

		return '<tr><td style="padding:0 0 16px 0;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="border-collapse:collapse;width:100%;">'
			. '<tr>'
			. $image_cell
			. '<td valign="middle" style="padding:0;">' . $body . '</td>'
			. '</tr>'
			. '</table>'
			. '</td></tr>';
	}
}

MergeTagsManager::instance()->register( new OrderProductsHtml() );
