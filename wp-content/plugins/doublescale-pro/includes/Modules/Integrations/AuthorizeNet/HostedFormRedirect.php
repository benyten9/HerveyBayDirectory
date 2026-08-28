<?php
/**
 * Bridges Accept Hosted's POST-only handoff onto the shared redirect flow.
 *
 * Accept Hosted will not accept a GET: the form token must be POSTed to
 * Authorize.Net. The shared frontend redirect path only ever does
 * `window.location.href = redirect_url`, so this endpoint returns a minimal
 * self-submitting form that performs the POST on the customer's behalf.
 *
 * The token is single-use, expires in 15 minutes, and is stored server-side
 * against a short-lived nonce so it never travels in a URL (which would leak it
 * into browser history, logs, and referrers).
 *
 * @package DoubleScale\Pro\Modules\Integrations\AuthorizeNet
 */

namespace DoubleScale\Pro\Modules\Integrations\AuthorizeNet;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Services\InvoiceUrl;
use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Pro\Modules\Pro\Payment\AuthorizeNetGateway;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\InvoicePayableSubject;

/**
 * HostedFormRedirect class.
 */
class HostedFormRedirect {

	/**
	 * Query arg carrying the handoff id.
	 */
	public const HANDOFF_ARG = 'ds_authnet_handoff';

	/**
	 * Transient prefix for stored handoffs.
	 */
	private const TRANSIENT_PREFIX = 'ds_authnet_handoff_';

	/**
	 * Authorize.Net expires the token after 15 minutes; expire ours sooner so a
	 * stale handoff cannot be replayed.
	 */
	private const TTL = 600;

	/**
	 * Store a token for handoff and return the id to redirect to.
	 *
	 * @param string $token     Hosted payment page token.
	 * @param string $form_url  Authorize.Net form post URL.
	 * @param int    $invoice_id Invoice id (for logging only).
	 * @return string Handoff id.
	 */
	public static function store( string $token, string $form_url, int $invoice_id ): string {
		$handoff_id = wp_generate_password( 24, false, false );

		set_transient(
			self::TRANSIENT_PREFIX . $handoff_id,
			array(
				'token'      => $token,
				'form_url'   => $form_url,
				'invoice_id' => $invoice_id,
			),
			self::TTL
		);

		return $handoff_id;
	}

	/**
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'doublescale/v1',
			'/integrations/authorize-net/handoff',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'doublescale/v1',
			'/integrations/authorize-net/return/(?P<hash>[a-f0-9]{32})',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'handle_return' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The handoff URL the customer is redirected to.
	 *
	 * @param string $handoff_id Handoff id.
	 * @return string
	 */
	public static function url( string $handoff_id ): string {
		return add_query_arg( 'id', $handoff_id, self::public_rest_url( 'doublescale/v1/integrations/authorize-net/handoff' ) );
	}

	/**
	 * Customer return/cancel URL given to Accept Hosted.
	 *
	 * Accept Hosted renders a blank "Order Summary" page when `url` or
	 * `cancelUrl` contain an ampersand. The public invoice link already has
	 * `?doublescale_invoice_hash=…`, so appending `ds_authorize_net_return=1`
	 * would introduce `&`. This path-only REST URL is then bounced onto the
	 * invoice with the return marker on our side, where `&` is safe.
	 *
	 * @param string $invoice_hash 32-char invoice hash.
	 * @return string
	 */
	public static function return_url( string $invoice_hash ): string {
		return self::public_rest_url(
			'doublescale/v1/integrations/authorize-net/return/' . rawurlencode( $invoice_hash )
		);
	}

	/**
	 * Emit a self-submitting form that POSTs the token to Authorize.Net.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return void
	 */
	public function handle( \WP_REST_Request $request ) {
		$handoff_id = (string) $request->get_param( 'id' );
		$key        = self::TRANSIENT_PREFIX . $handoff_id;
		$stored     = get_transient( $key );

		// Single use: a token must not be replayable from history or a back button.
		delete_transient( $key );

		if ( ! is_array( $stored ) || empty( $stored['token'] ) || empty( $stored['form_url'] ) ) {
			$this->render_expired();
			return;
		}

		$this->render_form( (string) $stored['form_url'], (string) $stored['token'] );
	}

	/**
	 * Bounce Authorize.Net's return/cancel onto the invoice page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return void
	 */
	public function handle_return( \WP_REST_Request $request ) {
		$hash    = (string) $request->get_param( 'hash' );
		$invoice = class_exists( InvoiceModel::class ) ? InvoiceModel::get_by_hash( $hash ) : null;
		$target  = \home_url( '/' );

		if ( $invoice ) {
			try {
				$manager = GatewayManager::instance();
				$gateway = $manager->get( GatewayManager::CONTEXT_INVOICE, 'authorize_net' );
				if ( $gateway instanceof AuthorizeNetGateway ) {
					$gateway->confirm( new InvoicePayableSubject( $invoice ) );
				}
			} catch ( \Throwable $e ) {
				doublescale_get_logger()->warning(
					'Authorize.Net return confirm failed',
					array(
						'code'    => 'authorize_net_return_confirm_failed',
						'message' => $e->getMessage(),
					)
				);
			}

			$public = InvoiceUrl::get_public_url( $invoice );
			if ( '' !== $public ) {
				$target = add_query_arg( AuthorizeNetGateway::RETURN_QUERY_ARG, '1', $public );
			}
		}

		nocache_headers();
		wp_safe_redirect( $target, 303 );
		exit;
	}

	/**
	 * @param string $form_url Authorize.Net form post URL.
	 * @param string $token    Hosted payment page token.
	 * @return void
	 */
	private function render_form( string $form_url, string $token ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Referrer-Policy: no-referrer' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in build_form_html().
		echo self::build_form_html( $form_url, $token );

		exit;
	}

	/**
	 * The self-submitting form markup.
	 *
	 * Split out from {@see render_form()} so the emitted HTML — the one place a
	 * live payment token is written into a response — can be asserted without
	 * the exit() that ends the request.
	 *
	 * @param string $form_url Authorize.Net form post URL.
	 * @param string $token    Hosted payment page token.
	 * @return string
	 */
	public static function build_form_html( string $form_url, string $token ): string {
		$title = esc_html__( 'Redirecting to secure payment…', 'doublescale' );

		$html  = '<!doctype html><html><head><meta charset="utf-8">';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
		$html .= '<meta name="referrer" content="no-referrer">';
		$html .= '<title>' . $title . '</title></head>';
		$html .= '<body style="font-family:system-ui,sans-serif;padding:2rem;text-align:center;color:#334">';
		$html .= '<p>' . $title . '</p>';
		$html .= sprintf(
			'<form id="ds-authnet" method="post" action="%s">',
			esc_url( $form_url )
		);
		$html .= sprintf(
			'<input type="hidden" name="token" value="%s">',
			esc_attr( $token )
		);
		$html .= sprintf(
			'<noscript><button type="submit">%s</button></noscript>',
			esc_html__( 'Continue to payment', 'doublescale' )
		);
		$html .= '</form>';
		$html .= '<script>document.getElementById("ds-authnet").submit();</script>';
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * @return void
	 */
	private function render_expired(): void {
		nocache_headers();
		status_header( 410 );
		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<!doctype html><html><head><meta charset="utf-8">';
		echo '<title>' . esc_html__( 'Payment session expired', 'doublescale' ) . '</title></head>';
		echo '<body style="font-family:system-ui,sans-serif;padding:2rem;text-align:center;color:#334"><p>';
		echo esc_html__(
			'This payment session has expired or was already used. Please return to the invoice and start the payment again.',
			'doublescale'
		);
		echo '</p></body></html>';

		exit;
	}

	/**
	 * @param string $path REST path under the doublescale namespace.
	 * @return string
	 */
	private static function public_rest_url( string $path ): string {
		$path = ltrim( $path, '/' );

		if ( \defined( 'DOUBLESCALE_PUBLIC_REST_URL' ) && \DOUBLESCALE_PUBLIC_REST_URL ) {
			return \trailingslashit( \DOUBLESCALE_PUBLIC_REST_URL ) . $path;
		}

		return \rest_url( $path );
	}
}
