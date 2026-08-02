<?php
/**
 * Notification Email Sender
 * Sends notification emails to WordPress users with site-wide rate limiting
 *
 * @since 1.2.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications\Services;

use DoubleScale\Modules\Emails\Emails;

/**
 * NotificationEmailSender class
 *
 * Handles sending notification emails to WordPress users (not CRM contacts).
 * Uses the simple Emails class (wp_mail wrapper) since these are admin notifications.
 * Implements site-wide rate limiting following CampaignRateLimiter pattern.
 *
 * @since 1.2.0
 */
class NotificationEmailSender {

	/**
	 * Default daily email limit (site-wide)
	 *
	 * @var int
	 */
	const DAILY_LIMIT = 1000;

	/**
	 * Option name for daily count
	 *
	 * @var string
	 */
	const OPTION_COUNT = 'doublescale_daily_notification_email_count';

	/**
	 * Get the configured daily limit
	 *
	 * Applies filter once and caches the result for the request.
	 *
	 * @since 1.2.0
	 *
	 * @return int Daily email limit
	 */
	private static function get_daily_limit() {
		static $limit = null;

		if ( null === $limit ) {
			/**
			 * Filter the daily notification email limit
			 *
			 * @since 1.2.0
			 *
			 * @param int $limit Default limit (1000)
			 */
			$limit = (int) apply_filters( 'doublescale_notification_email_daily_limit', self::DAILY_LIMIT );
		}

		return $limit;
	}

	/**
	 * Send notification email directly to a user
	 *
	 * Used when email is enabled for a notification category.
	 * Does not require a bell notification to be created first.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $title    Notification title.
	 * @param string $message  Notification message.
	 * @param string $link     Optional link to related item.
	 * @param string $category Notification category.
	 * @return bool True on success, false on failure
	 */
	public static function send_direct( $user_id, $title, $message, $link = null, $category = 'system' ) {
		// Check site-wide daily limit.
		if ( self::get_daily_count() >= self::get_daily_limit() ) {
			return false;
		}

		// Get user data.
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email ) {
			return false;
		}

		// Validate email address.
		if ( ! is_email( $user->user_email ) ) {
			return false;
		}

		// Build and send email.
		$emails  = new Emails();
		$subject = self::format_subject( $title );
		$body    = self::build_email_body( $title, $message, $link, $category );

		$result = $emails->send( $user->user_email, $subject, $body );

		// Increment count only on success.
		if ( $result ) {
			self::increment_daily_count();
		}

		return $result;
	}

	/**
	 * Get current daily email count
	 *
	 * Same pattern as CampaignRateLimiter::get_daily_count().
	 *
	 * @since 1.2.0
	 *
	 * @return int Current daily count
	 */
	public static function get_daily_count() {
		return (int) get_option( self::OPTION_COUNT, 0 );
	}

	/**
	 * Increment daily email count
	 *
	 * Uses atomic increment to prevent race conditions.
	 * Same pattern as CampaignRateLimiter::increment_daily_count().
	 *
	 * @since 1.2.0
	 *
	 * @return int New count after increment
	 */
	public static function increment_daily_count() {
		global $wpdb;

		// Atomic increment to prevent race conditions.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				VALUES (%s, 1, 'no')
				ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				self::OPTION_COUNT
			)
		);

		// Clear cache to ensure fresh read.
		wp_cache_delete( self::OPTION_COUNT, 'options' );

		return self::get_daily_count();
	}

	/**
	 * Reset daily email count
	 *
	 * Called by daily cron (doublescale_daily4).
	 *
	 * @since 1.2.0
	 *
	 * @return bool True on success
	 */
	public static function reset_daily_count() {
		return update_option( self::OPTION_COUNT, 0, false );
	}

	/**
	 * Check if daily limit is reached
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if limit reached
	 */
	public static function is_limit_reached() {
		return self::get_daily_count() >= self::get_daily_limit();
	}

	/**
	 * Get remaining email quota for today
	 *
	 * @since 1.2.0
	 *
	 * @return int Remaining emails that can be sent
	 */
	public static function get_remaining_quota() {
		return max( 0, self::get_daily_limit() - self::get_daily_count() );
	}

	/**
	 * Format email subject
	 *
	 * @since 1.2.0
	 *
	 * @param string $title Notification title.
	 * @return string Formatted subject
	 */
	private static function format_subject( $title ) {
		$site_name = get_bloginfo( 'name' );
		return $title . ' - ' . $site_name;
	}

	/**
	 * Get color for notification category
	 *
	 * Returns a color hex code for the category's border/accent in emails.
	 * Provides visual differentiation between notification types.
	 *
	 * @since 1.2.0
	 *
	 * @param string $category Notification category.
	 * @return string Hex color code
	 */
	private static function get_category_color( $category ) {
		$colors = array(
			'campaigns'      => '#2271b1', // Blue.
			'automations'    => '#d63638', // Red (errors).
			'contacts'       => '#00a32a', // Green.
			'pipeline'       => '#9b59b6', // Purple.
			'tasks'          => '#f0b849', // Orange.
			'email_tracking' => '#e65100', // Deep orange.
			'forms'          => '#00796b', // Teal.
			'integrations'   => '#5c6bc0', // Indigo.
			'system'         => '#1d2327', // Dark.
		);

		return $colors[ $category ] ?? '#0073aa'; // Default brand blue.
	}

	/**
	 * Build email body HTML
	 *
	 * @since 1.2.0
	 *
	 * @param string      $title    Notification title.
	 * @param string      $message  Notification message.
	 * @param string|null $link     Optional link.
	 * @param string      $category Notification category for color differentiation.
	 * @return string HTML email body
	 */
	private static function build_email_body( $title, $message, $link = null, $category = 'system' ) {
		$border_color = self::get_category_color( $category );

		$html = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen-Sans, Ubuntu, Cantarell, \'Helvetica Neue\', sans-serif; max-width: 600px; margin: 0 auto;">';

		// Header with colored border.
		$html .= '<div style="border-left: 4px solid ' . esc_attr( $border_color ) . '; padding-left: 16px; margin-bottom: 20px;">';
		$html .= '<h2 style="color: #1d2327; font-size: 20px; font-weight: 600; margin: 0 0 8px 0;">' . esc_html( $title ) . '</h2>';
		$html .= '</div>';

		// Message.
		$html .= '<div style="color: #50575e; font-size: 14px; line-height: 1.6; margin-bottom: 20px;">';
		$html .= '<p style="margin: 0;">' . esc_html( $message ) . '</p>';
		$html .= '</div>';

		// Link button (if provided).
		if ( ! empty( $link ) ) {
			$html .= '<div style="margin-bottom: 24px;">';
			$html .= '<a href="' . esc_url( $link ) . '" style="display: inline-block; background-color: #2271b1; color: #ffffff; text-decoration: none; padding: 10px 20px; border-radius: 4px; font-size: 14px; font-weight: 500;">' . esc_html__( 'View Details', 'doublescale' ) . '</a>';
			$html .= '</div>';
		}

		// Footer.
		$html           .= '<hr style="border: none; border-top: 1px solid #dcdcde; margin: 24px 0;">';
		$html           .= '<p style="color: #787c82; font-size: 12px; margin: 0;">';
		$html           .= esc_html__( 'You received this notification from Plugin.', 'doublescale' ) . ' ';
		$preferences_url = admin_url( 'admin.php?page=doublescale&path=settings&tab=notifications' );
		$html           .= '<a href="' . esc_url( $preferences_url ) . '" style="color: #2271b1;">' . esc_html__( 'Manage preferences', 'doublescale' ) . '</a>';
		$html           .= '</p>';

		$html .= '</div>';

		return $html;
	}
}
