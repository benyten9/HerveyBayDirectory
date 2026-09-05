<?php
/**
 * SEOPress PRO Redirections.
 *
 * @package SEOPress PRO
 * @subpackage Redirections
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/*
 * Hooks
 */
// Always register the post type on `init`, for every context.
//
// Gating this on current_user_can() left the CPT unregistered wherever the
// current user is not an administrator — and `admin_init` never fires on the
// front end, in REST requests, in cron or in WP-CLI, so the fallback branch was
// a no-op there. Any capability check against a redirection then went through
// map_meta_cap() with an unregistered post type, which logs
// "The post type seopress_404 is not registered" on every such request.
//
// The check was also evaluated at file load, before the current user is
// resolved, so it could not be trusted even for administrators.
//
// Registering a post type is a data-structure declaration, not access control:
// this one is `public => false`, `publicly_queryable => false`,
// `show_in_menu => false`, `exclude_from_search => true` and uses the custom
// `redirection` capabilities, so declaring it everywhere exposes nothing.
add_action( 'init', 'seopress_404_fn', 10 );
add_filter( 'map_meta_cap', 'seopress_404_map_meta_cap', 10, 4 );
add_action( 'init', 'seopress_404_cat_fn', 10 );
add_action( 'admin_head', 'seopress_404_btn_cpt' );
add_action( 'manage_posts_extra_tablenav', 'seopress_404_maybe_render_blank_state' );
add_filter( 'post_row_actions', 'seopress_404_row_actions', 10, 2 );
add_filter( 'views_edit-seopress_404', 'seopress_404_filter_views_cpt' );
add_action( 'restrict_manage_posts', 'seopress_404_filters_cpt' );
add_filter( 'parse_query', 'seopress_404_filters_action' );

// Enable 301.
add_filter( 'bulk_actions-edit-seopress_404', 'seopress_bulk_actions_enable' );
add_filter( 'handle_bulk_actions-edit-seopress_404', 'seopress_bulk_action_enable_handler', 10, 3 );
add_action( 'seopress_admin_notices', 'seopress_bulk_action_enable_admin_notice' );
// Disable 301.
add_filter( 'bulk_actions-edit-seopress_404', 'seopress_bulk_actions_disable' );
add_filter( 'handle_bulk_actions-edit-seopress_404', 'seopress_bulk_action_disable_handler', 10, 3 );
add_action( 'seopress_admin_notices', 'seopress_bulk_action_disable_admin_notice' );
// Enable regex.
add_filter( 'bulk_actions-edit-seopress_404', 'seopress_bulk_actions_enable_regex' );
add_filter( 'handle_bulk_actions-edit-seopress_404', 'seopress_bulk_action_enable_regex_handler', 10, 3 );
add_action( 'seopress_admin_notices', 'seopress_bulk_action_enable_regex_admin_notice' );
// Disable regex.
add_filter( 'bulk_actions-edit-seopress_404', 'seopress_bulk_actions_disable_regex' );
add_filter( 'handle_bulk_actions-edit-seopress_404', 'seopress_bulk_action_disable_regex_handler', 10, 3 );
add_action( 'seopress_admin_notices', 'seopress_bulk_action_disable_regex_admin_notice' );
// Set as 301.
add_filter( 'bulk_actions-edit-seopress_404', 'seopress_bulk_actions_redirect_301' );
add_filter( 'handle_bulk_actions-edit-seopress_404', 'seopress_bulk_action_redirect_301_handler', 10, 3 );
add_action( 'seopress_admin_notices', 'seopress_bulk_action_redirect_301_admin_notice' );
// Set as 302.
add_filter( 'bulk_actions-edit-seopress_404', 'seopress_bulk_actions_redirect_302' );
add_filter( 'handle_bulk_actions-edit-seopress_404', 'seopress_bulk_action_redirect_302_handler', 10, 3 );
add_action( 'seopress_admin_notices', 'seopress_bulk_action_redirect_302_admin_notice' );
// Set as 307.
add_filter( 'bulk_actions-edit-seopress_404', 'seopress_bulk_actions_redirect_307' );
add_filter( 'handle_bulk_actions-edit-seopress_404', 'seopress_bulk_action_redirect_307_handler', 10, 3 );
add_action( 'seopress_admin_notices', 'seopress_bulk_action_redirect_307_admin_notice' );
// Set as 410.
add_filter( 'bulk_actions-edit-seopress_404', 'seopress_bulk_actions_redirect_410' );
add_filter( 'handle_bulk_actions-edit-seopress_404', 'seopress_bulk_action_redirect_410_handler', 10, 3 );
add_action( 'seopress_admin_notices', 'seopress_bulk_action_redirect_410_admin_notice' );
// Set as 451.
add_filter( 'bulk_actions-edit-seopress_404', 'seopress_bulk_actions_redirect_451' );
add_filter( 'handle_bulk_actions-edit-seopress_404', 'seopress_bulk_action_redirect_451_handler', 10, 3 );
add_action( 'seopress_admin_notices', 'seopress_bulk_action_redirect_451_admin_notice' );
// Optimized bulk delete.
add_action( 'load-edit.php', 'seopress_404_optimized_bulk_delete' );
add_action( 'seopress_admin_notices', 'seopress_404_bulk_delete_admin_notice' );
// Add to category.
add_filter( 'bulk_actions-edit-seopress_404', 'seopress_bulk_actions_add_to_cat' );
add_filter( 'handle_bulk_actions-edit-seopress_404', 'seopress_bulk_action_add_to_cat_handler', 10, 3 );
add_action( 'seopress_admin_notices', 'seopress_bulk_action_add_to_cat_admin_notice' );

add_filter( 'enter_title_here', 'seopress_404_cpt_title' );
add_action( 'edit_form_after_title', 'seopress_301_after_title' );
add_filter( 'post_updated_messages', 'seopress_404_set_messages' );
add_filter( 'bulk_post_updated_messages', 'seopress_404_set_messages_list', 10, 2 );
add_filter( 'edit_seopress_404_per_page', 'seopress_404_items_per_page' );

add_filter( 'manage_edit-seopress_404_columns', 'seopress_404_count_columns' );
add_action( 'manage_seopress_404_posts_custom_column', 'seopress_404_count_display_column', 10, 2 );

add_filter( 'manage_edit-seopress_404_sortable_columns', 'seopress_404_sortable_columns' );

add_filter( 'pre_get_posts', 'seopress_404_sort_columns_by' );

add_action( 'quick_edit_custom_box', 'seopress_bulk_quick_edit_301_custom_box', 10, 2 );
add_action( 'save_post', 'seopress_bulk_quick_edit_301_save_post', 10, 2 );
add_filter( 'wp_insert_post_data', 'seopress_filter_post_title', '99', 2 );

/**
 * Register SEOPress Redirections Custom Post Type
 */
function seopress_404_fn() {
	$labels = array(
		'name'                  => _x( 'Redirections', 'Post Type General Name', 'wp-seopress-pro' ),
		'singular_name'         => _x( 'Redirections', 'Post Type Singular Name', 'wp-seopress-pro' ),
		'menu_name'             => esc_html__( 'Redirections', 'wp-seopress-pro' ),
		'name_admin_bar'        => esc_html__( 'Redirections', 'wp-seopress-pro' ),
		'archives'              => esc_html__( 'Item Archives', 'wp-seopress-pro' ),
		'parent_item_colon'     => esc_html__( 'Parent Item:', 'wp-seopress-pro' ),
		'all_items'             => esc_html__( 'All redirections', 'wp-seopress-pro' ),
		'add_new_item'          => esc_html__( 'Add New redirection', 'wp-seopress-pro' ),
		'add_new'               => esc_html__( 'Add redirection', 'wp-seopress-pro' ),
		'new_item'              => esc_html__( 'New redirection', 'wp-seopress-pro' ),
		'edit_item'             => esc_html__( 'Edit redirection', 'wp-seopress-pro' ),
		'update_item'           => esc_html__( 'Update redirection', 'wp-seopress-pro' ),
		'view_item'             => esc_html__( 'View redirection', 'wp-seopress-pro' ),
		'search_items'          => esc_html__( 'Search redirection', 'wp-seopress-pro' ),
		'not_found'             => esc_html__( 'Not found', 'wp-seopress-pro' ),
		'not_found_in_trash'    => esc_html__( 'Not found in Trash', 'wp-seopress-pro' ),
		'featured_image'        => esc_html__( 'Featured Image', 'wp-seopress-pro' ),
		'set_featured_image'    => esc_html__( 'Set featured image', 'wp-seopress-pro' ),
		'remove_featured_image' => esc_html__( 'Remove featured image', 'wp-seopress-pro' ),
		'use_featured_image'    => esc_html__( 'Use as featured image', 'wp-seopress-pro' ),
		'insert_into_item'      => esc_html__( 'Insert into item', 'wp-seopress-pro' ),
		'uploaded_to_this_item' => esc_html__( 'Uploaded to this item', 'wp-seopress-pro' ),
		'items_list'            => esc_html__( 'Redirections list', 'wp-seopress-pro' ),
		'items_list_navigation' => esc_html__( 'Redirections list navigation', 'wp-seopress-pro' ),
		'filter_items_list'     => esc_html__( 'Filter redirections list', 'wp-seopress-pro' ),
	);
	$args   = array(
		'label'               => esc_html__( 'Redirections', 'wp-seopress-pro' ),
		'description'         => esc_html__( 'Redirections and Monitoring 404', 'wp-seopress-pro' ),
		'labels'              => $labels,
		'supports'            => array( 'title' ),
		'hierarchical'        => false,
		'public'              => false,
		'show_ui'             => true,
		'show_in_menu'        => false,
		'menu_icon'           => 'dashicons-admin-links',
		'show_in_admin_bar'   => false,
		'show_in_nav_menus'   => false,
		'can_export'          => true,
		'has_archive'         => false,
		'exclude_from_search' => true,
		'publicly_queryable'  => false,
		'capability_type'     => 'redirection',
		'capabilities'        => array(
			'edit_post'              => 'edit_redirection',
			'edit_posts'             => 'edit_redirections',
			'edit_others_posts'      => 'edit_others_redirections',
			'publish_posts'          => 'publish_redirections',
			'read_post'              => 'read_redirection',
			'read_private_posts'     => 'read_private_redirections',
			'delete_post'            => 'delete_redirection',
			'delete_others_posts'    => 'delete_others_redirections',
			'delete_published_posts' => 'delete_published_redirections',
		),
	);

	register_post_type( 'seopress_404', $args );
}

/**
 * Map SEOPress 404 caps
 *
 * @param array  $caps Capabilities.
 * @param string $cap Capability.
 * @param int    $user_id User ID.
 * @param array  $args Args.
 *
 * @return array Capabilities.
 */
function seopress_404_map_meta_cap( $caps, $cap, $user_id, $args ) {
	/* If editing, deleting, or reading a redirection, get the post and post type object. */
	if ( 'edit_redirection' === $cap || 'delete_redirection' === $cap || 'read_redirection' === $cap ) {
		if ( ! isset( $args[0] ) ) {
			return $caps;
		}
		$post = get_post( $args[0] );

		// Bail early if the post doesn't exist, or isn't a redirection: these
		// meta caps are only ours to map for the seopress_404 post type. Without
		// this guard, calling e.g. edit_redirection with an arbitrary post id
		// would map to that post type's own capabilities (edit_posts for the
		// author), which is not what the caller asked to authorize.
		if ( ! $post || 'seopress_404' !== $post->post_type ) {
			return $caps;
		}

		$post_type = get_post_type_object( $post->post_type );

		// Bail early if post type isn't registered.
		if ( ! $post_type ) {
			return $caps;
		}

		/* Set an empty array for the caps. */
		$caps = array();
	}

	/* If editing a redirection, assign the required capability. */
	if ( 'edit_redirection' === $cap ) {
		if ( $user_id == $post->post_author ) {
			$caps[] = $post_type->cap->edit_posts;
		} else {
			$caps[] = $post_type->cap->edit_others_posts;
		}
	} elseif ( 'delete_redirection' === $cap ) { // If deleting a redirection, assign the required capability.
		if ( $user_id == $post->post_author ) {
			$caps[] = $post_type->cap->delete_published_posts;
		} else {
			$caps[] = $post_type->cap->delete_others_posts;
		}
	} elseif ( 'read_redirection' === $cap ) {
		// Redirections are admin-only management data, never public content.
		// Reading one must not fall back to the bare `read` capability, which
		// every subscriber holds — otherwise, now that the post type is
		// registered in every context, any logged-in user could read a
		// redirection by object id in REST or on the front end. Require the
		// redirection read-private capability (administrators only) in all cases.
		$caps[] = $post_type->cap->read_private_posts;
	}

	/* Return the capabilities required by the user. */
	return $caps;
}

/**
 * Register SEOPress Custom Taxonomy Categories for Redirections
 *
 * @return void Void.
 */
function seopress_404_cat_fn() {
	$labels = array(
		'name'                       => _x( 'Categories', 'Taxonomy General Name', 'wp-seopress-pro' ),
		'singular_name'              => _x( 'Category', 'Taxonomy Singular Name', 'wp-seopress-pro' ),
		'menu_name'                  => esc_html__( 'Categories', 'wp-seopress-pro' ),
		'all_items'                  => esc_html__( 'All Categories', 'wp-seopress-pro' ),
		'parent_item'                => esc_html__( 'Parent Category', 'wp-seopress-pro' ),
		'parent_item_colon'          => esc_html__( 'Parent Category:', 'wp-seopress-pro' ),
		'new_item_name'              => esc_html__( 'New Category Name', 'wp-seopress-pro' ),
		'add_new_item'               => esc_html__( 'Add New Category', 'wp-seopress-pro' ),
		'edit_item'                  => esc_html__( 'Edit Category', 'wp-seopress-pro' ),
		'update_item'                => esc_html__( 'Update Category', 'wp-seopress-pro' ),
		'view_item'                  => esc_html__( 'View Category', 'wp-seopress-pro' ),
		'separate_items_with_commas' => esc_html__( 'Separate categories with commas', 'wp-seopress-pro' ),
		'add_or_remove_items'        => esc_html__( 'Add or remove categories', 'wp-seopress-pro' ),
		'choose_from_most_used'      => esc_html__( 'Choose from the most used', 'wp-seopress-pro' ),
		'popular_items'              => esc_html__( 'Popular Categories', 'wp-seopress-pro' ),
		'search_items'               => esc_html__( 'Search Categories', 'wp-seopress-pro' ),
		'not_found'                  => esc_html__( 'Not Found', 'wp-seopress-pro' ),
		'no_terms'                   => esc_html__( 'No items', 'wp-seopress-pro' ),
		'items_list'                 => esc_html__( 'Categories list', 'wp-seopress-pro' ),
		'items_list_navigation'      => esc_html__( 'Categories list navigation', 'wp-seopress-pro' ),
	);
	$args   = array(
		'labels'            => $labels,
		'hierarchical'      => true,
		'public'            => false,
		'show_ui'           => true,
		'show_admin_column' => true,
		'show_in_nav_menus' => false,
		'show_tagcloud'     => false,
		'rewrite'           => false,
		'show_in_rest'      => false,
	);
	register_taxonomy( 'seopress_404_cat', array( 'seopress_404' ), $args );
}

/**
 * Add custom buttons to SEOPress Redirections Custom Post Type
 *
 * @return void Void.
 */
function seopress_404_btn_cpt() {
	$screen = get_current_screen();
	global $pagenow;

	if ( 'seopress_404' === $screen->post_type && ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) ) { ?>
<script>
	jQuery(function() {
		jQuery("body.post-type-seopress_404 .wrap h1").after(
			'<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=seopress_404' ) ); ?>" id="seopress-all-redirects" class="page-title-action"><?php esc_html_e( 'All redirects', 'wp-seopress-pro' ); ?></a>'
		);
	});
</script>
		<?php
	} elseif ( 'seopress_404' === $screen->post_type ) {
		?>
<script>
jQuery(function() {

jQuery("body.post-type-seopress_404 .wrap h1 ~ a").after(
	'<a href="<?php echo esc_url( admin_url( 'admin.php?page=seopress-import-export#tab=tab_seopress_tool_redirects' ) ); ?>" id="seopress-import-redirects" class="page-title-action"><?php esc_html_e( 'Import / Export redirects', 'wp-seopress-pro' ); ?></a>'
);

jQuery("body.post-type-seopress_404 .wrap h1 ~ #seopress-import-redirects").after(
	'<a href="<?php echo esc_url( admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_404' ) ); ?>" id="seopress-redirections-settings" class="page-title-action"><?php esc_html_e( 'Settings', 'wp-seopress-pro' ); ?></a>'
);

jQuery("body.post-type-seopress_404 .wrap h1 ~ #seopress-redirections-settings").after(
	'<a href="<?php echo esc_url( admin_url( 'admin.php?page=seopress-import-export#tab=tab_seopress_tool_redirects' ) ); ?>" id="seopress-clean-404" class="page-title-action"><?php esc_html_e( 'Clean your 404', 'wp-seopress-pro' ); ?></a>'
);

jQuery("body.post-type-seopress_404 .wrap h1 ~ #seopress-clean-404").after(
	'<a href="<?php echo esc_url( admin_url( 'admin.php?page=seopress-import-export#tab=tab_seopress_tool_redirects' ) ); ?>" id="seopress-clean-redirects" class="page-title-action"><?php esc_html_e( 'Clean all entries', 'wp-seopress-pro' ); ?></a>'
);
});
</script>
		<?php
	}
}

/**
 * Add buttons to post type list if empty
 *
 * @return void Void.
 */
function seopress_404_render_blank_state() {

	?>
<div class="seopress-BlankState">

<h2 class="seopress-BlankState-message">
	<?php esc_html_e( 'Your redirections and 404 errors will appear here.', 'wp-seopress-pro' ); ?>
</h2>

<div class="seopress-BlankState-buttons">

<a class="seopress-BlankState-cta btn btnPrimary"
	href="<?php echo esc_url( admin_url( 'post-new.php?post_type=seopress_404' ) ); ?>"><?php esc_html_e( 'Create a redirect', 'wp-seopress-pro' ); ?></a>
<a class="seopress-BlankState-cta btn btnTertiary"
	href="<?php echo esc_url( admin_url( 'admin.php?page=seopress-import-export#tab=tab_seopress_tool_redirects' ) ); ?>"><?php esc_html_e( 'Start Import', 'wp-seopress-pro' ); ?></a>

</div>

</div>

	<?php
}

/**
 * Maybe render blank state
 *
 * @param string $which Which.
 * @return void Void.
 */
function seopress_404_maybe_render_blank_state( $which ) {
	global $post_type;

	if ( 'seopress_404' === $post_type && 'bottom' === $which ) {
		$counts = (array) wp_count_posts( $post_type );
		unset( $counts['auto-draft'] );
		$count = array_sum( $counts );

		if ( 0 < $count ) {
			return;
		}

		seopress_404_render_blank_state();

		echo '<style type="text/css">#posts-filter .wp-list-table, #posts-filter .tablenav.top, .tablenav.bottom .actions, .wrap .subsubsub  { display: none; } #posts-filter .tablenav.bottom { height: auto; } </style>';
	}
}

/**
 * Row actions links
 *
 * @param array  $actions Actions.
 * @param object $post Post.
 * @return array Actions.
 */
function seopress_404_row_actions( $actions, $post ) {
	if ( 'seopress_404' === get_post_type() ) {
		// WPML.
		add_filter( 'wpml_get_home_url', 'seopress_remove_wpml_home_url_filter', 20, 5 );

		if ( 'yes' == get_post_meta( get_the_ID(), '_seopress_redirections_enabled', true ) ) {
			$parse_url = wp_parse_url( get_home_url() );

			$home_url = get_home_url();
			if ( ! empty( $parse_url['scheme'] ) && ! empty( $parse_url['host'] ) ) {
				$home_url = $parse_url['scheme'] . '://' . $parse_url['host'];
			}

			if ( 'yes' !== get_post_meta( get_the_ID(), '_seopress_redirections_enabled_regex', true ) ) {
				$actions['seopress_404_test'] = "<a href='" . $home_url . '/' . esc_html( get_post( get_the_ID() )->post_title ) . "' target='_blank'>" . esc_html__( 'Test redirection', 'wp-seopress-pro' ) . '</a>';
			}
		}

		// WPML.
		remove_filter( 'wpml_get_home_url', 'seopress_remove_wpml_home_url_filter', 20 );
	}

	return $actions;
}

/**
 * Filters view
 *
 * @param array $views Views.
 *
 * @return void Void.
 */
function seopress_404_filter_views_cpt( $views ) {

	$current_view = '';

	if ( isset( $_GET['post_status'] ) ) {
		$current_view = sanitize_text_field( wp_unslash( $_GET['post_status'] ) );
	}

	$views = array(
		'redirects'  => array(
			'href' => admin_url( 'edit.php?post_type=seopress_404&post_status=redirects' ),
			'i18n' => __( 'Redirects', 'wp-seopress-pro' ),
		),
		'404'        => array(
			'href' => admin_url( 'edit.php?post_type=seopress_404&action=-1&m=0&redirect-cat=0&redirection-type=404&redirection-enabled&filter_action=Filter&paged=1&action2=-1&post_status=404' ),
			'i18n' => __( '404 errors', 'wp-seopress-pro' ),
		),
		'all'        => array(
			'href'      => admin_url( 'edit.php?post_type=seopress_404&post_status=all' ),
			'i18n'      => __( 'All', 'wp-seopress-pro' ),
			'sub_links' => array(
				0 => array(
					'href' => admin_url( 'edit.php?post_status=pending&post_type=seopress_404' ),
					'i18n' => __( 'Pending', 'wp-seopress-pro' ),
				),
				1 => array(
					'href' => admin_url( 'edit.php?post_status=draft&post_type=seopress_404' ),
					'i18n' => __( 'Draft', 'wp-seopress-pro' ),
				),
				2 => array(
					'href' => admin_url( 'edit.php?post_status=trash&post_type=seopress_404' ),
					'i18n' => __( 'Trash', 'wp-seopress-pro' ),
				),
			),
		),
		'categories' => array(
			'href' => admin_url( 'edit-tags.php?taxonomy=seopress_404_cat&post_type=seopress_404' ),
			'i18n' => esc_html__( 'Categories', 'wp-seopress-pro' ),
		),
	);

	echo "<ul class='subsubsub'>\n";
	$count = count( $views );
	$i     = 1;
	foreach ( $views as $key => $view ) {
		$class = '';
		$aria  = '';
		if ( $key == $current_view ) {
			$class = 'current';
			$aria  = 'aria-current="page"';
		}
		?>
		<li class=<?php echo esc_attr( $key ); ?>>
			<a class="<?php echo esc_attr( $class ); ?>" <?php echo esc_attr( $aria ); ?> href="<?php echo esc_url( $view['href'] ); ?>">
				<?php echo esc_html( $view['i18n'] ); ?>
			</a>
			<?php
			if ( ! empty( $view['sub_links'] ) ) {
				$count_sub = count( $view['sub_links'] );
				$i_sub     = 1;
				echo '(';
				foreach ( $view['sub_links'] as $_key => $_value ) {
					?>
					<a class="<?php echo esc_attr( $class ); ?>" <?php echo esc_attr( $aria ); ?> href="<?php echo esc_url( $_value['href'] ); ?>">
						<?php echo esc_html( $_value['i18n'] ); ?>
					</a>
					<?php
					if ( $count_sub !== $i_sub ) {
						echo ' - ';
					}
					++$i_sub;
				}
				echo ')';
			}
			if ( $count !== $i ) {
				echo ' |';
			}
			?>
		</li>
		<?php
		++$i;
	}

	echo '</ul>';
}

/**
 * Filters CPT
 *
 * @return void Void.
 */
function seopress_404_filters_cpt() {
	global $typenow;

	if ( 'seopress_404' === $typenow ) {
		$redirections_type    = array( '301', '302', '307', '404', '410', '451' );
		$redirections_enabled = array(
			'yes' => 'Enabled',
			'no'  => 'Disabled',
		);

		echo "<select name='redirection-type' id='redirection-type' class='postform'>";
		echo "<option value=''>" . esc_html__( 'Show All', 'wp-seopress-pro' ) . '</option>';
		foreach ( $redirections_type as $type ) {
			$selected = ( isset( $_GET['redirection-type'] ) && sanitize_text_field( wp_unslash( $_GET['redirection-type'] ) ) === $type ) ? ' selected="selected"' : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<option value="' . esc_attr( $type ) . '"' . $selected . '>' . esc_html( $type ) . '</option>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</select>';

		echo "<select name='redirection-enabled' id='redirection-enabled' class='postform'>";
		echo "<option value=''>" . esc_html__( 'All status', 'wp-seopress-pro' ) . '</option>';
		foreach ( $redirections_enabled as $enabled => $value ) {
			$selected = ( isset( $_GET['redirection-enabled'] ) && sanitize_text_field( wp_unslash( $_GET['redirection-enabled'] ) ) === $enabled ) ? ' selected="selected"' : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<option value="' . esc_attr( $enabled ) . '"' . $selected . '>' . esc_html( $value ) . '</option>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</select>';

		$categories = get_terms(
			array(
				'taxonomy'   => 'seopress_404_cat',
				'hide_empty' => false,
			)
		);

		if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
			$current_bulk_cat = isset( $_GET['bulk_cat_id'] ) ? absint( $_GET['bulk_cat_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<label class="screen-reader-text" for="bulk_cat_id">' . esc_html__( 'Filter/assign category', 'wp-seopress-pro' ) . '</label>';
			echo "<select name='bulk_cat_id' id='bulk_cat_id' class='postform'>";
			echo "<option value='0'>" . esc_html__( 'All categories', 'wp-seopress-pro' ) . '</option>';
			foreach ( $categories as $cat ) {
				$selected = ( $current_bulk_cat === (int) $cat->term_id ) ? ' selected="selected"' : '';
				echo '<option value="' . esc_attr( $cat->term_id ) . '"' . $selected . '>' . esc_html( $cat->name ) . '</option>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</select>';
		}
	}
}

/**
 * Filters action
 *
 * @param object $query Query.
 * @return void Void.
 */
function seopress_404_filters_action( $query ) {
	global $pagenow;

	// Only run on admin pages.
	if ( ! is_admin() ) {
		return;
	}

	// Only run on edit.php pages.
	if ( 'edit.php' !== $pagenow ) {
		return;
	}

	// Only run when we're specifically on the seopress_404 post type.
	$current_page = isset( $_GET['post_type'] ) ? $_GET['post_type'] : '';
	if ( 'seopress_404' !== $current_page ) {
		return;
	}

	// Additional safety check: ensure we're in the main query for the admin list.
	if ( ! $query->is_main_query() || ! $query->is_admin ) {
		return;
	}

	if ( ! isset( $_GET['post_status'] ) && ! isset( $_GET['s'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// Carry any active filter params so they are not lost after the redirect.
		$query_args = array(
			'post_type'   => 'seopress_404',
			'post_status' => 'redirects',
		);
		if ( ! empty( $_GET['bulk_cat_id'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query_args['bulk_cat_id'] = absint( $_GET['bulk_cat_id'] ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! empty( $_GET['redirection-type'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query_args['redirection-type'] = sanitize_text_field( wp_unslash( $_GET['redirection-type'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! empty( $_GET['redirection-enabled'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query_args['redirection-enabled'] = sanitize_text_field( wp_unslash( $_GET['redirection-enabled'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		wp_safe_redirect( add_query_arg( $query_args, admin_url( 'edit.php' ) ), 301 );
		exit();
	}

	if ( isset( $_GET['post_status'] ) && 'redirects' == $_GET['post_status'] ) {
		$query->query_vars['meta_query'] = array(
			array(
				'key'     => '_seopress_redirections_type',
				'value'   => null,
				'compare' => '!=',
			),
		);
	}

	if ( isset( $_GET['bulk_cat_id'] ) && absint( $_GET['bulk_cat_id'] ) > 0 ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tax_query_args = array(
			array(
				'taxonomy' => 'seopress_404_cat',
				'field'    => 'term_id',
				'terms'    => absint( $_GET['bulk_cat_id'] ), //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			),
		);

		// WP_Tax_Query is built inside parse_query() before this hook fires,
		// so query_vars['tax_query'] is never re-read by get_posts().
		// We must assign the WP_Tax_Query object directly.
		$query->tax_query               = new \WP_Tax_Query( $tax_query_args ); //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		$query->query_vars['tax_query'] = $tax_query_args; //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	}

	if ( ( isset( $_GET['bulk_cat_id'] ) && //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		absint( $_GET['bulk_cat_id'] ) > 0 && isset( $_GET['redirection-type'] ) && //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'' != $_GET['redirection-type'] && isset( $_GET['redirection-enabled'] ) && '' != $_GET['redirection-enabled'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirection_type    = $_GET['redirection-type'];
		$redirection_enabled = $_GET['redirection-enabled'];

		$query->query_vars['meta_relation'] = 'AND';
		if ( 'no' == $_GET['redirection-enabled'] ) {
			$compare = 'NOT EXISTS';
		} else {
			$compare = '=';
		}
		$query->query_vars['meta_query'] = array(
			'relation' => 'AND',
			array(
				'key'     => '_seopress_redirections_type',
				'value'   => $redirection_type,
				'compare' => '=',
			),
			array(
				'key'     => '_seopress_redirections_enabled',
				'value'   => $redirection_enabled,
				'compare' => $compare,
			),
		);
	}

	if ( isset( $_GET['redirection-type'] ) && '' != $_GET['redirection-type'] ) {
		$redirection_type = $_GET['redirection-type'];

		$query->query_vars['meta_query'] = array(
			'relation' => 'AND',
			array(
				'key'     => '_seopress_redirections_type',
				'value'   => $redirection_type,
				'compare' => '=',
			),
		);

		if ( '404' == $redirection_type ) {
			$query->query_vars['meta_query'] = array(
				'relation' => 'AND',
				array(
					'key'     => '_seopress_redirections_type',
					'compare' => 'NOT EXISTS',
				),
			);
		}
	}
	if ( isset( $_GET['redirection-enabled'] ) && '' != $_GET['redirection-enabled'] ) {
		$redirection_enabled             = $_GET['redirection-enabled'];
		$query->query_vars['meta_key']   = '_seopress_redirections_enabled';
		$query->query_vars['meta_value'] = $redirection_enabled;
		if ( 'no' == $redirection_enabled ) {
			$query->query_vars['meta_compare'] = 'NOT EXISTS';
		} else {
			$query->query_vars['meta_compare'] = '=';
		}
	}
}

/**
 * Bulk actions
 *
 * @param array $bulk_actions Bulk actions.
 * @return array Bulk actions.
 */
function seopress_bulk_actions_enable( $bulk_actions ) {
	$bulk_actions['seopress_enable'] = esc_html__( 'Enable redirection', 'wp-seopress-pro' );

	return $bulk_actions;
}

/**
 * Bulk action enable handler
 *
 * @param string $redirect_to Redirect to.
 * @param string $doaction Do action.
 * @param array  $post_ids Post IDs.
 * @return string Redirect to.
 */
function seopress_bulk_action_enable_handler( $redirect_to, $doaction, $post_ids ) {
	if ( 'seopress_enable' !== $doaction ) {
		return $redirect_to;
	}
	foreach ( $post_ids as $post_id ) {
		// Perform action for each post.
		update_post_meta( $post_id, '_seopress_redirections_enabled', 'yes' );
	}
	$redirect_to = add_query_arg( 'bulk_enable_posts', count( $post_ids ), $redirect_to );

	return $redirect_to;
}

/**
 * Bulk action enable admin notice
 *
 * @return void Void.
 */
function seopress_bulk_action_enable_admin_notice() {
	if ( ! empty( $_REQUEST['bulk_enable_posts'] ) ) {
		$enable_count = $_REQUEST['bulk_enable_posts'];
		/* translators: %s number of redirections enabled */
		echo wp_kses_post( sprintf( '<div id="message" class="updated fade"><p>' . _n( '%s redirection enabled.', '%s redirections enabled.', $enable_count, 'wp-seopress-pro' ) . '</p></div>', intval( $enable_count ) ) );
	}
}

/**
 * Bulk actions disable
 *
 * @param array $bulk_actions Bulk actions.
 * @return array Bulk actions.
 */
function seopress_bulk_actions_disable( $bulk_actions ) {
	$bulk_actions['seopress_disable'] = esc_html__( 'Disable redirection', 'wp-seopress-pro' );

	return $bulk_actions;
}

/**
 * Bulk action disable handler
 *
 * @param string $redirect_to Redirect to.
 * @param string $doaction Do action.
 * @param array  $post_ids Post IDs.
 * @return string Redirect to.
 */
function seopress_bulk_action_disable_handler( $redirect_to, $doaction, $post_ids ) {
	if ( 'seopress_disable' !== $doaction ) {
		return $redirect_to;
	}
	foreach ( $post_ids as $post_id ) {
		// Perform action for each post.
		update_post_meta( $post_id, '_seopress_redirections_enabled', '' );
	}
	$redirect_to = add_query_arg( 'bulk_disable_posts', count( $post_ids ), $redirect_to );

	return $redirect_to;
}

/**
 * Bulk action disable admin notice
 *
 * @return void Void.
 */
function seopress_bulk_action_disable_admin_notice() {
	if ( ! empty( $_REQUEST['bulk_disable_posts'] ) ) {
		$disable_count = $_REQUEST['bulk_disable_posts'];
		/* translators: %s number of redirections disabled */
		echo wp_kses_post( sprintf( '<div id="message" class="updated fade"><p>' . _n( '%s redirection disabled.', '%s redirections disabled.', $disable_count, 'wp-seopress-pro' ) . '</p></div>', intval( $disable_count ) ) );
	}
}

/**
 * Bulk actions enable regex
 *
 * @param array $bulk_actions Bulk actions.
 * @return array Bulk actions.
 */
function seopress_bulk_actions_enable_regex( $bulk_actions ) {
	$bulk_actions['seopress_enable_regex'] = esc_html__( 'Enable regex', 'wp-seopress-pro' );

	return $bulk_actions;
}

/**
 * Bulk action enable regex handler
 *
 * @param string $redirect_to Redirect to.
 * @param string $doaction Do action.
 * @param array  $post_ids Post IDs.
 * @return string Redirect to.
 */
function seopress_bulk_action_enable_regex_handler( $redirect_to, $doaction, $post_ids ) {
	if ( 'seopress_enable_regex' !== $doaction ) {
		return $redirect_to;
	}
	foreach ( $post_ids as $post_id ) {
		// Perform action for each post.
		update_post_meta( $post_id, '_seopress_redirections_enabled_regex', 'yes' );
	}
	$redirect_to = add_query_arg( 'bulk_enable_regex_posts', count( $post_ids ), $redirect_to );

	return $redirect_to;
}

/**
 * Bulk action enable regex admin notice
 *
 * @return void Void.
 */
function seopress_bulk_action_enable_regex_admin_notice() {
	if ( ! empty( $_REQUEST['bulk_enable_regex_posts'] ) ) {
		$enable_regex_count = $_REQUEST['bulk_enable_regex_posts'];
		/* translators: %s number of redirections with regex enabled */
		echo wp_kses_post( sprintf( '<div id="message" class="updated fade"><p>' . _n( '%s redirection with regex enabled.', '%s redirections with regex enabled.', $enable_regex_count, 'wp-seopress-pro' ) . '</p></div>', intval( $enable_regex_count ) ) );
	}
}

/**
 * Bulk actions disable regex
 *
 * @param array $bulk_actions Bulk actions.
 * @return array Bulk actions.
 */
function seopress_bulk_actions_disable_regex( $bulk_actions ) {
	$bulk_actions['seopress_disable_regex'] = esc_html__( 'Disable regex', 'wp-seopress-pro' );

	return $bulk_actions;
}

/**
 * Bulk action disable regex handler
 *
 * @param string $redirect_to Redirect to.
 * @param string $doaction Do action.
 * @param array  $post_ids Post IDs.
 * @return string Redirect to.
 */
function seopress_bulk_action_disable_regex_handler( $redirect_to, $doaction, $post_ids ) {
	if ( 'seopress_disable_regex' !== $doaction ) {
		return $redirect_to;
	}
	foreach ( $post_ids as $post_id ) {
		// Perform action for each post.
		update_post_meta( $post_id, '_seopress_redirections_enabled_regex', '' );
	}
	$redirect_to = add_query_arg( 'bulk_disable_regex_posts', count( $post_ids ), $redirect_to );

	return $redirect_to;
}

/**
 * Bulk action disable regex admin notice
 *
 * @return void Void.
 */
function seopress_bulk_action_disable_regex_admin_notice() {
	if ( ! empty( $_REQUEST['bulk_disable_regex_posts'] ) ) {
		$disable_count = $_REQUEST['bulk_disable_regex_posts'];
		/* translators: %s number of redirections with regex disabled */
		echo wp_kses_post( sprintf( '<div id="message" class="updated fade"><p>' . _n( '%s redirection with regex disabled.', '%s redirections with regex disabled.', $disable_count, 'wp-seopress-pro' ) . '</p></div>', intval( $disable_count ) ) );
	}
}

/**
 * Bulk actions redirect 301
 *
 * @param array $bulk_actions Bulk actions.
 * @return array Bulk actions.
 */
function seopress_bulk_actions_redirect_301( $bulk_actions ) {
	$bulk_actions['seopress_redirect_301'] = esc_html__( 'Mark as 301', 'wp-seopress-pro' );

	return $bulk_actions;
}

/**
 * Bulk action redirect 301 handler
 *
 * @param string $redirect_to Redirect to.
 * @param string $doaction Do action.
 * @param array  $post_ids Post IDs.
 * @return string Redirect to.
 */
function seopress_bulk_action_redirect_301_handler( $redirect_to, $doaction, $post_ids ) {
	if ( 'seopress_redirect_301' !== $doaction ) {
		return $redirect_to;
	}
	foreach ( $post_ids as $post_id ) {
		// Perform action for each post.
		update_post_meta( $post_id, '_seopress_redirections_type', '301' );
	}
	$redirect_to = add_query_arg( 'bulk_301_redirects_posts', count( $post_ids ), $redirect_to );

	return $redirect_to;
}

/**
 * Bulk action redirect 301 admin notice
 *
 * @return void Void.
 */
function seopress_bulk_action_redirect_301_admin_notice() {
	if ( ! empty( $_REQUEST['bulk_301_redirects_posts'] ) ) {
		$count_301 = $_REQUEST['bulk_301_redirects_posts'];
		/* translators: %s number of redirections marked as 301 */
		echo wp_kses_post( sprintf( '<div id="message" class="updated fade"><p>' . _n( '%s marked as 301 redirect.', '%s marked as 301 redirect.', $count_301, 'wp-seopress-pro' ) . '</p></div>', intval( $count_301 ) ) );
	}
}

/**
 * Bulk actions redirect 302
 *
 * @param array $bulk_actions Bulk actions.
 * @return array Bulk actions.
 */
function seopress_bulk_actions_redirect_302( $bulk_actions ) {
	$bulk_actions['seopress_redirect_302'] = esc_html__( 'Mark as 302', 'wp-seopress-pro' );

	return $bulk_actions;
}

/**
 * Bulk action redirect 302 handler
 *
 * @param string $redirect_to Redirect to.
 * @param string $doaction Do action.
 * @param array  $post_ids Post IDs.
 * @return string Redirect to.
 */
function seopress_bulk_action_redirect_302_handler( $redirect_to, $doaction, $post_ids ) {
	if ( 'seopress_redirect_302' !== $doaction ) {
		return $redirect_to;
	}
	foreach ( $post_ids as $post_id ) {
		// Perform action for each post.
		update_post_meta( $post_id, '_seopress_redirections_type', '302' );
	}
	$redirect_to = add_query_arg( 'bulk_302_redirects_posts', count( $post_ids ), $redirect_to );

	return $redirect_to;
}

/**
 * Bulk action redirect 302 admin notice
 *
 * @return void Void.
 */
function seopress_bulk_action_redirect_302_admin_notice() {
	if ( ! empty( $_REQUEST['bulk_302_redirects_posts'] ) ) {
		$count_302 = $_REQUEST['bulk_302_redirects_posts'];
		/* translators: %s number of redirections marked as 302 */
		echo wp_kses_post( sprintf( '<div id="message" class="updated fade"><p>' . _n( '%s marked as 302 redirect.', '%s marked as 302 redirect.', $count_302, 'wp-seopress-pro' ) . '</p></div>', intval( $count_302 ) ) );
	}
}

/**
 * Bulk actions redirect 307
 *
 * @param array $bulk_actions Bulk actions.
 * @return array Bulk actions.
 */
function seopress_bulk_actions_redirect_307( $bulk_actions ) {
	$bulk_actions['seopress_redirect_307'] = esc_html__( 'Mark as 307', 'wp-seopress-pro' );

	return $bulk_actions;
}

/**
 * Bulk action redirect 307 handler
 *
 * @param string $redirect_to Redirect to.
 * @param string $doaction Do action.
 * @param array  $post_ids Post IDs.
 * @return string Redirect to.
 */
function seopress_bulk_action_redirect_307_handler( $redirect_to, $doaction, $post_ids ) {
	if ( 'seopress_redirect_307' !== $doaction ) {
		return $redirect_to;
	}
	foreach ( $post_ids as $post_id ) {
		// Perform action for each post.
		update_post_meta( $post_id, '_seopress_redirections_type', '307' );
	}
	$redirect_to = add_query_arg( 'bulk_307_redirects_posts', count( $post_ids ), $redirect_to );

	return $redirect_to;
}

/**
 * Bulk action redirect 307 admin notice
 *
 * @return void Void.
 */
function seopress_bulk_action_redirect_307_admin_notice() {
	if ( ! empty( $_REQUEST['bulk_307_redirects_posts'] ) ) {
		$count_307 = $_REQUEST['bulk_307_redirects_posts'];
		/* translators: %s number of redirections marked as 307 */
		echo wp_kses_post( sprintf( '<div id="message" class="updated fade"><p>' . _n( '%s marked as 307 redirect.', '%s marked as 307 redirect.', $count_307, 'wp-seopress-pro' ) . '</p></div>', intval( $count_307 ) ) );
	}
}

/**
 * Bulk actions redirect 410
 *
 * @param array $bulk_actions Bulk actions.
 * @return array Bulk actions.
 */
function seopress_bulk_actions_redirect_410( $bulk_actions ) {
	$bulk_actions['seopress_redirect_410'] = esc_html__( 'Mark as 410', 'wp-seopress-pro' );

	return $bulk_actions;
}

/**
 * Bulk action redirect 410 handler
 *
 * @param string $redirect_to Redirect to.
 * @param string $doaction Do action.
 * @param array  $post_ids Post IDs.
 * @return string Redirect to.
 */
function seopress_bulk_action_redirect_410_handler( $redirect_to, $doaction, $post_ids ) {
	if ( 'seopress_redirect_410' !== $doaction ) {
		return $redirect_to;
	}
	foreach ( $post_ids as $post_id ) {
		// Perform action for each post.
		update_post_meta( $post_id, '_seopress_redirections_type', '410' );
	}
	$redirect_to = add_query_arg( 'bulk_410_redirects_posts', count( $post_ids ), $redirect_to );

	return $redirect_to;
}

/**
 * Bulk action redirect 410 admin notice
 *
 * @return void Void.
 */
function seopress_bulk_action_redirect_410_admin_notice() {
	if ( ! empty( $_REQUEST['bulk_410_redirects_posts'] ) ) {
		$count_410 = $_REQUEST['bulk_410_redirects_posts'];
		/* translators: %s number of redirections marked as 410 */
		echo wp_kses_post( sprintf( '<div id="message" class="updated fade"><p>' . _n( '%s marked as 410 redirect.', '%s marked as 410 redirect.', $count_410, 'wp-seopress-pro' ) . '</p></div>', intval( $count_410 ) ) );
	}
}

/**
 * Bulk actions redirect 451
 *
 * @param array $bulk_actions Bulk actions.
 * @return array Bulk actions.
 */
function seopress_bulk_actions_redirect_451( $bulk_actions ) {
	$bulk_actions['seopress_redirect_451'] = esc_html__( 'Mark as 451', 'wp-seopress-pro' );

	return $bulk_actions;
}

/**
 * Bulk action redirect 451 handler
 *
 * @param string $redirect_to Redirect to.
 * @param string $doaction Do action.
 * @param array  $post_ids Post IDs.
 * @return string Redirect to.
 */
function seopress_bulk_action_redirect_451_handler( $redirect_to, $doaction, $post_ids ) {
	if ( 'seopress_redirect_451' !== $doaction ) {
		return $redirect_to;
	}
	foreach ( $post_ids as $post_id ) {
		// Perform action for each post.
		update_post_meta( $post_id, '_seopress_redirections_type', '451' );
	}
	$redirect_to = add_query_arg( 'bulk_451_redirects_posts', count( $post_ids ), $redirect_to );

	return $redirect_to;
}

/**
 * Bulk action redirect 451 admin notice
 *
 * @return void Void.
 */
function seopress_bulk_action_redirect_451_admin_notice() {
	if ( ! empty( $_REQUEST['bulk_451_redirects_posts'] ) ) {
		$count_451 = $_REQUEST['bulk_451_redirects_posts'];
		/* translators: %s number of redirections marked as 451 */
		echo wp_kses_post( sprintf( '<div id="message" class="updated fade"><p>' . _n( '%s marked as 451 redirect.', '%s marked as 451 redirect.', $count_451, 'wp-seopress-pro' ) . '</p></div>', intval( $count_451 ) ) );
	}
}

/**
 * Optimized bulk delete for seopress_404 post type.
 *
 * Intercepts WordPress bulk delete before it processes posts one-by-one,
 * and uses batched direct SQL to avoid timeouts on large datasets.
 *
 * @return void
 */
function seopress_404_optimized_bulk_delete() {
	$screen = get_current_screen();
	if ( ! $screen || 'edit-seopress_404' !== $screen->id ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( empty( $_REQUEST['action'] ) && empty( $_REQUEST['action2'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action1 = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '-1';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action2 = isset( $_REQUEST['action2'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action2'] ) ) : '-1';
	$action  = ( '-1' !== $action1 ) ? $action1 : $action2;

	if ( 'delete' !== $action ) {
		return;
	}

	// Verify nonce before reading post IDs or taking any action.
	check_admin_referer( 'bulk-posts' );

	$post_ids = isset( $_REQUEST['post'] ) ? array_map( 'intval', (array) $_REQUEST['post'] ) : array();

	if ( count( $post_ids ) < 20 ) {
		return; // Let WordPress handle small batches normally.
	}

	if ( ! current_user_can( seopress_capability( 'manage_options', 'redirections' ) ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'wp-seopress-pro' ) );
	}

	global $wpdb;

	// Process in batches of 100 to avoid query size limits.
	$batch_size      = 100;
	$deleted         = 0;
	$total_post_ids  = count( $post_ids );
	$affected_tt_ids = array(); // Collects term_taxonomy_ids to recount after all batches.

	for ( $i = 0; $i < $total_post_ids; $i += $batch_size ) {
		$batch        = array_slice( $post_ids, $i, $batch_size );
		$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );

		// Verify all posts are seopress_404 type before deleting.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$valid_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_type = 'seopress_404'",
				$batch
			)
		);

		if ( empty( $valid_ids ) ) {
			continue;
		}

		$valid_placeholders = implode( ',', array_fill( 0, count( $valid_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($valid_placeholders)",
				$valid_ids
			)
		);

		// Collect affected term_taxonomy_ids before removing relationships so counts can be updated after.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$batch_tt_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id IN ($valid_placeholders)",
				$valid_ids
			)
		);
		if ( ! empty( $batch_tt_ids ) ) {
			$affected_tt_ids = array_merge( $affected_tt_ids, $batch_tt_ids );
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($valid_placeholders)",
				$valid_ids
			)
		);

		// Clean up comments and commentmeta to match wp_delete_post() behaviour.
		$comment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ($valid_placeholders)",
				$valid_ids
			)
		);

		if ( ! empty( $comment_ids ) ) {
			$comment_placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ($comment_placeholders)",
					$comment_ids
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ($valid_placeholders)",
					$valid_ids
				)
			);
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->posts} WHERE ID IN ($valid_placeholders) AND post_type = 'seopress_404'",
				$valid_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$deleted += count( $valid_ids );
	}

	// Update term counts for all affected terms grouped by taxonomy.
	if ( ! empty( $affected_tt_ids ) ) {
		$affected_tt_ids = array_unique( array_map( 'intval', $affected_tt_ids ) );
		$tt_placeholders = implode( ',', array_fill( 0, count( $affected_tt_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$taxonomy_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_taxonomy_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ($tt_placeholders)",
				$affected_tt_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$by_taxonomy = array();
		foreach ( $taxonomy_rows as $row ) {
			$by_taxonomy[ $row->taxonomy ][] = (int) $row->term_taxonomy_id;
		}
		foreach ( $by_taxonomy as $taxonomy => $tt_ids ) {
			wp_update_term_count_now( $tt_ids, $taxonomy );
		}
	}

	// Clean per-post caches only — avoids a site-wide object cache flush.
	foreach ( $post_ids as $id ) {
		clean_post_cache( $id );
	}

	// Redirect back with success message.
	$redirect = add_query_arg(
		array(
			'post_type'    => 'seopress_404',
			'bulk_deleted' => $deleted,
		),
		admin_url( 'edit.php' )
	);

	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Admin notice for optimized bulk delete.
 *
 * @return void
 */
function seopress_404_bulk_delete_admin_notice() {
	if ( empty( $_GET['bulk_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$count = intval( $_GET['bulk_deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	echo wp_kses_post(
		sprintf(
			'<div id="message" class="updated fade"><p>' .
			/* translators: %s: number of redirections deleted */
			_n( '%s redirection permanently deleted.', '%s redirections permanently deleted.', $count, 'wp-seopress-pro' ) .
			'</p></div>',
			$count
		)
	);
}

/**
 * Set title placeholder for Redirections Custom Post Type
 *
 * @param string $title Title.
 * @return string Title.
 */
function seopress_404_cpt_title( $title ) {
	if ( function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
		if ( $screen && 'seopress_404' === $screen->post_type ) {
			$title = esc_html__( 'Enter the old URL here without domain name', 'wp-seopress-pro' );
		}

		return $title;
	}
}

/**
 * Display help after title
 *
 * @return void Void.
 */
function seopress_301_after_title() {
	global $typenow;
	if ( isset( $typenow ) && 'seopress_404' === $typenow ) {
		?>
		<p>
			<?php echo wp_kses_post( __( 'Enter your <strong>relative</strong> URL above. Do not use anchors, they are not sent by your browser.', 'wp-seopress-pro' ) ); ?>
			<br>
			<?php echo wp_kses_post( __( 'e.g. <code>my-custom-permalink</code>. If you have a permalink structure like <code>/%category%/%postname%/</code>, make sure to include the categories: <code>category/sub-category/my-custom-permalink</code>.', 'wp-seopress-pro' ) ); ?>
		</p>
		<?php
	}
}

/**
 * Enqueue validation script for Redirections editor.
 *
 * @since 9.8
 *
 * @return void
 */
function seopress_redirections_enqueue_validation() {
	$screen = get_current_screen();

	if ( ! $screen || 'seopress_404' !== $screen->post_type || 'post' !== $screen->base ) {
		return;
	}

	wp_enqueue_script(
		'seopress-redirections-validation',
		plugins_url( 'assets/js/seopress-redirections-validation.js', dirname( dirname( __DIR__ ) ) ),
		array(),
		SEOPRESS_PRO_VERSION,
		true
	);

	wp_localize_script(
		'seopress-redirections-validation',
		'seopressRedirectionsValidation',
		array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'seopress_check_redirection_nonce' ),
			'postId'   => get_the_ID(),
			'siteHost' => wp_parse_url( home_url(), PHP_URL_HOST ),
			'i18n'    => array(
				'fullUrl'       => wp_kses_post( __( 'Enter a relative path (e.g. <code>/old-page</code>), not a full URL. The domain will be stripped automatically.', 'wp-seopress-pro' ) ),
				'spaces'        => wp_kses_post( __( 'URLs should not contain spaces. Use <code>%20</code> or <code>-</code> instead.', 'wp-seopress-pro' ) ),
				'anchor'        => wp_kses_post( __( 'Anchors (<code>#</code>) are not sent by browsers and will be ignored.', 'wp-seopress-pro' ) ),
				'loop'          => esc_html__( 'Origin and destination are identical. This will cause a redirect loop.', 'wp-seopress-pro' ),
				'duplicate'     => esc_html__( 'A redirection already exists for this URL.', 'wp-seopress-pro' ),
				'duplicateEdit' => esc_html__( 'Edit existing', 'wp-seopress-pro' ),
				'chain'         => esc_html__( 'Warning: this destination is already redirected.', 'wp-seopress-pro' ),
				'chainAdvice'   => esc_html__( 'Consider redirecting directly to the final destination.', 'wp-seopress-pro' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'seopress_redirections_enqueue_validation' );

/**
 * AJAX handler: check for duplicate origin and redirect chains.
 *
 * @since 9.8
 *
 * @return void
 */
function seopress_check_redirection() {
	check_ajax_referer( 'seopress_check_redirection_nonce' );

	if ( ! current_user_can( seopress_capability( 'manage_options', 'redirections' ) ) ) {
		wp_send_json_error( 'not_authorized' );
	}

	$origin  = isset( $_POST['origin'] ) ? sanitize_text_field( wp_unslash( $_POST['origin'] ) ) : '';
	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

	if ( empty( $origin ) ) {
		wp_send_json_success( array( 'duplicate' => null, 'chain' => null ) );
	}

	$result = array(
		'duplicate' => null,
		'chain'     => null,
	);

	// Check for duplicate origin.
	$existing = get_posts(
		array(
			'post_type'      => 'seopress_404',
			'post_status'    => 'any',
			'title'          => $origin,
			'posts_per_page' => 1,
			'post__not_in'   => $post_id ? array( $post_id ) : array(),
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $existing ) ) {
		$dup_id = $existing[0];
		$result['duplicate'] = array(
			'id'       => $dup_id,
			'edit_url' => esc_url( get_edit_post_link( $dup_id, 'raw' ) ),
		);
	}

	// Check for redirect chain: is the destination of the current post already an origin of another redirect?
	$destination = get_post_meta( $post_id, '_seopress_redirections_value', true );
	if ( ! empty( $destination ) ) {
		$dest_path = trim( ltrim( (string) wp_parse_url( $destination, PHP_URL_PATH ), '/' ) );

		// Same guard as the REST validator in `SEOPressPro\Actions\Api\Redirections`.
		// A destination pointing at the site root, or one whose path is only
		// whitespace, leaves nothing to match on: "/" is truthy but becomes an
		// empty string once the slash is stripped, and `WP_Query` answers an empty
		// title clause by dropping it, turning the lookup into "the first
		// seopress_404 post" and reporting an unrelated entry as a chain.
		if ( '' !== $dest_path ) {
			$chain_post = get_posts(
				array(
					'post_type'      => 'seopress_404',
					'post_status'    => 'any',
					'title'          => $dest_path,
					'posts_per_page' => 1,
					'post__not_in'   => $post_id ? array( $post_id ) : array(),
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $chain_post ) ) {
				$chain_id          = $chain_post[0];
				$chain_destination = get_post_meta( $chain_id, '_seopress_redirections_value', true );

				if ( ! empty( $chain_destination ) ) {
					$result['chain'] = array(
						'origin'      => sanitize_text_field( $dest_path ),
						'destination' => sanitize_text_field( $chain_destination ),
					);
				}
			}
		}
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_seopress_check_redirection', 'seopress_check_redirection' );

/**
 * Set messages for Redirections Custom Post Type
 *
 * @param array $messages Messages.
 * @return array Messages.
 */
function seopress_404_set_messages( $messages ) {
	global $post, $post_ID, $typenow;
	$post_type         = 'seopress_404';
	$seopress_404_test = '';

	if ( 'seopress_404' === $typenow ) {
		$obj      = get_post_type_object( $post_type );
		$singular = $obj->labels->singular_name;

		// WPML.
		add_filter( 'wpml_get_home_url', 'seopress_remove_wpml_home_url_filter', 20, 5 );

		if ( 'yes' == get_post_meta( get_the_ID(), '_seopress_redirections_enabled', true ) ) {
			$parse_url = wp_parse_url( get_home_url() );

			$home_url = get_home_url();
			if ( ! empty( $parse_url['scheme'] ) && ! empty( $parse_url['host'] ) ) {
				$home_url = $parse_url['scheme'] . '://' . $parse_url['host'];
			}

			if ( 'yes' !== get_post_meta( get_the_ID(), '_seopress_redirections_enabled_regex', true ) ) {
				$seopress_404_test = "<a href='" . $home_url . '/' . esc_html( get_post( get_the_ID() )->post_title ) . "' target='_blank'>" . esc_html__( 'Test redirection', 'wp-seopress-pro' ) . "</a><span class='dashicons dashicons-external'></span>";
			}
		}

		$messages[ $post_type ] = array(
			0  => '', // Unused. Messages start at index 1.
			1  => sprintf(
				/* translators: %1$s singular name of the post type, %2$s button to test the redirect */
				esc_html__( '%1$s updated. %2$s', 'wp-seopress-pro' ),
				esc_html( $singular ),
				$seopress_404_test
			),
			2  => esc_html__( 'Custom field updated.', 'wp-seopress-pro' ),
			3  => esc_html__( 'Custom field deleted.', 'wp-seopress-pro' ),
			4  => sprintf(
				/* translators: %1$s singular name of the post type, %2$s button to test the redirect */
				esc_html__( '%1$s updated. %2$s', 'wp-seopress-pro' ),
				esc_html( $singular ),
				$seopress_404_test
			),
			5  => isset( $_GET['revision'] ) ? sprintf(
				/* translators: %1$s singular name of the post type, %2$s title of the revision */
				esc_html__( '%1$s restored to revision from %2$s', 'wp-seopress-pro' ),
				esc_html( $singular ),
				wp_post_revision_title( (int) $_GET['revision'], false )
			) : false,
			6  => sprintf(
				/* translators: %1$s singular name of the post type, %2$s button to test the redirect */
				esc_html__( '%1$s published. %2$s', 'wp-seopress-pro' ),
				esc_html( $singular ),
				$seopress_404_test
			),
			7  => esc_html__( 'Redirection saved.', 'wp-seopress-pro' ),
			8  => sprintf(
				/* translators: %s singular name of the post type */
				esc_html__( '%s submitted.', 'wp-seopress-pro' ),
				esc_html( $singular )
			),
			9  => sprintf(
				/* translators: %1$s singular name of the post type, %2$s scheduled date */
				esc_html__( '%1$s scheduled for: <strong>%2$s</strong>. ', 'wp-seopress-pro' ),
				esc_html( $singular ),
				date_i18n( esc_html__( 'M j, Y @ G:i', 'wp-seopress-pro' ), strtotime( $post->post_date ) ),
				esc_url( get_permalink( $post_ID ) )
			),
			10 => sprintf(
				/* translators: %s singular name of the post type */
				esc_html__( '%s draft updated.', 'wp-seopress-pro' ),
				esc_html( $singular )
			),
		);

		return $messages;
	} else {
		return $messages;
	}
}

/**
 * Set messages for Redirections Custom Post Type list
 *
 * @param array $bulk_messages Bulk messages.
 * @param array $bulk_counts Bulk counts.
 * @return array Bulk messages.
 */
function seopress_404_set_messages_list( $bulk_messages, $bulk_counts ) {
	$bulk_messages['seopress_404'] = array(
		'updated'   => /* translators: %d number of redirections */ _n( '%d redirection updated.', '%d redirections updated.', $bulk_counts['updated'], 'wp-seopress-pro' ),
		'locked'    => /* translators: %d number of redirections */ _n( '%d redirection not updated, somebody is editing it.', '%d redirections not updated, somebody is editing them.', $bulk_counts['locked'], 'wp-seopress-pro' ),
		'deleted'   => /* translators: %d number of redirections */ _n( '%d redirection permanently deleted.', '%d redirections permanently deleted.', $bulk_counts['deleted'], 'wp-seopress-pro' ),
		'trashed'   => /* translators: %d number of redirections */ _n( '%d redirection moved to the Trash.', '%d redirections moved to the Trash.', $bulk_counts['trashed'], 'wp-seopress-pro' ),
		'untrashed' => /* translators: %d number of redirections */ _n( '%d redirection restored from the Trash.', '%d redirections restored from the Trash.', $bulk_counts['untrashed'], 'wp-seopress-pro' ),
	);

	return $bulk_messages;
}
/**
 * Items per page
 *
 * @param int $per_page Per page.
 * @return int Per page.
 */
function seopress_404_items_per_page( $per_page ) {
	// Check if user has already defined the number of items.
	$user_id = get_current_user_id();
	if ( isset( $user_id ) && get_user_meta( $user_id, 'edit_seopress_404_per_page', true ) ) {
		return get_user_meta( $user_id, 'edit_seopress_404_per_page', true );
	}

	$per_page = 100;
	return $per_page;
}

/**
 * Columns for SEOPress Redirections Custom Post Type
 *
 * @param array $columns Columns.
 * @return array Columns.
 */
function seopress_404_count_columns( $columns ) {
	unset( $columns['title'] );
	unset( $columns['date'] );
	unset( $columns['taxonomy-seopress_404_cat'] );

	$columns['seopress_404_redirect_enable']       = esc_html__( 'On?', 'wp-seopress-pro' );
	$columns['title']                              = esc_html__( 'Origin URL', 'wp-seopress-pro' );
	$columns['seopress_404_redirect_value']        = esc_html__( 'Destination URL', 'wp-seopress-pro' );
	$columns['seopress_404_redirect_type']         = esc_html__( 'Type', 'wp-seopress-pro' );
	$columns['seopress_404']                       = esc_html__( 'Hits', 'wp-seopress-pro' );
	$columns['seopress_404_redirect_regex_enable'] = esc_html__( 'Regex?', 'wp-seopress-pro' );
	$columns['seopress_404_date']                  = esc_html__( 'Date', 'wp-seopress-pro' );
	$columns['taxonomy-seopress_404_cat']          = esc_html__( 'Categories', 'wp-seopress-pro' );
	$columns['seopress_404_redirect_date_request'] = esc_html__( 'Last time loaded', 'wp-seopress-pro' );
	$columns['seopress_404_redirect_ua']           = esc_html__( 'User agent', 'wp-seopress-pro' );
	$columns['seopress_404_redirect_referer']      = esc_html__( 'Full Origin', 'wp-seopress-pro' );

	return $columns;
}

/**
 * Display column for SEOPress Redirections Custom Post Type
 *
 * @param string $column Column.
 * @param int    $post_id Post ID.
 * @return void Void.
 */
function seopress_404_count_display_column( $column, $post_id ) {
	if ( 'seopress_404_date' === $column ) {
		esc_html( the_date( '', '', '', true ) );
	}
	if ( 'seopress_404' === $column ) {
		echo absint( get_post_meta( $post_id, 'seopress_404_count', true ) );
	}
	if ( 'seopress_404_redirect_enable' === $column ) {
		if ( 'yes' == get_post_meta( $post_id, '_seopress_redirections_enabled', true ) ) {
			echo '<span class="dashicons dashicons-yes-alt"></span>';
		}
	}
	if ( 'seopress_404_redirect_regex_enable' === $column ) {
		if ( 'yes' == get_post_meta( $post_id, '_seopress_redirections_enabled_regex', true ) ) {
			echo '<span class="dashicons dashicons-yes"></span>';
		}
	}
	if ( 'seopress_404_redirect_type' === $column ) {
		$seopress_redirections_type = get_post_meta( $post_id, '_seopress_redirections_type', true );
		switch ( $seopress_redirections_type ) {

			case '301':
				echo '<span class="seopress_redirection_301 seopress_redirection_status" title="' . esc_html__( 'Moved permanently', 'wp-seopress-pro' ) . '">' . absint( $seopress_redirections_type ) . '</span>';
				break;

			case '302':
				echo '<span class="seopress_redirection_302 seopress_redirection_status" title="' . esc_html__( '302 Found / Moved Temporarily', 'wp-seopress-pro' ) . '">' . absint( $seopress_redirections_type ) . '</span>';
				break;

			case '307':
				echo '<span class="seopress_redirection_307 seopress_redirection_status" title="' . esc_html__( '307 Moved Temporarily', 'wp-seopress-pro' ) . '">' . absint( $seopress_redirections_type ) . '</span>';
				break;

			case '410':
				echo '<span class="seopress_redirection_410 seopress_redirection_status" title="' . esc_html__( '410 Gone', 'wp-seopress-pro' ) . '">' . absint( $seopress_redirections_type ) . '</span>';
				break;

			case '451':
				echo '<span class="seopress_redirection_451 seopress_redirection_status" title="' . esc_html__( '451 Unavailable For Legal Reasons', 'wp-seopress-pro' ) . '">' . absint( $seopress_redirections_type ) . '</span>';
				break;

			default:
				echo '<span class="seopress_redirection_default seopress_redirection_status" title="' . esc_html__( '404 not found', 'wp-seopress-pro' ) . '">404</span>';
				break;
		}
	}
	if ( 'seopress_404_redirect_value' === $column ) {
		if ( get_post_meta( $post_id, '_seopress_redirections_value', true ) ) {
			echo esc_html( get_post_meta( $post_id, '_seopress_redirections_value', true ) );
		}
	}
	if ( 'seopress_404_redirect_date_request' === $column ) {
		global $wp_version;
		$timestamp = esc_html( get_post_meta( $post_id, '_seopress_404_redirect_date_request', true ) );
		if ( '' != $timestamp ) {
			echo esc_html( date( get_option( 'date_format' ) . ' [' . get_option( 'time_format' ) . ']', $timestamp ) );
		}
	}
	if ( 'seopress_404_redirect_ua' === $column ) {
		echo esc_html( get_post_meta( $post_id, 'seopress_redirections_ua', true ) );
	}
	if ( 'seopress_404_redirect_referer' === $column ) {
		echo '<a target="_blank" href="' . esc_html( get_post_meta( $post_id, 'seopress_redirections_referer', true ) ) . '">' . esc_html( get_post_meta( $post_id, 'seopress_redirections_referer', true ) ) . '</a>';
	}
}

/**
 * Sortable columns
 *
 * @param array $columns Columns.
 * @return array Columns.
 */
function seopress_404_sortable_columns( $columns ) {
	$columns['seopress_404']                       = 'seopress_404';
	$columns['seopress_404_redirect_enable']       = 'seopress_404_redirect_enable';
	$columns['seopress_404_redirect_regex_enable'] = 'seopress_404_redirect_regex_enable';
	$columns['seopress_404_redirect_type']         = 'seopress_404_redirect_type';

	return $columns;
}

/**
 * Sort columns by
 *
 * @param object $query Query.
 * @return object Query.
 */
function seopress_404_sort_columns_by( $query ) {
	if ( ! is_admin() ) {
		return $query;
	}

	global $typenow;
	if ( 'seopress_404' !== $typenow ) {
		return $query;
	}

	if ( isset( $_GET['post_status'] ) ) {
		$current_view = sanitize_text_field( wp_unslash( $_GET['post_status'] ) );
	}

	$orderby = $query->get( 'orderby' );

	// Count.
	if ( 'seopress_404' === $orderby ) {
		$query->set(
			'meta_query',
			array(
				'relation' => 'AND',
				array(
					'key'     => 'seopress_404_count',
					'compare' => 'EXISTS',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => '_seopress_redirections_type',
					'compare' => 'NOT EXISTS',
				),
			)
		);
		if ( 'redirects' === $_GET['post_status'] || 'all' === $_GET['post_status'] ) {
			$query->set(
				'meta_query',
				array(
					'relation' => 'AND',
					array(
						'key'     => 'seopress_404_count',
						'compare' => 'EXISTS',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_seopress_redirections_type',
						'compare' => 'EXISTS',
					),
				)
			);
		}
		$query->set( 'orderby', 'meta_value_num' );

		if ( '404' === $_GET['post_status'] ) {
			$query->set( 'orderby', 'seopress_404_count' );
		}
	}
	// Enabled?.
	if ( 'seopress_404_redirect_enable' === $orderby ) {
		$query->set(
			'meta_query',
			array(
				'relation' => 'OR',
				array(
					'key'     => '_seopress_redirections_enabled',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_seopress_redirections_enabled',
					'compare' => 'NOT EXISTS',
				),
			)
		);
		$query->set( 'orderby', '_seopress_redirections_enabled' );
	}
	// Regex?.
	if ( 'seopress_404_redirect_regex_enable' === $orderby ) {
		$query->set(
			'meta_query',
			array(
				'relation' => 'OR',
				array(
					'key'     => '_seopress_redirections_enabled_regex',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_seopress_redirections_enabled_regex',
					'compare' => 'NOT EXISTS',
				),
			)
		);
		$query->set( 'orderby', '_seopress_redirections_enabled_regex' );
	}
	// Type.
	if ( 'seopress_404_redirect_type' === $orderby ) {
		$query->set( 'orderby', 'meta_value' );
		$query->set(
			'meta_query',
			array(
				'relation' => 'OR',
				array(
					'key'     => '_seopress_redirections_type',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_seopress_redirections_type',
					'compare' => 'NOT EXISTS',
				),
			)
		);

		if ( 'redirects' === $_GET['post_status'] ) {
			$query->set(
				'meta_query',
				array(
					'relation' => 'AND',
					array(
						'key'     => '_seopress_redirections_type',
						'compare' => 'EXISTS',
					),
				)
			);
		}
	}

	return $query;
}

/**
 * Quick Edit
 *
 * @param string $column_name Column name.
 * @return void Void.
 */
function seopress_bulk_quick_edit_301_custom_box( $column_name ) {
	if ( is_plugin_active( 'admin-columns-pro/admin-columns-pro.php' ) ) {
		return;
	}

	static $printNonce = true;
	if ( $printNonce ) {
		$printNonce = false;
		wp_nonce_field( plugin_basename( __FILE__ ), 'seopress_301_edit_nonce' );
	}
	?>
<div class="wp-clearfix"></div>
<fieldset class="inline-edit-col-left">
<div class="inline-edit-col column-<?php echo esc_html( $column_name ); ?>">

	<?php
	switch ( $column_name ) {
		case 'seopress_404_redirect_value':
			?>
<label class="inline-edit-group">
	<span class="title"><?php esc_html_e( 'New URL', 'wp-seopress-pro' ); ?></span>
	<span class="input-text-wrap">
		<input type="text" name="seopress_redirections_value" />
	</span>
</label>
			<?php
			break;
		case 'seopress_404_redirect_type':
			?>
<label class="alignleft">
	<span class="title"><?php esc_html_e( 'Redirection type', 'wp-seopress-pro' ); ?></span>
	<select name="seopress_redirections_type">
		<option value="301"><?php esc_html_e( '301 Moved Permanently', 'wp-seopress-pro' ); ?>
		</option>
		<option value="302"><?php esc_html_e( '302 Found / Moved Temporarily', 'wp-seopress-pro' ); ?>
		</option>
		<option value="307"><?php esc_html_e( '307 Moved Temporarily', 'wp-seopress-pro' ); ?>
		</option>
		<option value="410"><?php esc_html_e( '410 Gone', 'wp-seopress-pro' ); ?>
		</option>
		<option value="451"><?php esc_html_e( '451 Unavailable For Legal Reasons', 'wp-seopress-pro' ); ?>
		</option>
	</select>
</label>
			<?php
			break;
		case 'seopress_404_redirect_enable':
			?>
<h4><?php esc_html_e( 'Redirection settings', 'wp-seopress-pro' ); ?>
</h4>
<label class="alignleft">
	<input type="checkbox" name="seopress_redirections_enabled" value="yes">
	<span class="checkbox-title"><?php esc_html_e( 'Enable redirection?', 'wp-seopress-pro' ); ?></span>
</label>
			<?php
			break;
		case 'seopress_404_redirect_regex_enable':
			?>
<label class="alignleft">
	<input type="checkbox" name="seopress_redirections_enabled_regex" value="yes">
	<span class="checkbox-title"><?php esc_html_e( 'Regex?', 'wp-seopress-pro' ); ?></span>
</label>
			<?php
			break;
		default:
			break;
	}
	?>
</div>
</fieldset>
	<?php
}

/**
 * Bulk quick edit 301 save post
 *
 * @param int $post_id Post ID.
 * @return int Post ID.
 */
function seopress_bulk_quick_edit_301_save_post( $post_id ) {
	// Don't save if Elementor library.
	if ( isset( $_REQUEST['post_type'] ) && 'elementor_library' === $_REQUEST['post_type'] ) {
		return $post_id;
	}

	// Don't save for autosave.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return $post_id;
	}

	// Don't save for revisions.
	if ( isset( $_REQUEST['post_type'] ) && 'revision' === $_REQUEST['post_type'] ) {
		return $post_id;
	}

	if ( ! current_user_can( 'edit_redirections', $post_id ) ) {
		return;
	}

	$_REQUEST += array( 'seopress_301_edit_nonce' => '' );

	if ( ! wp_verify_nonce( $_REQUEST['seopress_301_edit_nonce'], plugin_basename( __FILE__ ) ) ) {
		return;
	}
	if ( isset( $_REQUEST['seopress_redirections_value'] ) ) {
		update_post_meta( $post_id, '_seopress_redirections_value', sanitize_url( $_REQUEST['seopress_redirections_value'] ) );
	}
	if ( isset( $_REQUEST['seopress_redirections_type'] ) ) {
		$redirection_type = intval( $_REQUEST['seopress_redirections_type'] );

		if ( in_array( $redirection_type, array( 301, 302, 307, 410, 451 ), true ) ) {
			update_post_meta( $post_id, '_seopress_redirections_type', $redirection_type );
		} else {
			delete_post_meta( $post_id, '_seopress_redirections_type' );
		}
	}
	if ( isset( $_REQUEST['seopress_redirections_enabled'] ) ) {
		update_post_meta( $post_id, '_seopress_redirections_enabled', 'yes' );
	} else {
		delete_post_meta( $post_id, '_seopress_redirections_enabled', '' );
	}
	if ( isset( $_REQUEST['seopress_redirections_enabled_regex'] ) ) {
		update_post_meta( $post_id, '_seopress_redirections_enabled_regex', 'yes' );
	} else {
		delete_post_meta( $post_id, '_seopress_redirections_enabled_regex', '' );
	}
}

/**
 * Whether the redirection being written stores a regex pattern rather than a path.
 *
 * The stored meta is the source of truth, but both editors can flip the mode and
 * the origin in the same request and only save the meta afterwards, so at this
 * point it still holds the previous mode. Callers that know better say so:
 * the REST endpoints pass `seopress_enabled_regex` in the post array, and the
 * classic edit screen and quick edit post the checkbox alongside the title,
 * which their own nonce lets us trust.
 *
 * @param array $postarr Post array handed to wp_insert_post().
 * @return bool
 */
function seopress_redirection_origin_is_regex( $postarr ) {
	if ( isset( $postarr['seopress_enabled_regex'] ) ) {
		return ! empty( $postarr['seopress_enabled_regex'] );
	}

	if ( isset( $_REQUEST['seopress_301_edit_nonce'] ) ) {
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['seopress_301_edit_nonce'] ) );
		if ( wp_verify_nonce( $nonce, plugin_basename( __FILE__ ) ) ) {
			return isset( $_REQUEST['seopress_redirections_enabled_regex'] );
		}
	}

	return 'yes' === get_post_meta( $postarr['ID'], '_seopress_redirections_enabled_regex', true );
}

/**
 * Filter post title
 *
 * @param array $data Data.
 * @param array $postarr Postarr.
 * @return array Data.
 */
function seopress_filter_post_title( $data, $postarr ) {
	if ( isset( $data['post_type'] ) && 'seopress_404' === $data['post_type'] && isset( $postarr['ID'] ) ) {
		if ( '' != get_post_meta( $postarr['ID'], '_seopress_redirections_type', true ) ) {
			// A regex origin is a pattern, not a path. This filter runs on every
			// update of a redirection, whatever wrote it - REST editor, classic
			// edit screen, quick edit, WP-CLI, an import - so without this guard
			// a pattern stored verbatim on create is normalized back on its first
			// update: `/it(/(.*))?$` returns to `it(/(.*))?$`, which the matcher
			// applies unanchored and which then catches every URL merely
			// containing "it".
			if ( seopress_redirection_origin_is_regex( $postarr ) ) {
				return $data;
			}

			$title = $data['post_title'];

			if ( $title ) {
				// Not wp_parse_url(): this filter runs on every save, the title
				// is stored decoded, and parse_url() turns every C1 byte into an
				// underscore. `tag/остров/` came back as `tag/о_ _ _ов/` and
				// `tag/straße/` as `tag/stra_e/`, both invalid UTF-8, which the
				// database refuses — so saving any redirection whose origin is
				// not ASCII failed, with nothing on screen to say why.
				$path = seopress_pro_redirection_origin_path( $title );

				if ( '' !== $path ) {
					$data['post_title'] = $path;
				}
			}
		}
	}

	return $data;
}

/**
 * Bulk actions add to category
 *
 * @param array $bulk_actions Bulk actions.
 * @return array Bulk actions.
 */
function seopress_bulk_actions_add_to_cat( $bulk_actions ) {
	$categories = get_terms(
		array(
			'taxonomy'   => 'seopress_404_cat',
			'hide_empty' => false,
			'number'     => 1,
		)
	);

	if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
		$bulk_actions['seopress_add_to_cat'] = esc_html__( 'Add to category', 'wp-seopress-pro' );
	}

	return $bulk_actions;
}

/**
 * Bulk action add to category handler
 *
 * @param string $redirect_to Redirect to.
 * @param string $doaction Do action.
 * @param array  $post_ids Post IDs.
 * @return string Redirect to.
 */
function seopress_bulk_action_add_to_cat_handler( $redirect_to, $doaction, $post_ids ) {
	if ( 'seopress_add_to_cat' !== $doaction ) {
		return $redirect_to;
	}

	$term_id = isset( $_REQUEST['bulk_cat_id'] ) ? absint( $_REQUEST['bulk_cat_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( 0 === $term_id ) {
		return $redirect_to;
	}

	$term = get_term( $term_id, 'seopress_404_cat' );
	if ( ! $term || is_wp_error( $term ) ) {
		return $redirect_to;
	}

	$updated = 0;
	foreach ( $post_ids as $post_id ) {
		$result = wp_set_object_terms( (int) $post_id, $term_id, 'seopress_404_cat', true );
		if ( ! is_wp_error( $result ) ) {
			++$updated;
		}
	}

	$redirect_to = add_query_arg(
		array(
			'bulk_add_to_cat_posts' => $updated,
			'bulk_add_to_cat_name'  => rawurlencode( $term->name ),
		),
		$redirect_to
	);

	return $redirect_to;
}

/**
 * Bulk action add to category admin notice
 *
 * @return void Void.
 */
function seopress_bulk_action_add_to_cat_admin_notice() {
	if ( empty( $_REQUEST['bulk_add_to_cat_posts'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$count    = intval( $_REQUEST['bulk_add_to_cat_posts'] ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$cat_name = isset( $_REQUEST['bulk_add_to_cat_name'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['bulk_add_to_cat_name'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended

	/* translators: 1: number of redirections updated, 2: category name */
	$message = sprintf(
		_n(
			'%1$s redirection assigned to &ldquo;%2$s&rdquo;.',
			'%1$s redirections assigned to &ldquo;%2$s&rdquo;.',
			$count,
			'wp-seopress-pro'
		),
		$count,
		esc_html( $cat_name )
	);

	echo '<div id="message" class="updated fade"><p>' . wp_kses_post( $message ) . '</p></div>';
}
