<?php
/**
 * Class PipelineStageModel
 * This class is responsible for handling the pipeline stage model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Deals\Models;

use WPEloquent\Eloquent\Model;

/**
 * PipelineStageModel class
 */
class PipelineStageModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_pipeline_stages';

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
		'pipeline_id',
		'name',
		'color',
		'sort_order',
		'win_probability',
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
	 * Cast attributes
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $casts = array(
		'win_probability' => 'float',
		'sort_order' => 'integer',
	);

	/**
	 * Rules
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public $rules = array(
		'pipeline_id' => 'required|integer',
		'name' => 'required|string|max:255',
		'color' => 'nullable|string|size:7',
		'sort_order' => 'nullable|integer',
		'win_probability' => 'nullable|numeric|between:0,100',
	);

	/**
	 * Messages
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public $messages = array(
		'pipeline_id.required' => 'Pipeline ID is required.',
		'name.required' => 'Stage name is required.',
		'name.max' => 'Stage name must not exceed 255 characters.',
		'color.size' => 'Color must be a valid hex color code (7 characters).',
		'win_probability.between' => 'Win probability must be between 0 and 100.',
	);

	/**
	 * Get the pipeline this stage belongs to
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function pipeline() {
		return $this->belongsTo( PipelineModel::class, 'pipeline_id', 'id' );
	}

	/**
	 * Get the deals in this stage
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function deals() {
		return $this->hasMany( DealModel::class, 'stage_id', 'id' );
	}

	/**
	 * Get active deals (open status) in this stage
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function active_deals() {
		return $this->hasMany( DealModel::class, 'stage_id', 'id' )->where( 'status', 'open' );
	}

	/**
	 * Global-currency bucket only. Prefer `total_value_by_currency` so mixed
	 * EUR+USD deals are never added together.
	 *
	 * @since 1.0.0
	 *
	 * @return float
	 */
	public function getTotalValueAttribute() {
		$map    = $this->total_value_by_currency;
		$global = \DoubleScale\Core\Services\CurrencyResolver::global_currency();
		return (float) ( $map[ $global ] ?? 0.0 );
	}

	/**
	 * Stage total grouped by the resolved deal currency (mixed currencies are not
	 * summed together). Prefer this over the flat total for display.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, float>
	 */
	public function getTotalValueByCurrencyAttribute() {
		return \DoubleScale\Pro\Modules\Deals\Services\DealValueBreakdown::by_currency(
			$this->active_deals()->get(),
			'value'
		);
	}

	/**
	 * Get stage deal count
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function getDealCountAttribute() {
		return $this->active_deals()->count();
	}

	/**
	 * Get weighted value (sum of individual deal weighted values)
	 *
	 * @since 1.0.0
	 *
	 * @return float
	 */
	public function getWeightedValueAttribute() {
		$weighted_total = 0;
		foreach ( $this->active_deals as $deal ) {
			$weighted_total += $deal->weighted_value;
		}
		return $weighted_total;
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

		static::deleting(
			function( $stage ) {
				// Move all deals to the first stage of the same pipeline
				$first_stage = PipelineStageModel::where( 'pipeline_id', $stage->pipeline_id )
					->where( 'id', '!=', $stage->id )
					->orderBy( 'sort_order' )
					->first();

				if ( $first_stage ) {
					$stage->deals()->update( array( 'stage_id' => $first_stage->id ) );
				}
			}
		);
	}
}

