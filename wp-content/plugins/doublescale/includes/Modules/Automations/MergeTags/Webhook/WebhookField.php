<?php
/**
 * {{webhook:field:<path>}} — a value from the payload that started the workflow.
 *
 * `{{webhook:field:email}}`, `{{webhook:field:data.lead.phone}}`,
 * `{{webhook:field:answers.0.value}}`. Values from other apps are written
 * as plain text.
 *
 * @package DoubleScale\Modules\Automations\MergeTags\Webhook
 */

namespace DoubleScale\Modules\Automations\MergeTags\Webhook;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Services\WebhookPayload;

/**
 * Webhook payload field merge tag (dynamic slug prefix `field:`).
 */
class WebhookField extends MergeTag {

	/** @var string */
	public $name = 'Webhook field';

	/** @var string */
	public $slug = 'field:';

	/** @var string */
	public $description = 'A value from the webhook, by its path, e.g. {{webhook:field:data.email}}';

	/** @var string */
	public $group = 'webhook';

	/** @var bool */
	public $plain_text = true;

	/**
	 * @param mixed  $contact   Workflow run.
	 * @param string $merge_tag Full slug, e.g. `field:data.email`.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$path = (string) preg_replace( '/^field:/', '', (string) $merge_tag );
		if ( '' === $path ) {
			return '';
		}
		return WebhookPayload::to_text( WebhookPayload::get( WebhookPayload::for_run( $contact ), $path ) );
	}
}

MergeTagsManager::instance()->register( new WebhookField() );
