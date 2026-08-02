<?php
/**
 * HBL Location Archive Widget (All Locations)
 *
 * Beautiful location grid display with clean card design
 *
 * @package HBL
 * @since 1.2.697
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Location_Archive extends Widget_Base {

	public function get_name() {
		return 'hbl-location-archive';
	}

	public function get_title() {
		return esc_html__( 'HBL All Locations', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-map-pin';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'location', 'locations', 'archive', 'directory', 'grid', 'map', 'hbl' );
	}

	/**
	 * Get location image from term meta
	 */
	private function get_location_image( $term_id ) {
		$image = get_term_meta( $term_id, 'image', true );
		if ( ! empty( $image ) ) {
			return $image;
		}
		return '';
	}

	protected function register_controls() {
		// ========== CONTENT: GENERAL ==========
		$this->start_controls_section(
			'section_general',
			array(
				'label' => esc_html__( 'General Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => esc_html__( 'Section Title', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'Browse by Location',
				'label_block' => true,
			)
		);

		$this->add_control(
			'show_title',
			array(
				'label'        => esc_html__( 'Show Title', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'description',
			array(
				'label'       => esc_html__( 'Description', 'hbl' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => 'Discover businesses and services in different areas',
				'condition'   => array(
					'show_title' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: LOCATIONS ==========
		$this->start_controls_section(
			'section_locations',
			array(
				'label' => esc_html__( 'Location Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'location_type',
			array(
				'label'   => esc_html__( 'Show Locations', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'parent',
				'options' => array(
					'parent' => esc_html__( 'Parent Locations Only', 'hbl' ),
					'all'    => esc_html__( 'All Locations', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'       => esc_html__( 'Number of Locations', 'hbl' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => -1,
				'min'         => -1,
				'max'         => 999,
				'description' => esc_html__( 'Set to -1 to show all', 'hbl' ),
			)
		);

		$this->add_control(
			'hide_empty',
			array(
				'label'        => esc_html__( 'Hide Empty Locations', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->add_control(
			'orderby',
			array(
				'label'   => esc_html__( 'Order By', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'name',
				'options' => array(
					'name'  => esc_html__( 'Name', 'hbl' ),
					'count' => esc_html__( 'Listing Count', 'hbl' ),
					'id'    => esc_html__( 'ID', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'order',
			array(
				'label'   => esc_html__( 'Order', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'ASC',
				'options' => array(
					'ASC'  => esc_html__( 'Ascending', 'hbl' ),
					'DESC' => esc_html__( 'Descending', 'hbl' ),
				),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: LAYOUT ==========
		$this->start_controls_section(
			'section_layout',
			array(
				'label' => esc_html__( 'Layout Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'columns',
			array(
				'label'   => esc_html__( 'Columns', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '4',
				'options' => array(
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6',
				),
			)
		);

		$this->add_control(
			'card_style',
			array(
				'label'   => esc_html__( 'Card Style', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'clean-white',
				'options' => array(
					'clean-white' => esc_html__( 'Clean White', 'hbl' ),
					'gradient'    => esc_html__( 'Gradient', 'hbl' ),
					'bordered'    => esc_html__( 'Bordered', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'show_count',
			array(
				'label'        => esc_html__( 'Show Listing Count', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_icon',
			array(
				'label'        => esc_html__( 'Show Location Icon', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		// ========== STYLE: COLORS ==========
		$this->start_controls_section(
			'section_style_colors',
			array(
				'label' => esc_html__( 'Colors', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'primary_color',
			array(
				'label'     => esc_html__( 'Primary Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-locgrid-card-gradient' => 'background: linear-gradient(135deg, {{VALUE}} 0%, {{VALUE}}cc 100%);',
					'{{WRAPPER}} .hbl-locgrid-card-bordered:hover' => 'border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-locgrid-icon' => 'background: {{VALUE}};',
					'{{WRAPPER}} .hbl-locgrid-card:hover .hbl-locgrid-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		// Check if taxonomy exists
		if ( ! taxonomy_exists( 'at_biz_dir-location' ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="hbl-locgrid-notice"><p>' . esc_html__( 'Location taxonomy not found. Please ensure Directorist is active.', 'hbl' ) . '</p></div>';
			}
			return;
		}

		// Get locations
		$term_args = array(
			'taxonomy'   => 'at_biz_dir-location',
			'hide_empty' => 'yes' === $settings['hide_empty'],
			'orderby'    => $settings['orderby'],
			'order'      => $settings['order'],
		);

		if ( 'parent' === $settings['location_type'] ) {
			$term_args['parent'] = 0;
		}

		if ( $settings['limit'] > 0 ) {
			$term_args['number'] = $settings['limit'];
		}

		$locations = get_terms( $term_args );

		if ( empty( $locations ) || is_wp_error( $locations ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="hbl-locgrid-notice"><p>' . esc_html__( 'No locations found.', 'hbl' ) . '</p></div>';
			}
			return;
		}
		?>
		<div class="hbl-locgrid-widget">
			<?php if ( 'yes' === $settings['show_title'] && ! empty( $settings['title'] ) ) : ?>
				<div class="hbl-locgrid-header">
					<h2 class="hbl-locgrid-section-title"><?php echo esc_html( $settings['title'] ); ?></h2>
					<?php if ( ! empty( $settings['description'] ) ) : ?>
						<p class="hbl-locgrid-description"><?php echo esc_html( $settings['description'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="hbl-locgrid-grid hbl-locgrid-cols-<?php echo esc_attr( $settings['columns'] ); ?>">
				<?php foreach ( $locations as $location ) :
					$location_link = get_term_link( $location );
					$location_image = $this->get_location_image( $location->term_id );
				?>
					<a href="<?php echo esc_url( $location_link ); ?>" class="hbl-locgrid-card hbl-locgrid-card-<?php echo esc_attr( $settings['card_style'] ); ?>">
						<?php if ( 'yes' === $settings['show_icon'] ) : ?>
							<div class="hbl-locgrid-icon">
								<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
								</svg>
							</div>
						<?php endif; ?>
						<h3 class="hbl-locgrid-title"><?php echo esc_html( $location->name ); ?></h3>
						<?php if ( 'yes' === $settings['show_count'] ) : ?>
							<span class="hbl-locgrid-count">
								<?php
								printf(
									esc_html( _n( '%s listing', '%s listings', $location->count, 'hbl' ) ),
									esc_html( number_format_i18n( $location->count ) )
								);
								?>
							</span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}

