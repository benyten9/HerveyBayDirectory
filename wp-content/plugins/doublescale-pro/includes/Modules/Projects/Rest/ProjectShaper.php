<?php
/**
 * Shape project models for REST responses.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Services\ProjectManager;
use DoubleScale\Pro\Modules\Projects\Services\ProjectUrl;

/**
 * ProjectShaper class.
 */
class ProjectShaper {

	/**
	 * Customer-safe project payload for hash-based public access.
	 *
	 * @param ProjectModel $project Project.
	 * @return array<string, mixed>
	 */
	public static function shape_public( ProjectModel $project ): array {
		$financials = ProjectManager::instance()->get_financials( (int) $project->id );

		return array(
			'title'       => (string) $project->title,
			'description' => (string) ( $project->description ?? '' ),
			'status'      => $project->status
				? array(
					'name'         => (string) $project->status->name,
					'is_completed' => (bool) $project->status->is_completed,
				)
				: null,
			'budget'      => null !== $project->budget ? (float) $project->budget : null,
			'currency'    => (string) $project->currency,
			'start_date'  => $project->start_date ? (string) $project->start_date : null,
			'due_date'    => $project->due_date ? (string) $project->due_date : null,
			'progress'      => $project->resolveProgress(),
			'task_progress' => $project->task_progress,
			'financials'  => array(
				'total' => (float) ( $financials['total'] ?? 0 ),
				'paid'  => (float) ( $financials['paid'] ?? 0 ),
				'due'   => (float) ( $financials['due'] ?? 0 ),
			),
		);
	}

	/**
	 * @param ProjectModel $project Project.
	 * @return array<string, mixed>
	 */
	public static function public_url_fields( ProjectModel $project ): array {
		return array(
			'hash'       => (string) $project->hash,
			'public_url' => ProjectUrl::get_public_url( $project ),
		);
	}
}
