<?php
/**
 * Logging API (forked from WooCommerce pattern).
 *
 * @package DoubleScale\Core\Logger
 */

namespace DoubleScale\Core\Logger;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;

/**
 * Logger class.
 */
class Logger implements LoggerInterface {

	/**
	 * @var array
	 */
	protected $handlers;

	/**
	 * @var int|null
	 */
	protected $threshold;

	/**
	 * @param array|null  $handlers
	 * @param string|null $threshold
	 */
	public function __construct( $handlers = null, $threshold = null ) {
		if ( null === $handlers ) {
			$handlers = apply_filters( 'doublescale_log_handler_register', array() );
		}

		$register_handlers = array();

		if ( ! empty( $handlers ) && is_array( $handlers ) ) {
			foreach ( $handlers as $handler ) {
				$implements = class_implements( $handler );
				if ( is_object( $handler ) && is_array( $implements ) && in_array( LogHandlerInterface::class, $implements, true ) ) {
					$register_handlers[] = $handler;
				} else {
					_doing_it_wrong(
						__METHOD__,
						wp_kses_post(
							sprintf(
								/* translators: 1: class name 2: LogHandlerInterface */
								__( 'The provided handler %1$s does not implement %2$s.', 'doublescale' ),
								'<code>' . esc_html( is_object( $handler ) ? get_class( $handler ) : $handler ) . '</code>',
								'<code>LogHandlerInterface</code>'
							)
						),
						'1.0.0'
					);
				}
			}
		}

		if ( null === $threshold ) {
			if ( LogLevels::is_valid_level( $threshold ) ) {
				$threshold = null;
			}
		}

		if ( null !== $threshold ) {
			$threshold = LogLevels::get_level_severity( $threshold );
		}

		$this->handlers  = $register_handlers;
		$this->threshold = $threshold;
	}

	/**
	 * @param string $level emergency|alert|critical|error|warning|notice|info|debug.
	 */
	protected function should_handle( $level ) {
		if ( null !== $this->threshold ) {
			$level_severity = LogLevels::get_level_severity( $level );
			if ( $this->threshold > $level_severity ) {
				return false;
			}
		}

		$allowed_levels = $this->get_allowed_levels();

		$always_allowed = array( 'error', 'critical', 'alert', 'emergency' );
		if ( in_array( strtolower( $level ), $always_allowed, true ) ) {
			return true;
		}

		return in_array( strtolower( $level ), $allowed_levels, true );
	}

	/**
	 * @return string[]
	 */
	protected function get_allowed_levels() {
		$debugging_settings = Settings::get( 'debugging', array() );
		$log_level_setting  = isset( $debugging_settings['log_level'] ) ? $debugging_settings['log_level'] : 'error';

		$allowed = array( 'error', 'critical', 'alert', 'emergency' );

		if ( 'error' === $log_level_setting ) {
			return $allowed;
		}

		if ( strpos( $log_level_setting, 'debug' ) !== false ) {
			$allowed[] = 'debug';
		}

		if ( strpos( $log_level_setting, 'info' ) !== false ) {
			$allowed[] = 'info';
			$allowed[] = 'notice';
		}

		return $allowed;
	}

	/**
	 * @param string $handle
	 * @param string $message
	 * @param string $level
	 * @return bool
	 */
	public function add( $handle, $message, $level = LogLevels::NOTICE ) {
		$message = apply_filters( 'doublescale_log_add_message', $message, $handle );
		$this->log(
			$level,
			$message,
			array(
				'source' => $handle,
			)
		);
		return true;
	}

	/**
	 * @param string               $level
	 * @param string               $message
	 * @param array<string, mixed> $context
	 */
	public function log( $level, $message, $context = array() ) {
		if ( ! LogLevels::is_valid_level( $level ) ) {
			_doing_it_wrong(
				__METHOD__,
				wp_kses_post(
					sprintf(
						/* translators: 1: Logger::log 2: level */
						__( '%1$s was called with an invalid level "%2$s".', 'doublescale' ),
						'<code>Logger::log</code>',
						esc_html( $level )
					)
				),
				'1.0.0'
			);
		}

		if ( $this->should_handle( $level ) ) {
			$timestamp = time();
			$message   = apply_filters( 'doublescale_log_message', $message, $level, $context );

			foreach ( $this->handlers as $handler ) {
				$handler->handle( $timestamp, $level, $message, $context );
			}
		}
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function emergency( $message, $context = array() ) {
		$this->log( LogLevels::EMERGENCY, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function alert( $message, $context = array() ) {
		$this->log( LogLevels::ALERT, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function critical( $message, $context = array() ) {
		$this->log( LogLevels::CRITICAL, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function error( $message, $context = array() ) {
		$this->log( LogLevels::ERROR, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function warning( $message, $context = array() ) {
		$this->log( LogLevels::WARNING, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function notice( $message, $context = array() ) {
		$this->log( LogLevels::NOTICE, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function info( $message, $context = array() ) {
		$this->log( LogLevels::INFO, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function debug( $message, $context = array() ) {
		$this->log( LogLevels::DEBUG, $message, $context );
	}

	/**
	 * @param string $source
	 * @return bool
	 */
	public function clear( $source = '' ) {
		if ( ! $source ) {
			return false;
		}
		foreach ( $this->handlers as $handler ) {
			if ( is_callable( array( $handler, 'clear' ) ) ) {
				$handler->clear( $source );
			}
		}
		return true;
	}

	public function clear_expired_logs() {
		$days      = absint( apply_filters( 'doublescale_log_retention_days', 30 ) );
		$timestamp = strtotime( "-{$days} days" );

		foreach ( $this->handlers as $handler ) {
			if ( is_callable( array( $handler, 'delete_logs_before_timestamp' ) ) ) {
				$handler->delete_logs_before_timestamp( $timestamp );
			}
		}
	}
}
