<?php
/**
 * The help assistant's settings, with defaults and bounds.
 *
 *   name, fallback, welcome   How the assistant introduces itself and what it
 *                             says when the articles hold no answer.
 *   channels                  Where customers may ask: portal, widget (the
 *                             floating button; the help center and its
 *                             articles follow it, help_page has no switch of
 *                             its own since 2026-11-17). All OFF until the owner turns them on
 *                             (after trying the assistant in the admin): an
 *                             AI key alone must never start spending money.
 *   widget                    Floating button: position (right/left; by default
 *                             the side lines end on), color,
 *                             pages (ids; empty = every page).
 *   live_chat                 The team answers in the chat after a handoff
 *                             (LiveChat). OFF until the owner turns it on: off,
 *                             a handed-off conversation is a ticket answered by
 *                             email and the assistant keeps the chat.
 *   handoff_mailbox           Mailbox for tickets opened from a conversation
 *                             (0 = the default mailbox).
 *   retention_days            Conversations are deleted this many days after
 *                             their last message (default 180, at least 7).
 *   limits                    per_minute / per_hour per visitor, per
 *                             conversation, daily for the whole site, and
 *                             concurrent answers being written.
 *
 * Saving drops every kept answer ({@see AnswerCache}): a new name or fallback
 * changes what the assistant says.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

use DoubleScale\Pro\Modules\HelpAssistant\Rest\Controllers\RestHelpPublicChatController;

defined( 'ABSPATH' ) || exit;

/**
 * HelpSettings class.
 */
final class HelpSettings {

	public const OPTION = 'doublescale_help_assistant_settings';

	public const CHANNELS = array( 'portal', 'help_page', 'widget' );

	/**
	 * Bounds per limit: default, min, max.
	 */
	private const LIMITS = array(
		'per_minute'       => array( 6, 1, 60 ),
		'per_hour'         => array( 30, 1, 1000 ),
		'per_conversation' => array( 20, 1, 100 ),
		'daily'            => array( 300, 1, 100000 ),
		'concurrent'       => array( 4, 1, 50 ),
	);

	/**
	 * Settings with defaults filled in and every value in bounds.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		return self::normalize( (array) get_option( self::OPTION, array() ) );
	}

	/**
	 * Merge $changes into the stored settings (nested groups merge too).
	 *
	 * @param array<string, mixed> $changes Partial settings.
	 * @return array<string, mixed> Stored settings.
	 */
	public static function update( array $changes ): array {
		$current = self::get();
		foreach ( array( 'channels', 'widget', 'limits', 'site_pages' ) as $group ) {
			if ( isset( $changes[ $group ] ) && is_array( $changes[ $group ] ) ) {
				$changes[ $group ] = array_merge( $current[ $group ], $changes[ $group ] );
			}
		}

		// The AI switch as it was ($current): a site from before the switch
		// keeps what it had (on when customers could already ask somewhere)
		// and from now on it only changes when someone flips it. Turning on
		// a place for live chat never turns the AI on by itself.
		$settings = self::normalize( array_merge( $current, $changes ) );
		update_option( self::OPTION, $settings, false );
		AnswerCache::flush();

		return $settings;
	}

	/**
	 * Whether customers may ask on $channel (help_page: the help center and
	 * its articles, which follow the floating button).
	 *
	 * @param string $channel portal, help_page or widget.
	 * @return bool
	 */
	public static function channel_on( string $channel ): bool {
		$settings = self::get();
		// The help center has no switch of its own: its pages show the
		// floating chat whenever the site's floating button is on.
		if ( 'help_page' === $channel ) {
			$channel = 'widget';
		}

		return ! empty( $settings['channels'][ $channel ] ) && self::chat_on();
	}

	/**
	 * The AI assistant's main switch (AI Assistant page).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return ! empty( self::get()['enabled'] );
	}

	/**
	 * The AI answers in the chat: switched on and an AI provider connected.
	 * Otherwise (with live chat on) the chat goes straight to the team.
	 *
	 * @return bool
	 */
	public static function assistant_answers(): bool {
		// The AI answers from the knowledge base: no help center, no AI answers
		// (live chat still works with only the Helpdesk on).
		return self::enabled() && RestHelpPublicChatController::ai_ready() && \DoubleScale\Pro\Modules\HelpAssistant\Module::kb_ready();
	}

	/**
	 * Customers get a chat at all: someone answers in it — the AI assistant,
	 * the team (live chat, Helpdesk → Settings → Live chat), or both.
	 *
	 * @return bool
	 */
	public static function chat_on(): bool {
		return LiveChat::enabled() || self::assistant_answers();
	}

	/**
	 * Days to keep conversations: 180 when missing or not a positive number,
	 * otherwise between 7 and 3650.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	private static function retention( $value ): int {
		$days = is_numeric( $value ) ? (int) $value : 0;

		return $days < 1 ? 180 : max( 7, min( 3650, $days ) );
	}

	/**
	 * Ready answers: both fields filled, no markup, at most 30.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<int, array{question: string, answer: string}>
	 */
	private static function ready_answers( $value ): array {
		$out = array();
		foreach ( (array) $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$question = mb_substr( trim( sanitize_text_field( (string) ( $item['question'] ?? '' ) ) ), 0, 200, 'UTF-8' );
			$answer   = mb_substr( trim( sanitize_textarea_field( (string) ( $item['answer'] ?? '' ) ) ), 0, 1000, 'UTF-8' );
			if ( '' !== $question && '' !== $answer ) {
				$out[] = array(
					'question' => $question,
					'answer'   => $answer,
				);
			}
		}

		return array_slice( $out, 0, 30 );
	}

	/**
	 * Buttons under the welcome: one per line or a list, trimmed, without
	 * repeats, at most four of at most 80 characters.
	 *
	 * @param mixed $value Submitted value.
	 * @return string[]
	 */
	private static function quick_replies( $value ): array {
		$lines = is_array( $value ) ? $value : preg_split( '/\R/u', (string) $value );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = mb_substr( trim( sanitize_text_field( (string) $line ) ), 0, 80, 'UTF-8' );
			if ( '' !== $line && ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
		}

		return array_slice( $out, 0, 4 );
	}

	/**
	 * Greetings for a page, a time of day or a returning visitor: at most
	 * ten, each with its words (no markup, at most 200 characters; a rule
	 * without words is dropped), the pages it is for (ids) and/or address
	 * parts ("/shop"), the hours it is for (site time, from-to, 22-6 runs
	 * over midnight; null = all day) and who (any, new or returning). The
	 * first that fits is shown; none fits = the default greeting.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function greeting_rules( $value ): array {
		$out = array();
		foreach ( (array) $value as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$text = mb_substr( trim( sanitize_textarea_field( wp_strip_all_tags( (string) ( $rule['text'] ?? '' ) ) ) ), 0, 200, 'UTF-8' );
			if ( '' === $text ) {
				continue;
			}
			$paths = array();
			foreach ( array_slice( (array) ( $rule['paths'] ?? array() ), 0, 5 ) as $path ) {
				$path = mb_substr( trim( sanitize_text_field( (string) $path ) ), 0, 100, 'UTF-8' );
				if ( '' !== $path ) {
					$paths[] = '/' === $path[0] ? $path : '/' . $path;
				}
			}
			$hours = null;
			if ( isset( $rule['hours'] ) && is_array( $rule['hours'] ) ) {
				$hours = array(
					'from' => max( 0, min( 23, (int) ( $rule['hours']['from'] ?? 0 ) ) ),
					'to'   => max( 0, min( 24, (int) ( $rule['hours']['to'] ?? 0 ) ) ),
				);
			}
			$out[] = array(
				'text'    => $text,
				'pages'   => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $rule['pages'] ?? array() ) ) ) ) ),
				'paths'   => $paths,
				'hours'   => $hours,
				'visitor' => in_array( $rule['visitor'] ?? '', array( 'new', 'returning' ), true ) ? $rule['visitor'] : 'any',
			);
		}

		return array_slice( $out, 0, 10 );
	}

	/**
	 * @param array<string, mixed> $raw Site pages settings.
	 * @return array{enabled: bool, post_types: string[], excluded: int[], included: int[]}
	 */
	private static function site_pages( array $raw ): array {
		$types = array();
		foreach ( (array) ( $raw['post_types'] ?? array( 'page' ) ) as $type ) {
			$type = sanitize_key( (string) $type );
			if ( '' !== $type && post_type_exists( $type ) && is_post_type_viewable( $type ) && ! in_array( $type, array( 'attachment', 'ds_kb_article' ), true ) ) {
				$types[] = $type;
			}
		}

		return array(
			'enabled'    => ! empty( $raw['enabled'] ),
			'post_types' => array() !== $types ? array_values( array_unique( $types ) ) : array( 'page' ),
			'excluded'   => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $raw['excluded'] ?? array() ) ) ) ) ),
			// Pages nothing on the site links to, turned on by the owner (only that
			// reason is lifted; SitePages::reason()).
			'included'   => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $raw['included'] ?? array() ) ) ) ) ),
		);
	}

	/**
	 * @param array<string, mixed> $raw Stored or submitted settings.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $raw ): array {
		$channels = array();
		foreach ( self::CHANNELS as $channel ) {
			$channels[ $channel ] = ! empty( $raw['channels'][ $channel ] );
		}

		$widget = isset( $raw['widget'] ) && is_array( $raw['widget'] ) ? $raw['widget'] : array();
		$color  = sanitize_hex_color( (string) ( $widget['color'] ?? '' ) );
		$pages  = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $widget['pages'] ?? array() ) ) ) ) );

		$limits = array();
		foreach ( self::LIMITS as $key => $bounds ) {
			$value          = isset( $raw['limits'][ $key ] ) ? (int) $raw['limits'][ $key ] : $bounds[0];
			$limits[ $key ] = max( $bounds[1], min( $bounds[2], $value ) );
		}

		return array(
			// The main switch. Sites saved before it existed keep what they had:
			// on when customers could already ask somewhere.
			'enabled'  => array_key_exists( 'enabled', $raw ) ? ! empty( $raw['enabled'] ) : in_array( true, $channels, true ),
			'name'     => sanitize_text_field( (string) ( $raw['name'] ?? '' ) ),
			'fallback' => sanitize_textarea_field( (string) ( $raw['fallback'] ?? '' ) ),
			'welcome'  => sanitize_textarea_field( (string) ( $raw['welcome'] ?? '' ) ),
			'quick_replies' => self::quick_replies( $raw['quick_replies'] ?? array() ),
			// Ready answers: the owner's own question and answer, given word for word.
			'faq'      => self::ready_answers( $raw['faq'] ?? array() ),
			// The owner's guidance on tone and wording; never above the rules.
			'instructions' => mb_substr( trim( sanitize_textarea_field( (string) ( $raw['instructions'] ?? '' ) ) ), 0, 500, 'UTF-8' ),
			// The picture by the assistant's name (an image id) and the line under it.
			'avatar'   => absint( $raw['avatar'] ?? 0 ),
			'subtitle' => mb_substr( sanitize_text_field( (string) ( $raw['subtitle'] ?? '' ) ), 0, 80, 'UTF-8' ),
			// light (default), dark, or auto: the reader's device decides.
			'theme'    => in_array( $raw['theme'] ?? '', array( 'light', 'dark', 'auto' ), true ) ? $raw['theme'] : 'light',
			'channels' => $channels,
			'widget'   => array(
				'position' => in_array( $widget['position'] ?? '', array( 'left', 'right' ), true ) ? $widget['position'] : ( is_rtl() ? 'left' : 'right' ),
				// DoubleScale's brand blue (Button default) unless the owner picks one.
				'color'    => $color ? $color : '#3a3a99',
				'pages'    => $pages,
				// The greeting over the button (off unless the owner turns it on):
				// their words ('' = the default) after this many seconds.
				'greeting'       => ! empty( $widget['greeting'] ),
				'greeting_text'  => mb_substr( trim( sanitize_textarea_field( (string) ( $widget['greeting_text'] ?? '' ) ) ), 0, 200, 'UTF-8' ),
				'greeting_delay' => max( 0, min( 120, isset( $widget['greeting_delay'] ) ? (int) $widget['greeting_delay'] : 10 ) ),
				// Other words for some pages, times or returning visitors.
				'greeting_rules' => self::greeting_rules( $widget['greeting_rules'] ?? array() ),
			),
			'limits'   => $limits,
			// The team answers in the chat (off until the owner turns it on).
			'live_chat'     => ! empty( $raw['live_chat'] ),
			// The assistant learns from the site's pages (SitePages; off until turned on).
			'site_pages'    => self::site_pages( is_array( $raw['site_pages'] ?? null ) ? $raw['site_pages'] : array() ),
			// Read back by HandoffService, which falls back to the default when it is gone.
			'handoff_mailbox' => absint( $raw['handoff_mailbox'] ?? 0 ),
			// Conversations idle longer than this are deleted every day (Retention).
			// A cleared or invalid box means the default, never "one day".
			'retention_days'  => self::retention( $raw['retention_days'] ?? null ),
		);
	}
}
