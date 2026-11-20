<?php
// Prohibit direct script loading.
defined('ABSPATH') || die('No direct script access allowed!');

use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;


if (!function_exists('atbdp_get_option')) {

    /**
     * @return array    It returns the role of the users
     */
    function all_rules()
    {
        return apply_filters('dgrc_default_user_roles', array(
            array(
                'value' => 'administrator',
                'label' => __('Administrator', 'directorist-claim-listing'),
            ),
            array(
                'value' => 'editor',
                'label' => __('Editor', 'directorist-claim-listing'),
            ),
            array(
                'value' => 'author',
                'label' => __('Author', 'directorist-claim-listing'),
            ),
            array(
                'value' => 'contributor',
                'label' => __('Contributor', 'directorist-claim-listing'),
            ),
            array(
                'value' => 'subscriber',
                'label' => __('Subscriber', 'directorist-claim-listing'),
            )
        ));
    }


    /**
     * It retrieves an option from the database if it exists and returns false if it is not exist.
     * It is a custom function to get the data of custom setting page
     * @param string $name The name of the option we would like to get. Eg. map_api_key
     * @param string $group The name of the group where the option is saved. eg. general_settings
     * @param mixed $default Default value for the option key if the option does not have value then default will be returned
     * @return mixed    It returns the value of the $name option if it exists in the option $group in the database, false otherwise.
     */

    function atbdp_get_option($name, $group, $default = false)
    {
        // at first get the group of options from the database.
        // then check if the data exists in the array and if it exists then return it
        // if not, then return false
        if (empty($name) || empty($group)) {
            if (!empty($default)) return $default;
            return false;
        } // vail if either $name or option $group is empty
        $options_array = (array)get_option($group);
        if (array_key_exists($name, $options_array)) {
            return $options_array[$name];
        } else {
            if (!empty($default)) return $default;
            return false;
        }
    }
}

if (!function_exists('get_directorist_option')) {

    /**
     * It retrieves an option from the database if it exists and returns false if it is not exist.
     * It is a custom function to get the data of custom setting page
     * @param string $name The name of the option we would like to get. Eg. map_api_key
     * @param mixed $default Default value for the option key if the option does not have value then default will be returned
     * @param bool $force_default Whether to use default value when database return anything other than NULL such as '', false etc
     * @return mixed    It returns the value of the $name option if it exists in the option $group in the database, false otherwise.
     */
    function get_directorist_option($name, $default = false, $force_default = false)
    {
        // at first get the group of options from the database.
        // then check if the data exists in the array and if it exists then return it
        // if not, then return false
        if (empty($name)) {
            return $default;
        }
        // get the option from the database and return it if it is not a null value. Otherwise, return the default value
        $options = (array)get_option('atbdp_option');
        $v = (array_key_exists($name, $options))
            ? $v = $options[sanitize_key($name)]
            : null;

        $newvalue = apply_filters( 'directorist_option', $v, $name );

        if ( $newvalue != $v ) {
           return $newvalue;
        }

        // use default only when the value of the $v is NULL
        if (is_null($v)) {
            return $default;
        }
        if ($force_default) {
            // use the default value even if the value of $v is falsy value returned from the database
            if (empty($v)) {
                return $default;
            }
        }
        return (isset($v)) ? $v : $default; // return the data if it is anything but NULL.
    }
}

if (!function_exists('atbdp_sanitize_array')) {
    /**
     * It sanitize a multi-dimensional array
     * @param array &$array The array of the data to sanitize
     * @return mixed
     */
    function atbdp_sanitize_array(&$array)
    {

        foreach ($array as &$value) {

            if (!is_array($value)) {

                // sanitize if value is not an array
                $value = sanitize_text_field($value);

            } else {

                // go inside this function again
                atbdp_sanitize_array($value);
            }

        }

        return $array;

    }
}

if (!function_exists('is_directoria_active')) {
    /**
     * It checks if the Directorist theme is installed currently.
     * @return bool It returns true if the directorist theme is active currently. False otherwise.
     */
    function is_directoria_active()
    {
        return wp_get_theme()->get_stylesheet() === 'directoria';
    }
}


/// claim listing

if (!function_exists('dcl_claim_status')) {
    function dcl_claim_status()
    {
        $status = array(
            'pending' => __('Pending', 'directorist-claim-listing'),
            'approved' => __('Approved', 'directorist-claim-listing'),
            'declined' => __('Decline', 'directorist-claim-listing'),
        );
        return $status;
    }
}

if (!function_exists('is_pricing_plans_active')) {
    /**
     * Check if the pricing plans extension is active.
     *
     * @return bool
     */
    function is_pricing_plans_active() {
        return function_exists( 'directorist_pricing_plans_singleton' );
    }
}

if (!function_exists('is_pricing_plans_active_with_dcl')) {
    /**
     * Check if pricing plans is active and claim is configured to charge by plan.
     *
     * @return bool
     */
    function is_pricing_plans_active_with_dcl()
    {
        if ( ! is_pricing_plans_active() ) {
            return false;
        }

        return 'pricing_plan' === get_directorist_option( 'claim_charge_by' );
    }
}

/**
 * Send confermation to the listing owner.
 *
 * @return   string    $result    Message based on the result.
 * @since    1.0.0
 *
 */
if (!function_exists('dcl_current_user')) {
    /** @deprecated Use DCL_Claim_Service::submit() with explicit validated input. */
    function dcl_current_user() {
        return DCL_Base()->directorist_do_claim( absint( $_POST['post_id'] ?? 0 ) );
    }
}
if (!function_exists('dcl_email_admin_listing_claim')) {
    /** @deprecated Notification intents now belong to the complete submission unit. */
    function dcl_email_admin_listing_claim() {
        return dcl_current_user();
    }
}

if (!function_exists('dcl_need_to_charge_with_plan')) {
    /**
     * Determine if a plan-based claim needs payment.
     *
     * Returns true if no payment is needed (user has active package),
     * false if payment/checkout is required.
     *
     * @return bool
     */
    function dcl_need_to_charge_with_plan()
    {
        try {
            $payment = DCL_Claim_Service::payment( absint( $_POST['post_id'] ?? 0 ), get_current_user_id(), absint( $_POST['plan_id'] ?? 0 ), absint( $_POST['order_id'] ?? 0 ) );
            return $payment['verified'];
        } catch ( Throwable $error ) { return false; }
    }
}
if (!function_exists('dcl_need_to_charge_without_plan')) {
    function dcl_need_to_charge_without_plan()
    {
        $manual_charge = need_claim_to_charge_manually();
        if (!empty($manual_charge)) {
            return false;
        } else {
            return true;
        }
    }
}


/**
 * @since 1.0.0
 */
if (!function_exists('need_claim_to_charge_manually')) {
    function need_claim_to_charge_manually()
    {
        $post_id = (int)$_POST["post_id"];
        $claim_charge_by = get_directorist_option('claim_charge_by');
        $charged_by = get_post_meta($post_id, '_claim_fee', true);
        $charged_by = ($charged_by !== '') ? $charged_by : $claim_charge_by;
        if ('static_fee' === $charged_by) {
            return true;
        } else {
            return false;
        }
    }
}
/**
 * @since 1.0.0
 */
if (!function_exists('non_paid_claim')) {
    function non_paid_claim()
    {
        $post_id = (int)$_POST["post_id"];
        $charged_by = get_post_meta($post_id, '_claim_fee', true);
        $charged_by = $charged_by ? $charged_by : get_directorist_option( 'claim_charge_by', 'free_claim' );
        if ('free_claim' === $charged_by) {
            return true;
        } else {
            return false;
        }
    }
}

/**
 * @since 1.0.0
 */
if (!function_exists('dcl_new_claim')) {
    function dcl_new_claim($listing_id)
    {
        return DCL_Base()->directorist_do_claim( $listing_id );
    }
}

/**
 * @since 1.0.0
 * check is user already submitted claim for this listing
 */

if (!function_exists('dcl_tract_duplicate_claim')) {
    function dcl_tract_duplicate_claim($claimer, $listing)
    {
        $claims = new WP_Query(array(
            'post_type' => 'dcl_claim_listing',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_listing_claimer',
                    'value' => $claimer,
                ),
                array(
                    'key' => '_claimed_listing',
                    'value' => $listing,
                ),
                array(
                    'key' => '_claim_status',
                    'value' => 'pending',
                )
            )
        ));
        $claim_meta = array();
        foreach ($claims->posts as $key => $val) {
            $claim_meta[] = !empty($val) ? $val : array();
        }
        return ($claim_meta) ? $claim_meta : false;
    }
}

/**
 * Render the claim listing widget + modal for a given listing.
 *
 * Handles all checks (global setting, admin-only claimable, already claimed),
 * renders the claim card and modal form with pricing plan selection, and
 * returns the complete HTML. Returns empty string when claim should not show.
 *
 * @param int   $listing_id The listing post ID.
 * @param array $args       Optional overrides:
 *                           - claim_title, claim_description, claim_button_text: text overrides.
 *                           - icon: Card header icon class (renders card header when set).
 *                           - label: Card header label text (renders card header when set).
 *                           - custom_block_classes: Extra CSS classes on the wrapper.
 * @return string Rendered HTML or empty string.
 */
function directorist_render_claim_listing( int $listing_id, array $args = [] ): string {
	if ( ! get_directorist_option( 'enable_claim_listing', 1 ) ) {
		return '';
	}

	if ( ! function_exists( 'directorist_is_listing_created_by_admin' ) ) {
		return '';
	}

	$admin_only_claimable = get_directorist_option( 'admin_only_claimable', false );
	if ( ! empty( $admin_only_claimable ) && ! directorist_is_listing_created_by_admin( $listing_id ) ) {
		return '';
	}

	$claimed_by_admin = get_post_meta( $listing_id, '_claimed_by_admin', true );
	$claim_fee        = get_post_meta( $listing_id, '_claim_fee', true );
	if ( $claimed_by_admin || 'claim_approved' === $claim_fee ) {
		return '';
	}

	$defaults = [
		'claim_title'       => get_directorist_option( 'claim_widget_title', __( 'Is this your business?', 'directorist-claim-listing' ) ),
		'claim_description' => get_directorist_option( 'claim_widget_description', __( 'Claim listing is the best way to manage and protect your business.', 'directorist-claim-listing' ) ),
		'claim_button_text' => get_directorist_option( 'claim_now', __( 'Claim Now!', 'directorist-claim-listing' ) ),
	];
	$args = wp_parse_args( $args, $defaults );

	global $post;
	$original_post = $post;
	$post          = get_post( $listing_id );
	setup_postdata( $post );

	$wrapper_classes = 'directorist-claim-listing-wrapper';
	if ( ! empty( $args['custom_block_classes'] ) ) {
		$wrapper_classes .= ' ' . esc_attr( $args['custom_block_classes'] );
	}
	$has_header = ! empty( $args['icon'] ) || ! empty( $args['label'] );

	ob_start();
	?>
	<div class="<?php echo $wrapper_classes; ?>">
		<div class="directorist-card directorist-claim-listing">
			<?php if ( $has_header ) : ?>
				<div class="directorist-card__header">
					<h3 class="directorist-card__header__title">
						<?php if ( ! empty( $args['icon'] ) ) : ?>
							<span class="directorist-card__header-icon"><?php directorist_icon( $args['icon'] ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $args['label'] ) ) : ?>
							<span class="directorist-card__header-text"><?php echo esc_html( $args['label'] ); ?></span>
						<?php endif; ?>
					</h3>
				</div>
			<?php endif; ?>
			<div class="directorist-card__body">
				<h4 class="directorist-claim-listing__title"><?php echo esc_html( $args['claim_title'] ); ?></h4>
				<p class="directorist-claim-listing__description"><?php echo esc_html( $args['claim_description'] ); ?></p>
				<?php if ( is_user_logged_in() ) : ?>
					<a href="#" class="directorist-btn directorist-btn-modal directorist-btn-modal-js" data-directorist_target="directorist-claim-listing-modal"><?php echo esc_html( $args['claim_button_text'] ); ?></a>
				<?php else : ?>
					<a href="#" class="directorist-claim-listing__login-alert directorist-btn directorist-btn-modal directorist-btn-modal-js"><?php echo esc_html( $args['claim_button_text'] ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<input type="hidden" id="directorist__post-id" value="<?php echo esc_attr( $listing_id ); ?>" />
	</div>
	<div class="directorist-modal directorist-modal-js directorist-fade directorist-claim-listing-modal directorist-claimer">
		<div class="directorist-modal__dialog directorist-modal__dialog-lg">
			<div class="directorist-modal__content">
				<form id="directorist-claimer__form" class="directorist-claimer__form">
					<div class="directorist-modal__header">
						<h3 class="directorist-modal-title" id="directorist-claim-label"><?php _e( 'Claim This Listing', 'directorist-claim-listing' ); ?></h3>
						<a href="#" class="directorist-modal-close directorist-modal-close-js"><span aria-hidden="true">&times;</span></a>
					</div>
					<div class="directorist-modal__body">
						<div class="directorist-form-group">
							<label for="directorist-claimer__name" class="directorist-claimer__name"><?php _e( 'Full Name', 'directorist-claim-listing' ); ?> <span class="directorist-claimer__star-red">*</span></label>
							<input type="text" class="directorist-form-element" id="directorist-claimer__name" name="claimer_name" autocomplete="name" placeholder="<?php _e( 'Full Name', 'directorist-claim-listing' ); ?>" required>
						</div>
						<div class="directorist-form-group">
							<label for="directorist-claimer__phone" class="directorist-claimer__phone"><?php _e( 'Phone', 'directorist-claim-listing' ); ?> <span class="directorist-claimer__star-red">*</span></label>
							<input type="tel" class="directorist-form-element" id="directorist-claimer__phone" name="claimer_phone" autocomplete="tel" placeholder="<?php _e( '111-111-235', 'directorist-claim-listing' ); ?>" required>
						</div>
						<div class="directorist-form-group">
							<label for="directorist-claimer__details" class="directorist-claimer__details"><?php _e( 'Verification Details', 'directorist-claim-listing' ); ?> <span class="directorist-claimer__star-red">*</span></label>
							<textarea class="directorist-form-element" id="directorist-claimer__details" name="claimer_details" autocomplete="off" rows="3" placeholder="<?php _e( 'Details description about your business', 'directorist-claim-listing' ); ?>..." required></textarea>
						</div>
						<div class="directorist-form-group">
							<?php
							$claim_charge_by = get_directorist_option( 'claim_charge_by' );
							$charged_by      = get_post_meta( $listing_id, '_claim_fee', true );
							$directory_type  = get_post_meta( $listing_id, '_directory_type', true );
							$charged_by      = ( '' !== $charged_by ) ? $charged_by : $claim_charge_by;
							$has_plans       = is_pricing_plans_active();

							if ( ! empty( $has_plans ) && 'pricing_plan' === $charged_by ) {
								$metas = [];
								$metas['exclude'] = [
									'relation' => 'OR',
									[ 'key' => '_hide_from_plans', 'compare' => 'NOT EXISTS' ],
									[ 'key' => '_hide_from_plans', 'value' => 'yes', 'compare' => '!=' ],
								];
								if ( ! empty( $directory_type ) ) {
									$metas['directory'] = [
										'key'     => '_assign_to_directory',
										'value'   => $directory_type,
										'compare' => '=',
									];
								}

								if ( class_exists( 'ATBDP_Pricing_Plans' ) ) {
									$query_args = [
										'post_type'      => 'atbdp_pricing_plans',
										'posts_per_page' => -1,
										'status'         => 'publish',
										'meta_query'     => array_merge( [ 'relation' => 'AND' ], $metas ),
									];
								} else {
									$wc_metas            = $metas;
									$wc_metas['exclude'] = [
										'relation' => 'OR',
										[ 'key' => '_hide_from_plans', 'compare' => 'NOT EXISTS' ],
										[ 'key' => '_hide_from_plans', 'value' => 1, 'compare' => '!=' ],
									];
									$query_args = [
										'post_type'  => 'product',
										'tax_query'  => [ [ 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'listing_pricing_plans' ] ],
										'meta_query' => array_merge( [ 'relation' => 'AND' ], $wc_metas ),
									];
								}

								$atbdp_query = new \WP_Query( $query_args );
								if ( $atbdp_query->have_posts() ) {
									$plans     = $atbdp_query->posts;
									$is_native = class_exists( 'ATBDP_Pricing_Plans' );
									printf( '<label for="select_plans">%s</label>', __( 'Select Plan', 'directorist-claim-listing' ) );
									printf( '<select id="directorist-claimer_plan">' );
									printf( '<option>%s</option>', __( 'Select Plan', 'directorist-claim-listing' ) );
									foreach ( $plans as $plan ) {
										$active_plan = subscribed_package_or_PPL_plans( get_current_user_id(), 'completed', $plan->ID );
										$plan_type   = get_post_meta( $plan->ID, 'plan_type', true );
										$is_active   = ! empty( $active_plan ) && 'package' === $plan_type;
										$class_attr  = $is_active ? ( $is_native ? 'class="directorist__active-plan"' : 'class="directorist__active-plan" selected' ) : '';
										$badge       = $is_active ? '<span class="atbd_badge">' . __( '- Active', 'directorist-claim-listing' ) . '</span>' : '';
										printf( '<option %s value="%s">%s %s</option>', $class_attr, esc_attr( $plan->ID ), esc_html( $plan->post_title ), $badge );
									}
									printf( '</select>' );
									?>
									<div id="directorist__plan-allowances" data-author_id="<?php echo get_current_user_id(); ?>"></div>
									<?php
									printf( '<a target="_blank" href="%s" class="directorist__plans">%s</a>', esc_url( \ATBDP_Permalink::get_fee_plan_page_link() ), __( 'Show plan details', 'directorist-claim-listing' ) );
								}
							}
							?>
						</div>
						<div id="directorist-claimer__submit-notification"></div>
						<div id="directorist-claimer__warning-notification"></div>
					</div>
					<div class="directorist-modal__footer">
						<button type="submit" class="directorist-btn"><?php esc_html_e( 'Submit', 'directorist-claim-listing' ); ?></button>
						<span><?php directorist_icon( 'fas fa-lock' ); ?><?php esc_html_e( 'Secure Claim Process', 'directorist-claim-listing' ); ?></span>
					</div>
				</form>
			</div>
		</div>
	</div>
	<?php
	$html = ob_get_clean();

	$post = $original_post;
	$original_post ? setup_postdata( $original_post ) : wp_reset_postdata();

	return $html;
}

// dcl_get_template
function dcl_get_template( $template_file, $args = array() ) {
    if ( is_array( $args ) ) {
        extract( $args );
    }

    $theme_template  = '/directorist-claim-listing/' . $template_file . '.php';
    $plugin_template = DCL_TEMPLATES_DIR . $template_file . '.php';

    if ( file_exists( get_stylesheet_directory() . $theme_template ) ) {
        $file = get_stylesheet_directory() . $theme_template;
    } elseif ( file_exists( get_template_directory() . $theme_template ) ) {
        $file = get_template_directory() . $theme_template;
    } else {
        $file = $plugin_template;
    }


    if ( file_exists( $file ) ) {
        include $file;
    }
}

/**
 * Check if the given nonce field contains a verified nonce.
 *
 * @since 7.0.6.2
 *
 * @return boolen
 */
function directorist_claim_verify_nonce( $nonce_field = 'nonce' ) {
    $nonce = ! empty( $_REQUEST[ $nonce_field ] ) ? $_REQUEST[ $nonce_field ] : '';
    return wp_verify_nonce( $nonce, 'directorist_claim_nonce' );
}

/**
 * Check if the given nonce field contains a verified nonce.
 *
 * @since 7.0.6.2
 *
 * @return boolean
 */
function directorist_is_claimable_with_plan() {
    if ( ! is_pricing_plans_active() ) {
        return false;
    }

    $plans = directorist_pricing_plan_repository()
        ->get_query_builder()
        ->where( 'is_published', 1 )
        ->limit( 1 )
        ->get();

    return ! empty( $plans );
}

function directorist_is_listing_created_by_admin( $listing_id ) {
    $author_id = get_post_field( 'post_author', $listing_id );

    if ( ! $author_id ) {
        return false;
    }

    $author = get_user_by( 'id', $author_id );

    if ( !$author || empty( $author->roles ) ) {
        return false;
    }

    return in_array('administrator', (array) $author->roles);
}
