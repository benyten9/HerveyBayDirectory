<?php
/**
 * HBL Blogs Section Widget
 *
 * Displays blog posts from WordPress in a responsive grid layout
 * Matches Figma design specifications for Hervey Bay Directory
 *
 * @package HBL
 * @since 1.2.362
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class HBL_Blogs_Section extends Widget_Base {

	/**
	 * Get widget name
	 */
	public function get_name() {
		return 'hbl-blogs-section';
	}

	/**
	 * Get widget title
	 */
	public function get_title() {
		return esc_html__( 'HB Blogs', 'hbl' );
	}

	/**
	 * Get widget icon
	 */
	public function get_icon() {
		return 'eicon-posts-grid';
	}

	/**
	 * Get widget categories
	 */
	public function get_categories() {
		return array( 'hbl' );
	}

	/**
	 * Register widget controls
	 */
	protected function register_controls() {
		
		// ========== CONTENT: HEADER SECTION ==========
		$this->start_controls_section(
			'section_header',
			array(
				'label' => esc_html__( 'Header', 'hbl' ),
			)
		);

		$this->add_control(
			'subtitle',
			array(
				'label'       => esc_html__( 'Subtitle', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Latest News', 'hbl' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => esc_html__( 'Title', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'HB Blogs', 'hbl' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'       => esc_html__( 'Button Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'View All News', 'hbl' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'button_link',
			array(
				'label'       => esc_html__( 'Button Link', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'https://your-link.com', 'hbl' ),
				'default'     => array(
					'url' => get_permalink( get_option( 'page_for_posts' ) ),
				),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: BLOG SETTINGS ==========
		$this->start_controls_section(
			'section_blog_settings',
			array(
				'label' => esc_html__( 'Blog Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'posts_count',
			array(
				'label'   => esc_html__( 'Number of Posts', 'hbl' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 3,
				'min'     => 1,
				'max'     => 10,
			)
		);

		$this->add_control(
			'order_by',
			array(
				'label'   => esc_html__( 'Order By', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'date',
				'options' => array(
					'date'          => esc_html__( 'Date', 'hbl' ),
					'title'         => esc_html__( 'Title', 'hbl' ),
					'modified'      => esc_html__( 'Modified', 'hbl' ),
					'comment_count' => esc_html__( 'Comment Count', 'hbl' ),
					'rand'          => esc_html__( 'Random', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'order',
			array(
				'label'   => esc_html__( 'Order', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'DESC',
				'options' => array(
					'ASC'  => esc_html__( 'Ascending', 'hbl' ),
					'DESC' => esc_html__( 'Descending', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'category',
			array(
				'label'    => esc_html__( 'Category', 'hbl' ),
				'type'     => Controls_Manager::SELECT2,
				'multiple' => true,
				'options'  => $this->get_post_categories(),
				'default'  => array(),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: SECTION ==========
		$this->start_controls_section(
			'section_style',
			array(
				'label' => esc_html__( 'Section', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'section_padding',
			array(
				'label'      => esc_html__( 'Padding', 'hbl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => array(
					'top'      => '50',
					'right'    => '0',
					'bottom'   => '50',
					'left'     => '0',
					'unit'     => 'px',
					'isLinked' => false,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-blogs-section' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'section_gap',
			array(
				'label'      => esc_html__( 'Gap Between Header and Content', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 150,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 51,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-blogs-section' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: HEADER ==========
		$this->start_controls_section(
			'section_header_style',
			array(
				'label' => esc_html__( 'Header', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'subtitle_heading',
			array(
				'label' => esc_html__( 'Subtitle', 'hbl' ),
				'type'  => Controls_Manager::HEADING,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'subtitle_typography',
				'label'          => esc_html__( 'Typography', 'hbl' ),
				'selector'       => '{{WRAPPER}} .hbl-blogs-subtitle',
				'fields_options' => array(
					'typography'  => array( 'default' => 'custom' ),
					'font_family' => array( 'default' => 'Poppins' ),
					'font_weight' => array( 'default' => '400' ),
					'font_size'   => array(
						'default' => array(
							'unit' => 'px',
							'size' => 24,
						),
					),
					'line_height' => array(
						'default' => array(
							'unit' => 'em',
							'size' => 1.5,
						),
					),
				),
			)
		);

		$this->add_control(
			'subtitle_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blogs-subtitle' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'title_heading',
			array(
				'label'     => esc_html__( 'Title', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'title_typography',
				'label'          => esc_html__( 'Typography', 'hbl' ),
				'selector'       => '{{WRAPPER}} .hbl-blogs-title',
				'fields_options' => array(
					'typography'  => array( 'default' => 'custom' ),
					'font_family' => array( 'default' => 'Poppins' ),
					'font_weight' => array( 'default' => '600' ),
					'font_size'   => array(
						'default' => array(
							'unit' => 'px',
							'size' => 48,
						),
					),
					'line_height' => array(
						'default' => array(
							'unit' => 'em',
							'size' => 1.5,
						),
					),
				),
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blogs-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_heading',
			array(
				'label'     => esc_html__( 'Button', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'button_typography',
				'label'          => esc_html__( 'Typography', 'hbl' ),
				'selector'       => '{{WRAPPER}} .hbl-blogs-button',
				'fields_options' => array(
					'typography'  => array( 'default' => 'custom' ),
					'font_family' => array( 'default' => 'Poppins' ),
					'font_weight' => array( 'default' => '600' ),
					'font_size'   => array(
						'default' => array(
							'unit' => 'px',
							'size' => 17,
						),
					),
					'line_height' => array(
						'default' => array(
							'unit' => 'em',
							'size' => 1.5,
						),
					),
				),
			)
		);

		$this->add_control(
			'button_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EEEEEE',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blogs-button' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blogs-button' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_hover_color',
			array(
				'label'     => esc_html__( 'Hover Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-blogs-button:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_hover_bg_color',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-blogs-button:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: BLOG CARDS ==========
		$this->start_controls_section(
			'section_cards_style',
			array(
				'label' => esc_html__( 'Blog Cards', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'card_border_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 5,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-blog-card' => 'border-radius: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .hbl-blog-image' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'date_heading',
			array(
				'label'     => esc_html__( 'Date', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'date_typography',
				'label'          => esc_html__( 'Typography', 'hbl' ),
				'selector'       => '{{WRAPPER}} .hbl-blog-date',
				'fields_options' => array(
					'typography'  => array( 'default' => 'custom' ),
					'font_family' => array( 'default' => 'Poppins' ),
					'font_weight' => array( 'default' => '400' ),
					'font_size'   => array(
						'default' => array(
							'unit' => 'px',
							'size' => 17.28,
						),
					),
					'line_height' => array(
						'default' => array(
							'unit' => 'em',
							'size' => 1.7,
						),
					),
				),
			)
		);

		$this->add_control(
			'featured_date_color',
			array(
				'label'     => esc_html__( 'Featured Blog Date Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EEEEEE',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blog-featured .hbl-blog-date' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'standard_date_color',
			array(
				'label'     => esc_html__( 'Standard Blog Date Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blog-standard .hbl-blog-date' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'card_title_heading',
			array(
				'label'     => esc_html__( 'Card Title', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'card_title_typography',
				'label'          => esc_html__( 'Typography', 'hbl' ),
				'selector'       => '{{WRAPPER}} .hbl-blog-card-title',
				'fields_options' => array(
					'typography'  => array( 'default' => 'custom' ),
					'font_family' => array( 'default' => 'Poppins' ),
					'font_weight' => array( 'default' => '700' ),
					'font_size'   => array(
						'default' => array(
							'unit' => 'px',
							'size' => 32,
						),
					),
					'line_height' => array(
						'default' => array(
							'unit' => 'em',
							'size' => 1.3,
						),
					),
				),
			)
		);

		$this->add_control(
			'featured_title_color',
			array(
				'label'     => esc_html__( 'Featured Blog Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EEEEEE',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blog-featured .hbl-blog-card-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'standard_title_color',
			array(
				'label'     => esc_html__( 'Standard Blog Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blog-standard .hbl-blog-card-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'read_more_heading',
			array(
				'label'     => esc_html__( 'Read More Button', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'read_more_typography',
				'label'          => esc_html__( 'Typography', 'hbl' ),
				'selector'       => '{{WRAPPER}} .hbl-blog-read-more',
				'fields_options' => array(
					'typography'  => array( 'default' => 'custom' ),
					'font_family' => array( 'default' => 'Poppins' ),
					'font_weight' => array( 'default' => '600' ),
					'font_size'   => array(
						'default' => array(
							'unit' => 'px',
							'size' => 17,
						),
					),
					'line_height' => array(
						'default' => array(
							'unit' => 'em',
							'size' => 1.5,
						),
					),
				),
			)
		);

		$this->add_control(
			'featured_read_more_color',
			array(
				'label'     => esc_html__( 'Featured Blog Read More Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EEEEEE',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blog-featured .hbl-blog-read-more' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'featured_read_more_hover_color',
			array(
				'label'     => esc_html__( 'Featured Blog Read More Hover Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blog-featured .hbl-blog-read-more:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'featured_arrow_color',
			array(
				'label'     => esc_html__( 'Featured Blog Arrow Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blog-featured .hbl-blog-read-more svg path' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'featured_arrow_hover_color',
			array(
				'label'     => esc_html__( 'Featured Blog Arrow Hover Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-blog-featured .hbl-blog-read-more:hover svg path' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'standard_read_more_color',
			array(
				'label'     => esc_html__( 'Standard Blog Read More Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blog-standard .hbl-blog-read-more' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'standard_read_more_hover_color',
			array(
				'label'     => esc_html__( 'Standard Blog Read More Hover Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blog-standard .hbl-blog-read-more:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'standard_arrow_color',
			array(
				'label'     => esc_html__( 'Standard Blog Arrow Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-blog-standard .hbl-blog-read-more svg path' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'standard_arrow_hover_color',
			array(
				'label'     => esc_html__( 'Standard Blog Arrow Hover Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-blog-standard .hbl-blog-read-more:hover svg path' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'read_more_arrow_size',
			array(
				'label'      => esc_html__( 'Arrow Icon Size', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 5,
						'max' => 30,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 8,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-blog-read-more svg' => 'width: {{SIZE}}{{UNIT}}; height: auto;',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Get categories for dropdown
	 */
	private function get_post_categories() {
		$categories = get_categories();
		$options    = array();
		
		foreach ( $categories as $category ) {
			$options[ $category->term_id ] = $category->name;
		}
		
		return $options;
	}

	/**
	 * Render widget output on the frontend
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		
		// Query args
		$args = array(
			'post_type'      => 'post',
			'posts_per_page' => $settings['posts_count'],
			'orderby'        => $settings['order_by'],
			'order'          => $settings['order'],
			'post_status'    => 'publish',
		);
		
		// Add category filter if set
		if ( ! empty( $settings['category'] ) ) {
			$args['cat'] = implode( ',', $settings['category'] );
		}
		
		$query = new \WP_Query( $args );
		
		if ( ! $query->have_posts() ) {
			return;
		}
		?>
		<div class="hbl-blogs-section">
			<!-- Header -->
			<div class="hbl-blogs-header">
				<div class="hbl-blogs-header-text">
					<?php if ( ! empty( $settings['subtitle'] ) ) : ?>
						<p class="hbl-blogs-subtitle"><?php echo esc_html( $settings['subtitle'] ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $settings['title'] ) ) : ?>
						<h2 class="hbl-blogs-title"><?php echo esc_html( $settings['title'] ); ?></h2>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $settings['button_text'] ) ) : 
					$button_link = 'link_button';
					if ( ! empty( $settings['button_link']['url'] ) ) {
						$this->add_link_attributes( $button_link, $settings['button_link'] );
					}
				?>
					<div class="hbl-blogs-header-button">
						<a <?php echo $this->get_render_attribute_string( $button_link ); ?> class="hbl-blogs-button">
							<?php echo esc_html( $settings['button_text'] ); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>

			<!-- Blog Grid -->
			<div class="hbl-blogs-grid">
				<?php
				$posts_array = array();
				while ( $query->have_posts() ) : $query->the_post();
					$posts_array[] = array(
						'id'    => get_the_ID(),
						'link'  => get_permalink(),
						'title' => get_the_title(),
						'date'  => get_the_date( 'F j, Y' ),
						'image' => get_the_post_thumbnail_url( get_the_ID(), 'large' ),
					);
				endwhile;
				wp_reset_postdata();
				
				// Featured Blog (First Post)
				if ( ! empty( $posts_array[0] ) ) :
					$featured = $posts_array[0];
					?>
					<div class="hbl-blog-card hbl-blog-featured" <?php if ( $featured['image'] ) : ?>style="background-image: url('<?php echo esc_url( $featured['image'] ); ?>');"<?php endif; ?>>
						<div class="hbl-blog-content">
							<div class="hbl-blog-text">
								<p class="hbl-blog-date"><?php echo esc_html( $featured['date'] ); ?></p>
								<h3 class="hbl-blog-card-title"><?php echo esc_html( $featured['title'] ); ?></h3>
							</div>
							<a href="<?php echo esc_url( $featured['link'] ); ?>" class="hbl-blog-read-more">
								<?php echo esc_html__( 'Read More', 'hbl' ); ?>
								<svg width="8" height="11" viewBox="0 0 8 11" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M1.5 1.5L6 5.5L1.5 9.5" stroke="#F9532A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
							</a>
						</div>
					</div>
				<?php endif; ?>

				<!-- Right Column: Standard Blogs -->
				<div class="hbl-blogs-right-column">
					<?php
					// Show remaining posts (2nd and 3rd)
					for ( $i = 1; $i < count( $posts_array ); $i++ ) :
						$post_data = $posts_array[ $i ];
						?>
						<div class="hbl-blog-card hbl-blog-standard">
							<?php if ( $post_data['image'] ) : ?>
								<div class="hbl-blog-image" style="background-image: url('<?php echo esc_url( $post_data['image'] ); ?>');"></div>
							<?php endif; ?>
							<div class="hbl-blog-content">
								<div class="hbl-blog-text">
									<p class="hbl-blog-date"><?php echo esc_html( $post_data['date'] ); ?></p>
									<h3 class="hbl-blog-card-title"><?php echo esc_html( $post_data['title'] ); ?></h3>
								</div>
								<a href="<?php echo esc_url( $post_data['link'] ); ?>" class="hbl-blog-read-more">
									<?php echo esc_html__( 'Read More', 'hbl' ); ?>
									<svg width="8" height="11" viewBox="0 0 8 11" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M1.5 1.5L6 5.5L1.5 9.5" stroke="#F9532A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</a>
							</div>
						</div>
					<?php endfor; ?>
				</div>
			</div>

			<!-- Mobile/Tablet Button (Hidden on Desktop) -->
			<?php if ( ! empty( $settings['button_text'] ) ) : 
				$mobile_button_link = 'mobile_link_button';
				if ( ! empty( $settings['button_link']['url'] ) ) {
					$this->add_link_attributes( $mobile_button_link, $settings['button_link'] );
				}
			?>
				<div class="hbl-blogs-mobile-button">
					<a <?php echo $this->get_render_attribute_string( $mobile_button_link ); ?> class="hbl-blogs-button">
						<?php echo esc_html( $settings['button_text'] ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

}


