<?php
/**
 * Read-only lead scoring abilities.
 *
 * @package DoubleScale\Pro\Modules\LeadScoring
 */

namespace DoubleScale\Pro\Modules\LeadScoring\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityInput;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\LeadScoring\LeadScoringManager;
use DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleLevelModel;
use DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleModel;

/**
 * "Who are my hottest leads" is the question this module exists to answer, and
 * before this it was unreachable from an agent.
 *
 * Scores are NOT a column on the contact — they live in contact meta
 * (`lead_score_points`, `lead_score_level_id`), recomputed by
 * {@see LeadScoringManager}. Reading the meta directly would report a stale
 * value for any contact whose engagement changed since the last sweep, so
 * get-contact-score goes through the manager, which recalculates.
 *
 * Ranking, by contrast, cannot afford a per-contact recalculation across the
 * whole database, so list-top-leads sorts on the stored meta and says so in its
 * description. That is a deliberate accuracy/cost trade, not an oversight.
 */
final class LeadScoringAbilities {

	/**
	 * Contact meta key holding the computed point total.
	 */
	private const META_POINTS = 'lead_score_points';

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( Permissions::class, 'can_read_contacts' );

		return array(
			'doublescale/get-contact-score'     => array(
				'module_slug'      => 'leadscoring',
				'label'            => __( 'Get contact lead score', 'doublescale' ),
				'description'      => __( 'One contact\'s current lead score in points, plus the level that score puts them in. Recalculated when you ask, so it reflects engagement up to this moment. A contact below the lowest configured level has a score but no level — that is normal, not missing data.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Contact id. Use list-contacts to find one.',
						),
					),
					'required'   => array( 'contact_id' ),
				),
				'execute_callback' => array( self::class, 'get_contact_score' ),
			),

			'doublescale/list-top-leads'        => array(
				'module_slug'      => 'leadscoring',
				'label'            => __( 'List top leads by score', 'doublescale' ),
				'description'      => __( 'Contacts ranked by lead score, highest first — the "who should I call today" list. Scores come from the last time each contact\'s engagement was scored rather than being recalculated per contact, so a very recent click may not be reflected yet; use get-contact-score for a definitive figure on one person. Contacts who have never scored are left out.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'min_points' => array(
							'type'        => 'integer',
							'description' => 'Only contacts scoring at least this many points.',
						),
						'level_id'   => array(
							'type'        => 'integer',
							'description' => 'Only contacts currently in this level. Use list-lead-score-levels to find ids.',
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
				'execute_callback' => array( self::class, 'list_top_leads' ),
			),

			'doublescale/list-lead-score-levels' => array(
				'module_slug'      => 'leadscoring',
				'label'            => __( 'List lead score levels', 'doublescale' ),
				'description'      => __( 'The score bands configured on this site with the point threshold each one starts at, lowest first. Levels are site-defined, so call this before interpreting a score as "hot" or "cold".', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'execute_callback' => array( self::class, 'list_levels' ),
			),

			'doublescale/list-lead-score-rules' => array(
				'module_slug'      => 'leadscoring',
				'label'            => __( 'List lead scoring rules', 'doublescale' ),
				'description'      => __( 'The rules that add or subtract points, with the points each carries and whether it is active. Use this to explain WHY a contact has the score they do. Read-only: this never changes a rule or rescores anyone.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array(
							'type'        => 'string',
							'description' => 'Filter by rule status.',
							'enum'        => array( 'active', 'inactive' ),
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_rules' ),
			),
		);
	}

	/**
	 * One contact's live score.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_contact_score( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'contact_id' ) ),
				AbilityInput::id( $input['contact_id'] ?? null, 'contact_id' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$contact_id = (int) $input['contact_id'];

		// Recalculates rather than reading meta, so the figure is current.
		$score = LeadScoringManager::get_lead_score( $contact_id );

		if ( false === $score ) {
			return AbilityResult::not_found( __( 'No contact found with that id.', 'doublescale' ) );
		}

		$level = is_array( $score ) ? ( $score['level'] ?? null ) : null;

		return array(
			'contact_id' => $contact_id,
			'points'     => (int) ( is_array( $score ) ? ( $score['points'] ?? 0 ) : 0 ),
			'level'      => is_object( $level )
				? array(
					'id'     => (int) $level->id,
					'name'   => (string) ( $level->name ?? '' ),
					'slug'   => (string) ( $level->slug ?? '' ),
					'points' => (int) ( $level->points ?? 0 ),
				)
				: null,
		);
	}

	/**
	 * Contacts ranked by stored score.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function list_top_leads( array $input ) {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$rows = self::query_scored_contacts( $input );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$total = count( $rows );
		$page  = array_slice( $rows, $offset, $limit );

		return AbilityResult::collection( $page, $total, $limit, $offset );
	}

	/**
	 * Configured score bands.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_levels( array $input ): array {
		unset( $input );

		$levels = array();

		foreach ( LeadScoringRuleLevelModel::query()->orderBy( 'points', 'asc' )->get() as $level ) {
			$levels[] = array(
				'id'         => (int) $level->id,
				'name'       => (string) ( $level->name ?? '' ),
				'slug'       => (string) ( $level->slug ?? '' ),
				'from_points' => (int) ( $level->points ?? 0 ),
			);
		}

		return array(
			'levels' => $levels,
			'total'  => count( $levels ),
		);
	}

	/**
	 * Scoring rules.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function list_rules( array $input ) {
		$invalid = AbilityInput::enum(
			$input['status'] ?? null,
			array( 'active', 'inactive' ),
			'status'
		);
		if ( $invalid ) {
			return $invalid;
		}

		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = LeadScoringRuleModel::query();

		if ( ! empty( $input['status'] ) ) {
			$query->where( 'status', (string) $input['status'] );
		}

		$total = (int) $query->count();

		$rows  = $query->orderBy( 'id', 'desc' )->limit( $limit )->offset( $offset )->get();
		$items = array();

		foreach ( $rows as $rule ) {
			$items[] = array(
				'id'     => (int) $rule->id,
				'title'  => (string) ( $rule->title ?? '' ),
				'status' => (string) ( $rule->status ?? '' ),
				// is_adding is what makes a rule add or subtract, so the signed
				// value is the only honest way to report its effect.
				'points' => (bool) $rule->is_adding ? (int) $rule->points : -(int) $rule->points,
				'effect' => (bool) $rule->is_adding ? 'adds' : 'subtracts',
			);
		}

		return AbilityResult::collection( $items, $total, $limit, $offset );
	}

	/**
	 * Contacts holding a score, newest-highest first.
	 *
	 * Contact meta lives in its own table with no Eloquent relation wired for
	 * this purpose, so this reads it directly. Ordering happens in SQL — sorting
	 * in PHP would need every scored contact in memory before paging.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function query_scored_contacts( array $input ) {
		global $wpdb;

		$meta_table    = $wpdb->prefix . 'doublescale_contact_meta';
		$contact_table = $wpdb->prefix . 'doublescale_contacts';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$level_id   = isset( $input['level_id'] ) ? (int) $input['level_id'] : 0;
		$min_points = isset( $input['min_points'] ) ? (int) $input['min_points'] : null;

		$sql = "SELECT c.id, c.first_name, c.last_name, c.email, c.company_name,
				CAST(p.meta_value AS SIGNED) AS points,
				l.meta_value AS level_id
			FROM {$contact_table} c
			INNER JOIN {$meta_table} p
				ON p.contact_id = c.id AND p.meta_key = %s
			LEFT JOIN {$meta_table} l
				ON l.contact_id = c.id AND l.meta_key = 'lead_score_level_id'";

		$params = array( self::META_POINTS );
		$where  = array();

		if ( null !== $min_points ) {
			$where[]  = 'CAST(p.meta_value AS SIGNED) >= %d';
			$params[] = $min_points;
		}

		if ( $level_id > 0 ) {
			$where[]  = 'l.meta_value = %s';
			$params[] = (string) $level_id;
		}

		if ( array() !== $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql .= ' ORDER BY points DESC, c.id ASC';

		// Every interpolated fragment above is a literal from this file; only
		// values are bound, so prepare() still covers all caller input.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$levels = self::level_names();
		$items  = array();

		foreach ( $rows as $row ) {
			$row_level = (int) ( $row->level_id ?? 0 );

			$items[] = array(
				'contact_id' => (int) $row->id,
				'name'       => trim( (string) $row->first_name . ' ' . (string) $row->last_name ),
				'email'      => (string) $row->email,
				'company'    => (string) ( $row->company_name ?? '' ),
				'points'     => (int) $row->points,
				'level'      => $row_level > 0
					? array(
						'id'   => $row_level,
						'name' => $levels[ $row_level ] ?? '',
					)
					: null,
			);
		}

		return $items;
	}

	/**
	 * Level id => name, so ranked rows carry a readable band.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string>
	 */
	private static function level_names(): array {
		$names = array();

		foreach ( LeadScoringRuleLevelModel::query()->get() as $level ) {
			$names[ (int) $level->id ] = (string) ( $level->name ?? '' );
		}

		return $names;
	}
}
