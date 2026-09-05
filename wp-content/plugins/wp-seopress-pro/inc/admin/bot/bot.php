<?php
/**
 * SEOPress BOT Custom Post Type
 *
 * @package SEOPress PRO
 * @subpackage Bot
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( '1' !== seopress_get_toggle_option( 'bot' ) ) {
	return;
}
/**
 * Register SEOPress BOT Custom Post Type
 *
 * @return void
 */
function seopress_bot_fn() {
	$labels = array(
		'name'                  => _x( 'Broken links', 'Post Type General Name', 'wp-seopress-pro' ),
		'singular_name'         => _x( 'Broken links', 'Post Type Singular Name', 'wp-seopress-pro' ),
		'menu_name'             => __( 'Broken links', 'wp-seopress-pro' ),
		'name_admin_bar'        => __( 'Broken links', 'wp-seopress-pro' ),
		'archives'              => __( 'Item Links', 'wp-seopress-pro' ),
		'parent_item_colon'     => __( 'Parent Link:', 'wp-seopress-pro' ),
		'all_items'             => __( 'All Broken links', 'wp-seopress-pro' ),
		'add_new_item'          => __( 'Add New Link', 'wp-seopress-pro' ),
		'add_new'               => __( 'Add link', 'wp-seopress-pro' ),
		'new_item'              => __( 'New link', 'wp-seopress-pro' ),
		'edit_item'             => __( 'Edit link', 'wp-seopress-pro' ),
		'update_item'           => __( 'Update Link', 'wp-seopress-pro' ),
		'view_item'             => __( 'View Link', 'wp-seopress-pro' ),
		'search_items'          => __( 'Search Link', 'wp-seopress-pro' ),
		'not_found'             => __( 'Not found', 'wp-seopress-pro' ),
		'not_found_in_trash'    => __( 'Not found in Trash', 'wp-seopress-pro' ),
		'featured_image'        => __( 'Featured Image', 'wp-seopress-pro' ),
		'set_featured_image'    => __( 'Set featured image', 'wp-seopress-pro' ),
		'remove_featured_image' => __( 'Remove featured image', 'wp-seopress-pro' ),
		'use_featured_image'    => __( 'Use as featured image', 'wp-seopress-pro' ),
		'insert_into_item'      => __( 'Insert into item', 'wp-seopress-pro' ),
		'uploaded_to_this_item' => __( 'Uploaded to this item', 'wp-seopress-pro' ),
		'items_list'            => __( 'Redirections list', 'wp-seopress-pro' ),
		'items_list_navigation' => __( 'Redirections list navigation', 'wp-seopress-pro' ),
		'filter_items_list'     => __( 'Filter redirections list', 'wp-seopress-pro' ),
	);
	$args   = array(
		'label'               => __( 'Broken links', 'wp-seopress-pro' ),
		'description'         => __( 'List of broken links', 'wp-seopress-pro' ),
		'labels'              => $labels,
		'supports'            => array( 'title', 'editor', 'custom-fields' ),
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
		'capability_type'     => 'broken_link',
		'capabilities'        => array(
			'edit_post'              => 'edit_broken_link',
			'edit_posts'             => 'edit_broken_links',
			'edit_others_posts'      => 'edit_others_broken_links',
			'publish_posts'          => 'publish_broken_links',
			'read_post'              => 'read_broken_link',
			'read_private_posts'     => 'read_private_broken_links',
			'delete_post'            => 'delete_broken_link',
			'delete_others_posts'    => 'delete_others_broken_links',
			'delete_published_posts' => 'delete_published_broken_links',
			'create_posts'           => 'do_not_allow',
		),
		'map_meta_cap'        => true,
	);
	register_post_type( 'seopress_bot', $args );
}
add_action( 'init', 'seopress_bot_fn', 10 );

/**
 * Map SEOPress Broken Link caps.
 *
 * @param array  $caps The capabilities.
 * @param string $cap The capability.
 * @param int    $user_id The user ID.
 * @param array  $args The arguments.
 *
 * @return array The capabilities.
 */
function seopress_bot_map_meta_cap( $caps, $cap, $user_id, $args ) {
	if ( 'edit_broken_link' === $cap || 'delete_broken_link' === $cap || 'read_broken_link' === $cap ) {
		$post = get_post( $args[0] );

		if ( ! $post ) {
			return $caps;
		}

		$post_type = get_post_type_object( $post->post_type );

		if ( ! $post_type ) {
			return $caps;
		}

		$caps = array();
	}

	if ( 'edit_broken_link' === $cap ) {
		if ( (int) $user_id === (int) $post->post_author ) {
			$caps[] = $post_type->cap->edit_posts;
		} else {
			$caps[] = $post_type->cap->edit_others_posts;
		}
	} elseif ( 'delete_broken_link' === $cap ) {
		if ( (int) $user_id === (int) $post->post_author ) {
			$caps[] = $post_type->cap->delete_published_posts;
		} else {
			$caps[] = $post_type->cap->delete_others_posts;
		}
	} elseif ( 'read_broken_link' === $cap ) {
		if ( 'private' !== $post->post_status ) {
			$caps[] = 'read';
		} elseif ( (int) $user_id === (int) $post->post_author ) {
			$caps[] = 'read';
		} else {
			$caps[] = $post_type->cap->read_private_posts;
		}
	}

	return $caps;
}
add_filter( 'map_meta_cap', 'seopress_bot_map_meta_cap', 10, 4 );

/**
 * Remove bulk / inline edit for BOT Custom Post Type
 *
 * @param array   $actions The actions.
 * @param WP_Post $post The post.
 *
 * @return array The actions.
 */
function seopress_bot_bulk_inline_actions( $actions, $post ) {
	// Check for your post type.
	if ( 'seopress_bot' === $post->post_type ) {
		$edit_link = admin_url( 'post.php?post=' . get_post_meta( $post->ID, 'seopress_bot_source_id', true ) . '&action=edit' );
		$trash     = $actions['trash'];
		$actions   = array(
			'edit' => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $edit_link ),
				esc_html( __( 'Edit source to fix link', 'wp-seopress-pro' ) )
			),
		);

		$actions['trash'] = $trash;
	}

	return $actions;
}
add_filter( 'post_row_actions', 'seopress_bot_bulk_inline_actions', 10, 2 );

/**
 * Remove bulk edit actions for BOT Custom Post Type.
 *
 * @param array $actions The actions.
 * @return array The actions.
 */
function seopress_bot_bulk_edit_actions( $actions ) {
	unset( $actions['edit'] );

	return $actions;
}
add_filter( 'bulk_actions-edit-seopress_bot', 'seopress_bot_bulk_edit_actions' );

/**
 * Filters view
 *
 * @return void
 */
function seopress_bot_filters_cpt() {
	global $typenow;

	if ( 'seopress_bot' === $typenow ) {
		$status = array( '200', '301', '302', '307', '400', '401', '402', '403', '404', '410', '451', '500' );

		echo "<select name='bot-status' id='bot-status' class='postform'>";
		echo "<option value=''>" . esc_html__( 'Show All', 'wp-seopress-pro' ) . '</option>';
		foreach ( $status as $code ) {
			echo '<option value=' . absint( $code ), isset( $_GET[ $code ] ) === $code ? ' selected="selected"' : '', '>' . absint( $code ) . '</option>';
		}
		echo '</select>';
	}
}
add_action( 'restrict_manage_posts', 'seopress_bot_filters_cpt' );

/**
 * Filters action
 *
 * @param WP_Query $query The query.
 * @return void
 */
function seopress_bot_filters_action( $query ) {
	global $pagenow;
	$current_page = isset( $_GET['post_type'] ) ? wp_unslash( $_GET['post_type'] ) : '';

	if ( is_admin() && 'seopress_bot' === $current_page && 'edit.php' === $pagenow && isset( $_GET['bot-status'] ) &&
		'' !== $_GET['bot-status'] ) {
		$code                              = wp_unslash( $_GET['bot-status'] );
		$query->query_vars['meta_key']     = 'seopress_bot_status';
		$query->query_vars['meta_value']   = $code;
		$query->query_vars['meta_compare'] = '=';
	}
}
add_filter( 'parse_query', 'seopress_bot_filters_action' );

/**
 * Set messages for BOT Custom Post Type
 *
 * @param array $messages The post updated messages.
 *
 * @return array The post updated messages.
 */
function seopress_bot_set_messages( $messages ) {
	global $post, $post_ID;
	$post_type = 'seopress_bot';

	$obj      = get_post_type_object( $post_type );
	$singular = $obj->labels->singular_name;

	$messages['seopress_bot'] = array(
		0  => '', // Unused. Messages start at index 1.
		1  => /* translators: %s singular name of the post type */ sprintf( __( '%s updated.', 'wp-seopress-pro' ), esc_html( $singular ) ),
		2  => __( 'Custom field updated.', 'wp-seopress-pro' ),
		3  => __( 'Custom field deleted.', 'wp-seopress-pro' ),
		4  => /* translators: %s singular name of the post type */ sprintf( __( '%s updated.', 'wp-seopress-pro' ), esc_html( $singular ) ),
		5  => isset( $_GET['revision'] ) ? /* translators: %1$s singular name of the post type, %2$s title of the revision */ sprintf( __( '%1$s restored to revision from %2$s', 'wp-seopress-pro' ), esc_html( $singular ), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
		6  => /* translators: %s singular name of the post type */ sprintf( __( '%s published.', 'wp-seopress-pro' ), esc_html( $singular ) ),
		7  => __( 'Page saved.', 'wp-seopress-pro' ),
		8  => /* translators: %s singular name of the post type */ sprintf( __( '%s submitted.', 'wp-seopress-pro' ), esc_html( $singular ) ),
		9  => /* translators: %1$s singular name of the post type, %2$s scheduled date */ sprintf( __( '%1$s scheduled for: <strong>%2$s</strong>. ', 'wp-seopress-pro' ), esc_html( $singular ), date_i18n( __( 'M j, Y @ G:i', 'wp-seopress-pro' ), strtotime( $post->post_date ) ), esc_url( get_permalink( $post_ID ) ) ),
		10 => /* translators: %s singular name of the post type */ sprintf( __( '%s draft updated.', 'wp-seopress-pro' ), esc_html( $singular ) ),
	);

	return $messages;
}
add_filter( 'post_updated_messages', 'seopress_bot_set_messages' );

/**
 * Set messages for BOT Custom Post Type list.
 *
 * @param array $bulk_messages The bulk messages.
 * @param array $bulk_counts The bulk counts.
 * @return array The bulk messages.
 */
function seopress_bot_set_messages_list( $bulk_messages, $bulk_counts ) {
	$bulk_messages['seopress_bot'] = array(
		'updated'   => /* translators: %d number of entries updated */ _n( '%d broken link updated.', '%d broken links updated.', $bulk_counts['updated'], 'wp-seopress-pro' ),
		'locked'    => /* translators: %d number of entries updated */ _n( '%d broken link not updated, somebody is editing it.', '%d broken links not updated, somebody is editing them.', $bulk_counts['locked'], 'wp-seopress-pro' ),
		'deleted'   => /* translators: %d number of entries deleted */ _n( '%d broken link permanently deleted.', '%d broken links permanently deleted.', $bulk_counts['deleted'], 'wp-seopress-pro' ),
		'trashed'   => /* translators: %d number of entries trashed */ _n( '%d broken link moved to the Trash.', '%d broken links moved to the Trash.', $bulk_counts['trashed'], 'wp-seopress-pro' ),
		'untrashed' => /* translators: %d number of entries untrashed */ _n( '%d broken link restored from the Trash.', '%d broken links restored from the Trash.', $bulk_counts['untrashed'], 'wp-seopress-pro' ),
	);

	return $bulk_messages;
}
add_filter( 'bulk_post_updated_messages', 'seopress_bot_set_messages_list', 10, 2 );

/**
 * Add custom buttons to SEOPress BOT Custom Post Type
 *
 * @return void
 */
function seopress_bot_btn() {
	$screen = get_current_screen();
	// Null on admin pages that never register a WP_Screen (some third-party
	// plugin screens): reading a property on it warned on every such page.
	if ( ! $screen || 'seopress_bot' !== $screen->post_type ) {
		return;
	}
	?>
	<script>
	jQuery(function(){
		jQuery("body.post-type-seopress_bot .wrap h1").append('<a href="<?php echo esc_url( admin_url( 'admin.php?page=seopress-broken-links' ) ); ?>" class="page-title-action"><?php esc_attr_e( 'Scan broken links', 'wp-seopress-pro' ); ?></a> <a href="<?php echo esc_url( admin_url( 'admin.php?page=seopress-broken-links' ) ); ?>" class="page-title-action"><?php esc_attr_e( 'Export to CSV', 'wp-seopress-pro' ); ?></a>');
	});
	</script>
	<?php
}
add_action( 'admin_head', 'seopress_bot_btn' );

/**
 * Columns for BOT Custom Post Type
 *
 * @param array $columns The columns.
 * @return array
 */
function seopress_bot_count_columns( $columns ) {
	$columns['seopress_bot_broken_link'] = __( 'Broken link', 'wp-seopress-pro' );
	$columns['seopress_bot_count']       = __( 'Count', 'wp-seopress-pro' );
	$columns['seopress_bot_status']      = __( 'Status', 'wp-seopress-pro' );
	$columns['seopress_bot_type']        = __( 'Type', 'wp-seopress-pro' );
	$columns['seopress_bot_anchor']      = __( 'Anchor text', 'wp-seopress-pro' );
	$columns['seopress_bot_source']      = __( 'Source', 'wp-seopress-pro' );
	$columns['seopress_bot_cpt']         = __( 'Post type', 'wp-seopress-pro' );
	unset( $columns['date'] );
	unset( $columns['title'] );

	return $columns;
}
add_filter( 'manage_edit-seopress_bot_columns', 'seopress_bot_count_columns' );
add_action( 'manage_seopress_bot_posts_custom_column', 'seopress_bot_count_display_column', 10, 2 );

/**
 * Display columns for BOT Custom Post Type
 *
 * @param string $column The column.
 * @param int    $post_id The post ID.
 * @return void
 */
function seopress_bot_count_display_column( $column, $post_id ) {
	if ( $post_id ) {
		if ( 'seopress_bot_broken_link' === $column ) {
			if ( get_post_meta( $post_id, 'seopress_bot_source_id', true ) ) {
				$p_id = get_post_meta( $post_id, 'seopress_bot_source_id', true );

				if ( isset( $p_id ) ) {
					$broken_link_edit = get_edit_post_link( $p_id );
					echo '<a href="' . esc_url( $broken_link_edit ) . '">';
					echo esc_html( get_the_title( $post_id ) );
					echo ' - <span class="dashicons dashicons-edit"></span>';
					echo '</a>';
				}
			}
		}
		if ( 'seopress_bot_count' === $column ) {
			echo absint( get_post_meta( $post_id, 'seopress_bot_count', true ) );
		}
		if ( 'seopress_bot_status' === $column ) {
			$seopress_bot_status = get_post_meta( $post_id, 'seopress_bot_status', true );
			switch ( $seopress_bot_status ) {
				case '500':
					echo '<span class="seopress_bot_500">' . esc_html( $seopress_bot_status ) . '</span>';
					break;

				case '404':
				case '403':
				case '402':
				case '401':
				case '400':
					echo '<span class="seopress_bot_404">' . esc_html( $seopress_bot_status ) . '</span>';
					break;

				case '307':
					echo '<span class="seopress_bot_307">' . esc_html( $seopress_bot_status ) . '</span>';
					break;

				case '302':
					echo '<span class="seopress_bot_302">' . esc_html( $seopress_bot_status ) . '</span>';
					break;

				case '301':
					echo '<span class="seopress_bot_301">' . esc_html( $seopress_bot_status ) . '</span>';
					break;

				case '200':
					echo '<span class="seopress_bot_200">' . esc_html( $seopress_bot_status ) . '</span>';
					break;

				default:
					echo '<span class="seopress_bot_default">' . esc_html( $seopress_bot_status ) . '</span>';
					break;
			}
		}
		if ( 'seopress_bot_type' === $column ) {
			echo esc_html( get_post_meta( $post_id, 'seopress_bot_type', true ) );
		}
		if ( 'seopress_bot_anchor' === $column ) {
			echo esc_html( get_post_meta( $post_id, 'seopress_bot_a_title', true ) );
		}
		if ( 'seopress_bot_cpt' === $column ) {
			echo esc_html( get_post_meta( $post_id, 'seopress_bot_cpt', true ) );
		}
		if ( 'seopress_bot_source' === $column ) {
			echo '<a href="' . esc_url( get_post_meta( $post_id, 'seopress_bot_source_url', true ) ) . '" target="_blank">' . esc_html( get_post_meta( $post_id, 'seopress_bot_source_title', true ) ) . '</a><span class="seopress-help dashicons dashicons-external"></span>';
		}
	}
}

/**
 * Sortable columns for BOT Custom Post Type
 *
 * @param array $columns The columns.
 * @return array The columns.
 */
function seopress_bot_sortable_columns( $columns ) {
	$columns['seopress_bot_status'] = 'seopress_bot_status';

	return $columns;
}
add_filter( 'manage_edit-seopress_bot_sortable_columns', 'seopress_bot_sortable_columns' );

/**
 * Sort columns by for BOT Custom Post Type
 *
 * @param WP_Query $query The query.
 * @return void
 */
function seopress_bot_sort_columns_by( $query ) {
	if ( ! is_admin() ) {
		return;
	} else {
		$orderby = $query->get( 'orderby' );
		if ( 'seopress_bot_status' === $orderby ) {
			$query->set( 'meta_key', 'seopress_bot_status' );
			$query->set( 'orderby', 'meta_value' );
		}
	}
}
add_filter( 'pre_get_posts', 'seopress_bot_sort_columns_by' );
