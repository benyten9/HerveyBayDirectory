<?php
/**
 * Unified attachment upload, signed download, and file serving.
 *
 * @package DoubleScale\Core\Services
 */

namespace DoubleScale\Core\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Models\AttachmentModel;
use WP_Error;

/**
 * AttachmentService class.
 */
class AttachmentService {

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
	 * @param array<string, mixed> $opts            status, activity_id, storage_subdir, protected_base_dir.
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
		$mime          = $this->detect_mime( (string) $file['tmp_name'], (string) ( $file['type'] ?? '' ) );
		if ( ! in_array( $mime, self::accepted_mimes(), true ) ) {
			return new WP_Error( 'invalid_mime', __( 'This file type is not allowed.', 'doublescale' ), array( 'status' => 400 ) );
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
			: 'doublescale-attachments/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );

		$target_dir = trailingslashit( $upload_dir['basedir'] ) . $subdir;
		if ( ! wp_mkdir_p( $target_dir ) ) {
			$this->log_storage_failure( 'Could not create the upload directory', $target_dir );
			return new WP_Error( 'mkdir_failed', __( 'Could not create upload directory.', 'doublescale' ), array( 'status' => 500 ) );
		}

		if ( ! empty( $opts['protected_base_dir'] ) ) {
			$this->ensure_protected_dir( (string) $upload_dir['basedir'], (string) $opts['protected_base_dir'] );
		}

		$stored_name = wp_unique_filename( $target_dir, $original_name );
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
			: 'doublescale-attachments/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );

		$target_dir = trailingslashit( $upload_dir['basedir'] ) . $subdir;
		if ( ! wp_mkdir_p( $target_dir ) ) {
			$this->log_storage_failure( 'Could not create the upload directory (raw store)', $target_dir );
			return new WP_Error( 'mkdir_failed', __( 'Could not create upload directory.', 'doublescale' ), array( 'status' => 500 ) );
		}

		if ( ! empty( $opts['protected_base_dir'] ) ) {
			$this->ensure_protected_dir( (string) $upload_dir['basedir'], (string) $opts['protected_base_dir'] );
		}

		$stored_name = wp_unique_filename( $target_dir, $original_name );
		$absolute    = trailingslashit( $target_dir ) . $stored_name;
		$relative    = $subdir . '/' . $stored_name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $absolute, $content ) ) {
			$this->log_storage_failure( 'Failed to write raw attachment bytes to disk', $target_dir );
			return new WP_Error( 'write_failed', __( 'Failed to store the attachment.', 'doublescale' ), array( 'status' => 500 ) );
		}

		$mime = $this->detect_mime( $absolute, isset( $file['mime'] ) ? (string) $file['mime'] : '' );
		if ( ! in_array( $mime, self::accepted_mimes(), true ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $absolute );
			return new WP_Error( 'invalid_mime', __( 'This file type is not allowed.', 'doublescale' ), array( 'status' => 400 ) );
		}

		return AttachmentModel::create(
			array(
				'attachable_type' => $attachable_type,
				'attachable_id'   => $attachable_id,
				'activity_id'     => ! empty( $opts['activity_id'] ) ? (int) $opts['activity_id'] : null,
				'user_id'         => ! empty( $uploader['user_id'] ) ? (int) $uploader['user_id'] : null,
				'contact_id'      => ! empty( $uploader['contact_id'] ) ? (int) $uploader['contact_id'] : null,
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
	 * @param string $file_name Original filename.
	 * @return bool
	 */
	public function extension_is_allowed( string $file_name ): bool {
		$check = wp_check_filetype( $file_name );
		$type  = isset( $check['type'] ) ? (string) $check['type'] : '';
		if ( '' === $type ) {
			return true;
		}
		return in_array( $type, self::accepted_mimes(), true );
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
