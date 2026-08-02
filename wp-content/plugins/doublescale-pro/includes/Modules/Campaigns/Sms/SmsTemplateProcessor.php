<?php
/**
 * SMS campaign template processor (Pro).
 *
 * @package DoubleScale\Pro\Modules\Campaigns\Sms
 */

namespace DoubleScale\Pro\Modules\Campaigns\Sms;

use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Campaigns\Services\Abstract_Template_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * SMS template processor.
 */
class SmsTemplateProcessor extends Abstract_Template_Processor {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( CampaignChannel::STR_SMS );
	}

	/**
	 * Get default template name
	 *
	 * @return string
	 */
	public function get_default_name() {
		return __( 'Sms Campaign Template', 'doublescale' );
	}

	/**
	 * Get default Sms template body
	 *
	 * @return string
	 */
	protected function get_default_body() {
		return 'Hi {{contact:first_name}}, thank you for subscribing! Reply STOP to unsubscribe.';
	}
}
