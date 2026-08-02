<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Software App Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Automatic rich snippets software app option.
 *
 * @param array $schema_datas The schema datas.
 * @return void
 */
function seopress_automatic_rich_snippets_softwareapp_option( $schema_datas ) {
	// If no data.
	if ( 0 != count( array_filter( $schema_datas ) ) ) {

		// Init.
		$softwareapp_name       = $schema_datas['name'];
		$softwareapp_os         = $schema_datas['os'];
		$softwareapp_cat        = $schema_datas['cat'];
		$softwareapp_rating     = $schema_datas['rating'];
		$softwareapp_max_rating = $schema_datas['max_rating'];
		$softwareapp_price      = $schema_datas['price'];
		$softwareapp_currency   = $schema_datas['currency'];

		$json = array(
			'@context'            => seopress_check_ssl() . 'schema.org/',
			'@type'               => 'SoftwareApplication',
			'name'                => $softwareapp_name,
			'operatingSystem'     => $softwareapp_os,
			'applicationCategory' => $softwareapp_cat,
		);

		if ( '' != $softwareapp_rating ) {
			$json['review'] = array(
				'@type'        => 'Review',
				'reviewRating' => array(
					'@type'       => 'Rating',
					'ratingValue' => $softwareapp_rating,
				),
				'author'       => array(
					'@type' => 'Person',
					'name'  => get_the_author(),
				),
			);
		}

		if ( '' != $softwareapp_rating && '' != $softwareapp_max_rating ) {
			$json['review']['reviewRating']['bestRating']  = $softwareapp_max_rating;
			$json['review']['reviewRating']['worstRating'] = 1;
		}

		if ( '' != $softwareapp_price && '' != $softwareapp_currency ) {
			$json['offers'] = array(
				'@type'         => 'Offer',
				'price'         => is_float( $softwareapp_price ) ? number_format( $softwareapp_price, 2, '.', '' ) : $softwareapp_price,
				'priceCurrency' => $softwareapp_currency,
			);
		}

		$json = array_filter( $json );

		$json = apply_filters( 'seopress_schemas_auto_softwareapp_json', $json );

		$json = '<script type="application/ld+json">' . wp_json_encode( $json ) . '</script>' . "\n";

		$json = apply_filters( 'seopress_schemas_auto_softwareapp_html', $json );

		echo $json;
	}
}
