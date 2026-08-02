<?php
/**
 * REST API: Proposal reports.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\Analytics\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Pro\Modules\Analytics\Services\ProposalReportService;

/**
 * GET /doublescale/v1/reports/proposals
 */
class RestProposalReportsController extends AbstractEntityReportController {

	/**
	 * @var string
	 */
	protected $rest_base = 'reports/proposals';

	/**
	 * @var ProposalReportService|null
	 */
	private $service = null;

	/**
	 * @return ProposalReportService
	 */
	protected function service() {
		if ( null === $this->service ) {
			$this->service = new ProposalReportService();
		}

		return $this->service;
	}

	/**
	 * @return bool
	 */
	protected function can_view() {
		return Capabilities::can_manage_all_sales() || Capabilities::can_view_sales();
	}

	/**
	 * @return bool
	 */
	protected function can_manage_all() {
		return Capabilities::can_manage_all_sales();
	}
}
