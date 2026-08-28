<?php
/**
 * Resolve email template attachments (WordPress media library) to wp_mail paths.
 *
 * @package DoubleScale\Modules\Emails
 */

namespace DoubleScale\Modules\Emails;

use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;

defined( 'ABSPATH' ) || exit;

/**
 * EmailAttachmentResolver class.
 */
final class EmailAttachmentResolver {

	/**
	 * Maximum number of attachments per email.
	 */
	public const MAX_FILES = 5;

	/**
	 * Maximum file size per attachment (10 MB).
	 */
	public const MAX_SIZE_BYTES = 10485760;

	/**
	 * Document MIME types allowed for outbound email attachments.
	 *
	 * @var string[]
	 */
	private const ALLOWED_MIMES = array(
		'application/pdf',
		'text/plain',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.ms-excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/zip',
	);

	/**
	 * Sanitize attachment metadata for template settings storage.
	 *
	 * @param mixed $attachments Raw attachments from request/settings.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_attachments( $attachments ): array {
		if ( ! is_array( $attachments ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( array_slice( $attachments, 0, self::MAX_FILES ) as $item ) {
			$attachment_id = 0;
			$filename      = '';
			$mime          = '';
			$size          = 0;

			if ( is_numeric( $item ) ) {
				$attachment_id = absint( $item );
			} elseif ( is_array( $item ) ) {
				$attachment_id = absint( $item['id'] ?? $item['attachment_id'] ?? 0 );
				$filename      = sanitize_file_name( (string) ( $item['filename'] ?? $item['name'] ?? '' ) );
				$mime          = sanitize_text_field( (string) ( $item['mime'] ?? $item['mime_type'] ?? '' ) );
				$size          = absint( $item['size'] ?? 0 );
			}

			if ( $attachment_id <= 0 ) {
				continue;
			}

			$post = get_post( $attachment_id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				continue;
			}

			$path = get_attached_file( $attachment_id );
			if ( ! $path || ! is_readable( $path ) ) {
				continue;
			}

			$detected_mime = (string) get_post_mime_type( $attachment_id );
			if ( '' === $mime ) {
				$mime = $detected_mime;
			}

			if ( ! self::is_allowed_mime( $mime ) ) {
				continue;
			}

			$file_size = (int) filesize( $path );
			if ( $file_size > self::MAX_SIZE_BYTES ) {
				continue;
			}

			if ( '' === $filename ) {
				$filename = sanitize_file_name( basename( $path ) );
			}

			$sanitized[] = array(
				'id'       => $attachment_id,
				'filename' => $filename,
				'mime'     => $mime,
				'size'     => $size > 0 ? $size : $file_size,
			);
		}

		return $sanitized;
	}

	/**
	 * Extract attachments from builder body JSON (automation open_builder).
	 *
	 * @param string|null $body Builder body JSON.
	 * @return array<int, array<string, mixed>>
	 */
	public static function extract_from_builder_body( $body ): array {
		if ( empty( $body ) || ! is_string( $body ) ) {
			return array();
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$attachments = array();

		if ( isset( $decoded['value']['attachments'] ) ) {
			$attachments = $decoded['value']['attachments'];
		} elseif ( isset( $decoded['attachments'] ) ) {
			$attachments = $decoded['attachments'];
		}

		return self::sanitize_attachments( $attachments );
	}

	/**
	 * Resolve stored attachment metadata to filesystem paths for wp_mail.
	 *
	 * @param array<int, array<string, mixed>|int> $attachments Attachment metadata or IDs.
	 * @return string[]
	 */
	public static function resolve_paths( array $attachments ): array {
		$sanitized = self::sanitize_attachments( $attachments );
		$paths     = array();

		foreach ( $sanitized as $item ) {
			$path = get_attached_file( (int) $item['id'] );
			if ( $path && is_readable( $path ) ) {
				$paths[] = $path;
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Get attachment metadata from a template (settings first, then builder body).
	 *
	 * @param TemplateModel|null $template Template model.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_template_attachments( $template ): array {
		if ( ! $template ) {
			return array();
		}

		$settings = is_array( $template->settings ) ? $template->settings : array();
		if ( ! empty( $settings['attachments'] ) && is_array( $settings['attachments'] ) ) {
			$sanitized = self::sanitize_attachments( $settings['attachments'] );
			if ( ! empty( $sanitized ) ) {
				return $sanitized;
			}
		}

		$body = $template->body ?? '';
		if ( is_string( $body ) && '' !== $body ) {
			return self::extract_from_builder_body( $body );
		}

		return array();
	}

	/**
	 * Resolve template attachments to wp_mail file paths.
	 *
	 * @param TemplateModel|null $template Template model.
	 * @return string[]
	 */
	public static function resolve_template_paths( $template ): array {
		return self::resolve_paths( self::get_template_attachments( $template ) );
	}

	/**
	 * Whether a template has at least one valid attachment.
	 *
	 * @param TemplateModel|null $template Template model.
	 * @return bool
	 */
	public static function template_has_attachments( $template ): bool {
		return ! empty( self::get_template_attachments( $template ) );
	}

	/**
	 * Allowed MIME types for outbound email attachments.
	 *
	 * @return string[]
	 */
	public static function allowed_mimes(): array {
		$core_mimes = AttachmentService::accepted_mimes();
		$doc_mimes  = array_values( array_intersect( $core_mimes, self::ALLOWED_MIMES ) );

		return ! empty( $doc_mimes ) ? $doc_mimes : self::ALLOWED_MIMES;
	}

	/**
	 * @param string $mime MIME type.
	 * @return bool
	 */
	private static function is_allowed_mime( string $mime ): bool {
		if ( '' === $mime ) {
			return false;
		}

		return in_array( $mime, self::allowed_mimes(), true );
	}
}
