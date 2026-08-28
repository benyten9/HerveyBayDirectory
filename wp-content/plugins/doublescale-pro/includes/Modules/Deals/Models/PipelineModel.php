<?php
/**
 * Class PipelineModel
 * This class is responsible for handling the pipeline model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Deals\Models;

use WPEloquent\Eloquent\Model;

/**
 * PipelineModel class
 */
class PipelineModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_pipelines';

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
		'description',
		'sort_order',
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
	 * Rules
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public $rules = array(
		'name' => 'required|string|max:255',
		'sort_order' => 'nullable|integer',
	);

	/**
	 * Messages
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public $messages = array(
		'name.required' => 'Pipeline name is required.',
		'name.max' => 'Pipeline name must not exceed 255 characters.',
		'sort_order.integer' => 'Sort order must be a number.',
	);

	/**
	 * Get the pipeline stages
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function stages() {
		return $this->hasMany( PipelineStageModel::class, 'pipeline_id', 'id' )->orderBy( 'sort_order' );
	}

	/**
	 * Get the pipeline deals
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function deals() {
		return $this->hasMany( DealModel::class, 'pipeline_id', 'id' );
	}

	/**
	 * Get active deals (open status)
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function active_deals() {
		return $this->hasMany( DealModel::class, 'pipeline_id', 'id' )->where( 'status', 'open' );
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
	 * Pipeline total grouped by resolved deal currency (mixed currencies are not
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
	 * Get pipeline deal count
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function getDealCountAttribute() {
		return $this->active_deals()->count();
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
			function( $pipeline ) {
				// Delete all pipeline stages and their deals
				$pipeline->stages()->each(
					function( $stage ) {
						$stage->delete();
					}
				);
			}
		);
	}
}

