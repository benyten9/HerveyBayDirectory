<?php

namespace HBL\Widgets\V2\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Query_Handler {

	protected function get_listings_query( $settings, $paged = 1, $letter = '' ) {
		$listings_per_page = isset( $settings['listings_per_page'] ) ? absint( $settings['listings_per_page'] ) : 20;
		$total_listings = isset( $settings['number_of_listings'] ) ? intval( $settings['number_of_listings'] ) : -1;
		$enable_pagination = isset( $settings['enable_pagination'] ) && 'yes' === $settings['enable_pagination'];
		
		if ( $enable_pagination ) {
			$query_per_page = $listings_per_page;
		} else {
			$query_per_page = ( $total_listings > 0 ) ? $total_listings : -1;
		}

		$orderby = isset( $settings['orderby'] ) ? $settings['orderby'] : 'date';
		$order = isset( $settings['order'] ) ? $settings['order'] : 'DESC';

		if ( 'latest' === $orderby ) {
			$orderby = 'date';
			$order = 'DESC';
		}

		if ( 'last' === $orderby ) {
			$orderby = 'date';
			$order = 'ASC';
		}

		$args = array(
			'post_type'      => 'at_biz_dir',
			'posts_per_page' => $query_per_page,
			'paged'          => $enable_pagination ? $paged : 1,
			'orderby'        => $orderby,
			'order'          => $order,
			'post_status'    => 'publish',
		);

		$args['meta_query'] = array();

		if ( isset( $settings['featured_only'] ) && 'yes' === $settings['featured_only'] ) {
			$args['meta_query'][] = array(
				'key'     => '_featured',
				'value'   => '1',
				'compare' => '=',
			);
		}

		if ( isset( $settings['pricing_plans_filter_type'] ) && 'specific' === $settings['pricing_plans_filter_type'] ) {
			if ( ! empty( $settings['pricing_plans'] ) ) {
				$filter_plan_ids = is_array( $settings['pricing_plans'] ) ? $settings['pricing_plans'] : array( $settings['pricing_plans'] );
				$filter_plan_ids = array_map( 'absint', $filter_plan_ids );
				$filter_plan_ids = array_filter( $filter_plan_ids );

				if ( ! empty( $filter_plan_ids ) ) {
					$args['meta_query'][] = array(
						'key'     => '_fm_plans',
						'value'   => $filter_plan_ids,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					);
				}
			}
		}

		if ( count( $args['meta_query'] ) > 1 ) {
			$args['meta_query']['relation'] = 'AND';
		}

		$args['tax_query'] = array();

		if ( isset( $settings['category_filter_type'] ) && 'specific' === $settings['category_filter_type'] ) {
			if ( ! empty( $settings['category'] ) ) {
				$args['tax_query'][] = array(
					'taxonomy'         => 'at_biz_dir-category',
					'field'            => 'term_id',
					'terms'            => $settings['category'],
					'include_children' => true,
				);
			}
		}

		if ( isset( $settings['location_filter_type'] ) && 'specific' === $settings['location_filter_type'] ) {
			if ( ! empty( $settings['location'] ) ) {
				$args['tax_query'][] = array(
					'taxonomy'         => 'at_biz_dir-location',
					'field'            => 'term_id',
					'terms'            => $settings['location'],
					'include_children' => true,
				);
			}
		}

		if ( count( $args['tax_query'] ) > 1 ) {
			$args['tax_query']['relation'] = 'AND';
		}

		if ( ! empty( $settings['tag'] ) ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'at_biz_dir-tags',
				'field'    => 'term_id',
				'terms'    => $settings['tag'],
			);
		}

		if ( ! empty( $letter ) && preg_match( '/^[A-Z]$/', $letter ) ) {
			$GLOBALS['hbl_v2_letter_filter'] = $letter;
			add_filter( 'posts_where', array( $this, 'filter_posts_by_letter' ), 10, 2 );
		}

		$query = new \WP_Query( $args );

		if ( ! empty( $letter ) ) {
			remove_filter( 'posts_where', array( $this, 'filter_posts_by_letter' ), 10 );
			unset( $GLOBALS['hbl_v2_letter_filter'] );
		}

		return $query;
	}

	public function filter_posts_by_letter( $where, $query ) {
		global $wpdb;

		if ( ! isset( $GLOBALS['hbl_v2_letter_filter'] ) ) {
			return $where;
		}

		$letter = $GLOBALS['hbl_v2_letter_filter'];

		if ( ! empty( $letter ) && preg_match( '/^[A-Z]$/', $letter ) ) {
			$where .= $wpdb->prepare(
				" AND {$wpdb->posts}.post_title LIKE %s",
				$letter . '%'
			);
		}

		return $where;
	}

	protected function get_listing_categories() {
		$categories = get_terms( array(
			'taxonomy'   => 'at_biz_dir-category',
			'hide_empty' => false,
		) );

		$options = array();
		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			foreach ( $categories as $category ) {
				$options[ $category->term_id ] = $category->name;
			}
		}

		return $options;
	}

	protected function get_listing_locations() {
		$locations = get_terms( array(
			'taxonomy'   => 'at_biz_dir-location',
			'hide_empty' => false,
		) );

		$options = array();
		if ( ! is_wp_error( $locations ) && ! empty( $locations ) ) {
			foreach ( $locations as $location ) {
				$options[ $location->term_id ] = $location->name;
			}
		}

		return $options;
	}

	protected function get_pricing_plans() {
		$plans = get_posts( array(
			'post_type'      => 'atbdp_pricing_plans',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$options = array();
		if ( ! empty( $plans ) ) {
			foreach ( $plans as $plan ) {
				$options[ $plan->ID ] = $plan->post_title;
			}
		}

		return $options;
	}

	protected function get_plan_tier( $plan_id, $settings ) {
		$gold_plan_ids = isset( $settings['gold_plan_ids'] ) ? (array) $settings['gold_plan_ids'] : array();
		$silver_plan_ids = isset( $settings['silver_plan_ids'] ) ? (array) $settings['silver_plan_ids'] : array();

		$gold_plan_ids = array_map( 'absint', $gold_plan_ids );
		$silver_plan_ids = array_map( 'absint', $silver_plan_ids );

		if ( in_array( $plan_id, $gold_plan_ids, true ) ) {
			return 'gold';
		} elseif ( in_array( $plan_id, $silver_plan_ids, true ) ) {
			return 'silver';
		}

		return 'bronze';
	}
	protected function get_listing_tags() {
		$tags = get_terms( array(
			'taxonomy'   => 'at_biz_dir-tags',
			'hide_empty' => false,
		) );

		$options = array();
		if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
			foreach ( $tags as $tag ) {
				$options[ $tag->term_id ] = $tag->name;
			}
		}

		return $options;
	}
}
