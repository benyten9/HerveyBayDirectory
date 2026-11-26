<?php
/**
 * Public endpoints of DoubleScale forms: the definition, view and start counters, and submit.
 *
 * Open to visitors with no account. Only active forms answer (managers may also use drafts,
 * for previews); the spam gate and rate limits in Submission/Spam guard the submit.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Core\Security\RateLimiter;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Native\Renderer;
use DoubleScale\Modules\Forms\Native\Stats;
use DoubleScale\Modules\Forms\Native\Submission;
use DoubleScale\Modules\Forms\Native\Tokens;
use DoubleScale\Modules\Forms\Native\Access;
use DoubleScale\Modules\Forms\Native\Partial;
use DoubleScale\Modules\Forms\Native\Uploads;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Public form routes.
 */
class RestPublicFormController extends RestController {

	/**
	 * Routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$base = '/forms/public/(?P<hash>[a-z0-9]{6,40})';
		$hash = array(
			'hash' => array(
				'type'     => 'string',
				'required' => true,
			),
		);

		register_rest_route(
			$this->namespace,
			$base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'definition' ),
					'permission_callback' => '__return_true',
					'args'                => $hash,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/submit',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit' ),
					// Visitors with no account: the spam gate and rate limits guard it.
					'permission_callback' => '__return_true',
					'args'                => $hash,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/prefill',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'prefill' ),
					// Visitors with no account: answers only to a valid signed token for this form.
					'permission_callback' => '__return_true',
					'args'                => $hash,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/upload',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload' ),
					// Visitors with no account: Uploads::store() checks the field, type, size and rate.
					'permission_callback' => '__return_true',
					'args'                => $hash,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/partial',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'partial' ),
					// Visitors with no account: needs the page's signed token; rate limited (Pro forms with partial entries on).
					'permission_callback' => '__return_true',
					'args'                => $hash,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/resume',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resume' ),
					// Visitors with no account: answers only to a random 32-character resume token for this form.
					'permission_callback' => '__return_true',
					'args'                => $hash,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/(?P<event>view|start)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'count' ),
					'permission_callback' => '__return_true',
					'args'                => $hash,
				),
			)
		);
	}

	/**
	 * The form, as the renderer needs it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function definition( WP_REST_Request $request ) {
		$form = $this->open_form( $request );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		// Closed, not open yet, members only or locked: the questions stay private too.
		$access = Access::state( $form );
		if ( ! $access['open'] ) {
			$closed = Access::reply( $access );
			return new WP_Error( $closed['body']['code'], $closed['body']['message'], array( 'status' => $closed['status'] ) );
		}
		return new WP_REST_Response( Renderer::payload( $form, 'api' ), 200 );
	}

	/**
	 * Submit answers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit( WP_REST_Request $request ) {
		$form = $this->open_form( $request );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$input = $request->get_json_params();
		if ( ! is_array( $input ) || empty( $input ) ) {
			$input = $request->get_body_params();
		}

		$result = Submission::handle( $form, is_array( $input ) ? $input : array() );

		$response = new WP_REST_Response( $result['body'], $result['status'] );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The details of the contact a {{forms:link}} was sent to, for the fields this form has.
	 *
	 * Only with a signed, unexpired token made for this form; never cached.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function prefill( WP_REST_Request $request ) {
		$form = $this->open_form( $request );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$empty = new WP_REST_Response( array( 'prefill' => new \stdClass() ), 200 );
		$empty->header( 'Cache-Control', 'no-store, private' );

		if ( RateLimiter::ready() && RateLimiter::hit( 'forms_prefill_' . ClientIp::bucket(), HOUR_IN_SECONDS ) > 60 ) {
			return $empty;
		}
		$contact_id = Tokens::prefill_contact( (string) $request->get_param( 'token' ), (int) $form->id );
		$contact    = $contact_id ? \DoubleScale\Modules\Contacts\Models\ContactModel::find( $contact_id ) : null;
		if ( ! $contact ) {
			return $empty;
		}

		// Only what this form asks for.
		$types  = array();
		$mapped = array();
		foreach ( \DoubleScale\Modules\Forms\Native\Schema::input_fields( \DoubleScale\Modules\Forms\Native\Schema::effective( $form )['schema'] ) as $field ) {
			$types[ $field['type'] ] = true;
			if ( ! empty( $field['map'] ) && 'contact' === $field['map']['object'] ) {
				$mapped[ $field['map']['field'] ] = true;
			}
		}
		$prefill = array();
		if ( isset( $types['email'] ) || isset( $mapped['email'] ) ) {
			$prefill['email'] = (string) $contact->email;
		}
		if ( isset( $types['name'] ) || isset( $mapped['first_name'] ) || isset( $mapped['name'] ) ) {
			$prefill['first_name'] = (string) $contact->first_name;
		}
		if ( isset( $types['name'] ) || isset( $mapped['last_name'] ) || isset( $mapped['name'] ) ) {
			$prefill['last_name'] = (string) $contact->last_name;
		}
		if ( isset( $types['phone'] ) || isset( $mapped['phone'] ) ) {
			$prefill['phone'] = (string) $contact->phone;
		}

		$response = new WP_REST_Response( array( 'prefill' => (object) array_filter( $prefill ) ), 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * Upload one file for a file field (Pro).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( WP_REST_Request $request ) {
		$form = $this->open_form( $request );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
		}
		$access = Access::state( $form );
		if ( ! $access['open'] ) {
			$closed = Access::reply( $access );
			return new WP_Error( $closed['body']['code'], $closed['body']['message'], array( 'status' => $closed['status'] ) );
		}
		// Only a browser that loaded this form (signed token from the page) may upload.
		$age = Tokens::issued_age( (string) $request->get_param( '_dsf_issued' ), (int) $form->id );
		if ( null === $age || $age > Tokens::ISSUED_MAX ) {
			return new WP_Error( 'expired', __( 'Please reload the page and try again.', 'doublescale' ), array( 'status' => 403 ) );
		}
		$result = Uploads::store( $form, sanitize_key( (string) $request->get_param( 'field' ) ), $files['file'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Save the answers so far (partial entries, Pro).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function partial( WP_REST_Request $request ) {
		$form = $this->open_form( $request );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$input  = $request->get_json_params();
		$result = Partial::save( $form, is_array( $input ) ? $input : array() );

		$response = new WP_REST_Response( $result['body'], $result['status'] );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The saved answers of a resume link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resume( WP_REST_Request $request ) {
		$form = $this->open_form( $request );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$result = Partial::resume( $form, (string) $request->get_param( 'token' ) );

		$response = new WP_REST_Response( $result['body'], $result['status'] );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Referrer-Policy', 'no-referrer' );
		return $response;
	}

	/**
	 * Count a view or a start, once per visitor every 30 minutes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function count( WP_REST_Request $request ) {
		$form = $this->open_form( $request );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$event   = 'start' === $request->get_param( 'event' ) ? 'starts' : 'views';
		$variant = sanitize_key( (string) $request->get_param( 'variant' ) );
		$key     = 'forms_' . $event . '_' . $form->id . '_' . ClientIp::bucket();

		if ( ! RateLimiter::ready() || RateLimiter::once( $key, 30 * MINUTE_IN_SECONDS ) ) {
			Stats::bump( (int) $form->id, $event, $variant );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * The form for a public request: active, or a draft for a manager's preview.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return FormModel|WP_Error
	 */
	private function open_form( WP_REST_Request $request ) {
		$unavailable = new WP_Error( 'form_not_found', __( 'This form is not available.', 'doublescale' ), array( 'status' => 404 ) );

		if ( ! doublescale_module_data_ready( 'forms' ) ) {
			return $unavailable;
		}
		$form = FormModel::find_native_by_hash( (string) $request->get_param( 'hash' ) );
		if ( ! $form ) {
			return $unavailable;
		}
		if ( 'active' !== $form->status && ! Permissions::has_crm_manager_access() ) {
			return $unavailable;
		}
		return $form;
	}
}
