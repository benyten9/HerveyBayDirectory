<?php
/**
 * Microsoft Graph mail client.
 *
 * A drop-in replacement for {@see \DoubleScale\Modules\Tracking\ImapClient} that
 * reads Outlook mail over Microsoft Graph (`/me/messages`) instead of IMAP+XOAUTH2.
 * It exposes the same public method surface — connect(), fetch_unseen(),
 * fetch_recent(), mark_as_seen(), disconnect() — and emits the identical 12-key
 * normalized message array, so the existing pollers (EmailIncoming::poll_imap and
 * Support's MailboxImapPoller) consume it without any change.
 *
 * Why Graph instead of IMAP for Outlook:
 *  - The Outlook *sender* already uses Graph (Smtp\Providers\outlook), so reading
 *    over Graph reuses the same token family — no separate Outlook-resource token,
 *    no fragile two-step scope exchange, and the audience-mismatch bug that bites
 *    the IMAP path cannot recur.
 *  - Personal @outlook.com mailboxes refuse IMAP sessions even with a valid token,
 *    while Graph read works; Microsoft is also sunsetting its IMAP surface
 *    (legacy-TLS block, EWS retirement) — Graph has no such deadline.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Incoming;

defined( 'ABSPATH' ) || exit;

/**
 * GraphMailClient class.
 *
 * Stateless over HTTP: connect() mints a Graph access token from the stored
 * refresh token; every fetch/patch call carries it as a Bearer header and
 * refreshes once on a 401. disconnect() is a no-op kept for surface parity.
 */
class GraphMailClient {

	/**
	 * Microsoft token endpoint (multi-tenant /common works for consumer + work/school).
	 *
	 * @var string
	 */
	const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

	/**
	 * Graph base URL for the signed-in user's mailbox.
	 *
	 * @var string
	 */
	const GRAPH_BASE = 'https://graph.microsoft.com/v1.0/me';

	/**
	 * Graph scopes for reading + marking mail. Graph-audience (NOT the
	 * outlook.office.com resource scopes IMAP needs) — same family the sender uses.
	 *
	 * @var string
	 */
	const GRAPH_SCOPE = 'https://graph.microsoft.com/Mail.ReadWrite offline_access';

	/**
	 * Header stamp the plugin adds to its own outbound mail, so the pollers can
	 * skip our own messages (mirrors ImapClient's X-Plugin-Sent detection).
	 *
	 * @var string
	 */
	const SELF_SENT_HEADER = 'X-Plugin-Sent';

	/**
	 * Azure app client id.
	 *
	 * @var string
	 */
	private $client_id;

	/**
	 * Azure app client secret.
	 *
	 * @var string
	 */
	private $client_secret;

	/**
	 * OAuth refresh token for the connected mailbox.
	 *
	 * @var string
	 */
	private $refresh_token;

	/**
	 * Connected mailbox email (for logging / parity with ImapClient username).
	 *
	 * @var string
	 */
	private $email;

	/**
	 * Optional callback invoked when Microsoft rotates the refresh token, so the
	 * caller can persist the new one. Signature: function( string $new_refresh_token ).
	 *
	 * @var callable|null
	 */
	private $on_refresh_token_rotated;

	/**
	 * Current Graph access token (minted in connect(), rotated on 401).
	 *
	 * @var string
	 */
	private $access_token = '';

	/**
	 * Graph well-known mail folder the fetch_* calls target. Defaults to inbox;
	 * open_folder() switches it (e.g. to SentItems for the sent-folder sync).
	 *
	 * @var string
	 */
	private $folder = 'inbox';

	/**
	 * Constructor.
	 *
	 * @param string        $client_id                Azure app client id.
	 * @param string        $client_secret            Azure app client secret.
	 * @param string        $refresh_token            OAuth refresh token for the mailbox.
	 * @param string        $email                    Connected mailbox address.
	 * @param callable|null $on_refresh_token_rotated Called with the new refresh token when Microsoft rotates it.
	 */
	public function __construct( $client_id, $client_secret, $refresh_token, $email = '', $on_refresh_token_rotated = null ) {
		$this->client_id                = (string) $client_id;
		$this->client_secret            = (string) $client_secret;
		$this->refresh_token            = (string) $refresh_token;
		$this->email                    = (string) $email;
		$this->on_refresh_token_rotated = is_callable( $on_refresh_token_rotated ) ? $on_refresh_token_rotated : null;
	}

	/**
	 * Build a client from a graph-config array.
	 *
	 * Every Outlook receive path resolves credentials through one of the
	 * storage-specific providers — EmailOauth::get_graph_config() (shared inbox),
	 * UserEmailOauth::get_graph_config() (per-user), or
	 * RestSettingsControllerPro::get_smtp_outlook_graph_config() (smtp account),
	 * and the free-side Smtp\Settings graph config (Support). They differ in where
	 * the refresh token lives and how it's persisted on rotation, but all emit the
	 * SAME five keys. This factory is the single place that unpacks them, so the
	 * three pollers (shared inbox, per-user, Support) don't each repeat the call.
	 *
	 * @param array $config { client_id, client_secret, refresh_token, email, on_refresh_token_rotated }.
	 * @return self
	 */
	public static function from_config( array $config ): self {
		return new self(
			$config['client_id'] ?? '',
			$config['client_secret'] ?? '',
			$config['refresh_token'] ?? '',
			$config['email'] ?? '',
			$config['on_refresh_token_rotated'] ?? null
		);
	}

	/**
	 * Mint a Graph access token from the stored refresh token.
	 *
	 * Mirrors ImapClient::connect() in that it's the one method that throws on
	 * failure (the fetchers return empty arrays instead), so configuration errors
	 * surface to the poller's catch block exactly as the IMAP path expects.
	 *
	 * @throws \RuntimeException If the token cannot be minted.
	 */
	public function connect() {
		if ( '' === $this->client_id || '' === $this->client_secret || '' === $this->refresh_token ) {
			throw new \RuntimeException( 'Graph mail: incomplete Outlook OAuth configuration.' );
		}

		$token = $this->mint_access_token();
		if ( '' === $token ) {
			// Word "auth" is intentional: EmailIncoming::handle_oauth_auth_failure()
			// keys off auth-ish terms to flag needs_reauth and stop the retry loop.
			throw new \RuntimeException( 'Graph mail: OAuth token request failed (authentication error).' );
		}

		$this->access_token = $token;
	}

	/**
	 * Fetch unread inbox messages, newest first.
	 *
	 * @param int         $limit      Maximum number of messages to fetch.
	 * @param string|null $since_date Optional date string (strtotime-parseable); only mail at/after it.
	 * @return array<int, array<string, mixed>> Normalized message arrays.
	 */
	public function fetch_unseen( $limit = 20, $since_date = null ) {
		$filter = 'isRead eq false';
		$since  = $this->since_filter( $since_date );
		if ( '' !== $since ) {
			$filter .= ' and ' . $since;
		}

		return $this->fetch_messages( $filter, $limit );
	}

	/**
	 * Fetch recent messages regardless of read/unread state.
	 *
	 * Catches replies already marked read by another client before the poll ran —
	 * the same reason ImapClient::fetch_recent() exists.
	 *
	 * @param string $since_date Date string parseable by strtotime.
	 * @param int    $limit      Maximum number of messages to fetch.
	 * @return array<int, array<string, mixed>> Normalized message arrays.
	 */
	public function fetch_recent( $since_date, $limit = 20 ) {
		$filter = $this->since_filter( $since_date );
		return $this->fetch_messages( $filter, $limit );
	}

	/**
	 * Mark a message as read (Graph's analog of the IMAP \Seen flag).
	 *
	 * @param string $uid Graph message id (passed back unchanged from the fetch array).
	 * @return bool True on success.
	 */
	public function mark_as_seen( $uid ) {
		if ( '' === (string) $uid ) {
			return false;
		}

		$response = $this->request(
			'PATCH',
			self::GRAPH_BASE . '/messages/' . rawurlencode( (string) $uid ),
			wp_json_encode( array( 'isRead' => true ) ),
			array( 'Content-Type' => 'application/json' )
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return (int) wp_remote_retrieve_response_code( $response ) < 300;
	}

	/**
	 * Count unread inbox messages (optionally since a date), mirroring
	 * ImapClient::count_unseen(). Used by the settings "Test Connection" action.
	 *
	 * @param string|null $since_date Optional date string (strtotime-parseable).
	 * @return int Unread count (0 on any error).
	 */
	public function count_unseen( $since_date = null ) {
		$filter = 'isRead eq false';
		$since  = $this->since_filter( $since_date );
		if ( '' !== $since ) {
			$filter .= ' and ' . $since;
		}

		$url = self::GRAPH_BASE . '/mailFolders/' . rawurlencode( $this->folder ) . '/messages?' . http_build_query(
			array(
				'$filter' => $filter,
				'$count'  => 'true',
				'$top'    => 1,
			)
		);

		$response = $this->request( 'GET', $url, null, array( 'ConsistencyLevel' => 'eventual' ) );
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return 0;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		// Graph returns the total match count in @odata.count when $count=true.
		return isset( $data['@odata.count'] ) ? (int) $data['@odata.count'] : 0;
	}

	/**
	 * Switch which mail folder the subsequent fetch and count calls target.
	 *
	 * Graph addresses folders per-request (no stateful SELECT like IMAP), so this
	 * just records the well-known folder id. Mirrors ImapClient::open_folder()'s
	 * signature/return so the sent-folder sync (poll_sent_folder) is transport-
	 * agnostic. Maps common IMAP sent-folder names to Graph's `sentitems`; any
	 * inbox-ish name maps back to `inbox`. Unknown names are passed through so a
	 * Graph well-known id (or a folder id) can be supplied directly.
	 *
	 * @param string $folder Folder name (e.g. 'INBOX', '[Gmail]/Sent Mail', 'Sent Items').
	 * @return bool Always true (no socket to fail; kept for surface parity).
	 */
	public function open_folder( $folder ) {
		$needle = strtolower( (string) $folder );

		if ( false !== strpos( $needle, 'sent' ) ) {
			$this->folder = 'sentitems';
		} elseif ( '' === $needle || false !== strpos( $needle, 'inbox' ) ) {
			$this->folder = 'inbox';
		} else {
			// Allow an explicit Graph well-known name / folder id to pass through.
			$this->folder = $needle;
		}

		return true;
	}

	/**
	 * No-op — HTTP is stateless. Kept so the class is interchangeable with ImapClient.
	 */
	public function disconnect() {
		$this->access_token = '';
		$this->folder       = 'inbox';
	}

	// ─── Internals ───────────────────────────────────────────────────────────

	/**
	 * Build a Graph `$filter` clause for "received at/after $since_date", or '' if none.
	 *
	 * @param string|null $since_date Date string parseable by strtotime.
	 * @return string
	 */
	private function since_filter( $since_date ) {
		if ( null === $since_date || '' === (string) $since_date ) {
			return '';
		}

		$timestamp = strtotime( (string) $since_date );
		if ( false === $timestamp ) {
			return '';
		}

		// Graph wants ISO-8601 UTC; day-granularity floor matches the IMAP SINCE behaviour.
		return 'receivedDateTime ge ' . gmdate( 'Y-m-d\T00:00:00\Z', $timestamp );
	}

	/**
	 * Run a Graph messages query and normalize each result.
	 *
	 * @param string $filter Graph `$filter` expression (may be empty).
	 * @param int    $limit  Max results ($top).
	 * @return array<int, array<string, mixed>> Normalized message arrays.
	 */
	private function fetch_messages( $filter, $limit ) {
		$query = array(
			'$top'     => max( 1, (int) $limit ),
			'$orderby' => 'receivedDateTime desc',
			'$select'  => 'id,internetMessageId,subject,from,toRecipients,ccRecipients,receivedDateTime,body,hasAttachments,internetMessageHeaders',
		);
		if ( '' !== $filter ) {
			$query['$filter'] = $filter;
		}

		$url = self::GRAPH_BASE . '/mailFolders/' . rawurlencode( $this->folder ) . '/messages?' . http_build_query( $query );

		// Ask Graph for an HTML body so $message['body'] parity with IMAP holds.
		$response = $this->request( 'GET', $url, null, array( 'Prefer' => 'outlook.body-content-type="html"' ) );
		if ( is_wp_error( $response ) ) {
			return array();
		}
		if ( (int) wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['value'] ) || ! is_array( $data['value'] ) ) {
			return array();
		}

		$emails = array();
		foreach ( $data['value'] as $message ) {
			$normalized = $this->normalize_message( $message );
			if ( null !== $normalized ) {
				$emails[] = $normalized;
			}
		}

		return $emails;
	}

	/**
	 * Map one Graph message resource to the shared 12-key normalized array.
	 *
	 * Keys match ImapClient::parse_email() exactly so downstream consumers are
	 * transport-agnostic: uid, from_email, from_name, to_email, all_recipients,
	 * subject, body, message_id, in_reply_to, date, crm_sent, attachments.
	 *
	 * @param array<string, mixed> $message Graph message resource.
	 * @return array<string, mixed>|null Normalized message, or null if unusable.
	 */
	private function normalize_message( array $message ) {
		$from_email = isset( $message['from']['emailAddress']['address'] )
			? (string) $message['from']['emailAddress']['address']
			: '';
		if ( '' === $from_email ) {
			return null; // Parity with ImapClient: no From → skip.
		}

		$from_name = isset( $message['from']['emailAddress']['name'] )
			? (string) $message['from']['emailAddress']['name']
			: '';

		$to_recipients  = $this->recipient_addresses( $message['toRecipients'] ?? array() );
		$cc_recipients  = $this->recipient_addresses( $message['ccRecipients'] ?? array() );
		$all_recipients = array_values( array_unique( array_merge( $to_recipients, $cc_recipients ) ) );
		$to_email       = $to_recipients[0] ?? '';

		list( $in_reply_to, $crm_sent ) = $this->scan_headers( $message['internetMessageHeaders'] ?? array() );

		$message_id = isset( $message['internetMessageId'] ) ? (string) $message['internetMessageId'] : '';
		if ( '' === $message_id ) {
			// Fallback parity with ImapClient when the provider id is missing.
			$host       = wp_parse_url( home_url(), PHP_URL_HOST );
			$host       = ! empty( $host ) ? $host : 'localhost';
			$entropy    = ( $message['id'] ?? '' ) . $from_email . ( $message['subject'] ?? '' );
			$message_id = '<graph-' . md5( $entropy ) . '@' . $host . '>';
		}

		$attachments = ! empty( $message['hasAttachments'] )
			? $this->fetch_attachments( (string) ( $message['id'] ?? '' ) )
			: array();

		return array(
			// Graph message id is opaque; consumers only pass it back to mark_as_seen().
			'uid'            => isset( $message['id'] ) ? (string) $message['id'] : '',
			'from_email'     => $from_email,
			'from_name'      => $from_name,
			'to_email'       => $to_email,
			'all_recipients' => $all_recipients,
			'subject'        => isset( $message['subject'] ) ? (string) $message['subject'] : '',
			'body'           => isset( $message['body']['content'] ) ? (string) $message['body']['content'] : '',
			'message_id'     => $message_id,
			'in_reply_to'    => $in_reply_to,
			'date'           => isset( $message['receivedDateTime'] ) ? (string) $message['receivedDateTime'] : '',
			'crm_sent'       => $crm_sent,
			'attachments'    => $attachments,
		);
	}

	/**
	 * Extract lowercase-safe email addresses from a Graph recipient collection.
	 *
	 * @param array<int, array<string, mixed>> $recipients Graph recipient objects.
	 * @return array<int, string>
	 */
	private function recipient_addresses( $recipients ) {
		$out = array();
		if ( ! is_array( $recipients ) ) {
			return $out;
		}
		foreach ( $recipients as $recipient ) {
			if ( ! empty( $recipient['emailAddress']['address'] ) ) {
				$out[] = (string) $recipient['emailAddress']['address'];
			}
		}
		return $out;
	}

	/**
	 * Scan Graph internetMessageHeaders for In-Reply-To and the self-sent stamp.
	 *
	 * Graph returns headers as a flat [{name,value}, ...] list — much simpler than
	 * the raw-header regex scraping the IMAP path needs. The crm_sent detection
	 * stays semantically identical (presence of X-Plugin-Sent) so Support still
	 * skips our own outbound.
	 *
	 * @param array<int, array<string, mixed>> $headers Graph internetMessageHeaders.
	 * @return array{0:string,1:bool} [ in_reply_to, crm_sent ]
	 */
	private function scan_headers( $headers ) {
		$in_reply_to = '';
		$crm_sent    = false;

		if ( ! is_array( $headers ) ) {
			return array( $in_reply_to, $crm_sent );
		}

		foreach ( $headers as $header ) {
			$name = isset( $header['name'] ) ? strtolower( (string) $header['name'] ) : '';
			if ( 'in-reply-to' === $name && isset( $header['value'] ) ) {
				$in_reply_to = trim( (string) $header['value'] );
			} elseif ( strtolower( self::SELF_SENT_HEADER ) === $name ) {
				$crm_sent = true;
			}
		}

		return array( $in_reply_to, $crm_sent );
	}

	/**
	 * Fetch and normalize a message's attachments to ImapClient's attachment shape.
	 *
	 * Graph returns a flat array with base64 `contentBytes` — no MIME-tree walking.
	 * Output matches ImapClient::get_email_attachments(): each item is
	 * { filename, mime, content (raw bytes), content_id }. Oversized parts are
	 * skipped, mirroring the IMAP path's wp_max_upload_size guard.
	 *
	 * @param string $message_id Graph message id.
	 * @return array<int, array{filename:string, mime:string, content:string, content_id:string}>
	 */
	private function fetch_attachments( $message_id ) {
		if ( '' === $message_id ) {
			return array();
		}

		$response = $this->request(
			'GET',
			self::GRAPH_BASE . '/messages/' . rawurlencode( $message_id ) . '/attachments',
			null,
			array()
		);
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['value'] ) || ! is_array( $data['value'] ) ) {
			return array();
		}

		$max_size    = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		$attachments = array();

		foreach ( $data['value'] as $attachment ) {
			// Only file attachments carry contentBytes; skip itemAttachment/reference types.
			if ( empty( $attachment['contentBytes'] ) ) {
				continue;
			}
			if ( $max_size > 0 && isset( $attachment['size'] ) && (int) $attachment['size'] > $max_size ) {
				continue;
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Graph delivers attachment bytes base64-encoded; decoding to raw bytes matches ImapClient's attachment 'content' contract.
			$content = base64_decode( (string) $attachment['contentBytes'] );
			if ( false === $content || '' === $content ) {
				continue;
			}

			$filename = isset( $attachment['name'] ) ? sanitize_file_name( (string) $attachment['name'] ) : '';

			$attachments[] = array(
				'filename'   => '' !== $filename ? $filename : 'attachment',
				'mime'       => isset( $attachment['contentType'] ) ? (string) $attachment['contentType'] : 'application/octet-stream',
				'content'    => $content,
				// Content-ID (without angle brackets) for inline images, matching ImapClient.
				'content_id' => isset( $attachment['contentId'] ) ? trim( (string) $attachment['contentId'], " <>\t\r\n" ) : '',
			);
		}

		return $attachments;
	}

	/**
	 * Perform a Graph HTTP request with the Bearer token, refreshing once on 401.
	 *
	 * Mirrors the refresh-once-on-401 idiom in the Outlook sender
	 * (Smtp\Providers\outlook\class-account-api.php) so behaviour is consistent.
	 *
	 * @param string                $method   HTTP method.
	 * @param string                $url      Absolute Graph URL.
	 * @param string|null           $body     Request body (already JSON-encoded) or null.
	 * @param array<string, string> $headers  Extra headers (Authorization is added here).
	 * @param bool                  $is_retry Internal: true on the post-refresh retry.
	 * @return array|\WP_Error wp_remote_* response.
	 */
	private function request( $method, $url, $body = null, $headers = array(), $is_retry = false ) {
		if ( '' === $this->access_token && ! $is_retry ) {
			// Lazy mint if a fetch is called without connect() (defensive).
			$this->access_token = $this->mint_access_token();
		}

		$args = array(
			'method'  => $method,
			'headers' => array_merge(
				array( 'Authorization' => 'Bearer ' . $this->access_token ),
				$headers
			),
			'timeout' => 30,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 401 === (int) wp_remote_retrieve_response_code( $response ) && ! $is_retry ) {
			$refreshed = $this->mint_access_token();
			if ( '' === $refreshed ) {
				return $response; // Surface the 401; caller treats non-2xx as no data.
			}
			$this->access_token = $refreshed;
			return $this->request( $method, $url, $body, $headers, true );
		}

		return $response;
	}

	/**
	 * Redeem the refresh token for a fresh Graph access token.
	 *
	 * Persists any rotated refresh token via the on_refresh_token_rotated callback —
	 * Microsoft rotates refresh tokens on every redemption for consumer accounts, so
	 * failing to write the new one back would silently break the connection.
	 *
	 * @return string Access token, or '' on failure.
	 */
	private function mint_access_token() {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => http_build_query(
					array(
						'grant_type'    => 'refresh_token',
						'refresh_token' => $this->refresh_token,
						'client_id'     => $this->client_id,
						'client_secret' => $this->client_secret,
						'scope'         => self::GRAPH_SCOPE,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			doublescale_get_logger()->error(
				'Graph mail token request failed (HTTP error)',
				array(
					'source' => 'inbox-graph-mail',
					'email'  => $this->email,
					'error'  => $response->get_error_message(),
				)
			);
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			doublescale_get_logger()->error(
				'Graph mail token request rejected',
				array(
					'source' => 'inbox-graph-mail',
					'email'  => $this->email,
					'error'  => isset( $data['error'] ) ? (string) $data['error'] : 'unknown',
				)
			);
			return '';
		}

		// Persist a rotated refresh token if Microsoft issued a new one.
		if ( ! empty( $data['refresh_token'] ) && (string) $data['refresh_token'] !== $this->refresh_token ) {
			$this->refresh_token = (string) $data['refresh_token'];
			if ( null !== $this->on_refresh_token_rotated ) {
				call_user_func( $this->on_refresh_token_rotated, $this->refresh_token );
			}
		}

		return (string) $data['access_token'];
	}
}
