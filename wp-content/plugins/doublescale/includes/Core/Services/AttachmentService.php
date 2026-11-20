<?php
/**
 * Unified attachment upload, signed download, and file serving.
 *
 * @package DoubleScale\Core\Services
 */

namespace DoubleScale\Core\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use WP_Error;

/**
 * AttachmentService class.
 */
class AttachmentService {

	/**
	 * Uploads folder used when a caller passes no `storage_subdir`.
	 */
	public const DEFAULT_STORAGE_BASE = 'doublescale-attachments';

	/**
	 * Per-request cache of uploader labels, keyed `user:ID` / `contact:ID`.
	 *
	 * @var array<string, string>
	 */
	private static $uploader_names = array();

	/**
	 * Extensions a web server may execute or treat as configuration. Rejected
	 * in any dot-segment of an uploaded name, whatever the accepted-types filter
	 * allows.
	 */
	private const BLOCKED_EXTENSION_PATTERN = '/^(php[0-9]*|phtml|phar|pht|phps|pl|py|cgi|fcgi|sh|shtml?|asp|aspx|jsp|jspx|htaccess|htpasswd)$/';

	/**
	 * @var string[]
	 */
	private const DEFAULT_MIMES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'application/pdf',
		'text/plain',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.ms-excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/zip',
	);

	/**
	 * @return string[]
	 */
	public static function accepted_mimes(): array {
		$mimes = apply_filters( 'doublescale_attachment_accepted_mimes', self::DEFAULT_MIMES );
		return is_array( $mimes ) ? array_values( array_filter( array_map( 'strval', $mimes ) ) ) : self::DEFAULT_MIMES;
	}

	/**
	 * @param array<string, mixed> $file            PHP upload array.
	 * @param string               $attachable_type Owner type.
	 * @param int|null             $attachable_id   Owner id (NULL for pre-create temp).
	 * @param array<string, mixed> $uploader        user_id and/or contact_id.
	 * @param array<string, mixed> $opts            status, activity_id, storage_subdir, protected_base_dir,
	 *                                              randomize_name (store under a random name; the original
	 *                                              stays in `file_name` only), max_size_bytes, meta.
	 * @return AttachmentModel|WP_Error
	 */
	public function store_upload(
		array $file,
		string $attachable_type,
		?int $attachable_id,
		array $uploader,
		array $opts = array()
	) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$max_size  = isset( $opts['max_size_bytes'] ) ? (int) $opts['max_size_bytes'] : 0;
		$file_size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $max_size > 0 && $file_size > $max_size ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %s: maximum upload size */
					__( 'File exceeds the maximum upload size of %s.', 'doublescale' ),
					size_format( $max_size )
				),
				array( 'status' => 400 )
			);
		}

		$original_name = sanitize_file_name( (string) ( $file['name'] ?? 'file' ) );
		$validated     = $this->validate_file( $original_name, (string) $file['tmp_name'] );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$mime = $validated['mime'];

		$upload_dir = wp_upload_dir( null, false, false );
		if ( ! is_array( $upload_dir ) || empty( $upload_dir['basedir'] ) ) {
			return new WP_Error( 'upload_dir_unavailable', __( 'Upload directory is unavailable.', 'doublescale' ), array( 'status' => 500 ) );
		}

		$subdir = isset( $opts['storage_subdir'] ) && is_string( $opts['storage_subdir'] ) && '' !== $opts['storage_subdir']
			? $opts['storage_subdir']
			: self::DEFAULT_STORAGE_BASE . '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );

		$target_dir = trailingslashit( $upload_dir['basedir'] ) . $subdir;
		if ( ! wp_mkdir_p( $target_dir ) ) {
			$this->log_storage_failure( 'Could not create the upload directory', $target_dir );
			return new WP_Error( 'mkdir_failed', __( 'Could not create upload directory.', 'doublescale' ), array( 'status' => 500 ) );
		}

		$this->ensure_protected_dir( (string) $upload_dir['basedir'], $this->protected_base_for( $subdir, $opts ) );

		$stored_name = $this->safe_stored_name( $target_dir, $original_name, $validated['ext'], ! empty( $opts['randomize_name'] ) );
		$absolute    = trailingslashit( $target_dir ) . $stored_name;
		$relative    = $subdir . '/' . $stored_name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file
		if ( ! move_uploaded_file( $file['tmp_name'], $absolute ) ) {
			$this->log_storage_failure( 'Failed to move the uploaded file into place', $target_dir );
			return new WP_Error( 'move_failed', __( 'Failed to store the uploaded file.', 'doublescale' ), array( 'status' => 500 ) );
		}

		$status = isset( $opts['status'] ) ? (string) $opts['status'] : 'active';

		return AttachmentModel::create(
			array(
				'attachable_type' => $attachable_type,
				'attachable_id'   => $attachable_id,
				'activity_id'     => ! empty( $opts['activity_id'] ) ? (int) $opts['activity_id'] : null,
				'user_id'         => ! empty( $uploader['user_id'] ) ? (int) $uploader['user_id'] : null,
				'contact_id'      => ! empty( $uploader['contact_id'] ) ? (int) $uploader['contact_id'] : null,
				'company_id'      => ! empty( $uploader['company_id'] ) ? (int) $uploader['company_id'] : null,
				'file_name'       => $original_name,
				'file_path'       => $relative,
				'file_type'       => $mime,
				'file_size'       => $file_size > 0 ? $file_size : (int) filesize( $absolute ),
				'content_id'      => ! empty( $opts['content_id'] ) ? trim( (string) $opts['content_id'], " <>\t\r\n" ) : null,
				'driver'          => 'local',
				'status'          => $status,
				'meta'            => isset( $opts['meta'] ) && is_array( $opts['meta'] ) ? $opts['meta'] : null,
			)
		);
	}

	/**
	 * @param array<string, mixed> $file            filename, mime, content (bytes), optional content_id.
	 * @param string               $attachable_type Owner type.
	 * @param int                  $attachable_id   Owner id.
	 * @param array<string, mixed> $uploader        user_id and/or contact_id.
	 * @param array<string, mixed> $opts            activity_id, storage_subdir, protected_base_dir.
	 * @return AttachmentModel|WP_Error
	 */
	public function store_raw(
		array $file,
		string $attachable_type,
		int $attachable_id,
		array $uploader = array(),
		array $opts = array()
	) {
		$content = isset( $file['content'] ) ? (string) $file['content'] : '';
		if ( '' === $content ) {
			return new WP_Error( 'empty_attachment', __( 'Empty attachment.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$original_name = sanitize_file_name( (string) ( $file['filename'] ?? 'file' ) );
		if ( '' === $original_name ) {
			$original_name = 'file';
		}

		if ( ! $this->extension_is_allowed( $original_name ) ) {
			return new WP_Error( 'invalid_extension', __( 'This file type is not allowed.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$upload_dir = wp_upload_dir( null, false, false );
		if ( ! is_array( $upload_dir ) || empty( $upload_dir['basedir'] ) ) {
			return new WP_Error( 'upload_dir_unavailable', __( 'Upload directory is unavailable.', 'doublescale' ), array( 'status' => 500 ) );
		}

		$subdir = isset( $opts['storage_subdir'] ) && is_string( $opts['storage_subdir'] ) && '' !== $opts['storage_subdir']
			? $opts['storage_subdir']
			: self::DEFAULT_STORAGE_BASE . '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );

		$target_dir = trailingslashit( $upload_dir['basedir'] ) . $subdir;
		if ( ! wp_mkdir_p( $target_dir ) ) {
			$this->log_storage_failure( 'Could not create the upload directory (raw store)', $target_dir );
			return new WP_Error( 'mkdir_failed', __( 'Could not create upload directory.', 'doublescale' ), array( 'status' => 500 ) );
		}

		$this->ensure_protected_dir( (string) $upload_dir['basedir'], $this->protected_base_for( $subdir, $opts ) );

		// Validate the bytes in a temp file outside the uploads folder, so
		// nothing lands in a web-reachable directory until it has passed.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php'; // Not loaded during REST and cron requests.
		}
		$tmp = wp_tempnam( 'doublescale-attachment' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( ! $tmp || false === file_put_contents( $tmp, $content ) ) {
			$this->log_storage_failure( 'Failed to write raw attachment bytes to a temp file', $target_dir );
			return new WP_Error( 'write_failed', __( 'Failed to store the attachment.', 'doublescale' ), array( 'status' => 500 ) );
		}

		$validated = $this->validate_file( $original_name, $tmp );
		if ( is_wp_error( $validated ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp );
			return $validated;
		}
		$mime = $validated['mime'];

		// Raw bytes arrive from email senders, who need no account, so the
		// stored name is random unless a caller explicitly opts out.
		$randomize   = ! isset( $opts['randomize_name'] ) || ! empty( $opts['randomize_name'] );
		$stored_name = $this->safe_stored_name( $target_dir, $original_name, $validated['ext'], $randomize );
		$absolute    = trailingslashit( $target_dir ) . $stored_name;
		$relative    = $subdir . '/' . $stored_name;

		// rename() fails across filesystems (temp dir on another mount), so fall back to copy.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged
		$moved = @rename( $tmp, $absolute );
		if ( ! $moved ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.copy_copy,WordPress.PHP.NoSilencedErrors.Discouraged
			$moved = @copy( $tmp, $absolute );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp );
		}
		if ( ! $moved ) {
			$this->log_storage_failure( 'Failed to move raw attachment bytes into place', $target_dir );
			return new WP_Error( 'write_failed', __( 'Failed to store the attachment.', 'doublescale' ), array( 'status' => 500 ) );
		}

		return AttachmentModel::create(
			array(
				'attachable_type' => $attachable_type,
				'attachable_id'   => $attachable_id,
				'activity_id'     => ! empty( $opts['activity_id'] ) ? (int) $opts['activity_id'] : null,
				'user_id'         => ! empty( $uploader['user_id'] ) ? (int) $uploader['user_id'] : null,
				'contact_id'      => ! empty( $uploader['contact_id'] ) ? (int) $uploader['contact_id'] : null,
				'company_id'      => ! empty( $uploader['company_id'] ) ? (int) $uploader['company_id'] : null,
				'file_name'       => $original_name,
				'file_path'       => $relative,
				'file_type'       => $mime,
				'file_size'       => strlen( $content ),
				'content_id'      => isset( $file['content_id'] ) && '' !== (string) $file['content_id']
					? trim( (string) $file['content_id'], " <>\t\r\n" )
					: null,
				'driver'          => 'local',
				'status'          => 'active',
			)
		);
	}

	/**
	 * @param string $file_hash File hash query arg.
	 * @param string $sign      HMAC signature query arg.
	 * @return void
	 */
	public function serve( string $file_hash, string $sign ): void {
		$attachment = AttachmentModel::get_by_hash( $file_hash );
		if ( ! $attachment || 'active' !== (string) $attachment->status ) {
			wp_die( esc_html__( 'File not found.', 'doublescale' ), esc_html__( 'Not Found', 'doublescale' ), array( 'response' => 404 ) );
		}

		if ( ! $this->verify_signature( (int) $attachment->id, $sign ) ) {
			wp_die(
				esc_html__( 'Invalid or expired download link. Please reload the page and try again.', 'doublescale' ),
				esc_html__( 'Forbidden', 'doublescale' ),
				array( 'response' => 403 )
			);
		}

		$absolute = AttachmentModel::resolve_absolute_path( (string) $attachment->file_path );
		if ( '' === $absolute || ! is_file( $absolute ) ) {
			wp_die( esc_html__( 'File not found.', 'doublescale' ), esc_html__( 'Not Found', 'doublescale' ), array( 'response' => 404 ) );
		}

		// A file the team shared, opened by the client: the team sees it.
		if ( class_exists( \DoubleScale\Modules\Portal\Services\ClientSeen::class ) ) {
			\DoubleScale\Modules\Portal\Services\ClientSeen::file_opened( $attachment );
		}

		$filename    = (string) $attachment->file_name;
		$mime        = (string) $attachment->file_type;
		$disposition = $this->is_inline_displayable( $mime ) ? 'inline' : 'attachment';

		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . rawurlencode( $filename ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $absolute ) );
		header( 'X-Content-Type-Options: nosniff' );
		$this->stream_file_chunked( $absolute );
		exit;
	}

	/**
	 * @param AttachmentModel $attachment Attachment model.
	 * @param bool            $legacy     Use legacy query-arg names for backward compat.
	 * @return string
	 */
	public function signed_url( AttachmentModel $attachment, bool $legacy = false ): string {
		$sign = $this->generate_signature( (int) $attachment->id );
		if ( $legacy ) {
			return add_query_arg(
				array(
					'ds_support_file' => (string) $attachment->file_hash,
					'ds_support_sign' => $sign,
				),
				home_url( '/' )
			);
		}

		return add_query_arg(
			array(
				'ds_file' => (string) $attachment->file_hash,
				'ds_sign' => $sign,
			),
			home_url( '/' )
		);
	}

	/**
	 * @param AttachmentModel $attachment Attachment.
	 * @param bool            $is_inline  Whether embedded inline in a body.
	 * @param bool            $legacy_url Use legacy signed-URL query args.
	 * @return array<string, mixed>
	 */
	public function shape_for_api( AttachmentModel $attachment, bool $is_inline = false, bool $legacy_url = false ): array {
		return array(
			'id'          => (int) $attachment->id,
			'file_hash'   => (string) $attachment->file_hash,
			'file_name'   => (string) $attachment->file_name,
			'file_size'   => (int) $attachment->file_size,
			'file_type'   => (string) $attachment->file_type,
			'created_at'  => $attachment->created_at ? (string) $attachment->created_at : null,
			'url'         => $this->signed_url( $attachment, $legacy_url ),
			'is_inline'   => $is_inline,
		);
	}

	/**
	 * Who uploaded an attachment: a team member (`user_id`) or the contact
	 * themself (`contact_id` only — portal uploads never carry a user id).
	 *
	 * Opt-in on purpose: `shape_for_api()` also feeds customer-facing payloads
	 * (support portal), which must not gain staff names or extra queries.
	 *
	 * @param AttachmentModel $attachment Attachment.
	 * @return array{type: string, id: int|null, name: string}
	 */
	public function uploader_for_api( AttachmentModel $attachment ): array {
		$user_id    = (int) $attachment->user_id;
		$contact_id = (int) $attachment->contact_id;

		if ( $user_id > 0 ) {
			$key = 'user:' . $user_id;
			if ( ! isset( self::$uploader_names[ $key ] ) ) {
				$user                         = get_userdata( $user_id );
				self::$uploader_names[ $key ] = $user ? (string) $user->display_name : __( 'Deleted user', 'doublescale' );
			}

			return array(
				'type' => 'team',
				'id'   => $user_id,
				'name' => self::$uploader_names[ $key ],
			);
		}

		if ( $contact_id > 0 ) {
			$key = 'contact:' . $contact_id;
			if ( ! isset( self::$uploader_names[ $key ] ) ) {
				$contact = ContactModel::find( $contact_id );
				$name    = $contact ? trim( (string) $contact->first_name . ' ' . (string) $contact->last_name ) : '';
				if ( '' === $name && $contact ) {
					$name = (string) $contact->email;
				}
				self::$uploader_names[ $key ] = '' !== $name ? $name : __( 'Contact', 'doublescale' );
			}

			return array(
				'type' => 'contact',
				'id'   => $contact_id,
				'name' => self::$uploader_names[ $key ],
			);
		}

		return array(
			'type' => 'unknown',
			'id'   => null,
			'name' => '',
		);
	}

	/**
	 * @deprecated 1.5.2 Uploads now use safe_stored_name(), which always stores the validated extension.
	 *
	 * Name to write the file under inside `$target_dir`.
	 *
	 * Randomized names keep untrusted uploads unguessable even where the web
	 * server ignores `.htaccess` (nginx); the original name lives in the row.
	 *
	 * @param string $target_dir    Absolute target directory.
	 * @param string $original_name Sanitized original file name.
	 * @param bool   $randomize     Store under a random name.
	 * @return string
	 */
	public function stored_file_name( string $target_dir, string $original_name, bool $randomize = false ): string {
		if ( ! $randomize ) {
			return wp_unique_filename( $target_dir, $original_name );
		}

		$ext = strtolower( (string) pathinfo( $original_name, PATHINFO_EXTENSION ) );
		$ext = preg_replace( '/[^a-z0-9]/', '', $ext );

		try {
			$random = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			$random = strtolower( wp_generate_password( 32, false, false ) );
		}

		return $random . ( '' !== $ext ? '.' . $ext : '' );
	}

	/**
	 * Deny direct web access to the default storage folder.
	 *
	 * Every download goes through the signed `?ds_file=` handler, so nothing
	 * needs the folder to be browsable. Called on upgrade for existing sites.
	 *
	 * @return void
	 */
	public static function protect_default_storage(): void {
		$upload_dir = wp_upload_dir( null, false, false );
		if ( ! is_array( $upload_dir ) || empty( $upload_dir['basedir'] ) ) {
			return;
		}

		( new self() )->ensure_protected_dir( (string) $upload_dir['basedir'], self::DEFAULT_STORAGE_BASE );
	}

	/**
	 * The folder to lock down for a store call: the caller's explicit choice,
	 * else the default storage base when the file lands in it.
	 *
	 * @param string               $subdir Relative storage subdir.
	 * @param array<string, mixed> $opts   Store options.
	 * @return string Empty when nothing should be locked.
	 */
	private function protected_base_for( string $subdir, array $opts ): string {
		if ( ! empty( $opts['protected_base_dir'] ) ) {
			return (string) $opts['protected_base_dir'];
		}

		return 0 === strpos( $subdir, self::DEFAULT_STORAGE_BASE . '/' ) ? self::DEFAULT_STORAGE_BASE : '';
	}

	/**
	 * @param string $attachable_type Optional owner type filter.
	 * @return int
	 */
	public function cleanup_stale_temp( string $attachable_type = '' ): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$query  = AttachmentModel::query()
			->where( 'status', 'temp' )
			->where( 'created_at', '<', $cutoff );

		if ( '' !== $attachable_type ) {
			$query->where( 'attachable_type', $attachable_type );
		}

		$rows  = $query->get();
		$count = 0;
		foreach ( $rows as $row ) {
			$row->delete();
			++$count;
		}
		return $count;
	}

	/**
	 * @param int $attachment_id Attachment row id.
	 * @param string $sign       Client-provided signature.
	 * @return bool
	 */
	public function verify_signature( int $attachment_id, string $sign ): bool {
		if ( '' === $sign ) {
			return false;
		}
		foreach ( array( gmdate( 'YmdH' ), gmdate( 'YmdH', time() - HOUR_IN_SECONDS ) ) as $bucket ) {
			if ( hash_equals( $this->generate_signature( $attachment_id, $bucket ), $sign ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param int         $attachment_id Attachment row id.
	 * @param string|null $bucket        Hour bucket (`YmdH`).
	 * @return string
	 */
	public function generate_signature( int $attachment_id, ?string $bucket = null ): string {
		$bucket = null === $bucket ? gmdate( 'YmdH' ) : $bucket;
		return hash_hmac( 'sha256', $attachment_id . '|' . $bucket, wp_salt( 'secure_auth' ) );
	}

	/**
	 * @param string $absolute Absolute path to a verified file.
	 * @return void
	 */
	public function stream_file_chunked( string $absolute ): void {
		$chunk_size = 1024 * 1024;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $absolute, 'rb' );
		if ( false === $handle ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			readfile( $absolute );
			return;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		while ( ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$buffer = fread( $handle, $chunk_size );
			if ( false === $buffer ) {
				break;
			}
			echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			flush();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );
	}

	/**
	 * @param string $basedir WordPress uploads basedir.
	 * @param string $subdir  Protected subdirectory name (e.g. doublescale-support).
	 * @return void
	 */
	public function ensure_protected_dir( string $basedir, string $subdir ): void {
		if ( '' === $basedir || '' === $subdir ) {
			return;
		}
		$base = trailingslashit( $basedir ) . ltrim( $subdir, '/\\' );
		if ( ! is_dir( $base ) ) {
			return;
		}

		$htaccess = $base . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Generated by DoubleScale. Do not edit.\n"
				. "Deny from all\n"
				. "Options -Indexes\n"
				. "<IfModule mod_php5.c>\n  php_flag engine off\n</IfModule>\n"
				. "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n"
				. "<Files *>\n  SetHandler none\n  RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .pl .py .cgi\n</Files>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged
			@file_put_contents( $htaccess, $rules );
		}

		$index = $base . '/index.html';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged
			@file_put_contents( $index, '' );
		}
	}

	/**
	 * @deprecated 1.5.2 Use validate_file(), which checks content against the
	 *             original file name. This method sniffs content only.
	 *
	 * @param string $path          Temp file path.
	 * @param string $declared_mime Declared MIME from the client.
	 * @return string
	 */
	public function detect_mime( string $path, string $declared_mime ): string {
		$detected = '';
		if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
			$checked = wp_check_filetype_and_ext( $path, basename( $path ) );
			if ( is_array( $checked ) && ! empty( $checked['type'] ) ) {
				$detected = (string) $checked['type'];
			}
		}
		if ( '' === $detected && function_exists( 'mime_content_type' ) ) {
			$detected = (string) mime_content_type( $path );
		}
		if ( '' === $detected ) {
			$detected = sanitize_mime_type( $declared_mime );
		}
		return $detected;
	}

	/**
	 * Whether a file name may be stored.
	 *
	 * Fails closed: the extension must map to an accepted MIME type. An
	 * extension WordPress does not recognise (wp_check_filetype() returns an
	 * empty type) is rejected, and so is any name with an executable
	 * extension in any dot-segment, so `x.php`, `x.php.gif` and `x.gif.php`
	 * never reach disk.
	 *
	 * @param string $file_name Original filename.
	 * @return bool
	 */
	public function extension_is_allowed( string $file_name ): bool {
		return '' !== $this->allowed_mime_for_name( $file_name );
	}

	/**
	 * Accepted MIME type for a file name, or '' when the name is not allowed.
	 *
	 * @param string $file_name Original filename.
	 * @return string
	 */
	private function allowed_mime_for_name( string $file_name ): string {
		$file_name = strtolower( trim( $file_name ) );
		// Control characters (a NUL in "x.php\0.jpg") have no place in a file
		// name and can make later steps read a different name than this check.
		if ( '' === $file_name || preg_match( '/[\x00-\x1f\x7f]/', $file_name ) || $this->has_blocked_extension( $file_name ) ) {
			return '';
		}

		$check = wp_check_filetype( $file_name );
		$type  = isset( $check['type'] ) ? (string) $check['type'] : '';
		$ext   = isset( $check['ext'] ) ? (string) $check['ext'] : '';
		if ( '' === $type || '' === $ext ) {
			return '';
		}

		return in_array( $type, self::accepted_mimes(), true ) ? $type : '';
	}

	/**
	 * Whether any dot-separated segment of the name is an executable or
	 * server-configuration extension.
	 *
	 * Checking every segment, not only the last, covers servers that execute
	 * `x.php.gif` (Apache with AddHandler) as well as the plain `x.php` case.
	 *
	 * @param string $file_name Lower-cased file name.
	 * @return bool
	 */
	private function has_blocked_extension( string $file_name ): bool {
		$segments = explode( '.', $file_name );
		array_shift( $segments ); // The base name before the first dot is not an extension.

		foreach ( $segments as $segment ) {
			// sanitize_file_name() turns `x.php.gif` into `x.php_.gif`; the
			// underscore must not let the inner extension through.
			if ( 1 === preg_match( self::BLOCKED_EXTENSION_PATTERN, rtrim( $segment, '_' ) ) ) {
				return true;
			}
		}

		// Dotfiles such as `.htaccess` and `.user.ini` configure the server.
		return 0 === strpos( $file_name, '.' );
	}

	/**
	 * Validate an untrusted file before it is stored.
	 *
	 * Returns the MIME type and the extension the file will be stored with.
	 * The extension always comes from the accepted-types allow-list, never
	 * from the content, so a file is never stored with an executable
	 * extension whatever bytes it contains. Image types are additionally
	 * checked against the file's real content.
	 *
	 * @param string $original_name Sanitized original file name.
	 * @param string $path          Path to the bytes on disk.
	 * @return array{mime:string, ext:string}|WP_Error
	 */
	public function validate_file( string $original_name, string $path ) {
		$not_allowed = new WP_Error( 'invalid_extension', __( 'This file type is not allowed.', 'doublescale' ), array( 'status' => 400 ) );

		$mime = $this->allowed_mime_for_name( $original_name );
		if ( '' === $mime ) {
			return $not_allowed;
		}

		$check = wp_check_filetype( strtolower( $original_name ) );
		$ext   = (string) $check['ext'];

		// Validate content against the real name (not PHP's extensionless temp
		// name). WordPress verifies image bytes and rejects a mismatch, such as
		// HTML or script content named `photo.jpg`.
		if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
			$verified = wp_check_filetype_and_ext( $path, $original_name );
			if ( ! is_array( $verified ) || empty( $verified['type'] ) || empty( $verified['ext'] ) ) {
				return new WP_Error( 'invalid_mime', __( 'This file type is not allowed.', 'doublescale' ), array( 'status' => 400 ) );
			}
			$verified_mime = (string) $verified['type'];
			$verified_ext  = strtolower( (string) $verified['ext'] );
			if ( ! in_array( $verified_mime, self::accepted_mimes(), true ) || $this->has_blocked_extension( 'file.' . $verified_ext ) ) {
				return new WP_Error( 'invalid_mime', __( 'This file type is not allowed.', 'doublescale' ), array( 'status' => 400 ) );
			}
			// WordPress may correct the extension to match real image content
			// (a PNG named .jpg is stored as .png); both are on the allow-list.
			$mime = $verified_mime;
			$ext  = $verified_ext;
		}

		return array(
			'mime' => $mime,
			'ext'  => $ext,
		);
	}

	/**
	 * A safe stored name: the original base name, or a random one, always
	 * ending in the validated extension.
	 *
	 * @param string $target_dir    Absolute target directory.
	 * @param string $original_name Sanitized original file name.
	 * @param string $ext           Validated extension.
	 * @param bool   $randomize     Store under a random name.
	 * @return string
	 */
	private function safe_stored_name( string $target_dir, string $original_name, string $ext, bool $randomize ): string {
		$ext = preg_replace( '/[^a-z0-9]/', '', strtolower( $ext ) );

		if ( $randomize ) {
			try {
				$base = bin2hex( random_bytes( 16 ) );
			} catch ( \Throwable $e ) {
				$base = strtolower( wp_generate_password( 32, false, false ) );
			}
			return $base . '.' . $ext;
		}

		// Keep only the part before the first dot so no inner extension survives.
		$parts = explode( '.', $original_name );
		$base  = sanitize_file_name( (string) $parts[0] );
		if ( '' === $base ) {
			$base = 'file';
		}

		return wp_unique_filename( $target_dir, $base . '.' . $ext );
	}

	/**
	 * @param string $mime MIME type.
	 * @return bool
	 */
	private function is_inline_displayable( string $mime ): bool {
		$inline = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
		return in_array( strtolower( $mime ), $inline, true );
	}

	/**
	 * @param string $message    Summary.
	 * @param string $target_dir Target directory.
	 * @return void
	 */
	private function log_storage_failure( string $message, string $target_dir ): void {
		if ( ! function_exists( 'doublescale_get_logger' ) ) {
			return;
		}
		doublescale_get_logger()->error(
			$message,
			array(
				'source'       => 'core-attachment-service',
				'target_dir'   => $target_dir,
				'dir_exists'   => is_dir( $target_dir ),
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				'dir_writable' => is_writable( $target_dir ),
			)
		);
	}
}
