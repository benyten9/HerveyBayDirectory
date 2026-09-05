<?php
/**
 * SEOPress PRO — AI Assistant default state and store migration.
 *
 * Two changes ship together here.
 *
 * The AI Assistant used to be opt-in: the option was unset on a fresh install,
 * so the sidebar never rendered until the user found the toggle at the very
 * bottom of the AI settings tab. Nothing surfaced the feature before that, which
 * meant most installs never saw it at all. It now defaults to enabled. The
 * assistant still needs a configured provider to do anything (see
 * seopress_ai_assistant_is_enabled()), so turning it on has no effect on sites
 * without an API key — it only removes a step for the ones that do set one up.
 *
 * Its state also moves from `seopress_pro_option_name['seopress_ai_assistant_enable']`
 * to the feature module store (`seopress_toggle['toggle-ai-assistant']`), which
 * is what the Dashboard feature cards read and write. That gives the assistant a
 * card of its own, and lets the AI settings tab reuse the shared FeatureToggle
 * component instead of a bespoke field.
 *
 * Two entry points:
 * - seopress_pro_activation() for fresh activations;
 * - a one-shot admin_init migration for installs that update in place, since
 *   WordPress does not fire the activation hook on plugin updates.
 *
 * Both preserve an explicit choice: a site that turned the assistant off keeps
 * it off, because the old value is carried over rather than overwritten.
 *
 * What neither can tell apart is "never heard of this feature" from "does not
 * want it": both land on enabled. For a site that already has an AI provider
 * configured, that means a panel it never asked for shows up in the editor after
 * a routine update. Those installs get the one-time notice at the bottom of this
 * file, which says what appeared and where the switch is. Sites with no provider
 * see no change at all, so they get no notice either.
 *
 * @package SEOPress PRO
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( ! defined( 'SEOPRESS_PRO_AI_ASSISTANT_DEFAULT_VERSION' ) ) {
	define( 'SEOPRESS_PRO_AI_ASSISTANT_DEFAULT_VERSION', 1 );
}

if ( ! defined( 'SEOPRESS_PRO_AI_ASSISTANT_NOTICE_OPTION' ) ) {
	define( 'SEOPRESS_PRO_AI_ASSISTANT_NOTICE_OPTION', 'seopress_pro_ai_assistant_notice' );
}

/**
 * Give the AI Assistant a state in the feature module store.
 *
 * Carries over the pre-10.2 setting when there is one, and defaults to enabled
 * otherwise. Never touches an install that already has a value in the new store.
 *
 * @since 10.2.0
 *
 * @return string One of:
 *                'skipped'  the site already had a value, nothing was written;
 *                'migrated' the pre-10.2 setting was carried over as is;
 *                'enabled'  no preference existed, so the assistant was turned on.
 */
function seopress_pro_set_ai_assistant_default() {
	$toggle = get_option( 'seopress_toggle' );
	if ( ! is_array( $toggle ) ) {
		$toggle = array();
	}

	// Already answered — by the Dashboard card, the settings tab, the wizard, or
	// a previous run.
	if ( array_key_exists( 'toggle-ai-assistant', $toggle ) ) {
		return 'skipped';
	}

	$options = get_option( 'seopress_pro_option_name' );
	if ( ! is_array( $options ) ) {
		$options = array();
	}

	$result = 'enabled';

	// A stored `false` from before 10.2 means the user explicitly said no, so
	// array_key_exists() is what decides here, not empty().
	if ( array_key_exists( 'seopress_ai_assistant_enable', $options ) ) {
		$toggle['toggle-ai-assistant'] = empty( $options['seopress_ai_assistant_enable'] ) ? '0' : '1';
		$result                        = 'migrated';

		unset( $options['seopress_ai_assistant_enable'] );
		update_option( 'seopress_pro_option_name', $options );
	} else {
		$toggle['toggle-ai-assistant'] = '1';
	}

	update_option( 'seopress_toggle', $toggle );

	return $result;
}

add_action( 'admin_init', 'seopress_pro_migrate_ai_assistant_default', 20 );
/**
 * One-shot migration for installs that update in place.
 *
 * Guarded by a dedicated option so it runs once and never re-enables the
 * assistant for someone who turns it off afterwards.
 *
 * @since 10.2.0
 *
 * @return void
 */
function seopress_pro_migrate_ai_assistant_default() {
	$option_key = 'seopress_pro_ai_assistant_default_version';
	$current    = (int) get_option( $option_key, 0 );
	$target     = (int) SEOPRESS_PRO_AI_ASSISTANT_DEFAULT_VERSION;

	if ( $current >= $target ) {
		return;
	}

	// The assistant loader only comes in when the free plugin is active, and the
	// provider check below needs it. Bail instead of burning the one-shot guard:
	// the migration runs on the next admin request, when the pair is whole again.
	if ( ! function_exists( 'seopress_ai_assistant_is_enabled' ) ) {
		return;
	}

	$result = seopress_pro_set_ai_assistant_default();

	update_option( $option_key, $target, false );

	// Queue the explanation only for the installs that actually see something
	// change: the assistant was switched on here rather than by the user
	// ('enabled', not 'migrated'), and a provider is already configured, so the
	// panel renders on the next page load. seopress_ai_assistant_is_enabled()
	// reads the toggle written just above, which is now '1', so what it really
	// answers at this point is "is there a usable API key".
	if ( 'enabled' === $result && seopress_ai_assistant_is_enabled() ) {
		update_option( SEOPRESS_PRO_AI_ASSISTANT_NOTICE_OPTION, '1', false );
	}
}

add_action( 'admin_notices', 'seopress_pro_ai_assistant_default_notice' );
// SEOPress screens drop every admin_notices callback and re-emit their own
// action instead (see seopress_remove_other_notices()), so both hooks are needed
// to cover the whole admin. Only one of them ever fires on a given screen.
add_action( 'seopress_admin_notices', 'seopress_pro_ai_assistant_default_notice' );
/**
 * Tell the site that the AI Assistant turned itself on, and where the switch is.
 *
 * Shown once per site rather than once per user: it describes a one-off change
 * to the install, and the first administrator to acknowledge it settles the
 * question for the others.
 *
 * @since 10.2.0
 *
 * @return void
 */
function seopress_pro_ai_assistant_default_notice() {
	if ( '1' !== (string) get_option( SEOPRESS_PRO_AI_ASSISTANT_NOTICE_OPTION, '' ) ) {
		return;
	}

	if ( ! function_exists( 'seopress_capability' ) || ! current_user_can( seopress_capability( 'manage_options', 'notice' ) ) ) {
		return;
	}

	// Already found the switch and turned the assistant back off: there is
	// nothing left to explain, so drop the notice instead of showing it.
	if ( ! function_exists( 'seopress_get_toggle_option' ) || '1' !== seopress_get_toggle_option( 'ai-assistant' ) ) {
		delete_option( SEOPRESS_PRO_AI_ASSISTANT_NOTICE_OPTION );

		return;
	}

	$dismiss_url  = seopress_pro_ai_assistant_notice_url();
	$settings_url = seopress_pro_ai_assistant_notice_url( 'settings' );
	?>
<div class="notice notice-info" style="position:relative;">
	<a href="<?php echo esc_url( $dismiss_url ); ?>" class="notice-dismiss" style="text-decoration:none;">
		<span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice', 'wp-seopress-pro' ); ?></span>
	</a>
	<p>
		<strong><?php esc_html_e( 'The AI Assistant is now enabled by default', 'wp-seopress-pro' ); ?></strong>
	</p>
	<p>
		<?php esc_html_e( 'A chat panel now appears in the block editor and on the SEO settings pages, using the AI provider already configured on this site. You can turn it off at any time from the AI settings tab.', 'wp-seopress-pro' ); ?>
	</p>
	<p>
		<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-secondary">
			<?php esc_html_e( 'Review the setting', 'wp-seopress-pro' ); ?>
		</a>
	</p>
</div>
	<?php
}

/**
 * Build a nonced URL that dismisses the notice.
 *
 * @since 10.2.0
 *
 * @param string $destination Where to land afterwards: 'settings' for the AI
 *                            tab, anything else to stay on the current screen.
 * @return string
 */
function seopress_pro_ai_assistant_notice_url( $destination = '' ) {
	$args = array( 'action' => 'seopress_pro_dismiss_ai_assistant_notice' );

	if ( 'settings' === $destination ) {
		$args['target'] = 'settings';
	}

	return wp_nonce_url(
		add_query_arg( $args, admin_url( 'admin-post.php' ) ),
		'seopress_pro_dismiss_ai_assistant_notice'
	);
}

add_action( 'admin_post_seopress_pro_dismiss_ai_assistant_notice', 'seopress_pro_dismiss_ai_assistant_notice' );
/**
 * Dismiss the notice for good.
 *
 * @since 10.2.0
 *
 * @return void
 */
function seopress_pro_dismiss_ai_assistant_notice() {
	// The nonce is required, not merely checked when present: guarding the
	// verification behind isset() would let a request that simply omits
	// _wpnonce skip it entirely.
	$nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	$target = isset( $_GET['target'] ) ? sanitize_key( wp_unslash( $_GET['target'] ) ) : '';
	$cap    = function_exists( 'seopress_capability' ) ? seopress_capability( 'manage_options', 'notice' ) : 'manage_options';

	if ( wp_verify_nonce( $nonce, 'seopress_pro_dismiss_ai_assistant_notice' ) && current_user_can( $cap ) ) {
		delete_option( SEOPRESS_PRO_AI_ASSISTANT_NOTICE_OPTION );
	}

	if ( 'settings' === $target ) {
		wp_safe_redirect( admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_ai' ) );
		exit;
	}

	// wp_get_referer() validates the URL against this site, so an attacker
	// cannot turn the dismiss link into an open redirect.
	$referer = wp_get_referer();

	wp_safe_redirect( $referer ? $referer : admin_url() );
	exit;
}
