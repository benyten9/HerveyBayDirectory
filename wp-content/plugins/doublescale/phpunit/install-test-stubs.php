<?php
/**
 * Extra WordPress stubs for {@see \DoubleScale\Tests\FreeStandaloneActivationTest} only.
 *
 * @package DoubleScale\Tests
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['__doublescale_phpunit_install_dbdelta'] = array();

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * @param string|string[] $queries
	 * @return array<string, mixed>
	 */
	function dbDelta( $queries ) {
		$GLOBALS['__doublescale_phpunit_install_dbdelta'][] = $queries;
		return array();
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * @param string $type
	 * @param bool   $gmt
	 * @return int|string
	 */
	function current_time( $type = 'mysql', $gmt = false ) {
		unset( $gmt );
		return 'mysql' === $type ? '2000-01-01 00:00:00' : time();
	}
}

if ( ! function_exists( 'esc_sql' ) ) {
	/**
	 * @param string $sql
	 * @return string
	 */
	function esc_sql( $sql ) {
		return str_replace( array( "\0", "\r", "\n" ), '', (string) $sql );
	}
}

if ( ! function_exists( 'maybe_serialize' ) ) {
	/**
	 * @param mixed $data
	 * @return string
	 */
	function maybe_serialize( $data ) {
		return is_scalar( $data ) ? (string) $data : serialize( $data );
	}
}

/**
 * Minimal in-memory WP_Role stand-in for the role-capability provisioning that
 * {@see \DoubleScale\Core\UserRoles\UserRoles::add_roles_and_capabilities()}
 * performs during {@see \DoubleScale\Database\Install::install()}. Stores caps
 * in an array so add/has/remove behave correctly without a real WordPress.
 */
if ( ! class_exists( 'DoubleScale_Test_Stub_Role' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test-only stub colocated with the function stubs it supports.
	class DoubleScale_Test_Stub_Role {

		/** @var string */
		public $name;

		/** @var array<string, bool> */
		public $capabilities = array();

		public function __construct( $name, array $capabilities = array() ) {
			$this->name         = (string) $name;
			$this->capabilities = $capabilities;
		}

		public function add_cap( $cap, $grant = true ) {
			$this->capabilities[ (string) $cap ] = (bool) $grant;
		}

		public function has_cap( $cap ) {
			return ! empty( $this->capabilities[ (string) $cap ] );
		}

		public function remove_cap( $cap ) {
			unset( $this->capabilities[ (string) $cap ] );
		}
	}
}

/**
 * Registry backing the get_role / add_role stubs. Pre-seeded with
 * `administrator` since add_roles_and_capabilities() always grants caps to it.
 */
$GLOBALS['__doublescale_phpunit_roles'] = array(
	'administrator' => new DoubleScale_Test_Stub_Role( 'administrator' ),
);

if ( ! function_exists( 'get_role' ) ) {
	/**
	 * @param string $role
	 * @return DoubleScale_Test_Stub_Role|null
	 */
	function get_role( $role ) {
		return $GLOBALS['__doublescale_phpunit_roles'][ (string) $role ] ?? null;
	}
}

if ( ! function_exists( 'add_role' ) ) {
	/**
	 * @param string              $role
	 * @param string              $display_name
	 * @param array<string, bool> $capabilities
	 * @return DoubleScale_Test_Stub_Role
	 */
	function add_role( $role, $display_name = '', $capabilities = array() ) {
		unset( $display_name );
		$obj = new DoubleScale_Test_Stub_Role( $role, (array) $capabilities );
		$GLOBALS['__doublescale_phpunit_roles'][ (string) $role ] = $obj;
		return $obj;
	}
}
