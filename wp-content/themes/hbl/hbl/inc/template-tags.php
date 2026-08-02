<?php
/**
 * Custom template tags for this theme
 *
 * @package HBL
 * @since 1.0.0
 */

if ( ! function_exists( 'hbl_posted_on' ) ) :
	/**
	 * Prints HTML with meta information for the current post-date/time.
	 */
	function hbl_posted_on() {
		$time_string = '<time class="entry-date published updated" datetime="%1$s">%2$s</time>';
		if ( get_the_time( 'U' ) !== get_the_modified_time( 'U' ) ) {
			$time_string = '<time class="entry-date published" datetime="%1$s">%2$s</time><time class="updated" datetime="%3$s">%4$s</time>';
		}

		$time_string = sprintf(
			$time_string,
			esc_attr( get_the_date( DATE_W3C ) ),
			esc_html( get_the_date() ),
			esc_attr( get_the_modified_date( DATE_W3C ) ),
			esc_html( get_the_modified_date() )
		);

		$posted_on = sprintf(
			'<a href="%s" rel="bookmark">%s</a>',
			esc_url( get_permalink() ),
			$time_string
		);

		echo '<span class="posted-on">' . $posted_on . '</span>';
	}
endif;

if ( ! function_exists( 'hbl_posted_by' ) ) :
	/**
	 * Prints HTML with meta information for the current author.
	 */
	function hbl_posted_by() {
		$byline = sprintf(
			'<span class="author vcard"><a class="url fn n" href="%s">%s</a></span>',
			esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ),
			esc_html( get_the_author() )
		);

		echo '<span class="byline"> ' . $byline . '</span>';
	}
endif;

if ( ! function_exists( 'hbl_entry_footer' ) ) :
	/**
	 * Prints HTML with meta information for the categories, tags and comments.
	 */
	function hbl_entry_footer() {
		// Hide category and tag text for pages.
		if ( 'post' === get_post_type() ) {
			$categories_list = get_the_category_list( esc_html__( ', ', 'hbl' ) );
			if ( $categories_list ) {
				printf( '<span class="cat-links"><i class="bi bi-folder"></i> ' . esc_html__( 'Posted in %1$s', 'hbl' ) . '</span>', $categories_list );
			}

			$tags_list = get_the_tag_list( '', esc_html_x( ', ', 'list item separator', 'hbl' ) );
			if ( $tags_list ) {
				printf( '<span class="tags-links"><i class="bi bi-tags"></i> ' . esc_html__( 'Tagged %1$s', 'hbl' ) . '</span>', $tags_list );
			}
		}

		if ( ! is_single() && ! post_password_required() && ( comments_open() || get_comments_number() ) ) {
			echo '<span class="comments-link"><i class="bi bi-chat"></i> ';
			comments_popup_link(
				sprintf(
					wp_kses(
						__( 'Leave a Comment<span class="screen-reader-text"> on %s</span>', 'hbl' ),
						array(
							'span' => array(
								'class' => array(),
							),
						)
					),
					wp_kses_post( get_the_title() )
				)
			);
			echo '</span>';
		}

		edit_post_link(
			sprintf(
				wp_kses(
					__( 'Edit <span class="screen-reader-text">%s</span>', 'hbl' ),
					array(
						'span' => array(
							'class' => array(),
						),
					)
				),
				wp_kses_post( get_the_title() )
			),
			'<span class="edit-link"><i class="bi bi-pencil"></i> ',
			'</span>'
		);
	}
endif;

if ( ! function_exists( 'hbl_post_thumbnail' ) ) :
	/**
	 * Displays an optional post thumbnail.
	 */
	function hbl_post_thumbnail() {
		if ( post_password_required() || is_attachment() || ! has_post_thumbnail() ) {
			return;
		}

		if ( is_singular() ) :
			?>

			<div class="post-thumbnail">
				<?php the_post_thumbnail( 'hbl-featured', array( 'class' => 'img-fluid' ) ); ?>
			</div>

		<?php else : ?>

			<a class="post-thumbnail" href="<?php the_permalink(); ?>" aria-hidden="true" tabindex="-1">
				<?php
				the_post_thumbnail(
					'hbl-featured',
					array(
						'alt' => the_title_attribute(
							array(
								'echo' => false,
							)
						),
						'class' => 'img-fluid',
					)
				);
				?>
			</a>

			<?php
		endif;
	}
endif;

