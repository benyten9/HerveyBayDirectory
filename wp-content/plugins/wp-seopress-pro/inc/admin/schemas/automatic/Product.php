<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Product Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' ); ?>

<div class="wrap-rich-snippets-products">
	<div class="seopress-notice">
		<p>
			<?php
			/* translators: %s: link documentation */
				echo wp_kses_post( sprintf( __( 'Learn more about the <strong>Product schema</strong> from the <a href="%s" target="_blank">Google official documentation website</a>', 'wp-seopress-pro' ), 'https://developers.google.com/search/docs/data-types/product' ) );
			?>
			<span class="dashicons dashicons-external"></span>
		</p>
	</div>

	<?php
	if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
		if ( ( 'no' == get_option( 'woocommerce_enable_reviews' ) && get_option( 'woocommerce_enable_reviews' ) ) || ( 'no' == get_option( 'woocommerce_enable_review_rating' ) && get_option( 'woocommerce_enable_review_rating' ) ) || ( 'no' == get_option( 'woocommerce_review_rating_required' ) && get_option( 'woocommerce_review_rating_required' ) ) ) {
			?>
	<div class="seopress-notice">
		<p>
			<?php echo wp_kses_post( __( 'To automatically add <strong>aggregateRating</strong> and <strong>Review</strong> properties to your schema, you have to enable <strong>User Reviews</strong> from WooCommerce settings.', 'wp-seopress-pro' ) ); ?>
		</p>
		<p>
			<?php
			/* translators: %s: link to plugin settings page */
			echo wp_kses_post( sprintf( __( 'Please activate these options from <strong>WC settings</strong>, <strong>Products</strong>, <a href="%s"><strong>General tab</strong></a>:', 'wp-seopress-pro' ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=products' ) ) ) );
			?>
		</p>
		<ul>
			<?php
		}
		if ( 'no' == get_option( 'woocommerce_enable_reviews' ) && get_option( 'woocommerce_enable_reviews' ) ) {
			?>
			<li>
				<span class="dashicons dashicons-minus"></span>
				<?php esc_html_e( 'Enable product reviews', 'wp-seopress-pro' ); ?>
			</li>
			<?php
		}
		if ( 'no' == get_option( 'woocommerce_enable_review_rating' ) && get_option( 'woocommerce_enable_review_rating' ) ) {
			?>
			<li>
				<span class="dashicons dashicons-minus"></span>
				<?php esc_html_e( 'Enable star rating on reviews', 'wp-seopress-pro' ); ?>
			</li>
			<?php
		}
		if ( 'no' == get_option( 'woocommerce_review_rating_required' ) && get_option( 'woocommerce_review_rating_required' ) ) {
			?>
			<li>
				<span class="dashicons dashicons-minus"></span>
				<?php esc_html_e( 'Star ratings should be required, not optional', 'wp-seopress-pro' ); ?>
				<?php
		}
		if ( ( 'no' == get_option( 'woocommerce_enable_reviews' ) && get_option( 'woocommerce_enable_reviews' ) ) || ( 'no' == get_option( 'woocommerce_enable_review_rating' ) && get_option( 'woocommerce_enable_review_rating' ) ) || ( 'no' == get_option( 'woocommerce_review_rating_required' ) && get_option( 'woocommerce_review_rating_required' ) ) ) {
			echo '</ul></div>';
		}

		// WooCommerce Structured data.
		if ( '1' !== seopress_pro_get_service( 'OptionPro' )->getWCDisableSchemaOutput() ) {
			?>
				<div class="seopress-notice is-error">
					<p>
						<?php
						/* translators: %s: link to plugin settings page */
						echo wp_kses_post( sprintf( __( 'You have not deactivated the default WooCommerce structured data type from our <a href="%s"><strong>PRO settings > WooCommerce tab</strong></a>. It\'s recommended to disable it to avoid any conflicts with your product schemas.', 'wp-seopress-pro' ), esc_url( admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_woocommerce' ) ) ) );
						?>
					</p>
				</div>
				<?php
		}
	} else {
		?>
		<div class="seopress-notice is-error">
			<p>
				<?php echo wp_kses_post( __( 'WooCommerce is not enabled on your site. Some properties like <strong>aggregateRating</strong> and <strong>Review</strong> will not work out of the box.', 'wp-seopress-pro' ) ); ?>
			</p>
		</div>
		<?php } ?>

		<p>
			<label for="seopress_pro_rich_snippets_product_name_meta">
				<?php esc_html_e( 'Product name', 'wp-seopress-pro' ); ?>
				<code>name</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_name', 'default' ); ?>
			<span
				class="description"><?php esc_html_e( 'The name of your product', 'wp-seopress-pro' ); ?></span>
		</p>
		<p>
			<label
				for="seopress_pro_rich_snippets_product_description_meta"><?php esc_html_e( 'Product description', 'wp-seopress-pro' ); ?>
				<code>description</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_description', 'default' ); ?>
			<span
				class="description"><?php esc_html_e( 'The description of the product', 'wp-seopress-pro' ); ?></span>
		</p>
		<p>
			<label
				for="seopress_pro_rich_snippets_product_img_meta"><?php esc_html_e( 'Thumbnail', 'wp-seopress-pro' ); ?>
				<code>image</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_img', 'image' ); ?>
			<span
				class="description"><?php esc_html_e( 'Pictures clearly showing the product, e.g. against a white background, are preferred.', 'wp-seopress-pro' ); ?></span>
		</p>
		<p>
			<label for="seopress_pro_rich_snippets_product_price_meta">
				<?php esc_html_e( 'Product price', 'wp-seopress-pro' ); ?>
				<code>price</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_price', 'default' ); ?>
			<span
				class="description"><?php esc_html_e( 'e.g. 30. Even if this value is set to None, we‘ll still display a value for this property to follow Google guidelines and avoid warnings in Google Search Console.', 'wp-seopress-pro' ); ?></span>
		</p>
		<p>
			<label
				for="seopress_pro_rich_snippets_product_price_valid_date"><?php esc_html_e( 'Product price valid until', 'wp-seopress-pro' ); ?>
				<code>priceValidDate</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_price_valid_date', 'date' ); ?>
			<span
				class="description"><?php esc_html_e( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' ); ?></span>
		</p>
		<p>
			<label for="seopress_pro_rich_snippets_product_sku_meta">
				<?php esc_html_e( 'Product SKU', 'wp-seopress-pro' ); ?>
				<code>sku</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_sku', 'default' ); ?>
			<span
				class="description"><?php esc_html_e( 'e.g. 0446310786', 'wp-seopress-pro' ); ?></span>
		</p>
		<p>
			<label for="seopress_pro_rich_snippets_product_global_ids_meta">
				<?php esc_html_e( 'Product Global Identifiers type', 'wp-seopress-pro' ); ?>
				<code>globalIds</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_global_ids', 'default' ); ?>
			<span
				class="description"><?php esc_html_e( 'e.g. gtin8', 'wp-seopress-pro' ); ?></span>
		</p>
		<p>
			<label for="seopress_pro_rich_snippets_product_global_ids_value_meta">
				<?php esc_html_e( 'Product Global Identifiers', 'wp-seopress-pro' ); ?>
				<code>globalIdsValue</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_global_ids_value', 'default' ); ?>
			<span
				class="description"><?php esc_html_e( 'e.g. 925872', 'wp-seopress-pro' ); ?></span>
		</p>
		<p>
			<label for="seopress_pro_rich_snippets_product_brand_meta">
				<?php esc_html_e( 'Product Brand', 'wp-seopress-pro' ); ?>
				<code>brand</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_brand', 'default' ); ?>
			<span
				class="description"><?php esc_html_e( 'e.g. Apple', 'wp-seopress-pro' ); ?></span>
		</p>
		<p>
			<label for="seopress_pro_rich_snippets_product_price_currency_meta">
				<?php esc_html_e( 'Product currency', 'wp-seopress-pro' ); ?>
				<code>priceCurrency</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_price_currency', 'default' ); ?>
			<span
				class="description"><?php esc_html_e( 'e.g. USD, EUR', 'wp-seopress-pro' ); ?></span>
		</p>
		<p>
			<label
				for="seopress_pro_rich_snippets_product_condition_meta"><?php esc_html_e( 'Product Condition', 'wp-seopress-pro' ); ?>
				<code>condition</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_condition', 'default' ); ?>
			<span
				class="description"><?php echo wp_kses_post( __( '<strong>Authorized values:</strong> "NewCondition", "UsedCondition", "DamagedCondition", "RefurbishedCondition"', 'wp-seopress-pro' ) ); ?></span>
		</p>
		<p>
			<label
				for="seopress_pro_rich_snippets_product_availability_meta"><?php esc_html_e( 'Product Availability', 'wp-seopress-pro' ); ?>
				<code>availability</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_availability', 'default' ); ?>
			<span
				class="description"><?php echo wp_kses_post( __( '<strong>Authorized values:</strong> "InStock", "InStoreOnly", "OnlineOnly", "LimitedAvailability", "SoldOut", "OutOfStock", "Discontinued", "PreOrder", "PreSale"', 'wp-seopress-pro' ) ); ?></span>
		</p>
		<p>
			<label
				for="seopress_pro_rich_snippets_product_positive_notes"><?php esc_html_e( 'Positive Notes', 'wp-seopress-pro' ); ?>
				<code>positiveNotes</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_positive_notes', 'default' ); ?>
		</p>
		<p>
			<label
				for="seopress_pro_rich_snippets_product_negative_notes"><?php esc_html_e( 'Negative Notes', 'wp-seopress-pro' ); ?>
				<code>negativeNotes</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_negative_notes', 'default' ); ?>
		</p>
		<p>
			<label
				for="seopress_pro_rich_snippets_product_energy_consumption"><?php esc_html_e( 'Energy Consumption', 'wp-seopress-pro' ); ?>
				<code>energy_consumption</code>
			</label>
			<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_product_energy_consumption', 'default' ); ?>
		</p>
	</div>
