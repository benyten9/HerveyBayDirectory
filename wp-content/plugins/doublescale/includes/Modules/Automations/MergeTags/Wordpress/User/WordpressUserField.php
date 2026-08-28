<?php
/**
 * WordPress user profile merge tags.
 *
 * Registers core user properties and common billing meta so automations can
 * map WordPress profile data into DoubleScale contact fields.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\MergeTags\Wordpress\User;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/AbstractWordpressUserMergeTag.php';

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * WordpressUserField merge tag.
 */
class WordpressUserField extends AbstractWordpressUserMergeTag {

	/**
	 * Object property on WP_User (user_email, first_name, …).
	 *
	 * @var string
	 */
	private $prop;

	/**
	 * User-meta key when reading meta instead of a property.
	 *
	 * @var string|null
	 */
	private $meta;

	/**
	 * @param string      $name Display name.
	 * @param string      $slug Merge-tag slug (group wordpress_user).
	 * @param string      $prop WP_User property, or empty when using meta.
	 * @param string|null $meta User-meta key.
	 */
	public function __construct( string $name, string $slug, string $prop = '', $meta = null ) {
		$this->name        = $name;
		$this->slug        = $slug;
		$this->description = $name;
		$this->prop        = $prop;
		$this->meta        = $meta;
	}

	/**
	 * @param AutomationContactModel|null $contact Automation contact.
	 * @param string                      $merge_tag Raw merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$user = $this->resolve_user( $contact );
		if ( ! $user ) {
			return '';
		}

		return $this->read_user_value( $user, $this->prop, $this->meta );
	}
}

$manager = MergeTagsManager::instance();

foreach (
	array(
		array( __( 'User Email', 'doublescale' ), 'email', 'user_email', null ),
		array( __( 'User First Name', 'doublescale' ), 'first_name', 'first_name', null ),
		array( __( 'User Last Name', 'doublescale' ), 'last_name', 'last_name', null ),
		array( __( 'User Display Name', 'doublescale' ), 'display_name', 'display_name', null ),
		array( __( 'User Login', 'doublescale' ), 'user_login', 'user_login', null ),
		array( __( 'User Nickname', 'doublescale' ), 'nickname', 'nickname', null ),
		array( __( 'User Billing Phone', 'doublescale' ), 'billing_phone', '', 'billing_phone' ),
		array( __( 'User Billing Email', 'doublescale' ), 'billing_email', '', 'billing_email' ),
		array( __( 'User Billing First Name', 'doublescale' ), 'billing_first_name', '', 'billing_first_name' ),
		array( __( 'User Billing Last Name', 'doublescale' ), 'billing_last_name', '', 'billing_last_name' ),
		array( __( 'User Billing Address 1', 'doublescale' ), 'billing_address_1', '', 'billing_address_1' ),
		array( __( 'User Billing Address 2', 'doublescale' ), 'billing_address_2', '', 'billing_address_2' ),
		array( __( 'User Billing City', 'doublescale' ), 'billing_city', '', 'billing_city' ),
		array( __( 'User Billing State', 'doublescale' ), 'billing_state', '', 'billing_state' ),
		array( __( 'User Billing Postcode', 'doublescale' ), 'billing_postcode', '', 'billing_postcode' ),
		array( __( 'User Billing Country', 'doublescale' ), 'billing_country', '', 'billing_country' ),
	) as $tag
) {
	$manager->register( new WordpressUserField( $tag[0], $tag[1], $tag[2], $tag[3] ) );
}
