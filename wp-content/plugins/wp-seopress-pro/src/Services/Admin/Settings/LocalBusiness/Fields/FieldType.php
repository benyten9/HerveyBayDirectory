<?php

namespace SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

trait FieldType {
	/**
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function renderFieldType() {
		$selected = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessType(); ?>

		<select id="seopress_local_business_type" name="seopress_pro_option_name[seopress_local_business_type]">
			<?php foreach ( seopress_lb_types_list() as $type_value => $type_i18n ) { ?>
				<option <?php selected( $type_value, $selected ); ?> value="<?php echo esc_attr( $type_value ); ?>">
					<?php echo esc_html( $type_i18n ); ?>
				</option>
			<?php } ?>
		</select>
		<?php
	}
}
