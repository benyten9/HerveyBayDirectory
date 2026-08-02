<?php // phpcs:ignore

namespace SEOPressPro\Actions\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooksBackend;

/**
 * Manage column
 */
class ManageColumn implements ExecuteHooksBackend {
	/**
	 * Hooks
	 *
	 * @return void
	 */
	public function hooks() {
		if ( ! function_exists( 'seopress_get_toggle_option' ) ) {
			return;
		}

		if ( '1' == seopress_get_toggle_option( 'advanced' ) ) {
			add_action( 'init', array( $this, 'setup' ), 20 ); // Priority is important for plugins compatibility like Toolset.
		}
	}

	/**
	 * Setup
	 *
	 * @return void
	 */
	public function setup() {
		$list_post_types = seopress_get_service( 'WordPressData' )->getPostTypes();

		if ( empty( $list_post_types ) ) {
			return;
		}

		foreach ( $list_post_types as $key => $value ) {
			if ( method_exists( seopress_get_service( 'TitleOption' ), 'getSingleCptEnable' ) && null === seopress_get_service( 'TitleOption' )->getSingleCptEnable( $key ) && '' !== $key ) {
				add_filter( 'manage_' . $key . '_posts_columns', array( $this, 'addColumn' ) );
				add_action( 'manage_' . $key . '_posts_custom_column', array( $this, 'displayColumn' ), 10, 2 );
				add_filter( 'manage_edit-' . $key . '_sortable_columns', array( $this, 'sortableColumn' ) );
				add_filter( 'pre_get_posts', array( $this, 'sortColumnsBy' ) );
			}
		}

		add_filter( 'manage_edit-download_columns', array( $this, 'addColumn' ), 10, 2 );
	}

	/**
	 * Add columns to the post type list table
	 *
	 * @param array $columns Columns array.
	 *
	 * @return array Columns array.
	 */
	public function addColumn( $columns ) {
		if ( ! empty( seopress_get_service( 'AdvancedOption' )->getAppearanceSearchConsole() ) ) {
			$columns['seopress_search_console_clicks'] = __( 'Clicks', 'wp-seopress-pro' );
		}
		if ( ! empty( seopress_get_service( 'AdvancedOption' )->getAppearanceSearchConsole() ) ) {
			$columns['seopress_search_console_impressions'] = __( 'Impressions', 'wp-seopress-pro' );
		}
		if ( ! empty( seopress_get_service( 'AdvancedOption' )->getAppearanceSearchConsole() ) ) {
			$columns['seopress_search_console_ctr'] = __( 'CTR', 'wp-seopress-pro' );
		}
		if ( ! empty( seopress_get_service( 'AdvancedOption' )->getAppearanceSearchConsole() ) ) {
			$columns['seopress_search_console_position'] = __( 'Position', 'wp-seopress-pro' );
		}

		return $columns;
	}

	/**
	 * Display column
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function displayColumn( $column, $post_id ) {
		switch ( $column ) {
			case 'seopress_search_console_clicks':
				$clicks = get_post_meta( $post_id, '_seopress_search_console_analysis_clicks', true );
				if ( ! $clicks ) {
					echo '0';
					return;
				}

				echo esc_html( number_format_i18n( floatval( $clicks ), 0 ) );

				break;
			case 'seopress_search_console_impressions':
				$impressions = get_post_meta( $post_id, '_seopress_search_console_analysis_impressions', true );
				if ( ! $impressions ) {
					echo '0';
					return;
				}

				echo esc_html( number_format_i18n( floatval( $impressions ), 0 ) );

				break;
			case 'seopress_search_console_ctr':
				$ctr = get_post_meta( $post_id, '_seopress_search_console_analysis_ctr', true );
				if ( ! $ctr ) {
					echo '0';
					return;
				}

				echo esc_html( number_format_i18n( floatval( $ctr ) * 100, 2 ) . '%' );

				break;
			case 'seopress_search_console_position':
				$position = get_post_meta( $post_id, '_seopress_search_console_analysis_position', true );
				if ( ! $position ) {
					echo '0';
					return;
				}

				echo esc_html( number_format_i18n( floatval( $position ), 0 ) );

				break;
		}
	}

	/**
	 * Sortable columns
	 *
	 * @param string $columns Columns array.
	 *
	 * @return array Columns array.
	 */
	public function sortableColumn( $columns ) {
		$columns['seopress_search_console_clicks']      = 'seopress_search_console_clicks';
		$columns['seopress_search_console_ctr']         = 'seopress_search_console_ctr';
		$columns['seopress_search_console_impressions'] = 'seopress_search_console_impressions';
		$columns['seopress_search_console_position']    = 'seopress_search_console_position';

		return $columns;
	}

	/**
	 * Sort columns by
	 *
	 * @param string $query Query object.
	 *
	 * @return void
	 */
	public function sortColumnsBy( $query ) {
		if ( ! is_admin() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );
		if ( 'seopress_search_console_clicks' === $orderby ) {
			$query->set( 'meta_key', '_seopress_search_console_analysis_clicks' );
			$query->set( 'orderby', 'meta_value_num' );
		}
		if ( 'seopress_search_console_impressions' === $orderby ) {
			$query->set( 'meta_key', '_seopress_search_console_analysis_impressions' );
			$query->set( 'orderby', 'meta_value_num' );
		}
		if ( 'seopress_search_console_ctr' === $orderby ) {
			$query->set( 'meta_key', '_seopress_search_console_analysis_ctr' );
			$query->set( 'orderby', 'meta_value_num' );
		}
		if ( 'seopress_search_console_position' === $orderby ) {
			$query->set( 'meta_key', '_seopress_search_console_analysis_position' );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}
}
