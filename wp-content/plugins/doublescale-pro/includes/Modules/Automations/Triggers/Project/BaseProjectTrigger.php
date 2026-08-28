<?php
/**
 * Shared base for project automation triggers.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Project;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Pro\Modules\Automations\Support\ProjectContactResolver;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;

defined( 'ABSPATH' ) || exit;

/**
 * BaseProjectTrigger
 */
abstract class BaseProjectTrigger extends Trigger {

	/**
	 * When true, enroll() is a no-op — used to prevent create_project from
	 * re-entering automations mid-action.
	 *
	 * @var bool
	 */
	private static $suppress_enrollment = false;

	/**
	 * In-request dedupe for status-changed hooks (two emitters).
	 *
	 * @var array<string,bool>
	 */
	private static $status_change_seen = array();

	/**
	 * Source.
	 *
	 * @var string
	 */
	public $source = 'projects';

	/**
	 * Group.
	 *
	 * @var string
	 */
	public $group = 'project';

	/**
	 * Suppress project trigger enrollment (e.g. while CreateProject runs).
	 */
	public static function suppress_enrollment( bool $suppress ): void {
		self::$suppress_enrollment = $suppress;
	}

	/**
	 * Whether enrollment is currently suppressed.
	 */
	public static function is_enrollment_suppressed(): bool {
		return self::$suppress_enrollment;
	}

	/**
	 * Claim a status-change event for in-request dedupe.
	 *
	 * @param int $project_id Project ID.
	 * @param mixed $old_status_id Old status.
	 * @param mixed $new_status_id New status.
	 * @return bool True if this is the first claim (should process).
	 */
	/**
	 * Forget every status-change claim.
	 *
	 * The dedupe below is scoped to a single request, which in production means
	 * a single page load. Under PHPUnit the "request" is the whole suite, so a
	 * key claimed by one test silently blocks every later test that reuses the
	 * same project/status combination. Tests call this to restore the
	 * one-request assumption.
	 *
	 * @return void
	 */
	public static function reset_status_change_claims(): void {
		self::$status_change_seen = array();
	}

	protected static function claim_status_change( int $project_id, $old_status_id, $new_status_id ): bool {
		$key = $project_id . ':' . (string) $old_status_id . ':' . (string) $new_status_id;
		if ( isset( self::$status_change_seen[ $key ] ) ) {
			return false;
		}
		self::$status_change_seen[ $key ] = true;
		return true;
	}

	/**
	 * Resolve contact and enroll into matching automations.
	 *
	 * @param ProjectModel $project Project.
	 * @param array        $extra   Extra data merged into enrollment `data`.
	 */
	protected function enroll( ProjectModel $project, array $extra = array() ): void {
		if ( self::$suppress_enrollment ) {
			return;
		}

		$contact = ProjectContactResolver::resolve( $project );
		if ( ! $contact ) {
			return;
		}

		$this->process(
			array(
				'contact' => $contact,
				'project' => $project,
				'data'    => array_merge(
					array(
						'project_id' => (int) $project->id,
						'deal_id'    => $project->deal_id ? (int) $project->deal_id : null,
						'contact_id' => $project->contact_id ? (int) $project->contact_id : null,
					),
					$extra
				),
			)
		);
	}

	/**
	 * Translate helper.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected function t( $text ) {
		if ( function_exists( '\\__' ) ) {
			return call_user_func( '\\__', $text, 'doublescale' );
		}
		return $text;
	}

	/**
	 * Project status select options (any + statuses).
	 *
	 * @return array
	 */
	protected function get_status_options(): array {
		$options = array(
			'any-status' => $this->t( 'Any status' ),
		);

		if ( ! AutomationModuleStorage::is_ready( 'projects', ProjectModel::class ) ) {
			return $options;
		}

		if ( ! class_exists( ProjectStatusModel::class ) ) {
			return $options;
		}

		$statuses = ProjectStatusModel::orderBy( 'position', 'asc' )->get();
		foreach ( $statuses as $status ) {
			$options[ $status->id ] = $status->name;
		}

		return $options;
	}

	/**
	 * User select options (any + users).
	 *
	 * @return array
	 */
	protected function get_owner_options(): array {
		$options = array(
			'any-user' => $this->t( 'Any user' ),
		);

		if ( ! function_exists( 'get_users' ) ) {
			return $options;
		}

		$users = get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name' ),
			)
		);

		foreach ( $users as $user ) {
			$options[ $user->ID ] = $user->display_name;
		}

		return $options;
	}

	/**
	 * Whether a setting matches "any" or the concrete value.
	 *
	 * @param mixed  $setting      Automation setting.
	 * @param mixed  $actual       Actual value.
	 * @param string $any_sentinel Sentinel for "any".
	 */
	protected function matches_any_or_value( $setting, $actual, string $any_sentinel ): bool {
		if ( null === $setting || '' === $setting || $any_sentinel === $setting ) {
			return true;
		}
		return (string) $setting === (string) $actual;
	}
}