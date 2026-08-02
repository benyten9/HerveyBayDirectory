<?php
/**
 * Builds SMTP email log context so outbound sends from campaigns can link back in admin.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Campaigns\Models\CampaignModel;

/**
 * Campaign → SMTP email log metadata (crm_source for EmailLogHandler context).
 */
class CampaignEmailLogSource {

	/**
	 * Context fragment for {@see \DoubleScale\Modules\Smtp\EmailLog\EmailLogContext::push()}.
	 *
	 * @param CampaignModel $campaign Campaign being processed (broadcast, sequence parent, or sequence step).
	 * @return array<string, mixed>
	 */
	public static function for_campaign( CampaignModel $campaign ) {
		$name = isset( $campaign->name ) ? (string) $campaign->name : '';
		if ( '' === trim( $name ) ) {
			$name = sprintf(
				/* translators: %d: campaign id */
				__( 'Campaign #%d', 'doublescale' ),
				(int) $campaign->id
			);
		}

		if ( $campaign->is_email_sequence() ) {
			return array(
				'crm_source' => array(
					'kind'  => 'email_sequence',
					'label' => $name,
					'path'  => 'email-sequences/' . (int) $campaign->id,
				),
			);
		}

		if ( $campaign->is_sequence_mail() ) {
			$parent_id = (int) $campaign->parent_id;
			$label     = $name;
			if ( $parent_id ) {
				$parent = CampaignModel::find( $parent_id );
				if ( $parent && isset( $parent->name ) && '' !== trim( (string) $parent->name ) ) {
					$label = (string) $parent->name . ' → ' . $name;
				}
			}
			$path_id = $parent_id ? $parent_id : (int) $campaign->id;

			return array(
				'crm_source' => array(
					'kind'  => 'sequence_mail',
					'label' => $label,
					'path'  => 'email-sequences/' . $path_id,
				),
			);
		}

		return array(
			'crm_source' => array(
				'kind'  => 'campaign',
				'label' => $name,
				'path'  => 'campaigns/' . (int) $campaign->id . '/overview',
			),
		);
	}
}
