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
$global_settings = get_option( 'doublescale_booking_settings', array() );
$time_format     = $global_settings['general']['time_format'] ?? '12';

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

// Keep ICS as fallback for offline calendar apps
$ics_content = 'BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//DoubleScale//EN
BEGIN:VEVENT
UID:' . uniqid() . '
DTSTAMP:' . gmdate( 'Ymd\THis\Z' ) . '
DTSTART:' . $start_dt->format( 'Ymd\THis' ) . '
DTEND:' . $end_dt->format( 'Ymd\THis' ) . '
SUMMARY:' . $event_name . '
DESCRIPTION:' . $description . '
LOCATION:' . $location . '
END:VEVENT
END:VCALENDAR';

$ics_filename = 'doublescale_booking_' . uniqid() . '.ics';
$upload_dir   = wp_upload_dir();
$ics_path     = trailingslashit( $upload_dir['path'] ) . $ics_filename;
$ics_url      = trailingslashit( $upload_dir['url'] ) . $ics_filename;

file_put_contents( $ics_path, $ics_content );

// Permission variables are now passed from the renderer:
// $can_cancel, $cancel_denied_message, $can_reschedule, $reschedule_denied_message
?>

<div class="doublescale-booking-meeting">
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
			<h1 class="title"><?php esc_html_e( 'Your meeting has been Scheduled', 'doublescale' ); ?></h1>
			<p class="calendar-info">
				<?php esc_html_e( 'A calendar invitation has been sent to your email address.', 'doublescale' ); ?>
			</p>
		<?php endif; ?>

		<div class="booking-card">
			<h2 class="event-title">
				<?php echo esc_html( $event_name ); ?>
			</h2>

			<?php if ( ! empty( $booking_array['hosts'] ) && is_array( $booking_array['hosts'] ) ) : ?>
				<?php
				$host_names = array();
				foreach ( $booking_array['hosts'] as $host ) :
					if ( ! empty( $host['name'] ) ) :
						$host_names[] = esc_html( $host['name'] );
					endif;
				endforeach;
				?>
				<?php if ( ! empty( $host_names ) ) : ?>
				<p>
					<span><img src="<?php echo esc_url( $icons_url . 'profile.svg' ); ?>" alt="Host" /></span>
					<span><?php echo implode( ' - ', $host_names ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $host_names entries are individually esc_html'd above. ?></span>
				</p>
				<?php endif; ?>
			<?php endif; ?>
			<p>
				<span><img src="<?php echo esc_url( $icons_url . 'calendar.svg' ); ?>" alt="Date" /></span>
				<?php echo esc_html( $booking_array['formatted_time_range'] ?? '' ); ?>
			</p>
			<p>
				<span><img src="<?php echo esc_url( $icons_url . 'icon.svg' ); ?>" alt="Timezone" /></span>
				<?php echo esc_html( $timezone ); ?>
			</p>
		<?php if ( ! empty( $booking_array['location'] ) ) : ?>
		<p>
			<span><img src="<?php echo esc_url( $icons_url . 'location.svg' ); ?>" alt="Location" /></span>
			<?php echo wp_kses_post( $booking_array['location'] ); ?>
		</p>
		<?php endif; ?>
		</div>

		<?php if ( empty( $is_waiting ) ) : ?>
		<div class="calendar-buttons">
			<p><?php esc_html_e( 'Add your Scheduled to calendars.', 'doublescale' ); ?></p>
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
