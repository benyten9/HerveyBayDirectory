<?php
/**
 * HBL Events V2 AJAX Handlers
 *
 * Handles dynamic filtering for the V2 Event Single Category widget.
 *
 * @package HBL
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX Handler for V2 Event Single Category Widget Filtering
 */
function hbl_events_v2_filter_events() {
	check_ajax_referer( 'hbl_v2_filter_nonce', 'nonce' );

	$term_id         = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
	$keyword         = isset( $_POST['keyword'] ) ? sanitize_text_field( $_POST['keyword'] ) : '';
	$letter          = isset( $_POST['letter'] ) ? strtoupper( sanitize_text_field( $_POST['letter'] ) ) : '';
	$sort            = isset( $_POST['sort'] ) ? sanitize_text_field( $_POST['sort'] ) : 'recommended';
	$paged           = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
	$per_page        = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;
	$widget_settings = isset( $_POST['widget_settings'] ) ? json_decode( stripslashes( $_POST['widget_settings'] ), true ) : array();

	if ( ! $term_id ) {
		wp_send_json( array( 'success' => false, 'message' => 'Invalid term.' ) );
	}

	if ( ! function_exists( 'hbl_events_db' ) ) {
		wp_send_json( array( 'success' => false, 'message' => 'Events DB not available.' ) );
	}

	$upcoming_only = ! empty( $widget_settings['show_upcoming_only'] ) && 'yes' === $widget_settings['show_upcoming_only'];
	$fallback_logo = ! empty( $widget_settings['fallback_logo'] ) ? $widget_settings['fallback_logo'] : array();

	// ---- Query events ----
	$db         = hbl_events_db();
	$query_args = array(
		'category_id' => $term_id,
		'status'      => 'publish',
		'limit'       => -1,
	);
	if ( ! empty( $keyword ) ) {
		$query_args['search'] = $keyword;
	}

	$raw_events  = $db->get_events( $query_args );
	$now         = current_time( 'timestamp' );
	$today_start = strtotime( 'today', $now );
	$processed   = array();

	foreach ( $raw_events as $event ) {
		$next_date = hbl_events_v2_get_next_occurrence( $event );
		$next_ts   = strtotime( $next_date );

		if ( $upcoming_only && $next_ts < $today_start ) {
			continue;
		}

		if ( ! empty( $letter ) && strtoupper( substr( $event->title, 0, 1 ) ) !== $letter ) {
			continue;
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
		usort( $processed, function( $a, $b ) {
			return strtotime( $a->computed_start_date ) - strtotime( $b->computed_start_date );
		} );
	}

	$total_events = count( $processed );
	$max_pages    = $per_page > 0 ? ceil( $total_events / $per_page ) : 1;
	$offset       = ( $paged - 1 ) * $per_page;
	$events       = array_slice( $processed, $offset, $per_page );

	// ---- Render cards ----
	$half         = $events ? ceil( count( $events ) / 2 ) : 0;
	$left_column  = array_slice( $events, 0, $half );
	$right_column = array_slice( $events, $half );

	ob_start();
	foreach ( $left_column as $event ) {
		hbl_events_v2_render_event_card( $event, $fallback_logo );
	}
	$left_html = ob_get_clean();

	ob_start();
	foreach ( $right_column as $event ) {
		hbl_events_v2_render_event_card( $event, $fallback_logo );
	}
	$right_html = ob_get_clean();

	if ( empty( $left_html ) && empty( $right_html ) ) {
		$left_html = '<div class="hbl-v2-no-results"><i class="bi bi-search"></i><p>' . esc_html__( 'No events found.', 'hbl' ) . '</p></div>';
	}

	// ---- Render pagination ----
	$pagination_html = '';
	if ( $max_pages > 1 ) {
		ob_start();
		?>
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
		<?php
		$pagination_html = ob_get_clean();
	}

	wp_send_json( array(
		'success'         => true,
		'html'            => array(
			'left'  => $left_html,
			'right' => $right_html,
		),
		'pagination_html' => $pagination_html,
		'total'           => $total_events,
	) );
}
add_action( 'wp_ajax_hbl_v2_filter_events', 'hbl_events_v2_filter_events' );
add_action( 'wp_ajax_nopriv_hbl_v2_filter_events', 'hbl_events_v2_filter_events' );

/**
 * AJAX Handler for V2 Event Single Tag Widget Filtering
 */
function hbl_events_v2_filter_events_by_tag() {
	check_ajax_referer( 'hbl_v2_filter_nonce', 'nonce' );

	$tag_id          = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0;
	$keyword         = isset( $_POST['keyword'] ) ? sanitize_text_field( $_POST['keyword'] ) : '';
	$letter          = isset( $_POST['letter'] ) ? strtoupper( sanitize_text_field( $_POST['letter'] ) ) : '';
	$sort            = isset( $_POST['sort'] ) ? sanitize_text_field( $_POST['sort'] ) : 'recommended';
	$paged           = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
	$per_page        = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;
	$widget_settings = isset( $_POST['widget_settings'] ) ? json_decode( stripslashes( $_POST['widget_settings'] ), true ) : array();

	if ( ! $tag_id ) {
		wp_send_json( array( 'success' => false, 'message' => 'Invalid tag.' ) );
	}

	if ( ! function_exists( 'hbl_events_db' ) ) {
		wp_send_json( array( 'success' => false, 'message' => 'Events DB not available.' ) );
	}

	$upcoming_only = ! empty( $widget_settings['show_upcoming_only'] ) && 'yes' === $widget_settings['show_upcoming_only'];
	$fallback_logo = ! empty( $widget_settings['fallback_logo'] ) ? $widget_settings['fallback_logo'] : array();

	// ---- Query events by tag ----
	global $wpdb;
	$table = hbl_events_db()->get_table_name();

	$raw_events = $wpdb->get_results(
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

		$next_date = hbl_events_v2_get_next_occurrence( $event );
		$next_ts   = strtotime( $next_date );

		if ( $upcoming_only && $next_ts < $today_start ) {
			continue;
		}

		if ( ! empty( $letter ) && strtoupper( substr( $event->title, 0, 1 ) ) !== $letter ) {
			continue;
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
		usort( $processed, function( $a, $b ) {
			return strtotime( $a->computed_start_date ) - strtotime( $b->computed_start_date );
		} );
	}

	$total_events = count( $processed );
	$max_pages    = $per_page > 0 ? ceil( $total_events / $per_page ) : 1;
	$offset       = ( $paged - 1 ) * $per_page;
	$events       = array_slice( $processed, $offset, $per_page );

	// ---- Render cards ----
	$half         = $events ? ceil( count( $events ) / 2 ) : 0;
	$left_column  = array_slice( $events, 0, $half );
	$right_column = array_slice( $events, $half );

	ob_start();
	foreach ( $left_column as $event ) {
		hbl_events_v2_render_event_card( $event, $fallback_logo );
	}
	$left_html = ob_get_clean();

	ob_start();
	foreach ( $right_column as $event ) {
		hbl_events_v2_render_event_card( $event, $fallback_logo );
	}
	$right_html = ob_get_clean();

	if ( empty( $left_html ) && empty( $right_html ) ) {
		$left_html = '<div class="hbl-v2-no-results"><i class="bi bi-search"></i><p>' . esc_html__( 'No events found.', 'hbl' ) . '</p></div>';
	}

	// ---- Render pagination ----
	$pagination_html = '';
	if ( $max_pages > 1 ) {
		ob_start();
		?>
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
		<?php
		$pagination_html = ob_get_clean();
	}

	wp_send_json( array(
		'success'         => true,
		'html'            => array(
			'left'  => $left_html,
			'right' => $right_html,
		),
		'pagination_html' => $pagination_html,
		'total'           => $total_events,
	) );
}
add_action( 'wp_ajax_hbl_v2_filter_events_by_tag', 'hbl_events_v2_filter_events_by_tag' );
add_action( 'wp_ajax_nopriv_hbl_v2_filter_events_by_tag', 'hbl_events_v2_filter_events_by_tag' );

/**
 * Get next occurrence date for an event (standalone for AJAX context).
 */
function hbl_events_v2_get_next_occurrence( $event ) {
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
 * Render a single event card for AJAX responses.
 *
 * @param object $event        Event object with computed_start_date.
 * @param array  $fallback_logo Fallback logo array with 'url' key.
 */
function hbl_events_v2_render_event_card( $event, $fallback_logo = array() ) {
	$start_date    = $event->computed_start_date ?? $event->start_date;
	$event_url     = function_exists( 'hbl_events_db' ) ? hbl_events_db()->get_event_url( $event ) : '#';
	$thumbnail_url = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'medium_large' ) : '';
	$event_color   = ! empty( $event->event_color ) ? $event->event_color : '#008080';
	$venue_name    = $event->venue ?? '';
	$fallback_url  = ! empty( $fallback_logo['url'] ) ? $fallback_logo['url'] : '';
	?>
	<?php $image_src = $thumbnail_url ?: $fallback_url; ?>
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
