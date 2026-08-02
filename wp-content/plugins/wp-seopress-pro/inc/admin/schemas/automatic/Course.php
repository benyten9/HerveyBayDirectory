<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Course Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' ); ?>

<div class="wrap-rich-snippets-courses">
	<div class="seopress-notice">
		<p>
			<?php
				/* translators: %s: link documentation */
				echo wp_kses_post( sprintf( __( 'Learn more about the <strong>Course schema</strong> from the <a href="%s" target="_blank">Google official documentation website</a>', 'wp-seopress-pro' ), 'https://developers.google.com/search/docs/data-types/course' ) );
			?>
			<span class="dashicons dashicons-external"></span>
		</p>
	</div>
	<p>
		<label for="seopress_pro_rich_snippets_courses_title_meta">
			<?php esc_html_e( 'Title', 'wp-seopress-pro' ); ?>
			<code>name</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_courses_title', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'The title of your lesson, course...', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_courses_desc_meta">
			<?php esc_html_e( 'Course description', 'wp-seopress-pro' ); ?>
			<code>description</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_courses_desc', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Enter your course/lesson description', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_courses_school_meta">
			<?php esc_html_e( 'School/Organization', 'wp-seopress-pro' ); ?>
			<code>school</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_courses_school', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Name of university, organization...', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_courses_website_meta">
			<?php esc_html_e( 'School/Organization Website', 'wp-seopress-pro' ); ?>
			<code>website</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_courses_website', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Enter the URL like https://example.com/', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_courses_offers_meta">
			<?php esc_html_e( 'Offers', 'wp-seopress-pro' ); ?>
			<code>offers</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_courses_offers', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'List of Offers', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_courses_instances_meta">
			<?php esc_html_e( 'Course instances', 'wp-seopress-pro' ); ?>
			<code>instances</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_courses_instances', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'List of CourseInstance', 'wp-seopress-pro' ); ?></span>
	</p>
</div>
