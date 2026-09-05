<?php

namespace SEOPressPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared enqueue helper for the React DataViews admin pages.
 *
 * Every DataViews admin page (Redirections, Schemas, Broken Links, Site Audit)
 * loads a compiled bundle from `public/admin/<name>/`, the DataViews stylesheet
 * shipped via the `node_modules/@wordpress/dataviews/` package, the page's own
 * stylesheet, and wires script translations. This helper centralises that
 * recipe so each page only needs to declare what is unique to it (hook page
 * slug, handle, optional `wp_localize_script` payload, optional pre-enqueue
 * callback).
 *
 * It also filters the asset-manifest dependencies down to those actually
 * registered as WP scripts — the manifest may list new packages that are not
 * yet shipped with older WordPress versions and are bundled inside the JS
 * file instead. Without this filter, `wp_enqueue_script()` silently breaks
 * the dependency chain and the bundle never executes.
 *
 * @since 9.8
 */
class DataViewsAssets {

	/**
	 * Minimum WordPress version required to run the bundled
	 * `@wordpress/dataviews@14` chunk.
	 *
	 * The package opt-ins to `@wordpress/private-apis` with the legacy
	 * consent string and unlocks `Menu`, `Badge`, `DateCalendar`,
	 * `Validated*Control`, `kebabCase`, etc. from `@wordpress/components`'s
	 * private API surface. Empirical results:
	 *
	 * - WP 6.5 — `@wordpress/dataviews` not in core's `private-apis`
	 *   allowlist; the opt-in throws.
	 * - WP 6.6 — opt-in passes but `componentsPrivateApis.Menu.TriggerButton`
	 *   is undefined; Schemas / Site Audit / Broken Links crash on render.
	 *   Redirections may *look* OK on an empty table but fails as soon as a
	 *   row exists or a column header menu opens.
	 * - WP 6.7 — same crash as 6.6 (TriggerButton missing).
	 * - WP 6.8+ — confirmed working.
	 *
	 * Pages that depend on this constant fall back to the classic CPT
	 * screens (or a plain notice) when the running WP is older. The
	 * `seopress_pro_dataviews_min_wp` filter lets a mu-plugin lower the
	 * threshold for local probing.
	 *
	 * @var string
	 */
	const MIN_WP_VERSION = '6.8';

	/**
	 * Whether the running WordPress is recent enough to run the DataViews
	 * bundles without crashing.
	 *
	 * The threshold is filterable via `seopress_pro_dataviews_min_wp` so a
	 * mu-plugin can lower it (or bypass it entirely with `'0'`) for local
	 * testing on older WP releases without a rebuild — useful for nailing
	 * down the actual minimum empirically.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		global $wp_version;

		/**
		 * Filters the minimum WordPress version required to enqueue the
		 * DataViews-based admin pages.
		 *
		 * Set to `'0'` (or any version older than the running WP) to force
		 * the React bundles to load — useful when probing whether an older
		 * WP can actually run them. Falsy values fall back to the default.
		 *
		 * @since 9.8.0
		 *
		 * @param string $min_wp_version Default minimum (see `MIN_WP_VERSION`).
		 */
		$min = apply_filters( 'seopress_pro_dataviews_min_wp', self::MIN_WP_VERSION );

		if ( ! is_string( $min ) || '' === $min ) {
			$min = self::MIN_WP_VERSION;
		}

		return version_compare( $wp_version, $min, '>=' );
	}

	/**
	 * Enqueue a DataViews admin page bundle.
	 *
	 * @param array $args {
	 *     Required arguments.
	 *
	 *     @type string   $name             Bundle directory name under `public/admin/`. Also used for the stylesheet handle.
	 *     @type string   $handle           Script/style handle (e.g. `seopress-pro-redirections`).
	 *     @type string   $page_hook_match  Substring matched against the current admin `$hook` to decide whether to enqueue (e.g. `seopress-redirections`).
	 *     @type string   $hook             The current admin page hook passed by `admin_enqueue_scripts`.
	 *     @type string   $plugin_file      The main plugin file (`__FILE__` from `seopress-pro.php`) used to resolve `plugins_url()` and `plugin_dir_path()`.
	 *     @type string   $plugin_version   Fallback version when `index.asset.php` is missing.
	 *     @type string   $text_domain      Text domain for `wp_set_script_translations()` (defaults to `wp-seopress-pro`).
	 *     @type string   $localize_object  Optional. JS variable name passed to `wp_localize_script()`.
	 *     @type array    $localize_data    Optional. Data payload for `wp_localize_script()`.
	 *     @type callable $before_enqueue   Optional. Callback invoked before the script is enqueued (e.g. `wp_enqueue_media`).
	 *     @type array[]  $preload          Optional. List of REST requests to dispatch server-side and inline as JS globals so the React app can render the first page without a network round-trip. Each entry: `[ 'global' => 'WINDOW_VAR_NAME', 'route' => '/seopress/v1/redirections', 'params' => [ 'page' => 1, … ] ]`.
	 * }
	 *
	 * @return bool True if assets were enqueued, false if the hook did not match.
	 */
	public static function enqueue( array $args ) {
		if ( false === strpos( $args['hook'], $args['page_hook_match'] ) ) {
			return false;
		}

		// Defense-in-depth — every existing caller already checks
		// `is_supported()` before invoking us, but a future caller could
		// forget. Bail out silently so the page render callback's notice /
		// CPT redirect is what the user actually sees.
		if ( ! self::is_supported() ) {
			return false;
		}

		// WP core's wp-admin/css/forms.css ships globally-scoped rules on
		// `input[type=checkbox]`, `select`, etc. that clash with the React
		// DataViews UI (every control comes from @wordpress/components — no
		// native form elements are rendered). `forms` is also a registered
		// dependency of the `wp-admin` style bundle, so a plain
		// wp_dequeue_style() is not enough — strip it from wp-admin's deps
		// then dequeue + deregister, on this page only.
		add_action(
			'admin_print_styles',
			static function () {
				global $wp_styles;
				if ( isset( $wp_styles->registered['wp-admin'] ) ) {
					$wp_styles->registered['wp-admin']->deps = array_values(
						array_diff( $wp_styles->registered['wp-admin']->deps, array( 'forms' ) )
					);
				}
				wp_dequeue_style( 'forms' );
				wp_deregister_style( 'forms' );
			},
			0
		);

		$name           = $args['name'];
		$handle         = $args['handle'];
		$plugin_file    = $args['plugin_file'];
		$text_domain    = isset( $args['text_domain'] ) ? $args['text_domain'] : 'wp-seopress-pro';
		$plugin_dir     = plugin_dir_path( $plugin_file );
		$plugin_version = $args['plugin_version'];

		if ( isset( $args['before_enqueue'] ) && is_callable( $args['before_enqueue'] ) ) {
			call_user_func( $args['before_enqueue'] );
		}

		// Make the shared DataViews vendor chunk available BEFORE the dep
		// filter below runs — the per-page asset.php declares it as a
		// dependency and the filter would otherwise drop it as "unregistered".
		self::register_dataviews_commons( $plugin_file, $plugin_version );

		// Same idea for chart.js — Site Audit's bundle externalises it
		// against the existing UMD bundle. Other DataViews pages don't
		// list the handle and pay nothing for the early registration.
		self::register_chartjs( $plugin_file, $plugin_version );

		$asset_file = $plugin_dir . 'public/admin/' . $name . '/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => $plugin_version,
			);

		// Drop dependencies the current WP install does not ship as registered
		// script handles (older cores, packages bundled inline). Without this,
		// `wp_enqueue_script()` silently aborts the whole dependency chain.
		$deps = array_filter(
			$asset['dependencies'],
			function ( $dep ) {
				return false !== wp_scripts()->query( $dep, 'registered' );
			}
		);

		wp_enqueue_script(
			$handle,
			plugins_url( 'public/admin/' . $name . '/index.js', $plugin_file ),
			$deps,
			$asset['version'],
			true
		);

		// `@wordpress/dataviews` ships its component stylesheet outside of
		// the JS module graph (and the package declares `sideEffects: false`,
		// so a JS-side `import` would be tree-shaken). The commons webpack
		// config copies it to `public/admin/_commons/dataviews.css` via
		// `copy-webpack-plugin`; register/enqueue it here as a regular WP
		// style handle so per-page bundles can depend on it.
		self::register_dataviews_commons_style( $plugin_file, $plugin_version );

		$style_deps = array( 'wp-components' );
		if ( wp_style_is( 'seopress-pro-dataviews-commons', 'registered' ) ) {
			$style_deps[] = 'seopress-pro-dataviews-commons';
		}

		wp_enqueue_style(
			$handle,
			plugins_url( 'public/admin/' . $name . '/index.css', $plugin_file ),
			$style_deps,
			$asset['version']
		);

		wp_set_script_translations( $handle, $text_domain, WP_LANG_DIR . '/plugins' );

		// These bundles are code-split, and `wp_set_script_translations()` only
		// loads the JSON matching the entry file. Without this the strings that
		// webpack moved into a lazy chunk stay in English.
		ScriptTranslations::merge_chunks( $handle, $text_domain );

		if ( ! empty( $args['localize_object'] ) && isset( $args['localize_data'] ) ) {
			wp_localize_script( $handle, $args['localize_object'], $args['localize_data'] );
		}

		if ( ! empty( $args['preload'] ) && is_array( $args['preload'] ) ) {
			self::inline_preloads( $handle, $args['preload'] );
		}

		return true;
	}

	/**
	 * Register the shared DataViews vendor chunk script handle.
	 *
	 * Idempotent — safe to call from every page-level enqueue. Reads
	 * `public/admin/_commons/index.asset.php` for the dependency list and
	 * version so a fresh build is picked up automatically.
	 *
	 * If the build hasn't run yet (e.g. dev install without a `npm run
	 * build:dataviews-commons`), the file is missing and the handle is
	 * registered with no source — `wp_enqueue_script()` will then silently
	 * skip it through the existing `wp_scripts()->query()` filter, and the
	 * per-page bundles will fail to resolve `seopressVendor.*`. That's the
	 * correct failure mode in dev: surface the missing build instead of
	 * masking it.
	 *
	 * @param string $plugin_file    The main plugin file (`__FILE__` from `seopress-pro.php`).
	 * @param string $plugin_version Fallback version when `index.asset.php` is missing.
	 *
	 * @return void
	 */
	private static function register_dataviews_commons( $plugin_file, $plugin_version ) {
		$handle = 'seopress-pro-dataviews-commons';

		if ( wp_script_is( $handle, 'registered' ) ) {
			return;
		}

		$plugin_dir = plugin_dir_path( $plugin_file );
		$asset_file = $plugin_dir . 'public/admin/_commons/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => $plugin_version,
			);

		// The webpack dependency-extraction plugin externalises every
		// transitive `@wordpress/X` import to a `wp-X` script handle, but
		// some of those handles (e.g. `wp-theme`, `wp-fields`) are not
		// registered by WP core in the versions we support. Drop them
		// silently — the bundle inlines the actual code, so the missing
		// handle never matters at runtime, and not dropping them triggers
		// a "doing it wrong" notice + breaks the whole enqueue chain
		// (introduced as a hard error in WP 6.9.1).
		$deps = array_filter(
			$asset['dependencies'],
			function ( $dep ) {
				return false !== wp_scripts()->query( $dep, 'registered' );
			}
		);

		wp_register_script(
			$handle,
			plugins_url( 'public/admin/_commons/index.js', $plugin_file ),
			$deps,
			$asset['version'],
			true
		);
	}

	/**
	 * Register the DataViews component stylesheet as a WP style handle.
	 *
	 * The CSS file is copied at build time from
	 * `node_modules/@wordpress/dataviews/build-style/style.css` to
	 * `public/admin/_commons/dataviews.css` by `copy-webpack-plugin` (see
	 * `webpack.dataviews-commons.config.js`). Idempotent — safe to call
	 * from every page-level enqueue. Falls back to silent skip when the
	 * built file is missing (dev install with no commons build yet) so
	 * the page enqueue still succeeds.
	 *
	 * @param string $plugin_file    The main plugin file (`__FILE__` from `seopress-pro.php`).
	 * @param string $plugin_version Fallback version when the file is missing.
	 *
	 * @return void
	 */
	private static function register_dataviews_commons_style( $plugin_file, $plugin_version ) {
		$handle = 'seopress-pro-dataviews-commons';

		if ( wp_style_is( $handle, 'registered' ) ) {
			return;
		}

		$plugin_dir = plugin_dir_path( $plugin_file );
		$css_path   = $plugin_dir . 'public/admin/_commons/dataviews.css';

		if ( ! file_exists( $css_path ) ) {
			return;
		}

		wp_register_style(
			$handle,
			plugins_url( 'public/admin/_commons/dataviews.css', $plugin_file ),
			array( 'wp-components' ),
			$plugin_version
		);
	}

	/**
	 * Register chart.js as a WP script handle.
	 *
	 * Reuses the UMD bundle already shipped at `assets/js/chart.bundle.min.js`
	 * for the GA / Matomo dashboard widgets — the same global `window.Chart`
	 * is what `webpack.audit.config.js` externalises `import Chart from
	 * 'chart.js'` against. Idempotent and safe to call from every page-level
	 * enqueue.
	 *
	 * @param string $plugin_file    The main plugin file (`__FILE__` from `seopress-pro.php`).
	 * @param string $plugin_version Version string for cache busting.
	 *
	 * @return void
	 */
	private static function register_chartjs( $plugin_file, $plugin_version ) {
		$handle = 'seopress-pro-chartjs';

		if ( wp_script_is( $handle, 'registered' ) ) {
			return;
		}

		wp_register_script(
			$handle,
			plugins_url( 'assets/js/chart.bundle.min.js', $plugin_file ),
			array(),
			$plugin_version,
			true
		);
	}

	/**
	 * Dispatch each preload request through `rest_do_request()` and inline the
	 * resulting payload as a `window.<global>` JS literal, so the React app
	 * can render its first frame without an extra network round-trip.
	 *
	 * Failures (any non-2xx response or thrown exception) are swallowed
	 * silently — the JS app falls back to its normal fetch path.
	 *
	 * @param string  $handle   Script handle to attach the inline blob to.
	 * @param array[] $preloads See `enqueue()`'s `$preload` argument.
	 *
	 * @return void
	 */
	private static function inline_preloads( $handle, array $preloads ) {
		$snippets = array();

		foreach ( $preloads as $entry ) {
			if ( empty( $entry['global'] ) || empty( $entry['route'] ) ) {
				continue;
			}

			$payload = self::dispatch_rest_request( $entry['route'], isset( $entry['params'] ) ? $entry['params'] : array() );
			if ( null === $payload ) {
				continue;
			}

			// `wp_json_encode` returns false on encode failure; in that case
			// skip silently so a stray non-encodable response cannot break
			// the whole bundle.
			$json = wp_json_encode( $payload );
			if ( false === $json ) {
				continue;
			}

			$snippets[] = sprintf( 'window.%s=%s;', $entry['global'], $json );
		}

		if ( empty( $snippets ) ) {
			return;
		}

		// `before` runs prior to the script tag itself, so the React entry
		// point can read `window.*` synchronously on first execution.
		wp_add_inline_script( $handle, implode( '', $snippets ), 'before' );
	}

	/**
	 * Dispatch an internal REST request with the current user's permissions
	 * and return its data payload, or null on any error.
	 *
	 * Important: the targeted REST callback MUST return a `WP_REST_Response`
	 * (or `WP_Error`). Callbacks that end the request via `wp_send_json_*` /
	 * `wp_die()` will abort the surrounding admin page render and dump the
	 * JSON straight to the browser. Wrapping the dispatch in an output
	 * buffer catches stray `echo` / `print`, but it cannot recover from
	 * `wp_die()`.
	 *
	 * @param string $route  REST route, e.g. `/seopress/v1/redirections`.
	 * @param array  $params Query params to attach to the request.
	 *
	 * @return mixed|null
	 */
	private static function dispatch_rest_request( $route, array $params ) {
		ob_start();
		try {
			$request = new \WP_REST_Request( 'GET', $route );
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}
			$response = rest_do_request( $request );
		} catch ( \Throwable $e ) {
			ob_end_clean();
			return null;
		}
		// Drop any stray output the callback may have produced before the
		// admin page render appends its own (would otherwise corrupt the
		// HTML stream).
		ob_end_clean();

		if ( ! ( $response instanceof \WP_REST_Response ) ) {
			return null;
		}
		if ( $response->is_error() || $response->get_status() >= 300 ) {
			return null;
		}
		return $response->get_data();
	}
}
