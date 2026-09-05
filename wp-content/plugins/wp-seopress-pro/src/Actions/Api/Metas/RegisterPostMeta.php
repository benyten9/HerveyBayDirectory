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
	 * Every meta key this class registers.
	 *
	 * Kept so register_restricted_meta_keys() stays in sync with the
	 * registrations automatically rather than through a second list that
	 * would drift.
	 *
	 * @since 10.2.0
	 *
	 * @var string[]
	 */
	protected $registered_meta_keys = array();

	/**
	 * Keys whose registered schema is `array` of objects, mapped to the
	 * normalizer that makes an unusable stored value fit it again.
	 *
	 * @since 10.2.0
	 *
	 * @var array<string, string>
	 */
	const STRUCTURED_META_KEYS = array(
		'_seopress_pro_schemas_manual'        => 'array_of_objects',
		'_seopress_video'                     => 'array_of_objects',
		'_seopress_pro_schemas'               => 'object',
		'_seopress_pro_rich_snippets_disable' => 'object',
	);

	/**
	 * Guards normalize_structured_meta() against re-entering itself.
	 *
	 * Reading the raw value goes through `get_post_metadata` again, which is
	 * the very filter we are inside of.
	 *
	 * @var array<string, bool>
	 */
	protected $reading_raw = array();

	/**
	 * Register all Pro post-meta keys with `show_in_rest`.
	 *
	 * @return void
	 */
	public function hooks() {
		// Repair on read: a value stored in a shape its schema rejects makes
		// the post unsaveable, and nothing in the admin can clear it.
		add_filter( 'get_post_metadata', array( $this, 'normalize_structured_meta' ), 10, 4 );

		// Google News — disabled flag.
		$this->register_string_meta( '_seopress_news_disabled' );

		// Video Sitemap — disabled flag.
		$this->register_string_meta( '_seopress_video_disabled' );

		// Video Sitemap — array of video objects. Schema is intentionally
		// permissive (`additionalProperties: true`) because the field set
		// is data-driven (server emits `fields_video` from the Pro service)
		// and may include strings, integers and yes/empty flags.
		$this->register_meta_key(
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
		$this->register_meta_key(
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
		$this->register_meta_key(
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
		$this->register_meta_key(
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

		add_filter( 'seopress_restricted_meta_keys', array( $this, 'register_restricted_meta_keys' ) );
	}

	/**
	 * Hand back a usable value for a key whose stored one no longer fits its
	 * registered schema.
	 *
	 * When the Block Editor saves, it sends every registered meta and sends
	 * `null` for the ones it holds nothing for. Before deleting such a key,
	 * WordPress validates the value *already in the database* against the
	 * registered schema (`WP_REST_Meta_Fields::update_value()`). A value that
	 * fails answers 500 and the whole post update is rejected — SEO metabox
	 * or not, title change or not. The post becomes impossible to save, and
	 * nothing in the admin can clear the offending key, because clearing it
	 * is the operation that fails.
	 *
	 * These keys hold structured data, so anything writing them as a plain
	 * string (a CSV or XML importer with a column mapped onto the key, a
	 * migration script, a staging sync) leaves the post in that state.
	 *
	 * Only a value that exists AND is unusable is replaced. An absent key
	 * returns null so core keeps its own behaviour, and a healthy value is
	 * left strictly alone, so a site that never hit this sees no change.
	 *
	 * @since 10.2.0
	 *
	 * @param mixed  $value     Short-circuit value, null unless another filter set one.
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Meta key being read.
	 * @param bool   $single    Whether a single value was asked for.
	 *
	 * @return mixed Null to let WordPress read normally, the repaired value otherwise.
	 */
	public function normalize_structured_meta( $value, $object_id, $meta_key, $single ) {
		// Another filter already answered, or the key is none of ours.
		if ( null !== $value || ! isset( self::STRUCTURED_META_KEYS[ $meta_key ] ) ) {
			return $value;
		}

		$guard = $object_id . '|' . $meta_key . '|' . ( $single ? '1' : '0' );

		if ( isset( $this->reading_raw[ $guard ] ) ) {
			return $value;
		}

		$this->reading_raw[ $guard ] = true;
		$stored                      = get_metadata_raw( 'post', $object_id, $meta_key, $single );
		unset( $this->reading_raw[ $guard ] );

		// Key absent: nothing to repair, and returning a value here would
		// hide the difference between "not set" and "set to empty".
		if ( null === $stored ) {
			return $value;
		}

		$shape = self::STRUCTURED_META_KEYS[ $meta_key ];

		if ( $single ) {
			if ( $this->is_usable_meta( $stored, $shape ) ) {
				return $value;
			}

			// A short-circuit answer to a single read is unwrapped by core
			// (`return $check[0]` in get_metadata_raw()), so the repaired
			// value travels inside a one-entry list. Returning it bare would
			// hand back its first row, or raise a notice when it is empty.
			return array( $this->normalize_meta_value( $stored, $shape ) );
		}

		// Non-single reads answer a list of values; repair the entries that
		// need it and hand the list back only if one actually changed.
		if ( ! is_array( $stored ) ) {
			return $value;
		}

		$repaired = false;

		foreach ( $stored as $index => $entry ) {
			if ( ! $this->is_usable_meta( $entry, $shape ) ) {
				$stored[ $index ] = $this->normalize_meta_value( $entry, $shape );
				$repaired         = true;
			}
		}

		return $repaired ? $stored : $value;
	}

	/**
	 * Whether a stored value already satisfies the shape its key registered.
	 *
	 * Mirrors what `rest_validate_value_from_schema()` accepts for the two
	 * schemas used above, without paying for the full validator on a read
	 * that happens on every front-end render.
	 *
	 * @param mixed  $value The stored value.
	 * @param string $shape 'array_of_objects' or 'object'.
	 *
	 * @return bool
	 */
	protected function is_usable_meta( $value, $shape ) {
		// An empty string is what an emptied key reads as, and both schemas
		// accept it.
		if ( '' === $value ) {
			return true;
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		if ( 'object' === $shape ) {
			return true;
		}

		// array_of_objects: a numerically indexed list whose entries are all
		// arrays. Non-numeric keys make it an object, a scalar entry makes it
		// a list of scalars; the schema rejects both.
		foreach ( $value as $key => $entry ) {
			if ( ! is_int( $key ) || ! is_array( $entry ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Coerce an unusable stored value into the shape its key registered.
	 *
	 * Rows that are still readable are kept: an assignment map that merely
	 * lost its numeric keys comes back intact rather than being dropped.
	 * Anything that carries no structure at all resolves to an empty value,
	 * which is what the key reads as when it was never set.
	 *
	 * @param mixed  $value The stored value.
	 * @param string $shape 'array_of_objects' or 'object'.
	 *
	 * @return array
	 */
	protected function normalize_meta_value( $value, $shape ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		if ( 'object' === $shape ) {
			return $value;
		}

		return array_values( array_filter( $value, 'is_array' ) );
	}

	/**
	 * Declare the Pro metabox keys to the Free role-restriction mechanism.
	 *
	 * The restriction cannot be enforced from `meta_auth()`: `core/editor`
	 * sends every registered meta as a single object as soon as any one of
	 * them changes, so denying there rejects the whole request and a user
	 * restricted from the SEO metabox can no longer save the post at all.
	 * Free short-circuits the meta write instead, which drops the value and
	 * lets the save through; feeding the keys into that same list keeps a
	 * single implementation of the drop logic across both plugins.
	 *
	 * @since 10.2.0
	 *
	 * @param array<string, string> $keys Meta key => restriction area.
	 *
	 * @return array<string, string>
	 */
	public function register_restricted_meta_keys( $keys ) {
		// Every Pro key registered here belongs to the SEO metabox area.
		foreach ( $this->registered_meta_keys as $key ) {
			$keys[ $key ] = 'GLOBAL';
		}

		return $keys;
	}

	/**
	 * Register a post meta key and record it, so the restricted-keys list
	 * cannot drift away from what is actually registered.
	 *
	 * @param string $post_type Post type, empty string for all.
	 * @param string $key       Meta key.
	 * @param array  $args      register_post_meta() arguments.
	 *
	 * @return void
	 */
	protected function register_meta_key( $post_type, $key, $args ) {
		$this->registered_meta_keys[] = $key;

		register_post_meta( $post_type, $key, $args );
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
		$this->register_meta_key(
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
		// Deliberately does not enforce the Advanced > Security role
		// restrictions. Denying here fails the whole request rather than the
		// field: core/editor sends every registered meta as one object as soon
		// as any of them changes, so a restricted user who merely typed a
		// target keyword could no longer save the post at all, with an error
		// naming an internal meta key. The keys are declared to Free through
		// `seopress_restricted_meta_keys` instead, and the write is dropped
		// there while the save goes through.
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
	 * Leaf sanitizer for sanitize_schemas_manual(): only a JSON-LD `<script>`
	 * tag survives; everything else is reduced to a sanitized scalar.
	 *
	 * @param mixed $value Leaf value.
	 *
	 * @return mixed
	 */
	public function sanitize_schemas_manual_leaf( $value ) {
		if ( is_array( $value ) ) {
			return map_deep( $value, array( $this, 'sanitize_schemas_manual_leaf' ) );
		}

		return seopress_pro_kses_json_ld( $value );
	}
}
