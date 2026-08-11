<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Pricing_Plans {

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

	public static function get_plans( $args = array() ) {
		$args              = wp_parse_args( $args, array( 'with_restrictions' => false ) );
		$with_restrictions = (bool) $args['with_restrictions'];

		if ( self::supports_v4() ) {
			$plans = self::get_plans_v4( $with_restrictions );
		} else {
			$plans = self::get_plans_legacy( $with_restrictions );
		}

		self::sort_plans( $plans );

		return apply_filters( 'hbl_pricing_plans', $plans, $with_restrictions );
	}

	public static function get_plan( $plan_id ) {
		$plan_id = absint( $plan_id );

		if ( ! $plan_id ) {
			return null;
		}

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

	protected static function compute_tax( $price, $tax_type, $tax_rate ) {
		if ( ! $tax_type || $tax_rate <= 0 || $price <= 0 ) {
			return 0.0;
		}

		if ( function_exists( 'directorist_compute_fixed_or_percent_amount' ) ) {
			return (float) directorist_compute_fixed_or_percent_amount( $tax_type, $tax_rate, $price );
		}

		return 'percent' === $tax_type ? round( ( $price * $tax_rate ) / 100, 2 ) : round( $tax_rate, 2 );
	}

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

	protected static function supports_v4() {
		return function_exists( 'directorist_pricing_plan_repository' )
			&& function_exists( 'default_directory_type' );
	}

	protected static function get_plans_v4( $with_restrictions ) {
		$plans        = array();
		$directory_id = (int) default_directory_type();

		if ( ! $directory_id ) {
			return $plans;
		}

		try {
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

	protected static function build_v4_restrictions( $plan ) {
		$features = self::get_feature_map( $plan );

		$is_enabled = static function ( $restriction_key ) use ( $features ) {
			$feature_key = isset( self::FEATURE_KEY_MAP[ $restriction_key ] )
				? self::FEATURE_KEY_MAP[ $restriction_key ]
				: $restriction_key;

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
			'gallery'              => $is_enabled( 'gallery' ),
			'max_images'           => $limit( 'listing_img' ),
			'unlimited_images'     => $is_unlimited( 'listing_img' ),
			'video'                => $is_enabled( 'video' ),

			'phone'                => $is_enabled( 'phone' ),
			'email'                => $is_enabled( 'email' ),
			'website'              => $is_enabled( 'website' ),
			'social_networks'      => $is_enabled( 'social_networks' ),

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

			'reviews'              => $is_enabled( 'reviews' ),
			'faqs'                 => $is_enabled( 'faqs' ),
			'business_hours'       => $is_enabled( 'business_hours' ),
		);
	}

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
