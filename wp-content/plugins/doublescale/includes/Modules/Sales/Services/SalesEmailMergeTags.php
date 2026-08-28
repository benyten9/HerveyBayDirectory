<?php
/**
 * Resolve {{group:slug}} merge tags in sales customer emails.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;

/**
 * SalesEmailMergeTags helper.
 */
final class SalesEmailMergeTags {

	/**
	 * Replace merge tags when a contact context is available.
	 *
	 * @param string                         $content Content with optional merge tags.
	 * @param AutomationContactModel|null    $context Automation contact context.
	 * @return string
	 */
	public static function resolve( string $content, ?AutomationContactModel $context ): string {
		if ( '' === $content || false === strpos( $content, '{{' ) ) {
			return $content;
		}

		if ( null === $context ) {
			return $content;
		}

		return MergeTagsManager::instance()->process_merge_tags( $content, $context );
	}

	/**
	 * Resolve merge tags and normalize inline HTML for document sections.
	 *
	 * @param string|null                $content Rich text or plain text.
	 * @param AutomationContactModel|null $context Merge-tag context.
	 * @return string|null
	 */
	public static function resolve_rich_text( ?string $content, ?AutomationContactModel $context ): ?string {
		if ( null === $content || '' === $content ) {
			return null;
		}

		$resolved = self::resolve( $content, $context );

		return self::wrap_inline_rich_text( $resolved );
	}

	/**
	 * Resolve merge tags in document section rows.
	 *
	 * @param array<int, array<string, mixed>> $sections Section rows.
	 * @param AutomationContactModel|null    $context  Merge-tag context.
	 * @return array<int, array{title: string, body: string}>
	 */
	public static function resolve_sections( array $sections, ?AutomationContactModel $context ): array {
		if ( empty( $sections ) ) {
			return array();
		}

		$resolved = array();

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$title = isset( $section['title'] ) ? (string) $section['title'] : '';
			$body  = isset( $section['body'] ) ? (string) $section['body'] : '';

			$resolved[] = array(
				'title'    => self::resolve( $title, $context ),
				'body'     => self::wrap_inline_rich_text( self::resolve( $body, $context ) ),
				'position' => isset( $section['position'] ) && 'before_items' === $section['position']
					? 'before_items'
					: 'after_totals',
			);
		}

		return $resolved;
	}

	/**
	 * Wrap plain inline HTML so document section styles apply consistently.
	 *
	 * @param string $html Sanitized HTML fragment.
	 * @return string
	 */
	public static function wrap_inline_rich_text( string $html ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		if ( preg_match( '/<(p|div|ul|ol|h[1-6]|table|blockquote|br)\b/i', $html ) ) {
			return $html;
		}

		return '<p>' . $html . '</p>';
	}

	/**
	 * Build merge-tag context from a linked contact.
	 *
	 * @param ContactModel|null $contact Linked contact, if any.
	 * @param array<string, int|string> $data Optional context data (e.g. document ids).
	 * @return AutomationContactModel
	 */
	public static function context_from_contact( ?ContactModel $contact, array $data = array() ): AutomationContactModel {
		$context             = new AutomationContactModel();
		$context->contact_id = $contact ? (int) $contact->id : 0;

		if ( ! empty( $data ) ) {
			$context->data = $data;
		}

		if ( $contact instanceof ContactModel ) {
			$context->setRelation( 'contact', $contact );
		}

		return $context;
	}

	/**
	 * Load contact relation only for persisted documents (unit stubs skip DB).
	 *
	 * @param object $document Proposal, invoice, or similar Eloquent model.
	 * @return void
	 */
	public static function ensure_document_contact_loaded( object $document ): void {
		if ( ! method_exists( $document, 'loadMissing' ) || ! method_exists( $document, 'relationLoaded' ) ) {
			return;
		}

		if ( $document->relationLoaded( 'contact' ) ) {
			return;
		}

		if ( empty( $document->contact_id ) ) {
			return;
		}

		if ( method_exists( $document, 'exists' ) && ! $document->exists ) {
			return;
		}

		$document->loadMissing( 'contact' );
	}

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @return AutomationContactModel
	 */
	public static function for_proposal( ProposalModel $proposal ): AutomationContactModel {
		self::ensure_document_contact_loaded( $proposal );

		return self::context_from_contact(
			$proposal->contact,
			array( 'proposal_id' => (int) $proposal->id )
		);
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return AutomationContactModel
	 */
	public static function for_invoice( InvoiceModel $invoice ): AutomationContactModel {
		self::ensure_document_contact_loaded( $invoice );

		$data = array( 'invoice_id' => (int) $invoice->id );
		if ( ! empty( $invoice->proposal_id ) ) {
			$data['proposal_id'] = (int) $invoice->proposal_id;
		}

		return self::context_from_contact( $invoice->contact, $data );
	}

	/**
	 * @param object $document      Credit note, contract, or subscription model.
	 * @param string $id_key        Context data key, e.g. credit_note_id.
	 * @return AutomationContactModel
	 */
	public static function for_document( object $document, string $id_key ): AutomationContactModel {
		self::ensure_document_contact_loaded( $document );

		// Eloquent models expose id/contact via __get — property_exists() is always false.
		$contact = isset( $document->contact ) ? $document->contact : null;

		$id = 0;
		if ( isset( $document->id ) ) {
			$id = (int) $document->id;
		} elseif ( method_exists( $document, 'getKey' ) ) {
			$id = (int) $document->getKey();
		}

		return self::context_from_contact(
			$contact instanceof ContactModel ? $contact : null,
			array( $id_key => $id )
		);
	}

	/**
	 * Build merge-tag context for sales-rep in-app / email notifications.
	 *
	 * @param array<string, mixed> $context     Proposal, invoice, or contract + event.
	 * @param string               $subcategory Notification subcategory key.
	 * @return AutomationContactModel
	 */
	public static function for_rep_notification( array $context, string $subcategory ): AutomationContactModel {
		$event = isset( $context['event'] ) ? (string) $context['event'] : '';
		$data  = array(
			'rep_event'       => $event,
			'rep_subcategory' => $subcategory,
		);

		$proposal = $context['proposal'] ?? null;
		if ( $proposal instanceof ProposalModel ) {
			$proposal->loadMissing( 'contact' );
			$data['proposal_id']      = (int) $proposal->id;
			$data['sales_admin_link'] = admin_url( 'admin.php?page=doublescale&path=sales/proposals/' . (int) $proposal->id );

			return self::context_from_contact( $proposal->contact, $data );
		}

		$invoice = $context['invoice'] ?? null;
		if ( $invoice instanceof InvoiceModel ) {
			$invoice->loadMissing( 'contact' );
			$data['invoice_id']       = (int) $invoice->id;
			$data['sales_admin_link'] = admin_url( 'admin.php?page=doublescale&path=sales/invoices/' . (int) $invoice->id );

			return self::context_from_contact( $invoice->contact, $data );
		}

		$contract = $context['contract'] ?? null;
		if ( $contract instanceof ContractModel ) {
			if ( method_exists( $contract, 'loadMissing' ) ) {
				$contract->loadMissing( 'contact' );
			}
			$contact = isset( $contract->contact ) ? $contract->contact : null;
			$data['contract_id']      = isset( $contract->id ) ? (int) $contract->id : (int) $contract->getKey();
			$data['sales_admin_link'] = admin_url( 'admin.php?page=doublescale&path=sales/contracts/' . (int) $data['contract_id'] );

			return self::context_from_contact(
				$contact instanceof ContactModel ? $contact : null,
				$data
			);
		}

		return self::context_from_contact( null, $data );
	}
}
