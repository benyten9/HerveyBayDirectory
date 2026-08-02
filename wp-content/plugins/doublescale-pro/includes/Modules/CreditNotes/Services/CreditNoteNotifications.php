<?php
/**
 * Outbound credit note emails to customers.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Communication\EmailIdentityResolver;
use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Modules\Sales\Services\SalesEmailHtml;
use DoubleScale\Modules\Sales\Services\SalesEmailMergeTags;
use DoubleScale\Modules\Sales\Services\SalesSettings;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;

/**
 * CreditNoteNotifications service.
 */
final class CreditNoteNotifications {

	/**
	 * @param CreditNoteModel $credit_note      Credit note.
	 * @param string          $custom_message Optional message from the sender.
	 * @return bool
	 */
	public function send_credit_note( CreditNoteModel $credit_note, string $custom_message = '' ): bool {
		$to = $this->resolve_recipient_email( $credit_note );
		if ( '' === $to ) {
			return false;
		}

		$url = CreditNoteUrl::get_public_url( $credit_note );
		if ( '' === $url ) {
			return false;
		}

		$context = SalesEmailMergeTags::for_document( $credit_note, 'credit_note_id' );

		$host_user_id = $credit_note->sale_agent_user_id ? (int) $credit_note->sale_agent_user_id : null;
		$identity     = EmailIdentityResolver::resolve( $host_user_id );

		$customer_name = $this->resolve_customer_name( $credit_note );
		$subject_tpl   = (string) SalesSettings::get( 'credit_note_email_subject', '' );
		$subject       = trim( SalesEmailHtml::resolve_template( $subject_tpl, $context, 'credit_note' ) );
		if ( '' === trim( $subject ) ) {
			$subject = sprintf(
				/* translators: %s: credit note number */
				__( 'Credit Note: %s', 'doublescale' ),
				(string) $credit_note->credit_note_number
			);
		}

		$intro_tpl  = (string) SalesSettings::get( 'credit_note_email_intro', '' );
		$intro_html = SalesEmailHtml::resolve_intro_html(
			$custom_message,
			$intro_tpl,
			__( 'Please review your credit note and keep it for your records.', 'doublescale' ),
			$context,
			'credit_note'
		);

		$body = $this->build_body( $credit_note, $customer_name, $url, $intro_html );

		$emails = new Emails();
		$emails->from_address = $identity['from_address'];
		$emails->from_name    = $identity['from_name'];
		$emails->reply_to     = $identity['reply_to'];

		try {
			return (bool) $emails->send( $to, $subject, $body );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Credit note email failed',
					array(
						'source'         => 'sales-credit-note-email',
						'credit_note_id' => (int) $credit_note->id,
						'error'          => $e->getMessage(),
					)
				);
			}
			return false;
		}
	}

	/**
	 * @param CreditNoteModel $credit_note Credit note.
	 * @return string
	 */
	private function resolve_recipient_email( CreditNoteModel $credit_note ): string {
		$credit_note->loadMissing( 'contact' );
		if ( $credit_note->contact && is_email( (string) $credit_note->contact->email ) ) {
			return sanitize_email( (string) $credit_note->contact->email );
		}

		return '';
	}

	/**
	 * @param CreditNoteModel $credit_note Credit note.
	 * @return string
	 */
	private function resolve_customer_name( CreditNoteModel $credit_note ): string {
		$credit_note->loadMissing( 'contact' );
		if ( $credit_note->contact ) {
			$name = trim( (string) $credit_note->contact->first_name . ' ' . (string) $credit_note->contact->last_name );
			if ( '' !== $name ) {
				return $name;
			}
		}

		$billing = trim( (string) ( $credit_note->billing_address ?? '' ) );
		if ( '' !== $billing ) {
			$lines = preg_split( '/\r\n|\r|\n/', $billing );
			if ( is_array( $lines ) && ! empty( $lines[0] ) ) {
				return trim( (string) $lines[0] );
			}
		}

		return __( 'there', 'doublescale' );
	}

	/**
	 * @param CreditNoteModel $credit_note      Credit note.
	 * @param string          $customer_name    Customer display name.
	 * @param string          $url              Public URL.
	 * @param string          $intro_html       Safe HTML intro.
	 * @return string
	 */
	private function build_body( CreditNoteModel $credit_note, string $customer_name, string $url, string $intro_html ): string {
		$formatted_total = sprintf(
			'%1$s %2$s',
			\number_format_i18n( (float) $credit_note->total, 2 ),
			\DoubleScale\Core\Settings\Settings::document_currency( $credit_note->currency, $credit_note->sent_at )
		);

		$html  = '<div style="font-family:Helvetica,Arial,sans-serif;color:#1a202c;">';
		$html .= sprintf(
			'<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#1a202c;">%1$s <strong>%2$s</strong>,</p>',
			esc_html__( 'Hi', 'doublescale' ),
			esc_html( $customer_name )
		);
		$html .= sprintf(
			'<div style="margin:0 0 24px;font-size:14px;line-height:1.7;color:#4a5568;">%s</div>',
			$intro_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via SalesEmailHtml.
		);
		$html .= sprintf(
			'<p style="margin:0 0 8px;font-size:13px;color:#718096;">%1$s: <strong>%2$s</strong></p>',
			esc_html__( 'Credit amount', 'doublescale' ),
			esc_html( $formatted_total )
		);
		$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:24px auto;border-collapse:collapse;">';
		$html .= '<tr><td align="center" bgcolor="#4c6fff" style="border-radius:8px;background-color:#4c6fff;">';
		$html .= sprintf(
			'<a href="%1$s" target="_blank" style="display:inline-block;padding:14px 32px;font-size:14px;font-weight:600;line-height:1;color:#ffffff;text-decoration:none;font-family:Helvetica,Arial,sans-serif;">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'View Credit Note', 'doublescale' )
		);
		$html .= '</td></tr></table>';
		$html .= '</div>';

		return $html;
	}
}
