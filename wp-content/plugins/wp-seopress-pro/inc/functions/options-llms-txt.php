<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options LLMS.txt.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Get post types to include in llms.txt output.
 *
 * Returns all public post types except attachments and SEOPress internal types.
 * Filterable via 'seopress_llms_post_types'.
 *
 * @return array
 */
function seopress_get_llms_post_types() {
	$excluded = array( 'attachment', 'seopress_404', 'seopress_schemas', 'seopress_bot' );
	$types    = get_post_types( array( 'public' => true ), 'names' );
	$types    = array_diff( array_values( $types ), $excluded );

	return apply_filters( 'seopress_llms_post_types', $types );
}

/**
 * Return the canonical llms.txt URL, always at the site root.
 *
 * Like robots.txt, the llms.txt file is served only from the site's main
 * address. Multilingual plugins (WPML, Polylang) filter home_url() to prepend
 * a language segment on the front-end (e.g. /fr/), which would wrongly expose
 * and advertise the file at /fr/llms.txt. The raw `home` option is never
 * language-filtered, so it yields the true site root even under those plugins.
 *
 * @return string
 */
function seopress_llms_get_txt_url() {
	$url = trailingslashit( get_option( 'home' ) ) . 'llms.txt';

	return apply_filters( 'seopress_llms_txt_url', $url );
}

/**
 * Get the default language code from active multilingual plugin.
 *
 * @return string Language code or empty string when no plugin is active.
 */
function seopress_llms_get_default_language() {
	// Polylang.
	if ( function_exists( 'pll_default_language' ) ) {
		$lang = pll_default_language( 'slug' );
		if ( ! empty( $lang ) ) {
			return $lang;
		}
	}

	// WPML.
	$wpml_default = apply_filters( 'wpml_default_language', null );
	if ( ! empty( $wpml_default ) ) {
		return $wpml_default;
	}

	// TranslatePress.
	if ( class_exists( 'TRP_Translate_Press' ) ) {
		$trp = TRP_Translate_Press::get_trp_instance();
		if ( is_object( $trp ) && method_exists( $trp, 'get_component' ) ) {
			$settings_obj = $trp->get_component( 'settings' );
			if ( is_object( $settings_obj ) && method_exists( $settings_obj, 'get_setting' ) ) {
				$tp_default = $settings_obj->get_setting( 'default-language' );
				if ( ! empty( $tp_default ) ) {
					return $tp_default;
				}
			}
		}
	}

	return '';
}

/**
 * Translate a string into a target language when TranslatePress is active.
 *
 * Returns the original string unchanged when TP is missing, when $lang is empty
 * or 'all', or when the requested language matches the TP default.
 *
 * @param string $text The source string.
 * @param string $lang Target language code.
 * @return string
 */
function seopress_llms_translate_string( $text, $lang ) {
	if ( '' === $text || empty( $lang ) || 'all' === $lang ) {
		return $text;
	}

	if ( ! class_exists( 'TRP_Translate_Press' ) || ! function_exists( 'trp_translate' ) ) {
		return $text;
	}

	return trp_translate( $text, $lang, true );
}

/**
 * Convert a URL to its TranslatePress equivalent for the given language.
 *
 * Returns the URL unchanged when TP is missing, when $lang is empty or 'all',
 * or when the URL converter component is unavailable.
 *
 * @param string $url  Source URL.
 * @param string $lang Target language code.
 * @return string
 */
function seopress_llms_translate_url( $url, $lang ) {
	if ( '' === $url || empty( $lang ) || 'all' === $lang ) {
		return $url;
	}

	if ( ! class_exists( 'TRP_Translate_Press' ) ) {
		return $url;
	}

	$trp = TRP_Translate_Press::get_trp_instance();
	if ( ! is_object( $trp ) || ! method_exists( $trp, 'get_component' ) ) {
		return $url;
	}

	$url_converter = $trp->get_component( 'url_converter' );
	if ( ! is_object( $url_converter ) || ! method_exists( $url_converter, 'get_url_for_language' ) ) {
		return $url;
	}

	return $url_converter->get_url_for_language( $lang, $url, '' );
}

/**
 * Build a markdown list entry, applying TranslatePress translation when applicable.
 *
 * Note: when TranslatePress is active and $lang targets a non-default language,
 * this triggers up to three TP lookups per entry (title, excerpt, URL). For
 * placeholders requesting a large $count, integrators may want to add caching
 * via the `seopress_llms_query_posts` filter or by wrapping the final
 * `seopress_llms_txt_file` filter with a transient.
 *
 * @param string $title   Entry title (raw).
 * @param string $url     Entry URL (raw).
 * @param string $excerpt Entry excerpt (already plain text).
 * @param string $lang    Language code from the placeholder.
 * @return string The markdown list line, ending with a newline.
 */
function seopress_llms_format_entry( $title, $url, $excerpt, $lang ) {
	$title   = seopress_llms_translate_string( $title, $lang );
	$excerpt = seopress_llms_translate_string( $excerpt, $lang );
	$url     = seopress_llms_translate_url( $url, $lang );

	$line = "- [{$title}]({$url})";
	if ( ! empty( $excerpt ) ) {
		$line .= ": {$excerpt}";
	}

	return $line . "\n";
}

/**
 * Run a posts query with multilingual awareness for llms.txt placeholders.
 *
 * Handles Polylang and WPML based on the `$lang` argument:
 * - 'all'        : returns posts from every language. For Polylang the empty
 *                  `lang` arg disables the language filter; for WPML the
 *                  `wpml_switch_language` action with 'all' covers it.
 * - '' or null   : falls back to the site's default language.
 * - 'xx'         : restricts the query to language code 'xx'.
 *
 * On sites without a multilingual plugin, or with TranslatePress only (which
 * does not store separate posts per language), $lang is silently ignored at
 * the query level — TP translation is applied later at render time via
 * seopress_llms_format_entry().
 *
 * Filters:
 * - `seopress_llms_query_posts` ( WP_Post[] $posts, array $args, string $lang )
 *   Lets integrators replace, cache, or post-process the resolved post list.
 *
 * @param array  $args Standard get_posts() args.
 * @param string $lang Language code, 'all', or empty for default.
 * @return WP_Post[]
 */
function seopress_llms_query_posts( $args, $lang = '' ) {
	$lang = is_string( $lang ) ? strtolower( trim( $lang ) ) : '';

	// Resolve language to apply.
	if ( '' === $lang ) {
		$lang = seopress_llms_get_default_language();
	}

	$query_all = ( 'all' === $lang );

	// WPML and Polylang restrict a query to the current language through
	// WP_Query filter hooks (posts_join/posts_where). get_posts() sets
	// 'suppress_filters' => true by default, which disables those hooks, so
	// without this the language switch has no effect and posts from every
	// language leak into the list. Only force it when a per-language plugin is
	// active to avoid altering query behaviour on monolingual sites.
	$multilingual = function_exists( 'pll_default_language' ) || defined( 'ICL_SITEPRESS_VERSION' );
	if ( $multilingual && ! isset( $args['suppress_filters'] ) ) {
		$args['suppress_filters'] = false;
	}

	// Polylang: pass `lang` arg directly (empty string disables the language filter).
	if ( function_exists( 'pll_default_language' ) ) {
		$args['lang'] = $query_all ? '' : $lang;
	}

	// WPML: switch language for the duration of the query, restore afterwards.
	$wpml_active        = defined( 'ICL_SITEPRESS_VERSION' );
	$wpml_previous_lang = null;

	if ( $wpml_active ) {
		$wpml_previous_lang = apply_filters( 'wpml_current_language', null );

		if ( $query_all ) {
			do_action( 'wpml_switch_language', 'all' );
		} elseif ( ! empty( $lang ) ) {
			do_action( 'wpml_switch_language', $lang );
		}
	}

	$posts = get_posts( $args );

	// Restore WPML language.
	if ( $wpml_active && null !== $wpml_previous_lang ) {
		do_action( 'wpml_switch_language', $wpml_previous_lang );
	}

	return apply_filters( 'seopress_llms_query_posts', $posts, $args, $lang );
}

/**
 * Build a post permalink in the post's own language.
 *
 * When posts from several languages are listed together (lang=all, or a
 * placeholder targeting a language other than the current request), WordPress'
 * get_permalink() can resolve URLs in the active request language instead of
 * each post's own language. This happens under WPML, whose permalink output
 * follows the active language context — by the time the render loop runs, that
 * context has been restored to the request language (see seopress_llms_query_posts).
 * The result is that every entry gets the current language's URL structure
 * regardless of the post's actual language.
 *
 * This helper rewrites the permalink to the post's own language via WPML's
 * official `wpml_permalink` filter. Polylang resolves permalinks from each
 * post's own language natively, so it is left untouched; TranslatePress URL
 * conversion is handled at render time in seopress_llms_format_entry().
 *
 * @param WP_Post|int $post Post object or ID.
 * @return string
 */
function seopress_llms_get_post_permalink( $post ) {
	$url = get_permalink( $post );

	// WPML: convert the URL to the post's own language.
	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		$post_id   = is_object( $post ) ? $post->ID : (int) $post;
		$details   = apply_filters( 'wpml_post_language_details', null, $post_id );
		$post_lang = ( is_array( $details ) && ! empty( $details['language_code'] ) ) ? $details['language_code'] : '';

		if ( '' !== $post_lang ) {
			$url = apply_filters( 'wpml_permalink', $url, $post_lang );
		}
	}

	return $url;
}

/**
 * Generate default LLMS.txt content.
 *
 * @return string
 */
function seopress_generate_default_llms_txt_content() {
	$content  = "# " . get_bloginfo( 'name' ) . "\n\n";
	$content .= "> " . get_bloginfo( 'description' ) . "\n";
	$content .= "> Last updated: " . gmdate( 'Y-m-d' ) . "\n\n";
	$content .= "## Search\n\n";
	$content .= "- Search URL: `" . add_query_arg( 's', '{query}', get_home_url() ) . "`\n\n";
	$content .= "## Recent Content\n\n";

	// Get public post types for llms.txt (excluding 'attachment' and SEOPress internal types).
	$post_types = seopress_get_llms_post_types();

	// Get recent posts (default language when a multilingual plugin is active).
	$recent_posts = seopress_llms_query_posts(
		array(
			'numberposts'  => 5,
			'post_status'  => 'publish',
			'post_type'    => $post_types,
			'orderby'      => 'date',
			'has_password' => false,
			'meta_query'   => array(
				'relation' => 'OR',
				array(
					'key'     => '_seopress_robots_index',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_seopress_robots_index',
					'value'   => 'yes',
					'compare' => '!=',
				),
			),
		)
	);

	foreach ( $recent_posts as $post ) {
		$excerpt = wp_strip_all_tags( get_the_excerpt( $post ) );
		if ( empty( $excerpt ) ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 20 );
		}
		$content .= seopress_llms_format_entry(
			$post->post_title,
			seopress_llms_get_post_permalink( $post ),
			$excerpt,
			''
		);
	}

	$content .= "\n## Complete Sitemap\n\n";
	$content .= "For a comprehensive list of all URLs, see: " . get_home_url() . "/sitemaps.xml\n\n";
	$content .= "---\n\n";
	$content .= "*This file is dynamically generated and highlights our most important content.*\n";

	return apply_filters( 'seopress_default_llms_txt_content', $content );
}

/**
 * Replace placeholders in llms.txt content.
 *
 * @param string $content The content with placeholders.
 * @return string Content with placeholders replaced.
 */
function seopress_replace_llms_placeholders( $content ) {
	// Simple placeholders.
	$placeholders = array(
		'{{site_name}}'        => get_bloginfo( 'name' ),
		'{{site_description}}' => get_bloginfo( 'description' ),
		'{{site_url}}'         => get_home_url(),
		'{{current_date}}'     => gmdate( 'Y-m-d' ),
		'{{search_url}}'       => add_query_arg( 's', '{query}', get_home_url() ),
		'{{sitemap_url}}'      => get_home_url() . '/sitemaps.xml',
	);

	// Replace simple placeholders.
	$content = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $content );

	/*
	 * Handle latest posts placeholder, with optional post type filter and
	 * optional language. The two suffixes can appear in any order.
	 * Supported syntaxes:
	 *   {{latest_posts:5}}
	 *   {{latest_posts:5,(post,page)}}
	 *   {{latest_posts:5,lang=fr}}
	 *   {{latest_posts:5,(post,page),lang=fr}}
	 *   {{latest_posts:5,lang=fr,(post,page)}}
	 */
	if ( preg_match_all( '/\{\{latest_posts:(\d+)((?:,(?:\([^)]+\)|lang=[a-zA-Z0-9_-]+))*)\}\}/', $content, $matches ) ) {
		foreach ( $matches[0] as $index => $match ) {
			$count         = (int) $matches[1][ $index ];
			$params        = isset( $matches[2][ $index ] ) ? $matches[2][ $index ] : '';
			$posts_content = '';

			// Default to 'post' if no post types specified.
			$post_type = 'post';
			if ( preg_match( '/,\(([^)]+)\)/', $params, $pt_match ) ) {
				$post_type = array_map( 'trim', explode( ',', $pt_match[1] ) );
			}

			$lang = '';
			if ( preg_match( '/,lang=([a-zA-Z0-9_-]+)/', $params, $lang_match ) ) {
				$lang = $lang_match[1];
			}

			$recent_posts = seopress_llms_query_posts(
				array(
					'numberposts'  => $count,
					'post_status'  => 'publish',
					'post_type'    => $post_type,
					'orderby'      => 'date',
					'has_password' => false,
					'meta_query'   => array(
						'relation' => 'OR',
						array(
							'key'     => '_seopress_robots_index',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_seopress_robots_index',
							'value'   => 'yes',
							'compare' => '!=',
						),
					),
				),
				$lang
			);

			foreach ( $recent_posts as $post ) {
				$excerpt = wp_strip_all_tags( get_the_excerpt( $post ) );
				if ( empty( $excerpt ) ) {
					$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 20 );
				}
				$posts_content .= seopress_llms_format_entry(
					$post->post_title,
					seopress_llms_get_post_permalink( $post ),
					$excerpt,
					$lang
				);
			}

			$content = str_replace( $match, $posts_content, $content );
		}
	}

	// Handle featured products: {{featured_products:X}} and latest products: {{latest_products:X}}.
	if ( class_exists( 'WooCommerce' ) ) {
		// Featured products. Supports optional ,lang=xx suffix.
		if ( preg_match_all( '/\{\{featured_products:(\d+)(?:,lang=([a-zA-Z0-9_-]+))?\}\}/', $content, $matches ) ) {
			foreach ( $matches[0] as $index => $match ) {
				$count            = (int) $matches[1][ $index ];
				$lang             = isset( $matches[2][ $index ] ) ? $matches[2][ $index ] : '';
				$products_content = '';

				$product_args = array(
					'post_type'      => 'product',
					'posts_per_page' => $count,
					'post_status'    => 'publish',
					'has_password'   => false,
					'tax_query'      => array(
						'relation' => 'AND',
						array(
							'taxonomy' => 'product_visibility',
							'field'    => 'name',
							'terms'    => 'featured',
						),
						array(
							'taxonomy' => 'product_visibility',
							'field'    => 'name',
							'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
							'operator' => 'NOT IN',
						),
					),
					'meta_query'     => array(
						'relation' => 'OR',
						array(
							'key'     => '_seopress_robots_index',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_seopress_robots_index',
							'value'   => 'yes',
							'compare' => '!=',
						),
					),
				);

				$products = seopress_llms_query_posts( $product_args, $lang );

				// Fallback to latest if no featured products.
				if ( empty( $products ) ) {
					$products = seopress_llms_query_posts(
						array(
							'numberposts'  => $count,
							'post_status'  => 'publish',
							'post_type'    => 'product',
							'has_password' => false,
							'tax_query'    => array(
								array(
									'taxonomy' => 'product_visibility',
									'field'    => 'name',
									'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
									'operator' => 'NOT IN',
								),
							),
							'meta_query'   => array(
								'relation' => 'OR',
								array(
									'key'     => '_seopress_robots_index',
									'compare' => 'NOT EXISTS',
								),
								array(
									'key'     => '_seopress_robots_index',
									'value'   => 'yes',
									'compare' => '!=',
								),
							),
						),
						$lang
					);
				}

				foreach ( $products as $product ) {
					$product_obj = wc_get_product( $product->ID );
					$excerpt     = $product_obj ? wp_strip_all_tags( $product_obj->get_short_description() ) : '';

					if ( empty( $excerpt ) ) {
						$excerpt = wp_trim_words( wp_strip_all_tags( $product->post_content ), 15 );
					}

					$products_content .= seopress_llms_format_entry(
						$product->post_title,
						seopress_llms_get_post_permalink( $product ),
						$excerpt,
						$lang
					);
				}

				$content = str_replace( $match, $products_content, $content );
			}
		}

		// Latest products. Supports optional ,lang=xx suffix.
		if ( preg_match_all( '/\{\{latest_products:(\d+)(?:,lang=([a-zA-Z0-9_-]+))?\}\}/', $content, $matches ) ) {
			foreach ( $matches[0] as $index => $match ) {
				$count            = (int) $matches[1][ $index ];
				$lang             = isset( $matches[2][ $index ] ) ? $matches[2][ $index ] : '';
				$products_content = '';

				$products = seopress_llms_query_posts(
					array(
						'numberposts'  => $count,
						'post_status'  => 'publish',
						'post_type'    => 'product',
						'orderby'      => 'date',
						'has_password' => false,
						'tax_query'    => array(
							array(
								'taxonomy' => 'product_visibility',
								'field'    => 'name',
								'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
								'operator' => 'NOT IN',
							),
						),
						'meta_query'   => array(
							'relation' => 'OR',
							array(
								'key'     => '_seopress_robots_index',
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => '_seopress_robots_index',
								'value'   => 'yes',
								'compare' => '!=',
							),
						),
					),
					$lang
				);

				foreach ( $products as $product ) {
					$product_obj = wc_get_product( $product->ID );
					$excerpt     = $product_obj ? wp_strip_all_tags( $product_obj->get_short_description() ) : '';

					if ( empty( $excerpt ) ) {
						$excerpt = wp_trim_words( wp_strip_all_tags( $product->post_content ), 15 );
					}

					$products_content .= seopress_llms_format_entry(
						$product->post_title,
						seopress_llms_get_post_permalink( $product ),
						$excerpt,
						$lang
					);
				}

				$content = str_replace( $match, $products_content, $content );
			}
		}
	}

	return apply_filters( 'seopress_llms_placeholders_replaced', $content );
}

/**
 * Serve virtual llms.txt file.
 *
 * Uses 'do_parse_request' filter to intercept the request early,
 * similar to how WordPress handles robots.txt internally.
 *
 * @param bool         $do_parse Whether to parse the request.
 * @param WP           $wp       Current WordPress environment instance.
 * @param array|string $extra_query_vars Extra passed query variables.
 *
 * @return bool
 */
function seopress_serve_llms_txt( $do_parse, $wp, $extra_query_vars ) {
	// Get the home URL path to handle subdirectory installations.
	// Use the raw `home` option instead of home_url(): the latter is filtered
	// by multilingual plugins (WPML, Polylang) to add a language segment such
	// as /fr/, which would serve the file at /fr/llms.txt. Like robots.txt,
	// llms.txt must live only at the true site root.
	$home_path = wp_parse_url( get_option( 'home' ), PHP_URL_PATH );
	$home_path = $home_path ? trim( $home_path, '/' ) : '';

	// Build the expected path for llms.txt.
	$llms_path = $home_path ? $home_path . '/llms.txt' : 'llms.txt';

	// Get the request path. wp_parse_url() returns null/false when the URI
	// has no path component or fails to parse, which trips a PHP 8.1+
	// deprecation when passed straight to trim(). Cast to string.
	$request_uri  = seopress_agent_ready_request_uri();
	$request_path = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );

	// Check if the request is for llms.txt.
	if ( $request_path !== $llms_path ) {
		return $do_parse;
	}

	// Get custom content from settings.
	$seopress_llms = seopress_pro_get_service( 'OptionPro' )->getLlmsTxtFile();

	// If custom content is empty, use default.
	if ( empty( $seopress_llms ) ) {
		$seopress_llms = seopress_generate_default_llms_txt_content();
	} else {
		// Replace placeholders in custom content.
		$seopress_llms = seopress_replace_llms_placeholders( $seopress_llms );
	}

	// Apply filter to allow customization.
	$seopress_llms = apply_filters( 'seopress_llms_txt_file', $seopress_llms );

	// Set the content type header.
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );

	// Output the content.
	echo $seopress_llms;

	// Exit to prevent WordPress from continuing.
	exit;
}
add_filter( 'do_parse_request', 'seopress_serve_llms_txt', 0, 3 );
