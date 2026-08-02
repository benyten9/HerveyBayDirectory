<?php
defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Default email template for SEOPress Pro
 *
 * This template can be overridden by copying it to yourtheme/seopress/emails/default.php
 *
 * @package SEOPress\Templates
 * @version 1.0.0
 */

if ( ! function_exists( 'seopress_get_default_email_template' ) ) {
	/**
	 * Get the default email template HTML
	 *
	 * @param string $content The email content
	 * @param array  $args    Additional arguments
	 * @return string
	 */
	function seopress_get_default_email_template( $content, $args = array() ) {
		// Default arguments
		$defaults = array(
			'header_image' => '',
			'footer_text'  => sprintf(
				/* translators: %s: Site name */
				esc_html__( 'Sent from %s', 'wp-seopress-pro' ),
				get_bloginfo( 'name' )
			),
			'text_color'   => '#444444',
			'bg_color'     => '#f5f5f5',
		);

		$args = wp_parse_args( $args, $defaults );

		// Start output buffering
		ob_start();
		?>
		<!DOCTYPE html>
		<html lang="<?php echo esc_attr( get_locale() ); ?>">
		<head>
			<meta http-equiv="Content-Type" content="text/html; charset=<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
		</head>
		<body style="background-color: <?php echo esc_attr( $args['bg_color'] ); ?>; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
			<table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: <?php echo esc_attr( $args['bg_color'] ); ?>; padding: 20px;">
				<tr>
					<td align="center">
						<table border="0" cellpadding="0" cellspacing="0" width="600" style="background-color: #ffffff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
							<!-- Header -->
							<tr>
								<td align="center" style="padding: 20px;">
									<?php if ( ! empty( $args['header_image'] ) ) : ?>
										<img src="<?php echo esc_url( $args['header_image'] ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" style="max-width: 300px; height: auto;">
									<?php else : ?>
										<h1 style="color: <?php echo esc_attr( $args['text_color'] ); ?>; margin: 0; font-size: 24px;">
											<?php
											if ( ! empty( $args['header_subject'] ) ) {
												echo esc_html( $args['header_subject'] );
											} else {
												echo esc_html( get_bloginfo( 'name' ) );
											}
											?>
										</h1>
									<?php endif; ?>
								</td>
							</tr>

							<!-- Content -->
							<tr>
								<td style="padding: 20px; color: <?php echo esc_attr( $args['text_color'] ); ?>; font-size: 16px; line-height: 1.5;">
									<?php echo wp_kses_post( $content ); ?>
								</td>
							</tr>

							<!-- Footer -->
							<tr>
								<td style="padding: 20px; border-top: 1px solid #eee; color: #666; font-size: 12px; text-align: center;">
									<?php echo wp_kses_post( $args['footer_text'] ); ?>
									<?php if ( ! empty( $args['unsubscribe_url'] ) ) : ?>
										<br>
										<a href="<?php echo esc_url( $args['unsubscribe_url'] ); ?>" style="color: #999; text-decoration: underline;"><?php esc_html_e( 'Unsubscribe from these emails', 'wp-seopress-pro' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}
}

if ( ! function_exists( 'seopress_get_email_template' ) ) {
	/**
	 * Get the email template HTML with content
	 *
	 * @param string $content The email content
	 * @param array  $args    Additional arguments
	 * @return string
	 */
	function seopress_get_email_template( $content, $args = array() ) {
		// Allow themes/plugins to override the default template
		$template = apply_filters( 'seopress_email_template', 'default', $content, $args );

		if ( 'default' === $template ) {
			return seopress_get_default_email_template( $content, $args );
		}

		return $template;
	}
}
