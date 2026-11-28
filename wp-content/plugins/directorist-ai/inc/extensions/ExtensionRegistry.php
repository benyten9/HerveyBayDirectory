<?php
/** Shared site-scoped extension inventory and installed compatibility handlers. */
namespace DirectoristAIPro\Extensions;

final class ExtensionRegistry {
	private const REVISION = 'extension-handlers-1';

	/** Trusted code owns handlers; models and requests cannot register callbacks. */
	public static function handlers(): array {
		$definitions = array(
			'booking' => array( 'directorist-booking/directorist-booking.php', array( 'booking.request', 'booking.cancel' ) ),
			'claim' => array( 'directorist-claim-listing/directorist-claim-listing.php', array( 'claim_link' ) ),
			'owner_chat' => array( 'directorist-live-chat/directorist-live-chat.php', array( 'owner_chat_link' ) ),
			'business_hours' => array( 'directorist-business-hours/bd-business-hour.php', array( 'hours', 'open_now' ) ),
			'advanced_reviews' => array( 'directorist-advanced-review/directorist-advanced-review.php', array( 'reviews', 'review.submit' ) ),
			'comparison' => array( 'directorist-compare-listing/directorist-compare-listing.php', array( 'native_compare_link' ) ),
			'faqs' => array( 'directorist-faqs/directorist-faqs.php', array( 'faqs' ) ),
			'gallery' => array( 'directorist-gallery/bd-directorist-gallery.php', array( 'gallery' ) ),
			'jobs' => array( 'directorist-job-manager/directorist-job-manager.php', array( 'job_fields' ) ),
			'coupons' => array( 'directorist-coupon/directorist-coupon.php', array( 'native_coupon_display' ) ),
			'directory_linking' => array( 'directorist-directory-linking/directorist-directory-linking.php', array( 'native_related_listing_links' ) ),
			'map_listings' => array( 'directorist-listings-with-map/directorist-listings-map.php', array( 'native_listing_map' ) ),
			'slider_carousel' => array( 'directorist-slider-carousel/bd-directorist-slider.php', array( 'native_listing_carousel' ) ),
			'wpml' => array( 'directorist-wpml-integration/directorist-wpml-integration.php', array( 'public_content_language' ) ),
			'recaptcha' => array( 'directorist-google-recaptcha/directorist-google-recaptcha.php', array( 'native_form_challenge' ) ),
			'woo_pricing' => array( 'directorist-woocommerce-pricing-plans/directorist-woocommerce-pricing-plans.php', array( 'native_listing_plan_eligibility' ) ),
		);
		$handlers = array();
		foreach ( $definitions as $id => $definition ) {
			$handlers[ $id ] = new NativeExtensionHandler( $id, $definition[0], $definition[1], 'woo_pricing' === $id ? 'eligibility_adapter_pending' : 'native_checks_required' );
		}
		$handlers['addonskit'] = new AddonsKitHandler();
		return $handlers;
	}

	public static function hooks(): void {
		foreach ( self::handlers() as $handler ) {
			if ( self::active_plugin( $handler->plugin() ) ) { $handler->hooks(); }
		}
	}

	/** Fresh activation state; cached discovery never authorizes native effects. */
	public static function active_plugin( string $plugin ): bool {
		return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true ) || ( is_multisite() && isset( get_site_option( 'active_sitewide_plugins', array() )[ $plugin ] ) );
	}

	/** Versioned object-cache discovery, independent of users and listing eligibility. */
	public static function profile(): array {
		$handlers = self::handlers();
		$signature = array( self::REVISION, defined( 'DIRECTORIST_AI_PRO_VERSION' ) ? DIRECTORIST_AI_PRO_VERSION : null, filemtime( __FILE__ ), filesize( __FILE__ ), get_current_blog_id(), get_option( 'active_plugins', array() ), is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : array() );
		foreach ( $handlers as $id => $handler ) {
			$file = WP_PLUGIN_DIR . '/' . $handler->plugin();
			clearstatcache( true, $file );
			$signature[ $id ] = is_readable( $file ) ? array( filemtime( $file ), filesize( $file ) ) : null;
		}
		$key = hash( 'sha256', serialize( $signature ) );
		$cached = wp_cache_get( $key, 'directorist_ai_extensions' );
		if ( is_array( $cached ) ) { return $cached; }
		$result = array();
		foreach ( $handlers as $id => $handler ) {
			$file = WP_PLUGIN_DIR . '/' . $handler->plugin();
			$installed = is_readable( $file );
			$metadata = $installed ? get_file_data( $file, array( 'version' => 'Version' ) ) : array();
			$result[ $id ] = array(
				'installed' => $installed,
				'version' => $metadata['version'] ?? null,
				'active' => self::active_plugin( $handler->plugin() ),
				'integration_state' => $handler->support(),
				'features' => $handler->features(),
				'availability' => 'Checked for each directory, listing and actor. Installation or activation does not grant a workflow, facts or permission.',
			);
		}
		wp_cache_set( $key, $result, 'directorist_ai_extensions', 1800 );
		return $result;
	}

	/** Existing native adapters retain all their eligibility, validation and write checks. */
	public static function workflow_adapters(): array {
		$result = array();
		foreach ( array( 'booking.request' => \DirectoristAIPro\Chat\Workflows\BookingAdapter::class, 'booking.cancel' => \DirectoristAIPro\Chat\Workflows\BookingCancellationAdapter::class, 'review.submit' => \DirectoristAIPro\Chat\Workflows\ReviewAdapter::class ) as $id => $class ) {
			if ( class_exists( $class ) ) { $result[ $id ] = new $class(); }
		}
		return $result;
	}
}
