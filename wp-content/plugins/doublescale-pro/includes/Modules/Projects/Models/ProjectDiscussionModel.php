<?php
/**
 * Project discussion model.
 *
 * @package DoubleScale\Pro\Modules\Projects\Models
 */

namespace DoubleScale\Pro\Modules\Projects\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Models\UserModel;
use WPEloquent\Eloquent\Model;

/**
 * ProjectDiscussionModel class.
 */
class ProjectDiscussionModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_project_discussions';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var array<int, string>
	 */
	protected $fillable = array(
		'project_id',
		'parent_id',
		'user_id',
		'body',
		'created_at',
		'updated_at',
	);

	/**
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function project() {
		return $this->belongsTo( ProjectModel::class, 'project_id', 'id' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function user() {
		return $this->belongsTo( UserModel::class, 'user_id', 'ID' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function replies() {
		return $this->hasMany( self::class, 'parent_id', 'id' )->orderBy( 'created_at', 'asc' );
	}

	/**
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		static::deleting(
			function ( $discussion ) {
				self::where( 'parent_id', $discussion->id )->delete();
			}
		);
	}
}
