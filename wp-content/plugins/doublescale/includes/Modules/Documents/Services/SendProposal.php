<?php
/**
 * The one place a proposal send happens.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Services\DocumentCurrency;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Sales\Services\SalesRepNotifications;
use WP_Error;

/**
 * Extracted from RestProposalController so the REST endpoint and the MCP
 * ability cannot drift apart.
 *
 * Sending a proposal advances draft to sent, snapshots the customer party,
 * freezes the issuer block and the currency, stamps `sent_at`, writes the
 * activity row, and fires the hook other modules listen on.
 */
final class SendProposal {

	/**
	 * Refuse to send, or send and return the fresh proposal.
	 *
	 * @since 1.0.0
	 *
	 * @param ProposalModel $proposal Proposal to send.
	 * @param string        $message  Optional custom covering message.
	 * @param string        $channel  Delivery channel ('email' or 'whatsapp').
	 * @return ProposalModel|WP_Error
	 */
	public static function send( ProposalModel $proposal, string $message = '', string $channel = 'email' ) {
		$blocked = self::check_preconditions( $proposal );
		if ( $blocked instanceof WP_Error ) {
			return $blocked;
		}

		if ( 'whatsapp' !== $channel ) {
			$notifier = new ProposalNotifications();
			if ( ! $notifier->send_proposal( $proposal, $message ) ) {
				return new WP_Error(
					'email_failed',
					__( 'Failed to send the proposal email. Check the customer email and SMTP settings.', 'doublescale' ),
					array( 'status' => 500 )
				);
			}
		}

		return self::record( $proposal, $message, $channel );
	}

	/**
	 * Everything that must be true before a proposal may leave the building.
	 *
	 * @since 1.0.0
	 *
	 * @param ProposalModel $proposal Proposal.
	 * @return WP_Error|null Null when sending is allowed.
	 */
	public static function check_preconditions( ProposalModel $proposal ): ?WP_Error {
		if ( ProposalStatus::DECLINED === (string) $proposal->status ) {
			return new WP_Error(
				'invalid_status',
				__( 'Declined proposals cannot be sent.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === ProposalUrl::get_page_url() ) {
			return new WP_Error(
				'no_proposal_page',
				__( 'Create a WordPress page with the [doublescale_proposal] shortcode before sending proposals.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Last chance for another module to veto a send.
		 *
		 * @param WP_Error|null $gate     Set a WP_Error to block the send.
		 * @param string        $type     Document type.
		 * @param ProposalModel $proposal The proposal.
		 */
		$gate = apply_filters( 'doublescale_sales_send_gate', null, 'proposal', $proposal );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		return null;
	}

	/**
	 * Advance status, freeze the snapshots, and record the send.
	 *
	 * Public so the WhatsApp REST path can record after it has already
	 * dispatched, without sending a second email.
	 *
	 * @since 1.0.0
	 *
	 * @param ProposalModel $proposal Proposal.
	 * @param string        $message  Custom message.
	 * @param string        $channel  Delivery channel.
	 * @return ProposalModel
	 */
	public static function record( ProposalModel $proposal, string $message, string $channel ): ProposalModel {
		if ( ProposalStatus::DRAFT === (string) $proposal->status ) {
			$proposal->status = ProposalStatus::SENT;
		}
		DocumentCustomerDetails::snapshot_proposal_party_from_contact( $proposal );
		DocumentIssuerSnapshot::freeze_if_needed( $proposal );
		DocumentCurrency::freeze_on_send( $proposal );
		$proposal->sent_at = current_time( 'mysql' );
		$proposal->save();

		self::log_sent( $proposal, $message, $channel );

		do_action( 'doublescale_sales_proposal_sent', $proposal, $message, $channel );

		( new SalesRepNotifications() )->notify_proposal_event( $proposal, 'sent' );

		return $proposal;
	}

	/**
	 * Write the send onto the contact's timeline.
	 *
	 * @since 1.0.0
	 *
	 * @param ProposalModel $proposal Proposal.
	 * @param string        $message  Custom message.
	 * @param string        $channel  Delivery channel.
	 * @return void
	 */
	private static function log_sent( ProposalModel $proposal, string $message = '', string $channel = 'email' ): void {
		if ( ! class_exists( ActivityModel::class ) ) {
			return;
		}

		$note = 'whatsapp' === $channel
			? sprintf(
				/* translators: 1: proposal number, 2: proposal subject */
				__( 'Proposal %1$s sent via WhatsApp: %2$s', 'doublescale' ),
				(string) $proposal->proposal_number,
				(string) $proposal->subject
			)
			: sprintf(
				/* translators: 1: proposal number, 2: proposal subject */
				__( 'Proposal %1$s sent: %2$s', 'doublescale' ),
				(string) $proposal->proposal_number,
				(string) $proposal->subject
			);
		if ( '' !== trim( $message ) ) {
			$note .= ' — ' . $message;
		}

		ActivityModel::create(
			array(
				'contact_id'    => (int) $proposal->contact_id,
				'activity_type' => ActivityTypes::EMAIL_SENT,
				'data'          => array(
					'title'       => __( 'Proposal sent', 'doublescale' ),
					'type'        => 'system',
					'note'        => $note,
					'proposal_id' => (int) $proposal->id,
				),
				'user_id'       => get_current_user_id() ?: null,
			)
		);
	}
}
