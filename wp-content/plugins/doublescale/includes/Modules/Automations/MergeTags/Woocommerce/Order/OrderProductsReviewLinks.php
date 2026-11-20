<?php
/**
 * Class Order Products Review Links
 *
 * {{order:products_review_links}}: "Hoodie: https://…/hoodie/#reviews, Cap: …"
 * for WhatsApp and SMS, where a message cannot carry cards.
 *
 * One line on purpose: Meta rejects a template variable that contains a new
 * line, so a list with line breaks would fail every WhatsApp send.
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
 * Order Products Review Links Merge Tag
 */
class OrderProductsReviewLinks extends OrderProducts {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Products Review Links';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'products_review_links';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Each product bought with its review link, as text (for WhatsApp and SMS)';

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
		$links = array();

		foreach ( $this->get_products_for_contact( $contact ) as $product ) {
			$url = $this->get_review_url( $product );
			if ( '' === $url ) {
				continue;
			}

			// Product names can hold line breaks or tabs; WhatsApp refuses both.
			$name    = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $product->get_name() ) ) );
			$links[] = $name . ': ' . $url;
		}

		return implode( ', ', $links );
	}
}

MergeTagsManager::instance()->register( new OrderProductsReviewLinks() );
