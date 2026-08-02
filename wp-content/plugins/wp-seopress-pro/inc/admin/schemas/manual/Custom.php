<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Custom Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

function seopress_get_schema_metaboxe_custom( $seopress_pro_rich_snippets_data, $key_schema = 0 ) {
	$docs = function_exists( 'seopress_get_docs_links' ) ? seopress_get_docs_links() : '';

	$seopress_pro_rich_snippets_custom = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_custom'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_custom'] : ''; ?>

<div class="wrap-rich-snippets-item wrap-rich-snippets-custom">
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
		<textarea rows="25" id="seopress_pro_rich_snippets_custom_meta"
			class="seopress-custom-schema-textarea"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_custom]"
			placeholder="
			<?php
			esc_html_e(
				'e.g. <script type="application/ld+json">{
				"@context": "https://schema.org/",
				"@type": "Review",
				"itemReviewed": {
				"@type": "Restaurant",
				"image": "http://www.example.com/seafood-restaurant.jpg",
				"name": "Legal Seafood",
				"servesCuisine": "Seafood",
				"telephone": "1234567",
				"address" :{
					"@type": "PostalAddress",
					"streetAddress": "123 William St",
					"addressLocality": "New York",
					"addressRegion": "NY",
					"postalCode": "10038",
					"addressCountry": "US"
				}
				},
				"reviewRating": {
				"@type": "Rating",
				"ratingValue": "4"
				},
				"name": "A good seafood place.",
				"author": {
				"@type": "Person",
				"name": "Bob Smith"
				},
				"reviewBody": "The seafood is great.",
				"publisher": {
				"@type": "Organization",
				"name": "Washington Times"
				}
			}</script>',
				'wp-seopress-pro'
			);
			?>
							"
			aria-label="<?php esc_html_e( 'Custom schema', 'wp-seopress-pro' ); ?>"><?php echo esc_textarea( $seopress_pro_rich_snippets_custom ); ?></textarea>
	</p>
	<p class="description">
		<?php /* translators: %s: documentation link */ echo wp_kses_post( sprintf( __( '<a href="%s" target="_blank">You can use dynamic variables in your schema.</a>', 'wp-seopress-pro' ), esc_url( $docs['schemas']['dynamic'] ) ) ); ?>
		<span class="seopress-help dashicons dashicons-external"></span>
	</p>
</div>
	<?php
}
