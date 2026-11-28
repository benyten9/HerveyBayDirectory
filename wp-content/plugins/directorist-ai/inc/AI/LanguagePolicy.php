<?php
/** Shared site-language defaults and explicit, conversation-owned reply preferences. */
namespace DirectoristAIPro\AI;

use DirectoristAIPro\Validation\ContractException;

final class LanguagePolicy {

	/** Stable language identities, independent of browser or administrator locale. */
	public static function languages(): array {
		return array(
			'en' => array( 'English', 'English' ), 'es' => array( 'Spanish', 'Español' ),
			'sv' => array( 'Swedish', 'Svenska' ), 'bn' => array( 'Bengali', 'বাংলা' ),
			'ar' => array( 'Arabic', 'العربية' ), 'fr' => array( 'French', 'Français' ),
			'de' => array( 'German', 'Deutsch' ), 'hi' => array( 'Hindi', 'हिन्दी' ),
			'pt' => array( 'Portuguese', 'Português' ), 'it' => array( 'Italian', 'Italiano' ),
			'nl' => array( 'Dutch', 'Nederlands' ), 'da' => array( 'Danish', 'Dansk' ),
			'no' => array( 'Norwegian', 'Norsk' ), 'fi' => array( 'Finnish', 'Suomi' ),
			'pl' => array( 'Polish', 'Polski' ), 'uk' => array( 'Ukrainian', 'Українська' ),
			'ru' => array( 'Russian', 'Русский' ), 'tr' => array( 'Turkish', 'Türkçe' ),
			'el' => array( 'Greek', 'Ελληνικά' ), 'cs' => array( 'Czech', 'Čeština' ),
			'ro' => array( 'Romanian', 'Română' ), 'hu' => array( 'Hungarian', 'Magyar' ),
			'id' => array( 'Indonesian', 'Bahasa Indonesia' ), 'ms' => array( 'Malay', 'Bahasa Melayu' ),
			'th' => array( 'Thai', 'ไทย' ), 'vi' => array( 'Vietnamese', 'Tiếng Việt' ),
			'ja' => array( 'Japanese', '日本語' ), 'ko' => array( 'Korean', '한국어' ),
			'zh-Hans' => array( 'Simplified Chinese', '简体中文' ), 'zh-Hant' => array( 'Traditional Chinese', '繁體中文' ),
			'fa' => array( 'Persian', 'فارسی' ), 'he' => array( 'Hebrew', 'עברית' ),
			'ur' => array( 'Urdu', 'اردو' ), 'ta' => array( 'Tamil', 'தமிழ்' ),
			'te' => array( 'Telugu', 'తెలుగు' ), 'mr' => array( 'Marathi', 'मराठी' ),
			'fil' => array( 'Filipino', 'Filipino' ), 'sw' => array( 'Swahili', 'Kiswahili' ),
		);
	}

	/** Supported identity aliases only; public source locale/code stay unchanged. */
	public static function canonical_identity( string $language ): string {
		return array( 'nb' => 'no', 'iw' => 'he', 'in' => 'id' )[ $language ] ?? $language;
	}

	public static function preference( $value ): string {
		if ( is_string( $value ) ) { $value = self::canonical_identity( $value ); }
		if ( ! is_string( $value ) || ( 'site' !== $value && ! isset( self::languages()[ $value ] ) ) ) {
			throw new ContractException( 'invalid_input', 'Choose an available chat language.' );
		}
		return $value;
	}

	/** get_locale respects the public WPML language, unlike a user's admin locale. */
	public static function site_locale( ?object $context = null ): string {
		$locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		$target = $context->builder_document->native_id ?? $context->target ?? '';
		if ( is_string( $target ) && preg_match( '/^(?:post|listing):([1-9][0-9]*)$/D', $target, $match ) && function_exists( 'apply_filters' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, (int) $match[1] );
			if ( is_array( $details ) && is_string( $details['locale'] ?? null ) ) { $locale = $details['locale']; }
		}
		return is_string( $locale ) && preg_match( '/^[a-zA-Z]{2,3}(?:[_-][a-zA-Z0-9]{2,8}){0,3}$/D', $locale ) ? $locale : 'en_US';
	}

	public static function describe( string $locale ): array {
		$code = str_replace( '_', '-', $locale );
		$source_base = strtolower( explode( '-', $code )[0] );
		$base = self::canonical_identity( $source_base );
		$names = 'nb' === $source_base ? array( 'Norwegian Bokmål', 'Norsk bokmål' ) : ( self::languages()[ $code ] ?? self::languages()[ $base ] ?? array( $code, $code ) );
		return array( 'locale' => $locale, 'code' => $code, 'base' => $base, 'name' => $names[0], 'label' => $names[0] === $names[1] ? $names[0] : $names[1] . ' (' . $names[0] . ')', 'direction' => in_array( $base, array( 'ar', 'fa', 'he', 'ur' ), true ) ? 'rtl' : 'ltr' );
	}

	/** Match the captured public language in queues, and restore the caller afterward. */
	public static function enter_site_scope( ?object $policy ): \Closure {
		$noop = static function (): void {};
		if ( ! function_exists( 'apply_filters' ) || ! isset( $policy->site->locale ) ) { return $noop; }
		$previous = apply_filters( 'wpml_current_language', null );
		if ( ! is_string( $previous ) || '' === $previous ) { return $noop; }
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( ! is_array( $languages ) && isset( $GLOBALS['sitepress'] ) && method_exists( $GLOBALS['sitepress'], 'get_active_languages' ) ) {
			$languages = $GLOBALS['sitepress']->get_active_languages();
		}
		$target = null;
		foreach ( is_array( $languages ) ? $languages : array() as $code => $language ) {
			if ( is_array( $language ) && str_replace( '-', '_', $language['default_locale'] ?? '' ) === str_replace( '-', '_', $policy->site->locale ) && preg_match( '/^[a-z]{2,3}(?:-[a-zA-Z]{2,8})?$/D', $code ) ) { $target = $code; break; }
		}
		if ( null === $target ) { throw new ContractException( 'unsupported_dependency', 'The captured public site language is unavailable.' ); }
		if ( $target !== $previous ) { do_action( 'wpml_switch_language', $target ); }
		return static function () use ( $previous ): void { do_action( 'wpml_switch_language', $previous ); };
	}

	public static function configuration(): array {
		return array( 'site' => self::describe( self::site_locale() ), 'choices' => array_map( static fn( string $code ): array => array( 'code' => $code, 'label' => self::describe( $code )['label'] ), array_keys( self::languages() ) ) );
	}

	public static function snapshot( object $context, object $input, string $profile ): object {
		$site = self::describe( self::site_locale( $context ) );
		$chosen = 'visitor' === $profile ? clone $context : new \stdClass();
		if ( 'visitor' === $profile && true === ( $input->chat_language_confirmed ?? false ) && isset( $input->chat_language ) ) { $chosen = self::explicit_context( $chosen, $input->chat_language ); }
		return self::with_conversation( (object) array( 'site' => (object) $site ), $chosen );
	}

	/** Server-owned state; legacy explicit choices are already resolved. */
	public static function conversation_state( object $context ): array {
		$confirmed = true === ( $context->chat_language_confirmed ?? false );
		$resolved = $confirmed || true === ( $context->chat_language_resolved ?? false );
		$revision = is_int( $context->chat_language_revision ?? null ) && $context->chat_language_revision >= 0 ? $context->chat_language_revision : 0;
		return array( 'chat_language' => self::preference( $context->chat_language ?? 'site' ), 'chat_language_confirmed' => $confirmed, 'chat_language_resolved' => $resolved, 'chat_language_source' => $confirmed ? 'explicit' : ( $resolved ? 'auto' : 'default' ), 'chat_language_revision' => $revision );
	}

	/** Refresh conversation preference without changing a queued turn's public locale. */
	public static function with_conversation( object $captured, object $context ): object {
		$state = self::conversation_state( $context );
		$policy = clone $captured;
		$policy->preference = $state['chat_language'];
		$policy->confirmed = $state['chat_language_confirmed'];
		$policy->resolved = $state['chat_language_resolved'];
		$policy->source = $state['chat_language_source'];
		$policy->revision = $state['chat_language_revision'];
		$policy->reply = 'site' === $policy->preference ? clone $policy->site : (object) self::describe( $policy->preference );
		return $policy;
	}

	public static function explicit_context( object $context, string $preference ): object {
		$previous = self::conversation_state( $context );
		$result = clone $context;
		$result->chat_language = self::preference( $preference );
		$result->chat_language_confirmed = true;
		$result->chat_language_resolved = true;
		$result->chat_language_source = 'explicit';
		$result->chat_language_revision = $previous['chat_language_revision'] + ( $previous['chat_language_confirmed'] && $previous['chat_language'] === $result->chat_language ? 0 : 1 );
		return $result;
	}

	public static function resolved( object $policy ): bool {
		return true === ( $policy->confirmed ?? false ) || true === ( $policy->resolved ?? false );
	}

	/** Site-generated wording, short greetings and indeterminate input are not preferences. */
	public static function non_substantive( string $text, bool $site_starter = false ): bool {
		$text = trim( preg_replace( '/[.!?।؟]+$/u', '', trim( $text ) ) );
		return $site_starter || (bool) preg_match( '/^(?:Find listings near me(?: within .+)?|Show popular listings|hi|hello|hey|hej|hola|bonjour|hallo|thanks|thank you|tack|gracias|salut)$/iuD', $text );
	}

	/** A direct answer to this owned language offer is not a new search. */
	public static function offer_choice( string $text, string $offered, string $current ): ?string {
		$text = preg_replace( '/^[\s.!?।؟]+|[\s.!?।؟]+$/u', '', $text );
		if ( preg_match( '/^(?:yes|yes please|sure|okay|ok|sí|si|oui|ja|sim|sì|হ্যাঁ|হ্যা|हाँ|نعم|да)$/iuD', $text ) ) { return self::preference( $offered ); }
		if ( preg_match( '/^(?:no|no thanks|nej|non|nein|não|না|नहीं|لا|нет)$/iuD', $text ) ) { return self::preference( $current ); }
		return null;
	}

	/** Unconfirmed foreign input must pass the language planner before fast answers. */
	public static function may_use_native_helpers( object $policy, string $text ): bool {
		return self::resolved( $policy ) || self::non_substantive( $text );
	}

	/** Classification is part of the existing model call, not an extra inference. */
	public static function planner_schema( array $schema ): array {
		foreach ( $schema['oneOf'] as &$normal ) {
			if ( ! in_array( 'search', $normal['properties']['intent']['enum'] ?? array(), true ) ) { continue; }
		$normal['properties']['request_language'] = array( 'enum' => array_merge( array_keys( self::languages() ), array( 'und' ) ), 'description' => 'Language of the substantive current visitor question, independent of the site/reply language. Use und for a name, quotation, built-in starter, or genuinely indeterminate fragment.' );
		$normal['properties']['language_question'] = array( 'type' => 'string', 'maxLength' => 500, 'description' => 'If the question language differs from the selected reply language, ask whether to continue in that language. Write this brief question in the CURRENT reply language. Otherwise use an empty string.' );
		$normal['required'] = array_values( array_unique( array_merge( $normal['required'] ?? array(), array( 'request_language' ) ) ) );
		}
		unset( $normal );
		return $schema;
	}

	/** A normal task cannot silently bypass an explicitly classified foreign question. */
	public static function resolve_plan( object $plan, object $policy, string $question, bool $site_starter = false ): ?object {
		$reply_base = self::canonical_identity( (string) ( $policy->reply->base ?? '' ) );
		// Some models annotate a normal task with a switch to the language already
		// selected by this conversation. This is redundant transport metadata, not
		// a visitor decision. A foreign offer/switch still requires its own plan.
		if ( 'language' !== ( $plan->intent ?? null ) && 'switch' === ( $plan->language_action ?? null ) && is_string( $plan->language ?? null ) && isset( self::languages()[ $plan->language ] ) && ( $plan->language === ( $policy->reply->code ?? null ) || ( ! str_contains( $plan->language, '-' ) && self::describe( $plan->language )['base'] === $reply_base ) ) ) {
			unset( $plan->language, $plan->language_action );
		}
		if ( 'language' === ( $plan->intent ?? null ) || self::resolved( $policy ) ) {
			unset( $plan->request_language, $plan->language_question );
			return null;
		}
		if ( self::non_substantive( $question, $site_starter ) ) { unset( $plan->request_language, $plan->language_question ); return null; }
		$language = $plan->request_language ?? null;
		if ( ! is_string( $language ) || ( 'und' !== $language && ! isset( self::languages()[ $language ] ) ) ) {
			throw new ContractException( 'invalid_input', 'Before a normal visitor task, declare request_language from the substantive question. Do not infer the question language from the site default.' );
		}
		unset( $plan->request_language, $plan->language_question );
		if ( self::non_substantive( $question, $site_starter ) || 'und' === $language ) { return null; }
		if ( self::describe( $language )['base'] === $reply_base ) { $policy->resolved = true; $policy->source = 'auto'; return null; }
		return (object) array( 'intent' => 'language', 'language' => $language, 'language_action' => 'offer', 'question' => self::offer_question( $language, $policy ) );
	}

	/** The model detects language; fixed interface copy follows the current conversation. */
	public static function offer_question( string $target, object $policy ): string {
		self::preference( $target );
		if ( 'site' === $target ) { throw new ContractException( 'invalid_input', 'A language offer needs an explicit language.' ); }
		$locale = $policy->reply->code ?? $policy->reply->base ?? 'en';
		$base = self::canonical_identity( (string) ( $policy->reply->base ?? 'en' ) );
		$key = 'zh' === $base ? ( preg_match( '/(?:Hant|TW|HK|MO)/i', $locale ) ? 'zh-Hant' : 'zh-Hans' ) : $base;
		static $templates = null;
		if ( null === $templates ) {
			$path = __DIR__ . '/language-offer-translations.json';
			$raw = is_readable( $path ) ? file_get_contents( $path ) : false;
			$templates = is_string( $raw ) ? json_decode( $raw, true ) : array();
		}
		$template = $templates[ $key ] ?? 'Would you like to continue in %s?';
		if ( ! is_string( $template ) || substr_count( $template, '%s' ) !== 1 ) { $template = 'Would you like to continue in %s?'; }
		$language = self::languages()[ $target ][1];
		if ( class_exists( '\Locale' ) ) {
			$translated = \Locale::getDisplayName( str_replace( '-', '_', $target ), str_replace( '-', '_', $locale ) );
			if ( is_string( $translated ) && '' !== $translated && mb_strlen( $translated ) <= 100 ) { $language = $translated; }
		}
		return sprintf( $template, $language );
	}

	public static function instruction( string $profile, object $policy, bool $writing = false, bool $language_gate = true ): string {
		$name = $policy->reply->name . ' (' . $policy->reply->code . ')';
		if ( 'visitor' !== $profile ) {
			return "\n" . 'Site language policy: The configured public content language is ' . $name . '. Write generated listing copy, directory labels, page headings, explanations and suggestions in this language, even when the administrator writes their request in another language. An explicit request for a different output language overrides this default. Preserve existing unrequested content and exact native field IDs, option values, bindings and facts. The administrator interface language is not the content-language setting.';
		}
		$gate = ! $writing && ! self::resolved( $policy ) && $language_gate;
		$instruction = "\nChat language policy: The selected reply language is " . $name . '. Use it for answers, clarifications and EVERY suggestion label/text, overriding general instructions to mirror the question. Preserve proper names, addresses, exact field IDs/option values, amounts and factual meaning. Never translate a stored value before an exact native filter. ';
		if ( true === ( $policy->confirmed ?? false ) ) {
			$instruction .= 'The visitor explicitly chose this conversation language. Keep it until explicitly changed. ';
		} elseif ( self::resolved( $policy ) ) {
			$instruction .= 'This conversation language was automatically resolved. Keep it until explicitly changed; do not classify later questions or offer an automatic switch. ';
		} elseif ( $gate ) {
			$instruction .= 'Classify this first substantive CURRENT question with request_language from the declared schema, independently of the site/reply language. How many listings are there? is English even on a Spanish site. Use und only for names, quotations, greetings, site-generated starters or truly indeterminate fragments, never a clear typed sentence. A different language requires a choice before answering. ';
		} else {
			$instruction .= 'This starter or indeterminate input does not settle a visitor language preference. Use the current default without an automatic language offer. ';
		}
		if ( $writing ) {
			$instruction .= 'This is the final-answer phase: write the answer and EVERY suggestion in the selected reply language. Translate source-language narrative descriptions and explanatory clauses; keep only proper names, addresses and literal native option values in their original form. Do not copy a source-language sentence into your narration. ';
		} elseif ( $gate ) {
			$instruction .= 'Before a normal task, include request_language. A detected foreign language may use EXACTLY {"intent":"language","language":"bn","language_action":"offer","question":"Would you like to continue in Bengali?"}, replacing bn and the question. Write the question in the CURRENT reply language; do not combine a language intent with task fields or answer the substantive goal until the visitor chooses. ';
		} else {
			$instruction .= 'Do not return automatic language offers or request_language. An explicit natural request to change reply language may still use a language intent with language_action switch. ';
		}
		return $instruction . 'An explicit request for another reply language can select it directly with language_action switch, without asking again. Supported codes: ' . implode( ', ', array_keys( self::languages() ) ) . '. Never switch merely because of a listing name, greeting, quotation or site-generated starter. Do not repeat a language intent for the selected language. Language choice never changes directory scope, listing content, site settings or native action permission.';
	}
}
