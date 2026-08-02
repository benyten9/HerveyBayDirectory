<?php

/**
 * Template Data Preparer Service
 * Shared logic for preparing template data from various sources
 * Used by both CampaignModel and AutomationStepModel
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

/**
 * TemplateDataPreparer class
 */
class TemplateDataPreparer {



	/**
	 * Prepare template data from individual fields (email)
	 * Converts raw content fields into template data format expected by CampaignTemplateFactory
	 *
	 * @param string $subject Email subject
	 * @param string $body Email body
	 * @param array  $settings Optional settings (from_name, from_email, reply_to)
	 * @return array Template data array
	 */
	public static function prepare_email_template_data( $subject, $body, $settings = array() ) {
		// Get site name with fallback to domain if empty
		$site_name = $settings['from_name'] ?? get_bloginfo( 'name' );
		if ( empty( $site_name ) ) {
			$site_name = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'WordPress';
		}

		// Get admin email with fallback
		$admin_email = $settings['from_email'] ?? get_option( 'admin_email' );
		if ( empty( $admin_email ) ) {
			$admin_email = 'wordpress@' . ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost' );
		}

		return array(
			'name'     => $subject,
			'subject'  => $subject,
			'body'     => $body,
			'settings' => array(
				'from_name'       => $site_name,
				'from_email'      => $admin_email,
				'reply_to'        => $settings['reply_to'] ?? $settings['reply_to_email'] ?? $admin_email,
				'add_unsubscribe' => $settings['add_unsubscribe'] ?? true,
				'enable_utm'      => $settings['enable_utm'] ?? false,
			),
		);
	}

	/**
	 * Prepare template data from individual fields (Sms/Whatsapp)
	 *
	 * @param string $body Message body
	 * @param string $channel_type Channel type ('sms' or 'whatsapp')
	 * @return array Template data array
	 */
	public static function prepare_message_template_data( $body, $channel_type ) {
		$channel_label = ucfirst( $channel_type );
		return array(
			'name'     => "{$channel_label} - " . mb_substr( $body, 0, 30 ),
			'subject'  => '', // Sms/Whatsapp don't have subject
			'body'     => $body,
			'settings' => array(),
		);
	}

	/**
	 * Prepare template data from settings array
	 * Auto-detects channel type and prepares appropriate template data
	 *
	 * @param array  $settings Settings array with subject, body, etc.
	 * @param string $channel_type Channel type ('email', 'sms', 'whatsapp')
	 * @param string $name_prefix Optional prefix for template name (default: '')
	 * @return array|null Template data array or null if required fields missing
	 */
	public static function prepare_from_settings( $settings, $channel_type, $name_prefix = '' ) {
		// Email channel
		if ( $channel_type === 'email' ) {
			// Check for subject/body or email_body (sequences use email_body)
			$subject = $settings['subject'] ?? null;
			$body    = $settings['body'] ?? $settings['email_body'] ?? null;

			// If this is an existing template being reprocessed (has template_id), allow empty subject/body
			// This happens when attach_templates() adds templates and they get reprocessed during save
			$has_template_id = isset( $settings['template_ids'] ) && ! empty( $settings['template_ids'] );

			if ( ! $has_template_id && ( empty( $subject ) || empty( $body ) ) ) {
				return null;
			}

			$template_data         = self::prepare_email_template_data( $subject, $body, $settings );
			$template_data['name'] = $name_prefix . ( $subject ?: 'Untitled' );

			// Preserve template_id if it exists (for updates)
			if ( $has_template_id ) {
				$template_data['template_ids'] = $settings['template_ids'];
			}

			return $template_data;
		}

		// Sms or WhatsApp channel
		if ( $channel_type === 'sms' || $channel_type === 'whatsapp' ) {
			$body = $settings['body'] ?? null;

			if ( empty( $body ) ) {
				return null;
			}

			$template_data         = self::prepare_message_template_data( $body, $channel_type );
			$template_data['name'] = $name_prefix . $template_data['name'];

			return $template_data;
		}

		return null;
	}

	/**
	 * Check if settings contain raw template fields (not yet processed)
	 * Used to determine if template preparation is needed
	 *
	 * @param array  $settings Settings array
	 * @param string $channel_type Channel type ('email', 'sms', 'whatsapp')
	 * @return bool True if raw fields exist and need processing
	 */
	public static function has_raw_template_fields( $settings, $channel_type ) {
		if ( $channel_type === 'email' ) {
			// Check for subject and body (or email_body for sequences)
			return isset( $settings['subject'] ) &&
				( isset( $settings['body'] ) || isset( $settings['email_body'] ) );
		}

		if ( $channel_type === 'sms' || $channel_type === 'whatsapp' ) {
			return isset( $settings['body'] );
		}

		return false;
	}

	/**
	 * Prepare template data for CampaignModel (handles sequences and campaigns)
	 * Supports both parent campaigns and child sequence emails
	 *
	 * @param array  $data Campaign or sequence data (can be from REST request or model)
	 * @param string $channel_type Channel type
	 * @return array|null Template data array or null if not applicable
	 */
	public static function prepare_for_campaign( $data, $channel_type ) {
		// If this is a sequence email (has parent_id), prepare template with parent settings
		if ( isset( $data['parent_id'] ) && $data['parent_id'] ) {
			// Need to get parent sequence settings for email metadata
			if ( $channel_type === 'email' ) {
				// Load parent to get from_name, from_email, reply_to
				$parent_campaign = \DoubleScale\Modules\Campaigns\Models\CampaignModel::find( $data['parent_id'] );
				if ( $parent_campaign && isset( $parent_campaign->settings ) ) {
					$site_name   = get_option( 'blogname', '' );
					$admin_email = get_option( 'admin_email' );

					// Fallback just in case site title is empty
					if ( empty( $site_name ) ) {
						$site_name = wp_parse_url( home_url(), PHP_URL_HOST );
					}

					$merged_settings = array_merge(
						array(
							'from_name'       => $parent_campaign->settings['from_name'] ?? $site_name,
							'from_email'      => $parent_campaign->settings['from_email'] ?? $admin_email,
							'reply_to'        => $parent_campaign->settings['reply_to_email'] ?? $admin_email,
							'add_unsubscribe' => true,
							'enable_utm'      => false,
						),
						$data
					);

					return self::prepare_from_settings( $merged_settings, $channel_type );
				}
			}

			// Fallback for non-email or if parent not found
			return self::prepare_from_settings( $data, $channel_type );
		}

		// If this is a parent campaign with raw fields in settings
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			if ( self::has_raw_template_fields( $data['settings'], $channel_type ) ) {
				return self::prepare_from_settings( $data['settings'], $channel_type );
			}
		}

		return null;
	}
}
