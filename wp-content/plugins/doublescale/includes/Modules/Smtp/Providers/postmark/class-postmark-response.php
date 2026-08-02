<?php
/**
 * Postmark batch response entry.
 *
 * Exposes the `->Message`, `->MessageID`, `->ErrorCode`, `->To`,
 * `->SubmittedAt` properties the provider reads from the SDK's
 * DynamicResponseModel, backed by the decoded JSON entry.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\PostMark;

defined( 'ABSPATH' ) || exit;

/**
 * Postmark response entry.
 *
 * @since 1.0.0
 */
class Postmark_Response {

	/**
	 * Decoded response fields.
	 *
	 * @var array
	 */
	protected $data;

	/**
	 * Constructor.
	 *
	 * @param array $data Decoded response entry.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Magic accessor mirroring the SDK model's property access
	 * (e.g. `$result->Message`, `$result->MessageID`).
	 *
	 * @param string $name Field name.
	 * @return mixed|null
	 */
	public function __get( $name ) {
		return $this->data[ $name ] ?? null;
	}

	/**
	 * @param string $name Field name.
	 * @return bool
	 */
	public function __isset( $name ) {
		return isset( $this->data[ $name ] );
	}
}
