<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Service Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' ); ?>

<div class="wrap-rich-snippets-services">
	<div class="seopress-notice is-warning">
		<p>
			<?php
				/* translators: %s: link documentation */
				echo wp_kses_post( __( 'We invite webmasters to stop using the <strong>Service schema</strong> as it is deprecated. Please use the <strong>LocalBusiness schema</strong> instead.', 'wp-seopress-pro' ) );
			?>
			<span class="dashicons dashicons-external"></span>
		</p>
	</div>

	<p>
		<label for="seopress_pro_rich_snippets_service_name_meta">
			<?php esc_html_e( 'Service name', 'wp-seopress-pro' ); ?>
			<code>name</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_name', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The name of your service', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_service_type_meta">
			<?php esc_html_e( 'Service type', 'wp-seopress-pro' ); ?>
			<code>serviceType</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_type', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The type of service', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label
			for="seopress_pro_rich_snippets_service_description_meta"><?php esc_html_e( 'Service description', 'wp-seopress-pro' ); ?>
			<code>description</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_description', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The description of your service', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label
			for="seopress_pro_rich_snippets_service_img_meta"><?php esc_html_e( 'Image', 'wp-seopress-pro' ); ?>
			<code>image</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_img', 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
	</p>
	<p>
		<label
			for="seopress_pro_rich_snippets_service_area_meta"><?php esc_html_e( 'Area served', 'wp-seopress-pro' ); ?>
			<code>areaServed</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_area', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The area served by your service', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress-service-provider-source"><?php esc_html_e( 'Provider source', 'wp-seopress-pro' ); ?></label>
		<select id="seopress-service-provider-source" name="seopress_pro_rich_snippets_service_provider_source">
			<option value="none"><?php esc_html_e( 'Use the provider fields below', 'wp-seopress-pro' ); ?></option>
			<option value="knowledge_graph_organization" <?php selected( get_post_meta( get_the_ID(), '_seopress_pro_rich_snippets_service_provider_source', true ), 'knowledge_graph_organization' ); ?>><?php esc_html_e( 'Link to the site Organization', 'wp-seopress-pro' ); ?></option>
		</select>
		<span class="description"><?php esc_html_e( 'Link to the Organization configured in SEO > Social. If unavailable, the provider fields below are used. Person profiles cannot be linked here.', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label
			for="seopress_pro_rich_snippets_service_provider_name_meta"><?php esc_html_e( 'Provider name', 'wp-seopress-pro' ); ?>
			<code>providerName</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_provider_name', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The provider name of your service', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label
			for="seopress_pro_rich_snippets_service_lb_img_meta"><?php esc_html_e( 'Location image', 'wp-seopress-pro' ); ?>
			<code>localBusinessImage</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_lb_img', 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_service_provider_mobility_meta">
			<?php esc_html_e( 'Provider mobility (static or dynamic)', 'wp-seopress-pro' ); ?>
			<code>providerMobility</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_provider_mobility', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The provider mobility of your service', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_service_slogan_meta">
			<?php esc_html_e( 'Slogan', 'wp-seopress-pro' ); ?>
			<code>slogan</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_slogan', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The slogan of your service', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_service_street_addr_meta">
			<?php esc_html_e( 'Street Address', 'wp-seopress-pro' ); ?>
			<code>streetAddress</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_street_addr', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The street address of your service', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_service_city_meta">
			<?php esc_html_e( 'City', 'wp-seopress-pro' ); ?>
			<code>addressLocality</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_city', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The city of your service', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_service_state_meta">
			<?php esc_html_e( 'State', 'wp-seopress-pro' ); ?>
			<code>addressRegion</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_state', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The state of your service', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_service_pc_meta">
			<?php esc_html_e( 'Postal code', 'wp-seopress-pro' ); ?>
			<code>postalCode</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_pc', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The postal code of your service', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_service_country_meta">
			<?php esc_html_e( 'Country', 'wp-seopress-pro' ); ?>
			<code>addressCountry</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_country', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The country of your service (ISO format)', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_service_lat_meta">
			<?php esc_html_e( 'Latitude', 'wp-seopress-pro' ); ?>
			<code>latitude</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_lat', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The latitude of your service', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_service_lon_meta">
			<?php esc_html_e( 'Longitude', 'wp-seopress-pro' ); ?>
			<code>longitude</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_lon', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The longitude of your service', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_service_tel_meta">
			<?php esc_html_e( 'Telephone', 'wp-seopress-pro' ); ?>
			<code>telephone</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_tel', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The telephone of your service (international format)', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_service_price_meta">
			<?php esc_html_e( 'Price range', 'wp-seopress-pro' ); ?>
			<code>priceRange</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_service_price', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The price range of your service', 'wp-seopress-pro' ); ?></span>
	</p>
</div>
