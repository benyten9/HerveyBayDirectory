<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scope-local; populated by the parent renderer via extract().

defined( 'ABSPATH' ) || exit;

$icons_url = plugins_url( 'includes/Modules/Booking/Renderer/templates/icons/', DOUBLESCALE_PLUGIN_FILE );
?>

<div class="doublescale-booking-meeting">
	<div class="details-container">
		<!-- Calendar Header -->
		<div class="calendar-header">
			<?php if ( ! empty( $calendar['featured_image'] ) ) : ?>
				<div class="featured-image">
					<img src="<?php echo esc_url( $calendar['featured_image']['url'] ); ?>" alt="<?php echo esc_attr( $calendar['name'] ); ?>" />
				</div>
			<?php endif; ?>
			<div class="profile-picture">
				<?php if ( ! empty( $calendar['avatar'] ) ) : ?>
					<img src="<?php echo esc_url( $calendar['avatar']['url'] ); ?>" alt="<?php echo esc_attr( $calendar['name'] ); ?>" />
				<?php else : ?>
					<img src="<?php echo esc_url( $icons_url . 'profile.svg' ); ?>" alt="Calendar Avatar" />
				<?php endif; ?>
			</div>
			<h1 class="title"><?php echo esc_html( $calendar['name'] ?? 'Calendar Title will be here' ); ?></h1>
			<?php if ( ! empty( $calendar['description'] ) ) : ?>
				<p class="calendar-info"><?php echo esc_html( $calendar['description'] ); ?></p>
			<?php endif; ?>
		</div>

		<!-- Events List -->
		<div class="events-container">
			<?php
			// Convert Eloquent Collection to array
			$events = $calendar['events']->toArray();

			if ( ! empty( $events ) ) :
				?>
				<?php foreach ( $events as $event ) : ?>
					<div class="event-card">
						<div class="event-content">
							<h3 class="event-title"><?php echo esc_html( $event['name'] ?? 'Sprint Planning Meeting' ); ?></h3>

							<div class="event-details">
								<div class="event-detail">
									<span class="detail-icon">
										<img src="<?php echo esc_url( $icons_url . 'clock.svg' ); ?>" alt="Duration" />
									</span>
									<span><?php echo esc_html( $event['duration'] ?? '30' ); ?> min</span>
								</div>

								<?php if ( ! empty( $event['location'] ) && is_array( $event['location'] ) ) : ?>
									<div class="event-detail">
										<span class="detail-icon">
											<img src="<?php echo esc_url( $icons_url . 'location.svg' ); ?>" alt="Location" />
										</span>
										<span>
											<?php
											$location = $event['location'][0] ?? array();
											echo esc_html( $location['type'] ?? 'MS Teams - Conferencing' );
											?>
										</span>
									</div>
								<?php endif; ?>
							</div>
						</div>

						<div class="event-actions">
							<a href="?doublescale_booking_calendar=<?php echo esc_attr( $calendar['slug'] ); ?>&event=<?php echo esc_attr( $event['slug'] ); ?>"
								class="book-now-btn">
								Book Now
							</a>
						</div>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<div class="no-events">
					<p><?php esc_html_e( 'No events available at this time.', 'doublescale' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
