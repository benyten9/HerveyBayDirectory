<?php
/**
 * HBL Theme Functions
 *
 * @package HBL
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Define theme constants
 */
define( 'HBL_VERSION', '1.2.696' );
define( 'HBL_THEME_DIR', get_template_directory() );
define( 'HBL_THEME_URI', get_template_directory_uri() );
define( 'HBL_THEME_PATH', get_template_directory() );
define( 'HBL_THEME_URL', get_template_directory_uri() );
define( 'HBL_THEME_ASSETS_PATH', HBL_THEME_PATH . '/assets/' );
define( 'HBL_THEME_ASSETS_URL', HBL_THEME_URL . '/assets/' );

/**
 * Cache-busting version for a theme asset.
 *
 * Uses the file's last-modified time so browsers/CDNs/page-cache plugins
 * (e.g. LiteSpeed Cache) automatically fetch a fresh copy whenever the
 * file changes, instead of relying on the static HBL_VERSION constant
 * being bumped by hand on every edit.
 *
 * @param string $relative_path Path relative to the theme directory, e.g. '/style.css'.
 * @return string
 */
function hbl_asset_version( $relative_path ) {
	$file = HBL_THEME_DIR . $relative_path;

	return file_exists( $file ) ? (string) filemtime( $file ) : HBL_VERSION;
}

/**
 * Theme Setup
 */
function hbl_theme_setup() {
	// Add default posts and comments RSS feed links to head
	add_theme_support( 'automatic-feed-links' );

	// Let WordPress manage the document title
	add_theme_support( 'title-tag' );

	// Enable support for Post Thumbnails
	add_theme_support( 'post-thumbnails' );

	// Enable support for HTML5 markup
	add_theme_support( 'html5', array(
		'search-form',
		'comment-form',
		'comment-list',
		'gallery',
		'caption',
		'style',
		'script',
	) );

	// Add theme support for selective refresh for widgets
	add_theme_support( 'customize-selective-refresh-widgets' );

	// Add support for core custom logo
	add_theme_support( 'custom-logo', array(
		'height'      => 100,
		'width'       => 300,
		'flex-height' => true,
		'flex-width'  => true,
	) );

	// Add support for responsive embeds
	add_theme_support( 'responsive-embeds' );

	// Add support for editor styles
	add_theme_support( 'editor-styles' );

	// Add support for full and wide align images
	add_theme_support( 'align-wide' );

	// Register navigation menus
	register_nav_menus( array(
		'primary' => esc_html__( 'Primary Menu', 'hbl' ),
		'footer'  => esc_html__( 'Footer Menu', 'hbl' ),
	) );
}
add_action( 'after_setup_theme', 'hbl_theme_setup' );

/**
 * Set the content width in pixels
 */
function hbl_content_width() {
	$GLOBALS['content_width'] = apply_filters( 'hbl_content_width', 1920 );
}
add_action( 'after_setup_theme', 'hbl_content_width', 0 );

/**
 * Add rewrite rules for events
 * - /add-event/edit/{id}/ - Edit event form
 * - /events/{slug}/ - View single event
 */
function hbl_event_rewrite_rules() {
	// Add rewrite rule for /add-event/edit/{id}/
	add_rewrite_rule(
		'^add-event/edit/([0-9]+)/?$',
		'index.php?pagename=add-event&hbl_edit_event=$matches[1]',
		'top'
	);
	
	// Add rewrite rule for /events/{slug}/ - View single event
	add_rewrite_rule(
		'^events/([^/]+)/?$',
		'index.php?pagename=events&hbl_event_slug=$matches[1]',
		'top'
	);
}
add_action( 'init', 'hbl_event_rewrite_rules' );

/**
 * Register custom query vars for events
 */
function hbl_event_query_vars( $vars ) {
	$vars[] = 'hbl_edit_event';
	$vars[] = 'hbl_event_slug';
	return $vars;
}
add_filter( 'query_vars', 'hbl_event_query_vars' );

/**
 * Register Widget Areas
 */
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

/**
 * Enqueue Scripts and Styles
 */
function hbl_scripts() {
	// Bootstrap CSS
	wp_enqueue_style(
		'bootstrap',
		'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
		array(),
		'5.3.0'
	);

	// Bootstrap Icons
	wp_enqueue_style(
		'bootstrap-icons',
		'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css',
		array(),
		'1.11.0'
	);

	// Swiper CSS
	wp_enqueue_style(
		'swiper',
		'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css',
		array(),
		'11.0.0'
	);

	// Theme stylesheet
	wp_enqueue_style(
		'hbl-style',
		get_stylesheet_uri(),
		array( 'bootstrap' ),
		hbl_asset_version( '/style.css' )
	);

	// HBL Directorist V2 Modern Styles
	wp_enqueue_style(
		'hbl-directorist-v2',
		HBL_THEME_URI . '/assets/css/hbl-directorist-v2.css',
		array( 'hbl-style' ),
		hbl_asset_version( '/assets/css/hbl-directorist-v2.css' )
	);

	// Bootstrap JavaScript Bundle (includes Popper)
	wp_enqueue_script(
		'bootstrap',
		'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
		array(),
		'5.3.0',
		true
	);

	// Swiper JavaScript
	wp_enqueue_script(
		'swiper',
		'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js',
		array(),
		'11.0.0',
		true
	);

	// Theme JavaScript
	$dependencies = array( 'jquery', 'bootstrap' );
	
	// Ensure Directorist barrating script is enqueued for our widget
	if ( function_exists( 'directorist_is_review_enabled' ) && directorist_is_review_enabled() ) {
		if ( wp_script_is( 'directorist-jquery-barrating', 'registered' ) ) {
			wp_enqueue_script( 'directorist-jquery-barrating' );
		}
	}
	
	// Add Directorist dependencies if available
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

	// Localize script for AJAX
	wp_localize_script( 'hbl-script', 'hblData', array(
		'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
		'nonce'        => wp_create_nonce( 'hbl_nonce' ),
		'loginUrl'     => home_url( '/sign-in/' ),
		'isLoggedIn'   => is_user_logged_in(),
	) );

	// Register Google reCAPTCHA v2 script (uses Elementor Pro keys)
	$recaptcha_site_key = get_option( 'elementor_pro_recaptcha_site_key', '' );
	if ( $recaptcha_site_key ) {
		wp_register_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true );
		wp_localize_script( 'hbl-script', 'hblRecaptcha', array(
			'siteKey' => $recaptcha_site_key,
		) );
	}

	// Comment reply script
	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'hbl_scripts' );

/**
 * Resource hints for third-party origins.
 *
 * The theme's render-blocking CSS (Bootstrap, Bootstrap Icons, Swiper) is served
 * from cdn.jsdelivr.net, so preconnecting lets the browser open that TLS
 * connection before it discovers the stylesheet links, shortening the critical
 * path to first paint. Remaining origins are dns-prefetched (cheaper hint) since
 * they are used later (maps, Stripe, fonts, reCAPTCHA). Purely additive — no
 * asset behaviour changes.
 */
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

/**
 * Ensure Directorist scripts are loaded for HBL widgets
 */
function hbl_enqueue_directorist_scripts() {
	// Only run on pages that might have our widget
	if ( is_admin() ) {
		return;
	}
	
	// Check if Directorist is active and reviews are enabled
	if ( function_exists( 'directorist_is_review_enabled' ) && directorist_is_review_enabled() ) {
		// Enqueue the barrating script if it's registered but not enqueued
		if ( wp_script_is( 'directorist-jquery-barrating', 'registered' ) && ! wp_script_is( 'directorist-jquery-barrating', 'enqueued' ) ) {
			wp_enqueue_script( 'directorist-jquery-barrating' );
		}
		
		// Also enqueue single listing script which might have dependencies
		if ( wp_script_is( 'directorist-single-listing', 'registered' ) && ! wp_script_is( 'directorist-single-listing', 'enqueued' ) ) {
			wp_enqueue_script( 'directorist-single-listing' );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'hbl_enqueue_directorist_scripts', 15 );

/**
 * Enqueue Editor Styles
 */
function hbl_editor_styles() {
	// Bootstrap for Gutenberg editor
	add_editor_style( 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' );
	
	// Custom editor styles
	add_editor_style( 'assets/css/editor-style.css' );
}
add_action( 'after_setup_theme', 'hbl_editor_styles' );

/**
 * Elementor Theme Support
 */
function hbl_elementor_support() {
	// Add Elementor theme locations
	add_theme_support( 'elementor', array(
		'settings' => array(
			'page_title_selector' => '.entry-title',
		),
	) );

	// Elementor Header & Footer
	add_theme_support( 'header-footer-elementor' );

	// Elementor Color Scheme
	add_theme_support( 'elementor-color-scheme' );

	// Elementor Typography Scheme
	add_theme_support( 'elementor-typography-scheme' );

	// Elementor Default Colors
	add_theme_support( 'elementor-default-colors' );

	// Elementor Default Fonts
	add_theme_support( 'elementor-default-fonts' );
}
add_action( 'after_setup_theme', 'hbl_elementor_support' );

/**
 * Register Elementor Locations
 */
function hbl_register_elementor_locations( $elementor_theme_manager ) {
	// Register all core locations for full Theme Builder support
	$elementor_theme_manager->register_all_core_location();
}
add_action( 'elementor/theme/register_locations', 'hbl_register_elementor_locations' );

/**
 * Register Custom Elementor Theme Builder Conditions
 * Adds "Single Event", "Single Blog Post", and "Event Category Archive" options to Theme Builder display conditions
 */
function hbl_register_elementor_theme_conditions( $conditions_manager ) {
	// Check if Elementor Pro is active and the class exists
	if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base' ) ) {
		return;
	}
	
	// Include the condition class files
	require_once HBL_THEME_DIR . '/inc/elementor/conditions/class-single-event-condition.php';
	require_once HBL_THEME_DIR . '/inc/elementor/conditions/class-single-blog-condition.php';
	require_once HBL_THEME_DIR . '/inc/elementor/conditions/class-event-category-archive-condition.php';
	
	// Create the singular conditions
	$event_condition = new \HBL\Elementor\Conditions\Single_Event_Condition();
	$blog_condition = new \HBL\Elementor\Conditions\Single_Blog_Condition();
	
	// Register as sub-conditions of 'singular' condition
	$singular_condition = $conditions_manager->get_condition( 'singular' );
	if ( $singular_condition ) {
		$singular_condition->register_sub_condition( $event_condition );
		$singular_condition->register_sub_condition( $blog_condition );
	} else {
		// Fallback: register as standalone if singular doesn't exist
		$conditions_manager->register_condition_instance( $event_condition );
		$conditions_manager->register_condition_instance( $blog_condition );
	}
	
	// Create and register the Event Category Archive condition
	$event_category_archive_condition = new \HBL\Elementor\Conditions\Event_Category_Archive_Condition();
	
	// Register as sub-condition of 'archive' condition
	$archive_condition = $conditions_manager->get_condition( 'archive' );
	if ( $archive_condition ) {
		$archive_condition->register_sub_condition( $event_category_archive_condition );
	} else {
		// Fallback: register as standalone if archive doesn't exist
		$conditions_manager->register_condition_instance( $event_category_archive_condition );
	}
}
add_action( 'elementor/theme/register_conditions', 'hbl_register_elementor_theme_conditions' );

/**
 * Add body classes
 */
function hbl_body_classes( $classes ) {
	// Add class if Elementor is active
	if ( did_action( 'elementor/loaded' ) ) {
		$classes[] = 'elementor-active';
		
		// Add class if page is built with Elementor
		if ( is_singular() && \Elementor\Plugin::$instance->documents->get( get_the_ID() )->is_built_with_elementor() ) {
			$classes[] = 'elementor-page';
		}
	}

	// Add class for Bootstrap
	$classes[] = 'bootstrap-enabled';

	return $classes;
}
add_filter( 'body_class', 'hbl_body_classes' );

/**
 * Add custom image sizes
 */
function hbl_image_sizes() {
	add_image_size( 'hbl-featured', 1920, 850, true );
	add_image_size( 'hbl-card', 400, 400, true );
	add_image_size( 'hbl-thumbnail', 300, 300, true );
}
add_action( 'after_setup_theme', 'hbl_image_sizes' );

/**
 * Customize excerpt length
 */
function hbl_excerpt_length( $length ) {
	return 30;
}
add_filter( 'excerpt_length', 'hbl_excerpt_length' );

/**
 * Customize excerpt more string
 */
function hbl_excerpt_more( $more ) {
	return '...';
}
add_filter( 'excerpt_more', 'hbl_excerpt_more' );

/**
 * Add custom menu walker for Bootstrap navbar
 */
require_once HBL_THEME_DIR . '/inc/bootstrap-navwalker.php';

/**
 * Custom template tags
 */
require_once HBL_THEME_DIR . '/inc/template-tags.php';

/**
 * Customizer additions
 */
require_once HBL_THEME_DIR . '/inc/customizer.php';

/**
 * Load Jetpack compatibility file
 */
if ( defined( 'JETPACK__VERSION' ) ) {
	require_once HBL_THEME_DIR . '/inc/jetpack.php';
}

/**
 * Load HBL Events Database Handler
 */
require_once HBL_THEME_DIR . '/inc/class-hbl-events-db.php';

/**
 * Load HBL Pricing Plans data provider (Directorist abstraction)
 */
require_once HBL_THEME_DIR . '/inc/class-hbl-pricing-plans.php';

/**
 * Register Event Category Taxonomy
 *
 * URLs: /events/category/{term-slug}/
 *
 * Note: Attached to 'post' post type for WordPress URL recognition.
 * Events are stored in custom database table, not as posts.
 * The widgets query the custom events table by category_id.
 */
function hbl_register_event_taxonomy() {
	// Register event_category taxonomy
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
		'show_admin_column' => false, // Don't show on regular posts
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

	// Attach to 'post' for WordPress URL recognition (events use custom DB table)
	register_taxonomy( 'event_category', array( 'post' ), $args );

	// Register event_tag taxonomy
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

/**
 * Get Event Category URL
 *
 * @param object|int $term Term object or term ID
 * @return string Category URL
 */
function hbl_get_event_category_url( $term ) {
	if ( is_numeric( $term ) ) {
		$term = get_term( $term, 'event_category' );
	}
	
	if ( ! $term || is_wp_error( $term ) ) {
		return home_url( '/whats-on/' );
	}
	
	return get_term_link( $term, 'event_category' );
}

/**
 * Load Admin Tools
 */
if ( is_admin() ) {
	// HBL Events Admin Dashboard
	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-events-admin.php';

	// HBL Bulk Category Reassign Tool
	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-bulk-category-reassign.php';

	// HBL Bulk Plan Reassign Tool
	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-bulk-plan-reassign.php';

	// HBL Duplicate Listings Tool
	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-duplicate-listings.php';

	// HBL Missing Listing Images Tool
	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-missing-images.php';

	// HBL AI Description Generator Tool
	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-ai-descriptions.php';

	// HBL Place ID Finder Tool
	try {
		require_once HBL_THEME_DIR . '/inc/admin/class-hbl-place-id.php';
	} catch ( \Throwable $e ) {
		error_log( '[HBL] Place ID Manager failed to load: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
	}

	// HBL Partner Roles (Founding Partner & Partner Agency user roles)
	require_once HBL_THEME_DIR . '/inc/admin/class-hbl-partner-roles.php';
}

/**
 * Include V2 Widget AJAX Handlers
 */
require_once HBL_THEME_DIR . '/inc/ajax/hbl-directorist-v2-ajax.php';
require_once HBL_THEME_DIR . '/inc/ajax/hbl-events-v2-ajax.php';

/**
 * Register Custom Elementor Widgets
 */
function hbl_register_elementor_widgets() {
	// Check if Elementor is installed and activated
	if ( ! did_action( 'elementor/loaded' ) ) {
		return;
	}

	// HBL Directorist V2 Widget (Modern Design)
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
	// HBL Search Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-search.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Search() );

	// HBL Static Grid Widget (Grid of Listing)
	require_once HBL_THEME_DIR . '/inc/widgets/hbl-static-grid.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Static_Grid() );

	// HBL Static Grid V2 (Dynamic Events)
	require_once HBL_THEME_DIR . '/inc/widgets/hbl-static-grid-v2.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Static_Grid_V2() );

	// HBL CTA Section Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-cta-section.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_CTA_Section() );

	// HBL Blogs Section Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-blogs-section.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Blogs_Section() );

	// HBL Row Search Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-row-search.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Row_Search() );

	// HBL FAQs Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-faqs.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_FAQs() );

	// HBL Search Column Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-search-column.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Search_Column() );

	// HBL Calendar Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-calendar.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Calendar() );

	// HBL Pricing Plan Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-pricing-plan.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Pricing_Plan() );

	// HBL Noticeboard Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-noticeboard.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Noticeboard() );

	// HBL All About HB Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-all-about-hb.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_All_About_HB() );

	// HBL Dashboard Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-dashboard.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Dashboard() );

	// HBL Account Menu Widget (header "logged in" dropdown)
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-account-menu.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Account_Menu() );

	// HBL Add Listing Form Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-add-listing-form.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Add_Listing_Form() );

	// HBL Add Event Form Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-add-event-form.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Add_Event_Form() );

	// HBL Sign In/Signup Form Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-signin-signup-form.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Signin_Signup_Form() );

	// HBL Single Listing Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-single-listing.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Single_Listing() );

	// HBL Single Event Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-single-event.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Single_Event() );

	// HBL Claim Listing Form Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-claim-listing-form.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Claim_Listing_Form() );

	// HBL Category Archive Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-category-archive.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Category_Archive() );

	// HBL Event Single Category V2 Widget
	require_once HBL_THEME_DIR . '/inc/widgets/v2/class-hbl-event-single-category-v2.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\V2\HBL_Event_Single_Category_V2() );

	// HBL Event Single Tag V2 Widget
	require_once HBL_THEME_DIR . '/inc/widgets/v2/class-hbl-event-single-tag-v2.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\V2\HBL_Event_Single_Tag_V2() );

	// HBL Event Category Archive Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-event-category-archive.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Event_Category_Archive() );

	// HBL User Profile Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-user-profile.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_User_Profile() );

	// HBL Location Archive Widget (All Locations)
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-location-archive.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Location_Archive() );

	// HBL Listing Search V2 Widget
	require_once HBL_THEME_DIR . '/inc/widgets/v2/class-hbl-listing-search-v2.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\V2\HBL_Listing_Search_V2() );

	// HBL Listing Search Results V2 Widget
	require_once HBL_THEME_DIR . '/inc/widgets/v2/class-hbl-listing-search-results-v2.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\V2\HBL_Listing_Search_Results_V2() );

	// HBL Checkout Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-checkout.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Checkout() );

	// HBL Payment Receipt Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-payment-receipt.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Payment_Receipt() );

	// HBL Transaction Failure Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-transaction-failure.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Transaction_Failure() );

	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-thank-you.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Thank_You() );

	// HBL Single Post Widget
	require_once HBL_THEME_DIR . '/inc/widgets/class-hbl-single-post.php';
	\Elementor\Plugin::instance()->widgets_manager->register( new \HBL\Widgets\HBL_Single_Post() );
}
add_action( 'elementor/widgets/register', 'hbl_register_elementor_widgets' );

/**
 * Add Custom Elementor Widget Categories
 */
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

/**
 * Get the URL of the standalone Account Dashboard page.
 *
 * Looks for a published page using the "HBL Account Dashboard" page
 * template so the URL stays correct if the page is ever renamed or moved,
 * falling back to /dashboard/ if no such page has been created yet.
 *
 * @return string
 */
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

/**
 * Render the HBL Dashboard widget outside of the Elementor editor (e.g. from
 * the standalone Account Dashboard page template), reusing the same widget
 * class so the markup, styling and behaviour stay identical everywhere it
 * appears.
 *
 * @param array $settings Optional widget settings overrides.
 */
function hbl_render_dashboard_widget( $settings = array() ) {
	if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
		echo '<div class="hbl-dashboard-notice">' . esc_html__( 'Elementor is required to display the dashboard.', 'hbl' ) . '</div>';
		return;
	}

	// Elementor's Controls_Stack::init() reads $data['id'] unconditionally, so
	// element data MUST carry an id or it throws "Undefined array key id".
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

/**
 * Force Elementor to print its frontend config + enqueue its assets on the
 * standalone Account Dashboard template.
 *
 * That template renders a single Elementor widget by hand instead of going
 * through Elementor's normal page-content pipeline, so Elementor never marks
 * the page as "has Elementor content". As a result its wp_footer handler
 * bails and `elementorFrontendConfig` is never printed - yet the widget's
 * scripts still pull in elementor-frontend.js, which then throws
 * "elementorFrontendConfig is not defined". Calling enqueue_scripts() /
 * enqueue_styles() here attaches the config to the (already enqueued)
 * elementor-frontend handle and loads the base frontend CSS.
 */
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

/**
 * Force the "HBL Account Dashboard" page template to actually load.
 *
 * Elementor's own `template_include` filter (page-templates/module.php,
 * priority 11) only recognises its own template values (Canvas / Full
 * Width). For any other value - like our custom template - it checks
 * whether the page was ever "Edited with Elementor" and, if so, silently
 * swaps in the Kit's default page template instead (normally "Elementor
 * Full Width", which renders the Theme Builder header/footer). That's why
 * the site header/footer kept appearing even though this template never
 * calls get_header()/get_footer(). Re-assert our template after Elementor
 * has had its say.
 *
 * @param string $template Template path chosen so far.
 * @return string
 */
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

/**
 * Disable Elementor default colors and fonts
 * (Forces Elementor to use theme's colors and fonts)
 */
function hbl_disable_elementor_defaults() {
	update_option( 'elementor_disable_color_schemes', 'yes' );
	update_option( 'elementor_disable_typography_schemes', 'yes' );
	update_option( 'elementor_container_width', '1920' );
	update_option( 'elementor_viewport_lg', '1200' );
	update_option( 'elementor_viewport_md', '768' );
}
add_action( 'after_switch_theme', 'hbl_disable_elementor_defaults' );

/**
 * Allow SVG uploads
 */
function hbl_mime_types( $mimes ) {
	$mimes['svg'] = 'image/svg+xml';
	$mimes['svgz'] = 'image/svg+xml';
	return $mimes;
}
add_filter( 'upload_mimes', 'hbl_mime_types' );

/**
 * Fix SVG display in media library
 */
function hbl_fix_svg_display() {
	echo '<style>
		.attachment-266x266, .thumbnail img {
			width: 100% !important;
			height: auto !important;
		}
	</style>';
}
add_action( 'admin_head', 'hbl_fix_svg_display' );

/**
 * AJAX Handlers for Calendar Widget (Pie Calendar Integration)
 */

/**
 * Check if Pie Calendar plugin is active
 */
function hbl_is_piecal_active() {
	return defined( 'PIECAL_VERSION' );
}

/**
 * Get Pie Calendar event start date
 */
function hbl_get_piecal_start_date( $post_id ) {
	return get_post_meta( $post_id, '_piecal_start_date', true );
}

/**
 * Get Pie Calendar event end date
 */
function hbl_get_piecal_end_date( $post_id ) {
	return get_post_meta( $post_id, '_piecal_end_date', true );
}

/**
 * Check if Pie Calendar event is all day
 */
function hbl_is_piecal_allday( $post_id ) {
	return (bool) get_post_meta( $post_id, '_piecal_is_allday', true );
}

/**
 * Get formatted event time display for Pie Calendar
 */
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

/**
 * Build events by date array for a month, including recurring events
 *
 * @param array $events All events from database query
 * @param int   $year Year
 * @param int   $month Month
 * @return array Events indexed by date
 */
function hbl_build_events_by_date_for_month( $events, $year, $month ) {
	$events_by_date = array();
	
	if ( empty( $events ) ) {
		return $events_by_date;
	}

	$month_padded = str_pad( $month, 2, '0', STR_PAD_LEFT );
	$days_in_month = date( 't', strtotime( "{$year}-{$month_padded}-01" ) );

	// Day name to number mapping
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

		// Handle recurring events
		if ( in_array( $event_frequency, array( 'weekly', 'monthly', 'recurring' ), true ) ) {
			hbl_add_recurring_event_dates( $events_by_date, $event, $year, $month, $days_in_month, $day_to_num );
			continue;
		}

		// Handle one-off and multi-day events
		$start = strtotime( date( 'Y-m-d', strtotime( $event_start ) ) );
		$end = ! empty( $event_end ) ? strtotime( date( 'Y-m-d', strtotime( $event_end ) ) ) : $start;
		$recurrence_days = $event->recurrence_days ?? '';

		// Parse allowed days for multi-day events
		$allowed_days = array();
		if ( ! empty( $recurrence_days ) ) {
			$allowed_days = array_map( function( $d ) { return strtolower( trim( $d ) ); }, explode( ',', $recurrence_days ) );
		}

		for ( $date = $start; $date <= $end; $date = strtotime( '+1 day', $date ) ) {
			$date_key = date( 'Y-m-d', $date );
			
			// If recurrence_days is specified, only add event if this day matches
			if ( ! empty( $allowed_days ) ) {
				$day_of_week = strtolower( date( 'D', $date ) ); // mon, tue, wed, etc.
				if ( ! in_array( $day_of_week, $allowed_days, true ) ) {
					continue; // Skip this day - not in allowed days
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

/**
 * Add recurring event dates to the events_by_date array
 *
 * @param array  $events_by_date Reference to events by date array
 * @param object $event Event object
 * @param int    $year Year
 * @param int    $month Month
 * @param int    $days_in_month Number of days in the month
 * @param array  $day_to_num Day name to number mapping
 */
function hbl_add_recurring_event_dates( &$events_by_date, $event, $year, $month, $days_in_month, $day_to_num ) {
	$event_frequency = $event->event_frequency ?? 'once';
	$recurrence_days = $event->recurrence_days ?? '';
	$recurrence_week = $event->recurrence_week ?? '';
	$recurrence_interval = $event->recurrence_interval ?? 1;
	$event_start = strtotime( $event->start_date );

	$month_padded = str_pad( $month, 2, '0', STR_PAD_LEFT );

	if ( $event_frequency === 'weekly' && ! empty( $recurrence_days ) ) {
		// Weekly recurrence - event occurs on specific days each week
		$days = array_map( function( $d ) { return strtolower( trim( $d ) ); }, explode( ',', $recurrence_days ) );

		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$date_string = "{$year}-{$month_padded}-" . str_pad( $day, 2, '0', STR_PAD_LEFT );
			$date_timestamp = strtotime( $date_string );

			// Skip if date is before event start
			if ( $date_timestamp < strtotime( date( 'Y-m-d', $event_start ) ) ) {
				continue;
			}

			$day_of_week = strtolower( date( 'D', $date_timestamp ) ); // mon, tue, wed, etc.

			// Check if this day matches recurrence days
			if ( in_array( $day_of_week, $days, true ) ) {
				// For bi-weekly, check if this is the right week
				if ( $recurrence_interval == 2 ) {
					$weeks_since_start = floor( ( $date_timestamp - $event_start ) / ( 7 * 24 * 60 * 60 ) );
					if ( $weeks_since_start % 2 !== 0 ) {
						continue; // Skip odd weeks for bi-weekly
					}
				}

				if ( ! isset( $events_by_date[ $date_string ] ) ) {
					$events_by_date[ $date_string ] = array();
				}
				$events_by_date[ $date_string ][] = $event;
			}
		}
	} elseif ( $event_frequency === 'monthly' && ! empty( $recurrence_days ) ) {
		// Monthly recurrence - event occurs on specific week(s) and day
		$day_name = strtolower( trim( $recurrence_days ) );
		$weeks = ! empty( $recurrence_week ) ? array_map( 'trim', explode( ',', $recurrence_week ) ) : array();

		if ( isset( $day_to_num[ $day_name ] ) && ! empty( $weeks ) ) {
			$target_day_num = $day_to_num[ $day_name ];

			foreach ( $weeks as $week_num ) {
				$week_num = intval( $week_num );
				if ( $week_num < 1 || $week_num > 4 ) {
					continue;
				}

				// Find the nth occurrence of the day in this month
				$occurrence = 0;
				for ( $day = 1; $day <= $days_in_month; $day++ ) {
					$date_string = "{$year}-{$month_padded}-" . str_pad( $day, 2, '0', STR_PAD_LEFT );
					$date_timestamp = strtotime( $date_string );

					if ( date( 'w', $date_timestamp ) == $target_day_num ) {
						$occurrence++;
						if ( $occurrence == $week_num ) {
							// Skip if date is before event start
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
		// Ongoing/recurring - show on the original start date only (within month)
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

/**
 * Filter recurring events to only include those that match a specific date
 *
 * @param array  $events All events from database query
 * @param string $date Date string (Y-m-d format)
 * @param int    $limit Maximum number of events to return
 * @return array Filtered events
 */
function hbl_filter_recurring_events_for_date( $events, $date, $limit = 12 ) {
	if ( empty( $events ) ) {
		return array();
	}

	$date_timestamp = strtotime( $date );
	$day_of_week = strtolower( date( 'D', $date_timestamp ) ); // mon, tue, wed, etc.

	// Day name to number mapping
	$day_to_num = array(
		'sun' => 0,
		'mon' => 1,
		'tue' => 2,
		'wed' => 3,
		'thu' => 4,
		'fri' => 5,
		'sat' => 6,
		'sun' => 0, // Fallback
	);

	$filtered_events = array();

	foreach ( $events as $event ) {
		$event_frequency = $event->event_frequency ?? 'once';
		$event_start = strtotime( $event->start_date );

		// One-off and multi-day events - include if they match the date
		if ( ! in_array( $event_frequency, array( 'weekly', 'monthly', 'recurring' ), true ) ) {
			// Check if this multi-day event has specific recurrence days
			$recurrence_days = $event->recurrence_days ?? '';
			if ( ! empty( $recurrence_days ) ) {
				// Parse allowed days
				$allowed_days = array_map( function( $d ) { return strtolower( trim( $d ) ); }, explode( ',', $recurrence_days ) );
				// Only include if today matches one of the allowed days
				if ( ! in_array( $day_of_week, $allowed_days, true ) ) {
					continue; // Skip this event - date doesn't match allowed days
				}
			}
			$filtered_events[] = $event;
			continue;
		}

		// Skip if date is before event start
		if ( $date_timestamp < strtotime( date( 'Y-m-d', $event_start ) ) ) {
			continue;
		}

		// Weekly recurring events
		if ( $event_frequency === 'weekly' ) {
			$recurrence_days = $event->recurrence_days ?? '';
			$recurrence_interval = $event->recurrence_interval ?? 1;

			if ( ! empty( $recurrence_days ) ) {
				$days = array_map( function( $d ) { return strtolower( trim( $d ) ); }, explode( ',', $recurrence_days ) );

				if ( in_array( $day_of_week, $days, true ) ) {
					// For bi-weekly, check if this is the right week
					if ( $recurrence_interval == 2 ) {
						$weeks_since_start = floor( ( $date_timestamp - $event_start ) / ( 7 * 24 * 60 * 60 ) );
						if ( $weeks_since_start % 2 !== 0 ) {
							continue; // Skip odd weeks for bi-weekly
						}
					}
					$filtered_events[] = $event;
				}
			}
			continue;
		}

		// Monthly recurring events
		if ( $event_frequency === 'monthly' ) {
			$recurrence_days = $event->recurrence_days ?? '';
			$recurrence_week = $event->recurrence_week ?? '';

			if ( ! empty( $recurrence_days ) && ! empty( $recurrence_week ) ) {
				$day_name = strtolower( trim( $recurrence_days ) );
				$weeks = array_map( 'trim', explode( ',', $recurrence_week ) );

				if ( isset( $day_to_num[ $day_name ] ) ) {
					$target_day_num = $day_to_num[ $day_name ];

					// Check if today's day of week matches
					if ( date( 'w', $date_timestamp ) == $target_day_num ) {
						// Calculate which occurrence of this day in the month
						$day_of_month = (int) date( 'j', $date_timestamp );
						$current_month = date( 'n', $date_timestamp );
						$current_year = date( 'Y', $date_timestamp );

						// Count occurrences of this day up to and including today
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

		// Ongoing/recurring - only show on original start date
		if ( $event_frequency === 'recurring' ) {
			if ( date( 'Y-m-d', $event_start ) === $date ) {
				$filtered_events[] = $event;
			}
		}
	}

	// Apply limit if specificied, otherwise return all
	if ( $limit > 0 ) {
		return array_slice( $filtered_events, 0, $limit );
	}
	
	return $filtered_events;
}

/**
 * Get the next occurrence of an event starting from a specific date.
 */
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

/**
 * Get events for a specific date from custom HBL Events database
 */
function hbl_get_calendar_events() {
	check_ajax_referer( 'hbl_nonce', 'nonce' );
	
	// Check if HBL Events DB is available
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
	
	// Base query structure
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
		// Complex date filtering logic
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

	// Category Filtering
	if ( ! empty($category) ) {
		// Try to find the term by slug to get term_id
		$term = get_term_by( 'slug', $category, 'event_category' );
		if ( $term ) {
			$query_where .= " AND category_id = %d";
			$query_args[] = $term->term_id;
		}
	}

	// Keyword Search Filtering
	if ( ! empty($search) ) {
		$query_where .= " AND (title LIKE %s OR description LIKE %s)";
		$like_param = '%' . $wpdb->esc_like( $search ) . '%';
		$query_args[] = $like_param;
		$query_args[] = $like_param;
	}

	// AZ Filter
	if ( ! empty($azFilter) && $azFilter !== 'All' ) {
		$query_where .= " AND title LIKE %s";
		$query_args[] = $wpdb->esc_like( $azFilter ) . '%';
	}

	// Event Tag filtering — match against the tags column (comma-separated term IDs)
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

	// Sorting
	$order_by = "ORDER BY ";
	if ( $sort === 'a-z' ) {
		$order_by .= "title ASC, start_date ASC";
	} elseif ( $sort === 'z-a' ) {
		$order_by .= "title DESC, start_date ASC";
	} else { // 'recommended', 'newest', or default
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
		// Filter recurring events to only include those that match this specific date
		// Pass -1 to get all events for pagination
		$events = hbl_filter_recurring_events_for_date( $all_events, $date, -1 );
	}
	
	// Apply pagination
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
		
		// Get event time display
		$time_display = '';
		if ( $event->is_allday ) {
			$time_display = __( 'All Day', 'hbl' );
		} else {
			$start_time = date( get_option( 'time_format' ), strtotime( $event->start_date ) );
			$end_time = $event->end_date ? date( get_option( 'time_format' ), strtotime( $event->end_date ) ) : '';
			$time_display = $end_time ? $start_time . ' - ' . $end_time : $start_time;
		}
		
		// Get event URL
		$event_url = hbl_events_db()->get_event_url( $event );
		
		// Get category name and link
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

/**
 * Get calendar HTML for a specific month from custom HBL Events database
 */
function hbl_get_calendar_month() {
	check_ajax_referer( 'hbl_nonce', 'nonce' );
	
	// Check if HBL Events DB is available
	if ( ! function_exists( 'hbl_events_db' ) ) {
		wp_send_json_error( array( 'message' => 'HBL Events system is not available.' ) );
		return;
	}
	
	$year = isset( $_POST['year'] ) ? intval( $_POST['year'] ) : date( 'Y' );
	$month = isset( $_POST['month'] ) ? intval( $_POST['month'] ) : date( 'n' );
	$category = isset( $_POST['category'] ) ? sanitize_text_field( $_POST['category'] ) : '';
	
	global $wpdb;
	$table = hbl_events_db()->get_table_name();
	
	// Get events for the month
	$month_padded = str_pad( $month, 2, '0', STR_PAD_LEFT );
	$first_day = "{$year}-{$month_padded}-01";
	$start_date = "{$first_day} 00:00:00";
	$last_day = date( 't', strtotime( "{$year}-{$month_padded}-01" ) );
	$last_day_date = "{$year}-{$month_padded}-{$last_day}";
	$end_date = "{$last_day_date} 23:59:59";
	
	// Base query structure
	$query_select = "SELECT * FROM `{$table}`";
	$query_where = "WHERE status = %s";
	$query_args = array( 'publish' );
	
	// Complex date filtering logic
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

	// Category Filtering
	if ( ! empty($category) ) {
		// Try to find the term by slug to get term_id
		$term = get_term_by( 'slug', $category, 'event_category' );
		if ( $term ) {
			$query_where .= " AND category_id = %d";
			$query_args[] = $term->term_id;
		}
	}

	$sql = $wpdb->prepare( "{$query_select} {$query_where} ORDER BY start_date ASC", $query_args );
	
	$events = $wpdb->get_results( $sql );
	
	// Create events lookup by date using the helper function
	$events_by_date = hbl_build_events_by_date_for_month( $events, $year, $month );
	
	// Generate calendar HTML
	$first_day = mktime( 0, 0, 0, $month, 1, $year );
	$first_day_of_week = date( 'w', $first_day );
	$first_day_of_week = $first_day_of_week == 0 ? 7 : $first_day_of_week; // Convert Sunday from 0 to 7
	$days_in_month = date( 't', $first_day );
	$current_date = current_time( 'Y-m-d' );
	
	$calendar_html = '';
	$week = array();
	$days_in_week = 0;
	
	// Add empty cells for days before the first day of the month
	for ( $i = 1; $i < $first_day_of_week; $i++ ) {
		$week[] = '<div class="hbl-calendar-date-wrapper other-month"><div class="hbl-calendar-date empty"></div></div>';
		$days_in_week++;
	}
	
	// Add days of the current month
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
		
		// Close week when it reaches 7 days
		if ( $days_in_week == 7 ) {
			$calendar_html .= '<div class="hbl-calendar-week">' . implode( '', $week ) . '</div>';
			$week = array();
			$days_in_week = 0;
		}
	}
	
	// Add empty cells for days after the last day of the month to complete the final week
	while ( $days_in_week < 7 && $days_in_week > 0 ) {
		$week[] = '<div class="hbl-calendar-date-wrapper other-month"><div class="hbl-calendar-date empty"></div></div>';
		$days_in_week++;
	}
	
	// Close the final week if it has any days
	if ( $days_in_week > 0 ) {
		$calendar_html .= '<div class="hbl-calendar-week">' . implode( '', $week ) . '</div>';
	}
	
	wp_send_json_success( array( 'calendar' => $calendar_html ) );
}
add_action( 'wp_ajax_hbl_get_calendar_month', 'hbl_get_calendar_month' );
add_action( 'wp_ajax_nopriv_hbl_get_calendar_month', 'hbl_get_calendar_month' );

/**
 * AJAX handler for Explore By Category widget - Get events by days range using Pie Calendar
 */
function hbl_get_explore_category_events() {
	check_ajax_referer( 'hbl_nonce', 'nonce' );
	
	// Check if Pie Calendar plugin is active
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
				// Events that start within the date range
				array(
					'key'     => '_piecal_start_date',
					'value'   => array( $start_date, $end_date ),
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				),
				// Multi-day events that span into the range
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
	
	// Add taxonomy filter if specified
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
	
	// Format events for response
	$events_data = array();
	foreach ( $query->posts as $event ) {
		$event_id = $event->ID;
		$event_image = get_the_post_thumbnail_url( $event_id, 'large' );
		
		// Use placeholder if no image
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

/**
 * ============================================
 * HBL DASHBOARD AJAX HANDLERS
 * ============================================
 */

/**
 * Delete listing AJAX handler
 */
function hbl_delete_listing() {
	// Verify nonce
	if ( ! wp_verify_nonce( $_POST['nonce'], 'hbl_nonce' ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}

	// Check if user is logged in
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'Not logged in' );
	}

	$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	
	if ( ! $listing_id ) {
		wp_send_json_error( 'Invalid listing ID' );
	}

	// Verify the user owns this listing
	$listing = get_post( $listing_id );
	if ( ! $listing || $listing->post_author != get_current_user_id() ) {
		wp_send_json_error( 'Unauthorized' );
	}

	// Delete the listing
	$result = wp_delete_post( $listing_id, true );
	
	if ( $result ) {
		wp_send_json_success( array( 'message' => 'Listing deleted successfully' ) );
	} else {
		wp_send_json_error( 'Failed to delete listing' );
	}
}
add_action( 'wp_ajax_hbl_delete_listing', 'hbl_delete_listing' );

/**
 * Remove favorite AJAX handler
 */
function hbl_remove_favorite() {
	// Verify nonce
	if ( ! wp_verify_nonce( $_POST['nonce'], 'hbl_nonce' ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}

	// Check if user is logged in
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'Not logged in' );
	}

	$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	$user_id = get_current_user_id();
	
	if ( ! $listing_id ) {
		wp_send_json_error( 'Invalid listing ID' );
	}

	// Get current favorites
	$favorites = get_user_meta( $user_id, 'atbdp_favourites', true );
	
	if ( ! is_array( $favorites ) ) {
		$favorites = array();
	}

	// Remove from favorites
	$key = array_search( $listing_id, $favorites );
	if ( $key !== false ) {
		unset( $favorites[ $key ] );
		$favorites = array_values( $favorites ); // Re-index array
		update_user_meta( $user_id, 'atbdp_favourites', $favorites );
		wp_send_json_success( array( 'message' => 'Removed from favorites' ) );
	} else {
		wp_send_json_error( 'Not in favorites' );
	}
}
add_action( 'wp_ajax_hbl_remove_favorite', 'hbl_remove_favorite' );

/**
 * Update profile AJAX handler
 */
function hbl_update_profile() {
	// Verify nonce
	if ( ! wp_verify_nonce( $_POST['hbl_profile_nonce'], 'hbl_update_profile' ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}

	// Check if user is logged in
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'Not logged in' );
	}

	$user_id = get_current_user_id();
	
	// Update basic info
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
	
	// Update user
	$result = wp_update_user( $user_data );
	
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}
	
	// Update meta fields
	if ( isset( $_POST['phone'] ) ) {
		update_user_meta( $user_id, 'atbdp_phone', sanitize_text_field( $_POST['phone'] ) );
	}
	if ( isset( $_POST['address'] ) ) {
		update_user_meta( $user_id, 'address', sanitize_text_field( $_POST['address'] ) );
	}
	if ( isset( $_POST['bio'] ) ) {
		update_user_meta( $user_id, 'description', sanitize_textarea_field( $_POST['bio'] ) );
	}
	
	// Profile image
	if ( isset( $_POST['profile_image'] ) ) {
		$profile_image_id = absint( $_POST['profile_image'] );
		if ( $profile_image_id > 0 ) {
			update_user_meta( $user_id, 'hbl_profile_image', $profile_image_id );
		} else {
			delete_user_meta( $user_id, 'hbl_profile_image' );
		}
	}
	
	// Social links
	if ( isset( $_POST['facebook'] ) ) {
		update_user_meta( $user_id, 'atbdp_facebook', esc_url_raw( $_POST['facebook'] ) );
	}
	if ( isset( $_POST['twitter'] ) ) {
		update_user_meta( $user_id, 'atbdp_twitter', esc_url_raw( $_POST['twitter'] ) );
	}
	if ( isset( $_POST['linkedin'] ) ) {
		update_user_meta( $user_id, 'atbdp_linkedin', esc_url_raw( $_POST['linkedin'] ) );
	}
	
	// Password change
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

/**
 * Save profile image AJAX handler
 */
function hbl_save_profile_image() {
	// Verify nonce
	if ( ! isset( $_POST['hbl_profile_nonce'] ) || ! wp_verify_nonce( $_POST['hbl_profile_nonce'], 'hbl_update_profile' ) ) {
		wp_send_json_error( 'Security check failed' );
	}
	
	// Check if user is logged in
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'You must be logged in' );
	}
	
	$user_id = get_current_user_id();
	
	// Handle profile image
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

/**
 * Toggle favorite AJAX handler
 */
function hbl_toggle_favorite() {
	// Verify nonce
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'hbl_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Invalid security token' ) );
	}

	// Check if user is logged in
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Please log in to add favorites', 'login_required' => true ) );
	}

	$user_id = get_current_user_id();
	$item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
	$item_type = isset( $_POST['item_type'] ) ? sanitize_text_field( $_POST['item_type'] ) : 'listing';

	if ( ! $item_id ) {
		wp_send_json_error( array( 'message' => 'Invalid item' ) );
	}

	// Determine meta key based on type - use atbdp_favourites for listings (Directorist compatibility)
	$meta_key = ( $item_type === 'event' ) ? 'hbl_favorite_events' : 'atbdp_favourites';

	// Get current favorites
	$favorites = get_user_meta( $user_id, $meta_key, true );
	$favorites = is_array( $favorites ) ? $favorites : array();

	// Toggle favorite
	$is_favorited = in_array( $item_id, $favorites );
	
	if ( $is_favorited ) {
		// Remove from favorites
		$favorites = array_diff( $favorites, array( $item_id ) );
		$message = ( $item_type === 'event' ) ? 'Event removed from favorites' : 'Listing removed from favorites';
	} else {
		// Add to favorites
		$favorites[] = $item_id;
		$message = ( $item_type === 'event' ) ? 'Event added to favorites' : 'Listing added to favorites';
	}

	// Update user meta
	update_user_meta( $user_id, $meta_key, array_values( $favorites ) );

	wp_send_json_success( array( 
		'message' => $message,
		'is_favorited' => ! $is_favorited,
		'count' => count( $favorites )
	) );
}
add_action( 'wp_ajax_hbl_toggle_favorite', 'hbl_toggle_favorite' );
add_action( 'wp_ajax_nopriv_hbl_toggle_favorite', 'hbl_toggle_favorite' );

/**
 * Save event AJAX handler
 * Saves events to custom database table (not as posts)
 */
function hbl_save_event() {
	// Verify nonce
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'hbl_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
	}

	// Check if user is logged in
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'You must be logged in' ) );
	}

	$recaptcha_result = hbl_verify_recaptcha( isset( $_POST['g-recaptcha-response'] ) ? $_POST['g-recaptcha-response'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
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

	// HBL event fields
	// HBL event fields
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

	// Recurrence fields
	$recurrence_type = isset( $_POST['recurrence_type'] ) ? sanitize_text_field( $_POST['recurrence_type'] ) : null;
	$recurrence_interval = isset( $_POST['recurrence_interval'] ) ? absint( $_POST['recurrence_interval'] ) : 1;
	
	// Handle dates based on scheduling type
	$start_date = '';
	$end_date = '';
	$daily_start_time = null;
	$daily_end_time = null;
	$recurrence_days = null;
	$recurrence_week = null;

	if ( $scheduling_type === 'single' ) {
		// Single/Recurring logic (Option A)
		$date_part = isset( $_POST['start_date_single'] ) ? sanitize_text_field( $_POST['start_date_single'] ) : '';
		$start_time = isset( $_POST['start_time_single'] ) ? sanitize_text_field( $_POST['start_time_single'] ) : '00:00';
		$end_time = isset( $_POST['end_time_single'] ) ? sanitize_text_field( $_POST['end_time_single'] ) : '00:00';

		if ( ! empty( $date_part ) ) {
			$start_date = $date_part . ' ' . $start_time . ':00';
			$end_date = $date_part . ' ' . $end_time . ':00';
		}

		// Handle recurrence days for weekly/monthly
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

		// Handle recurrence week (for monthly events)
		if ( $event_frequency === 'monthly' && ! empty( $_POST['recurrence_week'] ) ) {
			$weeks = array_map( 'sanitize_text_field', (array) $_POST['recurrence_week'] );
			$valid_weeks = array( '1', '2', '3', '4', '5' );
			$weeks = array_intersect( $weeks, $valid_weeks );
			$recurrence_week = ! empty( $weeks ) ? implode( ',', $weeks ) : null;
		}

	} elseif ( $scheduling_type === 'multi' ) {
		// Multi-day logic (Option B)
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

		// Multi-day open days (Mon-Sun)
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
		
		// Force frequency generic for multi
		$event_frequency = 'multi_day'; 
	} elseif ( isset($_POST['start_date']) ) {
		// Fallback for legacy calls or other contexts
		$start_date = sanitize_text_field( $_POST['start_date'] );
		$end_date = isset( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : '';
	}

	if ( empty( $title ) || empty( $start_date ) ) {
		wp_send_json_error( array( 'message' => 'Title and start date are required' ) );
	}

	// Format dates for MySQL
	$start_datetime = date( 'Y-m-d H:i:s', strtotime( $start_date ) );
	$end_datetime = ! empty( $end_date ) ? date( 'Y-m-d H:i:s', strtotime( $end_date ) ) : null;

	// Generate internal tags
	$internal_tags = hbl_generate_event_internal_tags( $event_type, $event_cost, $event_frequency, $is_program, $organiser_type );

	// Prepare event data
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

	// Get database handler
	$db = hbl_events_db();

	if ( $event_id ) {
		// Update existing event
		// Check if user owns the event or is admin
		if ( ! $db->user_owns_event( $event_id, $user_id ) && ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to edit this event' ) );
		}
		
		$result = $db->update( $event_id, $event_data );
		
		if ( ! $result ) {
			wp_send_json_error( array( 'message' => 'Failed to update event' ) );
		}
		
		// Get the updated event to return the slug
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
		// Create new event
		$new_event_id = $db->insert( $event_data );
		
		if ( ! $new_event_id ) {
			wp_send_json_error( array( 'message' => 'Failed to create event' ) );
		}
		
		// Get the created event to return the slug
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

/**
 * Generate internal tags for an event
 */
function hbl_generate_event_internal_tags( $event_type, $event_cost, $event_frequency, $is_program, $organiser_type ) {
	$tags = array();

	// Event type tags
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

	// Cost tags
	$cost_tags = array(
		'free' => 'event-free',
		'paid' => 'event-paid',
	);
	if ( $event_cost && isset( $cost_tags[ $event_cost ] ) ) {
		$tags[] = $cost_tags[ $event_cost ];
	}

	// Frequency tags
	$frequency_tags = array(
		'once'      => 'event-once',
		'weekly'    => 'event-weekly',
		'monthly'   => 'event-monthly',
		'recurring' => 'event-recurring',
	);
	if ( $event_frequency && isset( $frequency_tags[ $event_frequency ] ) ) {
		$tags[] = $frequency_tags[ $event_frequency ];
	}

	// Program tag
	if ( $is_program ) {
		$tags[] = 'event-program';
	}

	// Organiser type tags
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

/**
 * Delete event AJAX handler
 */
function hbl_delete_event() {
	// Verify nonce
	if ( ! wp_verify_nonce( $_POST['nonce'], 'hbl_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
	}

	// Check if user is logged in
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

/**
 * Save listing AJAX handler
 */
function hbl_save_listing() {
	// Verify nonce
	if ( ! isset( $_POST['listing_nonce'] ) || ! wp_verify_nonce( $_POST['listing_nonce'], 'hbl_listing_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Invalid security token. Please refresh the page.' ) );
	}

	// Check if user is logged in
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'You must be logged in to submit a listing.' ) );
	}

	$recaptcha_result = hbl_verify_recaptcha( isset( $_POST['g-recaptcha-response'] ) ? $_POST['g-recaptcha-response'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( true !== $recaptcha_result ) {
		wp_send_json_error( array( 'message' => $recaptcha_result ) );
	}

	// Check if Directorist is active
	if ( ! defined( 'ATBDP_VERSION' ) ) {
		wp_send_json_error( array( 'message' => 'Directorist plugin is required.' ) );
	}

	$user_id    = get_current_user_id();
	$editing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	$title      = isset( $_POST['listing_title'] ) ? sanitize_text_field( $_POST['listing_title'] ) : '';
	$content    = isset( $_POST['listing_content'] ) ? wp_kses_post( $_POST['listing_content'] ) : '';
	$tagline    = isset( $_POST['listing_tagline'] ) ? sanitize_text_field( $_POST['listing_tagline'] ) : '';
	
	// Handle multiple categories (comma-separated IDs)
	$category_input = isset( $_POST['listing_category'] ) ? sanitize_text_field( $_POST['listing_category'] ) : '';
	$categories = array();
	if ( ! empty( $category_input ) ) {
		// Check if it's comma-separated
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
	$category = ! empty( $categories ) ? $categories[0] : 0; // Keep for backward compatibility
	
	$location   = isset( $_POST['listing_location'] ) ? absint( $_POST['listing_location'] ) : 0;
	$phone      = isset( $_POST['listing_phone'] ) ? sanitize_text_field( $_POST['listing_phone'] ) : '';
	$email      = isset( $_POST['listing_email'] ) ? sanitize_email( $_POST['listing_email'] ) : '';
	$website    = isset( $_POST['listing_website'] ) ? esc_url_raw( $_POST['listing_website'] ) : '';
	$address    = isset( $_POST['listing_address'] ) ? sanitize_text_field( $_POST['listing_address'] ) : '';
	$image_id   = isset( $_POST['listing_image'] ) ? absint( $_POST['listing_image'] ) : 0;
	
	// Social media fields
	$facebook   = isset( $_POST['listing_facebook'] ) ? esc_url_raw( $_POST['listing_facebook'] ) : '';
	$instagram  = isset( $_POST['listing_instagram'] ) ? esc_url_raw( $_POST['listing_instagram'] ) : '';
	$twitter    = isset( $_POST['listing_twitter'] ) ? esc_url_raw( $_POST['listing_twitter'] ) : '';
	$linkedin   = isset( $_POST['listing_linkedin'] ) ? esc_url_raw( $_POST['listing_linkedin'] ) : '';
	$youtube    = isset( $_POST['listing_youtube'] ) ? esc_url_raw( $_POST['listing_youtube'] ) : '';
	$tiktok     = isset( $_POST['listing_tiktok'] ) ? esc_url_raw( $_POST['listing_tiktok'] ) : '';
	$video      = isset( $_POST['listing_video'] ) ? esc_url_raw( $_POST['listing_video'] ) : '';

	// Validation
	if ( empty( $title ) ) {
		wp_send_json_error( array( 'message' => 'Business name is required.' ) );
	}

	if ( empty( $content ) ) {
		wp_send_json_error( array( 'message' => 'Description is required.' ) );
	}

	if ( empty( $categories ) ) {
		wp_send_json_error( array( 'message' => 'Please select a category.' ) );
	}
	
	// Check if updating existing listing
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

	// Handle plan and order if provided (check both GET and POST)
	// Check multiple possible field names: listing_package (from form), plan_id, plan
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

	// Determine post status
	if ( $is_update ) {
		// Keep current status when updating
		$post_status = get_post_status( $editing_id );
	} else {
		// Determine post status based on plan tier
		if ( $plan_id > 0 ) {
			$plan_tier = hbl_get_plan_tier( $plan_id );
			
			// Bronze plans require approval, Silver/Gold auto-approve
			if ( $plan_tier === 'bronze' ) {
				$post_status = 'pending'; // Bronze listings need approval
			} else {
				$post_status = 'publish'; // Silver/Gold listings auto-approve
			}
		} else {
			// Use Directorist setting for new listings without a plan
			$post_status = get_directorist_option( 'new_listing_status', 'pending' );
		}
	}

	// Prepare post data
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

	// An owner editing a listing they have already claimed. The claim itself
	// never fires a save, so the first owner edit after claiming is an
	// unambiguous "edited since claiming" signal for the CRM bridge.
	if ( $is_update && get_post_meta( $listing_id, '_claimed_by_admin', true ) ) {
		do_action( 'hbl_owner_edited_listing', (int) $listing_id, (int) $user_id );
	}

	// Set categories (support multiple categories)
	if ( ! empty( $categories ) ) {
		wp_set_object_terms( $listing_id, $categories, ATBDP_CATEGORY );
	}

	// Set location
	if ( $location ) {
		wp_set_object_terms( $listing_id, $location, ATBDP_LOCATION );
	}

	// Set tags (from listing_tagline field - it's actually tags, not tagline)
	$tags_input = $tagline; // The tagline field is used for tags
	$tags_taxonomy = 'at_biz_dir-tags';
	
	if ( ! empty( $tags_input ) && taxonomy_exists( $tags_taxonomy ) ) {
		// Tags are comma-separated
		$tag_names = array_map( 'trim', explode( ',', $tags_input ) );
		$tag_names = array_filter( $tag_names );
		
		if ( ! empty( $tag_names ) ) {
			$tag_ids = array();
			foreach ( $tag_names as $tag_name ) {
				if ( empty( $tag_name ) ) continue;
				
				// Check if tag exists
				$term = term_exists( $tag_name, $tags_taxonomy );
				
				if ( $term ) {
					$tag_ids[] = (int) $term['term_id'];
				} else {
					// Create new tag
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
		// Clear tags if empty
		if ( taxonomy_exists( $tags_taxonomy ) ) {
			wp_set_object_terms( $listing_id, array(), $tags_taxonomy );
		}
	}

	// Save meta fields (use update_post_meta to allow clearing values on edit)
	update_post_meta( $listing_id, '_tagline', $tagline );
	update_post_meta( $listing_id, '_phone', $phone );
	update_post_meta( $listing_id, '_email', $email );
	update_post_meta( $listing_id, '_website', $website );
	update_post_meta( $listing_id, '_address', $address );

	// Set featured image / preview image
	if ( $image_id ) {
		set_post_thumbnail( $listing_id, $image_id );
		
		// Save to _listing_prv_img for Directorist admin compatibility (expects attachment ID)
		update_post_meta( $listing_id, '_listing_prv_img', $image_id );
		
		// Save logo/file to custom-file - Directorist plupload expects format: url|id|title|caption
		$logo_url = wp_get_attachment_image_url( $image_id, 'full' );
		$logo_title = get_the_title( $image_id );
		if ( $logo_url ) {
			// Format: url|id|title|caption (Directorist plupload format)
			$custom_file_value = $logo_url . '|' . $image_id . '|' . $logo_title . '|';
			update_post_meta( $listing_id, '_custom-file', $custom_file_value );
			update_post_meta( $listing_id, 'custom-file', $custom_file_value );
			
			// Also save just the URL for frontend display compatibility
			update_post_meta( $listing_id, '_custom-file-url', $logo_url );
		}
	} else {
		// Clear preview image if not set
		delete_post_meta( $listing_id, '_listing_prv_img' );
		delete_post_meta( $listing_id, '_custom-file' );
		delete_post_meta( $listing_id, 'custom-file' );
		delete_post_meta( $listing_id, '_custom-file-url' );
		delete_post_thumbnail( $listing_id );
	}
	
	// Save gallery images
	$gallery_input = isset( $_POST['listing_gallery'] ) ? sanitize_text_field( $_POST['listing_gallery'] ) : '';
	
	if ( ! empty( $gallery_input ) ) {
		// Gallery is stored as comma-separated attachment IDs from form
		$gallery_ids = array_map( 'absint', explode( ',', $gallery_input ) );
		$gallery_ids = array_filter( $gallery_ids );
		
		if ( ! empty( $gallery_ids ) ) {
			// Directorist expects an ARRAY of IDs, not a comma-separated string
			update_post_meta( $listing_id, '_listing_img', $gallery_ids );
		} else {
			delete_post_meta( $listing_id, '_listing_img' );
		}
	} else {
		// Clear gallery if empty
		delete_post_meta( $listing_id, '_listing_img' );
	}
	
	// Save social media fields - individual fields
	update_post_meta( $listing_id, '_facebook', $facebook );
	update_post_meta( $listing_id, '_instagram', $instagram );
	update_post_meta( $listing_id, '_twitter', $twitter );
	update_post_meta( $listing_id, '_linkedin', $linkedin );
	update_post_meta( $listing_id, '_youtube', $youtube );
	update_post_meta( $listing_id, '_tiktok', $tiktok );
	
	// Also save social as serialized array for Directorist admin compatibility
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
	
	// Save video URL
	update_post_meta( $listing_id, '_videourl', $video );
	update_post_meta( $listing_id, 'videourl', $video );

	// Get directory type from URL or default
	$directory_type = isset( $_GET['directory_type'] ) ? absint( $_GET['directory_type'] ) : 0;
	if ( $directory_type ) {
		update_post_meta( $listing_id, '_directory_type', $directory_type );
	}

	// Save plan and order (already retrieved above)
	if ( $plan_id ) {
		update_post_meta( $listing_id, '_fm_plans', $plan_id );
	}

	if ( $order_id ) {
		update_post_meta( $listing_id, '_order_id', $order_id );
	}

	// Save Services - save to actual Directorist field keys (custom-textarea)
	$services = isset( $_POST['listing_services'] ) ? array_map( 'sanitize_text_field', $_POST['listing_services'] ) : array();
	$services = array_filter( $services ); // Remove empty items
	
	if ( ! empty( $services ) ) {
		$services_text = implode( "\n", $services );
		// Save to Directorist's actual field key: custom-textarea
		update_post_meta( $listing_id, '_custom-textarea', $services_text );
		update_post_meta( $listing_id, 'custom-textarea', $services_text );
		// Also save to legacy keys for compatibility
		update_post_meta( $listing_id, '_services', $services_text );
		update_post_meta( $listing_id, 'services', $services_text );
	} else {
		delete_post_meta( $listing_id, '_custom-textarea' );
		delete_post_meta( $listing_id, 'custom-textarea' );
		delete_post_meta( $listing_id, '_services' );
		delete_post_meta( $listing_id, 'services' );
	}

	// Save Pricing - save to actual Directorist field keys (custom-textarea-2)
	$pricing = isset( $_POST['listing_pricing'] ) ? array_map( 'sanitize_text_field', $_POST['listing_pricing'] ) : array();
	$pricing = array_filter( $pricing ); // Remove empty items
	
	if ( ! empty( $pricing ) ) {
		$pricing_text = implode( "\n", $pricing );
		// Save to Directorist's actual field key: custom-textarea-2
		update_post_meta( $listing_id, '_custom-textarea-2', $pricing_text );
		update_post_meta( $listing_id, 'custom-textarea-2', $pricing_text );
		// Also save to legacy keys for compatibility
		update_post_meta( $listing_id, '_pricing', $pricing_text );
		update_post_meta( $listing_id, 'pricing', $pricing_text );
	} else {
		delete_post_meta( $listing_id, '_custom-textarea-2' );
		delete_post_meta( $listing_id, 'custom-textarea-2' );
		delete_post_meta( $listing_id, '_pricing' );
		delete_post_meta( $listing_id, 'pricing' );
	}
	
	// Save Address to multiple possible field keys
	update_post_meta( $listing_id, '_address', $address );
	update_post_meta( $listing_id, 'address', $address );
	// Also save to custom-text which might be used for address
	update_post_meta( $listing_id, '_custom-text', $address );
	update_post_meta( $listing_id, 'custom-text', $address );

	// Get the redirect URL (dashboard or listing page)
	$dashboard_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_dashboard_page_link() : home_url();
	$listing_url = get_permalink( $listing_id );

	// Check if this is a paid plan that requires checkout
	$requires_payment = false;
	$checkout_url = '';
	
	if ( ! $is_update && $plan_id > 0 ) {
		// Check if plan has a price (not free)
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
			// Set listing to pending until payment is complete
			wp_update_post( array(
				'ID'          => $listing_id,
				'post_status' => 'pending',
			) );
			
			// Build checkout URL
			$checkout_url = add_query_arg( array(
				'listing_id' => $listing_id,
				'plan_id'    => $plan_id,
			), home_url( '/checkout/' ) );
		}
	}

	// Determine redirect URL
	if ( $requires_payment ) {
		$redirect_url = $checkout_url;
	} else {
		$redirect_url = $post_status === 'publish' ? $listing_url : $dashboard_url;
	}

	// Determine success message
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

/**
 * Debug AJAX handler to check listing meta data
 * Only available to administrators
 */
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
	
	// Get terms
	$categories = get_the_terms( $listing_id, ATBDP_CATEGORY );
	$locations = get_the_terms( $listing_id, ATBDP_LOCATION );
	$tags = get_the_terms( $listing_id, 'at_biz_dir-tags' );
	
	$terms_data = array(
		'categories' => $categories ? wp_list_pluck( $categories, 'name' ) : array(),
		'locations'  => $locations ? wp_list_pluck( $locations, 'name' ) : array(),
		'tags'       => $tags ? wp_list_pluck( $tags, 'name' ) : array(),
	);
	
	// Get featured image
	$featured_image_id = get_post_thumbnail_id( $listing_id );
	
	wp_send_json_success( array(
		'listing_id'        => $listing_id,
		'meta_data'         => $meta_data,
		'terms_data'        => $terms_data,
		'featured_image_id' => $featured_image_id,
	) );
}
add_action( 'wp_ajax_hbl_debug_listing_meta', 'hbl_debug_listing_meta' );

/**
 * Fix existing listing data to match Directorist's expected format
 * This runs when viewing a listing in admin and fixes data format issues
 */
function hbl_fix_listing_data_format( $post_id ) {
	if ( ! defined( 'ATBDP_POST_TYPE' ) || get_post_type( $post_id ) !== ATBDP_POST_TYPE ) {
		return;
	}
	
	// Fix 1: Ensure _listing_prv_img is set from featured image
	$listing_prv_img = get_post_meta( $post_id, '_listing_prv_img', true );
	$featured_image_id = get_post_thumbnail_id( $post_id );
	
	if ( empty( $listing_prv_img ) && $featured_image_id ) {
		update_post_meta( $post_id, '_listing_prv_img', $featured_image_id );
	}
	
	// Fix 2: Convert _listing_img from comma-separated string to array
	$listing_img = get_post_meta( $post_id, '_listing_img', true );
	
	if ( ! empty( $listing_img ) && is_string( $listing_img ) ) {
		// It's a comma-separated string, convert to array
		$gallery_ids = array_filter( array_map( 'absint', explode( ',', $listing_img ) ) );
		if ( ! empty( $gallery_ids ) ) {
			update_post_meta( $post_id, '_listing_img', $gallery_ids );
		}
	}
	
	// Fix 3: Copy services data to custom-textarea (Directorist's actual field key)
	$services = get_post_meta( $post_id, '_services', true );
	$custom_textarea = get_post_meta( $post_id, '_custom-textarea', true );
	if ( ! empty( $services ) && empty( $custom_textarea ) ) {
		update_post_meta( $post_id, '_custom-textarea', $services );
		update_post_meta( $post_id, 'custom-textarea', $services );
	}
	
	// Fix 4: Copy pricing data to custom-textarea-2 (Directorist's actual field key)
	$pricing = get_post_meta( $post_id, '_pricing', true );
	$custom_textarea_2 = get_post_meta( $post_id, '_custom-textarea-2', true );
	if ( ! empty( $pricing ) && empty( $custom_textarea_2 ) ) {
		update_post_meta( $post_id, '_custom-textarea-2', $pricing );
		update_post_meta( $post_id, 'custom-textarea-2', $pricing );
	}
	
	// Fix 5: Copy address data to custom-text if needed
	$address = get_post_meta( $post_id, '_address', true );
	$custom_text = get_post_meta( $post_id, '_custom-text', true );
	if ( ! empty( $address ) && empty( $custom_text ) ) {
		update_post_meta( $post_id, '_custom-text', $address );
		update_post_meta( $post_id, 'custom-text', $address );
	}
	
	// Fix 6: Ensure _custom-file is in plupload format (url|id|title|caption)
	$custom_file = get_post_meta( $post_id, '_custom-file', true );
	$featured_image_id = get_post_thumbnail_id( $post_id );
	
	// Check if custom_file needs to be converted to plupload format
	$needs_conversion = false;
	if ( ! empty( $custom_file ) ) {
		// If it's just a number (attachment ID) or a URL without |, convert it
		if ( is_numeric( $custom_file ) || ( is_string( $custom_file ) && strpos( $custom_file, '|' ) === false ) ) {
			$needs_conversion = true;
		}
	} elseif ( $featured_image_id ) {
		// No custom-file set, use featured image
		$needs_conversion = true;
	}
	
	if ( $needs_conversion && $featured_image_id ) {
		$logo_url = wp_get_attachment_image_url( $featured_image_id, 'full' );
		$logo_title = get_the_title( $featured_image_id );
		if ( $logo_url ) {
			// Format: url|id|title|caption (Directorist plupload format)
			$custom_file_value = $logo_url . '|' . $featured_image_id . '|' . $logo_title . '|';
			update_post_meta( $post_id, '_custom-file', $custom_file_value );
			update_post_meta( $post_id, 'custom-file', $custom_file_value );
			update_post_meta( $post_id, '_custom-file-url', $logo_url );
		}
	}
	
	// Fix 7: Build _social array from individual social fields if not set
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
	
	// Fix 8: If _listing_prv_img is still empty but we have gallery images, use first gallery image
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
// Run fix when viewing listing in admin - multiple hooks to ensure it runs
add_action( 'edit_form_top', function( $post ) {
	if ( $post && defined( 'ATBDP_POST_TYPE' ) && get_post_type( $post ) === ATBDP_POST_TYPE ) {
		hbl_fix_listing_data_format( $post->ID );
	}
}, 1 );

// Also run on admin_init when editing a listing
add_action( 'admin_init', function() {
	if ( isset( $_GET['post'] ) && isset( $_GET['action'] ) && $_GET['action'] === 'edit' ) {
		$post_id = absint( $_GET['post'] );
		if ( $post_id && defined( 'ATBDP_POST_TYPE' ) && get_post_type( $post_id ) === ATBDP_POST_TYPE ) {
			hbl_fix_listing_data_format( $post_id );
		}
	}
	
	// Handle manual fix request
	if ( isset( $_GET['hbl_fix_listing'] ) && isset( $_GET['post'] ) ) {
		$post_id = absint( $_GET['post'] );
		if ( $post_id && current_user_can( 'manage_options' ) ) {
			hbl_fix_listing_data_format( $post_id );
			wp_redirect( remove_query_arg( 'hbl_fix_listing' ) );
			exit;
		}
	}
}, 1 );

/**
 * Show admin notice with fix status for listings
 */
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
	
	// Check custom field mappings
	$services = get_post_meta( $post_id, '_services', true );
	$custom_textarea = get_post_meta( $post_id, '_custom-textarea', true );
	$pricing = get_post_meta( $post_id, '_pricing', true );
	$custom_textarea_2 = get_post_meta( $post_id, '_custom-textarea-2', true );
	$custom_file = get_post_meta( $post_id, '_custom-file', true );
	$social = get_post_meta( $post_id, '_social', true );
	
	// Check individual social fields
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
	
	// Check if custom-file is in plupload format (should contain |)
	if ( ! empty( $custom_file ) && is_string( $custom_file ) && strpos( $custom_file, '|' ) === false ) {
		$issues[] = '_custom-file not in plupload format (url|id|title|caption)';
	}
	
	// Check if custom-file is empty but featured image exists
	if ( empty( $custom_file ) && $featured_image_id ) {
		$issues[] = '_custom-file not set (Featured Image: ' . $featured_image_id . ')';
	}
	
	// Check if _social array is missing but individual fields exist
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


/**
 * Sync HBL listing data to Directorist field keys when admin loads listing
 * This ensures data saved via HBL form appears in Directorist admin
 */
function hbl_sync_listing_data_for_admin( $post_id ) {
	// Only run for at_biz_dir post type
	if ( ! defined( 'ATBDP_POST_TYPE' ) || get_post_type( $post_id ) !== ATBDP_POST_TYPE ) {
		return;
	}
	
	// Get the directory type to find the actual field keys
	$directory_id = 0;
	if ( function_exists( 'directorist_get_listing_directory' ) ) {
		$directory_id = directorist_get_listing_directory( $post_id );
	}
	if ( ! $directory_id ) {
		$directory_id = get_post_meta( $post_id, '_directory_type', true );
	}
	
	// Get form fields configuration
	$form_fields = array();
	if ( $directory_id && function_exists( 'directorist_get_listing_form_fields' ) ) {
		$form_fields = directorist_get_listing_form_fields( $directory_id );
	}
	
	// Map our saved data to Directorist's expected field keys
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
		
		// Check if this field matches any of our data types
		foreach ( $data_mapping as $data_type => $source_keys ) {
			// Check if field key or widget name contains our data type
			$field_lower = strtolower( $directorist_field_key );
			if ( strpos( $field_lower, $data_type ) !== false || 
			     ( $widget_name && strpos( strtolower( $widget_name ), $data_type ) !== false ) ) {
				
				// Try to get value from our source keys
				foreach ( $source_keys as $source_key ) {
					$value = get_post_meta( $post_id, $source_key, true );
					if ( ! empty( $value ) ) {
						// Save to Directorist's expected key (with underscore prefix)
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

/**
 * Sync data when admin metaboxes are loaded (before form renders)
 */
function hbl_sync_listing_data_for_admin_on_load( $post ) {
	if ( $post && isset( $post->ID ) ) {
		hbl_sync_listing_data_for_admin( $post->ID );
	}
}

/**
 * Filter to provide field values when Directorist renders form
 */
function hbl_filter_directorist_field_data( $field_data ) {
	global $post;
	
	if ( ! $post || ! isset( $post->ID ) ) {
		return $field_data;
	}
	
	// If value is already set, don't override
	if ( ! empty( $field_data['value'] ) ) {
		return $field_data;
	}
	
	$post_id = $post->ID;
	$field_key = isset( $field_data['field_key'] ) ? strtolower( $field_data['field_key'] ) : '';
	$widget_name = isset( $field_data['widget_name'] ) ? strtolower( $field_data['widget_name'] ) : '';
	
	// Map field types to our meta keys
	$fallback_sources = array();
	
	if ( strpos( $field_key, 'service' ) !== false || strpos( $widget_name, 'service' ) !== false ) {
		$fallback_sources = array( '_services', 'services', '_textarea_services', 'textarea_services' );
	} elseif ( strpos( $field_key, 'pricing' ) !== false || strpos( $widget_name, 'pricing' ) !== false ) {
		$fallback_sources = array( '_pricing', 'pricing', '_pricing_list', 'pricing_list', '_textarea_pricing', 'textarea_pricing' );
	} elseif ( strpos( $field_key, 'address' ) !== false || strpos( $widget_name, 'address' ) !== false ) {
		$fallback_sources = array( '_address', 'address', '_text_address', 'text_address' );
	}
	
	// Try to get value from fallback sources
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

/**
 * AJAX handler to get attachment URL
 * Used for pre-populating images in edit form
 */
function hbl_get_attachment_url() {
	check_ajax_referer( 'hbl_nonce', 'nonce' );
	
	$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
	$size = isset( $_POST['size'] ) ? sanitize_text_field( $_POST['size'] ) : 'medium';
	
	if ( ! $attachment_id ) {
		wp_send_json_error( array( 'message' => 'Invalid attachment ID' ) );
	}
	
	$url = wp_get_attachment_image_url( $attachment_id, $size );
	
	if ( ! $url ) {
		// Fallback to full size
		$url = wp_get_attachment_image_url( $attachment_id, 'full' );
	}
	
	if ( ! $url ) {
		wp_send_json_error( array( 'message' => 'Image not found' ) );
	}
	
	wp_send_json_success( array( 'url' => $url ) );
}
add_action( 'wp_ajax_hbl_get_attachment_url', 'hbl_get_attachment_url' );
add_action( 'wp_ajax_nopriv_hbl_get_attachment_url', 'hbl_get_attachment_url' );

/**
 * AJAX handler for checkout processing
 * Placeholder for PayPal/Stripe integration
 */
function hbl_process_checkout() {
	check_ajax_referer( 'hbl_checkout_nonce', 'checkout_nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in to complete checkout.', 'hbl' ) ) );
	}

	// Get form data
	$payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( $_POST['payment_method'] ) : '';
	$billing_name   = isset( $_POST['billing_name'] ) ? sanitize_text_field( $_POST['billing_name'] ) : '';
	$billing_email  = isset( $_POST['billing_email'] ) ? sanitize_email( $_POST['billing_email'] ) : '';
	$billing_phone  = isset( $_POST['billing_phone'] ) ? sanitize_text_field( $_POST['billing_phone'] ) : '';
	$order_id       = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$plan_id        = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;
	$listing_id     = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;

	// Validate required fields
	if ( empty( $billing_name ) || empty( $billing_email ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Billing name and email are required.', 'hbl' ) ) );
	}

	if ( empty( $payment_method ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Please select a payment method.', 'hbl' ) ) );
	}

	// Handle different payment methods
	switch ( $payment_method ) {
		case 'stripe':
			// Placeholder for Stripe integration
			// In production, this would process the payment via Stripe API
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
			// Placeholder for PayPal integration
			// In production, this would redirect to PayPal
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
			// Bank transfer - no immediate payment, mark as pending
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

/**
 * Human label for a plan's tax line (e.g. "GST (10%)"), built from
 * Directorist's own plan-level tax configuration (see HBL_Pricing_Plans::get_plan())
 * rather than a rate this theme assumes or hardcodes independently — so the
 * label always matches whatever was actually charged.
 *
 * @param string $tax_type 'percent' | 'flat' | ''.
 * @param float  $tax_rate Rate (percent) or amount (flat).
 * @return string
 */
function hbl_format_plan_tax_label( $tax_type, $tax_rate ) {
	if ( 'percent' === $tax_type && $tax_rate > 0 ) {
		$rate = rtrim( rtrim( number_format( (float) $tax_rate, 2 ), '0' ), '.' );
		return sprintf( __( 'GST (%s%%)', 'hbl' ), $rate );
	}
	return __( 'GST', 'hbl' );
}

/**
 * AJAX handler for creating Stripe Checkout Session
 */
function hbl_create_stripe_session() {
	// Verify nonce
	if ( ! isset( $_POST['checkout_nonce'] ) || ! wp_verify_nonce( $_POST['checkout_nonce'], 'hbl_checkout_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed.' ) );
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'You must be logged in.' ) );
	}

	$recaptcha_result = hbl_verify_recaptcha( isset( $_POST['g-recaptcha-response'] ) ? $_POST['g-recaptcha-response'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
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

	// Get plan details
	$plan = get_post( $plan_id );
	if ( ! $plan || $plan->post_type !== 'atbdp_pricing_plans' ) {
		wp_send_json_error( array( 'message' => 'Invalid pricing plan.' ) );
	}

	// Get plan price + tax via the theme's central pricing-plans provider — it
	// already resolves legacy vs 4.0+ plan storage correctly, so tax here always
	// matches what this plan actually charges in Directorist, not a fixed rate
	// this handler used to assume for every plan.
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

	// Get Stripe secret key
	$secret_key = hbl_get_stripe_secret_key();

	if ( empty( $secret_key ) ) {
		wp_send_json_error( array( 'message' => 'Stripe is not configured. Please configure your Stripe API keys in Directorist Settings → Monetization → Stripe.' ) );
	}

	// Create order record
	$order_id = wp_insert_post( array(
		'post_type'   => 'atbdp_orders',
		'post_status' => 'publish',
		'post_title'  => sprintf( 'Order #%s - %s', time(), $billing_name ),
		'post_author' => get_current_user_id(),
	) );

	if ( is_wp_error( $order_id ) ) {
		wp_send_json_error( array( 'message' => 'Failed to create order.' ) );
	}

	// Save order meta
	update_post_meta( $order_id, '_listing_id', $listing_id );
	update_post_meta( $order_id, '_fm_plan_ordered', $plan_id );
	update_post_meta( $order_id, '_payment_status', 'pending' );
	update_post_meta( $order_id, '_order_amount', $plan_price );
	update_post_meta( $order_id, '_billing_name', $billing_name );
	update_post_meta( $order_id, '_billing_email', $billing_email );

	// A paid plan checkout has begun (order is 'pending' until Stripe confirms).
	// Lets the CRM bridge record "started an upgrade" even if payment is never
	// completed. Payment completion is signalled separately via
	// hbl_listing_payment_verified.
	do_action( 'hbl_order_started', (int) $order_id, (int) $listing_id, (int) $plan_id );

	// Build success and cancel URLs
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

	// Currency - default to AUD for Australian site
	$currency = get_option( 'hbl_stripe_currency', 'AUD' );

	// Tax comes from Directorist's own plan-level configuration (flat or
	// percentage) via HBL_Pricing_Plans, instead of a fixed rate this handler
	// used to assume for every plan.
	$tax_amount     = $plan_data ? (float) $plan_data['tax_amount'] : 0.0;
	$tax_label      = $plan_data ? hbl_format_plan_tax_label( $plan_data['tax_type'], $plan_data['tax_rate'] ) : __( 'GST', 'hbl' );
	$total_with_tax = $plan_price + $tax_amount;

	// Save tax info to order
	update_post_meta( $order_id, '_order_subtotal', $plan_price );
	update_post_meta( $order_id, '_order_tax', $tax_amount );
	update_post_meta( $order_id, '_order_total', $total_with_tax );

	// Create Stripe Checkout Session with line items including tax
	$line_items = array(
		// Main product
		array(
			'price_data' => array(
				'currency'     => strtolower( $currency ),
				'unit_amount'  => intval( $plan_price * 100 ), // Stripe expects cents
				'product_data' => array(
					'name'        => $plan->post_title,
					'description' => get_post_meta( $plan_id, 'fm_description', true ) ?: 'Listing Package',
				),
			),
			'quantity' => 1,
		),
	);

	// Only add a tax line when this plan actually has tax configured in
	// Directorist — a zero-amount "GST" line on a tax-exempt plan would be
	// misleading on the Stripe checkout page.
	if ( $tax_amount > 0 ) {
		$line_items[] = array(
			'price_data' => array(
				'currency'     => strtolower( $currency ),
				'unit_amount'  => intval( $tax_amount * 100 ), // Tax in cents
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

	// Make API request to Stripe
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

	// Save Stripe session ID to order
	update_post_meta( $order_id, '_stripe_session_id', $body['id'] );

	wp_send_json_success( array(
		'checkout_url' => $body['url'],
		'session_id'   => $body['id'],
		'order_id'     => $order_id,
	) );
}
add_action( 'wp_ajax_hbl_create_stripe_session', 'hbl_create_stripe_session' );

/**
 * Build Stripe API body from nested array
 */
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

/**
 * Verify Stripe Payment and activate listing
 */
function hbl_verify_stripe_payment() {
	$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( $_GET['session_id'] ) : '';
	$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

	if ( ! $session_id || ! $order_id ) {
		return false;
	}

	// Check if already verified
	$payment_status = get_post_meta( $order_id, '_payment_status', true );
	if ( $payment_status === 'completed' ) {
		return true;
	}

	// Get Stripe secret key
	$secret_key = hbl_get_stripe_secret_key();

	if ( empty( $secret_key ) ) {
		return false;
	}

	// Retrieve session from Stripe
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

	// SECURITY: Bind the Stripe session to this specific order. The session_id and
	// order_id arrive as independent URL parameters, so without this check an
	// attacker could take any single "paid" session and replay it against other
	// (unpaid) orders — or reuse it across many orders — to get their listings
	// published without paying. client_reference_id and metadata.order_id are both
	// set to the order ID at session creation (see hbl_create_stripe_session), so a
	// legitimate session for this order must match here.
	$session_ref   = isset( $body['client_reference_id'] ) ? (string) $body['client_reference_id'] : '';
	$session_meta  = isset( $body['metadata']['order_id'] ) ? (string) $body['metadata']['order_id'] : '';
	$expected_ref  = (string) $order_id;

	if ( $session_ref !== $expected_ref && $session_meta !== $expected_ref ) {
		return false;
	}

	// SECURITY: Confirm the amount actually collected matches the order total, so a
	// session for a cheaper plan cannot be used to activate a more expensive one.
	$expected_total = (float) get_post_meta( $order_id, '_order_total', true );
	if ( $expected_total > 0 && isset( $body['amount_total'] ) ) {
		$paid_total = (float) $body['amount_total'] / 100; // Stripe reports minor units (cents).
		// Allow a 1-cent tolerance for rounding between our math and Stripe's.
		if ( abs( $paid_total - $expected_total ) > 0.01 ) {
			return false;
		}
	}

	if ( isset( $body['payment_status'] ) && $body['payment_status'] === 'paid' ) {
		// Update order status
		update_post_meta( $order_id, '_payment_status', 'completed' );
		update_post_meta( $order_id, '_stripe_payment_intent', $body['payment_intent'] ?? '' );
		
		// Get listing ID and activate it
		$listing_id = get_post_meta( $order_id, '_listing_id', true );
		$plan_id = get_post_meta( $order_id, '_fm_plan_ordered', true );
		
		if ( $listing_id ) {
			// Determine listing status based on plan tier - ROBUST CHECK
			$plan_tier = hbl_get_plan_tier( $plan_id );
			$plan_price = 0;
			
			// Get plan price for additional verification
			if ( $plan_id ) {
				if ( function_exists( 'atpp_total_price' ) ) {
					$plan_price = floatval( atpp_total_price( $plan_id ) );
				} else {
					$plan_price = floatval( get_post_meta( $plan_id, 'fm_price', true ) );
				}
			}
			
			// ROBUST: If it's a PAID listing (price > 0) and NOT bronze tier, auto-publish
			// This ensures any paid tier (silver/gold) gets auto-approved
			if ( $plan_price > 0 && $plan_tier !== 'bronze' ) {
				// Paid Silver/Gold plans auto-approve after payment
				$new_status = 'publish';
			} elseif ( $plan_tier === 'bronze' || $plan_price <= 0 ) {
				// Bronze plans or free plans remain pending for approval
				$new_status = 'pending';
			} else {
				// Fallback: Publish paid listings by default
				$new_status = 'publish';
			}
			
			// Update listing status - FORCE UPDATE with direct DB query for reliability
			global $wpdb;
			$wpdb->update(
				$wpdb->posts,
				array( 'post_status' => $new_status ),
				array( 'ID' => $listing_id ),
				array( '%s' ),
				array( '%d' )
			);
			
			// Also use wp_update_post for cache clearing
			wp_update_post( array(
				'ID'          => $listing_id,
				'post_status' => $new_status,
			) );
			
			// Clean post cache to ensure status update is reflected
			clean_post_cache( $listing_id );
			
			// Update listing with plan
			update_post_meta( $listing_id, '_fm_plans', $plan_id );
			update_post_meta( $listing_id, '_listing_order_id', $order_id );
			
			// Store auto-approval metadata for tracking
			update_post_meta( $listing_id, '_payment_verified', current_time( 'mysql' ) );
			update_post_meta( $listing_id, '_payment_plan_tier', $plan_tier );
			update_post_meta( $listing_id, '_payment_auto_status', $new_status );
			
			// Trigger Directorist hooks
			do_action( 'atbdp_order_completed', $order_id, $listing_id );
			do_action( 'hbl_listing_payment_verified', $listing_id, $plan_id, $plan_tier, $new_status );
		}
		
		return true;
	}

	return false;
}


/**
 * AJAX handler for applying Directorist coupons in custom checkout
 */
function hbl_apply_directorist_coupon() {
	// Verify nonce
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

	// Try to validate coupon using Directorist methods
	$coupon_valid = false;
	$discount_amount = 0;
	$discount_type = '';
	$coupon_message = '';

	// Method 1: Check if Directorist has coupon validation functions
	if ( function_exists( 'atbdp_validate_coupon' ) ) {
		$validation_result = atbdp_validate_coupon( $coupon_code, $plan_id );
		if ( $validation_result && isset( $validation_result['valid'] ) && $validation_result['valid'] ) {
			$coupon_valid = true;
			$discount_amount = $validation_result['discount_amount'] ?? 0;
			$discount_type = $validation_result['discount_type'] ?? 'fixed';
			$coupon_message = $validation_result['message'] ?? 'Coupon applied successfully!';
		}
	}

	// Method 2: Debug and check for coupon posts with multiple approaches
	if ( ! $coupon_valid ) {
	// First, let's debug what post types exist
	$debug_info = array();
	
	// Get ALL registered post types to see what's available
	$all_post_types = get_post_types( array(), 'names' );
	$coupon_related_types = array();
	
	foreach ( $all_post_types as $post_type ) {
		if ( strpos( strtolower( $post_type ), 'coupon' ) !== false ) {
			$coupon_related_types[] = $post_type;
		}
	}
	
	$debug_info[] = "All coupon-related post types: " . implode( ', ', $coupon_related_types );
	
	// Check common coupon post types (including the one we found: swbdp-coupon)
	$possible_post_types = array( 'swbdp-coupon', 'atbdp_coupon', 'directorist_coupon', 'coupon', 'atbdp_coupons' );
	$existing_post_types = array();
	
	foreach ( $possible_post_types as $post_type ) {
		if ( post_type_exists( $post_type ) ) {
			$existing_post_types[] = $post_type;
			$count = wp_count_posts( $post_type );
			$debug_info[] = "$post_type: {$count->publish} published";
			
			// If this is swbdp-coupon, let's examine the structure
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
					break; // Just show one sample
				}
			}
		}
	}
	
	// Check if Directorist Coupon extension is actually active
	$debug_info[] = "Active plugins check:";
	$active_plugins = get_option( 'active_plugins' );
	$coupon_plugins = array_filter( $active_plugins, function( $plugin ) {
		return strpos( strtolower( $plugin ), 'coupon' ) !== false;
	});
	$debug_info[] = "Coupon plugins: " . implode( ', ', $coupon_plugins );
	
	// Check for Directorist-specific functions
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
	
	// Check for Directorist classes
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
	
	// Check for custom database tables
	global $wpdb;
	$tables = $wpdb->get_results( "SHOW TABLES LIKE '%coupon%'", ARRAY_N );
	$coupon_tables = array();
	foreach ( $tables as $table ) {
		$coupon_tables[] = $table[0];
	}
	$debug_info[] = "Coupon-related database tables: " . implode( ', ', $coupon_tables );
	
	// If we found coupon tables, let's check their structure
	if ( ! empty( $coupon_tables ) ) {
		foreach ( $coupon_tables as $table ) {
			$columns = $wpdb->get_results( "DESCRIBE $table", ARRAY_A );
			$column_names = array_column( $columns, 'Field' );
			$debug_info[] = "Table $table columns: " . implode( ', ', $column_names );
			
			// Try to find a coupon with the provided code
			$code_columns = array( 'code', 'coupon_code', 'discount_code', 'name', 'title' );
			foreach ( $code_columns as $col ) {
				if ( in_array( $col, $column_names ) ) {
					$result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE $col = %s LIMIT 1", $coupon_code ) );
					if ( $result ) {
						$debug_info[] = "Found coupon in table $table, column $col";
						
						// Try to extract discount info
						$coupon_valid = true;
						$discount_amount = 0;
						$discount_type = 'fixed';
						
						// Look for amount columns
						$amount_columns = array( 'amount', 'discount_amount', 'value', 'discount_value' );
						foreach ( $amount_columns as $amt_col ) {
							if ( isset( $result->$amt_col ) ) {
								$discount_amount = floatval( $result->$amt_col );
								break;
							}
						}
						
						// Look for type columns
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
						
						break 2; // Break out of both loops
					}
				}
			}
		}
	}
		
		// Try different meta key variations (including the correct swbdpc_ prefix)
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
					
					// Get all meta for debugging
					$all_meta = get_post_meta( $coupon_post->ID );
					
					// Check if coupon is still valid (using correct meta keys)
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
					
					// Try different meta keys for discount amount and type (using correct swbdpc_ prefix)
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
					
					break 2; // Break out of both loops
				}
			}
		}
		
		// Method 3: Try searching by post title as fallback
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
					
					// Simple validation for title-based search
					$coupon_valid = true;
					$discount_amount = 10; // Default discount if we can't find meta
					$discount_type = 'fixed';
					$coupon_message = sprintf( 'Coupon "%s" applied!', $coupon_code );
					break;
				}
			}
		}
		
		// If still not found, send debug info to admin users
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

	// Store coupon in session for checkout process
	if ( ! session_id() ) {
		session_start();
	}
	
	$_SESSION['hbl_applied_coupon'] = array(
		'code' => $coupon_code,
		'discount_amount' => $discount_amount,
		'discount_type' => $discount_type,
		'applied_at' => current_time( 'timestamp' )
	);
	
	// Store discount info for checkout widget
	$_SESSION['hbl_coupon_discount'] = $discount_amount;
	$_SESSION['hbl_coupon_type'] = $discount_type;
	
	// Calculate new total for response (get current plan price + tax)
	$plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;
	$subtotal = 99.00; // Default fallback
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

	// Calculate discount amount
	$actual_discount = $discount_type === 'percentage' ? ( $subtotal * $discount_amount ) / 100 : $discount_amount;
	$actual_discount = min( $actual_discount, $subtotal ); // Don't exceed subtotal

	// Calculate new total (subtotal + tax - discount) — tax comes from
	// Directorist's own plan-level configuration, not a fixed rate.
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

/**
 * Verify Google reCAPTCHA v2 token server-side.
 *
 * Returns true on success, or an error message string on failure.
 * Skips verification gracefully when no secret key is configured.
 *
 * @param string $token The g-recaptcha-response token from the form.
 * @return true|string
 */
function hbl_verify_recaptcha( $token ) {
	$secret_key = get_option( 'elementor_pro_recaptcha_secret_key', '' );

	// No keys configured — skip (graceful degradation)
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

/**
 * AJAX handler for login
 */
function hbl_ajax_login() {
	check_ajax_referer( 'ajax-login-nonce', 'security' );

	$recaptcha_result = hbl_verify_recaptcha( isset( $_POST['g-recaptcha-response'] ) ? $_POST['g-recaptcha-response'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( true !== $recaptcha_result ) {
		wp_send_json_error( array( 'message' => $recaptcha_result ) );
	}

	$username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
	$password = isset( $_POST['password'] ) ? $_POST['password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
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

/**
 * AJAX handler for registration
 */
function hbl_ajax_register() {
	check_ajax_referer( 'ajax-register-nonce', 'security' );

	$recaptcha_result = hbl_verify_recaptcha( isset( $_POST['g-recaptcha-response'] ) ? $_POST['g-recaptcha-response'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( true !== $recaptcha_result ) {
		wp_send_json_error( array( 'message' => $recaptcha_result ) );
	}

	$username = isset( $_POST['user_login'] ) ? sanitize_user( wp_unslash( $_POST['user_login'] ) ) : '';
	$email = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
	$password = isset( $_POST['user_pass'] ) ? $_POST['user_pass'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash

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

	// Auto-login the user
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id );

	wp_send_json_success( array(
		'message'  => esc_html__( 'Registration successful!', 'hbl' ),
		'redirect' => home_url(),
	) );
}
add_action( 'wp_ajax_hbl_ajax_register', 'hbl_ajax_register' );
add_action( 'wp_ajax_nopriv_hbl_ajax_register', 'hbl_ajax_register' );

/**
 * AJAX handler for submitting reviews - saves to Directorist review system
 */
function hbl_submit_review() {
	// Verify nonce
	if ( ! isset( $_POST['hbl_review_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hbl_review_nonce'] ) ), 'hbl_submit_review' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'hbl' ) ) );
	}
	
	// Get and validate form data
	$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	$rating = isset( $_POST['review_rating'] ) ? absint( $_POST['review_rating'] ) : 0;
	$reviewer_name = isset( $_POST['reviewer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['reviewer_name'] ) ) : '';
	$reviewer_email = isset( $_POST['reviewer_email'] ) ? sanitize_email( wp_unslash( $_POST['reviewer_email'] ) ) : '';
	$review_content = isset( $_POST['review_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['review_content'] ) ) : '';
	
	// Validate required fields
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
	
	// Check if reviews are enabled
	if ( function_exists( 'directorist_is_review_enabled' ) && ! directorist_is_review_enabled() ) {
		wp_send_json_error( array( 'message' => __( 'Reviews are currently disabled.', 'hbl' ) ) );
	}
	
	// Check if user has already reviewed (if not allowing multiple reviews)
	if ( function_exists( 'directorist_user_review_exists' ) && directorist_user_review_exists( $reviewer_email, $listing_id ) ) {
		if ( ! apply_filters( 'directorist_is_multiple_review_enabled', false ) ) {
			wp_send_json_error( array( 'message' => __( 'You have already submitted a review for this listing.', 'hbl' ) ) );
		}
	}
	
	// Determine approval status
	$approve_immediately = function_exists( 'directorist_is_immediate_review_approve_enabled' ) && directorist_is_immediate_review_approve_enabled();
	$comment_approved = $approve_immediately ? 1 : 0;
	
	// Prepare comment data for Directorist review
	$comment_data = array(
		'comment_post_ID'      => $listing_id,
		'comment_author'       => $reviewer_name,
		'comment_author_email' => $reviewer_email,
		'comment_content'      => $review_content,
		'comment_type'         => 'review', // This is how Directorist identifies reviews
		'comment_approved'     => $comment_approved,
		'comment_parent'       => 0,
	);
	
	// Add user ID if logged in
	if ( is_user_logged_in() ) {
		$comment_data['user_id'] = get_current_user_id();
	}
	
	// Insert the comment/review
	$comment_id = wp_insert_comment( $comment_data );
	
	if ( ! $comment_id ) {
		wp_send_json_error( array( 'message' => __( 'Failed to submit review. Please try again.', 'hbl' ) ) );
	}
	
	// Save rating as comment meta (Directorist uses 'rating' meta key)
	add_comment_meta( $comment_id, 'rating', $rating, true );
	
	// Clear Directorist review transients and recalculate average rating
	if ( class_exists( 'Directorist\Review\Comment' ) ) {
		\Directorist\Review\Comment::clear_transients( $listing_id );
	}
	
	// Trigger Directorist action for review submission
	do_action( 'directorist_review_submitted', $comment_id, $comment_data );
	
	// Return success
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

/**
 * Search listings for claim form
 */
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

/**
 * Get listing title by ID for claim form
 */
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

/**
 * Get Stripe secret key based on current mode
 * Reads from Directorist settings first, then Elementor Pro as fallback
 */
function hbl_get_stripe_secret_key() {
	// First check Directorist Stripe settings (stored in atbdp_option)
	$atbdp_options = get_option( 'atbdp_option', array() );
	
	// Directorist Stripe Gateway extension uses 'stripe_gateway_test_mode' for the toggle
	$stripe_gateway_test_mode = isset( $atbdp_options['stripe_gateway_test_mode'] ) ? $atbdp_options['stripe_gateway_test_mode'] : '';
	$gateway_test_mode = isset( $atbdp_options['gateway_test_mode'] ) ? $atbdp_options['gateway_test_mode'] : '';
	
	// Check if test mode is enabled (can be 1, '1', true, 'yes', 'on')
	$is_test_mode = ! empty( $stripe_gateway_test_mode ) || ! empty( $gateway_test_mode );
	
	if ( $is_test_mode ) {
		// Directorist Stripe extension uses 'stripe_test_sk' for test secret key
		if ( ! empty( $atbdp_options['stripe_test_sk'] ) ) {
			return $atbdp_options['stripe_test_sk'];
		}
		// Fallback to other possible key names
		if ( ! empty( $atbdp_options['stripe_test_secret_key'] ) ) {
			return $atbdp_options['stripe_test_secret_key'];
		}
	} else {
		// Directorist Stripe extension uses 'stripe_live_sk' for live secret key
		if ( ! empty( $atbdp_options['stripe_live_sk'] ) ) {
			return $atbdp_options['stripe_live_sk'];
		}
		// Fallback to other possible key names
		if ( ! empty( $atbdp_options['stripe_live_secret_key'] ) ) {
			return $atbdp_options['stripe_live_secret_key'];
		}
	}
	
	// Fallback to Elementor Pro settings
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

/**
 * Check if Stripe is in test mode
 */
function hbl_is_stripe_test_mode() {
	// First check Directorist settings
	$atbdp_options = get_option( 'atbdp_option', array() );
	
	// Directorist Stripe Gateway extension uses 'stripe_gateway_test_mode'
	$stripe_gateway_test_mode = isset( $atbdp_options['stripe_gateway_test_mode'] ) ? $atbdp_options['stripe_gateway_test_mode'] : '';
	$gateway_test_mode = isset( $atbdp_options['gateway_test_mode'] ) ? $atbdp_options['gateway_test_mode'] : '';
	
	if ( ! empty( $stripe_gateway_test_mode ) || ! empty( $gateway_test_mode ) ) {
		return true;
	}
	
	// Check if we're using test keys
	$secret_key = hbl_get_stripe_secret_key();
	return strpos( $secret_key, 'sk_test_' ) === 0;
}

/**
 * Admin notice to show Stripe configuration status (for debugging)
 */
add_action( 'admin_notices', function() {
	// Only show on Directorist settings pages
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

/**
 * Get plan tier for a given plan ID
 * 
 * @param int $plan_id The plan ID
 * @return string Plan tier: 'gold', 'silver', or 'bronze'
 */
function hbl_get_plan_tier( $plan_id ) {
	if ( empty( $plan_id ) || $plan_id <= 0 ) {
		return 'bronze';
	}

	// Resolve the plan through the HBL_Pricing_Plans abstraction so this works
	// on both Directorist Pricing Plans 4.0+ (custom table) and legacy installs.
	$plan = class_exists( 'HBL_Pricing_Plans' ) ? \HBL_Pricing_Plans::get_plan( $plan_id ) : null;
	if ( ! $plan ) {
		return 'bronze';
	}

	// Try to get tier mappings from theme options or default widget settings
	// This is a fallback method since the tier mapping is usually in Elementor widget settings
	$plan_name = strtolower( $plan['title'] );

	// Check plan name for tier keywords
	if ( strpos( $plan_name, 'gold' ) !== false || strpos( $plan_name, 'premium' ) !== false || strpos( $plan_name, 'pro' ) !== false ) {
		return 'gold';
	}

	if ( strpos( $plan_name, 'silver' ) !== false || strpos( $plan_name, 'standard' ) !== false || strpos( $plan_name, 'plus' ) !== false ) {
		return 'silver';
	}

	// Explicit Bronze/entry-tier names. Without this, a plan literally named
	// "Bronze" has no keyword match and falls through to the price check below,
	// which wrongly promotes it to gold/silver when the plan is priced >= $50.
	if ( strpos( $plan_name, 'bronze' ) !== false || strpos( $plan_name, 'basic' ) !== false || strpos( $plan_name, 'starter' ) !== false || strpos( $plan_name, 'free' ) !== false ) {
		return 'bronze';
	}

	// Check plan price as another indicator
	$plan_price = floatval( $plan['price'] );

	// Price-based tier determination (adjust these thresholds as needed)
	if ( $plan_price >= 100 ) {
		return 'gold';
	} elseif ( $plan_price >= 50 ) {
		return 'silver';
	}

	// Default to bronze for free plans or low-cost plans
	return 'bronze';
}

/**
 * AJAX handler for HBL Claim Listing form submission
 * Handles package selection and checkout redirect like add listing form
 */
function hbl_submit_claim() {
	// Verify nonce
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'hbl_claim_nonce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'hbl' ) ) );
	}
	
	// Check if user is logged in
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'You must be logged in to claim a listing.', 'hbl' ) ) );
	}

	$recaptcha_result = hbl_verify_recaptcha( isset( $_POST['g-recaptcha-response'] ) ? $_POST['g-recaptcha-response'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( true !== $recaptcha_result ) {
		wp_send_json_error( array( 'message' => $recaptcha_result ) );
	}

	$user_id = get_current_user_id();
	$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
	$plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;
	$claimer_name = isset( $_POST['claimer_name'] ) ? sanitize_text_field( $_POST['claimer_name'] ) : '';
	$claimer_phone = isset( $_POST['claimer_phone'] ) ? sanitize_text_field( $_POST['claimer_phone'] ) : '';
	$claimer_details = isset( $_POST['claimer_details'] ) ? sanitize_textarea_field( $_POST['claimer_details'] ) : '';
	
	// Validate listing ID
	if ( ! $listing_id || get_post_type( $listing_id ) !== 'at_biz_dir' ) {
		wp_send_json_error( array( 'message' => __( 'Please select a valid business to claim.', 'hbl' ) ) );
	}
	
	// Check if user already has a pending claim for this listing
	if ( function_exists( 'dcl_tract_duplicate_claim' ) ) {
		$already_claimed = dcl_tract_duplicate_claim( $user_id, $listing_id );
		if ( ! empty( $already_claimed ) ) {
			wp_send_json_error( array( 'message' => __( 'You have already submitted a claim for this listing.', 'hbl' ) ) );
		}
	}
	
	// Update user type to author
	update_user_meta( $user_id, '_user_type', 'author' );
	
	// Store claimer information on the listing
	update_post_meta( $listing_id, '_claimer_name', $claimer_name );
	update_post_meta( $listing_id, '_claimer_phone', $claimer_phone );
	update_post_meta( $listing_id, '_claimer_details', $claimer_details );
	
	// Store the plan ID for claims
	if ( $plan_id ) {
		update_post_meta( $listing_id, '_claimer_plans', $plan_id );
	}
	
	// Create the claim post
	if ( function_exists( 'dcl_new_claim' ) ) {
		dcl_new_claim( $listing_id );
	} else {
		// Fallback: create claim post manually
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
	
	// Send admin notification
	if ( function_exists( 'dcl_email_admin_listing_claim' ) ) {
		dcl_email_admin_listing_claim();
	}
	
	// Get dashboard URL
	$dashboard_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_dashboard_page_link() : home_url( '/dashboard/' );
	
	// Check if payment is required based on plan
	$requires_payment = false;
	$checkout_url = '';
	$plan_tier = 'bronze';
	
	if ( $plan_id > 0 ) {
		// Get plan price
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
		
		// If plan has a price, redirect to checkout
		if ( $plan_price > 0 ) {
			$requires_payment = true;
			
			// Build checkout URL with claim parameters
			$checkout_url = add_query_arg( array(
				'listing_id' => $listing_id,
				'plan_id'    => $plan_id,
				'claimed'    => 'true',
			), home_url( '/checkout/' ) );
		}
	}
	
	// Determine response message based on plan tier
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

/**
 * Auto-approve Silver/Gold claim listings after payment completion
 * 
 * @param int $order_id Order ID
 * @param int $listing_id Listing ID
 */
function hbl_auto_approve_claim_after_payment( $order_id, $listing_id ) {
	// Check if this is a claim order
	$is_claim_order = get_post_meta( $order_id, '_claimed', true );
	$claimer_plans = get_post_meta( $listing_id, '_claimer_plans', true );
	
	// If not a claim order and no claimer plans, skip
	if ( ! $is_claim_order && empty( $claimer_plans ) ) {
		return;
	}
	
	// Get plan ID from order meta, claim plan meta, or listing claimer plans
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
	
	// Get plan tier
	$plan_tier = hbl_get_plan_tier( $plan_id );
	
	// Only auto-approve Silver and Gold packages (Bronze requires manual review)
	if ( $plan_tier === 'silver' || $plan_tier === 'gold' ) {
		// Find the claim post
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
			
			// Only process if claim is still pending
			if ( $current_status === 'pending' ) {
				$claimer_id = get_post_meta( $claim_id, '_listing_claimer', true );
				
				if ( $claimer_id ) {
					// Update claim status to approved
					update_post_meta( $claim_id, '_claim_status', 'approved' );
					
					// Transfer ownership to claimer
					global $wpdb;
					$wpdb->update(
						$wpdb->posts,
						array( 'post_author' => $claimer_id ),
						array( 'ID' => $listing_id ),
						array( '%d' ),
						array( '%d' )
					);
					
					// Move plan from claimer_plans to fm_plans
					if ( $claimer_plans ) {
						update_post_meta( $listing_id, '_fm_plans', $claimer_plans );
						update_post_meta( $listing_id, '_claimer_plans', 0 );
					}
					
					// Mark as claimed
					update_post_meta( $listing_id, '_claimed_by_admin', 1 );
					update_post_meta( $listing_id, '_claim_fee', 'claim_approved' );
					
					// Trigger claim approval actions (if hook exists)
					if ( has_action( 'atbdp_claim_approved' ) ) {
						do_action( 'atbdp_claim_approved', $claim_id, $listing_id );
					}
					
					// Send email notification using Directorist claim listing functions
					if ( function_exists( 'dcl_email_claimer_claim_approved' ) ) {
						dcl_email_claimer_claim_approved( $claim_id );
					}
				}
			}
		}
	}
}
add_action( 'atbdp_order_completed', 'hbl_auto_approve_claim_after_payment', 20, 2 );

/**
 * Store claim flag in order when claim is submitted with payment
 */
function hbl_mark_order_as_claim( $order_id, $listing_id ) {
	// Check if listing has claimer plans (indicating it's a claim)
	$claimer_plans = get_post_meta( $listing_id, '_claimer_plans', true );
	$claimed_by_admin = get_post_meta( $listing_id, '_claimed_by_admin', true );
	
	// Also check if there's a claim post for this listing
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
		// Store the plan ID if available
		if ( $claimer_plans ) {
			update_post_meta( $order_id, '_claim_plan_id', $claimer_plans );
		}
	}
}
add_action( 'atbdp_order_created', 'hbl_mark_order_as_claim', 10, 2 );

/**
 * Auto-approve Silver/Gold new listings after payment completion
 * 
 * This handles NEW listings (not claims) - Bronze stays pending, Silver/Gold auto-publish
 * 
 * @param int $order_id Order ID
 * @param int $listing_id Listing ID
 */
function hbl_auto_approve_listing_after_payment( $order_id, $listing_id ) {
	// Skip if this is a claim order (handled by hbl_auto_approve_claim_after_payment)
	$is_claim_order = get_post_meta( $order_id, '_claimed', true );
	$claimer_plans = get_post_meta( $listing_id, '_claimer_plans', true );
	
	if ( $is_claim_order || ! empty( $claimer_plans ) ) {
		return; // Let the claim handler deal with this
	}
	
	// Refresh listing data from database (avoid cache issues)
	clean_post_cache( $listing_id );
	$listing = get_post( $listing_id );
	
	if ( ! $listing || $listing->post_type !== 'at_biz_dir' ) {
		return;
	}
	
	// Get plan ID from order or listing
	$plan_id = get_post_meta( $order_id, '_fm_plan_ordered', true );
	if ( ! $plan_id ) {
		$plan_id = get_post_meta( $listing_id, '_fm_plans', true );
	}
	
	if ( ! $plan_id ) {
		return;
	}
	
	// Get plan tier and price for ROBUST verification
	$plan_tier = hbl_get_plan_tier( $plan_id );
	$plan_price = 0;
	
	if ( function_exists( 'atpp_total_price' ) ) {
		$plan_price = floatval( atpp_total_price( $plan_id ) );
	} else {
		$plan_price = floatval( get_post_meta( $plan_id, 'fm_price', true ) );
	}
	
	// ROBUST: Auto-approve if:
	// 1. Plan has a price (paid listing) AND
	// 2. Plan is Silver or Gold tier (not Bronze)
	$should_auto_approve = ( $plan_price > 0 && ( $plan_tier === 'silver' || $plan_tier === 'gold' ) );
	
	// Additional check: If listing is still pending but should be published, force publish
	if ( $should_auto_approve ) {
		// Force publish using direct DB update for reliability
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_status' => 'publish' ),
			array( 'ID' => $listing_id ),
			array( '%s' ),
			array( '%d' )
		);
		
		// Also use wp_update_post for proper cache clearing and hooks
		wp_update_post( array(
			'ID'          => $listing_id,
			'post_status' => 'publish',
		) );
		
		// Clear all caches
		clean_post_cache( $listing_id );
		
		// Log the auto-approval
		update_post_meta( $listing_id, '_auto_approved', current_time( 'mysql' ) );
		update_post_meta( $listing_id, '_auto_approved_plan_tier', $plan_tier );
		update_post_meta( $listing_id, '_auto_approved_via', 'order_completed_hook' );
		
		// Trigger any hooks for listing approval
		do_action( 'hbl_listing_auto_approved', $listing_id, $plan_id, $plan_tier );
		
		// Send notification to listing author
		hbl_send_listing_approved_email( $listing_id );
	}
	// Bronze tier listings remain pending - admin will review manually
}
add_action( 'atbdp_order_completed', 'hbl_auto_approve_listing_after_payment', 15, 2 ); // Priority 15 - before claim handler (20)

/**
 * Send email notification when listing is auto-approved
 * 
 * @param int $listing_id Listing ID
 */
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

/**
 * Delayed safety check to ensure paid listings are published
 * This runs after page load via shutdown hook to catch any edge cases
 */
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
	
	// Refresh from database
	clean_post_cache( $listing_id );
	$listing = get_post( $listing_id );
	
	if ( ! $listing || $listing->post_type !== 'at_biz_dir' ) {
		return;
	}
	
	// Get plan tier
	$plan_id = get_post_meta( $order_id, '_fm_plan_ordered', true );
	if ( ! $plan_id ) {
		$plan_id = get_post_meta( $listing_id, '_fm_plans', true );
	}
	
	$plan_tier = hbl_get_plan_tier( $plan_id );
	
	// If listing should be published but isn't, fix it now
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

/**
 * Fix 404 on Event Category Pagination (Archives)
 * 
 * Problem: Events are stored in a custom table, so the main WordPress query for 
 * 'event_category' returns 0 posts. This causes WordPress to issue a 404 when 
 * trying to access paginated URLs (e.g. /page/2/), because it calculates 
 * total pages = 0.
 * 
 * Solution: We intervene in the main query to:
 * 1. (pre_get_posts) Remove the taxonomy query so WP finds *some* standard posts 
 *    (preventing the "0 results" issue immediately).
 * 2. (found_posts) Override the total count with the actual count from our 
 *    custom HBL Events DB.
 * 
 * This treats the main query as a "virtual" query that perfectly matches the 
 * pagination structure of our actual event data.
 */

// 1. Modify the Query Arguments
function hbl_event_category_pre_get_posts( $query ) {
	// Only target the main query on event_category archives
    if ( ! is_admin() && $query->is_main_query() && $query->is_tax( 'event_category' ) ) {
        // Remove the tax_query so we just find *any* published posts.
        // This ensures the database query returns results (assuming the site has blog posts).
        $query->set( 'tax_query', array() );
        
        // Optimize: we don't need the actual data, just the existence/count.
        $query->set( 'posts_per_page', 1 );
        
        // Ensure we are querying standard posts (or any type that exists)
        $query->set( 'post_type', 'post' );
    }
}
add_action( 'pre_get_posts', 'hbl_event_category_pre_get_posts' );

// 2. Override the Total Count
function hbl_event_category_found_posts( $found_posts, $query ) {
    if ( ! is_admin() && $query->is_main_query() && $query->is_tax( 'event_category' ) ) {
        $term = get_queried_object();
        
        if ( $term && function_exists( 'hbl_events_db' ) ) {
            // Get the REAL count from our custom database
            $db = hbl_events_db();
            $count = $db->count_events( array(
                'category_id' => $term->term_id,
                'status'      => 'publish',
            ) );
            
            // If we have custom events, return THAT count.
            // This tricks WP into calculating the correct number of pages.
            // e.g. 50 events / 12 per page = 5 pages.
            // WP will now accept /page/2/ through /page/5/.
            if ( $count > 0 ) {
                return $count;
            }
        }
    }
    return $found_posts;
}
add_filter( 'found_posts', 'hbl_event_category_found_posts', 10, 2 );

// 3. Ensure posts_per_page matches our Widget settings to sync pagination math
// The widget uses 'posts_per_page' setting (default 12).
// We should ideally sync this. For now, we'll set the main query to 12 as default.
// If the widget has a different setting, the pagination numbers might drift, 
// but at least it won't 404.
function hbl_event_category_posts_per_page( $query ) {
    if ( ! is_admin() && $query->is_main_query() && $query->is_tax( 'event_category' ) ) {
        // Default to 12 to match typical widget default
        $query->set( 'posts_per_page', 12 );
    }
}
// Hooking this into pre_get_posts above is cleaner
add_action( 'pre_get_posts', 'hbl_event_category_posts_per_page', 11 );


/**
 * AJAX Handler for HBL Events Widget
 * Allows dynamic filtering and pagination without page reloads
 */
function hbl_ajax_get_events() {
    check_ajax_referer( 'hbl_nonce', 'nonce' );
    
    // Parse arguments
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
        
        // Recurrence Logic Helper (Self-contained for AJAX)
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

        // Process Logic
        $processed_events = array();
        $now = current_time( 'timestamp' );
        $today_start = strtotime( 'today', $now );

        foreach ( $raw_events as $event ) {
            $next_date = $get_next_occurrence( $event );
            // Apply strict upcoming filter logic if needed? 
            // The widget does this:
            // if ( $next_ts < $today_start ) continue; (if show_upcoming_only is YES)
            // But here we are always assuming 'yes' for this list type likely, 
            // or we should fetch the 'show_upcoming_only' setting from $_POST if available.
            // Let's assume we want valid upcoming events.
            
            $event_item = clone $event;
            $event_item->computed_start_date = $next_date;
            $processed_events[] = $event_item;
        }

        // Sort
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
        
        // Render
        ob_start();
        if ( ! empty( $events ) ) {
            ?>
            <div class="hbl-events-grid hbl-view-<?php echo esc_attr( $view ); ?>">
                <?php foreach ( $events as $event ) : 
                    $event_url = hbl_events_db()->get_event_url( $event );
                    $thumbnail_url = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'medium_large' ) : '';
                    $start_date = $event->computed_start_date; // Use computed date
                ?>
                <div class="hbl-event-card">
                    <a href="<?php echo esc_url( $event_url ); ?>" class="hbl-event-image-link">
                         <?php if ( $thumbnail_url ) : ?>
                            <div class="hbl-event-image" style="background-image: url('<?php echo esc_url( $thumbnail_url ); ?>');">
                         <?php else : ?>
                            <div class="hbl-event-image hbl-event-no-image" style="background-color: <?php echo $event->event_color ?: '#008080'; ?>;">
                         <?php endif; ?>
                            <!-- Date Badge -->
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

/**
 * Filter the total number of pages for pagination to work
 * This helps functions like paginate_links() or get_the_posts_pagination() 
 * inside standard templates, though our widget handles its own pagination.
 * The main issue is simply stopping the 404 redirect.
 */

/**
 * Load and initialize theme class
 */
require HBL_THEME_PATH . '/theme.php';

/**
 * Fix WP 6.9.1+ Elementor Script Dependencies Notice
 */
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
		// Just register them as blanks so WP doesn't issue a Notice warning when elementor-v2-editor-components tries to enqueue them.
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, false, [], false, true );
		}
	}
}
add_action( 'elementor/editor/before_enqueue_scripts', 'hbl_fix_elementor_v2_notices', 9 );
add_action( 'admin_enqueue_scripts', 'hbl_fix_elementor_v2_notices', 9 );
add_action( 'wp_enqueue_scripts', 'hbl_fix_elementor_v2_notices', 9 );

HBLTheme\Theme::instance();