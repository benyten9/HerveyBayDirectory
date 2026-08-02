<?php
/**
 * Email Tracking Helper
 * Shared utilities for email tracking (open/click) and footer generation
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Core\Settings\Settings;

/**
 * EmailTrackingHelper class
 */
class EmailTrackingHelper {

	/**
	 * Add only tracking pixel (for individual messages without footer/unsubscribe)
	 *
	 * @since 1.0.0
	 *
	 * @param string                     $body Email body.
	 * @param CommunicationTrackingModel $tracking_entry Tracking entry.
	 * @return string Email with tracking pixel only
	 */
	public static function add_tracking_pixel( $body, CommunicationTrackingModel $tracking_entry ) {
		$tracking_pixel = sprintf(
			'<img src="%s" width="1" height="1" style="width:1px;height:1px;" alt="" />',
			home_url( '?doublescale=email_open&hash_key=' . $tracking_entry->hash_key )
		);

		return $body . $tracking_pixel;
	}

	/**
	 * Add tracking pixel and footer to email body
	 *
	 * @param string                     $body Email body.
	 * @param CommunicationTrackingModel $tracking_entry Tracking entry.
	 * @param ContactModel               $contact Contact model.
	 * @param array                      $settings Optional email settings.
	 * @return string Complete email with footer
	 */
	public static function add_footer_and_tracking( $body, CommunicationTrackingModel $tracking_entry, ContactModel $contact, $settings = array() ) {
		// Add tracking pixel
		$body_with_tracking = self::add_tracking_pixel( $body, $tracking_entry );

		// Check if unsubscribe link already exists in body
		if ( self::has_unsubscribe_link( $body_with_tracking ) ) {
			// Unsubscribe link already present, no need to add footer
			return $body_with_tracking;
		}

		// Get email footer
		if ( ! empty( $settings['email_footer'] ) ) {
			$email_footer = $settings['email_footer'];
		} else {
			$global_settings = Settings::get( 'email', array() );
			$email_footer    = $global_settings['email_footer'] ?? self::get_default_footer();
		}

		// Process merge tags in footer (for unsubscribe link)
		$email_footer = MergeTagsManager::instance()->process_merge_tags( $email_footer, $contact );

		// Append footer to body
		return $body_with_tracking . $email_footer;
	}

	/**
	 * Add click tracking to all links in email
	 *
	 * @param string        $message Email message.
	 * @param string        $hash_key Tracking hash key.
	 * @param ContactModel  $contact Contact model.
	 * @param TemplateModel $template Optional template for UTM parameters.
	 * @return string Email with tracked links
	 */
	public static function add_click_tracking( $message, $hash_key, ContactModel $contact, $template = null ) {
		// Match all links
		preg_match_all( '/<a[^>]+href=([\'"])(?<href>.+?)\1[^>]*>/i', $message, $matches );

		if ( ! isset( $matches['href'] ) ) {
			return $message;
		}

		foreach ( $matches['href'] as $key => $href ) {
			if ( false !== strpos( $href, 'doublescale-link-trigger' ) ) {
				// Get query string
				$query_string = wp_parse_url( $href, PHP_URL_QUERY );
				parse_str( $query_string, $query_args );

				// Get link trigger hash
				$hash = $query_args['doublescale-link-trigger'] ?? '';

				// Link triggers ship with the Pro add-on; if Pro is not active
				// the class is absent and we skip the per-link rewrite.
				$link_trigger_class = '\DoubleScale\Pro\Modules\LinkTriggers\Models\LinkTriggerModel';
				$link_trigger       = null;

				if ( class_exists( $link_trigger_class ) ) {
					$link_trigger = $link_trigger_class::where( 'hash', $hash )->first();
				}

				if ( ! $link_trigger ) {
					continue;
				}

				$link_trigger_url = self::configure_link_trigger_url( $link_trigger, $contact, $hash_key );

				// Replace original link with click tracking link
				$to_replace = $matches[0][ $key ];
				$message    = str_replace( $to_replace, str_replace( $href, $link_trigger_url, $to_replace ), $message );
				continue;
			}

			// Skip if already a tracking URL or unsubscribe link (processed or unprocessed merge tag)
			if ( strpos( $href, 'doublescale=' ) !== false || strpos( $href, 'doublescale-unsubscribe' ) !== false || strpos( $href, '{{contact:unsubscribe_link}}' ) !== false ) {
				continue;
			}

			// Add UTM parameters to the original URL if enabled
			$original_url = $href;
			if ( $template && $template->get_setting( 'enable_utm', false ) ) {
				$original_url = self::add_utm_parameters( $original_url, $template );
			}

			// Add click tracking to original link
			$click_url = add_query_arg(
				array(
					'doublescale' => 'email_click',
					'hash_key'    => $hash_key,
					'original'    => urlencode( $original_url ),
				),
				home_url()
			);

			// Replace original link with click tracking link
			$to_replace = $matches[0][ $key ];
			$message    = str_replace( $to_replace, str_replace( $href, $click_url, $to_replace ), $message );
		}

		return $message;
	}

	/**
	 * Get default email footer
	 *
	 * @return string
	 */
	public static function get_default_footer() {
		return Settings::get_default_email_footer();
	}

	/**
	 * Check if unsubscribe link already exists in email body
	 *
	 * NOTE: This method is called AFTER merge tags have been processed in prepare_message_content().
	 * Therefore, we check for actual unsubscribe URLs, not the {{contact:unsubscribe_link}} merge tag.
	 *
	 * Detects unsubscribe URLs in two formats:
	 * 1. doublescale=email_unsubscribe (used in List-Unsubscribe headers and direct URLs)
	 * 2. doublescale-unsubscribe (used by {{contact:unsubscribe_link}} merge tag processor)
	 *
	 * @since 1.0.0
	 *
	 * @param string $body Email body to check (with merge tags already processed).
	 * @return bool True if unsubscribe link exists, false otherwise
	 */
	public static function has_unsubscribe_link( $body ) {
		// Check for List-Unsubscribe header URL format
		if ( false !== strpos( $body, 'doublescale=email_unsubscribe' ) ) {
			return true;
		}

		// Check for merge tag processed URL format ({{contact:unsubscribe_link}} becomes this)
		if ( false !== strpos( $body, 'doublescale-unsubscribe' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Add UTM parameters to URL
	 *
	 * @param string        $url Original URL.
	 * @param TemplateModel $template Template with UTM settings.
	 * @return string URL with UTM parameters
	 */
	protected static function add_utm_parameters( $url, TemplateModel $template ) {
		$utm_params = array();

		if ( $template->get_setting( 'utm_source' ) ) {
			$utm_params['utm_source'] = $template->get_setting( 'utm_source' );
		}
		if ( $template->get_setting( 'utm_medium' ) ) {
			$utm_params['utm_medium'] = $template->get_setting( 'utm_medium' );
		}
		if ( $template->get_setting( 'utm_campaign' ) ) {
			$utm_params['utm_campaign'] = $template->get_setting( 'utm_campaign' );
		}
		if ( $template->get_setting( 'utm_term' ) ) {
			$utm_params['utm_term'] = $template->get_setting( 'utm_term' );
		}
		if ( $template->get_setting( 'utm_content' ) ) {
			$utm_params['utm_content'] = $template->get_setting( 'utm_content' );
		}

		if ( empty( $utm_params ) ) {
			return $url;
		}

		return add_query_arg( $utm_params, $url );
	}

	/**
	 * Configure link trigger URL with tracking parameters and optional auto-login
	 *
	 * @param object       $link_trigger Link trigger model.
	 * @param ContactModel $contact      Contact model.
	 * @param string       $hash_key     Tracking hash key.
	 * @return string Configured link trigger URL
	 */
	protected static function configure_link_trigger_url( $link_trigger, ContactModel $contact, $hash_key ) {
		$auto_login    = $link_trigger->get_setting( 'auto_login', true );
		$contact_email = $contact->email;
		$user          = get_user_by( 'email', $contact_email );
		$args          = array(
			'doublescale-link-trigger' => $link_trigger->hash,
			'track-id'                 => $hash_key,
		);

		if ( $auto_login && $user ) {
			$args['auth-id'] = wp_hash_password( $contact_email );
		}

		$link_trigger_url = add_query_arg( $args, home_url() );

		return $link_trigger_url;
	}
}
