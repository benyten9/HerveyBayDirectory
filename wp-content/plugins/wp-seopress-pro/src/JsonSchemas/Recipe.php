<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class Recipe extends JsonSchemaValue implements GetJsonData {
	const NAME = 'recipe';

	const ALIAS = array( 'recipes' );

	protected function getName() {
		return self::NAME;
	}

	/**
	 * @since 4.6.0
	 *
	 * @return array
	 *
	 * @param array $schemaManual
	 */
	protected function getVariablesForManualSnippet( $schemaManual ) {
		$total = '';
		if ( isset( $schemaManual['_seopress_pro_rich_snippets_recipes_prep_time'], $schemaManual['_seopress_pro_rich_snippets_recipes_cook_time'] ) ) {
			$total = (int) $schemaManual['_seopress_pro_rich_snippets_recipes_prep_time'] + (int) $schemaManual['_seopress_pro_rich_snippets_recipes_cook_time'];
		}

		$variables = array(
			'type'           => isset( $schemaManual['_seopress_pro_rich_snippets_type'] ) ? $schemaManual['_seopress_pro_rich_snippets_type'] : '',
			'name'           => isset( $schemaManual['_seopress_pro_rich_snippets_recipes_name'] ) ? $schemaManual['_seopress_pro_rich_snippets_recipes_name'] : '',
			'description'    => isset( $schemaManual['_seopress_pro_rich_snippets_recipes_desc'] ) ? $schemaManual['_seopress_pro_rich_snippets_recipes_desc'] : '',
			'recipeCategory' => isset( $schemaManual['_seopress_pro_rich_snippets_recipes_cat'] ) ? $schemaManual['_seopress_pro_rich_snippets_recipes_cat'] : '',
			'image'          => isset( $schemaManual['_seopress_pro_rich_snippets_recipes_img'] ) ? $schemaManual['_seopress_pro_rich_snippets_recipes_img'] : '',
			'video'          => isset( $schemaManual['_seopress_pro_rich_snippets_recipes_video'] ) ? $schemaManual['_seopress_pro_rich_snippets_recipes_video'] : '',
			'prepTime'       => isset( $schemaManual['_seopress_pro_rich_snippets_recipes_prep_time'] ) ? sprintf( 'PT%sM', $schemaManual['_seopress_pro_rich_snippets_recipes_prep_time'] ) : '',
			'totalTime'      => ! empty( $total ) ? sprintf( 'PT%sM', $total ) : '',
			'recipeYield'    => isset( $schemaManual['_seopress_pro_rich_snippets_recipes_yield'] ) ? $schemaManual['_seopress_pro_rich_snippets_recipes_yield'] : '',
			'keywords'       => isset( $schemaManual['_seopress_pro_rich_snippets_recipes_keywords'] ) ? $schemaManual['_seopress_pro_rich_snippets_recipes_keywords'] : '',
			'recipeCuisine'  => isset( $schemaManual['_seopress_pro_rich_snippets_recipes_cuisine'] ) ? $schemaManual['_seopress_pro_rich_snippets_recipes_cuisine'] : '',
		);

		return $variables;
	}

	/**
	 * @since 4.6.0
	 *
	 * @param array $context
	 *
	 * @return array
	 */
	public function getJsonData( $context = null ) {
		$data = $this->getArrayJson();

		$typeSchema = isset( $context['type'] ) ? $context['type'] : RichSnippetType::MANUAL;

		$variables    = array();
		$schemaManual = array();
		switch ( $typeSchema ) {
			case RichSnippetType::MANUAL:
				$schemaManual = $this->getCurrentSchemaManual( $context );

				if ( null === $schemaManual ) {
					return $data;
				}

				$variables = $this->getVariablesForManualSnippet( $schemaManual );
				break;
		}

		if ( isset( $context['post']->ID ) ) {
			$variables['datePublished'] = get_the_date( 'Y-m-j', $context['post']->ID );
		}

		$data = seopress_get_service( 'VariablesToString' )->replaceDataToString( $data, $variables );

		$contextWithVariables              = $context;
		$contextWithVariables['variables'] = array(
			'name' => '%%post_author%%',
		);
		$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Author::NAME, $contextWithVariables, array( 'remove_empty' => true ) );

		if ( count( $schema ) > 1 ) {
			$data['author'] = $schema;
		}

		if ( isset( $schemaManual['_seopress_pro_rich_snippets_recipes_ingredient'] ) ) {
			$ingredients = preg_split( '/\r\n|[\r\n]/', $schemaManual['_seopress_pro_rich_snippets_recipes_ingredient'] );

			$data['recipeIngredient'] = $ingredients;
		}

		if ( isset( $schemaManual['_seopress_pro_rich_snippets_recipes_instructions'] ) ) {
			$instructions = preg_split( '/\r\n|[\r\n]/', $schemaManual['_seopress_pro_rich_snippets_recipes_instructions'] );

			foreach ( $instructions as $key => $value ) {
				$variablesHowTo['text'] = $value;
				$schema                 = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( HowToStep::NAME, array( 'variables' => $variablesHowTo ), array( 'remove_empty' => true ) );

				if ( count( $schema ) > 1 ) {
					$data['recipeInstructions'][] = $schema;
				}
			}
		}

		if ( isset( $schemaManual['_seopress_pro_rich_snippets_recipes_calories'] ) ) {
			$schema = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema(
				NutritionInformation::NAME,
				array(
					'variables' => array(
						'calories' => $schemaManual['_seopress_pro_rich_snippets_recipes_calories'],
					),
				),
				array( 'remove_empty' => true )
			);

			$data['nutrition'] = $schema;
		}

		return apply_filters( 'seopress_pro_get_json_data_recipe', $data, $context );
	}
}
