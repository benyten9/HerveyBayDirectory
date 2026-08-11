<?php

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Event_Single_Category extends Widget_Base {

	public function get_name() {
		return 'hbl-event-single-category';
	}

	public function get_title() {
		return esc_html__( 'HBL Event Single Category', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-calendar';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'event', 'category', 'single', 'calendar', 'hbl' );
	}

	private $day_labels = array(
		'mon' => 'Monday',
		'tue' => 'Tuesday',
		'wed' => 'Wednesday',
		'thu' => 'Thursday',
		'fri' => 'Friday',
		'sat' => 'Saturday',
		'sun' => 'Sunday',
	);

	private $week_labels = array(
		'1' => '1st',
		'2' => '2nd',
		'3' => '3rd',
		'4' => '4th',
	);

	private function get_next_occurrence( $event ) {
		$frequency = $event->event_frequency ?? 'once';
		$start_date = $event->start_date;
		$now = current_time( 'timestamp' );
		$today_start = strtotime( 'today', $now );
		$event_start = strtotime( $start_date );
		$event_time = date( 'H:i:s', $event_start );

		if ( $event_start >= $now ) {
			return $start_date;
		}

		if ( ! in_array( $frequency, array( 'weekly', 'monthly', 'recurring', 'multi_day' ), true ) ) {
			return $start_date;
		}

		$recurrence_days = $event->recurrence_days ?? '';
		$recurrence_week = $event->recurrence_week ?? '';
		$recurrence_interval = isset( $event->recurrence_interval ) ? intval( $event->recurrence_interval ) : 1;
		if ( $recurrence_interval < 1 ) $recurrence_interval = 1;

		$normalize_day = function( $d ) {
			$d = strtolower( trim( $d ) );
			$map = array(
				'monday' => 'mon', 'tuesday' => 'tue', 'wednesday' => 'wed', 
				'thursday' => 'thu', 'friday' => 'fri', 'saturday' => 'sat', 'sunday' => 'sun'
			);
			return isset( $map[ $d ] ) ? $map[ $d ] : substr( $d, 0, 3 );
		};

		if ( $frequency === 'weekly' && ! empty( $recurrence_days ) ) {
			$days = array_map( $normalize_day, explode( ',', $recurrence_days ) );
			
			for ( $i = 0; $i < 60; $i++ ) {
				$check_ts = strtotime( "+$i days", $today_start );
				$day_of_week = strtolower( date( 'D', $check_ts ) );

				if ( in_array( $day_of_week, $days, true ) ) {
					$orig_week_start = strtotime( 'last monday', $event_start + 86400 );
					$curr_week_start = strtotime( 'last monday', $check_ts + 86400 );
					$weeks_diff = round( ( $curr_week_start - $orig_week_start ) / ( 7 * 24 * 60 * 60 ) );
					
					if ( $weeks_diff % $recurrence_interval === 0 ) {
						return date( 'Y-m-d', $check_ts ) . ' ' . $event_time;
					}
				}
			}
		} elseif ( $frequency === 'monthly' && ! empty( $recurrence_days ) ) {
			$day_map = array('sun'=>0,'mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6);
			$day_name = $normalize_day( $recurrence_days );
			
			if ( isset( $day_map[$day_name] ) ) {
				$target_day_num = $day_map[$day_name];
				$weeks = ! empty( $recurrence_week ) ? array_map( 'trim', explode( ',', $recurrence_week ) ) : array();

				for ( $i = 0; $i < 12; $i++ ) {
					$check_month_ts = strtotime( "+$i month", $today_start );
					$year = date('Y', $check_month_ts);
					$month = date('n', $check_month_ts);
					
					$days_in_month = date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
					$occurrence = 0;
					
					for ( $day = 1; $day <= $days_in_month; $day++ ) {
						$date_ts = mktime( 0, 0, 0, $month, $day, $year );
						
						if ( date('w', $date_ts) == $target_day_num ) {
							$occurrence++;
							if ( in_array( $occurrence, $weeks ) ) {
								if ( $date_ts >= $today_start ) {
									return date( 'Y-m-d', $date_ts ) . ' ' . $event_time;
								}
							}
						}
					}
				}
			}
		} elseif ( $frequency === 'multi_day' ) {
			$end_date_ts = ! empty( $event->end_date ) ? strtotime( $event->end_date ) : $event_start;
			
			if ( $end_date_ts < $today_start ) {
				return $start_date; 
			}
			
			$check_ts = $today_start;
			for ( $i = 0; $i < 60; $i++ ) {
				$curr_ts = strtotime( "+$i days", $check_ts );
				if ( $curr_ts > $end_date_ts ) break;
				
				$days = ! empty( $recurrence_days ) ? array_map( $normalize_day, explode( ',', $recurrence_days ) ) : array();
				
				if ( empty( $days ) ) {
					return date( 'Y-m-d', $curr_ts ) . ' ' . $event_time;
				}
				
				$day_of_week = strtolower( date( 'D', $curr_ts ) );
				if ( in_array( $day_of_week, $days, true ) ) {
					return date( 'Y-m-d', $curr_ts ) . ' ' . $event_time;
				}
			}
		}

		return $start_date;
	}

	private function get_category_options() {
		$options = array( '' => esc_html__( '— Select Category —', 'hbl' ) );
		
		$taxonomies = array( 'tribe_events_cat', 'event_category', 'event-category', 'at_event-category' );
		
		foreach ( $taxonomies as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$terms = get_terms( array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
				) );
				
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$options[ $term->term_id . '|' . $tax ] = $term->name . ' (' . $term->count . ')';
					}
				}
			}
		}
		
		return $options;
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

	private function get_event_post_type() {
		$post_types = array( 'tribe_events', 'event', 'events', 'at_event' );
		
		foreach ( $post_types as $pt ) {
			if ( post_type_exists( $pt ) ) {
				return $pt;
			}
		}
		
		return 'tribe_events';
	}

	private function get_current_category() {
		$taxonomy = $this->get_event_taxonomy();
		
		if ( is_tax( $taxonomy ) ) {
			$term = get_queried_object();
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		if ( is_tax( 'event_category' ) ) {
			$term = get_queried_object();
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$url_path = trim( parse_url( $request_uri, PHP_URL_PATH ), '/' );
		$path_parts = explode( '/', $url_path );
		
		$whats_on_index = array_search( 'whats-on', $path_parts, true );
		if ( $whats_on_index !== false && isset( $path_parts[ $whats_on_index + 1 ] ) && $path_parts[ $whats_on_index + 1 ] === 'category' ) {
			if ( isset( $path_parts[ $whats_on_index + 2 ] ) ) {
				$potential_slug = $path_parts[ $whats_on_index + 2 ];
				$term = get_term_by( 'slug', $potential_slug, 'event_category' );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
			}
		}
		
		$category_indicators = array( 'event-category', 'events-category', 'single-event-category', 'category' );
		
		foreach ( $path_parts as $index => $part ) {
			if ( in_array( $part, $category_indicators, true ) && isset( $path_parts[ $index + 1 ] ) ) {
				$potential_slug = $path_parts[ $index + 1 ];
				$term = get_term_by( 'slug', $potential_slug, 'event_category' );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
				$term = get_term_by( 'slug', $potential_slug, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
			}
		}

		if ( ! empty( $path_parts ) ) {
			$last_segment = end( $path_parts );
			if ( ! empty( $last_segment ) ) {
				$term = get_term_by( 'slug', $last_segment, 'event_category' );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
				$term = get_term_by( 'slug', $last_segment, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
			}
		}

		if ( \Elementor\Plugin::$instance->preview->is_preview_mode() || \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			$terms = get_terms( array(
				'taxonomy'   => 'event_category',
				'hide_empty' => false,
				'number'     => 1,
			) );
			
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				return $terms[0];
			}
		}

		return null;
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_general',
			array(
				'label' => esc_html__( 'General Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'category_source',
			array(
				'label'   => esc_html__( 'Category Source', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'auto',
				'options' => array(
					'auto'   => esc_html__( 'Auto Detect from URL', 'hbl' ),
					'manual' => esc_html__( 'Manual Selection', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'category',
			array(
				'label'     => esc_html__( 'Select Category', 'hbl' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => $this->get_category_options(),
				'default'   => '',
				'condition' => array(
					'category_source' => 'manual',
				),
			)
		);

		$this->add_control(
			'show_header',
			array(
				'label'        => esc_html__( 'Show Header', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
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
				'condition'    => array(
					'show_header' => 'yes',
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
			'section_events',
			array(
				'label' => esc_html__( 'Events Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'posts_per_page',
			array(
				'label'   => esc_html__( 'Number of Events', 'hbl' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 12,
				'min'     => -1,
				'max'     => 100,
			)
		);

		$this->add_control(
			'show_pagination',
			array(
				'label'        => esc_html__( 'Show Pagination', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
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
					'{{WRAPPER}} .hbl-events-grid' => 'grid-template-columns: repeat({{VALUE}}, 1fr);',
				),
			)
		);

		$this->add_control(
			'show_upcoming_only',
			array(
				'label'        => esc_html__( 'Show Upcoming Only', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->add_control(
			'show_image',
			array(
				'label'        => esc_html__( 'Show Image', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_date',
			array(
				'label'        => esc_html__( 'Show Date Badge', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_venue',
			array(
				'label'        => esc_html__( 'Show Venue', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
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
				'default'      => 'yes',
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
				'default'      => 'yes',
				'description'  => esc_html__( 'Allow users to sort events', 'hbl' ),
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
			'accent_color',
			array(
				'label'     => esc_html__( 'Accent Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#e85d04',
				'selectors' => array(
					'{{WRAPPER}} .hbl-events-count' => 'background: {{VALUE}};',
					'{{WRAPPER}} .hbl-event-card:hover' => 'border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-event-card:hover .hbl-event-title a' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-event-date' => 'background: {{VALUE}};',
					'{{WRAPPER}} .hbl-event-link' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-events-pagination .current' => 'background: {{VALUE}}; border-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => esc_html__( 'Grid Gap', 'hbl' ),
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
					'size' => 24,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-events-grid' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		$category = null;

		if ( 'auto' === $settings['category_source'] ) {
			$category = $this->get_current_category();
			
			if ( ! $category ) {
				if ( current_user_can( 'edit_posts' ) ) {
					echo '<div class="hbl-events-notice">';
					echo '<p><strong>' . esc_html__( 'Auto Detect Mode', 'hbl' ) . '</strong><br>';
					echo esc_html__( 'No event category detected from URL.', 'hbl' ) . '</p>';
					echo '</div>';
				}
				return;
			}
		} else {
			if ( ! empty( $settings['category'] ) ) {
				$parts = explode( '|', $settings['category'] );
				$term_id = intval( $parts[0] );
				$taxonomy = isset( $parts[1] ) ? $parts[1] : 'event_category';
				$category = get_term( $term_id, $taxonomy );
			}

			if ( ! $category || is_wp_error( $category ) ) {
				if ( current_user_can( 'edit_posts' ) ) {
					echo '<div class="hbl-events-notice"><p>' . esc_html__( 'Please select an event category.', 'hbl' ) . '</p></div>';
				}
				return;
			}
		}

		$paged = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;
		$per_page = intval( $settings['posts_per_page'] );
		$offset = ( $paged - 1 ) * $per_page;

		$orderby = 'start_date';
		$order = 'ASC';
		
		if ( ! empty( $_GET['hbl_sort'] ) ) {
			$sort_value = sanitize_text_field( $_GET['hbl_sort'] );
			switch ( $sort_value ) {
				case 'title_asc':
					$orderby = 'title';
					$order = 'ASC';
					break;
				case 'title_desc':
					$orderby = 'title';
					$order = 'DESC';
					break;
				case 'date_desc':
					$orderby = 'start_date';
					$order = 'DESC';
					break;
				case 'date_asc':
					$orderby = 'start_date';
					$order = 'ASC';
					break;
			}
		}

		$events = array();
		$total_events = 0;
		
		if ( function_exists( 'hbl_events_db' ) ) {
			$db = hbl_events_db();
			
			$limit_mult = 5;
			$processing_limit = $per_page * $limit_mult;
			if ( $processing_limit < 50 ) $processing_limit = 50;
			
			$query_args = array(
				'category_id' => $category->term_id,
				'status'      => 'publish',
				'limit'       => -1,
			);

			if ( ! empty( $_GET['hbl_event_search'] ) ) {
				$query_args['search'] = sanitize_text_field( $_GET['hbl_event_search'] );
			}
			
			$raw_events = $db->get_events( $query_args );
			$total_events_count = 0;
			
			$processed_events = array();
			$now = current_time( 'timestamp' );
			$today_start = strtotime( 'today', $now );

			foreach ( $raw_events as $event ) {
				$next_date = $this->get_next_occurrence( $event );
				
				$next_ts = strtotime( $next_date );
				
				if ( 'yes' === $settings['show_upcoming_only'] ) {
					if ( $next_ts < $today_start ) {
						continue;
					}
				}

				$event_item = clone $event;
				$event_item->computed_start_date = $next_date;
				$processed_events[] = $event_item;
			}
			
			if ( empty( $orderby ) || $orderby === 'start_date' ) {
				usort( $processed_events, function( $a, $b ) use ( $order ) {
					$t1 = strtotime( $a->computed_start_date );
					$t2 = strtotime( $b->computed_start_date );
					if ( $t1 == $t2 ) return 0;
					$result = ( $t1 < $t2 ) ? -1 : 1;
					return ( $order === 'DESC' ) ? -$result : $result;
				});
			} elseif ( $orderby === 'title' ) {
				usort( $processed_events, function( $a, $b ) use ( $order ) {
					$result = strcasecmp( $a->title, $b->title );
					return ( $order === 'DESC' ) ? -$result : $result;
				});
			}

			$total_events = count( $processed_events );
			$events = array_slice( $processed_events, $offset, $per_page );
		}
		
		$max_pages = $per_page > 0 ? ceil( $total_events / $per_page ) : 1;
		
		$default_view = isset( $settings['default_view'] ) ? $settings['default_view'] : 'grid';
		$current_view = isset( $_GET['hbl_view'] ) ? sanitize_text_field( $_GET['hbl_view'] ) : $default_view;
		if ( ! in_array( $current_view, array( 'grid', 'list' ), true ) ) {
			$current_view = 'grid';
		}
		
		$current_sort = isset( $_GET['hbl_sort'] ) ? sanitize_text_field( $_GET['hbl_sort'] ) : '';
		
		$base_url = strtok( $_SERVER['REQUEST_URI'], '?' );
		$query_params = $_GET;
		unset( $query_params['paged'] );
		?>
		<div class="hbl-events-widget">
			<?php if ( 'yes' === $settings['show_header'] ) : ?>
				<div class="hbl-events-header">
					<div class="hbl-events-header-content">
						<h1 class="hbl-events-title"><?php echo esc_html( $category->name ); ?></h1>
						<?php if ( 'yes' === $settings['show_count'] ) : ?>
							<span class="hbl-events-count"><?php echo esc_html( $total_events ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( ! empty( $category->description ) ) : ?>
						<p class="hbl-events-desc"><?php echo esc_html( $category->description ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( 'yes' === $settings['show_search'] ) : ?>
				<div class="hbl-widget-search-wrap">
					<form role="search" method="get" class="hbl-widget-search-form" action="">
						<?php 
						foreach ( $_GET as $key => $val ) {
							if ( 'hbl_event_search' === $key ) continue;
							if ( 'paged' === $key ) continue;
							if ( is_array( $val ) ) {
								foreach ( $val as $k => $v ) {
									echo '<input type="hidden" name="' . esc_attr( $key ) . '[' . esc_attr( $k ) . ']" value="' . esc_attr( $v ) . '">';
								}
							} else {
								echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '">';
							}
						}
						?>
						<input type="text" name="hbl_event_search" class="hbl-widget-search-input" placeholder="<?php esc_attr_e( 'Search events...', 'hbl' ); ?>" value="<?php echo isset( $_GET['hbl_event_search'] ) ? esc_attr( $_GET['hbl_event_search'] ) : ''; ?>">
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
							<select id="hbl-sort-select-<?php echo esc_attr( $this->get_id() ); ?>" class="hbl-sort-select" onchange="hblSortChange(this)">
								<option value="" <?php selected( $current_sort, '' ); ?>><?php esc_html_e( 'Upcoming', 'hbl' ); ?></option>
								<option value="title_asc" <?php selected( $current_sort, 'title_asc' ); ?>><?php esc_html_e( 'Name (A-Z)', 'hbl' ); ?></option>
								<option value="title_desc" <?php selected( $current_sort, 'title_desc' ); ?>><?php esc_html_e( 'Name (Z-A)', 'hbl' ); ?></option>
								<option value="date_desc" <?php selected( $current_sort, 'date_desc' ); ?>><?php esc_html_e( 'Latest First', 'hbl' ); ?></option>
								<option value="date_asc" <?php selected( $current_sort, 'date_asc' ); ?>><?php esc_html_e( 'Earliest First', 'hbl' ); ?></option>
							</select>
						</div>
						<script>
						function hblSortChange(el) {
							var url = new URL(window.location.href);
							if (el.value) {
								url.searchParams.set('hbl_sort', el.value);
							} else {
								url.searchParams.delete('hbl_sort');
							}
							url.searchParams.delete('paged');
							window.location.href = url.toString();
						}
						</script>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $events ) ) : ?>
				<div class="hbl-events-grid hbl-view-<?php echo esc_attr( $current_view ); ?>">
					<?php foreach ( $events as $event ) :
						$event_id = $event->id;
						$original_start_date = $event->start_date;
						$start_date = isset( $event->computed_start_date ) ? $event->computed_start_date : $this->get_next_occurrence( $event );
						$venue_name = $event->venue;
						$event_url = function_exists( 'hbl_events_db' ) ? hbl_events_db()->get_event_url( $event ) : '#';
						$thumbnail_url = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'medium_large' ) : '';
						$event_color = $event->event_color ?: '#008080';
					?>
						<div class="hbl-event-card">
							<?php if ( 'yes' === $settings['show_image'] ) : ?>
								<a href="<?php echo esc_url( $event_url ); ?>" class="hbl-event-image-link">
									<?php if ( $thumbnail_url ) : ?>
										<div class="hbl-event-image" style="background-image: url('<?php echo esc_url( $thumbnail_url ); ?>');">
									<?php else : ?>
										<div class="hbl-event-image hbl-event-no-image" style="background-color: <?php echo esc_attr( $event_color ); ?>;">
											<svg width="40" height="40" viewBox="0 0 24 24" fill="none">
												<rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/>
												<line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
												<line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
												<line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="1.5"/>
											</svg>
									<?php endif; ?>
										<?php if ( 'yes' === $settings['show_date'] && $start_date ) : ?>
											<div class="hbl-event-date">
												<span class="hbl-event-date-day"><?php echo esc_html( date( 'd', strtotime( $start_date ) ) ); ?></span>
												<span class="hbl-event-date-month"><?php echo esc_html( date( 'M', strtotime( $start_date ) ) ); ?></span>
											</div>
										<?php endif; ?>
										</div>
								</a>
							<?php endif; ?>
							
							<div class="hbl-event-content">
								<h3 class="hbl-event-title">
									<a href="<?php echo esc_url( $event_url ); ?>"><?php echo esc_html( $event->title ); ?></a>
								</h3>
								
								<?php if ( 'yes' === $settings['show_venue'] && ! empty( $venue_name ) ) : ?>
									<div class="hbl-event-venue">
										<svg width="14" height="14" viewBox="0 0 24 24" fill="none">
											<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 5.03 7.03 1 12 1S21 5.03 21 10Z" stroke="currentColor" stroke-width="2"/>
											<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
										</svg>
										<span><?php echo esc_html( $venue_name ); ?></span>
									</div>
								<?php endif; ?>
								
								<a href="<?php echo esc_url( $event_url ); ?>" class="hbl-event-link">
									<?php esc_html_e( 'View Event', 'hbl' ); ?>
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none">
										<path d="M5 12H19M19 12L12 5M19 12L12 19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<?php if ( 'yes' === $settings['show_pagination'] && $max_pages > 1 ) : ?>
					<div class="hbl-events-pagination">
						<?php
						echo paginate_links( array(
							'total'     => $max_pages,
							'current'   => $paged,
							'prev_text' => '←',
							'next_text' => '→',
						) );
						?>
					</div>
				<?php endif; ?>

			<?php else : ?>
				<div class="hbl-events-empty">
					<svg width="48" height="48" viewBox="0 0 24 24" fill="none">
						<rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
						<line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						<line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						<line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
					</svg>
					<p><?php esc_html_e( 'No events found in this category.', 'hbl' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		
		<script>
		jQuery(document).ready(function($) {
			var widgetId = '<?php echo esc_js( $this->get_id() ); ?>';
			var $container = $('.elementor-element-' + widgetId + ' .hbl-events-widget');
			var $contentParams = {
				category_id: '<?php echo $category ? $category->term_id : 0; ?>',
				nonce: '<?php echo wp_create_nonce( 'hbl_nonce' ); ?>',
				action: 'hbl_get_events'
			};
			
			function fetchEvents(params) {
				$container.css('opacity', '0.5');
				
				$.post('<?php echo admin_url( 'admin-ajax.php' ); ?>', params, function(response) {
					$container.css('opacity', '1');
					if (response.success) {
						
						$container.find('.hbl-events-grid, .hbl-events-pagination, .hbl-events-empty').remove();
						
						var $insertAfter = $container.find('.hbl-widget-toolbar');
						if ($insertAfter.length === 0) $insertAfter = $container.find('.hbl-widget-search-wrap');
						if ($insertAfter.length === 0) $insertAfter = $container.find('.hbl-events-header');
						
						if ($insertAfter.length > 0) {
							$insertAfter.after(response.data.html);
						} else {
							$container.append(response.data.html);
						}
						
					} else {
						console.error('Error loading events');
					}
				});
			}

			$container.on('click', '.hbl-events-pagination a', function(e) {
				e.preventDefault();
				var url = $(this).attr('href');
				var paged = 1;
				var match = url.match(/page\/(\d+)/);
				if (match) {
					paged = match[1];
				} else {
					match = url.match(/paged=(\d+)/);
					if (match) paged = match[1];
				}
				
				var params = $.extend({}, $contentParams, {
					paged: paged,
					search: $container.find('input[name="hbl_event_search"]').val(),
					sort: $('#hbl-sort-select-' + widgetId).val(),
					view: $container.find('.hbl-view-btn.active').hasClass('list') ? 'list' : 'grid'
				});
				
				fetchEvents(params);
			});
			
			$('#hbl-sort-select-' + widgetId).removeAttr('onchange').off('change').on('change', function(e) {
				var params = $.extend({}, $contentParams, {
					paged: 1,
					search: $container.find('input[name="hbl_event_search"]').val(),
					sort: $(this).val(),
					view: $container.find('.hbl-view-btn.active').hasClass('list') ? 'list' : 'grid'
				});
				fetchEvents(params);
			});
			
			$container.on('submit', '.hbl-widget-search-form', function(e) {
				e.preventDefault();
				var params = $.extend({}, $contentParams, {
					paged: 1,
					search: $(this).find('input[name="hbl_event_search"]').val(),
					sort: $('#hbl-sort-select-' + widgetId).val(),
					view: $container.find('.hbl-view-btn.active').hasClass('list') ? 'list' : 'grid'
				});
				fetchEvents(params);
			});
		});
		</script>
		<?php
	}
}

