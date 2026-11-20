<?php
/**
 * Export Repository
 *
 * Handles database queries for export functionality.
 *
 * @package DirectoristAnalytics
 * @subpackage Repositories
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Export Repository Class
 *
 * @since 1.0.0
 */
class ExportRepository {

	/**
     * Get site-wide aggregated data
     *
     * @since 1.0.0
     * @param string $start_date Start date (YYYY-MM-DD).
     * @param string $end_date   End date (YYYY-MM-DD).
     * @param string $interval   Interval (daily, weekly, monthly, quarterly).
     * @param array  $metrics    Selected metrics.
     * @return array
     */
    public function get_site_wide_data( $start_date, $end_date, $interval, $metrics ) {
        $result = array();

        // Determine which data sources we need
        $need_daily_stats = $this->needs_daily_stats( $metrics );
        $need_country     = in_array( 'topCountries', $metrics );
        $need_tech        = $this->needs_tech_stats( $metrics );
        $need_search_eng  = in_array( 'searchEngines', $metrics );
        $need_referral    = in_array( 'referrals', $metrics );

        // Fetch data sources (only what we need)
        $daily_stats   = $need_daily_stats ? $this->fetch_daily_stats_data( $start_date, $end_date ) : array();
        $country_data  = $need_country ? $this->fetch_country_data( $start_date, $end_date ) : array();
        $tech_data     = $need_tech ? $this->fetch_tech_data( $start_date, $end_date ) : array();
        $search_data   = $need_search_eng ? $this->fetch_search_engine_data( $start_date, $end_date ) : array();
        $referral_data = $need_referral ? $this->fetch_referral_data( $start_date, $end_date ) : array();

        // Apply interval grouping
        if ( $interval !== 'daily' ) {
            if ( ! empty( $daily_stats ) ) {
                $daily_stats = $this->group_by_interval( $daily_stats, $interval );
            }
            if ( ! empty( $country_data ) ) {
                $country_data = $this->group_country_by_interval( $country_data, $interval );
            }
            if ( ! empty( $tech_data ) ) {
                $tech_data = $this->group_tech_by_interval( $tech_data, $interval );
            }
            if ( ! empty( $search_data ) ) {
                $search_data = $this->group_search_by_interval( $search_data, $interval );
            }
            if ( ! empty( $referral_data ) ) {
                $referral_data = $this->group_referral_by_interval( $referral_data, $interval );
            }
        }

        // Build response for each requested metric
        foreach ( $metrics as $metric ) {
            switch ( $metric ) {
                // Daily stats metrics
                case 'listingViews':
                    $result['listingViews'] = $this->format_metric_data( $daily_stats, 'total_views', $interval );
                    break;

                case 'clicks':
                    $result['clicks'] = $this->format_metric_data( $daily_stats, 'total_clicks', $interval );
                    break;

                case 'websiteClicks':
                    $result['websiteClicks'] = $this->format_metric_data( $daily_stats, 'total_website_clicks', $interval );
                    break;

                case 'searchAppearanceData':
                    $result['searchAppearanceData'] = $this->format_metric_data( $daily_stats, 'total_searches', $interval );
                    break;

                case 'contactFormUsesData':
                    $result['contactFormUsesData'] = $this->format_metric_data( $daily_stats, 'total_contact_form', $interval );
                    break;

                // Country data
                case 'topCountries':
                    $result['topCountries'] = $this->format_country_data( $country_data, $interval );
                    break;

                // Tech stats
                case 'browserType':
                    $result['browserType'] = $this->format_tech_data( $tech_data, 'browser', $interval );
                    break;

                case 'operatingSystems':
                    $result['operatingSystems'] = $this->format_tech_data( $tech_data, 'os', $interval );
                    break;

                case 'screenResolutions':
                    $result['screenResolutions'] = $this->format_tech_data( $tech_data, 'screen_resolution', $interval );
                    break;

                // Search engines
                case 'searchEngines':
                    $result['searchEngines'] = $this->format_search_engine_data( $search_data, $interval );
                    break;

                // Referrals
                case 'referrals':
                    $result['referrals'] = $this->format_referral_data( $referral_data, $interval );
                    break;
            }
        }

        return $result;
    }

    /**
     * Check if we need daily stats data
     *
     * @since 1.0.0
     * @param array $metrics Selected metrics.
     * @return bool
     */
    private function needs_daily_stats( $metrics ) {
        $daily_metrics = array( 'listingViews', 'clicks', 'websiteClicks', 'searchAppearanceData', 'contactFormUsesData' );
        return count( array_intersect( $metrics, $daily_metrics ) ) > 0;
    }

    /**
     * Check if we need tech stats data
     *
     * @since 1.0.0
     * @param array $metrics Selected metrics.
     * @return bool
     */
    private function needs_tech_stats( $metrics ) {
        $tech_metrics = array( 'browserType', 'operatingSystems', 'screenResolutions' );
        return count( array_intersect( $metrics, $tech_metrics ) ) > 0;
    }

	/**
     * Get per-listing data
     *
     * @since 1.0.0
     * @param string $start_date Start date (YYYY-MM-DD).
     * @param string $end_date   End date (YYYY-MM-DD).
     * @param string $interval   Interval (daily, weekly, monthly, quarterly).
     * @param array  $metrics    Selected metrics.
     * @return array
     */
    public function get_per_listing_data( $start_date, $end_date, $interval, $metrics ) {
        // Only daily stats metrics are supported for per-listing
        // (Country/tech/search/referral stats are site-wide only)
        $daily_metrics_only = array_intersect( 
            $metrics, 
            array( 'listingViews', 'clicks', 'websiteClicks', 'searchAppearanceData', 'contactFormUsesData' ) 
        );

        if ( empty( $daily_metrics_only ) ) {
            return array(); // No valid metrics for per-listing
        }

        // Fetch per-listing daily stats
        $raw_data = $this->fetch_per_listing_stats( $start_date, $end_date );

        // Group by listing first
        $grouped_by_listing = $this->group_by_listing( $raw_data );

        // Apply interval grouping to each listing's data
        if ( $interval !== 'daily' ) {
            foreach ( $grouped_by_listing as $listing_id => &$listing_data ) {
                $listing_data['daily_data'] = $this->group_by_interval( $listing_data['daily_data'], $interval );
            }
            unset( $listing_data );
        }

        // Format into response structure
        $listings = array();
        foreach ( $grouped_by_listing as $listing_id => $listing_data ) {
            $listing_item = array(
                'id'          => absint( $listing_id ),
                'name'        => $listing_data['name'],
                'author_id'   => absint( $listing_data['author_id'] ),
                'author_name' => $listing_data['author_name'],
                'metrics'     => array(),
            );

            // Add requested metrics
            foreach ( $daily_metrics_only as $metric ) {
                switch ( $metric ) {
                    case 'listingViews':
                        $listing_item['metrics']['listingViews'] = $this->format_metric_data( $listing_data['daily_data'], 'total_views', $interval );
                        break;

                    case 'clicks':
                        $listing_item['metrics']['clicks'] = $this->format_metric_data( $listing_data['daily_data'], 'total_clicks', $interval );
                        break;

                    case 'websiteClicks':
                        $listing_item['metrics']['websiteClicks'] = $this->format_metric_data( $listing_data['daily_data'], 'total_website_clicks', $interval );
                        break;

                    case 'searchAppearanceData':
                        $listing_item['metrics']['searchAppearanceData'] = $this->format_metric_data( $listing_data['daily_data'], 'total_searches', $interval );
                        break;

                    case 'contactFormUsesData':
                        $listing_item['metrics']['contactFormUsesData'] = $this->format_metric_data( $listing_data['daily_data'], 'total_contact_form', $interval );
                        break;
                }
            }

            $listings[] = $listing_item;
        }

        return $listings;
    }

    /**
     * Fetch per-listing stats from database
     *
     * @since 1.0.0
     * @param string $start_date Start date (YYYY-MM-DD).
     * @param string $end_date   End date (YYYY-MM-DD).
     * @return array
     */
    private function fetch_per_listing_stats( $start_date, $end_date ) {
        global $wpdb;

        $stats_table = $wpdb->prefix . 'directorist_analytics_daily_stats';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    p.ID as listing_id,
                    p.post_title as listing_name,
                    p.post_author as author_id,
                    u.display_name as author_name,
                    ds.stat_date,
                    COALESCE(ds.views, 0) as total_views,
                    COALESCE(ds.clicks_total, 0) as total_clicks,
                    COALESCE(ds.clicks_website, 0) as total_website_clicks,
                    COALESCE(ds.search_appearances, 0) as total_searches,
                    COALESCE(ds.clicks_contact_form, 0) as total_contact_form
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->users} u ON p.post_author = u.ID
                LEFT JOIN {$stats_table} ds 
                    ON p.ID = ds.listing_id 
                    AND ds.stat_date BETWEEN %s AND %s
                WHERE p.post_type = %s
                    AND p.post_status = 'publish'
                ORDER BY p.ID, ds.stat_date ASC",
                $start_date,
                $end_date,
                'at_biz_dir'
            )
        );

        return $results ? $results : array();
    }

    /**
     * Group raw data by listing
     *
     * @since 1.0.0
     * @param array $raw_data Raw query results.
     * @return array Grouped by listing_id.
     */
    private function group_by_listing( $raw_data ) {
        $grouped = array();

        foreach ( $raw_data as $row ) {
            $listing_id = $row->listing_id;

            // Initialize listing if not exists
            if ( ! isset( $grouped[ $listing_id ] ) ) {
                $grouped[ $listing_id ] = array(
                    'name'        => $row->listing_name,
                    'author_id'   => $row->author_id,
                    'author_name' => $row->author_name,
                    'daily_data'  => array(),
                );
            }

            // Add daily data (skip if no stat_date - means no data in this range)
            if ( ! empty( $row->stat_date ) ) {
                $grouped[ $listing_id ]['daily_data'][] = (object) array(
                    'stat_date'              => $row->stat_date,
                    'total_views'            => absint( $row->total_views ),
                    'total_clicks'           => absint( $row->total_clicks ),
                    'total_website_clicks'   => absint( $row->total_website_clicks ),
                    'total_searches'         => absint( $row->total_searches ),
                    'total_contact_form'     => absint( $row->total_contact_form ),
                );
            }
        }

        return $grouped;
    }

	/**
     * Get per-author data
     *
     * @since 1.0.0
     * @param string $start_date Start date (YYYY-MM-DD).
     * @param string $end_date   End date (YYYY-MM-DD).
     * @param string $interval   Interval (daily, weekly, monthly, quarterly).
     * @param array  $metrics    Selected metrics.
     * @return array
     */
    public function get_per_author_data( $start_date, $end_date, $interval, $metrics ) {
        // Only daily stats metrics are supported for per-author
        $daily_metrics_only = array_intersect( 
            $metrics, 
            array( 'listingViews', 'clicks', 'websiteClicks', 'searchAppearanceData', 'contactFormUsesData' ) 
        );

        if ( empty( $daily_metrics_only ) ) {
            return array(); // No valid metrics for per-author
        }

        // Fetch per-author aggregated stats
        $raw_data = $this->fetch_per_author_stats( $start_date, $end_date );

        // Group by author first
        $grouped_by_author = $this->group_by_author( $raw_data );

        // Apply interval grouping to each author's data
        if ( $interval !== 'daily' ) {
            foreach ( $grouped_by_author as $author_id => &$author_data ) {
                $author_data['daily_data'] = $this->group_by_interval( $author_data['daily_data'], $interval );
            }
            unset( $author_data );
        }

        // Format into response structure
        $authors = array();
        foreach ( $grouped_by_author as $author_id => $author_data ) {
            $author_item = array(
                'id'            => absint( $author_id ),
                'name'          => $author_data['name'],
                'email'         => $author_data['email'],
                'listing_count' => absint( $author_data['listing_count'] ),
                'metrics'       => array(),
            );

            // Add requested metrics
            foreach ( $daily_metrics_only as $metric ) {
                switch ( $metric ) {
                    case 'listingViews':
                        $author_item['metrics']['listingViews'] = $this->format_metric_data( $author_data['daily_data'], 'total_views', $interval );
                        break;

                    case 'clicks':
                        $author_item['metrics']['clicks'] = $this->format_metric_data( $author_data['daily_data'], 'total_clicks', $interval );
                        break;

                    case 'websiteClicks':
                        $author_item['metrics']['websiteClicks'] = $this->format_metric_data( $author_data['daily_data'], 'total_website_clicks', $interval );
                        break;

                    case 'searchAppearanceData':
                        $author_item['metrics']['searchAppearanceData'] = $this->format_metric_data( $author_data['daily_data'], 'total_searches', $interval );
                        break;

                    case 'contactFormUsesData':
                        $author_item['metrics']['contactFormUsesData'] = $this->format_metric_data( $author_data['daily_data'], 'total_contact_form', $interval );
                        break;
                }
            }

            $authors[] = $author_item;
        }

        return $authors;
    }

    /**
     * Fetch per-author stats from database
     *
     * @since 1.0.0
     * @param string $start_date Start date (YYYY-MM-DD).
     * @param string $end_date   End date (YYYY-MM-DD).
     * @return array
     */
    private function fetch_per_author_stats( $start_date, $end_date ) {
        global $wpdb;

        $stats_table = $wpdb->prefix . 'directorist_analytics_daily_stats';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    p.post_author as author_id,
                    u.display_name as author_name,
                    u.user_email as author_email,
                    COUNT(DISTINCT p.ID) as listing_count,
                    ds.stat_date,
                    SUM(COALESCE(ds.views, 0)) as total_views,
                    SUM(COALESCE(ds.clicks_total, 0)) as total_clicks,
                    SUM(COALESCE(ds.clicks_website, 0)) as total_website_clicks,
                    SUM(COALESCE(ds.search_appearances, 0)) as total_searches,
                    SUM(COALESCE(ds.clicks_contact_form, 0)) as total_contact_form
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->users} u ON p.post_author = u.ID
                LEFT JOIN {$stats_table} ds 
                    ON p.ID = ds.listing_id 
                    AND ds.stat_date BETWEEN %s AND %s
                WHERE p.post_type = %s
                    AND p.post_status = 'publish'
                GROUP BY p.post_author, u.display_name, u.user_email, ds.stat_date
                ORDER BY p.post_author, ds.stat_date ASC",
                $start_date,
                $end_date,
                'at_biz_dir'
            )
        );

        return $results ? $results : array();
    }

    /**
     * Group raw data by author
     *
     * @since 1.0.0
     * @param array $raw_data Raw query results.
     * @return array Grouped by author_id.
     */
    private function group_by_author( $raw_data ) {
        $grouped = array();

        foreach ( $raw_data as $row ) {
            $author_id = $row->author_id;

            // Initialize author if not exists
            if ( ! isset( $grouped[ $author_id ] ) ) {
                $grouped[ $author_id ] = array(
                    'name'          => $row->author_name,
                    'email'         => $row->author_email,
                    'listing_count' => $row->listing_count,
                    'daily_data'    => array(),
                );
            }

            // Add daily data (skip if no stat_date - means no data in this range)
            if ( ! empty( $row->stat_date ) ) {
                $grouped[ $author_id ]['daily_data'][] = (object) array(
                    'stat_date'              => $row->stat_date,
                    'total_views'            => absint( $row->total_views ),
                    'total_clicks'           => absint( $row->total_clicks ),
                    'total_website_clicks'   => absint( $row->total_website_clicks ),
                    'total_searches'         => absint( $row->total_searches ),
                    'total_contact_form'     => absint( $row->total_contact_form ),
                );
            }
        }

        return $grouped;
    }

	/**
	 * Fetch daily stats data from database
	 *
	 * @since 1.0.0
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @return array
	 */
	private function fetch_daily_stats_data( $start_date, $end_date ) {
		global $wpdb;

		$table = $wpdb->prefix . 'directorist_analytics_daily_stats';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
                    stat_date,
                    SUM(views) as total_views,
                    SUM(clicks_total) as total_clicks,
                    SUM(clicks_website) as total_website_clicks,
                    SUM(search_appearances) as total_searches,
                    SUM(clicks_contact_form) as total_contact_form
                FROM {$table}
				WHERE stat_date BETWEEN %s AND %s
				GROUP BY stat_date
				ORDER BY stat_date ASC",
				$start_date,
				$end_date
			)
		);

		return $results ? $results : array();
	}

    /**
     * Fetch country data from database
     *
     * @since 1.0.0
     * @param string $start_date Start date (YYYY-MM-DD).
     * @param string $end_date   End date (YYYY-MM-DD).
     * @return array
     */
    private function fetch_country_data( $start_date, $end_date ) {
        global $wpdb;

        $table = $wpdb->prefix . 'directorist_analytics_country_stats_daily';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    stat_date,
                    country_code,
                    country_name,
                    SUM(count) as visitors
                FROM {$table}
                WHERE stat_date BETWEEN %s AND %s
                GROUP BY stat_date, country_code, country_name
                ORDER BY stat_date ASC, visitors DESC",
                $start_date,
                $end_date
            )
        );

        return $results ? $results : array();
    }

    /**
     * Fetch tech stats data from database
     *
     * @since 1.0.0
     * @param string $start_date Start date (YYYY-MM-DD).
     * @param string $end_date   End date (YYYY-MM-DD).
     * @return array
     */
    private function fetch_tech_data( $start_date, $end_date ) {
        global $wpdb;

        $table = $wpdb->prefix . 'directorist_analytics_tech_stats_daily';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    stat_date,
                    browser,
                    os,
                    screen_resolution,
                    SUM(count) as total_count
                FROM {$table}
                WHERE stat_date BETWEEN %s AND %s
                GROUP BY stat_date, browser, os, screen_resolution
                ORDER BY stat_date ASC, total_count DESC",
                $start_date,
                $end_date
            )
        );

        return $results ? $results : array();
    }

    /**
     * Fetch search engine data from database
     *
     * @since 1.0.0
     * @param string $start_date Start date (YYYY-MM-DD).
     * @param string $end_date   End date (YYYY-MM-DD).
     * @return array
     */
    private function fetch_search_engine_data( $start_date, $end_date ) {
        global $wpdb;

        $table = $wpdb->prefix . 'directorist_analytics_search_engine_stats_daily';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    stat_date,
                    search_engine,
                    SUM(count) as visitors
                FROM {$table}
                WHERE stat_date BETWEEN %s AND %s
                GROUP BY stat_date, search_engine
                ORDER BY stat_date ASC, visitors DESC",
                $start_date,
                $end_date
            )
        );

        return $results ? $results : array();
    }

    /**
     * Fetch referral data from database
     *
     * @since 1.0.0
     * @param string $start_date Start date (YYYY-MM-DD).
     * @param string $end_date   End date (YYYY-MM-DD).
     * @return array
     */
    private function fetch_referral_data( $start_date, $end_date ) {
        global $wpdb;

        $table = $wpdb->prefix . 'directorist_analytics_referral_stats_daily';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    stat_date,
                    referrer_domain,
                    referrer_type,
                    SUM(count) as visitors
                FROM {$table}
                WHERE stat_date BETWEEN %s AND %s
                GROUP BY stat_date, referrer_domain, referrer_type
                ORDER BY stat_date ASC, visitors DESC",
                $start_date,
                $end_date
            )
        );

        return $results ? $results : array();
    }

	private function format_metric_data( $data, $metric_key, $interval = 'daily' ) {
        $formatted = array();
    
        foreach ( $data as $row ) {
            $item = array(
                'period' => $row->stat_date,
                'value'  => absint( $row->$metric_key ),
            );
    
            // Add human-readable label for non-daily intervals
            if ( $interval !== 'daily' ) {
                $item['period_label'] = $this->get_period_label( $row->stat_date, $interval );
            }
    
            $formatted[] = $item;
        }
    
        return $formatted;
    }

    /**
     * Format country data for response
     *
     * @since 1.0.0
     * @param array  $data     Raw data from query.
     * @param string $interval Interval type.
     * @return array
     */
    private function format_country_data( $data, $interval ) {
        $formatted = array();

        foreach ( $data as $row ) {
            $item = array(
                'period'       => $row->stat_date,
                'country_code' => $row->country_code,
                'country_name' => $row->country_name,
                'visitors'     => absint( $row->visitors ),
            );

            if ( $interval !== 'daily' ) {
                $item['period_label'] = $this->get_period_label( $row->stat_date, $interval );
            }

            $formatted[] = $item;
        }

        return $formatted;
    }

    /**
     * Format tech data for response
     *
     * @since 1.0.0
     * @param array  $data      Raw data from query.
     * @param string $tech_type Tech type (browser, os, screen_resolution).
     * @param string $interval  Interval type.
     * @return array
     */
    private function format_tech_data( $data, $tech_type, $interval ) {
        $formatted = array();

        foreach ( $data as $row ) {
            // Skip if the specific tech field is empty
            if ( empty( $row->$tech_type ) ) {
                continue;
            }

            $item = array(
                'period' => $row->stat_date,
                'name'   => $row->$tech_type,
                'count'  => absint( $row->total_count ),
            );

            if ( $interval !== 'daily' ) {
                $item['period_label'] = $this->get_period_label( $row->stat_date, $interval );
            }

            $formatted[] = $item;
        }

        return $formatted;
    }

    /**
     * Format search engine data for response
     *
     * @since 1.0.0
     * @param array  $data     Raw data from query.
     * @param string $interval Interval type.
     * @return array
     */
    private function format_search_engine_data( $data, $interval ) {
        $formatted = array();

        foreach ( $data as $row ) {
            $item = array(
                'period'   => $row->stat_date,
                'engine'   => $row->search_engine,
                'visitors' => absint( $row->visitors ),
            );

            if ( $interval !== 'daily' ) {
                $item['period_label'] = $this->get_period_label( $row->stat_date, $interval );
            }

            $formatted[] = $item;
        }

        return $formatted;
    }

    /**
     * Format referral data for response
     *
     * @since 1.0.0
     * @param array  $data     Raw data from query.
     * @param string $interval Interval type.
     * @return array
     */
    private function format_referral_data( $data, $interval ) {
        $formatted = array();

        foreach ( $data as $row ) {
            $item = array(
                'period'   => $row->stat_date,
                'domain'   => $row->referrer_domain,
                'type'     => $row->referrer_type,
                'visitors' => absint( $row->visitors ),
            );

            if ( $interval !== 'daily' ) {
                $item['period_label'] = $this->get_period_label( $row->stat_date, $interval );
            }

            $formatted[] = $item;
        }

        return $formatted;
    }
    
    /**
     * Get human-readable period label
     *
     * @since 1.0.0
     * @param string $period   Period string (e.g., "2025-W44").
     * @param string $interval Interval type.
     * @return string Human-readable label.
     */
    private function get_period_label( $period, $interval ) {
        switch ( $interval ) {
            case 'weekly':
                // Extract year and week number from "2025-W44"
                if ( preg_match( '/(\d{4})-W(\d{2})/', $period, $matches ) ) {
                    return sprintf( 'Week %d, %s', intval( $matches[2] ), $matches[1] );
                }
                return $period;
    
            case 'monthly':
                // Convert "2025-11" to "November 2025"
                $timestamp = strtotime( $period . '-01' );
                return gmdate( 'F Y', $timestamp );
    
            case 'quarterly':
                // Convert "2025-Q4" to "Q4 2025"
                if ( preg_match( '/(\d{4})-Q(\d)/', $period, $matches ) ) {
                    return sprintf( 'Q%d %s', $matches[2], $matches[1] );
                }
                return $period;
    
            default:
                return $period;
        }
    }

    /**
     * Group daily data by interval
     *
     * @since 1.0.0
     * @param array  $daily_data Daily data array.
     * @param string $interval   Interval (weekly, monthly, quarterly).
     * @return array Grouped data.
     */
    private function group_by_interval( $daily_data, $interval ) {
        $grouped = array();

        foreach ( $daily_data as $row ) {
            $date = $row->stat_date;
            
            // Determine period based on interval
            switch ( $interval ) {
                case 'weekly':
                    $period = $this->get_week_period( $date );
                    break;
                
                case 'monthly':
                    $period = $this->get_month_period( $date );
                    break;
                
                case 'quarterly':
                    $period = $this->get_quarter_period( $date );
                    break;
                
                default:
                    $period = $date; // Fallback to daily
            }

            // Initialize period if not exists
            if ( ! isset( $grouped[ $period ] ) ) {
                $grouped[ $period ] = (object) array(
                    'stat_date'              => $period,
                    'total_views'            => 0,
                    'total_clicks'           => 0,
                    'total_website_clicks'   => 0,
                    'total_searches'         => 0,
                    'total_contact_form'     => 0,
                );
            }

            // Sum values
            $grouped[ $period ]->total_views          += absint( $row->total_views );
            $grouped[ $period ]->total_clicks         += absint( $row->total_clicks );
            $grouped[ $period ]->total_website_clicks += absint( $row->total_website_clicks );
            $grouped[ $period ]->total_searches       += absint( $row->total_searches ?? 0 );
            $grouped[ $period ]->total_contact_form   += absint( $row->total_contact_form ?? 0 );
        }

        // Convert back to array (sorted by period)
        ksort( $grouped );
        return array_values( $grouped );
    }

    /**
     * Group country data by interval
     *
     * @since 1.0.0
     * @param array  $data     Country data array.
     * @param string $interval Interval.
     * @return array
     */
    private function group_country_by_interval( $data, $interval ) {
        $grouped = array();

        foreach ( $data as $row ) {
            $period = $this->get_period_for_interval( $row->stat_date, $interval );
            $key    = $period . '_' . $row->country_code;

            if ( ! isset( $grouped[ $key ] ) ) {
                $grouped[ $key ] = (object) array(
                    'stat_date'    => $period,
                    'country_code' => $row->country_code,
                    'country_name' => $row->country_name,
                    'visitors'     => 0,
                );
            }

            $grouped[ $key ]->visitors += absint( $row->visitors );
        }

        ksort( $grouped );
        return array_values( $grouped );
    }

    /**
     * Group tech data by interval
     *
     * @since 1.0.0
     * @param array  $data     Tech data array.
     * @param string $interval Interval.
     * @return array
     */
    private function group_tech_by_interval( $data, $interval ) {
        $grouped = array();

        foreach ( $data as $row ) {
            $period = $this->get_period_for_interval( $row->stat_date, $interval );
            $key    = $period . '_' . $row->browser . '_' . $row->os . '_' . $row->screen_resolution;

            if ( ! isset( $grouped[ $key ] ) ) {
                $grouped[ $key ] = (object) array(
                    'stat_date'         => $period,
                    'browser'           => $row->browser,
                    'os'                => $row->os,
                    'screen_resolution' => $row->screen_resolution,
                    'total_count'       => 0,
                );
            }

            $grouped[ $key ]->total_count += absint( $row->total_count );
        }

        ksort( $grouped );
        return array_values( $grouped );
    }

    /**
     * Group search engine data by interval
     *
     * @since 1.0.0
     * @param array  $data     Search engine data array.
     * @param string $interval Interval.
     * @return array
     */
    private function group_search_by_interval( $data, $interval ) {
        $grouped = array();

        foreach ( $data as $row ) {
            $period = $this->get_period_for_interval( $row->stat_date, $interval );
            $key    = $period . '_' . $row->search_engine;

            if ( ! isset( $grouped[ $key ] ) ) {
                $grouped[ $key ] = (object) array(
                    'stat_date'     => $period,
                    'search_engine' => $row->search_engine,
                    'visitors'      => 0,
                );
            }

            $grouped[ $key ]->visitors += absint( $row->visitors );
        }

        ksort( $grouped );
        return array_values( $grouped );
    }

    /**
     * Group referral data by interval
     *
     * @since 1.0.0
     * @param array  $data     Referral data array.
     * @param string $interval Interval.
     * @return array
     */
    private function group_referral_by_interval( $data, $interval ) {
        $grouped = array();

        foreach ( $data as $row ) {
            $period = $this->get_period_for_interval( $row->stat_date, $interval );
            $key    = $period . '_' . $row->referrer_domain . '_' . $row->referrer_type;

            if ( ! isset( $grouped[ $key ] ) ) {
                $grouped[ $key ] = (object) array(
                    'stat_date'       => $period,
                    'referrer_domain' => $row->referrer_domain,
                    'referrer_type'   => $row->referrer_type,
                    'visitors'        => 0,
                );
            }

            $grouped[ $key ]->visitors += absint( $row->visitors );
        }

        ksort( $grouped );
        return array_values( $grouped );
    }

    /**
     * Helper to get period for interval
     *
     * @since 1.0.0
     * @param string $date     Date (YYYY-MM-DD).
     * @param string $interval Interval.
     * @return string Period.
     */
    private function get_period_for_interval( $date, $interval ) {
        switch ( $interval ) {
            case 'weekly':
                return $this->get_week_period( $date );
            case 'monthly':
                return $this->get_month_period( $date );
            case 'quarterly':
                return $this->get_quarter_period( $date );
            default:
                return $date;
        }
    }

    /**
     * Get ISO week period from date
     *
     * @since 1.0.0
     * @param string $date Date (YYYY-MM-DD).
     * @return string Week period (YYYY-Wxx).
     */
    private function get_week_period( $date ) {
        return gmdate( 'o-\WW', strtotime( $date ) );
    }

    /**
     * Get month period from date
     *
     * @since 1.0.0
     * @param string $date Date (YYYY-MM-DD).
     * @return string Month period (YYYY-MM).
     */
    private function get_month_period( $date ) {
        return gmdate( 'Y-m', strtotime( $date ) );
    }

    /**
     * Get quarter period from date
     *
     * @since 1.0.0
     * @param string $date Date (YYYY-MM-DD).
     * @return string Quarter period (YYYY-Qx).
     */
    private function get_quarter_period( $date ) {
        $timestamp = strtotime( $date );
        $year      = gmdate( 'Y', $timestamp );
        $month     = gmdate( 'n', $timestamp );
        $quarter   = ceil( $month / 3 );
        
        return $year . '-Q' . $quarter;
    }
}
