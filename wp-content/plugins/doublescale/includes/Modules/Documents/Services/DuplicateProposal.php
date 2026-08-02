<?php
/**
 * Duplicate a proposal as a new draft.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\DocumentTemplate;
use DoubleScale\Modules\Documents\Constants\DocumentTemplateColor;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Sales\Services\SalesNumbering;

/**
 * DuplicateProposal service.
 */
final class DuplicateProposal {

	/**
	 * @param ProposalModel $source Source proposal.
	 * @return ProposalModel
	 */
	public function duplicate( ProposalModel $source ): ProposalModel {
		$copy = new ProposalModel();
		$copy->fill(
			array(
				'subject'          => sprintf(
					/* translators: %s: original subject */
					__( 'Copy of %s', 'doublescale' ),
					(string) $source->subject
				),
				'status'           => ProposalStatus::DRAFT,
				'template'         => DocumentTemplate::normalize( $source->template ?? DocumentTemplate::DEFAULT ),
				'template_color'   => DocumentTemplateColor::normalize( $source->template_color ?? null ),
				'contact_id'       => (int) $source->contact_id,
				'assigned_user_id' => $source->assigned_user_id ? (int) $source->assigned_user_id : null,
				'date'             => current_time( 'Y-m-d' ),
				'open_till'        => $source->open_till,
				'currency'         => (string) $source->currency,
				'discount_type'    => (string) $source->discount_type,
				'discount_value'   => (float) $source->discount_value,
				'line_items'       => is_array( $source->line_items ) ? $source->line_items : array(),
				'adjustment'       => (float) $source->adjustment,
				'to_name'          => $source->to_name,
				'address'          => $source->address,
				'city'             => $source->city,
				'state'            => $source->state,
				'country'          => $source->country,
				'zip'              => $source->zip,
				'email'            => $source->email,
				'phone'            => $source->phone,
			)
		);
		SalesNumbering::save_with_retry( $copy );
		return $copy->fresh( array( 'contact', 'assigned_user' ) );
	}
}
