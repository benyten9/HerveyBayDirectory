<?php

namespace SEOPressPro\Services\Forms\Schemas;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Core\FormApi;

class FormSchemaJob extends FormApi {
	protected function getTypeByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_jobs_desc':
				return 'textarea';
			case '_seopress_pro_rich_snippets_jobs_hiring_logo':
				return 'upload';
			case '_seopress_pro_rich_snippets_jobs_remote':
				return 'checkbox';
			case '_seopress_pro_rich_snippets_jobs_direct_apply':
				return 'checkbox';
			case '_seopress_pro_rich_snippets_jobs_salary':
				return 'number';
			case '_seopress_pro_rich_snippets_jobs_date_posted':
			case '_seopress_pro_rich_snippets_jobs_valid_through':
				return 'date';
			case '_seopress_pro_rich_snippets_jobs_name':
			case '_seopress_pro_rich_snippets_jobs_employment_type':
			case '_seopress_pro_rich_snippets_jobs_identifier_name':
			case '_seopress_pro_rich_snippets_jobs_identifier_value':
			case '_seopress_pro_rich_snippets_jobs_hiring_organization':
			case '_seopress_pro_rich_snippets_jobs_hiring_same_as':
			case '_seopress_pro_rich_snippets_jobs_address_street':
			case '_seopress_pro_rich_snippets_jobs_address_locality':
			case '_seopress_pro_rich_snippets_jobs_address_region':
			case '_seopress_pro_rich_snippets_jobs_postal_code':
			case '_seopress_pro_rich_snippets_jobs_country':
			case '_seopress_pro_rich_snippets_jobs_salary_currency':
			case '_seopress_pro_rich_snippets_jobs_location_requirement':
				return 'input';
			case '_seopress_pro_rich_snippets_jobs_salary_unit':
				return 'select';
		}
	}

	protected function getLabelByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_jobs_name':
				return __( 'Job title', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_desc':
				return __( 'Job description', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_date_posted':
				return __( 'Published date', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_valid_through':
				return __( 'Expiration date', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_employment_type':
				return __( 'Type of employment', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_identifier_name':
				return __( 'Identifier name', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_identifier_value':
				return __( 'Identifier value', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_hiring_organization':
				return __( 'Organization that hires', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_hiring_same_as':
				return __( 'Organization website', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_hiring_logo':
				return __( 'Organization logo', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_address_street':
				return __( 'Street address', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_address_locality':
				return __( 'Locality address', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_address_region':
				return __( 'Region', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_postal_code':
				return __( 'Postal code', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_country':
				return __( 'Country', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_remote':
				return __( 'Remote job', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_direct_apply':
				return __( 'Direct apply', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_salary':
				return __( 'Salary', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_salary_currency':
				return __( 'Currency', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_salary_unit':
				return __( 'Select your unit text', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_location_requirement':
				return __( 'Location requirement for remote job', 'wp-seopress-pro' );
		}
	}

	protected function getPlaceholderByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_jobs_name':
				return __( 'The title of the job (not the title of the posting). For example, "Software Engineer" or "Barista".', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_desc':
				return __( 'The full description of the job in HTML format.', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_date_posted':
			case '_seopress_pro_rich_snippets_jobs_valid_through':
				return __( 'The original date that employer posted the job in ISO 8601 format. For example, "2017-01-24" or "2017-01-24T19:33:17+00:00".', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_valid_through':
				return __( 'The date when the job posting will expire in ISO 8601 format. For example, "2017-02-24" or "2017-02-24T19:33:17+00:00".', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_employment_type':
				return __( 'Type of employment, You can include more than one employmentType property.', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_identifier_name':
				return __( "The hiring organization's unique identifier name for the job", 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_identifier_value':
				return __( "The hiring organization's value identifier value for the job", 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_hiring_organization':
				return __( 'The organization offering the job position. This should be the name of the company.', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_hiring_same_as':
				return __( 'The organization website URL offering the job position.', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_hiring_logo':
				return __( 'Select your image', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_address_street':
				return __( 'Street address', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_address_locality':
				return __( 'Locality address', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_address_region':
				return __( 'Region', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_postal_code':
				return __( 'Postal code', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_country':
				return __( 'Country', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_direct_apply':
				/* translators: do not translate expected values, true / false  */
				return __( 'Indicates whether the URL that\'s associated with this job posting enables direct application for the job. Expected value: "true" or "false".', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_salary':
				return __( 'e.g. 50.00', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_salary_currency':
				return __( 'e.g. USD', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_location_requirement':
				return __( 'e.g. FR for France', 'wp-seopress-pro' );
		}
	}

	protected function getDescriptionByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_jobs_hiring_organization':
				return __( 'Default: Organization name from your Knowledge Graph (SEO > Social Networks > Knowledge Graph)', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_hiring_same_as':
				return __( 'Default: URL of your site', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_jobs_hiring_logo':
				return __( 'Default: Logo from your Knowledge Graph (SEO > Social Networks > Knowledge Graph)', 'wp-seopress-pro' );
		}
	}

	protected function getOptions( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_jobs_salary_unit':
				return array(
					array(
						'value' => 'HOUR',
						'label' => __( 'HOUR', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'DAY',
						'label' => __( 'DAY', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'WEEK',
						'label' => __( 'WEEK', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'MONTH',
						'label' => __( 'MONTH', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'YEAR',
						'label' => __( 'YEAR', 'wp-seopress-pro' ),
					),
				);
		}
	}

	protected function getDetails( $postId = null ) {
		return array(
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_name',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_jobs_desc',
				'class' => 'seopress-textarea-high-size',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_date_posted',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_valid_through',
			),
			array(
				'key'     => '_seopress_pro_rich_snippets_jobs_employment_type',
				'options' => array(
					'separator'     => ',',
					'quick_buttons' => array(
						array(
							'value' => 'FULL_TIME',
							'label' => 'FULL TIME',
						),
						array(
							'value' => 'PART_TIME',
							'label' => 'PART TIME',
						),
						array(
							'value' => 'CONTRACTOR',
							'label' => 'CONTRACTOR',
						),
						array(
							'value' => 'TEMPORARY',
							'label' => 'TEMPORARY',
						),
						array(
							'value' => 'INTERN',
							'label' => 'INTERN',
						),
						array(
							'value' => 'VOLUNTEER',
							'label' => 'VOLUNTEER',
						),
						array(
							'value' => 'PER_DIEM',
							'label' => 'PER DIEM',
						),
						array(
							'value' => 'OTHER',
							'label' => 'OTHER',
						),
					),
				),
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_identifier_name',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_identifier_value',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_hiring_organization',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_hiring_same_as',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_hiring_logo',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_hiring_logo_width',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_hiring_logo_height',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_address_street',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_address_locality',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_address_region',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_postal_code',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_country',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_remote',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_location_requirement',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_direct_apply',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_salary',
				'min' => 1,
			),
			array(
				'key' => '_seopress_pro_rich_snippets_jobs_salary_currency',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_jobs_salary_unit',
				'value' => 'HOUR',
			),

		);
	}
}
