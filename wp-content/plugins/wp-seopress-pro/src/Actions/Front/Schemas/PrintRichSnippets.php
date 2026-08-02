<?php

namespace SEOPressPro\Actions\Front\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooksFrontend;
use SEOPress\Helpers\RichSnippetType;

class PrintRichSnippets implements ExecuteHooksFrontend {
	public function hooks() {
		add_action( 'wp_head', array( $this, 'render' ), 2 );
	}

	public function render() {
		$render = false;
		if ( is_singular() ) {
			$render = true;
		}

		if ( ! $render ) {
			return;
		}

		$enable = seopress_pro_get_service( 'OptionPro' )->getRichSnippetEnable();

		if ( '1' !== $enable ) {
			return;
		}

		$schemas = get_post_meta( get_the_ID(), '_seopress_pro_schemas_manual', true );
		if ( empty( $schemas ) || ! is_array( $schemas ) ) {
			return;
		}

		$jsonsNeedToCreate = array();
		$jsonsCustom       = array();

		foreach ( $schemas as $schema ) {
			if ( ! isset( $schema['_seopress_pro_rich_snippets_type'] ) ) {
				continue;
			}
			if ( 'custom' === $schema['_seopress_pro_rich_snippets_type'] && isset( $schema['_seopress_pro_rich_snippets_custom'] ) ) {
				$jsonsCustom[] = $schema['_seopress_pro_rich_snippets_custom'];
			}

			$jsonsNeedToCreate[] = $schema['_seopress_pro_rich_snippets_type'];
		}

		$context         = seopress_get_service( 'ContextPage' )->getContext();
		$context['type'] = RichSnippetType::MANUAL;

		$jsons = seopress_get_service( 'JsonSchemaGenerator' )->getJsonsEncoded( $jsonsNeedToCreate, $context );

		foreach ( $jsons as $key => $json ) {
			if ( null === $json ) {
				continue;
			} ?><script type="application/ld+json"><?php echo apply_filters( 'seopress_rich_snippets_' . $jsonsNeedToCreate[ $key ] . '_html', $json, $context ); ?></script>
			<?php
				echo "\n";
		}

		$jsonsCustom = seopress_get_service( 'TagsToString' )->replaceDataToString( $jsonsCustom, $context );

		foreach ( $jsonsCustom as $key => $json ) {
			echo apply_filters( 'seopress_rich_snippets_custom_' . $key . '_html', $json, $context );
			echo "\n";
		}
		?>
		<?php
	}
}
