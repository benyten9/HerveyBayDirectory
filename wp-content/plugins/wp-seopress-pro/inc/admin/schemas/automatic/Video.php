<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Video Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' ); ?>

<div class="wrap-rich-snippets-videos">
	<div class="seopress-notice">
		<p>
			<?php
			/* translators: %s: link documentation */
				echo wp_kses_post( sprintf( __( 'Learn more about the <strong>Video schema</strong> from the <a href="%s" target="_blank">Google official documentation website</a>', 'wp-seopress-pro' ), 'https://developers.google.com/search/docs/data-types/video' ) );
			?>
			<span class="dashicons dashicons-external"></span>
		</p>
	</div>
	<?php
	$seopress_pro_rich_snippets_videos_youtube_autofill = get_post_meta( get_the_ID(), '_seopress_pro_rich_snippets_videos_youtube_autofill', true );
	?>
	<p>
		<label for="seopress_pro_rich_snippets_videos_youtube_autofill">
			<input
				type="checkbox"
				id="seopress_pro_rich_snippets_videos_youtube_autofill"
				name="seopress_pro_rich_snippets_videos_youtube_autofill"
				value="1"
				<?php checked( '1', $seopress_pro_rich_snippets_videos_youtube_autofill ); ?>
			/>
			<?php esc_html_e( 'Auto-fill from first YouTube video detected in the post content', 'wp-seopress-pro' ); ?>
		</label>
		<span class="description">
			<?php esc_html_e( 'When enabled, empty fields below are populated with the metadata of the first YouTube video found in the post content, fetched when the post is saved. Without a YouTube Data API key in the Structured Data Types settings, only the title and thumbnail are available. Manual values always take precedence.', 'wp-seopress-pro' ); ?>
		</span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_videos_name_meta">
			<?php esc_html_e( 'Video name', 'wp-seopress-pro' ); ?>
			<code>name</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_videos_name', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The title of your video', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label
			for="seopress_pro_rich_snippets_videos_description_meta"><?php esc_html_e( 'Video description', 'wp-seopress-pro' ); ?>
			<code>description</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_videos_description', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The description of the video', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label
			for="seopress_pro_rich_snippets_videos_date_posted_meta"><?php esc_html_e( 'Uploaded date', 'wp-seopress-pro' ); ?>
			<code>uploadDate</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_videos_date_posted', 'date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'The uploaded date of your video in ISO 8601 format. For example, "2017-01-24" or "2017-01-24T19:33:17+00:00".', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label
			for="seopress_pro_rich_snippets_videos_img_meta"><?php esc_html_e( 'Video thumbnail', 'wp-seopress-pro' ); ?>
			<code>thumbnailUrl</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_videos_img', 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'Minimum size: 160px by 90px - Max size: 1920x1080px - crawlable and indexable', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_videos_duration_meta">
			<?php esc_html_e( 'Duration of your video (format: hh:mm:ss)', 'wp-seopress-pro' ); ?>
			<code>duration</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_videos_duration', 'time' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'e.g. 00:04:30 for 4 minutes and 30 seconds', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_videos_url_meta">
			<?php esc_html_e( 'Video URL', 'wp-seopress-pro' ); ?>
			<code>url</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_videos_url', 'default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- seopress_schemas_mapping_array() returns admin markup whose dynamic parts are escaped where the string is assembled. ?>
		<span
			class="description"><?php esc_html_e( 'e.g. https://example.com/video.mp4', 'wp-seopress-pro' ); ?></span>
	</p>
</div>
