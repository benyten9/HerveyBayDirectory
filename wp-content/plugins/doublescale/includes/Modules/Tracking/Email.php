<?php

/**
 * Class Email Tracking
 * This class is responsible for handling the Email Tracking
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Tracking;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * Email Tracking
 */
class Email {


	/**
	 * 1x1 transparent GIF pixel (base64-encoded).
	 *
	 * @var string
	 */
	private const TRACKING_PIXEL = 'R0lGODlhAQABAIAAAP///wAAACwAAAAAAQABAAACAkQBADs=';

	/**
	 * Class Instance.
	 *
	 * @since 1.0.0
	 *
	 * @var Email
	 */
	private static $instance;

	/**
	 * Email Instance.
	 *
	 * Instantiates or reuses an instance of Email.
	 *
	 * @since 1.0.0
	 *
	 * @return Email
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Email Tracking
	 */
	public function __construct() {
		// An open or a click can start an automation, and that run is queued
		// through Action Scheduler, which takes work from `init` priority 1 on.
		// The pixel request dies as soon as it is answered, so the run cannot be
		// deferred: handled at `doublescale_ready` it was never queued at all.
		add_action( 'init', array( $this, 'init' ), 2 );
	}

	/**
	 * Init
	 */
	public function init() {
		$this->email_opened_tracking();
		$this->email_clicked_tracking();
	}

	/**
	 * Send a 1x1 transparent tracking pixel and terminate.
	 *
	 * @param bool $cache Whether to allow caching (false = send no-cache headers).
	 */
	private function send_tracking_pixel( $cache = false ) {
		// The theme has loaded by `init`: whatever it printed on the way would
		// reach the mail client ahead of the image and break it.
		if ( ob_get_length() ) {
			ob_clean();
		}

		$pixel = base64_decode( self::TRACKING_PIXEL ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary 1x1 GIF tracking pixel.

		header( 'Content-Type: image/gif' );
		// The real length: a server that passes a longer one through (nginx,
		// unlike Apache) leaves the mail client waiting for bytes that never come.
		header( 'Content-Length: ' . strlen( $pixel ) );
		if ( ! $cache ) {
			header( 'Cache-Control: private, no-cache, no-cache=Set-Cookie, proxy-revalidate' );
			header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
			header( 'Last-Modified: Wed, 11 Jan 1984 05:00:00 GMT' );
			header( 'Pragma: no-cache' );
		}
		die( $pixel ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary 1x1 GIF tracking pixel; escaping would corrupt the bytes.
	}

	/**
	 * Check if a tracking entry is a failed outbound message.
	 *
	 * @param object $tracking_entry Communication tracking model instance.
	 * @return bool
	 */
	private function is_failed_outbound( $tracking_entry ) {
		return (int) $tracking_entry->status === TrackingStatus::FAILED
			&& (int) $tracking_entry->direction === MessageDirection::OUTBOUND;
	}

	/**
	 * Record the first open of an email and announce it.
	 *
	 * An open is learned from the pixel, from a click in a client that blocks
	 * images, and from a reply. Whichever comes first is the open: automations,
	 * notifications and lead scoring hear about each email once.
	 *
	 * @since 1.4.8
	 *
	 * @param CommunicationTrackingModel $tracking_entry Email that was opened.
	 * @param string                     $via            How it was detected: pixel, click or reply.
	 * @return bool Whether this call recorded the open.
	 */
	public static function record_open( $tracking_entry, $via = 'pixel' ) {
		if ( ! $tracking_entry instanceof CommunicationTrackingModel || $tracking_entry->opened ) {
			return false;
		}

		$opened_at = current_time( 'mysql', true );

		// Claimed in one statement, so two requests for the same pixel cannot
		// both find the email unopened and both start the workflow.
		$claimed = CommunicationTrackingModel::where( 'id', $tracking_entry->id )
			->where(
				static function ( $query ) {
					$query->where( 'opened', 0 )->orWhereNull( 'opened' );
				}
			)
			->update(
				array(
					'opened'    => 1,
					'opened_at' => $opened_at,
				)
			);

		if ( ! $claimed ) {
			return false;
		}

		$tracking_entry->opened    = 1;
		$tracking_entry->opened_at = $opened_at;
		$tracking_entry->syncOriginalAttributes( array( 'opened', 'opened_at' ) );

		$contact = $tracking_entry->contact;
		if ( $contact ) {
			/**
			 * Fires the first time an email is opened.
			 *
			 * @param ContactModel               $contact        Contact who opened it.
			 * @param CommunicationTrackingModel $tracking_entry The opened email. Since 1.4.8.
			 * @param string                     $via            pixel, click or reply. Since 1.4.8.
			 */
			do_action( 'doublescale_mail_open', $contact, $tracking_entry, $via );
		}

		return true;
	}

	/**
	 * Email Opened Tracking
	 * Handles tracking for all email types: Campaign, Automation, and Individual
	 */
	public function email_opened_tracking() {
		try {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public open-tracking pixel; identity comes from the per-message hash_key validated below.
			if ( ! isset( $_GET['doublescale'] ) || $_GET['doublescale'] !== 'email_open' ) {
				return;
			}

			$hash_key = isset( $_GET['hash_key'] ) ? sanitize_text_field( wp_unslash( $_GET['hash_key'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			$tracking_entry = CommunicationTrackingModel::where( 'hash_key', $hash_key )
				->where( 'mode', CommunicationTrackingModel::MODE_EMAIL )
				->first();

			if ( ! $tracking_entry ) {
				return;
			}

			// Skip open tracking for failed outbound emails (still send pixel to avoid broken images).
			if ( $this->is_failed_outbound( $tracking_entry ) ) {
				$this->send_tracking_pixel( true );
			}

			// Only the first open is recorded and announced (prevent duplicate notifications/lead scoring).
			self::record_open( $tracking_entry, 'pixel' );

			// Send the pixel (always, regardless of first/repeat open)
			$this->send_tracking_pixel();
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Email Opened Tracking Error', 'doublescale' ),
				array(
					'code'  => 'email_opened_tracking',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
			return;
		}
	}

	/**
	 * Email Clicked Tracking
	 * Handles tracking for all email types: Campaign, Automation, and Individual
	 */
	public function email_clicked_tracking() {
		try {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public click-tracking redirect; identity comes from the per-message hash_key validated below.
			if ( ! isset( $_GET['doublescale'] ) || $_GET['doublescale'] !== 'email_click' ) {
				return;
			}

			if ( ! isset( $_GET['hash_key'] ) || ! isset( $_GET['original'] ) ) {
				return;
			}

			$hash_key = sanitize_text_field( wp_unslash( $_GET['hash_key'] ) );
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			$tracking_entry = CommunicationTrackingModel::where( 'hash_key', $hash_key )
				->where( 'mode', CommunicationTrackingModel::MODE_EMAIL )
				->first();

			if ( ! $tracking_entry ) {
				return;
			}

			// Skip click tracking for failed outbound emails — redirect to original URL if valid, otherwise home.
			if ( $this->is_failed_outbound( $tracking_entry ) ) {
				// NOTE: esc_url_raw() is the sanitizer here, NOT sanitize_text_field().
				// sanitize_text_field() strips percent-encoded octets, which silently
				// corrupts legitimate destinations (e.g. %20 in a path, %25 in a query).
				// esc_url_raw() preserves them, and the https?:// check below is what
				// actually blocks javascript:/data: open redirects.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public click redirect; sanitized via esc_url_raw() and validated via preg_match below.
				$fallback = isset( $_GET['original'] ) ? esc_url_raw( urldecode( wp_unslash( $_GET['original'] ) ) ) : '';
				if ( empty( $fallback ) || ! preg_match( '#^https?://#i', $fallback ) ) {
					$fallback = home_url();
				}
				\doublescale_safe_redirect( $fallback );
			}

			// Capture state before update to avoid duplicate notifications/lead scoring.
			$already_clicked = (bool) $tracking_entry->clicked;

			// Update tracking status only on first click.
			if ( ! $already_clicked ) {
				$tracking_entry->update(
					array(
						'clicked'    => 1,
						'clicked_at' => current_time( 'mysql', true ),
					)
				);
			}

			// If email was clicked but not opened, the click is the open.
			self::record_open( $tracking_entry, 'click' );

			// Fire email clicked action only on first click.
			if ( ! $already_clicked ) {
				$contact = $tracking_entry->contact;
				if ( $contact ) {
					do_action( 'doublescale_mail_click', $contact );
				}
			}

			// Decode only; esc_url_raw() below is the sanitizer. sanitize_text_field()
			// must NOT be used on a URL — it strips percent-encoded octets and would
			// turn https://x/a%20b into https://x/ab.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public click-redirect handler; sanitized via esc_url_raw() and validated via preg_match below.
			$original_url = urldecode( wp_unslash( $_GET['original'] ) );

			// Handle broken unsubscribe merge tags (e.g., "unsubscribe_link}}" from unprocessed {{contact:unsubscribe_link}})
			if ( strpos( $original_url, 'unsubscribe_link' ) !== false || strpos( $original_url, '{{contact:' ) !== false ) {
				$contact = isset( $contact ) ? $contact : $tracking_entry->contact;
				if ( $contact ) {
					$unsubscribe_url = add_query_arg(
						array(
							'doublescale-unsubscribe' => '1',
							'id'                      => $contact->hash_id,
							'channel'                 => 'email',
						),
						home_url()
					);
					\doublescale_safe_redirect( $unsubscribe_url );
				}
			}

			// Validate URL scheme to prevent open redirect attacks.
			$original_url = esc_url_raw( $original_url );
			if ( empty( $original_url ) || ! preg_match( '#^https?://#i', $original_url ) ) {
				$original_url = home_url();
			}

			// Link triggers need track-id to identify the contact; carry it through when
			// the original URL was wrapped by generic email click tracking.
			if ( false !== strpos( $original_url, 'doublescale-link-trigger' ) ) {
				$original_url = add_query_arg(
					array(
						'track-id' => $hash_key,
					),
					$original_url
				);
			}

			\doublescale_safe_redirect( $original_url );
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Email Clicked Tracking Error', 'doublescale' ),
				array(
					'code'  => 'email_clicked_tracking',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
			return;
		}
	}
}
