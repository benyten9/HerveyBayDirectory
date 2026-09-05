<?php //phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * Watchers.
 *
 * @package Redirects
 */
defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( '1' === seopress_pro_get_service( 'OptionPro' )->get404DisableAutomaticRedirects() ) {
	add_filter( 'seopress_post_automatic_redirect', '__return_false' );
}

/**
 * Get the option to propose a redirection.
 *
 * @return array
 */
function seopress_get_option_post_need_redirects() {
	return get_option( 'seopress_can_post_redirect' );
}

/**
 * Build a deep-link URL to the React Redirections DataViews app, optionally
 * pre-filled with an origin (and a destination for "update" notices).
 *
 * The query string params live before the hash so the SPA can read them
 * via window.location.search while routing on window.location.hash.
 *
 * @param string $before_url Path of the original URL (origin to redirect from).
 * @param string $new_url    Path of the new URL (destination, optional).
 * @return string
 */
function seopress_get_redirection_create_url( $before_url = '', $new_url = '' ) {
	$args = array( 'page' => 'seopress-redirections' );

	$before_url = trim( (string) $before_url, '\/' );
	$new_url    = trim( (string) $new_url, '\/' );

	if ( '' !== $before_url ) {
		$args['seopress_prefill_origin'] = $before_url;
	}

	if ( '' !== $new_url ) {
		// Destination is stored as a full URL, so prefix it with home_url().
		$args['seopress_prefill_destination'] = user_trailingslashit( home_url( '/' . $new_url ) );
	}

	return admin_url( 'admin.php?' . http_build_query( $args ) ) . '#/new';
}

if ( '1' == seopress_get_toggle_option( '404' ) && apply_filters( 'seopress_post_automatic_redirect', true ) ) {
	/**
	 * Get the permalink for an updated post.
	 *
	 * @param int $post The post ID.
	 * @return string
	 */
	function seopress_get_permalink_for_updated_post( $post ) {
		$url = wp_parse_url( get_permalink( $post ) );
		if ( is_array( $url ) && isset( $url['path'] ) ) {
			return $url['path'];
		}

		return '';
	}

	/**
	 * Update of the option to propose a redirection.
	 *
	 * @return void
	 *
	 * @param mixed $message The message to add.
	 */
	function seopress_create_notification_for_redirect( $message ) {
		$messages = seopress_get_option_post_need_redirects();
		if ( ! $messages ) {
			$messages = array();
		}

		$messages[] = $message;

		update_option( 'seopress_can_post_redirect', $messages, false );
	}

	/**
	 * Delete the option to propose a redirection.
	 *
	 * @return void
	 *
	 * @param mixed $id The ID of the message to remove.
	 */
	function seopress_remove_notification_for_redirect( $id ) {
		$messages = seopress_get_option_post_need_redirects();
		if ( ! $messages ) {
			return;
		}

		foreach ( $messages as $key => $message ) {
			if ( $id === $message['id'] ) {
				unset( $messages[ $key ] );
			}
		}

		if ( empty( $messages ) ) {
			delete_option( 'seopress_can_post_redirect' );

			return;
		}

		update_option( 'seopress_can_post_redirect', $messages, false );
	}

	/**
	 * Checks if a post needs to be repeated.
	 *
	 * @param int $post_id The post ID.
	 * @return bool True if the post needs to be redirected, false otherwise.
	 */
	function seopress_can_post_autoredirect( $post_id ) {
		$post_type = get_post_type_object( get_post_type( $post_id ) );

		if ( ! $post_type ) {
			return false;
		}

		$post_types = seopress_get_service( 'WordPressData' )->getPostTypes();

		unset(
			$post_types['seopress_rankings'],
			$post_types['seopress_backlinks'],
			$post_types['seopress_404'],
			$post_types['elementor_library'],
			$post_types['fl-builder-template'],
			$post_types['editor-template'],
			$post_types['editor-form-entry'],
			$post_types['breakdance_form_res'],
			$post_types['customer_discount'],
			$post_types['cuar_private_file'],
			$post_types['cuar_private_page'],
			$post_types['vc_grid_item'],
			$post_types['zion_template'],
			$post_types['tbuilder_layout'],
			$post_types['tbuilder_layout_part'],
			$post_types['tb_cf'],
			$post_types['ct_template'],
			$post_types['oxy_user_library'],
			$post_types['bricks_template']
		);

		$post_types = apply_filters( 'seopress_automatic_redirect_cpt', $post_types );

		$post_type_authorized = array();
		foreach ( $post_types as $key => $type ) {
			$post_type_authorized[] = $type->name;
		}

		return in_array( $post_type->name, $post_type_authorized, true );
	}

	add_action( 'admin_notices', 'seopress_notice_need_to_redirect' );

	/**
	 * Notice proposing to create a redirection.
	 *
	 * @return void
	 */
	function seopress_notice_need_to_redirect() {
		$notices = seopress_get_option_post_need_redirects();
		if ( ! $notices ) {
			return;
		}

		if ( ! current_user_can( seopress_capability( 'edit_redirections', 'notice' ) ) ) {
			return;
		}

		if ( count( $notices ) > 1 ) {
			$remove_all_notices_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'seopress_dismiss_all_notice_need_to_redirect',
					),
					admin_url( 'admin-post.php' )
				),
				'seopress_dismiss_all_notice_need_to_redirect'
			);
			$info                   = /* translators: %s number of redirections */ __( 'We have %s redirections that needs your attention', 'wp-seopress-pro' );
			$view_all               = /* translators: %s number of notices */ __( 'View all notices (%s)', 'wp-seopress-pro' ); ?>
<div class="notice notice-warning">
	<p>
			<?php printf( esc_html( $info ), count( $notices ) ); ?>
	</p>
	<p>
		<a href="#" id="js-view-all-notices" class="button button-primary">
			<?php printf( esc_html( $view_all ), count( $notices ) ); ?>
		</a> 
		<a href="<?php echo esc_url( $remove_all_notices_url ); ?>"
			class="button button-secondary">
			<?php esc_html_e( 'Remove all notices', 'wp-seopress-pro' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=seopress-import-export#tab=tab_seopress_tool_redirects' ) ); ?>"
			class="button-link">
			<?php esc_html_e( 'Export all slug changes', 'wp-seopress-pro' ); ?>
		</a>
	</p>
</div>


			<?php
		}

		$notices = array_reverse( $notices );
		foreach ( $notices as $key => $notice ) {
			$href_button = seopress_get_redirection_create_url(
				(string) ( $notice['before_url'] ?? '' ),
				'update' === $notice['type'] ? (string) ( $notice['new_url'] ?? '' ) : ''
			);
			?>

<div class="notice notice-warning 
			<?php
			if ( $key > 0 ) {
				?>
	notice-redirect-hide<?php } ?>"
	style="position:relative; 
			<?php
			if ( $key > 0 ) {
				?>
		display:none;<?php } ?>">
			<?php
				printf(
					'<a href="%s" class="notice-dismiss" style="text-decoration:none;"><span class="screen-reader-text">' . esc_html__( 'Dismiss this notice', 'wp-seopress-pro' ) . '</span></a>',
					wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'seopress_dismiss_notice_need_to_redirect',
								'id'     => $notice['id'],
							),
							admin_url( 'admin-post.php' )
						),
						'seopress_dismiss_notice_need_to_redirect'
					)
				);
			?>
			<?php echo wp_kses_post( $notice['message'] ); ?>
	<p>
		<a href="<?php echo esc_url( $href_button ); ?>" target="_blank"
			class="button button-secondary">
			<?php esc_html_e( 'Create a redirection (new window)', 'wp-seopress-pro' ); ?>
		</a>
	</p>
</div>
			<?php
		}

		if ( count( $notices ) > 1 ) {
			?>
<script>
	document.addEventListener('DOMContentLoaded', function() {
		const $ = jQuery
		$("#js-view-all-notices").on("click", function(e) {
			e.preventDefault()
			$(".notice-redirect-hide").each(function(key, item) {
				$(item).slideToggle()
			})
		})
	})
</script>
			<?php
		}
	}

	add_action( 'admin_post_seopress_dismiss_notice_need_to_redirect', 'seopress_dismiss_notice_need_to_redirect' );

	/**
	 * Deleting need to redirect notice.
	 *
	 * @return void
	 */
	function seopress_dismiss_notice_need_to_redirect() {
		// The nonce is required, not merely checked when present: guarding the
		// verification behind isset() meant a request that simply omitted
		// _wpnonce skipped it entirely.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'seopress_dismiss_notice_need_to_redirect' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=seopress-option' ) );
			exit;
		}

		if ( ! current_user_can( seopress_capability( 'edit_redirections', 'notice' ) ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=seopress-option' ) );
			exit;
		}

		if ( ! isset( $_GET['id'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=seopress-option' ) );
			exit;
		}

		seopress_remove_notification_for_redirect( sanitize_text_field( wp_unslash( $_GET['id'] ) ) );

		$redirect = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : admin_url( 'admin.php?page=seopress-option' );

		wp_safe_redirect( $redirect );
	}

	add_action( 'admin_post_seopress_dismiss_all_notice_need_to_redirect', 'seopress_dismiss_all_notice_need_to_redirect' );

	/**
	 * Deleting all notices need to redirect.
	 *
	 * @return void
	 */
	function seopress_dismiss_all_notice_need_to_redirect() {
		// Same as above: the nonce must be present and valid, not optional.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'seopress_dismiss_all_notice_need_to_redirect' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=seopress-option' ) );
			exit;
		}

		if ( ! current_user_can( seopress_capability( 'edit_redirections', 'notice' ) ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=seopress-option' ) );
			exit;
		}

		delete_option( 'seopress_can_post_redirect' );

		$redirect = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : admin_url( 'admin.php?page=seopress-option' );
		wp_safe_redirect( $redirect );
	}

	add_action( 'admin_init', 'seopress_pre_filling_data_need_to_redirect' );

	/**
	 * Pre-populate the redirect if we try to create one through the watcher.
	 *
	 * @return void
	 */
	function seopress_pre_filling_data_need_to_redirect() {
		if ( ! is_seopress_page() ) {
			return;
		}

		if ( ! isset( $_GET['post_type'] ) || 'seopress_404' !== $_GET['post_type'] ) {
			return;
		}

		global $pagenow;
		if ( ! in_array( $pagenow, array( 'post-new.php' ) ) || ! isset( $_GET['prepare_redirect'] ) ) {
			return;
		}

		add_filter(
			'get_post_metadata',
			function ( $metadata, $object_id, $meta_key, $single ) {
				$can_filters = array(
					'_seopress_redirections_value',
					'_seopress_redirections_enabled',
				);

				if ( ! in_array( $meta_key, $can_filters, true ) ) {
					return $metadata;
				}

				if ( '_seopress_redirections_enabled' === $meta_key ) {
					return 'yes';
				}
				if ( '_seopress_redirections_value' === $meta_key && isset( $_GET['redirect_to'] ) ) {
					$url_redirect = user_trailingslashit( sprintf( '%s/%s', home_url(), wp_unslash( $_GET['redirect_to'] ) ) );

					return esc_url( $url_redirect );
				}

				return $metadata;
			},
			1,
			4
		);
	}

	require_once __DIR__ . '/post-watcher.php';
	require_once __DIR__ . '/term-watcher.php';
}
