<?php

namespace SEOPressPro\Actions\Api\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-user DataViews "view preferences" REST endpoints.
 *
 * Each DataViews admin page persists its listing state (perPage, sort, fields,
 * filters, density…) to user meta so display preferences survive a reload.
 * The load/save logic is identical across pages — this trait factorises it,
 * leaving each consuming class to declare only what is unique:
 *
 *   - the user-meta key that stores the prefs;
 *   - the whitelist of allowed view keys (drops anything else to avoid
 *     unbounded user-meta inflation).
 *
 * Pages that need richer behaviour (scope-based prefs, partial merges) — like
 * Site Audit — keep their own implementation.
 *
 * @since 9.8
 */
trait ViewPreferencesTrait {

	/**
	 * User-meta key that stores this page's view preferences.
	 *
	 * @return string
	 */
	abstract protected function getViewPrefsMetaKey();

	/**
	 * Whitelist of view keys the consumer is willing to persist.
	 *
	 * Anything outside this list is dropped on save. Keep it tight to avoid
	 * arbitrary user-meta inflation.
	 *
	 * @return string[]
	 */
	abstract protected function getViewPrefsAllowedKeys();

	/**
	 * GET — return the saved prefs as an object (empty object when none).
	 *
	 * @return \WP_REST_Response
	 */
	public function processGetViewPreferences() {
		$value = get_user_meta( get_current_user_id(), $this->getViewPrefsMetaKey(), true );

		if ( empty( $value ) || ! is_array( $value ) ) {
			return new \WP_REST_Response( (object) array() );
		}

		return new \WP_REST_Response( $value );
	}

	/**
	 * POST — persist the whitelisted subset of the submitted prefs.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processSaveViewPreferences( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$clean = array();
		foreach ( $this->getViewPrefsAllowedKeys() as $key ) {
			if ( isset( $params[ $key ] ) ) {
				$clean[ $key ] = $params[ $key ];
			}
		}

		update_user_meta( get_current_user_id(), $this->getViewPrefsMetaKey(), $clean );

		return new \WP_REST_Response(
			array(
				'code' => 'saved',
				'data' => $clean,
			)
		);
	}
}
