<?php

namespace SEOPressPro\Services;

defined( 'ABSPATH' ) || exit;


class Redirection {
	protected $cachePageByTitle = array();

	public function getPageByTitle( $title, $output, $post_type ) {
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

		$currentUrl = htmlspecialchars( rawurldecode( add_query_arg( $_SERVER['QUERY_STRING'] ?? '', '', $currentUrl ) ) );

		if ( isset( $options['only_uri'] ) && $options['only_uri'] ) {
			$currentUrlParse = wp_parse_url( $currentUrl );

			if ( isset( $currentUrlParse['path'] ) ) {
				$currentUrl = $currentUrlParse['path'];
			} else {
				$currentUrl = '/';
			}

			if ( isset( $currentUrlParse['query'] ) && ! empty( $currentUrlParse['query'] ) ) {
				$currentUrl .= '?' . $currentUrlParse['query'];
			}
		}

		if ( isset( $options['with_query_params'] ) && $options['with_query_params'] && isset( $_SERVER['QUERY_STRING'] ) ) {
			$currentUrl = add_query_arg( $_SERVER['QUERY_STRING'], '', $currentUrl );
		}

		return apply_filters( 'redirection_get_current_url', $currentUrl );
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
			$regex = $redirectionsWithRegex[ $i ]->post_title;
			$regex = preg_replace( '/\//', '\/', $regex );
			try {
				@\preg_match( sprintf( '/%s/i', $regex ), $currentUrl, $matches );
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
			$seopress_redirections_value = html_entity_decode( $redirectionValue );

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
