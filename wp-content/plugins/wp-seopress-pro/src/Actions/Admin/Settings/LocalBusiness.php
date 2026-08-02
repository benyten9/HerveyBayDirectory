<?php

namespace SEOPressPro\Actions\Admin\Settings;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooksBackend;

class LocalBusiness implements ExecuteHooksBackend {
	/**
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_init', array( $this, 'init' ) );
	}

	/**
	 * @since 4.5.0
	 * @see @admin_init
	 *
	 * @return void
	 */
	public function init() {
		seopress_pro_get_service( 'SettingsSectionLocalBusiness' )->renderSettings();
	}
}
