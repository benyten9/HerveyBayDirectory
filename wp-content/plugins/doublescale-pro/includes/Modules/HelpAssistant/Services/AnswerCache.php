<?php
/**
 * Kept answers to the first question of a conversation.
 *
 * Customers ask the same few things; the same question from the same audience
 * gets the same answer without a model call. The key is the question with
 * only case, punctuation, spacing and Arabic diacritics folded — every word
 * and digit kept, so "step 1" and "step 2", or "can I" and "can't I", stay
 * apart — the visibilities the reader may see, and a generation number.
 *
 * The generation moves whenever an answer could change: an article is saved
 * (its text, status or who may read it) or deleted, its passages are rebuilt,
 * or the assistant's settings are saved. Kept answers then simply stop being
 * found and expire on their own.
 *
 * The key (generation included) is taken BEFORE the model is asked and the
 * answer is kept under it: an article that changes while the model writes
 * moves the generation, so that answer lands under a key nobody reads.
 *
 * Only a first question is kept or served: a follow-up depends on the
 * conversation before it.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

/**
 * AnswerCache class.
 */
final class AnswerCache {

	private const GENERATION_OPTION = 'doublescale_help_answers_generation';

	private const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Drop kept answers when anything they were built from changes.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'doublescale_kb_article_saved', array( self::class, 'flush' ), 5, 0 );
		add_action( 'doublescale_kb_article_deleted', array( self::class, 'flush' ), 5, 0 );
	}

	/**
	 * A kept answer, or null.
	 *
	 * @param string|null $key From key().
	 * @return array{answer: string, citations: array<int, array<string, mixed>>, answered: bool}|null
	 */
	public static function get( ?string $key ): ?array {
		if ( null === $key ) {
			return null;
		}

		$kept = get_transient( $key );

		return is_array( $kept ) && isset( $kept['answer'], $kept['citations'], $kept['answered'] ) ? $kept : null;
	}

	/**
	 * Keep an engine result under the key taken before it was asked.
	 *
	 * @param string|null          $key    From key().
	 * @param array<string, mixed> $result ChatEngine::respond() result.
	 * @return void
	 */
	public static function put( ?string $key, array $result ): void {
		// A greeting's reply costs nothing to make again, and is no help answer.
		if ( null === $key || ! empty( $result['small_talk'] ) ) {
			return;
		}
		// An answer the model wrote in another language than the question
		// (Arabic for an English question) would reach every later asker.
		if ( ! empty( $result['answered'] ) && self::script( (string) ( $result['search'] ?? '' ) ) !== self::script( (string) $result['answer'] ) ) {
			return;
		}

		set_transient(
			$key,
			array(
				'answer'    => (string) $result['answer'],
				'citations' => (array) $result['citations'],
				'suggestions' => (array) ( $result['suggestions'] ?? array() ),
				'answered'  => (bool) $result['answered'],
			),
			self::TTL
		);
	}

	/**
	 * Arabic or Latin, by which letters the text holds more of; '' for neither.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function script( string $text ): string {
		$arabic = (int) preg_match_all( '/\p{Arabic}/u', $text );
		$latin  = (int) preg_match_all( '/\p{Latin}/u', $text );

		if ( 0 === $arabic && 0 === $latin ) {
			return '';
		}

		return $arabic > $latin ? 'arabic' : 'latin';
	}

	/**
	 * Forget every kept answer.
	 *
	 * @return void
	 */
	public static function flush(): void {
		update_option( self::GENERATION_OPTION, self::generation() + 1, false );
	}

	/**
	 * @return int
	 */
	public static function generation(): int {
		return (int) get_option( self::GENERATION_OPTION, 0 );
	}

	/**
	 * A question with case, punctuation, spacing and Arabic diacritics folded,
	 * every word and digit kept. Also groups the unanswered questions report.
	 *
	 * @param string $question Question.
	 * @return string
	 */
	public static function normalize( string $question ): string {
		$words = mb_strtolower( wp_strip_all_tags( $question ), 'UTF-8' );
		// Arabic diacritics and tatweel; then anything not a letter or digit.
		$words = (string) preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $words );

		return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $words ) );
	}

	/**
	 * The key a question is kept under right now.
	 *
	 * @param string   $question     Question.
	 * @param string[] $visibilities Visibilities.
	 * @return string|null Transient name, or null for a question with no words.
	 */
	public static function key( string $question, array $visibilities ): ?string {
		$words = self::normalize( $question );
		if ( '' === $words ) {
			return null;
		}

		sort( $visibilities );

		// "c2": answers since their [n] marks match the sources list; older
		// kept answers (numbered by passage) are not served again.
		return 'ds_help_ans_' . md5( 'c2|' . self::generation() . '|' . implode( ',', $visibilities ) . '|' . get_locale() . '|' . $words );
	}
}
