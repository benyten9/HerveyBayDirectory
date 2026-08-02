<?php
/**
 * Global IMAP polyfill shim for the no-ext-imap case.
 *
 * WHY THIS EXISTS
 * ----------------
 * The pure-PHP IMAP layer we ship (javanile/php-imap2) is meant to run without
 * the native `ext-imap` extension by providing polyfills for the `imap_*`
 * functions its own `src/` code calls (e.g. `\imap_rfc822_write_address`,
 * `\imap_rfc822_parse_adrlist`). Those polyfills live in the library's
 * `bootstrap.php`.
 *
 * However, our PHP-Scoper build wraps that `bootstrap.php` in
 * `namespace DoubleScale\Pro\Vendor;`. As a result every `function imap_xxx()`
 * declared there is actually defined as `DoubleScale\Pro\Vendor\imap_xxx()` —
 * NOT the GLOBAL `\imap_xxx()` that the library's `src/` files invoke with a
 * leading backslash. So on a server WITHOUT ext-imap (the common case on modern
 * PHP — ext-imap is unbundled in PHP 8.4+), calls like
 * `HeaderInfo` -> `Functions::writeAddressFromEnvelope()` ->
 * `\imap_rfc822_write_address()` hit a global function that was never defined
 * and PHP fatals with "Call to undefined function imap_rfc822_write_address()".
 *
 * That fatal is thrown on EVERY `imap2_headerinfo()` call, which is the hot path
 * for BOTH inbound email pollers (Support's MailboxImapPoller and Inbox's
 * EmailIncoming). The poll dies before any ticket/message is created — the
 * user-visible symptom is "IMAP connected but no tickets are ever created".
 *
 * WHAT THIS DOES
 * --------------
 * Defines the small set of GLOBAL `\imap_*` functions that the scoped library
 * calls but whose polyfills landed in the wrong namespace. Each delegates to the
 * library's own pure-PHP `Polyfill` implementation where one exists; the two
 * address helpers (`rfc822_parse_adrlist` / `rfc822_write_address`) are
 * implemented here directly because the library's `Polyfill::rfc822WriteHeaders`
 * is a no-op stub that ignores its arguments.
 *
 * These are defined ONLY when the native function is absent, so a server that
 * DOES have ext-imap keeps using the real extension untouched.
 *
 * RELATIONSHIP TO THE BUILD FIX
 * -----------------------------
 * The durable fix lives in the build pipeline ({@see dependencies/scoper.inc.php}):
 * php-imap2's bootstrap.php is excluded from scoping (so its polyfills stay
 * GLOBAL), and a patcher corrects the library's no-op `rfc822WriteHeaders`. Once
 * `dependencies/build/` is regenerated with that config, the library defines
 * these globals itself — and every guard below short-circuits, so this file
 * becomes an inert no-op. It is kept as defence-in-depth and to protect any
 * already-shipped `dependencies/build/` that predates the scoper change. The
 * `imap_rfc822_write_address` implementation here intentionally MATCHES the
 * patched `Polyfill::rfc822WriteHeaders`, so behaviour is identical whichever
 * one wins.
 *
 * @package DoubleScale\Pro
 */

defined( 'ABSPATH' ) || exit;

// The library keeps its own classes in the (un-scoped) `Javanile\Imap2`
// namespace. Guard on the Polyfill class: if the library isn't loaded for any
// reason, defining shims that reference it would only move the failure around.
if ( ! class_exists( '\Javanile\Imap2\Polyfill' ) ) {
	return;
}

if ( ! function_exists( 'imap_rfc822_write_address' ) ) {
	/**
	 * Build an RFC 822 address string from its parts.
	 *
	 * Native ext-imap renders `Personal <mailbox@host>` (and bare
	 * `mailbox@host` when there is no personal name). The library's own
	 * polyfill stub (`Polyfill::rfc822WriteHeaders`) drops the mailbox/host and
	 * returns nothing usable, so we render it correctly here. This feeds
	 * `HeaderInfo`/`Message` envelope parsing — getting it right is what makes
	 * From/To resolve to a real address during polling.
	 *
	 * @param string|null $mailbox  Local part (before the @).
	 * @param string|null $host     Domain (after the @).
	 * @param string|null $personal Optional display name.
	 * @return string RFC 822 formatted address.
	 */
	function imap_rfc822_write_address( $mailbox, $host, $personal = '' ) {
		$mailbox = (string) $mailbox;
		$host    = (string) $host;
		$address = '' !== $host ? $mailbox . '@' . $host : $mailbox;

		$personal = (string) $personal;
		if ( '' === $personal ) {
			return $address;
		}

		// Quote the personal name when it contains RFC 822 specials, matching
		// ext-imap's behaviour closely enough for downstream header parsing.
		if ( preg_match( '/[()<>@,;:\\\\".\[\]]/', $personal ) ) {
			$personal = '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $personal ) . '"';
		}

		return $personal . ' <' . $address . '>';
	}
}

if ( ! function_exists( 'imap_rfc822_parse_adrlist' ) ) {
	/**
	 * Parse an address list into objects with mailbox/host/personal members.
	 *
	 * Delegates to the library's pure-PHP implementation, which is correct —
	 * it only failed because it was reachable only under the wrong namespace.
	 *
	 * @param string $string          Address list (e.g. "A <a@x.com>, b@y.com").
	 * @param string $default_host    Host to assume for bare local parts.
	 * @return array<int, object> Address objects.
	 */
	function imap_rfc822_parse_adrlist( $string, $default_host = 'UNKNOWN' ) {
		return \Javanile\Imap2\Polyfill::rfc822ParseAdrList( (string) $string, (string) $default_host );
	}
}

if ( ! function_exists( 'imap_rfc822_parse_headers' ) ) {
	/**
	 * Parse raw headers into the ext-imap header-info object shape.
	 *
	 * @param string $headers      Raw header block.
	 * @param string $default_host Default host for bare addresses.
	 * @return object Header info object.
	 */
	function imap_rfc822_parse_headers( $headers, $default_host = 'UNKNOWN' ) {
		return \Javanile\Imap2\Polyfill::rfc822ParseHeaders( (string) $headers, (string) $default_host );
	}
}
