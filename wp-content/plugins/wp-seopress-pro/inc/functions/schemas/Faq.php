<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO FAQ Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Automatic rich snippets faq option.
 *
 * @param array $schema_datas The schema datas.
 * @return void
 */
function seopress_automatic_rich_snippets_faq_option( $schema_datas ) {
	// If no data.
	if ( 0 != count( array_filter( $schema_datas ) ) ) {
		$faq_q = $schema_datas['q'];
		$faq_a = $schema_datas['a'];
		if ( ( '' != $faq_q ) && ( '' != $faq_a ) ) {
			$json = array(
				'@context'   => seopress_check_ssl() . 'schema.org',
				'@type'      => 'FAQPage',
				'name'       => 'FAQ',
				'mainEntity' => array(
					'@type'          => 'Question',
					'name'           => $faq_q,
					'answerCount'    => 1,
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $faq_a,
					),
				),
			);

			$json = array_filter( $json );

			$json = apply_filters( 'seopress_schemas_auto_faq_json', $json );

			$json = '<script type="application/ld+json">' . wp_json_encode( $json ) . '</script>' . "\n";

			$json = apply_filters( 'seopress_schemas_auto_faq_html', $json );

			echo $json;
		}
	}
}
