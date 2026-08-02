<?php

namespace SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

trait FieldAddressCountry {
	/**
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function renderFieldAddressCountry() {
		$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessAddressCountry(); ?>
		<input
			type="text"
			name="seopress_pro_option_name[seopress_local_business_address_country]"
			placeholder="<?php esc_attr_e( 'e.g. FR for France', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_attr_e( 'Country', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $value ); ?>" />

		<p class="description"><?php echo wp_kses_post( __( '<span class="field-required">Required</span> property by Google.', 'wp-seopress-pro' ) ); ?>
		</p>
		<?php
	}
}
