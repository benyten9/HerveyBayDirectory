<?php
/**
 * Minimal WordPress REST API stubs for PHPUnit route registration tests.
 * Loaded only by {@see \DoubleScale\Tests\RestAllEndpointsRegistrationTest}.
 *
 * @package DoubleScale\Pro\Tests
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every {@see register_rest_route()} call appends here for assertions.
 *
 * @var array<int, array{namespace: string, route: string, args: mixed}>
 */
$GLOBALS['__doublescale_phpunit_rest_registrations'] = array();

if ( ! class_exists( 'WP_REST_Server', false ) ) {
	class WP_REST_Server {
		public const READABLE   = 1;
		public const CREATABLE  = 2;
		public const EDITABLE   = 4;
		public const DELETABLE  = 8;
		public const ALLMETHODS = self::READABLE | self::CREATABLE | self::EDITABLE | self::DELETABLE;
	}
}

if ( ! class_exists( 'WP_REST_Request', false ) ) {
	class WP_REST_Request {
		/** @var array<string, mixed> */
		public $params = array();

		/**
		 * @param string $key
		 * @return mixed
		 */
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * @param string $key
		 * @return string|null
		 */
		public function get_header( $key ) {
			unset( $key );
			return null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response', false ) ) {
	class WP_REST_Response {
		/** @var mixed */
		public $data;

		/** @var int */
		public $status = 200;

		/**
		 * @param mixed $data
		 * @param int   $status
		 */
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = (int) $status;
		}
	}
}

if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {
		/** @var string */
		private $code = '';

		/** @var string */
		private $message = '';

		/** @var mixed */
		private $data = '';

		/**
		 * @param string|int $code
		 * @param string     $message
		 * @param mixed      $data Optional error data (status code, context).
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * Real WP_Error carries the `status` and context callers assert on;
		 * dropping it here made those assertions impossible to write.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Tests set $GLOBALS['__doublescale_phpunit_current_user_id'] to switch user.
	 *
	 * @return int
	 */
	function get_current_user_id() {
		return (int) ( $GLOBALS['__doublescale_phpunit_current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	/**
	 * Minimal user stub. Tests populate
	 * $GLOBALS['__doublescale_phpunit_users'][ $user_id ] = array( 'roles' => array( … ) ).
	 *
	 * @param int $user_id User id.
	 * @return object|false
	 */
	function get_userdata( $user_id ) {
		$users = $GLOBALS['__doublescale_phpunit_users'] ?? array();
		if ( ! isset( $users[ $user_id ] ) ) {
			return false;
		}

		return (object) array_merge(
			array(
				'ID'         => (int) $user_id,
				'roles'      => array(),
				'user_email' => '',
			),
			(array) $users[ $user_id ]
		);
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * @param string               $namespace
	 * @param string               $route
	 * @param array<string, mixed> $args
	 * @return bool
	 */
	function register_rest_route( $namespace, $route, $args = array() ) {
		$GLOBALS['__doublescale_phpunit_rest_registrations'][] = array(
			'namespace' => (string) $namespace,
			'route'     => (string) $route,
			'args'      => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'rest_get_endpoint_args_for_schema' ) ) {
	/**
	 * @param array<string, mixed> $schema
	 * @param string               $method
	 * @return array<string, mixed>
	 */
	function rest_get_endpoint_args_for_schema( $schema, $method = \WP_REST_Server::CREATABLE ) {
		unset( $schema, $method );
		return array();
	}
}

if ( ! function_exists( '__return_true' ) ) {
	function __return_true() {
		return true;
	}
}

if ( ! function_exists( '__return_false' ) ) {
	function __return_false() {
		return false;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		unset( $capability, $args );
		return false;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		unset( $domain );
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) {
		return htmlspecialchars_decode( (string) $text, $quote_style );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Tests set $GLOBALS['__doublescale_phpunit_bloginfo'][ $key ].
	 *
	 * @param string $show Field.
	 * @return string
	 */
	function get_bloginfo( $show = '' ) {
		return (string) ( $GLOBALS['__doublescale_phpunit_bloginfo'][ $show ] ?? '' );
	}
}

if ( ! function_exists( 'get_rest_url' ) ) {
	function get_rest_url( $blog_id = null, $path = '/', $scheme = 'rest' ) {
		unset( $blog_id, $scheme );
		return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'user_can' ) ) {
	/**
	 * Capability check for a specific user (not the current one).
	 *
	 * Tests populate
	 * $GLOBALS['__doublescale_phpunit_user_caps'][ $user_id ] = array( 'manage_options' => true ).
	 *
	 * @param int|object $user       User id or user object.
	 * @param string     $capability Capability.
	 * @return bool
	 */
	function user_can( $user, $capability, ...$args ) {
		unset( $args );

		$user_id = is_object( $user ) ? (int) ( $user->ID ?? 0 ) : (int) $user;
		$caps    = $GLOBALS['__doublescale_phpunit_user_caps'][ $user_id ] ?? array();

		return ! empty( $caps[ $capability ] );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return false;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return (int) abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return is_string( $str ) ? $str : (string) $str;
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return is_string( $str ) ? $str : (string) $str;
	}
}

if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	function rest_authorization_required_code() {
		return 401;
	}
}
