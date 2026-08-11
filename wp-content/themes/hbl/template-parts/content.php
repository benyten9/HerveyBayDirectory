<?php
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'mb-5 card' ); ?>>
	<?php if ( has_post_thumbnail() ) : ?>
		<div class="post-thumbnail">
			<a href="<?php the_permalink(); ?>">
				<?php the_post_thumbnail( 'hbl-featured', array( 'class' => 'card-img-top' ) ); ?>
			</a>
		</div>
	<?php endif; ?>

	<div class="card-body">
		<header class="entry-header">
			<?php
			if ( is_singular() ) :
				the_title( '<h1 class="entry-title card-title">', '</h1>' );
			else :
				the_title( '<h2 class="entry-title card-title"><a href="' . esc_url( get_permalink() ) . '" rel="bookmark">', '</a></h2>' );
			endif;

			if ( 'post' === get_post_type() ) :
				?>
				<div class="entry-meta text-muted small mb-3">
					<span class="posted-on">
						<i class="bi bi-calendar"></i>
						<a href="<?php echo esc_url( get_permalink() ); ?>" rel="bookmark">
							<?php echo get_the_date(); ?>
						</a>
					</span>
					<span class="byline ms-2">
						<i class="bi bi-person"></i>
						<a href="<?php echo esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ); ?>">
							<?php echo get_the_author(); ?>
						</a>
					</span>
					<?php if ( ! post_password_required() && ( comments_open() || get_comments_number() ) ) : ?>
						<span class="comments-link ms-2">
							<i class="bi bi-chat"></i>
							<?php comments_popup_link( __( 'Leave a comment', 'hbl' ), __( '1 Comment', 'hbl' ), __( '% Comments', 'hbl' ) ); ?>
						</span>
					<?php endif; ?>
				</div>
				<?php
			endif;
			?>
		</header>

		<div class="entry-content card-text">
			<?php
			if ( is_singular() ) {
				the_content();
			} else {
				the_excerpt();
			}

			wp_link_pages( array(
				'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'hbl' ),
				'after'  => '</div>',
			) );
			?>
		</div>

		<footer class="entry-footer mt-3">
			<?php
			$categories_list = get_the_category_list( ', ' );
			if ( $categories_list ) {
				printf( '<span class="cat-links"><i class="bi bi-folder"></i> %s</span>', $categories_list );
			}

			$tags_list = get_the_tag_list( '', ', ' );
			if ( $tags_list ) {
				printf( '<span class="tags-links ms-3"><i class="bi bi-tags"></i> %s</span>', $tags_list );
			}
			?>
		</footer>
	</div>
</article>

