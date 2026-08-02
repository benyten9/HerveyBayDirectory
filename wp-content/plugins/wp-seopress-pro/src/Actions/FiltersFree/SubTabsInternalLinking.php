<?php

namespace SEOPressPro\Actions\FiltersFree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class SubTabsInternalLinking implements ExecuteHooks {
	public function hooks() {
		add_filter( 'seopress_active_internal_linking', '__return_true' );
	}
}
