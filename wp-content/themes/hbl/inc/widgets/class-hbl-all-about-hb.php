<?php

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Repeater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_All_About_HB extends Widget_Base {

	public function get_name() {
		return 'hbl-all-about-hb';
	}

	public function get_title() {
		return esc_html__( 'All About HB', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-posts-grid';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'hbl', 'all about', 'hervey bay', 'community', 'categories', 'grid' );
	}

	protected function register_controls() {

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
				'default'     => esc_html__( 'Community Information', 'hbl' ),
				'placeholder' => esc_html__( 'Enter subtitle', 'hbl' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => esc_html__( 'Title', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'All About Hervey Bay Locals', 'hbl' ),
				'placeholder' => esc_html__( 'Enter title', 'hbl' ),
				'label_block' => true,
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_cards',
			array(
				'label' => esc_html__( 'Category Cards', 'hbl' ),
			)
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'image',
			array(
				'label'   => esc_html__( 'Image', 'hbl' ),
				'type'    => Controls_Manager::MEDIA,
				'default' => array(
					'url' => \Elementor\Utils::get_placeholder_image_src(),
				),
			)
		);

		$repeater->add_control(
			'icon',
			array(
				'label'       => esc_html__( 'Icon', 'hbl' ),
				'type'        => Controls_Manager::ICONS,
				'default'     => array(
					'value'   => 'fas fa-users',
					'library' => 'fa-solid',
				),
			)
		);

		$repeater->add_control(
			'title',
			array(
				'label'       => esc_html__( 'Title', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Category', 'hbl' ),
				'label_block' => true,
			)
		);

		$repeater->add_control(
			'link',
			array(
				'label'       => esc_html__( 'Link', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'https://your-link.com', 'hbl' ),
				'show_external' => true,
				'default'     => array(
					'url'         => '',
					'is_external' => false,
					'nofollow'    => false,
				),
			)
		);

		$this->add_control(
			'cards',
			array(
				'label'       => esc_html__( 'Cards', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array(
						'title' => esc_html__( 'Community', 'hbl' ),
					),
					array(
						'title' => esc_html__( 'Events', 'hbl' ),
					),
					array(
						'title' => esc_html__( 'Family', 'hbl' ),
					),
					array(
						'title' => esc_html__( 'Lifestyle', 'hbl' ),
					),
					array(
						'title' => esc_html__( 'People', 'hbl' ),
					),
					array(
						'title' => esc_html__( 'Places', 'hbl' ),
					),
				),
				'title_field' => '{{{ title }}}',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_header',
			array(
				'label' => esc_html__( 'Header', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'subtitle_typography',
				'selector' => '{{WRAPPER}} .hbl-all-about-hb-subtitle',
			)
		);

		$this->add_control(
			'subtitle_color',
			array(
				'label'     => esc_html__( 'Subtitle Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-all-about-hb-subtitle' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .hbl-all-about-hb-title',
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-all-about-hb-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'header_gap',
			array(
				'label'      => esc_html__( 'Gap Between Subtitle and Title', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 14,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-all-about-hb-header' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_container',
			array(
				'label' => esc_html__( 'Container', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'container_gap',
			array(
				'label'      => esc_html__( 'Gap Between Header and Grid', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 200,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 50,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-all-about-hb-wrapper' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_grid',
			array(
				'label' => esc_html__( 'Grid', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'grid_gap',
			array(
				'label'      => esc_html__( 'Gap Between Cards', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 30,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-all-about-hb-grid' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_card',
			array(
				'label' => esc_html__( 'Card', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'card_width',
			array(
				'label'      => esc_html__( 'Card Width', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 200,
						'max' => 600,
					),
					'%' => array(
						'min' => 20,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 460,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-all-about-hb-card' => 'width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'card_gap',
			array(
				'label'      => esc_html__( 'Gap Between Image and Content', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 20,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-all-about-hb-card' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'card_border_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
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
					'{{WRAPPER}} .hbl-all-about-hb-card-image' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_image',
			array(
				'label' => esc_html__( 'Image', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'image_height',
			array(
				'label'      => esc_html__( 'Image Height', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 100,
						'max' => 600,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 297,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-all-about-hb-card-image' => 'height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'zoom_effect',
			array(
				'label'        => esc_html__( 'Zoom on Hover', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'zoom_scale',
			array(
				'label'      => esc_html__( 'Zoom Scale', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 1,
						'max'  => 2,
						'step' => 0.1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 1.1,
				),
				'condition'  => array(
					'zoom_effect' => 'yes',
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-all-about-hb-card:hover .hbl-all-about-hb-card-image img' => 'transform: scale({{SIZE}});',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_content',
			array(
				'label' => esc_html__( 'Content', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'content_gap',
			array(
				'label'      => esc_html__( 'Gap Between Icon and Title', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 25,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-all-about-hb-card-content' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'icon_heading',
			array(
				'label'     => esc_html__( 'Icon', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'icon_size',
			array(
				'label'      => esc_html__( 'Icon Size', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 10,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 45,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-all-about-hb-card-icon' => 'font-size: {{SIZE}}{{UNIT}}; width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .hbl-all-about-hb-card-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'icon_color',
			array(
				'label'     => esc_html__( 'Icon Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-all-about-hb-card-icon' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-all-about-hb-card-icon svg' => 'fill: {{VALUE}};',
					'{{WRAPPER}} .hbl-all-about-hb-card-icon svg path' => 'stroke: {{VALUE}}; fill: {{VALUE}};',
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
				'name'     => 'card_title_typography',
				'selector' => '{{WRAPPER}} .hbl-all-about-hb-card-title',
			)
		);

		$this->add_control(
			'card_title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-all-about-hb-card-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		if ( empty( $settings['cards'] ) ) {
			return;
		}

		$subtitle = isset( $settings['subtitle'] ) ? $settings['subtitle'] : '';
		$title = isset( $settings['title'] ) ? $settings['title'] : '';
		$cards = $settings['cards'];
		?>
		<div class="hbl-all-about-hb-wrapper">
			<?php if ( ! empty( $subtitle ) || ! empty( $title ) ) : ?>
				<div class="hbl-all-about-hb-header">
					<?php if ( ! empty( $subtitle ) ) : ?>
						<h3 class="hbl-all-about-hb-subtitle"><?php echo esc_html( $subtitle ); ?></h3>
					<?php endif; ?>
					<?php if ( ! empty( $title ) ) : ?>
						<h2 class="hbl-all-about-hb-title"><?php echo esc_html( $title ); ?></h2>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="hbl-all-about-hb-grid">
				<?php foreach ( $cards as $card ) : 
					$image = isset( $card['image'] ) ? $card['image'] : array();
					$image_url = isset( $image['url'] ) ? $image['url'] : '';
					$icon = isset( $card['icon'] ) ? $card['icon'] : array();
					$card_title = isset( $card['title'] ) ? $card['title'] : '';
					$link = isset( $card['link'] ) ? $card['link'] : array();
					$link_url = isset( $link['url'] ) ? $link['url'] : '#';
					$target = isset( $link['is_external'] ) && $link['is_external'] ? 'target="_blank"' : '';
					$nofollow = isset( $link['nofollow'] ) && $link['nofollow'] ? 'rel="nofollow"' : '';
					?>
					<?php if ( ! empty( $link_url ) && $link_url !== '#' ) : ?>
						<a href="<?php echo esc_url( $link_url ); ?>" class="hbl-all-about-hb-card" <?php echo esc_attr( $target ); ?> <?php echo esc_attr( $nofollow ); ?>>
					<?php else : ?>
						<div class="hbl-all-about-hb-card">
					<?php endif; ?>
						<?php if ( $image_url ) : ?>
							<div class="hbl-all-about-hb-card-image">
								<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $card_title ); ?>" loading="lazy">
							</div>
						<?php endif; ?>
						<div class="hbl-all-about-hb-card-content">
							<?php if ( ! empty( $icon ) ) : ?>
								<div class="hbl-all-about-hb-card-icon">
									<?php \Elementor\Icons_Manager::render_icon( $icon, array( 'aria-hidden' => 'true' ) ); ?>
								</div>
							<?php endif; ?>
							<?php if ( $card_title ) : ?>
								<h3 class="hbl-all-about-hb-card-title"><?php echo esc_html( $card_title ); ?></h3>
							<?php endif; ?>
						</div>
					<?php if ( ! empty( $link_url ) && $link_url !== '#' ) : ?>
						</a>
					<?php else : ?>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
