<?php
/**
 * Shared helpers for bulk write abilities.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Batch validation, per-row processing, and the write envelope.
 *
 * Partial success is the atomicity model: valid rows save, failed rows come
 * back in `errors`. There is no transaction. Hooks cannot be deferred without
 * rewriting shared model code, and rolling back after a notify has already
 * emailed someone is worse than leaving the saved rows in place.
 *
 * Schema `maxItems` is a hint, same doctrine as {@see AbilityResult::limit()}.
 * Over-cap batches are rejected outright — never truncated — because dropping
 * a write silently would leave the agent believing records it never saved.
 */
final class AbilityBulk {

	/**
	 * Default batch size, mirroring {@see AbilityResult::MAX_LIMIT}.
	 */
	public const DEFAULT_MAX_ITEMS = 100;

	/**
	 * Write ceiling. Dry-run may report a larger `matched` count so the
	 * agent can split; actual writes never exceed this. FluentCRM's cap is
	 * 5000 — we keep 500 so one call cannot fan that far.
	 */
	public const HARD_MAX_ITEMS = 500;

	/**
	 * The effective max for one ability, after the filter and the hard clamp.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ability_name Full ability name.
	 * @return int In the closed range [1, HARD_MAX_ITEMS].
	 */
	public static function max_items( string $ability_name ): int {
		/**
		 * Filter the per-ability bulk write cap.
		 *
		 * The return is clamped into [1, HARD_MAX_ITEMS]. Returning a number
		 * above the hard ceiling does not raise it.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $max          Proposed cap.
		 * @param string $ability_name Full ability name.
		 */
		$filtered = (int) apply_filters( 'doublescale_ability_bulk_max_items', self::DEFAULT_MAX_ITEMS, $ability_name );

		if ( $filtered < 1 ) {
			$filtered = 1;
		}

		return min( $filtered, self::HARD_MAX_ITEMS );
	}

	/**
	 * Whether this invocation is a preview.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return bool
	 */
	public static function is_dry_run( array $input ): bool {
		return ! empty( $input['dry_run'] );
	}

	/**
	 * Whether a per-row handler should skip the write.
	 *
	 * Injected by {@see process()} as `_dry_run` so single-record callbacks
	 * can return a preview without a second code path. Not a public input.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Row passed to a handler.
	 * @return bool
	 */
	public static function is_preview( array $input ): bool {
		return ! empty( $input['_dry_run'] );
	}

	/**
	 * Schema property for the dry_run flag, shared by every bulk ability.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public static function dry_run_property(): array {
		return array(
			'type'        => 'boolean',
			'description' => 'Preview only: count and validate matches without writing. The write cap does not apply, so "matched" is the real number. If over_cap is true, split the target before calling again without dry_run. created/updated stay 0, so this is safe to retry.',
			'default'     => false,
		);
	}

	/**
	 * Schema property for an id-list targeting mode.
	 *
	 * No `items` key — the same whole-batch schema trap as the rows array.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ability_name Full ability name.
	 * @param string $description  Field description.
	 * @return array<string, mixed>
	 */
	public static function ids_property( string $ability_name, string $description ): array {
		unset( $ability_name );

		return array(
			'type'        => 'array',
			'minItems'    => 1,
			// No maxItems: schema must not hide the real match count from
			// dry_run. PHP still rejects writes over the cap.
			'description' => $description,
		);
	}

	/**
	 * Schema property for filter targeting.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $description Field description.
	 * @param array<string, mixed> $properties  Filter criteria.
	 * @return array<string, mixed>
	 */
	public static function filter_property( string $description, array $properties ): array {
		return array(
			'type'        => 'object',
			'description' => $description,
			'properties'  => $properties,
		);
	}

	/**
	 * Validate that `$input[$key]` is a present, non-empty list within cap.
	 *
	 * Distinct codes so an agent can tell "you forgot the field" from "you
	 * sent an object instead of a list" from "split this and retry".
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input        Ability input.
	 * @param string               $key          Array key holding the rows.
	 * @param string               $ability_name Full ability name, for the cap.
	 * @param array<string, mixed> $options {
	 *     @type bool $skip_cap When true (dry_run), over-cap is not rejected.
	 * }
	 * @return WP_Error|null Null when the batch is acceptable.
	 */
	public static function validate_batch( array $input, string $key, string $ability_name, array $options = array() ): ?WP_Error {
		$missing = AbilityInput::required( $input, array( $key ) );
		if ( $missing ) {
			return $missing;
		}

		$rows = $input[ $key ];

		if ( ! is_array( $rows ) || ! self::is_list( $rows ) ) {
			return new WP_Error(
				'doublescale_invalid_batch',
				sprintf(
					/* translators: %s: field name */
					__( '%s must be a list of objects.', 'doublescale' ),
					$key
				),
				array(
					'status' => 400,
					'field'  => $key,
				)
			);
		}

		if ( array() === $rows ) {
			return new WP_Error(
				'doublescale_empty_batch',
				__( 'The batch is empty. Provide at least one row.', 'doublescale' ),
				array(
					'status' => 400,
					'field'  => $key,
				)
			);
		}

		$max      = self::max_items( $ability_name );
		$received = count( $rows );

		if ( empty( $options['skip_cap'] ) && $received > $max ) {
			return new WP_Error(
				'doublescale_batch_too_large',
				sprintf(
					/* translators: 1: maximum allowed rows, 2: number of rows received */
					__( 'This batch has %2$d rows; the limit is %1$d. Split it into smaller batches and retry.', 'doublescale' ),
					$max,
					$received
				),
				array(
					'status'   => 400,
					'max'      => $max,
					'received' => $received,
				)
			);
		}

		return null;
	}

	/**
	 * Run `$handler` on each row, catching per-row failures so one bad item
	 * cannot turn the whole batch into an opaque 500.
	 *
	 * Non-array rows never reach the handler. A thrown `\Throwable` becomes
	 * `doublescale_item_failed` — essential because ContactModel::save() throws
	 * a bare `\Exception` on identifier conflict.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $rows    Submitted rows.
	 * @param callable          $handler fn(array $row, int $index): array|WP_Error.
	 * @param array<string, mixed> $options {
	 *     @type string $ability_name Full ability name, passed to the per-row hook.
	 *     @type string $batch_id     Optional override; generated when absent.
	 *     @type bool   $dry_run      Inject `_dry_run` into each row and skip per-row hooks.
	 * }
	 * @return array{items: array<int, mixed>, errors: array<int, array<string, mixed>>, batch_id: string}
	 */
	public static function process( array $rows, callable $handler, array $options = array() ): array {
		$batch_id = ( isset( $options['batch_id'] ) && is_string( $options['batch_id'] ) && '' !== $options['batch_id'] )
			? $options['batch_id']
			: substr( md5( uniqid( 'ds_bulk_', true ) ), 0, 12 );

		$ability_name = isset( $options['ability_name'] ) ? (string) $options['ability_name'] : '';
		$dry_run      = ! empty( $options['dry_run'] );

		$items  = array();
		$errors = array();

		foreach ( $rows as $index => $row ) {
			$index = (int) $index;

			if ( ! is_array( $row ) ) {
				$shaped             = self::shape_error(
					$index,
					new WP_Error(
						'doublescale_invalid_item',
						__( 'Each row must be an object with named fields.', 'doublescale' ),
						array( 'status' => 400 )
					)
				);
				$shaped['batch_id'] = $batch_id;
				$errors[]           = $shaped;
				continue;
			}

			if ( $dry_run ) {
				$row['_dry_run'] = true;
			}

			try {
				$result = call_user_func( $handler, $row, $index );
			} catch ( \Throwable $e ) {
				$shaped             = self::shape_error(
					$index,
					new WP_Error(
						'doublescale_item_failed',
						sprintf(
							/* translators: 1: row index, 2: failure reason */
							__( 'Row %1$d failed: %2$s', 'doublescale' ),
							$index,
							$e->getMessage()
						),
						array( 'status' => 500 )
					),
					self::identity_from_row( $row )
				);
				$shaped['batch_id'] = $batch_id;
				$errors[]           = $shaped;
				continue;
			}

			if ( is_wp_error( $result ) ) {
				$shaped             = self::shape_error( $index, $result, self::identity_from_row( $row ) );
				$shaped['batch_id'] = $batch_id;
				$errors[]           = $shaped;
				continue;
			}

			$items[] = $result;

			if ( $dry_run ) {
				continue;
			}

			/**
			 * Fires after one row in a bulk write succeeds.
			 *
			 * AbilityGuard must not introspect envelope shape; per-row
			 * accountability lives here so a listener can write a timeline
			 * entry without parsing the batch result. `$batch_id` is the same
			 * value on the envelope and on every error entry, so N timeline
			 * rows from one call are distinguishable from N separate calls.
			 *
			 * @since 1.0.0
			 *
			 * @param string $ability_name Full ability name.
			 * @param int    $index        Row index in the submitted batch.
			 * @param mixed  $result       What the per-row handler returned.
			 * @param string $batch_id     Id shared with the envelope and errors.
			 * @param int    $user_id      User the write ran as.
			 */
			do_action(
				'doublescale_ability_bulk_item',
				$ability_name,
				$index,
				$result,
				$batch_id,
				(int) get_current_user_id()
			);
		}

		return array(
			'items'    => $items,
			'errors'   => $errors,
			'batch_id' => $batch_id,
		);
	}

	/**
	 * Shape one per-row error for the envelope.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $index    Row index in the submitted batch.
	 * @param WP_Error             $error    Failure.
	 * @param array<string, mixed> $identity Optional identifying fields (email, id).
	 * @return array<string, mixed>
	 */
	public static function shape_error( int $index, WP_Error $error, array $identity = array() ): array {
		$data  = $error->get_error_data();
		$extra = is_array( $data ) ? $data : array();

		return array_merge(
			array(
				'index'   => $index,
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			$identity,
			$extra
		);
	}

	/**
	 * Build the bulk write envelope.
	 *
	 * `$verb` (`created` or `updated`) is a top-level int on purpose:
	 * {@see AbilityGuard::changed_something()} reads only those keys and casts
	 * to bool. Nesting the count under `summary` would fall through to
	 * `return true` and fire a phantom audit for a batch where every row
	 * failed. `created => 0` suppresses the audit with no change to the guard.
	 *
	 * @since 1.0.0
	 *
	 * @param array{items?: array<int, mixed>, errors?: array<int, mixed>, batch_id?: string} $processed From {@see process()}.
	 * @param string               $verb  `created` or `updated`.
	 * @param array<string, mixed> $extra Additional top-level keys.
	 * @return array<string, mixed>
	 */
	public static function envelope( array $processed, string $verb, array $extra = array() ): array {
		$items  = array_values( $processed['items'] ?? array() );
		$errors = array_values( $processed['errors'] ?? array() );
		$count  = count( $items );
		$failed = count( $errors );

		$id_key      = isset( $extra['id_key'] ) ? (string) $extra['id_key'] : '';
		$applied_key = isset( $extra['applied_key'] ) ? (string) $extra['applied_key'] : 'applied_ids';
		unset( $extra['id_key'], $extra['applied_key'] );

		$applied = array();
		if ( '' !== $id_key ) {
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || ! isset( $item[ $id_key ] ) ) {
					continue;
				}
				if ( empty( $item['created'] ) && empty( $item['updated'] ) ) {
					continue;
				}
				$applied[] = (int) $item[ $id_key ];
			}
			$applied = array_values( array_unique( $applied ) );
		}

		$base = array(
			$verb         => $count,
			'failed'      => $failed,
			'total'       => $count + $failed,
			'items'       => $items,
			'errors'      => $errors,
			'partial'     => $count > 0 && $failed > 0,
			'batch_id'    => (string) ( $processed['batch_id'] ?? '' ),
			'dry_run'     => false,
			'applied_ids' => $applied,
		);

		if ( 'applied_ids' !== $applied_key ) {
			$base[ $applied_key ] = $applied;
		}

		return array_merge( $base, $extra );
	}

	/**
	 * Finalise a bulk result, turning a dry_run into a zero-write envelope.
	 *
	 * `created`/`updated` become 0 so {@see AbilityGuard} does not audit a
	 * preview. The real count moves to `would_create` / `would_update`.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $processed From {@see process()}.
	 * @param string               $verb      `created` or `updated`.
	 * @param array<string, mixed> $input     Original ability input.
	 * @param array<string, mixed> $extra     Extra envelope keys, including id_key.
	 * @return array<string, mixed>
	 */
	public static function finish( array $processed, string $verb, array $input, array $extra = array() ): array {
		$envelope = self::envelope( $processed, $verb, $extra );

		if ( ! self::is_dry_run( $input ) ) {
			return $envelope;
		}

		$would_key = 'created' === $verb ? 'would_create' : 'would_update';
		$would     = 0;
		foreach ( $envelope['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! empty( $item[ $would_key ] ) || ! empty( $item['created'] ) || ! empty( $item['updated'] ) ) {
				++$would;
			}
		}
		$envelope[ $would_key ] = $would;
		$envelope[ $verb ]      = 0;
		$envelope['dry_run']    = true;

		foreach ( array_keys( $envelope ) as $key ) {
			if ( 0 === strpos( (string) $key, 'applied_' ) ) {
				$envelope[ $key ] = array();
			}
		}

		return $envelope;
	}

	/**
	 * Count-only dry_run envelope when a filter/id list exceeds the write cap.
	 *
	 * Bypassing the cap is the point: the agent sees the real match count
	 * without a write, then splits. `created`/`updated` stay 0.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $matched       How many rows the target selects.
	 * @param string               $ability_name  Full ability name.
	 * @param string               $verb          `created` or `updated`.
	 * @param array<string, mixed> $extra         Extra envelope keys.
	 * @return array<string, mixed>
	 */
	public static function preview_over_cap( int $matched, string $ability_name, string $verb, array $extra = array() ): array {
		$max         = self::max_items( $ability_name );
		$would_key   = 'created' === $verb ? 'would_create' : 'would_update';
		$applied_key = isset( $extra['applied_key'] ) ? (string) $extra['applied_key'] : 'applied_ids';
		unset( $extra['id_key'], $extra['applied_key'] );

		$base = array(
			$verb         => 0,
			$would_key    => $matched,
			'failed'      => 0,
			'total'       => $matched,
			'matched'     => $matched,
			'max'         => $max,
			'over_cap'    => true,
			'dry_run'     => true,
			'partial'     => false,
			'items'       => array(),
			'errors'      => array(),
			'applied_ids' => array(),
			'batch_id'    => substr( md5( uniqid( 'ds_bulk_', true ) ), 0, 12 ),
			'message'     => sprintf(
				/* translators: 1: matched row count, 2: write cap */
				__( 'This target matches %1$d rows; the write limit is %2$d. Split it into smaller batches (contact_ids, or a narrower filter) before calling without dry_run.', 'doublescale' ),
				$matched,
				$max
			),
		);

		if ( 'applied_ids' !== $applied_key ) {
			$base[ $applied_key ] = array();
		}

		return array_merge( $base, $extra );
	}

	/**
	 * Resolve one targeting mode into handler rows.
	 *
	 * Exactly one of `$rows_key`, `$ids_key`, or `filter` must be present.
	 * An empty filter is refused — it would match every record.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input        Ability input.
	 * @param string               $ability_name Full ability name.
	 * @param array<string, mixed> $options {
	 *     @type string        $rows_key        Explicit row list key (contacts, notes, tasks).
	 *     @type string        $ids_key         Id-list key (contact_ids, task_ids).
	 *     @type string        $id_field        Field stamped onto expanded rows.
	 *     @type array<string> $patch_keys      Top-level fields copied onto expanded rows.
	 *     @type bool          $patch_required  Whether expanded modes need a patch.
	 *     @type callable      $querier         fn(array $filter): object Query with count()/pluck('id').
	 * }
	 * @return array{rows: array<int, array<string, mixed>>, matched: int, max: int, over_cap: bool, count_only: bool}|WP_Error
	 */
	public static function expand( array $input, string $ability_name, array $options ) {
		$rows_key = isset( $options['rows_key'] ) ? (string) $options['rows_key'] : '';
		$ids_key  = isset( $options['ids_key'] ) ? (string) $options['ids_key'] : '';
		$id_field = isset( $options['id_field'] ) ? (string) $options['id_field'] : 'id';
		$dry_run  = self::is_dry_run( $input );
		$max      = self::max_items( $ability_name );

		$has_rows   = '' !== $rows_key && array_key_exists( $rows_key, $input );
		$has_ids    = '' !== $ids_key && array_key_exists( $ids_key, $input );
		$has_filter = array_key_exists( 'filter', $input );

		if ( (int) $has_rows + (int) $has_ids + (int) $has_filter !== 1 ) {
			$modes = array();
			if ( '' !== $rows_key ) {
				$modes[] = $rows_key . ' (per-row objects)';
			}
			if ( '' !== $ids_key ) {
				$modes[] = $ids_key;
			}
			$modes[] = 'filter';

			return new WP_Error(
				'doublescale_invalid_target',
				sprintf(
					/* translators: %s: comma-separated targeting modes */
					__( 'Provide exactly one of: %s. Combining them is refused so a filter cannot silently widen a row list.', 'doublescale' ),
					implode( ', ', $modes )
				),
				array( 'status' => 400 )
			);
		}

		if ( $has_rows ) {
			$invalid = self::validate_batch(
				$input,
				$rows_key,
				$ability_name,
				array( 'skip_cap' => $dry_run )
			);
			if ( $invalid ) {
				return $invalid;
			}

			$rows    = array_values( (array) $input[ $rows_key ] );
			$matched = count( $rows );

			return array(
				'rows'       => $rows,
				'matched'    => $matched,
				'max'        => $max,
				'over_cap'   => $matched > $max,
				'count_only' => false,
			);
		}

		$patch_keys = isset( $options['patch_keys'] ) && is_array( $options['patch_keys'] )
			? $options['patch_keys']
			: array();

		$patch = array();
		foreach ( $patch_keys as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$patch[ $key ] = $input[ $key ];
			}
		}

		if ( ! empty( $options['patch_required'] ) && array() === $patch ) {
			return new WP_Error(
				'doublescale_nothing_to_update',
				__( 'Provide at least one field to apply to the matched rows.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		if ( $has_ids ) {
			$ids = self::normalize_id_list( $input[ $ids_key ], $ids_key );
			if ( $ids instanceof WP_Error ) {
				return $ids;
			}

			return self::rows_from_ids( $ids, $id_field, $patch, $ability_name, $dry_run );
		}

		$filter = $input['filter'];
		if ( ! is_array( $filter ) || self::filter_is_empty( $filter ) ) {
			return new WP_Error(
				'doublescale_empty_filter',
				__( 'filter must include at least one criterion. An empty filter would match every record and is refused.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$querier = $options['querier'] ?? null;
		if ( ! is_callable( $querier ) ) {
			return new WP_Error(
				'doublescale_invalid_target',
				__( 'This tool does not support filter targeting.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$query = call_user_func( $querier, $filter );
		if ( $query instanceof WP_Error ) {
			return $query;
		}

		$matched = (int) $query->count();

		if ( $matched < 1 ) {
			return new WP_Error(
				'doublescale_empty_batch',
				__( 'The filter matched no records.', 'doublescale' ),
				array(
					'status'  => 400,
					'matched' => 0,
				)
			);
		}

		if ( $matched > $max ) {
			if ( $dry_run ) {
				return array(
					'rows'       => array(),
					'matched'    => $matched,
					'max'        => $max,
					'over_cap'   => true,
					'count_only' => true,
				);
			}

			return new WP_Error(
				'doublescale_batch_too_large',
				sprintf(
					/* translators: 1: write cap, 2: matched count */
					__( 'This filter matches %2$d rows; the limit is %1$d. Call again with dry_run to confirm, then split into smaller batches.', 'doublescale' ),
					$max,
					$matched
				),
				array(
					'status'   => 400,
					'max'      => $max,
					'received' => $matched,
					'matched'  => $matched,
				)
			);
		}

		$ids = array();
		foreach ( $query->orderBy( 'id' )->pluck( 'id' ) as $id ) {
			$ids[] = (int) $id;
		}

		return self::rows_from_ids( $ids, $id_field, $patch, $ability_name, $dry_run );
	}

	/**
	 * Run a rows-only bulk ability with dry_run support.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input        Ability input.
	 * @param string               $rows_key     Key holding the rows.
	 * @param string               $ability_name Full ability name.
	 * @param callable             $handler      Per-row handler.
	 * @param string               $verb         `created` or `updated`.
	 * @param array<string, mixed> $extra        Envelope extras (id_key, applied_key).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run( array $input, string $rows_key, string $ability_name, callable $handler, string $verb, array $extra = array() ) {
		$dry_run = self::is_dry_run( $input );
		$invalid = self::validate_batch(
			$input,
			$rows_key,
			$ability_name,
			array( 'skip_cap' => $dry_run )
		);
		if ( $invalid ) {
			return $invalid;
		}

		$rows    = array_values( (array) $input[ $rows_key ] );
		$matched = count( $rows );
		$max     = self::max_items( $ability_name );

		$processed = self::process(
			$rows,
			$handler,
			array(
				'ability_name' => $ability_name,
				'dry_run'      => $dry_run,
			)
		);

		$extra['matched']  = $matched;
		$extra['max']      = $max;
		$extra['over_cap'] = $matched > $max;

		return self::finish( $processed, $verb, $input, $extra );
	}

	/**
	 * Expand targeting modes and process (or preview) the resulting rows.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input          Ability input.
	 * @param string               $ability_name   Full ability name.
	 * @param callable             $handler        Per-row handler.
	 * @param string               $verb           `created` or `updated`.
	 * @param array<string, mixed> $expand_options Options for {@see expand()}.
	 * @param array<string, mixed> $extra          Envelope extras (id_key, applied_key).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run_targeted( array $input, string $ability_name, callable $handler, string $verb, array $expand_options, array $extra = array() ) {
		$expanded = self::expand( $input, $ability_name, $expand_options );
		if ( $expanded instanceof WP_Error ) {
			return $expanded;
		}

		return self::dispatch( $expanded, $input, $ability_name, $handler, $verb, $extra );
	}

	/**
	 * Process expanded rows (or return a count-only preview).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $expanded     From {@see expand()}.
	 * @param array<string, mixed> $input        Original input.
	 * @param string               $ability_name Full ability name.
	 * @param callable             $handler      Per-row handler.
	 * @param string               $verb         `created` or `updated`.
	 * @param array<string, mixed> $extra        Envelope extras.
	 * @return array<string, mixed>
	 */
	public static function dispatch( array $expanded, array $input, string $ability_name, callable $handler, string $verb, array $extra = array() ): array {
		$extra['matched']  = (int) $expanded['matched'];
		$extra['max']      = (int) $expanded['max'];
		$extra['over_cap'] = ! empty( $expanded['over_cap'] );

		if ( ! empty( $expanded['count_only'] ) ) {
			return self::preview_over_cap(
				(int) $expanded['matched'],
				$ability_name,
				$verb,
				$extra
			);
		}

		$processed = self::process(
			$expanded['rows'],
			$handler,
			array(
				'ability_name' => $ability_name,
				'dry_run'      => self::is_dry_run( $input ),
			)
		);

		return self::finish( $processed, $verb, $input, $extra );
	}

	/**
	 * Turn a list of ids into handler rows, or a count-only preview when over cap.
	 *
	 * @param array<int, int>      $ids          Record ids.
	 * @param string               $id_field     Field name on each row.
	 * @param array<string, mixed> $patch        Fields applied to every row.
	 * @param string               $ability_name Full ability name.
	 * @param bool                 $dry_run      Preview.
	 * @return array{rows: array<int, array<string, mixed>>, matched: int, max: int, over_cap: bool, count_only: bool}|WP_Error
	 */
	private static function rows_from_ids( array $ids, string $id_field, array $patch, string $ability_name, bool $dry_run ) {
		$matched = count( $ids );
		$max     = self::max_items( $ability_name );

		if ( $matched > $max ) {
			if ( $dry_run ) {
				return array(
					'rows'       => array(),
					'matched'    => $matched,
					'max'        => $max,
					'over_cap'   => true,
					'count_only' => true,
				);
			}

			return new WP_Error(
				'doublescale_batch_too_large',
				sprintf(
					/* translators: 1: write cap, 2: received count */
					__( 'This batch has %2$d rows; the limit is %1$d. Split it into smaller batches and retry.', 'doublescale' ),
					$max,
					$matched
				),
				array(
					'status'   => 400,
					'max'      => $max,
					'received' => $matched,
					'matched'  => $matched,
				)
			);
		}

		$rows = array();
		foreach ( $ids as $id ) {
			$row              = $patch;
			$row[ $id_field ] = $id;
			$rows[]           = $row;
		}

		return array(
			'rows'       => $rows,
			'matched'    => $matched,
			'max'        => $max,
			'over_cap'   => false,
			'count_only' => false,
		);
	}

	/**
	 * Normalise a submitted id list.
	 *
	 * @param mixed  $value Raw input.
	 * @param string $key   Field name, for the message.
	 * @return array<int, int>|WP_Error
	 */
	public static function normalize_id_list( $value, string $key ) {
		if ( ! is_array( $value ) || ! self::is_list( $value ) ) {
			return new WP_Error(
				'doublescale_invalid_batch',
				sprintf(
					/* translators: %s: field name */
					__( '%s must be a list of record ids.', 'doublescale' ),
					$key
				),
				array(
					'status' => 400,
					'field'  => $key,
				)
			);
		}

		if ( array() === $value ) {
			return new WP_Error(
				'doublescale_empty_batch',
				__( 'The batch is empty. Provide at least one row.', 'doublescale' ),
				array(
					'status' => 400,
					'field'  => $key,
				)
			);
		}

		$ids = array();
		foreach ( $value as $index => $raw ) {
			if ( ! is_numeric( $raw ) || (int) $raw <= 0 ) {
				return new WP_Error(
					'doublescale_invalid_id',
					sprintf(
						/* translators: %s: field name */
						__( '%s must be a list of positive record ids.', 'doublescale' ),
						$key
					),
					array(
						'status' => 400,
						'field'  => $key,
						'index'  => (int) $index,
					)
				);
			}
			$ids[] = (int) $raw;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Whether a filter object has no usable criterion.
	 *
	 * @param array<string, mixed> $filter Filter.
	 * @return bool
	 */
	public static function filter_is_empty( array $filter ): bool {
		foreach ( $filter as $value ) {
			if ( true === $value ) {
				return false;
			}
			if ( is_numeric( $value ) && (float) $value != 0.0 ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- 0/"0" are empty criteria.
				return false;
			}
			if ( is_string( $value ) && '' !== trim( $value ) && ! is_numeric( $value ) ) {
				return false;
			}
			if ( is_array( $value ) && array() !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether an array is a 0-indexed list (PHP 7.4 stand-in for array_is_list).
	 *
	 * @param array<mixed> $value Value.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		$expected = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}
		return true;
	}

	/**
	 * Pull identifying fields off a row for error entries.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function identity_from_row( array $row ): array {
		$identity = array();

		if ( isset( $row['email'] ) && is_scalar( $row['email'] ) && '' !== (string) $row['email'] ) {
			$identity['email'] = (string) $row['email'];
		}

		if ( isset( $row['id'] ) && is_numeric( $row['id'] ) && (int) $row['id'] > 0 ) {
			$identity['id'] = (int) $row['id'];
		}

		if ( isset( $row['contact_id'] ) && is_numeric( $row['contact_id'] ) && (int) $row['contact_id'] > 0 ) {
			$identity['contact_id'] = (int) $row['contact_id'];
		}

		return $identity;
	}
}
