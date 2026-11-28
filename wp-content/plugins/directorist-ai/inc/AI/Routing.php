<?php
/**
 * Bind explicit site-owned WordPress choices; managed routing stays remote.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

// phpcs:disable WordPress.WP.CapitalPDangit -- This literal is an AI source identifier, not display text.

use DirectoristAIPro\Orchestration\ModelException;
use WordPress\AiClient\AiClient;

/** Never fallback across AI sources or treat a model preference as a hard pin. */
final class Routing {

	/**
	 * Bind a task's captured provider/model to the native prompt builder.
	 *
	 * @param object $builder Native prompt builder.
	 * @param array  $snapshot Captured administrator policy.
	 * @param string $task Required model capability.
	 * @throws ModelException Source or selected provider/model is unavailable.
	 */
	public static function bind( object $builder, array $snapshot, string $task ): object {
		$snapshot = Settings::for_task( $snapshot, $task );
		if ( 'managed' === ( $snapshot['source'] ?? 'wordpress' ) ) {
			throw new ModelException( 'managed_unavailable', 'Managed execution must use the managed transport. WordPress provider fallback is disabled.' );
		}
		$route = $snapshot['wordpress'][ $task ] ?? array(
			'provider_id' => '',
			'model_id'    => '',
		);
		if ( '' === $route['provider_id'] ) {
			return $builder;
		}
		try {
			if ( '' !== $route['model_id'] ) {
				// The installed OpenRouter connector overrides transport parameters.
				// Refuse a changed override rather than silently breaking a captured pin.
				$class = '\\rtCamp\\ConnectorForOpenrouter\\Settings\\OpenRouterSettings';
				if ( 'openrouter' === $route['provider_id'] && class_exists( $class ) ) {
					$current = 'image' === $task ? $class::get_selected_image_model() : $class::get_selected_model();
					if ( $current !== $route['model_id'] ) {
						throw new ModelException( 'provider_configuration_changed', 'The connector model changed after this turn started. Start a new request using the updated configuration.' );
					}
				}
				return $builder->using_model( AiClient::defaultRegistry()->getProviderModel( $route['provider_id'], $route['model_id'] ) );
			}
			return $builder->using_provider( $route['provider_id'] );
		} catch ( ModelException $error ) {
			throw $error;
		} catch ( \Throwable $error ) {
			throw new ModelException( 'provider_unavailable', 'The selected WordPress provider or model is unavailable.' );
		}
	}
}
