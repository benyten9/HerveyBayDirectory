<?php
/**
 * Sales module bootstrap.
 *
 * Parent module for proposals, invoices, contracts, and shared sales settings.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Admin\AdminLoader;
use DoubleScale\Admin\MenuRegistry;
use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Container;
use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Documents\Rest\ProposalShaper;
use DoubleScale\Modules\Documents\Services\ConvertProposalToInvoice;

/**
 * Sales module.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'sales';
	}

	public function label(): string {
		return __( 'Sales', 'doublescale' );
	}

	public function description(): string {
		if ( ! \doublescale_sales_documents_ready() ) {
			return __( 'Sales tools for your team. Includes the sales pipeline; proposals and invoices are coming soon.', 'doublescale' );
		}

		return __( 'Sales workspace with pipelines, proposals, invoices, contracts, credit notes, taxes, subscriptions, and team settings.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return true;
	}

	public function dependencies(): array {
		return array( 'core', 'contacts' );
	}

	public function onActivate(): void {
		\DoubleScale\Core\UserRoles\UserRoles::provision_crm_roles();
		Capabilities::sync_capabilities_for_user_roles();
	}

	public function onDeactivate(): void {
		\DoubleScale\Core\UserRoles\UserRoles::enforce_module_scoped_roles();
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestContactSalesController::class,
			Rest\Controllers\RestSalesUsersController::class,
			Rest\Controllers\RestSalesTaxController::class,
			Rest\Controllers\RestSalesSettingsController::class,
			Rest\Controllers\RestPortalDocumentsController::class,
			Rest\Controllers\RestPortalPaymentsController::class,
			Rest\Controllers\RestSalesAnalyticsController::class,
		);
	}

	public function migrations(): array {
		if ( ! \doublescale_sales_documents_ready() ) {
			return array();
		}

		$dir = $this->module_dir() . '/Migrations';
		$shared = array(
			$dir . '/SalesTaxesTable.php',
		);

		return array_values( array_filter( $shared, 'is_file' ) );
	}

	public function boot( Container $container ): void {
		Capabilities::ensure_capabilities_synced();

		if ( ! \doublescale_sales_documents_ready() ) {
			return;
		}

		parent::boot( $container );

		GatewayManager::instance();

		new Services\SalesPortalProvider();
		new Services\SalesCalendarProvider();

		add_action( 'doublescale_sales_proposal_accepted', array( $this, 'auto_convert_accepted_proposal' ), 10, 1 );

		add_filter( 'doublescale_mail_merge_tag_groups', array( $this, 'register_merge_tag_groups' ) );

		require_once $this->module_dir() . '/MergeTags/AbstractSalesMergeTag.php';
		$this->loadModuleMergeTagFiles();

		MenuRegistry::add(
			array(
				'page_title'      => __( 'Sales Settings', 'doublescale' ),
				'menu_title'      => __( 'Sales Settings', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => 'doublescale&path=sales/settings',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 45,
				'group'           => 'sales',
				'requires_module' => 'sales',
			)
		);
	}

	/**
	 * When a customer accepts a proposal, create a draft invoice automatically.
	 *
	 * @param ProposalModel $proposal Proposal.
	 * @return void
	 */
	public function auto_convert_accepted_proposal( ProposalModel $proposal ): void {
		if ( ! function_exists( 'doublescale_is_module_active' )
			|| ! doublescale_is_module_active( 'documents' ) ) {
			return;
		}

		if ( ProposalStatus::ACCEPTED !== (string) $proposal->status ) {
			return;
		}

		if ( ProposalShaper::get_linked_invoice_id( $proposal ) ) {
			return;
		}

		$invoice = ( new ConvertProposalToInvoice() )->convert( $proposal );
		if ( is_wp_error( $invoice ) ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Auto invoice conversion failed after proposal acceptance',
					array(
						'source'      => 'sales-proposal-accept',
						'proposal_id' => (int) $proposal->id,
						'error'       => $invoice->get_error_message(),
					)
				);
			}
			return;
		}

		if ( class_exists( ActivityModel::class ) ) {
			$deal_ids = $this->get_proposal_deal_ids( $proposal );

			$data = array(
				'title'          => __( 'Invoice created from proposal', 'doublescale' ),
				'type'           => 'system',
				'note'           => sprintf(
					/* translators: 1: proposal number, 2: invoice number */
					__( 'Proposal %1$s was accepted and converted to invoice %2$s.', 'doublescale' ),
					(string) $proposal->proposal_number,
					(string) $invoice->invoice_number
				),
				'proposal_id'    => (int) $proposal->id,
				'invoice_id'     => (int) $invoice->id,
				'invoice_number' => (string) $invoice->invoice_number,
				'event_key'      => 'invoice_linked',
			);
			if ( $deal_ids ) {
				$data['deal_id'] = (int) $deal_ids[0];
			}

			$activity = ActivityModel::create(
				array(
					'contact_id'    => (int) $proposal->contact_id,
					'activity_type' => ActivityTypes::STATUS_CHANGED,
					'data'          => $data,
					'user_id'       => null,
				)
			);

			if ( $activity && class_exists( ActivityAssociationModel::class ) ) {
				$entities = array(
					array( ActivityAssociationModel::ENTITY_TYPE_PROPOSAL, (int) $proposal->id ),
					array( ActivityAssociationModel::ENTITY_TYPE_INVOICE, (int) $invoice->id ),
				);
				foreach ( $deal_ids as $deal_id ) {
					$entities[] = array( ActivityAssociationModel::ENTITY_TYPE_DEAL, $deal_id );
				}
				foreach ( $entities as $entity ) {
					ActivityAssociationModel::create(
						array(
							'activity_id' => $activity->id,
							'entity_type' => $entity[0],
							'entity_id'   => $entity[1],
						)
					);
				}
			}
		}

		do_action( 'doublescale_sales_proposal_converted_to_invoice', $proposal, $invoice );
	}

	/**
	 * Deal ids linked to a proposal via activity associations.
	 *
	 * Documents link to deals purely through activity associations (no deal_id
	 * column), so walk proposal associations to their activities and read the
	 * deal associations back out.
	 *
	 * @param ProposalModel $proposal Proposal.
	 * @return array<int>
	 */
	private function get_proposal_deal_ids( ProposalModel $proposal ): array {
		if ( ! class_exists( ActivityAssociationModel::class ) ) {
			return array();
		}

		$activity_ids = ActivityAssociationModel::where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_PROPOSAL )
			->where( 'entity_id', (int) $proposal->id )
			->pluck( 'activity_id' )
			->toArray();

		if ( empty( $activity_ids ) ) {
			return array();
		}

		$deal_ids = ActivityAssociationModel::whereIn( 'activity_id', $activity_ids )
			->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_DEAL )
			->pluck( 'entity_id' )
			->unique()
			->values()
			->toArray();

		return array_map( 'intval', $deal_ids );
	}

	/**
	 * Restrict the Sales merge-tag group to sales lifecycle triggers.
	 *
	 * @param array<string, array<string, mixed>> $groups Merge tag groups.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_merge_tag_groups( array $groups ): array {
		$disabled = ! doublescale_automation_sales_merge_tags_enabled();

		$groups['sales'] = array(
			'name'        => __( 'Sales', 'doublescale' ),
			'mergeTags'   => isset( $groups['sales']['mergeTags'] ) ? $groups['sales']['mergeTags'] : array(),
			'triggers'    => array(
				'proposal_sent',
				'proposal_declined',
				'proposal_accepted',
				'proposal_converted_to_invoice',
				'contract_sent',
				'contract_signed',
				'invoice_sent',
				'invoice_paid',
				'credit_note_sent',
				'credit_note_applied',
				'subscription_sent',
			),
			'is_disabled' => $disabled,
		);

		return $groups;
	}
}
