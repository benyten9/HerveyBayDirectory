<?php
/**
 * Emails MCP connection instructions to the user a key belongs to.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities\Mcp;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\McpServer;
use DoubleScale\Modules\Emails\Emails;
use WP_Error;

/**
 * Sends the setup steps for one client on one operating system.
 *
 * Two rules shape this class, both narrowing:
 *
 * 1. **The recipient is never chosen by hand.** It is read from the WordPress
 *    account the key belongs to. A free-text field would let one typo mail a
 *    working credential to a stranger, and there is no recall.
 *
 * 2. **The key is optional and off by default.** An API key is a permanent
 *    password: once mailed it lives in an inbox, in the provider's storage, and
 *    in every backup, and it keeps working years later. The instructions are
 *    the hard part of setup and are safe to send on their own, so the caller
 *    must ask for the credential explicitly.
 */
final class SetupMailer {

	/**
	 * Send setup instructions.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id     WordPress user the key belongs to.
	 * @param string $client      Client label, e.g. "Claude Desktop".
	 * @param string $os          Operating system label.
	 * @param string $config      Ready-made client configuration.
	 * @param string $config_path Where the configuration file lives.
	 * @param string $secret      Plaintext key, or '' to omit it.
	 * @return true|WP_Error
	 */
	public static function send(
		int $user_id,
		string $client,
		string $os,
		string $config,
		string $config_path,
		string $secret = ''
	) {
		$user = get_userdata( $user_id );

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return new WP_Error(
				'doublescale_mcp_no_recipient',
				__( 'That user has no valid email address on file.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$emails = new Emails();

		$sent = $emails->send(
			$user->user_email,
			self::subject( $client ),
			self::body( $user, $client, $os, $config, $config_path, $secret )
		);

		if ( ! $sent ) {
			$detail = Emails::get_last_send_failure_detail();

			return new WP_Error(
				'doublescale_mcp_email_failed',
				$detail
					? sprintf(
						/* translators: %s: reason the email could not be sent. */
						__( 'The email could not be sent: %s', 'doublescale' ),
						$detail
					)
					: __( 'The email could not be sent. Check the site’s email settings.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * @since 1.0.0
	 *
	 * @param string $client Client label.
	 * @return string
	 */
	private static function subject( string $client ): string {
		$site = trim( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );

		// A site with no name would otherwise produce a literal "[] Connect …",
		// which reads as a broken template rather than a prefix.
		if ( '' === $site ) {
			return sprintf(
				/* translators: %s: AI client name. */
				__( 'Connect %s to your CRM', 'doublescale' ),
				$client
			);
		}

		return sprintf(
			/* translators: 1: site name, 2: AI client name. */
			__( '[%1$s] Connect %2$s to your CRM', 'doublescale' ),
			$site,
			$client
		);
	}

	/**
	 * Build the message body.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_User $user        Recipient.
	 * @param string   $client      Client label.
	 * @param string   $os          Operating system label.
	 * @param string   $config      Client configuration.
	 * @param string   $config_path Configuration file path.
	 * @param string   $secret      Plaintext key, or ''.
	 * @return string
	 */
	private static function body(
		$user,
		string $client,
		string $os,
		string $config,
		string $config_path,
		string $secret
	): string {
		$name = $user->display_name ? $user->display_name : $user->user_login;

		$parts = array();

		$parts[] = '<p>' . sprintf(
			/* translators: %s: recipient's name. */
			esc_html__( 'Hi %s,', 'doublescale' ),
			esc_html( $name )
		) . '</p>';

		$parts[] = '<p>' . sprintf(
			/* translators: 1: AI client name, 2: operating system. */
			esc_html__( 'Here is how to connect %1$s on %2$s to the CRM. Anything it does will run as your account and show only what you are allowed to see.', 'doublescale' ),
			'<strong>' . esc_html( $client ) . '</strong>',
			esc_html( $os )
		) . '</p>';

		$parts[] = '<h3 style="margin:24px 0 8px;font-size:15px;">'
			. esc_html__( 'Endpoint', 'doublescale' ) . '</h3>';
		$parts[] = '<p style="font-family:monospace;word-break:break-all;">'
			. esc_html( McpServer::endpoint_url() ) . '</p>';

		if ( '' !== $secret ) {
			$parts[] = '<h3 style="margin:24px 0 8px;font-size:15px;">'
				. esc_html__( 'Your API key', 'doublescale' ) . '</h3>';
			$parts[] = '<p style="font-family:monospace;word-break:break-all;background:#f6f7f7;'
				. 'padding:12px;border-radius:4px;">' . esc_html( $secret ) . '</p>';

			// Stated plainly rather than buried: this email now contains a
			// working credential, and the reader is the only one who can limit
			// how long that stays true.
			$parts[] = '<p style="background:#fcf0f1;border-left:3px solid #d63638;padding:12px;">'
				. '<strong>' . esc_html__( 'This key is a password.', 'doublescale' ) . '</strong> '
				. esc_html__( 'It does not expire. Anyone who reads this email can use it to read your CRM data, so set it up and then delete this message. If you think it has been seen by someone else, ask an administrator to revoke it — a replacement takes seconds.', 'doublescale' )
				. '</p>';
		} else {
			$parts[] = '<h3 style="margin:24px 0 8px;font-size:15px;">'
				. esc_html__( 'Your API key', 'doublescale' ) . '</h3>';
			$parts[] = '<p>' . esc_html__( 'Not included here on purpose — a key is a permanent password and email is not a safe place to keep one. An administrator will send it to you separately. Paste it where the configuration below says to.', 'doublescale' ) . '</p>';
		}

		$parts[] = '<h3 style="margin:24px 0 8px;font-size:15px;">'
			. esc_html__( 'Configuration', 'doublescale' ) . '</h3>';

		if ( '' !== $config_path ) {
			$parts[] = '<p>' . sprintf(
				/* translators: %s: path to the client's configuration file. */
				esc_html__( 'Put this in %s:', 'doublescale' ),
				'<code>' . esc_html( $config_path ) . '</code>'
			) . '</p>';
		}

		$parts[] = '<pre style="background:#f6f7f7;padding:12px;border-radius:4px;'
			. 'overflow-x:auto;font-size:12px;line-height:1.5;">'
			. esc_html( $config ) . '</pre>';

		$parts[] = '<p>' . esc_html__( 'Restart the client after saving, then ask it something about your CRM to check the connection.', 'doublescale' ) . '</p>';

		$parts[] = '<p style="color:#666;font-size:12px;margin-top:24px;">'
			. esc_html__( 'You are receiving this because an API key was issued for your account on this site.', 'doublescale' )
			. '</p>';

		return implode( "\n", $parts );
	}
}
