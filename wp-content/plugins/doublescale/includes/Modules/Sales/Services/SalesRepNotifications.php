<?php
/**
 * In-app / email / push notifications to assigned sales reps.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Notifications\Services\NotificationCategories;
use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;

/**
 * SalesRepNotifications service.
 */
final class SalesRepNotifications {

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @param string        $event Event key: sent|accepted|declined.
	 * @return void
	 */
	public function notify_proposal_event( ProposalModel $proposal, string $event ): void {
		$user_id = $proposal->assigned_user_id ? (int) $proposal->assigned_user_id : 0;
		if ( $user_id <= 0 ) {
			return;
		}

		$subcategory = $this->proposal_subcategory_for_event( $event );
		if ( ! $subcategory ) {
			return;
		}

		$rendered = SalesRepNotificationTemplates::render(
			$subcategory,
			array(
				'proposal' => $proposal,
				'event'    => $event,
			)
		);

		$this->notify_rep(
			$user_id,
			$rendered['title'],
			$rendered['message'],
			$this->proposal_links( $proposal ),
			$subcategory,
			array(
				'proposal_id' => (int) $proposal->id,
				'event'       => $event,
			)
		);
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @param string        $event Event key: sent|signed.
	 * @return void
	 */
	public function notify_contract_event( ContractModel $contract, string $event ): void {
		$user_id = $contract->assigned_user_id ? (int) $contract->assigned_user_id : 0;
		if ( $user_id <= 0 ) {
			return;
		}

		$subcategory = $this->contract_subcategory_for_event( $event );
		if ( ! $subcategory ) {
			return;
		}

		$rendered = SalesRepNotificationTemplates::render(
			$subcategory,
			array(
				'contract' => $contract,
				'event'    => $event,
			)
		);

		$this->notify_rep(
			$user_id,
			$rendered['title'],
			$rendered['message'],
			$this->contract_links( $contract ),
			$subcategory,
			array(
				'contract_id' => (int) $contract->id,
				'event'       => $event,
			)
		);
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return void
	 */
	public function notify_invoice_paid( InvoiceModel $invoice ): void {
		$user_id = $invoice->sale_agent_user_id ? (int) $invoice->sale_agent_user_id : 0;
		if ( $user_id <= 0 ) {
			return;
		}

		$rendered = SalesRepNotificationTemplates::render(
			NotificationCategories::SALES_INVOICE_PAID,
			array( 'invoice' => $invoice )
		);

		$this->notify_rep(
			$user_id,
			$rendered['title'],
			$rendered['message'],
			$this->invoice_links( $invoice ),
			NotificationCategories::SALES_INVOICE_PAID,
			array(
				'invoice_id' => (int) $invoice->id,
			)
		);
	}

	/**
	 * @param string $event Contract event key.
	 * @return string|null
	 */
	private function contract_subcategory_for_event( string $event ): ?string {
		$map = array(
			'sent'   => NotificationCategories::SALES_CONTRACT_SENT,
			'signed' => NotificationCategories::SALES_CONTRACT_SIGNED,
		);

		return $map[ $event ] ?? null;
	}

	/**
	 * @param string $event Proposal event key.
	 * @return string|null
	 */
	private function proposal_subcategory_for_event( string $event ): ?string {
		$map = array(
			'sent'     => NotificationCategories::SALES_PROPOSAL_SENT,
			'accepted' => NotificationCategories::SALES_PROPOSAL_ACCEPTED,
			'declined' => NotificationCategories::SALES_PROPOSAL_DECLINED,
		);

		return $map[ $event ] ?? null;
	}

	/**
	 * @param int    $user_id Rep user ID.
	 * @param string $title Notification title.
	 * @param string $message Notification body.
	 * @param array  $links Link payload.
	 * @param string $subcategory Notification subcategory.
	 * @param array  $metadata Optional metadata.
	 * @return void
	 */
	private function notify_rep( int $user_id, string $title, string $message, array $links, string $subcategory, array $metadata = array() ): void {
		if ( ! class_exists( NotificationService::class ) ) {
			return;
		}

		try {
			NotificationService::create( $user_id, $title, $message, $links, $subcategory, $metadata );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Sales rep notification failed',
					array(
						'source'      => 'sales-rep-notifications',
						'user_id'     => $user_id,
						'subcategory' => $subcategory,
						'error'       => $e->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @return array{web:string,mobile:string}
	 */
	private function proposal_links( ProposalModel $proposal ): array {
		$id = (int) $proposal->id;
		return array(
			'web'    => admin_url( 'admin.php?page=doublescale&path=sales/proposals/' . $id ),
			'mobile' => '/sales/proposals/' . $id,
		);
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return array{web:string,mobile:string}
	 */
	private function invoice_links( InvoiceModel $invoice ): array {
		$id = (int) $invoice->id;
		return array(
			'web'    => admin_url( 'admin.php?page=doublescale&path=sales/invoices/' . $id ),
			'mobile' => '/sales/invoices/' . $id,
		);
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @return array{web:string,mobile:string}
	 */
	private function contract_links( ContractModel $contract ): array {
		$id = (int) $contract->id;
		return array(
			'web'    => admin_url( 'admin.php?page=doublescale&path=sales/contracts/' . $id ),
			'mobile' => '/sales/contracts/' . $id,
		);
	}
}
