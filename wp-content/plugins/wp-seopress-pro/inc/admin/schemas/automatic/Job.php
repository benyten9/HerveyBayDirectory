<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Job Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' ); ?>

<div class="wrap-rich-snippets-jobs">
	<div class="seopress-notice">
		<p>
			<?php
				/* translators: %s: link documentation */
				echo wp_kses_post( sprintf( __( 'Learn more about the <strong>Job Posting schema</strong> from the <a href="%s" target="_blank">Google official documentation website</a>', 'wp-seopress-pro' ), 'https://developers.google.com/search/docs/data-types/job-posting' ) );
			?>
			<span class="dashicons dashicons-external"></span>
		</p>
	</div>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_name_meta">
			<?php esc_html_e( 'Job title', 'wp-seopress-pro' ); ?>
			<code>title</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_name', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Job title', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_desc_meta">
			<?php esc_html_e( 'Job description', 'wp-seopress-pro' ); ?>
			<code>description</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_desc', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Job description', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_date_posted_meta">
			<?php esc_html_e( 'Published date', 'wp-seopress-pro' ); ?>
			<code>datePosted</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_date_posted', 'date' ); ?>
		<span class="description"><?php esc_html_e( 'The original date that employer posted the job in ISO 8601 format. For example, "2017-01-24" or "2017-01-24T19:33:17+00:00".', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_valid_through_meta">
			<?php esc_html_e( 'Expiration date', 'wp-seopress-pro' ); ?>
			<code>validThrough</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_valid_through', 'date' ); ?>
		<span class="description"><?php esc_html_e( 'The date when the job posting will expire in ISO 8601 format. For example, "2017-02-24" or "2017-02-24T19:33:17+00:00".', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_employment_type_meta">
			<?php esc_html_e( 'Type of employment', 'wp-seopress-pro' ); ?>
			<code>employmentType</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_employment_type', 'default' ); ?>
		<span class="description">
			<?php
				/* translators: do not translate authorized values, e.g. FULL_TIME  */
				esc_html_e( 'Type of employment, You can include more than one employmentType property. Authorized values: "FULL_TIME", "PART_TIME", "CONTRACTOR", "TEMPORARY", "INTERN", "VOLUNTEER", "PER_DIEM", "OTHER"', 'wp-seopress-pro' );
			?>
		</span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_identifier_name_meta"><?php esc_html_e( 'Identifier name', 'wp-seopress-pro' ); ?>
			<code>identifierName</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_identifier_name', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'The hiring organization\'s unique identifier name for the job', 'wp-seopress-pro' ); ?></span>
	</p>

	<p>
		<label for="seopress_pro_rich_snippets_jobs_identifier_value_meta"><?php esc_html_e( 'Identifier value', 'wp-seopress-pro' ); ?>
			<code>identifierValue</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_identifier_value', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'The hiring organization\'s value identifier value for the job', 'wp-seopress-pro' ); ?></span>
	</p>
	
	<hr>
	
	<h3><?php esc_html_e( 'Hiring Organization', 'wp-seopress-pro' ); ?></h3>

	<p>
		<label for="seopress_pro_rich_snippets_jobs_hiring_organization_meta"><?php esc_html_e( 'Organization that hires', 'wp-seopress-pro' ); ?>
			<code>hiringOrganization</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_hiring_organization', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'The organization offering the job position. This should be the name of the company.', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_hiring_same_as_meta"><?php esc_html_e( 'Organization website', 'wp-seopress-pro' ); ?>
			<code>hiringSameAs</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_hiring_same_as', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Enter the URL like https://example.com/', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_hiring_logo_meta"><?php esc_html_e( 'Image', 'wp-seopress-pro' ); ?>
			<code>hiringLogo</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_hiring_logo', 'image' ); ?>
		<span class="description"><?php esc_html_e( 'Default: Logo from your Knowledge Graph (SEO > Social Networks > Knowledge Graph)', 'wp-seopress-pro' ); ?></span>
	</p>
	
	<hr>

	<p>
		<label for="seopress_pro_rich_snippets_jobs_address_street_meta"><?php esc_html_e( 'Street address', 'wp-seopress-pro' ); ?>
			<code>addressStreet</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_address_street', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Street address', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_address_locality_meta"><?php esc_html_e( 'Locality address', 'wp-seopress-pro' ); ?>
			<code>addressLocality</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_address_locality', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Locality address', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_address_region_meta"><?php esc_html_e( 'Region', 'wp-seopress-pro' ); ?>
			<code>addressRegion</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_address_region', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Region', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_postal_code_meta"><?php esc_html_e( 'Postal code', 'wp-seopress-pro' ); ?>
			<code>postalCode</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_postal_code', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Postal code', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_country_meta"><?php esc_html_e( 'Country', 'wp-seopress-pro' ); ?>
			<code>addressCountry</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_country', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Country', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_remote_meta"><?php esc_html_e( 'Remote job?', 'wp-seopress-pro' ); ?>
			<code>remote</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_remote', 'default' ); ?>
		<span class="description">
			<?php esc_html_e( 'If a value exists (e.g. "yes"), the job offer will be marked as fully remote. Don\'t mark up jobs that allow occasional work-from-home, jobs for which remote work is a negotiable benefit, or have other arrangements that are not 100% remote. The "gig economy" nature of a job doesn\'t imply that it is or is not remote.', 'wp-seopress-pro' ); ?>
		</span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_location_requirement_meta"><?php esc_html_e( 'Location requirement for remote job', 'wp-seopress-pro' ); ?>
			<code>applicantLocationRequirements</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_location_requirement', 'default' ); ?>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_direct_apply_meta"><?php esc_html_e( 'Direct apply?', 'wp-seopress-pro' ); ?>
			<code>direct_apply</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_direct_apply', 'default' ); ?>
		<span class="description">
			<?php
			/* translators: do not translate expected values, true / false  */
			esc_html_e( 'Indicates whether the URL that\'s associated with this job posting enables direct application for the job. Expected value: "true" or "false".', 'wp-seopress-pro' );
			?>
		</span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_salary_meta"><?php esc_html_e( 'Salary', 'wp-seopress-pro' ); ?>
			<code>salary</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_salary', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. 50.00', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_salary_currency_meta"><?php esc_html_e( 'Currency', 'wp-seopress-pro' ); ?>
			<code>salaryCurrency</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_salary_currency', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. USD', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_jobs_salary_unit_meta"><?php esc_html_e( 'Select your unit text', 'wp-seopress-pro' ); ?>
			<code>salaryUnit</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_jobs_salary_unit', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Authorized values: "HOUR", "DAY", "WEEK", "MONTH", "YEAR"', 'wp-seopress-pro' ); ?></span>
	</p>
</div>
