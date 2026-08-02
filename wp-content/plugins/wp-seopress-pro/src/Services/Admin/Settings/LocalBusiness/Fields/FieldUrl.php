<?php

namespace SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

trait FieldUrl {

	/**
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function renderFieldUrl() {
		$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessUrl(); ?>
<input type="text" name="seopress_pro_option_name[seopress_local_business_url]"
	placeholder="
		<?php
		printf(
		/* translators: %s: home url */
			esc_attr__( 'default: %s', 'wp-seopress-pro' ),
			esc_url( get_home_url() )
		);
		?>
	"
	aria-label="<?php esc_html_e( 'URL', 'wp-seopress-pro' ); ?>"
	value="<?php echo esc_attr( $value ); ?>" />
<p class="description">
		<?php esc_html_e( 'Default: homepage.', 'wp-seopress-pro' ); ?>
</p>

<p class="description"><?php echo wp_kses_post( __( '<span class="field-recommended">Recommended</span> property by Google.', 'wp-seopress-pro' ) ); ?>
</p>

		<?php
	}
}
