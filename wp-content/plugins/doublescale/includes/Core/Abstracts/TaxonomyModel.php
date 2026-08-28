<?php

namespace DoubleScale\Core\Abstracts;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use Illuminate\Support\Str;

class TaxonomyModel extends Model {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table;


	/**
	 * Model name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $model_name;

	/**
	 * Model slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $model_slug;
	/**
	 * Primary key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $primary_key = 'id';

	/**
	 * Hidden from JSON (discriminator for unified table; implied by endpoint).
	 *
	 * @var array
	 */
	protected $hidden = array( 'type' );

	/**
	 * Fillable columns
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $fillable = array(
		'type',
		'name',
		'slug',
		'description',
		'status',
		'created_at',
		'updated_at',
	);

	/**
	 * Rules
	 *
	 * @var array
	 */
	protected $rules = array(
		'name' => 'required',
	);



	/**
	 * Timestamps
	 *
	 * @var bool
	 *
	 * @since 1.0.0
	 */
	public $timestamps = true;


	/**
	 * Get contacts
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function contacts() {
		return $this->belongsToMany(
			ContactModel::class,
			'doublescale_contact_taxonomy_relationship',
			'taxonomy_id',
			'contact_id'
		)
			->wherePivot( 'taxonomy_type', $this->model_slug )
			->withPivot( 'taxonomy_type' );
	}

	/**
	 * Count contacts on this taxonomy who can receive a campaign on the given channel.
	 *
	 * Mirrors campaign recipient rules: channel status subscribed, non-empty recipient
	 * field, and (for lists) subscribed pivot on the list relationship.
	 *
	 * @since 1.0.0
	 *
	 * @param string|int $campaign_type Campaign channel (email, sms, whatsapp).
	 * @return int
	 */
	public function get_eligible_contacts_count( $campaign_type ) {
		$channel_int = CampaignChannel::ensure_integer( $campaign_type );
		if ( null === $channel_int ) {
			return (int) $this->contacts()->distinct()->count();
		}

		$channel_string  = CampaignChannel::to_string( $channel_int );
		$status_field    = $channel_string . '_status';
		$recipient_field = CampaignChannel::get_recipient_field( $channel_int );

		if ( ! $recipient_field ) {
			return (int) $this->contacts()->distinct()->count();
		}

		global $wpdb;
		$pivot_status = $wpdb->prefix . 'doublescale_contact_taxonomy_relationship.status';
		$taxonomy_id  = (int) $this->id;

		$query = ContactModel::query()
			->where( $status_field, 'subscribed' )
			->whereNotNull( $recipient_field )
			->where( $recipient_field, '!=', '' );

		if ( 'list' === $this->model_slug ) {
			$query->whereHas(
				'lists',
				function ( $q ) use ( $taxonomy_id, $pivot_status ) {
					$q->where( $q->getModel()->getTable() . '.id', $taxonomy_id )
						->where( $pivot_status, 'subscribed' );
				}
			);
		} else {
			$query->whereHas(
				'tags',
				function ( $q ) use ( $taxonomy_id ) {
					$q->where( $q->getModel()->getTable() . '.id', $taxonomy_id );
				}
			);
		}

		return (int) $query->count();
	}

	/**
	 * Discriminator for unified `doublescale_terms.type` (e.g. list, tag).
	 * Always set on insert — do not rely only on model events (may not fire in all stacks).
	 *
	 * @since 1.0.0
	 */
	public static function type_value(): string {
		$instance = new static();
		return $instance->model_slug;
	}

	/**
	 * Get by name
	 *
	 * @param string $name Taxonomy name
	 *
	 * @return mixed
	 */
	public static function get_by_name( $name ) {
		return static::where( 'name', $name )->first();
	}

	/**
	 * Get or create taxonomy
	 *
	 * @param string $name Taxonomy name
	 *
	 * @return mixed
	 */
	public static function getOrCreate( $name ) {
		$taxonomy = static::get_by_name( $name );

		if ( ! $taxonomy ) {
			$taxonomy = static::create( array( 'name' => $name ) );
		}

		return $taxonomy;
	}

	/**
	 * Override the save method to add validation.
	 *
	 * @param array $options
	 * @return bool
	 * @throws \Exception
	 */
	public function save( array $options = array() ) {
		$dispatcher = static::getEventDispatcher();
		$model_name = static::class;
		$event_name = "eloquent.creating: {$model_name}";
		$listeners  = $dispatcher ? $dispatcher->getListeners( $event_name ) : array();

		// If no listeners, re-register events on current dispatcher
		if ( count( $listeners ) === 0 && $dispatcher ) {
			$this->registerEventsOnDispatcher( $dispatcher, $model_name );
		}

		// Do not rely solely on eloquent events — WPEloquent may swallow INSERT failures
		// when `type` / `slug` are missing (NOT NULL + UNIQUE on doublescale_terms).
		$this->ensureInsertAttributes();

		return parent::save( $options );
	}

	/**
	 * Ensure required insert columns are set before persisting a new taxonomy row.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function ensureInsertAttributes(): void {
		if ( $this->exists ) {
			return;
		}

		if ( empty( $this->getAttribute( 'type' ) ) ) {
			$this->setAttribute( 'type', static::type_value() );
		}

		$name = (string) $this->getAttribute( 'name' );
		if ( '' !== $name && empty( $this->getAttribute( 'slug' ) ) ) {
			$this->setAttribute( 'slug', static::generateUniqueSlug( $name ) );
		}
	}

	/**
	 * Generate a unique slug for this taxonomy type.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $name       Display name.
	 * @param int|null $exclude_id Row ID to exclude from uniqueness check (updates).
	 *
	 * @return string
	 */
	protected static function generateUniqueSlug( string $name, ?int $exclude_id = null ): string {
		$original_slug = Str::slug( $name );
		$slug          = $original_slug;
		$count         = 1;

		while ( static::slugExists( $slug, $exclude_id ) ) {
			$slug = $original_slug . '-' . $count++;
		}

		return $slug;
	}

	/**
	 * Whether a slug is already taken for this taxonomy type.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $slug       Candidate slug.
	 * @param int|null $exclude_id Row ID to exclude.
	 *
	 * @return bool
	 */
	protected static function slugExists( string $slug, ?int $exclude_id = null ): bool {
		$query = static::where( 'slug', $slug );

		if ( null !== $exclude_id ) {
			$query->where( 'id', '!=', $exclude_id );
		}

		return $query->exists();
	}

	/**
	 * Register events on a specific dispatcher
	 *
	 * @param object $dispatcher Event dispatcher instance
	 * @param string $model_name Model class name
	 * @return void
	 */
	private function registerEventsOnDispatcher( $dispatcher, $model_name ) {
		// Creating event — always set `type` first (unified `doublescale_terms` table).
		$dispatcher->listen(
			"eloquent.creating: {$model_name}",
			function ( $taxonomy ) {
				$class = get_class( $taxonomy );
				if ( is_subclass_of( $class, TaxonomyModel::class, true ) ) {
					$taxonomy->setAttribute( 'type', $class::type_value() );
				}

				$name = (string) $taxonomy->getAttribute( 'name' );
				if ( '' !== $name && empty( $taxonomy->getAttribute( 'slug' ) ) ) {
					$taxonomy->setAttribute( 'slug', $class::generateUniqueSlug( $name ) );
				}
			}
		);

		// Saving event
		$dispatcher->listen(
			"eloquent.saving: {$model_name}",
			function ( $taxonomy ) {
				unset( $taxonomy->contacts_count );
			}
		);

		// Deleting event
		$dispatcher->listen(
			"eloquent.deleting: {$model_name}",
			function ( $taxonomy ) {
				$taxonomy->contacts()->detach();
			}
		);

		// Retrieved event - always calculate contacts_count
		// Count distinct contacts in the relationship to ensure accuracy
		$dispatcher->listen(
			"eloquent.retrieved: {$model_name}",
			function ( $taxonomy ) {
				$taxonomy->contacts_count = $taxonomy->contacts()->distinct()->count();
			}
		);
	}

	/**
	 * Automatically add slug when creating a taxonomy using the name and boot method
	 *
	 * @since 1.0.0
	 */
	public static function boot() {
		parent::boot();

		// Get the event dispatcher
		$dispatcher = static::getEventDispatcher();
		if ( ! $dispatcher ) {
			return;
		}

		// Register events directly with the dispatcher
		$model_name = static::class;
		$instance   = new static();
		$instance->registerEventsOnDispatcher( $dispatcher, $model_name );
	}
}
