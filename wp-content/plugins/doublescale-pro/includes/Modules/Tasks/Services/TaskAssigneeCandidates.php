<?php
/**
 * The people an assignee picker offers.
 *
 * @package DoubleScale\Pro\Modules\Tasks
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Core\Scope\OwnerScope;
use DoubleScale\Core\UserRoles\UserRoles;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Core\UserRoles\PermissionsCompat;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Pro\Modules\Projects\Capabilities;

/**
 * Lists only the people {@see TaskAssigneeRule} lets take a task, so the
 * task window never offers a choice the save would refuse.
 */
final class TaskAssigneeCandidates {

	/**
	 * The automation assignee meaning "whoever owns the record the task is
	 * about" (deal, project or contact owner).
	 */
	public const RECORD_OWNER = -1;

	/**
	 * Team members who may take a task on this record, by name.
	 *
	 * @param int    $entity_type Task entity type.
	 * @param int    $entity_id   Record id.
	 * @param string $search      Name, login or email (2+ characters), or ''.
	 * @param int[]  $include     Only these ids (resolving a picked value).
	 * @return \WP_User[]
	 */
	public static function for_record( int $entity_type, int $entity_id, string $search = '', array $include = array() ): array {
		$people = array();
		foreach ( self::team( $search, $include ) as $user ) {
			if ( null === TaskAssigneeRule::error( $entity_type, $entity_id, (int) $user->ID ) ) {
				$people[] = $user;
			}
		}
		return $people;
	}

	/**
	 * Team members who open Tasks: the picker before its record is chosen,
	 * and automation steps, which check the record when they run.
	 *
	 * @param string $search  Search text.
	 * @param int[]  $include Only these ids.
	 * @return \WP_User[]
	 */
	public static function who_open_tasks( string $search = '', array $include = array() ): array {
		$people = array();
		foreach ( self::team( $search, $include ) as $user ) {
			if ( null === TaskAssigneeRule::error( TaskEntityType::CONTACT, 0, (int) $user->ID ) ) {
				$people[] = $user;
			}
		}
		return $people;
	}

	/**
	 * Whether the current user can see the record (and so who works on it).
	 *
	 * @param int $entity_type Task entity type.
	 * @param int $entity_id   Record id.
	 * @return bool
	 */
	public static function viewer_sees_record( int $entity_type, int $entity_id ): bool {
		if ( $entity_id <= 0 ) {
			return false;
		}

		if ( TaskEntityType::DEAL === $entity_type ) {
			$deal = doublescale_module_data_ready( 'deals' ) ? DealModel::find( $entity_id ) : null;
			return $deal && ( PermissionsCompat::sees_all_deals() || (int) $deal->owner_id === get_current_user_id() );
		}

		if ( TaskEntityType::PROJECT === $entity_type ) {
			return class_exists( Capabilities::class ) && Capabilities::can_read_project( $entity_id );
		}

		if ( TaskEntityType::CONTACT === $entity_type ) {
			$contact = ContactModel::find( $entity_id );
			return $contact && OwnerScope::current()->can_view_contact( $contact );
		}

		// A record of a custom type: whoever the add-on lets open it.
		if ( TaskRecordEntity::TYPE === $entity_type ) {
			return TaskRecordEntity::readable( $entity_id, get_current_user_id() );
		}

		// A company: whoever reads contacts sees it (as on the company page).
		if ( TaskEntityType::COMPANY === $entity_type ) {
			$company = '\\DoubleScale\\Pro\\Modules\\Companies\\Models\\CompanyModel';
			return class_exists( $company ) && $company::find( $entity_id ) && \DoubleScale\Core\UserRoles\Permissions::can_read_contacts();
		}

		return false;
	}

	/**
	 * The users/frontend shape, so the same picker component reads it.
	 *
	 * @param array<int, \WP_User|array<string, mixed>> $people   People, in order (arrays are virtual rows).
	 * @param int                                       $page     Page (1-based).
	 * @param int                                       $per_page Page size.
	 * @return array<string, mixed>
	 */
	public static function page( array $people, int $page, int $per_page ): array {
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		$total    = count( $people );
		$pages    = (int) max( 1, ceil( $total / $per_page ) );

		$users = array();
		foreach ( array_slice( $people, ( $page - 1 ) * $per_page, $per_page ) as $person ) {
			if ( is_array( $person ) ) {
				$users[] = $person;
				continue;
			}
			$id      = (int) $person->ID;
			$users[] = array(
				'id'           => $id,
				'name'         => (string) $person->display_name,
				'display_name' => (string) $person->display_name,
				'email'        => (string) $person->user_email,
				'avatar_url'   => (string) get_avatar_url( $id, array( 'size' => 48 ) ),
			);
		}

		return array(
			'users'      => $users,
			'pagination' => array(
				'total'        => $total,
				'per_page'     => $per_page,
				'current_page' => $page,
				'total_pages'  => $pages,
				'has_next'     => $page < $pages,
				'has_prev'     => $page > 1,
			),
		);
	}

	/**
	 * The virtual "owner of the linked record" row for automation pickers.
	 *
	 * @return array<string, mixed>
	 */
	public static function record_owner_row(): array {
		$label = __( 'Owner of the linked record (deal, project or contact)', 'doublescale' );

		return array(
			'id'           => self::RECORD_OWNER,
			'name'         => $label,
			'display_name' => $label,
			'email'        => '',
			'avatar_url'   => '',
		);
	}

	/**
	 * Staff with a DoubleScale role (or administrators), matching a search.
	 *
	 * @param string $search  Search text.
	 * @param int[]  $include Only these ids.
	 * @return \WP_User[]
	 */
	private static function team( string $search, array $include ): array {
		$args = array(
			'role__in' => array_merge( UserRoles::get_known_role_slugs(), array( UserRoles::ADMINISTRATOR ) ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'number'   => 500,
		);

		$include = array_values( array_filter( array_map( 'intval', $include ), static fn( $id ) => $id > 0 ) );
		if ( ! empty( $include ) ) {
			$args['include'] = $include;
		}

		$search = trim( $search );
		if ( strlen( $search ) >= 2 ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		return get_users( $args );
	}
}
