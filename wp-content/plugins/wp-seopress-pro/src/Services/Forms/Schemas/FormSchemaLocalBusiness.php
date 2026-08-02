<?php

namespace SEOPressPro\Services\Forms\Schemas;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Core\FormApi;
use SEOPressPro\Helpers\Settings\LocalBusinessHelper;

class FormSchemaLocalBusiness extends FormApi {
	protected function getTypeByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_lb_name':
			case '_seopress_pro_rich_snippets_lb_street_addr':
			case '_seopress_pro_rich_snippets_lb_city':
			case '_seopress_pro_rich_snippets_lb_state':
			case '_seopress_pro_rich_snippets_lb_pc':
			case '_seopress_pro_rich_snippets_lb_country':
			case '_seopress_pro_rich_snippets_lb_lat':
			case '_seopress_pro_rich_snippets_lb_lon':
			case '_seopress_pro_rich_snippets_lb_website':
			case '_seopress_pro_rich_snippets_lb_tel':
			case '_seopress_pro_rich_snippets_lb_price':
			case '_seopress_pro_rich_snippets_lb_cuisine':
			case '_seopress_pro_rich_snippets_lb_menu':
			case '_seopress_pro_rich_snippets_lb_accepts_reservations':
				return 'input';
			case '_seopress_pro_rich_snippets_lb_type':
				return 'select';
			case '_seopress_pro_rich_snippets_lb_img':
				return 'upload';
			case '_seopress_pro_rich_snippets_lb_opening_hours':
				return 'opening_hours';
		}
	}

	protected function getLabelByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_lb_name':
				return __( 'Name of your business', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_type':
				return __( 'Select a business type', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_img':
				return __( 'Image', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_street_addr':
				return __( 'Street Address', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_city':
				return __( 'City', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_state':
				return __( 'State', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_pc':
				return __( 'Postal code', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_country':
				return __( 'Country', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_lat':
				return __( 'Latitude', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_lon':
				return __( 'Longitude', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_website':
				return __( 'URL', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_tel':
				return __( 'Telephone', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_price':
				return __( 'Price range', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_cuisine':
				return __( 'Cuisine served', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_menu':
				return __( 'URL of the menu', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_accepts_reservations':
				return __( 'Accepts reservations', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_opening_hours':
				return __( 'Opening hours', 'wp-seopress-pro' );
		}
	}

	protected function getPlaceholderByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_lb_name':
				return __( 'e.g. My Local Business', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_type':
				return __( 'Select a business type', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_img':
				return __( 'Select your image', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_street_addr':
				return __( 'e.g. Place Bellevue', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_city':
				return __( 'e.g. Biarritz', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_state':
				return __( 'e.g. Nouvelle Aquitaine', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_pc':
				return __( 'e.g. 64200', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_country':
				return __( 'e.g. FR for France', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_lat':
				return __( 'e.g. 43.4831389', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_lon':
				return __( 'e.g. -1.5630987', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_website':
				return sprintf( esc_html__( 'e.g. %s', 'wp-seopress-pro' ), get_home_url() );
			case '_seopress_pro_rich_snippets_lb_tel':
				return __( '+33501020304', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_price':
				return __( '$$, €€€, or ££££...', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_cuisine':
				return __( 'French, Italian, Indian, American', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_menu':
				return sprintf( esc_html__( 'e.g. %s', 'wp-seopress-pro' ), get_home_url() );
			case '_seopress_pro_rich_snippets_lb_accepts_reservations':
				return __( 'e.g. True', 'wp-seopress-pro' );
			default:
				return '';
		}
	}

	protected function getDescriptionByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_lb_img':
				return __( 'Every page must contain at least one image (whether or not you include markup). Google will pick the best image to display in Search results based on the aspect ratio and resolution.<br> Image URLs must be crawlable and indexable.<br> Images must represent the marked up content.<br> Images must be in .jpg, .png, or. gif format.<br> For best results, provide multiple high-resolution images (minimum of 50K pixels when multiplying width and height) with the following aspect ratios: 16x9, 4x3, and 1x1.', 'wp-seopress-pro' );

			case '_seopress_pro_rich_snippets_lb_accepts_reservations':
				return __( 'Indicates whether a FoodEstablishment accepts reservations. Values can be Boolean (True or False), an URL at which reservations can be made or (for backwards compatibility) the strings Yes or No.', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_lb_opening_hours':
				return __( "<strong>Morning and Afternoon are just time slots.</strong> e.g. if you're opened from 10:00 AM to 9:00 PM, check Morning and enter 10:00 / 21:00. If you are open non-stop, check Morning and enter 0:00 / 23:59.", 'wp-seopress-pro' );
			default:
				return '';
		}
	}

	protected function getOptions( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_lb_type':
				$types = LocalBusinessHelper::getListTypes();

				return $types;
		}
	}

	protected function getDetails( $postId = null ) {
		return array(
			array( 'key' => '_seopress_pro_rich_snippets_lb_name' ),
			array(
				'key'   => '_seopress_pro_rich_snippets_lb_type',
				'value' => 'LocalBusiness',
			),
			array( 'key' => '_seopress_pro_rich_snippets_lb_img' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_street_addr' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_city' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_state' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_pc' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_country' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_lat' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_lon' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_website' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_tel' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_price' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_cuisine' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_menu' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_accepts_reservations' ),
			array( 'key' => '_seopress_pro_rich_snippets_lb_opening_hours' ),
		);
	}
}
