<?php
/**
 * Projects ⇄ Client Portal bridge.
 *
 * Contributes the Projects section, a "projects" summary card, and
 * project-lifecycle timeline items to the portal. A client sees only the
 * projects whose `contact_id` matches their resolved portal contact.
 *
 * Resolved in {@see \DoubleScale\Pro\Modules\Projects\Module::boot()} so its
 * filters are only registered while the Projects module is enabled.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

/**
 * ProjectPortalProvider.
 */
final class ProjectPortalProvider {

	public function __construct() {
		add_filter( 'doublescale_portal_sections', array( $this, 'register_section' ) );
		add_filter( 'doublescale_portal_summary_cards', array( $this, 'add_summary_card' ), 10, 2 );
		add_filter( 'doublescale_portal_timeline_items', array( $this, 'add_timeline_items' ), 10, 2 );
	}

	/**
	 * Contribute the Projects section descriptor.
	 *
	 * @param array<int, array<string, mixed>> $sections Section descriptors.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_section( array $sections ): array {
		$sections[] = array(
			'slug'         => 'projects',
			'label'        => __( 'Projects', 'doublescale' ),
			'icon'         => 'folder',
			'order'        => 25,
			'is_available' => static fn() => function_exists( 'doublescale_is_module_active' )
				&& doublescale_is_module_active( 'projects' ),
			'badge'        => static fn( $contact ) => self::count_active( $contact ),
		);

		return $sections;
	}

	/**
	 * Add the "active projects" summary card.
	 *
	 * @param array<int, array<string, mixed>> $cards   Summary cards.
	 * @param ContactModel|null                $contact Resolved contact.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_summary_card( array $cards, $contact ): array {
		$cards[] = array(
			'key'   => 'active_projects',
			'label' => __( 'Active projects', 'doublescale' ),
			'value' => self::count_active( $contact ),
			'route' => 'projects',
		);

		return $cards;
	}

	/**
	 * Project the contact's project lifecycle rows into the timeline.
	 *
	 * @param array<int, array<string, mixed>> $items   Timeline items.
	 * @param ContactModel|null                $contact Resolved contact.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_timeline_items( array $items, $contact ): array {
		if ( ! $contact instanceof ContactModel ) {
			return $items;
		}

		$projects = ProjectModel::with( array( 'status' ) )
			->where( 'contact_id', (int) $contact->id )
			->orderBy( 'id', 'desc' )
			->limit( 50 )
			->get();

		foreach ( $projects as $project ) {
			$items[] = array(
				'id'         => 'project-' . (int) $project->id,
				'kind'       => 'project',
				'type'       => 'project_created',
				'date'       => (string) $project->created_at,
				'title'      => (string) $project->title,
				'status'     => $project->status ? (string) $project->status->name : '',
				'project_id' => (int) $project->id,
			);
		}

		return $items;
	}

	/**
	 * Count a contact's active (not-completed) projects.
	 *
	 * @param ContactModel|null $contact Resolved contact.
	 * @return int
	 */
	private static function count_active( $contact ): int {
		if ( ! $contact instanceof ContactModel ) {
			return 0;
		}

		if (
			function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'projects', ProjectModel::class )
		) {
			return 0;
		}

		try {
			return (int) ProjectModel::where( 'contact_id', (int) $contact->id )
				->whereHas(
					'status',
					static function ( $query ) {
						$query->where( 'is_completed', 0 );
					}
				)
				->count();
		} catch ( \Throwable $e ) {
			return 0;
		}
	}
}
