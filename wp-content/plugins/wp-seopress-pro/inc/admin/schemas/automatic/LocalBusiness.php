<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Local Business Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' ); ?>

<div class="wrap-rich-snippets-local-business">
	<div class="seopress-notice">
		<p>
			<?php
			/* translators: %s: link documentation */
				echo wp_kses_post( sprintf( __( 'Learn more about the <strong>Local Business schema</strong> from the <a href="%s" target="_blank">Google official documentation website</a>', 'wp-seopress-pro' ), 'https://developers.google.com/search/docs/data-types/local-business' ) );
			?>
			<span class="dashicons dashicons-external"></span>
		</p>
	</div>
	<p>
		<label for="seopress_pro_rich_snippets_lb_name_meta">
			<?php esc_html_e( 'Name of your business', 'wp-seopress-pro' ); ?>
			<code>name</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_name', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. My Local Business', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_type_meta">
			<?php esc_html_e( 'Select a business type', 'wp-seopress-pro' ); ?>
			<code>type</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_type', 'lb' ); ?>
	</p>
	<p class="description">
		<a href="https://schema.org/LocalBusiness" target="_blank"
			title="<?php esc_html_e( 'All business types (new window)', 'wp-seopress-pro' ); ?>">
			<?php esc_html_e( 'Full list of business types available on schema.org', 'wp-seopress-pro' ); ?>
		</a>
		<span class="dashicons dashicons-external"></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_img_meta">
			<?php esc_html_e( 'Image', 'wp-seopress-pro' ); ?>
			<code>image</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_img', 'image' ); ?>
		<span class="description">
		<?php
		echo wp_kses_post(
			__(
				'Every page must contain at least one image (whether or not you include markup). Google will pick the best image to display in Search results based on the aspect ratio and resolution.<br>
Image URLs must be crawlable and indexable.<br>
Images must represent the marked up content.<br>
Images must be in .jpg, .png, or. gif format.<br>
For best results, provide multiple high-resolution images (minimum of 50K pixels when multiplying width and height) with the following aspect ratios: 16x9, 4x3, and 1x1.',
				'wp-seopress-pro'
			)
		);
		?>
		</span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_street_addr_meta">
			<?php esc_html_e( 'Street Address', 'wp-seopress-pro' ); ?>
			<code>address</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_street_addr', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. Place Bellevue', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_city_meta">
			<?php esc_html_e( 'City', 'wp-seopress-pro' ); ?>
			<code>city</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_city', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. Biarritz', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_state_meta">
			<?php esc_html_e( 'State', 'wp-seopress-pro' ); ?>
			<code>state</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_state', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. Nouvelle Aquitaine', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_pc_meta">
			<?php esc_html_e( 'Postal code', 'wp-seopress-pro' ); ?>
			<code>postalCode</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_pc', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. 64200', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_country_meta">
			<?php esc_html_e( 'Country', 'wp-seopress-pro' ); ?>
			<code>country</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_country', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. FR for France', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_lat_meta">
			<?php esc_html_e( 'Latitude', 'wp-seopress-pro' ); ?>
			<code>geo</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_lat', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. 43.4831389', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_lon_meta">
			<?php esc_html_e( 'Longitude', 'wp-seopress-pro' ); ?>
			<code>geo</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_lon', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. -1.5630987', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_website_meta">
			<?php esc_html_e( 'URL', 'wp-seopress-pro' ); ?>
			<code>url</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_website', 'default' ); ?>
		<span class="description">
		<?php
		esc_html_e( 'e.g. ', 'wp-seopress-pro' );
		echo esc_url( get_home_url() );
		?>
		</span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_tel_meta">
			<?php esc_html_e( 'Telephone', 'wp-seopress-pro' ); ?>
			<code>telephone</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_tel', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. +33501020304', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_price_meta">
			<?php esc_html_e( 'Price range', 'wp-seopress-pro' ); ?>
			<code>priceRange</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_price', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'e.g. $$, €€€, or ££££...', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_serves_cuisine_meta">
			<?php esc_html_e( 'Cuisine served', 'wp-seopress-pro' ); ?>
			<code>servesCuisine</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_serves_cuisine', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Only to be filled if the business type is: "FoodEstablishment", "Bakery", "BarOrPub", "Brewery", "CafeOrCoffeeShop", "FastFoodRestaurant", "IceCreamShop", "Restaurant" or "Winery".', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_menu_meta">
			<?php esc_html_e( 'URL of the menu', 'wp-seopress-pro' ); ?>
			<code>menu</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_menu', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Only to be filled if the business type is: "FoodEstablishment", "Bakery", "BarOrPub", "Brewery", "CafeOrCoffeeShop", "FastFoodRestaurant", "IceCreamShop", "Restaurant" or "Winery".', 'wp-seopress-pro' ); ?></span>
		<span class="description"><?php esc_html_e( 'Default value if empty: URL from the Website property', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_accepts_reservations_meta">
			<?php esc_html_e( 'Accepts reservations', 'wp-seopress-pro' ); ?>
			<code>acceptsReservations</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_lb_accepts_reservations', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Indicates whether a FoodEstablishment accepts reservations. Values can be Boolean (True or False), an URL at which reservations can be made or (for backwards compatibility) the strings Yes or No.', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_lb_opening_hours_meta">
			<?php esc_html_e( 'Opening hours', 'wp-seopress-pro' ); ?>
			<code>openingHours</code>
		</label>
		<span class="description"><?php echo wp_kses_post( __( '<strong>Morning and Afternoon are just time slots</strong>. e.g. if you\'re opened from 10:00 AM to 9:00 PM, check Morning and enter 10:00 / 21:00. If you are open non-stop, check Morning and enter 0:00 / 23:59.', 'wp-seopress-pro' ) ); ?></span>
	</p>

	<?php
	$options = $seopress_pro_rich_snippets_lb_opening_hours;

	$days = array(
		__( 'Monday', 'wp-seopress-pro' ),
		__( 'Tuesday', 'wp-seopress-pro' ),
		__( 'Wednesday', 'wp-seopress-pro' ),
		__( 'Thursday', 'wp-seopress-pro' ),
		__( 'Friday', 'wp-seopress-pro' ),
		__( 'Saturday', 'wp-seopress-pro' ),
		__( 'Sunday', 'wp-seopress-pro' ),
	);

	$hours = array( '00', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23' );

	$mins = array( '00', '15', '30', '45', '59' );
	?>

	<ul class="wrap-opening-hours">

		<?php
		foreach ( $days as $key => $day ) {
			$check_day = isset( $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['open'] );

			$check_day_am = isset( $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['open'] );

			$check_day_pm = isset( $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['open'] );

			$selected_start_hours = isset( $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['start']['hours'] ) ? $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['start']['hours'] : null;

			$selected_start_mins = isset( $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['start']['mins'] ) ? $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['start']['mins'] : null;
			?>
		<li>
			<span class="day"><strong><?php echo $day; ?></strong></span>

			<ul>
				<!--Closed?-->
				<li>
					<input
						id="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][open]"
						name="seopress_pro_rich_snippets_lb_opening_hours[seopress_pro_rich_snippets_lb_opening_hours][<?php echo esc_attr( $key ); ?>][open]"
						type="checkbox" <?php if ( '1' == $check_day ) { ?>
					checked="yes"
						<?php } ?>
					value="1"/>

					<label
						for="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][open]">
							<?php esc_html_e( 'Closed all the day?', 'wp-seopress-pro' ); ?>
					</label>

						<?php
						if ( isset( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['open'] ) ) {
							esc_attr( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['open'] );
						}
						?>
				</li>

				<!--AM-->
				<li>
					<input
						id="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][am][open]"
						name="seopress_pro_rich_snippets_lb_opening_hours[seopress_pro_rich_snippets_lb_opening_hours][<?php echo esc_attr( $key ); ?>][am][open]"
						type="checkbox" <?php if ( '1' == $check_day_am ) { ?>
					checked="yes"
					<?php } ?>
					value="1"/>

					<label
						for="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][am][open]"><?php esc_html_e( 'Open in the morning?', 'wp-seopress-pro' ); ?></label>

						<?php
						if ( isset( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['open'] ) ) {
							esc_attr( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['open'] );
						}
						?>

					<select
						id="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][am][start][hours]"
						name="seopress_pro_rich_snippets_lb_opening_hours[seopress_pro_rich_snippets_lb_opening_hours][<?php echo esc_attr( $key ); ?>][am][start][hours]">

						<?php foreach ( $hours as $hour ) { ?>
						<option <?php if ( $hour == $selected_start_hours ) { ?>
							selected="selected"
							<?php } ?>
							value="<?php echo esc_attr( $hour ); ?>"><?php echo esc_attr( $hour ); ?>
						</option>
						<?php } ?>

					</select>

						<?php esc_html_e( ' : ', 'wp-seopress-pro' ); ?>

					<select
						id="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][am][start][mins]"
						name="seopress_pro_rich_snippets_lb_opening_hours[seopress_pro_rich_snippets_lb_opening_hours][<?php echo esc_attr( $key ); ?>][am][start][mins]">

						<?php foreach ( $mins as $min ) { ?>
						<option <?php if ( $min == $selected_start_mins ) { ?>
							selected="selected"
							<?php } ?>
							value="<?php echo esc_attr( $min ); ?>"><?php echo esc_attr( $min ); ?>
						</option>
						<?php } ?>

					</select>

						<?php
						if ( isset( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['start']['hours'] ) ) {
							esc_attr( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['start']['hours'] );
						}

						if ( isset( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['start']['mins'] ) ) {
									esc_attr( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['start']['mins'] );
						}

						esc_html_e( ' - ', 'wp-seopress-pro' );

						$selected_end_hours = isset( $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['end']['hours'] ) ? $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['end']['hours'] : null;

						$selected_end_mins = isset( $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['end']['mins'] ) ? $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['am']['end']['mins'] : null;
						?>

					<select
						id="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][am][end][hours]"
						name="seopress_pro_rich_snippets_lb_opening_hours[seopress_pro_rich_snippets_lb_opening_hours][<?php echo esc_attr( $key ); ?>][am][end][hours]">

							<?php foreach ( $hours as $hour ) { ?>
						<option <?php if ( $hour == $selected_end_hours ) { ?>
							selected="selected"
							<?php } ?>
							value="<?php echo esc_attr( $hour ); ?>"><?php echo esc_attr( $hour ); ?>
						</option>
						<?php } ?>

					</select>

						<?php esc_html_e( ' : ', 'wp-seopress-pro' ); ?>

					<select
						id="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][am][end][mins]"
						name="seopress_pro_rich_snippets_lb_opening_hours[seopress_pro_rich_snippets_lb_opening_hours][<?php echo esc_attr( $key ); ?>][am][end][mins]">

							<?php foreach ( $mins as $min ) { ?>
						<option <?php if ( $min == $selected_end_mins ) { ?>
							selected="selected"
							<?php } ?>
							value="<?php echo esc_attr( $min ); ?>"><?php echo esc_attr( $min ); ?>
						</option>
						<?php } ?>

					</select>
				</li>

				<!--PM-->
				<li>
						<?php
						$selected_start_hours2 = isset( $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['start']['hours'] ) ? $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['start']['hours'] : null;

						$selected_start_mins2 = isset( $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['start']['mins'] ) ? $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['start']['mins'] : null;
						?>

					<input
						id="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][pm][open]"
						name="seopress_pro_rich_snippets_lb_opening_hours[seopress_pro_rich_snippets_lb_opening_hours][<?php echo esc_attr( $key ); ?>][pm][open]"
						type="checkbox" <?php if ( '1' == $check_day_pm ) { ?>
					checked="yes"
						<?php } ?>
					value="1"/>

					<label
						for="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][pm][open]"><?php esc_html_e( 'Open in the afternoon?', 'wp-seopress-pro' ); ?></label>

						<?php
						if ( isset( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['open'] ) ) {
							esc_attr( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['open'] );
						}
						?>

					<select
						id="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][pm][start][hours]"
						name="seopress_pro_rich_snippets_lb_opening_hours[seopress_pro_rich_snippets_lb_opening_hours][<?php echo esc_attr( $key ); ?>][pm][start][hours]">
						<?php foreach ( $hours as $hour ) { ?>
						<option <?php if ( $hour == $selected_start_hours2 ) { ?>
							selected="selected"
							<?php } ?>
							value="<?php echo esc_attr( $hour ); ?>"><?php echo esc_attr( $hour ); ?>
						</option>
						<?php } ?>

					</select>

						<?php esc_html_e( ' : ', 'wp-seopress-pro' ); ?>

					<select
						id="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][pm][start][mins]"
						name="seopress_pro_rich_snippets_lb_opening_hours[seopress_pro_rich_snippets_lb_opening_hours][<?php echo esc_attr( $key ); ?>][pm][start][mins]">

						<?php foreach ( $mins as $min ) { ?>
						<option <?php if ( $min == $selected_start_mins2 ) { ?>
							selected="selected"
							<?php } ?>
							value="<?php echo esc_attr( $min ); ?>"><?php echo esc_attr( $min ); ?>
						</option>
						<?php } ?>

					</select>

						<?php
						if ( isset( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['start']['hours'] ) ) {
							esc_attr( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['start']['hours'] );
						}

						if ( isset( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['start']['mins'] ) ) {
									esc_attr( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['start']['mins'] );
						}

						esc_html_e( ' - ', 'wp-seopress-pro' );

						$selected_end_hours2 = isset( $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['end']['hours'] ) ? $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['end']['hours'] : null;

						$selected_end_mins2 = isset( $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['end']['mins'] ) ? $options[0]['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['end']['mins'] : null;
						?>

					<select
						id="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][pm][end][hours]"
						name="seopress_pro_rich_snippets_lb_opening_hours[seopress_pro_rich_snippets_lb_opening_hours][<?php echo esc_attr( $key ); ?>][pm][end][hours]">
							<?php foreach ( $hours as $hour ) { ?>
						<option <?php if ( $hour == $selected_end_hours2 ) { ?>
							selected="selected"
							<?php } ?>
							value="<?php echo esc_attr( $hour ); ?>"><?php echo esc_attr( $hour ); ?>
						</option>
						<?php } ?>
					</select>

						<?php esc_html_e( ' : ', 'wp-seopress-pro' ); ?>

					<select
						id="seopress_pro_rich_snippets_lb_opening_hours[<?php echo esc_attr( $key ); ?>][pm][end][mins]"
						name="seopress_pro_rich_snippets_lb_opening_hours[seopress_pro_rich_snippets_lb_opening_hours][<?php echo esc_attr( $key ); ?>][pm][end][mins]">

							<?php foreach ( $mins as $min ) { ?>
						<option <?php if ( $min == $selected_end_mins2 ) { ?>
							selected="selected"
							<?php } ?>
							value="<?php echo esc_attr( $min ); ?>"><?php echo esc_attr( $min ); ?>
						</option>
						<?php } ?>

					</select>

				</li>
			</ul>

				<?php
				if ( isset( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['end']['hours'] ) ) {
					esc_attr( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['end']['hours'] );
				}

				if ( isset( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['end']['mins'] ) ) {
					esc_attr( $options['seopress_pro_rich_snippets_lb_opening_hours'][ $key ]['pm']['end']['mins'] );
				}

				$seopress_pro_rich_snippets_lb_opening_hours = $options;
		}
		?>

	</ul>
</div>
