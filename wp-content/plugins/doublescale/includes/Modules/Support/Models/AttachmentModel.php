<?php
/**
 * Support-scoped attachment model (unified `doublescale_attachments` store).
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Models\AttachmentModel as CoreAttachmentModel;

/**
 * AttachmentModel class.
 */
class AttachmentModel extends CoreAttachmentModel {

	/**
	 * Attachable type for support tickets.
	 */
	public const ATTACHABLE_TYPE = 'support_ticket';

	/**
	 * @var string[]
	 */
	protected $fillable = array(
		'attachable_type',
		'attachable_id',
		'ticket_id',
		'activity_id',
		'user_id',
		'contact_id',
		'file_name',
		'file_path',
		'file_type',
		'file_size',
		'file_hash',
		'content_id',
		'driver',
		'status',
		'meta',
	);

	/**
	 * Parent ticket.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function ticket() {
		return $this->belongsTo( TicketModel::class, 'attachable_id', 'id' );
	}

	/**
	 * @param array<string, mixed> $attributes Attributes.
	 * @return $this
	 */
	public function fill( array $attributes ) {
		if ( isset( $attributes['ticket_id'] ) ) {
			$attributes['attachable_type'] = self::ATTACHABLE_TYPE;
			$attributes['attachable_id']   = ! empty( $attributes['ticket_id'] ) ? (int) $attributes['ticket_id'] : null;
			unset( $attributes['ticket_id'] );
		}
		return parent::fill( $attributes );
	}

	/**
	 * Backward-compat accessor for ticket_id.
	 *
	 * @return int|null
	 */
	public function getTicketIdAttribute() {
		return self::ATTACHABLE_TYPE === (string) $this->attachable_type
			? ( $this->attachable_id ? (int) $this->attachable_id : null )
			: null;
	}

	/**
	 * Backward-compat mutator for ticket_id.
	 *
	 * @param int|null $value Ticket id.
	 * @return void
	 */
	public function setTicketIdAttribute( $value ) {
		$this->attributes['attachable_type'] = self::ATTACHABLE_TYPE;
		$this->attributes['attachable_id']   = $value ? (int) $value : null;
	}
}
