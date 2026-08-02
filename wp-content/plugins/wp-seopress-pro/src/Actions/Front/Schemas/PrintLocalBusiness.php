<?php

namespace SEOPressPro\Actions\Front\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooksFrontend;
use SEOPress\Helpers\RichSnippetType;

class PrintLocalBusiness implements ExecuteHooksFrontend {
	public function hooks() {
		add_action( 'wp_head', array( $this, 'render' ), 2 );
	}

	public function render() {
		/**
		 * Per Google, a LocalBusiness only requires `name` and `address`;
		 * `openingHoursSpecification` is recommended, not required
		 * (https://developers.google.com/search/docs/appearance/structured-data/local-business).
		 * The previous behavior keyed visibility on the opening hours and hid the
		 * whole schema as soon as they were empty, so the markup disappeared for
		 * online-only businesses, businesses that do not publish hours, and users
		 * whose hours were wiped during the 9.7/9.8 field rework. It must render
		 * regardless of opening hours; when present, they are added to the JSON-LD
		 * by JsonSchemaGenerator.
		 *
		 * We still avoid emitting an address-less LocalBusiness: bail out when no
		 * street address is configured. The `seopress_fallback_local_business_schema_renderer`
		 * filter is kept for backward compatibility and now receives this computed
		 * suppress flag (true when no address is set); developers can still
		 * force-suppress (`__return_true`) or force-render (`__return_false`).
		 */
		$street_address = (string) seopress_pro_get_service( 'OptionPro' )->getLocalBusinessStreetAddress();
		$suppress       = '' === trim( $street_address );

		if ( apply_filters( 'seopress_fallback_local_business_schema_renderer', $suppress ) ) {
			return;
		}

		$render = false;
		$page   = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessPage();
		if ( ! empty( $page ) && ( is_single( $page ) || is_page( $page ) ) ) {
			$render = true;
		} elseif ( empty( $page ) && ( is_home() || is_front_page() ) ) {
			$render = true;
		}

		if ( ! $render ) {
			return;
		}

		$toggle = seopress_get_service( 'ToggleOption' )->getToggleLocalBusiness();
		if ( '1' !== $toggle ) {
			return;
		}

		if ( 'localbusiness' === get_post_meta( get_the_ID(), '_seopress_pro_rich_snippets_type', true ) ) {
			return;
		}

		/**
		 * Pass the real page context, not just the type.
		 *
		 * The Local Business fields can hold dynamic variables, and without a
		 * context they resolve to nothing. This renders on the configured Local
		 * Business page, or on the home / front page, so the context describes
		 * the page being viewed.
		 */
		$context = array_merge(
			seopress_get_service( 'ContextPage' )->getContext(),
			array( 'type' => RichSnippetType::OPTION_LOCAL_BUSINESS )
		);

		$jsons = seopress_get_service( 'JsonSchemaGenerator' )->getJsonsEncoded(
			array(
				'local-business',
			),
			$context
		);

		?><script type="application/ld+json"><?php echo apply_filters( 'seopress_schemas_local_business_html', $jsons[0] ); ?></script>
		<?php
	}
}
