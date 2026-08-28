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

use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Utils\Utils;
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
			$href = html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			if ( false !== strpos( $href, 'doublescale-link-trigger' ) ) {
				// Get query string
				$query_string = wp_parse_url( $href, PHP_URL_QUERY );
				parse_str( (string) $query_string, $query_args );

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
				$message    = str_replace( $to_replace, str_replace( $matches['href'][ $key ], $link_trigger_url, $to_replace ), $message );
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

	/**
	 * Append a per-recipient track-id placeholder to Link Trigger URLs in shared HTML.
	 *
	 * Bulk / curl-multi campaigns send one HTML body to many recipients, so the
	 * concrete hash_key cannot be baked in at render time. Inject
	 * `track-id={{tracking:hash_key}}` instead; mailers substitute it from
	 * recipient_variables (same pattern as the open-tracking pixel).
	 *
	 * Skips URLs that already carry track-id or hash_key. Does not urlencode the
	 * placeholder braces — add_query_arg would break merge-tag substitution.
	 *
	 * @since 1.3.3
	 *
	 * @param string $html Email HTML body.
	 * @return string HTML with track-id placeholders on link-trigger hrefs.
	 */
	public static function inject_link_trigger_track_id_placeholder( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === strpos( $html, 'doublescale-link-trigger' ) ) {
			return $html;
		}

		return preg_replace_callback(
			'/<a\b[^>]*\bhref=([\'"])(?<href>[^\'"]*doublescale-link-trigger[^\'"]*)\1[^>]*>/i',
			static function ( $matches ) {
				$href = html_entity_decode( $matches['href'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				if ( false !== strpos( $href, 'track-id=' ) || false !== strpos( $href, 'hash_key=' ) ) {
					return $matches[0];
				}

				$separator = false === strpos( $href, '?' ) ? '?' : '&';
				$updated   = $href . $separator . 'track-id={{tracking:hash_key}}';

				return str_replace( $matches['href'], $updated, $matches[0] );
			},
			$html
		);
	}

	/**
	 * Wrap ordinary links in click tracking for bulk / curl-multi sends.
	 *
	 * Bulk campaigns render ONE HTML body for many recipients, so the concrete
	 * hash_key cannot be baked in. We emit `hash_key={{tracking:hash_key}}` and let
	 * each mailer's convert_merge_tags() rewrite it to that provider's
	 * recipient-variable syntax (%recipient.hash_key%, {{hash_key}}, {hash_key}, …),
	 * exactly like the open-tracking pixel already does.
	 *
	 * Mirrors the skip rules of add_click_tracking(): link triggers (handled by
	 * inject_link_trigger_track_id_placeholder()), already-tracked URLs, unsubscribe
	 * links, and unprocessed merge-tag hrefs are all left alone.
	 *
	 * The tracker URL is assembled by concatenation rather than add_query_arg(),
	 * because add_query_arg() percent-encodes the placeholder braces to %7B%7B and
	 * the mailers' merge-tag regex would then never match it.
	 *
	 * @since 1.3.4
	 *
	 * @param string             $html     Shared email HTML body.
	 * @param TemplateModel|null $template Optional template, for UTM parameters.
	 * @return string HTML with ordinary links wrapped in click tracking.
	 */
	public static function inject_bulk_click_tracking( $html, $template = null ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<a' ) ) {
			return $html;
		}

		$base = home_url();

		return preg_replace_callback(
			'/<a\b[^>]*\bhref=([\'"])(?<href>[^\'"]*)\1[^>]*>/i',
			static function ( $matches ) use ( $base, $template ) {
				$href = html_entity_decode( $matches['href'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				// Link triggers get their own track-id placeholder elsewhere.
				if ( false !== strpos( $href, 'doublescale-link-trigger' ) ) {
					return $matches[0];
				}

				// Already tracked, unsubscribe, or an unexpanded merge tag.
				if ( false !== strpos( $href, 'doublescale=' )
					|| false !== strpos( $href, 'doublescale-unsubscribe' )
					|| false !== strpos( $href, '{{' ) ) {
					return $matches[0];
				}

				// Only http(s) destinations are trackable — skip mailto:, tel:, #anchors.
				if ( ! preg_match( '#^https?://#i', $href ) ) {
					return $matches[0];
				}

				$original_url = $href;
				if ( $template && $template->get_setting( 'enable_utm', false ) ) {
					$original_url = self::add_utm_parameters( $original_url, $template );
				}

				// Double-encode to match the non-bulk path: add_click_tracking() urlencodes
				// the destination and add_query_arg() encodes the whole arg again. The
				// click handler mirrors that (PHP decodes $_GET once, then it urldecodes
				// once more), so a single encode here would corrupt destinations that
				// contain literal percent-escapes (e.g. %20) or "+".
				$separator = false === strpos( $base, '?' ) ? '?' : '&';
				$click_url = $base . $separator
					. 'doublescale=email_click'
					. '&hash_key={{tracking:hash_key}}'
					. '&original=' . urlencode( urlencode( $original_url ) );

				return str_replace( $matches['href'], $click_url, $matches[0] );
			},
			$html
		);
	}

	/**
	 * Tracking vars every bulk/curl-multi recipient needs for open + link-trigger clicks.
	 *
	 * @since 1.3.3
	 *
	 * @param CommunicationTrackingModel $tracking Per-recipient tracking row.
	 * @return array{hash_key: string, tracking_pixel: string, unsubscribe_url: string}
	 */
	public static function bulk_tracking_recipient_variables( CommunicationTrackingModel $tracking ) {
		return array(
			'hash_key'         => $tracking->hash_key,
			'tracking_pixel'   => home_url( '?doublescale=email_open&hash_key=' . $tracking->hash_key ),
			'unsubscribe_url'  => add_query_arg(
				array(
					'doublescale' => 'email_unsubscribe',
					'hash_key'    => $tracking->hash_key,
				),
				home_url()
			),
		);
	}

	/**
	 * Apply per-recipient open/click tracking to a test email body.
	 *
	 * Test sends previously shipped link triggers without a track-id, so clicks
	 * fell back to the logged-in WP user instead of the intended recipient.
	 *
	 * @since 1.3.5
	 *
	 * @param string           $body            Rendered HTML body.
	 * @param string           $recipient_email Test recipient address.
	 * @param ContactModel|null $contact        Optional known contact for merge-tag context.
	 * @return string Body with tracking pixel and tracked links when applicable.
	 */
	public static function prepare_test_email_body( $body, $recipient_email, $contact = null ) {
		if ( ! is_string( $body ) || '' === $body ) {
			return $body;
		}

		$has_trackable_links = false !== stripos( $body, '<a' );
		if ( ! $has_trackable_links ) {
			return $body;
		}

		if ( ! $contact instanceof ContactModel ) {
			$contact = ContactModel::get_by_email( $recipient_email );
		}

		if ( ! $contact && false !== strpos( $body, 'doublescale-link-trigger' ) ) {
			$contact = self::resolve_test_recipient_contact( $recipient_email );
		}

		if ( ! $contact ) {
			return $body;
		}

		$tracking_entry = CommunicationTrackingModel::create(
			array(
				'contact_id'  => $contact->id,
				'template_id' => null,
				'hash_key'    => Utils::generate_hash_key(),
				'mode'        => CommunicationTrackingModel::MODE_EMAIL,
				'direction'   => MessageDirection::OUTBOUND,
				'source_type' => MessageSourceTypes::INDIVIDUAL,
				'source_id'   => null,
				'author_id'   => get_current_user_id(),
				'recipient'   => $recipient_email,
				'status'      => TrackingStatus::PENDING,
			)
		);

		$body = self::add_tracking_pixel( $body, $tracking_entry );
		$body = self::add_click_tracking( $body, $tracking_entry->hash_key, $contact );

		$tracking_entry->update(
			array(
				'status'  => TrackingStatus::SENT,
				'sent_at' => current_time( 'mysql', true ),
			)
		);

		return $body;
	}

	/**
	 * Ensure a CRM contact exists for a test-email recipient.
	 *
	 * @param string $recipient_email Recipient email address.
	 * @return ContactModel|null
	 */
	protected static function resolve_test_recipient_contact( $recipient_email ) {
		$email = strtolower( trim( (string) $recipient_email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}

		$existing = ContactModel::get_by_email( $email );
		if ( $existing ) {
			return $existing;
		}

		$wp_user    = get_user_by( 'email', $email );
		$first_name = ( $wp_user && ! empty( $wp_user->first_name ) ) ? $wp_user->first_name : null;
		$last_name  = ( $wp_user && ! empty( $wp_user->last_name ) ) ? $wp_user->last_name : null;

		try {
			return ContactModel::create(
				array(
					'email'      => $email,
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'source'     => 'test_email',
				)
			);
		} catch ( \Illuminate\Database\QueryException $e ) {
			return ContactModel::get_by_email( $email );
		}
	}
}
