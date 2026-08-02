<?php
/**
 * AI Assistant Conversations API.
 *
 * Per-user persistent conversation history.
 *
 * @package SEOPress PRO
 * @subpackage API
 * @since 9.8.0
 */

namespace SEOPressPro\Actions\Api\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * Class Conversations
 *
 * REST API endpoints for AI conversation history (CRUD).
 * All queries are scoped to the current user for security.
 */
class Conversations implements ExecuteHooks {

	/**
	 * Maximum conversations per user before auto-purge.
	 *
	 * @var int
	 */
	const MAX_CONVERSATIONS = 50;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register() {
		register_rest_route(
			'seopress/v1',
			'/ai/conversations',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_conversations' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_conversation' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'       => array(
							'default'           => null,
							'sanitize_callback' => function ( $param ) {
								return null === $param ? null : absint( $param );
							},
						),
						'title'    => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'messages' => array(
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_array( $param );
							},
						),
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/ai/conversations/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_conversation' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Check user permission.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Get the conversations table name.
	 *
	 * @return string
	 */
	private function get_table() {
		global $wpdb;
		return $wpdb->prefix . 'seopress_ai_conversations';
	}

	/**
	 * List conversations for the current user.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function list_conversations( \WP_REST_Request $request ) {
		global $wpdb;

		$table   = $this->get_table();
		$user_id = get_current_user_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, messages, created_at, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			),
			ARRAY_A
		);

		if ( null === $results ) {
			$results = array();
		}

		// Decode messages JSON and add message_count for the list view.
		foreach ( $results as &$row ) {
			$decoded          = json_decode( $row['messages'], true );
			$row['messages']  = is_array( $decoded ) ? $decoded : array();
			$row['msg_count'] = count( $row['messages'] );
		}
		unset( $row );

		return new \WP_REST_Response( $results, 200 );
	}

	/**
	 * Save (create or update) a conversation.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_conversation( \WP_REST_Request $request ) {
		global $wpdb;

		$table        = $this->get_table();
		$user_id      = get_current_user_id();
		$id           = $request->get_param( 'id' );
		$title        = $request->get_param( 'title' );
		$messages_raw = $request->get_param( 'messages' );

		// Sanitize messages.
		$messages = $this->sanitize_messages( $messages_raw );
		$json     = wp_json_encode( $messages );

		// Auto-generate title from first user message if empty.
		if ( empty( $title ) && ! empty( $messages ) ) {
			foreach ( $messages as $msg ) {
				if ( 'user' === $msg['role'] ) {
					$title = mb_substr( $msg['content'], 0, 60 );
					break;
				}
			}
		}

		$now = current_time( 'mysql' );

		if ( $id ) {
			// Update existing — verify ownership.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE id = %d AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$id,
					$user_id
				)
			);

			if ( ! $existing ) {
				return new \WP_Error(
					'not_found',
					__( 'Conversation not found.', 'wp-seopress-pro' ),
					array( 'status' => 404 )
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'title'      => $title,
					'messages'   => $json,
					'updated_at' => $now,
				),
				array(
					'id'      => $id,
					'user_id' => $user_id,
				),
				array( '%s', '%s', '%s' ),
				array( '%d', '%d' )
			);
		} else {
			// Create new.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'user_id'    => $user_id,
					'title'      => $title,
					'messages'   => $json,
					'created_at' => $now,
					'updated_at' => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);

			$id = $wpdb->insert_id;

			// Auto-purge: delete oldest if user has too many.
			$this->auto_purge( $user_id );
		}

		return new \WP_REST_Response(
			array(
				'id'         => (int) $id,
				'title'      => $title,
				'updated_at' => $now,
			),
			200
		);
	}

	/**
	 * Delete a conversation.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_conversation( \WP_REST_Request $request ) {
		global $wpdb;

		$table   = $this->get_table();
		$user_id = get_current_user_id();
		$id      = absint( $request->get_param( 'id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$table,
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);

		if ( ! $deleted ) {
			return new \WP_Error(
				'not_found',
				__( 'Conversation not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		return new \WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Delete oldest conversations if user exceeds the limit.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private function auto_purge( $user_id ) {
		global $wpdb;

		$table = $this->get_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			)
		);

		if ( $count <= self::MAX_CONVERSATIONS ) {
			return;
		}

		$to_delete = $count - self::MAX_CONVERSATIONS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d ORDER BY updated_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$to_delete
			)
		);
	}

	/**
	 * Sanitize messages array.
	 *
	 * @param array $messages Messages array.
	 * @return array Sanitized messages.
	 */
	private function sanitize_messages( $messages ) {
		$sanitized = array();

		// Allowed HTML for stored message content. AI replies contain structured
		// HTML (articles, FAQ, lists) that must survive the save/load round-trip,
		// otherwise the formatting is lost when reopening a conversation. This
		// whitelist mirrors what the chat renders; sanitize_textarea_field would
		// strip every tag. The bracket tags ([INSERT_CONTENT], etc.) are plain
		// text to wp_kses and are preserved.
		$allowed_html = array(
			'h1'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'h5'         => array(),
			'h6'         => array(),
			'p'          => array(),
			'br'         => array(),
			'hr'         => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'blockquote' => array(),
			'pre'        => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'a'          => array(
				'href'   => array(),
				'title'  => array(),
				'rel'    => array(),
				'target' => array(),
			),
			'code'       => array(),
			'sub'        => array(),
			'sup'        => array(),
			's'          => array(),
			'del'        => array(),
			'ins'        => array(),
			'mark'       => array(),
			'kbd'        => array(),
			'abbr'       => array( 'title' => array() ),
			'table'      => array(),
			'thead'      => array(),
			'tbody'      => array(),
			'tr'         => array(),
			'th'         => array(),
			'td'         => array(),
		);

		foreach ( $messages as $message ) {
			if ( ! isset( $message['role'] ) || ! isset( $message['content'] ) ) {
				continue;
			}

			$item = array(
				'role'    => sanitize_text_field( $message['role'] ),
				'content' => wp_kses( $message['content'], $allowed_html ),
			);

			// Preserve suggestions if present.
			if ( ! empty( $message['suggestions'] ) && is_array( $message['suggestions'] ) ) {
				$item['suggestions'] = array();
				foreach ( $message['suggestions'] as $suggestion ) {
					if ( isset( $suggestion['value'] ) ) {
						$item['suggestions'][] = array(
							'type'     => isset( $suggestion['type'] ) ? sanitize_text_field( $suggestion['type'] ) : '',
							'value'    => sanitize_text_field( $suggestion['value'] ),
							'meta_key' => isset( $suggestion['meta_key'] ) ? sanitize_text_field( $suggestion['meta_key'] ) : '',
						);
					}
				}
			}

			$sanitized[] = $item;
		}

		return $sanitized;
	}
}
