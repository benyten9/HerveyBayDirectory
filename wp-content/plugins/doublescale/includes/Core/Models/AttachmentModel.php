<?php
/**
 * Unified attachment model — polymorphic owner for all modules.
 *
 * @package DoubleScale\Core\Models
 */

namespace DoubleScale\Core\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use WPEloquent\Eloquent\Model;

/**
 * AttachmentModel class.
 */
class AttachmentModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_attachments';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var string[]
	 */
	protected $fillable = array(
		'attachable_type',
		'attachable_id',
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
	 * @var array<string, string>
	 */
	protected $casts = array(
		'meta' => 'array',
	);

	/**
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function activity() {
		return $this->belongsTo( ActivityModel::class, 'activity_id', 'id' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function user() {
		return $this->belongsTo( UserModel::class, 'user_id', 'ID' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( ContactModel::class, 'contact_id', 'id' );
	}

	/**
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeTemp( $query ) {
		return $query->where( 'status', 'temp' );
	}

	/**
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeActive( $query ) {
		return $query->where( 'status', 'active' );
	}

	/**
	 * @param string $attachable_type Owner type string.
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForType( $query, string $attachable_type ) {
		return $query->where( 'attachable_type', $attachable_type );
	}

	/**
	 * @param string $file_hash File hash.
	 * @return self|null
	 */
	public static function get_by_hash( $file_hash ) {
		$file_hash = trim( (string) $file_hash );
		if ( '' === $file_hash ) {
			return null;
		}
		return self::where( 'file_hash', $file_hash )->first();
	}

	/**
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		static::creating(
			function ( $attachment ) {
				if ( empty( $attachment->file_hash ) ) {
					try {
						$attachment->file_hash = bin2hex( random_bytes( 24 ) );
					} catch ( \Throwable $e ) {
						$attachment->file_hash = wp_generate_password( 48, false, false );
					}
				}
			}
		);

		static::deleting(
			function ( $attachment ) {
				if ( method_exists( $attachment, 'isForceDeleting' ) && ! $attachment->isForceDeleting() ) {
					return;
				}

				$absolute = self::resolve_absolute_path( (string) $attachment->file_path );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				if ( '' !== $absolute && is_file( $absolute ) && is_writable( $absolute ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
					@unlink( $absolute );
				}
			}
		);
	}

	/**
	 * @param string $relative_path Stored relative path.
	 * @return string
	 */
	public static function resolve_absolute_path( $relative_path ) {
		if ( '' === $relative_path ) {
			return '';
		}
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$upload = wp_upload_dir( null, false, false );
		if ( ! is_array( $upload ) || empty( $upload['basedir'] ) ) {
			return '';
		}
		return rtrim( (string) $upload['basedir'], '/\\' ) . '/' . ltrim( $relative_path, '/\\' );
	}
}
