<?php

namespace SEOPressPro\Helpers\Schemas;

defined( 'ABSPATH' ) || exit;

/** Shared Book fields and output validation for manual and automatic schemas. */
class Book {
	const PREFIX = '_seopress_pro_rich_snippets_book_';

	public static function fields() {
		return array(
			'name' => array(
				'label'       => __( 'Book title', 'wp-seopress-pro' ),
				'property'    => 'name',
				'description' => '',
			),
			'author' => array(
				'label'       => __( 'Author', 'wp-seopress-pro' ),
				'property'    => 'author.name',
				'description' => '',
			),
			'author_type' => array(
				'label'       => __( 'Author type', 'wp-seopress-pro' ),
				'property'    => 'author.@type',
				'description' => __( 'Person or Organization.', 'wp-seopress-pro' ),
			),
			'isbn' => array(
				'label'       => __( 'ISBN', 'wp-seopress-pro' ),
				'property'    => 'isbn',
				'description' => __( 'Keep leading zeros and hyphens.', 'wp-seopress-pro' ),
			),
			'edition' => array(
				'label'       => __( 'Edition', 'wp-seopress-pro' ),
				'property'    => 'bookEdition',
				'description' => '',
			),
			'format' => array(
				'label'       => __( 'Book format', 'wp-seopress-pro' ),
				'property'    => 'bookFormat',
				'description' => __( 'Hardcover, Paperback, EBook, AudiobookFormat, GraphicNovel or Pamphlet.', 'wp-seopress-pro' ),
			),
			'pages' => array(
				'label'       => __( 'Number of pages', 'wp-seopress-pro' ),
				'property'    => 'numberOfPages',
				'description' => __( 'Positive whole number.', 'wp-seopress-pro' ),
			),
			'language' => array(
				'label'       => __( 'Language', 'wp-seopress-pro' ),
				'property'    => 'inLanguage',
				'description' => __( 'Language code, for example en or fr.', 'wp-seopress-pro' ),
			),
			'date' => array(
				'label'       => __( 'Publication date', 'wp-seopress-pro' ),
				'property'    => 'datePublished',
				'description' => __( 'YYYY-MM-DD.', 'wp-seopress-pro' ),
			),
			'publisher' => array(
				'label'       => __( 'Publisher', 'wp-seopress-pro' ),
				'property'    => 'publisher.name',
				'description' => '',
			),
			'illustrator' => array(
				'label'       => __( 'Illustrator', 'wp-seopress-pro' ),
				'property'    => 'illustrator.name',
				'description' => '',
			),
			'abridged' => array(
				'label'       => __( 'Abridged', 'wp-seopress-pro' ),
				'property'    => 'abridged',
				'description' => __( 'true for abridged, false for unabridged. Leave empty if unknown.', 'wp-seopress-pro' ),
			),
			'price' => array(
				'label'       => __( 'Price', 'wp-seopress-pro' ),
				'property'    => 'offers.price',
				'description' => __( 'Use 0 for a free book.', 'wp-seopress-pro' ),
			),
			'currency' => array(
				'label'       => __( 'Currency', 'wp-seopress-pro' ),
				'property'    => 'offers.priceCurrency',
				'description' => __( 'ISO currency code, for example EUR or USD.', 'wp-seopress-pro' ),
			),
			'offer_url' => array(
				'label'       => __( 'Offer URL', 'wp-seopress-pro' ),
				'property'    => 'offers.url',
				'description' => '',
			),
			'image' => array(
				'label'       => __( 'Cover image URL', 'wp-seopress-pro' ),
				'property'    => 'image',
				'description' => '',
			),
			'url' => array(
				'label'       => __( 'Book URL', 'wp-seopress-pro' ),
				'property'    => 'url',
				'description' => '',
			),
			'description' => array(
				'label'       => __( 'Description', 'wp-seopress-pro' ),
				'property'    => 'description',
				'description' => '',
			),
			'product' => array(
				'label'       => __( 'Also mark as Product', 'wp-seopress-pro' ),
				'property'    => 'product',
				'description' => __( 'true to also use the Product type. Leave empty for Book only.', 'wp-seopress-pro' ),
			),
		);
	}

	public static function meta_keys() {
		$keys = array();
		foreach ( self::fields() as $key => $field ) {
			$keys[ $key ] = self::PREFIX . $key;
		}
		return $keys;
	}

	/** Build the structure before dynamic tags are resolved. */
	public static function build( $values ) {
		$data = array( '@context' => 'https://schema.org', '@type' => 'Book' );
		foreach ( self::fields() as $key => $field ) {
			if ( ! isset( $values[ $key ] ) || ! is_scalar( $values[ $key ] ) || '' === (string) $values[ $key ] ) {
				continue;
			}
			$path = explode( '.', $field['property'] );
			if ( 2 === count( $path ) ) {
				$data[ $path[0] ][ $path[1] ] = (string) $values[ $key ];
			} else {
				$data[ $path[0] ] = (string) $values[ $key ];
			}
		}
		return $data;
	}

	/** Normalize only after custom fields and dynamic tags have been resolved. */
	/**
	 * The date part of a value that may carry a time.
	 *
	 * `post_date` and `post_modified` are stored as `Y-m-d H:i:s`, and a user
	 * may type the same shape by hand. Only the leading date is kept; a value
	 * that does not start with one is returned untouched, so the validation in
	 * normalize() still rejects it.
	 *
	 * @since 10.4.0
	 *
	 * @param string $value The raw date value.
	 *
	 * @return string
	 */
	private static function date_part( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$value = trim( $value );

		return preg_match( '/^(\d{4}-\d{2}-\d{2})(?:[T ]|$)/', $value, $matches ) ? $matches[1] : $value;
	}

	public static function normalize( $data ) {
		if ( isset( $data['product'] ) && in_array( $data['product'], array( 'true', '1' ), true ) ) {
			$data['@type'] = array( 'Book', 'Product' );
		}
		unset( $data['product'] );
		foreach ( array( 'author' => 'Person', 'publisher' => 'Organization', 'illustrator' => 'Person' ) as $key => $type ) {
			if ( empty( $data[ $key ]['name'] ) ) {
				unset( $data[ $key ] );
				continue;
			}
			if ( 'author' === $key && isset( $data[ $key ]['@type'] ) && 'Organization' === $data[ $key ]['@type'] ) {
				$type = 'Organization';
			}
			$data[ $key ]['@type'] = $type;
		}
		if ( isset( $data['numberOfPages'] ) ) {
			$pages = filter_var( $data['numberOfPages'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false === $pages ) {
				unset( $data['numberOfPages'] );
			} else {
				$data['numberOfPages'] = $pages;
			}
		}
		if ( isset( $data['abridged'] ) ) {
			if ( in_array( $data['abridged'], array( 'true', 'false', '1', '0' ), true ) ) {
				$data['abridged'] = in_array( $data['abridged'], array( 'true', '1' ), true );
			} else {
				unset( $data['abridged'] );
			}
		}
		if ( isset( $data['bookFormat'] ) ) {
			$format = preg_replace( '#^https?://schema\.org/#', '', $data['bookFormat'] );
			if ( in_array( $format, array( 'Hardcover', 'Paperback', 'EBook', 'AudiobookFormat', 'GraphicNovel', 'Pamphlet' ), true ) ) {
				$data['bookFormat'] = 'https://schema.org/' . $format;
			} else {
				unset( $data['bookFormat'] );
			}
		}
		if ( isset( $data['datePublished'] ) ) {
			// The `post_date` source hands us a MySQL datetime, which is a
			// value we produced ourselves in a format we control, so it is
			// reduced to its date part rather than rejected. Anything that is
			// still not a real calendar day afterwards is dropped, as before:
			// an absent property beats an invalid one.
			$date_value = self::date_part( $data['datePublished'] );
			$date       = preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date_value ) ? \DateTime::createFromFormat( '!Y-m-d', $date_value ) : false;
			if ( false === $date || $date->format( 'Y-m-d' ) !== $date_value ) {
				unset( $data['datePublished'] );
			} else {
				$data['datePublished'] = $date_value;
			}
		}
		if ( isset( $data['offers'] ) ) {
			$offer = $data['offers'];
			if ( ! isset( $offer['price'], $offer['priceCurrency'] ) || ! preg_match( '/^\d+(?:\.\d+)?$/D', $offer['price'] ) || ! preg_match( '/^[A-Z]{3}$/D', $offer['priceCurrency'] ) ) {
				unset( $data['offers'] );
			} else {
				$data['offers']['@type'] = 'Offer';
				if ( isset( $offer['url'] ) ) {
					$data['offers']['url'] = esc_url_raw( $offer['url'], array( 'http', 'https' ) );
					if ( '' === $data['offers']['url'] ) {
						unset( $data['offers']['url'] );
					}
				}
			}
		}
		// Before escaping, not after: esc_url_raw() turns a bare attachment ID
		// into `http://97480`, which ctype_digit() no longer recognises, so the
		// resolution that runs later in seopress_pro_json_ld_encode() silently
		// does nothing. A value that is already a URL comes back unchanged.
		if ( isset( $data['image'] ) && function_exists( 'seopress_pro_resolve_schema_images' ) ) {
			$data['image'] = seopress_pro_resolve_schema_images( $data['image'], true );
		}

		// An ID that resolved to nothing is still a bare number here, and
		// esc_url_raw() below would publish it as `http://999999999`. A missing
		// cover beats a URL built out of a row that is not there.
		if ( isset( $data['image'] ) && is_string( $data['image'] ) && ctype_digit( $data['image'] ) ) {
			unset( $data['image'] );
		}

		foreach ( array( 'url', 'image' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$data[ $key ] = esc_url_raw( $data[ $key ], array( 'http', 'https' ) );
			}
		}
		return array_filter(
			$data,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);
	}
}
