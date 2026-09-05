<?php //phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * Cron.
 *
 * @package Cron
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request Google PageSpeed Insights.
 *
 * @param boolean $cron Is a CRON request.
 * @return void
 */
function seopress_request_page_speed_fn( $cron = false ) {
	$options = get_option( 'seopress_pro_option_name' );

	// Save URLs field.
	if ( isset( $_POST['seopress_ps_url'] ) ) {
		$options['seopress_ps_url'] = sanitize_textarea_field( $_POST['seopress_ps_url'] );
		update_option( 'seopress_pro_option_name', $options );
	} elseif ( isset( $options['seopress_ps_url'] ) ) {
		$seopress_get_site_url = $options['seopress_ps_url'];
	} else {
		$seopress_get_site_url = get_home_url();
	}

	$options = get_option( 'seopress_pro_option_name' );

	// Save API key.
	if ( isset( $_POST['seopress_ps_api_key'] ) ) {
		$options['seopress_ps_api_key'] = sanitize_text_field( $_POST['seopress_ps_api_key'] );
		update_option( 'seopress_pro_option_name', $options );
	}

	$options = get_option( 'seopress_pro_option_name' );

	$seopress_google_api_key = ! empty( $options['seopress_ps_api_key'] ) ? $options['seopress_ps_api_key'] : 'AIzaSyBqvSx2QrqbEqZovzKX8znGpTosw7KClHQ';
	$seopress_get_site_url   = ! empty( $options['seopress_ps_url'] ) ? $options['seopress_ps_url'] : get_home_url();

	delete_transient( 'seopress_results_page_speed' );
	delete_transient( 'seopress_results_page_speed_desktop' );

	$args = array(
		'timeout'  => 120,
		'blocking' => true,
	);

	if ( function_exists( 'seopress_normalized_locale' ) ) {
		$language = seopress_normalized_locale( get_locale() );
	} else {
		$language = get_locale();
	}

	// Mobile.
	if ( false === ( $seopress_results_page_speed_cache = get_transient( 'seopress_results_page_speed' ) ) ) {
		$seopress_results_page_speed       = wp_remote_retrieve_body( wp_remote_get( 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' . $seopress_get_site_url . '&key=' . $seopress_google_api_key . '&screenshot=true&strategy=mobile&category=performance&category=seo&category=best-practices&locale=' . $language, $args ) );
		$seopress_results_page_speed_cache = $seopress_results_page_speed;
		set_transient( 'seopress_results_page_speed', $seopress_results_page_speed_cache, 1 * DAY_IN_SECONDS );
	}

	// Desktop.
	if ( false === ( $seopress_results_page_speed_desktop_cache = get_transient( 'seopress_results_page_speed_desktop' ) ) ) {
		$seopress_results_page_speed_desktop       = wp_remote_retrieve_body( wp_remote_get( 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' . $seopress_get_site_url . '&key=' . $seopress_google_api_key . '&screenshot=true&strategy=desktop&category=performance&locale=' . $language, $args ) );
		$seopress_results_page_speed_desktop_cache = $seopress_results_page_speed_desktop;
		set_transient( 'seopress_results_page_speed_desktop', $seopress_results_page_speed_desktop_cache, 1 * DAY_IN_SECONDS );
	}
	$data = array( 'url' => add_query_arg( 'ps', 'done', remove_query_arg( array( 'data_permalink', 'ps' ), admin_url( 'admin.php?page=seopress-pro-page&ps=done#tab=tab_seopress_page_speed' ) ) ) );

	if ( false === $cron ) {
		wp_send_json_success( $data );
		exit();
	}
}
/**
 * Request Page Speed Insights by CRON.
 *
 * @return void
 */
function seopress_request_page_speed_insights_cron() {
	seopress_request_page_speed_fn( true );
}
add_action( 'seopress_page_speed_insights_cron', 'seopress_request_page_speed_insights_cron' );

/**
 * Request Page Speed Insights.
 *
 * @return void
 */
function seopress_request_page_speed() {
	check_ajax_referer( 'seopress_request_page_speed_nonce' );

	if ( current_user_can( seopress_capability( 'manage_options', 'cron' ) ) && is_admin() ) {
		seopress_request_page_speed_fn();
	}
}
add_action( 'wp_ajax_seopress_request_page_speed', 'seopress_request_page_speed' );

/**
 * Request Google Analytics.
 *
 * @param boolean $clear Is clear cache.
 * @return void
 */
use SEOPressPro\Vendor\Google\Analytics\Data\V1beta\BetaAnalyticsDataClient;
use SEOPressPro\Vendor\Google\Analytics\Data\V1beta\DateRange;
use SEOPressPro\Vendor\Google\Analytics\Data\V1beta\Dimension;
use SEOPressPro\Vendor\Google\Analytics\Data\V1beta\OrderBy;
use SEOPressPro\Vendor\Google\Analytics\Data\V1beta\Metric;
use SEOPressPro\Vendor\Google\ApiCore\ApiException;
use SEOPressPro\Vendor\Google\Auth\OAuth2;

/**
 * Whether the GA stats cache currently holds a valid (non-error) result.
 *
 * Used by the fetch error handlers so a transient failure (an expired token
 * refreshed mid-request, a conflicting Google SDK throwing a \Throwable, a
 * PHP 8 TypeError in the gax client…) does not overwrite previously-good data
 * and blank both the WP dashboard widget and the SEOPress Site overview.
 *
 * @return bool
 */
function seopress_ga_stats_cache_is_valid() {
	$cache = get_transient( 'seopress_results_google_analytics' );
	if ( is_string( $cache ) ) {
		$decoded = json_decode( $cache, true );
		$cache   = is_array( $decoded ) ? $decoded : null;
	}

	return is_array( $cache )
		&& empty( $cache['error'] )
		&& ( isset( $cache['sessions'] ) || isset( $cache['users'] ) || isset( $cache['pageviews'] ) );
}

/**
 * Request Google Analytics.
 *
 * @param boolean $clear Is clear cache.
 * @return void
 */
function seopress_request_google_analytics_fn( $clear = false ) {
	if ( seopress_pro_get_service( 'GoogleAnalyticsWidgetsOptionPro' )->getGA4DashboardWidget() === '1' ) {
		return;
	}

	$authOption = seopress_get_service( 'GoogleAnalyticsOption' )->getAuth();
	if ( ( ! empty( $authOption ) || ! empty( seopress_get_service( 'GoogleAnalyticsOption' )->getGA4PropertId() ) ) && ! empty( seopress_pro_get_service( 'GoogleAnalyticsOptionPro' )->getAccessToken() ) ) {
		try {
			// get saved data.
			if ( ! $widget_options = get_option( 'seopress_ga_dashboard_widget_options' ) ) {
				$widget_options = array();
			}

			// check if saved data contains content.
			$seopress_ga_dashboard_widget_options_period = isset( $widget_options['period'] ) ? $widget_options['period'] : false;

			$seopress_ga_dashboard_widget_options_type = isset( $widget_options['type'] ) ? $widget_options['type'] : 'ga_sessions';

			// custom content saved by control callback, modify output.
			if ( $seopress_ga_dashboard_widget_options_period ) {
				$period = $seopress_ga_dashboard_widget_options_period;
			} else {
				$period = '30daysAgo';
			}

			$client_id     = seopress_get_service( 'GoogleAnalyticsOption' )->getAuthClientId();
			$client_secret = seopress_get_service( 'GoogleAnalyticsOption' )->getAuthSecretId();

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				return;
			}

			$ga_account   = 'ga:' . $authOption;
			$redirect_uri = admin_url( 'admin.php?page=seopress-google-analytics' );

			require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . '/vendor/autoload.php';

			$oauth = new OAuth2(
				array(
					'scope'              => 'https://www.googleapis.com/auth/analytics.readonly',
					'tokenCredentialUri' => 'https://oauth2.googleapis.com/token',
					'authorizationUri'   => 'https://accounts.google.com/o/oauth2/auth',
					'clientId'           => $client_id,
					'clientSecret'       => $client_secret,
					'redirectUri'        => admin_url( 'admin.php?page=seopress-google-analytics' ),
					'plugin_name'        => 'SEOPress',
				)
			);

			$client = new \SEOPressPro\Vendor\Google\Client();
			$client->setApplicationName( 'Client_Library_Examples' );
			$client->setClientId( $client_id );
			$client->setClientSecret( $client_secret );
			$client->setRedirectUri( $redirect_uri );
			$client->setScopes( array( 'https://www.googleapis.com/auth/analytics.readonly' ) );
			$client->setApprovalPrompt( 'force' );   // mandatory to get this fucking refreshtoken.
			$client->setAccessType( 'offline' ); // mandatory to get this fucking refreshtoken.
			$client->setIncludeGrantedScopes( true ); // mandatory to get this fucking refreshtoken.
			$client->setPrompt( 'consent' ); // mandatory to get this fucking refreshtoken.

			$client->setAccessToken( seopress_pro_get_service( 'GoogleAnalyticsOptionPro' )->getDebug() );

			if ( $client->isAccessTokenExpired() ) {
				try {
					$client->refreshToken( seopress_pro_get_service( 'GoogleAnalyticsOptionPro' )->getDebug() );
					$seopress_new_access_token = $client->getAccessToken();
					$seopress_google_analytics_options                  = get_option( 'seopress_google_analytics_option_name1' );
					$seopress_google_analytics_options['access_token']  = $seopress_new_access_token['access_token'] ?? null;
					$seopress_google_analytics_options['refresh_token'] = $seopress_new_access_token['refresh_token'] ?? null;
					$seopress_google_analytics_options['debug']         = $seopress_new_access_token ?? null;
					update_option( 'seopress_google_analytics_option_name1', $seopress_google_analytics_options, 'yes' );
				} catch ( \Throwable $refresh_error ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'SEOPress: Google Analytics token refresh failed: ' . $refresh_error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}

					// Keep serving the last good result instead of blanking both
					// dashboards for two hours on a one-off refresh failure. Only
					// cache the error (briefly, so the next cron retries) when we
					// have nothing valid to show.
					if ( ! seopress_ga_stats_cache_is_valid() ) {
						set_transient(
							'seopress_results_google_analytics',
							wp_json_encode( array( 'error' => $refresh_error->getMessage() ) ),
							5 * MINUTE_IN_SECONDS
						);
					}

					if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
						return;
					}

					wp_send_json( $refresh_error->getMessage() );
				}
			}

			// Get current token (refreshed if it was expired, or existing if still valid)
			$current_token_data = $client->getAccessToken();

			$service = new \SEOPressPro\Vendor\Google\Service\AnalyticsReporting( $client );

			// Use the current (possibly refreshed) token from Google Client
			$access_token  = $current_token_data['access_token'] ?? seopress_pro_get_service( 'GoogleAnalyticsOptionPro' )->getAccessToken();
			$refresh_token = $current_token_data['refresh_token'] ?? seopress_pro_get_service( 'GoogleAnalyticsOptionPro' )->getRefreshToken();
			$oauth->setAccessToken( $access_token );
			$oauth->setRefreshToken( $refresh_token );
			// GA4 Stats.
			$all = array();

			/**
			 * Periods exposed by the React Site overview filter.
			 * The currently-selected period feeds the legacy WP widget fields.
			 */
			$ga_periods_to_fetch = array(
				'last1'   => '1daysAgo',
				'last2'   => '2daysAgo',
				'last7'   => '7daysAgo',
				'last30'  => '30daysAgo',
				'last90'  => '90daysAgo',
				'last365' => '365daysAgo',
			);
			$ga_metric_map       = array(
				'ga_sessions'           => 'sessions',
				'ga_users'              => 'newUsers',
				'ga_pageviews'          => 'screenPageViews',
				'ga_avgSessionDuration' => 'averageSessionDuration',
			);
			$ga_periods_data     = array();
			$last_report_error   = null;

			// Get GA4 property ID.
			$property_id = '';
			if ( seopress_get_service( 'GoogleAnalyticsOption' )->getGA4PropertId() ) {
				$property_id = seopress_get_service( 'GoogleAnalyticsOption' )->getGA4PropertId();

				// Get GA4 data — one multi-metric report per period.
				$ga4_data = new BetaAnalyticsDataClient( array( 'credentials' => $oauth ) );

				foreach ( $ga_periods_to_fetch as $period_key => $period_value ) {
					try {
						// Build fresh request message objects on every iteration. A
						// protobuf sub-message can only belong to a single parent
						// request, so reusing the same Metric instances across
						// periods makes runReport() throw from the second call on
						// and silently empties the dashboards.
						$metric_objects = array();
						foreach ( $ga_metric_map as $api_name ) {
							$metric_objects[] = new Metric( array( 'name' => $api_name ) );
						}

						$report = $ga4_data->runReport(
							array(
								'property'   => 'properties/' . $property_id,
								'dateRanges' => array(
									new DateRange(
										array(
											'start_date' => $period_value,
											'end_date'   => 'today',
										)
									),
								),
								'dimensions' => array(
									new Dimension( array( 'name' => 'date' ) ),
								),
								'metrics'    => $metric_objects,
								'orderBys'   => array(
									new OrderBy(
										array(
											'dimension' => new OrderBy\DimensionOrderBy(
												array(
													'dimension_name' => 'date',
													'order_type' => OrderBy\DimensionOrderBy\OrderType::ALPHANUMERIC,
												)
											),
											'desc'      => false,
										)
									),
								),
							)
						);
					} catch ( \Throwable $report_error ) {
						// Skip this period on transient failure; keep what we already have.
						$last_report_error = $report_error;
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( 'SEOPress: Google Analytics runReport failed for period ' . $period_value . ': ' . get_class( $report_error ) . ' — ' . $report_error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
						continue;
					}

					$by_metric           = array();
					$metric_keys_ordered = array_keys( $ga_metric_map );
					foreach ( $report->getRows() as $row ) {
						$date   = $row->getDimensionValues()[0]->getValue();
						$values = $row->getMetricValues();
						foreach ( $metric_keys_ordered as $i => $internal_key ) {
							$internal_metric_name = $ga_metric_map[ $internal_key ];
							if ( isset( $values[ $i ] ) ) {
								$by_metric[ $internal_metric_name ][ $date ] = $values[ $i ]->getValue();
							}
						}
					}

					$ga_periods_data[ $period_key ] = $by_metric;

					// Feed the legacy `$all[0]` slot for the user-selected period so the
					// WP dashboard widget keeps reading the same shape.
					if ( $period_value === $period ) {
						$all[0] = array(
							'sessions'           => isset( $by_metric['sessions'] ) ? $by_metric['sessions'] : array(),
							'users'              => isset( $by_metric['newUsers'] ) ? $by_metric['newUsers'] : array(),
							'pageviews'          => isset( $by_metric['screenPageViews'] ) ? $by_metric['screenPageViews'] : array(),
							'avgSessionDuration' => isset( $by_metric['averageSessionDuration'] ) ? $by_metric['averageSessionDuration'] : array(),
						);
					}
				}

				// If the saved period didn't match any of our preset values, fall back to last30.
				if ( empty( $all ) && isset( $ga_periods_data['last30'] ) ) {
					$by_metric = $ga_periods_data['last30'];
					$all[0]    = array(
						'sessions'           => isset( $by_metric['sessions'] ) ? $by_metric['sessions'] : array(),
						'users'              => isset( $by_metric['newUsers'] ) ? $by_metric['newUsers'] : array(),
						'pageviews'          => isset( $by_metric['screenPageViews'] ) ? $by_metric['screenPageViews'] : array(),
						'avgSessionDuration' => isset( $by_metric['averageSessionDuration'] ) ? $by_metric['averageSessionDuration'] : array(),
					);
				}
			}

			// Every period failed (e.g. an auth/transport error returned by the
			// GA4 Data API on each runReport call): surface the real error
			// instead of caching a misleading empty success, so the dashboards
			// show the error state and the cause is logged. The per-period catch
			// above already logged the underlying exception under WP_DEBUG.
			if ( empty( $ga_periods_data ) && null !== $last_report_error ) {
				$error_message = $last_report_error->getMessage();
				$decoded_error = json_decode( $error_message, true );
				$error         = wp_json_encode( array( 'error' => null !== $decoded_error ? $decoded_error : $error_message ) );

				// Don't clobber a previously-good result; cache briefly so the
				// next cron retries instead of latching the failure.
				if ( ! seopress_ga_stats_cache_is_valid() ) {
					set_transient( 'seopress_results_google_analytics', $error, 5 * MINUTE_IN_SECONDS );
				}

				if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
					return;
				}

				wp_send_json( $error );
			}

			if ( true === $clear ) {
				delete_transient( 'seopress_results_google_analytics' );
			}

			if ( false === ( $seopress_results_google_analytics_cache = get_transient( 'seopress_results_google_analytics' ) ) ) {
				$seopress_results_google_analytics_cache = array();

				// GA4.
				if ( seopress_get_service( 'GoogleAnalyticsOption' )->getGA4PropertId() ) {
					$seopress_results_google_analytics_cache['sessions']  = isset( $all[0]['sessions'] ) && is_array( $all[0]['sessions'] ) ? array_sum( $all[0]['sessions'] ) : 0;
					$seopress_results_google_analytics_cache['users']     = isset( $all[0]['users'] ) && is_array( $all[0]['users'] ) ? array_sum( $all[0]['users'] ) : 0;
					$seopress_results_google_analytics_cache['pageviews'] = isset( $all[0]['pageviews'] ) && is_array( $all[0]['pageviews'] ) ? array_sum( $all[0]['pageviews'] ) : 0;

					$seopress_results_google_analytics_cache['avgSessionDuration'] = 0;
					if ( isset( $all[0]['avgSessionDuration'] ) && is_array( $all[0]['avgSessionDuration'] ) ) {
						$sum     = array_sum( array_map( 'floatval', $all[0]['avgSessionDuration'] ) );
						$divided = count( $all[0]['avgSessionDuration'] );
						if ( 0 === $divided ) {
							$divided = 1;
						}

						$seopress_results_google_analytics_cache['avgSessionDuration'] = gmdate( 'i:s', round( $sum / $divided ) );
					}

					switch ( $seopress_ga_dashboard_widget_options_type ) {
						case 'ga_sessions':
							$ga_sessions_rows                           = $all[0]['sessions'] ?? array();
							$seopress_ga_dashboard_widget_options_title = __( 'Sessions', 'wp-seopress-pro' );
							break;
						case 'ga_users':
							$ga_sessions_rows                           = $all[0]['users'] ?? array();
							$seopress_ga_dashboard_widget_options_title = __( 'Users', 'wp-seopress-pro' );
							break;
						case 'ga_pageviews':
							$ga_sessions_rows                           = $all[0]['pageviews'] ?? array();
							$seopress_ga_dashboard_widget_options_title = __( 'Page Views', 'wp-seopress-pro' );
							break;
						case 'ga_avgSessionDuration':
							$ga_sessions_rows                           = $all[0]['avgSessionDuration'] ?? array();
							$seopress_ga_dashboard_widget_options_title = __( 'Session Duration', 'wp-seopress-pro' );
							break;
						default:
							$ga_sessions_rows                           = $all[0]['sessions'] ?? array();
							$seopress_ga_dashboard_widget_options_title = __( 'Sessions', 'wp-seopress-pro' );
					}

					/**
					 * Get sessions labels.
					 *
					 * @param array $ga_date The GA date.
					 * @return array
					 */
					function seopress_ga_dashboard_4_get_sessions_labels( $ga_date ) {
						$labels = array();
						foreach ( $ga_date as $key => $value ) {
							array_push( $labels, date_i18n( get_option( 'date_format' ), strtotime( $key ) ) );
						}

						return $labels;
					}

					/**
					 * Get sessions data.
					 *
					 * @param array $ga_sessions_rows The GA sessions rows.
					 * @return array
					 */
					function seopress_ga_dashboard_4_get_sessions_data( $ga_sessions_rows ) {
						$data = array();
						foreach ( $ga_sessions_rows as $key => $value ) {
							array_push( $data, $value );
						}

						return $data;
					}
					$seopress_results_google_analytics_cache['sessions_graph_labels'] = seopress_ga_dashboard_4_get_sessions_labels( $ga_sessions_rows );
					$seopress_results_google_analytics_cache['sessions_graph_data']   = seopress_ga_dashboard_4_get_sessions_data( $ga_sessions_rows );
					$seopress_results_google_analytics_cache['sessions_graph_title']  = $seopress_ga_dashboard_widget_options_title;

					// Per-period payload consumed by the React Site overview tab.
					$seopress_results_google_analytics_cache['periods'] = array();
					foreach ( $ga_periods_data as $period_key => $by_metric ) {
						$sessions_per_day  = isset( $by_metric['sessions'] ) ? $by_metric['sessions'] : array();
						$users_per_day     = isset( $by_metric['newUsers'] ) ? $by_metric['newUsers'] : array();
						$pageviews_per_day = isset( $by_metric['screenPageViews'] ) ? $by_metric['screenPageViews'] : array();
						$avg_per_day       = isset( $by_metric['averageSessionDuration'] ) ? $by_metric['averageSessionDuration'] : array();

						$labels = array_map(
							function ( $date ) {
								return date_i18n( get_option( 'date_format' ), strtotime( $date ) );
							},
							array_keys( $sessions_per_day )
						);

						$total_sessions   = array_sum( array_map( 'floatval', $sessions_per_day ) );
						$total_users      = array_sum( array_map( 'floatval', $users_per_day ) );
						$total_pageviews  = array_sum( array_map( 'floatval', $pageviews_per_day ) );
						$avg_count        = count( $avg_per_day );
						$avg_duration_sec = 0;
						if ( $avg_count > 0 ) {
							$avg_duration_sec = array_sum( array_map( 'floatval', $avg_per_day ) ) / $avg_count;
						}

						$seopress_results_google_analytics_cache['periods'][ $period_key ] = array(
							'labels'  => $labels,
							'metrics' => array(
								'ga_sessions'           => array_values( array_map( 'floatval', $sessions_per_day ) ),
								'ga_users'              => array_values( array_map( 'floatval', $users_per_day ) ),
								'ga_pageviews'          => array_values( array_map( 'floatval', $pageviews_per_day ) ),
								'ga_avgSessionDuration' => array_values( array_map( 'floatval', $avg_per_day ) ),
							),
							'totals'  => array(
								'sessions'           => (int) $total_sessions,
								'users'              => (int) $total_users,
								'pageviews'          => (int) $total_pageviews,
								'avgSessionDuration' => gmdate( 'i:s', (int) round( $avg_duration_sec ) ),
							),
						);
					}
				}

				// Transient.
				set_transient( 'seopress_results_google_analytics', $seopress_results_google_analytics_cache, 2 * HOUR_IN_SECONDS );
			}

			// Return.
			$seopress_results_google_analytics_transient = get_transient( 'seopress_results_google_analytics' );

			if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
				return;
			}

			wp_send_json_success( $seopress_results_google_analytics_transient );
		} catch ( \Throwable $e ) {
			// Catch \Throwable, not just \Exception: when another active plugin
			// ships its own (prefixed) copy of the Google SDK — e.g. WooCommerce
			// Google Listings & Ads — class resolution across the two copies can
			// surface a \Error/\TypeError (or a foreign exception) from the gax
			// client. Degrading gracefully here keeps the request alive instead
			// of white-screening the admin. See issue #1405; the complete fix is
			// to prefix our bundled Google vendor libraries.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SEOPress: Google Analytics stats fetch failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			$error_message = $e->getMessage();
			// Try to decode if it's JSON, otherwise use the message as-is
			$decoded_error = json_decode( $error_message, true );
			$error_data    = array(
				'error' => null !== $decoded_error ? $decoded_error : $error_message,
			);
			$error         = wp_json_encode( $error_data );

			// Do not overwrite a previously-good result with an error for two
			// hours: a one-off \Throwable (PHP 8 TypeError, a conflicting Google
			// SDK from another plugin, a token expiring mid-request) would
			// otherwise blank both the WP dashboard widget and the SEOPress Site
			// overview until the cache expires. Cache the error only when there
			// is nothing valid to show, and briefly so the next hourly cron
			// retries instead of latching the failure.
			if ( ! seopress_ga_stats_cache_is_valid() ) {
				set_transient( 'seopress_results_google_analytics', $error, 5 * MINUTE_IN_SECONDS );
			}

			if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
				return;
			}

			wp_send_json( $error );
		}
	}
}
/**
 * Request GA stats by CRON.
 *
 * @return void
 */
function seopress_request_google_analytics_cron() {
	if ( function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'google-analytics' ) ) {
		seopress_request_google_analytics_fn( true );
	}
}
add_action( 'seopress_google_analytics_cron', 'seopress_request_google_analytics_cron' );

/**
 * Request Google Analytics.
 *
 * @return void
 */
function seopress_request_google_analytics() {
	check_ajax_referer( 'seopress_request_google_analytics_nonce' );
	if ( ( current_user_can( seopress_capability( 'manage_options', 'cron' ) ) || seopress_advanced_security_ga_widget_check() === true ) && is_admin() ) {
		if ( function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'google-analytics' ) ) {
			// Tell the user why a sync produces nothing instead of silently
			// returning zeros: the underlying fetch needs the OAuth token, the
			// API credentials and a property to be in place.
			$has_token = ! empty( seopress_pro_get_service( 'GoogleAnalyticsOptionPro' )->getAccessToken() );
			$has_creds = '' !== (string) seopress_get_service( 'GoogleAnalyticsOption' )->getAuthClientId()
				&& '' !== (string) seopress_get_service( 'GoogleAnalyticsOption' )->getAuthSecretId();
			$has_prop  = '' !== (string) seopress_get_service( 'GoogleAnalyticsOption' )->getGA4PropertId();

			if ( ! $has_creds || ! $has_token || ! $has_prop ) {
				wp_send_json_error( array(
					'message' => __( 'Google Analytics is not fully connected yet. Add your Client ID and Secret ID, connect your Google account, then choose your property.', 'wp-seopress-pro' ),
				) );
			}

			$force = isset( $_POST['force'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force'] ) );
			seopress_request_google_analytics_fn( $force );
		}
	}
}
add_action( 'wp_ajax_seopress_request_google_analytics', 'seopress_request_google_analytics' );

/**
 * Request Matomo stats.
 *
 * @param boolean $clear Is clear cache.
 * @return void|string
 */
function seopress_request_matomo_analytics_fn( $clear = false ) {
	if ( seopress_pro_get_service( 'GoogleAnalyticsWidgetsOptionPro' )->getMatomoDashboardWidget() === '1' ) {
		return;
	}

	// Clear cache if CRON.
	if ( true === $clear ) {
		delete_transient( 'seopress_results_matomo' );
	}

	if ( false === ( $seopress_results_matomo_cache = get_transient( 'seopress_results_matomo' ) ) ) {
		$seopress_results_matomo_cache = array();

		$matomo_id = seopress_get_service( 'GoogleAnalyticsOption' )->getMatomoId() ? seopress_get_service( 'GoogleAnalyticsOption' )->getMatomoId() : null;

		if ( empty( $matomo_id ) ) {
			return;
		}

		$site_id = seopress_get_service( 'GoogleAnalyticsOption' )->getMatomoSiteId() ? seopress_get_service( 'GoogleAnalyticsOption' )->getMatomoSiteId() : null;

		if ( empty( $site_id ) ) {
			return;
		}

		$auth_token = seopress_get_service( 'GoogleAnalyticsOption' )->getMatomoAuthToken() ? seopress_get_service( 'GoogleAnalyticsOption' )->getMatomoAuthToken() : null;

		if ( empty( $auth_token ) ) {
			return;
		}

		// get saved data.
		if ( ! $widget_options = get_option( 'seopress_matomo_dashboard_widget_options' ) ) {
			$widget_options = array();
		}

		// check if saved data contains content.
		$seopress_matomo_dashboard_widget_options_period = isset( $widget_options['period'] ) ? $widget_options['period'] : false;

		$seopress_matomo_dashboard_widget_options_type = isset( $widget_options['type'] ) ? $widget_options['type'] : 'nb_visits';

		// custom content saved by control callback, modify output.
		if ( $seopress_matomo_dashboard_widget_options_period ) {
			$period = $seopress_matomo_dashboard_widget_options_period;
		} else {
			$period = 'last30';
		}

		$url = 'https://' . $matomo_id;

		/**
		 * Periods exposed by the React Site overview filter.
		 * The legacy WP dashboard widget always reads whichever one matches `$period`.
		 */
		$periods_to_fetch = array( 'last1', 'last2', 'last7', 'last30', 'last90', 'last365' );
		if ( ! in_array( $period, $periods_to_fetch, true ) ) {
			$periods_to_fetch[] = $period;
		}

		$fetched_periods = array();
		$response        = null;

		foreach ( $periods_to_fetch as $period_key ) {
			$body = array(
				'module'          => 'API',
				'method'          => 'API.getProcessedReport',
				'idSite'          => $site_id,
				'date'            => $period_key,
				'period'          => 'day',
				'apiModule'       => 'VisitsSummary',
				'apiAction'       => 'get',
				'format'          => 'json',
				'token_auth'      => $auth_token,
				'filter_truncate' => 5,
				'language'        => 'en',
			);

			$args = array(
				'blocking'  => true,
				'timeout'   => 10,
				'body'      => $body,
			);

			/**
			 * Filter whether the Matomo request validates the TLS certificate.
			 *
			 * The request carries the Matomo `token_auth`, so verification is on
			 * by default. Self-hosted instances behind a self-signed certificate
			 * can opt out, accepting that the token then travels over a
			 * connection an active network attacker can read.
			 *
			 * @since 10.2.0
			 *
			 * @param bool   $sslverify Whether to verify the certificate. Default true.
			 * @param string $url       The Matomo endpoint being called.
			 */
			$args['sslverify'] = (bool) apply_filters( 'seopress_matomo_request_sslverify', true, $url );

			$single = wp_remote_post( $url, $args );
			if ( is_wp_error( $single ) ) {
				continue;
			}
			$single = json_decode( wp_remote_retrieve_body( $single ), true );
			if ( ! is_array( $single ) ) {
				continue;
			}

			$fetched_periods[ $period_key ] = $single;

			// Keep the user-selected period as the base response so legacy fields
			// (`all`, `sessions_graph_*`) target the period the WP widget expects.
			if ( $period_key === $period ) {
				$response = $single;
			}
		}

		if ( null === $response && ! empty( $fetched_periods ) ) {
			$response = reset( $fetched_periods );
		}

		if ( ! is_array( $response ) ) {
			return;
		}

		switch ( $seopress_matomo_dashboard_widget_options_type ) {
			case 'nb_uniq_visitors':
				$widget_title = __( 'Unique visitors', 'wp-seopress-pro' );
				break;
			case 'nb_visits':
				$widget_title = __( 'Visits', 'wp-seopress-pro' );
				break;
			case 'max_actions':
				$widget_title = __( 'Maximum actions in one visit', 'wp-seopress-pro' );
				break;
			case 'nb_actions_per_visit':
				$widget_title = __( 'Average actions per visit', 'wp-seopress-pro' );
				break;
			case 'bounce_rate':
				$widget_title = __( 'Bounce Rate', 'wp-seopress-pro' );
				break;
			case 'avg_time_on_site':
				$widget_title = __( 'Avg. Visit Duration (in seconds)', 'wp-seopress-pro' );
				break;
			default:
				$widget_title = __( 'Unique visitors', 'wp-seopress-pro' );
		}

		/**
		 * Get sessions labels.
		 *
		 * @param array $rows The rows.
		 * @return array
		 */
		function seopress_matomo_get_sessions_labels( $rows ) {
			$labels = array();

			if ( is_array( $rows ) && isset( $rows['reportMetadata'] ) ) {
				$rows = $rows['reportMetadata'];
				foreach ( $rows as $key => $value ) {
					if ( isset( $key ) ) {
						$labels[] = date_i18n( get_option( 'date_format' ), strtotime( $key ) );
					} else {
						$labels[] = 0;
					}
				}
			}
			return $labels;
		}

		/**
		 * Get sessions data.
		 *
		 * @param array  $rows The rows.
		 * @param string $seopress_matomo_dashboard_widget_options_type The options type.
		 * @return array
		 */
		function seopress_matomo_get_sessions_data( $rows, $seopress_matomo_dashboard_widget_options_type ) {
			$data = array();
			if ( is_array( $rows ) && isset( $rows['reportData'] ) ) {
				$rows = array_values( $rows['reportData'] );
				foreach ( $rows as $key => $value ) {
					if ( isset( $value[ $seopress_matomo_dashboard_widget_options_type ] ) ) {
						// Bounce rate: remove %.
						if ( 'bounce_rate' === $seopress_matomo_dashboard_widget_options_type ) {
							$value[ $seopress_matomo_dashboard_widget_options_type ] = rtrim( $value[ $seopress_matomo_dashboard_widget_options_type ], '%' );
						}

						// Average time: convert to seconds.
						if ( 'avg_time_on_site' === $seopress_matomo_dashboard_widget_options_type ) {
							$value[ $seopress_matomo_dashboard_widget_options_type ] = strtotime( "1970-01-01 $value[$seopress_matomo_dashboard_widget_options_type] UTC" );
						}

						$data[] = $value[ $seopress_matomo_dashboard_widget_options_type ] ? $value[ $seopress_matomo_dashboard_widget_options_type ] : 0;
					} else {
						$data[] = 0;
					}
				}
			}
			return $data;
		}

		/**
		 * Convert timestamp to seconds.
		 *
		 * @param string $n The timestamp.
		 * @return int
		 */
		function seopress_timestamp_to_seconds( $n ) {
			return strtotime( "1970-01-01 $n UTC" );
		}

		/**
		 * Remove percentage.
		 *
		 * @param string $n The value.
		 * @return string
		 */
		function seopress_remove_pourcentage( $n ) {
			return rtrim( $n, '%' );
		}

		/**
		 * Get all data.
		 *
		 * @param array $rows The rows.
		 * @return array
		 */
		function seopress_matomo_get_all_data( $rows ) {
			$data = array();
			$rows = $rows['reportData'];

			if ( ! is_array( $rows ) ) {
				return $data;
			}

			if ( empty( $rows ) ) {
				return $data;
			}

			// Unique Visitors.
			$data['nb_uniq_visitors'] = array_sum( array_column( $rows, 'nb_uniq_visitors' ) );

			// Visits.
			$data['nb_visits'] = array_sum( array_column( $rows, 'nb_visits' ) );

			// Max actions.
			$max_actions         = array_column( $rows, 'max_actions' );
			$data['max_actions'] = ! empty( $max_actions ) ? max( $max_actions ) : null;

			// Actions per visit.
			$data['nb_actions_per_visit'] = array_column( $rows, 'nb_actions_per_visit' );
			$count                        = count( $data['nb_actions_per_visit'] );
			if ( $count > 1 ) {
				$data['nb_actions_per_visit'] = round( array_sum( $data['nb_actions_per_visit'] ) / $count, 2 );
			} else {
				// Guard the single/empty case: an empty column (a partial Matomo
				// report) has no index 0, which warns on PHP 8.
				$data['nb_actions_per_visit'] = 1 === $count ? $data['nb_actions_per_visit'][0] : 0;
			}

			// Bounce rate.
			$data['bounce_rate'] = array_map( 'seopress_remove_pourcentage', array_column( $rows, 'bounce_rate' ) );
			$count               = count( $data['bounce_rate'] );
			if ( $count > 1 ) {
				$data['bounce_rate'] = round( array_sum( $data['bounce_rate'] ) / $count, 2 );
			} else {
				$data['bounce_rate'] = 1 === $count ? $data['bounce_rate'][0] : 0;
			}

			// Avg. Visit Duration.
			$data['avg_time_on_site'] = array_map( 'seopress_timestamp_to_seconds', array_column( $rows, 'avg_time_on_site' ) );
			$count                    = count( $data['avg_time_on_site'] );
			if ( $count > 1 ) {
				$data['avg_time_on_site'] = round( array_sum( $data['avg_time_on_site'] ) / $count, 2 );
			} else {
				$data['avg_time_on_site'] = 1 === $count ? $data['avg_time_on_site'][0] : 0;
			}

			return $data;
		}

		if ( ! is_wp_error( $response ) ) {
			$response['sessions_graph_labels'] = seopress_matomo_get_sessions_labels( $response );
			$response['sessions_graph_data']   = seopress_matomo_get_sessions_data( $response, $seopress_matomo_dashboard_widget_options_type );
			$response['sessions_graph_title']  = $widget_title;
			$response['all']                   = seopress_matomo_get_all_data( $response );

			// Per-period payload consumed by the React Site overview tab.
			// One Matomo VisitsSummary call returns every metric per day, so we
			// reuse each fetched response to build labels + all 6 metric series
			// + period totals without extra HTTP traffic.
			$metric_keys         = array( 'nb_visits', 'nb_uniq_visitors', 'max_actions', 'nb_actions_per_visit', 'bounce_rate', 'avg_time_on_site' );
			$response['periods'] = array();
			foreach ( $fetched_periods as $period_key => $period_response ) {
				$labels  = seopress_matomo_get_sessions_labels( $period_response );
				$metrics = array();
				foreach ( $metric_keys as $metric_key ) {
					$metrics[ $metric_key ] = seopress_matomo_get_sessions_data( $period_response, $metric_key );
				}
				$response['periods'][ $period_key ] = array(
					'labels'  => $labels,
					'metrics' => $metrics,
					'totals'  => seopress_matomo_get_all_data( $period_response ),
				);
			}

			// Transient.
			set_transient( 'seopress_results_matomo', $response, 2 * HOUR_IN_SECONDS );
		}
	}
	// Return.
	$seopress_results_matomo_transient = get_transient( 'seopress_results_matomo' );

	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return;
	}

	wp_send_json_success( $seopress_results_matomo_transient );
}
/**
 * Request Matomo Analytics by CRON.
 *
 * @return void
 */
function seopress_request_matomo_analytics_cron() {
	if ( function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'google-analytics' ) ) {
		seopress_request_matomo_analytics_fn( true );
	}
}
add_action( 'seopress_matomo_analytics_cron', 'seopress_request_matomo_analytics_cron' );

/**
 * Request Matomo Analytics.
 *
 * @return void
 */
function seopress_request_matomo_analytics() {
	check_ajax_referer( 'seopress_request_matomo_analytics_nonce' );

	if ( ( current_user_can( seopress_capability( 'manage_options', 'cron' ) ) || seopress_advanced_security_matomo_widget_check() === true ) && is_admin() ) {
		if ( function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'google-analytics' ) ) {
			$force = isset( $_POST['force'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force'] ) );
			seopress_request_matomo_analytics_fn( $force );
		}
	}
}
add_action( 'wp_ajax_seopress_request_matomo_analytics', 'seopress_request_matomo_analytics' );

/**
 * Send 404 weekly email notifications.
 *
 * @return void
 */
function seopress_404_send_alert() {
	$to      = seopress_pro_get_service( 'OptionPro' )->get404RedirectEnableMailsFrom();
	$subject = /* translators: %s name of the site from General settings */ sprintf( __( '404 alert - %s', 'wp-seopress-pro' ), get_bloginfo( 'name' ) );
	$content = '';

	// Get the Latest 404 errors.
	$args = array(
		'date_query'     => array(
			array(
				'column' => 'post_date_gmt',
				'after'  => '1 week ago',
			),
		),
		'posts_per_page' => 10,
		'post_type'      => 'seopress_404',
		'meta_key'       => '_seopress_redirections_type',
		'meta_compare'   => 'NOT EXISTS',
	);

	$args = apply_filters( 'seopress_404_email_alerts_latest_query', $args );

	$latest_404_query = new WP_Query( $args );

	if ( $latest_404_query->have_posts() ) {
		$errors['latest'] = array();
		while ( $latest_404_query->have_posts() ) {
			$latest_404_query->the_post();

			$errors['latest'][] = array(
				'url'   => get_the_title(),
				'count' => get_post_meta( get_the_ID(), 'seopress_404_count', true ),
			);
		}
		wp_reset_postdata();
	}

	if ( ! empty( $errors['latest'] ) ) {
		$content .= '<h2>' . __( 'Latest 404 errors since 1 week', 'wp-seopress-pro' ) . '</h2>';
		$content .= '<ul>';
		foreach ( $errors['latest'] as $error ) {
			$hits     = ! empty( $error['count'] ) ? ' - ' . $error['count'] . __( ' Hits', 'wp-seopress-pro' ) : '';
			$content .= '<li>' . get_home_url() . '/' . $error['url'] . $hits . '</li>';
		}
		$content .= '</ul>';
	}

	// Get the top 404 errors.
	$args = array(
		'posts_per_page' => 10,
		'post_type'      => 'seopress_404',
		'meta_key'       => 'seopress_404_count',
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'     => 'seopress_404_count',
				'compare' => 'EXISTS',
				'type'    => 'NUMERIC',
			),
			array(
				'key'     => '_seopress_redirections_type',
				'compare' => 'NOT EXISTS',
			),
		),
		'order'          => 'DESC',
		'orderby'        => 'meta_value_num',
	);

	$args = apply_filters( 'seopress_404_email_alerts_top_query', $args );

	$top_404_query = new WP_Query( $args );

	if ( $top_404_query->have_posts() ) {
		$errors['top'] = array();
		while ( $top_404_query->have_posts() ) {
			$top_404_query->the_post();

			$errors['top'][] = array(
				'url'   => get_the_title(),
				'count' => get_post_meta( get_the_ID(), 'seopress_404_count', true ),
			);
		}
		wp_reset_postdata();
	}

	if ( ! empty( $errors['top'] ) ) {
		$content .= '<h2>' . __( 'Top 404 errors', 'wp-seopress-pro' ) . '</h2>';
		$content .= '<ul>';
		foreach ( $errors['top'] as $error ) {
			$hits     = ! empty( $error['count'] ) ? ' - ' . $error['count'] . __( ' Hits', 'wp-seopress-pro' ) : '';
			$content .= '<li>' . get_home_url() . '/' . $error['url'] . $hits . '</li>';
		}
		$content .= '</ul>';
	}

	if ( ! empty( $content ) ) {
		// Use the new EmailService.
		$email_service       = \SEOPress\Services\Email\EmailService::get_instance();
		$unsubscribe_service = new \SEOPressPro\Services\Email\UnsubscribeService();

		// Add the view all 404 errors button.
		$content .= '<p style="text-align: center;"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=seopress-redirections&view=404' ) ) . '">' . __( 'View all 404 errors', 'wp-seopress-pro' ) . '</a></p>';

		// Send individual emails so each recipient gets their own unsubscribe link.
		$recipients = array_filter( array_map( 'trim', explode( ',', $to ) ) );

		foreach ( $recipients as $recipient ) {
			$email_args = array(
				'header_subject'  => $subject,
				'footer_text'     => sprintf(
					/* translators: %s site name */
					esc_html__( 'Sent from %s', 'wp-seopress-pro' ),
					get_bloginfo( 'name' )
				),
				'text_color'      => '#505050',
				'bg_color'        => '#F9F9F9',
				'unsubscribe_url' => $unsubscribe_service->get_unsubscribe_url( $recipient, '404_alerts' ),
			);

			$email_service->send( $recipient, $subject, $content, $email_args );
		}
	}
}

/**
 * Send 404 email alerts by CRON.
 *
 * @return void
 */
function seopress_404_send_alert_cron() {
	if ( ( function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( '404' ) ) && '1' === seopress_pro_get_service( 'OptionPro' )->get404RedirectEnableMails() && '' !== seopress_pro_get_service( 'OptionPro' )->get404RedirectEnableMailsFrom() ) {
		seopress_404_send_alert();
	}
}
add_action( 'seopress_404_email_alerts_cron', 'seopress_404_send_alert_cron' );

/**
 * 404 Cleaning CRON.
 *
 * @param boolean $force Is force.
 * @return void
 */
function seopress_404_cron_cleaning_action( $force = false ) {
	if ( '1' === seopress_pro_get_service( 'OptionPro' )->get404Cleaning() || true === $force ) {
		$args = array(
			'date_query'     => array(
				array(
					'column' => 'post_date_gmt',
					'before' => '1 month ago',
				),
			),
			'posts_per_page' => -1,
			'post_type'      => 'seopress_404',
			'meta_key'       => '_seopress_redirections_type',
			'meta_compare'   => 'NOT EXISTS',
		);

		$args = apply_filters( 'seopress_404_cleaning_query', $args );

		// The Query.
		$old_404_query = new WP_Query( $args );

		// The Loop.
		if ( $old_404_query->have_posts() ) {
			while ( $old_404_query->have_posts() ) {
				$old_404_query->the_post();
				wp_delete_post( get_the_ID(), true );
			}
			/* Restore original Post Data */
			wp_reset_postdata();
		}
	}
}
add_action( 'seopress_404_cron_cleaning', 'seopress_404_cron_cleaning_action', 10, 1 );

/**
 * Get Insights from Google Search Console.
 *
 * @return void
 */
function seopress_get_insights_gsc_cron() {
	// Check if GSC toggle is ON.
	if ( seopress_get_service( 'ToggleOption' )->getToggleInspectUrl() !== '1' ) {
		return;
	}

	// Get Google API Key.
	$options        = get_option( 'seopress_instant_indexing_option_name' );
	$google_api_key = isset( $options['seopress_instant_indexing_google_api_key'] ) ? $options['seopress_instant_indexing_google_api_key'] : '';

	if ( empty( $google_api_key ) ) {
		return;
	}

	try {
		$service = seopress_pro_get_service( 'SearchConsole' );

		$response = $service->handle();
		if ( 'error' === $response['status'] ) {
			return;
		}

		foreach ( $response['data'] as $row ) {
			$result = $service->saveDataFromRowResult( $row );
		}
	} catch ( \Throwable $e ) {
		// Catch \Throwable so a bundled-Google-SDK collision with another
		// plugin (see issue #1405) degrades gracefully instead of fataling.
	}
}
add_action( 'seopress_insights_gsc_cron', 'seopress_get_insights_gsc_cron' );

/**
 * Send emails / Slack SEO alerts.
 *
 * @return void
 */
function seopress_send_alerts_cron() {
	// Check if SEO Alerts toggle is ON.
	if ( seopress_get_service( 'ToggleOption' )->getToggleAlerts() !== '1' ) {
		return;
	}

	// Check if email/slack webhook are set.
	if ( empty( seopress_pro_get_service( 'OptionPro' )->getSEOAlertsRecipients() ) && empty( seopress_pro_get_service( 'OptionPro' )->getSEOAlertsSlackWebhookUrl() ) ) {
		return;
	}

	// Init.
	$alerts                 = array();
	$alerts['noindex']      = false;
	$alerts['robots']       = false;
	$alerts['xml_sitemaps'] = false;
	$sitemap_errors         = array();

	// Check noindex on homepage.
	if ( seopress_pro_get_service( 'OptionPro' )->getSEOAlertsNoIndex() === '1' ) {
		$alerts['noindex'] = false;

		$args = array(
			'blocking'    => true,
			'timeout'     => 10,
			'redirection' => 1,
		);

		$args = apply_filters( 'seopress_seo_alerts_homepage_args', $args );

		$response = wp_remote_get( get_home_url(), $args );

		if ( ! is_wp_error( $response ) ) {
			$body = wp_remote_retrieve_body( $response );

			// Load HTML into DOMDocument.
			$dom = new DOMDocument();
			libxml_use_internal_errors( true ); // Suppress errors for malformed HTML.
			if ( $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $body ) ) {
				$xpath = new DOMXPath( $dom );

				// Find meta robots tag.
				$meta_robots = $xpath->query( '//meta[@name="robots"]' );

				if ( $meta_robots->length > 0 ) {
					$content = $meta_robots->item( 0 )->getAttribute( 'content' );
					if ( strpos( $content, 'noindex' ) !== false ) {
						// "noindex" found.
						$alerts['noindex'] = true;
					}
				}
			}
			libxml_clear_errors(); // Clear libxml errors.
		}
	}

	// Check robots.txt file.
	if ( seopress_pro_get_service( 'OptionPro' )->getSEOAlertsRobotsTxt() === '1' ) {
		$alerts['robots'] = false;

		$args = array(
			'blocking'    => true,
			'timeout'     => 10,
			'redirection' => 1,
		);

		$args = apply_filters( 'seopress_seo_alerts_robots_args', $args );

		$test = wp_remote_retrieve_response_code( wp_remote_get( seopress_pro_get_robots_txt_alert_url(), $args ) );

		if ( is_wp_error( $test ) || 200 !== $test ) {
			$alerts['robots'] = true;
		}
	}

	// Check XML sitemaps using the comprehensive diagnostic from the free plugin.
	// Only error-severity issues raise an alert; a disabled sitemap is skipped.
	if ( seopress_pro_get_service( 'OptionPro' )->getSEOAlertsXMLSitemaps() === '1' ) {
		$sitemap_result         = seopress_pro_get_service( 'SitemapAlert' )->evaluate();
		$alerts['xml_sitemaps'] = (bool) $sitemap_result['has_error'];
		$sitemap_errors         = $sitemap_result['errors'];
	}

	// Only notify on a new failure (transition from ok to error) so the same
	// problem is not re-sent on every run.
	$last_state = get_option( 'seopress_seo_alerts_last_state', array() );
	if ( ! is_array( $last_state ) ) {
		$last_state = array();
	}

	$has_new_failure = false;
	foreach ( $alerts as $alert_key => $is_failing ) {
		if ( $is_failing && empty( $last_state[ $alert_key ] ) ) {
			$has_new_failure = true;
		}
	}

	update_option( 'seopress_seo_alerts_last_state', $alerts, false );

	// Email alerts.
	if ( ! empty( seopress_pro_get_service( 'OptionPro' )->getSEOAlertsRecipients() ) ) {
		if ( $has_new_failure ) {
			$to      = seopress_pro_get_service( 'OptionPro' )->getSEOAlertsRecipients();
			$subject = /* translators: %s name of the site from General settings */ sprintf( __( 'SEO Alerts - %s', 'wp-seopress-pro' ), get_bloginfo( 'name' ) );
			$content = '';

			if ( ! empty( $alerts ) ) {
				$content .= '<ul>';

				if ( true === $alerts['noindex'] ) {
					$content .= '<li>' . sprintf(
						/* translators: %1$s homepage URL, %2$s homepage URL */
						wp_kses_post( __( '⚠️ Your <strong>homepage</strong> has a <code>noindex</code> meta robots. Please check it at <a href="%1$s" target="_blank">%2$s</a>', 'wp-seopress-pro' ) ),
						esc_url( get_home_url() ),
						esc_url( get_home_url() )
					) . '</li>';
				}
				if ( true === $alerts['robots'] ) {
					$robots_txt_url = seopress_pro_get_robots_txt_alert_url();
					$content       .= '<li>' . sprintf(
						/* translators: %1$s robots.txt URL, %2$s robots.txt URL */
						wp_kses_post( __( '⚠️ Your <code>robots.txt</code> file returns an error. Please check it at <a href="%1$s" target="_blank">%2$s</a>', 'wp-seopress-pro' ) ),
						esc_url( $robots_txt_url ),
						esc_url( $robots_txt_url )
					) . '</li>';
				}
				if ( true === $alerts['xml_sitemaps'] ) {
					$content .= '<li>' . sprintf(
						/* translators: %1$s XML sitemap URL, %2$s XML sitemap URL */
						wp_kses_post( __( '⚠️ Your <strong>XML sitemap</strong> has errors. Index sitemap: <a href="%1$s" target="_blank">%2$s</a>', 'wp-seopress-pro' ) ),
						esc_url( get_home_url() . '/sitemaps.xml' ),
						esc_url( get_home_url() . '/sitemaps.xml' )
					);

					if ( ! empty( $sitemap_errors ) ) {
						$content .= '<ul>';
						foreach ( $sitemap_errors as $sitemap_error ) {
							$content .= '<li>' . esc_html( $sitemap_error['label'] ) . ': ' . esc_html( $sitemap_error['message'] ) . '</li>';
						}
						$content .= '</ul>';
					}

					$content .= '</li>';
				}

				$content .= '</ul>';
			}

			// Add notification management link.
			$content .= '<p>' . sprintf(
				/* translators: %s manage notifications URL */
				wp_kses_post( __( 'You are receiving this email because SEO alerts are enabled on your WordPress site. <a href="%s">Manage notifications</a>', 'wp-seopress-pro' ) ),
				esc_url( admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_alerts' ) )
			) . '</p>';

			// Use the new EmailService.
			$email_service       = \SEOPress\Services\Email\EmailService::get_instance();
			$unsubscribe_service = new \SEOPressPro\Services\Email\UnsubscribeService();

			// Send individual emails so each recipient gets their own unsubscribe link.
			$recipients = array_filter( array_map( 'trim', explode( ',', $to ) ) );

			foreach ( $recipients as $recipient ) {
				$email_args = array(
					'header_subject'  => $subject,
					'footer_text'     => sprintf(
						/* translators: %s site name */
						esc_html__( 'Sent from %s', 'wp-seopress-pro' ),
						get_bloginfo( 'name' )
					),
					'text_color'      => '#505050',
					'bg_color'        => '#F9F9F9',
					'unsubscribe_url' => $unsubscribe_service->get_unsubscribe_url( $recipient, 'seo_alerts' ),
				);

				if ( ! empty( $content ) ) {
					$email_service->send( $recipient, $subject, $content, $email_args );
				}
			}
		}
	}

	// Slack alerts.
	if ( ! empty( seopress_pro_get_service( 'OptionPro' )->getSEOAlertsSlackWebhookUrl() ) ) {
		if ( $has_new_failure ) {
			$title = '🔔 ' . __( 'SEO Alerts', 'wp-seopress-pro' );

			$body = array(
				'blocks' => array(
					array(
						'type' => 'header',
						'text' => array(
							'type'  => 'plain_text',
							'text'  => $title,
							'emoji' => true,
						),
					),
				),
			);
			if ( true === $alerts['noindex'] ) {
				$body['blocks'][] =
				array(
					'type' => 'section',
					'text' => array(
						'type' => 'mrkdwn',
						'text' => '⚠️ Your *homepage* has a `noindex` meta robots. Please check it at ' . get_home_url(),
					),
				);
			}
			if ( true === $alerts['robots'] ) {
				$body['blocks'][] =
				array(
					'type' => 'section',
					'text' => array(
						'type' => 'mrkdwn',
						'text' => '⚠️ Your `robots.txt` file returns an error. Please check it at ' . seopress_pro_get_robots_txt_alert_url(),
					),
				);
			}
			if ( true === $alerts['xml_sitemaps'] ) {
				$sitemap_text = '⚠️ Your *XML sitemap* has errors. Index sitemap: ' . get_home_url() . '/sitemaps.xml';
				foreach ( $sitemap_errors as $sitemap_error ) {
					$sitemap_text .= "\n• " . $sitemap_error['label'] . ': ' . $sitemap_error['message'];
				}
				$body['blocks'][] = array(
					'type' => 'section',
					'text' => array(
						'type' => 'mrkdwn',
						'text' => $sitemap_text,
					),
				);
			}

			$args = array(
				'method'     => 'POST',
				'headers'    => array(
					'Content-type' => 'application/json',
				),
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ),
				'timeout'    => 15,
				'body'       => wp_json_encode( $body ),
			);

			wp_remote_post( seopress_pro_get_service( 'OptionPro' )->getSEOAlertsSlackWebhookUrl(), $args );
		}
	}
}
add_action( 'seopress_alerts_cron', 'seopress_send_alerts_cron' );

/**
 * Site Audit - Run Task.
 *
 * @param int     $offset The offset.
 * @param boolean $new Is new.
 * @return void
 */
/**
 * Where the next batch resumes, skipping a post that keeps killing the worker.
 *
 * A post that fatally ends the request (memory exhaustion, a hard timeout in
 * the DOM analysis, a fatal in a third-party filter) leaves the worker no
 * chance to record anything: the watchdog relaunches from the saved offset,
 * reaches the same post and dies again. The audit never completes and burns a
 * cron slot every five minutes, indefinitely.
 *
 * A fatal cannot be caught, so the only thing that can break that cycle is
 * noticing it from the outside: when a run starts at the same point it started
 * at last time, and the time before that, the post sitting there is the one
 * ending the request. It is skipped and recorded, so one toxic page costs one
 * skipped page rather than the whole audit.
 *
 * @since 10.2.0
 *
 * @param int $offset Offset this run was asked to start from.
 *
 * @return int Offset to actually start from.
 */
function seopress_site_audit_resolve_resume_offset( $offset ) {
	$offset      = max( 0, (int) $offset );
	$stuck_at    = get_option( 'seopress_pro_site_audit_stuck_offset', null );
	$stuck_count = (int) get_option( 'seopress_pro_site_audit_stuck_count', 0 );

	// Moved since last time: the previous run made progress.
	if ( null === $stuck_at || (int) $stuck_at !== $offset ) {
		update_option( 'seopress_pro_site_audit_stuck_offset', $offset, false );
		update_option( 'seopress_pro_site_audit_stuck_count', 1, false );

		return $offset;
	}

	++$stuck_count;

	/**
	 * How many runs may start from the same point before the post sitting
	 * there is treated as the one ending the request.
	 *
	 * Two is not enough: a host-wide timeout can legitimately cost one retry.
	 *
	 * @since 10.2.0
	 *
	 * @param int $threshold Number of runs.
	 */
	$threshold = (int) apply_filters( 'seopress_site_audit_stuck_threshold', 3 );

	if ( $stuck_count < $threshold ) {
		update_option( 'seopress_pro_site_audit_stuck_count', $stuck_count, false );

		return $offset;
	}

	// The worker records the post it is about to analyse, so the one that ended
	// the request can be named even though it left no other trace.
	$skipped = (int) get_option( 'seopress_pro_site_audit_current_post', 0 );

	update_option(
		'seopress_pro_site_audit_last_error',
		array(
			'post_id'   => $skipped,
			'url'       => $skipped ? get_permalink( $skipped ) : '',
			'offset'    => $offset,
			'timestamp' => time(),
		),
		false
	);

	// Zero, not one: the post now sitting at that offset has not been seen yet,
	// and it deserves the same number of chances as any other. Recording it as
	// already sighted would condemn it a run early.
	update_option( 'seopress_pro_site_audit_stuck_offset', $offset + 1, false );
	update_option( 'seopress_pro_site_audit_stuck_count', 0, false );
	update_option( 'seopress_pro_site_audit_offset', $offset + 1, false );

	return $offset + 1;
}

function seopress_site_audit_run_task_fn( $offset = 0, $new = false ) {
	// Check if GSC toggle is ON.
	if ( seopress_get_service( 'ToggleOption' )->getToggleBot() !== '1' ) {
		return;
	}

	// Get post types.
	$post_types = seopress_get_service( 'WordPressData' )->getPostTypes();
	if ( empty( $post_types ) ) {
		return;
	}

	// Get post types for the query.
	$cpt = array_keys( $post_types );

	// Get post types from the Audit settings if available.
	$cpt = seopress_pro_get_service( 'OptionBot' )->getBotScanSettingsAuditCPT() ? array_keys( seopress_pro_get_service( 'OptionBot' )->getBotScanSettingsAuditCPT() ) : $cpt;

	// Get current offset and batch size.
	$batch_size = seopress_pro_get_service( 'OptionBot' )->getBotScanSettingsAuditBatchSize() ? seopress_pro_get_service( 'OptionBot' )->getBotScanSettingsAuditBatchSize() : 10;

	// CPT status.
	$cpt_status = array( 'publish' );

	// Update last scan date.
	if ( true === $new ) {
		delete_option( 'seopress_pro_site_audit_offset' );
		delete_option( 'seopress_pro_site_audit_count_posts' );
		delete_option( 'seopress_pro_site_audit_post_count' );
		delete_option( 'seopress_pro_site_audit_stuck_offset' );
		delete_option( 'seopress_pro_site_audit_stuck_count' );
		delete_option( 'seopress_pro_site_audit_current_post' );
		delete_option( 'seopress_pro_site_audit_last_error' );
		update_option( 'seopress_pro_site_audit_last_scan', time(), false );
	}

	// If no offset was passed, fetch the saved one.
	if ( 0 === (int) $offset ) {
		$offset = get_option( 'seopress_pro_site_audit_offset', 0 );
	}

	// A run that keeps starting where the last one started is stuck on the post
	// sitting there. Step over it rather than dying on it forever.
	$offset = seopress_site_audit_resolve_resume_offset( $offset );

	// Query the posts.
	$args = array(
		'posts_per_page' => $batch_size,
		'offset'         => (int) $offset,
		'post_type'      => $cpt,
		'post_status'    => $cpt_status,
		'fields'         => 'ids',
	);

	// Apply filters if needed.
	$args = apply_filters( 'seopress_site_audit_query', $args, $batch_size, $offset, $cpt, $cpt_status );

	// Fetch posts.
	$postslist = get_posts( $args );

	// Check if there are posts to process.
	if ( ! empty( $postslist ) ) {
		// Update total post count once (if needed).
		if ( 0 === $offset || empty( get_option( 'seopress_pro_site_audit_count_posts' ) ) ) {
			global $wpdb;

			$cpt = array_map(
				function ( $item ) {
					return "'" . esc_sql( $item ) . "'";
				},
				$cpt
			);

			$cpt_string = implode( ',', $cpt );

			$cpt_status = array_map(
				function ( $item ) {
					return "'" . esc_sql( $item ) . "'";
				},
				$cpt_status
			);

			$cpt_status_string = implode( ',', $cpt_status );

			$sql               = "SELECT count(*) FROM {$wpdb->posts} WHERE post_status IN ($cpt_status_string) AND post_type IN ($cpt_string)";
			$total_count_posts = (int) $wpdb->get_var( $sql );

			update_option( 'seopress_pro_site_audit_count_posts', $total_count_posts, false );
		}

		$post_count = get_option( 'seopress_pro_site_audit_post_count' ) ? (int) get_option( 'seopress_pro_site_audit_post_count' ) : 1;

		foreach ( $postslist as $batch_index => $post_id ) {
			$is_running = get_option( 'seopress_pro_site_audit_running', 0 );

			if ( 0 === $is_running ) {
				// Task was canceled, break the loop.
				break;
			}

			// Save the resume point for this post, not the batch's starting
			// offset. Pinning it to the start meant a worker that died mid-batch
			// re-audited and re-counted everything already done in it, which is
			// how post_count climbed past the total ("410 / 67 posts").
			update_option( 'seopress_pro_site_audit_offset', $offset + $batch_index, false );

			// Heartbeat: record that a batch is actively making progress. The
			// watchdog uses this to tell a live audit from a stalled one, so it
			// must be refreshed per post (a batch can be long) and not only per
			// batch.
			update_option( 'seopress_pro_site_audit_heartbeat', time(), false );

			// Ignore issue marked as ignore.
			$is_ignore = seopress_pro_get_service( 'SEOIssuesDatabase' )->getData( $post_id, array( 'issue_ignore' ) );
			if ( ! empty( $is_ignore ) ) {
				continue;
			}

			// Skip if noindex is enabled for this post. Its issues from earlier
			// scans are purged, not just skipped: a page audited before being
			// noindexed would otherwise keep showing up in the results forever,
			// since only visited posts get their rows rewritten.
			if ( get_post_meta( $post_id, '_seopress_robots_index', true ) === 'yes' && seopress_pro_get_service( 'OptionBot' )->getBotScanSettingsAuditNoindex() !== '1' ) {
				seopress_pro_get_service( 'SEOIssuesRepository' )->deleteAllForPost( $post_id );
				continue;
			}

			// Skip if redirections are enabled for this post. Same purge, same
			// reason: a redirected page is out of the audit's scope.
			if ( 'yes' == get_post_meta( $post_id, '_seopress_redirections_enabled', true ) ) {
				seopress_pro_get_service( 'SEOIssuesRepository' )->deleteAllForPost( $post_id );
				continue;
			}

			// Name the post about to be analysed. A fatal leaves no trace of
			// itself, so this is the only record of which post ended the run.
			update_option( 'seopress_pro_site_audit_current_post', $post_id, false );

			// Process post audit logic.
			//
			// A fatal cannot be caught and is handled by the stuck detection at
			// the top of this function. A Throwable can, and one page throwing
			// should cost that page rather than the rest of the batch.
			try {
				$dom_result = seopress_get_service( 'RequestPreview' )->getDomById( $post_id, null );

				if ( ! $dom_result['success'] ) {
					continue;
				}

				$str = $dom_result['body'];

				$data = seopress_get_service( 'DomFilterContent' )->getData( $str, $post_id );
				$data = seopress_get_service( 'DomAnalysis' )->getDataAnalyze( $data, array( 'id' => $post_id ) );

				// Save analysis data.
				$post          = get_post( $post_id );
				$score         = seopress_get_service( 'DomAnalysis' )->getScore( $post );
				$data['score'] = $score;
				$keywords      = seopress_get_service( 'DomAnalysis' )->getKeywords( array( 'id' => $post_id ) );
				seopress_get_service( 'ContentAnalysisDatabase' )->saveData( $post_id, $data, $keywords );
				seopress_get_service( 'GetContentAnalysis' )->getAnalyzes( $post );
			} catch ( \Throwable $e ) {
				update_option(
					'seopress_pro_site_audit_last_error',
					array(
						'post_id'   => $post_id,
						'url'       => get_permalink( $post_id ),
						'offset'    => $offset + $batch_index,
						'message'   => $e->getMessage(),
						'timestamp' => time(),
					),
					false
				);

				continue;
			}

			// Re-enable QM if disabled.
			remove_filter( 'user_has_cap', 'seopress_disable_qm', 10, 3 );

			// Log post title.
			update_option( 'seopress_pro_site_audit_log', $post->post_title . ' - ' . get_permalink( $post_id ), false );

			// Increment current post count. Capped at the total: a counter that
			// reports more posts than exist is never right, and reading past
			// 100% is what the reporter saw first.
			$total_posts = (int) get_option( 'seopress_pro_site_audit_count_posts', 0 );
			$next_count  = $post_count++;

			if ( $total_posts > 0 ) {
				$next_count = min( $next_count, $total_posts );
			}

			update_option( 'seopress_pro_site_audit_post_count', $next_count, false );
		}

		// Update offset for the next batch.
		$new_offset = $offset + $batch_size;
		update_option( 'seopress_pro_site_audit_offset', $new_offset, false );

		if ( (int) get_option( 'seopress_pro_site_audit_running', 1 ) === 1 ) {
			// Schedule the next batch with a slight delay (e.g., 5 seconds).
			$scheduled = wp_schedule_single_event( time() + 5, 'seopress_site_audit_run_task_cron', array( $new_offset ) );

			// If scheduling fails (a cron-table write error, a short-circuiting
			// pre_schedule_event filter, or an identical event already queued),
			// the audit would stall silently. Record the failure and log it when
			// debugging is on; the watchdog below re-drives the batch either way.
			if ( false === $scheduled ) {
				update_option( 'seopress_pro_site_audit_last_error', sprintf( 'Failed to schedule next batch at offset %1$d on %2$s', $new_offset, gmdate( 'c' ) ), false );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'SEOPress Site Audit: failed to schedule next batch at offset ' . $new_offset ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}

			// Keep the self-heal watchdog alive while the audit is running.
			seopress_site_audit_schedule_watchdog();
		} else {
			if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
				wp_send_json_success( 'Site audit task canceled.' );
			}
		}
	} else {
		// No more posts to process, mark the task as finished.
		update_option( 'seopress_pro_site_audit_running', 0, false );
		$start_time = get_option( 'seopress_pro_site_audit_last_scan' );
		update_option( 'seopress_pro_site_audit_scan_duration', time() - $start_time, false );

		// The audit is complete; stop the self-heal watchdog.
		seopress_site_audit_unschedule_watchdog();

		// Send an email notification.
		do_action( 'seopress_site_audit_after_processs' );

		if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
			wp_send_json_success( 'Site audit task completed.' );
		}
	}
}

// Hook the cron event.
add_action( 'seopress_site_audit_run_task_cron', 'seopress_site_audit_run_task_fn', 10, 1 );

/**
 * Site Audit - Register the watchdog recurring interval (every 5 minutes).
 *
 * @param array $schedules Existing cron schedules.
 * @return array
 */
function seopress_site_audit_watchdog_interval( $schedules ) {
	if ( ! isset( $schedules['seopress_site_audit_watchdog_interval'] ) ) {
		$schedules['seopress_site_audit_watchdog_interval'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'SEOPress Site Audit watchdog (every 5 minutes)', 'wp-seopress-pro' ),
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'seopress_site_audit_watchdog_interval' );

/**
 * Site Audit - Ensure the self-heal watchdog is scheduled.
 *
 * @return void
 */
function seopress_site_audit_schedule_watchdog() {
	if ( ! wp_next_scheduled( 'seopress_site_audit_watchdog_cron' ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'seopress_site_audit_watchdog_interval', 'seopress_site_audit_watchdog_cron' );
	}
}

/**
 * Site Audit - Remove the self-heal watchdog and its heartbeat.
 *
 * @return void
 */
function seopress_site_audit_unschedule_watchdog() {
	wp_clear_scheduled_hook( 'seopress_site_audit_watchdog_cron' );
	delete_option( 'seopress_pro_site_audit_heartbeat' );
}

/**
 * Site Audit - Self-heal watchdog.
 *
 * A no-argument recurring event that re-drives a stalled audit. Because it takes
 * no arguments it also runs under `wp cron event run --due-now`, unlike the
 * offset-carrying batch event, which that command skips.
 *
 * The audit is treated as stalled when it is flagged as running but no batch has
 * refreshed the heartbeat within the staleness window. That single condition
 * covers every known failure mode: a request that died mid-batch before it could
 * reschedule, a failed wp_schedule_single_event(), and setups where the
 * offset-carrying batch event is queued but never executed. In each case the
 * next batch is driven directly from the saved offset. A healthy audit keeps its
 * heartbeat fresh, so the watchdog leaves it alone and never runs a batch
 * concurrently with a live one.
 *
 * @return void
 */
function seopress_site_audit_watchdog_fn() {
	// Only guard an audit that is supposed to be running.
	if ( 1 !== (int) get_option( 'seopress_pro_site_audit_running', 0 ) ) {
		seopress_site_audit_unschedule_watchdog();
		return;
	}

	// Leave a healthy audit alone: a live batch refreshes the heartbeat on every
	// processed post.
	$heartbeat   = (int) get_option( 'seopress_pro_site_audit_heartbeat', 0 );
	$stale_after = (int) apply_filters( 'seopress_site_audit_watchdog_stale_seconds', 3 * MINUTE_IN_SECONDS );
	if ( $heartbeat > 0 && ( time() - $heartbeat ) < $stale_after ) {
		return;
	}

	// Stalled: drop any leftover (possibly never-running) batch event and drive
	// the next batch directly from the saved offset. wp_unschedule_hook() is used
	// rather than wp_clear_scheduled_hook() because the batch events carry the
	// offset as an argument, which the latter would not match.
	wp_unschedule_hook( 'seopress_site_audit_run_task_cron' );
	seopress_site_audit_run_task_fn( (int) get_option( 'seopress_pro_site_audit_offset', 0 ) );
}
add_action( 'seopress_site_audit_watchdog_cron', 'seopress_site_audit_watchdog_fn' );

/**
 * Site Audit - Email notification.
 *
 * @return void
 */
function seopress_site_audit_send_email() {
	$to            = seopress_pro_get_service( 'OptionBot' )->getBotScanSettingsEmail() ? seopress_pro_get_service( 'OptionBot' )->getBotScanSettingsEmail() : null;
	$subject       = /* translators: %s name of the site from General settings */ sprintf( __( 'Site audit completed - %s', 'wp-seopress-pro' ), get_bloginfo( 'name' ) );
	$scan_duration = get_option( 'seopress_pro_site_audit_scan_duration' ) ? get_option( 'seopress_pro_site_audit_scan_duration' ) : null;

	if ( $scan_duration ) {
		$scan_duration_minutes = floor( $scan_duration / 60 );
		$scan_duration_seconds = $scan_duration % 60;
	}

	$content  = '<p>' . esc_html__( 'We have finished auditing your site\'s SEO.', 'wp-seopress-pro' ) . '</p>';
	$content .= '<p>' . sprintf(
		wp_kses_post( /* translators: %1$s duration in minutes, %2$s duration in seconds */ __( '<strong>Scan duration:</strong> %1$s minutes %2$s seconds', 'wp-seopress-pro' ) ),
		esc_html( $scan_duration_minutes ),
		esc_html( $scan_duration_seconds )
	) . '</p>';
	$content .= '<p>' . esc_html__( 'Find all the details of the analysis by clicking on the following link:', 'wp-seopress-pro' ) . '</p>';
	$content .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=seopress-bot-batch#tab=tab_seopress_audit' ) ) . '" title="' . esc_html__( 'Read the audit', 'wp-seopress-pro' ) . '">' . esc_html__( 'Read the site audit', 'wp-seopress-pro' ) . '</a></p>';

	// Use the new EmailService.
	$email_service       = \SEOPress\Services\Email\EmailService::get_instance();
	$unsubscribe_service = new \SEOPressPro\Services\Email\UnsubscribeService();

	if ( ! empty( $content ) && null !== $to ) {
		// Send individual emails so each recipient gets their own unsubscribe link.
		$recipients = array_filter( array_map( 'trim', explode( ',', $to ) ) );

		foreach ( $recipients as $recipient ) {
			$email_args = array(
				'header_subject'  => $subject,
				'footer_text'     => sprintf(
					/* translators: %s site name */
					esc_html__( 'Sent from %s', 'wp-seopress-pro' ),
					get_bloginfo( 'name' )
				),
				'text_color'      => '#505050',
				'bg_color'        => '#F9F9F9',
				'unsubscribe_url' => $unsubscribe_service->get_unsubscribe_url( $recipient, 'site_audit' ),
			);

			$email_service->send( $recipient, $subject, $content, $email_args );
		}
	}
}
add_action( 'seopress_site_audit_after_processs', 'seopress_site_audit_send_email' );

/**
 * Site Audit - Historise the completed scan.
 *
 * Persists an aggregated snapshot in {prefix}seopress_site_audit_history so
 * the Overview screen can chart trends over time. One row per successful
 * scan, inserted once the worker hits the "no more posts" branch.
 *
 * @return void
 */
function seopress_site_audit_historize() {
	global $wpdb;

	$table_issues  = $wpdb->prefix . 'seopress_seo_issues';
	$table_history = $wpdb->prefix . 'seopress_site_audit_history';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	$total_crawled = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$table_issues}" );
	$total_issues  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_issues} WHERE issue_ignore = 0" );
	$total_ignored = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_issues} WHERE issue_ignore = 1" );

	$priority_counts = $wpdb->get_results(
		"SELECT issue_priority, COUNT(*) AS count FROM {$table_issues} WHERE issue_ignore = 0 GROUP BY issue_priority",
		OBJECT_K
	);
	// phpcs:enable

	$high   = isset( $priority_counts['high'] ) ? (int) $priority_counts['high']->count : 0;
	$medium = isset( $priority_counts['medium'] ) ? (int) $priority_counts['medium']->count : 0;
	$low    = isset( $priority_counts['low'] ) ? (int) $priority_counts['low']->count : 0;
	$good   = isset( $priority_counts['good'] ) ? (int) $priority_counts['good']->count : 0;

	// Severity-weighted health score (0–100). Each URL starts at 100; one
	// High issue costs 3 pts, Medium 2, Low 1, averaged across crawled URLs.
	// Divisor guards against zero. Must stay in sync with SiteAudit::processOverview.
	$denominator = max( 1, $total_crawled );
	$penalty     = ( $high * 3 + $medium * 2 + $low * 1 ) / $denominator;
	$score       = (int) max( 0, min( 100, round( 100 - $penalty ) ) );

	$duration = (int) get_option( 'seopress_pro_site_audit_scan_duration', 0 );

	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table_history,
		array(
			'scan_date'        => current_time( 'mysql' ),
			'duration_seconds' => $duration,
			'total_crawled'    => $total_crawled,
			'total_issues'     => $total_issues,
			'total_ignored'    => $total_ignored,
			'priority_high'    => $high,
			'priority_medium'  => $medium,
			'priority_low'     => $low,
			'priority_good'    => $good,
			'health_score'     => $score,
		),
		array( '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d' )
	);
}
add_action( 'seopress_site_audit_after_processs', 'seopress_site_audit_historize' );

/**
 * Site Audit - Webhook notification.
 *
 * Posts a JSON summary to a user-configured endpoint once a scan
 * completes, so external tools (CI pipelines, dashboards, automation)
 * can react without polling the REST status endpoint. No-op unless a
 * webhook URL is configured. The URL can be set via the
 * `seopress_bot_scan_settings_webhook_url` Bot option or overridden with
 * the `seopress_site_audit_webhook_url` filter (handy for headless setups
 * that have no settings UI access).
 *
 * @since 9.9.0
 *
 * @return void
 */
function seopress_site_audit_send_webhook() {
	global $wpdb;

	$option_bot = seopress_pro_get_service( 'OptionBot' );
	$url        = $option_bot->getBotScanSettingsWebhookUrl();

	/**
	 * Filter the site-audit webhook URL.
	 *
	 * @since 9.9.0
	 *
	 * @param string $url Configured webhook URL.
	 */
	$url = apply_filters( 'seopress_site_audit_webhook_url', $url );

	if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
		return;
	}

	$table_issues = $wpdb->prefix . 'seopress_seo_issues';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	$total_crawled = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$table_issues}" );
	$total_issues  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_issues} WHERE issue_ignore = 0" );

	$priority_counts = $wpdb->get_results(
		"SELECT issue_priority, COUNT(*) AS count FROM {$table_issues} WHERE issue_ignore = 0 GROUP BY issue_priority",
		OBJECT_K
	);
	// phpcs:enable

	$high   = isset( $priority_counts['high'] ) ? (int) $priority_counts['high']->count : 0;
	$medium = isset( $priority_counts['medium'] ) ? (int) $priority_counts['medium']->count : 0;
	$low    = isset( $priority_counts['low'] ) ? (int) $priority_counts['low']->count : 0;

	$denominator  = max( 1, $total_crawled );
	$penalty      = ( $high * 3 + $medium * 2 + $low * 1 ) / $denominator;
	$health_score = (int) max( 0, min( 100, round( 100 - $penalty ) ) );

	$payload = array(
		'event'        => 'site_audit.completed',
		'event_id'     => wp_generate_uuid4(),
		'site_url'     => home_url(),
		// UTC ISO-8601 so consumers (CI, dashboards, log pipelines) don't have to know the site timezone.
		'completed_at' => current_time( 'c', true ),
		'duration'     => (int) get_option( 'seopress_pro_site_audit_scan_duration', 0 ),
		'health_score' => $health_score,
		'totals'       => array(
			'crawled' => $total_crawled,
			'issues'  => $total_issues,
			'high'    => $high,
			'medium'  => $medium,
			'low'     => $low,
		),
		'report_url'   => admin_url( 'admin.php?page=seopress-bot-batch#tab=tab_seopress_audit' ),
	);

	/**
	 * Filter the site-audit webhook payload before it is dispatched.
	 *
	 * @since 9.9.0
	 *
	 * @param array $payload The webhook payload.
	 */
	$payload = apply_filters( 'seopress_site_audit_webhook_payload', $payload );

	$body = wp_json_encode( $payload );

	$headers = array(
		'Content-Type' => 'application/json',
	);

	// Signed bytes are the raw wp_json_encode($payload) output - the same
	// $body posted below. Receivers MUST verify the HMAC against the raw
	// request body, not against a re-encoded version, otherwise key
	// reordering / whitespace changes will break the signature.
	$secret = $option_bot->getBotScanSettingsWebhookSecret();
	if ( ! empty( $secret ) ) {
		$headers['X-SEOPress-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	// When WP_DEBUG is on, wait for the response so we can surface
	// transport / HTTP errors in the PHP error log - otherwise the user
	// has nothing to debug "the webhook isn't firing" support tickets with.
	$debug    = defined( 'WP_DEBUG' ) && WP_DEBUG;
	$blocking = $debug;

	$args = array(
		'method'      => 'POST',
		'timeout'     => 15,
		'redirection' => 0,
		'blocking'    => $blocking,
		'headers'     => $headers,
		'body'        => $body,
	);

	/**
	 * Filter the wp_safe_remote_post() arguments for the site-audit
	 * webhook (e.g. to add custom auth headers).
	 *
	 * @since 9.9.0
	 *
	 * @param array $args wp_safe_remote_post() arguments.
	 */
	$args = apply_filters( 'seopress_site_audit_webhook_args', $args );

	$response = wp_safe_remote_post( $url, $args );

	if ( $debug ) {
		if ( is_wp_error( $response ) ) {
			error_log( sprintf( '[SEOPress] site-audit webhook transport error: %s', $response->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				error_log( sprintf( '[SEOPress] site-audit webhook returned HTTP %d', $code ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
}
// Priority 5 so the webhook payload is computed from a stable snapshot of
// wp_seopress_seo_issues, before sibling handlers (e.g. historize) get a
// chance to mutate / prune the table.
add_action( 'seopress_site_audit_after_processs', 'seopress_site_audit_send_webhook', 5 );

///////////////////////////////////////////////////////////////////////////////////////////////////
// Broken Links CRON Batch Scan
///////////////////////////////////////////////////////////////////////////////////////////////////

/**
 * Broken Links - Run batch scan task.
 *
 * Processes posts in batches, parsing links and checking HTTP status.
 * Schedules itself for the next batch via wp_schedule_single_event().
 *
 * @since 9.8.0
 *
 * @param int     $offset The current offset.
 * @param boolean $is_new Whether this is a new scan.
 *
 * @return void
 */
function seopress_broken_links_run_task_fn( $offset = 0, $is_new = false ) {
	// Check if bot toggle is ON.
	if ( '1' !== seopress_get_toggle_option( 'bot' ) ) {
		return;
	}

	$option_bot = seopress_pro_get_service( 'OptionBot' );

	// Get post types.
	$cpt = array();
	if ( ! empty( $option_bot->getBotScanSettingsPostTypes() ) ) {
		foreach ( $option_bot->getBotScanSettingsPostTypes() as $cpt_key => $cpt_value ) {
			foreach ( $cpt_value as $_cpt_key => $_cpt_value ) {
				if ( '1' === $_cpt_value ) {
					$cpt[] = $cpt_key;
				}
			}
		}
	}

	if ( empty( $cpt ) ) {
		$post_types = seopress_get_service( 'WordPressData' )->getPostTypes();
		if ( empty( $post_types ) ) {
			return;
		}
		$cpt = array_keys( $post_types );
	}

	// Get settings.
	$batch_size = $option_bot->getBotScanSettingsNumber();
	$batch_size = ( ! empty( $batch_size ) && $batch_size >= 10 ) ? (int) $batch_size : 100;

	$timeout = $option_bot->getBotScanSettingsTimeout();
	$timeout = ! empty( $timeout ) ? (int) $timeout : 5;

	$where     = $option_bot->getBotScanSettingsWhere();
	$only_404  = '1' === $option_bot->getBotScanSettings404();
	$scan_type = '1' === $option_bot->getBotScanSettingsType();

	// Initialize new scan.
	if ( true === $is_new ) {
		delete_option( 'seopress_pro_broken_links_offset' );
		delete_option( 'seopress_pro_broken_links_count_posts' );
		delete_option( 'seopress_pro_broken_links_post_count' );
		update_option( 'seopress_pro_broken_links_last_scan', time(), false );

		// Clean old entries if setting is enabled.
		if ( '1' === $option_bot->getBotScanSettingsCleaning() ) {
			global $wpdb;
			$wpdb->query(
				"DELETE posts, pm
				FROM {$wpdb->posts} AS posts
				LEFT JOIN {$wpdb->postmeta} AS pm ON pm.post_id = posts.ID
				WHERE posts.post_type = 'seopress_bot'"
			);
		}
	}

	// Resume from saved offset if none was passed.
	if ( 0 === (int) $offset ) {
		$offset = (int) get_option( 'seopress_pro_broken_links_offset', 0 );
	}

	// How many posts per CRON batch (smaller than total limit for performance).
	$cron_batch = min( 5, $batch_size );

	// Query posts.
	$args = array(
		'posts_per_page' => $cron_batch,
		'offset'         => $offset,
		'post_type'      => $cpt,
		'post_status'    => 'publish',
		'orderby'        => 'date',
		'order'          => 'DESC',
		'fields'         => 'ids',
		'cache_results'  => false,
	);

	$args      = apply_filters( 'seopress_bot_query', $args, $offset, $cpt );
	$postslist = get_posts( $args );

	if ( ! empty( $postslist ) ) {
		// Count total posts once.
		if ( 0 === $offset || empty( get_option( 'seopress_pro_broken_links_count_posts' ) ) ) {
			global $wpdb;

			$cpt_escaped = array_map(
				function ( $item ) {
					return "'" . esc_sql( $item ) . "'";
				},
				$cpt
			);
			$cpt_string  = implode( ',', $cpt_escaped );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total_count_posts = (int) $wpdb->get_var( "SELECT count(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($cpt_string)" );

			// Limit to configured number.
			$total_count_posts = min( $total_count_posts, $batch_size );
			update_option( 'seopress_pro_broken_links_count_posts', $total_count_posts, false );
		}

		$post_count = (int) get_option( 'seopress_pro_broken_links_post_count', 0 );

		// Check if we've reached the limit.
		if ( $post_count >= $batch_size ) {
			seopress_broken_links_finish_scan();
			return;
		}

		foreach ( $postslist as $post_id ) {
			$is_running = (int) get_option( 'seopress_pro_broken_links_running', 0 );
			if ( 0 === $is_running ) {
				break;
			}

			// Check batch limit.
			if ( $post_count >= $batch_size ) {
				break;
			}

			// Save progress.
			update_option( 'seopress_pro_broken_links_offset', $offset, false );

			// Heartbeat: record that a batch is actively making progress, so the
			// watchdog can tell a live scan from a stalled one. Refreshed per post
			// because a batch (each post triggers HTTP link checks) can be long.
			update_option( 'seopress_pro_broken_links_heartbeat', time(), false );

			update_option( 'seopress_pro_broken_links_log', get_the_title( $post_id ) . ' - ' . get_permalink( $post_id ), false );

			// Parse and check links for this post.
			seopress_broken_links_process_post( $post_id, $timeout, $where, $only_404, $scan_type );

			++$post_count;
			update_option( 'seopress_pro_broken_links_post_count', $post_count, false );
		}

		// Update offset for next batch.
		$new_offset = $offset + $cron_batch;
		update_option( 'seopress_pro_broken_links_offset', $new_offset, false );

		if ( 1 === (int) get_option( 'seopress_pro_broken_links_running', 1 ) && $post_count < $batch_size ) {
			$scheduled = wp_schedule_single_event( time() + 5, 'seopress_broken_links_run_task_cron', array( $new_offset ) );

			// If scheduling fails (a cron-table write error, a short-circuiting
			// pre_schedule_event filter, or an identical event already queued),
			// the scan would stall silently. Record the failure and log it when
			// debugging is on; the watchdog below re-drives the batch either way.
			if ( false === $scheduled ) {
				update_option( 'seopress_pro_broken_links_last_error', sprintf( 'Failed to schedule next batch at offset %1$d on %2$s', $new_offset, gmdate( 'c' ) ), false );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'SEOPress Broken Links: failed to schedule next batch at offset ' . $new_offset ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}

			// Keep the self-heal watchdog alive while the scan is running.
			seopress_broken_links_schedule_watchdog();
		} else {
			seopress_broken_links_finish_scan();
		}
	} else {
		seopress_broken_links_finish_scan();
	}
}
add_action( 'seopress_broken_links_run_task_cron', 'seopress_broken_links_run_task_fn', 10, 1 );

/**
 * Broken Links - Register the watchdog recurring interval (every 5 minutes).
 *
 * @param array $schedules Existing cron schedules.
 * @return array
 */
function seopress_broken_links_watchdog_interval( $schedules ) {
	if ( ! isset( $schedules['seopress_broken_links_watchdog_interval'] ) ) {
		$schedules['seopress_broken_links_watchdog_interval'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'SEOPress Broken Links watchdog (every 5 minutes)', 'wp-seopress-pro' ),
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'seopress_broken_links_watchdog_interval' );

/**
 * Broken Links - Ensure the self-heal watchdog is scheduled.
 *
 * @return void
 */
function seopress_broken_links_schedule_watchdog() {
	if ( ! wp_next_scheduled( 'seopress_broken_links_watchdog_cron' ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'seopress_broken_links_watchdog_interval', 'seopress_broken_links_watchdog_cron' );
	}
}

/**
 * Broken Links - Remove the self-heal watchdog and its heartbeat.
 *
 * @return void
 */
function seopress_broken_links_unschedule_watchdog() {
	wp_clear_scheduled_hook( 'seopress_broken_links_watchdog_cron' );
	delete_option( 'seopress_pro_broken_links_heartbeat' );
}

/**
 * Broken Links - Self-heal watchdog.
 *
 * A no-argument recurring event that re-drives a stalled scan. Because it takes
 * no arguments it also runs under `wp cron event run --due-now`, unlike the
 * offset-carrying batch event, which that command skips.
 *
 * The scan is treated as stalled when it is flagged as running but no batch has
 * refreshed the heartbeat within the staleness window. That single condition
 * covers every known failure mode: a request that died mid-batch before it could
 * reschedule (a hanging link check, a timeout, memory exhaustion), a failed
 * wp_schedule_single_event(), and setups where the offset-carrying batch event is
 * queued but never executed. In each case the next batch is driven directly from
 * the saved offset. A healthy scan keeps its heartbeat fresh, so the watchdog
 * leaves it alone and never runs a batch concurrently with a live one.
 *
 * @return void
 */
function seopress_broken_links_watchdog_fn() {
	// Only guard a scan that is supposed to be running.
	if ( 1 !== (int) get_option( 'seopress_pro_broken_links_running', 0 ) ) {
		seopress_broken_links_unschedule_watchdog();
		return;
	}

	// Leave a healthy scan alone: a live batch refreshes the heartbeat on every
	// processed post.
	$heartbeat   = (int) get_option( 'seopress_pro_broken_links_heartbeat', 0 );
	$stale_after = (int) apply_filters( 'seopress_broken_links_watchdog_stale_seconds', 3 * MINUTE_IN_SECONDS );
	if ( $heartbeat > 0 && ( time() - $heartbeat ) < $stale_after ) {
		return;
	}

	// Stalled: drop any leftover (possibly never-running) batch event and drive
	// the next batch directly from the saved offset. wp_unschedule_hook() is used
	// rather than wp_clear_scheduled_hook() because the batch events carry the
	// offset as an argument, which the latter would not match.
	wp_unschedule_hook( 'seopress_broken_links_run_task_cron' );
	seopress_broken_links_run_task_fn( (int) get_option( 'seopress_pro_broken_links_offset', 0 ) );
}
add_action( 'seopress_broken_links_watchdog_cron', 'seopress_broken_links_watchdog_fn' );

/**
 * Broken Links - Whether a host name resolves at all.
 *
 * @since 10.2.1
 *
 * @param string $host Host name to look up.
 *
 * @return bool
 */
function seopress_broken_links_host_resolves( $host ) {
	if ( gethostbyname( $host ) !== $host ) {
		return true;
	}

	// gethostbyname() only knows about A records, so an IPv6-only host answers
	// nothing here while being perfectly reachable. Reporting it as dead would
	// be a false positive, and this second lookup only ever runs on the handful
	// of links that failed the first one.
	if ( function_exists( 'checkdnsrr' ) ) {
		return (bool) checkdnsrr( $host, 'AAAA' );
	}

	return false;
}

/**
 * Broken Links - Decide what to do with a link before probing it.
 *
 * wp_http_validate_url() answers one question — may WordPress fetch this? — and
 * returns the same refusal for a host that resolves into a private range and
 * for a host that does not resolve at all. The scan needs those two apart: the
 * first must never be requested, the second is the single most common broken
 * link there is and is exactly what the report exists to show.
 *
 * @since 10.2.1
 *
 * @param string $href The link found in the content.
 *
 * @return string 'safe' to probe it, 'unresolved' for a host that does not
 *                exist, 'blocked' for a target WordPress refuses to fetch, or
 *                'invalid' for anything that is not an absolute HTTP(S) URL.
 */
function seopress_broken_links_classify_target( $href ) {
	if ( ! is_string( $href ) || '' === $href ) {
		return 'invalid';
	}

	$parsed = wp_parse_url( $href );

	if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
		return 'invalid';
	}

	if ( ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
		return 'invalid';
	}

	if ( wp_http_validate_url( $href ) ) {
		return 'safe';
	}

	// Core refused it. The only reason worth telling apart is a host that does
	// not resolve, and only for a host that is not the site's own: core lets
	// that one through whatever it resolves to, so a refusal there is about the
	// port or the credentials, never about DNS.
	$host        = trim( $parsed['host'], '.' );
	$parsed_home = wp_parse_url( home_url() );
	$same_host   = ! empty( $parsed_home['host'] ) && strtolower( $parsed_home['host'] ) === strtolower( $host );

	if ( ! $same_host && ! filter_var( $host, FILTER_VALIDATE_IP ) && ! seopress_broken_links_host_resolves( $host ) ) {
		return 'unresolved';
	}

	return 'blocked';
}

/**
 * Broken Links - Process a single post for broken links.
 *
 * @since 9.8.0
 *
 * @param int    $post_id   The post ID.
 * @param int    $timeout   HTTP request timeout.
 * @param string $where     Content source: 'post_content' or 'body_page'.
 * @param bool   $only_404  Only record 404 errors.
 * @param bool   $scan_type Whether to capture Content-Type header.
 *
 * @return void
 */
function seopress_broken_links_process_post( $post_id, $timeout, $where, $only_404, $scan_type ) {
	$dom                     = new \DOMDocument();
	$internal_errors         = libxml_use_internal_errors( true );
	$dom->preserveWhiteSpace = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	$http_args = array(
		'blocking'    => true,
		'timeout'     => $timeout,
		'sslverify'   => false,
		'compress'    => true,
		'redirection' => 4,
	);

	$http_args = apply_filters( 'seopress_bot_query_dom_args', $http_args );

	// Get content.
	if ( empty( $where ) || 'post_content' === $where ) {
		$content = get_post_field( 'post_content', $post_id );
	} else {
		$response = wp_remote_get( get_permalink( $post_id ), $http_args );
		if ( is_wp_error( $response ) || 404 === (int) wp_remote_retrieve_response_code( $response ) ) {
			libxml_use_internal_errors( $internal_errors );
			return;
		}
		$content = wp_remote_retrieve_body( $response );
	}

	if ( empty( $content ) || ! is_string( $content ) ) {
		libxml_use_internal_errors( $internal_errors );
		return;
	}

	if ( ! $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $content ) ) {
		libxml_use_internal_errors( $internal_errors );
		return;
	}

	$xpath = new \DOMXPath( $dom );
	$links = $xpath->query( '//a' );

	if ( empty( $links ) || 0 === $links->length ) {
		libxml_use_internal_errors( $internal_errors );
		return;
	}

	$checked_urls = array();

	foreach ( $links as $link ) {
		$href = $link->getAttribute( 'href' );
		$text = esc_attr( $link->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		// Skip anchors, empty, javascript, mailto.
		if ( empty( $href ) || '#' === $href[0] || 0 === strpos( $href, 'javascript:' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
			continue;
		}

		// Skip already checked URLs in this post.
		if ( isset( $checked_urls[ $href ] ) ) {
			continue;
		}
		$checked_urls[ $href ] = true;

		// Check link status.
		//
		// The href comes from post content, so anyone who can publish decides
		// what the server connects to: reject_unsafe_urls makes WordPress
		// validate the target (and every hop of the redirect chain) against
		// private and reserved ranges, so a link cannot turn the scan into a
		// probe of the internal network or of a cloud metadata endpoint.
		//
		// Certificate verification stays off: this only reads a status code,
		// sends no credential, and an expired certificate on a third-party site
		// is not what the broken links report is meant to flag.
		$link_args = array(
			'timeout'            => $timeout,
			'blocking'           => true,
			'sslverify'          => false,
			'compress'           => true,
			'redirection'        => 4,
			'reject_unsafe_urls' => true,
		);

		/**
		 * Filter the arguments used to probe a link found in the content.
		 *
		 * Set reject_unsafe_urls to false to let the scan reach hosts on a
		 * private range — needed on a staging site that links to internal
		 * hosts, at the cost of the protection described above.
		 *
		 * @since 10.2.0
		 *
		 * @param array  $link_args The wp_remote_get() arguments.
		 * @param string $href      The link being checked.
		 */
		$link_args = apply_filters( 'seopress_broken_links_request_args', $link_args, $href );

		// A host that no longer resolves is the most common broken link there
		// is, and reject_unsafe_urls refuses it exactly the way it refuses a
		// link into the internal network: the request never leaves, and the
		// WP_Error that comes back says nothing more than "A valid URL was not
		// provided". Classifying the target here keeps the two apart, so the
		// dead domain lands in the report and the internal host still does not.
		$link_response = null;
		$unreachable   = false;

		if ( ! empty( $link_args['reject_unsafe_urls'] ) ) {
			$target = seopress_broken_links_classify_target( $href );

			if ( 'blocked' === $target ) {
				continue;
			}

			$unreachable = 'unresolved' === $target;
		}

		if ( ! $unreachable ) {
			$link_response = wp_remote_get( $href, $link_args );

			// The cURL transport is the only one to phrase an unresolved host
			// this way; the streams one says "getaddrinfo failed". Both are only
			// reached when the pre-flight above was skipped through the filter.
			$unreachable = is_wp_error( $link_response )
				&& false !== strpos( $link_response->get_error_message(), 'cURL error 6' );
		}

		$bot_status_code = wp_remote_retrieve_response_code( $link_response );
		$bot_status_type = '';

		if ( ! $bot_status_code ) {
			$bot_status_code = __( 'domain not found', 'wp-seopress-pro' );
		}

		if ( $scan_type ) {
			$bot_status_type = wp_remote_retrieve_header( $link_response, 'content-type' );
		}

		// Filter 404 only if setting is enabled.
		//
		// The status code comes back from the HTTP API as an integer, so the
		// comparison has to be one too: '404' === $bot_status_code never matched
		// a real response, which left unreachable hosts as the only thing this
		// option ever let through.
		if ( $only_404 && 404 !== (int) $bot_status_code && ! $unreachable ) {
			continue;
		}

		// Check if link already exists.
		$check_page_id = seopress_pro_get_service( 'Redirection' )->getPageByTitle( $href, '', 'seopress_bot' );

		if ( is_bool( $check_page_id ) ) {
			wp_insert_post(
				array(
					'post_title'  => $href,
					'post_type'   => 'seopress_bot',
					'post_status' => 'publish',
					'meta_input'  => array(
						'seopress_bot_type'         => $bot_status_type,
						'seopress_bot_status'       => $bot_status_code,
						'seopress_bot_source_url'   => get_permalink( $post_id ),
						'seopress_bot_source_id'    => $post_id,
						'seopress_bot_cpt'          => get_post_type( $post_id ),
						'seopress_bot_source_title' => get_the_title( $post_id ),
						'seopress_bot_a_title'      => $text,
					),
				)
			);
		} elseif ( ! is_bool( $check_page_id ) && $check_page_id->post_title === $href ) {
			$seopress_bot_count = (int) get_post_meta( $check_page_id->ID, 'seopress_bot_count', true );
			update_post_meta( $check_page_id->ID, 'seopress_bot_count', ++$seopress_bot_count );
		}
	}

	libxml_use_internal_errors( $internal_errors );
}

/**
 * Broken Links - Finish scan and trigger email.
 *
 * @since 9.8.0
 *
 * @return void
 */
function seopress_broken_links_finish_scan() {
	update_option( 'seopress_pro_broken_links_running', 0, false );

	// The scan is complete; stop the self-heal watchdog.
	seopress_broken_links_unschedule_watchdog();

	$start_time = get_option( 'seopress_pro_broken_links_last_scan' );
	if ( $start_time ) {
		update_option( 'seopress_pro_broken_links_scan_duration', time() - $start_time, false );
	}

	update_option( 'seopress_bot_log', current_time( 'Y-m-d H:i' ), false );

	do_action( 'seopress_broken_links_after_scan' );
}

/**
 * Broken Links - Email notification after scan completes.
 *
 * @since 9.8.0
 *
 * @return void
 */
function seopress_broken_links_send_email() {
	$to = seopress_pro_get_service( 'OptionBot' )->getBotScanSettingsEmail();
	if ( empty( $to ) ) {
		return;
	}

	/* translators: %s site name from General settings */
	$subject       = sprintf( __( 'Broken links scan completed - %s', 'wp-seopress-pro' ), get_bloginfo( 'name' ) );
	$scan_duration = (int) get_option( 'seopress_pro_broken_links_scan_duration', 0 );

	$scan_duration_minutes = floor( $scan_duration / 60 );
	$scan_duration_seconds = $scan_duration % 60;

	// Count broken links found.
	$broken_count = wp_count_posts( 'seopress_bot' );
	$total_links  = isset( $broken_count->publish ) ? (int) $broken_count->publish : 0;

	$content  = '<p>' . esc_html__( 'We have finished scanning your site for broken links.', 'wp-seopress-pro' ) . '</p>';
	$content .= '<p>' . sprintf(
		/* translators: %1$s duration in minutes, %2$s duration in seconds */
		wp_kses_post( __( '<strong>Scan duration:</strong> %1$s minutes %2$s seconds', 'wp-seopress-pro' ) ),
		esc_html( $scan_duration_minutes ),
		esc_html( $scan_duration_seconds )
	) . '</p>';
	$content .= '<p>' . sprintf(
		/* translators: %d number of broken links found */
		wp_kses_post( __( '<strong>Broken links found:</strong> %d', 'wp-seopress-pro' ) ),
		$total_links
	) . '</p>';
	$content .= '<p>' . esc_html__( 'Find all the details by clicking on the following link:', 'wp-seopress-pro' ) . '</p>';
	$content .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=seopress-broken-links' ) ) . '">' . esc_html__( 'View broken links', 'wp-seopress-pro' ) . '</a></p>';

	$email_service = \SEOPress\Services\Email\EmailService::get_instance();

	$email_args = array(
		'header_subject' => $subject,
		'footer_text'    => sprintf(
			/* translators: %s site name */
			esc_html__( 'Sent from %s', 'wp-seopress-pro' ),
			get_bloginfo( 'name' )
		),
		'text_color'     => '#505050',
		'bg_color'       => '#F9F9F9',
	);

	$email_service->send( $to, $subject, $content, $email_args );
}
add_action( 'seopress_broken_links_after_scan', 'seopress_broken_links_send_email' );

/**
 * Broken Links - Cancel scan task.
 *
 * @since 9.8.0
 *
 * @return void
 */
function seopress_broken_links_cancel_task() {
	update_option( 'seopress_pro_broken_links_running', 0, false );

	// wp_unschedule_hook() (not wp_clear_scheduled_hook()) so the offset-carrying
	// batch events are actually removed; otherwise a leftover event would fire
	// after the cancel. Also stop the self-heal watchdog.
	wp_unschedule_hook( 'seopress_broken_links_run_task_cron' );
	seopress_broken_links_unschedule_watchdog();
}

///////////////////////////////////////////////////////////////////////////////////////////////////
// Weekly License Validation CRON
///////////////////////////////////////////////////////////////////////////////////////////////////
/**
 * Validate PRO license with remote EDD API.
 *
 * This cron runs weekly to ensure license status stays up-to-date,
 * particularly to detect expired licenses that wouldn't be caught otherwise.
 *
 * @since 9.6.0
 *
 * @return void
 */
function seopress_license_validation_cron() {
	// Get the license key.
	$license = defined( 'SEOPRESS_LICENSE_KEY' ) && ! empty( SEOPRESS_LICENSE_KEY ) && is_string( SEOPRESS_LICENSE_KEY )
		? SEOPRESS_LICENSE_KEY
		: trim( (string) get_option( 'seopress_pro_license_key' ) );

	if ( empty( $license ) ) {
		return;
	}

	// Data to send to the API.
	$api_params = array(
		'edd_action'  => 'check_license',
		'license'     => $license,
		'item_id'     => ITEM_ID_SEOPRESS,
		'url'         => get_option( 'home' ),
		'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
	);

	// Call the EDD API.
	$response = wp_remote_post(
		STORE_URL_SEOPRESS,
		array(
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ),
			'timeout'    => 15,
			'body'       => $api_params,
		)
	);

	// Make sure the response is valid.
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return; // Fail silently, try again next week.
	}

	$license_data = json_decode( wp_remote_retrieve_body( $response ) );

	if ( ! $license_data || ! isset( $license_data->license ) ) {
		return;
	}

	// Update license status if it has changed.
	$current_status = get_option( 'seopress_pro_license_status' );
	if ( $current_status !== $license_data->license ) {
		update_option( 'seopress_pro_license_status', $license_data->license );
	}

	// Store the expiry timestamp for condition evaluation.
	if ( isset( $license_data->expires ) && 'lifetime' !== $license_data->expires ) {
		$expiry_timestamp = strtotime( $license_data->expires );
		update_option( 'seopress_pro_license_expiry', $expiry_timestamp, false );
	} elseif ( isset( $license_data->expires ) && 'lifetime' === $license_data->expires ) {
		// Lifetime license - set expiry far in the future.
		update_option( 'seopress_pro_license_expiry', strtotime( '+100 years' ), false );
	}

	// Store last check timestamp.
	update_option( 'seopress_pro_license_last_check', time(), false );
}
add_action( 'seopress_license_validation_cron', 'seopress_license_validation_cron' );

/**
 * Schedule the weekly license validation cron on plugin activation.
 *
 * @since 9.6.0
 *
 * @return void
 */
function seopress_schedule_license_validation_cron() {
	if ( ! wp_next_scheduled( 'seopress_license_validation_cron' ) ) {
		wp_schedule_event( time(), 'weekly', 'seopress_license_validation_cron' );
	}
}
add_action( 'admin_init', 'seopress_schedule_license_validation_cron' );

/**
 * One-time license check for existing users without stored expiry.
 *
 * For users who activated their license before this feature was added,
 * we need to fetch and store the expiry date on first admin load.
 *
 * @since 9.6.0
 *
 * @return void
 */
function seopress_migrate_license_expiry() {
	// Only run if license is "valid" but no expiry is stored.
	$license_status = get_option( 'seopress_pro_license_status' );
	$license_expiry = get_option( 'seopress_pro_license_expiry' );

	if ( 'valid' !== $license_status || ! empty( $license_expiry ) ) {
		return;
	}

	// Run the validation to populate expiry.
	seopress_license_validation_cron();
}
add_action( 'admin_init', 'seopress_migrate_license_expiry' );
