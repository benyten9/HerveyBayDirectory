<?php

namespace SEOPressPro\Services\Options;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

class SitemapOptionPro {

	/**
	 * @since 6.6.0
	 *
	 * @return array
	 */
	public function getOption() {
		return get_option( 'seopress_xml_sitemap_option_name' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string|null
	 *
	 * @param string $key
	 */
	protected function searchOptionByKey( $key ) {
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
	 * @since 6.6.0
	 *
	 * @return string|null
	 */
	public function getSitemapVideoEnable() {
		return $this->searchOptionByKey( 'seopress_xml_sitemap_video_enable' );
	}

	/**
	 * Auto-fill the automatic Video schema from the first YouTube video detected in the post content.
	 *
	 * @since 9.9.0
	 *
	 * @return string|null
	 */
	public function getVideoSchemaYouTubeEnable() {
		return $this->searchOptionByKey( 'seopress_xml_sitemap_video_schema_youtube_enable' );
	}
}
