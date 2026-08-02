<?php
/**
 * Mailers Class.
 *
 *  @since 1.0.0
 *
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Providers\SendLayer\SendLayer;
use DoubleScale\Modules\Smtp\Providers\Mailgun\Mailgun;
use DoubleScale\Modules\Smtp\Providers\SMTPcom\SMTPcom;
use DoubleScale\Modules\Smtp\Providers\SparkPost\SparkPost;
use DoubleScale\Modules\Smtp\Providers\SMTP\SMTP;
use DoubleScale\Modules\Smtp\Providers\Defaultmailer\Defaultmailer;
use DoubleScale\Modules\Smtp\Providers\ElasticEmail\ElasticEmail;
use DoubleScale\Modules\Smtp\Providers\SendGrid\SendGrid;
use DoubleScale\Modules\Smtp\Providers\Gmail\Gmail;
use DoubleScale\Modules\Smtp\Providers\PostMark\PostMark;
use DoubleScale\Modules\Smtp\Providers\SendInBlue\SendInBlue;
use DoubleScale\Modules\Smtp\Providers\Loops\Loops;
use DoubleScale\Modules\Smtp\Providers\MailerSend\MailerSend;
use DoubleScale\Modules\Smtp\Providers\Mailjet\Mailjet;
use DoubleScale\Modules\Smtp\Providers\SMTP2GO\SMTP2GO;
use DoubleScale\Modules\Smtp\Providers\Outlook\Outlook;
use DoubleScale\Modules\Smtp\Providers\Zoho\Zoho;
use DoubleScale\Modules\Smtp\Providers\Aws\Aws;
use DoubleScale\Modules\Smtp\Providers\Mandrill\Mandrill;
use DoubleScale\Modules\Smtp\Providers\SocketLabs\SocketLabs;

/**
 * Mailers Class.
 *
 * @since 1.0.0
 */
final class Mailers {

	/**
	 * Class Instance.
	 *
	 * @since 1.0.0
	 *
	 * @var smtp
	 */
	private static $instance;

	/**
	 * smtp Instance.
	 *
	 * Instantiates or reuses an instance of smtp.
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @return self - Single instance
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->load_mailers();
	}

	/**
	 * Load Mailers.
	 *
	 * @since 1.0.0
	 */
	private function load_mailers() {
		$mailers = self::get_mailers();

		foreach ( $mailers as $key => $mailer ) {
			$mailer::instance();
		}
	}

	/**
	 * Get mailer provider.
	 *
	 * @since 1.0.0
	 *
	 * @return \DoubleScale\Modules\Smtp\Mailer\Mailer[]
	 */
	public static function get_mailers() {
		$mailers = array(
			'sendlayer'    => SendLayer::class,
			'mailgun'      => Mailgun::class,
			'smtpcom'      => SMTPcom::class,
			'sparkpost'    => SparkPost::class,
			'smtp'         => SMTP::class,
			'phpmailer'    => Defaultmailer::class,
			'elasticemail' => ElasticEmail::class,
			'sendgrid'     => SendGrid::class,
			'gmail'        => Gmail::class,
			'postmark'     => PostMark::class,
			'sendinblue'   => SendInBlue::class,
			'loops'        => Loops::class,
			'mailersend'   => MailerSend::class,
			'mailjet'      => Mailjet::class,
			'smtp2go'      => SMTP2GO::class,
			'outlook'      => Outlook::class,
			'zoho'         => Zoho::class,
			'aws'          => Aws::class,
			'mandrill'     => Mandrill::class,
			'socketlabs'   => SocketLabs::class,
		);

		return apply_filters( 'doublescale_smtp_mailers', $mailers );
	}

	/**
	 * Get mailer provider.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Key.
	 *
	 * @return \DoubleScale\Modules\Smtp\Mailer\Provider\Provider
	 */
	public static function get_mailer( $key ) {
		$mailers = self::get_mailers();

		if ( isset( $mailers[ $key ] ) ) {
			$mailer = $mailers[ $key ];
			return $mailer::instance();
		}

		return false;
	}
}
