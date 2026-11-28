<?php
/**
 * Safe contract-boundary failure.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Validation;

/**
 * Carries a stable code without exposing schema or provider internals.
 */
final class ContractException extends \RuntimeException {
	/**
	 * Public machine-readable error code.
	 *
	 * @var string
	 */
	private string $error_code;
	/**
	 * Bounded server-authored diagnostic codes, without values or paths.
	 *
	 * @var string[]
	 */
	private array $repair_codes;

	/**
	 * Initialize an expected boundary failure.
	 *
	 * @param string $error_code Stable code.
	 * @param string $message Safe diagnostic.
	 * @param array  $repair_codes Optional server-authored validation codes.
	 */
	public function __construct( string $error_code, string $message, array $repair_codes = array() ) {
		parent::__construct( $message );
		$this->error_code   = $error_code;
		$this->repair_codes = array_slice( array_values( array_unique( array_filter( $repair_codes, static fn( $code ): bool => is_string( $code ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $code ) ) ) ), 0, 12 );
	}

	/**
	 * Return the transport-independent code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/** Return codes only; never native values, schema internals or error prose. */
	public function repair_codes(): array {
		return $this->repair_codes;
	}
}
