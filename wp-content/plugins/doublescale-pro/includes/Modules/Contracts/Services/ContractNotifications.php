<?php
/**
 * Outbound contract emails to customers.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Communication\EmailIdentityResolver;
use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;
use DoubleScale\Pro\Modules\Contracts\Services\ContractContentMergeTags;
use DoubleScale\Modules\Sales\Services\SalesEmailHtml;
use DoubleScale\Modules\Sales\Services\SalesSettings;

/**
 * ContractNotifications service.
 */
final class ContractNotifications {

	/**
	 * Send the contract link to the customer.
	 *
	 * @param ContractModel $contract Contract.
	 * @param string        $custom_message Optional message from the sender.
	 * @return bool
	 */
	public function send_contract( ContractModel $contract, string $custom_message = '' ): bool {
		$to = $this->resolve_recipient_email( $contract );
		if ( '' === $to ) {
			return false;
		}

		$url = ContractUrl::get_public_url( $contract );
		if ( '' === $url ) {
			return false;
		}

		$context = ContractContentMergeTags::automation_context( $contract );
		$resolve = static fn( string $content ): string => ContractContentMergeTags::resolve( $contract, $content );

		$host_user_id = $contract->assigned_user_id ? (int) $contract->assigned_user_id : null;
		$identity     = EmailIdentityResolver::resolve( $host_user_id );

		$contract->loadMissing( 'contact' );
		$customer_name = __( 'there', 'doublescale' );
		if ( $contract->contact ) {
			$name = trim( (string) $contract->contact->first_name . ' ' . (string) $contract->contact->last_name );
			if ( '' !== $name ) {
				$customer_name = $name;
			}
		}

		$subject_tpl = (string) SalesSettings::get( 'contract_email_subject', '' );
		$subject     = trim( SalesEmailHtml::resolve_template( $subject_tpl, $context, 'contract', $resolve ) );
		if ( '' === trim( $subject ) ) {
			$subject = sprintf(
				/* translators: %s: contract subject */
				__( 'Contract: %s', 'doublescale' ),
				(string) $contract->subject
			);
		}

		$intro_tpl  = (string) SalesSettings::get( 'contract_email_intro', '' );
		$intro_html = SalesEmailHtml::resolve_intro_html(
			$custom_message,
			$intro_tpl,
			__( 'Please review the contract below and sign when you are ready.', 'doublescale' ),
			$context,
			'contract',
			$resolve
		);

		$body = $this->build_body( $contract, $customer_name, $url, $intro_html );

		$emails = new Emails();
		$emails->from_address = $identity['from_address'];
		$emails->from_name    = $identity['from_name'];
		$emails->reply_to     = $identity['reply_to'];

		try {
			return (bool) $emails->send( $to, $subject, $body );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Contract email failed',
					array(
						'source'      => 'sales-contract-email',
						'contract_id' => (int) $contract->id,
						'error'       => $e->getMessage(),
					)
				);
			}
			return false;
		}
	}

	/**
	 * Send a signed confirmation email to the customer.
	 *
	 * @param ContractModel $contract Contract.
	 * @return bool
	 */
	public function send_signed_confirmation( ContractModel $contract ): bool {
		$to = $this->resolve_recipient_email( $contract );
		if ( '' === $to ) {
			return false;
		}

		$url = ContractUrl::get_public_url( $contract );
		if ( '' === $url ) {
			return false;
		}

		$context = ContractContentMergeTags::automation_context( $contract );
		$resolve = static fn( string $content ): string => ContractContentMergeTags::resolve( $contract, $content );

		$host_user_id = $contract->assigned_user_id ? (int) $contract->assigned_user_id : null;
		$identity     = EmailIdentityResolver::resolve( $host_user_id );

		$contract->loadMissing( 'contact' );
		$customer_name = __( 'there', 'doublescale' );
		if ( $contract->contact ) {
			$name = trim( (string) $contract->contact->first_name . ' ' . (string) $contract->contact->last_name );
			if ( '' !== $name ) {
				$customer_name = $name;
			}
		}

		$subject_tpl = (string) SalesSettings::get( 'contract_signed_email_subject', '' );
		$subject     = trim( SalesEmailHtml::resolve_template( $subject_tpl, $context, 'contract', $resolve ) );
		if ( '' === trim( $subject ) ) {
			$subject = sprintf(
				/* translators: %s: contract number */
				__( 'Contract signed: %s', 'doublescale' ),
				(string) $contract->contract_number
			);
		}

		$intro_tpl  = (string) SalesSettings::get( 'contract_signed_email_intro', '' );
		$intro_html = SalesEmailHtml::resolve_intro_html(
			'',
			$intro_tpl,
			__( 'Thank you for signing. You can view your signed contract using the link below.', 'doublescale' ),
			$context,
			'contract',
			$resolve
		);

		$body = $this->build_body(
			$contract,
			$customer_name,
			$url,
			$intro_html,
			__( 'View Signed Contract', 'doublescale' )
		);

		$emails = new Emails();
		$emails->from_address = $identity['from_address'];
		$emails->from_name    = $identity['from_name'];
		$emails->reply_to     = $identity['reply_to'];

		try {
			return (bool) $emails->send( $to, $subject, $body );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Contract signed confirmation email failed',
					array(
						'source'      => 'sales-contract-email',
						'contract_id' => (int) $contract->id,
						'error'       => $e->getMessage(),
					)
				);
			}
			return false;
		}
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @return string
	 */
	private function resolve_recipient_email( ContractModel $contract ): string {
		$contract->loadMissing( 'contact' );
		if ( $contract->contact && is_email( (string) $contract->contact->email ) ) {
			return sanitize_email( (string) $contract->contact->email );
		}

		return '';
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @param string        $customer_name Customer display name.
	 * @param string        $url Public contract URL.
	 * @param string        $intro_html Safe HTML intro.
	 * @param string|null   $button_label Optional CTA label.
	 * @return string
	 */
	private function build_body( ContractModel $contract, string $customer_name, string $url, string $intro_html, ?string $button_label = null ): string {
		$formatted_value = sprintf(
			'%1$s %2$s',
			number_format_i18n( (float) $contract->contract_value, 2 ),
			\DoubleScale\Pro\Compat\SettingsCurrency::document_currency( $contract->currency, $contract->sent_at )
		);

		$summary_rows = array(
			array(
				'label' => __( 'Subject', 'doublescale' ),
				'value' => (string) $contract->subject,
			),
			array(
				'label' => __( 'Reference', 'doublescale' ),
				'value' => (string) $contract->contract_number,
			),
			array(
				'label' => __( 'Value', 'doublescale' ),
				'value' => $formatted_value,
			),
		);

		if ( $contract->end_date ) {
			$summary_rows[] = array(
				'label' => __( 'Valid until', 'doublescale' ),
				'value' => (string) $contract->end_date,
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
			esc_html__( 'Contract Summary', 'doublescale' )
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
			esc_html( $button_label ?? __( 'View Contract', 'doublescale' ) )
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
