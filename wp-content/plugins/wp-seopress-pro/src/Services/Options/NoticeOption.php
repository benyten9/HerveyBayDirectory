<?php

namespace SEOPressPro\Services\Options;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

class NoticeOption {

	/**
	 * @since 6.7.0
	 *
	 * @return array
	 */
	public function getOption() {
		return get_option( 'seopress_notices' );
	}

	/**
	 * @since 6.7.0
	 *
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function searchOptionByKey( $key ) {
		$data = $this->getOption();

		if ( empty( $data ) ) {
			return null;
		}

		if ( ! isset( $data[ $key ] ) ) {
			return null;
		}

		return $data[ $key ];
	}

	/**
	 * @since 6.7.0
	 *
	 * @return string
	 */
	public function getNoticeRobotsTxt() {
		return $this->searchOptionByKey( 'notice-robots-txt' );
	}
}
