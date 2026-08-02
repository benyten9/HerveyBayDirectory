<?php

namespace SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

trait FieldOpeningHoursDisplayFormat {
	/**
	 * @since 9.2.0
	 *
	 * @return void
	 */
	public function renderFieldOpeningHoursDisplayFormat() {
		$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessOpeningHoursDisplayFormat(); ?>
		
		<select
			id="seopress_pro_option_name[seopress_local_business_opening_hours_display_format]"
			name="seopress_pro_option_name[seopress_local_business_opening_hours_display_format]"
			class="seopress-admin-select">
			
			<option value="24h" <?php selected( $value, '24h' ); ?>>
				<?php esc_html_e( '24-hour format (e.g., 14:30)', 'wp-seopress-pro' ); ?>
			</option>
			
			<option value="12h" <?php selected( $value, '12h' ); ?>>
				<?php esc_html_e( '12-hour format (e.g., 2:30 PM)', 'wp-seopress-pro' ); ?>
			</option>
		</select>
		
		<p class="description">
			<?php esc_html_e( 'Choose how opening hours are displayed in the frontend widget.', 'wp-seopress-pro' ); ?>
		</p>

		<?php
	}
}
