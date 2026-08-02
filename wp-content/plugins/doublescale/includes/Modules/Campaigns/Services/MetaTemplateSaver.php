<?php
/**
 * Meta WhatsApp Template Saver
 *
 * Saves Meta WhatsApp templates to local database
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Core\Constants\CampaignChannel;

defined( 'ABSPATH' ) || exit;

/**
 * MetaTemplateSaver class
 */
class MetaTemplateSaver {

	/**
	 * Save template to database on first use
	 * If already exists (by external_id), returns existing template
	 *
	 * @param array $template_data Template data from MetaTemplateFetcher.
	 *
	 * @return TemplateModel
	 */
	public function save_on_use( array $template_data ): TemplateModel {
		$settings    = is_array( $template_data['settings'] ?? null ) ? $template_data['settings'] : array();
		$external_id = $settings['external_id'] ?? ( $template_data['sid'] ?? '' );

		// Persist external_id into settings so downstream callers (e.g. is_whatsapp_business_template,
		// get_whatsapp_content_sid) can read it. Callers may pass sid as a top-level key without
		// also nesting it in settings.
		if ( ! empty( $external_id ) ) {
			$settings['external_id'] = $external_id;
		}

		// Carry through variables/language from the request when settings doesn't already carry them
		// - these are part of the template's identity and several consumers depend on settings.variables.
		if ( ! isset( $settings['variables'] ) && isset( $template_data['variables'] ) ) {
			$settings['variables'] = $template_data['variables'];
		}
		if ( ! isset( $settings['language'] ) && ! empty( $template_data['language'] ) ) {
			$settings['language'] = $template_data['language'];
		}

		// Check if already exists by external_id
		$existing = $this->find_by_external_id( $external_id );

		if ( $existing ) {
			doublescale_get_logger()->debug(
				'Meta WhatsApp template already exists in database',
				array(
					'template_id' => $existing->id,
					'name'        => $existing->name,
					'external_id' => $external_id,
					'code'        => 'meta_whatsapp_template_exists',
				)
			);
			return $existing;
		}

		// Create new template record
		$template = TemplateModel::create(
			array(
				'name'     => $template_data['name'],
				'type'     => CampaignChannel::CHANNEL_WHATSAPP,
				'category' => 'whatsapp_business',
				'body'     => $template_data['body'],
				'settings' => array_merge(
					$settings,
					array(
						'saved_at' => current_time( 'mysql' ),
					)
				),
				'hidden'   => 0,
				'is_pro'   => 1,
			)
		);

		doublescale_get_logger()->info(
			'Meta WhatsApp template saved on first use',
			array(
				'template_id' => $template->id,
				'name'        => $template->name,
				'external_id' => $external_id,
				'code'        => 'meta_whatsapp_template_saved',
			)
		);

		return $template;
	}

	/**
	 * Find template by external_id
	 *
	 * @param string $external_id External ID (template_name:language).
	 *
	 * @return TemplateModel|null
	 */
	private function find_by_external_id( string $external_id ): ?TemplateModel {
		// Query templates with whatsapp_business category
		$templates = TemplateModel::where( 'category', 'whatsapp_business' )
			->where( 'type', CampaignChannel::CHANNEL_WHATSAPP )
			->get();

		foreach ( $templates as $template ) {
			$template_external_id = $template->get_setting( 'external_id' );
			if ( $template_external_id === $external_id ) {
				return $template;
			}
		}

		return null;
	}

	/**
	 * Update variable mappings for a template
	 *
	 * @param TemplateModel $template          Template model.
	 * @param array         $variable_mappings Variable mappings {"1": "{{contact:first_name}}", ...}.
	 *
	 * @return TemplateModel
	 */
	public function update_variable_mappings( TemplateModel $template, array $variable_mappings ): TemplateModel {
		$settings                      = $template->settings ?? array();
		$settings['variable_mappings'] = $variable_mappings;
		$template->settings            = $settings;
		$template->save();

		doublescale_get_logger()->debug(
			'Updated Meta WhatsApp template variable mappings',
			array(
				'template_id' => $template->id,
				'mappings'    => $variable_mappings,
				'code'        => 'meta_whatsapp_template_mappings_updated',
			)
		);

		return $template;
	}

	/**
	 * Sync templates from Meta Api to local database
	 *
	 * @return array Result with counts.
	 * @throws \Exception If fetch fails.
	 */
	public function sync_from_meta(): array {
		$fetcher   = new MetaTemplateFetcher();
		$templates = $fetcher->fetch_approved_templates();

		$created = 0;
		$updated = 0;
		$skipped = 0;

		foreach ( $templates as $template_data ) {
			$external_id = $template_data['settings']['external_id'];
			$existing    = $this->find_by_external_id( $external_id );

			if ( $existing ) {
				// Update existing template
				$existing->body     = $template_data['body'];
				$existing->name     = $template_data['name'];
				$settings           = $existing->settings ?? array();
				$settings           = array_merge( $settings, $template_data['settings'] );
				$existing->settings = $settings;
				$existing->save();
				++$updated;
			} else {
				// Create new template
				$this->save_on_use( $template_data );
				++$created;
			}
		}

		doublescale_get_logger()->info(
			'Synced Meta WhatsApp templates from Api',
			array(
				'created' => $created,
				'updated' => $updated,
				'total'   => count( $templates ),
				'code'    => 'meta_whatsapp_templates_synced',
			)
		);

		return array(
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
			'total'   => count( $templates ),
		);
	}
}
