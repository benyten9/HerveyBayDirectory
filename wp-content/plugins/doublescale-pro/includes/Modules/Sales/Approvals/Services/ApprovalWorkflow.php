<?php
/**
 * Sales document approval workflow service.
 *
 * @package DoubleScale\Pro\Modules\Sales\Approvals
 */

namespace DoubleScale\Pro\Modules\Sales\Approvals\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Pro\Modules\Contracts\Constants\ContractStatus;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;
use DoubleScale\Pro\Modules\CreditNotes\Constants\CreditNoteStatus;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;
use DoubleScale\Modules\Sales\Services\SalesSettings;
use DoubleScale\Pro\Modules\Sales\Approvals\Constants\ApprovalStatus;
use DoubleScale\Pro\Modules\Sales\Approvals\Migrations\SalesApprovalsTable;
use DoubleScale\Pro\Modules\Sales\Approvals\Models\ApprovalModel;
use WP_Error;

/**
 * ApprovalWorkflow class.
 */
final class ApprovalWorkflow {

	/** @var bool|null */
	private static $storage_ready_cache = null;

	/**
	 * Wire filters and hooks for the approval workflow.
	 *
	 * @return void
	 */
	public static function register(): void {
		$instance = new self();
		add_filter( 'doublescale_sales_send_gate', array( $instance, 'gate' ), 10, 3 );
		add_filter( 'doublescale_sales_credit_apply_gate', array( $instance, 'apply_credit_gate' ), 10, 3 );
		add_filter( 'doublescale_sales_update_gate', array( $instance, 'update_gate' ), 10, 3 );
		add_filter( 'doublescale_sales_convert_proposal_gate', array( $instance, 'convert_gate' ), 10, 2 );
		add_filter( 'doublescale_sales_proposal_admin_shape', array( $instance, 'append_proposal_shape' ), 10, 2 );
		add_filter( 'doublescale_sales_invoice_admin_shape', array( $instance, 'append_invoice_shape' ), 10, 2 );
		add_filter( 'doublescale_sales_contract_admin_shape', array( $instance, 'append_contract_shape' ), 10, 2 );
		add_filter( 'doublescale_sales_credit_note_admin_shape', array( $instance, 'append_credit_note_shape' ), 10, 2 );
		add_action( 'updated_option', array( self::class, 'maybe_ensure_storage_on_settings_change' ), 10, 3 );
		add_action( 'doublescale_sales_proposal_deleted', array( self::class, 'on_proposal_deleted' ), 10, 1 );
		add_action( 'doublescale_sales_invoice_deleted', array( self::class, 'on_invoice_deleted' ), 10, 1 );
		add_action( 'doublescale_sales_contract_deleted', array( self::class, 'on_contract_deleted' ), 10, 1 );
		add_action( 'doublescale_sales_credit_note_deleted', array( self::class, 'on_credit_note_deleted' ), 10, 1 );
		add_action( 'doublescale_sales_proposal_updated', array( self::class, 'on_proposal_updated' ), 10, 1 );
		add_action( 'doublescale_sales_invoice_updated', array( self::class, 'on_invoice_updated' ), 10, 1 );
		add_action( 'doublescale_sales_contract_updated', array( self::class, 'on_contract_updated' ), 10, 1 );
		add_action( 'doublescale_sales_credit_note_updated', array( self::class, 'on_credit_note_updated' ), 10, 1 );
		add_action( 'doublescale_sales_proposal_converted_to_invoice', array( self::class, 'on_proposal_converted_to_invoice' ), 10, 2 );

		if ( self::is_enabled() ) {
			self::ensure_storage();
		}
	}

	/**
	 * @param string $option    Option name.
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     New value.
	 * @return void
	 */
	public static function maybe_ensure_storage_on_settings_change( $option, $old_value, $value ): void {
		if ( 'doublescale_sales_settings' !== $option || ! is_array( $value ) ) {
			return;
		}

		$enabled = ! empty( $value['approval_workflow_enabled'] );
		if ( ! $enabled ) {
			return;
		}

		$was_enabled = is_array( $old_value ) && ! empty( $old_value['approval_workflow_enabled'] );
		if ( $was_enabled && self::storage_ready() ) {
			return;
		}

		self::ensure_storage();
	}

	/**
	 * @return bool
	 */
	public static function storage_ready(): bool {
		if ( null !== self::$storage_ready_cache ) {
			return self::$storage_ready_cache;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'doublescale_sales_approvals';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::$storage_ready_cache = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

		return self::$storage_ready_cache;
	}

	/**
	 * Create the approvals table when missing (idempotent).
	 *
	 * @return bool
	 */
	public static function ensure_storage(): bool {
		if ( self::storage_ready() ) {
			return true;
		}

		$file = self::migration_file();
		if ( null === $file ) {
			return false;
		}

		require_once $file;

		if ( ! class_exists( SalesApprovalsTable::class ) ) {
			return false;
		}

		try {
			( new SalesApprovalsTable() )->run();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Sales approvals table migration failed',
					array(
						'source' => 'sales-approval-workflow',
						'error'  => $e->getMessage(),
					)
				);
			}
			return false;
		}

		// Bust the request-level cache after DDL.
		self::$storage_ready_cache = null;

		return self::storage_ready();
	}

	/**
	 * @return WP_Error|null
	 */
	public static function ensure_storage_or_error(): ?WP_Error {
		return self::require_storage();
	}

	/**
	 * @return WP_Error|null
	 */
	private static function require_storage(): ?WP_Error {
		if ( self::storage_ready() || self::ensure_storage() ) {
			return null;
		}

		return new WP_Error(
			'approval_storage_unavailable',
			__( 'Approval storage is not ready. Re-save Sales settings or contact support.', 'doublescale' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * @return string|null
	 */
	private static function migration_file(): ?string {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return null;
		}

		$file = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/Sales/Approvals/Migrations/SalesApprovalsTable.php';

		return is_readable( $file ) ? $file : null;
	}

	/**
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) SalesSettings::get( 'approval_workflow_enabled', false );
	}

	/**
	 * @param string                $type     Document type.
	 * @param ProposalModel|InvoiceModel $document Document model.
	 * @return bool
	 */
	public static function requires_approval( string $type, $document ): bool {
		unset( $type, $document );
		return self::is_enabled();
	}

	/**
	 * @param string $type Document type.
	 * @param int    $id   Document id.
	 * @return ApprovalModel|null
	 */
	public static function current( string $type, int $id ): ?ApprovalModel {
		if ( $id <= 0 ) {
			return null;
		}

		if ( ! self::storage_ready() ) {
			if ( self::is_enabled() ) {
				self::ensure_storage();
			}
			if ( ! self::storage_ready() ) {
				return null;
			}
		}

		try {
			$row = ApprovalModel::forDocument( $type, $id )->first();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Sales approval lookup failed',
					array(
						'source' => 'sales-approval-workflow',
						'type'   => $type,
						'id'     => $id,
						'error'  => $e->getMessage(),
					)
				);
			}
			return null;
		}

		return $row instanceof ApprovalModel ? $row : null;
	}

	/**
	 * Remove the approval row when a sales document is deleted.
	 *
	 * @param object $proposal Deleted proposal model.
	 * @return void
	 */
	public static function on_proposal_deleted( $proposal ): void {
		if ( ! is_object( $proposal ) || empty( $proposal->id ) ) {
			return;
		}

		self::delete_for_document( 'proposal', (int) $proposal->id );
	}

	/**
	 * Remove the approval row when a sales document is deleted.
	 *
	 * @param object $invoice Deleted invoice model.
	 * @return void
	 */
	public static function on_invoice_deleted( $invoice ): void {
		if ( ! is_object( $invoice ) || empty( $invoice->id ) ) {
			return;
		}

		self::delete_for_document( 'invoice', (int) $invoice->id );
	}

	/**
	 * Delete the approval record for a document, if any.
	 *
	 * @param string $type Document type (`proposal` or `invoice`).
	 * @param int    $id   Document id.
	 * @return void
	 */
	public static function delete_for_document( string $type, int $id ): void {
		if ( $id <= 0 || ! self::storage_ready() ) {
			return;
		}

		try {
			ApprovalModel::forDocument( $type, $id )->delete();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Sales approval cleanup failed',
					array(
						'source' => 'sales-approval-workflow',
						'type'   => $type,
						'id'     => $id,
						'error'  => $e->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * @param string $type Document type.
	 * @param int    $id   Document id.
	 * @return bool
	 */
	public static function is_approved( string $type, int $id ): bool {
		$current = self::current( $type, $id );
		return $current && ApprovalStatus::APPROVED === (string) $current->status;
	}

	/**
	 * Whether the current user may edit a sales document under the approval workflow.
	 *
	 * @param string                       $type     Document type.
	 * @param ProposalModel|InvoiceModel   $document Document model.
	 * @return bool
	 */
	public static function can_current_user_edit( string $type, $document ): bool {
		if ( ! self::is_enabled() || ! self::requires_approval( $type, $document ) ) {
			return true;
		}

		if ( Capabilities::can_approve_sales() || Capabilities::can_manage_all_sales() ) {
			return true;
		}

		$approval = self::current( $type, (int) $document->id );
		if ( ! $approval ) {
			return true;
		}

		return ApprovalStatus::PENDING !== (string) $approval->status;
	}

	/**
	 * @param object $proposal Updated proposal model.
	 * @return void
	 */
	public static function on_proposal_updated( $proposal ): void {
		if ( ! is_object( $proposal ) || empty( $proposal->id ) ) {
			return;
		}

		self::maybe_reset_pending_after_manager_edit( 'proposal', $proposal );
		self::maybe_invalidate_approval_after_edit( 'proposal', $proposal );
	}

	/**
	 * @param object $invoice Updated invoice model.
	 * @return void
	 */
	public static function on_invoice_updated( $invoice ): void {
		if ( ! is_object( $invoice ) || empty( $invoice->id ) ) {
			return;
		}

		self::maybe_reset_pending_after_manager_edit( 'invoice', $invoice );
		self::maybe_invalidate_approval_after_edit( 'invoice', $invoice );
	}

	/**
	 * Sales reps must re-submit for approval after editing an approved document.
	 *
	 * @param string     $type     Document type.
	 * @param object     $document Document model.
	 * @return void
	 */
	private static function maybe_invalidate_approval_after_edit( string $type, $document ): void {
		if ( ! self::is_enabled() || ! self::requires_approval( $type, $document ) ) {
			return;
		}

		$approval = self::current( $type, (int) $document->id );
		if ( ! $approval || ApprovalStatus::APPROVED !== (string) $approval->status ) {
			return;
		}

		$reviewer_id  = (int) $approval->reviewed_by_user_id;
		$requester_id = (int) $approval->requested_by_user_id;
		$editor_id    = get_current_user_id();

		self::delete_for_document( $type, (int) $document->id );

		/**
		 * Fires when an approved sales document is edited and must be re-submitted.
		 *
		 * @param string $type         Document type.
		 * @param object $document     Document model.
		 * @param int    $reviewer_id  Prior approving user id.
		 * @param int    $requester_id Rep who requested approval.
		 * @param int    $editor_id    User who edited the document.
		 */
		do_action( 'doublescale_sales_approval_invalidated', $type, $document, $reviewer_id, $requester_id, $editor_id );
	}

	/**
	 * When a manager edits a document that is pending approval, reset the request so the rep can review changes.
	 *
	 * @param string $type     Document type.
	 * @param object $document Document model.
	 * @return void
	 */
	private static function maybe_reset_pending_after_manager_edit( string $type, $document ): void {
		if ( ! self::is_enabled() || ! self::requires_approval( $type, $document ) ) {
			return;
		}

		if ( ! Capabilities::can_approve_sales() && ! Capabilities::can_manage_all_sales() ) {
			return;
		}

		$approval = self::current( $type, (int) $document->id );
		if ( ! $approval || ApprovalStatus::PENDING !== (string) $approval->status ) {
			return;
		}

		$requester_id = (int) $approval->requested_by_user_id;
		$editor_id    = get_current_user_id();

		self::delete_for_document( $type, (int) $document->id );

		self::log_activity(
			$document,
			$type,
			sprintf(
				/* translators: %s: document label */
				__( '%s approval request reset after a manager edit.', 'doublescale' ),
				self::document_label( $document, $type )
			)
		);

		/**
		 * Fires when a manager edit clears a pending approval request.
		 *
		 * @param string $type         Document type.
		 * @param object $document     Document model.
		 * @param int    $requester_id Rep who submitted the request.
		 * @param int    $editor_id    Manager who edited the document.
		 */
		do_action( 'doublescale_sales_approval_pending_reset', $type, $document, $requester_id, $editor_id );
	}

	/**
	 * Whether a document status allows a new approval submission.
	 *
	 * @param string $type     Document type.
	 * @param object $document Document model.
	 * @return bool
	 */
	public static function document_allows_submission( string $type, $document ): bool {
		$status = (string) $document->status;

		if ( 'proposal' === $type ) {
			return in_array(
				$status,
				array( ProposalStatus::DRAFT, ProposalStatus::SENT, ProposalStatus::OPEN, ProposalStatus::ACCEPTED ),
				true
			);
		}

		if ( 'invoice' === $type ) {
			return in_array(
				$status,
				array(
					InvoiceStatus::DRAFT,
					InvoiceStatus::UNPAID,
					InvoiceStatus::PARTIALLY_PAID,
					InvoiceStatus::OVERDUE,
				),
				true
			);
		}

		if ( 'contract' === $type ) {
			return in_array(
				$status,
				array( ContractStatus::DRAFT, ContractStatus::SENT, ContractStatus::ACTIVE, ContractStatus::SIGNED ),
				true
			);
		}

		if ( 'credit_note' === $type ) {
			return in_array(
				$status,
				array( CreditNoteStatus::DRAFT, CreditNoteStatus::OPEN, CreditNoteStatus::PARTIALLY_APPLIED ),
				true
			);
		}

		return false;
	}

	/**
	 * Whether the current user may withdraw a pending approval request.
	 *
	 * @param string $type     Document type.
	 * @param object $document Document model.
	 * @return bool
	 */
	public static function can_current_user_withdraw( string $type, $document ): bool {
		if ( ! self::is_enabled() || Capabilities::can_approve_sales() || Capabilities::can_manage_all_sales() ) {
			return false;
		}

		$approval = self::current( $type, (int) $document->id );
		if ( ! $approval || ApprovalStatus::PENDING !== (string) $approval->status ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( (int) $approval->requested_by_user_id === $user_id ) {
			return true;
		}

		$assigned_id = self::document_assigned_user_id( $document, $type );
		return Capabilities::user_can_manage_record( $user_id, $assigned_id );
	}

	/**
	 * Cancel a pending approval so the requester can edit and re-submit.
	 *
	 * @param string $type    Document type.
	 * @param int    $id      Document id.
	 * @param int    $user_id Acting user id.
	 * @return true|WP_Error
	 */
	public static function withdraw( string $type, int $id, int $user_id ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'approval_disabled', __( 'Approval workflow is not enabled.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$storage = self::require_storage();
		if ( $storage ) {
			return $storage;
		}

		$document = self::resolve_document( $type, $id );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		if ( ! self::can_current_user_withdraw( $type, $document ) ) {
			return new WP_Error(
				'not_withdrawable',
				__( 'This approval request cannot be withdrawn.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		$approval = self::current( $type, $id );
		if ( ! $approval ) {
			return new WP_Error( 'not_pending', __( 'No pending approval found for this document.', 'doublescale' ), array( 'status' => 400 ) );
		}

		self::delete_for_document( $type, $id );

		self::log_activity(
			$document,
			$type,
			sprintf(
				/* translators: %s: document label */
				__( '%s approval request withdrawn.', 'doublescale' ),
				self::document_label( $document, $type )
			)
		);

		do_action( 'doublescale_sales_approval_withdrawn', $type, $document, $user_id );

		return true;
	}

	/**
	 * @param object $proposal Converted proposal.
	 * @param object $invoice  New invoice.
	 * @return void
	 */
	public static function on_proposal_converted_to_invoice( $proposal, $invoice ): void {
		unset( $proposal );

		if ( ! is_object( $invoice ) || empty( $invoice->id ) ) {
			return;
		}

		self::delete_for_document( 'invoice', (int) $invoice->id );
	}

	/**
	 * @param object $contract Deleted contract.
	 * @return void
	 */
	public static function on_contract_deleted( $contract ): void {
		if ( ! is_object( $contract ) || empty( $contract->id ) ) {
			return;
		}

		self::delete_for_document( 'contract', (int) $contract->id );
	}

	/**
	 * @param object $credit_note Deleted credit note.
	 * @return void
	 */
	public static function on_credit_note_deleted( $credit_note ): void {
		if ( ! is_object( $credit_note ) || empty( $credit_note->id ) ) {
			return;
		}

		self::delete_for_document( 'credit_note', (int) $credit_note->id );
	}

	/**
	 * @param object $contract Updated contract.
	 * @return void
	 */
	public static function on_contract_updated( $contract ): void {
		if ( ! is_object( $contract ) || empty( $contract->id ) ) {
			return;
		}

		self::maybe_reset_pending_after_manager_edit( 'contract', $contract );
		self::maybe_invalidate_approval_after_edit( 'contract', $contract );
	}

	/**
	 * @param object $credit_note Updated credit note.
	 * @return void
	 */
	public static function on_credit_note_updated( $credit_note ): void {
		if ( ! is_object( $credit_note ) || empty( $credit_note->id ) ) {
			return;
		}

		self::maybe_reset_pending_after_manager_edit( 'credit_note', $credit_note );
		self::maybe_invalidate_approval_after_edit( 'credit_note', $credit_note );
	}

	/**
	 * @param string $type    Document type.
	 * @param int    $id      Document id.
	 * @param int    $user_id Requester user id.
	 * @return ApprovalModel|WP_Error
	 */
	public static function submit( string $type, int $id, int $user_id ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'approval_disabled', __( 'Approval workflow is not enabled.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$storage = self::require_storage();
		if ( $storage ) {
			return $storage;
		}

		$document = self::resolve_document( $type, $id );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$forbidden = self::require_document_ownership( $document, $type );
		if ( $forbidden ) {
			return $forbidden;
		}

		if ( ! self::document_allows_submission( $type, $document ) ) {
			return new WP_Error(
				'invalid_status',
				__( 'This document cannot be submitted for approval in its current status.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$existing = self::current( $type, $id );
		if ( $existing && ApprovalStatus::PENDING === (string) $existing->status ) {
			return new WP_Error( 'already_pending', __( 'This document is already pending approval.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$now = current_time( 'mysql' );
		$row = ApprovalModel::updateOrCreate(
			array(
				'document_type' => $type,
				'document_id'   => $id,
			),
			array(
				'status'               => ApprovalStatus::PENDING,
				'requested_by_user_id' => $user_id,
				'requested_at'         => $now,
				'reviewed_by_user_id'  => null,
				'reviewed_at'          => null,
				'rejection_reason'     => null,
			)
		);

		self::log_activity(
			$document,
			$type,
			sprintf(
				/* translators: %s: document label */
				__( '%s submitted for approval.', 'doublescale' ),
				self::document_label( $document, $type )
			)
		);

		do_action( 'doublescale_sales_approval_requested', $row->fresh(), $document, $type );

		return $row->fresh();
	}

	/**
	 * @param string $type        Document type.
	 * @param int    $id          Document id.
	 * @param int    $reviewer_id Reviewer user id.
	 * @return ApprovalModel|WP_Error
	 */
	public static function approve( string $type, int $id, int $reviewer_id ) {
		$storage = self::require_storage();
		if ( $storage ) {
			return $storage;
		}

		$approval = self::current( $type, $id );
		if ( ! $approval || ApprovalStatus::PENDING !== (string) $approval->status ) {
			return new WP_Error( 'not_pending', __( 'No pending approval found for this document.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$document = self::resolve_document( $type, $id );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$approval->status              = ApprovalStatus::APPROVED;
		$approval->reviewed_by_user_id = $reviewer_id;
		$approval->reviewed_at         = current_time( 'mysql' );
		$approval->rejection_reason    = null;
		$approval->save();

		self::log_activity(
			$document,
			$type,
			sprintf(
				/* translators: %s: document label */
				__( '%s approved for sending.', 'doublescale' ),
				self::document_label( $document, $type )
			)
		);

		do_action( 'doublescale_sales_approval_approved', $approval->fresh(), $document, $type );

		return $approval->fresh();
	}

	/**
	 * @param string $type        Document type.
	 * @param int    $id          Document id.
	 * @param int    $reviewer_id Reviewer user id.
	 * @param string $reason      Rejection reason.
	 * @return ApprovalModel|WP_Error
	 */
	public static function reject( string $type, int $id, int $reviewer_id, string $reason ) {
		$reason = trim( $reason );
		if ( '' === $reason ) {
			return new WP_Error( 'reason_required', __( 'A rejection reason is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$storage = self::require_storage();
		if ( $storage ) {
			return $storage;
		}

		$approval = self::current( $type, $id );
		if ( ! $approval || ApprovalStatus::PENDING !== (string) $approval->status ) {
			return new WP_Error( 'not_pending', __( 'No pending approval found for this document.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$document = self::resolve_document( $type, $id );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$approval->status              = ApprovalStatus::REJECTED;
		$approval->reviewed_by_user_id = $reviewer_id;
		$approval->reviewed_at         = current_time( 'mysql' );
		$approval->rejection_reason    = sanitize_textarea_field( $reason );
		$approval->save();

		self::log_activity(
			$document,
			$type,
			sprintf(
				/* translators: 1: document label, 2: rejection reason */
				__( '%1$s rejected: %2$s', 'doublescale' ),
				self::document_label( $document, $type ),
				$approval->rejection_reason
			)
		);

		do_action( 'doublescale_sales_approval_rejected', $approval->fresh(), $document, $type );

		return $approval->fresh();
	}

	/**
	 * @param mixed|null            $result   Existing gate result.
	 * @param string                $type     Document type.
	 * @param ProposalModel|InvoiceModel $document Document model.
	 * @return mixed|WP_Error
	 */
	public function gate( $result, string $type, $document ) {
		return $this->approval_required_gate(
			$result,
			$type,
			$document,
			__( 'This document must be approved before it can be sent to the client.', 'doublescale' )
		);
	}

	/**
	 * Block applying credit note balance until the note is approved (sales reps only).
	 *
	 * @param mixed|null $result   Existing gate result.
	 * @param string     $type     Document type.
	 * @param mixed      $document Credit note model.
	 * @return mixed|WP_Error
	 */
	public function apply_credit_gate( $result, string $type, $document ) {
		if ( 'credit_note' !== $type ) {
			return $result;
		}

		return $this->approval_required_gate(
			$result,
			$type,
			$document,
			__( 'This credit note must be approved before credit can be applied to an invoice.', 'doublescale' )
		);
	}

	/**
	 * Shared approval check for outbound sales actions.
	 *
	 * @param mixed|null $result   Existing gate result.
	 * @param string     $type     Document type.
	 * @param mixed      $document Document model.
	 * @param string     $message  Error message when approval is missing.
	 * @return mixed|WP_Error
	 */
	private function approval_required_gate( $result, string $type, $document, string $message ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! self::is_enabled() || ! self::requires_approval( $type, $document ) ) {
			return $result;
		}

		if ( Capabilities::can_approve_sales() || Capabilities::can_manage_all_sales() ) {
			return $result;
		}

		if ( self::is_approved( $type, (int) $document->id ) ) {
			return $result;
		}

		return new WP_Error(
			'approval_required',
			$message,
			array( 'status' => 403 )
		);
	}

	/**
	 * Block sales reps from editing documents that are pending approval.
	 *
	 * @param mixed|null                   $result   Existing gate result.
	 * @param string                       $type     Document type.
	 * @param ProposalModel|InvoiceModel   $document Document model.
	 * @return mixed|WP_Error
	 */
	public function update_gate( $result, string $type, $document ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( self::can_current_user_edit( $type, $document ) ) {
			return $result;
		}

		return new WP_Error(
			'approval_pending',
			__( 'This document is pending approval and cannot be edited.', 'doublescale' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Require proposal approval before converting to invoice (sales reps only).
	 *
	 * @param mixed|null    $result   Existing gate result.
	 * @param ProposalModel $proposal Proposal model.
	 * @return mixed|WP_Error
	 */
	public function convert_gate( $result, ProposalModel $proposal ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! self::is_enabled() || ! self::requires_approval( 'proposal', $proposal ) ) {
			return $result;
		}

		if ( Capabilities::can_approve_sales() || Capabilities::can_manage_all_sales() ) {
			return $result;
		}

		if ( ProposalStatus::ACCEPTED === (string) $proposal->status ) {
			return $result;
		}

		if ( self::is_approved( 'proposal', (int) $proposal->id ) ) {
			return $result;
		}

		return new WP_Error(
			'approval_required',
			__( 'This proposal must be approved before it can be converted to an invoice.', 'doublescale' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * @param array $data Shaped document payload.
	 * @return array
	 */
	private static function append_workflow_context( array $data, string $type, $document ): array {
		$data['approval_workflow_enabled'] = self::is_enabled();
		$data['can_bypass_sales_approval'] = Capabilities::can_approve_sales() || Capabilities::can_manage_all_sales();
		$data['can_edit_sales_document']   = self::can_current_user_edit( $type, $document );
		$data['can_withdraw_sales_approval'] = self::can_current_user_withdraw( $type, $document );

		return $data;
	}

	/**
	 * @param array         $data     Shaped proposal data.
	 * @param ProposalModel $proposal Proposal model.
	 * @return array
	 */
	public function append_proposal_shape( array $data, ProposalModel $proposal ): array {
		$data = self::append_workflow_context( $data, 'proposal', $proposal );

		if ( ! self::storage_ready() && ! self::is_enabled() ) {
			$data['approval'] = null;
			return $data;
		}

		$data['approval'] = self::shape_approval( self::current( 'proposal', (int) $proposal->id ) );
		return $data;
	}

	/**
	 * @param array        $data    Shaped invoice data.
	 * @param InvoiceModel $invoice Invoice model.
	 * @return array
	 */
	public function append_invoice_shape( array $data, InvoiceModel $invoice ): array {
		$data = self::append_workflow_context( $data, 'invoice', $invoice );

		if ( ! self::storage_ready() && ! self::is_enabled() ) {
			$data['approval'] = null;
			return $data;
		}

		$data['approval'] = self::shape_approval( self::current( 'invoice', (int) $invoice->id ) );
		return $data;
	}

	/**
	 * @param array         $data     Shaped contract data.
	 * @param ContractModel $contract Contract model.
	 * @return array
	 */
	public function append_contract_shape( array $data, ContractModel $contract ): array {
		$data = self::append_workflow_context( $data, 'contract', $contract );

		if ( ! self::storage_ready() && ! self::is_enabled() ) {
			$data['approval'] = null;
			return $data;
		}

		$data['approval'] = self::shape_approval( self::current( 'contract', (int) $contract->id ) );
		return $data;
	}

	/**
	 * @param array           $data        Shaped credit note data.
	 * @param CreditNoteModel $credit_note Credit note model.
	 * @return array
	 */
	public function append_credit_note_shape( array $data, CreditNoteModel $credit_note ): array {
		$data = self::append_workflow_context( $data, 'credit_note', $credit_note );

		if ( ! self::storage_ready() && ! self::is_enabled() ) {
			$data['approval'] = null;
			return $data;
		}

		$data['approval'] = self::shape_approval( self::current( 'credit_note', (int) $credit_note->id ) );
		return $data;
	}

	/**
	 * @param object $document Document model.
	 * @param string $type     Document type.
	 * @return int|null
	 */
	private static function document_assigned_user_id( $document, string $type ): ?int {
		if ( 'proposal' === $type || 'contract' === $type ) {
			return $document->assigned_user_id ? (int) $document->assigned_user_id : null;
		}

		return $document->sale_agent_user_id ? (int) $document->sale_agent_user_id : null;
	}

	/**
	 * @param ApprovalModel|null $approval Approval row.
	 * @return array<string, mixed>|null
	 */
	public static function shape_approval( ?ApprovalModel $approval ): ?array {
		if ( ! $approval ) {
			return null;
		}

		$reviewer = $approval->reviewed_by_user_id ? get_userdata( (int) $approval->reviewed_by_user_id ) : null;
		$requester = get_userdata( (int) $approval->requested_by_user_id );

		return array(
			'id'                     => (int) $approval->id,
			'document_type'          => (string) $approval->document_type,
			'document_id'            => (int) $approval->document_id,
			'status'                 => (string) $approval->status,
			'status_label'           => ApprovalStatus::get_label( (string) $approval->status ),
			'requested_by_user_id'   => (int) $approval->requested_by_user_id,
			'requested_by_name'      => $requester ? (string) $requester->display_name : '',
			'requested_at'           => (string) $approval->requested_at,
			'reviewed_by_user_id'    => $approval->reviewed_by_user_id ? (int) $approval->reviewed_by_user_id : null,
			'reviewed_by_name'       => $reviewer ? (string) $reviewer->display_name : null,
			'reviewed_at'            => $approval->reviewed_at ? (string) $approval->reviewed_at : null,
			'rejection_reason'       => $approval->rejection_reason ? (string) $approval->rejection_reason : null,
		);
	}

	/**
	 * @param string $type Document type.
	 * @param int    $id   Document id.
	 * @return ProposalModel|InvoiceModel|WP_Error
	 */
	private static function resolve_document( string $type, int $id ) {
		if ( 'proposal' === $type ) {
			$document = ProposalModel::find( $id );
			if ( ! $document ) {
				return new WP_Error( 'not_found', __( 'Proposal not found.', 'doublescale' ), array( 'status' => 404 ) );
			}
			return $document;
		}

		if ( 'invoice' === $type ) {
			$document = InvoiceModel::find( $id );
			if ( ! $document ) {
				return new WP_Error( 'not_found', __( 'Invoice not found.', 'doublescale' ), array( 'status' => 404 ) );
			}
			return $document;
		}

		if ( 'contract' === $type ) {
			$document = ContractModel::find( $id );
			if ( ! $document ) {
				return new WP_Error( 'not_found', __( 'Contract not found.', 'doublescale' ), array( 'status' => 404 ) );
			}
			return $document;
		}

		if ( 'credit_note' === $type ) {
			$document = CreditNoteModel::find( $id );
			if ( ! $document ) {
				return new WP_Error( 'not_found', __( 'Credit note not found.', 'doublescale' ), array( 'status' => 404 ) );
			}
			return $document;
		}

		return new WP_Error( 'invalid_type', __( 'Invalid document type.', 'doublescale' ), array( 'status' => 400 ) );
	}

	/**
	 * @param object $document Document.
	 * @param string $type     Document type.
	 * @return WP_Error|null
	 */
	private static function require_document_ownership( $document, string $type ) {
		$assigned_id = self::document_assigned_user_id( $document, $type );

		if ( Capabilities::user_can_manage_record( get_current_user_id(), $assigned_id ) ) {
			return null;
		}

		return new WP_Error( 'not_allowed', __( 'You do not have permission to access this document.', 'doublescale' ), array( 'status' => 403 ) );
	}

	/**
	 * @param object $document Document.
	 * @param string $type     Document type.
	 * @return string
	 */
	private static function document_label( $document, string $type ): string {
		if ( 'proposal' === $type ) {
			return (string) $document->proposal_number . ': ' . (string) $document->subject;
		}

		if ( 'invoice' === $type ) {
			return (string) $document->invoice_number;
		}

		if ( 'contract' === $type ) {
			return (string) $document->contract_number . ': ' . (string) $document->subject;
		}

		return (string) $document->credit_note_number;
	}

	/**
	 * @param object $document Document.
	 * @param string $type     Document type.
	 * @param string $note     Activity note.
	 * @return void
	 */
	private static function log_activity( $document, string $type, string $note ): void {
		if ( ! class_exists( ActivityModel::class ) ) {
			return;
		}

		$data = array(
			'contact_id'    => (int) $document->contact_id,
			'activity_type' => ActivityTypes::STATUS_CHANGED,
			'data'          => array(
				'title' => __( 'Sales approval', 'doublescale' ),
				'type'  => 'system',
				'note'  => $note,
			),
			'user_id'       => get_current_user_id() ?: null,
		);

		if ( 'proposal' === $type ) {
			$data['data']['proposal_id'] = (int) $document->id;
		} elseif ( 'invoice' === $type ) {
			$data['data']['invoice_id'] = (int) $document->id;
		} elseif ( 'contract' === $type ) {
			$data['data']['contract_id'] = (int) $document->id;
		} else {
			$data['data']['credit_note_id'] = (int) $document->id;
		}

		ActivityModel::create( $data );
	}
}
