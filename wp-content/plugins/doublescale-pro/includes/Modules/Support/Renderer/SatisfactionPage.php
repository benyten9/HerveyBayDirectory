<?php
/**
 * The page a rating link opens: `?ds_rate=<token>&score=<1-5>`.
 *
 * Opening it saves NOTHING: mail scanners open every link in an email on
 * their own. The page shows the five faces with the clicked one chosen, a box
 * for a few words, and a button; the click posts to
 * `support/public/satisfaction` with the token, and only that saves.
 *
 * It shows only the ticket's number, never its title or messages: a link can
 * be forwarded. Not cached, not indexed, and the address (it holds the token)
 * is never sent on as a referrer.
 *
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Renderer;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Support\Services\SatisfactionLinks;

/**
 * SatisfactionPage class.
 */
final class SatisfactionPage {

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_render' ), 0 );
	}

	/**
	 * @return void
	 */
	public static function maybe_render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed token is the proof; nothing is saved here.
		if ( ! isset( $_GET['ds_rate'] ) ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Content-Type: text/html; charset=utf-8' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( (string) $_GET['ds_rate'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$score = isset( $_GET['score'] ) ? absint( $_GET['score'] ) : 0;
		echo self::render( $token, $score ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped below.
		exit;
	}

	/**
	 * The page. Nothing here writes anything.
	 *
	 * @param string $token Token from the link.
	 * @param int    $score Face clicked in the email (0: none).
	 * @return string
	 */
	public static function render( string $token, int $score ): string {
		$ticket = SatisfactionLinks::verify( $token );
		$site   = (string) get_bloginfo( 'name' );
		$head   = '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . esc_html( $site ) . '</title>'
			. '<style>body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f6f7f9;color:#1f2937}main{max-width:520px;margin:48px auto;padding:28px;background:#fff;border:1px solid #e5e7eb;border-radius:16px}h1{font-size:20px;margin:0 0 6px}p{color:#6b7280;margin:0 0 18px}.faces{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.faces label{flex:1;min-width:76px;text-align:center;padding:10px 4px;border:1px solid #e5e7eb;border-radius:12px;cursor:pointer;font-size:13px}.faces input{position:absolute;opacity:0}.faces span{display:block;font-size:30px}.faces input:checked+span+em,.faces label:has(input:checked){border-color:#3a3a99;background:#f1f1fb}textarea{width:100%;box-sizing:border-box;min-height:90px;padding:10px;border:1px solid #d1d5db;border-radius:10px;font:inherit}button{margin-top:14px;padding:10px 18px;border:0;border-radius:10px;background:#3a3a99;color:#fff;font:inherit;cursor:pointer}button[disabled]{opacity:.6}.done{color:#047857}.error{color:#b91c1c}</style></head><body><main>';
		$foot   = '</main></body></html>';

		if ( is_wp_error( $ticket ) ) {
			return $head . '<h1>' . esc_html( $site ) . '</h1><p class="error" role="alert">' . esc_html( $ticket->get_error_message() ) . '</p>' . $foot;
		}

		$faces = '';
		foreach ( SatisfactionLinks::faces() as $value => $face ) {
			$faces .= '<label><input type="radio" name="score" value="' . (int) $value . '"' . ( $value === $score ? ' checked' : '' ) . ' required><span aria-hidden="true">' . $face[0] . '</span>' . esc_html( $face[1] ) . '</label>';
		}
		$endpoint = esc_url_raw( rest_url( 'doublescale/v1/support/public/satisfaction' ) );

		return $head
			/* translators: %d: ticket number */
			. '<h1>' . esc_html( sprintf( __( 'How did we do on request #%d?', 'doublescale' ), (int) $ticket->id ) ) . '</h1>'
			. '<p>' . esc_html__( 'Choose a face and send it. A few words help us even more.', 'doublescale' ) . '</p>'
			. '<form id="ds-rate"><fieldset style="border:0;padding:0;margin:0"><legend style="position:absolute;left:-9999px">' . esc_html__( 'Your rating', 'doublescale' ) . '</legend><div class="faces">' . $faces . '</div></fieldset>'
			. '<label for="ds-rate-comment" style="display:block;margin-bottom:6px;font-size:14px">' . esc_html__( 'Tell us more (optional)', 'doublescale' ) . '</label>'
			. '<textarea id="ds-rate-comment" name="comment" maxlength="1000"></textarea>'
			. '<button type="submit">' . esc_html__( 'Send my rating', 'doublescale' ) . '</button>'
			. '<p id="ds-rate-status" role="status" style="margin-top:12px"></p></form>'
			. '<script>(function(){var f=document.getElementById("ds-rate"),s=document.getElementById("ds-rate-status");f.addEventListener("submit",function(e){e.preventDefault();var c=f.querySelector("input[name=score]:checked");if(!c){return;}var b=f.querySelector("button");b.disabled=true;fetch(' . wp_json_encode( $endpoint, JSON_HEX_TAG | JSON_HEX_AMP ) . ',{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({token:' . wp_json_encode( $token, JSON_HEX_TAG | JSON_HEX_AMP ) . ',score:Number(c.value),comment:f.comment.value})}).then(function(r){return r.json().then(function(d){return{ok:r.ok,d:d};});}).then(function(x){s.className=x.ok?"done":"error";s.textContent=x.ok?' . wp_json_encode( __( 'Thank you! Your rating was sent.', 'doublescale' ), JSON_HEX_TAG | JSON_HEX_AMP ) . ':(x.d&&x.d.message?x.d.message:' . wp_json_encode( __( 'Something went wrong. Please try again.', 'doublescale' ), JSON_HEX_TAG | JSON_HEX_AMP ) . ');b.disabled=x.ok;}).catch(function(){s.className="error";s.textContent=' . wp_json_encode( __( 'Something went wrong. Please try again.', 'doublescale' ), JSON_HEX_TAG | JSON_HEX_AMP ) . ';b.disabled=false;});});})();</script>'
			. $foot;
	}
}
