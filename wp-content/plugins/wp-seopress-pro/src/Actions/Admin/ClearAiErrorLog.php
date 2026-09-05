<?php

namespace SEOPressPro\Actions\Admin;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooks;
use SEOPressPro\Services\OpenAI\Completions;

/**
 * Drop the recorded AI failure when the provider changes.
 *
 * The AI settings tab renders the last provider failure from the
 * `seopress_pro_ai_logs` transient, written with a thirty day lifetime and
 * never invalidated. Switching provider is the moment the entry becomes most
 * misleading: it names a provider the site has stopped calling, while the new
 * one works, so the panel asserts something false about which provider is in
 * use and the user has no way to clear it.
 *
 * A successful call clears it too, in `Completions::clearApiErrorLog()`. This
 * covers the case where the user fixes the configuration and looks at the tab
 * before generating anything.
 *
 * @since 10.2.0
 */
class ClearAiErrorLog implements ExecuteHooks {

	/**
	 * @return void
	 */
	public function hooks() {
		add_action( 'update_option_seopress_pro_option_name', array( $this, 'clearOnProviderChange' ), 10, 2 );

		// A site that has never saved the PRO settings has no row yet, so the
		// first save calls add_option() and never reaches the filter above. It
		// is reachable: the provider defaults to OpenAI, so a failure can be
		// recorded before the option exists, and the save that then picks a
		// different provider is exactly the one that must clear it.
		add_action( 'add_option_seopress_pro_option_name', array( $this, 'clearOnFirstSave' ), 10, 2 );
	}

	/**
	 * The PRO options being created for the first time.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  The option that was just stored.
	 *
	 * @return void
	 */
	public function clearOnFirstSave( $option, $value ) {
		$this->clearOnProviderChange( null, $value );
	}

	/**
	 * Compare the AI provider before and after a settings save.
	 *
	 * Only a change clears the log: an unrelated save leaves a genuine, current
	 * failure on screen, which is what the panel is for.
	 *
	 * @param mixed $old_value The option before the save.
	 * @param mixed $value     The option after the save.
	 *
	 * @return void
	 */
	public function clearOnProviderChange( $old_value, $value ) {
		if ( $this->readProvider( $old_value ) === $this->readProvider( $value ) ) {
			return;
		}

		delete_transient( Completions::ERROR_LOG_TRANSIENT );
	}

	/**
	 * Read the provider out of an option value of unknown shape.
	 *
	 * @param mixed $option The PRO option group.
	 *
	 * @return string
	 */
	protected function readProvider( $option ) {
		// `seopress_ai_provider` is not in the free plugin's sanitize allow
		// list, and `seopress_sanitize_options_fields()` returns unknown keys
		// untouched, so the pro settings endpoint can store an array here. The
		// cast would then warn on every later save.
		if ( ! is_array( $option )
			|| ! isset( $option['seopress_ai_provider'] )
			|| ! is_scalar( $option['seopress_ai_provider'] ) ) {
			return '';
		}

		return (string) $option['seopress_ai_provider'];
	}
}
