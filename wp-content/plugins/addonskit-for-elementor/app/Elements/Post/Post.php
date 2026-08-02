<?php
/**
 * @author  wpWax
 * @since   1.0
 * @version 1.0
 */

 namespace AddonskitForElementor\Elements\Post;

use AddonskitForElementor\Elements\Elements;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post extends Widget_Base {

	public function get_name() {
        return 'akfe_posts';
    }

    public function get_title() {
        return __( 'Posts', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['general'];
    }

    public function get_keywords() {
        return [
            'blog', 'post',
        ];
    }

	private function wpwax_query( $data ) {
		$args = array(
			'cat'                 => (int) $data['cat'],
			'orderby'             => $data['orderby'],
			'posts_per_page'      => $data['number_of_post'],
			'post_status'         => 'publish',
			'suppress_filters'    => false,
			'ignore_sticky_posts' => true,
		);

		switch ( $data['orderby'] ) {
			case 'title':
			case 'menu_order':
				$args['order'] = 'ASC';
				break;
		}

		return new \WP_Query( $args );
	}

	protected function register_controls(): void {
		$this->register_contents();
		//$this->register_styles();
	}

	protected function register_contents(): void {

		$categories        = get_categories();
		$category_dropdown = array( '0' => __( 'All Categories', 'addonskit-for-elementor' ) );

		foreach ( $categories as $category ) {
			$category_dropdown[$category->term_id] = $category->name;
		}

		$this->start_controls_section(
			'sec_general',
			[
				'label' => __( 'Posts', 'addonskit-for-elementor' ),
			]
		);

		$this->add_control(
			'number_of_post',
			[
				'label'     => __( 'Number of Posts', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::NUMBER,
				'default' => 3,
			]
		);

		$this->add_control(
			'number_of_columns',
			[
				'label'   => __( 'Columns', 'addonskit-for-elementor' ),
				'type'    => Controls_Manager::SELECT2,
				'options' => array(
					'2' => __( '6 Columns', 'addonskit-for-elementor' ),
					'3' => __( '4 Columns', 'addonskit-for-elementor' ),
					'4' => __( '3 Columns', 'addonskit-for-elementor' ),
					'6' => __( '2 Columns', 'addonskit-for-elementor' ),
				),
				'default' => 4,
			]
		);

		$this->add_control(
			'cat',
			[
				'type'    => Controls_Manager::SELECT2,
				'label'   => __( 'Categories', 'addonskit-for-elementor' ),
				'options' => $category_dropdown,
				'default' => '0',
			]
		);

		$this->add_control(
			'orderby',
			[
				'type'    => Controls_Manager::SELECT2,
				'label'   => __( 'Order By', 'addonskit-for-elementor' ),
				'options' => array(
					'date'       => __( 'Date (Recents comes first)', 'addonskit-for-elementor' ),
					'title'      => __( 'Title', 'addonskit-for-elementor' ),
					'menu_order' => __( 'Custom Order (Available via Order field inside Page Attributes box)', 'addonskit-for-elementor' ),
				),
				'default' => 'date',
			]
		);

		$this->add_control(
			'show_expert',
			[
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label'     => __( 'Show Excerpt', 'addonskit-for-elementor' ),
				'label_on'  => __( 'Show', 'addonskit-for-elementor' ),
				'label_off' => __( 'Hide', 'addonskit-for-elementor' ),
				'default'   => false,
			]
		);

		$this->add_control(
			'show_date',
			[
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label'     => __( 'Show Date', 'addonskit-for-elementor' ),
				'label_on'  => __( 'Show', 'addonskit-for-elementor' ),
				'label_off' => __( 'Hide', 'addonskit-for-elementor' ),
				'default'   => true,
			]
		);

		$this->add_control(
			'show_reading_time',
			[
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label'     => __( 'Show Reading Time', 'addonskit-for-elementor' ),
				'label_on'  => __( 'Show', 'addonskit-for-elementor' ),
				'label_off' => __( 'Hide', 'addonskit-for-elementor' ),
				'default'   => true,
			]
		);

		$this->add_control(
			'show_category',
			[
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label'     => __( 'Show Categories', 'addonskit-for-elementor' ),
				'label_on'  => __( 'Show', 'addonskit-for-elementor' ),
				'label_off' => __( 'Hide', 'addonskit-for-elementor' ),
				'default'   => false,
			]
		);

		$this->end_controls_section();
	}

	protected function render() {
		$data = $this->get_settings();

		$data['query'] = $this->wpwax_query( $data );

		$template = 'Post/view';

		return Elements::wpwax_template( $template, $data );
	}
}