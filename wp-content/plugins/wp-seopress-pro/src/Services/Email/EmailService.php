<?php
namespace SEOPress\Services\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EmailService {
	/**
	 * Send an email using the default template
	 *
	 * @param string $to          Email recipient
	 * @param string $subject     Email subject
	 * @param string $content     Email content (can be HTML)
	 * @param array  $args        Template arguments
	 * @param array  $attachments Optional. Files to attach
	 * @return bool Whether the email was sent successfully
	 */
	public function send( $to, $subject, $content, $args = array(), $attachments = array() ) {
		// Set content type to HTML
		add_filter( 'wp_mail_content_type', array( $this, 'setHtmlContentType' ) );

		// Get the email content with template
		$email_content = seopress_get_email_template( $content, $args );

		// Send the email
		$headers = array(
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_bloginfo( 'admin_email' ) . '>',
		);

		$sent = wp_mail( $to, $subject, $email_content, $headers, $attachments );

		// Reset content type
		remove_filter( 'wp_mail_content_type', array( $this, 'setHtmlContentType' ) );

		return $sent;
	}

	/**
	 * Set the content type to HTML for wp_mail
	 *
	 * @return string
	 */
	public function setHtmlContentType() {
		return 'text/html';
	}

	/**
	 * Get instance of the service
	 *
	 * @return self
	 */
	public static function get_instance() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}
}
