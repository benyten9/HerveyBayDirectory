<?php
/**
 * Administrator-owned AI source policy, without a second provider key store.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

use WordPress\AiClient\AiClient;

/** Configuration is not permission to switch billing sources. */
final class Settings {

	private const OPTION = 'directorist_ai_pro_ai_sources';

	public const TASKS = array( 'visitor', 'listing', 'builder', 'admin', 'image', 'vision' );

	/** Return one source/provider choice per task. */
	public static function defaults(): array {
		return array(
			'default_source' => 'managed',
			'task_routes'    => array_fill_keys(
				self::TASKS,
				array(
					'source'      => 'inherit',
					'provider_id' => '',
					'model_id'    => '',
				)
			),
		);
	}

	/** Project old feature/modality choices without changing their billing source. */
	public static function settings(): array {
		$unset = new \stdClass();
		$saved = function_exists( 'get_option' ) ? get_option( self::OPTION, $unset ) : $unset;
		$next  = self::defaults();
		// Only a genuinely new setup changes its default. Existing partial/legacy
		// policies inherited WordPress, even without an explicit default member.
		if ( $saved !== $unset ) { $next['default_source'] = 'wordpress'; }
		if ( ! is_array( $saved ) ) {
			return $next;
		}
		if ( in_array( $saved['default_source'] ?? '', array( 'wordpress', 'managed' ), true ) ) {
			$next['default_source'] = $saved['default_source'];
		}
		if ( isset( $saved['task_routes'] ) && is_array( $saved['task_routes'] ) ) {
			foreach ( self::TASKS as $task ) {
				if ( is_array( $saved['task_routes'][ $task ] ?? null ) && self::valid_task_route( $task, $saved['task_routes'][ $task ] ) ) {
					$next['task_routes'][ $task ] = $saved['task_routes'][ $task ];
				}
			}
			return $next;
		}
		$legacy = $saved['feature_sources'] ?? array();
		foreach ( self::TASKS as $task ) {
			$media = in_array( $task, array( 'image', 'vision' ), true );
			$route = $saved['wordpress'][ $media ? $task : 'text' ] ?? array(
				'provider_id' => '',
				'model_id'    => '',
			);
			if ( is_array( $route ) && self::valid_route( $route ) ) {
				$next['task_routes'][ $task ] = array( 'source' => 'inherit' ) + $route;
			}
			if ( ! $media && in_array( $legacy[ $task ] ?? '', array( 'inherit', 'wordpress', 'managed' ), true ) ) {
				$next['task_routes'][ $task ]['source'] = $legacy[ $task ];
			} elseif ( $media && array_filter( $legacy, static fn( $value ): bool => in_array( $value, array( 'wordpress', 'managed' ), true ) ) ) {
				// Existing media followed its workflow. Preserve it until explicitly changed.
				$next['task_routes'][ $task ]['source'] = 'workflow';
			}
		}
		return $next;
	}

	/**
	 * Infer the feature from the verified server context, not a model request.
	 *
	 * @param string      $profile Verified actor profile.
	 * @param object|null $context Verified native context.
	 */
	public static function feature( string $profile, ?object $context = null ): string {
		if ( 'admin' === $profile && isset( $context->visitor_starters ) ) { return 'visitor'; }
		if ( in_array( $profile, array( 'builder', 'visitor' ), true ) ) {
			return $profile;
		}
		if ( 'owner' === $profile || isset( $context->listing_form ) || ( isset( $context->target ) && is_string( $context->target ) && 0 === strpos( $context->target, 'listing:' ) ) ) {
			return 'listing';
		}
		return 'admin';
	}

	/**
	 * Freeze all task choices at admission, including later media subtasks.
	 *
	 * @param string      $profile Verified actor profile.
	 * @param object|null $context Verified native context.
	 */
	public static function snapshot( string $profile, ?object $context = null ): array {
		$settings = self::settings();
		$feature  = self::feature( $profile, $context );
		$routes   = array();
		foreach ( array(
			'text'   => $feature,
			'image'  => 'image',
			'vision' => 'vision',
		) as $modality => $task ) {
			$route = $settings['task_routes'][ $task ];
			if ( 'workflow' === $route['source'] ) {
				$route['source'] = $settings['task_routes'][ $feature ]['source'];
			}
			if ( 'inherit' === $route['source'] ) {
				$route['source'] = $settings['default_source'];
			}
			$routes[ $modality ] = $route;
		}
		$snapshot = array(
			'version'     => 2,
			'feature'     => $feature,
			'source'      => $routes['text']['source'],
			'wordpress'   => array(),
			'task_routes' => $routes,
		);
		foreach ( $routes as $modality => $route ) {
			$snapshot['wordpress'][ $modality ] = array(
				'provider_id' => $route['provider_id'],
				'model_id'    => $route['model_id'],
			);
		}
		if ( in_array( 'managed', array_column( $routes, 'source' ), true ) ) {
			$snapshot['managed_fingerprint'] = ManagedService::fingerprint();
		}
		return $snapshot;
	}

	/**
	 * Resolve a modality from captured policy; v1 runs retain their original source.
	 *
	 * @param array  $snapshot Admitted policy.
	 * @param string $task Required capability.
	 */
	public static function for_task( array $snapshot, string $task ): array {
		if ( 2 === ( $snapshot['version'] ?? null ) && isset( $snapshot['task_routes'][ $task ] ) ) {
			$snapshot['source'] = $snapshot['task_routes'][ $task ]['source'];
		}
		return $snapshot;
	}

	/** Return installed provider declarations; discovering metadata never generates. */
	public static function providers(): array {
		if ( ! class_exists( AiClient::class ) ) {
			return array();
		}
		$registry = AiClient::defaultRegistry();
		$result   = array();
		foreach ( $registry->getRegisteredProviderIds() as $id ) {
			$provider = array(
				'id'              => $id,
				'name'            => $id,
				'configured'      => false,
				'metadata_status' => 'unknown',
				'models'          => array(),
			);
			try {
				$class                  = $registry->getProviderClassName( $id );
				$provider['configured'] = $registry->isProviderConfigured( $id );
				$provider['name']       = $class::metadata()->getName();
				if ( $provider['configured'] ) {
					$models = $class::modelMetadataDirectory()->listModelMetadata();
					foreach ( array_slice( $models, 0, 300 ) as $model ) {
						$capabilities = array();
						$tools        = false;
						foreach ( $model->getSupportedCapabilities() as $capability ) {
							if ( $capability->isTextGeneration() ) {
								$capabilities[] = 'text';
							} elseif ( $capability->isImageGeneration() ) {
								$capabilities[] = 'image';
							}
						}
						foreach ( $model->getSupportedOptions() as $option ) {
							$tools = $tools || $option->getName()->isFunctionDeclarations();
							if ( $option->getName()->isInputModalities() && self::contains_image( $option->getSupportedValues() ?? array() ) && in_array( 'text', $capabilities, true ) ) {
								$capabilities[] = 'vision';
							}
						}
						$provider['models'][] = array(
							'id'             => $model->getId(),
							'name'           => $model->getName(),
							'capabilities'   => array_values( array_unique( $capabilities ) ),
							'supports_tools' => $tools,
						);
					}
					$provider['metadata_status'] = count( $models ) <= 300 ? 'known' : 'unknown';
				}
			} catch ( \Throwable $error ) {
				// Metadata failures retain the configured connector and any declarations already read.
				$provider['metadata_status'] = 'unknown';
			}
			$result[] = $provider;
		}
		return $result;
	}

	/**
	 * Validate one complete task hierarchy before saving partial updates.
	 *
	 * @param array $input Administrator settings update.
	 */
	public static function save( array $input ): array|\WP_Error {
		$next = self::settings();
		if ( array_diff( array_keys( $input ), array_keys( $next ) ) ) {
			return self::invalid( 'AI setting is unsupported. Reload the connection settings.' );
		}
		if ( array_key_exists( 'default_source', $input ) ) {
			if ( ! in_array( $input['default_source'], array( 'wordpress', 'managed' ), true ) ) {
				return self::invalid( 'Choose an available AI source option.' );
			}
			$next['default_source'] = $input['default_source'];
		}
		if ( array_key_exists( 'task_routes', $input ) ) {
			if ( ! is_array( $input['task_routes'] ) || array_diff( array_keys( $input['task_routes'] ), self::TASKS ) ) {
				return self::invalid( 'AI settings contain an unsupported task.' );
			}
			foreach ( $input['task_routes'] as $task => $route ) {
				if ( ! is_array( $route ) || ! self::valid_task_route( $task, $route ) || ( 'workflow' === $route['source'] && 'workflow' !== $next['task_routes'][ $task ]['source'] ) ) {
					return self::invalid( 'Choose a task source and an eligible WordPress connector.' );
				}
				$next['task_routes'][ $task ] = $route;
			}
		}
		$providers = self::providers();
		foreach ( $input['task_routes'] ?? array() as $task => $route ) {
			$source = 'inherit' === $route['source'] ? $next['default_source'] : $route['source'];
			if ( 'workflow' === $source ) {
				$workflow_sources = array_map( static fn( string $feature ): string => 'inherit' === $next['task_routes'][ $feature ]['source'] ? $next['default_source'] : $next['task_routes'][ $feature ]['source'], array( 'visitor', 'listing', 'builder', 'admin' ) );
				$source           = in_array( 'wordpress', $workflow_sources, true ) ? 'wordpress' : 'managed'; // phpcs:ignore WordPress.WP.CapitalPDangit -- Internal source identifiers are lowercase.
			}
			if ( 'managed' === $source || '' === $route['provider_id'] ) {
				continue;
			}
			$provider = array_values( array_filter( $providers, static fn( array $item ): bool => $item['id'] === $route['provider_id'] ) );
			if ( ! $provider || ! $provider[0]['configured'] ) {
				return self::invalid( 'The selected WordPress connector is unavailable or not configured.' );
			}
			$capability = in_array( $task, array( 'image', 'vision' ), true ) ? $task : 'text';
			$models     = array_filter( $provider[0]['models'], static fn( array $model ): bool => '' === $route['model_id'] || $model['id'] === $route['model_id'] );
			$supported  = array_filter( $models, static fn( array $model ): bool => in_array( $capability, $model['capabilities'], true ) && ( 'text' !== $capability || $model['supports_tools'] ) );
			if ( $supported ) {
				continue;
			}
			if ( 'unknown' === $provider[0]['metadata_status'] && ( '' === $route['model_id'] || ! $models ) ) {
				// Incomplete discovery may be configured; native request support still gates execution.
				continue;
			}
			return self::invalid( 'The selected WordPress connector or model does not provide the required task capability.' );
		}
		update_option( self::OPTION, $next, false );
		return $next;
	}

	/**
	 * Validate a source together with its nested WordPress selection.
	 *
	 * @param string $task Server task identity.
	 * @param array  $route Nested source and connector choice.
	 */
	private static function valid_task_route( string $task, array $route ): bool {
		$sources = in_array( $task, array( 'image', 'vision' ), true ) ? array( 'inherit', 'wordpress', 'managed', 'workflow' ) : array( 'inherit', 'wordpress', 'managed' );
		return 3 === count( $route ) && in_array( $route['source'] ?? '', $sources, true ) && self::valid_route( array_diff_key( $route, array( 'source' => true ) ) );
	}

	/** Return settings and clearly separated connection/mock status. */
	public static function status(): array {
		return array(
			'settings'          => self::settings(),
			'providers'         => self::providers(),
			'managed'           => ManagedService::status(),
			'license'           => ManagedService::license(),
			'configuration_url' => admin_url( 'options-connectors.php' ),
		);
	}

	/**
	 * Validate identifier shapes without silently repairing user choices.
	 *
	 * @param array $route Provider/model pair.
	 */
	private static function valid_route( array $route ): bool {
		return count( $route ) === 2 && isset( $route['provider_id'], $route['model_id'] ) && is_string( $route['provider_id'] ) && is_string( $route['model_id'] )
			&& ( '' === $route['provider_id'] || preg_match( '/^[a-z0-9_-]{1,128}$/D', $route['provider_id'] ) )
			&& ( '' === $route['model_id'] || preg_match( '/^[A-Za-z0-9._:\\/@+-]{1,191}$/D', $route['model_id'] ) )
			&& ( '' === $route['model_id'] || '' !== $route['provider_id'] );
	}

	/**
	 * Read a declared image input modality, without claiming model certification.
	 *
	 * @param array $values Connector-declared option values.
	 */
	private static function contains_image( array $values ): bool {
		foreach ( $values as $value ) {
			if ( is_array( $value ) && self::contains_image( $value ) ) {
				return true;
			}
			if ( 'image' === $value || ( $value instanceof \WordPress\AiClient\Messages\Enums\ModalityEnum && $value->isImage() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return a safe settings validation error.
	 *
	 * @param string $message Safe diagnostic.
	 */
	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'invalid_input', $message, array( 'status' => 400 ) );
	}
}
