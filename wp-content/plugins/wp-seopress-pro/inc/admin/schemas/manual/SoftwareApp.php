<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Software App Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

function seopress_get_schema_metaboxe_software( $seopress_pro_rich_snippets_data, $key_schema = 0 ) {
	$options_software = array(
		array(
			'value' => 'GameApplication',
			'label' => __( 'GameApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'SocialNetworkingApplication',
			'label' => __( 'SocialNetworkingApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'TravelApplication',
			'label' => __( 'TravelApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'ShoppingApplication',
			'label' => __( 'ShoppingApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'SportsApplication',
			'label' => __( 'SportsApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'LifestyleApplication',
			'label' => __( 'LifestyleApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'BusinessApplication',
			'label' => __( 'BusinessApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'DesignApplication',
			'label' => __( 'DesignApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'DeveloperApplication',
			'label' => __( 'DeveloperApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'DriverApplication',
			'label' => __( 'DriverApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'EducationalApplication',
			'label' => __( 'EducationalApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'HealthApplication',
			'label' => __( 'HealthApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'FinanceApplication',
			'label' => __( 'FinanceApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'SecurityApplication',
			'label' => __( 'SecurityApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'BrowserApplication',
			'label' => __( 'BrowserApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'CommunicationApplication',
			'label' => __( 'CommunicationApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'DesktopEnhancementApplication',
			'label' => __( 'DesktopEnhancementApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'EntertainmentApplication',
			'label' => __( 'EntertainmentApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'MultimediaApplication',
			'label' => __( 'MultimediaApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'HomeApplication',
			'label' => __( 'HomeApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'UtilitiesApplication',
			'label' => __( 'UtilitiesApplication', 'wp-seopress-pro' ),
		),
		array(
			'value' => 'ReferenceApplication',
			'label' => __( 'ReferenceApplication', 'wp-seopress-pro' ),
		),
	);

	$seopress_pro_rich_snippets_softwareapp_name       = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_name'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_name'] : '';
	$seopress_pro_rich_snippets_softwareapp_os         = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_os'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_os'] : '';
	$seopress_pro_rich_snippets_softwareapp_cat        = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_cat'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_cat'] : '';
	$seopress_pro_rich_snippets_softwareapp_price      = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_price'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_price'] : '';
	$seopress_pro_rich_snippets_softwareapp_currency   = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_currency'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_currency'] : '';
	$seopress_pro_rich_snippets_softwareapp_rating     = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_rating'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_rating'] : '';
	$seopress_pro_rich_snippets_softwareapp_max_rating = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_max_rating'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_softwareapp_max_rating'] : ''; ?>
<div class="wrap-rich-snippets-item wrap-rich-snippets-software-app">
	<div class="seopress-notice">
		<p>
			<?php esc_html_e( 'Mark up software application information so that Google can provide detailed service information in rich Search results.', 'wp-seopress-pro' ); ?>
		</p>
	</div>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_name_meta">
			<?php esc_html_e( 'Software name', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_softwareapp_name_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_softwareapp_name]"
			placeholder="<?php esc_html_e( 'The name of your app', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'App name', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_softwareapp_name ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_os_meta">
			<?php esc_html_e( 'Operating system', 'wp-seopress-pro' ); ?>'</label>
		<input type="text" id="seopress_pro_rich_snippets_softwareapp_os_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_softwareapp_os]"
			placeholder="<?php esc_html_e( 'The operating system(s) required to use the app', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Operating system', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_softwareapp_os ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_cat_meta">
			<?php esc_html_e( 'Application category', 'wp-seopress-pro' ); ?>
		</label>
		<select id="seopress_pro_rich_snippets_softwareapp_cat_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_softwareapp_cat]">
			<?php foreach ( $options_software as $item ) { ?>
			<option <?php selected( $item['value'], $seopress_pro_rich_snippets_softwareapp_cat ); ?>value="<?php echo esc_attr( $item['value'] ); ?>">
				<?php echo esc_attr( $item['label'] ); ?>
			</option>
			<?php } ?>

		</select>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_price_meta">
			<?php esc_html_e( 'Price of your app', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_softwareapp_price_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_softwareapp_price]"
			placeholder="<?php esc_html_e( 'The price of your app (set "0" if the app is free of charge)', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Price', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_softwareapp_price ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_currency_meta">
			<?php esc_html_e( 'Currency', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_softwareapp_currency_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_softwareapp_currency]"
			placeholder="<?php esc_html_e( 'Currency: USD, EUR...', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Currency', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_softwareapp_currency ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_rating_meta">
			<?php esc_html_e( 'Your rating', 'wp-seopress-pro' ); ?>
		</label>
		<input type="number" id="seopress_pro_rich_snippets_softwareapp_rating_meta" min="1" step="0.1"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_softwareapp_rating]"
			placeholder="<?php esc_html_e( 'The item rating', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Your rating', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_softwareapp_rating ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_softwareapp_max_rating_meta">
			<?php esc_html_e( 'Max best rating', 'wp-seopress-pro' ); ?>
		</label>
		<input type="number" id="seopress_pro_rich_snippets_softwareapp_max_rating_meta" min="1" step="0.1"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_softwareapp_max_rating]"
			placeholder="<?php esc_html_e( 'Max best rating', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Max best rating', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_softwareapp_max_rating ); ?>" />
	</p>
</div>
	<?php
}
