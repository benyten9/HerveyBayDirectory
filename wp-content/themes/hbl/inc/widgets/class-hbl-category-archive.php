<?php

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Category_Archive extends Widget_Base {

	public function get_name() {
		return 'hbl-category-archive';
	}

	public function get_title() {
		return esc_html__( 'HBL Category Archive', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-folder-o';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'category', 'archive', 'categories', 'directory', 'grid', 'hbl' );
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
				'default'     => 'Browse by Category',
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
				'default'     => 'Find local businesses and services in Hervey Bay',
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

		$this->add_control(
			'search_placeholder',
			array(
				'label'       => esc_html__( 'Search Placeholder', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'Search categories...',
				'label_block' => true,
				'condition'   => array(
					'show_search' => 'yes',
				),
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
			'show_subcategory_count',
			array(
				'label'        => esc_html__( 'Include Subcategory Count', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Add child category counts to parent total', 'hbl' ),
				'condition'    => array(
					'category_type' => 'parent',
					'show_count'    => 'yes',
				),
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
					'{{WRAPPER}} .hbl-catgrid' => 'grid-template-columns: repeat({{VALUE}}, 1fr);',
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
					'{{WRAPPER}} .hbl-catgrid' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_header',
			array(
				'label'     => esc_html__( 'Header', 'hbl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_title' => 'yes',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'label'    => esc_html__( 'Title Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-catgrid-title',
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#1a1a1a',
				'selectors' => array(
					'{{WRAPPER}} .hbl-catgrid-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'header_align',
			array(
				'label'     => esc_html__( 'Alignment', 'hbl' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array(
						'title' => esc_html__( 'Left', 'hbl' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => esc_html__( 'Center', 'hbl' ),
						'icon'  => 'eicon-text-align-center',
					),
				),
				'default'   => 'center',
				'selectors' => array(
					'{{WRAPPER}} .hbl-catgrid-header' => 'text-align: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_cards',
			array(
				'label' => esc_html__( 'Cards', 'hbl' ),
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
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-catgrid-card-clean:hover' => 'border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-catgrid-card-clean .hbl-catgrid-icon' => 'background: {{VALUE}};',
					'{{WRAPPER}} .hbl-catgrid-card-clean:hover .hbl-catgrid-name' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-catgrid-card-gradient' => 'background: linear-gradient(135deg, {{VALUE}} 0%, color-mix(in srgb, {{VALUE}} 70%, #000) 100%);',
					'{{WRAPPER}} .hbl-catgrid-card-bordered' => 'border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-catgrid-card-bordered .hbl-catgrid-icon' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-catgrid-card-bordered:hover' => 'background: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_icon',
			array(
				'label' => esc_html__( 'Icon', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
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
					'{{WRAPPER}} .hbl-catgrid-icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .hbl-catgrid-icon svg' => 'width: calc({{SIZE}}{{UNIT}} * 0.5); height: calc({{SIZE}}{{UNIT}} * 0.5);',
					'{{WRAPPER}} .hbl-catgrid-icon i' => 'font-size: calc({{SIZE}}{{UNIT}} * 0.5);',
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

		$args = array(
			'taxonomy'   => 'at_biz_dir-category',
			'hide_empty' => 'yes' === $settings['hide_empty'],
			'orderby'    => $settings['orderby'],
			'order'      => $settings['order'],
		);

		if ( 'parent' === $settings['category_type'] ) {
			$args['parent'] = 0;
		}

		if ( intval( $settings['limit'] ) > 0 ) {
			$args['number'] = intval( $settings['limit'] );
		}

		$categories = get_terms( $args );

		if ( empty( $categories ) || is_wp_error( $categories ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				echo '<div class="hbl-catgrid-notice"><p>' . esc_html__( 'No categories found.', 'hbl' ) . '</p></div>';
			}
			return;
		}

		$child_counts = array();
		if ( 'parent' === $settings['category_type'] && 'yes' === $settings['show_subcategory_count'] ) {
			$all_terms = get_terms( array(
				'taxonomy'   => 'at_biz_dir-category',
				'hide_empty' => false,
			) );
			
			foreach ( $all_terms as $term ) {
				if ( $term->parent > 0 ) {
					if ( ! isset( $child_counts[ $term->parent ] ) ) {
						$child_counts[ $term->parent ] = 0;
					}
					$child_counts[ $term->parent ] += $term->count;
				}
			}
		}

		$card_style = $settings['card_style'];
		$widget_id = $this->get_id();
		
		$default_view = isset( $settings['default_view'] ) ? $settings['default_view'] : 'grid';
		$current_view = isset( $_GET['hbl_view'] ) ? sanitize_text_field( $_GET['hbl_view'] ) : $default_view;
		if ( ! in_array( $current_view, array( 'grid', 'list' ), true ) ) {
			$current_view = 'grid';
		}
		
		$current_sort = isset( $_GET['hbl_cat_sort'] ) ? sanitize_text_field( $_GET['hbl_cat_sort'] ) : '';
		
		$base_url = strtok( $_SERVER['REQUEST_URI'], '?' );
		$query_params = $_GET;
		?>
		<div class="hbl-catgrid-widget" data-widget-id="<?php echo esc_attr( $widget_id ); ?>">
			<?php if ( 'yes' === $settings['show_title'] && ! empty( $settings['title'] ) ) : ?>
				<div class="hbl-catgrid-header">
					<h2 class="hbl-catgrid-title"><?php echo esc_html( $settings['title'] ); ?></h2>
					<?php if ( ! empty( $settings['description'] ) ) : ?>
						<p class="hbl-catgrid-desc"><?php echo esc_html( $settings['description'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( 'yes' === $settings['show_search'] ) : ?>
				<div class="hbl-catgrid-search">
					<div class="hbl-catgrid-search-wrapper">
						<svg class="hbl-catgrid-search-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
							<path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
						<input type="text" class="hbl-catgrid-search-input" placeholder="<?php echo esc_attr( $settings['search_placeholder'] ); ?>" data-target="<?php echo esc_attr( $widget_id ); ?>">
						<button type="button" class="hbl-catgrid-search-clear" aria-label="<?php esc_attr_e( 'Clear search', 'hbl' ); ?>">
							<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							</svg>
						</button>
					</div>
					<p class="hbl-catgrid-no-results" style="display: none;"><?php esc_html_e( 'No categories found matching your search.', 'hbl' ); ?></p>
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
							<select id="hbl-sort-select-<?php echo esc_attr( $this->get_id() ); ?>" class="hbl-sort-select" onchange="hblCatSortChange(this)">
								<option value="" <?php selected( $current_sort, '' ); ?>><?php esc_html_e( 'Default', 'hbl' ); ?></option>
								<option value="name_asc" <?php selected( $current_sort, 'name_asc' ); ?>><?php esc_html_e( 'Name (A-Z)', 'hbl' ); ?></option>
								<option value="name_desc" <?php selected( $current_sort, 'name_desc' ); ?>><?php esc_html_e( 'Name (Z-A)', 'hbl' ); ?></option>
								<option value="count_desc" <?php selected( $current_sort, 'count_desc' ); ?>><?php esc_html_e( 'Most Listings', 'hbl' ); ?></option>
								<option value="count_asc" <?php selected( $current_sort, 'count_asc' ); ?>><?php esc_html_e( 'Fewest Listings', 'hbl' ); ?></option>
							</select>
						</div>
						<script>
						function hblCatSortChange(el) {
							var url = new URL(window.location.href);
							if (el.value) {
								url.searchParams.set('hbl_cat_sort', el.value);
							} else {
								url.searchParams.delete('hbl_cat_sort');
							}
							window.location.href = url.toString();
						}
						</script>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="hbl-catgrid hbl-view-<?php echo esc_attr( $current_view ); ?>">
				<?php foreach ( $categories as $category ) : 
					$cat_link = get_term_link( $category );
					$cat_icon = $this->get_category_icon( $category->term_id );
					
					$total_count = $category->count;
					if ( isset( $child_counts[ $category->term_id ] ) ) {
						$total_count += $child_counts[ $category->term_id ];
					}
				?>
					<a href="<?php echo esc_url( $cat_link ); ?>" class="hbl-catgrid-card hbl-catgrid-card-<?php echo esc_attr( $card_style ); ?>" data-category-name="<?php echo esc_attr( strtolower( $category->name ) ); ?>">
						<?php if ( 'yes' === $settings['show_icon'] ) : ?>
							<div class="hbl-catgrid-icon">
								<?php if ( ! empty( $cat_icon ) ) : ?>
									<i class="<?php echo esc_attr( $cat_icon ); ?>"></i>
								<?php else : ?>
									<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M22 19C22 19.5304 21.7893 20.0391 21.4142 20.4142C21.0391 20.7893 20.5304 21 20 21H4C3.46957 21 2.96086 20.7893 2.58579 20.4142C2.21071 20.0391 2 19.5304 2 19V5C2 4.46957 2.21071 3.96086 2.58579 3.58579C2.96086 3.21071 3.46957 3 4 3H9L11 6H20C20.5304 6 21.0391 6.21071 21.4142 6.58579C21.7893 6.96086 22 7.46957 22 8V19Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								<?php endif; ?>
							</div>
						<?php endif; ?>
						<h3 class="hbl-catgrid-name"><?php echo esc_html( $category->name ); ?></h3>
						<?php if ( 'yes' === $settings['show_count'] ) : ?>
							<span class="hbl-catgrid-count"><?php echo esc_html( sprintf( _n( '%s listing', '%s listings', $total_count, 'hbl' ), $total_count ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>

		<?php if ( 'yes' === $settings['show_search'] ) : ?>
		<script>
		(function() {
			const widget = document.querySelector('.hbl-catgrid-widget[data-widget-id="<?php echo esc_js( $widget_id ); ?>"]');
			if (!widget) return;

			const searchInput = widget.querySelector('.hbl-catgrid-search-input');
			const clearBtn = widget.querySelector('.hbl-catgrid-search-clear');
			const noResults = widget.querySelector('.hbl-catgrid-no-results');
			const cards = widget.querySelectorAll('.hbl-catgrid-card');

			if (!searchInput || !cards.length) return;

			function filterCategories() {
				const searchTerm = searchInput.value.toLowerCase().trim();
				let visibleCount = 0;

				cards.forEach(function(card) {
					const categoryName = card.getAttribute('data-category-name') || '';
					const isMatch = !searchTerm || categoryName.includes(searchTerm);
					
					card.style.display = isMatch ? '' : 'none';
					if (isMatch) visibleCount++;
				});

				if (clearBtn) {
					clearBtn.style.display = searchTerm ? 'flex' : 'none';
				}

				if (noResults) {
					noResults.style.display = visibleCount === 0 ? 'block' : 'none';
				}
			}

			searchInput.addEventListener('input', filterCategories);

			if (clearBtn) {
				clearBtn.addEventListener('click', function() {
					searchInput.value = '';
					filterCategories();
					searchInput.focus();
				});
			}
		})();
		</script>
		<?php endif; ?>
		<?php
	}
}
