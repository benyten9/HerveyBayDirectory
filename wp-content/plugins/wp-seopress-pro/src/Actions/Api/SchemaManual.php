<?php

namespace SEOPressPro\Actions\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;
use SEOPressPro\Helpers\Schemas\ManualSchemas;

class SchemaManual implements ExecuteHooks {

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function register() {
		register_rest_route(
			'seopress/v1',
			'/posts/(?P<id>\d+)/schemas-manual',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGet' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						},
					),
				),

				/*
				 * `current_user_can()` uses the live current user state, which
				 * `rest_cookie_check_errors` resets to 0 when no nonce is
				 * provided on a cookie-authenticated REST request. Caching the
				 * user ID at hook-registration time would silently bypass that
				 * check and let CSRF GETs leak post-level data, so we rely on
				 * the live state here.
				 */
				'permission_callback' => function ( $request ) {
					return current_user_can( 'edit_post', (int) $request['id'] );
				},
			)
		);
		register_rest_route(
			'seopress/v1',
			'/posts/(?P<id>\d+)/schemas-manual',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'processPut' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						},
					),
				),
				'permission_callback' => function ( $request ) {
					if ( function_exists( 'seopress_metabox_role_is_blocked' ) && seopress_metabox_role_is_blocked( 'GLOBAL' ) ) {
						return false;
					}
					$post_id = $request['id'];
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	public function cleanData( $data ) {
		if ( empty( $data ) ) {
			return $data;
		}

		foreach ( $data as $key => $value ) {
			if ( 'howto' === $value['_seopress_pro_rich_snippets_type'] ) {
				$data[ $key ]['_seopress_pro_rich_snippets_how_to'] = array_values(
					isset( $value['_seopress_pro_rich_snippets_how_to'] ) ? $value['_seopress_pro_rich_snippets_how_to'] : array()
				);
			}
		}

		return $data;
	}

	public function processGet( \WP_REST_Request $request ) {
		$id   = $request->get_param( 'id' );
		$data = get_post_meta( $id, '_seopress_pro_schemas_manual', true );

		if ( empty( $data ) ) {
			$data = array();
		}

		$schemasAvailable = seopress_pro_get_service( 'FormSchemaAvailable' )->getData();

		$fields = array();
		foreach ( $schemasAvailable as $key => $item ) {
			$class                   = new $item['class']();
			$fields[ $item['type'] ] = $class->getFields( $id );
		}

		$data = $this->cleanData( $data );

		return new \WP_REST_Response(
			array(
				'data'    => $data,
				'fields'  => $fields,
				'schemas' => $schemasAvailable,
			)
		);
	}

	public function processPut( \WP_REST_Request $request ) {
		$id     = $request->get_param( 'id' );
		$params = $request->get_params();

		if ( ! isset( $params['schemas'] ) ) {
			return new \WP_REST_Response(
				array(
					'code'         => 'error',
					'code_message' => 'missing_parameters',
				),
				403
			);
		}

		// The meta is registered as a list of objects, so the rows are stored
		// as a numerically indexed list whatever the client sent. A payload
		// keyed by anything else would persist a value its own schema rejects,
		// and the Block Editor then refuses every later save of that post.
		$schemas = array_values( array_filter( (array) $params['schemas'], 'is_array' ) );

		// Sanitize each schema, preserving <script> tags for custom schemas.
		foreach ( $schemas as $key => $schema ) {
			foreach ( $schema as $field => $value ) {
				if ( '_seopress_pro_rich_snippets_custom' === $field ) {
					// Allow a JSON-LD script tag, and only that one: the field
					// is echoed into wp_head() as it is stored.
					$schemas[ $key ][ $field ] = seopress_pro_kses_json_ld( $value );
				} elseif ( is_array( $value ) ) {
					$schemas[ $key ][ $field ] = map_deep( $value, 'sanitize_text_field' );
				} else {
					$schemas[ $key ][ $field ] = sanitize_text_field( $value );
				}
			}
		}

		// Nothing here renders (no row, or every row left on "None"), so drop
		// the meta rather than leave a marker that reads as an assignment. This
		// is what lets a site upgraded from a version where "None" was the
		// default choice shed those rows as its posts get saved again. Rows
		// that do carry a type are always stored, untouched.
		if ( ! ManualSchemas::hasEffectiveSchema( $schemas ) ) {
			delete_post_meta( $id, ManualSchemas::META_KEY );
		} else {
			update_post_meta( $id, ManualSchemas::META_KEY, $schemas );
		}

		return new \WP_REST_Response(
			array(
				'code' => 'success',
			)
		);
	}
}
