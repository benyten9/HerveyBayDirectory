<?php
/**
 * Project management service.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\Services;

use Exception;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Pro\Modules\Deals\Models\PipelineStageModel;
use DoubleScale\Pro\Modules\Deals\Services\DealManager;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Documents\Rest\ProposalShaper;
use DoubleScale\Pro\Core\UserRoles\PermissionsCompat;
use DoubleScale\Pro\Modules\Projects\Capabilities;

final class ProjectManager {

	/**
	 * @var ProjectManager|null
	 */
	private static $instance;

	/**
	 * @return ProjectManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'doublescale_ready', array( $this, 'init' ) );
	}

	/**
	 * @return void
	 */
	public function init() {
		add_action( 'wp_loaded', array( $this, 'seed_default_statuses' ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_default_statuses() {
		return array(
			array(
				'name'         => 'New',
				'color'        => '#8775EC',
				'bg_color'     => '#F4F2FE',
				'is_completed' => 0,
				'is_protected' => 1,
			),
			array(
				'name'         => 'In Progress',
				'color'        => '#33C3E2',
				'bg_color'     => '#ECF9FC',
				'is_completed' => 0,
				'is_protected' => 0,
			),
			array(
				'name'         => 'Done',
				'color'        => '#4BB99E',
				'bg_color'     => '#E0F0EC',
				'is_completed' => 0,
				'is_protected' => 0,
			),
			array(
				'name'         => 'Completed',
				'color'        => '#0BA24B',
				'bg_color'     => '#DDFFDE',
				'is_completed' => 1,
				'is_protected' => 1,
			),
		);
	}

	/**
	 * @return void
	 */
	public function seed_default_statuses() {
		if ( ProjectStatusModel::count() > 0 ) {
			return;
		}
		$position = 0;
		foreach ( $this->get_default_statuses() as $status ) {
			ProjectStatusModel::create(
				array_merge(
					$status,
					array( 'position' => $position++ )
				)
			);
		}
	}

	/**
	 * Guarantee exactly one protected Open and one protected Closed status.
	 *
	 * @return void
	 */
	public function ensure_protected_statuses() {
		$this->seed_default_statuses();

		$protected_open = ProjectStatusModel::where( 'is_completed', 0 )
			->where( 'is_protected', 1 )
			->orderBy( 'position', 'asc' )
			->orderBy( 'id', 'asc' )
			->first();

		if ( ! $protected_open ) {
			$first_open = ProjectStatusModel::where( 'is_completed', 0 )
				->orderBy( 'position', 'asc' )
				->orderBy( 'id', 'asc' )
				->first();

			if ( $first_open ) {
				$first_open->is_protected = 1;
				$first_open->save();
			} else {
				ProjectStatusModel::create(
					array(
						'name'         => 'New',
						'color'        => '#8775EC',
						'bg_color'     => '#F4F2FE',
						'is_completed' => 0,
						'is_protected' => 1,
						'position'     => 0,
					)
				);
			}
		}

		$protected_closed = ProjectStatusModel::where( 'is_completed', 1 )
			->where( 'is_protected', 1 )
			->orderBy( 'position', 'asc' )
			->orderBy( 'id', 'asc' )
			->first();

		if ( ! $protected_closed ) {
			$first_closed = ProjectStatusModel::where( 'is_completed', 1 )
				->orderBy( 'position', 'asc' )
				->orderBy( 'id', 'asc' )
				->first();

			if ( $first_closed ) {
				$first_closed->is_protected = 1;
				$first_closed->save();
			} else {
				$max_position = (int) ProjectStatusModel::max( 'position' );
				ProjectStatusModel::create(
					array(
						'name'         => 'Completed',
						'color'        => '#0BA24B',
						'bg_color'     => '#DDFFDE',
						'is_completed' => 1,
						'is_protected' => 1,
						'position'     => $max_position + 1,
					)
				);
			}
		}
	}

	/**
	 * @param array $data Project data.
	 * @return ProjectModel|null
	 */
	public function create_project( array $data ) {
		try {
			if ( empty( $data['title'] ) || empty( $data['status_id'] ) ) {
				throw new Exception( 'Missing required project data' );
			}

			$data = $this->resolve_contact_from_deal( $data );

			if ( ! empty( $data['contact_id'] ) && ! ContactModel::find( $data['contact_id'] ) ) {
				throw new Exception( 'Contact not found' );
			}

			if ( ! empty( $data['deal_id'] ) && class_exists( DealModel::class ) && ! DealModel::find( $data['deal_id'] ) ) {
				throw new Exception( 'Deal not found' );
			}

			if ( ! ProjectStatusModel::find( $data['status_id'] ) ) {
				throw new Exception( 'Invalid status' );
			}

			$current_user_id = get_current_user_id();
			$project_data    = array_merge(
				array(
					'owner_id' => $current_user_id > 0 ? $current_user_id : null,
					'position' => $this->next_position_for_status( (int) $data['status_id'] ),
				),
				$data
			);

			return ProjectModel::create( $project_data );
		} catch ( Exception $e ) {
			error_log( 'DoubleScale Project Manager Error: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * @param int   $project_id Project ID.
	 * @param array $data       Updated data.
	 * @return ProjectModel|null
	 */
	public function update_project( $project_id, array $data ) {
		$project = ProjectModel::find( $project_id );
		if ( ! $project ) {
			return null;
		}

		if ( ! Capabilities::can_manage_project( (int) $project->id ) ) {
			return null;
		}

		$data = $this->resolve_contact_from_deal( $data );

		if ( ! PermissionsCompat::can_assign_project_owner() ) {
			unset( $data['owner_id'] );
		}

		$project->fill( $data );
		$project->save();
		return $project;
	}

	/**
	 * @param int $status_id Status ID.
	 * @return int
	 */
	public function next_position_for_status( $status_id ) {
		$max = ProjectModel::where( 'status_id', $status_id )->max( 'position' );
		return null === $max ? 0 : ( (int) $max + 1 );
	}

	/**
	 * @param \Illuminate\Database\Eloquent\Builder $query  Query builder.
	 * @param array                               $filters Query filters.
	 * @return void
	 */
	private function apply_filters_to_query( $query, array $filters ) {
		if ( ! user_can( get_current_user_id(), 'doublescale_project_read_all_projects' ) ) {
			$query->where( 'owner_id', get_current_user_id() );
		}

		if ( ! empty( $filters['status_id'] ) ) {
			$query->where( 'status_id', (int) $filters['status_id'] );
		}
		if ( ! empty( $filters['contact_id'] ) ) {
			$query->where( 'contact_id', (int) $filters['contact_id'] );
		}
		if ( ! empty( $filters['deal_id'] ) ) {
			$query->where( 'deal_id', (int) $filters['deal_id'] );
		}
		if ( ! empty( $filters['owner_id'] ) ) {
			$query->where( 'owner_id', (int) $filters['owner_id'] );
		}
		if ( ! empty( $filters['search'] ) ) {
			$like = '%' . str_replace( array( '%', '_' ), array( '\\%', '\\_' ), $filters['search'] ) . '%';
			$query->where( 'title', 'LIKE', $like );
		}
	}

	/**
	 * @param array $filters Query filters.
	 * @return int
	 */
	public function count_projects_with_filters( array $filters = array() ) {
		$query = ProjectModel::query();
		$this->apply_filters_to_query( $query, $filters );
		return (int) $query->count();
	}

	/**
	 * @param array $filters Query filters.
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	public function get_projects_with_filters( array $filters = array() ) {
		$query = ProjectModel::query()->with( array( 'status', 'contact', 'owner', 'deal' ) );
		$this->apply_filters_to_query( $query, $filters );

		$allowed_sort_columns = array( 'position', 'title', 'budget', 'start_date', 'due_date', 'created_at', 'updated_at', 'status_id' );
		$sort_by              = in_array( $filters['sort_by'] ?? '', $allowed_sort_columns, true )
			? $filters['sort_by']
			: 'position';
		$sort_order           = ( ! empty( $filters['sort_order'] ) && 'desc' === $filters['sort_order'] ) ? 'desc' : 'asc';
		$query->orderBy( $sort_by, $sort_order );

		if ( ! empty( $filters['page'] ) && ! empty( $filters['per_page'] ) ) {
			$page     = max( 1, (int) $filters['page'] );
			$per_page = max( 1, min( 100, (int) $filters['per_page'] ) );
			$offset   = ( $page - 1 ) * $per_page;
			$query->skip( $offset )->take( $per_page );
		}

		return $query->get();
	}

	/**
	 * @param array $filters Query filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_board_data( array $filters = array() ) {
		$this->ensure_protected_statuses();
		$statuses = ProjectStatusModel::orderBy( 'position' )->get();
		$projects = $this->get_projects_with_filters( $filters );

		$grouped = array();
		foreach ( $statuses as $status ) {
			$grouped[ $status->id ] = array(
				'status'   => $status->toArray(),
				'projects' => array(),
			);
		}

		foreach ( $projects as $project ) {
			$status_id = (int) $project->status_id;
			if ( ! isset( $grouped[ $status_id ] ) ) {
				$grouped[ $status_id ] = array(
					'status'   => $project->status ? $project->status->toArray() : array( 'id' => $status_id ),
					'projects' => array(),
				);
			}
			$grouped[ $status_id ]['projects'][] = $this->shape_project_for_board( $project );
		}

		foreach ( $grouped as &$column ) {
			usort(
				$column['projects'],
				static function ( $a, $b ) {
					return (int) ( $a['position'] ?? 0 ) <=> (int) ( $b['position'] ?? 0 );
				}
			);
		}
		unset( $column );

		return array_values( $grouped );
	}

	/**
	 * @param ProjectModel $project Project.
	 * @return array<string, mixed>
	 */
	private function shape_project_for_board( ProjectModel $project ) {
		return $project->toArray();
	}

	/**
	 * @param array<int> $project_ids Ordered project IDs.
	 * @param int        $status_id   Target status ID.
	 * @param int|null   $user_id     Current user ID.
	 * @return bool
	 */
	public function reorder_projects( array $project_ids, $status_id, $user_id = null ) {
		if ( ! ProjectStatusModel::find( $status_id ) ) {
			return false;
		}

		$projects_by_id = ProjectModel::whereIn( 'id', array_map( 'intval', $project_ids ) )
			->get()
			->keyBy( 'id' );

		$position = 0;
		foreach ( $project_ids as $project_id ) {
			$project = $projects_by_id->get( (int) $project_id );
			if ( ! $project ) {
				continue;
			}

			if ( ! Capabilities::can_manage_project( (int) $project->id ) ) {
				continue;
			}

			$old_status_id = (int) $project->status_id;
			$new_position  = $position++;
			$project->position = $new_position;

			if ( $old_status_id !== (int) $status_id ) {
				$project->status_id = (int) $status_id;
				$project->save();
				$this->log_project_status_change( $project, $old_status_id, (int) $status_id, $user_id );
			} else {
				$project->save();
			}
		}

		return true;
	}

	/**
	 * @param ProjectModel $project       Project.
	 * @param int          $old_status_id Previous status ID.
	 * @param int          $new_status_id New status ID.
	 * @param int|null     $user_id       Acting user ID.
	 * @return void
	 */
	private function log_project_status_change( $project, $old_status_id, $new_status_id, $user_id = null ) {
		$activity = ActivityModel::create(
			array(
				'contact_id'    => $project->contact_id,
				'activity_type' => ActivityTypes::PROJECT_STATUS_CHANGED,
				'data'          => array(
					'old_status_id' => $old_status_id,
					'new_status_id' => $new_status_id,
				),
				'user_id'       => $user_id,
			)
		);

		if ( $activity && class_exists( ActivityAssociationModel::class ) ) {
			ActivityAssociationModel::create(
				array(
					'activity_id' => $activity->id,
					'entity_type' => ActivityAssociationModel::ENTITY_TYPE_PROJECT,
					'entity_id'   => $project->id,
				)
			);
		}

		do_action( 'doublescale_project_status_changed', $project, $old_status_id, $new_status_id );
	}

	/**
	 * @param int $deal_id Deal ID.
	 * @return ProjectModel|null
	 */
	public function convert_from_deal( $deal_id ) {
		$deal = DealModel::find( $deal_id );
		if ( ! $deal ) {
			return null;
		}

		if ( 'lost' === (string) $deal->status ) {
			return null;
		}

		$existing = ProjectModel::where( 'deal_id', $deal_id )->first();
		if ( $existing ) {
			return $existing;
		}

		if ( ! $this->ensure_deal_won_for_conversion( $deal ) ) {
			return null;
		}

		$deal = DealModel::find( $deal_id );
		if ( ! $deal ) {
			return null;
		}

		$default_status = $this->get_default_open_status();
		if ( ! $default_status ) {
			return null;
		}

		$project = $this->create_project(
			array(
				'title'      => $deal->title,
				'contact_id' => $deal->contact_id,
				'deal_id'    => $deal->id,
				'budget'     => $deal->value,
				'status_id'  => $default_status->id,
				'owner_id'   => $deal->owner_id,
			)
		);

		if ( ! $project ) {
			return null;
		}

		// Link the boot-created project activity to the source deal (avoid a duplicate row).
		if ( class_exists( ActivityAssociationModel::class ) ) {
			$project_association = ActivityAssociationModel::where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_PROJECT )
				->where( 'entity_id', $project->id )
				->orderBy( 'id', 'desc' )
				->first();

			if ( $project_association ) {
				ActivityAssociationModel::create(
					array(
						'activity_id' => $project_association->activity_id,
						'entity_type' => ActivityAssociationModel::ENTITY_TYPE_DEAL,
						'entity_id'   => $deal->id,
					)
				);

				$activity = ActivityModel::find( $project_association->activity_id );
				if ( $activity ) {
					$data            = is_array( $activity->data ) ? $activity->data : array();
					$data['deal_id'] = (int) $deal->id;
					$data['source']  = 'deal_conversion';
					$activity->data  = $data;
					$activity->save();
				}
			}
		}

		do_action( 'doublescale_project_converted_from_deal', $project, $deal );
		return $project;
	}

	/**
	 * Mark an open deal as won before project conversion (won stage when available).
	 *
	 * @param DealModel $deal Source deal.
	 * @return bool
	 */
	private function ensure_deal_won_for_conversion( $deal ) {
		if ( ! $deal instanceof DealModel ) {
			return false;
		}

		if ( 'lost' === (string) $deal->status ) {
			return false;
		}

		if ( 'won' === (string) $deal->status ) {
			return true;
		}

		$deal_manager = DealManager::instance();
		$won_stage    = PipelineStageModel::where( 'pipeline_id', $deal->pipeline_id )
			->where( 'win_probability', 100 )
			->orderBy( 'sort_order' )
			->first();

		if ( $won_stage ) {
			return $deal_manager->move_deal_to_stage(
				(int) $deal->id,
				(int) $won_stage->id,
				get_current_user_id(),
				true
			);
		}

		$updated = $deal_manager->update_deal(
			(int) $deal->id,
			array( 'status' => 'won' )
		);

		return $updated instanceof DealModel && 'won' === (string) $updated->status;
	}

	/**
	 * First non-completed project status (protected "New" when available).
	 *
	 * @return ProjectStatusModel|null
	 */
	public function get_default_open_status() {
		$this->ensure_protected_statuses();

		$status = ProjectStatusModel::where( 'is_completed', 0 )
			->where( 'is_protected', 1 )
			->orderBy( 'position', 'asc' )
			->orderBy( 'id', 'asc' )
			->first();

		if ( $status ) {
			return $status;
		}

		return ProjectStatusModel::where( 'is_completed', 0 )
			->orderBy( 'position', 'asc' )
			->orderBy( 'id', 'asc' )
			->first();
	}

	/**
	 * @param int $project_id Project ID.
	 * @return array<string, mixed>
	 */
	public function get_financials( $project_id ) {
		$invoice_ids  = $this->get_linked_document_ids( $project_id, ActivityAssociationModel::ENTITY_TYPE_INVOICE );
		$proposal_ids = $this->get_linked_document_ids( $project_id, ActivityAssociationModel::ENTITY_TYPE_PROPOSAL );

		$total = 0.0;
		$paid  = 0.0;
		$due   = 0.0;
		$invoices = array();

		if ( class_exists( InvoiceModel::class ) && ! empty( $invoice_ids ) ) {
			$rows = InvoiceModel::whereIn( 'id', $invoice_ids )->get();
			foreach ( $rows as $invoice ) {
				$invoice_total = (float) $invoice->total;
				$invoice_paid  = (float) $invoice->amount_paid;
				$total        += $invoice_total;
				$paid         += $invoice_paid;
				$due          += max( 0, $invoice_total - $invoice_paid );
				$invoices[]    = array(
					'id'             => (int) $invoice->id,
					'invoice_number' => (string) $invoice->invoice_number,
					'status'         => (string) $invoice->status,
					'total'          => $invoice_total,
					'amount_paid'    => $invoice_paid,
					'due'            => max( 0, $invoice_total - $invoice_paid ),
				);
			}
		}

		$proposals = array();
		if ( class_exists( ProposalModel::class ) && ! empty( $proposal_ids ) ) {
			$rows = ProposalModel::whereIn( 'id', $proposal_ids )->get();
			foreach ( $rows as $proposal ) {
				$proposals[] = array(
					'id'              => (int) $proposal->id,
					'proposal_number' => (string) $proposal->proposal_number,
					'status'          => (string) $proposal->status,
					'total'           => (float) $proposal->total,
					'invoice_id'      => ProposalShaper::get_linked_invoice_id( $proposal ),
				);
			}
		}

		return array(
			'total'     => $total,
			'paid'      => $paid,
			'due'       => $due,
			'invoices'  => $invoices,
			'proposals' => $proposals,
		);
	}

	/**
	 * @param int $project_id  Project ID.
	 * @param int $invoice_id  Invoice ID.
	 * @return bool
	 */
	public function attach_invoice( $project_id, $invoice_id ) {
		return $this->attach_document( $project_id, $invoice_id, 'invoice' );
	}

	/**
	 * @param int $project_id   Project ID.
	 * @param int $proposal_id  Proposal ID.
	 * @return bool
	 */
	public function attach_proposal( $project_id, $proposal_id ) {
		return $this->attach_document( $project_id, $proposal_id, 'proposal' );
	}

	/**
	 * @param int    $project_id Project ID.
	 * @param int    $doc_id     Document ID.
	 * @param string $type       invoice|proposal.
	 * @return bool
	 */
	private function attach_document( $project_id, $doc_id, $type ) {
		$project = ProjectModel::find( $project_id );
		if ( ! $project || ! class_exists( ActivityAssociationModel::class ) ) {
			return false;
		}

		$entity_type = 'invoice' === $type
			? ActivityAssociationModel::ENTITY_TYPE_INVOICE
			: ActivityAssociationModel::ENTITY_TYPE_PROPOSAL;

		$activity = ActivityModel::create(
			array(
				'contact_id'    => $project->contact_id,
				'activity_type' => ActivityTypes::STATUS_CHANGED,
				'data'          => array(
					'title' => sprintf(
						/* translators: 1: document type, 2: document id */
						__( '%1$s #%2$d linked to project', 'doublescale' ),
						ucfirst( $type ),
						$doc_id
					),
					$type . '_id' => (int) $doc_id,
					'project_id'  => (int) $project_id,
				),
				'user_id'       => get_current_user_id(),
			)
		);

		if ( ! $activity ) {
			return false;
		}

		ActivityAssociationModel::create(
			array(
				'activity_id' => $activity->id,
				'entity_type' => ActivityAssociationModel::ENTITY_TYPE_PROJECT,
				'entity_id'   => (int) $project_id,
			)
		);
		ActivityAssociationModel::create(
			array(
				'activity_id' => $activity->id,
				'entity_type' => $entity_type,
				'entity_id'   => (int) $doc_id,
			)
		);

		return true;
	}

	/**
	 * @param int $project_id  Project ID.
	 * @param int $entity_type Document entity type constant.
	 * @return array<int>
	 */
	private function get_linked_document_ids( $project_id, $entity_type ) {
		if ( ! class_exists( ActivityAssociationModel::class ) ) {
			return array();
		}

		$activity_ids = ActivityAssociationModel::where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_PROJECT )
			->where( 'entity_id', (int) $project_id )
			->pluck( 'activity_id' )
			->toArray();

		if ( empty( $activity_ids ) ) {
			return array();
		}

		return ActivityAssociationModel::whereIn( 'activity_id', $activity_ids )
			->where( 'entity_type', $entity_type )
			->pluck( 'entity_id' )
			->unique()
			->values()
			->toArray();
	}

	/**
	 * When a project is linked to a deal, inherit the deal's contact when none
	 * was sent explicitly (picker UI may only submit deal_id).
	 *
	 * @param array<string, mixed> $data Project payload.
	 * @return array<string, mixed>
	 */
	private function resolve_contact_from_deal( array $data ) {
		if ( empty( $data['deal_id'] ) || ! empty( $data['contact_id'] ) ) {
			return $data;
		}

		if ( ! class_exists( DealModel::class ) ) {
			return $data;
		}

		$deal = DealModel::find( (int) $data['deal_id'] );
		if ( $deal && $deal->contact_id ) {
			$data['contact_id'] = (int) $deal->contact_id;
		}

		return $data;
	}
}
