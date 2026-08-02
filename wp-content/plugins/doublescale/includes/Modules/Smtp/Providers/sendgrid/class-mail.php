<?php
/**
 * SendGrid Mail builder (SDK drop-in).
 *
 * Accumulates the same fields the sendgrid/sendgrid `\SendGrid\Mail\Mail`
 * builder did — via the identical setter surface the provider calls — and
 * serialises them to the `POST /v3/mail/send` request body. Replacing the SDK
 * lets the scoped autoloader stop force-loading the SendGrid class tree on
 * every request.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\SendGrid;

defined( 'ABSPATH' ) || exit;

/**
 * Mail builder.
 *
 * @since 1.0.0
 */
class Mail {

	/**
	 * From address: { email, name }.
	 *
	 * @var array
	 */
	protected $from = array();

	/**
	 * Reply-to address: { email, name }.
	 *
	 * @var array
	 */
	protected $reply_to = array();

	/**
	 * Global subject.
	 *
	 * @var string
	 */
	protected $subject = '';

	/**
	 * Content parts: [ { type, value }, ... ].
	 *
	 * @var array
	 */
	protected $content = array();

	/**
	 * Custom headers keyed by name.
	 *
	 * @var array
	 */
	protected $headers = array();

	/**
	 * Categories.
	 *
	 * @var array
	 */
	protected $categories = array();

	/**
	 * Attachments: [ { content, type, filename }, ... ].
	 *
	 * @var array
	 */
	protected $attachments = array();

	/**
	 * Tracking settings payload.
	 *
	 * @var array
	 */
	protected $tracking_settings = array();

	/**
	 * Mail settings payload (e.g. spam_check).
	 *
	 * @var array
	 */
	protected $mail_settings = array();

	/**
	 * Personalization objects.
	 *
	 * @var Personalization[]
	 */
	protected $personalizations = array();

	/**
	 * The single implicit personalization used by the non-batch (per-recipient
	 * setter) path, created lazily the first time a recipient is added.
	 *
	 * @var Personalization|null
	 */
	protected $default_personalization = null;

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK-compatible camelCase surface.

	/**
	 * @param string $email Sender email.
	 * @param string $name  Sender name.
	 */
	public function setFrom( $email, $name = null ) {
		$this->from = array( 'email' => (string) $email );
		if ( ! empty( $name ) ) {
			$this->from['name'] = (string) $name;
		}
	}

	/**
	 * @param string $email Reply-to email.
	 * @param string $name  Reply-to name.
	 */
	public function setReplyTo( $email, $name = null ) {
		$this->reply_to = array( 'email' => (string) $email );
		if ( ! empty( $name ) ) {
			$this->reply_to['name'] = (string) $name;
		}
	}

	/**
	 * @param string $subject Subject.
	 */
	public function setSubject( $subject ) {
		$this->subject = (string) $subject;
	}

	/**
	 * @param string $type  MIME type (text/plain, text/html).
	 * @param string $value Content.
	 */
	public function addContent( $type, $value ) {
		$this->content[] = array(
			'type'  => (string) $type,
			'value' => (string) $value,
		);
	}

	/**
	 * @param string $name  Header name.
	 * @param string $value Header value.
	 */
	public function addHeader( $name, $value ) {
		$this->headers[ (string) $name ] = (string) $value;
	}

	/**
	 * @param string $category Category.
	 */
	public function addCategory( $category ) {
		$this->categories[] = (string) $category;
	}

	/**
	 * @param string $content  Base64 content.
	 * @param string $type     MIME type.
	 * @param string $filename Filename.
	 */
	public function addAttachment( $content, $type = null, $filename = null ) {
		$attachment = array( 'content' => (string) $content );
		if ( null !== $type ) {
			$attachment['type'] = (string) $type;
		}
		if ( null !== $filename ) {
			$attachment['filename'] = (string) $filename;
		}
		$this->attachments[] = $attachment;
	}

	/**
	 * @param bool $enable       Enable click tracking.
	 * @param bool $enable_text  Track plain-text links.
	 */
	public function setClickTracking( $enable, $enable_text = false ) {
		$this->tracking_settings['click_tracking'] = array(
			'enable'      => (bool) $enable,
			'enable_text' => (bool) $enable_text,
		);
	}

	/**
	 * @param bool $enable Enable open tracking.
	 */
	public function setOpenTracking( $enable ) {
		$this->tracking_settings['open_tracking'] = array( 'enable' => (bool) $enable );
	}

	/**
	 * @param bool   $enable    Enable spam check.
	 * @param int    $threshold Spam threshold (1-10).
	 * @param string $post_to   URL to POST spam reports to.
	 */
	public function setSpamCheck( $enable, $threshold = null, $post_to = null ) {
		$spam = array( 'enable' => (bool) $enable );
		if ( null !== $threshold ) {
			$spam['threshold'] = (int) $threshold;
		}
		if ( null !== $post_to ) {
			$spam['post_to_url'] = (string) $post_to;
		}
		$this->mail_settings['spam_check'] = $spam;
	}

	/**
	 * @param string $email Recipient email.
	 * @param string $name  Recipient name.
	 */
	public function addTo( $email, $name = null ) {
		$this->default_personalization()->addTo( new To( $email, $name ) );
	}

	/**
	 * @param string $email CC email.
	 * @param string $name  CC name.
	 */
	public function addCc( $email, $name = null ) {
		$this->default_personalization()->addCc( new To( $email, $name ) );
	}

	/**
	 * @param string $email BCC email.
	 * @param string $name  BCC name.
	 */
	public function addBcc( $email, $name = null ) {
		$this->default_personalization()->addBcc( new To( $email, $name ) );
	}

	/**
	 * @param Personalization $personalization Personalization block.
	 */
	public function addPersonalization( Personalization $personalization ) {
		$this->personalizations[] = $personalization;
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Lazily create the implicit personalization for the per-recipient path.
	 *
	 * @return Personalization
	 */
	protected function default_personalization() {
		if ( null === $this->default_personalization ) {
			$this->default_personalization = new Personalization();
			$this->personalizations[]      = $this->default_personalization;
		}
		return $this->default_personalization;
	}

	/**
	 * Serialise to the `POST /v3/mail/send` request body.
	 *
	 * @return array
	 */
	public function to_array() {
		$payload = array(
			'personalizations' => array_map(
				static function ( Personalization $p ) {
					return $p->to_array();
				},
				$this->personalizations
			),
			'from'             => $this->from,
			'subject'          => $this->subject,
			'content'          => $this->content,
		);

		if ( ! empty( $this->reply_to ) ) {
			$payload['reply_to'] = $this->reply_to;
		}
		if ( ! empty( $this->headers ) ) {
			$payload['headers'] = $this->headers;
		}
		if ( ! empty( $this->categories ) ) {
			$payload['categories'] = array_values( array_unique( $this->categories ) );
		}
		if ( ! empty( $this->attachments ) ) {
			$payload['attachments'] = $this->attachments;
		}
		if ( ! empty( $this->tracking_settings ) ) {
			$payload['tracking_settings'] = $this->tracking_settings;
		}
		if ( ! empty( $this->mail_settings ) ) {
			$payload['mail_settings'] = $this->mail_settings;
		}

		return $payload;
	}
}
