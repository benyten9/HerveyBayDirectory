<?php
/**
 * Block Bindings source: seopress/seo-data.
 *
 * Allows any block to bind its attributes to SEOPress SEO data
 * (title, description, keywords, social metas, etc.).
 *
 * Usage in a block:
 * <!-- wp:paragraph {
 *   "metadata": {
 *     "bindings": {
 *       "content": {
 *         "source": "seopress/seo-data",
 *         "args": { "key": "meta_description" }
 *       }
 *     }
 *   }
 * } -->
 *
 * @package SEOPress PRO
 * @since 9.8.0
 */

namespace SEOPressPro\Actions\BlockBindings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class SeoDataSource implements ExecuteHooks {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the block bindings source.
	 *
	 * @return void
	 */
	public function register() {
		register_block_bindings_source(
			'seopress/seo-data',
			array(
				'label'              => __( 'SEOPress SEO Data', 'wp-seopress-pro' ),
				'get_value_callback' => array( $this, 'get_value' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * Get the value from SEOPress post meta.
	 *
	 * @param array  $source_args    Source args with 'key'.
	 * @param object $block_instance Block instance.
	 * @param string $attribute_name Attribute name.
	 * @return string|null Value or null.
	 */
	public function get_value( $source_args, $block_instance, $attribute_name ) {
		if ( empty( $source_args['key'] ) ) {
			return null;
		}

		$post_id = ! empty( $block_instance->context['postId'] )
			? $block_instance->context['postId']
			: get_the_ID();

		if ( ! $post_id ) {
			return null;
		}

		$key = sanitize_text_field( $source_args['key'] );

		$meta_map = array(
			'seo_title'       => '_seopress_titles_title',
			'meta_description' => '_seopress_titles_desc',
			'target_keywords' => '_seopress_analysis_target_kw',
			'canonical'       => '_seopress_robots_canonical',
			'fb_title'        => '_seopress_social_fb_title',
			'fb_description'  => '_seopress_social_fb_desc',
			'tw_title'        => '_seopress_social_twitter_title',
			'tw_description'  => '_seopress_social_twitter_desc',
			'noindex'         => '_seopress_robots_index',
			'nofollow'        => '_seopress_robots_follow',
		);

		if ( ! isset( $meta_map[ $key ] ) ) {
			return null;
		}

		$value = get_post_meta( $post_id, $meta_map[ $key ], true );

		return ! empty( $value ) ? $value : null;
	}
}
