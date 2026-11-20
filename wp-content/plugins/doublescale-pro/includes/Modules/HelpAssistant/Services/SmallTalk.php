<?php
/**
 * Greetings, "how are you", thanks, "ok" and goodbyes.
 *
 * Also "no" (the answer to "anything else?"), "what can you do / who are
 * you" (who the assistant is and what it answers from) and "talk to a person"
 * (`handoff`: straight to the support team, only when the chat can reach it).
 *
 * A message made ONLY of such phrases (with the assistant's name or "there"
 * around them) gets a short, fixed, friendly reply in the asker's language —
 * no search, no model call, never the "not found" reply. Anything more ("hi,
 * I forgot my password") is a question and takes the normal path, so this is
 * no door to off-topic chatting.
 *
 * Phrases and replies are kept per language (en, ar, de, fr, es); the English
 * replies go through translation for the site's own language. The match is
 * done on a folded form: lower case, Arabic diacritics and letter variants
 * unified, punctuation and emoji dropped, repeated letters squeezed ("hiii"),
 * the same way on both sides.
 *
 * Filter `doublescale_help_small_talk_reply` ( string $reply, string $kind,
 * string $language ) changes a reply.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

/**
 * SmallTalk class.
 */
final class SmallTalk {

	/**
	 * Longest message looked at, in characters.
	 */
	private const MAX_CHARS = 80;

	/**
	 * When a message holds several kinds ("hi, thanks"), the reply is for the
	 * first of these found.
	 */
	private const PRIORITY = array( 'handoff', 'bye', 'thanks', 'about', 'how_are_you', 'no', 'ok', 'salam', 'greeting' );

	/**
	 * Words that may stand around the phrases without making a question.
	 */
	private const FILLERS = array( 'there', 'again', 'everyone', 'all', 'guys', 'team', 'bot', 'assistant', 'friend', 'dear', 'sir', 'madam', 'and', 'so', 'very', 'much', 'lot', 'a', 'oh', 'well', 'i', 'want', 'to', 'please', 'can', 'could', 'would', 'like', 'id', 'me', 'يا', 'و', 'بوت', 'عزيزي', 'حبيبي', 'جدا', 'خالص', 'اوي', 'كتير', 'عايز', 'عاوز', 'عايزه', 'اريد', 'ممكن', 'لو', 'سمحت', 'فضلك', 'انا' );

	/**
	 * A message of emoji only: what each one says.
	 *
	 * @var array<string, string[]>
	 */
	private const EMOJI = array(
		// "Call us": straight to the support team (only when it can be reached).
		'handoff'  => array( '📞', '☎' ),
		'unhappy'  => array( '😢', '😭', '😞', '😔', '☹', '🙁', '😟', '😕', '👎', '😡', '😠', '🤬', '😤', '💔', '😩', '😫' ),
		'confused' => array( '🤔', '❓', '❔', '🤷', '😐', '🧐', '😮', '😯', '👀', '💡' ),
		'no'       => array( '❌', '✖', '🚫', '🙅' ),
		'thanks'   => array( '🙏', '❤', '♥', '😍', '🥰', '💯', '💙', '💚', '💜', '🧡', '💛', '🤍', '🖤' ),
		'ok'       => array( '👍', '👌', '✅', '🆗', '😊', '🙂', '😀', '😃', '😄', '😁', '🤝', '👏', '🎉', '💪', '🔥', '⭐', '😂', '🤣', '😆', '😉', '✔', '😅' ),
		'greeting' => array( '👋', '🙋' ),
	);

	/**
	 * An emoji that names a topic: asked about as a question, in the chat's
	 * language ("🚚" is "shipping and delivery").
	 *
	 * @var array<string, array{en: string, ar: string}>
	 */
	private const TOPICS = array(
		'🚚' => array(
			'en' => 'shipping and delivery',
			'ar' => 'الشحن والتوصيل',
		),
		'📦' => array(
			'en' => 'my order and its package',
			'ar' => 'طلبي والشحنة',
		),
		'💳' => array(
			'en' => 'paying by card',
			'ar' => 'الدفع بالبطاقة',
		),
		'📧' => array(
			'en' => 'email',
			'ar' => 'البريد الإلكتروني',
		),
		'📞' => array(
			'en' => 'contact phone number',
			'ar' => 'رقم الهاتف للتواصل',
		),
		'🛒' => array(
			'en' => 'cart and checkout',
			'ar' => 'سلة الشراء وإتمام الطلب',
		),
		'⏰' => array(
			'en' => 'opening hours',
			'ar' => 'مواعيد العمل',
		),
	);

	/**
	 * "It's solved" and the like: with thanks/ok/bye/no, a visitor's closing
	 * words once the team marked their request solved ({@see closing()}). No
	 * "yes": after "reply if you still need help" it may mean they do.
	 *
	 * @var array<string, string[]>
	 */
	private const SOLVED = array(
		'en' => array( 'solved', 'its solved', 'it is solved', 'resolved', 'its resolved', 'fixed', 'its fixed', 'it is fixed', 'it works', 'it works now', 'works now', 'working now', 'its working', 'it is working', 'all good', 'all set', 'no need', 'problem solved', 'issue solved', 'sorted', 'done', 'that worked', 'that helped', 'it helped' ),
		'ar' => array( 'اتحل', 'اتحلت', 'اتحلت المشكله', 'المشكله اتحلت', 'تم الحل', 'تم حلها', 'انحلت', 'حلت', 'اشتغل', 'اشتغلت', 'ظبطت', 'زبطت', 'خلاص', 'كده تمام', 'كدا تمام', 'تمت', 'مش محتاج', 'مش محتاجه', 'مش محتاج حاجه', 'الحمد لله', 'الحمدلله' ),
	);

	/**
	 * Phrases per language and kind.
	 *
	 * @var array<string, array<string, string[]>>
	 */
	private const PHRASES = array(
		'en' => array(
			'greeting'    => array( 'hi', 'hey', 'hello', 'hiya', 'howdy', 'yo', 'greetings', 'good morning', 'good afternoon', 'good evening', 'morning', 'evening' ),
			'how_are_you' => array( 'how are you', 'how are you doing', 'how is it going', 'hows it going', 'how r u', 'how are u', 'whats up', 'sup', 'how do you do' ),
			'thanks'      => array( 'thanks', 'thank you', 'thank u', 'thx', 'ty', 'thanks a lot', 'many thanks', 'much appreciated', 'appreciated', 'great thanks', 'perfect thanks', 'cheers' ),
			'ok'          => array( 'ok', 'okay', 'k', 'kk', 'cool', 'great', 'perfect', 'nice', 'awesome', 'got it', 'alright', 'all right', 'sounds good', 'i see', 'understood' ),
			'bye'         => array( 'bye', 'goodbye', 'bye bye', 'see you', 'see ya', 'good night', 'no thanks', 'no thank you', 'nothing else', 'thats all', 'that is all' ),
			// The answer to "Anything else I can help you with?".
			'no'          => array( 'no', 'nope', 'nah', 'not now' ),
			// "Talk to a person": straight to the support team.
			'handoff'     => array( 'talk to a person', 'talk to a human', 'talk to someone', 'talk to an agent', 'talk to support', 'talk to a real person', 'speak to a person', 'speak to a human', 'speak to someone', 'speak to an agent', 'speak with a person', 'speak with someone', 'chat with a person', 'chat with a human', 'human', 'a person', 'real person', 'agent', 'live agent', 'customer service', 'customer support', 'contact support', 'support team', 'support' ),
			'about'       => array( 'what can you do', 'what do you do', 'who are you', 'what are you', 'are you a bot', 'are you a robot', 'are you human', 'are you a human', 'are you real', 'how can you help', 'how can you help me', 'what can you help with', 'what can you help me with', 'help', 'help me' ),
		),
		'ar' => array(
			'salam'       => array( 'السلام عليكم', 'سلام عليكم', 'السلام عليكم ورحمه الله', 'السلام عليكم ورحمه الله وبركاته' ),
			'greeting'    => array( 'مرحبا', 'مرحبتين', 'اهلا', 'اهلا وسهلا', 'اهلين', 'هلا', 'هاي', 'هلو', 'صباح الخير', 'مساء الخير', 'صباح النور', 'مساء النور', 'وعليكم السلام' ),
			'how_are_you' => array( 'ازيك', 'ازيكم', 'عامل ايه', 'عاملين ايه', 'اخبارك ايه', 'اخبارك', 'كيف حالك', 'كيفك', 'شلونك', 'كيف الحال', 'ايه الاخبار', 'عامله ايه' ),
			'thanks'      => array( 'شكرا', 'شكرا جزيلا', 'شكرا ليك', 'شكرا لك', 'شكرا ليكي', 'متشكر', 'متشكرين', 'متشكره', 'ميرسي', 'تسلم', 'تسلمي', 'تسلم ايدك', 'يسلمو', 'مشكور', 'الف شكر', 'جزاك الله خير', 'جزاك الله خيرا' ),
			'ok'          => array( 'تمام', 'اوك', 'اوكي', 'حلو', 'ممتاز', 'جميل', 'فهمت', 'طيب', 'ماشي', 'تمام شكرا' ),
			'bye'         => array( 'مع السلامه', 'باي', 'سلام', 'لا شكرا', 'تصبح علي خير', 'الي اللقاء' ),
			'no'          => array( 'لا', 'لا لا', 'مفيش', 'لا مفيش', 'لا يوجد' ),
			'handoff'     => array( 'كلم شخص', 'كلمني شخص', 'اكلم شخص', 'اكلم حد', 'كلم حد', 'حد من الدعم', 'اكلم حد من الدعم', 'اكلم الدعم', 'كلم الدعم', 'الدعم', 'الدعم الفني', 'خدمه العملاء', 'موظف', 'اكلم موظف', 'كلم موظف', 'موظف خدمه العملاء', 'التحدث مع شخص', 'التحدث مع موظف', 'التواصل مع الدعم', 'تواصل مع الدعم', 'انسان حقيقي', 'شخص حقيقي' ),
			'about'       => array( 'انت تقدر تعمل ايه', 'تقدر تعمل ايه', 'بتعمل ايه', 'انت بتعمل ايه', 'انت مين', 'مين انت', 'من انت', 'انت بوت', 'انت روبوت', 'انت انسان', 'تقدر تساعدني في ايه', 'بتساعد في ايه', 'ماذا تستطيع ان تفعل', 'ماذا يمكنك ان تفعل', 'كيف يمكنك مساعدتي', 'مساعده', 'ساعدني' ),
		),
		'de' => array(
			'greeting'    => array( 'hallo', 'guten tag', 'guten morgen', 'guten abend', 'servus', 'moin' ),
			'how_are_you' => array( 'wie gehts', 'wie geht es dir', 'wie geht es ihnen' ),
			'thanks'      => array( 'danke', 'danke schon', 'danke schön', 'vielen dank', 'dankeschön' ),
			'bye'         => array( 'tschuss', 'tschüss', 'auf wiedersehen', 'nein danke' ),
			'no'          => array( 'nein' ),
		),
		'fr' => array(
			'greeting'    => array( 'bonjour', 'bonsoir', 'salut', 'coucou' ),
			'how_are_you' => array( 'ca va', 'ça va', 'comment ca va', 'comment ça va', 'comment allez vous' ),
			'thanks'      => array( 'merci', 'merci beaucoup', 'merci bien' ),
			'bye'         => array( 'au revoir', 'non merci', 'bonne journee', 'bonne journée' ),
			'no'          => array( 'non' ),
		),
		'es' => array(
			'greeting'    => array( 'hola', 'buenos dias', 'buenos días', 'buenas tardes', 'buenas noches', 'buenas' ),
			'how_are_you' => array( 'que tal', 'qué tal', 'como estas', 'cómo estás', 'como esta', 'cómo está' ),
			'thanks'      => array( 'gracias', 'muchas gracias', 'mil gracias' ),
			'bye'         => array( 'adios', 'adiós', 'hasta luego', 'no gracias' ),
		),
	);

	/**
	 * The reply when $message is only small talk; null when it is a question.
	 *
	 * @param string $message Message as typed.
	 * @param string $name    Assistant name (may be said to it: "hi Gogo").
	 * @param bool   $handoff Whether the support team can be reached from the chat;
	 *                        without it, "talk to a person" is left to the normal path.
	 * @param string $lang    The chat's language ('ar' or ''), for a message of
	 *                        emoji only: it has no words to tell.
	 * @return array{kind: string, language: string, reply: string}|null
	 */
	public static function reply( string $message, string $name = '', bool $handoff = false, string $lang = '' ): ?array {
		if ( '' === trim( $message ) || mb_strlen( $message, 'UTF-8' ) > self::MAX_CHARS ) {
			return null;
		}

		$words = self::words( $message );
		if ( array() === $words ) {
			return self::emoji_reply( $message, 'ar' === $lang ? 'ar' : 'en', trim( $name ), $handoff );
		}

		$found = self::match( $words, self::phrases(), array_merge( self::FILLERS, self::words( $name ) ) );
		if ( null === $found || array() === $found ) {
			return null;
		}

		$language = $found[0]['language'];
		$kinds    = array_column( $found, 'kind' );
		if ( in_array( 'handoff', $kinds, true ) && ! $handoff ) {
			// Never promise a team the site cannot reach.
			return null;
		}
		foreach ( self::PRIORITY as $kind ) {
			if ( in_array( $kind, $kinds, true ) ) {
				break;
			}
		}

		return array(
			'kind'     => $kind,
			'language' => $language,
			'reply'    => (string) apply_filters( 'doublescale_help_small_talk_reply', self::text( $kind, $language, trim( $name ) ), $kind, $language ),
		);
	}

	/**
	 * Closing words only: thanks, ok, bye, no, "it's solved", in any mix
	 * ("لا تمام اتحل", "Thanks, it works now!", "👍"). A question, or any other
	 * word, is not: it may say the problem is still there.
	 *
	 * @param string $message Message as typed.
	 * @return bool
	 */
	public static function closing( string $message ): bool {
		if ( '' === trim( $message ) || mb_strlen( $message, 'UTF-8' ) > self::MAX_CHARS || preg_match( '/[?؟]/u', $message ) ) {
			return false;
		}

		$words = self::words( $message );
		if ( array() === $words ) {
			$said = self::emoji_reply( $message, 'en', '' );
			return null !== $said && in_array( $said['kind'], array( 'thanks', 'ok' ), true );
		}

		$phrases = self::phrases();
		foreach ( self::SOLVED as $language => $list ) {
			foreach ( $list as $phrase ) {
				$phrases[] = array(
					'words'    => self::words( $phrase ),
					'kind'     => 'solved',
					'language' => $language,
				);
			}
		}
		usort(
			$phrases,
			static function ( $a, $b ) {
				return count( $b['words'] ) <=> count( $a['words'] );
			}
		);

		$found = self::match( $words, $phrases, self::FILLERS );
		if ( null === $found || array() === $found ) {
			return false;
		}

		return array() === array_diff( array_column( $found, 'kind' ), array( 'thanks', 'ok', 'bye', 'no', 'solved' ) );
	}

	/**
	 * Every word is a phrase (longest first) or a filler: the phrases found;
	 * null when a word is neither.
	 *
	 * @param string[]                                                             $words   Folded words.
	 * @param array<int, array{words: string[], kind: string, language: string}> $phrases Phrases, longest first.
	 * @param string[]                                                             $fillers Words that say nothing.
	 * @return array<int, array{words: string[], kind: string, language: string}>|null
	 */
	private static function match( array $words, array $phrases, array $fillers ): ?array {
		$found = array();
		$i     = 0;
		$count = count( $words );

		while ( $i < $count ) {
			$match = null;
			// Longest phrase first, so "thank you so much" is not cut at "thank".
			foreach ( $phrases as $phrase ) {
				$length = count( $phrase['words'] );
				if ( array_slice( $words, $i, $length ) === $phrase['words'] ) {
					$match = $phrase;
					break;
				}
			}

			if ( null !== $match ) {
				$found[] = $match;
				$i      += count( $match['words'] );
				continue;
			}
			if ( in_array( $words[ $i ], $fillers, true ) ) {
				++$i;
				continue;
			}

			return null;
		}

		return $found;
	}

	/**
	 * A message of topic emoji only ("🚚", "📦💳"): the question it asks, in
	 * the chat's language; null for anything else.
	 *
	 * @param string $message Message.
	 * @param string $lang    The chat's language ('ar' or '').
	 * @return string|null
	 */
	public static function topic( string $message, string $lang = '' ): ?string {
		$label = self::topic_label( $message, $lang );

		return null !== $label ? $message . ' ' . $label : null;
	}

	/**
	 * What a message of topic emoji only is about ("shipping and delivery"),
	 * in the chat's language; null for anything else.
	 *
	 * @param string $message Message.
	 * @param string $lang    The chat's language ('ar' or '').
	 * @return string|null
	 */
	public static function topic_label( string $message, string $lang = '' ): ?string {
		if ( array() !== self::words( $message ) ) {
			return null;
		}
		$clean = (string) preg_replace( '/[\x{FE0E}\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{200D}\s]/u', '', $message );
		$chars = array_unique( preg_split( '//u', $clean, -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
		if ( array() === $chars ) {
			return null;
		}

		$topics = array();
		foreach ( $chars as $char ) {
			if ( ! isset( self::TOPICS[ $char ] ) ) {
				return null;
			}
			$topics[] = self::TOPICS[ $char ][ 'ar' === $lang ? 'ar' : 'en' ];
		}

		return implode( 'ar' === $lang ? ' و' : ' and ', $topics );
	}

	/**
	 * A message of known emoji only ("😢", "👍👍", "👋🏽"): the reply to what
	 * they say, unhappy first. Any other character: null.
	 *
	 * @param string $message  Message.
	 * @param string $language Reply language.
	 * @param string $name     Assistant name.
	 * @param bool   $handoff  Whether the support team can be reached.
	 * @return array{kind: string, language: string, reply: string}|null
	 */
	private static function emoji_reply( string $message, string $language, string $name, bool $handoff = false ): ?array {
		// Variation selectors, skin tones, joiners and spaces say nothing more.
		$clean = (string) preg_replace( '/[\x{FE0E}\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{200D}\x{2640}\x{2642}\s]/u', '', $message );
		$chars = preg_split( '//u', $clean, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		if ( array() === $chars ) {
			return null;
		}

		$kinds = array();
		foreach ( $chars as $char ) {
			$kind = null;
			foreach ( self::EMOJI as $candidate => $list ) {
				if ( in_array( $char, $list, true ) ) {
					$kind = $candidate;
					break;
				}
			}
			if ( null === $kind ) {
				return null;
			}
			$kinds[ $kind ] = true;
		}

		if ( isset( $kinds['handoff'] ) && ! $handoff ) {
			// No team to reach: "📞" stays a question about contacting.
			return null;
		}
		foreach ( array_keys( self::EMOJI ) as $kind ) {
			if ( isset( $kinds[ $kind ] ) ) {
				return array(
					'kind'     => $kind,
					'language' => $language,
					'reply'    => (string) apply_filters( 'doublescale_help_small_talk_reply', self::text( $kind, $language, $name ), $kind, $language ),
				);
			}
		}

		return null;
	}

	/**
	 * Every phrase, folded and split, longest first.
	 *
	 * @return array<int, array{words: string[], kind: string, language: string}>
	 */
	private static function phrases(): array {
		static $phrases = null;
		if ( null !== $phrases ) {
			return $phrases;
		}

		$phrases = array();
		foreach ( self::PHRASES as $language => $kinds ) {
			foreach ( $kinds as $kind => $list ) {
				foreach ( $list as $phrase ) {
					$phrases[] = array(
						'words'    => self::words( $phrase ),
						'kind'     => $kind,
						'language' => $language,
					);
				}
			}
		}
		usort(
			$phrases,
			static function ( $a, $b ) {
				return count( $b['words'] ) <=> count( $a['words'] );
			}
		);

		return $phrases;
	}

	/**
	 * Folded words of $text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function words( string $text ): array {
		$text = mb_strtolower( $text, 'UTF-8' );
		// Arabic: diacritics and tatweel out, letter variants unified.
		$text = (string) preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text );
		$text = strtr(
			$text,
			array(
				'أ' => 'ا',
				'إ' => 'ا',
				'آ' => 'ا',
				'ٱ' => 'ا',
				'ة' => 'ه',
				'ى' => 'ي',
				'ؤ' => 'و',
				'ئ' => 'ي',
				'’' => '',
				"'" => '',
			)
		);
		// Punctuation, symbols and emoji are not words.
		$text = (string) preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		// "hiii", "شكراااا": a letter said twice or more counts once.
		$text = (string) preg_replace( '/(\p{L})\1+/u', '$1', $text );

		return preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
	}

	/**
	 * @param string $kind     Kind.
	 * @param string $language Language.
	 * @param string $name     Assistant name, '' for none.
	 * @return string
	 */
	private static function text( string $kind, string $language, string $name ): string {
		$site = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );

		if ( 'about' === $kind && 'ar' === $language ) {
			return 'أنا ' . ( '' !== $name ? $name . '، ' : '' ) . 'مساعد الدعم' . ( '' !== $site ? ' في ' . $site : '' ) . '. أجيب على أسئلتك من مقالات المساعدة. ماذا تريد أن تعرف؟';
		}
		if ( 'about' === $kind ) {
			if ( '' !== $name && '' !== $site ) {
				/* translators: 1: assistant name, 2: site name */
				return sprintf( __( "I'm %1\$s, the help assistant for %2\$s. I answer your questions from our help articles. What would you like to know?", 'doublescale' ), $name, $site );
			}
			if ( '' !== $name ) {
				/* translators: %s: assistant name */
				return sprintf( __( "I'm %s, the help assistant. I answer your questions from our help articles. What would you like to know?", 'doublescale' ), $name );
			}
			if ( '' !== $site ) {
				/* translators: %s: site name */
				return sprintf( __( "I'm the help assistant for %s. I answer your questions from our help articles. What would you like to know?", 'doublescale' ), $site );
			}

			return __( "I'm the help assistant. I answer your questions from our help articles. What would you like to know?", 'doublescale' );
		}

		$replies = array(
			'ar' => array(
				'greeting'    => 'أهلاً بك! كيف يمكنني مساعدتك اليوم؟',
				'salam'       => 'وعليكم السلام ورحمة الله! كيف يمكنني مساعدتك اليوم؟',
				'how_are_you' => 'أنا بخير، شكراً لسؤالك! كيف يمكنني مساعدتك اليوم؟',
				'thanks'      => 'العفو! هل هناك شيء آخر يمكنني مساعدتك فيه؟',
				'ok'          => 'رائع! هل هناك شيء آخر يمكنني مساعدتك فيه؟',
				'bye'         => 'مع السلامة! أنا هنا متى احتجت إلى المساعدة.',
				'no'          => 'حسناً! أنا هنا متى احتجت إلى المساعدة.',
				'handoff'     => 'بالتأكيد! سأوصلك بفريق الدعم.',
				'unhappy'     => 'يؤسفني سماع ذلك. أخبرني بما حدث وسأبذل قصارى جهدي لمساعدتك.',
				'confused'    => 'لا مشكلة! أخبرني بما تبحث عنه وسأجد لك الإجابة في مقالات المساعدة.',
			),
			'de' => array(
				'greeting'    => 'Hallo! Wie kann ich Ihnen heute helfen?',
				'how_are_you' => 'Mir geht es gut, danke der Nachfrage! Wie kann ich Ihnen heute helfen?',
				'thanks'      => 'Gern geschehen! Kann ich Ihnen noch bei etwas anderem helfen?',
				'bye'         => 'Auf Wiedersehen! Ich bin da, wenn Sie Hilfe brauchen.',
				'no'          => 'Kein Problem! Ich bin da, wenn Sie Hilfe brauchen.',
			),
			'fr' => array(
				'greeting'    => 'Bonjour ! Comment puis-je vous aider aujourd’hui ?',
				'how_are_you' => 'Je vais bien, merci de demander ! Comment puis-je vous aider aujourd’hui ?',
				'thanks'      => 'Je vous en prie ! Puis-je vous aider pour autre chose ?',
				'bye'         => 'Au revoir ! Je suis là si vous avez besoin d’aide.',
				'no'          => 'Pas de souci ! Je suis là si vous avez besoin d’aide.',
			),
			'es' => array(
				'greeting'    => '¡Hola! ¿En qué puedo ayudarte hoy?',
				'how_are_you' => '¡Estoy bien, gracias por preguntar! ¿En qué puedo ayudarte hoy?',
				'thanks'      => '¡De nada! ¿Hay algo más en lo que pueda ayudarte?',
				'bye'         => '¡Adiós! Aquí estaré si necesitas ayuda.',
			),
		);

		if ( isset( $replies[ $language ][ $kind ] ) ) {
			return $replies[ $language ][ $kind ];
		}

		switch ( $kind ) {
			case 'how_are_you':
				return __( "I'm doing well, thanks for asking! How can I help you today?", 'doublescale' );
			case 'thanks':
				return __( "You're welcome! Is there anything else I can help you with?", 'doublescale' );
			case 'ok':
				return __( 'Great! Is there anything else I can help you with?', 'doublescale' );
			case 'bye':
				return __( "Goodbye! I'm here whenever you need help.", 'doublescale' );
			case 'no':
				return __( "No problem! I'm here whenever you need help.", 'doublescale' );
			case 'handoff':
				return __( "Sure! I'll connect you with our support team.", 'doublescale' );
			case 'unhappy':
				return __( "I'm sorry to hear that. Tell me what went wrong and I'll do my best to help.", 'doublescale' );
			case 'confused':
				return __( "No problem! Tell me what you're looking for and I'll find the answer in our help articles.", 'doublescale' );
			default:
				return __( 'Hi! How can I help you today?', 'doublescale' );
		}
	}
}
