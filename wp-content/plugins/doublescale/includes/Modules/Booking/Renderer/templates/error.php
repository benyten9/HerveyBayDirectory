<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scope-local; populated by the parent renderer via extract().

defined( 'ABSPATH' ) || exit;

$icons_url = plugins_url( 'includes/Modules/Booking/Renderer/templates/icons/', DOUBLESCALE_PLUGIN_FILE );
?>

<div class="doublescale-booking-meeting">
	<div class="details-container">
		<div class="profile-picture">
			<img src="<?php echo esc_url( $icons_url . 'calendar.svg' ); ?>" alt="<?php esc_attr_e( 'error', 'doublescale' ); ?>" />
		</div>

		<h1 class="title"><?php echo esc_html( $heading ); ?></h1>
		<p class="calendar-info"><?php echo esc_html( $message ); ?></p>

		<?php if ( ! empty( $detail ) ) : ?>
			<div class="booking-card">
				<p><?php echo esc_html( $detail ); ?></p>
			</div>
		<?php endif; ?>

		<div class="calendar-buttons-container">
			<a href="<?php echo esc_url( home_url() ); ?>" class="cancel-btn nevermind-btn">
				<?php esc_html_e( 'Go to homepage', 'doublescale' ); ?>
			</a>
		</div>
	</div>
</div>
