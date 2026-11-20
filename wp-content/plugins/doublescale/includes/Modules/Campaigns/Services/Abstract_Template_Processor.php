<?php
/**
 * Shared base for the processors that save a campaign's templates.
 *
 * Pro's SMS processor extends it, and so will Pro's WhatsApp processor once
 * the engine moves. Declared inside CampaignTemplateFactory.php it only
 * existed once the factory happened to be loaded, so it lives here now.
 *
 * @package DoubleScale\Modules\Campaigns\Services
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Core\Constants\CampaignChannel;

/**
 * Abstract Template Processor
 */
abstract class Abstract_Template_Processor implements Template_Processor_Interface {

	/**
	 * Campaign type
	 *
	 * @var string
	 */
	protected $campaign_type;

	/**
	 * Constructor
	 *
	 * @param string $campaign_type Campaign type (email, sms, whatsapp)
	 */
	public function __construct( $campaign_type ) {
		$this->campaign_type = $campaign_type;
	}

	/**
	 * Process template data
	 *
	 * @param array  $template_data Template data
	 * @param string $campaign_status Campaign status (draft, scheduled, etc.)
	 * @return TemplateModel|null
	 */
	public function process( array $template_data, $campaign_status = 'draft' ) {
		if ( ! $this->validate( $template_data, $campaign_status ) ) {
			return null;
		}

		$template_id = $template_data['template_id'] ?? null;
		$hidden      = $template_data['hidden'] ?? 1;

		// If campaign is NOT draft, create new template to preserve original
		// Only update existing template if campaign is draft
		if ( $campaign_status !== 'draft' ) {
			$template_id = null; // Force creation of new template
		}

		// A Campaign Agent's campaign never changes a template someone else
		// made: it gets its own copy.
		if ( $template_id && ! \DoubleScale\Core\UserRoles\Permissions::can_manage_campaigns() ) {
			$existing = TemplateModel::find( $template_id );
			if ( $existing && (int) $existing->created_by !== get_current_user_id() ) {
				$template_id = null;
			}
		}

		$processed_data = $this->process_specific_data( $template_data );

		$template_attributes = array(
			'name'     => $processed_data['name'] ?: $this->get_default_name(),
			'type'     => $this->campaign_type,
			'subject'  => $processed_data['subject'] ?? '',
			'body'     => $processed_data['body'] ?? $this->get_default_body(),
			'settings' => $processed_data['settings'] ?? array(),
			'hidden'   => $hidden,
		);

		// An approved Meta template must keep the category that marks it as one.
		// Omitting it let the column default ('general') win, and
		// is_whatsapp_business_template() then reported false for a template the
		// operator had just picked from the approved list.
		$category = $template_data['category'] ?? null;
		if ( ! $category && ! empty( $processed_data['settings']['external_id'] ) ) {
			$category = 'whatsapp_business';
		}
		if ( $category ) {
			$template_attributes['category'] = $category;
		}

		return TemplateModel::createOrUpdate( $template_id, $template_attributes );
	}

	/**
	 * Process campaign-specific template data
	 * Uses centralized TemplateFieldMapper for consistent field mapping
	 *
	 * @param array $template_data Template data
	 * @return array Processed data with name, subject, body, settings
	 */
	protected function process_specific_data( array $template_data ) {
		// Use centralized field mapper
		$processed = TemplateFieldMapper::array_to_template( $template_data, $this->campaign_type );

		// Apply defaults if needed
		if ( empty( $processed['body'] ) ) {
			$processed['body'] = $this->get_default_body();
		}

		return $processed;
	}

	/**
	 * Get default template body
	 * Must be implemented by child classes
	 *
	 * @return string
	 */
	abstract protected function get_default_body();

	/**
	 * Basic validation - can be overridden by child classes
	 *
	 * @param array  $template_data Template data
	 * @param string $campaign_status Campaign status (draft, scheduled, etc.)
	 * @return bool
	 */
	public function validate( array $template_data, $campaign_status = 'draft' ) {
		if ( empty( $template_data ) ) {
			doublescale_get_logger()->error(
				'Template validation failed: Empty template data',
				array(
					'campaign_type' => $this->campaign_type,
					'code'          => 'template_validation_empty',
				)
			);
			return false;
		}

		// For Sms and WhatsApp, validate body content and length
		if ( in_array( $this->campaign_type, array( CampaignChannel::STR_SMS, CampaignChannel::STR_WHATSAPP ) ) ) {
			$body = $template_data['body'] ?? '';

			// Body is required
			if ( empty( trim( $body ) ) ) {
				doublescale_get_logger()->error(
					'Template validation failed: Empty body',
					array(
						'campaign_type' => $this->campaign_type,
						'template_data' => $template_data,
						'code'          => 'template_validation_empty_body',
					)
				);
				return false;
			}

			// Validate body length (max 1600 characters)
			if ( strlen( wp_strip_all_tags( $body ) ) > 1600 ) {
				doublescale_get_logger()->error(
					'Template validation failed: Body too long',
					array(
						'campaign_type' => $this->campaign_type,
						'body_length'   => strlen( wp_strip_all_tags( $body ) ),
						'code'          => 'template_validation_body_too_long',
					)
				);
				return false;
			}
		}

		return true;
	}
}
