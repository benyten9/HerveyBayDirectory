<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Review Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' ); ?>

<div class="wrap-rich-snippets-review">
	<div class="seopress-notice">
		<p>
			<?php
			/* translators: %s: link documentation */
				echo wp_kses_post( sprintf( __( 'Learn more about the <strong>Review schema</strong> from the <a href="%s" target="_blank">Google official documentation website</a>', 'wp-seopress-pro' ), 'https://developers.google.com/search/docs/data-types/review-snippet' ) );
			?>
			<span class="dashicons dashicons-external"></span>
		</p>
	</div>
	<p>
		<label for="seopress_pro_rich_snippets_review_item_meta">
			<?php esc_html_e( 'Review item name', 'wp-seopress-pro' ); ?>
			<code>item</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_review_item', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'The item name reviewed', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_review_item_type_meta">
			<?php esc_html_e( 'Review item type', 'wp-seopress-pro' ); ?>
			<code>itemType</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_review_item_type', 'default' ); ?>
		<span class="description"><?php echo wp_kses_post( __( '<strong>Authorized values:</strong> "CreativeWorkSeason", "CreativeWorkSeries", "Episode", "Game", "MediaObject", "MusicPlaylist", "MusicRecording", "Organization"', 'wp-seopress-pro' ) ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_review_img_meta"><?php esc_html_e( 'Review item image', 'wp-seopress-pro' ); ?>
			<code>image</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_review_img', 'image' ); ?>
		<span class="description"><?php esc_html_e( 'Review item image URL', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_review_rating_meta">
			<?php esc_html_e( 'Your rating', 'wp-seopress-pro' ); ?>
			<code>ratingValue</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_review_rating', 'rating' ); ?>
		<span class="description"><?php esc_html_e( 'Your rating: scale from 1 to 5', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_review_max_rating_meta">
			<?php esc_html_e( 'Max best rating', 'wp-seopress-pro' ); ?>
			<code>bestRating</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_review_max_rating', 'rating' ); ?>
		<span class="description"><?php esc_html_e( 'Only required if your scale is different from 1 to 5.', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_review_body_meta">
			<?php esc_html_e( 'Review body', 'wp-seopress-pro' ); ?>
			<code>reviewBody</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_review_body', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Your review body', 'wp-seopress-pro' ); ?></span>
	</p>
</div>
