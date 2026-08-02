<?php

namespace SEOPressPro\Services\Options\Schemas;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

trait PublisherOptions {
	/**
	 * @since 4.6.0
	 *
	 * @return string
	 */
	public function getArticlesPublisherLogo() {
		return $this->searchOptionByKey( 'seopress_rich_snippets_publisher_logo' );
	}

	/**
	 * @since 4.6.0
	 *
	 * @return string
	 */
	public function getArticlesPublisherLogoWidth() {
		return $this->searchOptionByKey( 'seopress_rich_snippets_publisher_logo_width' );
	}

	/**
	 * @since 4.6.0
	 *
	 * @return string
	 */
	public function getArticlesPublisherLogoHeight() {
		return $this->searchOptionByKey( 'seopress_rich_snippets_publisher_logo_height' );
	}
}
