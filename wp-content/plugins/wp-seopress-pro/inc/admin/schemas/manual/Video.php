<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Video Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

function seopress_get_schema_metaboxe_video( $seopress_pro_rich_snippets_data, $key_schema = 0 ) {
	$seopress_pro_rich_snippets_videos_name        = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_name'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_name'] : '';
	$seopress_pro_rich_snippets_videos_description = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_description'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_description'] : '';
	$seopress_pro_rich_snippets_videos_img         = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_img'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_img'] : '';
	$seopress_pro_rich_snippets_videos_date_posted = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_date_posted'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_date_posted'] : '';
	$seopress_pro_rich_snippets_videos_img_width   = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_img_width'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_img_width'] : '';
	$seopress_pro_rich_snippets_videos_img_height  = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_img_height'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_img_height'] : '';
	$seopress_pro_rich_snippets_videos_duration    = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_duration'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_duration'] : '';
	$seopress_pro_rich_snippets_videos_url         = isset( $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_url'] ) ? $seopress_pro_rich_snippets_data['_seopress_pro_rich_snippets_videos_url'] : '';

	?>
<div class="wrap-rich-snippets-item wrap-rich-snippets-videos">
	<div class="seopress-notice">
		<p>
			<?php esc_html_e( 'Mark up your video content with structured data to make Google Search an entry point for discovering and watching videos. ', 'wp-seopress-pro' ); ?>
		</p>
	</div>
	<p>
		<label for="seopress_pro_rich_snippets_videos_name_meta">
			<?php esc_html_e( 'Video name', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_videos_name_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_videos_name]"
			placeholder="<?php esc_html_e( 'The title of your video', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Video name', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_videos_name ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_videos_description_meta"><?php esc_html_e( 'Video description', 'wp-seopress-pro' ); ?>
		</label>
		<textarea id="seopress_pro_rich_snippets_videos_description_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_videos_description]"
			placeholder="<?php esc_html_e( 'The description of the video', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Video description', 'wp-seopress-pro' ); ?>"><?php echo wp_kses_post( $seopress_pro_rich_snippets_videos_description ); ?></textarea>
	</p>
	<p>
		<label for="seopress-date-picker4">
			<?php esc_html_e( 'Uploaded date', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress-date-picker4" class="seopress-date-picker" autocomplete="off"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_videos_date_posted]"
			placeholder="<?php esc_html_e( 'The uploaded date of your video in ISO 8601 format. For example, "2017-01-24" or "2017-01-24T19:33:17+00:00".', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Published date', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_videos_date_posted ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_videos_img_meta"><?php esc_html_e( 'Video thumbnail', 'wp-seopress-pro' ); ?>
		</label>
		<span class="description"><?php esc_html_e( 'Minimum size: 160px by 90px - Max size: 1920x1080px - crawlable and indexable', 'wp-seopress-pro' ); ?></span>
		<input id="seopress_pro_rich_snippets_videos_img_meta" type="text"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_videos_img]"
			placeholder="<?php esc_html_e( 'Select your image', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Video thumbnail', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_url( $seopress_pro_rich_snippets_videos_img ); ?>" />
		<input id="seopress_pro_rich_snippets_videos_img_width" type="hidden"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_videos_img_width]"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_videos_img_width ); ?>" />
		<input id="seopress_pro_rich_snippets_videos_img_height" type="hidden"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_videos_img_height]"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_videos_img_height ); ?>" />
		<input id="seopress_pro_rich_snippets_videos_img" class="<?php echo esc_attr( seopress_btn_secondary_classes() ); ?> seopress_media_upload"
			type="button"
			value="<?php esc_html_e( 'Upload an Image', 'wp-seopress-pro' ); ?>" />
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_videos_duration_meta">
			<?php esc_html_e( 'Duration of your video (format: hh:mm:ss)', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_videos_duration_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_videos_duration]"
			placeholder="<?php esc_html_e( 'e.g. 00:04:30 for 4 minutes and 30 seconds', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Duration of your video (format: hh:mm:ss)', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_attr( $seopress_pro_rich_snippets_videos_duration ); ?>" />
		<span class="description"><?php esc_html_e( 'You must respect the format of this field: hh:mm:ss', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_videos_url_meta">
			<?php esc_html_e( 'Video URL', 'wp-seopress-pro' ); ?>
		</label>
		<input type="text" id="seopress_pro_rich_snippets_videos_url_meta"
			name="seopress_pro_rich_snippets_data[<?php echo esc_attr( $key_schema ); ?>][seopress_pro_rich_snippets_videos_url]"
			placeholder="<?php esc_html_e( 'e.g. https://example.com/video.mp4', 'wp-seopress-pro' ); ?>"
			aria-label="<?php esc_html_e( 'Video URL', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_url( $seopress_pro_rich_snippets_videos_url ); ?>" />
	</p>
</div>
	<?php
}
