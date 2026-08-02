<?php
/**
 * Send reminder emails before proposals expire.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Communication\EmailIdentityResolver;
use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Sales\Services\SalesEmailTokens;
use DoubleScale\Modules\Sales\Services\SalesSettings;

/**
 * ExpiringProposals service.
 */
final class ExpiringProposals {

	/**
	 * @return int Number of reminders sent.
	 */
	public function run(): int {
		$days = (int) SalesSettings::get( 'proposal_expiry_reminder_days', 3 );
		if ( $days <= 0 ) {
			return 0;
		}

		$target = gmdate( 'Y-m-d', strtotime( '+' . $days . ' days', current_time( 'timestamp' ) ) );

		$proposals = ProposalModel::query()
			->whereIn( 'status', array( ProposalStatus::SENT, ProposalStatus::OPEN ) )
			->where( 'open_till', $target )
			->get();

		$sent = 0;
		foreach ( $proposals as $proposal ) {
			if ( $this->send_reminder( $proposal ) ) {
				++$sent;
			}
		}

		return $sent;
	}

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @return bool
	 */
	private function send_reminder( ProposalModel $proposal ): bool {
		$option_key = 'doublescale_pr_reminder_' . (int) $proposal->id . '_' . md5( (string) $proposal->open_till );
		if ( get_option( $option_key, false ) ) {
			return false;
		}

		$email = sanitize_email( (string) ( $proposal->email ?? '' ) );
		if ( ! is_email( $email ) ) {
			$proposal->loadMissing( 'contact' );
			if ( $proposal->contact && is_email( (string) $proposal->contact->email ) ) {
				$email = sanitize_email( (string) $proposal->contact->email );
			}
		}
		if ( ! is_email( $email ) ) {
			return false;
		}

		$url = ProposalUrl::get_public_url( $proposal );
		if ( '' === $url ) {
			return false;
		}

		$tokens  = SalesEmailTokens::for_proposal( $proposal, $url );
		$subject = sprintf(
			/* translators: %s: proposal subject */
			__( 'Reminder: Proposal expires soon — %s', 'doublescale' ),
			$tokens['subject']
		);

		$body = sprintf(
			'<p>%s</p><p><a href="%s">%s</a></p>',
			esc_html(
				sprintf(
					/* translators: %s: expiry date */
					__( 'Your proposal is valid until %s. Please review and respond before it expires.', 'doublescale' ),
					(string) $proposal->open_till
				)
			),
			esc_url( $url ),
			esc_html__( 'View Proposal', 'doublescale' )
		);

		$host_user_id = $proposal->assigned_user_id ? (int) $proposal->assigned_user_id : null;
		$identity     = EmailIdentityResolver::resolve( $host_user_id );
		$emails       = new Emails();
		$emails->from_address = $identity['from_address'];
		$emails->from_name    = $identity['from_name'];
		$emails->reply_to     = $identity['reply_to'];

		if ( ! $emails->send( $email, $subject, $body ) ) {
			return false;
		}

		update_option( $option_key, 1, false );
		return true;
	}
}
