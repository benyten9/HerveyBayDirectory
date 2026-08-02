<?php

/**
 * Class ActivityManager
 * Unified manager for handling all activity operations (contacts and deals)
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Activities\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Activities\Models\ActivityCommentModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\Constants\ActivityTypes;

/**
 * ActivityManager class
 */
final class ActivityManager {

	/**
	 * Class Instance.
	 *
	 * @since 1.0.0
	 *
	 * @var ActivityManager
	 */
	private static $instance;

	/**
	 * Manager Instance.
	 *
	 * Instantiates or reuses an instance of ActivityManager.
	 *
	 * @since 1.0.0
	 *
	 * @return ActivityManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		add_action( 'doublescale_ready', array( $this, 'init' ) );
		add_action( 'wp_login', array( $this, 'on_user_login' ), 10, 2 );
		add_action( 'wp_logout', array( $this, 'on_user_logout' ), 10, 1 );
	}

	public function on_user_login( $user_login, $user ) {
		ActivityModel::log_login(
			array(
				'user_id'    => $user->ID,
				'user_email' => $user->user_email,
			)
		);
	}

	public function on_user_logout( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}
		ActivityModel::log_logout(
			array(
				'user_id'    => $user_id,
				'user_email' => $user->user_email,
			)
		);
	}

	/**
	 * Initialize
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init() {
		// Add any initialization hooks here.
	}

	/**
	 * Add note activity
	 *
	 * @since 1.0.0
	 *
	 * @param array    $data Note data (contact_id, deal_id, title, content).
	 * @param int|null $user_id User ID.
	 *
	 * @return ActivityModel|null
	 */
	public function add_note( $data, $user_id = null ) {
		$contact_id  = $data['contact_id'] ?? null;
		$entity_id   = $data['entity_id'] ?? null;
		$entity_type = $data['entity_type'] ?? null;
		$title       = $data['title'] ?? '';
		$content     = $data['content'] ?? '';

		// Must have contact_id OR both entity_id and entity_type.
		$has_entity_association = $entity_id && $entity_type;
		if ( ! $contact_id && ! $has_entity_association ) {
			return null;
		}

		if ( empty( $content ) && empty( $title ) ) {
			return null;
		}

		if ( $entity_type === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL ) {
			if ( ! $this->can_access_deal( $entity_id ) ) {
				return null;
			}
			// Get contact_id from deal if not provided.
			if ( $entity_id && ! $contact_id ) {
				$contact_id = $this->get_contact_id_from_deal( $entity_id );
			}
		}

		if ( $entity_type === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_TASK ) {
			if ( ! $this->can_access_task( $entity_id ) ) {
				return null;
			}
		}

		if ( $entity_type === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT ) {
			if ( ! $this->can_manage_project( $entity_id ) ) {
				return null;
			}
		}

		$note_data = array(
			'title'   => sanitize_text_field( $title ),
			'content' => wp_kses_post( $content ),
		);

		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$note_data = array_merge( $note_data, $data['meta'] );
		}

		$activity = ActivityModel::create(
			array(
				'contact_id'    => $contact_id,
				'activity_type' => 'note',
				'data'          => $note_data,
				'user_id'       => $user_id ?: get_current_user_id(),
			)
		);

		// Create activity association with entity if provided
		if ( $activity && $entity_type && $entity_id && class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
			\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::create(
				array(
					'activity_id' => $activity->id,
					'entity_type' => $entity_type,
					'entity_id'   => $entity_id,
				)
			);
		}

		do_action( 'doublescale_note_add', $activity, $data );

		// Fire deal-specific hook for deal note notifications.
		if ( $activity && $entity_type === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL && $entity_id ) {
			$deal_class = doublescale_resolve_deal_model_class();
			if ( $deal_class ) {
				$deal = $deal_class::find( $entity_id );
				if ( $deal ) {
					do_action( 'doublescale_deal_note_added', $deal, $activity );
				}
			}
		}

		return $activity;
	}

	/**
	 * Log email activity
	 *
	 * @since 1.0.0
	 *
	 * @param array    $data Email data.
	 * @param int|null $user_id User ID.
	 *
	 * @return ActivityModel|null
	 */
	public function log_email( $data, $user_id = null ) {
		$contact_id  = $data['contact_id'] ?? null;
		$entity_id   = $data['entity_id'] ?? null;
		$entity_type = $data['entity_type'] ?? null;

		// Must have contact_id OR both entity_id and entity_type.
		$has_entity_association = $entity_id && $entity_type;
		if ( ! $contact_id && ! $has_entity_association ) {
			return null;
		}

		if ( $entity_type === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL ) {
			if ( ! $this->can_access_deal( $entity_id ) ) {
				return null;
			}
			// Get contact_id and contact info from deal if not provided.
			if ( ! $contact_id ) {
				$deal_data  = $this->get_deal_with_contact( $entity_id );
				$contact_id = $deal_data['contact_id'] ?? null;

				if ( $deal_data['contact'] ?? null ) {
					$data['contact_email'] = $data['contact_email'] ?? $deal_data['contact']['email'];
					$data['contact_name']  = $data['contact_name'] ?? $deal_data['contact']['name'];
				}
			}
		}

		$activity = ActivityModel::create(
			array(
				'contact_id'    => $contact_id,
				'activity_type' => 'email_sent',
				'data'          => array(
					'subject'       => sanitize_text_field( $data['subject'] ?? '' ),
					'body'          => wp_kses_post( $data['body'] ?? '' ),
					'sent_at'       => $data['sent_at'] ?? current_time( 'mysql', true ),
					'contact_email' => sanitize_email( $data['contact_email'] ?? '' ),
					'contact_name'  => sanitize_text_field( $data['contact_name'] ?? '' ),
					'source'        => 'manual',
				),
				'user_id'       => $user_id ?: get_current_user_id(),
			)
		);

		// Create activity association with entity if provided
		if ( $activity && $entity_type && $entity_id && class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
			\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::create(
				array(
					'activity_id' => $activity->id,
					'entity_type' => $entity_type,
					'entity_id'   => $entity_id,
				)
			);
		}

		do_action( 'doublescale_mail_log', $activity, $data );

		return $activity;
	}

	/**
	 * Log call activity
	 *
	 * @since 1.0.0
	 *
	 * @param array    $data Call data.
	 * @param int|null $user_id User ID.
	 *
	 * @return ActivityModel|null
	 */
	public function log_call( $data, $user_id = null ) {
		$contact_id  = $data['contact_id'] ?? null;
		$entity_id   = $data['entity_id'] ?? null;
		$entity_type = $data['entity_type'] ?? null;

		// Must have contact_id OR both entity_id and entity_type.
		$has_entity_association = $entity_id && $entity_type;
		if ( ! $contact_id && ! $has_entity_association ) {
			return null;
		}

		// Check deal permissions if deal_id is provided.
		if ( $entity_type === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL ) {
			if ( ! $this->can_access_deal( $entity_id ) ) {
				return null;
			}
			// Get contact_id from deal if not provided.
			if ( ! $contact_id ) {
				$contact_id = $this->get_contact_id_from_deal( $entity_id );
			}
		}

		$activity = ActivityModel::create(
			array(
				'contact_id'    => $contact_id,
				'activity_type' => 'call_logged',
				'data'          => array(
					'duration'     => isset( $data['duration'] ) ? intval( $data['duration'] ) : null,
					'outcome'      => sanitize_text_field( $data['outcome'] ?? '' ),
					'notes'        => wp_kses_post( $data['notes'] ?? '' ),
					'called_at'    => $data['called_at'] ?? current_time( 'mysql', true ),
					'phone_number' => sanitize_text_field( $data['phone_number'] ?? '' ),
				),
				'user_id'       => $user_id ?: get_current_user_id(),
			)
		);

		// Create activity association with entity if provided
		if ( $activity && $entity_type && $entity_id && class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
			\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::create(
				array(
					'activity_id' => $activity->id,
					'entity_type' => $entity_type,
					'entity_id'   => $entity_id,
				)
			);
		}

		do_action( 'doublescale_call_log', $activity, $data );

		return $activity;
	}

	/**
	 * Schedule meeting activity
	 *
	 * @since 1.0.0
	 *
	 * @param array    $data Meeting data.
	 * @param int|null $user_id User ID.
	 *
	 * @return ActivityModel|null
	 */
	public function schedule_meeting( $data, $user_id = null ) {
		$contact_id  = $data['contact_id'] ?? null;
		$entity_id   = $data['entity_id'] ?? null;
		$entity_type = $data['entity_type'] ?? null;

		// Must have contact_id OR both entity_id and entity_type.
		$has_entity_association = $entity_id && $entity_type;
		if ( ! $contact_id && ! $has_entity_association ) {
			return null;
		}

		if ( $entity_type === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL ) {
			// Check deal permissions if deal_id is provided.
			if ( $entity_id && ! $this->can_access_deal( $entity_id ) ) {
				return null;
			}

			// Get contact info from deal if not provided.
			if ( $entity_id && ! $contact_id ) {
				$deal_data  = $this->get_deal_with_contact( $entity_id );
				$contact_id = $deal_data['contact_id'] ?? null;

				if ( $deal_data['contact'] ?? null ) {
					$data['primary_attendee_id']    = $data['primary_attendee_id'] ?? $deal_data['contact']['id'];
					$data['primary_attendee_name']  = $data['primary_attendee_name'] ?? $deal_data['contact']['name'];
					$data['primary_attendee_email'] = $data['primary_attendee_email'] ?? $deal_data['contact']['email'];
				}
			}
		}

		$scheduled_at = sanitize_text_field( $data['scheduled_at'] ?? '' );

		$activity_data = array(
			'contact_id'    => $contact_id,
			'activity_type' => 'meeting_scheduled',
			'data'          => array(
				'title'                  => sanitize_text_field( $data['title'] ?? '' ),
				'scheduled_at'           => $scheduled_at,
				'duration'               => isset( $data['duration'] ) ? intval( $data['duration'] ) : 60,
				'location'               => sanitize_text_field( $data['location'] ?? '' ),
				'description'            => wp_kses_post( $data['description'] ?? '' ),
				'primary_attendee_id'    => $data['primary_attendee_id'] ?? null,
				'primary_attendee_name'  => sanitize_text_field( $data['primary_attendee_name'] ?? '' ),
				'primary_attendee_email' => sanitize_email( $data['primary_attendee_email'] ?? '' ),
			),
			'user_id'       => $user_id ?: get_current_user_id(),
		);

		$activity = ActivityModel::create( $activity_data );

		// Create activity association with entity if provided
		if ( $activity && $entity_type && $entity_id && class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
			\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::create(
				array(
					'activity_id' => $activity->id,
					'entity_type' => $entity_type,
					'entity_id'   => $entity_id,
				)
			);
		}

		do_action( 'doublescale_meeting_schedule', $activity, $data );

		return $activity;
	}

	/**
	 * Get activities with pagination
	 *
	 * @since 1.0.0
	 *
	 * @param array $filters Filter criteria (contact_id, deal_id, activity_type, user_id, date_from, date_to).
	 * @param int   $per_page Results per page.
	 * @param int   $page Page number.
	 *
	 * @return \Illuminate\Pagination\LengthAwarePaginator|null
	 */
	public function get_activities( $filters = array(), $per_page = 20, $page = 1 ) {
		$query = ActivityModel::withMorphAppends()->with( array( 'user', 'comments.user' ) );

		// Filter by contact via activity_associations (polymorphic).
		if ( ! empty( $filters['contact_id'] ) ) {
			$query->whereHas(
				'associations',
				function ( $q ) use ( $filters ) {
					$q->where( 'entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_CONTACT )
						->where( 'entity_id', $filters['contact_id'] );
				}
			);
		}

		// Filter by deal using activity_associations table.
		if ( ! empty( $filters['entity_id'] ) && ! empty( $filters['entity_type'] ) && $filters['entity_type'] === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL ) {
			// Check deal permissions.
			if ( ! $this->can_access_deal( $filters['entity_id'] ) ) {
				return null;
			}

			// Use whereHas to filter activities that have a deal association
			if ( class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
				$query->whereHas(
					'associations',
					function ( $q ) use ( $filters ) {
						$q->where( 'entity_type', $filters['entity_type'] )
							->where( 'entity_id', $filters['entity_id'] );
					}
				);
			}
		}

		// Filter by task using activity_associations table.
		if ( ! empty( $filters['entity_id'] ) && ! empty( $filters['entity_type'] ) && $filters['entity_type'] === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_TASK ) {
			if ( ! $this->can_access_task( $filters['entity_id'] ) ) {
				return null;
			}

			if ( class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
				$query->whereHas(
					'associations',
					function ( $q ) use ( $filters ) {
						$q->where( 'entity_type', $filters['entity_type'] )
							->where( 'entity_id', $filters['entity_id'] );
					}
				);
			}
		}

		// Filter by project using activity_associations table.
		if ( ! empty( $filters['entity_id'] ) && ! empty( $filters['entity_type'] ) && (int) $filters['entity_type'] === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT ) {
			if ( ! $this->can_access_project( $filters['entity_id'] ) ) {
				return null;
			}

			if ( class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
				$query->whereHas(
					'associations',
					function ( $q ) use ( $filters ) {
						$q->where( 'entity_type', $filters['entity_type'] )
							->where( 'entity_id', $filters['entity_id'] );
					}
				);
			}
		}

		// Exclude activities that have deal or task associations when no entity type is provided.
		// Contact associations are the contact timeline's source of truth and must not be excluded.
		if ( empty( $filters['entity_type'] ) ) {
			if ( class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
				$query->whereDoesntHave(
					'associations',
					function ( $q ) {
						$q->whereIn(
							'entity_type',
							array(
								\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL,
								\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_TASK,
								\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT,
							)
						);
					}
				);
			}
		}

		// Task audit rows only belong on the task activity feed.
		if (
			empty( $filters['entity_type'] ) ||
			(int) $filters['entity_type'] !== \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_TASK
		) {
			$query->where( 'activity_type', '!=', ActivityTypes::TASK_EVENT );
		}

		// Project audit rows only belong on the project activity feed.
		if (
			empty( $filters['entity_type'] ) ||
			(int) $filters['entity_type'] !== \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT
		) {
			$query->where( 'activity_type', '!=', ActivityTypes::PROJECT_EVENT );
		}

		// Filter by activity type.
		if ( ! empty( $filters['activity_type'] ) ) {
			$activity_types = $filters['activity_type'];
			// Handle comma-separated string.
			if ( is_string( $activity_types ) && strpos( $activity_types, ',' ) !== false ) {
				$activity_types = array_map( 'trim', explode( ',', $activity_types ) );
			}
			if ( is_array( $activity_types ) ) {
				$query->whereIn( 'activity_type', $activity_types );
			} else {
				$query->where( 'activity_type', $activity_types );
			}
		}

		// Filter by user.
		if ( ! empty( $filters['user_id'] ) ) {
			$query->where( 'user_id', $filters['user_id'] );
		}

		// Filter by date range using the indexed activity_date column.
		// Use range comparisons (>=, <) instead of DATE() to preserve index usage.
		if ( ! empty( $filters['date_from'] ) ) {
			$query->where( 'activity_date', '>=', $filters['date_from'] . ' 00:00:00' );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$query->where( 'activity_date', '<', gmdate( 'Y-m-d', strtotime( $filters['date_to'] . ' +1 day' ) ) . ' 00:00:00' );
		}

		// Sort by the activity-specific date by default.
		$sort_by    = $filters['sort_by'] ?? 'activity_date';
		$sort_order = $filters['sort_order'] ?? 'desc';
		$allowed    = array( 'activity_date', 'created_at', 'updated_at' );
		$query->orderBy( in_array( $sort_by, $allowed, true ) ? $sort_by : 'activity_date', $sort_order );

		return $query->paginate( $per_page, array( '*' ), 'page', $page );
	}

	/**
	 * Get single activity
	 *
	 * @since 1.0.0
	 *
	 * @param int  $activity_id Activity ID.
	 * @param bool $with_comments Include comments.
	 *
	 * @return ActivityModel|null
	 */
	public function get_activity( $activity_id, $with_comments = false ) {
		$relations = array( 'user', 'associations' );
		if ( $with_comments ) {
			$relations[] = 'comments.user';
		}

		$activity = ActivityModel::withMorphAppends()->with( $relations )->find( $activity_id );

		if ( ! $activity ) {
			return null;
		}

		// Check deal permissions if activity is associated with a deal.
		if ( $activity->deal_id && ! $this->can_access_deal( $activity->deal_id ) ) {
			return null;
		}

		// Check task permissions if activity is associated with a task.
		if ( $activity->task_id && ! $this->can_access_task( $activity->task_id ) ) {
			return null;
		}

		$project_id = $this->get_project_id_from_activity( $activity );
		if ( $project_id && ! $this->can_access_project( $project_id ) ) {
			return null;
		}

		return $activity;
	}

	/**
	 * Update activity (only for user-created activities)
	 *
	 * @since 1.0.0
	 *
	 * @param int      $activity_id Activity ID.
	 * @param array    $data Activity data to update.
	 * @param int|null $user_id User performing the action.
	 *
	 * @return ActivityModel|null
	 */
	public function update_activity( $activity_id, $data, $user_id = null ) {
		$activity = ActivityModel::withMorphAppends()->with( array( 'associations' ) )->find( $activity_id );

		if ( ! $activity ) {
			return null;
		}

		// Check if activity is system-generated (immutable).
		if ( $activity->is_system_activity() ) {
			return null;
		}

		if ( ! $activity->is_editable() ) {
			return null;
		}

		// Check deal permissions if activity is associated with a deal.
		if ( $activity->deal_id && ! $this->can_access_deal( $activity->deal_id ) ) {
			return null;
		}

		// Check task permissions if activity is associated with a task.
		if ( $activity->task_id && ! $this->can_access_task( $activity->task_id ) ) {
			return null;
		}

		$project_id = $this->get_project_id_from_activity( $activity );
		if ( $project_id && ! $this->can_manage_project( $project_id ) ) {
			return null;
		}

		// Only allow the creator or admin to edit.
		if ( $user_id && $activity->user_id !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		// Update activity data based on type.
		switch ( $activity->activity_type ) {
			case 'note':
				$current_data = $activity->data ?? array();
				if ( isset( $data['title'] ) ) {
					$current_data['title'] = sanitize_text_field( $data['title'] );
				}
				if ( isset( $data['content'] ) ) {
					$current_data['content'] = wp_kses_post( $data['content'] );
				}
				$activity->data = $current_data;
				break;

			case 'email_sent':
				if ( isset( $data['email_data'] ) && is_array( $data['email_data'] ) ) {
					$sent_at        = $data['email_data']['sent_at'] ?? $activity->data['sent_at'] ?? current_time( 'mysql', true );
					$activity->data = array(
						'subject'       => sanitize_text_field( $data['email_data']['subject'] ?? $activity->data['subject'] ?? '' ),
						'body'          => wp_kses_post( $data['email_data']['body'] ?? $activity->data['body'] ?? '' ),
						'sent_at'       => $sent_at,
						'contact_email' => $activity->data['contact_email'] ?? '',
						'contact_name'  => $activity->data['contact_name'] ?? '',
						'source'        => 'manual',
					);
				}
				break;

			case 'call_logged':
				if ( isset( $data['call_data'] ) && is_array( $data['call_data'] ) ) {
					$called_at      = $data['call_data']['called_at'] ?? $activity->data['called_at'] ?? current_time( 'mysql', true );
					$activity->data = array(
						'duration'     => isset( $data['call_data']['duration'] ) ? intval( $data['call_data']['duration'] ) : ( $activity->data['duration'] ?? null ),
						'outcome'      => sanitize_text_field( $data['call_data']['outcome'] ?? $activity->data['outcome'] ?? '' ),
						'notes'        => wp_kses_post( $data['call_data']['notes'] ?? $activity->data['notes'] ?? '' ),
						'called_at'    => $called_at,
						'phone_number' => sanitize_text_field( $data['call_data']['phone_number'] ?? $activity->data['phone_number'] ?? '' ),
					);
				}
				break;

			case 'meeting_scheduled':
				if ( isset( $data['meeting_data'] ) && is_array( $data['meeting_data'] ) ) {
					$scheduled_at   = sanitize_text_field( $data['meeting_data']['scheduled_at'] ?? $data['meeting_data']['meeting_date_time'] ?? $activity->data['scheduled_at'] ?? '' );
					$activity->data = array(
						'title'                  => sanitize_text_field( $data['meeting_data']['title'] ?? $data['meeting_data']['meeting_title'] ?? $activity->data['title'] ?? '' ),
						'scheduled_at'           => $scheduled_at,
						'duration'               => isset( $data['meeting_data']['duration'] ) ? intval( $data['meeting_data']['duration'] ) : ( $activity->data['duration'] ?? 60 ),
						'location'               => sanitize_text_field( $data['meeting_data']['location'] ?? $activity->data['location'] ?? '' ),
						'description'            => wp_kses_post( $data['meeting_data']['description'] ?? $activity->data['description'] ?? '' ),
						'primary_attendee_id'    => $activity->data['primary_attendee_id'] ?? null,
						'primary_attendee_name'  => $activity->data['primary_attendee_name'] ?? '',
						'primary_attendee_email' => $activity->data['primary_attendee_email'] ?? '',
					);
				}
				break;
		}

		$activity->save();

		do_action( 'doublescale_activity_update', $activity );

		return $activity;
	}

	/**
	 * Delete activity (only for user-created activities)
	 *
	 * @since 1.0.0
	 *
	 * @param int      $activity_id Activity ID.
	 * @param int|null $user_id User performing the action.
	 *
	 * @return bool
	 */
	public function delete_activity( $activity_id, $user_id = null ) {
		$activity = ActivityModel::withMorphAppends()->find( $activity_id );

		if ( ! $activity ) {
			return false;
		}

		// Check if activity is system-generated (immutable).
		if ( $activity->is_system_activity() ) {
			return false;
		}

		if ( ! $activity->is_editable() ) {
			return false;
		}

		// Check deal permissions if activity is associated with a deal.
		if ( $activity->deal_id && ! $this->can_access_deal( $activity->deal_id ) ) {
			return false;
		}

		// Check task permissions if activity is associated with a task.
		if ( $activity->task_id && ! $this->can_access_task( $activity->task_id ) ) {
			return false;
		}

		$project_id = $this->get_project_id_from_activity( $activity );
		if ( $project_id && ! $this->can_manage_project( $project_id ) ) {
			return false;
		}

		// Only allow the creator or admin to delete.
		if ( $user_id && $activity->user_id !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		do_action( 'doublescale_activity_pre_delete', $activity );

		$deleted = $activity->delete();

		if ( $deleted ) {
			do_action( 'doublescale_activity_delete', $activity_id );
		}

		return $deleted;
	}

	/**
	 * Bulk delete activities
	 *
	 * @since 1.0.0
	 *
	 * @param array    $activity_ids Array of activity IDs.
	 * @param int|null $user_id User performing the action.
	 *
	 * @return int Number of deleted activities.
	 */
	public function bulk_delete_activities( $activity_ids, $user_id = null ) {
		if ( empty( $activity_ids ) ) {
			return 0;
		}

		$deleted_count = 0;

		foreach ( $activity_ids as $activity_id ) {
			if ( $this->delete_activity( $activity_id, $user_id ) ) {
				++$deleted_count;
			}
		}

		do_action( 'doublescale_activities_bulk_delete', $activity_ids, $deleted_count );

		return $deleted_count;
	}

	/**
	 * Add comment to activity
	 *
	 * @since 1.0.0
	 *
	 * @param int      $activity_id Activity ID.
	 * @param string   $content Comment content.
	 * @param int|null $user_id User ID.
	 *
	 * @return ActivityCommentModel|null
	 */
	public function add_comment( $activity_id, $content, $user_id = null ) {
		$activity = ActivityModel::withMorphAppends()->find( $activity_id );

		if ( ! $activity ) {
			return null;
		}

		// Check deal permissions if activity is associated with a deal.
		if ( $activity->deal_id && ! $this->can_access_deal( $activity->deal_id ) ) {
			return null;
		}

		// Check task permissions if activity is associated with a task.
		if ( $activity->task_id && ! $this->can_access_task( $activity->task_id ) ) {
			return null;
		}

		if ( empty( $content ) ) {
			return null;
		}

		$comment = ActivityCommentModel::create(
			array(
				'activity_id' => $activity_id,
				'content'     => wp_kses_post( $content ),
				'user_id'     => $user_id ?: get_current_user_id(),
			)
		);

		do_action( 'doublescale_activity_comment_add', $comment, $activity );

		return $comment;
	}

	/**
	 * Update comment
	 *
	 * @since 1.0.0
	 *
	 * @param int      $comment_id Comment ID.
	 * @param string   $content New content.
	 * @param int|null $user_id User performing the action.
	 *
	 * @return ActivityCommentModel|null
	 */
	public function update_comment( $comment_id, $content, $user_id = null ) {
		$comment = ActivityCommentModel::with(
			array(
				'activity' => function ( $query ) {
					$query->withMorphAppends();
				},
			)
		)->find( $comment_id );

		if ( ! $comment ) {
			return null;
		}

		$activity = $comment->activity;
		if ( $activity ) {
			if ( $activity->deal_id && ! $this->can_access_deal( $activity->deal_id ) ) {
				return null;
			}
			if ( $activity->task_id && ! $this->can_access_task( $activity->task_id ) ) {
				return null;
			}
		}

		// Check if user can edit this comment.
		if ( $user_id && $comment->user_id !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		$comment->content = wp_kses_post( $content );
		$comment->save();

		do_action( 'doublescale_activity_comment_update', $comment );

		return $comment;
	}

	/**
	 * Delete comment
	 *
	 * @since 1.0.0
	 *
	 * @param int      $comment_id Comment ID.
	 * @param int|null $user_id User performing the action.
	 *
	 * @return bool
	 */
	public function delete_comment( $comment_id, $user_id = null ) {
		$comment = ActivityCommentModel::with(
			array(
				'activity' => function ( $query ) {
					$query->withMorphAppends();
				},
			)
		)->find( $comment_id );

		if ( ! $comment ) {
			return false;
		}

		$activity = $comment->activity;
		if ( $activity ) {
			if ( $activity->deal_id && ! $this->can_access_deal( $activity->deal_id ) ) {
				return false;
			}
			if ( $activity->task_id && ! $this->can_access_task( $activity->task_id ) ) {
				return false;
			}
		}

		// Check if user can delete this comment.
		if ( $user_id && $comment->user_id !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		do_action( 'doublescale_activity_comment_pre_delete', $comment );

		$deleted = $comment->delete();

		if ( $deleted ) {
			do_action( 'doublescale_activity_comment_delete', $comment_id );
		}

		return $deleted;
	}

	/**
	 * Get activity statistics
	 *
	 * @since 1.0.0
	 *
	 * @param array $filters Filter criteria.
	 *
	 * @return array
	 */
	public function get_activity_statistics( $filters = array() ) {
		$query = ActivityModel::query();

		// Filter by contact via activity_associations (polymorphic).
		if ( ! empty( $filters['contact_id'] ) ) {
			$query->whereHas(
				'associations',
				function ( $q ) use ( $filters ) {
					$q->where( 'entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_CONTACT )
						->where( 'entity_id', $filters['contact_id'] );
				}
			);
		}

		// Filter by deal using activity_associations table.
		if ( ! empty( $filters['entity_id'] ) && ! empty( $filters['entity_type'] ) && $filters['entity_type'] === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL ) {
			if ( class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
				$query->whereHas(
					'associations',
					function ( $q ) use ( $filters ) {
						$q->where( 'entity_type', $filters['entity_type'] )
							->where( 'entity_id', $filters['entity_id'] );
					}
				);
			}
		}

		// Filter by user.
		if ( ! empty( $filters['user_id'] ) ) {
			$query->where( 'user_id', $filters['user_id'] );
		}

		// Filter by date range using the indexed activity_date column.
		// Use range comparisons (>=, <) instead of DATE() to preserve index usage.
		if ( ! empty( $filters['date_from'] ) ) {
			$query->where( 'activity_date', '>=', $filters['date_from'] . ' 00:00:00' );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$query->where( 'activity_date', '<', gmdate( 'Y-m-d', strtotime( $filters['date_to'] . ' +1 day' ) ) . ' 00:00:00' );
		}

		$activities = $query->get();

		$stats = array(
			'total_activities' => $activities->count(),
			'notes'            => $activities->where( 'activity_type', 'note' )->count(),
			'emails'           => $activities->where( 'activity_type', 'email_sent' )->count(),
			'calls'            => $activities->where( 'activity_type', 'call_logged' )->count(),
			'meetings'         => $activities->where( 'activity_type', 'meeting_scheduled' )->count(),
			'stage_changes'    => $activities->where( 'activity_type', 'stage_changed' )->count(),
			'value_changes'    => $activities->where( 'activity_type', 'value_changed' )->count(),
			'status_changes'   => $activities->where( 'activity_type', 'status_changed' )->count(),
		);

		// Add activity breakdown by type.
		$stats['by_type'] = array();
		foreach ( $activities->groupBy( 'activity_type' ) as $type => $type_activities ) {
			$stats['by_type'][ $type ] = $type_activities->count();
		}

		return $stats;
	}

	/**
	 * Check if current user can access a deal.
	 *
	 * Returns a string status: 'ok', 'not_found', 'no_pro', or 'forbidden'.
	 *
	 * @since 1.0.0
	 *
	 * @param int $deal_id Deal ID.
	 *
	 * @return string 'ok' if accessible, error code otherwise.
	 */
	private function check_deal_access( $deal_id ): string {
		$deal_class = doublescale_resolve_deal_model_class();
		if ( ! $deal_class ) {
			return 'no_pro';
		}

		$deal = $deal_class::find( $deal_id );

		if ( ! $deal ) {
			return 'not_found';
		}

		if ( Permissions::is_sales_rep() && $deal->owner_id !== get_current_user_id() ) {
			return 'forbidden';
		}

		return 'ok';
	}

	/**
	 * Check if current user can access a deal (boolean shortcut).
	 *
	 * @since 1.0.0
	 *
	 * @param int $deal_id Deal ID.
	 *
	 * @return bool
	 */
	private function can_access_deal( $deal_id ) {
		return 'ok' === $this->check_deal_access( $deal_id );
	}

	/**
	 * Check if current user can access a task (sales reps only see their own).
	 *
	 * @since 1.0.0
	 *
	 * @param int $task_id Task ID.
	 *
	 * @return string 'ok', 'not_found', or 'forbidden'.
	 */
	private function check_task_access( $task_id ) {
		if ( ! class_exists( '\DoubleScale\Pro\Modules\Tasks\Models\TaskModel' ) ) {
			return 'not_found';
		}

		$task = \DoubleScale\Pro\Modules\Tasks\Models\TaskModel::find( $task_id );
		if ( ! $task ) {
			return 'not_found';
		}

		if ( Permissions::is_sales_rep() && ! \DoubleScale\Pro\Modules\Tasks\Models\TaskModel::salesRepCanView( $task ) ) {
			return 'forbidden';
		}

		return 'ok';
	}

	/**
	 * Check if current user can access a task (boolean shortcut).
	 *
	 * @since 1.0.0
	 *
	 * @param int $task_id Task ID.
	 *
	 * @return bool
	 */
	private function can_access_task( $task_id ) {
		return 'ok' === $this->check_task_access( $task_id );
	}

	/**
	 * Check if current user can access a project.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $project_id     Project ID.
	 * @param bool $require_manage When true, require manage access.
	 *
	 * @return string 'ok', 'not_found', 'no_pro', or 'forbidden'.
	 */
	private function check_project_access( $project_id, $require_manage = false ): string {
		if ( ! class_exists( '\DoubleScale\Pro\Modules\Projects\Capabilities' ) ) {
			return 'no_pro';
		}

		if (
			! class_exists( '\DoubleScale\Pro\Modules\Projects\Models\ProjectModel' )
			|| (
				function_exists( 'doublescale_is_module_storage_ready' )
				&& ! doublescale_is_module_storage_ready( 'projects', '\DoubleScale\Pro\Modules\Projects\Models\ProjectModel' )
			)
		) {
			return 'no_pro';
		}

		try {
			$project = \DoubleScale\Pro\Modules\Projects\Models\ProjectModel::find( $project_id );
		} catch ( \Throwable $e ) {
			return 'no_pro';
		}
		if ( ! $project ) {
			return 'not_found';
		}

		$can = $require_manage
			? \DoubleScale\Pro\Modules\Projects\Capabilities::can_manage_project( $project_id )
			: \DoubleScale\Pro\Modules\Projects\Capabilities::can_read_project( $project_id );

		return $can ? 'ok' : 'forbidden';
	}

	/**
	 * Check if current user can read a project (boolean shortcut).
	 *
	 * @since 1.0.0
	 *
	 * @param int $project_id Project ID.
	 *
	 * @return bool
	 */
	private function can_access_project( $project_id ) {
		return 'ok' === $this->check_project_access( $project_id, false );
	}

	/**
	 * Check if current user can manage a project (boolean shortcut).
	 *
	 * @since 1.0.0
	 *
	 * @param int $project_id Project ID.
	 *
	 * @return bool
	 */
	private function can_manage_project( $project_id ) {
		return 'ok' === $this->check_project_access( $project_id, true );
	}

	/**
	 * Resolve the project ID associated with an activity, if any.
	 *
	 * @since 1.0.0
	 *
	 * @param ActivityModel $activity Activity model.
	 *
	 * @return int|null
	 */
	private function get_project_id_from_activity( ActivityModel $activity ): ?int {
		if ( $activity->relationLoaded( 'associations' ) ) {
			foreach ( $activity->associations as $association ) {
				if ( (int) $association->entity_type === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT ) {
					return (int) $association->entity_id;
				}
			}

			return null;
		}

		if ( ! class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
			return null;
		}

		$entity_id = \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::query()
			->where( 'activity_id', $activity->id )
			->where( 'entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT )
			->value( 'entity_id' );

		return null !== $entity_id ? (int) $entity_id : null;
	}

	/**
	 * Get contact_id from deal
	 *
	 * @since 1.0.0
	 *
	 * @param int $deal_id Deal ID.
	 *
	 * @return int|null
	 */
	private function get_contact_id_from_deal( $deal_id ) {
		$deal_class = doublescale_resolve_deal_model_class();
		if ( ! $deal_class ) {
			return null;
		}

		$deal = $deal_class::find( $deal_id );
		return $deal ? $deal->contact_id : null;
	}

	/**
	 * Get deal with contact information
	 *
	 * @since 1.0.0
	 *
	 * @param int $deal_id Deal ID.
	 *
	 * @return array
	 */
	private function get_deal_with_contact( $deal_id ) {
		$deal_class = doublescale_resolve_deal_model_class();
		if ( ! $deal_class ) {
			return array();
		}

		$deal = $deal_class::with( 'contact' )->find( $deal_id );

		if ( ! $deal ) {
			return array();
		}

		$result = array(
			'contact_id' => $deal->contact_id,
		);

		if ( $deal->contact ) {
			$result['contact'] = array(
				'id'    => $deal->contact->id,
				'email' => $deal->contact->email,
				'name'  => trim( $deal->contact->first_name . ' ' . $deal->contact->last_name ),
			);
		}

		return $result;
	}

	/**
	 * Get unified timeline combining activities and tasks
	 *
	 * Uses raw SQL UNION query for optimal performance when combining
	 * data from multiple tables. Always includes all activities, and includes
	 * tasks automatically when Pro is active.
	 *
	 * Supported filters:
	 * - contact_id: Filter by contact
	 * - entity_id + entity_type: Filter by associated entity (deal, campaign)
	 * - user_id: Filter by creator (activities) or assignee (tasks)
	 * - date_from, date_to: Date range filter
	 * - sort_by, sort_order: Sorting options
	 *
	 * @since 1.0.0
	 *
	 * @param array $filters  Filter criteria.
	 * @param int   $per_page Results per page.
	 * @param int   $page     Page number.
	 *
	 * @return array Array with 'data' and 'meta' keys.
	 */
	public function get_unified_timeline(
		array $filters,
		int $per_page = 20,
		int $page = 1
	): array {
		// Sanitize pagination parameters to prevent division by zero and invalid values.
		$per_page = max( 1, $per_page );
		$page     = max( 1, $page );

		$pro_active = doublescale_pro_task_model_available();
		global $wpdb;

		// Permission check for deal access.
		if ( ! empty( $filters['entity_type'] ) &&
			$filters['entity_type'] == \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL ) {
			$deal_access = $this->check_deal_access( $filters['entity_id'] );
			if ( 'ok' !== $deal_access ) {
				return array(
					'data' => array(),
					'meta' => array(
						'total'        => 0,
						'per_page'     => $per_page,
						'current_page' => $page,
						'total_pages'  => 0,
						'pro_active'   => $pro_active,
						'error'        => $deal_access,
					),
				);
			}
		}

		// Permission check for project access.
		if ( ! empty( $filters['entity_type'] ) &&
			(int) $filters['entity_type'] === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT ) {
			$project_access = $this->check_project_access( $filters['entity_id'], false );
			if ( 'ok' !== $project_access ) {
				return array(
					'data' => array(),
					'meta' => array(
						'total'        => 0,
						'per_page'     => $per_page,
						'current_page' => $page,
						'total_pages'  => 0,
						'pro_active'   => $pro_active,
						'error'        => $project_access,
					),
				);
			}
		}

		$select_queries = array();
		$count_queries  = array();

		// Build activities query.
		$activities_sql = $this->build_activities_union_sql( $filters, $wpdb );
		if ( $activities_sql ) {
			$select_queries[] = $activities_sql['select'];
			$count_queries[]  = $activities_sql['count'];
		}

		// Include tasks when Pro is active AND the Tasks module is enabled.
		// Class existence proves Pro loaded; the option toggle proves the admin
		// hasn't disabled the module (table persists across module disables).
		// Note: activity_type filtering is handled by get_activities(), not here.
		// This method always returns all activities + tasks (when Pro active).
		if ( $pro_active && ( ! function_exists( 'doublescale_is_module_active' ) || doublescale_is_module_active( 'tasks' ) ) ) {
			$tasks_sql = $this->build_tasks_union_sql( $filters, $wpdb );
			if ( $tasks_sql ) {
				$select_queries[] = $tasks_sql['select'];
				$count_queries[]  = $tasks_sql['count'];
			}
		}

		// Include tracking events (opens/clicks) and page visits when viewing
		// a contact timeline without deal/user filters.
		if ( ! function_exists( 'doublescale_is_module_active' ) || doublescale_is_module_active( 'tracking' ) ) {
			$tracking_sqls = $this->build_tracking_union_sqls( $filters, $wpdb );
			if ( $tracking_sqls ) {
				foreach ( $tracking_sqls as $sql ) {
					$select_queries[] = $sql['select'];
					$count_queries[]  = $sql['count'];
				}
			}
		}

		// Page visits table is owned by Pro's WebsiteTracking module — without
		// this gate, a Free-standalone install hits "Table doesn't exist".
		if ( ! function_exists( 'doublescale_is_module_active' ) || doublescale_is_module_active( 'websitetracking' ) ) {
			$page_visits_sql = $this->build_page_visits_union_sql( $filters, $wpdb );
			if ( $page_visits_sql ) {
				$select_queries[] = $page_visits_sql['select'];
				$count_queries[]  = $page_visits_sql['count'];
			}
		}

		// If no queries, return empty.
		if ( empty( $select_queries ) ) {
			return array(
				'data' => array(),
				'meta' => array(
					'total'        => 0,
					'per_page'     => $per_page,
					'current_page' => $page,
					'total_pages'  => 0,
					'pro_active'   => $pro_active,
				),
			);
		}

		// Get total count.
		$total = 0;
		foreach ( $count_queries as $count_sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is built with prepared statements internally.
			$count_result = $wpdb->get_var( $count_sql );
			$total       += intval( $count_result );
		}

		// Build final UNION query with sorting and pagination.
		$offset     = ( $page - 1 ) * $per_page;
		$sort_by    = $this->sanitize_sort_field( $filters['sort_by'] ?? 'activity_date' );
		$sort_order = strtoupper( $filters['sort_order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$union_sql = '(' . implode( ') UNION ALL (', $select_queries ) . ')';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $union_sql is built from prepared SELECTs above; $sort_by is whitelisted by sanitize_sort_field(); $sort_order is gated to 'ASC'|'DESC' just above.
		$final_sql = $wpdb->prepare(
			"SELECT * FROM ({$union_sql}) AS combined
			ORDER BY {$sort_by} {$sort_order}
			LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above with $wpdb->prepare().
		$results = $wpdb->get_results( $final_sql );

		// Batch load users.
		$user_ids = array_unique( array_filter( wp_list_pluck( $results, 'user_id' ) ) );
		$users    = $this->batch_load_users( $user_ids );

		// Transform results.
		$data = array();
		foreach ( $results as $row ) {
			$user = $users[ $row->user_id ] ?? null;

			if ( $row->item_type === 'tracking' ) {
				$data[] = $this->transform_tracking_row( $row );
			} elseif ( $row->item_type === 'activity' ) {
				$data[] = $this->transform_activity_row( $row, $user );
			} else {
				$data[] = $this->transform_task_row( $row, $user );
			}
		}

		return array(
			'data' => $data,
			'meta' => array(
				'total'        => $total,
				'per_page'     => $per_page,
				'current_page' => $page,
				'total_pages'  => (int) ceil( $total / $per_page ),
				'pro_active'   => $pro_active,
			),
		);
	}

	/**
	 * Build activities SQL for UNION query
	 *
	 * @since 1.0.0
	 *
	 * @param array $filters Filters.
	 * @param wpdb  $wpdb    WordPress database object.
	 *
	 * @return array|null SQL queries.
	 */
	private function build_activities_union_sql( array $filters, $wpdb ): ?array {
		$activities_table   = $wpdb->prefix . 'doublescale_activities';
		$associations_table = $wpdb->prefix . 'doublescale_activity_associations';
		$comments_table     = $wpdb->prefix . 'doublescale_activity_comments';

		$where_clauses = array( '1=1' );
		$join_clauses  = array();

		// Contact filter via activity_associations (polymorphic).
		if ( ! empty( $filters['contact_id'] ) ) {
			$contact_type    = \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_CONTACT;
			$where_clauses[] = $wpdb->prepare(
				"EXISTS (
					SELECT 1 FROM {$associations_table} contact_assoc
					WHERE contact_assoc.activity_id = a.id
					AND contact_assoc.entity_type = %d
					AND contact_assoc.entity_id = %d
				)",
				$contact_type,
				$filters['contact_id']
			);
		}

		// Deal/Entity filter (via associations).
		if ( ! empty( $filters['entity_type'] ) && ! empty( $filters['entity_id'] ) ) {
			$join_clauses[]  = "INNER JOIN {$associations_table} aa ON a.id = aa.activity_id";
			$where_clauses[] = $wpdb->prepare(
				'aa.entity_type = %d AND aa.entity_id = %d',
				$filters['entity_type'],
				$filters['entity_id']
			);
		}

		// Exclude deal- and task-scoped activities when no entity_type is provided.
		// Task audit rows (task_event) belong on the task activity feed, not contact/deal timelines.
		if ( empty( $filters['entity_type'] ) ) {
			$deal_type = \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL;
			$task_type     = \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_TASK;
			$project_type  = \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT;
			$where_clauses[] = "NOT EXISTS (
				SELECT 1 FROM {$associations_table} excl
				WHERE excl.activity_id = a.id AND excl.entity_type IN ({$deal_type}, {$task_type}, {$project_type})
			)";
		}

		// User filter.
		if ( ! empty( $filters['user_id'] ) ) {
			$where_clauses[] = $wpdb->prepare( 'a.user_id = %d', $filters['user_id'] );
		}

		// Date filters using the indexed activity_date column.
		// Use range comparisons (>=, <) instead of DATE() to preserve index usage.
		if ( ! empty( $filters['date_from'] ) ) {
			$where_clauses[] = $wpdb->prepare(
				'a.activity_date >= %s',
				$filters['date_from'] . ' 00:00:00'
			);
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where_clauses[] = $wpdb->prepare(
				'a.activity_date < %s',
				gmdate( 'Y-m-d', strtotime( $filters['date_to'] . ' +1 day' ) ) . ' 00:00:00'
			);
		}

		// Task audit rows only belong on the task activity feed.
		$task_entity_type = \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_TASK;
		if (
			empty( $filters['entity_type'] ) ||
			(int) $filters['entity_type'] !== $task_entity_type
		) {
			$where_clauses[] = $wpdb->prepare(
				'a.activity_type != %s',
				ActivityTypes::TASK_EVENT
			);
		}

		// Project audit rows only belong on the project activity feed.
		$project_entity_type = \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROJECT;
		if (
			empty( $filters['entity_type'] ) ||
			(int) $filters['entity_type'] !== $project_entity_type
		) {
			$where_clauses[] = $wpdb->prepare(
				'a.activity_type != %s',
				ActivityTypes::PROJECT_EVENT
			);
		}

		// Project deal_id / contact_id via scalar subqueries — a LEFT JOIN would fan out
		// when an activity has multiple associations of the same entity_type.
		$deal_entity_type    = \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL;
		$contact_entity_type = \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_CONTACT;

		$where_sql = implode( ' AND ', $where_clauses );
		$joins_sql = implode( ' ', $join_clauses );

		// Select query - normalized columns for UNION.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$select_sql = "SELECT
			a.id,
			'activity' as item_type,
			a.activity_type,
			NULL as task_type,
			(SELECT MIN(c.entity_id) FROM {$associations_table} c WHERE c.activity_id = a.id AND c.entity_type = {$contact_entity_type}) as contact_id,
			(SELECT MIN(d.entity_id) FROM {$associations_table} d WHERE d.activity_id = a.id AND d.entity_type = {$deal_entity_type}) as deal_id,
			a.data,
			a.user_id,
			a.created_at,
			a.updated_at,
			a.activity_date,
			NULL as title,
			NULL as description,
			NULL as status,
			CASE
			WHEN a.activity_type IN ('meeting_scheduled', 'call_logged') THEN
				CASE
					WHEN DATE(a.activity_date) < CURDATE() THEN 'completed'
					WHEN DATE(a.activity_date) = CURDATE() THEN 'due_today'
					ELSE 'upcoming'
				END
			ELSE NULL
		END as display_status,
			NULL as priority,
			NULL as due_date,
			NULL as due_time,
			NULL as is_overdue,
			(SELECT COUNT(*) FROM {$comments_table} c WHERE c.activity_id = a.id) as comments_count
		FROM {$activities_table} a
		{$joins_sql}
		WHERE {$where_sql}";

		// Count query - only need the filter joins, not the deal_id lookup join.
		$filter_joins = array();
		if ( ! empty( $filters['entity_type'] ) && ! empty( $filters['entity_id'] ) ) {
			$filter_joins[] = "INNER JOIN {$associations_table} aa ON a.id = aa.activity_id";
		}
		$filter_joins_sql = implode( ' ', $filter_joins );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$activities_table} a {$filter_joins_sql} WHERE {$where_sql}";

		return array(
			'select' => $select_sql,
			'count'  => $count_sql,
		);
	}

	/**
	 * Build tasks SQL for UNION query
	 *
	 * @since 1.0.0
	 *
	 * @param array $filters Filters.
	 * @param wpdb  $wpdb    WordPress database object.
	 *
	 * @return array|null SQL queries.
	 */
	private function build_tasks_union_sql( array $filters, $wpdb ): ?array {
		$tasks_table = $wpdb->prefix . 'doublescale_tasks';

		$where_clauses = array( '1=1' );

		// Contact filter.
		if ( ! empty( $filters['contact_id'] ) ) {
			$where_clauses[] = $wpdb->prepare(
				't.entity_type = %d AND t.entity_id = %d',
				\DoubleScale\Core\Constants\TaskEntityType::CONTACT,
				$filters['contact_id']
			);
		}

		// Deal filter.
		if ( ! empty( $filters['entity_type'] ) &&
			$filters['entity_type'] == \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL &&
			! empty( $filters['entity_id'] ) ) {
			$where_clauses[] = $wpdb->prepare(
				't.entity_type = %d AND t.entity_id = %d',
				\DoubleScale\Core\Constants\TaskEntityType::DEAL,
				$filters['entity_id']
			);
		}

		// User filter (assigned_to for tasks).
		if ( ! empty( $filters['user_id'] ) ) {
			$where_clauses[] = $wpdb->prepare( 't.assigned_to = %d', $filters['user_id'] );
		}

		// Date filters - use due_date for tasks (more relevant for "upcoming" filtering).
		// Tasks without due_date are excluded from date filtering.
		if ( ! empty( $filters['date_from'] ) ) {
			$where_clauses[] = $wpdb->prepare( 't.due_date IS NOT NULL AND DATE(t.due_date) >= %s', $filters['date_from'] );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where_clauses[] = $wpdb->prepare( 't.due_date IS NOT NULL AND DATE(t.due_date) <= %s', $filters['date_to'] );
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Get entity type constants.
		$contact_entity_type = \DoubleScale\Core\Constants\TaskEntityType::CONTACT;
		$deal_entity_type    = \DoubleScale\Core\Constants\TaskEntityType::DEAL;

		// Select query - normalized columns for UNION.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$select_sql = "SELECT
			t.id,
			'task' as item_type,
			NULL as activity_type,
			t.task_type,
			CASE
				WHEN t.entity_type = {$contact_entity_type} THEN t.entity_id
				ELSE NULL
			END as contact_id,
			CASE
				WHEN t.entity_type = {$deal_entity_type} THEN t.entity_id
				ELSE NULL
			END as deal_id,
			NULL as data,
			t.assigned_to as user_id,
			t.created_at,
			t.updated_at,
			t.created_at as activity_date,
			t.title,
			t.description,
			t.status,
			CASE
				WHEN t.status = 'completed' THEN 'completed'
				WHEN t.due_date < CURDATE() THEN 'overdue'
				WHEN t.due_date = CURDATE() THEN 'due_today'
				ELSE 'upcoming'
			END as display_status,
			t.priority,
			t.due_date,
			t.due_time,
			CASE WHEN t.status = 'pending' AND t.due_date < CURDATE() THEN 1 ELSE 0 END as is_overdue,
			0 as comments_count
		FROM {$tasks_table} t
		WHERE {$where_sql}";

		// Count query.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$tasks_table} t WHERE {$where_sql}";

		return array(
			'select' => $select_sql,
			'count'  => $count_sql,
		);
	}

	/**
	 * Batch load users by IDs
	 *
	 * @since 1.0.0
	 *
	 * @param array $user_ids User IDs.
	 *
	 * @return array Users array.
	 */
	private function batch_load_users( array $user_ids ): array {
		if ( empty( $user_ids ) ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a dynamically built '%d,%d…' string bound via spread; $wpdb->users is a trusted core table reference.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, display_name, user_email FROM {$wpdb->users} WHERE ID IN ({$placeholders})",
				...$user_ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$users = array();
		foreach ( $results as $user ) {
			$users[ $user->ID ] = array(
				'id'           => (int) $user->ID,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
			);
		}

		return $users;
	}

	/**
	 * Whether an activity row is editable in the API response.
	 *
	 * email_sent is only editable when it was manually logged, not when sent via CRM.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $activity_type  Activity type slug.
	 * @param array<string, mixed> $data           Activity data payload.
	 * @param array<int, string>   $editable_types Editable type list from ActivityTypes.
	 *
	 * @return bool
	 */
	private function is_activity_editable( string $activity_type, array $data, array $editable_types ): bool {
		if ( ActivityTypes::EMAIL_SENT === $activity_type ) {
			return ActivityModel::is_manual_email_log( $data );
		}

		return in_array( $activity_type, $editable_types, true );
	}

	/**
	 * Transform activity row from SQL result
	 *
	 * @since 1.0.0
	 *
	 * @param object     $row  Activity row.
	 * @param array|null $user User data.
	 *
	 * @return array Transformed activity.
	 */
	private function transform_activity_row( $row, ?array $user ): array {
		$data = ! empty( $row->data ) ? json_decode( $row->data, true ) : array();

		$editable_types = ActivityTypes::get_editable_types();
		$system_types   = ActivityTypes::get_system_types();

		return array(
			'id'                => (int) $row->id,
			'item_type'         => 'activity',
			'activity_type'     => $row->activity_type,
			'contact_id'        => $row->contact_id ? (int) $row->contact_id : null,
			'deal_id'           => ! empty( $row->deal_id ) ? (int) $row->deal_id : null,
			'data'              => $data,
			'user_id'           => $row->user_id ? (int) $row->user_id : null,
			'user'              => $user,
			'formatted_message' => $this->format_activity_message( $row->activity_type, $user, $data ),
			'is_editable'       => $this->is_activity_editable( $row->activity_type, $data, $editable_types ),
			'is_system'         => in_array( $row->activity_type, $system_types, true ),
			'display_status'    => $row->display_status ?? null,
			'comments_count'    => (int) $row->comments_count,
			'activity_date'     => $row->activity_date,
			'created_at'        => $row->created_at,
			'updated_at'        => $row->updated_at,
		);
	}

	/**
	 * Transform task row from SQL result
	 *
	 * @since 1.0.0
	 *
	 * @param object     $row  Task row.
	 * @param array|null $user User data.
	 *
	 * @return array Transformed task.
	 */
	private function transform_task_row( $row, ?array $user ): array {
		return array(
			'id'             => (int) $row->id,
			'item_type'      => 'task',
			'task_type'      => $row->task_type,
			'contact_id'     => $row->contact_id ? (int) $row->contact_id : null,
			'deal_id'        => ! empty( $row->deal_id ) ? (int) $row->deal_id : null,
			'title'          => $row->title,
			'description'    => $row->description,
			'user_id'        => $row->user_id ? (int) $row->user_id : null,
			'user'           => $user,
			'status'         => $row->status,
			'display_status' => $row->display_status,
			'priority'       => $row->priority,
			'due_date'       => $row->due_date,
			'due_time'       => $row->due_time,
			'is_overdue'     => (bool) $row->is_overdue,
			'created_at'     => $row->created_at,
			'updated_at'     => $row->updated_at,
		);
	}

	/**
	 * Format activity message
	 *
	 * @since 1.0.0
	 *
	 * @param string     $type Activity type.
	 * @param array|null $user User data.
	 * @param array      $data Activity data.
	 *
	 * @return string Formatted message.
	 */
	private function format_activity_message( string $type, ?array $user, array $data ): string {
		$user_name = $user ? $user['display_name'] : null;

		if ( ActivityTypes::EMAIL_RECEIVED === $type ) {
			$from_email = $data['from_email'] ?? '';
			$subject    = $data['subject'] ?? '';
			$sender     = ! empty( $from_email ) ? $from_email : __( 'a contact', 'doublescale' );
			/* translators: %s: sender name or email */
			$message = sprintf( __( '%s sent a reply', 'doublescale' ), $sender );
			if ( ! empty( $subject ) ) {
				/* translators: %s: email subject line */
				$message .= sprintf( __( ' with subject "%s"', 'doublescale' ), $subject );
			}
			return $message;
		}

		if ( ActivityTypes::EMAIL_SENT === $type ) {
			if ( null === $user_name ) {
				$from_email = $data['from_email'] ?? '';
				$user_name  = ! empty( $from_email ) ? $from_email : __( 'Unknown User', 'doublescale' );
			}
			$subject   = $data['subject'] ?? '';
			$is_manual = ActivityModel::is_manual_email_log( $data );
			$message   = $is_manual
				? sprintf(
					/* translators: %s: user name */
					__( '%s logged an email', 'doublescale' ),
					$user_name
				)
				: sprintf(
					/* translators: %s: user name */
					__( '%s sent an email', 'doublescale' ),
					$user_name
				);
			if ( ! empty( $subject ) ) {
				/* translators: %s: email subject line */
				$message .= sprintf( __( ' with subject "%s"', 'doublescale' ), $subject );
			}
			$contact_email = $data['contact_email'] ?? '';
			$contact_name  = $data['contact_name'] ?? '';
			if ( ! empty( $contact_email ) ) {
				$recipient = ! empty( $contact_name ) ? $contact_name : $contact_email;
				/* translators: %s: recipient name or email */
				$message .= sprintf( __( ' to %s', 'doublescale' ), $recipient );
			}
			return $message;
		}

		if ( ActivityTypes::FILE_ATTACHED === $type || ActivityTypes::FILE_REMOVED === $type ) {
			$file_name = isset( $data['file_name'] ) ? (string) $data['file_name'] : '';
			$base      = ActivityTypes::get_activity_message( $type, $user_name );
			if ( '' !== $file_name ) {
				/* translators: 1: activity message, 2: file name */
				return sprintf( __( '%1$s: %2$s', 'doublescale' ), $base, $file_name );
			}
			return $base;
		}

		if ( ActivityTypes::STATUS_CHANGED === $type ) {
			$actor = $user_name ?? __( 'Someone', 'doublescale' );
			if ( ! empty( $data['proposal_id'] ) ) {
				$number = ! empty( $data['proposal_number'] )
					? (string) $data['proposal_number']
					: '#' . (int) $data['proposal_id'];
				return sprintf(
					/* translators: 1: user name, 2: proposal number */
					__( '%1$s linked proposal %2$s to this deal', 'doublescale' ),
					$actor,
					$number
				);
			}
			if ( ! empty( $data['invoice_id'] ) ) {
				$number = ! empty( $data['invoice_number'] )
					? (string) $data['invoice_number']
					: '#' . (int) $data['invoice_id'];
				return sprintf(
					/* translators: 1: user name, 2: invoice number */
					__( '%1$s linked invoice %2$s to this deal', 'doublescale' ),
					$actor,
					$number
				);
			}
		}

		return ActivityTypes::get_activity_message( $type, $user_name );
	}

	/**
	 * Build tracking UNION SQLs for email opens and link clicks.
	 *
	 * Returns two query pairs (opens + clicks) from the communication_tracking table,
	 * joining campaigns for context. Only included when viewing a contact's timeline
	 * without deal/user filters.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $filters Filters.
	 * @param object $wpdb    WordPress database object.
	 *
	 * @return array|null Array of [ [ 'select' => ..., 'count' => ... ], ... ] or null.
	 */
	private function build_tracking_union_sqls( array $filters, $wpdb ): ?array {
		if ( empty( $filters['contact_id'] ) || ! empty( $filters['entity_type'] ) || ! empty( $filters['user_id'] ) ) {
			return null;
		}

		$tracking_table  = $wpdb->prefix . 'doublescale_communication_tracking';
		$campaigns_table = $wpdb->prefix . 'doublescale_campaigns';
		$contacts_table  = $wpdb->prefix . 'doublescale_contacts';
		$contact_where   = $wpdb->prepare( 'ct.contact_id = %d', $filters['contact_id'] );

		$queries = array();

		// --- Opens (email only, mode=1) ---
		$opens_where = array( $contact_where, 'ct.opened = 1', 'ct.mode = 1' );
		if ( ! empty( $filters['date_from'] ) ) {
			$opens_where[] = $wpdb->prepare( 'ct.opened_at >= %s', $filters['date_from'] . ' 00:00:00' );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$opens_where[] = $wpdb->prepare(
				'ct.opened_at < %s',
				gmdate( 'Y-m-d', strtotime( $filters['date_to'] . ' +1 day' ) ) . ' 00:00:00'
			);
		}
		$opens_where_sql = implode( ' AND ', $opens_where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$queries[] = array(
			'select' => "SELECT
				ct.id,
				'tracking' as item_type,
				'email_opened' as activity_type,
				NULL as task_type,
				ct.contact_id,
				NULL as deal_id,
				JSON_OBJECT(
					'tracking_id', ct.id,
					'source_type', ct.source_type,
					'source_id', ct.source_id,
					'campaign_name', COALESCE(camp.name, ''),
					'recipient', ct.recipient,
					'opened_at', ct.opened_at,
					'contact_name', TRIM(CONCAT(COALESCE(con.first_name, ''), ' ', COALESCE(con.last_name, '')))
				) as data,
				NULL as user_id,
				ct.created_at,
				ct.updated_at,
				ct.opened_at as activity_date,
				NULL as title,
				NULL as description,
				NULL as status,
				NULL as display_status,
				NULL as priority,
				NULL as due_date,
				NULL as due_time,
				NULL as is_overdue,
				0 as comments_count
			FROM {$tracking_table} ct
			LEFT JOIN {$campaigns_table} camp ON ct.source_type = 1 AND ct.source_id = camp.id
			LEFT JOIN {$contacts_table} con ON ct.contact_id = con.id
			WHERE {$opens_where_sql}",
			'count'  => "SELECT COUNT(*) FROM {$tracking_table} ct WHERE {$opens_where_sql}",
		);

		// --- Clicks (all modes) ---
		$clicks_where = array( $contact_where, 'ct.clicked = 1' );
		if ( ! empty( $filters['date_from'] ) ) {
			$clicks_where[] = $wpdb->prepare( 'ct.clicked_at >= %s', $filters['date_from'] . ' 00:00:00' );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$clicks_where[] = $wpdb->prepare(
				'ct.clicked_at < %s',
				gmdate( 'Y-m-d', strtotime( $filters['date_to'] . ' +1 day' ) ) . ' 00:00:00'
			);
		}
		$clicks_where_sql = implode( ' AND ', $clicks_where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$queries[] = array(
			'select' => "SELECT
				ct.id,
				'tracking' as item_type,
				CASE ct.mode
					WHEN 1 THEN 'email_clicked'
					WHEN 2 THEN 'sms_clicked'
					WHEN 3 THEN 'whatsapp_clicked'
				END as activity_type,
				NULL as task_type,
				ct.contact_id,
				NULL as deal_id,
				JSON_OBJECT(
					'tracking_id', ct.id,
					'source_type', ct.source_type,
					'source_id', ct.source_id,
					'campaign_name', COALESCE(camp.name, ''),
					'recipient', ct.recipient,
					'clicked_at', ct.clicked_at,
					'contact_name', TRIM(CONCAT(COALESCE(con.first_name, ''), ' ', COALESCE(con.last_name, '')))
				) as data,
				NULL as user_id,
				ct.created_at,
				ct.updated_at,
				ct.clicked_at as activity_date,
				NULL as title,
				NULL as description,
				NULL as status,
				NULL as display_status,
				NULL as priority,
				NULL as due_date,
				NULL as due_time,
				NULL as is_overdue,
				0 as comments_count
			FROM {$tracking_table} ct
			LEFT JOIN {$campaigns_table} camp ON ct.source_type = 1 AND ct.source_id = camp.id
			LEFT JOIN {$contacts_table} con ON ct.contact_id = con.id
			WHERE {$clicks_where_sql}",
			'count'  => "SELECT COUNT(*) FROM {$tracking_table} ct WHERE {$clicks_where_sql}",
		);

		return $queries;
	}

	/**
	 * Build page visits UNION SQL.
	 *
	 * Returns a query pair from the page_visits table. Only included when
	 * viewing a contact's timeline without deal/user filters.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $filters Filters.
	 * @param object $wpdb    WordPress database object.
	 *
	 * @return array|null [ 'select' => ..., 'count' => ... ] or null.
	 */
	private function build_page_visits_union_sql( array $filters, $wpdb ): ?array {
		if ( empty( $filters['contact_id'] ) || ! empty( $filters['entity_type'] ) || ! empty( $filters['user_id'] ) ) {
			return null;
		}

		$visits_table   = $wpdb->prefix . 'doublescale_page_visits';
		$contacts_table = $wpdb->prefix . 'doublescale_contacts';
		$where          = array( $wpdb->prepare( 'pv.contact_id = %d', $filters['contact_id'] ) );

		if ( ! empty( $filters['date_from'] ) ) {
			$where[] = $wpdb->prepare( 'pv.created_at >= %s', $filters['date_from'] . ' 00:00:00' );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[] = $wpdb->prepare(
				'pv.created_at < %s',
				gmdate( 'Y-m-d', strtotime( $filters['date_to'] . ' +1 day' ) ) . ' 00:00:00'
			);
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$select_sql = "SELECT
			pv.id,
			'tracking' as item_type,
			'page_visited' as activity_type,
			NULL as task_type,
			pv.contact_id,
			NULL as deal_id,
			JSON_OBJECT(
				'path', pv.path,
				'query', pv.query,
				'visited_at', pv.created_at,
				'contact_name', TRIM(CONCAT(COALESCE(con.first_name, ''), ' ', COALESCE(con.last_name, '')))
			) as data,
			NULL as user_id,
			pv.created_at,
			pv.updated_at,
			pv.created_at as activity_date,
			NULL as title,
			NULL as description,
			NULL as status,
			NULL as display_status,
			NULL as priority,
			NULL as due_date,
			NULL as due_time,
			NULL as is_overdue,
			0 as comments_count
		FROM {$visits_table} pv
		LEFT JOIN {$contacts_table} con ON pv.contact_id = con.id
		WHERE {$where_sql}";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$visits_table} pv WHERE {$where_sql}";

		return array(
			'select' => $select_sql,
			'count'  => $count_sql,
		);
	}

	/**
	 * Transform a tracking row from SQL result.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row Tracking row.
	 *
	 * @return array Transformed tracking item.
	 */
	private function transform_tracking_row( $row ): array {
		$data = ! empty( $row->data ) ? json_decode( $row->data, true ) : array();

		return array(
			'id'                => (int) $row->id,
			'item_type'         => 'tracking',
			'activity_type'     => $row->activity_type,
			'contact_id'        => $row->contact_id ? (int) $row->contact_id : null,
			'deal_id'           => null,
			'data'              => $data,
			'user_id'           => null,
			'user'              => null,
			'formatted_message' => $this->format_tracking_message( $row->activity_type, $data ),
			'is_editable'       => false,
			'is_system'         => false,
			'display_status'    => null,
			'comments_count'    => 0,
			'activity_date'     => $row->activity_date,
			'created_at'        => $row->created_at,
			'updated_at'        => $row->updated_at,
		);
	}

	/**
	 * Format tracking event message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Activity type (email_opened, email_clicked, etc.).
	 * @param array  $data Tracking data.
	 *
	 * @return string Formatted message.
	 */
	private function format_tracking_message( string $type, array $data ): string {
		$campaign_name = ! empty( $data['campaign_name'] ) ? $data['campaign_name'] : '';
		$contact_name  = ! empty( $data['contact_name'] ) ? $data['contact_name'] : __( 'Contact', 'doublescale' );

		switch ( $type ) {
			case 'email_opened':
				/* translators: %s: contact name */
				$message = sprintf( __( '%s opened an email', 'doublescale' ), $contact_name );
				if ( ! empty( $campaign_name ) ) {
					/* translators: %s: campaign name */
					$message .= sprintf( __( ' from campaign "%s"', 'doublescale' ), $campaign_name );
				}
				return $message;

			case 'email_clicked':
				/* translators: %s: contact name */
				$message = sprintf( __( '%s clicked a link in an email', 'doublescale' ), $contact_name );
				if ( ! empty( $campaign_name ) ) {
					/* translators: %s: campaign name */
					$message .= sprintf( __( ' from campaign "%s"', 'doublescale' ), $campaign_name );
				}
				return $message;

			case 'sms_clicked':
				/* translators: %s: contact name */
				return sprintf( __( '%s clicked a link in an Sms', 'doublescale' ), $contact_name );

			case 'whatsapp_clicked':
				/* translators: %s: contact name */
				return sprintf( __( '%s clicked a link in a WhatsApp message', 'doublescale' ), $contact_name );

			case 'page_visited':
				$path    = $data['path'] ?? '/';
				$message = sprintf(
					/* translators: 1: contact name, 2: page path */
					__( '%1$s visited %2$s', 'doublescale' ),
					$contact_name,
					$path
				);
				return $message;

			default:
				return __( 'Tracking event', 'doublescale' );
		}
	}

	/**
	 * Sanitize sort field
	 *
	 * @since 1.0.0
	 *
	 * @param string $field Sort field.
	 *
	 * @return string Sanitized field.
	 */
	private function sanitize_sort_field( string $field ): string {
		$allowed = array( 'created_at', 'activity_date', 'due_date' );

		if ( $field === 'due_date' ) {
			return 'COALESCE(due_date, activity_date)';
		}

		return in_array( $field, $allowed, true ) ? $field : 'activity_date';
	}
}
