<?php


/**
 * Class LeadScoringManager
 *
 * This class is responsible for handling the Lead Scoring Manager
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\LeadScoring;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleModel;
use DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleLevelModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Conditions\Process as Process_Conditions;

class LeadScoringManager {

	/**
	 * Singleton instance (registered on the DI container by the Lead Scoring module).
	 *
	 * @var self|null
	 */
	private static $instance;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
	}

	/** Cron hook that recalculates the next batch of contacts' scores. */
	const RECALCULATE_HOOK = 'doublescale_lead_score_recalculate';

	/** Contacts scored per batch. */
	const RECALCULATE_BATCH = 200;

	/**
	 * After a lead-scoring rule or level is created, changed or deleted over REST,
	 * recalculate every contact's score: the first batch now (so the change shows
	 * at once on smaller sites), the rest in the background.
	 *
	 * @param \WP_HTTP_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_HTTP_Response
	 */
	public static function recalculate_after_rest_change( $response, $server, $request ) {
		if ( ! $request instanceof \WP_REST_Request || 'GET' === $request->get_method() ) {
			return $response;
		}
		if ( ! preg_match( '#/lead-scoring-(rules|levels)(/|$)#', (string) $request->get_route() ) ) {
			return $response;
		}
		if ( ! $response instanceof \WP_HTTP_Response || $response->get_status() >= 300 ) {
			return $response;
		}

		wp_clear_scheduled_hook( self::RECALCULATE_HOOK );
		self::recalculate_batch( 0 );

		return $response;
	}

	/**
	 * Recalculate one batch of contacts' scores, then schedule the next batch.
	 *
	 * @param int $after_id Continue after this contact ID.
	 * @return void
	 */
	public static function recalculate_batch( $after_id = 0 ) {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- batch walk by id.
				"SELECT id FROM {$wpdb->prefix}doublescale_contacts WHERE id > %d ORDER BY id ASC LIMIT %d",
				(int) $after_id,
				self::RECALCULATE_BATCH
			)
		);

		foreach ( $ids as $id ) {
			$contact = ContactModel::find( (int) $id );
			if ( $contact ) {
				self::get_lead_score( $contact );
			}
		}

		if ( count( $ids ) === self::RECALCULATE_BATCH ) {
			wp_schedule_single_event( time() + 5, self::RECALCULATE_HOOK, array( (int) end( $ids ) ) );
		}
	}

	/**
	 * Get the lead score points for a contact
	 *
	 * @param int|\DoubleScale\Pro\Modules\LeadScoring\Models\ContactModel $contact Contact ID or Contact Model.
	 *
	 * @since 1.0.0
	 *
	 * @return int The lead score points
	 */
	public static function get_lead_score_points( $contact ) {
		// Get contact model if ID is provided
		if ( is_numeric( $contact ) ) {
			$contact = ContactModel::find( $contact );
		}

		if ( ! $contact || ! $contact->exists ) {
			return 0;
		}

		// Get all active rules
		$rules = LeadScoringRuleModel::get_active_rules();

		$score = 0;

		foreach ( $rules as $rule ) {
			// Get the settings (filters/conditions) for this rule
			$filters = $rule->settings ?? array();

			if ( empty( $filters ) || empty( $filters['conditions'] ) || ! is_array( $filters['conditions'] ) ) {
				continue;
			}

			// Create an automation contact to check against rules
			$automation_contact             = new AutomationContactModel();
			$automation_contact->contact_id = $contact->id;

			// Check if the contact matches the given filters using the condition processor
			$processor = new Process_Conditions( $automation_contact, $filters['conditions'] );
			$matches   = $processor->Check();

			// Contact matches the given filters
			if ( $matches ) {
				// Add or subtract points
				if ( $rule->is_adding_points() ) {
					$score += $rule->points;
				} else {
					$score -= $rule->points;
				}
			}
		}

		// Score can't be less than 0
		if ( $score < 0 ) {
			$score = 0;
		}

		return $score;
	}

	/**
	 * Get the lead score and level for a contact
	 *
	 * @param int|\DoubleScale\Pro\Modules\LeadScoring\Models\ContactModel $contact Contact ID or Contact Model.
	 *
	 * @since 1.0.0
	 *
	 * @return array|false Array with 'points' and 'level' (level may be null), or false if contact is missing
	 */
	public static function get_lead_score( $contact ) {
		// Score cache
		static $cache;

		if ( empty( $cache ) ) {
			$cache = array();
		}

		// Get contact model if ID is provided
		if ( is_numeric( $contact ) ) {
			$contact = ContactModel::find( $contact );
		}

		if ( ! $contact || ! $contact->exists ) {
			return false;
		}

		// Check if we have a cached result
		if ( isset( $cache[ $contact->id ] ) ) {
			return $cache[ $contact->id ];
		}

		// Calculate current points
		$points = self::get_lead_score_points( $contact );

		// Get stored values (meta may be string from DB — compare as integers)
		$cur_points   = doublescale_get_contact_meta( $contact->id, 'lead_score_points', true );
		$cur_level_id = doublescale_get_contact_meta( $contact->id, 'lead_score_level_id', true );
		$cur_level    = $cur_level_id ? LeadScoringRuleLevelModel::find( $cur_level_id ) : null;

		// Get the level for the current score (highest tier where tier.points <= score)
		$new_level = LeadScoringRuleLevelModel::get_level_for_score( $points );

		// Nothing to write when the points and the level are both unchanged (levels can
		// change without the points changing: a tier added, or its threshold moved)
		if ( $cur_level && $cur_level->exists && (int) $points === (int) $cur_points
			&& $new_level && (int) $new_level->id === (int) $cur_level->id ) {
			$return                = array(
				'points' => $points,
				'level'  => $cur_level,
			);
			$cache[ $contact->id ] = $return;

			return $return;
		}

		// Always persist calculated points so segments / filters stay in sync
		doublescale_update_contact_meta( $contact->id, 'lead_score_points', $points );

		if ( ! $new_level ) {
			// Score is below the lowest configured tier, or no levels exist — still expose points
			doublescale_delete_contact_meta( $contact->id, 'lead_score_level_slug' );
			doublescale_delete_contact_meta( $contact->id, 'lead_score_level_id' );

			$return                = array(
				'points' => $points,
				'level'  => null,
			);
			$cache[ $contact->id ] = $return;

			return $return;
		}

		// Check if level changed
		if ( ! $cur_level || ! $cur_level->exists || (int) $new_level->id !== (int) $cur_level->id ) {
			doublescale_update_contact_meta( $contact->id, 'lead_score_level_slug', $new_level->slug );
			doublescale_update_contact_meta( $contact->id, 'lead_score_level_id', $new_level->id );
		}

		$return = array(
			'points' => $points,
			'level'  => $new_level,
		);

		$cache[ $contact->id ] = $return;

		return $return;
	}
}
