<?php
/**
 * SEOPress PRO Content Analysis Metaboxes Form.
 *
 * @package SEOPress PRO
 * @subpackage Metaboxes
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Add Tabs to Content Analysis.
 */
function seopress_pro_ca_tab_before() {
	?>
	<ul class="wrap-ca-list">
		<li><a href="#seopress-ca-tabs-2"><?php esc_html_e( 'Overview', 'wp-seopress-pro' ); ?></a></li>
		<?php
		if ( version_compare( SEOPRESS_PRO_VERSION, '5.7', '>=' ) ) {
			?>
				<?php if ( seopress_get_toggle_option( 'inspect-url' ) === '1' ) { ?>
					<li><a href="#seopress-ca-tabs-1"><?php esc_html_e( 'Inspect with Google', 'wp-seopress-pro' ); ?></a></li>
				<?php } ?>

			<?php
		}
		?>
		<li><a href="#seopress-ca-tabs-3"><?php esc_html_e( 'Internal Linking', 'wp-seopress-pro' ); ?></a></li>
	</ul>
	<?php
}
add_action( 'seopress_ca_tab_before', 'seopress_pro_ca_tab_before' );

/**
 * Add Google Suggestions to Content Analysis metabox.
 */
function seopress_pro_ca_before() {
	?>
	<div class="col-right">
		<p>
			<label for="seopress_google_suggest_kw_meta">
				<?php esc_html_e( 'Google suggestions', 'wp-seopress-pro' ); ?>
			</label>
			<span class="description"><?php esc_html_e( 'Enter a keyword, or a phrase, to find the top 10 Google suggestions instantly. This is useful if you want to work with the long tail technique.', 'wp-seopress-pro' ); ?></span>
			<input id="seopress_google_suggest_kw_meta" type="text" name="seopress_google_suggest_kw"
				placeholder="<?php esc_html_e( 'Get suggestions from Google', 'wp-seopress-pro' ); ?>"
				aria-label="Google suggestions" value="">
			<span class="description"><?php esc_html_e( 'Click on a suggestion below to add it as a target keyword.', 'wp-seopress-pro' ); ?></span>
		</p>
		<button id="seopress_get_suggestions" type="button"
			class="<?php echo seopress_btn_secondary_classes(); ?>">
			<?php esc_html_e( 'Get suggestions!', 'wp-seopress-pro' ); ?>
		</button>

		<ul id='seopress_suggestions'></ul>
		<?php
		if ( '' != get_locale() ) {
				$locale       = function_exists( 'locale_get_primary_language' ) ? locale_get_primary_language( get_locale() ) : get_locale();
				$country_code = function_exists( 'locale_get_region' ) ? locale_get_region( get_locale() ) : get_locale();
		} else {
			$locale       = 'en';
			$country_code = 'US';
		}
		?>
		<script>
			jQuery('#seopress_get_suggestions').on('click', function(data) {
				data.preventDefault();

				document.getElementById('seopress_suggestions').innerHTML = '';

				var kws = jQuery('#seopress_google_suggest_kw_meta').val();

				if (kws) {
					var script = document.createElement('script');
					script.src =
						'https://www.google.com/complete/search?client=firefox&format=rich&hl=<?php echo $locale; ?>&q=' +
						kws +
						'&gl=<?php echo $country_code; ?>&callback=seopress_google_suggest';
					document.body.appendChild(script);
				}
			});
		</script>
	</div>
	<?php
}
add_action( 'seopress_ca_before', 'seopress_pro_ca_before' );

/**
 * Add Inspect URL tab.
 *
 * @param int $current_id The current post ID.
 */
function seopress_pro_ca_tab_after( $current_id ) {
	if ( version_compare( SEOPRESS_PRO_VERSION, '5.7', '>=' ) ) {
		if ( seopress_get_toggle_option( 'inspect-url' ) === '1' ) {
			?>
		<div id="seopress-ca-tabs-1">
			<?php
			if ( function_exists( 'seopress_pro_get_service' ) && ! empty( $current_id ) ) {
				seopress_pro_get_service( 'RenderGSCInspectUrl' )->render( $current_id );
			}
			?>
		</div>
			<?php
		}
	}

	?>
	<div id="seopress-ca-tabs-3">
		<?php
		if ( function_exists( 'seopress_pro_get_service' ) && ! empty( $current_id ) ) {
			seopress_pro_get_service( 'RenderMetaboxInternalLinking' )->render( $current_id );
		}
		?>
	</div>
	<?php
}
add_action( 'seopress_ca_tab_after', 'seopress_pro_ca_tab_after', 10, 1 );
