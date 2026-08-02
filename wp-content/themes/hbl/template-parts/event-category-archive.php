<?php
/**
 * Template part for displaying event category archives
 * 
 * Fallback template when no Elementor template is assigned.
 * Shows events from the HBL custom events database.
 *
 * @package HBL
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get the current category
$term = get_queried_object();
$category_name = $term ? $term->name : __( 'Event Category', 'hbl' );
$category_description = $term ? $term->description : '';
$category_id = $term ? $term->term_id : 0;

// Get events from the custom database
$events = array();
if ( function_exists( 'hbl_events_db' ) && $category_id ) {
	$db = hbl_events_db();
	$events = $db->get_events( array(
		'category_id' => $category_id,
		'status'      => 'publish',
		'upcoming'    => true,
		'orderby'     => 'start_date',
		'order'       => 'ASC',
		'limit'       => 20,
	) );
}
?>

<main id="content" class="site-main">
	<div class="container py-5">
		<!-- Category Header -->
		<header class="page-header mb-5 text-center">
			<h1 class="entry-title mb-3"><?php echo esc_html( $category_name ); ?></h1>
			<?php if ( $category_description ) : ?>
				<p class="archive-description lead text-muted"><?php echo esc_html( $category_description ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $events ) ) : ?>
				<span class="badge bg-primary"><?php echo count( $events ); ?> <?php echo _n( 'upcoming event', 'upcoming events', count( $events ), 'hbl' ); ?></span>
			<?php endif; ?>
		</header>

		<!-- Events Grid -->
		<div class="page-content">
			<?php if ( ! empty( $events ) ) : ?>
				<div class="row g-4">
					<?php foreach ( $events as $event ) : 
						$event_url = function_exists( 'hbl_events_db' ) ? hbl_events_db()->get_event_url( $event ) : '#';
						$thumbnail_url = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'medium_large' ) : '';
						$start_date = $event->start_date;
						$event_color = $event->event_color ?: '#008080';
					?>
						<div class="col-md-6 col-lg-4">
							<article class="card h-100 shadow-sm border-0">
								<?php if ( $thumbnail_url ) : ?>
									<a href="<?php echo esc_url( $event_url ); ?>">
										<img src="<?php echo esc_url( $thumbnail_url ); ?>" class="card-img-top" alt="<?php echo esc_attr( $event->title ); ?>" style="height: 200px; object-fit: cover;">
									</a>
								<?php else : ?>
									<a href="<?php echo esc_url( $event_url ); ?>" class="card-img-top d-flex align-items-center justify-content-center" style="height: 200px; background-color: <?php echo esc_attr( $event_color ); ?>;">
										<span class="dashicons dashicons-calendar-alt" style="font-size: 48px; width: 48px; height: 48px; color: rgba(255,255,255,0.7);"></span>
									</a>
								<?php endif; ?>
								
								<div class="card-body">
									<?php if ( $start_date ) : ?>
										<div class="mb-2">
											<span class="badge" style="background-color: <?php echo esc_attr( $event_color ); ?>;">
												<?php echo esc_html( date_i18n( 'M j, Y', strtotime( $start_date ) ) ); ?>
											</span>
										</div>
									<?php endif; ?>
									
									<h3 class="card-title h5">
										<a href="<?php echo esc_url( $event_url ); ?>" class="text-decoration-none text-dark">
											<?php echo esc_html( $event->title ); ?>
										</a>
									</h3>
									
									<?php if ( $event->venue ) : ?>
										<p class="card-text text-muted small mb-2">
											<i class="bi bi-geo-alt me-1"></i>
											<?php echo esc_html( $event->venue ); ?>
										</p>
									<?php endif; ?>
									
									<?php if ( $event->description ) : ?>
										<p class="card-text small"><?php echo esc_html( wp_trim_words( strip_tags( $event->description ), 20 ) ); ?></p>
									<?php endif; ?>
								</div>
								
								<div class="card-footer bg-transparent border-0">
									<a href="<?php echo esc_url( $event_url ); ?>" class="btn btn-outline-primary btn-sm">
										<?php esc_html_e( 'View Event', 'hbl' ); ?>
										<i class="bi bi-arrow-right ms-1"></i>
									</a>
								</div>
							</article>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="text-center py-5">
					<span class="dashicons dashicons-calendar" style="font-size: 64px; width: 64px; height: 64px; color: #ccc;"></span>
					<h3 class="mt-4 text-muted"><?php esc_html_e( 'No upcoming events', 'hbl' ); ?></h3>
					<p class="text-muted"><?php esc_html_e( 'There are no upcoming events in this category at the moment.', 'hbl' ); ?></p>
					<a href="<?php echo esc_url( home_url( '/whats-on/' ) ); ?>" class="btn btn-primary mt-3">
						<?php esc_html_e( 'View All Events', 'hbl' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>

		<!-- Admin Notice -->
		<?php if ( current_user_can( 'edit_theme_options' ) ) : ?>
			<div class="alert alert-info mt-5">
				<strong><?php esc_html_e( 'Admin Notice:', 'hbl' ); ?></strong>
				<?php esc_html_e( 'This is the default event category archive template. To use a custom Elementor template:', 'hbl' ); ?>
				<ol class="mb-0 mt-2">
					<li><?php esc_html_e( 'Go to Templates > Theme Builder in Elementor', 'hbl' ); ?></li>
					<li><?php esc_html_e( 'Create a new Archive template', 'hbl' ); ?></li>
					<li><?php esc_html_e( 'Add the "HBL Event Single Category" widget', 'hbl' ); ?></li>
					<li><?php esc_html_e( 'Set Display Conditions to: Archive > Event Category', 'hbl' ); ?></li>
				</ol>
			</div>
		<?php endif; ?>
	</div>
</main>
