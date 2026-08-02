<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Custom Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' ); ?>

<div class="wrap-rich-snippets-custom">
	<!-- Validation error message (hidden by default, shown by JS if validation fails) -->
	<div class="seopress-notice is-error seopress-custom-schema-validation" style="display: none;">
		<p>
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %1$s: opening script tag code, %2$s: closing script tag code */
					__( '<strong>Error:</strong> Your custom schema is missing the required %1$s and/or %2$s tags. Please add them to prevent the schema from displaying as visible text on your page.', 'wp-seopress-pro' ),
					'<code>&lt;script type="application/ld+json"&gt;</code>',
					'<code>&lt;/script&gt;</code>'
				)
			);
			?>
		</p>
	</div>

	<p>
		<label for="seopress_pro_rich_snippets_custom_meta">
			<?php esc_html_e( 'Custom schema', 'wp-seopress-pro' ); ?>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_custom', 'custom' ); ?>
	</p>

	<p class="description">
		<?php
			/* translators: %s link documentation */
			echo wp_kses_post( sprintf( __( '<a href="%s" target="_blank">You can use dynamic variables in your schema.</a>', 'wp-seopress-pro' ), esc_url( $docs['schemas']['dynamic'] ) ) );
		?>
		<span class="seopress-help dashicons dashicons-external"></span>
	</p>
</div>
