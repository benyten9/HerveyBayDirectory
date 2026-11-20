<?php
/**
 * "About the visitor" beside a ticket (Intercom's recent page views and
 * store orders, Chatway's cart and orders).
 *
 * - pages: up to 20, newest first, path only (what follows "?" can hold a
 *   reset key or an order key). A known customer's come from website
 *   tracking; a chat visitor who only typed an email gets the pages their own
 *   browser kept (VisitorCapture), never the tracked pages of that contact.
 *   Website tracking off: no pages.
 * - orders: the last 5 (WooCommerce, FluentCart), only when we know it is
 *   them: an email ticket, a portal or admin ticket, or a chat whose
 *   signed-in account is that contact. A typed email shows no one's orders
 *   (Chatway: orders for logged-in customers only). A link to the order only
 *   for who may open the store's orders.
 * - cart: what a chat visitor had in their WooCommerce cart.
 * Never an address or a phone.
 *
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\FluentCartOrders;
use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Support\Models\TicketModel;

/**
 * VisitorPanel class.
 */
final class VisitorPanel {

	/**
	 * Pages shown.
	 */
	public const PAGES = 20;

	/**
	 * Orders shown.
	 */
	public const ORDERS = 5;

	/**
	 * @param TicketModel $ticket Ticket the current user may open.
	 * @return array<string, mixed>
	 */
	public static function for_ticket( TicketModel $ticket ): array {
		$contact      = ContactModel::find( (int) $ticket->contact_id );
		$conversation = self::conversation( (int) $ticket->id );
		// What the help chat learned about the visitor (the HelpAssistant module).
		$context      = null !== $conversation ? (array) apply_filters( 'doublescale_support_chat_context', array(), $conversation ) : array();
		$confirmed    = null !== $contact && self::confirmed( $ticket, $contact, $conversation );
		$tracking     = Settings::is_website_tracking_enabled();

		$pages      = array();
		$pages_from = null;
		if ( $tracking && $confirmed ) {
			$pages      = self::tracked_pages( (int) $contact->id );
			$pages_from = 'tracking';
		} elseif ( $tracking && null !== $conversation ) {
			$pages      = self::kept_pages( $context['pages'] ?? array() );
			$pages_from = 'chat';
		}

		return array(
			'identity_confirmed' => $confirmed,
			// Why it is not them, for the panel to say: 'typed' (an email typed
			// in the chat), 'unknown_chat' (from the chat, no conversation left),
			// 'no_contact'; null when confirmed.
			'unconfirmed'        => $confirmed ? null : ( null === $contact ? 'no_contact' : ( null !== $conversation ? 'typed' : 'unknown_chat' ) ),
			'chat'               => null !== $conversation,
			'tracking'           => $tracking,
			'pages_from'         => $pages_from,
			'pages'              => self::with_titles( $pages ),
			'cart'               => self::cart( $context['cart'] ?? null ),
			'stores'             => self::stores(),
			'orders'             => $confirmed ? self::orders( (string) $contact->email ) : null,
		);
	}

	/**
	 * A store whose orders the panel reads runs on this site.
	 *
	 * @return bool
	 */
	private static function stores(): bool {
		$woo = function_exists( 'wc_get_orders' ) && function_exists( 'doublescale_is_plugin_active' ) && doublescale_is_plugin_active( 'woocommerce' );

		return $woo || ( class_exists( FluentCartOrders::class ) && FluentCartOrders::is_active() );
	}

	/**
	 * A path of this site, or ''. Drops what follows "?" or "#", another
	 * site ("//host"), and anything that is not a plain URL path.
	 *
	 * @param string $path Path from tracking or a browser.
	 * @return string
	 */
	public static function clean_path( string $path ): string {
		$path = trim( (string) strtok( $path, '?#' ) );
		if ( '' === $path || '/' !== $path[0] || 0 === strpos( $path, '//' ) || strlen( $path ) > 500 ) {
			return '';
		}

		return 1 === preg_match( '#^/[A-Za-z0-9\-._~%!$&\'()*+,;=:@/]*$#', $path ) ? $path : '';
	}

	/**
	 * A page worth showing the team, as the visitor had it, or ''.
	 * Login and dashboard screens are left out (signing in is not browsing).
	 * Visits stored before Website tracking's fix carry a site folder twice
	 * ("/wordpress/wordpress/pricing/"): shown once.
	 *
	 * @param string $path Stored path.
	 * @return string
	 */
	private static function shown_path( string $path ): string {
		$path = self::clean_path( $path );
		if ( '' === $path ) {
			return '';
		}
		$folder = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' !== $folder && 0 === strpos( $path, $folder . $folder . '/' ) ) {
			$path = substr( $path, strlen( $folder ) );
		}
		if ( 1 === preg_match( '#/wp-login\.php$|/wp-admin(/|$)#', $path ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * We know it is them: not a chat, or a chat whose signed-in account is
	 * this contact and proved its email. A chat whose conversation is gone
	 * counts as typed.
	 *
	 * @param TicketModel               $ticket       Ticket.
	 * @param ContactModel              $contact      Its contact.
	 * @param array<string, mixed>|null $conversation The chat it came from.
	 * @return bool
	 */
	private static function confirmed( TicketModel $ticket, ContactModel $contact, ?array $conversation ): bool {
		if ( null === $conversation ) {
			return 'chat' !== self::opening_source( $ticket );
		}
		$user = (int) ( $conversation['user_id'] ?? 0 ) > 0 ? get_userdata( (int) $conversation['user_id'] ) : false;
		if ( ! $user instanceof \WP_User || '' === (string) $contact->email
			|| 0 !== strcasecmp( (string) $user->user_email, (string) $contact->email ) ) {
			return false;
		}
		// Some sites let anyone register with a client's address: like the
		// portal, trust a login only once it proved the address.
		$proof = '\DoubleScale\Modules\Portal\Services\PortalEmailConfirmation';

		return ! class_exists( $proof ) || ! method_exists( $proof, 'is_confirmed' ) || (bool) $proof::is_confirmed( $user );
	}

	/**
	 * @param TicketModel $ticket Ticket.
	 * @return string
	 */
	private static function opening_source( TicketModel $ticket ): string {
		$helper = '\DoubleScale\Pro\Modules\Automations\Support\SupportConversationHelper';

		return class_exists( $helper ) && method_exists( $helper, 'get_opening_source' ) ? (string) $helper::get_opening_source( $ticket ) : '';
	}

	/**
	 * @param int $ticket_id Ticket id.
	 * @return array<string, mixed>|null
	 */
	private static function conversation( int $ticket_id ): ?array {
		// The help chat this ticket came from, when the HelpAssistant module is on.
		$conversation = apply_filters( 'doublescale_support_ticket_chat', null, $ticket_id );
		return is_array( $conversation ) ? $conversation : null;
	}

	/**
	 * @param int $contact_id Contact id.
	 * @return array<int, array{path: string, at: string}>
	 */
	private static function tracked_pages( int $contact_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'doublescale_page_visits';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// More rows than shown: login screens in between are left out.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT path, created_at FROM {$table} WHERE contact_id = %d ORDER BY created_at DESC, id DESC LIMIT %d", $contact_id, self::PAGES * 5 ), ARRAY_A );

		$pages = array();
		foreach ( (array) $rows as $row ) {
			$path = self::shown_path( (string) $row['path'] );
			if ( '' !== $path ) {
				$pages[] = array(
					'path' => $path,
					'at'   => (string) $row['created_at'],
				);
			}
		}

		return array_slice( $pages, 0, self::PAGES );
	}

	/**
	 * @param mixed $kept Pages kept on the conversation.
	 * @return array<int, array{path: string, at: string}>
	 */
	private static function kept_pages( $kept ): array {
		$pages = array();
		foreach ( is_array( $kept ) ? $kept : array() as $page ) {
			$path = is_array( $page ) ? self::shown_path( (string) ( $page['path'] ?? '' ) ) : '';
			if ( '' !== $path ) {
				$pages[] = array(
					'path' => $path,
					'at'   => (string) ( $page['at'] ?? '' ),
				);
			}
		}

		return array_slice( $pages, 0, self::PAGES );
	}

	/**
	 * A page, post or product of this site is named by its title.
	 *
	 * @param array<int, array{path: string, at: string}> $pages Pages.
	 * @return array<int, array{path: string, at: string, title: string}>
	 */
	private static function with_titles( array $pages ): array {
		// The path is the whole path of the address (a site in a folder keeps
		// its folder in it): put it after the site's host, not its home.
		$home   = wp_parse_url( home_url() );
		$origin = ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$titles = array();
		foreach ( $pages as $i => $page ) {
			if ( ! array_key_exists( $page['path'], $titles ) ) {
				$post                    = url_to_postid( $origin . $page['path'] );
				$titles[ $page['path'] ] = $post > 0 && 'publish' === get_post_status( $post ) && is_post_type_viewable( (string) get_post_type( $post ) )
					? html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' )
					: '';
			}
			$pages[ $i ]['title'] = $titles[ $page['path'] ];
		}

		return $pages;
	}

	/**
	 * @param mixed $cart Cart kept on the conversation.
	 * @return array<string, mixed>|null
	 */
	private static function cart( $cart ): ?array {
		if ( ! is_array( $cart ) || empty( $cart['items'] ) || ! is_array( $cart['items'] ) ) {
			return null;
		}
		$items = array();
		foreach ( $cart['items'] as $item ) {
			if ( is_array( $item ) ) {
				$items[] = array(
					'name'     => (string) ( $item['name'] ?? '' ),
					'quantity' => (int) ( $item['quantity'] ?? 0 ),
					'total'    => (float) ( $item['total'] ?? 0 ),
				);
			}
		}

		return array(
			'items'    => $items,
			'total'    => (float) ( $cart['total'] ?? 0 ),
			'currency' => (string) ( $cart['currency'] ?? '' ),
			'at'       => (string) ( $cart['at'] ?? '' ),
		);
	}

	/**
	 * The customer's last orders in the stores this site runs, newest first.
	 *
	 * @param string $email Contact email.
	 * @return array<int, array<string, mixed>>
	 */
	private static function orders( string $email ): array {
		if ( ! is_email( $email ) ) {
			return array();
		}
		$orders = array();

		if ( function_exists( 'wc_get_orders' ) && function_exists( 'doublescale_is_plugin_active' ) && doublescale_is_plugin_active( 'woocommerce' ) ) {
			$open = current_user_can( 'edit_shop_orders' );
			foreach ( wc_get_orders(
				array(
					'customer' => $email,
					'type'     => 'shop_order',
					'limit'    => self::ORDERS,
					'orderby'  => 'date',
					'order'    => 'DESC',
				)
			) as $order ) {
				$created  = $order->get_date_created();
				$orders[] = array(
					'store'    => 'woocommerce',
					'number'   => (string) $order->get_order_number(),
					'date'     => $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : '',
					'status'   => (string) wc_get_order_status_name( $order->get_status() ),
					'total'    => (float) $order->get_total(),
					'currency' => (string) $order->get_currency(),
					'url'      => $open ? (string) $order->get_edit_order_url() : null,
				);
			}
		}

		if ( class_exists( FluentCartOrders::class ) && FluentCartOrders::is_active() ) {
			$manager = '\FluentCart\App\Services\Permission\PermissionManager';
			$open    = class_exists( $manager ) ? (bool) $manager::hasPermission( 'orders/view' ) : current_user_can( 'manage_options' );
			foreach ( FluentCartOrders::sale_orders_for_email( $email, self::ORDERS, 0 ) as $order ) {
				$orders[] = array(
					'store'    => 'fluentcart',
					'number'   => (string) $order['number'],
					'date'     => (string) $order['date'],
					'status'   => (string) ( '' !== (string) $order['payment_status'] ? $order['payment_status'] : $order['status'] ),
					'total'    => (float) $order['total_amount'],
					'currency' => (string) $order['currency'],
					'url'      => $open ? (string) $order['url'] : null,
				);
			}
		}

		usort(
			$orders,
			static function ( array $a, array $b ): int {
				return strcmp( (string) $b['date'], (string) $a['date'] );
			}
		);

		return array_slice( $orders, 0, self::ORDERS );
	}
}
