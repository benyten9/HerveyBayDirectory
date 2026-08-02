<?php
/**
 * Automation rule: proposal subject.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Proposal;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;

defined( 'ABSPATH' ) || exit;

class ProposalSubject extends BaseProposalRule {

	public $name = 'Proposal Subject';

	public $slug = 'proposal_subject';

	public $type = 'text';

	public function get_operators() {
		return array(
			'contains'     => __( 'Contains', 'doublescale' ),
			'not_contains' => __( 'Does not contain', 'doublescale' ),
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'starts_with'  => __( 'Starts with', 'doublescale' ),
			'ends_with'    => __( 'Ends with', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$proposal = $this->resolve_proposal( $automation_contact );
		return $proposal ? (string) $proposal->subject : '';
	}

	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		return parent::is_met( $automation_contact, $rule );
	}
}

BaseProposalRule::register( new ProposalSubject() );
