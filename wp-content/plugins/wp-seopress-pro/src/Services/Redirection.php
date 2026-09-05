<?php

namespace SEOPressPro\Services;

defined( 'ABSPATH' ) || exit;


class Redirection {
	protected $cachePageByTitle = array();

	public function getPageByTitle( $title, $output, $post_type ) {
		// An empty title is not a lookup key. Both queries below compare
		// `post_title` to it directly, so they answer with whatever row happens
		// to carry an empty title — and rows like that exist: `processCreate()`
		// stores one whenever `normalize_origin()` reduces the input to nothing,
		// which `/`, a bare domain and any non-Latin path all do.
		//
		// The front-end matcher reaches here with an empty key on the homepage:
		// a request carrying only query parameters has no path, and the pass
		// that ignores the parameters then has nothing left to match on. The
		// visitor was redirected to that unrelated row's destination, so
		// `/?utm_source=…` and `/?fbclid=…` left the homepage while `/` itself
		// stayed put.
		//
		// Trimmed, because `WP_Query` trims `title` before testing it and a
		// whitespace-only key degrades exactly the same way.
		$title = (string) $title;

		if ( '' === trim( $title ) ) {
			return false;
		}

		if ( isset( $this->cachePageByTitle[ $title ] ) ) {
			return $this->cachePageByTitle[ $title ];
		}

		global $wpdb;

		$post_type = isset( $post_type ) ? $post_type : 'seopress_404';
		$output    = isset( $output ) ? $output : OBJECT;

		$metaValueByLoggedIn = \is_user_logged_in() ? 'only_logged_in' : 'only_not_logged_in';

		$sql = $wpdb->prepare(
			"
			SELECT ID
			FROM $wpdb->posts
			INNER JOIN $wpdb->postmeta
			ON ( $wpdb->posts.ID = $wpdb->postmeta.post_id )
            INNER JOIN $wpdb->postmeta AS mt1
            ON ( $wpdb->posts.ID = mt1.post_id )
			WHERE 1=1
			AND ( ( $wpdb->postmeta.meta_key = '_seopress_redirections_enabled'
			AND $wpdb->postmeta.meta_value = 'yes' ) )
			AND post_title = %s
			AND post_type = %s
			AND post_status = 'publish'
            AND ( ( mt1.meta_key = '_seopress_redirections_logged_status'
            AND mt1.meta_value = '$metaValueByLoggedIn' )
            OR ( mt1.meta_key = '_seopress_redirections_logged_status'
            AND mt1.meta_value = 'both' ) )
		",
			$title,
			$post_type
		);

		$page = $wpdb->get_var( $sql );
		if ( isset( $page ) ) {
			$this->cachePageByTitle[ $title ] = get_post( $page, $output );
			return $this->cachePageByTitle[ $title ];
		}

		$sql = $wpdb->prepare(
			"
				SELECT ID
				FROM $wpdb->posts
				WHERE 1=1
				AND post_title = %s
				AND post_type = %s
			",
			$title,
			$post_type
		);

		$page = $wpdb->get_var( $sql );

		if ( isset( $page ) ) {
			$this->cachePageByTitle[ $title ] = get_post( $page, $output );
		} else {
			$this->cachePageByTitle[ $title ] = false;
		}

		return $this->cachePageByTitle[ $title ];
	}

	public function update404CounterById( $id ) {
		$counter = (int) get_post_meta( $id, 'seopress_404_count', true );

		$stop_counter = apply_filters( 'seopress_stop_counter_redirects', false );

		if ( $stop_counter === false ) {
			update_post_meta( $id, 'seopress_404_count', ++$counter );
		}

		// Update last time requested
		$stop_date = apply_filters( 'seopress_stop_last_date_request_redirects', false );

		if ( $stop_date === false ) {
			update_post_meta( $id, '_seopress_404_redirect_date_request', time() );
		}
	}

	/**
	 *
	 * @param array $options ["only_uri"]
	 * @return string
	 */
	public function getCurrentUrl( $options = array() ) {
		global $wp;
		$currentUrl = home_url( $wp->request );
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$currentUrl = untrailingslashit( $currentUrl );
		}

		$currentUrl = add_query_arg( $_SERVER['QUERY_STRING'] ?? '', '', $currentUrl );

		// Parsed before being decoded: parse_url() replaces every C1 byte with
		// an underscore, so decoding first turned `остров` into `о_ _ ов` and
		// no non-Latin pattern could ever match. See
		// seopress_pro_parse_url_decoded().
		$currentUrlParse = seopress_pro_parse_url_decoded( $currentUrl );

		if ( isset( $options['only_uri'] ) && $options['only_uri'] ) {
			if ( isset( $currentUrlParse['path'] ) ) {
				// Without the path the site is served from: home_url() adds it
				// back, and both the stored origins and the URL tester work
				// without it. See seopress_pro_strip_home_path().
				$currentUrl = seopress_pro_strip_home_path( $currentUrlParse['path'] );
				if ( '' === $currentUrl ) {
					$currentUrl = '/';
				}
			} else {
				$currentUrl = '/';
			}

			if ( isset( $currentUrlParse['query'] ) && ! empty( $currentUrlParse['query'] ) ) {
				$currentUrl .= '?' . $currentUrlParse['query'];
			}
		} else {
			// The full URL keeps its previous shape: decoded, and escaped the
			// same way, so callers that log or redirect on it are unchanged.
			$currentUrl = htmlspecialchars( rawurldecode( $currentUrl ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
		}

		if ( isset( $options['with_query_params'] ) && $options['with_query_params'] && isset( $_SERVER['QUERY_STRING'] ) ) {
			$currentUrl = add_query_arg( $_SERVER['QUERY_STRING'], '', $currentUrl );
		}

		return apply_filters( 'redirection_get_current_url', $currentUrl );
	}

	/**
	 * Turn a stored redirection pattern into a usable PCRE expression.
	 *
	 * The delimiter is `#`, not `/`, so a pattern full of slashes needs no
	 * mangling before it can be compiled. We used to wrap patterns in `/…/`
	 * and escape every `/` on the way in, which broke the moment a user
	 * escaped one themselves: `\/it` became `\\/it`, a literal backslash
	 * followed by a slash, and the redirection quietly stopped matching
	 * anything. Both `/it` and `\/it` now compile to the same thing.
	 *
	 * A `#` inside the pattern is escaped, unless the user already escaped it.
	 *
	 * Matching stays case-insensitive, as it has always been.
	 *
	 * @param string $pattern Pattern as stored on the redirection.
	 *
	 * @return string A delimited, case-insensitive PCRE pattern.
	 */
	public function buildRegexPattern( $pattern ) {
		$escaped = preg_replace_callback(
			'~(?<!\\\\)#~',
			static function () {
				return '\\#';
			},
			(string) $pattern
		);

		return '#' . $escaped . '#i';
	}

	public function checkRegexRedirect() {
		$redirectionsWithRegex = get_posts(
			array(
				'post_type'      => 'seopress_404',
				'meta_query'     => array(
					array(
						'key'   => '_seopress_redirections_enabled_regex',
						'value' => 'yes',
					),
					array(
						'relation' => 'OR',
						array(
							'key'   => '_seopress_redirections_logged_status',
							'value' => \is_user_logged_in() ? 'only_logged_in' : 'only_not_logged_in',
						),
						array(
							'key'   => '_seopress_redirections_logged_status',
							'value' => 'both',
						),
					),
				),
				'posts_per_page' => -1,
			)
		);

		if ( empty( $redirectionsWithRegex ) ) {
			return;
		}

		$redirectionMatch = false;
		$i                = 0;
		$totalRedirects   = count( $redirectionsWithRegex );
		$currentUrl       = $this->getCurrentUrl( array( 'only_uri' => true ) );

		$redirectionMatch = null;
		$matches          = null;
		do {
			$regex = $this->buildRegexPattern( $redirectionsWithRegex[ $i ]->post_title );
			try {
				@\preg_match( $regex, $currentUrl, $matches );
				if ( ! empty( $matches ) ) {
					$redirectionMatch = $redirectionsWithRegex[ $i ];
				}
			} catch ( \Exception $e ) {
			}
			++$i;
		} while ( $redirectionMatch === null && $i < $totalRedirects );

		if ( ! $redirectionMatch ) {
			return;
		}

		$query_param = get_post_meta( $redirectionMatch->ID, '_seopress_redirections_param', true );

		$this->handleRedirectionWithId(
			$redirectionMatch->ID,
			array(
				'init_url'    => $this->getCurrentUrl(
					array(
						'with_query_params' => $query_param === 'with_ignored_param',
					)
				),
				'query_param' => 'regex',
				'matches'     => $matches,
			)
		);
	}

	protected function replaceRegexPatternMatches( $url, $matches ) {
		$maxI = count( $matches ) - 1;
		for ( $i = 1; $i <= $maxI; $i++ ) {
			if ( strpos( $url, \sprintf( '$%d', $i ) ) === false ) {
				continue;
			}

			$url = str_replace( '$' . $i, $matches[ $i ], $url );
		}

		return $url;
	}

	public function handleRedirectionWithId( $id, $options = array() ) {
		$redirectionsEnabled = get_post_meta( $id, '_seopress_redirections_enabled', true );

		if ( ! $redirectionsEnabled ) {
			return;
		}

		$initUrl        = isset( $options['init_url'] ) ? $options['init_url'] : $this->getCurrentUrl();
		$if_exact_match = isset( $options['if_exact_match'] ) ? $options['if_exact_match'] : false;

		// Query parameters
		$query_param = $query_param_value_safe = get_post_meta( $id, '_seopress_redirections_param', true );

		if ( ! $query_param ) {
			$query_param = 'exact_match';
		}

		if ( isset( $options['query_param'] ) ) {
			$query_param = $options['query_param'];
		}

		$loggedStatus = get_post_meta( $id, '_seopress_redirections_logged_status', true );

		if ( $loggedStatus === 'only_logged_in' && ! is_user_logged_in() ) {
			return;
		}

		if ( $loggedStatus === 'only_not_logged_in' && is_user_logged_in() ) {
			return;
		}

		$redirectionType  = get_post_meta( $id, '_seopress_redirections_type', true );
		$redirectionValue = get_post_meta( $id, '_seopress_redirections_value', true );
		if ( \strpos( $redirectionValue, '$' ) !== 0 && isset( $options['matches'] ) && \is_array( $options['matches'] ) ) {
			$redirectionValue = $this->replaceRegexPatternMatches( $redirectionValue, $options['matches'] );
		}

		// 451 / 410
		if ( '410' == $redirectionType || '451' == $redirectionType ) {
			// URL redirection
			$seopress_redirections_value = $initUrl;

			// Update counter
			$this->update404CounterById( $id );

			// Do redirect
			if ( true == $if_exact_match ) {
				header( 'Location:' . $seopress_redirections_value, true, $redirectionType );
				exit();
			} elseif ( false == $if_exact_match && 'exact_match' != $query_param ) {
				header( 'Location:' . $seopress_redirections_value, true, $redirectionType );
				exit();
			} elseif ( 'regex' === $query_param ) {
				header( 'Location:' . $seopress_redirections_value, true, $redirectionType );
				exit();
			}
		}
		// 301 / 302 / 307
		elseif ( $redirectionValue ) {
			// URL redirection
			$seopress_redirections_value = html_entity_decode( $redirectionValue, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );

			// Query parameters
			if ( 'with_ignored_param' === $query_param_value_safe && isset( $_SERVER['QUERY_STRING'] ) ) {
				$seopress_redirections_value = add_query_arg( $_SERVER['QUERY_STRING'], '', $seopress_redirections_value );
			}

			// Update counter
			$this->update404CounterById( $id );

			// Do redirect
			if ( true == $if_exact_match ) {
				wp_redirect( $seopress_redirections_value, $redirectionType );
				exit();
			} elseif ( false == $if_exact_match && 'exact_match' != $query_param ) {
				wp_redirect( $seopress_redirections_value, $redirectionType );
				exit();
			} elseif ( 'regex' === $query_param ) {
				wp_redirect( $seopress_redirections_value, $redirectionType );
				exit();
			}
		}
	}
}
