<?php
/**
 * SEOPress PRO functions.
 *
 * @package SEOPress
 * @subpackage Functions
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

use SEOPressPro\Core\Kernel;

/**
 * Get a service.
 *
 * @param string $service The service name.
 *
 * @return object
 */
function seopress_pro_get_service( $service ) {
	return Kernel::getContainer()->getServiceByName( $service );
}

/**
 * Resolve the robots.txt URL used by the SEO alerts, accounting for multisite
 * and language plugins.
 *
 * On a subdirectory multisite, robots.txt is served globally by WordPress at
 * the network root only — a subsite lives at a virtual path (e.g. /site2) that
 * has no physical directory, so /site2/robots.txt returns a 404 and produces a
 * false alert. In that case the URL must target the network root (the main
 * site home) instead of the subsite home. Subdomain multisite (each subsite has
 * its own host) and single-site installs already serve robots.txt from their
 * own home URL.
 *
 * The raw `home` option is read directly rather than through home_url() /
 * get_home_url() on purpose: those go through the `home_url` filter that
 * language plugins such as WPML hook to prepend a language segment (e.g. /fr/),
 * which would point the check at /fr/robots.txt — another false 404.
 *
 * @return string The absolute robots.txt URL to check.
 */
function seopress_pro_get_robots_txt_alert_url() {
	if ( is_multisite() && ! is_subdomain_install() ) {
		$home = get_blog_option( get_main_site_id(), 'home' );
	} else {
		$home = get_option( 'home' );
	}

	return untrailingslashit( $home ) . '/robots.txt';
}

/**
 * Enable Google Suggestions
 *
 * @param boolean true Whether to enable Google Suggestions.
 *
 * @return boolean
 */
add_filter( 'seopress_ui_metabox_google_suggest', '__return_true' );

/**
 * Get Page Speed Mobile Score
 *
 * @since 5.3
 *
 * @return string
 * @param mixed $json The JSON data.
 * @param mixed $is_mobile Whether to get the score for mobile.
 */
function seopress_pro_get_ps_score( $json, $is_mobile = false ) {
	if ( ! is_array( $json ) ) {
		return;
	}
	if ( 'error' === array_key_first( $json ) ) {
		return;
	}

	$ps_score = $json['lighthouseResult']['categories']['performance']['score'] ? ( $json['lighthouseResult']['categories']['performance']['score'] ) * 100 : '';
	if ( true === $is_mobile ) {
		$i18n = __( 'Mobile', 'wp-seopress-pro' );
	} else {
		$i18n = __( 'Desktop', 'wp-seopress-pro' );
	}

	if ( $ps_score >= 0 && $ps_score < 49 ) {
		$class_score = 'red';
	} elseif ( $ps_score >= 50 && $ps_score < 90 ) {
		$class_score = 'yellow';
	} elseif ( $ps_score >= 90 && $ps_score <= 100 ) {
		$class_score = 'green';
	} else {
		$class_score = 'grey';
	}

	$perf_score = '<div class="wrap-chart">
	<p>' . $i18n . '</p>
		<div class="ps-score ' . $class_score . '">
			<svg role="img" aria-hidden="true" focusable="false" width="100%" height="100%" viewBox="0 0 36 36" version="1.1" xmlns="http://www.w3.org/2000/svg">
				<path stroke-dasharray="100, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"></path>
				<path id="bar" stroke-dasharray="' . $ps_score . ', 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"></path>
			</svg>
			<span>' . $ps_score . '%</span>
		</div>
	</div>';

	return $perf_score;
}

/**
 * Get Core Web Vitals Score
 *
 * @return string
 * @param mixed $json The JSON data.
 */
function seopress_pro_get_cwv_score( $json ) {
	if ( array_key_first( $json ) === 'error' ) {
		return;
	}
	$core_web_vitals_score = false;

	if ( ! isset( $json['loadingExperience']['metrics'] ) ) {
		$core_web_vitals_score = null;
		return $core_web_vitals_score;
	}

	if (
		( isset( $json['loadingExperience']['metrics']['FIRST_INPUT_DELAY_MS']['category'] ) && 'FAST' === $json['loadingExperience']['metrics']['FIRST_INPUT_DELAY_MS']['category'] ) &&
			( isset( $json['loadingExperience']['metrics']['LARGEST_CONTENTFUL_PAINT_MS']['category'] ) && 'FAST' === $json['loadingExperience']['metrics']['LARGEST_CONTENTFUL_PAINT_MS']['category'] ) &&
			( isset( $json['loadingExperience']['metrics']['CUMULATIVE_LAYOUT_SHIFT_SCORE']['category'] ) && 'FAST' === $json['loadingExperience']['metrics']['CUMULATIVE_LAYOUT_SHIFT_SCORE']['category'] ) ) {
		$core_web_vitals_score = true;
	} elseif (
		( isset( $json['loadingExperience']['metrics']['LARGEST_CONTENTFUL_PAINT_MS']['category'] ) && 'FAST' === $json['loadingExperience']['metrics']['LARGEST_CONTENTFUL_PAINT_MS']['category'] ) &&
		( isset( $json['loadingExperience']['metrics']['CUMULATIVE_LAYOUT_SHIFT_SCORE']['category'] ) && 'FAST' === $json['loadingExperience']['metrics']['CUMULATIVE_LAYOUT_SHIFT_SCORE']['category'] )
	) {
		$core_web_vitals_score = true;
	}

	return $core_web_vitals_score;
}

/**
 * Get GA Dashboard widget role option
 *
 * @return string
 */
function seopress_advanced_security_ga_widget_role_option() {
	$service = seopress_get_service( 'AdvancedOption' );

	if ( ! empty( $service ) || ! method_exists( $service, 'getSecurityGaWidgetRole' ) ) {
		$data = get_option( 'seopress_advanced_option_name' );
		if ( isset( $data['seopress_advanced_security_ga_widget_role'] ) ) {
			return $data['seopress_advanced_security_ga_widget_role'];
		}
	}

	return $service->getSecurityGaWidgetRole();
}

/**
 * Check GA Dashboard widget capability
 *
 * @return boolean
 */
function seopress_advanced_security_ga_widget_check() {
	if ( empty( seopress_advanced_security_ga_widget_role_option() ) ) {
		$seopress_ga_dashboard_widget_cap = 'edit_dashboard';
		$seopress_ga_dashboard_widget_cap = apply_filters( 'seopress_ga_dashboard_widget_cap', $seopress_ga_dashboard_widget_cap );

		return current_user_can( $seopress_ga_dashboard_widget_cap );
	}

	global $wp_roles;

	// Get current user role.
	if ( ! isset( wp_get_current_user()->roles[0] ) ) {
		return;
	}
	$seopress_user_role = wp_get_current_user()->roles[0];

	if ( array_key_exists( $seopress_user_role, seopress_advanced_security_ga_widget_role_option() ) ) {
		return true;
	}

	return;
}

/**
 * Get Matomo Dashboard widget role option
 *
 * @return string
 */
function seopress_advanced_security_matomo_widget_role_option() {
	$service = seopress_get_service( 'AdvancedOption' );

	if ( ! empty( $service ) || ! method_exists( $service, 'getSecurityMatomoWidgetRole' ) ) {
		$data = get_option( 'seopress_advanced_option_name' );
		if ( isset( $data['seopress_advanced_security_matomo_widget_role'] ) ) {
			return $data['seopress_advanced_security_matomo_widget_role'];
		}
	}

	return $service->getSecurityMatomoWidgetRole();
}

/**
 * Check Matomo Dashboard widget capability
 *
 * @return boolean
 */
function seopress_advanced_security_matomo_widget_check() {
	if ( empty( seopress_advanced_security_matomo_widget_role_option() ) ) {
		$cap = 'edit_dashboard';
		$cap = apply_filters( 'seopress_matomo_dashboard_widget_cap', $cap );

		return current_user_can( $cap );
	}

	global $wp_roles;

	// Get current user role.
	if ( ! isset( wp_get_current_user()->roles[0] ) ) {
		return;
	}
	$seopress_user_role = wp_get_current_user()->roles[0];

	if ( array_key_exists( $seopress_user_role, seopress_advanced_security_matomo_widget_role_option() ) ) {
		return true;
	}

	return;
}

/**
 * Get the AI generation allowed-roles option.
 *
 * Stored alongside the other Advanced > Security role gates in the free
 * plugin option array. Read directly so PRO does not depend on a free-side
 * getter being present.
 *
 * @since 10.1.0
 *
 * @return array Role-keyed array of allowed roles (empty = everyone allowed).
 */
function seopress_ai_generation_role_option() {
	$data = get_option( 'seopress_advanced_option_name' );

	if ( is_array( $data ) && isset( $data['seopress_advanced_security_ai_role'] ) && is_array( $data['seopress_advanced_security_ai_role'] ) ) {
		return $data['seopress_advanced_security_ai_role'];
	}

	return array();
}

/**
 * Whether the current user may use AI generation features.
 *
 * ALLOW semantics: when no role is configured the behavior is unchanged
 * (every user holding the base capability keeps AI). When at least one role
 * is checked, only those roles (plus administrators) are allowed. The base
 * capability is always required so the gate can never grant more than the
 * user already had.
 *
 * @since 10.1.0
 *
 * @param int|null $post_id  Post being edited, if any (used for the base cap check).
 * @param string   $base_cap Base capability to require (edit_post / edit_posts).
 * @param string   $context  Surface being gated (metabox, social, image_meta, upload, bulk, chat).
 *
 * @return bool
 */
function seopress_ai_generation_check( $post_id = null, $base_cap = 'edit_posts', $context = '' ) {
	// Base capability: never grant AI to a user who cannot edit the content.
	$allowed = null !== $post_id ? current_user_can( $base_cap, $post_id ) : current_user_can( $base_cap );

	// Administrators are never restricted (they are not listed in the UI).
	if ( $allowed && ! current_user_can( 'manage_options' ) ) {
		$roles = seopress_ai_generation_role_option();

		// No role configured: default behavior, everyone keeps AI.
		if ( ! empty( $roles ) ) {
			$allowed = false;
			foreach ( (array) wp_get_current_user()->roles as $role ) {
				if ( array_key_exists( $role, $roles ) ) {
					$allowed = true;
					break;
				}
			}
		}
	}

	/**
	 * Filter whether the current user may use AI generation features.
	 *
	 * Lets developers implement finer-grained rules (per post type, per
	 * field, per surface) than the role selector exposes.
	 *
	 * @since 10.1.0
	 *
	 * @param bool     $allowed Whether AI generation is allowed.
	 * @param int|null $post_id Post being edited, if any.
	 * @param string   $context Surface being gated.
	 */
	return (bool) apply_filters( 'seopress_ai_generation_user_can', $allowed, $post_id, $context );
}

/**
 * Retrocompatibility for PHP < 8.0
 */
if ( ! function_exists( 'str_starts_with' ) ) {
	/**
	 * Check if a string starts with a given substring.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for.
	 *
	 * @return boolean
	 */
	function str_starts_with( $haystack, $needle ) {
		return '' !== (string) $needle && 0 === strncmp( $haystack, $needle, strlen( $needle ) );
	}
}

/**
 * Extract the days list from a Local Business opening hours schema meta.
 *
 * The value is stored under an inner key, and two shapes exist in the wild:
 *
 * - `array( 'seopress_pro_rich_snippets_lb_opening_hours' => $days )`, posted
 *   by the classic PHP schema form.
 * - `array( 0 => array( 'seopress_pro_rich_snippets_lb_opening_hours' => $days ) )`,
 *   written by the React schema editor, which mistook the numeric level added
 *   by `get_post_meta( $id, $key, false )` for part of the payload.
 *
 * Accept both so a schema keeps outputting its hours whichever UI saved it.
 *
 * @since 10.1.0
 *
 * @param mixed $value The raw meta value.
 *
 * @return array|string The days list (0-6), or '' when absent.
 */
function seopress_pro_get_lb_opening_hours_days( $value ) {
	$inner_key = 'seopress_pro_rich_snippets_lb_opening_hours';

	if ( ! is_array( $value ) ) {
		return '';
	}

	// Unwrap the extra numeric level written by the React editor.
	if ( ! isset( $value[ $inner_key ] ) && isset( $value[0] ) && is_array( $value[0] ) ) {
		$value = $value[0];
	}

	if ( ! isset( $value[ $inner_key ] ) || ! is_array( $value[ $inner_key ] ) ) {
		return '';
	}

	return $value[ $inner_key ];
}

/**
 * Get LB types list
 */
function seopress_lb_types_list() {
	$seopress_lb_types = array(
		'LocalBusiness'               => __( 'Local Business (default)', 'wp-seopress-pro' ),
		'AnimalShelter'               => __( 'Animal Shelter', 'wp-seopress-pro' ),
		'AutomotiveBusiness'          => __( 'Automotive Business', 'wp-seopress-pro' ),
		'AutoBodyShop'                => __( '|-Auto Body Shop', 'wp-seopress-pro' ),
		'AutoDealer'                  => __( '|-Auto Dealer', 'wp-seopress-pro' ),
		'AutoPartsStore'              => __( '|-Auto Parts Store', 'wp-seopress-pro' ),
		'AutoRental'                  => __( '|-Auto Rental', 'wp-seopress-pro' ),
		'AutoRepair'                  => __( '|-Auto Repair', 'wp-seopress-pro' ),
		'AutoWash'                    => __( '|-AutoWash', 'wp-seopress-pro' ),
		'GasStation'                  => __( '|-Gas Station', 'wp-seopress-pro' ),
		'MotorcycleDealer'            => __( '|-Motorcycle Dealer', 'wp-seopress-pro' ),
		'MotorcycleRepair'            => __( '|-Motorcycle Repair', 'wp-seopress-pro' ),
		'ChildCare'                   => __( 'Child Care', 'wp-seopress-pro' ),
		'DryCleaningOrLaundry'        => __( 'Dry Cleaning Or Laundry', 'wp-seopress-pro' ),
		'EmergencyService'            => __( 'Emergency Service', 'wp-seopress-pro' ),
		'FireStation'                 => __( '|-Fire Station', 'wp-seopress-pro' ),
		'Hospital'                    => __( '|-Hospital', 'wp-seopress-pro' ),
		'PoliceStation'               => __( '|-Police Station', 'wp-seopress-pro' ),
		'EmploymentAgency'            => __( 'Employment Agency', 'wp-seopress-pro' ),
		'EntertainmentBusiness'       => __( 'Entertainment Business', 'wp-seopress-pro' ),
		'AdultEntertainment'          => __( '|-Adult Entertainment', 'wp-seopress-pro' ),
		'AmusementPark'               => __( '|-Amusement Park', 'wp-seopress-pro' ),
		'ArtGallery'                  => __( '|-Art Gallery', 'wp-seopress-pro' ),
		'Casino'                      => __( '|-Casino', 'wp-seopress-pro' ),
		'ComedyClub'                  => __( '|-Comedy Club', 'wp-seopress-pro' ),
		'MovieTheater'                => __( '|-Movie Theater', 'wp-seopress-pro' ),
		'NightClub'                   => __( '|-Night Club', 'wp-seopress-pro' ),
		'FinancialService'            => __( 'Financial Service', 'wp-seopress-pro' ),
		'AccountingService'           => __( '|-Accounting Service', 'wp-seopress-pro' ),
		'AutomatedTeller'             => __( '|-Automated Teller', 'wp-seopress-pro' ),
		'BankOrCreditUnion'           => __( '|-Bank Or Credit Union', 'wp-seopress-pro' ),
		'InsuranceAgency'             => __( '|-Insurance Agency', 'wp-seopress-pro' ),
		'FoodEstablishment'           => __( 'Food Establishment', 'wp-seopress-pro' ),
		'Bakery'                      => __( '|-Bakery', 'wp-seopress-pro' ),
		'BarOrPub'                    => __( '|-Bar Or Pub', 'wp-seopress-pro' ),
		'Brewery'                     => __( '|-Brewery', 'wp-seopress-pro' ),
		'CafeOrCoffeeShop'            => __( '|-Cafe Or Coffee Shop', 'wp-seopress-pro' ),
		'FastFoodRestaurant'          => __( '|-Fast Food Restaurant', 'wp-seopress-pro' ),
		'IceCreamShop'                => __( '|-Ice Cream Shop', 'wp-seopress-pro' ),
		'Restaurant'                  => __( '|-Restaurant', 'wp-seopress-pro' ),
		'Winery'                      => __( '|-Winery', 'wp-seopress-pro' ),
		'GovernmentOffice'            => __( 'Government Office', 'wp-seopress-pro' ),
		'PostOffice'                  => __( '|-PostOffice', 'wp-seopress-pro' ),
		'HealthAndBeautyBusiness'     => __( 'Health And Beauty Business', 'wp-seopress-pro' ),
		'BeautySalon'                 => __( '|-Beauty Salon', 'wp-seopress-pro' ),
		'DaySpa'                      => __( '|-Day Spa', 'wp-seopress-pro' ),
		'HairSalon'                   => __( '|-Hair Salon', 'wp-seopress-pro' ),
		'HealthClub'                  => __( '|-Health Club', 'wp-seopress-pro' ),
		'NailSalon'                   => __( '|-Nail Salon', 'wp-seopress-pro' ),
		'TattooParlor'                => __( '|-Tattoo Parlor', 'wp-seopress-pro' ),
		'HomeAndConstructionBusiness' => __( 'Home And Construction Business', 'wp-seopress-pro' ),
		'Electrician'                 => __( '|-Electrician', 'wp-seopress-pro' ),
		'HVACBusiness'                => __( '|-HVAC Business', 'wp-seopress-pro' ),
		'HousePainter'                => __( '|-House Painter', 'wp-seopress-pro' ),
		'Locksmith'                   => __( '|-Locksmith', 'wp-seopress-pro' ),
		'MovingCompany'               => __( '|-Moving Company', 'wp-seopress-pro' ),
		'Plumber'                     => __( '|-Plumber', 'wp-seopress-pro' ),
		'RoofingContractor'           => __( '|-Roofing Contractor', 'wp-seopress-pro' ),
		'InternetCafe'                => __( 'Internet Cafe', 'wp-seopress-pro' ),
		'MedicalBusiness'             => __( 'Medical Business', 'wp-seopress-pro' ),
		'CommunityHealth'             => __( '|-Community Health', 'wp-seopress-pro' ),
		'Dentist'                     => __( '|-Dentist', 'wp-seopress-pro' ),
		'Dermatology'                 => __( '|-Dermatology', 'wp-seopress-pro' ),
		'DietNutrition'               => __( '|-Diet Nutrition', 'wp-seopress-pro' ),
		'Emergency'                   => __( '|-Emergency', 'wp-seopress-pro' ),
		'Gynecologic'                 => __( '|-Gynecologic', 'wp-seopress-pro' ),
		'MedicalClinic'               => __( '|-Medical Clinic', 'wp-seopress-pro' ),
		'Midwifery'                   => __( '|-Midwifery', 'wp-seopress-pro' ),
		'Nursing'                     => __( '|-Nursing', 'wp-seopress-pro' ),
		'Obstetric'                   => __( '|-Obstetric', 'wp-seopress-pro' ),
		'Oncologic'                   => __( '|-Oncologic', 'wp-seopress-pro' ),
		'Optician'                    => __( '|-Optician', 'wp-seopress-pro' ),
		'Optometric'                  => __( '|-Optometric', 'wp-seopress-pro' ),
		'Otolaryngologic'             => __( '|-Otolaryngologic', 'wp-seopress-pro' ),
		'Pediatric'                   => __( '|-Pediatric', 'wp-seopress-pro' ),
		'Pharmacy'                    => __( '|-Pharmacy', 'wp-seopress-pro' ),
		'Physician'                   => __( '|-Physician', 'wp-seopress-pro' ),
		'Physiotherapy'               => __( '|-Physiotherapy', 'wp-seopress-pro' ),
		'PlasticSurgery'              => __( '|-Plastic Surgery', 'wp-seopress-pro' ),
		'Podiatric'                   => __( '|-Podiatric', 'wp-seopress-pro' ),
		'PrimaryCare'                 => __( '|-Primary Care', 'wp-seopress-pro' ),
		'Psychiatric'                 => __( '|-Psychiatric', 'wp-seopress-pro' ),
		'PublicHealth'                => __( '|-Public Health', 'wp-seopress-pro' ),
		'VeterinaryCare'              => __( '|-Veterinary Care', 'wp-seopress-pro' ),
		'LegalService'                => __( 'Legal Service', 'wp-seopress-pro' ),
		'Attorney'                    => __( '|-Attorney', 'wp-seopress-pro' ),
		'Notary'                      => __( '|-Notary', 'wp-seopress-pro' ),
		'Library'                     => __( 'Library', 'wp-seopress-pro' ),
		'LodgingBusiness'             => __( 'Lodging Business', 'wp-seopress-pro' ),
		'BedAndBreakfast'             => __( '|-Bed And Breakfast', 'wp-seopress-pro' ),
		'Campground'                  => __( '|-Campground', 'wp-seopress-pro' ),
		'Hostel'                      => __( '|-Hostel', 'wp-seopress-pro' ),
		'Hotel'                       => __( '|-Hotel', 'wp-seopress-pro' ),
		'Motel'                       => __( '|-Motel', 'wp-seopress-pro' ),
		'Resort'                      => __( '|-Resort', 'wp-seopress-pro' ),
		'ProfessionalService'         => __( 'Professional Service', 'wp-seopress-pro' ),
		'RadioStation'                => __( 'Radio Station', 'wp-seopress-pro' ),
		'RealEstateAgent'             => __( 'Real Estate Agent', 'wp-seopress-pro' ),
		'RecyclingCenter'             => __( 'Recycling Center', 'wp-seopress-pro' ),
		'SelfStorage'                 => __( 'Real Self Storage', 'wp-seopress-pro' ),
		'ShoppingCenter'              => __( 'Shopping Center', 'wp-seopress-pro' ),
		'SportsActivityLocation'      => __( 'Sports Activity Location', 'wp-seopress-pro' ),
		'BowlingAlley'                => __( '|-Bowling Alley', 'wp-seopress-pro' ),
		'ExerciseGym'                 => __( '|-Exercise Gym', 'wp-seopress-pro' ),
		'GolfCourse'                  => __( '|-Golf Course', 'wp-seopress-pro' ),
		'HealthClub'                  => __( '|-Health Club', 'wp-seopress-pro' ), //phpcs:ignore
		'PublicSwimmingPool'          => __( '|-Public Swimming Pool', 'wp-seopress-pro' ),
		'SkiResort'                   => __( '|-Ski Resort', 'wp-seopress-pro' ),
		'SportsClub'                  => __( '|-Sports Club', 'wp-seopress-pro' ),
		'StadiumOrArena'              => __( '|-Stadium Or Arena', 'wp-seopress-pro' ),
		'TennisComplex'               => __( '|-Tennis Complex', 'wp-seopress-pro' ),
		'Store'                       => __( 'Store', 'wp-seopress-pro' ),
		'AutoPartsStore'              => __( '|-Auto Parts Store', 'wp-seopress-pro' ), //phpcs:ignore
		'BikeStore'                   => __( '|-Bike Store', 'wp-seopress-pro' ),
		'BookStore'                   => __( '|-Book Store', 'wp-seopress-pro' ),
		'ClothingStore'               => __( '|-Clothing Store', 'wp-seopress-pro' ),
		'ComputerStore'               => __( '|-Computer Store', 'wp-seopress-pro' ),
		'ConvenienceStore'            => __( '|-Convenience Store', 'wp-seopress-pro' ),
		'DepartmentStore'             => __( '|-Department Store', 'wp-seopress-pro' ),
		'ElectronicsStore'            => __( '|-Electronics Store', 'wp-seopress-pro' ),
		'Florist'                     => __( '|-Florist', 'wp-seopress-pro' ),
		'FurnitureStore'              => __( '|-Furniture Store', 'wp-seopress-pro' ),
		'GardenStore'                 => __( '|-Garden Store', 'wp-seopress-pro' ),
		'GroceryStore'                => __( '|-Grocery Store', 'wp-seopress-pro' ),
		'HardwareStore'               => __( '|-Hardware Store', 'wp-seopress-pro' ),
		'HobbyShop'                   => __( '|-Hobby Shop', 'wp-seopress-pro' ),
		'HomeGoodsStore'              => __( '|-Home Goods Store', 'wp-seopress-pro' ),
		'JewelryStore'                => __( '|-Jewelry Store', 'wp-seopress-pro' ),
		'LiquorStore'                 => __( '|-Liquor Store', 'wp-seopress-pro' ),
		'MensClothingStore'           => __( '|-Mens Clothing Store', 'wp-seopress-pro' ),
		'MobilePhoneStore'            => __( '|-Mobile Phone Store', 'wp-seopress-pro' ),
		'MovieRentalStore'            => __( '|-Movie Rental Store', 'wp-seopress-pro' ),
		'MusicStore'                  => __( '|-Music Store', 'wp-seopress-pro' ),
		'OfficeEquipmentStore'        => __( '|-Office Equipment Store', 'wp-seopress-pro' ),
		'OutletStore'                 => __( '|-Outlet Store', 'wp-seopress-pro' ),
		'PawnShop'                    => __( '|-Pawn Shop', 'wp-seopress-pro' ),
		'PetStore'                    => __( '|-Pet Store', 'wp-seopress-pro' ),
		'ShoeStore'                   => __( '|-Shoe Store', 'wp-seopress-pro' ),
		'SportingGoodsStore'          => __( '|-Sporting Goods Store', 'wp-seopress-pro' ),
		'TireShop'                    => __( '|-Tire Shop', 'wp-seopress-pro' ),
		'ToyStore'                    => __( '|-Toy Store', 'wp-seopress-pro' ),
		'WholesaleStore'              => __( '|-Wholesale Store', 'wp-seopress-pro' ),
		'TelevisionStation'           => __( '|-Wholesale Store', 'wp-seopress-pro' ),
		'TouristInformationCenter'    => __( 'Tourist Information Center', 'wp-seopress-pro' ),
		'TravelAgency'                => __( 'Travel Agency', 'wp-seopress-pro' ),
	);

	$seopress_lb_types = apply_filters( 'seopress_schemas_lb_types', $seopress_lb_types );

	return $seopress_lb_types;
}

$versions       = get_option( 'seopress_versions' );
$actual_version = isset( $versions['free'] ) ? $versions['free'] : 0;

if ( version_compare( $actual_version, '6.7', '>=' ) || ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG === true ) ) {
	add_filter( 'seopress_notifications_center_item', 'seopress_pro_notifications_list', 10, 5 );
	/**
	 * Filter Notifications manager
	 *
	 * @param array $args The array of notifications.
	 * @param int   $alerts_info The number of info alerts.
	 * @param int   $alerts_low The number of low alerts.
	 * @param int   $alerts_medium The number of medium alerts.
	 * @param int   $alerts_high The number of high alerts.
	 * @return array
	 */
	function seopress_pro_notifications_list( $args, $alerts_info, $alerts_low, $alerts_medium, $alerts_high ) {
		$option_pro_service    = seopress_pro_get_service( 'OptionPro' );
		$notice_option_service = seopress_pro_get_service( 'NoticeOption' );

		if ( null !== $option_pro_service && method_exists( $option_pro_service, 'get404Cleaning' ) ) {
			if ( $option_pro_service->get404Cleaning() === '1' && ! wp_next_scheduled( 'seopress_404_cron_cleaning' ) ) {

				$args[] = array(
					'id'         => 'notice-title-tag',
					'title'      => __( 'You have enabled 404 cleaning BUT the scheduled task is not running.', 'wp-seopress-pro' ),
					'desc'       => __( 'To solve this, please disable and re-enable SEOPress PRO. No data will be lost.', 'wp-seopress-pro' ),
					'impact'     => array(
						'medium' => __( 'Medium impact', 'wp-seopress-pro' ),
					),
					'deleteable' => false,
					'status'     => true,
				);
			}
		}
		if ( '1' === seopress_get_toggle_option( 'rich-snippets' ) ) {
			if ( '1' !== $option_pro_service->getRichSnippetEnable() ) {
				++$alerts_high;

				$args[] = array(
					'id'         => 'notice-schemas-metabox',
					'title'      => __( 'Structured data types is not correctly enabled', 'wp-seopress-pro' ),
					'desc'       => __( 'Please enable <strong>Structured Data Types metabox for your posts, pages and custom post types</strong> option in order to use automatic and manual schemas. (SEO > PRO > Structured Data Types (schema.org)', 'wp-seopress-pro' ),
					'impact'     => array(
						'high' => __( 'High impact', 'wp-seopress-pro' ),
					),
					'link'       => array(
						'en'       => esc_url( admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_rich_snippets' ) ),
						'title'    => __( 'Fix this!', 'wp-seopress-pro' ),
						'external' => false,
					),
					'deleteable' => false,
					'status'     => true,
				);
			}
		}

		if ( 'valid' !== get_option( 'seopress_pro_license_status' ) ) {
			++$alerts_info;

			$args[] = array(
				'id'         => 'notice-license',
				'title'      => __( 'You have to enter your licence key to get updates and support', 'wp-seopress-pro' ),
				'desc'       => __( 'Please activate the SEOPress PRO license key to automatically receive updates to guarantee you the best user experience possible.', 'wp-seopress-pro' ),
				'impact'     => array(
					'info' => __( 'License', 'wp-seopress-pro' ),
				),
				'link'       => array(
					'en'       => admin_url( 'admin.php?page=seopress-license' ),
					'title'    => __( 'Fix this!', 'wp-seopress-pro' ),
					'external' => false,
				),
				'deleteable' => false,
				'status'     => true,
			);
		}

		$status = false;
		if ( null !== $notice_option_service && file_exists( ABSPATH . 'robots.txt' ) && '1' !== $notice_option_service->getNoticeRobotsTxt() ) {
			++$alerts_high;
			$status = true;

			$args[] = array(
				'id'         => 'notice-robots-txt',
				'title'      => __( 'A physical robots.txt file has been found', 'wp-seopress-pro' ),
				'desc'       => __( 'A robots.txt file already exists at the root of your site. We invite you to remove it so we can handle it virtually.', 'wp-seopress-pro' ),
				'impact'     => array(
					'high' => __( 'High impact', 'wp-seopress-pro' ),
				),
				'deleteable' => true,
				'status'     => $status ? $status : false,
			);
		}

		// GA4: property ID === measurement.
		if ( '1' === seopress_get_toggle_option( 'google-analytics' ) ) {
			if ( ! empty( seopress_get_service( 'GoogleAnalyticsOption' )->getGA4PropertId() ) && ! empty( seopress_get_service( 'GoogleAnalyticsOption' )->getGA4() ) ) {
				$status = false;
				if ( seopress_get_service( 'GoogleAnalyticsOption' )->getGA4PropertId() === seopress_get_service( 'GoogleAnalyticsOption' )->getGA4() ) {
					++$alerts_info;
					$status = true;

					$args[] = array(
						'id'         => 'notice-ga4-property-id',
						'title'      => __( 'Your GA4 property ID is incorrectly set!', 'wp-seopress-pro' ),
						'desc'       => __( 'To get your Google Analytics stats in dashboard, your GA4 property ID must NOT be equal to your GA4 measurement ID.', 'wp-seopress-pro' ),
						'impact'     => array(
							'high' => __( 'High impact', 'wp-seopress-pro' ),
						),
						'link'       => array(
							'en'       => admin_url( 'admin.php?page=seopress-google-analytics#seopress-analytics-stats' ),
							'title'    => __( 'Fix this!', 'wp-seopress-pro' ),
							'external' => false,
						),
						'deleteable' => false,
						'status'     => ( $status ? $status : false ),
					);
				}
			}
		}

		$args['impact']['high']   = $alerts_high;
		$args['impact']['medium'] = $alerts_medium;
		$args['impact']['low']    = $alerts_low;
		$args['impact']['info']   = $alerts_info;

		return $args;
	}
}

/**
 * Attach the seopress_seo_issues rows for $post_id to the content-analysis
 * REST payload. The React panel uses this map to render per-check ignore
 * controls (button + greyed state) without an extra round trip.
 *
 * The map is keyed by issue_type so the editor side can look up entries
 * the same way the audit DataViews does. Each entry exposes only what
 * the UI needs: id (primary key, used by the bulk-ignore endpoint),
 * issue_name (granularity inside the type), and ignored (boolean).
 *
 * @since 9.9.0
 *
 * @param array $data    The analysis payload.
 * @param int   $post_id Post id being analyzed.
 *
 * @return array
 */
function seopress_pro_attach_seo_issues_to_content_analysis( $data, $post_id ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}

	$post_id = absint( $post_id );
	if ( ! $post_id ) {
		return $data;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'seopress_seo_issues';

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT id, issue_name, issue_type, issue_ignore FROM {$table} WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$post_id
		)
	);

	$by_type = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$type = isset( $row->issue_type ) ? (string) $row->issue_type : '';
			if ( '' === $type ) {
				continue;
			}
			if ( ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = array();
			}
			$by_type[ $type ][] = array(
				'id'         => (int) $row->id,
				'issue_name' => (string) $row->issue_name,
				'ignored'    => 1 === (int) $row->issue_ignore,
			);
		}
	}

	$data['seo_issues'] = $by_type;

	return $data;
}
add_filter( 'seopress_content_analysis_response', 'seopress_pro_attach_seo_issues_to_content_analysis', 10, 2 );
