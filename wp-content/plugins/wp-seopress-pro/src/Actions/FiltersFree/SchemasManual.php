<?php

namespace SEOPressPro\Actions\FiltersFree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class SchemasManual implements ExecuteHooks {
	public function hooks() {
		if ( '1' === seopress_pro_get_service( 'OptionPro' )->getRichSnippetEnable() ) {
			add_filter( 'seopress_active_schemas_manual_universal_metabox', '__return_true' );
		}
	}
}
