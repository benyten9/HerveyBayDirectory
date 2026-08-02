<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Breadcrumbs Widget.
 *
 * @package SEOPress PRO
 * @subpackage Elementor
 */

namespace SEOPressElementorBreadcrumbs\Widgets;

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

/**
 * Class SEOPress_Breadcrumbs_Widget
 *
 * Main SEOPress Breadcrumbs Widget class
 */
class SEOPress_Breadcrumbs_Widget extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve SEOPress Breadcrumbs widget name.
	 *
	 * @access public
	 *
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'sp-breadcrumbs';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve SEOPress Breadcrumbs widget title.
	 *
	 * @access public
	 *
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'Breadcrumbs', 'wp-seopress-pro' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve SEOPress Breadcrumbs widget icon.
	 *
	 * @access public
	 *
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'dashicons dashicons-feedback';
	}

	/**
	 * Get widget categories.
	 *
	 * Retrieve the list of categories the SEOPress Breadcrumbs widget belongs to.
	 *
	 * @access public
	 *
	 * @return array Widget categories.
	 */
	public function get_categories() {
		return array( 'theme-elements' );
	}

	/**
	 * Register SEOPress Breadcrumbs widget controls.
	 *
	 * Adds different input fields to allow the user to change and customize the widget settings.
	 *
	 * @access protected
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Content', 'wp-seopress-pro' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_responsive_control(
			'alignment',
			array(
				'label'     => __( 'Alignment', 'wp-seopress-pro' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array(
						'title' => __( 'Left', 'wp-seopress-pro' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => __( 'Center', 'wp-seopress-pro' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => __( 'Right', 'wp-seopress-pro' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'default'   => 'left',
				'selectors' => array(
					'{{WRAPPER}} .breadcrumb' => 'text-align: {{VALUE}}',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style_section',
			array(
				'label' => __( 'Style', 'wp-seopress-pro' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .breadcrumb',
			)
		);

		$this->add_control(
			'text_color',
			array(
				'label'     => __( 'Text Color', 'wp-seopress-pro' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .breadcrumb' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'link_color',
			array(
				'label'     => __( 'Link Color', 'wp-seopress-pro' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .breadcrumb a' => 'color: {{VALUE}}',
				),
			)
		);

		$this->add_control(
			'link_hover_color',
			array(
				'label'     => __( 'Link hover Color', 'wp-seopress-pro' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .breadcrumb a:hover' => 'color: {{VALUE}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render SEOPress Breadcrumbs widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @access protected
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		if ( function_exists( 'seopress_display_breadcrumbs' ) ) {
			seopress_display_breadcrumbs( true );
		}
	}

	/**
	 * Render the widget output in the editor.
	 *
	 * Written as a Backbone JavaScript template and used to generate the live preview.
	 *
	 * @access protected
	 */
	protected function _content_template() {
		if ( function_exists( 'seopress_display_breadcrumbs' ) ) {
			seopress_display_breadcrumbs( true );
		}
	}
}
