<?php

defined( 'ABSPATH' ) || exit;

function seopress_automatic_rich_snippets_book_option( $schema_datas ) {
	$json = \SEOPressPro\Helpers\Schemas\Book::normalize( \SEOPressPro\Helpers\Schemas\Book::build( $schema_datas ) );
	if ( empty( $json['name'] ) ) {
		return;
	}
	$json = apply_filters( 'seopress_schemas_auto_book_json', $json );
	$html = '<script type="application/ld+json">' . seopress_pro_json_ld_encode( $json ) . '</script>' . "\n";
	echo apply_filters( 'seopress_schemas_auto_book_html', $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD encoded above; HTML filter is for trusted integrations.
}
