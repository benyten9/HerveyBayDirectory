<?php
/**
 * Shared resolver for WordPress user merge tags.
 *
 * Prefers the user_id stored on the automation enrollment (set by WordPress
 * user triggers), then falls back to the contact's linked WP user / email.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\MergeTags\Wordpress\User;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use WP_User;

/**
 * AbstractWordpressUserMergeTag.
 */
abstract class AbstractWordpressUserMergeTag extends MergeTag {

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'wordpress_user';

	/**
	 * Resolve the WordPress user for this merge tag.
	 *
	 * @param AutomationContactModel|ContactModel|null $contact Contact context.
	 * @return WP_User|null
	 */
	protected function resolve_user( $contact ) {
		if ( $contact instanceof AutomationContactModel ) {
			$user_id = (int) $contact->get_data( 'user_id', 0 );
			if ( $user_id > 0 ) {
				$user = get_userdata( $user_id );
				if ( $user instanceof WP_User ) {
					return $user;
				}
			}
			$contact = $contact->contact;
		}

		if ( ! $contact instanceof ContactModel ) {
			return null;
		}

		if ( $contact->user && ! empty( $contact->user->ID ) ) {
			$user = get_userdata( (int) $contact->user->ID );
			if ( $user instanceof WP_User ) {
				return $user;
			}
		}

		$email = (string) $contact->email;
		if ( '' === $email ) {
			return null;
		}

		$user = get_user_by( 'email', $email );
		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Read a user property or meta value.
	 *
	 * @param WP_User     $user User.
	 * @param string      $prop Object property (e.g. user_email, first_name).
	 * @param string|null $meta Optional user-meta key when $prop is empty.
	 * @return string
	 */
	protected function read_user_value( WP_User $user, string $prop = '', $meta = null ): string {
		if ( '' !== $prop && isset( $user->{$prop} ) ) {
			return is_scalar( $user->{$prop} ) ? (string) $user->{$prop} : '';
		}

		if ( null !== $meta && '' !== $meta ) {
			$value = get_user_meta( $user->ID, $meta, true );
			return is_scalar( $value ) ? (string) $value : '';
		}

		return '';
	}
}
