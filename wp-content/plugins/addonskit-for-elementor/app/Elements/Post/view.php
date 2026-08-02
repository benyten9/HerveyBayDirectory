<?php
/**
 * @author  wpWax
 * @since   1.0
 * @version 1.0
 */

namespace wpWax\Theme\Elementor;

use AddonskitForElementor\Utils\Helper;

$thumb_size = 'wpwaxtheme-size2';
$query      = $data['query'];
$columns    = $data['number_of_columns'];
$show_btn   = ( isset( $data['show_more_button'] ) && 'yes' === $data['show_more_button'] ) ?  true : false ;
$blog_url   = get_option( 'page_for_posts', false );
$btn_text   = isset( $data['show_more_button_text'] ) ? $data['show_more_button_text'] : esc_html__( 'See all the guides', 'addonskit-for-elementor' );

if ( $query->have_posts() ): ?>

	<div class="akfe-row">

		<?php
		while ( $query->have_posts() ):
			$query->the_post();
			$get_cat_ob = get_the_category();
			$cat_link   = get_category_link( $get_cat_ob[0]->cat_ID );
			$cat_name   = $get_cat_ob[0]->name;
			?>

			<div class="akfe-col-lg-<?php echo esc_attr( $columns ); ?> akfe-col-md-6 akfe-col-12">

				<div id="post-<?php echo esc_attr(get_the_ID()); ?>" <?php post_class( 'akfe-theme-blog-each' ); ?>>

					<div class="akfe-theme-blog-card blog-grid-card">

						<?php if ( has_post_thumbnail() ): ?>

							<div class="akfe-theme-blog-card__thumbnail">

								<a href="<?php echo esc_url(get_permalink()); ?>"><?php the_post_thumbnail( [ 420, 260 ] ); ?></a>

							</div>

						<?php endif; ?>

						<div class="akfe-theme-blog-card__details">

							<div class="akfe-theme-blog-card__content">

								<h2 class="akfe-theme-blog-card__title">

									<a href="<?php echo esc_url(get_permalink()); ?>" class="entry-title" rel="bookmark"><?php echo esc_html(get_the_title()); ?></a>

								</h2>

								<?php if ( $data['show_expert'] ): ?>

									<div class="akfe-theme-blog-card__summary entry-summary"><?php echo wp_kses_post(get_the_excerpt()); ?></div>

								<?php endif; ?>

							</div>

							<?php if ( $data['show_date'] || $data['show_category'] || $data['show_reading_time'] ): ?>

								<div class="akfe-theme-blog-card__meta">

									<div class="akfe-theme-blog-card__meta-list">

										<ul class="list-unstyled">

											<?php if ( $data['show_date'] ): ?>

												<li class="akfe-theme-blog-card_date-meta">

													<span class="akfe-theme-blog-card_date-meta-text updated published"><?php echo esc_html(get_the_time( get_option( 'date_format' ) )); ?></span>

												</li>

											<?php endif; ?>

											<?php if ( $data['show_reading_time'] ): ?>

												<li class="akfe-theme-blog-card_reading-time-meta"><?php echo wp_kses_post(Helper::get_reading_time( get_the_content(), 'span' )); ?></li>

											<?php endif; ?>

											<?php if ( $data['show_category'] ): ?>

												<li class="akfe-theme-blog-card_category-meta">

													<span class="akfe-theme-blog-card_category-meta-label"><?php esc_html_e( 'In', 'addonskit-for-elementor' ); ?> </span>

													<a href="<?php echo esc_url( $cat_link ); ?>" class="akfe-theme-blog-cat"><?php echo esc_html( $cat_name ); ?></a>

												</li>

											<?php endif; ?>

										</ul>

									</div>

								</div>

							<?php endif; ?>

						</div>

					</div>

				</div>

			</div>

		<?php endwhile;?>

	</div>

	<?php if( $blog_url && $show_btn ) : ?>

		<div class="akfe-theme-more-btn">

			<a href="<?php echo esc_url(get_permalink( $blog_url )); ?>">

				<span class="akfe-theme-more-btn__text"><?php echo esc_html( $btn_text ); ?></span>

				<?php directorist_icon( 'fas fa-long-arrow-alt-right' ); ?>

			</a>

		</div>

	<?php endif; ?>

<?php else: ?>

	<div><?php esc_html_e( 'Currently there are no posts', 'addonskit-for-elementor' );?></div>

<?php endif;?>

<?php wp_reset_postdata();?>