<?php
/**
 * Contact Information Updated Trigger
 *
 * Fires when a contact's profile information is updated by an admin, by the
 * contact (portal), or when a linked WordPress user profile is updated.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use WP_User;

/**
 * ContactInformationUpdated trigger.
 */
class ContactInformationUpdated extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Contact Information Updated';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'contact_information_updated';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger fires when a contact\'s profile information is updated — from the DoubleScale contact editor, the client portal, or a WordPress user profile.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'contact';

	/**
	 * WordPress user fields that map to contact profile changes.
	 *
	 * Keys are change keys stored in automation context; values describe how to
	 * read old/new values from WP_User objects.
	 *
	 * @var array<string, array{prop?: string, meta?: string}>
	 */
	private const WP_PROFILE_FIELDS = array(
		'email'      => array( 'prop' => 'user_email' ),
		'first_name' => array( 'prop' => 'first_name' ),
		'last_name'  => array( 'prop' => 'last_name' ),
	);

	/**
	 * Guard against re-entrant profile_update callbacks.
	 *
	 * @var bool
	 */
	private static $handling_wp_update = false;

	/**
	 * Load Hooks
	 */
	public function load_hooks() {
		add_action( 'doublescale_contact_update', array( $this, 'contact_updated' ), 10, 2 );
		// After core has written user meta (first_name/last_name) in wp_insert_user().
		add_action( 'profile_update', array( $this, 'wordpress_user_updated' ), 20, 3 );
	}

	/**
	 * Handle contact profile update.
	 *
	 * @param ContactModel $contact Updated contact.
	 * @param array        $context Update context.
	 */
	public function contact_updated( ContactModel $contact, $context = array() ) {
		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$changes = $context['changes'] ?? array();
		if ( empty( $changes ) ) {
			return;
		}

		$data = array(
			'contact' => $contact,
			'data'    => array(
				'updated_by' => $context['updated_by'] ?? 'admin',
				'changes'    => $changes,
			),
		);

		$this->process( $data );
	}

	/**
	 * Handle WordPress user profile updates (Users → Edit / Profile).
	 *
	 * @param int     $user_id       Updated user ID.
	 * @param WP_User $old_user_data User data prior to the update.
	 * @param array   $userdata      Raw userdata passed to wp_insert_user().
	 */
	public function wordpress_user_updated( $user_id, $old_user_data, $userdata = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( self::$handling_wp_update ) {
			return;
		}

		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! $old_user_data instanceof WP_User ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$changes = $this->collect_wordpress_profile_changes( $user, $old_user_data );
		if ( empty( $changes ) ) {
			return;
		}

		self::$handling_wp_update = true;

		try {
			$contact = $this->find_contact_for_user( $user, $old_user_data );
			$updated_by = ( (int) get_current_user_id() === $user_id ) ? 'contact' : 'admin';

			$payload = array(
				'data' => array(
					'updated_by' => $updated_by,
					'changes'    => $changes,
					'user_id'    => $user_id,
					'source'     => 'wordpress_user',
				),
			);

			if ( $contact instanceof ContactModel ) {
				$payload['contact'] = $contact;
			} else {
				// Enrollment creates/finds by email when no contact row is linked yet.
				$payload['email'] = $old_user_data->user_email ?: $user->user_email;
			}

			$this->process( $payload );
		} finally {
			self::$handling_wp_update = false;
		}
	}

	/**
	 * Locate the DoubleScale contact for a WordPress user.
	 *
	 * Prefers the pre-update email so an email change still finds the existing row.
	 *
	 * @param WP_User $user          Updated user.
	 * @param WP_User $old_user_data User prior to update.
	 * @return ContactModel|null
	 */
	private function find_contact_for_user( WP_User $user, WP_User $old_user_data ) {
		$emails = array_filter(
			array_unique(
				array(
					(string) $old_user_data->user_email,
					(string) $user->user_email,
				)
			)
		);

		foreach ( $emails as $email ) {
			$contact = ContactModel::find_by_identifiers( array( 'email' => $email ) );
			if ( $contact instanceof ContactModel ) {
				return $contact;
			}
		}

		return null;
	}

	/**
	 * Diff WordPress user profile fields that map to contact information.
	 *
	 * @param WP_User $user          Updated user.
	 * @param WP_User $old_user_data User prior to update.
	 * @return array<string, array{old: mixed, new: mixed}>
	 */
	private function collect_wordpress_profile_changes( WP_User $user, WP_User $old_user_data ): array {
		$changes = array();

		foreach ( self::WP_PROFILE_FIELDS as $field => $spec ) {
			$prop = $spec['prop'] ?? '';
			if ( '' === $prop ) {
				continue;
			}

			$old_value = isset( $old_user_data->{$prop} ) ? (string) $old_user_data->{$prop} : '';
			$new_value = isset( $user->{$prop} ) ? (string) $user->{$prop} : '';

			if ( $old_value === $new_value ) {
				continue;
			}

			$changes[ $field ] = array(
				'old' => $old_value,
				'new' => $new_value,
			);
		}

		return $changes;
	}

	/**
	 * Filter by who performed the update when configured.
	 *
	 * @param AutomationModel $automation Automation model.
	 * @param array           $args       Trigger args.
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$setting = $automation->get_setting( 'updated_by', 'any' );
		if ( 'any' === $setting ) {
			return true;
		}

		$actual = $args['data']['updated_by'] ?? 'admin';

		return $setting === $actual;
	}

	/**
	 * Trigger configuration fields.
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'updated_by' => array(
				'type'          => 'select',
				'label'         => __( 'Updated by', 'doublescale' ),
				'default-value' => 'any',
				'options'       => array(
					'any'     => __( 'Anyone (admin or contact)', 'doublescale' ),
					'admin'   => __( 'Admin only', 'doublescale' ),
					'contact' => __( 'Contact only', 'doublescale' ),
				),
			),
		);
	}

	/**
	 * Attributes schema.
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'updated_by' => array(
					'type' => 'string',
				),
				'changes'    => array(
					'type' => 'object',
				),
			),
		);
	}
}
