<?php
/** Virtual discovery documents and versioned internal formatting cache.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Readiness;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Fixed public publication routes.

/** All old URLs recheck publication eligibility, including cache hits. */
final class Publication {
	public const PAGE_SIZE = 50;
	// Legacy page URLs remain readable; cursor links have no product coverage ceiling.
	public const MAX_PAGE           = 200;
	public const MAX_DOCUMENT_BYTES = 262144;
	private NativeFacts $facts;

	public function __construct( ?NativeFacts $facts = null ) {
		$this->facts = $facts ?? new NativeFacts();
	}

	public static function listing_url( int $id ): string {
		return home_url( '/directorist/listings/' . $id . '.md' );
	}

	public static function directory_url( int $id, int $page = 1 ): string {
		return home_url( '/directorist/directories/' . $id . '/page/' . $page . '.md' );
	}

	public static function cursor_url( int $id, int $after = 0 ): string {
		return $after ? home_url( '/directorist/directories/' . $id . '/after/' . $after . '.md' ) : self::directory_url( $id );
	}

	public static function discovery_url( int $after ): string {
		return $after ? home_url( '/directorist/discovery/after/' . $after . '.md' ) : self::entry_url();
	}

	/** The private query marker keeps this keyset fence off other native queries. */
	public static function candidates( int $directory, int $after = 0, int $offset = 0 ): array {
		global $wpdb;
		$marker = bin2hex( random_bytes( 8 ) );
		$fence  = static function ( string $where, \WP_Query $query ) use ( $after, $marker, $wpdb ): string {
			return $query->get( '_dai_readiness_cursor' ) === $marker ? $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after ) : $where;
		};
		add_filter( 'posts_where', $fence, 20, 2 );
		try {
			$query = new \WP_Query(
				array(
					'post_type'              => ATBDP_POST_TYPE,
					'post_status'            => 'publish',
					'has_password'           => false,
					'posts_per_page'         => self::PAGE_SIZE + 1,
					'offset'                 => $offset,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'cache_results'          => false,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
					'suppress_filters'       => false,
					'_dai_readiness_cursor'  => $marker,
					'_dai_readiness_after'   => $after,
					'tax_query'              => array(
						array(
							'taxonomy' => ATBDP_DIRECTORY_TYPE,
							'field'    => 'term_id',
							'terms'    => array( $directory ),
						),
					),
				)
			);
			return array_map( 'intval', $query->posts );
		} finally {
			remove_filter( 'posts_where', $fence, 20 );
		}
	}

	public static function root_conflict(): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( is_file( get_home_path() . 'llms.txt' ) ) {
			return 'physical_file';
		}
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( $plugins as $plugin ) {
			if ( preg_match( '~(?:wordpress-seo|seo-by-rank-math|all-in-one-seo|autodescription|llms)~i', $plugin ) ) {
				return 'existing_generator';
			}
		}
		foreach ( (array) get_option( 'rewrite_rules', array() ) as $pattern => $target ) {
			if ( false !== stripos( $pattern . $target, 'llms' ) ) {
				return 'existing_rewrite';
			}
		}
		global $wp_filter;
		foreach ( array_keys( (array) $wp_filter ) as $hook ) {
			if ( false !== stripos( (string) $hook, 'llms' ) ) {
				return 'existing_hook';
			}
			foreach ( (array) ( $wp_filter[ $hook ]->callbacks ?? array() ) as $callbacks ) {
				foreach ( $callbacks as $entry ) {
					$callback = $entry['function'] ?? null;
					$name     = is_string( $callback ) ? $callback : ( is_array( $callback ) ? ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . (string) $callback[1] : ( is_object( $callback ) ? get_class( $callback ) : '' ) );
					if ( false !== stripos( $name, 'llms' ) ) {
						return 'existing_generator_callback';
					}
				}
			}
		}
		return '';
	}

	public static function entry_url(): string {
		return home_url( 'scoped_only' === Settings::get()['root_mode'] || self::root_conflict() ? '/directorist/llms.txt' : '/llms.txt' );
	}

	public function listing( int $id ): array {
		try {
			$current = $this->facts->capture( $id );
			if ( null === $current ) {
				delete_transient( 'dai_ready_' . get_current_blog_id() . '_' . $id );
				return $this->missing();
			}
			$key    = 'dai_ready_' . get_current_blog_id() . '_' . $id;
			$cached = get_transient( $key );
			$body   = is_array( $cached ) && hash_equals( $current['revision'], (string) ( $cached['revision'] ?? '' ) ) ? $cached['body'] : Markdown::listing( $current );
			if ( strlen( $body ) > self::MAX_DOCUMENT_BYTES ) {
				delete_transient( $key );
				return array(
					'status' => 413,
					'body'   => "Public facts exceed the 256 KiB Markdown limit. Read the canonical listing.\n",
				);
			}
			// A native read hook may mutate the source during reconstruction: reject drift before delivery.
			$final = $this->facts->capture( $id );
			if ( null === $final ) {
				delete_transient( $key );
				return $this->missing();
			}
			if ( ! hash_equals( $current['revision'], $final['revision'] ) ) {
				delete_transient( $key );
				return array(
					'status' => 503,
					'body'   => "Public listing changed during publication. Retry the source URL.\n",
				);
			}
			set_transient(
				$key,
				array(
					'revision' => $current['revision'],
					'body'     => $body,
				),
				HOUR_IN_SECONDS
			);
			return array(
				'status'    => 200,
				'body'      => $body,
				'canonical' => $current['canonical'],
			);
		} catch ( \Throwable $error ) {
			return $this->missing();
		}
	}

	public function index( int $directory, int $page = 1, ?int $after = null ): array {
		return NativeFacts::anonymous(
			function () use ( $directory, $page, $after ): array {
				if ( $page < 1 || $page > self::MAX_PAGE || ! $this->facts->directory( $directory ) ) {
						return $this->missing();
				}
				$ids = self::candidates( $directory, $after ?? 0, null === $after ? ( $page - 1 ) * self::PAGE_SIZE : 0 );
				if ( ! $ids && ( $page > 1 || $after > 0 ) ) {
					return $this->missing();
				}
				$term     = get_term( $directory, ATBDP_DIRECTORY_TYPE );
				$lines    = array( '# ' . Markdown::text( $term->name ) . ' — public listings', '', 'Current eligible listings are checked on every request. Follow the continuation link for more listings.', '', '## Listings', '' );
				$captured = array();
				foreach ( array_slice( $ids, 0, self::PAGE_SIZE ) as $id ) {
					try {
						$fact = $this->facts->capture( $id );
					} catch ( \Throwable $error ) {
						$fact = null;
					}
					if ( null !== $fact && $directory === $fact['directory_id'] && strlen( Markdown::listing( $fact ) ) <= self::MAX_DOCUMENT_BYTES ) {
						$captured[ $id ] = $fact;
						$title           = Markdown::plain( (string) $fact['title'] );
						$label           = mb_substr( $title, 0, 160 ) . ( mb_strlen( $title ) > 160 ? '…' : '' );
						$lines[]         = '- ' . Markdown::link( $label, self::listing_url( $id ) ) . ' — ' . Markdown::link( 'Canonical source', $fact['canonical'] ) . '; source modified (UTC): ' . Markdown::text( $fact['modified_gmt'] );
					}
				}
				$lines[] = '';
				if ( count( $ids ) > self::PAGE_SIZE ) {
					$lines[] = Markdown::link( 'More listings', self::cursor_url( $directory, $ids[ self::PAGE_SIZE - 1 ] ) );
				}
				$lines[] = Markdown::link( 'Directorist discovery', self::entry_url() );
				// Index titles receive the same fresh disclosure fence as the individual endpoint.
				foreach ( $captured as $id => $fact ) {
					try {
						$final = $this->facts->capture( $id );
					} catch ( \Throwable $error ) {
						$final = null;
					}
					if ( null === $final || $directory !== $final['directory_id'] || ! hash_equals( $fact['revision'], $final['revision'] ) ) {
						return array(
							'status' => 503,
							'body'   => "Public directory changed during publication. Retry the index.\n",
						);
					}
				}
				return $this->facts->directory( $directory ) ? $this->bounded(
					array(
						'status' => 200,
						'body'   => implode( "\n", $lines ) . "\n",
					)
				) : $this->missing();
			}
		);
	}

	public function discovery( int $after = 0 ): array {
		return NativeFacts::anonymous(
			function () use ( $after ): array {
				if ( ! Settings::active() ) {
						return $this->missing();
				}
				$settings = Settings::get();
				$title    = $settings['discovery_title'] ? $settings['discovery_title'] : get_bloginfo( 'name' ) . ' — Directorist';
				$summary  = $settings['discovery_summary'] ? $settings['discovery_summary'] : 'Current public listing facts with canonical source links. No AI-generated summaries.';
				$lines    = array( '# ' . Markdown::text( Markdown::plain( $title ) ), '', '> ' . Markdown::text( Markdown::plain( $summary ) ), '', '## Directory indexes', '' );
				$observed = array();
				$ids      = Settings::directory_ids( $after, self::PAGE_SIZE + 1 );
				foreach ( array_slice( $ids, 0, self::PAGE_SIZE ) as $directory ) {
					if ( $this->facts->directory( $directory ) ) {
						$term = get_term( $directory, ATBDP_DIRECTORY_TYPE );
						if ( ! $term instanceof \WP_Term ) {
							return $this->missing();
						}
						$observed[ $directory ] = $term->name;
						$lines[]                = '- ' . Markdown::link( $term->name, self::directory_url( $directory ) );
					}
				}
				if ( count( $ids ) > self::PAGE_SIZE ) {
					$lines[] = Markdown::link( 'More directories', self::discovery_url( $ids[ self::PAGE_SIZE - 1 ] ) );
				}
				foreach ( $observed as $directory => $name ) {
					if ( ! $this->facts->directory( $directory ) || get_term( $directory, ATBDP_DIRECTORY_TYPE )->name !== $name ) {
						return $this->missing();
					}
				}
				if ( ! Settings::active() ) {
					return $this->missing();
				}
				return $this->bounded(
					array(
						'status' => 200,
						'body'   => implode( "\n", $lines ) . "\n",
					)
				);
			}
		);
	}

	/** Directory titles can collectively exceed the per-listing document bound. */
	private function bounded( array $result ): array {
		return strlen( $result['body'] ) > self::MAX_DOCUMENT_BYTES ? array(
			'status' => 413,
			'body'   => "Public facts exceed the 256 KiB Markdown limit. Read the canonical directory.\n",
		) : $result;
	}

	private function missing(): array {
		return array(
			'status' => 404,
			'body'   => "Public Directorist Markdown is unavailable.\n",
		);
	}
}
