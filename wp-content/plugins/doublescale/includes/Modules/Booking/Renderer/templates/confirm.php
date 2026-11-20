<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scope-local; populated by the parent renderer via extract().

defined( 'ABSPATH' ) || exit;

$icons_url = plugins_url( 'includes/Modules/Booking/Renderer/templates/icons/', DOUBLESCALE_PLUGIN_FILE );

$event_name  = $booking_array['event']['name'] ?? '';
$start_time  = $booking_array['start_time'] ?? ''; // Format: 'Y-m-d H:i:s' (stored as UTC)
$slot_time   = $booking_array['slot_time'] ?? 30; // Duration in minutes
$location    = $booking_array['location_value'] ?? '';
$description = $booking_array['description'] ?? $booking_array['event']['description'] ?? '';
$timezone    = $booking_array['timezone'] ?? 'UTC';

// Get global time format setting
$time_format = (string) \DoubleScale\Modules\Booking\Helpers\BookingSettings::all()['general']['time_format'];

// Convert UTC time to user's timezone (same logic as your render_generic_page method)
try {
	$start_dt = new DateTime( $start_time, new DateTimeZone( 'UTC' ) );
	$start_dt->setTimezone( new DateTimeZone( $timezone ) );
	$end_dt = clone $start_dt;
	$end_dt->modify( "+{$slot_time} minutes" );
} catch ( Exception $e ) {
	// Fallback if timezone conversion fails
	$start_dt = new DateTime( $start_time );
	$end_dt   = new DateTime( $start_time );
	$end_dt->modify( '+30 minutes' );
}

// Format times in ISO 8601 for URLs
$start_iso = $start_dt->format( 'Ymd\THis' );
$end_iso   = $end_dt->format( 'Ymd\THis' );

// Google Calendar URL
$google_url = 'https://www.google.com/calendar/render?action=TEMPLATE'
	. '&text=' . urlencode( $event_name )
	. '&dates=' . $start_iso . '/' . $end_iso
	. '&details=' . urlencode( $description )
	. '&location=' . urlencode( $location )
	. '&sf=true&output=xml';

// Outlook Web Calendar URL
$outlook_url = 'https://outlook.live.com/calendar/0/deeplink/compose?'
	. 'subject=' . urlencode( $event_name )
	. '&startdt=' . $start_dt->format( 'Y-m-d\TH:i:s' )
	. '&enddt=' . $end_dt->format( 'Y-m-d\TH:i:s' )
	. '&body=' . urlencode( $description )
	. '&location=' . urlencode( $location );

// Apple iCloud Calendar URL
$apple_url = 'https://www.icloud.com/calendar/'
	. '?action=create'
	. '&title=' . urlencode( $event_name )
	. '&startDate=' . $start_iso
	. '&endDate=' . $end_iso
	. '&notes=' . urlencode( $description )
	. '&location=' . urlencode( $location );

// Permission variables are now passed from the renderer:
// $can_cancel, $cancel_denied_message, $can_reschedule, $reschedule_denied_message
?>

<div class="doublescale-booking-meeting">
	<?php require __DIR__ . '/parts/back-to-site.php'; ?>
	<div class="details-container">
		<div class="profile-picture">
			<?php if ( ! empty( $is_waiting ) ) : ?>
				<span style="font-size: 48px;">&#9201;</span>
			<?php else : ?>
				<img src="<?php echo esc_url( $icons_url . 'confirm.svg' ); ?>"
					alt="<?php esc_attr_e( 'confirmation', 'doublescale' ); ?>" />
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $is_waiting ) ) : ?>
			<h1 class="title"><?php esc_html_e( "You're on the Waiting List!", 'doublescale' ); ?></h1>
			<p class="calendar-info">
				<?php if ( ! empty( $waiting_list_position ) ) : ?>
					<?php
					printf(
						/* translators: %s: position number */
						esc_html__( 'You are at position #%s in the waiting list. We will notify you when a spot becomes available.', 'doublescale' ),
						esc_html( $waiting_list_position )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'We will notify you when a spot becomes available.', 'doublescale' ); ?>
				<?php endif; ?>
			</p>
		<?php else : ?>
			<h1 class="title"><?php esc_html_e( 'This meeting is scheduled', 'doublescale' ); ?></h1>
			<p class="calendar-info">
				<?php esc_html_e( 'We sent an email with a calendar invitation with the details to everyone.', 'doublescale' ); ?>
			</p>
		<?php endif; ?>

		<div class="booking-card booking-card--details-grid">
			<div class="booking-detail-row">
				<div class="booking-detail-label"><?php esc_html_e( 'What', 'doublescale' ); ?></div>
				<div class="booking-detail-value">
					<?php echo esc_html( $event_name ); ?>
				</div>
			</div>

			<div class="booking-detail-row">
				<div class="booking-detail-label"><?php esc_html_e( 'When', 'doublescale' ); ?></div>
				<div class="booking-detail-value">
					<div class="booking-detail-when-date">
						<?php echo esc_html( \DoubleScale\Modules\Booking\Data\BookingDataFormatter::long_date( $start_dt ) ); ?>
					</div>
					<div class="booking-detail-when-time">
						<?php
						$time_fmt = ( '24' === (string) $time_format ) ? 'H:i' : 'g:i a';
						echo esc_html(
							$start_dt->format( $time_fmt ) . ' – ' . $end_dt->format( $time_fmt )
						);
						?>
						<span class="booking-detail-when-tz">(<?php echo esc_html( $timezone ); ?>)</span>
					</div>
				</div>
			</div>

			<?php if ( ! empty( $booking_array['hosts'] ) && is_array( $booking_array['hosts'] ) ) : ?>
			<div class="booking-detail-row">
				<div class="booking-detail-label"><?php esc_html_e( 'Who', 'doublescale' ); ?></div>
				<div class="booking-detail-value booking-detail-who">
					<?php foreach ( $booking_array['hosts'] as $host_index => $host ) : ?>
						<?php if ( empty( $host['name'] ) ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<div class="booking-detail-person">
							<div class="booking-detail-person-name">
								<?php echo esc_html( $host['name'] ); ?>
								<?php if ( 0 === (int) $host_index ) : ?>
									<span class="booking-detail-host-badge"><?php esc_html_e( 'Host', 'doublescale' ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( ! empty( $host['email'] ) ) : ?>
								<div class="booking-detail-person-email"><?php echo esc_html( $host['email'] ); ?></div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
					<?php if ( ! empty( $booking_array['contact']['email'] ) || ! empty( $booking_array['contact']['display_name'] ) ) : ?>
						<div class="booking-detail-person">
							<div class="booking-detail-person-name">
								<?php
								$guest      = $booking_array['contact'] ?? array();
								$guest_name = trim( (string) ( $guest['display_name'] ?? '' ) );
								if ( '' === $guest_name ) {
									$guest_name = trim( ( $guest['first_name'] ?? '' ) . ' ' . ( $guest['last_name'] ?? '' ) );
								}
								if ( '' === $guest_name ) {
									$guest_name = (string) ( $guest['name'] ?? '' );
								}
								echo esc_html( $guest_name );
								?>
							</div>
							<?php if ( ! empty( $booking_array['contact']['email'] ) ) : ?>
								<div class="booking-detail-person-email"><?php echo esc_html( $booking_array['contact']['email'] ); ?></div>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $location ) || ! empty( $booking_array['location'] ) ) : ?>
			<div class="booking-detail-row">
				<div class="booking-detail-label"><?php esc_html_e( 'Where', 'doublescale' ); ?></div>
				<div class="booking-detail-value">
					<?php
					echo wp_kses_post(
						! empty( $booking_array['location'] )
							? $booking_array['location']
							: $location
					);
					?>
				</div>
			</div>
			<?php endif; ?>
		</div>

		<?php if ( empty( $is_waiting ) ) : ?>
		<div class="calendar-buttons">
			<p><?php esc_html_e( 'Add to calendar', 'doublescale' ); ?></p>
			<div class="icons">
				<!-- Outlook Web -->
				<a href="<?php echo esc_url( $outlook_url ); ?>" target="_blank" rel="noopener">
					<img src="<?php echo esc_url( $icons_url . 'outlook.svg' ); ?>" alt="Outlook" />
				</a>

				<!-- Google Calendar -->
				<a href="<?php echo esc_url( $google_url ); ?>" target="_blank" rel="noopener">
					<img src="<?php echo esc_url( $icons_url . 'google.svg' ); ?>" alt="Google Calendar" />
				</a>

				<!-- Apple iCloud Calendar -->
				<a href="<?php echo esc_url( $apple_url ); ?>" target="_blank" rel="noopener">
					<img src="<?php echo esc_url( $icons_url . 'apple.svg' ); ?>" alt="Apple Calendar" />
				</a>
			</div>
		</div>
		<?php endif; ?>
	</div>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public booking confirmation page; the `embed_type` query string only controls layout, no auth context.
	if ( ! isset( $_GET['embed_type'] ) || sanitize_text_field( wp_unslash( $_GET['embed_type'] ) ) !== 'Inline' ) :
		?>
		<?php if ( ! empty( $is_waiting ) ) : ?>
			<div></div>
		<?php elseif ( ! $can_cancel && ! $can_reschedule ) : ?>
			<?php
			$combined_denied_message = trim( (string) ( $cancel_denied_message ?? '' ) );
			if ( '' === $combined_denied_message ) {
				$combined_denied_message = trim( (string) ( $reschedule_denied_message ?? '' ) );
			}
			?>
			<?php if ( '' !== $combined_denied_message ) : ?>
				<div class="confirmation-footer">
					<div class="permission-denied-message">
						<?php echo wp_kses_post( $combined_denied_message ); ?>
					</div>
				</div>
			<?php endif; ?>
		<?php else : ?>
			<div class="confirmation-footer">
				<div class="change-options">
					<p><?php esc_html_e( 'Need to make a change?', 'doublescale' ); ?>
						<?php if ( $can_cancel && $can_reschedule ) : ?>
							<a href="?doublescale_booking=booking&id=<?php echo esc_attr( $booking_array['hash_id'] ); ?>&type=cancel"
								class="cancel-link"><?php esc_html_e( 'Cancel', 'doublescale' ); ?></a>
							<?php esc_html_e( 'or', 'doublescale' ); ?>
							<a href="?doublescale_booking=booking&id=<?php echo esc_attr( $booking_array['hash_id'] ); ?>&type=reschedule"
								class="reschedule-link"><?php esc_html_e( 'Reschedule', 'doublescale' ); ?></a>
						<?php elseif ( $can_cancel ) : ?>
							<a href="?doublescale_booking=booking&id=<?php echo esc_attr( $booking_array['hash_id'] ); ?>&type=cancel"
								class="cancel-link"><?php esc_html_e( 'Cancel', 'doublescale' ); ?></a>
						<?php elseif ( $can_reschedule ) : ?>
							<a href="?doublescale_booking=booking&id=<?php echo esc_attr( $booking_array['hash_id'] ); ?>&type=reschedule"
								class="reschedule-link"><?php esc_html_e( 'Reschedule', 'doublescale' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
