<?php
/**
 * SEOPress PRO Metaboxes Form.
 *
 * @package SEOPress PRO
 * @subpackage Metaboxes
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Render AI generation button with icon.
 *
 * @param string $data_meta The meta field identifier (e.g., 'title', 'desc', 'fb_title').
 * @param string $tooltip_text The tooltip text to display on hover.
 * @return void
 */
function seopress_pro_render_ai_button( $data_meta, $tooltip_text ) {
	?>
	<span class="seopress-d-flex">
		<button class="seopress_ai_generate_seo_meta seopress-ai-icon-button"
			data-lang="<?php echo seopress_get_current_lang(); ?>"
			data_meta="<?php echo esc_attr( $data_meta ); ?>"
			type="button"
			title="<?php echo esc_attr( $tooltip_text ); ?>"
			style="border:none;background:transparent;padding:6px 12px;min-width:auto;cursor:pointer;transition:background-color 0.2s ease-in-out, border-radius 0.2s ease-in-out;"
			onmouseover="this.style.backgroundColor='#f0f0f1';this.style.borderRadius='4px';this.querySelector('svg').style.transform='rotate(-15deg) scale(1.1)';"
			onmouseout="this.style.backgroundColor='transparent';this.style.borderRadius='0';this.querySelector('svg').style.transform='rotate(0deg) scale(1)';">
			<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block;transition:transform 0.2s ease-in-out;">
				<path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72"/><path d="m14 7 3 3"/><path d="M5 6v4"/><path d="M19 14v4"/><path d="M10 2v2"/><path d="M7 8H3"/><path d="M21 16h-4"/><path d="M11 3H9"/>
			</svg>
		</button>
	</span>
	<?php
}

/**
 * Add AI log to Titles settings tab.
 *
 * @param string $pagenow The current page.
 */
function seopress_pro_titles_title_tab_before( $pagenow ) {
	if ( '1' == seopress_get_toggle_option( 'ai' ) ) {
		if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow || 'term.php' === $pagenow || 'edit-tags.php' === $pagenow ) { ?>
		<div id="seopress_ai_generate_seo_meta_log" style="display:none"></div>
			<?php
		}
	}
}
add_filter( 'seopress_titles_title_tab_before', 'seopress_pro_titles_title_tab_before', 10 );

/**
 * Add Generate meta title with AI button to input meta title.
 *
 * @param string $pagenow The current page.
 */
function seopress_pro_titles_title_input_before( $pagenow ) {
	if ( '1' == seopress_get_toggle_option( 'ai' ) ) {
		if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow || 'term.php' === $pagenow || 'edit-tags.php' === $pagenow ) {
			seopress_pro_render_ai_button( 'title', __( 'Generate meta title with AI', 'wp-seopress-pro' ) );
		}
	}
}
add_filter( 'seopress_titles_title_input_before', 'seopress_pro_titles_title_input_before', 10 );

/**
 * Add Generate meta description with AI button to input meta description.
 *
 * @param string $pagenow The current page.
 */
function seopress_pro_titles_meta_desc_input_before( $pagenow ) {
	if ( '1' == seopress_get_toggle_option( 'ai' ) ) {
		if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow || 'term.php' === $pagenow || 'edit-tags.php' === $pagenow ) {
			seopress_pro_render_ai_button( 'desc', __( 'Generate meta description with AI', 'wp-seopress-pro' ) );
		}
	}
}
add_filter( 'seopress_titles_meta_desc_input_before', 'seopress_pro_titles_meta_desc_input_before', 10 );

/**
 * Add AI generation button before Facebook Title input.
 *
 * @param string $pagenow The current admin page.
 * @return void
 */
function seopress_pro_social_fb_title_input_before( $pagenow ) {
	if ( '1' == seopress_get_toggle_option( 'ai' ) ) {
		if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
			seopress_pro_render_ai_button( 'fb_title', __( 'Generate Facebook title with AI', 'wp-seopress-pro' ) );
		}
	}
}
add_filter( 'seopress_social_fb_title_input_before', 'seopress_pro_social_fb_title_input_before', 10 );

/**
 * Add AI generation button before Facebook Description input.
 *
 * @param string $pagenow The current admin page.
 * @return void
 */
function seopress_pro_social_fb_desc_input_before( $pagenow ) {
	if ( '1' == seopress_get_toggle_option( 'ai' ) ) {
		if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
			seopress_pro_render_ai_button( 'fb_desc', __( 'Generate Facebook description with AI', 'wp-seopress-pro' ) );
		}
	}
}
add_filter( 'seopress_social_fb_desc_input_before', 'seopress_pro_social_fb_desc_input_before', 10 );

/**
 * Add AI generation button before Twitter/X Title input.
 *
 * @param string $pagenow The current admin page.
 * @return void
 */
function seopress_pro_social_twitter_title_input_before( $pagenow ) {
	if ( '1' == seopress_get_toggle_option( 'ai' ) ) {
		if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
			seopress_pro_render_ai_button( 'twitter_title', __( 'Generate X title with AI', 'wp-seopress-pro' ) );
		}
	}
}
add_filter( 'seopress_social_twitter_title_input_before', 'seopress_pro_social_twitter_title_input_before', 10 );

/**
 * Add AI generation button before Twitter/X Description input.
 *
 * @param string $pagenow The current admin page.
 * @return void
 */
function seopress_pro_social_twitter_desc_input_before( $pagenow ) {
	if ( '1' == seopress_get_toggle_option( 'ai' ) ) {
		if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
			seopress_pro_render_ai_button( 'twitter_desc', __( 'Generate X description with AI', 'wp-seopress-pro' ) );
		}
	}
}
add_filter( 'seopress_social_twitter_desc_input_before', 'seopress_pro_social_twitter_desc_input_before', 10 );

/**
 * Add Google News / Video Sitemap tabs to our SEO metabox.
 *
 * @param array  $seo_tabs The SEO tabs.
 * @param string $typenow The current post type.
 * @param string $pagenow The current page.
 *
 * @return array
 */
function seopress_pro_metabox_seo_tabs( $seo_tabs, $typenow = '', $pagenow = '' ) {
	if ( function_exists( 'seopress_get_toggle_option' ) && '1' == seopress_get_toggle_option( 'news' ) ) {
		if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
			if ( 'seopress_404' !== $typenow ) {
				$seo_tabs['news-tab'] = '<li><a href="#tabs-5">' . esc_html__( 'Google News', 'wp-seopress-pro' ) . '</a></li>';
			}
		}
	}
	if ( function_exists( 'seopress_get_toggle_option' ) && '1' == seopress_get_toggle_option( 'xml-sitemap' ) && '1' === seopress_pro_get_service( 'SitemapOptionPro' )->getSitemapVideoEnable() ) {
		if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
			if ( 'seopress_404' !== $typenow ) {
				$seo_tabs['video-tab'] = '<li><a href="#tabs-6">' . esc_html__( 'Video Sitemap', 'wp-seopress-pro' ) . '</a></li>';
			}
		}
	}
	return $seo_tabs;
}
add_filter( 'seopress_metabox_seo_tabs', 'seopress_pro_metabox_seo_tabs', 10, 3 );

/**
 * Add Google News / Video Sitemap content tabs to our SEO metabox.
 *
 * @param string $typenow The current post type.
 * @param string $pagenow The current page.
 * @param array  $data_attr The data attributes.
 * @param array  $seo_tabs The SEO tabs.
 */
function seopress_pro_seo_metabox_after_content( $typenow, $pagenow, $data_attr, $seo_tabs ) {
	$seopress_news_disabled  = get_post_meta( $data_attr['current_id'], '_seopress_news_disabled', true );
	$seopress_video_disabled = get_post_meta( $data_attr['current_id'], '_seopress_video_disabled', true );
	$seopress_video          = get_post_meta( $data_attr['current_id'], '_seopress_video', false );

	if ( function_exists( 'seopress_get_toggle_option' ) && '1' == seopress_get_toggle_option( 'news' ) ) {
		if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
			if ( 'seopress_404' !== $typenow ) {
				if ( array_key_exists( 'news-tab', $seo_tabs ) ) {
					?>
			<div id="tabs-5">
				<p>
					<label for="seopress_news_disabled_meta" id="seopress_news_disabled">
						<input type="checkbox" name="seopress_news_disabled" id="seopress_news_disabled_meta"
							value="yes" <?php echo checked( $seopress_news_disabled, 'yes', false ); ?>
						/>
						<?php esc_html_e( 'Exclude this post from Google News Sitemap?', 'wp-seopress-pro' ); ?>
					</label>
				</p>
			</div>
					<?php
				}
			}
		}
	}
	if ( function_exists( 'seopress_get_toggle_option' ) && '1' == seopress_get_toggle_option( 'xml-sitemap' ) && '1' === seopress_pro_get_service( 'SitemapOptionPro' )->getSitemapVideoEnable() ) {
		if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
			if ( 'seopress_404' !== $typenow ) {
				// Init $seopress_video array if empty.
				if ( empty( $seopress_video ) ) {
					$seopress_video = array( '0' => array( '' ) );
				}

				$count = $seopress_video[0];

				$total = '';
				if ( is_array( $count ) ) {
					end( $count );
					$total = key( $count );
				}

				if ( array_key_exists( 'video-tab', $seo_tabs ) ) {
					?>
			<div id="tabs-6">
				<p>
					<?php esc_html_e( 'YouTube videos are automatically added when you create / save a post, page or post type.', 'wp-seopress-pro' ); ?>
				</p>
				<p>
					<label for="seopress_video_disabled_meta" id="seopress_video_disabled">
						<input type="checkbox" name="seopress_video_disabled" id="seopress_video_disabled_meta"
							value="yes" <?php echo checked( $seopress_video_disabled, 'yes', false ); ?>
						/>
						<?php esc_html_e( 'Exclude this post from Video Sitemap?', 'wp-seopress-pro' ); ?>
					</label>
					<span class="description"><?php esc_html_e( 'If your post is set to noindex, it will be automatically excluded from the sitemap.', 'wp-seopress-pro' ); ?></span>
				</p>
				<div id="wrap-videos"
					data-count="<?php echo $total; ?>">
					<?php
					foreach ( $seopress_video[0] as $key => $value ) {
						$check_url             = isset( $seopress_video[0][ $key ]['url'] ) ? $seopress_video[0][ $key ]['url'] : '';
						$check_internal_video  = isset( $seopress_video[0][ $key ]['internal_video'] ) ? $seopress_video[0][ $key ]['internal_video'] : null;
						$check_title           = isset( $seopress_video[0][ $key ]['title'] ) ? $seopress_video[0][ $key ]['title'] : null;
						$check_desc            = isset( $seopress_video[0][ $key ]['desc'] ) ? $seopress_video[0][ $key ]['desc'] : null;
						$check_thumbnail       = isset( $seopress_video[0][ $key ]['thumbnail'] ) ? $seopress_video[0][ $key ]['thumbnail'] : '';
						$check_duration        = isset( $seopress_video[0][ $key ]['duration'] ) ? $seopress_video[0][ $key ]['duration'] : null;
						$check_rating          = isset( $seopress_video[0][ $key ]['rating'] ) ? $seopress_video[0][ $key ]['rating'] : null;
						$check_view_count      = isset( $seopress_video[0][ $key ]['view_count'] ) ? $seopress_video[0][ $key ]['view_count'] : null;
						$check_tag             = isset( $seopress_video[0][ $key ]['tag'] ) ? $seopress_video[0][ $key ]['tag'] : null;
						$check_family_friendly = isset( $seopress_video[0][ $key ]['family_friendly'] ) ? $seopress_video[0][ $key ]['family_friendly'] : null;
						?>

					<div class="video">
						<h3 class="accordion-section-title" tabindex="0"><?php esc_html_e( 'Video ', 'wp-seopress-pro' ); ?>
							<?php echo esc_html( $check_title ); ?>
						</h3>
						<div class="accordion-section-content">
							<p>
								<label
									for="seopress_video[<?php echo esc_attr( $key ); ?>][url_meta]"><?php esc_html_e( 'Video URL (required)', 'wp-seopress-pro' ); ?></label>
								<input
									id="seopress_video[<?php echo esc_attr( $key ); ?>][url_meta]"
									type="text" class="components-text-control__input"
									name="seopress_video[<?php echo esc_attr( $key ); ?>][url]"
									placeholder="<?php esc_html_e( 'Enter your video URL', 'wp-seopress-pro' ); ?>"
									aria-label="<?php esc_html_e( 'Video URL', 'wp-seopress-pro' ); ?>"
									value="<?php echo esc_url( $check_url ); ?>" />
							</p>
							<p class="internal_video">
								<label
									for="seopress_video[<?php echo esc_attr( $key ); ?>][internal_video_meta]"
									id="seopress_video[<?php echo esc_attr( $key ); ?>][internal_video]">
									<input type="checkbox"
										name="seopress_video[<?php echo esc_attr( $key ); ?>][internal_video]"
										id="seopress_video[<?php echo esc_attr( $key ); ?>][internal_video_meta]"
										value="yes" <?php echo checked( $check_internal_video, 'yes', false ); ?>
									/>
									<?php esc_html_e( 'NOT an external video (e.g. video hosting on YouTube, Vimeo, Wistia...)? Check this if your video is hosting on this server.', 'wp-seopress-pro' ); ?>
								</label>
							</p>
							<p>
								<label
									for="seopress_video[<?php echo esc_attr( $key ); ?>][title_meta]"><?php esc_html_e( 'Video Title (required)', 'wp-seopress-pro' ); ?></label>
								<input
									id="seopress_video[<?php echo esc_attr( $key ); ?>][title_meta]"
									type="text" class="components-text-control__input"
									name="seopress_video[<?php echo esc_attr( $key ); ?>][title]"
									placeholder="<?php esc_html_e( 'Enter your video title', 'wp-seopress-pro' ); ?>"
									aria-label="<?php esc_html_e( 'Video title', 'wp-seopress-pro' ); ?>"
									value="<?php echo esc_attr( $check_title ); ?>" />
								<span class="description"><?php esc_html_e( 'Default: title tag, if not available, post title.', 'wp-seopress-pro' ); ?></span>
							</p>
							<p>
								<label
									for="seopress_video[<?php echo esc_attr( $key ); ?>][desc_meta]"><?php esc_html_e( 'Video Description (required)', 'wp-seopress-pro' ); ?></label>
								<textarea
									id="seopress_video[<?php echo esc_attr( $key ); ?>][desc_meta]"
									name="seopress_video[<?php echo esc_attr( $key ); ?>][desc]"
									class="components-text-control__input"
									placeholder="<?php esc_html_e( 'Enter your video description', 'wp-seopress-pro' ); ?>"
									aria-label="<?php esc_html_e( 'Video description', 'wp-seopress-pro' ); ?>"><?php echo esc_html( $check_desc ); ?></textarea>
								<span class="description"><?php esc_html_e( '2048 characters max.; default: meta description. If not available, use the beginning of the post content.', 'wp-seopress-pro' ); ?></span>
							</p>
							<p>
								<label
									for="seopress_video[<?php echo esc_attr( $key ); ?>][thumbnail_meta]"><?php esc_html_e( 'Video Thumbnail (required)', 'wp-seopress-pro' ); ?></label>

								<input
									id="seopress_video[<?php echo esc_attr( $key ); ?>][thumbnail_meta]"
									class="seopress_video_thumbnail_meta components-text-control__input"
									type="text"
									name="seopress_video[<?php echo esc_attr( $key ); ?>][thumbnail]"
									placeholder="<?php esc_html_e( 'Select your video thumbnail', 'wp-seopress-pro' ); ?>"
									value="<?php echo esc_url( $check_thumbnail ); ?>" />


								<input
									class="<?php echo esc_attr( seopress_btn_secondary_classes() ); ?> seopress_video_thumbnail_upload seopress_media_upload"
									type="button"
									aria-label="<?php esc_html_e( 'Video Thumbnail', 'wp-seopress-pro' ); ?>"
									value="<?php esc_html_e( 'Upload an Image', 'wp-seopress-pro' ); ?>" />
								<span class="description">
									<?php esc_html_e( 'Minimum size: 160x90px (1920x1080 max), JPG, PNG or GIF formats. Default: your post featured image.', 'wp-seopress-pro' ); ?>
								</span>
							</p>
							<p>
								<label
									for="seopress_video[<?php echo esc_attr( $key ); ?>][duration_meta]"><?php esc_html_e( 'Video Duration (recommended)', 'wp-seopress-pro' ); ?></label>
								<input
									id="seopress_video[<?php echo esc_attr( $key ); ?>][duration_meta]"
									type="number" step="1" min="0" max="28800"
									name="seopress_video[<?php echo esc_attr( $key ); ?>][duration]"
									placeholder="<?php esc_html_e( 'Duration in seconds', 'wp-seopress-pro' ); ?>"
									aria-label="<?php esc_html_e( 'Video duration', 'wp-seopress-pro' ); ?>"
									value="<?php echo esc_attr( $check_duration ); ?>" />
								<span class="description"><?php esc_html_e( 'The duration of the video in seconds. Value must be between 0 and 28800 (8 hours).', 'wp-seopress-pro' ); ?></span>
							</p>
							<p>
								<label
									for="seopress_video[<?php echo esc_attr( $key ); ?>][rating_meta]"><?php esc_html_e( 'Video Rating', 'wp-seopress-pro' ); ?></label>
								<input
									id="seopress_video[<?php echo esc_attr( $key ); ?>][rating_meta]"
									type="number" step="0.1" min="0" max="5"
									name="seopress_video[<?php echo esc_attr( $key ); ?>][rating]"
									placeholder="<?php esc_html_e( 'Video rating', 'wp-seopress-pro' ); ?>"
									aria-label="<?php esc_html_e( 'Video rating', 'wp-seopress-pro' ); ?>"
									value="<?php echo esc_attr( $check_rating ); ?>" />
								<span class="description"><?php esc_html_e( 'Allowed values are float numbers in the range 0.0 to 5.0.', 'wp-seopress-pro' ); ?></span>
							</p>
							<p>
								<label
									for="seopress_video[<?php echo esc_attr( $key ); ?>][view_count_meta]"><?php esc_html_e( 'View count', 'wp-seopress-pro' ); ?></label>
								<input
									id="seopress_video[<?php echo esc_attr( $key ); ?>][view_count_meta]"
									type="number"
									name="seopress_video[<?php echo esc_attr( $key ); ?>][view_count]"
									placeholder="<?php esc_html_e( 'Number of views', 'wp-seopress-pro' ); ?>"
									aria-label="<?php esc_html_e( 'View count', 'wp-seopress-pro' ); ?>"
									value="<?php echo esc_attr( $check_view_count ); ?>" />
							</p>
							<p>
								<label
									for="seopress_video[<?php echo esc_attr( $key ); ?>][tag_meta]"><?php esc_html_e( 'Video tags', 'wp-seopress-pro' ); ?></label>
								<input
									id="seopress_video[<?php echo esc_attr( $key ); ?>][tag_meta]"
									type="text" class="components-text-control__input"
									name="seopress_video[<?php echo esc_attr( $key ); ?>][tag]"
									placeholder="<?php esc_html_e( 'Enter your video tags', 'wp-seopress-pro' ); ?>"
									aria-label="<?php esc_html_e( 'Video tags', 'wp-seopress-pro' ); ?>"
									value="<?php echo esc_attr( $check_tag ); ?>" />
								<span class="description"><?php esc_html_e( '32 tags max., separate tags with commas. Default: target keywords + post tags if available.', 'wp-seopress-pro' ); ?></span>
							</p>
							<p class="family-friendly">
								<label
									for="seopress_video[<?php echo esc_attr( $key ); ?>][family_friendly_meta]"
									id="seopress_video[<?php echo esc_attr( $key ); ?>][family_friendly]">
									<input type="checkbox"
										name="seopress_video[<?php echo esc_attr( $key ); ?>][family_friendly]"
										id="seopress_video[<?php echo esc_attr( $key ); ?>][family_friendly_meta]"
										value="yes" <?php echo checked( $check_family_friendly, 'yes', false ); ?>
									/>
									<?php esc_html_e( 'NOT family friendly?', 'wp-seopress-pro' ); ?>
								</label>
								<span class="description"><?php esc_html_e( 'The video will be available only to users with SafeSearch turned off.', 'wp-seopress-pro' ); ?></span>
							</p>
							<p><a href="#"
									class="remove-video components-button editor-post-trash is-tertiary is-destructive"><?php esc_html_e( 'Remove video', 'wp-seopress-pro' ); ?></a>
							</p>
						</div>
					</div>
						<?php
					}
					?>
				</div>
				<p>
					<a href="#" id="add-video" class="add-video <?php echo esc_attr( seopress_btn_secondary_classes() ); ?>">
						<?php esc_html_e( 'Add video', 'wp-seopress-pro' ); ?>
					</a>
				</p>
			</div>
					<?php
				}
			}
		}
	}
}
add_action( 'seopress_seo_metabox_after_content', 'seopress_pro_seo_metabox_after_content', 10, 4 );

/**
 * Save our Custom Breadcrumbs / Google News / Video sitemap meta.
 *
 * @param int   $post_id The post ID.
 * @param array $seo_tabs The SEO tabs.
 */
function seopress_pro_seo_metabox_save( $post_id, $seo_tabs ) {
	if ( ! empty( $_POST['seopress_robots_breadcrumbs'] ) ) {
		update_post_meta( $post_id, '_seopress_robots_breadcrumbs', sanitize_text_field( $_POST['seopress_robots_breadcrumbs'] ) );
	} else {
		delete_post_meta( $post_id, '_seopress_robots_breadcrumbs' );
	}

	if ( in_array( 'news-tab', $seo_tabs ) ) {
		if ( isset( $_POST['seopress_news_disabled'] ) ) {
			update_post_meta( $post_id, '_seopress_news_disabled', 'yes' );
		} else {
			delete_post_meta( $post_id, '_seopress_news_disabled', '' );
		}
	}
	if ( in_array( 'video-tab', $seo_tabs ) ) {
		if ( isset( $_POST['seopress_video_disabled'] ) ) {
			update_post_meta( $post_id, '_seopress_video_disabled', 'yes' );
		} else {
			delete_post_meta( $post_id, '_seopress_video_disabled', '' );
		}
		if ( ! empty( $_POST['seopress_video'] ) ) {
			update_post_meta( $post_id, '_seopress_video', $_POST['seopress_video'] );
		} else {
			delete_post_meta( $post_id, '_seopress_video' );
		}
	}
}
add_action( 'seopress_seo_metabox_save', 'seopress_pro_seo_metabox_save', 10, 2 );

/**
 * Save our Custom Breadcrumbs term meta.
 *
 * @param int   $term_id The term ID.
 * @param array $term    The term.
 */
function seopress_pro_seo_metabox_term_save( $term_id, $term ) {
	if ( ! empty( $term['seopress_robots_breadcrumbs'] ) ) {
		update_term_meta( $term_id, '_seopress_robots_breadcrumbs', sanitize_text_field( $term['seopress_robots_breadcrumbs'] ) );
	} else {
		delete_term_meta( $term_id, '_seopress_robots_breadcrumbs' );
	}
}
add_action( 'seopress_seo_metabox_term_save', 'seopress_pro_seo_metabox_term_save', 10, 2 );

/**
 * Add Custom Breadcrumbs to Robots tab, SEO metabox.
 *
 * @param string $pagenow The current page.
 * @param array  $data_attr The data attributes.
 */
function seopress_pro_titles_title_tab_after( $pagenow, $data_attr ) {
	if ( 'term.php' === $pagenow || 'edit-tags.php' === $pagenow ) {
		$seopress_robots_breadcrumbs = get_term_meta( $data_attr['termId'], '_seopress_robots_breadcrumbs', true );
	} else {
		$seopress_robots_breadcrumbs = get_post_meta( $data_attr['current_id'], '_seopress_robots_breadcrumbs', true );
	}
	?>
	<p>
		<label for="seopress_robots_breadcrumbs_meta"><?php esc_html_e( 'Custom breadcrumbs', 'wp-seopress-pro' ); ?></label>
		<span class="description"><?php esc_html_e( 'Enter a custom value, useful if your title is too long', 'wp-seopress-pro' ); ?></span>
	</p>
	<p>
		<input id="seopress_robots_breadcrumbs_meta" type="text" name="seopress_robots_breadcrumbs"
			class="components-text-control__input"
			placeholder="<?php /* translators: %s: default post title */ printf( esc_html__( 'Current breadcrumbs: %s', 'wp-seopress-pro' ), esc_html( $data_attr['title'] ) ); ?>"
			aria-label="<?php esc_html_e( 'Custom breadcrumbs', 'wp-seopress-pro' ); ?>"
			value="<?php echo esc_html( $seopress_robots_breadcrumbs ); ?>" />
	</p>
	<?php
}
add_action( 'seopress_titles_title_tab_after', 'seopress_pro_titles_title_tab_after', 10, 2 );