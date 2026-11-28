<?php
/**
 * Account-bound transport; the service owns pricing and pooled funds.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;
use DirectoristAIPro\Orchestration\ModelException;
// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Closed transport implementation.
/** Maintains the closed server-owned boundary. */
final class ManagedService {
	private const OPTION = 'directorist_ai_pro_managed_connection';
	public static function status(): array {
		$c    = self::configuration();
		$base = array(
			'mode'             => 'unconnected',
			'available'        => false,
			'status'           => 'not_connected',
			'message'          => 'Activate your license to connect this site to your shared credits.',
			'endpoint'         => $c['endpoint'] ?? '',
			'checked_at'       => null,
			'stale'            => false,
			'token_configured' => ! empty( $c ),
			'account_url'      => $c['account_url'] ?? '',
			'buy_credits_url'  => $c['buy_credits_url'] ?? ProductConfiguration::buy_credits_url(),
			'credit_packs'     => array(),
			'credits'          => array(
				'balance'        => null,
				'reserved'       => null,
				'available'      => null,
				'currency'       => 'USD',
				'authoritative'  => false,
				'unit'           => 'microusd',
				'credit_divisor' => '10000',
			),
		);
		if ( ! $c ) {
			return $base; }
		$a                  = get_transient( self::cache_key( $c ) );
		$base['status']     = 'status_unavailable';
		$base['mode']       = $c['mode'] ?? 'unknown';
		$base['account_id'] = $c['account_id'] ?? null;
		$base['site_id']    = $c['site_id'] ?? null;
		$base['message']    = 'The site is connected. Refresh the account to verify service availability and balance.';
		if ( ! is_array( $a ) ) {
			return $base; }
		return array_merge(
			$base,
			array(
				'mode'              => $a['mode'],
				'available'         => true === ( $a['service']['available'] ?? false ),
				'status'            => $a['service']['status'] ?? 'unknown',
				'message'           => 'All connected sites share this account’s credits.',
				'checked_at'        => $a['checked_at'] ?? null,
				'stale'             => ! empty( $a['_refresh_failed'] ) || empty( $a['checked_at'] ) || strtotime( $a['checked_at'] ) < time() - 300,
				'sites'             => $a['sites'] ?? array(),
				'task_availability' => self::task_availability( $a ),
				'task_limits'       => self::task_limits( $a ),
				'credit_unit'       => $a['credit_unit'] ?? array(
					'name'                => 'credit',
					'microusd_per_credit' => '10000',
					'credits_per_usd'     => '100',
					'decimals'            => 4,
				),
				'account_id'        => $a['account_id'],
				'site_id'           => $a['site_id'],
				'account_url'       => $c['account_url'] ?? '',
				'credit_packs'      => self::credit_packs( $a ),
				'credits'           => array(
					'balance'        => $a['balance']['balance_microusd'],
					'reserved'       => $a['balance']['reserved_microusd'],
					'available'      => $a['balance']['available_microusd'],
					'currency'       => 'USD',
					'authoritative'  => true,
					'unit'           => 'microusd',
					'credit_divisor' => '10000',
				),
				'pricing'           => isset( $a['pricing']['version'] ) ? array(
					'version' => (string) $a['pricing']['version'],
					'basis'   => 'usage_plus_markup',
				) : null,
			)
		);
	}
	public static function license(): array {
		return LicenseConnection::status(); }
	public static function fingerprint(): string {
		$c = self::configuration();
		return $c ? hash( 'sha256', $c['endpoint'] . "\0" . $c['token'] ) : ''; }
	public static function assert_available( ?array $route = null ): void {
		if ( ! self::configuration() ) {
			throw new ModelException( 'managed_unavailable', FailureNotice::message( 'managed_unavailable', 'managed' ) ); }
		if ( null !== $route && ( empty( $route['managed_fingerprint'] ) || ! hash_equals( self::fingerprint(), $route['managed_fingerprint'] ) ) ) {
			throw new ModelException( 'provider_configuration_changed', 'The managed connection changed. Start a new request using the current account.' ); }
	}
	public static function connect( array $input, ?array $expected = null ): array {
		if ( array_diff( array_keys( $input ), array( 'endpoint', 'site_token', 'account_url', 'buy_credits_url' ) ) || ! is_string( $input['endpoint'] ?? null ) || ! is_string( $input['site_token'] ?? null ) || ( isset( $input['account_url'] ) && ! is_string( $input['account_url'] ) ) || ( isset( $input['buy_credits_url'] ) && ! is_string( $input['buy_credits_url'] ) ) || strlen( $input['site_token'] ) < 20 || strlen( $input['site_token'] ) > 512 || preg_match( '/[\s\x00-\x1f]/', $input['site_token'] ) ) {
			throw new ModelException( 'invalid_input', 'The account connection could not be verified. Check your license again.' ); }
		$c           = array(
			'endpoint' => self::endpoint( $input['endpoint'] ),
			'token'    => $input['site_token'],
		);
		$account_url = isset( $input['account_url'] ) && '' !== $input['account_url'] ? self::destination( $input['account_url'] ) : '';
		$buy_url     = isset( $input['buy_credits_url'] ) && '' !== $input['buy_credits_url'] ? self::destination( $input['buy_credits_url'] ) : ProductConfiguration::buy_credits_url();
		$a           = self::account( self::transport( $c, 'GET', '/v1/account' ) );
		if ( null !== $expected && ( ( $expected['account_id'] ?? null ) !== $a['account_id'] || ( $expected['site_id'] ?? null ) !== $a['site_id'] ) ) {
			throw new ModelException( 'invalid_output', 'The service account does not match the verified license owner and site.' );
		}
		update_option(
			self::OPTION,
			array(
				'endpoint'        => $c['endpoint'],
				'secret'          => self::seal( $c['token'] ),
				'mode'            => $a['mode'],
				'account_id'      => $a['account_id'],
				'site_id'         => $a['site_id'],
				'account_url'     => $account_url,
				'buy_credits_url' => $buy_url,
			),
			false
		);
		set_transient( self::cache_key( $c ), $a, 86400 );
		return self::status();
	}
	public static function refresh(): array {
		self::assert_available();
		$c = self::configuration();
		try {

			$account = self::account( self::transport( $c, 'GET', '/v1/account' ) );
			if ( $account['account_id'] !== $c['account_id'] || $account['site_id'] !== $c['site_id'] ) {
				throw new ModelException( 'invalid_output', 'The account response did not match this site connection.' ); }
			set_transient( self::cache_key( $c ), $account, 86400 );
		} catch ( ModelException $error ) {
			$previous = get_transient( self::cache_key( $c ) );
			if ( is_array( $previous ) ) {
				$previous['_refresh_failed'] = true;
				set_transient( self::cache_key( $c ), $previous, 86400 );
			}
			throw $error;
		}
		return self::status();
	}
	public static function disconnect(): array {
		$c = self::configuration();
		if ( $c ) {
			self::transport( $c, 'POST', '/v1/sites/revoke', array() );
			delete_transient( self::cache_key( $c ) ); }
		delete_option( self::OPTION );
		return self::status();
	}
	/** Remove local access immediately after confirmed native deactivation or owner change. */
	public static function forget(): void {
		$c = self::configuration();
		if ( $c ) {
			delete_transient( self::cache_key( $c ) );
		}
		delete_option( self::OPTION );
	}
	public static function inference( array $body, array $route, bool $poll = false, ?string $submission_json = null ): array {
		self::assert_available( $route );
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $body['request_id'] ?? '' ) ) {
			throw new ModelException( 'invalid_input', 'Managed inference requires a durable request identity.' ); }
		if ( null !== $submission_json && ! hash_equals( $submission_json, wp_json_encode( $body, JSON_PRESERVE_ZERO_FRACTION ) ) ) {
			throw new ModelException( 'managed_submission_invalid', 'The original managed request bytes changed. Its receipt is retained.' );
		}
		$r = self::transport( self::configuration(), $poll ? 'GET' : 'POST', $poll ? '/v1/inference/' . rawurlencode( $body['request_id'] ) : '/v1/inference', $poll ? null : $body, false, $poll ? null : $submission_json );
		if ( ( $r['request_id'] ?? null ) !== $body['request_id'] || ( $r['operation_id'] ?? null ) !== $body['operation_id'] ) {
			throw new ModelException( 'managed_uncertain', 'The service returned an unbound result. Its charge remains unconfirmed.' ); }
		if ( isset( $r['balance'] ) ) {
			delete_transient( self::cache_key( self::configuration() ) ); }
		if ( in_array( $r['status'] ?? '', array( 'pending', 'uncertain' ), true ) ) {
			throw new ModelException( 'pending' === $r['status'] ? 'managed_pending' : 'managed_uncertain', 'The existing managed request is awaiting its result. Provider execution will not be repeated.', true, self::receipt( $r ) ); }
		if ( 'completed' !== ( $r['status'] ?? null ) ) {
			throw new ModelException( 'managed_failed', 'The managed provider could not complete this request.', false, self::receipt( $r ) ); }
		self::receipt( $r );
		return $r;
	}
	public static function receipt( array $result ): array {
		$r = $result['receipt'] ?? null;
		if ( ! is_array( $r ) || ! is_string( $r['id'] ?? null ) || ! in_array( $r['mode'] ?? '', array( 'sandbox', 'production' ), true ) || ! in_array( $r['type'] ?? '', array( 'chat', 'listing', 'layout', 'image', 'inspection' ), true ) ) {
			throw new ModelException( 'managed_uncertain', 'No valid managed receipt is available. Provider charges remain unconfirmed.' ); }
		$safe = array_intersect_key( $r, array_flip( array( 'id', 'mode', 'type', 'pricing_version', 'state', 'usage', 'provider_cost_microusd', 'charged_microusd', 'reserved_microusd', 'cost_basis', 'operation_id', 'request_id', 'credit_unit', 'charged_credits_display', 'reserved_credits_display' ) ) );
		foreach ( array( 'provider_cost_microusd', 'charged_microusd' ) as $key ) {
			if ( null !== ( $safe[ $key ] ?? null ) && ( ! is_string( $safe[ $key ] ) || ! preg_match( '/^(0|[1-9][0-9]{0,24})$/D', $safe[ $key ] ) ) ) {
					throw new ModelException( 'managed_uncertain', 'Managed receipt contains invalid monetary evidence.' ); }
		}
		$telemetry = array(
			'source'          => 'managed',
			'managed_receipt' => $safe,
		);
		if ( is_string( $result['error']['code'] ?? null ) && ( preg_match( '/^provider_http_[1-5][0-9]{2}$/D', $result['error']['code'] ) || ( 'request_failed' === $result['error']['code'] && is_array( $result['error']['diagnostics'] ?? null ) ) ) ) {
			$telemetry['managed_rejection'] = array( 'code' => $result['error']['code'] );
			$diagnostics = $result['error']['diagnostics'] ?? array();
			if ( is_int( $diagnostics['http_status'] ?? null ) && $diagnostics['http_status'] >= 100 && $diagnostics['http_status'] <= 599 ) { $telemetry['managed_rejection']['http_status'] = $diagnostics['http_status']; }
			if ( is_int( $diagnostics['retry_after_seconds'] ?? null ) && $diagnostics['retry_after_seconds'] >= 0 && $diagnostics['retry_after_seconds'] <= 86400 ) { $telemetry['managed_rejection']['retry_after_seconds'] = $diagnostics['retry_after_seconds']; }
			if ( in_array( $diagnostics['category'] ?? null, array( 'rate_limit', 'capacity', 'authentication', 'credits', 'model_unavailable', 'invalid_request', 'upstream_rejection' ), true ) ) { $telemetry['managed_rejection']['category'] = $diagnostics['category']; }
		}
		return $telemetry;
	}
	public static function usage( array $filters = array() ): array {
		self::assert_available();
		return self::customer_response( self::transport( self::configuration(), 'GET', '/v1/usage?' . self::usage_query( $filters, true ) ) ); }
	public static function usage_summary( array $filters = array() ): array {
		self::assert_available();
		return self::customer_response( self::transport( self::configuration(), 'GET', '/v1/usage-summary?' . self::usage_query( $filters ) ) ); }
	private static function usage_query( array $filters, bool $paged = false ): string {
		$allowed = array( 'from', 'to', 'days', 'site_id', 'type', 'state', 'operation_id', 'group_by', 'timezone' );
		if ( $paged ) {
			$allowed = array_merge( $allowed, array( 'cursor', 'limit' ) ); }
		if ( array_diff( array_keys( $filters ), $allowed ) ) {
			throw new ModelException( 'invalid_input', 'Choose supported receipt filters.' ); }
		$clean = array();
		foreach ( $filters as $key => $value ) {
			if ( ! is_scalar( $value ) || strlen( (string) $value ) > ( 'cursor' === $key ? 2048 : 256 ) || preg_match( '/[\x00-\x1f]/', (string) $value ) ) {
				throw new ModelException( 'invalid_input', 'Receipt filter is invalid.' ); }
			if ( '' !== (string) $value ) {
				$clean[ $key ] = (string) $value; }
		}
		if ( $paged ) {
			$clean['limit'] = isset( $clean['limit'] ) ? $clean['limit'] : '50'; }
		return http_build_query( $clean, '', '&', PHP_QUERY_RFC3986 );
	}
	public static function image( string $path, array $route ): string {
		self::assert_available( $route );
		if ( ! preg_match( '#^/v1/images/[a-f0-9-]{36}$#D', $path ) ) {
			throw new ModelException( 'invalid_output', 'Managed image must be a service-owned artifact.' ); }
		return self::transport( self::configuration(), 'GET', $path, null, true );
	}
	public static function endpoint( string $url ): string {
		$url   = rtrim( trim( $url ), '/' );
		$p     = wp_parse_url( $url );
		$qa    = defined( 'DAI_MANAGED_ALLOW_TEST_URLS' ) && true === DAI_MANAGED_ALLOW_TEST_URLS;
		$local = is_array( $p ) && ( in_array( $p['host'] ?? '', array( 'localhost', '127.0.0.1', '::1' ), true ) || str_ends_with( $p['host'] ?? '', '.test' ) );
		if ( ! is_array( $p ) || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['query'] ) || isset( $p['fragment'] ) || ! empty( $p['path'] ) || ( 'https' !== ( $p['scheme'] ?? '' ) && ! ( $qa && $local && 'http' === ( $p['scheme'] ?? '' ) ) ) ) {
			throw new ModelException( 'invalid_input', 'Use an HTTPS service origin. HTTP is allowed only in explicitly enabled local QA.' ); }
		return $url;
	}
	public static function destination( string $url ): string {
		$p     = wp_parse_url( $url );
		$qa    = defined( 'DAI_MANAGED_ALLOW_TEST_URLS' ) && true === DAI_MANAGED_ALLOW_TEST_URLS;
		$local = is_array( $p ) && ( in_array( $p['host'] ?? '', array( 'localhost', '127.0.0.1' ), true ) || str_ends_with( $p['host'] ?? '', '.test' ) );
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! is_array( $p ) || isset( $p['user'] ) || isset( $p['pass'] ) || ( 'https' !== ( $p['scheme'] ?? '' ) && ! ( $qa && $local && 'http' === ( $p['scheme'] ?? '' ) ) ) ) {
			throw new ModelException( 'invalid_input', 'Use an HTTPS store-account URL, or an explicitly enabled local QA URL.' ); }
		return $url;
	}

	public static function seal( string $secret ): string {
		$nonce = random_bytes( 12 );
		$tag   = '';
		$bytes = openssl_encrypt( $secret, 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $bytes ) {
			throw new ModelException( 'configuration_unavailable', 'The site could not encrypt its connection key.' ); }
		return base64_encode( $nonce . $tag . $bytes );
	}
	public static function unseal( string $encoded ): string {
		$raw = base64_decode( $encoded, true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return ''; }
		$v = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return is_string( $v ) ? $v : '';
	}
	private static function configuration(): array {
		$s = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		if ( ! is_array( $s ) || empty( $s['endpoint'] ) || empty( $s['secret'] ) ) {
			return array(); }
		$token = self::unseal( $s['secret'] );
		return $token ? array(
			'endpoint'        => self::endpoint( $s['endpoint'] ),
			'token'           => $token,
			'mode'            => $s['mode'] ?? 'unknown',
			'account_id'      => $s['account_id'] ?? null,
			'site_id'         => $s['site_id'] ?? null,
			'account_url'     => $s['account_url'] ?? '',
			'buy_credits_url' => $s['buy_credits_url'] ?? ProductConfiguration::buy_credits_url(),
		) : array();
	}
	private static function cache_key( array $c ): string {
		return 'dai_managed_' . hash( 'sha256', $c['endpoint'] . "\0" . $c['token'] ); }
	private static function account( array $a ): array {
		if ( ! is_string( $a['account_id'] ?? null ) || ! is_string( $a['site_id'] ?? null ) || ! in_array( $a['mode'] ?? '', array( 'sandbox', 'production' ), true ) || 'USD' !== ( $a['balance']['currency'] ?? '' ) || ! is_array( $a['service'] ?? null ) ) {
			throw new ModelException( 'invalid_output', 'The service account response is invalid.' ); }
		foreach ( array( 'balance_microusd', 'reserved_microusd', 'available_microusd' ) as $k ) {
			if ( ! is_string( $a['balance'][ $k ] ?? null ) || ! preg_match( '/^(0|[1-9][0-9]{0,24})$/D', $a['balance'][ $k ] ) ) {
					throw new ModelException( 'invalid_output', 'The service account balance is invalid.' ); }
		}
		if ( isset( $a['credit_unit']['microusd_per_credit'] ) && '10000' !== (string) $a['credit_unit']['microusd_per_credit'] ) {
			throw new ModelException( 'invalid_output', 'The service credit unit is invalid.' ); }
		$a['task_availability'] = self::task_availability( $a );
		$a['checked_at']        = is_string( $a['checked_at'] ?? null ) && false !== strtotime( $a['checked_at'] ) ? $a['checked_at'] : gmdate( 'Y-m-d\TH:i:s\Z' );
		$a['task_limits'] = self::task_limits( $a );
		$a['credit_packs'] = self::credit_packs( $a );
		return array_intersect_key( $a, array_flip( array( 'account_id', 'site_id', 'mode', 'balance', 'service', 'task_availability', 'task_limits', 'pricing', 'sites', 'credit_unit', 'credit_packs', 'checked_at' ) ) );
	}
	/** Store-authored purchase choices contain no account IDs or service credentials. */
	private static function credit_packs( array $account ): array {
		$rows = $account['credit_packs'] ?? array(); $out = array(); $seen = array();
		if ( ! is_array( $rows ) || count( $rows ) > 500 ) return $out;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['product_id'] ?? null ) || ! preg_match( '/^[1-9]\d{0,17}$/D', $row['product_id'] ) || ( null !== ( $row['price_id'] ?? null ) && ( ! is_string( $row['price_id'] ) || ! preg_match( '/^\d{1,18}$/D', $row['price_id'] ) ) ) ) continue;
			$key = $row['product_id'] . ':' . ( $row['price_id'] ?? 'base' );
			if ( isset( $seen[ $key ] ) || ! is_string( $row['credits'] ?? null ) || ! preg_match( '/^(?:[1-9]\d{0,10}|0)(?:\.\d{1,4})?$/D', $row['credits'] ) || (float) $row['credits'] <= 0 || ! is_string( $row['title'] ?? null ) || strlen( $row['title'] ) > 800 || ! is_string( $row['price_label'] ?? null ) || strlen( $row['price_label'] ) > 400 || ! is_string( $row['purchase_price_label'] ?? null ) || '' === $row['purchase_price_label'] || strlen( $row['purchase_price_label'] ) > 400 || ! is_string( $row['checkout_url'] ?? null ) || strlen( $row['checkout_url'] ) > 2048 ) continue;
			try { $url = ProductConfiguration::store_destination( $row['checkout_url'] ); } catch ( ModelException $error ) { continue; }
			$parts = wp_parse_url( $url ); $store = wp_parse_url( ProductConfiguration::store_url() ); $query = array(); parse_str( $parts['query'] ?? '', $query );
			if ( isset( $parts['fragment'] ) || ( $parts['path'] ?? '/' ) !== rtrim( $store['path'] ?? '', '/' ) . '/' || count( $query ) !== ( null === ( $row['price_id'] ?? null ) ? 4 : 5 ) || ( $query['dai_ai_credits'] ?? null ) !== '1' || ( $query['dai_credit_product'] ?? null ) !== $row['product_id'] || ( $query['dai_credit_price'] ?? null ) !== ( $row['price_id'] ?? null ) || ! is_string( $query['dai_credit_owner'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $query['dai_credit_owner'] ) || ! is_string( $query['dai_credit_until'] ?? null ) || ! preg_match( '/^\d{10}$/D', $query['dai_credit_until'] ) || (int) $query['dai_credit_until'] <= time() ) continue;
			$seen[ $key ] = true; $out[] = array_intersect_key( $row, array_flip( array( 'product_id', 'price_id', 'title', 'price_label', 'credits', 'purchase_price_label', 'checkout_url' ) ) );
		}
		return $out;
	}
	/** Capacity is read only from the authenticated service account report. */
	private static function task_limits( array $account ): array {
		$result = array();
		foreach ( array( 'chat', 'listing', 'layout' ) as $task ) {
			$value = $account['task_limits'][ $task ] ?? null;
			if ( ! is_array( $value ) || 1 !== ( $value['revision'] ?? null ) || ! is_int( $value['max_output_tokens'] ?? null ) || $value['max_output_tokens'] < 1 || $value['max_output_tokens'] > 16384 || ! is_int( $value['max_input_units'] ?? null ) || $value['max_input_units'] < 1 || $value['max_input_units'] > 262144 ) { continue; }
			$verified = true === ( $value['context_verified'] ?? false ) && is_int( $value['context_tokens'] ?? null ) && $value['context_tokens'] > $value['max_output_tokens'];
			$result[ $task ] = array( 'revision' => 1, 'context_tokens' => $verified ? $value['context_tokens'] : null, 'max_output_tokens' => $value['max_output_tokens'], 'max_input_units' => $verified ? max( 1, min( $value['max_input_units'], $value['context_tokens'] - $value['max_output_tokens'] - 4096 ) ) : min( 64000, $value['max_input_units'] ), 'context_verified' => $verified );
		}
		return $result;
	}
	/** Strip private managed identities from legacy reports as well as new responses. */
	public static function customer_response( array $data ): array {
		$private = array( 'models', 'model', 'by_model', 'routes', 'provider_id', 'provider_kind', 'selected_model', 'reported_model', 'model_id', 'model_label', 'requested_model', 'requested_provider', 'vendor_receipt', 'vendor_provider', 'vendor_response_id', 'rate_snapshot', 'markup_bps' );
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, $private, true ) ) {
				unset( $data[ $key ] );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::customer_response( $value );
			}
		}
		return $data;
	}

	/** Customer-visible readiness describes task support, never model catalogs. */
	private static function task_availability( array $account ): array {
		$result = array();
		foreach ( array( 'chat', 'listing', 'layout', 'image', 'inspection' ) as $task ) {
			$value           = $account['task_availability'][ $task ] ?? null;
			$result[ $task ] = array(
				'available'    => is_array( $value ) && true === ( $value['available'] ?? false ),
				'capabilities' => is_array( $value['capabilities'] ?? null ) ? array_values( array_intersect( $value['capabilities'], array( 'text', 'tools', 'image', 'vision' ) ) ) : array(),
			);
		}
		return $result;
	}

	private static function transport( array $c, string $method, string $path, ?array $body = null, bool $binary = false, ?string $submission_json = null ) {
		$phase = str_starts_with( $path, '/v1/inference/' ) ? 'receipt_lookup' : ( '/v1/inference' === $path ? 'submission' : 'service_request' );
		$args = array(
			'method'              => $method,
			'timeout'             => 30,
			'redirection'         => 0,
			'limit_response_size' => $binary ? \DirectoristAIPro\Domain\MediaSource::MAX_BYTES + 1 : 1048576,
			'headers'             => array(
				'Authorization' => 'Bearer ' . $c['token'],
				'Accept'        => $binary ? 'image/*' : 'application/json',
			),
		);
		if ( ! $binary && ( '/v1/inference' === $path || str_starts_with( $path, '/v1/inference/' ) ) ) {
			// Wait only for this already durable request. Older services safely
			// ignore the preference; pending/uncertain recovery keeps its identity.
			$args['headers']['X-DAI-Wait-Ms'] = '8000';
		}
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $submission_json ?? wp_json_encode( $body ); }
		$qa       = defined( 'DAI_MANAGED_ALLOW_TEST_URLS' ) && true === DAI_MANAGED_ALLOW_TEST_URLS && 'http' === wp_parse_url( $c['endpoint'], PHP_URL_SCHEME );
		$response = $qa ? wp_remote_request( $c['endpoint'] . $path, $args ) : wp_safe_remote_request( $c['endpoint'] . $path, $args );
		if ( is_wp_error( $response ) ) {
			$diagnostic = array( 'phase' => $phase, 'category' => 'network_error' );
			if ( preg_match( '/cURL error ([0-9]{1,3}):/', $response->get_error_message(), $match ) ) {
				$diagnostic['curl_error'] = (int) $match[1];
				$diagnostic['category'] = array( 5 => 'dns_error', 6 => 'dns_error', 7 => 'connection_error', 28 => 'timeout', 35 => 'tls_error', 60 => 'tls_error' )[ (int) $match[1] ] ?? 'network_error';
			}
			throw new ModelException( 'managed_transport_uncertain', 'The service response is unavailable. Check the existing receipt. Inference may still be processing.', true, array( 'managed_transport' => $diagnostic ) ); }
		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		if ( $binary && 200 === $status ) {
			return $raw; }
		$data = json_decode( $raw, true );
		$diagnostic = array( 'phase' => $phase, 'http_status' => $status );
		if ( in_array( $data['error']['code'] ?? null, array( 'request_not_found', 'request_conflict', 'request_site_conflict', 'invalid_site_token', 'queue_full', 'insufficient_balance', 'service_unavailable' ), true ) ) { $diagnostic['service_code'] = $data['error']['code']; }
		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( is_string( $retry_after ) && preg_match( '/^[0-9]{1,5}$/D', $retry_after ) && (int) $retry_after <= 86400 ) { $diagnostic['retry_after_seconds'] = (int) $retry_after; }
		if ( ! is_array( $data ) ) {
			throw new ModelException( 'managed_transport_uncertain', 'The service returned no verifiable response.', true, array( 'managed_transport' => $diagnostic ) ); }
		if ( $status < 200 || $status >= 300 ) {
			if ( 'failed' === ( $data['status'] ?? '' ) && 'released' === ( $data['receipt']['state'] ?? '' ) ) {
				return $data; }
			if ( $status >= 500 ) {
				throw new ModelException( 'managed_transport_uncertain', 'The service could not confirm the existing request. Its existing identity is retained for recovery.', true, array( 'managed_transport' => $diagnostic ) ); }
			$code = $data['error']['code'] ?? '';
			if ( 404 === $status && str_starts_with( $path, '/v1/inference/' ) ) {
				throw new ModelException( 'managed_transport_uncertain', 'The original managed request has no visible receipt. Recovery retains its exact original identity.', true, array( 'managed_transport' => $diagnostic ) ); }
			$task    = 'image' === ( $body['type'] ?? '' ) ? 'image' : ( 'inspection' === ( $body['type'] ?? '' ) ? 'vision' : 'text' );
			$message = FailureNotice::message( $code, 'managed', $task );
			throw new ModelException( null !== $message ? $code : 'managed_request_rejected', $message ?? FailureNotice::message( 'managed_request_rejected', 'managed', $task ), false, array( 'managed_transport' => $diagnostic ) );
		}
		return $data;
	}
}
