<?php
/**
 * Logger Interface
 * This class is forked from Woocommerce.
 *
 * @package DoubleScale\Pro
 * @since 1.0.0
 */

namespace DoubleScale\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * QF Logger Interface
 *
 * Functions that must be defined to correctly fulfill logger Api.
 *
 * @version 1.0.0
 */
interface LoggerInterface {

	/**
	 * Add a log entry.
	 *
	 * @param string $handle File handle.
	 * @param string $message Log message.
	 * @param string $level Log level.
	 *
	 * @return bool True if log was added, otherwise false.
	 */
	public function add( $handle, $message, $level = LogLevels::NOTICE );

	/**
	 * Add a log entry.
	 *
	 * @param string $level Log level.
	 * @param string $message Log message.
	 * @param array  $context Optional. Additional information for log handlers.
	 */
	public function log( $level, $message, $context = array() );

	/**
	 * @param string $message Log message.
	 * @param array  $context Optional.
	 */
	public function emergency( $message, $context = array() );

	/**
	 * @param string $message Log message.
	 * @param array  $context Optional.
	 */
	public function alert( $message, $context = array() );

	/**
	 * @param string $message Log message.
	 * @param array  $context Optional.
	 */
	public function critical( $message, $context = array() );

	/**
	 * @param string $message Log message.
	 * @param array  $context Optional.
	 */
	public function error( $message, $context = array() );

	/**
	 * @param string $message Log message.
	 * @param array  $context Optional.
	 */
	public function warning( $message, $context = array() );

	/**
	 * @param string $message Log message.
	 * @param array  $context Optional.
	 */
	public function notice( $message, $context = array() );

	/**
	 * @param string $message Log message.
	 * @param array  $context Optional.
	 */
	public function info( $message, $context = array() );

	/**
	 * @param string $message Log message.
	 * @param array  $context Optional.
	 */
	public function debug( $message, $context = array() );
}
