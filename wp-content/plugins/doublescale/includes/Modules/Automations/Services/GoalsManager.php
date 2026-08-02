<?php

/**
 * Class Goals Manager
 * This class is responsible for handling the goals
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Services;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Exception;
use DoubleScale\Modules\Automations\Abstracts\Goal;

/**
 * Goals class
 */
final class GoalsManager {


	/**
	 * Registed goals
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $goals = array();

	/**
	 * Sources
	 *
	 * @var array
	 */
	protected $sources = array();


	/**
	 * @var GoalsManager|null
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
	 * @return GoalsManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * constructor
	 */
	private function __construct() {
		$this->set_sources();
		add_action( 'doublescale_ready', array( $this, 'load_goals' ) );
	}

	/**
	 * Load goals
	 */
	public function load_goals() {
		/** @var Goal[] $goals */
		$goals = apply_filters( 'doublescale_automation_goals', $this->goals );

		foreach ( $goals as $slug => $goal ) {
			$this->goals[ $slug ] = $goal;

			// Get triggers and is_disabled from the source group if they exist
			$triggers = isset( $this->sources[ $goal->source ]['groups'][ $goal->group ]['triggers'] )
				? $this->sources[ $goal->source ]['groups'][ $goal->group ]['triggers']
				: array();

			$is_disabled = isset( $this->sources[ $goal->source ]['groups'][ $goal->group ]['is_disabled'] )
				? $this->sources[ $goal->source ]['groups'][ $goal->group ]['is_disabled']
				: false;

			// Update the sources array with the (potentially updated) goal's fields
			$this->sources[ $goal->source ]['groups'][ $goal->group ]['goals'][ $goal->slug ] = array(
				'label'       => $goal->name,
				'description' => $goal->description,
				'fields'      => $goal->get_fields(),
				'triggers'    => $triggers,
				'is_disabled' => $is_disabled,
				'is_pro'      => $goal->is_pro,
			);
			if ( ! $goal->is_pro ) {
				$goal->load_hooks();
			}
		}
	}

	/**
	 * Register Goal
	 *
	 * @since 1.0.0
	 *
	 * @param Goal $goal
	 * @return void
	 */
	public function register( Goal $goal ) {
		if ( ! $goal instanceof Goal ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new Exception( esc_html__( 'Invalid goal', 'doublescale' ) );
		}

		if ( isset( $this->goals[ $goal->slug ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			/* translators: %s: goal name */
			throw new Exception( sprintf( esc_html__( 'Goal %s already registered', 'doublescale' ), esc_html( $goal->name ) ) );
		}

		$this->goals[ $goal->slug ] = $goal;

		// Get triggers and is_disabled from the source group if they exist
		$triggers = isset( $this->sources[ $goal->source ]['groups'][ $goal->group ]['triggers'] )
			? $this->sources[ $goal->source ]['groups'][ $goal->group ]['triggers']
			: array();

		$is_disabled = isset( $this->sources[ $goal->source ]['groups'][ $goal->group ]['is_disabled'] )
			? $this->sources[ $goal->source ]['groups'][ $goal->group ]['is_disabled']
			: false;

		$this->sources[ $goal->source ]['groups'][ $goal->group ]['goals'][ $goal->slug ] = array(
			'label'       => $goal->name,
			'description' => $goal->description,
			'fields'      => $goal->get_fields(),
			'triggers'    => $triggers,
			'is_disabled' => $is_disabled,
			'is_pro'      => $goal->is_pro,
		);
	}

	/**
	 * Get Goal
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug
	 * @return Goal
	 */
	public function get_goal( $slug ) {
		if ( isset( $this->goals[ $slug ] ) ) {
			return $this->goals[ $slug ];
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
		/* translators: %s: goal slug */
		throw new Exception( sprintf( esc_html__( 'Goal %s not found', 'doublescale' ), esc_html( $slug ) ) );
	}

	/**
	 * Get Goals
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_goals() {
		return $this->goals;
	}

	/**
	 * Get sources
	 *
	 * @return array
	 */
	public function set_sources() {
		$this->sources = array(
			'automation'  => array(
				'label'  => __( 'Automation', 'doublescale' ),
				'groups' => array(
					'contact' => array(
						'label' => __( 'Contact', 'doublescale' ),
						'goals' => array(),
					),
				),
			),
			'woocommerce' => array(
				'label'  => __( 'WooCommerce', 'doublescale' ),
				'groups' => array(
					'coupon' => array(
						'label'       => __( 'Coupon', 'doublescale' ),
						'goals'       => array(),
						'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
					),
					'cart'   => array(
						'label'       => __( 'Cart', 'doublescale' ),
						'goals'       => array(),
						'triggers'    => array( 'wc_abandoned_cart_created' ),
						'is_disabled' => ! doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
					),
				),
			),
			'surecart'    => array(
				'label'  => __( 'SureCart', 'doublescale' ),
				'groups' => array(
					'order' => array(
						'label'       => __( 'Order', 'doublescale' ),
						'goals'       => array(),
						'is_disabled' => ! defined( 'SURECART_PLUGIN_FILE' ),
					),
				),
			),
		);

		$this->sources = apply_filters( 'doublescale_automation_goal_sources', $this->sources );
	}

	/**
	 * Get sources
	 *
	 * @return array
	 */
	public function get_sources() {
		return $this->sources;
	}
}
