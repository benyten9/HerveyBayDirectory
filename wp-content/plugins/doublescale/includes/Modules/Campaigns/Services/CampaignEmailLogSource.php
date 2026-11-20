<?php
/**
 * Builds SMTP email log context so outbound sends from campaigns can link back in admin.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

/**
 * Campaign → SMTP email log metadata (crm_source for EmailLogHandler context).
 */
class CampaignEmailLogSource {

	/**
	 * Context fragment for any tracked send, whatever created it.
	 *
	 * Automations reuse the campaign pipeline through a throw-away
	 * CampaignModel that is not in the database, so the tracking record — not
	 * the campaign — is what identifies the source. Returns an empty array
	 * when the source cannot be identified; the caller then logs as usual.
	 *
	 * @param CommunicationTrackingModel $tracking Tracking record for this send.
	 * @return array<string, mixed>
	 */
	public static function for_tracking( CommunicationTrackingModel $tracking ) {
		if ( (int) $tracking->source_type === MessageSourceTypes::AUTOMATION ) {
			return self::for_automation( (int) $tracking->source_id );
		}

		$campaign = $tracking->get_campaign();

		return $campaign instanceof CampaignModel ? self::for_campaign( $campaign ) : array();
	}

	/**
	 * Context fragment for an automation send.
	 *
	 * @param int $automation_id Automation the send belongs to.
	 * @return array<string, mixed>
	 */
	public static function for_automation( $automation_id ) {
		$automation_id = (int) $automation_id;
		$automation    = $automation_id ? AutomationModel::find( $automation_id ) : null;
		$name          = $automation && isset( $automation->name ) ? trim( (string) $automation->name ) : '';
		if ( '' === $name ) {
			$name = sprintf(
				/* translators: %d: automation id */
				__( 'Automation #%d', 'doublescale' ),
				$automation_id
			);
		}

		return array(
			'crm_source' => array(
				'kind'  => 'automation',
				'label' => $name,
				'path'  => 'automations/' . $automation_id,
			),
		);
	}

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
