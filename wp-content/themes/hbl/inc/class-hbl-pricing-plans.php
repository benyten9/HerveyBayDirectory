<?php
/**
 * HBL Pricing Plans data provider.
 *
 * Central abstraction over Directorist Pricing Plans. Directorist Pricing
 * Plans 4.0 moved plans out of the `atbdp_pricing_plans` custom post type and
 * into a dedicated database table exposed through
 * `directorist_pricing_plan_repository()`. Because our widgets used to query
 * the old post type directly, the upgrade made every plan disappear.
 *
 * All coupling to Directorist internals lives here so the Add Listing and
 * Claim Listing widgets never touch Directorist storage directly. The provider
 * prefers the modern 4.0+ API and transparently falls back to the legacy
 * post-type query for older installs, so a future Directorist change only ever
 * needs to be handled in this one file.
 *
 * @package HBL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Pricing_Plans {

	/**
	 * Maps our internal restriction keys to the Directorist 4.0 submission
	 * form "feature" keys. Features are ENABLED by default in Directorist 4.0
	 * (a plan only restricts a field when the feature exists AND is explicitly
	 * disabled), so unknown/absent keys always resolve to "allowed".
	 *
	 * @var array<string,string>
	 */
	const FEATURE_KEY_MAP = array(
		'phone'           => 'phone',
		'email'           => 'email',
		'website'         => 'website',
		'gallery'         => 'listing_img',
		'video'           => 'videourl',
		'address'         => 'address',
		'location'        => 'tax_input[at_biz_dir-location][]',
		'social_networks' => 'social',
		'map'             => 'map',
		'tags'            => 'tax_input[at_biz_dir-tags][]',
		'category'        => 'admin_category_select[]',
		'price_field'     => 'price_range',
		'description'     => 'listing_content',
		'tagline'         => 'tagline',
		'reviews'         => 'review',
		'faqs'            => 'faq',
		'business_hours'  => 'bdbh',
	);

	/**
	 * Get the normalised list of pricing plans for the widgets.
	 *
	 * Returns an ordered array of plans. Each plan is:
	 *   id             (int)
	 *   title          (string)
	 *   price          (float)  Pre-tax price.
	 *   is_free        (bool)
	 *   type           (string)
	 *   description    (string)
	 *   recommended    (bool)
	 *   tax_rate       (float)  Rate (percent) or amount (flat); 0 when untaxed.
	 *   tax_type       (string) 'percent' | 'flat' | ''.
	 *   tax_amount     (float)  Computed tax for `price`, via Directorist's own calculator.
	 *   price_with_tax (float)  price + tax_amount.
	 *   restrictions   (array)  // only when $args['with_restrictions'] is true
	 *
	 * @param array $args {
	 *     @type bool $with_restrictions Whether to compute per-plan field restrictions. Default false.
	 * }
	 * @return array
	 */
	public static function get_plans( $args = array() ) {
		$args              = wp_parse_args( $args, array( 'with_restrictions' => false ) );
		$with_restrictions = (bool) $args['with_restrictions'];

		if ( self::supports_v4() ) {
			$plans = self::get_plans_v4( $with_restrictions );
		} else {
			$plans = self::get_plans_legacy( $with_restrictions );
		}

		self::sort_plans( $plans );

		/**
		 * Filter the normalised HBL pricing plans before they are rendered.
		 *
		 * @param array $plans             Normalised plans.
		 * @param bool  $with_restrictions Whether restrictions were requested.
		 */
		return apply_filters( 'hbl_pricing_plans', $plans, $with_restrictions );
	}

	/**
	 * Get a single normalised plan by ID.
	 *
	 * Accepts either a modern 4.0+ plan ID or a legacy post ID (legacy IDs are
	 * transparently mapped to their migrated equivalent). Returns null when the
	 * plan cannot be found.
	 *
	 * @param int $plan_id Plan ID.
	 * @return array{id:int,title:string,price:float,is_free:bool,tax_rate:float,tax_type:string,tax_amount:float,price_with_tax:float}|null
	 */
	public static function get_plan( $plan_id ) {
		$plan_id = absint( $plan_id );

		if ( ! $plan_id ) {
			return null;
		}

		// Modern Directorist Pricing Plans 4.0+.
		if ( function_exists( 'directorist_get_pricing_plan_by_id' ) ) {
			$resolved_id = function_exists( 'directorist_pricing_plans_legacy_plan_id' )
				? (int) directorist_pricing_plans_legacy_plan_id( $plan_id )
				: $plan_id;

			try {
				$plan = directorist_get_pricing_plan_by_id( $resolved_id );
			} catch ( \Throwable $e ) {
				$plan = null;
			}

			if ( $plan && ! empty( $plan->id ) ) {
				$price    = isset( $plan->price ) ? (float) $plan->price : 0.0;
				$tax_type = isset( $plan->tax_type ) ? (string) $plan->tax_type : '';
				$tax_rate = isset( $plan->tax_rate ) ? (float) $plan->tax_rate : 0.0;
				$tax      = self::compute_tax( $price, $tax_type, $tax_rate );

				return array(
					'id'             => (int) $plan->id,
					'title'          => isset( $plan->title ) ? (string) $plan->title : '',
					'price'          => $price,
					'is_free'        => isset( $plan->fee_type ) && 'free' === $plan->fee_type,
					'tax_rate'       => $tax_rate,
					'tax_type'       => $tax_type,
					'tax_amount'     => $tax,
					'price_with_tax' => round( $price + $tax, 2 ),
				);
			}
		}

		// Legacy post-type fallback.
		$post = get_post( $plan_id );
		if ( $post && 'atbdp_pricing_plans' === $post->post_type ) {
			$is_free    = get_post_meta( $plan_id, 'free_plan', true );
			$price      = $is_free ? 0.0 : floatval( get_post_meta( $plan_id, 'fm_price', true ) );
			$is_taxable = (bool) get_post_meta( $plan_id, 'plan_tax', true );
			$tax_type   = (string) get_post_meta( $plan_id, 'plan_tax_type', true );
			$tax_rate   = floatval( get_post_meta( $plan_id, 'fm_tax', true ) );
			$tax        = $is_taxable ? self::compute_tax( $price, $tax_type, $tax_rate ) : 0.0;

			return array(
				'id'             => (int) $plan_id,
				'title'          => $post->post_title,
				'price'          => $price,
				'is_free'        => (bool) $is_free,
				'tax_rate'       => $tax_rate,
				'tax_type'       => $tax_type,
				'tax_amount'     => $tax,
				'price_with_tax' => round( $price + $tax, 2 ),
			);
		}

		return null;
	}

	/**
	 * Computes a plan's tax amount using Directorist core's own fixed/percent
	 * calculator (the same function Directorist uses for its own order totals),
	 * so tax here always matches what Directorist itself would charge — never a
	 * rate this theme assumes or hardcodes independently.
	 *
	 * @param float       $price    Pre-tax price.
	 * @param string|null $tax_type 'flat' | 'percent' | '' (no tax).
	 * @param float       $tax_rate Rate (percent) or amount (flat).
	 * @return float
	 */
	protected static function compute_tax( $price, $tax_type, $tax_rate ) {
		if ( ! $tax_type || $tax_rate <= 0 || $price <= 0 ) {
			return 0.0;
		}

		if ( function_exists( 'directorist_compute_fixed_or_percent_amount' ) ) {
			return (float) directorist_compute_fixed_or_percent_amount( $tax_type, $tax_rate, $price );
		}

		// Minimal inline fallback matching Directorist core's own semantics,
		// only used if Directorist core is somehow unavailable.
		return 'percent' === $tax_type ? round( ( $price * $tax_rate ) / 100, 2 ) : round( $tax_rate, 2 );
	}

	/**
	 * The plan ID currently assigned to a listing.
	 *
	 * On Directorist Pricing Plans 4.0+ the assignment lives in the packages
	 * table (directorist_get_listing_package), NOT the legacy `_fm_plans` post
	 * meta — reading `_fm_plans` on a v4 install returns a stale/incorrect value,
	 * which is why tier lookups were wrong. Falls back to `_fm_plans` for legacy
	 * installs or listings not yet migrated.
	 *
	 * @param int $listing_id
	 * @return int Plan ID, or 0 when none is assigned.
	 */
	public static function get_listing_plan_id( $listing_id ) {
		$listing_id = absint( $listing_id );
		if ( ! $listing_id ) {
			return 0;
		}

		if ( function_exists( 'directorist_get_listing_package' ) ) {
			try {
				$package = directorist_get_listing_package( $listing_id );
			} catch ( \Throwable $e ) {
				$package = null;
			}
			if ( $package && ! empty( $package->plan_id ) ) {
				return (int) $package->plan_id;
			}
		}

		return (int) get_post_meta( $listing_id, '_fm_plans', true );
	}

	/**
	 * Whether the modern Directorist Pricing Plans 4.0+ API is available.
	 *
	 * @return bool
	 */
	protected static function supports_v4() {
		return function_exists( 'directorist_pricing_plan_repository' )
			&& function_exists( 'default_directory_type' );
	}

	/**
	 * Fetch plans using the Directorist Pricing Plans 4.0+ repository API.
	 *
	 * @param bool $with_restrictions Whether to compute restrictions.
	 * @return array
	 */
	protected static function get_plans_v4( $with_restrictions ) {
		$plans        = array();
		$directory_id = (int) default_directory_type();

		if ( ! $directory_id ) {
			return $plans;
		}

		try {
			// Already filtered to published, non-hidden plans and ordered by sort order.
			$raw_plans = directorist_pricing_plan_repository()->get_by_directory_type( $directory_id );
		} catch ( \Throwable $e ) {
			return $plans;
		}

		if ( empty( $raw_plans ) || ! is_array( $raw_plans ) ) {
			return $plans;
		}

		foreach ( $raw_plans as $plan ) {
			if ( empty( $plan->id ) ) {
				continue;
			}

			$is_free  = isset( $plan->fee_type ) && 'free' === $plan->fee_type;
			$price    = isset( $plan->price ) ? (float) $plan->price : 0.0;
			$tax_type = isset( $plan->tax_type ) ? (string) $plan->tax_type : '';
			$tax_rate = isset( $plan->tax_rate ) ? (float) $plan->tax_rate : 0.0;
			$tax      = self::compute_tax( $price, $tax_type, $tax_rate );

			$normalised = array(
				'id'             => (int) $plan->id,
				'title'          => isset( $plan->title ) ? (string) $plan->title : '',
				'price'          => $price,
				'is_free'        => (bool) $is_free,
				'type'           => isset( $plan->type ) ? (string) $plan->type : 'package',
				'description'    => isset( $plan->description ) ? (string) $plan->description : '',
				'recommended'    => ! empty( $plan->is_marked_as_recommended ),
				'tax_rate'       => $tax_rate,
				'tax_type'       => $tax_type,
				'tax_amount'     => $tax,
				'price_with_tax' => round( $price + $tax, 2 ),
			);

			if ( $with_restrictions ) {
				$normalised['restrictions'] = self::build_v4_restrictions( $plan );
			}

			$plans[] = $normalised;
		}

		return $plans;
	}

	/**
	 * Build the restriction map for a 4.0+ plan from its enabled features.
	 *
	 * Directorist treats every feature as enabled unless a plan explicitly
	 * disables it, so any feature we cannot resolve defaults to "allowed".
	 *
	 * @param object $plan Raw plan row from the repository.
	 * @return array
	 */
	protected static function build_v4_restrictions( $plan ) {
		$features = self::get_feature_map( $plan );

		$is_enabled = static function ( $restriction_key ) use ( $features ) {
			$feature_key = isset( self::FEATURE_KEY_MAP[ $restriction_key ] )
				? self::FEATURE_KEY_MAP[ $restriction_key ]
				: $restriction_key;

			// Feature absent => enabled by default (Directorist 4.0 semantics).
			if ( ! isset( $features[ $feature_key ] ) ) {
				return true;
			}

			return (bool) $features[ $feature_key ]['is_enabled'];
		};

		$limit = static function ( $feature_key ) use ( $features ) {
			return isset( $features[ $feature_key ]['limit'] ) ? absint( $features[ $feature_key ]['limit'] ) : 0;
		};

		$is_unlimited = static function ( $feature_key ) use ( $features ) {
			return isset( $features[ $feature_key ]['is_unlimited'] ) ? (bool) $features[ $feature_key ]['is_unlimited'] : false;
		};

		return array(
			// Media.
			'gallery'              => $is_enabled( 'gallery' ),
			'max_images'           => $limit( 'listing_img' ),
			'unlimited_images'     => $is_unlimited( 'listing_img' ),
			'video'                => $is_enabled( 'video' ),

			// Contact.
			'phone'                => $is_enabled( 'phone' ),
			'email'                => $is_enabled( 'email' ),
			'website'              => $is_enabled( 'website' ),
			'social_networks'      => $is_enabled( 'social_networks' ),

			// Content.
			'category'             => $is_enabled( 'category' ),
			'max_categories'      => $limit( 'admin_category_select[]' ),
			'unlimited_categories' => $is_unlimited( 'admin_category_select[]' ),
			'tags'                 => $is_enabled( 'tags' ),
			'max_tags'             => $limit( 'tax_input[at_biz_dir-tags][]' ),
			'unlimited_tags'       => $is_unlimited( 'tax_input[at_biz_dir-tags][]' ),
			'price_field'          => $is_enabled( 'price_field' ),
			'description'          => $is_enabled( 'description' ),
			'tagline'              => $is_enabled( 'tagline' ),
			'address'              => $is_enabled( 'address' ),
			'map'                  => $is_enabled( 'map' ),
			'location'             => $is_enabled( 'location' ),

			// Features.
			'reviews'              => $is_enabled( 'reviews' ),
			'faqs'                 => $is_enabled( 'faqs' ),
			'business_hours'       => $is_enabled( 'business_hours' ),
		);
	}

	/**
	 * Resolve a plan's features into a `feature_key => data` lookup.
	 *
	 * Uses the Directorist 4.0 PlanFeatureRepository when available. If it
	 * cannot be reached the map is empty, which makes every field default to
	 * enabled — the safe outcome (nothing is hidden by mistake).
	 *
	 * @param object $plan Raw plan row.
	 * @return array<string,array{is_enabled:bool,limit:int,is_unlimited:bool}>
	 */
	protected static function get_feature_map( $plan ) {
		$map                = array();
		$repository_class   = '\DirectoristPricingPlan\App\Repositories\Admin\PlanFeatureRepository';

		if ( ! function_exists( 'directorist_pricing_plans_singleton' ) || ! class_exists( $repository_class ) ) {
			return $map;
		}

		try {
			$features = directorist_pricing_plans_singleton( $repository_class )->get( $plan );
		} catch ( \Throwable $e ) {
			return $map;
		}

		if ( empty( $features ) || ! is_array( $features ) ) {
			return $map;
		}

		foreach ( $features as $feature ) {
			if ( empty( $feature->key ) ) {
				continue;
			}

			$data = array();
			if ( isset( $feature->data ) ) {
				$data = is_array( $feature->data ) ? $feature->data : (array) $feature->data;
			}

			$map[ (string) $feature->key ] = array(
				'is_enabled'   => ! isset( $feature->is_enabled ) || (bool) $feature->is_enabled,
				'limit'        => isset( $data['limit'] ) ? absint( $data['limit'] ) : 0,
				'is_unlimited' => ! empty( $data['is_unlimited'] ),
			);
		}

		return $map;
	}

	/**
	 * Legacy fallback: query the deprecated `atbdp_pricing_plans` post type.
	 *
	 * Retained so the widgets keep working on Directorist Pricing Plans < 4.0.
	 *
	 * @param bool $with_restrictions Whether to compute restrictions.
	 * @return array
	 */
	protected static function get_plans_legacy( $with_restrictions ) {
		$plans = array();

		if ( ! post_type_exists( 'atbdp_pricing_plans' ) ) {
			return $plans;
		}

		$packages_query = new \WP_Query(
			array(
				'post_type'      => 'atbdp_pricing_plans',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => '_hide_from_plans',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_hide_from_plans',
						'value'   => 1,
						'compare' => '!=',
					),
				),
			)
		);

		if ( ! $packages_query->have_posts() ) {
			return $plans;
		}

		while ( $packages_query->have_posts() ) {
			$packages_query->the_post();
			$plan_id = get_the_ID();

			$plan_price = get_post_meta( $plan_id, 'fm_price', true );
			$is_free    = get_post_meta( $plan_id, 'free_plan', true );

			$final_price = ( ! $is_free && $plan_price ) ? floatval( $plan_price ) : 0.0;

			$is_taxable = (bool) get_post_meta( $plan_id, 'plan_tax', true );
			$tax_type   = (string) get_post_meta( $plan_id, 'plan_tax_type', true );
			$tax_rate   = floatval( get_post_meta( $plan_id, 'fm_tax', true ) );
			$tax        = $is_taxable ? self::compute_tax( $final_price, $tax_type, $tax_rate ) : 0.0;

			$normalised = array(
				'id'             => (int) $plan_id,
				'title'          => get_the_title(),
				'price'          => $final_price,
				'is_free'        => (bool) $is_free,
				'type'           => get_post_meta( $plan_id, 'plan_type', true ),
				'description'    => get_post_meta( $plan_id, 'fm_description', true ),
				'recommended'    => (bool) get_post_meta( $plan_id, 'default_pln', true ),
				'tax_rate'       => $tax_rate,
				'tax_type'       => $tax_type,
				'tax_amount'     => $tax,
				'price_with_tax' => round( $final_price + $tax, 2 ),
			);

			if ( $with_restrictions ) {
				$normalised['restrictions'] = self::build_legacy_restrictions( $plan_id );
			}

			$plans[] = $normalised;
		}
		wp_reset_postdata();

		return $plans;
	}

	/**
	 * Build the restriction map for a legacy (< 4.0) plan from its post meta.
	 *
	 * @param int $plan_id Legacy plan post ID.
	 * @return array
	 */
	protected static function build_legacy_restrictions( $plan_id ) {
		return array(
			'gallery'              => (bool) ( get_post_meta( $plan_id, '_listing_img', true ) || get_post_meta( $plan_id, 'fm_allow_slider', true ) ),
			'max_images'           => absint( get_post_meta( $plan_id, 'num_image', true ) ?: get_post_meta( $plan_id, '_max_listing_img', true ) ) ?: 0,
			'unlimited_images'     => (bool) ( get_post_meta( $plan_id, 'num_image_unl', true ) || get_post_meta( $plan_id, '_unlimited_listing_img', true ) ),
			'video'                => (bool) ( get_post_meta( $plan_id, '_videourl', true ) || get_post_meta( $plan_id, 'l_video', true ) ),

			'phone'                => (bool) ( get_post_meta( $plan_id, '_phone', true ) || get_post_meta( $plan_id, 'fm_phone', true ) ),
			'email'                => (bool) ( get_post_meta( $plan_id, '_email', true ) || get_post_meta( $plan_id, 'fm_email', true ) ),
			'website'              => (bool) ( get_post_meta( $plan_id, '_website', true ) || get_post_meta( $plan_id, 'fm_web_link', true ) ),
			'social_networks'      => (bool) ( get_post_meta( $plan_id, '_social', true ) || get_post_meta( $plan_id, 'fm_social_network', true ) ),

			'category'             => (bool) ( get_post_meta( $plan_id, '_category', true ) || get_post_meta( $plan_id, 'fm_allow_category', true ) ),
			'max_categories'      => absint( get_post_meta( $plan_id, 'fm_category_limit', true ) ?: get_post_meta( $plan_id, '_max_category', true ) ) ?: 1,
			'unlimited_categories' => (bool) get_post_meta( $plan_id, 'fm_category_limit_unl', true ),
			'tags'                 => (bool) ( get_post_meta( $plan_id, '_tag', true ) || get_post_meta( $plan_id, 'fm_allow_tag', true ) ),
			'max_tags'             => absint( get_post_meta( $plan_id, 'fm_tag_limit', true ) ?: get_post_meta( $plan_id, '_max_tag', true ) ) ?: 1,
			'unlimited_tags'       => (bool) get_post_meta( $plan_id, 'fm_tag_limit_unl', true ),
			'price_field'          => (bool) ( get_post_meta( $plan_id, '_pricing', true ) || get_post_meta( $plan_id, 'fm_allow_price', true ) ),
			'description'          => (bool) get_post_meta( $plan_id, '_listing_content', true ),
			'tagline'              => (bool) get_post_meta( $plan_id, '_tagline', true ),
			'address'              => (bool) get_post_meta( $plan_id, '_address', true ),
			'map'                  => (bool) get_post_meta( $plan_id, '_map', true ),
			'location'             => (bool) get_post_meta( $plan_id, '_location', true ),

			'reviews'              => (bool) get_post_meta( $plan_id, 'fm_cs_review', true ),
			'faqs'                 => (bool) get_post_meta( $plan_id, '_faqs', true ),
			'business_hours'       => (bool) ( get_post_meta( $plan_id, '_bdbh', true ) || get_post_meta( $plan_id, 'business_hrs', true ) ),
		);
	}

	/**
	 * Order plans by our marketing tiers: Bronze, Silver, Gold/Listing, then rest.
	 *
	 * @param array $plans Plans array, sorted in place.
	 * @return void
	 */
	protected static function sort_plans( &$plans ) {
		$package_order = array(
			'bronze'  => 1,
			'silver'  => 2,
			'gold'    => 3,
			'listing' => 3,
		);

		usort(
			$plans,
			static function ( $a, $b ) use ( $package_order ) {
				$title_a = strtolower( trim( $a['title'] ) );
				$title_b = strtolower( trim( $b['title'] ) );
				$order_a = isset( $package_order[ $title_a ] ) ? $package_order[ $title_a ] : 99;
				$order_b = isset( $package_order[ $title_b ] ) ? $package_order[ $title_b ] : 99;
				return $order_a - $order_b;
			}
		);
	}
}
