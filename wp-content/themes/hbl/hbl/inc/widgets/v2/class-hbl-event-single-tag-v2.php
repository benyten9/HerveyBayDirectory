<?php
/**
 * HBL Event Single Tag V2 Widget
 *
 * Displays events from a tag (auto-detected from URL) using V2 design.
 *
 * @package HBL
 * @since 2.0.0
 */

namespace HBL\Widgets\V2;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Event_Single_Tag_V2 extends Widget_Base {

	public function get_name() {
		return 'hbl-event-single-tag-v2';
	}

	public function get_title() {
		return esc_html__( 'HBL Event Single Tag V2', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-tags';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'event', 'tag', 'archive', 'v2' );
	}

	/**
	 * Detect current event tag from URL / query vars.
	 */
	private function get_current_term() {
		// 1. Queried object — works when WP correctly routes the taxonomy archive
		//    (including Elementor Theme Builder archive templates)
		$queried = get_queried_object();
		if ( $queried instanceof \WP_Term && $queried->taxonomy === 'event_tag' ) {
			return $queried;
		}

		// 2. WP query var — set whenever 'query_var' => true and URL is matched
		$tag_slug = get_query_var( 'event_tag' );
		if ( $tag_slug ) {
			$term = get_term_by( 'slug', $tag_slug, 'event_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		// 3. URL path detection — /events/tag/{slug}/
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$url_path    = trim( wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
		$path_parts  = explode( '/', $url_path );

		// Check for /events/tag/{slug}/ pattern
		$events_index = array_search( 'events', $path_parts, true );
		if ( $events_index !== false && isset( $path_parts[ $events_index + 1 ] ) && $path_parts[ $events_index + 1 ] === 'tag' ) {
			if ( isset( $path_parts[ $events_index + 2 ] ) ) {
				$term = get_term_by( 'slug', $path_parts[ $events_index + 2 ], 'event_tag' );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
			}
		}

		// 4. Generic 'tag' segment anywhere in path
		foreach ( $path_parts as $index => $part ) {
			if ( $part === 'tag' && isset( $path_parts[ $index + 1 ] ) ) {
				$term = get_term_by( 'slug', $path_parts[ $index + 1 ], 'event_tag' );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
			}
		}

		// 5. Last segment fallback
		$last_segment = end( $path_parts );
		if ( ! empty( $last_segment ) ) {
			$term = get_term_by( 'slug', $last_segment, 'event_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		// 6. GET param fallback
		if ( isset( $_GET['event_tag'] ) ) {
			$term = get_term_by( 'slug', sanitize_text_field( $_GET['event_tag'] ), 'event_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		// Editor preview — show first available tag
		if ( \Elementor\Plugin::$instance->preview->is_preview_mode() || \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			$terms = get_terms( array(
				'taxonomy'   => 'event_tag',
				'hide_empty' => false,
				'number'     => 1,
			) );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				return $terms[0];
			}
		}

		return null;
	}

	/**
	 * Get events that have the given tag ID in their tags column.
	 *
	 * @param int $tag_id WP term ID for the event_tag.
	 * @return array Raw event objects.
	 */
	private function get_raw_events_by_tag( $tag_id ) {
		global $wpdb;

		if ( ! function_exists( 'hbl_events_db' ) ) {
			return array();
		}

		$table = hbl_events_db()->get_table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE status = 'publish'
				AND (
					tags = %s
					OR tags LIKE %s
					OR tags LIKE %s
					OR tags LIKE %s
				)",
				(string) $tag_id,
				$wpdb->esc_like( $tag_id . ',' ) . '%',
				'%' . $wpdb->esc_like( ',' . $tag_id . ',' ) . '%',
				'%' . $wpdb->esc_like( ',' . $tag_id )
			)
		);
	}

	/**
	 * Get the next occurrence date of an event.
	 */
	private function get_next_occurrence( $event ) {
		$frequency   = $event->event_frequency ?? 'once';
		$start_date  = $event->start_date;
		$now         = current_time( 'timestamp' );
		$today_start = strtotime( 'today', $now );
		$event_start = strtotime( $start_date );
		$event_time  = date( 'H:i:s', $event_start );

		if ( $event_start >= $now ) {
			return $start_date;
		}

		if ( ! in_array( $frequency, array( 'weekly', 'monthly', 'recurring', 'multi_day' ), true ) ) {
			return $start_date;
		}

		$recurrence_days     = $event->recurrence_days ?? '';
		$recurrence_week     = $event->recurrence_week ?? '';
		$recurrence_interval = isset( $event->recurrence_interval ) ? max( 1, intval( $event->recurrence_interval ) ) : 1;

		$normalize_day = function( $d ) {
			$d   = strtolower( trim( $d ) );
			$map = array(
				'monday' => 'mon', 'tuesday' => 'tue', 'wednesday' => 'wed',
				'thursday' => 'thu', 'friday' => 'fri', 'saturday' => 'sat', 'sunday' => 'sun',
			);
			return isset( $map[ $d ] ) ? $map[ $d ] : substr( $d, 0, 3 );
		};

		if ( 'weekly' === $frequency && ! empty( $recurrence_days ) ) {
			$days = array_map( $normalize_day, explode( ',', $recurrence_days ) );
			for ( $i = 0; $i < 60; $i++ ) {
				$check_ts    = strtotime( "+{$i} days", $today_start );
				$day_of_week = strtolower( date( 'D', $check_ts ) );
				if ( in_array( $day_of_week, $days, true ) ) {
					$orig_week_start = strtotime( 'last monday', $event_start + 86400 );
					$curr_week_start = strtotime( 'last monday', $check_ts + 86400 );
					$weeks_diff      = round( ( $curr_week_start - $orig_week_start ) / ( 7 * 24 * 60 * 60 ) );
					if ( $weeks_diff % $recurrence_interval === 0 ) {
						return date( 'Y-m-d', $check_ts ) . ' ' . $event_time;
					}
				}
			}
		} elseif ( 'monthly' === $frequency && ! empty( $recurrence_days ) ) {
			$day_map        = array( 'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6 );
			$day_name       = $normalize_day( $recurrence_days );
			if ( isset( $day_map[ $day_name ] ) ) {
				$target_day_num = $day_map[ $day_name ];
				$weeks          = ! empty( $recurrence_week ) ? array_map( 'trim', explode( ',', $recurrence_week ) ) : array();
				for ( $i = 0; $i < 12; $i++ ) {
					$check_month_ts = strtotime( "+{$i} month", $today_start );
					$year           = date( 'Y', $check_month_ts );
					$month          = date( 'n', $check_month_ts );
					$days_in_month  = date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
					$occurrence     = 0;
					for ( $day = 1; $day <= $days_in_month; $day++ ) {
						$date_ts = mktime( 0, 0, 0, $month, $day, $year );
						if ( date( 'w', $date_ts ) == $target_day_num ) {
							$occurrence++;
							if ( in_array( $occurrence, $weeks ) && $date_ts >= $today_start ) {
								return date( 'Y-m-d', $date_ts ) . ' ' . $event_time;
							}
						}
					}
				}
			}
		} elseif ( 'multi_day' === $frequency ) {
			$end_date_ts = ! empty( $event->end_date ) ? strtotime( $event->end_date ) : $event_start;
			if ( $end_date_ts >= $today_start ) {
				$days = ! empty( $recurrence_days ) ? array_map( $normalize_day, explode( ',', $recurrence_days ) ) : array();
				for ( $i = 0; $i < 60; $i++ ) {
					$curr_ts = strtotime( "+{$i} days", $today_start );
					if ( $curr_ts > $end_date_ts ) break;
					if ( empty( $days ) ) {
						return date( 'Y-m-d', $curr_ts ) . ' ' . $event_time;
					}
					if ( in_array( strtolower( date( 'D', $curr_ts ) ), $days, true ) ) {
						return date( 'Y-m-d', $curr_ts ) . ' ' . $event_time;
					}
				}
			}
		}

		return $start_date;
	}

	/**
	 * Query events for the given tag term.
	 *
	 * @param int    $tag_id       Event tag term ID.
	 * @param string $keyword      Keyword filter.
	 * @param string $letter       A–Z letter filter.
	 * @param string $sort         Sort value.
	 * @param bool   $upcoming_only Only show upcoming events.
	 * @return array Processed event objects with computed_start_date.
	 */
	private function get_events_for_term( $tag_id, $keyword = '', $letter = '', $sort = 'recommended', $upcoming_only = false ) {
		$raw_events  = $this->get_raw_events_by_tag( $tag_id );
		$now         = current_time( 'timestamp' );
		$today_start = strtotime( 'today', $now );
		$processed   = array();

		foreach ( $raw_events as $event ) {
			// Keyword filter
			if ( ! empty( $keyword ) ) {
				$haystack = strtolower( $event->title . ' ' . ( $event->venue ?? '' ) );
				if ( strpos( $haystack, strtolower( $keyword ) ) === false ) {
					continue;
				}
			}

			$next_date = $this->get_next_occurrence( $event );
			$next_ts   = strtotime( $next_date );

			if ( $upcoming_only && $next_ts < $today_start ) {
				continue;
			}

			// A–Z letter filter on title
			if ( ! empty( $letter ) ) {
				if ( strtoupper( substr( $event->title, 0, 1 ) ) !== $letter ) {
					continue;
				}
			}

			$item                      = clone $event;
			$item->computed_start_date = $next_date;
			$processed[]               = $item;
		}

		// Sort
		if ( 'a-z' === $sort ) {
			usort( $processed, function( $a, $b ) { return strcasecmp( $a->title, $b->title ); } );
		} elseif ( 'z-a' === $sort ) {
			usort( $processed, function( $a, $b ) { return strcasecmp( $b->title, $a->title ); } );
		} elseif ( 'newest' === $sort ) {
			usort( $processed, function( $a, $b ) {
				return strtotime( $b->computed_start_date ) - strtotime( $a->computed_start_date );
			} );
		} else {
			// Default: upcoming (soonest first)
			usort( $processed, function( $a, $b ) {
				return strtotime( $a->computed_start_date ) - strtotime( $b->computed_start_date );
			} );
		}

		return $processed;
	}

	/**
	 * Render a single event card.
	 */
	private function render_event_card( $event, $settings ) {
		$start_date    = $event->computed_start_date ?? $event->start_date;
		$event_url     = function_exists( 'hbl_events_db' ) ? hbl_events_db()->get_event_url( $event ) : '#';
		$thumbnail_url = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'medium_large' ) : '';
		$event_color   = ! empty( $event->event_color ) ? $event->event_color : '#008080';
		$venue_name    = $event->venue ?? '';
		$fallback_url  = ! empty( $settings['fallback_logo']['url'] ) ? $settings['fallback_logo']['url'] : '';
		$image_src     = $thumbnail_url ?: $fallback_url;
		?>
		<div class="hbl-v2-event-card">
			<a href="<?php echo esc_url( $event_url ); ?>" class="hbl-v2-event-image-link">
				<div class="hbl-v2-event-image<?php echo ! $image_src ? ' hbl-v2-event-no-image' : ''; ?>"<?php echo ! $image_src ? ' style="background-color:' . esc_attr( $event_color ) . ';"' : ''; ?>>
					<?php if ( $image_src ) : ?>
						<img src="<?php echo esc_url( $image_src ); ?>" alt="<?php echo esc_attr( $event->title ); ?>" class="hbl-v2-event-img" loading="lazy">
					<?php else : ?>
						<svg width="40" height="40" viewBox="0 0 24 24" fill="none">
							<rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/>
							<line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
							<line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
							<line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="1.5"/>
						</svg>
					<?php endif; ?>
					<?php if ( $start_date ) : ?>
						<div class="hbl-v2-event-date-badge">
							<span class="hbl-v2-event-date-day"><?php echo esc_html( date( 'd', strtotime( $start_date ) ) ); ?></span>
							<span class="hbl-v2-event-date-month"><?php echo esc_html( date( 'M', strtotime( $start_date ) ) ); ?></span>
						</div>
					<?php endif; ?>
				</div>
			</a>
			<div class="hbl-v2-event-content">
				<h3 class="hbl-v2-event-title">
					<a href="<?php echo esc_url( $event_url ); ?>"><?php echo esc_html( $event->title ); ?></a>
				</h3>
				<?php if ( ! empty( $venue_name ) ) : ?>
					<div class="hbl-v2-event-venue">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none">
							<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 5.03 7.03 1 12 1S21 5.03 21 10Z" stroke="currentColor" stroke-width="2"/>
							<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
						</svg>
						<span><?php echo esc_html( $venue_name ); ?></span>
					</div>
				<?php endif; ?>
				<a href="<?php echo esc_url( $event_url ); ?>" class="hbl-v2-event-link">
					<?php esc_html_e( 'View Event', 'hbl' ); ?>
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none">
						<path d="M5 12H19M19 12L12 5M19 12L12 19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</a>
			</div>
		</div>
		<?php
	}

	protected function register_controls() {

		// Display Options
		$this->start_controls_section(
			'section_display',
			array( 'label' => esc_html__( 'Display Options', 'hbl' ) )
		);

		$this->add_control(
			'listings_per_page',
			array(
				'label'   => esc_html__( 'Events Per Page', 'hbl' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 20,
				'min'     => 1,
				'max'     => 100,
			)
		);

		$this->add_control(
			'enable_pagination',
			array(
				'label'        => esc_html__( 'Enable Pagination', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_term_header',
			array(
				'label'        => esc_html__( 'Show Tag Header', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_alphabetical_filter',
			array(
				'label'        => esc_html__( 'Show A-Z Filter', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_keyword_search',
			array(
				'label'        => esc_html__( 'Show Keyword Search', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_sort_dropdown',
			array(
				'label'        => esc_html__( 'Show Sort Dropdown', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_view_toggle',
			array(
				'label'        => esc_html__( 'Show View Toggle', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'default_view',
			array(
				'label'   => esc_html__( 'Default View', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'grid',
				'options' => array(
					'grid' => esc_html__( 'Grid', 'hbl' ),
					'list' => esc_html__( 'List', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'grid_view_icon',
			array(
				'label'     => esc_html__( 'Grid View Icon', 'hbl' ),
				'type'      => Controls_Manager::MEDIA,
				'default'   => array( 'url' => get_template_directory_uri() . '/assets/images/grid-view-icon.svg' ),
				'condition' => array( 'show_view_toggle' => 'yes' ),
			)
		);

		$this->add_control(
			'list_view_icon',
			array(
				'label'     => esc_html__( 'List View Icon', 'hbl' ),
				'type'      => Controls_Manager::MEDIA,
				'default'   => array( 'url' => get_template_directory_uri() . '/assets/images/list-view-icon.svg' ),
				'condition' => array( 'show_view_toggle' => 'yes' ),
			)
		);

		$this->add_control(
			'show_upcoming_only',
			array(
				'label'        => esc_html__( 'Show Upcoming Only', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->end_controls_section();

		// Fallback Image
		$this->start_controls_section(
			'section_fallback_logo',
			array( 'label' => esc_html__( 'Fallback Image', 'hbl' ) )
		);
		$this->add_control(
			'fallback_logo',
			array(
				'label' => esc_html__( 'Default Event Image', 'hbl' ),
				'type'  => Controls_Manager::MEDIA,
			)
		);
		$this->end_controls_section();
	}

	protected function render() {
		$settings     = $this->get_settings_for_display();
		$widget_id    = $this->get_id();
		$current_term = $this->get_current_term();

		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$letter = isset( $_GET['letter'] ) ? strtoupper( sanitize_text_field( $_GET['letter'] ) ) : '';

		$default_view  = isset( $settings['default_view'] ) ? $settings['default_view'] : 'grid';
		$show_sort     = isset( $settings['show_sort_dropdown'] ) && 'yes' === $settings['show_sort_dropdown'];
		$show_toggle   = isset( $settings['show_view_toggle'] ) && 'yes' === $settings['show_view_toggle'];
		$show_search   = isset( $settings['show_keyword_search'] ) && 'yes' === $settings['show_keyword_search'];
		$upcoming_only = isset( $settings['show_upcoming_only'] ) && 'yes' === $settings['show_upcoming_only'];
		$per_page      = isset( $settings['listings_per_page'] ) ? absint( $settings['listings_per_page'] ) : 20;

		// Initial server-side render
		$all_events = array();
		if ( $current_term ) {
			$all_events = $this->get_events_for_term( $current_term->term_id, '', $letter, 'recommended', $upcoming_only );
		}

		$total_events = count( $all_events );
		$max_pages    = $per_page > 0 ? ceil( $total_events / $per_page ) : 1;
		$offset       = ( $paged - 1 ) * $per_page;
		$events       = array_slice( $all_events, $offset, $per_page );

		$ajax_settings = array(
			'listings_per_page'  => $per_page,
			'enable_pagination'  => $settings['enable_pagination'] ?? 'yes',
			'show_upcoming_only' => $settings['show_upcoming_only'] ?? 'no',
			'fallback_logo'      => $settings['fallback_logo'] ?? array(),
		);
		?>
		<div class="hbl-v2-widget hbl-v2-event-tag-widget"
		     data-widget-id="<?php echo esc_attr( $widget_id ); ?>"
		     data-default-view="<?php echo esc_attr( $default_view ); ?>"
		     data-tag-id="<?php echo $current_term ? esc_attr( $current_term->term_id ) : ''; ?>"
		     data-widget-settings="<?php echo esc_attr( wp_json_encode( $ajax_settings ) ); ?>">

			<?php if ( $current_term && isset( $settings['show_term_header'] ) && 'yes' === $settings['show_term_header'] ) : ?>
				<div class="hbl-v2-term-header">
					<h1 class="hbl-v2-term-title"><?php echo esc_html( $current_term->name ); ?></h1>
					<?php if ( ! empty( $current_term->description ) ) : ?>
						<p class="hbl-v2-term-description"><?php echo esc_html( $current_term->description ); ?></p>
					<?php endif; ?>
					<span class="hbl-v2-term-count">
						<?php printf( esc_html( _n( '%d event', '%d events', $total_events, 'hbl' ) ), $total_events ); ?>
					</span>
				</div>
			<?php endif; ?>

			<?php if ( ! $current_term ) : ?>
				<div class="hbl-v2-notice">
					<p><?php esc_html_e( 'No event tag detected. Please navigate to an event tag page.', 'hbl' ); ?></p>
				</div>
			<?php else : ?>

			<?php if ( isset( $settings['show_alphabetical_filter'] ) && 'yes' === $settings['show_alphabetical_filter'] ) : ?>
				<div class="hbl-v2-alphabetical-filter">
					<div class="hbl-v2-filter-buttons">
						<?php foreach ( range( 'A', 'Z' ) as $alpha_letter ) :
							$is_active  = ( $letter === $alpha_letter );
							$letter_url = $is_active ? remove_query_arg( 'letter' ) : add_query_arg( 'letter', $alpha_letter );
						?>
							<a href="<?php echo esc_url( $letter_url ); ?>"
							   class="hbl-v2-letter-btn <?php echo $is_active ? 'active' : ''; ?>"
							   data-letter="<?php echo esc_attr( $alpha_letter ); ?>">
								<?php echo esc_html( $alpha_letter ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $show_search || $show_sort || $show_toggle ) : ?>
				<div class="hbl-v2-filters-bar">
					<div class="hbl-v2-filters-left">
						<span class="hbl-v2-filters-label"><?php esc_html_e( 'Filters', 'hbl' ); ?></span>
						<?php if ( $show_search ) : ?>
							<div class="hbl-v2-keyword-search">
								<i class="bi bi-search hbl-v2-keyword-search-icon"></i>
								<input type="text" class="hbl-v2-keyword-search-input" placeholder="<?php esc_attr_e( 'Search by Keyword...', 'hbl' ); ?>" autocomplete="off">
								<button type="button" class="hbl-v2-keyword-clear" style="display:none;"><i class="bi bi-x-lg"></i></button>
							</div>
						<?php endif; ?>
						<?php if ( $show_sort ) : ?>
							<div class="hbl-v2-sort-wrapper hbl-v2-filter-dropdown">
								<div class="hbl-v2-sort-container">
									<button type="button" class="hbl-v2-sort-trigger" aria-expanded="false" aria-haspopup="listbox">
										<span class="hbl-v2-sort-label"><?php esc_html_e( 'Sort By', 'hbl' ); ?></span>
										<span class="hbl-v2-sort-chevron"><svg width="8" height="4" viewBox="0 0 8 4" fill="none"><path d="M1 0.5L4 3.5L7 0.5" stroke="#F9532A" stroke-width="2"/></svg></span>
									</button>
								</div>
								<div class="hbl-v2-sort-dropdown" role="listbox" aria-hidden="true" style="display:none;">
									<div class="hbl-v2-sort-list">
										<button type="button" class="hbl-v2-sort-item" data-value="recommended" role="option"><?php esc_html_e( 'Upcoming', 'hbl' ); ?></button>
										<button type="button" class="hbl-v2-sort-item" data-value="a-z" role="option"><?php esc_html_e( 'A–Z', 'hbl' ); ?></button>
										<button type="button" class="hbl-v2-sort-item" data-value="z-a" role="option"><?php esc_html_e( 'Z–A', 'hbl' ); ?></button>
										<button type="button" class="hbl-v2-sort-item" data-value="newest" role="option"><?php esc_html_e( 'Newest', 'hbl' ); ?></button>
									</div>
								</div>
							</div>
						<?php endif; ?>
					</div>
					<?php if ( $show_toggle ) : ?>
						<div class="hbl-v2-filters-right">
							<div class="hbl-v2-view-toggle">
								<span class="hbl-v2-view-label"><?php esc_html_e( 'View as:', 'hbl' ); ?></span>
								<div class="hbl-v2-view-buttons">
									<button type="button" class="hbl-v2-view-btn <?php echo 'grid' === $default_view ? 'active' : ''; ?>" data-view="grid">
										<?php if ( ! empty( $settings['grid_view_icon']['url'] ) ) : ?>
											<img src="<?php echo esc_url( $settings['grid_view_icon']['url'] ); ?>" alt="Grid" class="hbl-v2-view-icon">
										<?php else : ?>
											<i class="bi bi-grid-3x3-gap"></i>
										<?php endif; ?>
									</button>
									<button type="button" class="hbl-v2-view-btn <?php echo 'list' === $default_view ? 'active' : ''; ?>" data-view="list">
										<?php if ( ! empty( $settings['list_view_icon']['url'] ) ) : ?>
											<img src="<?php echo esc_url( $settings['list_view_icon']['url'] ); ?>" alt="List" class="hbl-v2-view-icon">
										<?php else : ?>
											<i class="bi bi-list-ul"></i>
										<?php endif; ?>
									</button>
								</div>
							</div>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="hbl-v2-active-filters" style="display:none;">
				<div class="hbl-v2-active-filters-container"></div>
			</div>

			<div class="hbl-v2-listings-grid <?php echo 'list' === $default_view ? 'list-view' : ''; ?>">
				<?php
				$half         = ! empty( $events ) ? ceil( count( $events ) / 2 ) : 0;
				$left_column  = array_slice( $events, 0, $half );
				$right_column = array_slice( $events, $half );
				?>
				<div class="hbl-v2-left-column">
					<?php if ( ! empty( $left_column ) ) :
						foreach ( $left_column as $event ) :
							$this->render_event_card( $event, $settings );
						endforeach;
					else : ?>
						<div class="hbl-v2-no-results">
							<i class="bi bi-search"></i>
							<p><?php esc_html_e( 'No events found with this tag.', 'hbl' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
				<div class="hbl-v2-right-column">
					<?php foreach ( $right_column as $event ) :
						$this->render_event_card( $event, $settings );
					endforeach; ?>
				</div>
			</div>

			<?php if ( isset( $settings['enable_pagination'] ) && 'yes' === $settings['enable_pagination'] && $max_pages > 1 ) : ?>
				<div class="hbl-v2-pagination">
					<div class="hbl-v2-pagination-info">
						<?php
						$start_item = ( ( $paged - 1 ) * $per_page ) + 1;
						$end_item   = min( $paged * $per_page, $total_events );
						printf( esc_html__( 'Showing %1$d - %2$d of %3$d events', 'hbl' ), $start_item, $end_item, $total_events );
						?>
					</div>
					<div class="hbl-v2-pagination-controls">
						<?php if ( $paged > 1 ) : ?>
							<a href="#" data-page="<?php echo esc_attr( $paged - 1 ); ?>" class="hbl-v2-page-btn hbl-v2-prev-btn hbl-v2-page-link">
								<i class="bi bi-chevron-left"></i><span><?php esc_html_e( 'Previous', 'hbl' ); ?></span>
							</a>
						<?php else : ?>
							<span class="hbl-v2-page-btn hbl-v2-prev-btn disabled"><i class="bi bi-chevron-left"></i><span><?php esc_html_e( 'Previous', 'hbl' ); ?></span></span>
						<?php endif; ?>
						<div class="hbl-v2-page-numbers">
							<?php for ( $i = 1; $i <= $max_pages; $i++ ) :
								if ( $i === 1 || $i === $max_pages || ( $i >= $paged - 2 && $i <= $paged + 2 ) ) : ?>
									<a href="#" data-page="<?php echo esc_attr( $i ); ?>" class="hbl-v2-page-number hbl-v2-page-link <?php echo $i === $paged ? 'active' : ''; ?>"><?php echo esc_html( $i ); ?></a>
								<?php endif;
							endfor; ?>
						</div>
						<?php if ( $paged < $max_pages ) : ?>
							<a href="#" data-page="<?php echo esc_attr( $paged + 1 ); ?>" class="hbl-v2-page-btn hbl-v2-next-btn hbl-v2-page-link">
								<span><?php esc_html_e( 'Next', 'hbl' ); ?></span><i class="bi bi-chevron-right"></i>
							</a>
						<?php else : ?>
							<span class="hbl-v2-page-btn hbl-v2-next-btn disabled"><span><?php esc_html_e( 'Next', 'hbl' ); ?></span><i class="bi bi-chevron-right"></i></span>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
			<?php endif; // current_term check ?>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var widgetId = '<?php echo esc_js( $widget_id ); ?>';
			var $widget  = $('[data-widget-id="' + widgetId + '"]');
			var tagId    = $widget.attr('data-tag-id') || 0;
			var widgetSettingsJSON = $widget.attr('data-widget-settings') || '{}';
			var sortLabels = { 'recommended': '<?php esc_html_e( 'Upcoming', 'hbl' ); ?>', 'a-z': 'A\u2013Z', 'z-a': 'Z\u2013A', 'newest': '<?php esc_html_e( 'Newest', 'hbl' ); ?>' };
			var currentFilters = { keyword: '', letter: '<?php echo esc_js( $letter ); ?>', sort: 'recommended', paged: 1 };

			// ---- Active filters UI ----
			function updateActiveFilters() {
				var tags = [];
				if (currentFilters.keyword) {
					tags.push({ key: 'keyword', label: '<?php esc_html_e( 'Keyword', 'hbl' ); ?>: ' + currentFilters.keyword });
				}
				if (currentFilters.letter) {
					tags.push({ key: 'letter', label: '<?php esc_html_e( 'Letter', 'hbl' ); ?>: ' + currentFilters.letter });
				}
				if (currentFilters.sort && currentFilters.sort !== 'recommended') {
					tags.push({ key: 'sort', label: '<?php esc_html_e( 'Sort', 'hbl' ); ?>: ' + (sortLabels[currentFilters.sort] || currentFilters.sort) });
				}

				var $bar = $widget.find('.hbl-v2-active-filters');
				var $container = $widget.find('.hbl-v2-active-filters-container');
				$container.empty();

				if (tags.length === 0) {
					$bar.slideUp(200);
					return;
				}

				$.each(tags, function(i, tag) {
					var $tag = $('<span class="hbl-v2-active-filter-tag"></span>').text(tag.label);
					var $clear = $('<button type="button" class="hbl-v2-active-filter-tag-clear" aria-label="Remove filter">&times;</button>');
					$clear.on('click', function() { clearFilter(tag.key); });
					$tag.append($clear);
					$container.append($tag);
				});

				// Clear all button
				if (tags.length > 1) {
					var $clearAll = $('<button type="button" class="hbl-v2-active-filter-tag hbl-v2-clear-all-filters"><?php esc_html_e( 'Clear All', 'hbl' ); ?></button>');
					$clearAll.on('click', function() { clearFilter('all'); });
					$container.append($clearAll);
				}

				$bar.slideDown(200);
			}

			function clearFilter(key) {
				if (key === 'all') {
					currentFilters.keyword = '';
					currentFilters.letter  = '';
					currentFilters.sort    = 'recommended';
					$widget.find('.hbl-v2-keyword-search-input').val('');
					$widget.find('.hbl-v2-keyword-clear').hide();
					$widget.find('.hbl-v2-letter-btn').removeClass('active');
					$widget.find('.hbl-v2-sort-label').text('<?php esc_html_e( 'Sort By', 'hbl' ); ?>');
					$widget.find('.hbl-v2-sort-item').removeClass('selected');
				} else if (key === 'keyword') {
					currentFilters.keyword = '';
					$widget.find('.hbl-v2-keyword-search-input').val('');
					$widget.find('.hbl-v2-keyword-clear').hide();
				} else if (key === 'letter') {
					currentFilters.letter = '';
					$widget.find('.hbl-v2-letter-btn').removeClass('active');
				} else if (key === 'sort') {
					currentFilters.sort = 'recommended';
					$widget.find('.hbl-v2-sort-label').text('<?php esc_html_e( 'Sort By', 'hbl' ); ?>');
					$widget.find('.hbl-v2-sort-item').removeClass('selected');
				}
				currentFilters.paged = 1;
				updateActiveFilters();
				applyFilters();
			}

			// Show any pre-set filters from URL on load
			updateActiveFilters();

			// View toggle
			$widget.find('.hbl-v2-view-btn').on('click', function() {
				var view = $(this).data('view');
				$(this).addClass('active').siblings().removeClass('active');
				$widget.find('.hbl-v2-listings-grid').toggleClass('list-view', view === 'list');
			});

			// Keyword search
			var searchTimeout;
			$widget.find('.hbl-v2-keyword-search-input').on('input', function() {
				var keyword = $(this).val().trim();
				clearTimeout(searchTimeout);
				searchTimeout = setTimeout(function() {
					currentFilters.keyword = keyword;
					currentFilters.paged = 1;
					$widget.find('.hbl-v2-keyword-clear').toggle(!!keyword);
					updateActiveFilters();
					applyFilters();
				}, 500);
			});
			$widget.find('.hbl-v2-keyword-clear').on('click', function() {
				$widget.find('.hbl-v2-keyword-search-input').val('');
				currentFilters.keyword = '';
				currentFilters.paged = 1;
				$(this).hide();
				updateActiveFilters();
				applyFilters();
			});

			// Letter filter
			$widget.find('.hbl-v2-letter-btn').on('click', function(e) {
				e.preventDefault();
				var ltr = $(this).data('letter');
				if ($(this).hasClass('active')) {
					currentFilters.letter = '';
					$(this).removeClass('active');
				} else {
					currentFilters.letter = ltr;
					$widget.find('.hbl-v2-letter-btn').removeClass('active');
					$(this).addClass('active');
				}
				currentFilters.paged = 1;
				updateActiveFilters();
				applyFilters();
			});

			// Sort dropdown
			$widget.find('.hbl-v2-sort-trigger').on('click', function() {
				var $dd = $widget.find('.hbl-v2-sort-dropdown');
				var open = $(this).attr('aria-expanded') === 'true';
				open ? $dd.slideUp(200) : $dd.slideDown(200);
				$(this).attr('aria-expanded', !open);
			});
			$widget.find('.hbl-v2-sort-item').on('click', function() {
				currentFilters.sort = $(this).data('value');
				currentFilters.paged = 1;
				var lbl = currentFilters.sort === 'recommended' ? '<?php esc_html_e( 'Sort By', 'hbl' ); ?>' : $(this).text().trim();
				$widget.find('.hbl-v2-sort-label').text(lbl);
				$widget.find('.hbl-v2-sort-dropdown').slideUp(200);
				$widget.find('.hbl-v2-sort-trigger').attr('aria-expanded', 'false');
				$widget.find('.hbl-v2-sort-item').removeClass('selected');
				$(this).addClass('selected');
				updateActiveFilters();
				applyFilters();
			});
			$(document).on('click', function(e) {
				if (!$(e.target).closest('.hbl-v2-sort-wrapper').length) {
					$widget.find('.hbl-v2-sort-dropdown').slideUp(200);
					$widget.find('.hbl-v2-sort-trigger').attr('aria-expanded', 'false');
				}
			});

			// Pagination
			$widget.on('click', '.hbl-v2-page-link', function(e) {
				e.preventDefault();
				currentFilters.paged = parseInt($(this).data('page')) || 1;
				applyFilters();
				$('html,body').animate({ scrollTop: $widget.offset().top - 100 }, 300);
			});

			function applyFilters() {
				$.ajax({
					url: hblV2Ajax.ajaxurl,
					type: 'POST',
					data: {
						action: 'hbl_v2_filter_events_by_tag',
						nonce: hblV2Ajax.nonce,
						widget_id: widgetId,
						tag_id: tagId,
						widget_settings: widgetSettingsJSON,
						keyword: currentFilters.keyword,
						letter: currentFilters.letter,
						sort: currentFilters.sort,
						paged: currentFilters.paged,
						per_page: <?php echo absint( $per_page ); ?>
					},
					beforeSend: function() { $widget.addClass('loading'); },
					success: function(response) {
						if (response.success) {
							if (response.html) {
								$widget.find('.hbl-v2-left-column').html(response.html.left);
								$widget.find('.hbl-v2-right-column').html(response.html.right);
							}
							if (response.pagination_html !== undefined) {
								var $pg = $widget.find('.hbl-v2-pagination');
								if (response.pagination_html) {
									if ($pg.length) {
										$pg.replaceWith(response.pagination_html);
									} else {
										$widget.find('.hbl-v2-listings-grid').after(response.pagination_html);
									}
								} else {
									$pg.remove();
								}
							}
						}
					},
					complete: function() { $widget.removeClass('loading'); }
				});
			}
		});
		</script>
		<?php
	}
}
