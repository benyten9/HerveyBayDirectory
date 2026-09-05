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
 * Get the supported AI providers and the wp-config.php constant that pins each
 * one's API key.
 *
 * Single source of truth for the provider list: the settings tab, the wizard
 * step and the key hints all read it, so adding a provider is a one-line change
 * here. Order matches the AI settings tab, SEOPress Credits first — it is the
 * only one that needs no provider account of its own.
 *
 * @since 10.2.0
 *
 * @return array Map of provider slug => array( 'label', 'constant' ).
 */
function seopress_ai_get_providers() {
	return array(
		'seopress' => array(
			'label'    => __( 'SEOPress AI Credits', 'wp-seopress-pro' ),
			'constant' => 'SEOPRESS_CREDITS_KEY',
		),
		'openai'   => array(
			'label'    => 'OpenAI',
			'constant' => 'SEOPRESS_OPENAI_KEY',
		),
		'deepseek' => array(
			'label'    => 'DeepSeek',
			'constant' => 'SEOPRESS_DEEPSEEK_KEY',
		),
		'gemini'   => array(
			'label'    => 'Gemini',
			'constant' => 'SEOPRESS_GEMINI_KEY',
		),
		'mistral'  => array(
			'label'    => 'Mistral',
			'constant' => 'SEOPRESS_MISTRAL_KEY',
		),
		'claude'   => array(
			'label'    => 'Claude',
			'constant' => 'SEOPRESS_CLAUDE_KEY',
		),
	);
}

/**
 * Get the provider list shaped for a React <SelectControl>.
 *
 * @since 10.2.0
 *
 * @return array List of array( 'value' => slug, 'label' => name ).
 */
function seopress_ai_get_providers_list() {
	$options = array();

	foreach ( seopress_ai_get_providers() as $slug => $provider ) {
		$options[] = array(
			'value' => $slug,
			'label' => $provider['label'],
		);
	}

	return $options;
}

/**
 * Get the wp-config.php constant that overrides a provider's API key.
 *
 * @since 10.2.0
 *
 * @param string $provider Provider slug.
 * @return string Constant name, or an empty string for an unknown provider.
 */
function seopress_ai_get_provider_constant( $provider ) {
	$providers = seopress_ai_get_providers();

	return isset( $providers[ $provider ] ) ? $providers[ $provider ]['constant'] : '';
}

/**
 * Whether a provider's API key is pinned in wp-config.php.
 *
 * Matches what Usage::getLicenseKey() actually honors: a constant defined but
 * left empty is ignored there, so it must not read as "pinned" here either —
 * otherwise the settings UI locks the field and claims a key that is never used.
 *
 * @since 10.2.0
 *
 * @param string $provider Provider slug.
 * @return bool
 */
function seopress_ai_provider_key_is_constant( $provider ) {
	$constant = seopress_ai_get_provider_constant( $provider );

	return '' !== $constant && defined( $constant ) && ! empty( constant( $constant ) );
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
 * Whether the free plugin is recent enough to drive the per-post opening hours.
 *
 * The property is rendered and saved by the universal metabox, which lives in
 * the free plugin. An older free build renders the field but writes it back in
 * the shape its own tab expects, without the weekday translations and without
 * the override merge, so the option is not offered there. Schemas that already
 * opted in keep working: the resolver reads the meta whatever the free version
 * is, and the callers below keep showing the option when it is the stored one,
 * so saving such a schema never silently resets it.
 *
 * @since 10.2.0
 *
 * @return bool
 */
function seopress_pro_has_per_post_opening_hours_support() {
	$free_version = defined( 'SEOPRESS_VERSION' ) ? SEOPRESS_VERSION : '0';

	// In development the version is the literal `{VERSION}` placeholder, which
	// version_compare() would read as older than any release.
	if ( '{VERSION}' === $free_version ) {
		return true;
	}

	return version_compare( $free_version, '10.2', '>=' );
}

/**
 * Resolve the opening hours of an automatic Local Business schema for the post
 * being rendered.
 *
 * Two modes, kept apart on purpose:
 *
 * - Opt-in (`_source` meta set to `manual_opening_hours_single`): the schema
 *   delegates the property to each post, so the post value is authoritative,
 *   including when the post left it empty.
 * - Default (no `_source` meta, which is every schema created before 10.2):
 *   the historical precedence applies untouched — a post that filled the hours
 *   through the classic metabox wins, otherwise the schema global applies.
 *
 * @since 10.2.0
 *
 * @param int    $schema_id            The `seopress_schemas` post ID.
 * @param string $meta_key             The global opening hours meta key.
 * @param string $schema_name          The schema short name (e.g. `lb`).
 * @param array  $seopress_pro_schemas The `_seopress_pro_schemas` post meta of the current post.
 *
 * @return array|string The days list (0-6), or '' when absent.
 */
function seopress_pro_resolve_lb_opening_hours( $schema_id, $meta_key, $schema_name, $seopress_pro_schemas ) {
	$section  = 'rich_snippets_' . $schema_name;
	$per_post = isset( $seopress_pro_schemas[0][ $schema_id ][ $section ]['opening_hours'] )
		? $seopress_pro_schemas[0][ $schema_id ][ $section ]['opening_hours']
		: null;

	// The React field keeps its seven days wrapped; the meta stores them bare.
	// Accept both, the way the global hours already do, so the JSON-LD does not
	// depend on which save path wrote the value.
	if ( is_array( $per_post ) && isset( $per_post['seopress_local_business_opening_hours'] ) && is_array( $per_post['seopress_local_business_opening_hours'] ) ) {
		$per_post = $per_post['seopress_local_business_opening_hours'];
	}

	if ( 'manual_opening_hours_single' === get_post_meta( $schema_id, $meta_key . '_source', true ) ) {
		return ! empty( $per_post ) && is_array( $per_post ) ? $per_post : '';
	}

	if ( ! empty( $per_post ) && is_array( $per_post ) && function_exists( 'seopress_if_key_exists' ) && true === seopress_if_key_exists( $per_post, 'open' ) ) {
		$hours = $per_post;
	} else {
		$hours = seopress_pro_get_lb_opening_hours_days( get_post_meta( $schema_id, $meta_key, true ) );
	}

	/*
	 * Both UIs that write these hours always cover the seven days. A shorter
	 * list never reached the JSON-LD before 10.2 — it fell through to a branch
	 * driven by the previous key of the resolver loop — so keep discarding it
	 * rather than start emitting hours on sites that never showed any.
	 */
	return is_array( $hours ) && 7 === count( $hours ) ? $hours : '';
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

/**
 * Neutralize a CSV cell a spreadsheet would evaluate as a formula.
 *
 * Excel, LibreOffice Calc and Google Sheets treat a cell starting with =, +,
 * - or @ as a formula, so a value that reached the database from an untrusted
 * source (a 404 URL logged from a visitor request, a title written by a
 * contributor) can run DDE or exfiltrate the sheet when a site owner opens the
 * export. Prefixing a single quote makes the cell a literal string.
 *
 * The tab and carriage return are included because a leading whitespace does
 * not stop the parsers from evaluating what follows.
 *
 * The importer strips the quote back (SEOPRESS_Importer::unescape_data()), so
 * an export/import round trip is lossless.
 *
 * @since 10.2.0
 *
 * @param mixed $value The cell value.
 *
 * @return mixed The value, quoted when it would otherwise be evaluated.
 */
function seopress_pro_escape_csv_value( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return $value;
	}

	if ( in_array( mb_substr( $value, 0, 1 ), array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
		return "'" . $value;
	}

	return $value;
}

/**
 * Reverse seopress_pro_escape_csv_value() when reading a cell back.
 *
 * Keeps an export/import round trip lossless: a redirection whose source path
 * legitimately starts with a dash must come back without the quote the export
 * added.
 *
 * @since 10.2.0
 *
 * @param mixed $value The cell value read from the CSV.
 *
 * @return mixed The value without the leading quote guard.
 */
function seopress_pro_unescape_csv_value( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return $value;
	}

	if ( in_array( mb_substr( $value, 0, 2 ), array( "'=", "'+", "'-", "'@", "'\t", "'\r" ), true ) ) {
		return mb_substr( $value, 1 );
	}

	return $value;
}

/**
 * Encode schema data for a `<script type="application/ld+json">` element.
 *
 * A thin wrapper over the free plugin's seopress_json_ld_encode(), which
 * decodes the HTML entities WordPress stores and re-escapes the result with
 * JSON's own escapes, so nothing printed here needs an HTML unescaping pass to
 * be read. Google applies that pass exactly once and asks publishers to move
 * away from it.
 *
 * The wrapper exists because these call sites all run on the front end, where
 * an undefined function is a white page, and nothing stops a site from updating
 * Pro before the free plugin. On an older free plugin the schema keeps the
 * output it had rather than the process dying over it.
 *
 * @since 10.2.0
 *
 * @param mixed $data The schema data.
 *
 * @return string|false The JSON-LD, or false if the data cannot be encoded.
 */
function seopress_pro_json_ld_encode( $data ) {
	if ( function_exists( 'seopress_json_ld_encode' ) ) {
		return seopress_json_ld_encode( $data );
	}

	return seopress_pro_json_ld_encode_fallback( $data );
}

/**
 * Encode schema data on a site whose free plugin predates the encoder.
 *
 * The values are left as they are found, entities and all, because decoding
 * them is what the free plugin's encoder is for: a site running an older one
 * keeps the output it has always had rather than getting a different one out
 * of Pro alone.
 *
 * What is not kept is the bare `<` this used to print. `wp_json_encode()`
 * escapes the slash of a `</script>` by default, so no value could close the
 * element, but a value opening with `<!--<script>` still put the parser in the
 * double-escaped state where the element's own `</script>` no longer ends it
 * and the rest of the document is swallowed as script data. The How-to block
 * made that reachable: the block serializer used to escape its payload as
 * HTML, and rebuilding it from the attributes prints it raw.
 *
 * `JSON_HEX_TAG` and its three companions settle it. A JSON reader sees the
 * same strings it saw before — `\u003C` and `<` decode alike — so nothing
 * downstream changes; only the markup stops carrying characters a parser can
 * act on. The slashes and the unicode stay escaped, as they were.
 *
 * @since 10.2.0
 *
 * @param mixed $data The schema data.
 *
 * @return string|false The JSON-LD, or false if the data cannot be encoded.
 */
function seopress_pro_json_ld_encode_fallback( $data ) {
	return wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
}

/**
 * The only script type a custom schema is allowed to carry.
 *
 * @since 10.2.0
 *
 * @return string
 */
function seopress_pro_json_ld_script_type() {
	return 'application/ld+json';
}

/**
 * Drop every script element a JSON-LD payload has no business carrying.
 *
 * The custom schema field is the one place SEOPress lets an editor store a
 * `<script>` tag, so `wp_kses()` is called there with `script` on the
 * allowlist. An allowlist entry covers the tag, not the value of its
 * attributes: `<script type="text/javascript">` matches it just as well as
 * `<script type="application/ld+json">`, and the field is echoed into
 * `wp_head()` untouched. Constraining the attribute in the allowlist does not
 * help either — `wp_kses_check_attr_val()` knows `maxlen` and `minval`, not a
 * list of accepted values, and a failed check drops the attribute while
 * keeping the tag, which turns the payload into a bare `<script>` and makes
 * things worse.
 *
 * So the tags are filtered here instead. Every opening tag is rewritten from
 * what it declares: a JSON-LD one comes back canonical, stripped of whatever
 * a paste or a filter attached to it, and anything else disappears. A field
 * holding several schemas keeps all of them, which is what the metabox
 * allows.
 *
 * @since 10.2.0
 *
 * @param string $value The custom schema.
 *
 * @return string
 */
function seopress_pro_strip_foreign_script_tags( $value ) {
	if ( ! is_string( $value ) || false === stripos( $value, '<script' ) ) {
		return $value;
	}

	$type = preg_quote( seopress_pro_json_ld_script_type(), '#' );

	// `(?<![\w-])type` so that a `data-type` carrying the right value does not
	// vouch for a tag whose own type is `text/javascript`, and `\b` after the
	// type so that `application/ld+jsonx` is not read as a match while
	// `application/ld+json; charset=utf-8` still is.
	$declared = '(?<![\w-])type\s*=\s*["\']?\s*' . $type . '\b';

	// A foreign script goes with its body, so its source does not stay behind
	// as text. The body is matched as "holding no other script tag", which is
	// what stops a payload that never closes from swallowing the schema that
	// follows it: the pair simply fails to match and the tag alone is removed
	// by the pass below.
	$paired = preg_replace(
		'#<script\b(?![^>]*' . $declared . ')[^>]*>(?:(?!</?script\b).)*</script\s*>#is',
		'',
		$value
	);

	if ( null !== $paired ) {
		$value = $paired;
	}

	$rebuilt = preg_replace_callback(
		'#<script\b[^>]*>#i',
		function ( $matches ) use ( $declared ) {
			if ( ! preg_match( '#' . $declared . '#i', $matches[0] ) ) {
				return '';
			}

			return '<script type="' . seopress_pro_json_ld_script_type() . '">';
		},
		$value
	);

	return null === $rebuilt ? $value : $rebuilt;
}

/**
 * Sanitize a custom JSON-LD field on its way into the database.
 *
 * The three paths that store a schema — the REST route the metabox talks to,
 * the registered post meta the Block Editor writes through, and the Classic
 * Editor fallback — all ran the same `wp_kses()` call with the same
 * `script` allowlist. They share this instead, so the script type is
 * constrained in one place.
 *
 * @since 10.2.0
 *
 * @param mixed $value The submitted field value.
 *
 * @return string
 */
function seopress_pro_kses_json_ld( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	// Everything but a script tag goes, so nothing outside a schema can carry
	// an event handler. The type is then constrained on what is left.
	$value = wp_kses( (string) $value, array( 'script' => array( 'type' => array() ) ) );

	return seopress_pro_strip_foreign_script_tags( $value );
}

/**
 * Re-encode a custom JSON-LD payload with JSON escapes.
 *
 * A custom schema is a raw string an administrator pastes, with `%%variables%%`
 * resolved into it afterwards. Those values come out of WordPress
 * entity-encoded, so the JSON-LD reaches the page carrying `&#038;`, `&quot;`
 * and friends, and can only be read by a consumer that unescapes HTML. Google
 * now applies that pass exactly once and asks publishers to use standard JSON
 * escapes instead.
 *
 * Decoding the string as a whole is not an option — a `&quot;` sitting inside a
 * value would become the quote that ends it — so the payload is parsed and
 * re-encoded through seopress_pro_json_ld_encode(), which decodes the values and
 * escapes them at the JSON level. Anything that does not parse is handed back
 * untouched, so a schema that was already broken stays exactly as it was.
 *
 * @since 10.2.0
 *
 * @param string $html The custom schema, usually a full script element.
 *
 * @return string
 */
function seopress_pro_json_ld_normalize_custom( $html ) {
	if ( ! is_string( $html ) ) {
		return $html;
	}

	// A value stored before the script type was constrained on the way in is
	// still in the database, and nothing rewrites it until its post is saved
	// again. Filtering here is what keeps it from reaching wp_head(), and it
	// has to run before the early return below: a payload that carries no
	// brace has nothing to re-encode but may still carry a script tag.
	$html = seopress_pro_strip_foreign_script_tags( $html );

	if ( false === strpos( $html, '{' ) ) {
		return $html;
	}

	if ( false !== stripos( $html, '<script' ) ) {
		// The field may hold several script elements. Each body is normalized
		// on its own and everything around them is left alone.
		$normalized = preg_replace_callback(
			'#(<script\b[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>)(.*?)(</script\s*>)#is',
			function ( $matches ) {
				return $matches[1] . seopress_pro_json_ld_normalize_body( $matches[2] ) . $matches[3];
			},
			$html
		);

		return null === $normalized ? $html : $normalized;
	}

	return seopress_pro_json_ld_normalize_body( $html );
}

/**
 * Re-encode the body of a single JSON-LD payload.
 *
 * @since 10.2.0
 *
 * @param string $body The JSON, without its script element.
 *
 * @return string
 */
function seopress_pro_json_ld_normalize_body( $body ) {
	$decoded = json_decode( $body, true );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
		return $body;
	}

	$encoded = seopress_pro_json_ld_encode( $decoded );

	return false === $encoded ? $body : $encoded;
}

/**
 * Read REQUEST_URI without destroying percent-encoded characters.
 *
 * `sanitize_text_field()` runs `_sanitize_text_fields()`, which strips every
 * `%XX` sequence. That rule is meant for text; on a URL it deletes the
 * percent-encoded UTF-8 that makes up any non-ASCII path. A Japanese slug
 * such as `/topics/news/%E5%BB%BA%E8%A8%AD/` comes back as `/topics/news//`,
 * so the Markdown URL we advertise to agents points at a page that does not
 * exist. Reported on a live site; the same mechanism was fixed on redirection
 * origins in #1356.
 *
 * `esc_url_raw()` keeps `%XX` intact while still dropping tags and encoding
 * spaces, and it is already what the free plugin uses on this exact
 * superglobal (`Render.php`, `template-xml-sitemaps-single-term.php`,
 * `options-redirections.php`). #1356 could not use it because redirection
 * origins may hold regex patterns, whose metacharacters it would mangle;
 * nothing regex-shaped passes through here.
 *
 * It lives here rather than next to its Agent Readiness call sites because
 * `options-llms-txt.php` uses it too, and the two features sit behind two
 * toggles a site owner sets independently. Declared inside
 * `options-agent-ready.php`, it was simply absent whenever LLMS.txt was on and
 * Agent Readiness was off, and `seopress_serve_llms_txt()` fatals on
 * `do_parse_request` — every front-end page and every admin screen that calls
 * `wp()`. `seopress-pro.php` loads this file unconditionally, so a shared
 * helper is reachable here whatever the toggles say. See #1722.
 *
 * @since 10.2.0
 *
 * @param string $fallback Value to return when the superglobal is absent.
 *
 * @return string
 */
function seopress_agent_ready_request_uri( $fallback = '' ) {
	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return $fallback;
	}

	return esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() is the sanitizer; sanitize_text_field() would strip percent-encoding.
}

/**
 * Remove the path WordPress serves the site from.
 *
 * `WP::parse_request()` strips that prefix from `$wp->request`, so a request for
 * `/blog/tag/promo/` on a site installed in `/blog` arrives as `tag/promo/`, and
 * that is the shape the 404 log stores an origin under. The redirection matcher
 * rebuilds an absolute URL with `home_url( $wp->request )`, which puts the
 * prefix straight back, and then compares `blog/tag/promo/` against a stored
 * `tag/promo/`. The two can never be equal, so redirections created the normal
 * way never fired.
 *
 * Affects every install whose home URL carries a path: WordPress in a
 * subdirectory (`/blog`, `/wp`), and every subsite of a subdirectory multisite.
 * Subdomain multisite has no prefix and is unaffected, as is a site at the
 * domain root, where this returns the path untouched.
 *
 * The prefix is only removed when a full segment matches, so a site at `/blog`
 * does not shorten a request for `/blogging/`.
 *
 * Only the default matcher and `getCurrentUrl()` use this. The WPML and Weglot
 * branches build their URL from a home URL this function cannot reason about —
 * WPML deliberately unhooks its own filter around the call, Weglot returns a URL
 * of its own — and they exist precisely to offer an alternative prefix, so they
 * are left exactly as they were.
 *
 * @since 10.2.0
 *
 * @param string $path Path taken from the current URL.
 *
 * @return string The same path, without the site prefix.
 */
function seopress_pro_strip_home_path( $path ) {
	$path = (string) $path;

	$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$home_path = is_string( $home_path ) ? trim( $home_path, '/' ) : '';

	if ( '' === $home_path ) {
		return $path;
	}

	$leading = ( '' !== $path && '/' === $path[0] ) ? '/' : '';
	$rest    = ltrim( $path, '/' );

	if ( 0 === stripos( $rest, $home_path . '/' ) ) {
		return $leading . substr( $rest, strlen( $home_path ) + 1 );
	}

	if ( 0 === strcasecmp( $rest, $home_path ) ) {
		return $leading;
	}

	return $path;
}

/**
 * Reduce a redirection origin to the path it stands for.
 *
 * Origins travel decoded through the plugin, so `parse_url()` cannot be used to
 * take one apart: it replaces every byte in the C1 range (0x80-0x9F, plus 0xAD)
 * with an underscore. That destroys `ß`, every uppercase Latin accent, `í`, the
 * Polish, Czech, Turkish and Romanian letters, and every non-Latin script:
 *
 *     parse_url( 'tag/остров/' )['path']  →  tag/о_ _ _ов/
 *     parse_url( 'tag/straße/' )['path']  →  tag/stra_e/
 *
 * The result is invalid UTF-8, which the database then refuses outright — so
 * the save failed — or, on a three-byte `utf8` column, silently stores mangled.
 *
 * Only the scheme and the authority have to go, and they are ASCII by
 * definition, so removing them as text leaves everything after them untouched.
 * A value that is already a relative path comes back unchanged apart from its
 * leading slash.
 *
 * The fragment is dropped: browsers never send it.
 *
 * @since 10.2.0
 *
 * @param string $origin Origin as typed or pasted, decoded.
 *
 * @return string Path and query string, without a leading slash. Empty when the
 *                value carries no path at all, e.g. a bare domain.
 */
function seopress_pro_redirection_origin_path( $origin ) {
	$origin = (string) $origin;

	// `https://example.com`, `http://example.com`, or protocol-relative `//example.com`.
	$rest = preg_replace( '#^(?:[a-z][a-z0-9+.\-]*:)?//[^/?\#]*#i', '', $origin, 1 );

	if ( null === $rest ) {
		$rest = $origin;
	}

	$hash = strpos( $rest, '#' );
	if ( false !== $hash ) {
		$rest = substr( $rest, 0, $hash );
	}

	return ltrim( $rest, '/' );
}

/**
 * Parse a URL, then decode its components, in that order.
 *
 * `parse_url()` replaces every byte in the C1 range (0x80-0x9F) with an
 * underscore. Handing it a URL whose path has already been percent-decoded
 * therefore destroys any character whose UTF-8 continuation byte falls in
 * that range:
 *
 *     parse_url( '/tag/' . "\xd0\xbe\xd1\x81" )['path']  →  /tag/о_
 *
 * In Cyrillic that is every letter from `р` to `я`, so a redirection stored
 * for `остров` could never match the request it was created from. Greek,
 * Hebrew, Arabic and Thai are hit the same way; Latin accents are spared
 * (`é` is C3 A9), which is why this stayed invisible on Western sites.
 *
 * Parsing the encoded URL is safe, and decoding each component afterwards
 * gives back exactly the characters the origin was stored with.
 *
 * @since 10.2.0
 *
 * @param string $url URL to parse, percent-encoded.
 *
 * @return array|false|null|int|string Same shape as wp_parse_url().
 */
function seopress_pro_parse_url_decoded( $url ) {
	$parts = wp_parse_url( (string) $url );

	if ( ! is_array( $parts ) ) {
		return $parts;
	}

	// The htmlspecialchars() call mirrors what the callers used to apply to
	// the whole URL: the query keeps travelling escaped, and the existing
	// htmlspecialchars_decode() downstream still gives back a usable `&`.
	foreach ( array( 'path', 'query', 'fragment' ) as $component ) {
		if ( isset( $parts[ $component ] ) && '' !== $parts[ $component ] ) {
			$parts[ $component ] = htmlspecialchars(
				rawurldecode( $parts[ $component ] ),
				ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401
			);
		}
	}

	return $parts;
}

/**
 * Drop the query string from a URI that has already been decoded.
 *
 * The redirection matcher falls back to "ignore all parameters" when no exact
 * match is found, and at that point the URI it holds has been through
 * `rawurldecode()` already. Sending it back through `parse_url()` to get the
 * path is what `seopress_pro_parse_url_decoded()` exists to avoid: every byte
 * in the C1 range comes back as an underscore, so `tag/остров/?utm_source=x`
 * became `tag/о_ _ _ов/` and no non-Latin origin could ever be matched once a
 * query string was present.
 *
 * Ignoring the parameters only means cutting at the first `?`, so that is all
 * this does. Anything a decoded path may legitimately carry — a `#` that came
 * in as `%23`, raw UTF-8 — is left untouched, exactly as the exact-match pass
 * left it.
 *
 * @since 10.2.0
 *
 * @param string $uri URI, already decoded.
 *
 * @return string The URI without its query string.
 */
function seopress_pro_strip_query_string( $uri ) {
	$uri = (string) $uri;
	$pos = strpos( $uri, '?' );

	return false === $pos ? $uri : substr( $uri, 0, $pos );
}
