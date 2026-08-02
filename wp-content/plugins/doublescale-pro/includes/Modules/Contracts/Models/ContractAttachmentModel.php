<?php
/**
 * Contract attachment model (unified `doublescale_attachments` store).
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Models\AttachmentModel as CoreAttachmentModel;

/**
 * ContractAttachmentModel class.
 */
class ContractAttachmentModel extends CoreAttachmentModel {

	/**
	 * Attachable type for sales contracts.
	 */
	public const ATTACHABLE_TYPE = 'sales_contract';

	/**
	 * @var string[]
	 */
	protected $fillable = array(
		'attachable_type',
		'attachable_id',
		'contract_id',
		'user_id',
		'contact_id',
		'file_name',
		'file_path',
		'file_type',
		'file_size',
		'file_hash',
		'driver',
		'status',
		'meta',
	);

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contract() {
		return $this->belongsTo( ContractModel::class, 'attachable_id', 'id' );
	}

	/**
	 * @param array<string, mixed> $attributes Attributes.
	 * @return $this
	 */
	public function fill( array $attributes ) {
		if ( isset( $attributes['contract_id'] ) ) {
			$attributes['attachable_type'] = self::ATTACHABLE_TYPE;
			$attributes['attachable_id']   = (int) $attributes['contract_id'];
			unset( $attributes['contract_id'] );
		}
		return parent::fill( $attributes );
	}

	/**
	 * @return int|null
	 */
	public function getContractIdAttribute() {
		return self::ATTACHABLE_TYPE === (string) $this->attachable_type
			? ( $this->attachable_id ? (int) $this->attachable_id : null )
			: null;
	}

	/**
	 * @param int|null $value Contract id.
	 * @return void
	 */
	public function setContractIdAttribute( $value ) {
		$this->attributes['attachable_type'] = self::ATTACHABLE_TYPE;
		$this->attributes['attachable_id']   = $value ? (int) $value : null;
	}
}
