<?php
/**
 * Notification Model
 * Model for user notifications (critical errors, system alerts)
 *
 * @since 1.2.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications\Models;

use WPEloquent\Eloquent\Model;

/**
 * NotificationModel class
 */
class NotificationModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.2.0
	 */
	protected $table = 'doublescale_notifications';

	/**
	 * Primary key
	 *
	 * @var string
	 *
	 * @since 1.2.0
	 */
	protected $primary_key = 'id';

	/**
	 * Timestamps
	 *
	 * @var bool
	 *
	 * @since 1.2.0
	 */
	public $timestamps = false;

	/**
	 * Fillable columns
	 *
	 * @var array
	 *
	 * @since 1.2.0
	 */
	protected $fillable = array(
		'user_id',
		'subcategory',
		'data',
		'is_read',
		'created_at',
	);

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'is_read' => 'boolean',
		'data'    => 'array',
	);

	/**
	 * Appended attributes (computed from data JSON or derived)
	 *
	 * @var array
	 */
	protected $appends = array( 'category', 'title', 'message', 'link', 'mobile_link', 'created_at_ts' );

	/**
	 * Get created_at_ts attribute (UTC unix timestamp)
	 *
	 * The created_at value is stored as UTC, so strtotime() directly gives the
	 * correct unix timestamp. No get_gmt_from_date() needed (would double-convert).
	 *
	 * @return int
	 */
	public function getCreatedAtTsAttribute() {
		return strtotime( $this->created_at );
	}

	/**
	 * Get category attribute (computed from subcategory)
	 *
	 * Category is not stored in DB - derived from subcategory.
	 *
	 * @return string|null
	 */
	public function getCategoryAttribute() {
		return \DoubleScale\Modules\Notifications\Services\NotificationCategories::get_category_for_subcategory( $this->subcategory );
	}

	/**
	 * Get title attribute from data JSON
	 *
	 * @return string
	 */
	public function getTitleAttribute() {
		$data = $this->data;
		return $data['title'] ?? '';
	}

	/**
	 * Get message attribute from data JSON
	 *
	 * @return string
	 */
	public function getMessageAttribute() {
		$data = $this->data;
		return $data['message'] ?? '';
	}

	/**
	 * Get link attribute (web link) from data JSON
	 *
	 * @return string|null
	 */
	public function getLinkAttribute() {
		$data = $this->data;
		return $data['links']['web'] ?? null;
	}

	/**
	 * Get mobile link attribute from data JSON
	 *
	 * @return string|null
	 */
	public function getMobileLinkAttribute() {
		$data = $this->data;
		return $data['links']['mobile'] ?? null;
	}

	/**
	 * Get metadata attribute from data JSON
	 *
	 * @return array
	 */
	public function getMetadataAttribute() {
		$data = $this->data;
		return $data['metadata'] ?? array();
	}

	/**
	 * Scope: Filter by user
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $user_id User ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForUser( $query, $user_id ) {
		return $query->where( 'user_id', $user_id );
	}

	/**
	 * Scope: Unread notifications
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeUnread( $query ) {
		return $query->where( 'is_read', 0 );
	}

	/**
	 * Scope: Filter by category
	 *
	 * Filters by all subcategories that belong to the given category.
	 * Category is not stored in DB - derived from subcategory.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query    Query builder.
	 * @param string                                $category Notification category.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByCategory( $query, $category ) {
		$subcategories = array_keys( \DoubleScale\Modules\Notifications\Services\NotificationCategories::get_subcategories( $category ) );
		return $query->whereIn( 'subcategory', $subcategories );
	}

	/**
	 * Scope: Filter by subcategory
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query       Query builder.
	 * @param string                                $subcategory Notification subcategory.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeBySubcategory( $query, $subcategory ) {
		return $query->where( 'subcategory', $subcategory );
	}

	/**
	 * Scope: Exclude system notifications for non-admin users
	 *
	 * System category notifications are restricted to administrators only.
	 * This scope automatically filters them out for non-admin users.
	 *
	 * @since 1.2.0
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query    Query builder.
	 * @param bool|null                             $is_admin Whether user is admin (null = auto-detect).
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeExcludeSystemForNonAdmin( $query, $is_admin = null ) {
		if ( null === $is_admin ) {
			$is_admin = current_user_can( 'manage_options' );
		}

		if ( ! $is_admin ) {
			$system_subcategories = array_keys(
				\DoubleScale\Modules\Notifications\Services\NotificationCategories::get_subcategories( 'system' )
			);
			if ( ! empty( $system_subcategories ) ) {
				$query->whereNotIn( 'subcategory', $system_subcategories );
			}
		}

		return $query;
	}

	/**
	 * Mark notification as read
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function markAsRead() {
		$was_unread    = ! $this->is_read;
		$this->is_read = true;
		$result        = $this->save();

		// Invalidate cache if it was actually unread
		if ( $result && $was_unread ) {
			self::invalidateCountCache( $this->user_id );
		}

		return $result;
	}

	/**
	 * Mark all notifications as read for a user
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id User ID.
	 *
	 * @return int Number of updated records.
	 */
	public static function markAllAsRead( $user_id ) {
		$count = static::query()
			->forUser( $user_id )
			->unread()
			->update( array( 'is_read' => 1 ) );

		// Invalidate cache
		if ( $count > 0 ) {
			self::invalidateCountCache( $user_id );
		}

		return $count;
	}

	/**
	 * User meta key prefix for cached unread count
	 *
	 * Full key format: {prefix}_{subcats_hash}_{admin_flag}
	 */
	const UNREAD_COUNT_META_KEY = '_doublescale_unread_notifications';

	/**
	 * Generate cache key for unread count
	 *
	 * Creates a composite key that accounts for user preferences.
	 * Key includes hash of enabled subcategories and admin status.
	 *
	 * @since 1.2.0
	 *
	 * @param array $bell_enabled_subcats Array of bell-enabled subcategory keys.
	 * @param bool  $is_admin             Whether the user is an administrator.
	 *
	 * @return string Cache key.
	 */
	private static function getCacheKey( $bell_enabled_subcats, $is_admin ) {
		// Sort subcategories for consistent hash regardless of order.
		$sorted_subcats = $bell_enabled_subcats;
		sort( $sorted_subcats );
		$subcats_hash = md5( implode( ',', $sorted_subcats ) );
		$admin_flag   = $is_admin ? '1' : '0';

		return self::UNREAD_COUNT_META_KEY . '_' . $subcats_hash . '_' . $admin_flag;
	}

	/**
	 * Get unread count for user (with preference-aware caching)
	 *
	 * Uses user meta to cache the count, avoiding DB queries on every heartbeat.
	 * Cache key includes hash of enabled subcategories and admin status.
	 * Cache is invalidated when notifications are created, read, deleted, or preferences change.
	 *
	 * @since 1.2.0
	 *
	 * @param int   $user_id              User ID.
	 * @param array $bell_enabled_subcats Array of bell-enabled subcategory keys.
	 * @param bool  $is_admin             Whether the user is an administrator.
	 *
	 * @return int
	 */
	public static function getUnreadCount( $user_id, $bell_enabled_subcats = array(), $is_admin = false ) {
		// If no subcategories enabled, count is 0.
		if ( empty( $bell_enabled_subcats ) ) {
			return 0;
		}

		$cache_key = self::getCacheKey( $bell_enabled_subcats, $is_admin );

		// Try to get cached count from user meta.
		$cached = get_user_meta( $user_id, $cache_key, true );

		// If cache exists (even if 0), return it.
		if ( $cached !== '' && $cached !== false ) {
			return (int) $cached;
		}

		// Cache miss - query DB with preference filters.
		$query = static::query()
			->forUser( $user_id )
			->unread()
			->whereIn( 'subcategory', $bell_enabled_subcats )
			->excludeSystemForNonAdmin( $is_admin );

		$count = $query->count();

		// Cache the result.
		update_user_meta( $user_id, $cache_key, $count );

		return $count;
	}

	/**
	 * Invalidate cached unread count for a user
	 *
	 * Deletes ALL cached count keys for the user (since preferences may vary).
	 * Call this whenever notifications change (create, read, delete) or preferences change.
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id User ID.
	 */
	public static function invalidateCountCache( $user_id ) {
		global $wpdb;

		// Delete all cache keys for this user that match our prefix pattern.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user_id,
				$wpdb->esc_like( self::UNREAD_COUNT_META_KEY ) . '_%'
			)
		);
	}

	/**
	 * Invalidate cached unread count for all users
	 *
	 * Use sparingly - only for bulk operations like cleanup.
	 *
	 * @since 1.2.0
	 */
	public static function invalidateAllCountCaches() {
		global $wpdb;

		// Delete all cache keys matching our prefix pattern.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( self::UNREAD_COUNT_META_KEY ) . '_%'
			)
		);
	}

	/**
	 * Delete old notifications (cleanup)
	 *
	 * @since 1.2.0
	 *
	 * @param int $days Number of days to keep.
	 *
	 * @return int Number of deleted records.
	 */
	public static function deleteOlderThan( $days = 30 ) {
		// Use UTC for consistency with created_at timestamps (stored as UTC).
		$days   = absint( $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$count  = static::query()->where( 'created_at', '<', $cutoff )->delete();

		// Invalidate all user caches since this is a bulk operation
		if ( $count > 0 ) {
			self::invalidateAllCountCaches();
		}

		return $count;
	}

	/**
	 * Boot method
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		// Set created_at on create (UTC for consistency with the rest of the codebase).
		static::creating(
			function ( $notification ) {
				if ( ! $notification->created_at ) {
					$notification->created_at = current_time( 'mysql', true );
				}
			}
		);

		// Invalidate cache after creating notification
		static::created(
			function ( $notification ) {
				self::invalidateCountCache( $notification->user_id );
			}
		);

		// Invalidate cache after deleting notification
		static::deleted(
			function ( $notification ) {
				if ( ! $notification->is_read ) {
					self::invalidateCountCache( $notification->user_id );
				}
			}
		);
	}
}
