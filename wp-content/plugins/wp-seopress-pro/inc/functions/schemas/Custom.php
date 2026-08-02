<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Custom Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Automatic rich snippets custom option.
 *
 * @param array $schema_datas The schema datas.
 * @return void
 */
function seopress_automatic_rich_snippets_custom_option( $schema_datas ) {
	// If no data.
	if ( 0 != count( array_filter( $schema_datas ) ) ) {
		$custom = $schema_datas['custom'];

		$variables = null;
		$variables = apply_filters( 'seopress_dyn_variables_fn', $variables );

		$post                                     = $variables['post'];
		$term                                     = $variables['term'];
		$seopress_titles_title_template           = $variables['seopress_titles_title_template'];
		$seopress_titles_description_template     = $variables['seopress_titles_description_template'];
		$seopress_paged                           = $variables['seopress_paged'];
		$the_author_meta                          = $variables['the_author_meta'];
		$sep                                      = $variables['sep'];
		$seopress_excerpt                         = $variables['seopress_excerpt'];
		$post_category                            = $variables['post_category'];
		$post_tag                                 = $variables['post_tag'];
		$post_thumbnail_url                       = $variables['post_thumbnail_url'];
		$get_search_query                         = $variables['get_search_query'];
		$woo_single_cat_html                      = $variables['woo_single_cat_html'];
		$woo_single_tag_html                      = $variables['woo_single_tag_html'];
		$woo_single_price                         = $variables['woo_single_price'];
		$woo_single_price_exc_tax                 = $variables['woo_single_price_exc_tax'];
		$woo_single_sku                           = $variables['woo_single_sku'];
		$author_bio                               = $variables['author_bio'];
		$seopress_get_the_excerpt                 = $variables['seopress_get_the_excerpt'];
		$seopress_titles_template_variables_array = $variables['seopress_titles_template_variables_array'];
		$seopress_titles_template_replace_array   = array_map(
			function ( $value ) {
				return null !== $value ? htmlentities( $value ) : null;
			},
			$variables['seopress_titles_template_replace_array']
		);
		$seopress_excerpt_length                  = $variables['seopress_excerpt_length'];

		preg_match_all( '/%%_cf_(.*?)%%/', $custom, $matches ); // Custom fields.

		if ( ! empty( $matches ) ) {
			$seopress_titles_cf_template_variables_array = array();
			$seopress_titles_cf_template_replace_array   = array();

			foreach ( $matches['0'] as $key => $value ) {
				$seopress_titles_cf_template_variables_array[] = $value;
			}

			foreach ( $matches['1'] as $key => $value ) {
				$seopress_titles_cf_template_replace_array[] = esc_attr( get_post_meta( $post->ID, $value, true ) );
			}
		}

		preg_match_all( '/%%_ct_(.*?)%%/', $custom, $matches2 ); // Custom terms taxonomy.

		if ( ! empty( $matches2 ) ) {
			$seopress_titles_ct_template_variables_array = array();
			$seopress_titles_ct_template_replace_array   = array();

			foreach ( $matches2['0'] as $key => $value ) {
				$seopress_titles_ct_template_variables_array[] = $value;
			}

			foreach ( $matches2['1'] as $key => $value ) {
				$term = wp_get_post_terms( $post->ID, $value );
				if ( ! is_wp_error( $term ) ) {
					$seopress_titles_ct_template_replace_array[] = isset( $term[0]->name ) ? esc_attr( $term[0]->name ) : '';
				}
			}
		}

		// Default.
		$custom = str_replace( $seopress_titles_template_variables_array, $seopress_titles_template_replace_array, $custom );

		// Custom fields.
		if ( ! empty( $matches ) && ! empty( $seopress_titles_cf_template_variables_array ) && ! empty( $seopress_titles_cf_template_replace_array ) ) {
			$custom = str_replace( $seopress_titles_cf_template_variables_array, $seopress_titles_cf_template_replace_array, $custom );
		}

		// Custom terms taxonomy.
		if ( ! empty( $matches2 ) && ! empty( $seopress_titles_ct_template_variables_array ) && ! empty( $seopress_titles_ct_template_replace_array ) ) {
			$custom = str_replace( $seopress_titles_ct_template_variables_array, $seopress_titles_ct_template_replace_array, $custom );
		}

		$html = wp_specialchars_decode( $custom, ENT_COMPAT );

		$html .= "\n";

		$html = apply_filters( 'seopress_schemas_auto_custom_html', $html );

		echo $html;
	}
}
