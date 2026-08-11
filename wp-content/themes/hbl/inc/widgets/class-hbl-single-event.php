<?php

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Single_Event extends Widget_Base {

	public function get_name() {
		return 'hbl-single-event';
	}

	public function get_title() {
		return esc_html__( 'HBL Single Event', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-calendar';
	}

	public function get_categories() {
		return array( 'hbl-widgets' );
	}

	public function get_keywords() {
		return array( 'event', 'single', 'calendar', 'hbl' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => esc_html__( 'Content Settings', 'hbl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_featured_image',
			array(
				'label'        => esc_html__( 'Show Featured Image', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_organizer',
			array(
				'label'        => esc_html__( 'Show Organizer', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_share_buttons',
			array(
				'label'        => esc_html__( 'Show Share Buttons', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_related',
			array(
				'label'        => esc_html__( 'Show Related Events', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'related_count',
			array(
				'label'     => esc_html__( 'Related Events Count', 'hbl' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 3,
				'min'       => 1,
				'max'       => 6,
				'condition' => array(
					'show_related' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_header',
			array(
				'label' => esc_html__( 'Header Style', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'header_overlay_color',
			array(
				'label'     => esc_html__( 'Header Overlay', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(0, 128, 128, 0.85)',
				'selectors' => array(
					'{{WRAPPER}} .hbl-single-event-header-overlay' => 'background: linear-gradient(135deg, {{VALUE}} 0%, rgba(0,0,0,0.7) 100%);',
				),
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .hbl-single-event-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'label'    => esc_html__( 'Title Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-single-event-title',
			)
		);

		$this->add_control(
			'accent_color',
			array(
				'label'     => esc_html__( 'Accent Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-single-event-date-badge' => 'background: {{VALUE}};',
					'{{WRAPPER}} .hbl-single-event-cta-btn' => 'background: {{VALUE}};',
					'{{WRAPPER}} .hbl-single-event-section-title::before' => 'background: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_content',
			array(
				'label' => esc_html__( 'Content Style', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'content_bg_color',
			array(
				'label'     => esc_html__( 'Content Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .hbl-single-event-content' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'section_title_color',
			array(
				'label'     => esc_html__( 'Section Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#1a1a2e',
				'selectors' => array(
					'{{WRAPPER}} .hbl-single-event-section-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#4a5568',
				'selectors' => array(
					'{{WRAPPER}} .hbl-single-event-description' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'primary_color',
			array(
				'label'     => esc_html__( 'Primary Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-single-event-detail-icon' => 'background: linear-gradient(135deg, {{VALUE}} 0%, color-mix(in srgb, {{VALUE}} 80%, #000) 100%);',
					'{{WRAPPER}} .hbl-single-event-share-btn:hover' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	private function get_event_from_db( $event_id ) {
		if ( function_exists( 'hbl_events_db' ) ) {
			return hbl_events_db()->get( $event_id );
		}
		return null;
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
		'5' => '5th',
	);

	private function get_recurrence_description( $frequency, $interval, $days, $weeks ) {
		if ( empty( $frequency ) || $frequency === 'once' ) {
			return __( 'One-off event', 'hbl' );
		}

		if ( $frequency === 'recurring' ) {
			return __( 'Ongoing / recurring event', 'hbl' );
		}

		if ( $frequency === 'multi_day' ) {
			if ( ! empty( $days ) ) {
				$day_list = explode( ',', $days );
				$day_names = array();
				foreach ( $day_list as $day ) {
					$day = strtolower( trim( $day ) );
					if ( isset( $this->day_labels[ $day ] ) ) {
						$day_names[] = $this->day_labels[ $day ];
					}
				}
				if ( count( $day_names ) === 1 ) {
					return sprintf( __( 'Multi-day event, open %s', 'hbl' ), $day_names[0] );
				} elseif ( count( $day_names ) > 1 ) {
					$last = array_pop( $day_names );
					return sprintf( __( 'Multi-day event, open %s and %s', 'hbl' ), implode( ', ', $day_names ), $last );
				}
			}
			return __( 'Multi-day event', 'hbl' );
		}

		$parts = array();

		if ( $frequency === 'weekly' ) {
			if ( $interval == 2 ) {
				$prefix = __( 'Every second', 'hbl' );
			} else {
				$prefix = __( 'Every', 'hbl' );
			}

			if ( ! empty( $days ) ) {
				$day_list = explode( ',', $days );
				$day_names = array();
				foreach ( $day_list as $day ) {
					$day = trim( $day );
					if ( isset( $this->day_labels[ $day ] ) ) {
						$day_names[] = $this->day_labels[ $day ];
					}
				}
				if ( count( $day_names ) === 1 ) {
					return $prefix . ' ' . $day_names[0];
				} elseif ( count( $day_names ) > 1 ) {
					$last = array_pop( $day_names );
					return $prefix . ' ' . implode( ', ', $day_names ) . ' ' . __( 'and', 'hbl' ) . ' ' . $last;
				}
			}
			return __( 'Weekly', 'hbl' );

		} elseif ( $frequency === 'monthly' ) {
			$week_parts = array();
			$day_name = '';

			if ( ! empty( $weeks ) ) {
				$week_list = explode( ',', $weeks );
				foreach ( $week_list as $week ) {
					$week = trim( $week );
					if ( isset( $this->week_labels[ $week ] ) ) {
						$week_parts[] = $this->week_labels[ $week ];
					}
				}
			}

			if ( ! empty( $days ) ) {
				$day = trim( $days );
				if ( isset( $this->day_labels[ $day ] ) ) {
					$day_name = $this->day_labels[ $day ];
				}
			}

			if ( ! empty( $week_parts ) && ! empty( $day_name ) ) {
				if ( count( $week_parts ) === 1 ) {
					return sprintf( __( 'Every %s %s of the month', 'hbl' ), $week_parts[0], $day_name );
				} else {
					$last_week = array_pop( $week_parts );
					return sprintf( 
						__( 'Every %s and %s %s of the month', 'hbl' ), 
						implode( ', ', $week_parts ), 
						$last_week, 
						$day_name 
					);
				}
			} elseif ( ! empty( $day_name ) ) {
				return sprintf( __( 'Monthly on %s', 'hbl' ), $day_name );
			}
			return __( 'Monthly', 'hbl' );
		}

		return '';
	}

	private function get_related_events( $event_id, $category_id, $limit = 3 ) {
		if ( ! function_exists( 'hbl_events_db' ) ) {
			return array();
		}

		$args = array(
			'status'   => 'publish',
			'upcoming' => true,
			'orderby'  => 'start_date',
			'order'    => 'ASC',
			'limit'    => $limit + 1,
		);

		if ( $category_id ) {
			$args['category_id'] = $category_id;
		}

		$events = hbl_events_db()->get_events( $args );

		$events = array_filter( $events, function( $e ) use ( $event_id ) {
			return $e->id != $event_id;
		} );

		return array_slice( $events, 0, $limit );
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		$event = null;
		$event_slug = get_query_var( 'hbl_event_slug' );
		$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;

		if ( $event_slug ) {
			$event = hbl_events_db()->get_by_slug( sanitize_title( $event_slug ) );
		} elseif ( $event_id ) {
			$event = $this->get_event_from_db( $event_id );
		}
		
		if ( ! $event ) {
			echo '<div class="hbl-single-event-notice">';
			echo '<p>' . esc_html__( 'Event not found. Please check the URL and try again.', 'hbl' ) . '</p>';
			echo '</div>';
			return;
		}
		
		$event_id = $event->id;
		
		$title = $event->title;
		$content = $event->description;
		$featured_image = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'large' ) : '';
		
		$start_date = $event->start_date;
		$end_date = $event->end_date;
		$is_allday = $event->is_allday;
		$event_color = $event->event_color ?: '#008080';
		$event_location = $event->venue;
		$event_url = $event->event_url;
		$event_type = $event->event_type;
		$event_cost = $event->event_cost;
		$scheduling_type = isset($event->scheduling_type) ? $event->scheduling_type : 'single';
		$daily_start_time = isset($event->daily_start_time) ? $event->daily_start_time : '';
		$daily_end_time = isset($event->daily_end_time) ? $event->daily_end_time : '';
		$event_frequency = $event->event_frequency;
		$recurrence_type = $event->recurrence_type ?? '';
		$recurrence_interval = $event->recurrence_interval ?? 1;
		$recurrence_days = $event->recurrence_days ?? '';
		$recurrence_week = $event->recurrence_week ?? '';
		$organiser_type = $event->organiser_type;
		$category_id = $event->category_id;
		
		$recurrence_description = $this->get_recurrence_description( $event_frequency, $recurrence_interval, $recurrence_days, $recurrence_week );
		
		$category_name = '';
		$category_link = '';
		if ( $category_id ) {
			$category = get_term( $category_id, 'event_category' );
			if ( $category && ! is_wp_error( $category ) ) {
				$category_name = $category->name;
				$category_link = get_term_link( $category );
			}
		}

		$event_tags_list = array();
		if ( ! empty( $event->tags ) ) {
			$tag_ids = array_filter( array_map( 'absint', explode( ',', $event->tags ) ) );
			foreach ( $tag_ids as $tag_id ) {
				$tag = get_term( $tag_id, 'event_tag' );
				if ( $tag && ! is_wp_error( $tag ) ) {
					$event_tags_list[] = array(
						'name' => $tag->name,
						'link' => get_term_link( $tag ),
					);
				}
			}
		}
		
		$author_id = $event->user_id;
		$author_name = get_the_author_meta( 'display_name', $author_id );
		
		$custom_profile_image_id = get_user_meta( $author_id, 'hbl_profile_image', true );
		if ( $custom_profile_image_id ) {
			$author_avatar = wp_get_attachment_image_url( $custom_profile_image_id, 'thumbnail' );
		}
		if ( empty( $author_avatar ) ) {
			$author_avatar = get_avatar_url( $author_id, array( 'size' => 60 ) );
		}
		
		$original_start = strtotime( $start_date );
		$original_end = $end_date ? strtotime( $end_date ) : $original_start;
		$duration = $original_end - $original_start;

		$selected_date_param = isset($_GET['date']) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		if ( empty( $selected_date_param ) && isset($_GET['event_date']) ) {
			$selected_date_param = sanitize_text_field( wp_unslash( $_GET['event_date'] ) );
		}
		
		if ( ! empty( $selected_date_param ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $selected_date_param ) ) {
			$time_part = date( 'H:i:s', $original_start );
			$start_timestamp = strtotime( $selected_date_param . ' ' . $time_part );
			
			if ( $event_frequency === 'multi_day' ) {
				$end_timestamp = $original_end;
				
				if ( $start_timestamp > $end_timestamp ) {
					$end_timestamp = $start_timestamp;
				}
			} else {
				$end_timestamp = $start_timestamp + $duration;
			}
		} else {
			$is_recurring = ! empty( $event_frequency ) && ! in_array( $event_frequency, array( 'once', '' ), true );

			if ( $is_recurring && function_exists( 'hbl_get_next_occurrence' ) ) {
				$today      = current_time( 'Y-m-d' );
				$next_date  = hbl_get_next_occurrence( $event, $today );

				if ( $next_date ) {
					$next_date_only  = date( 'Y-m-d', strtotime( $next_date ) );
					$time_part       = date( 'H:i:s', $original_start );
					$start_timestamp = strtotime( $next_date_only . ' ' . $time_part );

					$end_timestamp = ( $event_frequency === 'multi_day' )
						? $original_end
						: $start_timestamp + $duration;
				} else {
					$start_timestamp = $original_start;
					$end_timestamp   = $original_end;
				}
			} else {
				$start_timestamp = $original_start;
				$end_timestamp   = $original_end;
			}
		}
		
		$start_day = date_i18n( 'd', $start_timestamp );
		$start_month = date_i18n( 'M', $start_timestamp );
		$start_year = date_i18n( 'Y', $start_timestamp );
		$start_weekday = date_i18n( 'l', $start_timestamp );
		
		if ( $scheduling_type === 'multi' && $daily_start_time ) {
			$start_time = date_i18n( 'g:i A', strtotime( $daily_start_time ) );
			$end_time = $daily_end_time ? date_i18n( 'g:i A', strtotime( $daily_end_time ) ) : '';
		} else {
			$start_time = date_i18n( 'g:i A', $start_timestamp );
			$end_time = date_i18n( 'g:i A', $end_timestamp );
		}

		if ( $scheduling_type === 'multi' && $end_date && $start_timestamp != $end_timestamp ) {
			$full_date = date_i18n( 'M j', $start_timestamp ) . ' - ' . date_i18n( 'M j, Y', $end_timestamp );
		} else {
			$full_date = date_i18n( 'F j, Y', $start_timestamp );
		}
		
		$now = current_time( 'timestamp' );
		$event_status = 'upcoming';
		if ( $now > $end_timestamp ) {
			$event_status = 'past';
		} elseif ( $now >= $start_timestamp && $now <= $end_timestamp ) {
			$event_status = 'ongoing';
		}

		$event_page_url = add_query_arg( 'event_id', $event_id, get_permalink() );

		$type_labels = array(
			'community'     => __( 'Community Event', 'hbl' ),
			'workshop'      => __( 'Workshop or Class', 'hbl' ),
			'market'        => __( 'Market or Festival', 'hbl' ),
			'business'      => __( 'Business/Networking', 'hbl' ),
			'entertainment' => __( 'Entertainment', 'hbl' ),
			'other'         => __( 'Other', 'hbl' ),
		);
		$event_type_label = isset( $type_labels[ $event_type ] ) ? $type_labels[ $event_type ] : '';
		?>
		<div class="hbl-single-event-widget" data-event-color="<?php echo esc_attr( $event_color ); ?>">
			<div class="hbl-single-event-header">
				<?php if ( $featured_image && 'yes' === $settings['show_featured_image'] ) : ?>
					<div class="hbl-single-event-header-bg" style="background-image: url('<?php echo esc_url( $featured_image ); ?>');"></div>
				<?php endif; ?>
				<div class="hbl-single-event-header-overlay"></div>
				
				<div class="hbl-single-event-header-content">
					<div class="hbl-single-event-header-top">
						<?php
						$back_url = home_url( '/whats-on/' );
						$referrer = wp_get_referer();
						
						if ( $referrer ) {
							$referrer_host = wp_parse_url( $referrer, PHP_URL_HOST );
							$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
							
							if ( $referrer_host === $site_host ) {
								$back_url = $referrer;
							}
						}
						?>
						<a href="<?php echo esc_url( $back_url ); ?>" class="hbl-single-event-back-btn">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M19 12H5M12 19L5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php esc_html_e( 'Go Back', 'hbl' ); ?>
						</a>
						
						<div class="hbl-single-event-header-actions">
							<?php if ( 'yes' === $settings['show_share_buttons'] ) : ?>
							<button class="hbl-single-event-share-btn" onclick="navigator.share({title: '<?php echo esc_js( $title ); ?>', url: '<?php echo esc_url( $event_page_url ); ?>'})" title="<?php esc_attr_e( 'Share', 'hbl' ); ?>">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<circle cx="18" cy="5" r="3" stroke="currentColor" stroke-width="2"/>
									<circle cx="6" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
									<circle cx="18" cy="19" r="3" stroke="currentColor" stroke-width="2"/>
									<path d="M8.59 13.51L15.42 17.49M15.41 6.51L8.59 10.49" stroke="currentColor" stroke-width="2"/>
								</svg>
							</button>
							<?php endif; ?>
							<?php 
							$is_favorited = false;
							if ( is_user_logged_in() ) {
								$user_favorites = get_user_meta( get_current_user_id(), 'hbl_favorite_events', true );
								$user_favorites = is_array( $user_favorites ) ? $user_favorites : array();
								$is_favorited = in_array( $event_id, $user_favorites );
							}
							?>
							<button class="hbl-single-event-favorite-btn hbl-favorite-btn <?php echo $is_favorited ? 'is-favorited' : ''; ?>" data-item-id="<?php echo esc_attr( $event_id ); ?>" data-type="event" title="<?php echo $is_favorited ? esc_attr__( 'Remove from Favorites', 'hbl' ) : esc_attr__( 'Add to Favorites', 'hbl' ); ?>">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="<?php echo $is_favorited ? 'currentColor' : 'none'; ?>" xmlns="http://www.w3.org/2000/svg">
									<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
							</button>
						</div>
					</div>
					
					<div class="hbl-single-event-header-main">
						<div class="hbl-single-event-date-badge">
							<span class="hbl-single-event-date-day"><?php echo esc_html( $start_day ); ?></span>
							<span class="hbl-single-event-date-month"><?php echo esc_html( $start_month ); ?></span>
						</div>
						
						<div class="hbl-single-event-header-info">
							<?php if ( $category_name || $event_type_label ) : ?>
								<div class="hbl-single-event-categories">
									<?php if ( $category_name ) : ?>
										<span class="hbl-single-event-category">
											<?php if ( ! empty( $category_link ) && ! is_wp_error( $category_link ) ) : ?>
												<a href="<?php echo esc_url( $category_link ); ?>" style="color: inherit; text-decoration: none;"><?php echo esc_html( $category_name ); ?></a>
											<?php else : ?>
												<?php echo esc_html( $category_name ); ?>
											<?php endif; ?>
										</span>
									<?php endif; ?>
									<?php if ( $event_type_label ) : ?>
										<span class="hbl-single-event-category hbl-single-event-type"><?php echo esc_html( $event_type_label ); ?></span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $event_tags_list ) ) : ?>
								<div class="hbl-single-event-tags">
									<?php foreach ( $event_tags_list as $evt_tag ) : ?>
										<a href="<?php echo esc_url( $evt_tag['link'] ); ?>" class="hbl-single-event-tag">#<?php echo esc_html( $evt_tag['name'] ); ?></a>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							
							<h1 class="hbl-single-event-title"><?php echo esc_html( $title ); ?></h1>
							
							<div class="hbl-single-event-meta">
								<div class="hbl-single-event-meta-item">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
										<path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
									</svg>
									<?php echo esc_html( $full_date ); ?>
								</div>
								
								<?php if ( ! $is_allday ) : ?>
								<div class="hbl-single-event-meta-item">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
										<path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
									</svg>
									<?php echo esc_html( $start_time ); ?> - <?php echo esc_html( $end_time ); ?>
								</div>
								<?php else : ?>
								<div class="hbl-single-event-meta-item">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
										<path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
									</svg>
									<?php esc_html_e( 'All Day Event', 'hbl' ); ?>
								</div>
								<?php endif; ?>
								
								<div class="hbl-single-event-status hbl-single-event-status-<?php echo esc_attr( $event_status ); ?>">
									<?php 
									if ( $event_status === 'ongoing' ) {
										esc_html_e( 'Happening Now', 'hbl' );
									} elseif ( $event_status === 'past' ) {
										esc_html_e( 'Event Ended', 'hbl' );
									} else {
										esc_html_e( 'Upcoming', 'hbl' );
									}
									?>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			
			<div class="hbl-single-event-body">
				<div class="hbl-single-event-main">
					<div class="hbl-single-event-section hbl-single-event-description-section">
						<div class="hbl-single-event-section-header">
							<div class="hbl-single-event-section-icon">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M14 2V8H20M16 13H8M16 17H8M10 9H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
							</div>
							<h2 class="hbl-single-event-section-title"><?php esc_html_e( 'About This Event', 'hbl' ); ?></h2>
						</div>
						<div class="hbl-single-event-section-content">
							<div class="hbl-single-event-description">
								<?php echo wp_kses_post( wpautop( $content ) ); ?>
							</div>
						</div>
					</div>
					
					<?php if ( $featured_image ) : ?>
					<div class="hbl-single-event-section hbl-single-event-image-section">
						<div class="hbl-single-event-section-header">
							<div class="hbl-single-event-section-icon">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
									<circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/>
									<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
							</div>
							<h2 class="hbl-single-event-section-title"><?php esc_html_e( 'Event Image', 'hbl' ); ?></h2>
						</div>
						<div class="hbl-single-event-section-content">
							<div class="hbl-single-event-featured-image">
								<img src="<?php echo esc_url( $featured_image ); ?>" alt="<?php echo esc_attr( $title ); ?>">
							</div>
						</div>
					</div>
					<?php endif; ?>
				</div>
				
				<div class="hbl-single-event-sidebar">
					<div class="hbl-single-event-card hbl-single-event-details-card">
						<h3 class="hbl-single-event-card-title">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
								<path d="M12 16V12M12 8H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							</svg>
							<?php esc_html_e( 'Event Details', 'hbl' ); ?>
						</h3>
						
						<div class="hbl-single-event-details-list">
							<div class="hbl-single-event-detail-item">
								<div class="hbl-single-event-detail-icon">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
										<path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
									</svg>
								</div>
								<div class="hbl-single-event-detail-text">
									<span class="hbl-single-event-detail-label"><?php esc_html_e( 'Date', 'hbl' ); ?></span>
									<span class="hbl-single-event-detail-value"><?php echo esc_html( $start_weekday . ', ' . $full_date ); ?></span>
								</div>
							</div>
							
							<div class="hbl-single-event-detail-item">
								<div class="hbl-single-event-detail-icon">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
										<path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
									</svg>
								</div>
								<div class="hbl-single-event-detail-text">
									<span class="hbl-single-event-detail-label"><?php esc_html_e( 'Time', 'hbl' ); ?></span>
									<span class="hbl-single-event-detail-value">
										<?php 
										if ( $is_allday ) {
											esc_html_e( 'All Day', 'hbl' );
										} else {
											echo esc_html( $start_time . ' - ' . $end_time );
										}
										?>
									</span>
								</div>
							</div>
							
							<?php if ( $event_location ) : ?>
							<div class="hbl-single-event-detail-item">
								<div class="hbl-single-event-detail-icon">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
									</svg>
								</div>
								<div class="hbl-single-event-detail-text">
									<span class="hbl-single-event-detail-label"><?php esc_html_e( 'Location', 'hbl' ); ?></span>
									<span class="hbl-single-event-detail-value"><?php echo esc_html( $event_location ); ?></span>
								</div>
							</div>
							<?php endif; ?>
							
							<div class="hbl-single-event-detail-item">
								<div class="hbl-single-event-detail-icon">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
										<path d="M12 6V18M15 9.5C15 8.12 13.66 7 12 7C10.34 7 9 8.12 9 9.5S10.34 12 12 12C13.66 12 15 13.12 15 14.5S13.66 17 12 17C10.34 17 9 15.88 9 14.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
									</svg>
								</div>
								<div class="hbl-single-event-detail-text">
									<span class="hbl-single-event-detail-label"><?php esc_html_e( 'Cost', 'hbl' ); ?></span>
									<span class="hbl-single-event-detail-value"><?php echo $event_cost === 'paid' ? esc_html__( 'Paid', 'hbl' ) : esc_html__( 'Free', 'hbl' ); ?></span>
								</div>
							</div>
							
							<?php if ( ! empty( $recurrence_description ) ) : ?>
							<div class="hbl-single-event-detail-item">
								<div class="hbl-single-event-detail-icon">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M17 1L21 5L17 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M3 11V9C3 7.93913 3.42143 6.92172 4.17157 6.17157C4.92172 5.42143 5.93913 5 7 5H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M7 23L3 19L7 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M21 13V15C21 16.0609 20.5786 17.0783 19.8284 17.8284C19.0783 18.5786 18.0609 19 17 19H3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</div>
								<div class="hbl-single-event-detail-text">
									<span class="hbl-single-event-detail-label"><?php esc_html_e( 'Frequency', 'hbl' ); ?></span>
									<span class="hbl-single-event-detail-value"><?php echo esc_html( $recurrence_description ); ?></span>
								</div>
							</div>
							<?php endif; ?>
							
							<?php if ( $category_name ) : ?>
							<div class="hbl-single-event-detail-item">
								<div class="hbl-single-event-detail-icon">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M20.59 13.41L13.42 20.58C13.2343 20.766 13.0137 20.9135 12.7709 21.0141C12.5281 21.1148 12.2678 21.1666 12.005 21.1666C11.7422 21.1666 11.4819 21.1148 11.2391 21.0141C10.9963 20.9135 10.7757 20.766 10.59 20.58L2 12V2H12L20.59 10.59C20.9625 10.9647 21.1716 11.4716 21.1716 12C21.1716 12.5284 20.9625 13.0353 20.59 13.41Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<circle cx="7" cy="7" r="1" fill="currentColor"/>
									</svg>
								</div>
								<div class="hbl-single-event-detail-text">
									<span class="hbl-single-event-detail-label"><?php esc_html_e( 'Category', 'hbl' ); ?></span>
									<span class="hbl-single-event-detail-value"><?php echo esc_html( $category_name ); ?></span>
								</div>
							</div>
							<?php endif; ?>

						<?php if ( ! empty( $event_tags_list ) ) : ?>
						<div class="hbl-single-event-detail-item">
							<div class="hbl-single-event-detail-icon">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M20.59 13.41L13.42 20.58C13.2343 20.766 13.0137 20.9135 12.7709 21.0141C12.5281 21.1148 12.2678 21.1666 12.005 21.1666C11.7422 21.1666 11.4819 21.1148 11.2391 21.0141C10.9963 20.9135 10.7757 20.766 10.59 20.58L2 12V2H12L20.59 10.59C20.9625 10.9647 21.1716 11.4716 21.1716 12C21.1716 12.5284 20.9625 13.0353 20.59 13.41Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<line x1="7" y1="7" x2="7.01" y2="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
							</div>
							<div class="hbl-single-event-detail-text">
								<span class="hbl-single-event-detail-label"><?php esc_html_e( 'Tags', 'hbl' ); ?></span>
								<span class="hbl-single-event-detail-value hbl-single-event-tags-list">
									<?php foreach ( $event_tags_list as $evt_tag ) : ?>
										<a href="<?php echo esc_url( $evt_tag['link'] ); ?>" class="hbl-single-event-tag-link"><?php echo esc_html( $evt_tag['name'] ); ?></a>
									<?php endforeach; ?>
								</span>
							</div>
						</div>
						<?php endif; ?>
						</div>

						<?php if ( $event_url ) : ?>
						<div class="hbl-single-event-cta">
							<a href="<?php echo esc_url( $event_url ); ?>" target="_blank" rel="noopener" class="hbl-single-event-cta-btn" style="background-color: <?php echo esc_attr( $event_color ?: '#F9532A' ); ?>;">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M18 13V19C18 19.5304 17.7893 20.0391 17.4142 20.4142C17.0391 20.7893 16.5304 21 16 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V8C3 7.46957 3.21071 6.96086 3.58579 6.58579C3.96086 6.21071 4.46957 6 5 6H11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M15 3H21V9M10 14L21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<?php esc_html_e( 'Get Tickets / Register', 'hbl' ); ?>
							</a>
						</div>
						<?php endif; ?>
						
						<div class="hbl-single-event-add-calendar">
							<button class="hbl-single-event-calendar-btn" onclick="hblAddToCalendar()">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
									<path d="M16 2V6M8 2V6M3 10H21M12 14V18M10 16H14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
								</svg>
								<?php esc_html_e( 'Add to Calendar', 'hbl' ); ?>
							</button>
						</div>
					</div>
					
					<?php if ( 'yes' === $settings['show_organizer'] ) : ?>
					<div class="hbl-single-event-card hbl-single-event-organizer-card">
						<h3 class="hbl-single-event-card-title">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M20 21V19C20 17.9391 19.5786 16.9217 18.8284 16.1716C18.0783 15.4214 17.0609 15 16 15H8C6.93913 15 5.92172 15.4214 5.17157 16.1716C4.42143 16.9217 4 17.9391 4 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
							</svg>
							<?php esc_html_e( 'Organizer', 'hbl' ); ?>
						</h3>
						
						<div class="hbl-single-event-organizer">
							<div class="hbl-single-event-organizer-avatar">
								<img src="<?php echo esc_url( $author_avatar ); ?>" alt="<?php echo esc_attr( $author_name ); ?>">
							</div>
							<div class="hbl-single-event-organizer-info">
								<h4 class="hbl-single-event-organizer-name"><?php echo esc_html( $author_name ); ?></h4>
								<span class="hbl-single-event-organizer-label"><?php esc_html_e( 'Event Organizer', 'hbl' ); ?></span>
							</div>
						</div>
					</div>
					<?php endif; ?>
					
					<?php if ( 'yes' === $settings['show_share_buttons'] ) : ?>
					<div class="hbl-single-event-card hbl-single-event-share-card">
						<h3 class="hbl-single-event-card-title">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<circle cx="18" cy="5" r="3" stroke="currentColor" stroke-width="2"/>
								<circle cx="6" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
								<circle cx="18" cy="19" r="3" stroke="currentColor" stroke-width="2"/>
								<path d="M8.59 13.51L15.42 17.49M15.41 6.51L8.59 10.49" stroke="currentColor" stroke-width="2"/>
							</svg>
							<?php esc_html_e( 'Share Event', 'hbl' ); ?>
						</h3>
						
						<div class="hbl-single-event-share-links">
							<a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode( $event_page_url ); ?>" target="_blank" rel="noopener" class="hbl-single-event-share-link hbl-share-facebook" title="Facebook">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
									<path d="M18 2H15C13.6739 2 12.4021 2.52678 11.4645 3.46447C10.5268 4.40215 10 5.67392 10 7V10H7V14H10V22H14V14H17L18 10H14V7C14 6.73478 14.1054 6.48043 14.2929 6.29289C14.4804 6.10536 14.7348 6 15 6H18V2Z"/>
								</svg>
							</a>
							<a href="https://twitter.com/intent/tweet?url=<?php echo urlencode( $event_page_url ); ?>&text=<?php echo urlencode( $title ); ?>" target="_blank" rel="noopener" class="hbl-single-event-share-link hbl-share-twitter" title="Twitter/X">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
									<path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
								</svg>
							</a>
							<a href="https://www.linkedin.com/shareArticle?mini=true&url=<?php echo urlencode( $event_page_url ); ?>&title=<?php echo urlencode( $title ); ?>" target="_blank" rel="noopener" class="hbl-single-event-share-link hbl-share-linkedin" title="LinkedIn">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
									<path d="M16 8C17.5913 8 19.1174 8.63214 20.2426 9.75736C21.3679 10.8826 22 12.4087 22 14V21H18V14C18 13.4696 17.7893 12.9609 17.4142 12.5858C17.0391 12.2107 16.5304 12 16 12C15.4696 12 14.9609 12.2107 14.5858 12.5858C14.2107 12.9609 14 13.4696 14 14V21H10V14C10 12.4087 10.6321 10.8826 11.7574 9.75736C12.8826 8.63214 14.4087 8 16 8Z"/>
									<rect x="2" y="9" width="4" height="12"/>
									<circle cx="4" cy="4" r="2"/>
								</svg>
							</a>
							<a href="mailto:?subject=<?php echo urlencode( $title ); ?>&body=<?php echo urlencode( $event_page_url ); ?>" class="hbl-single-event-share-link hbl-share-email" title="Email">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" xmlns="http://www.w3.org/2000/svg">
									<path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z"/>
									<path d="M22 6L12 13L2 6"/>
								</svg>
							</a>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</div>
			
			<?php if ( 'yes' === $settings['show_related'] ) : ?>
			<div class="hbl-single-event-related">
				<div class="hbl-single-event-related-header">
					<h2 class="hbl-single-event-related-title">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
							<path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
						<?php esc_html_e( 'More Events', 'hbl' ); ?>
					</h2>
				</div>
				
				<div class="hbl-single-event-related-grid">
					<?php
					$related_events = $this->get_related_events( $event_id, $category_id, absint( $settings['related_count'] ) );
					
					if ( ! empty( $related_events ) ) :
						foreach ( $related_events as $related ) :
							$related_id = $related->id;
							$related_image = $related->featured_image ? wp_get_attachment_image_url( $related->featured_image, 'medium' ) : '';
							$related_start = $related->start_date;
							$related_color = $related->event_color ?: '#008080';
							$related_timestamp = strtotime( $related_start );
							$related_url = add_query_arg( 'event_id', $related_id, get_permalink() );
							
							$related_cat_name = '';
							if ( $related->category_id ) {
								$related_cat = get_term( $related->category_id, 'event_category' );
								if ( $related_cat && ! is_wp_error( $related_cat ) ) {
									$related_cat_name = $related_cat->name;
								}
							}
					?>
						<a href="<?php echo esc_url( $related_url ); ?>" class="hbl-single-event-related-card">
							<div class="hbl-single-event-related-image">
								<?php if ( $related_image ) : ?>
									<img src="<?php echo esc_url( $related_image ); ?>" alt="<?php echo esc_attr( $related->title ); ?>">
								<?php else : ?>
									<div class="hbl-single-event-related-no-image" style="background-color: <?php echo esc_attr( $related_color ); ?>;">
										<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
											<path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
										</svg>
									</div>
								<?php endif; ?>
								<div class="hbl-single-event-related-date" style="background-color: <?php echo esc_attr( $related_color ); ?>;">
									<span class="hbl-single-event-related-day"><?php echo esc_html( date_i18n( 'd', $related_timestamp ) ); ?></span>
									<span class="hbl-single-event-related-month"><?php echo esc_html( date_i18n( 'M', $related_timestamp ) ); ?></span>
								</div>
							</div>
							<div class="hbl-single-event-related-content">
								<h4 class="hbl-single-event-related-card-title"><?php echo esc_html( $related->title ); ?></h4>
								<?php if ( $related_cat_name ) : ?>
									<span class="hbl-single-event-related-category"><?php echo esc_html( $related_cat_name ); ?></span>
								<?php endif; ?>
								<span class="hbl-single-event-related-time">
									<svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
										<path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
									</svg>
									<?php echo esc_html( date_i18n( 'g:i A', $related_timestamp ) ); ?>
								</span>
							</div>
						</a>
					<?php 
						endforeach;
					else :
					?>
						<p class="hbl-single-event-no-related"><?php esc_html_e( 'No upcoming events found.', 'hbl' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
		
		<script>
		function hblAddToCalendar() {
			var title = <?php echo wp_json_encode( $title ); ?>;
			var start = <?php echo wp_json_encode( date( 'Ymd', $start_timestamp ) . 'T' . date( 'His', $start_timestamp ) ); ?>;
			var end = <?php echo wp_json_encode( date( 'Ymd', $end_timestamp ) . 'T' . date( 'His', $end_timestamp ) ); ?>;
			var location = <?php echo wp_json_encode( $event_location ?: '' ); ?>;
			var url = <?php echo wp_json_encode( $event_page_url ); ?>;
			
			var googleUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE' +
				'&text=' + encodeURIComponent(title) +
				'&dates=' + start + '/' + end +
				'&location=' + encodeURIComponent(location) +
				'&details=' + encodeURIComponent('More info: ' + url);
			
			window.open(googleUrl, '_blank');
		}
		</script>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			try {
				var selectedDateStr = sessionStorage.getItem('hbl_event_selected_date');
				if (selectedDateStr) {
					if (/^\d{4}-\d{2}-\d{2}$/.test(selectedDateStr)) {
						var dateParts = selectedDateStr.split('-');
						var year = parseInt(dateParts[0]);
						var month = parseInt(dateParts[1]) - 1;
						var day = parseInt(dateParts[2]);
						
						var date = new Date(year, month, day);
						var monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
						var fullMonthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
						var dayNames = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
						
						var badgeDay = document.querySelector('.hbl-single-event-date-day');
						var badgeMonth = document.querySelector('.hbl-single-event-date-month');
						
						if (badgeDay) badgeDay.textContent = day;
						if (badgeMonth) badgeMonth.textContent = monthNames[month];
						
						var metaDate = document.querySelector('.hbl-single-event-meta-item:first-child');
						if (metaDate && metaDate.innerHTML.includes('<svg')) {
							var iconHtml = metaDate.innerHTML.split('</svg>')[0] + '</svg>';
							metaDate.innerHTML = iconHtml + ' ' + dayNames[date.getDay()] + ', ' + fullMonthNames[month] + ' ' + day + ', ' + year;
						}
						
						var detailsLabel = Array.from(document.querySelectorAll('.hbl-single-event-detail-label')).find(el => el.textContent.trim() === 'Date');
						if (detailsLabel) {
							var detailsValue = detailsLabel.nextElementSibling;
							if (detailsValue) {
								detailsValue.textContent = dayNames[date.getDay()] + ', ' + fullMonthNames[month] + ' ' + day + ', ' + year;
							}
						}

						var relatedDates = document.querySelectorAll('.hbl-single-event-related-card');
					}
					
				}
			} catch (e) {
				console.error('Error updating event date from storage', e);
			}
		});
		</script>
		<?php
	}
}
