<?php
/**
 * Postmark attachment (SDK drop-in for \Postmark\Models\PostmarkAttachment).
 *
 * Only the `fromRawData()` factory is used by the provider. Serialises to the
 * `{ Name, Content, ContentType }` shape Postmark's email API expects.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\PostMark;

defined( 'ABSPATH' ) || exit;

/**
 * Postmark attachment.
 *
 * @since 1.0.0
 */
class PostmarkAttachment {

	/**
	 * Base64-encoded content.
	 *
	 * @var string
	 */
	protected $content;

	/**
	 * Attachment filename.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * MIME content type.
	 *
	 * @var string
	 */
	protected $content_type;

	/**
	 * Constructor.
	 *
	 * @param string $content      Base64-encoded content.
	 * @param string $name         Filename.
	 * @param string $content_type MIME type.
	 */
	public function __construct( $content, $name, $content_type ) {
		$this->content      = (string) $content;
		$this->name         = (string) $name;
		$this->content_type = (string) $content_type;
	}

	/**
	 * Build an attachment from raw (un-encoded) file data.
	 *
	 * @since 1.0.0
	 *
	 * @param string $data         Raw file contents.
	 * @param string $name         Filename.
	 * @param string $content_type MIME type.
	 * @return self
	 */
	public static function fromRawData( $data, $name, $content_type ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK-compatible factory.
		return new self( base64_encode( (string) $data ), $name, $content_type );
	}

	/**
	 * Serialise to the Postmark attachment payload.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'Name'        => $this->name,
			'Content'     => $this->content,
			'ContentType' => $this->content_type,
		);
	}
}
