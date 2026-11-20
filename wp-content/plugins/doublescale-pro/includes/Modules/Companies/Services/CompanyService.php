<?php
/**
 * Company membership: who belongs to which company, their role, and what of
 * the company's work they see in the client portal.
 *
 * Roles: primary (main contact), billing (copied on the company's invoices
 * and proposals), member. What a member sees is decided by their own portal
 * access (the areas switched on for the contact), never a second list here:
 * with an area on, they see their own records there plus the company's.
 *
 * A record belongs either to a contact (private to them) or to their company
 * (`company_id` set: every member sees it). The contact on a company record
 * is its main contact, who gets the emails and signs.
 *
 * A contact can belong to several companies, with a role in each (the
 * billing person of three companies, an agency owner who is the primary
 * contact of every client company). One of them is their main company
 * (`is_main`, else the oldest membership): it fills the contact's
 * `company_name` text for merge tags and exports, and new deals take it.
 *
 * @package DoubleScale\Modules\Contacts\Services
 */

namespace DoubleScale\Pro\Modules\Companies\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Companies\Models\CompanyModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Portal\Services\PortalAccess;

/**
 * CompanyService class
 */
class CompanyService {

	public const ROLE_PRIMARY = 'primary';
	public const ROLE_BILLING = 'billing';
	public const ROLE_MEMBER  = 'member';

	public const ROLES = array( self::ROLE_PRIMARY, self::ROLE_BILLING, self::ROLE_MEMBER );

	/**
	 * Portal areas a company shares => the contact's portal access feature
	 * that switches the area on.
	 */
	public const AREA_FEATURES = array(
		'projects' => 'projects',
		'tickets'  => 'requests',
		'files'    => 'files',
		'invoices' => 'documents',
	);

	public const AREAS = array( 'projects', 'tickets', 'files', 'invoices' );

	/**
	 * Per-request cache.
	 *
	 * @var array<string, mixed>
	 */
	private static $cache = array();

	/**
	 * Membership table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'doublescale_company_contact';
	}

	/**
	 * Whether the contact's portal access shows an area.
	 *
	 * @param int    $contact_id Contact ID.
	 * @param string $area       One of AREAS.
	 */
	public static function can_see( int $contact_id, string $area ): bool {
		$feature = self::AREA_FEATURES[ $area ] ?? '';
		if ( '' === $feature || ! class_exists( PortalAccess::class ) ) {
			return '' !== $feature;
		}
		$key = 'see:' . $contact_id . ':' . $area;
		if ( ! array_key_exists( $key, self::$cache ) ) {
			$contact             = ContactModel::find( $contact_id );
			self::$cache[ $key ] = $contact && PortalAccess::allows( $feature, $contact );
		}
		return self::$cache[ $key ];
	}

	/**
	 * Areas the contact's portal access shows, of the ones a company shares.
	 *
	 * @param int $contact_id Contact ID.
	 * @return string[]
	 */
	public static function areas_for( int $contact_id ): array {
		return array_values(
			array_filter(
				self::AREAS,
				static function ( $area ) use ( $contact_id ) {
					return self::can_see( $contact_id, $area );
				}
			)
		);
	}

	/**
	 * Companies whose records in an area the contact sees: the companies they
	 * belong to, when their portal access shows that area.
	 *
	 * @param int    $contact_id Contact ID.
	 * @param string $area       One of AREAS.
	 * @return int[]
	 */
	public static function company_ids_for( int $contact_id, string $area ): array {
		if ( ! self::can_see( $contact_id, $area ) ) {
			return array();
		}
		return array_map(
			static function ( $row ) {
				return (int) $row->company_id;
			},
			self::memberships( $contact_id )
		);
	}

	/**
	 * Raw membership rows of a contact (company_id, role, is_main, name),
	 * main company first, then in the order they joined.
	 *
	 * @param int $contact_id Contact ID.
	 * @return object[]
	 */
	public static function memberships( int $contact_id ): array {
		global $wpdb;
		if ( $contact_id <= 0 || ! self::ready() ) {
			return array();
		}
		$table = self::table();
		$key   = 'memberships:' . $contact_id;
		if ( ! isset( self::$cache[ $key ] ) ) {
			$companies = $wpdb->prefix . 'doublescale_companies';
			// Until the release's column sync adds `is_main`, the oldest
			// membership is the main one.
			$main  = self::has_main_column() ? 'm.is_main' : '0';
			$order = self::has_main_column() ? 'm.is_main DESC, m.id ASC' : 'm.id ASC';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.company_id, m.role, {$main} AS is_main, c.name FROM `{$table}` m INNER JOIN `{$companies}` c ON c.id = m.company_id WHERE m.contact_id = %d ORDER BY {$order}", $contact_id ) );
			foreach ( $rows as $i => $row ) {
				$row->is_main = 0 === $i;
			}
			self::$cache[ $key ] = $rows;
		}
		return self::$cache[ $key ];
	}

	/**
	 * Company ids the contact belongs to, main first.
	 *
	 * @param int $contact_id Contact ID.
	 * @return int[]
	 */
	public static function company_ids_of( int $contact_id ): array {
		return array_map(
			static function ( $row ) {
				return (int) $row->company_id;
			},
			self::memberships( $contact_id )
		);
	}

	/**
	 * The contact's main company, or null.
	 *
	 * @param int $contact_id Contact ID.
	 * @return CompanyModel|null
	 */
	public static function company_of( int $contact_id ) {
		$rows = self::memberships( $contact_id );
		if ( empty( $rows ) ) {
			return null;
		}
		return CompanyModel::find( (int) $rows[0]->company_id );
	}

	/**
	 * Email providers anyone can sign up with: their domain never names a company.
	 */
	private const PUBLIC_EMAIL_DOMAINS = array(
		'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'hotmail.com', 'outlook.com', 'live.com', 'msn.com',
		'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'pm.me', 'gmx.com', 'gmx.de',
		'gmx.net', 'web.de', 'mail.com', 'yandex.com', 'yandex.ru', 'zoho.com', 'hey.com', 'fastmail.com', 'qq.com',
		'163.com', '126.com', 'mail.ru', 'tutanota.com', 'hotmail.co.uk', 'yahoo.co.uk', 'outlook.de', 'free.fr', 'orange.fr',
	);

	/**
	 * A company domain as stored: lower case, no scheme, "www." or path.
	 *
	 * @param string $domain Domain, URL or email address.
	 */
	public static function normalize_domain( string $domain ): string {
		$domain = strtolower( trim( $domain ) );
		if ( str_contains( $domain, '@' ) ) {
			$domain = substr( $domain, strrpos( $domain, '@' ) + 1 );
		}
		$domain = preg_replace( '#^[a-z]+://#', '', $domain );
		$domain = preg_replace( '#^www\.#', '', (string) $domain );
		$domain = preg_replace( '#[/?:].*$#', '', (string) $domain );
		return (string) $domain;
	}

	/**
	 * Whether a domain belongs to a public email provider.
	 *
	 * @param string $domain Normalized domain.
	 */
	public static function is_public_domain( string $domain ): bool {
		/**
		 * Filters the email domains that never match a company (public providers).
		 *
		 * @param string[] $domains Domains.
		 */
		$public = (array) apply_filters( 'doublescale_company_public_email_domains', self::PUBLIC_EMAIL_DOMAINS );
		return in_array( $domain, $public, true );
	}

	/**
	 * The one company whose domain matches an email address, or null (no
	 * match, a public provider, or several companies share the domain).
	 *
	 * @param string $email Email address.
	 * @return CompanyModel|null
	 */
	public static function company_for_email( string $email ) {
		$domain = self::normalize_domain( $email );
		if ( '' === $domain || ! str_contains( $email, '@' ) || self::is_public_domain( $domain ) ) {
			return null;
		}
		$matches = CompanyModel::where( 'domain', $domain )->limit( 2 )->get();
		return 1 === $matches->count() ? $matches->first() : null;
	}

	/**
	 * Contacts with the company's email domain who belong to no company yet.
	 *
	 * @param int $company_id Company ID.
	 * @param int $limit      Most to return.
	 * @return array<int, array<string, mixed>>
	 */
	public static function domain_matches( int $company_id, int $limit = 20 ): array {
		global $wpdb;
		$company = CompanyModel::find( $company_id );
		$domain  = $company ? self::normalize_domain( (string) $company->domain ) : '';
		if ( '' === $domain || self::is_public_domain( $domain ) || ! self::ready() ) {
			return array();
		}
		$contacts = $wpdb->prefix . 'doublescale_contacts';
		$table    = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.first_name, c.last_name, c.email FROM `{$contacts}` c LEFT JOIN `{$table}` m ON m.contact_id = c.id WHERE m.id IS NULL AND LOWER(c.email) LIKE %s ORDER BY c.first_name ASC LIMIT %d", '%@' . $wpdb->esc_like( $domain ), $limit ) );
		return array_map(
			static function ( $row ) {
				return array(
					'contact_id' => (int) $row->id,
					'first_name' => (string) $row->first_name,
					'last_name'  => (string) $row->last_name,
					'email'      => (string) $row->email,
				);
			},
			$rows
		);
	}

	/**
	 * Hook new contacts into their company.
	 */
	public static function register(): void {
		add_action( 'doublescale_contact_created', array( self::class, 'place_new_contact' ) );

		// Free's records, portal and emails ask about companies through filters.
		add_filter( 'doublescale_record_company_id', static fn( $id, $contact_id, $requested ) => self::record_company_id( $contact_id, $requested ), 10, 3 );
		add_filter( 'doublescale_portal_scope_company_ids', static fn( $ids, $contact_id, $area ) => array_merge( (array) $ids, self::company_ids_for( (int) $contact_id, (string) $area ) ), 10, 3 );
		add_filter( 'doublescale_portal_company', array( self::class, 'portal_company' ), 10, 2 );
		add_filter( 'doublescale_document_cc', static fn( $cc, $contact_id, $company_id, $to ) => array_merge( (array) $cc, self::billing_cc( $contact_id, $company_id, (string) $to ) ), 10, 4 );
		add_filter( 'doublescale_support_company_cc', array( self::class, 'ticket_cc' ), 10, 3 );
		add_filter( 'doublescale_portal_notification_recipients', array( self::class, 'portal_recipients' ), 10, 3 );
	}

	/**
	 * A new contact joins its company: the one named in its company name, else
	 * the one whose domain matches its email, else a company created from the
	 * name it came with (as the upgrade did for existing contacts).
	 *
	 * @param ContactModel $contact New contact.
	 * @return void
	 */
	public static function place_new_contact( $contact ): void {
		if ( ! $contact instanceof ContactModel || ! self::ready() || self::memberships( (int) $contact->id ) ) {
			return;
		}
		/**
		 * Filters whether a new contact is placed in a company automatically.
		 *
		 * @param bool         $place   Place it.
		 * @param ContactModel $contact Contact.
		 */
		if ( ! apply_filters( 'doublescale_company_place_new_contact', true, $contact ) ) {
			return;
		}

		$name    = trim( (string) $contact->company_name );
		$company = '' !== $name ? CompanyModel::whereRaw( 'LOWER(TRIM(name)) = ?', array( strtolower( $name ) ) )->first() : null;
		if ( ! $company ) {
			$company = self::company_for_email( (string) $contact->email );
		}
		if ( ! $company && '' !== $name ) {
			$company = CompanyModel::create( array( 'name' => $name ) );
		}
		if ( $company ) {
			self::add_contact_company( (int) $contact->id, (int) $company->id );
		}
	}

	/**
	 * Whether a contact is a member of a company.
	 *
	 * @param int $company_id Company ID.
	 * @param int $contact_id Contact ID.
	 */
	public static function is_member( int $company_id, int $contact_id ): bool {
		foreach ( self::memberships( $contact_id ) as $row ) {
			if ( (int) $row->company_id === $company_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The company_id to store on a record (project, ticket, invoice): the
	 * requested company when the record's contact belongs to it, else null.
	 *
	 * @param int|null $contact_id Record contact.
	 * @param mixed    $requested  Requested company id (0/empty = not shared).
	 * @return int|null
	 */
	public static function record_company_id( $contact_id, $requested ): ?int {
		$company_id = (int) $requested;
		if ( $company_id <= 0 || (int) $contact_id <= 0 ) {
			return null;
		}
		return self::is_member( $company_id, (int) $contact_id ) ? $company_id : null;
	}

	/**
	 * Add a contact to a company, keeping their other companies. Already a
	 * member: only the role changes (when given). Their first company becomes
	 * their main one.
	 *
	 * @param int         $contact_id Contact ID.
	 * @param int         $company_id Company ID.
	 * @param string|null $role       Role; null keeps the current role (new members: primary when the company has none, else member).
	 * @return bool Whether the contact is a member now.
	 */
	public static function add_contact_company( int $contact_id, int $company_id, ?string $role = null ): bool {
		global $wpdb;
		$company = $company_id > 0 && $contact_id > 0 && self::ready() ? CompanyModel::find( $company_id ) : null;
		if ( ! $company ) {
			return false;
		}
		if ( self::is_member( $company_id, $contact_id ) ) {
			if ( null !== $role ) {
				self::update_member( $company_id, $contact_id, array( 'role' => $role ) );
			}
			return true;
		}

		$role = in_array( $role, self::ROLES, true ) ? $role : self::first_role( $company_id );
		if ( self::ROLE_PRIMARY === $role ) {
			// Only one primary contact per company.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( self::table(), array( 'role' => self::ROLE_MEMBER ), array( 'company_id' => $company_id, 'role' => self::ROLE_PRIMARY ) );
		}
		$row = array(
			'company_id' => $company_id,
			'contact_id' => $contact_id,
			'role'       => $role,
			'created_at' => current_time( 'mysql', true ),
		);
		if ( self::has_main_column() ) {
			$row['is_main'] = self::memberships( $contact_id ) ? 0 : 1;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( self::table(), $row );
		self::flush();
		self::sync_company_name( $contact_id );

		/**
		 * Fires after a contact joins or leaves a company.
		 *
		 * @param int $contact_id Contact ID.
		 * @param int $company_id The company joined (0 when one was left).
		 */
		do_action( 'doublescale_contact_company_changed', $contact_id, $company_id );
		return true;
	}

	/**
	 * Take a contact out of one company; their other companies stay. The
	 * company's records they were the main contact of get its next person.
	 * When it was their main company, the next one becomes main.
	 *
	 * @param int $contact_id Contact ID.
	 * @param int $company_id Company ID.
	 * @return void
	 */
	public static function remove_contact_company( int $contact_id, int $company_id ): void {
		global $wpdb;
		if ( ! self::is_member( $company_id, $contact_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			self::table(),
			array(
				'company_id' => $company_id,
				'contact_id' => $contact_id,
			)
		);
		// The company's records stay with the company and get another main
		// contact; their own records stay theirs.
		self::hand_over_company_records( $contact_id, $company_id );
		self::flush();

		$rest = self::memberships( $contact_id );
		if ( $rest ) {
			self::set_main_company( $contact_id, (int) $rest[0]->company_id );
		} else {
			self::sync_company_name( $contact_id );
		}

		/** This action is documented in add_contact_company(). */
		do_action( 'doublescale_contact_company_changed', $contact_id, 0 );
	}

	/**
	 * Make one of the contact's companies their main one.
	 *
	 * @param int $contact_id Contact ID.
	 * @param int $company_id Company ID (one they belong to).
	 * @return bool Whether it is their main company now.
	 */
	public static function set_main_company( int $contact_id, int $company_id ): bool {
		global $wpdb;
		if ( ! self::is_member( $company_id, $contact_id ) ) {
			return false;
		}
		if ( self::has_main_column() ) {
			$table = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET is_main = IF(company_id = %d, 1, 0) WHERE contact_id = %d", $company_id, $contact_id ) );
			self::flush();
		}
		self::sync_company_name( $contact_id );
		return true;
	}

	/**
	 * The contact's `company_name` text follows their main company.
	 *
	 * @param int $contact_id Contact ID.
	 * @return void
	 */
	public static function sync_company_name( int $contact_id ): void {
		$rows = self::memberships( $contact_id );
		ContactModel::where( 'id', $contact_id )->update( array( 'company_name' => $rows ? (string) $rows[0]->name : null ) );
	}

	/**
	 * Make a company the contact's main one, joining it first when needed;
	 * 0 takes them out of their main company. Their other companies stay.
	 * (Older callers and the single-company `contacts/{id}/company` route.)
	 *
	 * @param int         $contact_id Contact ID.
	 * @param int         $company_id Company ID; 0 removes the contact from their main company.
	 * @param string|null $role       Role; null keeps the current role (or the default).
	 * @return void
	 */
	public static function set_contact_company( int $contact_id, int $company_id, ?string $role = null ): void {
		if ( $company_id <= 0 ) {
			$main = self::company_of( $contact_id );
			if ( $main ) {
				self::remove_contact_company( $contact_id, (int) $main->id );
			}
			return;
		}
		if ( self::add_contact_company( $contact_id, $company_id, $role ) ) {
			self::set_main_company( $contact_id, $company_id );
		}
	}

	/**
	 * Update a member's role.
	 *
	 * @param int                  $company_id Company ID.
	 * @param int                  $contact_id Contact ID.
	 * @param array<string, mixed> $changes    `role`.
	 * @return void
	 */
	public static function update_member( int $company_id, int $contact_id, array $changes ): void {
		global $wpdb;
		$data = array();
		if ( isset( $changes['role'] ) && in_array( $changes['role'], self::ROLES, true ) ) {
			$data['role'] = $changes['role'];
		}
		if ( empty( $data ) ) {
			return;
		}
		// Only one primary contact per company.
		if ( isset( $data['role'] ) && self::ROLE_PRIMARY === $data['role'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( self::table(), array( 'role' => self::ROLE_MEMBER ), array( 'company_id' => $company_id, 'role' => self::ROLE_PRIMARY ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::table(), $data, array( 'company_id' => $company_id, 'contact_id' => $contact_id ) );
		self::flush();
	}

	/**
	 * Members of a company with their role and the areas their portal access shows.
	 *
	 * @param int $company_id Company ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function members( int $company_id ): array {
		global $wpdb;
		$table    = self::table();
		$contacts = $wpdb->prefix . 'doublescale_contacts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.contact_id, m.role, c.first_name, c.last_name, c.email, c.avatar_id FROM `{$table}` m INNER JOIN `{$contacts}` c ON c.id = m.contact_id WHERE m.company_id = %d ORDER BY FIELD(m.role, 'primary', 'billing', 'member'), c.first_name ASC", $company_id ) );

		return array_map(
			static function ( $row ) {
				return array(
					'contact_id' => (int) $row->contact_id,
					'first_name' => (string) $row->first_name,
					'last_name'  => (string) $row->last_name,
					'email'      => (string) $row->email,
					'avatar_url' => $row->avatar_id ? ( wp_get_attachment_image_url( (int) $row->avatar_id, 'thumbnail' ) ?: null ) : null,
					'role'       => (string) $row->role,
					'access'     => self::areas_for( (int) $row->contact_id ),
				);
			},
			$rows
		);
	}

	/**
	 * Contact ids of a company's members with a given role.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $role       Role.
	 * @return int[]
	 */
	public static function member_ids_with_role( int $company_id, string $role ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT contact_id FROM `{$table}` WHERE company_id = %d AND role = %s", $company_id, $role ) ) );
	}

	/**
	 * The signed-in client's companies for the portal: the main one's name,
	 * every company they belong to, and the areas where they see company work
	 * (their own portal access).
	 *
	 * @param array|null $company    Earlier answer.
	 * @param int        $contact_id Contact ID.
	 * @return array{name: string, areas: string[], companies: array<int, array{id: int, name: string}>}|null
	 */
	public static function portal_company( $company, $contact_id ) {
		$rows = self::memberships( (int) $contact_id );
		if ( empty( $rows ) ) {
			return $company;
		}

		// `name` is the main company (older free shows only that one);
		// `companies` lists every one, main first.
		return array(
			'name'      => (string) $rows[0]->name,
			'areas'     => self::areas_for( (int) $contact_id ),
			'companies' => array_map(
				static function ( $row ) {
					return array(
						'id'   => (int) $row->company_id,
						'name' => (string) $row->name,
					);
				},
				$rows
			),
		);
	}

	/**
	 * Colleagues copied on a company ticket's emails: members who see the
	 * company's tickets and either replied on this one or chose to follow all
	 * of them. Never the whole company.
	 *
	 * @param string[]                                        $cc     Addresses so far.
	 * @param \DoubleScale\Modules\Support\Models\TicketModel $ticket Ticket.
	 * @param string                                          $to     The requester's address.
	 * @return string[]
	 */
	public static function ticket_cc( $cc, $ticket, $to = '' ): array {
		$cc = (array) $cc;
		foreach ( self::ticket_followers( $ticket ) as $member ) {
			$email = strtolower( trim( $member['email'] ) );
			if ( is_email( $email ) && strtolower( (string) $to ) !== $email ) {
				$cc[] = $email;
			}
		}
		return $cc;
	}

	/**
	 * Colleagues who follow a company ticket: members who see the company's
	 * tickets and replied on it or chose to follow all of them.
	 *
	 * @param object $ticket Ticket.
	 * @return array<int, array<string, mixed>> Members (see members()).
	 */
	public static function ticket_followers( $ticket ): array {
		$company_id = (int) ( $ticket->company_id ?? 0 );
		if ( $company_id <= 0 ) {
			return array();
		}

		$repliers = array();
		$replies  = \DoubleScale\Modules\Activities\Models\ActivityModel::forTicket( (int) $ticket->id )
			->where( 'activity_type', \DoubleScale\Core\Constants\ActivityTypes::SUPPORT_REPLY )
			->whereNull( 'user_id' )
			->get();
		foreach ( $replies as $reply ) {
			$data = is_array( $reply->data ) ? $reply->data : array();
			if ( ! empty( $data['from_email'] ) ) {
				$repliers[ strtolower( trim( (string) $data['from_email'] ) ) ] = true;
			}
		}

		$prefs     = \DoubleScale\Modules\Portal\Services\PortalEmailPreferences::class;
		$followers = array();
		foreach ( self::members( $company_id ) as $member ) {
			if ( (int) $member['contact_id'] === (int) ( $ticket->contact_id ?? 0 ) || ! in_array( 'tickets', $member['access'], true ) ) {
				continue;
			}
			if ( isset( $repliers[ strtolower( trim( $member['email'] ) ) ] )
				|| ( class_exists( $prefs ) && $prefs::EVERY === $prefs::mode( $member['contact_id'], $prefs::COMPANY_TICKETS ) ) ) {
				$followers[] = $member;
			}
		}
		return $followers;
	}

	/**
	 * Portal notifications about a company record: ticket replies reach the
	 * colleagues who follow the ticket, new invoices and proposals reach the
	 * billing contacts (the same people the emails copy).
	 *
	 * @param int[]  $ids    Contact ids.
	 * @param string $type   Notification type.
	 * @param object $record Ticket or document.
	 * @return int[]
	 */
	public static function portal_recipients( $ids, $type, $record ): array {
		$ids = (array) $ids;
		if ( 'ticket_reply' === $type ) {
			foreach ( self::ticket_followers( $record ) as $member ) {
				$ids[] = (int) $member['contact_id'];
			}
		} elseif ( in_array( $type, array( 'invoice_sent', 'proposal_sent' ), true ) && ! empty( $record->company_id ) ) {
			foreach ( self::members( (int) $record->company_id ) as $member ) {
				if ( self::ROLE_BILLING === $member['role'] && in_array( 'invoices', $member['access'], true ) ) {
					$ids[] = (int) $member['contact_id'];
				}
			}
		}
		return $ids;
	}

	/**
	 * Addresses to copy on a company invoice or proposal: the members with the
	 * billing role who see invoices, without the record's own recipient.
	 *
	 * @param int|null $contact_id Record contact.
	 * @param int|null $company_id Record company_id (null = not set to one).
	 * @param string   $to         The recipient already on the email.
	 * @return string[]
	 */
	public static function billing_cc( $contact_id, $company_id, string $to ): array {
		unset( $contact_id );
		$company_id = self::ready() ? (int) $company_id : 0;
		if ( $company_id <= 0 ) {
			return array();
		}
		$cc = array();
		foreach ( self::members( (int) $company_id ) as $member ) {
			$email = strtolower( trim( $member['email'] ) );
			if ( self::ROLE_BILLING === $member['role'] && in_array( 'invoices', $member['access'], true )
				&& is_email( $email ) && strtolower( trim( $to ) ) !== $email ) {
				$cc[] = $email;
			}
		}
		return array_values( array_unique( $cc ) );
	}

	/**
	 * Tables with a `company_id` column (and a `contact_id`).
	 *
	 * @return string[] Table names with the WP prefix, existing ones only.
	 */
	private static function record_tables(): array {
		global $wpdb;
		/**
		 * Tables with a `company_id` column to clear when a company is deleted
		 * or a member leaves it.
		 *
		 * @param string[] $tables Table names without the WP prefix.
		 */
		$names  = (array) apply_filters(
			'doublescale_company_tables',
			array( 'doublescale_support_tickets', 'doublescale_sales_invoices', 'doublescale_sales_proposals', 'doublescale_attachments' )
		);
		$tables = array();
		foreach ( $names as $name ) {
			$table = $wpdb->prefix . $name;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$tables[] = $table;
			}
		}
		return $tables;
	}

	/**
	 * The company's records whose main contact is leaving get the company's
	 * next contact (primary, else billing, else any member). With nobody
	 * left, they become the leaver's own records again. Files and deals keep
	 * their person and stay with the company.
	 *
	 * @param int $contact_id Contact leaving.
	 * @param int $company_id The company they leave.
	 */
	private static function hand_over_company_records( int $contact_id, int $company_id ): void {
		global $wpdb;
		$next = 0;
		foreach ( array( self::ROLE_PRIMARY, self::ROLE_BILLING, self::ROLE_MEMBER ) as $role ) {
			$ids = array_diff( self::member_ids_with_role( $company_id, $role ), array( $contact_id ) );
			if ( $ids ) {
				$next = (int) reset( $ids );
				break;
			}
		}
		foreach ( self::record_tables() as $table ) {
			// Files keep their uploader and deals their person: both stay with
			// the company as they are.
			if ( in_array( $table, array( $wpdb->prefix . 'doublescale_attachments', $wpdb->prefix . 'doublescale_deals' ), true ) ) {
				continue;
			}
			$where = array(
				'company_id' => $company_id,
				'contact_id' => $contact_id,
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $next ? array( 'contact_id' => $next ) : array( 'company_id' => null ), $where );
		}

		/**
		 * Fires after a leaving member's company records got a new main contact.
		 *
		 * @param int $contact_id Contact who left.
		 * @param int $company_id Company.
		 * @param int $next       New main contact (0: records went back to the leaver).
		 */
		do_action( 'doublescale_company_records_handed_over', $contact_id, $company_id, $next );
	}

	/**
	 * Delete a company: memberships go, shared records fall back to their contact.
	 *
	 * @param int $company_id Company ID.
	 * @return void
	 */
	public static function delete_company( int $company_id ): void {
		global $wpdb;
		$member_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT contact_id FROM `' . self::table() . '` WHERE company_id = %d', $company_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'company_id' => $company_id ) );

		foreach ( self::record_tables() as $table ) {
			$wpdb->update( $table, array( 'company_id' => null ), array( 'company_id' => $company_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		// Members' company_name text follows their next main company (or none).
		self::flush();
		foreach ( $member_ids as $member_id ) {
			self::sync_company_name( $member_id );
		}

		// The company's own files go with it.
		CompanyFiles::delete_all( $company_id );

		CompanyModel::where( 'id', $company_id )->delete();
		self::flush();

		do_action( 'doublescale_company_deleted', $company_id );
	}

	/**
	 * First member becomes primary; later ones are members.
	 *
	 * @param int $company_id Company ID.
	 */
	private static function first_role( int $company_id ): string {
		return empty( self::member_ids_with_role( $company_id, self::ROLE_PRIMARY ) ) ? self::ROLE_PRIMARY : self::ROLE_MEMBER;
	}

	/**
	 * Whether the membership table has `is_main` yet (added by the release's
	 * column sync; until then the oldest membership is the main one).
	 */
	private static function has_main_column(): bool {
		// Only a yes is kept: a column added later in the request still counts.
		static $has = false;
		if ( ! $has && self::ready() ) {
			global $wpdb;
			$table = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has = ! empty( $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'is_main' ) ) );
		}
		return $has;
	}

	/**
	 * Whether the membership table exists (older installs mid-upgrade).
	 */
	private static function ready(): bool {
		static $ready = null;
		if ( null === $ready ) {
			global $wpdb;
			$table = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}
		return $ready;
	}

	/**
	 * Clear the per-request cache.
	 */
	public static function flush(): void {
		self::$cache = array();
	}
}
