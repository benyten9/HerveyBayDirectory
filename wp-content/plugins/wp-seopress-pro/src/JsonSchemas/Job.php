<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\JsonSchemas\Organization;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class Job extends JsonSchemaValue implements GetJsonData {
	const NAME = 'job';

	const ALIAS = array( 'jobs' );

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
		$keys      = array(
			'jobType'                       => '_seopress_pro_rich_snippets_type',
			'title'                         => '_seopress_pro_rich_snippets_jobs_name',
			'description'                   => '_seopress_pro_rich_snippets_jobs_desc',
			'datePosted'                    => '_seopress_pro_rich_snippets_jobs_date_posted',
			'validThrough'                  => '_seopress_pro_rich_snippets_jobs_valid_through',
			'employmentType'                => '_seopress_pro_rich_snippets_jobs_employment_type',
			'identifierName'                => '_seopress_pro_rich_snippets_jobs_identifier_name',
			'identifierValue'               => '_seopress_pro_rich_snippets_jobs_identifier_value',
			'hiringOrganization'            => '_seopress_pro_rich_snippets_jobs_hiring_organization',
			'hiringSameAs'                  => '_seopress_pro_rich_snippets_jobs_hiring_same_as',
			'hiringLogo'                    => '_seopress_pro_rich_snippets_jobs_hiring_logo',
			'hiringLogoWidth'               => '_seopress_pro_rich_snippets_jobs_hiring_logo_width',
			'hiringLogoHeight'              => '_seopress_pro_rich_snippets_jobs_hiring_logo_height',
			'addressStreet'                 => '_seopress_pro_rich_snippets_jobs_address_street',
			'addressLocality'               => '_seopress_pro_rich_snippets_jobs_address_locality',
			'addressRegion'                 => '_seopress_pro_rich_snippets_jobs_address_region',
			'postalCode'                    => '_seopress_pro_rich_snippets_jobs_postal_code',
			'addressCountry'                => '_seopress_pro_rich_snippets_jobs_country',
			'remote'                        => '_seopress_pro_rich_snippets_jobs_remote',
			'direct_apply'                  => '_seopress_pro_rich_snippets_jobs_direct_apply',
			'salary'                        => '_seopress_pro_rich_snippets_jobs_salary',
			'salaryCurrency'                => '_seopress_pro_rich_snippets_jobs_salary_currency',
			'salaryUnit'                    => '_seopress_pro_rich_snippets_jobs_salary_unit',
			'applicantLocationRequirements' => '_seopress_pro_rich_snippets_jobs_location_requirement',
		);
		$variables = array();

		foreach ( $keys as $key => $value ) {
			$variables[ $key ] = isset( $schemaManual[ $value ] ) ? $schemaManual[ $value ] : '';
		}

		return $variables;
	}

	/**
	 * @since 4.5.0
	 *
	 * @param array $context
	 *
	 * @return array
	 */
	public function getJsonData( $context = null ) {
		$data = $this->getArrayJson();

		$typeSchema = isset( $context['type'] ) ? $context['type'] : RichSnippetType::MANUAL;

		switch ( $typeSchema ) {
			case RichSnippetType::MANUAL:
				$schemaManual = $this->getCurrentSchemaManual( $context );

				if ( null === $schemaManual ) {
					return $data;
				}

				$variables = $this->getVariablesForManualSnippet( $schemaManual );
				break;
		}

		$variablesSchema                   = array(
			'type' => 'Organization',
			'name' => ( isset( $variables['hiringOrganization'] ) && ! empty( $variables['hiringOrganization'] ) ) ? $variables['hiringOrganization'] : '%%social_knowledge_name%%',
			'logo' => ( isset( $variables['hiringLogo'] ) && ! empty( $variables['hiringLogo'] ) ) ? $variables['hiringLogo'] : '%%social_knowledge_image%%',
		);
		$contextWithVariables              = $context;
		$contextWithVariables['variables'] = $variablesSchema;
		$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;

		$schema = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Organization::NAME, $contextWithVariables, array( 'remove_empty' => true ) );

		if ( count( $schema ) > 1 ) {
			$data['hiringOrganization']           = $schema;
			$data['hiringOrganization']['sameAs'] = isset( $variables['hiringSameAs'] ) && ! empty( $variables['hiringSameAs'] ) ? $variables['hiringSameAs'] : '%%siteurl%%';
		}

		if ( isset( $variables['identifierName'] ) && ! empty( $variables['identifierName'] ) ) {
			$data['identifier'] = array(
				'@type' => 'PropertyValue',
				'name'  => $variables['identifierName'],
				'value' => isset( $variables['identifierValue'] ) ? $variables['identifierValue'] : '',
			);
		}

		if ( isset( $variables['addressStreet'] ) && ! empty( $variables['addressStreet'] ) ) {
			$variablesSchema = array(
				'streetAddress'   => $variables['addressStreet'],
				'addressLocality' => isset( $variables['addressLocality'] ) ? $variables['addressLocality'] : '',
				'addressRegion'   => isset( $variables['addressRegion'] ) ? $variables['addressRegion'] : '',
				'postalCode'      => isset( $variables['postalCode'] ) ? $variables['postalCode'] : '',
				'addressCountry'  => isset( $variables['addressCountry'] ) ? $variables['addressCountry'] : '',
			);

			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = $variablesSchema;
			$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
			$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Place::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
			if ( count( $schema ) > 1 ) {
				$data['jobLocation'] = $schema;
			}
		}

		if ( isset( $variables['remote'] ) && ! empty( $variables['remote'] ) ) {
			$data['jobLocationType'] = 'TELECOMMUTE';

			if ( isset( $variables['applicantLocationRequirements'] ) && ! empty( $variables['applicantLocationRequirements'] ) ) {
				$data['applicantLocationRequirements'] = array(
					'@type' => 'Country',
					'name'  => $variables['applicantLocationRequirements'],
				);
			}
		}

		if ( isset( $variables['direct_apply'] ) && ! empty( $variables['direct_apply'] ) ) {
			// schema.org's property is "directApply" (camelCase); "direct_apply"
			// is flagged as unrecognized by the schema.org validator. This mirrors
			// the automatic builder in inc/functions/schemas/Job.php.
			$data['directApply'] = true;
		}

		if ( isset( $variables['salary'] ) && ! empty( $variables['salary'] ) ) {
			$variablesSchema = array(
				'currency'           => isset( $variables['salaryCurrency'] ) ? $variables['salaryCurrency'] : '',
				'quantity_value'     => isset( $variables['salary'] ) ? $variables['salary'] : '',
				'quantity_unit_text' => isset( $variables['salaryUnit'] ) ? $variables['salaryUnit'] : '',
			);

			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = $variablesSchema;
			$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
			$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( MonetaryAmount::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
			if ( count( $schema ) > 1 ) {
				$data['baseSalary'] = $schema;
			}
		}

		$data = seopress_get_service( 'VariablesToString' )->replaceDataToString( $data, $variables );

		return apply_filters( 'seopress_pro_get_json_data_job', $data, $context );
	}

	/**
	 * @since 4.6.0
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public function cleanValues( $data ) {
		if ( isset( $data['hiringOrganization']['contactPoint'] ) ) {
			unset( $data['hiringOrganization']['contactPoint'] );
		}

		return $data;
	}
}
