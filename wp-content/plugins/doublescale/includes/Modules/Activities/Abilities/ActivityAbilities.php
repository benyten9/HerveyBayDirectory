<?php
/**
 * Read-only activity timeline abilities.
 *
 * @package DoubleScale\Modules\Activities
 */

namespace DoubleScale\Modules\Activities\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityBulk;
use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityInput;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Activities\Services\ActivityManager;
use DoubleScale\Modules\Contacts\Abilities\ContactAbilities;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * "What happened with this customer" is the question the timeline answers, and
 * it is the one an agent gets asked constantly.
 *
 * These delegate to {@see ActivityManager::get_unified_timeline()} rather than
 * querying the table: that method already merges tasks into the stream and runs
 * its own deal/project access checks, so reimplementing it here would mean
 * maintaining two versions of the same authorisation logic.
 *
 * The `activities` module is never toggleable, so Gate 1 always passes; Gate 2
 * defers to contact-read access because a timeline is only ever read in the
 * context of a contact.
 */
final class ActivityAbilities {

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( Permissions::class, 'can_read_contacts' );

		return array(
			'doublescale/get-contact-timeline' => array(
				'module_slug'      => 'activities',
				'label'            => __( 'Get contact timeline', 'doublescale' ),
				'description'      => __( 'Everything that has happened with one contact in chronological order — notes, calls, emails, meetings, and tasks. This is the fastest way to answer "what is going on with this customer".', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Contact id. Use list-contacts to find one.',
						),
						'date_from'  => array(
							'type'        => 'string',
							'description' => 'Inclusive lower bound, YYYY-MM-DD.',
						),
						'date_to'    => array(
							'type'        => 'string',
							'description' => 'Inclusive upper bound, YYYY-MM-DD.',
						),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 20,
						),
					),
					'required'   => array( 'contact_id' ),
				),
				'execute_callback' => array( self::class, 'get_contact_timeline' ),
			),

			'doublescale/list-activity-types'  => array(
				'module_slug'      => 'activities',
				'label'            => __( 'List activity types', 'doublescale' ),
				'description'      => __( 'The activity type vocabulary, split into types a person can author (notes, calls, meetings) and types the system generates automatically.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'execute_callback' => array( self::class, 'list_activity_types' ),
			),

			'doublescale/list-activities'      => array(
				'module_slug'      => 'activities',
				'label'            => __( 'List activities', 'doublescale' ),
				'description'      => __( 'Recent activity across the whole CRM — notes, calls, emails, status changes, and system events — newest first. Use this for "what happened today" or "what changed recently". Audit rows that belong to a specific deal, task, or project are left out of this feed; read those from the record itself. For one contact\'s history use get-contact-timeline, which merges in sources this does not cover.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'activity_type' => array(
							'type'        => 'string',
							'description' => 'Filter by activity type. Call list-activity-types for the vocabulary; several types can be passed comma-separated.',
						),
						'contact_id'    => array(
							'type'        => 'integer',
							'description' => 'Only activities linked to this contact.',
						),
						'user_id'       => array(
							'type'        => 'integer',
							'description' => 'Only activities recorded by this WordPress user.',
						),
						'date_from'     => array(
							'type'        => 'string',
							'description' => 'Only activities on or after this date (YYYY-MM-DD).',
						),
						'date_to'       => array(
							'type'        => 'string',
							'description' => 'Only activities on or before this date (YYYY-MM-DD).',
						),
						'limit'         => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'        => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_activities' ),
			),

			'doublescale/add-contact-note'     => array(
				'module_slug'      => 'activities',
				'label'            => __( 'Add a note to a contact', 'doublescale' ),
				'description'      => __( 'Append a note to a contact\'s timeline. The note is attributed to you and cannot be edited or removed through this tool.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => array( Permissions::class, 'can_send_contact_message' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Contact to attach the note to.',
						),
						'content'    => array(
							'type'        => 'string',
							'description' => 'The note text.',
						),
						'title'      => array(
							'type'        => 'string',
							'description' => 'Optional short heading for the note.',
						),
					),
					'required'   => array( 'contact_id', 'content' ),
				),
				// Append-only: nothing is overwritten and no notification or
				// automation trigger fires, which makes this the lowest-risk
				// write in the product.
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => false,
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'add_contact_note' ),
			),

			'doublescale/log-call'             => array(
				'module_slug'      => 'activities',
				'label'            => __( 'Log a call', 'doublescale' ),
				'description'      => __( 'Record that a call happened with a contact, with optional duration, outcome, and notes. This only records history — it does not place a call.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => array( Permissions::class, 'can_send_contact_message' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Contact the call was with.',
						),
						'notes'      => array(
							'type'        => 'string',
							'description' => 'What was discussed.',
						),
						'duration'   => array(
							'type'        => 'integer',
							'description' => 'Call length in minutes.',
						),
						'outcome'    => array(
							'type'        => 'string',
							'description' => 'Short result, e.g. "left voicemail" or "agreed to proposal".',
						),
					),
					'required'   => array( 'contact_id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => false,
						// Records history only; places no outbound call.
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'log_call' ),
			),

			'doublescale/add-contact-notes-bulk' => array(
				'module_slug'      => 'activities',
				'label'            => __( 'Add notes to contacts in bulk', 'doublescale' ),
				'description'      => __( 'Append a note to each of many contacts in one call. Provide exactly one of: notes (per-row objects, each with its own text), contact_ids, or filter. With contact_ids or filter, the same content is written to every match. Set dry_run to preview without writing. Notes are attributed to you and cannot be edited or removed through this tool. Nothing is emailed. Rows are processed independently — some may succeed while others fail. Check errors before reporting success.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => array( Permissions::class, 'can_send_contact_message' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'notes'       => array(
							'type'        => 'array',
							'minItems'    => 1,
							'maxItems'    => AbilityBulk::max_items( 'doublescale/add-contact-notes-bulk' ),
							'description' => 'One object per note. Each accepts: contact_id (required), '
								. 'content (required), title. Mutually exclusive with contact_ids and filter.',
							// NO 'items' key — WP validates items before the callback
							// runs, so one bad row would reject the whole batch.
						),
						'contact_ids' => AbilityBulk::ids_property(
							'doublescale/add-contact-notes-bulk',
							'Contact ids to receive the same note. Mutually exclusive with notes and filter.'
						),
						'filter'      => AbilityBulk::filter_property(
							'Same criteria as list-contacts. Mutually exclusive with notes and contact_ids. An empty filter is refused.',
							ContactAbilities::filter_schema_properties()
						),
						'content'     => array(
							'type'        => 'string',
							'description' => 'Note body applied to every matched contact (contact_ids or filter).',
						),
						'title'       => array(
							'type'        => 'string',
							'description' => 'Optional note title applied to every matched contact (contact_ids or filter).',
						),
						'dry_run'     => AbilityBulk::dry_run_property(),
					),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						// Each call appends fresh rows.
						'idempotent'    => false,
						'openWorldHint' => false,
						'bulk'          => true,
					),
				),
				'execute_callback' => array( self::class, 'add_contact_notes_bulk' ),
			),

			'doublescale/log-calls-bulk'       => array(
				'module_slug'      => 'activities',
				'label'            => __( 'Log calls in bulk', 'doublescale' ),
				'description'      => __( 'Record many calls in one call to this tool — useful after working through a call list. Provide exactly one of: calls (per-row objects), contact_ids, or filter. With contact_ids or filter, the same notes/duration/outcome apply to every match. Set dry_run to preview without writing. This only records history; it places no outbound calls and emails nobody. Rows are processed independently — some may succeed while others fail. Check errors before reporting success.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => array( Permissions::class, 'can_send_contact_message' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'calls'       => array(
							'type'        => 'array',
							'minItems'    => 1,
							'maxItems'    => AbilityBulk::max_items( 'doublescale/log-calls-bulk' ),
							'description' => 'One object per call. Each accepts: contact_id (required), '
								. 'notes, duration (minutes), outcome. Mutually exclusive with contact_ids and filter.',
						),
						'contact_ids' => AbilityBulk::ids_property(
							'doublescale/log-calls-bulk',
							'Contact ids to log the same call against. Mutually exclusive with calls and filter.'
						),
						'filter'      => AbilityBulk::filter_property(
							'Same criteria as list-contacts. Mutually exclusive with calls and contact_ids. An empty filter is refused.',
							ContactAbilities::filter_schema_properties()
						),
						'notes'       => array(
							'type'        => 'string',
							'description' => 'Call notes applied to every matched contact (contact_ids or filter).',
						),
						'duration'    => array(
							'type'        => 'integer',
							'description' => 'Call length in minutes, applied to every matched contact (contact_ids or filter).',
						),
						'outcome'     => array(
							'type'        => 'string',
							'description' => 'Call outcome applied to every matched contact (contact_ids or filter).',
						),
						'dry_run'     => AbilityBulk::dry_run_property(),
					),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => false,
						'openWorldHint' => false,
						'bulk'          => true,
					),
				),
				'execute_callback' => array( self::class, 'log_calls_bulk' ),
			),
		);
	}

	/**
	 * Confirm a contact exists before writing anything against it.
	 *
	 * ActivityManager does NOT check this: passing an unknown contact_id creates
	 * an orphan activity row pointing at nothing, which then shows up in no
	 * timeline and cannot be found or removed through the UI. An agent that
	 * mistypes an id would silently litter the table.
	 *
	 * @since 1.0.0
	 *
	 * @param int $contact_id Contact id.
	 * @return \WP_Error|null Null when the contact exists.
	 */
	private static function assert_contact_exists( int $contact_id ): ?\WP_Error {
		if ( ContactModel::query()->where( 'id', $contact_id )->count() > 0 ) {
			return null;
		}

		return AbilityResult::not_found(
			sprintf(
				/* translators: %d: contact id */
				__( 'No contact exists with id %d. Use list-contacts to find the right one.', 'doublescale' ),
				$contact_id
			)
		);
	}

	/**
	 * Append a note to a contact timeline.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function add_contact_note( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'contact_id', 'content' ) ),
				AbilityInput::id( $input['contact_id'] ?? null, 'contact_id' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$missing = self::assert_contact_exists( (int) $input['contact_id'] );
		if ( $missing ) {
			return $missing;
		}

		if ( AbilityBulk::is_preview( $input ) ) {
			return array(
				'created'      => false,
				'would_create' => true,
				'contact_id'   => (int) $input['contact_id'],
			);
		}

		$activity = ActivityManager::instance()->add_note(
			array(
				'contact_id' => (int) $input['contact_id'],
				'content'    => (string) $input['content'],
				'title'      => isset( $input['title'] ) ? (string) $input['title'] : '',
			)
		);

		// The service returns null for every failure — unknown contact, no
		// access, empty body — so translate that into something an agent can
		// act on rather than passing a bare null back as success.
		if ( ! $activity ) {
			return new \WP_Error(
				'doublescale_note_not_created',
				__( 'The note could not be added. Check that the contact id exists and that you have access to it.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'created'     => true,
			'activity_id' => (int) $activity->id,
			'contact_id'  => (int) $input['contact_id'],
		);
	}

	/**
	 * Record a call against a contact.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function log_call( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'contact_id' ) ),
				AbilityInput::id( $input['contact_id'] ?? null, 'contact_id' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$missing = self::assert_contact_exists( (int) $input['contact_id'] );
		if ( $missing ) {
			return $missing;
		}

		if ( AbilityBulk::is_preview( $input ) ) {
			return array(
				'created'      => false,
				'would_create' => true,
				'contact_id'   => (int) $input['contact_id'],
			);
		}

		$activity = ActivityManager::instance()->log_call(
			array(
				'contact_id' => (int) $input['contact_id'],
				'notes'      => isset( $input['notes'] ) ? (string) $input['notes'] : '',
				'outcome'    => isset( $input['outcome'] ) ? (string) $input['outcome'] : '',
				'duration'   => isset( $input['duration'] ) ? (int) $input['duration'] : null,
			)
		);

		if ( ! $activity ) {
			return new \WP_Error(
				'doublescale_call_not_logged',
				__( 'The call could not be logged. Check that the contact id exists and that you have access to it.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'created'     => true,
			'activity_id' => (int) $activity->id,
			'contact_id'  => (int) $input['contact_id'],
		);
	}

	/**
	 * Add a note to each of many contacts.
	 *
	 * Loops {@see add_contact_note()} per row, so the contact-exists check and
	 * the service's own access check stay in one place. No dedup pass here:
	 * unlike contacts, two notes on the same contact are a legitimate request,
	 * not a mistake to catch.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function add_contact_notes_bulk( array $input ) {
		if ( ! array_key_exists( 'notes', $input ) ) {
			$missing = AbilityInput::required( $input, array( 'content' ) );
			if ( $missing ) {
				return $missing;
			}
		}

		return AbilityBulk::run_targeted(
			$input,
			'doublescale/add-contact-notes-bulk',
			static function ( array $row ) {
				return self::add_contact_note( $row );
			},
			'created',
			array(
				'rows_key'       => 'notes',
				'ids_key'        => 'contact_ids',
				'id_field'       => 'contact_id',
				'patch_keys'     => array( 'content', 'title' ),
				'patch_required' => true,
				'querier'        => array( ContactAbilities::class, 'query_for_filter' ),
			),
			array(
				'id_key'      => 'contact_id',
				'applied_key' => 'applied_contact_ids',
			)
		);
	}

	/**
	 * Record many calls.
	 *
	 * Loops {@see log_call()} per row.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function log_calls_bulk( array $input ) {
		return AbilityBulk::run_targeted(
			$input,
			'doublescale/log-calls-bulk',
			static function ( array $row ) {
				return self::log_call( $row );
			},
			'created',
			array(
				'rows_key'   => 'calls',
				'ids_key'    => 'contact_ids',
				'id_field'   => 'contact_id',
				'patch_keys' => array( 'notes', 'duration', 'outcome' ),
				'querier'    => array( ContactAbilities::class, 'query_for_filter' ),
			),
			array(
				'id_key'      => 'contact_id',
				'applied_key' => 'applied_contact_ids',
			)
		);
	}

	/**
	 * Timeline for one contact.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_contact_timeline( array $input ) {
		$contact_id = isset( $input['contact_id'] ) ? (int) $input['contact_id'] : 0;
		if ( $contact_id <= 0 ) {
			return AbilityResult::not_found( __( 'Provide a valid contact id.', 'doublescale' ) );
		}

		$limit = AbilityResult::limit( $input, 20, 50 );

		$filters = array( 'contact_id' => $contact_id );
		if ( ! empty( $input['date_from'] ) ) {
			$filters['date_from'] = (string) $input['date_from'];
		}
		if ( ! empty( $input['date_to'] ) ) {
			$filters['date_to'] = (string) $input['date_to'];
		}

		$timeline = ActivityManager::instance()->get_unified_timeline( $filters, $limit, 1 );

		$rows = is_array( $timeline['data'] ?? null ) ? $timeline['data'] : array();
		$meta = is_array( $timeline['meta'] ?? null ) ? $timeline['meta'] : array();

		// The service reports permission refusals through meta.error rather than
		// throwing, so surface that as a real refusal instead of "no results".
		if ( ! empty( $meta['error'] ) ) {
			return AbilityResult::forbidden(
				__( 'You do not have permission to view this timeline.', 'doublescale' )
			);
		}

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape_entry( $row );
		}

		return array(
			'contact_id' => $contact_id,
			'items'      => $items,
			'total'      => (int) ( $meta['total'] ?? count( $items ) ),
			'limit'      => $limit,
			'has_more'   => (int) ( $meta['total'] ?? 0 ) > count( $items ),
		);
	}

	/**
	 * The activity type vocabulary.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_activity_types( array $input ): array {
		unset( $input );

		return array(
			'editable_types' => ActivityTypes::get_editable_types(),
			'system_types'   => ActivityTypes::get_system_types(),
			'note'           => __( 'Editable types are authored by people; system types are generated automatically by the CRM and are not written by hand.', 'doublescale' ),
		);
	}

	/**
	 * Recent activity across the whole CRM.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function list_activities( $input ) {
		$input  = (array) $input;
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$filters = array();

		foreach ( array( 'activity_type', 'date_from', 'date_to' ) as $key ) {
			if ( ! empty( $input[ $key ] ) ) {
				$filters[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		foreach ( array( 'contact_id', 'user_id' ) as $key ) {
			if ( ! empty( $input[ $key ] ) ) {
				$filters[ $key ] = (int) $input[ $key ];
			}
		}

		if ( isset( $filters['contact_id'] ) ) {
			$error = self::assert_contact_exists( $filters['contact_id'] );
			if ( $error ) {
				return $error;
			}
		}

		// get_activities() rather than get_unified_timeline(): the unified
		// version is a UNION across sources that deliberately ignores
		// activity_type (see its own comment), and this ability is built around
		// filtering by type. It also eager-loads the morph rows, so shaping
		// rows here does not fire a query each.
		//
		// The service applies deal and task permission checks internally, so a
		// caller never sees an activity attached to a record they cannot open.
		$page      = (int) floor( $offset / $limit ) + 1;
		$paginator = ActivityManager::instance()->get_activities( $filters, $limit, $page );

		$items = array();
		foreach ( $paginator->items() as $row ) {
			$items[] = self::shape_entry( $row );
		}

		return AbilityResult::collection(
			$items,
			(int) $paginator->total(),
			$limit,
			$offset
		);
	}

	/**
	 * Shape one timeline or activity row.
	 *
	 * Rows arrive either from a UNION query (get-contact-timeline) or from an
	 * Eloquent paginator (list-activities), so their shape differs between
	 * sources — read defensively.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $row Row.
	 * @return array<string, mixed>
	 */
	private static function shape_entry( $row ): array {
		// An Eloquent model keeps its attributes behind __get, so
		// get_object_vars() returns an empty array for it — every field would
		// come back null. toArray() is the only shape that works for both the
		// UNION's plain objects and a model.
		if ( is_object( $row ) && method_exists( $row, 'toArray' ) ) {
			$data = (array) $row->toArray();

			// formatted_message is a magic accessor, so toArray() omits it —
			// without this every summary would be blank and the reader would
			// get a row of nulls instead of "wo wo logged in".
			if ( empty( $data['formatted_message'] ) ) {
				$data['formatted_message'] = (string) $row->formatted_message;
			}
		} elseif ( is_object( $row ) ) {
			$data = get_object_vars( $row );
		} else {
			$data = (array) $row;
		}

		// The service already renders a human sentence ("Someone linked invoice
		// INV-000001 to this deal"). Prefer it over the raw payload: it is what
		// a reader would recognise, and the `data` column is a per-type blob
		// that means nothing without knowing the type.
		$body = '';
		foreach ( array( 'formatted_message', 'title', 'content', 'description' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$body = $data[ $key ];
				break;
			}
		}

		if ( '' === $body && ! empty( $data['data'] ) ) {
			$payload = is_array( $data['data'] ) ? $data['data'] : array();
			if ( ! empty( $payload['title'] ) && is_string( $payload['title'] ) ) {
				$body = $payload['title'];
			}
		}

		$shaped = AbilityResult::truncate( $body, 500 );

		$author = $data['user'] ?? null;

		return array(
			'id'        => isset( $data['id'] ) ? (int) $data['id'] : null,
			'kind'      => $data['item_type'] ?? 'activity',
			'type'      => $data['activity_type'] ?? null,
			'date'      => $data['activity_date'] ?? $data['created_at'] ?? null,
			'author'    => is_array( $author ) ? ( $author['display_name'] ?? null ) : ( is_object( $author ) ? ( $author->display_name ?? null ) : null ),
			'system'    => ! empty( $data['is_system'] ),
			'summary'   => $shaped['text'],
			'truncated' => $shaped['truncated'],
		);
	}
}
