<?php
/**
 * Global helpers for DoubleScale (free).
 *
 * @package DoubleScale
 */


defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Core\Container;
use DoubleScale\Core\Logger\Logger;
use DoubleScale\Core\Logger\LoggerInterface;
use DoubleScale\Core\Settings\Settings;
use DoubleScale\Core\PluginKernel;

require_once __DIR__ . '/ModuleRequestCache.php';
require_once __DIR__ . '/ModuleFeatureGate.php';

if ( ! function_exists( 'doublescale_resolve' ) ) {
	/**
	 * @template T
	 * @param class-string<T>|string $abstract
	 * @return T|mixed|null
	 */
	function doublescale_resolve( string $abstract ) {
		$container = Container::global();
		if ( ! $container ) {
			return null;
		}
		if ( ! $container->has( $abstract ) ) {
			return null;
		}
		return $container->get( $abstract );
	}
}

if ( ! function_exists( 'doublescale_get_manifest_path' ) ) {
	function doublescale_get_manifest_path( string $key ): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}

		$upload = wp_upload_dir( null, true, false );
		if ( ! is_array( $upload ) ) {
			return '';
		}
		if ( ! empty( $upload['error'] ) && is_string( $upload['error'] ) && '' !== $upload['error'] ) {
			return '';
		}

		$basedir = $upload['basedir'] ?? null;
		if ( ! is_string( $basedir ) || '' === $basedir ) {
			return '';
		}

		$basedir = rtrim( $basedir, '/\\' );
		$dir     = $basedir . '/doublescale/cache/manifests';
		if ( ! is_dir( $dir ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem is unavailable this early; direct check is required for cache-manifest write path.
			return '';
		}

		$safe = preg_replace( '/[^a-z0-9._-]+/i', '', $key );
		if ( '' === $safe ) {
			return '';
		}

		return $dir . '/' . $safe . '.php';
	}
}

if ( ! function_exists( 'doublescale_sanitize_text_fields' ) ) {
	function doublescale_sanitize_text_fields( $str, $keep_newlines = false ) {
		if ( is_object( $str ) || is_array( $str ) ) {
			return '';
		}

		$str = (string) $str;

		$filtered = wp_check_invalid_utf8( $str );

		if ( strpos( $filtered, '<' ) !== false ) {
			$filtered = wp_pre_kses_less_than( $filtered );
			$filtered = wp_strip_all_tags( $filtered, false );
			$filtered = str_replace( "<\n", "&lt;\n", $filtered );
		}

		if ( ! $keep_newlines ) {
			$filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
		}
		$filtered = trim( $filtered );

		$found = false;
		while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
			$filtered = str_replace( $match[0], '', $filtered );
			$found    = true;
		}

		if ( $found ) {
			$filtered = trim( preg_replace( '/ +/', ' ', $filtered ) );
		}

		return $filtered;
	}
}

if ( ! function_exists( 'doublescale_sanitize_text_deeply' ) ) {
	function doublescale_sanitize_text_deeply( $string, $keep_newlines = false ) {
		if ( is_object( $string ) || is_array( $string ) ) {
			return '';
		}

		$string        = (string) $string;
		$keep_newlines = (bool) $keep_newlines;

		$new_value = doublescale_sanitize_text_fields( $string, $keep_newlines );

		if ( strlen( $new_value ) !== strlen( $string ) ) {
			$new_value = doublescale_sanitize_text_deeply( $new_value, $keep_newlines );
		}

		return $new_value;
	}
}

if ( ! function_exists( 'doublescale_decode_string' ) ) {
	function doublescale_decode_string( $string ) {
		if ( ! is_string( $string ) ) {
			return $string;
		}

		$string = doublescale_sanitize_text_deeply( $string, true );
		$string = wp_kses_decode_entities( html_entity_decode( $string, ENT_QUOTES ) );

		return doublescale_sanitize_text_deeply( $string, true );
	}
}

if ( ! function_exists( 'doublescale_is_plugin_active' ) ) {
	/**
	 * Whether a plugin DoubleScale integrates with is active: any of its plugin
	 * files is active, or any constant it defines once loaded is defined (so a
	 * copy installed under another folder still counts).
	 *
	 * Takes a key of doublescale_integrated_plugins() (`easy-digital-downloads`)
	 * or a plugin basename (`easy-digital-downloads/easy-digital-downloads.php`);
	 * Pro passes basenames so it keeps working with an older free plugin.
	 *
	 * @param string $plugin_name Integrated plugin key or plugin basename.
	 */
	function doublescale_is_plugin_active( $plugin_name ) {
		$plugin_name = strtolower( (string) $plugin_name );
		$plugins     = doublescale_integrated_plugins();
		$plugin      = $plugins[ $plugin_name ] ?? null;

		if ( null === $plugin ) {
			$plugin = array(
				'files'     => array( $plugin_name ),
				'constants' => array(),
			);
			foreach ( $plugins as $candidate ) {
				if ( in_array( $plugin_name, array_map( 'strtolower', (array) ( $candidate['files'] ?? array() ) ), true ) ) {
					$plugin = $candidate;
					break;
				}
			}
		}

		foreach ( (array) ( $plugin['constants'] ?? array() ) as $constant ) {
			if ( is_string( $constant ) && '' !== $constant && defined( $constant ) ) {
				return true;
			}
		}

		foreach ( (array) ( $plugin['files'] ?? array() ) as $file ) {
			if ( is_string( $file ) && doublescale_is_plugin_active_basename( $file ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'doublescale_integrated_plugins' ) ) {
	/**
	 * Plugins DoubleScale integrates with: the plugin files each ships as (free
	 * and pro editions together) and the constants each defines once loaded.
	 *
	 * @return array<string, array{files: array<int, string>, constants: array<int, string>}>
	 */
	function doublescale_integrated_plugins(): array {
		$plugins = array(
			// Commerce.
			'woocommerce'               => array(
				'files'     => array( 'woocommerce/woocommerce.php' ),
				'constants' => array( 'WC_PLUGIN_FILE' ),
			),
			'woocommerce-subscriptions' => array(
				'files'     => array( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ),
				'constants' => array(),
			),
			'woocommerce-memberships'   => array(
				'files'     => array( 'woocommerce-memberships/woocommerce-memberships.php' ),
				'constants' => array(),
			),
			'woocommerce-wishlists'     => array(
				'files'     => array( 'woocommerce-wishlists/woocommerce-wishlists.php' ),
				'constants' => array(),
			),
			'easy-digital-downloads'    => array(
				'files'     => array(
					'easy-digital-downloads/easy-digital-downloads.php',
					'easy-digital-downloads-pro/easy-digital-downloads.php',
				),
				'constants' => array( 'EDD_PLUGIN_FILE' ),
			),
			'surecart'                  => array(
				'files'     => array( 'surecart/surecart.php' ),
				'constants' => array( 'SURECART_PLUGIN_FILE' ),
			),
			'fluent-cart'               => array(
				'files'     => array( 'fluent-cart/fluent-cart.php' ),
				'constants' => array( 'FLUENTCART_VERSION' ),
			),

			// Booking.
			'ameliabooking'             => array(
				'files'     => array( 'ameliabooking/ameliabooking.php' ),
				'constants' => array( 'AMELIA_VERSION' ),
			),
			'latepoint'                 => array(
				'files'     => array( 'latepoint/latepoint.php' ),
				'constants' => array( 'LATEPOINT_VERSION' ),
			),

			// LMS and memberships.
			'learndash'                 => array(
				'files'     => array( 'sfwd-lms/sfwd_lms.php' ),
				'constants' => array( 'LEARNDASH_VERSION' ),
			),
			'lifterlms'                 => array(
				'files'     => array( 'lifterlms/lifterlms.php' ),
				'constants' => array( 'LLMS_PLUGIN_FILE' ),
			),
			'tutor'                     => array(
				'files'     => array( 'tutor/tutor.php' ),
				'constants' => array( 'TUTOR_VERSION' ),
			),
			'learnpress'                => array(
				'files'     => array( 'learnpress/learnpress.php' ),
				'constants' => array( 'LP_PLUGIN_FILE' ),
			),
			'memberpress'               => array(
				'files'     => array( 'memberpress/memberpress.php' ),
				'constants' => array( 'MEPR_PLUGIN_NAME' ),
			),
			'paid-memberships-pro'      => array(
				'files'     => array( 'paid-memberships-pro/paid-memberships-pro.php' ),
				'constants' => array( 'PMPRO_VERSION' ),
			),

			// Forms.
			'wpforms'                   => array(
				'files'     => array( 'wpforms-lite/wpforms.php', 'wpforms/wpforms.php' ),
				'constants' => array( 'WPFORMS_VERSION' ),
			),
			'quillforms'                => array(
				'files'     => array( 'quillforms/quillforms.php' ),
				'constants' => array( 'QUILLFORMS_VERSION' ),
			),
			'fluentform'                => array(
				'files'     => array( 'fluentform/fluentform.php' ),
				'constants' => array( 'FLUENTFORM_VERSION' ),
			),
			'contact-form-7'            => array(
				'files'     => array( 'contact-form-7/wp-contact-form-7.php' ),
				'constants' => array( 'WPCF7_VERSION' ),
			),
			'ws-form'                   => array(
				'files'     => array( 'ws-form/ws-form.php', 'ws-form-pro/ws-form.php' ),
				'constants' => array( 'WS_FORM_VERSION' ),
			),
			'gravityforms'              => array(
				'files'     => array( 'gravityforms/gravityforms.php' ),
				'constants' => array( 'GF_MIN_WP_VERSION' ),
			),
			'ninja-forms'               => array(
				'files'     => array( 'ninja-forms/ninja-forms.php' ),
				'constants' => array( 'NF_PLUGIN_URL' ),
			),
			'formidable'                => array(
				'files'     => array( 'formidable/formidable.php' ),
				'constants' => array(),
			),
			'forminator'                => array(
				'files'     => array( 'forminator/forminator.php' ),
				'constants' => array( 'FORMINATOR_PLUGIN_BASENAME' ),
			),
			'metform'                   => array(
				'files'     => array( 'metform/metform.php' ),
				'constants' => array( 'METFORM_VERSION' ),
			),
			'jetformbuilder'            => array(
				'files'     => array( 'jetformbuilder/jet-form-builder.php' ),
				'constants' => array( 'JET_FORM_BUILDER_PATH' ),
			),
			'sureforms'                 => array(
				'files'     => array( 'sureforms/sureforms.php' ),
				'constants' => array( 'SRFM_FILE' ),
			),
			'bit-form'                  => array(
				'files'     => array( 'bit-form/bitforms.php' ),
				'constants' => array( 'BITFORMS_VERSION' ),
			),
			'bricks'                    => array(
				// Bricks is a theme: no plugin file, only its constant (defined once the theme loads).
				'files'     => array(),
				'constants' => array( 'BRICKS_VERSION' ),
			),
			'elementor-pro'             => array(
				'files'     => array( 'elementor-pro/elementor-pro.php' ),
				'constants' => array( 'ELEMENTOR_PRO_VERSION' ),
			),
			'eform'                     => array(
				'files'     => array( 'wp-fsqm-pro/ipt_fsqm.php' ),
				'constants' => array(),
			),

			// Other.
			'acf'                       => array(
				'files'     => array( 'advanced-custom-fields/acf.php', 'advanced-custom-fields-pro/acf.php' ),
				'constants' => array( 'ACF_VERSION' ),
			),
			'fluent-crm'                => array(
				'files'     => array( 'fluent-crm/fluent-crm.php' ),
				'constants' => array( 'FLUENTCRM_PLUGIN_VERSION' ),
			),
			'wp-marketing-automations'  => array(
				'files'     => array( 'wp-marketing-automations/wp-marketing-automations.php' ),
				'constants' => array( 'BWFAN_VERSION' ),
			),
		);

		return (array) apply_filters( 'doublescale_integrated_plugins', $plugins );
	}
}

if ( ! function_exists( 'doublescale_is_plugin_active_basename' ) ) {
	/**
	 * Whether a plugin basename is active (case-insensitive; folder name may vary).
	 *
	 * @param string $basename Plugin path relative to wp-content/plugins.
	 */
	function doublescale_is_plugin_active_basename( string $basename ): bool {
		$needle = strtolower( $basename );
		if ( '' === $needle ) {
			return false;
		}

		foreach ( (array) get_option( 'active_plugins', array() ) as $active ) {
			if ( is_string( $active ) && strtolower( $active ) === $needle ) {
				return true;
			}
		}

		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			foreach ( array_keys( $network ) as $active ) {
				if ( is_string( $active ) && strtolower( $active ) === $needle ) {
					return true;
				}
			}
		}

		return false;
	}
}

if ( ! function_exists( 'doublescale_get_pro_plugin_basenames' ) ) {
	/**
	 * Known Pro plugin basenames (any folder name; main file doublescale-pro.php).
	 *
	 * @return string[]
	 */
	function doublescale_get_pro_plugin_basenames(): array {
		$basenames = array( 'doublescale-pro/doublescale-pro.php' );

		if ( defined( 'DOUBLESCALE_PRO_PLUGIN_FILE' ) ) {
			$basenames[] = plugin_basename( \DOUBLESCALE_PRO_PLUGIN_FILE );
		}
		if ( defined( 'DOUBLESCALE_PRO_PLUGIN_PATH' ) && \DOUBLESCALE_PRO_PLUGIN_PATH ) {
			$basenames[] = (string) \DOUBLESCALE_PRO_PLUGIN_PATH;
		}

		$plugin_lists = array( (array) get_option( 'active_plugins', array() ) );
		if ( is_multisite() ) {
			$plugin_lists[] = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
		}
		foreach ( $plugin_lists as $plugins ) {
			foreach ( $plugins as $plugin ) {
				if ( is_string( $plugin ) && preg_match( '#/doublescale-pro\.php$#i', $plugin ) ) {
					$basenames[] = $plugin;
				}
			}
		}

		$plugins_root = defined( 'WP_PLUGIN_DIR' ) ? \WP_PLUGIN_DIR : '';
		if ( $plugins_root && is_dir( $plugins_root ) ) {
			foreach ( (array) glob( $plugins_root . '/*/doublescale-pro.php' ) as $main_file ) {
				if ( is_readable( $main_file ) ) {
					$basenames[] = plugin_basename( $main_file );
				}
			}
		}

		$basenames = array_values( array_unique( array_filter( $basenames ) ) );

		/**
		 * Filter the list of Pro plugin basenames used for install/active detection.
		 *
		 * @param string[] $basenames Plugin paths relative to wp-content/plugins.
		 */
		return apply_filters( 'doublescale_pro_plugin_basenames', $basenames );
	}
}

if ( ! function_exists( 'doublescale_get_pro_plugin_status' ) ) {
	/**
	 * Pro plugin install/active flags for admin config and license UI.
	 *
	 * @return array{is_installed: bool, is_active: bool}
	 */
	function doublescale_get_pro_plugin_status(): array {
		$installed = false;

		if ( defined( 'DOUBLESCALE_PRO_PLUGIN_FILE' ) && is_readable( \DOUBLESCALE_PRO_PLUGIN_FILE ) ) {
			$installed = true;
		} else {
			foreach ( doublescale_get_pro_plugin_basenames() as $basename ) {
				$path = ( defined( 'WP_PLUGIN_DIR' ) ? \WP_PLUGIN_DIR : '' ) . '/' . $basename;
				if ( is_readable( $path ) ) {
					$installed = true;
					break;
				}
			}
		}

		return array(
			'is_installed' => $installed,
			'is_active'    => doublescale_is_pro_addon_active(),
		);
	}
}

if ( ! function_exists( 'doublescale_is_pro_addon_active' ) ) {
	/**
	 * Whether the DoubleScale Pro add-on is active for this site.
	 *
	 * Detection is folder-agnostic: uses Pro constants, active-plugin scan, and
	 * {@see 'doublescale_is_pro_addon_active'} filter (Pro registers true when loaded).
	 */
	function doublescale_is_pro_addon_active(): bool {
		$active = false;

		if ( defined( 'DOUBLESCALE_PRO_VERSION' ) ) {
			$active = true;
		} else {
			foreach ( doublescale_get_pro_plugin_basenames() as $basename ) {
				if ( doublescale_is_plugin_active_basename( $basename ) ) {
					$active = true;
					break;
				}
			}
		}

		/**
		 * Whether DoubleScale Pro is active for this request.
		 *
		 * @param bool $active Detected active state before filtering.
		 */
		return (bool) apply_filters( 'doublescale_is_pro_addon_active', $active );
	}
}

if ( ! function_exists( 'doublescale_sales_online_payments_available' ) ) {
	/**
	 * Whether invoice online-payment gateways (Stripe, PayPal, …) may run.
	 *
	 * Invoices and offline (manual) payments ship in free. Checkout gateways
	 * require DoubleScale Pro.
	 *
	 * @return bool
	 */
	function doublescale_sales_online_payments_available(): bool {
		$available = function_exists( 'doublescale_is_pro_addon_active' ) && doublescale_is_pro_addon_active();

		/**
		 * Filter whether invoice online payments are available.
		 *
		 * @param bool $available Default: Pro add-on is active.
		 */
		return (bool) apply_filters( 'doublescale_sales_online_payments_available', $available );
	}
}

if ( ! function_exists( 'doublescale_uses_legacy_pro_booking_gateway' ) ) {
	/**
	 * Whether an older doublescale-pro build still ships StripeBookingGateway.
	 *
	 * Free 1.2+ removed the legacy PaymentGateway base class; Pro 1.1+ replaced
	 * StripeBookingGateway with BookingStripeHandler. Mismatched pairs fatal without
	 * the compatibility shims restored in PaymentGateway\Loader.
	 *
	 * @return bool
	 */
	function doublescale_uses_legacy_pro_booking_gateway(): bool {
		if ( ! doublescale_is_pro_addon_active() ) {
			return false;
		}

		$pro_dir = defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ? (string) \DOUBLESCALE_PRO_PLUGIN_DIR : '';
		if ( '' === $pro_dir ) {
			foreach ( doublescale_get_pro_plugin_basenames() as $basename ) {
				$main_file = ( defined( 'WP_PLUGIN_DIR' ) ? \WP_PLUGIN_DIR : '' ) . '/' . $basename;
				if ( is_readable( $main_file ) ) {
					$pro_dir = plugin_dir_path( $main_file );
					break;
				}
			}
		}

		if ( '' === $pro_dir ) {
			return false;
		}

		$legacy = $pro_dir . 'includes/Modules/Booking/PaymentGateways/Stripe/StripeBookingGateway.php';
		$modern = $pro_dir . 'includes/Modules/Booking/PaymentGateways/BookingStripeHandler.php';

		return is_readable( $legacy ) && ! is_readable( $modern );
	}
}

add_action(
	'admin_notices',
	static function (): void {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		if ( ! doublescale_uses_legacy_pro_booking_gateway() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__(
			'DoubleScale Pro is outdated for this version of DoubleScale. Update DoubleScale Pro to 1.1.0 or newer to use the unified payment gateway stack.',
			'doublescale'
		);
		echo '</p></div>';
	}
);

if ( ! function_exists( 'doublescale_pro_task_model_available' ) ) {
	/**
	 * Whether the Pro tasks Eloquent model is loaded (unified timelines include tasks).
	 *
	 * Pro ships tasks under {@see \DoubleScale\Pro\Modules\Tasks\Models\TaskModel}.
	 */
	function doublescale_pro_task_model_available(): bool {
		return class_exists( '\DoubleScale\Pro\Modules\Tasks\Models\TaskModel' )
			|| class_exists( '\DoubleScale\Modules\Tasks\Models\TaskModel' );
	}
}

if ( ! function_exists( 'doublescale_resolve_deal_model_class' ) ) {
	/**
	 * Resolves the Deal Eloquent model when Pipelines & Deals (Pro) is active.
	 *
	 * @return string|null Fully-qualified class name.
	 */
	function doublescale_resolve_deal_model_class(): ?string {
		$class = '\DoubleScale\Pro\Modules\Deals\Models\DealModel';
		if ( ! class_exists( $class ) ) {
			return null;
		}

		// Pro still autoloads DealModel when Pipelines is off / tables missing.
		if ( function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'deals', $class ) ) {
			return null;
		}

		return $class;
	}
}

if ( ! function_exists( 'doublescale_client_portal_enabled' ) ) {
	/**
	 * Whether the client portal is on (Settings → Modules). On by default.
	 */
	function doublescale_client_portal_enabled(): bool {
		return function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'portal' );
	}
}

// With the portal off: its endpoints answer "turned off", its page shows nothing.
\DoubleScale\Core\Services\ClientPortalGate::register();

if ( ! function_exists( 'doublescale_portal_can' ) ) {
	/**
	 * Whether a customer can see an area of the Client Portal.
	 *
	 * For add-ons that serve portal data themselves: ask before answering, with
	 * the contact the add-on resolved (it may resolve differently from the
	 * portal). Defaults to the logged-in customer. An area nobody registered is
	 * not governed and answers true. Guard with function_exists(): an older
	 * DoubleScale has no portal access at all.
	 *
	 * @param string                                                 $feature Area key, e.g. 'invoices'.
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel|null $contact Contact; null = the logged-in customer.
	 * @return bool
	 */
	function doublescale_portal_can( string $feature, $contact = null ): bool {
		if ( ! doublescale_client_portal_enabled() ) {
			return false;
		}
		if ( null === \DoubleScale\Modules\Portal\Services\PortalFeatures::get( $feature ) ) {
			return true;
		}

		if ( null === $contact ) {
			$contact = \DoubleScale\Modules\Portal\Services\PortalIdentity::current_contact();
		}

		return \DoubleScale\Modules\Portal\Services\PortalAccess::allows(
			$feature,
			$contact instanceof \DoubleScale\Modules\Contacts\Models\ContactModel ? $contact : null
		);
	}
}

if ( ! function_exists( 'doublescale_sales_documents_ready' ) ) {
	/**
	 * Whether the customer-facing Sales "Documents" portal surface is live.
	 *
	 * Invoices/proposals live in Free Sales, so this is Free + release-gated
	 * (NOT Pro-presence — online card payment is the only Pro-gated piece and it
	 * is handled on the public page the portal links out to). Flip the
	 * `doublescale_sales_documents_ready` filter to false to ship the Sales admin
	 * while the customer Documents surface is still baking.
	 *
	 * @return bool
	 */
	function doublescale_sales_documents_ready(): bool {
		$ready = doublescale_is_module_active( 'sales' );

		/**
		 * Filter whether the Client Portal Documents surface is available.
		 *
		 * @param bool $ready Default readiness (Sales module active).
		 */
		return (bool) apply_filters( 'doublescale_sales_documents_ready', $ready );
	}
}

if ( ! function_exists( 'doublescale_record_company_id' ) ) {
	/**
	 * The company a record (ticket, invoice, proposal, project) belongs to.
	 *
	 * Records belong to a contact. When companies exist (DoubleScale Pro),
	 * a record can belong to the contact's company instead, and all its people
	 * see it in their client portal; Pro answers the filter.
	 *
	 * @param int   $contact_id The record's contact.
	 * @param mixed $requested  The company asked for (0/null = none).
	 * @return int|null
	 */
	function doublescale_record_company_id( $contact_id, $requested ): ?int {
		/**
		 * Filters the company a record belongs to.
		 *
		 * @param int|null $company_id Null: the record is the contact's.
		 * @param int      $contact_id The record's contact.
		 * @param mixed    $requested  The company asked for.
		 */
		$company_id = (int) apply_filters( 'doublescale_record_company_id', null, (int) $contact_id, $requested );
		return $company_id > 0 ? $company_id : null;
	}
}

if ( ! function_exists( 'doublescale_ensure_once' ) ) {
	/**
	 * Run a module's table/column self-heal checks once per plugin version.
	 *
	 * The checks are `SHOW TABLES` / `SHOW COLUMNS` probes that only find work
	 * right after an install or upgrade. Run on every request they cost a few
	 * dozen queries; gated, a steady-state request pays one autoloaded option
	 * read. The stamp is written only after the checks return.
	 *
	 * @param string   $key      Unique key, e.g. 'pro_projects'.
	 * @param string   $version  Version of the code that owns the checks.
	 * @param callable $callback The checks.
	 */
	function doublescale_ensure_once( string $key, string $version, callable $callback ): void {
		$done = get_option( 'doublescale_schema_checks', array() );
		$done = is_array( $done ) ? $done : array();
		if ( isset( $done[ $key ] ) && $done[ $key ] === $version ) {
			return;
		}

		$callback();

		$done[ $key ] = $version;
		update_option( 'doublescale_schema_checks', $done, true );
	}
}

if ( ! function_exists( 'doublescale_get_countries' ) ) {
	function doublescale_get_countries() {
		if ( ! defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			return array();
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		WP_Filesystem();
		$path = DOUBLESCALE_PLUGIN_DIR . 'assets/countries.json';
		if ( ( ! $wp_filesystem || ! file_exists( $path ) )
			&& defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			$pro_path = DOUBLESCALE_PRO_PLUGIN_DIR . 'assets/countries.json';
			if ( $wp_filesystem && file_exists( $pro_path ) ) {
				$path = $pro_path;
			}
		}
		if ( ! $wp_filesystem || ! file_exists( $path ) ) {
			return array();
		}
		$raw = $wp_filesystem->get_contents( $path );
		if ( false === $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}
}

if ( ! function_exists( 'doublescale_get_country_code' ) ) {
	function doublescale_get_country_code( $country_name ) {
		$countries = doublescale_get_countries();
		if ( isset( $countries[ $country_name ] ) ) {
			return $country_name;
		}
		$country_name = ucwords( strtolower( $country_name ) );
		$code         = array_search( $country_name, array_column( $countries, 'name' ), true );

		return $code;
	}
}

if ( ! function_exists( 'doublescale_get_country_name' ) ) {
	function doublescale_get_country_name( $country_code ) {
		$countries = doublescale_get_countries();
		$name      = $countries[ $country_code ]['name'] ?? '';

		return $name;
	}
}

if ( ! function_exists( 'doublescale_validator' ) ) {
	function doublescale_validator() {
		return PluginKernel::instance()->validator;
	}
}

if ( ! function_exists( 'doublescale_get_logger' ) ) {
	function doublescale_get_logger() {
		static $logger = null;

		$class = apply_filters( 'doublescale_log_class', Logger::class );

		if ( null !== $logger && is_string( $class ) && is_a( $logger, $class ) ) {
			return $logger;
		}

		$implements = class_implements( $class );

		if ( is_array( $implements ) && in_array( LoggerInterface::class, $implements, true ) ) {
			$debugging_settings = Settings::get( 'debugging', array() );
			$log_level_setting  = isset( $debugging_settings['log_level'] ) ? $debugging_settings['log_level'] : 'error';

			$threshold = 'error';
			if ( strpos( $log_level_setting, 'debug' ) !== false ) {
				$threshold = 'debug';
			} elseif ( strpos( $log_level_setting, 'info' ) !== false ) {
				$threshold = 'info';
			}

			$logger = is_object( $class ) ? $class : new $class( null, $threshold );
		} else {
			_doing_it_wrong(
				__FUNCTION__,
				wp_kses_post(
					sprintf(
						/* translators: 1: provided class name, 2: filter name, 3: required interface */
						__( 'The class %1$s provided by %2$s filter must implement %3$s.', 'doublescale' ),
						'<code>' . esc_html( is_object( $class ) ? get_class( $class ) : $class ) . '</code>',
						'<code>doublescale_log_class</code>',
						'<code>LoggerInterface</code>'
					)
				),
				'1.0.0'
			);

			$logger = is_a( $logger, Logger::class ) ? $logger : new Logger();
		}

		return $logger;
	}
}

if ( ! function_exists( 'doublescale_cleanup_logs' ) ) {
	function doublescale_cleanup_logs() {
		$logger = doublescale_get_logger();

		if ( is_callable( array( $logger, 'clear_expired_logs' ) ) ) {
			$logger->clear_expired_logs();
		}
	}
	add_action( 'doublescale_cleanup_logs', 'doublescale_cleanup_logs' );
}

if ( ! function_exists( 'doublescale_add_contact_meta' ) ) {
	function doublescale_add_contact_meta( $contact_id, $meta_key, $meta_value, $unique = false ) {
		return add_metadata( 'contact', $contact_id, $meta_key, $meta_value, $unique );
	}
}

if ( ! function_exists( 'doublescale_update_contact_meta' ) ) {
	function doublescale_update_contact_meta( $contact_id, $meta_key, $meta_value, $prev_value = '' ) {
		return update_metadata( 'contact', $contact_id, $meta_key, $meta_value, $prev_value );
	}
}

if ( ! function_exists( 'doublescale_get_contact_meta' ) ) {
	function doublescale_get_contact_meta( $contact_id, $meta_key = '', $single = false ) {
		return get_metadata( 'contact', $contact_id, $meta_key, $single );
	}
}

if ( ! function_exists( 'doublescale_delete_contact_meta' ) ) {
	function doublescale_delete_contact_meta( $contact_id, $meta_key, $meta_value = '' ) {
		return delete_metadata( 'contact', $contact_id, $meta_key, $meta_value );
	}
}

if ( ! function_exists( 'doublescale_get_meta_args' ) ) {
	function doublescale_get_meta_args( $meta_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no meta API available. Caching by ID is not useful for single-row lookups inside request-scoped helpers.
		$meta = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}doublescale_task_meta WHERE ID = %d",
				$meta_id
			),
			ARRAY_A
		);

		if ( ! $meta || empty( $meta['value'] ) ) {
			return false;
		}

		return maybe_unserialize( $meta['value'] );
	}
}

if ( ! function_exists( 'doublescale_objects_find' ) ) {
	function doublescale_objects_find( $objects, $key, $value ) {
		foreach ( $objects as $object ) {
			if ( $object->{$key} === $value ) {
				return $object;
			}
		}
		return null;
	}
}

if ( ! function_exists( 'doublescale_get_smtp_email_log' ) ) {
	/**
	 * Email log handler for the SMTP module (outbound mail audit trail).
	 *
	 * @return \DoubleScale\Modules\Smtp\EmailLog\EmailLogHandler|null
	 */
	function doublescale_get_smtp_email_log() {
		if ( ! class_exists( \DoubleScale\Modules\Smtp\EmailLog\EmailLogHandler::class ) ) {
			return null;
		}
		return \DoubleScale\Core\ModuleManager::whenEnabled(
			'smtp',
			static function () {
				return \DoubleScale\Modules\Smtp\EmailLog\EmailLogHandler::get_instance();
			}
		);
	}
}
