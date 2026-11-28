<?php
/**
 * Native EDD Software Licensing client.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;
use DirectoristAIPro\Orchestration\ModelException;
use DirectoristAIPro\Compatibility\PluginIdentity;
// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Thin native EDD adapter.
/** Maintains the closed server-owned boundary. */
final class LicenseConnection {
	private const OPTION = 'directorist_ai_pro_edd_license';
	public static function status(): array {
		$s = get_option( self::OPTION, array() );
		return array(
			'mode'                => 'native_edd',
			'status'              => $s['status'] ?? 'not_connected',
			'key_configured'      => ! empty( $s['secret'] ),
			'configuration_ready' => ProductConfiguration::configured(),
			'expires'             => $s['expires'] ?? null,
			'checked_at'          => $s['checked_at'] ?? null,
			'managed_status'      => $s['managed_status'] ?? 'not_connected',
			'managed_message'     => $s['managed_message'] ?? '',
			'message'             => 'Your license connects this site to your shared credit account. Purchased credits remain usable on already-linked sites after license expiry.',
		);
	}
	/** Register native update metadata without installing or publishing anything. */
	public static function hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'updates' ) );
		add_filter( 'plugins_api', array( self::class, 'information' ), 10, 3 );
	}

	public static function updates( $transient ) {
		if ( ! is_object( $transient ) || ! defined( 'DIRECTORIST_AI_PRO_FILE' ) ) {
			return $transient; }
		$plugin = plugin_basename( DIRECTORIST_AI_PRO_FILE );
		if ( PluginIdentity::CANONICAL_BASENAME !== $plugin ) {
			// Folder/main-file migration requires native installation and activation.
			return $transient;
		}
		if ( empty( $transient->checked[ $plugin ] ) ) {
			return $transient; }
		$version = self::version();
		if ( ! $version || empty( $version['new_version'] ) || ! version_compare( $version['new_version'], $transient->checked[ $plugin ], '>' ) || empty( $version['package'] ?? $version['download_link'] ?? '' ) ) {
			return $transient; }
		$transient->response[ $plugin ] = (object) array(
			'slug'         => PluginIdentity::SLUG,
			'plugin'       => $plugin,
			'new_version'  => $version['new_version'],
			'url'          => $version['url'] ?? '',
			'package'      => $version['package'] ?? $version['download_link'],
			'requires'     => $version['requires'] ?? '7.0',
			'requires_php' => $version['requires_php'] ?? '8.0',
			'tested'       => $version['tested'] ?? '',
		);
		return $transient;
	}

	public static function information( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action || ! in_array( ( $args->slug ?? '' ), array( PluginIdentity::SLUG, PluginIdentity::LEGACY_SLUG ), true ) ) {
			return $result; }
		$version = self::version();
		return $version ? (object) array_merge(
			$version,
			array(
				'name'          => 'Directorist AI',
				'slug'          => PluginIdentity::SLUG,
				'version'       => $version['new_version'] ?? '',
				'download_link' => $version['package'] ?? $version['download_link'] ?? '',
			)
		) : $result;
	}

	private static function version(): ?array {
		$s = get_option( self::OPTION, array() );
		if ( 'valid' !== ( $s['status'] ?? '' ) || empty( $s['secret'] ) ) {
			return null; }
		if ( ! is_string( $s['store_url'] ?? null ) || rtrim( $s['store_url'], '/' ) !== ProductConfiguration::store_url() || (int) ( $s['item_id'] ?? 0 ) !== ProductConfiguration::item_id() ) {
			return null;
		}
		$cache = 'dai_edd_version_' . hash( 'sha256', ProductConfiguration::store_url() . '|' . ProductConfiguration::item_id() . '|' . $s['secret'] . '|' . plugin_basename( DIRECTORIST_AI_PRO_FILE ) );
		$value = get_transient( $cache );
		if ( is_array( $value ) ) {
			return $value; }
		try {
			$value = self::action( 'get_version' );
		} catch ( ModelException $error ) {
			return null; }
		set_transient( $cache, $value, 600 );
		return $value;
	}

	public static function action( string $action, array $input = array() ): array {
		if ( ! in_array( $action, array( 'activate_license', 'check_license', 'deactivate_license', 'get_version' ), true ) ) {
			throw new ModelException( 'invalid_input', 'Choose a native EDD license action.' ); }
		$s   = get_option( self::OPTION, array() );
		$old = $s;
		if ( ! ProductConfiguration::configured() ) {
			throw new ModelException( 'license_unavailable', 'License activation is temporarily unavailable. Please try again later or contact support.' );
		}
		if ( array_diff( array_keys( $input ), array( 'license_key' ) ) || ( 'activate_license' !== $action && $input ) ) {
			throw new ModelException( 'invalid_input', 'Only the license key is required. Product and account details are handled automatically.' );
		}
		if ( 'activate_license' === $action ) {
			if ( ! is_string( $input['license_key'] ?? null ) || strlen( $input['license_key'] ) < 8 || strlen( $input['license_key'] ) > 256 || preg_match( '/[\x00-\x1f]/', $input['license_key'] ) ) {
				throw new ModelException( 'invalid_input', 'Enter a valid license key.' ); }
			$s = array(
				'store_url' => ProductConfiguration::store_url(),
				'item_id'   => ProductConfiguration::item_id(),
				'secret'    => ManagedService::seal( $input['license_key'] ),
			);
		}
		if ( 'activate_license' !== $action && ! empty( $s['secret'] ) && ( ! is_string( $s['store_url'] ?? null ) || rtrim( $s['store_url'], '/' ) !== ProductConfiguration::store_url() || (int) ( $s['item_id'] ?? 0 ) !== ProductConfiguration::item_id() ) ) {
			throw new ModelException( 'license_unavailable', 'Your license connection needs to be updated. Your existing account is retained. Enter a license from the current store to reconnect.' );
		}
		$key = ManagedService::unseal( $s['secret'] ?? '' );
		if ( ! $key || empty( $s['store_url'] ) || empty( $s['item_id'] ) ) {
			throw new ModelException( 'license_unavailable', 'No EDD license is connected.' ); }
		if ( 'get_version' !== $action ) {
			return self::enroll( $action, $s, $old, $key );
		}
		// Existing native EDD version metadata remains the update authority.
		$s['store_url'] = ProductConfiguration::store_url();
		$s['item_id']   = ProductConfiguration::item_id();
		$args           = array(
			'method'              => 'POST',
			'timeout'             => 15,
			'redirection'         => 0,
			'limit_response_size' => 1048576,
			'body'                => array(
				'edd_action' => $action,
				'slug'       => plugin_basename( DIRECTORIST_AI_PRO_FILE ) === PluginIdentity::CANONICAL_BASENAME ? PluginIdentity::SLUG : PluginIdentity::LEGACY_SLUG,
				'license'    => $key,
				'item_id'    => $s['item_id'],
				'author'     => 'WpWax',
				'beta'       => false,
				'url'        => home_url( '/' ),
			),
		);
		$qa             = defined( 'DAI_MANAGED_ALLOW_TEST_URLS' ) && true === DAI_MANAGED_ALLOW_TEST_URLS && 'http' === wp_parse_url( $s['store_url'], PHP_URL_SCHEME );
		$r              = $qa ? wp_remote_request( $s['store_url'] . '/', $args ) : wp_safe_remote_request( $s['store_url'] . '/', $args );
		if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) {
			throw new ModelException( 'license_unavailable', 'The EDD license endpoint could not be reached.' ); }
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $data ) ) {
			throw new ModelException( 'license_unavailable', 'The EDD license endpoint returned an invalid response.' ); }
		$allowed = array_intersect_key( $data, array_flip( array( 'new_version', 'name', 'slug', 'url', 'package', 'download_link', 'requires', 'tested', 'requires_php', 'sections', 'icons', 'banners', 'contributors', 'author', 'author_profile', 'homepage', 'last_updated', 'downloaded', 'rating', 'num_ratings' ) ) );
		foreach ( array( 'package', 'download_link' ) as $k ) {
			if ( isset( $allowed[ $k ] ) && ( ! is_string( $allowed[ $k ] ) || ! wp_http_validate_url( $allowed[ $k ] ) ) ) {
				unset( $allowed[ $k ] );
			}
		}
		return $allowed;
	}

	/**
	 * Verify native license ownership and establish a private site credential.
	 *
	 * @throws ModelException Unverifiable license or account response.
	 */
	private static function enroll( string $action, array $next, array $old, string $key ): array {
		$url      = ProductConfiguration::store_url() . '/wp-json/directorist-ai-store/v1/license';
		$args     = array(
			'method'              => 'POST',
			'timeout'             => 30,
			'redirection'         => 0,
			'limit_response_size' => 1048576,
			'headers'             => array( 'Content-Type' => 'application/json' ),
			'body'                => wp_json_encode(
				array(
					'action'      => $action,
					'license_key' => $key,
					'site_url'    => home_url( '/' ),
					'site_label'  => get_bloginfo( 'name' ),
					'request_id'  => 'license-' . bin2hex( random_bytes( 16 ) ),
				)
			),
		);
		$qa       = defined( 'DAI_MANAGED_ALLOW_TEST_URLS' ) && true === DAI_MANAGED_ALLOW_TEST_URLS && 'http' === wp_parse_url( $url, PHP_URL_SCHEME );
		$response = $qa ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new ModelException( 'license_unavailable', 'The license could not be verified. Your previous account connection is retained. Please try again.' );
		}
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$states = array( 'valid', 'expired', 'inactive', 'site_inactive', 'disabled', 'invalid', 'deactivated', 'revoked', 'refunded' );
		if ( ! is_array( $data ) || 1 !== ( $data['schema_version'] ?? null ) || ! in_array( $data['license'] ?? '', $states, true ) || ! is_bool( $data['site_active'] ?? null ) || ! is_array( $data['managed'] ?? null ) ) {
			throw new ModelException( 'invalid_output', 'The license service returned no verifiable account status.' );
		}
		$expired_recovery = 'expired' === $data['license'] && $data['site_active'] && 'connected' === ( $data['managed']['status'] ?? '' );
		if ( 'activate_license' === $action && 'valid' !== $data['license'] && ! $expired_recovery ) {
			throw new ModelException( 'license_rejected', 'This license could not activate the site. Check its status and available activations in your account.' );
		}
		$next['store_url']       = ProductConfiguration::store_url();
		$next['item_id']         = ProductConfiguration::item_id();
		$next['status']          = $data['license'];
		$next['expires']         = is_string( $data['expires_at'] ?? null ) && false !== strtotime( $data['expires_at'] ) ? $data['expires_at'] : null;
		$next['checked_at']      = gmdate( DATE_RFC3339 );
		$next['managed_status']  = 'pending';
		$next['managed_message'] = 'Your license is verified. The credit account connection is awaiting confirmation. Check again to retry.';
		$replacing               = ! empty( $old['secret'] ) && ! hash_equals( ManagedService::unseal( $old['secret'] ), $key );
		if ( $replacing || 'deactivate_license' === $action || ! $data['site_active'] || in_array( $data['license'], array( 'disabled', 'invalid', 'revoked', 'refunded' ), true ) ) {
			ManagedService::forget();
		}
		$managed = $data['managed'];
		if ( 'connected' === ( $managed['status'] ?? '' ) && $data['site_active'] && in_array( $data['license'], array( 'valid', 'expired' ), true ) ) {
			try {
				$endpoint = ManagedService::endpoint( (string) ( $managed['service_url'] ?? '' ) );
				if ( ! ProductConfiguration::service_url() || ProductConfiguration::service_url() !== $endpoint || ! is_string( $managed['account_id'] ?? null ) || ! is_string( $managed['site_id'] ?? null ) ) {
					throw new ModelException( 'invalid_output', 'The returned account connection does not match this product.' );
				}
				$account_url  = isset( $data['account_url'] ) ? ProductConfiguration::store_destination( (string) $data['account_url'] ) : '';
				$purchase_url = isset( $data['topup_url'] ) ? ProductConfiguration::store_destination( (string) $data['topup_url'] ) : ProductConfiguration::buy_credits_url();
				ManagedService::connect(
					array(
						'endpoint'        => $endpoint,
						'site_token'      => $managed['site_token'] ?? '',
						'account_url'     => $account_url,
						'buy_credits_url' => $purchase_url,
					),
					array(
						'account_id' => $managed['account_id'],
						'site_id'    => $managed['site_id'],
					)
				);
				$next['managed_status']  = 'connected';
				$next['managed_message'] = 'This site uses the same credit pool as your other connected sites.';
			} catch ( ModelException $error ) {
				$next['managed_status'] = 'pending';
			}
		} elseif ( in_array( $managed['status'] ?? '', array( 'disconnected', 'not_connected' ), true ) ) {
			$next['managed_status']  = $managed['status'];
			$next['managed_message'] = 'Activate an eligible license to connect this site to your credit account.';
		}
		update_option( self::OPTION, $next, false );
		return self::status();
	}
}
