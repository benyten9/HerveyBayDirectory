<?php
/**
 * Shape project models for REST responses.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Modules\Documents\Rest\ProposalShaper;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\PaymentModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Portal\Services\PortalAccess;
use DoubleScale\Modules\Portal\Services\PortalAccessGate;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;
use DoubleScale\Pro\Modules\Projects\Services\ProjectManager;
use DoubleScale\Pro\Modules\Projects\Services\ProjectTeam;
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
		$financials = self::client_financials( self::client_documents( $project ) );

		return array(
			'title'       => (string) $project->title,
			'description' => (string) ( $project->description ?? '' ),
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
			'progress'      => $project->resolveProgress(),
			'task_progress' => $project->task_progress,
			// Null when nothing was sent or the invoices are in different currencies.
			'financials'  => $financials,
		);
	}

	/**
	 * The project's custom fields the team shows the client (Client portal:
	 * Visible to the client), in their usual order, empty values left out.
	 * Lists come as arrays and yes/no fields as booleans; the portal writes
	 * them out.
	 *
	 * @param ProjectModel $project Project.
	 * @return array<int, array{id: int, label: string, type: string, value: mixed}>
	 */
	public static function client_fields( ProjectModel $project ): array {
		if ( ! class_exists( CustomFieldModel::class ) ) {
			return array();
		}
		$values = array();
		foreach ( $project->custom_fields()->get() as $field ) {
			$values[ (int) $field->id ] = (string) ( $field->pivot->value ?? '' );
		}
		if ( ! $values ) {
			return array();
		}

		$out = array();
		foreach ( CustomFieldModel::query()->where( 'scope', 'project' )->whereIn( 'id', array_keys( $values ) )->ordered()->get() as $field ) {
			if ( CustomFieldModel::PORTAL_HIDDEN === $field->get_portal_access() ) {
				continue;
			}
			$raw = trim( $values[ (int) $field->id ] ?? '' );
			if ( 'boolean' === $field->type ) {
				$value = in_array( $raw, array( 'true', '1' ), true );
			} elseif ( in_array( $field->type, array( 'multiselect', 'checkbox' ), true ) ) {
				$value = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) );
				if ( ! $value ) {
					continue;
				}
			} else {
				if ( '' === $raw ) {
					continue;
				}
				$value = $raw;
			}
			$out[] = array(
				'id'    => (int) $field->id,
				'label' => (string) $field->name,
				'type'  => (string) $field->type,
				'value' => $value,
			);
		}
		return $out;
	}

	/**
	 * The project's invoices and proposals as the client sees them, newest
	 * first. Empty while the Documents area is off: the portal could not open
	 * them, and the summary would point at documents the client cannot see.
	 *
	 * @param ProjectModel $project Project.
	 * @return array<int, array<string, mixed>>
	 */
	public static function client_documents( ProjectModel $project ): array {
		$documents_on = function_exists( 'doublescale_sales_child_module_active' )
			? doublescale_sales_child_module_active( 'documents' )
			: false;
		if ( ! $documents_on ) {
			return array();
		}

		$linked = ProjectManager::instance()->get_client_documents( $project );
		$rows   = array();

		foreach ( $linked['invoices'] as $invoice ) {
			$total  = (float) $invoice->total;
			$paid   = (float) $invoice->amount_paid;
			$rows[] = array(
				'type'        => 'invoice',
				'id'          => (int) $invoice->id,
				'number'      => (string) $invoice->invoice_number,
				'subject'     => null,
				'status'      => (string) $invoice->status,
				'date'        => $invoice->invoice_date ? (string) $invoice->invoice_date : null,
				'due_date'    => self::day( $invoice->due_date ),
				'open_till'   => null,
				'is_overdue'  => method_exists( InvoiceShaper::class, 'is_overdue' ) && InvoiceShaper::is_overdue( $invoice ),
				// Online gateways take it: the Overview offers "Pay", else "View".
				'can_pay'     => method_exists( InvoiceShaper::class, 'can_pay' ) ? InvoiceShaper::can_pay( $invoice ) : null,
				'is_expired'  => false,
				'currency'    => (string) $invoice->currency,
				'total'       => $total,
				'amount_paid' => $paid,
				'balance'     => max( 0, round( $total - $paid, 2 ) ),
				'hash'        => (string) $invoice->hash,
			);
		}

		foreach ( $linked['proposals'] as $proposal ) {
			$rows[] = array(
				'type'        => 'proposal',
				'id'          => (int) $proposal->id,
				'number'      => (string) $proposal->proposal_number,
				'subject'     => $proposal->subject ? (string) $proposal->subject : null,
				'status'      => (string) $proposal->status,
				'date'        => $proposal->date ? (string) $proposal->date : null,
				'due_date'    => null,
				'open_till'   => self::day( $proposal->open_till ),
				'is_overdue'  => false,
				'is_expired'  => method_exists( ProposalShaper::class, 'is_expired' ) && ProposalShaper::is_expired( $proposal ),
				'currency'    => (string) $proposal->currency,
				'total'       => (float) $proposal->total,
				'amount_paid' => null,
				'balance'     => null,
				'hash'        => (string) $proposal->hash,
			);
		}

		return $rows;
	}

	/**
	 * A stored date as its day ("Y-m-d"), or null when there is none.
	 *
	 * @param mixed $value Stored date or datetime.
	 * @return string|null
	 */
	private static function day( $value ): ?string {
		$value = (string) $value;
		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}
		return substr( $value, 0, 10 );
	}

	/**
	 * Money summary of the client's invoices: total, paid and due, in their
	 * currency. Null when there are none, or when they are in more than one
	 * currency — amounts in different currencies are never added together.
	 *
	 * @param array<int, array<string, mixed>> $documents Rows from client_documents().
	 * @return array{currency: string, total: float, paid: float, due: float}|null
	 */
	public static function client_financials( array $documents ): ?array {
		$invoices = array_values(
			array_filter(
				$documents,
				static function ( $row ) {
					return 'invoice' === ( $row['type'] ?? '' );
				}
			)
		);
		if ( empty( $invoices ) ) {
			return null;
		}

		$currencies = array_unique( array_column( $invoices, 'currency' ) );
		if ( 1 !== count( $currencies ) ) {
			return null;
		}

		return array(
			'currency' => (string) reset( $currencies ),
			'total'    => round( array_sum( array_column( $invoices, 'total' ) ), 2 ),
			'paid'     => round( array_sum( array_column( $invoices, 'amount_paid' ) ), 2 ),
			'due'      => round( array_sum( array_column( $invoices, 'balance' ) ), 2 ),
		);
	}

	/**
	 * Fields that carry the project's money. The public link counts: its page
	 * shows the budget and the client's invoices.
	 */
	public const MONEY_FIELDS = array( 'budget', 'hourly_rate', 'fee_type', 'tax_id', 'tax', 'hash', 'public_url' );

	/**
	 * Fit a team-facing project payload to the person reading it: what they
	 * may do, who is on the team, and no money unless they may see it.
	 *
	 * @param array<string, mixed> $data        Shaped project.
	 * @param ProjectModel         $project     Project.
	 * @param array<string, bool>  $permissions {@see \DoubleScale\Pro\Modules\Projects\Capabilities::project_permissions()}.
	 * @param int[]                $member_ids  Team member ids (the owner is not one).
	 * @return array<string, mixed>
	 */
	public static function apply_viewer( array $data, ProjectModel $project, array $permissions, array $member_ids ): array {
		$data['permissions']         = $permissions;
		$data['team_member_ids']     = array_values( array_map( 'intval', $member_ids ) );
		// Whether new time is billable, without saying how the project is billed.
		$data['billable_by_default'] = ProjectModel::FEE_FIXED !== $project->effectiveFeeType();

		if ( empty( $permissions['can_view_financials'] ) ) {
			foreach ( self::MONEY_FIELDS as $field ) {
				$data[ $field ] = null;
			}
		}

		return $data;
	}

	/**
	 * A project's team for the screens: the owner, then the members.
	 *
	 * @param ProjectModel $project         Project.
	 * @param bool         $can_manage_team Whether the reader may change it.
	 * @return array{owner: array<string, mixed>|null, members: array<int, array<string, mixed>>, can_manage_team: bool}
	 */
	public static function team( ProjectModel $project, bool $can_manage_team ): array {
		$owner_id = (int) $project->owner_id;
		$owner    = $owner_id > 0 ? self::team_person( $owner_id ) : null;
		$members  = array();

		foreach ( ProjectTeam::members( (int) $project->id ) as $row ) {
			$person = self::team_person( $row['user_id'] );
			if ( null === $person ) {
				continue;
			}
			$members[] = array_merge(
				$person,
				array(
					'user_id'    => $row['user_id'],
					'reason'     => $row['reason'],
					'added_by'   => $row['added_by'],
					'created_at' => $row['created_at'],
				)
			);
		}

		return array(
			'owner'           => $owner,
			'members'         => $members,
			'can_manage_team' => $can_manage_team,
		);
	}

	/**
	 * @param int $user_id User id.
	 * @return array<string, mixed>|null
	 */
	private static function team_person( int $user_id ): ?array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		return array(
			'id'           => $user_id,
			'name'         => (string) $user->display_name,
			'email'        => (string) $user->user_email,
			'avatar_url'   => (string) get_avatar_url( $user_id, array( 'size' => 48 ) ),
			'role_label'   => ProjectTeam::role_label( $user_id ),
			// Lost their projects role: still listed, no longer sees the project.
			'has_access'   => ProjectTeam::is_eligible( $user_id ),
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

	/**
	 * Whether the team shared this project file with the client. Files stay
	 * hidden until someone shares them.
	 *
	 * @param AttachmentModel $attachment Attachment row.
	 * @return bool
	 */
	public static function is_client_visible( AttachmentModel $attachment ): bool {
		$meta = is_array( $attachment->meta ) ? $attachment->meta : array();
		return ! empty( $meta['client_visible'] );
	}

	/**
	 * The project's files the team shared with the client, newest first.
	 *
	 * @param ProjectModel $project Project.
	 * @return array<int, AttachmentModel>
	 */
	private static function shared_attachments( ProjectModel $project ): array {
		if ( ! class_exists( AttachmentModel::class ) ) {
			return array();
		}

		$rows = AttachmentModel::forType( 'project' )
			->where( 'attachable_id', (int) $project->id )
			->active()
			->orderBy( 'created_at', 'desc' )
			->get();

		$shared = array();
		foreach ( $rows as $attachment ) {
			if ( self::is_client_visible( $attachment ) ) {
				$shared[] = $attachment;
			}
		}
		return $shared;
	}

	/**
	 * Shared files for the client portal: what a client needs to see and
	 * download, with a link signed now (signed links last an hour or two), and
	 * never the stored path or hash.
	 *
	 * @param ProjectModel $project Project.
	 * @return array<int, array{id:int, file_name:string, file_size:int, file_type:string, created_at:string, url:string}>
	 */
	public static function client_attachments( ProjectModel $project ): array {
		$service = new AttachmentService();
		$files   = array();
		foreach ( self::shared_attachments( $project ) as $attachment ) {
			$shaped  = $service->shape_for_api( $attachment );
			$files[] = array(
				'id'         => (int) $attachment->id,
				'file_name'  => (string) $attachment->file_name,
				'file_size'  => (int) $attachment->file_size,
				'file_type'  => (string) $attachment->file_type,
				'created_at' => (string) $attachment->created_at,
				'url'        => (string) ( $shaped['url'] ?? '' ),
			);
		}
		return $files;
	}

	/**
	 * The name of the status a change moved to: kept with the change, else
	 * the status as it is now, else null (deleted: never its id).
	 *
	 * @param array<string, mixed> $data Activity data.
	 * @return string|null
	 */
	private static function status_name( array $data ): ?string {
		$kept = isset( $data['new_status_name'] ) ? trim( (string) $data['new_status_name'] ) : '';
		if ( '' !== $kept ) {
			return $kept;
		}
		$status = ! empty( $data['new_status_id'] ) ? ProjectStatusModel::find( (int) $data['new_status_id'] ) : null;

		return $status ? (string) $status->name : null;
	}

	/**
	 * The project's history as the client may see it: when it started, status
	 * and date changes, files the team shared, and the invoices and proposals
	 * the client was sent. Notes, comments, emails, the owner and the budget
	 * stay with the team. Newest first.
	 *
	 * @param ProjectModel                     $project   Project.
	 * @param array<int, array<string, mixed>> $documents {@see client_documents()} rows.
	 * @param ContactModel|null                $contact   The client, to respect what they may see.
	 * @return array<int, array<string, mixed>>
	 */
	public static function client_activities( ProjectModel $project, array $documents, $contact = null ): array {
		$items = array(
			array(
				'id'   => 'project-created',
				'type' => 'project_created',
				'date' => (string) $project->created_at,
			),
		);

		if ( class_exists( ActivityModel::class ) && class_exists( ActivityAssociationModel::class ) ) {
			$rows = ActivityModel::whereIn( 'activity_type', array( ActivityTypes::PROJECT_STATUS_CHANGED, ActivityTypes::PROJECT_EVENT ) )
				->whereHas(
					'associations',
					static function ( $query ) use ( $project ) {
						$query->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_PROJECT )
							->where( 'entity_id', (int) $project->id );
					}
				)
				->orderBy( 'created_at', 'desc' )
				->limit( 100 )
				->get();

			foreach ( $rows as $row ) {
				$data = is_array( $row->data ) ? $row->data : array();
				if ( ActivityTypes::PROJECT_STATUS_CHANGED === (string) $row->activity_type ) {
					$items[] = array(
						'id'   => 'activity-' . (int) $row->id,
						'type' => 'status_changed',
						'date' => (string) $row->created_at,
						'to'   => self::status_name( $data ),
					);
					continue;
				}
				$key = (string) ( $data['event_key'] ?? '' );
				if ( 'start_date_changed' === $key || 'due_date_changed' === $key ) {
					$to      = isset( $data['to'] ) && is_string( $data['to'] ) && '' !== $data['to'] ? $data['to'] : null;
					$items[] = array(
						'id'   => 'activity-' . (int) $row->id,
						'type' => $key,
						'date' => (string) $row->created_at,
						'to'   => $to,
					);
				}
			}
		}

		foreach ( self::shared_attachments( $project ) as $attachment ) {
			$meta    = is_array( $attachment->meta ) ? $attachment->meta : array();
			$items[] = array(
				'id'    => 'file-' . (int) $attachment->id,
				'type'  => 'file_shared',
				'date'  => (string) ( $meta['client_visible_at'] ?? $attachment->created_at ),
				'title' => (string) $attachment->file_name,
			);
		}

		foreach ( $documents as $doc ) {
			foreach ( self::document_events( $doc ) as $event ) {
				$items[] = $event;
			}
		}

		// A document type hidden from this client leaves its events out too.
		if ( $contact instanceof ContactModel && class_exists( PortalAccess::class ) && class_exists( PortalAccessGate::class ) ) {
			$access = PortalAccess::for_contact( $contact );
			$items  = array_values(
				array_filter(
					$items,
					static fn( array $item ): bool => empty( $item['document_type'] )
						|| PortalAccessGate::visible( 'document_types', (string) $item['document_type'], $access )
				)
			);
		}

		usort(
			$items,
			static fn( array $a, array $b ): int => strcmp( self::sortable_date( $b['date'] ), self::sortable_date( $a['date'] ) )
		);

		return array_slice( $items, 0, 50 );
	}

	/**
	 * What happened to one invoice or proposal the client was sent, each step
	 * at its own date.
	 *
	 * @param array<string, mixed> $doc {@see client_documents()} row.
	 * @return array<int, array<string, mixed>>
	 */
	private static function document_events( array $doc ): array {
		$type   = (string) ( $doc['type'] ?? '' );
		$id     = (int) ( $doc['id'] ?? 0 );
		$base   = array(
			'title'         => (string) ( $doc['number'] ?? '' ),
			'document_type' => $type,
			'hash'          => (string) ( $doc['hash'] ?? '' ),
		);
		$events = array();

		if ( 'invoice' === $type && doublescale_module_data_ready( 'documents' ) ) {
			$invoice = InvoiceModel::find( $id );
			if ( ! $invoice ) {
				return array();
			}
			$events[] = $base + array(
				'id'   => 'invoice-sent-' . $id,
				'type' => 'invoice_sent',
				'date' => (string) ( $invoice->sent_at ?: ( $invoice->invoice_date ?: $invoice->created_at ) ),
			);
			if ( 'paid' === (string) $invoice->status ) {
				$paid_on = class_exists( PaymentModel::class )
					? PaymentModel::where( 'invoice_id', $id )->max( 'payment_date' )
					: null;
				$events[] = $base + array(
					'id'   => 'invoice-paid-' . $id,
					'type' => 'invoice_paid',
					'date' => (string) ( $paid_on ?: $invoice->updated_at ),
				);
			}
			return $events;
		}

		if ( 'proposal' === $type && doublescale_module_data_ready( 'documents' ) ) {
			$proposal = ProposalModel::find( $id );
			if ( ! $proposal ) {
				return array();
			}
			$events[] = $base + array(
				'id'   => 'proposal-sent-' . $id,
				'type' => 'proposal_sent',
				'date' => (string) ( $proposal->sent_at ?: ( $proposal->date ?: $proposal->created_at ) ),
			);
			if ( 'accepted' === (string) $proposal->status ) {
				$events[] = $base + array(
					'id'   => 'proposal-accepted-' . $id,
					'type' => 'proposal_accepted',
					'date' => (string) ( $proposal->accepted_at ?: $proposal->updated_at ),
				);
			} elseif ( 'declined' === (string) $proposal->status ) {
				$events[] = $base + array(
					'id'   => 'proposal-declined-' . $id,
					'type' => 'proposal_declined',
					'date' => (string) ( $proposal->declined_at ?: $proposal->updated_at ),
				);
			}
		}

		return $events;
	}

	/**
	 * A date-only value sorts as the start of its day.
	 *
	 * @param mixed $date `Y-m-d` or `Y-m-d H:i:s`.
	 * @return string
	 */
	private static function sortable_date( $date ): string {
		$date = (string) $date;
		return 10 === strlen( $date ) ? $date . ' 00:00:00' : $date;
	}
}
