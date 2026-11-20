<?php
/**
 * Customer file uploads from the Client Portal.
 *
 * A portal upload is an ordinary contact attachment (`attachable_type`
 * `contact`) whose uploader is the contact itself: `contact_id` is set and
 * `user_id` stays null. That pair is what tells the team "the customer sent
 * this" and what scopes every customer read and delete — a customer only ever
 * removes files they uploaded. The team's files stay internal (404) unless the
 * team turns on "Visible to client" (`client_visible` in the meta): a file on
 * the contact then shows to them, one on their company to all its members.
 *
 * Uploads are treated as hostile:
 *   - the type is decided by the file's CONTENT (WordPress's own
 *     `wp_check_filetype_and_ext()`), not by its name or the browser's claim,
 *     and only types from {@see PortalUploadSettings} pass;
 *   - files are written under a random name to a folder that denies web
 *     access and script execution; downloads go through signed links only;
 *   - a per-customer file cap and hourly attempt limit bound disk use.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use WP_Error;

/**
 * PortalFileService.
 */
final class PortalFileService {

	/** Attachments belong to the contact record. */
	public const ATTACHABLE_TYPE = 'contact';

	/** Files the team keeps on a company (DoubleScale Pro's company page). */
	public const COMPANY_ATTACHABLE_TYPE = 'company';

	/** Uploads folder for customer files (locked by .htaccess + index.html). */
	public const STORAGE_BASE = 'doublescale-portal-uploads';

	/** Longest original file name kept, before the extension. */
	private const MAX_NAME_LENGTH = 150;

	/**
	 * @var AttachmentService
	 */
	private $attachments;

	public function __construct() {
		$this->attachments = new AttachmentService();
	}

	/**
	 * Transient key counting a customer's upload attempts this hour.
	 *
	 * @param int $contact_id Contact ID.
	 * @return string
	 */
	public static function rate_limit_key( int $contact_id ): string {
		return 'doublescale_portal_uploads_' . $contact_id;
	}

	/**
	 * The customer's own files, newest first, shaped for the portal.
	 *
	 * @param ContactModel $contact Current customer.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_for_contact( ContactModel $contact ): array {
		$rows = $this->visible_files( (int) $contact->id )->orderBy( 'created_at', 'desc' )->orderBy( 'id', 'desc' )->get();

		$names = $this->uploader_names( $rows, (int) $contact->id );
		$items = array();
		foreach ( $rows as $attachment ) {
			$item              = $this->shape( $attachment );
			$is_mine           = (int) $attachment->contact_id === (int) $contact->id;
			$item['is_mine']   = $is_mine;
			$item['shared']    = ! empty( $attachment->company_id );
			$item['company_id'] = $attachment->company_id ? (int) $attachment->company_id : null;
			$item['uploader']  = $is_mine ? null : ( $names[ (int) $attachment->contact_id ] ?? null );
			$item['from_team'] = false;
			$items[]           = $item;
		}

		// Files the team chose to show: on the contact, or on their company.
		foreach ( $this->team_files( (int) $contact->id ) as $attachment ) {
			$item              = $this->shape( $attachment );
			$item['is_mine']   = false;
			$item['shared']    = self::COMPANY_ATTACHABLE_TYPE === (string) $attachment->attachable_type;
			$item['company_id'] = $item['shared'] ? (int) $attachment->attachable_id : null;
			$item['uploader']  = null;
			$item['from_team'] = true;
			$items[]           = $item;
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( (string) $b['created_at'], (string) $a['created_at'] ) ?: $b['id'] <=> $a['id'];
			}
		);
		return $items;
	}

	/**
	 * Whether the team shows a file in the client portal (a flag in its meta,
	 * as on project files).
	 *
	 * @param AttachmentModel $attachment Attachment.
	 * @return bool
	 */
	public static function is_client_visible( AttachmentModel $attachment ): bool {
		$meta = is_array( $attachment->meta ) ? $attachment->meta : array();
		return ! empty( $meta['client_visible'] );
	}

	/**
	 * Show or hide a team file in the client portal.
	 *
	 * @param AttachmentModel $attachment Attachment.
	 * @param bool            $visible    Show it.
	 * @return bool Whether it was just shared (hidden before).
	 */
	public static function set_client_visible( AttachmentModel $attachment, bool $visible ): bool {
		// Assign the whole array: a key set through the cast property is not saved.
		$meta         = is_array( $attachment->meta ) ? $attachment->meta : array();
		$newly_shared = $visible && empty( $meta['client_visible'] );
		if ( $newly_shared ) {
			$meta['client_visible_at'] = current_time( 'mysql', true );
		}
		$meta['client_visible'] = $visible;
		$attachment->meta       = $meta;
		$attachment->save();
		return $newly_shared;
	}

	/**
	 * Tell clients in their portal's bell that the team shared a file.
	 *
	 * @param AttachmentModel $attachment  The file.
	 * @param int[]           $contact_ids Who sees it.
	 * @return void
	 */
	public static function notify_shared( AttachmentModel $attachment, array $contact_ids ): void {
		foreach ( array_unique( array_map( 'intval', $contact_ids ) ) as $contact_id ) {
			PortalNotifications::add(
				$contact_id,
				array(
					'category' => PortalNotifications::TEAM_UPDATES,
					'type'     => 'shared_file',
					'area'     => 'files',
					'title'    => __( 'New file from our team', 'doublescale' ),
					'body'     => (string) $attachment->file_name,
					'route'    => '/files',
				)
			);
		}
	}

	/**
	 * How many files the customer has uploaded and kept.
	 *
	 * @param int $contact_id Contact ID.
	 * @return int
	 */
	public function count_for_contact( int $contact_id ): int {
		return (int) $this->own_files( $contact_id )->count();
	}

	/**
	 * Limits the portal shows next to the upload button.
	 *
	 * @param ContactModel|null $contact Current customer, or null (no contact).
	 * @return array<string, mixed>
	 */
	public function policy_for_contact( ?ContactModel $contact ): array {
		$allowed = PortalUploadSettings::allowed_extensions();
		$used    = $contact ? $this->count_for_contact( (int) $contact->id ) : 0;

		return array(
			'allowed_extensions'  => $allowed,
			'max_file_size_bytes' => PortalUploadSettings::max_file_size_bytes(),
			'max_file_count'      => PortalUploadSettings::MAX_FILE_COUNT,
			'used_count'          => $used,
			'can_upload'          => null !== $contact && ! empty( $allowed ) && $used < PortalUploadSettings::MAX_FILE_COUNT,
		);
	}

	/**
	 * Store a customer upload on their own contact record.
	 *
	 * @param ContactModel              $contact Current customer.
	 * @param array<string, mixed>|null $file    PHP upload array (`$_FILES['file']`).
	 * @param bool                      $share   Show it to the customer's company too.
	 * @param int                       $company Which of their companies (0: their main one).
	 * @return AttachmentModel|WP_Error
	 */
	public function store( ContactModel $contact, ?array $file, bool $share = false, int $company = 0 ) {
		$contact_id = (int) $contact->id;

		$limited = $this->hit_rate_limit( $contact_id );
		if ( $limited ) {
			return $limited;
		}

		if ( $this->count_for_contact( $contact_id ) >= PortalUploadSettings::MAX_FILE_COUNT ) {
			return new WP_Error(
				'too_many_files',
				sprintf(
					/* translators: %d: maximum number of files a customer can upload */
					__( 'You can upload up to %d files. Remove one to add another.', 'doublescale' ),
					PortalUploadSettings::MAX_FILE_COUNT
				),
				array( 'status' => 400 )
			);
		}

		$checked_upload = $this->check_upload( $file );
		if ( is_wp_error( $checked_upload ) ) {
			return $checked_upload;
		}

		$checked = $this->check_file( (string) $file['tmp_name'], (string) ( $file['name'] ?? '' ) );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$file['name'] = $checked['name'];

		$attachment = $this->attachments->store_upload(
			$file,
			self::ATTACHABLE_TYPE,
			$contact_id,
			array_filter(
				array(
					'contact_id' => $contact_id,
					// Shared with the member's company only when they ask to.
					'company_id' => $share ? PortalScope::share_company( $contact_id, 'files', $company ) : null,
				)
			),
			array(
				'status'             => 'active',
				'storage_subdir'     => self::STORAGE_BASE . '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' ),
				'protected_base_dir' => self::STORAGE_BASE,
				'randomize_name'     => true,
				'max_size_bytes'     => PortalUploadSettings::max_file_size_bytes(),
				'meta'               => array(
					'contact_id' => $contact_id,
					'source'     => 'client_portal',
				),
			)
		);

		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		do_action( 'doublescale_contact_file_attached', $contact, $attachment, array( 'actor' => 'contact' ) );

		return $attachment;
	}

	/**
	 * Delete one of the customer's own files.
	 *
	 * @param ContactModel $contact       Current customer.
	 * @param int          $attachment_id Attachment row ID.
	 * @return true|WP_Error 404 for anything that is not theirs.
	 */
	public function delete( ContactModel $contact, int $attachment_id ) {
		$attachment = $this->own_files( (int) $contact->id )->where( 'id', $attachment_id )->first();
		if ( ! $attachment instanceof AttachmentModel ) {
			return PortalIdentity::not_found( __( 'File not found.', 'doublescale' ) );
		}

		do_action( 'doublescale_contact_file_removed', $contact, $attachment, array( 'actor' => 'contact' ) );

		$attachment->delete();

		return true;
	}

	/**
	 * Check a file's real type against the allowed list.
	 *
	 * The name only supplies the extension to test; the content must match it
	 * (images via their header, everything else via fileinfo). A PNG named
	 * `.jpg` comes back renamed to `.png`, if PNG is allowed.
	 *
	 * @param string                     $path    Absolute path to the file.
	 * @param string                     $name    Original file name.
	 * @param array<string, string>|null $allowed Extension → MIME; defaults to the policy.
	 * @return array{name: string, ext: string, mime: string}|WP_Error
	 */
	public function check_file( string $path, string $name, ?array $allowed = null ) {
		$allowed = null === $allowed ? PortalUploadSettings::allowed_mimes() : $allowed;

		$name = $this->clean_name( $name );
		if ( '' === $name ) {
			return $this->type_error();
		}

		if ( empty( $allowed ) || ! is_file( $path ) ) {
			return $this->type_error();
		}

		$checked = wp_check_filetype_and_ext( $path, $name, $allowed );
		$ext     = strtolower( (string) ( $checked['ext'] ?? '' ) );
		$mime    = (string) ( $checked['type'] ?? '' );

		if ( '' === $ext || '' === $mime || ! isset( $allowed[ $ext ] ) || $allowed[ $ext ] !== $mime ) {
			return $this->type_error();
		}

		if ( ! empty( $checked['proper_filename'] ) ) {
			$name = $this->clean_name( (string) $checked['proper_filename'] );
		}

		return array(
			'name' => $name,
			'ext'  => $ext,
			'mime' => $mime,
		);
	}

	/**
	 * Shape one file for the portal (no storage path, no internal ids).
	 *
	 * @param AttachmentModel $attachment Attachment.
	 * @return array<string, mixed>
	 */
	public function shape( AttachmentModel $attachment ): array {
		return array(
			'id'         => (int) $attachment->id,
			'file_name'  => (string) $attachment->file_name,
			'file_size'  => (int) $attachment->file_size,
			'file_type'  => (string) $attachment->file_type,
			'created_at' => $attachment->created_at ? (string) $attachment->created_at : null,
			'url'        => $this->attachments->signed_url( $attachment ),
		);
	}

	/**
	 * Files the customer uploaded themselves: on their contact, by their
	 * contact, with no team member as uploader.
	 *
	 * @param int $contact_id Contact ID.
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	private function visible_files( int $contact_id ) {
		$companies = PortalScope::company_ids( $contact_id, 'files' );
		if ( empty( $companies ) ) {
			return $this->own_files( $contact_id );
		}

		// The client's own uploads, plus colleagues' uploads shared with the
		// company: never the files the team keeps on a record.
		return AttachmentModel::forType( self::ATTACHABLE_TYPE )
			->whereNull( 'user_id' )
			->where(
				static function ( $q ) use ( $contact_id, $companies ) {
					$q->where(
						static function ( $own ) use ( $contact_id ) {
							$own->where( 'attachable_id', $contact_id )->where( 'contact_id', $contact_id );
						}
					)->orWhereIn( 'company_id', $companies );
				}
			)
			->active();
	}

	/**
	 * Files the team added and chose to show: on the contact (them only), or
	 * on a company they belong to with the Files area (all its members).
	 *
	 * @param int $contact_id Contact ID.
	 * @return AttachmentModel[]
	 */
	private function team_files( int $contact_id ): array {
		$companies = PortalScope::company_ids( $contact_id, 'files' );
		$rows      = AttachmentModel::query()
			->whereNotNull( 'user_id' )
			->where(
				static function ( $q ) use ( $contact_id, $companies ) {
					$q->where(
						static function ( $own ) use ( $contact_id ) {
							$own->where( 'attachable_type', self::ATTACHABLE_TYPE )->where( 'attachable_id', $contact_id );
						}
					);
					if ( $companies ) {
						$q->orWhere(
							static function ( $company ) use ( $companies ) {
								$company->where( 'attachable_type', self::COMPANY_ATTACHABLE_TYPE )->whereIn( 'attachable_id', $companies );
							}
						);
					}
				}
			)
			->active()
			->get();

		return array_values( array_filter( $rows->all(), array( self::class, 'is_client_visible' ) ) );
	}

	/**
	 * Colleagues' display names for files they shared with the company.
	 *
	 * @param iterable<AttachmentModel> $rows       Files.
	 * @param int                       $contact_id Current customer.
	 * @return array<int, string>
	 */
	private function uploader_names( $rows, int $contact_id ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			if ( (int) $row->contact_id !== $contact_id && $row->contact_id ) {
				$ids[] = (int) $row->contact_id;
			}
		}
		if ( ! $ids ) {
			return array();
		}
		$names = array();
		foreach ( ContactModel::whereIn( 'id', array_unique( $ids ) )->get( array( 'id', 'first_name', 'last_name', 'email' ) ) as $c ) {
			$name                    = trim( $c->first_name . ' ' . $c->last_name );
			$names[ (int) $c->id ] = '' !== $name ? $name : (string) $c->email;
		}
		return $names;
	}

	/**
	 * Files the customer uploaded themselves, which they may delete and which
	 * count toward their upload limit.
	 *
	 * @param int $contact_id Contact ID.
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	private function own_files( int $contact_id ) {
		return AttachmentModel::forType( self::ATTACHABLE_TYPE )
			->where( 'attachable_id', $contact_id )
			->where( 'contact_id', $contact_id )
			->whereNull( 'user_id' )
			->active();
	}

	/**
	 * Validate the PHP upload array before looking at the bytes.
	 *
	 * @param array<string, mixed>|null $file Upload array.
	 * @return true|WP_Error
	 */
	private function check_upload( ?array $file ) {
		if ( ! $file || ! isset( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK;
		if ( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return $this->size_error();
		}
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
		}
		if ( UPLOAD_ERR_OK !== $error || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'The upload did not complete. Please try again.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size <= 0 ) {
			return new WP_Error( 'empty_file', __( 'This file is empty.', 'doublescale' ), array( 'status' => 400 ) );
		}
		if ( $size > PortalUploadSettings::max_file_size_bytes() ) {
			return $this->size_error();
		}

		return true;
	}

	/**
	 * Count an attempt; refuse once the hourly limit is reached.
	 *
	 * @param int $contact_id Contact ID.
	 * @return WP_Error|null
	 */
	private function hit_rate_limit( int $contact_id ): ?WP_Error {
		$key  = self::rate_limit_key( $contact_id );
		$hits = (int) get_transient( $key );

		if ( $hits >= PortalUploadSettings::HOURLY_UPLOAD_LIMIT ) {
			return new WP_Error(
				'rest_portal_upload_rate_limited',
				__( 'You have uploaded a lot of files in a short time. Please wait a while and try again.', 'doublescale' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );

		return null;
	}

	/**
	 * Sanitize and shorten an original file name, keeping its extension.
	 *
	 * @param string $name Raw name.
	 * @return string Empty when nothing usable is left.
	 */
	private function clean_name( string $name ): string {
		// sanitize_file_name() also neutralises inner extensions ("a.php.pdf"
		// becomes "a.php_.pdf"), so the base is never trimmed of underscores.
		$name = sanitize_file_name( wp_basename( $name ) );
		$ext  = (string) pathinfo( $name, PATHINFO_EXTENSION );
		$base = (string) ( '' !== $ext ? substr( $name, 0, -( strlen( $ext ) + 1 ) ) : $name );

		if ( '' === trim( $base, '.-_ ' ) ) {
			return '';
		}

		if ( strlen( $base ) > self::MAX_NAME_LENGTH ) {
			$base = rtrim( substr( $base, 0, self::MAX_NAME_LENGTH ), '. ' );
		}

		return '' !== $ext ? $base . '.' . $ext : $base;
	}

	/**
	 * @return WP_Error
	 */
	private function type_error(): WP_Error {
		return new WP_Error( 'invalid_file_type', __( 'This file type is not allowed.', 'doublescale' ), array( 'status' => 400 ) );
	}

	/**
	 * @return WP_Error
	 */
	private function size_error(): WP_Error {
		return new WP_Error(
			'file_too_large',
			sprintf(
				/* translators: %s: maximum upload size, e.g. "10 MB" */
				__( 'File exceeds the maximum upload size of %s.', 'doublescale' ),
				size_format( PortalUploadSettings::max_file_size_bytes() )
			),
			array( 'status' => 400 )
		);
	}
}
