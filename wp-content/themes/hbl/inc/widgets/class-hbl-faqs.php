<?php
/**
 * HBL FAQs Widget
 *
 * Beautiful FAQ accordion widget with expand/collapse functionality
 *
 * @package HBL
 * @since 1.2.400
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Repeater;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * HBL FAQs Widget Class
 */
class HBL_FAQs extends Widget_Base {

	/**
	 * Get widget name
	 */
	public function get_name() {
		return 'hbl-faqs';
	}

	/**
	 * Get widget title
	 */
	public function get_title() {
		return esc_html__( 'HBL FAQs', 'hbl' );
	}

	/**
	 * Get widget icon
	 */
	public function get_icon() {
		return 'eicon-accordion';
	}

	/**
	 * Get widget categories
	 */
	public function get_categories() {
		return array( 'hbl' );
	}

	/**
	 * Get widget keywords
	 */
	public function get_keywords() {
		return array( 'hbl', 'faq', 'accordion', 'question', 'answer', 'toggle', 'collapse' );
	}

	/**
	 * Register widget controls
	 */
	protected function register_controls() {
		
		// ========== CONTENT SECTION: FAQ ITEMS ==========
		$this->start_controls_section(
			'section_faq_items',
			array(
				'label' => esc_html__( 'FAQ Items', 'hbl' ),
			)
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'question',
			array(
				'label'       => esc_html__( 'Question', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'What are your business hours?', 'hbl' ),
				'label_block' => true,
				'dynamic'     => array(
					'active' => true,
				),
			)
		);

		$repeater->add_control(
			'answer',
			array(
				'label'   => esc_html__( 'Answer', 'hbl' ),
				'type'    => Controls_Manager::WYSIWYG,
				'default' => esc_html__( 'We are open Monday to Friday, 9:00 AM to 5:00 PM. We are closed on weekends and public holidays.', 'hbl' ),
				'dynamic' => array(
					'active' => true,
				),
			)
		);

		$repeater->add_control(
			'is_open',
			array(
				'label'        => esc_html__( 'Open by Default', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->add_control(
			'faq_items',
			array(
				'label'       => esc_html__( 'FAQ Items', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array(
						'question' => esc_html__( 'What services do you offer?', 'hbl' ),
						'answer'   => esc_html__( 'We offer a comprehensive range of professional services tailored to meet your specific needs.', 'hbl' ),
						'is_open'  => 'yes',
					),
					array(
						'question' => esc_html__( 'How can I contact you?', 'hbl' ),
						'answer'   => esc_html__( 'You can reach us via phone, email, or by filling out the contact form on our website.', 'hbl' ),
						'is_open'  => 'no',
					),
					array(
						'question' => esc_html__( 'Do you offer free consultations?', 'hbl' ),
						'answer'   => esc_html__( 'Yes, we provide complimentary initial consultations to discuss your requirements.', 'hbl' ),
						'is_open'  => 'no',
					),
				),
				'title_field' => '{{{ question }}}',
			)
		);

		$this->end_controls_section();

		// ========== CONTENT SECTION: SETTINGS ==========
		$this->start_controls_section(
			'section_settings',
			array(
				'label' => esc_html__( 'Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'accordion_behavior',
			array(
				'label'       => esc_html__( 'Accordion Behavior', 'hbl' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'single',
				'options'     => array(
					'single'   => esc_html__( 'Single - One Open at a Time', 'hbl' ),
					'multiple' => esc_html__( 'Multiple - Multiple Can Be Open', 'hbl' ),
				),
				'description' => esc_html__( 'Choose whether only one FAQ can be open at a time or multiple FAQs can be open simultaneously', 'hbl' ),
			)
		);

		$this->add_control(
			'icon_position',
			array(
				'label'   => esc_html__( 'Icon Position', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'right',
				'options' => array(
					'left'  => esc_html__( 'Left', 'hbl' ),
					'right' => esc_html__( 'Right', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'show_icon',
			array(
				'label'        => esc_html__( 'Show Toggle Icon', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'icon_type',
			array(
				'label'     => esc_html__( 'Toggle Icon Type', 'hbl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'chevron',
				'options'   => array(
					'chevron' => esc_html__( 'Chevron', 'hbl' ),
					'plus'    => esc_html__( 'Plus/Minus', 'hbl' ),
					'arrow'   => esc_html__( 'Arrow', 'hbl' ),
				),
				'condition' => array(
					'show_icon' => 'yes',
				),
			)
		);

		$this->add_control(
			'question_html_tag',
			array(
				'label'       => esc_html__( 'Question HTML Tag', 'hbl' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'span',
				'options'     => array(
					'h1'   => esc_html__( 'H1', 'hbl' ),
					'h2'   => esc_html__( 'H2', 'hbl' ),
					'h3'   => esc_html__( 'H3', 'hbl' ),
					'h4'   => esc_html__( 'H4', 'hbl' ),
					'h5'   => esc_html__( 'H5', 'hbl' ),
					'h6'   => esc_html__( 'H6', 'hbl' ),
					'div'  => esc_html__( 'DIV', 'hbl' ),
					'span' => esc_html__( 'SPAN', 'hbl' ),
					'p'    => esc_html__( 'P', 'hbl' ),
				),
				'description' => esc_html__( 'Choose the HTML tag for FAQ questions', 'hbl' ),
				'separator'   => 'before',
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
			'container_spacing',
			array(
				'label'      => esc_html__( 'Spacing Between Items', 'hbl' ),
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
					'size' => 15,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-faq-item:not(:last-child)' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: FAQ ITEM ==========
		$this->start_controls_section(
			'section_style_item',
			array(
				'label' => esc_html__( 'FAQ Item', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'item_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-faq-item' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: QUESTION ==========
		$this->start_controls_section(
			'section_style_question',
			array(
				'label' => esc_html__( 'Question', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'question_typography',
				'selector' => '{{WRAPPER}} .hbl-faq-question-text',
			)
		);

		$this->start_controls_tabs( 'question_style_tabs' );

		// Normal State
		$this->start_controls_tab(
			'question_normal',
			array(
				'label' => esc_html__( 'Normal', 'hbl' ),
			)
		);

		$this->add_control(
			'question_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-faq-question-text' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'question_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F5F5F5',
				'selectors' => array(
					'{{WRAPPER}} .hbl-faq-question' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		// Hover State
		$this->start_controls_tab(
			'question_hover',
			array(
				'label' => esc_html__( 'Hover', 'hbl' ),
			)
		);

		$this->add_control(
			'question_hover_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-faq-question:hover .hbl-faq-question-text' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'question_hover_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-faq-question:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		// Active State
		$this->start_controls_tab(
			'question_active',
			array(
				'label' => esc_html__( 'Active', 'hbl' ),
			)
		);

		$this->add_control(
			'question_active_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-faq-item.active .hbl-faq-question-text' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'question_active_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFF5F3',
				'selectors' => array(
					'{{WRAPPER}} .hbl-faq-item.active .hbl-faq-question' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		// ========== STYLE: ANSWER ==========
		$this->start_controls_section(
			'section_style_answer',
			array(
				'label' => esc_html__( 'Answer', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'answer_typography',
				'selector' => '{{WRAPPER}} .hbl-faq-answer',
			)
		);

		$this->add_control(
			'answer_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#666666',
				'selectors' => array(
					'{{WRAPPER}} .hbl-faq-answer' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'answer_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-faq-answer-wrapper' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'answer_margin',
			array(
				'label'      => esc_html__( 'Top Spacing', 'hbl' ),
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
					'{{WRAPPER}} .hbl-faq-answer-wrapper' => 'margin-top: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: ICON ==========
		$this->start_controls_section(
			'section_style_icon',
			array(
				'label'     => esc_html__( 'Toggle Icon', 'hbl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_icon' => 'yes',
				),
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
						'max' => 50,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 20,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-faq-icon' => 'font-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'icon_style_tabs' );

		// Normal State
		$this->start_controls_tab(
			'icon_normal',
			array(
				'label' => esc_html__( 'Normal', 'hbl' ),
			)
		);

		$this->add_control(
			'icon_color',
			array(
				'label'     => esc_html__( 'Icon Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-faq-icon' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		// Active State
		$this->start_controls_tab(
			'icon_active',
			array(
				'label' => esc_html__( 'Active', 'hbl' ),
			)
		);

		$this->add_control(
			'icon_active_color',
			array(
				'label'     => esc_html__( 'Icon Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-faq-item.active .hbl-faq-icon' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_responsive_control(
			'icon_spacing',
			array(
				'label'      => esc_html__( 'Spacing', 'hbl' ),
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
					'size' => 15,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-faq-question.icon-left .hbl-faq-icon'  => 'margin-right: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .hbl-faq-question.icon-right .hbl-faq-icon' => 'margin-left: {{SIZE}}{{UNIT}};',
				),
				'separator'  => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render widget output on the frontend
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		if ( empty( $settings['faq_items'] ) ) {
			return;
		}

		$accordion_behavior = isset( $settings['accordion_behavior'] ) ? $settings['accordion_behavior'] : 'single';
		$icon_position = isset( $settings['icon_position'] ) ? $settings['icon_position'] : 'right';
		$show_icon = isset( $settings['show_icon'] ) && 'yes' === $settings['show_icon'];
		$icon_type = isset( $settings['icon_type'] ) ? $settings['icon_type'] : 'chevron';
		$question_html_tag = isset( $settings['question_html_tag'] ) ? $settings['question_html_tag'] : 'span';
		
		// Validate HTML tag to prevent XSS
		$allowed_tags = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' );
		if ( ! in_array( $question_html_tag, $allowed_tags, true ) ) {
			$question_html_tag = 'span';
		}

		// Get icon classes based on type
		$icon_collapsed = $this->get_icon_class( $icon_type, false );
		$icon_expanded = $this->get_icon_class( $icon_type, true );
		?>

		<div class="hbl-faqs-wrapper" data-behavior="<?php echo esc_attr( $accordion_behavior ); ?>">
			<?php foreach ( $settings['faq_items'] as $index => $item ) : 
				$is_open = isset( $item['is_open'] ) && 'yes' === $item['is_open'];
				$item_key = 'faq_item_' . $index;
				?>
				
				<div class="hbl-faq-item <?php echo $is_open ? 'active' : ''; ?>" data-index="<?php echo esc_attr( $index ); ?>">
					
					<!-- Question -->
					<div class="hbl-faq-question icon-<?php echo esc_attr( $icon_position ); ?>">
						
						<?php if ( $show_icon && 'left' === $icon_position ) : ?>
							<i class="hbl-faq-icon <?php echo esc_attr( $is_open ? $icon_expanded : $icon_collapsed ); ?>" 
							   data-collapsed="<?php echo esc_attr( $icon_collapsed ); ?>" 
							   data-expanded="<?php echo esc_attr( $icon_expanded ); ?>"></i>
						<?php endif; ?>
						
						<<?php echo esc_attr( $question_html_tag ); ?> class="hbl-faq-question-text">
							<?php echo esc_html( $item['question'] ); ?>
						</<?php echo esc_attr( $question_html_tag ); ?>>
						
						<?php if ( $show_icon && 'right' === $icon_position ) : ?>
							<i class="hbl-faq-icon <?php echo esc_attr( $is_open ? $icon_expanded : $icon_collapsed ); ?>" 
							   data-collapsed="<?php echo esc_attr( $icon_collapsed ); ?>" 
							   data-expanded="<?php echo esc_attr( $icon_expanded ); ?>"></i>
						<?php endif; ?>
						
					</div>

					<!-- Answer -->
					<div class="hbl-faq-answer-wrapper" style="<?php echo $is_open ? '' : 'display: none;'; ?>">
						<div class="hbl-faq-answer">
							<?php echo wp_kses_post( $item['answer'] ); ?>
						</div>
					</div>

				</div>

			<?php endforeach; ?>
		</div>

		<?php
	}

	/**
	 * Get icon class based on type and state
	 */
	private function get_icon_class( $type, $expanded = false ) {
		$icons = array(
			'chevron' => array(
				'collapsed' => 'bi bi-chevron-down',
				'expanded'  => 'bi bi-chevron-up',
			),
			'plus'    => array(
				'collapsed' => 'bi bi-plus-lg',
				'expanded'  => 'bi bi-dash-lg',
			),
			'arrow'   => array(
				'collapsed' => 'bi bi-arrow-down-short',
				'expanded'  => 'bi bi-arrow-up-short',
			),
		);

		$icon_set = isset( $icons[ $type ] ) ? $icons[ $type ] : $icons['chevron'];
		
		return $expanded ? $icon_set['expanded'] : $icon_set['collapsed'];
	}
}

