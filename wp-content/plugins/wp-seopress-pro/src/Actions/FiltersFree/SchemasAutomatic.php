<?php

namespace SEOPressPro\Actions\FiltersFree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * Activate the "Automatic" sub-tab of the React universal metabox (Schemas
 * tab) when the PRO rich snippets feature is enabled.
 *
 * Mirrors `SchemasManual` — the free plugin exposes a filter and lets the PRO
 * plugin decide whether to flip it on. The free side reads the result when
 * localizing `SEOPRESS_DATA.SUB_TABS.SCHEMA_AUTOMATIC` for the React app.
 *
 * @since 9.8
 */
class SchemasAutomatic implements ExecuteHooks {
	public function hooks() {
		if ( '1' === seopress_pro_get_service( 'OptionPro' )->getRichSnippetEnable() ) {
			add_filter( 'seopress_active_schemas_automatic_universal_metabox', '__return_true' );
		}
	}
}
