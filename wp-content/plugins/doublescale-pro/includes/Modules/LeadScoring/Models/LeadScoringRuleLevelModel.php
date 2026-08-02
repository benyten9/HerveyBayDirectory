<?php

/**
 * Class LeadScoringRuleLevelModel
 *
 * This class is responsible for handling the Lead Scoring Rule Level model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\LeadScoring\Models;

use WPEloquent\Eloquent\Model;

/**
 * LeadScoringRuleLevelModel class
 */
class LeadScoringRuleLevelModel extends Model {




	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_lead_scoring_rules_levels';

	/**
	 * Primary key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $primary_key = 'id';

	/**
	 * Fillable columns
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $fillable = array(
		'name',
		'slug',
		'points',
		'created_at',
		'updated_at',
	);

	/**
	 * Timestamps
	 *
	 * @var bool
	 *
	 * @since 1.0.0
	 */
	public $timestamps = true;

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'points' => 'integer',
	);


	/**
	 * Get all levels ordered by points
	 *
	 * @param string $order Order direction (asc/desc).
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	public static function get_ordered_by_points( $order = 'asc' ) {
		return self::orderBy( 'points', $order )->get();
	}

	/**
	 * Get level by slug
	 *
	 * @param string $slug Level slug.
	 *
	 * @since 1.0.0
	 *
	 * @return LeadScoringRuleLevelModel|null
	 */
	public static function get_by_slug( $slug ) {
		return self::where( 'slug', $slug )->first();
	}

	/**
	 * Get level for a given score
	 * Returns the highest level the score qualifies for
	 *
	 * @param int $score The score to check.
	 *
	 * @since 1.0.0
	 *
	 * @return LeadScoringRuleLevelModel|null
	 */
	public static function get_level_for_score( $score ) {
		return self::where( 'points', '<=', $score )
			->orderBy( 'points', 'desc' )
			->first();
	}

	/**
	 * Get next level for a given score
	 * Returns the next level that can be achieved
	 *
	 * @param int $score The current score.
	 *
	 * @since 1.0.0
	 *
	 * @return LeadScoringRuleLevelModel|null
	 */
	public static function get_next_level( $score ) {
		return self::where( 'points', '>', $score )
			->orderBy( 'points', 'asc' )
			->first();
	}

	/**
	 * Get all levels with status for a given score
	 * Adds 'is_achieved' attribute to each level
	 *
	 * @param int $score The score to check against.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	public static function get_levels_with_achievement_status( $score ) {
		$levels = self::get_ordered_by_points( 'asc' );

		return $levels->map(
			function ( $level ) use ( $score ) {
				$level->is_achieved = $score >= $level->points;
				return $level;
			}
		);
	}

	/**
	 * Boot method
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot() {
		 parent::boot();

		// Auto-generate slug from name if not provided
		static::creating(
			function ( $level ) {
				if ( empty( $level->slug ) && ! empty( $level->name ) ) {
					$level->slug = \sanitize_title( $level->name );
				}
			}
		);

		// Update slug when name changes
		static::updating(
			function ( $level ) {
				if ( $level->isDirty( 'name' ) && ! $level->isDirty( 'slug' ) ) {
					$level->slug = \sanitize_title( $level->name );
				}
			}
		);
	}
}
