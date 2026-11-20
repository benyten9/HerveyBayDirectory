<?php
// Prohibit direct script loading.
defined( 'ABSPATH' ) || die( 'No direct script access allowed!' );

if ( ! class_exists( 'DCL_REST_Response' ) ) {
    /**
     * Add claim verification data to Directorist listing REST responses.
     */
    final class DCL_REST_Response {

        /**
         * Register hooks.
         *
         * @return void
         */
        public function __construct() {
            $post_type = defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';

            add_filter( "directorist_rest_prepare_{$post_type}_object", [ $this, 'filter_prepared_listing_response' ], 10, 3 );
        }

        /**
         * Add verification status to v1 and v2 collection items and individual listings.
         *
         * @param WP_REST_Response $response REST response object.
         * @param WP_Post          $object   Listing object.
         * @param WP_REST_Request  $request  Current REST request.
         *
         * @return WP_REST_Response
         */
        public function filter_prepared_listing_response( $response, $object, $request ) {
            if ( is_wp_error( $response ) || ! ( $object instanceof WP_Post ) || ! ( $request instanceof WP_REST_Request ) ) {
                return $response;
            }

            $post_type = defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';

            if ( $post_type !== $object->post_type || ! $object->ID ) {
                return $response;
            }

            if ( ! preg_match( '#^/directorist/v[12]/listings(?:/\d+)?/?$#', $request->get_route() ) ) {
                return $response;
            }

            $response      = rest_ensure_response( $response );
            $response_data = $response->get_data();

            if ( ! is_array( $response_data ) ) {
                return $response;
            }

            $claimed_by_admin = get_post_meta( absint( $object->ID ), '_claimed_by_admin', true );
            $response_data['verified'] = ! empty( $claimed_by_admin );

            $response->set_data( $response_data );

            return $response;
        }
    }
}
