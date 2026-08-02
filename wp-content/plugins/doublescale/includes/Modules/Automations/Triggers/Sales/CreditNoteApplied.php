<?php
/**
 * Pro automation trigger (free plugin): definition only.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Automations\Triggers\Sales;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * CreditNoteApplied trigger stub.
 */
class CreditNoteApplied extends TriggerPro {

	public $name = 'Credit note applied';

	public $slug = 'credit_note_applied';

	public $description = 'Fires when credit from a credit note is applied to an invoice.';

	public $attributes = array();

	public $source = 'sales';

	public $group = 'credit_notes';
}

TriggersManager::instance()->register( new CreditNoteApplied() );
