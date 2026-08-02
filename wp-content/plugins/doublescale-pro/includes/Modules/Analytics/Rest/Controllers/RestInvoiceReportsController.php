<?php
/**
 * REST API: Invoice reports.
 *
 * Complements the existing /sales/analytics/revenue endpoint with the shared
 * KPI + trend + breakdown shape used by every entity report.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\Analytics\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Pro\Modules\Analytics\Services\InvoiceReportService;

/**
 * GET /doublescale/v1/reports/invoices
 */
class RestInvoiceReportsController extends AbstractEntityReportController {

	/**
	 * @var string
	 */
	protected $rest_base = 'reports/invoices';

	/**
	 * @var InvoiceReportService|null
	 */
	private $service = null;

	/**
	 * @return InvoiceReportService
	 */
	protected function service() {
		if ( null === $this->service ) {
			$this->service = new InvoiceReportService();
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
