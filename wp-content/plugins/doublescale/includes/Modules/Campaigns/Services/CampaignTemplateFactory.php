<?php
/**
 * Campaign Template Factory
 * Factory pattern for creating and updating campaign templates
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Modules\Campaigns\Services\TemplateFieldMapper;
use DoubleScale\Core\Constants\CampaignChannel;

/**
 * CampaignTemplateFactory class
 */
class CampaignTemplateFactory {

	/**
	 * Class Instance.
	 *
	 * @since 1.0.0
	 *
	 * @var CampaignTemplateFactory
	 */
	private static $instance;

	/**
	 * CampaignTemplateFactory Instance.
	 *
	 * Instantiates or reuses an instance of CampaignTemplateFactory.
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @return self - Single instance
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Process templates data and create/update TemplateModel records
	 *
	 * @param array  $templates_data Array of template data
	 * @param string $campaign_type Campaign type (email, sms, whatsapp)
	 * @param string $campaign_status Campaign status (draft, scheduled, etc.)
	 * @return array Array of template IDs
	 */
	public function process_templates_data( $templates_data, $campaign_type, $campaign_status = 'draft' ) {
		if ( empty( $templates_data ) || ! is_array( $templates_data ) ) {
			doublescale_get_logger()->info(
				'Template processing: Empty or invalid templates_data',
				array(
					'templates_data' => $templates_data,
					'campaign_type'  => $campaign_type,
					'code'           => 'template_processing_empty_data',
				)
			);
			return array();
		}

		$template_ids = array();

		foreach ( $templates_data as $index => $template_data ) {
			doublescale_get_logger()->info(
				'Processing template',
				array(
					'index'           => $index,
					'template_data'   => $template_data,
					'campaign_type'   => $campaign_type,
					'campaign_status' => $campaign_status,
					'code'            => 'template_processing_start',
				)
			);

			$template_processor = $this->get_template_processor( $campaign_type );
			if ( ! $template_processor ) {
				doublescale_get_logger()->error(
					'Template processing failed: No processor for campaign type',
					array(
						'campaign_type' => $campaign_type,
						'code'          => 'template_processing_no_processor',
					)
				);
				continue;
			}
			$template = $template_processor->process( $template_data, $campaign_status );

			if ( $template ) {
				$template_ids[] = $template->id;
				doublescale_get_logger()->info(
					'Template processed successfully',
					array(
						'template_id' => $template->id,
						'code'        => 'template_processing_success',
					)
				);
			} else {
				doublescale_get_logger()->error(
					'Template processing failed',
					array(
						'template_data' => $template_data,
						'campaign_type' => $campaign_type,
						'code'          => 'template_processing_failed',
					)
				);
			}
		}

		return $template_ids;
	}

	/**
	 * Get template processor for campaign type
	 *
	 * @param string $campaign_type Campaign type
	 * @return Template_Processor_Interface|null
	 * @throws \InvalidArgumentException
	 */
	private function get_template_processor( $campaign_type ) {
		switch ( $campaign_type ) {
			case CampaignChannel::STR_EMAIL:
			case CampaignChannel::STR_EMAIL_SEQUENCE:
			case CampaignChannel::STR_SEQUENCE_MAIL:
				return new Email_Template_Processor();
			case CampaignChannel::STR_SMS:
				if ( class_exists( \DoubleScale\Pro\Modules\Campaigns\Sms\SmsTemplateProcessor::class ) ) {
					return new \DoubleScale\Pro\Modules\Campaigns\Sms\SmsTemplateProcessor();
				}
				return null;
			case CampaignChannel::STR_WHATSAPP:
				return new WhatsApp_Template_Processor();
			default:
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
				throw new \InvalidArgumentException( "Unsupported campaign type: {$campaign_type}" );
		}
	}
}

/**
 * Template Processor Interface
 */
interface Template_Processor_Interface {

	/**
	 * Process template data and create/update template
	 *
	 * @param array  $template_data Template data
	 * @param string $campaign_status Campaign status (draft, scheduled, etc.)
	 * @return TemplateModel|null
	 */
	public function process( array $template_data, $campaign_status = 'draft' );

	/**
	 * Get default template name
	 *
	 * @return string
	 */
	public function get_default_name();

	/**
	 * Validate template data
	 *
	 * @param array  $template_data Template data
	 * @param string $campaign_status Campaign status (draft, scheduled, etc.)
	 * @return bool
	 */
	public function validate( array $template_data, $campaign_status = 'draft' );
}

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

		$processed_data = $this->process_specific_data( $template_data );

		$template_attributes = array(
			'name'     => $processed_data['name'] ?: $this->get_default_name(),
			'type'     => $this->campaign_type,
			'subject'  => $processed_data['subject'] ?? '',
			'body'     => $processed_data['body'] ?? $this->get_default_body(),
			'settings' => $processed_data['settings'] ?? array(),
			'hidden'   => $hidden,
		);

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

/**
 * Email Template Processor
 */
class Email_Template_Processor extends Abstract_Template_Processor {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( CampaignChannel::STR_EMAIL );
	}

	/**
	 * Get default template name
	 *
	 * @return string
	 */
	public function get_default_name() {
		return __( 'Email Campaign Template', 'doublescale' );
	}

	/**
	 * Get default email template body
	 *
	 * @return string
	 */
	protected function get_default_body() {
		return '<div><p>Hi {{contact:first_name}} {{contact:last_name}},</p><p>Thank you for subscribing to our updates.</p><p>Don\'t want to stay in the loop? We\'ll be sad to see you go, but you can click here to <a href="{{contact:unsubscribe_link}}" target="_blank">unsubscribe</a>.</p></div>';
	}

	/**
	 * Validate email template data
	 *
	 * @param array  $template_data Template data
	 * @param string $campaign_status Campaign status (draft, scheduled, etc.)
	 * @return bool
	 */
	public function validate( array $template_data, $campaign_status = 'draft' ) {
		// Call parent validation first
		if ( ! parent::validate( $template_data, $campaign_status ) ) {
			doublescale_get_logger()->error(
				'Email template validation failed: Parent validation failed',
				array(
					'template_data' => $template_data,
					'code'          => 'email_template_validation_parent_failed',
				)
			);
			return false;
		}

		// Email templates require both subject and body
		// For draft campaigns, allow empty subject but log a warning
		if ( empty( $template_data['subject'] ) ) {
			if ( $campaign_status === 'draft' ) {
				doublescale_get_logger()->info(
					'Email template has empty subject (draft mode)',
					array(
						'template_data' => $template_data,
						'code'          => 'email_template_draft_empty_subject',
					)
				);
			} else {
				doublescale_get_logger()->error(
					'Email template validation failed: Empty subject',
					array(
						'template_data' => $template_data,
						'code'          => 'email_template_validation_empty_subject',
					)
				);
				return false;
			}
		}

		if ( empty( $template_data['body'] ) ) {
			doublescale_get_logger()->error(
				'Email template validation failed: Empty body',
				array(
					'template_data' => $template_data,
					'code'          => 'email_template_validation_empty_body',
				)
			);
			return false;
		}

		// Ensure from_name has a value, fall back to WordPress site name
		if ( empty( $template_data['settings']['from_name'] ) ) {
			$template_data['settings']['from_name'] = get_bloginfo( 'name' );
		}

		// Ensure from_email has a value, fall back to WordPress admin email
		if ( empty( $template_data['settings']['from_email'] ) ) {
			$template_data['settings']['from_email'] = get_option( 'admin_email' );
		}

		return true;
	}
}

/**
 * WhatsApp Template Processor
 */
class WhatsApp_Template_Processor extends Abstract_Template_Processor {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( CampaignChannel::STR_WHATSAPP );
	}

	/**
	 * Get default template name
	 *
	 * @return string
	 */
	public function get_default_name() {
		return __( 'Whatsapp Campaign Template', 'doublescale' );
	}

	/**
	 * Get default WhatsApp template body
	 *
	 * @return string
	 */
	protected function get_default_body() {
		return 'Hi {{contact:first_name}}, thank you for subscribing! Reply STOP to unsubscribe.';
	}
}
