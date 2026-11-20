<?php
/**
 * Bricks Builder form lookups.
 *
 * Bricks keeps a page's elements in post meta (content, header and footer
 * areas), each element an array with `id`, `name` and `settings`. A form is an
 * element named `form`; its fields are the `settings.fields` repeater.
 *
 * @since 1.5.4
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Bricks;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Utils class.
 */
class Utils {

	/**
	 * Post meta keys Bricks stores element trees under (header, content, footer).
	 *
	 * The theme defines constants for these; the literal values are the fallback
	 * for requests where the theme has not loaded (REST before `after_setup_theme`,
	 * tests).
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		return array(
			defined( 'BRICKS_DB_PAGE_CONTENT' ) ? BRICKS_DB_PAGE_CONTENT : '_bricks_page_content_2',
			defined( 'BRICKS_DB_PAGE_HEADER' ) ? BRICKS_DB_PAGE_HEADER : '_bricks_page_header_2',
			defined( 'BRICKS_DB_PAGE_FOOTER' ) ? BRICKS_DB_PAGE_FOOTER : '_bricks_page_footer_2',
		);
	}

	/**
	 * Published pages, posts and Bricks templates that hold at least one form.
	 *
	 * @return array<int, string> Post ID => title.
	 */
	public static function get_pages() {
		$meta_query = array( 'relation' => 'OR' );
		foreach ( self::meta_keys() as $key ) {
			// Serialized element arrays: `"name";s:4:"form";`.
			$meta_query[] = array(
				'key'     => $key,
				'compare' => 'LIKE',
				'value'   => '"name";s:4:"form"',
			);
		}

		$query = new WP_Query(
			array(
				// 'any' skips types hidden from search, which can include Bricks templates.
				'post_type'      => array_values( array_unique( array_merge( get_post_types( array( 'public' => true ) ), array( 'bricks_template' ) ) ) ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only picker.
			)
		);

		$pages = array();
		foreach ( $query->posts as $post ) {
			$title = get_the_title( $post );
			if ( '' === $title ) {
				/* translators: %d: post ID */
				$title = sprintf( __( '(no title) #%d', 'doublescale' ), $post->ID );
			}
			$pages[ $post->ID ] = $title;
		}

		return $pages;
	}

	/**
	 * Every form element stored on a post, across all three areas.
	 *
	 * @param int $post_id Post or template ID.
	 * @return array<int, array> Form elements.
	 */
	public static function get_form_elements( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return array();
		}

		$forms = array();
		foreach ( self::meta_keys() as $key ) {
			$elements = get_post_meta( $post_id, $key, true );
			if ( ! is_array( $elements ) ) {
				continue;
			}
			foreach ( $elements as $element ) {
				if ( is_array( $element ) && 'form' === ( $element['name'] ?? '' ) && ! empty( $element['id'] ) ) {
					$forms[] = $element;
				}
			}
		}

		return $forms;
	}

	/**
	 * Forms on a post, labelled so two forms on one page can be told apart.
	 *
	 * @param int $post_id Post or template ID.
	 * @return array<string, string> Element ID => label.
	 */
	public static function get_forms_by_page_id( $post_id ) {
		$forms = array();
		foreach ( self::get_form_elements( $post_id ) as $element ) {
			$forms[ (string) $element['id'] ] = self::get_form_label( $element );
		}

		return $forms;
	}

	/**
	 * One form element by its element ID.
	 *
	 * @param string $form_id Element ID.
	 * @param int    $page_id Post or template ID.
	 * @return array Element, or an empty array.
	 */
	public static function get_form( $form_id, $page_id ) {
		foreach ( self::get_form_elements( $page_id ) as $element ) {
			if ( (string) $element['id'] === (string) $form_id ) {
				return $element;
			}
		}

		return array();
	}

	/**
	 * A form's name: the label set in Bricks' structure panel, else its fields.
	 *
	 * @param array $element Form element.
	 * @return string
	 */
	public static function get_form_label( $element ) {
		$id    = (string) ( $element['id'] ?? '' );
		$label = trim( (string) ( $element['label'] ?? '' ) );
		if ( '' !== $label ) {
			return sprintf( '%s (#%s)', $label, $id );
		}

		$names = array();
		foreach ( (array) ( $element['settings']['fields'] ?? array() ) as $field ) {
			$name = self::get_field_label( (array) $field );
			if ( '' !== $name ) {
				$names[] = $name;
			}
			if ( count( $names ) >= 3 ) {
				break;
			}
		}

		if ( empty( $names ) ) {
			/* translators: %s: Bricks element ID */
			return sprintf( __( 'Form #%s', 'doublescale' ), $id );
		}

		/* translators: 1: first field names, 2: Bricks element ID */
		return sprintf( __( 'Form: %1$s (#%2$s)', 'doublescale' ), implode( ', ', $names ), $id );
	}

	/**
	 * A field's display name: label, else placeholder, else its ID.
	 *
	 * @param array $field Field from the form's `fields` repeater.
	 * @return string
	 */
	public static function get_field_label( $field ) {
		foreach ( array( 'label', 'placeholder', 'name' ) as $key ) {
			$value = trim( wp_strip_all_tags( (string) ( $field[ $key ] ?? '' ) ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return (string) ( $field['id'] ?? '' );
	}
}
