<?php

namespace SEOPressPro\Helpers\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SEOIssues {
	public static function getData() {
		$data = array(
			'all_canonical'     => array(
				'title' => __( 'Canonical URL', 'wp-seopress-pro' ),
				'desc'  => null,
			),
			'json_schemas'      => array(
				'title' => __( 'Structured data types', 'wp-seopress-pro' ),
				'desc'  => null,
			),
			'old_post'          => array(
				'title' => __( 'Last modified date', 'wp-seopress-pro' ),
				'desc'  => __( 'Search engines love fresh content. Update regularly your articles without entirely rewriting your content and give them a boost in search rankings.', 'wp-seopress-pro' ),
			),
			'permalink'         => array(
				'title' => __( 'Keywords in permalink', 'wp-seopress-pro' ),
				'desc'  => null,
			),
			'headings'          => array(
				'title' => __( 'Headings', 'wp-seopress-pro' ),
				'desc'  => null,
			),
			'title'             => array(
				'title' => __( 'Meta title', 'wp-seopress-pro' ),
				'desc'  => null,
			),
			'description'       => array(
				'title' => __( 'Meta description', 'wp-seopress-pro' ),
				'desc'  => null,
			),
			'social'            => array(
				'title' => __( 'Social meta tags', 'wp-seopress-pro' ),
				'desc'  => null,
			),
			'robots'            => array(
				'title' => __( 'Meta robots', 'wp-seopress-pro' ),
				'desc'  => null,
			),
			'img_alt'           => array(
				'title' => __( 'Alternative texts of images', 'wp-seopress-pro' ),
				'desc'  => __( 'No alternative text found for these images. Alt tags are important for both SEO and accessibility. Edit your images using the media library or your favorite page builder and fill in alternative text fields.', 'wp-seopress-pro' ),
			),
			'nofollow_links'    => array(
				'title' => __( 'NoFollow Links', 'wp-seopress-pro' ),
				'desc'  => null,
			),
			'outbound_links'    => array(
				'title' => __( 'Outbound Links', 'wp-seopress-pro' ),
				'desc'  => null,
			),
			'internal_links'    => array(
				'title' => __( 'Internal Links', 'wp-seopress-pro' ),
				'desc'  => null,
			),
			'content_depth'     => array(
				'title' => __( 'Content depth', 'wp-seopress-pro' ),
				'desc'  => __( 'Google\'s AI features and search rank in-depth content with a unique point of view higher than thin, commodity content.', 'wp-seopress-pro' ),
			),
			'heading_hierarchy' => array(
				'title' => __( 'Heading structure', 'wp-seopress-pro' ),
				'desc'  => __( 'Organize your content into clear sections with a coherent heading hierarchy so readers and AI systems can understand the structure of your page.', 'wp-seopress-pro' ),
			),
			'content_media'     => array(
				'title' => __( 'Media in content', 'wp-seopress-pro' ),
				'desc'  => __( 'Google recommends including relevant, high-quality images and videos. Rich media improves engagement and eligibility for AI experiences.', 'wp-seopress-pro' ),
			),
			'content_structure' => array(
				'title' => __( 'Content readability', 'wp-seopress-pro' ),
				'desc'  => __( 'Avoid walls of text. Splitting long content into well-titled sections makes it easier to read and to surface in AI answers.', 'wp-seopress-pro' ),
			),
		);

		return $data;
	}
}
