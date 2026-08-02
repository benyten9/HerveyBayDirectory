<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class Faq extends JsonSchemaValue implements GetJsonData {
	const NAME = 'faq';

	protected function getName() {
		return self::NAME;
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

		$questions = array();
		switch ( $typeSchema ) {
			case RichSnippetType::MANUAL:
				$schemaManual = $this->getCurrentSchemaManual( $context );

				if ( null === $schemaManual ) {
					return $data;
				}

				if ( isset( $schemaManual['_seopress_pro_rich_snippets_faq'] ) ) {
					$questions = $schemaManual['_seopress_pro_rich_snippets_faq'];
				}
				break;
		}

		if ( ! empty( $questions ) ) {
			foreach ( $questions as $key => $question ) {
				$variables = array(
					'name'        => $question['question'],
					'text'        => $question['answer'],
					'answerCount' => 1,
				);

				$schema = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( MainEntity::NAME, array( 'variables' => $variables ), array( 'remove_empty' => true ) );

				if ( count( $schema ) > 1 && isset( $schema['name'] ) && ! empty( $schema['name'] ) ) {
					$data['mainEntity'][] = $schema;
				}
			}
		}

		return apply_filters( 'seopress_pro_get_json_data_faq', $data, $context );
	}
}
