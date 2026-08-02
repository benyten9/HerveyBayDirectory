<?php
/**
 * WhatsApp Template Preparation Trait
 * Shared methods for preparing WhatsApp template messages.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Traits;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Trait WhatsappTemplatePreparation
 *
 * Provides common methods for preparing WhatsApp template messages. Mirrors the
 * corresponding methods on Free's Campaigns\Campaign\WhatsappProcessing so
 * individual-send and campaign-send paths share identical template prep semantics.
 *
 * Used by: WhatsappIndividualSender.
 *
 * @since 1.0.0
 */
trait WhatsappTemplatePreparation {

	/**
	 * Prepare WhatsApp template message data.
	 *
	 * @param TemplateModel              $template           Template with WhatsApp business content.
	 * @param ContactModel               $contact            Contact for merge tag processing.
	 * @param array                      $template_variables Variable mappings (slot => value or merge tag).
	 * @param CommunicationTrackingModel $tracking_entry     Tracking record to store metadata.
	 * @param bool                       $encode_as_json     Whether to JSON-encode ContentVariables.
	 * @return array Message data with ContentSid and ContentVariables.
	 * @throws \Exception If template is missing ContentSid.
	 */
	protected function prepare_whatsapp_template_data(
		TemplateModel $template,
		ContactModel $contact,
		array $template_variables,
		CommunicationTrackingModel $tracking_entry,
		bool $encode_as_json = false
	): array {
		$content_sid = $template->get_whatsapp_content_sid();

		if ( empty( $content_sid ) ) {
			throw new \Exception( esc_html__( 'Whatsapp Business template missing ContentSid', 'doublescale' ) );
		}

		$content_variables = $this->process_template_variables( $template_variables, $contact );

		if ( ! empty( $content_variables ) ) {
			CommunicationTrackingMetaModel::store_whatsapp_template_params(
				$tracking_entry->id,
				$content_variables
			);
		}

		$this->capture_merge_tag_values( $template_variables, $tracking_entry, $contact );

		return array(
			'ContentSid'       => $content_sid,
			'ContentVariables' => $encode_as_json && ! empty( $content_variables )
				? wp_json_encode( $content_variables )
				: $content_variables,
		);
	}

	/**
	 * Process template variables through merge tags.
	 *
	 * @param array        $template_variables Variable mappings (slot => value/merge tag).
	 * @param ContactModel $contact            Contact for merge tag processing.
	 * @return array Processed variables with merge tags replaced.
	 */
	protected function process_template_variables( array $template_variables, ContactModel $contact ): array {
		$content_variables = array();

		foreach ( $template_variables as $slot => $value ) {
			$processed_value                     = MergeTagsManager::instance()
				->process_merge_tags( $value, $contact );
			$content_variables[ (string) $slot ] = $processed_value;
		}

		return $content_variables;
	}

	/**
	 * Capture merge tag values for historical tracking.
	 *
	 * @param array                      $template_variables Variable mappings.
	 * @param CommunicationTrackingModel $tracking_entry     Tracking record.
	 * @param ContactModel               $contact            Contact model.
	 * @return void
	 */
	protected function capture_merge_tag_values(
		array $template_variables,
		CommunicationTrackingModel $tracking_entry,
		ContactModel $contact
	): void {
		if ( empty( $template_variables ) ) {
			return;
		}

		$combined_values = implode( ' ', $template_variables );
		$merge_tag_keys  = MergeTagsManager::instance()->extract_merge_tag_keys( $combined_values );

		if ( ! empty( $merge_tag_keys ) ) {
			CommunicationTrackingMetaModel::capture_merge_tags_from_keys(
				$tracking_entry->id,
				$merge_tag_keys,
				$contact
			);
		}
	}
}
