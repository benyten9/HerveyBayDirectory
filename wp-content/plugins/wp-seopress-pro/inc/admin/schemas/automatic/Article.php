<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Article Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' ); ?>

<div class="wrap-rich-snippets-articles schema-steps">
	<div class="seopress-notice">
		<p class="seopress-help">
			<?php
			/* translators: %s: link documentation */
				echo wp_kses_post( sprintf( __( 'Learn more about the <strong>Article schema</strong> from the <a href="%s" target="_blank">Google official documentation website</a>', 'wp-seopress-pro' ), 'https://developers.google.com/search/docs/data-types/article' ) );
			?>
			<span class="dashicons dashicons-external"></span>
		</p>
	</div>

	<?php if ( '' !== seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogo() ) { ?>
	<div class="seopress-notice is-success">
		<p>
			<?php esc_html_e( 'You have set a publisher logo. Good!', 'wp-seopress-pro' ); ?>
		</p>
	</div>
	<?php } else { ?>
	<div class="seopress-notice is-error">
		<p><span class="dashicons dashicons-no-alt"></span>
			<?php
			/* translators: %s link to settings page */
			echo wp_kses_post( sprintf( __( 'You don\'t have set a <a href="%s">publisher logo</a>. It\'s required for Article content types.', 'wp-seopress-pro' ), esc_url( admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_rich_snippets' ) ) ) );
			?>
		</p>
	</div>

	<?php } ?>
	<p>
		<label for="seopress_pro_rich_snippets_article_type_meta"><?php esc_html_e( 'Select your article type', 'wp-seopress-pro' ); ?></label>
		<select name="seopress_pro_rich_snippets_article_type">
			<option <?php echo selected( 'Article', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="Article">
				<?php esc_html_e( 'Article (generic)', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'AdvertiserContentArticle', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="AdvertiserContentArticle">
				<?php esc_html_e( 'Advertiser Content Article', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'NewsArticle', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="NewsArticle">
				<?php esc_html_e( 'News Article', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'Report', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="Report">
				<?php esc_html_e( 'Report', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'SatiricalArticle', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="SatiricalArticle">
				<?php esc_html_e( 'Satirical Article', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'ScholarlyArticle', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="ScholarlyArticle">
				<?php esc_html_e( 'Scholarly Article', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'SocialMediaPosting', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="SocialMediaPosting">
				<?php esc_html_e( 'Social Media Posting', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'BlogPosting', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="BlogPosting">
				<?php esc_html_e( 'Blog Posting', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'TechArticle', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="TechArticle">
				<?php esc_html_e( 'Tech Article', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'AnalysisNewsArticle', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="AnalysisNewsArticle">
				<?php esc_html_e( 'Analysis News Article', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'AskPublicNewsArticle', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="AskPublicNewsArticle">
				<?php esc_html_e( 'Ask Public News Article', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'BackgroundNewsArticle', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="BackgroundNewsArticle">
				<?php esc_html_e( 'Background News Article', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'OpinionNewsArticle', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="OpinionNewsArticle">
				<?php esc_html_e( 'Opinion News Article', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'ReportageNewsArticle', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="ReportageNewsArticle">
				<?php esc_html_e( 'Reportage News Article', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'ReviewNewsArticle', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="ReviewNewsArticle">
				<?php esc_html_e( 'Review News Article', 'wp-seopress-pro' ); ?>
			</option>
			<option <?php echo selected( 'LiveBlogPosting', $seopress_pro_rich_snippets_article_type, false ); ?>
				value="LiveBlogPosting">
				<?php esc_html_e( 'Live Blog Posting', 'wp-seopress-pro' ); ?>
			</option>
		</select>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_article_title_meta">
			<?php echo wp_kses_post( __( 'Headline <em>(max limit: 110)</em>', 'wp-seopress-pro' ) ); ?>
			<code>headline</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_article_title', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'The headline of the article', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_article_desc_meta">
			<?php esc_html_e( 'Description', 'wp-seopress-pro' ); ?>
			<code>description</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_article_desc', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'The description of the article', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_article_author_meta">
			<?php esc_html_e( 'Post author', 'wp-seopress-pro' ); ?>
			<code>author</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_article_author', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'The author of the article', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_article_img_meta">
			<?php esc_html_e( 'Image', 'wp-seopress-pro' ); ?>
			<code>image</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_article_img', 'image' ); ?>
		<span class="description"><?php esc_html_e( 'The representative image of the article. Only a marked-up image that directly belongs to the article should be specified. ', 'wp-seopress-pro' ); ?><br>
			<?php esc_html_e( 'Default value if empty: Post thumbnail (featured image)', 'wp-seopress-pro' ); ?></span>
		<span class="field-required"><?php esc_html_e( 'Minimum size: 696px wide, JPG, PNG or GIF, crawlable and indexable (default: post thumbnail if available)', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_article_coverage_start_date_meta">
			<?php esc_html_e( 'Coverage Start Date', 'wp-seopress-pro' ); ?>
			<code>coverageStartDate</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_article_coverage_start_date', 'date' ); ?>
		<span class="description"><?php echo wp_kses_post( __( 'e.g. YYYY-MM-DD - To use with <strong>Live Blog Posting</strong> article type only', 'wp-seopress-pro' ) ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_article_coverage_start_time_meta">
			<?php esc_html_e( 'Coverage Start Time', 'wp-seopress-pro' ); ?>
			<code>coverageStartTime</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_article_coverage_start_time', 'time' ); ?>
		<span class="description"><?php echo wp_kses_post( __( 'e.g. HH:MM - To use with <strong>Live Blog Posting</strong> article type only', 'wp-seopress-pro' ) ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_article_coverage_end_date_meta">
			<?php esc_html_e( 'Coverage End Date', 'wp-seopress-pro' ); ?>
			<code>coverageEndDate</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_article_coverage_end_date', 'date' ); ?>
		<span class="description"><?php echo wp_kses_post( __( 'e.g. YYYY-MM-DD - To use with <strong>Live Blog Posting</strong> article type only', 'wp-seopress-pro' ) ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_article_coverage_end_time_meta">
			<?php esc_html_e( 'Coverage End Time', 'wp-seopress-pro' ); ?>
			<code>coverageEndTime</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_article_coverage_end_time', 'time' ); ?>
		<span class="description"><?php echo wp_kses_post( __( 'e.g. HH:MM - To use with <strong>Live Blog Posting</strong> article type only', 'wp-seopress-pro' ) ); ?></span>
	</p>
	<p>
		<label for="seopress_pro_rich_snippets_article_speakable_meta">
			<?php esc_html_e( 'Speakable CSS Selector', 'wp-seopress-pro' ); ?>
			<code>speakableCssSelector</code>
		</label>
		<?php echo seopress_schemas_mapping_array( 'seopress_pro_rich_snippets_article_speakable', 'default' ); ?>
		<span class="description"><?php esc_html_e( 'Addresses content in the annotated pages (such as class attribute)', 'wp-seopress-pro' ); ?></span>
	</p>
</div>
