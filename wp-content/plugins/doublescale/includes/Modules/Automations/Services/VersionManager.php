<?php

/**
 * Class VersionManager
 *
 * Captures full snapshots of an automation (its row + all steps) after each
 * mutating change and restores them, enabling undo / redo (rollback) of a
 * workflow. History is capped at the most recent {@see self::MAX_VERSIONS}
 * snapshots per automation.
 *
 * The "current" position within the history is stored in the automation's own
 * `settings` JSON under {@see self::CURSOR_KEY}, so undo / redo survive page
 * reloads and work across sessions without an extra column.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationVersionModel;

/**
 * VersionManager class
 */
final class VersionManager {

	/**
	 * Maximum number of versions retained per automation.
	 *
	 * @var int
	 */
	const MAX_VERSIONS = 20;

	/**
	 * Settings key holding the current version cursor (the id of the version
	 * representing the automation's present on-screen state).
	 *
	 * @var string
	 */
	const CURSOR_KEY = '_version_cursor';

	/**
	 * Step statuses considered "live" (i.e. part of a snapshot). Soft-deleted
	 * steps are excluded.
	 *
	 * @var array
	 */
	private static $live_statuses = array( 'active', 'draft', 'disabled' );

	/**
	 * When true, snapshotting is suppressed. Set while a restore is in
	 * progress so re-saving steps does not create new versions.
	 *
	 * @var bool
	 */
	private static $restoring = false;

	/**
	 * @var VersionManager|null
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return VersionManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Whether a restore is currently in progress (snapshotting suppressed).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_restoring() {
		return self::$restoring;
	}

	/**
	 * Capture a snapshot of the automation's current state.
	 *
	 * If the cursor is not at the newest version (i.e. the user undid and is now
	 * making a fresh edit), the "redo" tail is discarded first, matching standard
	 * undo / redo semantics.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $automation_id Automation ID.
	 * @param string $label         Short human description of the change.
	 *
	 * @return AutomationVersionModel|null The created version, or null if skipped.
	 */
	public function snapshot( $automation_id, $label = '' ) {
		if ( self::$restoring ) {
			return null;
		}

		$automation = AutomationModel::find( $automation_id );
		if ( ! $automation ) {
			return null;
		}

		try {
			$cursor = $this->get_cursor( $automation );

			// Discard the redo tail: any version created after the current cursor.
			// (When the user undid and is now making a fresh edit.)
			if ( $cursor ) {
				$cursor_version = AutomationVersionModel::find( $cursor );
				if ( $cursor_version ) {
					AutomationVersionModel::where( 'automation_id', $automation_id )
						->where( 'version', '>', $cursor_version->version )
						->delete();
				}
			}

			// Sequence number continues from the highest remaining version.
			$next_version = (int) AutomationVersionModel::where( 'automation_id', $automation_id )->max( 'version' ) + 1;

			$version = AutomationVersionModel::create(
				array(
					'automation_id' => $automation_id,
					'version'       => $next_version,
					'label'         => (string) $label,
					'snapshot'      => $this->build_snapshot( $automation ),
					'created_by'    => get_current_user_id() ?: null,
				)
			);

			$this->set_cursor( $automation, $version->id );
			$this->prune( $automation_id );

			return $version;
		} catch ( \Throwable $e ) {
			$this->log_error( 'snapshot', $automation_id, $e );
			return null;
		}
	}

	/**
	 * Move one step backward in history (undo).
	 *
	 * @since 1.0.0
	 *
	 * @param int $automation_id Automation ID.
	 *
	 * @return bool True if a restore happened.
	 */
	public function undo( $automation_id ) {
		return $this->step_to_neighbor( $automation_id, 'prev' );
	}

	/**
	 * Move one step forward in history (redo).
	 *
	 * @since 1.0.0
	 *
	 * @param int $automation_id Automation ID.
	 *
	 * @return bool True if a restore happened.
	 */
	public function redo( $automation_id ) {
		return $this->step_to_neighbor( $automation_id, 'next' );
	}

	/**
	 * Restore the version immediately before/after the cursor (undo / redo).
	 *
	 * @since 1.0.0
	 *
	 * @param int    $automation_id Automation ID.
	 * @param string $direction     'prev' (undo) or 'next' (redo).
	 *
	 * @return bool True if a restore happened.
	 */
	private function step_to_neighbor( $automation_id, $direction ) {
		$automation = AutomationModel::find( $automation_id );
		if ( ! $automation ) {
			return false;
		}

		$target = $this->neighbor_version( $automation, $direction );
		if ( ! $target ) {
			return false;
		}

		return $this->restore( $automation_id, $target->id );
	}

	/**
	 * Restore the automation to a specific version snapshot.
	 *
	 * Steps present in the snapshot are upserted/re-activated; live steps absent
	 * from the snapshot are soft-deleted. Model events are suppressed so the
	 * end_automation ordering guard and template generation do not re-fire.
	 *
	 * The rest of the CRM avoids Eloquent transactions for connector
	 * compatibility, so this follows the same convention and logs on failure.
	 *
	 * @since 1.0.0
	 *
	 * @param int $automation_id Automation ID.
	 * @param int $version_id    Target version row ID.
	 *
	 * @return bool True on success.
	 */
	public function restore( $automation_id, $version_id ) {
		$version = AutomationVersionModel::find( $version_id );
		if ( ! $version || (int) $version->automation_id !== (int) $automation_id ) {
			return false;
		}

		$automation = AutomationModel::find( $automation_id );
		if ( ! $automation ) {
			return false;
		}

		$snapshot = $version->snapshot;
		if ( ! is_array( $snapshot ) ) {
			return false;
		}

		self::$restoring = true;
		try {
			AutomationStepModel::withoutEvents(
				function () use ( $automation_id, $snapshot ) {
					$snapshot_steps = isset( $snapshot['steps'] ) && is_array( $snapshot['steps'] ) ? $snapshot['steps'] : array();
					$snapshot_ids   = array();

					foreach ( $snapshot_steps as $step_data ) {
						if ( empty( $step_data['id'] ) ) {
							continue;
						}
						$snapshot_ids[] = (int) $step_data['id'];
						$this->restore_step( $automation_id, $step_data );
					}

					// Soft-delete live steps that are not part of the snapshot.
					$query = AutomationStepModel::where( 'automation_id', $automation_id )
						->whereIn( 'status', self::$live_statuses );
					if ( ! empty( $snapshot_ids ) ) {
						$query->whereNotIn( 'id', $snapshot_ids );
					}
					$query->update( array( 'status' => 'deleted' ) );
				}
			);

			// Apply the automation-level fields from the snapshot.
			$auto_data = isset( $snapshot['automation'] ) && is_array( $snapshot['automation'] ) ? $snapshot['automation'] : array();
			if ( ! empty( $auto_data ) ) {
				$settings = isset( $auto_data['settings'] ) && is_array( $auto_data['settings'] ) ? $auto_data['settings'] : array();
				// Preserve the cursor; it is bookkeeping, not part of the snapshotted state.
				$settings[ self::CURSOR_KEY ] = $version_id;

				$automation->fill(
					array(
						'name'     => $auto_data['name'] ?? $automation->name,
						'status'   => $auto_data['status'] ?? $automation->status,
						'settings' => $settings,
					)
				);
				$automation->save();
			} else {
				$this->set_cursor( $automation, $version_id );
			}

			return true;
		} catch ( \Throwable $e ) {
			$this->log_error( 'restore', $automation_id, $e );
			return false;
		} finally {
			self::$restoring = false;
		}
	}

	/**
	 * List versions for an automation (newest first) with undo / redo state.
	 *
	 * @since 1.0.0
	 *
	 * @param int $automation_id Automation ID.
	 *
	 * @return array {
	 *     @type array $versions  List of {id, version, label, created_at, created_by, is_current}.
	 *     @type int   $cursor    Current version id (0 if none).
	 *     @type bool  $can_undo
	 *     @type bool  $can_redo
	 * }
	 */
	public function get_history( $automation_id ) {
		$automation = AutomationModel::find( $automation_id );
		$cursor     = $automation ? $this->get_cursor( $automation ) : 0;

		$versions = AutomationVersionModel::where( 'automation_id', $automation_id )
			->orderBy( 'version', 'desc' )
			->get();

		// Derive undo / redo availability from the loaded rows — the list is
		// ordered newest-first, so any row before the cursor in the list is a
		// redo target and any row after it is an undo target. Avoids two extra
		// COUNT queries.
		$cursor_version = null;
		$items          = array();
		$cursor_index   = null;
		foreach ( $versions as $index => $v ) {
			$is_current = (int) $v->id === (int) $cursor;
			if ( $is_current ) {
				$cursor_version = $v;
				$cursor_index   = $index;
			}
			$items[] = array(
				'id'         => (int) $v->id,
				'version'    => (int) $v->version,
				'label'      => $v->label,
				'created_at' => $v->created_at,
				'created_by' => $v->created_by ? (int) $v->created_by : null,
				'is_current' => $is_current,
			);
		}

		$can_undo = null !== $cursor_index && $cursor_index < count( $items ) - 1;
		$can_redo = null !== $cursor_index && $cursor_index > 0;

		return array(
			'versions' => $items,
			'cursor'   => (int) $cursor,
			'can_undo' => $can_undo,
			'can_redo' => $can_redo,
		);
	}

	/**
	 * Ensure a baseline snapshot exists so the user can undo back to the state
	 * the editor was opened in. No-op if any version already exists.
	 *
	 * @since 1.0.0
	 *
	 * @param int $automation_id Automation ID.
	 *
	 * @return void
	 */
	public function ensure_baseline( $automation_id ) {
		$has_any = AutomationVersionModel::where( 'automation_id', $automation_id )->exists();
		if ( ! $has_any ) {
			$this->snapshot( $automation_id, __( 'Initial state', 'doublescale' ) );
		}
	}

	/**
	 * Build a portable snapshot of an automation by ID.
	 *
	 * Public entry point reusing the same serializer that powers undo / redo, so
	 * the workflow export feature ships the exact shape the editor already knows
	 * how to restore. Returns the raw `{ automation, steps }` array (bookkeeping
	 * keys included — the exporter strips those).
	 *
	 * @since 1.0.0
	 *
	 * @param int $automation_id Automation ID.
	 *
	 * @return array|null The snapshot, or null if the automation does not exist.
	 */
	public function export_snapshot( $automation_id ) {
		$automation = AutomationModel::find( $automation_id );
		if ( ! $automation ) {
			return null;
		}

		return $this->build_snapshot( $automation );
	}

	/**
	 * Build the JSON snapshot payload for an automation.
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation model.
	 *
	 * @return array
	 */
	private function build_snapshot( AutomationModel $automation ) {
		$settings = is_array( $automation->settings ) ? $automation->settings : array();
		// The cursor is bookkeeping; never persist it inside a snapshot.
		unset( $settings[ self::CURSOR_KEY ] );

		$steps = AutomationStepModel::where( 'automation_id', $automation->id )
			->whereIn( 'status', self::$live_statuses )
			->orderBy( 'order', 'asc' )
			->get();

		$step_rows = array();
		foreach ( $steps as $step ) {
			$step_rows[] = array(
				'id'        => (int) $step->id,
				'parent_id' => (int) $step->parent_id,
				'action'    => $step->action,
				'type'      => $step->type,
				'condition' => $step->condition,
				'status'    => $step->status,
				'settings'  => is_array( $step->settings ) ? $step->settings : array(),
				'order'     => (int) $step->order,
			);
		}

		return array(
			'automation' => array(
				'name'     => $automation->name,
				'trigger'  => $automation->trigger,
				'status'   => $automation->status,
				'settings' => $settings,
			),
			'steps'      => $step_rows,
		);
	}

	/**
	 * Restore a single step from snapshot data. Existing rows are updated in
	 * place (keeping their IDs so parent_id links stay valid); missing rows are
	 * re-inserted with their original ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $automation_id Automation ID.
	 * @param array $step_data     Snapshot step row.
	 *
	 * @return void
	 */
	private function restore_step( $automation_id, array $step_data ) {
		$attributes = array(
			'automation_id' => $automation_id,
			'parent_id'     => (int) ( $step_data['parent_id'] ?? 0 ),
			'action'        => $step_data['action'] ?? '',
			'type'          => $step_data['type'] ?? '',
			'condition'     => $step_data['condition'] ?? '',
			'status'        => $step_data['status'] ?? 'active',
			'settings'      => isset( $step_data['settings'] ) && is_array( $step_data['settings'] ) ? $step_data['settings'] : array(),
			'order'         => (int) ( $step_data['order'] ?? 1 ),
		);

		$step = AutomationStepModel::find( (int) $step_data['id'] );
		if ( $step ) {
			$step->fill( $attributes );
			$step->save();
			return;
		}

		// Row was hard-deleted at some point — re-insert preserving the ID so
		// parent_id references from sibling steps remain valid. Disable
		// auto-increment for this instance so the explicit id is written rather
		// than a fresh one being generated.
		$step               = new AutomationStepModel();
		$step->incrementing = false;
		$step->forceFill( array_merge( array( 'id' => (int) $step_data['id'] ), $attributes ) );
		$step->save();
	}

	/**
	 * Find the version immediately before/after the current cursor.
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation model.
	 * @param string          $direction  'prev' or 'next'.
	 *
	 * @return AutomationVersionModel|null
	 */
	private function neighbor_version( AutomationModel $automation, $direction ) {
		$cursor = $this->get_cursor( $automation );
		if ( ! $cursor ) {
			return null;
		}

		$cursor_version = AutomationVersionModel::find( $cursor );
		if ( ! $cursor_version ) {
			return null;
		}

		$query = AutomationVersionModel::where( 'automation_id', $automation->id );
		if ( 'prev' === $direction ) {
			return $query->where( 'version', '<', $cursor_version->version )
				->orderBy( 'version', 'desc' )->first();
		}

		return $query->where( 'version', '>', $cursor_version->version )
			->orderBy( 'version', 'asc' )->first();
	}

	/**
	 * Delete the oldest versions beyond the retention cap.
	 *
	 * @since 1.0.0
	 *
	 * @param int $automation_id Automation ID.
	 *
	 * @return void
	 */
	private function prune( $automation_id ) {
		$ids_to_keep = AutomationVersionModel::where( 'automation_id', $automation_id )
			->orderBy( 'version', 'desc' )
			->limit( self::MAX_VERSIONS )
			->pluck( 'id' )
			->all();

		if ( count( $ids_to_keep ) < self::MAX_VERSIONS ) {
			return;
		}

		AutomationVersionModel::where( 'automation_id', $automation_id )
			->whereNotIn( 'id', $ids_to_keep )
			->delete();
	}

	/**
	 * Read the current version cursor from the automation settings.
	 *
	 * @param AutomationModel $automation Automation model.
	 *
	 * @return int Version id, or 0 if unset.
	 */
	private function get_cursor( AutomationModel $automation ) {
		$settings = is_array( $automation->settings ) ? $automation->settings : array();
		return isset( $settings[ self::CURSOR_KEY ] ) ? (int) $settings[ self::CURSOR_KEY ] : 0;
	}

	/**
	 * Persist the current version cursor into the automation settings.
	 *
	 * @param AutomationModel $automation Automation model.
	 * @param int             $version_id Version id.
	 *
	 * @return void
	 */
	private function set_cursor( AutomationModel $automation, $version_id ) {
		$settings                     = is_array( $automation->settings ) ? $automation->settings : array();
		$settings[ self::CURSOR_KEY ] = (int) $version_id;
		$automation->settings         = $settings;
		$automation->save();
	}

	/**
	 * Log a handled error without interrupting the request.
	 *
	 * @param string     $op            Operation name.
	 * @param int        $automation_id Automation ID.
	 * @param \Throwable $e             Exception.
	 *
	 * @return void
	 */
	private function log_error( $op, $automation_id, \Throwable $e ) {
		if ( function_exists( 'doublescale_get_logger' ) ) {
			doublescale_get_logger()->error(
				'Automation version ' . $op . ' failed',
				array(
					'source'        => 'automation-version-manager',
					'automation_id' => $automation_id,
					'exception'     => $e->getMessage(),
					'file'          => $e->getFile(),
					'line'          => $e->getLine(),
				)
			);
		}
	}
}
