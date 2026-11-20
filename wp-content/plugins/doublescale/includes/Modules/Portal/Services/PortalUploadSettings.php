<?php
/**
 * Client Portal file uploads — the admin's switch and the upload policy.
 *
 * Customers may upload files to their own contact record from the portal. The
 * feature is OFF until an admin turns it on, and then accepts the safe type
 * list below; the admin can narrow that list but never widen it.
 *
 * Pro-only: the place the team sees these files (contact → Attachments) ships
 * in Pro, and a Pro that renders "uploaded by" declares it through the
 * `doublescale_portal_client_uploads_supported` filter. An older Pro does not,
 * so after a partial update the feature stays off instead of half-working.
 *
 * Persisted under `doublescale_settings['client_portal']`, next to the keys
 * {@see PortalUserProvisioner} owns; only this class's keys are written here.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Core\Settings\Settings;
use WP_Error;

/**
 * PortalUploadSettings.
 */
final class PortalUploadSettings {

	/** Settings key shared with the customer-accounts settings. */
	public const SETTINGS_KEY = 'client_portal';

	/** Most files one customer can keep uploaded at a time. */
	public const MAX_FILE_COUNT = 20;

	/** Upload attempts one customer can make per hour. */
	public const HOURLY_UPLOAD_LIMIT = 30;

	/** Per-file cap before the server's own limit is applied (10 MB). */
	public const DEFAULT_MAX_FILE_SIZE_BYTES = 10485760;

	/**
	 * The safe list: extension → MIME. Mirrors Pro's contact attachment input
	 * (`ContactService.ACCEPTED_ATTACHMENT_EXTENSIONS`). Order is display order.
	 */
	private const TYPES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'  => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'txt'  => 'text/plain',
		'zip'  => 'application/zip',
	);

	/**
	 * Extensions no filter may ever open to customers: anything a browser or
	 * web server can execute or render as active content.
	 */
	private const NEVER_ALLOWED = '/^(php\d*|phtml|pht|phar|phps|inc|js|mjs|cjs|html?|xhtml|shtml|svgz?|xml|xsl|swf|exe|msi|dll|com|bat|cmd|sh|bash|cgi|pl|py|rb|asp|aspx|jsp|jar|htaccess|htpasswd|ini|config)$/';

	/**
	 * Safe types available to customers: the list above, minus MIME types the
	 * core attachment store does not accept, minus anything dangerous a
	 * `doublescale_portal_upload_types` filter tried to add.
	 *
	 * @return array<string, string> Extension → MIME.
	 */
	public static function available_types(): array {
		/**
		 * Filter the file types customers may upload from the portal.
		 *
		 * Executable or renderable types (php, js, html, svg, ...) are always
		 * removed afterwards, whatever this returns.
		 *
		 * @param array<string, string> $types Extension → MIME.
		 */
		$types = apply_filters( 'doublescale_portal_upload_types', self::TYPES );
		$types = is_array( $types ) ? $types : self::TYPES;

		$accepted  = AttachmentService::accepted_mimes();
		$available = array();
		foreach ( $types as $ext => $mime ) {
			$ext = strtolower( trim( (string) $ext, ". \t\n\r\0\x0B" ) );
			if ( '' === $ext || ! preg_match( '/^[a-z0-9]+$/', $ext ) || preg_match( self::NEVER_ALLOWED, $ext ) ) {
				continue;
			}
			if ( ! in_array( (string) $mime, $accepted, true ) ) {
				continue;
			}
			$available[ $ext ] = (string) $mime;
		}

		return $available;
	}

	/**
	 * Whether DoubleScale Pro is active.
	 *
	 * @return bool
	 */
	public static function is_pro_active(): bool {
		return function_exists( 'doublescale_is_pro_addon_active' ) && doublescale_is_pro_addon_active();
	}

	/**
	 * Whether the active Pro can show the team who uploaded each file.
	 *
	 * @return bool
	 */
	public static function is_supported(): bool {
		/**
		 * Whether the active DoubleScale Pro supports customer file uploads.
		 *
		 * @param bool $supported Default false; Pro returns true.
		 */
		return self::is_pro_active() && (bool) apply_filters( 'doublescale_portal_client_uploads_supported', false );
	}

	/**
	 * Whether the admin's upload switch is on. It is also the default of the
	 * Files row in Settings → Client Portal → What clients see in the portal.
	 *
	 * @return bool
	 */
	public static function is_switched_on(): bool {
		return ! empty( self::raw()['client_uploads_enabled'] );
	}

	/**
	 * Whether the current customer can upload files right now: a Pro that
	 * supports it, and Files shown to them — their own access when a team
	 * member set one, the upload switch otherwise.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return self::is_supported() && PortalAccess::allows( 'files', PortalIdentity::current_contact() );
	}

	/**
	 * Extensions customers may upload, in safe-list order. Empty when the
	 * admin's restriction no longer matches any available type (fail closed).
	 *
	 * @return string[]
	 */
	public static function allowed_extensions(): array {
		return array_keys( self::allowed_mimes() );
	}

	/**
	 * Allowed extension → MIME map.
	 *
	 * @return array<string, string>
	 */
	public static function allowed_mimes(): array {
		$available = self::available_types();
		$raw       = self::raw();

		if ( empty( $raw['client_upload_restrict_types'] ) ) {
			return $available;
		}

		$chosen = self::normalise_types( $raw['client_upload_types'] ?? array(), $available );

		return array_intersect_key( $available, array_flip( $chosen ) );
	}

	/**
	 * Per-file size cap: 10 MB, or less when the server allows less.
	 *
	 * @return int Bytes, always > 0.
	 */
	public static function max_file_size_bytes(): int {
		$configured = (int) apply_filters( 'doublescale_portal_upload_max_bytes', self::DEFAULT_MAX_FILE_SIZE_BYTES );
		$configured = $configured > 0 ? $configured : self::DEFAULT_MAX_FILE_SIZE_BYTES;
		$server     = (int) wp_max_upload_size();

		return $server > 0 ? min( $configured, $server ) : $configured;
	}

	/**
	 * Settings payload for the admin card.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$raw       = self::raw();
		$available = self::available_types();

		$types = array();
		foreach ( $available as $ext => $mime ) {
			$types[] = array(
				'ext'   => $ext,
				'mime'  => $mime,
				'group' => self::group_for( $mime ),
			);
		}

		return array(
			'enabled'             => ! empty( $raw['client_uploads_enabled'] ),
			'restrict_types'      => ! empty( $raw['client_upload_restrict_types'] ),
			'allowed_types'       => self::normalise_types( $raw['client_upload_types'] ?? array(), $available ),
			'available_types'     => $types,
			'max_file_size_bytes' => self::max_file_size_bytes(),
			'max_file_count'      => self::MAX_FILE_COUNT,
			'pro_active'          => self::is_pro_active(),
			'supported'           => self::is_supported(),
		);
	}

	/**
	 * Save the admin's choices (only the keys this class owns).
	 *
	 * @param array<string, mixed> $input `enabled`, `restrict_types`, `allowed_types`.
	 * @return array<string, mixed>|WP_Error Settings payload, or 400 when the
	 *                                       restriction would allow nothing.
	 */
	public static function update( array $input ) {
		$current   = self::raw();
		$available = self::available_types();

		$enabled  = array_key_exists( 'enabled', $input ) ? (bool) $input['enabled'] : ! empty( $current['client_uploads_enabled'] );
		$restrict = array_key_exists( 'restrict_types', $input ) ? (bool) $input['restrict_types'] : ! empty( $current['client_upload_restrict_types'] );
		$types    = array_key_exists( 'allowed_types', $input )
			? self::normalise_types( $input['allowed_types'], $available )
			: self::normalise_types( $current['client_upload_types'] ?? array(), $available );

		if ( $restrict && empty( $types ) ) {
			return new WP_Error(
				'invalid_file_types',
				__( 'Pick at least one file type, or turn off the restriction.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$current['client_uploads_enabled']       = $enabled;
		$current['client_upload_restrict_types'] = $restrict;
		$current['client_upload_types']          = $types;

		Settings::update( self::SETTINGS_KEY, $current );

		return self::settings();
	}

	/**
	 * Lower-case, strip dots, keep available types only, safe-list order.
	 *
	 * @param mixed                 $types     Raw list.
	 * @param array<string, string> $available Available extension → MIME.
	 * @return string[]
	 */
	private static function normalise_types( $types, array $available ): array {
		if ( ! is_array( $types ) ) {
			return array();
		}

		$wanted = array();
		foreach ( $types as $type ) {
			if ( is_scalar( $type ) ) {
				$wanted[ strtolower( trim( (string) $type, ". \t\n\r\0\x0B" ) ) ] = true;
			}
		}

		return array_values( array_filter( array_keys( $available ), static fn( $ext ) => isset( $wanted[ $ext ] ) ) );
	}

	/**
	 * UI group for a MIME type.
	 *
	 * @param string $mime MIME type.
	 * @return string `image`, `document` or `archive`.
	 */
	private static function group_for( string $mime ): string {
		if ( 0 === strpos( $mime, 'image/' ) ) {
			return 'image';
		}
		return 'application/zip' === $mime ? 'archive' : 'document';
	}

	/**
	 * Stored `client_portal` settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function raw(): array {
		$raw = Settings::get( self::SETTINGS_KEY, array() );
		return is_array( $raw ) ? $raw : array();
	}
}
