<?php
/**
 * AI Context Summarizer.
 *
 * Builds short, plain-text CRM summaries that are folded into AI generation
 * prompts so inline drafts (contact/deal emails, ticket replies) reference real
 * records instead of just a name. Every section honors the admin's `data_access`
 * governance toggles and guards each model independently with class_exists() so
 * it degrades gracefully when a toggleable module (Deals, Activities, Support)
 * is not installed/active.
 *
 * This mirrors the AI Assistant addon's AI_Context_Builder::for_*() helpers, but
 * lives in Pro core so the generators work without the optional addon.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Ai;

use DoubleScale\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * AiContextSummarizer class.
 */
final class AiContextSummarizer {

	// Model FQCNs referenced as strings so the file loads regardless of which
	// modules are active. Resolved through class_exists() before use.
	const CONTACT_MODEL  = '\\DoubleScale\\Modules\\Contacts\\Models\\ContactModel';
	const DEAL_MODEL     = '\\DoubleScale\\Pro\\Modules\\Deals\\Models\\DealModel';
	const ACTIVITY_MODEL = '\\DoubleScale\\Modules\\Activities\\Models\\ActivityModel';
	const TICKET_MODEL   = '\\DoubleScale\\Modules\\Support\\Models\\TicketModel';

	/**
	 * Read the admin's AI data-access governance toggles.
	 *
	 * @return array
	 */
	private static function data_access(): array {
		$ai = Settings::get( 'ai', array() );
		return is_array( $ai['data_access'] ?? null ) ? $ai['data_access'] : array();
	}

	/**
	 * Whether a module is active (true when the gate function is unavailable).
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	private static function module_active( string $slug ): bool {
		if ( function_exists( 'doublescale_is_module_active' ) ) {
			return doublescale_is_module_active( $slug );
		}
		return true;
	}

	/**
	 * Summarize a contact: basics + recent activities + open deals.
	 *
	 * Gated on the `crm_data` toggle. The activities/deals sections are gated
	 * independently (their own toggle / module) so a contact still summarizes
	 * when Activities or Deals are off.
	 *
	 * @param int $contact_id Contact ID.
	 * @return string Empty string when unavailable or access denied.
	 */
	public static function for_contact( int $contact_id ): string {
		$access = self::data_access();
		if ( ! ( $access['crm_data'] ?? true ) ) {
			return '';
		}

		$contact_model = self::CONTACT_MODEL;
		if ( $contact_id <= 0 || ! class_exists( $contact_model ) ) {
			return '';
		}

		$contact = $contact_model::find( $contact_id );
		if ( ! $contact ) {
			return '';
		}

		$parts   = array();
		$parts[] = sprintf(
			'Contact: %s %s (Email: %s)',
			$contact->first_name,
			$contact->last_name,
			$contact->email
		);
		if ( ! empty( $contact->phone ) ) {
			$parts[] = 'Phone: ' . $contact->phone;
		}
		$location = trim( (string) ( $contact->city ?? '' ) . ', ' . (string) ( $contact->country ?? '' ), ', ' );
		if ( '' !== $location ) {
			$parts[] = 'Location: ' . $location;
		}
		if ( ! empty( $contact->source ) ) {
			$parts[] = 'Source: ' . $contact->source;
		}

		// Recent activities — gated on the activity_data toggle AND the module.
		$activity_model = self::ACTIVITY_MODEL;
		if ( ( $access['activity_data'] ?? true ) && self::module_active( 'activities' ) && class_exists( $activity_model ) ) {
			$activities = $activity_model::query()
				->forContact( $contact_id )
				->orderBy( 'created_at', 'desc' )
				->limit( 5 )
				->get();
			if ( $activities->count() ) {
				$parts[] = 'Recent activity:';
				foreach ( $activities as $activity ) {
					$parts[] = sprintf( '- %s (%s)', $activity->activity_type, $activity->created_at );
				}
			}
		}

		// Open deals — only when the Deals module is present/active.
		$deal_model = self::DEAL_MODEL;
		if ( self::module_active( 'deals' ) && class_exists( $deal_model ) ) {
			$deals = $deal_model::where( 'contact_id', $contact_id )
				->where( 'status', 'open' )
				->limit( 5 )
				->get();
			if ( $deals->count() ) {
				$parts[] = 'Open deals:';
				foreach ( $deals as $deal ) {
					$parts[] = sprintf( '- %s (Value: %s)', $deal->title, $deal->value );
				}
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Summarize a deal: title/value/stage + linked contact basics.
	 *
	 * Gated on the `crm_data` toggle and the Deals module.
	 *
	 * @param int $deal_id Deal ID.
	 * @return string Empty string when unavailable or access denied.
	 */
	public static function for_deal( int $deal_id ): string {
		$access = self::data_access();
		if ( ! ( $access['crm_data'] ?? true ) ) {
			return '';
		}

		$deal_model = self::DEAL_MODEL;
		if ( $deal_id <= 0 || ! self::module_active( 'deals' ) || ! class_exists( $deal_model ) ) {
			return '';
		}

		$deal = $deal_model::with( array( 'contact', 'stage' ) )->find( $deal_id );
		if ( ! $deal ) {
			return '';
		}

		$parts   = array();
		$stage   = $deal->stage ? ( $deal->stage->name ?? '' ) : '';
		$parts[] = sprintf(
			'Deal: %s (Value: %s%s, Status: %s)',
			$deal->title,
			$deal->value,
			'' !== $stage ? ', Stage: ' . $stage : '',
			$deal->status
		);

		if ( $deal->contact ) {
			$parts[] = sprintf(
				'Contact: %s %s (%s)',
				$deal->contact->first_name,
				$deal->contact->last_name,
				$deal->contact->email
			);
		}

		return implode( "\n", $parts );
	}

	/**
	 * Summarize a ticket: header + the last few real messages.
	 *
	 * Gated on the `support_data` toggle and the Support module. Filters the
	 * conversation to actual replies/notes (drops empty system-event rows) so
	 * the model sees real messages, not status-change noise.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @param int $limit     Max conversation messages to include.
	 * @return string Empty string when unavailable or access denied.
	 */
	public static function for_ticket( int $ticket_id, int $limit = 6 ): string {
		$access = self::data_access();
		if ( ! ( $access['support_data'] ?? true ) ) {
			return '';
		}

		$ticket_model = self::TICKET_MODEL;
		if ( $ticket_id <= 0 || ! self::module_active( 'support' ) || ! class_exists( $ticket_model ) ) {
			return '';
		}

		$ticket = $ticket_model::with( array( 'contact', 'agent' ) )->find( $ticket_id );
		if ( ! $ticket ) {
			return '';
		}

		$parts   = array();
		$parts[] = sprintf(
			'Support ticket: %s (Status: %s, Priority: %s)',
			$ticket->title,
			$ticket->status,
			$ticket->priority
		);
		if ( $ticket->contact ) {
			$parts[] = sprintf(
				'Customer: %s %s (%s)',
				$ticket->contact->first_name,
				$ticket->contact->last_name,
				$ticket->contact->email
			);
		}

		// Only real messages — replies + internal notes — newest dropped to the
		// bottom in chronological order. Mirrors the de-noised query used by the
		// AI Assistant Support tools.
		$types         = array();
		$activity_type = '\\DoubleScale\\Core\\Constants\\ActivityTypes';
		if ( class_exists( $activity_type ) ) {
			$types = array( $activity_type::SUPPORT_REPLY, $activity_type::SUPPORT_NOTE );
		}

		$query = $ticket->conversations();
		if ( ! empty( $types ) ) {
			$query->whereIn( 'activity_type', $types );
		}
		$messages = $query->orderBy( 'created_at', 'desc' )
			->limit( $limit )
			->get()
			->reverse()
			->values();

		$lines = array();
		foreach ( $messages as $activity ) {
			$data    = is_array( $activity->data ) ? $activity->data : array();
			$content = self::clean_message_text( (string) ( $data['content'] ?? '' ) );
			if ( '' === $content ) {
				continue;
			}
			$author  = $activity->user_id ? ( get_userdata( (int) $activity->user_id )->display_name ?? 'Agent' ) : 'Customer';
			$lines[] = sprintf( '- %s: %s', $author, $content );
		}

		if ( ! empty( $lines ) ) {
			$parts[] = 'Conversation (oldest to newest):';
			$parts   = array_merge( $parts, $lines );
		}

		return implode( "\n", $parts );
	}

	/**
	 * Reduce a stored message body to clean prose for the AI prompt.
	 *
	 * Inbound HTML emails are stored after wp_kses_post(), which drops <style>
	 * and <head> tags but keeps their inner text — so raw CSS rules and Outlook
	 * conditional-comment remnants survive as plain text and would otherwise eat
	 * the whole length budget before any real message is reached. Strip the
	 * leftover markup, drop the CSS/conditional cruft, collapse the whitespace
	 * the removed layout tables leave behind, and only then truncate.
	 *
	 * @param string $raw Stored message content (HTML or already-stripped text).
	 * @param int    $max Max characters of the cleaned result.
	 * @return string Cleaned, length-capped prose ('' when nothing meaningful remains).
	 */
	private static function clean_message_text( string $raw, int $max = 400 ): string {
		// Remove HTML comments (incl. hidden email preheader/preview text and MSO
		// conditionals) before tag-stripping, since wp_strip_all_tags keeps their
		// inner text.
		$raw  = preg_replace( '/<!--.*?-->/s', '', $raw );
		$text = wp_strip_all_tags( (string) $raw );

		// Decode entities so spacer/zero-width chars (&nbsp; &zwnj; &#847;) email
		// designers use for layout padding become real characters we can drop,
		// instead of surviving as literal "&zwnj;" noise in the prompt.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Strip the now-decoded invisible padding (NBSP, ZWNJ, ZWSP, combining
		// grapheme joiner, word joiner) that adds no meaning for the model.
		$text = preg_replace( '/[\x{00A0}\x{200B}-\x{200D}\x{2060}\x{034F}\x{FEFF}]+/u', ' ', $text );

		// Drop @media / @import / @font-face rule blocks and any bare CSS
		// declaration blocks (selector { prop: value; ... }) left as text.
		$text = preg_replace( '/@(?:media|import|font-face|supports|keyframes)[^{}]*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/i', '', (string) $text );
		$text = preg_replace( '/[^{}\n]*\{[^{}]*\}/', '', (string) $text );
		// Collapse the whitespace explosion from removed layout tables/markup.
		$text = preg_replace( '/\s+/', ' ', (string) $text );

		return mb_substr( trim( (string) $text ), 0, $max );
	}
}
