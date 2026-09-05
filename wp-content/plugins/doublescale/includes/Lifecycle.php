<?php
/**
 * Plugin lifecycle: constants, dependency loading, activation, deactivation,
 * and the deferred kernel bootstrap. Keeps the main plugin file paper-thin.
 *
 * @package DoubleScale
 */

namespace DoubleScale;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	/**
	 * Action Scheduler group slugs owned by this plugin.
	 * All pending/in-progress actions in these groups are cancelled on deactivation.
	 */
	private const SCHEDULED_GROUPS = array(
		'doublescale_campaigns',
		'doublescale_automations',
		'doublescale_daily',
		'doublescale_abandoned_cart',
		'doublescale_forms',
		'doublescale_subscription',
		'doublescale_sales',
		'doublescale_support',
		'doublescale_booking_payment',
		'doublescale_booking_completion',
		'doublescale-push',
		'doublescale_smtp',
	);

	/**
	 * Entry point. Called once from the main plugin file.
	 */
	public static function boot( string $plugin_file ): void {
		self::define_constants( $plugin_file );
		self::load_dependencies();
		self::register_hooks( $plugin_file );
	}

	/**
	 * Define plugin-wide constants.
	 */
	private static function define_constants( string $plugin_file ): void {
		$defaults = array(
			'DOUBLESCALE_PLUGIN_FILE'   => $plugin_file,
			'DOUBLESCALE_VERSION'       => '1.3.24',
			'DOUBLESCALE_PLUGIN_DIR'    => plugin_dir_path( $plugin_file ),
			'DOUBLESCALE_PLUGIN_URL'    => plugin_dir_url( $plugin_file ),
			'DOUBLESCALE_PLUGIN_PATH'   => plugin_basename( $plugin_file ),
			'DOUBLESCALE_PRO_PRICE_URL' => 'https://doublescale.io/pricing',
		);

		foreach ( $defaults as $name => $value ) {
			if ( ! defined( $name ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- $name is bound to the DOUBLESCALE_* keys from $defaults above; all are plugin-prefixed.
				define( $name, $value );
			}
		}
	}

	/**
	 * Require Composer + custom autoloaders.
	 */
	private static function load_dependencies(): void {
		$dir = DOUBLESCALE_PLUGIN_DIR;

		require_once $dir . 'includes/safe-redirect.php';
		require_once $dir . 'dependencies/libraries/load.php';

		$doublescale_scoped_deps     = $dir . 'dependencies/build/vendor/scoper-autoload.php';
		$vendor_autoload             = $dir . 'dependencies/vendor/autoload.php';
		$doublescale_root_composer   = $dir . 'vendor/autoload.php';
		$doublescale_composer_loader = null;

		if ( file_exists( $doublescale_scoped_deps ) ) {
			$doublescale_composer_loader = require $doublescale_scoped_deps;
		} elseif ( file_exists( $vendor_autoload ) ) {
			require_once $vendor_autoload;
		} elseif ( file_exists( $doublescale_root_composer ) ) {
			$doublescale_composer_loader = require $doublescale_root_composer;
		}

		/*
		 * If the scoper-autoload was previously included (e.g. by Pro's
		 * bootstrap when Pro loads before Free), `require` returns true
		 * instead of the ClassLoader. Recover the existing loader by scanning
		 * the spl autoload chain for the Composer ClassLoader whose vendor dir
		 * is rooted under this plugin.
		 */
		if ( ! $doublescale_composer_loader instanceof \Composer\Autoload\ClassLoader ) {
			$doublescale_vendor_dir = $dir . 'dependencies/build/vendor';
			foreach ( spl_autoload_functions() as $doublescale_spl_fn ) {
				if ( ! is_array( $doublescale_spl_fn ) || ! isset( $doublescale_spl_fn[0] ) ) {
					continue;
				}
				$doublescale_spl_obj = $doublescale_spl_fn[0];
				if ( ! $doublescale_spl_obj instanceof \Composer\Autoload\ClassLoader ) {
					continue;
				}
				foreach ( $doublescale_spl_obj->getPrefixesPsr4() as $doublescale_paths ) {
					foreach ( (array) $doublescale_paths as $doublescale_path ) {
						if ( 0 === strpos( $doublescale_path, $doublescale_vendor_dir ) ) {
							$doublescale_composer_loader = $doublescale_spl_obj;
							break 3;
						}
					}
				}
			}
		}

		if ( $doublescale_composer_loader instanceof \Composer\Autoload\ClassLoader
			&& file_exists( $doublescale_scoped_deps )
			&& file_exists( $doublescale_root_composer ) ) {
			$doublescale_root_classmap = $dir . 'vendor/composer/autoload_classmap.php';
			if ( is_readable( $doublescale_root_classmap ) ) {
				$doublescale_classmap      = require $doublescale_root_classmap;
				$doublescale_includes_root = $dir . 'includes/';
				$doublescale_plugin_map    = array();
				foreach ( $doublescale_classmap as $doublescale_fqcn => $doublescale_path ) {
					if ( 0 !== strpos( $doublescale_fqcn, 'DoubleScale\\' ) ) {
						continue;
					}
					if ( 0 === strpos( $doublescale_fqcn, 'DoubleScale\\Tests\\' ) ) {
						continue;
					}
					if ( 0 === strpos( $doublescale_fqcn, 'DoubleScale\\Vendor\\' ) ) {
						continue;
					}
					if ( 0 !== strpos( $doublescale_path, $doublescale_includes_root ) ) {
						continue;
					}
					$doublescale_plugin_map[ $doublescale_fqcn ] = $doublescale_path;
				}
				if ( $doublescale_plugin_map ) {
					$doublescale_composer_loader->addClassMap( $doublescale_plugin_map );
				}
			}
		}

		/*
		 * WordPress.org packages omit root vendor/. SMTP (and other WP-style
		 * class-*.php files) still need Composer’s classmap. Ship a generated
		 * subset at includes/includes-classmap.php and always merge/register it.
		 */
		$doublescale_includes_classmap_file = $dir . 'includes/includes-classmap.php';
		if ( is_readable( $doublescale_includes_classmap_file ) ) {
			$doublescale_includes_classmap = require $doublescale_includes_classmap_file;
			if ( is_array( $doublescale_includes_classmap ) && $doublescale_includes_classmap ) {
				$doublescale_absolute_map = array();
				foreach ( $doublescale_includes_classmap as $doublescale_fqcn => $doublescale_rel ) {
					$doublescale_absolute_map[ $doublescale_fqcn ] = $dir . ltrim( (string) $doublescale_rel, '/' );
				}
				if ( $doublescale_composer_loader instanceof \Composer\Autoload\ClassLoader ) {
					$doublescale_composer_loader->addClassMap( $doublescale_absolute_map );
				} else {
					spl_autoload_register(
						static function ( $class ) use ( $doublescale_absolute_map ) {
							if ( isset( $doublescale_absolute_map[ $class ] ) && is_readable( $doublescale_absolute_map[ $class ] ) ) {
								require_once $doublescale_absolute_map[ $class ];
							}
						},
						true,
						true
					);
				}
			}
		}

		if ( file_exists( $dir . 'includes/Autoload.php' ) ) {
			require_once $dir . 'includes/Autoload.php';
		}

		// Gate helpers (e.g. doublescale_sales_documents_ready) must exist before
		// activation migrations — Install::install() runs on register_activation_hook,
		// which fires before PluginKernel loads includes/Core/functions.php.
		require_once $dir . 'includes/Core/functions.php';

		require_once $dir . 'includes/Compat/LegacyProBookingPayment.php';
		\DoubleScale\Compat\LegacyProBookingPayment::ensure_loaded();
	}

	/**
	 * Register WordPress lifecycle hooks.
	 */
	private static function register_hooks( string $plugin_file ): void {
		if ( is_multisite() ) {
			register_activation_hook( $plugin_file, array( __CLASS__, 'on_multisite_activate' ) );
			add_action( 'wpmu_new_blog', array( __CLASS__, 'on_new_blog' ) );
		} else {
			register_activation_hook( $plugin_file, array( __CLASS__, 'on_activate' ) );
		}

		register_deactivation_hook( $plugin_file, array( __CLASS__, 'on_deactivate' ) );

		// Register the languages/ path before anything can call `__()`.
		//
		// Since WP 6.7 `load_plugin_textdomain()` only records a custom path;
		// the .mo is loaded just-in-time on the first translate call. The boot
		// below fires `doublescale_ready` at plugins_loaded priority 5 and many
		// service managers call `__()` while registering, so hooking this on
		// `init` resolved the domain to the plugin root (no .mo there) and
		// cached NOOP_Translations for the whole request — every string stayed
		// untranslated even with a valid languages/doublescale-<locale>.mo.
		// Priority 4 guarantees the path is set before that first call.
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ), 4 );
		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ), 5 );
		add_action( 'loco_file_written', array( \DoubleScale\I18n\LocoJsonSync::class, 'on_file_written' ) );

		// Suppress WP 6.7+'s "translation loaded too early" notice for the
		// `doublescale` domain. This codebase's modular boot fires
		// `doublescale_ready` at plugins_loaded priority 5, before WP's `init`
		// action, and many service managers call `__()` during their
		// registration handlers. Refactoring every such call to defer to `init`
		// is invasive and high-risk; suppressing the specific _doing_it_wrong
		// trigger for this domain is the documented WP-supported workaround.
		add_filter(
			'doing_it_wrong_trigger_error',
			static function ( $trigger, $function, $message ) {
				if ( '_load_textdomain_just_in_time' === $function
					&& is_string( $message )
					&& false !== strpos( $message, 'doublescale' )
				) {
					return false;
				}
				return $trigger;
			},
			10,
			3
		);
	}

	/**
	 * Load plugin translations for PHP strings and translation plugins (Loco, WPML, etc.).
	 */
	public static function load_textdomain(): void {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Required for Loco Translate, WPML, and self-hosted language packs in languages/.
		load_plugin_textdomain(
			'doublescale',
			false,
			dirname( plugin_basename( DOUBLESCALE_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Single-site activation.
	 */
	public static function on_activate(): void {
		if ( class_exists( \DoubleScale\Database\Install::class ) ) {
			\DoubleScale\Database\Install::install();
		}
		update_option( 'doublescale_needs_task_cleanup', true, false );
	}

	/**
	 * Multisite network activation.
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide.
	 */
	public static function on_multisite_activate( $network_wide = false ): void {
		if ( class_exists( \DoubleScale\Database\Install::class ) ) {
			\DoubleScale\Database\Install::multisite_activate( $network_wide );
		}
		update_option( 'doublescale_needs_task_cleanup', true, false );
	}

	/**
	 * New blog hook on multisite.
	 *
	 * @param int $blog_id
	 */
	public static function on_new_blog( $blog_id ): void {
		if ( class_exists( \DoubleScale\Database\Install::class ) ) {
			\DoubleScale\Database\Install::activate_new_site( $blog_id );
		}
	}

	/**
	 * Deactivation: cancel all Action Scheduler tasks owned by this plugin.
	 *
	 * as_unschedule_all_actions() cancels every pending and in-progress action
	 * for the given group. Passing an empty hook and empty args targets the
	 * whole group rather than individual hooks, so this stays correct even as
	 * new hooks are added in future versions.
	 */
	public static function on_deactivate(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		foreach ( self::SCHEDULED_GROUPS as $group ) {
			as_unschedule_all_actions( '', array(), $group );
		}
	}

	/**
	 * Boot the modular kernel after WordPress finishes loading plugins.
	 */
	public static function on_plugins_loaded(): void {
		// `ensure_db_ready()` runs `SHOW TABLES` probes plus a per-module
		// migration-repair sweep. That work only matters right after an install
		// or upgrade, so gate it on a version stamp: on steady-state requests
		// this collapses to one autoloaded `get_option` compare. The stamp is
		// written only once the schema check completes cleanly.
		if (
			class_exists( \DoubleScale\Database\Install::class )
			&& get_option( 'doublescale_schema_ready_version' ) !== DOUBLESCALE_VERSION
		) {
			\DoubleScale\Database\Install::ensure_db_ready();
			update_option( 'doublescale_schema_ready_version', DOUBLESCALE_VERSION );
		}

		\DoubleScale\Core\Bootstrap::init();

		// On first boot after activation or upgrade, run task cleanup immediately
		// so that any stale scheduled actions with no registered callback are
		// removed before they can accumulate failures.
		if ( get_option( 'doublescale_needs_task_cleanup' ) ) {
			delete_option( 'doublescale_needs_task_cleanup' );
			if ( class_exists( \DoubleScale\Core\Tasks::class ) ) {
				\DoubleScale\Core\Tasks::cleanup_old_tasks();
			}
		}

		/**
		 * Fires after the DoubleScale (free) modular stack is fully initialized.
		 */
		do_action( 'doublescale_ready' );
	}
}
