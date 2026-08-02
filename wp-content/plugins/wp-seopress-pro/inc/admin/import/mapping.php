<?php //phpcs:ignore
/**
 * SEOPress PRO Generic mappings.
 *
 * @package SEOPress PRO
 * @subpackage Import
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Add generic mappings.
 *
 * @param array $mappings Importer columns mappings.
 *
 * @return array
 */
function seopress_importer_generic_mappings( $mappings ) {
	$generic_mappings = array(
		__( 'Title', 'wp-seopress-pro' ) => 'name',
	);

	return array_merge( $mappings, $generic_mappings );
}
add_filter( 'seopress_csv_metadata_import_mapping_default_columns', 'seopress_importer_generic_mappings' );
