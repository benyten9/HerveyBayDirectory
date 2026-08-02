<?php

namespace SEOPressPro\Services\Admin\Settings\LocalBusiness\Fields;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Helpers\OpeningHoursHelper;

trait FieldOpeningHours {
	/**
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function renderFieldOpeningHours() {
		$options = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessOpeningHours();

		$options = seopress_pro_get_service( 'TransformOldOpeningHours' )->transform( $options );

		$days  = OpeningHoursHelper::getDays();
		$hours = OpeningHoursHelper::getHours();
		$mins  = OpeningHoursHelper::getMinutes();

		$halfDay = array( 'am', 'pm' ); ?>

<div class="seopress-notice">
	<p>
		<?php echo '<strong>' . esc_html__( 'Morning and Afternoon are just time slots', 'wp-seopress-pro' ) . '</strong>'; ?>
	</p>
	<p>
		<?php esc_html_e( 'e.g. if you\'re opened from 10:00 AM to 9:00 PM, check Morning and enter 10:00 / 21:00.', 'wp-seopress-pro' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'If you are open non-stop, check Morning and enter 0:00 / 23:59.', 'wp-seopress-pro' ); ?>
	</p>
</div>

<ul class="wrap-opening-hours">
		<?php
		foreach ( $days as $key => $day ) {
			$closedAllDay = isset( $options[ $key ]['open'] ) ? $options[ $key ]['open'] : 0;
			?>
	<li>
		<span class="day">
			<?php echo esc_html( $day ); ?>
		</span>

		<label
			for="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][open]">

		<input
			id="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][open]"
			name="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][open]"
			type="checkbox" <?php checked( $closedAllDay, '1' ); ?>
			value="1"/>

			<?php esc_html_e( 'Closed all the day?', 'wp-seopress-pro' ); ?>
		</label>
			<?php
			foreach ( $halfDay as $valueHalfDay ) {
					$open = isset( $options[ $key ][ $valueHalfDay ]['open'] ) ? $options[ $key ][ $valueHalfDay ]['open'] : 0;

					$startHours = isset( $options[ $key ][ $valueHalfDay ]['start']['hours'] ) ? $options[ $key ][ $valueHalfDay ]['start']['hours'] : '00';
					$endHours   = isset( $options[ $key ][ $valueHalfDay ]['end']['hours'] ) ? $options[ $key ][ $valueHalfDay ]['end']['hours'] : '00';
					$startMins  = isset( $options[ $key ][ $valueHalfDay ]['start']['mins'] ) ? $options[ $key ][ $valueHalfDay ]['start']['mins'] : '00';
					$endMins    = isset( $options[ $key ][ $valueHalfDay ]['end']['mins'] ) ? $options[ $key ][ $valueHalfDay ]['end']['mins'] : '00';
				?>
		<div class="hours">
			<div class="range">
				<label
					for="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $valueHalfDay ); ?>][open]">
					<input
						id="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $valueHalfDay ); ?>][open]"
						name="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $valueHalfDay ); ?>][open]"
						type="checkbox" <?php checked( $open, '1' ); ?>
					value="1"
					/>
					<?php if ( 'am' === $valueHalfDay ) { ?>

						<?php esc_html_e( 'Open in the morning?', 'wp-seopress-pro' ); ?>
					<?php } else { ?>
						<?php esc_html_e( 'Open in the afternoon?', 'wp-seopress-pro' ); ?>
					<?php } ?>
				</label>
			</div>

			<div class="range">
				<select
					id="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $valueHalfDay ); ?>][start][hours]"
					name="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $valueHalfDay ); ?>][start][hours]">
					<?php foreach ( $hours as $hour ) { ?>
					<option <?php selected( $hour, $startHours ); ?>
						value="<?php echo esc_attr( $hour ); ?>">
						<?php echo esc_html( $hour ); ?>
					</option>
					<?php } ?>

				</select>

				<span> : </span>

				<select
					id="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $valueHalfDay ); ?>][start][mins]"
					name="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $valueHalfDay ); ?>][start][mins]">

					<?php foreach ( $mins as $min ) { ?>
					<option <?php selected( $min, $startMins ); ?>
						value="<?php echo esc_attr( $min ); ?>">
						<?php echo esc_html( $min ); ?>
					</option>
					<?php } ?>

				</select>

				<span> <?php esc_html_e( 'to', 'wp-seopress-pro' ); ?> </span>

				<select
					id="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $valueHalfDay ); ?>][end][hours]"
					name="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $valueHalfDay ); ?>][end][hours]">

					<?php foreach ( $hours as $hour ) { ?>
					<option <?php selected( $hour, $endHours ); ?>
						value="<?php echo esc_attr( $hour ); ?>">
						<?php echo esc_html( $hour ); ?>
					</option>
					<?php } ?>
				</select>

				<span> : </span>

				<select
					id="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $valueHalfDay ); ?>][end][mins]"
					name="seopress_pro_option_name[seopress_local_business_opening_hours][<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $valueHalfDay ); ?>][end][mins]">

					<?php foreach ( $mins as $min ) { ?>
					<option <?php selected( $min, $endMins ); ?>
						value="<?php echo esc_attr( $min ); ?>">
						<?php echo esc_html( $min ); ?>
					</option>
					<?php } ?>
				</select>
			</div>
		</div>
					<?php
			}
			?>

	</li>
			<?php
		}
		?>
</ul>


		<?php
	}
}
