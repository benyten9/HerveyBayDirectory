<?php

namespace SEOPressPro\Services\Admin\Settings\LocalBusiness;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

class TransformOldOpeningHours {
	/**
	 * @since 4.5.0
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public function transform( $data ) {
		if ( ! is_array( $data ) ) {
			return null;
		}

		if ( isset( $data[0] ) && isset( $data[0]['am'], $data[0]['pm'] ) ) {
			return $data;
		}

		$transformData = array();

		foreach ( $data as $key => $value ) {
			$transformData[ $key ] = array(
				'open' => isset( $value['open'] ) ? '1' : '0',
				'am'   => array(
					'open'  => ! isset( $value['open'] ) ? '1' : '0',
					'start' => $value['start'],
					'end'   => array(
						'hours' => '00',
						'mins'  => '00',
					),
				),
				'pm'   => array(
					'open'  => ! isset( $value['open'] ) ? '1' : '0',
					'start' => array(
						'hours' => '00',
						'mins'  => '00',
					),
					'end'   => $value['end'],
				),
			);
		}

		return $transformData;
	}
}
