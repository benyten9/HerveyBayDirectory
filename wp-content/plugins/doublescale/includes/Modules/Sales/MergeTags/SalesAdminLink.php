<?php
/**
 * Admin CRM link merge tag for sales-rep notifications.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * SalesAdminLink merge tag.
 */
class SalesAdminLink extends AbstractSalesMergeTag {

	public $name = 'Sales Admin Link';

	public $slug = 'admin_link';

	public $description = 'Link to open the document in the DoubleScale admin.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		unset( $merge_tag );
		if ( ! $contact instanceof AutomationContactModel ) {
			return '';
		}

		return (string) ( $contact->data['sales_admin_link'] ?? '' );
	}
}

MergeTagsManager::instance()->register( new SalesAdminLink() );
