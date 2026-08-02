<?php
/**
 * Outbound invoice emails to customers.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Communication\EmailIdentityResolver;
use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Sales\Services\SalesEmailHtml;
use DoubleScale\Modules\Sales\Services\SalesEmailMergeTags;
use DoubleScale\Modules\Sales\Services\SalesSettings;

/**
 * InvoiceNotifications service.
 */
final class InvoiceNotifications {

	/**
	 * Send the invoice link to the customer.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @param string       $custom_message Optional message from the sender.
	 * @return bool
	 */
	public function send_invoice( InvoiceModel $invoice, string $custom_message = '' ): bool {
		$to = $this->resolve_recipient_email( $invoice );
		if ( '' === $to ) {
			return false;
		}

		$url = InvoiceUrl::get_public_url( $invoice );
		if ( '' === $url ) {
			return false;
		}

		$context = SalesEmailMergeTags::for_invoice( $invoice );

		$host_user_id = $invoice->sale_agent_user_id ? (int) $invoice->sale_agent_user_id : null;
		$identity     = EmailIdentityResolver::resolve( $host_user_id );

		$customer_name = $this->resolve_customer_name( $invoice );
		$subject_tpl   = (string) SalesSettings::get( 'invoice_email_subject', '' );
		$subject       = trim( SalesEmailHtml::resolve_template( $subject_tpl, $context, 'invoice' ) );
		if ( '' === trim( $subject ) ) {
			$subject = sprintf(
				/* translators: %s: invoice number */
				__( 'Invoice: %s', 'doublescale' ),
				(string) $invoice->invoice_number
			);
		}

		$intro_tpl  = (string) SalesSettings::get( 'invoice_email_intro', '' );
		$intro_html = SalesEmailHtml::resolve_intro_html(
			$custom_message,
			$intro_tpl,
			__( 'Please review your invoice and pay the balance due when ready.', 'doublescale' ),
			$context,
			'invoice'
		);

		$body = $this->build_body( $invoice, $customer_name, $url, $intro_html );

		$emails = new Emails();
		$emails->from_address = $identity['from_address'];
		$emails->from_name    = $identity['from_name'];
		$emails->reply_to     = $identity['reply_to'];

		try {
			return (bool) $emails->send( $to, $subject, $body );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Invoice email failed',
					array(
						'source'     => 'sales-invoice-email',
						'invoice_id' => (int) $invoice->id,
						'error'      => $e->getMessage(),
					)
				);
			}
			return false;
		}
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return string
	 */
	private function resolve_recipient_email( InvoiceModel $invoice ): string {
		$invoice->loadMissing( 'contact' );
		if ( $invoice->contact && is_email( (string) $invoice->contact->email ) ) {
			return sanitize_email( (string) $invoice->contact->email );
		}

		return '';
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return string
	 */
	private function resolve_customer_name( InvoiceModel $invoice ): string {
		$invoice->loadMissing( 'contact' );
		if ( $invoice->contact ) {
			$name = trim( (string) $invoice->contact->first_name . ' ' . (string) $invoice->contact->last_name );
			if ( '' !== $name ) {
				return $name;
			}
		}

		$billing = trim( (string) ( $invoice->billing_address ?? '' ) );
		if ( '' !== $billing ) {
			$lines = preg_split( '/\r\n|\r|\n/', $billing );
			if ( is_array( $lines ) && ! empty( $lines[0] ) ) {
				return trim( (string) $lines[0] );
			}
		}

		return __( 'there', 'doublescale' );
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param string       $customer_name Customer display name.
	 * @param string       $url Public invoice URL.
	 * @param string       $intro_html Safe HTML intro.
	 * @return string
	 */
	private function build_body( InvoiceModel $invoice, string $customer_name, string $url, string $intro_html ): string {
		$balance = max( 0, round( (float) $invoice->total - (float) $invoice->amount_paid, 2 ) );
		$formatted_total = sprintf(
			'%1$s %2$s',
			number_format_i18n( $balance, 2 ),
			\DoubleScale\Core\Settings\Settings::document_currency( $invoice->currency, $invoice->sent_at )
		);

		$summary_rows = array(
			array(
				'label' => __( 'Reference', 'doublescale' ),
				'value' => (string) $invoice->invoice_number,
			),
			array(
				'label' => __( 'Balance Due', 'doublescale' ),
				'value' => $formatted_total,
			),
		);

		if ( $invoice->due_date ) {
			$summary_rows[] = array(
				'label' => __( 'Due Date', 'doublescale' ),
				'value' => (string) $invoice->due_date,
			);
		}

		$summary_html = '';
		foreach ( $summary_rows as $row ) {
			$summary_html .= sprintf(
				'<tr>'
				. '<td style="padding:8px 0;color:#718096;font-size:13px;font-family:Helvetica,Arial,sans-serif;width:34%%;vertical-align:top;">%1$s</td>'
				. '<td style="padding:8px 0;color:#1a202c;font-size:13px;font-family:Helvetica,Arial,sans-serif;font-weight:600;vertical-align:top;">%2$s</td>'
				. '</tr>',
				esc_html( $row['label'] ),
				esc_html( $row['value'] )
			);
		}

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

		$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 28px;border-collapse:collapse;background-color:#f7fafc;border:1px solid #edf2f7;border-top:4px solid #4c6fff;border-radius:8px;">';
		$html .= '<tr><td style="padding:18px 20px;">';
		$html .= sprintf(
			'<p style="margin:0 0 12px;font-size:12px;line-height:1.4;color:#4c6fff;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;">%s</p>',
			esc_html__( 'Invoice Summary', 'doublescale' )
		);
		$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">';
		$html .= $summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped values.
		$html .= '</table>';
		$html .= '</td></tr></table>';

		$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 24px;border-collapse:collapse;">';
		$html .= '<tr><td align="center" bgcolor="#4c6fff" style="border-radius:8px;background-color:#4c6fff;">';
		$html .= sprintf(
			'<a href="%1$s" target="_blank" style="display:inline-block;padding:14px 32px;font-size:14px;font-weight:600;line-height:1;color:#ffffff;text-decoration:none;font-family:Helvetica,Arial,sans-serif;">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'View Invoice', 'doublescale' )
		);
		$html .= '</td></tr></table>';

		$html .= sprintf(
			'<p style="margin:0 0 8px;font-size:12px;line-height:1.6;color:#718096;">%s</p>',
			esc_html__( 'If the button does not work, copy and paste this link into your browser:', 'doublescale' )
		);
		$html .= sprintf(
			'<p style="margin:0;font-size:12px;line-height:1.6;word-break:break-all;"><a href="%1$s" style="color:#4c6fff;text-decoration:underline;">%1$s</a></p>',
			esc_url( $url )
		);
		$html .= '</div>';

		return $html;
	}
}
