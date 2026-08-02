<?php

/**
 * Amazon SES Bounce Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\BounceHandlers;

use DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler;

/**
 * AmazonsesBounceHandler class
 */
class AmazonsesBounceHandler extends BounceHandler
{

	/**
	 * Provider name
	 *
	 * @var string
	 */
	protected $name = 'Amazon SES';

	/**
	 * Provider description
	 *
	 * @var string
	 */
	protected $description = 'Configure Amazon SES bounce notifications via SNS';

	/**
	 * Documentation URL
	 *
	 * @var string
	 */
	protected $doc_url = 'https://docs.aws.amazon.com/ses/latest/dg/configure-sns-notifications.html';

	/**
	 * Setup instructions
	 *
	 * @var string
	 */
	protected $setup_instructions = 'Set up an SNS topic for bounces in AWS SES Console → Email Addresses → Notifications, then subscribe this webhook URL to that topic.';

	/**
	 * Handle Amazon SES bounce webhook (via SNS)
	 *
	 * @since 1.0.0
	 *
	 * @return bool|array
	 */
	public function handle()
	{
		// Validate data structure.
		if (empty($this->data)) {
			$this->log('Empty webhook data received', 'warning');
			return false;
		}

		// Handle SNS subscription confirmation.
		if (isset($this->data['Type']) && $this->data['Type'] === 'SubscriptionConfirmation') {
			return $this->handle_subscription_confirmation();
		}

		// Verify this is a notification.
		if (! isset($this->data['Type']) || $this->data['Type'] !== 'Notification') {
			$this->log(
				sprintf('Unsupported SNS message type: %s', $this->data['Type'] ?? 'unknown'),
				'warning'
			);
			return false;
		}

		// Verify SNS signature (CRITICAL for security).
		// Allow bypassing for testing environments only.
		$skip_verification = apply_filters('doublescale_amazonses_skip_signature_verification', false);

		// Also check for test mode option (for automated testing)
		// if ( ! $skip_verification && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// 	$test_mode = get_option( 'doublescale_amazonses_test_mode', false );
		// 	if ( $test_mode ) {
		// 		$skip_verification = true;
		// 		$this->log( 'SNS signature verification skipped (test mode enabled)', 'debug' );
		// 	}
		// }

		if (! $skip_verification && ! $this->verify_sns_signature()) {
			$this->log('SNS signature verification failed - possible security threat', 'error');
			return false;
		}

		// Parse the nested Message field (contains actual SES notification).
		if (! isset($this->data['Message'])) {
			$this->log('Missing Message field in SNS notification', 'warning');
			return false;
		}

		$message = json_decode($this->data['Message'], true);
		if (! $message) {
			$this->log('Failed to parse Message JSON', 'warning');
			return false;
		}

		// Verify this is a bounce notification.
		if (! isset($message['notificationType']) || $message['notificationType'] !== 'Bounce') {
			$this->log(
				sprintf('Non-bounce notification received: %s', $message['notificationType'] ?? 'unknown'),
				'info'
			);
			return false;
		}

		// Extract bounce data.
		if (! isset($message['bounce'])) {
			$this->log('Missing bounce data in SES notification', 'warning');
			return false;
		}

		$bounce = $message['bounce'];

		// Process each bounced recipient.
		$bounced_recipients = $bounce['bouncedRecipients'] ?? array();
		if (empty($bounced_recipients)) {
			$this->log('No bounced recipients in notification', 'warning');
			return false;
		}

		$results       = array();
		$success_count = 0;
		$total_count   = count($bounced_recipients);

		foreach ($bounced_recipients as $index => $recipient) {
			$email = $recipient['emailAddress'] ?? '';
			if (empty($email)) {
				$this->log(sprintf('Missing emailAddress at recipient index %d', $index), 'warning');
				continue;
			}

			// Determine bounce type.
			$bounce_type_raw = $bounce['bounceType'] ?? 'Undetermined';
			$bounce_type     = $this->classify_bounce($bounce_type_raw);

			// Build bounce reason from bounceSubType and diagnosticCode.
			$bounce_sub_type = $bounce['bounceSubType'] ?? 'General';
			$diagnostic_code = $recipient['diagnosticCode'] ?? '';
			$status          = $recipient['status'] ?? '';
			$action          = $recipient['action'] ?? 'failed';

			$reason = sprintf('%s - %s', $bounce_type_raw, $bounce_sub_type);
			if ($diagnostic_code) {
				$reason .= ': ' . $diagnostic_code;
			}

			// Parse timestamp.
			$bounced_at = $bounce['timestamp'] ?? '';
			$timestamp  = $bounced_at ? strtotime($bounced_at) : time();

			// Build metadata array.
			$metadata = array(
				'provider'        => 'amazonses',
				'bounce_type'     => $bounce_type,
				'reason'          => $reason,
				'diagnostic_code' => $diagnostic_code,
				'timestamp'       => $timestamp,
				'bounce_type_raw' => $bounce_type_raw,
				'bounce_sub_type' => $bounce_sub_type,
				'dsn_status'      => $status,
				'action'          => $action,
				'feedback_id'     => $bounce['feedbackId'] ?? '',
				'remote_mta_ip'   => $bounce['remoteMtaIp'] ?? '',
				'reporting_mta'   => $bounce['reportingMTA'] ?? '',
			);

			// Process the bounce.
			$result = $this->mark_contact_bounced($email, $metadata);

			$results[$index] = array(
				'email'   => $email,
				'success' => $result,
			);

			if ($result) {
				$success_count++;
			}
		}

		// Log summary.
		$this->log(
			sprintf(
				'Processed %d/%d bounced recipients from Amazon SES',
				$success_count,
				$total_count
			),
			'info',
			array(
				'total'           => $total_count,
				'success'         => $success_count,
				'failed'          => $total_count - $success_count,
				'bounce_type_raw' => $bounce['bounceType'] ?? 'unknown',
			)
		);

		return $results;
	}

	/**
	 * Classify bounce based on bounceType
	 *
	 * @since 1.0.0
	 *
	 * @param string $bounce_type_raw Amazon SES bounceType value.
	 *
	 * @return string 'hard' or 'soft'
	 */
	private function classify_bounce($bounce_type_raw)
	{
		// Permanent bounces are hard bounces.
		if ($bounce_type_raw === 'Permanent') {
			return 'hard';
		}

		// Transient bounces are soft bounces.
		if ($bounce_type_raw === 'Transient') {
			return 'soft';
		}

		// Default to soft for undetermined.
		$this->log(
			sprintf('Unknown bounceType: %s, defaulting to soft', $bounce_type_raw),
			'warning',
			array('bounce_type_raw' => $bounce_type_raw)
		);

		return 'soft';
	}

	/**
	 * Verify SNS message signature
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function verify_sns_signature()
	{
		// Check for required signature fields.
		if (! isset($this->data['SignatureVersion']) || ! isset($this->data['Signature']) || ! isset($this->data['SigningCertURL'])) {
			$this->log('Missing required SNS signature fields', 'error');
			return false;
		}

		// Validate SigningCertURL is from AWS.
		$cert_url = $this->data['SigningCertURL'];
		$parsed   = wp_parse_url($cert_url);

		if (! $parsed || ! isset($parsed['host'])) {
			$this->log('Invalid SigningCertURL format', 'error');
			return false;
		}

		// Verify the certificate URL is from amazonaws.com domain.
		if (! preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/i', $parsed['host'])) {
			$this->log(
				sprintf('SigningCertURL host not from AWS: %s', $parsed['host']),
				'error',
				array('cert_url' => $cert_url)
			);
			return false;
		}

		// Verify HTTPS protocol.
		if (! isset($parsed['scheme']) || $parsed['scheme'] !== 'https') {
			$this->log('SigningCertURL must use HTTPS', 'error');
			return false;
		}

		// Download the certificate.
		$response = wp_remote_get(
			$cert_url,
			array(
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if (is_wp_error($response)) {
			$this->log(
				sprintf('Failed to download signing certificate: %s', $response->get_error_message()),
				'error'
			);
			return false;
		}

		$certificate = wp_remote_retrieve_body($response);
		if (empty($certificate)) {
			$this->log('Empty certificate received', 'error');
			return false;
		}

		// Extract public key from certificate.
		$public_key = openssl_pkey_get_public($certificate);
		if (! $public_key) {
			$this->log('Failed to extract public key from certificate', 'error');
			return false;
		}

		// Build the string to sign based on message type.
		$string_to_sign = $this->build_string_to_sign();

		// Decode the signature.
		$signature = base64_decode($this->data['Signature'], true);
		if (! $signature) {
			$this->log('Failed to decode signature', 'error');
			return false;
		}

		// Verify the signature.
		$signature_version = $this->data['SignatureVersion'];
		$algorithm         = ($signature_version === '2') ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;

		$verify_result = openssl_verify($string_to_sign, $signature, $public_key, $algorithm);

		// Free the key resource.
		openssl_free_key($public_key);

		if ($verify_result === 1) {
			$this->log('SNS signature verified successfully', 'info');
			return true;
		}

		$this->log(
			sprintf('SNS signature verification failed (result: %d)', $verify_result),
			'error'
		);
		return false;
	}

	/**
	 * Build string to sign for SNS signature verification
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function build_string_to_sign()
	{
		// Determine fields to include based on message type.
		$type = $this->data['Type'] ?? '';

		if ($type === 'SubscriptionConfirmation' || $type === 'UnsubscribeConfirmation') {
			$fields = array('Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type');
		} else {
			// Notification type.
			$fields = array('Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type');
		}

		// Build string by concatenating field name and value.
		$string = '';
		foreach ($fields as $field) {
			if (isset($this->data[$field])) {
				$string .= $field . "\n" . $this->data[$field] . "\n";
			}
		}

		return $string;
	}

	/**
	 * Handle SNS subscription confirmation
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function handle_subscription_confirmation()
	{
		// Log subscription confirmation received.
		$this->log(
			'SNS subscription confirmation received',
			'info',
			array(
				'topic_arn' => $this->data['TopicArn'] ?? '',
				'token'     => substr($this->data['Token'] ?? '', 0, 20) . '...',
			)
		);

		if (! isset($this->data['SubscribeURL'])) {
			$this->log('Missing SubscribeURL in subscription confirmation', 'warning');
			return false;
		}

		// Validate SubscribeURL is from amazonaws.com to prevent SSRF.
		$parsed_url = wp_parse_url($this->data['SubscribeURL']);
		if (
			! $parsed_url
			|| ! isset($parsed_url['host'])
			|| ! isset($parsed_url['scheme'])
			|| $parsed_url['scheme'] !== 'https'
			|| ! preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/i', $parsed_url['host'])
		) {
			$this->log(
				sprintf('Invalid SubscribeURL - not from AWS: %s', $this->data['SubscribeURL']),
				'error'
			);
			return false;
		}

		// Auto-confirm by default. Developers can disable if needed.
		$auto_confirm = apply_filters('doublescale_amazonses_auto_confirm_subscription', true, $this->data);

		if (! $auto_confirm) {
			$this->log(
				sprintf('Auto-confirm disabled. To confirm subscription, visit: %s', $this->data['SubscribeURL']),
				'notice'
			);
			return true;
		}

		$response = wp_remote_get($this->data['SubscribeURL'], array('timeout' => 10));

		if (is_wp_error($response)) {
			$this->log(
				sprintf('Failed to auto-confirm SNS subscription: %s', $response->get_error_message()),
				'warning'
			);
			return false;
		}

		if (wp_remote_retrieve_response_code($response) === 200) {
			$this->log('SNS subscription confirmed automatically', 'notice');
			return true;
		}

		$this->log(
			sprintf(
				'Failed to auto-confirm SNS subscription (HTTP %d)',
				wp_remote_retrieve_response_code($response)
			),
			'warning'
		);
		return false;
	}
}
