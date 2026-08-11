<?php

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Background;
use Elementor\Repeater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_CTA_Section extends Widget_Base {

	public function get_name() {
		return 'hbl-cta-section';
	}

	public function get_title() {
		return esc_html__( 'CTA Section', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-call-to-action';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	protected function register_controls() {
		
		$this->start_controls_section(
			'section_cta_items',
			array(
				'label' => esc_html__( 'CTA Items', 'hbl' ),
			)
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'icon',
			array(
				'label'       => esc_html__( 'Choose Icon', 'hbl' ),
				'type'        => Controls_Manager::ICONS,
				'default'     => array(
					'value'   => 'fas fa-search',
					'library' => 'fa-solid',
				),
				'label_block' => true,
			)
		);

		$repeater->add_control(
			'text',
			array(
				'label'       => esc_html__( 'Button Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Find a Tradie', 'hbl' ),
				'label_block' => true,
			)
		);

		$repeater->add_control(
			'link',
			array(
				'label'       => esc_html__( 'Link', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'https://your-link.com', 'hbl' ),
				'default'     => array(
					'url' => '#',
				),
			)
		);

		$this->add_control(
			'cta_items',
			array(
				'label'       => esc_html__( 'CTA Items', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array(
						'icon' => array(
							'value'   => 'fas fa-search',
							'library' => 'fa-solid',
						),
						'text' => esc_html__( 'Find a Tradie', 'hbl' ),
						'link' => array( 'url' => '#' ),
					),
					array(
						'icon' => array(
							'value'   => 'fas fa-calendar-alt',
							'library' => 'fa-solid',
						),
						'text' => esc_html__( 'See What\'s On', 'hbl' ),
						'link' => array( 'url' => '#' ),
					),
					array(
						'icon' => array(
							'value'   => 'fas fa-plus',
							'library' => 'fa-solid',
						),
						'text' => esc_html__( 'Submit a Listing', 'hbl' ),
						'link' => array( 'url' => '#' ),
					),
					array(
						'icon' => array(
							'value'   => 'fas fa-shopping-cart',
							'library' => 'fa-solid',
						),
						'text' => esc_html__( 'Browse Local Markets', 'hbl' ),
						'link' => array( 'url' => '#' ),
					),
				),
				'title_field' => '{{{ text }}}',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_container_style',
			array(
				'label' => esc_html__( 'Container', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'container_background',
				'label'    => esc_html__( 'Background', 'hbl' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-cta-section',
				'fields_options' => array(
					'background' => array(
						'default' => 'classic',
					),
					'color' => array(
						'default' => '#008080',
					),
				),
			)
		);

		$this->add_control(
			'container_padding',
			array(
				'label'      => esc_html__( 'Padding', 'hbl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => array(
					'top'      => '0',
					'right'    => '100',
					'bottom'   => '0',
					'left'     => '100',
					'unit'     => 'px',
					'isLinked' => false,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-cta-section' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'item_padding',
			array(
				'label'      => esc_html__( 'Item Padding', 'hbl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => array(
					'top'      => '25',
					'right'    => '0',
					'bottom'   => '25',
					'left'     => '0',
					'unit'     => 'px',
					'isLinked' => false,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-cta-item' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'items_gap',
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
					'size' => 65,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-cta-section' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_items_style',
			array(
				'label' => esc_html__( 'CTA Items', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'item_icon_heading',
			array(
				'label'     => esc_html__( 'Icon', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
			)
		);

		$this->add_control(
			'icon_size',
			array(
				'label'      => esc_html__( 'Icon Size', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 10,
						'max' => 60,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 30,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-cta-icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .hbl-cta-icon i' => 'font-size: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .hbl-cta-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'icon_color',
			array(
				'label'     => esc_html__( 'Icon Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-cta-icon' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-cta-icon i' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-cta-icon svg' => 'fill: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'divider_color_control',
			array(
				'label'     => esc_html__( 'Divider Color (when enabled)', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-cta-divider' => 'background-color: {{VALUE}};',
				),
				'condition' => array(
					'show_dividers' => 'yes',
				),
			)
		);

		$this->add_control(
			'icon_gap',
			array(
				'label'      => esc_html__( 'Gap Between Icon and Text', 'hbl' ),
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
					'size' => 20,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-cta-item-inner' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'item_text_heading',
			array(
				'label'     => esc_html__( 'Text', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'text_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-cta-text',
				'fields_options' => array(
					'typography'  => array( 'default' => 'custom' ),
					'font_family' => array( 'default' => 'Poppins' ),
					'font_weight' => array( 'default' => '600' ),
					'font_size'   => array(
						'default' => array(
							'unit' => 'px',
							'size' => 25,
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
			'text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-cta-text' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'item_hover_heading',
			array(
				'label'     => esc_html__( 'Hover Effects', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'hover_background_color',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-cta-item:hover' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'hover_text_color',
			array(
				'label'     => esc_html__( 'Hover Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-cta-item:hover .hbl-cta-text' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'hover_icon_color',
			array(
				'label'     => esc_html__( 'Hover Icon Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-cta-item:hover .hbl-cta-icon' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-cta-item:hover .hbl-cta-icon i' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-cta-item:hover .hbl-cta-icon svg' => 'fill: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_dividers_style',
			array(
				'label' => esc_html__( 'Dividers', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'show_dividers',
			array(
				'label'        => esc_html__( 'Show Dividers', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'divider_color',
			array(
				'label'     => esc_html__( 'Divider Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-cta-divider' => 'background-color: {{VALUE}};',
				),
				'condition' => array(
					'show_dividers' => 'yes',
				),
			)
		);

		$this->add_control(
			'divider_width',
			array(
				'label'      => esc_html__( 'Divider Width/Height', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 1,
						'max' => 10,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 1,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-cta-divider.vertical' => 'width: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .hbl-cta-divider.horizontal' => 'height: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array(
					'show_dividers' => 'yes',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$cta_items = $settings['cta_items'];
		
		if ( empty( $cta_items ) ) {
			return;
		}

		$show_dividers = 'yes' === $settings['show_dividers'];
		?>
		<div class="hbl-cta-section">
			<?php 
			$total_items = count( $cta_items );
			foreach ( $cta_items as $index => $item ) : 
				$link_key = 'link_' . $index;
				
				if ( ! empty( $item['link']['url'] ) ) {
					$this->add_link_attributes( $link_key, $item['link'] );
				}
				?>
				<a <?php echo $this->get_render_attribute_string( $link_key ); ?> class="hbl-cta-item">
					<div class="hbl-cta-item-inner">
						<div class="hbl-cta-icon">
							<?php
							if ( ! empty( $item['icon']['value'] ) ) {
								\Elementor\Icons_Manager::render_icon( $item['icon'], array( 'aria-hidden' => 'true' ) );
							}
							?>
						</div>
						<span class="hbl-cta-text"><?php echo esc_html( $item['text'] ); ?></span>
					</div>
				</a>
				
				<?php 
				if ( $show_dividers && $index < $total_items - 1 ) : 
				?>
					<div class="hbl-cta-divider vertical"></div>
					<div class="hbl-cta-divider horizontal"></div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

}

