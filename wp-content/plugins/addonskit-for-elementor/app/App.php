<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor;

use AddonskitForElementor\Admin\Admin;
use AddonskitForElementor\Elements\Elements;
use AddonskitForElementor\Utils\Singleton;

class App {
	use Singleton;

	public function __construct() {
		$this->setup();
	}

	public function setup() {
		if ( is_admin() ) {
			Admin::initialize();
		}

		Enqueuer::initialize();
		Elements::initialize();
		DirectoristSupport::instance();
	}
}