<?php
namespace DirectoristAnalytics\App\Http\Controllers;

use DirectoristAnalytics\WpMVC\RequestValidator\Validator;
use DirectoristAnalytics\WpMVC\Routing\Response;
use WP_REST_Request;
use Exception;
use DirectoristAnalytics\App\Services\SettingsService;

/**
 * Settings Controller
 *
 * @package DirectoristAnalytics
 * @since 1.0.0
 */
class SettingsController extends Controller {

	const OPTION_NAME = 'directorist_analytics_settings';

	/**
     * Get settings
     *
     * @since 1.0.0
     * @param Validator       $validator       Validator instance.
     * @param WP_REST_Request $wp_rest_request Request object.
     * @return Response
     */
    public function get_settings( Validator $validator, WP_REST_Request $wp_rest_request ) {
        // No validation needed for GET settings
        
        try {
            $settings = $this->get_default_settings();
            $saved    = get_option( self::OPTION_NAME, array() );

            // Merge with saved settings
            if ( is_array( $saved ) ) {
                $settings = array_merge( $settings, $saved );
            }

            return Response::send(
                array(
                    'settings' => $settings,
                    'effective_policy' => SettingsService::policy( $settings ),
                )
            );
        } catch ( Exception $e ) {
            return Response::send(
                array(
                    'message' => esc_html__( 'Error fetching settings', 'directorist-analytics' ),
                ),
                500
            );
        }
    }

	/**
	 * Update settings
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function update_settings( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'enable_data_protection'   => 'boolean',
				'respect_do_not_track'     => 'boolean',
				'include_admin_visits'     => 'boolean',
				'anonymize_ips'            => 'boolean',
				'auto_delete_days'         => 'numeric|min:0|max:365',
			)
		);

		try {
			$changes = array();
			foreach ( SettingsService::defaults() as $key => $default ) {
				if ( ! $wp_rest_request->has_param( $key ) ) {
					continue;
				}
				$changes[ $key ] = 'auto_delete_days' === $key
					? absint( $wp_rest_request->get_param( $key ) ?: 30 )
					: rest_sanitize_boolean( $wp_rest_request->get_param( $key ) );
			}
			$settings = SettingsService::save( $changes );

			return Response::send(
				array(
					'success'  => true,
					'settings' => $settings,
					'effective_policy' => SettingsService::policy( $settings ),
					'message'  => esc_html__( 'Settings saved successfully', 'directorist-analytics' ),
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error saving settings', 'directorist-analytics' ),
				),
				500
			);
		}
	}

	/**
	 * Delete raw logs
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function delete_raw_logs( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'confirm' => 'required|boolean',
			)
		);

		try {
			$confirm = rest_sanitize_boolean( $wp_rest_request->get_param( 'confirm' ) );

			if ( ! $confirm ) {
				return Response::send(
					array(
						'message' => esc_html__( 'Confirmation required', 'directorist-analytics' ),
					),
					400
				);
			}

			global $wpdb;

			// Delete from analytics_views table
			$views_deleted = $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}directorist_analytics_views" );

			// Delete from analytics_clicks table
			$clicks_deleted = $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}directorist_analytics_clicks" );

			// Delete from analytics_searches table
			$searches_deleted = $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}directorist_analytics_searches" );

			return Response::send(
				array(
					'success' => true,
					'message' => esc_html__( 'Raw logs deleted successfully', 'directorist-analytics' ),
					'deleted' => array(
						'views'    => $views_deleted !== false,
						'clicks'   => $clicks_deleted !== false,
						'searches' => $searches_deleted !== false,
					),
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error deleting logs', 'directorist-analytics' ),
				),
				500
			);
		}
	}

	/**
	 * Get default settings
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function get_default_settings() {
		return array(
			'enable_data_protection'   => false,
			'respect_do_not_track'     => false,
			'include_admin_visits'     => true,
			'anonymize_ips'            => false,
			'auto_delete_days'         => 30,
		);
	}
}