<?php
/**
 * Public presentation metadata for an already verified visitor answer.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat;

/** Display metadata is unsigned and cannot grant listing or action authority. */
final class VisitorDisplayContext {
	/**
	 * Decorate only identities retained by the existing grounded history verifier.
	 *
	 * The caller supplies the exact owned run's original site snapshot. Never read
	 * today's currency to label an old amount, or use requested IDs without cells.
	 *
	 * @param object      $response Verified current or qualified dated response.
	 * @param object|null $site Original owned run's visitor site snapshot.
	 * @param array       $unit_fields Revision-checked public unit field per directory.
	 * @return object Bounded display-only currency and public listing identities.
	 */
	public static function from_verified( object $response, ?object $site, array $unit_fields = array() ): object {
		$display = (object) array( 'listings' => array() );
		if ( is_string( $site->currency ?? null ) && preg_match( '/^[A-Z]{3}$/D', $site->currency ) ) {
			$display->currency = $site->currency;
		}
		if ( 'directorist-ai/grounded-response-v1' !== ( $response->contract ?? null ) || ! in_array( $response->status ?? null, array( 'results', 'unknown' ), true ) || 'listing' !== ( $response->task->family ?? null ) || 'public' !== ( $response->task->dataset ?? null ) ) {
			return $display;
		}
		$candidates = array();
		$units      = array();
		if ( 'search' === ( $response->task->kind ?? null ) ) {
			foreach ( is_array( $response->cards ?? null ) ? $response->cards : array() as $card ) {
				if ( is_object( $card ) && self::identity( $card->listing_id ?? null, $card->directory_id ?? null ) && self::version( $card->version ?? null ) && is_array( $card->facts ?? null ) ) {
					$candidates[ $card->directory_id . ':' . $card->listing_id ] = array( $card->listing_id, $card->directory_id );
					foreach ( $card->facts as $fact ) {
						if ( is_object( $fact ) && ( $unit_fields[ $card->directory_id ] ?? null ) === ( $fact->field_id ?? null ) ) {
							$units[ $card->directory_id . ':' . $card->listing_id ][] = $fact;
						}
					}
				}
			}
		} elseif ( 'compare' === ( $response->task->kind ?? null ) ) {
			$sets = is_array( $response->comparisons ?? null ) ? $response->comparisons : array();
			if ( is_object( $response->comparison ?? null ) ) {
				$sets[] = $response->comparison;
			}
			foreach ( $sets as $set ) {
				if ( ! is_object( $set ) || 'directorist-ai/compare-v1' !== ( $set->contract ?? null ) || 'comparison' !== ( $set->status ?? null ) || ! is_int( $set->directory_id ?? null ) || $set->directory_id < 1 || ! is_array( $set->listing_ids ?? null ) || ! is_array( $set->rows ?? null ) ) {
					continue;
				}
				foreach ( $set->rows as $row ) {
					foreach ( is_array( $row->cells ?? null ) ? $row->cells : array() as $cell ) {
						if ( is_object( $cell ) && self::identity( $cell->listing_id ?? null, $set->directory_id ) && in_array( $cell->listing_id, $set->listing_ids, true ) && in_array( $cell->state ?? null, array( 'known', 'unknown', 'missing' ), true ) && self::version( $cell->version ?? null ) ) {
							$candidates[ $set->directory_id . ':' . $cell->listing_id ] = array( $cell->listing_id, $set->directory_id );
							if ( ( $unit_fields[ $set->directory_id ] ?? null ) === ( $row->field_id ?? null ) ) {
								$fact           = clone $cell;
								$fact->field_id = $row->field_id;
								$units[ $set->directory_id . ':' . $cell->listing_id ][] = $fact;
							}
						}
					}
				}
			}
		}
		if ( ! defined( 'ATBDP_POST_TYPE' ) || ! function_exists( 'directorist_get_listing_directory' ) ) {
			return $display;
		}
		$listings = array();
		foreach ( $candidates as list( $id, $directory ) ) {
			if ( isset( $listings[ $id ] ) ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || $id !== (int) $post->ID || ATBDP_POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || (int) directorist_get_listing_directory( $id ) !== $directory ) {
				continue;
			}
			$url = get_permalink( $post );
			if ( ! self::public_url( $url ) ) {
				continue;
			}
			$title           = wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$listings[ $id ] = (object) array(
				'listing_id'   => $id,
				'directory_id' => $directory,
				'title'        => mb_substr( trim( $title ), 0, 240 ),
				'url'          => $url,
			);
			$matches         = $units[ $directory . ':' . $id ] ?? array();
			if ( 1 === count( $matches ) && 'known' === ( $matches[0]->state ?? null ) && is_string( $matches[0]->value ?? null ) && '' !== trim( $matches[0]->value ) && strlen( $matches[0]->value ) <= 160 && 1 === preg_match( '//u', $matches[0]->value ) ) {
				$listings[ $id ]->price_unit = (object) array(
					'field_id' => $matches[0]->field_id,
					'value'    => $matches[0]->value,
				);
			}
			if ( count( $listings ) >= 20 ) {
				break;
			}
		}
		$display->listings = array_values( $listings );
		return $display;
	}

	/**
	 * Require exact positive native identities, never numeric-string coercion.
	 *
	 * @param mixed $id Listing identity.
	 * @param mixed $directory Directory identity.
	 * @return bool Whether both identities are exact positive integers.
	 */
	private static function identity( $id, $directory ): bool {
		return is_int( $id ) && $id > 0 && is_int( $directory ) && $directory > 0;
	}

	/**
	 * Require the native eligibility version retained in verified cards/cells.
	 *
	 * @param mixed $version Native content hash.
	 * @return bool Whether the native version has its closed hash shape.
	 */
	private static function version( $version ): bool {
		return is_string( $version ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $version );
	}

	/**
	 * Keep native links on the site's own HTTP(S) origin without credentials.
	 *
	 * @param mixed $url Native public permalink.
	 * @return bool Whether the native permalink is safe presentation text.
	 */
	private static function public_url( $url ): bool {
		if ( ! is_string( $url ) || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || ! is_array( $home ) || ! in_array( $parts['scheme'] ?? null, array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$port      = $parts['port'] ?? ( 'https' === $parts['scheme'] ? 443 : 80 );
		$home_port = $home['port'] ?? ( 'https' === ( $home['scheme'] ?? null ) ? 443 : 80 );
		return ( $home['scheme'] ?? null ) === $parts['scheme'] && strtolower( $home['host'] ?? '' ) === strtolower( $parts['host'] ) && $home_port === $port;
	}
}
