<?php
/**
 * Assign Contact Owner Action
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Actions;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Contacts\Services\LeadRouter;

/**
 * Give the contact an owner: the workflow's owner, the next person in the
 * round-robin, or a specific team member.
 *
 * The action acts with the rights of the person who built the workflow: a
 * workflow built by an agent can only assign contacts to that agent, the
 * same way an agent can't hand contacts to colleagues anywhere else.
 */
class AssignContactOwner extends Action {

	public const PICK_WORKFLOW_OWNER = 'workflow_owner';
	public const PICK_ROUND_ROBIN    = 'round_robin';

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Assign Contact Owner';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'assign_contact_owner';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will assign an owner to the contact.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

	/**
	 * Trigger Group
	 *
	 * @var string
	 */
	public $group = 'contact';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Process Action
	 *
	 * @param AutomationModel        $automation         Automation Model.
	 * @param AutomationStepModel    $step               Automation Step Model.
	 * @param AutomationContactModel $automation_contact Contact Model.
	 * @return bool
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$contact = $automation_contact->contact;
		if ( ! $contact ) {
			return true;
		}

		$current = (int) ( $contact->owner_id ?? 0 );
		if ( $current > 0 && $this->is_on( $step->get_setting( 'only_unassigned', true ) ) ) {
			return true;
		}

		$owner = self::resolve_owner( $automation, (string) $step->get_setting( 'owner', self::PICK_WORKFLOW_OWNER ) );
		if ( null === $owner || $owner === $current ) {
			return true;
		}

		$contact->owner_id = $owner;
		$contact->save();

		/** This action is documented in RestContactController::flush_owner_change(). */
		do_action( 'doublescale_contact_owner_changed', (int) $contact->id, $current, $owner );

		return true;
	}

	/**
	 * Who the step assigns the contact to, within the workflow owner's rights.
	 *
	 * @param AutomationModel $automation Automation.
	 * @param string          $pick       Step setting: workflow_owner, round_robin, or a user as "user_<id>" (a bare id also works).
	 * @return int|null Null when nobody valid can be picked.
	 */
	public static function resolve_owner( AutomationModel $automation, string $pick ): ?int {
		$creator = (int) ( $automation->created_by ?? 0 );

		// An agent's workflow only ever assigns to that agent.
		if ( $creator > 0 && Permissions::is_agent_tier( $creator ) ) {
			return LeadRouter::is_valid_owner( $creator ) ? $creator : null;
		}

		if ( self::PICK_WORKFLOW_OWNER === $pick ) {
			return ( $creator > 0 && LeadRouter::is_valid_owner( $creator ) ) ? $creator : null;
		}

		if ( self::PICK_ROUND_ROBIN === $pick ) {
			return LeadRouter::next_round_robin();
		}

		$user_id = (int) ( 0 === strpos( $pick, 'user_' ) ? substr( $pick, 5 ) : $pick );

		return ( $user_id > 0 && LeadRouter::is_valid_owner( $user_id ) ) ? $user_id : null;
	}

	/**
	 * @param mixed $value Toggle value as stored.
	 * @return bool
	 */
	private function is_on( $value ): bool {
		return ! in_array( $value, array( false, 0, '0', 'false', '', null ), true );
	}

	/**
	 * Get fields. Built for the person looking at the builder: an agent is
	 * only offered themselves.
	 *
	 * @return array
	 */
	public function get_fields() {
		$options = array(
			self::PICK_WORKFLOW_OWNER => __( "The workflow's owner", 'doublescale' ),
		);

		if ( ! Permissions::is_agent_tier() ) {
			$options[ self::PICK_ROUND_ROBIN ] = __( 'Next in the round-robin (Team settings)', 'doublescale' );

			$users = get_users(
				array(
					'role__in' => array_merge( \DoubleScale\Core\UserRoles\UserRoles::get_known_role_slugs(), array( 'administrator' ) ),
					'orderby'  => 'display_name',
					'order'    => 'ASC',
					'fields'   => array( 'ID', 'display_name' ),
				)
			);
			// "user_<id>" keeps the keywords first: JavaScript sorts numeric
			// object keys ahead of every other key.
			foreach ( $users as $user ) {
				$options[ 'user_' . $user->ID ] = $user->display_name;
			}
		}

		return array(
			'owner'           => array(
				'type'     => 'select',
				'label'    => __( 'Assign to', 'doublescale' ),
				'required' => true,
				'default'  => self::PICK_WORKFLOW_OWNER,
				'options'  => $options,
				'help'     => Permissions::is_agent_tier()
					? __( 'Contacts are assigned to you: agents can only assign contacts to themselves.', 'doublescale' )
					: __( "The workflow's owner is the person who created this workflow.", 'doublescale' ),
			),
			'only_unassigned' => array(
				'type'    => 'toggle',
				'label'   => __( 'Only if the contact has no owner', 'doublescale' ),
				'default' => true,
				'help'    => __( 'Leave contacts that already have an owner as they are.', 'doublescale' ),
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'owner'           => array(
					'type'     => 'string',
					'required' => true,
				),
				'only_unassigned' => array(
					'type' => 'boolean',
				),
			),
		);
	}
}

AssignContactOwner::instance();
