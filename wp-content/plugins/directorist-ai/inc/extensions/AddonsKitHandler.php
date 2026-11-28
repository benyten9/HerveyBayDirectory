<?php
/** Protect AddonsKit's own field dropdown from directories without a form schema. */
namespace DirectoristAIPro\Extensions;

final class AddonsKitHandler extends NativeExtensionHandler {
	private static array $omitted = array();
	private static bool $reading = false;

	public function __construct() {
		parent::__construct( 'addonskit', 'addonskit-for-elementor/addonskit-for-elementor.php', array( 'elementor_dynamic_fields' ), 'scoped_compatibility' );
	}

	public function hooks(): void {
		add_filter( 'get_terms', array( $this, 'field_directories' ), 100, 4 );
		add_filter( 'get_term_metadata', array( $this, 'safe_field_schema' ), PHP_INT_MAX, 5 );
	}

	/** Normal Directorist term queries and stored metadata are never changed. */
	public function field_directories( $terms, $taxonomies, $args, $query ) {
		if ( ! is_array( $terms ) || ! defined( 'ATBDP_DIRECTORY_TYPE' ) || ! in_array( ATBDP_DIRECTORY_TYPE, (array) $taxonomies, true ) || ! ExtensionRegistry::active_plugin( $this->plugin() ) || ! $this->addon_field_inventory() ) {
			return $terms;
		}
		$result = array();
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->term_id ) ) { return $terms; }
			$schema = get_term_meta( (int) $term->term_id, 'submission_form_fields', true );
			if ( self::valid_form_schema( $schema ) ) {
				$result[] = $term;
				continue;
			}
			$blog = get_current_blog_id();
			self::$omitted[ $blog ][ (int) $term->term_id ] = 'invalid_form_schema';
			do_action( 'directorist_ai_pro_extension_omitted', $this->id(), (int) $term->term_id, 'invalid_form_schema' );
		}
		return $result;
	}

	public static function omissions(): array { return self::$omitted[ get_current_blog_id() ] ?? array(); }

	/** Validate only the nested data actually consumed by AddonsKit's dropdowns. */
	public static function valid_form_schema( $schema ): bool {
		if ( ! is_array( $schema ) || ! is_array( $schema['groups'] ?? null ) || ! is_array( $schema['fields'] ?? null ) ) { return false; }
		foreach ( $schema['groups'] as $group ) {
			if ( ! is_array( $group ) || ! is_array( $group['fields'] ?? null ) ) { return false; }
			foreach ( $group['fields'] as $id ) {
				if ( ( ! is_string( $id ) && ! is_int( $id ) ) || '' === (string) $id || ! is_array( $schema['fields'][ $id ] ?? null ) ) { return false; }
				$field = $schema['fields'][ $id ];
				if ( ! is_string( $field['label'] ?? null ) || ! is_string( $field['widget_name'] ?? null ) || '' === $field['widget_name'] || ( isset( $field['type'] ) && ! is_string( $field['type'] ) ) || ( isset( $field['widget_key'] ) && ! is_string( $field['widget_key'] ) ) ) { return false; }
			}
		}
		return true;
	}

	/**
	 * AddonsKit's single-directory branch reads its form directly rather than
	 * enumerating terms. Repair only that exact read, never stored/global metadata.
	 * The metadata prefilter wraps a single array value in one outer array.
	 */
	public function safe_field_schema( $check, $term_id, $key, $single, $meta_type = 'term' ) {
		if ( self::$reading || 'term' !== $meta_type || 'submission_form_fields' !== $key || true !== $single || ! ExtensionRegistry::active_plugin( $this->plugin() ) || ! $this->addon_field_leaf() ) { return $check; }
		self::$reading = true;
		try {
			$from_default = false;
			if ( null !== $check ) {
				$schema = is_array( $check ) ? ( $check[0] ?? null ) : $check;
			} else {
				// Read the same native cache/default value that WordPress would use
				// after this filter. Do not recursively invoke other metadata filters.
				$metadata = wp_cache_get( (int) $term_id, 'term_meta' );
				if ( ! $metadata ) { $filled = update_meta_cache( 'term', array( (int) $term_id ) ); $metadata = $filled[ (int) $term_id ] ?? array(); }
				$from_default = ! isset( $metadata[ $key ][0] );
				$schema = $from_default ? get_metadata_default( 'term', (int) $term_id, $key, true ) : maybe_unserialize( $metadata[ $key ][0] );
			}
			if ( self::valid_form_schema( $schema ) ) { return $from_default ? array( $schema ) : $check; }
			self::$omitted[ get_current_blog_id() ][ (int) $term_id ] = 'invalid_form_schema';
			do_action( 'directorist_ai_pro_extension_omitted', $this->id(), (int) $term_id, 'invalid_form_schema' );
			return array( array( 'groups' => array(), 'fields' => array() ) );
		} finally { self::$reading = false; }
	}

	private function addon_field_leaf(): bool {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) {
			if ( 'AddonskitForElementor\\Utils\\DirectoristHelper' === ( $frame['class'] ?? null ) && in_array( $frame['function'] ?? '', array( 'get_preset_fields', 'get_custom_fields', 'get_custom_color_field' ), true ) ) { return true; }
		}
		return false;
	}

	private function addon_field_inventory(): bool {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 18 ) as $frame ) {
			if ( 'AddonskitForElementor\\Utils\\DirectoristHelper' === ( $frame['class'] ?? null ) && in_array( $frame['function'] ?? '', array( 'get_group_fields', 'get_custom_group_fields', 'get_custom_color_group_fields' ), true ) ) {
				return true;
			}
		}
		return false;
	}
}
