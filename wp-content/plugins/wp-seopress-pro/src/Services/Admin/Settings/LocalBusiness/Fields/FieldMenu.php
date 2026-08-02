<?php

namespace SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

trait FieldMenu {
	/**
	 *
	 * @return void
	 */
	public function renderFieldMenu() {
		$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessMenu(); ?>
<input type="text" name="seopress_pro_option_name[seopress_local_business_menu]"
	placeholder="
		<?php
		printf(
		/* translators: 1: home URL */
			esc_html__( 'e.g. %s', 'wp-seopress-pro' ),
			get_home_url()
		);
		?>
	"
	aria-label="<?php esc_attr_e( 'The URL of the menu.', 'wp-seopress-pro' ); ?>"
	value="<?php echo esc_attr( $value ); ?>" />

<p class="description"><?php echo wp_kses_post( __( '<span class="field-recommended">Recommended</span> property by Google.', 'wp-seopress-pro' ) ); ?>
</p>

		<?php
	}
}
