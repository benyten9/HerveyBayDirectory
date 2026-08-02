<?php

namespace SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

trait FieldOpeningHoursSeparator {
	/**
	 * @since 9.2.0
	 *
	 * @return void
	 */
	public function renderFieldOpeningHoursSeparator() {
		$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessOpeningHoursSeparator(); ?>
		
		<select
			id="seopress_pro_option_name[seopress_local_business_opening_hours_separator]"
			name="seopress_pro_option_name[seopress_local_business_opening_hours_separator]"
			class="seopress-admin-select">
			
			<option value=":" <?php selected( $value, ':', true ); ?>>
				<?php esc_html_e( 'Colon (:)', 'wp-seopress-pro' ); ?>
			</option>
			
			<option value="." <?php selected( $value, '.' ); ?>>
				<?php esc_html_e( 'Dot (.)', 'wp-seopress-pro' ); ?>
			</option>
			
			<option value=" " <?php selected( $value, ' ' ); ?>>
				<?php esc_html_e( 'Space ( )', 'wp-seopress-pro' ); ?>
			</option>
		</select>
		
		<p class="description">
			<?php esc_html_e( 'Choose the separator between hours and minutes in time display.', 'wp-seopress-pro' ); ?>
		</p>

		<?php
	}
}
