<?php
/**
 * Atomically merge a source contact into a primary contact.
 *
 * All writes go through $wpdb so they share WordPress's MySQL connection and
 * can roll back together. Eloquent uses a separate PDO connection and must not
 * be used for merge writes.
 *
 * Do not call ContactModel::delete() — its deleting hook destroys proposals
 * and unlinks activities.
 *
 * @package DoubleScale\Modules\Contacts
 */

namespace DoubleScale\Modules\Contacts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Core\Constants\TaskEntityType;
use WP_Error;

/**
 * ContactMergeService class.
 */
final class ContactMergeService {

	const MERGED_HASH_META_KEY = 'merged_hash_id';

	/**
	 * Profile columns copied when primary is empty.
	 *
	 * @var string[]
	 */
	const PROFILE_FIELDS = array(
		'first_name',
		'last_name',
		'company_name',
		'company_registration_number',
		'tax_vat_number',
		'address_1',
		'address_2',
		'city',
		'state',
		'country',
		'zip',
		'avatar_id',
		'source',
	);

	/**
	 * Identifier columns that remain unique.
	 *
	 * @var string[]
	 */
	const IDENTIFIER_FIELDS = array( 'email', 'phone', 'whatsapp_phone' );

	/**
	 * Channel status columns.
	 *
	 * @var string[]
	 */
	const STATUS_FIELDS = array( 'email_status', 'sms_status', 'whatsapp_status' );

	/**
	 * Tables with a simple contact_id column.
	 *
	 * Relationship policy (Phase A):
	 * - Reassign contact_id: invoices, proposals, tickets, bookings (including
	 *   waiting-list rows), form submissions, campaign/communication tracking,
	 *   unsubscribes, attachments, deals, projects, contracts, credit notes,
	 *   page visits.
	 * - Union with duplicate protection: tags/lists, custom fields (primary
	 *   value wins; empty primary takes source; conflicts logged), contact
	 *   meta (primary key wins), activity associations, tasks, automation
	 *   enrollments (keep the primary enrollment when both are in the same
	 *   automation).
	 * - Not rewritten as a Contact FK:
	 *   WooCommerce/EDD orders (matched by billing email at read time),
	 *   abandoned carts (email / WP user_id), recurring invoice schedules
	 *   (follow the template invoice contact_id), external memberships
	 *   (MemberPress/PMPro/Woo Memberships live on the WP user; distinct WP
	 *   users are a blocking merge conflict).
	 * - Source deletion uses $wpdb->delete on the contacts row only. Do not
	 *   call ContactModel::delete() — doublescale_contact_deleting destroys
	 *   proposals and unlinks activities that were just moved.
	 *
	 * @var string[]
	 */
	const SIMPLE_CONTACT_TABLES = array(
		'sales_invoices',
		'sales_proposals',
		'support_tickets',
		'bookings',
		'form_submissions',
		'communication_tracking',
		'contact_unsubscribes',
		'attachments',
		'deals',
		'projects',
		'sales_contracts',
		'sales_credit_notes',
		'page_visits',
	);

	/**
	 * @var bool
	 */
	private $used_savepoint = false;

	/**
	 * Preview whether two contacts can be merged and what would change.
	 *
	 * @param int $primary_id Primary contact ID (kept).
	 * @param int $source_id  Source contact ID (absorbed).
	 * @return array|\WP_Error
	 */
	public function preview( $primary_id, $source_id ) {
		$loaded = $this->load_pair( $primary_id, $source_id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$primary = $loaded['primary'];
		$source  = $loaded['source'];

		return array(
			'primary'             => $this->public_contact( $primary ),
			'source'              => $this->public_contact( $source ),
			'blocking'            => $this->blocking_conflicts( $primary, $source ),
			'conflicts'           => $this->field_conflicts( $primary, $source ),
			'identifiers_to_copy' => $this->identifiers_to_copy( $primary, $source ),
			'relationship_counts' => $this->relationship_counts( (int) $source['id'] ),
		);
	}

	/**
	 * Merge a duplicate only when the result needs no human judgement.
	 *
	 * Unattended paths — a visitor updating their own details, a purchase, a
	 * registration, a CSV import — have nobody to review the outcome, and a
	 * merge cannot be undone. So this runs only when there is genuinely nothing
	 * to decide, and otherwise leaves the duplicate for an admin to confirm
	 * through the merge dialog.
	 *
	 * @param int    $primary_id Primary contact ID (kept).
	 * @param int    $source_id  Source contact ID (absorbed).
	 * @param string $reason     Short context for logs and notifications.
	 * @return array|null Merged contact, or null when it was not safe to merge.
	 */
	public function auto_merge( $primary_id, $source_id, $reason = '' ) {
		$preview = $this->preview( $primary_id, $source_id );

		if ( is_wp_error( $preview ) || ! $this->preview_is_auto_mergeable( $preview ) ) {
			return null;
		}

		$merged = $this->merge( $primary_id, $source_id );

		if ( is_wp_error( $merged ) ) {
			doublescale_get_logger()->error(
				'Automatic contact merge failed',
				array(
					'primary_id' => (int) $primary_id,
					'source_id'  => (int) $source_id,
					'reason'     => (string) $reason,
					'error'      => $merged->get_error_message(),
					'code'       => 'contact_auto_merge_failed',
				)
			);
			return null;
		}

		/**
		 * Fires after a duplicate is merged without human review.
		 *
		 * @param array  $merged  Public merged contact row.
		 * @param array  $preview The preview the decision was made on.
		 * @param string $reason  Context for the merge.
		 */
		do_action( 'doublescale_contact_auto_merged', $merged, $preview, (string) $reason );

		return $merged;
	}

	/**
	 * Whether a preview describes a merge with nothing left to decide.
	 *
	 * Requires **both** lists to be present and empty: a blocking conflict means
	 * the same identifier holds two different values, and a field conflict means
	 * a human has to pick which value survives. A malformed preview is treated
	 * as unsafe rather than assumed clean.
	 *
	 * @param mixed $preview Preview payload.
	 * @return bool
	 */
	private function preview_is_auto_mergeable( $preview ) {
		if ( ! is_array( $preview ) ) {
			return false;
		}

		foreach ( array( 'blocking', 'conflicts' ) as $key ) {
			if ( ! isset( $preview[ $key ] ) || ! is_array( $preview[ $key ] ) ) {
				return false;
			}

			if ( ! empty( $preview[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Execute the merge. Source is removed only after relationships move.
	 *
	 * @param int $primary_id Primary contact ID.
	 * @param int $source_id  Source contact ID.
	 * @return array|\WP_Error Public primary contact row on success.
	 */
	public function merge( $primary_id, $source_id ) {
		$preview = $this->preview( $primary_id, $source_id );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		if ( ! empty( $preview['blocking'] ) ) {
			return new WP_Error(
				'merge_identifier_conflict',
				__( 'These contacts cannot be merged because they have different values for the same identifier.', 'doublescale' ),
				array(
					'status'   => 409,
					'blocking' => $preview['blocking'],
				)
			);
		}

		$loaded = $this->load_pair( $primary_id, $source_id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$primary = $loaded['primary'];
		$source  = $loaded['source'];

		$this->begin();

		try {
			$cf_conflicts = array();
			$this->union_taxonomies( (int) $source['id'], (int) $primary['id'] );
			$this->union_custom_fields( (int) $source['id'], (int) $primary['id'], $cf_conflicts );
			$preview['conflicts'] = array_merge( $preview['conflicts'], $cf_conflicts );
			$this->union_meta( (int) $source['id'], (int) $primary['id'] );
			$this->repoint_activities( (int) $source['id'], (int) $primary['id'] );
			$this->repoint_tasks( (int) $source['id'], (int) $primary['id'] );
			$this->repoint_attachments( (int) $source['id'], (int) $primary['id'] );
			$this->merge_automations( (int) $source['id'], (int) $primary['id'] );

			foreach ( self::SIMPLE_CONTACT_TABLES as $suffix ) {
				$this->reassign_contact_id( $suffix, (int) $source['id'], (int) $primary['id'] );
			}

			$merged_row = $this->merge_contact_row( $primary, $source, $preview );
			$this->preserve_source_hash( (int) $primary['id'], (string) $source['hash_id'] );
			$this->insert_merge_note( (int) $primary['id'], $source, $preview );
			$this->delete_source_shell( (int) $source['id'] );

			/**
			 * Fires inside the merge transaction, after data is moved and before commit.
			 *
			 * Tests may throw to force a rollback.
			 *
			 * @param int $primary_id Primary contact ID.
			 * @param int $source_id  Source contact ID.
			 */
			do_action( 'doublescale_contact_merge_before_commit', (int) $primary['id'], (int) $source['id'] );

			$this->commit();
		} catch ( \Throwable $e ) {
			$this->rollback();
			return new WP_Error(
				'merge_failed',
				__( 'Contact merge failed and was rolled back.', 'doublescale' ),
				array(
					'status'  => 500,
					'details' => $e->getMessage(),
				)
			);
		}

		return $this->public_contact( $merged_row );
	}

	/**
	 * Restrictiveness rank: higher means less eligible to receive messages.
	 *
	 * @param string $status Status slug.
	 * @return int
	 */
	public static function status_rank( $status ) {
		$ranks = array(
			'blocked'      => 50,
			'bounced'      => 40,
			'unsubscribed' => 30,
			'unverified'   => 20,
			'subscribed'   => 10,
		);

		$status = (string) $status;
		return isset( $ranks[ $status ] ) ? $ranks[ $status ] : 0;
	}

	/**
	 * Choose the more restrictive channel status.
	 *
	 * @param string $primary_status Primary status.
	 * @param string $source_status  Source status.
	 * @return string
	 */
	public static function merge_status( $primary_status, $source_status ) {
		if ( self::status_rank( $source_status ) > self::status_rank( $primary_status ) ) {
			return (string) $source_status;
		}

		return (string) $primary_status;
	}

	/**
	 * @param mixed $value Field value.
	 * @return bool
	 */
	public static function is_empty_value( $value ) {
		if ( null === $value ) {
			return true;
		}

		if ( is_string( $value ) ) {
			return '' === trim( $value );
		}

		return false;
	}

	/**
	 * @param int $primary_id Primary ID.
	 * @param int $source_id  Source ID.
	 * @return array{primary: array, source: array}|\WP_Error
	 */
	private function load_pair( $primary_id, $source_id ) {
		$primary_id = (int) $primary_id;
		$source_id  = (int) $source_id;

		if ( $primary_id <= 0 || $source_id <= 0 ) {
			return new WP_Error( 'merge_invalid', __( 'Both contacts are required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( $primary_id === $source_id ) {
			return new WP_Error( 'merge_same_contact', __( 'A contact cannot be merged into itself.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$primary = $this->get_contact_row( $primary_id );
		$source  = $this->get_contact_row( $source_id );

		if ( ! $primary || ! $source ) {
			return new WP_Error( 'merge_not_found', __( 'One of the contacts could not be found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return array(
			'primary' => $primary,
			'source'  => $source,
		);
	}

	/**
	 * @param array<string, mixed> $primary Primary row.
	 * @param array<string, mixed> $source  Source row.
	 * @return array<int, array{field: string, primary: mixed, source: mixed}>
	 */
	private function blocking_conflicts( array $primary, array $source ) {
		$blocking = array();

		foreach ( self::IDENTIFIER_FIELDS as $field ) {
			$p = $primary[ $field ] ?? null;
			$s = $source[ $field ] ?? null;
			if ( self::is_empty_value( $p ) || self::is_empty_value( $s ) ) {
				continue;
			}
			if ( (string) $p !== (string) $s ) {
				$blocking[] = array(
					'field'   => $field,
					'primary' => $p,
					'source'  => $s,
				);
			}
		}

		$primary_email = isset( $primary['email'] ) ? (string) $primary['email'] : '';
		$source_email  = isset( $source['email'] ) ? (string) $source['email'] : '';
		if ( '' !== $primary_email && '' !== $source_email && strtolower( $primary_email ) !== strtolower( $source_email ) ) {
			$primary_user = get_user_by( 'email', $primary_email );
			$source_user  = get_user_by( 'email', $source_email );
			if ( $primary_user && $source_user && (int) $primary_user->ID !== (int) $source_user->ID ) {
				$blocking[] = array(
					'field'   => 'wp_user',
					'primary' => (int) $primary_user->ID,
					'source'  => (int) $source_user->ID,
				);
			}
		}

		return $blocking;
	}

	/**
	 * @param array<string, mixed> $primary Primary row.
	 * @param array<string, mixed> $source  Source row.
	 * @return array<int, array{field: string, primary: mixed, source: mixed}>
	 */
	private function field_conflicts( array $primary, array $source ) {
		$conflicts = array();
		foreach ( self::PROFILE_FIELDS as $field ) {
			$p = $primary[ $field ] ?? null;
			$s = $source[ $field ] ?? null;
			if ( self::is_empty_value( $p ) || self::is_empty_value( $s ) ) {
				continue;
			}
			if ( (string) $p !== (string) $s ) {
				$conflicts[] = array(
					'field'   => $field,
					'primary' => $p,
					'source'  => $s,
				);
			}
		}

		return $conflicts;
	}

	/**
	 * @param array<string, mixed> $primary Primary row.
	 * @param array<string, mixed> $source  Source row.
	 * @return array<int, array{field: string, value: mixed}>
	 */
	private function identifiers_to_copy( array $primary, array $source ) {
		$copy = array();
		foreach ( self::IDENTIFIER_FIELDS as $field ) {
			$p = $primary[ $field ] ?? null;
			$s = $source[ $field ] ?? null;
			if ( self::is_empty_value( $p ) && ! self::is_empty_value( $s ) ) {
				$copy[] = array(
					'field' => $field,
					'value' => $s,
				);
			}
		}

		return $copy;
	}

	/**
	 * @param int $source_id Source contact ID.
	 * @return array<string, int>
	 */
	private function relationship_counts( $source_id ) {
		return array(
			'notes'              => $this->count_notes( $source_id ),
			'activities'         => $this->count_where( 'activity_associations', 'entity_id', $source_id, array( 'entity_type' => (string) ActivityAssociationModel::ENTITY_TYPE_CONTACT ) ),
			'tags'               => $this->count_where( 'contact_taxonomy_relationship', 'contact_id', $source_id, array( 'taxonomy_type' => 'tag' ) ),
			'lists'              => $this->count_where( 'contact_taxonomy_relationship', 'contact_id', $source_id, array( 'taxonomy_type' => 'list' ) ),
			'invoices'           => $this->count_where( 'sales_invoices', 'contact_id', $source_id ),
			'proposals'          => $this->count_where( 'sales_proposals', 'contact_id', $source_id ),
			'tickets'            => $this->count_where( 'support_tickets', 'contact_id', $source_id ),
			'bookings'           => $this->count_where( 'bookings', 'contact_id', $source_id ),
			'deals'              => $this->count_where( 'deals', 'contact_id', $source_id ),
			'projects'           => $this->count_where( 'projects', 'contact_id', $source_id ),
			'tasks'              => $this->count_tasks( $source_id ),
			'contracts'          => $this->count_where( 'sales_contracts', 'contact_id', $source_id ),
			'credit_notes'       => $this->count_where( 'sales_credit_notes', 'contact_id', $source_id ),
			'form_submissions'   => $this->count_where( 'form_submissions', 'contact_id', $source_id ),
			'campaign_tracking'  => $this->count_where( 'communication_tracking', 'contact_id', $source_id ),
			'unsubscribes'       => $this->count_where( 'contact_unsubscribes', 'contact_id', $source_id ),
			'attachments'        => $this->count_where( 'attachments', 'contact_id', $source_id ),
			'page_visits'        => $this->count_where( 'page_visits', 'contact_id', $source_id ),
			'automations'        => $this->count_where( 'automation_contacts', 'contact_id', $source_id ),
			'custom_fields'      => $this->count_where( 'custom_field_relationship', 'entity_id', $source_id, array( 'entity_type' => 'contact' ) ),
		);
	}

	/**
	 * @param array<string, mixed> $row Contact row.
	 * @return array<string, mixed>
	 */
	private function public_contact( array $row ) {
		return array(
			'id'              => (int) $row['id'],
			'first_name'      => $row['first_name'] ?? '',
			'last_name'       => $row['last_name'] ?? '',
			'email'           => $row['email'] ?? null,
			'phone'           => $row['phone'] ?? null,
			'whatsapp_phone'  => $row['whatsapp_phone'] ?? null,
			'email_status'    => $row['email_status'] ?? null,
			'sms_status'      => $row['sms_status'] ?? null,
			'whatsapp_status' => $row['whatsapp_status'] ?? null,
			'hash_id'         => $row['hash_id'] ?? null,
		);
	}

	/**
	 * @param int $id Contact ID.
	 * @return array<string, mixed>|null
	 */
	private function get_contact_row( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'doublescale_contacts';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return void
	 */
	private function begin() {
		global $wpdb;
		$autocommit = (int) $wpdb->get_var( 'SELECT @@SESSION.autocommit' );
		if ( 0 === $autocommit ) {
			$wpdb->query( 'SAVEPOINT doublescale_contact_merge' );
			$this->used_savepoint = true;
			return;
		}

		$wpdb->query( 'START TRANSACTION' );
		$this->used_savepoint = false;
	}

	/**
	 * @return void
	 */
	private function commit() {
		global $wpdb;
		if ( $this->used_savepoint ) {
			$wpdb->query( 'RELEASE SAVEPOINT doublescale_contact_merge' );
			return;
		}

		$wpdb->query( 'COMMIT' );
	}

	/**
	 * @return void
	 */
	private function rollback() {
		global $wpdb;
		if ( $this->used_savepoint ) {
			$wpdb->query( 'ROLLBACK TO SAVEPOINT doublescale_contact_merge' );
			return;
		}

		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * @param int $source_id  Source ID.
	 * @param int $primary_id Primary ID.
	 * @return void
	 */
	private function union_taxonomies( $source_id, $primary_id ) {
		global $wpdb;
		if ( ! $this->table_exists( 'contact_taxonomy_relationship' ) ) {
			return;
		}

		$table = $wpdb->prefix . 'doublescale_contact_taxonomy_relationship';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, taxonomy_type, taxonomy_id FROM {$table} WHERE contact_id = %d", $source_id ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$table} WHERE contact_id = %d AND taxonomy_type = %s AND taxonomy_id = %d LIMIT 1",
					$primary_id,
					$row['taxonomy_type'],
					(int) $row['taxonomy_id']
				)
			);

			if ( $exists ) {
				$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
				continue;
			}

			$wpdb->update(
				$table,
				array( 'contact_id' => $primary_id ),
				array( 'id' => (int) $row['id'] ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * @param int   $source_id  Source ID.
	 * @param int   $primary_id Primary ID.
	 * @param array $conflicts  Conflict list (appended).
	 * @return void
	 */
	private function union_custom_fields( $source_id, $primary_id, array &$conflicts ) {
		global $wpdb;
		if ( ! $this->table_exists( 'custom_field_relationship' ) ) {
			return;
		}

		$table = $wpdb->prefix . 'doublescale_custom_field_relationship';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, custom_field_id, value FROM {$table} WHERE entity_type = %s AND entity_id = %d",
				'contact',
				$source_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$primary_row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id, value FROM {$table} WHERE entity_type = %s AND entity_id = %d AND custom_field_id = %d LIMIT 1",
					'contact',
					$primary_id,
					(int) $row['custom_field_id']
				),
				ARRAY_A
			);

			if ( $primary_row ) {
				$primary_empty = self::is_empty_value( $primary_row['value'] );
				$source_empty  = self::is_empty_value( $row['value'] );
				if ( ! $primary_empty && ! $source_empty && (string) $primary_row['value'] !== (string) $row['value'] ) {
					$conflicts[] = array(
						'field'   => 'custom_field_' . (int) $row['custom_field_id'],
						'primary' => $primary_row['value'],
						'source'  => $row['value'],
					);
				} elseif ( $primary_empty && ! $source_empty ) {
					$wpdb->update(
						$table,
						array( 'value' => $row['value'] ),
						array( 'id' => (int) $primary_row['id'] ),
						array( '%s' ),
						array( '%d' )
					);
				}
				$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
				continue;
			}

			$wpdb->update(
				$table,
				array( 'entity_id' => $primary_id ),
				array( 'id' => (int) $row['id'] ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * @param int $source_id  Source ID.
	 * @param int $primary_id Primary ID.
	 * @return void
	 */
	private function union_meta( $source_id, $primary_id ) {
		global $wpdb;
		if ( ! $this->table_exists( 'contact_meta' ) ) {
			return;
		}

		$table = $wpdb->prefix . 'doublescale_contact_meta';
		$keys  = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT meta_key FROM {$table} WHERE contact_id = %d",
				$primary_id
			)
		);
		$keys = is_array( $keys ) ? $keys : array();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_id, meta_key FROM {$table} WHERE contact_id = %d", $source_id ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( in_array( $row['meta_key'], $keys, true ) ) {
				$wpdb->delete( $table, array( 'meta_id' => (int) $row['meta_id'] ), array( '%d' ) );
				continue;
			}

			$wpdb->update(
				$table,
				array( 'contact_id' => $primary_id ),
				array( 'meta_id' => (int) $row['meta_id'] ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * @param int $source_id  Source ID.
	 * @param int $primary_id Primary ID.
	 * @return void
	 */
	private function repoint_activities( $source_id, $primary_id ) {
		global $wpdb;
		if ( ! $this->table_exists( 'activity_associations' ) ) {
			return;
		}

		$table = $wpdb->prefix . 'doublescale_activity_associations';
		$type  = ActivityAssociationModel::ENTITY_TYPE_CONTACT;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, activity_id FROM {$table} WHERE entity_type = %d AND entity_id = %d",
				$type,
				$source_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$table} WHERE activity_id = %d AND entity_type = %d AND entity_id = %d LIMIT 1",
					(int) $row['activity_id'],
					$type,
					$primary_id
				)
			);
			if ( $exists ) {
				$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
				continue;
			}

			$wpdb->update(
				$table,
				array( 'entity_id' => $primary_id ),
				array( 'id' => (int) $row['id'] ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * @param int $source_id  Source ID.
	 * @param int $primary_id Primary ID.
	 * @return void
	 */
	private function repoint_tasks( $source_id, $primary_id ) {
		global $wpdb;
		if ( ! $this->table_exists( 'tasks' ) ) {
			return;
		}

		$table = $wpdb->prefix . 'doublescale_tasks';
		$wpdb->update(
			$table,
			array( 'entity_id' => $primary_id ),
			array(
				'entity_type' => TaskEntityType::CONTACT,
				'entity_id'   => $source_id,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * @param int $source_id  Source ID.
	 * @param int $primary_id Primary ID.
	 * @return void
	 */
	private function repoint_attachments( $source_id, $primary_id ) {
		global $wpdb;
		if ( ! $this->table_exists( 'attachments' ) ) {
			return;
		}

		$table = $wpdb->prefix . 'doublescale_attachments';
		$wpdb->update(
			$table,
			array( 'contact_id' => $primary_id ),
			array( 'contact_id' => $source_id ),
			array( '%d' ),
			array( '%d' )
		);
		$wpdb->update(
			$table,
			array( 'attachable_id' => $primary_id ),
			array(
				'attachable_type' => 'contact',
				'attachable_id'   => $source_id,
			),
			array( '%d' ),
			array( '%s', '%d' )
		);
	}

	/**
	 * @param int $source_id  Source ID.
	 * @param int $primary_id Primary ID.
	 * @return void
	 */
	private function merge_automations( $source_id, $primary_id ) {
		global $wpdb;
		if ( ! $this->table_exists( 'automation_contacts' ) ) {
			return;
		}

		$enroll_table = $wpdb->prefix . 'doublescale_automation_contacts';
		$proc_table   = $wpdb->prefix . 'doublescale_automation_contact_processes';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, automation_id FROM {$enroll_table} WHERE contact_id = %d", $source_id ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$enroll_table} WHERE contact_id = %d AND automation_id = %d LIMIT 1",
					$primary_id,
					(int) $row['automation_id']
				)
			);

			if ( $exists ) {
				if ( $this->table_exists( 'automation_contact_processes' ) ) {
					$wpdb->delete( $proc_table, array( 'automation_contact_id' => (int) $row['id'] ), array( '%d' ) );
				}
				$wpdb->delete( $enroll_table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
				continue;
			}

			$wpdb->update(
				$enroll_table,
				array( 'contact_id' => $primary_id ),
				array( 'id' => (int) $row['id'] ),
				array( '%d' ),
				array( '%d' )
			);

			if ( $this->table_exists( 'automation_contact_processes' ) ) {
				$wpdb->update(
					$proc_table,
					array( 'contact_id' => $primary_id ),
					array( 'automation_contact_id' => (int) $row['id'] ),
					array( '%d' ),
					array( '%d' )
				);
			}
		}
	}

	/**
	 * @param string $suffix Table suffix.
	 * @param int    $from   Source contact ID.
	 * @param int    $to     Primary contact ID.
	 * @return void
	 */
	private function reassign_contact_id( $suffix, $from, $to ) {
		global $wpdb;
		if ( ! $this->table_exists( $suffix ) ) {
			return;
		}

		$table = $wpdb->prefix . 'doublescale_' . $suffix;
		$wpdb->update(
			$table,
			array( 'contact_id' => $to ),
			array( 'contact_id' => $from ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * @param array<string, mixed> $primary Primary row.
	 * @param array<string, mixed> $source  Source row.
	 * @param array                $preview Preview payload.
	 * @return array<string, mixed>
	 */
	private function merge_contact_row( array $primary, array $source, array $preview ) {
		global $wpdb;

		$updates = array();
		foreach ( self::PROFILE_FIELDS as $field ) {
			if ( self::is_empty_value( $primary[ $field ] ?? null ) && ! self::is_empty_value( $source[ $field ] ?? null ) ) {
				$updates[ $field ] = $source[ $field ];
			}
		}

		foreach ( $preview['identifiers_to_copy'] as $copy ) {
			$updates[ $copy['field'] ] = $copy['value'];
		}

		foreach ( self::STATUS_FIELDS as $field ) {
			$updates[ $field ] = self::merge_status(
				(string) ( $primary[ $field ] ?? 'subscribed' ),
				(string) ( $source[ $field ] ?? 'subscribed' )
			);
		}

		$table = $wpdb->prefix . 'doublescale_contacts';

		if ( ! empty( $updates ) ) {
			$clear = array();
			foreach ( self::IDENTIFIER_FIELDS as $field ) {
				if ( isset( $updates[ $field ] ) ) {
					$clear[ $field ] = null;
				}
			}
			if ( ! empty( $clear ) ) {
				$wpdb->update( $table, $clear, array( 'id' => (int) $source['id'] ) );
			}

			$wpdb->update( $table, $updates, array( 'id' => (int) $primary['id'] ) );
		}

		$merged = $this->get_contact_row( (int) $primary['id'] );
		return is_array( $merged ) ? $merged : $primary;
	}

	/**
	 * @param int    $primary_id Primary ID.
	 * @param string $hash_id    Source hash_id.
	 * @return void
	 */
	private function preserve_source_hash( $primary_id, $hash_id ) {
		global $wpdb;
		if ( '' === $hash_id || ! $this->table_exists( 'contact_meta' ) ) {
			return;
		}

		$wpdb->insert(
			$wpdb->prefix . 'doublescale_contact_meta',
			array(
				'contact_id' => $primary_id,
				'meta_key'   => self::MERGED_HASH_META_KEY,
				'meta_value' => $hash_id,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * @param int                  $primary_id Primary ID.
	 * @param array<string, mixed> $source     Source row.
	 * @param array                $preview    Preview payload.
	 * @return void
	 */
	private function insert_merge_note( $primary_id, array $source, array $preview ) {
		global $wpdb;
		if ( ! $this->table_exists( 'activities' ) || ! $this->table_exists( 'activity_associations' ) ) {
			return;
		}

		$date  = current_time( 'mysql' );
		$lines = array(
			sprintf(
				/* translators: %s: datetime */
				__( 'Contact merged on %s.', 'doublescale' ),
				$date
			),
			sprintf(
				/* translators: %d: source contact id */
				__( 'Duplicate contact #%d was merged into this contact. Related records were preserved.', 'doublescale' ),
				(int) $source['id']
			),
		);

		if ( ! empty( $preview['conflicts'] ) ) {
			$lines[] = __( 'Conflicting fields were kept on the primary contact:', 'doublescale' );
			foreach ( $preview['conflicts'] as $conflict ) {
				$lines[] = sprintf(
					'%s: %s / %s',
					$conflict['field'],
					(string) $conflict['primary'],
					(string) $conflict['source']
				);
			}
		}

		$now  = current_time( 'mysql', true );
		$body = implode( "\n", $lines );
		$data = wp_json_encode(
			array(
				'title'   => __( 'Contact merged', 'doublescale' ),
				'type'    => 'note',
				'content' => $body,
				'note'    => $body,
				'source'  => (int) $source['id'],
			)
		);

		$wpdb->insert(
			$wpdb->prefix . 'doublescale_activities',
			array(
				'activity_type' => 'note',
				'data'          => $data,
				'user_id'       => get_current_user_id() ? get_current_user_id() : null,
				'activity_date' => $now,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		$activity_id = (int) $wpdb->insert_id;
		if ( $activity_id <= 0 ) {
			throw new \RuntimeException( 'Failed to record merge activity.' );
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'doublescale_activity_associations',
			array(
				'activity_id' => $activity_id,
				'entity_type' => ActivityAssociationModel::ENTITY_TYPE_CONTACT,
				'entity_id'   => $primary_id,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new \RuntimeException( 'Failed to associate merge activity.' );
		}
	}

	/**
	 * Delete the empty source row without firing ContactModel deleting hooks.
	 *
	 * @param int $source_id Source ID.
	 * @return void
	 */
	private function delete_source_shell( $source_id ) {
		global $wpdb;

		$deleted = $wpdb->delete(
			$wpdb->prefix . 'doublescale_contacts',
			array( 'id' => $source_id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			throw new \RuntimeException( 'Failed to remove the merged source contact.' );
		}
	}

	/**
	 * @param string $suffix Table suffix without prefix.
	 * @return bool
	 */
	private function table_exists( $suffix ) {
		global $wpdb;
		$table = $wpdb->prefix . 'doublescale_' . $suffix;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	/**
	 * @param string               $suffix Table suffix.
	 * @param string               $column Column name.
	 * @param int                  $value  Match value.
	 * @param array<string, mixed> $extra  Extra AND equals.
	 * @return int
	 */
	private function count_where( $suffix, $column, $value, array $extra = array() ) {
		global $wpdb;
		if ( ! $this->table_exists( $suffix ) ) {
			return 0;
		}

		$allowed_columns = array(
			'contact_id',
			'entity_id',
			'entity_type',
			'taxonomy_type',
		);
		if ( ! in_array( $column, $allowed_columns, true ) ) {
			return 0;
		}

		$table = $wpdb->prefix . 'doublescale_' . $suffix;
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE `{$column}` = %d";
		$args  = array( $value );
		foreach ( $extra as $extra_col => $extra_val ) {
			if ( ! in_array( $extra_col, $allowed_columns, true ) ) {
				continue;
			}
			$sql   .= " AND `{$extra_col}` = %s";
			$args[] = $extra_val;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- column names are allow-listed.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * @param int $source_id Source ID.
	 * @return int
	 */
	private function count_notes( $source_id ) {
		global $wpdb;
		if ( ! $this->table_exists( 'activities' ) || ! $this->table_exists( 'activity_associations' ) ) {
			return 0;
		}

		$activities = $wpdb->prefix . 'doublescale_activities';
		$assoc      = $wpdb->prefix . 'doublescale_activity_associations';
		$type       = ActivityAssociationModel::ENTITY_TYPE_CONTACT;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$activities} a
				INNER JOIN {$assoc} aa ON aa.activity_id = a.id
				WHERE aa.entity_type = %d AND aa.entity_id = %d AND a.activity_type = %s",
				$type,
				$source_id,
				'note'
			)
		);
	}

	/**
	 * @param int $source_id Source ID.
	 * @return int
	 */
	private function count_tasks( $source_id ) {
		return $this->count_where(
			'tasks',
			'entity_id',
			$source_id,
			array( 'entity_type' => (string) TaskEntityType::CONTACT )
		);
	}
}
