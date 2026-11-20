<?php
/**
 * Limit checks and error triage for the send queue.
 *
 * @package DoubleScale\Pro\Modules\SendQueue
 */

namespace DoubleScale\Pro\Modules\SendQueue;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Campaigns\Whatsapp\MetaTierGuard;

/**
 * Limits helper.
 */
final class Limits {

	/** Retries for a temporary failure before the step fails: wait 1, 5, 30 minutes. */
	const RETRY_DELAYS = array( 60, 300, 1800 );

	/** How long to wait when Meta rates the number RED (no automatic resume time). */
	const QUALITY_RETRY_SECONDS = 3600;

	/**
	 * Whether a WhatsApp message to $recipient fits the Meta tier right now.
	 *
	 * @param string $recipient Recipient as tracked.
	 * @return array{allowed: bool, resumes_at: string|null, reason: string} resumes_at is local time.
	 */
	public static function whatsapp_check( string $recipient ): array {
		if ( ! class_exists( MetaTierGuard::class ) || ! MetaTierGuard::is_active() ) {
			return array(
				'allowed'    => true,
				'resumes_at' => null,
				'reason'     => '',
			);
		}

		$check = MetaTierGuard::can_contact( $recipient );
		if ( ! empty( $check['allowed'] ) ) {
			return array(
				'allowed'    => true,
				'resumes_at' => null,
				'reason'     => '',
			);
		}

		$quality = MetaTierGuard::QUALITY_CODE === ( $check['reason'] ?? '' );
		$resume  = ! $quality && ! empty( $check['resumes_at'] )
			? strtotime( $check['resumes_at'] . ' UTC' )
			: time() + self::QUALITY_RETRY_SECONDS;

		return array(
			'allowed'    => false,
			'resumes_at' => wp_date( 'Y-m-d H:i:s', $resume ?: time() + self::QUALITY_RETRY_SECONDS ),
			'reason'     => $quality ? 'whatsapp_quality' : 'whatsapp_tier',
		);
	}

	/**
	 * Whether a failed send is worth retrying (temporary) rather than final.
	 *
	 * @param string $error Provider or SMTP error.
	 * @return bool
	 */
	public static function is_transient( string $error ): bool {
		if ( '' === trim( $error ) ) {
			return true;
		}
		// SMTP permanent rejections (550 5.1.1 no such mailbox…) never succeed on retry.
		if ( preg_match( '/\b55[0-4]\b|\b5\.\d\.\d+\b/', $error ) ) {
			return false;
		}
		return (bool) preg_match(
			'/time[ds]?[ -]?out|timed out|\b429\b|too many|rate[ -]?limit|throttl|temporar|try again|\b50[0234]\b|\b4(21|5[0-2])\b|\b4\.\d\.\d+\b|unavailable|connection|could not connect|connect\(\) failed|network|deadlock/i',
			$error
		);
	}

	/**
	 * Seconds to wait before retry number $attempt (1-based), or null when out of retries.
	 *
	 * @param int $attempt Attempts made so far.
	 * @return int|null
	 */
	public static function retry_delay( int $attempt ): ?int {
		return self::RETRY_DELAYS[ $attempt - 1 ] ?? null;
	}
}
