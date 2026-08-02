<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Event Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

function seopress_get_schema_metaboxe_event( $seopress_pro_rich_snippets_data, $key_schema = 0 ) {
	$options_currencies = seopress_get_options_schema_currencies();

	$seopress_pro_rich_snippets_events_type                   = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_type'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_type'] : '';
	$seopress_pro_rich_snippets_events_name                   = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_name'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_name'] : '';
	$seopress_pro_rich_snippets_events_desc                   = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_desc'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_desc'] : '';
	$seopress_pro_rich_snippets_events_img                    = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_img'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_img'] : '';
	$seopress_pro_rich_snippets_events_start_date             = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_start_date'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_start_date'] : '';
	$seopress_pro_rich_snippets_events_start_date_timezone    = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_start_date_timezone'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_start_date_timezone'] : '';
	$seopress_pro_rich_snippets_events_start_time             = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_start_time'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_start_time'] : '';
	$seopress_pro_rich_snippets_events_end_date               = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_end_date'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_end_date'] : '';
	$seopress_pro_rich_snippets_events_end_time               = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_end_time'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_end_time'] : '';
	$seopress_pro_rich_snippets_events_previous_start_date    = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_previous_start_date'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_previous_start_date'] : '';
	$seopress_pro_rich_snippets_events_previous_start_time    = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_previous_start_time'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_previous_start_time'] : '';
	$seopress_pro_rich_snippets_events_location_name          = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_location_name'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_location_name'] : '';
	$seopress_pro_rich_snippets_events_location_url           = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_location_url'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_location_url'] : '';
	$seopress_pro_rich_snippets_events_location_address       = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_location_address'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_location_address'] : '';
	$seopress_pro_rich_snippets_events_offers_name            = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_name'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_name'] : '';
	$seopress_pro_rich_snippets_events_offers_cat             = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_cat'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_cat'] : '';
	$seopress_pro_rich_snippets_events_offers_price           = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_price'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_price'] : '';
	$seopress_pro_rich_snippets_events_offers_price_currency  = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_price_currency'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_price_currency'] : '';
	$seopress_pro_rich_snippets_events_offers_availability    = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_availability'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_availability'] : '';
	$seopress_pro_rich_snippets_events_offers_valid_from_date = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_valid_from_date'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_valid_from_date'] : '';
	$seopress_pro_rich_snippets_events_offers_valid_from_time = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_valid_from_time'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_valid_from_time'] : '';
	$seopress_pro_rich_snippets_events_offers_url             = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_url'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_offers_url'] : '';
	$seopress_pro_rich_snippets_events_performer              = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_performer'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_performer'] : '';
	$seopress_pro_rich_snippets_events_organizer_name         = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_organizer_name'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_organizer_name'] : '';
	$seopress_pro_rich_snippets_events_organizer_url          = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_organizer_url'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_organizer_url'] : '';
	$seopress_pro_rich_snippets_events_status                 = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_status'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_status'] : '';
	$seopress_pro_rich_snippets_events_attendance_mode        = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_attendance_mode'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_events_attendance_mode'] : ''; ?>
<div class="wrap-rich-snippets-item wrap-rich-snippets-events">
	<div class="seopress-notice">
		<p>
			<?php esc_html_e( 'Event markup describes the details of organized events. When you use it in your content, that event becomes relevant for enhanced search results for relevant queries.', 'wp-seopress-pro' ); ?>
		</p>
	</div>

	<div class="seopress-notice is-warning">
		<ul class="advice seopress-list">
			<li>
				<?php esc_html_e( 'Expired events. Events data for any feature will never be shown for expired events. However, you do not have to remove markup for expired events.', 'wp-seopress-pro' ); ?>
			</li>
			<li>
				<?php esc_html_e( 'Indicate the performer. Each event item must specify a performer property corresponding to the event\'s performer; that is, a musician, musical group, presenter, actor, and so on.', 'wp-seopress-pro' ); ?>
			</li>
			<li>
				<?php esc_html_e( 'Do not include promotional elements in the name.', 'wp-seopress-pro' ); ?>
			</li>
			<ul class="sublist">
				<li>
					<span class="dashicons dashicons-no"></span><?php esc_html_e( 'Promoting non-event products or services: "Trip package: San Diego/LA, 7 nights"', 'wp-seopress-pro' ); ?>
				</li>
				<li>
					<span class="dashicons dashicons-no"></span><?php esc_html_e( 'Prices in event titles: "Music festival - only $10!" Instead, highlight ticket prices using the tickets property in your markup.', 'wp-seopress-pro' ); ?>
				</li>
				<li>
					<span class="dashicons dashicons-no"></span><?php esc_html_e( 'Using a non-event for a title, such as: "Sale on dresses!"', 'wp-seopress-pro' ); ?>
				</li>
				<li>
					<span class="dashicons dashicons-no"></span><?php esc_html_e( 'Discounts or purchase opportunties, such as: "Concert - buy your tickets now," or "Concert - 50 percent off until Saturday!"', 'wp-seopress-pro' ); ?>
				</li>
			</ul>
			<li>
				<?php esc_html_e( 'Multi-day events. If your event/ticket info is for the festival itself, specify both the start and end date of the festival. If your event/ticket info is for a specific performance that is part of the festival, specify the specific date of the performance. If the specific date is unavailable, specify both the start and end date of the festival.', 'wp-seopress-pro' ); ?>
			</li>
		</ul>
	</div>

	<p>
		<label for="seopress_pro_rich_snippets_events_type_meta">
			<?php esc_html_e( 'Select your event type', 'wp-seopress-pro' ); ?>
		</label>
		<select id="seopress_pro_rich_snippets_events_type_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_type]">
			<option <?php selected( 'BusinessEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="BusinessEvent"><?php esc_html_e( 'Business Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'ChildrensEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="ChildrensEvent">
				<?php esc_html_e( 'Children\'s Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'ComedyEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="ComedyEvent">
				<?php esc_html_e( 'Comedy Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'CourseInstance', $seopress_pro_rich_snippets_events_type ); ?>
				value="CourseInstance">
				<?php esc_html_e( 'Course Instance', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'DanceEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="DanceEvent">
				<?php esc_html_e( 'Dance Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'DeliveryEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="DeliveryEvent">
				<?php esc_html_e( 'Delivery Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'EducationEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="EducationEvent">
				<?php esc_html_e( 'Education Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'ExhibitionEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="ExhibitionEvent">
				<?php esc_html_e( 'Exhibition Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'Festival', $seopress_pro_rich_snippets_events_type ); ?>
				value="Festival">
				<?php esc_html_e( 'Festival', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'FoodEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="FoodEvent">
				<?php esc_html_e( 'Food Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'LiteraryEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="LiteraryEvent">
				<?php esc_html_e( 'Literary Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'MusicEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="MusicEvent">
				<?php esc_html_e( 'Music Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'PublicationEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="PublicationEvent">
				<?php esc_html_e( 'Publication Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'SaleEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="SaleEvent">
				<?php esc_html_e( 'Sale Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'ScreeningEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="ScreeningEvent">
				<?php esc_html_e( 'Screening Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'SocialEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="SocialEvent">
				<?php esc_html_e( 'Social Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'SportsEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="SportsEvent">
				<?php esc_html_e( 'Sports Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'TheaterEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="TheaterEvent">
				<?php esc_html_e( 'Theater Event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'VisualArtsEvent', $seopress_pro_rich_snippets_events_type ); ?>
				value="VisualArtsEvent">
				<?php esc_html_e( 'Visual Arts Event', 'wp-seopress-pro' ); ?>
			</option>
		</select>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_name_meta">
			<?php esc_html_e( 'Event name', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_name_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_name]"
			placeholder="<?php esc_html_e( 'The name of your event', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Event name', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_name ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_desc">
			<?php esc_html_e( 'Event description (default excerpt, or beginning of the content)', 'wp-seopress-pro' ); ?>
		</label>
		<textarea id="seopress_pro_rich_snippets_events_desc"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_desc]"
			placeholder="<?php esc_html_e( 'Enter your event description', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Event description', 'wp-seopress-pro' ); ?>"><?php echo wp_kses_post( $seopress_pro_rich_snippets_events_desc ); ?></textarea>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_img_meta">
			<?php esc_html_e( 'Image thumbnail', 'wp-seopress-pro' ); ?>
		</label>
		<input id="seopress_pro_rich_snippets_events_img_meta" type="text"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_img]"
			placeholder="<?php esc_html_e( 'Select your image', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Image thumbnail', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_url( $seopress_pro_rich_snippets_events_img ); ?>" />
		<span class="description"><?php esc_html_e( 'Minimum width: 720px - Recommended size: 1920px -  .jpg, .png, or. gif format - crawlable and indexable', 'wp-seopress-pro' ); ?></span>
		<input id="seopress_pro_rich_snippets_events_img" class="<?php echo esc_attr( seopress_btn_secondary_classes() ); ?> seopress_media_upload"
			type="button"
			value="<?php esc_html_e( 'Upload an Image', 'wp-seopress-pro' ); ?>" />
	</p>
	<p>
		<label for="seopress-date-picker1">
			<?php esc_html_e( 'Start date', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress-date-picker1" class="seopress-date-picker" autocomplete="off"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_start_date]"
			placeholder="<?php esc_html_e( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Start date', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_start_date ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_start_date_timezone_meta">
			<?php esc_html_e( 'Timezone', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_start_date_timezone_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_start_date_timezone]"
			placeholder="<?php esc_html_e( 'e.g. -4:00', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Timezone', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_start_date_timezone ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_start_time_meta">
			<?php esc_html_e( 'Start time', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_start_time_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_start_time]"
			placeholder="<?php esc_html_e( 'e.g. HH:MM', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Start time', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_start_time ); ?>" />
	</p>
	<p>
		<label for="seopress-date-picker2">
			<?php esc_html_e( 'End date', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress-date-picker2" class="seopress-date-picker" autocomplete="off"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_end_date]"
			placeholder="<?php esc_html_e( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'End date', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_end_date ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_end_time_meta">
			<?php esc_html_e( 'End time', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_end_time_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_end_time]"
			placeholder="<?php esc_html_e( 'e.g. HH:MM', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'End time', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_end_time ); ?>" />
	</p>
	<p>
		<label for="seopress-date-picker7">
			<?php esc_html_e( 'Previous start date', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress-date-picker7" class="seopress-date-picker" autocomplete="off"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_previous_start_date]"
			placeholder="<?php esc_html_e( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Previous start date', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_previous_start_date ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_previous_start_time_meta">
			<?php esc_html_e( 'Previous start time', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_previous_start_time_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_previous_start_time]"
			placeholder="<?php esc_html_e( 'e.g. HH:MM', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Previous start time', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_previous_start_time ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_location_name_meta">
			<?php esc_html_e( 'Location name', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_location_name_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_location_name]"
			placeholder="<?php esc_html_e( 'e.g. My Local Business name', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Location name', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_location_name ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_location_url_meta">
			<?php esc_html_e( 'Event website', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_location_url_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_location_url]"
			placeholder="<?php esc_html_e( 'e.g. https://www.example.com', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Event website', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_location_url ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_location_address_meta">
			<?php esc_html_e( 'Location Address', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_location_address_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_location_address]"
			placeholder="<?php esc_html_e( 'e.g. 1 Avenue de l\'Imperatrice, 64200 Biarritz', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Location Address', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_location_address ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_offers_name_meta">
			<?php esc_html_e( 'Offer name', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_offers_name_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_offers_name]"
			aria-label="<?php esc_html_e( 'Offer name', 'wp-seopress-pro' ); ?>"
			placeholder="<?php esc_html_e( 'e.g. General admission', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_offers_name ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_offers_cat_meta"><?php esc_html_e( 'Select your offer category', 'wp-seopress-pro' ); ?>
		</label>
		<select id="seopress_pro_rich_snippets_events_offers_cat_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_offers_cat]">
			<option <?php selected( 'Primary', $seopress_pro_rich_snippets_events_offers_cat ); ?>
				value="Primary"><?php esc_html_e( 'Primary', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'Secondary', $seopress_pro_rich_snippets_events_offers_cat ); ?>
				value="Secondary"><?php esc_html_e( 'Secondary', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'Presale', $seopress_pro_rich_snippets_events_offers_cat ); ?>
				value="Presale"><?php esc_html_e( 'Presale', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'Premium', $seopress_pro_rich_snippets_events_offers_cat ); ?>
				value="Premium"><?php esc_html_e( 'Premium', 'wp-seopress-pro' ); ?>
			</option>
		</select>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_offers_price_meta">
			<?php esc_html_e( 'Price', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_offers_price_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_offers_price]"
			placeholder="<?php esc_html_e( 'e.g. 10', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Price', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_offers_price ); ?>" />
		<span class="description">
			<?php esc_html_e( 'The lowest available price, including service charges and fees, of this type of ticket.', 'wp-seopress-pro' ); ?>
		</span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_offers_price_currency_meta"><?php esc_html_e( 'Select your currency', 'wp-seopress-pro' ); ?>
		</label>
		<select id="seopress_pro_rich_snippets_events_offers_price_currency_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_offers_price_currency]">
			<?php foreach ( $options_currencies as $item ) { ?>
			<option <?php selected( $item['value'], $seopress_pro_rich_snippets_events_offers_price_currency ); ?>
				value="<?php echo esc_attr( $item['value'] ); ?>">
				<?php echo esc_attr( $item['label'] ); ?>
			</option>
			<?php } ?>
		</select>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_offers_availability_meta"><?php esc_html_e( 'Availability', 'wp-seopress-pro' ); ?>
		</label>
		<select id="seopress_pro_rich_snippets_events_offers_availability_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_offers_availability]">
			<option <?php selected( 'InStock', $seopress_pro_rich_snippets_events_offers_availability ); ?>
				value="InStock"><?php esc_html_e( 'In Stock', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'SoldOut', $seopress_pro_rich_snippets_events_offers_availability ); ?>
				value="SoldOut"><?php esc_html_e( 'Sold Out', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'PreOrder', $seopress_pro_rich_snippets_events_offers_availability ); ?>
				value="PreOrder"><?php esc_html_e( 'Pre Order', 'wp-seopress-pro' ); ?>
			</option>
		</select>
	</p>
	<p>
		<label for="seopress-date-picker3">
			<?php esc_html_e( 'Valid From', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress-date-picker3" class="seopress-date-picker" autocomplete="off"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_offers_valid_from_date]"
			aria-label="<?php esc_html_e( 'The date when tickets go on sale', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_offers_valid_from_date ); ?>" />

		<span class="description">
			<?php esc_html_e( 'The date when tickets go on sale', 'wp-seopress-pro' ); ?>
		</span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_offers_valid_from_meta_time">
			<?php esc_html_e( 'Time', 'wp-seopress-pro' ); ?>
		</label>
		<span class="description"><?php esc_html_e( 'The time when tickets go on sale', 'wp-seopress-pro' ); ?></span>
		<input type="time" id="seopress_pro_rich_snippets_events_offers_valid_from_meta_time"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_offers_valid_from_time]"
			aria-label="<?php esc_html_e( 'The time when tickets go on sale', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_offers_valid_from_time ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_offers_url_meta">
			<?php esc_html_e( 'Website to buy tickets', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_offers_url_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_offers_url]"
			placeholder="<?php esc_html_e( 'e.g. https://www.example.com', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Website to buy tickets', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_url( $seopress_pro_rich_snippets_events_offers_url ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_performer_meta">
			<?php esc_html_e( 'Performer name', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_performer_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_performer]"
			placeholder="<?php esc_html_e( 'e.g. Lana Del Rey', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Performer name', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_performer ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_organizer_name_meta">
			<?php esc_html_e( 'Organizer name', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_organizer_name_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_organizer_name]"
			placeholder="<?php esc_html_e( 'e.g. Apple', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Organizer name', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_events_organizer_name ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_organizer_url_meta">
			<?php esc_html_e( 'Organizer URL', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_events_organizer_url_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_organizer_url]"
			placeholder="<?php esc_html_e( 'e.g. https://www.example.com', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Organizer URL', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_url( $seopress_pro_rich_snippets_events_organizer_url ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_status_meta"><?php esc_html_e( 'Select your event status', 'wp-seopress-pro' ); ?>
		</label>
		<select id="seopress_pro_rich_snippets_events_status_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_status]">
			<option <?php selected( 'none', $seopress_pro_rich_snippets_events_status ); ?>
				value="none"><?php esc_html_e( 'Select a status event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'EventCancelled', $seopress_pro_rich_snippets_events_status ); ?>
				value="EventCancelled"><?php esc_html_e( 'Event cancelled', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'EventMovedOnline', $seopress_pro_rich_snippets_events_status ); ?>
				value="EventMovedOnline"><?php esc_html_e( 'Event moved online', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'EventPostponed', $seopress_pro_rich_snippets_events_status ); ?>
				value="EventPostponed"><?php esc_html_e( 'Event postponed', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'EventRescheduled', $seopress_pro_rich_snippets_events_status ); ?>
				value="EventRescheduled"><?php esc_html_e( 'Event rescheduled', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'EventScheduled', $seopress_pro_rich_snippets_events_status ); ?>
				value="EventScheduled"><?php esc_html_e( 'Event scheduled', 'wp-seopress-pro' ); ?>
			</option>
		</select>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_events_attendance_mode_meta"><?php esc_html_e( 'Select your event attendance mode', 'wp-seopress-pro' ); ?>
		</label>
		<select id="seopress_pro_rich_snippets_events_attendance_mode_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_events_attendance_mode]">
			<option <?php selected( 'none', $seopress_pro_rich_snippets_events_attendance_mode ); ?>
				value="none"><?php esc_html_e( 'Select your event attendance mode', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'OfflineEventAttendanceMode', $seopress_pro_rich_snippets_events_attendance_mode ); ?>
				value="OfflineEventAttendanceMode"><?php esc_html_e( 'Offline event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'OnlineEventAttendanceMode', $seopress_pro_rich_snippets_events_attendance_mode ); ?>
				value="OnlineEventAttendanceMode"><?php esc_html_e( 'Online event', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php selected( 'MixedEventAttendanceMode', $seopress_pro_rich_snippets_events_attendance_mode ); ?>
				value="MixedEventAttendanceMode"><?php esc_html_e( 'Mixed event', 'wp-seopress-pro' ); ?>
			</option>
		</select>
	</p>
</div>
	<?php
}
