<?php
/**
 * Class Order Products Review HTML
 *
 * {{order:products_review_html}}: the products bought in the order as email
 * cards that ask for a review — image and title open the product's Reviews
 * tab, with a "Leave a review" link under the title.
 *
 * @since 1.7.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Order;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;

// Merge-tag files are pulled in by a RecursiveDirectoryIterator whose order is
// filesystem-dependent, so the parent is not guaranteed to be loaded first.
require_once __DIR__ . '/OrderProductsHtml.php';

/**
 * Order Products Review HTML Merge Tag
 */
class OrderProductsReviewHtml extends OrderProductsHtml {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Products Review Links (with images)';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'products_review_html';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Products bought in the order, each with its image and a link to leave a review';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Image and title open the Reviews tab.
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return string
	 */
	protected function get_card_url( $product ) {
		return $this->get_review_url( $product );
	}

	/**
	 * "Leave a review", only where the product takes reviews — on a product
	 * with reviews closed the link would promise something the page lacks.
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return string HTML.
	 */
	protected function render_card_footer( $product ) {
		if ( ! $this->accepts_reviews( $product ) ) {
			return '';
		}

		return '<div style="padding-top:6px;font-size:14px;line-height:1.4;">'
			. '<a href="' . esc_url( $this->get_review_url( $product ) ) . '" style="color:#2563EB;text-decoration:underline;">'
			. esc_html__( 'Leave a review', 'doublescale' )
			. '</a></div>';
	}
}

MergeTagsManager::instance()->register( new OrderProductsReviewHtml() );
