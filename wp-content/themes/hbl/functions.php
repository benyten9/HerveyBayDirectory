<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HBL_VERSION', '1.2.697' );
define( 'HBL_THEME_DIR', get_template_directory() );
define( 'HBL_THEME_URI', get_template_directory_uri() );
define( 'HBL_THEME_PATH', get_template_directory() );
define( 'HBL_THEME_URL', get_template_directory_uri() );
define( 'HBL_THEME_ASSETS_PATH', HBL_THEME_PATH . '/assets/' );
define( 'HBL_THEME_ASSETS_URL', HBL_THEME_URL . '/assets/' );

function hbl_asset_version( $relative_path ) {
	$file = HBL_THEME_DIR . $relative_path;

	return file_exists( $file ) ? (string) filemtime( $file ) : HBL_VERSION;
}

function hbl_theme_setup() {
	add_theme_support( 'automatic-feed-links' );

	add_theme_support( 'title-tag' );

	add_theme_support( 'post-thumbnails' );

	add_theme_support( 'html5', array(
		'search-form',
		'comment-form',
		'comment-list',
		'gallery',
		'caption',
		'style',
		'script',
	) );

	add_theme_support( 'customize-selective-refresh-widgets' );

	add_theme_support( 'custom-logo', array(
		'height'      => 100,
		'width'       => 300,
		'flex-height' => true,
		'flex-width'  => true,
	) );

	add_theme_support( 'responsive-embeds' );

	add_theme_support( 'editor-styles' );

	add_theme_support( 'align-wide' );

	register_nav_menus( array(
		'primary' => esc_html__( 'Primary Menu', 'hbl' ),
		'footer'  => esc_html__( 'Footer Menu', 'hbl' ),
	) );
}
add_action( 'after_setup_theme', 'hbl_theme_setup' );

function hbl_content_width() {
	$GLOBALS['content_width'] = apply_filters( 'hbl_content_width', 1920 );
}
add_action( 'after_setup_theme', 'hbl_content_width', 0 );

function hbl_event_rewrite_rules() {
	add_rewrite_rule(
		'^add-event/edit/([0-9]+)/?$',
		'index.php?pagename=add-event&hbl_edit_event=$matches[1]',
		'top'
	);
	
	add_rewrite_rule(
		'^events/([^/]+)/?$',
		'index.php?pagename=events&hbl_event_slug=$matches[1]',
		'top'
	);
}
add_action( 'init', 'hbl_event_rewrite_rules' );

function hbl_event_query_vars( $vars ) {
	$vars[] = 'hbl_edit_event';
	$vars[] = 'hbl_event_slug';
	return $vars;
}
add_filter( 'query_vars', 'hbl_event_query_vars' );

function hbl_widgets_init() {
	register_sidebar( array(
		'name'          => esc_html__( 'Sidebar', 'hbl' ),
		'id'            => 'sidebar-1',
		'description'   => esc_html__( 'Add widgets here.', 'hbl' ),
		'before_widget' => '<section id="%1$s" class="widget %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h2 class="widget-title">',
		'after_title'   => '</h2>',
	) );

	register_sidebar( array(
		'name'          => esc_html__( 'Footer 1', 'hbl' ),
		'id'            => 'footer-1',
		'description'   => esc_html__( 'Footer widget area 1', 'hbl' ),
		'before_widget' => '<div id="%1$s" class="widget %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<h3 class="widget-title">',
		'after_title'   => '</h3>',
	) );

	register_sidebar( array(
		'name'          => esc_html__( 'Footer 2', 'hbl' ),
		'id'            => 'footer-2',
		'description'   => esc_html__( 'Footer widget area 2', 'hbl' ),
		'before_widget' => '<div id="%1$s" class="widget %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<h3 class="widget-title">',
		'after_title'   => '</h3>',
	) );

	register_sidebar( array(
		'name'          => esc_html__( 'Footer 3', 'hbl' ),
		'id'            => 'footer-3',
		'description'   => esc_html__( 'Footer widget area 3', 'hbl' ),
		'before_widget' => '<div id="%1$s" class="widget %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<h3 class="widget-title">',
		'after_title'   => '</h3>',
	) );

	register_sidebar( array(
		'name'          => esc_html__( 'Footer 4', 'hbl' ),
		'id'            => 'footer-4',
		'description'   => esc_html__( 'Footer widget area 4', 'hbl' ),
		'before_widget' => '<div id="%1$s" class="widget %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<h3 class="widget-title">',
		'after_title'   => '</h3>',
	) );
}
add_action( 'widgets_init', 'hbl_widgets_init' );

function hbl_scripts() {
	wp_enqueue_style(
		'bootstrap',
		'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
		array(),
		'5.3.0'
	);

	wp_enqueue_style(
		'bootstrap-icons',
		'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css',
		array(),
		'1.11.0'
	);

	wp_enqueue_style(
		'swiper',
		'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css',
		array(),
		'11.0.0'
	);

	wp_enqueue_style(
		'hbl-style',
		get_stylesheet_uri(),
		array( 'bootstrap' ),
		hbl_asset_version( '/style.css' )
	);

	wp_enqueue_style(
		'hbl-directorist-v2',
		HBL_THEME_URI . '/assets/css/hbl-directorist-v2.css',
		array( 'hbl-style' ),
		hbl_asset_version( '/assets/css/hbl-directorist-v2.css' )
	);

	wp_enqueue_script(
		'bootstrap',
		'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
		array(),
		'5.3.0',
		true
	);

	wp_enqueue_script(
		'swiper',
		'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js',
		array(),
		'11.0.0',
		true
	);

	$dependencies = array( 'jquery', 'bootstrap' );
	
	if ( function_exists( 'directorist_is_review_enabled' ) && directorist_is_review_enabled() ) {
		if ( wp_script_is( 'directorist-jquery-barrating', 'registered' ) ) {
			wp_enqueue_script( 'directorist-jquery-barrating' );
		}
	}
	
	if ( wp_script_is( 'directorist-single-listing', 'registered' ) ) {
		$dependencies[] = 'directorist-single-listing';
	}
	if ( wp_script_is( 'directorist-jquery-barrating', 'registered' ) ) {
		$dependencies[] = 'directorist-jquery-barrating';
	}
	
	wp_enqueue_script(
		'hbl-script',
		HBL_THEME_URI . '/assets/js/theme.js',
		$dependencies,
		hbl_asset_version( '/assets/js/theme.js' ),
		true
	);

	wp_localize_script( 'hbl-script', 'hblData', array(
		'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
		'nonce'        => wp_create_nonce( 'hbl_nonce' ),
		'loginUrl'     => home_url( '/sign-in/' ),
		'isLoggedIn'   => is_user_logged_in(),
	) );

	$recaptcha_site_key = get_option( 'elementor_pro_recaptcha_site_key', '' );
	if ( $recaptcha_site_key ) {
		wp_register_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true );
		wp_localize_script( 'hbl-script', 'hblRecaptcha', array(
			'siteKey' => $recaptcha_site_key,
		) );
	}

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'hbl_scripts' );

function hbl_resource_hints( $hints, $relation_type ) {
	if ( 'preconnect' === $relation_type ) {
		$hints[] = 'https://cdn.jsdelivr.net';
	}

	if ( 'dns-prefetch' === $relation_type ) {
		$hints[] = 'https://cdn.jsdelivr.net';
		$hints[] = 'https://unpkg.com';
		$hints[] = 'https://js.stripe.com';
		$hints[] = 'https://fonts.googleapis.com';
		$hints[] = 'https://fonts.gstatic.com';
		$hints[] = 'https://www.google.com';
	}

	return $hints;
}
add_filter( 'wp_resource_hints', 'hbl_resource_hints', 10, 2 );

function hbl_enqueue_directorist_scripts() {
	if ( is_admin() ) {
		return;
	}
	
	if ( function_exists( 'directorist_is_review_enabled' ) && directorist_is_review_enabled() ) {
		if ( wp_script_is( 'directorist-jquery-barrating', 'registered' ) && ! wp_script_is( 'directorist-jquery-barrating', 'enqueued' ) ) {
			wp_enqueue_script( 'directorist-jquery-barrating' );
		}
		
		if ( wp_script_is( 'directorist-single-listing', 'registered' ) && ! wp_script_is( 'directorist-single-listing', 'enqueued' ) ) {
			wp_enqueue_script( 'directorist-single-listing' );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'hbl_enqueue_directorist_scripts', 15 );

function hbl_editor_styles() {
	add_editor_style( 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' );
	
	add_editor_style( 'assets/css/editor-style.css' );
}
add_action( 'after_setup_theme', 'hbl_editor_styles' );

function hbl_elementor_support() {
	add_theme_support( 'elementor', array(
		'settings' => array(
			'page_title_selector' => '.entry-title',
		),
	) );

	add_theme_support( 'header-footer-elementor' );

	add_theme_support( 'elementor-color-scheme' );

	add_theme_support( 'elementor-typography-scheme' );

	add_theme_support( 'elementor-default-colors' );

	add_theme_support( 'elementor-default-fonts' );
}
add_action( 'after_setup_theme', 'hbl_elementor_support' );

function hbl_register_elementor_locations( $elementor_theme_manager ) {
	$elementor_theme_manager->register_all_core_location();
}
add_action( 'elementor/theme/register_locations', 'hbl_register_elementor_locations' );

function hbl_register_elementor_theme_conditions( $conditions_manager ) {
	if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base' ) ) {
		return;
	}
	
	require_once HBL_THEME_DIR . '/inc/elementor/conditions/class-single-event-condition.php';
	require_once HBL_THEME_DIR . '/inc/elementor/conditions/class-single-blog-condition.php';
	require_once HBL_THEME_DIR . '/inc/elementor/conditions/class-event-category-archive-condition.php';
	
	$event_condition = new \HBL\Elementor\Conditions\Single_Event_Condition();
	$blog_condition = new \HBL\Elementor\Conditions\Single_Blog_Condition();
	
	$singular_condition = $conditions_manager->get_condition( 'singular' );
	if ( $singular_condition ) {
		$singular_condition->register_sub_condition( $event_condition );
		$singular_condition->register_sub_condition( $blog_condition );
	} else {
		$conditions_manager->register_condition_instance( $event_condition );
		$conditions_manager->register_condition_instance( $blog_condition );
	}
	
	$event_category_archive_condition = new \HBL\Elementor\Conditions\Event_Category_Archive_Condition();
	
	$archive_condition = $conditions_manager->get_condition( 'archive' );
	if ( $archive_condition ) {
		$archive_condition->register_sub_condition( $event_category_archive_condition );
	} else {
		$conditions_manager->register_condition_instance( $event_category_archive_condition );
	}
}
add_action( 'elementor/theme/register_conditions', 'hbl_register_elementor_theme_conditions' );

function hbl_body_classes( $classes ) {
	if ( did_action( 'elementor/loaded' ) ) {
		$classes[] = 'elementor-active';
		
		if ( is_singular() && \Elementor\Plugin::$instance->documents->get( get_the_ID() )->is_built_with_elementor() ) {
			$classes[] = 'elementor-page';
		}
	}

	$classes[] = 'bootstrap-enabled';

	return $classes;
}
add_filter( 'body_class', 'hbl_body_classes' );

function hbl_image_sizes() {
	add_image_size( 'hbl-featured', 1920, 850, true );
	add_image_size( 'hbl-card', 400, 400, true );
	add_image_size( 'hbl-thumbnail', 300, 300, true );
}
add_action( 'after_setup_theme', 'hbl_image_sizes' );

function hbl_excerpt_length( $length ) {
	return 30;
}
add_filter( 'excerpt_length', 'hbl_excerpt_length' );

function hbl_excerpt_more( $more ) {
	return '...';
}
add_filter( 'excerpt_more', 'hbl_excerpt_more' );

require_once HBL_THEME_DIR . '/inc/bootstrap-navwalker.php';

require_once HBL_THEME_DIR . '/inc/template-tags.php';

require_once HBL_THEME_DIR . '/inc/customizer.php';

if ( defined( 'JETPACK__VERSION' ) ) {
	require_once HBL_THEME_DIR . '/inc/jetpack.php';
}

require_once HBL_THEME_DIR . '/inc/class-hbl-events-db.php';

require_once HBL_THEME_DIR . '/inc/class-hbl-pricing-plans.php';

function hbl_register_event_taxonomy() {
	$labels = array(
		'name'              => _x( 'Event Categories', 'taxonomy general name', 'hbl' ),
		'singular_name'     => _x( 'Event Category', 'taxonomy singular name', 'hbl' ),
		'search_items'      => __( 'Search Event Categories', 'hbl' ),
		'all_items'         => __( 'All Event Categories', 'hbl' ),
		'parent_item'       => __( 'Parent Event Category', 'hbl' ),
		'parent_item_colon' => __( 'Parent Event Category:', 'hbl' ),
		'edit_item'         => __( 'Edit Event Category', 'hbl' ),
		'update_item'       => __( 'Update Event Category', 'hbl' ),
		'add_new_item'      => __( 'Add New Event Category', 'hbl' ),
		'new_item_name'     => __( 'New Event Category Name', 'hbl' ),
		'menu_name'         => __( 'Event Categories', 'hbl' ),
	);

	$args = array(
		'hierarchical'      => true,
		'labels'            => $labels,
		'show_ui'           => true,
		'show_admin_column' => false,
		'query_var'         => true,
		'rewrite'           => array(
			'slug'         => 'events/category',
			'with_front'   => false,
			'hierarchical' => true,
		),
		'show_in_rest'      => true,
		'public'            => true,
		'publicly_queryable' => true,
		'show_in_nav_menus' => true,
		'show_tagcloud'     => true,
	);

	register_taxonomy( 'event_category', array( 'post' ), $args );

	$tag_labels = array(
		'name'                       => _x( 'Event Tags', 'taxonomy general name', 'hbl' ),
		'singular_name'              => _x( 'Event Tag', 'taxonomy singular name', 'hbl' ),
		'search_items'               => __( 'Search Event Tags', 'hbl' ),
		'popular_items'              => __( 'Popular Event Tags', 'hbl' ),
		'all_items'                  => __( 'All Event Tags', 'hbl' ),
		'edit_item'                  => __( 'Edit Event Tag', 'hbl' ),
		'update_item'                => __( 'Update Event Tag', 'hbl' ),
		'add_new_item'               => __( 'Add New Event Tag', 'hbl' ),
		'new_item_name'              => __( 'New Event Tag Name', 'hbl' ),
		'separate_items_with_commas' => __( 'Separate tags with commas', 'hbl' ),
		'add_or_remove_items'        => __( 'Add or remove tags', 'hbl' ),
		'choose_from_most_used'      => __( 'Choose from the most used tags', 'hbl' ),
		'menu_name'                  => __( 'Event Tags', 'hbl' ),
	);

	$tag_args = array(
		'hierarchical'      => false,
		'labels'            => $tag_labels,
		'show_ui'           => true,
		'show_admin_column' => false,
		'query_var'         => true,
		'rewrite'           => array(
			'slug'       => 'events/tag',
			'with_front' => false,
		),
		'show_in_rest'       => true,
		'public'             => true,
		'publicly_queryable' => true,
		'show_tagcloud'      => true,
	);

	register_taxonomy( 'event_tag', array( 'post' ), $tag_args );
}
add_action( 'init', 'hbl_register_event_taxonomy', 0 );

function hbl_get_event_category_url( $term ) {
	if ( is_numeric( $term ) ) {
		$term = get_term( $term, 'event_category' );
	}
	
	if ( ! $term || is_wp_error( $term ) ) {
		return home_url( '/whats-on/' );
	}
	
	return get_term_link( $term, 'event_category' );
}

if ( is_admin() ) {
	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-events-admin.php';

	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-bulk-category-reassign.php';

	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-bulk-plan-reassign.php';

	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-duplicate-listings.php';

	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-missing-images.php';

	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-ai-descriptions.php';

	try {
		require_once HBL_THEME_DIR . '/inc/admin/class-hbl-place-id.php';
	} catch ( \Throwable $e ) {
		error_log( '[HBL] Place ID Manager failed to load: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
	}

	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-partner-roles.php';
}

require_once HBL_THEME_DIR . '/inc/ajax/hbl-directorist-v2-ajax.php';
require_once HBL_THEME_DIR . '/inc/ajax/hbl-events-v2-ajax.php';

function hbl_register_elementor_widgets() {
	if ( ! did_action( 'elementor/loaded' ) ) {
		return;
	}

	require_once HBL_THEME_DIR . '/inc/widgets/v2/traits/trait-query-handler.php';
	require_once HBL_THEME_DIR . '/inc/widgets/v2/traits/trait-filter-controls.php';
	require_once HBL_THEME_DIR . '/inc/widgets/v2/traits/trait-card-renderer.php';
	require_once HBL_THEME_DIR . '/inc/widgets/v2/class-hbl-directorist-v2.php';
	require_once HBL_THEME_DIR . '/inc/widgets/v2/class-hbl-single-category-v2.php';
	require_once HBL_THEME_DIR . '/inc/widgets/v2/class-hbl-single-location-v2.php';
	require_once HBL_THEME_DIR . '/inc/widgets/v2/class-hbl-single-tag-v2.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\V2\HBL_Directorist_V2() );
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\V2\HBL_Single_Category_V2() );
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\V2\HBL_Single_Location_V2() );
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\V2\HBL_Single_Tag_V2() );
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-search.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Search() );

	require_once HBL_THEME_DIR . '/inc/widgets/hbl-static-grid.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Static_Grid() );

	require_once HBL_THEME_DIR . '/inc/widgets/hbl-static-grid-v2.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Static_Grid_V2() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-cta-section.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_CTA_Section() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-blogs-section.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Blogs_Section() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-row-search.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Row_Search() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-faqs.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_FAQs() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-search-column.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Search_Column() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-calendar.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Calendar() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-pricing-plan.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Pricing_Plan() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-noticeboard.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Noticeboard() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-all-about-hb.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_All_About_HB() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-dashboard.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Dashboard() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-account-menu.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Account_Menu() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-add-listing-form.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Add_Listing_Form() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-add-event-form.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Add_Event_Form() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-signin-signup-form.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Signin_Signup_Form() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-single-listing.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Single_Listing() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-single-event.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Single_Event() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-claim-listing-form.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Claim_Listing_Form() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-category-archive.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Category_Archive() );

	require_once HBL_THEME_DIR . '/inc/widgets/v2/class-hbl-event-single-category-v2.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\V2\HBL_Event_Single_Category_V2() );

	require_once HBL_THEME_DIR . '/inc/widgets/v2/class-hbl-event-single-tag-v2.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\V2\HBL_Event_Single_Tag_V2() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-event-category-archive.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Event_Category_Archive() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-user-profile.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_User_Profile() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-location-archive.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Location_Archive() );

	require_once HBL_THEME_DIR . '/inc/widgets/v2/class-hbl-listing-search-v2.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\V2\HBL_Listing_Search_V2() );

	require_once HBL_THEME_DIR . '/inc/widgets/v2/class-hbl-listing-search-results-v2.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\V2\HBL_Listing_Search_Results_V2() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-checkout.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Checkout() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-payment-receipt.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Payment_Receipt() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-transaction-failure.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Transaction_Failure() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-thank-you.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Thank_You() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-single-post.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Single_Post() );
}
add_action( 'elementor/widgets/register', 'hbl_register_elementor_widgets' );

function hbl_add_elementor_widget_categories( $elements_manager ) {
	$elements_manager->add_category(
		'hbl',
		[
			'title' => esc_html__( 'HBL Directory', 'hbl' ),
			'icon' => 'fa fa-map-marker',
		]
	);
}
add_action( 'elementor/elements/categories_registered', 'hbl_add_elementor_widget_categories' );

function hbl_get_dashboard_page_url() {
	static $url = null;

	if ( null !== $url ) {
		return $url;
	}

	$pages = get_posts( array(
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'meta_key'       => '_wp_page_template',
		'meta_value'     => 'page-templates/template-account-dashboard.php',
		'fields'         => 'ids',
	) );

	$url = ! empty( $pages ) ? get_permalink( $pages[0] ) : home_url( '/dashboard/' );

	return $url;
}

function hbl_render_dashboard_widget( $settings = array() ) {
	if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
		echo '<div class="hbl-dashboard-notice">' . esc_html__( 'Elementor is required to display the dashboard.', 'hbl' ) . '</div>';
		return;
	}

	$widget = \Elementor\Plugin::instance()->elements_manager->create_element_instance( array(
		'id'         => 'hbl-dashboard-standalone',
		'elType'     => 'widget',
		'widgetType' => 'hbl-dashboard',
		'settings'   => $settings,
	) );

	if ( $widget ) {
		$widget->render_content();
	}
}

function hbl_dashboard_force_elementor_assets() {
	if ( ! is_page_template( 'page-templates/template-account-dashboard.php' ) ) {
		return;
	}

	if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
		return;
	}

	$frontend = \Elementor\Plugin::instance()->frontend;

	$frontend->enqueue_styles();
	$frontend->enqueue_scripts();
}
add_action( 'wp_enqueue_scripts', 'hbl_dashboard_force_elementor_assets', 20 );

function hbl_force_account_dashboard_template( $template ) {
	if ( ! is_singular( 'page' ) ) {
		return $template;
	}

	$page_template = get_page_template_slug( get_queried_object_id() );

	if ( 'page-templates/template-account-dashboard.php' !== $page_template ) {
		return $template;
	}

	$theme_template = locate_template( 'page-templates/template-account-dashboard.php' );

	return $theme_template ? $theme_template : $template;
}
add_filter( 'template_include', 'hbl_force_account_dashboard_template', 100 );

function hbl_disable_elementor_defaults() {
	update_option( 'elementor_disable_color_schemes', 'yes' );
	update_option( 'elementor_disable_typography_schemes', 'yes' );
	update_option( 'elementor_container_width', '1920' );
	update_option( 'elementor_viewport_lg', '1200' );
	update_option( 'elementor_viewport_md', '768' );
}
add_action( 'after_switch_theme', 'hbl_disable_elementor_defaults' );

function hbl_mime_types( $mimes ) {
	$mimes['svg'] = 'image/svg+xml';
	$mimes['svgz'] = 'image/svg+xml';
	return $mimes;
}
add_filter( 'upload_mimes', 'hbl_mime_types' );

function hbl_fix_svg_display() {
	echo '<style>
		.attachment-266x266, .thumbnail img {
			width: 100% !important;
			height: auto !important;
		}
	</style>';
}
add_action( 'admin_head', 'hbl_fix_svg_display' );


function hbl_is_piecal_active() {
	return defined( 'PIECAL_VERSION' );
}

function hbl_get_piecal_start_date( $post_id ) {
	return get_post_meta( $post_id, '_piecal_start_date', true );
}

function hbl_get_piecal_end_date( $post_id ) {
	return get_post_meta( $post_id, '_piecal_end_date', true );
}

function hbl_is_piecal_allday( $post_id ) {
	return (bool) get_post_meta( $post_id, '_piecal_is_allday', true );
}

function hbl_get_piecal_time_display( $post_id ) {
	if ( hbl_is_piecal_allday( $post_id ) ) {
		return esc_html__( 'All Day', 'hbl' );
	}

	$start_date = hbl_get_piecal_start_date( $post_id );
	$end_date = hbl_get_piecal_end_date( $post_id );

	if ( empty( $start_date ) ) {
		return '';
	}

	$start_time = date( get_option( 'time_format' ), strtotime( $start_date ) );
	
	if ( ! empty( $end_date ) ) {
		$end_time = date( get_option( 'time_format' ), strtotime( $end_date ) );
		return $start_time . ' - ' . $end_time;
	}

	return $start_time;
}

function hbl_build_events_by_date_for_month( $events, $year, $month ) {
	$events_by_date = array();
	
	if ( empty( $events ) ) {
		return $events_by_date;
	}

	$month_padded = str_pad( $month, 2, '0', STR_PAD_LEFT );
	$days_in_month = date( 't', strtotime( "{$year}-{$month_padded}-01" ) );

	$day_to_num = array(
		'sun' => 0,
		'mon' => 1,
		'tue' => 2,
		'wed' => 3,
		'thu' => 4,
		'fri' => 5,
		'sat' => 6,
	);

	foreach ( $events as $event ) {
		$event_start = $event->start_date;
		$event_end = $event->end_date;
		$event_frequency = $event->event_frequency ?? 'once';

		if ( empty( $event_start ) ) {
			continue;
		}

		if ( in_array( $event_frequency, array( 'weekly', 'monthly', 'recurring' ), true ) ) {
			hbl_add_recurring_event_dates( $events_by_date, $event, $year, $month, $days_in_month, $day_to_num );
			continue;
		}

		$start = strtotime( date( 'Y-m-d', strtotime( $event_start ) ) );
		$end = ! empty( $event_end ) ? strtotime( date( 'Y-m-d', strtotime( $event_end ) ) ) : $start;
		$recurrence_days = $event->recurrence_days ?? '';

		$allowed_days = array();
		if ( ! empty( $recurrence_days ) ) {
			$allowed_days = array_map( function( $d ) { return strtolower( trim( $d ) ); }, explode( ',', $recurrence_days ) );
		}

		for ( $date = $start; $date <= $end; $date = strtotime( '+1 day', $date ) ) {
			$date_key = date( 'Y-m-d', $date );
			
			if ( ! empty( $allowed_days ) ) {
				$day_of_week = strtolower( date( 'D', $date ) );
				if ( ! in_array( $day_of_week, $allowed_days, true ) ) {
					continue;
				}
			}
			
			if ( ! isset( $events_by_date[ $date_key ] ) ) {
				$events_by_date[ $date_key ] = array();
			}
			$events_by_date[ $date_key ][] = $event;
		}
	}

	return $events_by_date;
}

function hbl_add_recurring_event_dates( &$events_by_date, $event, $year, $month, $days_in_month, $day_to_num ) {
	$event_frequency = $event->event_frequency ?? 'once';
	$recurrence_days = $event->recurrence_days ?? '';
	$recurrence_week = $event->recurrence_week ?? '';
	$recurrence_interval = $event->recurrence_interval ?? 1;
	$event_start = strtotime( $event->start_date );

	$month_padded = str_pad( $month, 2, '0', STR_PAD_LEFT );

	if ( $event_frequency === 'weekly' && ! empty( $recurrence_days ) ) {
		$days = array_map( function( $d ) { return strtolower( trim( $d ) ); }, explode( ',', $recurrence_days ) );

		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$date_string = "{$year}-{$month_padded}-" . str_pad( $day, 2, '0', STR_PAD_LEFT );
			$date_timestamp = strtotime( $date_string );

			if ( $date_timestamp < strtotime( date( 'Y-m-d', $event_start ) ) ) {
				continue;
			}

			$day_of_week = strtolower( date( 'D', $date_timestamp ) );

			if ( in_array( $day_of_week, $days, true ) ) {
				if ( $recurrence_interval == 2 ) {
					$weeks_since_start = floor( ( $date_timestamp - $event_start ) / ( 7 * 24 * 60 * 60 ) );
					if ( $weeks_since_start % 2 !== 0 ) {
						continue;
					}
				}

				if ( ! isset( $events_by_date[ $date_string ] ) ) {
					$events_by_date[ $date_string ] = array();
				}
				$events_by_date[ $date_string ][] = $event;
			}
		}
	} elseif ( $event_frequency === 'monthly' && ! empty( $recurrence_days ) ) {
		$day_name = strtolower( trim( $recurrence_days ) );
		$weeks = ! empty( $recurrence_week ) ? array_map( 'trim', explode( ',', $recurrence_week ) ) : array();

		if ( isset( $day_to_num[ $day_name ] ) && ! empty( $weeks ) ) {
			$target_day_num = $day_to_num[ $day_name ];

			foreach ( $weeks as $week_num ) {
				$week_num = intval( $week_num );
				if ( $week_num < 1 || $week_num > 4 ) {
					continue;
				}

				$occurrence = 0;
				for ( $day = 1; $day <= $days_in_month; $day++ ) {
					$date_string = "{$year}-{$month_padded}-" . str_pad( $day, 2, '0', STR_PAD_LEFT );
					$date_timestamp = strtotime( $date_string );

					if ( date( 'w', $date_timestamp ) == $target_day_num ) {
						$occurrence++;
						if ( $occurrence == $week_num ) {
							if ( $date_timestamp < strtotime( date( 'Y-m-d', $event_start ) ) ) {
								break;
							}

							if ( ! isset( $events_by_date[ $date_string ] ) ) {
								$events_by_date[ $date_string ] = array();
							}
							$events_by_date[ $date_string ][] = $event;
							break;
						}
					}
				}
			}
		}
	} elseif ( $event_frequency === 'recurring' ) {
		$start_date_string = date( 'Y-m-d', $event_start );
		$start_month = date( 'n', $event_start );
		$start_year = date( 'Y', $event_start );

		if ( $start_month == $month && $start_year == $year ) {
			if ( ! isset( $events_by_date[ $start_date_string ] ) ) {
				$events_by_date[ $start_date_string ] = array();
			}
			$events_by_date[ $start_date_string ][] = $event;
		}
	}
}

function hbl_filter_recurring_events_for_date( $events, $date, $limit = 12 ) {
	if ( empty( $events ) ) {
		return array();
	}

	$date_timestamp = strtotime( $date );
	$day_of_week = strtolower( date( 'D', $date_timestamp ) );

	$day_to_num = array(
		'sun' => 0,
		'mon' => 1,
		'tue' => 2,
		'wed' => 3,
		'thu' => 4,
		'fri' => 5,
		'sat' => 6,
		'sun' => 0,
	);

	$filtered_events = array();

	foreach ( $events as $event ) {
		$event_frequency = $event->event_frequency ?? 'once';
		$event_start = strtotime( $event->start_date );

		if ( ! in_array( $event_frequency, array( 'weekly', 'monthly', 'recurring' ), true ) ) {
			$recurrence_days = $event->recurrence_days ?? '';
			if ( ! empty( $recurrence_days ) ) {
				$allowed_days = array_map( function( $d ) { return strtolower( trim( $d ) ); }, explode( ',', $recurrence_days ) );
				if ( ! in_array( $day_of_week, $allowed_days, true ) ) {
					continue;
				}
			}
			$filtered_events[] = $event;
			continue;
		}

		if ( $date_timestamp < strtotime( date( 'Y-m-d', $event_start ) ) ) {
			continue;
		}

		if ( $event_frequency === 'weekly' ) {
			$recurrence_days = $event->recurrence_days ?? '';
			$recurrence_interval = $event->recurrence_interval ?? 1;

			if ( ! empty( $recurrence_days ) ) {
				$days = array_map( function( $d ) { return strtolower( trim( $d ) ); }, explode( ',', $recurrence_days ) );

				if ( in_array( $day_of_week, $days, true ) ) {
					if ( $recurrence_interval == 2 ) {
						$weeks_since_start = floor( ( $date_timestamp - $event_start ) / ( 7 * 24 * 60 * 60 ) );
						if ( $weeks_since_start % 2 !== 0 ) {
							continue;
						}
					}
					$filtered_events[] = $event;
				}
			}
			continue;
		}

		if ( $event_frequency === 'monthly' ) {
			$recurrence_days = $event->recurrence_days ?? '';
			$recurrence_week = $event->recurrence_week ?? '';

			if ( ! empty( $recurrence_days ) && ! empty( $recurrence_week ) ) {
				$day_name = strtolower( trim( $recurrence_days ) );
				$weeks = array_map( 'trim', explode( ',', $recurrence_week ) );

				if ( isset( $day_to_num[ $day_name ] ) ) {
					$target_day_num = $day_to_num[ $day_name ];

					if ( date( 'w', $date_timestamp ) == $target_day_num ) {
						$day_of_month = (int) date( 'j', $date_timestamp );
						$current_month = date( 'n', $date_timestamp );
						$current_year = date( 'Y', $date_timestamp );

						$occurrence = 0;
						for ( $d = 1; $d <= $day_of_month; $d++ ) {
							$check_date = strtotime( "{$current_year}-{$current_month}-{$d}" );
							if ( date( 'w', $check_date ) == $target_day_num ) {
								$occurrence++;
							}
						}

						if ( in_array( (string) $occurrence, $weeks, true ) ) {
							$filtered_events[] = $event;
						}
					}
				}
			}
			continue;
		}

		if ( $event_frequency === 'recurring' ) {
			if ( date( 'Y-m-d', $event_start ) === $date ) {
				$filtered_events[] = $event;
			}
		}
	}

	if ( $limit > 0 ) {
		return array_slice( $filtered_events, 0, $limit );
	}
	
	return $filtered_events;
}

function hbl_get_next_occurrence($event, $from_date) {
	if ( empty($event->event_frequency) || $event->event_frequency === 'single' || $event->event_frequency === 'none' ) {
		$start_ts = strtotime( date( 'Y-m-d', strtotime( $event->start_date ) ) );
		$from_ts = strtotime( $from_date );
		if ( $start_ts >= $from_ts ) {
			return date( 'Y-m-d', strtotime( $event->start_date ) );
		}
		if ( ! empty( $event->end_date ) ) {
			$end_ts = strtotime( date( 'Y-m-d', strtotime( $event->end_date ) ) );
			if ( $end_ts >= $from_ts ) {
				return $from_date;
			}
		}
		return false;
	}

	$current_timestamp = strtotime($from_date);
	$end_timestamp = $current_timestamp + (365 * 86400); 
	
	while ($current_timestamp <= $end_timestamp) {
		$check_date = date('Y-m-d', $current_timestamp);
		$filtered = hbl_filter_recurring_events_for_date([$event], $check_date, 1);
		if (!empty($filtered)) {
			return $check_date;
		}
		$current_timestamp += 86400;
	}
	
	return false;
}

function hbl_get_calendar_events() {
	check_ajax_referer( 'hbl_nonce', 'nonce' );
	
	if ( ! function_exists( 'hbl_events_db' ) ) {
		wp_send_json_error( array( 'message' => 'HBL Events system is not available.' ) );
		return;
	}
	
	$date = isset( $_POST['date'] ) ? sanitize_text_field( $_POST['date'] ) : '';
	$events_per_date = isset( $_POST['events_per_date'] ) ? intval( $_POST['events_per_date'] ) : 12;
	$category = isset( $_POST['category'] ) ? sanitize_text_field( $_POST['category'] ) : '';
	$search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
	$sort = isset( $_POST['sort'] ) ? sanitize_text_field( $_POST['sort'] ) : '';
	$tag = isset( $_POST['tag'] ) ? sanitize_text_field( $_POST['tag'] ) : '';
	$azFilter = isset( $_POST['azFilter'] ) ? sanitize_text_field( $_POST['azFilter'] ) : '';

	$page = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
	
	if ( $page < 1 ) {
		$page = 1;
	}
	
	$is_global_search = empty( $date );
	$today = current_time( 'Y-m-d' );
	
	global $wpdb;
	$table = hbl_events_db()->get_table_name();
	
	$query_select = "SELECT * FROM `{$table}`";
	$query_where = "WHERE status = %s";
	$query_args = array( 'publish' );
	
	if ( $is_global_search ) {
		$date_start = $today . ' 00:00:00';
		$date_logic = "(
			(end_date >= %s OR end_date IS NULL)
			OR (event_frequency IN ('weekly', 'monthly', 'recurring'))
		)";
		$query_where .= " AND " . $date_logic;
		$query_args[] = $date_start;
	} else {
		$date_start = $date . ' 00:00:00';
		$date_end = $date . ' 23:59:59';
		$date_logic = "(
			(DATE(start_date) = %s)
			OR (start_date < %s AND (end_date >= %s OR end_date IS NULL))
			OR (event_frequency IN ('weekly', 'monthly', 'recurring') AND DATE(start_date) <= %s)
		)";
		$query_where .= " AND " . $date_logic;
		$query_args[] = $date;
		$query_args[] = $date_start;
		$query_args[] = $date_start;
		$query_args[] = $date;
	}

	if ( ! empty($category) ) {
		$term = get_term_by( 'slug', $category, 'event_category' );
		if ( $term ) {
			$query_where .= " AND category_id = %d";
			$query_args[] = $term->term_id;
		}
	}

	if ( ! empty($search) ) {
		$query_where .= " AND (title LIKE %s OR description LIKE %s)";
		$like_param = '%' . $wpdb->esc_like( $search ) . '%';
		$query_args[] = $like_param;
		$query_args[] = $like_param;
	}

	if ( ! empty($azFilter) && $azFilter !== 'All' ) {
		$query_where .= " AND title LIKE %s";
		$query_args[] = $wpdb->esc_like( $azFilter ) . '%';
	}

	if ( ! empty( $tag ) ) {
		$tag_term = get_term_by( 'slug', $tag, 'event_tag' );
		if ( $tag_term ) {
			$tag_id = $tag_term->term_id;
			$query_where .= " AND (tags = %s OR tags LIKE %s OR tags LIKE %s OR tags LIKE %s)";
			$query_args[] = (string) $tag_id;
			$query_args[] = $wpdb->esc_like( $tag_id . ',' ) . '%';
			$query_args[] = '%' . $wpdb->esc_like( ',' . $tag_id . ',' ) . '%';
			$query_args[] = '%' . $wpdb->esc_like( ',' . $tag_id );
		}
	}

	$order_by = "ORDER BY ";
	if ( $sort === 'a-z' ) {
		$order_by .= "title ASC, start_date ASC";
	} elseif ( $sort === 'z-a' ) {
		$order_by .= "title DESC, start_date ASC";
	} else {
		$order_by .= "start_date ASC";
	}

	$sql = $wpdb->prepare( "{$query_select} {$query_where} {$order_by}", $query_args );

	$all_events = $wpdb->get_results( $sql );

	if ( $is_global_search ) {
		$events = array();
		foreach ($all_events as $event) {
			$next_occurrence = hbl_get_next_occurrence($event, $today);
			if ($next_occurrence) {
				$event->display_date = $next_occurrence;
				$events[] = $event;
			}
		}
		usort($events, function($a, $b) use ($sort) {
			if ( $sort === 'a-z' ) {
				return strcmp( $a->title, $b->title );
			}
			if ( $sort === 'z-a' ) {
				return strcmp( $b->title, $a->title );
			}
			return strtotime($a->display_date) - strtotime($b->display_date);
		});
	} else {
		$events = hbl_filter_recurring_events_for_date( $all_events, $date, -1 );
	}
	
	$total_events = count( $events );
	$total_pages = ceil( $total_events / $events_per_date );
	$offset = ( $page - 1 ) * $events_per_date;
	
	$paged_events = array_slice( $events, $offset, $events_per_date );
	
	$events_data = array();
	
	foreach ( $paged_events as $event ) {
		$event_image = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'large' ) : '';
		
		if ( ! $event_image ) {
			$event_image = get_template_directory_uri() . '/assets/images/placeholder-event.jpg';
		}
		
		$time_display = '';
		if ( $event->is_allday ) {
			$time_display = __( 'All Day', 'hbl' );
		} else {
			$start_time = date( get_option( 'time_format' ), strtotime( $event->start_date ) );
			$end_time = $event->end_date ? date( get_option( 'time_format' ), strtotime( $event->end_date ) ) : '';
			$time_display = $end_time ? $start_time . ' - ' . $end_time : $start_time;
		}
		
		$event_url = hbl_events_db()->get_event_url( $event );
		
		$category_name = '';
		$category_link = '';
		if ( ! empty( $event->category_id ) ) {
			$term = get_term( $event->category_id );
			if ( ! is_wp_error( $term ) && $term ) {
				$category_name = $term->name;
				$category_link = get_term_link( $term );
			}
		}
		
		$events_data[] = array(
			'id'            => $event->id,
			'title'         => $event->title,
			'venue'         => $event->venue ?? '',
			'category'      => $category_name,
			'category_link' => $category_link,
			'excerpt'  => wp_trim_words( $event->description, 30 ),
			'image'    => $event_image,
			'url'      => $event_url,
			'time'     => $time_display,
			'cost'     => $event->event_cost === 'paid' ? __( 'Paid', 'hbl' ) : __( 'Free', 'hbl' ),
		);
	}
	
	wp_send_json_success( array( 
		'events' => $events_data,
		'pagination' => array(
			'current_page' => $page,
			'total_pages'  => $total_pages,
			'total_events' => $total_events
		)
	) );
}
add_action( 'wp_ajax_hbl_get_calendar_events', 'hbl_get_calendar_events' );
add_action( 'wp_ajax_nopriv_hbl_get_calendar_events', 'hbl_get_calendar_events' );

function hbl_get_calendar_month() {
	check_ajax_referer( 'hbl_nonce', 'nonce' );
	
	if ( ! function_exists( 'hbl_events_db' ) ) {
		wp_send_json_error( array( 'message' => 'HBL Events system is not available.' ) );
		return;
	}
	
	$year = isset( $_POST['year'] ) ? intval( $_POST['year'] ) : date( 'Y' );
	$month = isset( $_POST['month'] ) ? intval( $_POST['month'] ) : date( 'n' );
	$category = isset( $_POST['category'] ) ? sanitize_text_field( $_POST['category'] ) : '';
	
	global $wpdb;
	$table = hbl_events_db()->get_table_name();
	
	$month_padded = str_pad( $month, 2, '0', STR_PAD_LEFT );
	$first_day = "{$year}-{$month_padded}-01";
	$start_date = "{$first_day} 00:00:00";
	$last_day = date( 't', strtotime( "{$year}-{$month_padded}-01" ) );
	$last_day_date = "{$year}-{$month_padded}-{$last_day}";
	$end_date = "{$last_day_date} 23:59:59";
	
	$query_select = "SELECT * FROM `{$table}`";
	$query_where = "WHERE status = %s";
	$query_args = array( 'publish' );
	
	$date_logic = "(
			(start_date >= %s AND start_date <= %s)
			OR (start_date < %s AND (end_date >= %s OR end_date IS NULL))
			OR (event_frequency IN ('weekly', 'monthly', 'recurring') AND DATE(start_date) <= %s)
		)";
	$query_where .= " AND " . $date_logic;
	$query_args[] = $start_date;
	$query_args[] = $end_date;
	$query_args[] = $start_date;
	$query_args[] = $start_date;
	$query_args[] = $last_day_date;

	if ( ! empty($category) ) {
		$term = get_term_by( 'slug', $category, 'event_category' );
		if ( $term ) {
			$query_where .= " AND category_id = %d";
			$query_args[] = $term->term_id;
		}
	}

	$sql = $wpdb->prepare( "{$query_select} {$query_where} ORDER BY start_date ASC", $query_args );
	
	$events = $wpdb->get_results( $sql );
	
	$events_by_date = hbl_build_events_by_date_for_month( $events, $year, $month );
	
	$first_day = mktime( 0, 0, 0, $month, 1, $year );
	$first_day_of_week = date( 'w', $first_day );
	$first_day_of_week = $first_day_of_week == 0 ? 7 : $first_day_of_week;
	$days_in_month = date( 't', $first_day );
	$current_date = current_time( 'Y-m-d' );
	
	$calendar_html = '';
	$week = array();
	$days_in_week = 0;
	
	for ( $i = 1; $i < $first_day_of_week; $i++ ) {
		$week[] = '<div class="hbl-calendar-date-wrapper other-month"><div class="hbl-calendar-date empty"></div></div>';
		$days_in_week++;
	}
	
	for ( $day = 1; $day <= $days_in_month; $day++ ) {
		$date_string = $year . '-' . str_pad( $month, 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $day, 2, '0', STR_PAD_LEFT );
		$is_today = ( $date_string === $current_date );
		$has_events = isset( $events_by_date[ $date_string ] );
		
		$classes = array( 'hbl-calendar-date' );
		if ( $is_today ) {
			$classes[] = 'today';
		}
		if ( $has_events ) {
			$classes[] = 'has-events';
		}
		
		$week[] = '<div class="hbl-calendar-date-wrapper">';
		$week[] = '<button type="button" class="' . implode( ' ', $classes ) . '" ';
		$week[] = 'data-date="' . esc_attr( $date_string ) . '" ';
		$week[] = 'data-year="' . esc_attr( $year ) . '" ';
		$week[] = 'data-month="' . esc_attr( $month ) . '" ';
		$week[] = 'data-day="' . esc_attr( $day ) . '">';
		$week[] = esc_html( $day );
		$week[] = '</button>';
		$week[] = '</div>';
		
		$days_in_week++;
		
		if ( $days_in_week == 7 ) {
			$calendar_html .= '<div class="hbl-calendar-week">' . implode( '', $week ) . '</div>';
			$week = array();
			$days_in_week = 0;
		}
	}
	
	while ( $days_in_week < 7 && $days_in_week > 0 ) {
		$week[] = '<div class="hbl-calendar-date-wrapper other-month"><div class="hbl-calendar-date empty"></div></div>';
		$days_in_week++;
	}
	
	if ( $days_in_week > 0 ) {
		$calendar_html .= '<div class="hbl-calendar-week">' . implode( '', $week ) . '</div>';
	}
	
	wp_send_json_success( array( 'calendar' => $calendar_html ) );
}
add_action( 'wp_ajax_hbl_get_calendar_month', 'hbl_get_calendar_month' );
add_action( 'wp_ajax_nopriv_hbl_get_calendar_month', 'hbl_get_calendar_month' );

function hbl_get_explore_category_events() {
	check_ajax_referer( 'hbl_nonce', 'nonce' );
	
	if ( ! hbl_is_piecal_active() ) {
		wp_send_json_error( array( 'message' => 'Pie Calendar plugin is required.' ) );
		return;
	}
	
	$days_range = isset( $_POST['days_range'] ) ? intval( $_POST['days_range'] ) : 7;
	$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( $_POST['post_type'] ) : 'any';
	$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_text_field( $_POST['taxonomy'] ) : '';
	$terms = isset( $_POST['terms'] ) ? sanitize_text_field( $_POST['terms'] ) : '';
	$placeholder_image = isset( $_POST['placeholder_image'] ) ? esc_url_raw( $_POST['placeholder_image'] ) : '';
	
	$start_date = current_time( 'Y-m-d' ) . 'T00:00:00';
	$end_date = date( 'Y-m-d', strtotime( '+' . $days_range . ' days' ) ) . 'T23:59:59';
	
	$args = array(
		'post_type'      => $post_type,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'     => '_piecal_is_event',
				'value'   => '1',
				'compare' => '=',
			),
			array(
				'key'     => '_piecal_start_date',
				'value'   => '',
				'compare' => '!=',
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => '_piecal_start_date',
					'value'   => array( $start_date, $end_date ),
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				),
				array(
					'relation' => 'AND',
					array(
						'key'     => '_piecal_start_date',
						'value'   => $start_date,
						'compare' => '<=',
						'type'    => 'DATETIME',
					),
					array(
						'key'     => '_piecal_end_date',
						'value'   => $start_date,
						'compare' => '>=',
						'type'    => 'DATETIME',
					),
				),
			),
		),
		'orderby'        => 'meta_value',
		'meta_key'       => '_piecal_start_date',
		'order'          => 'ASC',
	);
	
	if ( ! empty( $taxonomy ) && ! empty( $terms ) ) {
		$term_array = array_map( 'trim', explode( ',', $terms ) );
		$args['tax_query'] = array(
			array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $term_array,
			),
		);
	}
	
	$query = new WP_Query( $args );
	
	if ( empty( $query->posts ) ) {
		wp_send_json_error( array( 'message' => 'No events found.' ) );
		return;
	}
	
	$events_data = array();
	foreach ( $query->posts as $event ) {
		$event_id = $event->ID;
		$event_image = get_the_post_thumbnail_url( $event_id, 'large' );
		
		if ( ! $event_image && ! empty( $placeholder_image ) ) {
			$event_image = $placeholder_image;
		}
		
		$start_date_raw = hbl_get_piecal_start_date( $event_id );
		$formatted_date = ! empty( $start_date_raw ) ? date( 'F j, Y', strtotime( $start_date_raw ) ) : '';
		
		$events_data[] = array(
			'id'      => $event_id,
			'title'   => get_the_title( $event_id ),
			'url'     => get_permalink( $event_id ),
			'image'   => $event_image ? $event_image : '',
			'date'    => $formatted_date,
			'time'    => hbl_get_piecal_time_display( $event_id ),
		);
	}
	
	wp_reset_postdata();
	wp_send_json_success( array( 'events' => $events_data ) );
}
add_action( 'wp_ajax_hbl_get_explore_category_events', 'hbl_get_explore_category_events' );
add_action( 'wp_ajax_nopriv_hbl_get_explore_category_events', 'hbl_get_explore_category_events' );


function hbl_delete_listing() {
	if ( ! wp_verify_nonce( $_POST['nonce'], 'hbl_nonce' ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'Not logged in' );
	}

	$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	
	if ( ! $listing_id ) {
		wp_send_json_error( 'Invalid listing ID' );
	}

	$listing = get_post( $listing_id );
	if ( ! $listing || $listing->post_author != get_current_user_id() ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$result = wp_delete_post( $listing_id, true );
	
	if ( $result ) {
		wp_send_json_success( array( 'message' => 'Listing deleted successfully' ) );
	} else {
		wp_send_json_error( 'Failed to delete listing' );
	}
}
add_action( 'wp_ajax_hbl_delete_listing', 'hbl_delete_listing' );

function hbl_remove_favorite() {
	if ( ! wp_verify_nonce( $_POST['nonce'], 'hbl_nonce' ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'Not logged in' );
	}

	$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	$user_id = get_current_user_id();
	
	if ( ! $listing_id ) {
		wp_send_json_error( 'Invalid listing ID' );
	}

	$favorites = get_user_meta( $user_id, 'atbdp_favourites', true );
	
	if ( ! is_array( $favorites ) ) {
		$favorites = array();
	}

	$key = array_search( $listing_id, $favorites );
	if ( $key !== false ) {
		unset( $favorites[ $key ] );
		$favorites = array_values( $favorites );
		update_user_meta( $user_id, 'atbdp_favourites', $favorites );
		wp_send_json_success( array( 'message' => 'Removed from favorites' ) );
	} else {
		wp_send_json_error( 'Not in favorites' );
	}
}
add_action( 'wp_ajax_hbl_remove_favorite', 'hbl_remove_favorite' );

function hbl_update_profile() {
	if ( ! wp_verify_nonce( $_POST['hbl_profile_nonce'], 'hbl_update_profile' ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'Not logged in' );
	}

	$user_id = get_current_user_id();
	
	$user_data = array(
		'ID' => $user_id,
	);
	
	if ( isset( $_POST['first_name'] ) ) {
		$user_data['first_name'] = sanitize_text_field( $_POST['first_name'] );
	}
	if ( isset( $_POST['last_name'] ) ) {
		$user_data['last_name'] = sanitize_text_field( $_POST['last_name'] );
	}
	if ( isset( $_POST['email'] ) && is_email( $_POST['email'] ) ) {
		$user_data['user_email'] = sanitize_email( $_POST['email'] );
	}
	if ( isset( $_POST['website'] ) ) {
		$user_data['user_url'] = esc_url_raw( $_POST['website'] );
	}
	
	$result = wp_update_user( $user_data );
	
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}
	
	if ( isset( $_POST['phone'] ) ) {
		update_user_meta( $user_id, 'atbdp_phone', sanitize_text_field( $_POST['phone'] ) );
	}
	if ( isset( $_POST['address'] ) ) {
		update_user_meta( $user_id, 'address', sanitize_text_field( $_POST['address'] ) );
	}
	if ( isset( $_POST['bio'] ) ) {
		update_user_meta( $user_id, 'description', sanitize_textarea_field( $_POST['bio'] ) );
	}
	
	if ( isset( $_POST['profile_image'] ) ) {
		$profile_image_id = absint( $_POST['profile_image'] );
		if ( $profile_image_id > 0 ) {
			update_user_meta( $user_id, 'hbl_profile_image', $profile_image_id );
		} else {
			delete_user_meta( $user_id, 'hbl_profile_image' );
		}
	}
	
	if ( isset( $_POST['facebook'] ) ) {
		update_user_meta( $user_id, 'atbdp_facebook', esc_url_raw( $_POST['facebook'] ) );
	}
	if ( isset( $_POST['twitter'] ) ) {
		update_user_meta( $user_id, 'atbdp_twitter', esc_url_raw( $_POST['twitter'] ) );
	}
	if ( isset( $_POST['linkedin'] ) ) {
		update_user_meta( $user_id, 'atbdp_linkedin', esc_url_raw( $_POST['linkedin'] ) );
	}
	
	if ( ! empty( $_POST['current_password'] ) && ! empty( $_POST['new_password'] ) ) {
		$user = get_user_by( 'id', $user_id );
		
		if ( ! wp_check_password( $_POST['current_password'], $user->user_pass, $user_id ) ) {
			wp_send_json_error( 'Current password is incorrect' );
		}
		
		if ( $_POST['new_password'] !== $_POST['confirm_password'] ) {
			wp_send_json_error( 'New passwords do not match' );
		}
		
		wp_set_password( $_POST['new_password'], $user_id );
	}
	
	wp_send_json_success( array( 'message' => 'Profile updated successfully' ) );
}
add_action( 'wp_ajax_hbl_update_profile', 'hbl_update_profile' );

function hbl_save_profile_image() {
	if ( ! isset( $_POST['hbl_profile_nonce'] ) || ! wp_verify_nonce( $_POST['hbl_profile_nonce'], 'hbl_update_profile' ) ) {
		wp_send_json_error( 'Security check failed' );
	}
	
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'You must be logged in' );
	}
	
	$user_id = get_current_user_id();
	
	if ( isset( $_POST['profile_image'] ) ) {
		$profile_image_id = absint( $_POST['profile_image'] );
		if ( $profile_image_id > 0 ) {
			update_user_meta( $user_id, 'hbl_profile_image', $profile_image_id );
		} else {
			delete_user_meta( $user_id, 'hbl_profile_image' );
		}
	}
	
	wp_send_json_success( array( 'message' => 'Profile image saved successfully' ) );
}
add_action( 'wp_ajax_hbl_save_profile_image', 'hbl_save_profile_image' );

function hbl_toggle_favorite() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'hbl_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Invalid security token' ) );
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Please log in to add favorites', 'login_required' => true ) );
	}

	$user_id = get_current_user_id();
	$item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
	$item_type = isset( $_POST['item_type'] ) ? sanitize_text_field( $_POST['item_type'] ) : 'listing';

	if ( ! $item_id ) {
		wp_send_json_error( array( 'message' => 'Invalid item' ) );
	}

	$meta_key = ( $item_type === 'event' ) ? 'hbl_favorite_events' : 'atbdp_favourites';

	$favorites = get_user_meta( $user_id, $meta_key, true );
	$favorites = is_array( $favorites ) ? $favorites : array();

	$is_favorited = in_array( $item_id, $favorites );
	
	if ( $is_favorited ) {
		$favorites = array_diff( $favorites, array( $item_id ) );
		$message = ( $item_type === 'event' ) ? 'Event removed from favorites' : 'Listing removed from favorites';
	} else {
		$favorites[] = $item_id;
		$message = ( $item_type === 'event' ) ? 'Event added to favorites' : 'Listing added to favorites';
	}

	update_user_meta( $user_id, $meta_key, array_values( $favorites ) );

	wp_send_json_success( array( 
		'message' => $message,
		'is_favorited' => ! $is_favorited,
		'count' => count( $favorites )
	) );
}
add_action( 'wp_ajax_hbl_toggle_favorite', 'hbl_toggle_favorite' );
add_action( 'wp_ajax_nopriv_hbl_toggle_favorite', 'hbl_toggle_favorite' );

function hbl_save_event() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'hbl_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'You must be logged in' ) );
	}

	$recaptcha_result = hbl_verify_recaptcha( isset( $_POST['g-recaptcha-response'] ) ? $_POST['g-recaptcha-response'] : '' );
	if ( true !== $recaptcha_result ) {
		wp_send_json_error( array( 'message' => $recaptcha_result ) );
	}

	$user_id   = get_current_user_id();
	$event_id  = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
	$title     = isset( $_POST['event_title'] ) ? sanitize_text_field( wp_unslash( $_POST['event_title'] ) ) : '';
	$content   = isset( $_POST['event_content'] ) ? wp_kses_post( wp_unslash( $_POST['event_content'] ) ) : '';
	$category  = isset( $_POST['event_category'] ) ? absint( $_POST['event_category'] ) : null;
	$tags_raw  = isset( $_POST['event_tags'] ) ? (array) $_POST['event_tags'] : array();
	$tags_ids  = array_filter( array_map( 'absint', $tags_raw ) );
	$tags      = ! empty( $tags_ids ) ? implode( ',', $tags_ids ) : null;
	$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : '';
	$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : '';
	$is_allday  = isset( $_POST['is_allday'] ) ? 1 : 0;
	$event_color = isset( $_POST['event_color'] ) ? sanitize_hex_color( $_POST['event_color'] ) : '#008080';

	$venue          = isset( $_POST['event_venue'] ) ? sanitize_text_field( wp_unslash( $_POST['event_venue'] ) ) : '';
	$address        = isset( $_POST['event_address'] ) ? sanitize_text_field( wp_unslash( $_POST['event_address'] ) ) : '';
	$event_url      = isset( $_POST['event_url'] ) ? esc_url_raw( $_POST['event_url'] ) : '';
	$contact_email  = isset( $_POST['contact_email'] ) ? sanitize_email( $_POST['contact_email'] ) : '';
	$event_type     = isset( $_POST['event_type'] ) ? sanitize_text_field( $_POST['event_type'] ) : '';
	$event_cost     = isset( $_POST['event_cost'] ) ? sanitize_text_field( $_POST['event_cost'] ) : 'free';
	$scheduling_type = isset( $_POST['scheduling_type'] ) ? sanitize_text_field( $_POST['scheduling_type'] ) : 'single';
	$event_frequency = isset( $_POST['event_frequency'] ) ? sanitize_text_field( $_POST['event_frequency'] ) : '';
	$is_program     = ! empty( $_POST['is_program'] ) ? 1 : 0;
	$organiser_type = isset( $_POST['organiser_type'] ) ? sanitize_text_field( $_POST['organiser_type'] ) : '';
	$featured_image = isset( $_POST['featured_image'] ) ? absint( $_POST['featured_image'] ) : null;

	$recurrence_type = isset( $_POST['recurrence_type'] ) ? sanitize_text_field( $_POST['recurrence_type'] ) : null;
	$recurrence_interval = isset( $_POST['recurrence_interval'] ) ? absint( $_POST['recurrence_interval'] ) : 1;
	
	$start_date = '';
	$end_date = '';
	$daily_start_time = null;
	$daily_end_time = null;
	$recurrence_days = null;
	$recurrence_week = null;

	if ( $scheduling_type === 'single' ) {
		$date_part = isset( $_POST['start_date_single'] ) ? sanitize_text_field( $_POST['start_date_single'] ) : '';
		$start_time = isset( $_POST['start_time_single'] ) ? sanitize_text_field( $_POST['start_time_single'] ) : '00:00';
		$end_time = isset( $_POST['end_time_single'] ) ? sanitize_text_field( $_POST['end_time_single'] ) : '00:00';

		if ( ! empty( $date_part ) ) {
			$start_date = $date_part . ' ' . $start_time . ':00';
			$end_date = $date_part . ' ' . $end_time . ':00';
		}

		if ( $event_frequency === 'weekly' && ! empty( $_POST['recurrence_days'] ) ) {
			$days = array_map( 'sanitize_text_field', (array) $_POST['recurrence_days'] );
			$valid_days = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
			$days = array_intersect( $days, $valid_days );
			$recurrence_days = ! empty( $days ) ? implode( ',', $days ) : null;
		} elseif ( $event_frequency === 'monthly' && ! empty( $_POST['recurrence_day_monthly'] ) ) {
			$day = sanitize_text_field( $_POST['recurrence_day_monthly'] );
			$valid_days = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
			$recurrence_days = in_array( $day, $valid_days, true ) ? $day : null;
		}

		if ( $event_frequency === 'monthly' && ! empty( $_POST['recurrence_week'] ) ) {
			$weeks = array_map( 'sanitize_text_field', (array) $_POST['recurrence_week'] );
			$valid_weeks = array( '1', '2', '3', '4', '5' );
			$weeks = array_intersect( $weeks, $valid_weeks );
			$recurrence_week = ! empty( $weeks ) ? implode( ',', $weeks ) : null;
		}

	} elseif ( $scheduling_type === 'multi' ) {
		$start_part = isset( $_POST['start_date_multi'] ) ? sanitize_text_field( $_POST['start_date_multi'] ) : '';
		$end_part = isset( $_POST['end_date_multi'] ) ? sanitize_text_field( $_POST['end_date_multi'] ) : '';
		$daily_start = isset( $_POST['daily_start_time'] ) ? sanitize_text_field( $_POST['daily_start_time'] ) : null;
		$daily_end = isset( $_POST['daily_end_time'] ) ? sanitize_text_field( $_POST['daily_end_time'] ) : null;
		
		if ( ! empty( $start_part ) ) {
			$start_date = $start_part . ' 00:00:00';
		}
		if ( ! empty( $end_part ) ) {
			$end_date = $end_part . ' 23:59:59';
		}

		$daily_start_time = $daily_start ? $daily_start . ':00' : null;
		$daily_end_time = $daily_end ? $daily_end . ':00' : null;

		if ( ! empty( $_POST['multi_open_days'] ) ) {
			error_log( '?? PHP - Multi Open Days Received: ' . $_POST['multi_open_days'] );
			$days = explode(',', sanitize_text_field( $_POST['multi_open_days'] ) );
			error_log( '?? PHP - After explode: ' . print_r( $days, true ) );
			$valid_days = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
			$days = array_intersect( $days, $valid_days );
			error_log( '?? PHP - After array_intersect: ' . print_r( $days, true ) );
			$recurrence_days = ! empty( $days ) ? implode( ',', $days ) : null;
			error_log( '?? PHP - Final recurrence_days to save: ' . $recurrence_days );
		} else {
			error_log( '?? PHP - No multi_open_days received in POST' );
		}
		
		$event_frequency = 'multi_day'; 
	} elseif ( isset($_POST['start_date']) ) {
		$start_date = sanitize_text_field( $_POST['start_date'] );
		$end_date = isset( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : '';
	}

	if ( empty( $title ) || empty( $start_date ) ) {
		wp_send_json_error( array( 'message' => 'Title and start date are required' ) );
	}

	$start_datetime = date( 'Y-m-d H:i:s', strtotime( $start_date ) );
	$end_datetime = ! empty( $end_date ) ? date( 'Y-m-d H:i:s', strtotime( $end_date ) ) : null;

	$internal_tags = hbl_generate_event_internal_tags( $event_type, $event_cost, $event_frequency, $is_program, $organiser_type );

	$event_data = array(
		'user_id'             => $user_id,
		'title'               => $title,
		'description'         => $content,
		'start_date'          => $start_datetime,
		'end_date'            => $end_datetime,
		'daily_start_time'    => $daily_start_time,
		'daily_end_time'      => $daily_end_time,
		'is_allday'           => $is_allday,
		'venue'               => $venue,
		'address'             => $address,
		'event_url'           => $event_url,
		'contact_email'       => $contact_email,
		'event_type'          => $event_type,
		'event_cost'          => $event_cost,
		'scheduling_type'     => $scheduling_type,
		'event_frequency'     => $event_frequency,
		'recurrence_type'     => $recurrence_type,
		'recurrence_interval' => $recurrence_interval,
		'recurrence_days'     => $recurrence_days,
		'recurrence_week'     => $recurrence_week,
		'is_program'          => $is_program,
		'organiser_type'      => $organiser_type,
		'category_id'         => $category > 0 ? $category : null,
		'tags'                => $tags,
		'featured_image'      => $featured_image > 0 ? $featured_image : null,
		'event_color'         => $event_color,
		'internal_tags'       => $internal_tags,
		'status'              => 'publish',
	);

	error_log( '?? PHP - Event data array about to be saved:' );
	error_log( '?? PHP - scheduling_type: ' . $event_data['scheduling_type'] );
	error_log( '?? PHP - event_frequency: ' . $event_data['event_frequency'] );
	error_log( '?? PHP - recurrence_days: ' . $event_data['recurrence_days'] );
	error_log( '?? PHP - daily_start_time: ' . $event_data['daily_start_time'] );
	error_log( '?? PHP - daily_end_time: ' . $event_data['daily_end_time'] );

	$db = hbl_events_db();

	if ( $event_id ) {
		if ( ! $db->user_owns_event( $event_id, $user_id ) && ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to edit this event' ) );
		}
		
		$result = $db->update( $event_id, $event_data );
		
		if ( ! $result ) {
			wp_send_json_error( array( 'message' => 'Failed to update event' ) );
		}
		
		$updated_event = $db->get( $event_id );
		
		wp_send_json_success( array( 
			'message'  => 'Event updated successfully',
			'event_id' => $event_id,
			'slug'     => $updated_event ? $updated_event->slug : '',
			'debug'    => array(
				'received_multi_open_days' => isset($_POST['multi_open_days']) ? $_POST['multi_open_days'] : 'NOT SET',
				'saved_recurrence_days'    => $event_data['recurrence_days'],
				'saved_scheduling_type'    => $event_data['scheduling_type'],
				'saved_event_frequency'    => $event_data['event_frequency'],
				'db_recurrence_days'       => $updated_event ? $updated_event->recurrence_days : 'N/A',
			),
		) );
	} else {
		$new_event_id = $db->insert( $event_data );
		
		if ( ! $new_event_id ) {
			wp_send_json_error( array( 'message' => 'Failed to create event' ) );
		}
		
		$created_event = $db->get( $new_event_id );
		
		wp_send_json_success( array( 
			'message'  => 'Event submitted successfully!',
			'event_id' => $new_event_id,
			'slug'     => $created_event ? $created_event->slug : '',
			'debug'    => array(
				'received_multi_open_days' => isset($_POST['multi_open_days']) ? $_POST['multi_open_days'] : 'NOT SET',
				'saved_recurrence_days'    => $event_data['recurrence_days'],
				'saved_scheduling_type'    => $event_data['scheduling_type'],
				'saved_event_frequency'    => $event_data['event_frequency'],
				'db_recurrence_days'       => $created_event ? $created_event->recurrence_days : 'N/A',
			),
		) );
	}
}
add_action( 'wp_ajax_hbl_save_event', 'hbl_save_event' );

function hbl_generate_event_internal_tags( $event_type, $event_cost, $event_frequency, $is_program, $organiser_type ) {
	$tags = array();

	$type_tags = array(
		'community'     => 'event-type-community',
		'workshop'      => 'event-type-workshop',
		'market'        => 'event-type-market',
		'business'      => 'event-type-business',
		'entertainment' => 'event-type-entertainment',
		'other'         => 'event-type-other',
	);
	if ( $event_type && isset( $type_tags[ $event_type ] ) ) {
		$tags[] = $type_tags[ $event_type ];
	}

	$cost_tags = array(
		'free' => 'event-free',
		'paid' => 'event-paid',
	);
	if ( $event_cost && isset( $cost_tags[ $event_cost ] ) ) {
		$tags[] = $cost_tags[ $event_cost ];
	}

	$frequency_tags = array(
		'once'      => 'event-once',
		'weekly'    => 'event-weekly',
		'monthly'   => 'event-monthly',
		'recurring' => 'event-recurring',
	);
	if ( $event_frequency && isset( $frequency_tags[ $event_frequency ] ) ) {
		$tags[] = $frequency_tags[ $event_frequency ];
	}

	if ( $is_program ) {
		$tags[] = 'event-program';
	}

	$organiser_tags = array(
		'individual' => 'organiser-individual',
		'community'  => 'organiser-community',
		'business'   => 'organiser-business',
	);
	if ( $organiser_type && isset( $organiser_tags[ $organiser_type ] ) ) {
		$tags[] = $organiser_tags[ $organiser_type ];
	}

	return $tags;
}

function hbl_delete_event() {
	if ( ! wp_verify_nonce( $_POST['nonce'], 'hbl_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'You must be logged in' ) );
	}

	$user_id  = get_current_user_id();
	$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;

	if ( ! $event_id || ! function_exists( 'hbl_events_db' ) ) {
		wp_send_json_error( array( 'message' => 'Invalid event ID' ) );
	}

	if ( ! hbl_events_db()->user_owns_event( $event_id, $user_id ) ) {
		wp_send_json_error( array( 'message' => 'You do not have permission to delete this event' ) );
	}

	if ( hbl_events_db()->delete( $event_id ) ) {
		wp_send_json_success( array( 'message' => 'Event deleted successfully' ) );
	} else {
		wp_send_json_error( array( 'message' => 'Failed to delete event' ) );
	}
}
add_action( 'wp_ajax_hbl_delete_event', 'hbl_delete_event' );

function hbl_save_listing() {
	if ( ! isset( $_POST['listing_nonce'] ) || ! wp_verify_nonce( $_POST['listing_nonce'], 'hbl_listing_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Invalid security token. Please refresh the page.' ) );
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'You must be logged in to submit a listing.' ) );
	}

	$recaptcha_result = hbl_verify_recaptcha( isset( $_POST['g-recaptcha-response'] ) ? $_POST['g-recaptcha-response'] : '' );
	if ( true !== $recaptcha_result ) {
		wp_send_json_error( array( 'message' => $recaptcha_result ) );
	}

	if ( ! defined( 'ATBDP_VERSION' ) ) {
		wp_send_json_error( array( 'message' => 'Directorist plugin is required.' ) );
	}

	$user_id    = get_current_user_id();
	$editing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	$title      = isset( $_POST['listing_title'] ) ? sanitize_text_field( $_POST['listing_title'] ) : '';
	$content    = isset( $_POST['listing_content'] ) ? wp_kses_post( $_POST['listing_content'] ) : '';
	$tagline    = isset( $_POST['listing_tagline'] ) ? sanitize_text_field( $_POST['listing_tagline'] ) : '';
	
	$category_input = isset( $_POST['listing_category'] ) ? sanitize_text_field( $_POST['listing_category'] ) : '';
	$categories = array();
	if ( ! empty( $category_input ) ) {
		if ( strpos( $category_input, ',' ) !== false ) {
			$category_ids = array_map( 'absint', explode( ',', $category_input ) );
			$categories = array_filter( $category_ids );
		} else {
			$category_id = absint( $category_input );
			if ( $category_id > 0 ) {
				$categories = array( $category_id );
			}
		}
	}
	$category = ! empty( $categories ) ? $categories[0] : 0;
	
	$location   = isset( $_POST['listing_location'] ) ? absint( $_POST['listing_location'] ) : 0;
	$phone      = isset( $_POST['listing_phone'] ) ? sanitize_text_field( $_POST['listing_phone'] ) : '';
	$email      = isset( $_POST['listing_email'] ) ? sanitize_email( $_POST['listing_email'] ) : '';
	$website    = isset( $_POST['listing_website'] ) ? esc_url_raw( $_POST['listing_website'] ) : '';
	$address    = isset( $_POST['listing_address'] ) ? sanitize_text_field( $_POST['listing_address'] ) : '';
	$image_id   = isset( $_POST['listing_image'] ) ? absint( $_POST['listing_image'] ) : 0;
	
	$facebook   = isset( $_POST['listing_facebook'] ) ? esc_url_raw( $_POST['listing_facebook'] ) : '';
	$instagram  = isset( $_POST['listing_instagram'] ) ? esc_url_raw( $_POST['listing_instagram'] ) : '';
	$twitter    = isset( $_POST['listing_twitter'] ) ? esc_url_raw( $_POST['listing_twitter'] ) : '';
	$linkedin   = isset( $_POST['listing_linkedin'] ) ? esc_url_raw( $_POST['listing_linkedin'] ) : '';
	$youtube    = isset( $_POST['listing_youtube'] ) ? esc_url_raw( $_POST['listing_youtube'] ) : '';
	$tiktok     = isset( $_POST['listing_tiktok'] ) ? esc_url_raw( $_POST['listing_tiktok'] ) : '';
	$video      = isset( $_POST['listing_video'] ) ? esc_url_raw( $_POST['listing_video'] ) : '';

	if ( empty( $title ) ) {
		wp_send_json_error( array( 'message' => 'Business name is required.' ) );
	}

	if ( empty( $content ) ) {
		wp_send_json_error( array( 'message' => 'Description is required.' ) );
	}

	if ( empty( $categories ) ) {
		wp_send_json_error( array( 'message' => 'Please select a category.' ) );
	}
	
	$is_update = false;
	if ( $editing_id ) {
		$existing_listing = get_post( $editing_id );
		if ( $existing_listing && 
		     get_post_type( $existing_listing ) === ATBDP_POST_TYPE && 
		     ( (int) $existing_listing->post_author === $user_id || current_user_can( 'edit_others_posts' ) ) ) {
			$is_update = true;
		} else {
			wp_send_json_error( array( 'message' => 'You do not have permission to edit this listing.' ) );
		}
	}

	$plan_id = 0;
	if ( isset( $_POST['listing_package'] ) && ! empty( $_POST['listing_package'] ) ) {
		$plan_id = absint( $_POST['listing_package'] );
	} elseif ( isset( $_GET['plan'] ) ) {
		$plan_id = absint( $_GET['plan'] );
	} elseif ( isset( $_POST['plan_id'] ) ) {
		$plan_id = absint( $_POST['plan_id'] );
	} elseif ( isset( $_GET['plan_id'] ) ) {
		$plan_id = absint( $_GET['plan_id'] );
	}
	$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : ( isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0 );

	if ( $is_update ) {
		$post_status = get_post_status( $editing_id );
	} else {
		if ( $plan_id > 0 ) {
			$plan_tier = hbl_get_plan_tier( $plan_id );
			
			if ( $plan_tier === 'bronze' ) {
				$post_status = 'pending';
			} else {
				$post_status = 'publish';
			}
		} else {
			$post_status = get_directorist_option( 'new_listing_status', 'pending' );
		}
	}

	$post_data = array(
		'post_title'   => $title,
		'post_content' => $content,
		'post_status'  => $post_status,
		'post_type'    => ATBDP_POST_TYPE,
		'post_author'  => $user_id,
	);

	if ( $is_update ) {
		$post_data['ID'] = $editing_id;
		$listing_id = wp_update_post( $post_data );
	} else {
		$listing_id = wp_insert_post( $post_data );
	}

	if ( is_wp_error( $listing_id ) || ! $listing_id ) {
		wp_send_json_error( array( 'message' => $is_update ? 'Failed to update listing. Please try again.' : 'Failed to create listing. Please try again.' ) );
	}

	if ( $is_update && get_post_meta( $listing_id, '_claimed_by_admin', true ) ) {
		do_action( 'hbl_owner_edited_listing', (int) $listing_id, (int) $user_id );
	}

	if ( ! empty( $categories ) ) {
		wp_set_object_terms( $listing_id, $categories, ATBDP_CATEGORY );
	}

	if ( $location ) {
		wp_set_object_terms( $listing_id, $location, ATBDP_LOCATION );
	}

	$tags_input = $tagline;
	$tags_taxonomy = 'at_biz_dir-tags';
	
	if ( ! empty( $tags_input ) && taxonomy_exists( $tags_taxonomy ) ) {
		$tag_names = array_map( 'trim', explode( ',', $tags_input ) );
		$tag_names = array_filter( $tag_names );
		
		if ( ! empty( $tag_names ) ) {
			$tag_ids = array();
			foreach ( $tag_names as $tag_name ) {
				if ( empty( $tag_name ) ) continue;
				
				$term = term_exists( $tag_name, $tags_taxonomy );
				
				if ( $term ) {
					$tag_ids[] = (int) $term['term_id'];
				} else {
					$new_term = wp_insert_term( $tag_name, $tags_taxonomy );
					if ( ! is_wp_error( $new_term ) && isset( $new_term['term_id'] ) ) {
						$tag_ids[] = (int) $new_term['term_id'];
					}
				}
			}
			
			if ( ! empty( $tag_ids ) ) {
				wp_set_object_terms( $listing_id, $tag_ids, $tags_taxonomy );
			}
		}
	} else {
		if ( taxonomy_exists( $tags_taxonomy ) ) {
			wp_set_object_terms( $listing_id, array(), $tags_taxonomy );
		}
	}

	update_post_meta( $listing_id, '_tagline', $tagline );
	update_post_meta( $listing_id, '_phone', $phone );
	update_post_meta( $listing_id, '_email', $email );
	update_post_meta( $listing_id, '_website', $website );
	update_post_meta( $listing_id, '_address', $address );

	if ( $image_id ) {
		set_post_thumbnail( $listing_id, $image_id );
		
		update_post_meta( $listing_id, '_listing_prv_img', $image_id );
		
		$logo_url = wp_get_attachment_image_url( $image_id, 'full' );
		$logo_title = get_the_title( $image_id );
		if ( $logo_url ) {
			$custom_file_value = $logo_url . '|' . $image_id . '|' . $logo_title . '|';
			update_post_meta( $listing_id, '_custom-file', $custom_file_value );
			update_post_meta( $listing_id, 'custom-file', $custom_file_value );
			
			update_post_meta( $listing_id, '_custom-file-url', $logo_url );
		}
	} else {
		delete_post_meta( $listing_id, '_listing_prv_img' );
		delete_post_meta( $listing_id, '_custom-file' );
		delete_post_meta( $listing_id, 'custom-file' );
		delete_post_meta( $listing_id, '_custom-file-url' );
		delete_post_thumbnail( $listing_id );
	}
	
	$gallery_input = isset( $_POST['listing_gallery'] ) ? sanitize_text_field( $_POST['listing_gallery'] ) : '';
	
	if ( ! empty( $gallery_input ) ) {
		$gallery_ids = array_map( 'absint', explode( ',', $gallery_input ) );
		$gallery_ids = array_filter( $gallery_ids );
		
		if ( ! empty( $gallery_ids ) ) {
			update_post_meta( $listing_id, '_listing_img', $gallery_ids );
		} else {
			delete_post_meta( $listing_id, '_listing_img' );
		}
	} else {
		delete_post_meta( $listing_id, '_listing_img' );
	}
	
	update_post_meta( $listing_id, '_facebook', $facebook );
	update_post_meta( $listing_id, '_instagram', $instagram );
	update_post_meta( $listing_id, '_twitter', $twitter );
	update_post_meta( $listing_id, '_linkedin', $linkedin );
	update_post_meta( $listing_id, '_youtube', $youtube );
	update_post_meta( $listing_id, '_tiktok', $tiktok );
	
	$social_array = array();
	if ( ! empty( $facebook ) ) {
		$social_array[] = array( 'id' => 'facebook', 'url' => $facebook );
	}
	if ( ! empty( $instagram ) ) {
		$social_array[] = array( 'id' => 'instagram', 'url' => $instagram );
	}
	if ( ! empty( $twitter ) ) {
		$social_array[] = array( 'id' => 'twitter', 'url' => $twitter );
	}
	if ( ! empty( $linkedin ) ) {
		$social_array[] = array( 'id' => 'linkedin', 'url' => $linkedin );
	}
	if ( ! empty( $youtube ) ) {
		$social_array[] = array( 'id' => 'youtube', 'url' => $youtube );
	}
	if ( ! empty( $tiktok ) ) {
		$social_array[] = array( 'id' => 'tiktok', 'url' => $tiktok );
	}
	
	if ( ! empty( $social_array ) ) {
		update_post_meta( $listing_id, '_social', $social_array );
		update_post_meta( $listing_id, 'social', $social_array );
	} else {
		delete_post_meta( $listing_id, '_social' );
		delete_post_meta( $listing_id, 'social' );
	}
	
	update_post_meta( $listing_id, '_videourl', $video );
	update_post_meta( $listing_id, 'videourl', $video );

	$directory_type = isset( $_GET['directory_type'] ) ? absint( $_GET['directory_type'] ) : 0;
	if ( $directory_type ) {
		update_post_meta( $listing_id, '_directory_type', $directory_type );
	}

	if ( $plan_id ) {
		update_post_meta( $listing_id, '_fm_plans', $plan_id );
	}

	if ( $order_id ) {
		update_post_meta( $listing_id, '_order_id', $order_id );
	}

	$services = isset( $_POST['listing_services'] ) ? array_map( 'sanitize_text_field', $_POST['listing_services'] ) : array();
	$services = array_filter( $services );
	
	if ( ! empty( $services ) ) {
		$services_text = implode( "\n", $services );
		update_post_meta( $listing_id, '_custom-textarea', $services_text );
		update_post_meta( $listing_id, 'custom-textarea', $services_text );
		update_post_meta( $listing_id, '_services', $services_text );
		update_post_meta( $listing_id, 'services', $services_text );
	} else {
		delete_post_meta( $listing_id, '_custom-textarea' );
		delete_post_meta( $listing_id, 'custom-textarea' );
		delete_post_meta( $listing_id, '_services' );
		delete_post_meta( $listing_id, 'services' );
	}

	$pricing = isset( $_POST['listing_pricing'] ) ? array_map( 'sanitize_text_field', $_POST['listing_pricing'] ) : array();
	$pricing = array_filter( $pricing );
	
	if ( ! empty( $pricing ) ) {
		$pricing_text = implode( "\n", $pricing );
		update_post_meta( $listing_id, '_custom-textarea-2', $pricing_text );
		update_post_meta( $listing_id, 'custom-textarea-2', $pricing_text );
		update_post_meta( $listing_id, '_pricing', $pricing_text );
		update_post_meta( $listing_id, 'pricing', $pricing_text );
	} else {
		delete_post_meta( $listing_id, '_custom-textarea-2' );
		delete_post_meta( $listing_id, 'custom-textarea-2' );
		delete_post_meta( $listing_id, '_pricing' );
		delete_post_meta( $listing_id, 'pricing' );
	}
	
	update_post_meta( $listing_id, '_address', $address );
	update_post_meta( $listing_id, 'address', $address );
	update_post_meta( $listing_id, '_custom-text', $address );
	update_post_meta( $listing_id, 'custom-text', $address );

	$dashboard_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_dashboard_page_link() : home_url();
	$listing_url = get_permalink( $listing_id );

	$requires_payment = false;
	$checkout_url = '';
	
	if ( ! $is_update && $plan_id > 0 ) {
		$plan_price = 0;
		if ( function_exists( 'atpp_total_price' ) ) {
			$plan_price = atpp_total_price( $plan_id );
		} else {
			$plan_price = floatval( get_post_meta( $plan_id, 'fm_price', true ) );
			$is_free_plan = get_post_meta( $plan_id, 'free_plan', true );
			if ( $is_free_plan ) {
				$plan_price = 0;
			}
		}
		
		if ( $plan_price > 0 ) {
			$requires_payment = true;
			wp_update_post( array(
				'ID'          => $listing_id,
				'post_status' => 'pending',
			) );
			
			$checkout_url = add_query_arg( array(
				'listing_id' => $listing_id,
				'plan_id'    => $plan_id,
			), home_url( '/checkout/' ) );
		}
	}

	if ( $requires_payment ) {
		$redirect_url = $checkout_url;
	} else {
		$redirect_url = $post_status === 'publish' ? $listing_url : $dashboard_url;
	}

	if ( $is_update ) {
		$message = 'Your listing has been updated successfully!';
	} elseif ( $requires_payment ) {
		$message = 'Your listing has been saved! Redirecting to checkout...';
	} else {
		$message = $post_status === 'publish' 
			? 'Your listing has been published successfully!' 
			: 'Your listing has been submitted and is pending review.';
	}

	wp_send_json_success( array(
		'message'          => $message,
		'listing_id'       => $listing_id,
		'redirect_url'     => $redirect_url,
		'is_update'        => $is_update,
		'requires_payment' => $requires_payment,
	) );
}
add_action( 'wp_ajax_hbl_save_listing', 'hbl_save_listing' );

function hbl_debug_listing_meta() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}
	
	$listing_id = isset( $_GET['listing_id'] ) ? absint( $_GET['listing_id'] ) : 0;
	
	if ( ! $listing_id ) {
		wp_send_json_error( array( 'message' => 'No listing ID provided' ) );
	}
	
	$meta_keys = array(
		'_listing_prv_img',
		'_listing_img',
		'custom-file',
		'_services',
		'services',
		'_pricing',
		'pricing',
		'_address',
		'_phone',
		'_email',
		'_website',
		'_facebook',
		'_instagram',
		'_twitter',
		'_linkedin',
		'_youtube',
		'_tiktok',
		'_videourl',
		'_tagline',
		'_fm_plans',
		'_directory_type',
	);
	
	$meta_data = array();
	foreach ( $meta_keys as $key ) {
		$value = get_post_meta( $listing_id, $key, true );
		$meta_data[ $key ] = array(
			'value' => $value,
			'type'  => gettype( $value ),
		);
	}
	
	$categories = get_the_terms( $listing_id, ATBDP_CATEGORY );
	$locations = get_the_terms( $listing_id, ATBDP_LOCATION );
	$tags = get_the_terms( $listing_id, 'at_biz_dir-tags' );
	
	$terms_data = array(
		'categories' => $categories ? wp_list_pluck( $categories, 'name' ) : array(),
		'locations'  => $locations ? wp_list_pluck( $locations, 'name' ) : array(),
		'tags'       => $tags ? wp_list_pluck( $tags, 'name' ) : array(),
	);
	
	$featured_image_id = get_post_thumbnail_id( $listing_id );
	
	wp_send_json_success( array(
		'listing_id'        => $listing_id,
		'meta_data'         => $meta_data,
		'terms_data'        => $terms_data,
		'featured_image_id' => $featured_image_id,
	) );
}
add_action( 'wp_ajax_hbl_debug_listing_meta', 'hbl_debug_listing_meta' );

function hbl_fix_listing_data_format( $post_id ) {
	if ( ! defined( 'ATBDP_POST_TYPE' ) || get_post_type( $post_id ) !== ATBDP_POST_TYPE ) {
		return;
	}
	
	$listing_prv_img = get_post_meta( $post_id, '_listing_prv_img', true );
	$featured_image_id = get_post_thumbnail_id( $post_id );
	
	if ( empty( $listing_prv_img ) && $featured_image_id ) {
		update_post_meta( $post_id, '_listing_prv_img', $featured_image_id );
	}
	
	$listing_img = get_post_meta( $post_id, '_listing_img', true );
	
	if ( ! empty( $listing_img ) && is_string( $listing_img ) ) {
		$gallery_ids = array_filter( array_map( 'absint', explode( ',', $listing_img ) ) );
		if ( ! empty( $gallery_ids ) ) {
			update_post_meta( $post_id, '_listing_img', $gallery_ids );
		}
	}
	
	$services = get_post_meta( $post_id, '_services', true );
	$custom_textarea = get_post_meta( $post_id, '_custom-textarea', true );
	if ( ! empty( $services ) && empty( $custom_textarea ) ) {
		update_post_meta( $post_id, '_custom-textarea', $services );
		update_post_meta( $post_id, 'custom-textarea', $services );
	}
	
	$pricing = get_post_meta( $post_id, '_pricing', true );
	$custom_textarea_2 = get_post_meta( $post_id, '_custom-textarea-2', true );
	if ( ! empty( $pricing ) && empty( $custom_textarea_2 ) ) {
		update_post_meta( $post_id, '_custom-textarea-2', $pricing );
		update_post_meta( $post_id, 'custom-textarea-2', $pricing );
	}
	
	$address = get_post_meta( $post_id, '_address', true );
	$custom_text = get_post_meta( $post_id, '_custom-text', true );
	if ( ! empty( $address ) && empty( $custom_text ) ) {
		update_post_meta( $post_id, '_custom-text', $address );
		update_post_meta( $post_id, 'custom-text', $address );
	}
	
	$custom_file = get_post_meta( $post_id, '_custom-file', true );
	$featured_image_id = get_post_thumbnail_id( $post_id );
	
	$needs_conversion = false;
	if ( ! empty( $custom_file ) ) {
		if ( is_numeric( $custom_file ) || ( is_string( $custom_file ) && strpos( $custom_file, '|' ) === false ) ) {
			$needs_conversion = true;
		}
	} elseif ( $featured_image_id ) {
		$needs_conversion = true;
	}
	
	if ( $needs_conversion && $featured_image_id ) {
		$logo_url = wp_get_attachment_image_url( $featured_image_id, 'full' );
		$logo_title = get_the_title( $featured_image_id );
		if ( $logo_url ) {
			$custom_file_value = $logo_url . '|' . $featured_image_id . '|' . $logo_title . '|';
			update_post_meta( $post_id, '_custom-file', $custom_file_value );
			update_post_meta( $post_id, 'custom-file', $custom_file_value );
			update_post_meta( $post_id, '_custom-file-url', $logo_url );
		}
	}
	
	$social = get_post_meta( $post_id, '_social', true );
	if ( empty( $social ) || ! is_array( $social ) ) {
		$social_array = array();
		$social_fields = array(
			'facebook'  => get_post_meta( $post_id, '_facebook', true ),
			'instagram' => get_post_meta( $post_id, '_instagram', true ),
			'twitter'   => get_post_meta( $post_id, '_twitter', true ),
			'linkedin'  => get_post_meta( $post_id, '_linkedin', true ),
			'youtube'   => get_post_meta( $post_id, '_youtube', true ),
			'tiktok'    => get_post_meta( $post_id, '_tiktok', true ),
		);
		
		foreach ( $social_fields as $network => $url ) {
			if ( ! empty( $url ) ) {
				$social_array[] = array( 'id' => $network, 'url' => $url );
			}
		}
		
		if ( ! empty( $social_array ) ) {
			update_post_meta( $post_id, '_social', $social_array );
			update_post_meta( $post_id, 'social', $social_array );
		}
	}
	
	$listing_prv_img = get_post_meta( $post_id, '_listing_prv_img', true );
	if ( empty( $listing_prv_img ) ) {
		$listing_img = get_post_meta( $post_id, '_listing_img', true );
		if ( ! empty( $listing_img ) ) {
			$gallery_array = is_array( $listing_img ) ? $listing_img : array_filter( array_map( 'absint', explode( ',', $listing_img ) ) );
			if ( ! empty( $gallery_array[0] ) ) {
				update_post_meta( $post_id, '_listing_prv_img', $gallery_array[0] );
				set_post_thumbnail( $post_id, $gallery_array[0] );
			}
		}
	}
}
add_action( 'edit_form_top', function( $post ) {
	if ( $post && defined( 'ATBDP_POST_TYPE' ) && get_post_type( $post ) === ATBDP_POST_TYPE ) {
		hbl_fix_listing_data_format( $post->ID );
	}
}, 1 );

add_action( 'admin_init', function() {
	if ( isset( $_GET['post'] ) && isset( $_GET['action'] ) && $_GET['action'] === 'edit' ) {
		$post_id = absint( $_GET['post'] );
		if ( $post_id && defined( 'ATBDP_POST_TYPE' ) && get_post_type( $post_id ) === ATBDP_POST_TYPE ) {
			hbl_fix_listing_data_format( $post_id );
		}
	}
	
	if ( isset( $_GET['hbl_fix_listing'] ) && isset( $_GET['post'] ) ) {
		$post_id = absint( $_GET['post'] );
		if ( $post_id && current_user_can( 'manage_options' ) ) {
			hbl_fix_listing_data_format( $post_id );
			wp_redirect( remove_query_arg( 'hbl_fix_listing' ) );
			exit;
		}
	}
}, 1 );

function hbl_listing_fix_admin_notice() {
	global $post;
	$screen = get_current_screen();
	
	if ( ! $screen || $screen->base !== 'post' || ! defined( 'ATBDP_POST_TYPE' ) ) {
		return;
	}
	
	if ( ! $post || get_post_type( $post ) !== ATBDP_POST_TYPE ) {
		return;
	}
	
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	
	$post_id = $post->ID;
	$listing_prv_img = get_post_meta( $post_id, '_listing_prv_img', true );
	$listing_img = get_post_meta( $post_id, '_listing_img', true );
	$featured_image_id = get_post_thumbnail_id( $post_id );
	
	$services = get_post_meta( $post_id, '_services', true );
	$custom_textarea = get_post_meta( $post_id, '_custom-textarea', true );
	$pricing = get_post_meta( $post_id, '_pricing', true );
	$custom_textarea_2 = get_post_meta( $post_id, '_custom-textarea-2', true );
	$custom_file = get_post_meta( $post_id, '_custom-file', true );
	$social = get_post_meta( $post_id, '_social', true );
	
	$has_social_fields = ! empty( get_post_meta( $post_id, '_facebook', true ) ) ||
	                     ! empty( get_post_meta( $post_id, '_instagram', true ) ) ||
	                     ! empty( get_post_meta( $post_id, '_twitter', true ) ) ||
	                     ! empty( get_post_meta( $post_id, '_linkedin', true ) ) ||
	                     ! empty( get_post_meta( $post_id, '_youtube', true ) ) ||
	                     ! empty( get_post_meta( $post_id, '_tiktok', true ) );
	
	$issues = array();
	
	if ( empty( $listing_prv_img ) && $featured_image_id ) {
		$issues[] = '_listing_prv_img is not set (Featured Image: ' . $featured_image_id . ')';
	}
	
	if ( ! empty( $listing_img ) && is_string( $listing_img ) ) {
		$issues[] = '_listing_img is a string instead of array: ' . $listing_img;
	}
	
	if ( ! empty( $services ) && empty( $custom_textarea ) ) {
		$issues[] = 'Services (_services) not copied to _custom-textarea';
	}
	
	if ( ! empty( $pricing ) && empty( $custom_textarea_2 ) ) {
		$issues[] = 'Pricing (_pricing) not copied to _custom-textarea-2';
	}
	
	if ( ! empty( $custom_file ) && is_string( $custom_file ) && strpos( $custom_file, '|' ) === false ) {
		$issues[] = '_custom-file not in plupload format (url|id|title|caption)';
	}
	
	if ( empty( $custom_file ) && $featured_image_id ) {
		$issues[] = '_custom-file not set (Featured Image: ' . $featured_image_id . ')';
	}
	
	if ( $has_social_fields && ( empty( $social ) || ! is_array( $social ) ) ) {
		$issues[] = '_social array not built from individual social fields';
	}
	
	if ( ! empty( $issues ) ) {
		$fix_url = add_query_arg( 'hbl_fix_listing', '1' );
		echo '<div class="notice notice-warning"><p><strong>HBL Data Fix Needed:</strong></p><ul>';
		foreach ( $issues as $issue ) {
			echo '<li>' . esc_html( $issue ) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( $fix_url ) . '" class="button button-primary">Fix Now</a></p></div>';
	} else {
		echo '<div class="notice notice-success is-dismissible"><p><strong>HBL:</strong> Listing data format is correct. All fields mapped properly.</p></div>';
	}
}
add_action( 'admin_notices', 'hbl_listing_fix_admin_notice' );


function hbl_sync_listing_data_for_admin( $post_id ) {
	if ( ! defined( 'ATBDP_POST_TYPE' ) || get_post_type( $post_id ) !== ATBDP_POST_TYPE ) {
		return;
	}
	
	$directory_id = 0;
	if ( function_exists( 'directorist_get_listing_directory' ) ) {
		$directory_id = directorist_get_listing_directory( $post_id );
	}
	if ( ! $directory_id ) {
		$directory_id = get_post_meta( $post_id, '_directory_type', true );
	}
	
	$form_fields = array();
	if ( $directory_id && function_exists( 'directorist_get_listing_form_fields' ) ) {
		$form_fields = directorist_get_listing_form_fields( $directory_id );
	}
	
	$data_mapping = array(
		'services' => array( '_services', 'services' ),
		'pricing'  => array( '_pricing', 'pricing', '_pricing_list', 'pricing_list' ),
		'address'  => array( '_address', 'address' ),
	);
	
	foreach ( $form_fields as $field_key => $field_config ) {
		if ( empty( $field_config['field_key'] ) ) {
			continue;
		}
		
		$directorist_field_key = $field_config['field_key'];
		$widget_name = isset( $field_config['widget_name'] ) ? $field_config['widget_name'] : '';
		
		foreach ( $data_mapping as $data_type => $source_keys ) {
			$field_lower = strtolower( $directorist_field_key );
			if ( strpos( $field_lower, $data_type ) !== false || 
			     ( $widget_name && strpos( strtolower( $widget_name ), $data_type ) !== false ) ) {
				
				foreach ( $source_keys as $source_key ) {
					$value = get_post_meta( $post_id, $source_key, true );
					if ( ! empty( $value ) ) {
						$target_key = '_' . $directorist_field_key;
						$existing = get_post_meta( $post_id, $target_key, true );
						if ( empty( $existing ) ) {
							update_post_meta( $post_id, $target_key, $value );
							update_post_meta( $post_id, $directorist_field_key, $value );
						}
						break;
					}
				}
			}
		}
	}
}
add_action( 'edit_post', 'hbl_sync_listing_data_for_admin', 5 );
add_action( 'add_meta_boxes_at_biz_dir', 'hbl_sync_listing_data_for_admin_on_load' );

function hbl_sync_listing_data_for_admin_on_load( $post ) {
	if ( $post && isset( $post->ID ) ) {
		hbl_sync_listing_data_for_admin( $post->ID );
	}
}

function hbl_filter_directorist_field_data( $field_data ) {
	global $post;
	
	if ( ! $post || ! isset( $post->ID ) ) {
		return $field_data;
	}
	
	if ( ! empty( $field_data['value'] ) ) {
		return $field_data;
	}
	
	$post_id = $post->ID;
	$field_key = isset( $field_data['field_key'] ) ? strtolower( $field_data['field_key'] ) : '';
	$widget_name = isset( $field_data['widget_name'] ) ? strtolower( $field_data['widget_name'] ) : '';
	
	$fallback_sources = array();
	
	if ( strpos( $field_key, 'service' ) !== false || strpos( $widget_name, 'service' ) !== false ) {
		$fallback_sources = array( '_services', 'services', '_textarea_services', 'textarea_services' );
	} elseif ( strpos( $field_key, 'pricing' ) !== false || strpos( $widget_name, 'pricing' ) !== false ) {
		$fallback_sources = array( '_pricing', 'pricing', '_pricing_list', 'pricing_list', '_textarea_pricing', 'textarea_pricing' );
	} elseif ( strpos( $field_key, 'address' ) !== false || strpos( $widget_name, 'address' ) !== false ) {
		$fallback_sources = array( '_address', 'address', '_text_address', 'text_address' );
	}
	
	foreach ( $fallback_sources as $source_key ) {
		$value = get_post_meta( $post_id, $source_key, true );
		if ( ! empty( $value ) ) {
			$field_data['value'] = $value;
			break;
		}
	}
	
	return $field_data;
}
add_filter( 'directorist_form_field_data', 'hbl_filter_directorist_field_data', 10, 1 );

function hbl_get_attachment_url() {
	check_ajax_referer( 'hbl_nonce', 'nonce' );
	
	$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
	$size = isset( $_POST['size'] ) ? sanitize_text_field( $_POST['size'] ) : 'medium';
	
	if ( ! $attachment_id ) {
		wp_send_json_error( array( 'message' => 'Invalid attachment ID' ) );
	}
	
	$url = wp_get_attachment_image_url( $attachment_id, $size );
	
	if ( ! $url ) {
		$url = wp_get_attachment_image_url( $attachment_id, 'full' );
	}
	
	if ( ! $url ) {
		wp_send_json_error( array( 'message' => 'Image not found' ) );
	}
	
	wp_send_json_success( array( 'url' => $url ) );
}
add_action( 'wp_ajax_hbl_get_attachment_url', 'hbl_get_attachment_url' );
add_action( 'wp_ajax_nopriv_hbl_get_attachment_url', 'hbl_get_attachment_url' );

function hbl_process_checkout() {
	check_ajax_referer( 'hbl_checkout_nonce', 'checkout_nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in to complete checkout.', 'hbl' ) ) );
	}

	$payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( $_POST['payment_method'] ) : '';
	$billing_name   = isset( $_POST['billing_name'] ) ? sanitize_text_field( $_POST['billing_name'] ) : '';
	$billing_email  = isset( $_POST['billing_email'] ) ? sanitize_email( $_POST['billing_email'] ) : '';
	$billing_phone  = isset( $_POST['billing_phone'] ) ? sanitize_text_field( $_POST['billing_phone'] ) : '';
	$order_id       = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$plan_id        = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;
	$listing_id     = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;

	if ( empty( $billing_name ) || empty( $billing_email ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Billing name and email are required.', 'hbl' ) ) );
	}

	if ( empty( $payment_method ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Please select a payment method.', 'hbl' ) ) );
	}

	switch ( $payment_method ) {
		case 'stripe':
			$redirect_url = add_query_arg(
				array(
					'order_id'       => $order_id ?: wp_rand( 10000, 99999 ),
					'transaction_id' => 'TXN-' . strtoupper( substr( md5( time() . wp_rand() ), 0, 12 ) ),
				),
				home_url( '/payment-receipt/' )
			);
			wp_send_json_success( array(
				'message'      => esc_html__( 'Payment successful! Redirecting...', 'hbl' ),
				'redirect_url' => $redirect_url,
			) );
			break;

		case 'paypal':
			$redirect_url = add_query_arg(
				array(
					'order_id'       => $order_id ?: wp_rand( 10000, 99999 ),
					'transaction_id' => 'PP-' . strtoupper( substr( md5( time() . wp_rand() ), 0, 12 ) ),
				),
				home_url( '/payment-receipt/' )
			);
			wp_send_json_success( array(
				'message'      => esc_html__( 'Redirecting to PayPal...', 'hbl' ),
				'redirect_url' => $redirect_url,
			) );
			break;

		case 'bank_transfer':
			$redirect_url = add_query_arg(
				array(
					'order_id'       => $order_id ?: wp_rand( 10000, 99999 ),
					'transaction_id' => 'BT-' . strtoupper( substr( md5( time() . wp_rand() ), 0, 12 ) ),
					'pending'        => '1',
				),
				home_url( '/payment-receipt/' )
			);
			wp_send_json_success( array(
				'message'      => esc_html__( 'Order placed! Please complete bank transfer.', 'hbl' ),
				'redirect_url' => $redirect_url,
			) );
			break;

		default:
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid payment method.', 'hbl' ) ) );
	}
}
add_action( 'wp_ajax_hbl_process_checkout', 'hbl_process_checkout' );

function hbl_format_plan_tax_label( $tax_type, $tax_rate ) {
	if ( 'percent' === $tax_type && $tax_rate > 0 ) {
		$rate = rtrim( rtrim( number_format( (float) $tax_rate, 2 ), '0' ), '.' );
		return sprintf( __( 'GST (%s%%)', 'hbl' ), $rate );
	}
	return __( 'GST', 'hbl' );
}

function hbl_create_stripe_session() {
	if ( ! isset( $_POST['checkout_nonce'] ) || ! wp_verify_nonce( $_POST['checkout_nonce'], 'hbl_checkout_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed.' ) );
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'You must be logged in.' ) );
	}

	$recaptcha_result = hbl_verify_recaptcha( isset( $_POST['g-recaptcha-response'] ) ? $_POST['g-recaptcha-response'] : '' );
	if ( true !== $recaptcha_result ) {
		wp_send_json_error( array( 'message' => $recaptcha_result ) );
	}

	$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	$plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;
	$billing_name = isset( $_POST['billing_name'] ) ? sanitize_text_field( $_POST['billing_name'] ) : '';
	$billing_email = isset( $_POST['billing_email'] ) ? sanitize_email( $_POST['billing_email'] ) : '';

	if ( ! $listing_id || ! $plan_id ) {
		wp_send_json_error( array( 'message' => 'Invalid listing or plan.' ) );
	}

	$plan = get_post( $plan_id );
	if ( ! $plan || $plan->post_type !== 'atbdp_pricing_plans' ) {
		wp_send_json_error( array( 'message' => 'Invalid pricing plan.' ) );
	}

	$plan_data = class_exists( 'HBL_Pricing_Plans' ) ? HBL_Pricing_Plans::get_plan( $plan_id ) : null;

	if ( $plan_data ) {
		$plan_price = (float) $plan_data['price'];
	} elseif ( function_exists( 'atpp_total_price' ) ) {
		$plan_price = (float) atpp_total_price( $plan_id );
	} else {
		$plan_price = floatval( get_post_meta( $plan_id, 'fm_price', true ) );
	}

	if ( $plan_price <= 0 ) {
		wp_send_json_error( array( 'message' => 'This plan is free and does not require payment.' ) );
	}

	$secret_key = hbl_get_stripe_secret_key();

	if ( empty( $secret_key ) ) {
		wp_send_json_error( array( 'message' => 'Stripe is not configured. Please configure your Stripe API keys in Directorist Settings → Monetization → Stripe.' ) );
	}

	$order_id = wp_insert_post( array(
		'post_type'   => 'atbdp_orders',
		'post_status' => 'publish',
		'post_title'  => sprintf( 'Order #%s - %s', time(), $billing_name ),
		'post_author' => get_current_user_id(),
	) );

	if ( is_wp_error( $order_id ) ) {
		wp_send_json_error( array( 'message' => 'Failed to create order.' ) );
	}

	update_post_meta( $order_id, '_listing_id', $listing_id );
	update_post_meta( $order_id, '_fm_plan_ordered', $plan_id );
	update_post_meta( $order_id, '_payment_status', 'pending' );
	update_post_meta( $order_id, '_order_amount', $plan_price );
	update_post_meta( $order_id, '_billing_name', $billing_name );
	update_post_meta( $order_id, '_billing_email', $billing_email );

	do_action( 'hbl_order_started', (int) $order_id, (int) $listing_id, (int) $plan_id );

	$success_url = add_query_arg( array(
		'order_id'   => $order_id,
		'listing_id' => $listing_id,
		'session_id' => '{CHECKOUT_SESSION_ID}',
	), home_url( '/payment-receipt/' ) );

	$cancel_url = add_query_arg( array(
		'order_id'   => $order_id,
		'listing_id' => $listing_id,
		'plan_id'    => $plan_id,
		'cancelled'  => '1',
	), home_url( '/transaction-failure/' ) );

	$currency = get_option( 'hbl_stripe_currency', 'AUD' );

	$tax_amount     = $plan_data ? (float) $plan_data['tax_amount'] : 0.0;
	$tax_label      = $plan_data ? hbl_format_plan_tax_label( $plan_data['tax_type'], $plan_data['tax_rate'] ) : __( 'GST', 'hbl' );
	$total_with_tax = $plan_price + $tax_amount;

	update_post_meta( $order_id, '_order_subtotal', $plan_price );
	update_post_meta( $order_id, '_order_tax', $tax_amount );
	update_post_meta( $order_id, '_order_total', $total_with_tax );

	$line_items = array(
		array(
			'price_data' => array(
				'currency'     => strtolower( $currency ),
				'unit_amount'  => intval( $plan_price * 100 ),
				'product_data' => array(
					'name'        => $plan->post_title,
					'description' => get_post_meta( $plan_id, 'fm_description', true ) ?: 'Listing Package',
				),
			),
			'quantity' => 1,
		),
	);

	if ( $tax_amount > 0 ) {
		$line_items[] = array(
			'price_data' => array(
				'currency'     => strtolower( $currency ),
				'unit_amount'  => intval( $tax_amount * 100 ),
				'product_data' => array(
					'name'        => $tax_label,
					'description' => __( 'Goods and Services Tax', 'hbl' ),
				),
			),
			'quantity' => 1,
		);
	}

	$stripe_body = array(
		'payment_method_types' => array( 'card' ),
		'mode'                 => 'payment',
		'success_url'          => $success_url,
		'cancel_url'           => $cancel_url,
		'customer_email'       => $billing_email,
		'client_reference_id'  => strval( $order_id ),
		'line_items'           => $line_items,
		'metadata' => array(
			'order_id'   => strval( $order_id ),
			'listing_id' => strval( $listing_id ),
			'plan_id'    => strval( $plan_id ),
			'user_id'    => strval( get_current_user_id() ),
			'subtotal'   => strval( $plan_price ),
			'tax'        => strval( $tax_amount ),
			'total'      => strval( $total_with_tax ),
		),
	);

	$response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $secret_key,
			'Content-Type'  => 'application/x-www-form-urlencoded',
		),
		'body'    => hbl_build_stripe_body( $stripe_body ),
		'timeout' => 30,
	) );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( array( 'message' => 'Failed to connect to payment processor: ' . $response->get_error_message() ) );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( isset( $body['error'] ) ) {
		wp_send_json_error( array( 'message' => 'Payment error: ' . $body['error']['message'] ) );
	}

	if ( ! isset( $body['url'] ) ) {
		wp_send_json_error( array( 'message' => 'Failed to create checkout session.' ) );
	}

	update_post_meta( $order_id, '_stripe_session_id', $body['id'] );

	wp_send_json_success( array(
		'checkout_url' => $body['url'],
		'session_id'   => $body['id'],
		'order_id'     => $order_id,
	) );
}
add_action( 'wp_ajax_hbl_create_stripe_session', 'hbl_create_stripe_session' );

function hbl_build_stripe_body( $data, $prefix = '' ) {
	$result = array();
	
	foreach ( $data as $key => $value ) {
		$new_key = $prefix ? $prefix . '[' . $key . ']' : $key;
		
		if ( is_array( $value ) ) {
			$result = array_merge( $result, hbl_build_stripe_body( $value, $new_key ) );
		} else {
			$result[ $new_key ] = $value;
		}
	}
	
	return $result;
}

function hbl_verify_stripe_payment() {
	$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( $_GET['session_id'] ) : '';
	$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

	if ( ! $session_id || ! $order_id ) {
		return false;
	}

	$payment_status = get_post_meta( $order_id, '_payment_status', true );
	if ( $payment_status === 'completed' ) {
		return true;
	}

	$secret_key = hbl_get_stripe_secret_key();

	if ( empty( $secret_key ) ) {
		return false;
	}

	$response = wp_remote_get( 'https://api.stripe.com/v1/checkout/sessions/' . $session_id, array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $secret_key,
		),
		'timeout' => 30,
	) );

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $body ) || isset( $body['error'] ) ) {
		return false;
	}

	$session_ref   = isset( $body['client_reference_id'] ) ? (string) $body['client_reference_id'] : '';
	$session_meta  = isset( $body['metadata']['order_id'] ) ? (string) $body['metadata']['order_id'] : '';
	$expected_ref  = (string) $order_id;

	if ( $session_ref !== $expected_ref && $session_meta !== $expected_ref ) {
		return false;
	}

	$expected_total = (float) get_post_meta( $order_id, '_order_total', true );
	if ( $expected_total > 0 && isset( $body['amount_total'] ) ) {
		$paid_total = (float) $body['amount_total'] / 100;
		if ( abs( $paid_total - $expected_total ) > 0.01 ) {
			return false;
		}
	}

	if ( isset( $body['payment_status'] ) && $body['payment_status'] === 'paid' ) {
		update_post_meta( $order_id, '_payment_status', 'completed' );
		update_post_meta( $order_id, '_stripe_payment_intent', $body['payment_intent'] ?? '' );
		
		$listing_id = get_post_meta( $order_id, '_listing_id', true );
		$plan_id = get_post_meta( $order_id, '_fm_plan_ordered', true );
		
		if ( $listing_id ) {
			$plan_tier = hbl_get_plan_tier( $plan_id );
			$plan_price = 0;
			
			if ( $plan_id ) {
				if ( function_exists( 'atpp_total_price' ) ) {
					$plan_price = floatval( atpp_total_price( $plan_id ) );
				} else {
					$plan_price = floatval( get_post_meta( $plan_id, 'fm_price', true ) );
				}
			}
			
			if ( $plan_price > 0 && $plan_tier !== 'bronze' ) {
				$new_status = 'publish';
			} elseif ( $plan_tier === 'bronze' || $plan_price <= 0 ) {
				$new_status = 'pending';
			} else {
				$new_status = 'publish';
			}
			
			global $wpdb;
			$wpdb->update(
				$wpdb->posts,
				array( 'post_status' => $new_status ),
				array( 'ID' => $listing_id ),
				array( '%s' ),
				array( '%d' )
			);
			
			wp_update_post( array(
				'ID'          => $listing_id,
				'post_status' => $new_status,
			) );
			
			clean_post_cache( $listing_id );
			
			update_post_meta( $listing_id, '_fm_plans', $plan_id );
			update_post_meta( $listing_id, '_listing_order_id', $order_id );
			
			update_post_meta( $listing_id, '_payment_verified', current_time( 'mysql' ) );
			update_post_meta( $listing_id, '_payment_plan_tier', $plan_tier );
			update_post_meta( $listing_id, '_payment_auto_status', $new_status );
			
			do_action( 'atbdp_order_completed', $order_id, $listing_id );
			do_action( 'hbl_listing_payment_verified', $listing_id, $plan_id, $plan_tier, $new_status );
		}
		
		return true;
	}

	return false;
}


function hbl_apply_directorist_coupon() {
	if ( ! isset( $_POST['checkout_nonce'] ) || ! wp_verify_nonce( $_POST['checkout_nonce'], 'hbl_checkout_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed.' ) );
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'You must be logged in.' ) );
	}

	$coupon_code = isset( $_POST['coupon_code'] ) ? sanitize_text_field( $_POST['coupon_code'] ) : '';
	$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	$plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;

	if ( empty( $coupon_code ) ) {
		wp_send_json_error( array( 'message' => 'Please enter a coupon code.' ) );
	}

	$coupon_valid = false;
	$discount_amount = 0;
	$discount_type = '';
	$coupon_message = '';

	if ( function_exists( 'atbdp_validate_coupon' ) ) {
		$validation_result = atbdp_validate_coupon( $coupon_code, $plan_id );
		if ( $validation_result && isset( $validation_result['valid'] ) && $validation_result['valid'] ) {
			$coupon_valid = true;
			$discount_amount = $validation_result['discount_amount'] ?? 0;
			$discount_type = $validation_result['discount_type'] ?? 'fixed';
			$coupon_message = $validation_result['message'] ?? 'Coupon applied successfully!';
		}
	}

	if ( ! $coupon_valid ) {
	$debug_info = array();
	
	$all_post_types = get_post_types( array(), 'names' );
	$coupon_related_types = array();
	
	foreach ( $all_post_types as $post_type ) {
		if ( strpos( strtolower( $post_type ), 'coupon' ) !== false ) {
			$coupon_related_types[] = $post_type;
		}
	}
	
	$debug_info[] = "All coupon-related post types: " . implode( ', ', $coupon_related_types );
	
	$possible_post_types = array( 'swbdp-coupon', 'atbdp_coupon', 'directorist_coupon', 'coupon', 'atbdp_coupons' );
	$existing_post_types = array();
	
	foreach ( $possible_post_types as $post_type ) {
		if ( post_type_exists( $post_type ) ) {
			$existing_post_types[] = $post_type;
			$count = wp_count_posts( $post_type );
			$debug_info[] = "$post_type: {$count->publish} published";
			
			if ( $post_type === 'swbdp-coupon' && $count->publish > 0 ) {
				$sample_coupons = get_posts( array(
					'post_type' => $post_type,
					'post_status' => 'publish',
					'posts_per_page' => 3
				) );
				
				foreach ( $sample_coupons as $sample ) {
					$all_meta = get_post_meta( $sample->ID );
					$meta_keys = array_keys( $all_meta );
					$debug_info[] = "Sample coupon '{$sample->post_title}' meta keys: " . implode( ', ', $meta_keys );
					break;
				}
			}
		}
	}
	
	$debug_info[] = "Active plugins check:";
	$active_plugins = get_option( 'active_plugins' );
	$coupon_plugins = array_filter( $active_plugins, function( $plugin ) {
		return strpos( strtolower( $plugin ), 'coupon' ) !== false;
	});
	$debug_info[] = "Coupon plugins: " . implode( ', ', $coupon_plugins );
	
	$directorist_functions = array(
		'atbdp_validate_coupon',
		'directorist_validate_coupon', 
		'atbdp_apply_coupon',
		'directorist_apply_coupon'
	);
	
	$available_functions = array();
	foreach ( $directorist_functions as $func ) {
		if ( function_exists( $func ) ) {
			$available_functions[] = $func;
		}
	}
	$debug_info[] = "Available Directorist coupon functions: " . implode( ', ', $available_functions );
	
	$directorist_classes = array(
		'Directorist_Coupon',
		'ATBDP_Coupon',
		'Directorist\\Coupon'
	);
	
	$available_classes = array();
	foreach ( $directorist_classes as $class ) {
		if ( class_exists( $class ) ) {
			$available_classes[] = $class;
		}
	}
	$debug_info[] = "Available Directorist coupon classes: " . implode( ', ', $available_classes );
	
	global $wpdb;
	$tables = $wpdb->get_results( "SHOW TABLES LIKE '%coupon%'", ARRAY_N );
	$coupon_tables = array();
	foreach ( $tables as $table ) {
		$coupon_tables[] = $table[0];
	}
	$debug_info[] = "Coupon-related database tables: " . implode( ', ', $coupon_tables );
	
	if ( ! empty( $coupon_tables ) ) {
		foreach ( $coupon_tables as $table ) {
			$columns = $wpdb->get_results( "DESCRIBE $table", ARRAY_A );
			$column_names = array_column( $columns, 'Field' );
			$debug_info[] = "Table $table columns: " . implode( ', ', $column_names );
			
			$code_columns = array( 'code', 'coupon_code', 'discount_code', 'name', 'title' );
			foreach ( $code_columns as $col ) {
				if ( in_array( $col, $column_names ) ) {
					$result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE $col = %s LIMIT 1", $coupon_code ) );
					if ( $result ) {
						$debug_info[] = "Found coupon in table $table, column $col";
						
						$coupon_valid = true;
						$discount_amount = 0;
						$discount_type = 'fixed';
						
						$amount_columns = array( 'amount', 'discount_amount', 'value', 'discount_value' );
						foreach ( $amount_columns as $amt_col ) {
							if ( isset( $result->$amt_col ) ) {
								$discount_amount = floatval( $result->$amt_col );
								break;
							}
						}
						
						$type_columns = array( 'type', 'discount_type', 'coupon_type' );
						foreach ( $type_columns as $type_col ) {
							if ( isset( $result->$type_col ) ) {
								$discount_type = $result->$type_col;
								break;
							}
						}
						
						$coupon_message = sprintf( 'Coupon "%s" applied! You saved %s.', 
							$coupon_code, 
							$discount_type === 'percentage' ? $discount_amount . '%' : '$' . number_format( $discount_amount, 2 )
						);
						
						break 2;
					}
				}
			}
		}
	}
		
		$possible_meta_keys = array( 'swbdpc_coupon_code', '_coupon_code', 'coupon_code', '_code', 'code', '_discount_code' );
		
		foreach ( $existing_post_types as $post_type ) {
			foreach ( $possible_meta_keys as $meta_key ) {
				$coupon_posts = get_posts( array(
					'post_type' => $post_type,
					'post_status' => 'publish',
					'meta_query' => array(
						array(
							'key' => $meta_key,
							'value' => $coupon_code,
							'compare' => '='
						)
					),
					'posts_per_page' => 1
				) );
				
				if ( ! empty( $coupon_posts ) ) {
					$coupon_post = $coupon_posts[0];
					$debug_info[] = "Found coupon in $post_type with meta key $meta_key";
					
					$all_meta = get_post_meta( $coupon_post->ID );
					
					$expiry_date = get_post_meta( $coupon_post->ID, 'swbdpc_coupon_expiry', true ) ?: 
					              get_post_meta( $coupon_post->ID, '_expiry_date', true ) ?: 
					              get_post_meta( $coupon_post->ID, 'expiry_date', true );
					$usage_limit = get_post_meta( $coupon_post->ID, 'swbdpc_coupon_usage_limit', true ) ?: 
					              get_post_meta( $coupon_post->ID, '_usage_limit', true ) ?: 
					              get_post_meta( $coupon_post->ID, 'usage_limit', true );
					$usage_count = get_post_meta( $coupon_post->ID, '_usage_count', true ) ?: get_post_meta( $coupon_post->ID, 'usage_count', true );
					
					$is_expired = $expiry_date && strtotime( $expiry_date ) < current_time( 'timestamp' );
					$is_limit_reached = $usage_limit && $usage_count >= $usage_limit;
					
					if ( $is_expired ) {
						wp_send_json_error( array( 
							'message' => 'This coupon has expired.',
							'debug' => current_user_can( 'manage_options' ) ? $debug_info : null
						) );
					}
					
					if ( $is_limit_reached ) {
						wp_send_json_error( array( 
							'message' => 'This coupon has reached its usage limit.',
							'debug' => current_user_can( 'manage_options' ) ? $debug_info : null
						) );
					}
					
					$coupon_valid = true;
					
					$discount_amount = floatval( 
						get_post_meta( $coupon_post->ID, 'swbdpc_coupon_amount', true ) ?: 
						get_post_meta( $coupon_post->ID, '_discount_amount', true ) ?: 
						get_post_meta( $coupon_post->ID, 'discount_amount', true ) ?: 
						get_post_meta( $coupon_post->ID, '_amount', true ) ?: 
						get_post_meta( $coupon_post->ID, 'amount', true ) ?: 0
					);
					
					$discount_type = get_post_meta( $coupon_post->ID, 'swbdpc_coupon_type', true ) ?: 
					                get_post_meta( $coupon_post->ID, '_discount_type', true ) ?: 
					                get_post_meta( $coupon_post->ID, 'discount_type', true ) ?: 
					                get_post_meta( $coupon_post->ID, '_type', true ) ?: 
					                get_post_meta( $coupon_post->ID, 'type', true ) ?: 'fixed';
					
					$coupon_message = sprintf( 'Coupon "%s" applied! You saved %s.', 
						$coupon_code, 
						$discount_type === 'percentage' ? $discount_amount . '%' : '$' . number_format( $discount_amount, 2 )
					);
					
					break 2;
				}
			}
		}
		
		if ( ! $coupon_valid ) {
			foreach ( $existing_post_types as $post_type ) {
				$coupon_posts = get_posts( array(
					'post_type' => $post_type,
					'post_status' => 'publish',
					's' => $coupon_code,
					'posts_per_page' => 1
				) );
				
				if ( ! empty( $coupon_posts ) ) {
					$coupon_post = $coupon_posts[0];
					$debug_info[] = "Found coupon in $post_type by title search";
					
					$coupon_valid = true;
					$discount_amount = 10;
					$discount_type = 'fixed';
					$coupon_message = sprintf( 'Coupon "%s" applied!', $coupon_code );
					break;
				}
			}
		}
		
		if ( ! $coupon_valid && current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 
				'message' => 'Coupon not found. Debug info: ' . implode( ', ', $debug_info ),
				'debug' => $debug_info,
				'searched_post_types' => $existing_post_types,
				'searched_meta_keys' => $possible_meta_keys
			) );
		}
	}

	if ( ! $coupon_valid ) {
		wp_send_json_error( array( 'message' => 'Invalid or expired coupon code.' ) );
	}

	if ( ! session_id() ) {
		session_start();
	}
	
	$_SESSION['hbl_applied_coupon'] = array(
		'code' => $coupon_code,
		'discount_amount' => $discount_amount,
		'discount_type' => $discount_type,
		'applied_at' => current_time( 'timestamp' )
	);
	
	$_SESSION['hbl_coupon_discount'] = $discount_amount;
	$_SESSION['hbl_coupon_type'] = $discount_type;
	
	$plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;
	$subtotal = 99.00;
	$tax = 0.0;

	$plan_data = ( $plan_id && class_exists( 'HBL_Pricing_Plans' ) ) ? HBL_Pricing_Plans::get_plan( $plan_id ) : null;

	if ( $plan_data ) {
		$subtotal = (float) $plan_data['price'];
		$tax      = (float) $plan_data['tax_amount'];
	} elseif ( $plan_id ) {
		if ( function_exists( 'atpp_total_price' ) ) {
			$subtotal = floatval( atpp_total_price( $plan_id ) );
		} else {
			$subtotal = floatval( get_post_meta( $plan_id, 'fm_price', true ) );
		}
	}

	$actual_discount = $discount_type === 'percentage' ? ( $subtotal * $discount_amount ) / 100 : $discount_amount;
	$actual_discount = min( $actual_discount, $subtotal );

	$new_total = $subtotal + $tax - $actual_discount;

	wp_send_json_success( array(
		'message' => $coupon_message,
		'discount_amount' => $actual_discount,
		'discount_type' => $discount_type,
		'new_total' => $new_total,
		'reload' => false
	) );
}
add_action( 'wp_ajax_hbl_apply_directorist_coupon', 'hbl_apply_directorist_coupon' );

function hbl_verify_recaptcha( $token ) {
	$secret_key = get_option( 'elementor_pro_recaptcha_secret_key', '' );

	if ( empty( $secret_key ) ) {
		return true;
	}

	$token = sanitize_text_field( wp_unslash( $token ) );

	if ( empty( $token ) ) {
		return __( 'Please complete the CAPTCHA verification.', 'hbl' );
	}

	$response = wp_remote_post(
		'https://www.google.com/recaptcha/api/siteverify',
		array(
			'body' => array(
				'secret'   => $secret_key,
				'response' => $token,
			),
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		return __( 'CAPTCHA verification failed. Please try again.', 'hbl' );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $body['success'] ) ) {
		return __( 'CAPTCHA verification failed. Please try again.', 'hbl' );
	}

	return true;
}

function hbl_ajax_login() {
	check_ajax_referer( 'ajax-login-nonce', 'security' );

	$recaptcha_result = hbl_verify_recaptcha( isset( $_POST['g-recaptcha-response'] ) ? $_POST['g-recaptcha-response'] : '' );
	if ( true !== $recaptcha_result ) {
		wp_send_json_error( array( 'message' => $recaptcha_result ) );
	}

	$username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
	$password = isset( $_POST['password'] ) ? $_POST['password'] : '';
	$rememberme = isset( $_POST['rememberme'] ) && '1' === $_POST['rememberme'];
	$redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( $_POST['redirect_to'] ) : home_url();

	if ( empty( $username ) || empty( $password ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Username and password are required.', 'hbl' ) ) );
	}

	$creds = array(
		'user_login'    => $username,
		'user_password' => $password,
		'remember'      => $rememberme,
	);

	$user = wp_signon( $creds, false );

	if ( is_wp_error( $user ) ) {
		wp_send_json_error( array( 'message' => $user->get_error_message() ) );
	}

	wp_send_json_success( array(
		'message'  => esc_html__( 'Login successful!', 'hbl' ),
		'redirect' => $redirect_to,
	) );
}
add_action( 'wp_ajax_hbl_ajax_login', 'hbl_ajax_login' );
add_action( 'wp_ajax_nopriv_hbl_ajax_login', 'hbl_ajax_login' );

function hbl_ajax_register() {
	check_ajax_referer( 'ajax-register-nonce', 'security' );

	$recaptcha_result = hbl_verify_recaptcha( isset( $_POST['g-recaptcha-response'] ) ? $_POST['g-recaptcha-response'] : '' );
	if ( true !== $recaptcha_result ) {
		wp_send_json_error( array( 'message' => $recaptcha_result ) );
	}

	$username = isset( $_POST['user_login'] ) ? sanitize_user( wp_unslash( $_POST['user_login'] ) ) : '';
	$email = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
	$password = isset( $_POST['user_pass'] ) ? $_POST['user_pass'] : '';

	if ( empty( $username ) || empty( $email ) || empty( $password ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'All fields are required.', 'hbl' ) ) );
	}

	if ( ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Invalid email address.', 'hbl' ) ) );
	}

	if ( username_exists( $username ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Username already exists.', 'hbl' ) ) );
	}

	if ( email_exists( $email ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Email already registered.', 'hbl' ) ) );
	}

	$user_id = wp_create_user( $username, $password, $email );

	if ( is_wp_error( $user_id ) ) {
		wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
	}

	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id );

	wp_send_json_success( array(
		'message'  => esc_html__( 'Registration successful!', 'hbl' ),
		'redirect' => home_url(),
	) );
}
add_action( 'wp_ajax_hbl_ajax_register', 'hbl_ajax_register' );
add_action( 'wp_ajax_nopriv_hbl_ajax_register', 'hbl_ajax_register' );

function hbl_submit_review() {
	if ( ! isset( $_POST['hbl_review_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hbl_review_nonce'] ) ), 'hbl_submit_review' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'hbl' ) ) );
	}
	
	$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	$rating = isset( $_POST['review_rating'] ) ? absint( $_POST['review_rating'] ) : 0;
	$reviewer_name = isset( $_POST['reviewer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['reviewer_name'] ) ) : '';
	$reviewer_email = isset( $_POST['reviewer_email'] ) ? sanitize_email( wp_unslash( $_POST['reviewer_email'] ) ) : '';
	$review_content = isset( $_POST['review_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['review_content'] ) ) : '';
	
	if ( empty( $listing_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid listing.', 'hbl' ) ) );
	}
	
	if ( $rating < 1 || $rating > 5 ) {
		wp_send_json_error( array( 'message' => __( 'Please select a rating between 1 and 5 stars.', 'hbl' ) ) );
	}
	
	if ( empty( $reviewer_name ) ) {
		wp_send_json_error( array( 'message' => __( 'Please enter your name.', 'hbl' ) ) );
	}
	
	if ( empty( $reviewer_email ) || ! is_email( $reviewer_email ) ) {
		wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'hbl' ) ) );
	}
	
	if ( empty( $review_content ) ) {
		wp_send_json_error( array( 'message' => __( 'Please write your review.', 'hbl' ) ) );
	}
	
	if ( function_exists( 'directorist_is_review_enabled' ) && ! directorist_is_review_enabled() ) {
		wp_send_json_error( array( 'message' => __( 'Reviews are currently disabled.', 'hbl' ) ) );
	}
	
	if ( function_exists( 'directorist_user_review_exists' ) && directorist_user_review_exists( $reviewer_email, $listing_id ) ) {
		if ( ! apply_filters( 'directorist_is_multiple_review_enabled', false ) ) {
			wp_send_json_error( array( 'message' => __( 'You have already submitted a review for this listing.', 'hbl' ) ) );
		}
	}
	
	$approve_immediately = function_exists( 'directorist_is_immediate_review_approve_enabled' ) && directorist_is_immediate_review_approve_enabled();
	$comment_approved = $approve_immediately ? 1 : 0;
	
	$comment_data = array(
		'comment_post_ID'      => $listing_id,
		'comment_author'       => $reviewer_name,
		'comment_author_email' => $reviewer_email,
		'comment_content'      => $review_content,
		'comment_type'         => 'review',
		'comment_approved'     => $comment_approved,
		'comment_parent'       => 0,
	);
	
	if ( is_user_logged_in() ) {
		$comment_data['user_id'] = get_current_user_id();
	}
	
	$comment_id = wp_insert_comment( $comment_data );
	
	if ( ! $comment_id ) {
		wp_send_json_error( array( 'message' => __( 'Failed to submit review. Please try again.', 'hbl' ) ) );
	}
	
	add_comment_meta( $comment_id, 'rating', $rating, true );
	
	if ( class_exists( 'Directorist\Review\Comment' ) ) {
		\Directorist\Review\Comment::clear_transients( $listing_id );
	}
	
	do_action( 'directorist_review_submitted', $comment_id, $comment_data );
	
	$success_message = $approve_immediately 
		? __( 'Thank you! Your review has been submitted successfully.', 'hbl' )
		: __( 'Thank you! Your review has been submitted and is pending approval.', 'hbl' );
	
	wp_send_json_success( array( 
		'message' => $success_message,
		'comment_id' => $comment_id,
	) );
}
add_action( 'wp_ajax_hbl_submit_review', 'hbl_submit_review' );
add_action( 'wp_ajax_nopriv_hbl_submit_review', 'hbl_submit_review' );

function hbl_search_listings() {
	check_ajax_referer( 'hbl_search_nonce', 'nonce' );

	$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

	if ( empty( $query ) || strlen( $query ) < 2 ) {
		wp_send_json_success( array( 'listings' => array() ) );
	}

	$post_type = defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';

	$args = array(
		'post_type'      => $post_type,
		'post_status'    => 'publish',
		'posts_per_page' => 10,
		's'              => $query,
		'orderby'        => 'relevance',
	);

	$listings_query = new WP_Query( $args );
	$listings       = array();

	if ( $listings_query->have_posts() ) {
		while ( $listings_query->have_posts() ) {
			$listings_query->the_post();
			$listing_id = get_the_ID();
			$address    = get_post_meta( $listing_id, '_address', true );

			$listings[] = array(
				'id'      => $listing_id,
				'title'   => get_the_title(),
				'address' => $address ? $address : '',
			);
		}
		wp_reset_postdata();
	}

	wp_send_json_success( array( 'listings' => $listings ) );
}
add_action( 'wp_ajax_hbl_search_listings', 'hbl_search_listings' );
add_action( 'wp_ajax_nopriv_hbl_search_listings', 'hbl_search_listings' );

function hbl_get_listing_title() {
	check_ajax_referer( 'hbl_search_nonce', 'nonce' );

	$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;

	if ( ! $listing_id ) {
		wp_send_json_error( array( 'message' => 'Invalid listing ID' ) );
	}

	$post_type = defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';
	$post      = get_post( $listing_id );

	if ( ! $post || $post->post_type !== $post_type ) {
		wp_send_json_error( array( 'message' => 'Listing not found' ) );
	}

	wp_send_json_success( array(
		'title'   => $post->post_title,
		'id'      => $listing_id,
	) );
}
add_action( 'wp_ajax_hbl_get_listing_title', 'hbl_get_listing_title' );
add_action( 'wp_ajax_nopriv_hbl_get_listing_title', 'hbl_get_listing_title' );

function hbl_get_stripe_secret_key() {
	$atbdp_options = get_option( 'atbdp_option', array() );
	
	$stripe_gateway_test_mode = isset( $atbdp_options['stripe_gateway_test_mode'] ) ? $atbdp_options['stripe_gateway_test_mode'] : '';
	$gateway_test_mode = isset( $atbdp_options['gateway_test_mode'] ) ? $atbdp_options['gateway_test_mode'] : '';
	
	$is_test_mode = ! empty( $stripe_gateway_test_mode ) || ! empty( $gateway_test_mode );
	
	if ( $is_test_mode ) {
		if ( ! empty( $atbdp_options['stripe_test_sk'] ) ) {
			return $atbdp_options['stripe_test_sk'];
		}
		if ( ! empty( $atbdp_options['stripe_test_secret_key'] ) ) {
			return $atbdp_options['stripe_test_secret_key'];
		}
	} else {
		if ( ! empty( $atbdp_options['stripe_live_sk'] ) ) {
			return $atbdp_options['stripe_live_sk'];
		}
		if ( ! empty( $atbdp_options['stripe_live_secret_key'] ) ) {
			return $atbdp_options['stripe_live_secret_key'];
		}
	}
	
	$elementor_live_key = get_option( 'elementor_pro_stripe_live_secret_key', '' );
	if ( ! empty( $elementor_live_key ) ) {
		return $elementor_live_key;
	}
	
	$elementor_test_key = get_option( 'elementor_pro_stripe_test_secret_key', '' );
	if ( ! empty( $elementor_test_key ) ) {
		return $elementor_test_key;
	}
	
	return '';
}

function hbl_is_stripe_test_mode() {
	$atbdp_options = get_option( 'atbdp_option', array() );
	
	$stripe_gateway_test_mode = isset( $atbdp_options['stripe_gateway_test_mode'] ) ? $atbdp_options['stripe_gateway_test_mode'] : '';
	$gateway_test_mode = isset( $atbdp_options['gateway_test_mode'] ) ? $atbdp_options['gateway_test_mode'] : '';
	
	if ( ! empty( $stripe_gateway_test_mode ) || ! empty( $gateway_test_mode ) ) {
		return true;
	}
	
	$secret_key = hbl_get_stripe_secret_key();
	return strpos( $secret_key, 'sk_test_' ) === 0;
}

add_action( 'admin_notices', function() {
	if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'atbdp' ) === false ) {
		return;
	}
	
	$atbdp_options = get_option( 'atbdp_option', array() );
	$stripe_keys = array();
	
	foreach ( $atbdp_options as $key => $value ) {
		if ( stripos( $key, 'stripe' ) !== false ) {
			$display_value = is_string( $value ) && strlen( $value ) > 15 
				? substr( $value, 0, 10 ) . '...' . substr( $value, -5 ) 
				: $value;
			$stripe_keys[ $key ] = $display_value;
		}
	}
	
	if ( ! empty( $stripe_keys ) ) {
		$secret_key = hbl_get_stripe_secret_key();
		$is_test = hbl_is_stripe_test_mode();
		$key_type = strpos( $secret_key, 'sk_test_' ) === 0 ? 'TEST' : ( strpos( $secret_key, 'sk_live_' ) === 0 ? 'LIVE' : 'UNKNOWN' );
		
		echo '<div class="notice notice-info"><p>';
		echo '<strong>HBL Stripe Status:</strong> ';
		echo 'Mode: <code>' . ( $is_test ? 'TEST' : 'LIVE' ) . '</code> | ';
		echo 'Key Type: <code>' . $key_type . '</code> | ';
		echo 'Key Found: <code>' . ( ! empty( $secret_key ) ? 'Yes (' . substr( $secret_key, 0, 12 ) . '...)' : 'No' ) . '</code>';
		echo '</p></div>';
	}
});

function hbl_get_plan_tier( $plan_id ) {
	if ( empty( $plan_id ) || $plan_id <= 0 ) {
		return 'bronze';
	}

	$plan = class_exists( 'HBL_Pricing_Plans' ) ? \HBL_Pricing_Plans::get_plan( $plan_id ) : null;
	if ( ! $plan ) {
		return 'bronze';
	}

	$plan_name = strtolower( $plan['title'] );

	if ( strpos( $plan_name, 'gold' ) !== false || strpos( $plan_name, 'premium' ) !== false || strpos( $plan_name, 'pro' ) !== false ) {
		return 'gold';
	}

	if ( strpos( $plan_name, 'silver' ) !== false || strpos( $plan_name, 'standard' ) !== false || strpos( $plan_name, 'plus' ) !== false ) {
		return 'silver';
	}

	if ( strpos( $plan_name, 'bronze' ) !== false || strpos( $plan_name, 'basic' ) !== false || strpos( $plan_name, 'starter' ) !== false || strpos( $plan_name, 'free' ) !== false ) {
		return 'bronze';
	}

	$plan_price = floatval( $plan['price'] );

	if ( $plan_price >= 100 ) {
		return 'gold';
	} elseif ( $plan_price >= 50 ) {
		return 'silver';
	}

	return 'bronze';
}

function hbl_submit_claim() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'hbl_claim_nonce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'hbl' ) ) );
	}
	
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'You must be logged in to claim a listing.', 'hbl' ) ) );
	}

	$recaptcha_result = hbl_verify_recaptcha( isset( $_POST['g-recaptcha-response'] ) ? $_POST['g-recaptcha-response'] : '' );
	if ( true !== $recaptcha_result ) {
		wp_send_json_error( array( 'message' => $recaptcha_result ) );
	}

	$user_id = get_current_user_id();
	$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	$plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;
	$claimer_name = isset( $_POST['claimer_name'] ) ? sanitize_text_field( $_POST['claimer_name'] ) : '';
	$claimer_phone = isset( $_POST['claimer_phone'] ) ? sanitize_text_field( $_POST['claimer_phone'] ) : '';
	$claimer_details = isset( $_POST['claimer_details'] ) ? sanitize_textarea_field( $_POST['claimer_details'] ) : '';
	
	if ( ! $listing_id || get_post_type( $listing_id ) !== 'at_biz_dir' ) {
		wp_send_json_error( array( 'message' => __( 'Please select a valid business to claim.', 'hbl' ) ) );
	}
	
	if ( function_exists( 'dcl_tract_duplicate_claim' ) ) {
		$already_claimed = dcl_tract_duplicate_claim( $user_id, $listing_id );
		if ( ! empty( $already_claimed ) ) {
			wp_send_json_error( array( 'message' => __( 'You have already submitted a claim for this listing.', 'hbl' ) ) );
		}
	}
	
	update_user_meta( $user_id, '_user_type', 'author' );
	
	update_post_meta( $listing_id, '_claimer_name', $claimer_name );
	update_post_meta( $listing_id, '_claimer_phone', $claimer_phone );
	update_post_meta( $listing_id, '_claimer_details', $claimer_details );
	
	if ( $plan_id ) {
		update_post_meta( $listing_id, '_claimer_plans', $plan_id );
	}
	
	if ( function_exists( 'dcl_new_claim' ) ) {
		dcl_new_claim( $listing_id );
	} else {
		$claim_id = wp_insert_post( array(
			'post_content'   => '',
			'post_title'     => get_the_title( $listing_id ),
			'post_status'    => 'publish',
			'post_type'      => 'dcl_claim_listing',
			'comment_status' => 'closed',
		) );
		
		if ( $claim_id && ! is_wp_error( $claim_id ) ) {
			update_post_meta( $claim_id, '_listing_claimer', $user_id );
			update_post_meta( $claim_id, '_claimed_listing', $listing_id );
			update_post_meta( $claim_id, '_claim_status', 'pending' );
			update_post_meta( $claim_id, '_claimer_details', $claimer_details );
			update_post_meta( $claim_id, '_claimer_phone', $claimer_phone );
		}
	}
	
	if ( function_exists( 'dcl_email_admin_listing_claim' ) ) {
		dcl_email_admin_listing_claim();
	}
	
	$dashboard_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_dashboard_page_link() : home_url( '/dashboard/' );
	
	$requires_payment = false;
	$checkout_url = '';
	$plan_tier = 'bronze';
	
	if ( $plan_id > 0 ) {
		$plan_price = 0;
		if ( function_exists( 'atpp_total_price' ) ) {
			$plan_price = floatval( atpp_total_price( $plan_id ) );
		} else {
			$plan_price = floatval( get_post_meta( $plan_id, 'fm_price', true ) );
			$is_free_plan = get_post_meta( $plan_id, 'free_plan', true );
			if ( $is_free_plan ) {
				$plan_price = 0;
			}
		}
		
		$plan_tier = hbl_get_plan_tier( $plan_id );
		
		if ( $plan_price > 0 ) {
			$requires_payment = true;
			
			$checkout_url = add_query_arg( array(
				'listing_id' => $listing_id,
				'plan_id'    => $plan_id,
				'claimed'    => 'true',
			), home_url( '/checkout/' ) );
		}
	}
	
	if ( $requires_payment ) {
		$message = __( 'Your claim has been submitted! Please complete payment to proceed.', 'hbl' );
	} elseif ( $plan_tier === 'bronze' ) {
		$message = __( 'Your claim has been submitted and is pending review. We will notify you once it\'s approved.', 'hbl' );
	} else {
		$message = __( 'Your claim has been submitted successfully!', 'hbl' );
	}
	
	wp_send_json_success( array(
		'message'          => $message,
		'requires_payment' => $requires_payment,
		'checkout_url'     => $checkout_url,
		'redirect_url'     => $requires_payment ? '' : $dashboard_url,
		'plan_tier'        => $plan_tier,
	) );
}
add_action( 'wp_ajax_hbl_submit_claim', 'hbl_submit_claim' );

function hbl_auto_approve_claim_after_payment( $order_id, $listing_id ) {
	$is_claim_order = get_post_meta( $order_id, '_claimed', true );
	$claimer_plans = get_post_meta( $listing_id, '_claimer_plans', true );
	
	if ( ! $is_claim_order && empty( $claimer_plans ) ) {
		return;
	}
	
	$plan_id = get_post_meta( $order_id, '_fm_plan_ordered', true );
	if ( ! $plan_id ) {
		$plan_id = get_post_meta( $order_id, '_claim_plan_id', true );
	}
	if ( ! $plan_id && $claimer_plans ) {
		$plan_id = $claimer_plans;
	}
	
	if ( ! $plan_id ) {
		return;
	}
	
	$plan_tier = hbl_get_plan_tier( $plan_id );
	
	if ( $plan_tier === 'silver' || $plan_tier === 'gold' ) {
		$claim_posts = get_posts( array(
			'post_type'      => 'dcl_claim_listing',
			'posts_per_page' => 1,
			'post_status'   => 'publish',
			'meta_query'     => array(
				array(
					'key'   => '_claimed_listing',
					'value' => $listing_id,
				),
			),
		) );
		
		if ( ! empty( $claim_posts ) ) {
			$claim_id = $claim_posts[0]->ID;
			$current_status = get_post_meta( $claim_id, '_claim_status', true );
			
			if ( $current_status === 'pending' ) {
				$claimer_id = get_post_meta( $claim_id, '_listing_claimer', true );
				
				if ( $claimer_id ) {
					update_post_meta( $claim_id, '_claim_status', 'approved' );
					
					global $wpdb;
					$wpdb->update(
						$wpdb->posts,
						array( 'post_author' => $claimer_id ),
						array( 'ID' => $listing_id ),
						array( '%d' ),
						array( '%d' )
					);
					
					if ( $claimer_plans ) {
						update_post_meta( $listing_id, '_fm_plans', $claimer_plans );
						update_post_meta( $listing_id, '_claimer_plans', 0 );
					}
					
					update_post_meta( $listing_id, '_claimed_by_admin', 1 );
					update_post_meta( $listing_id, '_claim_fee', 'claim_approved' );
					
					if ( has_action( 'atbdp_claim_approved' ) ) {
						do_action( 'atbdp_claim_approved', $claim_id, $listing_id );
					}
					
					if ( function_exists( 'dcl_email_claimer_claim_approved' ) ) {
						dcl_email_claimer_claim_approved( $claim_id );
					}
				}
			}
		}
	}
}
add_action( 'atbdp_order_completed', 'hbl_auto_approve_claim_after_payment', 20, 2 );

function hbl_mark_order_as_claim( $order_id, $listing_id ) {
	$claimer_plans = get_post_meta( $listing_id, '_claimer_plans', true );
	$claimed_by_admin = get_post_meta( $listing_id, '_claimed_by_admin', true );
	
	$claim_exists = get_posts( array(
		'post_type'      => 'dcl_claim_listing',
		'posts_per_page' => 1,
		'meta_query'     => array(
			array(
				'key'   => '_claimed_listing',
				'value' => $listing_id,
			),
		),
	) );
	
	if ( ! empty( $claimer_plans ) || ! empty( $claim_exists ) ) {
		update_post_meta( $order_id, '_claimed', true );
		if ( $claimer_plans ) {
			update_post_meta( $order_id, '_claim_plan_id', $claimer_plans );
		}
	}
}
add_action( 'atbdp_order_created', 'hbl_mark_order_as_claim', 10, 2 );

function hbl_auto_approve_listing_after_payment( $order_id, $listing_id ) {
	$is_claim_order = get_post_meta( $order_id, '_claimed', true );
	$claimer_plans = get_post_meta( $listing_id, '_claimer_plans', true );
	
	if ( $is_claim_order || ! empty( $claimer_plans ) ) {
		return;
	}
	
	clean_post_cache( $listing_id );
	$listing = get_post( $listing_id );
	
	if ( ! $listing || $listing->post_type !== 'at_biz_dir' ) {
		return;
	}
	
	$plan_id = get_post_meta( $order_id, '_fm_plan_ordered', true );
	if ( ! $plan_id ) {
		$plan_id = get_post_meta( $listing_id, '_fm_plans', true );
	}
	
	if ( ! $plan_id ) {
		return;
	}
	
	$plan_tier = hbl_get_plan_tier( $plan_id );
	$plan_price = 0;
	
	if ( function_exists( 'atpp_total_price' ) ) {
		$plan_price = floatval( atpp_total_price( $plan_id ) );
	} else {
		$plan_price = floatval( get_post_meta( $plan_id, 'fm_price', true ) );
	}
	
	$should_auto_approve = ( $plan_price > 0 && ( $plan_tier === 'silver' || $plan_tier === 'gold' ) );
	
	if ( $should_auto_approve ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_status' => 'publish' ),
			array( 'ID' => $listing_id ),
			array( '%s' ),
			array( '%d' )
		);
		
		wp_update_post( array(
			'ID'          => $listing_id,
			'post_status' => 'publish',
		) );
		
		clean_post_cache( $listing_id );
		
		update_post_meta( $listing_id, '_auto_approved', current_time( 'mysql' ) );
		update_post_meta( $listing_id, '_auto_approved_plan_tier', $plan_tier );
		update_post_meta( $listing_id, '_auto_approved_via', 'order_completed_hook' );
		
		do_action( 'hbl_listing_auto_approved', $listing_id, $plan_id, $plan_tier );
		
		hbl_send_listing_approved_email( $listing_id );
	}
}
add_action( 'atbdp_order_completed', 'hbl_auto_approve_listing_after_payment', 15, 2 );

function hbl_send_listing_approved_email( $listing_id ) {
	$listing = get_post( $listing_id );
	if ( ! $listing ) {
		return;
	}
	
	$author_id = $listing->post_author;
	$author_email = get_the_author_meta( 'user_email', $author_id );
	
	if ( $author_email && is_email( $author_email ) ) {
		$listing_title = get_the_title( $listing_id );
		$listing_url = get_permalink( $listing_id );
		$site_name = get_bloginfo( 'name' );
		
		$subject = sprintf( __( '[%s] Your listing "%s" is now live!', 'hbl' ), $site_name, $listing_title );
		
		$message = sprintf(
			__( "Hi there,\n\nGreat news! Your listing \"%s\" has been published and is now live on %s.\n\nYou can view your listing here:\n%s\n\nThank you for choosing %s!\n\nBest regards,\nThe %s Team", 'hbl' ),
			$listing_title,
			$site_name,
			$listing_url,
			$site_name,
			$site_name
		);
		
		wp_mail( $author_email, $subject, $message );
	}
}

function hbl_ensure_paid_listing_published() {
	if ( ! isset( $_GET['order_id'], $_GET['session_id'] ) ) {
		return;
	}
	
	$order_id = absint( $_GET['order_id'] );
	if ( ! $order_id ) {
		return;
	}
	
	$listing_id = get_post_meta( $order_id, '_listing_id', true );
	if ( ! $listing_id ) {
		return;
	}
	
	$payment_status = get_post_meta( $order_id, '_payment_status', true );
	if ( $payment_status !== 'completed' ) {
		return;
	}
	
	clean_post_cache( $listing_id );
	$listing = get_post( $listing_id );
	
	if ( ! $listing || $listing->post_type !== 'at_biz_dir' ) {
		return;
	}
	
	$plan_id = get_post_meta( $order_id, '_fm_plan_ordered', true );
	if ( ! $plan_id ) {
		$plan_id = get_post_meta( $listing_id, '_fm_plans', true );
	}
	
	$plan_tier = hbl_get_plan_tier( $plan_id );
	
	if ( $listing->post_status !== 'publish' && ( $plan_tier === 'silver' || $plan_tier === 'gold' ) ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_status' => 'publish' ),
			array( 'ID' => $listing_id ),
			array( '%s' ),
			array( '%d' )
		);
		clean_post_cache( $listing_id );
		update_post_meta( $listing_id, '_auto_approved_safety_fix', current_time( 'mysql' ) );
	}
}
add_action( 'shutdown', 'hbl_ensure_paid_listing_published' );


function hbl_event_category_pre_get_posts( $query ) {
    if ( ! is_admin() && $query->is_main_query() && $query->is_tax( 'event_category' ) ) {
        $query->set( 'tax_query', array() );
        
        $query->set( 'posts_per_page', 1 );
        
        $query->set( 'post_type', 'post' );
    }
}
add_action( 'pre_get_posts', 'hbl_event_category_pre_get_posts' );

function hbl_event_category_found_posts( $found_posts, $query ) {
    if ( ! is_admin() && $query->is_main_query() && $query->is_tax( 'event_category' ) ) {
        $term = get_queried_object();
        
        if ( $term && function_exists( 'hbl_events_db' ) ) {
            $db = hbl_events_db();
            $count = $db->count_events( array(
                'category_id' => $term->term_id,
                'status'      => 'publish',
            ) );
            
            if ( $count > 0 ) {
                return $count;
            }
        }
    }
    return $found_posts;
}
add_filter( 'found_posts', 'hbl_event_category_found_posts', 10, 2 );

function hbl_event_category_posts_per_page( $query ) {
    if ( ! is_admin() && $query->is_main_query() && $query->is_tax( 'event_category' ) ) {
        $query->set( 'posts_per_page', 12 );
    }
}
add_action( 'pre_get_posts', 'hbl_event_category_posts_per_page', 11 );


function hbl_ajax_get_events() {
    check_ajax_referer( 'hbl_nonce', 'nonce' );
    
    $category_id = isset( $_POST['category_id'] ) ? intval( $_POST['category_id'] ) : 0;
    $paged = isset( $_POST['paged'] ) ? intval( $_POST['paged'] ) : 1;
    $search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
    $sort = isset( $_POST['sort'] ) ? sanitize_text_field( $_POST['sort'] ) : '';
    $view = isset( $_POST['view'] ) ? sanitize_text_field( $_POST['view'] ) : 'grid';
    
    $per_page = 12;
    $offset = ( $paged - 1 ) * $per_page;
    
    $orderby = 'start_date';
    $order = 'ASC';
    
    switch ( $sort ) {
        case 'title_asc': $orderby = 'title'; $order = 'ASC'; break;
        case 'title_desc': $orderby = 'title'; $order = 'DESC'; break;
        case 'date_desc': $orderby = 'start_date'; $order = 'DESC'; break;
        case 'date_asc': $orderby = 'start_date'; $order = 'ASC'; break;
    }
    
    if ( function_exists( 'hbl_events_db' ) ) {
        $db = hbl_events_db();
        
        $query_args = array(
            'category_id' => $category_id,
            'status'      => 'publish',
            'limit'       => -1, 
        );
        
        if ( ! empty( $search ) ) {
            $query_args['search'] = $search;
        }
        
        $raw_events = $db->get_events( $query_args );
        
        $get_next_occurrence = function( $event ) {
             $frequency = $event->event_frequency ?? 'once';
             $start_date = $event->start_date;
             $now = current_time( 'timestamp' );
             $today_start = strtotime( 'today', $now );
             $event_start = strtotime( $start_date );
             $event_time = date( 'H:i:s', $event_start );
             
             if ( $event_start >= $now ) return $start_date;
             if ( ! in_array( $frequency, array( 'weekly', 'monthly', 'recurring', 'multi_day' ) ) ) return $start_date;
             
             $recurrence_days = $event->recurrence_days ?? '';
             $recurrence_week = $event->recurrence_week ?? '';
             $recurrence_interval = isset( $event->recurrence_interval ) ? max( 1, intval( $event->recurrence_interval ) ) : 1;
             
             $normalize_day = function( $d ) {
                $d = strtolower( trim( $d ) );
                $map = array('monday'=>'mon','tuesday'=>'tue','wednesday'=>'wed','thursday'=>'thu','friday'=>'fri','saturday'=>'sat','sunday'=>'sun');
                return isset( $map[ $d ] ) ? $map[ $d ] : substr( $d, 0, 3 );
             };

             if ( $frequency === 'weekly' && ! empty( $recurrence_days ) ) {
                 $days = array_map( $normalize_day, explode( ',', $recurrence_days ) );
                 for ( $i = 0; $i < 60; $i++ ) {
                     $check_ts = strtotime( "+$i days", $today_start );
                     $day_of_week = strtolower( date( 'D', $check_ts ) );
                     if ( in_array( $day_of_week, $days ) ) {
                        $orig_week_start = strtotime( 'last monday', $event_start + 86400 );
                        $curr_week_start = strtotime( 'last monday', $check_ts + 86400 );
                        $weeks_diff = round( ( $curr_week_start - $orig_week_start ) / ( 7 * 24 * 60 * 60 ) );
                        if ( $weeks_diff % $recurrence_interval === 0 ) return date( 'Y-m-d', $check_ts ) . ' ' . $event_time;
                     }
                 }
             } elseif ( $frequency === 'monthly' && ! empty( $recurrence_days ) ) {
                 $day_map = array('sun'=>0,'mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6);
                 $day_name = $normalize_day( $recurrence_days );
                 if ( isset( $day_map[$day_name] ) ) {
                    $target_day_num = $day_map[$day_name];
                    $weeks = ! empty( $recurrence_week ) ? array_map( 'trim', explode( ',', $recurrence_week ) ) : array();
                    for ( $i = 0; $i < 12; $i++ ) {
                        $check_month_ts = strtotime( "+$i month", $today_start );
                        $year = date('Y', $check_month_ts);
                        $month = date('n', $check_month_ts);
                        $days_in_month = date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
                        $occurrence = 0;
                        for ( $day = 1; $day <= $days_in_month; $day++ ) {
                            $date_ts = mktime( 0, 0, 0, $month, $day, $year );
                            if ( date('w', $date_ts) == $target_day_num ) {
                                $occurrence++;
                                if ( in_array( $occurrence, $weeks ) ) {
                                    if ( $date_ts >= $today_start ) return date( 'Y-m-d', $date_ts ) . ' ' . $event_time;
                                }
                            }
                        }
                    }
                 }
             } elseif ( $frequency === 'multi_day' ) {
                 $end_date_ts = ! empty( $event->end_date ) ? strtotime( $event->end_date ) : $event_start;
                 if ( $end_date_ts < $today_start ) return $start_date;
                 
                 $check_ts = $today_start;
                 for ( $i = 0; $i < 60; $i++ ) {
                     $curr_ts = strtotime( "+$i days", $check_ts );
                     if ( $curr_ts > $end_date_ts ) break;
                     $days = ! empty( $recurrence_days ) ? array_map( $normalize_day, explode( ',', $recurrence_days ) ) : array();
                     if ( empty( $days ) ) return date( 'Y-m-d', $curr_ts ) . ' ' . $event_time;
                     $day_of_week = strtolower( date( 'D', $curr_ts ) );
                     if ( in_array( $day_of_week, $days ) ) return date( 'Y-m-d', $curr_ts ) . ' ' . $event_time;
                 }
             }
             return $start_date;
        };

        $processed_events = array();
        $now = current_time( 'timestamp' );
        $today_start = strtotime( 'today', $now );

        foreach ( $raw_events as $event ) {
            $next_date = $get_next_occurrence( $event );
            
            $event_item = clone $event;
            $event_item->computed_start_date = $next_date;
            $processed_events[] = $event_item;
        }

        if ( empty( $sort ) || $sort === 'date_asc' || strpos($sort, 'date') !== false ) {
             usort( $processed_events, function( $a, $b ) use ( $order ) {
                $t1 = strtotime( $a->computed_start_date );
                $t2 = strtotime( $b->computed_start_date );
                if ( $t1 == $t2 ) return 0;
                $result = ( $t1 < $t2 ) ? -1 : 1;
                return ( $order === 'DESC' ) ? -$result : $result;
            });
        } elseif ( strpos($sort, 'title') !== false ) {
            usort( $processed_events, function( $a, $b ) use ( $order ) {
                $result = strcasecmp( $a->title, $b->title );
                return ( $order === 'DESC' ) ? -$result : $result;
            });
        }
        
        $total_events = count( $processed_events );
        $max_pages = ceil( $total_events / $per_page );
        $events = array_slice( $processed_events, $offset, $per_page );
        
        ob_start();
        if ( ! empty( $events ) ) {
            ?>
            <div class="hbl-events-grid hbl-view-<?php echo esc_attr( $view ); ?>">
                <?php foreach ( $events as $event ) : 
                    $event_url = hbl_events_db()->get_event_url( $event );
                    $thumbnail_url = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'medium_large' ) : '';
                    $start_date = $event->computed_start_date;
                ?>
                <div class="hbl-event-card">
                    <a href="<?php echo esc_url( $event_url ); ?>" class="hbl-event-image-link">
                         <?php if ( $thumbnail_url ) : ?>
                            <div class="hbl-event-image" style="background-image: url('<?php echo esc_url( $thumbnail_url ); ?>');">
                         <?php else : ?>
                            <div class="hbl-event-image hbl-event-no-image" style="background-color: <?php echo $event->event_color ?: '#008080'; ?>;">
                         <?php endif; ?>
                            <?php if ( $start_date ) : ?>
                                <div class="hbl-event-date">
                                    <span class="hbl-event-date-day"><?php echo esc_html( date( 'd', strtotime( $start_date ) ) ); ?></span>
                                    <span class="hbl-event-date-month"><?php echo esc_html( date( 'M', strtotime( $start_date ) ) ); ?></span>
                                </div>
                            <?php endif; ?>
                            </div>
                    </a>
                    <div class="hbl-event-content">
                        <h3 class="hbl-event-title"><a href="<?php echo esc_url( $event_url ); ?>"><?php echo esc_html( $event->title ); ?></a></h3>
                        <a href="<?php echo esc_url( $event_url ); ?>" class="hbl-event-link">View Event</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ( $max_pages > 1 ) : ?>
                <div class="hbl-events-pagination">
                    <?php
                    $format = '?paged=%#%';
                    $args = array(
                        'base' => '%_%',
                        'format' => $format,
                        'total'     => $max_pages,
                        'current'   => $paged,
                        'prev_text' => '←',
                        'next_text' => '→',
                        'type' => 'array',
                    );
                    $links = paginate_links( $args );
                    if( $links ) {
                        foreach( $links as $link ) {
                            echo $link; 
                        }
                    }
                    ?>
                </div>
            <?php endif; 
        } else {
             echo '<div class="hbl-events-empty"><p>No events found.</p></div>';
        }
        
        $html = ob_get_clean();
        
        wp_send_json_success( array( 'html' => $html, 'max_pages' => $max_pages ) );
    }
    
    wp_send_json_error( 'DB Error' );
}
add_action( 'wp_ajax_hbl_get_events', 'hbl_ajax_get_events' );
add_action( 'wp_ajax_nopriv_hbl_get_events', 'hbl_ajax_get_events' );


require HBL_THEME_PATH . '/theme.php';

function hbl_fix_elementor_v2_notices() {
	$handles = [
		'elementor-v2-editor-canvas',
		'elementor-v2-editor-controls',
		'elementor-v2-editor-editing-panel',
		'elementor-v2-editor-elements',
		'elementor-v2-editor-props',
		'elementor-v2-editor-styles-repository',
		'elementor-v2-editor-templates'
	];
	foreach ( $handles as $handle ) {
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, false, [], false, true );
		}
	}
}
add_action( 'elementor/editor/before_enqueue_scripts', 'hbl_fix_elementor_v2_notices', 9 );
add_action( 'admin_enqueue_scripts', 'hbl_fix_elementor_v2_notices', 9 );
add_action( 'wp_enqueue_scripts', 'hbl_fix_elementor_v2_notices', 9 );

HBLTheme\Theme::instance();