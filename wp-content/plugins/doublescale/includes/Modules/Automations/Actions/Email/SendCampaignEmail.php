<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Email;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * SendCampaignEmail action stub.
 */
class SendCampaignEmail extends ProAutomationStubAction {

	public $name = 'Send Campaign Email';

	public $slug = 'send_campaign_email';

	public $description = 'This action will send a campaign email to the contact.';

	public $source = 'email';

	public $group = 'email';
}

SendCampaignEmail::instance();
