<?php

/**
 * Class Utils
 * This class contains functions that are used in multiple places
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Utils;

defined( 'ABSPATH' ) || exit;

// use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldsGroupModel; // Pro
// use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel; // Pro
use DateInterval;
use DatePeriod;
use DateTime;

/**
 * Utils class
 */
class Utils {


	/**
	 * Get max execution time
	 *
	 * @return int
	 */
	public static function get_max_execution_time() {
		$max_execution_time = 30;

		if ( function_exists( 'ini_get' ) ) {
			$max_execution_time = ini_get( 'max_execution_time' );

			if ( ! $max_execution_time ) {
				$max_execution_time = 30;
			}
		}

		// Cap at 60 seconds max to avoid overly long runs
		$max_execution_time = min( $max_execution_time, 60 );

		$adjusted_execution_time = $max_execution_time * 0.75;

		return apply_filters( 'doublescale_runtime_max_execution_time', $adjusted_execution_time );
	}

	/**
	 * Is memory limit reached
	 *
	 * @return bool
	 */
	public static function is_memory_limit_reached() {
		$memory_limit = self::get_memory_limit();
		$memory_usage = memory_get_usage( true );

		// Handle unlimited memory (-1)
		if ( $memory_limit === '-1' ) {
			return false;
		}

		$memory_limit_bytes = self::convert_to_bytes( $memory_limit );
		$memory_threshold   = $memory_limit_bytes * 0.75;

		return $memory_usage >= $memory_threshold;
	}

	/**
	 * Get memory limit
	 *
	 * @return string
	 */
	public static function get_memory_limit() {
		$memory_limit = '128M';

		if ( function_exists( 'ini_get' ) ) {
			$memory_limit = ini_get( 'memory_limit' );

			if ( ! $memory_limit ) {
				$memory_limit = '128M';
			}
		}

		return apply_filters( 'doublescale_runtime_memory_limit', $memory_limit );
	}

	/**
	 * Convert to bytes
	 *
	 * @param string $value
	 *
	 * @return int
	 */
	public static function convert_to_bytes( $value ) {
		$value     = trim( $value );
		$last      = strtolower( $value[ strlen( $value ) - 1 ] );
		$new_value = intval( $value );

		switch ( $last ) {
			case 'g':
				$new_value *= GB_IN_BYTES;
				break;
			case 'm':
				$new_value *= MB_IN_BYTES;
				break;
			case 'k':
				$new_value *= KB_IN_BYTES;
				break;
		}

		return $new_value;
	}

	/**
	 * Generate hash key
	 *
	 * @return string
	 */
	public static function generate_hash_key() {
		return md5( uniqid( wp_rand(), true ) );
	}

	/**
	 * Get selectable timezone options
	 *
	 * Returns every PHP timezone identifier with its current UTC offset, so
	 * date/time controls can offer a complete list instead of a hardcoded handful
	 * of cities. Labels are derived from the identifier on the client to keep this
	 * payload small - it ships on every admin page load.
	 *
	 * @since 1.0.0
	 *
	 * @return array List of array{value:string,offset:string}.
	 */
	public static function get_timezone_options() {
		$options = array();

		foreach ( \DateTimeZone::listIdentifiers() as $identifier ) {
			try {
				$zone   = new \DateTimeZone( $identifier );
				$offset = $zone->getOffset( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) );
			} catch ( \Exception $e ) {
				continue;
			}

			$sign    = $offset < 0 ? '-' : '+';
			$offset  = abs( $offset );
			$hours   = str_pad( (string) floor( $offset / 3600 ), 2, '0', STR_PAD_LEFT );
			$minutes = str_pad( (string) floor( ( $offset % 3600 ) / 60 ), 2, '0', STR_PAD_LEFT );

			$options[] = array(
				'value'  => $identifier,
				'offset' => sprintf( 'UTC%s%s:%s', $sign, $hours, $minutes ),
			);
		}

		return $options;
	}

	/**
	 * Get contact fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_contact_fields() {
		$fields = array(
			0 => array(
				'label'  => __( 'Default Fields', 'doublescale' ),
				'fields' => array(
					'first_name'     => array(
						'label' => __( 'First Name', 'doublescale' ),
						'type'  => 'text',
					),
					'last_name'      => array(
						'label' => __( 'Last Name', 'doublescale' ),
						'type'  => 'text',
					),
					'company_name'   => array(
						'label' => __( 'Company Name', 'doublescale' ),
						'type'  => 'text',
					),
					'company_registration_number' => array(
						'label' => __( 'Company Registration Number', 'doublescale' ),
						'type'  => 'text',
					),
					'tax_vat_number' => array(
						'label' => __( 'Tax / VAT Number', 'doublescale' ),
						'type'  => 'text',
					),
					'email'          => array(
						'label' => __( 'Email', 'doublescale' ),
						'type'  => 'email',
					),
					'phone'          => array(
						'label' => __( 'Phone', 'doublescale' ),
						'type'  => 'phone',
					),
					'address_1'      => array(
						'label' => __( 'Address 1', 'doublescale' ),
						'type'  => 'text',
					),
					'address_2'      => array(
						'label' => __( 'Address 2', 'doublescale' ),
						'type'  => 'text',
					),
					'city'           => array(
						'label' => __( 'City', 'doublescale' ),
						'type'  => 'text',
					),
					'state'          => array(
						'label' => __( 'State', 'doublescale' ),
						'type'  => 'text',
					),
					'country'        => array(
						'label' => __( 'Country', 'doublescale' ),
						'type'  => 'text',
					),
					'zip'            => array(
						'label' => __( 'Zip', 'doublescale' ),
						'type'  => 'text',
					),
					'whatsapp_phone' => array(
						'label' => __( 'Whatsapp Phone', 'doublescale' ),
						'type'  => 'phone',
					),
				),
			),
		);

		$custom_fields = self::get_custom_fields();

		$fields = array_merge( $fields, $custom_fields );

		return $fields;
	}

	/**
	 * Get custom fields
	 * Custom fields are PRO-only feature
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope Scope to load fields for. Defaults to contact.
	 *
	 * @return array
	 */
	public static function get_custom_fields( $scope = 'contact' ) {
		// Custom fields are PRO-only feature
		if ( ! class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldsGroupModel' ) ) {
			return array();
		}

		// Groups and fields are both scoped, so filter on each side. Without
		// this every scope (deal, task, project) leaks into contact mapping.
		$groups = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldsGroupModel::where( 'scope', $scope )
			->with(
				array(
					'custom_fields' => function ( $query ) use ( $scope ) {
						$query->where( 'scope', $scope );
					},
				)
			)
			->get();

		$fields = array();

		foreach ( $groups as $group ) {
			if ( empty( $group->custom_fields ) || count( $group->custom_fields ) === 0 ) {
				continue;
			}

			/** @var \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldsGroupModel $group */
			$fields[ $group->id ] = array(
				'label'  => $group->name,
				'fields' => array(),
			);

			foreach ( $group->custom_fields as $field ) {
				/** @var \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel $field */
				$fields[ $group->id ]['fields'][ $field->id ] = array(
					'label' => $field->name,
					'type'  => $field->type,
				);
			}
		}

		return $fields;
	}

	/**
	 * Get days/months between two dates
	 *
	 * @since 1.0.0
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 *
	 * @return array
	 */
	public static function get_dates_between_dates( $start_date, $end_date ) {
		// First check if days > 30 return months.
		$days  = self::get_days_count_between_dates( $start_date, $end_date );
		$dates = array(
			'dates' => array(),
			'type'  => 'day',
		);

		if ( $days > 30 ) {
			$dates = array(
				'dates' => self::get_months_between_dates( $start_date, $end_date ),
				'type'  => 'month',
			);
		} elseif ( $days === 0 ) {
			$dates = array(
				'dates' => self::get_day_hours_between_dates( $start_date ),
				'type'  => 'hour',
			);
		} else {
			$dates = array(
				'dates' => self::get_days_between_dates( $start_date, $end_date ),
				'type'  => 'day',
			);
		}

		return $dates;
	}

	/**
	 * Get days between two dates
	 *
	 * @since 1.0.0
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 *
	 * @return int
	 */
	public static function get_days_count_between_dates( $start_date, $end_date ) {
		$datetime1 = new DateTime( $start_date );
		$datetime2 = new DateTime( $end_date );
		$interval  = $datetime1->diff( $datetime2 );

		return $interval->days;
	}

	/**
	 * Get days between two dates
	 *
	 * @since 1.0.0
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 *
	 * @return array
	 */
	public static function get_days_between_dates( $start_date, $end_date ) {
		$dates = array();

		$start_date = new DateTime( $start_date );
		$end_date   = new DateTime( $end_date );

		$end_date->modify( '+1 day' );

		$interval  = new DateInterval( 'P1D' );
		$daterange = new DatePeriod( $start_date, $interval, $end_date );

		foreach ( $daterange as $date ) {
			$dates[] = $date->format( 'Y-m-d' );
		}

		return $dates;
	}

	/**
	 * Get months between two dates
	 *
	 * @since 1.0.0
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 *
	 * @return array
	 */
	public static function get_months_between_dates( $start_date, $end_date ) {
		$dates = array();

		$start_date = new DateTime( $start_date );
		$end_date   = new DateTime( $end_date );

		$end_date->modify( '+1 month' );

		$interval  = new DateInterval( 'P1M' );
		$daterange = new DatePeriod( $start_date, $interval, $end_date );

		foreach ( $daterange as $date ) {
			$dates[] = $date->format( 'Y-m-d' );
		}

		return $dates;
	}

	/**
	 * Get day hours between two dates
	 *
	 * @since 1.0.0
	 *
	 * @param string $date date.
	 *
	 * @return array
	 */
	public static function get_day_hours_between_dates( $date ) {
		$dates = array();

		// Get day hours.
		for ( $i = 0; $i < 24; $i++ ) {
			$dates[] = $date . ' ' . $i . ':00:00';
		}

		return $dates;
	}

	/**
	 * Get start date
	 *
	 * @since 1.0.0
	 *
	 * @param string $interval Interval.
	 * @param string $start_date Start date.
	 *
	 * @return string
	 */
	public static function get_start_date( $interval, $start_date ) {
		$start_date = '';
		switch ( $interval ) {
			case 'today':
				$start_date = gmdate( 'Y-m-d' );
				break;
			case 'yesterday':
				$start_date = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
				break;
			case 'last_7_days':
				$start_date = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
				break;
			case 'last_30_days':
				$start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
				break;
			case 'this_month':
				$start_date = gmdate( 'Y-m-01' );
				break;
			case 'last_month':
				$start_date = gmdate( 'Y-m-01', strtotime( 'first day of last month' ) );
				break;
			case 'this_year':
				$start_date = gmdate( 'Y-01-01' );
				break;
			case 'last_year':
				$start_date = gmdate( 'Y-01-01', strtotime( 'first day of last year' ) );
				break;
		}

		return $start_date;
	}

	/**
	 * Get end date
	 *
	 * @since 1.0.0
	 *
	 * @param string $interval Interval.
	 * @param string $end_date End date.
	 *
	 * @return string
	 */
	public static function get_end_date( $interval, $end_date ) {
		$end_date = '';
		switch ( $interval ) {
			case 'today':
				$end_date = gmdate( 'Y-m-d' );
				break;
			case 'yesterday':
				$end_date = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
				break;
			case 'last_7_days':
				$end_date = gmdate( 'Y-m-d' );
				break;
			case 'last_30_days':
				$end_date = gmdate( 'Y-m-d' );
				break;
			case 'this_month':
				$end_date = gmdate( 'Y-m-t' );
				break;
			case 'last_month':
				$end_date = gmdate( 'Y-m-t', strtotime( 'last day of last month' ) );
				break;
			case 'this_year':
				$end_date = gmdate( 'Y-12-31' );
				break;
			case 'last_year':
				$end_date = gmdate( 'Y-12-31', strtotime( 'last day of last year' ) );
				break;
		}

		return $end_date;
	}

	/**
	 * Format a date to the site's timezone, optionally including the time.
	 *
	 * @param string $date_string  The date string in UTC format.
	 * @param bool   $include_time Optional. Whether to include the time in the output. Default false.
	 *
	 * @return string The formatted date in the site's timezone.
	 */
	public static function format_date( $date_string, $include_time = false ) {
		if ( empty( $date_string ) ) {
			return '';
		}
		// Get the site's timezone setting, or default to 'UTC'.
		$site_timezone = get_option( 'timezone_string' ) ?: 'UTC';

		// Create a DateTime object in UTC timezone.
		$date = new DateTime( $date_string, new \DateTimeZone( 'UTC' ) );

		// Set the timezone to the site's timezone.
		$date->setTimezone( new \DateTimeZone( $site_timezone ) );

		// Determine the format based on whether to include time.
		$format = $include_time ? 'F j, Y \o\n h:i A' : 'F j, Y';

		return $date->format( $format );
	}
}
