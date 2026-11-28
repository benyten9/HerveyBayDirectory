<?php
/**
 * Private intake, with public-address URL fencing.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Closed import adapters documented in the feature contract.

use DirectoristAIPro\Validation\ContractException;

/** Raw bytes are parsed privately and never moved into public uploads. */
final class SourceIntake {
	public function upload( array $file, string $records_path = '' ): array {
		if ( UPLOAD_ERR_OK !== ( $file['error'] ?? null ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) || ! is_string( $file['name'] ?? null ) ) {
			throw new ContractException( 'source_upload', 'Choose a successfully uploaded source file.' ); }
		$size = filesize( $file['tmp_name'] );
		if ( false === $size || $size < 1 || $size > min( Limits::SOURCE_BYTES, wp_max_upload_size() ) ) {
			throw new ContractException( 'source_size', 'The file exceeds the site upload limit or 10 MiB.' ); }
		$format = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $format, array( 'csv', 'xlsx', 'json', 'xml' ), true ) ) {
			throw new ContractException( 'unsupported_source', 'Upload CSV, XLSX, JSON or XML. Use public URL intake for HTML.' ); }
		$bytes            = file_get_contents( $file['tmp_name'] );
		$result           = ( new SourceParser() )->parse( $bytes, $format, $records_path );
		$result['origin'] = array(
			'kind' => 'upload',
			'name' => sanitize_file_name( $file['name'] ),
		);
		return $result;
	}

	public static function public_url( string $url ): string {
		self::addresses( $url );
		return $url;
	}

	private static function addresses( string $url ): array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && ! in_array( $parts['port'], array( 80, 443 ), true ) ) || strlen( $url ) > 2048 ) {
			throw new ContractException( 'source_url', 'Use a public HTTP(S) URL without credentials on port 80 or 443.' );
		}
		$host      = trim( $parts['host'], '[]' );
		$addresses = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$addresses[] = $host; } else {
			$dns = dns_get_record( $host, DNS_A | DNS_AAAA );
			if ( ! is_array( $dns ) ) {
				throw new ContractException( 'source_url', 'The source hostname could not be resolved.' ); }
			foreach ( $dns as $record ) {
				if ( isset( $record['ip'] ) ) {
					$addresses[] = $record['ip'];
				} if ( isset( $record['ipv6'] ) ) {
					$addresses[] = $record['ipv6']; }
			}
			}
			if ( ! $addresses ) {
				throw new ContractException( 'source_url', 'The source hostname has no public address.' ); }
			foreach ( $addresses as $address ) {
				if ( ! self::public_address( $address ) ) {
						throw new ContractException( 'source_url', 'Local, private, reserved or mixed-address hosts are unsupported.' ); }
			}
			return array_values( array_unique( $addresses ) );
	}

	public static function public_address( string $address ): bool {
		if ( ! filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false; }
		if ( filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$value = unpack( 'N', inet_pton( $address ) )[1];
			foreach ( array( array( '100.64.0.0', 10 ), array( '192.0.0.0', 24 ), array( '192.0.2.0', 24 ), array( '198.18.0.0', 15 ), array( '198.51.100.0', 24 ), array( '203.0.113.0', 24 ), array( '224.0.0.0', 4 ) ) as $range ) {
				$mask = ( 0xffffffff << ( 32 - $range[1] ) ) & 0xffffffff;
				if ( ( $value & $mask ) === ( unpack( 'N', inet_pton( $range[0] ) )[1] & $mask ) ) {
					return false; }
			}
			return true;
		}
		$packed = inet_pton( $address );
		return ( ord( $packed[0] ) & 0xe0 ) === 0x20 && ! str_starts_with( strtolower( $address ), '2001:db8:' ) && ! str_starts_with( strtolower( $address ), '2002:' ) && substr( $packed, 0, 4 ) !== hex2bin( '20010000' );
	}

	public function url( string $url ): array {
		$origin  = $url;
		$seen    = array();
		$started = microtime( true );
		for ( $redirects = 0; $redirects <= 3; ++$redirects ) {
			$addresses = self::addresses( $url );
			if ( ! class_exists( '\\WpOrg\\Requests\\Transport\\Curl' ) || ! \WpOrg\Requests\Transport\Curl::test( array( 'ssl' => str_starts_with( $url, 'https:' ) ) ) ) {
				throw new ContractException( 'unsupported_dependency', 'Public URL import requires the native cURL transport to pin a verified public address.' ); }
			if ( ( new \WP_Http() )->block_request( $url ) ) {
				throw new ContractException( 'source_url', 'The site HTTP policy blocks this public source.' ); }
			$remaining = 30 - ( microtime( true ) - $started );
			if ( $remaining <= 0 ) {
				throw new ContractException( 'source_url', 'The public source exceeded the 30-second fetch budget.' ); }
			$parts  = wp_parse_url( $url );
			$host   = trim( $parts['host'], '[]' );
			$port   = $parts['port'] ?? ( 'https' === $parts['scheme'] ? 443 : 80 );
			$pinned = false;
			$hooks  = new \WpOrg\Requests\Hooks();
			$hooks->register(
				'curl.before_send',
				static function ( $handle ) use ( $host, $port, $addresses, &$pinned ): void {
					$address = str_contains( $addresses[0], ':' ) ? '[' . $addresses[0] . ']' : $addresses[0];
					curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . $address ) );
					curl_setopt( $handle, CURLOPT_PROXY, '' );
					curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
					curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 2 );
					curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, true );
					$pinned = true;
				}
			);
			if ( isset( $seen[ $url ] ) ) {
				throw new ContractException( 'source_url', 'The source URL redirects in a loop.' );
			} $seen[ $url ] = true;
			try {
				// Public Requests API with an explicit transport prevents any unpinned stream fallback.
				$response = \WpOrg\Requests\Requests::get(
					$url,
					array( 'Accept' => 'text/html' ),
					array(
						'transport'        => new \WpOrg\Requests\Transport\Curl(),
						'hooks'            => $hooks,
						'timeout'          => min( 15, $remaining ),
						'connect_timeout'  => min( 10, $remaining ),
						'follow_redirects' => false,
						'redirects'        => 0,
						'max_bytes'        => Limits::SOURCE_BYTES + 1,
						'auth'             => false,
						'cookies'          => array(),
						'proxy'            => false,
						'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
						'verifyname'       => true,
					)
				);
			} catch ( \Throwable $error ) {
				throw new ContractException( 'source_url', 'The public page could not be fetched with verified TLS and a pinned public address. Export its data as a supported file.' ); }
			if ( ! $pinned ) {
				throw new ContractException( 'source_url', 'The native cURL transport did not pin the verified public address.' ); }
			$status = (int) $response->status_code;
			if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = $response->headers['location'] ?? '';
				if ( ! is_string( $location ) || '' === $location ) {
					throw new ContractException( 'source_url', 'The page returned an invalid redirect.' );
				} $url = \WP_Http::make_absolute_url( $location, $url );
				continue; }
			if ( 200 !== $status || ! preg_match( '#^text/html(?:;|$)#i', (string) ( $response->headers['content-type'] ?? '' ) ) ) {
				throw new ContractException( 'source_html', 'Use an unauthenticated HTML page returning HTTP 200.' ); }
			$result           = ( new SourceParser() )->parse( $response->body, 'html' );
			$result['origin'] = array(
				'kind'         => 'url',
				'url'          => $origin,
				'resolved_url' => $url,
				'captured_at'  => gmdate( DATE_RFC3339 ),
			);
			return $result;
		}
		throw new ContractException( 'source_url', 'The source exceeds three redirects.' );
	}
}
