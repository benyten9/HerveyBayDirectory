<?php
/**
 * Shared base for credit note automation rules (conditions).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\CreditNote;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;
use DoubleScale\Pro\Modules\CreditNotes\Rest\CreditNoteShaper;

defined( 'ABSPATH' ) || exit;

abstract class BaseCreditNoteRule extends Rule {

	/**
	 * @var string
	 */
	public $group = 'credit_note';

	/**
	 * @var array
	 */
	public $required_triggers = array(
		'credit_note_sent',
		'credit_note_applied',
	);

	/**
	 * @param object $automation_contact Automation contact model.
	 * @return CreditNoteModel|null
	 */
	protected function resolve_credit_note( $automation_contact ): ?CreditNoteModel {
		if ( ! self::storage_ready() ) {
			return null;
		}

		$credit_note_id = isset( $automation_contact->data['credit_note_id'] )
			? (int) $automation_contact->data['credit_note_id']
			: 0;

		if ( $credit_note_id <= 0 ) {
			return null;
		}

		$credit_note = CreditNoteModel::find( $credit_note_id );
		return $credit_note instanceof CreditNoteModel ? $credit_note : null;
	}

	/**
	 * @param CreditNoteModel $credit_note Credit note.
	 * @return float
	 */
	protected function remaining_credit( CreditNoteModel $credit_note ): float {
		return CreditNoteShaper::remaining( $credit_note );
	}

	/**
	 * @param Rule $rule Rule instance.
	 */
	public static function register( Rule $rule ): void {
		AutomationModuleStorage::register_rule( $rule, 'credit_notes', CreditNoteModel::class );
	}

	/**
	 * Whether credit note storage is safe to query.
	 */
	protected static function storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'credit_notes', CreditNoteModel::class );
	}
}
