<?php
/**
 * Project team member row (see {@see \DoubleScale\Pro\Modules\Projects\Services\ProjectTeam}).
 *
 * @package DoubleScale\Pro\Modules\Projects\Models
 */

namespace DoubleScale\Pro\Modules\Projects\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Models\UserModel;
use DoubleScale\Vendor\WPEloquent\Eloquent\Model;

/**
 * ProjectMemberModel class.
 */
class ProjectMemberModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_project_members';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var array<int, string>
	 */
	protected $fillable = array(
		'project_id',
		'user_id',
		'added_by',
		'reason',
		'created_at',
	);

	/**
	 * @var bool
	 */
	public $timestamps = false;

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function project() {
		return $this->belongsTo( ProjectModel::class, 'project_id', 'id' );
	}

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function user() {
		return $this->belongsTo( UserModel::class, 'user_id', 'ID' );
	}
}
