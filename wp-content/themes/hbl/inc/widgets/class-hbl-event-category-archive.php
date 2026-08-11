<?php

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Event_Category_Archive extends Widget_Base {

	public function get_name() {
		return 'hbl-event-category-archive';
	}

	public function get_title() {
		return esc_html__( 'HBL Event Category Archive', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-archive';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'event', 'category', 'archive', 'categories', 'grid', 'hbl' );
	}

	private function get_event_taxonomy() {
		if ( taxonomy_exists( 'event_category' ) ) {
			return 'event_category';
		}
		
		$taxonomies = array( 'tribe_events_cat', 'event-category', 'at_event-category' );
		
		foreach ( $taxonomies as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				return $tax;
			}
		}
		
		return 'event_category';
	}

	private function get_category_icon( $term_id ) {
		$icon = get_term_meta( $term_id, 'category_icon', true );
		if ( ! empty( $icon ) ) {
			return $icon;
		}
		return '';
	}

	protected function register_controls() {
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
				'default'     => 'Event Categories',
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
				'default'     => 'Discover events by category',
				'condition'   => array(
					'show_title' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_search',
			array(
				'label'        => esc_html__( 'Show Search', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_categories',
			array(
				'label' => esc_html__( 'Category Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'category_type',
			array(
				'label'   => esc_html__( 'Show Categories', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'parent',
				'options' => array(
					'parent' => esc_html__( 'Parent Categories Only', 'hbl' ),
					'all'    => esc_html__( 'All Categories', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'       => esc_html__( 'Number of Categories', 'hbl' ),
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
				'label'        => esc_html__( 'Hide Empty Categories', 'hbl' ),
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
					'count' => esc_html__( 'Event Count', 'hbl' ),
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

		$this->add_control(
			'show_count',
			array(
				'label'        => esc_html__( 'Show Event Count', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_layout',
			array(
				'label' => esc_html__( 'Layout Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'show_view_toggle',
			array(
				'label'        => esc_html__( 'Show View Toggle', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'no',
				'description'  => esc_html__( 'Toggle between grid and list view', 'hbl' ),
			)
		);

		$this->add_control(
			'default_view',
			array(
				'label'     => esc_html__( 'Default View', 'hbl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'grid',
				'options'   => array(
					'grid' => esc_html__( 'Grid', 'hbl' ),
					'list' => esc_html__( 'List', 'hbl' ),
				),
				'condition' => array(
					'show_view_toggle' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_sort',
			array(
				'label'        => esc_html__( 'Show Sort Dropdown', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'no',
				'description'  => esc_html__( 'Allow users to sort categories', 'hbl' ),
			)
		);

		$this->add_responsive_control(
			'columns',
			array(
				'label'          => esc_html__( 'Columns', 'hbl' ),
				'type'           => Controls_Manager::SELECT,
				'default'        => '4',
				'tablet_default' => '3',
				'mobile_default' => '2',
				'options'        => array(
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6',
				),
				'selectors'      => array(
					'{{WRAPPER}} .hbl-evcatgrid' => 'grid-template-columns: repeat({{VALUE}}, 1fr);',
				),
			)
		);

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => esc_html__( 'Gap', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 8,
						'max' => 60,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 20,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-evcatgrid' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			array(
				'label' => esc_html__( 'Style', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'card_style',
			array(
				'label'   => esc_html__( 'Card Style', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'clean',
				'options' => array(
					'clean'    => esc_html__( 'Clean White', 'hbl' ),
					'gradient' => esc_html__( 'Gradient', 'hbl' ),
					'bordered' => esc_html__( 'Bordered', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'accent_color',
			array(
				'label'     => esc_html__( 'Accent Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#e85d04',
				'selectors' => array(
					'{{WRAPPER}} .hbl-evcatgrid-card-clean:hover' => 'border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-evcatgrid-card-clean .hbl-evcatgrid-icon' => 'background: {{VALUE}};',
					'{{WRAPPER}} .hbl-evcatgrid-card-clean:hover .hbl-evcatgrid-name' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-evcatgrid-card-gradient' => 'background: linear-gradient(135deg, {{VALUE}} 0%, color-mix(in srgb, {{VALUE}} 70%, #000) 100%);',
					'{{WRAPPER}} .hbl-evcatgrid-card-bordered' => 'border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-evcatgrid-card-bordered .hbl-evcatgrid-icon' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-evcatgrid-card-bordered:hover' => 'background: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'card_padding',
			array(
				'label'      => esc_html__( 'Padding', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 16,
						'max' => 60,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 28,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-evcatgrid-card' => 'padding: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'card_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 24,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 12,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-evcatgrid-card' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'show_icon',
			array(
				'label'        => esc_html__( 'Show Icon', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
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
						'max' => 80,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 48,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-evcatgrid-icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .hbl-evcatgrid-icon svg' => 'width: calc({{SIZE}}{{UNIT}} * 0.5); height: calc({{SIZE}}{{UNIT}} * 0.5);',
					'{{WRAPPER}} .hbl-evcatgrid-icon i' => 'font-size: calc({{SIZE}}{{UNIT}} * 0.5);',
				),
				'condition'  => array(
					'show_icon' => 'yes',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$taxonomy = $this->get_event_taxonomy();

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'count' === $settings['orderby'] ? 'name' : $settings['orderby'],
			'order'      => $settings['order'],
		);

		if ( ! empty( $_GET['hbl_cat_search'] ) ) {
			$args['search'] = sanitize_text_field( $_GET['hbl_cat_search'] );
		}

		if ( 'parent' === $settings['category_type'] ) {
			$args['parent'] = 0;
		}

		$categories = get_terms( $args );

		if ( empty( $categories ) || is_wp_error( $categories ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				echo '<div class="hbl-evcatgrid-notice"><p>' . esc_html__( 'No event categories found.', 'hbl' ) . '</p></div>';
			}
			return;
		}

		$real_counts = function_exists( 'hbl_events_db' ) ? hbl_events_db()->count_by_category( 'publish' ) : array();

		foreach ( $categories as $category ) {
			$category->real_count = isset( $real_counts[ $category->term_id ] ) ? $real_counts[ $category->term_id ] : 0;
		}

		if ( 'yes' === $settings['hide_empty'] ) {
			$categories = array_values( array_filter( $categories, function( $category ) {
				return $category->real_count > 0;
			} ) );
		}

		if ( 'count' === $settings['orderby'] ) {
			usort( $categories, function( $a, $b ) use ( $settings ) {
				$diff = $a->real_count - $b->real_count;
				return 'DESC' === $settings['order'] ? -$diff : $diff;
			} );
		}

		if ( intval( $settings['limit'] ) > 0 ) {
			$categories = array_slice( $categories, 0, intval( $settings['limit'] ) );
		}

		if ( empty( $categories ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				echo '<div class="hbl-evcatgrid-notice"><p>' . esc_html__( 'No event categories found.', 'hbl' ) . '</p></div>';
			}
			return;
		}

		$card_style = $settings['card_style'];
		
		$default_view = isset( $settings['default_view'] ) ? $settings['default_view'] : 'grid';
		$current_view = isset( $_GET['hbl_view'] ) ? sanitize_text_field( $_GET['hbl_view'] ) : $default_view;
		if ( ! in_array( $current_view, array( 'grid', 'list' ), true ) ) {
			$current_view = 'grid';
		}
		
		$current_sort = isset( $_GET['hbl_evcat_sort'] ) ? sanitize_text_field( $_GET['hbl_evcat_sort'] ) : '';
		
		$base_url = strtok( $_SERVER['REQUEST_URI'], '?' );
		$query_params = $_GET;
		?>
		<div class="hbl-evcatgrid-widget">
			<?php if ( 'yes' === $settings['show_title'] && ! empty( $settings['title'] ) ) : ?>
				<div class="hbl-evcatgrid-header">
					<h2 class="hbl-evcatgrid-title"><?php echo esc_html( $settings['title'] ); ?></h2>
					<?php if ( ! empty( $settings['description'] ) ) : ?>
						<p class="hbl-evcatgrid-desc"><?php echo esc_html( $settings['description'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( 'yes' === $settings['show_search'] ) : ?>
				<div class="hbl-widget-search-wrap">
					<form role="search" method="get" class="hbl-widget-search-form" action="">
						<?php 
						foreach ( $_GET as $key => $val ) {
							if ( 'hbl_cat_search' === $key ) continue;
							if ( is_array( $val ) ) {
								foreach ( $val as $k => $v ) {
									echo '<input type="hidden" name="' . esc_attr( $key ) . '[' . esc_attr( $k ) . ']" value="' . esc_attr( $v ) . '">';
								}
							} else {
								echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '">';
							}
						}
						?>
						<input type="text" name="hbl_cat_search" class="hbl-widget-search-input" placeholder="<?php esc_attr_e( 'Search categories...', 'hbl' ); ?>" value="<?php echo isset( $_GET['hbl_cat_search'] ) ? esc_attr( $_GET['hbl_cat_search'] ) : ''; ?>">
						<button type="submit" class="hbl-widget-search-submit">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<circle cx="11" cy="11" r="8"></circle>
								<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
							</svg>
						</button>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( 'yes' === $settings['show_view_toggle'] || 'yes' === $settings['show_sort'] ) : ?>
				<div class="hbl-widget-toolbar">
					<?php if ( 'yes' === $settings['show_view_toggle'] ) : ?>
						<div class="hbl-view-toggle">
							<?php
							$grid_params = $query_params;
							$grid_params['hbl_view'] = 'grid';
							$grid_url = add_query_arg( $grid_params, $base_url );
							
							$list_params = $query_params;
							$list_params['hbl_view'] = 'list';
							$list_url = add_query_arg( $list_params, $base_url );
							?>
							<a href="<?php echo esc_url( $grid_url ); ?>" class="hbl-view-btn <?php echo 'grid' === $current_view ? 'active' : ''; ?>" title="<?php esc_attr_e( 'Grid View', 'hbl' ); ?>">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
									<rect x="3" y="3" width="7" height="7"></rect>
									<rect x="14" y="3" width="7" height="7"></rect>
									<rect x="14" y="14" width="7" height="7"></rect>
									<rect x="3" y="14" width="7" height="7"></rect>
								</svg>
							</a>
							<a href="<?php echo esc_url( $list_url ); ?>" class="hbl-view-btn <?php echo 'list' === $current_view ? 'active' : ''; ?>" title="<?php esc_attr_e( 'List View', 'hbl' ); ?>">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
									<line x1="8" y1="6" x2="21" y2="6"></line>
									<line x1="8" y1="12" x2="21" y2="12"></line>
									<line x1="8" y1="18" x2="21" y2="18"></line>
									<line x1="3" y1="6" x2="3.01" y2="6"></line>
									<line x1="3" y1="12" x2="3.01" y2="12"></line>
									<line x1="3" y1="18" x2="3.01" y2="18"></line>
								</svg>
							</a>
						</div>
					<?php endif; ?>

					<?php if ( 'yes' === $settings['show_sort'] ) : ?>
						<div class="hbl-sort-dropdown">
							<label for="hbl-sort-select-<?php echo esc_attr( $this->get_id() ); ?>" class="hbl-sort-label"><?php esc_html_e( 'Sort by:', 'hbl' ); ?></label>
							<select id="hbl-sort-select-<?php echo esc_attr( $this->get_id() ); ?>" class="hbl-sort-select" onchange="hblEvCatSortChange(this)">
								<option value="" <?php selected( $current_sort, '' ); ?>><?php esc_html_e( 'Default', 'hbl' ); ?></option>
								<option value="name_asc" <?php selected( $current_sort, 'name_asc' ); ?>><?php esc_html_e( 'Name (A-Z)', 'hbl' ); ?></option>
								<option value="name_desc" <?php selected( $current_sort, 'name_desc' ); ?>><?php esc_html_e( 'Name (Z-A)', 'hbl' ); ?></option>
								<option value="count_desc" <?php selected( $current_sort, 'count_desc' ); ?>><?php esc_html_e( 'Most Events', 'hbl' ); ?></option>
								<option value="count_asc" <?php selected( $current_sort, 'count_asc' ); ?>><?php esc_html_e( 'Fewest Events', 'hbl' ); ?></option>
							</select>
						</div>
						<script>
						function hblEvCatSortChange(el) {
							var url = new URL(window.location.href);
							if (el.value) {
								url.searchParams.set('hbl_evcat_sort', el.value);
							} else {
								url.searchParams.delete('hbl_evcat_sort');
							}
							window.location.href = url.toString();
						}
						</script>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="hbl-evcatgrid hbl-view-<?php echo esc_attr( $current_view ); ?>">
				<?php foreach ( $categories as $category ) : 
					$cat_link = get_term_link( $category );
					$cat_icon = $this->get_category_icon( $category->term_id );
				?>
					<a href="<?php echo esc_url( $cat_link ); ?>" class="hbl-evcatgrid-card hbl-evcatgrid-card-<?php echo esc_attr( $card_style ); ?>">
						<?php if ( 'yes' === $settings['show_icon'] ) : ?>
							<div class="hbl-evcatgrid-icon">
								<?php if ( ! empty( $cat_icon ) ) : ?>
									<i class="<?php echo esc_attr( $cat_icon ); ?>"></i>
								<?php else : ?>
									<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
										<line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
										<line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
										<line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
									</svg>
								<?php endif; ?>
							</div>
						<?php endif; ?>
						<h3 class="hbl-evcatgrid-name"><?php echo esc_html( $category->name ); ?></h3>
						<?php if ( 'yes' === $settings['show_count'] ) : ?>
							<span class="hbl-evcatgrid-count"><?php echo esc_html( sprintf( _n( '%s event', '%s events', $category->real_count, 'hbl' ), $category->real_count ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}

