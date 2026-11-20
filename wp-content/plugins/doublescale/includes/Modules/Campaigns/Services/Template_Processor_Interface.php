<?php
/**
 * Contract for the processors that save a campaign's templates.
 *
 * Declared inside CampaignTemplateFactory.php until the WhatsApp engine began
 * moving to Pro. Pro's processors implement it, so it has to load on its own.
 *
 * @package DoubleScale\Modules\Campaigns\Services
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

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
