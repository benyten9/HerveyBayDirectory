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
		$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessPlaceId();

		// Kept out of the translated string: a URL is not something a translator
		// should be asked to carry, and when the destination moves, every locale
		// keeps pointing at the old one until each translation is updated.
		//
		// It lives in the free plugin's DocsLinks.php under `lb`, alongside the
		// other Local Business links, which is where every external link belongs
		// so there is one place to update when a destination moves. That entry
		// also carries a French variant, so reading it is what gets the reader
		// the ?hl=fr address on a French admin. The fallback covers a PRO running
		// against a free that predates the corrected URL, since PRO ships and
		// updates independently.
		// Google retired /places/web-service/place-id; that address only still
		// resolves through a redirect.
		$place_id_doc_url = 'https://developers.google.com/maps/documentation/places/web-service/place-id';

		if ( function_exists( 'seopress_get_docs_links' ) ) {
			$docs_links = seopress_get_docs_links();

			if ( ! empty( $docs_links['lb']['place_id'] ) ) {
				$place_id_doc_url = $docs_links['lb']['place_id'];
			}
		}
		?>
<input type="text" name="seopress_pro_option_name[seopress_local_business_place_id]"
	placeholder="<?php esc_attr_e( 'e.g. ChIJ1zmBfihrUQ0RE02R1pnXoc8', 'wp-seopress-pro' ); ?>"
	aria-label="<?php esc_attr_e( 'Google Maps Place ID', 'wp-seopress-pro' ); ?>"
	value="<?php echo esc_attr( $value ); ?>" />
<p class="description">
		<?php
		printf(
			/* translators: 1: opening link tag to the Google Place ID documentation, 2: closing link tag followed by an external-link icon. */
			wp_kses_post( __( '%1$sClick here to find your Google Maps Place ID%2$s for your Local Business.', 'wp-seopress-pro' ) ),
			'<a href="' . esc_url( $place_id_doc_url ) . '" target="_blank" rel="noopener noreferrer">',
			'</a><span class="seopress-help dashicons dashicons-external"></span>'
		);
		?>
	<br>
		<?php esc_html_e( 'This ID will be used to display the Google Maps link from the LB widget.', 'wp-seopress-pro' ); ?>
</p>
		<?php
	}
}
