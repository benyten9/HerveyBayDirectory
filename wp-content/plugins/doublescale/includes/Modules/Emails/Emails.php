<?php
/**
 * Emails: class Emails
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Emails
 */

namespace DoubleScale\Modules\Emails;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emails.
 *
 * This class handles all (notification) emails sent by DoubleScale.
 *
 * Heavily influenced by the great AffiliateWP plugin by Pippin Williamson and WP forms.
 * https://github.com/JustinSainton/AffiliateWP/blob/master/includes/emails/class-affwp-emails.php
 *
 * @since 1.0.0
 */
class Emails {

	/**
	 * Store the from address.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $from_address;

	/**
	 * Store the from name.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $from_name;

	/**
	 * Store the reply-to address.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $reply_to = false;

	/**
	 * Store the carbon copy addresses.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $cc = false;

	/**
	 * Store the email content type.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $content_type;

	/**
	 * Store the email headers.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $headers;

	/**
	 * Whether to send email in HTML.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	public $html = true;

	/**
	 * The email template to use.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $template;

	/**
	 * Unsubscribe URL for List-Unsubscribe header (RFC 8058 compliance)
	 *
	 * @since 1.0.0
	 *
	 * @var string|false
	 */
	public $unsubscribe_url = false;

	/**
	 * Custom Message-ID for email threading.
	 *
	 * When set, this value is used as the Message-ID header, enabling
	 * reply matching via In-Reply-To for incoming email processing.
	 *
	 * @since 1.0.0
	 *
	 * @var string|false
	 */
	public $message_id = false;

	/**
	 * In-Reply-To header for email threading.
	 *
	 * When set, adds In-Reply-To and References headers so email clients
	 * and inbound processors can thread replies to the correct conversation.
	 *
	 * @since 1.0.0
	 *
	 * @var string|false
	 */
	public $in_reply_to = false;

	/**
	 * Human-readable reason for the last failed {@see self::send()} (PHPMailer / wp_mail_failed).
	 *
	 * @var string
	 */
	private static $last_send_failure_detail = '';

	/**
	 * Detail string from the most recent failed {@see self::send()} call, if any.
	 *
	 * Cleared at the start of each send; populated when `wp_mail()` returns false.
	 *
	 * @return string
	 */
	public static function get_last_send_failure_detail() {
		return self::$last_send_failure_detail;
	}

	/**
	 * Merge WordPress `wp_mail_failed` data with PHPMailer::ErrorInfo.
	 *
	 * @param string $wp_mail_failed_message Message from `wp_mail_failed` hook, if fired.
	 * @return string
	 */
	private static function compose_send_failure_detail( $wp_mail_failed_message ) {
		global $phpmailer;

		$parts = array();

		if ( class_exists( '\DoubleScale\Modules\Smtp\Settings', false ) ) {
			$smtp_attempt = \DoubleScale\Modules\Smtp\Settings::consume_smtp_send_attempt();
			if ( is_array( $smtp_attempt ) && ! empty( $smtp_attempt ) ) {
				$parts[] = \DoubleScale\Modules\Smtp\Settings::format_smtp_send_attempt_for_detail( $smtp_attempt );
			}
		}

		$w = is_string( $wp_mail_failed_message ) ? trim( $wp_mail_failed_message ) : '';
		if ( '' !== $w ) {
			$parts[] = $w;
		}

		if ( isset( $phpmailer ) && is_object( $phpmailer ) && property_exists( $phpmailer, 'ErrorInfo' ) ) {
			$p = trim( (string) $phpmailer->ErrorInfo );
			if ( '' !== $p && ! in_array( $p, $parts, true ) ) {
				$parts[] = $p;
			}
		}

		return implode( ' — ', $parts );
	}

	/**
	 * Get things going.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		if ( 'none' === $this->get_template() ) {
			$this->html = false;
		}

		add_action( 'doublescale_mail_send_before', array( $this, 'send_before' ) );
		add_action( 'doublescale_mail_send_after', array( $this, 'send_after' ) );
	}

	/**
	 * Get the email from name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The email from name
	 */
	public function get_from_name() {

		if ( empty( $this->from_name ) ) {
			$this->from_name = get_bloginfo( 'name' );
		}

		return apply_filters( 'doublescale_mail_from_name', doublescale_decode_string( $this->from_name ), $this );
	}

	/**
	 * Get the email from address.
	 *
	 * @since 1.0.0
	 *
	 * @return string The email from address.
	 */
	public function get_from_address() {

		if ( empty( $this->from_address ) ) {
			$this->from_address = get_option( 'admin_email' );
		}

		return apply_filters( 'doublescale_mail_from_address', doublescale_decode_string( $this->from_address ), $this );
	}

	/**
	 * Get the email reply-to.
	 *
	 * @since 1.0.0
	 *
	 * @return string The email reply-to address.
	 */
	public function get_reply_to() {

		if ( ! empty( $this->reply_to ) && ! is_email( $this->reply_to ) ) {
			$this->reply_to = false;
		}

		return apply_filters( 'doublescale_mail_reply_to', doublescale_decode_string( $this->reply_to ), $this );
	}

	/**
	 * Get the email carbon copy addresses.
	 *
	 * @since 1.0.0
	 *
	 * @return string The email reply-to address.
	 */
	public function get_cc() {

		if ( ! empty( $this->cc ) ) {
			$addresses = array_map( 'trim', explode( ',', $this->cc ) );

			foreach ( $addresses as $key => $address ) {
				if ( ! is_email( $address ) ) {
					unset( $addresses[ $key ] );
				}
			}

			$this->cc = implode( ',', $addresses );
		}

		return apply_filters( 'doublescale_mail_cc', doublescale_decode_string( $this->cc ), $this );
	}

	/**
	 * Get the email content type.
	 *
	 * @since 1.0.0
	 *
	 * @return string The email content type.
	 */
	public function get_content_type() {

		if ( ! $this->content_type && $this->html ) {
			$this->content_type = apply_filters( 'doublescale_mail_default_content_type', 'text/html', $this );
		} elseif ( ! $this->html ) {
			$this->content_type = 'text/plain';
		}

		return apply_filters( 'doublescale_mail_content_type', $this->content_type, $this );
	}

	/**
	 * Get the email headers.
	 * Includes List-Unsubscribe header for RFC 8058 compliance (Gmail/Yahoo 2024 requirement)
	 *
	 * @since 1.0.0
	 *
	 * @return string The email headers.
	 */
	public function get_headers() {

		if ( ! $this->headers ) {
			$this->headers = "From: {$this->get_from_name()} <{$this->get_from_address()}>\r\n";
			if ( $this->get_reply_to() ) {
				$this->headers .= "Reply-To: {$this->get_reply_to()}\r\n";
			}
			if ( $this->get_cc() ) {
				$this->headers .= "Cc: {$this->get_cc()}\r\n";
			}

			// Add email threading headers (Message-ID, In-Reply-To, References)
			// These enable reply matching for incoming email processing.
			if ( ! empty( $this->message_id ) ) {
				$this->headers .= "Message-ID: {$this->message_id}\r\n";
			}
			if ( ! empty( $this->in_reply_to ) ) {
				$this->headers .= "In-Reply-To: {$this->in_reply_to}\r\n";
				$this->headers .= "References: {$this->in_reply_to}\r\n";
			}

			// Add List-Unsubscribe headers for RFC 8058 compliance
			// Required by Gmail/Yahoo for bulk senders as of 2024
			if ( ! empty( $this->unsubscribe_url ) ) {
				$this->headers .= "List-Unsubscribe: <{$this->unsubscribe_url}>\r\n";
				$this->headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
			}

			$this->headers .= "Content-Type: {$this->get_content_type()}; charset=utf-8\r\n";
		}

		return apply_filters( 'doublescale_mail_headers', $this->headers, $this );
	}

	/**
	 * Build the email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message The email message.
	 *
	 * @return string
	 */
	public function build_email( $message ) {
		// Plain text email shortcut.
		if ( false === $this->html ) {
			return apply_filters( 'doublescale_mail_message', doublescale_decode_string( $message ), $this );
		}

		/*
		* Generate an HTML email.
		*/

		// Check if message is already a complete HTML document (from EmailRenderer)
		$is_complete_html = strpos( $message, '<!DOCTYPE html' ) !== false ||
						strpos( $message, '<html' ) !== false;

		if ( $is_complete_html ) {
			// Message is already a complete HTML document, return as-is
			return apply_filters( 'doublescale_mail_message', $message, $this );
		}

		// Process as template-based email
		ob_start();

		$this->get_template_part( 'header', $this->get_template(), true );

		// Hooks into the email header.
		do_action( 'doublescale_mail_header', $this );

		$this->get_template_part( 'body', $this->get_template(), true );

		// Hooks into the email body.
		do_action( 'doublescale_mail_body', $this );

		$this->get_template_part( 'footer', $this->get_template(), true );

		// Only apply nl2br and make_clickable for template-based emails
		$message = nl2br( $message );

		$body = ob_get_clean();

		$message = str_replace( '{email}', $message, $body );

		// Skip make_clickable if message already has links (prevents nested <a> tags)
		if ( strpos( $message, '<a ' ) === false ) {
			$message = make_clickable( $message );
		}

		return apply_filters( 'doublescale_mail_message', $message, $this );
	}

	/**
	 * Send the email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $to          The To address.
	 * @param string $subject     The subject line of the email.
	 * @param string $message     The body of the email.
	 * @param array  $attachments Attachments to the email.
	 *
	 * @return bool True on success. On failure, see {@see self::get_last_send_failure_detail()}.
	 */
	public function send( $to, $subject, $message, $attachments = array() ) {
		self::$last_send_failure_detail = '';

		// Don't send if email address is invalid.
		if ( ! is_email( $to ) ) {
			self::$last_send_failure_detail = __( 'Invalid recipient email address.', 'doublescale' );
			return false;
		}

		// Hooks before email is sent.
		do_action( 'doublescale_mail_send_before', $this );

		/*
		 * Allow to filter data on per-email basis,
		 * useful for localizations based on recipient email address, form settings,
		 * or for specific notifications - whatever available in Emails class.
		 */
		$data = apply_filters(
			'doublescale_emails_send_email_data',
			array(
				'to'          => $to,
				'subject'     => $subject,
				'message'     => $message,
				'headers'     => $this->get_headers(),
				'attachments' => $attachments,
			),
			$this
		);

		// Prepare subject and message.
		$prepared_subject = $this->get_prepared_subject( $data['subject'] );
		$prepared_message = $this->build_email( $data['message'] );

		$wp_mail_failed_message = '';
		$wp_mail_failed_cb      = static function ( $error ) use ( &$wp_mail_failed_message ) {
			if ( ! $error instanceof \WP_Error ) {
				return;
			}
			$wp_mail_failed_message = $error->get_error_message();
			$data                   = $error->get_error_data();
			if ( is_array( $data ) ) {
				if ( isset( $data['phpmailer_exception'] ) && $data['phpmailer_exception'] instanceof \Exception ) {
					$wp_mail_failed_message = $data['phpmailer_exception']->getMessage();
				} elseif ( isset( $data['exception'] ) && $data['exception'] instanceof \Exception ) {
					$wp_mail_failed_message = $data['exception']->getMessage();
				}
			}
		};
		add_action( 'wp_mail_failed', $wp_mail_failed_cb, 10, 1 );

		$result = false;
		try {
			// Let's do this NOW.
			$result = wp_mail(
				$data['to'],
				$prepared_subject,
				$prepared_message,
				$data['headers'],
				$data['attachments']
			);
		} finally {
			remove_action( 'wp_mail_failed', $wp_mail_failed_cb, 10 );
		}

		if ( ! $result ) {
			self::$last_send_failure_detail = self::compose_send_failure_detail( $wp_mail_failed_message );
		} elseif ( class_exists( '\DoubleScale\Modules\Smtp\Settings', false ) ) {
			// Discard routing snapshot so a later failed send is not attributed to this successful attempt.
			\DoubleScale\Modules\Smtp\Settings::consume_smtp_send_attempt();
		}

		// Hooks after the email is sent.
		do_action( 'doublescale_mail_send_after', $this );

		return $result;
	}

	/**
	 * Add filters/actions before the email is sent.
	 *
	 * @since 1.0.0
	 */
	public function send_before() {

		add_filter( 'wp_mail_from', array( $this, 'get_from_address' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'get_from_name' ) );
		add_filter( 'wp_mail_content_type', array( $this, 'get_content_type' ) );

		// Hook into PHPMailer to set custom headers and Message-ID directly.
		add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ) );
	}

	/**
	 * Remove filters/actions after the email is sent.
	 *
	 * @since 1.0.0
	 */
	public function send_after() {

		remove_filter( 'wp_mail_from', array( $this, 'get_from_address' ) );
		remove_filter( 'wp_mail_from_name', array( $this, 'get_from_name' ) );
		remove_filter( 'wp_mail_content_type', array( $this, 'get_content_type' ) );
		remove_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ) );
	}

	/**
	 * Configure PHPMailer instance before sending.
	 *
	 * Sets the custom Message-ID (PHPMailer may override headers set via wp_mail,
	 * so we set $phpmailer->MessageID directly) and stamps an X-Plugin-Sent
	 * header so the SENT-folder sync can recognize CRM-originated emails.
	 *
	 * @since 1.0.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 */
	public function configure_phpmailer( $phpmailer ) {
		if ( $this->message_id ) {
			$phpmailer->MessageID = $this->message_id;
		}

		$phpmailer->addCustomHeader( 'X-Plugin-Sent', '1' );
	}

	/**
	 * Convert text formatted HTML. This is primarily for turning line breaks
	 * into <p> and <br/> tags.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Text to convert.
	 *
	 * @return string
	 */
	public function text_to_html( $message ) {

		if ( 'text/html' === $this->content_type || true === $this->html ) {
			$message = wpautop( $message );
		}

		return $message;
	}

	/**
	 * Get the enabled email template.
	 *
	 * @since 1.0.0
	 *
	 * @return string When filtering return 'none' to switch to text/plain email.
	 */
	public function get_template() {

		if ( ! $this->template ) {
			$this->template = 'default';
		}

		return apply_filters( 'doublescale_mail_template', $this->template );
	}

	/**
	 * Retrieve a template part. Taken from bbPress.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Template file slug.
	 * @param string $name Optional. Default null.
	 * @param bool   $load Maybe load.
	 *
	 * @return string
	 */
	public function get_template_part( $slug, $name = null, $load = true ) {

		// Setup possible parts.
		$templates = array();
		if ( isset( $name ) ) {
			$templates[] = $slug . '-' . $name . '.php';
		}
		$templates[] = $slug . '.php';

		// Return the part that is found.
		return $this->locate_template( $templates, $load, false );
	}

	/**
	 * Retrieve the name of the highest priority template file that exists.
	 *
	 * Search in the STYLESHEETPATH before TEMPLATEPATH so that themes which
	 * inherit from a parent theme can just overload one file. If the template is
	 * not found in either of those, it looks in the theme-compat folder last.
	 *
	 * Taken from bbPress.
	 *
	 * @since 1.0.0
	 *
	 * @param string|array $template_names Template file(s) to search for, in order.
	 * @param bool         $load           If true the template file will be loaded if it is found.
	 * @param bool         $require_once   Whether to require_once or require. Default true.
	 *                                     Has no effect if $load is false.
	 *
	 * @return string The template filename if one is located.
	 */
	public function locate_template( $template_names, $load = false, $require_once = true ) {

		// No file found yet.
		$located = false;

		// Try to find a template file.
		foreach ( (array) $template_names as $template_name ) {

			// Continue if template is empty.
			if ( empty( $template_name ) ) {
				continue;
			}

			// Trim off any slashes from the template name.
			$template_name = ltrim( $template_name, '/' );

			// Try locating this template file by looping through the template paths.
			foreach ( $this->get_theme_template_paths() as $template_path ) {
				if ( file_exists( $template_path . $template_name ) ) {
					$located = $template_path . $template_name;
					break;
				}
			}
		}

		if ( ( true === $load ) && ! empty( $located ) ) {
			load_template( $located, $require_once );
		}

		return $located;
	}

	/**
	 * Return a list of paths to check for template locations
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_theme_template_paths() {

		$stylesheet = trailingslashit( get_stylesheet_directory() );
		$template   = trailingslashit( get_template_directory() );

		$file_paths = array(
			1   => $stylesheet . 'doublescale-email',
			2   => $stylesheet . 'ds-email',
			10  => $template . 'doublescale-email',
			11  => $template . 'ds-email',
			100 => DOUBLESCALE_PLUGIN_DIR . 'includes/Modules/Emails/Templates',
		);

		$file_paths = apply_filters( 'doublescale_mail_template_paths', $file_paths );

		// Sort the file paths based on priority.
		ksort( $file_paths, SORT_NUMERIC );

		return array_map( 'trailingslashit', $file_paths );
	}

	/**
	 * Send double opt-in confirmation email to a contact.
	 *
	 * Centralizes the double opt-in email logic used by forms, imports,
	 * and the manual resend endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact Contact model instance.
	 *
	 * @return bool True if the email was sent successfully, false otherwise.
	 */
	public static function send_double_optin_email( $contact ) {
		try {
			$settings   = \DoubleScale\Core\Settings\Settings::get( 'double_optin', array() );
			$subject    = $settings['email_subject'] ?? \DoubleScale\Core\Settings\Settings::get_default_opt_in_subject();
			$content    = $settings['email_content'] ?? \DoubleScale\Core\Settings\Settings::get_default_opt_in_content();
			$merge_tags = \DoubleScale\Core\MergeTags\MergeTagsManager::instance();
			$subject    = $merge_tags->process_merge_tags( $subject, $contact );
			$content    = $merge_tags->process_merge_tags( $content, $contact );
			$emails     = new self();
			$result     = $emails->send( $contact->email, $subject, $content );

			doublescale_get_logger()->info(
				__( 'Double opt-in email sent', 'doublescale' ),
				array(
					'contact_id' => $contact->id,
					'email'      => $contact->email,
				)
			);

			return $result;
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Error sending double opt-in email', 'doublescale' ),
				array(
					'contact_id' => $contact->id ?? null,
					'error'      => $e->getMessage(),
				)
			);

			return false;
		}
	}

	/**
	 * Perform email subject preparation: process tags, remove new lines, etc.
	 *
	 * @since 1.0.0
	 *
	 * @param string $subject Email subject to post-process.
	 *
	 * @return string
	 */
	private function get_prepared_subject( $subject ) {
		$subject = trim( str_replace( array( "\r\n", "\r", "\n" ), ' ', $subject ) );

		return doublescale_decode_string( $subject );
	}
}
