<?php
/**
 * Shared one-time provisioner for customer-facing shortcode pages.
 *
 * Every customer-facing surface in DoubleScale (proposal, invoice, support
 * portal, booking, client portal, and Pro's credit note / project / contract)
 * renders through a shortcode that must live on a published WordPress page.
 * Until that page exists the corresponding `*Url::get_page_url()` returns an
 * empty string, so "Send proposal" mails a link to nowhere — with no error, and
 * no hint that a page was ever required.
 *
 * Historically only the Client Portal auto-created its page
 * ({@see \DoubleScale\Modules\Portal\Services\PortalPageProvisioner}); every
 * other surface left the admin to hand-build a page per document type. This
 * class generalizes that behavior so each module registers a descriptor and all
 * pages are provisioned together, the way WooCommerce provisions My Account /
 * Cart / Checkout on install.
 *
 * Discipline (inherited from the Portal provisioner, which now delegates here):
 *   - Runs once per shortcode, gated by its own `*_page_provisioned` flag. After
 *     the first attempt we never recreate automatically — an admin who later
 *     trashes a page is not fought; they recreate it from the settings card.
 *   - Adopts an existing page containing the shortcode instead of creating a
 *     duplicate, so hand-built pages (and pages from before this class existed)
 *     keep working untouched.
 *   - Hooked on `admin_init`, not activation: the `page` post type and
 *     `home_url()` are guaranteed ready there, module gating has already run,
 *     and front-end requests are never touched.
 *   - Flags are set only on success, so a failed creation retries next load.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Services;

defined( 'ABSPATH' ) || exit;

/**
 * ShortcodePageProvisioner.
 */
final class ShortcodePageProvisioner {

	/**
	 * Registered descriptors keyed by shortcode name.
	 *
	 * Each descriptor: array{title: string, slug: string}.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static $registered = array();

	/**
	 * Shortcodes whose option names predate this class. Renaming these would
	 * make every existing install look unprovisioned and create a duplicate
	 * page on the next admin load, so the legacy keys are preserved verbatim.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const LEGACY_OPTION_NAMES = array(
		'doublescale_client_portal' => array(
			'flag'    => 'doublescale_client_portal_page_provisioned',
			'page_id' => 'doublescale_client_portal_page_id',
		),
	);

	/**
	 * Register a shortcode for auto-provisioning. Idempotent: the first
	 * registration wins, so a module booting twice (or Pro re-registering a
	 * free shortcode) never produces a duplicate entry.
	 *
	 * @param string                $shortcode  Shortcode name, without brackets.
	 * @param array<string, string> $descriptor Page title + slug.
	 * @return void
	 */
	public static function register( string $shortcode, array $descriptor ): void {
		$shortcode = trim( $shortcode );
		if ( '' === $shortcode || isset( self::$registered[ $shortcode ] ) ) {
			return;
		}

		$title = isset( $descriptor['title'] ) ? (string) $descriptor['title'] : $shortcode;
		$slug  = isset( $descriptor['slug'] ) ? (string) $descriptor['slug'] : str_replace( '_', '-', $shortcode );

		self::$registered[ $shortcode ] = array(
			'title' => $title,
			'slug'  => $slug,
		);
	}

	/**
	 * All registered descriptors.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_registered(): array {
		return self::$registered;
	}

	/**
	 * Option flag recording that the one-time auto-provision ran for a
	 * shortcode.
	 *
	 * @param string $shortcode Shortcode name.
	 * @return string
	 */
	public static function provisioned_flag( string $shortcode ): string {
		if ( isset( self::LEGACY_OPTION_NAMES[ $shortcode ]['flag'] ) ) {
			return self::LEGACY_OPTION_NAMES[ $shortcode ]['flag'];
		}

		return $shortcode . '_page_provisioned';
	}

	/**
	 * Option holding the canonical page id for a shortcode. Preferred over the
	 * content scan so resolution is O(1) and cannot pick the wrong page when
	 * several embed the same shortcode.
	 *
	 * @param string $shortcode Shortcode name.
	 * @return string
	 */
	public static function page_id_option( string $shortcode ): string {
		if ( isset( self::LEGACY_OPTION_NAMES[ $shortcode ]['page_id'] ) ) {
			return self::LEGACY_OPTION_NAMES[ $shortcode ]['page_id'];
		}

		return $shortcode . '_page_id';
	}

	/**
	 * `admin_init` entry point: provision every registered shortcode page that
	 * has not been attempted yet.
	 *
	 * @return void
	 */
	public static function maybe_provision_all(): void {
		// First creation should land on a real admin page load, not mid-AJAX.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}

		foreach ( array_keys( self::$registered ) as $shortcode ) {
			if ( 'yes' === get_option( self::provisioned_flag( $shortcode ) ) ) {
				continue;
			}

			self::provision( $shortcode );
		}
	}

	/**
	 * Adopt-or-create the page for one shortcode and record it. Sets the
	 * one-time flag only on success, so a failed creation retries on the next
	 * admin load. Shared by the auto path and the settings "Create page" action.
	 *
	 * @param string $shortcode Shortcode name.
	 * @return int Resolved page id, or 0 on failure.
	 */
	public static function provision( string $shortcode ): int {
		if ( ! isset( self::$registered[ $shortcode ] ) ) {
			return 0;
		}

		$page_id = self::ensure_page( $shortcode );

		if ( $page_id > 0 ) {
			update_option( self::provisioned_flag( $shortcode ), 'yes' );
		}

		return $page_id;
	}

	/**
	 * Status payload for one shortcode, backing the admin settings card.
	 *
	 * @param string $shortcode Shortcode name.
	 * @return array<string, mixed>
	 */
	public static function get_status( string $shortcode ): array {
		$page_id = self::resolve_existing_page_id( $shortcode );
		$exists  = $page_id > 0;
		$title   = isset( self::$registered[ $shortcode ]['title'] )
			? self::$registered[ $shortcode ]['title']
			: $shortcode;

		return array(
			'title'       => $title,
			'provisioned' => 'yes' === get_option( self::provisioned_flag( $shortcode ) ),
			'page_id'     => $page_id,
			'exists'      => $exists,
			'view_url'    => $exists ? (string) get_permalink( $page_id ) : '',
			'edit_url'    => $exists ? (string) get_edit_post_link( $page_id, 'raw' ) : '',
			'shortcode'   => '[' . $shortcode . ']',
		);
	}

	/**
	 * Status for every registered shortcode, keyed by shortcode name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all_status(): array {
		$all = array();

		foreach ( array_keys( self::$registered ) as $shortcode ) {
			$all[ $shortcode ] = self::get_status( $shortcode );
		}

		return $all;
	}

	/**
	 * Find a published page embedding the shortcode. Public so each module's
	 * `*Url` service can share one lookup instead of duplicating the query.
	 *
	 * @param string $shortcode Shortcode name.
	 * @return int Page id, or 0.
	 */
	public static function find_existing_page_id( string $shortcode ): int {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $shortcode ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off page lookup, result cached in a transient by the caller.
		$page_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'page'
				AND post_status IN ('publish', 'private')
				AND post_content LIKE %s
				ORDER BY ID ASC
				LIMIT 1",
				$like
			)
		);

		if ( $page_id > 0 ) {
			return $page_id;
		}

		// Block editor / page builders sometimes store the tag without the
		// literal `[shortcode]` brackets in post_content.
		$pages = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => array( 'publish', 'private' ),
				'posts_per_page'         => 50,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $pages as $page ) {
			if ( ! $page instanceof \WP_Post ) {
				continue;
			}
			if ( has_shortcode( $page->post_content, $shortcode ) || false !== strpos( $page->post_content, $shortcode ) ) {
				return (int) $page->ID;
			}
		}

		return 0;
	}

	/**
	 * Resolve the live page for a shortcode: the recorded id when still valid,
	 * else a content scan (covers a hand-built page, or one created before this
	 * class existed).
	 *
	 * @param string $shortcode Shortcode name.
	 * @return int Page id, or 0.
	 */
	public static function resolve_page_id( string $shortcode ): int {
		$stored = (int) get_option( self::page_id_option( $shortcode ), 0 );
		if ( $stored > 0 && self::page_embeds_shortcode( $stored, $shortcode ) ) {
			return $stored;
		}

		return self::find_existing_page_id( $shortcode );
	}

	/**
	 * Reset the registry between tests. Test-only seam: the registry is static
	 * process state, so without this a registration leaks into the next case.
	 *
	 * @return void
	 */
	public static function reset_registry_for_tests(): void {
		self::$registered = array();
	}

	/**
	 * Resolve the live page id for the settings card.
	 *
	 * @param string $shortcode Shortcode name.
	 * @return int Page id, or 0.
	 */
	private static function resolve_existing_page_id( string $shortcode ): int {
		$stored = (int) get_option( self::page_id_option( $shortcode ), 0 );
		if ( $stored > 0 && self::page_is_live( $stored ) ) {
			return $stored;
		}

		return self::find_existing_page_id( $shortcode );
	}

	/**
	 * Adopt an existing shortcode page or create a new one, recording its id and
	 * flushing any cached permalink.
	 *
	 * @param string $shortcode Shortcode name.
	 * @return int Page id, or 0 on failure.
	 */
	private static function ensure_page( string $shortcode ): int {
		$existing = self::find_existing_page_id( $shortcode );
		if ( $existing > 0 ) {
			update_option( self::page_id_option( $shortcode ), $existing );
			self::flush_url_cache( $shortcode );

			return $existing;
		}

		$page_id = self::create_page( $shortcode );
		if ( $page_id > 0 ) {
			update_option( self::page_id_option( $shortcode ), $page_id );
			self::flush_url_cache( $shortcode );
		}

		return $page_id;
	}

	/**
	 * Insert the published page hosting the shortcode. The body is wrapped in a
	 * shortcode block so it is clean in the block editor and still renders on
	 * classic themes.
	 *
	 * @param string $shortcode Shortcode name.
	 * @return int New page id, or 0 on failure.
	 */
	private static function create_page( string $shortcode ): int {
		$descriptor = self::$registered[ $shortcode ];

		$page_id = wp_insert_post(
			array(
				'post_title'   => $descriptor['title'],
				'post_name'    => $descriptor['slug'],
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => "<!-- wp:shortcode -->\n[" . $shortcode . "]\n<!-- /wp:shortcode -->",
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Failed to auto-create a shortcode page',
					array(
						'source'    => 'shortcode-page-provision',
						'shortcode' => $shortcode,
						'error'     => is_wp_error( $page_id ) ? $page_id->get_error_message() : 'unknown',
					)
				);
			}

			return 0;
		}

		return (int) $page_id;
	}

	/**
	 * Let the owning module drop its cached permalink once a page appears.
	 * Modules subscribe rather than this class knowing every `*Url` service.
	 *
	 * @param string $shortcode Shortcode name.
	 * @return void
	 */
	private static function flush_url_cache( string $shortcode ): void {
		/**
		 * Fires after a shortcode page is adopted or created.
		 *
		 * @param string $shortcode Shortcode name.
		 */
		do_action( 'doublescale_shortcode_page_provisioned', $shortcode );
	}

	/**
	 * Whether a page id points at a live page that still embeds the shortcode.
	 * Guards against a stored id whose page was trashed, deleted, or had the
	 * shortcode edited out.
	 *
	 * @param int    $page_id   Candidate page id.
	 * @param string $shortcode Shortcode name.
	 * @return bool
	 */
	private static function page_embeds_shortcode( int $page_id, string $shortcode ): bool {
		if ( ! self::page_is_live( $page_id ) ) {
			return false;
		}

		$post = get_post( $page_id );

		return has_shortcode( (string) $post->post_content, $shortcode )
			|| false !== strpos( (string) $post->post_content, $shortcode );
	}

	/**
	 * Whether a page id points at a live (publish/private) page.
	 *
	 * @param int $page_id Page id.
	 * @return bool
	 */
	private static function page_is_live( int $page_id ): bool {
		$post = get_post( $page_id );

		return $post instanceof \WP_Post
			&& 'page' === $post->post_type
			&& in_array( $post->post_status, array( 'publish', 'private' ), true );
	}
}
