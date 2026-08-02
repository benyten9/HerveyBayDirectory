<?php
/**
 * SMTP module: routes wp_mail() through configured providers.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Modules\Smtp\Alerts\SmtpAlertDispatcher;
use DoubleScale\Modules\Smtp\EmailLog\EmailLogHandler;
use DoubleScale\Modules\Smtp\Multisite\SmtpMultisite;
use DoubleScale\Modules\Smtp\Override\PHPMailerOverride;
use DoubleScale\Modules\Smtp\Providers\Mailers;
use DoubleScale\Modules\Smtp\Reports\SummaryEmail;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'smtp';
	}

	public function label(): string {
		return __( 'SMTP', 'doublescale' );
	}

	public function description(): string {
		return __( 'Routes wp_mail() through configured providers (SendGrid, SES, Gmail, etc.) with smart routing and fallback.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return true;
	}

	public function dependencies(): array {
		return array( 'core' );
	}

	public function register( Container $container ): void {
		$container->singleton(
			Mailers::class,
			static function () {
				return Mailers::instance();
			}
		);
		$container->singleton(
			EmailLogHandler::class,
			static function () {
				return EmailLogHandler::get_instance();
			}
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		add_action( 'plugins_loaded', array( $this, 'replace_phpmailer' ), 20 );
		// wp_mail() may instantiate WP_PHPMailer after our early global; ensure each send uses our override with full message state copied.
		add_action( 'phpmailer_init', array( $this, 'ensure_phpmailer_override' ), 1 );

		$container->get( Mailers::class );
		SmtpAlertDispatcher::boot();
		SummaryEmail::boot();
		SmtpMultisite::boot();
	}

	public function replace_phpmailer(): void {
		global $phpmailer;
		if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer', false ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		}
		$phpmailer = new PHPMailerOverride();
	}

	/**
	 * After wp_mail() populates the mailer, ensure we use PHPMailerOverride so bundled SMTP routing runs.
	 *
	 * Core may set {@see global $phpmailer} to {@see \WP_PHPMailer} when the global was unset; replacing
	 * without copying would drop recipients and body.
	 *
	 * @since 1.0.0
	 */
	public function ensure_phpmailer_override(): void {
		global $phpmailer;

		if ( ! isset( $phpmailer ) || ! is_object( $phpmailer ) ) {
			return;
		}

		if ( $phpmailer instanceof PHPMailerOverride ) {
			return;
		}

		if ( ! $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
			return;
		}

		$source      = $phpmailer;
		$replacement = $this->copy_phpmailer_state_to_override( $source );

		$phpmailer = $replacement;
	}

	/**
	 * Copy all instance state from a core mailer (e.g. {@see \WP_PHPMailer}) into {@see PHPMailerOverride}
	 * so {@see \PHPMailer\PHPMailer\PHPMailer::send()} uses bundled SMTP (recipients live in protected properties).
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $source Source instance wp_mail() fully configured.
	 * @return PHPMailerOverride
	 */
	private function copy_phpmailer_state_to_override( \PHPMailer\PHPMailer\PHPMailer $source ): PHPMailerOverride {
		$replacement = new PHPMailerOverride( true );

		$ref_src = new \ReflectionObject( $source );
		$ref_dst = new \ReflectionObject( $replacement );

		foreach ( $ref_src->getProperties() as $property ) {
			if ( $property->isStatic() ) {
				continue;
			}
			$property->setAccessible( true );
			$name  = $property->getName();
			$value = $property->getValue( $source );

			if ( ! $ref_dst->hasProperty( $name ) ) {
				continue;
			}
			$dest_prop = $ref_dst->getProperty( $name );
			if ( $dest_prop->isStatic() ) {
				continue;
			}
			$dest_prop->setAccessible( true );
			try {
				$dest_prop->setValue( $replacement, $value );
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return $replacement;
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestSmtpSettingsController::class,
			Rest\Controllers\RestSmtpEmailLogController::class,
			Rest\Controllers\RestSmtpSendTestController::class,
			Rest\Controllers\RestSmtpAlertsTestController::class,
		);
	}
}
