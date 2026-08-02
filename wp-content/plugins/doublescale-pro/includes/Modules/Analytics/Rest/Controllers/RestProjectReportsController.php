<?php
/**
 * REST API: Project reports.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\Analytics\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\Analytics\Services\ProjectReportService;

/**
 * GET /doublescale/v1/reports/projects
 */
class RestProjectReportsController extends AbstractEntityReportController {

	/**
	 * @var string
	 */
	protected $rest_base = 'reports/projects';

	/**
	 * @var ProjectReportService|null
	 */
	private $service = null;

	/**
	 * @return ProjectReportService
	 */
	protected function service() {
		if ( null === $this->service ) {
			$this->service = new ProjectReportService();
		}

		return $this->service;
	}

	/**
	 * @return bool
	 */
	protected function can_view() {
		return Permissions::has_project_access();
	}

	/**
	 * @return bool
	 */
	protected function can_manage_all() {
		return Permissions::can_manage_all_projects();
	}
}
