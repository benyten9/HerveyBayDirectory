<?php //phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * AJAX.
 *
 * @package AJAX
 */
defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

require_once __DIR__ . '/export/csv.php';

/**
 * LB Widget order.
 *
 * @return void
 */
function seopress_pro_lb_widget() {
	check_ajax_referer( 'seopress_pro_lb_widget_nonce' );
	if ( current_user_can( 'edit_theme_options' ) && is_admin() ) {
		if ( isset( $_POST['order'] ) && $_POST['order'] && isset( $_POST['id'] ) && $_POST['id'] ) {
			$widget_option = get_option( 'widget_seopress_pro_lb_widget' );

			$widget_option[ (int) $_POST['id'] ]['order'] = sanitize_text_field( wp_unslash( $_POST['order'] ) );

			update_option( 'widget_seopress_pro_lb_widget', $widget_option );
		}
	}

	wp_send_json_success();
}
add_action( 'wp_ajax_seopress_pro_lb_widget', 'seopress_pro_lb_widget' );

/**
 * Clear Google Page Speed cache.
 *
 * @return void
 */
function seopress_clear_page_speed_cache() {
	check_ajax_referer( 'seopress_clear_page_speed_cache_nonce' );

	if ( ! current_user_can( seopress_capability( 'manage_options', 'pagespeed' ) ) ) {
		wp_send_json_error( 'not_authorized' );
	}

	global $wpdb;

	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_seopress_results_page_speed' " );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_seopress_results_page_speed' " );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_seopress_results_page_speed_desktop' " );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_seopress_results_page_speed_desktop' " );

	exit();
}
add_action( 'wp_ajax_seopress_clear_page_speed_cache', 'seopress_clear_page_speed_cache' );

/**
 * Reset License.
 *
 * @return void
 */
function seopress_request_reset_license() {
	check_ajax_referer( 'seopress_request_reset_license_nonce' );

	if ( current_user_can( seopress_capability( 'manage_options', 'license' ) ) && is_admin() ) {
		delete_option( 'seopress_pro_license_status' );
		delete_option( 'seopress_pro_license_key' );
		delete_option( 'seopress_pro_license_key_error' );
		delete_option( 'seopress_pro_license_automatic_attempt' );
		delete_option( 'seopress_pro_license_home_url' );

		$data = array( 'url' => admin_url( 'admin.php?page=seopress-license' ) );
		wp_send_json_success( $data );
	}
}
add_action( 'wp_ajax_seopress_request_reset_license', 'seopress_request_reset_license' );

/**
 * Lock Google Analytics view.
 *
 * @return void
 */
function seopress_google_analytics_lock() {
	check_ajax_referer( 'seopress_google_analytics_lock_nonce' );

	if ( ! current_user_can( seopress_capability( 'manage_options', 'google_analytics' ) ) ) {
		wp_send_json_error( 'not_authorized' );
	}

	update_option( 'seopress_google_analytics_lock_option_name', '1', 'yes' );

	wp_send_json_success();
}
add_action( 'wp_ajax_seopress_google_analytics_lock', 'seopress_google_analytics_lock' );

/**
 * Find the first Apache directive in a .htaccess body that could expose the
 * source of PHP files, or null when the body is clean.
 *
 * These directives switch off the PHP handler or reassign the file type, so the
 * server returns .php files as plain text instead of executing them. Any of them
 * turns wp-config.php into a readable file: database credentials, secret keys,
 * and every API key hardcoded elsewhere in the codebase. None of the snippets
 * this editor offers (redirects, directory-browsing, wp-config protection) uses
 * them, so refusing them costs the feature nothing.
 *
 * The cases beyond the obvious handler directives, and the line-continuation
 * handling that a naive line split misses, were contributed by Julio Potier.
 *
 * @since 10.1.2
 *
 * @param string $content Raw .htaccess body.
 * @param string $current The file as it is on disk. A line it already holds is
 *                        not refused: hosts and security plugins write their
 *                        own (cPanel's session.save_path, a firewall's
 *                        auto_prepend_file), and refusing them would block every
 *                        save without making the file any safer.
 * @return string|null The offending directive as matched, or null.
 */
function seopress_htaccess_find_unsafe_directive( $content, $current = '' ) {
	if ( ! is_string( $content ) || '' === trim( $content ) ) {
		return null;
	}

	$existing = array();
	if ( is_string( $current ) && '' !== $current ) {
		$current = preg_replace( '/\\[ \t]*(\r\n|\r|\n)[ \t]*/', '', $current );
		foreach ( preg_split( '/\r\n|\r|\n/', $current ) as $current_line ) {
			$existing[ trim( $current_line ) ] = true;
		}
	}

	// Include and IncludeOptional read directives from another file, which the
	// checks below never see: an upload carrying SetHandler would do.
	$blocked = array( 'SetHandler', 'AddHandler', 'RemoveHandler', 'AddType', 'RemoveType', 'ForceType', 'Include', 'IncludeOptional' );

	// Apache joins lines ending with a backslash before parsing directives, so
	// the same must happen here or "SetH\" + "andler" would slip through.
	$content = preg_replace( '/\\\\[ \t]*(\r\n|\r|\n)[ \t]*/', '', $content );

	foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $line ) {
		$trimmed = ltrim( $line );

		// Comments are inert, so a directive name inside one is harmless.
		if ( '' === $trimmed || '#' === $trimmed[0] ) {
			continue;
		}

		// Already in the file: saving it again changes nothing.
		if ( isset( $existing[ trim( $line ) ] ) ) {
			continue;
		}

		// The directive (or section tag) is the first token on the line; a
		// dangerous directive nested inside a <Files> block is still caught,
		// since the block container spans its own lines.
		$parts = preg_split( '/\s+/', $trimmed, 2 );
		$token = strtolower( $parts[0] );

		foreach ( $blocked as $directive ) {
			if ( $token === strtolower( $directive ) ) {
				return $directive;
			}
		}

		// Turning the PHP engine off has the same effect as removing the
		// handler. The value can be quoted, and the `_value` variants set the
		// same thing as the `_flag` ones.
		if ( in_array( $token, array( 'php_flag', 'php_admin_flag', 'php_value', 'php_admin_value' ), true )
			&& preg_match( '/\bengine\s+["\']?(off|0|false)\b/i', $trimmed ) ) {
			return 'php_flag engine off';
		}

		// Other PHP settings are checked against the ones sites usually tune
		// here. auto_prepend_file pointed at an upload runs it on every request,
		// and error_log or include_path are just as easy to turn into code
		// execution, so a list of what is allowed holds where a list of what is
		// refused kept missing one.
		$is_php_directive = in_array( $token, array( 'php_flag', 'php_admin_flag', 'php_value', 'php_admin_value' ), true );

		if ( $is_php_directive ) {
			$setting = isset( $parts[1] ) ? strtolower( (string) strtok( $parts[1], " \t" ) ) : '';

			if ( ! in_array( $setting, seopress_htaccess_allowed_php_settings(), true ) ) {
				return '' === $setting ? $parts[0] : $parts[0] . ' ' . $setting;
			}
		}

		// PHP-FPM reads the same settings from the PHP_VALUE and PHP_ADMIN_VALUE
		// variables Apache passes to it: SetEnv, SetEnvIf or a rewrite's E= flag.
		if ( ! $is_php_directive && preg_match( '/\bPHP_(?:ADMIN_)?VALUE\b/i', $trimmed ) ) {
			return 'PHP_VALUE';
		}

		// mod_headers runs in the fixup phase, before the handler is picked, and
		// setting Content-Type there calls ap_set_content_type(): that deselects
		// PHP wherever it is wired through AddType rather than SetHandler. Only
		// this header is refused, so the usual security headers still save.
		if ( 'header' === $token
			&& preg_match( '/^header\s+(?:always\s+|onsuccess\s+)?(?:set|setifempty|append|add|merge|unset|edit\*?|echo)\s+["\']?content-type\b/i', $trimmed ) ) {
			return 'Header Content-Type';
		}

		// Same phase for mod_rewrite: T= forces the MIME type, and H= replaces
		// the handler outright, which defeats SetHandler as well.
		if ( 'rewriterule' === $token && preg_match( '/\[[^\]]*\b(?:T|H)=/i', $trimmed ) ) {
			return 'RewriteRule T=/H=';
		}
	}

	return null;
}

/**
 * PHP settings the .htaccess editor accepts in php_value / php_flag lines.
 *
 * Resource limits, error display and the like: what sites usually set there,
 * and nothing that names a file PHP would load or write.
 *
 * @since 10.4.0
 *
 * @return string[] Lowercase setting names.
 */
function seopress_htaccess_allowed_php_settings() {
	$settings = array(
		'memory_limit',
		'upload_max_filesize',
		'post_max_size',
		'max_execution_time',
		'max_input_time',
		'max_input_vars',
		'max_input_nesting_level',
		'max_file_uploads',
		'default_socket_timeout',
		'display_errors',
		'display_startup_errors',
		'log_errors',
		'error_reporting',
		'html_errors',
		'output_buffering',
		'zlib.output_compression',
		'zlib.output_compression_level',
		'short_open_tag',
		'default_charset',
		'date.timezone',
		'pcre.backtrack_limit',
		'pcre.recursion_limit',
		'session.gc_maxlifetime',
		'session.cookie_lifetime',
		'session.cookie_httponly',
		'session.cookie_secure',
		'session.cookie_samesite',
		'session.use_only_cookies',
		'session.use_strict_mode',
		'mbstring.language',
		'mbstring.internal_encoding',
	);

	/**
	 * Filter the PHP settings the .htaccess editor accepts.
	 *
	 * Add to it with care: a setting that names a file PHP loads or writes
	 * (auto_prepend_file, error_log, include_path...) lets whoever can edit the
	 * file run code on the server.
	 *
	 * @since 10.4.0
	 *
	 * @param string[] $settings Lowercase setting names.
	 */
	$settings = (array) apply_filters( 'seopress_htaccess_allowed_php_settings', $settings );

	return array_map( 'strtolower', array_map( 'strval', $settings ) );
}

/**
 * Save htaccess file.
 *
 * @return void
 */
function seopress_save_htaccess() {
	check_ajax_referer( 'seopress_save_htaccess_nonce' );

	if ( ! current_user_can( seopress_capability( 'manage_options', 'htaccess' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to perform this action.', 'wp-seopress-pro' ) ), 403 );
	}

	// Refuse to write when the site is hardened against file edits. The settings
	// screen already hides the editor in this case, but that guard is only
	// applied while rendering the page: the AJAX endpoint has to enforce it too,
	// or the file can still be written by posting to it directly. A control that
	// lives only in the display is not a control.
	if ( seopress_htaccess_editing_is_disabled() ) {
		wp_send_json_error( array( 'message' => __( 'Editing the .htaccess file is disabled on this site.', 'wp-seopress-pro' ) ), 403 );
	}

	// On multisite the .htaccess lives at the network root and is shared by every
	// site, so only a Super Admin may write it. A regular site administrator must
	// not be able to change a file that affects the whole network.
	if ( is_multisite() && ! is_super_admin() ) {
		wp_send_json_error( array( 'message' => __( 'Only a Super Admin can edit the network .htaccess file.', 'wp-seopress-pro' ) ), 403 );
	}

	$filename = get_home_path() . '/.htaccess';

	if ( ! file_exists( get_home_path() . '/.htaccess' ) ) {
		$msg   = __( 'Impossible to open file: ', 'wp-seopress-pro' ) . $filename;
		$class = 'is-error';
	}
	$old_htaccess = file_get_contents( $filename );

	// Never defaulted before: a request without htaccess_content used to write an
	// undefined variable and empty the file. Default to empty and let the
	// homepage check below decide.
	$current_htaccess = isset( $_POST['htaccess_content'] ) ? wp_unslash( $_POST['htaccess_content'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- a .htaccess body is not sanitizable text; it is validated against a directive denylist below and written verbatim.

	// Reject directives that would make the server hand back the raw source of
	// PHP files, wp-config.php included. Checked before the file is touched, so a
	// rejected payload leaves the current .htaccess exactly as it was.
	$unsafe_directive = seopress_htaccess_find_unsafe_directive( $current_htaccess, is_string( $old_htaccess ) ? $old_htaccess : '' );
	if ( null !== $unsafe_directive ) {
		wp_send_json_success(
			array(
				'msg'   => sprintf(
					/* translators: %s: the Apache directive that was rejected, e.g. SetHandler. */
					__( 'For security reasons, the "%s" directive is not allowed here: it can expose the source code of your PHP files, including wp-config.php, or run code on the server. Please remove it and try again.', 'wp-seopress-pro' ),
					$unsafe_directive
				),
				'class' => 'is-error',
			)
		);
	}

	if ( is_writable( $filename ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Direct .htaccess access, already gated by is_writable(); WP_Filesystem would demand FTP credentials for a file the site owns.
		if ( ! $handle = fopen( $filename, 'w' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct .htaccess access, already gated by is_writable(); WP_Filesystem would demand FTP credentials for a file the site owns.
			$msg   = __( 'Impossible to open file: ', 'wp-seopress-pro' ) . $filename;
			$class = 'is-error';
		}

		if ( false === fwrite( $handle, $current_htaccess ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct .htaccess access, already gated by is_writable(); WP_Filesystem would demand FTP credentials for a file the site owns.
			$msg   = __( 'Impossible to write in file: ', 'wp-seopress-pro' ) . $filename;
			$class = 'is-error';
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct .htaccess access, already gated by is_writable(); WP_Filesystem would demand FTP credentials for a file the site owns.

		$args = array(
			'blocking'    => true,
			'redirection' => 5,
			'timeout'     => 10,
		);

		$response = wp_remote_get( get_home_url(), $args );
		$code     = wp_remote_retrieve_response_code( $response );

		if ( is_wp_error( $response ) || ! $code || $code >= 400 ) {
			$handle = fopen( $filename, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct .htaccess access, already gated by is_writable(); WP_Filesystem would demand FTP credentials for a file the site owns.
			fwrite( $handle, $old_htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct .htaccess access, already gated by is_writable(); WP_Filesystem would demand FTP credentials for a file the site owns.
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct .htaccess access, already gated by is_writable(); WP_Filesystem would demand FTP credentials for a file the site owns.

			$msg   = __( '.htaccess could not be saved: the homepage check failed and the previous file has been restored. This usually indicates a syntax error in your .htaccess, or a server-side loopback issue (firewall, Basic Auth, broken DNS). Please verify your homepage is reachable and try again.', 'wp-seopress-pro' );
			$class = 'is-error';
		} else {
			$msg   = __( '.htaccess successfully updated!', 'wp-seopress-pro' );
			$class = 'is-success';
		}
	} else {
		$msg   = __( 'Your .htaccess is not writable.', 'wp-seopress-pro' );
		$class = 'is-error';
	}

	$data = array(
		'msg'   => $msg,
		'class' => $class,
	);

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_seopress_save_htaccess', 'seopress_save_htaccess' );

/**
 * Inspect URL with Google Search Console API.
 *
 * @return void
 */
function seopress_inspect_url() {
	check_ajax_referer( 'seopress_inspect_url_nonce' );

	$data = array();

	// Get post id.
	$id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

	if ( empty( $id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $id ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to perform this action.', 'wp-seopress-pro' ) ), 403 );
	}

	$data = seopress_pro_get_service( 'InspectUrlGoogle' )->handle( $id );

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_seopress_inspect_url', 'seopress_inspect_url' );

/**
 * Regenerate Video XML Sitemap.
 *
 * @return void
 */
function seopress_pro_video_xml_sitemap_regenerate() {
	check_ajax_referer( 'seopress_video_regenerate_nonce', '_ajax_nonce', true );

	if ( current_user_can( seopress_capability( 'manage_options', 'migration' ) ) && is_admin() ) {
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		// Post types included in the XML sitemap. The names were quoted for the
		// count below and the quoted list was then handed to get_posts() too, so
		// it matched no post at all. Without a list, every type a front-end query
		// covers, as 'any' did.
		$cpt = array();
		foreach ( (array) seopress_get_service( 'SitemapOption' )->getPostTypesList() as $cpt_key => $cpt_value ) {
			foreach ( (array) $cpt_value as $_cpt_key => $_cpt_value ) {
				if ( '1' == $_cpt_value ) {
					$cpt[] = $cpt_key;
				}
			}
		}

		if ( empty( $cpt ) ) {
			$cpt = array_values( get_post_types( array( 'exclude_from_search' => false ) ) );
		}

		global $wpdb;
		$cpt_placeholders  = implode( ',', array_fill( 0, count( $cpt ), '%s' ) );
		$total_count_posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT count(*) FROM {$wpdb->posts} WHERE post_status IN ('pending', 'draft', 'publish', 'future') AND post_type IN ( $cpt_placeholders )", $cpt ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders only.

		$total_count_posts = apply_filters( 'seopress_video_regeneration_total_count_posts', $total_count_posts );

		$increment = 10;

		$increment = apply_filters( 'seopress_video_regeneration_increment', $increment );

		global $post;

		if ( $offset > $total_count_posts ) {
			wp_reset_postdata();
			$count_items = $total_count_posts;
			$offset      = 'done';
		} else {
			$args = array(
				'posts_per_page' => $increment,
				'post_type'      => $cpt,
				'post_status'    => array( 'pending', 'draft', 'publish', 'future' ),
				'offset'         => $offset,
			);

			$args = apply_filters( 'seopress_video_regeneration_query', $args, $increment, $cpt, $offset );

			$video_query = get_posts( $args );

			if ( $video_query ) {
				foreach ( $video_query as $post ) {
					seopress_pro_video_xml_sitemap( $post->ID, $post );
				}
			}
			$offset += $increment;
		}
		$data = array();

		$data['total'] = $total_count_posts;

		if ( $offset >= $total_count_posts ) {
			$data['count'] = $total_count_posts;
		} else {
			$data['count'] = $offset;
		}

		$data['offset'] = $offset;

		// Clear cache.
		delete_transient( '_seopress_sitemap_ids_video' );

		wp_send_json_success( $data );
		exit();
	}
}
add_action( 'wp_ajax_seopress_pro_video_xml_sitemap_regenerate', 'seopress_pro_video_xml_sitemap_regenerate' );

/**
 * Open AI - Generate SEO metadata.
 *
 * @return void
 */
function seopress_ai_generate_seo_meta() {
	check_ajax_referer( 'seopress_ai_generate_seo_meta_nonce' );

	$data = array();

	// Get post id.
	$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

	if ( empty( $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to perform this action.', 'wp-seopress-pro' ) ), 403 );
	}

	// Enforce the AI generation role gate (Advanced > Security) server-side.
	if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( $post_id, 'edit_post', 'metabox' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to perform this action.', 'wp-seopress-pro' ) ), 403 );
	}

	// Same reading as the term handler below. $language was left undefined
	// without `lang`, and esc_html() is an output escaper, not a sanitizer.
	$language = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : 'en_US';
	$meta     = isset( $_POST['meta'] ) ? sanitize_text_field( wp_unslash( $_POST['meta'] ) ) : '';

	if ( 'alt_text' === $meta || 'image_meta' === $meta ) {
		$fields = null;
		if ( isset( $_POST['fields'] ) && ! empty( $_POST['fields'] ) ) {
			$fields = array_map( 'sanitize_text_field', explode( ',', sanitize_text_field( $_POST['fields'] ) ) );
		}
		$data = seopress_pro_get_service( 'Completions' )->generateImgAltText( $post_id, $meta, $language, true, null, $fields );
	} elseif ( in_array( $meta, array( 'fb_title', 'fb_desc', 'twitter_title', 'twitter_desc' ), true ) ) {
		// Determine platform and meta_type from the meta value
		$platform  = ( strpos( $meta, 'fb_' ) === 0 ) ? 'facebook' : 'twitter';
		$meta_type = ( strpos( $meta, '_title' ) !== false ) ? 'title' : 'desc';

		$data = seopress_pro_get_service( 'Completions' )->generateSocialMetas( $post_id, $meta_type, $platform, $language );

		// Map the response key to match the expected format for JavaScript
		// generateSocialMetas returns 'content' key, we need to map it to the specific meta field
		if ( isset( $data['content'] ) ) {
			$data[ $meta ] = $data['content'];
		}
	} else {
		$data = seopress_pro_get_service( 'Completions' )->generateTitlesDesc( $post_id, $meta, $language );
	}

	// The service reports the outcome with an explicit flag: `message` alone
	// cannot be trusted, since it also carries benign notices such as "already
	// exists, no need to generate".
	if ( is_array( $data ) && empty( $data['success'] ) ) {
		wp_send_json_error( $data );
	}

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_seopress_ai_generate_seo_meta', 'seopress_ai_generate_seo_meta' );

/**
 * AJAX: generate a meta title / description for a taxonomy term with AI.
 *
 * The post handler above validates a post id and reads post_content, so terms
 * need their own entry point. Context is built from the term itself
 * (name, description, titles of related posts).
 *
 * @return void
 */
function seopress_ai_generate_term_seo_meta() {
	check_ajax_referer( 'seopress_ai_generate_seo_meta_nonce' );

	$term_id  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
	$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';

	if ( empty( $term_id ) || empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid term provided.', 'wp-seopress-pro' ) ), 400 );
	}

	if ( ! current_user_can( 'edit_term', $term_id ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to perform this action.', 'wp-seopress-pro' ) ), 403 );
	}

	// Enforce the AI generation role gate (Advanced > Security) server-side.
	if ( function_exists( 'seopress_ai_generation_check' ) && ! seopress_ai_generation_check( $term_id, 'edit_term', 'metabox' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to perform this action.', 'wp-seopress-pro' ) ), 403 );
	}

	$language = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : 'en_US';
	$meta     = isset( $_POST['meta'] ) ? sanitize_text_field( wp_unslash( $_POST['meta'] ) ) : '';

	$data = seopress_pro_get_service( 'Completions' )->generateTermTitlesDesc( $term_id, $taxonomy, $meta, $language );

	if ( is_array( $data ) && empty( $data['success'] ) ) {
		wp_send_json_error( $data );
	}

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_seopress_ai_generate_term_seo_meta', 'seopress_ai_generate_term_seo_meta' );

/**
 * AI - Check license key.
 *
 * @return void
 */
function seopress_ai_check_license_key() {
	check_ajax_referer( 'seopress_ai_check_license_key_nonce' );

	if ( ! current_user_can( seopress_capability( 'manage_options', 'pro' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-seopress-pro' ) ) );
		return;
	}

	// Determine provider based on the request.
	$provider = 'openai'; // default
	if ( isset( $_REQUEST['seopress_ai_provider'] ) ) {
		$provider = strtolower( sanitize_text_field( wp_unslash( $_REQUEST['seopress_ai_provider'] ) ) );

		// The provider names the option the key is saved in and the constant it
		// is read from: anything else than a supported one is refused.
		if ( ! array_key_exists( $provider, \SEOPressPro\Services\OpenAI\Usage::PROVIDER_KEY_CONSTANTS ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown AI provider.', 'wp-seopress-pro' ) ) );
			return;
		}
	} elseif ( isset( $_REQUEST['seopress_ai_model'] ) ) {
		$model = sanitize_text_field( $_REQUEST['seopress_ai_model'] );
		// Extract provider from model or determine based on context.
		if ( strpos( $model, 'deepseek' ) !== false ) {
			$provider = 'deepseek';
		} else {
			$provider = 'openai';
		}
	}

	// Save API key for the specific provider (only if it's not the placeholder).
	$options        = get_option( 'seopress_pro_option_name' );
	$placeholder    = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
	$submitted_key  = isset( $_REQUEST['seopress_ai_api_key'] ) ? sanitize_text_field( $_REQUEST['seopress_ai_api_key'] ) : '';

	// Only update if a new key is provided (not the placeholder or empty).
	if ( ! empty( $submitted_key ) && $submitted_key !== $placeholder ) {
		$options[ 'seopress_ai_' . $provider . '_api_key' ] = $submitted_key;
		update_option( 'seopress_pro_option_name', $options );
	}

	// Save model selection if provided.
	if ( isset( $_REQUEST['seopress_ai_model'] ) ) {
		$options[ 'seopress_ai_' . $provider . '_model' ] = sanitize_text_field( $_REQUEST['seopress_ai_model'] );
		update_option( 'seopress_pro_option_name', $options );
	}

	$usage_service = seopress_pro_get_service( 'Usage' );
	if ( null === $usage_service ) {
		wp_send_json_error( array( 'message' => __( 'Service not available.', 'wp-seopress-pro' ) ) );
		return;
	}

	$data = $usage_service->checkLicenseKeyExists( $provider );

	if ( 'success' === $data['code'] ) {
		$data = $usage_service->checkLicenseKeyExpiration( $provider );
	}

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_seopress_ai_check_license_key', 'seopress_ai_check_license_key' );

/**
 * AI Credits - Get credit balance.
 *
 * @return void
 */
function seopress_ai_credits_balance() {
	check_ajax_referer( 'seopress_ai_credits_nonce' );

	if ( ! current_user_can( 'manage_options' ) || ! is_admin() ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-seopress-pro' ) ) );
		return;
	}

	$usage_service = seopress_pro_get_service( 'Usage' );
	if ( null === $usage_service ) {
		wp_send_json_error( array( 'message' => __( 'Service not available.', 'wp-seopress-pro' ) ) );
		return;
	}

	$api_key = $usage_service->getLicenseKey( 'seopress' );
	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No SEOPress Credits token configured.', 'wp-seopress-pro' ) ) );
		return;
	}

	$cache_key = 'seopress_ai_credits_balance_' . md5( $api_key );
	$error_key = $cache_key . '_error';
	$refresh   = isset( $_POST['refresh'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['refresh'] ) );

	if ( ! $refresh ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			wp_send_json_success( $cached );
			return;
		}

		// The Dashboard asks for the balance on every visit: without this, a
		// credits API that's down or slow would mean a 30s request each time.
		$cached_error = get_transient( $error_key );
		if ( is_string( $cached_error ) && '' !== $cached_error ) {
			wp_send_json_error( array( 'message' => $cached_error ) );
			return;
		}
	}

	$proxy_url = defined( 'SEOPRESS_AI_PROXY_URL' ) ? SEOPRESS_AI_PROXY_URL : 'https://api.seopress.org';

	$response = wp_remote_get(
		$proxy_url . '/v1/balance',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
			),
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $response ) ) {
		$message = $response->get_error_message();
		set_transient( $error_key, $message, MINUTE_IN_SECONDS );
		wp_send_json_error( array( 'message' => $message ) );
		return;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 === wp_remote_retrieve_response_code( $response ) && is_array( $body ) ) {
		// Only keep what the UI consumes: whatever else the proxy may return one
		// day (customer identifiers, plan internals) has no reason to be stored
		// in wp_options, and to end up in every database backup.
		$balance = array(
			'status'               => isset( $body['status'] ) ? (string) $body['status'] : '',
			'credits_remaining'    => isset( $body['credits_remaining'] ) ? (int) $body['credits_remaining'] : 0,
			'credits_used'         => isset( $body['credits_used'] ) ? (int) $body['credits_used'] : 0,
			'monthly_credit_limit' => isset( $body['monthly_credit_limit'] ) ? (int) $body['monthly_credit_limit'] : 0,
			'plan_tier'            => isset( $body['plan_tier'] ) ? (string) $body['plan_tier'] : '',
			'cycle_reset_date'     => isset( $body['cycle_reset_date'] ) ? (string) $body['cycle_reset_date'] : '',
		);

		delete_transient( $error_key );
		set_transient( $cache_key, $balance, 5 * MINUTE_IN_SECONDS );
		wp_send_json_success( $balance );
	} else {
		// The proxy answers with an error that is either a string or an object
		// carrying a message key, depending on the failure.
		$error = isset( $body['error'] ) ? $body['error'] : '';
		if ( is_array( $error ) ) {
			$error = isset( $error['message'] ) ? $error['message'] : '';
		}

		$message = is_string( $error ) && '' !== $error
			? $error
			: __( 'Failed to retrieve credit balance.', 'wp-seopress-pro' );

		set_transient( $error_key, $message, MINUTE_IN_SECONDS );
		wp_send_json_error( array( 'message' => $message ) );
	}
}
add_action( 'wp_ajax_seopress_ai_credits_balance', 'seopress_ai_credits_balance' );

/**
 * Site Audit - Load dynamically analysis.
 *
 * @return void
 */
function seopress_site_audit_load_analysis() {
	// Check nonce.
	check_ajax_referer( 'seopress_request_bot_nonce' );

	// Check if user is in admin.
	if ( ! is_admin() ) {
		return;
	}

	$allowed = current_user_can( seopress_capability( 'manage_options', 'bot' ) );

	// Check if user is allowed to access the analysis.
	if ( ! $allowed ) {
		return;
	}

	// Check if GSC toggle is ON.
	if ( seopress_get_service( 'ToggleOption' )->getToggleBot() !== '1' ) {
		return;
	}

	if ( ! isset( $_POST['type'] ) ) {
		wp_send_json_error( 'Type not provided.' );
	}

	// The legacy PHP rendering this endpoint returned was removed with the
	// React Site Audit, which no longer calls it. Calling the removed
	// SiteAudit::renderAnalysisResults() was a fatal error; the checks above
	// stay so a stale caller is answered the same way as before.
	wp_send_json_error( 'Not available.', 410 );
}
add_action( 'wp_ajax_seopress_site_audit_load_analysis', 'seopress_site_audit_load_analysis' );

/**
 * Site Audit - Run actions.
 *
 * @return void
 */
function seopress_site_audit_run_actions() {
	// Check nonce.
	check_ajax_referer( 'seopress_request_bot_nonce' );

	// Check if user is in admin.
	if ( ! is_admin() ) {
		return;
	}

	$allowed = current_user_can( seopress_capability( 'manage_options', 'bot' ) );

	// Same reasoning as the REST route next to it: the `bot` area can be
	// delegated to any role in Advanced > Security, and that delegation says
	// nothing about AI. This handler generates and writes alt text, so the
	// restriction applies as it does on every ordinary generation route.
	// The read-only analysis loader above is deliberately untouched.
	// See SEC-05.
	if ( $allowed
		&& function_exists( 'seopress_ai_generation_check' )
		&& ! seopress_ai_generation_check( null, seopress_capability( 'manage_options', 'bot' ), 'bulk' ) ) {
		$allowed = false;
	}

	// Check if user is allowed to access the analysis.
	if ( ! $allowed ) {
		return;
	}

	// Validate and sanitize input.
	if ( ! isset( $_POST['issue_post_id'] ) ) {
		wp_send_json_error( 'Post ID not provided.' );
	}

	$issue_post_id = absint( $_POST['issue_post_id'] );
	if ( ! $issue_post_id ) {
		wp_send_json_error( 'Invalid Post ID.' );
	}

	// Retrieve data.
	$data = seopress_pro_get_service( 'SEOIssuesDatabase' )->getData( $issue_post_id, array( 'img_alt' ) );

	if ( empty( $data ) || empty( $data[0] ) ) {
		wp_send_json_error( 'No data found.' );
	}

	$issue_desc = maybe_unserialize( $data[0]['issue_desc'] );
	if ( is_array( $issue_desc ) && ! empty( $issue_desc ) ) {
		$results = array();
		foreach ( $issue_desc as $issue ) {
			$attachment_id = seopress_get_service( 'SearchAttachment' )->searchByUrl( $issue );

			if ( ! empty( $attachment_id ) ) {
				foreach ( $attachment_id as $id ) {
					$result = seopress_pro_get_service( 'Completions' )->generateImgAltText( $id, 'alt_text' );
					if ( ! is_wp_error( $result ) ) {
						$results[] = $result;
					}
				}
			}
		}
		wp_send_json_success( $results );
	} else {
		wp_send_json_error( 'No valid issues found.' );
	}
}
add_action( 'wp_ajax_seopress_site_audit_run_actions', 'seopress_site_audit_run_actions' );

/**
 * Test a YouTube Data API key with one request.
 *
 * Tests the key typed in the settings field when there is one, otherwise the
 * key in use. A key that works lifts the pause set after YouTube refused it,
 * so a project the API was just enabled on is used again right away.
 *
 * @since 10.3.0
 *
 * @return void
 */
function seopress_pro_test_youtube_api_key() {
	check_ajax_referer( 'seopress_pro_youtube_api_key_nonce' );

	if ( ! current_user_can( seopress_capability( 'manage_options', 'pro' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-seopress-pro' ) ) );
	}

	$api_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
	if ( '' === $api_key || seopress_pro_youtube_api_key_is_constant() ) {
		$api_key = seopress_pro_get_youtube_api_key();
	}

	if ( '' === $api_key ) {
		wp_send_json_error( array( 'message' => __( 'Enter an API key first.', 'wp-seopress-pro' ) ) );
	}

	// "Me at the zoo", the first video uploaded to YouTube: public and not going anywhere.
	$result = seopress_pro_request_youtube_data_api( 'jNQXAC9IVRw', $api_key );

	if ( 200 === $result['code'] ) {
		if ( seopress_pro_get_youtube_api_key() === $api_key ) {
			delete_transient( 'seopress_pro_yt_api_paused' );
		}

		wp_send_json_success( array( 'message' => __( 'The API key works.', 'wp-seopress-pro' ) ) );
	}

	switch ( $result['reason'] ) {
		case 'API_KEY_INVALID':
		case 'keyInvalid':
		case 'badRequest':
			$message = __( 'This API key is not valid.', 'wp-seopress-pro' );
			break;
		case 'SERVICE_DISABLED':
		case 'accessNotConfigured':
			$message = __( 'The YouTube Data API v3 is not enabled in the Google Cloud project this key belongs to.', 'wp-seopress-pro' );
			break;
		case 'API_KEY_HTTP_REFERRER_BLOCKED':
		case 'API_KEY_IP_ADDRESS_BLOCKED':
		case 'API_KEY_SERVICE_BLOCKED':
		case 'ipRefererBlocked':
		case 'forbidden':
			$message = __( 'This key is restricted and refuses requests from this server. Requests are sent by the server, not by the browser: restrict the key by IP address or by API, not by website.', 'wp-seopress-pro' );
			break;
		case 'quotaExceeded':
		case 'dailyLimitExceeded':
		case 'rateLimitExceeded':
		case 'RATE_LIMIT_EXCEEDED':
			$message = __( 'The daily quota of this key is used up. It is reset at midnight Pacific Time.', 'wp-seopress-pro' );
			break;
		default:
			if ( 0 === $result['code'] ) {
				/* translators: %s: error code, e.g. http_request_failed */
				$message = sprintf( __( 'YouTube could not be reached (%s).', 'wp-seopress-pro' ), $result['reason'] );
			} else {
				/* translators: 1: HTTP status code, 2: error reason reported by YouTube */
				$message = sprintf( __( 'YouTube refused the request (HTTP %1$d, %2$s).', 'wp-seopress-pro' ), $result['code'], $result['reason'] );
			}
	}

	wp_send_json_error( array( 'message' => $message ) );
}
add_action( 'wp_ajax_seopress_pro_test_youtube_api_key', 'seopress_pro_test_youtube_api_key' );
