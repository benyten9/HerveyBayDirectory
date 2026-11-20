<?php

/**
 * Plugin Name:       DoubleScale Pro
 * Plugin URI:        https://www.doublescale.io/
 * Description:       A powerful CRM Builder for WordPress that lets you manage leads, track interactions, and automate customer relationships—all seamlessly integrated within your WordPress dashboard.
 * Version:           1.6.1
 * Author:            doublescale.io
 * Author URI:        http://www.doublescale.io
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       doublescale
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  doublescale
 *
 * @package DoubleScale
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_FILE' ) ) {
	define( 'DOUBLESCALE_PRO_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'DOUBLESCALE_PRO_VERSION' ) ) {
	define( 'DOUBLESCALE_PRO_VERSION', '1.6.1' );
}
// Pro asks for Illuminate, WPEloquent, Carbon and Doctrine as DoubleScale\Vendor\…:
// tells free it needs no aliases for the old, unprefixed names.
if ( ! defined( 'DOUBLESCALE_PRO_SCOPED_VENDOR' ) ) {
	define( 'DOUBLESCALE_PRO_SCOPED_VENDOR', 1 );
}
/*
 * Version of the hooks and screen slots add-ons build on (project, deal and
 * task tabs, actions and sections; the portal project data; the project
 * delete veto; 2: the Custom Objects record pages). Raise it when one
 * changes; add-ons check it, not the version.
 */
if ( ! defined( 'DOUBLESCALE_PRO_ADDON_API' ) ) {
	define( 'DOUBLESCALE_PRO_ADDON_API', 2 );
}
if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
	define( 'DOUBLESCALE_PRO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_URL' ) ) {
	define( 'DOUBLESCALE_PRO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_PATH' ) ) {
	define( 'DOUBLESCALE_PRO_PLUGIN_PATH', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'DOUBLESCALE_PRO_PRICE_URL' ) ) {
	define( 'DOUBLESCALE_PRO_PRICE_URL', 'https://doublescale.io/pricing' );
}

/*
 * Minimum free version this Pro build expects.
 *
 * This is a SOFT requirement — it never blocks Pro from booting. WordPress
 * updates plugins one at a time, so an admin who updates Pro first runs new
 * Pro against the older free for a while; hard-blocking there would take the
 * whole plugin down over a partial update.
 *
 * Instead, Pro boots normally and individual features that need something the
 * older free does not provide degrade on their own `class_exists()` /
 * `method_exists()` checks, while the admin notice below explains why.
 *
 * Bump this when a Pro release starts depending on APIs added in a newer free.
 */
if ( ! defined( 'DOUBLESCALE_PRO_MIN_FREE_VERSION' ) ) {
	// 1.10.1 has the knowledge base module, RateLimiter and ClientIp, which
	// the Help Assistant, live chat and ticket ratings build on. Against an
	// older free those features stay off and this notice says why.
	// 1.9.6 lists the Bricks trigger (catalog + stub) and hands the picked page
	// to any page-based form when the editor loads its field rules and merge
	// tags. Against an older free the Bricks forms connection works, but a
	// Bricks workflow's conditions and {{bricks:…}} tags find no fields, and
	// this notice says why.
	// 1.9.2 shows the project custom fields Pro marks "Visible to the
	// client" in the portal's project overview (an older free leaves them
	// out), and has 1.9.1's fixes: a task cleanup that never deletes other
	// plugins' scheduled jobs, and product rules that don't load the catalogue.
	// 1.9.0 has what the Companies module builds on: company_id on tickets,
	// invoices, proposals, files and projects, the record-owner slot and the
	// doublescale_record_company_id / _portal_scope_company_ids /
	// _portal_company / _document_cc / _support_company_cc filters. Against
	// an older free company records and the client portal's company sharing
	// do not work, and this notice says why.
	// 1.5.1 lets customers upload files from the client portal and tells the
	// contact's Attachments tab who uploaded each file (`uploaded_by`). Against
	// an older free the portal has no Files page and the tab shows no uploader,
	// and this notice says why. 1.5.1 also takes a date filter's "Between" as
	// a [from, to] range; an older free answers that filter with an HTTP 500.
	// 1.4.9 keeps a buyer in the workflow when their phone number already
	// belongs to another contact, and never lets a phone match overwrite that
	// contact's email. Every WooCommerce order trigger in this Pro passes the
	// buyer's phone; against an older free such a buyer silently drops out of
	// the workflow or takes over the other contact, and this notice says why.
	// 1.4.8 lists the Email Opened trigger (catalog + stub), announces every
	// recorded open with its email on `doublescale_mail_open`, and handles the
	// tracking pixel on `init`, where an automation run can be queued. Against
	// an older free the trigger is simply not offered, and this notice says why.
	// 1.4.7 adds what Pro's XRechnung export builds on: DocumentDownload, the
	// invoices.buyer_reference column, and the doublescale_sales_settings_*
	// and doublescale_public_invoice_payload filters. Against an older free
	// the export registers no routes (EInvoicing::free_supports()) and this
	// notice explains why the buttons error.
	// 1.4.6 added POST /doublescale/v1/campaigns/{id}/rename.
	// 1.6.3 ships what the send queue and Pro's WhatsApp engine build on
	// (SendContext, waiting steps, shared daily limits, the channel engine
	// registry). Pro runs on an older free too: those features stay off, and
	// ScopedVendorNames bridges free's unprefixed vendor names.
	define( 'DOUBLESCALE_PRO_MIN_FREE_VERSION', '1.10.1' );
}
add_filter( 'doublescale_is_pro_addon_active', '__return_true', 1 );
// This Pro shows who uploaded each contact file (client or team), so free may
// let customers upload from the client portal. An older Pro never says so,
// which keeps the portal uploads off until both plugins are updated.
add_filter( 'doublescale_portal_client_uploads_supported', '__return_true' );
// This Pro ships its own WhatsApp campaign engine, so free stops asking the
// site to update Pro before DoubleScale 2.0 (which no longer ships free's).
add_filter( 'doublescale_pro_messaging_engine_capable', '__return_true' );
add_filter(
	'doublescale_pro_plugin_basenames',
	static function ( array $basenames ): array {
		$basenames[] = plugin_basename( __FILE__ );
		return array_values( array_unique( $basenames ) );
	},
	1
);

/**
 * Whether the free DoubleScale plugin is active for this site (single or network).
 *
 * Detection priority (folder-rename-safe):
 *
 * 1. DOUBLESCALE_FREE_PLUGIN_LOADED — set by the free plugin's main file the
 *    moment it is included. If it exists, free loaded in this very request.
 *
 * 2. DOUBLESCALE_PLUGIN_PATH — defined by the free plugin's Lifecycle class as
 *    plugin_basename( $plugin_file ). This reflects the actual folder name even
 *    when a user renames the plugin directory, and is checked against
 *    active_plugins / active_sitewide_plugins.
 *
 * 3. Filesystem fallback — scan active_plugins for any entry whose filename
 *    portion is "doublescale.php". Used only when the free plugin is in
 *    active_plugins but has not booted yet (e.g. very early cron requests).
 */
function doublescale_pro_is_free_plugin_active(): bool {
	// Most reliable: free set this constant when it booted.
	if ( defined( 'DOUBLESCALE_FREE_PLUGIN_LOADED' ) ) {
		return true;
	}

	// DOUBLESCALE_PLUGIN_PATH is set by the free plugin's own Lifecycle class
	// via plugin_basename(); it is folder-rename-safe.
	if ( defined( 'DOUBLESCALE_PLUGIN_PATH' ) ) {
		$basename = DOUBLESCALE_PLUGIN_PATH;

		if ( in_array( $basename, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}

		if ( is_multisite() ) {
			return array_key_exists( $basename, (array) get_site_option( 'active_sitewide_plugins', array() ) );
		}

		return false;
	}

	// Fallback: match by filename only — works even if the folder was renamed.
	foreach ( (array) get_option( 'active_plugins', array() ) as $entry ) {
		if ( is_string( $entry ) && basename( $entry ) === 'doublescale.php' ) {
			return true;
		}
	}

	if ( is_multisite() ) {
		foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $entry ) {
			if ( is_string( $entry ) && basename( $entry ) === 'doublescale.php' ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Whether the free DoubleScale plugin is installed (regardless of active state).
 *
 * Uses the free plugin's own constants when available so that a renamed folder
 * is handled correctly. Falls back to a glob scan of the plugins directory.
 */
function doublescale_pro_is_free_plugin_installed(): bool {
	// DOUBLESCALE_PLUGIN_FILE is the absolute path to the free plugin's main file.
	if ( defined( 'DOUBLESCALE_PLUGIN_FILE' ) ) {
		return file_exists( DOUBLESCALE_PLUGIN_FILE );
	}

	// DOUBLESCALE_PLUGIN_PATH is the plugin_basename() value set by free's Lifecycle.
	if ( defined( 'DOUBLESCALE_PLUGIN_PATH' ) ) {
		return file_exists( WP_PLUGIN_DIR . '/' . DOUBLESCALE_PLUGIN_PATH );
	}

	// Fallback: look for any doublescale.php in a direct sub-directory of plugins/.
	$matches = glob( WP_PLUGIN_DIR . '/*/doublescale.php' );
	return ! empty( $matches );
}

/**
 * Absolute path to the free DoubleScale plugin main file, if installed.
 */
function doublescale_pro_get_free_plugin_file(): ?string {
	if ( defined( 'DOUBLESCALE_PLUGIN_FILE' ) && file_exists( DOUBLESCALE_PLUGIN_FILE ) ) {
		return DOUBLESCALE_PLUGIN_FILE;
	}

	if ( defined( 'DOUBLESCALE_PLUGIN_PATH' ) ) {
		$path = WP_PLUGIN_DIR . '/' . DOUBLESCALE_PLUGIN_PATH;
		if ( file_exists( $path ) ) {
			return $path;
		}
	}

	$matches = glob( WP_PLUGIN_DIR . '/*/doublescale.php' );
	if ( ! empty( $matches ) && is_string( $matches[0] ) && file_exists( $matches[0] ) ) {
		return $matches[0];
	}

	return null;
}

/**
 * Installed version of the free DoubleScale plugin.
 */
function doublescale_pro_get_free_version(): ?string {
	if ( defined( 'DOUBLESCALE_VERSION' ) ) {
		return (string) DOUBLESCALE_VERSION;
	}

	$file = doublescale_pro_get_free_plugin_file();
	if ( ! $file ) {
		return null;
	}

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$data = get_plugin_data( $file, false, false );
	if ( empty( $data['Version'] ) ) {
		return null;
	}

	return (string) $data['Version'];
}

/**
 * Whether the installed free plugin is older than this Pro build expects.
 *
 * Returns false when the version cannot be determined, so an unreadable
 * version header never produces a false warning.
 */
function doublescale_pro_is_free_outdated(): bool {
	$free_version = doublescale_pro_get_free_version();

	if ( null === $free_version || '' === $free_version ) {
		return false;
	}

	return version_compare( $free_version, DOUBLESCALE_PRO_MIN_FREE_VERSION, '<' );
}

/**
 * Warn when free is older than this Pro build expects.
 *
 * Pro keeps running: everything the older free supports works as usual, and
 * only the features that need the newer APIs stay inactive. The notice is
 * dismissible per free-version so that dismissing it once does not hide a
 * later, different mismatch.
 */
add_action(
	'admin_notices',
	static function (): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// The "free is missing entirely" case has its own notice above.
		if ( ! doublescale_pro_is_free_plugin_active() || ! doublescale_pro_is_free_outdated() ) {
			return;
		}

		$free_version = (string) doublescale_pro_get_free_version();

		if ( get_user_meta( get_current_user_id(), 'doublescale_pro_dismissed_free_update_notice', true ) === $free_version ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: Pro version, 2: required free version, 3: installed free version. */
			__( 'DoubleScale Pro %1$s expects DoubleScale (free) %2$s or newer, but version %3$s is installed. Pro is still running — only the features that rely on the newer version are unavailable until you update DoubleScale (free).', 'doublescale' ),
			DOUBLESCALE_PRO_VERSION,
			DOUBLESCALE_PRO_MIN_FREE_VERSION,
			$free_version
		);

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'doublescale_pro_dismiss_free_notice', $free_version ),
			'doublescale_pro_dismiss_free_notice'
		);

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a> &middot; <a href="%s">%s</a></p></div>',
			esc_html( $message ),
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Update now', 'doublescale' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'doublescale' )
		);
	}
);

/**
 * Persist dismissal of the outdated-free notice for the current user.
 */
add_action(
	'admin_init',
	static function (): void {
		if ( ! isset( $_GET['doublescale_pro_dismiss_free_notice'] ) ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'doublescale_pro_dismiss_free_notice' ) ) {
			return;
		}

		$version = sanitize_text_field( wp_unslash( $_GET['doublescale_pro_dismiss_free_notice'] ) );

		update_user_meta( get_current_user_id(), 'doublescale_pro_dismissed_free_update_notice', $version );
	}
);

/**
 * Block Pro activation when the free DoubleScale plugin is not installed/active.
 *
 * Runs on register_activation_hook BEFORE WordPress marks Pro as active,
 * so calling wp_die here aborts activation entirely and Pro stays inactive.
 */
function doublescale_pro_require_free_on_activation(): void {
	if ( ! doublescale_pro_is_free_plugin_active() ) {
		$installed = doublescale_pro_is_free_plugin_installed();
		$title     = __( 'DoubleScale Pro cannot be activated', 'doublescale' );

		if ( $installed ) {
			$message = __(
				'DoubleScale Pro requires the free DoubleScale plugin to be active. Please activate the free DoubleScale plugin first, then activate DoubleScale Pro.',
				'doublescale'
			);
		} else {
			$message = __(
				'DoubleScale Pro requires the free DoubleScale plugin to be installed and active. Please install and activate the free DoubleScale plugin first, then activate DoubleScale Pro.',
				'doublescale'
			);
		}

		$back_link = sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Back to Plugins', 'doublescale' )
		);

		wp_die(
			esc_html( $message ) . $back_link,
			esc_html( $title ),
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'doublescale_pro_require_free_on_activation' );

/**
 * Show an admin notice when the free plugin is not loaded.
 *
 * Auto-deactivation was removed: the `return` guard at the bottom of this
 * file already prevents Pro from loading its classes when free is absent, so
 * no fatal can occur. Permanently deactivating Pro caused a false-positive
 * race condition: WordPress temporarily removes a plugin from active_plugins
 * during its own update process, which would deactivate Pro even though free
 * was simply being updated and would be restored moments later.
 *
 * The notice below is sufficient — it tells the admin what is wrong without
 * destructively removing Pro from active_plugins.
 */
add_action(
	'admin_notices',
	static function (): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( doublescale_pro_is_free_plugin_active() ) {
			return;
		}

		echo '<div class="notice notice-error is-dismissible"><p>';
		esc_html_e(
			'DoubleScale Pro requires the free DoubleScale plugin to be active. DoubleScale Pro features are disabled until the free plugin is activated.',
			'doublescale'
		);
		echo '</p></div>';
	}
);

/**
 * Disable the "Activate" action link for DoubleScale Pro on the Plugins page
 * when the free DoubleScale plugin is not active. We replace the link with a
 * non-clickable, disabled-looking label that explains why activation is blocked.
 *
 * This complements the wp_die() guard in doublescale_pro_require_free_on_activation():
 * the wp_die() guard prevents activation via any path (URL hacking, WP-CLI flows
 * that respect activation hooks, etc.), while this filter prevents the user from
 * even attempting to click "Activate" in the first place.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $actions ) {
		if ( doublescale_pro_is_free_plugin_active() ) {
			return $actions;
		}

		$tooltip = doublescale_pro_is_free_plugin_installed()
			? __( 'Activate the free DoubleScale plugin first to enable DoubleScale Pro.', 'doublescale' )
			: __( 'Install and activate the free DoubleScale plugin first to enable DoubleScale Pro.', 'doublescale' );

		$disabled_label = sprintf(
			'<span aria-disabled="true" title="%s" style="color:#a7aaad;cursor:not-allowed;pointer-events:none;">%s</span>',
			esc_attr( $tooltip ),
			esc_html__( 'Activate', 'doublescale' )
		);

		if ( is_array( $actions ) ) {
			unset( $actions['activate'] );
			$actions = array_merge( array( 'activate' => $disabled_label ), $actions );
		} else {
			$actions = array( 'activate' => $disabled_label );
		}

		return $actions;
	},
	10,
	1
);

/**
 * Same as above, but for the network admin Plugins screen on multisite.
 */
add_filter(
	'network_admin_plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $actions ) {
		if ( doublescale_pro_is_free_plugin_active() ) {
			return $actions;
		}

		$tooltip = doublescale_pro_is_free_plugin_installed()
			? __( 'Network-activate the free DoubleScale plugin first to enable DoubleScale Pro.', 'doublescale' )
			: __( 'Install and network-activate the free DoubleScale plugin first to enable DoubleScale Pro.', 'doublescale' );

		$disabled_label = sprintf(
			'<span aria-disabled="true" title="%s" style="color:#a7aaad;cursor:not-allowed;pointer-events:none;">%s</span>',
			esc_attr( $tooltip ),
			esc_html__( 'Network Activate', 'doublescale' )
		);

		if ( is_array( $actions ) ) {
			unset( $actions['activate'] );
			$actions = array_merge( array( 'activate' => $disabled_label ), $actions );
		} else {
			$actions = array( 'activate' => $disabled_label );
		}

		return $actions;
	},
	10,
	1
);

require_once DOUBLESCALE_PRO_PLUGIN_DIR . 'dependencies/libraries/load.php';

/*
 * Load Free's scoped vendor first. Pro only ships Pro-unique runtime packages
 * (Stripe, javanile/php-imap2, etc.) — everything else (Carbon, Guzzle,
 * Illuminate/WPEloquent, league/csv, SendGrid/Brevo/Postmark, Doctrine) is
 * provided by Free's dependencies/build/vendor/. WP_PLUGIN_DIR is used because
 * DOUBLESCALE_PLUGIN_DIR may not be defined yet depending on plugin load order.
 */
$doublescale_free_scoped_deps = WP_PLUGIN_DIR . '/doublescale/dependencies/build/vendor/scoper-autoload.php';
if ( file_exists( $doublescale_free_scoped_deps ) ) {
	require_once $doublescale_free_scoped_deps;
}

// An older free ships Illuminate & co. unprefixed: Pro's prefixed names point at its classes.
require_once DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Compat/ScopedVendorNames.php';
\DoubleScale\Pro\Compat\ScopedVendorNames::register();

$doublescale_scoped_deps     = DOUBLESCALE_PRO_PLUGIN_DIR . 'dependencies/build/vendor/scoper-autoload.php';
$vendor_autoload             = DOUBLESCALE_PRO_PLUGIN_DIR . 'dependencies/vendor/autoload.php';
$doublescale_pro_composer    = DOUBLESCALE_PRO_PLUGIN_DIR . 'vendor/autoload.php';
$doublescale_composer_loader = null;
if ( file_exists( $doublescale_scoped_deps ) ) {
	$doublescale_composer_loader = require $doublescale_scoped_deps;
} elseif ( file_exists( $vendor_autoload ) ) {
	require_once $vendor_autoload;
} elseif ( file_exists( $doublescale_pro_composer ) ) {
	$doublescale_composer_loader = require $doublescale_pro_composer;
}

/*
 * PSR-4 fallback for this plugin's own classes.
 *
 * The branches above load third-party dependencies only. When the scoped
 * dependency bundle exists (the normal shipped build) the chain stops at the
 * first branch, so `vendor/autoload.php` — the only thing that registers
 * PSR-4 for `DoubleScale\Pro\` — is never reached. The classmap recovery
 * further down cannot cover it either: an unoptimised Composer classmap holds
 * no PSR-4 classes, so it merges an empty array.
 *
 * The result was that nothing autoloaded `DoubleScale\Pro\*` at all. Anything
 * relying on `class_exists( ..., true )` rather than an explicit require —
 * notably third-party add-ons — failed with "Class not found".
 *
 * Registered unconditionally and last, so Composer still wins when present.
 * Mirrors free's `require_once $dir . 'includes/Autoload.php'`.
 */
require_once DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/autoload.php';

/*
 * Global IMAP polyfill shim. Our scoped php-imap2 build declares its `imap_*`
 * polyfills inside `namespace DoubleScale\Pro\Vendor;`, so on a server WITHOUT
 * native ext-imap the library's own `\imap_*` calls fatal with "Call to
 * undefined function" and inbound email polling never opens a ticket. This
 * defines the missing GLOBAL `\imap_*` functions (only when ext-imap is absent).
 * Loaded right after the scoped autoload so the Polyfill class is resolvable.
 */
require_once DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Compat/imap-polyfill-shim.php';

/*
 * Recover the existing ClassLoader if the scoper-autoload was previously
 * included (the second `require` returns true, not the loader instance).
 * Mirrors the symmetric recovery in Free's Lifecycle::load_dependencies().
 */
if ( ! $doublescale_composer_loader instanceof \Composer\Autoload\ClassLoader ) {
	$doublescale_pro_vendor_dir = DOUBLESCALE_PRO_PLUGIN_DIR . 'dependencies/build/vendor';
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
				if ( 0 === strpos( $doublescale_path, $doublescale_pro_vendor_dir ) ) {
					$doublescale_composer_loader = $doublescale_spl_obj;
					break 3;
				}
			}
		}
	}
}

$doublescale_stripe_init = DOUBLESCALE_PRO_PLUGIN_DIR . 'dependencies/vendor/stripe/stripe-php/init.php';
if ( file_exists( $doublescale_stripe_init ) ) {
	require_once $doublescale_stripe_init;
}

if ( $doublescale_composer_loader instanceof \Composer\Autoload\ClassLoader
	&& file_exists( $doublescale_scoped_deps )
	&& file_exists( $doublescale_pro_composer ) ) {
	$doublescale_root_classmap = DOUBLESCALE_PRO_PLUGIN_DIR . 'vendor/composer/autoload_classmap.php';
	if ( is_readable( $doublescale_root_classmap ) ) {
		$doublescale_classmap        = require $doublescale_root_classmap;
		$doublescale_includes_root = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/';
		$doublescale_plugin_map      = array();
		foreach ( $doublescale_classmap as $doublescale_fqcn => $doublescale_path ) {
			if ( 0 !== strpos( $doublescale_fqcn, 'DoubleScale\\Pro\\' ) ) {
				continue;
			}
			if ( 0 === strpos( $doublescale_fqcn, 'DoubleScale\\Pro\\Tests\\' ) ) {
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

if ( file_exists( DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/autoload.php' ) ) {
	require_once DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/autoload.php';
}

if ( is_multisite() ) {
	register_activation_hook( __FILE__, array( \DoubleScale\Pro\Database\ProInstall::class, 'multisite_activate' ) );
	add_action( 'wpmu_new_blog', array( \DoubleScale\Pro\Database\ProInstall::class, 'activate_new_site' ) );
} else {
	register_activation_hook( __FILE__, array( \DoubleScale\Pro\Database\ProInstall::class, 'install' ) );
}

register_activation_hook( __FILE__, 'doublescale_pro_clear_eventbus_cron' );

register_deactivation_hook( __FILE__, 'doublescale_pro_deactivation' );

if ( ! doublescale_pro_is_free_plugin_active() ) {
	return;
}

add_action( 'plugins_loaded', 'doublescale_pro_maybe_bootstrap', 1 );

/**
 * Load Pro bootstrap only when the free plugin is present.
 *
 * Runs at plugins_loaded priority 1 — after all plugin main files are included
 * (so the free plugin has set its load constants) but before free's kernel
 * boots at 5.
 *
 * Pro no longer enforces a minimum free version; it only requires that free is
 * active, because Pro compiles against free's shared runtime and would fatal if
 * free were absent entirely.
 */
function doublescale_pro_maybe_bootstrap(): void {
	static $bootstrapped = false;
	if ( $bootstrapped ) {
		return;
	}

	if ( ! doublescale_pro_is_free_plugin_active() ) {
		return;
	}

	$bootstrapped = true;

	// Soft stubs for free ≥ 1.3.8 APIs when Pro was updated first.
	$free_api_stubs = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Compat/FreeApiStubs.php';
	if ( is_readable( $free_api_stubs ) ) {
		require_once $free_api_stubs;
	}
	$settings_currency = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Compat/SettingsCurrency.php';
	if ( is_readable( $settings_currency ) ) {
		require_once $settings_currency;
	}
	$payment_mode_slugs = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Compat/PaymentModeSlugs.php';
	if ( is_readable( $payment_mode_slugs ) ) {
		require_once $payment_mode_slugs;
	}

	if ( file_exists( DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/pro-plugin-bootstrap.php' ) ) {
		require_once DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/pro-plugin-bootstrap.php';
	}

	$file = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Core/ModuleFeatureGatePro.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}

	if ( ! class_exists( \DoubleScale\Core\ModuleManager::class, false ) ) {
		$doublescale_mm_candidates = array();
		if ( defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			$doublescale_mm_candidates[] = DOUBLESCALE_PLUGIN_DIR . 'includes/Core/ModuleManager.php';
		}
		$plugins_parent              = dirname( DOUBLESCALE_PRO_PLUGIN_DIR );
		$doublescale_mm_candidates[] = $plugins_parent . '/DoubleScale/includes/Core/ModuleManager.php';
		$doublescale_mm_candidates[] = $plugins_parent . '/doublescale/includes/Core/ModuleManager.php';
		foreach ( $doublescale_mm_candidates as $mm_path ) {
			if ( is_readable( $mm_path ) ) {
				require_once $mm_path;
				break;
			}
		}
	}

	doublescale_pro_pre_init();
}

/**
 * Unschedule the legacy EventBus retry cron.
 *
 * @since 1.13.0
 */
function doublescale_pro_clear_eventbus_cron() {
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'doublescale_booking_retry_failed_workflows' );
	}
}

/**
 * Initialize DoubleScale Pro (depends on free PluginKernel).
 *
 * Listens on `doublescale_ready` (fired by free at plugins_loaded priority 5)
 * so initialization runs strictly after free's kernel is booted. Falls back
 * to a defensive `class_exists` guard for installs where the free plugin is
 * missing or didn't fire the action.
 */
function doublescale_pro_pre_init() {
	add_action(
		'doublescale_ready',
		static function () {
			if ( ! defined( 'DOUBLESCALE_FREE_PLUGIN_LOADED' ) || ! class_exists( \DoubleScale\Core\PluginKernel::class, false ) ) {
				if ( is_admin() ) {
					add_action(
						'admin_notices',
						static function () {
							echo '<div class="notice notice-error"><p>';
							esc_html_e( 'DoubleScale Pro could not load the DoubleScale (free) application kernel. Ensure the free plugin is active and not broken.', 'doublescale' );
							echo '</p></div>';
						}
					);
				}
				return;
			}

			// `ensure_db_ready()` fires several `SHOW TABLES` probes on every
			// request; gate it on a version stamp so the schema sweep only runs
			// after a Pro install/upgrade. `init()` stays unconditional — it
			// merely registers the `check_version` hook that drives real
			// upgrades and is effectively free.
			if ( class_exists( \DoubleScale\Pro\Database\ProInstall::class ) ) {
				$pro_version = defined( 'DOUBLESCALE_PRO_VERSION' ) ? DOUBLESCALE_PRO_VERSION : '0';
				if ( get_option( 'doublescale_pro_schema_ready_version' ) !== $pro_version ) {
					\DoubleScale\Pro\Database\ProInstall::ensure_db_ready();
					update_option( 'doublescale_pro_schema_ready_version', $pro_version );
				}
				\DoubleScale\Pro\Database\ProInstall::init();
			}

			if ( file_exists( DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/functions.php' ) ) {
				require_once DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/functions.php';
			}

			doublescale_pro_clear_eventbus_cron();

			if ( class_exists( \DoubleScale\Pro\Core\ProAnalyticsRestFallback::class ) ) {
				\DoubleScale\Pro\Core\ProAnalyticsRestFallback::register();
			}

			if ( ! get_option( 'doublescale_booking_eventbus_cron_cleared_v1' ) ) {
				if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
					wp_clear_scheduled_hook( 'doublescale_booking_retry_failed_workflows' );
				}
				update_option( 'doublescale_booking_eventbus_cron_cleared_v1', 1, false );
			}
		}
	);
}

/**
 * Unschedule the Action Scheduler work only Pro's engines run.
 *
 * The clears below only reach old WP-Cron names; the real queues live in
 * Action Scheduler, and with Pro off nothing listens on them — the SMS drain
 * then failed every minute. WhatsApp's queue stays while free ships its own
 * engine: free takes WhatsApp back on the next request and finishes it.
 *
 * @return void
 */
function doublescale_pro_unschedule_engine_tasks(): void {
	if ( ! class_exists( \DoubleScale\Core\Tasks::class ) ) {
		return;
	}

	$hooks = array( 'doublescale_sms_campaigns', 'process_campaign_sms', 'continue_sms_campaign', 'resume_after_meta_tier' );

	$registry = '\\DoubleScale\\Modules\\Campaigns\\Services\\ChannelEngineRegistry';
	if ( class_exists( $registry ) && ! $registry::has_fallback( 'whatsapp' ) ) {
		array_push( $hooks, 'doublescale_whatsapp_campaigns', 'process_campaign_whatsapp', 'continue_whatsapp_campaign' );
	}

	$tasks = new \DoubleScale\Core\Tasks( 'doublescale_campaigns' );
	foreach ( $hooks as $hook ) {
		$tasks->unschedule_all( $hook );
	}
}

/**
 * Plugin deactivation hook.
 *
 * @since 1.0.0
 */
function doublescale_pro_deactivation() {
	wp_clear_scheduled_hook( 'doublescale_email_campaigns' );
	wp_clear_scheduled_hook( 'doublescale_sms_campaigns' );
	wp_clear_scheduled_hook( 'doublescale_whatsapp_campaigns' );
	wp_clear_scheduled_hook( 'doublescale_email_sequences' );
	wp_clear_scheduled_hook( 'doublescale_daily3' );
	wp_clear_scheduled_hook( 'doublescale_daily4' );
	wp_clear_scheduled_hook( 'doublescale_cleanup_page_visits' );

	doublescale_pro_unschedule_engine_tasks();
	if ( class_exists( \DoubleScale\Core\NextRunCache::class ) ) {
		\DoubleScale\Core\NextRunCache::flush();
	}

	if ( class_exists( \DoubleScale\Modules\Emails\SocialIconGenerator::class ) ) {
		\DoubleScale\Modules\Emails\SocialIconGenerator::cleanup();
	}

	if ( class_exists( \DoubleScale\Core\UserRoles\UserRoles::class ) ) {
		\DoubleScale\Core\UserRoles\UserRoles::deprovision_pro_roles();
	}
}

/**
 * Restore Pro-gated CRM / pipeline roles when Pro is activated.
 */
function doublescale_pro_on_activation_roles(): void {
	if ( class_exists( \DoubleScale\Core\UserRoles\UserRoles::class ) ) {
		\DoubleScale\Core\UserRoles\UserRoles::provision_pro_roles();
	}
}
register_activation_hook( __FILE__, 'doublescale_pro_on_activation_roles' );
