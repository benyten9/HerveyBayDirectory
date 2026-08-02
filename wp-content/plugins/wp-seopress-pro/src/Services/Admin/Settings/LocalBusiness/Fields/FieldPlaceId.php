<?php

namespace SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

trait FieldPlaceId {

	/**
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function renderFieldPlaceId() {
		$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessPlaceId(); ?>
<input type="text" name="seopress_pro_option_name[seopress_local_business_place_id]"
	placeholder="<?php esc_attr_e( 'e.g. ChIJ1zmBfihrUQ0RE02R1pnXoc8', 'wp-seopress-pro' ); ?>"
	aria-label="<?php esc_attr_e( 'Google Maps Place ID', 'wp-seopress-pro' ); ?>"
	value="<?php echo esc_attr( $value ); ?>" />
<p class="description">
		<?php echo wp_kses_post( __( '<a href="https://developers.google.com/places/web-service/place-id" target="_blank">Click here to find your Google Maps Place ID</a><span class="seopress-help dashicons dashicons-external"></span> for your Local Business. <br>This ID will be used to display the Google Maps link from the LB widget.', 'wp-seopress-pro' ) ); ?>
</p>
		<?php
	}
}
