<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scope-local; populated by the parent renderer via extract().

defined( 'ABSPATH' ) || exit;

$icons_url = plugins_url( 'includes/Modules/Booking/Renderer/templates/icons/', DOUBLESCALE_PLUGIN_FILE );

$event_name = $booking_array['event']['name'] ?? '';
$start_time = $booking_array['start_time'] ?? '';
$slot_time  = $booking_array['slot_time'] ?? 30;
$location   = $booking_array['location_value'] ?? '';
$timezone   = $booking_array['timezone'] ?? 'UTC';

$global_settings = get_option( 'doublescale_booking_settings', array() );
$time_format     = $global_settings['general']['time_format'] ?? '12';

try {
	$start_dt = new DateTime( $start_time, new DateTimeZone( 'UTC' ) );
	$start_dt->setTimezone( new DateTimeZone( $timezone ) );
	$end_dt = clone $start_dt;
	$end_dt->modify( "+{$slot_time} minutes" );
} catch ( Exception $e ) {
	$start_dt = new DateTime( $start_time );
	$end_dt   = new DateTime( $start_time );
	$end_dt->modify( '+30 minutes' );
}
?>

<div class="doublescale-booking-meeting">
	<div class="details-container">
		<div class="profile-picture">
			<?php if ( $success ) : ?>
				<img src="<?php echo esc_url( $icons_url . 'confirm.svg' ); ?>"
					alt="<?php esc_attr_e( 'confirmed', 'doublescale' ); ?>" />
			<?php else : ?>
				<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#e74c3c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="12" cy="12" r="10"/>
					<line x1="12" y1="8" x2="12" y2="12"/>
					<line x1="12" y1="16" x2="12.01" y2="16"/>
				</svg>
			<?php endif; ?>
		</div>

		<h1 class="title"><?php echo esc_html( $title ); ?></h1>
		<p class="calendar-info"><?php echo esc_html( $message ); ?></p>

		<div class="booking-card">
			<h2 class="event-title"><?php echo esc_html( $event_name ); ?></h2>

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

		<?php if ( ! $success ) : ?>
		<div class="confirmation-footer">
			<p><?php esc_html_e( 'The spot was claimed before you. You are still on the waiting list and will be notified when another spot becomes available.', 'doublescale' ); ?></p>
		</div>
		<?php endif; ?>
	</div>
</div>
