<?php

namespace SEOPressPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes the translations of lazy-loaded webpack chunks reachable.
 *
 * `wp_set_script_translations()` loads exactly one JSON file: the one whose
 * name carries the md5 of the registered handle's own `src`. Our React admin
 * bundles are code-split, so most user-facing strings end up in chunk files
 * (`public/admin/schemas/schema-editor.<hash>.js`) that the webpack runtime
 * fetches itself and that never go through `wp_enqueue_script()`. WordPress
 * has no idea they exist, so their translations are never loaded and the UI
 * falls back to English no matter how complete the language pack is.
 *
 * Keying the chunk JSON by md5 is not an option either: the chunk filenames
 * are content-hashed, so the hash changes at every build and the language
 * pack would break on each release.
 *
 * This helper therefore merges every JSON of the text domain for the current
 * locale into the payload served for the main handle. Registering a handle is
 * enough — the filter is added once and dispatches on the handle/domain pair.
 *
 * @since 10.2
 */
class ScriptTranslations {

	/**
	 * Handles opted into the merge, as `handle => domain`.
	 *
	 * @var array<string,string>
	 */
	private static $handles = array();

	/**
	 * Whether the `pre_load_script_translations` filter is installed.
	 *
	 * @var bool
	 */
	private static $filter_added = false;

	/**
	 * Merged payloads already built during this request, keyed by
	 * `domain|locale`. Avoids re-globbing when several handles opt in on the
	 * same page load.
	 *
	 * @var array<string,string|false>
	 */
	private static $memo = array();

	/**
	 * Opt a script handle into the chunk-translation merge.
	 *
	 * Call it right after `wp_set_script_translations()` for the same handle,
	 * and only for a code-split bundle. A bundle made of a single `index.js`
	 * has nothing to gain and would only inline the whole domain payload —
	 * which matters for handles enqueued on every admin screen.
	 *
	 * @param string $handle Registered script handle.
	 * @param string $domain Text domain the handle was registered with.
	 * @return void
	 */
	public static function merge_chunks( $handle, $domain = 'wp-seopress-pro' ) {
		if ( empty( $handle ) || empty( $domain ) ) {
			return;
		}

		self::$handles[ $handle ] = $domain;

		if ( self::$filter_added ) {
			return;
		}

		self::$filter_added = true;

		add_filter( 'pre_load_script_translations', array( __CLASS__, 'filter_translations' ), 10, 4 );
	}

	/**
	 * Short-circuits `load_script_textdomain()` with the merged payload.
	 *
	 * Returns `$translations` untouched when the handle did not opt in, or
	 * when nothing could be merged — so WordPress keeps its normal lookup and
	 * we never replace a working payload with an empty one.
	 *
	 * @param string|false|null $translations Translations payload, or null so far.
	 * @param string|false      $file         Path to the JSON WordPress resolved.
	 * @param string            $handle       Script handle being loaded.
	 * @param string            $domain       Text domain being loaded.
	 * @return string|false|null
	 */
	public static function filter_translations( $translations, $file, $handle, $domain ) {
		if ( ! isset( self::$handles[ $handle ] ) || self::$handles[ $handle ] !== $domain ) {
			return $translations;
		}

		$merged = self::get_merged_payload( $domain );

		if ( false === $merged ) {
			return $translations;
		}

		return $merged;
	}

	/**
	 * Builds (and caches) the merged payload for a domain in the current locale.
	 *
	 * @param string $domain Text domain.
	 * @return string|false JSON payload, or false when there is nothing to merge.
	 */
	private static function get_merged_payload( $domain ) {
		$locale   = determine_locale();
		$memo_key = $domain . '|' . $locale;

		if ( isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}

		$cache_key = 'seopress_script_i18n_' . md5( $memo_key );
		$cached    = wp_cache_get( $cache_key, 'seopress' );

		if ( false !== $cached ) {
			self::$memo[ $memo_key ] = $cached;

			return $cached;
		}

		$files = glob( WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '-*.json' );

		if ( empty( $files ) ) {
			self::$memo[ $memo_key ] = false;

			return false;
		}

		$messages = array();
		$header   = array();

		foreach ( $files as $json_file ) {
			$data = json_decode( (string) file_get_contents( $json_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( ! isset( $data['locale_data']['messages'] ) || ! is_array( $data['locale_data']['messages'] ) ) {
				continue;
			}

			foreach ( $data['locale_data']['messages'] as $msgid => $translation ) {
				// The empty msgid carries the PO header (plural forms, charset).
				// Every file of a given locale ships the same one, so the first
				// non-empty header wins and the rest are skipped.
				if ( '' === $msgid ) {
					if ( empty( $header ) ) {
						$header = $translation;
					}

					continue;
				}

				$messages[ $msgid ] = $translation;
			}
		}

		if ( empty( $messages ) ) {
			self::$memo[ $memo_key ] = false;

			return false;
		}

		$payload = wp_json_encode(
			array(
				'domain'      => 'messages',
				'locale_data' => array(
					'messages' => array_merge( array( '' => $header ), $messages ),
				),
			)
		);

		if ( false === $payload ) {
			self::$memo[ $memo_key ] = false;

			return false;
		}

		// Short TTL: a language pack update must not stay masked by a
		// persistent object cache until the next flush.
		wp_cache_set( $cache_key, $payload, 'seopress', HOUR_IN_SECONDS );

		self::$memo[ $memo_key ] = $payload;

		return $payload;
	}
}
