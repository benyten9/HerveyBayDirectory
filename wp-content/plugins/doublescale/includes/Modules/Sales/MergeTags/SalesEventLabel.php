<?php
/**
 * Sales rep notification event label merge tag.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;

/**
 * SalesEventLabel merge tag.
 */
class SalesEventLabel extends AbstractSalesMergeTag {

	public $name = 'Event Label';

	public $slug = 'event_label';

	public $description = 'Human-readable label for the sales notification event.';

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

		$event       = (string) ( $contact->data['rep_event'] ?? '' );
		$subcategory = (string) ( $contact->data['rep_subcategory'] ?? '' );

		$proposal_labels = array(
			'sent'     => __( 'Proposal sent to customer', 'doublescale' ),
			'accepted' => __( 'Proposal accepted by customer', 'doublescale' ),
			'declined' => __( 'Proposal declined by customer', 'doublescale' ),
		);
		$contract_labels = array(
			'sent'   => __( 'Contract sent to customer', 'doublescale' ),
			'signed' => __( 'Contract signed by customer', 'doublescale' ),
		);

		if ( in_array( $subcategory, array( NotificationCategories::SALES_PROPOSAL_SENT, NotificationCategories::SALES_PROPOSAL_ACCEPTED, NotificationCategories::SALES_PROPOSAL_DECLINED ), true ) ) {
			return $proposal_labels[ $event ] ?? __( 'Proposal update', 'doublescale' );
		}

		if ( in_array( $subcategory, array( NotificationCategories::SALES_CONTRACT_SENT, NotificationCategories::SALES_CONTRACT_SIGNED ), true ) ) {
			return $contract_labels[ $event ] ?? __( 'Contract update', 'doublescale' );
		}

		return '';
	}
}

MergeTagsManager::instance()->register( new SalesEventLabel() );
