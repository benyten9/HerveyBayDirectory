<?php
/**
 * Administrative AI availability, budgets, usage and retention.
 *
 * @package DirectoristAIPro
 */

// phpcs:disable NormalizedArrays.Arrays.CommaAfterLast.MissingMultiLine -- Retain exact reviewed PHP tokens.
namespace DirectoristAIPro\Chat;

use DirectoristAIPro\Runtime\ActionSchedulerQueue;
use DirectoristAIPro\Storage\Database;

/** Policy writes remain outside model-accessible abilities. */
final class Operations {
	private const OPTION = 'directorist_ai_pro_operations';

	/**
	 * Return the default settings.
	 */
	public static function defaults(): array {
		return array_fill_keys( array_keys( UsageLimits::MAXIMUMS ), null ) + array(
			'guest_retention_days'        => 7,
			'user_retention_days'         => 90,
			'audit_retention_days'        => 90,
			'task_receipt_retention_days' => 365,
			'emergency_stop'              => false,
			'global_launcher'             => false,
			'chat_default_directory'      => '',
			'chat_allowed_directories'    => array(),
			'chat_purpose'                => '',
			'chat_tone'                   => 'friendly',
			'usage_limits_revision'       => UsageLimits::REVISION,
		);
	}

	/**
	 * Return sanitized saved settings.
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), UsageLimits::normalize( is_array( $saved ) ? array_intersect_key( $saved, self::defaults() ) : array() ) );
	}

	/** Public directory identities only; no field definitions or private listing data. */
	public static function directory_catalog(): array {
		if ( ! defined( 'ATBDP_DIRECTORY_TYPE' ) || ! function_exists( 'directorist_is_directory' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => ATBDP_DIRECTORY_TYPE,
				'hide_empty' => false
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$result = array();
		foreach ( $terms as $term ) {
			if ( directorist_is_directory( $term->term_id ) ) {
				$result[] = array(
					'id'   => (string) $term->term_id,
					'name' => wp_strip_all_tags( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) )
				);
			}
		}
		return $result;
	}

	/** Empty allow-list means all current native directories, never an unbound scope. */
	public static function public_directories(): array {
		$allowed = self::settings()['chat_allowed_directories'];
		return array_values( array_filter( self::directory_catalog(), static fn( array $directory ): bool => ! $allowed || in_array( $directory['id'], $allowed, true ) ) );
	}

	/**
	 * Validate and save settings.
	 *
	 * @param array $input Reviewed input.
	 */
	public static function save( array $input ): array|\WP_Error {
		$defaults = self::defaults();
		if ( array_diff( array_keys( $input ), array_keys( $defaults ) ) ) {
			return new \WP_Error( 'invalid_input', 'Operational setting is unsupported.', array( 'status' => 400 ) );
		}
		$next = self::settings();
		foreach ( $input as $key => $value ) {
			if ( 'usage_limits_revision' === $key ) {
				if ( UsageLimits::REVISION !== $value ) { return new \WP_Error( 'invalid_input', 'Usage policy version is unsupported.', array( 'status' => 400 ) ); }
			} elseif ( array_key_exists( $key, UsageLimits::MAXIMUMS ) ) {
				if ( null !== $value && ( ! is_int( $value ) || $value < 1 || $value > UsageLimits::MAXIMUMS[ $key ] ) ) {
					return new \WP_Error( 'invalid_input', 'Enter an available limit or use available credits.', array( 'status' => 400 ) );
				}
			} elseif ( 'chat_purpose' === $key ) {
				if ( ! is_string( $value ) || mb_strlen( $value ) > 2000 ) {
					return new \WP_Error( 'invalid_input', 'Describe the site purpose in up to 2000 characters.', array( 'status' => 400 ) ); }
				$next[ $key ] = sanitize_textarea_field( $value );
				continue;
			} elseif ( 'chat_tone' === $key ) {
				if ( ! in_array( $value, array( 'friendly', 'professional', 'concise' ), true ) ) {
					return new \WP_Error( 'invalid_input', 'Choose an available chat tone.', array( 'status' => 400 ) ); }
				$next[ $key ] = $value;
				continue;
			} elseif ( 'chat_allowed_directories' === $key ) {
				$ids = array_column( self::directory_catalog(), 'id' );
				if ( ! is_array( $value ) || array_values( $value ) !== $value || count( $value ) > 100 || count( array_unique( $value, SORT_REGULAR ) ) !== count( $value ) ) {
					return new \WP_Error( 'invalid_input', 'Choose valid chat directories.', array( 'status' => 400 ) );
				}
				foreach ( $value as $directory ) {
					if ( ! is_string( $directory ) || ! in_array( $directory, $ids, true ) ) {
						return new \WP_Error( 'invalid_input', 'A selected directory is unavailable.', array( 'status' => 400 ) );
					}
				}
			} elseif ( 'chat_default_directory' === $key ) {
				if ( ! is_string( $value ) || ( '' !== $value && ! in_array( $value, array_column( self::directory_catalog(), 'id' ), true ) ) ) {
					return new \WP_Error( 'invalid_input', 'Choose an available default directory.', array( 'status' => 400 ) );
				}
			} elseif ( in_array( $key, array( 'emergency_stop', 'global_launcher' ), true ) ) {
				if ( ! is_bool( $value ) ) {
					return new \WP_Error( 'invalid_input', 'Operational switch must be Boolean.', array( 'status' => 400 ) );
				}
			} else {
				$maximum = false !== strpos( $key, 'retention_days' ) ? ( 'guest_retention_days' === $key ? 7 : 365 ) : ( false !== strpos( $key, 'rounds' ) || in_array( $key, array( 'site_ai_tasks', 'visitor_ai_tasks' ), true ) ? 5000 : 4000000 );
				if ( ! is_int( $value ) || $value < 1 || $value > $maximum ) {
					return new \WP_Error( 'invalid_input', 'Operational limit is outside the supported range.', array( 'status' => 400 ) );
				}
			}
			$next[ $key ] = $value;
		}
		if ( '' !== $next['chat_default_directory'] && $next['chat_allowed_directories'] && ! in_array( $next['chat_default_directory'], $next['chat_allowed_directories'], true ) ) {
			return new \WP_Error( 'invalid_input', 'The default must be an enabled chat directory.', array( 'status' => 400 ) );
		}
		update_option( self::OPTION, $next, false );
		update_option( 'directorist_ai_pro_emergency_stop', $next['emergency_stop'], false );
		update_option( 'directorist_ai_pro_global_launcher', $next['global_launcher'], false );
		return $next;
	}

	/**
	 * Sanitized counters and diagnostics, excluding prompts, keys and reasoning.
	 *
	 * @param Database $db Reviewed input.
	 */
	public static function status( Database $db ): array {
		$text_supported  = false;
		$image_supported = false;
		if ( function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai() ) {
			try {
				$probe           = wp_ai_client_prompt( 'Provider capability check.' );
				$text_supported  = $probe->is_supported_for_text_generation();
				$image_supported = $probe->is_supported_for_image_generation();
			} catch ( \Throwable $error ) {
				$text_supported  = false;
				$image_supported = false;
			}
		}
		$usage = array();
		if ( $db->readiness()->status()['effects_enabled'] ) {
			$table = $db->table( 'usage_windows' );
			$usage = $db->rows( "SELECT scope_key,model_rounds,input_tokens,output_tokens FROM $table WHERE scope_key=%s AND window_start=%s LIMIT 1", array( 'site:' . get_current_blog_id(), gmdate( 'Y-m-d 00:00:00' ) ) );
		}
		$queue = ( new ActionSchedulerQueue() )->health();
		return array(
			'settings'    => self::settings(),
			'directories' => self::directory_catalog(),
			'provider'    => array(
				'supported'         => $text_supported,
				'image_supported'   => $image_supported,
				'configuration_url' => admin_url( 'options-connectors.php' )
			),
			'queue'       => array(
				'available' => (bool) $queue['available'],
				'version'   => $queue['version']
			),
			'usage_day'   => $usage ? array(
				'model_rounds'  => (int) $usage[0]['model_rounds'],
				'input_tokens'  => (int) $usage[0]['input_tokens'],
				'output_tokens' => (int) $usage[0]['output_tokens']
			) : array(
				'model_rounds'  => 0,
				'input_tokens'  => 0,
				'output_tokens' => 0
			),
		);
	}

	/**
	 * Register the REST routes.
	 *
	 * @param Database $db Reviewed input.
	 */
	public static function register_routes( Database $db ): void {
		register_rest_route(
			'directorist-ai/v1',
			'/operations',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					'callback'            => static fn(): array => self::status( $db )
				),
				array(
					'methods'             => 'PUT',
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					'callback'            => static function ( \WP_REST_Request $request ) use ( $db ) {
						$document = json_decode( $request->get_body() );
						if ( ! $document instanceof \stdClass ) {
							return new \WP_Error( 'invalid_input', 'Operational settings must be an object.', array( 'status' => 400 ) );
						}
						$saved = self::save( json_decode( $request->get_body(), true ) );
						return is_wp_error( $saved ) ? $saved : self::status( $db );
					}
				),
			)
		);
	}
}
