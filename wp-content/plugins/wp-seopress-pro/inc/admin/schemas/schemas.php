<?php
/**
 * SEOPress PRO Schemas.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Return the conditions for schemas.
 *
 * @since 3.8.1
 *
 * @author Julio Potier
 *
 * @return (array)
 **/
function seopress_get_schemas_conditions() {
	return array(
		'equal'     => __( 'is equal to', 'wp-seopress-pro' ),
		'not_equal' => __( 'is NOT equal to', 'wp-seopress-pro' ),
	);
}

/**
 * Return the filters for schemas.
 *
 * @since 3.8.1
 *
 * @author Julio Potier
 *
 * @return (array)
 **/
function seopress_get_schemas_filters() {
	return array(
		'post_type' => __( 'Post Type', 'wp-seopress-pro' ),
		'taxonomy'  => __( 'Term Taxonomy', 'wp-seopress-pro' ),
		'postId'    => __( 'Post ID', 'wp-seopress-pro' ),
	);
}

/**
 * Return default values for retrocompat.
 *
 * @param mixed $rule The default rule.
 *
 * @return array The default rule.
 **/
function seopress_get_default_schemas_rules( $rule ) {
	return array(
		array(
			array(
				'filter' => 'post_type',
				'cpt'    => $rule,
				'taxo'   => 0,
				'cond'   => 'equal',
			),
		),
	);
}

/**
 * Register SEOPress Schemas Custom Post Type.
 */
function seopress_schemas_fn() {
	$labels = array(
		'name'                  => _x( 'Schemas', 'Post Type General Name', 'wp-seopress-pro' ),
		'singular_name'         => _x( 'Schema', 'Post Type Singular Name', 'wp-seopress-pro' ),
		'menu_name'             => __( 'Schemas', 'wp-seopress-pro' ),
		'name_admin_bar'        => __( 'Schemas', 'wp-seopress-pro' ),
		'archives'              => __( 'Item Archives', 'wp-seopress-pro' ),
		'parent_item_colon'     => __( 'Parent Item:', 'wp-seopress-pro' ),
		'all_items'             => __( 'All schemas', 'wp-seopress-pro' ),
		'add_new_item'          => __( 'Add New schema', 'wp-seopress-pro' ),
		'add_new'               => __( 'Add schema', 'wp-seopress-pro' ),
		'new_item'              => __( 'New schema', 'wp-seopress-pro' ),
		'edit_item'             => __( 'Edit schema', 'wp-seopress-pro' ),
		'update_item'           => __( 'Update schema', 'wp-seopress-pro' ),
		'view_item'             => __( 'View schema', 'wp-seopress-pro' ),
		'search_items'          => __( 'Search schema', 'wp-seopress-pro' ),
		'not_found'             => __( 'Not found', 'wp-seopress-pro' ),
		'not_found_in_trash'    => __( 'Not found in Trash', 'wp-seopress-pro' ),
		'featured_image'        => __( 'Featured Image', 'wp-seopress-pro' ),
		'set_featured_image'    => __( 'Set featured image', 'wp-seopress-pro' ),
		'remove_featured_image' => __( 'Remove featured image', 'wp-seopress-pro' ),
		'use_featured_image'    => __( 'Use as featured image', 'wp-seopress-pro' ),
		'insert_into_item'      => __( 'Insert into item', 'wp-seopress-pro' ),
		'uploaded_to_this_item' => __( 'Uploaded to this item', 'wp-seopress-pro' ),
		'items_list'            => __( 'Schemas list', 'wp-seopress-pro' ),
		'items_list_navigation' => __( 'Schemas list navigation', 'wp-seopress-pro' ),
		'filter_items_list'     => __( 'Filter schema list', 'wp-seopress-pro' ),
	);
	$args   = array(
		'label'               => __( 'Schemas', 'wp-seopress-pro' ),
		'description'         => __( 'List of Schemas', 'wp-seopress-pro' ),
		'labels'              => $labels,
		'supports'            => array( 'title' ),
		'hierarchical'        => false,
		'public'              => false,
		'show_ui'             => true,
		'show_in_menu'        => false,
		'menu_icon'           => 'dashicons-excerpt-view',
		'show_in_admin_bar'   => false,
		'show_in_nav_menus'   => false,
		'can_export'          => true,
		'has_archive'         => false,
		'exclude_from_search' => true,
		'publicly_queryable'  => false,
		'capability_type'     => 'schema',
		'capabilities'        => array(
			'edit_post'              => 'edit_schema',
			'edit_posts'             => 'edit_schemas',
			'edit_others_posts'      => 'edit_others_schemas',
			'publish_posts'          => 'publish_schemas',
			'read_post'              => 'read_schema',
			'read_private_posts'     => 'read_private_schemas',
			'delete_post'            => 'delete_schema',
			'delete_others_posts'    => 'delete_others_schemas',
			'delete_published_posts' => 'delete_published_schemas',
		),
	);
	register_post_type( 'seopress_schemas', $args );
}
add_action( 'admin_init', 'seopress_schemas_fn', 10 );


/**
 * Map SEOPress Schema caps.
 *
 * @param array  $caps The capabilities.
 * @param string $cap The capability.
 * @param int    $user_id The user ID.
 * @param array  $args The arguments.
 *
 * @return array The capabilities.
 */
function seopress_schemas_map_meta_cap( $caps, $cap, $user_id, $args ) {
	/* If editing, deleting, or reading a schema, get the post and post type object. */
	if ( 'edit_schema' === $cap || 'delete_schema' === $cap || 'read_schema' === $cap ) {
		$post = get_post( $args[0] );

		if ( ! $post ) {
			return $caps;
		}

		$post_type = get_post_type_object( $post->post_type );

		if ( ! $post_type ) {
			return $caps;
		}

		/* Set an empty array for the caps. */
		$caps = array();
	}

	/* If editing a schema, assign the required capability. */
	if ( 'edit_schema' === $cap ) {
		if ( $user_id == $post->post_author ) {
			$caps[] = $post_type->cap->edit_posts;
		} else {
			$caps[] = $post_type->cap->edit_others_posts;
		}
	}

	/* If deleting a schema, assign the required capability. */
	elseif ( 'delete_schema' === $cap ) {
		if ( $user_id == $post->post_author ) {
			$caps[] = $post_type->cap->delete_published_posts;
		} else {
			$caps[] = $post_type->cap->delete_others_posts;
		}
	}

	/* If reading a private schema, assign the required capability. */
	elseif ( 'read_schema' === $cap ) {
		if ( 'private' !== $post->post_status ) {
			$caps[] = 'read';
		} elseif ( $user_id == $post->post_author ) {
			$caps[] = 'read';
		} else {
			$caps[] = $post_type->cap->read_private_posts;
		}
	}

	/* Return the capabilities required by the user. */
	return $caps;
}
add_filter( 'map_meta_cap', 'seopress_schemas_map_meta_cap', 10, 4 );

/**
 * Set title placeholder for Schemas Custom Post Type.
 *
 * @param string $title The title.
 *
 * @return string The title.
 */
function seopress_schemas_cpt_title( $title ) {
	$screen = get_current_screen();
	// Null on screens without a WP_Screen context. Hand the title back as is.
	if ( $screen && 'seopress_schemas' === $screen->post_type ) {
		$title = esc_html__( 'Enter the name of your schema', 'wp-seopress-pro' );
	}

	return $title;
}
add_filter( 'enter_title_here', 'seopress_schemas_cpt_title' );

/**
 * Add custom buttons to SEOPress Schemas Post Type.
 */
function seopress_schemas_btn_cpt() {
	$screen = get_current_screen();
	// Null on admin pages that never register a WP_Screen: warned there.
	if ( ! $screen || 'seopress_schemas' !== $screen->post_type ) {
		return;
	}
		?>
<script>
	jQuery(function() {
		jQuery("body.post-type-seopress_schemas .wrap h1 ~ a").after(
			'<a href="<?php echo esc_url( admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_rich_snippets' ) ); ?>" id="seopress-schemas-settings" class="page-title-action"><?php esc_html_e( 'Settings', 'wp-seopress-pro' ); ?></a>'
		);
	});
</script>
		<?php
}
add_action( 'admin_head', 'seopress_schemas_btn_cpt' );

/**
 * Add buttons to post type list if empty.
 */
function seopress_schemas_render_blank_state() {
	$docs = function_exists( 'seopress_get_docs_links' ) ? seopress_get_docs_links() : '';
	?>
<div class="seopress-BlankState">

	<h2 class="seopress-BlankState-message">
		<?php esc_html_e( 'Boost your visibility in search results and increase your traffic and conversions.', 'wp-seopress-pro' ); ?>
	</h2>

	<div class="seopress-BlankState-buttons">

		<a class="seopress-BlankState-cta btn btnPrimary"
			href="<?php echo esc_url( admin_url( 'post-new.php?post_type=seopress_schemas' ) ); ?>">
			<?php esc_html_e( 'Create a schema', 'wp-seopress-pro' ); ?>
		</a>

		<a class="seopress-BlankState-cta btn btnTertiary"
			href="<?php echo esc_url( $docs['schemas']['add'] ); ?>"
			target="_blank">
			<?php esc_html_e( 'Learn more about schemas', 'wp-seopress-pro' ); ?>
		</a>

	</div>

</div>

	<?php
}
add_action( 'manage_posts_extra_tablenav', 'seopress_schemas_maybe_render_blank_state' );

/**
 * Maybe render blank state.
 *
 * @param string $which The which.
 *
 * @return void Void.
 */
function seopress_schemas_maybe_render_blank_state( $which ) {
	global $post_type;

	if ( 'seopress_schemas' === $post_type && 'bottom' === $which ) {
		$counts = (array) wp_count_posts( $post_type );
		unset( $counts['auto-draft'] );
		$count = array_sum( $counts );

		if ( 0 < $count ) {
			return;
		}

		seopress_schemas_render_blank_state();

		echo '<style type="text/css">#posts-filter .wp-list-table, #posts-filter .tablenav.top, .tablenav.bottom .actions, .wrap .subsubsub  { display: none; } #posts-filter .tablenav.bottom { height: auto; } </style>';
	}
}

/**
 * Set messages for Schemas Custom Post Type.
 *
 * @param array $messages The messages.
 *
 * @return array The messages.
 */
function seopress_schemas_set_messages( $messages ) {
	global $post, $post_ID, $typenow;
	$post_type = 'seopress_schemas';

	if ( 'seopress_schemas' === $typenow ) {
		$obj      = get_post_type_object( $post_type );
		$singular = $obj->labels->singular_name;

		$messages[ $post_type ] = array(
			0  => '', // Unused. Messages start at index 1.
			1  => sprintf(
				/* translators: %s number of schemas updated. */
				esc_html__( '%s updated.', 'wp-seopress-pro' ),
				$singular
			),
			2  => esc_html__( 'Custom field updated.', 'wp-seopress-pro' ),
			3  => esc_html__( 'Custom field deleted.', 'wp-seopress-pro' ),
			4  => sprintf(
				/* translators: %s number of schemas updated. */
				esc_html__( '%s updated.', 'wp-seopress-pro' ),
				$singular
			),
			5  => isset( $_GET['revision'] ) ? sprintf(
				/* translators: %1$s number of schemas restored to revision from %2$s */
				esc_html__( '%1$s restored to revision from %2$s', 'wp-seopress-pro' ),
				wp_post_revision_title( (int) $_GET['revision'], false ),
				$singular
			) : false,
			6  => sprintf(
				/* translators: %s number of schemas published. */
				esc_html__( '%s published.', 'wp-seopress-pro' ),
				$singular
			),
			7  => sprintf(/* translators: %s number of schemas saved. */
				esc_html__( '%s saved.', 'wp-seopress-pro' ),
				$singular
			),
			8  => sprintf(
				/* translators: %s number of schemas submitted. */
				esc_html__( '%s submitted.', 'wp-seopress-pro' ),
				$singular
			),
			9  => sprintf(/* translators: %1$s number of schemas scheduled for: <strong>%2$s</strong>. */
				esc_html__( '%1$s scheduled for: <strong>%2$s</strong>. ', 'wp-seopress-pro' ),
				$singular,
				date_i18n( esc_html__( 'M j, Y @ G:i', 'wp-seopress-pro' ), strtotime( $post->post_date ) )
			),
			10 => sprintf(
				/* translators: %s number of schemas draft updated. */
				esc_html__( '%s draft updated.', 'wp-seopress-pro' ),
				$singular
			),
		);

		return $messages;
	} else {
		return $messages;
	}
}
add_filter( 'post_updated_messages', 'seopress_schemas_set_messages' );

/**
 * Set messages for Schemas Custom Post Type list.
 *
 * @param array $bulk_messages The bulk messages.
 * @param array $bulk_counts The bulk counts.
 *
 * @return array The bulk messages.
 */
function seopress_schemas_set_messages_list( $bulk_messages, $bulk_counts ) {
	$bulk_messages['seopress_schemas'] = array(
		'updated'   => /* translators: %s schema name */ _n( '%s schema updated.', '%s schemas updated.', $bulk_counts['updated'], 'wp-seopress-pro' ),
		'locked'    => /* translators: %s schema name */ _n( '%s schema not updated, somebody is editing it.', '%s schemas not updated, somebody is editing them.', $bulk_counts['locked'], 'wp-seopress-pro' ),
		'deleted'   => /* translators: %s schema name */ _n( '%s schema permanently deleted.', '%s schemas permanently deleted.', $bulk_counts['deleted'], 'wp-seopress-pro' ),
		'trashed'   => /* translators: %s schema name */ _n( '%s schema moved to the Trash.', '%s schemas moved to the Trash.', $bulk_counts['trashed'], 'wp-seopress-pro' ),
		'untrashed' => /* translators: %s schema name */ _n( '%s schema restored from the Trash.', '%s schemas restored from the Trash.', $bulk_counts['untrashed'], 'wp-seopress-pro' ),
	);

	return $bulk_messages;
}
add_filter( 'bulk_post_updated_messages', 'seopress_schemas_set_messages_list', 10, 2 );

/**
 * Columns for Schemas Custom Post Type.
 *
 * @param array $columns The columns.
 *
 * @return array The columns.
 */
function seopress_schemas_columns( $columns ) {
	$columns['seopress_schemas_type'] = __( 'Data type', 'wp-seopress-pro' );
	$columns['seopress_schemas_cpt']  = __( 'Post type', 'wp-seopress-pro' );
	unset( $columns['date'] );

	return $columns;
}
add_filter( 'manage_edit-seopress_schemas_columns', 'seopress_schemas_columns' );

function seopress_schemas_display_column( $column, $post_id ) {
	if ( 'seopress_schemas_type' == $column ) {
		if ( get_post_meta( $post_id, '_seopress_pro_rich_snippets_type', true ) ) {
			echo get_post_meta( $post_id, '_seopress_pro_rich_snippets_type', true );
		}
	}
	if ( 'seopress_schemas_cpt' == $column ) {
		if ( get_post_meta( $post_id, '_seopress_pro_rich_snippets_rules', true ) ) {
			$rules = get_post_meta( $post_id, '_seopress_pro_rich_snippets_rules', true );
			if ( ! is_array( $rules ) ) {
				$rules = seopress_get_default_schemas_rules( $rules );
			}
			$conditions = seopress_get_schemas_conditions();
			$filters    = seopress_get_schemas_filters();
			$n          = 0;
			$html       = '';
			foreach ( $rules as $or => $values ) {
				foreach ( $values as $and => $value ) {
					$filter = esc_html( $filters[ $value['filter'] ] );
					$cond   = $conditions[ $value['cond'] ];
					if ( 'post_type' === $value['filter'] && post_type_exists( $value['cpt'] ) ) {
						$label = esc_html( get_post_type_object( $value['cpt'] )->label );
						$html .= " <strong>$filter</strong> <em>$cond</em> \"$label\" ";
					} elseif ( 'taxonomy' === $value['filter'] && term_exists( (int) $value['taxo'] ) ) {
						$tax = get_term( $value['taxo'] );
						if ( ! is_wp_error( $tax ) && is_object( $tax ) ) {
							$tax   = esc_html( get_taxonomy( $tax->taxonomy )->label );
							$label = esc_html( get_term( $value['taxo'] )->name );
							$html .= " <strong>$filter</strong> \"$tax\" <em>$cond</em> \"$label\" ";
						}
					} elseif ( 'postId' === $value['filter'] ) {
						$label = esc_html( $value['postId'] );
						$html .= " <strong>$filter</strong> <em>$cond</em> \"$label\" ";
					}
					$html .= esc_html__( 'and', 'wp-seopress-pro' );
					++$n;
					if ( 3 === $n ) {
						$html  = trim( $html, esc_html__( 'and', 'wp-seopress-pro' ) . ' ' );
						$html .= '&hellip;';
						continue 2;
					}
				}
				$html  = trim( $html, esc_html__( 'and', 'wp-seopress-pro' ) );
				$html .= esc_html__( 'or', 'wp-seopress-pro' );
			}
			$html = trim( $html, esc_html__( 'or', 'wp-seopress-pro' ) );
			echo $html;
		}
	}
}
add_action( 'manage_seopress_schemas_posts_custom_column', 'seopress_schemas_display_column', 10, 2 );

/**
 * Display metabox for Schemas Custom Post Type.
 *
 * @return void
 */
function seopress_schemas_init_metabox() {
	add_meta_box( 'seopress_schemas', esc_html__( 'Your schema', 'wp-seopress-pro' ), 'seopress_schemas_cpt', 'seopress_schemas', 'normal', 'default' );
}
add_action( 'add_meta_boxes', 'seopress_schemas_init_metabox' );

/**
 * Display metabox for Schemas Custom Post Type.
 *
 * @param object $post The post object.
 */
function seopress_schemas_cpt( $post ) {
	$prefix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

	wp_nonce_field( plugin_basename( __FILE__ ), 'seopress_schemas_cpt_nonce' );

	global $typenow;

	// Enqueue scripts.
	wp_enqueue_script( 'jquery-ui-accordion' );

	wp_enqueue_script( 'seopress-pro-media-uploader', plugins_url( 'assets/js/seopress-pro-media-uploader' . $prefix . '.js', dirname( dirname( __DIR__ ) ) ), array( 'jquery' ), SEOPRESS_PRO_VERSION, false );

	wp_enqueue_script( 'seopress-pro-rich-snippets', plugins_url( 'assets/js/seopress-pro-rich-snippets' . $prefix . '.js', dirname( dirname( __DIR__ ) ) ), array( 'jquery' ), SEOPRESS_PRO_VERSION, false );

	wp_enqueue_media();

	wp_enqueue_script( 'jquery-ui-datepicker' );

	/**
	 * Filter taxonomies args.
	 *
	 * @param array $args The args.
	 *
	 * @return array The args.
	 */
	function sp_get_taxonomies_args( $args ) {
		$args = array();

		return $args;
	}
	add_filter( 'seopress_get_taxonomies_args', 'sp_get_taxonomies_args' );

	/**
	 * Filter taxonomies list to get WC product attributes.
	 *
	 * @param array $terms The terms.
	 *
	 * @return array The terms.
	 */
	function sp_get_taxonomies_list( $terms ) {
		unset( $terms['seopress_404_cat'] );
		unset( $terms['nav_menu'] );
		unset( $terms['link_category'] );
		unset( $terms['post_format'] );

		return $terms;
	}
	add_filter( 'seopress_get_taxonomies_list', 'sp_get_taxonomies_list' );

	/**
	 * Mapping fields.
	 *
	 * @param string $post_meta_name The post meta name.
	 * @param string $cases The cases.
	 *
	 * @return string The mapping fields.
	 */
	function seopress_schemas_mapping_array( $post_meta_name, $cases ) {
		global $post;

		// Custom fields.
		if ( function_exists( 'seopress_get_custom_fields' ) ) {
			$seopress_get_custom_fields = seopress_get_custom_fields();
		}

		// Init default case array.
		$seopress_schemas_mapping_case = array(
			'Select an option'           => array( 'none' => esc_html__( 'None', 'wp-seopress-pro' ) ),
			'Site Meta'                  => array(
				'site_title' => esc_html__( 'Site Title', 'wp-seopress-pro' ),
				'tagline'    => esc_html__( 'Tagline', 'wp-seopress-pro' ),
				'site_url'   => esc_html__( 'Site URL', 'wp-seopress-pro' ),
			),
			'Post Meta'                  => array(
				'post_id'          => esc_html__( 'Post / Product ID', 'wp-seopress-pro' ),
				'post_title'       => __( 'Post Title / Product title', 'wp-seopress-pro' ),
				'post_excerpt'     => esc_html__( 'Excerpt / Product short description', 'wp-seopress-pro' ),
				'post_content'     => esc_html__( 'Content', 'wp-seopress-pro' ),
				'post_permalink'   => esc_html__( 'Permalink', 'wp-seopress-pro' ),
				'post_author_name' => esc_html__( 'Author', 'wp-seopress-pro' ),
				'post_date'        => esc_html__( 'Publish date', 'wp-seopress-pro' ),
				'post_updated'     => esc_html__( 'Last update', 'wp-seopress-pro' ),
			),
			'Product meta (WooCommerce)' => array(
				'product_regular_price'  => esc_html__( 'Regular Price', 'wp-seopress-pro' ),
				'product_sale_price'     => esc_html__( 'Sale Price', 'wp-seopress-pro' ),
				'product_price_with_tax' => esc_html__( 'Sales price, including tax', 'wp-seopress-pro' ),
				'product_date_from'      => esc_html__( 'Sale price dates "From"', 'wp-seopress-pro' ),
				'product_date_to'        => esc_html__( 'Sale price dates "To"', 'wp-seopress-pro' ),
				'product_sku'            => esc_html__( 'SKU', 'wp-seopress-pro' ),
				'product_barcode_type'   => esc_html__( 'Product Global Identifier type', 'wp-seopress-pro' ),
				'product_barcode'        => esc_html__( 'Product Global Identifier', 'wp-seopress-pro' ),
				'product_category'       => esc_html__( 'Product category', 'wp-seopress-pro' ),
				'product_stock'          => esc_html__( 'Product availability', 'wp-seopress-pro' ),
			),
			'Custom taxonomy / Product attribute (WooCommerce)' => array(
				'custom_taxonomy' => esc_html__( 'Select your custom taxonomy / product attribute', 'wp-seopress-pro' ),
			),
			'Custom fields'              => array(
				'custom_fields' => esc_html__( 'Select your custom field', 'wp-seopress-pro' ),
			),
		);

		// Custom field.
		$post_meta_value = get_post_meta( $post->ID, '_' . $post_meta_name . '_cf', true );

		$seopress_schemas_cf  = '<select name="' . $post_meta_name . '_cf" class="cf">';
		$seopress_schemas_cf .= '<option value=""></option>';

		foreach ( $seopress_get_custom_fields as $value ) {
			$seopress_schemas_cf .= '<option ' . selected( $value, $post_meta_value, false ) . ' value="' . $value . '">' . $value . '</option>';
		}

		$seopress_schemas_cf .= '</select>';

		// Custom taxonomy.
		$post_meta_value = get_post_meta( $post->ID, '_' . $post_meta_name . '_tax', true );

		$seopress_schemas_tax = '<select name="' . $post_meta_name . '_tax" class="tax">';

		$serviceWpData           = seopress_get_service( 'WordPressData' );
		$seopress_get_taxonomies = array();
		if ( $serviceWpData && method_exists( $serviceWpData, 'getTaxonomies' ) ) {
			$seopress_get_taxonomies = $serviceWpData->getTaxonomies();
		}

		$seopress_schemas_tax .= '<option value=""></option>';
		foreach ( $seopress_get_taxonomies as $key => $value ) {
			$seopress_schemas_tax .= '<option ' . selected( $key, $post_meta_value, false ) . ' value="' . $key . '">' . $key . '</option>';
		}
		$seopress_schemas_tax .= '</select>';

		if ( is_string( $cases ) ) {
			$cases = array( $cases );
		}

		foreach ( $cases as $case ) {
			// LB types list.
			if ( 'lb' === $case ) {
				$post_meta_value = get_post_meta( $post->ID, '_' . $post_meta_name . '_lb', true );

				$seopress_schemas_lb = '<select name="' . $post_meta_name . '_lb" class="lb">';

				foreach ( seopress_lb_types_list() as $type_value => $type_i18n ) {
					$seopress_schemas_lb .= '<option ' . selected( $type_value, $post_meta_value, false ) . ' value="' . $type_value . '">' . $type_i18n . '</option>';
				}
				$seopress_schemas_lb .= '</select>';
			}

			switch ( $case ) {
				case 'default':
					$seopress_schemas_mapping_case['Manual'] = array(
						'manual_global' => esc_html__( 'Manual text', 'wp-seopress-pro' ),
						'manual_single' => esc_html__( 'Manual text on each post', 'wp-seopress-pro' ),
					);

					$post_meta_value = get_post_meta( $post->ID, '_' . $post_meta_name . '_manual_global', true );

					$seopress_schemas_manual_global = '<input type="text" id="' . $post_meta_name . '_manual_global" name="' . $post_meta_name . '_manual_global" class="manual_global" placeholder="' . esc_html__( 'Enter a global value here', 'wp-seopress-pro' ) . '" aria-label="' . __( 'Manual value', 'wp-seopress-pro' ) . '" value="' . $post_meta_value . '" />';

					break;
				case 'lb':
					$seopress_schemas_mapping_case['Manual'] = array(
						'manual_global' => esc_html__( 'Manual text', 'wp-seopress-pro' ),
						'manual_single' => esc_html__( 'Manual text on each post', 'wp-seopress-pro' ),
					);

					$post_meta_value = get_post_meta( $post->ID, '_' . $post_meta_name . '_manual_global', true );

					$seopress_schemas_manual_global = '<input type="text" id="' . $post_meta_name . '_manual_global" name="' . $post_meta_name . '_manual_global" class="manual_global" placeholder="' . esc_html__( 'Enter a global value here', 'wp-seopress-pro' ) . '" aria-label="' . __( 'Manual value', 'wp-seopress-pro' ) . '" value="' . $post_meta_value . '" />';

					// LB types case.
					$seopress_schemas_mapping_case['Local Business'] = array(
						'manual_lb_global' => esc_html__( 'Local Business type', 'wp-seopress-pro' ),
					);

					$post_meta_value = get_post_meta( $post->ID, '_' . $post_meta_name . '_manual_lb_global', true );

					break;
				case 'image':
						$seopress_schemas_mapping_case = array(
							'Select an option' => array( 'none' => esc_html__( 'None', 'wp-seopress-pro' ) ),
							'Site Meta'        => array(
								'knowledge_graph_logo' => esc_html__( 'Knowledge Graph logo (SEO > Social)', 'wp-seopress-pro' ),
							),
							'Post Meta'        => array(
								'post_thumbnail'      => esc_html__( 'Featured image / Product image', 'wp-seopress-pro' ),
								'post_author_picture' => esc_html__( 'Post author picture', 'wp-seopress-pro' ),
							),
							'Custom fields'    => array(
								'custom_fields' => esc_html__( 'Select your custom field', 'wp-seopress-pro' ),
							),
							'Manual'           => array(
								'manual_img_global' => esc_html__( 'Manual Image URL', 'wp-seopress-pro' ),
								'manual_img_library_global' => esc_html__( 'Manual Image from Library', 'wp-seopress-pro' ),
								'manual_img_single' => esc_html__( 'Manual text on each post', 'wp-seopress-pro' ),
							),
						);

						$post_meta_value = get_post_meta( $post->ID, '_' . $post_meta_name . '_manual_img_global', true );

						$seopress_schemas_manual_img_global = '<input type="text" id="' . $post_meta_name . '_manual_img_global" name="' . $post_meta_name . '_manual_img_global" class="manual_img_global" placeholder="' . esc_html__( 'Enter a global value here', 'wp-seopress-pro' ) . '" aria-label="' . __( 'Manual Image URL', 'wp-seopress-pro' ) . '" value="' . $post_meta_value . '" />';

						$post_meta_value  = get_post_meta( $post->ID, '_' . $post_meta_name . '_manual_img_library_global', true );
						$post_meta_value2 = get_post_meta( $post->ID, '_' . $post_meta_name . '_manual_img_library_global_width', true );
						$post_meta_value3 = get_post_meta( $post->ID, '_' . $post_meta_name . '_manual_img_library_global_height', true );

						$seopress_schemas_manual_img_library_global = '<input type="text" id="' . $post_meta_name . '_manual_img_library_global" name="' . $post_meta_name . '_manual_img_library_global" class="manual_img_library_global" placeholder="' . esc_html__( 'Select your global image from the media library', 'wp-seopress-pro' ) . '" aria-label="' . __( 'Manual Image URL', 'wp-seopress-pro' ) . '" value="' . $post_meta_value . '" />

						<input id="' . $post_meta_name . '_manual_img_library_global_width" type="hidden" name="' . $post_meta_name . '_manual_img_library_global_width" class="manual_img_library_global_width" value="' . $post_meta_value2 . '" />

						<input id="' . $post_meta_name . '_manual_img_library_global_height" type="hidden" name="' . $post_meta_name . '_manual_img_library_global_height" class="manual_img_library_global_height" value="' . $post_meta_value3 . '" />

						<input id="' . $post_meta_name . '_manual_img_library_global_btn" class="btn btnSecondary manual_img_library_global" type="button" value="' . esc_html__( 'Upload an Image', 'wp-seopress-pro' ) . '" />';

					break;
				case 'events':
						// Events Calendar.
					if ( is_plugin_active( 'the-events-calendar/the-events-calendar.php' ) ) {
						$seopress_schemas_mapping_case['Events Calendar'] = array(
							'events_start_date'          => esc_html__( 'Start date', 'wp-seopress-pro' ),
							'events_start_date_timezone' => esc_html__( 'Timezone start date', 'wp-seopress-pro' ),
							'events_start_time'          => esc_html__( 'Start time', 'wp-seopress-pro' ),
							'events_end_date'            => esc_html__( 'End date', 'wp-seopress-pro' ),
							'events_end_time'            => esc_html__( 'End time', 'wp-seopress-pro' ),
							'events_location_name'       => esc_html__( 'Event location name', 'wp-seopress-pro' ),
							'events_location_address'    => esc_html__( 'Event location address', 'wp-seopress-pro' ),
							'events_website'             => esc_html__( 'Event website', 'wp-seopress-pro' ),
							'events_cost'                => esc_html__( 'Event cost', 'wp-seopress-pro' ),
							'events_currency'            => esc_html__( 'Event currency', 'wp-seopress-pro' ),
						);
					}

					break;
				case 'date':
						// Date case.
						$seopress_schemas_mapping_case['Manual'] = array(
							'manual_date_global' => esc_html__( 'Manual date', 'wp-seopress-pro' ),
							'manual_date_single' => esc_html__( 'Manual date on each post', 'wp-seopress-pro' ),
						);

						$post_meta_value = get_post_meta( $post->ID, '_' . $post_meta_name . '_manual_date_global', true );

						$seopress_schemas_manual_date_global = '<input type="text" class="seopress-date-picker manual_date_global" autocomplete="false" name="' . $post_meta_name . '_manual_date_global" class="manual_global" placeholder="' . esc_html__( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' ) . '" aria-label="' . esc_html__( 'Global date', 'wp-seopress-pro' ) . '" value="' . $post_meta_value . '" />';
					break;
				case 'time':
						// Time case.
						$seopress_schemas_mapping_case['Manual'] = array(
							'manual_time_global' => esc_html__( 'Manual time', 'wp-seopress-pro' ),
							'manual_time_single' => esc_html__( 'Manual time on each post', 'wp-seopress-pro' ),
						);

						$post_meta_value = get_post_meta( $post->ID, '_' . $post_meta_name . '_manual_time_global', true );

						$seopress_schemas_manual_time_global = '<input type="time" step="2" placeholder="' . esc_html__( 'HH:MM', 'wp-seopress-pro' ) . '" id="' . $post_meta_name . '_manual_time_global" name="' . $post_meta_name . '_manual_time_global" class="manual_time_global" aria-label="' . esc_html__( 'Time', 'wp-seopress-pro' ) . '" value="' . $post_meta_value . '" />';
					break;
				case 'rating':
						// Rating case.
						$seopress_schemas_mapping_case['Manual'] = array(
							'manual_rating_global' => esc_html__( 'Manual rating', 'wp-seopress-pro' ),
							'manual_rating_single' => esc_html__( 'Manual rating on each post', 'wp-seopress-pro' ),
						);

						$post_meta_value = get_post_meta( $post->ID, '_' . $post_meta_name . '_manual_rating_global', true );

						$seopress_schemas_manual_rating_global = '<input type="number" id="' . $post_meta_name . '_manual_rating_global" name="' . $post_meta_name . '_manual_rating_global" min="1" step="0.1" class="manual_rating_global" aria-label="' . esc_html__( 'Rating', 'wp-seopress-pro' ) . '" value="' . $post_meta_value . '" />';
					break;
				case 'custom':
						// Custom case.
						$seopress_schemas_mapping_case           = array();
						$seopress_schemas_mapping_case['custom'] = array(
							'manual_custom_global' => esc_html__( 'Manual custom schema', 'wp-seopress-pro' ),
							'manual_custom_single' => esc_html__( 'Manual custom schema on each post', 'wp-seopress-pro' ),
						);

						$post_meta_value = get_post_meta( $post->ID, '_' . $post_meta_name . '_manual_custom_global', true );

						$seopress_schemas_manual_custom_global = '<textarea rows="25" id="' . $post_meta_name . '_manual_custom_global" name="' . $post_meta_name . '_manual_custom_global" class="manual_custom_global" aria-label="' . __( 'Custom schema', 'wp-seopress-pro' ) . '" value="' . htmlspecialchars( $post_meta_value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) . '" placeholder="' . esc_html__(
							'e.g. <script type="application/ld+json">{
                            "@context": "https://schema.org/",
                            "@type": "Review",
                            "itemReviewed": {
                            "@type": "Restaurant",
                            "image": "http://www.example.com/seafood-restaurant.jpg",
                            "name": "Legal Seafood",
                            "servesCuisine": "Seafood",
                            "telephone": "1234567",
                            "address" :{
                                "@type": "PostalAddress",
                                "streetAddress": "123 William St",
                                "addressLocality": "New York",
                                "addressRegion": "NY",
                                "postalCode": "10038",
                                "addressCountry": "US"
                            }
                            },
                            "reviewRating": {
                            "@type": "Rating",
                            "ratingValue": "4"
                            },
                            "name": "A good seafood place.",
                            "author": {
                            "@type": "Person",
                            "name": "Bob Smith"
                            },
                            "reviewBody": "The seafood is great.",
                            "publisher": {
                            "@type": "Organization",
                            "name": "Washington Times"
                            }
                        }</script>',
							'wp-seopress-pro'
						) . '">' . htmlspecialchars( $post_meta_value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) . '</textarea>';
					break;
			}
		}

		$post_meta_value = get_post_meta( $post->ID, '_' . $post_meta_name, true );

		$seopress_schemas_mapping_case = apply_filters( 'seopress_schemas_mapping_select', $seopress_schemas_mapping_case );

		$html = '<select name="' . $post_meta_name . '" class="dyn">';
		foreach ( $seopress_schemas_mapping_case as $key => $value ) {
			$html .= '<optgroup label="' . $key . '">';
			foreach ( $value as $_key => $_value ) {
				$html .= '<option ' . selected( $_key, $post_meta_value, false ) . ' value="' . $_key . '">' . $_value . '</option>';
			}
			$html .= '</optgroup>';
		}
		$html .= '</select>';

		if ( isset( $seopress_schemas_manual_global ) ) {
			$html .= $seopress_schemas_manual_global;
		}
		if ( isset( $seopress_schemas_manual_img_global ) ) {
			$html .= $seopress_schemas_manual_img_global;
		}
		if ( isset( $seopress_schemas_manual_img_library_global ) ) {
			$html .= $seopress_schemas_manual_img_library_global;
		}
		if ( isset( $seopress_schemas_manual_date_global ) ) {
			$html .= $seopress_schemas_manual_date_global;
		}
		if ( isset( $seopress_schemas_manual_time_global ) ) {
			$html .= $seopress_schemas_manual_time_global;
		}
		if ( isset( $seopress_schemas_manual_rating_global ) ) {
			$html .= $seopress_schemas_manual_rating_global;
		}
		if ( isset( $seopress_schemas_cf ) && 'custom' != $case ) {
			$html .= $seopress_schemas_cf;
		}
		if ( isset( $seopress_schemas_tax ) && 'custom' != $case ) {
			$html .= $seopress_schemas_tax;
		}
		if ( isset( $seopress_schemas_lb ) && 'custom' != $case ) {
			$html .= $seopress_schemas_lb;
		}
		if ( isset( $seopress_schemas_manual_custom_global ) ) {
			$html .= $seopress_schemas_manual_custom_global;
		}

		return $html;
	}
	$docs = function_exists( 'seopress_get_docs_links' ) ? seopress_get_docs_links() : '';

	// Get datas.
	$seopress_pro_rich_snippets_type = get_post_meta( $post->ID, '_seopress_pro_rich_snippets_type', true );

	// Article.
	$seopress_pro_rich_snippets_article_type = get_post_meta( $post->ID, '_seopress_pro_rich_snippets_article_type', true );

	// Local Business.
	// Rebuild the structure the template indexes as
	// $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $day ], from
	// whichever shape is stored, so schemas saved with the React editor also
	// display here.
	$seopress_pro_rich_snippets_lb_opening_hours_days = seopress_pro_get_lb_opening_hours_days( get_post_meta( $post->ID, '_seopress_pro_rich_snippets_lb_opening_hours', true ) );

	$seopress_pro_rich_snippets_lb_opening_hours = array(
		array(
			'seopress_pro_rich_snippets_lb_opening_hours' => is_array( $seopress_pro_rich_snippets_lb_opening_hours_days ) ? $seopress_pro_rich_snippets_lb_opening_hours_days : array(),
		),
	);

	$seopress_pro_rich_snippets_lb_opening_hours_source = get_post_meta( $post->ID, '_seopress_pro_rich_snippets_lb_opening_hours_source', true );
	?>
<tr id="term-seopress" class="form-field">
	<td>
		<div id="seopress_pro_cpt" class="seopress-your-schema">
			<div class="inside">
				<div id="seopress-your-schema">
					<div class="box-left">
						<div class="wrap-rich-snippets-type">
							<p>
								<strong>
									<?php esc_html_e( 'Schema Type', 'wp-seopress-pro' ); ?>
								</strong>
								<span class="description"><?php esc_html_e( 'Select the type of structured data you want to add to your content', 'wp-seopress-pro' ); ?></span>
							</p>
							<div class="seopress-schema-type-grid">
							<?php
							$schema_types = array(
								array(
									'value' => 'none',
									'label' => esc_html__( 'None', 'wp-seopress-pro' ),
									'icon'  => 'dashicons-no',
									'desc'  => esc_html__( 'No schema will be added.', 'wp-seopress-pro' ),
								),
								array(
									'value' => 'articles',
									'label' => esc_html__( 'Article (WebPage)', 'wp-seopress-pro' ),
									'icon'  => 'dashicons-media-text',
									'desc'  => esc_html__( 'For blog posts, news, and articles.', 'wp-seopress-pro' ),
								),
								array(
									'value' => 'localbusiness',
									'label' => esc_html__( 'Local Business', 'wp-seopress-pro' ),
									'icon'  => 'dashicons-store',
									'desc'  => esc_html__( 'For local businesses and organizations.', 'wp-seopress-pro' ),
								),
								array(
									'value' => 'faq',
									'label' => esc_html__( 'FAQ', 'wp-seopress-pro' ),
									'icon'  => 'dashicons-editor-help',
									'desc'  => esc_html__( 'For frequently asked questions.', 'wp-seopress-pro' ),
									'deprecated' => true,
								),
								array(
									'value'      => 'courses',
									'label'      => esc_html__( 'Course', 'wp-seopress-pro' ),
									'icon'       => 'dashicons-welcome-learn-more',
									'desc'       => esc_html__( 'For educational courses.', 'wp-seopress-pro' ),
									'deprecated' => true,
								),
								array(
									'value' => 'recipes',
									'label' => esc_html__( 'Recipe', 'wp-seopress-pro' ),
									'icon'  => 'dashicons-carrot',
									'desc'  => esc_html__( 'For food and recipe content.', 'wp-seopress-pro' ),
								),
								array(
									'value' => 'jobs',
									'label' => esc_html__( 'Job', 'wp-seopress-pro' ),
									'icon'  => 'dashicons-businessman',
									'desc'  => esc_html__( 'For job postings.', 'wp-seopress-pro' ),
								),
								array(
									'value' => 'videos',
									'label' => esc_html__( 'Video', 'wp-seopress-pro' ),
									'icon'  => 'dashicons-format-video',
									'desc'  => esc_html__( 'For video content.', 'wp-seopress-pro' ),
								),
								array(
									'value' => 'events',
									'label' => esc_html__( 'Event', 'wp-seopress-pro' ),
									'icon'  => 'dashicons-calendar-alt',
									'desc'  => esc_html__( 'For events and happenings.', 'wp-seopress-pro' ),
								),
								array(
									'value' => 'products',
									'label' => esc_html__( 'Product', 'wp-seopress-pro' ),
									'icon'  => 'dashicons-cart',
									'desc'  => esc_html__( 'For products and e-commerce.', 'wp-seopress-pro' ),
								),
								array(
									'value' => 'services',
									'label' => esc_html__( 'Service', 'wp-seopress-pro' ),
									'icon'  => 'dashicons-hammer',
									'desc'  => esc_html__( 'For service offerings.', 'wp-seopress-pro' ),
								),
								array(
									'value' => 'softwareapp',
									'label' => esc_html__( 'Software Application', 'wp-seopress-pro' ),
									'icon'  => 'dashicons-admin-generic',
									'desc'  => esc_html__( 'For software and apps.', 'wp-seopress-pro' ),
								),
								array(
									'value' => 'review',
									'label' => esc_html__( 'Review', 'wp-seopress-pro' ),
									'icon'  => 'dashicons-star-filled',
									'desc'  => esc_html__( 'For reviews and ratings.', 'wp-seopress-pro' ),
								),
								array(
									'value' => 'custom',
									'label' => esc_html__( 'Custom', 'wp-seopress-pro' ),
									'icon'  => 'dashicons-editor-code',
									'desc'  => esc_html__( 'Add your own custom schema.', 'wp-seopress-pro' ),
								),
							);
							$selected     = isset( $seopress_pro_rich_snippets_type ) ? $seopress_pro_rich_snippets_type : 'none';
							foreach ( $schema_types as $type ) :
								?>
								<label class="seopress-schema-card
								<?php
								if ( $selected == $type['value'] ) {
									echo ' selected';}
								if ( ! empty( $type['deprecated'] ) ) {
									echo ' seopress-schema-deprecated';}
								?>
								">
									<input type="radio" name="seopress_pro_rich_snippets_type" value="<?php echo esc_attr( $type['value'] ); ?>" <?php checked( $selected, $type['value'] ); ?> />
									<span class="dashicons <?php echo esc_attr( $type['icon'] ); ?>"></span>
									<div class="schema-content">
										<span class="schema-title">
											<?php echo esc_html( $type['label'] ); ?>
											<?php if ( ! empty( $type['deprecated'] ) ) { ?>
												<span class="seopress-badge-deprecated"><?php esc_html_e( 'Deprecated', 'wp-seopress-pro' ); ?></span>
											<?php } ?>
										</span>
										<span class="schema-desc"><?php echo esc_html( $type['desc'] ); ?></span>
									</div>
								</label>
							<?php endforeach; ?>
							</div>
						</div>
						<div class="wrap-rich-snippets-rules schema-steps">
							<p>
								<label for="seopress_pro_rich_snippets_rules_meta"><?php esc_html_e( 'Show this schema if your singular post, page or post type has:', 'wp-seopress-pro' ); ?></label>
								<?php
								$_id_name_for                = 'seopress_pro_rich_snippets_rules';
								$snippets_rules              = get_post_meta( $post->ID, '_seopress_pro_rich_snippets_rules', true );
								$_available_rules_filters    = seopress_get_schemas_filters();
								$_available_rules_conditions = seopress_get_schemas_conditions();
								// Retrocompat < 3.8.2.
								if ( ! is_array( $snippets_rules ) || empty( $snippets_rules ) ) {
									$snippets_rules = seopress_get_default_schemas_rules( $snippets_rules );
								}
								$_g = 0;
								foreach ( $snippets_rules as $_group => $_rules ) {
									$_group = $_g++;
									$_n     = 0;
									echo '<div data-group="' . $_group . '">';
									foreach ( $_rules as $_index => $_rule ) {
										$_index = $_n++;

										echo '<p data-group="' . $_group . '">';

										// Filters.
										echo "\t<select id=\"{$_id_name_for}[g{$_group}][i{$_index}][filter]\" name=\"{$_id_name_for}[g{$_group}][i{$_index}][filter]\" class=\"small-text\">\n";
										foreach ( $_available_rules_filters as $_filter => $_filter_label ) {
											echo "\t\t" . '<option value="' . $_filter . '" ' . selected( $_rule['filter'], $_filter, false ) . '>' . $_filter_label . '</option>' . "\n";
										}
										echo '</select>';

										// Condition.
										echo "\t<select id=\"{$_id_name_for}[g{$_group}][i{$_index}][cond]\" name=\"{$_id_name_for}[g{$_group}][i{$_index}][cond]\" class=\"small-text\">\n";
										foreach ( $_available_rules_conditions as $_cond => $_cond_label ) {
											echo "\t\t" . '<option value="' . $_cond . '" ' . selected( $_rule['cond'], $_cond, false ) . '>' . $_cond_label . '</option>' . "\n";
										}
										echo '</select>';

										// CPT.
										$class = 'post_type' === $_rule['filter'] ? '' : 'hidden';
										echo "\t<select id=\"{$_id_name_for}[g{$_group}][i{$_index}][cpt]\" name=\"{$_id_name_for}[g{$_group}][i{$_index}][cpt]\" class=\"{$class}\">\n";
										$postTypes = seopress_get_service( 'WordPressData' )->getPostTypes();
										foreach ( $postTypes as $_cpt_slug => $_post_type_obj ) {
											echo "\t\t" . '<option ' . selected( $_rule['cpt'], $_cpt_slug, false ) . ' value="' . $_cpt_slug . '">' . $_post_type_obj->labels->name . '</option>' . "\n";
										}
										echo '</select>';

										// TAXO.
										$class = 'taxonomy' === $_rule['filter'] ? '' : 'hidden';
										echo "\t<select id=\"{$_id_name_for}[g{$_group}][i{$_index}][taxo]\" name=\"{$_id_name_for}[g{$_group}][i{$_index}][taxo]\" class=\"{$class}\">\n";
										foreach ( seopress_get_service( 'WordPressData' )->getTaxonomies( true ) as $_tax_slug => $_tax ) {
											echo "\t\t" . '<optgroup label="' . $_tax->label . '">' . "\n";
											if ( isset( $_tax->terms ) ) { // Free version is up to date.
												foreach ( $_tax->terms as $_term ) {
													echo "\t\t" . '<option ' . selected( $_rule['taxo'], $_term->term_id, false ) . ' value="' . $_term->term_id . '">' . esc_html( $_term->name ) . '</option>' . "\n";
												}
											}
											echo '</optgroup>';
										}
										echo '</select>';

										// INPUT.
										$class       = 'postId' === $_rule['filter'] ? '' : 'hidden';
										$valuePostId = isset( $_rule['postId'] ) ? $_rule['postId'] : '';
										echo "\t<input type=\"text\" id=\"{$_id_name_for}[g{$_group}][i{$_index}][postId]\" name=\"{$_id_name_for}[g{$_group}][i{$_index}][postId]\" class=\"{$class}\" value=\"{$valuePostId}\" />\n";

										// Buttons.
										echo ' <span class="dashicons dashicons-plus-alt ' . $_id_name_for . '_and" data-group="' . $_group . '"></span>';
										echo ' <span class="hidden dashicons dashicons-no-alt ' . $_id_name_for . '_del" data-group="' . $_group . '"></span>';

										echo '</p>';
									}
									echo '</div>';
									echo '<p class="separat_or"><strong>' . esc_html__( 'or', 'wp-seopress-pro' ) . '</strong></p>';
								}
								?>
							<p>
								<button type="button" class="button button-secondary"
									id="<?php echo $_id_name_for; ?>_add">
									<?php esc_html_e( 'Add a rule', 'wp-seopress-pro' ); ?>
								</button>
							</p>
						</div>
						<p class="seopress-schema-mapping-label">
							<label><?php esc_html_e( 'Map all schema properties to a value:', 'wp-seopress-pro' ); ?></label>
						</p>

						<?php
							require_once __DIR__ . '/automatic/Article.php';
						require_once __DIR__ . '/automatic/LocalBusiness.php';
						require_once __DIR__ . '/automatic/Faq.php';
						require_once __DIR__ . '/automatic/Course.php';
						require_once __DIR__ . '/automatic/Recipe.php';
						require_once __DIR__ . '/automatic/Job.php';
						require_once __DIR__ . '/automatic/Video.php';
						require_once __DIR__ . '/automatic/Event.php';
						require_once __DIR__ . '/automatic/Product.php';
						require_once __DIR__ . '/automatic/SoftwareApp.php';
						require_once __DIR__ . '/automatic/Service.php';
						require_once __DIR__ . '/automatic/Review.php';
						require_once __DIR__ . '/automatic/Custom.php';
						?>
					</div>
					<div class="seopress-notice seopress-help">
						<h3><?php esc_html_e( 'Common issues', 'wp-seopress-pro' ); ?></h3>
						<p>
							<?php
								// translators: %s documentation URL.
								printf( __( 'How to add your own <a href="%s" target="_blank">predefined dynamic variables</a>.', 'wp-seopress-pro' ), esc_url( $docs['schemas']['variables'] ) );
							?>
							<span class="seopress-help dashicons dashicons-external"></span>
						</p>
						<hr>
						<p>
							<?php
								// translators: %s documentation URL.
								printf( __( 'I don’t see <a href="%s" target="_blank">all my custom fields from the list</a>!', 'wp-seopress-pro' ), esc_url( $docs['schemas']['custom_fields'] ) );
							?>
							<span class="seopress-help dashicons dashicons-external"></span>
						</p>
					</div>
				</div>
			</div>
		</div>
	</td>
</tr>

	<?php

	global $pagenow;

	if ( isset( $pagenow ) && 'post-new.php' === $pagenow ) :

		?>

<script>
	document.addEventListener('DOMContentLoaded', function() {
		const $ = jQuery

		$("input[name='seopress_pro_rich_snippets_type']").on("change", function(e) {
			console.log("change");
			const val = $(this).val()
			if (val !== "products") {
				return;
			}

			$("select[name='seopress_pro_rich_snippets_product_name']").val("post_title")
			$("select[name='seopress_pro_rich_snippets_product_description']").val("post_excerpt")
			$("select[name='seopress_pro_rich_snippets_product_img']").val("post_thumbnail")
			$("select[name='seopress_pro_rich_snippets_product_price']").val(
				"product_regular_price")
			$("select[name='seopress_pro_rich_snippets_product_sku']").val("product_sku")
			$("select[name='seopress_pro_rich_snippets_product_price_valid_date']").val(
				"product_date_to")
			$("select[name='seopress_pro_rich_snippets_product_global_ids']").val(
				"product_barcode_type")
			$("select[name='seopress_pro_rich_snippets_product_global_ids_value']").val(
				"product_barcode")
			$("select[name='seopress_pro_rich_snippets_product_availability']").val("product_stock")
		})
	})
</script>
		<?php
	endif;
}

/**
 * Save inputs schema automatic.
 *
 * @param array $inputs The inputs.
 * @param int   $postId The post ID.
 *
 * @return void
 */
function seopress_save_inputs_schema_automatic( $inputs, $postId ) {
	foreach ( $inputs as $key => $item ) {
		if ( isset( $_POST[ $key ] ) ) {
			if ( $_POST[ $key ] === 'none' ) {
				delete_post_meta( $postId, $item['key'] );
			} elseif ( $item['key'] === '_seopress_pro_rich_snippets_lb_opening_hours' ) {
				update_post_meta( $postId, $item['key'], $_POST[ $key ] );
			} elseif ( $_POST[ $key ] === '' || $_POST[ $key ] === null ) {
				delete_post_meta( $postId, $item['key'] );
			} else {
				update_post_meta( $postId, $item['key'], esc_html( $_POST[ $key ] ) );
			}
		} else {
			delete_post_meta( $postId, $item['key'] );
		}
	}
}

/**
 * Save schema metabox.
 *
 * @param int    $post_id Post ID.
 * @param object $post Post object.
 *
 * @return int Post ID.
 */
function seopress_schemas_save_metabox( $post_id, $post ) {
	// Nonce.
	if ( ! isset( $_POST['seopress_schemas_cpt_nonce'] ) || ! wp_verify_nonce(
		$_POST['seopress_schemas_cpt_nonce'],
		plugin_basename( __FILE__ )
	) ) {
		return $post_id;
	}

	// Post type object.
	$post_type = get_post_type_object( $post->post_type );

	// Check permission.
	if ( ! current_user_can( 'edit_schemas', $post_id ) ) {
		return $post_id;
	}

	if ( isset( $_POST['seopress_pro_rich_snippets_rules'] ) ) {
		update_post_meta( $post_id, '_seopress_pro_rich_snippets_rules', $_POST['seopress_pro_rich_snippets_rules'] );
	}
	if ( isset( $_POST['seopress_pro_rich_snippets_type'] ) ) {
		update_post_meta( $post_id, '_seopress_pro_rich_snippets_type', esc_html( $_POST['seopress_pro_rich_snippets_type'] ) );
	}

	// Article.
	$inputsArticle = array(
		'seopress_pro_rich_snippets_article_type'          => array(
			'key' => '_seopress_pro_rich_snippets_article_type',
		),
		'seopress_pro_rich_snippets_article_title'         => array(
			'key' => '_seopress_pro_rich_snippets_article_title',
		),
		'seopress_pro_rich_snippets_article_title_cf'      => array(
			'key' => '_seopress_pro_rich_snippets_article_title_cf',
		),
		'seopress_pro_rich_snippets_article_title_tax'     => array(
			'key' => '_seopress_pro_rich_snippets_article_title_tax',
		),
		'seopress_pro_rich_snippets_article_title_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_article_title_manual_global',
		),
		'seopress_pro_rich_snippets_article_desc'          => array(
			'key' => '_seopress_pro_rich_snippets_article_desc',
		),
		'seopress_pro_rich_snippets_article_desc_cf'       => array(
			'key' => '_seopress_pro_rich_snippets_article_desc_cf',
		),
		'seopress_pro_rich_snippets_article_desc_tax'      => array(
			'key' => '_seopress_pro_rich_snippets_article_desc_tax',
		),
		'seopress_pro_rich_snippets_article_desc_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_article_desc_manual_global',
		),
		'seopress_pro_rich_snippets_article_author'        => array(
			'key' => '_seopress_pro_rich_snippets_article_author',
		),
		'seopress_pro_rich_snippets_article_author_cf'     => array(
			'key' => '_seopress_pro_rich_snippets_article_author_cf',
		),
		'seopress_pro_rich_snippets_article_author_tax'    => array(
			'key' => '_seopress_pro_rich_snippets_article_author_tax',
		),
		'seopress_pro_rich_snippets_article_author_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_article_author_manual_global',
		),
		'seopress_pro_rich_snippets_article_img'           => array(
			'key' => '_seopress_pro_rich_snippets_article_img',
		),
		'seopress_pro_rich_snippets_article_img_manual_img_global' => array(
			'key' => '_seopress_pro_rich_snippets_article_img_manual_img_global',
		),
		'seopress_pro_rich_snippets_article_img_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_article_img_cf',
		),
		'seopress_pro_rich_snippets_article_img_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_article_img_tax',
		),
		'seopress_pro_rich_snippets_article_img_manual_img_library_global' => array(
			'key' => '_seopress_pro_rich_snippets_article_img_manual_img_library_global',
		),
		'seopress_pro_rich_snippets_article_img_manual_img_library_global_width' => array(
			'key' => '_seopress_pro_rich_snippets_article_img_manual_img_library_global_width',
		),
		'seopress_pro_rich_snippets_article_img_manual_img_library_global_height' => array(
			'key' => '_seopress_pro_rich_snippets_article_img_manual_img_library_global_height',
		),
		'seopress_pro_rich_snippets_article_coverage_start_date' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_start_date',
		),
		'seopress_pro_rich_snippets_article_coverage_start_date_cf' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_start_date_cf',
		),
		'seopress_pro_rich_snippets_article_coverage_start_date_tax' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_start_date_tax',
		),
		'seopress_pro_rich_snippets_article_coverage_start_date_manual_date_global' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_start_date_manual_date_global',
		),
		'seopress_pro_rich_snippets_article_coverage_start_time' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_start_time',
		),
		'seopress_pro_rich_snippets_article_coverage_start_time_cf' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_start_time_cf',
		),
		'seopress_pro_rich_snippets_article_coverage_start_time_tax' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_start_time_tax',
		),
		'seopress_pro_rich_snippets_article_coverage_start_time_manual_time_global' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_start_time_manual_time_global',
		),
		'seopress_pro_rich_snippets_article_coverage_end_date' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_end_date',
		),
		'seopress_pro_rich_snippets_article_coverage_end_date_cf' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_end_date_cf',
		),
		'seopress_pro_rich_snippets_article_coverage_end_date_tax' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_end_date_tax',
		),
		'seopress_pro_rich_snippets_article_coverage_end_date_manual_date_global' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_end_date_manual_date_global',
		),
		'seopress_pro_rich_snippets_article_coverage_end_time' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_end_time',
		),
		'seopress_pro_rich_snippets_article_coverage_end_time_cf' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_end_time_cf',
		),
		'seopress_pro_rich_snippets_article_coverage_end_time_tax' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_end_time_tax',
		),
		'seopress_pro_rich_snippets_article_coverage_end_time_manual_time_global' => array(
			'key' => '_seopress_pro_rich_snippets_article_coverage_end_time_manual_time_global',
		),
		'seopress_pro_rich_snippets_article_speakable'     => array(
			'key' => '_seopress_pro_rich_snippets_article_speakable',
		),
		'seopress_pro_rich_snippets_article_speakable_cf'  => array(
			'key' => '_seopress_pro_rich_snippets_article_speakable_cf',
		),
		'seopress_pro_rich_snippets_article_speakable_tax' => array(
			'key' => '_seopress_pro_rich_snippets_article_speakable_tax',
		),
		'seopress_pro_rich_snippets_article_speakable_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_article_speakable_manual_global',
		),
	);

	seopress_save_inputs_schema_automatic( $inputsArticle, $post_id );

	// Local Business.
	$inputsLocalBusiness = array(
		'seopress_pro_rich_snippets_lb_name'               => array(
			'key' => '_seopress_pro_rich_snippets_lb_name',
		),
		'seopress_pro_rich_snippets_lb_name_cf'            => array(
			'key' => '_seopress_pro_rich_snippets_lb_name_cf',
		),
		'seopress_pro_rich_snippets_lb_name_tax'           => array(
			'key' => '_seopress_pro_rich_snippets_lb_name_tax',
		),
		'seopress_pro_rich_snippets_lb_name_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_lb_name_manual_global',
		),
		'seopress_pro_rich_snippets_lb_type'               => array(
			'key' => '_seopress_pro_rich_snippets_lb_type',
		),
		'seopress_pro_rich_snippets_lb_type_cf'            => array(
			'key' => '_seopress_pro_rich_snippets_lb_type_cf',
		),
		'seopress_pro_rich_snippets_lb_type_tax'           => array(
			'key' => '_seopress_pro_rich_snippets_lb_type_tax',
		),
		'seopress_pro_rich_snippets_lb_type_lb'            => array(
			'key' => '_seopress_pro_rich_snippets_lb_type_lb',
		),
		'seopress_pro_rich_snippets_lb_type_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_lb_type_manual_global',
		),
		'seopress_pro_rich_snippets_lb_img'                => array(
			'key' => '_seopress_pro_rich_snippets_lb_img',
		),
		'seopress_pro_rich_snippets_lb_img_manual_img_global' => array(
			'key' => '_seopress_pro_rich_snippets_lb_img_manual_img_global',
		),
		'seopress_pro_rich_snippets_lb_img_cf'             => array(
			'key' => '_seopress_pro_rich_snippets_lb_img_cf',
		),
		'seopress_pro_rich_snippets_lb_img_tax'            => array(
			'key' => '_seopress_pro_rich_snippets_lb_img_tax',
		),
		'seopress_pro_rich_snippets_lb_img_manual_img_library_global' => array(
			'key' => '_seopress_pro_rich_snippets_lb_img_manual_img_library_global',
		),
		'seopress_pro_rich_snippets_lb_img_manual_img_library_global_width' => array(
			'key' => '_seopress_pro_rich_snippets_lb_img_manual_img_library_global_width',
		),
		'seopress_pro_rich_snippets_lb_img_manual_img_library_global_height' => array(
			'key' => '_seopress_pro_rich_snippets_lb_img_manual_img_library_global_height',
		),
		'seopress_pro_rich_snippets_lb_street_addr'        => array(
			'key' => '_seopress_pro_rich_snippets_lb_street_addr',
		),
		'seopress_pro_rich_snippets_lb_street_addr_cf'     => array(
			'key' => '_seopress_pro_rich_snippets_lb_street_addr_cf',
		),
		'seopress_pro_rich_snippets_lb_street_addr_tax'    => array(
			'key' => '_seopress_pro_rich_snippets_lb_street_addr_tax',
		),
		'seopress_pro_rich_snippets_lb_street_addr_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_lb_street_addr_manual_global',
		),
		'seopress_pro_rich_snippets_lb_city'               => array(
			'key' => '_seopress_pro_rich_snippets_lb_city',
		),
		'seopress_pro_rich_snippets_lb_city_cf'            => array(
			'key' => '_seopress_pro_rich_snippets_lb_city_cf',
		),
		'seopress_pro_rich_snippets_lb_city_tax'           => array(
			'key' => '_seopress_pro_rich_snippets_lb_city_tax',
		),
		'seopress_pro_rich_snippets_lb_city_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_lb_city_manual_global',
		),
		'seopress_pro_rich_snippets_lb_state'              => array(
			'key' => '_seopress_pro_rich_snippets_lb_state',
		),
		'seopress_pro_rich_snippets_lb_state_cf'           => array(
			'key' => '_seopress_pro_rich_snippets_lb_state_cf',
		),
		'seopress_pro_rich_snippets_lb_state_tax'          => array(
			'key' => '_seopress_pro_rich_snippets_lb_state_tax',
		),
		'seopress_pro_rich_snippets_lb_state_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_lb_state_manual_global',
		),
		'seopress_pro_rich_snippets_lb_pc'                 => array(
			'key' => '_seopress_pro_rich_snippets_lb_pc',
		),
		'seopress_pro_rich_snippets_lb_pc_cf'              => array(
			'key' => '_seopress_pro_rich_snippets_lb_pc_cf',
		),
		'seopress_pro_rich_snippets_lb_pc_tax'             => array(
			'key' => '_seopress_pro_rich_snippets_lb_pc_tax',
		),
		'seopress_pro_rich_snippets_lb_pc_manual_global'   => array(
			'key' => '_seopress_pro_rich_snippets_lb_pc_manual_global',
		),
		'seopress_pro_rich_snippets_lb_country'            => array(
			'key' => '_seopress_pro_rich_snippets_lb_country',
		),
		'seopress_pro_rich_snippets_lb_country_cf'         => array(
			'key' => '_seopress_pro_rich_snippets_lb_country_cf',
		),
		'seopress_pro_rich_snippets_lb_country_tax'        => array(
			'key' => '_seopress_pro_rich_snippets_lb_country_tax',
		),
		'seopress_pro_rich_snippets_lb_country_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_lb_country_manual_global',
		),
		'seopress_pro_rich_snippets_lb_lat'                => array(
			'key' => '_seopress_pro_rich_snippets_lb_lat',
		),
		'seopress_pro_rich_snippets_lb_lat_cf'             => array(
			'key' => '_seopress_pro_rich_snippets_lb_lat_cf',
		),
		'seopress_pro_rich_snippets_lb_lat_tax'            => array(
			'key' => '_seopress_pro_rich_snippets_lb_lat_tax',
		),
		'seopress_pro_rich_snippets_lb_lat_manual_global'  => array(
			'key' => '_seopress_pro_rich_snippets_lb_lat_manual_global',
		),
		'seopress_pro_rich_snippets_lb_lon'                => array(
			'key' => '_seopress_pro_rich_snippets_lb_lon',
		),
		'seopress_pro_rich_snippets_lb_lon_cf'             => array(
			'key' => '_seopress_pro_rich_snippets_lb_lon_cf',
		),
		'seopress_pro_rich_snippets_lb_lon_tax'            => array(
			'key' => '_seopress_pro_rich_snippets_lb_lon_tax',
		),
		'seopress_pro_rich_snippets_lb_lon_manual_global'  => array(
			'key' => '_seopress_pro_rich_snippets_lb_lon_manual_global',
		),
		'seopress_pro_rich_snippets_lb_website'            => array(
			'key' => '_seopress_pro_rich_snippets_lb_website',
		),
		'seopress_pro_rich_snippets_lb_website_cf'         => array(
			'key' => '_seopress_pro_rich_snippets_lb_website_cf',
		),
		'seopress_pro_rich_snippets_lb_website_tax'        => array(
			'key' => '_seopress_pro_rich_snippets_lb_website_tax',
		),
		'seopress_pro_rich_snippets_lb_website_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_lb_website_manual_global',
		),
		'seopress_pro_rich_snippets_lb_tel'                => array(
			'key' => '_seopress_pro_rich_snippets_lb_tel',
		),
		'seopress_pro_rich_snippets_lb_tel_cf'             => array(
			'key' => '_seopress_pro_rich_snippets_lb_tel_cf',
		),
		'seopress_pro_rich_snippets_lb_tel_tax'            => array(
			'key' => '_seopress_pro_rich_snippets_lb_tel_tax',
		),
		'seopress_pro_rich_snippets_lb_tel_manual_global'  => array(
			'key' => '_seopress_pro_rich_snippets_lb_tel_manual_global',
		),
		'seopress_pro_rich_snippets_lb_price'              => array(
			'key' => '_seopress_pro_rich_snippets_lb_price',
		),
		'seopress_pro_rich_snippets_lb_price_cf'           => array(
			'key' => '_seopress_pro_rich_snippets_lb_price_cf',
		),
		'seopress_pro_rich_snippets_lb_price_tax'          => array(
			'key' => '_seopress_pro_rich_snippets_lb_price_tax',
		),
		'seopress_pro_rich_snippets_lb_price_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_lb_price_manual_global',
		),
		'seopress_pro_rich_snippets_lb_serves_cuisine'     => array(
			'key' => '_seopress_pro_rich_snippets_lb_serves_cuisine',
		),
		'seopress_pro_rich_snippets_lb_serves_cuisine_cf'  => array(
			'key' => '_seopress_pro_rich_snippets_lb_serves_cuisine_cf',
		),
		'seopress_pro_rich_snippets_lb_serves_cuisine_tax' => array(
			'key' => '_seopress_pro_rich_snippets_lb_serves_cuisine_tax',
		),
		'seopress_pro_rich_snippets_lb_serves_cuisine_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_lb_serves_cuisine_manual_global',
		),
		'seopress_pro_rich_snippets_lb_menu'               => array(
			'key' => '_seopress_pro_rich_snippets_lb_menu',
		),
		'seopress_pro_rich_snippets_lb_menu_cf'            => array(
			'key' => '_seopress_pro_rich_snippets_lb_menu_cf',
		),
		'seopress_pro_rich_snippets_lb_menu_tax'           => array(
			'key' => '_seopress_pro_rich_snippets_lb_menu_tax',
		),
		'seopress_pro_rich_snippets_lb_menu_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_lb_menu_manual_global',
		),
		'seopress_pro_rich_snippets_lb_accepts_reservations' => array(
			'key' => '_seopress_pro_rich_snippets_lb_accepts_reservations',
		),
		'seopress_pro_rich_snippets_lb_accepts_reservations_cf' => array(
			'key' => '_seopress_pro_rich_snippets_lb_accepts_reservations_cf',
		),
		'seopress_pro_rich_snippets_lb_accepts_reservations_tax' => array(
			'key' => '_seopress_pro_rich_snippets_lb_accepts_reservations_tax',
		),
		'seopress_pro_rich_snippets_lb_accepts_reservations_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_lb_accepts_reservations_manual_global',
		),
		'seopress_pro_rich_snippets_lb_opening_hours'      => array(
			'key' => '_seopress_pro_rich_snippets_lb_opening_hours',
		),
		'seopress_pro_rich_snippets_lb_opening_hours_source' => array(
			'key' => '_seopress_pro_rich_snippets_lb_opening_hours_source',
		),
	);

	seopress_save_inputs_schema_automatic( $inputsLocalBusiness, $post_id );

	// FAQ.
	$inputsFaq = array(
		'seopress_pro_rich_snippets_faq_q'               => array(
			'key' => '_seopress_pro_rich_snippets_faq_q',
		),
		'seopress_pro_rich_snippets_faq_q_cf'            => array(
			'key' => '_seopress_pro_rich_snippets_faq_q_cf',
		),
		'seopress_pro_rich_snippets_faq_q_tax'           => array(
			'key' => '_seopress_pro_rich_snippets_faq_q_tax',
		),
		'seopress_pro_rich_snippets_faq_q_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_faq_q_manual_global',
		),
		'seopress_pro_rich_snippets_faq_a'               => array(
			'key' => '_seopress_pro_rich_snippets_faq_a',
		),
		'seopress_pro_rich_snippets_faq_a_cf'            => array(
			'key' => '_seopress_pro_rich_snippets_faq_a_cf',
		),
		'seopress_pro_rich_snippets_faq_a_tax'           => array(
			'key' => '_seopress_pro_rich_snippets_faq_a_tax',
		),
		'seopress_pro_rich_snippets_faq_a_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_faq_a_manual_global',
		),
	);

	seopress_save_inputs_schema_automatic( $inputsFaq, $post_id );

	// Course.
	$inputsCourse = array(
		'seopress_pro_rich_snippets_courses_title'         => array(
			'key' => '_seopress_pro_rich_snippets_courses_title',
		),
		'seopress_pro_rich_snippets_courses_title_cf'      => array(
			'key' => '_seopress_pro_rich_snippets_courses_title_cf',
		),
		'seopress_pro_rich_snippets_courses_title_tax'     => array(
			'key' => '_seopress_pro_rich_snippets_courses_title_tax',
		),
		'seopress_pro_rich_snippets_courses_title_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_courses_title_manual_global',
		),
		'seopress_pro_rich_snippets_courses_desc'          => array(
			'key' => '_seopress_pro_rich_snippets_courses_desc',
		),
		'seopress_pro_rich_snippets_courses_desc_cf'       => array(
			'key' => '_seopress_pro_rich_snippets_courses_desc_cf',
		),
		'seopress_pro_rich_snippets_courses_desc_tax'      => array(
			'key' => '_seopress_pro_rich_snippets_courses_desc_tax',
		),
		'seopress_pro_rich_snippets_courses_desc_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_courses_desc_manual_global',
		),
		'seopress_pro_rich_snippets_courses_school'        => array(
			'key' => '_seopress_pro_rich_snippets_courses_school',
		),
		'seopress_pro_rich_snippets_courses_school_cf'     => array(
			'key' => '_seopress_pro_rich_snippets_courses_school_cf',
		),
		'seopress_pro_rich_snippets_courses_school_tax'    => array(
			'key' => '_seopress_pro_rich_snippets_courses_school_tax',
		),
		'seopress_pro_rich_snippets_courses_school_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_courses_school_manual_global',
		),
		'seopress_pro_rich_snippets_courses_website'       => array(
			'key' => '_seopress_pro_rich_snippets_courses_website',
		),
		'seopress_pro_rich_snippets_courses_website_cf'    => array(
			'key' => '_seopress_pro_rich_snippets_courses_website_cf',
		),
		'seopress_pro_rich_snippets_courses_website_tax'   => array(
			'key' => '_seopress_pro_rich_snippets_courses_website_tax',
		),
		'seopress_pro_rich_snippets_courses_website_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_courses_website_manual_global',
		),
		'seopress_pro_rich_snippets_courses_offers'        => array(
			'key' => '_seopress_pro_rich_snippets_courses_offers',
		),
		'seopress_pro_rich_snippets_courses_offers_cf'     => array(
			'key' => '_seopress_pro_rich_snippets_courses_offers_cf',
		),
		'seopress_pro_rich_snippets_courses_offers_tax'    => array(
			'key' => '_seopress_pro_rich_snippets_courses_offers_tax',
		),
		'seopress_pro_rich_snippets_courses_offers_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_courses_offers_manual_global',
		),
		'seopress_pro_rich_snippets_courses_instances'     => array(
			'key' => '_seopress_pro_rich_snippets_courses_instances',
		),
		'seopress_pro_rich_snippets_courses_instances_cf'  => array(
			'key' => '_seopress_pro_rich_snippets_courses_instances_cf',
		),
		'seopress_pro_rich_snippets_courses_instances_tax' => array(
			'key' => '_seopress_pro_rich_snippets_courses_instances_tax',
		),
		'seopress_pro_rich_snippets_courses_instances_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_courses_instances_manual_global',
		),

	);

	seopress_save_inputs_schema_automatic( $inputsCourse, $post_id );

	// Recipe.
	$inputsRecipe = array(
		'seopress_pro_rich_snippets_recipes_name'          => array(
			'key' => '_seopress_pro_rich_snippets_recipes_name',
		),
		'seopress_pro_rich_snippets_recipes_name_cf'       => array(
			'key' => '_seopress_pro_rich_snippets_recipes_name_cf',
		),
		'seopress_pro_rich_snippets_recipes_name_tax'      => array(
			'key' => '_seopress_pro_rich_snippets_recipes_name_tax',
		),
		'seopress_pro_rich_snippets_recipes_name_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_name_manual_global',
		),
		'seopress_pro_rich_snippets_recipes_desc'          => array(
			'key' => '_seopress_pro_rich_snippets_recipes_desc',
		),
		'seopress_pro_rich_snippets_recipes_desc_cf'       => array(
			'key' => '_seopress_pro_rich_snippets_recipes_desc_cf',
		),
		'seopress_pro_rich_snippets_recipes_desc_tax'      => array(
			'key' => '_seopress_pro_rich_snippets_recipes_desc_tax',
		),
		'seopress_pro_rich_snippets_recipes_desc_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_desc_manual_global',
		),
		'seopress_pro_rich_snippets_recipes_cat'           => array(
			'key' => '_seopress_pro_rich_snippets_recipes_cat',
		),
		'seopress_pro_rich_snippets_recipes_cat_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_recipes_cat_cf',
		),
		'seopress_pro_rich_snippets_recipes_cat_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_recipes_cat_tax',
		),
		'seopress_pro_rich_snippets_recipes_cat_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_cat_manual_global',
		),
		'seopress_pro_rich_snippets_recipes_img'           => array(
			'key' => '_seopress_pro_rich_snippets_recipes_img',
		),
		'seopress_pro_rich_snippets_recipes_img_manual_img_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_img_manual_img_global',
		),
		'seopress_pro_rich_snippets_recipes_img_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_recipes_img_cf',
		),
		'seopress_pro_rich_snippets_recipes_img_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_recipes_img_tax',
		),
		'seopress_pro_rich_snippets_recipes_img_manual_img_library_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_img_manual_img_library_global',
		),
		'seopress_pro_rich_snippets_recipes_img_manual_img_library_global_width' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_img_manual_img_library_global_width',
		),
		'seopress_pro_rich_snippets_recipes_img_manual_img_library_global_height' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_img_manual_img_library_global_height',
		),
		'seopress_pro_rich_snippets_recipes_video'         => array(
			'key' => '_seopress_pro_rich_snippets_recipes_video',
		),
		'seopress_pro_rich_snippets_recipes_video_cf'      => array(
			'key' => '_seopress_pro_rich_snippets_recipes_video_cf',
		),
		'seopress_pro_rich_snippets_recipes_video_tax'     => array(
			'key' => '_seopress_pro_rich_snippets_recipes_video_tax',
		),
		'seopress_pro_rich_snippets_recipes_video_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_video_manual_global',
		),
		'seopress_pro_rich_snippets_recipes_prep_time'     => array(
			'key' => '_seopress_pro_rich_snippets_recipes_prep_time',
		),
		'seopress_pro_rich_snippets_recipes_prep_time_cf'  => array(
			'key' => '_seopress_pro_rich_snippets_recipes_prep_time_cf',
		),
		'seopress_pro_rich_snippets_recipes_prep_time_tax' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_prep_time_tax',
		),
		'seopress_pro_rich_snippets_recipes_prep_time_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_prep_time_manual_global',
		),
		'seopress_pro_rich_snippets_recipes_cook_time'     => array(
			'key' => '_seopress_pro_rich_snippets_recipes_cook_time',
		),
		'seopress_pro_rich_snippets_recipes_cook_time_cf'  => array(
			'key' => '_seopress_pro_rich_snippets_recipes_cook_time_cf',
		),
		'seopress_pro_rich_snippets_recipes_cook_time_tax' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_cook_time_tax',
		),
		'seopress_pro_rich_snippets_recipes_cook_time_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_cook_time_manual_global',
		),
		'seopress_pro_rich_snippets_recipes_calories'      => array(
			'key' => '_seopress_pro_rich_snippets_recipes_calories',
		),
		'seopress_pro_rich_snippets_recipes_calories_cf'   => array(
			'key' => '_seopress_pro_rich_snippets_recipes_calories_cf',
		),
		'seopress_pro_rich_snippets_recipes_calories_tax'  => array(
			'key' => '_seopress_pro_rich_snippets_recipes_calories_tax',
		),
		'seopress_pro_rich_snippets_recipes_calories_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_calories_manual_global',
		),
		'seopress_pro_rich_snippets_recipes_yield'         => array(
			'key' => '_seopress_pro_rich_snippets_recipes_yield',
		),
		'seopress_pro_rich_snippets_recipes_yield_cf'      => array(
			'key' => '_seopress_pro_rich_snippets_recipes_yield_cf',
		),
		'seopress_pro_rich_snippets_recipes_yield_tax'     => array(
			'key' => '_seopress_pro_rich_snippets_recipes_yield_tax',
		),
		'seopress_pro_rich_snippets_recipes_yield_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_yield_manual_global',
		),
		'seopress_pro_rich_snippets_recipes_keywords'      => array(
			'key' => '_seopress_pro_rich_snippets_recipes_keywords',
		),
		'seopress_pro_rich_snippets_recipes_keywords_cf'   => array(
			'key' => '_seopress_pro_rich_snippets_recipes_keywords_cf',
		),
		'seopress_pro_rich_snippets_recipes_keywords_tax'  => array(
			'key' => '_seopress_pro_rich_snippets_recipes_keywords_tax',
		),
		'seopress_pro_rich_snippets_recipes_keywords_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_keywords_manual_global',
		),
		'seopress_pro_rich_snippets_recipes_cuisine'       => array(
			'key' => '_seopress_pro_rich_snippets_recipes_cuisine',
		),
		'seopress_pro_rich_snippets_recipes_cuisine_cf'    => array(
			'key' => '_seopress_pro_rich_snippets_recipes_cuisine_cf',
		),
		'seopress_pro_rich_snippets_recipes_cuisine_tax'   => array(
			'key' => '_seopress_pro_rich_snippets_recipes_cuisine_tax',
		),
		'seopress_pro_rich_snippets_recipes_cuisine_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_cuisine_manual_global',
		),
		'seopress_pro_rich_snippets_recipes_ingredient'    => array(
			'key' => '_seopress_pro_rich_snippets_recipes_ingredient',
		),
		'seopress_pro_rich_snippets_recipes_ingredient_cf' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_ingredient_cf',
		),
		'seopress_pro_rich_snippets_recipes_ingredient_tax' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_ingredient_tax',
		),
		'seopress_pro_rich_snippets_recipes_ingredient_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_ingredient_manual_global',
		),
		'seopress_pro_rich_snippets_recipes_instructions'  => array(
			'key' => '_seopress_pro_rich_snippets_recipes_instructions',
		),
		'seopress_pro_rich_snippets_recipes_instructions_cf' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_instructions_cf',
		),
		'seopress_pro_rich_snippets_recipes_instructions_tax' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_instructions_tax',
		),
		'seopress_pro_rich_snippets_recipes_instructions_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_recipes_instructions_manual_global',
		),
	);

	seopress_save_inputs_schema_automatic( $inputsRecipe, $post_id );

	// Job.
	$inputsJob = array(
		'seopress_pro_rich_snippets_jobs_name'             => array(
			'key' => '_seopress_pro_rich_snippets_jobs_name',
		),
		'seopress_pro_rich_snippets_jobs_name_cf'          => array(
			'key' => '_seopress_pro_rich_snippets_jobs_name_cf',
		),
		'seopress_pro_rich_snippets_jobs_name_tax'         => array(
			'key' => '_seopress_pro_rich_snippets_jobs_name_tax',
		),
		'seopress_pro_rich_snippets_jobs_name_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_name_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_desc'             => array(
			'key' => '_seopress_pro_rich_snippets_jobs_desc',
		),
		'seopress_pro_rich_snippets_jobs_desc_cf'          => array(
			'key' => '_seopress_pro_rich_snippets_jobs_desc_cf',
		),
		'seopress_pro_rich_snippets_jobs_desc_tax'         => array(
			'key' => '_seopress_pro_rich_snippets_jobs_desc_tax',
		),
		'seopress_pro_rich_snippets_jobs_desc_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_desc_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_date_posted'      => array(
			'key' => '_seopress_pro_rich_snippets_jobs_date_posted',
		),
		'seopress_pro_rich_snippets_jobs_date_posted_cf'   => array(
			'key' => '_seopress_pro_rich_snippets_jobs_date_posted_cf',
		),
		'seopress_pro_rich_snippets_jobs_date_posted_tax'  => array(
			'key' => '_seopress_pro_rich_snippets_jobs_date_posted_tax',
		),
		'seopress_pro_rich_snippets_jobs_date_posted_manual_date_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_date_posted_manual_date_global',
		),
		'seopress_pro_rich_snippets_jobs_valid_through'    => array(
			'key' => '_seopress_pro_rich_snippets_jobs_valid_through',
		),
		'seopress_pro_rich_snippets_jobs_valid_through_cf' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_valid_through_cf',
		),
		'seopress_pro_rich_snippets_jobs_valid_through_tax' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_valid_through_tax',
		),
		'seopress_pro_rich_snippets_jobs_valid_through_manual_date_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_valid_through_manual_date_global',
		),
		'seopress_pro_rich_snippets_jobs_employment_type'  => array(
			'key' => '_seopress_pro_rich_snippets_jobs_employment_type',
		),
		'seopress_pro_rich_snippets_jobs_employment_type_cf' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_employment_type_cf',
		),
		'seopress_pro_rich_snippets_jobs_employment_type_tax' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_employment_type_tax',
		),
		'seopress_pro_rich_snippets_jobs_employment_type_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_employment_type_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_identifier_name'  => array(
			'key' => '_seopress_pro_rich_snippets_jobs_identifier_name',
		),
		'seopress_pro_rich_snippets_jobs_identifier_name_cf' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_identifier_name_cf',
		),
		'seopress_pro_rich_snippets_jobs_identifier_name_tax' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_identifier_name_tax',
		),
		'seopress_pro_rich_snippets_jobs_identifier_name_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_identifier_name_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_identifier_value' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_identifier_value',
		),
		'seopress_pro_rich_snippets_jobs_identifier_value_cf' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_identifier_value_cf',
		),
		'seopress_pro_rich_snippets_jobs_identifier_value_tax' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_identifier_value_tax',
		),
		'seopress_pro_rich_snippets_jobs_identifier_value_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_identifier_value_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_hiring_organization' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_organization',
		),
		'seopress_pro_rich_snippets_jobs_hiring_organization_cf' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_organization_cf',
		),
		'seopress_pro_rich_snippets_jobs_hiring_organization_tax' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_organization_tax',
		),
		'seopress_pro_rich_snippets_jobs_hiring_organization_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_organization_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_hiring_same_as'   => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_same_as',
		),
		'seopress_pro_rich_snippets_jobs_hiring_same_as_cf' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_same_as_cf',
		),
		'seopress_pro_rich_snippets_jobs_hiring_same_as_tax' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_same_as_tax',
		),
		'seopress_pro_rich_snippets_jobs_hiring_same_as_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_same_as_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_hiring_logo'      => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_logo',
		),
		'seopress_pro_rich_snippets_jobs_hiring_logo_manual_img_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_logo_manual_img_global',
		),
		'seopress_pro_rich_snippets_jobs_hiring_logo_cf'   => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_logo_cf',
		),
		'seopress_pro_rich_snippets_jobs_hiring_logo_tax'  => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_logo_tax',
		),
		'seopress_pro_rich_snippets_jobs_hiring_logo_manual_img_library_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_logo_manual_img_library_global',
		),
		'seopress_pro_rich_snippets_jobs_hiring_logo_manual_img_library_global_width' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_logo_manual_img_library_global_width',
		),
		'seopress_pro_rich_snippets_jobs_hiring_logo_manual_img_library_global_height' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_hiring_logo_manual_img_library_global_height',
		),
		'seopress_pro_rich_snippets_jobs_address_street'   => array(
			'key' => '_seopress_pro_rich_snippets_jobs_address_street',
		),
		'seopress_pro_rich_snippets_jobs_address_street_cf' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_address_street_cf',
		),
		'seopress_pro_rich_snippets_jobs_address_street_tax' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_address_street_tax',
		),
		'seopress_pro_rich_snippets_jobs_address_street_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_address_street_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_address_locality' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_address_locality',
		),
		'seopress_pro_rich_snippets_jobs_address_locality_cf' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_address_locality_cf',
		),
		'seopress_pro_rich_snippets_jobs_address_locality_tax' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_address_locality_tax',
		),
		'seopress_pro_rich_snippets_jobs_address_locality_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_address_locality_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_address_region'   => array(
			'key' => '_seopress_pro_rich_snippets_jobs_address_region',
		),
		'seopress_pro_rich_snippets_jobs_address_region_cf' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_address_region_cf',
		),
		'seopress_pro_rich_snippets_jobs_address_region_tax' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_address_region_tax',
		),
		'seopress_pro_rich_snippets_jobs_address_region_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_address_region_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_postal_code'      => array(
			'key' => '_seopress_pro_rich_snippets_jobs_postal_code',
		),
		'seopress_pro_rich_snippets_jobs_postal_code_cf'   => array(
			'key' => '_seopress_pro_rich_snippets_jobs_postal_code_cf',
		),
		'seopress_pro_rich_snippets_jobs_postal_code_tax'  => array(
			'key' => '_seopress_pro_rich_snippets_jobs_postal_code_tax',
		),
		'seopress_pro_rich_snippets_jobs_postal_code_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_postal_code_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_country'          => array(
			'key' => '_seopress_pro_rich_snippets_jobs_country',
		),
		'seopress_pro_rich_snippets_jobs_country_cf'       => array(
			'key' => '_seopress_pro_rich_snippets_jobs_country_cf',
		),
		'seopress_pro_rich_snippets_jobs_country_tax'      => array(
			'key' => '_seopress_pro_rich_snippets_jobs_country_tax',
		),
		'seopress_pro_rich_snippets_jobs_country_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_country_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_remote'           => array(
			'key' => '_seopress_pro_rich_snippets_jobs_remote',
		),
		'seopress_pro_rich_snippets_jobs_remote_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_jobs_remote_cf',
		),
		'seopress_pro_rich_snippets_jobs_remote_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_jobs_remote_tax',
		),
		'seopress_pro_rich_snippets_jobs_remote_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_remote_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_direct_apply'     => array(
			'key' => '_seopress_pro_rich_snippets_jobs_direct_apply',
		),
		'seopress_pro_rich_snippets_jobs_direct_apply_cf'  => array(
			'key' => '_seopress_pro_rich_snippets_jobs_direct_apply_cf',
		),
		'seopress_pro_rich_snippets_jobs_direct_apply_tax' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_direct_apply_tax',
		),
		'seopress_pro_rich_snippets_jobs_direct_apply_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_direct_apply_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_salary'           => array(
			'key' => '_seopress_pro_rich_snippets_jobs_salary',
		),
		'seopress_pro_rich_snippets_jobs_salary_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_jobs_salary_cf',
		),
		'seopress_pro_rich_snippets_jobs_salary_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_jobs_salary_tax',
		),
		'seopress_pro_rich_snippets_jobs_salary_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_salary_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_salary_currency'  => array(
			'key' => '_seopress_pro_rich_snippets_jobs_salary_currency',
		),
		'seopress_pro_rich_snippets_jobs_salary_currency_cf' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_salary_currency_cf',
		),
		'seopress_pro_rich_snippets_jobs_salary_currency_tax' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_salary_currency_tax',
		),
		'seopress_pro_rich_snippets_jobs_salary_currency_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_salary_currency_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_salary_unit'      => array(
			'key' => '_seopress_pro_rich_snippets_jobs_salary_unit',
		),
		'seopress_pro_rich_snippets_jobs_salary_unit_cf'   => array(
			'key' => '_seopress_pro_rich_snippets_jobs_salary_unit_cf',
		),
		'seopress_pro_rich_snippets_jobs_salary_unit_tax'  => array(
			'key' => '_seopress_pro_rich_snippets_jobs_salary_unit_tax',
		),
		'seopress_pro_rich_snippets_jobs_salary_unit_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_salary_unit_manual_global',
		),
		'seopress_pro_rich_snippets_jobs_location_requirement' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_location_requirement',
		),
		'seopress_pro_rich_snippets_jobs_location_requirement_cf' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_location_requirement_cf',
		),
		'seopress_pro_rich_snippets_jobs_location_requirement_tax' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_location_requirement_tax',
		),
		'seopress_pro_rich_snippets_jobs_location_requirement_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_jobs_location_requirement_manual_global',
		),
	);
	seopress_save_inputs_schema_automatic( $inputsJob, $post_id );

	// Video.
	$inputsVideo = array(
		'seopress_pro_rich_snippets_videos_youtube_autofill' => array(
			'key' => '_seopress_pro_rich_snippets_videos_youtube_autofill',
		),
		'seopress_pro_rich_snippets_videos_name'           => array(
			'key' => '_seopress_pro_rich_snippets_videos_name',
		),
		'seopress_pro_rich_snippets_videos_name_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_videos_name_cf',
		),
		'seopress_pro_rich_snippets_videos_name_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_videos_name_tax',
		),
		'seopress_pro_rich_snippets_videos_name_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_videos_name_manual_global',
		),
		'seopress_pro_rich_snippets_videos_description'    => array(
			'key' => '_seopress_pro_rich_snippets_videos_description',
		),
		'seopress_pro_rich_snippets_videos_description_cf' => array(
			'key' => '_seopress_pro_rich_snippets_videos_description_cf',
		),
		'seopress_pro_rich_snippets_videos_description_tax' => array(
			'key' => '_seopress_pro_rich_snippets_videos_description_tax',
		),
		'seopress_pro_rich_snippets_videos_description_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_videos_description_manual_global',
		),
		'seopress_pro_rich_snippets_videos_date_posted'    => array(
			'key' => '_seopress_pro_rich_snippets_videos_date_posted',
		),
		'seopress_pro_rich_snippets_videos_date_posted_cf' => array(
			'key' => '_seopress_pro_rich_snippets_videos_date_posted_cf',
		),
		'seopress_pro_rich_snippets_videos_date_posted_tax' => array(
			'key' => '_seopress_pro_rich_snippets_videos_date_posted_tax',
		),
		'seopress_pro_rich_snippets_videos_date_posted_manual_date_global' => array(
			'key' => '_seopress_pro_rich_snippets_videos_date_posted_manual_date_global',
		),
		'seopress_pro_rich_snippets_videos_img'            => array(
			'key' => '_seopress_pro_rich_snippets_videos_img',
		),
		'seopress_pro_rich_snippets_videos_img_manual_img_global' => array(
			'key' => '_seopress_pro_rich_snippets_videos_img_manual_img_global',
		),
		'seopress_pro_rich_snippets_videos_img_cf'         => array(
			'key' => '_seopress_pro_rich_snippets_videos_img_cf',
		),
		'seopress_pro_rich_snippets_videos_img_tax'        => array(
			'key' => '_seopress_pro_rich_snippets_videos_img_tax',
		),
		'seopress_pro_rich_snippets_videos_img_manual_img_library_global' => array(
			'key' => '_seopress_pro_rich_snippets_videos_img_manual_img_library_global',
		),
		'seopress_pro_rich_snippets_videos_img_manual_img_library_global_width' => array(
			'key' => '_seopress_pro_rich_snippets_videos_img_manual_img_library_global_width',
		),
		'seopress_pro_rich_snippets_videos_img_manual_img_library_global_height' => array(
			'key' => '_seopress_pro_rich_snippets_videos_img_manual_img_library_global_height',
		),
		'seopress_pro_rich_snippets_videos_duration'       => array(
			'key' => '_seopress_pro_rich_snippets_videos_duration',
		),
		'seopress_pro_rich_snippets_videos_duration_cf'    => array(
			'key' => '_seopress_pro_rich_snippets_videos_duration_cf',
		),
		'seopress_pro_rich_snippets_videos_duration_tax'   => array(
			'key' => '_seopress_pro_rich_snippets_videos_duration_tax',
		),
		'seopress_pro_rich_snippets_videos_duration_manual_time_global' => array(
			'key' => '_seopress_pro_rich_snippets_videos_duration_manual_time_global',
		),
		'seopress_pro_rich_snippets_videos_url'            => array(
			'key' => '_seopress_pro_rich_snippets_videos_url',
		),
		'seopress_pro_rich_snippets_videos_url_cf'         => array(
			'key' => '_seopress_pro_rich_snippets_videos_url_cf',
		),
		'seopress_pro_rich_snippets_videos_url_tax'        => array(
			'key' => '_seopress_pro_rich_snippets_videos_url_tax',
		),
		'seopress_pro_rich_snippets_videos_url_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_videos_url_manual_global',
		),
	);
	seopress_save_inputs_schema_automatic( $inputsVideo, $post_id );

	// Event.
	$inputsEvent = array(
		'seopress_pro_rich_snippets_events_type'           => array(
			'key' => '_seopress_pro_rich_snippets_events_type',
		),
		'seopress_pro_rich_snippets_events_type_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_events_type_cf',
		),
		'seopress_pro_rich_snippets_events_type_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_events_type_tax',
		),
		'seopress_pro_rich_snippets_events_type_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_type_manual_global',
		),
		'seopress_pro_rich_snippets_events_name'           => array(
			'key' => '_seopress_pro_rich_snippets_events_name',
		),
		'seopress_pro_rich_snippets_events_name_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_events_name_cf',
		),
		'seopress_pro_rich_snippets_events_name_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_events_name_tax',
		),
		'seopress_pro_rich_snippets_events_name_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_name_manual_global',
		),
		'seopress_pro_rich_snippets_events_desc'           => array(
			'key' => '_seopress_pro_rich_snippets_events_desc',
		),
		'seopress_pro_rich_snippets_events_desc_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_events_desc_cf',
		),
		'seopress_pro_rich_snippets_events_desc_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_events_desc_tax',
		),
		'seopress_pro_rich_snippets_events_desc_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_desc_manual_global',
		),
		'seopress_pro_rich_snippets_events_img'            => array(
			'key' => '_seopress_pro_rich_snippets_events_img',
		),
		'seopress_pro_rich_snippets_events_img_manual_img_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_img_manual_img_global',
		),
		'seopress_pro_rich_snippets_events_img_cf'         => array(
			'key' => '_seopress_pro_rich_snippets_events_img_cf',
		),
		'seopress_pro_rich_snippets_events_img_tax'        => array(
			'key' => '_seopress_pro_rich_snippets_events_img_tax',
		),
		'seopress_pro_rich_snippets_events_img_manual_img_library_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_img_manual_img_library_global',
		),
		'seopress_pro_rich_snippets_events_img_manual_img_library_global_width' => array(
			'key' => '_seopress_pro_rich_snippets_events_img_manual_img_library_global_width',
		),
		'seopress_pro_rich_snippets_events_img_manual_img_library_global_height' => array(
			'key' => '_seopress_pro_rich_snippets_events_img_manual_img_library_global_height',
		),
		'seopress_pro_rich_snippets_events_start_date'     => array(
			'key' => '_seopress_pro_rich_snippets_events_start_date',
		),
		'seopress_pro_rich_snippets_events_start_date_cf'  => array(
			'key' => '_seopress_pro_rich_snippets_events_start_date_cf',
		),
		'seopress_pro_rich_snippets_events_start_date_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_start_date_tax',
		),
		'seopress_pro_rich_snippets_events_start_date_manual_date_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_start_date_manual_date_global',
		),
		'seopress_pro_rich_snippets_events_start_date_timezone' => array(
			'key' => '_seopress_pro_rich_snippets_events_start_date_timezone',
		),
		'seopress_pro_rich_snippets_events_start_date_timezone_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_start_date_timezone_cf',
		),
		'seopress_pro_rich_snippets_events_start_date_timezone_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_start_date_timezone_tax',
		),
		'seopress_pro_rich_snippets_events_start_date_timezone_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_start_date_timezone_manual_global',
		),
		'seopress_pro_rich_snippets_events_start_time'     => array(
			'key' => '_seopress_pro_rich_snippets_events_start_time',
		),
		'seopress_pro_rich_snippets_events_start_time_cf'  => array(
			'key' => '_seopress_pro_rich_snippets_events_start_time_cf',
		),
		'seopress_pro_rich_snippets_events_start_time_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_start_time_tax',
		),
		'seopress_pro_rich_snippets_events_start_time_manual_time_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_start_time_manual_time_global',
		),
		'seopress_pro_rich_snippets_events_end_date'       => array(
			'key' => '_seopress_pro_rich_snippets_events_end_date',
		),
		'seopress_pro_rich_snippets_events_end_date_cf'    => array(
			'key' => '_seopress_pro_rich_snippets_events_end_date_cf',
		),
		'seopress_pro_rich_snippets_events_end_date_tax'   => array(
			'key' => '_seopress_pro_rich_snippets_events_end_date_tax',
		),
		'seopress_pro_rich_snippets_events_end_date_manual_date_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_end_date_manual_date_global',
		),
		'seopress_pro_rich_snippets_events_end_time'       => array(
			'key' => '_seopress_pro_rich_snippets_events_end_time',
		),
		'seopress_pro_rich_snippets_events_end_time_cf'    => array(
			'key' => '_seopress_pro_rich_snippets_events_end_time_cf',
		),
		'seopress_pro_rich_snippets_events_end_time_tax'   => array(
			'key' => '_seopress_pro_rich_snippets_events_end_time_tax',
		),
		'seopress_pro_rich_snippets_events_end_time_manual_time_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_end_time_manual_time_global',
		),
		'seopress_pro_rich_snippets_events_previous_start_date' => array(
			'key' => '_seopress_pro_rich_snippets_events_previous_start_date',
		),
		'seopress_pro_rich_snippets_events_previous_start_date_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_previous_start_date_cf',
		),
		'seopress_pro_rich_snippets_events_previous_start_date_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_previous_start_date_tax',
		),
		'seopress_pro_rich_snippets_events_previous_start_date_manual_date_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_previous_start_date_manual_date_global',
		),
		'seopress_pro_rich_snippets_events_previous_start_time' => array(
			'key' => '_seopress_pro_rich_snippets_events_previous_start_time',
		),
		'seopress_pro_rich_snippets_events_previous_start_time_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_previous_start_time_cf',
		),
		'seopress_pro_rich_snippets_events_previous_start_time_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_previous_start_time_tax',
		),
		'seopress_pro_rich_snippets_events_previous_start_time_manual_time_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_previous_start_time_manual_time_global',
		),
		'seopress_pro_rich_snippets_events_location_name'  => array(
			'key' => '_seopress_pro_rich_snippets_events_location_name',
		),
		'seopress_pro_rich_snippets_events_location_name_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_location_name_cf',
		),
		'seopress_pro_rich_snippets_events_location_name_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_location_name_tax',
		),
		'seopress_pro_rich_snippets_events_location_name_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_location_name_manual_global',
		),
		'seopress_pro_rich_snippets_events_location_url'   => array(
			'key' => '_seopress_pro_rich_snippets_events_location_url',
		),
		'seopress_pro_rich_snippets_events_location_url_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_location_url_cf',
		),
		'seopress_pro_rich_snippets_events_location_url_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_location_url_tax',
		),
		'seopress_pro_rich_snippets_events_location_url_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_location_url_manual_global',
		),
		'seopress_pro_rich_snippets_events_location_address' => array(
			'key' => '_seopress_pro_rich_snippets_events_location_address',
		),
		'seopress_pro_rich_snippets_events_location_address_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_location_address_cf',
		),
		'seopress_pro_rich_snippets_events_location_address_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_location_address_tax',
		),
		'seopress_pro_rich_snippets_events_location_address_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_location_address_manual_global',
		),
		'seopress_pro_rich_snippets_events_offers_name'    => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_name',
		),
		'seopress_pro_rich_snippets_events_offers_name_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_name_cf',
		),
		'seopress_pro_rich_snippets_events_offers_name_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_name_tax',
		),
		'seopress_pro_rich_snippets_events_offers_name_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_name_manual_global',
		),
		'seopress_pro_rich_snippets_events_offers_cat'     => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_cat',
		),
		'seopress_pro_rich_snippets_events_offers_cat_cf'  => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_cat_cf',
		),
		'seopress_pro_rich_snippets_events_offers_cat_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_cat_tax',
		),
		'seopress_pro_rich_snippets_events_offers_cat_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_cat_manual_global',
		),
		'seopress_pro_rich_snippets_events_offers_price'   => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_price',
		),
		'seopress_pro_rich_snippets_events_offers_price_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_price_cf',
		),
		'seopress_pro_rich_snippets_events_offers_price_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_price_tax',
		),
		'seopress_pro_rich_snippets_events_offers_price_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_price_manual_global',
		),
		'seopress_pro_rich_snippets_events_offers_price_currency' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_price_currency',
		),
		'seopress_pro_rich_snippets_events_offers_price_currency_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_price_currency_cf',
		),
		'seopress_pro_rich_snippets_events_offers_price_currency_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_price_currency_tax',
		),
		'seopress_pro_rich_snippets_events_offers_price_currency_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_price_currency_manual_global',
		),
		'seopress_pro_rich_snippets_events_offers_availability' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_availability',
		),
		'seopress_pro_rich_snippets_events_offers_availability_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_availability_cf',
		),
		'seopress_pro_rich_snippets_events_offers_availability_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_availability_tax',
		),
		'seopress_pro_rich_snippets_events_offers_availability_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_availability_manual_global',
		),
		'seopress_pro_rich_snippets_events_offers_valid_from_date' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_valid_from_date',
		),
		'seopress_pro_rich_snippets_events_offers_valid_from_date_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_valid_from_date_cf',
		),
		'seopress_pro_rich_snippets_events_offers_valid_from_date_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_valid_from_date_tax',
		),
		'seopress_pro_rich_snippets_events_offers_valid_from_date_manual_date_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_valid_from_date_manual_date_global',
		),
		'seopress_pro_rich_snippets_events_offers_valid_from_time' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_valid_from_time',
		),
		'seopress_pro_rich_snippets_events_offers_valid_from_time_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_valid_from_time_cf',
		),
		'seopress_pro_rich_snippets_events_offers_valid_from_time_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_valid_from_time_tax',
		),
		'seopress_pro_rich_snippets_events_offers_valid_from_time_manual_time_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_valid_from_time_manual_time_global',
		),
		'seopress_pro_rich_snippets_events_offers_url'     => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_url',
		),
		'seopress_pro_rich_snippets_events_offers_url_cf'  => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_url_cf',
		),
		'seopress_pro_rich_snippets_events_offers_url_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_url_tax',
		),
		'seopress_pro_rich_snippets_events_offers_url_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_offers_url_manual_global',
		),
		'seopress_pro_rich_snippets_events_performer'      => array(
			'key' => '_seopress_pro_rich_snippets_events_performer',
		),
		'seopress_pro_rich_snippets_events_performer_cf'   => array(
			'key' => '_seopress_pro_rich_snippets_events_performer_cf',
		),
		'seopress_pro_rich_snippets_events_performer_tax'  => array(
			'key' => '_seopress_pro_rich_snippets_events_performer_tax',
		),
		'seopress_pro_rich_snippets_events_performer_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_performer_manual_global',
		),
		'seopress_pro_rich_snippets_events_organizer_name' => array(
			'key' => '_seopress_pro_rich_snippets_events_organizer_name',
		),
		'seopress_pro_rich_snippets_events_organizer_name_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_organizer_name_cf',
		),
		'seopress_pro_rich_snippets_events_organizer_name_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_organizer_name_tax',
		),
		'seopress_pro_rich_snippets_events_organizer_name_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_organizer_name_manual_global',
		),
		'seopress_pro_rich_snippets_events_organizer_url'  => array(
			'key' => '_seopress_pro_rich_snippets_events_organizer_url',
		),
		'seopress_pro_rich_snippets_events_organizer_url_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_organizer_url_cf',
		),
		'seopress_pro_rich_snippets_events_organizer_url_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_organizer_url_tax',
		),
		'seopress_pro_rich_snippets_events_organizer_url_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_organizer_url_manual_global',
		),
		'seopress_pro_rich_snippets_events_status'         => array(
			'key' => '_seopress_pro_rich_snippets_events_status',
		),
		'seopress_pro_rich_snippets_events_status_cf'      => array(
			'key' => '_seopress_pro_rich_snippets_events_status_cf',
		),
		'seopress_pro_rich_snippets_events_status_tax'     => array(
			'key' => '_seopress_pro_rich_snippets_events_status_tax',
		),
		'seopress_pro_rich_snippets_events_status_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_status_manual_global',
		),
		'seopress_pro_rich_snippets_events_attendance_mode' => array(
			'key' => '_seopress_pro_rich_snippets_events_attendance_mode',
		),
		'seopress_pro_rich_snippets_events_attendance_mode_cf' => array(
			'key' => '_seopress_pro_rich_snippets_events_attendance_mode_cf',
		),
		'seopress_pro_rich_snippets_events_attendance_mode_tax' => array(
			'key' => '_seopress_pro_rich_snippets_events_attendance_mode_tax',
		),
		'seopress_pro_rich_snippets_events_attendance_mode_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_events_attendance_mode_manual_global',
		),
	);
	seopress_save_inputs_schema_automatic( $inputsEvent, $post_id );

	// Product.
	$inputsProduct = array(
		'seopress_pro_rich_snippets_product_name'          => array(
			'key' => '_seopress_pro_rich_snippets_product_name',
		),
		'seopress_pro_rich_snippets_product_name_cf'       => array(
			'key' => '_seopress_pro_rich_snippets_product_name_cf',
		),
		'seopress_pro_rich_snippets_product_name_tax'      => array(
			'key' => '_seopress_pro_rich_snippets_product_name_tax',
		),
		'seopress_pro_rich_snippets_product_name_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_name_manual_global',
		),
		'seopress_pro_rich_snippets_product_description'   => array(
			'key' => '_seopress_pro_rich_snippets_product_description',
		),
		'seopress_pro_rich_snippets_product_description_cf' => array(
			'key' => '_seopress_pro_rich_snippets_product_description_cf',
		),
		'seopress_pro_rich_snippets_product_description_tax' => array(
			'key' => '_seopress_pro_rich_snippets_product_description_tax',
		),
		'seopress_pro_rich_snippets_product_description_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_description_manual_global',
		),
		'seopress_pro_rich_snippets_product_img'           => array(
			'key' => '_seopress_pro_rich_snippets_product_img',
		),
		'seopress_pro_rich_snippets_product_img_manual_img_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_img_manual_img_global',
		),
		'seopress_pro_rich_snippets_product_img_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_product_img_cf',
		),
		'seopress_pro_rich_snippets_product_img_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_product_img_tax',
		),
		'seopress_pro_rich_snippets_product_img_manual_img_library_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_img_manual_img_library_global',
		),
		'seopress_pro_rich_snippets_product_img_manual_img_library_global_width' => array(
			'key' => '_seopress_pro_rich_snippets_product_img_manual_img_library_global_width',
		),
		'seopress_pro_rich_snippets_product_img_manual_img_library_global_height' => array(
			'key' => '_seopress_pro_rich_snippets_product_img_manual_img_library_global_height',
		),
		'seopress_pro_rich_snippets_product_price'         => array(
			'key' => '_seopress_pro_rich_snippets_product_price',
		),
		'seopress_pro_rich_snippets_product_price_cf'      => array(
			'key' => '_seopress_pro_rich_snippets_product_price_cf',
		),
		'seopress_pro_rich_snippets_product_price_tax'     => array(
			'key' => '_seopress_pro_rich_snippets_product_price_tax',
		),
		'seopress_pro_rich_snippets_product_price_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_price_manual_global',
		),
		'seopress_pro_rich_snippets_product_price_valid_date' => array(
			'key' => '_seopress_pro_rich_snippets_product_price_valid_date',
		),
		'seopress_pro_rich_snippets_product_price_valid_date_cf' => array(
			'key' => '_seopress_pro_rich_snippets_product_price_valid_date_cf',
		),
		'seopress_pro_rich_snippets_product_price_valid_date_tax' => array(
			'key' => '_seopress_pro_rich_snippets_product_price_valid_date_tax',
		),
		'seopress_pro_rich_snippets_product_price_valid_date_manual_date_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_price_valid_date_manual_date_global',
		),
		'seopress_pro_rich_snippets_product_sku'           => array(
			'key' => '_seopress_pro_rich_snippets_product_sku',
		),
		'seopress_pro_rich_snippets_product_sku_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_product_sku_cf',
		),
		'seopress_pro_rich_snippets_product_sku_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_product_sku_tax',
		),
		'seopress_pro_rich_snippets_product_sku_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_sku_manual_global',
		),
		'seopress_pro_rich_snippets_product_global_ids'    => array(
			'key' => '_seopress_pro_rich_snippets_product_global_ids',
		),
		'seopress_pro_rich_snippets_product_global_ids_cf' => array(
			'key' => '_seopress_pro_rich_snippets_product_global_ids_cf',
		),
		'seopress_pro_rich_snippets_product_global_ids_tax' => array(
			'key' => '_seopress_pro_rich_snippets_product_global_ids_tax',
		),
		'seopress_pro_rich_snippets_product_global_ids_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_global_ids_manual_global',
		),
		'seopress_pro_rich_snippets_product_global_ids_value' => array(
			'key' => '_seopress_pro_rich_snippets_product_global_ids_value',
		),
		'seopress_pro_rich_snippets_product_global_ids_value_cf' => array(
			'key' => '_seopress_pro_rich_snippets_product_global_ids_value_cf',
		),
		'seopress_pro_rich_snippets_product_global_ids_value_tax' => array(
			'key' => '_seopress_pro_rich_snippets_product_global_ids_value_tax',
		),
		'seopress_pro_rich_snippets_product_global_ids_value_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_global_ids_value_manual_global',
		),
		'seopress_pro_rich_snippets_product_brand'         => array(
			'key' => '_seopress_pro_rich_snippets_product_brand',
		),
		'seopress_pro_rich_snippets_product_brand_cf'      => array(
			'key' => '_seopress_pro_rich_snippets_product_brand_cf',
		),
		'seopress_pro_rich_snippets_product_brand_tax'     => array(
			'key' => '_seopress_pro_rich_snippets_product_brand_tax',
		),
		'seopress_pro_rich_snippets_product_brand_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_brand_manual_global',
		),
		'seopress_pro_rich_snippets_product_price_currency' => array(
			'key' => '_seopress_pro_rich_snippets_product_price_currency',
		),
		'seopress_pro_rich_snippets_product_price_currency_cf' => array(
			'key' => '_seopress_pro_rich_snippets_product_price_currency_cf',
		),
		'seopress_pro_rich_snippets_product_price_currency_tax' => array(
			'key' => '_seopress_pro_rich_snippets_product_price_currency_tax',
		),
		'seopress_pro_rich_snippets_product_price_currency_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_price_currency_manual_global',
		),
		'seopress_pro_rich_snippets_product_condition'     => array(
			'key' => '_seopress_pro_rich_snippets_product_condition',
		),
		'seopress_pro_rich_snippets_product_condition_cf'  => array(
			'key' => '_seopress_pro_rich_snippets_product_condition_cf',
		),
		'seopress_pro_rich_snippets_product_condition_tax' => array(
			'key' => '_seopress_pro_rich_snippets_product_condition_tax',
		),
		'seopress_pro_rich_snippets_product_condition_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_condition_manual_global',
		),
		'seopress_pro_rich_snippets_product_availability'  => array(
			'key' => '_seopress_pro_rich_snippets_product_availability',
		),
		'seopress_pro_rich_snippets_product_availability_cf' => array(
			'key' => '_seopress_pro_rich_snippets_product_availability_cf',
		),
		'seopress_pro_rich_snippets_product_availability_tax' => array(
			'key' => '_seopress_pro_rich_snippets_product_availability_tax',
		),
		'seopress_pro_rich_snippets_product_availability_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_availability_manual_global',
		),
		'seopress_pro_rich_snippets_product_positive_notes' => array(
			'key' => '_seopress_pro_rich_snippets_product_positive_notes',
		),
		'seopress_pro_rich_snippets_product_positive_notes_cf' => array(
			'key' => '_seopress_pro_rich_snippets_product_positive_notes_cf',
		),
		'seopress_pro_rich_snippets_product_positive_notes_tax' => array(
			'key' => '_seopress_pro_rich_snippets_product_positive_notes_tax',
		),
		'seopress_pro_rich_snippets_product_positive_notes_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_positive_notes_manual_global',
		),
		'seopress_pro_rich_snippets_product_negative_notes' => array(
			'key' => '_seopress_pro_rich_snippets_product_negative_notes',
		),
		'seopress_pro_rich_snippets_product_negative_notes_cf' => array(
			'key' => '_seopress_pro_rich_snippets_product_negative_notes_cf',
		),
		'seopress_pro_rich_snippets_product_negative_notes_tax' => array(
			'key' => '_seopress_pro_rich_snippets_product_negative_notes_tax',
		),
		'seopress_pro_rich_snippets_product_negative_notes_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_negative_notes_manual_global',
		),
		'seopress_pro_rich_snippets_product_energy_consumption' => array(
			'key' => '_seopress_pro_rich_snippets_product_energy_consumption',
		),
		'seopress_pro_rich_snippets_product_energy_consumption_cf' => array(
			'key' => '_seopress_pro_rich_snippets_product_energy_consumption_cf',
		),
		'seopress_pro_rich_snippets_product_energy_consumption_tax' => array(
			'key' => '_seopress_pro_rich_snippets_product_energy_consumption_tax',
		),
		'seopress_pro_rich_snippets_product_energy_consumption_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_product_energy_consumption_manual_global',
		),
	);
	seopress_save_inputs_schema_automatic( $inputsProduct, $post_id );

	// Software App.
	$inputsSoftware = array(
		'seopress_pro_rich_snippets_softwareapp_name'      => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_name',
		),
		'seopress_pro_rich_snippets_softwareapp_name_cf'   => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_name_cf',
		),
		'seopress_pro_rich_snippets_softwareapp_name_tax'  => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_name_tax',
		),
		'seopress_pro_rich_snippets_softwareapp_name_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_name_manual_global',
		),
		'seopress_pro_rich_snippets_softwareapp_os'        => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_os',
		),
		'seopress_pro_rich_snippets_softwareapp_os_cf'     => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_os_cf',
		),
		'seopress_pro_rich_snippets_softwareapp_os_tax'    => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_os_tax',
		),
		'seopress_pro_rich_snippets_softwareapp_os_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_os_manual_global',
		),
		'seopress_pro_rich_snippets_softwareapp_cat'       => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_cat',
		),
		'seopress_pro_rich_snippets_softwareapp_cat_cf'    => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_cat_cf',
		),
		'seopress_pro_rich_snippets_softwareapp_cat_tax'   => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_cat_tax',
		),
		'seopress_pro_rich_snippets_softwareapp_cat_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_cat_manual_global',
		),
		'seopress_pro_rich_snippets_softwareapp_price'     => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_price',
		),
		'seopress_pro_rich_snippets_softwareapp_price_cf'  => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_price_cf',
		),
		'seopress_pro_rich_snippets_softwareapp_price_tax' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_price_tax',
		),
		'seopress_pro_rich_snippets_softwareapp_price_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_price_manual_global',
		),
		'seopress_pro_rich_snippets_softwareapp_currency'  => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_currency',
		),
		'seopress_pro_rich_snippets_softwareapp_currency_cf' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_currency_cf',
		),
		'seopress_pro_rich_snippets_softwareapp_currency_tax' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_currency_tax',
		),
		'seopress_pro_rich_snippets_softwareapp_currency_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_currency_manual_global',
		),
		'seopress_pro_rich_snippets_softwareapp_rating'    => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_rating',
		),
		'seopress_pro_rich_snippets_softwareapp_rating_cf' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_rating_cf',
		),
		'seopress_pro_rich_snippets_softwareapp_rating_tax' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_rating_tax',
		),
		'seopress_pro_rich_snippets_softwareapp_rating_manual_rating_global' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_rating_manual_rating_global',
		),
		'seopress_pro_rich_snippets_softwareapp_max_rating' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_max_rating',
		),
		'seopress_pro_rich_snippets_softwareapp_max_rating_cf' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_max_rating_cf',
		),
		'seopress_pro_rich_snippets_softwareapp_max_rating_tax' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_max_rating_tax',
		),
		'seopress_pro_rich_snippets_softwareapp_max_rating_manual_rating_global' => array(
			'key' => '_seopress_pro_rich_snippets_softwareapp_max_rating_manual_rating_global',
		),
	);

	seopress_save_inputs_schema_automatic( $inputsSoftware, $post_id );

	// Service.
	$inputsService = array(
		'seopress_pro_rich_snippets_service_name'          => array(
			'key' => '_seopress_pro_rich_snippets_service_name',
		),
		'seopress_pro_rich_snippets_service_name_cf'       => array(
			'key' => '_seopress_pro_rich_snippets_service_name_cf',
		),
		'seopress_pro_rich_snippets_service_name_tax'      => array(
			'key' => '_seopress_pro_rich_snippets_service_name_tax',
		),
		'seopress_pro_rich_snippets_service_name_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_name_manual_global',
		),
		'seopress_pro_rich_snippets_service_type'          => array(
			'key' => '_seopress_pro_rich_snippets_service_type',
		),
		'seopress_pro_rich_snippets_service_type_cf'       => array(
			'key' => '_seopress_pro_rich_snippets_service_type_cf',
		),
		'seopress_pro_rich_snippets_service_type_tax'      => array(
			'key' => '_seopress_pro_rich_snippets_service_type_tax',
		),
		'seopress_pro_rich_snippets_service_type_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_type_manual_global',
		),
		'seopress_pro_rich_snippets_service_description'   => array(
			'key' => '_seopress_pro_rich_snippets_service_description',
		),
		'seopress_pro_rich_snippets_service_description_cf' => array(
			'key' => '_seopress_pro_rich_snippets_service_description_cf',
		),
		'seopress_pro_rich_snippets_service_description_tax' => array(
			'key' => '_seopress_pro_rich_snippets_service_description_tax',
		),
		'seopress_pro_rich_snippets_service_description_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_description_manual_global',
		),
		'seopress_pro_rich_snippets_service_img'           => array(
			'key' => '_seopress_pro_rich_snippets_service_img',
		),
		'seopress_pro_rich_snippets_service_img_manual_img_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_img_manual_img_global',
		),
		'seopress_pro_rich_snippets_service_img_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_service_img_cf',
		),
		'seopress_pro_rich_snippets_service_img_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_service_img_tax',
		),
		'seopress_pro_rich_snippets_service_img_manual_img_library_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_img_manual_img_library_global',
		),
		'seopress_pro_rich_snippets_service_img_manual_img_library_global_width' => array(
			'key' => '_seopress_pro_rich_snippets_service_img_manual_img_library_global_width',
		),
		'seopress_pro_rich_snippets_service_img_manual_img_library_global_height' => array(
			'key' => '_seopress_pro_rich_snippets_service_img_manual_img_library_global_height',
		),
		'seopress_pro_rich_snippets_service_area'          => array(
			'key' => '_seopress_pro_rich_snippets_service_area',
		),
		'seopress_pro_rich_snippets_service_area_cf'       => array(
			'key' => '_seopress_pro_rich_snippets_service_area_cf',
		),
		'seopress_pro_rich_snippets_service_area_tax'      => array(
			'key' => '_seopress_pro_rich_snippets_service_area_tax',
		),
		'seopress_pro_rich_snippets_service_area_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_area_manual_global',
		),
		'seopress_pro_rich_snippets_service_provider_name' => array(
			'key' => '_seopress_pro_rich_snippets_service_provider_name',
		),
		'seopress_pro_rich_snippets_service_provider_name_cf' => array(
			'key' => '_seopress_pro_rich_snippets_service_provider_name_cf',
		),
		'seopress_pro_rich_snippets_service_provider_name_tax' => array(
			'key' => '_seopress_pro_rich_snippets_service_provider_name_tax',
		),
		'seopress_pro_rich_snippets_service_provider_name_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_provider_name_manual_global',
		),
		'seopress_pro_rich_snippets_service_lb_img'        => array(
			'key' => '_seopress_pro_rich_snippets_service_lb_img',
		),
		'seopress_pro_rich_snippets_service_lb_img_manual_img_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_lb_img_manual_img_global',
		),
		'seopress_pro_rich_snippets_service_lb_img_cf'     => array(
			'key' => '_seopress_pro_rich_snippets_service_lb_img_cf',
		),
		'seopress_pro_rich_snippets_service_lb_img_tax'    => array(
			'key' => '_seopress_pro_rich_snippets_service_lb_img_tax',
		),
		'seopress_pro_rich_snippets_service_lb_img_manual_img_library_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_lb_img_manual_img_library_global',
		),
		'seopress_pro_rich_snippets_service_lb_img_manual_img_library_global_width' => array(
			'key' => '_seopress_pro_rich_snippets_service_lb_img_manual_img_library_global_width',
		),
		'seopress_pro_rich_snippets_service_lb_img_manual_img_library_global_height' => array(
			'key' => '_seopress_pro_rich_snippets_service_lb_img_manual_img_library_global_height',
		),
		'seopress_pro_rich_snippets_service_provider_mobility' => array(
			'key' => '_seopress_pro_rich_snippets_service_provider_mobility',
		),
		'seopress_pro_rich_snippets_service_provider_mobility_cf' => array(
			'key' => '_seopress_pro_rich_snippets_service_provider_mobility_cf',
		),
		'seopress_pro_rich_snippets_service_provider_mobility_tax' => array(
			'key' => '_seopress_pro_rich_snippets_service_provider_mobility_tax',
		),
		'seopress_pro_rich_snippets_service_provider_mobility_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_provider_mobility_manual_global',
		),
		'seopress_pro_rich_snippets_service_slogan'        => array(
			'key' => '_seopress_pro_rich_snippets_service_slogan',
		),
		'seopress_pro_rich_snippets_service_slogan_cf'     => array(
			'key' => '_seopress_pro_rich_snippets_service_slogan_cf',
		),
		'seopress_pro_rich_snippets_service_slogan_tax'    => array(
			'key' => '_seopress_pro_rich_snippets_service_slogan_tax',
		),
		'seopress_pro_rich_snippets_service_slogan_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_slogan_manual_global',
		),
		'seopress_pro_rich_snippets_service_street_addr'   => array(
			'key' => '_seopress_pro_rich_snippets_service_street_addr',
		),
		'seopress_pro_rich_snippets_service_street_addr_cf' => array(
			'key' => '_seopress_pro_rich_snippets_service_street_addr_cf',
		),
		'seopress_pro_rich_snippets_service_street_addr_tax' => array(
			'key' => '_seopress_pro_rich_snippets_service_street_addr_tax',
		),
		'seopress_pro_rich_snippets_service_street_addr_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_street_addr_manual_global',
		),
		'seopress_pro_rich_snippets_service_city'          => array(
			'key' => '_seopress_pro_rich_snippets_service_city',
		),
		'seopress_pro_rich_snippets_service_city_cf'       => array(
			'key' => '_seopress_pro_rich_snippets_service_city_cf',
		),
		'seopress_pro_rich_snippets_service_city_tax'      => array(
			'key' => '_seopress_pro_rich_snippets_service_city_tax',
		),
		'seopress_pro_rich_snippets_service_city_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_city_manual_global',
		),
		'seopress_pro_rich_snippets_service_state'         => array(
			'key' => '_seopress_pro_rich_snippets_service_state',
		),
		'seopress_pro_rich_snippets_service_state_cf'      => array(
			'key' => '_seopress_pro_rich_snippets_service_state_cf',
		),
		'seopress_pro_rich_snippets_service_state_tax'     => array(
			'key' => '_seopress_pro_rich_snippets_service_state_tax',
		),
		'seopress_pro_rich_snippets_service_state_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_state_manual_global',
		),
		'seopress_pro_rich_snippets_service_pc'            => array(
			'key' => '_seopress_pro_rich_snippets_service_pc',
		),
		'seopress_pro_rich_snippets_service_pc_cf'         => array(
			'key' => '_seopress_pro_rich_snippets_service_pc_cf',
		),
		'seopress_pro_rich_snippets_service_pc_tax'        => array(
			'key' => '_seopress_pro_rich_snippets_service_pc_tax',
		),
		'seopress_pro_rich_snippets_service_pc_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_pc_manual_global',
		),
		'seopress_pro_rich_snippets_service_country'       => array(
			'key' => '_seopress_pro_rich_snippets_service_country',
		),
		'seopress_pro_rich_snippets_service_country_cf'    => array(
			'key' => '_seopress_pro_rich_snippets_service_country_cf',
		),
		'seopress_pro_rich_snippets_service_country_tax'   => array(
			'key' => '_seopress_pro_rich_snippets_service_country_tax',
		),
		'seopress_pro_rich_snippets_service_country_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_country_manual_global',
		),
		'seopress_pro_rich_snippets_service_lat'           => array(
			'key' => '_seopress_pro_rich_snippets_service_lat',
		),
		'seopress_pro_rich_snippets_service_lat_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_service_lat_cf',
		),
		'seopress_pro_rich_snippets_service_lat_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_service_lat_tax',
		),
		'seopress_pro_rich_snippets_service_lat_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_lat_manual_global',
		),
		'seopress_pro_rich_snippets_service_lon'           => array(
			'key' => '_seopress_pro_rich_snippets_service_lon',
		),
		'seopress_pro_rich_snippets_service_lon_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_service_lon_cf',
		),
		'seopress_pro_rich_snippets_service_lon_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_service_lon_tax',
		),
		'seopress_pro_rich_snippets_service_lon_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_lon_manual_global',
		),
		'seopress_pro_rich_snippets_service_tel'           => array(
			'key' => '_seopress_pro_rich_snippets_service_tel',
		),
		'seopress_pro_rich_snippets_service_tel_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_service_tel_cf',
		),
		'seopress_pro_rich_snippets_service_tel_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_service_tel_tax',
		),
		'seopress_pro_rich_snippets_service_tel_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_tel_manual_global',
		),
		'seopress_pro_rich_snippets_service_price'         => array(
			'key' => '_seopress_pro_rich_snippets_service_price',
		),
		'seopress_pro_rich_snippets_service_price_cf'      => array(
			'key' => '_seopress_pro_rich_snippets_service_price_cf',
		),
		'seopress_pro_rich_snippets_service_price_tax'     => array(
			'key' => '_seopress_pro_rich_snippets_service_price_tax',
		),
		'seopress_pro_rich_snippets_service_price_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_service_price_manual_global',
		),
	);
	seopress_save_inputs_schema_automatic( $inputsService, $post_id );

	// Review.
	$inputsReview = array(
		'seopress_pro_rich_snippets_review_item'           => array(
			'key' => '_seopress_pro_rich_snippets_review_item',
		),
		'seopress_pro_rich_snippets_review_item_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_review_item_cf',
		),
		'seopress_pro_rich_snippets_review_item_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_review_item_tax',
		),
		'seopress_pro_rich_snippets_review_item_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_review_item_manual_global',
		),
		'seopress_pro_rich_snippets_review_item_type'      => array(
			'key' => '_seopress_pro_rich_snippets_review_item_type',
		),
		'seopress_pro_rich_snippets_review_item_type_cf'   => array(
			'key' => '_seopress_pro_rich_snippets_review_item_type_cf',
		),
		'seopress_pro_rich_snippets_review_item_type_tax'  => array(
			'key' => '_seopress_pro_rich_snippets_review_item_type_tax',
		),
		'seopress_pro_rich_snippets_review_item_type_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_review_item_type_manual_global',
		),
		'seopress_pro_rich_snippets_review_img'            => array(
			'key' => '_seopress_pro_rich_snippets_review_img',
		),
		'seopress_pro_rich_snippets_review_img_manual_img_global' => array(
			'key' => '_seopress_pro_rich_snippets_review_img_manual_img_global',
		),
		'seopress_pro_rich_snippets_review_img_cf'         => array(
			'key' => '_seopress_pro_rich_snippets_review_img_cf',
		),
		'seopress_pro_rich_snippets_review_img_tax'        => array(
			'key' => '_seopress_pro_rich_snippets_review_img_tax',
		),
		'seopress_pro_rich_snippets_review_img_manual_img_library_global' => array(
			'key' => '_seopress_pro_rich_snippets_review_img_manual_img_library_global',
		),
		'seopress_pro_rich_snippets_review_img_manual_img_library_global_width' => array(
			'key' => '_seopress_pro_rich_snippets_review_img_manual_img_library_global_width',
		),
		'seopress_pro_rich_snippets_review_img_manual_img_library_global_height' => array(
			'key' => '_seopress_pro_rich_snippets_review_img_manual_img_library_global_height',
		),
		'seopress_pro_rich_snippets_review_rating'         => array(
			'key' => '_seopress_pro_rich_snippets_review_rating',
		),
		'seopress_pro_rich_snippets_review_rating_cf'      => array(
			'key' => '_seopress_pro_rich_snippets_review_rating_cf',
		),
		'seopress_pro_rich_snippets_review_rating_tax'     => array(
			'key' => '_seopress_pro_rich_snippets_review_rating_tax',
		),
		'seopress_pro_rich_snippets_review_rating_manual_rating_global' => array(
			'key' => '_seopress_pro_rich_snippets_review_rating_manual_rating_global',
		),
		'seopress_pro_rich_snippets_review_max_rating'     => array(
			'key' => '_seopress_pro_rich_snippets_review_max_rating',
		),
		'seopress_pro_rich_snippets_review_max_rating_cf'  => array(
			'key' => '_seopress_pro_rich_snippets_review_max_rating_cf',
		),
		'seopress_pro_rich_snippets_review_max_rating_tax' => array(
			'key' => '_seopress_pro_rich_snippets_review_max_rating_tax',
		),
		'seopress_pro_rich_snippets_review_max_rating_manual_rating_global' => array(
			'key' => '_seopress_pro_rich_snippets_review_max_rating_manual_rating_global',
		),
		'seopress_pro_rich_snippets_review_body'           => array(
			'key' => '_seopress_pro_rich_snippets_review_body',
		),
		'seopress_pro_rich_snippets_review_body_cf'        => array(
			'key' => '_seopress_pro_rich_snippets_review_body_cf',
		),
		'seopress_pro_rich_snippets_review_body_tax'       => array(
			'key' => '_seopress_pro_rich_snippets_review_body_tax',
		),
		'seopress_pro_rich_snippets_review_body_manual_global' => array(
			'key' => '_seopress_pro_rich_snippets_review_body_manual_global',
		),
	);
	seopress_save_inputs_schema_automatic( $inputsReview, $post_id );

	// Custom.
	if ( isset( $_POST['seopress_pro_rich_snippets_custom'] ) ) {
		update_post_meta( $post_id, '_seopress_pro_rich_snippets_custom', esc_html( $_POST['seopress_pro_rich_snippets_custom'] ) );
	}
	if ( isset( $_POST['seopress_pro_rich_snippets_custom_manual_custom_global'] ) && ! empty( $_POST['seopress_pro_rich_snippets_custom_manual_custom_global'] ) ) {
		update_post_meta(
			$post_id,
			'_seopress_pro_rich_snippets_custom_manual_custom_global',
			$_POST['seopress_pro_rich_snippets_custom_manual_custom_global']
		);
	}
}
add_action( 'save_post', 'seopress_schemas_save_metabox', 10, 2 );
