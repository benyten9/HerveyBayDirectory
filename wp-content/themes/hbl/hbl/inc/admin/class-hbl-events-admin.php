<?php
/**
 * HBL Events Admin Page
 * 
 * Beautiful admin interface for managing events with internal tag analytics
 *
 * @package HBL
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Events_Admin {

	/**
	 * Instance
	 */
	private static $instance = null;

	/**
	 * Event type labels
	 */
	private $event_types = array(
		'community'     => 'Community Event',
		'workshop'      => 'Workshop or Class',
		'market'        => 'Market or Festival',
		'business'      => 'Business/Networking',
		'entertainment' => 'Entertainment',
		'other'         => 'Other',
	);

	/**
	 * Frequency labels
	 */
	private $frequencies = array(
		'once'      => 'One-off',
		'weekly'    => 'Weekly',
		'monthly'   => 'Monthly',
		'recurring' => 'Recurring',
	);

	/**
	 * Day labels for recurrence
	 */
	private $day_labels = array(
		'mon' => 'Mon',
		'tue' => 'Tue',
		'wed' => 'Wed',
		'thu' => 'Thu',
		'fri' => 'Fri',
		'sat' => 'Sat',
		'sun' => 'Sun',
	);

	/**
	 * Week ordinal labels for recurrence
	 */
	private $week_labels = array(
		'1' => '1st',
		'2' => '2nd',
		'3' => '3rd',
		'4' => '4th',
	);

	/**
	 * Organiser type labels
	 */
	private $organiser_types = array(
		'individual' => 'Individual',
		'community'  => 'Community/NFP',
		'business'   => 'Business',
	);

	/**
	 * Get instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 9 );
		add_action( 'admin_menu', array( $this, 'remove_directorist_event_menus' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'wp_ajax_hbl_admin_update_event', array( $this, 'ajax_update_event' ) );
		add_action( 'wp_ajax_hbl_admin_delete_event', array( $this, 'ajax_delete_event' ) );
		add_action( 'wp_ajax_hbl_admin_bulk_delete_events', array( $this, 'ajax_bulk_delete_events' ) );
		add_action( 'wp_ajax_hbl_admin_bulk_update_events', array( $this, 'ajax_bulk_update_events' ) );
		add_action( 'wp_ajax_hbl_admin_add_category', array( $this, 'ajax_add_category' ) );
		add_action( 'wp_ajax_hbl_admin_edit_category', array( $this, 'ajax_edit_category' ) );
		add_action( 'wp_ajax_hbl_admin_delete_category', array( $this, 'ajax_delete_category' ) );
		add_action( 'wp_ajax_hbl_admin_add_tag', array( $this, 'ajax_add_tag' ) );
		add_action( 'wp_ajax_hbl_admin_edit_tag', array( $this, 'ajax_edit_tag' ) );
		add_action( 'wp_ajax_hbl_admin_delete_tag', array( $this, 'ajax_delete_tag' ) );
		add_action( 'wp_ajax_hbl_admin_filter_events', array( $this, 'ajax_filter_events' ) );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'HBL Events', 'hbl' ),
			__( 'HBL Events', 'hbl' ),
			'edit_posts',
			'hbl-events',
			array( $this, 'render_events_page' ),
			'dashicons-calendar-alt',
			'9' // Position: below Directory Listings (5), above Media (10)
		);

		add_submenu_page(
			'hbl-events',
			__( 'All Events', 'hbl' ),
			__( 'All Events', 'hbl' ),
			'edit_posts',
			'hbl-events',
			array( $this, 'render_events_page' )
		);

		// Add Event link (opens frontend form)
		global $submenu;
		$submenu['hbl-events'][] = array(
			__( 'Add Event', 'hbl' ),
			'edit_posts',
			home_url( '/add-event/' ),
		);

		add_submenu_page(
			'hbl-events',
			__( 'Event Categories', 'hbl' ),
			__( 'Categories', 'hbl' ),
			'manage_categories',
			'hbl-event-categories',
			array( $this, 'render_categories_page' )
		);

		add_submenu_page(
			'hbl-events',
			__( 'Event Tags', 'hbl' ),
			__( 'Tags', 'hbl' ),
			'manage_categories',
			'hbl-event-tags',
			array( $this, 'render_tags_page' )
		);

		add_submenu_page(
			'hbl-events',
			__( 'Event Analytics', 'hbl' ),
			__( 'Analytics', 'hbl' ),
			'manage_options',
			'hbl-events-analytics',
			array( $this, 'render_analytics_page' )
		);
	}

	/**
	 * Remove event-related submenus from Directorist
	 */
	public function remove_directorist_event_menus() {
		global $submenu;

		// Remove "All Events" and "Event Categories" from Directorist (at_biz_dir) menu
		if ( isset( $submenu['edit.php?post_type=at_biz_dir'] ) ) {
			foreach ( $submenu['edit.php?post_type=at_biz_dir'] as $key => $item ) {
				// Remove "All Events" (dpci-all-events)
				if ( isset( $item[2] ) && strpos( $item[2], 'dpci-all-events' ) !== false ) {
					unset( $submenu['edit.php?post_type=at_biz_dir'][ $key ] );
				}
				// Remove "Event Categories" taxonomy page
				if ( isset( $item[2] ) && $item[2] === 'edit-tags.php?taxonomy=event_category' ) {
					unset( $submenu['edit.php?post_type=at_biz_dir'][ $key ] );
				}
			}
		}

		// Remove event taxonomies from Posts menu (edit.php)
		if ( isset( $submenu['edit.php'] ) ) {
			foreach ( $submenu['edit.php'] as $key => $item ) {
				if ( isset( $item[2] ) && (
					$item[2] === 'edit-tags.php?taxonomy=event_category' ||
					$item[2] === 'edit-tags.php?taxonomy=event_tag'
				) ) {
					unset( $submenu['edit.php'][ $key ] );
				}
			}
		}
	}

	/**
	 * Enqueue admin styles
	 */
	public function enqueue_admin_styles( $hook ) {
		if ( strpos( $hook, 'hbl-events' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'hbl-events-admin',
			get_template_directory_uri() . '/inc/admin/css/events-admin.css',
			array(),
			HBL_VERSION
		);

		wp_enqueue_script(
			'hbl-events-admin',
			get_template_directory_uri() . '/inc/admin/js/events-admin.js',
			array( 'jquery' ),
			HBL_VERSION,
			true
		);

		wp_localize_script( 'hbl-events-admin', 'hblEventsAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'hbl_admin_nonce' ),
		) );
	}

	/**
	 * Extract filter args from any request source array
	 */
	private function extract_filter_args( $source ) {
		$args = array();
		if ( ! empty( $source['category_id'] ) ) {
			$args['category_id'] = absint( $source['category_id'] );
		}
		if ( ! empty( $source['event_cost'] ) ) {
			$args['event_cost'] = sanitize_text_field( $source['event_cost'] );
		}
		if ( ! empty( $source['event_frequency'] ) ) {
			$args['event_frequency'] = sanitize_text_field( $source['event_frequency'] );
		}
		if ( ! empty( $source['organiser_type'] ) ) {
			$args['organiser_type'] = sanitize_text_field( $source['organiser_type'] );
		}
		if ( ! empty( $source['author_id'] ) ) {
			$args['user_id'] = absint( $source['author_id'] );
		}
		if ( ! empty( $source['s'] ) ) {
			$args['search'] = sanitize_text_field( $source['s'] );
		}
		return $args;
	}

	/**
	 * Get events from custom database table
	 */
	private function get_events( $args = array(), $source = null ) {
		$db = hbl_events_db();
		$defaults = array( 'limit' => 20, 'offset' => 0 );
		$args = wp_parse_args( $args, $defaults );
		$filter_args = $this->extract_filter_args( $source ?? $_GET );
		$args = array_merge( $args, $filter_args );
		return $db->get_events( $args );
	}

	/**
	 * Count total events for pagination
	 */
	private function count_events( $args = array(), $source = null ) {
		$db = hbl_events_db();
		$filter_args = $this->extract_filter_args( $source ?? $_GET );
		$args = array_merge( $args, $filter_args );
		return $db->count_events( $args );
	}

	/**
	 * Render event table rows into a string (reused by page render + AJAX)
	 */
	private function get_event_rows_html( $events ) {
		ob_start();
		if ( ! empty( $events ) ) :
			foreach ( $events as $event ) :
				$event_id        = $event->id;
				$start_date      = $event->start_date;
				$end_date        = $event->end_date;
				$venue           = $event->venue;
				$category_id     = $event->category_id ?? 0;
				$event_cost      = $event->event_cost;
				$event_frequency = $event->event_frequency;
				$organiser_type  = $event->organiser_type;
				$is_program      = $event->is_program;
				$contact_email   = $event->contact_email;
				$event_color     = $event->event_color ?: '#008080';
				$author          = get_userdata( $event->user_id );
				$is_upcoming     = $start_date && strtotime( $start_date ) > current_time( 'timestamp' );
				$thumbnail_url   = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'thumbnail' ) : '';
				$category_name   = '—';
				$category_slug   = '';
				if ( $category_id ) {
					$term = get_term( $category_id, 'event_category' );
					if ( $term && ! is_wp_error( $term ) ) {
						$category_name = $term->name;
						$category_slug = $term->slug;
					}
				}
				?>
				<tr class="hbl-event-row <?php echo $is_upcoming ? 'is-upcoming' : 'is-past'; ?>" data-event-id="<?php echo esc_attr( $event_id ); ?>">
					<th scope="row" class="check-column">
						<label class="screen-reader-text" for="cb-select-<?php echo esc_attr( $event_id ); ?>">
							<?php printf( esc_html__( 'Select %s', 'hbl' ), esc_html( $event->title ) ); ?>
						</label>
						<input id="cb-select-<?php echo esc_attr( $event_id ); ?>" type="checkbox" name="event[]" value="<?php echo esc_attr( $event_id ); ?>" class="hbl-event-checkbox">
					</th>
					<td class="column-image">
						<?php if ( $thumbnail_url ) : ?>
							<div class="hbl-event-thumb">
								<img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="<?php echo esc_attr( $event->title ); ?>">
							</div>
						<?php else : ?>
							<div class="hbl-event-thumb hbl-event-thumb-placeholder" style="background-color: <?php echo esc_attr( $event_color ); ?>;">
								<span class="dashicons dashicons-calendar-alt"></span>
							</div>
						<?php endif; ?>
					</td>
					<td class="column-title">
						<div class="hbl-event-title-wrap">
							<strong class="hbl-event-title">
								<a href="<?php echo esc_url( home_url( '/add-event/?event_id=' . $event_id ) ); ?>">
									<?php echo esc_html( $event->title ); ?>
								</a>
							</strong>
							<?php if ( $venue ) : ?>
								<span class="hbl-event-venue">
									<span class="dashicons dashicons-location"></span>
									<?php echo esc_html( $venue ); ?>
								</span>
							<?php endif; ?>
							<?php if ( $contact_email ) : ?>
								<span class="hbl-event-email">
									<span class="dashicons dashicons-email"></span>
									<?php echo esc_html( $contact_email ); ?>
								</span>
							<?php endif; ?>
						</div>
					</td>
					<td class="column-date">
						<?php if ( $start_date ) :
							$scheduling_type    = $event->scheduling_type ?? 'single';
							$daily_start_time   = $event->daily_start_time ?? '';
							$daily_end_time     = $event->daily_end_time ?? '';
							$start_timestamp    = strtotime( $start_date );
							$end_timestamp      = $end_date ? strtotime( $end_date ) : $start_timestamp;
							if ( $scheduling_type === 'multi' && $end_date && $start_timestamp !== $end_timestamp ) {
								$display_date = date_i18n( 'M j', $start_timestamp ) . ' - ' . date_i18n( 'M j, Y', $end_timestamp );
							} else {
								$display_date = date_i18n( 'F j, Y', $start_timestamp );
							}
							if ( $scheduling_type === 'multi' && $daily_start_time ) {
								$display_start_time = date_i18n( 'g:i A', strtotime( $daily_start_time ) );
								$display_end_time   = $daily_end_time ? date_i18n( 'g:i A', strtotime( $daily_end_time ) ) : '';
							} else {
								$display_start_time = date_i18n( 'g:i A', $start_timestamp );
								$display_end_time   = date_i18n( 'g:i A', $end_timestamp );
							}
						?>
						<div class="hbl-event-date <?php echo $is_upcoming ? 'is-upcoming' : 'is-past'; ?>">
							<span class="hbl-date-primary"><?php echo esc_html( $display_date ); ?></span>
							<?php if ( $event->is_allday ) : ?>
								<span class="hbl-date-time"><?php esc_html_e( 'All Day', 'hbl' ); ?></span>
							<?php else : ?>
								<span class="hbl-date-time">
									<?php
									if ( $display_start_time && $display_end_time && $display_start_time !== $display_end_time ) {
										echo esc_html( $display_start_time . ' - ' . $display_end_time );
									} else {
										echo esc_html( $display_start_time );
									}
									?>
								</span>
							<?php endif; ?>
						</div>
						<?php endif; ?>
					</td>
					<td class="column-type">
						<?php if ( $category_id ) : ?>
							<span class="hbl-badge hbl-badge-type hbl-badge-<?php echo esc_attr( $category_slug ); ?>">
								<?php echo esc_html( $category_name ); ?>
							</span>
						<?php else : ?>
							<span class="hbl-badge hbl-badge-empty">—</span>
						<?php endif; ?>
					</td>
					<td class="column-cost">
						<span class="hbl-badge hbl-badge-cost hbl-badge-<?php echo esc_attr( $event_cost ?: 'free' ); ?>">
							<?php echo $event_cost === 'paid' ? esc_html__( 'Paid', 'hbl' ) : esc_html__( 'Free', 'hbl' ); ?>
						</span>
					</td>
					<td class="column-frequency">
						<?php if ( $event_frequency && isset( $this->frequencies[ $event_frequency ] ) ) : ?>
							<span class="hbl-badge hbl-badge-frequency">
								<?php echo esc_html( $this->frequencies[ $event_frequency ] ); ?>
							</span>
							<?php
							$recurrence_details = $this->get_recurrence_display( $event );
							if ( $recurrence_details ) :
							?>
								<span class="hbl-recurrence-detail" title="<?php echo esc_attr( $recurrence_details ); ?>">
									<?php echo esc_html( $recurrence_details ); ?>
								</span>
							<?php endif; ?>
							<?php if ( $is_program ) : ?>
								<span class="hbl-badge hbl-badge-program"><?php esc_html_e( 'Series', 'hbl' ); ?></span>
							<?php endif; ?>
						<?php else : ?>
							<span class="hbl-badge hbl-badge-empty">—</span>
						<?php endif; ?>
					</td>
					<td class="column-organiser">
						<?php if ( $organiser_type && isset( $this->organiser_types[ $organiser_type ] ) ) : ?>
							<span class="hbl-badge hbl-badge-organiser hbl-badge-<?php echo esc_attr( $organiser_type ); ?>">
								<?php echo esc_html( $this->organiser_types[ $organiser_type ] ); ?>
							</span>
						<?php else : ?>
							<span class="hbl-badge hbl-badge-empty">—</span>
						<?php endif; ?>
					</td>
					<td class="column-author">
						<?php if ( $author ) : ?>
							<div class="hbl-author-info">
								<?php echo get_avatar( $author->ID, 32 ); ?>
								<span><?php echo esc_html( $author->display_name ); ?></span>
							</div>
						<?php endif; ?>
					</td>
					<td class="column-actions">
						<div class="hbl-action-buttons">
							<a href="<?php echo esc_url( hbl_events_db()->get_event_url( $event ) ); ?>" class="button button-small hbl-view-event" target="_blank" title="<?php esc_attr_e( 'View', 'hbl' ); ?>">
								<span class="dashicons dashicons-visibility"></span>
							</a>
							<a href="<?php echo esc_url( home_url( '/add-event/?event_id=' . $event_id ) ); ?>" class="button button-small hbl-edit-event" title="<?php esc_attr_e( 'Edit', 'hbl' ); ?>">
								<span class="dashicons dashicons-edit"></span>
							</a>
							<button type="button" class="button button-small hbl-delete-event" data-event-id="<?php echo esc_attr( $event_id ); ?>" title="<?php esc_attr_e( 'Delete', 'hbl' ); ?>">
								<span class="dashicons dashicons-trash"></span>
							</button>
						</div>
					</td>
				</tr>
				<?php
			endforeach;
		else :
			?>
			<tr>
				<td colspan="10" class="hbl-no-events">
					<div class="hbl-no-events-message">
						<span class="dashicons dashicons-calendar"></span>
						<p><?php esc_html_e( 'No events found matching your criteria.', 'hbl' ); ?></p>
					</div>
				</td>
			</tr>
			<?php
		endif;
		return ob_get_clean();
	}

	/**
	 * Get pagination HTML for dynamic rendering
	 */
	private function get_pagination_html( $total_events, $per_page, $paged, $filter_params = array() ) {
		if ( $total_events <= $per_page ) {
			return '';
		}
		$total_pages = ceil( $total_events / $per_page );
		$base_url    = admin_url( 'admin.php?page=hbl-events' );
		if ( ! empty( $filter_params ) ) {
			$base_url = add_query_arg( $filter_params, $base_url );
		}
		return paginate_links( array(
			'base'      => add_query_arg( 'paged', '%#%', $base_url ),
			'format'    => '',
			'current'   => max( 1, $paged ),
			'total'     => $total_pages,
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
		) ) ?? '';
	}

	/**
	 * AJAX: Dynamic event filtering
	 */
	public function ajax_filter_events() {
		check_ajax_referer( 'hbl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$paged    = absint( $_POST['paged'] ?? 1 );
		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;

		$events = $this->get_events( array( 'limit' => $per_page, 'offset' => $offset ), $_POST );
		$total  = $this->count_events( array(), $_POST );

		$filter_params = array_filter( array(
			's'               => sanitize_text_field( $_POST['s'] ?? '' ),
			'category_id'     => absint( $_POST['category_id'] ?? 0 ),
			'event_cost'      => sanitize_text_field( $_POST['event_cost'] ?? '' ),
			'event_frequency' => sanitize_text_field( $_POST['event_frequency'] ?? '' ),
			'organiser_type'  => sanitize_text_field( $_POST['organiser_type'] ?? '' ),
			'author_id'       => absint( $_POST['author_id'] ?? 0 ),
		) );

		$rows_html       = $this->get_event_rows_html( $events );
		$pagination_html = $this->get_pagination_html( $total, $per_page, $paged, $filter_params );

		wp_send_json_success( array(
			'rows'       => $rows_html,
			'pagination' => $pagination_html,
			'total'      => $total,
		) );
	}

	/**
	 * Get event statistics from custom database table
	 */
	private function get_event_stats() {
		$db = hbl_events_db();
		return $db->get_stats();
	}

	/**
	 * Render events page
	 */
	public function render_events_page() {
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$per_page = 20;
		$offset = ( $paged - 1 ) * $per_page;
		
		$events = $this->get_events( array( 
			'limit'  => $per_page, 
			'offset' => $offset,
		) );
		$total_events = $this->count_events();
		$total_pages = ceil( $total_events / $per_page );
		$stats = $this->get_event_stats();
		
		// Get categories for filter
		$categories = get_terms( array(
			'taxonomy'   => 'event_category',
			'hide_empty' => false,
		) );

		// Get users who have submitted events
		$db_instance = hbl_events_db();
		global $wpdb;
		$event_user_ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT user_id FROM %i WHERE user_id > 0 ORDER BY user_id',
			$db_instance->get_table_name()
		) );
		$event_users = array();
		foreach ( $event_user_ids as $uid ) {
			$u = get_userdata( (int) $uid );
			if ( $u ) {
				$event_users[] = $u;
			}
		}
		?>
		<div class="wrap hbl-events-admin">
			<h1 class="wp-heading-inline">
				<span class="dashicons dashicons-calendar-alt"></span>
				<?php esc_html_e( 'HBL Events', 'hbl' ); ?>
			</h1>

			<!-- Stats Cards -->
			<div class="hbl-admin-stats">
				<div class="hbl-stat-card hbl-stat-total">
					<div class="hbl-stat-icon">
						<span class="dashicons dashicons-calendar"></span>
					</div>
					<div class="hbl-stat-content">
						<span class="hbl-stat-number"><?php echo esc_html( $stats['total'] ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Total Events', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-upcoming">
					<div class="hbl-stat-icon">
						<span class="dashicons dashicons-clock"></span>
					</div>
					<div class="hbl-stat-content">
						<span class="hbl-stat-number"><?php echo esc_html( $stats['upcoming'] ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Upcoming', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-free">
					<div class="hbl-stat-icon">
						<span class="dashicons dashicons-yes-alt"></span>
					</div>
					<div class="hbl-stat-content">
						<span class="hbl-stat-number"><?php echo esc_html( $stats['free'] ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Free Events', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-paid">
					<div class="hbl-stat-icon">
						<span class="dashicons dashicons-money-alt"></span>
					</div>
					<div class="hbl-stat-content">
						<span class="hbl-stat-number"><?php echo esc_html( $stats['paid'] ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Paid Events', 'hbl' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Filters -->
			<div class="hbl-admin-filters">
				<form method="get" class="hbl-filter-form">
					<input type="hidden" name="page" value="hbl-events">
					
					<div class="hbl-filter-row">
						<div class="hbl-filter-group">
							<label><?php esc_html_e( 'Search', 'hbl' ); ?></label>
							<input type="text" name="s" value="<?php echo esc_attr( $_GET['s'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Search events...', 'hbl' ); ?>">
						</div>

						<div class="hbl-filter-group">
							<label><?php esc_html_e( 'Category', 'hbl' ); ?></label>
							<select name="category_id">
								<option value=""><?php esc_html_e( 'All Types', 'hbl' ); ?></option>
								<?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
									<?php foreach ( $categories as $category ) : ?>
										<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $_GET['category_id'] ?? '', $category->term_id ); ?>><?php echo esc_html( $category->name ); ?></option>
									<?php endforeach; ?>
								<?php endif; ?>
							</select>
						</div>

						<div class="hbl-filter-group">
							<label><?php esc_html_e( 'Cost', 'hbl' ); ?></label>
							<select name="event_cost">
								<option value=""><?php esc_html_e( 'All', 'hbl' ); ?></option>
								<option value="free" <?php selected( $_GET['event_cost'] ?? '', 'free' ); ?>><?php esc_html_e( 'Free', 'hbl' ); ?></option>
								<option value="paid" <?php selected( $_GET['event_cost'] ?? '', 'paid' ); ?>><?php esc_html_e( 'Paid', 'hbl' ); ?></option>
							</select>
						</div>

						<div class="hbl-filter-group">
							<label><?php esc_html_e( 'Frequency', 'hbl' ); ?></label>
							<select name="event_frequency">
								<option value=""><?php esc_html_e( 'All', 'hbl' ); ?></option>
								<?php foreach ( $this->frequencies as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $_GET['event_frequency'] ?? '', $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="hbl-filter-group">
							<label><?php esc_html_e( 'Organiser', 'hbl' ); ?></label>
							<select name="organiser_type">
								<option value=""><?php esc_html_e( 'All', 'hbl' ); ?></option>
								<?php foreach ( $this->organiser_types as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $_GET['organiser_type'] ?? '', $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="hbl-filter-group">
							<label><?php esc_html_e( 'Submitted By', 'hbl' ); ?></label>
							<select name="author_id">
								<option value=""><?php esc_html_e( 'All Users', 'hbl' ); ?></option>
								<?php foreach ( $event_users as $event_user ) : ?>
									<option value="<?php echo esc_attr( $event_user->ID ); ?>" <?php selected( $_GET['author_id'] ?? '', $event_user->ID ); ?>><?php echo esc_html( $event_user->display_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="hbl-filter-group hbl-filter-actions">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'hbl' ); ?></button>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=hbl-events' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'hbl' ); ?></a>
						</div>
					</div>
				</form>
			</div>

			<!-- Bulk Actions (Top) -->
			<div class="hbl-bulk-actions tablenav top">
				<div class="alignleft actions bulkactions">
					<label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'hbl' ); ?></label>
					<select name="action" id="bulk-action-selector-top" class="hbl-bulk-action-select">
						<option value="-1"><?php esc_html_e( 'Bulk Actions', 'hbl' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'hbl' ); ?></option>
						<option value="publish"><?php esc_html_e( 'Set to Published', 'hbl' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Set to Pending', 'hbl' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Set to Draft', 'hbl' ); ?></option>
					</select>
					<button type="button" class="button action hbl-apply-bulk-action" data-position="top"><?php esc_html_e( 'Apply', 'hbl' ); ?></button>
				</div>
				<div class="hbl-selected-count alignleft">
					<span class="hbl-selected-count-number">0</span> <?php esc_html_e( 'selected', 'hbl' ); ?>
				</div>
			</div>

			<!-- Results count bar -->
			<div class="hbl-results-bar">
				<span id="hbl-results-count"><?php echo esc_html( sprintf( _n( '%d event', '%d events', $total_events, 'hbl' ), $total_events ) ); ?></span>
			</div>

			<!-- Events Table -->
			<div class="hbl-events-table-wrap" id="hbl-table-wrap">
				<div class="hbl-table-loading" id="hbl-table-loading" style="display:none;">
					<span class="spinner is-active"></span>
				</div>
				<table class="hbl-events-table wp-list-table widefat striped">
					<thead>
						<tr>
							<th class="column-cb check-column">
								<label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e( 'Select All', 'hbl' ); ?></label>
								<input id="cb-select-all-1" type="checkbox" class="hbl-select-all">
							</th>
							<th class="column-image"><?php esc_html_e( 'Image', 'hbl' ); ?></th>
							<th class="column-title"><?php esc_html_e( 'Event', 'hbl' ); ?></th>
							<th class="column-date"><?php esc_html_e( 'Date/Time', 'hbl' ); ?></th>
							<th class="column-type"><?php esc_html_e( 'Category', 'hbl' ); ?></th>
							<th class="column-cost"><?php esc_html_e( 'Cost', 'hbl' ); ?></th>
							<th class="column-frequency"><?php esc_html_e( 'Frequency', 'hbl' ); ?></th>
							<th class="column-organiser"><?php esc_html_e( 'Organiser', 'hbl' ); ?></th>
							<th class="column-author"><?php esc_html_e( 'Submitted By', 'hbl' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'hbl' ); ?></th>
						</tr>
					</thead>
					<tbody id="hbl-events-tbody">
						<?php echo $this->get_event_rows_html( $events ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</tbody>
					<tfoot>
						<tr>
							<th class="column-cb check-column">
								<label class="screen-reader-text" for="cb-select-all-2"><?php esc_html_e( 'Select All', 'hbl' ); ?></label>
								<input id="cb-select-all-2" type="checkbox" class="hbl-select-all">
							</th>
							<th class="column-image"><?php esc_html_e( 'Image', 'hbl' ); ?></th>
							<th class="column-title"><?php esc_html_e( 'Event', 'hbl' ); ?></th>
							<th class="column-date"><?php esc_html_e( 'Date/Time', 'hbl' ); ?></th>
							<th class="column-type"><?php esc_html_e( 'Type', 'hbl' ); ?></th>
							<th class="column-cost"><?php esc_html_e( 'Cost', 'hbl' ); ?></th>
							<th class="column-frequency"><?php esc_html_e( 'Frequency', 'hbl' ); ?></th>
							<th class="column-organiser"><?php esc_html_e( 'Organiser', 'hbl' ); ?></th>
							<th class="column-author"><?php esc_html_e( 'Submitted By', 'hbl' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'hbl' ); ?></th>
						</tr>
					</tfoot>
				</table>
			</div>

			<!-- Bulk Actions (Bottom) -->
			<div class="hbl-bulk-actions tablenav bottom">
				<div class="alignleft actions bulkactions">
					<label for="bulk-action-selector-bottom" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'hbl' ); ?></label>
					<select name="action2" id="bulk-action-selector-bottom" class="hbl-bulk-action-select">
						<option value="-1"><?php esc_html_e( 'Bulk Actions', 'hbl' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'hbl' ); ?></option>
						<option value="publish"><?php esc_html_e( 'Set to Published', 'hbl' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Set to Pending', 'hbl' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Set to Draft', 'hbl' ); ?></option>
					</select>
					<button type="button" class="button action hbl-apply-bulk-action" data-position="bottom"><?php esc_html_e( 'Apply', 'hbl' ); ?></button>
				</div>
				<div class="hbl-selected-count alignleft">
					<span class="hbl-selected-count-number">0</span> <?php esc_html_e( 'selected', 'hbl' ); ?>
				</div>
			</div>

			<!-- Pagination -->
			<div class="hbl-admin-pagination" id="hbl-events-pagination">
				<?php
				$filter_params = array_filter( array(
					's'               => $_GET['s'] ?? '',
					'category_id'     => $_GET['category_id'] ?? '',
					'event_cost'      => $_GET['event_cost'] ?? '',
					'event_frequency' => $_GET['event_frequency'] ?? '',
					'organiser_type'  => $_GET['organiser_type'] ?? '',
					'author_id'       => $_GET['author_id'] ?? '',
				) );
				echo $this->get_pagination_html( $total_events, $per_page, $paged, $filter_params ); // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render analytics page
	 */
	public function render_analytics_page() {
		$stats = $this->get_event_stats();
		?>
		<div class="wrap hbl-events-admin hbl-events-analytics">
			<h1>
				<span class="dashicons dashicons-chart-bar"></span>
				<?php esc_html_e( 'Event Analytics', 'hbl' ); ?>
			</h1>

			<div class="hbl-analytics-grid">
				<!-- Overview Card -->
				<div class="hbl-analytics-card hbl-analytics-overview">
					<h2><?php esc_html_e( 'Overview', 'hbl' ); ?></h2>
					<div class="hbl-overview-stats">
						<div class="hbl-overview-stat">
							<span class="hbl-overview-number"><?php echo esc_html( $stats['total'] ); ?></span>
							<span class="hbl-overview-label"><?php esc_html_e( 'Total Events', 'hbl' ); ?></span>
						</div>
						<div class="hbl-overview-stat">
							<span class="hbl-overview-number"><?php echo esc_html( $stats['upcoming'] ); ?></span>
							<span class="hbl-overview-label"><?php esc_html_e( 'Upcoming', 'hbl' ); ?></span>
						</div>
						<div class="hbl-overview-stat">
							<span class="hbl-overview-number"><?php echo esc_html( $stats['past'] ); ?></span>
							<span class="hbl-overview-label"><?php esc_html_e( 'Past', 'hbl' ); ?></span>
						</div>
					</div>
				</div>

				<!-- Cost Breakdown -->
				<div class="hbl-analytics-card">
					<h2><?php esc_html_e( 'Cost Breakdown', 'hbl' ); ?></h2>
					<div class="hbl-chart-bars">
						<?php 
						$free_pct = $stats['total'] > 0 ? round( ( $stats['free'] / $stats['total'] ) * 100 ) : 0;
						$paid_pct = $stats['total'] > 0 ? round( ( $stats['paid'] / $stats['total'] ) * 100 ) : 0;
						?>
						<div class="hbl-chart-bar-item">
							<div class="hbl-chart-bar-label">
								<span class="hbl-chart-bar-name"><?php esc_html_e( 'Free', 'hbl' ); ?></span>
								<span class="hbl-chart-bar-value"><?php echo esc_html( $stats['free'] ); ?> (<?php echo esc_html( $free_pct ); ?>%)</span>
							</div>
							<div class="hbl-chart-bar">
								<div class="hbl-chart-bar-fill hbl-bar-free" style="width: <?php echo esc_attr( $free_pct ); ?>%;"></div>
							</div>
						</div>
						<div class="hbl-chart-bar-item">
							<div class="hbl-chart-bar-label">
								<span class="hbl-chart-bar-name"><?php esc_html_e( 'Paid', 'hbl' ); ?></span>
								<span class="hbl-chart-bar-value"><?php echo esc_html( $stats['paid'] ); ?> (<?php echo esc_html( $paid_pct ); ?>%)</span>
							</div>
							<div class="hbl-chart-bar">
								<div class="hbl-chart-bar-fill hbl-bar-paid" style="width: <?php echo esc_attr( $paid_pct ); ?>%;"></div>
							</div>
						</div>
					</div>
				</div>

				<!-- By Event Category -->
				<div class="hbl-analytics-card">
					<h2><?php esc_html_e( 'By Event Category', 'hbl' ); ?></h2>
					<div class="hbl-chart-bars">
						<?php 
                        // Calculate stats by category
                        $categories = get_terms( array(
                            'taxonomy'   => 'event_category',
                            'hide_empty' => false,
                        ) );
                        
                        if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
                            // Get all events to count categories
                            $events = $this->get_events( array( 'limit' => -1 ) );
                            $category_counts = array();
                            
                            // Initialize counts
                            foreach ( $categories as $category ) {
                                $category_counts[ $category->term_id ] = 0;
                            }
                            
                            // Count events per category
                            foreach ( $events as $event ) {
                                if ( ! empty( $event->category_id ) && isset( $category_counts[ $event->category_id ] ) ) {
                                    $category_counts[ $event->category_id ]++;
                                }
                            }
                            
                            // Display bars
                            foreach ( $categories as $category ) {
                                $count = $category_counts[ $category->term_id ];
                                $pct = $stats['total'] > 0 ? round( ( $count / $stats['total'] ) * 100 ) : 0;
                                // Generate a color based on ID or use term meta color
                                $color = get_term_meta( $category->term_id, '_hbl_category_color', true ) ?: '#008080';
                                ?>
                                <div class="hbl-chart-bar-item">
                                    <div class="hbl-chart-bar-label">
                                        <span class="hbl-chart-bar-name"><?php echo esc_html( $category->name ); ?></span>
                                        <span class="hbl-chart-bar-value"><?php echo esc_html( $count ); ?></span>
                                    </div>
                                    <div class="hbl-chart-bar">
                                        <div class="hbl-chart-bar-fill" style="width: <?php echo esc_attr( $pct ); ?>%; background-color: <?php echo esc_attr( $color ); ?>;"></div>
                                    </div>
                                </div>
                                <?php
                            }
                        } else {
                            echo '<p>' . esc_html__( 'No categories found.', 'hbl' ) . '</p>';
                        }
                        ?>
					</div>
				</div>

				<!-- By Frequency -->
				<div class="hbl-analytics-card">
					<h2><?php esc_html_e( 'By Frequency', 'hbl' ); ?></h2>
					<div class="hbl-chart-bars">
						<?php foreach ( $this->frequencies as $key => $label ) : 
							$count = $stats['by_frequency'][ $key ] ?? 0;
							$pct = $stats['total'] > 0 ? round( ( $count / $stats['total'] ) * 100 ) : 0;
						?>
						<div class="hbl-chart-bar-item">
							<div class="hbl-chart-bar-label">
								<span class="hbl-chart-bar-name"><?php echo esc_html( $label ); ?></span>
								<span class="hbl-chart-bar-value"><?php echo esc_html( $count ); ?></span>
							</div>
							<div class="hbl-chart-bar">
								<div class="hbl-chart-bar-fill hbl-bar-frequency" style="width: <?php echo esc_attr( $pct ); ?>%;"></div>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- By Organiser Type -->
				<div class="hbl-analytics-card">
					<h2><?php esc_html_e( 'By Organiser Type', 'hbl' ); ?></h2>
					<div class="hbl-chart-bars">
						<?php foreach ( $this->organiser_types as $key => $label ) : 
							$count = $stats['by_organiser'][ $key ] ?? 0;
							$pct = $stats['total'] > 0 ? round( ( $count / $stats['total'] ) * 100 ) : 0;
						?>
						<div class="hbl-chart-bar-item">
							<div class="hbl-chart-bar-label">
								<span class="hbl-chart-bar-name"><?php echo esc_html( $label ); ?></span>
								<span class="hbl-chart-bar-value"><?php echo esc_html( $count ); ?></span>
							</div>
							<div class="hbl-chart-bar">
								<div class="hbl-chart-bar-fill hbl-bar-organiser-<?php echo esc_attr( $key ); ?>" style="width: <?php echo esc_attr( $pct ); ?>%;"></div>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Quick Insights -->
				<div class="hbl-analytics-card hbl-analytics-insights">
					<h2><?php esc_html_e( 'Quick Insights', 'hbl' ); ?></h2>
					<ul class="hbl-insights-list">
						<?php
						// Most common event type
						if ( ! empty( $stats['by_type'] ) ) {
							arsort( $stats['by_type'] );
							$top_type = array_key_first( $stats['by_type'] );
							if ( isset( $this->event_types[ $top_type ] ) ) {
								echo '<li><span class="dashicons dashicons-yes"></span> ' . sprintf( 
									esc_html__( 'Most common type: %s (%d events)', 'hbl' ),
									esc_html( $this->event_types[ $top_type ] ),
									esc_html( $stats['by_type'][ $top_type ] )
								) . '</li>';
							}
						}

						// Most common organiser
						if ( ! empty( $stats['by_organiser'] ) ) {
							arsort( $stats['by_organiser'] );
							$top_organiser = array_key_first( $stats['by_organiser'] );
							if ( isset( $this->organiser_types[ $top_organiser ] ) ) {
								echo '<li><span class="dashicons dashicons-groups"></span> ' . sprintf( 
									esc_html__( 'Most events by: %s (%d events)', 'hbl' ),
									esc_html( $this->organiser_types[ $top_organiser ] ),
									esc_html( $stats['by_organiser'][ $top_organiser ] )
								) . '</li>';
							}
						}

						// Free vs paid ratio
						if ( $stats['total'] > 0 ) {
							$free_ratio = round( ( $stats['free'] / $stats['total'] ) * 100 );
							echo '<li><span class="dashicons dashicons-chart-pie"></span> ' . sprintf( 
								esc_html__( '%d%% of events are free to attend', 'hbl' ),
								esc_html( $free_ratio )
							) . '</li>';
						}

						// Recurring events
						$recurring_count = ( $stats['by_frequency']['weekly'] ?? 0 ) + ( $stats['by_frequency']['monthly'] ?? 0 ) + ( $stats['by_frequency']['recurring'] ?? 0 );
						if ( $recurring_count > 0 ) {
							echo '<li><span class="dashicons dashicons-update"></span> ' . sprintf( 
								esc_html__( '%d recurring events in the system', 'hbl' ),
								esc_html( $recurring_count )
							) . '</li>';
						}
						?>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get human-readable recurrence display
	 *
	 * @param object $event Event object
	 * @return string Recurrence description
	 */
	private function get_recurrence_display( $event ) {
		if ( empty( $event->event_frequency ) || $event->event_frequency === 'once' || $event->event_frequency === 'recurring' ) {
			return '';
		}

		$parts = array();

		if ( $event->event_frequency === 'weekly' ) {
			// Weekly recurrence
			$interval = ! empty( $event->recurrence_interval ) ? (int) $event->recurrence_interval : 1;
			
			if ( $interval === 2 ) {
				$parts[] = __( 'Every 2nd week', 'hbl' );
			}

			// Days
			if ( ! empty( $event->recurrence_days ) ) {
				$days = explode( ',', $event->recurrence_days );
				$day_names = array();
				foreach ( $days as $day ) {
					if ( isset( $this->day_labels[ $day ] ) ) {
						$day_names[] = $this->day_labels[ $day ];
					}
				}
				if ( ! empty( $day_names ) ) {
					$parts[] = implode( ', ', $day_names );
				}
			}
		} elseif ( $event->event_frequency === 'monthly' ) {
			// Monthly recurrence
			$week_parts = array();
			
			// Weeks
			if ( ! empty( $event->recurrence_week ) ) {
				$weeks = explode( ',', $event->recurrence_week );
				foreach ( $weeks as $week ) {
					if ( isset( $this->week_labels[ $week ] ) ) {
						$week_parts[] = $this->week_labels[ $week ];
					}
				}
			}

			// Day
			if ( ! empty( $event->recurrence_days ) ) {
				$day = $event->recurrence_days;
				if ( isset( $this->day_labels[ $day ] ) ) {
					if ( ! empty( $week_parts ) ) {
						$parts[] = implode( ' & ', $week_parts ) . ' ' . $this->day_labels[ $day ];
					} else {
						$parts[] = $this->day_labels[ $day ];
					}
				}
			} elseif ( ! empty( $week_parts ) ) {
				$parts[] = implode( ' & ', $week_parts ) . ' ' . __( 'week', 'hbl' );
			}
		}

		return implode( ' · ', $parts );
	}

	/**
	 * AJAX: Delete event
	 */
	public function ajax_delete_event() {
		check_ajax_referer( 'hbl_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;

		if ( ! $event_id ) {
			wp_send_json_error( array( 'message' => 'Invalid event ID' ) );
		}

		// Use custom database to delete event
		$db = hbl_events_db();
		$result = $db->delete( $event_id );

		if ( $result ) {
			wp_send_json_success( array( 'message' => 'Event deleted successfully' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Failed to delete event' ) );
		}
	}

	/**
	 * AJAX handler for bulk delete events
	 */
	public function ajax_bulk_delete_events() {
		check_ajax_referer( 'hbl_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$event_ids = isset( $_POST['event_ids'] ) ? array_map( 'absint', $_POST['event_ids'] ) : array();

		if ( empty( $event_ids ) ) {
			wp_send_json_error( array( 'message' => 'No events selected' ) );
		}

		$db = hbl_events_db();
		$deleted = 0;
		$failed = 0;

		foreach ( $event_ids as $event_id ) {
			if ( $db->delete( $event_id ) ) {
				$deleted++;
			} else {
				$failed++;
			}
		}

		if ( $deleted > 0 ) {
			wp_send_json_success( array( 
				'message' => sprintf( 
					_n( '%d event deleted successfully.', '%d events deleted successfully.', $deleted, 'hbl' ),
					$deleted
				),
				'deleted' => $deleted,
				'failed'  => $failed,
			) );
		} else {
			wp_send_json_error( array( 'message' => 'Failed to delete events' ) );
		}
	}

	/**
	 * AJAX handler for bulk update events (change status)
	 */
	public function ajax_bulk_update_events() {
		check_ajax_referer( 'hbl_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$event_ids = isset( $_POST['event_ids'] ) ? array_map( 'absint', $_POST['event_ids'] ) : array();
		$status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : '';

		if ( empty( $event_ids ) ) {
			wp_send_json_error( array( 'message' => 'No events selected' ) );
		}

		if ( ! in_array( $status, array( 'publish', 'pending', 'draft' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid status' ) );
		}

		$db = hbl_events_db();
		$updated = 0;
		$failed = 0;

		foreach ( $event_ids as $event_id ) {
			$result = $db->update( $event_id, array( 'status' => $status ) );
			if ( $result !== false ) {
				$updated++;
			} else {
				$failed++;
			}
		}

		$status_labels = array(
			'publish' => __( 'Published', 'hbl' ),
			'pending' => __( 'Pending', 'hbl' ),
			'draft'   => __( 'Draft', 'hbl' ),
		);

		if ( $updated > 0 ) {
			wp_send_json_success( array( 
				'message' => sprintf( 
					_n( '%d event updated to %s.', '%d events updated to %s.', $updated, 'hbl' ),
					$updated,
					$status_labels[ $status ]
				),
				'updated' => $updated,
				'failed'  => $failed,
			) );
		} else {
			wp_send_json_error( array( 'message' => 'Failed to update events' ) );
		}
	}

	/**
	 * Render categories management page
	 */
	public function render_categories_page() {
		// Handle form submissions
		$message = '';
		$message_type = '';

		// Get all categories
		$categories = get_terms( array(
			'taxonomy'   => 'event_category',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );

		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}

		// Get parent categories for dropdown
		$parent_categories = array_filter( $categories, function( $cat ) {
			return $cat->parent == 0;
		});
		?>
		<div class="wrap hbl-events-admin hbl-events-categories">
			<h1>
				<span class="dashicons dashicons-category"></span>
				<?php esc_html_e( 'Event Categories', 'hbl' ); ?>
			</h1>

			<?php if ( $message ) : ?>
				<div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<div class="hbl-categories-layout">
				<!-- Add New Category Form -->
				<div class="hbl-category-form-card">
					<h2><?php esc_html_e( 'Add New Category', 'hbl' ); ?></h2>
					<form id="hbl-add-category-form" class="hbl-category-form">
						<div class="hbl-form-field">
							<label for="category_name"><?php esc_html_e( 'Name', 'hbl' ); ?> <span class="required">*</span></label>
							<input type="text" id="category_name" name="category_name" required>
						</div>

						<div class="hbl-form-field">
							<label for="category_slug"><?php esc_html_e( 'Slug', 'hbl' ); ?></label>
							<input type="text" id="category_slug" name="category_slug" placeholder="<?php esc_attr_e( 'auto-generated from name', 'hbl' ); ?>">
						</div>

						<div class="hbl-form-field">
							<label for="category_parent"><?php esc_html_e( 'Parent Category', 'hbl' ); ?></label>
							<select id="category_parent" name="category_parent">
								<option value="0"><?php esc_html_e( '— None (Top Level) —', 'hbl' ); ?></option>
								<?php foreach ( $parent_categories as $parent ) : ?>
									<option value="<?php echo esc_attr( $parent->term_id ); ?>"><?php echo esc_html( $parent->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="hbl-form-field">
							<label for="category_description"><?php esc_html_e( 'Description', 'hbl' ); ?></label>
							<textarea id="category_description" name="category_description" rows="3"></textarea>
						</div>

						<div class="hbl-form-field">
							<label for="category_color"><?php esc_html_e( 'Color', 'hbl' ); ?></label>
							<div class="hbl-color-picker-wrap">
								<input type="color" id="category_color" name="category_color" value="#008080">
								<span class="hbl-color-value">#008080</span>
							</div>
						</div>

						<div class="hbl-form-field">
							<label for="category_icon"><?php esc_html_e( 'Icon (Dashicon)', 'hbl' ); ?></label>
							<input type="text" id="category_icon" name="category_icon" placeholder="dashicons-star-filled">
							<p class="description"><?php echo sprintf( __( 'Enter a <a href="%s" target="_blank">Dashicon</a> class name', 'hbl' ), 'https://developer.wordpress.org/resource/dashicons/' ); ?></p>
						</div>

						<button type="submit" class="button button-primary">
							<span class="dashicons dashicons-plus-alt"></span>
							<?php esc_html_e( 'Add Category', 'hbl' ); ?>
						</button>
					</form>
				</div>

				<!-- Categories List -->
				<div class="hbl-categories-list-card">
					<h2><?php esc_html_e( 'All Categories', 'hbl' ); ?> <span class="hbl-count">(<?php echo count( $categories ); ?>)</span></h2>
					
					<?php if ( ! empty( $categories ) ) : ?>
						<table class="hbl-categories-table wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th class="column-color"><?php esc_html_e( 'Color', 'hbl' ); ?></th>
									<th class="column-name"><?php esc_html_e( 'Name', 'hbl' ); ?></th>
									<th class="column-slug"><?php esc_html_e( 'Slug', 'hbl' ); ?></th>
									<th class="column-count"><?php esc_html_e( 'Events', 'hbl' ); ?></th>
									<th class="column-actions"><?php esc_html_e( 'Actions', 'hbl' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
						// Pre-calculate counts from custom DB
						$db = hbl_events_db();
						$events_all = $db->get_events( array( 'limit' => -1 ) );
						$category_real_counts = array();
						if ( $events_all ) {
							foreach ( $events_all as $evt ) {
								if ( ! empty( $evt->category_id ) ) {
									if ( ! isset( $category_real_counts[ $evt->category_id ] ) ) {
										$category_real_counts[ $evt->category_id ] = 0;
									}
									$category_real_counts[ $evt->category_id ]++;
								}
							}
						}

						foreach ( $categories as $category ) : 
									$color = get_term_meta( $category->term_id, '_hbl_category_color', true ) ?: '#008080';
									$icon = get_term_meta( $category->term_id, '_hbl_category_icon', true ) ?: '';
									$is_child = $category->parent > 0;
									$parent_name = '';
									if ( $is_child ) {
										$parent = get_term( $category->parent, 'event_category' );
										$parent_name = $parent ? $parent->name : '';
									}
									
									// Use custom count
									$real_count = isset( $category_real_counts[ $category->term_id ] ) ? $category_real_counts[ $category->term_id ] : 0;
								?>
								<tr data-category-id="<?php echo esc_attr( $category->term_id ); ?>" class="<?php echo $is_child ? 'is-child' : 'is-parent'; ?>">
									<td class="column-color">
										<span class="hbl-category-color-dot" style="background-color: <?php echo esc_attr( $color ); ?>;"></span>
									</td>
									<td class="column-name">
										<?php if ( $is_child ) : ?>
											<span class="hbl-child-indicator">— </span>
										<?php endif; ?>
										<?php if ( $icon ) : ?>
											<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
										<?php endif; ?>
										<strong><?php echo esc_html( $category->name ); ?></strong>
										<?php if ( $is_child && $parent_name ) : ?>
											<span class="hbl-parent-name"><?php echo esc_html( $parent_name ); ?></span>
										<?php endif; ?>
										<?php if ( $category->description ) : ?>
											<p class="hbl-category-description"><?php echo esc_html( wp_trim_words( $category->description, 10 ) ); ?></p>
										<?php endif; ?>
									</td>
									<td class="column-slug">
										<code><?php echo esc_html( $category->slug ); ?></code>
									</td>
									<td class="column-count">
										<?php if ( $real_count > 0 ) : ?>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=hbl-events&event_category=' . $category->term_id ) ); ?>" class="hbl-count-link">
												<?php echo esc_html( $real_count ); ?>
											</a>
										<?php else : ?>
											<span class="hbl-count-zero">0</span>
										<?php endif; ?>
									</td>
								<td class="column-actions">
									<div class="hbl-action-buttons">
										<a href="<?php echo esc_url( get_term_link( $category, 'event_category' ) ); ?>" class="button button-small hbl-view-category" target="_blank" title="<?php esc_attr_e( 'View', 'hbl' ); ?>">
											<span class="dashicons dashicons-visibility"></span>
										</a>
										<button type="button" class="button button-small hbl-edit-category" 
											data-id="<?php echo esc_attr( $category->term_id ); ?>"
											data-name="<?php echo esc_attr( $category->name ); ?>"
											data-slug="<?php echo esc_attr( $category->slug ); ?>"
											data-parent="<?php echo esc_attr( $category->parent ); ?>"
											data-description="<?php echo esc_attr( $category->description ); ?>"
											data-color="<?php echo esc_attr( $color ); ?>"
											data-icon="<?php echo esc_attr( $icon ); ?>"
											title="<?php esc_attr_e( 'Edit', 'hbl' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<button type="button" class="button button-small hbl-delete-category" 
											data-id="<?php echo esc_attr( $category->term_id ); ?>"
											data-name="<?php echo esc_attr( $category->name ); ?>"
											title="<?php esc_attr_e( 'Delete', 'hbl' ); ?>">
											<span class="dashicons dashicons-trash"></span>
										</button>
									</div>
								</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<div class="hbl-no-categories">
							<span class="dashicons dashicons-category"></span>
							<p><?php esc_html_e( 'No event categories found. Create your first category using the form.', 'hbl' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Edit Category Modal -->
			<div id="hbl-edit-category-modal" class="hbl-modal" style="display: none;">
				<div class="hbl-modal-overlay"></div>
				<div class="hbl-modal-content">
					<div class="hbl-modal-header">
						<h3><?php esc_html_e( 'Edit Category', 'hbl' ); ?></h3>
						<button type="button" class="hbl-modal-close">&times;</button>
					</div>
					<form id="hbl-edit-category-form" class="hbl-category-form">
						<input type="hidden" id="edit_category_id" name="category_id">
						
						<div class="hbl-form-field">
							<label for="edit_category_name"><?php esc_html_e( 'Name', 'hbl' ); ?> <span class="required">*</span></label>
							<input type="text" id="edit_category_name" name="category_name" required>
						</div>

						<div class="hbl-form-field">
							<label for="edit_category_slug"><?php esc_html_e( 'Slug', 'hbl' ); ?></label>
							<input type="text" id="edit_category_slug" name="category_slug">
						</div>

						<div class="hbl-form-field">
							<label for="edit_category_parent"><?php esc_html_e( 'Parent Category', 'hbl' ); ?></label>
							<select id="edit_category_parent" name="category_parent">
								<option value="0"><?php esc_html_e( '— None (Top Level) —', 'hbl' ); ?></option>
								<?php foreach ( $parent_categories as $parent ) : ?>
									<option value="<?php echo esc_attr( $parent->term_id ); ?>"><?php echo esc_html( $parent->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="hbl-form-field">
							<label for="edit_category_description"><?php esc_html_e( 'Description', 'hbl' ); ?></label>
							<textarea id="edit_category_description" name="category_description" rows="3"></textarea>
						</div>

						<div class="hbl-form-field">
							<label for="edit_category_color"><?php esc_html_e( 'Color', 'hbl' ); ?></label>
							<div class="hbl-color-picker-wrap">
								<input type="color" id="edit_category_color" name="category_color" value="#008080">
								<span class="hbl-color-value">#008080</span>
							</div>
						</div>

						<div class="hbl-form-field">
							<label for="edit_category_icon"><?php esc_html_e( 'Icon (Dashicon)', 'hbl' ); ?></label>
							<input type="text" id="edit_category_icon" name="category_icon" placeholder="dashicons-star-filled">
						</div>

						<div class="hbl-modal-actions">
							<button type="button" class="button hbl-modal-cancel"><?php esc_html_e( 'Cancel', 'hbl' ); ?></button>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Update Category', 'hbl' ); ?></button>
						</div>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Add category
	 */
	public function ajax_add_category() {
		check_ajax_referer( 'hbl_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
		$slug = isset( $_POST['slug'] ) ? sanitize_title( $_POST['slug'] ) : '';
		$parent = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( $_POST['description'] ) : '';
		$color = isset( $_POST['color'] ) ? sanitize_hex_color( $_POST['color'] ) : '#008080';
		$icon = isset( $_POST['icon'] ) ? sanitize_text_field( $_POST['icon'] ) : '';

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => 'Category name is required' ) );
		}

		$term_args = array(
			'description' => $description,
			'parent'      => $parent,
		);

		if ( ! empty( $slug ) ) {
			$term_args['slug'] = $slug;
		}

		$result = wp_insert_term( $name, 'event_category', $term_args );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$term_id = $result['term_id'];

		// Save custom meta
		update_term_meta( $term_id, '_hbl_category_color', $color );
		update_term_meta( $term_id, '_hbl_category_icon', $icon );

		wp_send_json_success( array( 
			'message' => 'Category created successfully',
			'term_id' => $term_id,
		) );
	}

	/**
	 * AJAX: Edit category
	 */
	public function ajax_edit_category() {
		check_ajax_referer( 'hbl_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
		$slug = isset( $_POST['slug'] ) ? sanitize_title( $_POST['slug'] ) : '';
		$parent = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( $_POST['description'] ) : '';
		$color = isset( $_POST['color'] ) ? sanitize_hex_color( $_POST['color'] ) : '#008080';
		$icon = isset( $_POST['icon'] ) ? sanitize_text_field( $_POST['icon'] ) : '';

		if ( ! $term_id ) {
			wp_send_json_error( array( 'message' => 'Invalid category ID' ) );
		}

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => 'Category name is required' ) );
		}

		// Prevent setting self as parent
		if ( $parent === $term_id ) {
			$parent = 0;
		}

		$result = wp_update_term( $term_id, 'event_category', array(
			'name'        => $name,
			'slug'        => $slug,
			'parent'      => $parent,
			'description' => $description,
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Save custom meta
		update_term_meta( $term_id, '_hbl_category_color', $color );
		update_term_meta( $term_id, '_hbl_category_icon', $icon );

		wp_send_json_success( array( 'message' => 'Category updated successfully' ) );
	}

	/**
	 * AJAX: Delete category
	 */
	public function ajax_delete_category() {
		check_ajax_referer( 'hbl_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;

		if ( ! $term_id ) {
			wp_send_json_error( array( 'message' => 'Invalid category ID' ) );
		}

		$result = wp_delete_term( $term_id, 'event_category' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( $result === false ) {
			wp_send_json_error( array( 'message' => 'Category not found' ) );
		}

		wp_send_json_success( array( 'message' => 'Category deleted successfully' ) );
	}

	/**
	 * Render tags management page
	 */
	public function render_tags_page() {
		$tags = get_terms( array(
			'taxonomy'   => 'event_tag',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );

		if ( is_wp_error( $tags ) ) {
			$tags = array();
		}

		// Pre-calculate tag counts from custom DB
		$db = hbl_events_db();
		$events_all = $db->get_events( array( 'limit' => -1 ) );
		$tag_counts = array();
		if ( $events_all ) {
			foreach ( $events_all as $evt ) {
				if ( ! empty( $evt->tags ) ) {
					$tag_ids = array_filter( array_map( 'absint', explode( ',', $evt->tags ) ) );
					foreach ( $tag_ids as $tid ) {
						$tag_counts[ $tid ] = ( $tag_counts[ $tid ] ?? 0 ) + 1;
					}
				}
			}
		}
		?>
		<div class="wrap hbl-events-admin hbl-events-categories">
			<h1>
				<span class="dashicons dashicons-tag"></span>
				<?php esc_html_e( 'Event Tags', 'hbl' ); ?>
			</h1>

			<div class="hbl-categories-layout">
				<!-- Add New Tag Form -->
				<div class="hbl-category-form-card">
					<h2><?php esc_html_e( 'Add New Tag', 'hbl' ); ?></h2>
					<form id="hbl-add-tag-form" class="hbl-category-form">
						<div class="hbl-form-field">
							<label for="tag_name"><?php esc_html_e( 'Name', 'hbl' ); ?> <span class="required">*</span></label>
							<input type="text" id="tag_name" name="tag_name" required>
						</div>

						<div class="hbl-form-field">
							<label for="tag_slug"><?php esc_html_e( 'Slug', 'hbl' ); ?></label>
							<input type="text" id="tag_slug" name="tag_slug" placeholder="<?php esc_attr_e( 'auto-generated from name', 'hbl' ); ?>">
						</div>

						<div class="hbl-form-field">
							<label for="tag_description"><?php esc_html_e( 'Description', 'hbl' ); ?></label>
							<textarea id="tag_description" name="tag_description" rows="3"></textarea>
						</div>

						<button type="submit" class="button button-primary">
							<span class="dashicons dashicons-plus-alt"></span>
							<?php esc_html_e( 'Add Tag', 'hbl' ); ?>
						</button>
					</form>
				</div>

				<!-- Tags List -->
				<div class="hbl-categories-list-card">
					<h2><?php esc_html_e( 'All Tags', 'hbl' ); ?> <span class="hbl-count">(<?php echo count( $tags ); ?>)</span></h2>

					<?php if ( ! empty( $tags ) ) : ?>
						<table class="hbl-categories-table wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th class="column-name"><?php esc_html_e( 'Name', 'hbl' ); ?></th>
									<th class="column-slug"><?php esc_html_e( 'Slug', 'hbl' ); ?></th>
									<th class="column-count"><?php esc_html_e( 'Events', 'hbl' ); ?></th>
									<th class="column-actions"><?php esc_html_e( 'Actions', 'hbl' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $tags as $tag ) :
									$real_count = $tag_counts[ $tag->term_id ] ?? 0;
								?>
								<tr data-tag-id="<?php echo esc_attr( $tag->term_id ); ?>">
									<td class="column-name">
										<strong><?php echo esc_html( $tag->name ); ?></strong>
										<?php if ( $tag->description ) : ?>
											<p class="hbl-category-description"><?php echo esc_html( wp_trim_words( $tag->description, 10 ) ); ?></p>
										<?php endif; ?>
									</td>
									<td class="column-slug">
										<code><?php echo esc_html( $tag->slug ); ?></code>
									</td>
									<td class="column-count">
										<?php if ( $real_count > 0 ) : ?>
											<span class="hbl-count-link"><?php echo esc_html( $real_count ); ?></span>
										<?php else : ?>
											<span class="hbl-count-zero">0</span>
										<?php endif; ?>
									</td>
									<td class="column-actions">
										<div class="hbl-action-buttons">
											<a href="<?php echo esc_url( get_term_link( $tag, 'event_tag' ) ); ?>" class="button button-small" target="_blank" title="<?php esc_attr_e( 'View', 'hbl' ); ?>">
												<span class="dashicons dashicons-visibility"></span>
											</a>
											<button type="button" class="button button-small hbl-edit-tag"
												data-id="<?php echo esc_attr( $tag->term_id ); ?>"
												data-name="<?php echo esc_attr( $tag->name ); ?>"
												data-slug="<?php echo esc_attr( $tag->slug ); ?>"
												data-description="<?php echo esc_attr( $tag->description ); ?>"
												title="<?php esc_attr_e( 'Edit', 'hbl' ); ?>">
												<span class="dashicons dashicons-edit"></span>
											</button>
											<button type="button" class="button button-small hbl-delete-tag"
												data-id="<?php echo esc_attr( $tag->term_id ); ?>"
												data-name="<?php echo esc_attr( $tag->name ); ?>"
												title="<?php esc_attr_e( 'Delete', 'hbl' ); ?>">
												<span class="dashicons dashicons-trash"></span>
											</button>
										</div>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<div class="hbl-no-categories">
							<span class="dashicons dashicons-tag"></span>
							<p><?php esc_html_e( 'No event tags found. Create your first tag using the form.', 'hbl' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Edit Tag Modal -->
			<div id="hbl-edit-tag-modal" class="hbl-modal" style="display: none;">
				<div class="hbl-modal-overlay"></div>
				<div class="hbl-modal-content">
					<div class="hbl-modal-header">
						<h3><?php esc_html_e( 'Edit Tag', 'hbl' ); ?></h3>
						<button type="button" class="hbl-modal-close">&times;</button>
					</div>
					<form id="hbl-edit-tag-form" class="hbl-category-form">
						<input type="hidden" id="edit_tag_id" name="tag_id">

						<div class="hbl-form-field">
							<label for="edit_tag_name"><?php esc_html_e( 'Name', 'hbl' ); ?> <span class="required">*</span></label>
							<input type="text" id="edit_tag_name" name="tag_name" required>
						</div>

						<div class="hbl-form-field">
							<label for="edit_tag_slug"><?php esc_html_e( 'Slug', 'hbl' ); ?></label>
							<input type="text" id="edit_tag_slug" name="tag_slug">
						</div>

						<div class="hbl-form-field">
							<label for="edit_tag_description"><?php esc_html_e( 'Description', 'hbl' ); ?></label>
							<textarea id="edit_tag_description" name="tag_description" rows="3"></textarea>
						</div>

						<div class="hbl-modal-actions">
							<button type="button" class="button hbl-modal-cancel"><?php esc_html_e( 'Cancel', 'hbl' ); ?></button>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Update Tag', 'hbl' ); ?></button>
						</div>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Add tag
	 */
	public function ajax_add_tag() {
		check_ajax_referer( 'hbl_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$name        = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
		$slug        = isset( $_POST['slug'] ) ? sanitize_title( $_POST['slug'] ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( $_POST['description'] ) : '';

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => 'Tag name is required' ) );
		}

		$term_args = array( 'description' => $description );
		if ( ! empty( $slug ) ) {
			$term_args['slug'] = $slug;
		}

		$result = wp_insert_term( $name, 'event_tag', $term_args );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => 'Tag created successfully',
			'term_id' => $result['term_id'],
		) );
	}

	/**
	 * AJAX: Edit tag
	 */
	public function ajax_edit_tag() {
		check_ajax_referer( 'hbl_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$term_id     = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
		$slug        = isset( $_POST['slug'] ) ? sanitize_title( $_POST['slug'] ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( $_POST['description'] ) : '';

		if ( ! $term_id ) {
			wp_send_json_error( array( 'message' => 'Invalid tag ID' ) );
		}
		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => 'Tag name is required' ) );
		}

		$result = wp_update_term( $term_id, 'event_tag', array(
			'name'        => $name,
			'slug'        => $slug,
			'description' => $description,
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'Tag updated successfully' ) );
	}

	/**
	 * AJAX: Delete tag
	 */
	public function ajax_delete_tag() {
		check_ajax_referer( 'hbl_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;

		if ( ! $term_id ) {
			wp_send_json_error( array( 'message' => 'Invalid tag ID' ) );
		}

		$result = wp_delete_term( $term_id, 'event_tag' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( $result === false ) {
			wp_send_json_error( array( 'message' => 'Tag not found' ) );
		}

		wp_send_json_success( array( 'message' => 'Tag deleted successfully' ) );
	}
}

// Initialize
HBL_Events_Admin::get_instance();