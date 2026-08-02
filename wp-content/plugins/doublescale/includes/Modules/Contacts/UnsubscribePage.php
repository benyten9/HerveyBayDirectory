<?php
/**
 * Unsubscribe Page Handler
 *
 * Renders a styled unsubscribe confirmation page
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts;

defined( 'ABSPATH' ) || exit;

/**
 * UnsubscribePage class
 */
class UnsubscribePage {

	/**
	 * Instance
	 *
	 * @var UnsubscribePage
	 */
	private static $instance;

	/**
	 * Get instance
	 *
	 * @return UnsubscribePage
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		add_action( 'template_redirect', array( $this, 'handle_unsubscribe_page' ), 5 );
		add_filter( 'doublescale_email_unsubscribe_redirect', array( $this, 'get_unsubscribe_url' ) );
		add_filter( 'doublescale_sms_unsubscribe_redirect', array( $this, 'get_unsubscribe_url' ) );
		add_filter( 'doublescale_whatsapp_unsubscribe_redirect', array( $this, 'get_unsubscribe_url' ) );
	}

	/**
	 * Get unsubscribe page URL
	 *
	 * @return string
	 */
	public function get_unsubscribe_url() {
		return add_query_arg( 'doublescale_unsubscribe_success', '1', home_url() );
	}

	/**
	 * Handle unsubscribe page display
	 */
	public function handle_unsubscribe_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public unsubscribe confirmation page; redirect comes from a signed unsubscribe link and the success flag is purely informational.
		if ( ! isset( $_GET['doublescale_unsubscribe_success'] ) ) {
			return;
		}

		// Prevent other templates from loading
		status_header( 200 );
		nocache_headers();

		$this->render_unsubscribe_page();
		exit;
	}

	/**
	 * Render unsubscribe page
	 */
	private function render_unsubscribe_page() {
		$site_name = get_bloginfo( 'name' );

		// Register/enqueue the styled unsubscribe-success stylesheet (shared with
		// SubscriptionManager). Safe to wp_register_style here even after the
		// wp_enqueue_scripts hook fired — wp_print_styles below honors registered handles.
		if ( ! wp_style_is( 'doublescale-unsubscribe-success', 'registered' ) ) {
			wp_register_style(
				'doublescale-unsubscribe-success',
				DOUBLESCALE_PLUGIN_URL . 'assets/css/subscription/unsubscribe-success.css',
				array(),
				DOUBLESCALE_VERSION
			);
		}
		wp_enqueue_style( 'doublescale-unsubscribe-success' );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex, nofollow">
			<title>
			<?php
				/* translators: %s: site name */
				echo esc_html( sprintf( __( 'Unsubscribed - %s', 'doublescale' ), $site_name ) );
			?>
			</title>
			<?php wp_head(); ?>
		</head>
	<body>
		<div class="unsubscribe-container">
			<div class="success-icon">
				<svg viewBox="0 0 24 24">
					<polyline class="checkmark" points="20 6 9 17 4 12"></polyline>
				</svg>
			</div>

			<h1><?php esc_html_e( 'You\'ve Been Unsubscribed', 'doublescale' ); ?></h1>

			<p class="message">
				<?php
				/* translators: %s: site name */
				echo esc_html( sprintf( __( 'You have been successfully unsubscribed from %s. You will no longer receive these communications.', 'doublescale' ), $site_name ) );
				?>
			</p>

			<a href="<?php echo esc_url( home_url() ); ?>" class="home-button">
				<?php esc_html_e( 'Back to Homepage', 'doublescale' ); ?>
			</a>

			<p class="footer-note">
				<?php
				/* translators: %s: site name */
				echo esc_html( sprintf( __( 'If this was a mistake, you can re-subscribe by contacting %s.', 'doublescale' ), $site_name ) );
				?>
			</p>
		</div>
		<?php wp_footer(); ?>
	</body>
	</html>
		<?php
	}
}

// Initialize
UnsubscribePage::instance();
