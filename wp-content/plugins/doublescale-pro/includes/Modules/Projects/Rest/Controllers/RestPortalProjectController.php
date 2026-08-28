<?php
/**
 * Client Portal project endpoints.
 *
 *   GET /doublescale/v1/portal/projects        (the contact's projects)
 *   GET /doublescale/v1/portal/projects/{id}   (one project, ownership-gated)
 *
 * Both routes reuse {@see PortalIdentity} for the login + lowercased-email
 * contact resolve, and gate ownership on `contact_id` returning 404 (not 403)
 * on a mismatch so project ids can't be enumerated. Payloads are shaped to the
 * data a customer may see — title, status, dates, budget, and linked invoice
 * totals — never owner/internal fields or activity logs.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Services\ProjectManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalProjectController.
 */
class RestPortalProjectController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'portal';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/projects',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_projects' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/projects/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_project' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);
	}

	/**
	 * List the contact's projects.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_projects( WP_REST_Request $request ) {
		$disabled = $this->require_module( 'projects' );
		if ( $disabled ) {
			return $disabled;
		}

		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return new WP_REST_Response( array( 'data' => array() ), 200 );
		}

		$projects = ProjectModel::with( array( 'status' ) )
			->where( 'contact_id', (int) $contact->id )
			->orderBy( 'id', 'desc' )
			->limit( 100 )
			->get();

		$data = array();
		foreach ( $projects as $project ) {
			$data[] = $this->shape_project( $project );
		}

		return new WP_REST_Response( array( 'data' => $data ), 200 );
	}

	/**
	 * One project detail (ownership-gated), including invoice/proposal totals.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_project( WP_REST_Request $request ) {
		$disabled = $this->require_module( 'projects' );
		if ( $disabled ) {
			return $disabled;
		}

		$project = $this->resolve_own_project( $request );
		if ( $project instanceof WP_Error ) {
			return $project;
		}

		$data              = $this->shape_project( $project );
		$data['financials'] = $this->shape_financials( (int) $project->id );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Resolve a project by id and assert it belongs to the current contact.
	 * Returns a 404 (not 403) on a mismatch so ids can't be enumerated.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return ProjectModel|WP_Error
	 */
	private function resolve_own_project( WP_REST_Request $request ) {
		$contact = PortalIdentity::current_contact();
		$not_found = new WP_Error(
			'project_not_found',
			__( 'Project not found.', 'doublescale' ),
			array( 'status' => 404 )
		);

		if ( ! $contact ) {
			return $not_found;
		}

		$project = ProjectModel::with( array( 'status' ) )->find( (int) $request->get_param( 'id' ) );
		if ( ! $project || (int) $project->contact_id !== (int) $contact->id ) {
			return $not_found;
		}

		return $project;
	}

	/**
	 * Shape a project row for the client (no owner/internal fields).
	 *
	 * @param ProjectModel $project Project.
	 * @return array<string, mixed>
	 */
	private function shape_project( ProjectModel $project ): array {
		return array(
			'id'          => (int) $project->id,
			'title'       => (string) $project->title,
			'description' => (string) $project->description,
			'status'      => $project->status
				? array(
					'id'           => (int) $project->status->id,
					'name'         => (string) $project->status->name,
					'color'        => (string) ( $project->status->color ?? '#8775EC' ),
					'bg_color'     => (string) ( $project->status->bg_color ?? '#F4F2FE' ),
					'is_completed' => (bool) $project->status->is_completed,
					'position'     => (int) ( $project->status->position ?? 0 ),
				)
				: null,
			'budget'      => null !== $project->budget ? (float) $project->budget : null,
			'currency'    => (string) $project->currency,
			'start_date'  => $project->start_date ? (string) $project->start_date : null,
			'due_date'    => $project->due_date ? (string) $project->due_date : null,
			'created_at'  => (string) $project->created_at,
		);
	}

	/**
	 * Shape the client-safe financial summary for a project.
	 *
	 * @param int $project_id Project ID.
	 * @return array<string, float>
	 */
	private function shape_financials( int $project_id ): array {
		$financials = ProjectManager::instance()->get_financials( $project_id );

		return array(
			'total' => (float) ( $financials['total'] ?? 0 ),
			'paid'  => (float) ( $financials['paid'] ?? 0 ),
			'due'   => (float) ( $financials['due'] ?? 0 ),
		);
	}
}
