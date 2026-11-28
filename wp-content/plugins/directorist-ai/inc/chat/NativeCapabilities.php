<?php
/**
 * Read-only handoffs to currently rendered native listing workflows.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat;

use DirectoristAIPro\Capabilities\Context;

/** Eligibility is separate from any native submission or transaction. */
final class NativeCapabilities {

	/**
	 * Describe native links after the caller admits the chat directory/listing.
	 *
	 * The caller owns the enabled-directory policy. Recheck public membership and
	 * execution identity here before consulting helpers which use WordPress state.
	 *
	 * @param Context $context Server-resolved request authority.
	 * @param int     $directory Admitted native directory ID.
	 * @param int     $listing_id Admitted native listing ID.
	 * @param string  $url Caller-resolved permalink fallback.
	 * @param array   $layout Native directory single-listing layout.
	 * @return array Native link entries; never transaction receipts.
	 */
	public static function actions( Context $context, int $directory, int $listing_id, string $url, array $layout ): array {
		$context->assert_execution_identity();
		$post = get_post( $listing_id );
		if ( 1 > $directory || 1 > $listing_id || ! defined( 'ATBDP_POST_TYPE' ) || ! defined( 'ATBDP_DIRECTORY_TYPE' ) || ! $post || ATBDP_POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || ! empty( $post->post_password ) || ! has_term( $directory, ATBDP_DIRECTORY_TYPE, $listing_id ) ) {
			return array();
		}
		$permalink = get_permalink( $listing_id );
		$url       = is_string( $permalink ) && '' !== $permalink ? $permalink : $url;
		$host      = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			return array();
		}
		$url     = explode( '#', $url, 2 )[0];
		$guest   = $context->actor()->is_guest();
		$actions = array();

		if ( self::section( $layout, 'review' ) && function_exists( 'directorist_is_review_enabled' ) && function_exists( 'directorist_can_current_user_review' ) && function_exists( 'directorist_can_guest_review' ) && directorist_is_review_enabled() ) {
			// A guest can follow the native login handoff; an ineligible user cannot write.
			if ( $guest || directorist_can_current_user_review( $listing_id ) ) {
				$actions[] = self::link( 'review', __( 'Write a review', 'directorist-ai' ), $url, $guest && ! directorist_can_guest_review() );
			}
		}
		if ( self::booking_item( $layout ) && self::loaded( 'directorist-booking/directorist-booking.php', 'Directorist_Booking', 'Directorist_Booking\\App\\Providers\\FormBuilder', 'directorist_single_item_template' ) && self::booking_enabled( $listing_id ) ) {
			$actions[] = self::link( 'booking', __( 'Request booking', 'directorist-ai' ), $url . '#form-booking', $guest && ! get_directorist_option( 'bdb_guest_booking' ) );
		}
		if ( self::section( $layout, 'claim_listing' ) && self::loaded( 'directorist-claim-listing/directorist-claim-listing.php', 'DCL_Base', 'Claim_Post_Type_Manager', 'directorist_single_section_template' ) && self::claim_enabled( $listing_id ) ) {
			$actions[] = self::link( 'claim', __( 'Claim this listing', 'directorist-ai' ), $url, $guest );
		}
		if ( self::section( $layout, 'live_chat' ) && self::loaded( 'directorist-live-chat/directorist-live-chat.php', 'Directorist_Live_Chat', 'DLC_Directory_Type_Manager', 'directorist_single_section_template' ) && function_exists( 'atbdp_check_live_chat_restriction' ) && get_directorist_option( 'enable_live_chat', 1 ) && ! atbdp_check_live_chat_restriction( $listing_id ) && self::plan_allows( $listing_id, '_fm_live_chat' ) && (int) $post->post_author !== $context->actor()->user_id() && get_userdata( (int) $post->post_author ) ) {
			$actions[] = self::link( 'live_chat', __( 'Chat with the listing owner', 'directorist-ai' ), $url, $guest );
		}
		return $actions;
	}

	/**
	 * Match an actual special section, rather than an unused widget declaration.
	 *
	 * @param array  $layout Native layout.
	 * @param string $widget Native section name.
	 * @return bool
	 */
	private static function section( array $layout, string $widget ): bool {
		foreach ( (array) ( $layout['groups'] ?? array() ) as $group ) {
			if ( is_array( $group ) && 'widget_group' === ( $group['type'] ?? '' ) && ( $group['widget_name'] ?? '' ) === $widget ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Booking renders only when a general section references its item.
	 *
	 * @param array $layout Native layout.
	 * @return bool
	 */
	private static function booking_item( array $layout ): bool {
		foreach ( (array) ( $layout['groups'] ?? array() ) as $group ) {
			if ( ! is_array( $group ) || 'general_group' !== ( $group['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $group['fields'] ?? array() ) as $key ) {
				$field = is_string( $key ) || is_int( $key ) ? ( $layout['fields'][ $key ] ?? null ) : null;
				if ( is_array( $field ) && 'booking' === ( $field['widget_name'] ?? '' ) && 'other_widgets' === ( $field['widget_group'] ?? '' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Require activation and the exact loaded native renderer without booting it.
	 *
	 * @param string $plugin Native plugin basename.
	 * @param string $base Native main class.
	 * @param string $renderer Native renderer class.
	 * @param string $hook Native layout renderer hook/method.
	 * @return bool
	 */
	private static function loaded( string $plugin, string $base, string $renderer, string $hook ): bool {
		if ( ! \DirectoristAIPro\Extensions\ExtensionRegistry::active_plugin( $plugin ) || ! class_exists( $base, false ) || ! class_exists( $renderer, false ) ) {
			return false;
		}
		global $wp_filter;
		foreach ( (array) ( $wp_filter[ $hook ]->callbacks ?? array() ) as $callbacks ) {
			foreach ( (array) $callbacks as $entry ) {
				$callback = $entry['function'] ?? null;
				if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && is_object( $callback[0] ) && is_a( $callback[0], $renderer ) && $hook === $callback[1] && is_callable( $callback ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Follow the installed fee manager's listing plan allowance.
	 *
	 * @param int    $listing_id Native listing ID.
	 * @param string $meta Native plan feature flag.
	 * @return bool
	 */
	private static function plan_allows( int $listing_id, string $meta ): bool {
		if ( ! function_exists( 'is_fee_manager_active' ) ) {
			return false;
		}
		if ( ! is_fee_manager_active() ) {
			return true;
		}
		$plan_id = (int) get_post_meta( $listing_id, '_fm_plans', true );
		return $plan_id > 0 && (bool) get_post_meta( $plan_id, $meta, true );
	}

	/**
	 * Check native booking visibility and a configured native workflow target.
	 *
	 * @param int $listing_id Native listing ID.
	 * @return bool
	 */
	private static function booking_enabled( int $listing_id ): bool {
		if ( ! function_exists( 'atbdp_check_booking_restriction' ) || ! function_exists( 'bdb_booking_confirmation_page' ) || ! get_directorist_option( 'enable_booking', 1 ) || get_post_meta( $listing_id, '_bdb_hide_booking', true ) || atbdp_check_booking_restriction( $listing_id ) || ! self::plan_allows( $listing_id, '_fm_booking' ) ) {
			return false;
		}
		$page_id = (int) get_directorist_option( 'booking_confirmation' );
		if ( 1 > $page_id ) {
			return false;
		}
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status || ! empty( $page->post_password ) || ! shortcode_exists( 'directorist_booking_confirmation' ) || ! has_shortcode( $page->post_content, 'directorist_booking_confirmation' ) ) {
			return false;
		}
		$type = get_post_meta( $listing_id, '_bdb_booking_type', true );
		if ( empty( $type ) || 'undefined' === $type ) {
			$type = get_directorist_option( 'booking_type', array( 'service', 'rent', 'booking' ) );
			$type = ! empty( $type ) ? $type : 'service';
		}
		if ( 'rent' === $type ) {
			// The native rental form manages availability; no free-place claim is made.
			return true;
		}
		if ( 'event' === $type ) {
			return (int) get_post_meta( $listing_id, '_bdb_event_ticket', true ) > (int) get_post_meta( $listing_id, '_event_tickets_sold', true ) && (int) get_post_meta( $listing_id, '_bdb_maximum_ticket_allowed', true ) > 0;
		}
		if ( 'service' !== $type ) {
			return false;
		}
		$mode = get_post_meta( $listing_id, '_bdb_slot_status', true );
		if ( ! in_array( $mode, array( '', 'time_slot', 'time_picker' ), true ) ) {
			return false;
		}
		foreach ( (array) get_post_meta( $listing_id, '_bdb', true ) as $day => $slots ) {
			if ( ! is_numeric( $day ) || (int) $day < 0 || (int) $day > 6 || ! is_array( $slots ) ) {
				continue;
			}
			foreach ( $slots as $slot ) {
				if ( is_array( $slot ) && ! empty( $slot['start'] ) && ! empty( $slot['close'] ) && ( 'time_slot' !== $mode || (int) ( $slot['slots'] ?? 0 ) > 0 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Follow the native section's claim guards, not its legacy sidebar switch.
	 *
	 * @param int $listing_id Native listing ID.
	 * @return bool
	 */
	private static function claim_enabled( int $listing_id ): bool {
		if ( ! get_directorist_option( 'enable_claim_listing', 1 ) || get_post_meta( $listing_id, '_claimed_by_admin', true ) || 'claim_approved' === get_post_meta( $listing_id, '_claim_fee', true ) ) {
			return false;
		}
		return ! get_directorist_option( 'admin_only_claimable', false ) || ( function_exists( 'directorist_is_listing_created_by_admin' ) && directorist_is_listing_created_by_admin( $listing_id ) );
	}

	/**
	 * Build a read-only handoff with an explicit native login requirement.
	 *
	 * @param string $id Known action identifier.
	 * @param string $label User-facing action label.
	 * @param string $url Native permalink or verified form anchor.
	 * @param bool   $requires_auth Native workflow requires login.
	 * @return array
	 */
	private static function link( string $id, string $label, string $url, bool $requires_auth ): array {
		return array(
			'id'            => $id,
			'label'         => $label,
			'kind'          => 'link',
			'url'           => $url,
			'requires_auth' => $requires_auth,
		);
	}
}
