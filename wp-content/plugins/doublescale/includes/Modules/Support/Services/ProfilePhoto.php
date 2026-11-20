<?php
/**
 * A support team member's own profile photo.
 *
 * Website visitors see the team in the live chat (the faces on its home, the
 * person who answers), so whoever answers tickets may give themselves a
 * photo instead of relying on Gravatar. Only their own: nobody sets someone
 * else's.
 *
 * The uploaded file is never kept. It is checked (size, real image type,
 * pixel count), then drawn again by WordPress's image editor as a 256 × 256
 * centred square, and only that new picture is saved. That drops EXIF and GPS
 * data and anything hidden after the image data, so the file in uploads is
 * always just a picture, under a random name nobody can guess.
 *
 * Every avatar on the site follows (the chat, the team list, the header,
 * ticket replies) through `pre_get_avatar_data`. Removing the photo goes
 * back to Gravatar or the first letter.
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\ModuleManager;
use DoubleScale\Core\Security\RateLimiter;
use DoubleScale\Core\UserRoles\Permissions;
use WP_Comment;
use WP_Error;
use WP_Post;
use WP_User;

/**
 * ProfilePhoto class.
 */
final class ProfilePhoto {

	/**
	 * Where the photo is (user meta): its path under the uploads folder.
	 */
	public const META = 'doublescale_profile_photo';

	/**
	 * Who has a photo (autoloaded): lets every avatar on a page skip the
	 * lookup when nobody has one, which is most sites.
	 */
	public const USERS_OPTION = 'doublescale_profile_photo_users';

	/**
	 * Folder under uploads.
	 */
	public const DIR = 'doublescale/avatars';

	/**
	 * Largest upload, in bytes.
	 */
	public const MAX_BYTES = 2 * MB_IN_BYTES;

	/**
	 * The saved square, in pixels.
	 */
	public const SIZE = 256;

	/**
	 * Uploads per person per hour: each one costs an image resize.
	 */
	public const UPLOADS_PER_HOUR = 10;

	/**
	 * Bigger pictures than this are refused before decoding: a small file can
	 * still unpack to gigabytes of pixels.
	 */
	private const MAX_PIXELS = 40000000;

	/**
	 * The image types accepted, by what the file really is (not its name).
	 */
	private const TYPES = array(
		IMAGETYPE_JPEG => 'image/jpeg',
		IMAGETYPE_PNG  => 'image/png',
		IMAGETYPE_WEBP => 'image/webp',
	);

	/**
	 * Email => user id, for avatars asked by email in this request.
	 *
	 * @var array<string, int>
	 */
	private static $by_email = array();

	/**
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'pre_get_avatar_data', array( self::class, 'avatar_data' ), 20, 2 );
		// A deleted account leaves no picture behind.
		add_action( 'deleted_user', array( self::class, 'remove' ) );
	}

	/**
	 * Whether this person may set their own photo: they answer tickets and
	 * the Helpdesk is on.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function can_set( int $user_id ): bool {
		return $user_id > 0 && ModuleManager::isEnabled( 'support' ) && Permissions::has_support_access( $user_id );
	}

	/**
	 * The photo's address, '' when they have none.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function url( int $user_id ): string {
		$relative = self::relative( $user_id );
		if ( '' === $relative ) {
			return '';
		}
		$uploads = wp_upload_dir( null, false );

		return empty( $uploads['baseurl'] ) ? '' : set_url_scheme( trailingslashit( $uploads['baseurl'] ) . $relative );
	}

	/**
	 * Check a picture and save it as this person's photo, in place of the old one.
	 *
	 * @param int    $user_id User.
	 * @param string $path    The uploaded file (PHP's temp file).
	 * @return string|WP_Error The new photo's address.
	 */
	public static function store( int $user_id, string $path ) {
		// Counted before any check, so bad files cost a try too.
		$hits = RateLimiter::hit( 'profile_photo:' . $user_id, HOUR_IN_SECONDS );
		if ( $hits > self::UPLOADS_PER_HOUR ) {
			return new WP_Error( 'photo_rate_limited', __( 'Too many uploads. Try again in an hour.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$size = is_file( $path ) ? (int) filesize( $path ) : 0;
		if ( $size <= 0 ) {
			return self::not_an_image();
		}
		if ( $size > self::MAX_BYTES ) {
			return self::too_large();
		}

		// What the file really is, from its bytes: the name and the type the
		// browser sent are the uploader's to choose.
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a broken file is an answer, not a warning.
		if ( ! is_array( $info ) || ! isset( self::TYPES[ $info[2] ] ) ) {
			return self::not_an_image();
		}
		if ( (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] * (int) $info[1] > self::MAX_PIXELS ) {
			return self::not_an_image();
		}

		$editor = wp_get_image_editor( $path, array( 'mime_type' => self::TYPES[ $info[2] ] ) );
		if ( is_wp_error( $editor ) ) {
			return self::not_an_image();
		}
		// A phone photo taken sideways is stored sideways with a note to turn it.
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}
		$dims = $editor->get_size();
		$w    = (int) ( $dims['width'] ?? 0 );
		$h    = (int) ( $dims['height'] ?? 0 );
		$side = min( $w, $h );
		if ( $side < 1 ) {
			return self::not_an_image();
		}
		$cropped = $editor->crop( (int) floor( ( $w - $side ) / 2 ), (int) floor( ( $h - $side ) / 2 ), $side, $side, self::SIZE, self::SIZE );
		if ( is_wp_error( $cropped ) ) {
			return self::not_an_image();
		}
		$editor->set_quality( 85 );

		$dir = self::directory();
		if ( '' === $dir ) {
			return new WP_Error( 'photo_storage', __( 'The photo could not be saved.', 'doublescale' ), array( 'status' => 500 ) );
		}
		// WebP keeps a transparent background; JPEG where the server cannot write WebP.
		$mime  = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'image/webp' : 'image/jpeg';
		$name  = bin2hex( random_bytes( 16 ) ) . ( 'image/webp' === $mime ? '.webp' : '.jpg' );
		$saved = $editor->save( $dir . '/' . $name, $mime );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return new WP_Error( 'photo_storage', __( 'The photo could not be saved.', 'doublescale' ), array( 'status' => 500 ) );
		}

		$old = self::relative( $user_id );
		update_user_meta( $user_id, self::META, self::DIR . '/' . wp_basename( (string) $saved['path'] ) );
		if ( '' !== $old ) {
			self::delete_file( $old );
		}
		self::remember( $user_id, true );

		return self::url( $user_id );
	}

	/**
	 * Remove this person's photo: back to Gravatar or their first letter.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	public static function remove( $user_id ): void {
		$user_id = (int) $user_id;
		// Most deleted accounts never had one: nothing to write.
		if ( '' === (string) get_user_meta( $user_id, self::META, true ) ) {
			return;
		}
		$relative = self::relative( $user_id );
		if ( '' !== $relative ) {
			self::delete_file( $relative );
		}
		delete_user_meta( $user_id, self::META );
		self::remember( $user_id, false );
	}

	/**
	 * `pre_get_avatar_data`: the DoubleScale photo for someone who has one.
	 * Setting a URL here also means a `default => '404'` caller (the chat)
	 * gets the photo instead of "not found".
	 *
	 * @param array<string, mixed> $args        Avatar arguments.
	 * @param mixed                $id_or_email User id, email, WP_User, WP_Post or WP_Comment.
	 * @return array<string, mixed>
	 */
	public static function avatar_data( $args, $id_or_email ) {
		if ( ! is_array( $args ) || ! empty( $args['force_default'] ) ) {
			return $args;
		}
		$with_photo = get_option( self::USERS_OPTION, array() );
		if ( empty( $with_photo ) || ! is_array( $with_photo ) ) {
			return $args;
		}
		$user_id = self::user_of( $id_or_email );
		if ( $user_id < 1 || ! in_array( $user_id, array_map( 'intval', $with_photo ), true ) ) {
			return $args;
		}
		$url = self::url( $user_id );
		if ( '' !== $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}

		return $args;
	}

	/**
	 * @param mixed $id_or_email What get_avatar() was given.
	 * @return int
	 */
	private static function user_of( $id_or_email ): int {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}
		if ( $id_or_email instanceof WP_User ) {
			return (int) $id_or_email->ID;
		}
		if ( $id_or_email instanceof WP_Post ) {
			return (int) $id_or_email->post_author;
		}
		if ( $id_or_email instanceof WP_Comment ) {
			return (int) $id_or_email->user_id;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$email = strtolower( $id_or_email );
			if ( ! isset( self::$by_email[ $email ] ) ) {
				$user                     = get_user_by( 'email', $email );
				self::$by_email[ $email ] = $user ? (int) $user->ID : 0;
			}
			return self::$by_email[ $email ];
		}

		return 0;
	}

	/**
	 * The stored path, '' when none or when it is not one of ours.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	private static function relative( int $user_id ): string {
		$relative = (string) get_user_meta( $user_id, self::META, true );

		return self::is_ours( $relative ) ? $relative : '';
	}

	/**
	 * Only a file this class named, so a changed meta value can never point
	 * the delete anywhere else.
	 *
	 * @param string $relative Path under uploads.
	 * @return bool
	 */
	private static function is_ours( string $relative ): bool {
		return 1 === preg_match( '#^' . preg_quote( self::DIR, '#' ) . '/[a-f0-9]{32}\.(webp|jpg)$#', $relative );
	}

	/**
	 * @param string $relative Path under uploads.
	 * @return void
	 */
	private static function delete_file( string $relative ): void {
		if ( ! self::is_ours( $relative ) ) {
			return;
		}
		$uploads = wp_upload_dir( null, false );
		$file    = trailingslashit( (string) $uploads['basedir'] ) . $relative;
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * The photos folder, made on first use, with an empty index so the folder
	 * cannot be listed (the random names are the only way in).
	 *
	 * @return string Absolute path, '' when it cannot be made.
	 */
	private static function directory(): string {
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['basedir'] ) ) {
			return '';
		}
		$dir = trailingslashit( (string) $uploads['basedir'] ) . self::DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		if ( ! is_file( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixed one-line file in our own folder.
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		return $dir;
	}

	/**
	 * Keep the list of who has a photo.
	 *
	 * @param int  $user_id User.
	 * @param bool $has     Has one now.
	 * @return void
	 */
	private static function remember( int $user_id, bool $has ): void {
		$list = array_map( 'intval', (array) get_option( self::USERS_OPTION, array() ) );
		$list = array_values( array_diff( $list, array( $user_id ) ) );
		if ( $has ) {
			$list[] = $user_id;
		}
		update_option( self::USERS_OPTION, $list, true );

		/**
		 * A team member's photo changed (added, replaced or removed): lets the
		 * chat's cached faces refresh.
		 *
		 * @param int $user_id User.
		 */
		do_action( 'doublescale_profile_photo_changed', $user_id );
	}

	/**
	 * @return WP_Error
	 */
	public static function not_an_image(): WP_Error {
		return new WP_Error( 'photo_not_image', __( 'Choose a JPG, PNG or WebP image.', 'doublescale' ), array( 'status' => 400 ) );
	}

	/**
	 * @return WP_Error
	 */
	public static function too_large(): WP_Error {
		return new WP_Error( 'photo_too_large', __( 'The photo can be up to 2 MB.', 'doublescale' ), array( 'status' => 400 ) );
	}
}
