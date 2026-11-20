<?php
/**
 * Saves a WhatsApp campaign's templates — Pro's copy of free's
 * WhatsApp_Template_Processor, used while Pro's engine owns WhatsApp.
 *
 * Free's CampaignTemplateFactory asks the engine that owns a channel for its
 * processor (WhatsappProcessing::get_template_processor()), which only a free
 * 1.7.0+ does — and from 1.7.0 the base class below loads on its own.
 *
 * @package DoubleScale\Pro\Modules\Campaigns\Whatsapp
 */

namespace DoubleScale\Pro\Modules\Campaigns\Whatsapp;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Campaigns\Services\Abstract_Template_Processor;

/**
 * WhatsappTemplateProcessor.
 */
class WhatsappTemplateProcessor extends Abstract_Template_Processor {

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
