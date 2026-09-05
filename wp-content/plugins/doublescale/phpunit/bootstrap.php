<?php
/**
 * PHPUnit bootstrap for DoubleScale (free) — minimal WordPress stubs when WP is not loaded.
 *
 * @package DoubleScale\Tests
 */

$plugin_root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $plugin_root . '/phpunit/wp-abspath/' );
}

if ( ! defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
	define( 'DOUBLESCALE_PLUGIN_DIR', rtrim( $plugin_root, '/\\' ) . '/' );
}

if ( ! defined( 'DOUBLESCALE_PLUGIN_URL' ) ) {
	define( 'DOUBLESCALE_PLUGIN_URL', 'http://example.test/wp-content/plugins/doublescale/' );
}

if ( ! defined( 'DOUBLESCALE_VERSION' ) ) {
	define( 'DOUBLESCALE_VERSION', '0.0.0-phpunit' );
}

if ( ! defined( 'DOUBLESCALE_PLUGIN_FILE' ) ) {
	define( 'DOUBLESCALE_PLUGIN_FILE', $plugin_root . '/doublescale.php' );
}

if ( ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', '127.0.0.1' );
}
if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'phpunit' );
}
if ( ! defined( 'DB_USER' ) ) {
	define( 'DB_USER', 'phpunit' );
}
if ( ! defined( 'DB_PASSWORD' ) ) {
	define( 'DB_PASSWORD', '' );
}
if ( ! defined( 'DB_CHARSET' ) ) {
	define( 'DB_CHARSET', 'utf8' );
}
if ( ! defined( 'DB_COLLATE' ) ) {
	define( 'DB_COLLATE', '' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['__doublescale_phpunit_transients'] = array();

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		unset( $expiration );
		$GLOBALS['__doublescale_phpunit_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		return $GLOBALS['__doublescale_phpunit_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		unset( $GLOBALS['__doublescale_phpunit_transients'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'doublescale_is_plugin_active' ) ) {
	/**
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	function doublescale_is_plugin_active( $plugin ) {
		unset( $plugin );
		return false;
	}
}

if ( empty( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class() {
		/** @var string */
		public $prefix = 'wp_';
	};
}

// Minimal WP REST shim so PHPUnit can autoload REST controller classes without WordPress.
if ( ! class_exists( 'WP_REST_Server', false ) ) {
	class WP_REST_Server {
		public const READABLE   = 1;
		public const CREATABLE  = 2;
		public const EDITABLE   = 4;
		public const DELETABLE  = 8;
		public const ALLMETHODS = self::READABLE | self::CREATABLE | self::EDITABLE | self::DELETABLE;
	}
}

if ( ! class_exists( 'WP_REST_Controller', false ) ) {
	class WP_REST_Controller {
		/**
		 * @return void
		 */
		public function register_routes() {
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_collection_params() {
			return array();
		}

		/**
		 * @param int|string $method
		 * @return array<string, mixed>
		 */
		public function get_endpoint_args_for_item_schema( $method = WP_REST_Server::CREATABLE ) {
			unset( $method );
			return array();
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_context_param() {
			return array();
		}
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * @param string $type
	 * @param bool   $gmt
	 * @return int|string
	 */
	function current_time( $type = 'mysql', $gmt = false ) {
		$timestamp = time();
		if ( 'timestamp' === $type ) {
			return $timestamp;
		}
		if ( 'Y-m-d' === $type ) {
			return gmdate( 'Y-m-d', $timestamp );
		}
		unset( $gmt );
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * @param string $show
	 * @return string
	 */
	function get_bloginfo( $show = '' ) {
		if ( 'name' === $show ) {
			return 'DoubleScale Test Site';
		}
		if ( 'url' === $show ) {
			return 'http://example.test';
		}
		return '';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * @param string $path
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'http://example.test' . $path;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text
	 * @param string $domain
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * @param string $text
	 * @param string $domain
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) {
		unset( $domain );
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * @param string $text
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * @param mixed $args
	 * @param mixed $defaults
	 * @return array
	 */
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_object( $args ) ) {
			$parsed = get_object_vars( $args );
		} elseif ( is_array( $args ) ) {
			$parsed = $args;
		} else {
			parse_str( (string) $args, $parsed );
		}

		if ( is_array( $defaults ) && $defaults ) {
			return array_merge( $defaults, $parsed );
		}

		return $parsed;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string       $url
	 * @param array<mixed> $protocols
	 * @return string
	 */
	function esc_url_raw( $url, $protocols = null ) {
		unset( $protocols );
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url
	 * @param int    $component
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $string
	 * @return string
	 */
	function wp_strip_all_tags( $string ) {
		return strip_tags( (string) $string );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * @param string $data
	 * @return string
	 */
	function wp_kses_post( $data ) {
		return (string) $data;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * @param string $text
	 * @param string $domain
	 * @return void
	 */
	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html( __( $text, $domain ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * @param float|int $number
	 * @param int       $decimals
	 * @return string
	 */
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals, '.', ',' );
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return false;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	/**
	 * @param mixed $default
	 * @return mixed
	 */
	function get_site_option( $option, $default = false ) {
		unset( $option );
		return $default;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * @param string $str
	 * @return string
	 */
	function sanitize_textarea_field( $str ) {
		return trim( (string) $str );
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	/**
	 * @param string $file
	 * @return string
	 */
	function plugin_basename( $file ) {
		$file = str_replace( '\\', '/', (string) $file );
		$plugins_pos = strpos( $file, '/plugins/' );
		if ( false !== $plugins_pos ) {
			return substr( $file, $plugins_pos + strlen( '/plugins/' ) );
		}
		return basename( $file );
	}
}

$GLOBALS['__doublescale_phpunit_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param mixed $default
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['__doublescale_phpunit_options'] )
			? $GLOBALS['__doublescale_phpunit_options'][ $option ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['__doublescale_phpunit_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		unset( $GLOBALS['__doublescale_phpunit_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * @param string $scheme Salt scheme.
	 * @return string
	 */
	function wp_salt( $scheme ) {
		return 'phpunit-salt-' . (string) $scheme;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * @param array<string, mixed>|string $key   Query args or key.
	 * @param mixed                       $value Value when key is string.
	 * @param string                      $url   Base URL.
	 * @return string
	 */
	function add_query_arg( $key, $value = null, $url = null ) {
		if ( is_array( $key ) ) {
			$args = $key;
			$url  = null === $value ? '' : (string) $value;
		} else {
			$args = array( (string) $key => $value );
			$url  = null === $url ? '' : (string) $url;
		}
		$base = '' === $url ? 'http://example.test/' : $url;
		$join = false !== strpos( $base, '?' ) ? '&' : '?';
		return $base . $join . http_build_query( $args );
	}
}

$GLOBALS['__doublescale_phpunit_hooks']   = array();
$GLOBALS['__doublescale_phpunit_filters'] = array();

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param int $accepted_args Ignored; matches WordPress signature.
	 */
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $accepted_args );
		if ( ! isset( $GLOBALS['__doublescale_phpunit_hooks'][ $hook_name ] ) ) {
			$GLOBALS['__doublescale_phpunit_hooks'][ $hook_name ] = array();
		}
		$p = (int) $priority;
		if ( ! isset( $GLOBALS['__doublescale_phpunit_hooks'][ $hook_name ][ $p ] ) ) {
			$GLOBALS['__doublescale_phpunit_hooks'][ $hook_name ][ $p ] = array();
		}
		$GLOBALS['__doublescale_phpunit_hooks'][ $hook_name ][ $p ][] = $callback;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * @param mixed ...$args
	 */
	function do_action( $hook_name, ...$args ) {
		if ( empty( $GLOBALS['__doublescale_phpunit_hooks'][ $hook_name ] ) ) {
			return;
		}
		ksort( $GLOBALS['__doublescale_phpunit_hooks'][ $hook_name ] );
		foreach ( $GLOBALS['__doublescale_phpunit_hooks'][ $hook_name ] as $callbacks ) {
			foreach ( (array) $callbacks as $cb ) {
				$cb( ...$args );
			}
		}
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param int $accepted_args Ignored; matches WordPress signature.
	 */
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $accepted_args );
		if ( ! isset( $GLOBALS['__doublescale_phpunit_filters'][ $hook_name ] ) ) {
			$GLOBALS['__doublescale_phpunit_filters'][ $hook_name ] = array();
		}
		$p = (int) $priority;
		if ( ! isset( $GLOBALS['__doublescale_phpunit_filters'][ $hook_name ][ $p ] ) ) {
			$GLOBALS['__doublescale_phpunit_filters'][ $hook_name ][ $p ] = array();
		}
		$GLOBALS['__doublescale_phpunit_filters'][ $hook_name ][ $p ][] = $callback;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * @param mixed $callback Same instance as passed to add_filter.
	 */
	function remove_filter( $hook_name, $callback, $priority = 10 ) {
		$p = (int) $priority;
		if ( empty( $GLOBALS['__doublescale_phpunit_filters'][ $hook_name ][ $p ] ) ) {
			return false;
		}
		$callbacks = &$GLOBALS['__doublescale_phpunit_filters'][ $hook_name ][ $p ];
		foreach ( $callbacks as $i => $cb ) {
			if ( $cb === $callback ) {
				array_splice( $callbacks, $i, 1 );
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param mixed $value
	 * @param mixed ...$args
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value, ...$args ) {
		if ( empty( $GLOBALS['__doublescale_phpunit_filters'][ $hook_name ] ) ) {
			return $value;
		}
		$carry = $value;
		ksort( $GLOBALS['__doublescale_phpunit_filters'][ $hook_name ] );
		foreach ( $GLOBALS['__doublescale_phpunit_filters'][ $hook_name ] as $callbacks ) {
			foreach ( (array) $callbacks as $cb ) {
				$carry = $cb( $carry, ...$args );
			}
		}
		return $carry;
	}
}

require_once $plugin_root . '/vendor/autoload.php';
if ( is_file( $plugin_root . '/dependencies/build/vendor/scoper-autoload.php' ) ) {
	require_once $plugin_root . '/dependencies/build/vendor/scoper-autoload.php';
} elseif ( is_readable( $plugin_root . '/dependencies/vendor/autoload.php' ) ) {
	require_once $plugin_root . '/dependencies/vendor/autoload.php';
}
require_once $plugin_root . '/includes/Autoload.php';
