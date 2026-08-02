<?php

/**
 * Class Payment Gateway
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Pmpro;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Payment Gateway Merge Tag
 */
class PaymentGateway extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Payment Gateway';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'payment_gateway';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'pmpro';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$gateway = $contact->get_data( 'gateway' );

		return $gateway ? ucfirst( $gateway ) : '';
	}
}

MergeTagsManager::instance()->register( new PaymentGateway() );
