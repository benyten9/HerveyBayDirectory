<?php

namespace SEOPressPro\Actions\FiltersFree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class SubTabsGoogleNews implements ExecuteHooks {
	public function hooks() {
		add_filter(
			'seopress_active_google_news',
			function () {
				$option = seopress_get_service( 'ToggleOption' );

				if ( ! \method_exists( $option, 'getToggleGoogleNews' ) ) {
					return true;
				}

				return seopress_get_service( 'ToggleOption' )->getToggleGoogleNews() === '1';
			}
		);
	}
}
