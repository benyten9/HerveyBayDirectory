<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Review Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Automatic rich snippets review option.
 *
 * @param array $schema_datas The schema datas.
 * @return void
 */
function seopress_automatic_rich_snippets_review_option( $schema_datas ) {
	// If no data.
	if ( 0 != count( array_filter( $schema_datas ) ) ) {
		$review_item       = $schema_datas['item'];
		$review_type       = $schema_datas['item_type'];
		$review_img        = $schema_datas['img'];
		$review_rating     = $schema_datas['rating'];
		$review_max_rating = $schema_datas['max_rating'];
		$review_body       = $schema_datas['body'];

		if ( $review_type ) {
			$type = $review_type;
		} else {
			$type = 'Thing';
		}

		$json = array(
			'@context'      => seopress_check_ssl() . 'schema.org/',
			'@type'         => 'Review',
			'itemReviewed'  => array(
				'@type' => $type,
				'name'  => $review_item,
			),
			'datePublished' => get_the_date( 'c' ),
			'author'        => array(
				'@type' => 'Person',
				'name'  => get_the_author(),
			),
			'reviewBody'    => $review_body,
		);

		if ( '' != $review_img ) {
			$json['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $review_img,
			);
		}

		if ( '' != $review_rating ) {
			$json['reviewRating'] = array(
				'@type'       => 'Rating',
				'ratingValue' => $review_rating,
			);
		}

		if ( '' != $review_rating && '' != $review_max_rating ) {
			$json['reviewRating']['bestRating']  = $review_max_rating;
			$json['reviewRating']['worstRating'] = 1;
		}

		$json = array_filter( $json );

		$json = apply_filters( 'seopress_schemas_auto_review_json', $json );

		$json = '<script type="application/ld+json">' . wp_json_encode( $json ) . '</script>' . "\n";

		$json = apply_filters( 'seopress_schemas_auto_review_html', $json );

		echo $json;
	}
}
