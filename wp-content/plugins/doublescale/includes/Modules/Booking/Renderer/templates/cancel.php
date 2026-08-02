<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scope-local; populated by the parent renderer via extract().

defined( 'ABSPATH' ) || exit;

$icons_url           = plugins_url( 'includes/Modules/Booking/Renderer/templates/icons/', DOUBLESCALE_PLUGIN_FILE );
$cancellation_reason = $fields['cancellation_reason'];
$status              = $booking_array['status'] ?? '';
?>

<div class="doublescale-booking-meeting">
	<div class="details-container">
		<div class="profile-picture">
			<img src="<?php echo esc_url( $icons_url . 'calendar.svg' ); ?>" alt="<?php esc_attr_e( 'cancellation', 'doublescale' ); ?>" />
		</div>

		<h1 class="title"><?php esc_html_e( 'Booking Cancellation', 'doublescale' ); ?></h1>
		<p class="calendar-info"><?php esc_html_e( 'Confirm and cancel the scheduled booking', 'doublescale' ); ?></p>

		<div class="booking-card">
			<h2 class="event-title"><?php echo esc_html( $booking_array['event']['name'] ?? '' ); ?></h2>
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
			<p><span><img src="<?php echo esc_url( $icons_url . 'calendar.svg' ); ?>" alt="Time" /></span><?php echo esc_html( $booking_array['formatted_time_range'] ?? '' ); ?>
			</p>
			<p><span><img src="<?php echo esc_url( $icons_url . 'icon.svg' ); ?>" alt="Timezone" /></span><?php echo esc_html( $booking_array['timezone'] ?? '' ); ?></p>
			<?php if ( ! empty( $booking_array['location'] ) ) : ?>
		<p><span><img src="<?php echo esc_url( $icons_url . 'location.svg' ); ?>" alt="Location" /></span> <?php echo wp_kses_post( $booking_array['location'] ); ?></p>
		<?php endif; ?>
		</div>

		<?php if ( strtolower( $status ) == 'cancelled' ) : ?>
			<div class="already-cancelled-message">
				<?php esc_html_e( 'This booking has already been cancelled.', 'doublescale' ); ?>
			</div>
		<?php elseif ( ! $can_cancel ) : ?>
			<div class="cancellation-denied-message">
				<?php echo wp_kses_post( $cancel_denied_message ); ?>
			</div>
		<?php else : ?>
			<div class="cancellation-container">
				<?php if ( $cancellation_reason['enabled'] ) : ?>
					<div class="cancellation-input-container">
						<label for="cancellation_reason">
							<?php echo esc_attr( $cancellation_reason['label'] ); ?>
							<?php if ( $cancellation_reason['required'] ) : ?>
								<span class="required">*</span>
							<?php endif; ?>
						</label>
						<div class="validation-message" id="validation_message" aria-live="polite"></div>
						<textarea class="cancellation-reason" name="cancellation_reason" id="cancellation_reason" rows="4" placeholder="<?php echo esc_attr( $cancellation_reason['placeholder'] ); ?>"
							<?php
							if ( $cancellation_reason['required'] ) :
								?>
							required<?php endif; ?>></textarea>
					</div>
					<?php if ( isset( $cancellation_reason['helpText'] ) && $cancellation_reason['helpText'] ) : ?>
						<p class="help-text"><?php echo esc_html( $cancellation_reason['helpText'] ); ?></p>
					<?php endif; ?>
				<?php endif; ?>

				<div class="calendar-buttons-container" id="buttons_container">
					<a href="?doublescale_booking=booking&id=<?php echo esc_attr( $booking_array['hash_id'] ); ?>&type=confirm" class="cancel-btn nevermind-btn"><?php esc_html_e( 'Nevermind', 'doublescale' ); ?></a>
					<button class="cancel-btn" id="cancel_booking_button">
						<span id="button_text"><?php esc_html_e( 'Cancel Booking', 'doublescale' ); ?></span>
						<span id="loading_spinner" style="display: none;">
							<svg width="16" height="16" viewBox="0 0 16 16" style="animation: spin 1s linear infinite; margin-right: 8px;">
								<circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="37.7" stroke-dashoffset="37.7" opacity="0.25"></circle>
								<circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="37.7" stroke-dashoffset="9.4"></circle>
							</svg>
							<?php esc_html_e( 'Cancelling...', 'doublescale' ); ?>
						</span>
					</button>
				</div>
			</div>
		<?php endif; ?>

		<div class="success-message" id="success_message" aria-live="polite" hidden></div>
	</div>
</div>
