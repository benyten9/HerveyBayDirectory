<?php

namespace SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

trait FieldStreetAddress {
	/**
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function renderFieldStreetAddress() {
		$check = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessStreetAddress(); ?>
		<input
			type="text"
			name="seopress_pro_option_name[seopress_local_business_street_address]"
			placeholder="<?php esc_attr_e( 'e.g. Place Bellevue', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_attr_e( 'Street Address', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $check ); ?>" />

			<p class="description"><?php echo wp_kses_post( __( '<span class="field-required">Required</span> property by Google.', 'wp-seopress-pro' ) ); ?></p>
		<?php
	}
}
