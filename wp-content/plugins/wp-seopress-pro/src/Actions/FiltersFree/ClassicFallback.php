<?php

namespace SEOPressPro\Actions\FiltersFree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * Register the Pro post-meta keys saved by the free plugin's Classic
 * Editor `save_post` fallback (introduced in wp-seopress 9.8). When the
 * REST API is blocked, the React metabox tabs render hidden inputs
 * mirroring their Formik state inside `<form id="post">`; the free
 * `ModuleMetabox::saveClassicEditorMetaFallback()` reads them and writes
 * the matching post_meta. The free handler ships a default map with the
 * free fields; this class adds Schemas Manual, Schemas Automatic,
 * Google News, and Video Sitemap so Pro tabs survive the same scenario.
 *
 * Sanitizers below mirror the REST endpoints' behaviour:
 *   - SchemaManual::processPut         → wp_kses for JSON-LD <script>,
 *                                        sanitize_text_field elsewhere
 *   - SchemaAutomaticPerPost::processPut → per-field sanitize via the
 *                                        type registered by the schema
 *   - GoogleNewsSettings::processPut   → 'yes' or delete
 *   - VideoSitemap::processPut         → map_deep + sanitize_text_field
 */
class ClassicFallback implements ExecuteHooks {

	/**
	 * Wire the filter that extends the free Classic Editor save fallback
	 * meta keys map.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'seopress_metabox_classic_fallback_meta_keys', array( $this, 'registerProMetaKeys' ) );
	}

	/**
	 * Append the Pro post-meta keys to the fallback map.
	 *
	 * @param array<string, mixed> $keys Free meta key => sanitizer spec.
	 *
	 * @return array<string, mixed>
	 */
	public function registerProMetaKeys( $keys ) {
		// Google News — single string flag.
		$keys['_seopress_news_disabled'] = 'text';

		// Video Sitemap.
		$keys['_seopress_video_disabled'] = 'text';
		$keys['_seopress_video']          = array( 'json', array( $this, 'sanitizeNestedScalarArray' ) );

		// Schemas Manual — array of schema rows; one field per row may
		// hold raw JSON-LD inside a <script> tag and needs wp_kses.
		$keys['_seopress_pro_schemas_manual'] = array( 'json', array( $this, 'sanitizeSchemaManualField' ) );

		// Schemas Automatic — three meta keys, the last being a multi-
		// dimensional override map keyed by schema id, section, field key
		// that may contain custom JSON-LD overrides.
		$keys['_seopress_pro_rich_snippets_disable_all'] = 'text';
		$keys['_seopress_pro_rich_snippets_disable']     = array( 'json', array( $this, 'sanitizeNestedScalarArray' ) );
		$keys['_seopress_pro_schemas']                   = array( 'json', array( $this, 'sanitizeSchemaManualField' ) );

		return $keys;
	}

	/**
	 * Sanitize an arbitrarily nested array of scalars with
	 * `sanitize_text_field`. Used by everything that is not the JSON-LD
	 * custom field.
	 *
	 * @param mixed $value Scalar or array of scalars.
	 *
	 * @return mixed
	 */
	public function sanitizeNestedScalarArray( $value ) {
		if ( is_array( $value ) ) {
			return map_deep( $value, 'sanitize_text_field' );
		}

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Sanitize a single field value inside a schemas-manual row, matching
	 * SchemaManual::processPut(). The custom JSON-LD field is allowed to
	 * keep its `<script type="application/ld+json">` wrapper; everything
	 * else falls through to scalar/array sanitization.
	 *
	 * NOTE: this sanitizer is invoked by `map_deep()` on the JSON-decoded
	 * structure and therefore receives leaf values without their key
	 * context. We can't tell here whether the value is the JSON-LD
	 * custom field or another text field. Allowing the JSON-LD wrapper
	 * everywhere is the only conservative choice that keeps the schema
	 * working on the fallback path; any other text passing through is
	 * still cleaned by `wp_kses` against a tiny allowlist.
	 *
	 * @param mixed $value Leaf value.
	 *
	 * @return mixed
	 */
	public function sanitizeSchemaManualField( $value ) {
		if ( is_array( $value ) ) {
			return map_deep( $value, array( $this, 'sanitizeSchemaManualField' ) );
		}

		return seopress_pro_kses_json_ld( $value );
	}
}
