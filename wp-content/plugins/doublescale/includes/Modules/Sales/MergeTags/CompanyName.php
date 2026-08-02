<?php
/**
 * Company (site) name merge tag for sales emails.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * CompanyName merge tag.
 */
class CompanyName extends AbstractSalesMergeTag {

	public $name = 'Company Name';

	public $slug = 'company_name';

	public $description = 'Your WordPress site name.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		unset( $contact, $merge_tag );
		return (string) get_bloginfo( 'name' );
	}
}

MergeTagsManager::instance()->register( new CompanyName() );
