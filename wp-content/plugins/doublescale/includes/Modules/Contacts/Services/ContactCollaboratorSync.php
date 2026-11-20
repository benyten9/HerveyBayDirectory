<?php
/**
 * Keeps contact collaborators in step with record assignments.
 *
 * @package DoubleScale\Modules\Contacts\Services
 */

namespace DoubleScale\Modules\Contacts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Modules\Booking\Models\BookingHostsModel;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;

/**
 * Listens to the events every module already fires when something on a
 * contact is created or reassigned, and makes the assignee a collaborator on
 * that contact. No module has to know collaborators exist.
 */
final class ContactCollaboratorSync {

	/**
	 * @return void
	 */
	public static function register(): void {
		// Deals (Pro): created, and owner changed.
		add_action( 'doublescale_deal_created_by_manager', array( self::class, 'on_deal_saved' ), 10, 1 );
		add_action( 'doublescale_automation_deal_owner_changed', array( self::class, 'on_deal_owner_changed' ), 10, 4 );

		// Tasks (Pro): a new assignment only. Re-sharing on every save undid
		// an owner's "stop sharing" the next time an old task was touched.
		add_action( 'doublescale_task_created', array( self::class, 'on_task_saved' ), 10, 1 );
		add_action( 'doublescale_task_updated', array( self::class, 'on_task_updated' ), 10, 2 );

		// Support tickets.
		add_action( 'doublescale_support_ticket_created', array( self::class, 'on_ticket_saved' ), 10, 1 );
		add_action( 'doublescale_support_ticket_updated', array( self::class, 'on_ticket_saved' ), 10, 1 );

		// Sales documents and booking hosts have no assignment action, so listen
		// on the models themselves: every save path (REST, MCP, automations,
		// portal) goes through them.
		if ( doublescale_module_data_ready( 'documents' ) ) {
			InvoiceModel::saved( array( self::class, 'on_invoice_saved' ) );
		}
		if ( doublescale_module_data_ready( 'documents' ) ) {
			ProposalModel::saved( array( self::class, 'on_proposal_saved' ) );
		}
		// Bookings: every host of a booking works its guest.
		if ( doublescale_module_data_ready( 'booking' ) ) {
			BookingHostsModel::created( array( self::class, 'on_booking_host_added' ) );
		}
	}

	/**
	 * @param object $deal Deal model.
	 * @return void
	 */
	public static function on_deal_saved( $deal ): void {
		if ( ! is_object( $deal ) ) {
			return;
		}
		ContactCollaboratorService::sync_from_assignment( 'deal', (int) ( $deal->id ?? 0 ), (int) ( $deal->contact_id ?? 0 ), (int) ( $deal->owner_id ?? 0 ) );
	}

	/**
	 * @param object|null $contact   Contact model.
	 * @param object      $deal      Deal model.
	 * @param mixed       $old_owner Previous owner.
	 * @param mixed       $new_owner New owner.
	 * @return void
	 */
	public static function on_deal_owner_changed( $contact, $deal, $old_owner, $new_owner ): void {
		unset( $contact, $old_owner );
		if ( ! is_object( $deal ) ) {
			return;
		}
		ContactCollaboratorService::sync_from_assignment( 'deal', (int) ( $deal->id ?? 0 ), (int) ( $deal->contact_id ?? 0 ), (int) $new_owner );
	}

	/**
	 * A task on a contact (directly, or through its deal) makes its assignee a collaborator.
	 *
	 * @param object $task Task model.
	 * @return void
	 */
	public static function on_task_saved( $task ): void {
		if ( ! is_object( $task ) || empty( $task->assigned_to ) ) {
			return;
		}

		$contact_id = self::task_contact_id( $task );
		if ( $contact_id > 0 ) {
			ContactCollaboratorService::sync_from_assignment( 'task', (int) ( $task->id ?? 0 ), $contact_id, (int) $task->assigned_to );
		}
	}

	/**
	 * A task saved again shares its contact only when it changed hands or
	 * records. An older Pro that passes no changes keeps the old behaviour.
	 *
	 * @param object     $task    Task model.
	 * @param array|null $changes Changed attributes.
	 * @return void
	 */
	public static function on_task_updated( $task, $changes = null ): void {
		if ( is_array( $changes ) && ! array_intersect( array( 'assigned_to', 'entity_type', 'entity_id' ), array_keys( $changes ) ) ) {
			return;
		}
		self::on_task_saved( $task );
	}

	/**
	 * @param object $ticket Ticket model.
	 * @return void
	 */
	public static function on_ticket_saved( $ticket ): void {
		if ( ! is_object( $ticket ) || empty( $ticket->agent_user_id ) ) {
			return;
		}
		ContactCollaboratorService::sync_from_assignment( 'ticket', (int) ( $ticket->id ?? 0 ), (int) ( $ticket->contact_id ?? 0 ), (int) $ticket->agent_user_id );
	}

	/**
	 * @param object $invoice Invoice model.
	 * @return void
	 */
	public static function on_invoice_saved( $invoice ): void {
		if ( ! is_object( $invoice ) || empty( $invoice->sale_agent_user_id ) ) {
			return;
		}
		ContactCollaboratorService::sync_from_assignment( 'invoice', (int) ( $invoice->id ?? 0 ), (int) ( $invoice->contact_id ?? 0 ), (int) $invoice->sale_agent_user_id );
	}

	/**
	 * @param object $proposal Proposal model.
	 * @return void
	 */
	public static function on_proposal_saved( $proposal ): void {
		if ( ! is_object( $proposal ) || empty( $proposal->assigned_user_id ) ) {
			return;
		}
		ContactCollaboratorService::sync_from_assignment( 'proposal', (int) ( $proposal->id ?? 0 ), (int) ( $proposal->contact_id ?? 0 ), (int) $proposal->assigned_user_id );
	}

	/**
	 * A host row added to a booking makes that host a collaborator on the guest.
	 *
	 * @param object $host Booking host row (booking_id, user_id).
	 * @return void
	 */
	public static function on_booking_host_added( $host ): void {
		if ( ! is_object( $host ) || empty( $host->user_id ) || empty( $host->booking_id ) ) {
			return;
		}

		$booking = BookingModel::find( (int) $host->booking_id );
		if ( ! $booking || empty( $booking->contact_id ) ) {
			return;
		}

		ContactCollaboratorService::sync_from_assignment( 'booking', (int) $booking->id, (int) $booking->contact_id, (int) $host->user_id );
	}

	/**
	 * The contact a task is about: a contact task directly, a deal task via its deal.
	 *
	 * @param object $task Task model.
	 * @return int
	 */
	private static function task_contact_id( $task ): int {
		$type = (int) ( $task->entity_type ?? 0 );
		$id   = (int) ( $task->entity_id ?? 0 );

		if ( $id <= 0 ) {
			return 0;
		}

		if ( class_exists( TaskEntityType::class ) ) {
			if ( TaskEntityType::CONTACT === $type ) {
				return $id;
			}
			if ( TaskEntityType::DEAL === $type && doublescale_module_data_ready( 'deals' ) ) {
				$deal = \DoubleScale\Pro\Modules\Deals\Models\DealModel::find( $id );
				return $deal ? (int) ( $deal->contact_id ?? 0 ) : 0;
			}
		}

		return 0;
	}
}
