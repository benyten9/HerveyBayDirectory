<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor;

use AddonskitForElementor\Abstracts\EnqueuerBase;
use AddonskitForElementor\Utils\Helper;

class Enqueuer extends EnqueuerBase {
	public function __construct() {
		add_action( 'wp_admin_scripts', [$this, 'enqueue_elementor_scripts'] );
		add_action( 'wp_enqueue_scripts', [$this, 'register_scripts'], 12 );
	}

	public function enqueue_elementor_scripts() {
		if ( Helper::is_edit() ) {
			wp_enqueue_script( 'directorist-select2-script' );
			wp_enqueue_script( 'directorist-add-listing' );
		}
	}

	public function register_scripts() {
		wp_register_style( 'akfe-style', AKFE_ASSETS . '/css/style-global.css', [], AKFE_VERSION );
		wp_enqueue_style( 'akfe-style' );
	}
}
