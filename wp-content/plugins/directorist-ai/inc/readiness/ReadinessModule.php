<?php
/** Native settings and anonymous virtual Markdown route registration.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Readiness;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Fixed WordPress integration callbacks.

/** The module has no AI, runtime, accounting or job dependency. */
final class ReadinessModule {
	public function hooks(): void {
		if ( ! ( new \DirectoristAIPro\Domain\DirectoristInspector() )->available() ) {
			return;
		}
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'template_redirect', array( $this, 'serve' ), -20 );
		add_action( 'wp_head', array( $this, 'head' ) );
		add_action( 'send_headers', array( $this, 'discovery_header' ) );
		foreach ( array( 'save_post', 'before_delete_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'atbdp_listing_expired' ) as $hook ) {
			add_action( $hook, array( $this, 'invalidate_listing' ) );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'invalidate_meta' ), 10, 3 );
		}
		add_action( 'set_object_terms', array( $this, 'invalidate_listing' ) );
		foreach ( array( 'edited_term', 'deleted_term', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'invalidate_all' ) );
		}
		add_action( 'updated_option', array( $this, 'invalidate_option' ), 10, 1 );
	}

	public function register_routes(): void {
		$permission = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'directorist-ai/v1',
			'/readiness',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $permission,
					'callback'            => function () {
						try {
							return $this->status();
						} catch ( \Throwable $error ) {
							return new \WP_Error( 'readiness_inspection_unavailable', __( 'Current public listing access details are unavailable. Refresh and try again.', 'directorist-ai' ), array( 'status' => 503 ) );
						}
					},
				),
				array(
					'methods'             => 'PUT',
					'permission_callback' => $permission,
					'callback'            => function ( \WP_REST_Request $request ) {
						// Cookie-auth REST calls must present WordPress's native REST nonce.
						if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
							return new \WP_Error( 'readiness_nonce_required', __( 'Refresh this page before saving export settings.', 'directorist-ai' ), array( 'status' => 403 ) );
						}
						try {
							$input = $request->get_json_params();
							if ( ! is_array( $input ) ) {
								throw new \InvalidArgumentException( 'Export settings must be an object.' );
							}
							Settings::save( $input );
							$this->invalidate_all();
							return $this->status();
						} catch ( \InvalidArgumentException $error ) {
							return new \WP_Error( 'readiness_invalid_settings', $error->getMessage(), array( 'status' => 400 ) );
						} catch ( \Throwable $error ) {
							return new \WP_Error( 'readiness_inspection_unavailable', __( 'Current public listing access details are unavailable. Refresh and try again.', 'directorist-ai' ), array( 'status' => 503 ) );
						}
					},
				),
			)
		);
		$this->register_admin_routes();
		$this->register_review_routes();
	}

	private function register_review_routes(): void {
		$permission = static fn(): bool => current_user_can( 'manage_options' );
		$routes     = array(
			'/readiness/site' => array(
				'GET' => fn(): array => $this->site_status(),
				'PUT' => function ( \WP_REST_Request $request ): array {
										Settings::save_publication( $request->get_json_params() );
										return $this->site_status(); },
			),
			'/readiness/directories/(?P<id>[1-9][0-9]{0,9})/review' => array(
				'GET' => static fn( \WP_REST_Request $request ): array => ( new AdminInspector() )->directory( (int) $request['id'], self::field_page( $request ) ),
				'PUT' => static function ( \WP_REST_Request $request ): array {
										DirectoryReview::save( (int) $request['id'], $request->get_json_params() );
										return ( new AdminInspector() )->directory( (int) $request['id'] ); },
			),
			'/readiness/directories/(?P<id>[1-9][0-9]{0,9})/verification' => array( 'POST' => static fn( \WP_REST_Request $request ): array => Verification::save( (int) $request['id'], $request->get_json_params() ) ),
		);
		foreach ( $routes as $path => $methods ) {
			$definitions = array();
			foreach ( $methods as $method => $callback ) {
				$definitions[] = array(
					'methods'             => $method,
					'permission_callback' => $permission,
					'callback'            => static function ( \WP_REST_Request $request ) use ( $method, $callback ) {
						try {
							if ( 'GET' !== $method ) {
								if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
									return new \WP_Error( 'readiness_nonce_required', __( 'Refresh before saving.', 'directorist-ai' ), array( 'status' => 403 ) ); }
								if ( strlen( $request->get_body() ) > Verification::MAX_REQUEST_BYTES || ! is_array( $request->get_json_params() ) ) {
									throw new \InvalidArgumentException( 'Use a bounded settings or check request.' ); }
							}
							return $callback( $request );
						} catch ( \DomainException $error ) {
							return new \WP_Error( 'readiness_changed', $error->getMessage(), array( 'status' => 409 ) ); } catch ( \InvalidArgumentException $error ) {
							return new \WP_Error( 'readiness_invalid_settings', $error->getMessage(), array( 'status' => 400 ) ); } catch ( \Throwable $error ) {
								return new \WP_Error( 'readiness_inspection_unavailable', __( 'Current details are unavailable. Refresh and try again.', 'directorist-ai' ), array( 'status' => 503 ) ); }
					},
				);
			}
			register_rest_route( 'directorist-ai/v1', $path, $definitions );
		}
	}

	public function site_status(): array {
		$legacy = $this->status();
		return array(
			'settings'             => Settings::publication(),
			'saved_policy_hash'    => Settings::policy_hash(),
			'state'                => ! Settings::get()['enabled'] ? 'not_enabled' : ( '1' !== (string) get_option( 'blog_public', '1' ) ? 'blocked' : 'choose_directory' ),
			'reason'               => ! Settings::get()['enabled'] ? 'export_disabled' : ( '1' !== (string) get_option( 'blog_public', '1' ) ? 'site_indexing_disabled' : 'select_directory' ),
			'directory_inventory'  => $legacy['directory_inventory'],
			'selected_directories' => $legacy['selected_directories'],
			'entry_url'            => $legacy['entry_url'],
			'scoped_entry_url'     => $legacy['scoped_entry_url'],
			'root_url'             => $legacy['root_url'],
			'root_conflict'        => $legacy['root_conflict'],
			'admin_links'          => $legacy['admin_links'],
			'defaults'             => $legacy['defaults'],
			'provider_calls'       => 0,
		);
	}

	private function register_admin_routes(): void {
		$permission = static fn(): bool => current_user_can( 'manage_options' );
		$routes     = array(
			'/readiness/overview'    => static fn( \WP_REST_Request $request ): array => ( new AdminInspector() )->overview( self::search( $request ), self::page( $request ) ),
			'/readiness/directories' => static fn( \WP_REST_Request $request ): array => ( new AdminInspector() )->directories( self::search( $request ), self::page( $request ) ),
			'/readiness/directories/(?P<id>[1-9][0-9]{0,9})' => static fn( \WP_REST_Request $request ): array => ( new AdminInspector() )->directory( (int) $request['id'], self::field_page( $request ) ),
			'/readiness/listings'    => static fn( \WP_REST_Request $request ): array => ( new AdminInspector() )->listings( self::directory_id( $request ), self::search( $request ), self::page( $request ) ),
			'/readiness/listings/(?P<id>[1-9][0-9]{0,9})/audit' => static fn( \WP_REST_Request $request ): array => ( new AdminInspector() )->listing( (int) $request['id'] ),
		);
		foreach ( $routes as $route => $read ) {
			register_rest_route(
				'directorist-ai/v1',
				$route,
				array(
					'methods'             => 'GET',
					'permission_callback' => $permission,
					'callback'            => static function ( \WP_REST_Request $request ) use ( $read ) {
						try {
							return $read( $request );
						} catch ( \InvalidArgumentException $error ) {
							return new \WP_Error( 'readiness_invalid_selection', $error->getMessage(), array( 'status' => 400 ) );
						} catch ( \Throwable $error ) {
							return new \WP_Error( 'readiness_inspection_unavailable', __( 'Current public listing access details are unavailable. Refresh and try again.', 'directorist-ai' ), array( 'status' => 503 ) ); }
					},
				)
			);
		}
	}

	private static function field_page( \WP_REST_Request $request ): int {
		$value = $request->get_param( 'field_page' ) ?? '1';
		if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || (int) $value < 1 || (int) $value > 2147483647 ) {
			throw new \InvalidArgumentException( 'Choose a valid field page.' ); }
		return (int) $value;
	}

	private static function page( \WP_REST_Request $request ): int {
		$value = $request->get_param( 'page' ) ?? '1';
		if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || (int) $value < 1 ) {
			throw new \InvalidArgumentException( 'Choose a valid page.' ); }
		return (int) $value;
	}

	private static function search( \WP_REST_Request $request ): string {
		$value = $request->get_param( 'search' ) ?? '';
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) || mb_strlen( $value ) > 100 || preg_match( '/[\x00-\x1f\x7f]/u', $value ) ) {
			throw new \InvalidArgumentException( 'Use a search of at most 100 characters.' ); }
		return $value;
	}

	private static function directory_id( \WP_REST_Request $request ): int {
		$value = $request->get_param( 'directory_id' );
		if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || (int) $value < 1 ) {
			throw new \InvalidArgumentException( 'Choose an existing directory.' ); }
		return (int) $value;
	}

	public function status(): array {
		$settings    = Settings::get();
		$admin       = new AdminInspector();
		$inventory   = $admin->directories();
		$selected    = $admin->selected();
		$directories = array();
		foreach ( array_merge( $inventory['items'], $selected ) as $directory ) {
			$directories[ $directory['id'] ] = $directory; }
		$eligible_selected = (bool) array_filter( $selected, static fn( array $directory ): bool => $directory['eligible'] );
		$active            = Settings::active() && $eligible_selected;
		$status            = ! $settings['enabled'] ? 'disabled' : ( '1' !== (string) get_option( 'blog_public', '1' ) ? 'site_indexing_disabled' : ( ! $settings['directories'] ? 'no_directories_selected' : ( get_directorist_option( 'disable_single_listing', false ) ? 'single_listing_disabled' : ( ! $eligible_selected ? 'no_eligible_directories' : 'enabled' ) ) ) );
		return array(
			'settings'              => $settings,
			'directories'           => array_values( $directories ),
			'directory_inventory'   => $inventory,
			'selected_directories'  => $selected,
			'active'                => $active,
			'status'                => $status,
			'verification'          => 'not_checked',
			'entry_url'             => Publication::entry_url(),
			'scoped_entry_url'      => home_url( '/directorist/llms.txt' ),
			'root_url'              => home_url( '/llms.txt' ),
			'root_conflict'         => Publication::root_conflict(),
			'page_size'             => Publication::PAGE_SIZE,
			'index_candidate_limit' => null,
			'policy_revision'       => Settings::REVISION,
			'saved_policy_hash'     => hash( 'sha256', serialize( $settings ) ),
			'provider_calls'        => 0,
			'admin_links'           => array(
				'reading'     => admin_url( 'options-reading.php' ),
				'directorist' => admin_url( 'edit.php?post_type=' . ATBDP_POST_TYPE . '&page=atbdp-settings' ),
				'directories' => admin_url( 'edit.php?post_type=' . ATBDP_POST_TYPE . '&page=' . ( get_directorist_option( 'enable_multi_directory', false ) ? 'atbdp-directory-types' : 'atbdp-layout-builder' ) ),
			),
			'defaults'              => array(
				'discovery_title'   => AdminInspector::label( get_bloginfo( 'name' ) . ' — Directorist', 120 ),
				'discovery_summary' => 'Current public listing facts with canonical source links. No AI-generated summaries.',
			),
			'limits'                => array(
				'selected_directories'          => null,
				'excluded_fields_per_directory' => null,
				'directory_review_bytes'        => 65536,
				'directory_page_size'           => AdminInspector::PAGE_SIZE,
				'field_audit'                   => AdminInspector::MAX_FIELDS,
				'markdown_bytes'                => Publication::MAX_DOCUMENT_BYTES,
			),
			'limitations'           => array( 'Saved policy is not an anonymous HTTP verification result.', 'Custom single pages require a verified visibility adapter and are withheld.', 'Only native public fields are exported. Conditions and plan permissions apply per listing.', 'Physical files and other root generators retain ownership.' ),
		);
	}

	/** Exact paths work with plain or pretty permalinks without persistent rewrite changes. */
	public static function route( string $request_uri ): ?array {
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( ! is_string( $path ) || ( $base && 0 !== strpos( $path, $base . '/' ) ) ) {
			return null;
		}
		$path = substr( $path, strlen( $base ) );
		if ( '/directorist/llms.txt' === $path ) {
			return array( 'kind' => 'discovery' );
		}
		if ( '/llms.txt' === $path && 'auto' === Settings::get()['root_mode'] && ! Publication::root_conflict() ) {
			return array( 'kind' => 'discovery' );
		}
		if ( preg_match( '~^/directorist/discovery/after/([1-9][0-9]{0,9})\.md$~D', $path, $matches ) ) {
			return array(
				'kind'  => 'discovery',
				'after' => (int) $matches[1],
			);
		}
		if ( preg_match( '~^/directorist/listings/([1-9][0-9]{0,9})\.md$~D', $path, $matches ) ) {
			return array(
				'kind' => 'listing',
				'id'   => (int) $matches[1],
			);
		}
		if ( preg_match( '~^/directorist/directories/([1-9][0-9]{0,9})/after/([1-9][0-9]{0,9})\.md$~D', $path, $matches ) ) {
			return array(
				'kind'  => 'index',
				'id'    => (int) $matches[1],
				'page'  => 1,
				'after' => (int) $matches[2],
			);
		}
		if ( preg_match( '~^/directorist/directories/([1-9][0-9]{0,9})/page/([1-9][0-9]{0,9})\.md$~D', $path, $matches ) ) {
			return array(
				'kind' => 'index',
				'id'   => (int) $matches[1],
				'page' => (int) $matches[2],
			);
		}
		return null;
	}

	public function serve(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Used only for exact fixed path matching; never interpolated into output or queries.
		$route = self::route( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( null === $route ) {
			return;
		}
		$method      = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$publication = new Publication();
		try {
			$result = ! in_array( $method, array( 'GET', 'HEAD' ), true ) ? array(
				'status' => 405,
				'body'   => "Use GET or HEAD.\n",
			) : ( 'listing' === $route['kind'] ? $publication->listing( $route['id'] ) : ( 'index' === $route['kind'] ? $publication->index( $route['id'], $route['page'], $route['after'] ?? null ) : $publication->discovery( $route['after'] ?? 0 ) ) );
		} catch ( \Throwable $error ) {
			$result = array(
				'status' => 503,
				'body'   => "Public Directorist facts are temporarily unavailable.\n",
			);
		}
		status_header( $result['status'] );
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'Content-Type: text/markdown; charset=UTF-8', true );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'X-Robots-Tag: noindex', true );
		header( 'Vary: Cookie', false );
		if ( 405 === $result['status'] ) {
			header( 'Allow: GET, HEAD' );
		}
		if ( ! empty( $result['canonical'] ) ) {
			header( 'Link: <' . Markdown::url( $result['canonical'] ) . '>; rel="canonical"', false );
		}
		if ( 'HEAD' !== $method ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This is a plain Markdown response; native data was stripped and Markdown-escaped.
			echo $result['body'];
		}
		exit;
	}

	public function head(): void {
		if ( ! Settings::active() ) {
			return;
		}
		echo '<link rel="describedby" type="text/markdown" href="' . esc_url( Publication::entry_url() ) . '" title="Directorist public listing facts" />' . "\n";
		if ( is_singular( ATBDP_POST_TYPE ) ) {
			try {
				$fact = ( new NativeFacts() )->capture( (int) get_queried_object_id() );
			} catch ( \Throwable $error ) {
				$fact = null;
			}
			if ( $fact && strlen( Markdown::listing( $fact ) ) <= Publication::MAX_DOCUMENT_BYTES ) {
				echo '<link rel="alternate" type="text/markdown" href="' . esc_url( Publication::listing_url( (int) get_queried_object_id() ) ) . '" title="Public listing Markdown" />' . "\n";
			}
		}
	}

	public function discovery_header(): void {
		if ( Settings::active() && ! headers_sent() ) {
			header( 'Link: <' . Markdown::url( Publication::entry_url() ) . '>; rel="describedby"; type="text/markdown"', false );
		}
	}

	public function invalidate_listing( int $id ): void {
		delete_transient( 'dai_ready_' . get_current_blog_id() . '_' . $id );
		if ( get_post( $id ) instanceof \WP_Post && ATBDP_POST_TYPE === get_post( $id )->post_type ) {
			DirectoryReview::touch( (int) directorist_get_listing_directory( $id ) ); }
	}

	public function invalidate_meta( $meta_id, int $id, string $key = '' ): void {
		delete_transient( 'dai_ready_' . get_current_blog_id() . '_' . $id );
		// Verified native counter and WordPress editing bookkeeping are not public facts.
		if ( in_array( $key, array( '_atbdp_post_views_count', '_edit_lock', '_edit_last' ), true ) ) {
			return; }
		if ( get_post( $id ) instanceof \WP_Post && ATBDP_POST_TYPE === get_post( $id )->post_type ) {
			DirectoryReview::touch( (int) directorist_get_listing_directory( $id ) ); }
	}

	public function invalidate_option( string $option ): void {
		if ( in_array( $option, array( Settings::OPTION, 'blog_public', 'atbdp_option', 'active_plugins', 'permalink_structure' ), true ) ) {
			$this->invalidate_all();
		}
	}

	public function invalidate_all(): void {
		global $wpdb;
		// Only this site's feature-owned, expiring formatting entries; no native/runtime storage.
		$prefix = 'dai_ready_' . get_current_blog_id() . '_';
		// Eager cleanup is bounded. Remaining entries cannot pass fresh eligibility/revision checks and expire within an hour.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 250", $wpdb->esc_like( '_transient_' . $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Feature-owned transient namespace; dynamic table is the native trusted options table.
		foreach ( $names as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
	}
}
