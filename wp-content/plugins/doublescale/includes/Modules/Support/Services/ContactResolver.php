<?php
/**
 * ContactResolver — adapter that maps an email address to a CRM contact for
 * support workflows, creating one if no match exists.
 *
 * Replaces QuillSupport's `Customer_Model::find_or_create_by_email`. The
 * polymorphic persons table is gone; we resolve straight against the canonical
 * `doublescale_contacts` table because the CRM is the source of truth for
 * customer identity. WP-user-as-customer linkage is opportunistic — if the
 * email belongs to a WP user the contact is enriched with that user's name on
 * first contact, but agents are NEVER stored as contacts (they live in WP
 * users, referenced from `support_tickets.agent_user_id`).
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * ContactResolver class.
 */
class ContactResolver {

	/**
	 * Return an existing contact for the given email, or create one.
	 *
	 * @param string      $email      Email address (will be lowercased).
	 * @param string|null $first_name Optional first name (used only on create / enrichment).
	 * @param string|null $last_name  Optional last name (used only on create / enrichment).
	 * @return ContactModel
	 *
	 * @throws \InvalidArgumentException When the email is not parseable.
	 * @throws \Illuminate\Database\QueryException When the post-race lookup after a UNIQUE-violation also fails.
	 */
	public function find_or_create( $email, $first_name = null, $last_name = null ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			throw new \InvalidArgumentException( 'ContactResolver: invalid email address.' );
		}

		$existing = ContactModel::where( 'email', $email )->first();
		if ( $existing ) {
			return $existing;
		}

		// Enrich from a matching WP user if one exists — this lets a customer
		// who already has a WP account auto-fill their name on the first
		// ticket they file. We do NOT link the WP user to the contact via a
		// FK; that linkage belongs to the contact module's own integration if
		// it wants one.
		$wp_user = get_user_by( 'email', $email );
		if ( $wp_user ) {
			if ( null === $first_name && ! empty( $wp_user->first_name ) ) {
				$first_name = $wp_user->first_name;
			}
			if ( null === $last_name && ! empty( $wp_user->last_name ) ) {
				$last_name = $wp_user->last_name;
			}
		}

		try {
			return ContactModel::create(
				array(
					'email'      => $email,
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'source'     => 'support',
				)
			);
		} catch ( \Illuminate\Database\QueryException $e ) {
			// Recover from a duplicate-key race on the email UNIQUE index — a
			// concurrent inbound (e.g. two IMAP polls overlapping, two
			// portal submits) created the contact between our lookup and
			// our insert. The post-race read finds the winner.
			$existing = ContactModel::where( 'email', $email )->first();
			if ( $existing ) {
				return $existing;
			}
			throw $e;
		}
	}
}
