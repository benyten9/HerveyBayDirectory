<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor;

use AddonskitForElementor\Utils\Helper;

class DirectoristSupport {
	protected static $instance = null;

	public function __construct() {
		// add_filter( 'atbdp_listing_type_settings_field_list', [$this, 'all_listing_widgets'] );
		add_filter( 'directorist_account_page_accessible', [$this, 'directorist_account_page_accessible'] );
	}

	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function directorist_account_page_accessible() {
		if ( Helper::is_edit() ) {
			return false;
		}

		return true;
	}

	public function all_listing_widgets( $fields ) {
		if ( is_plugin_inactive( 'elementor-pro/elementor-pro.php' ) ) {
			return $fields;
		}

		$fields['single_listing_template']['options'][1]['label'] = __( 'Elementor Template', 'addonskit-for-elementor' );

		return $fields;
	}
}