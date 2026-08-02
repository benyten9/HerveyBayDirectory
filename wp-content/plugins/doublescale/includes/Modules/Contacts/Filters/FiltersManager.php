<?php

/**
 * Class FiltersManager
 *
 * This class is responsible for handling the filters
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Filters;

defined( 'ABSPATH' ) || exit;

use Exception;
use DoubleScale\Modules\Contacts\Abstracts\Filter;

/**
 * Filters class
 */
final class FiltersManager {


	/**
	 * Registed filters
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $filters = array();

	/**
	 * Groups
	 *
	 * @var array
	 */
	protected $groups = array();

	/**
	 * @var FiltersManager|null
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * The DI container is registered to call this method. Do not resolve the
	 * same FQCN from within here or the container will recurse until the
	 * process runs out of memory.
	 *
	 * @since 1.0.0
	 *
	 * @return FiltersManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->set_groups();
	}

	/**
	 * Set groups
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function set_groups() {
		$this->groups = array(
			'contact'        => array(
				'name'    => __( 'Contact', 'doublescale' ),
				'filters' => array(),
			),
			'lead_scoring'   => array(
				'name'    => __( 'Lead Scoring', 'doublescale' ),
				'filters' => array(),
			),
			'contact_fields' => array(
				'name'    => __( 'Contact Fields', 'doublescale' ),
				'filters' => array(),
			),
			'segments'       => array(
				'name'    => __( 'Segments', 'doublescale' ),
				'filters' => array(),
			),
			'user'           => array(
				'name'    => __( 'User', 'doublescale' ),
				'filters' => array(),
			),
			'activity'       => array(
				'name'    => __( 'Activity', 'doublescale' ),
				'filters' => array(),
			),
			'submission'     => array(
				'name'    => __( 'Submission', 'doublescale' ),
				'filters' => array(),
			),
		);
	}

	/**
	 * Register filter
	 *
	 * @since 1.0.0
	 *
	 * @param Filter $filter
	 *
	 * @throws Exception If trigger is not an instance of Trigger
	 * @return void
	 */
	public function register( Filter $filter ) {
		if ( ! $filter instanceof Filter ) {
			throw new Exception( 'Filter must be an instance of Filter' );
		}

		if ( isset( $this->filters[ $filter->slug ] ) ) {
			throw new Exception( 'Filter already exists' );
		}

		$this->filters[ $filter->slug ]                             = $filter;
		$this->groups[ $filter->group ]['filters'][ $filter->slug ] = array(
			'name'         => $filter->name,
			'type'         => $filter->type,
			'operators'    => $filter->get_operators(),
			'options'      => $filter->get_options(),
			'is_dynamic'   => $filter->is_dynamic,
			'dynamic_args' => $filter->dynamic_args,
		);
	}

	/**
	 * Get filters
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_filters() {
		return $this->filters;
	}

	/**
	 * Get filter
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug
	 *
	 * @return Filter
	 */
	public function get_filter( $slug ) {
		if ( isset( $this->filters[ $slug ] ) ) {
			return $this->filters[ $slug ];
		}

		return null;
	}

	/**
	 * Get groups
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_groups() {
		$groups = $this->groups;

		if ( function_exists( 'doublescale_filter_contact_filters_groups_for_modules' ) ) {
			$groups = doublescale_filter_contact_filters_groups_for_modules( $groups );
		}

		return apply_filters( 'doublescale_contact_filters_groups', $groups );
	}

	/**
	 * Get groups by slugs
	 *
	 * @since 1.0.0
	 *
	 * @param array $slugs
	 *
	 * @return array
	 */
	public function get_groups_by_slugs( $slugs ) {
		$groups = array();
		$all    = $this->get_groups();

		foreach ( $slugs as $slug ) {
			if ( isset( $all[ $slug ] ) ) {
				$groups[ $slug ] = $all[ $slug ];
			}
		}

		return $groups;
	}
}
