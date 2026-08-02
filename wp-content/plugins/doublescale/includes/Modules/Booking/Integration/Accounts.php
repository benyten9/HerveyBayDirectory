<?php

/**
 * Integration Accounts
 *
 * This class is responsible for handling the Integration Accounts
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Integration;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\Integration;
use Illuminate\Support\Arr;

/**
 * Integration Accounts class
 */
class Accounts {

	/**
	 * Integration
	 *
	 * @var Integration
	 */
	protected $integration;

	/**
	 * Cache option
	 *
	 * @var string
	 */
	protected $cache_meta_key;

	/**
	 * Constructor
	 *
	 * @param Integration $integration
	 */
	public function __construct( Integration $integration ) {
		$this->integration    = $integration;
		$this->cache_meta_key = $this->integration->slug . '_accounts_cache';
	}

	/**
	 * Get cache data
	 *
	 * @since 1.0.0
	 *
	 * @param int      $account_id Account ID.
	 * @param string   $key Key.
	 * @param callable $callback Callback.
	 * @param int      $cache_time Cache time.
	 *
	 * @return array
	 */
	public function get_cache_data( $account_id, $key, $callback, $cache_time = null ) {
		if ( $cache_time == null ) {
			$cache_time = MINUTE_IN_SECONDS * 5;
		} else {
			$cache_time = $cache_time * MINUTE_IN_SECONDS;
		}

		$cache_key = "{$this->integration->slug}_cache_$account_id";
		$cache     = $this->integration->host->get_meta( $cache_key, array() );
		// Ensure cache is an array (it may be a string if data was corrupted)
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}
		$cached = Arr::get( $cache, $key );

		if ( ! empty( $cached ) && Arr::get( $cached, 'time' ) > time() - $cache_time ) {
			return $cached['data'];
		}

		$data          = $callback();
		$cache[ $key ] = array(
			'time' => time(),
			'data' => $data,
		);

		$this->integration->host->update_meta( $cache_key, $cache );

		return $data;
	}

	/**
	 * Delete cache data
	 *
	 * @since 1.0.0
	 *
	 * @param int    $account_id Account ID.
	 * @param string $key Key.
	 *
	 * @return void
	 */
	public function delete_cache_data( $account_id, $key ) {
		$cache_key = "{$this->integration->slug}_cache_$account_id";
		$cache     = $this->integration->host->get_meta( $cache_key, array() );
		// Ensure cache is an array (it may be a string if data was corrupted)
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}
		unset( $cache[ $key ] );
		$this->integration->host->update_meta( $cache_key, $cache );
	}

	/**
	 * Get the accounts
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_accounts() {
		$meta = $this->integration->host->get_meta( $this->integration->meta_key, array() );

		return $meta;
	}

	/**
	 * Get the account
	 *
	 * @since 1.0.0
	 *
	 * @param int $account_id
	 *
	 * @return array
	 */
	public function get_account( $account_id ) {
		$accounts = $this->get_accounts();
		return Arr::get( $accounts, $account_id, array() );
	}

	/**
	 * Add account
	 *
	 * @since 1.0.0
	 *
	 * @param string|int $account_id Account ID.
	 * @param array      $data Data.
	 *
	 * @return string|int
	 */
	public function add_account( $account_id, $data ) {
		if ( ! is_numeric( $account_id ) ) {
			$hash       = crc32( $account_id );
			$account_id = abs( $hash );
		}

		$accounts                = $this->get_accounts();
		$accounts[ $account_id ] = $data;
		$this->integration->host->update_meta( $this->integration->meta_key, $accounts );

		return $account_id;
	}

	/**
	 * Update account
	 *
	 * @since 1.0.0
	 *
	 * @param int   $account_id Account ID.
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function update_account( $account_id, $data ) {
		$accounts = $this->get_accounts();
		$existing = $this->get_account( $account_id );

		// Merge config keys instead of replacing the whole config object. Partial REST
		// updates (e.g. only `calendars`) must not wipe `default_calendar` or `settings`.
		if ( isset( $data['config'] ) && is_array( $data['config'] ) ) {
			$existing_config = isset( $existing['config'] ) && is_array( $existing['config'] )
				? $existing['config']
				: array();
			$data['config']  = array_merge( $existing_config, $data['config'] );
		}

		$account                 = array_replace( $existing, $data );
		$accounts[ $account_id ] = $account;
		$this->integration->host->update_meta( $this->integration->meta_key, $accounts );

		return $account;
	}

	/**
	 * Delete account
	 *
	 * @since 1.0.0
	 *
	 * @param int $account_id Account ID.
	 *
	 * @return void
	 */
	public function delete_account( $account_id ) {
		$accounts = $this->get_accounts();
		unset( $accounts[ $account_id ] );
		$this->integration->host->update_meta( $this->integration->meta_key, $accounts );
	}
}
