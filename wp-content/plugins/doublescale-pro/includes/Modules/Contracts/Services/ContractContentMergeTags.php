<?php
/**
 * Resolve merge tags inside contract HTML content.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;

/**
 * ContractContentMergeTags helper.
 */
final class ContractContentMergeTags {

	/**
	 * Replace {{group:slug}} placeholders in contract body content.
	 *
	 * @param ContractModel $contract Contract.
	 * @param string        $content  HTML or text with merge tags.
	 * @return string
	 */
	public static function resolve( ContractModel $contract, string $content ): string {
		if ( '' === $content || false === strpos( $content, '{{' ) ) {
			return $content;
		}

		return MergeTagsManager::instance()->process_merge_tags(
			$content,
			self::automation_context( $contract )
		);
	}

	/**
	 * Build an automation contact context for merge-tag resolution.
	 *
	 * @param ContractModel $contract Contract.
	 * @return AutomationContactModel
	 */
	public static function automation_context( ContractModel $contract ): AutomationContactModel {
		$contract->loadMissing( 'contact' );

		$context = new AutomationContactModel();
		$context->contact_id = (int) $contract->contact_id;
		$context->data       = array(
			'contract_id' => (int) $contract->id,
		);

		if ( $contract->contact instanceof ContactModel ) {
			$context->setRelation( 'contact', $contract->contact );
		}

		// Avoid a DB round-trip when resolving document content.
		$context->setRelation( 'contract', $contract );

		return $context;
	}
}
