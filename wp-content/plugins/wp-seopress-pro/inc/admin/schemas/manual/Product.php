<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Product Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

function seopress_get_schema_metaboxe_product( $seopress_pro_rich_snippets_data, $key_schema = 0 ) {
	$options_currencies = seopress_get_options_schema_currencies();

	$seopress_pro_rich_snippets_product_name               = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_name'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_name'] : '';
	$seopress_pro_rich_snippets_product_description        = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_description'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_description'] : '';
	$seopress_pro_rich_snippets_product_img                = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_img'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_img'] : '';
	$seopress_pro_rich_snippets_product_price              = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_price'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_price'] : '';
	$seopress_pro_rich_snippets_product_price_valid_date   = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_price_valid_date'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_price_valid_date'] : '';
	$seopress_pro_rich_snippets_product_sku                = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_sku'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_sku'] : '';
	$seopress_pro_rich_snippets_product_brand              = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_brand'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_brand'] : '';
	$seopress_pro_rich_snippets_product_global_ids         = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_global_ids'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_global_ids'] : '';
	$seopress_pro_rich_snippets_product_global_ids_value   = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_global_ids_value'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_global_ids_value'] : '';
	$seopress_pro_rich_snippets_product_price_currency     = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_price_currency'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_price_currency'] : '';
	$seopress_pro_rich_snippets_product_condition          = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_condition'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_condition'] : '';
	$seopress_pro_rich_snippets_product_availability       = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_availability'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_availability'] : '';
	$seopress_pro_rich_snippets_product_positive_notes     = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_positive_notes'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_positive_notes'] : array();
	$seopress_pro_rich_snippets_product_negative_notes     = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_negative_notes'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_negative_notes'] : array();
	$seopress_pro_rich_snippets_product_energy_consumption = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_energy_consumption'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_product_energy_consumption'] : 'none';

	?>
<div class="wrap-rich-snippets-item wrap-rich-snippets-products">
	<div class="seopress-notice">
		<p>
			<?php esc_html_e( 'Add markup to your product pages so Google can provide detailed product information in rich Search results - including Image Search. Users can see price, availability... right on Search results.', 'wp-seopress-pro' ); ?>
		</p>
	</div>
	<div class="seopress-notice is-warning">
		<ul class="advice seopress-list">
			<li><?php esc_html_e( 'Use markup for a specific product, not a category or list of products. For example, "shoes in our shop" is not a specific product.', 'wp-seopress-pro' ); ?>
			</li>
			<li><?php esc_html_e( 'Adult-related products are not supported.', 'wp-seopress-pro' ); ?>
			</li>
			<li><?php esc_html_e( 'Works best with WooCommerce: we automatically add aggregateRating properties from user reviews (you have to enable this option from WooCommerce settings).', 'wp-seopress-pro' ); ?>
			</li>
		</ul>
	</div>
	<p>
		<label for="seopress_pro_rich_snippets_product_name_meta">
			<?php esc_html_e( 'Product name', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_product_name_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_name]"
			placeholder="<?php esc_html_e( 'The name of your product', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Product name', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_product_name ); ?>" />
		<span class="description"><?php esc_html_e( 'Default: product title', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_product_description_meta">
			<?php esc_html_e( 'Product description', 'wp-seopress-pro' ); ?>
		</label>
		<textarea id="seopress_pro_rich_snippets_product_description_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_description]"
			placeholder="<?php esc_html_e( 'The description of the product', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Product description', 'wp-seopress-pro' ); ?>"><?php echo wp_kses_post( $seopress_pro_rich_snippets_product_description ); ?></textarea>
		<span class="description"><?php esc_html_e( 'Default: product excerpt', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_product_img_meta">
			<?php esc_html_e( 'Thumbnail', 'wp-seopress-pro' ); ?>
		</label>
		<span class="description"><?php esc_html_e( 'Pictures clearly showing the product, e.g. against a white background, are preferred', 'wp-seopress-pro' ); ?></span>
		<input id="seopress_pro_rich_snippets_product_img_meta" type="text"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_img]"
			placeholder="<?php esc_html_e( 'Select your image', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Thumbnail', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_url( $seopress_pro_rich_snippets_product_img ); ?>" />
		<input id="seopress_pro_rich_snippets_product_img"
			class="<?php echo esc_attr( seopress_btn_secondary_classes() ); ?> seopress_media_upload"
			type="button"
			value="<?php esc_html_e( 'Upload an Image', 'wp-seopress-pro' ); ?>" />
		<span class="description"><?php esc_html_e( 'Default: product image', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_product_price_meta">
			<?php esc_html_e( 'Product price', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_product_price_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_price]"
			placeholder="<?php esc_html_e( 'e.g. 30', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Product price', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_product_price ); ?>" />
		<span class="description"><?php esc_html_e( 'Default: active product price', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress-date-picker6">
			<?php esc_html_e( 'Product price valid until', 'wp-seopress-pro' ); ?>
		</label>
		<input id="seopress-date-picker6" type="text"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_price_valid_date]"
			class="seopress-date-picker"
			placeholder="<?php esc_html_e( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Product price valid until', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_product_price_valid_date ); ?>" />
		<span class="description"><?php esc_html_e( 'Default: sale price dates To field', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_product_sku_meta">
			<?php esc_html_e( 'Product SKU', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_product_sku_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_sku]"
			placeholder="<?php esc_html_e( 'e.g. 0446310786', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Product SKU', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_product_sku ); ?>" />
		<span class="description"><?php esc_html_e( 'Default: product SKU', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_product_global_ids_meta">
			<?php esc_html_e( 'Product Global Identifiers type', 'wp-seopress-pro' ); ?>
		</label>
		<select id="seopress_pro_rich_snippets_product_global_ids_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_global_ids]">
			<option <?php selected( 'none', $seopress_pro_rich_snippets_product_global_ids ); ?>
				value="none"><?php esc_html_e( 'Select a global identifier', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'gtin8', $seopress_pro_rich_snippets_product_global_ids ); ?>
				value="gtin8"><?php esc_html_e( 'gtin8 (ean8)', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'gtin12', $seopress_pro_rich_snippets_product_global_ids ); ?>
				value="gtin12"><?php esc_html_e( 'gtin12 (ean12)', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'gtin13', $seopress_pro_rich_snippets_product_global_ids ); ?>
				value="gtin13"><?php esc_html_e( 'gtin13 (ean13)', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'gtin14', $seopress_pro_rich_snippets_product_global_ids ); ?>
				value="gtin14"><?php esc_html_e( 'gtin14 (ean14)', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'mpn', $seopress_pro_rich_snippets_product_global_ids ); ?>
				value="mpn"><?php esc_html_e( 'mpn', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'isbn', $seopress_pro_rich_snippets_product_global_ids ); ?>
				value="isbn"><?php esc_html_e( 'isbn', 'wp-seopress-pro' ); ?>
			</option>
		</select>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_product_global_ids_value_meta">
			<?php esc_html_e( 'Product Global Identifier value', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_product_global_ids_value_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_global_ids_value]"
			placeholder="<?php esc_html_e( 'e.g. 925872', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Product Global Identifiers', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_product_global_ids_value ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_product_brand_meta">
			<?php esc_html_e( 'Product Brand', 'wp-seopress-pro' ); ?>
		</label>
		<?php
				$serviceWpData   = seopress_get_service( 'WordPressData' );
		$seopress_get_taxonomies = array();
		if ( $serviceWpData && method_exists( $serviceWpData, 'getTaxonomies' ) ) {
			$seopress_get_taxonomies = $serviceWpData->getTaxonomies();
		}
		if ( ! empty( $seopress_get_taxonomies ) ) {
			?>
		<select id="seopress_pro_rich_snippets_product_brand_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_brand]">
			<option <?php selected( 'none', $seopress_pro_rich_snippets_product_brand ); ?>
				value="none">
				<?php esc_html_e( 'Select a taxonomy', 'wp-seopress-pro' ); ?>
			</option>

			<?php foreach ( $seopress_get_taxonomies as $key => $value ) { ?>
			<option <?php selected( $key, $seopress_pro_rich_snippets_product_brand ); ?>
				value="<?php echo esc_attr( $key ); ?>"><?php echo esc_attr( $key ); ?>
			</option>
			<?php } ?>
		</select>
			<?php
		}
		?>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_product_price_currency_meta">
			<?php esc_html_e( 'Product currency', 'wp-seopress-pro' ); ?>
		</label>
		<select id="seopress_pro_rich_snippets_product_price_currency_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_price_currency]">
			<?php foreach ( $options_currencies as $item ) { ?>
			<option <?php selected( $item['value'], $seopress_pro_rich_snippets_product_price_currency ); ?>
				value="<?php echo esc_attr( $item['value'] ); ?>">
				<?php echo esc_attr( $item['label'] ); ?>
			</option>
			<?php } ?>
		</select>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_product_condition_meta"><?php esc_html_e( 'Product Condition', 'wp-seopress-pro' ); ?>
		</label>
		<select id="seopress_pro_rich_snippets_product_condition_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_condition]">
			<option <?php selected( 'NewCondition', $seopress_pro_rich_snippets_product_condition ); ?>
				value="NewCondition"><?php esc_html_e( 'New', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'UsedCondition', $seopress_pro_rich_snippets_product_condition ); ?>
				value="UsedCondition"><?php esc_html_e( 'Used', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'DamagedCondition', $seopress_pro_rich_snippets_product_condition ); ?>
				value="DamagedCondition"><?php esc_html_e( 'Damaged', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'RefurbishedCondition', $seopress_pro_rich_snippets_product_condition ); ?>
				value="RefurbishedCondition"><?php esc_html_e( 'Refurbished', 'wp-seopress-pro' ); ?>
			</option>
		</select>
		<span class="description"><?php esc_html_e( 'Default: new', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_product_availability_meta"><?php esc_html_e( 'Product Availability', 'wp-seopress-pro' ); ?>
		</label>
		<select id="seopress_pro_rich_snippets_product_availability_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_availability]">
			<option <?php selected( 'InStock', $seopress_pro_rich_snippets_product_availability ); ?>
				value="InStock"><?php esc_html_e( 'In Stock', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'InStoreOnly', $seopress_pro_rich_snippets_product_availability ); ?>
				value="InStoreOnly"><?php esc_html_e( 'In Store Only', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'OnlineOnly', $seopress_pro_rich_snippets_product_availability ); ?>
				value="OnlineOnly"><?php esc_html_e( 'Online Only', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'LimitedAvailability', $seopress_pro_rich_snippets_product_availability ); ?>
				value="LimitedAvailability"><?php esc_html_e( 'Limited Availability', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'SoldOut', $seopress_pro_rich_snippets_product_availability ); ?>
				value="SoldOut"><?php esc_html_e( 'Sold Out', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'OutOfStock', $seopress_pro_rich_snippets_product_availability ); ?>
				value="OutOfStock"><?php esc_html_e( 'Out Of Stock', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'Discontinued', $seopress_pro_rich_snippets_product_availability ); ?>
				value="Discontinued"><?php esc_html_e( 'Discontinued', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'PreOrder', $seopress_pro_rich_snippets_product_availability ); ?>
				value="PreOrder"><?php esc_html_e( 'Pre Order', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'PreSale', $seopress_pro_rich_snippets_product_availability ); ?>
				value="PreSale"><?php esc_html_e( 'Pre Sale', 'wp-seopress-pro' ); ?>
			</option>
		</select>
	</p>

	<p>
		<label for="_seopress_pro_rich_snippets_product_energy_consumption"><?php esc_html_e( 'Energy Consumption', 'wp-seopress-pro' ); ?>
		</label>
		<select id="_seopress_pro_rich_snippets_product_energy_consumption"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_energy_consumption]">
			<option <?php selected( 'none', $seopress_pro_rich_snippets_product_energy_consumption ); ?>
				value="none"><?php esc_html_e( 'Select an Energy Consumption', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'https://schema.org/EUEnergyEfficiencyCategoryA3Plus', $seopress_pro_rich_snippets_product_energy_consumption ); ?>
				value="https://schema.org/EUEnergyEfficiencyCategoryA3Plus"><?php esc_html_e( 'A+++', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'https://schema.org/EUEnergyEfficiencyCategoryA2Plus', $seopress_pro_rich_snippets_product_energy_consumption ); ?>
				value="https://schema.org/EUEnergyEfficiencyCategoryA2Plus"><?php esc_html_e( 'A++', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'https://schema.org/EUEnergyEfficiencyCategoryA1Plus', $seopress_pro_rich_snippets_product_energy_consumption ); ?>
				value="https://schema.org/EUEnergyEfficiencyCategoryA1Plus"><?php esc_html_e( 'A+', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'https://schema.org/EUEnergyEfficiencyCategoryA', $seopress_pro_rich_snippets_product_energy_consumption ); ?>
				value="https://schema.org/EUEnergyEfficiencyCategoryA"><?php esc_html_e( 'A', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'https://schema.org/EUEnergyEfficiencyCategoryB', $seopress_pro_rich_snippets_product_energy_consumption ); ?>
				value="https://schema.org/EUEnergyEfficiencyCategoryB"><?php esc_html_e( 'B', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'https://schema.org/EUEnergyEfficiencyCategoryC', $seopress_pro_rich_snippets_product_energy_consumption ); ?>
				value="https://schema.org/EUEnergyEfficiencyCategoryC"><?php esc_html_e( 'C', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'https://schema.org/EUEnergyEfficiencyCategoryD', $seopress_pro_rich_snippets_product_energy_consumption ); ?>
				value="https://schema.org/EUEnergyEfficiencyCategoryD"><?php esc_html_e( 'D', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'https://schema.org/EUEnergyEfficiencyCategoryE', $seopress_pro_rich_snippets_product_energy_consumption ); ?>
				value="https://schema.org/EUEnergyEfficiencyCategoryE"><?php esc_html_e( 'E', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'https://schema.org/EUEnergyEfficiencyCategoryF', $seopress_pro_rich_snippets_product_energy_consumption ); ?>
				value="https://schema.org/EUEnergyEfficiencyCategoryF"><?php esc_html_e( 'F', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'https://schema.org/EUEnergyEfficiencyCategoryG', $seopress_pro_rich_snippets_product_energy_consumption ); ?>
				value="https://schema.org/EUEnergyEfficiencyCategoryG"><?php esc_html_e( 'G', 'wp-seopress-pro' ); ?>
			</option>
		</select>
	</p>

	<p>
		<label for="seopress_pro_rich_snippets_product_positive_notes">
			<?php esc_html_e( 'Positive notes', 'wp-seopress-pro' ); ?>
		</label>
	</p>
	<div id="wrap-positive-notes" data-count="<?php echo count( $seopress_pro_rich_snippets_product_positive_notes ); ?>">


		<?php
		foreach ( $seopress_pro_rich_snippets_product_positive_notes as $key => $value ) {
				$name = isset( $value['name'] ) ? $value['name'] : null;
			?>
			<div class="positive_notes">
				<h3 class="accordion-section-title" tabindex="0">
					<?php if ( empty( $name ) ) { ?>
						<span style="color:red">
						<?php esc_html_e( 'Empty Statement', 'wp-seopress-pro' ); ?>
						</span>
						<?php
					} else {
						echo esc_attr( $name );
					}
					?>
				</h3>
				<div class="accordion-section-content">
					<div class="inside">
						<p>
							<label
								for="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_positive_notes][<?php echo esc_attr( $key ); ?>][name]">
								<?php esc_html_e( 'Name (required)', 'wp-seopress-pro' ); ?>
							</label>
							<input
								id="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_positive_notes][<?php echo esc_attr( $key ); ?>][name]"
								type="text"
								name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_positive_notes][<?php echo esc_attr( $key ); ?>][name]"
								placeholder="<?php esc_html_e( 'Enter your name', 'wp-seopress-pro' ); ?>"
								aria-label="<?php esc_html_e( 'Name', 'wp-seopress-pro' ); ?>"
								value="<?php echo esc_attr( $name ); ?>" />
						</p>
						<p>
							<a href="#" class="remove-positive-note button">
								<?php esc_html_e( 'Remove statement', 'wp-seopress-pro' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>
			<?php
		}
		?>
	</div>
	<p>
		<a href="#" id="add-positive-note" class="add-positive-note <?php echo esc_attr( seopress_btn_secondary_classes() ); ?>">
			<?php esc_html_e( 'Add statement', 'wp-seopress-pro' ); ?>
		</a>
	</p>

	<template
		id="schema-template-positive-note">
		<div class="positive_notes">
			<h3 class="accordion-section-title" tabindex="0">
				<span style="color:red">
					<?php esc_html_e( 'Empty Statement', 'wp-seopress-pro' ); ?>
				</span>
			</h3>
			<div class="accordion-section-content">
				<div class="inside">
					<p>
						<label
							for="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_positive_notes][X][name]">
							<?php esc_html_e( 'Name (required)', 'wp-seopress-pro' ); ?>
						</label>
						<input
							id="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_positive_notes][X][name]"
							type="text"
							name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_positive_notes][X][name]"
							placeholder="<?php esc_html_e( 'Enter your name', 'wp-seopress-pro' ); ?>"
							aria-label="<?php esc_html_e( 'Name', 'wp-seopress-pro' ); ?>"
							value="" />
					</p>
					<p>
						<a href="#" class="remove-positive-note button">
							<?php esc_html_e( 'Remove statement', 'wp-seopress-pro' ); ?>
						</a>
					</p>
				</div>
			</div>
		</div>
	</template>

	<p>
		<label for="seopress_pro_rich_snippets_product_negative_notes">
			<?php esc_html_e( 'Negative notes', 'wp-seopress-pro' ); ?>
		</label>
	</p>
	<div id="wrap-negative-notes" data-count="<?php echo count( $seopress_pro_rich_snippets_product_negative_notes ); ?>">


		<?php
		foreach ( $seopress_pro_rich_snippets_product_negative_notes as $key => $value ) {
				$name = isset( $value['name'] ) ? $value['name'] : null;
			?>
			<div class="negative_notes">
				<h3 class="accordion-section-title" tabindex="0">
					<?php if ( empty( $name ) ) { ?>
						<span style="color:red">
						<?php esc_html_e( 'Empty Statement', 'wp-seopress-pro' ); ?>
						</span>
						<?php
					} else {
						echo esc_attr( $name );
					}
					?>
				</h3>
				<div class="accordion-section-content">
					<div class="inside">
						<p>
							<label
								for="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_negative_notes][<?php echo esc_attr( $key ); ?>][name]">
								<?php esc_html_e( 'Name (required)', 'wp-seopress-pro' ); ?>
							</label>
							<input
								id="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_negative_notes][<?php echo esc_attr( $key ); ?>][name]"
								type="text"
								name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_negative_notes][<?php echo esc_attr( $key ); ?>][name]"
								placeholder="<?php esc_html_e( 'Enter your name', 'wp-seopress-pro' ); ?>"
								aria-label="<?php esc_html_e( 'Name', 'wp-seopress-pro' ); ?>"
								value="<?php echo esc_attr( $name ); ?>" />
						</p>
						<p>
							<a href="#" class="remove-negative-note button">
								<?php esc_html_e( 'Remove statement', 'wp-seopress-pro' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>
			<?php
		}

		?>
	</div>
	<p>
		<a href="#" id="add-negative-note" class="add-negative-note <?php echo esc_attr( seopress_btn_secondary_classes() ); ?>">
			<?php esc_html_e( 'Add statement', 'wp-seopress-pro' ); ?>
		</a>
	</p>

	<template
		id="schema-template-negative-note">
		<div class="negative_notes">
			<h3 class="accordion-section-title" tabindex="0">
				<span style="color:red">
					<?php esc_html_e( 'Empty Statement', 'wp-seopress-pro' ); ?>
				</span>
			</h3>
			<div class="accordion-section-content">
				<div class="inside">
					<p>
						<label
							for="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_negative_notes][X][name]">
							<?php esc_html_e( 'Name (required)', 'wp-seopress-pro' ); ?>
						</label>
						<input
							id="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_negative_notes][X][name]"
							type="text"
							name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_product_negative_notes][X][name]"
							placeholder="<?php esc_html_e( 'Enter your name', 'wp-seopress-pro' ); ?>"
							aria-label="<?php esc_html_e( 'Name', 'wp-seopress-pro' ); ?>"
							value="" />
					</p>
					<p>
						<a href="#" class="remove-negative-note button">
							<?php esc_html_e( 'Remove statement', 'wp-seopress-pro' ); ?>
						</a>
					</p>
				</div>
			</div>
		</div>
	</template>
</div>
	<?php
}
