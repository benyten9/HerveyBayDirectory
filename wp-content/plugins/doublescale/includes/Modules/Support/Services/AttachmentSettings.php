<?php
/**
 * Configurable limits for support ticket attachments.
 *
 * Single source of truth for the per-message attachment caps — read by the
 * backend enforcement paths ({@see AttachmentService::store_upload()} and the
 * upload REST controllers) AND surfaced to every frontend (admin ticket, portal,
 * guest) so the uploader can pre-validate and show the limits before a file is
 * sent. Persisted under `doublescale_settings['support']['attachments']`.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;

/**
 * AttachmentSettings class.
 */
final class AttachmentSettings {

	/**
	 * Default maximum size per file, in megabytes.
	 */
	public const DEFAULT_MAX_FILE_SIZE_MB = 5;

	/**
	 * Default maximum number of files per message/ticket.
	 */
	public const DEFAULT_MAX_FILE_COUNT = 5;

	/**
	 * Hard ceiling for the configurable file count, so a typo in settings can't
	 * allow an unbounded number of attachments on a single message.
	 */
	public const MAX_FILE_COUNT_CEILING = 20;

	/**
	 * Maximum size per file in bytes, clamped to what the server actually allows.
	 *
	 * The configured megabyte value is the admin's intent; the effective limit is
	 * the smaller of that and `wp_max_upload_size()` so we never advertise (or try
	 * to accept) a file larger than PHP's `upload_max_filesize`/`post_max_size`.
	 *
	 * @return int Bytes. Always > 0.
	 */
	public static function max_file_size_bytes(): int {
		$configured_bytes = self::max_file_size_mb() * 1024 * 1024;
		$server_max       = (int) wp_max_upload_size();

		if ( $server_max > 0 ) {
			return min( $configured_bytes, $server_max );
		}
		return $configured_bytes;
	}

	/**
	 * Configured maximum size per file, in megabytes (the admin's setting).
	 *
	 * @return int Megabytes. Always >= 1.
	 */
	public static function max_file_size_mb(): int {
		$value = self::raw_setting( 'max_file_size_mb', self::DEFAULT_MAX_FILE_SIZE_MB );
		$value = (int) round( (float) $value );
		return $value >= 1 ? $value : self::DEFAULT_MAX_FILE_SIZE_MB;
	}

	/**
	 * Configured maximum number of files per message/ticket.
	 *
	 * @return int Count between 1 and {@see MAX_FILE_COUNT_CEILING}.
	 */
	public static function max_file_count(): int {
		$value = (int) self::raw_setting( 'max_file_count', self::DEFAULT_MAX_FILE_COUNT );
		if ( $value < 1 ) {
			return self::DEFAULT_MAX_FILE_COUNT;
		}
		return min( $value, self::MAX_FILE_COUNT_CEILING );
	}

	/**
	 * The limits shaped for a REST/config payload the frontend consumes.
	 *
	 * @return array{max_file_size_mb:int, max_file_size_bytes:int, max_file_count:int, accepted_mimes:string[]}
	 */
	public static function to_payload(): array {
		return array(
			'max_file_size_mb'    => self::max_file_size_mb(),
			'max_file_size_bytes' => self::max_file_size_bytes(),
			'max_file_count'      => self::max_file_count(),
			'accepted_mimes'      => AttachmentService::accepted_mimes(),
		);
	}

	/**
	 * Persist the attachment-limit settings, sanitizing each value.
	 *
	 * Only the two known keys are written; unknown input is ignored. Values are
	 * clamped the same way the getters clamp on read, so a saved value always
	 * round-trips to itself.
	 *
	 * @param array<string, mixed> $input Raw settings (`max_file_size_mb`, `max_file_count`).
	 * @return array{max_file_size_mb:int, max_file_count:int} The stored values.
	 */
	public static function save( array $input ): array {
		$size = isset( $input['max_file_size_mb'] )
			? max( 1, (int) round( (float) $input['max_file_size_mb'] ) )
			: self::max_file_size_mb();

		$count = isset( $input['max_file_count'] )
			? min( self::MAX_FILE_COUNT_CEILING, max( 1, (int) $input['max_file_count'] ) )
			: self::max_file_count();

		$support = Settings::get( 'support', array() );
		if ( ! is_array( $support ) ) {
			$support = array();
		}
		$attachments                     = isset( $support['attachments'] ) && is_array( $support['attachments'] ) ? $support['attachments'] : array();
		$attachments['max_file_size_mb'] = $size;
		$attachments['max_file_count']   = $count;
		$support['attachments']          = $attachments;

		Settings::update( 'support', $support );

		return array(
			'max_file_size_mb' => $size,
			'max_file_count'   => $count,
		);
	}

	/**
	 * Read a single raw value from `support.attachments`, before clamping.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value when unset.
	 * @return mixed
	 */
	private static function raw_setting( string $key, $fallback ) {
		$support = Settings::get( 'support', array() );
		if ( ! is_array( $support ) || ! isset( $support['attachments'] ) || ! is_array( $support['attachments'] ) ) {
			return $fallback;
		}
		return $support['attachments'][ $key ] ?? $fallback;
	}
}
