<?php
/**
 * Sales document approval model.
 *
 * @package DoubleScale\Pro\Modules\Sales\Approvals
 */

namespace DoubleScale\Pro\Modules\Sales\Approvals\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Sales\Approvals\Constants\ApprovalStatus;
use WPEloquent\Eloquent\Model;

/**
 * ApprovalModel class.
 */
class ApprovalModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_sales_approvals';

	/**
	 * @var string
	 */
	protected $primaryKey = 'id';

	/**
	 * @var array<int, string>
	 */
	protected $fillable = array(
		'document_type',
		'document_id',
		'status',
		'requested_by_user_id',
		'requested_at',
		'reviewed_by_user_id',
		'reviewed_at',
		'rejection_reason',
	);

	/**
	 * @param \WPEloquent\Eloquent\Builder $query Query builder.
	 * @param string                         $type  Document type.
	 * @param int                            $id    Document id.
	 * @return \WPEloquent\Eloquent\Builder
	 */
	public function scopeForDocument( $query, string $type, int $id ) {
		return $query->where( 'document_type', $type )->where( 'document_id', $id );
	}

	/**
	 * @param \WPEloquent\Eloquent\Builder $query Query builder.
	 * @return \WPEloquent\Eloquent\Builder
	 */
	public function scopePending( $query ) {
		return $query->where( 'status', ApprovalStatus::PENDING );
	}
}
