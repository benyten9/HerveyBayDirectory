<?php
/**
 * Mailbox model — a routing channel (department) for tickets.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * MailboxModel class.
 */
class MailboxModel extends Model {

	/**
	 * Eloquent prepends `$wpdb->prefix` via the Capsule connection config.
	 *
	 * @var string
	 */
	protected $table = 'doublescale_support_mailboxes';

	/**
	 * Primary key.
	 *
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * Mass-assignable columns. `data` is a JSON blob stored as LONGTEXT so the
	 * accessor/mutator below handle the encode/decode boundary.
	 *
	 * @var string[]
	 */
	protected $fillable = array(
		'slug',
		'email',
		'box_type',
		'is_default',
		'data',
	);

	/**
	 * Casts.
	 *
	 * @var array<string, string>
	 */
	protected $casts = array(
		'is_default' => 'boolean',
	);

	/**
	 * Eloquent timestamps enabled.
	 *
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * Tickets routed through this mailbox.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function tickets() {
		return $this->hasMany( TicketModel::class, 'mailbox_id', 'id' );
	}

	/**
	 * Decoded `data` blob as an associative array.
	 *
	 * @param mixed $value Raw value from the DB.
	 * @return array
	 */
	public function getDataAttribute( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Encode the `data` blob on write.
	 *
	 * @param mixed $value Array or pre-encoded string.
	 */
	public function setDataAttribute( $value ) {
		if ( is_array( $value ) ) {
			$this->attributes['data'] = wp_json_encode( $value );
		} else {
			$this->attributes['data'] = $value;
		}
	}

	/**
	 * Convenience accessor — display name pulled from the JSON, falling back
	 * to the slug so a half-configured mailbox still has a label.
	 *
	 * @return string
	 */
	public function getNameAttribute() {
		$data = $this->data;
		return $data['name'] ?? $this->slug;
	}

	/**
	 * The mailbox flagged as default for new tickets, or NULL.
	 *
	 * @return self|null
	 */
	public static function get_default() {
		return self::where( 'is_default', 1 )->first();
	}

	/**
	 * Override save to recover from slug UNIQUE-collisions that race past the
	 * counter loop in the boot creating event (concurrent imports, retries).
	 *
	 * Only `slug` is unique, so it is the only collision we retry. `email` is
	 * intentionally NOT unique (duplicate mailboxes are allowed), so there is no
	 * email branch here — nothing can collide on it.
	 *
	 * @param array $options Eloquent save options.
	 * @return bool
	 */
	public function save( array $options = array() ) {
		try {
			return parent::save( $options );
		} catch ( \Illuminate\Database\QueryException $e ) {
			if ( false !== strpos( $e->getMessage(), 'slug' ) ) {
				$this->slug = $this->slug . '-' . substr( wp_generate_uuid4(), 0, 8 );
				return parent::save( $options );
			}
			throw $e;
		}
	}

	/**
	 * Boot — auto-generate slug from name on create, ensure only one default.
	 *
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		static::creating(
			function ( $mailbox ) {
				if ( empty( $mailbox->slug ) ) {
					$data = $mailbox->data;
					$name = $data['name'] ?? $mailbox->email;
					$slug = sanitize_title( $name );

					if ( empty( $slug ) ) {
						$slug = 'mailbox-' . substr( md5( uniqid( '', true ) ), 0, 8 );
					}

					$base    = $slug;
					$counter = 2;
					while ( self::where( 'slug', $slug )->exists() ) {
						$slug = $base . '-' . $counter;
						++$counter;
					}

					$mailbox->slug = $slug;
				}
			}
		);

		static::saving(
			function ( $mailbox ) {
				if ( $mailbox->is_default ) {
					// Single UPDATE — MySQL already provides per-statement atomicity,
					// so no wrapping transaction is needed (and the rest of the CRM
					// avoids Eloquent transactions for compatibility reasons).
					self::where( 'id', '!=', $mailbox->id ?? 0 )
						->where( 'is_default', 1 )
						->update( array( 'is_default' => 0 ) );
				}
			}
		);

		/*
		 * Mirror the `email` column from the mailbox's own sending identity
		 * (`data.identity.from_email`) — the single chokepoint that keeps `email`
		 * in sync across every caller (REST, IMAP, WP-CLI), for EVERY box
		 * type. The from_email is the box identity used to send (From/Reply-To) and
		 * — for `email` boxes — to match inbound mail. Runs on insert AND update
		 * (the `saving` event precedes both `creating` and `updating`). Re-saves are
		 * idempotent; a missing identity keeps the value the row already holds
		 * (finally the site admin email), so the NOT NULL column is never blanked.
		 */
		static::saving(
			function ( $mailbox ) {
				self::populate_email_from_identity( $mailbox );
			}
		);
	}

	/**
	 * Mirror the `email` column from the mailbox's sending identity, for EVERY
	 * box type.
	 *
	 * A support mailbox stores its sending identity directly as
	 * `data.identity.from_email` (the same shape as the CRM Inbox identity). That
	 * address is the From/Reply-To used to send and — for `email` boxes — the
	 * address inbound mail is matched against. When no identity is set yet we keep
	 * whatever the row already has, finally falling back to the site admin email
	 * so the NOT NULL column always lands a non-empty value.
	 *
	 * @param self $mailbox Mailbox being saved.
	 * @return void
	 */
	private static function populate_email_from_identity( self $mailbox ): void {
		$data       = is_array( $mailbox->data ) ? $mailbox->data : array();
		$from_email = isset( $data['identity']['from_email'] ) ? (string) $data['identity']['from_email'] : '';

		if ( '' === $from_email ) {
			// Keep an already-set address; otherwise fall back so NOT NULL holds.
			$from_email = ! empty( $mailbox->email ) ? (string) $mailbox->email : (string) get_option( 'admin_email' );
		}

		$mailbox->email = strtolower( trim( $from_email ) );
	}
}
