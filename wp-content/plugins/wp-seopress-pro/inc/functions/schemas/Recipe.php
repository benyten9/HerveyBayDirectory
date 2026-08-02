<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Recipe Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Automatic rich snippets recipes option.
 *
 * @param array $schema_datas The schema datas.
 * @return void
 */
function seopress_automatic_rich_snippets_recipes_option( $schema_datas ) {
	// If no data.
	if ( 0 != count( array_filter( $schema_datas ) ) ) {
		$recipes_name         = $schema_datas['name'];
		$recipes_desc         = $schema_datas['desc'];
		$recipes_cat          = $schema_datas['cat'];
		$recipes_img          = $schema_datas['img'];
		$recipes_video        = $schema_datas['video'];
		$recipes_prep_time    = $schema_datas['prep_time'];
		$recipes_cook_time    = $schema_datas['cook_time'];
		$recipes_calories     = $schema_datas['calories'];
		$recipes_yield        = $schema_datas['yield'];
		$recipes_keywords     = $schema_datas['keywords'];
		$recipes_cuisine      = $schema_datas['cuisine'];
		$recipes_ingredient   = $schema_datas['ingredient'];
		$recipes_instructions = $schema_datas['instructions'];

		$json = array(
			'@context'       => seopress_check_ssl() . 'schema.org/',
			'@type'          => 'Recipe',
			'name'           => $recipes_name,
			'recipeCategory' => $recipes_cat,
			'image'          => $recipes_img,
			'description'    => $recipes_desc,
			'video'          => $recipes_video,
			'prepTime'       => 'PT' . $recipes_prep_time . 'M',
			'totalTime'      => 'PT' . $recipes_cook_time . 'M',
			'recipeYield'    => $recipes_yield,
			'keywords'       => $recipes_keywords,
			'recipeCuisine'  => $recipes_cuisine,
		);

		if ( get_the_author() ) {
			$json['author'] = array(
				'@type' => 'Person',
				'name'  => get_the_author(),
			);
		}

		if ( get_the_date() ) {
			$json['datePublished'] = get_the_date( 'Y-m-j' );
		}

		if ( '' != $recipes_ingredient ) {
			$recipes_ingredient = preg_split( '/\r\n|[\r\n]/', $recipes_ingredient );
			if ( ! empty( $recipes_ingredient ) ) {
				$i     = '0';
				$count = count( $recipes_ingredient );

				foreach ( $recipes_ingredient as $value ) {
					$json['recipeIngredient'][] = $value;
					++$i;
				}
			}
		}
		if ( '' != $recipes_instructions ) {
			$recipes_instructions = preg_split( '/\r\n|[\r\n]/', $recipes_instructions );
			if ( ! empty( $recipes_instructions ) ) {
				$i     = '0';
				$count = count( $recipes_instructions );

				foreach ( $recipes_instructions as $value ) {
					$json['recipeInstructions'][] = array(
						'@type' => 'HowToStep',
						'text'  => $value,
					);

					++$i;
				}
			}
		}
		if ( '' != $recipes_calories ) {
			$json['nutrition'] = array(
				'@type'    => 'NutritionInformation',
				'calories' => $recipes_calories,
			);
		}

		$json = array_filter( $json );

		$json = apply_filters( 'seopress_schemas_auto_recipe_json', $json );

		$json = '<script type="application/ld+json">' . wp_json_encode( $json ) . '</script>' . "\n";

		$json = apply_filters( 'seopress_schemas_auto_recipe_html', $json );

		echo $json;
	}
}
