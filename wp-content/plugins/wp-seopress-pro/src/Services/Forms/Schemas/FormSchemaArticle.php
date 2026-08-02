<?php

namespace SEOPressPro\Services\Forms\Schemas;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Core\FormApi;

class FormSchemaArticle extends FormApi {
	protected function getTypeByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_article_type':
				return 'select';
			case '_seopress_pro_rich_snippets_article_title':
			case '_seopress_pro_rich_snippets_article_desc':
			case '_seopress_pro_rich_snippets_article_author':
			case '_seopress_pro_rich_snippets_article_coverage_start_time':
			case '_seopress_pro_rich_snippets_article_coverage_end_time':
			case '_seopress_pro_rich_snippets_article_speakable_css_selector':
				return 'input';
			case '_seopress_pro_rich_snippets_article_img':
				return 'upload';
			case '_seopress_pro_rich_snippets_article_img_width':
			case '_seopress_pro_rich_snippets_article_img_height':
				return '';
			case '_seopress_pro_rich_snippets_article_coverage_start_date':
			case '_seopress_pro_rich_snippets_article_coverage_end_date':
				return 'date';
		}
	}

	protected function getLabelByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_article_type':
				return __( 'Select your article type', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_title':
				return __( 'Headline (max limit: 110)', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_desc':
				return __( 'Description', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_author':
				return __( 'Post author', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_img':
				return __( 'Image', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_coverage_start_date':
				return __( 'Coverage Start Date', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_coverage_start_time':
				return __( 'Coverage Start Time', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_coverage_end_date':
				return __( 'Coverage End Date', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_coverage_end_time':
				return __( 'Coverage End Time', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_speakable_css_selector':
				return __( 'Speakable CSS Selector', 'wp-seopress-pro' );
		}
	}

	protected function getPlaceholderByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_article_title':
				return __( 'The headline of the article', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_desc':
				return __( 'The description of the article', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_author':
				return __( 'The author of the article', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_img':
				return __( 'Select your image', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_coverage_start_date':
				return __( 'The beginning of live coverage. For example, "2017-01-24T19:33:17+00:00".', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_coverage_start_time':
			case '_seopress_pro_rich_snippets_article_coverage_end_time':
				return __( 'e.g. HH:MM', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_coverage_end_date':
				return __( 'The end of live coverage. For example, "2017-01-24T19:33:17+00:00".', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_speakable_css_selector':
				return __( 'e.g. post', 'wp-seopress-pro' );
			default:
				return '';
		}
	}

	protected function getDescriptionByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_article_type':
				return '';
			case '_seopress_pro_rich_snippets_article_title':
				return __( 'Default value if empty: Post title', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_desc':
				return __( 'Default value if empty: Post excerpt', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_author':
				return __( 'Default value if empty: Post author', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_img':
				return __( 'The representative image of the article. Only a marked-up image that directly belongs to the article should be specified. Default value if empty: Post thumbnail (featured image). Minimum size: 696px wide, JPG, PNG or GIF, crawlable and indexable (default: post thumbnail if available)', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_img_width':
			case '_seopress_pro_rich_snippets_article_img_height':
				return '';
			case '_seopress_pro_rich_snippets_article_coverage_start_date':
			case '_seopress_pro_rich_snippets_article_coverage_start_time':
			case '_seopress_pro_rich_snippets_article_coverage_end_date':
			case '_seopress_pro_rich_snippets_article_coverage_end_time':
				return __( 'To use with Live Blog Posting article type', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_article_speakable_css_selector':
				return __( 'Addresses content in the annotated pages (such as class attribute)', 'wp-seopress-pro' );
		}
	}

	protected function getOptions( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_article_type':
				return array(
					array(
						'value' => 'Article',
						'label' => 'Article (generic)',
					),
					array(
						'value' => 'AdvertiserContentArticle',
						'label' => 'Advertiser Content Article',
					),
					array(
						'value' => 'NewsArticle',
						'label' => 'News Article',
					),
					array(
						'value' => 'Report',
						'label' => 'Report',
					),
					array(
						'value' => 'SatiricalArticle',
						'label' => 'Satirical Article',
					),
					array(
						'value' => 'ScholarlyArticle',
						'label' => 'Scholarly Article',
					),
					array(
						'value' => 'SocialMediaPosting',
						'label' => 'Social Media Posting',
					),
					array(
						'value' => 'BlogPosting',
						'label' => 'Blog Posting',
					),
					array(
						'value' => 'TechArticle',
						'label' => 'Tech Article',
					),
					array(
						'value' => 'AnalysisNewsArticle',
						'label' => 'Analysis News Article',
					),
					array(
						'value' => 'AskPublicNewsArticle',
						'label' => 'Ask Public News Article',
					),
					array(
						'value' => 'BackgroundNewsArticle',
						'label' => 'Background News Article',
					),
					array(
						'value' => 'OpinionNewsArticle',
						'label' => 'Opinion News Article',
					),
					array(
						'value' => 'ReportageNewsArticle',
						'label' => 'Reportage News Article',
					),
					array(
						'value' => 'ReviewNewsArticle',
						'label' => 'Review News Article',
					),
					array(
						'value' => 'LiveBlogPosting',
						'label' => 'Live Blog Posting',
					),
				);
		}
	}

	protected function getDetails( $postId = null ) {
		return array(
			array(
				'key'     => '_seopress_pro_rich_snippets_article_type',
				'value'   => 'Article',
				'visible' => true,
			),
			array(
				'key'               => '_seopress_pro_rich_snippets_article_title',
				'visible'           => true,
				'recommended_limit' => 110,
			),
			array(
				'key'     => '_seopress_pro_rich_snippets_article_desc',
				'visible' => true,
			),
			array(
				'key'     => '_seopress_pro_rich_snippets_article_author',
				'visible' => true,
			),
			array(
				'key'     => '_seopress_pro_rich_snippets_article_img',
				'visible' => true,
			),
			array(
				'key'     => '_seopress_pro_rich_snippets_article_coverage_start_date',
				'visible' => true,
			),
			array(
				'key'     => '_seopress_pro_rich_snippets_article_coverage_start_time',
				'visible' => true,
			),
			array(
				'key'     => '_seopress_pro_rich_snippets_article_coverage_end_date',
				'visible' => true,
			),
			array(
				'key'     => '_seopress_pro_rich_snippets_article_coverage_end_time',
				'visible' => true,
			),
			array(
				'key'     => '_seopress_pro_rich_snippets_article_speakable_css_selector',
				'visible' => true,
			),
		);
	}
}
