<?php

namespace SEOPressPro\Services\Forms\Schemas;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Core\FormApi;

class FormSchemaReview extends FormApi {
	protected function getTypeByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_review_item_type':
				return 'select';
			case '_seopress_pro_rich_snippets_review_img':
				return 'upload';
			case '_seopress_pro_rich_snippets_review_rating':
			case '_seopress_pro_rich_snippets_review_max_rating':
				return 'number';
			case '_seopress_pro_rich_snippets_review_body':
				return 'textarea';
			case '_seopress_pro_rich_snippets_review_item':
				return 'input';

		}
	}

	protected function getLabelByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_review_item':
				return __( 'Review item name', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_review_item_type':
				return __( 'Review item type', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_review_img':
				return __( 'Review item image', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_review_rating':
				return __( 'Your rating', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_review_max_rating':
				return __( 'Max best rating', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_review_body':
				return __( 'Review body', 'wp-seopress-pro' );
		}
	}

	protected function getPlaceholderByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_review_item':
				return __( 'The item name reviewed', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_review_img':
				return __( 'Select your image', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_review_rating':
				return __( 'The item rating', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_review_max_rating':
				return __( 'Max best rating', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_review_body':
				return __( 'Enter your review body', 'wp-seopress-pro' );
		}
	}

	protected function getDescriptionByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_review_rating':
				return __( 'Your rating: scale from 1 to 5.', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_review_max_rating':
				return __( 'Only required if your scale is different from 1 to 5.', 'wp-seopress-pro' );
		}
	}

	protected function getOptions( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_review_item_type':
				return array(
					array(
						'value' => 'CreativeWorkSeason',
						'label' => __( 'CreativeWorkSeason', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'CreativeWorkSeries',
						'label' => __( 'CreativeWorkSeries', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'Episode',
						'label' => __( 'Episode', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'Game',
						'label' => __( 'Game', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'MediaObject',
						'label' => __( 'MediaObject', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'MusicPlaylist',
						'label' => __( 'MusicPlaylist', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'MusicRecording',
						'label' => __( 'MusicRecording', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'Organization',
						'label' => __( 'Organization', 'wp-seopress-pro' ),
					),
				);
		}
	}

	protected function getDetails( $postId = null ) {
		return array(
			array(
				'key' => '_seopress_pro_rich_snippets_review_item',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_review_item_type',
				'value' => 'CreativeWorkSeason',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_review_img',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_review_rating',
				'min' => 1,
			),
			array(
				'key' => '_seopress_pro_rich_snippets_review_max_rating',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_review_body',
				'class' => 'seopress-textarea-high-size',
			),
		);
	}
}
