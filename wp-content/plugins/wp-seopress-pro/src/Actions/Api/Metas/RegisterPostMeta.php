<?php // phpcs:ignore

namespace SEOPressPro\Actions\Api\Metas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * Register the Pro post-meta keys with `show_in_rest` so a Block Editor
 * "Update" persists the Pro metabox tabs via `/wp/v2/<type>/<id>`. Mirrors
 * what `SEOPress\Actions\Api\Metas\AdvancedSettings` does in the free
 * plugin for the free tabs.
 *
 * On the React side, each Pro tab renders `<SyncMetaToEditor/>` (shipped
 * by Free) which mirrors its Formik state into Gutenberg's `core/editor`
 * meta on every change. The meta keys MUST therefore be exposed via
 * `show_in_rest` with a matching schema so Gutenberg's save POST validates
 * and persists them. Sanitizers below mirror the dedicated Pro REST
 * endpoints (GoogleNewsSettings::processPut, VideoSitemap::processPut,
 * SchemaManual::processPut, SchemaAutomaticPerPost::processPut) so both
 * code paths converge on the same DB value.
 *
 * @since 9.8.4
 */
class RegisterPostMeta implements ExecuteHooks {

	/**
	 * Register all Pro post-meta keys with `show_in_rest`.
	 *
	 * @return void
	 */
	public function hooks() {
		// Google News — disabled flag.
		$this->register_string_meta( '_seopress_news_disabled' );

		// Video Sitemap — disabled flag.
		$this->register_string_meta( '_seopress_video_disabled' );

		// Video Sitemap — array of video objects. Schema is intentionally
		// permissive (`additionalProperties: true`) because the field set
		// is data-driven (server emits `fields_video` from the Pro service)
		// and may include strings, integers and yes/empty flags.
		register_post_meta(
			'',
			'_seopress_video',
			array(
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					),
				),
				'single'            => true,
				'type'              => 'array',
				'auth_callback'     => array( $this, 'meta_auth' ),
				'sanitize_callback' => array( $this, 'sanitize_array_deep' ),
			)
		);

		// Schemas Manual — array of schema rows. One row's field may carry
		// raw JSON-LD wrapped in <script type="application/ld+json"> and
		// must keep its tags after sanitization.
		register_post_meta(
			'',
			'_seopress_pro_schemas_manual',
			array(
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					),
				),
				'single'            => true,
				'type'              => 'array',
				'auth_callback'     => array( $this, 'meta_auth' ),
				'sanitize_callback' => array( $this, 'sanitize_schemas_manual' ),
			)
		);

		// Schemas Automatic — disable-all flag.
		$this->register_string_meta( '_seopress_pro_rich_snippets_disable_all' );

		// Schemas Automatic — per-schema disable map (object keyed by schemaId).
		register_post_meta(
			'',
			'_seopress_pro_rich_snippets_disable',
			array(
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
				'single'            => true,
				'type'              => 'object',
				'auth_callback'     => array( $this, 'meta_auth' ),
				'sanitize_callback' => array( $this, 'sanitize_array_deep' ),
			)
		);

		// Schemas Automatic — overrides map. Three-level nested object:
		// schemaId → section → fieldKey → value. May hold custom JSON-LD.
		register_post_meta(
			'',
			'_seopress_pro_schemas',
			array(
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
				'single'            => true,
				'type'              => 'object',
				'auth_callback'     => array( $this, 'meta_auth' ),
				'sanitize_callback' => array( $this, 'sanitize_schemas_manual' ),
			)
		);
	}

	/**
	 * Register a scalar text meta key with the standard auth callback and
	 * `sanitize_text_field` as the sanitizer.
	 *
	 * @param string $key Meta key.
	 *
	 * @return void
	 */
	protected function register_string_meta( $key ) {
		register_post_meta(
			'',
			$key,
			array(
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
				'auth_callback'     => array( $this, 'meta_auth' ),
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
	}

	/**
	 * Auth callback shared by every Pro meta key registered here.
	 *
	 * @param bool   $allowed  Default decision from WP.
	 * @param string $meta_key Meta key being checked.
	 * @param int    $id       Post ID.
	 *
	 * @return bool
	 */
	public function meta_auth( $allowed, $meta_key, $id ) { // phpcs:ignore
		// Enforce the Advanced > Security role restrictions on the Gutenberg
		// native save path (core/editor persists these registered metas via
		// /wp/v2/<type>/<id>). Every Pro key registered here belongs to the
		// SEO metabox (GLOBAL) area.
		if ( function_exists( 'seopress_metabox_role_is_blocked' ) && seopress_metabox_role_is_blocked( 'GLOBAL' ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $id );
	}

	/**
	 * Sanitize an arbitrarily nested array/scalar with `sanitize_text_field`.
	 * Mirrors the helper used by the Classic Editor fallback so the two save
	 * paths produce identical DB values.
	 *
	 * @param mixed $value Scalar or nested array.
	 *
	 * @return mixed
	 */
	public function sanitize_array_deep( $value ) {
		if ( is_array( $value ) ) {
			return map_deep( $value, 'sanitize_text_field' );
		}

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Sanitize a single field value inside a schemas-manual / schemas-
	 * overrides structure. Matches the behaviour of
	 * SchemaManual::processPut(): the JSON-LD custom field is allowed to
	 * keep its `<script type="application/ld+json">` wrapper; every other
	 * value falls through to scalar / nested-array sanitization.
	 *
	 * Recurses through arrays so it can be used as the top-level meta
	 * sanitize_callback (the value entering here is the full schemas array).
	 *
	 * @param mixed $value Leaf scalar or nested array.
	 *
	 * @return mixed
	 */
	public function sanitize_schemas_manual( $value ) {
		if ( is_array( $value ) ) {
			return map_deep( $value, array( $this, 'sanitize_schemas_manual_leaf' ) );
		}

		return $this->sanitize_schemas_manual_leaf( $value );
	}

	/**
	 * Leaf sanitizer for sanitize_schemas_manual(): only `<script>` tags with
	 * a `type` attribute survive; everything else is reduced to a sanitized
	 * scalar.
	 *
	 * @param mixed $value Leaf value.
	 *
	 * @return mixed
	 */
	public function sanitize_schemas_manual_leaf( $value ) {
		if ( is_array( $value ) ) {
			return map_deep( $value, array( $this, 'sanitize_schemas_manual_leaf' ) );
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return wp_kses( (string) $value, array( 'script' => array( 'type' => array() ) ) );
	}
}
