<?php
/**
 * The header for our theme
 *
 * @package HBL
 * @since 1.0.0
 */
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link screen-reader-text" href="#primary"><?php esc_html_e( 'Skip to content', 'hbl' ); ?></a>

<div id="page" class="site">
	
	<?php
	// Check if Elementor header exists
	if ( function_exists( 'elementor_theme_do_location' ) && elementor_theme_do_location( 'header' ) ) {
		// Elementor header is being used
	} else {
		// Default header
		?>
		<header id="masthead" class="site-header">
			<nav class="navbar navbar-expand-lg navbar-light bg-light">
				<div class="container">
					<div class="navbar-brand">
						<?php
						if ( has_custom_logo() ) {
							the_custom_logo();
						} else {
							?>
							<a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
								<span class="site-title"><?php bloginfo( 'name' ); ?></span>
							</a>
							<?php
						}
						?>
					</div>

					<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#primaryNav" aria-controls="primaryNav" aria-expanded="false" aria-label="<?php esc_attr_e( 'Toggle navigation', 'hbl' ); ?>">
						<span class="navbar-toggler-icon"></span>
					</button>

					<div class="collapse navbar-collapse" id="primaryNav">
						<?php
						wp_nav_menu( array(
							'theme_location'  => 'primary',
							'container'       => false,
							'menu_class'      => 'navbar-nav ms-auto',
							'fallback_cb'     => '__return_false',
							'items_wrap'      => '<ul id="%1$s" class="%2$s">%3$s</ul>',
							'depth'           => 2,
							'walker'          => new Bootstrap_NavWalker(),
						) );
						?>
					</div>
				</div>
			</nav>
		</header>
		<?php
	}
	?>

	<div id="content" class="site-content">

