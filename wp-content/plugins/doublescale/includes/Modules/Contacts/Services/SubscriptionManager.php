<?php
/**
 * Class Subscription Manage
 *
 * This class is responsible for handling the Subscription Manage
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Services;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Models\ListModel;
use DoubleScale\Core\Settings\Settings;

/**
 * Subscription Manage
 */
class SubscriptionManager {

	/**
	 * Class Instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SubscriptionManager
	 */
	private static $instance;

	/**
	 * Merge Tags Manager
	 *
	 * @var MergeTagsManager
	 */
	public $merge_tags_manager;

	/**
	 * SubscriptionManager Instance.
	 *
	 * Instantiates or reuses an instance
	 *
	 * @since 1.0.0
	 *
	 * @return SubscriptionManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'wp_loaded', array( $this, 'manage' ) );

		// Register scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts' ) );

		// Register AJAX actions.
		add_action( 'wp_ajax_doublescale_unsubscribe', array( $this, 'unsubscribe_ajax' ) );
		add_action( 'wp_ajax_nopriv_doublescale_unsubscribe', array( $this, 'unsubscribe_ajax' ) );
		add_action( 'wp_ajax_doublescale_resubscribe', array( $this, 'resubscribe_ajax' ) );
		add_action( 'wp_ajax_nopriv_doublescale_resubscribe', array( $this, 'resubscribe_ajax' ) );
	}

	/**
	 * Manage
	 */
	public function manage() {
		$this->subscribe();
		$this->unsubscribe();
	}

	/**
	 * Get head
	 *
	 * @return string
	 */
	public function get_head() {
		// Ensure our handles exist before enqueueing — this method runs early
		// (on wp_loaded), before the `wp_enqueue_scripts` action where
		// register_scripts() would normally fire.
		$this->register_scripts();

		ob_start();
		?>
		<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
			"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
		<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
		<head>
			<meta http-equiv="Content-type" content="text/html; charset=utf-8"/>
			<meta http-equiv="Imagetoolbar" content="No"/>
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Request Unsubscribe', 'doublescale' ); ?></title>
			<meta name="robots" content="noindex">
			<?php
				wp_enqueue_style( 'doublescale-public' );
				wp_head();
			?>
		</head>
		<body class="doublescale-body">
		<?php
		return ob_get_clean();
	}

	/**
	 * Get footer
	 *
	 * @return string
	 */
	public function get_footer() {
		ob_start();
		wp_enqueue_script( 'doublescale-public' );
		wp_footer();
		?>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Subscribe
	 */
	public function subscribe() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public subscription link, nonce not applicable
		if ( ! isset( $_GET['doublescale-subscribe'] ) || $_GET['doublescale-subscribe'] !== '1' || ! isset( $_GET['id'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public subscription link, nonce not applicable
		$id      = sanitize_text_field( wp_unslash( $_GET['id'] ) );
		$contact = ContactModel::get_by_hash_id( $id );
		if ( ! $contact ) {
			return;
		}

		$contact->email_status = 'subscribed';
		$contact->save();

		\DoubleScale\Modules\Activities\Models\ActivityModel::create(
			array(
				'contact_id'    => $contact->id,
				'activity_type' => 'note',
				'data'          => array(
					'title' => __( 'Subscribed', 'doublescale' ),
					'type'  => 'system',
					'note'  => __( 'Contact subscribed to the email list.', 'doublescale' ),
				),
				'user_id'       => null,
			)
		);

		$double_optin       = Settings::get( 'double_optin', array() );
		$after_confirmation = $double_optin['after_confirmation'] ?? 'message';
		if ( 'message' === $after_confirmation ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from get_subscribe_message() is escaped internally.
			echo $this->get_subscribe_message();
			exit;
		}

		$redirect_url = $double_optin['confirmation_redirect'] ?? home_url();
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirect URL is from admin settings, may be external
		wp_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}

	/**
	 * Get Subscribe Message
	 *
	 * @return string
	 */
	public function get_subscribe_message() {
		$double_optin = Settings::get( 'double_optin', array() );
		$message      = $double_optin['confirmation_message'] ?? '';

		ob_start();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from get_head() is escaped internally.
		echo $this->get_head();
		?>
		<div class="doublescale-subscribe-message-container">
			<div class="doublescale-subscribe-message">
				<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Either wp_kses_post() filtered message or get_default_message() which is escaped internally.
					echo ! empty( $message ) ? wp_kses_post( $message ) : $this->get_default_message();
				?>
			</div>
		</div>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from get_footer() is escaped internally.
		echo $this->get_footer();
		return ob_get_clean();
	}

	/**
	 * Default message
	 *
	 * @return string
	 */
	public function get_default_message() {
		ob_start();
		?>
		<h2><?php esc_html_e( 'Subscription Confirmed', 'doublescale' ); ?></h2>
		<p><?php esc_html_e( 'You have successfully subscribed to our mailing list.', 'doublescale' ); ?></p>
		<div>
			<p><?php esc_html_e( 'Thank you for subscribing!', 'doublescale' ); ?></p>
			<a href="<?php echo esc_url( home_url() ); ?>"><?php esc_html_e( 'Go to Home', 'doublescale' ); ?></a>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Unsubscribe AJAX
	 */
	public function unsubscribe_ajax() {
		check_ajax_referer( 'doublescale-unsubscribe', 'nonce' );

		$id              = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$channel         = isset( $_POST['channel'] ) ? sanitize_text_field( wp_unslash( $_POST['channel'] ) ) : 'email';
		$reason          = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : 'other';
		$unsubscribe_all = ! empty( $_POST['unsubscribe_all'] );

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID', 'doublescale' ) ) );
		}

		// Only the work sits in the try: wp_send_json_*() ends the request with
		// wp_die(), which throws wherever a die handler throws (tests, some
		// hosts), and a catch-all around it answered twice — success, then an
		// error — which the browser cannot parse.
		try {
			$contact = ContactModel::get_by_hash_id( $id );
			if ( ! $contact ) {
				$html = null;
			} else {
				$html = $this->apply_unsubscribe_preferences( $contact, $channel, $reason, $unsubscribe_all );
			}
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		if ( null === $html ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID', 'doublescale' ) ) );
		}

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * "Resubscribe" on the confirmation page: turn the channel back on and
	 * rejoin the lists the contact left with "unsubscribe from all".
	 */
	public function resubscribe_ajax() {
		check_ajax_referer( 'doublescale-resubscribe', 'nonce' );

		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$channel = isset( $_POST['channel'] ) ? sanitize_text_field( wp_unslash( $_POST['channel'] ) ) : 'email';
		$lists   = isset( $_POST['lists'] ) ? sanitize_text_field( wp_unslash( $_POST['lists'] ) ) : '';

		$contact = $id ? ContactModel::get_by_hash_id( $id ) : null;
		if ( ! $contact ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID', 'doublescale' ) ) );
		}

		$contact->subscribe_to_channel( $channel );

		// Only lists the contact already belongs to: the field comes from the
		// browser, so it can bring back a list but never add a new one.
		$member_ids = array_map( 'intval', $contact->lists()->pluck( $contact->lists()->getRelated()->getQualifiedKeyName() )->all() );
		foreach ( array_filter( array_map( 'intval', explode( ',', $lists ) ) ) as $list_id ) {
			if ( in_array( $list_id, $member_ids, true ) && ! $contact->is_subscribed_to_list( $list_id ) ) {
				$contact->resubscribe_to_list( $list_id );
			}
		}

		ob_start();
		$this->render_styled_unsubscribe_page( false, false, null, $channel, array(), true );
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * Apply what the preference form asked for, and render the page to show.
	 *
	 * @param ContactModel $contact         The contact who submitted the form.
	 * @param string       $channel         Channel the form was opened for.
	 * @param string       $reason          Reason given.
	 * @param bool         $unsubscribe_all Whether "unsubscribe from all" was ticked.
	 * @return string The confirmation page.
	 */
	private function apply_unsubscribe_preferences( $contact, $channel, $reason, $unsubscribe_all ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by unsubscribe_ajax().
		$list_status = isset( $_POST['list_status'] ) && is_array( $_POST['list_status'] )
			? wp_unslash( $_POST['list_status'] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$public_list_ids = ListModel::query()
			->where( 'is_public', 1 )
			->pluck( 'id' )
			->map(
				function ( $list_id ) {
					return (int) $list_id;
				}
			)
			->all();

		// The browser submits the list checkboxes as they were rendered, even
		// when "unsubscribe from all" is ticked. Honouring them here would
		// re-subscribe a contact who just asked to be left alone, so the
		// global opt-out below owns the list rows instead.
		$lists_to_reconcile = $unsubscribe_all ? array() : $public_list_ids;

		// The global opt-out also leaves every list; remember which ones so
		// "Resubscribe" on the confirmation page can put them back.
		$relation         = $contact->lists();
		$lists_before_all = $unsubscribe_all
			? array_values(
				array_filter(
					array_map( 'intval', $relation->pluck( $relation->getRelated()->getQualifiedKeyName() )->all() ),
					array( $contact, 'is_subscribed_to_list' )
				)
			)
			: array();

		foreach ( $lists_to_reconcile as $list_id ) {
			$desired_status = isset( $list_status[ $list_id ] )
				? sanitize_text_field( (string) $list_status[ $list_id ] )
				: 'unsubscribed';

			$is_member = $contact->lists()->whereKey( $list_id )->exists();

			if ( 'subscribed' === $desired_status ) {
				if ( ! $contact->is_subscribed_to_list( $list_id ) ) {
					$contact->resubscribe_to_list( $list_id );
				}
			} elseif ( $is_member && $contact->is_subscribed_to_list( $list_id ) ) {
				$contact->unsubscribe_from_list( $list_id, $reason );
			}
		}

		if ( $unsubscribe_all ) {
			$source_type = null;
			$source_id   = null;
			$mode        = null;

			$mode_map = array(
				'email'    => 1,
				'sms'      => 2,
				'whatsapp' => 3,
			);
			$mode     = $mode_map[ $channel ] ?? null;

			if ( $mode && class_exists( '\DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel' ) ) {
				$recent_tracking = \DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel::where( 'contact_id', $contact->id )
					->where( 'mode', $mode )
					->orderBy( 'created_at', 'desc' )
					->first();

				if ( $recent_tracking && $recent_tracking->source_type && $recent_tracking->source_id ) {
					$source_type = $recent_tracking->source_type;
					$source_id   = $recent_tracking->source_id;
				}
			}

			if ( $mode ) {
				$contact->unsubscribe_from_mode( $mode, $reason, $source_type, $source_id );
			}
		} elseif ( ! $contact->is_subscribed_to_channel( $channel ) ) {
			$contact->subscribe_to_channel( $channel );
		}

		$contact->refresh();

		ob_start();
		$this->render_styled_unsubscribe_page( false, $unsubscribe_all, $contact, $channel, $lists_before_all );
		return ob_get_clean();
	}

	/**
	 * Unsubscribe
	 */
	public function unsubscribe() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public unsubscribe link, nonce not applicable
		if ( ! isset( $_GET['doublescale-unsubscribe'] ) || $_GET['doublescale-unsubscribe'] !== '1' || ! isset( $_GET['id'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public unsubscribe link, nonce not applicable
		$id = sanitize_text_field( wp_unslash( $_GET['id'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public unsubscribe link, nonce not applicable
		$channel = isset( $_GET['channel'] ) ? sanitize_text_field( wp_unslash( $_GET['channel'] ) ) : 'email';

		$contact = ContactModel::get_by_hash_id( $id );
		if ( ! $contact ) {
			return;
		}

		$public_lists = ListModel::query()
			->where( 'is_public', 1 )
			->orderBy( 'name' )
			->get();

		$has_public_lists = $public_lists->isNotEmpty();

		if ( ! $contact->is_subscribed_to_channel( $channel ) && ! $has_public_lists ) {
			$this->render_styled_unsubscribe_page( true, true, $contact, $channel );
			exit;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML from get_preference_form() is escaped internally.
		echo $this->get_preference_form( $contact, $channel, $public_lists );
		exit;
	}

	/**
	 * Get Unsubscribe Message
	 *
	 * @param string $channel Channel.
	 * @return string
	 */
	public function get_unsubscribe_message( $channel = 'email' ) {
		$channel_label = ContactModel::get_channel_label( $channel );

		ob_start();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_head() method
		echo $this->get_head();
		?>
		<div class="doublescale-unsubscribe-message-container">
			<div class="doublescale-unsubscribe-message">
				<p>
				<?php
					/* translators: %s: channel name (email, Sms, WhatsApp) */
					printf( esc_html__( 'You have already unsubscribed from %s.', 'doublescale' ), esc_html( $channel_label ) );
				?>
				</p>
				<a href="<?php echo esc_url( home_url() ); ?>"><?php esc_html_e( 'Go to Home', 'doublescale' ); ?></a>
			</div>
		</div>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_footer() method
		echo $this->get_footer();
		return ob_get_clean();
	}

	/**
	 * Get preference form with per-list subscription controls.
	 *
	 * @param ContactModel                            $contact      Contact.
	 * @param string                                  $channel      Channel (email, sms, whatsapp).
	 * @param \DoubleScale\Vendor\Illuminate\Support\Collection|array    $public_lists Public lists collection.
	 *
	 * @return string
	 */
	public function get_preference_form( $contact, $channel = 'email', $public_lists = array() ) {
		$channel_label = ContactModel::get_channel_label( $channel );
		$site_name     = get_bloginfo( 'name' );
		$globally_out  = ! $contact->is_subscribed_to_channel( $channel );

		$this->register_scripts();

		wp_enqueue_style( 'doublescale-unsubscribe-form' );
		wp_enqueue_script( 'doublescale-unsubscribe-form' );
		wp_add_inline_script(
			'doublescale-unsubscribe-form',
			'window.doublescaleUnsubscribeForm = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'i18n'    => array(
						'processing'   => __( 'Processing...', 'doublescale' ),
						'confirm'      => __( 'Save Preferences', 'doublescale' ),
						'genericError' => __( 'An error occurred. Please try again.', 'doublescale' ),
					),
				), JSON_HEX_TAG | JSON_HEX_AMP
			) . ';',
			'before'
		);

		ob_start();
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
				echo esc_html( sprintf( __( 'Email Preferences - %s', 'doublescale' ), $site_name ) );
			?>
			</title>
			<?php wp_head(); ?>
		</head>
		<body>
			<?php self::render_site_brand(); ?>
			<div class="unsubscribe-container">
				<div class="icon-wrapper">
					<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
						<path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</div>

				<h3><?php esc_html_e( 'Manage your email preferences', 'doublescale' ); ?></h3>

				<p class="intro-text">
					<?php esc_html_e( 'Choose which lists you want to receive emails from, or unsubscribe from all communications.', 'doublescale' ); ?>
				</p>

				<div class="error-message" id="error-message"></div>

				<form id="doublescale-unsubscribe-form">
					<input type="hidden" name="id" value="<?php echo esc_attr( $contact->hash_id ); ?>">
					<input type="hidden" name="channel" value="<?php echo esc_attr( $channel ); ?>">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'doublescale-unsubscribe' ) ); ?>">
					<input type="hidden" name="action" value="doublescale_unsubscribe">

					<div class="doublescale-form-item">
						<label for="contact_info">
							<?php echo 'email' === $channel ? esc_html__( 'Email', 'doublescale' ) : esc_html__( 'Phone', 'doublescale' ); ?>
						</label>
						<input
							type="text"
							name="contact_info"
							value="<?php echo esc_attr( $this->hide_contact_info( $contact, $channel ) ); ?>"
							disabled
						>
					</div>

					<?php if ( ! empty( $public_lists ) ) : ?>
					<div class="doublescale-form-item">
						<label><?php esc_html_e( 'Email lists', 'doublescale' ); ?></label>
						<div class="doublescale-list-checklist">
							<?php foreach ( $public_lists as $list_item ) : ?>
								<?php
								$is_subscribed = $contact->is_subscribed_to_list( $list_item->id );
								?>
								<label class="doublescale-list-checklist-item">
									<input
										type="checkbox"
										class="list-preference-checkbox"
										data-list-id="<?php echo esc_attr( (string) $list_item->id ); ?>"
										<?php checked( $is_subscribed ); ?>
									>
									<span><?php echo esc_html( $list_item->name ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<div class="doublescale-form-item doublescale-unsubscribe-all">
						<label class="doublescale-list-checklist-item doublescale-unsubscribe-all-item">
							<input
								type="checkbox"
								name="unsubscribe_all"
								id="unsubscribe_all"
								value="1"
								<?php checked( $globally_out ); ?>
							>
							<span>
							<?php
								/* translators: %s: channel name (emails, messages, etc.) */
								printf( esc_html__( 'Unsubscribe from all %s', 'doublescale' ), esc_html( $channel_label ) );
							?>
							</span>
						</label>
					</div>

					<div class="doublescale-form-item" id="reason-field">
						<label for="reason"><?php esc_html_e( 'Reason (optional)', 'doublescale' ); ?></label>
						<div class="doublescale-form-radio-group">
							<label>
								<input type="radio" name="reason" value="spam">
								<?php
									/* translators: %s: channel name (emails, messages, etc.) */
									echo esc_html( sprintf( __( 'I consider these %s to be spam', 'doublescale' ), $channel_label ) );
								?>
							</label>
							<label>
								<input type="radio" name="reason" value="not-interested">
								<?php
									/* translators: %s: channel name (emails, messages, etc.) */
									echo esc_html( sprintf( __( 'I am no longer interested in these %s', 'doublescale' ), $channel_label ) );
								?>
							</label>
							<label>
								<input type="radio" name="reason" value="other" checked>
								<?php esc_html_e( 'Other', 'doublescale' ); ?>
							</label>
						</div>
					</div>

					<button type="submit"><?php esc_html_e( 'Save Preferences', 'doublescale' ); ?></button>
				</form>
			</div>
			<?php self::render_page_note(); ?>

			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get Unsubscribe Form - Styled version
	 *
	 * @param ContactModel $contact Contact.
	 * @param string       $channel Channel (email, sms, whatsapp).
	 *
	 * @return string
	 */
	public function get_unsubscribe_form( $contact, $channel = 'email' ) {
		$channel_label = ContactModel::get_channel_label( $channel );
		$site_name     = get_bloginfo( 'name' );

		// This method is reached on `wp_loaded` (before the `wp_enqueue_scripts`
		// action where `register_scripts()` normally runs). Register the handles
		// lazily here so wp_enqueue_style/script actually adds them to the queue
		// when wp_head() later prints styles.
		$this->register_scripts();

		wp_enqueue_style( 'doublescale-unsubscribe-form' );
		wp_enqueue_script( 'doublescale-unsubscribe-form' );
		wp_add_inline_script(
			'doublescale-unsubscribe-form',
			'window.doublescaleUnsubscribeForm = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'i18n'    => array(
						'processing'   => __( 'Processing...', 'doublescale' ),
						'confirm'      => __( 'Confirm Unsubscribe', 'doublescale' ),
						'genericError' => __( 'An error occurred. Please try again.', 'doublescale' ),
					),
				), JSON_HEX_TAG | JSON_HEX_AMP
			) . ';',
			'before'
		);

		ob_start();
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
				echo esc_html( sprintf( __( 'Unsubscribe - %s', 'doublescale' ), $site_name ) );
			?>
			</title>
			<?php wp_head(); ?>
		</head>
		<body>
			<?php self::render_site_brand(); ?>
			<div class="unsubscribe-container">
				<div class="icon-wrapper">
					<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
						<path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
						<line x1="3" y1="3" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					</svg>
				</div>

				<h3>
				<?php
					/* translators: %s: channel name (email, Sms, WhatsApp) */
					printf( esc_html__( 'Unsubscribe from %s', 'doublescale' ), esc_html( $channel_label ) );
				?>
				</h3>
				
				<p class="intro-text">
					<?php esc_html_e( 'We\'re sorry to see you go. Please let us know why you\'re unsubscribing.', 'doublescale' ); ?>
				</p>

				<div class="error-message" id="error-message"></div>

				<form id="doublescale-unsubscribe-form">
					<input type="hidden" name="id" value="<?php echo esc_attr( $contact->hash_id ); ?>">
					<input type="hidden" name="channel" value="<?php echo esc_attr( $channel ); ?>">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'doublescale-unsubscribe' ) ); ?>">
					<input type="hidden" name="action" value="doublescale_unsubscribe">
					
					<div class="doublescale-form-item">
						<label for="contact_info">
							<?php echo 'email' === $channel ? esc_html__( 'Email', 'doublescale' ) : esc_html__( 'Phone', 'doublescale' ); ?>
						</label>
						<input
							type="text"
							name="contact_info"
							value="<?php echo esc_attr( $this->hide_contact_info( $contact, $channel ) ); ?>"
							disabled
						>
					</div>
					
					<div class="doublescale-form-item">
						<label for="reason"><?php esc_html_e( 'Reason for unsubscribing', 'doublescale' ); ?></label>
						<div class="doublescale-form-radio-group">
							<label>
								<input type="radio" name="reason" value="spam">
								<?php
									/* translators: %s: channel name (emails, messages, etc.) */
									echo esc_html( sprintf( __( 'I consider these %s to be spam', 'doublescale' ), $channel_label ) );
								?>
							</label>
							<label>
								<input type="radio" name="reason" value="not-interested">
								<?php
									/* translators: %s: channel name (emails, messages, etc.) */
									echo esc_html( sprintf( __( 'I am no longer interested in these %s', 'doublescale' ), $channel_label ) );
								?>
							</label>
							<label>
								<input type="radio" name="reason" value="other" checked>
								<?php esc_html_e( 'Other', 'doublescale' ); ?>
							</label>
						</div>
					</div>
					
					<button type="submit"><?php esc_html_e( 'Confirm Unsubscribe', 'doublescale' ); ?></button>
				</form>
			</div>
			<?php self::render_page_note(); ?>

			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Hide contact info (email or phone)
	 *
	 * @param ContactModel $contact Contact.
	 * @param string       $channel Channel.
	 *
	 * @return string
	 */
	public function hide_contact_info( $contact, $channel ) {
		if ( 'email' === $channel ) {
			return $this->hide_contact_email( $contact->email );
		} else {
			// Hide phone number
			$phone = $contact->phone ?? '';
			if ( strlen( $phone ) > 6 ) {
				return substr( $phone, 0, 3 ) . str_repeat( '*', strlen( $phone ) - 6 ) . substr( $phone, -3 );
			}
			return $phone;
		}
	}

	/**
	 * Hide contact email
	 *
	 * @param string $email Email.
	 *
	 * @return string
	 */
	public function hide_contact_email( $email ) {
		$parts = explode( '@', $email );
		$first = substr( $parts[0], 0, 2 );
		$last  = substr( $parts[0], -2 );
		$hide  = str_repeat( '*', strlen( $parts[0] ) - 4 );
		return $first . $hide . $last . '@' . $parts[1];
	}

	/**
	 * The sender above the card: the site's logo (custom logo, else site icon)
	 * or its first letter, and its name, linking home.
	 */
	public static function render_site_brand() {
		$site_name = get_bloginfo( 'name' );
		$logo_id   = (int) get_theme_mod( 'custom_logo' );
		$logo_url  = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		if ( ! $logo_url ) {
			$logo_url = get_site_icon_url( 96 );
		}
		?>
		<a class="ds-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php if ( $logo_url ) : ?>
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="">
			<?php else : ?>
				<span class="ds-brand__mark" aria-hidden="true"><?php echo esc_html( function_exists( 'mb_substr' ) ? mb_substr( $site_name, 0, 1 ) : substr( $site_name, 0, 1 ) ); ?></span>
			<?php endif; ?>
			<span><?php echo esc_html( $site_name ); ?></span>
		</a>
		<?php
	}

	/**
	 * The line under the card: why the reader is on this page.
	 */
	public static function render_page_note() {
		?>
		<p class="ds-page-note">
			<?php
			/* translators: %s: site name */
			echo esc_html( sprintf( __( 'You are seeing this page because you received messages from %s.', 'doublescale' ), get_bloginfo( 'name' ) ) );
			?>
		</p>
		<?php
	}

	/**
	 * Register Scripts
	 */
	public function register_scripts() {
		wp_register_script( 'doublescale-public', DOUBLESCALE_PLUGIN_URL . 'assets/js/public.js', array( 'jquery' ), '1.0.0', true );
		wp_localize_script(
			'doublescale-public',
			'doublescale_public_config',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
		wp_register_style( 'doublescale-public', DOUBLESCALE_PLUGIN_URL . 'assets/css/public.css', array(), '1.0.0' );

		// The success sheet holds the shared page layout; the form adds to it.
		wp_register_style(
			'doublescale-unsubscribe-success',
			DOUBLESCALE_PLUGIN_URL . 'assets/css/subscription/unsubscribe-success.css',
			array(),
			DOUBLESCALE_VERSION
		);
		wp_register_style(
			'doublescale-unsubscribe-form',
			DOUBLESCALE_PLUGIN_URL . 'assets/css/subscription/unsubscribe-form.css',
			array( 'doublescale-unsubscribe-success' ),
			DOUBLESCALE_VERSION
		);
		wp_register_script(
			'doublescale-unsubscribe-form',
			DOUBLESCALE_PLUGIN_URL . 'assets/js/subscription/unsubscribe-form.js',
			array(),
			DOUBLESCALE_VERSION,
			true
		);
	}

	/**
	 * Render styled unsubscribe success page
	 *
	 * @param bool              $already_unsubscribed Whether user was already unsubscribed.
	 * @param bool              $unsubscribed_all     Whether global unsubscribe was applied.
	 * @param ContactModel|null $contact              Contact, for the Resubscribe button.
	 * @param string            $channel              Channel unsubscribed from.
	 * @param int[]             $list_ids             Lists left by the global opt-out, rejoined on Resubscribe.
	 * @param bool              $resubscribed         The page after Resubscribe.
	 */
	private function render_styled_unsubscribe_page( $already_unsubscribed = false, $unsubscribed_all = true, $contact = null, $channel = 'email', $list_ids = array(), $resubscribed = false ) {
		$site_name       = get_bloginfo( 'name' );
		$can_resubscribe = $contact && ( $already_unsubscribed || $unsubscribed_all );
		if ( $resubscribed ) {
			$title = __( 'You\'re Subscribed Again', 'doublescale' );
		} elseif ( $already_unsubscribed ) {
			$title = __( 'Already Unsubscribed', 'doublescale' );
		} elseif ( $unsubscribed_all ) {
			$title = __( 'You\'re Unsubscribed', 'doublescale' );
		} else {
			$title = __( 'Preferences Saved', 'doublescale' );
		}

		// Called via the AJAX response path before `wp_enqueue_scripts` runs;
		// register handles lazily so the enqueue actually sticks.
		$this->register_scripts();
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
			<?php wp_print_styles( 'doublescale-unsubscribe-success' ); ?>
		</head>
		<body>
			<?php self::render_site_brand(); ?>
			<div class="unsubscribe-container">
				<div class="success-icon">
					<svg viewBox="0 0 52 52">
						<polyline class="checkmark" points="14 27 22 35 38 17"/>
					</svg>
				</div>
				
				<h1><?php echo esc_html( $title ); ?></h1>
				
				<p class="message">
					<?php
					if ( $resubscribed ) {
						esc_html_e( 'Welcome back! You will receive our messages again.', 'doublescale' );
					} elseif ( $already_unsubscribed ) {
						esc_html_e( 'You have already unsubscribed from our mailing list.', 'doublescale' );
					} elseif ( $unsubscribed_all ) {
						esc_html_e( 'You have been successfully unsubscribed from our mailing list. We\'re sorry to see you go, but we respect your decision.', 'doublescale' );
					} else {
						esc_html_e( 'Your email preferences have been updated successfully.', 'doublescale' );
					}
					?>
				</p>

				<?php if ( ! $already_unsubscribed && $unsubscribed_all ) : ?>
				<p class="message">
					<?php esc_html_e( 'You will no longer receive emails from us.', 'doublescale' ); ?>
				</p>
				<?php endif; ?>

				<div class="ds-actions">
					<a href="<?php echo esc_url( home_url() ); ?>" class="home-button">
						<?php esc_html_e( 'Back to Home', 'doublescale' ); ?>
					</a>
				</div>

				<?php if ( $can_resubscribe ) : ?>
				<form class="footer-note ds-resubscribe" id="doublescale-resubscribe-form">
					<input type="hidden" name="action" value="doublescale_resubscribe">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'doublescale-resubscribe' ) ); ?>">
					<input type="hidden" name="id" value="<?php echo esc_attr( $contact->hash_id ); ?>">
					<input type="hidden" name="channel" value="<?php echo esc_attr( $channel ); ?>">
					<input type="hidden" name="lists" value="<?php echo esc_attr( implode( ',', array_map( 'intval', $list_ids ) ) ); ?>">
					<span><?php esc_html_e( 'Unsubscribed by mistake?', 'doublescale' ); ?></span>
					<button type="submit" class="ds-link-button"><?php esc_html_e( 'Resubscribe', 'doublescale' ); ?></button>
					<span class="ds-resubscribe-error" role="alert" hidden><?php esc_html_e( 'Something went wrong. Please try again.', 'doublescale' ); ?></span>
				</form>
				<script>
				( function () {
					var form = document.getElementById( 'doublescale-resubscribe-form' );
					form.addEventListener( 'submit', function ( event ) {
						event.preventDefault();
						var button = form.querySelector( 'button' );
						button.disabled = true;
						fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: new FormData( form ) } )
							.then( function ( response ) { return response.json(); } )
							.then( function ( response ) {
								if ( response.success && response.data && response.data.html ) {
									document.open();
									document.write( response.data.html );
									document.close();
									return;
								}
								throw new Error();
							} )
							.catch( function () {
								button.disabled = false;
								form.querySelector( '.ds-resubscribe-error' ).hidden = false;
							} );
					} );
				} )();
				</script>
				<?php elseif ( ! $resubscribed ) : ?>
				<div class="footer-note">
					<?php esc_html_e( 'Changed your mind? You can resubscribe anytime by contacting us.', 'doublescale' ); ?>
				</div>
				<?php endif; ?>
			</div>
			<?php self::render_page_note(); ?>
		</body>
		</html>
		<?php
	}
}
