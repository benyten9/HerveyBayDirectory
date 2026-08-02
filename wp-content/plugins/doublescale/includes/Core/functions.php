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
	function doublescale_is_plugin_active( $plugin_name ) {
		return doublescale_is_plugin_active_basename( (string) $plugin_name );
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
