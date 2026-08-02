<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Software App Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' ); ?>

<div class="wrap-rich-snippets-software-app">
	<div class="seopress-notice">
		<p>
			<?php
				/* translators: %s: link documentation */
				echo wp_kses_post( sprintf( __( 'Learn more about the <strong>Software App schema</strong> from the <a href="%s" target="_blank">Google official documentation website</a>', 'wp-seopress-pro' ), 'https://developers.google.com/search/docs/data-types/software-app' ) );
			?>
			<span class="dashicons dashicons-external"></span>
		</p>
	</div>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_name_meta">
			<?php esc_html_e( 'Software name', 'wp-seopress-pro' ); ?>
			<code>name</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_softwareapp_name', 'default' ); ?>
		<span
			class="description"><?php esc_html_e( 'The name of your app', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_os_meta">
			<?php esc_html_e( 'Operating system', 'wp-seopress-pro' ); ?>
			<code>operatingSystem</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_softwareapp_os', 'default' ); ?>
		<span
			class="description"><?php esc_html_e( 'The operating system(s) required to use the app', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_cat_meta">
			<?php esc_html_e( 'Application category', 'wp-seopress-pro' ); ?>
			<code>applicationCategory</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_softwareapp_cat', 'default' ); ?>
		<span
			class="description"><?php echo wp_kses_post( __( '<strong>Authorized values:</strong> "GameApplication", "SocialNetworkingApplication", "TravelApplication", "ShoppingApplication", "SportsApplication", "LifestyleApplication", "BusinessApplication", "DesignApplication", "DeveloperApplication", "DriverApplication", "EducationalApplication", "HealthApplication", "FinanceApplication", "SecurityApplication", "BrowserApplication", "CommunicationApplication", "DesktopEnhancementApplication", "EntertainmentApplication", "MultimediaApplication", "HomeApplication", "UtilitiesApplication", "ReferenceApplication"', 'wp-seopress-pro' ) ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_price_meta">
			<?php esc_html_e( 'Price of your app', 'wp-seopress-pro' ); ?>
			<code>price</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_softwareapp_price', 'default' ); ?>
		<span
			class="description"><?php esc_html_e( 'The price of your app (set "0" if the app is free of charge)', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_currency_meta">
			<?php esc_html_e( 'Currency', 'wp-seopress-pro' ); ?>
			<code>priceCurrency</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_softwareapp_currency', 'default' ); ?>
		<span
			class="description"><?php esc_html_e( 'e.g. USD, EUR...', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_rating_meta">
			<?php esc_html_e( 'Your rating', 'wp-seopress-pro' ); ?>
			<code>ratingValue</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_softwareapp_rating', 'rating' ); ?>
		<span
			class="description"><?php esc_html_e( 'The item rating', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_max_rating_meta">
			<?php esc_html_e( 'Max best rating', 'wp-seopress-pro' ); ?>
			<code>bestRating</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_softwareapp_max_rating', 'rating' ); ?>
		<span
			class="description"><?php esc_html_e( 'Only required if your scale is different from 1 to 5.', 'wp-seopress-pro' ); ?></span>
	</p>
</div>
