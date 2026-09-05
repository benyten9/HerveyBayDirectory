<?php // phpcs:ignore

namespace SEOPressPro\Actions\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;
use SEOPressPro\Actions\Api\Redirections;
use SEOPressPro\Actions\Api\SchemaManual;

/**
 * Register SEOPress PRO abilities on the WordPress Abilities API (WP 6.9+).
 *
 * Abilities are added to the shared "seopress" category created by the free
 * plugin. The category is feature-detected and created as a fallback when the
 * free plugin is too old to provide it.
 *
 * @since 9.9.0
 */
class AbilitiesApiPro implements ExecuteHooks {

	const CATEGORY = 'seopress';

	/**
	 * Register the hooks.
	 *
	 * @since 9.9.0
	 *
	 * @return void
	 */
	public function hooks() {
		if ( ! function_exists( 'seopress_abilities_api_available' ) || ! seopress_abilities_api_available() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'ensureCategory' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'registerAbilities' ) );
	}

	/**
	 * Make sure the shared category exists (fallback for older free versions).
	 *
	 * @since 9.9.0
	 *
	 * @return void
	 */
	public function ensureCategory() {
		// Use the registry's is_registered() rather than wp_get_ability_category():
		// the latter calls get_registered(), which emits a "_doing_it_wrong" notice
		// when the category is not registered yet, which is exactly the case the
		// first time this guard runs.
		if ( class_exists( 'WP_Ability_Categories_Registry' )
			&& \WP_Ability_Categories_Registry::get_instance()->is_registered( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SEO', 'wp-seopress-pro' ),
				'description' => __( 'Read and manage SEO data (redirections, structured data, content analysis, AI generation, media).', 'wp-seopress-pro' ),
			)
		);
	}

	/**
	 * Register all PRO abilities.
	 *
	 * @since 9.9.0
	 *
	 * @return void
	 */
	public function registerAbilities() {
		// 1. List redirections.
		wp_register_ability(
			'seopress/list-redirections',
			array(
				'label'               => __( 'List redirections', 'wp-seopress-pro' ),
				'description'         => __( 'List the SEOPress redirections and detected 404s, with pagination, search and filtering.', 'wp-seopress-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
						'search'   => array( 'type' => 'string' ),
						'view'     => array(
							'type' => 'string',
							'enum' => array( 'redirects', '404', 'all' ),
						),
						'type'     => array(
							'type' => 'string',
							'enum' => array( '301', '302', '307', '410', '451' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'Paginated redirections payload.', 'wp-seopress-pro' ),
				),
				'permission_callback' => function () {
					return current_user_can( 'read_redirection' );
				},
				'execute_callback'    => function ( $input ) {
					return $this->runController( new Redirections(), 'processGetAll', 'GET', is_array( $input ) ? $input : array() );
				},
				'meta'                => $this->abilityMeta(
					array(
						'readonly'   => true,
						'idempotent' => true,
					)
				),
			)
		);

		// 2. Get a single redirection.
		wp_register_ability(
			'seopress/get-redirection',
			array(
				'label'               => __( 'Get a redirection', 'wp-seopress-pro' ),
				'description'         => __( 'Retrieve a single redirection by its ID.', 'wp-seopress-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->idSchema( __( 'The redirection ID.', 'wp-seopress-pro' ) ),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'The redirection data.', 'wp-seopress-pro' ),
				),
				'permission_callback' => function () {
					// Use the primitive capability (no object id) so the meta-cap mapping does not
					// collapse to the bare "read" capability for published redirections. Mirrors the
					// list ability above.
					return current_user_can( 'read_redirection' );
				},
				'execute_callback'    => function ( $input ) {
					return $this->runController( new Redirections(), 'processGet', 'GET', array( 'id' => (int) $input['id'] ) );
				},
				'meta'                => $this->abilityMeta(
					array(
						'readonly'   => true,
						'idempotent' => true,
					)
				),
			)
		);

		// 3. Create a redirection.
		wp_register_ability(
			'seopress/create-redirection',
			array(
				'label'               => __( 'Create a redirection', 'wp-seopress-pro' ),
				'description'         => __( 'Create a new redirection from an origin URL to a target URL.', 'wp-seopress-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'origin'      => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'The origin path or URL to redirect from.', 'wp-seopress-pro' ),
						),
						'destination' => array(
							'type'        => 'string',
							'description' => __( 'The target URL to redirect to.', 'wp-seopress-pro' ),
						),
						'type'        => array(
							'type'    => 'string',
							'enum'    => array( '301', '302', '307', '410', '451' ),
							'default' => '301',
						),
						'enabled'     => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'required'             => array( 'origin' ),
					'additionalProperties' => true,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'The created redirection.', 'wp-seopress-pro' ),
				),
				'permission_callback' => function () {
					return current_user_can( 'publish_redirections' );
				},
				'execute_callback'    => function ( $input ) {
					return $this->runController( new Redirections(), 'processCreate', 'POST', is_array( $input ) ? $input : array() );
				},
				'meta'                => $this->abilityMeta(
					array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					)
				),
			)
		);

		// 4. Update a redirection.
		wp_register_ability(
			'seopress/update-redirection',
			array(
				'label'               => __( 'Update a redirection', 'wp-seopress-pro' ),
				'description'         => __( 'Update an existing redirection by its ID.', 'wp-seopress-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'origin'      => array( 'type' => 'string' ),
						'destination' => array( 'type' => 'string' ),
						'type'        => array(
							'type' => 'string',
							'enum' => array( '301', '302', '307', '410', '451' ),
						),
						'enabled'     => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => true,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'The updated redirection.', 'wp-seopress-pro' ),
				),
				'permission_callback' => function ( $input ) {
					return current_user_can( 'edit_redirection', isset( $input['id'] ) ? (int) $input['id'] : 0 );
				},
				'execute_callback'    => function ( $input ) {
					return $this->runController( new Redirections(), 'processUpdate', 'PUT', is_array( $input ) ? $input : array() );
				},
				'meta'                => $this->abilityMeta(
					array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					)
				),
			)
		);

		// 5. Delete a redirection.
		wp_register_ability(
			'seopress/delete-redirection',
			array(
				'label'               => __( 'Delete a redirection', 'wp-seopress-pro' ),
				'description'         => __( 'Permanently delete a redirection by its ID.', 'wp-seopress-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->idSchema( __( 'The redirection ID to delete.', 'wp-seopress-pro' ) ),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'Deletion result.', 'wp-seopress-pro' ),
				),
				'permission_callback' => function ( $input ) {
					return current_user_can( 'delete_redirection', isset( $input['id'] ) ? (int) $input['id'] : 0 );
				},
				'execute_callback'    => function ( $input ) {
					return $this->runController( new Redirections(), 'processDelete', 'DELETE', array( 'id' => (int) $input['id'] ) );
				},
				'meta'                => $this->abilityMeta(
					array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					)
				),
			)
		);

		// 6. Get the manual structured data (schemas) of a post.
		wp_register_ability(
			'seopress/get-post-schemas',
			array(
				'label'               => __( 'Get post structured data', 'wp-seopress-pro' ),
				'description'         => __( 'Retrieve the manual structured data (schema.org) entries configured for a post, along with the available schema types and fields.', 'wp-seopress-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->postIdSchema(),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'The manual schemas data, available fields and schema types.', 'wp-seopress-pro' ),
				),
				'permission_callback' => array( $this, 'canEditPost' ),
				'execute_callback'    => function ( $input ) {
					return $this->runController( new SchemaManual(), 'processGet', 'GET', array( 'id' => (int) $input['post_id'] ) );
				},
				'meta'                => $this->abilityMeta(
					array(
						'readonly'   => true,
						'idempotent' => true,
					)
				),
			)
		);

		// 7. Update the manual structured data (schemas) of a post.
		wp_register_ability(
			'seopress/update-post-schemas',
			array(
				'label'               => __( 'Update post structured data', 'wp-seopress-pro' ),
				'description'         => __( 'Replace the manual structured data (schema.org) entries of a post. Pass the full "schemas" array as returned by the get ability.', 'wp-seopress-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'schemas' => array(
							'type'        => 'array',
							'description' => __( 'The list of schema entries to save for this post.', 'wp-seopress-pro' ),
						),
					),
					'required'             => array( 'post_id', 'schemas' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'Result code.', 'wp-seopress-pro' ),
				),
				'permission_callback' => array( $this, 'canEditPost' ),
				'execute_callback'    => function ( $input ) {
					return $this->runController(
						new SchemaManual(),
						'processPut',
						'PUT',
						array(
							'id'      => (int) $input['post_id'],
							'schemas' => isset( $input['schemas'] ) ? $input['schemas'] : array(),
						)
					);
				},
				'meta'                => $this->abilityMeta(
					array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					)
				),
			)
		);

		// 8. Generate an SEO title with AI.
		wp_register_ability(
			'seopress/generate-seo-title',
			array(
				'label'               => __( 'Generate an SEO title', 'wp-seopress-pro' ),
				'description'         => __( 'Generate an AI-optimized SEO title suggestion for a post. The suggestion is returned only and is not saved.', 'wp-seopress-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'lang'    => array(
							'type'        => 'string',
							'description' => __( 'Optional language/locale (e.g. en_US). Defaults to the site language.', 'wp-seopress-pro' ),
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'The generated SEO title.', 'wp-seopress-pro' ),
				),
				'permission_callback' => array( $this, 'canEditPost' ),
				'execute_callback'    => array( $this, 'generateTitle' ),
				'meta'                => $this->abilityMeta(
					array(
						'readonly'   => true,
						'idempotent' => false,
					)
				),
			)
		);

		// 9. Generate a meta description with AI.
		wp_register_ability(
			'seopress/generate-meta-description',
			array(
				'label'               => __( 'Generate a meta description', 'wp-seopress-pro' ),
				'description'         => __( 'Generate an AI-optimized meta description suggestion for a post. The suggestion is returned only and is not saved.', 'wp-seopress-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'lang'    => array(
							'type'        => 'string',
							'description' => __( 'Optional language/locale (e.g. en_US). Defaults to the site language.', 'wp-seopress-pro' ),
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'The generated meta description.', 'wp-seopress-pro' ),
				),
				'permission_callback' => array( $this, 'canEditPost' ),
				'execute_callback'    => array( $this, 'generateDescription' ),
				'meta'                => $this->abilityMeta(
					array(
						'readonly'   => true,
						'idempotent' => false,
					)
				),
			)
		);

		// 10. Generate alt text (and caption) for an image attachment with AI.
		wp_register_ability(
			'seopress/generate-alt-text',
			array(
				'label'               => __( 'Generate image alt text', 'wp-seopress-pro' ),
				'description'         => __( 'Generate an AI-optimized alt text and caption for an image attachment. Requires an AI provider that supports image input. The suggestion is returned only and is not saved.', 'wp-seopress-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The ID of the image attachment.', 'wp-seopress-pro' ),
						),
					),
					'required'             => array( 'attachment_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'The generated alt text and caption.', 'wp-seopress-pro' ),
				),
				'permission_callback' => function ( $input ) {
					$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;

					return $attachment_id > 0 && current_user_can( 'edit_post', $attachment_id );
				},
				'execute_callback'    => array( $this, 'generateAltText' ),
				'meta'                => $this->abilityMeta(
					array(
						'readonly'   => true,
						'idempotent' => false,
					)
				),
			)
		);

		// 11. Update the target keywords used for content analysis.
		wp_register_ability(
			'seopress/update-target-keywords',
			array(
				'label'               => __( 'Update target keywords', 'wp-seopress-pro' ),
				'description'         => __( 'Update the target keywords used by the SEOPress content analysis of a post.', 'wp-seopress-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'keywords' => array(
							'type'        => 'string',
							'description' => __( 'Comma-separated target keywords.', 'wp-seopress-pro' ),
						),
					),
					'required'             => array( 'post_id', 'keywords' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'Update result.', 'wp-seopress-pro' ),
				),
				'permission_callback' => array( $this, 'canEditPost' ),
				'execute_callback'    => array( $this, 'updateTargetKeywords' ),
				'meta'                => $this->abilityMeta(
					array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					)
				),
			)
		);

		// 12. Upload an image from an external URL to the Media Library.
		wp_register_ability(
			'seopress/upload-image',
			array(
				'label'               => __( 'Upload an image to the Media Library', 'wp-seopress-pro' ),
				'description'         => __( 'Download an image from an external URL and add it to the WordPress Media Library. Returns the attachment ID and local URL.', 'wp-seopress-pro' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'image_url'   => array(
							'type'        => 'string',
							'description' => __( 'The external URL of the image to download.', 'wp-seopress-pro' ),
						),
						'post_id'     => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Optional post ID to attach the image to.', 'wp-seopress-pro' ),
						),
						'alt_text'    => array( 'type' => 'string' ),
						'caption'     => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
					),
					'required'             => array( 'image_url' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'The created or matched attachment.', 'wp-seopress-pro' ),
				),
				'permission_callback' => function () {
					return current_user_can( 'upload_files' );
				},
				'execute_callback'    => array( $this, 'uploadImage' ),
				'meta'                => $this->abilityMeta(
					array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					)
				),
			)
		);
	}

	/**
	 * Permission callback for per-post abilities.
	 *
	 * @since 9.9.0
	 *
	 * @param array $input The ability input.
	 *
	 * @return bool|\WP_Error
	 */
	public function canEditPost( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( $post_id < 1 || ! get_post( $post_id ) ) {
			return new \WP_Error(
				'seopress_ability_invalid_post',
				__( 'The provided post does not exist.', 'wp-seopress-pro' )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'seopress_ability_forbidden',
				__( 'You are not allowed to edit this post.', 'wp-seopress-pro' )
			);
		}

		return true;
	}

	/**
	 * Generate an AI SEO title suggestion for a post.
	 *
	 * @since 10.0.0
	 *
	 * @param array $input The ability input.
	 *
	 * @return array|\WP_Error
	 */
	public function generateTitle( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$lang    = ! empty( $input['lang'] ) ? sanitize_text_field( $input['lang'] ) : '';

		$result = seopress_pro_get_service( 'Completions' )->generateTitlesDesc( $post_id, 'title', $lang );

		if ( ! empty( $result['title'] ) ) {
			return array( 'title' => $result['title'] );
		}

		return new \WP_Error(
			'seopress_ability_generation_failed',
			! empty( $result['message'] ) ? $result['message'] : __( 'The title could not be generated.', 'wp-seopress-pro' )
		);
	}

	/**
	 * Generate an AI meta description suggestion for a post.
	 *
	 * @since 10.0.0
	 *
	 * @param array $input The ability input.
	 *
	 * @return array|\WP_Error
	 */
	public function generateDescription( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$lang    = ! empty( $input['lang'] ) ? sanitize_text_field( $input['lang'] ) : '';

		$result = seopress_pro_get_service( 'Completions' )->generateTitlesDesc( $post_id, 'desc', $lang );

		if ( ! empty( $result['desc'] ) ) {
			return array( 'description' => $result['desc'] );
		}

		return new \WP_Error(
			'seopress_ability_generation_failed',
			! empty( $result['message'] ) ? $result['message'] : __( 'The meta description could not be generated.', 'wp-seopress-pro' )
		);
	}

	/**
	 * Generate an AI alt text and caption for an image attachment.
	 *
	 * @since 10.0.0
	 *
	 * @param array $input The ability input.
	 *
	 * @return array|\WP_Error
	 */
	public function generateAltText( $input ) {
		$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;

		$result = seopress_pro_get_service( 'Completions' )->generateImgAltText( $attachment_id, 'alt_text' );

		if ( ! empty( $result['alt_text'] ) ) {
			return array(
				'alt_text' => $result['alt_text'],
				'caption'  => isset( $result['caption'] ) ? $result['caption'] : '',
			);
		}

		return new \WP_Error(
			'seopress_ability_generation_failed',
			! empty( $result['message'] ) ? $result['message'] : __( 'The alt text could not be generated.', 'wp-seopress-pro' )
		);
	}

	/**
	 * Update the target keywords used for content analysis.
	 *
	 * @since 10.0.0
	 *
	 * @param array $input The ability input.
	 *
	 * @return array
	 */
	public function updateTargetKeywords( $input ) {
		$post_id  = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$keywords = isset( $input['keywords'] ) ? sanitize_text_field( $input['keywords'] ) : '';

		update_post_meta( $post_id, '_seopress_analysis_target_kw', $keywords );

		return array( 'success' => true );
	}

	/**
	 * Download an external image and add it to the Media Library.
	 *
	 * @since 10.0.0
	 *
	 * @param array $input The ability input.
	 *
	 * @return array|\WP_Error
	 */
	public function uploadImage( $input ) {
		$image_url = isset( $input['image_url'] ) ? esc_url_raw( $input['image_url'] ) : '';
		$post_id   = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$alt_text  = isset( $input['alt_text'] ) ? sanitize_text_field( $input['alt_text'] ) : '';
		$caption   = isset( $input['caption'] ) ? sanitize_text_field( $input['caption'] ) : '';
		$desc      = isset( $input['description'] ) ? sanitize_textarea_field( $input['description'] ) : '';

		if ( empty( $image_url ) || 0 !== strpos( $image_url, 'http' ) ) {
			return new \WP_Error(
				'seopress_ability_invalid_url',
				__( 'A valid HTTP/HTTPS image URL is required.', 'wp-seopress-pro' )
			);
		}

		// If the image already lives on this site, reuse the existing attachment.
		$upload_dir = wp_get_upload_dir();
		if ( ! empty( $upload_dir['baseurl'] ) && 0 === strpos( $image_url, $upload_dir['baseurl'] ) ) {
			$existing_id = attachment_url_to_postid( $image_url );
			if ( $existing_id ) {
				// Only mutate an existing attachment's metadata if the user can edit it;
				// the upload_files capability alone must not allow editing arbitrary media.
				if ( ! current_user_can( 'edit_post', $existing_id ) ) {
					$alt_text = '';
					$caption  = '';
				}

				return $this->attachmentPayload( $existing_id, $alt_text, $caption );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// WordPress validates the file type and size during the sideload.
		$attachment_id = media_sideload_image( $image_url, $post_id, $desc, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return $this->attachmentPayload( $attachment_id, $alt_text, $caption );
	}

	/**
	 * Apply alt text / caption and build the response payload for an attachment.
	 *
	 * @since 10.0.0
	 *
	 * @param int    $attachment_id The attachment ID.
	 * @param string $alt_text      Optional alt text to store.
	 * @param string $caption       Optional caption to store.
	 *
	 * @return array
	 */
	protected function attachmentPayload( $attachment_id, $alt_text = '', $caption = '' ) {
		$attachment_id = (int) $attachment_id;

		if ( '' !== $alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		if ( '' !== $caption ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => $caption,
				)
			);
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		return array(
			'success'       => true,
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'width'         => isset( $metadata['width'] ) ? (int) $metadata['width'] : 0,
			'height'        => isset( $metadata['height'] ) ? (int) $metadata['height'] : 0,
		);
	}

	/**
	 * Wrap an existing SEOPress PRO REST controller and adapt its response.
	 *
	 * Both query/body params are populated so controllers using
	 * get_param()/get_params() and get_json_params() behave identically.
	 *
	 * @since 9.9.0
	 *
	 * @param object $controller  The controller instance.
	 * @param string $method      The controller method to call.
	 * @param string $http_method The simulated HTTP method.
	 * @param array  $params      The request parameters.
	 *
	 * @return mixed|\WP_Error
	 */
	protected function runController( $controller, $method, $http_method, $params = array() ) {
		$request = new \WP_REST_Request( $http_method );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		if ( ! empty( $params ) ) {
			$request->add_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}

		try {
			$response = $controller->$method( $request );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'seopress_ability_execution_failed',
				__( 'The SEOPress action could not be completed.', 'wp-seopress-pro' )
			);
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response instanceof \WP_REST_Response ) {
			$status = $response->get_status();
			if ( $status >= 400 ) {
				return new \WP_Error(
					'seopress_ability_execution_failed',
					__( 'The SEOPress action returned an error.', 'wp-seopress-pro' ),
					array( 'status' => $status )
				);
			}

			return $response->get_data();
		}

		return $response;
	}

	/**
	 * Build the "meta" array of an ability.
	 *
	 * Delegates to the free plugin helper, which owns the exposure rules
	 * (meta.mcp.public for MCP clients, meta.show_in_rest for the REST API
	 * on WordPress 6.9/7.0). The fallback covers Pro running alongside a free
	 * version that predates the helper, and stays disabled by default.
	 *
	 * @since 10.2.0
	 *
	 * @param array $annotations The MCP annotations.
	 *
	 * @return array
	 */
	protected function abilityMeta( $annotations ) {
		if ( function_exists( 'seopress_abilities_api_meta' ) ) {
			return seopress_abilities_api_meta( $annotations );
		}

		$exposed = function_exists( 'seopress_abilities_api_rest_enabled' ) && seopress_abilities_api_rest_enabled();

		return array(
			'public'       => $exposed,
			'show_in_rest' => $exposed,
			'mcp'          => array(
				'public' => $exposed,
				'type'   => 'tool',
			),
			'annotations'  => $annotations,
		);
	}

	/**
	 * Reusable { id } input schema.
	 *
	 * @since 9.9.0
	 *
	 * @param string $description The id description.
	 *
	 * @return array
	 */
	protected function idSchema( $description ) {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => $description,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Reusable { post_id } input schema.
	 *
	 * @since 9.9.0
	 *
	 * @return array
	 */
	protected function postIdSchema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The ID of the post.', 'wp-seopress-pro' ),
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}
}
