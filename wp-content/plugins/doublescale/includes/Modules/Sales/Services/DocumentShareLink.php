<?php
/**
 * Builds WhatsApp share payloads (recipient, message, deep link) for sales documents.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Validators\PhoneValidator;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Documents\Services\InvoiceUrl;
use DoubleScale\Modules\Documents\Services\ProposalUrl;

/**
 * DocumentShareLink helper.
 *
 * The customer-facing link is the existing hash-based public URL, so nothing
 * here mints tokens or exposes ids. Sending is deliberately split in two: this
 * service only *prepares* the share, and the caller decides whether the
 * document is subsequently marked as sent.
 */
final class DocumentShareLink {

	/**
	 * Supported document types, matching the doublescale_sales_send_gate arg.
	 */
	public const TYPES = array( 'invoice', 'proposal', 'credit_note', 'contract' );

	/**
	 * Build the WhatsApp share payload for a document.
	 *
	 * @param object $document Invoice, proposal, credit note, or contract model.
	 * @param string $type     One of self::TYPES.
	 * @param string $message  Optional custom note from the admin.
	 * @return array{phone: string, text: string, url: string, link: string}
	 */
	public static function build( object $document, string $type, string $message = '' ): array {
		$url   = self::public_url( $document, $type );
		$phone = self::recipient_phone( $document );
		$text  = self::compose_text( $document, $type, $message, $url );

		$payload = array(
			'phone' => $phone,
			'text'  => $text,
			'url'   => $url,
		);

		$payload['link'] = self::deep_link( $payload );

		return $payload;
	}

	/**
	 * Build the wa.me click-to-chat deep link for a payload.
	 *
	 * An empty phone is not an error: wa.me without a recipient opens the
	 * contact picker, which is the sane fallback for a contact with no number.
	 *
	 * @param array{phone?: string, text?: string} $payload Share payload.
	 * @return string
	 */
	public static function deep_link( array $payload ): string {
		$phone = isset( $payload['phone'] ) ? (string) $payload['phone'] : '';
		$text  = isset( $payload['text'] ) ? (string) $payload['text'] : '';

		// Digits only, so the path segment needs no escaping.
		$base = 'https://wa.me/' . preg_replace( '/\D+/', '', $phone );

		// add_query_arg() urlencodes the value; pre-encoding would double-escape it.
		return '' === $text ? $base : add_query_arg( 'text', $text, $base );
	}

	/**
	 * Resolve the customer-facing page URL for a document.
	 *
	 * Credit note and contract URL services live in Pro, so they are resolved by
	 * class name rather than imported.
	 *
	 * @param object $document Document model.
	 * @param string $type     Document type.
	 * @return string Empty when the shortcode page is missing.
	 */
	public static function public_url( object $document, string $type ): string {
		switch ( $type ) {
			case 'invoice':
				return $document instanceof InvoiceModel ? InvoiceUrl::get_public_url( $document ) : '';

			case 'proposal':
				return $document instanceof ProposalModel ? ProposalUrl::get_public_url( $document ) : '';

			case 'credit_note':
				return self::pro_public_url( '\\DoubleScale\\Pro\\Modules\\CreditNotes\\Services\\CreditNoteUrl', $document );

			case 'contract':
				return self::pro_public_url( '\\DoubleScale\\Pro\\Modules\\Contracts\\Services\\ContractUrl', $document );
		}

		return '';
	}

	/**
	 * Whether the shortcode page backing this document type exists.
	 *
	 * Mirrors the guard the email send handlers already apply, so both channels
	 * fail with the same message instead of producing a half-built link.
	 *
	 * @param string $type Document type.
	 * @return bool
	 */
	public static function has_public_page( string $type ): bool {
		switch ( $type ) {
			case 'invoice':
				return '' !== InvoiceUrl::get_page_url();

			case 'proposal':
				return '' !== ProposalUrl::get_page_url();

			case 'credit_note':
				return self::pro_page_exists( '\\DoubleScale\\Pro\\Modules\\CreditNotes\\Services\\CreditNoteUrl' );

			case 'contract':
				return self::pro_page_exists( '\\DoubleScale\\Pro\\Modules\\Contracts\\Services\\ContractUrl' );
		}

		return false;
	}

	/**
	 * Digits-only recipient number for the document's contact.
	 *
	 * Prefers the dedicated WhatsApp column and falls back to the general phone.
	 * wa.me rejects "+" and separators, so the E.164 value is reduced to digits.
	 *
	 * @param object $document Document model.
	 * @return string Empty when the contact has no usable number.
	 */
	public static function recipient_phone( object $document ): string {
		$contact = self::contact_of( $document );
		if ( ! $contact instanceof ContactModel ) {
			return '';
		}

		$candidates = array(
			(string) $contact->whatsapp_phone,
			(string) $contact->phone,
		);

		foreach ( $candidates as $candidate ) {
			if ( '' === trim( $candidate ) ) {
				continue;
			}

			$e164   = PhoneValidator::to_e164( $candidate, (string) $contact->country );
			$digits = preg_replace( '/\D+/', '', (string) ( $e164 ?? $candidate ) );

			if ( is_string( $digits ) && '' !== $digits ) {
				return $digits;
			}
		}

		return '';
	}

	/**
	 * Compose the plain-text message body.
	 *
	 * The link is appended once, at the end — it is never also passed as a
	 * separate query argument.
	 *
	 * @param object $document Document model.
	 * @param string $type     Document type.
	 * @param string $message  Optional custom note.
	 * @param string $url      Public document URL.
	 * @return string
	 */
	private static function compose_text( object $document, string $type, string $message, string $url ): string {
		$body = SalesEmailMergeTags::resolve( self::default_template( $type ), self::merge_context( $document, $type ) );

		$parts = array( self::tidy( $body ) );

		$message = trim( $message );
		if ( '' !== $message ) {
			$parts[] = $message;
		}

		if ( '' !== $url ) {
			$parts[] = $url;
		}

		$text = implode( "\n\n", array_filter( $parts, 'strlen' ) );

		/**
		 * Filter the composed WhatsApp message for a sales document.
		 *
		 * @param string $text     Message body, plain text.
		 * @param string $type     Document type.
		 * @param object $document Document model.
		 */
		return (string) apply_filters( 'doublescale_sales_whatsapp_message', $text, $type, $document );
	}

	/**
	 * Drop label lines whose merge tag resolved to nothing.
	 *
	 * A document without a due date would otherwise ship a dangling
	 * "Due date:" line. Lines ending in a colon carry no information on their
	 * own, so they are removed rather than sent empty.
	 *
	 * @param string $body Resolved template body.
	 * @return string
	 */
	private static function tidy( string $body ): string {
		$lines = preg_split( '/\R/', $body );
		if ( ! is_array( $lines ) ) {
			return trim( $body );
		}

		// The closing call-to-action also ends in a colon but introduces the
		// link appended after this body, so only earlier lines are candidates.
		$last_content = -1;
		foreach ( $lines as $index => $line ) {
			if ( '' !== trim( $line ) ) {
				$last_content = $index;
			}
		}

		$kept = array();
		foreach ( $lines as $index => $line ) {
			$trimmed = rtrim( $line );

			// "Label:" with nothing after it — the tag resolved empty.
			if ( $index !== $last_content && 1 === preg_match( '/^[^:]{1,40}:$/u', trim( $trimmed ) ) ) {
				continue;
			}

			$kept[] = $trimmed;
		}

		// Collapse the blank runs left behind by removed lines.
		$text = preg_replace( '/\n{3,}/', "\n\n", implode( "\n", $kept ) );

		return trim( (string) $text );
	}

	/**
	 * Default plain-text template per document type.
	 *
	 * Leads with the document number, amount and date so the recipient sees the
	 * essentials in the WhatsApp preview without opening the link. Every tag is
	 * dropped to an empty string when it cannot resolve, so blank lines are
	 * collapsed afterwards by {@see self::tidy()}.
	 *
	 * @param string $type Document type.
	 * @return string
	 */
	private static function default_template( string $type ): string {
		switch ( $type ) {
			case 'invoice':
				return __(
					"Hello {{contact:first_name}},\n\nYour invoice {{sales:invoice_number}} for {{sales:invoice_total}} is ready.\nDue date: {{sales:invoice_due_date}}\n\nYou can view and pay it here:",
					'doublescale'
				);

			case 'proposal':
				return __(
					"Hello {{contact:first_name}},\n\nYour proposal {{sales:proposal_number}} for {{sales:proposal_total}} is ready.\nOpen till: {{sales:proposal_open_till}}\n\nYou can review it here:",
					'doublescale'
				);

			case 'credit_note':
				return __(
					"Hello {{contact:first_name}},\n\nCredit note {{sales:credit_note_number}} for {{sales:credit_note_total}} has been issued for your account.\nDate: {{sales:credit_note_date}}\n\nYou can view it here:",
					'doublescale'
				);

			case 'contract':
				return __(
					"Hello {{contact:first_name}},\n\nYour contract {{sales:contract_number}} for {{sales:contract_value}} is ready to review and sign.\nEnd date: {{sales:contract_end_date}}\n\nYou can open it here:",
					'doublescale'
				);
		}

		return '';
	}

	/**
	 * Merge-tag context for the document type.
	 *
	 * @param object $document Document model.
	 * @param string $type     Document type.
	 * @return \DoubleScale\Modules\Automations\Models\AutomationContactModel
	 */
	private static function merge_context( object $document, string $type ) {
		if ( $document instanceof InvoiceModel ) {
			return SalesEmailMergeTags::for_invoice( $document );
		}

		if ( $document instanceof ProposalModel ) {
			return SalesEmailMergeTags::for_proposal( $document );
		}

		return SalesEmailMergeTags::for_document( $document, $type . '_id' );
	}

	/**
	 * Contact attached to a document, loading the relation when needed.
	 *
	 * @param object $document Document model.
	 * @return ContactModel|null
	 */
	private static function contact_of( object $document ): ?ContactModel {
		if ( method_exists( $document, 'loadMissing' ) ) {
			$document->loadMissing( 'contact' );
		}

		// Eloquent exposes relations via __get, so isset() is the only safe probe.
		$contact = isset( $document->contact ) ? $document->contact : null;

		return $contact instanceof ContactModel ? $contact : null;
	}

	/**
	 * Resolve a public URL through a Pro URL service, when Pro is active.
	 *
	 * @param string $class    Fully-qualified Pro URL service.
	 * @param object $document Document model.
	 * @return string
	 */
	private static function pro_public_url( string $class, object $document ): string {
		if ( ! class_exists( $class ) || ! method_exists( $class, 'get_public_url' ) ) {
			return '';
		}

		return (string) call_user_func( array( $class, 'get_public_url' ), $document );
	}

	/**
	 * Whether a Pro URL service resolves a shortcode page.
	 *
	 * @param string $class Fully-qualified Pro URL service.
	 * @return bool
	 */
	private static function pro_page_exists( string $class ): bool {
		if ( ! class_exists( $class ) || ! method_exists( $class, 'get_page_url' ) ) {
			return false;
		}

		return '' !== (string) call_user_func( array( $class, 'get_page_url' ) );
	}
}
