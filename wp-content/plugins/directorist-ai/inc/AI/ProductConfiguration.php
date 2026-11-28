<?php
/**
 * Deployment-owned product identity; customers provide only their license key.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

/** Fixed build/deployment values never come from REST input or a license key. */
final class ProductConfiguration {

	/** Return the pinned licensing origin. */
	public static function store_url(): string {
		return defined( 'DIRECTORIST_AI_PRO_STORE_URL' ) ? rtrim( (string) DIRECTORIST_AI_PRO_STORE_URL, '/' ) : 'https://directorist.com';
	}

	/** Return the native EDD product identity for this plugin. */
	public static function item_id(): int {
		return defined( 'DIRECTORIST_AI_PRO_PRODUCT_ID' ) ? (int) DIRECTORIST_AI_PRO_PRODUCT_ID : 372875;
	}

	/** Return the approved managed-service origin. */
	public static function service_url(): string {
		return defined( 'DIRECTORIST_AI_PRO_SERVICE_URL' ) ? rtrim( (string) DIRECTORIST_AI_PRO_SERVICE_URL, '/' ) : 'https://ai.directorist.com';
	}

	/** Native EDD authentication resolves the buyer; no credential is in this URL. */
	public static function buy_credits_url(): string {
		return self::store_url() ? self::store_url() . '/?dai_ai_credits=1' : '';
	}

	/** Check public build readiness without returning deployment fields. */
	public static function configured(): bool {
		try {
			$url   = self::store_url();
			$parts = wp_parse_url( $url );
			return self::item_id() > 0 && '' !== $url && is_array( $parts ) && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] ) && ManagedService::destination( $url ) === $url;
		} catch ( \DirectoristAIPro\Orchestration\ModelException $error ) {
			return false;
		}
	}

	/**
	 * Admit navigation only from the pinned store, never from caller input.
	 *
	 * @param string $url Store-authored navigation URL.
	 * @throws \DirectoristAIPro\Orchestration\ModelException Untrusted destination.
	 */
	public static function store_destination( string $url ): string {
		$url = ManagedService::destination( $url );
		$one = wp_parse_url( self::store_url() );
		$two = wp_parse_url( $url );
		foreach ( array( 'scheme', 'host', 'port' ) as $key ) {
			if ( ( $one[ $key ] ?? null ) !== ( $two[ $key ] ?? null ) ) {
				throw new \DirectoristAIPro\Orchestration\ModelException( 'invalid_output', 'The credit destination does not belong to the license store.' );
			}
		}
		return $url;
	}
}
