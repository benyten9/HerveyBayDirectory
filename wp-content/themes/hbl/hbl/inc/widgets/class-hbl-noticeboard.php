<?php
/**
 * HBL Noticeboard Widget
 *
 * Displays noticeboard items in a grid layout with images and buttons
 * Matches Figma design specifications for Hervey Bay Directory
 *
 * @package HBL
 * @since 1.2.510
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Background;
use Elementor\Repeater;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * HBL Noticeboard Widget Class
 */
class HBL_Noticeboard extends Widget_Base {

	public function get_name() {
		return 'hbl-noticeboard';
	}

	public function get_title() {
		return esc_html__( 'Noticeboard', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-posts-grid';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'hbl', 'noticeboard', 'grid', 'cards', 'items' );
	}

	protected function register_controls() {

		// ========== CONTENT SECTION: TITLE ==========
		$this->start_controls_section(
			'section_title',
			array(
				'label' => esc_html__( 'Title', 'hbl' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => esc_html__( 'Title', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Noticeboard', 'hbl' ),
				'label_block' => true,
				'dynamic'     => array(
					'active' => true,
				),
			)
		);

		$this->add_control(
			'subtitle',
			array(
				'label'       => esc_html__( 'Subtitle', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'dynamic'     => array(
					'active' => true,
				),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT SECTION: ITEMS ==========
		$this->start_controls_section(
			'section_items',
			array(
				'label' => esc_html__( 'Noticeboard Items', 'hbl' ),
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
			'button_text',
			array(
				'label'       => esc_html__( 'Button Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Button Text', 'hbl' ),
				'label_block' => true,
				'dynamic'     => array(
					'active' => true,
				),
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

		$repeater->add_control(
			'description',
			array(
				'label'       => esc_html__( 'Description', 'hbl' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => '',
				'label_block' => true,
				'rows'        => 3,
				'dynamic'     => array(
					'active' => true,
				),
			)
		);

		$this->add_control(
			'items',
			array(
				'label'       => esc_html__( 'Items', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array(
						'button_text' => esc_html__( 'Jobs Vacant', 'hbl' ),
					),
					array(
						'button_text' => esc_html__( 'Babysitters/Nannies', 'hbl' ),
					),
					array(
						'button_text' => esc_html__( 'Buy/Swap/Sell or Free Stuff', 'hbl' ),
					),
					array(
						'button_text' => esc_html__( 'House Sitters/Home Rentals/Flatmates', 'hbl' ),
					),
					array(
						'button_text' => esc_html__( 'Local Deals and Promotions', 'hbl' ),
					),
					array(
						'button_text' => esc_html__( 'Pet Walking/Minding', 'hbl' ),
					),
				),
				'title_field' => '{{{ button_text }}}',
			)
		);

		$this->end_controls_section();

		// ========== STYLE: TITLE ==========
		$this->start_controls_section(
			'section_style_title',
			array(
				'label' => esc_html__( 'Title', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .hbl-noticeboard-title',
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-noticeboard-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'title_spacing',
			array(
				'label'      => esc_html__( 'Bottom Spacing', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-noticeboard-title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'subtitle_heading',
			array(
				'label'     => esc_html__( 'Subtitle', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'subtitle_typography',
				'selector' => '{{WRAPPER}} .hbl-noticeboard-subtitle',
			)
		);

		$this->add_control(
			'subtitle_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-noticeboard-subtitle' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'subtitle_spacing',
			array(
				'label'      => esc_html__( 'Bottom Spacing', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-noticeboard-subtitle' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: CONTAINER ==========
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
				'label'      => esc_html__( 'Gap Between Title and Items', 'hbl' ),
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
					'size' => 50,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-noticeboard-header' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: GRID ==========
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
				'label'      => esc_html__( 'Gap Between Items', 'hbl' ),
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
					'{{WRAPPER}} .hbl-noticeboard-grid' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: CARD ==========
		$this->start_controls_section(
			'section_style_card',
			array(
				'label' => esc_html__( 'Card', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->end_controls_section();

		// ========== STYLE: IMAGE ==========
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
					'{{WRAPPER}} .hbl-noticeboard-card-image' => 'height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: BUTTON ==========
		$this->start_controls_section(
			'section_style_button',
			array(
				'label' => esc_html__( 'Button', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .hbl-noticeboard-card-button',
			)
		);

		$this->start_controls_tabs( 'button_style_tabs' );

		$this->start_controls_tab(
			'button_normal',
			array(
				'label' => esc_html__( 'Normal', 'hbl' ),
			)
		);

		$this->add_control(
			'button_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-noticeboard-card-button' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-noticeboard-card-button svg' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'button_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-noticeboard-card-button',
				'default'  => '#008080',
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'button_hover',
			array(
				'label' => esc_html__( 'Hover', 'hbl' ),
			)
		);

		$this->add_control(
			'button_hover_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-noticeboard-card:hover .hbl-noticeboard-card-button' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-noticeboard-card:hover .hbl-noticeboard-card-button svg' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'button_hover_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-noticeboard-card:hover .hbl-noticeboard-card-button',
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_responsive_control(
			'icon_size',
			array(
				'label'      => esc_html__( 'Icon Size', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 5,
						'max' => 20,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 6,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-noticeboard-card-button-icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'icon_gap',
			array(
				'label'      => esc_html__( 'Icon Gap', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 30,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 10,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-noticeboard-card-button' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: DESCRIPTION ==========
		$this->start_controls_section(
			'section_style_description',
			array(
				'label' => esc_html__( 'Description', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'description_typography',
				'selector' => '{{WRAPPER}} .hbl-noticeboard-card-description',
			)
		);

		$this->add_control(
			'description_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-noticeboard-card-description' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'description_spacing',
			array(
				'label'      => esc_html__( 'Top Spacing', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-noticeboard-card-description' => 'margin-top: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render widget output on the frontend
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		if ( empty( $settings['items'] ) ) {
			return;
		}

		$title = isset( $settings['title'] ) ? $settings['title'] : '';
		$subtitle = isset( $settings['subtitle'] ) ? $settings['subtitle'] : '';
		$items = $settings['items'];
		?>

		<div class="hbl-noticeboard-wrapper">
			<?php if ( ! empty( $title ) || ! empty( $subtitle ) ) : ?>
				<div class="hbl-noticeboard-header">
					<?php if ( ! empty( $title ) ) : ?>
						<h2 class="hbl-noticeboard-title"><?php echo esc_html( $title ); ?></h2>
					<?php endif; ?>
					<?php if ( ! empty( $subtitle ) ) : ?>
						<p class="hbl-noticeboard-subtitle"><?php echo esc_html( $subtitle ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="hbl-noticeboard-grid">
				<?php foreach ( $items as $item ) : 
					$image = isset( $item['image'] ) ? $item['image'] : array();
					$image_url = isset( $image['url'] ) ? $image['url'] : '';
					$button_text = isset( $item['button_text'] ) ? $item['button_text'] : '';
					$link = isset( $item['link'] ) ? $item['link'] : array();
					$link_url = isset( $link['url'] ) ? $link['url'] : '#';
					$target = isset( $link['is_external'] ) && $link['is_external'] ? 'target="_blank"' : '';
					$nofollow = isset( $link['nofollow'] ) && $link['nofollow'] ? 'rel="nofollow"' : '';
					?>
					<div class="hbl-noticeboard-card">
						<?php if ( $image_url ) : ?>
							<div class="hbl-noticeboard-card-image">
								<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $button_text ); ?>" loading="lazy">
							</div>
						<?php endif; ?>
						<?php 
						$description = isset( $item['description'] ) ? $item['description'] : '';
						if ( $button_text ) : ?>
							<a href="<?php echo esc_url( $link_url ); ?>" class="hbl-noticeboard-card-button" <?php echo esc_attr( $target ); ?> <?php echo esc_attr( $nofollow ); ?>>
								<div class="hbl-noticeboard-card-button-content">
									<span class="hbl-noticeboard-card-button-text"><?php echo esc_html( $button_text ); ?></span>
									<svg class="hbl-noticeboard-card-button-icon" width="6" height="12" viewBox="0 0 6 12" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M1 1L5 6L1 11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</div>
								<?php if ( ! empty( $description ) ) : ?>
									<div class="hbl-noticeboard-card-description">
										<?php echo esc_html( $description ); ?>
									</div>
								<?php endif; ?>
							</a>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<?php
	}
}
