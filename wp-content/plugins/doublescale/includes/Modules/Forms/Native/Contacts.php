<?php
/**
 * Turns a submission into a contact: maps answers to contact fields, finds the person by
 * email, then phone, then WhatsApp, and creates or updates them.
 *
 * Rules (see the plan, "Contact creation"):
 * - Matching uses what the visitor typed. A cookie never decides which contact is updated.
 * - A logged-in visitor with no email or phone in the answers is linked to their own contact.
 * - With an email in the answers, only the email decides the match. Phone, then WhatsApp,
 *   are used only when the visitor gave no email. So a stranger can't reach a contact
 *   through their phone and rewrite their email (portal login takeover).
 * - A public form never changes a contact's email, phone or WhatsApp number once set; it
 *   only fills them when empty and nobody else has them.
 * - Updating never blanks a stored value; "fill blank" (the default) only writes empty fields.
 * - A channel the person unsubscribed from, or that bounced, is never re-subscribed by a form.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Fields\ContactFields;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Services\LeadRouter;
use DoubleScale\Modules\Forms\Models\FormModel;

/**
 * Contact resolution for submissions.
 */
final class Contacts {

	public const SOURCE = 'doublescale_form';

	private const IDENTIFIERS = array( 'email', 'phone', 'whatsapp_phone' );

	/**
	 * Contact data the answers map to.
	 *
	 * @param array $fields  Input fields keyed by id (visible ones).
	 * @param array $answers Answers keyed by field key.
	 * @return array{fields: array, custom_fields: array}
	 */
	public static function mapped_data( array $fields, array $answers ): array {
		$built_in = ContactFields::instance()->get_built_in_fields();
		$all      = ContactFields::instance()->get_fields();
		$data     = array();
		$custom   = array();
		$mapped   = array();
		// Two questions saved to the same contact field: the first answer wins (a later "Friend's
		// email" never replaces "Your email").
		$put = static function ( string $key, $value ) use ( &$data ): void {
			if ( ! isset( $data[ $key ] ) || '' === (string) $data[ $key ] ) {
				$data[ $key ] = $value;
			}
		};

		foreach ( $fields as $field ) {
			if ( empty( $field['map'] ) || 'contact' !== $field['map']['object'] ) {
				continue;
			}
			$mapped[ $field['map']['field'] ] = true;
		}

		foreach ( $fields as $field ) {
			$value = $answers[ $field['key'] ] ?? null;
			$kind  = FieldTypes::get( $field );
			if ( null === $value || ! $kind->saves_to_contact() ) {
				continue;
			}

			$target = ( ! empty( $field['map'] ) && 'contact' === $field['map']['object'] ) ? (string) $field['map']['field'] : '';
			// A form with no email mapped still gives its first email answer (same for phone and
			// name), unless that question was set to "Don't save to the contact" on purpose.
			$fallback = empty( $field['map'] ) ? $kind->contact_fallback() : '';
			if ( '' === $target && '' !== $fallback && ! isset( $mapped[ $fallback ] ) ) {
				$target              = $fallback;
				$mapped[ $fallback ] = true;
			}
			if ( '' === $target ) {
				continue;
			}

			// Answers made of parts (name, address) fill their own columns.
			$parts = $kind->contact_parts( $value );
			if ( null !== $parts ) {
				foreach ( $parts as $part => $part_value ) {
					$put( (string) $part, $part_value );
				}
				continue;
			}

			// A one-line answer saved as the name: first word(s) and the rest. (A whole address
			// comes from an Address question only.)
			if ( 'name' === $target || 'address' === $target ) {
				if ( 'name' === $target && is_scalar( $value ) ) {
					$split = preg_split( '/\s+/', trim( (string) $value ), 2 );
					$put( 'first_name', $split[0] );
					if ( ! empty( $split[1] ) ) {
						$put( 'last_name', $split[1] );
					}
				}
				continue;
			}

			if ( isset( $built_in[ $target ] ) ) {
				$text = $kind->contact_text( $field, $value );
				if ( 'country' === $target && function_exists( 'doublescale_get_country_code' ) ) {
					$text = doublescale_get_country_code( $text );
				}
				$put( $target, $text );
				continue;
			}

			if ( isset( $all[ $target ] ) && ! empty( $all[ $target ]['is_custom'] ) && Schema::pro_active() ) {
				if ( ! isset( $custom[ $target ] ) || '' === (string) $custom[ $target ] ) {
					$custom[ $target ] = is_array( $value ) ? implode( ', ', $value ) : $value;
				}
			}
		}

		// Only a real email address or phone number can find or create a contact (a short text
		// question saved to Email may hold anything).
		if ( isset( $data['email'] ) && ! is_email( (string) $data['email'] ) ) {
			unset( $data['email'] );
		}
		foreach ( array( 'phone', 'whatsapp_phone' ) as $number ) {
			if ( isset( $data[ $number ] ) ) {
				$digits = strlen( (string) preg_replace( '/\D/', '', (string) $data[ $number ] ) );
				if ( $digits < 6 || $digits > 16 ) {
					unset( $data[ $number ] );
				}
			}
		}

		return array(
			'fields'        => $data,
			'custom_fields' => $custom,
		);
	}

	/**
	 * Whether a form can find or create a contact, exactly as submissions do (mapped_data()):
	 * contacts on, and a question saved to Email, Phone or WhatsApp (or an Email / Phone
	 * question never given a target, which fills it automatically).
	 *
	 * @param FormModel $form Form.
	 * @return array{status: string, via: string[], always: bool}
	 *   status: ok | off (contacts switched off) | no_identifier;
	 *   via: labels of the questions that find the contact;
	 *   always: true when one of them is required (every submission has a contact).
	 */
	public static function readiness( FormModel $form ): array {
		$settings = Schema::normalize_settings( $form->settings );
		if ( empty( $settings['contact']['create'] ) ) {
			return array( 'status' => 'off', 'via' => array(), 'always' => false );
		}
		$fields = Schema::input_fields( Schema::effective( $form )['schema'] );
		$mapped = array();
		foreach ( $fields as $field ) {
			if ( ! empty( $field['map'] ) && 'contact' === ( $field['map']['object'] ?? '' ) ) {
				$mapped[ (string) $field['map']['field'] ] = true;
			}
		}
		$via    = array();
		$always = false;
		foreach ( $fields as $field ) {
			$target = ( ! empty( $field['map'] ) && 'contact' === ( $field['map']['object'] ?? '' ) ) ? (string) $field['map']['field'] : '';
			if ( '' === $target && empty( $field['map'] ) ) {
				$fallback = FieldTypes::get( $field )->contact_fallback();
				if ( '' !== $fallback && ! isset( $mapped[ $fallback ] ) ) {
					$target            = $fallback;
					$mapped[ $target ] = true;
				}
			}
			if ( in_array( $target, self::IDENTIFIERS, true ) ) {
				$via[]  = '' !== (string) $field['label'] ? (string) $field['label'] : (string) $field['key'];
				$always = $always || ! empty( $field['required'] );
			}
		}
		return array(
			'status' => $via ? 'ok' : 'no_identifier',
			'via'    => $via,
			'always' => $always,
		);
	}

	/**
	 * Find or create the contact for a submission.
	 *
	 * @param FormModel $form     Form.
	 * @param array     $settings The form's settings.
	 * @param array     $fields   Visible input fields keyed by id.
	 * @param array     $answers  Answers keyed by field key.
	 * @return array{contact: ContactModel|null, created: bool, reason: string}
	 */
	public static function resolve( FormModel $form, array $settings, array $fields, array $answers ): array {
		$none = array(
			'contact' => null,
			'created' => false,
			'reason'  => '',
		);

		if ( empty( $settings['contact']['create'] ) ) {
			$none['reason'] = 'contacts_off';
			return $none;
		}

		$mapped = self::mapped_data( $fields, $answers );
		$data   = $mapped['fields'];

		$has_identifier = false;
		foreach ( self::IDENTIFIERS as $identifier ) {
			if ( ! empty( $data[ $identifier ] ) ) {
				$has_identifier = true;
			}
		}

		if ( ! $has_identifier ) {
			// Logged in with no email or phone in the form: link to the user's own contact.
			$user = wp_get_current_user();
			if ( $user && $user->exists() && is_email( $user->user_email ) ) {
				$contact = ContactModel::get_by_email( $user->user_email );
				if ( $contact ) {
					self::update_existing( $contact, $data, $settings );
					self::after( $contact, $mapped['custom_fields'], $settings, $fields, $answers, false );
					return array(
						'contact' => $contact,
						'created' => false,
						'reason'  => 'logged_in',
					);
				}
				$data['email'] = $user->user_email;
			} else {
				// A browser already known as a contact (website tracking, signed cookie: it created
				// the contact or logged in here). Shared devices happen, so the answers only fill
				// empty fields and never opt anyone in to email, SMS or WhatsApp.
				$known = self::known_visitor( $form );
				if ( $known ) {
					$careful = array_replace_recursive( $settings, array( 'contact' => array( 'update' => 'fill_blank' ) ) );
					self::update_existing( $known, $data, $careful );
					self::after( $known, $mapped['custom_fields'], $careful, $fields, $answers, false, false );
					return array(
						'contact' => $known,
						'created' => false,
						'reason'  => 'known_visitor',
					);
				}
				$none['reason'] = 'no_identifier';
				return $none;
			}
		}

		try {
			$existing = self::match( $data );
			if ( $existing ) {
				self::update_existing( $existing, $data, $settings );
				self::after( $existing, $mapped['custom_fields'], $settings, $fields, $answers, false );
				return array(
					'contact' => $existing,
					'created' => false,
					'reason'  => 'matched',
				);
			}

			// A phone or WhatsApp number that already belongs to someone stays theirs.
			foreach ( array( 'phone', 'whatsapp_phone' ) as $identifier ) {
				if ( ! empty( $data[ $identifier ] ) && ContactModel::find_identifier_conflict( ContactModel::normalize_contact_data( array( $identifier => $data[ $identifier ] ) ) ) ) {
					unset( $data[ $identifier ] );
				}
			}

			$data['source']       = self::SOURCE;
			$data['email_status'] = self::new_email_status( $settings, $fields, $answers );

			$contact = LeadRouter::with_context(
				'form',
				array(
					'form_id'  => $form->id,
					'owner_id' => (int) ( $settings['contact']['owner_id'] ?? 0 ),
					'actor_id' => 0,
				),
				static function () use ( $data ) {
					return ContactModel::create( $data );
				}
			);

			self::after( $contact, $mapped['custom_fields'], $settings, $fields, $answers, true );

			return array(
				'contact' => $contact,
				'created' => true,
				'reason'  => 'created',
			);
		} catch ( \Throwable $e ) {
			// A second submission raced this one to create the same person: use theirs.
			$existing = self::match( $data );
			if ( $existing ) {
				return array(
					'contact' => $existing,
					'created' => false,
					'reason'  => 'matched',
				);
			}
			doublescale_get_logger()->error(
				'Form submission could not create a contact',
				array(
					'code'    => 'native_form_contact_failed',
					'form_id' => $form->id,
					'error'   => $e->getMessage(),
				)
			);
			$none['reason'] = 'error';
			return $none;
		}
	}

	/**
	 * The existing contact a submission would go to, without changing anything (the
	 * "one entry per person" check runs before the contact is updated).
	 *
	 * @param array $fields  Visible input fields keyed by id.
	 * @param array $answers Answers keyed by field key.
	 * @return ContactModel|null
	 */
	public static function find( array $fields, array $answers ): ?ContactModel {
		$data = self::mapped_data( $fields, $answers )['fields'];
		foreach ( self::IDENTIFIERS as $identifier ) {
			if ( ! empty( $data[ $identifier ] ) ) {
				return self::match( $data );
			}
		}
		$user = wp_get_current_user();
		if ( $user && $user->exists() && is_email( $user->user_email ) ) {
			$contact = ContactModel::get_by_email( $user->user_email );
			return $contact instanceof ContactModel ? $contact : null;
		}
		return null;
	}

	/**
	 * The contact a submission belongs to. An email decides alone; phone, then WhatsApp,
	 * only count when the visitor gave no email.
	 *
	 * @param array $data Mapped contact data.
	 * @return ContactModel|null
	 */
	private static function match( array $data ): ?ContactModel {
		$normalized = ContactModel::normalize_contact_data( $data );
		foreach ( self::IDENTIFIERS as $identifier ) {
			if ( empty( $normalized[ $identifier ] ) ) {
				continue;
			}
			$found = ContactModel::find_by_identifiers( array( $identifier => $normalized[ $identifier ] ) );
			return $found instanceof ContactModel ? $found : null;
		}
		return null;
	}

	/**
	 * Update a matched contact with the answers.
	 *
	 * @param ContactModel $contact  Contact.
	 * @param array        $data     Mapped contact data.
	 * @param array        $settings Form settings.
	 * @return void
	 */
	private static function update_existing( ContactModel $contact, array $data, array $settings ): void {
		$fill_blank = 'overwrite' !== ( $settings['contact']['update'] ?? 'fill_blank' );
		$apply      = array();

		foreach ( $data as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$identifier = in_array( $key, self::IDENTIFIERS, true );
			// Identifiers are only ever filled in, never replaced: whoever typed them proved nothing.
			if ( ( $fill_blank || $identifier ) && '' !== (string) $contact->getAttribute( $key ) ) {
				continue;
			}
			if ( $identifier ) {
				// Someone else's email or phone stays theirs.
				$conflict = ContactModel::find_identifier_conflict( ContactModel::normalize_contact_data( array( $key => $value ) ), (int) $contact->id );
				if ( $conflict ) {
					continue;
				}
			}
			$apply[ $key ] = $value;
		}

		if ( empty( $apply ) ) {
			return;
		}

		try {
			$contact->fill( ContactModel::normalize_contact_data( $apply ) );
			$contact->save();
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->warning(
				'Form submission could not update the contact',
				array(
					'code'       => 'native_form_contact_update_failed',
					'contact_id' => $contact->id,
					'error'      => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * The contact this browser is known as (website tracking, Pro), or null.
	 *
	 * @param FormModel $form Form.
	 * @return ContactModel|null
	 */
	private static function known_visitor( FormModel $form ): ?ContactModel {
		/**
		 * The contact the visitor's browser is known as, for a form sent without an email or
		 * phone. Return 0 for none. Only return a contact the browser has proved to be.
		 *
		 * @param int       $contact_id Contact id (0).
		 * @param FormModel $form       Form.
		 */
		$id = (int) apply_filters( 'doublescale_native_form_known_visitor', 0, $form );
		return $id > 0 ? ContactModel::find( $id ) : null;
	}

	/**
	 * Lists, tags, custom fields and channel consent.
	 *
	 * @param ContactModel $contact  Contact.
	 * @param array        $custom   Custom field values keyed by custom field id.
	 * @param array        $settings Form settings.
	 * @param array        $fields   Visible input fields.
	 * @param array        $answers  Answers keyed by key.
	 * @param bool         $created  Whether this submission created the contact.
	 * @return void
	 */
	private static function after( ContactModel $contact, array $custom, array $settings, array $fields, array $answers, bool $created, bool $consent = true ): void {
		try {
			if ( ! empty( $settings['contact']['lists'] ) ) {
				$contact->add_lists( $settings['contact']['lists'] );
			}
			if ( ! empty( $settings['contact']['tags'] ) ) {
				$contact->add_tags( $settings['contact']['tags'] );
			}
			if ( ! empty( $custom ) && method_exists( $contact, 'custom_fields' ) && class_exists( '\DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' ) ) {
				// "Fill blank" (the default) never replaces a value the contact already has, for
				// custom fields too: a stranger typing someone's email must not rewrite them.
				if ( ! $created && 'overwrite' !== ( $settings['contact']['update'] ?? 'fill_blank' ) ) {
					$stored = $contact->custom_fields()->get();
					foreach ( $stored as $row ) {
						$current = isset( $row->pivot ) ? $row->pivot->value : null;
						if ( null !== $current && '' !== trim( is_scalar( $current ) ? (string) $current : (string) wp_json_encode( $current ) ) ) {
							unset( $custom[ (int) $row->id ] );
						}
					}
				}
				$values = array();
				foreach ( $custom as $id => $value ) {
					$values[ $id ] = array(
						'value'       => $value,
						'entity_type' => 'contact',
					);
				}
				$contact->custom_fields()->syncWithoutDetaching( $values );
			}

			// A ticked channel consent box subscribes that channel (not for a visitor known only
			// by their browser: the tick is kept in the consent log, the contact is not opted in).
			foreach ( $consent ? $fields : array() as $field ) {
				if ( 'consent' !== $field['type'] || empty( $answers[ $field['key'] ] ) ) {
					continue;
				}
				$channel = (string) ( $field['props']['channel'] ?? 'terms' );
				// Someone who opted out (or whose address bounced) is never opted back in by a
				// form anyone could fill with their email. The tick is still in the consent log.
				$status = (string) $contact->getAttribute( $channel . '_status' );
				if ( ! $created && in_array( $status, array( 'unsubscribed', 'bounced', 'complained', 'spam', 'blocked' ), true ) ) {
					continue;
				}
				if ( in_array( $channel, array( 'sms', 'whatsapp' ), true ) || ( 'email' === $channel && 'double_optin' !== ( $settings['contact']['email_status'] ?? '' ) ) ) {
					$contact->subscribe_to_channel( $channel );
				}
			}
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->warning(
				'Form submission could not apply lists, tags or consent',
				array(
					'code'       => 'native_form_contact_after_failed',
					'contact_id' => $contact->id,
					'error'      => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Whether this submission asks the contact to confirm their email (double opt-in): the
	 * form uses double opt-in, and when it has an email consent box, that box was ticked.
	 * Leaving the box empty means "no email marketing", so no confirmation email either.
	 *
	 * @param array $settings Form settings.
	 * @param array $fields   Input fields.
	 * @param array $answers  Answers keyed by key.
	 * @return bool
	 */
	public static function wants_double_optin( array $settings, array $fields, array $answers ): bool {
		if ( 'double_optin' !== ( $settings['contact']['email_status'] ?? '' ) ) {
			return false;
		}
		$boxes = 0;
		foreach ( $fields as $field ) {
			if ( 'consent' === ( $field['type'] ?? '' ) && 'email' === ( $field['props']['channel'] ?? '' ) ) {
				++$boxes;
				if ( ! empty( $answers[ $field['key'] ] ) ) {
					return true;
				}
			}
		}
		return 0 === $boxes;
	}

	/**
	 * Email status of a new contact.
	 *
	 * @param array $settings Form settings.
	 * @param array $fields   Visible input fields.
	 * @param array $answers  Answers keyed by key.
	 * @return string
	 */
	private static function new_email_status( array $settings, array $fields, array $answers ): string {
		$mode = (string) ( $settings['contact']['email_status'] ?? 'subscribed' );
		if ( 'subscribed' === $mode ) {
			return 'subscribed';
		}
		if ( 'double_optin' === $mode ) {
			return 'unverified';
		}
		foreach ( $fields as $field ) {
			if ( 'consent' === $field['type'] && 'email' === ( $field['props']['channel'] ?? '' ) && ! empty( $answers[ $field['key'] ] ) ) {
				return 'subscribed';
			}
		}
		return 'unverified';
	}
}
