<?php
/**
 * Safe HTML formatting for sales customer email intros.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * SalesEmailHtml helper.
 */
final class SalesEmailHtml {

	/**
	 * Resolve a subject or intro template with unified {{group:slug}} merge tags.
	 *
	 * @param string                         $template       Template text or HTML.
	 * @param AutomationContactModel|null    $merge_context  Contact + document context.
	 * @param string                         $document_type  proposal|invoice|credit_note|contract|subscription.
	 * @param callable(string): string|null  $merge_resolver Optional custom merge-tag resolver.
	 * @return string
	 */
	public static function resolve_template(
		string $template,
		?AutomationContactModel $merge_context,
		string $document_type,
		?callable $merge_resolver = null
	): string {
		$content = SalesEmailLegacyTokens::migrate( $template, $document_type );

		return self::apply_merge_tags( $content, $merge_context, $merge_resolver );
	}

	/**
	 * Resolve intro HTML from a per-send custom message or a settings template.
	 *
	 * @param string                         $custom_message Optional override from the sender.
	 * @param string                         $intro_tpl      Settings template (may contain merge tags).
	 * @param string                         $fallback_plain Plain-text fallback when empty.
	 * @param AutomationContactModel|null    $merge_context  Contact + document context.
	 * @param string                         $document_type  proposal|invoice|credit_note|contract|subscription.
	 * @param callable(string): string|null  $merge_resolver Optional custom merge-tag resolver.
	 * @return string Safe HTML.
	 */
	public static function resolve_intro_html(
		string $custom_message,
		string $intro_tpl,
		string $fallback_plain,
		?AutomationContactModel $merge_context,
		string $document_type,
		?callable $merge_resolver = null
	): string {
		$source  = '' !== trim( wp_strip_all_tags( $custom_message ) ) ? $custom_message : $intro_tpl;
		$content = self::resolve_template( $source, $merge_context, $document_type, $merge_resolver );

		return self::format_intro( $content, $fallback_plain );
	}

	/**
	 * Apply {{group:slug}} merge tags after legacy token migration.
	 *
	 * @param string                         $content        Content with tokens already migrated.
	 * @param AutomationContactModel|null    $merge_context  Contact context.
	 * @param callable(string): string|null  $merge_resolver Optional custom resolver.
	 * @return string
	 */
	public static function apply_merge_tags(
		string $content,
		?AutomationContactModel $merge_context = null,
		?callable $merge_resolver = null
	): string {
		if ( is_callable( $merge_resolver ) ) {
			return (string) $merge_resolver( $content );
		}

		return SalesEmailMergeTags::resolve( $content, $merge_context );
	}

	/**
	 * Format stored or token-replaced intro content for email bodies.
	 *
	 * @param string $content        Intro HTML or plain text.
	 * @param string $fallback_plain Plain-text fallback when empty.
	 * @return string Safe HTML.
	 */
	public static function format_intro( string $content, string $fallback_plain ): string {
		$text = trim( $content );
		if ( '' === $text || '' === trim( wp_strip_all_tags( $text ) ) ) {
			$text = $fallback_plain;
		}

		if ( self::contains_html( $text ) ) {
			return wp_kses_post( $text );
		}

		return \wpautop( esc_html( $text ) );
	}

	/**
	 * @param string $text Content.
	 * @return bool
	 */
	private static function contains_html( string $text ): bool {
		return $text !== wp_strip_all_tags( $text );
	}
}
