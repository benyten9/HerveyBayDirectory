<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Agent Readiness.
 *
 * Implements website-level signals and endpoints that help AI agents
 * discover, understand, and access content.
 *
 * Covers the following checks from Cloudflare's agent-readiness framework:
 *  - Discoverability: Link response headers (markdown alternate, sitemap, llms.txt).
 *  - Content Accessibility: Markdown content negotiation (Accept: text/markdown).
 *  - Bot Access Control: Content-Signal directive injected into robots.txt.
 *  - Protocol Discovery: /.well-known/mcp.json, /.well-known/api-catalog,
 *    /.well-known/agent-card.json, /.well-known/agent-skills/index.json.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/* -----------------------------------------------------------------------------
 * Discoverability: Link response headers
 * ---------------------------------------------------------------------------*/

/**
 * Return the URL agents should hit to fetch the current page as Markdown.
 *
 * @return string
 */
function seopress_agent_ready_current_markdown_url() {
	$request_uri = seopress_agent_ready_request_uri( '/' );
	$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
	$url         = home_url( '' === $path ? '/' : $path );
	$url         = remove_query_arg( array( 'format' ), $url );
	$url         = add_query_arg( 'format', 'md', $url );

	return apply_filters( 'seopress_agent_ready_markdown_url', $url );
}

/**
 * Send Link response headers pointing to discovery resources.
 *
 * Cloudflare's agent-readiness checker only credits "agent-useful" Link
 * relation types. The single most important one is `alternate` with
 * `type="text/markdown"`, which advertises that the same URL can be
 * served as Markdown. We also keep the sitemap/llms hints for clients
 * that understand them.
 *
 * @return void
 */
function seopress_agent_ready_send_link_headers() {
	if ( is_admin() || headers_sent() ) {
		return;
	}

	$links = array();

	if ( function_exists( 'is_singular' ) && ( is_singular( apply_filters( 'seopress_agent_ready_markdown_post_types', array( 'post', 'page' ) ) ) || is_home() || is_front_page() ) ) {
		$markdown_url = seopress_agent_ready_current_markdown_url();
		$links[]      = '<' . esc_url_raw( $markdown_url ) . '>; rel="alternate"; type="text/markdown"';
	}

	if ( function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'xml-sitemap' ) ) {
		$sitemap_url = apply_filters( 'seopress_agent_ready_sitemap_url', home_url( '/sitemaps.xml' ) );
		$links[]     = '<' . esc_url_raw( $sitemap_url ) . '>; rel="sitemap"; type="application/xml"';
	}

	if ( function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'llms' ) ) {
		$default_llms_url = function_exists( 'seopress_llms_get_txt_url' ) ? seopress_llms_get_txt_url() : home_url( '/llms.txt' );
		$llms_url         = apply_filters( 'seopress_agent_ready_llms_url', $default_llms_url );
		$links[]          = '<' . esc_url_raw( $llms_url ) . '>; rel="describedby"; type="text/plain"';
	}

	$links = apply_filters( 'seopress_agent_ready_link_headers', $links );

	foreach ( $links as $link ) {
		if ( ! is_string( $link ) || false !== strpbrk( $link, "\r\n\0" ) ) {
			continue;
		}
		header( 'Link: ' . $link, false );
	}
}
add_action( 'send_headers', 'seopress_agent_ready_send_link_headers' );

/* -----------------------------------------------------------------------------
 * Bot Access Control: Content-Signal directive in robots.txt
 * ---------------------------------------------------------------------------*/

/**
 * Return the Content-Signal directives for the current site.
 *
 * Defaults align with Cloudflare's Content-Signal specification:
 *  - search=yes        (allow classic search indexing)
 *  - ai-input=yes      (allow use as grounding input in AI answers)
 *  - ai-train=no       (disallow use for training models)
 *
 * Filterable via 'seopress_agent_ready_content_signal'.
 *
 * @return string
 */
function seopress_agent_ready_get_content_signal() {
	$signals = array(
		'search'   => 'yes',
		'ai-input' => 'yes',
		'ai-train' => 'no',
	);

	$signals = apply_filters( 'seopress_agent_ready_content_signal', $signals );

	$parts = array();
	foreach ( $signals as $key => $value ) {
		$parts[] = sanitize_key( $key ) . '=' . sanitize_key( $value );
	}

	return implode( ', ', $parts );
}

/**
 * Inject a Content-Signal directive into robots.txt.
 *
 * Per Cloudflare's specification, Content-Signal lives in robots.txt as a
 * directive nested under a User-agent group. Cloudflare's checker only
 * inspects robots.txt for it. We append a `User-agent: *` group with the
 * directive at the end of whatever robots.txt content WordPress (or the
 * SEOPress custom robots.txt) just produced.
 *
 * @param string $output The current robots.txt body.
 * @param bool   $public Whether the site is publicly accessible.
 * @return string
 */
function seopress_agent_ready_inject_content_signal_robots( $output, $public ) {
	if ( ! $public ) {
		return $output;
	}

	$value = seopress_agent_ready_get_content_signal();

	if ( '' === $value || false !== strpbrk( $value, "\r\n\0" ) ) {
		return $output;
	}

	if ( false !== stripos( $output, 'Content-Signal:' ) ) {
		return $output;
	}

	// The specification's own site, not the announcement blog post that
	// introduced it. This URL is written into a public file on every site
	// with the feature on, so it has to survive the source reorganising its
	// own content: the first one we shipped now returns a 404.
	$block  = "\n# Content Signals (https://contentsignals.org/)\n";
	$block .= "User-agent: *\n";
	$block .= 'Content-Signal: ' . $value . "\n";

	return rtrim( $output, "\n" ) . "\n" . $block;
}
add_filter( 'robots_txt', 'seopress_agent_ready_inject_content_signal_robots', 99, 2 );

/* -----------------------------------------------------------------------------
 * Content Accessibility: Markdown content negotiation
 * ---------------------------------------------------------------------------*/

/**
 * Convert a post's rendered HTML to a reasonable Markdown approximation.
 *
 * Handles headings, paragraphs, lists, links, emphasis, inline code,
 * and code blocks. Anything else is flattened to plain text. Sites
 * that need richer output can replace this via the
 * 'seopress_agent_ready_html_to_markdown' filter.
 *
 * @param string $html Rendered post HTML.
 * @return string
 */
function seopress_agent_ready_html_to_markdown( $html ) {
	$short = apply_filters( 'seopress_agent_ready_html_to_markdown', null, $html );
	if ( null !== $short ) {
		return $short;
	}

	$html = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $html );
	$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );

	$patterns = array(
		'#<h1[^>]*>(.*?)</h1>#is'         => "\n# $1\n\n",
		'#<h2[^>]*>(.*?)</h2>#is'         => "\n## $1\n\n",
		'#<h3[^>]*>(.*?)</h3>#is'         => "\n### $1\n\n",
		'#<h4[^>]*>(.*?)</h4>#is'         => "\n#### $1\n\n",
		'#<h5[^>]*>(.*?)</h5>#is'         => "\n##### $1\n\n",
		'#<h6[^>]*>(.*?)</h6>#is'         => "\n###### $1\n\n",
		'#<(strong|b)[^>]*>(.*?)</\1>#is' => '**$2**',
		'#<(em|i)[^>]*>(.*?)</\1>#is'     => '*$2*',
		'#<code[^>]*>(.*?)</code>#is'     => '`$1`',
		'#<pre[^>]*>(.*?)</pre>#is'       => "\n```\n$1\n```\n",
		'#<hr[^>]*/?>#i'                  => "\n---\n",
		'#<br\s*/?>#i'                    => "\n",
		'#<li[^>]*>(.*?)</li>#is'         => "- $1\n",
		'#</?(ul|ol)[^>]*>#i'             => "\n",
		'#<p[^>]*>(.*?)</p>#is'           => "$1\n\n",
	);
	$html = preg_replace( array_keys( $patterns ), array_values( $patterns ), $html );

	$html = preg_replace_callback( '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ( $m ) {
		$text = trim( wp_strip_all_tags( $m[2] ) );
		$url  = esc_url_raw( $m[1], array( 'http', 'https', 'mailto' ) );
		if ( '' === $url ) {
			return $text;
		}
		return '[' . $text . '](' . $url . ')';
	}, $html );

	$text = wp_strip_all_tags( $html );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( "/\n{3,}/", "\n\n", $text );

	return trim( $text );
}

/**
 * Render a simple Markdown representation of a post.
 *
 * @param WP_Post $post The post to render.
 * @return string
 */
function seopress_agent_ready_render_markdown( $post ) {
	$title       = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$permalink   = get_permalink( $post );
	$modified    = get_post_modified_time( 'c', true, $post );
	$description = has_excerpt( $post )
		? wp_strip_all_tags( get_the_excerpt( $post ) )
		: wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 );
	$description = html_entity_decode( $description, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	$content_html = apply_filters( 'the_content', $post->post_content );
	$content_md   = seopress_agent_ready_html_to_markdown( $content_html );

	$markdown  = '# ' . $title . "\n\n";
	$markdown .= '> ' . $description . "\n";
	$markdown .= '> URL: ' . $permalink . "\n";
	$markdown .= '> Last modified: ' . $modified . "\n\n";
	$markdown .= $content_md . "\n";

	return apply_filters( 'seopress_agent_ready_markdown_body', $markdown, $post );
}

/**
 * Render a Markdown representation of the blog index / homepage.
 *
 * Used when the request hits the home URL but the front page is the
 * blog list (no static page assigned), so there is no single $post to
 * render. Lists the most recent posts with excerpt and permalink.
 *
 * @return string
 */
function seopress_agent_ready_render_home_markdown() {
	$site_name        = html_entity_decode( wp_strip_all_tags( get_bloginfo( 'name' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$site_description = html_entity_decode( wp_strip_all_tags( get_bloginfo( 'description' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$home_url         = home_url( '/' );
	$count            = (int) apply_filters( 'seopress_agent_ready_home_post_count', 10 );

	$markdown  = '# ' . $site_name . "\n\n";
	if ( '' !== $site_description ) {
		$markdown .= '> ' . $site_description . "\n";
	}
	$markdown .= '> URL: ' . $home_url . "\n\n";

	$recent = get_posts(
		array(
			'numberposts'      => $count,
			'post_status'      => 'publish',
			'has_password'     => false,
			'suppress_filters' => false,
		)
	);

	if ( ! empty( $recent ) ) {
		$markdown .= "## Recent Content\n\n";
		foreach ( $recent as $post ) {
			$title   = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$url     = get_permalink( $post );
			$excerpt = has_excerpt( $post )
				? wp_strip_all_tags( get_the_excerpt( $post ) )
				: wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
			$excerpt = html_entity_decode( $excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			$markdown .= '- [' . $title . '](' . $url . ')';
			if ( '' !== $excerpt ) {
				$markdown .= ': ' . $excerpt;
			}
			$markdown .= "\n";
		}
	}

	return apply_filters( 'seopress_agent_ready_home_markdown_body', $markdown );
}

/**
 * Serve a Markdown representation of the current page when the client
 * requests it via the Accept header or the ?format=md query.
 *
 * Covers both single posts/pages and the blog index / homepage so the
 * site exposes a markdown alternate from the same URL agents already
 * crawl.
 *
 * @return void
 */
function seopress_agent_ready_markdown_negotiation() {
	if ( is_admin() ) {
		return;
	}

	$post_types  = apply_filters( 'seopress_agent_ready_markdown_post_types', array( 'post', 'page' ) );
	$is_singular = is_singular( $post_types );
	$is_home     = is_home() || is_front_page();

	if ( ! $is_singular && ! $is_home ) {
		return;
	}

	$accept         = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
	$format_param   = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$wants_markdown = ( '' !== $accept && false !== stripos( $accept, 'text/markdown' ) )
		|| 'markdown' === $format_param
		|| 'md' === $format_param;

	if ( ! $wants_markdown ) {
		return;
	}

	if ( $is_singular ) {
		$post = get_post();
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		if ( post_password_required( $post ) ) {
			return;
		}

		$body = seopress_agent_ready_render_markdown( $post );
	} else {
		$body = seopress_agent_ready_render_home_markdown();
	}

	seopress_agent_ready_prevent_page_caching();

	nocache_headers();
	header( 'Content-Type: text/markdown; charset=utf-8' );
	header( 'Vary: Accept' );

	echo $body; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	exit;
}
add_action( 'template_redirect', 'seopress_agent_ready_markdown_negotiation' );

/**
 * Keep the Markdown representation out of every full-page cache.
 *
 * An `Accept: text/markdown` request carries the canonical URL, byte for byte,
 * so a cache that keys on the URL alone stores the Markdown body under the key
 * the HTML belongs to and then serves it to every plain visitor until the cache
 * is purged. Reported on a LiteSpeed site where seven URLs answered
 * `Content-Type: text/markdown` to an ordinary browser.
 *
 * The `Cache-Control` headers sent alongside this do not prevent it: page
 * caches decide what to store through their own layer and do not defer to
 * headers PHP emits, and `Vary: Accept` only matters to caches that key on that
 * header, which these do not. So the request is flagged through the interfaces
 * they actually read.
 *
 * Called only once the Markdown body is about to be sent. A request that asked
 * for Markdown but falls back to HTML (an unpublished or password-protected
 * post) is left alone, so its HTML response stays cacheable.
 *
 * @return void
 */
function seopress_agent_ready_prevent_page_caching() {
	// Read by WP Rocket, W3 Total Cache, WP Super Cache, Comet Cache and the
	// LiteSpeed Cache plugin, among others.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	// LiteSpeed Cache's own control layer, which is what decides cacheability
	// when the plugin is active.
	do_action( 'litespeed_control_set_nocache', 'SEOPress: Markdown content negotiation' );

	// Same instruction for a LiteSpeed server running without the plugin, where
	// the action above has nobody listening.
	if ( ! headers_sent() ) {
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}
}

/* -----------------------------------------------------------------------------
 * Protocol Discovery: WebMCP tool registration
 * ---------------------------------------------------------------------------*/

/**
 * Print a tiny inline WebMCP bootstrap on the frontend.
 *
 * Calls `navigator.modelContext.provideContext()` with two tools wired to
 * existing site capabilities: site search (REST `wp/v2/search`) and a
 * Markdown fetch (uses our `Accept: text/markdown` content negotiation).
 *
 * Behavior is no-op for regular visitors: 99.99% of browsers don't have
 * `navigator.modelContext`, so the feature-detect at the top exits before
 * touching anything. Agent-aware browsers / the WebMCP polyfill loaded by
 * the agent-readiness probe see the registered tools and the WebMCP check
 * passes.
 *
 * Tools are filterable via `seopress_agent_ready_webmcp_tools`; the
 * default execute callbacks are matched by name on the JS side, so
 * additional tools added through the filter must include their own
 * implementation by also filtering `seopress_agent_ready_webmcp_extra_js`.
 *
 * @return void
 */
function seopress_agent_ready_print_webmcp_script() {
	if ( is_admin() ) {
		return;
	}

	$tools = array(
		array(
			'name'        => 'search_site',
			'description' => sprintf(
				/* translators: %s: site name. */
				__( 'Full-text search of %s.', 'wp-seopress-pro' ),
				get_bloginfo( 'name' )
			),
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'query' => array(
						'type'        => 'string',
						'description' => 'The search query.',
					),
				),
				'required'   => array( 'query' ),
			),
		),
		array(
			'name'        => 'fetch_as_markdown',
			'description' => __( 'Fetch any public page on this site as Markdown.', 'wp-seopress-pro' ),
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'url' => array(
						'type'        => 'string',
						'description' => 'Absolute URL of a page on this site.',
					),
				),
				'required'   => array( 'url' ),
			),
		),
	);

	$tools = apply_filters( 'seopress_agent_ready_webmcp_tools', $tools );

	if ( empty( $tools ) || ! is_array( $tools ) ) {
		return;
	}

	// JSON_HEX_TAG escapes `<`/`>` so a tool description containing
	// `</script>` can't break out of the inline script tag.
	$tools_json = wp_json_encode( $tools, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES );

	if ( false === $tools_json ) {
		return;
	}

	$search_url = esc_js( rest_url( 'wp/v2/search' ) );
	$extra_js   = (string) apply_filters( 'seopress_agent_ready_webmcp_extra_js', '' );

	$script  = '(function(){';
	$script .= 'var ctx=(typeof navigator!=="undefined")?navigator.modelContext:null;';
	$script .= 'if(!ctx||typeof ctx.provideContext!=="function")return;';
	$script .= 'var tools=' . $tools_json . ';';
	$script .= 'tools.forEach(function(t){';
	$script .= 'if(t.name==="search_site"){t.execute=async function(a){var q=(a&&a.query)||"";var r=await fetch("' . $search_url . '?search="+encodeURIComponent(q));if(!r.ok)return{error:"search failed",status:r.status};return{results:await r.json()};};}';
	$script .= 'else if(t.name==="fetch_as_markdown"){t.execute=async function(a){var u=(a&&a.url)||"";var r=await fetch(u,{headers:{"Accept":"text/markdown"}});return{contentType:r.headers.get("content-type")||"",body:await r.text()};};}';
	$script .= '});';
	if ( '' !== $extra_js ) {
		$script .= $extra_js;
	}
	$script .= 'try{ctx.provideContext({tools:tools});}catch(e){}';
	$script .= '})();';

	if ( function_exists( 'wp_print_inline_script_tag' ) ) {
		wp_print_inline_script_tag( $script, array( 'id' => 'seopress-webmcp' ) );
	} else {
		echo "<script id=\"seopress-webmcp\">{$script}</script>"; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action( 'wp_footer', 'seopress_agent_ready_print_webmcp_script', 99 );

/* -----------------------------------------------------------------------------
 * Protocol Discovery: /.well-known/mcp.json and /.well-known/api-catalog
 * ---------------------------------------------------------------------------*/

/**
 * Build the default MCP Server Card payload.
 *
 * Conforms to the MCP discovery shape expected by validators: a
 * top-level `serverInfo` object with `name` + `version`, a
 * `protocolVersion`, and a `capabilities` map. We expose the WordPress
 * REST API as the "resource" capability so agents have a real endpoint
 * to call, and keep the human-friendly metadata (homepage, description,
 * resources list) alongside.
 *
 * @return array
 */
function seopress_agent_ready_get_mcp_card() {
	$site_name        = (string) get_bloginfo( 'name' );
	$site_description = (string) get_bloginfo( 'description' );

	$card = array(
		'schemaVersion'   => '2025-06-18',
		'protocolVersion' => '2025-06-18',
		'name'            => $site_name,
		'version'         => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '1.0.0',
		'description'     => $site_description,
		'homepage'        => home_url( '/' ),
		'serverInfo'      => array(
			'name'    => $site_name,
			'version' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '1.0.0',
		),
		'capabilities'    => array(
			'resources' => array(
				'listChanged' => false,
			),
		),
		'resources'       => array(
			array(
				'name' => 'sitemap',
				'uri'  => home_url( '/sitemaps.xml' ),
				'mimeType' => 'application/xml',
			),
			array(
				'name' => 'llms',
				'uri'  => home_url( '/llms.txt' ),
				'mimeType' => 'text/plain',
			),
			array(
				'name' => 'api-catalog',
				'uri'  => home_url( '/.well-known/api-catalog' ),
				'mimeType' => 'application/json',
			),
			array(
				'name' => 'agent-skills',
				'uri'  => home_url( '/.well-known/agent-skills/index.json' ),
				'mimeType' => 'application/json',
			),
			array(
				'name' => 'rest-api',
				'uri'  => rest_url(),
				'mimeType' => 'application/json',
			),
		),
		'servers'         => array(),
	);

	return apply_filters( 'seopress_agent_ready_mcp_card', $card );
}

/**
 * Build the default A2A Agent Card payload (Agent2Agent protocol).
 *
 * Lightweight description of the site as an "agent" peers can talk to.
 * For non-application sites this mostly mirrors the MCP card's
 * identity fields but uses the A2A schema names ('agent', 'skills',
 * 'capabilities') so the A2A discovery probe finds something valid.
 *
 * @return array
 */
function seopress_agent_ready_get_a2a_agent_card() {
	$site_name        = (string) get_bloginfo( 'name' );
	$site_description = (string) get_bloginfo( 'description' );

	$card = array(
		'schemaVersion' => '2025-06-18',
		'name'          => $site_name,
		'description'   => $site_description,
		'url'           => home_url( '/' ),
		'version'       => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '1.0.0',
		'capabilities'  => array(
			'streaming'             => false,
			'pushNotifications'     => false,
			'stateTransitionHistory' => false,
		),
		'supportedInterfaces' => array(
			array(
				'transport' => 'HTTP+JSON',
				'url'       => rest_url(),
			),
			array(
				'transport' => 'text/markdown',
				'url'       => add_query_arg( 'format', 'md', home_url( '/' ) ),
			),
		),
		'defaultInputModes'  => array( 'text/plain' ),
		'defaultOutputModes' => array( 'text/markdown', 'text/html' ),
		'skills'             => array(
			array(
				'id'          => 'search',
				'name'        => 'Site search',
				'description' => 'Full-text search of the site content.',
				'tags'        => array( 'search' ),
				'examples'    => array( '?s=hello' ),
			),
			array(
				'id'          => 'fetch-markdown',
				'name'        => 'Fetch page as Markdown',
				'description' => 'Return any public page as a Markdown document.',
				'tags'        => array( 'content', 'markdown' ),
			),
		),
	);

	return apply_filters( 'seopress_agent_ready_a2a_agent_card', $card );
}

/**
 * Build the default API Catalog payload (RFC 9727-style).
 *
 * Points agents to the WordPress REST API root, where they can
 * auto-discover available endpoints via the standard WP API index.
 *
 * @return array
 */
function seopress_agent_ready_get_api_catalog() {
	$catalog = array(
		'linkset' => array(
			array(
				'anchor' => home_url( '/' ),
				'service-desc' => array(
					array(
						'href' => rest_url(),
						'type' => 'application/json',
					),
				),
			),
		),
	);

	return apply_filters( 'seopress_agent_ready_api_catalog', $catalog );
}

/**
 * Build the default Agent Skills index payload.
 *
 * Conforms to the Agent Skills v0.2.0 index format
 * (https://agentskills.io). The index lists each skill's id and the
 * URI of its individual descriptor. Sites that expose richer
 * capabilities can extend this via the
 * 'seopress_agent_ready_agent_skills' filter.
 *
 * @return array
 */
function seopress_agent_ready_get_agent_skills() {
	$skills_base = home_url( '/.well-known/agent-skills' );

	$index = array(
		'schemaVersion' => '0.2.0',
		'name'          => (string) get_bloginfo( 'name' ),
		'description'   => (string) get_bloginfo( 'description' ),
		'skills'        => array(
			array(
				'id'          => 'search',
				'name'        => 'Site search',
				'description' => 'Full-text search of the site content.',
				'uri'         => $skills_base . '/search.json',
			),
			array(
				'id'          => 'fetch-markdown',
				'name'        => 'Fetch page as Markdown',
				'description' => 'Return any public page as a Markdown document.',
				'uri'         => $skills_base . '/fetch-markdown.json',
			),
		),
	);

	return apply_filters( 'seopress_agent_ready_agent_skills', $index );
}

/**
 * Build the descriptor payload for an individual agent skill.
 *
 * @param string $skill_id Skill identifier (e.g. 'search', 'fetch-markdown').
 * @return array|null Skill descriptor, or null if unknown.
 */
function seopress_agent_ready_get_agent_skill_descriptor( $skill_id ) {
	$descriptors = array(
		'search'         => array(
			'schemaVersion' => '0.2.0',
			'id'            => 'search',
			'name'          => 'Site search',
			'description'   => 'Full-text search of the site content.',
			'invocation'    => array(
				'method' => 'GET',
				'url'    => add_query_arg( 's', '{query}', home_url( '/' ) ),
			),
			'parameters'    => array(
				'query' => array(
					'type'        => 'string',
					'description' => 'The search query.',
					'required'    => true,
				),
			),
		),
		'fetch-markdown' => array(
			'schemaVersion' => '0.2.0',
			'id'            => 'fetch-markdown',
			'name'          => 'Fetch page as Markdown',
			'description'   => 'Return any public page as a Markdown document.',
			'invocation'    => array(
				'method' => 'GET',
				'url'    => '{page_url}?format=md',
				'accept' => 'text/markdown',
			),
			'parameters'    => array(
				'page_url' => array(
					'type'        => 'string',
					'description' => 'Absolute URL of a page on this site.',
					'required'    => true,
				),
			),
		),
	);

	$descriptors = apply_filters( 'seopress_agent_ready_agent_skill_descriptors', $descriptors );

	return isset( $descriptors[ $skill_id ] ) ? $descriptors[ $skill_id ] : null;
}

/**
 * Serve virtual /.well-known/* endpoints for agent discovery.
 *
 * @param bool         $do_parse         Whether to parse the request.
 * @param WP           $wp               Current WordPress environment instance.
 * @param array|string $extra_query_vars Extra passed query variables.
 *
 * @return bool
 */
function seopress_agent_ready_serve_well_known( $do_parse, $wp, $extra_query_vars ) {
	$request_uri = seopress_agent_ready_request_uri();

	if ( '' === $request_uri || false === strpos( $request_uri, '.well-known/' ) ) {
		return $do_parse;
	}

	$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
	$home_path = $home_path ? trim( $home_path, '/' ) : '';

	$request_path = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );

	if ( '' !== $home_path && 0 === strpos( $request_path, $home_path . '/' ) ) {
		$request_path = substr( $request_path, strlen( $home_path ) + 1 );
	}

	$routes = array(
		'.well-known/mcp.json'                => 'seopress_agent_ready_get_mcp_card',
		'.well-known/api-catalog'             => 'seopress_agent_ready_get_api_catalog',
		'.well-known/agent-card.json'         => 'seopress_agent_ready_get_a2a_agent_card',
		'.well-known/agent-skills/index.json' => 'seopress_agent_ready_get_agent_skills',
		// Back-compat for the v0.1 path that shipped in 9.7.x.
		'.well-known/agent-skills.json'       => 'seopress_agent_ready_get_agent_skills',
	);

	$payload = null;

	if ( isset( $routes[ $request_path ] ) ) {
		$payload = call_user_func( $routes[ $request_path ] );
	} elseif ( preg_match( '#^\.well-known/agent-skills/([a-z0-9_\-]+)\.json$#i', $request_path, $m ) ) {
		$payload = seopress_agent_ready_get_agent_skill_descriptor( $m[1] );
	}

	if ( null === $payload ) {
		return $do_parse;
	}

	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );

	$cors_origin = apply_filters( 'seopress_agent_ready_well_known_cors_origin', '*', $request_path );
	if ( is_string( $cors_origin ) && '' !== $cors_origin && false === strpbrk( $cors_origin, "\r\n\0" ) ) {
		header( 'Access-Control-Allow-Origin: ' . $cors_origin );
	}

	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

	exit;
}
add_filter( 'do_parse_request', 'seopress_agent_ready_serve_well_known', 0, 3 );

/* -----------------------------------------------------------------------------
 * Discoverability helper: AI bots robots.txt preset
 * ---------------------------------------------------------------------------*/

/**
 * Return a recommended robots.txt block listing common AI crawlers.
 *
 * Split between "training" bots (disallowed by default) and
 * "answer engine" bots used for live citations (allowed by default).
 * Sites can copy/paste this block into SEO > PRO > robots.txt,
 * or retrieve it programmatically via the filter below.
 *
 * @return string
 */
function seopress_agent_ready_get_ai_bots_robots_block() {
	$training_bots = array(
		'GPTBot',
		'Google-Extended',
		'Applebot-Extended',
		'ClaudeBot',
		'CCBot',
		'anthropic-ai',
		'Bytespider',
		'Amazonbot',
		'FacebookBot',
		'Meta-ExternalAgent',
		'cohere-ai',
		'Diffbot',
		'Omgili',
		'PerplexityBot-Train',
	);

	$answer_bots = array(
		'OAI-SearchBot',
		'ChatGPT-User',
		'PerplexityBot',
		'YouBot',
		'Google-CloudVertexBot',
	);

	$lines = array( '# AI bot rules (generated by SEOPress)' );

	foreach ( $training_bots as $bot ) {
		$lines[] = 'User-agent: ' . $bot;
		$lines[] = 'Disallow: /';
		$lines[] = '';
	}

	foreach ( $answer_bots as $bot ) {
		$lines[] = 'User-agent: ' . $bot;
		$lines[] = 'Allow: /';
		$lines[] = '';
	}

	$block = implode( "\n", $lines );

	return apply_filters( 'seopress_agent_ready_ai_bots_robots_block', $block, $training_bots, $answer_bots );
}
