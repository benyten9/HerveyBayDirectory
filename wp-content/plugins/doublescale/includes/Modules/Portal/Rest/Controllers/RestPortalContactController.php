<?php
/**
 * Client Portal contact profile endpoint.
 *
 * Lets a logged-in customer update their own CRM contact record.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Contacts\Services\ContactUpdateNotifier;
use DoubleScale\Modules\Portal\Services\PortalAccess;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalContactController.
 */
class RestPortalContactController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'portal/contact';

	/**
	 * Fields a customer may update on their own profile.
	 *
	 * @var string[]
	 */
	private const SELF_EDITABLE_FIELDS = array(
		'first_name',
		'last_name',
		'phone',
		'whatsapp_phone',
		'address_1',
		'address_2',
		'city',
		'state',
		'country',
		'zip',
	);

	/**
	 * Longest value each text field takes: the column sizes, kept a little
	 * under them (the database cuts a longer value without a word).
	 *
	 * @var array<string, int>
	 */
	private const MAX_LENGTH = array(
		'first_name' => 100,
		'last_name'  => 100,
		'address_1'  => 200,
		'address_2'  => 200,
		'city'       => 100,
		'state'      => 100,
		'country'    => 100,
		'zip'        => 20,
	);

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function get_endpoint_args(): array {
		$args = array();
		foreach ( self::SELF_EDITABLE_FIELDS as $field ) {
			$args[ $field ] = array(
				'type'     => 'string',
				'required' => false,
			);
		}
		// Custom fields the admin made editable in the portal: { field id: value }.
		$args['custom_fields'] = array(
			'type'     => 'object',
			'required' => false,
		);

		return $args;
	}

	/**
	 * Return the current customer's contact profile.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_item( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return new WP_Error(
				'rest_portal_contact_missing',
				__( 'No contact record is linked to your account.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $this->shape_contact( $contact ), 200 );
	}

	/**
	 * Update the current customer's contact profile.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_item( $request ) {
		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return new WP_Error(
				'rest_portal_contact_missing',
				__( 'No contact record is linked to your account.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		// Settings → Client Portal → "Edit their details" (or this client's own
		// access): off, the client sees their details but cannot change them.
		if ( empty( PortalAccess::for_contact( $contact )['profile_edit'] ) ) {
			return new WP_Error(
				'portal_profile_read_only',
				__( 'Changing your details here is turned off. Contact us to update them.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		// Plain text only: these values reach emails (merge tags) and documents.
		$data = array();
		foreach ( self::SELF_EDITABLE_FIELDS as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$data[ $field ] = sanitize_text_field( (string) $value );
			}
		}

		$custom = $request->get_param( 'custom_fields' );
		$custom = is_array( $custom ) ? $custom : array();

		if ( empty( $data ) && empty( $custom ) ) {
			return new WP_Error(
				'rest_portal_contact_no_changes',
				__( 'No profile fields were provided to update.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// Every field is checked before anything is saved, and each refusal is
		// named: the contact record itself would store an unreadable WhatsApp
		// number as empty, guess a missing country code from the store, and
		// cut text longer than its column.
		list( $data, $errors ) = $this->validate( $data, $contact );

		// Custom fields (DoubleScale Pro): only those the admin made editable in
		// the portal, checked like the team's own form, before anything is saved.
		$custom_prepared = array(
			'values'  => array(),
			'errors'  => array(),
			'changes' => array(),
			'labels'  => array(),
		);
		if ( ! empty( $custom ) ) {
			/**
			 * Check the custom field values a client sent from the portal profile.
			 *
			 * @param array|null   $prepared null, or values/errors/changes/labels.
			 * @param ContactModel $contact  The client's contact.
			 * @param array        $custom   Submitted { field id: value }.
			 */
			$prepared = apply_filters( 'doublescale_portal_contact_custom_fields_prepare', null, $contact, $custom );
			if ( is_array( $prepared ) ) {
				$custom_prepared = array_merge( $custom_prepared, $prepared );
			}
			foreach ( (array) $custom_prepared['errors'] as $field_id => $message ) {
				$errors[ 'custom_fields.' . $field_id ] = (string) $message;
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Some of your details need fixing.', 'doublescale' ),
				array(
					'status' => 400,
					'params' => $errors,
				)
			);
		}

		$changes        = ContactUpdateNotifier::collect_field_changes( $contact, $data );
		$custom_changes = (array) $custom_prepared['changes'];
		if ( empty( $changes ) && empty( $custom_changes ) ) {
			return new WP_REST_Response( $this->shape_contact( $contact ), 200 );
		}

		try {
			if ( ! empty( $changes ) ) {
				$contact->update( $data );
			}
		} catch ( \Exception $e ) {
			// The model refuses an invalid or already-used phone number with a
			// message written for people; uncaught, the client got a bare 500.
			return new WP_Error(
				'rest_portal_contact_invalid',
				wp_strip_all_tags( $e->getMessage() ),
				array( 'status' => 400 )
			);
		}
		if ( ! empty( $custom_changes ) ) {
			/**
			 * Save the checked custom field values from the portal profile.
			 *
			 * @param ContactModel $contact The client's contact.
			 * @param array        $values  { field id: value } that changed.
			 */
			do_action( 'doublescale_portal_contact_custom_fields_save', $contact, (array) $custom_prepared['values'] );
		}
		$contact->refresh();

		$this->log_for_the_team( $contact, $changes, $custom_changes, (array) $custom_prepared['labels'] );

		ContactUpdateNotifier::fire(
			$contact,
			array(
				'updated_by' => 'contact',
				'changes'    => array_merge( $changes, $custom_changes ),
			)
		);

		return new WP_REST_Response( $this->shape_contact( $contact ), 200 );
	}

	/**
	 * Tell the team on the contact's timeline: who changed what, old and new.
	 * Team-only — the portal timeline shows only the activity types it allows.
	 *
	 * @param ContactModel                                $contact        The client's contact, saved.
	 * @param array<string, array{old: mixed, new: mixed}> $changes        What changed.
	 * @param array<string, array{old: mixed, new: mixed}> $custom_changes Custom fields that changed (custom_field_<id>).
	 * @param array<string, string>                        $labels         Custom field names by key.
	 * @return void
	 */
	private function log_for_the_team( ContactModel $contact, array $changes, array $custom_changes = array(), array $labels = array() ): void {
		// What was stored, not what was typed (the record normalises numbers).
		$saved = array();
		foreach ( $changes as $field => $change ) {
			$saved[ $field ] = array(
				'old' => null === $change['old'] ? '' : (string) $change['old'],
				'new' => (string) ( $contact->{$field} ?? '' ),
			);
		}
		foreach ( $custom_changes as $field => $change ) {
			$saved[ $field ] = array(
				'old' => (string) ( $change['old'] ?? '' ),
				'new' => (string) ( $change['new'] ?? '' ),
			);
		}

		try {
			ActivityModel::create(
				array(
					'contact_id'    => (int) $contact->id,
					'activity_type' => ActivityTypes::DETAILS_UPDATED,
					'data'          => array(
						'actor'      => 'contact',
						'actor_name' => trim( $contact->first_name . ' ' . $contact->last_name ),
						'changes'    => $saved,
						'labels'     => $labels,
					),
				)
			);
		} catch ( \Throwable $e ) {
			// The details are saved; a timeline that cannot be written must not
			// tell the client otherwise.
			doublescale_get_logger()->error(
				'Could not log a client portal details change',
				array(
					'code'       => 'portal_details_activity_failed',
					'contact_id' => (int) $contact->id,
					'message'    => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Check and clean what the client sent.
	 *
	 * @param array<string, string> $data    Sanitized values by field.
	 * @param ContactModel          $contact The client's contact.
	 * @return array{0: array<string, string>, 1: array<string, string>} Clean values, and field => message.
	 */
	private function validate( array $data, ContactModel $contact ): array {
		$errors = array();

		foreach ( self::MAX_LENGTH as $field => $max ) {
			if ( isset( $data[ $field ] ) && mb_strlen( $data[ $field ] ) > $max ) {
				$errors[ $field ] = sprintf(
					/* translators: %d: most characters the field takes */
					__( 'Use at most %d characters.', 'doublescale' ),
					$max
				);
			}
		}

		if ( isset( $data['zip'] ) && ! isset( $errors['zip'] ) && ! preg_match( '/^[\p{L}0-9 -]*$/u', $data['zip'] ) ) {
			$errors['zip'] = __( 'Use letters, digits, spaces and dashes only.', 'doublescale' );
		}

		// Both numbers in international form: SMS and WhatsApp send only to
		// that, and replies are matched to the contact by it. A number without
		// the country code is never completed by guessing the country.
		foreach ( array( 'phone', 'whatsapp_phone' ) as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}
			$number = self::strip_phone_separators( $data[ $field ] );
			$error  = self::international_number_error( $number );
			if ( null !== $error ) {
				$errors[ $field ] = $error;
			} else {
				$data[ $field ] = $number;
			}
		}

		// A number another contact already uses: the record refuses it, so say
		// so on the field instead of after the save.
		foreach ( array( 'phone', 'whatsapp_phone' ) as $field ) {
			if ( isset( $errors[ $field ] ) || ! isset( $data[ $field ] ) || '' === $data[ $field ] || (string) $contact->{$field} === $data[ $field ] ) {
				continue;
			}
			$conflict = ContactModel::find_identifier_conflict( array( $field => $data[ $field ] ), (int) $contact->id );
			if ( $conflict && $field === $conflict['field'] ) {
				$errors[ $field ] = __( 'This number is already on another account. If it is yours, contact us.', 'doublescale' );
			}
		}

		return array( $data, $errors );
	}

	/**
	 * Why a number is not in international form, or null when it is (or empty,
	 * which removes it).
	 *
	 * @param string $number Number without separators.
	 * @return string|null
	 */
	private static function international_number_error( string $number ): ?string {
		if ( '' === $number ) {
			return null;
		}
		if ( '+' !== substr( $number, 0, 1 ) ) {
			return __( 'Start with + and your country code, like +201001234567.', 'doublescale' );
		}
		if ( ! preg_match( '/^\+[1-9][0-9]{7,14}$/', $number ) ) {
			return __( 'Use + and the country code, then the number: 8 to 15 digits in all, like +201001234567.', 'doublescale' );
		}

		return null;
	}

	/**
	 * A number as people type it, without the spaces, dashes, dots and
	 * brackets they group it with.
	 *
	 * @param string $value Typed number.
	 * @return string
	 */
	private static function strip_phone_separators( string $value ): string {
		return (string) preg_replace( '/[\s().-]/', '', $value );
	}

	/**
	 * @param ContactModel $contact Contact.
	 * @return array<string, mixed>
	 */
	private function shape_contact( ContactModel $contact ): array {
		$payload = array();
		foreach ( self::SELF_EDITABLE_FIELDS as $field ) {
			$payload[ $field ] = $contact->{$field};
		}
		$payload['email'] = $contact->email;

		/**
		 * Custom fields the client sees on their portal profile (DoubleScale Pro):
		 * [ id, label, type, value, options, required, editable ].
		 *
		 * @param array        $fields   Fields, none by default.
		 * @param ContactModel $contact  The client's contact.
		 * @param bool         $can_edit Whether the client may edit their details at all.
		 */
		$fields                   = apply_filters( 'doublescale_portal_contact_custom_fields', array(), $contact, ! empty( PortalAccess::for_contact( $contact )['profile_edit'] ) );
		$payload['custom_fields'] = is_array( $fields ) ? array_values( $fields ) : array();

		return $payload;
	}
}
