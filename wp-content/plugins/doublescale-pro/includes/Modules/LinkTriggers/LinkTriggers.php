<?php
/**
 * Tracked-link redirect handler.
 *
 * Looks up the link-trigger by hash on every front-end request that carries the
 * `doublescale-link-trigger` query var, increments click count, marks the
 * associated communication tracking row as opened/clicked, syncs tags/lists on
 * the contact, fires `doublescale_link_trigger_clicked`, and redirects.
 *
 * Moved from free → Pro to remove `wp_set_auth_cookie` / `wp_set_current_user`
 * from the WordPress.org-distributed plugin. Auto-login from a tracked link is
 * intentionally NOT reintroduced here.
 *
 * @package DoubleScale\Pro\Modules\LinkTriggers
 */

namespace DoubleScale\Pro\Modules\LinkTriggers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\LinkTriggers\Models\LinkTriggerModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

class LinkTriggers {

	private static $instance;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// After `init`: Action Scheduler is not initialized at `plugins_loaded`
		// (`doublescale_ready`), so enqueue_async() would store task meta with a
		// NULL action_id and the automation would never run.
		add_action( 'template_redirect', array( $this, 'link_trigger_tracking' ), 1 );
	}

	public function link_trigger_tracking() {

		$redirect_url = '';
		try {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public link-trigger redirect; identity comes from the per-trigger hash + per-message track-id, both validated against the DB below.
			if ( ! isset( $_GET['doublescale-link-trigger'] ) ) {
				return;
			}
			$hash = sanitize_text_field( wp_unslash( $_GET['doublescale-link-trigger'] ) );
			if ( empty( $hash ) ) {
				return;
			}

			$link_trigger = LinkTriggerModel::where( 'hash', $hash )->where( 'status', 'active' )->first();
			if ( ! $link_trigger ) {
				return;
			}

			$redirect_url = $link_trigger->get_setting( 'redirect_url', home_url() );

			$link_trigger->click_count = $link_trigger->click_count + 1;
			$link_trigger->save();

			$track_id = isset( $_GET['track-id'] ) ? sanitize_text_field( wp_unslash( $_GET['track-id'] ) ) : '';
			if ( '' === $track_id && isset( $_GET['hash_key'] ) ) {
				$track_id = sanitize_text_field( wp_unslash( $_GET['hash_key'] ) );
			}

			$campaign_email = $this->find_email_tracking_row( $track_id );
			if ( $campaign_email ) {
				$campaign_email->update(
					array(
						'clicked'    => 1,
						'clicked_at' => current_time( 'mysql', true ),
					)
				);

				if ( ! $campaign_email->opened ) {
					$campaign_email->update(
						array(
							'opened'    => 1,
							'opened_at' => current_time( 'mysql', true ),
						)
					);
				}
			}

			$contact = $campaign_email ? $campaign_email->contact : null;
			// Only fall back to the logged-in user when the URL has no track-id
			// (copied/preview links). A present but unknown track-id must not
			// attribute the click to whoever happens to be logged in.
			if ( ! $contact && '' === $track_id ) {
				$contact = $this->resolve_logged_in_contact();
			}

			if ( $contact ) {
				$this->sync_contact_data( $link_trigger, $contact );
				do_action( 'doublescale_link_trigger_clicked', $link_trigger, $contact );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				__( 'Link Trigger Tracking Error', 'doublescale' ),
				array(
					'code'  => 'link_trigger_tracking',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
		}

		if ( '' !== $redirect_url ) {
			\doublescale_safe_redirect( $redirect_url );
		}
	}

	/**
	 * Look up the per-message email tracking row used to identify the contact.
	 *
	 * @param string $track_id Communication tracking hash_key.
	 * @return CommunicationTrackingModel|null
	 */
	protected function find_email_tracking_row( $track_id ) {
		if ( ! is_string( $track_id ) || '' === $track_id ) {
			return null;
		}

		return CommunicationTrackingModel::where( 'hash_key', $track_id )
			->where( 'mode', CommunicationTrackingModel::MODE_EMAIL )
			->first();
	}

	/**
	 * Identify the clicker from the current WP session when the URL has no track-id.
	 *
	 * Copied link-trigger URLs (sites, SMS, tests) do not carry a per-email
	 * hash. Without this fallback tags/automations only ran from tracked emails.
	 *
	 * @return ContactModel|null
	 */
	protected function resolve_logged_in_contact() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID || empty( $user->user_email ) ) {
			return null;
		}

		// Contacts are keyed by email; the contacts table has no user_id column.
		return ContactModel::get_by_email( $user->user_email );
	}

	public function sync_contact_data( LinkTriggerModel $link_trigger, $contact ) {
		$to_apply_tags = $link_trigger->get_setting( 'add_tags', array() );
		if ( ! empty( $to_apply_tags ) ) {
			$contact->add_tags( $to_apply_tags );
		}

		$to_remove_tags = $link_trigger->get_setting( 'remove_tags', array() );
		if ( ! empty( $to_remove_tags ) ) {
			$contact->tags()->detach( $to_remove_tags );
			do_action( 'doublescale_contact_tag_remove', $contact, $to_remove_tags );
		}

		$to_apply_lists = $link_trigger->get_setting( 'add_lists', array() );
		if ( ! empty( $to_apply_lists ) ) {
			$contact->add_lists( $to_apply_lists );
		}

		$to_remove_lists = $link_trigger->get_setting( 'remove_lists', array() );
		if ( ! empty( $to_remove_lists ) ) {
			$contact->lists()->detach( $to_remove_lists );
			do_action( 'doublescale_contact_list_remove', $contact, $to_remove_lists );
		}
	}
}
