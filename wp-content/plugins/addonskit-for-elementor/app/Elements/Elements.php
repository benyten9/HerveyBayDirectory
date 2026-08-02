<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements;

use AddonskitForElementor\Utils\Helper;
use AddonskitForElementor\Utils\Hookable;
use AddonskitForElementor\Utils\Singleton;
use Directorist\Asset_Loader\Asset_Loader;
use Directorist\Asset_Loader\Localized_Data;
use Directorist\Directorist_Template_Hooks;
use Elementor\Plugin;
use ReflectionClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Elements {

	public static $plugin_dir;

	use Singleton;
	use Hookable;

	public function __construct() {
		// Add action hooks and initialize properties.
		$this->action( 'elementor/editor/after_enqueue_styles', 'editor_style' );
		$this->action( 'elementor/elements/categories_registered', 'register_category' );
		add_action( 'elementor/widgets/register', [$this, 'register_widgets'], 20 );
		add_action( 'elementor/dynamic_tags/register', [$this, 'register_new_dynamic_tags'], 10, 1 );

		add_filter( 'directorist_custom_single_listing_pre_page_content', [$this, 'single_listing_elementor_support'], 10, 2 );

		self::$plugin_dir = dirname(  ( new ReflectionClass( $this ) )->getFileName() );

	}

	public function single_listing_elementor_support( $content, $page ) {
		if ( did_action( 'elementor/loaded' ) && \Elementor\Plugin::instance()->documents->get( $page->ID )->is_built_with_elementor() ) {
			return \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $page->ID );
		}
	
		return $content;
	}

	/**
	 * Register new dynamic tags for Directorist.
	 *
	 * @param object $dynamic_tags The dynamic tags object.
	 */
	public function register_new_dynamic_tags( $dynamic_tags ) {

		if ( is_plugin_inactive( 'elementor-pro/elementor-pro.php' ) ) {
			return;
		}

		$dynamic_tags->register_group(
			'directorist',
			[
				'title' => esc_html__( 'Directorist', 'addonskit-for-elementor' ),
			]
		);

		$get_tag_classes_names = [
			'Fields',
			'Address',
			'FieldsUrl',
			'SocialFields',
			'FieldColor',
		];

		// Register individual dynamic tags classes.
		foreach ( $get_tag_classes_names as $tag_class ) {
			$class = 'AddonskitForElementor\\Elements\\SingleListingFields\\DynamicTags\\' . $tag_class;
			if ( class_exists( $class ) ) {
				$dynamic_tags->register( new $class() );
			}
		}
	}

	/**
	 * Register widgets for Elementor.
	 */
	public function register_widgets() {
		$widgets_manager = Plugin::instance()->widgets_manager;

		// Registers Directorist Elements
		$this->general_elements( $widgets_manager );
		$this->directorist_elements( $widgets_manager );

		if ( is_plugin_active( 'elementor-pro/elementor-pro.php' ) ) {
			$this->register_single_listing_elements( $widgets_manager );
		}
	}

	private function directorist_elements( $widgets_manager ) {
		Asset_Loader::register_scripts();
		Localized_Data::load_localized_data();

		$directorist_widget_classes = [
			'AddListing',
			'AllAuthors',
			'AllCategories',
			'AllListings',
			'AllLocations',
			'AuthorProfile',
			'Checkout',
			'PaymentReceipt',
			'SearchListing',
			'SearchResult',
			'SingleCategory',
			'SingleLocation',
			'SingleTag',
			'TransactionFailure',
			'UserDashboard',
			'UserLogin',
		];

		// Register Directorist widgets.
		foreach ( $directorist_widget_classes as $widget_class ) {
			$class = 'AddonskitForElementor\\Elements\\' . $widget_class . '\\' . $widget_class;
			if ( class_exists( $class ) ) {
				$widgets_manager->register( new $class() );
			}
		}
	}

	private function general_elements( $widgets_manager ) {
		$directorist_widget_classes = [
			'Post',
			'Team',
		];

		// Register Directorist widgets.
		foreach ( $directorist_widget_classes as $widget_class ) {
			$class = 'AddonskitForElementor\\Elements\\' . $widget_class . '\\' . $widget_class;
			if ( class_exists( $class ) ) {
				$widgets_manager->register( new $class() );
			}
		}
	}

	private function register_single_listing_elements( $widgets_manager ) {

		$directorist_widget_classes = [
			'EditAction',
			'Action',
			'Info',
			'Images',
			'AuthorInfo',
			'SocialInfo',
			'ContactListingsOwnerForm',
			'Map',
			'Review',
			'RelatedListings',
		];

		foreach ( $directorist_widget_classes as $widget_class ) {
			$class = 'AddonskitForElementor\\Elements\\SingleListingFields\\' . $widget_class . '\\' . $widget_class;
			if ( class_exists( $class ) ) {
				$widgets_manager->register( new $class() );
			}
		}
	}

	public function register_category( $elements_manager ) {
		$elements_manager->add_category(
			'directorist-widgets',
			[
				'title' => esc_html__( 'Directorist', 'addonskit-for-elementor' ),
			]
		);
	}

	public function editor_style() {
		$img = esc_url(DIRECTORIST_ASSETS . 'images/elementor-icon.png');
		wp_add_inline_style( 'elementor-editor', '.elementor-control-type-select2 .elementor-control-input-wrapper {min-width: 130px;}.elementor-element .icon .directorist-el-custom{content: url(' . esc_url($img) . ');width: 22px;}' );
	}

	public static function wpwax_template( $template, $data ) {
		$template_name = '/elementor-support/' . esc_attr(basename( self::$plugin_dir )) . '/' . esc_attr($template) . '.php';

		if ( file_exists( WP_DEFAULT_THEME . $template_name ) ) {
			$file = WP_DEFAULT_THEME . $template_name;
		} else {
			$file = self::$plugin_dir . '/' . esc_attr($template) . '.php';
		}

		ob_start();
		include $file;
		echo wp_kses_post(ob_get_clean());
	}
}