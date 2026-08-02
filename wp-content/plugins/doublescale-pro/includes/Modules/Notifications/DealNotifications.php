<?php
/**
 * Deal Notifications Handler
 * Listens to deal events and creates notifications
 *
 * @since 1.2.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Notifications;

use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Pro\Modules\Deals\Models\PipelineStageModel;

/**
 * DealNotifications class
 *
 * Handles notification creation for deal-related events:
 * - Deal won
 * - Deal lost
 * - Deal stage changed
 * - Deal assigned (on creation with owner)
 * - Deal created (broadcast to all CRM users)
 * - Deal value changed
 * - Deal note added
 * - Deal overdue (via daily cron)
 *
 * @listens doublescale_deal_created Fired from DealModel::boot() in Pro plugin
 * @listens doublescale_deal_updated Fired from DealModel::boot() in Pro plugin
 * @listens doublescale_deal_stage_changed Fired from DealModel::boot() in Pro plugin
 * @listens doublescale_deal_note_added Fired when a note is added to a deal
 *
 * @since 1.2.0
 */
class DealNotifications {

	/**
	 * Option name for tracking overdue notifications
	 *
	 * Stores array of deal IDs that have been notified.
	 *
	 * @var string
	 */
	const OVERDUE_NOTIFIED_OPTION = '_doublescale_deals_overdue_notified';

	/**
	 * Constructor - register hooks
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		if ( ! NotificationCategories::is_module_active( NotificationCategories::PIPELINE ) ) {
			return;
		}
		// Deal status changes (won/lost) and value changes.
		add_action( 'doublescale_deal_updated', array( $this, 'on_deal_updated' ), 10, 2 );

		// Deal stage changes.
		add_action( 'doublescale_deal_stage_changed', array( $this, 'on_deal_stage_changed' ), 10, 4 );

		// Deal created (for owner assignment and broadcast notifications).
		add_action( 'doublescale_deal_created', array( $this, 'on_deal_created' ), 10, 1 );

		// Deal note added.
		add_action( 'doublescale_deal_note_added', array( $this, 'on_deal_note_added' ), 10, 2 );
	}

	/**
	 * Handle deal updated event
	 *
	 * Checks for status changes (won/lost) and value changes, then notifies.
	 *
	 * @since 1.2.0
	 *
	 * @param DealModel $deal    The updated deal.
	 * @param array      $changes Array of changed attributes.
	 */
	public function on_deal_updated( $deal, $changes ) {
		$owner_id = $deal->owner_id;

		// Check if owner changed (deal reassignment).
		if ( isset( $changes['owner_id'] ) ) {
			$old_owner_id = $deal->getOriginal( 'owner_id' );
			$new_owner_id = $changes['owner_id'];
			$this->on_deal_owner_changed( $deal, $old_owner_id, $new_owner_id );
		}

		// Check if status changed.
		if ( isset( $changes['status'] ) ) {
			$new_status = $changes['status'];

			// If no owner, broadcast to all CRM users.
			if ( ! $owner_id ) {
				$this->broadcast_status_change( $deal, $new_status );
			} else {
				// Notify the owner.
				if ( 'won' === $new_status ) {
					$this->notify_deal_won( $deal, $owner_id );
				} elseif ( 'lost' === $new_status ) {
					$this->notify_deal_lost( $deal, $owner_id );
				}
			}
		}

		// Check if value changed.
		if ( isset( $changes['value'] ) ) {
			$this->on_deal_value_changed( $deal, $changes['value'] );
		}
	}

	/**
	 * Handle deal value changed
	 *
	 * @since 1.2.0
	 *
	 * @param DealModel   $deal      The deal.
	 * @param float|string $new_value The new value.
	 */
	private function on_deal_value_changed( $deal, $new_value ) {
		$owner_id     = $deal->owner_id;
		$current_user = get_current_user_id();
		$changer      = get_userdata( $current_user );
		$changer_name = $changer ? $changer->display_name : __( 'Someone', 'doublescale');

		$title   = sprintf( __( 'Deal "%s" Value Updated', 'doublescale'), $deal->title );
		$message = sprintf( __( '%1$s updated the deal value to %2$s.', 'doublescale'), $changer_name, $this->format_deal_value( $deal ) );
		$link    = $this->get_deal_link( $deal );

		if ( $owner_id && $owner_id !== $current_user ) {
			// Notify the deal owner.
			NotificationService::create(
				$owner_id,
				$title,
				$message,
				$link,
				NotificationCategories::PIPELINE_DEAL_VALUE_CHANGED
			);
		} elseif ( ! $owner_id ) {
			// No owner — broadcast to all CRM users except the person who made the change.
			NotificationService::broadcast(
				$title,
				$message,
				$link,
				NotificationCategories::PIPELINE_DEAL_VALUE_CHANGED,
				array(),
				$current_user ? array( $current_user ) : array()
			);
		}
	}

	/**
	 * Handle deal owner changed event (reassignment)
	 *
	 * Notifies the new owner they've been assigned the deal.
	 * Optionally notifies the old owner they're no longer responsible.
	 * Skips if self-assignment or if owner didn't actually change.
	 *
	 * @since 1.2.0
	 *
	 * @param DealModel $deal         The deal being reassigned.
	 * @param int|null   $old_owner_id Previous owner user ID (null if unassigned).
	 * @param int|null   $new_owner_id New owner user ID (null if unassigning).
	 */
	private function on_deal_owner_changed( $deal, $old_owner_id, $new_owner_id ) {
		$current_user = get_current_user_id();

		// Skip if owner didn't actually change (shouldn't happen, but safety check).
		if ( $old_owner_id === $new_owner_id ) {
			return;
		}

		// Get who made the change.
		$changer      = get_userdata( $current_user );
		$changer_name = $changer ? $changer->display_name : __( 'Someone', 'doublescale');

		// Notify NEW owner (skip if they reassigned to themselves).
		if ( $new_owner_id && $new_owner_id !== $current_user ) {
			NotificationService::create(
				$new_owner_id,
				/* translators: %s: deal title */
				sprintf( __( 'Deal Reassigned: "%s"', 'doublescale'), $deal->title ),
				/* translators: 1: user name, 2: deal value */
				sprintf(
					__( '%1$s assigned you a deal worth %2$s.', 'doublescale'),
					$changer_name,
					$this->format_deal_value( $deal )
				),
				$this->get_deal_link( $deal ),
				NotificationCategories::PIPELINE_DEAL_ASSIGNED
			);
		}

		// Notify OLD owner they're no longer responsible (skip if they unassigned themselves).
		if ( $old_owner_id && $old_owner_id !== $current_user ) {
			NotificationService::create(
				$old_owner_id,
				/* translators: %s: deal title */
				sprintf( __( 'Deal Unassigned: "%s"', 'doublescale'), $deal->title ),
				/* translators: %s: user name */
				sprintf(
					__( '%s removed you from this deal.', 'doublescale'),
					$changer_name
				),
				$this->get_deal_link( $deal ),
				NotificationCategories::PIPELINE_DEAL_UNASSIGNED
			);
		}
	}

	/**
	 * Handle deal stage changed event
	 *
	 * Notifies the deal owner when the deal moves to a different stage.
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact      The contact associated with the deal.
	 * @param DealModel                     $deal         The deal that changed.
	 * @param int                            $old_stage_id Previous stage ID.
	 * @param int                            $new_stage_id New stage ID.
	 */
	public function on_deal_stage_changed( $contact, $deal, $old_stage_id, $new_stage_id ) {
		$owner_id = $deal->owner_id;

		// Skip if no owner (stage changes are less critical, don't broadcast).
		if ( ! $owner_id ) {
			return;
		}

		// Get stage names.
		$old_stage = PipelineStageModel::find( $old_stage_id );
		$new_stage = PipelineStageModel::find( $new_stage_id );

		$old_stage_name = $old_stage ? $old_stage->name : __( 'Unknown', 'doublescale');
		$new_stage_name = $new_stage ? $new_stage->name : __( 'Unknown', 'doublescale');

		NotificationService::create(
			$owner_id,
			/* translators: %s: deal title */
			sprintf( __( 'Deal "%s" Stage Changed', 'doublescale'), $deal->title ),
			/* translators: 1: old stage name, 2: new stage name */
			sprintf( __( 'Moved from "%1$s" to "%2$s".', 'doublescale'), $old_stage_name, $new_stage_name ),
			$this->get_deal_link( $deal ),
			NotificationCategories::PIPELINE_DEAL_STAGE_CHANGED
		);
	}

	/**
	 * Handle deal created event
	 *
	 * Notifies the owner when they are assigned to a new deal.
	 * Notifies the deal owner only (not a team-wide broadcast).
	 * Skipped if there's no owner or the owner created the deal themselves.
	 *
	 * @since 1.2.0
	 *
	 * @param DealModel $deal The created deal.
	 */
	public function on_deal_created( $deal ) {
		$owner_id     = $deal->owner_id;
		$current_user = get_current_user_id();

		// Only notify the owner, and only if someone else created it.
		if ( ! $owner_id || $owner_id === $current_user ) {
			return;
		}

		$creator      = get_userdata( $current_user );
		$creator_name = $creator ? $creator->display_name : __( 'Someone', 'doublescale');

		NotificationService::create(
			$owner_id,
			/* translators: %s: deal title */
			sprintf( __( 'New Deal Assigned: "%s"', 'doublescale'), $deal->title ),
			/* translators: 1: creator name, 2: deal value */
			sprintf(
				__( '%1$s assigned you a deal worth %2$s.', 'doublescale'),
				$creator_name,
				$this->format_deal_value( $deal )
			),
			$this->get_deal_link( $deal ),
			NotificationCategories::PIPELINE_DEAL_ASSIGNED
		);
	}

	/**
	 * Handle deal note added event
	 *
	 * Notifies the deal owner when someone adds a note to their deal.
	 *
	 * @since 1.2.0
	 *
	 * @param DealModel $deal      The deal.
	 * @param object     $note_data Note data (content, type, etc.).
	 */
	public function on_deal_note_added( $deal, $note_data ) {
		$owner_id     = $deal->owner_id;
		$current_user = get_current_user_id();

		// Skip if no owner or owner added the note themselves.
		if ( ! $owner_id || $owner_id === $current_user ) {
			return;
		}

		// Get who added the note.
		$noter      = get_userdata( $current_user );
		$noter_name = $noter ? $noter->display_name : __( 'Someone', 'doublescale');

		NotificationService::create(
			$owner_id,
			/* translators: %s: deal title */
			sprintf( __( 'Note Added to "%s"', 'doublescale'), $deal->title ),
			/* translators: %s: user name */
			sprintf( __( '%s added a note to your deal.', 'doublescale'), $noter_name ),
			$this->get_deal_link( $deal ),
			NotificationCategories::PIPELINE_DEAL_NOTE_ADDED
		);
	}

	/**
	 * Check for overdue deals and send notifications
	 *
	 * Called by daily cron. Queries deals that are past their expected close date
	 * and haven't been notified yet. Each deal is notified only once.
	 *
	 * @since 1.2.0
	 */
	public static function check_overdue_deals() {
		$today = wp_date( 'Y-m-d' );

		// Get previously notified deal IDs (stored as single array option).
		$notified_ids = get_option( self::OVERDUE_NOTIFIED_OPTION, array() );
		if ( ! is_array( $notified_ids ) ) {
			$notified_ids = array();
		}

		// Get open deals that are past their expected close date.
		$overdue_deals = DealModel::where( 'status', 'open' )
			->whereNotNull( 'expected_close_date' )
			->where( 'expected_close_date', '<', $today )
			->whereNotNull( 'owner_id' )
			->get();

		$new_notifications = false;

		foreach ( $overdue_deals as $deal ) {
			// Skip if already notified (once per deal, ever).
			if ( in_array( $deal->id, $notified_ids, true ) ) {
				continue;
			}

			// Calculate days overdue.
			$expected_date = new \DateTime( $deal->expected_close_date );
			$now           = new \DateTime( $today );
			$days_overdue  = $now->diff( $expected_date )->days;

			// Send notification to owner.
			NotificationService::create(
				$deal->owner_id,
				/* translators: %s: deal title */
				sprintf( __( 'Deal "%s" is Overdue', 'doublescale'), $deal->title ),
				/* translators: %d: number of days overdue */
				sprintf(
					_n(
						'This deal is %d day past its expected close date.',
						'This deal is %d days past its expected close date.',
						$days_overdue,
						'doublescale'
					),
					$days_overdue
				),
				self::get_deal_link_static( $deal ),
				NotificationCategories::PIPELINE_DEAL_OVERDUE
			);

			// Mark as notified.
			$notified_ids[]    = $deal->id;
			$new_notifications = true;
		}

		// Save updated notified list if changed.
		if ( $new_notifications ) {
			update_option( self::OVERDUE_NOTIFIED_OPTION, $notified_ids, false );
		}

		// Cleanup: Remove IDs of deals that are no longer open/overdue (optional optimization).
		self::cleanup_notified_deals( $notified_ids );
	}

	/**
	 * Cleanup notified deals list
	 *
	 * Removes deal IDs from the notified list if the deal is no longer open
	 * or has been won/lost. This allows re-notification if a deal is reopened.
	 *
	 * @since 1.2.0
	 *
	 * @param array $notified_ids Current list of notified deal IDs.
	 */
	private static function cleanup_notified_deals( $notified_ids ) {
		if ( empty( $notified_ids ) ) {
			return;
		}

		// Get IDs of deals that are still open.
		$still_open_ids = DealModel::whereIn( 'id', $notified_ids )
			->where( 'status', 'open' )
			->pluck( 'id' )
			->toArray();

		// If some deals were closed, update the list.
		if ( count( $still_open_ids ) < count( $notified_ids ) ) {
			update_option( self::OVERDUE_NOTIFIED_OPTION, $still_open_ids, false );
		}
	}

	/**
	 * Notify deal owner that deal was won
	 *
	 * @since 1.2.0
	 *
	 * @param DealModel $deal     The deal.
	 * @param int        $owner_id Owner user ID.
	 */
	private function notify_deal_won( $deal, $owner_id ) {
		NotificationService::create(
			$owner_id,
			/* translators: %s: deal title */
			sprintf( __( 'Deal Won: "%s"', 'doublescale'), $deal->title ),
			/* translators: %s: deal value */
			sprintf( __( 'Congratulations! Deal worth %s has been marked as won.', 'doublescale'), $this->format_deal_value( $deal ) ),
			$this->get_deal_link( $deal ),
			NotificationCategories::PIPELINE_DEAL_WON_LOST
		);
	}

	/**
	 * Notify deal owner that deal was lost
	 *
	 * @since 1.2.0
	 *
	 * @param DealModel $deal     The deal.
	 * @param int        $owner_id Owner user ID.
	 */
	private function notify_deal_lost( $deal, $owner_id ) {
		$message = __( 'Deal has been marked as lost.', 'doublescale');

		// Include lost reason if available.
		if ( ! empty( $deal->lost_reason ) ) {
			/* translators: %s: reason for losing the deal */
			$message = sprintf( __( 'Deal has been marked as lost. Reason: %s', 'doublescale'), $deal->lost_reason );
		}

		NotificationService::create(
			$owner_id,
			/* translators: %s: deal title */
			sprintf( __( 'Deal Lost: "%s"', 'doublescale'), $deal->title ),
			$message,
			$this->get_deal_link( $deal ),
			NotificationCategories::PIPELINE_DEAL_WON_LOST
		);
	}

	/**
	 * Broadcast status change to all CRM users
	 *
	 * Used when deal has no owner assigned.
	 *
	 * @since 1.2.0
	 *
	 * @param DealModel $deal       The deal.
	 * @param string     $new_status New status (won/lost).
	 */
	private function broadcast_status_change( $deal, $new_status ) {
		if ( 'won' === $new_status ) {
			NotificationService::broadcast(
				/* translators: %s: deal title */
				sprintf( __( 'Deal Won: "%s"', 'doublescale'), $deal->title ),
				/* translators: %s: deal value */
				sprintf( __( 'A deal worth %s has been marked as won.', 'doublescale'), $this->format_deal_value( $deal ) ),
				$this->get_deal_link( $deal ),
				NotificationCategories::PIPELINE_DEAL_WON_LOST
			);
		} elseif ( 'lost' === $new_status ) {
			NotificationService::broadcast(
				/* translators: %s: deal title */
				sprintf( __( 'Deal Lost: "%s"', 'doublescale'), $deal->title ),
				__( 'A deal has been marked as lost.', 'doublescale'),
				$this->get_deal_link( $deal ),
				NotificationCategories::PIPELINE_DEAL_WON_LOST
			);
		}
	}

	/**
	 * Format deal value for display
	 *
	 * @since 1.2.0
	 *
	 * @param DealModel $deal The deal.
	 * @return string Formatted value with currency.
	 */
	private function format_deal_value( $deal ) {
		return self::format_deal_value_static( $deal );
	}

	/**
	 * Format deal value for display (static version)
	 *
	 * @since 1.2.0
	 *
	 * @param DealModel $deal The deal.
	 * @return string Formatted value with currency.
	 */
	private static function format_deal_value_static( $deal ) {
		$value    = $deal->value ?? 0;
		$currency = $deal->currency ?? \DoubleScale\Pro\Settings::get_currency();

		return $currency . ' ' . number_format( $value, 2 );
	}

	/**
	 * Get link to deal in admin
	 *
	 * @since 1.2.0
	 *
	 * @param DealModel $deal The deal.
	 * @return string Admin URL to deal.
	 */
	private function get_deal_link( $deal ) {
		return self::get_deal_link_static( $deal );
	}

	/**
	 * Get link to deal in admin (static version for cron context)
	 *
	 * @since 1.2.0
	 *
	 * @param DealModel $deal The deal.
	 * @return array Links array with web and mobile keys.
	 */
	private static function get_deal_link_static( $deal ) {
		return array(
			'web'    => admin_url( 'admin.php?page=doublescale&path=pipeline/deal&id=' . $deal->id ),
			'mobile' => '/deals/' . $deal->id,
		);
	}
}
