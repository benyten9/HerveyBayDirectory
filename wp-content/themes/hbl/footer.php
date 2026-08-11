<?php
?>

	</div>

	<?php
	if ( function_exists( 'elementor_theme_do_location' ) && elementor_theme_do_location( 'footer' ) ) {
	} else {
		?>
		<footer id="colophon" class="site-footer">
			<div class="container">
				<?php if ( is_active_sidebar( 'footer-1' ) || is_active_sidebar( 'footer-2' ) || is_active_sidebar( 'footer-3' ) || is_active_sidebar( 'footer-4' ) ) : ?>
					<div class="row g-4 mb-4">
						<?php if ( is_active_sidebar( 'footer-1' ) ) : ?>
							<div class="col-lg-3 col-md-6">
								<?php dynamic_sidebar( 'footer-1' ); ?>
							</div>
						<?php endif; ?>

						<?php if ( is_active_sidebar( 'footer-2' ) ) : ?>
							<div class="col-lg-3 col-md-6">
								<?php dynamic_sidebar( 'footer-2' ); ?>
							</div>
						<?php endif; ?>

						<?php if ( is_active_sidebar( 'footer-3' ) ) : ?>
							<div class="col-lg-3 col-md-6">
								<?php dynamic_sidebar( 'footer-3' ); ?>
							</div>
						<?php endif; ?>

						<?php if ( is_active_sidebar( 'footer-4' ) ) : ?>
							<div class="col-lg-3 col-md-6">
								<?php dynamic_sidebar( 'footer-4' ); ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="site-info text-center py-3 border-top">
					<p class="mb-0">
						<?php
						printf(
							esc_html__( '&copy; %1$s %2$s. All rights reserved.', 'hbl' ),
							date( 'Y' ),
							get_bloginfo( 'name' )
						);
						?>
					</p>
				</div>
			</div>
		</footer>
		<?php
	}
	?>

</div>

<?php wp_footer(); ?>

</body>
</html>

