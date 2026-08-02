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
		add_action( 'doublescale_ready', array( $this, 'init' ) );
	}

	public function init() {
		$this->link_trigger_tracking();
	}

	public function link_trigger_tracking() {
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

			$track_id       = isset( $_GET['track-id'] ) ? sanitize_text_field( wp_unslash( $_GET['track-id'] ) ) : '';
			$campaign_email = CommunicationTrackingModel::where( 'hash_key', $track_id )
				->where( 'mode', CommunicationTrackingModel::MODE_EMAIL )
				->first();
			if ( ! $campaign_email ) {
				\doublescale_safe_redirect( $redirect_url );
			}

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

			$contact = $campaign_email->contact;

			if ( $contact ) {
				$this->sync_contact_data( $link_trigger, $contact );
				do_action( 'doublescale_link_trigger_clicked', $link_trigger, $contact );
			}

			\doublescale_safe_redirect( $redirect_url );
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		} catch ( \Exception $e ) {
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
