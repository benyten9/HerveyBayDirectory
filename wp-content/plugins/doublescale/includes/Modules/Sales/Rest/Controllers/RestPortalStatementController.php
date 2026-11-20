<?php
/**
 * The client's account statement in the portal.
 *
 *   GET /doublescale/v1/portal/statement?from=Y-m-d&to=Y-m-d       (JSON, per currency)
 *   GET /doublescale/v1/portal/statement/pdf?from=Y-m-d&to=Y-m-d   (the same as a PDF)
 *
 * A money summary of documents: only for a client who sees both the
 * documents and the financial summaries ({@see PortalStatement}).
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Documents\Services\DocumentDownload;
use DoubleScale\Modules\Portal\Services\PortalAccess;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Modules\Sales\Services\PortalStatement;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalStatementController.
 */
class RestPortalStatementController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'portal/statement';

	/**
	 * @return void
	 */
	public function register_routes() {
		$args = array(
			'from' => array( 'type' => 'string' ),
			'to'   => array( 'type' => 'string' ),
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_statement' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
					'args'                => $args,
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/pdf',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pdf' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
					'args'                => $args,
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_statement( WP_REST_Request $request ) {
		$scope = $this->scope( $request );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}
		return new WP_REST_Response( PortalStatement::build( $scope['contact'], $scope['from'], $scope['to'] ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_pdf( WP_REST_Request $request ) {
		$scope = $this->scope( $request );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}
		if ( ! class_exists( 'DoubleScale\\Vendor\\Dompdf\\Dompdf' ) ) {
			return new WP_Error( 'pdf_unavailable', __( 'PDF export is not available.', 'doublescale' ), array( 'status' => 503 ) );
		}
		$statement = PortalStatement::build( $scope['contact'], $scope['from'], $scope['to'] );

		$dompdf_class  = 'DoubleScale\\Vendor\\Dompdf\\Dompdf';
		$options_class = 'DoubleScale\\Vendor\\Dompdf\\Options';
		$options       = new $options_class();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		try {
			$dompdf = new $dompdf_class( $options );
			$dompdf->loadHtml( self::html( $scope['contact'], $statement ) );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->render();
			$bytes = (string) $dompdf->output();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'pdf_generation_failed', __( 'The statement could not be made. Please try again.', 'doublescale' ), array( 'status' => 500 ) );
		}

		return DocumentDownload::stream( $bytes, 'statement-' . $scope['from'] . '-' . $scope['to'], 'pdf', 'application/pdf' );
	}

	/**
	 * The client, the period, or why not.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{contact: ContactModel, from: string, to: string}|WP_Error
	 */
	private function scope( WP_REST_Request $request ) {
		$contact = PortalIdentity::current_contact();
		if ( ! $contact
			|| ! function_exists( 'doublescale_sales_documents_ready' ) || ! doublescale_sales_documents_ready()
			// A statement lists invoices and payments: Documents must be on.
			|| ! doublescale_module_data_ready( 'documents' )
			|| ! PortalAccess::allows( 'documents', $contact )
			|| ! PortalAccess::allows( 'financial_summaries', $contact ) ) {
			return new WP_Error( 'portal_feature_unavailable', __( 'This page is not available.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$today = current_time( 'Y-m-d' );
		$from  = (string) ( $request->get_param( 'from' ) ?: substr( $today, 0, 4 ) . '-01-01' );
		$to    = (string) ( $request->get_param( 'to' ) ?: $today );
		if ( ! self::is_day( $from ) || ! self::is_day( $to ) || $from > $to ) {
			return new WP_Error( 'rest_invalid_param', __( 'Choose a start date before the end date.', 'doublescale' ), array( 'status' => 400 ) );
		}

		return array(
			'contact' => $contact,
			'from'    => $from,
			'to'      => $to,
		);
	}

	/**
	 * @param string $value Day.
	 * @return bool
	 */
	private static function is_day( string $value ): bool {
		$date = \DateTime::createFromFormat( '!Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * The statement as printable HTML.
	 *
	 * @param ContactModel         $contact   Client.
	 * @param array<string, mixed> $statement Statement.
	 * @return string
	 */
	private static function html( ContactModel $contact, array $statement ): string {
		$name   = trim( (string) $contact->first_name . ' ' . (string) $contact->last_name );
		$money  = static fn( $amount, $currency ) => esc_html( number_format_i18n( (float) $amount, 2 ) . ' ' . $currency );
		$labels = array(
			'invoice'     => __( 'Invoice', 'doublescale' ),
			'payment'     => __( 'Payment', 'doublescale' ),
			'credit_note' => __( 'Credit note', 'doublescale' ),
		);

		$html  = '<html><head><meta charset="utf-8"><style>body{font-family:"DejaVu Sans",sans-serif;font-size:11px;color:#29292e}h1{font-size:18px;margin:0 0 4px}table{width:100%;border-collapse:collapse;margin:12px 0 24px}th,td{padding:6px 8px;border-bottom:1px solid #e4e4e7;text-align:left}th{background:#f7f8fa}td.n,th.n{text-align:right}tr.total td{font-weight:bold}</style></head><body>';
		$html .= '<h1>' . esc_html__( 'Account statement', 'doublescale' ) . '</h1>';
		$html .= '<p>' . esc_html( get_bloginfo( 'name' ) ) . '<br>' . esc_html( '' !== $name ? $name : (string) $contact->email ) . '<br>'
			/* translators: 1: first day, 2: last day */
			. esc_html( sprintf( __( 'From %1$s to %2$s', 'doublescale' ), date_i18n( get_option( 'date_format' ), strtotime( $statement['from'] ) ), date_i18n( get_option( 'date_format' ), strtotime( $statement['to'] ) ) ) ) . '</p>';

		if ( empty( $statement['currencies'] ) ) {
			return $html . '<p>' . esc_html__( 'Nothing in this period.', 'doublescale' ) . '</p></body></html>';
		}
		foreach ( $statement['currencies'] as $cur ) {
			$c     = (string) $cur['currency'];
			$html .= '<table><thead><tr><th>' . esc_html__( 'Date', 'doublescale' ) . '</th><th>' . esc_html__( 'Document', 'doublescale' ) . '</th><th class="n">' . esc_html__( 'Charges', 'doublescale' ) . '</th><th class="n">' . esc_html__( 'Payments', 'doublescale' ) . '</th><th class="n">' . esc_html__( 'Balance', 'doublescale' ) . '</th></tr></thead><tbody>';
			$html .= '<tr><td colspan="4">' . esc_html__( 'Balance brought forward', 'doublescale' ) . '</td><td class="n">' . $money( $cur['opening'], $c ) . '</td></tr>';
			foreach ( $cur['rows'] as $row ) {
				$html .= '<tr><td>' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $row['date'] ) ) ) . '</td><td>' . esc_html( ( $labels[ $row['type'] ] ?? (string) $row['type'] ) . ' ' . (string) $row['number'] ) . '</td>'
					. '<td class="n">' . ( (float) $row['charge'] > 0 ? $money( $row['charge'], $c ) : '' ) . '</td>'
					. '<td class="n">' . ( (float) $row['credit'] > 0 ? $money( $row['credit'], $c ) : '' ) . '</td>'
					. '<td class="n">' . $money( $row['balance'], $c ) . '</td></tr>';
			}
			$html .= '<tr class="total"><td colspan="2">' . esc_html__( 'Balance due', 'doublescale' ) . '</td><td class="n">' . $money( $cur['charges'], $c ) . '</td><td class="n">' . $money( $cur['credits'], $c ) . '</td><td class="n">' . $money( $cur['closing'], $c ) . '</td></tr>';
			$html .= '</tbody></table>';
		}
		return $html . '</body></html>';
	}
}
