<?php

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Static_Grid extends Widget_Base {

	public function get_name() {
		return 'hbl-static-grid';
	}

	public function get_title() {
		return esc_html__( 'Grid of Listing', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-posts-grid';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'hbl', 'static', 'grid', 'listing', 'items', 'cards' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => esc_html__( 'Content Items', 'hbl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$repeater = new \Elementor\Repeater();

		$repeater->add_control(
			'item_image',
			array(
				'label'   => esc_html__( 'Featured Image', 'hbl' ),
				'type'    => Controls_Manager::MEDIA,
				'default' => array(
					'url' => Utils::get_placeholder_image_src(),
				),
			)
		);

		$repeater->add_control(
			'item_title',
			array(
				'label'       => esc_html__( 'Title', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Item Title', 'hbl' ),
				'label_block' => true,
			)
		);

		$repeater->add_control(
			'item_description',
			array(
				'label'       => esc_html__( 'Description', 'hbl' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => esc_html__( 'Item description goes here...', 'hbl' ),
				'label_block' => true,
			)
		);

		$repeater->add_control(
			'item_link',
			array(
				'label'       => esc_html__( 'Link', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'https://your-link.com', 'hbl' ),
				'default'     => array(
					'url' => '#',
				),
			)
		);

		$repeater->add_control(
			'show_icon',
			array(
				'label'        => esc_html__( 'Show Arrow Icon', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$repeater->add_control(
			'icon_link',
			array(
				'label'       => esc_html__( 'Icon Link (Optional)', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'https://your-link.com', 'hbl' ),
				'condition'   => array(
					'show_icon' => 'yes',
				),
			)
		);

		$this->add_control(
			'items_list',
			array(
				'label'       => esc_html__( 'Items', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array(
						'item_title'       => esc_html__( 'Item #1', 'hbl' ),
						'item_description' => esc_html__( 'This is the description for item #1.', 'hbl' ),
					),
					array(
						'item_title'       => esc_html__( 'Item #2', 'hbl' ),
						'item_description' => esc_html__( 'This is the description for item #2.', 'hbl' ),
					),
					array(
						'item_title'       => esc_html__( 'Item #3', 'hbl' ),
						'item_description' => esc_html__( 'This is the description for item #3.', 'hbl' ),
					),
				),
				'title_field' => '{{{ item_title }}}',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style_grid',
			array(
				'label' => esc_html__( 'Grid Layout', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'columns',
			array(
				'label'          => esc_html__( 'Columns', 'hbl' ),
				'type'           => Controls_Manager::SELECT,
				'default'        => '3',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'options'        => array(
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
				'selectors'      => array(
					'{{WRAPPER}} .static-grid-container' => 'grid-template-columns: repeat({{VALUE}}, 1fr);',
				),
			)
		);

		$this->add_responsive_control(
			'column_gap',
			array(
				'label'      => esc_html__( 'Column Gap', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
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
					'{{WRAPPER}} .static-grid-container' => 'column-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'row_gap',
			array(
				'label'      => esc_html__( 'Row Gap', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
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
					'{{WRAPPER}} .static-grid-container' => 'row-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style_card',
			array(
				'label' => esc_html__( 'Card', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style_image',
			array(
				'label' => esc_html__( 'Featured Image', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'image_height',
			array(
				'label'      => esc_html__( 'Image Height', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'vh' ),
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
					'{{WRAPPER}} .static-grid-image' => 'height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style_title',
			array(
				'label' => esc_html__( 'Title', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .static-grid-title',
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .static-grid-title' => 'color: {{VALUE}};',
					'{{WRAPPER}} .static-grid-title a' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'title_hover_color',
			array(
				'label'     => esc_html__( 'Hover Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .static-grid-title a:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'title_spacing',
			array(
				'label'      => esc_html__( 'Spacing', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .static-grid-title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style_description',
			array(
				'label' => esc_html__( 'Description', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'description_typography',
				'selector' => '{{WRAPPER}} .static-grid-description',
			)
		);

		$this->add_control(
			'description_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .static-grid-description' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'description_spacing',
			array(
				'label'      => esc_html__( 'Spacing', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .static-grid-description' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style_icon',
			array(
				'label' => esc_html__( 'Arrow Icon', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
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
						'min' => 20,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 40,
				),
				'selectors'  => array(
					'{{WRAPPER}} .static-grid-icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .static-grid-icon svg' => 'width: calc({{SIZE}}{{UNIT}} * 0.4); height: calc({{SIZE}}{{UNIT}} * 0.4);',
				),
			)
		);

		$this->add_control(
			'icon_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .static-grid-icon' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'icon_arrow_color',
			array(
				'label'     => esc_html__( 'Arrow Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .static-grid-icon svg' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'icon_hover_bg_color',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .static-grid-icon:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'icon_hover_arrow_color',
			array(
				'label'     => esc_html__( 'Hover Arrow Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .static-grid-icon:hover svg' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$items    = $settings['items_list'];

		if ( empty( $items ) ) {
			echo '<div class="static-grid-no-items">';
			echo '<p>' . esc_html__( 'No items added. Please add items from the widget settings.', 'hbl' ) . '</p>';
			echo '</div>';
			return;
		}

		?>
		<div class="static-grid-wrapper">
			<div class="static-grid-container">
				<?php foreach ( $items as $item ) : ?>
					<div class="static-grid-card">
						<?php if ( ! empty( $item['item_image']['url'] ) ) : ?>
							<div class="static-grid-image-wrapper">
								<?php
								$link_tag = '';
								if ( ! empty( $item['item_link']['url'] ) ) {
									$this->add_link_attributes( 'item_link_' . $item['_id'], $item['item_link'] );
									$link_tag = 'item_link_' . $item['_id'];
								}
								?>
								<?php if ( ! empty( $item['item_link']['url'] ) ) : ?>
									<a <?php echo $this->get_render_attribute_string( $link_tag ); ?> class="static-grid-image-link">
								<?php endif; ?>
									<div class="static-grid-image" style="background-image: url('<?php echo esc_url( $item['item_image']['url'] ); ?>');">
									</div>
								<?php if ( ! empty( $item['item_link']['url'] ) ) : ?>
									</a>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<div class="static-grid-content">
							<?php if ( ! empty( $item['item_title'] ) ) : ?>
								<h3 class="static-grid-title">
									<?php if ( ! empty( $item['item_link']['url'] ) ) : ?>
										<a <?php echo $this->get_render_attribute_string( $link_tag ); ?>>
											<?php echo esc_html( $item['item_title'] ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( $item['item_title'] ); ?>
									<?php endif; ?>
								</h3>
							<?php endif; ?>

							<?php if ( ! empty( $item['item_description'] ) ) : ?>
								<div class="static-grid-description">
									<?php echo wp_kses_post( $item['item_description'] ); ?>
								</div>
							<?php endif; ?>

							<?php if ( 'yes' === $item['show_icon'] ) : ?>
								<?php
								$icon_link = ! empty( $item['icon_link']['url'] ) ? $item['icon_link'] : $item['item_link'];
								if ( ! empty( $icon_link['url'] ) ) {
									$this->add_link_attributes( 'icon_link_' . $item['_id'], $icon_link );
								}
								?>
								<a <?php echo $this->get_render_attribute_string( 'icon_link_' . $item['_id'] ); ?> class="static-grid-icon" title="<?php echo esc_attr( $item['item_title'] ); ?>">
									<svg width="10" height="10" viewBox="0 0 10 10" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M1 9L9 1M9 1H1M9 1V9" stroke="white" stroke-width="2.43" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</a>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}

