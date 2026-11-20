<?php
/**
 * A saved reply (see SupportSavedRepliesTable).
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Vendor\WPEloquent\Eloquent\Model;

/**
 * SavedReplyModel class.
 */
class SavedReplyModel extends Model {

	/**
	 * Eloquent prepends `$wpdb->prefix` via the Capsule connection config.
	 *
	 * @var string
	 */
	protected $table = 'doublescale_support_saved_replies';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var string[]
	 */
	protected $fillable = array( 'title', 'content', 'user_id', 'shared', 'use_count' );

	/**
	 * @var array<string, string>
	 */
	protected $casts = array(
		'user_id'   => 'integer',
		'shared'    => 'boolean',
		'use_count' => 'integer',
	);
}
