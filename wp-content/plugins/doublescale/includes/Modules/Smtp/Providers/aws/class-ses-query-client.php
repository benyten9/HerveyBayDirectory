<?php
/**
 * Amazon SES Query API (2010-12-01) over HTTPS with AWS Signature Version 4.
 *
 * Replaces aws/aws-sdk-php for the SMTP module’s SES-only usage.
 *
 * @package smtp
 */

namespace DoubleScale\Modules\Smtp\Providers\Aws;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Thrown when SES returns an error XML payload or the HTTP transport fails.
 */
class Ses_Exception extends \Exception {

	/** @var string */
	private $aws_error_code = '';

	/**
	 * @param string $message Human-readable message.
	 * @param string $code    Optional AWS error Code from XML.
	 */
	public function __construct( $message, $code = '', $previous = null ) {
		$this->aws_error_code = (string) $code;
		parent::__construct( $message, 0, $previous );
	}

	/**
	 * @return string
	 */
	public function get_aws_error_code() {
		return $this->aws_error_code;
	}
}

/**
 * Result object compatible with Aws\Result::get( $key ) used by callers.
 */
class Ses_Result implements \JsonSerializable {

	/** @var array<string, mixed> */
	private $data;

	/**
	 * @param array<string, mixed> $data Parsed response fields.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * @param mixed $default
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return $this->data;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->data;
	}
}

/**
 * Low-level SES Query POST + SigV4 + XML helpers.
 */
class Ses_Query_Client {

	private const SES_NS = 'http://ses.amazonaws.com/doc/2010-12-01/';

	private const VERSION = '2010-12-01';

	/** @var string */
	private $access_key;

	/** @var string */
	private $secret_key;

	/** @var string */
	private $region;

	/** @var string */
	private $host;

	/**
	 * @param string $access_key IAM access key.
	 * @param string $secret_key IAM secret key.
	 * @param string $region     AWS region id (e.g. us-east-1).
	 */
	public function __construct( $access_key, $secret_key, $region ) {
		$this->access_key = (string) $access_key;
		$this->secret_key = (string) $secret_key;
		$this->region     = (string) $region;
		$this->host       = 'email.' . $this->region . '.amazonaws.com';
	}

	/**
	 * Flat SES Query parameters (must include Action). Returns parsed result or WP_Error.
	 *
	 * @param array<string, string|int|float> $params Flat key => scalar for the POST body.
	 * @return Ses_Result|WP_Error
	 */
	public function post( array $params ) {
		if ( empty( $params['Action'] ) ) {
			return new WP_Error( 'ses_missing_action', 'SES Action is required.' );
		}
		$params['Version'] = self::VERSION;
		ksort( $params );
		$body = self::build_form_body( $params );
		$url  = 'https://' . $this->host . '/';

		$amz_date     = gmdate( 'Ymd\THis\Z' );
		$date_stamp   = gmdate( 'Ymd' );
		$payload_hash = hash( 'sha256', $body );

		$canonical_headers = 'content-type:application/x-www-form-urlencoded; charset=UTF-8' . "\n" .
			'host:' . $this->host . "\n" .
			'x-amz-content-sha256:' . $payload_hash . "\n" .
			'x-amz-date:' . $amz_date . "\n";
		$signed_headers    = 'content-type;host;x-amz-content-sha256;x-amz-date';
		$canonical_request = "POST\n/\n\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";

		$algorithm        = 'AWS4-HMAC-SHA256';
		$credential_scope = $date_stamp . '/' . $this->region . '/ses/aws4_request';
		$string_to_sign   = $algorithm . "\n{$amz_date}\n{$credential_scope}\n" . hash( 'sha256', $canonical_request );

		$signing_key = $this->get_signing_key( $date_stamp );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$credential = $this->access_key . '/' . $credential_scope;
		$auth       = $algorithm . ' Credential=' . $credential . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 90,
				'headers' => array(
					'Host'                 => $this->host,
					'Authorization'        => $auth,
					'X-Amz-Date'           => $amz_date,
					'X-Amz-Content-Sha256' => $payload_hash,
					'Content-Type'         => 'application/x-www-form-urlencoded; charset=UTF-8',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code > 299 ) {
			$msg = self::parse_error_xml_message( $raw );
			if ( $msg !== '' ) {
				return new WP_Error( 'ses_api_error', $msg );
			}
			return new WP_Error( 'ses_http_error', 'SES HTTP ' . $code . ': ' . substr( $raw, 0, 500 ) );
		}

		try {
			return $this->parse_response( $raw, (string) $params['Action'] );
		} catch ( Ses_Exception $e ) {
			return new WP_Error( 'ses_api_error', $e->getMessage(), array( 'code' => $e->get_aws_error_code() ) );
		}
	}

	/**
	 * Best-effort AWS error message from XML body on failed HTTP status.
	 *
	 * @param string $raw Response body.
	 * @return string Empty if not a recognizable ErrorResponse.
	 */
	private static function parse_error_xml_message( $raw ) {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return '';
		}
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $raw );
		if ( false === $xml || 'ErrorResponse' !== $xml->getName() ) {
			return '';
		}
		$ens = $xml->children( self::SES_NS );
		if ( 0 === count( $ens ) ) {
			$ens = $xml->children();
		}
		if ( isset( $ens->Error->Message ) ) {
			return (string) $ens->Error->Message;
		}
		return '';
	}

	/**
	 * @param array<string, string|int|float> $params
	 */
	private static function build_form_body( array $params ): string {
		$pairs = array();
		foreach ( $params as $k => $v ) {
			$pairs[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
		}
		return implode( '&', $pairs );
	}

	/**
	 * @return string Binary signing key.
	 */
	private function get_signing_key( $date_stamp ) {
		$k_date    = hash_hmac( 'sha256', (string) $date_stamp, 'AWS4' . $this->secret_key, true );
		$k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
		$k_service = hash_hmac( 'sha256', 'ses', $k_region, true );
		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}

	/**
	 * @return Ses_Result
	 */
	private function parse_response( $xml_body, $action ) {
		$xml_body = trim( (string) $xml_body );
		if ( $xml_body === '' ) {
			throw new Ses_Exception( esc_html__( 'Empty SES response body.', 'doublescale' ) );
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $xml_body );
		if ( false === $xml ) {
			throw new Ses_Exception( esc_html__( 'Invalid SES XML response.', 'doublescale' ) );
		}

		$root_name = $xml->getName();
		if ( 'ErrorResponse' === $root_name ) {
			$ens = $xml->children( self::SES_NS );
			if ( 0 === count( $ens ) ) {
				$ens = $xml->children();
			}
			if ( isset( $ens->Error->Code, $ens->Error->Message ) ) {
				// phpcs:ignore WordPress.CodeAnalysis.EscapedNotTranslated.Found -- Runtime values returned by the AWS SES XML response (error code + message). Not a translatable string literal; escape only.
				throw new Ses_Exception( esc_html( (string) $ens->Error->Code . ': ' . (string) $ens->Error->Message ) );
			}
			throw new Ses_Exception( esc_html__( 'SES ErrorResponse without detail.', 'doublescale' ) );
		}

		$ns = $xml->children( self::SES_NS );
		if ( 0 === count( $ns ) ) {
			$ns = $xml->children();
		}

		switch ( $action ) {
			case 'SendRawEmail':
				$res = $ns->SendRawEmailResult;
				$mid = isset( $res->MessageId ) ? (string) $res->MessageId : '';
				return new Ses_Result( array( 'MessageId' => $mid ) );

			case 'ListIdentities':
				$list = array();
				if ( isset( $ns->ListIdentitiesResult->Identities ) ) {
					foreach ( $ns->ListIdentitiesResult->Identities->member as $m ) {
						$list[] = (string) $m;
					}
				}
				return new Ses_Result( array( 'Identities' => $list ) );

			case 'GetIdentityVerificationAttributes':
				$attrs = array();
				if ( isset( $ns->GetIdentityVerificationAttributesResult->VerificationAttributes ) ) {
					foreach ( $ns->GetIdentityVerificationAttributesResult->VerificationAttributes->entry as $entry ) {
						$key = (string) $entry->key;
						$val = array();
						foreach ( $entry->value->children() as $child ) {
							$val[ $child->getName() ] = (string) $child;
						}
						$attrs[ $key ] = $val;
					}
				}
				return new Ses_Result( array( 'VerificationAttributes' => $attrs ) );

			case 'GetIdentityDkimAttributes':
				$attrs = array();
				if ( isset( $ns->GetIdentityDkimAttributesResult->DkimAttributes ) ) {
					foreach ( $ns->GetIdentityDkimAttributesResult->DkimAttributes->entry as $entry ) {
						$key = (string) $entry->key;
						$val = array();
						foreach ( $entry->value->children() as $child ) {
							$val[ $child->getName() ] = (string) $child;
						}
						$attrs[ $key ] = $val;
					}
				}
				return new Ses_Result( array( 'DkimAttributes' => $attrs ) );

			case 'SendBulkTemplatedEmail':
				$statuses = array();
				if ( isset( $ns->SendBulkTemplatedEmailResult->Status ) ) {
					foreach ( $ns->SendBulkTemplatedEmailResult->Status->member as $m ) {
						$row = array();
						foreach ( $m->children() as $c ) {
							$row[ $c->getName() ] = (string) $c;
						}
						$statuses[] = $row;
					}
				}
				return new Ses_Result( array( 'Status' => $statuses ) );

			case 'CreateTemplate':
			case 'DeleteTemplate':
			case 'VerifyDomainIdentity':
			case 'VerifyEmailIdentity':
			case 'DeleteIdentity':
				return new Ses_Result( array() );

			default:
				return new Ses_Result( array() );
		}
	}
}

/**
 * Facade matching the subset of Aws\Ses\SesClient methods used by this plugin.
 */
class Ses_Client {

	/** @var Ses_Query_Client */
	private $query;

	/**
	 * @param Ses_Query_Client $query Low-level client.
	 */
	public function __construct( Ses_Query_Client $query ) {
		$this->query = $query;
	}

	/**
	 * @param array<string, mixed> $input RawMessage.Data = raw MIME string.
	 * @return Ses_Result
	 */
	public function sendRawEmail( array $input ) {
		$mime = isset( $input['RawMessage']['Data'] ) ? (string) $input['RawMessage']['Data'] : '';
		$b64  = base64_encode( $mime );
		$res  = $this->query->post(
			array(
				'Action'          => 'SendRawEmail',
				'RawMessage.Data' => $b64,
			)
		);
		$this->throw_if_wp_error( $res );
		return $res;
	}

	/**
	 * @param array<string, mixed> $input SDK-style Template array.
	 * @return Ses_Result
	 */
	public function createTemplate( array $input ) {
		$t      = $input['Template'] ?? array();
		$params = array(
			'Action'                => 'CreateTemplate',
			'Template.TemplateName' => (string) ( $t['TemplateName'] ?? '' ),
			'Template.SubjectPart'  => (string) ( $t['SubjectPart'] ?? '' ),
			'Template.HtmlPart'     => (string) ( $t['HtmlPart'] ?? '' ),
		);
		if ( ! empty( $t['TextPart'] ) ) {
			$params['Template.TextPart'] = (string) $t['TextPart'];
		}
		$res = $this->query->post( $params );
		$this->throw_if_wp_error( $res );
		return $res;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return Ses_Result
	 */
	public function sendBulkTemplatedEmail( array $input ) {
		$params = array(
			'Action'              => 'SendBulkTemplatedEmail',
			'Source'              => (string) ( $input['Source'] ?? '' ),
			'Template'            => (string) ( $input['Template'] ?? '' ),
			'DefaultTemplateData' => (string) ( $input['DefaultTemplateData'] ?? '{}' ),
		);
		$dests  = $input['Destinations'] ?? array();
		$n      = 0;
		foreach ( (array) $dests as $dest ) {
			++$n;
			$to = $dest['Destination']['ToAddresses'][0] ?? '';
			$params[ 'Destinations.member.' . $n . '.Destination.ToAddresses.member.1' ] = (string) $to;
			$params[ 'Destinations.member.' . $n . '.ReplacementTemplateData' ]          = (string) ( $dest['ReplacementTemplateData'] ?? '{}' );
		}
		if ( ! empty( $input['ReplyToAddresses'] ) && is_array( $input['ReplyToAddresses'] ) ) {
			$r = 0;
			foreach ( $input['ReplyToAddresses'] as $addr ) {
				++$r;
				$params[ 'ReplyToAddresses.member.' . $r ] = (string) $addr;
			}
		}
		if ( ! empty( $input['DefaultTags'] ) && is_array( $input['DefaultTags'] ) ) {
			$t = 0;
			foreach ( $input['DefaultTags'] as $tag ) {
				++$t;
				$params[ 'DefaultTags.member.' . $t . '.Name' ]  = (string) ( $tag['Name'] ?? '' );
				$params[ 'DefaultTags.member.' . $t . '.Value' ] = (string) ( $tag['Value'] ?? '' );
			}
		}
		$res = $this->query->post( $params );
		$this->throw_if_wp_error( $res );
		return $res;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return Ses_Result
	 */
	public function deleteTemplate( array $input ) {
		$res = $this->query->post(
			array(
				'Action'       => 'DeleteTemplate',
				'TemplateName' => (string) ( $input['TemplateName'] ?? '' ),
			)
		);
		$this->throw_if_wp_error( $res );
		return $res;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return Ses_Result
	 */
	public function listIdentities( array $input ) {
		$res = $this->query->post(
			array(
				'Action'       => 'ListIdentities',
				'IdentityType' => (string) ( $input['IdentityType'] ?? 'EmailAddress' ),
			)
		);
		$this->throw_if_wp_error( $res );
		return $res;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return Ses_Result
	 */
	public function getIdentityVerificationAttributes( array $input ) {
		$params = array( 'Action' => 'GetIdentityVerificationAttributes' );
		$i      = 0;
		foreach ( (array) ( $input['Identities'] ?? array() ) as $id ) {
			++$i;
			$params[ 'Identities.member.' . $i ] = (string) $id;
		}
		$res = $this->query->post( $params );
		$this->throw_if_wp_error( $res );
		return $res;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return Ses_Result
	 */
	public function getIdentityDkimAttributes( array $input ) {
		$params = array( 'Action' => 'GetIdentityDkimAttributes' );
		$i      = 0;
		foreach ( (array) ( $input['Identities'] ?? array() ) as $id ) {
			++$i;
			$params[ 'Identities.member.' . $i ] = (string) $id;
		}
		$res = $this->query->post( $params );
		$this->throw_if_wp_error( $res );
		return $res;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return Ses_Result
	 */
	public function verifyDomainIdentity( array $input ) {
		$res = $this->query->post(
			array(
				'Action' => 'VerifyDomainIdentity',
				'Domain' => (string) ( $input['Domain'] ?? '' ),
			)
		);
		$this->throw_if_wp_error( $res );
		return $res;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return Ses_Result
	 */
	public function verifyEmailIdentity( array $input ) {
		$res = $this->query->post(
			array(
				'Action'       => 'VerifyEmailIdentity',
				'EmailAddress' => (string) ( $input['EmailAddress'] ?? '' ),
			)
		);
		$this->throw_if_wp_error( $res );
		return $res;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return Ses_Result
	 */
	public function deleteIdentity( array $input ) {
		$res = $this->query->post(
			array(
				'Action'   => 'DeleteIdentity',
				'Identity' => (string) ( $input['Identity'] ?? '' ),
			)
		);
		$this->throw_if_wp_error( $res );
		return $res;
	}

	/**
	 * @param Ses_Result|WP_Error $res
	 */
	private function throw_if_wp_error( $res ) {
		if ( is_wp_error( $res ) ) {
			throw new Ses_Exception( esc_html( $res->get_error_message() ) );
		}
	}
}
