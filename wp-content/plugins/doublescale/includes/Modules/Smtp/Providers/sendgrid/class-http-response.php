<?php
/**
 * SendGrid HTTP response value object.
 *
 * Mirrors the `statusCode()` / `body()` surface of the SDK's response so the
 * provider's send paths need no changes.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\SendGrid;

defined( 'ABSPATH' ) || exit;

/**
 * SendGrid HTTP response.
 *
 * @since 1.0.0
 */
class Http_Response {

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	protected $status_code;

	/**
	 * Response body.
	 *
	 * @var string
	 */
	protected $body;

	/**
	 * Constructor.
	 *
	 * @param int    $status_code HTTP status code (0 for transport error).
	 * @param string $body        Response body.
	 */
	public function __construct( $status_code, $body ) {
		$this->status_code = (int) $status_code;
		$this->body        = (string) $body;
	}

	/**
	 * HTTP status code.
	 *
	 * @return int
	 */
	public function statusCode() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK-compatible surface.
		return $this->status_code;
	}

	/**
	 * Response body.
	 *
	 * @return string
	 */
	public function body() {
		return $this->body;
	}
}
