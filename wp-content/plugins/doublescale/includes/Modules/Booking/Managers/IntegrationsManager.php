<?php

/**
 * Class Integrations Manager
 * This class is responsible for handling the integrations
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Managers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\Manager;
use DoubleScale\Modules\Booking\Abstracts\Integration;
use DoubleScale\Modules\Booking\Traits\Singleton;
use DoubleScale\Modules\Booking\Helpers\IntegrationsHelper;

/**
 * Integrations Manager class
 */
final class IntegrationsManager extends \DoubleScale\Modules\Booking\Abstracts\Manager {


	use \DoubleScale\Modules\Booking\Traits\Singleton;

	/**
	 * Register Integration
	 *
	 * @since 1.0.0
	 *
	 * @param Integration $integration
	 * @return void
	 * @throws \Exception
	 */
	public function register_integration( Integration $integration ) {
		$this->register(
			$integration,
			Integration::class,
			'slug',
			array(
				'name'         => 'name',
				'description'  => 'description',
				'icon'         => 'get_icon',
				'settings'     => 'get_settings',
				'is_calendar'  => 'is_calendar',
				'auth_type'    => 'auth_type',
				'fields'       => 'get_fields',
				'has_accounts' => 'has_accounts',
				'is_global'    => 'is_global',
				'auth_fields'  => 'get_auth_fields',
			)
		);
	}

	/**
	 * Get Integration
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug
	 * @return Integration|null
	 */
	public function get_integration( $slug ) {
		return $this->get_item( $slug );
	}

	/**
	 * Check if integrations are available
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function has_integrations() {
		return IntegrationsHelper::has_integrations();
	}

	/**
	 * Get Integrations
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_integrations() {
		if ( ! $this->has_integrations() ) {
			return IntegrationsHelper::get_default_integrations( 'manager' );
		}

		return $this->get_items();
	}

	/**
	 * Get Options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options() {
		if ( ! $this->has_integrations() ) {
			return IntegrationsHelper::get_default_integrations( 'manager' );
		}

		return parent::get_options();
	}
}
