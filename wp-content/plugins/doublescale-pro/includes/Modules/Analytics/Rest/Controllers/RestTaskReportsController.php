<?php
/**
 * REST API: Task reports.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\Analytics\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\Analytics\Services\TaskReportService;

/**
 * GET /doublescale/v1/reports/tasks
 */
class RestTaskReportsController extends AbstractEntityReportController {

	/**
	 * @var string
	 */
	protected $rest_base = 'reports/tasks';

	/**
	 * @var TaskReportService|null
	 */
	private $service = null;

	/**
	 * @return TaskReportService
	 */
	protected function service() {
		if ( null === $this->service ) {
			$this->service = new TaskReportService();
		}

		return $this->service;
	}

	/**
	 * @return bool
	 */
	protected function can_view() {
		return Permissions::can_access_tasks_api();
	}

	/**
	 * @return bool
	 */
	protected function can_manage_all() {
		return Permissions::can_manage_all_projects();
	}
}
