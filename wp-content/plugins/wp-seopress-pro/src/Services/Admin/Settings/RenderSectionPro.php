<?php

namespace SEOPressPro\Services\Admin\Settings;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

class RenderSectionPro {

	/**
	 * @since 4.5.0
	 *
	 * @param string $key
	 */
	public function render( $key ) {
		$docs = function_exists( 'seopress_get_docs_links' ) ? seopress_get_docs_links() : '';

		$breadcrumbs_desc = __( 'Configure your breadcrumbs, using schema.org markup, allowing it to appear in Google\'s search results.', 'wp-seopress-pro' ) . ' <a class="seopress-help" href="https://developers.google.com/search/docs/data-types/breadcrumb" target="_blank" title="' . __( 'Google developers website (new window)', 'wp-seopress-pro' ) . '">' . __( 'Lean more on Google developers website', 'wp-seopress-pro' ) . '</a><span class="seopress-help dashicons dashicons-external"></span>';

		$sections = array(
			'local-business' => array(
				'toggle' => 1,
				'title'  => __( 'Local Business', 'wp-seopress-pro' ),
				'desc'   => sprintf(
					/* translators: 1: Local Business widget URL, 2: Google EAT URL */
					__( 'Local Business data type for Google. This schema will be displayed on the homepage. <br>You can also display these informations using our <a href="%1$s">Local Business widget</a> or Local Business block to optimize your site for <a class="seopress-help" href="%2$s" target="_blank" title="Optimizing WordPress sites for Google EAT (new window)">Google EAT</a><span class="seopress-help dashicons dashicons-external"></span>.', 'wp-seopress-pro' ),
					admin_url( 'widgets.php' ),
					$docs['lb']['eat']
				),
			),
			'edd'            => array(
				'toggle' => 1,
				'title'  => __( 'Easy Digital Downloads', 'wp-seopress-pro' ),
				'desc'   => __( 'Improve Easy Digital Downloads SEO.', 'wp-seopress-pro' ),
			),
			'woocommerce'    => array(
				'toggle' => 1,
				'title'  => __( 'WooCommerce', 'wp-seopress-pro' ),
				'desc'   => __( 'Improve WooCommerce SEO. By enabling this feature, we\'ll automatically add <strong>product identifiers type</strong> and <strong>product identifiers value</strong> fields to the WooCommerce product metabox (barcode) for the Product schema.', 'wp-seopress-pro' ),
			),
			'alerts'         => array(
				'toggle' => 1,
				'title'  => __( 'SEO Alerts', 'wp-seopress-pro' ),
				'desc'   => __( 'Receive alerts by email/Slack about your SEO before it\'s too late.', 'wp-seopress-pro' ),
			),
			'dublin-core'    => array(
				'toggle' => 1,
				'title'  => __( 'Dublin Core', 'wp-seopress-pro' ),
				'desc'   => __( 'Dublin Core is a set of meta tags to describe your content.<br> These tags are automatically generated. Recognized by states / governements, they are used by directories, Bing, Baidu and Yandex.', 'wp-seopress-pro' ),
			),
			'rich-snippets'  => array(
				'toggle' => 1,
				'title'  => __( 'Structured Data Types (schema.org)', 'wp-seopress-pro' ),
				'desc'   => __( 'Add Structured Data Types support, mark your content, and get better Google Search Results.', 'wp-seopress-pro' ),
			),
			'page-speed'     => array(
				'title' => __( 'PageSpeed Insights', 'wp-seopress-pro' ),
				'desc'  => __( 'Check your site performance with Google PageSpeed Insights.', 'wp-seopress-pro' ),
			),
			'inspect-url'    => array(
				'toggle' => 1,
				'title'  => __( 'Google Search Console', 'wp-seopress-pro' ),
				'desc'   => __( 'Connect your Google Search Console account to get SEO insights directly in WordPress.', 'wp-seopress-pro' ),
			),
			'robots'         => array(
				'toggle' => 1,
				'title'  => __( 'robots.txt', 'wp-seopress-pro' ),
				'desc'   => __( 'Configure your virtual robots.txt file.', 'wp-seopress-pro' ),
			),
			'news'           => array(
				'toggle' => 1,
				'title'  => __( 'Google News', 'wp-seopress-pro' ),
				'desc'   => __( 'Enable your Google News Sitemap.', 'wp-seopress-pro' ),
			),
			'404'            => array(
				'toggle' => 1,
				'title'  => __( '404 monitoring / Redirections', 'wp-seopress-pro' ),
				'desc'   => __( 'Monitor 404 urls in your Dashboard. Crawlers (robots/spiders) will be automatically exclude (e.g. Google Bot, Yahoo, Bing...).', 'wp-seopress-pro' ),
			),
			'htaccess'       => array(
				'title' => __( '.htaccess', 'wp-seopress-pro' ),
				'desc'  => __( 'View and edit your .htaccess file directly from WordPress to add custom redirects, security rules, or performance optimizations.', 'wp-seopress-pro' ),
			),
			'rss'            => array(
				'title' => __( 'RSS feeds', 'wp-seopress-pro' ),
				'desc'  => sprintf(
					/* translators: 1: RSS feed URL */
					__( 'Configure WordPress default feeds. <br><br><a href="%1$s" class="btn btnSecondary" target="_blank">View my RSS feed</a>', 'wp-seopress-pro' ),
					get_home_url() . '/feed'
				),
			),
			'white-label'    => array(
				'toggle' => 1,
				'title'  => __( 'White Label', 'wp-seopress-pro' ),
				'desc'   => __( 'Enable White Label.', 'wp-seopress-pro' ),
			),
			'breadcrumbs'    => array(
				'toggle' => 1,
				'title'  => __( 'Breadcrumbs', 'wp-seopress-pro' ),
				'desc'   => $breadcrumbs_desc,
			),
			'ai'             => array(
				'toggle' => 1,
				'title'  => __( 'AI', 'wp-seopress-pro' ),
				'desc'   => __( 'Use the power of AI to improve your productivity.', 'wp-seopress-pro' ),
			),
		);

		if ( ! empty( $sections ) ) {
			if ( '1' == seopress_get_toggle_option( $key ) ) {
				$seopress_get_toggle_option = '1';
			} else {
				$seopress_get_toggle_option = '0';
			} ?>
<div class="sp-section-header">
	<h2>
			<?php echo esc_html( $sections[ $key ]['title'] ); ?>
	</h2>

			<?php if ( ! empty( $sections[ $key ]['toggle'] ) && 1 == $sections[ $key ]['toggle'] ) { ?>
	<div class="wrap-toggle-checkboxes">
		<input type="checkbox" name="toggle-<?php echo esc_attr( $key ); ?>"
			id="toggle-<?php echo esc_attr( $key ); ?>" class="toggle"
			data-toggle="<?php echo esc_attr( $seopress_get_toggle_option ); ?>">
		<label for="toggle-<?php echo esc_attr( $key ); ?>"></label>

				<?php
				if ( '1' == $seopress_get_toggle_option ) {
					?>
		<span id="<?php echo esc_attr( $key ); ?>-state-default"
			class="feature-state">
			<span class="dashicons dashicons-arrow-left-alt"></span>
					<?php esc_html_e( 'Click to disable this feature', 'wp-seopress-pro' ); ?>
		</span>
		<span id="<?php echo esc_attr( $key ); ?>-state"
			class="feature-state feature-state-off">
			<span class="dashicons dashicons-arrow-left-alt"></span>
					<?php esc_html_e( 'Click to enable this feature', 'wp-seopress-pro' ); ?>
		</span>
		<?php } else { ?>
		<span id="<?php echo esc_attr( $key ); ?>-state-default"
			class="feature-state">
			<span class="dashicons dashicons-arrow-left-alt"></span>
					<?php esc_html_e( 'Click to enable this feature', 'wp-seopress-pro' ); ?>
		</span>
		<span id="<?php echo esc_attr( $key ); ?>-state"
			class="feature-state feature-state-off">
			<span class="dashicons dashicons-arrow-left-alt"></span>
					<?php esc_html_e( 'Click to disable this feature', 'wp-seopress-pro' ); ?>
		</span>
		<?php } ?>
	</div>
	<?php } ?>
</div>
<p>
			<?php echo wp_kses_post( $sections[ $key ]['desc'] ); ?>
</p>
<p>
			<?php
			if ( isset( $sections[ $key ]['alert'] ) ) {
				echo '<div class="seopress-notice"><p>' . wp_kses_post( $sections[ $key ]['alert'] ) . '</p></div>';
			}
			?>
</p>
			<?php
		}
	}
}
