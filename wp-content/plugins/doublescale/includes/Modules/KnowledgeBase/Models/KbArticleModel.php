<?php
/**
 * Help-center article model.
 *
 * Writes go through {@see \DoubleScale\Modules\KnowledgeBase\Services\ArticleService}
 * so `search_text`, `content_hash`, the excerpt and the change hooks stay in
 * step with the body. Do not save a changed body straight through the model.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Vendor\WPEloquent\Eloquent\Model;

/**
 * KbArticleModel class.
 */
class KbArticleModel extends Model {

	public const STATUS_DRAFT     = 'draft';
	public const STATUS_PUBLISHED = 'published';

	public const VISIBILITY_PUBLIC   = 'public';
	public const VISIBILITY_PORTAL   = 'portal';
	public const VISIBILITY_INTERNAL = 'internal';

	/**
	 * Eloquent prepends `$wpdb->prefix` via the Capsule connection config.
	 *
	 * @var string
	 */
	protected $table = 'doublescale_kb_articles';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected $fillable = array(
		'category_id',
		'title',
		'slug',
		'content',
		'excerpt',
		'search_text',
		'status',
		'visibility',
		'include_in_assistant',
		'author_id',
		'position',
		'content_hash',
		'published_at',
		'post_id',
	);

	/**
	 * Casts.
	 *
	 * @var array<string, string>
	 */
	protected $casts = array(
		'category_id'          => 'integer',
		'include_in_assistant' => 'boolean',
		'author_id'            => 'integer',
		'position'             => 'integer',
		'views'                => 'integer',
		'helpful_yes'          => 'integer',
		'helpful_no'           => 'integer',
		'post_id'              => 'integer',
	);

	/**
	 * Eloquent timestamps enabled.
	 *
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * @return string[]
	 */
	public static function statuses(): array {
		return array( self::STATUS_DRAFT, self::STATUS_PUBLISHED );
	}

	/**
	 * @return string[]
	 */
	public static function visibilities(): array {
		return array( self::VISIBILITY_PUBLIC, self::VISIBILITY_PORTAL, self::VISIBILITY_INTERNAL );
	}

	/**
	 * Category the article is filed under, if any.
	 *
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function category() {
		return $this->belongsTo( KbCategoryModel::class, 'category_id', 'id' );
	}
}
