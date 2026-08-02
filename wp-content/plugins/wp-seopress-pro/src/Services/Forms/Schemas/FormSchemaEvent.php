<?php

namespace SEOPressPro\Services\Forms\Schemas;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Core\FormApi;
use SEOPressPro\Helpers\Schemas\Currencies;

class FormSchemaEvent extends FormApi {
	protected function getTypeByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_events_type':
			case '_seopress_pro_rich_snippets_events_offers_cat':
			case '_seopress_pro_rich_snippets_events_offers_price_currency':
			case '_seopress_pro_rich_snippets_events_offers_availability':
			case '_seopress_pro_rich_snippets_events_status':
			case '_seopress_pro_rich_snippets_events_attendance_mode':
				return 'select';
			case '_seopress_pro_rich_snippets_events_desc':
				return 'textarea';
			case '_seopress_pro_rich_snippets_events_img':
				return 'upload';
			case '_seopress_pro_rich_snippets_events_start_date':
			case '_seopress_pro_rich_snippets_events_end_date':
			case '_seopress_pro_rich_snippets_events_previous_start_date':
			case '_seopress_pro_rich_snippets_events_offers_valid_from_date':
				return 'date';
			case '_seopress_pro_rich_snippets_events_start_time':
			case '_seopress_pro_rich_snippets_events_end_time':
			case '_seopress_pro_rich_snippets_events_offers_valid_from_time':
				return 'time';
			case '_seopress_pro_rich_snippets_events_name':
			case '_seopress_pro_rich_snippets_events_start_date_timezone':
			case '_seopress_pro_rich_snippets_events_previous_start_time':
			case '_seopress_pro_rich_snippets_events_location_name':
			case '_seopress_pro_rich_snippets_events_location_url':
			case '_seopress_pro_rich_snippets_events_location_address':
			case '_seopress_pro_rich_snippets_events_offers_name':
			case '_seopress_pro_rich_snippets_events_offers_price':
			case '_seopress_pro_rich_snippets_events_offers_url':
			case '_seopress_pro_rich_snippets_events_performer':
			case '_seopress_pro_rich_snippets_events_organizer_name':
			case '_seopress_pro_rich_snippets_events_organizer_url':
				return 'input';
		}
	}

	protected function getLabelByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_events_type':
				return __( 'Select your event type', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_name':
				return __( 'Event name', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_desc':
				return __( 'Event description (default excerpt, or beginning of the content)', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_img':
				return __( 'Image thumbnail', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_start_date':
				return __( 'Start date', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_start_date_timezone':
				return __( 'Timezone', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_start_time':
				return __( 'Start time', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_end_date':
				return __( 'End date', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_end_time':
				return __( 'End time', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_previous_start_date':
				return __( 'Previous start date', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_previous_start_time':
				return __( 'Previous start time', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_location_name':
				return __( 'Location name', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_location_url':
				return __( 'Event website', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_location_address':
				return __( 'Location Address', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_offers_name':
				return __( 'Offer name', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_offers_cat':
				return __( 'Select your offer category', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_offers_price':
				return __( 'Price', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_offers_price_currency':
				return __( 'Select your currency', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_offers_availability':
				return __( 'Availability', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_offers_valid_from_date':
				return __( 'Valid From', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_offers_valid_from_time':
				return __( 'Time', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_offers_url':
				return __( 'Website to buy tickets', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_performer':
				return __( 'Performer name', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_organizer_name':
				return __( 'Organizer name', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_organizer_url':
				return __( 'Organizer URL', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_status':
				return __( 'Select your event status', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_attendance_mode':
				return __( 'Select your event attendance mode', 'wp-seopress-pro' );
		}
	}

	protected function getPlaceholderByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_events_name':
				return __( 'The name of your event', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_desc':
				return __( 'Enter your event description', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_img':
				return __( 'Select your image', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_start_date':
				return __( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_start_date_timezone':
				return __( 'e.g. -4:00', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_start_time':
				return __( 'e.g. HH:MM', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_end_date':
				return __( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_end_time':
				return __( 'e.g. HH:MM', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_previous_start_date':
				return __( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_previous_start_time':
				return __( 'e.g. HH:MM', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_location_name':
				return __( 'e.g. My Local Business name', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_location_url':
				return __( 'e.g. https://www.example.com', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_location_address':
				return __( "e.g. 1 Avenue de l'Imperatrice, 64200 Biarritz", 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_offers_name':
				return __( 'e.g. General admission', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_offers_price':
				return __( 'e.g. 10', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_offers_url':
				return __( 'e.g. https://www.example.com', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_performer':
				return __( 'e.g. Lana Del Rey', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_organizer_name':
				return __( 'e.g. Apple', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_organizer_url':
				return __( 'e.g. https://www.example.com', 'wp-seopress-pro' );
		}
	}

	protected function getDescriptionByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_events_offers_valid_from_date':
				return __( 'The date when tickets go on sale', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_offers_valid_from_time':
				return __( 'The time when tickets go on sale', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_offers_price':
				return __( 'The lowest available price, including service charges and fees, of this type of ticket.', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_events_img':
				return __( 'Minimum width: 720px - Recommended size: 1920px - .jpg, .png, or. gif format - crawlable and indexable', 'wp-seopress-pro' );
		}
	}

	protected function getOptions( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_events_type':
				return array(
					array(
						'value' => 'BusinessEvent',
						'label' => __( 'Business Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'ChildrensEvent',
						'label' => __( 'Children\'s Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'ComedyEvent',
						'label' => __( 'Comedy Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'CourseInstance',
						'label' => __( 'Course Instance', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'DanceEvent',
						'label' => __( 'Dance Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'DeliveryEvent',
						'label' => __( 'Delivery Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'EducationEvent',
						'label' => __( 'Education Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'ExhibitionEvent',
						'label' => __( 'Exhibition Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'Festival',
						'label' => __( 'Festival', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'FoodEvent',
						'label' => __( 'Food Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'LiteraryEvent',
						'label' => __( 'Literary Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'MusicEvent',
						'label' => __( 'Music Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'PublicationEvent',
						'label' => __( 'Publication Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'SaleEvent',
						'label' => __( 'Sale Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'ScreeningEvent',
						'label' => __( 'Screening Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'SocialEvent',
						'label' => __( 'Social Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'SportsEvent',
						'label' => __( 'Sports Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'TheaterEvent',
						'label' => __( 'Theater Event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'VisualArtsEvent',
						'label' => __( 'Visual Arts Event', 'wp-seopress-pro' ),
					),
				);
			case '_seopress_pro_rich_snippets_events_offers_cat':
				return array(
					array(
						'value' => 'Primary',
						'label' => __( 'Primary', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'Secondary',
						'label' => __( 'Secondary', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'Presale',
						'label' => __( 'Presale', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'Premium',
						'label' => __( 'Premium', 'wp-seopress-pro' ),
					),
				);
			case '_seopress_pro_rich_snippets_events_offers_price_currency':
				return Currencies::getOptions();
			case '_seopress_pro_rich_snippets_events_offers_availability':
				return array(
					array(
						'value' => 'InStock',
						'label' => __( 'In Stock', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'SoldOut',
						'label' => __( 'Sold Out', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'PreOrder',
						'label' => __( 'Pre Order', 'wp-seopress-pro' ),
					),
				);
			case '_seopress_pro_rich_snippets_events_status':
				return array(
					array(
						'value' => 'none',
						'label' => __( 'Select a status event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'EventCancelled',
						'label' => __( 'Event cancelled', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'EventMovedOnline',
						'label' => __( 'Event moved online', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'EventPostponed',
						'label' => __( 'Event postponed', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'EventRescheduled',
						'label' => __( 'Event rescheduled', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'EventScheduled',
						'label' => __( 'Event scheduled', 'wp-seopress-pro' ),
					),
				);
			case '_seopress_pro_rich_snippets_events_attendance_mode':
				return array(
					array(
						'value' => 'none',
						'label' => __( 'Select your event attendance mode', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'OfflineEventAttendanceMode',
						'label' => __( 'Offline event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'OnlineEventAttendanceMode',
						'label' => __( 'Online event', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'MixedEventAttendanceMode',
						'label' => __( 'Mixed event', 'wp-seopress-pro' ),
					),
				);
				break;
		}
	}

	protected function getDetails( $postId = null ) {
		return array(
			array(
				'key'   => '_seopress_pro_rich_snippets_events_type',
				'value' => 'BusinessEvent',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_name',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_events_desc',
				'class' => 'seopress-textarea-high-size',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_img',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_start_date',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_start_date_timezone',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_start_time',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_end_date',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_end_time',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_previous_start_date',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_previous_start_time',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_location_name',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_location_url',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_location_address',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_offers_name',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_events_offers_cat',
				'value' => 'Primary',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_offers_price',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_events_offers_price_currency',
				'value' => 'none',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_events_offers_availability',
				'value' => 'InStock',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_offers_valid_from_date',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_offers_valid_from_time',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_offers_url',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_performer',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_organizer_name',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_events_organizer_url',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_events_status',
				'value' => 'none',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_events_attendance_mode',
				'value' => 'none',
			),
		);
	}
}
