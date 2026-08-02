<?php

namespace SEOPressPro\Actions\FiltersFree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class GoogleSuggest implements ExecuteHooks {
	public function hooks() {
		add_filter( 'seopress_ui_metabox_google_suggest', '__return_true' );
	}
}
