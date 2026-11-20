<?php
/**
 * One reader's reason an article did not help ({@see \DoubleScale\Modules\KnowledgeBase\Migrations\KbFeedbackTable}).
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Vendor\WPEloquent\Eloquent\Model;

/**
 * KbFeedbackModel class.
 */
class KbFeedbackModel extends Model {

	/**
	 * Most characters kept of one reason.
	 */
	public const MAX_LENGTH = 1000;

	/**
	 * Eloquent prepends `$wpdb->prefix` via the Capsule connection config.
	 *
	 * @var string
	 */
	protected $table = 'doublescale_kb_feedback';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected $fillable = array(
		'article_id',
		'helpful',
		'comment',
		'user_id',
		'contact_id',
	);

	/**
	 * Casts.
	 *
	 * @var array<string, string>
	 */
	protected $casts = array(
		'article_id' => 'integer',
		'helpful'    => 'boolean',
		'user_id'    => 'integer',
		'contact_id' => 'integer',
	);

	/**
	 * Only `created_at` is kept: a reason is never edited.
	 *
	 * @var bool
	 */
	public $timestamps = false;
}
