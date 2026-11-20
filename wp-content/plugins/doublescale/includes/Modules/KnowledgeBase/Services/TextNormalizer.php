<?php
/**
 * Search-text normaliser for help-center articles.
 *
 * Articles are matched against a normalised `search_text` column rather than a
 * MySQL FULLTEXT index. InnoDB's FULLTEXT parser has no Arabic stemming, a
 * 3-character minimum token and an English-only stopword list, so a visitor
 * typing "والمنتجات" would never reach an article about "منتج". A FULLTEXT KEY
 * line would also be misread as a column by SchemaColumns::parse_columns().
 *
 * The same normaliser runs on the stored article text and on the query, so the
 * two sides meet on the same light stem:
 *
 *   - HTML tags and punctuation become word breaks
 *   - Arabic diacritics and tatweel are dropped
 *   - أ إ آ ٱ → ا, ة → ه, ى → ي, ؤ → و, ئ → ي
 *   - Arabic-Indic digits → Latin digits
 *   - the article prefix (ال and its و/ب/ك/ف/ل forms) and a few plural or
 *     pronoun suffixes are stripped, never below a two/three letter stem; a
 *     word that starts with hamza-alef + lam (ألغي, إلغاء) keeps those letters
 *   - English is lower-cased and a trailing plural "s" folded
 *
 * Deliberately light: a full Arabic stemmer over-merges unrelated words, and
 * the assistant re-ranks whatever this finds.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Services;

defined( 'ABSPATH' ) || exit;

/**
 * TextNormalizer class.
 */
final class TextNormalizer {

	/**
	 * Arabic article prefixes, longest first so "وال" wins over "ال".
	 *
	 * @var string[]
	 */
	private const ARABIC_PREFIXES = array( 'وال', 'بال', 'كال', 'فال', 'لل', 'ال' );

	/**
	 * Plural / pronoun suffixes folded off Arabic words.
	 *
	 * @var string[]
	 */
	private const ARABIC_SUFFIXES = array( 'ات', 'ون', 'ين', 'ها', 'هم' );

	/**
	 * Letter unification applied before stemming.
	 *
	 * @var array<string, string>
	 */
	private const ARABIC_LETTER_MAP = array(
		'أ' => 'ا',
		'إ' => 'ا',
		'آ' => 'ا',
		'ٱ' => 'ا',
		'ة' => 'ه',
		'ى' => 'ي',
		'ؤ' => 'و',
		'ئ' => 'ي',
	);

	/**
	 * Arabic-Indic and Eastern Arabic-Indic digits.
	 *
	 * @var array<string, string>
	 */
	private const DIGIT_MAP = array(
		'٠' => '0',
		'١' => '1',
		'٢' => '2',
		'٣' => '3',
		'٤' => '4',
		'٥' => '5',
		'٦' => '6',
		'٧' => '7',
		'٨' => '8',
		'٩' => '9',
		'۰' => '0',
		'۱' => '1',
		'۲' => '2',
		'۳' => '3',
		'۴' => '4',
		'۵' => '5',
		'۶' => '6',
		'۷' => '7',
		'۸' => '8',
		'۹' => '9',
	);

	/**
	 * Normalised, stemmed tokens of a text (HTML allowed), in order, duplicates kept.
	 *
	 * @param string $text Raw text or HTML.
	 * @return string[]
	 */
	public static function tokens( string $text ): array {
		$text = preg_replace( '/<[^>]*>/u', ' ', $text );
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = mb_strtolower( $text, 'UTF-8' );

		// Diacritics (harakat, shadda, sukun, superscript alef) and tatweel.
		$text = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text );
		$text = strtr( (string) $text, self::DIGIT_MAP );

		// Anything that is not a letter or a digit separates words.
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

		$tokens = array();
		foreach ( preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			// "ألغي", "إلغاء", "آلة": a hamza on the alef means these letters are
			// the word's own, not the article ال — decided before the hamza is
			// unified away.
			$own_alef_lam = (bool) preg_match( '/^[أإآ]ل/u', $word );
			$stem         = self::stem( strtr( $word, self::ARABIC_LETTER_MAP ), $own_alef_lam );
			if ( mb_strlen( $stem, 'UTF-8' ) < 2 ) {
				continue;
			}
			$tokens[] = $stem;
		}

		return $tokens;
	}

	/**
	 * The value stored in a `search_text` column: the stems, space-joined.
	 *
	 * @param string $text Raw text or HTML.
	 * @return string
	 */
	public static function search_text( string $text ): string {
		return implode( ' ', self::tokens( $text ) );
	}

	/**
	 * Light stem of one already-normalised word.
	 *
	 * @param string $word         Lower-cased word with unified letters.
	 * @param bool   $own_alef_lam The word starts with hamza-alef + lam (no article).
	 * @return string
	 */
	private static function stem( string $word, bool $own_alef_lam = false ): string {
		if ( preg_match( '/\p{Arabic}/u', $word ) ) {
			return self::stem_arabic( $word, $own_alef_lam );
		}

		$length = strlen( $word );
		if ( $length > 3 && 's' === substr( $word, -1 ) && 's' !== substr( $word, -2, 1 ) ) {
			return substr( $word, 0, -1 );
		}

		return $word;
	}

	/**
	 * Strip one article prefix and one suffix, keeping a usable stem.
	 *
	 * @param string $word         Arabic word.
	 * @param bool   $own_alef_lam Keep a leading ال: it is part of the word.
	 * @return string
	 */
	private static function stem_arabic( string $word, bool $own_alef_lam = false ): string {
		foreach ( $own_alef_lam ? array() : self::ARABIC_PREFIXES as $prefix ) {
			$prefix_length = mb_strlen( $prefix, 'UTF-8' );
			if ( mb_substr( $word, 0, $prefix_length, 'UTF-8' ) === $prefix
				&& mb_strlen( $word, 'UTF-8' ) - $prefix_length >= 2 ) {
				$word = mb_substr( $word, $prefix_length, null, 'UTF-8' );
				break;
			}
		}

		foreach ( self::ARABIC_SUFFIXES as $suffix ) {
			$suffix_length = mb_strlen( $suffix, 'UTF-8' );
			if ( mb_substr( $word, -$suffix_length, null, 'UTF-8' ) === $suffix
				&& mb_strlen( $word, 'UTF-8' ) - $suffix_length >= 3 ) {
				$word = mb_substr( $word, 0, -$suffix_length, 'UTF-8' );
				break;
			}
		}

		return $word;
	}
}
