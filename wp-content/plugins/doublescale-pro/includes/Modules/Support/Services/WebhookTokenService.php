<?php
/**
 * Per-mailbox secret token for the incoming support webhook.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Support\Models\MailboxModel;

/**
 * WebhookTokenService class.
 */
final class WebhookTokenService {

	/**
	 * Return the mailbox's webhook token, minting one on first access.
	 *
	 * @param MailboxModel $mailbox Mailbox row.
	 * @return string
	 */
	public function get_or_create_token( MailboxModel $mailbox ): string {
		$data  = is_array( $mailbox->data ) ? $mailbox->data : array();
		$token = isset( $data['webhook']['token'] ) ? (string) $data['webhook']['token'] : '';
		if ( '' !== $token && $this->is_valid_token_format( $token ) ) {
			return $token;
		}

		$token = $this->mint_token();
		$this->persist_token( $mailbox, $token );
		return $token;
	}

	/**
	 * Rotate the mailbox webhook token and persist it.
	 *
	 * @param MailboxModel $mailbox Mailbox row.
	 * @return string New token.
	 */
	public function regenerate( MailboxModel $mailbox ): string {
		$token = $this->mint_token();
		$this->persist_token( $mailbox, $token );
		return $token;
	}

	/**
	 * Load a mailbox by id and verify the supplied token.
	 *
	 * @param int    $mailbox_id Mailbox id from the URL.
	 * @param string $token      Token from the URL.
	 * @return MailboxModel|null
	 */
	public function find_mailbox_by_token( int $mailbox_id, string $token ): ?MailboxModel {
		if ( $mailbox_id <= 0 || ! $this->is_valid_token_format( $token ) ) {
			return null;
		}

		$mailbox = MailboxModel::find( $mailbox_id );
		if ( ! $mailbox ) {
			return null;
		}

		$data           = is_array( $mailbox->data ) ? $mailbox->data : array();
		$expected_token = isset( $data['webhook']['token'] ) ? (string) $data['webhook']['token'] : '';
		if ( '' === $expected_token || ! hash_equals( $expected_token, $token ) ) {
			return null;
		}

		return $mailbox;
	}

	/**
	 * Build the public ingest URL for a mailbox token.
	 *
	 * @param MailboxModel $mailbox Mailbox row.
	 * @param string       $token   Webhook token.
	 * @return string
	 */
	public function build_webhook_url( MailboxModel $mailbox, string $token ): string {
		return (string) rest_url(
			'doublescale/v1/support/webhook/' . (int) $mailbox->id . '/' . rawurlencode( $token )
		);
	}

	/**
	 * @return string
	 */
	private function mint_token(): string {
		try {
			return bin2hex( random_bytes( 20 ) );
		} catch ( \Throwable $e ) {
			return bin2hex( (string) wp_rand() . uniqid( '', true ) );
		}
	}

	/**
	 * @param MailboxModel $mailbox Mailbox row.
	 * @param string       $token   Token to store.
	 * @return void
	 */
	private function persist_token( MailboxModel $mailbox, string $token ): void {
		$data = is_array( $mailbox->data ) ? $mailbox->data : array();
		if ( ! isset( $data['webhook'] ) || ! is_array( $data['webhook'] ) ) {
			$data['webhook'] = array();
		}
		$data['webhook']['token'] = $token;
		$mailbox->data            = $data;
		$mailbox->save();
	}

	/**
	 * @param string $token Token candidate.
	 * @return bool
	 */
	private function is_valid_token_format( string $token ): bool {
		return (bool) preg_match( '/^[a-f0-9]{40}$/', $token );
	}
}
