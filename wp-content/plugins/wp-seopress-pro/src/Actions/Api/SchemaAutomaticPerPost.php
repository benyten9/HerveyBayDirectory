<?php

namespace SEOPressPro\Actions\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * REST API endpoint for per-post automatic schema overrides.
 *
 * Exposes the data the React universal metabox needs to render and save the
 * "Automatic" sub-tab of the Schemas tab:
 *
 * - `GET  /seopress/v1/posts/{id}/schemas-automatic`
 * - `PUT  /seopress/v1/posts/{id}/schemas-automatic`
 *
 * This controller is distinct from `SchemaAutomatic.php` which handles the
 * global CRUD of `seopress_schemas` CPT posts (admin listing / editor). This
 * one is strictly per-post and writes the three legacy meta keys consumed by
 * `options-automatic-rich-snippets.php` and `PrintRichSnippets.php`:
 *
 * - `_seopress_pro_rich_snippets_disable_all` (`'1'` or absent)
 * - `_seopress_pro_rich_snippets_disable`     (`array( <schemaId> => '1' )`)
 * - `_seopress_pro_schemas`                   (`array( 0 => <schemaId> => <section> => <key> => value )`)
 *
 * The data contract and the 3 meta keys match the classic save path byte-for-
 * byte, so the frontend JSON-LD output is unchanged regardless of which UI
 * wrote the data.
 *
 * @since 9.8
 */
class SchemaAutomaticPerPost implements ExecuteHooks {

	/**
	 * Register the WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function register() {
		register_rest_route(
			'seopress/v1',
			'/posts/(?P<id>\d+)/schemas-automatic',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGet' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
						'sanitize_callback' => 'absint',
					),
				),

				/*
				 * `current_user_can()` uses the live current user state, which
				 * `rest_cookie_check_errors` resets to 0 when no nonce is
				 * provided on a cookie-authenticated REST request. Caching the
				 * user ID at hook-registration time would silently bypass that
				 * check and let CSRF GETs leak the schema overrides tree, so
				 * we deliberately rely on the live state here.
				 */
				'permission_callback' => function ( $request ) {
					return current_user_can( 'edit_post', (int) $request['id'] );
				},
			)
		);

		register_rest_route(
			'seopress/v1',
			'/posts/(?P<id>\d+)/schemas-automatic',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'processPut' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
						'sanitize_callback' => 'absint',
					),
				),
				'permission_callback' => function ( $request ) {
					if ( function_exists( 'seopress_metabox_role_is_blocked' ) && seopress_metabox_role_is_blocked( 'GLOBAL' ) ) {
						return false;
					}
					return current_user_can( 'edit_post', (int) $request['id'] );
				},
			)
		);
	}

	/**
	 * GET handler — list matching automatic schemas, their per-post state, and
	 * the raw overrides tree stored on the post.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGet( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );

		$matcher = seopress_pro_get_service( 'AutomaticSchemasMatcher' );
		$fields  = seopress_pro_get_service( 'AutomaticSchemasFields' );

		$matching_ids = array();
		if ( $matcher ) {
			// Deduplicate matching IDs so the React UI renders each schema once,
			// even if the schema has several rule groups (OR branches) matching.
			$matching_ids = array_values( array_unique( $matcher->forPost( $post_id ) ) );
		}

		$disable_all      = '1' === (string) get_post_meta( $post_id, '_seopress_pro_rich_snippets_disable_all', true );
		$disabled_raw     = get_post_meta( $post_id, '_seopress_pro_rich_snippets_disable', true );
		$disabled         = is_array( $disabled_raw ) ? array_map( 'strval', $disabled_raw ) : array();
		$overrides_raw    = get_post_meta( $post_id, '_seopress_pro_schemas', false );
		$overrides_legacy = isset( $overrides_raw[0] ) && is_array( $overrides_raw[0] ) ? $overrides_raw[0] : array();

		$matching_schemas = array();
		foreach ( $matching_ids as $schema_id ) {
			$schema_id = (int) $schema_id;
			$schema    = get_post( $schema_id );
			if ( ! $schema instanceof \WP_Post ) {
				continue;
			}

			$type_definition = $fields ? $fields->getOverridableFieldsForSchema( $schema_id ) : array(
				'type'    => get_post_meta( $schema_id, '_seopress_pro_rich_snippets_type', true ),
				'section' => '',
				'fields'  => array(),
			);

			$section       = isset( $type_definition['section'] ) ? $type_definition['section'] : '';
			$schema_fields = array();

			foreach ( $type_definition['fields'] as $field ) {
				$stored = null;
				if ( '' !== $section && isset( $overrides_legacy[ $schema_id ][ $section ][ $field['key'] ] ) ) {
					$stored = $overrides_legacy[ $schema_id ][ $section ][ $field['key'] ];
				}

				if ( 'opening_hours' === $field['type'] ) {
					$value = $fields->formatOpeningHoursForClient( $stored );
				} else {
					$value = is_scalar( $stored ) ? (string) $stored : '';
				}

				$schema_fields[] = array(
					'key'          => $field['key'],
					'section'      => $section,
					'meta_key'     => $field['meta_key'],
					'sentinel'     => $field['sentinel'],
					'type'         => $field['type'],
					'label'        => $field['label'],
					'placeholder'  => $field['placeholder'],
					'default_hint' => $field['default_hint'],
					'description'  => $field['description'],
					'options'      => $field['options'],
					'value'        => $value,
				);
			}

			$matching_schemas[] = array(
				'id'       => $schema_id,
				'title'    => get_the_title( $schema_id ),
				'type'     => isset( $type_definition['type'] ) ? (string) $type_definition['type'] : '',
				'section'  => $section,
				'edit_url' => $this->getSchemaEditUrl( $schema_id ),
				'disabled' => isset( $disabled[ $schema_id ] ) && '1' === $disabled[ $schema_id ],
				'fields'   => $schema_fields,
			);
		}

		return new \WP_REST_Response(
			array(
				'disable_all'      => $disable_all,
				'disabled'         => $disabled,
				'matching_schemas' => $matching_schemas,
				/*
				 * The whole stored tree, so the client can seed its state with
				 * it and hand it back untouched. The metabox mirrors this meta
				 * into the Block Editor and into the Classic Editor post form,
				 * and both of those write it verbatim: sending only the fields
				 * of the schemas currently matching would drop the rest — the
				 * overrides of a schema that no longer matches, and the opening
				 * hours the classic metabox saved.
				 */
				'overrides'        => (object) $overrides_legacy,
			)
		);
	}

	/**
	 * Admin URL where an automatic schema is edited.
	 *
	 * The React Schemas editor replaced the CPT edit screen, so the metabox
	 * link has to land there too. The classic metabox already routes this way,
	 * falling back to the CPT screen on the WordPress versions that cannot run
	 * DataViews — mirror both branches here so the two metaboxes agree.
	 *
	 * Built manually instead of get_edit_post_link() because the
	 * `seopress_schemas` CPT uses custom capabilities (`edit_schema`) mapped
	 * via map_meta_cap, which makes the WP helper return null in REST context.
	 *
	 * Returns an empty string when the user cannot manage schemas — the same
	 * gate the classic metabox applies before printing its own link, and the
	 * React metabox already hides the link when the URL is empty. Otherwise a
	 * contributor is handed a link to a screen they cannot open.
	 *
	 * @param int $schema_id The `seopress_schemas` post ID.
	 *
	 * @return string
	 */
	private function getSchemaEditUrl( $schema_id ) {
		$capability = function_exists( 'seopress_capability' )
			? seopress_capability( 'manage_options', 'schemas' )
			: 'manage_options';

		if ( ! current_user_can( $capability ) ) {
			return '';
		}

		// The only caller already casts, but the guarantee belongs to whoever
		// builds the URL: the value ends up in an href the metabox renders.
		$schema_id = absint( $schema_id );

		if ( \SEOPressPro\Helpers\DataViewsAssets::is_supported() ) {
			return admin_url( 'admin.php?page=seopress-schemas#/edit/' . $schema_id );
		}

		return admin_url( 'post.php?post=' . $schema_id . '&action=edit' );
	}

	/**
	 * PUT handler — persist per-post automatic schema state.
	 *
	 * Accepts:
	 * ``` json
	 * {
	 *   "disable_all": false,
	 *   "disabled":    { "<schemaId>": true },
	 *   "overrides":   { "<schemaId>": { "rich_snippets_article": { "title": "…" } } }
	 * }
	 * ```
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processPut( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$params  = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		// disable_all.
		$disable_all = ! empty( $params['disable_all'] );
		if ( $disable_all ) {
			update_post_meta( $post_id, '_seopress_pro_rich_snippets_disable_all', '1' );
		} else {
			delete_post_meta( $post_id, '_seopress_pro_rich_snippets_disable_all' );
		}

		// disabled per schema.
		$disabled_input = isset( $params['disabled'] ) && is_array( $params['disabled'] ) ? $params['disabled'] : array();
		$disabled       = array();
		foreach ( $disabled_input as $schema_id => $flag ) {
			$sid = (int) $schema_id;
			if ( $sid > 0 && ! empty( $flag ) ) {
				$disabled[ $sid ] = '1';
			}
		}
		if ( ! empty( $disabled ) ) {
			update_post_meta( $post_id, '_seopress_pro_rich_snippets_disable', $disabled );
		} else {
			delete_post_meta( $post_id, '_seopress_pro_rich_snippets_disable' );
		}

		// overrides keyed by schema ID -> section -> field.
		$overrides_input = isset( $params['overrides'] ) && is_array( $params['overrides'] ) ? $params['overrides'] : array();
		$sanitized       = $this->sanitizeOverrides( $overrides_input );

		/*
		 * Only the fields this controller knows about are replaced. Anything
		 * else already stored on the post is carried over untouched: overrides
		 * belonging to a schema that no longer matches, and — on sites that
		 * never opted into the per-post opening hours — the `opening_hours`
		 * entry written by the classic metabox, which the frontend resolver
		 * still reads. Overwriting the whole tree would silently drop both.
		 */
		$fields_service = seopress_pro_get_service( 'AutomaticSchemasFields' );
		$existing_raw   = get_post_meta( $post_id, '_seopress_pro_schemas', false );
		$existing       = isset( $existing_raw[0] ) && is_array( $existing_raw[0] ) ? $existing_raw[0] : array();
		$merged         = $fields_service ? $fields_service->mergeOverrides( $existing, $sanitized ) : $sanitized;

		if ( ! empty( $merged ) ) {
			/*
			 * The classic save path stores the schema tree as
			 * [ <schemaId> => [ <section> => [ <key> => value ] ] ]
			 * directly via update_post_meta(). The `[0]` index that the
			 * classic READ path uses comes from `get_post_meta( ..., false )`
			 * (multi-mode), not from any extra wrapping at write time. We
			 * must NOT wrap it ourselves or the classic UI will look one
			 * level too deep and find nothing.
			 */
			update_post_meta( $post_id, '_seopress_pro_schemas', $merged );
		} else {
			delete_post_meta( $post_id, '_seopress_pro_schemas' );
		}

		return new \WP_REST_Response(
			array(
				'code' => 'success',
			)
		);
	}

	/**
	 * Sanitize the overrides tree according to each field's declared type.
	 *
	 * Unknown schema types, unknown sections and unknown fields are dropped.
	 * The shape of the returned array matches the legacy save path:
	 *   array( <schemaId> => array( <section> => array( <key> => <value> ) ) )
	 *
	 * @param array $overrides The raw tree submitted by the client.
	 *
	 * @return array<int,array<string,array<string,string>>>
	 */
	private function sanitizeOverrides( array $overrides ) {
		$fields_service = seopress_pro_get_service( 'AutomaticSchemasFields' );
		if ( ! $fields_service ) {
			return array();
		}

		$sanitized = array();

		foreach ( $overrides as $schema_id => $sections ) {
			$sid = (int) $schema_id;
			if ( $sid <= 0 || ! is_array( $sections ) ) {
				continue;
			}

			$definition = $fields_service->getOverridableFieldsForSchema( $sid );
			if ( empty( $definition['fields'] ) ) {
				continue;
			}

			$section_name = $definition['section'];
			$allowed_keys = array();
			$field_types  = array();
			foreach ( $definition['fields'] as $field ) {
				$allowed_keys[ $field['key'] ] = true;
				$field_types[ $field['key'] ]  = $field['type'];
			}

			if ( ! isset( $sections[ $section_name ] ) || ! is_array( $sections[ $section_name ] ) ) {
				continue;
			}

			$clean_section = array();
			foreach ( $sections[ $section_name ] as $key => $value ) {
				if ( ! isset( $allowed_keys[ $key ] ) ) {
					continue;
				}

				if ( 'opening_hours' === $field_types[ $key ] ) {
					$clean_section[ $key ] = $fields_service->sanitizeOpeningHours( $value );
					continue;
				}

				$clean_section[ $key ] = $this->sanitizeValue( $value, $field_types[ $key ] );
			}

			if ( ! empty( $clean_section ) ) {
				$sanitized[ $sid ][ $section_name ] = $clean_section;
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize a single override value by field type.
	 *
	 * @param mixed  $value The raw value.
	 * @param string $type  The declared field type.
	 *
	 * @return string
	 */
	private function sanitizeValue( $value, $type ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;

		switch ( $type ) {
			case 'url':
				return esc_url_raw( $value );

			case 'textarea':
				return sanitize_textarea_field( $value );

			case 'custom':
				// Allow <script type="application/ld+json"> blobs for the
				// custom JSON-LD field, matching the classic save path.
				return wp_kses(
					$value,
					array( 'script' => array( 'type' => array() ) )
				);

			case 'number':
			case 'rating':
				return '' === trim( $value ) ? '' : (string) $value;

			case 'checkbox':
				return '' === $value ? '' : '1';

			case 'date':
			case 'time':
			case 'select':
			case 'upload':
			case 'text':
			default:
				return sanitize_text_field( $value );
		}
	}
}
