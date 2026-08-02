<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Job Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

function seopress_get_schema_metaboxe_jobs( $seopress_pro_rich_snippets_data, $key_schema = 0 ) {
	$seopress_pro_rich_snippets_jobs_name                 = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_name'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_name'] : '';
	$seopress_pro_rich_snippets_jobs_desc                 = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_desc'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_desc'] : '';
	$seopress_pro_rich_snippets_jobs_date_posted          = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_date_posted'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_date_posted'] : '';
	$seopress_pro_rich_snippets_jobs_valid_through        = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_valid_through'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_valid_through'] : '';
	$seopress_pro_rich_snippets_jobs_employment_type      = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_employment_type'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_employment_type'] : '';
	$seopress_pro_rich_snippets_jobs_identifier_name      = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_identifier_name'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_identifier_name'] : '';
	$seopress_pro_rich_snippets_jobs_identifier_value     = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_identifier_value'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_identifier_value'] : '';
	$seopress_pro_rich_snippets_jobs_hiring_organization  = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_hiring_organization'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_hiring_organization'] : '';
	$seopress_pro_rich_snippets_jobs_hiring_same_as       = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_hiring_same_as'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_hiring_same_as'] : '';
	$seopress_pro_rich_snippets_jobs_hiring_logo          = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_hiring_logo'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_hiring_logo'] : '';
	$seopress_pro_rich_snippets_jobs_hiring_logo_width    = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_hiring_logo_width'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_hiring_logo_width'] : '';
	$seopress_pro_rich_snippets_jobs_hiring_logo_height   = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_hiring_logo_height'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_hiring_logo_height'] : '';
	$seopress_pro_rich_snippets_jobs_address_street       = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_address_street'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_address_street'] : '';
	$seopress_pro_rich_snippets_jobs_address_locality     = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_address_locality'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_address_locality'] : '';
	$seopress_pro_rich_snippets_jobs_address_region       = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_address_region'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_address_region'] : '';
	$seopress_pro_rich_snippets_jobs_postal_code          = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_postal_code'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_postal_code'] : '';
	$seopress_pro_rich_snippets_jobs_country              = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_country'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_country'] : '';
	$seopress_pro_rich_snippets_jobs_remote               = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_remote'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_remote'] : '';
	$seopress_pro_rich_snippets_jobs_direct_apply         = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_direct_apply'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_direct_apply'] : '';
	$seopress_pro_rich_snippets_jobs_salary               = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_salary'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_salary'] : '';
	$seopress_pro_rich_snippets_jobs_salary_currency      = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_salary_currency'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_salary_currency'] : '';
	$seopress_pro_rich_snippets_jobs_salary_unit          = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_salary_unit'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_salary_unit'] : '';
	$seopress_pro_rich_snippets_jobs_location_requirement = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_location_requirement'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_jobs_location_requirement'] : ''; ?>
<div class="wrap-rich-snippets-item wrap-rich-snippets-jobs">
	<div class="seopress-notice">
		<p>
			<?php esc_html_e( 'Adding structured data makes your job postings eligible to appear in a special user experience in Google Search results.', 'wp-seopress-pro' ); ?>
		</p>
	</div>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_name_meta">
			<?php esc_html_e( 'Job title', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_jobs_name_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_name]"
			placeholder="<?php esc_html_e( 'The title of the job (not the title of the posting). For example, "Software Engineer" or "Barista".', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Job title', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_name ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_desc_meta">
			<?php esc_html_e( 'Job description', 'wp-seopress-pro' ); ?>
		</label>
		<textarea rows="12" id="seopress_pro_rich_snippets_jobs_desc_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_desc]"
			placeholder="<?php esc_html_e( 'The full description of the job in HTML format.', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Job description', 'wp-seopress-pro' ); ?>"><?php echo wp_kses_post( $seopress_pro_rich_snippets_jobs_desc ); ?></textarea>
	</p>
	<p>
		<label for="seopress-date-picker4">
			<?php esc_html_e( 'Published date', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress-date-picker4" class="seopress-date-picker" autocomplete="off"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_date_posted]"
			placeholder="<?php esc_html_e( 'The original date that employer posted the job in ISO 8601 format. For example, "2017-01-24" or "2017-01-24T19:33:17+00:00".', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Published date', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_date_posted ); ?>" />
	</p>
	<p>
		<label for="seopress-date-picker5">
			<?php esc_html_e( 'Expiration date', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress-date-picker5" class="seopress-date-picker" autocomplete="off"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_valid_through]"
			placeholder="<?php esc_html_e( 'The date when the job posting will expire in ISO 8601 format. For example, "2017-02-24" or "2017-02-24T19:33:17+00:00".', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Expiration date', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_valid_through ); ?>" />
	</p>
	<p class="seopress_pro_rich_snippets_jobs_employment_type_p">
		<label for="seopress_pro_rich_snippets_jobs_employment_type_meta">
			<?php esc_html_e( 'Type of employment', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_jobs_employment_type_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_employment_type]"
			class="seopress_pro_rich_snippets_jobs_employment_type"
			placeholder="<?php esc_html_e( 'Type of employment, You can include more than one employmentType property.', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Type of employment', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_employment_type ); ?>" />

		<span class="wrap-tags">
			<?php
			$employment_type = array(
				'FULL_TIME'  => 'FULL TIME',
				'PART_TIME'  => 'PART TIME',
				'CONTRACTOR' => 'CONTRACTOR',
				'TEMPORARY'  => 'TEMPORARY',
				'INTERN'     => 'INTERN',
				'VOLUNTEER'  => 'VOLUNTEER',
				'PER_DIEM'   => 'PER_DIEM',
				'OTHER'      => 'OTHER',
			);
			$i               = 1;
			foreach ( $employment_type as $key => $value ) {
				?>
			<button type="button" class="<?php echo seopress_btn_secondary_classes(); ?> tag-title" id="seopress-tag-employment-<?php echo absint( $i ); ?>"
				data-tag="<?php echo esc_attr( $key ); ?>"><span
					class="dashicons dashicons-plus-alt2"></span><?php echo esc_attr( $value ); ?></button>
					<?php
						++$i;
			}
			?>
		</span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_identifier_name_meta">
			<?php esc_html_e( 'Identifier name', 'wp-seopress-pro' ); ?></label>
		<input type="text" id="seopress_pro_rich_snippets_jobs_identifier_name_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_identifier_name]"
			placeholder="<?php esc_html_e( 'The hiring organization\'s unique identifier name for the job', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Identifier name', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_identifier_name ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_identifier_value_meta">
			<?php esc_html_e( 'Identifier value', 'wp-seopress-pro' ); ?></label>
		<input type="number" id="seopress_pro_rich_snippets_jobs_identifier_value_meta" min="0"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_identifier_value]"
			placeholder="<?php esc_html_e( 'The hiring organization\'s value identifier value for the job', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Identifier value', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_identifier_value ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_hiring_organization_meta">
			<?php esc_html_e( 'Organization that hires', 'wp-seopress-pro' ); ?>
		</label>
		<span class="description"><?php esc_html_e( 'Default: Organization name from your Knowledge Graph (SEO > Social Networks > Knowledge Graph)', 'wp-seopress-pro' ); ?></span>
		<input type="text" id="seopress_pro_rich_snippets_jobs_hiring_organization_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_hiring_organization]"
			placeholder="<?php esc_html_e( 'The organization offering the job position. This should be the name of the company.', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Organization that hires', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_hiring_organization ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_hiring_same_as_meta">
			<?php esc_html_e( 'Organization website', 'wp-seopress-pro' ); ?>
		</label>
		<span class="description"><?php esc_html_e( 'Default: URL of your site', 'wp-seopress-pro' ); ?></span>
		<input type="text" id="seopress_pro_rich_snippets_jobs_hiring_same_as_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_hiring_same_as]"
			placeholder="<?php esc_html_e( 'The organization website URL offering the job position.', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Organization URL', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_hiring_same_as ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_hiring_logo_meta">
			<?php esc_html_e( 'Organization logo', 'wp-seopress-pro' ); ?>
		</label>
		<span class="description"><?php esc_html_e( 'Default: Logo from your Knowledge Graph (SEO > Social Networks > Knowledge Graph)', 'wp-seopress-pro' ); ?>
		</span>
		<input id="seopress_pro_rich_snippets_jobs_hiring_logo_meta" type="text"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_hiring_logo]"
			placeholder="<?php esc_html_e( 'Select your image', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Organization logo', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_url( $seopress_pro_rich_snippets_jobs_hiring_logo ); ?>" />
		<input id="seopress_pro_rich_snippets_jobs_hiring_logo_width" type="hidden"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_hiring_logo_width]"
			value="" />
		<input id="seopress_pro_rich_snippets_jobs_hiring_logo_height" type="hidden"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_hiring_logo_height]"
			value="" />
		<input id="seopress_pro_rich_snippets_jobs_hiring_logo"
			class="<?php echo seopress_btn_secondary_classes(); ?> seopress_media_upload" type="button"
			value="<?php esc_html_e( 'Upload an Image', 'wp-seopress-pro' ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_address_street_meta">
			<?php esc_html_e( 'Street address', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_jobs_address_street_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_address_street]"
			placeholder="<?php esc_html_e( 'Street address', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Street address', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_address_street ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_address_locality_meta">
			<?php esc_html_e( 'Locality address', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_jobs_address_locality_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_address_locality]"
			placeholder="<?php esc_html_e( 'Locality address', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Locality address', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_address_locality ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_address_region_meta">
			<?php esc_html_e( 'Region', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_jobs_address_region_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_address_region]"
			placeholder="<?php esc_html_e( 'Region', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Region', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_address_region ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_postal_code_meta">
			<?php esc_html_e( 'Postal code', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_jobs_postal_code_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_postal_code]"
			placeholder="<?php esc_html_e( 'Postal code', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Postal code', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_postal_code ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_country_meta">
			<?php esc_html_e( 'Country', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_jobs_country_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_country]"
			placeholder="<?php esc_html_e( 'Country', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Country', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_country ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_remote_meta">
			<input type="checkbox" id="seopress_pro_rich_snippets_jobs_remote_meta"
				name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_remote]"
				aria-label="<?php esc_html_e( 'Remote job', 'wp-seopress-pro' ); ?>"
				<?php
				if ( '1' == $seopress_pro_rich_snippets_jobs_remote ) {
					echo 'checked="yes"';
				}
				?>
			value="1"
			/>
			<?php esc_html_e( 'Remote job?', 'wp-seopress-pro' ); ?>
		</label>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_location_requirement_meta">
			<?php esc_html_e( 'Location requirement for remote job', 'wp-seopress-pro' ); ?>
		</label>

		<input type="text" id="seopress_pro_rich_snippets_jobs_location_requirement_meta"
		name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_location_requirement]"
		placeholder="<?php esc_html_e( 'e.g. FR for France', 'wp-seopress-pro' ); ?>"
		aria-label="<?php esc_html_e( 'e.g. FR for France', 'wp-seopress-pro' ); ?>"
		value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_location_requirement ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_direct_apply_meta">
			<input type="checkbox" id="seopress_pro_rich_snippets_jobs_direct_apply_meta"
				name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_direct_apply]"
				aria-label="<?php esc_html_e( 'Direct apply', 'wp-seopress-pro' ); ?>"
				<?php
				if ( '1' == $seopress_pro_rich_snippets_jobs_direct_apply ) {
					echo 'checked="yes"';
				}
				?>
			value="1"
			/>
			<?php esc_html_e( 'Direct apply?', 'wp-seopress-pro' ); ?>
		</label>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_salary_meta">
			<?php esc_html_e( 'Salary', 'wp-seopress-pro' ); ?>
		</label>
		<input type="number" id="seopress_pro_rich_snippets_jobs_salary_meta" step="0.01" min="0"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_salary]"
			placeholder="<?php esc_html_e( 'e.g. 50.00', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Currency', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_salary ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_salary_currency_meta">
			<?php esc_html_e( 'Currency', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_jobs_salary_currency_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_salary_currency]"
			placeholder="<?php esc_html_e( 'e.g. USD', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Currency', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_jobs_salary_currency ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_salary_unit_meta">
			<?php esc_html_e( 'Select your unit text', 'wp-seopress-pro' ); ?>
		</label>
		<select id="seopress_pro_rich_snippets_jobs_salary_unit_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_jobs_salary_unit]">
			<option <?php selected( 'HOUR', $seopress_pro_rich_snippets_jobs_salary_unit ); ?>
				value="HOUR">
				<?php esc_html_e( 'HOUR', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'DAY', $seopress_pro_rich_snippets_jobs_salary_unit ); ?>
				value="DAY">
				<?php esc_html_e( 'DAY', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'WEEK', $seopress_pro_rich_snippets_jobs_salary_unit ); ?>
				value="WEEK">
				<?php esc_html_e( 'WEEK', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'MONTH', $seopress_pro_rich_snippets_jobs_salary_unit ); ?>
				value="MONTH">
				<?php esc_html_e( 'MONTH', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'YEAR', $seopress_pro_rich_snippets_jobs_salary_unit ); ?>
				value="YEAR">
				<?php esc_html_e( 'YEAR', 'wp-seopress-pro' ); ?>
			</option>
		</select>
	</p>
</div>
	<?php
}
