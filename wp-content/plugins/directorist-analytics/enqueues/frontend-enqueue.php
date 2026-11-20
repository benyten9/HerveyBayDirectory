<?php

defined( 'ABSPATH' ) || exit;

/**
 * Track UTM parameters and referrer
 *
 * @since 1.0.0
 */
add_action( 'wp_footer', 'directorist_analytics_utm_tracker', 50 );

function directorist_analytics_utm_tracker() {
	// Only on single listing pages
	if ( ! is_singular( 'at_biz_dir' ) ) {
		return;
	}
	?>
	<script>
		(function() {
			// Capture UTM parameters from URL
			var urlParams = new URLSearchParams(window.location.search);
			var utmSource = urlParams.get('utm_source');
			var utmMedium = urlParams.get('utm_medium');
			var utmCampaign = urlParams.get('utm_campaign');
			var gclid = urlParams.get('gclid');
			var fbclid = urlParams.get('fbclid');
			var msclkid = urlParams.get('msclkid');
			
			// Build attribution cookie if we have any tracking params
			if (utmSource || gclid || fbclid || msclkid) {
				var attribution = [];
				if (utmSource) attribution.push('utm_source=' + encodeURIComponent(utmSource));
				if (utmMedium) attribution.push('utm_medium=' + encodeURIComponent(utmMedium));
				if (utmCampaign) attribution.push('utm_campaign=' + encodeURIComponent(utmCampaign));
				if (gclid) attribution.push('gclid=' + encodeURIComponent(gclid));
				if (fbclid) attribution.push('fbclid=' + encodeURIComponent(fbclid));
				if (msclkid) attribution.push('msclkid=' + encodeURIComponent(msclkid));
				
				// Set cookie (accessible on next page load)
				document.cookie = 'directorist_attribution=' + attribution.join('&') + 
					'; path=/; max-age=' + (30 * 24 * 60 * 60) + '; SameSite=Lax' +
					(location.protocol === 'https:' ? '; Secure' : '');
			} else if (!getCookie('directorist_attribution')) {
				// No UTM/Click IDs - store document.referrer as fallback
				// (more reliable than HTTP_REFERER for organic traffic)
				var docReferrer = document.referrer;
				if (docReferrer) {
					try {
						var referrerHost = new URL(docReferrer).hostname;
						document.cookie = 'directorist_js_referrer=' + encodeURIComponent(referrerHost) + 
							'; path=/; max-age=' + (30 * 24 * 60 * 60) + '; SameSite=Lax' +
							(location.protocol === 'https:' ? '; Secure' : '');
					} catch(e) {}
				}
			}
			
			// Helper to read cookies
			function getCookie(name) {
				var value = "; " + document.cookie;
				var parts = value.split("; " + name + "=");
				if (parts.length == 2) return parts.pop().split(";").shift();
			}
		})();
	</script>
	<?php
}

/**
 * Add screen resolution tracking script to single listings
 *
 * @since 1.0.0
 */
add_action( 'wp_footer', 'directorist_analytics_resolution_tracker', 100 );

function directorist_analytics_resolution_tracker() {
	// Only on single listing pages
	if ( ! is_singular( 'at_biz_dir' ) ) {
		return;
	}
	?>
	<script>
	(function() {
		// Track resolution after page fully loads
		window.addEventListener('load', function() {
			setTimeout(function() {
				try {
					var resolution = window.screen.width + 'x' + window.screen.height;
					var apiUrl = '<?php echo esc_url( rest_url( 'directorist-analytics/track/resolution' ) ); ?>';
					
					// Use sendBeacon for reliability (works even if user navigates away)
					if (navigator.sendBeacon) {
						var data = new Blob(
							[JSON.stringify({ resolution: resolution })],
							{ type: 'application/json' }
						);
						navigator.sendBeacon(apiUrl, data);
					} else {
						// Fallback to fetch for older browsers
						fetch(apiUrl, {
							method: 'POST',
							headers: { 'Content-Type': 'application/json' },
							credentials: 'same-origin',
							body: JSON.stringify({ resolution: resolution }),
							keepalive: true
						}).catch(function(error) {
							console.debug('Resolution tracking failed:', error);
						});
					}
				} catch (error) {
					console.debug('Resolution detection failed:', error);
				}
			}, 300); // Increased timeout to ensure REST API is ready
		});
	})();
	</script>
	<?php
}


/**
 * Track website, phone, and email link clicks
 *
 * @since 1.0.0
 */
add_action( 'wp_footer', 'directorist_analytics_click_tracker', 100 );

function directorist_analytics_click_tracker() {
	// Only on single listing pages
	if ( ! is_singular( 'at_biz_dir' ) ) {
		return;
	}

	$listing_id = get_the_ID();
	?>
	<script>
	(function() {
		document.addEventListener('DOMContentLoaded', function() {
			var listingId = <?php echo absint( $listing_id ); ?>;
			var apiUrl = '<?php echo esc_url( rest_url( 'directorist-analytics/track/click' ) ); ?>';

			// Track website clicks
			var websiteLinks = document.querySelectorAll('.directorist-single-info-web a');
			websiteLinks.forEach(function(link) {
				link.addEventListener('click', function() {
					trackClick('website');
				});
			});

			// Track phone clicks
			var phoneLinks = document.querySelectorAll('a[href^="tel:"]');
			phoneLinks.forEach(function(link) {
				link.addEventListener('click', function() {
					trackClick('phone');
				});
			});

			// Track email clicks
			var emailLinks = document.querySelectorAll('.directorist-single-info-email a[href^="mailto:"]');
			emailLinks.forEach(function(link) {
				link.addEventListener('click', function() {
					trackClick('email');
				});
			});

			// Tracking function
			function trackClick(type) {
				try {
					var data = JSON.stringify({
						listing_id: listingId,
						click_type: type
					});

					navigator.sendBeacon(apiUrl, new Blob([data], { type: 'application/json' }));
				} catch (error) {
					console.debug('Click tracking failed:', error);
				}
			}
		});
	})();
	</script>
	<?php
}