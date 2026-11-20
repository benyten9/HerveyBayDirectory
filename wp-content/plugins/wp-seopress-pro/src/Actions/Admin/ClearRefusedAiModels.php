<?php

namespace SEOPressPro\Actions\Admin;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * Forget the models a provider refused when the AI settings change.
 *
 * `Completions::rememberRefusedModel()` keeps a refusal for six hours so the
 * feature keeps working on another model instead of failing on every request.
 * That lifetime is right for the case it was written for, a permission granted
 * on the provider's side taking effect on its own, and wrong for the case the
 * user is most likely to hit: they read the notice, allow the model in their
 * provider dashboard, come back, pick it again and save. Without this, the
 * requests keep going to the substitute for up to six hours, and the
 * connection test says everything is fine because it tests the substitute too.
 *
 * So a save that changes the provider, the model or the key clears the list.
 * The user has just told us the situation is different; making them wait out a
 * transient to be believed is the trap the fallback was meant to avoid.
 *
 * An unrelated save leaves it alone: a refusal that is still true should not
 * cost a failed request every time someone saves an XML sitemap option.
 *
 * @since 10.4.0
 */
class ClearRefusedAiModels implements ExecuteHooks {

	/**
	 * @since 10.4.0
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'update_option_seopress_pro_option_name', array( $this, 'clearOnAiSettingsChange' ), 10, 2 );

		// Before core compares the old and new values. A save that changes
		// nothing never fires update_option_*, and that is precisely the save
		// the refusal notice asks for: the user allows the model at the
		// provider, comes back, and saves with it still selected.
		add_filter( 'pre_update_option_seopress_pro_option_name', array( $this, 'clearOnResave' ), 10, 2 );

		// A site that has never saved the PRO settings has no row yet, so the
		// first save calls add_option() and never fires the action above. It is
		// reachable: the provider and the model both have defaults, so a
		// refusal can be recorded before the option exists.
		add_action( 'add_option_seopress_pro_option_name', array( $this, 'clearOnFirstSave' ), 10, 2 );
	}

	/**
	 * Forget the refusal of a model the settings are being saved with.
	 *
	 * Only that model's provider, and only when its selected model is one the
	 * provider refused: saving a sitemap option while some other model is
	 * refused leaves that refusal alone. The cost of being believed is one
	 * request to the model again, which the retry covers if it is still
	 * refused.
	 *
	 * @since 10.4.0
	 *
	 * @param mixed $value     The option about to be stored.
	 * @param mixed $old_value The option as it is.
	 *
	 * @return mixed The value, unchanged.
	 */
	public function clearOnResave( $value, $old_value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$completions = seopress_pro_get_service( 'Completions' );

		if ( ! $completions || ! method_exists( $completions, 'getRefusedModels' ) ) {
			return $value;
		}

		foreach ( array( 'openai', 'deepseek', 'gemini', 'mistral', 'claude' ) as $provider ) {
			$model = $this->readString( $value, 'seopress_ai_' . $provider . '_model' );

			if ( '' !== $model && in_array( $model, $completions->getRefusedModels( $provider ), true ) ) {
				$completions->clearRefusedModels( $provider );
			}
		}

		return $value;
	}

	/**
	 * The PRO options being created for the first time.
	 *
	 * @since 10.4.0
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  The option that was just stored.
	 *
	 * @return void
	 */
	public function clearOnFirstSave( $option, $value ) {
		$this->clearOnAiSettingsChange( array(), $value );
	}

	/**
	 * Compare the AI settings before and after a save.
	 *
	 * @since 10.4.0
	 *
	 * @param mixed $old_value The option before the save.
	 * @param mixed $value     The option after the save.
	 *
	 * @return void
	 */
	public function clearOnAiSettingsChange( $old_value, $value ) {
		$changed = $this->changedProviders( $old_value, $value );

		if ( empty( $changed ) ) {
			return;
		}

		$completions = seopress_pro_get_service( 'Completions' );

		if ( ! $completions || ! method_exists( $completions, 'clearRefusedModels' ) ) {
			return;
		}

		foreach ( $changed as $provider ) {
			$completions->clearRefusedModels( $provider );
		}
	}

	/**
	 * The providers whose configuration this save touched.
	 *
	 * The selected provider is included whenever it changed, because the list
	 * is stored per provider and the one being switched to deserves a clean
	 * slate as much as the one being left. Beyond that, any provider whose own
	 * model moved is included, so changing the OpenAI model does not silently
	 * reset a genuine Claude refusal.
	 *
	 * A key change needs nothing here: `Completions::refusedModelsKey()`
	 * includes a digest of the key in use, so a new key reads a transient that
	 * does not exist yet.
	 *
	 * @since 10.4.0
	 *
	 * @param mixed $old_value The option before the save.
	 * @param mixed $value     The option after the save.
	 *
	 * @return string[]
	 */
	protected function changedProviders( $old_value, $value ) {
		$old_value = is_array( $old_value ) ? $old_value : array();
		$value     = is_array( $value ) ? $value : array();

		$providers = array( 'openai', 'deepseek', 'gemini', 'mistral', 'claude', 'seopress' );
		$changed   = array();

		$old_provider = $this->readString( $old_value, 'seopress_ai_provider' );
		$new_provider = $this->readString( $value, 'seopress_ai_provider' );

		if ( $old_provider !== $new_provider ) {
			foreach ( array( $old_provider, $new_provider ) as $provider ) {
				if ( '' !== $provider ) {
					$changed[] = strtolower( $provider );
				}
			}
		}

		foreach ( $providers as $provider ) {
			$key = 'seopress_ai_' . $provider . '_model';

			if ( $this->readString( $old_value, $key ) !== $this->readString( $value, $key ) ) {
				$changed[] = $provider;
			}
		}

		return array_values( array_unique( $changed ) );
	}

	/**
	 * Read one option value of unknown shape as a string.
	 *
	 * `seopress_sanitize_options_fields()` returns unknown keys untouched, so
	 * the settings endpoint can store an array here. A cast would then warn on
	 * every save.
	 *
	 * @since 10.4.0
	 *
	 * @param array  $option The PRO option group.
	 * @param string $key    The key to read.
	 *
	 * @return string
	 */
	protected function readString( $option, $key ) {
		if ( ! isset( $option[ $key ] ) || ! is_scalar( $option[ $key ] ) ) {
			return '';
		}

		return (string) $option[ $key ];
	}
}
