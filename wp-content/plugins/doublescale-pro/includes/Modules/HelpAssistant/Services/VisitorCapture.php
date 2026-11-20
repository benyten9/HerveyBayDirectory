<?php
/**
 * What the team sees about a chat visitor, kept on the conversation.
 *
 * When the visitor asks for the team (and on their messages in a live chat,
 * at most once a minute):
 * - pages: the pages their browser kept for website tracking (a visitor who
 *   is not signed in has no tracked contact yet). Kept on the CONVERSATION,
 *   never the contact: the email they type is not proven theirs, so their
 *   pages must not land on that customer's record.
 * - cart: their WooCommerce cart (product names, quantities, totals), when
 *   the store runs and the browser has a cart session.
 *
 * Shown on the ticket by Support's VisitorPanel.
 *
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Pro\Modules\Support\Services\VisitorPanel;

/**
 * VisitorCapture class.
 */
final class VisitorCapture {

	/**
	 * Pages kept, like Intercom's recent page views.
	 */
	public const PAGES = 20;

	/**
	 * Cart lines kept.
	 */
	private const CART_LINES = 20;

	/**
	 * Seconds between two cart reads of one conversation.
	 */
	private const CART_EVERY = MINUTE_IN_SECONDS;

	/**
	 * The page-visits cookie of website tracking.
	 */
	private const PAGES_COOKIE = 'doublescale_page_visits';

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'doublescale_help_chat_handed_off', array( self::class, 'on_handoff' ), 10, 2 );
	}

	/**
	 * @param mixed $ticket       Ticket.
	 * @param mixed $conversation Conversation row (null without one).
	 * @return void
	 */
	public static function on_handoff( $ticket, $conversation ): void {
		unset( $ticket );
		if ( ! is_array( $conversation ) || empty( $conversation['id'] ) ) {
			return;
		}
		self::capture( $conversation, true );
	}

	/**
	 * A message in a live chat: the cart may have changed.
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @return void
	 */
	public static function on_message( array $conversation ): void {
		self::capture( $conversation, false );
	}

	/**
	 * @param array<string, mixed> $conversation Conversation row.
	 * @param bool                 $pages        Read the pages too (handoff).
	 * @return void
	 */
	private static function capture( array $conversation, bool $pages ): void {
		try {
			$context = ConversationStore::context( $conversation );
			$changed = false;

			if ( $pages ) {
				$context['pages'] = self::browser_pages();
				$changed          = true;
			}

			$last = isset( $context['cart']['at'] ) ? strtotime( (string) $context['cart']['at'] . ' UTC' ) : 0;
			if ( $pages || time() - (int) $last >= self::CART_EVERY ) {
				$cart = self::cart();
				if ( null !== $cart || isset( $context['cart'] ) ) {
					$context['cart'] = $cart;
					$changed         = true;
				}
			}

			if ( $changed ) {
				ConversationStore::set_context( (int) $conversation['id'], $context );
			}
		} catch ( \Throwable $e ) {
			// The team's side panel is a help, never a reason to lose the chat.
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error( 'Help chat visitor details could not be kept', array( 'exception' => get_class( $e ) ) );
			}
		}
	}

	/**
	 * The pages website tracking kept in this browser, newest first.
	 *
	 * @return array<int, array{path: string, at: string}>
	 */
	private static function browser_pages(): array {
		if ( ! Settings::is_website_tracking_enabled() || empty( $_COOKIE[ self::PAGES_COOKIE ] ) ) {
			return array();
		}
		$kept = json_decode( wp_unslash( (string) $_COOKIE[ self::PAGES_COOKIE ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every path goes through VisitorPanel::clean_path().
		if ( ! is_array( $kept ) ) {
			return array();
		}

		$pages = array();
		foreach ( array_reverse( $kept ) as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$path = VisitorPanel::clean_path( (string) ( $page['path'] ?? '' ) );
			$when = (int) ( $page['timestamp'] ?? 0 );
			if ( '' === $path || $when <= 0 ) {
				continue;
			}
			$pages[] = array(
				'path' => $path,
				'at'   => gmdate( 'Y-m-d H:i:s', min( $when, time() ) ),
			);
			if ( count( $pages ) >= self::PAGES ) {
				break;
			}
		}

		return $pages;
	}

	/**
	 * The visitor's WooCommerce cart, or null (no store, no cart session,
	 * empty cart).
	 *
	 * @return array{items: array<int, array{name: string, quantity: int, total: float}>, total: float, currency: string, at: string}|null
	 */
	private static function cart(): ?array {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_load_cart' ) || ! defined( 'COOKIEHASH' ) ) {
			return null;
		}
		// No cart session in this browser: never start one for the chat.
		if ( empty( $_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] ) && ! is_user_logged_in() ) {
			return null;
		}
		if ( null === WC()->cart ) {
			// REST requests do not load the cart on their own.
			wc_load_cart();
		}
		if ( null === WC()->cart || WC()->cart->is_empty() ) {
			return null;
		}

		$items = array();
		foreach ( WC()->cart->get_cart() as $line ) {
			$product = isset( $line['data'] ) && is_object( $line['data'] ) ? $line['data'] : null;
			if ( null === $product ) {
				continue;
			}
			$items[] = array(
				'name'     => wp_strip_all_tags( (string) $product->get_name() ),
				'quantity' => (int) ( $line['quantity'] ?? 0 ),
				'total'    => round( (float) ( $line['line_total'] ?? 0 ) + (float) ( $line['line_tax'] ?? 0 ), 2 ),
			);
			if ( count( $items ) >= self::CART_LINES ) {
				break;
			}
		}

		return array(
			'items'    => $items,
			'total'    => round( (float) WC()->cart->get_total( 'edit' ), 2 ),
			'currency' => (string) get_woocommerce_currency(),
			'at'       => gmdate( 'Y-m-d H:i:s' ),
		);
	}
}
