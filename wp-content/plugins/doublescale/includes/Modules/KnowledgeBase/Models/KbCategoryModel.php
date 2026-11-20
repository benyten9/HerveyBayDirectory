<?php
/**
 * Help-center category model.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Vendor\WPEloquent\Eloquent\Model;

/**
 * KbCategoryModel class.
 */
class KbCategoryModel extends Model {

	/**
	 * Eloquent prepends `$wpdb->prefix` via the Capsule connection config.
	 *
	 * @var string
	 */
	protected $table = 'doublescale_kb_categories';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected $fillable = array(
		'name',
		'slug',
		'description',
		'parent_id',
		'position',
		'icon',
	);

	/**
	 * Casts.
	 *
	 * @var array<string, string>
	 */
	protected $casts = array(
		'parent_id' => 'integer',
		'position'  => 'integer',
	);

	/**
	 * Eloquent timestamps enabled.
	 *
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * Articles filed under this category.
	 *
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function articles() {
		return $this->hasMany( KbArticleModel::class, 'category_id', 'id' );
	}
}
