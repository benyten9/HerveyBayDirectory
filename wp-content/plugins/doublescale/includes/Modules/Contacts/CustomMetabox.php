<?php
/**
 * Class CustomMetabox
 *
 * This class is responsible for handling the custom metabox
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * CustomMetabox class
 */
final class CustomMetabox {

	/**
	 * Instance
	 *
	 * @var CustomMetabox
	 */
	private static $instance;

	/**
	 * Get instance
	 *
	 * @return CustomMetabox
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		add_action( 'edd_view_order_details_sidebar_after', array( $this, 'edd_order_metabox' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 99, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register and conditionally enqueue the order-metabox stylesheet on
	 * WooCommerce / EDD order edit screens.
	 */
	public function register_assets( $hook_suffix ): void {
		wp_register_style(
			'doublescale-contact-order-metabox',
			DOUBLESCALE_PLUGIN_URL . 'assets/css/admin/contact-order-metabox.css',
			array(),
			DOUBLESCALE_VERSION
		);

		$is_order_screen = false;
		$screen          = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen ) {
			$is_order_screen =
				'woocommerce_page_wc-orders' === $screen->id
				|| 'shop_order' === $screen->id
				|| 'shop_order' === $screen->post_type
				|| false !== strpos( (string) $hook_suffix, 'edd-payment-history' );
		}

		if ( $is_order_screen ) {
			wp_enqueue_style( 'doublescale-contact-order-metabox' );
		}
	}

	/**
	 * Add meta boxes
	 *
	 * @param string   $post_type Post type
	 * @param \WP_Post $post Post object
	 *
	 * @return void
	 */
	public function add_meta_boxes( $post_type, $post ) {
		if ( ! in_array( $post_type, array( 'woocommerce_page_wc-orders', 'shop_order' ) ) ) {
			return;
		}

		if ( $post_type == 'woocommerce_page_wc-orders' ) {
			$order_id = $post->get_id();
		} else {
			$order_id = $post->ID;
		}

		$order = wc_get_order( $order_id );
		$email = $order->get_billing_email();

		$contact = ContactModel::where( 'email', $email )->first();
		if ( ! $contact ) {
			return;
		}

		add_meta_box(
			'doublescale-contact-metabox',
			__( 'DoubleScale contact', 'doublescale' ),
			function () use ( $contact ) {
				$this->generate_contact_metabox_html( $contact->email );
			},
			$post_type,
			'side',
			'default',
			$contact
		);
	}

	/**
	 * EDD Order Metabox
	 *
	 * @param int $payment_id Payment ID
	 */
	public function edd_order_metabox( $payment_id ) {
		$payment = edd_get_payment( $payment_id );
		if ( ! $payment ) {
			return;
		}

		$email = $payment->email;
		if ( ! $email ) {
			return;
		}

		$this->generate_contact_metabox_html( $email );
	}

	/**
	 * Generate contact metabox HTML for a given email.
	 *
	 * @param string $email The email address to fetch the contact details.
	 */
	function generate_contact_metabox_html( $email ) {
		// Fetch the contact based on email
		$contact = ContactModel::where( 'email', $email )->first();
		if ( ! $contact ) {
			return;
		}

		$total_emails         = $contact->campaign_emails->count();
		$total_opened_emails  = $contact->campaign_emails->where( 'opened', 1 )->count();
		$total_clicked_emails = $contact->campaign_emails->where( 'clicked', 1 )->count();
		$first_name           = $contact->first_name ?? '-';
		$last_name            = $contact->last_name ?? '-';
		$name                 = "{$first_name} {$last_name}";

		$avatar_url = $contact->avatar_url;

		$profile_url = admin_url( 'admin.php?page=doublescale&path=contacts&id=' . $contact->id );

		?>
		<div class="doublescale-edd-order-metabox postbox">
			<h2 class="hndle"><?php esc_html_e( 'DoubleScale contact', 'doublescale' ); ?></h2>
			<div class="inside">
				<a class="doublescale-avatar" href="<?php echo esc_url( $profile_url ); ?>">
					<img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $contact->first_name . ' ' . $contact->last_name ); ?>" class="doublescale-avatar-img">
					<p class="doublescale-contact-name">
						<?php echo esc_html( $first_name . ' ' . $last_name ); ?>
					</p>
				</a>
				<div class="doublescale-contact-emails">
					<p>
						<strong><?php esc_html_e( 'Emails:', 'doublescale' ); ?></strong>
						<?php echo esc_html( $total_emails ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Opened:', 'doublescale' ); ?></strong>
						<?php echo esc_html( $total_opened_emails ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Clicked:', 'doublescale' ); ?></strong>
						<?php echo esc_html( $total_clicked_emails ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}
}
