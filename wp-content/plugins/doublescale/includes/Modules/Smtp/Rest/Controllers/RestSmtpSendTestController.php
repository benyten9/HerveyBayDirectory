<?php
/**
 * Send a test message through a specific SMTP connection (wp_mail).
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Smtp\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Smtp\Settings;
use DoubleScale\Modules\Smtp\SmtpConnectionValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller: POST /doublescale/v1/smtp/send-test
 */
class RestSmtpSendTestController extends RestController {

	/**
	 * REST base (relative to namespace).
	 *
	 * @var string
	 */
	protected $rest_base = 'smtp/send-test';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'email'        => array(
						'required' => true,
						'type'     => 'string',
						'format'   => 'email',
					),
					'connection'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'content_type' => array(
						'type'    => 'string',
						'default' => 'html',
						'enum'    => array( 'html', 'plain' ),
					),
					'message'      => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Send test email.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send( $request ) {
		$email          = (string) $request->get_param( 'email' );
		$connection_id  = sanitize_text_field( (string) $request->get_param( 'connection' ) );
		$content_type   = (string) $request->get_param( 'content_type' );
		$raw_message    = $request->get_param( 'message' );
		$custom_message = is_string( $raw_message ) ? trim( wp_unslash( $raw_message ) ) : '';
		$connections    = Settings::get( 'connections', array() );

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'doublescale_smtp_test_invalid_email',
				__( 'Please enter a valid email address.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $connection_id || ! isset( $connections[ $connection_id ] ) ) {
			return new WP_Error(
				'doublescale_smtp_test_unknown_connection',
				__( 'Connection not found.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		$connection_row = $connections[ $connection_id ];
		$account_check  = SmtpConnectionValidator::validate_single_connection( $connection_id, $connection_row );
		if ( is_wp_error( $account_check ) ) {
			return $account_check;
		}

		$explicit = static function () use ( $connection_id ) {
			return $connection_id;
		};

		add_filter( 'doublescale_smtp_explicit_connection', $explicit, 10, 0 );

		$blogname    = get_option( 'blogname', '' );
		$admin_email = get_option( 'admin_email', '' );
		$subject     = __( 'DoubleScale SMTP Test', 'doublescale' );
		$body_plain  = __( 'This is a test email sent from DoubleScale SMTP.', 'doublescale' );
		$body_html   = '<p>' . esc_html( $body_plain ) . '</p>';

		if ( '' !== $custom_message ) {
			if ( 'html' === $content_type ) {
				$body = wp_kses_post( $custom_message );
				if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
					$body = $body_html;
				}
			} else {
				$body = sanitize_textarea_field( $custom_message );
				if ( '' === $body ) {
					$body = $body_plain;
				}
			}
		} else {
			$body = 'html' === $content_type ? $body_html : $body_plain;
		}

		// Resolve {{group:slug}} merge tags (e.g. {{general:admin_email}}) like campaigns / inbox.
		$subject = MergeTagsManager::instance()->process_merge_tags( $subject, null );
		$body    = MergeTagsManager::instance()->process_merge_tags( $body, null );

		$content_head = 'html' === $content_type ? 'text/html' : 'text/plain';
		$headers      = array(
			'Content-Type: ' . $content_head . '; charset=UTF-8',
		);

		// From MUST be the selected connection's own sending address, not the WP
		// admin_email. OAuth providers that send "as" the authenticated mailbox —
		// Outlook (Graph /me/sendMail), Gmail (Graph-equivalent), Zoho — reject any
		// other From with an identity error (e.g. Outlook's ErrorSendAsDenied:
		// "does not have the right to send mail on behalf of the specified sending
		// account"). Fall back to admin_email only when the connection has no
		// from_email of its own (e.g. a bare custom SMTP relay).
		$connection_from = isset( $connection_row['from_email'] ) ? trim( (string) $connection_row['from_email'] ) : '';
		$from_address    = '' !== $connection_from ? $connection_from : $admin_email;
		$from_name       = isset( $connection_row['from_name'] ) && '' !== trim( (string) $connection_row['from_name'] )
			? trim( (string) $connection_row['from_name'] )
			: $blogname;
		if ( $from_address ) {
			$from      = $from_name ? sprintf( '%s <%s>', $from_name, $from_address ) : $from_address;
			$headers[] = 'From: ' . $from;
		}

		$result = wp_mail( $email, $subject, $body, $headers );

		remove_filter( 'doublescale_smtp_explicit_connection', $explicit, 10 );

		if ( ! $result ) {
			global $phpmailer;
			$detail = '';
			if ( $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
				$detail = trim( (string) $phpmailer->ErrorInfo );
			}
			if ( '' === $detail ) {
				$detail = __(
					'Mail was not sent. For Gmail, Outlook, or Zoho, authorize the provider first (REST or legacy smtp OAuth) so the Account ID on this connection matches a stored account with valid tokens.',
					'doublescale'
				);
			}
			return new WP_Error(
				'doublescale_smtp_test_send_failed',
				$detail,
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Email sent successfully.', 'doublescale' ),
			),
			200
		);
	}

	/**
	 * Permission check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return Settings::user_can_manage_smtp_rest();
	}
}
