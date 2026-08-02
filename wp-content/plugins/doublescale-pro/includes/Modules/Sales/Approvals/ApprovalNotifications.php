<?php
/**
 * Sales approval notification listener.
 *
 * @package DoubleScale\Pro\Modules\Sales\Approvals
 */

namespace DoubleScale\Pro\Modules\Sales\Approvals;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Notifications\Services\NotificationCategories;
use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Pro\Modules\Sales\Approvals\Models\ApprovalModel;

/**
 * ApprovalNotifications class.
 */
final class ApprovalNotifications {

	/**
	 * Register approval workflow notification hooks.
	 */
	public function __construct() {
		add_action( 'doublescale_sales_approval_requested', array( $this, 'on_requested' ), 10, 3 );
		add_action( 'doublescale_sales_approval_approved', array( $this, 'on_approved' ), 10, 3 );
		add_action( 'doublescale_sales_approval_rejected', array( $this, 'on_rejected' ), 10, 3 );
		add_action( 'doublescale_sales_approval_invalidated', array( $this, 'on_invalidated' ), 10, 5 );
		add_action( 'doublescale_sales_approval_withdrawn', array( $this, 'on_withdrawn' ), 10, 3 );
		add_action( 'doublescale_sales_approval_pending_reset', array( $this, 'on_pending_reset' ), 10, 4 );
	}

	/**
	 * @param ApprovalModel $approval Approval row.
	 * @param object        $document Document model.
	 * @param string        $type     Document type.
	 * @return void
	 */
	public function on_requested( $approval, $document, $type ) {
		unset( $document );

		$requester = get_userdata( (int) $approval->requested_by_user_id );
		$label     = $this->document_title( $approval, $type );
		$title     = sprintf(
			/* translators: %s: document label */
			__( 'Approval requested: %s', 'doublescale' ),
			$label
		);
		$message = sprintf(
			/* translators: %s: requester display name */
			__( '%s submitted a sales document for your review.', 'doublescale' ),
			$requester ? $requester->display_name : __( 'A team member', 'doublescale' )
		);

		$this->notify_reviewers(
			$title,
			$message,
			$this->queue_link(),
			NotificationCategories::SALES_APPROVAL_REQUESTED,
			(int) $approval->requested_by_user_id
		);
	}

	/**
	 * @param ApprovalModel $approval Approval row.
	 * @param object        $document Document model.
	 * @param string        $type     Document type.
	 * @return void
	 */
	public function on_approved( $approval, $document, $type ) {
		unset( $document );

		$reviewer = get_userdata( get_current_user_id() );
		$label    = $this->document_title( $approval, $type );
		$title    = sprintf(
			/* translators: %s: document label */
			__( 'Approved: %s', 'doublescale' ),
			$label
		);
		$message = sprintf(
			/* translators: %s: reviewer display name */
			__( '%s approved your document. You can now send it to the client.', 'doublescale' ),
			$reviewer ? $reviewer->display_name : __( 'A reviewer', 'doublescale' )
		);

		NotificationService::create(
			(int) $approval->requested_by_user_id,
			$title,
			$message,
			$this->document_link( $type, (int) $approval->document_id ),
			NotificationCategories::SALES_APPROVAL_APPROVED
		);
	}

	/**
	 * @param ApprovalModel $approval Approval row.
	 * @param object        $document Document model.
	 * @param string        $type     Document type.
	 * @return void
	 */
	public function on_rejected( $approval, $document, $type ) {
		unset( $document );

		$reviewer = get_userdata( get_current_user_id() );
		$label    = $this->document_title( $approval, $type );
		$title    = sprintf(
			/* translators: %s: document label */
			__( 'Rejected: %s', 'doublescale' ),
			$label
		);
		$reason = trim( (string) $approval->rejection_reason );
		$message = sprintf(
			/* translators: 1: reviewer display name, 2: rejection reason */
			__( '%1$s rejected your document. Reason: %2$s', 'doublescale' ),
			$reviewer ? $reviewer->display_name : __( 'A reviewer', 'doublescale' ),
			$reason
		);

		NotificationService::create(
			(int) $approval->requested_by_user_id,
			$title,
			$message,
			$this->document_link( $type, (int) $approval->document_id ),
			NotificationCategories::SALES_APPROVAL_REJECTED
		);
	}

	/**
	 * @param string $type         Document type.
	 * @param object $document     Document model.
	 * @param int    $reviewer_id  Prior reviewer user id.
	 * @param int    $requester_id Rep who requested approval.
	 * @param int    $editor_id    User who edited the document.
	 * @return void
	 */
	public function on_invalidated( $type, $document, $reviewer_id, $requester_id, $editor_id ) {
		unset( $requester_id );

		$document_id = is_object( $document ) && ! empty( $document->id ) ? (int) $document->id : 0;
		$editor      = get_userdata( (int) $editor_id );
		$label       = $this->document_title_from_model( $type, $document );
		$title  = sprintf(
			/* translators: %s: document label */
			__( 'Approval reset: %s', 'doublescale' ),
			$label
		);
		$message = sprintf(
			/* translators: %s: editor display name */
			__( '%s edited an approved document. It must be submitted for approval again before sending.', 'doublescale' ),
			$editor ? $editor->display_name : __( 'A team member', 'doublescale' )
		);

		if ( $reviewer_id > 0 ) {
			NotificationService::create(
				$reviewer_id,
				$title,
				$message,
				$this->document_link( $type, $document_id ),
				NotificationCategories::SALES_APPROVAL_INVALIDATED
			);
			return;
		}

		$this->notify_reviewers(
			$title,
			$message,
			$this->queue_link(),
			NotificationCategories::SALES_APPROVAL_INVALIDATED,
			(int) $editor_id
		);
	}

	/**
	 * @param string $type     Document type.
	 * @param object $document Document model.
	 * @param int    $user_id  Rep who withdrew the request.
	 * @return void
	 */
	public function on_withdrawn( $type, $document, $user_id ) {
		$requester = get_userdata( (int) $user_id );
		$label     = $this->document_title_from_model( $type, $document );
		$title     = sprintf(
			/* translators: %s: document label */
			__( 'Approval withdrawn: %s', 'doublescale' ),
			$label
		);
		$message = sprintf(
			/* translators: %s: requester display name */
			__( '%s withdrew an approval request and can edit the document before submitting again.', 'doublescale' ),
			$requester ? $requester->display_name : __( 'A team member', 'doublescale' )
		);

		$document_id = is_object( $document ) && ! empty( $document->id ) ? (int) $document->id : 0;

		$this->notify_reviewers(
			$title,
			$message,
			$this->document_link( $type, $document_id ),
			NotificationCategories::SALES_APPROVAL_WITHDRAWN,
			(int) $user_id
		);
	}

	/**
	 * @param string $type         Document type.
	 * @param object $document     Document model.
	 * @param int    $requester_id Rep who submitted the pending request.
	 * @param int    $editor_id    Manager who edited the document.
	 * @return void
	 */
	public function on_pending_reset( $type, $document, $requester_id, $editor_id ) {
		if ( $requester_id <= 0 ) {
			return;
		}

		$editor = get_userdata( (int) $editor_id );
		$label  = $this->document_title_from_model( $type, $document );
		$title  = sprintf(
			/* translators: %s: document label */
			__( 'Approval request reset: %s', 'doublescale' ),
			$label
		);
		$message = sprintf(
			/* translators: %s: editor display name */
			__( '%s edited your pending document. Review the changes and submit for approval again when ready.', 'doublescale' ),
			$editor ? $editor->display_name : __( 'A manager', 'doublescale' )
		);

		$document_id = is_object( $document ) && ! empty( $document->id ) ? (int) $document->id : 0;

		NotificationService::create(
			$requester_id,
			$title,
			$message,
			$this->document_link( $type, $document_id ),
			NotificationCategories::SALES_APPROVAL_PENDING_RESET
		);
	}

	/**
	 * @param string $title Title.
	 * @param string $message Message.
	 * @param array  $links Links.
	 * @param string $subcategory Subcategory.
	 * @param int    $exclude_user_id User to exclude.
	 * @return void
	 */
	private function notify_reviewers( string $title, string $message, array $links, string $subcategory, int $exclude_user_id ): void {
		$candidates = get_users(
			array(
				'capability__in' => array(
					'doublescale_approve_sales',
					'doublescale_manage_all_sales',
					'doublescale_manage',
				),
			)
		);

		$notified = array();
		foreach ( $candidates as $user ) {
			$user_id = (int) $user->ID;
			if ( $user_id === $exclude_user_id || isset( $notified[ $user_id ] ) ) {
				continue;
			}
			if ( ! Capabilities::can_approve_sales( $user_id ) ) {
				continue;
			}
			$notified[ $user_id ] = true;
			NotificationService::create( $user_id, $title, $message, $links, $subcategory );
		}
	}

	/**
	 * @return array{web: string, mobile: string}
	 */
	private function queue_link(): array {
		return array(
			'web'    => admin_url( 'admin.php?page=doublescale&path=sales/approvals' ),
			'mobile' => '/sales/approvals',
		);
	}

	/**
	 * @param string $type Document type.
	 * @param int    $id   Document id.
	 * @return array{web: string, mobile: string}
	 */
	private function document_link( string $type, int $id ): array {
		$paths = array(
			'proposal'    => 'sales/proposals/' . $id,
			'invoice'     => 'sales/invoices/' . $id,
			'contract'    => 'sales/contracts/' . $id,
			'credit_note' => 'sales/credit-notes/' . $id,
		);
		$path  = $paths[ $type ] ?? 'sales/proposals/' . $id;

		return array(
			'web'    => admin_url( 'admin.php?page=doublescale&path=' . $path ),
			'mobile' => '/' . $path,
		);
	}

	/**
	 * @param ApprovalModel $approval Approval row.
	 * @param string        $type     Document type.
	 * @return string
	 */
	private function document_title( ApprovalModel $approval, string $type ): string {
		$document = 'proposal' === $type
			? \DoubleScale\Modules\Documents\Models\ProposalModel::find( (int) $approval->document_id )
			: \DoubleScale\Modules\Documents\Models\InvoiceModel::find( (int) $approval->document_id );

		if ( ! $document ) {
			return 'proposal' === $type ? __( 'Proposal', 'doublescale' ) : __( 'Invoice', 'doublescale' );
		}

		if ( 'proposal' === $type ) {
			return (string) $document->proposal_number . ': ' . (string) $document->subject;
		}

		return (string) $document->invoice_number;
	}

	/**
	 * @param string $type     Document type.
	 * @param object $document Document model.
	 * @return string
	 */
	private function document_title_from_model( string $type, $document ): string {
		if ( ! is_object( $document ) ) {
			return $this->fallback_document_label( $type );
		}

		if ( 'proposal' === $type ) {
			return (string) $document->proposal_number . ': ' . (string) $document->subject;
		}

		if ( 'invoice' === $type ) {
			return (string) $document->invoice_number;
		}

		if ( 'contract' === $type ) {
			return (string) $document->contract_number . ': ' . (string) $document->subject;
		}

		if ( 'credit_note' === $type ) {
			return (string) $document->credit_note_number;
		}

		return $this->fallback_document_label( $type );
	}

	/**
	 * @param string $type Document type.
	 * @return string
	 */
	private function fallback_document_label( string $type ): string {
		$labels = array(
			'proposal'    => __( 'Proposal', 'doublescale' ),
			'invoice'     => __( 'Invoice', 'doublescale' ),
			'contract'    => __( 'Contract', 'doublescale' ),
			'credit_note' => __( 'Credit Note', 'doublescale' ),
		);

		return $labels[ $type ] ?? __( 'Sales document', 'doublescale' );
	}
}
