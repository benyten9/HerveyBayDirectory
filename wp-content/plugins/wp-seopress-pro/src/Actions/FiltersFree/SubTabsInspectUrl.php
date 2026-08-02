<?php

namespace SEOPressPro\Actions\FiltersFree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class SubTabsInspectUrl implements ExecuteHooks {
	public function hooks() {
		add_filter(
			'seopress_active_inspect_url',
			function () {
				$option = seopress_get_service( 'ToggleOption' );

				if ( ! \method_exists( $option, 'getToggleInspectUrl' ) ) {
					return true;
				}

				return seopress_get_service( 'ToggleOption' )->getToggleInspectUrl() === '1';
			}
		);
	}
}
