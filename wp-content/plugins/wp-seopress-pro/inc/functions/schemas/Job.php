<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Job Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Automatic rich snippets jobs option.
 *
 * @param array $schema_datas The schema datas.
 * @return void
 */
function seopress_automatic_rich_snippets_jobs_option( $schema_datas ) {
	// If no data.
	if ( 0 != count( array_filter( $schema_datas ) ) ) {
		$jobs_name                = $schema_datas['name'];
		$jobs_desc                = $schema_datas['desc'];
		$jobs_date_posted         = $schema_datas['date_posted'];
		$jobs_valid_through       = $schema_datas['valid_through'];
		$jobs_employment_type     = $schema_datas['employment_type'];
		$jobs_identifier_name     = $schema_datas['identifier_name'];
		$jobs_identifier_value    = $schema_datas['identifier_value'];
		$jobs_hiring_organization = $schema_datas['hiring_organization'];
		$jobs_hiring_same_as      = $schema_datas['hiring_same_as'];
		$jobs_hiring_logo         = $schema_datas['hiring_logo'];
		$jobs_hiring_logo_width   = $schema_datas['hiring_logo_width'];
		$jobs_hiring_logo_height  = $schema_datas['hiring_logo_height'];
		$jobs_address_street      = $schema_datas['address_street'];
		$jobs_address_locality    = $schema_datas['address_locality'];
		$jobs_address_region      = $schema_datas['address_region'];
		$jobs_postal_code         = $schema_datas['postal_code'];
		$jobs_country             = $schema_datas['country'];
		$jobs_remote              = $schema_datas['remote'];
		$jobs_direct_apply        = $schema_datas['direct_apply'];
		$jobs_salary              = $schema_datas['salary'];
		$jobs_salary_currency     = $schema_datas['salary_currency'];
		$jobs_salary_unit         = $schema_datas['salary_unit'];

		$json = array(
			'@context'       => seopress_check_ssl() . 'schema.org/',
			'@type'          => 'JobPosting',
			'title'          => $jobs_name,
			'description'    => $jobs_desc,
			'datePosted'     => $jobs_date_posted,
			'validThrough'   => $jobs_valid_through,
			'employmentType' => $jobs_employment_type,
		);

		if ( '' != $jobs_identifier_name && '' != $jobs_identifier_value ) {
			$json['identifier'] = array(
				'@type' => 'PropertyValue',
				'name'  => $jobs_identifier_name,
				'value' => $jobs_identifier_value,
			);
		}

		if ( '' != $jobs_hiring_organization ) {
			$json['hiringOrganization'] = array(
				'@type' => 'Organization',
				'name'  => $jobs_hiring_organization,
			);
		}

		if ( '' != $jobs_hiring_same_as ) {
			$json['hiringOrganization']['sameAs'] = $jobs_hiring_same_as;
		}

		if ( '' != $jobs_hiring_logo ) {
			$json['hiringOrganization']['logo'] = $jobs_hiring_logo;
			if ( '' != $jobs_hiring_logo_width ) {
				$json['hiringOrganization']['logo']['width'] = $jobs_hiring_logo_width;
			}
			if ( '' != $jobs_hiring_logo_height ) {
				$json['hiringOrganization']['logo']['height'] = $jobs_hiring_logo_height;
			}
		}

		if ( '' != $jobs_address_street || '' != $jobs_address_locality || '' != $jobs_address_region || '' != $jobs_postal_code || '' != $jobs_country ) {
			$json['jobLocation'] = array(
				'@type'   => 'Place',
				'address' => array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => $jobs_address_street,
					'addressLocality' => $jobs_address_locality,
					'addressRegion'   => $jobs_address_region,
					'postalCode'      => $jobs_postal_code,
					'addressCountry'  => $jobs_country,
				),
			);
		}

		if ( '' != $jobs_remote && '' != $jobs_country ) {
			$json['jobLocationType'] = 'TELECOMMUTE';
			if ( isset( $schema_datas['location_requirement'] ) && ! empty( $schema_datas['location_requirement'] ) ) {
				$json['applicantLocationRequirements'] = array(
					'@type' => 'Country',
					'name'  => $schema_datas['location_requirement'],
				);
			}
		}

		if ( '' != $jobs_direct_apply ) {
			$json['directApply'] = true;
		}

		if ( '' != $jobs_salary && '' != $jobs_salary_currency && '' != $jobs_salary_unit ) {
			$json['baseSalary'] = array(
				'@type'    => 'MonetaryAmount',
				'currency' => $jobs_salary_currency,
				'value'    => array(
					'@type'    => 'QuantitativeValue',
					'value'    => $jobs_salary,
					'unitText' => $jobs_salary_unit,
				),
			);
		}

		$json = array_filter( $json );

		$json = apply_filters( 'seopress_schemas_auto_job_json', $json );

		$json = '<script type="application/ld+json">' . wp_json_encode( $json ) . '</script>' . "\n";

		$json = apply_filters( 'seopress_schemas_auto_job_html', $json );

		echo $json;
	}
}
