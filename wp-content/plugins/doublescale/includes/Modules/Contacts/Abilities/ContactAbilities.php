<?php
/**
 * Contact abilities.
 *
 * @package DoubleScale\Modules\Contacts
 */

namespace DoubleScale\Modules\Contacts\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityBulk;
use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityInput;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Models\ListModel;
use DoubleScale\Modules\Contacts\Models\TagModel;

/**
 * Contacts are team-wide in DoubleScale, so there is no owner column here —
 * Gate 2 (can_read_contacts) is the whole access story for this module.
 */
final class ContactAbilities {

	/**
	 * Email subscription statuses actually written by the CRM.
	 *
	 * There is no central constant for these — importers and form handlers
	 * write the literals directly (e.g. Modules/Forms/Abstracts/Form.php:232),
	 * and the stored set was confirmed against the contacts table. Advertising
	 * a status the data never uses makes an agent filter to an empty result and
	 * report "no contacts" as though it were a fact.
	 */
	public const EMAIL_STATUSES = array( 'subscribed', 'unsubscribed', 'unverified' );

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
			'doublescale/list-contacts'         => array(
				'module_slug'      => 'contacts',
				'label'            => __( 'List contacts', 'doublescale' ),
				'description'      => __( 'Search and page through CRM contacts. Returns id, name, email, phone, company, and status for each match. Use get-contact for the full record.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Free-text match on first name, last name, email, company, or phone.',
						),
						'status'  => array(
							'type'        => 'string',
							'description' => 'Email subscription status.',
							'enum'        => self::EMAIL_STATUSES,
						),
						'tag_id'  => array(
							'type'        => 'integer',
							'description' => 'Only contacts that have this tag.',
						),
						'list_id' => array(
							'type'        => 'integer',
							'description' => 'Only contacts on this list.',
						),
						'limit'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'  => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_contacts' ),
			),

			'doublescale/get-contact'           => array(
				'module_slug'      => 'contacts',
				'label'            => __( 'Get contact', 'doublescale' ),
				'description'      => __( 'Full record for one contact, including address, company, tags, and lists. Look up by id or by email address. Pass include=["relationships"] for counts of their invoices, deals, tickets, tasks, and bookings — one call instead of five, and it respects what you are allowed to see.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'Contact id. Provide this or email.',
						),
						'email'   => array(
							'type'        => 'string',
							'description' => 'Email address. Provide this or id.',
						),
						// Opt-in rather than always-on: each section costs
						// queries, and the usual "who is this" question needs
						// none of them.
						'include' => array(
							'type'        => 'array',
							'description' => 'Optional extra sections. "relationships" adds counts across sales, deals, support, tasks, and bookings. "engagement" adds sent/opened/clicked totals.',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'relationships', 'engagement' ),
							),
						),
					),
				),
				'execute_callback' => array( self::class, 'get_contact' ),
			),

			'doublescale/list-contact-segments' => array(
				'module_slug'      => 'contacts',
				'label'            => __( 'List tags and lists', 'doublescale' ),
				'description'      => __( 'All contact tags and lists with their ids. Call this to resolve a tag or list name to an id before filtering contacts by it, or before add-contact-tags / add-contact-lists.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'type' => array(
							'type'        => 'string',
							'description' => 'Restrict to one kind. Defaults to both.',
							'enum'        => array( 'tag', 'list' ),
						),
					),
				),
				'execute_callback' => array( self::class, 'list_segments' ),
			),

			'doublescale/create-contact'        => array(
				'module_slug'      => 'contacts',
				'label'            => __( 'Create a contact', 'doublescale' ),
				'description'      => __( 'Add a new contact. Email must be unique — if someone already has that address, use update-contact instead. Creating a contact does not email them.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => array( self::class, 'can_write_contacts' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'email'        => array(
							'type'        => 'string',
							'description' => 'Email address. Must not already belong to another contact.',
						),
						'first_name'   => array(
							'type'        => 'string',
							'description' => 'First name.',
						),
						'last_name'    => array(
							'type'        => 'string',
							'description' => 'Last name.',
						),
						'phone'        => array(
							'type'        => 'string',
							'description' => 'Phone number, digits with an optional leading +.',
						),
						'company_name' => array(
							'type'        => 'string',
							'description' => 'Company the contact works for.',
						),
					),
					'required'   => array( 'email' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => false,
						// No welcome email or automation fires on a bare create.
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'create_contact' ),
			),

			'doublescale/update-contact'        => array(
				'module_slug'      => 'contacts',
				'label'            => __( 'Update a contact', 'doublescale' ),
				'description'      => __( 'Change a contact\'s name, phone, or company. Email and subscription status are deliberately not editable here — changing those affects deliverability and consent.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => array( self::class, 'can_write_contacts' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array(
							'type'        => 'integer',
							'description' => 'Contact id.',
						),
						'first_name'   => array(
							'type'        => 'string',
							'description' => 'New first name.',
						),
						'last_name'    => array(
							'type'        => 'string',
							'description' => 'New last name.',
						),
						'phone'        => array(
							'type'        => 'string',
							'description' => 'New phone number.',
						),
						'company_name' => array(
							'type'        => 'string',
							'description' => 'New company name.',
						),
					),
					'required'   => array( 'id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'update_contact' ),
			),

			'doublescale/create-contacts-bulk' => array(
				'module_slug'      => 'contacts',
				'label'            => __( 'Create contacts in bulk', 'doublescale' ),
				'description'      => __( 'Add many contacts in one call. Email must be unique per contact. Creating contacts does not email them. Set dry_run to preview without writing. Rows are processed independently — some may succeed while others fail. Check errors before reporting success.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => array( self::class, 'can_write_contacts' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contacts' => array(
							'type'        => 'array',
							'minItems'    => 1,
							'maxItems'    => AbilityBulk::max_items( 'doublescale/create-contacts-bulk' ),
							'description' => 'One object per contact. Each accepts: email (required, unique), '
								. 'first_name, last_name, phone, company_name. Rows are validated '
								. 'individually — an invalid row is reported in "errors" with its '
								. 'index while valid rows still save.',
							// NO 'items' key at all — WP schema validation of items would
							// reject the whole batch before the callback runs.
						),
						'dry_run'  => AbilityBulk::dry_run_property(),
					),
					'required'   => array( 'contacts' ),
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
				'execute_callback' => array( self::class, 'create_contacts_bulk' ),
			),

			'doublescale/update-contacts-bulk' => array(
				'module_slug'      => 'contacts',
				'label'            => __( 'Update contacts in bulk', 'doublescale' ),
				'description'      => __( 'Change name, phone, or company on many contacts in one call. Email and subscription status are not editable here. Provide exactly one of: contacts (per-row objects), contact_ids, or filter. With contact_ids or filter, the patch fields apply to every match. Set dry_run to preview the match count without writing. Rows are processed independently — some may succeed while others fail. Check errors before reporting success.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => array( self::class, 'can_write_contacts' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contacts'     => array(
							'type'        => 'array',
							'minItems'    => 1,
							'maxItems'    => AbilityBulk::max_items( 'doublescale/update-contacts-bulk' ),
							'description' => 'One object per contact. Each accepts: id (required), '
								. 'first_name, last_name, phone, company_name. Mutually exclusive with '
								. 'contact_ids and filter.',
						),
						'contact_ids'  => AbilityBulk::ids_property(
							'doublescale/update-contacts-bulk',
							'Contact ids to apply the same patch to. Mutually exclusive with contacts and filter.'
						),
						'filter'       => AbilityBulk::filter_property(
							'Same criteria as list-contacts. Mutually exclusive with contacts and contact_ids. An empty filter is refused.',
							self::filter_schema_properties()
						),
						'first_name'   => array(
							'type'        => 'string',
							'description' => 'Patch: new first name for every matched contact (contact_ids or filter).',
						),
						'last_name'    => array(
							'type'        => 'string',
							'description' => 'Patch: new last name for every matched contact (contact_ids or filter).',
						),
						'phone'        => array(
							'type'        => 'string',
							'description' => 'Patch: new phone for every matched contact (contact_ids or filter).',
						),
						'company_name' => array(
							'type'        => 'string',
							'description' => 'Patch: new company name for every matched contact (contact_ids or filter).',
						),
						'dry_run'      => AbilityBulk::dry_run_property(),
					),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => false,
						'bulk'          => true,
					),
				),
				'execute_callback' => array( self::class, 'update_contacts_bulk' ),
			),

			'doublescale/add-contact-tags'     => array(
				'module_slug'      => 'contacts',
				'label'            => __( 'Add tags to contacts', 'doublescale' ),
				'description'      => __( 'Attach existing tags to contacts. Does not create tags — call list-contact-segments to resolve names to ids. Provide exactly one of: contact_id, contact_ids, or filter. Set dry_run to preview the match count without writing. Tags already on a contact are left as-is.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => array( self::class, 'can_write_contacts' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => self::membership_schema_properties(
						'tag_ids',
						'Tag ids to attach. Must already exist — this tool never creates a tag.'
					),
					'required'   => array( 'tag_ids' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'add_contact_tags' ),
			),

			'doublescale/remove-contact-tags'  => array(
				'module_slug'      => 'contacts',
				'label'            => __( 'Remove tags from contacts', 'doublescale' ),
				'description'      => __( 'Detach tags from contacts. The tag itself is not deleted. Provide exactly one of: contact_id, contact_ids, or filter. Set dry_run to preview without writing.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => array( self::class, 'can_write_contacts' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => self::membership_schema_properties(
						'tag_ids',
						'Tag ids to detach. The tags themselves are not deleted.'
					),
					'required'   => array( 'tag_ids' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'remove_contact_tags' ),
			),

			'doublescale/add-contact-lists'    => array(
				'module_slug'      => 'contacts',
				'label'            => __( 'Add contacts to lists', 'doublescale' ),
				'description'      => __( 'Put contacts on existing lists. Does not create lists — call list-contact-segments to resolve names to ids. Provide exactly one of: contact_id, contact_ids, or filter. Set dry_run to preview without writing. Contacts already on a list are left as-is.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => array( self::class, 'can_write_contacts' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => self::membership_schema_properties(
						'list_ids',
						'List ids to attach. Must already exist — this tool never creates a list.'
					),
					'required'   => array( 'list_ids' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'add_contact_lists' ),
			),

			'doublescale/remove-contact-lists' => array(
				'module_slug'      => 'contacts',
				'label'            => __( 'Remove contacts from lists', 'doublescale' ),
				'description'      => __( 'Take contacts off lists. The list itself is not deleted. Provide exactly one of: contact_id, contact_ids, or filter. Set dry_run to preview without writing.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => array( self::class, 'can_write_contacts' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => self::membership_schema_properties(
						'list_ids',
						'List ids to detach. The lists themselves are not deleted.'
					),
					'required'   => array( 'list_ids' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'remove_contact_lists' ),
			),
		);
	}

	/**
	 * Gate 2 for contact writes.
	 *
	 * Reading a contact is broad (project users need it to pick one); changing
	 * one is a sales-team action, so the write gate is deliberately narrower
	 * than the read gate on the same module.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function can_write_contacts(): bool {
		return Permissions::has_sales_rep_access();
	}

	/**
	 * Create a contact.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_contact( array $input ) {
		$invalid = AbilityInput::required( $input, array( 'email' ) );
		if ( $invalid ) {
			return $invalid;
		}

		$email = trim( (string) $input['email'] );

		if ( ! is_email( $email ) ) {
			return new \WP_Error(
				'doublescale_invalid_email',
				sprintf(
					/* translators: %s: the supplied address */
					__( '"%s" is not a valid email address.', 'doublescale' ),
					$email
				),
				array( 'status' => 400 )
			);
		}

		// The model allows duplicates; the admin UI blocks them. Without this an
		// agent asked twice to "add Layla" silently creates two records that
		// then split her history between them.
		$existing = ContactModel::query()->where( 'email', $email )->first();
		if ( $existing ) {
			return new \WP_Error(
				'doublescale_contact_exists',
				sprintf(
					/* translators: 1: email address, 2: existing contact id */
					__( 'A contact with the address %1$s already exists (id %2$d). Use update-contact to change it.', 'doublescale' ),
					$email,
					(int) $existing->id
				),
				array(
					'status'     => 409,
					'contact_id' => (int) $existing->id,
				)
			);
		}

		if ( AbilityBulk::is_preview( $input ) ) {
			return array(
				'created'      => false,
				'would_create' => true,
				'email'        => $email,
			);
		}

		$contact = ContactModel::create(
			array(
				'email'        => $email,
				'first_name'   => isset( $input['first_name'] ) ? (string) $input['first_name'] : '',
				'last_name'    => isset( $input['last_name'] ) ? (string) $input['last_name'] : '',
				'phone'        => isset( $input['phone'] ) ? (string) $input['phone'] : '',
				'company_name' => isset( $input['company_name'] ) ? (string) $input['company_name'] : '',
				'email_status' => 'unverified',
			)
		);

		if ( ! $contact ) {
			return new \WP_Error(
				'doublescale_contact_not_created',
				__( 'The contact could not be created.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'created'    => true,
			'contact_id' => (int) $contact->id,
			'email'      => $contact->email,
		);
	}

	/**
	 * Update a contact.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update_contact( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'id' ) ),
				AbilityInput::id( $input['id'] ?? null, 'id' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$contact = ContactModel::query()->where( 'id', (int) $input['id'] )->first();
		if ( ! $contact ) {
			return AbilityResult::not_found( __( 'No contact found with that id.', 'doublescale' ) );
		}

		$changed = array();
		foreach ( array( 'first_name', 'last_name', 'phone', 'company_name' ) as $field ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}
			$value = (string) $input[ $field ];
			if ( $value !== (string) $contact->{$field} ) {
				$contact->{$field} = $value;
				$changed[]         = $field;
			}
		}

		if ( array() === $changed ) {
			return array(
				'updated'    => false,
				'contact_id' => (int) $contact->id,
				'message'    => __( 'Nothing to change — the contact already has those values.', 'doublescale' ),
			);
		}

		if ( AbilityBulk::is_preview( $input ) ) {
			return array(
				'updated'      => false,
				'would_update' => true,
				'contact_id'   => (int) $contact->id,
				'changed'      => $changed,
			);
		}

		$contact->save();

		return array(
			'updated'    => true,
			'contact_id' => (int) $contact->id,
			'changed'    => $changed,
		);
	}

	/**
	 * Create many contacts.
	 *
	 * Loops {@see create_contact()} per row so every per-row permission check
	 * and validation stays in one place. The only extra logic is intra-batch
	 * email dedup — a single-record callback cannot see sibling rows.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_contacts_bulk( array $input ) {
		// Keyed on strtolower(trim($email)), matching MySQL's case-insensitive
		// UNIQUE index. Deliberately NOT ContactModel::normalize_email() — that
		// only trims, so A@x.com / a@x.com would slip past the seen-set and
		// collide in the DB. Do not lowercase normalize_email() as a "fix";
		// it sits on every save path and would rewrite stored casing.
		$seen = array();

		return AbilityBulk::run(
			$input,
			'contacts',
			'doublescale/create-contacts-bulk',
			static function ( array $row, int $index ) use ( &$seen ) {
				$email_key = self::batch_email_key( $row['email'] ?? '' );

				if ( '' !== $email_key ) {
					if ( isset( $seen[ $email_key ] ) ) {
						return new \WP_Error(
							'doublescale_duplicate_in_batch',
							sprintf(
								/* translators: 1: email address, 2: index of the first occurrence */
								__( 'Email %1$s appears more than once in this batch (first at index %2$d).', 'doublescale' ),
								$email_key,
								$seen[ $email_key ]
							),
							array(
								'status'             => 409,
								'duplicate_of_index' => $seen[ $email_key ],
							)
						);
					}
					$seen[ $email_key ] = $index;
				}

				return self::create_contact( $row );
			},
			'created',
			array(
				'id_key'      => 'contact_id',
				'applied_key' => 'applied_contact_ids',
			)
		);
	}

	/**
	 * Update many contacts.
	 *
	 * Loops {@see update_contact()} per row.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update_contacts_bulk( array $input ) {
		return AbilityBulk::run_targeted(
			$input,
			'doublescale/update-contacts-bulk',
			static function ( array $row ) {
				return self::update_contact( $row );
			},
			'updated',
			array(
				'rows_key'       => 'contacts',
				'ids_key'        => 'contact_ids',
				'id_field'       => 'id',
				'patch_keys'     => array( 'first_name', 'last_name', 'phone', 'company_name' ),
				'patch_required' => true,
				'querier'        => array( self::class, 'query_for_filter' ),
			),
			array(
				'id_key'      => 'contact_id',
				'applied_key' => 'applied_contact_ids',
			)
		);
	}

	/**
	 * Attach tags to contacts.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function add_contact_tags( array $input ) {
		return self::mutate_membership( $input, 'doublescale/add-contact-tags', 'tag', true );
	}

	/**
	 * Detach tags from contacts.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function remove_contact_tags( array $input ) {
		return self::mutate_membership( $input, 'doublescale/remove-contact-tags', 'tag', false );
	}

	/**
	 * Put contacts on lists.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function add_contact_lists( array $input ) {
		return self::mutate_membership( $input, 'doublescale/add-contact-lists', 'list', true );
	}

	/**
	 * Take contacts off lists.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function remove_contact_lists( array $input ) {
		return self::mutate_membership( $input, 'doublescale/remove-contact-lists', 'list', false );
	}

	/**
	 * Shared schema for tag/list membership tools.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ids_key         tag_ids or list_ids.
	 * @param string $ids_description Field description.
	 * @return array<string, array<string, mixed>>
	 */
	private static function membership_schema_properties( string $ids_key, string $ids_description ): array {
		return array(
			'contact_id'  => array(
				'type'        => 'integer',
				'description' => 'One contact. Mutually exclusive with contact_ids and filter.',
			),
			'contact_ids' => AbilityBulk::ids_property(
				'doublescale/add-contact-tags',
				'Contact ids. Mutually exclusive with contact_id and filter.'
			),
			'filter'      => AbilityBulk::filter_property(
				'Same criteria as list-contacts. Mutually exclusive with contact_id and contact_ids. An empty filter is refused.',
				self::filter_schema_properties()
			),
			$ids_key      => array(
				'type'        => 'array',
				'minItems'    => 1,
				'description' => $ids_description,
			),
			'dry_run'     => AbilityBulk::dry_run_property(),
		);
	}

	/**
	 * Add or remove tags/lists on contacts targeted like bulk updates.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input        Ability input.
	 * @param string               $ability_name Full ability name.
	 * @param string               $kind         'tag' or 'list'.
	 * @param bool                 $attach       True to add, false to detach.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function mutate_membership( array $input, string $ability_name, string $kind, bool $attach ) {
		$ids_key = 'tag' === $kind ? 'tag_ids' : 'list_ids';

		$targeted = self::normalize_membership_target( $input );
		if ( is_wp_error( $targeted ) ) {
			return $targeted;
		}

		$term_ids = self::normalize_term_ids( $targeted[ $ids_key ] ?? null, $ids_key );
		if ( is_wp_error( $term_ids ) ) {
			return $term_ids;
		}

		$existing = self::existing_term_ids( $kind, $term_ids );
		$missing  = array_values( array_diff( $term_ids, $existing ) );
		if ( array() !== $missing ) {
			return new \WP_Error(
				'doublescale_unknown_ids',
				sprintf(
					/* translators: 1: tag or list, 2: comma-separated ids */
					__( 'Unknown %1$s ids: %2$s. Call list-contact-segments for ids that exist.', 'doublescale' ),
					$kind,
					implode( ', ', $missing )
				),
				array(
					'status' => 400,
					'field'  => $ids_key,
					'ids'    => $missing,
				)
			);
		}

		return AbilityBulk::run_targeted(
			$targeted,
			$ability_name,
			static function ( array $row ) use ( $kind, $attach, $ids_key, $term_ids ) {
				$row[ $ids_key ] = $term_ids;
				return self::apply_membership_to_contact( $row, $kind, $attach );
			},
			'updated',
			array(
				'ids_key'        => 'contact_ids',
				'id_field'       => 'id',
				'patch_keys'     => array( $ids_key ),
				'patch_required' => true,
				'querier'        => array( self::class, 'query_for_filter' ),
			),
			array(
				'id_key'      => 'contact_id',
				'applied_key' => 'applied_contact_ids',
			)
		);
	}

	/**
	 * Turn a single contact_id into contact_ids so expand sees one targeting mode.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function normalize_membership_target( array $input ) {
		$has_single = array_key_exists( 'contact_id', $input );
		$has_ids    = array_key_exists( 'contact_ids', $input );
		$has_filter = array_key_exists( 'filter', $input );

		if ( (int) $has_single + (int) $has_ids + (int) $has_filter !== 1 ) {
			return new \WP_Error(
				'doublescale_invalid_target',
				__( 'Provide exactly one of: contact_id, contact_ids, filter. Combining them is refused so a filter cannot silently widen a row list.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		if ( $has_single ) {
			$invalid = AbilityInput::id( $input['contact_id'] ?? null, 'contact_id' );
			if ( $invalid ) {
				return $invalid;
			}
			$input['contact_ids'] = array( (int) $input['contact_id'] );
			unset( $input['contact_id'] );
		}

		return $input;
	}

	/**
	 * Positive integer ids from a membership array.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $raw   Caller value.
	 * @param string $field Field name for errors.
	 * @return array<int, int>|\WP_Error
	 */
	private static function normalize_term_ids( $raw, string $field ) {
		if ( ! is_array( $raw ) || array() === $raw ) {
			return new \WP_Error(
				'doublescale_missing_field',
				sprintf(
					/* translators: %s: field name */
					__( 'Provide at least one id in %s.', 'doublescale' ),
					$field
				),
				array(
					'status' => 400,
					'field'  => $field,
				)
			);
		}

		$ids = array();
		foreach ( $raw as $value ) {
			$id = (int) $value;
			if ( $id < 1 ) {
				return new \WP_Error(
					'doublescale_invalid_id',
					sprintf(
						/* translators: %s: field name */
						__( '%s must contain positive integer ids.', 'doublescale' ),
						$field
					),
					array(
						'status' => 400,
						'field'  => $field,
					)
				);
			}
			$ids[] = $id;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Term ids that exist for the given kind.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $kind 'tag' or 'list'.
	 * @param array<int> $ids  Requested ids.
	 * @return array<int, int>
	 */
	private static function existing_term_ids( string $kind, array $ids ): array {
		$class = 'tag' === $kind ? TagModel::class : ListModel::class;
		$found = array();

		foreach ( $class::query()->whereIn( 'id', $ids )->pluck( 'id' ) as $id ) {
			$found[] = (int) $id;
		}

		return $found;
	}

	/**
	 * Add or remove terms on one contact.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input  Row with id plus tag_ids or list_ids.
	 * @param string               $kind   'tag' or 'list'.
	 * @param bool                 $attach True to add, false to detach.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function apply_membership_to_contact( array $input, string $kind, bool $attach ) {
		$invalid = AbilityInput::id( $input['id'] ?? null, 'id' );
		if ( $invalid ) {
			return $invalid;
		}

		$contact = ContactModel::query()->with( array( 'tags', 'lists' ) )->where( 'id', (int) $input['id'] )->first();
		if ( ! $contact ) {
			return AbilityResult::not_found( __( 'No contact found with that id.', 'doublescale' ) );
		}

		$ids_key  = 'tag' === $kind ? 'tag_ids' : 'list_ids';
		$relation = 'tag' === $kind ? 'tags' : 'lists';
		$wanted   = array_map( 'intval', (array) ( $input[ $ids_key ] ?? array() ) );
		$current  = array();
		foreach ( $contact->{$relation} as $term ) {
			$current[] = (int) $term->id;
		}

		$changed = $attach
			? array_values( array_diff( $wanted, $current ) )
			: array_values( array_intersect( $wanted, $current ) );

		if ( array() === $changed ) {
			return array(
				'updated'    => false,
				'contact_id' => (int) $contact->id,
				$ids_key     => array(),
				'message'    => $attach
					? __( 'Nothing to change — those terms are already attached.', 'doublescale' )
					: __( 'Nothing to change — those terms are not attached.', 'doublescale' ),
			);
		}

		if ( AbilityBulk::is_preview( $input ) ) {
			return array(
				'updated'      => false,
				'would_update' => true,
				'contact_id'   => (int) $contact->id,
				$ids_key       => $changed,
			);
		}

		if ( $attach ) {
			if ( 'tag' === $kind ) {
				$contact->add_tags( $changed );
			} else {
				$contact->add_lists( $changed );
			}
		} elseif ( 'tag' === $kind ) {
			$contact->tags()->detach( $changed );
			do_action( 'doublescale_contact_tag_remove', $contact, $changed );
		} else {
			$contact->lists()->detach( $changed );
			do_action( 'doublescale_contact_list_remove', $contact, $changed );
		}

		return array(
			'updated'    => true,
			'contact_id' => (int) $contact->id,
			$ids_key     => $changed,
		);
	}

	/**
	 * Filter fields shared by list-contacts and bulk targeting.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function filter_schema_properties(): array {
		return array(
			'search'  => array(
				'type'        => 'string',
				'description' => 'Free-text match on first name, last name, email, company, or phone.',
			),
			'status'  => array(
				'type'        => 'string',
				'description' => 'Email subscription status.',
				'enum'        => self::EMAIL_STATUSES,
			),
			'tag_id'  => array(
				'type'        => 'integer',
				'description' => 'Only contacts that have this tag.',
			),
			'list_id' => array(
				'type'        => 'integer',
				'description' => 'Only contacts on this list.',
			),
		);
	}

	/**
	 * Contact query for list-contacts and bulk filter targeting.
	 *
	 * Contacts are team-wide — there is no Gate 3 owner column to apply.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $filter Filter criteria.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public static function query_for_filter( array $filter ) {
		$query = ContactModel::query();

		$search = isset( $filter['search'] ) ? trim( (string) $filter['search'] ) : '';
		if ( '' !== $search ) {
			$like = '%' . $search . '%';
			$query->where(
				static function ( $sub ) use ( $like ) {
					$sub->where( 'first_name', 'LIKE', $like )
						->orWhere( 'last_name', 'LIKE', $like )
						->orWhere( 'email', 'LIKE', $like )
						->orWhere( 'company_name', 'LIKE', $like )
						->orWhere( 'phone', 'LIKE', $like );
				}
			);
		}

		if ( ! empty( $filter['status'] ) ) {
			$query->where( 'email_status', (string) $filter['status'] );
		}

		$tag_id = isset( $filter['tag_id'] ) ? (int) $filter['tag_id'] : 0;
		if ( $tag_id > 0 ) {
			$query->whereHas(
				'tags',
				static function ( $q ) use ( $tag_id ) {
					$q->where( $q->getModel()->getTable() . '.id', $tag_id );
				}
			);
		}

		$list_id = isset( $filter['list_id'] ) ? (int) $filter['list_id'] : 0;
		if ( $list_id > 0 ) {
			$query->whereHas(
				'lists',
				static function ( $q ) use ( $list_id ) {
					$q->where( $q->getModel()->getTable() . '.id', $list_id );
				}
			);
		}

		return $query;
	}

	/**
	 * Batch-local email key.
	 *
	 * See {@see create_contacts_bulk()} for why this is not normalize_email().
	 *
	 * @param mixed $email Raw email from a row.
	 * @return string Empty when the row has no usable email.
	 */
	private static function batch_email_key( $email ): string {
		if ( ! is_scalar( $email ) ) {
			return '';
		}

		return strtolower( trim( (string) $email ) );
	}

	/**
	 * List contacts.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_contacts( array $input ): array {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = self::query_for_filter( $input );

		$total = (int) $query->count();

		$rows = $query->orderBy( 'created_at', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape_summary( $row );
		}

		return AbilityResult::collection( $items, $total, $limit, $offset );
	}

	/**
	 * Get one contact by id or email.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_contact( array $input ) {
		$id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$email = isset( $input['email'] ) ? trim( (string) $input['email'] ) : '';

		if ( $id <= 0 && '' === $email ) {
			return new \WP_Error(
				'doublescale_missing_identifier',
				__( 'Provide either a contact id or an email address.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// Eager-load so the shaper does not fire a query per relation.
		$query = ContactModel::query()->with( array( 'tags', 'lists' ) );

		$contact = $id > 0
			? $query->where( 'id', $id )->first()
			: $query->where( 'email', $email )->first();

		if ( ! $contact ) {
			return AbilityResult::not_found( __( 'No contact found for that identifier.', 'doublescale' ) );
		}

		$data = self::shape_summary( $contact );

		$data['company_name'] = $contact->company_name;
		$data['address']      = array_filter(
			array(
				'address_1' => $contact->address_1,
				'address_2' => $contact->address_2,
				'city'      => $contact->city,
				'state'     => $contact->state,
				'country'   => $contact->country,
				'zip'       => $contact->zip,
			),
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		$data['tags']  = self::shape_terms( $contact->tags );
		$data['lists'] = self::shape_terms( $contact->lists );

		$include = array_map( 'strval', (array) ( $input['include'] ?? array() ) );

		if ( in_array( 'relationships', $include, true ) ) {
			$data['relationships'] = self::relationship_counts( (int) $contact->id );
		}

		if ( in_array( 'engagement', $include, true ) ) {
			$data['engagement'] = self::engagement_counts( (int) $contact->id );
		}

		return $data;
	}

	/**
	 * Counts of what this contact is linked to across the CRM.
	 *
	 * Deliberately routed through the other abilities rather than querying
	 * their models directly. Each one applies its own module gate, capability
	 * check, and owner scoping, so a sales rep asking about a contact sees
	 * counts of THEIR invoices, not the company's. Querying the models here
	 * would rebuild all three gates in a second place and drift from them.
	 *
	 * A module that is switched off, or that the caller cannot read, is absent
	 * from the result rather than reported as zero — "none" and "not visible to
	 * you" are different answers and an agent should not conflate them.
	 *
	 * @since 1.0.0
	 *
	 * @param int $contact_id Contact id.
	 * @return array<string, int>
	 */
	private static function relationship_counts( int $contact_id ): array {
		$sections = array(
			'invoices'  => 'doublescale/list-invoices',
			'proposals' => 'doublescale/list-proposals',
			'deals'     => 'doublescale/list-deals',
			'tickets'   => 'doublescale/list-tickets',
			'tasks'     => 'doublescale/list-tasks',
			'bookings'  => 'doublescale/list-bookings',
		);

		$counts = array();

		foreach ( $sections as $key => $ability_name ) {
			if ( ! function_exists( 'wp_get_ability' ) ) {
				break;
			}

			$ability = wp_get_ability( $ability_name );
			if ( ! $ability || true !== $ability->check_permissions() ) {
				continue;
			}

			// limit=1 because only the total is wanted; the rows are discarded.
			$result = $ability->execute(
				array(
					'contact_id' => $contact_id,
					'limit'      => 1,
				)
			);

			if ( is_wp_error( $result ) || ! isset( $result['total'] ) ) {
				continue;
			}

			$counts[ $key ] = (int) $result['total'];
		}

		return $counts;
	}

	/**
	 * Sent, opened, and clicked totals for this contact.
	 *
	 * Routed through get-engagement-summary for the same reason as above, and
	 * absent entirely when the tracking module is off.
	 *
	 * @since 1.0.0
	 *
	 * @param int $contact_id Contact id.
	 * @return array<string, mixed>
	 */
	private static function engagement_counts( int $contact_id ): array {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array();
		}

		$ability = wp_get_ability( 'doublescale/get-engagement-summary' );
		if ( ! $ability || true !== $ability->check_permissions() ) {
			return array();
		}

		$result = $ability->execute( array( 'contact_id' => $contact_id ) );

		if ( is_wp_error( $result ) ) {
			return array();
		}

		return array(
			'sent'       => (int) ( $result['sent'] ?? 0 ),
			'opened'     => (int) ( $result['opened'] ?? 0 ),
			'clicked'    => (int) ( $result['clicked'] ?? 0 ),
			'open_rate'  => $result['open_rate'] ?? null,
			'click_rate' => $result['click_rate'] ?? null,
		);
	}

	/**
	 * List tags and lists.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_segments( array $input ): array {
		$type = isset( $input['type'] ) ? (string) $input['type'] : '';
		$out  = array();

		if ( 'list' !== $type ) {
			$out['tags'] = self::shape_terms( TagModel::query()->orderBy( 'name' )->get() );
		}

		if ( 'tag' !== $type ) {
			$out['lists'] = self::shape_terms( ListModel::query()->orderBy( 'name' )->get() );
		}

		return $out;
	}

	/**
	 * Shape a contact row for list output.
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact Contact.
	 * @return array<string, mixed>
	 */
	private static function shape_summary( $contact ): array {
		$name = trim( (string) $contact->first_name . ' ' . (string) $contact->last_name );

		return array(
			'id'           => (int) $contact->id,
			'name'         => '' !== $name ? $name : (string) $contact->email,
			'email'        => $contact->email,
			'phone'        => $contact->phone,
			'company_name' => $contact->company_name,
			'email_status' => $contact->email_status,
			'created_at'   => $contact->created_at,
		);
	}

	/**
	 * Shape a tag/list collection.
	 *
	 * @since 1.0.0
	 *
	 * @param iterable<object> $terms Terms.
	 * @return array<int, array<string, mixed>>
	 */
	private static function shape_terms( $terms ): array {
		$out = array();

		// NOT (array) — casting an Eloquent Collection yields its internal
		// `items` property as one element, so every term is silently dropped
		// and the agent is told the site has no tags at all.
		foreach ( ( $terms ?? array() ) as $term ) {
			if ( ! is_object( $term ) ) {
				continue;
			}
			$out[] = array(
				'id'   => (int) $term->id,
				'name' => $term->name,
				'slug' => $term->slug ?? null,
			);
		}
		return $out;
	}
}
