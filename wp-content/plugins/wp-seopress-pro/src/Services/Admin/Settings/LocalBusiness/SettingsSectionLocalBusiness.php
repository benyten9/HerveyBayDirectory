<?php

namespace SEOPressPro\Services\Admin\Settings\LocalBusiness;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPressPro\Helpers\Settings\LocalBusinessHelper;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldAddressCountry;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldAddressLocality;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldAddressRegion;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldCuisine;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldLatitude;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldLongitude;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldOpeningHours;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldOpeningHoursDisplayFormat;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldOpeningHoursSeparator;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldPage;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldPhone;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldPlaceId;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldPostalCode;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldPriceRange;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldStreetAddress;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldType;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldUrl;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldMenu;
use SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields\FieldAcceptsReservations;

class SettingsSectionLocalBusiness {
	use FieldPage;
	use FieldType;
	use FieldStreetAddress;
	use FieldLatitude;
	use FieldLongitude;
	use FieldAddressCountry;
	use FieldAddressLocality;
	use FieldAddressRegion;
	use FieldPostalCode;
	use FieldUrl;
	use FieldPlaceId;
	use FieldPhone;
	use FieldPriceRange;
	use FieldCuisine;
	use FieldOpeningHours;
	use FieldOpeningHoursDisplayFormat;
	use FieldOpeningHoursSeparator;
	use FieldMenu;
	use FieldAcceptsReservations;

	public function __call( $name, $params ) {
		do_action( 'seopress_pro_render_field_local_business', $name );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function renderSettings() {
		$settings = LocalBusinessHelper::getSettingsSection( $this );

		if ( ! isset( $settings['section'] ) ||
			! isset( $settings['section']['id'], $settings['section']['title'], $settings['section']['callback'], $settings['section']['page'] ) ) {
			return;
		}

		add_settings_section(
			$settings['section']['id'],
			$settings['section']['title'],
			$settings['section']['callback'],
			$settings['section']['page']
		);

		if ( ! isset( $settings['fields'] ) || empty( $settings['fields'] ) ) {
			return;
		}

		foreach ( $settings['fields'] as $key => $field ) {
			if ( ! isset( $field['id'], $field['title'], $field['callback'], $field['page'], $field['section'] ) ) {
				continue;
			}

			add_settings_field(
				$field['id'],
				$field['title'],
				$field['callback'],
				$field['page'],
				$field['section'],
				$field['args'] ?? array()
			);
		}
	}

	/**
	 * Render the optional details in the classic settings form.
	 *
	 * @param array $args Registered field and label.
	 * @return void
	 */
	public function render_additional_field( $args ) {
		if ( ! current_user_can( seopress_capability( 'manage_options', 'pro' ) ) ) {
			return;
		}
		$field = $args['field'] ?? '';
		if ( ! in_array( $field, array( 'email', 'logo', 'image', 'area_served', 'same_as' ), true ) ) {
			return;
		}
		$key     = 'seopress_local_business_' . $field;
		$options = get_option( 'seopress_pro_option_name', array() );
		$value   = isset( $options[ $key ] ) && is_string( $options[ $key ] ) ? $options[ $key ] : '';
		$name    = 'seopress_pro_option_name[' . $key . ']';
		if ( in_array( $field, array( 'area_served', 'same_as' ), true ) ) {
			printf( '<textarea name="%s" aria-label="%s" rows="4">%s</textarea>', esc_attr( $name ), esc_attr( $args['label'] ), esc_textarea( $value ) );
		} else {
			printf( '<input type="%s" name="%s" aria-label="%s" value="%s" />', 'email' === $field ? 'email' : 'url', esc_attr( $name ), esc_attr( $args['label'] ), esc_attr( $value ) );
		}
	}

	/**
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function renderSection() {
		seopress_pro_get_service( 'RenderSectionPro' )->render( 'local-business' );
		$options   = get_option( 'seopress_pro_option_name', array() );
		$imgOption = ! empty( $options['seopress_local_business_image'] ) ? $options['seopress_local_business_image'] : seopress_get_service( 'SocialOption' )->getSocialKnowledgeImage();
		$docs      = function_exists( 'seopress_get_docs_links' ) ? seopress_get_docs_links() : array();

		if ( empty( $imgOption ) ) {
			?>
			<div class="seopress-notice is-error">
				<p>
					<?php esc_html_e( 'Add a business image here or set an image in Knowledge Graph settings.', 'wp-seopress-pro' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=seopress-social' ) ); ?>" class="btn btnPrimary">
						<?php esc_html_e( 'Knowledge Graph settings', 'wp-seopress-pro' ); ?>
					</a>
				</p>
			</div>
			<?php
		}

		$content  = '<p>' . esc_html__( 'Local Business schema helps search engines understand your business information, improving visibility in local search results and Google Maps.', 'wp-seopress-pro' ) . '</p>';
		$content .= '<p><strong>' . esc_html__( 'Display options:', 'wp-seopress-pro' ) . '</strong></p>';
		$content .= '<ul class="list-none">';
		$content .= '<li><span class="dashicons dashicons-admin-appearance"></span><span>' . sprintf(
			/* translators: %s widgets admin URL */
			wp_kses_post( __( '<a href="%s">Local Business widget</a> - Add to any widget area', 'wp-seopress-pro' ) ),
			esc_url( admin_url( 'widgets.php' ) )
		) . '</span></li>';
		$content .= '<li><span class="dashicons dashicons-block-default"></span><span>' . esc_html__( 'Local Business block - Use in the block editor', 'wp-seopress-pro' ) . '</span></li>';
		$content .= '<li><span class="dashicons dashicons-admin-home"></span><span>' . esc_html__( 'Homepage schema - Automatically added based on settings below', 'wp-seopress-pro' ) . '</span></li>';
		$content .= '</ul>';
		$content .= '<div class="seopress-notice is-warning"><p>' . wp_kses_post( sprintf(
			/* translators: %s schema metabox URL */
			__( '<strong>Need multiple locations?</strong> Create a manual Local Business schema from the schema metabox, or an <a href="%s">automatic Local Business schema</a>.', 'wp-seopress-pro' ),
			esc_url( admin_url( 'admin.php?page=seopress-schemas' ) )
		) ) . '</p></div>';

		if ( ! empty( $docs['lb']['eat'] ) && function_exists( 'seopress_external_link' ) ) {
			$content .= '<p>' . seopress_external_link(
				array(
					'url'  => $docs['lb']['eat'],
					'text' => __( 'Learn how Local Business schema improves Google E-E-A-T', 'wp-seopress-pro' ),
				)
			) . '</p>';
		}

		if ( function_exists( 'seopress_accordion' ) ) {
			seopress_accordion(
				array(
					'title'   => __( 'About Local Business schema', 'wp-seopress-pro' ),
					'icon'    => 'dashicons-info-outline',
					'content' => $content,
				)
			);
		}
		?>

		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=seopress-social#tab=tab_seopress_social_knowledge' ) ); ?>">
				<?php esc_html_e( 'To edit your business name, visit this page.', 'wp-seopress-pro' ); ?>
			</a>
		</p>

		<?php
	}
}
