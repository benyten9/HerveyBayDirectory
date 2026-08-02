<?php
/**
 * Automation rule: proposal number.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Proposal;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;

defined( 'ABSPATH' ) || exit;

class ProposalNumber extends BaseProposalRule {

	public $name = 'Proposal Number';

	public $slug = 'proposal_number';

	public $type = 'text';

	public function get_operators() {
		return array(
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'contains'     => __( 'Contains', 'doublescale' ),
			'not_contains' => __( 'Does not contain', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$proposal = $this->resolve_proposal( $automation_contact );
		return $proposal ? (string) $proposal->proposal_number : '';
	}

	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		return parent::is_met( $automation_contact, $rule );
	}
}

BaseProposalRule::register( new ProposalNumber() );
