<?php
/**
 * @package Directorist Claim Listing
 */

if( ! class_exists( 'Directorist_Claim_Listing_API' ) ):

    class Directorist_Claim_Listing_API {

        /**
         * REST API namespace.
         *
         * @var string
         */
        protected $namespace = 'directorist/v1';

        public function __construct() {
            add_filter( 'directorist_rest_listing_data', array( $this, 'listing_claim_data' ), 10, 2 );
            add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        }

        /**
         * Register REST API routes.
         *
         * @return void
         */
        public function register_routes() {
            register_rest_route(
                $this->namespace,
                '/listings/(?P<listing_id>[\d]+)/claim',
                array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array( $this, 'submit_claim' ),
                        'permission_callback' => array( $this, 'check_permissions' ),
                        'args'                => $this->get_endpoint_args(),
                    ),
                    'schema' => array( $this, 'get_item_schema' ),
                )
            );
        }

        /**
         * Check if a given request has access to submit a claim.
         *
         * @param WP_REST_Request $request Full details about the request.
         * @return WP_Error|boolean
         */
        public function check_permissions( $request ) {
            if ( ! is_user_logged_in() ) {
                return new WP_Error(
                    'directorist_rest_claim_unauthorized',
                    __( 'You must be logged in to claim a listing.', 'directorist-claim-listing' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            return true;
        }

        /**
         * Get endpoint arguments.
         *
         * @return array
         */
        protected function get_endpoint_args() {
            return array(
                'claimer_name' => array(
                    'description' => __( 'Full name of the claimer.', 'directorist-claim-listing' ),
                    'type'        => 'string',
                    'required'    => true,
                    'minLength' => 1,
                    'maxLength' => 200,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'claimer_phone' => array(
                    'description' => __( 'Phone number of the claimer.', 'directorist-claim-listing' ),
                    'type'        => 'string',
                    'required'    => true,
                    'minLength' => 1,
                    'maxLength' => 50,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'claimer_details' => array(
                    'description' => __( 'Verification details about the business.', 'directorist-claim-listing' ),
                    'type'        => 'string',
                    'required'    => true,
                    'minLength' => 1,
                    'maxLength' => 5000,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
                'plan_id' => array(
                    'description' => __( 'Pricing plan ID (optional).', 'directorist-claim-listing' ),
                    'type'        => 'integer',
                    'required'    => false,
                    'sanitize_callback' => 'absint',
                ),
                'order_id' => array(
                    'description' => __( 'Order ID (optional).', 'directorist-claim-listing' ),
                    'type'        => 'integer',
                    'required'    => false,
                    'sanitize_callback' => 'absint',
                ),
                'type' => array(
                    'description' => __( 'Listing type: regular or featured (optional).', 'directorist-claim-listing' ),
                    'type'        => 'string',
                    'required'    => false,
                    'enum'        => array( 'regular', 'featured' ),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'nonce' => array(
                    'description' => __( 'Security nonce.', 'directorist-claim-listing' ),
                    'type'        => 'string',
                    'required'    => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            );
        }

        /**
         * Submit a claim for a listing.
         *
         * @param WP_REST_Request $request Full details about the request.
         * @return WP_Error|WP_REST_Response
         */
        public function submit_claim( $request ) {
            $nonce = $request->get_param( 'nonce' );
            if ( $nonce && ! wp_verify_nonce( $nonce, 'directorist_claim_nonce' ) ) {
                return new WP_Error( 'directorist_rest_invalid_nonce', 'Invalid security nonce.', [ 'status' => 403 ] );
            }
            $args = $request->get_params();
            $args['listing_id'] = (int) $request['listing_id'];
            foreach ( [ 'plan_id', 'order_id' ] as $key ) { $args[ $key ] = (int) ( $args[ $key ] ?? 0 ); }
            try {
                $result = DCL_Claim_Service::submit( $args );
                return new WP_REST_Response( $result + [ 'error' => false, 'message' => 'Your claim submitted successfully.', 'take_payment' => $result['checkout_required'] ], 201 );
            } catch ( Throwable $error ) {
                return new WP_Error( 'directorist_rest_claim_rejected', 'Claim could not be submitted. Check eligibility, required fields and purchase references.', [ 'status' => 400 ] );
            }
        }

        /**
         * Get the schema for the claim endpoint.
         *
         * @return array
         */
        public function get_item_schema() {
            $schema = array(
                '$schema'    => 'http://json-schema.org/draft-04/schema#',
                'title'      => 'claim-listing',
                'type'       => 'object',
                'properties' => array(
                    'error' => array(
                        'description' => __( 'Whether an error occurred.', 'directorist-claim-listing' ),
                        'type'        => 'boolean',
                        'context'     => array( 'view', 'edit' ),
                    ),
                    'message' => array(
                        'description' => __( 'Response message.', 'directorist-claim-listing' ),
                        'type'        => 'string',
                        'context'     => array( 'view', 'edit' ),
                    ),
                    'take_payment' => array(
                        'description' => __( 'Whether payment is required.', 'directorist-claim-listing' ),
                        'type'        => 'boolean',
                        'context'     => array( 'view', 'edit' ),
                    ),
                    'checkout_url' => array(
                        'description' => __( 'Checkout URL if payment is required.', 'directorist-claim-listing' ),
                        'type'        => 'string',
                        'format'      => 'uri',
                        'context'     => array( 'view', 'edit' ),
                    ),
                ),
            );

            return $schema;
        }

        /**
         * Add claim data to listing REST response.
         *
         * @param array $data Listing data.
         * @param WP_Post $listing Listing post object.
         * @return array
         */
        public function listing_claim_data( $data, $listing ) {
            $data['claimed_by_admin'] = get_post_meta( $listing->ID, '_claimed_by_admin', true );
            return $data;
        }

    }

endif;

new Directorist_Claim_Listing_API();