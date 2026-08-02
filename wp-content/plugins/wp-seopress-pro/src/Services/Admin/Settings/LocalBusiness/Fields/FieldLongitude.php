<?php

namespace SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

trait FieldLongitude {
	/**
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function renderFieldLongitude() {
		$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessLongitude(); ?>
		<input
			type="text"
			name="seopress_pro_option_name[seopress_local_business_lon]"
			placeholder="<?php esc_attr_e( 'e.g. -1.5630987', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_attr_e( 'Longitude', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $value ); ?>" />

		<p class="description"><?php echo wp_kses_post( __( '<span class="field-recommended">Recommended</span> property by Google.', 'wp-seopress-pro' ) ); ?></p>
		<?php
	}
}
