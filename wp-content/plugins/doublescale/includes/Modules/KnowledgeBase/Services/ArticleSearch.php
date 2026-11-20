<?php
/**
 * Article search shared by every reader: the admin list, the public help
 * center, the client portal, the search ability and Pro's assistant.
 *
 * Every normalised word of the search must appear in `search_text` (so
 * "والمنتجات" finds "منتج"); a raw title match is accepted too, for searches
 * the normaliser reduces to nothing. LIKE only — never a FULLTEXT index
 * ({@see TextNormalizer} explains why).
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Services;

defined( 'ABSPATH' ) || exit;

/**
 * ArticleSearch class.
 */
final class ArticleSearch {

	/** Longest search text and most words a search uses (the rest is dropped). */
	public const MAX_LENGTH = 200;
	public const MAX_TOKENS = 10;

	/**
	 * A search cut to {@see self::MAX_LENGTH} characters and {@see self::MAX_TOKENS} words.
	 *
	 * @param string $search Raw search text.
	 * @return string
	 */
	public static function limit( string $search ): string {
		$search = mb_substr( trim( $search ), 0, self::MAX_LENGTH, 'UTF-8' );
		$words  = preg_split( '/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $words ) ? implode( ' ', array_slice( $words, 0, self::MAX_TOKENS ) ) : '';
	}

	/**
	 * Narrow an article query to rows matching $search. No-op for an empty search.
	 *
	 * @param object $query  Eloquent builder on KbArticleModel.
	 * @param string $search Raw search text.
	 * @return void
	 */
	public static function apply( $query, string $search ): void {
		$search = self::limit( $search );
		if ( '' === $search ) {
			return;
		}

		$tokens = array_values( array_unique( TextNormalizer::tokens( $search ) ) );
		$raw    = '%' . self::escape_like( $search ) . '%';

		$query->where(
			static function ( $outer ) use ( $tokens, $raw ) {
				$outer->where( 'title', 'LIKE', $raw );
				if ( array() !== $tokens ) {
					$outer->orWhere(
						static function ( $inner ) use ( $tokens ) {
							foreach ( $tokens as $token ) {
								$inner->where( 'search_text', 'LIKE', '%' . self::escape_like( $token ) . '%' );
							}
						}
					);
				}
			}
		);
	}

	/**
	 * Escape LIKE wildcards in user input.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function escape_like( string $value ): string {
		return str_replace( array( '\\', '%', '_' ), array( '\\\\', '\\%', '\\_' ), $value );
	}
}
