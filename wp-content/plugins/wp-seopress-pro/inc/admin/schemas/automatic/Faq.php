<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO FAQ Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' ); ?>

<div class="wrap-rich-snippets-faq">
	<div class="seopress-notice">
		<p>
			<?php
				/* translators: %s: link documentation */
				echo wp_kses_post( sprintf( __( 'Learn more about the <strong>FAQ schema</strong> from the <a href="%s" target="_blank">Google official documentation website</a>', 'wp-seopress-pro' ), 'https://developers.google.com/search/docs/data-types/faqpage' ) );
			?>
			<span class="dashicons dashicons-external"></span>
		</p>
	</div>
	<div class="seopress-notice">
		<p>
			<?php
			/* translators: %s: link documentation */
				echo wp_kses_post( sprintf( __( 'Using <strong>Advanced Custom Fields</strong> plugin? Learn <a href="%s" target="_blank">how to use repeater fields to build an automatic FAQ schema</a>', 'wp-seopress-pro' ), esc_url( $docs['schemas']['faq_acf'] ) ) );
			?>
			<span class="dashicons dashicons-external"></span>
		</p>
	</div>
	<p>
		<label for="seopress_pro_rich_snippets_faq_q_meta">
			<?php esc_html_e( 'Question', 'wp-seopress-pro' ); ?>
			<code>name</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_faq_q', 'default' ); ?>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_faq_a_meta">
			<?php esc_html_e( 'Answer', 'wp-seopress-pro' ); ?>
			<code>text</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_faq_a', 'default' ); ?>
	</p>
</div>
