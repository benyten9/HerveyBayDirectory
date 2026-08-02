<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\JsonSchemas\Organization;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class CourseInstance extends JsonSchemaValue implements GetJsonData {
	const NAME = 'course-instance';

	protected function getName() {
		return self::NAME;
	}

	/**
	 * @since 7.5.0
	 *
	 * @param array $context
	 *
	 * @return array
	 */
	public function getJsonData( $context = null ) {
		$data = $this->getArrayJson();

		$variables = $context['variables'];
		if ( ! empty( $variables['repeatCount'] && (int) $variables['repeatCount'] > 1 ) ) {
			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = $variables;
			$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
			$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Schedule::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
			if ( ! empty( $schema ) ) {
				$data['courseSchedule'] = $schema;
			}
		} elseif ( ! empty( $variables['duration'] ) ) {
			$data['courseWorkload'] = sprintf( 'PT%dH', (int) $variables['duration'] );
		}

		$data = seopress_get_service( 'VariablesToString' )->replaceDataToString( $data, $variables );

		return apply_filters( 'seopress_pro_get_json_data_course_instance', $data, $context );
	}
}
