<?php
/**
 * Invoice and proposal abilities.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityInput;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\Abilities\AbilityScope;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Documents\Constants\DocumentTemplate;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Documents\Services\SendInvoice;
use DoubleScale\Modules\Documents\Services\SendProposal;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Modules\Sales\Services\SalesNumbering;
use DoubleScale\Modules\Sales\Services\SalesSettings;

/**
 * Gate 3 lives here.
 *
 * Two different owner columns, which is the trap in this module: invoices are
 * scoped by `sale_agent_user_id` and proposals by `assigned_user_id`. The
 * scoping condition itself is copied verbatim from the REST controllers
 * (RestInvoiceController / RestProposalController) so both surfaces agree.
 */
final class DocumentAbilities {

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( self::class, 'can_view_sales' );

		return array(
			'doublescale/list-invoices'     => array(
				'module_slug'      => 'documents',
				'label'            => __( 'List invoices', 'doublescale' ),
				'description'      => __( 'List invoices with number, status, contact, total, and due date. Amounts are returned with their currency code. If your sales scope is "own" you see only invoices assigned to you — check get-context first.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'status'     => array(
							'type'        => 'string',
							'description' => 'Filter by invoice status.',
							'enum'        => InvoiceStatus::all(),
						),
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Only invoices for this contact.',
						),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'     => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_invoices' ),
			),

			'doublescale/get-invoice'       => array(
				'module_slug'      => 'documents',
				'label'            => __( 'Get invoice', 'doublescale' ),
				'description'      => __( 'One invoice with line items, totals, amount paid, and balance due.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Invoice id.',
						),
					),
					'required'   => array( 'id' ),
				),
				'execute_callback' => array( self::class, 'get_invoice' ),
			),

			'doublescale/list-proposals'    => array(
				'module_slug'      => 'documents',
				'label'            => __( 'List proposals', 'doublescale' ),
				'description'      => __( 'List proposals with number, subject, status, contact, and total. If your sales scope is "own" you see only proposals assigned to you.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'status'     => array(
							'type'        => 'string',
							'description' => 'Filter by proposal status.',
							'enum'        => ProposalStatus::all(),
						),
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Only proposals for this contact.',
						),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'     => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_proposals' ),
			),

			'doublescale/get-proposal'      => array(
				'module_slug'      => 'documents',
				'label'            => __( 'Get proposal', 'doublescale' ),
				'description'      => __( 'One proposal with line items and totals.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Proposal id.',
						),
					),
					'required'   => array( 'id' ),
				),
				'execute_callback' => array( self::class, 'get_proposal' ),
			),

			'doublescale/get-sales-summary' => array(
				'module_slug'      => 'documents',
				'label'            => __( 'Get sales summary', 'doublescale' ),
				'description'      => __( 'Invoice counts and totals grouped by status, broken down per currency. Respects your sales scope: if it is "own", these are your figures only, not the whole site.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'date_from' => array(
							'type'        => 'string',
							'description' => 'Inclusive lower bound on invoice_date, YYYY-MM-DD.',
						),
						'date_to'   => array(
							'type'        => 'string',
							'description' => 'Inclusive upper bound on invoice_date, YYYY-MM-DD.',
						),
					),
				),
				'execute_callback' => array( self::class, 'get_sales_summary' ),
			),

			'doublescale/update-invoice'    => array(
				'module_slug'      => 'documents',
				'label'            => __( 'Update invoice details', 'doublescale' ),
				'description'      => __( 'Change an invoice\'s due date, client note, or terms. Line items, discounts, and totals cannot be changed here — editing those alters what the customer owes, so it stays a human decision in the invoice editor. This tool never sends anything.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => 'Invoice id.',
						),
						'due_date'    => array(
							'type'        => 'string',
							'description' => 'New due date as YYYY-MM-DD.',
						),
						'client_note' => array(
							'type'        => 'string',
							'description' => 'Note shown to the customer on the invoice.',
						),
						'terms'       => array(
							'type'        => 'string',
							'description' => 'Payment terms text.',
						),
					),
					'required'   => array( 'id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						// Overwrites text fields only; no figure and no record
						// is destroyed, and the document is not sent.
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'update_invoice' ),
			),

			'doublescale/update-proposal'   => array(
				'module_slug'      => 'documents',
				'label'            => __( 'Update proposal details', 'doublescale' ),
				'description'      => __( 'Change a proposal\'s subject, expiry date, or terms. Line items, discounts, and totals cannot be changed here — editing those alters what is being quoted. This tool never sends anything.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array(
							'type'        => 'integer',
							'description' => 'Proposal id.',
						),
						'subject'   => array(
							'type'        => 'string',
							'description' => 'New subject line.',
						),
						'open_till' => array(
							'type'        => 'string',
							'description' => 'New expiry date as YYYY-MM-DD.',
						),
						'terms'     => array(
							'type'        => 'string',
							'description' => 'Terms text.',
						),
					),
					'required'   => array( 'id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'update_proposal' ),
			),

			'doublescale/send-invoice'      => array(
				'module_slug'      => 'documents',
				'label'            => __( 'Send an invoice to the customer', 'doublescale' ),
				'description'      => __( 'Email an invoice to the customer it belongs to. THIS EMAILS THE CUSTOMER IMMEDIATELY and cannot be recalled, so only call it when the user has explicitly asked to send this invoice. Sending also moves a draft to unpaid, stamps the sent date, and locks the currency and the billing and issuer details to what they are now — so send only once the invoice is final. Paid invoices cannot be sent. The recipient is always the invoice\'s own contact and cannot be overridden.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'Invoice id to send.',
						),
						'message' => array(
							'type'        => 'string',
							'description' => 'Optional covering note included in the email.',
						),
					),
					'required'   => array( 'id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						// The record survives, but the send freezes snapshots
						// and cannot be undone.
						'destructive'   => false,
						// Calling twice sends a second email.
						'idempotent'    => false,
						'openWorldHint' => true,
					),
				),
				'execute_callback' => array( self::class, 'send_invoice' ),
			),

			'doublescale/create-invoice'    => array(
				'module_slug'      => 'documents',
				'label'            => __( 'Create a draft invoice', 'doublescale' ),
				'description'      => __( 'Create a draft invoice for an existing contact. Line items are allowed here because the document has not been sent yet. This tool never emails the customer — use send-invoice after the user has confirmed the draft. Status is always draft. The assigned sales agent is the connecting user.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contact_id'  => array(
							'type'        => 'integer',
							'description' => 'Contact this invoice belongs to.',
						),
						'line_items'  => array(
							'type'        => 'array',
							'description' => 'Line items. Each accepts: description, qty (or quantity), rate. Amount is qty × rate. Totals are computed on save.',
						),
						'invoice_date' => array(
							'type'        => 'string',
							'description' => 'Invoice date as YYYY-MM-DD. Defaults to today.',
						),
						'due_date'    => array(
							'type'        => 'string',
							'description' => 'Due date as YYYY-MM-DD.',
						),
						'client_note' => array(
							'type'        => 'string',
							'description' => 'Note shown to the customer on the invoice.',
						),
						'terms'       => array(
							'type'        => 'string',
							'description' => 'Payment terms text.',
						),
					),
					'required'   => array( 'contact_id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => false,
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'create_invoice' ),
			),

			'doublescale/create-proposal'   => array(
				'module_slug'      => 'documents',
				'label'            => __( 'Create a draft proposal', 'doublescale' ),
				'description'      => __( 'Create a draft proposal for an existing contact. Line items are allowed here because the document has not been sent yet. This tool never emails the customer — use send-proposal after the user has confirmed the draft. Status is always draft. The assigned user is the connecting user.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Contact this proposal belongs to.',
						),
						'subject'    => array(
							'type'        => 'string',
							'description' => 'Proposal subject line.',
						),
						'line_items' => array(
							'type'        => 'array',
							'description' => 'Line items. Each accepts: description, qty (or quantity), rate. Amount is qty × rate.',
						),
						'open_till'  => array(
							'type'        => 'string',
							'description' => 'Expiry date as YYYY-MM-DD.',
						),
						'terms'      => array(
							'type'        => 'string',
							'description' => 'Terms text.',
						),
					),
					'required'   => array( 'contact_id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => false,
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'create_proposal' ),
			),

			'doublescale/send-proposal'     => array(
				'module_slug'      => 'documents',
				'label'            => __( 'Send a proposal to the customer', 'doublescale' ),
				'description'      => __( 'Email a proposal to the customer it belongs to. THIS EMAILS THE CUSTOMER IMMEDIATELY and cannot be recalled, so only call it when the user has explicitly asked to send this proposal. Sending also moves a draft to sent, stamps the sent date, and locks the currency and party details to what they are now. Declined proposals cannot be sent. The recipient is always the proposal\'s own contact and cannot be overridden.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'Proposal id to send.',
						),
						'message' => array(
							'type'        => 'string',
							'description' => 'Optional covering note included in the email.',
						),
					),
					'required'   => array( 'id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => false,
						'openWorldHint' => true,
					),
				),
				'execute_callback' => array( self::class, 'send_proposal' ),
			),
		);
	}

	/**
	 * Fields an agent may change on a sales document.
	 *
	 * Deliberately excludes everything financial. `line_items`, `discount_type`,
	 * `discount_value`, and `adjustment` all feed TotalsCalculator inside the
	 * model's saving() hook, so touching any of them silently rewrites
	 * subtotal/total_tax/total — changing what a customer owes on a document
	 * that may already be in their inbox, with no undo anywhere in the product.
	 *
	 * `status` is excluded too: transitions like "sent" and "paid" fire
	 * automation triggers and are the surface that reaches customers.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function editable_fields(): array {
		return array(
			'invoice'  => array( 'due_date', 'client_note', 'terms' ),
			'proposal' => array( 'subject', 'open_till', 'terms' ),
		);
	}

	/**
	 * Update the non-financial fields of an invoice.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update_invoice( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'id' ) ),
				AbilityInput::id( $input['id'] ?? null, 'id' ),
				AbilityInput::date( $input['due_date'] ?? null, 'due_date' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$invoice = InvoiceModel::query()->where( 'id', (int) $input['id'] )->first();
		if ( ! $invoice ) {
			return AbilityResult::not_found( __( 'No invoice found with that id.', 'doublescale' ) );
		}

		$forbidden = AbilityScope::assert_owns(
			$invoice,
			'sale_agent_user_id',
			self::sees_all_sales(),
			__( 'You do not have permission to access this invoice.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		return self::apply_document_update( $invoice, 'invoice', $input, 'invoice_id' );
	}

	/**
	 * Update the non-financial fields of a proposal.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update_proposal( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'id' ) ),
				AbilityInput::id( $input['id'] ?? null, 'id' ),
				AbilityInput::date( $input['open_till'] ?? null, 'open_till' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$proposal = ProposalModel::query()->where( 'id', (int) $input['id'] )->first();
		if ( ! $proposal ) {
			return AbilityResult::not_found( __( 'No proposal found with that id.', 'doublescale' ) );
		}

		$forbidden = AbilityScope::assert_owns(
			$proposal,
			'assigned_user_id',
			self::sees_all_sales(),
			__( 'You do not have permission to access this proposal.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		return self::apply_document_update( $proposal, 'proposal', $input, 'proposal_id' );
	}

	/**
	 * Email an invoice to its customer.
	 *
	 * Delegates the entire send to {@see SendInvoice}, which the REST endpoint
	 * also uses. That shared path is the point: sending advances status and
	 * freezes the currency, billing, and issuer snapshots, and a second
	 * implementation that missed one of those would corrupt a customer-facing
	 * financial document.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function send_invoice( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'id' ) ),
				AbilityInput::id( $input['id'] ?? null, 'id' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$invoice = InvoiceModel::query()
			->with( array( 'contact', 'sale_agent', 'proposal' ) )
			->where( 'id', (int) $input['id'] )
			->first();

		if ( ! $invoice ) {
			return AbilityResult::not_found( __( 'No invoice found with that id.', 'doublescale' ) );
		}

		$forbidden = AbilityScope::assert_owns(
			$invoice,
			'sale_agent_user_id',
			self::sees_all_sales(),
			__( 'You do not have permission to access this invoice.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		// A customer with no email address would otherwise surface as an opaque
		// SMTP failure after the status had already advanced.
		$contact = $invoice->contact ?? null;
		$email   = is_object( $contact ) ? (string) ( $contact->email ?? '' ) : '';
		if ( '' === $email ) {
			return new \WP_Error(
				'doublescale_no_recipient',
				__( 'This invoice\'s contact has no email address, so there is nobody to send it to.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$message = isset( $input['message'] ) ? sanitize_textarea_field( (string) $input['message'] ) : '';

		$sent = SendInvoice::send( $invoice, $message, 'email' );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		return array(
			'sent'           => true,
			'invoice_id'     => (int) $sent->id,
			'invoice_number' => (string) $sent->invoice_number,
			'status'         => (string) $sent->status,
			'sent_at'        => (string) $sent->sent_at,
			// Named explicitly so the agent can tell the user who was emailed
			// rather than implying it chose the recipient.
			'emailed_to'     => $email,
		);
	}

	/**
	 * Create a draft invoice. Never sends.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_invoice( array $input ) {
		$prepared = self::prepare_document_create( $input, 'invoice' );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$invoice = new InvoiceModel();
		$invoice->fill( $prepared );
		SalesNumbering::save_with_retry( $invoice );

		$fresh = $invoice->fresh();

		return array(
			'created'        => true,
			'invoice_id'     => (int) $fresh->id,
			'invoice_number' => (string) $fresh->invoice_number,
			'status'         => (string) $fresh->status,
			'total'          => (float) $fresh->total,
			'sent'           => false,
		);
	}

	/**
	 * Create a draft proposal. Never sends.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_proposal( array $input ) {
		$prepared = self::prepare_document_create( $input, 'proposal' );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$proposal = new ProposalModel();
		$proposal->fill( $prepared );
		SalesNumbering::save_with_retry( $proposal );

		$fresh = $proposal->fresh();

		return array(
			'created'         => true,
			'proposal_id'     => (int) $fresh->id,
			'proposal_number' => (string) $fresh->proposal_number,
			'status'          => (string) $fresh->status,
			'total'           => (float) $fresh->total,
			'sent'            => false,
		);
	}

	/**
	 * Email a proposal to its customer.
	 *
	 * Delegates the entire send to {@see SendProposal}, which the REST endpoint
	 * also uses.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function send_proposal( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'id' ) ),
				AbilityInput::id( $input['id'] ?? null, 'id' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$proposal = ProposalModel::query()
			->with( array( 'contact', 'assigned_user' ) )
			->where( 'id', (int) $input['id'] )
			->first();

		if ( ! $proposal ) {
			return AbilityResult::not_found( __( 'No proposal found with that id.', 'doublescale' ) );
		}

		$forbidden = AbilityScope::assert_owns(
			$proposal,
			'assigned_user_id',
			self::sees_all_sales(),
			__( 'You do not have permission to access this proposal.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		$contact = $proposal->contact ?? null;
		$email   = is_object( $contact ) ? (string) ( $contact->email ?? '' ) : '';
		if ( '' === $email ) {
			return new \WP_Error(
				'doublescale_no_recipient',
				__( 'This proposal\'s contact has no email address, so there is nobody to send it to.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$message = isset( $input['message'] ) ? sanitize_textarea_field( (string) $input['message'] ) : '';

		$sent = SendProposal::send( $proposal, $message, 'email' );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		return array(
			'sent'            => true,
			'proposal_id'     => (int) $sent->id,
			'proposal_number' => (string) $sent->proposal_number,
			'status'          => (string) $sent->status,
			'sent_at'         => (string) $sent->sent_at,
			'emailed_to'      => $email,
		);
	}

	/**
	 * Shared create payload for invoices and proposals.
	 *
	 * Always draft, always assigned to the connecting user, never sent.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param string               $type  'invoice' or 'proposal'.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function prepare_document_create( array $input, string $type ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'contact_id' ) ),
				AbilityInput::id( $input['contact_id'] ?? null, 'contact_id' ),
				AbilityInput::date( $input['invoice_date'] ?? null, 'invoice_date' ),
				AbilityInput::date( $input['due_date'] ?? null, 'due_date' ),
				AbilityInput::date( $input['open_till'] ?? null, 'open_till' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$contact = ContactModel::query()->where( 'id', (int) $input['contact_id'] )->first();
		if ( ! $contact ) {
			return AbilityResult::not_found( __( 'No contact found with that id.', 'doublescale' ) );
		}

		$line_items = array();
		if ( isset( $input['line_items'] ) ) {
			$line_items = self::sanitize_line_items_input( $input['line_items'] );
			if ( is_wp_error( $line_items ) ) {
				return $line_items;
			}
		}

		$today = current_time( 'Y-m-d' );

		if ( 'invoice' === $type ) {
			return array(
				'contact_id'         => (int) $contact->id,
				'status'             => InvoiceStatus::DRAFT,
				'sale_agent_user_id' => get_current_user_id(),
				'template'           => DocumentTemplate::normalize(
					SalesSettings::get( 'default_invoice_template', DocumentTemplate::DEFAULT )
				),
				'discount_type'      => 'none',
				'discount_value'     => 0,
				'line_items'         => $line_items,
				'invoice_date'       => isset( $input['invoice_date'] ) ? (string) $input['invoice_date'] : $today,
				'due_date'           => isset( $input['due_date'] ) ? (string) $input['due_date'] : null,
				'client_note'        => isset( $input['client_note'] ) ? sanitize_textarea_field( (string) $input['client_note'] ) : '',
				'terms'              => isset( $input['terms'] ) ? sanitize_textarea_field( (string) $input['terms'] ) : '',
			);
		}

		return array(
			'contact_id'       => (int) $contact->id,
			'status'           => ProposalStatus::DRAFT,
			'assigned_user_id' => get_current_user_id(),
			'template'         => DocumentTemplate::normalize(
				SalesSettings::get( 'default_proposal_template', DocumentTemplate::DEFAULT )
			),
			'discount_type'    => 'none',
			'discount_value'   => 0,
			'line_items'       => $line_items,
			'subject'          => isset( $input['subject'] ) ? sanitize_text_field( (string) $input['subject'] ) : '',
			'date'             => $today,
			'open_till'        => isset( $input['open_till'] ) ? (string) $input['open_till'] : null,
			'terms'            => isset( $input['terms'] ) ? sanitize_textarea_field( (string) $input['terms'] ) : '',
		);
	}

	/**
	 * Sanitize agent-supplied line items into the qty/rate/amount shape the model stores.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw line_items input.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function sanitize_line_items_input( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new \WP_Error(
				'doublescale_invalid_batch',
				__( 'line_items must be a list of objects.', 'doublescale' ),
				array(
					'status' => 400,
					'field'  => 'line_items',
				)
			);
		}

		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$qty  = isset( $item['qty'] ) ? (float) $item['qty'] : ( isset( $item['quantity'] ) ? (float) $item['quantity'] : 1.0 );
			$rate = isset( $item['rate'] ) ? (float) $item['rate'] : 0.0;

			$out[] = array(
				'description' => sanitize_text_field( (string) ( $item['description'] ?? ( $item['name'] ?? '' ) ) ),
				'qty'         => $qty,
				'rate'        => $rate,
				'amount'      => $qty * $rate,
			);
		}

		return $out;
	}

	/**
	 * Apply an allow-listed update to a sales document.
	 *
	 * @since 1.0.0
	 *
	 * @param object               $document  Invoice or proposal model.
	 * @param string               $type      'invoice' or 'proposal'.
	 * @param array<string, mixed> $input     Ability input.
	 * @param string               $id_key    Key to report the id under.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function apply_document_update( $document, string $type, array $input, string $id_key ) {
		/**
		 * The same gate the REST controller honours, so Pro's approval workflow
		 * still intercepts a locked document rather than being bypassed by a
		 * different surface.
		 */
		$gate = apply_filters( 'doublescale_sales_update_gate', null, $type, $document );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$allowed = self::editable_fields()[ $type ];
		$changed = array();

		foreach ( $allowed as $field ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}
			$value = (string) $input[ $field ];
			if ( $value !== (string) $document->{$field} ) {
				$document->{$field} = $value;
				$changed[]          = $field;
			}
		}

		if ( array() === $changed ) {
			return array(
				'updated' => false,
				$id_key   => (int) $document->id,
				'message' => __( 'Nothing to change — supply a field this tool can edit.', 'doublescale' ),
			);
		}

		$total_before = (float) $document->total;

		$document->save();

		// The model recomputes totals on every save. We never touch a field
		// that feeds the calculation, so the figure must come back identical —
		// assert it rather than trust it, because a silent change here is money.
		$total_after = (float) $document->total;

		return array(
			'updated'    => true,
			$id_key      => (int) $document->id,
			'changed'    => $changed,
			'total'      => $total_after,
			'total_note' => abs( $total_after - $total_before ) < 0.001
				? __( 'The amount is unchanged, as expected — this tool cannot edit line items or totals.', 'doublescale' )
				: __( 'WARNING: the total changed unexpectedly. Report this.', 'doublescale' ),
		);
	}

	/**
	 * Gate 2 for every document ability.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function can_view_sales(): bool {
		return Capabilities::current_user_can( 'doublescale_view_sales' );
	}

	/**
	 * Whether the caller sees every sales record or only their own.
	 *
	 * Mirrors RestProposalController::get_items() and RestInvoiceController.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function sees_all_sales(): bool {
		return Capabilities::can_manage_all_sales() || Capabilities::can_assign_sales_rep();
	}

	/**
	 * List invoices, scoped by sale_agent_user_id.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_invoices( array $input ): array {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = InvoiceModel::query()->with( array( 'contact' ) );

		AbilityScope::apply( $query, 'sale_agent_user_id', self::sees_all_sales() );

		if ( ! empty( $input['status'] ) ) {
			$query->where( 'status', (string) $input['status'] );
		}
		if ( ! empty( $input['contact_id'] ) ) {
			$query->where( 'contact_id', (int) $input['contact_id'] );
		}

		$total = (int) $query->count();

		$rows = $query->orderBy( 'invoice_date', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'id'             => (int) $row->id,
				'invoice_number' => $row->invoice_number,
				'status'         => $row->status,
				'contact'        => self::shape_contact( $row ),
				'total'          => (float) $row->total,
				'amount_paid'    => (float) $row->amount_paid,
				'currency'       => $row->currency,
				'invoice_date'   => $row->invoice_date,
				'due_date'       => $row->due_date,
			);
		}

		return AbilityResult::collection(
			$items,
			$total,
			$limit,
			$offset,
			array( 'scope' => AbilityScope::label( self::sees_all_sales() ) )
		);
	}

	/**
	 * Get one invoice.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_invoice( array $input ) {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( $id <= 0 ) {
			return AbilityResult::not_found( __( 'Provide a valid invoice id.', 'doublescale' ) );
		}

		$invoice = InvoiceModel::query()->with( array( 'contact' ) )->where( 'id', $id )->first();
		if ( ! $invoice ) {
			return AbilityResult::not_found( __( 'No invoice found with that id.', 'doublescale' ) );
		}

		if ( ! Capabilities::user_can_manage_record(
			get_current_user_id(),
			$invoice->sale_agent_user_id ? (int) $invoice->sale_agent_user_id : null
		) ) {
			return AbilityResult::forbidden( __( 'You do not have permission to access this invoice.', 'doublescale' ) );
		}

		return array(
			'id'             => (int) $invoice->id,
			'invoice_number' => $invoice->invoice_number,
			'status'         => $invoice->status,
			'contact'        => self::shape_contact( $invoice ),
			'currency'       => $invoice->currency,
			'subtotal'       => (float) $invoice->subtotal,
			'total_tax'      => (float) $invoice->total_tax,
			'total'          => (float) $invoice->total,
			'amount_paid'    => (float) $invoice->amount_paid,
			'balance_due'    => (float) $invoice->total - (float) $invoice->amount_paid,
			'invoice_date'   => $invoice->invoice_date,
			'due_date'       => $invoice->due_date,
			'line_items'     => self::shape_line_items( $invoice->line_items ),
		);
	}

	/**
	 * List proposals, scoped by assigned_user_id.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_proposals( array $input ): array {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = ProposalModel::query()->with( array( 'contact' ) );

		AbilityScope::apply( $query, 'assigned_user_id', self::sees_all_sales() );

		if ( ! empty( $input['status'] ) ) {
			$query->where( 'status', (string) $input['status'] );
		}
		if ( ! empty( $input['contact_id'] ) ) {
			$query->where( 'contact_id', (int) $input['contact_id'] );
		}

		$total = (int) $query->count();

		$rows = $query->orderBy( 'date', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'id'              => (int) $row->id,
				'proposal_number' => $row->proposal_number,
				'subject'         => $row->subject,
				'status'          => $row->status,
				'contact'         => self::shape_contact( $row ),
				'total'           => (float) $row->total,
				'currency'        => $row->currency,
				'date'            => $row->date,
				'open_till'       => $row->open_till,
			);
		}

		return AbilityResult::collection(
			$items,
			$total,
			$limit,
			$offset,
			array( 'scope' => AbilityScope::label( self::sees_all_sales() ) )
		);
	}

	/**
	 * Get one proposal.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_proposal( array $input ) {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( $id <= 0 ) {
			return AbilityResult::not_found( __( 'Provide a valid proposal id.', 'doublescale' ) );
		}

		$proposal = ProposalModel::query()->with( array( 'contact' ) )->where( 'id', $id )->first();
		if ( ! $proposal ) {
			return AbilityResult::not_found( __( 'No proposal found with that id.', 'doublescale' ) );
		}

		if ( ! Capabilities::user_can_manage_record(
			get_current_user_id(),
			$proposal->assigned_user_id ? (int) $proposal->assigned_user_id : null
		) ) {
			return AbilityResult::forbidden( __( 'You do not have permission to access this proposal.', 'doublescale' ) );
		}

		return array(
			'id'              => (int) $proposal->id,
			'proposal_number' => $proposal->proposal_number,
			'subject'         => $proposal->subject,
			'status'          => $proposal->status,
			'contact'         => self::shape_contact( $proposal ),
			'currency'        => $proposal->currency,
			'subtotal'        => (float) $proposal->subtotal,
			'total'           => (float) $proposal->total,
			'date'            => $proposal->date,
			'open_till'       => $proposal->open_till,
			'line_items'      => self::shape_line_items( $proposal->line_items ),
		);
	}

	/**
	 * Invoice counts and totals by status, per currency.
	 *
	 * Grouped by currency because summing mixed-currency rows into one number
	 * produces a figure that is wrong in every currency. Applies the same
	 * ownership scope as the list abilities — an unscoped total would leak
	 * company-wide revenue to a sales rep.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_sales_summary( array $input ): array {
		$query = InvoiceModel::query();

		AbilityScope::apply( $query, 'sale_agent_user_id', self::sees_all_sales() );

		if ( ! empty( $input['date_from'] ) ) {
			$query->where( 'invoice_date', '>=', (string) $input['date_from'] );
		}
		if ( ! empty( $input['date_to'] ) ) {
			$query->where( 'invoice_date', '<=', (string) $input['date_to'] );
		}

		$rows = $query->get();

		$by_currency = array();
		foreach ( $rows as $row ) {
			$currency = (string) ( $row->currency ?? '' );
			$status   = (string) $row->status;

			if ( ! isset( $by_currency[ $currency ] ) ) {
				$by_currency[ $currency ] = array(
					'currency'    => $currency,
					'count'       => 0,
					'total'       => 0.0,
					'amount_paid' => 0.0,
					'by_status'   => array(),
				);
			}

			++$by_currency[ $currency ]['count'];
			$by_currency[ $currency ]['total']       += (float) $row->total;
			$by_currency[ $currency ]['amount_paid'] += (float) $row->amount_paid;

			if ( ! isset( $by_currency[ $currency ]['by_status'][ $status ] ) ) {
				$by_currency[ $currency ]['by_status'][ $status ] = array(
					'count' => 0,
					'total' => 0.0,
				);
			}
			++$by_currency[ $currency ]['by_status'][ $status ]['count'];
			$by_currency[ $currency ]['by_status'][ $status ]['total'] += (float) $row->total;
		}

		return array(
			'currencies'    => array_values( $by_currency ),
			'scope'         => AbilityScope::label( self::sees_all_sales() ),
			'currency_note' => __( 'Figures are grouped by currency and must not be added together across currencies.', 'doublescale' ),
		);
	}

	/**
	 * Shape the related contact, tolerating a missing relation.
	 *
	 * @since 1.0.0
	 *
	 * @param object $document Invoice or proposal.
	 * @return array<string, mixed>|null
	 */
	private static function shape_contact( $document ): ?array {
		$contact = $document->contact ?? null;
		if ( ! is_object( $contact ) ) {
			return null;
		}

		$name = trim( (string) $contact->first_name . ' ' . (string) $contact->last_name );

		return array(
			'id'    => (int) $contact->id,
			'name'  => '' !== $name ? $name : (string) $contact->email,
			'email' => $contact->email,
		);
	}

	/**
	 * Normalise stored line items to a compact shape.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $line_items Raw column value (array or JSON string).
	 * @return array<int, array<string, mixed>>
	 */
	private static function shape_line_items( $line_items ): array {
		if ( is_string( $line_items ) ) {
			$line_items = json_decode( $line_items, true );
		}
		if ( ! is_array( $line_items ) ) {
			return array();
		}

		$out = array();
		foreach ( $line_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$out[] = array(
				'description' => $item['description'] ?? ( $item['name'] ?? '' ),
				'quantity'    => isset( $item['quantity'] ) ? (float) $item['quantity'] : null,
				'rate'        => isset( $item['rate'] ) ? (float) $item['rate'] : null,
				'amount'      => isset( $item['amount'] ) ? (float) $item['amount'] : null,
			);
		}

		return $out;
	}
}
